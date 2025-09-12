<?php
/**
 * Online Radio — BOOTSTRAP (sticky right player + Now Playing ICY + YT knop + close ×)
 * Schrijft alle bestanden en mappen in de webroot.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

function out($s){ echo $s.(str_ends_with($s,"\n")?'':"\n"); }
function ok($s){ out("? $s"); }
function fail($s){ out("? $s"); exit(1); }

out("== Online Radio • Bootstrap ==");
out("PHP: ".PHP_VERSION);
if (version_compare(PHP_VERSION,'7.4.0','<')) fail("PHP 7.4 of hoger vereist.");
if (!is_writable(__DIR__)) fail("Webroot niet schrijfbaar: ".__DIR__);

function write_b64($rel,$b64){
  $full = __DIR__.'/'.$rel; $dir = dirname($full);
  if (!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException("Kon map niet maken: $dir");
  $data = base64_decode($b64,true); if ($data===false) throw new RuntimeException("Base64 decode faalde: $rel");
  if (file_put_contents($full,$data)===false) throw new RuntimeException("Kon bestand niet schrijven: $rel");
  ok($rel);
}

/* ---------- FILES ---------- */

$files = [];

/* .htaccess (forceer UTF-8 zodat × geen ? wordt) */
$files['.htaccess'] = base64_encode("Options -Indexes\nAddDefaultCharset UTF-8\n");

/* config.php (incl. Google placeholders) */
$files['config.php'] = base64_encode(<<<'PHP'
<?php
// === Config ===
$CFG = [
  'rb_endpoints'  => ['https://de1.api.radio-browser.info','https://nl1.api.radio-browser.info','https://all.api.radio-browser.info'],
  'cache_ttl'     => 600,
  'timeout'       => 12,
  'default_limit' => 100,
  // YouTube OAuth (optioneel, voor yt.php). Laat zo staan als je het (nog) niet gebruikt.
  'google' => [
    'client_id'     => 'PASTE_CLIENT_ID.apps.googleusercontent.com',
    'client_secret' => 'PASTE_CLIENT_SECRET',
    'redirect_uri'  => (isset($_SERVER['HTTP_HOST']) ? ( ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . '/auth/google-callback.php' ) : ''),
    'scopes'        => ['https://www.googleapis.com/auth/youtube'],
    'token_file'    => __DIR__ . '/data/google_token.json',
  ],
];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function gp($k,$def=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $def; }

// Optionele lokale stats met SQLite
function plays_db_path(){ return __DIR__ . '/data/app.db'; }
function plays_db(){
  if (!extension_loaded('pdo_sqlite')) return null;
  $path = plays_db_path(); @mkdir(dirname($path),0775,true);
  $pdo = new PDO('sqlite:'.$path, null, null, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA journal_mode=WAL;");
  $pdo->exec("CREATE TABLE IF NOT EXISTS plays(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stationuuid TEXT, name TEXT, played_at INTEGER NOT NULL, ip TEXT
  )");
  return $pdo;
}

// Simple file cache
function cache_get($key){
  $f = __DIR__.'/cache/'.sha1($key).'.json';
  if (file_exists($f)) {
    global $CFG; $age = time() - filemtime($f);
    if ($age < ($CFG['cache_ttl'] ?? 600)) { $t = file_get_contents($f); if ($t!==false) return $t; }
  }
  return null;
}
function cache_set($key,$txt){
  $d = __DIR__.'/cache'; if (!is_dir($d)) @mkdir($d,0775,true);
  @file_put_contents($d.'/'.sha1($key).'.json',$txt);
}

// HTTP helper — cURL (IPv4) ? default ? fopen
function http_get_json_from_endpoints($path,$query=[]){
  global $CFG;
  $qs = http_build_query($query);
  $cache_key = $path.'?'.$qs;
  if ($c = cache_get($cache_key)) return $c;

  $heads = ['Accept: application/json','User-Agent: OnlineRadioPHP/1.0'];
  foreach ($CFG['rb_endpoints'] as $base){
    $url = rtrim($base,'/').$path.($qs?('?'.$qs):'');

    if (function_exists('curl_init')){
      $ch = curl_init($url);
      curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>$CFG['timeout'],CURLOPT_TIMEOUT=>$CFG['timeout'],
        CURLOPT_HTTPHEADER=>$heads,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_ENCODING=>'',
        CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
      ]);
      $res = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
      if ($res!==false && $code>=200 && $code<300){ cache_set($cache_key,$res); return $res; }

      $ch = curl_init($url);
      curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>$CFG['timeout'],CURLOPT_TIMEOUT=>$CFG['timeout'],
        CURLOPT_HTTPHEADER=>$heads,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_ENCODING=>'',
      ]);
      $res = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
      if ($res!==false && $code>=200 && $code<300){ cache_set($cache_key,$res); return $res; }
    }

    if (filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)){
      $ctx = stream_context_create(['http'=>[
        'timeout'=>$CFG['timeout'],'method'=>'GET',
        'header'=>"User-Agent: OnlineRadioPHP/1.0\r\nAccept: application/json\r\n"
      ]]);
      $res = @file_get_contents($url,false,$ctx);
      if ($res!==false){ cache_set($cache_key,$res); return $res; }
    }
  }
  return json_encode([]);
}
PHP);

/* index.php — sticky right player; close = ×; YT-knop altijd klikbaar */
$files['index.php'] = base64_encode(<<<'PHP'
<?php
error_reporting(E_ALL); ini_set('display_errors','1');
require __DIR__.'/config.php';
$g = $CFG['google'];
$ytConfigured = ($g['client_id']!=='PASTE_CLIENT_ID.apps.googleusercontent.com' && $g['client_secret']!=='PASTE_CLIENT_SECRET' && !empty($g['redirect_uri']));
?><!doctype html>
<html lang="nl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Online Radio</title>
<link rel="stylesheet" href="assets/css/style.css">
<script>window.__YT_READY__=<?php echo $ytConfigured?'true':'false'; ?>;</script>
<script defer src="assets/js/app.js"></script>
</head><body>
<header class="container">
  <h1>Online Radio</h1>
  <p class="muted">Live via <a href="https://www.radio-browser.info" target="_blank" rel="noopener">Radio Browser</a>.</p>
</header>

<main class="container">
  <section class="filters card">
    <form id="searchForm" class="grid">
      <input type="search" name="q" placeholder="Zoek op naam / tags / url">
      <select name="tag"><option value="">Tag (genre)</option><option>pop</option><option>rock</option><option>news</option><option>jazz</option><option>classical</option><option>dance</option><option>electronic</option><option>talk</option></select>
      <select name="country"><option value="">Land (bv. Netherlands)</option><option>Netherlands</option><option>Belgium</option><option>Germany</option><option>France</option><option>United Kingdom</option><option>United States Of America</option><option>Spain</option><option>Italy</option><option>Canada</option><option>Brazil</option></select>
      <select name="language"><option value="">Taal (bv. Dutch)</option><option>Dutch</option><option>English</option><option>French</option><option>German</option><option>Spanish</option><option>Italian</option><option>Portuguese</option><option>Hindi</option></select>
      <select name="min_bitrate"><option value="">Min. bitrate</option><option value="64">64</option><option value="96">96</option><option value="128">128</option><option value="192">192</option><option value="256">256</option><option value="320">320</option></select>
      <select name="order"><option value="clickcount">Populair</option><option value="bitrate">Bitrate</option><option value="name">Naam</option><option value="country">Land</option><option value="language">Taal</option></select>
      <button type="submit">Zoeken</button>
      <button type="button" id="resetBtn" class="btn-secondary">Reset</button>
    </form>
  </section>

  <section class="card">
    <h2>Stations</h2>
    <ul id="stationList" class="list"><li class="muted">Kies filters en klik op Zoeken.</li></ul>
  </section>

  <section class="card">
    <h2>Tip</h2>
    <p>Beheer zenders via <a href="https://www.radio-browser.info" target="_blank" rel="noopener">radio-browser.info</a>; updates komen direct via de API.</p>
    <h3><a href="stats.php">Statistieken (lokaal)</a></h3>
    <p class="muted">Werkt als <code>pdo_sqlite</code> actief is.</p>
  </section>
</main>

<!-- Sticky RIGHT player -->
<aside id="player" class="player hidden" aria-live="polite">
  <div class="p-head">
    <img id="pLogo" alt="" />
    <div class="p-txt">
      <div id="pName" class="p-name">—</div>
      <div id="pTrack" class="p-track muted">—</div>
    </div>
    <button id="pClose" class="p-close" title="Sluiten">&times;</button>
  </div>
  <audio id="pAudio" controls preload="none"></audio>
  <div class="p-actions">
    <button id="pStop" class="btn-secondary" title="Stop">¦ Stop</button>
    <a id="pYT" class="btn" href="yt.php" title="Toevoegen aan YouTube playlist">? Add to YouTube</a>
  </div>
</aside>

<footer class="container muted"><p>© <?php echo date('Y'); ?> Online Radio</p></footer>
</body></html>
PHP);

/* stats.php */
$files['stats.php'] = base64_encode(<<<'PHP'
<?php
error_reporting(E_ALL); ini_set('display_errors','1');
require __DIR__.'/config.php';
$pdo = plays_db();
?><!doctype html><meta charset="utf-8"><title>Statistieken</title>
<link rel="stylesheet" href="assets/css/style.css">
<div class="container card">
  <h1>Statistieken (lokaal)</h1>
  <?php if(!$pdo): ?>
    <p class="muted">pdo_sqlite staat uit of pad <code>data/</code> is niet schrijfbaar.</p>
    <p><a href="index.php">Terug</a></p>
  <?php else:
    $rows = $pdo->query("SELECT name, stationuuid, COUNT(*) AS cnt
                         FROM plays GROUP BY stationuuid
                         ORDER BY cnt DESC, name ASC LIMIT 100")->fetchAll();
    if(!$rows){ echo '<p class="muted">Nog geen afspeellog. Speel eerst een station af.</p>'; }
  ?>
    <ol class="top">
      <?php foreach($rows as $r): ?>
        <li><?php echo h($r['name'] ?: $r['stationuuid']); ?> — <?php echo (int)$r['cnt']; ?></li>
      <?php endforeach; ?>
    </ol>
    <p><a href="index.php">Terug</a></p>
  <?php endif; ?>
</div>
PHP);

/* assets/css/style.css */
$files['assets/css/style.css'] = base64_encode(<<<'CSS'
:root{ --bg:#0f1117; --card:#1a1d29; --fg:#e5e7eb; --muted:#9ca3af; --accent:#10b981; --border:#2e3444; }
*{box-sizing:border-box}
body{margin:0;font:16px/1.5 system-ui,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--fg)}
a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}
.container{max-width:1100px;margin:0 auto;padding:16px}
header{background:var(--card);border-bottom:1px solid var(--border)}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin:12px 0}
.muted{color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}
@media (max-width:960px){.grid{grid-template-columns:repeat(2,1fr)}}
input,select,button,.btn{padding:10px;border-radius:10px;border:1px solid var(--border);background:#0f1117;color:var(--fg)}
button,.btn{background:var(--accent);border:none;font-weight:600;cursor:pointer;text-align:center;display:inline-block}
.btn-secondary{background:#374151}
.btn.disabled{opacity:.5;pointer-events:none}
.list{list-style:none;margin:0;padding:0;display:grid;gap:12px}
.list li{border:1px solid var(--border);border-radius:10px;padding:14px;background:#141826}
.badge{display:inline-block;background:#374151;color:#fff;padding:2px 6px;border-radius:6px;font-size:.75rem;margin:2px 6px 0 0}
.badge img.flag{width:16px;height:12px;object-fit:cover;border-radius:2px;vertical-align:-2px;margin-right:6px}
.links{margin-top:6px;display:flex;gap:12px;align-items:center}
.links a{font-size:13px;display:inline-flex;gap:6px;align-items:center;opacity:.9}
.links a:hover{opacity:1}
.icon{width:14px;height:14px;display:inline-block}
.logo{max-width:40px;max-height:40px;border-radius:6px;border:1px solid var(--border);margin:4px 0}

/* right sticky player */
.player{
  position:fixed; right:16px; bottom:16px; width:360px; max-width:92vw;
  background:var(--card); border:1px solid var(--border); border-radius:14px;
  box-shadow:0 8px 24px rgba(0,0,0,.35); padding:12px; z-index:9999;
}
.player.hidden{ display:none; }
.p-head{display:flex; align-items:center; gap:10px; margin-bottom:8px}
#pLogo{width:40px;height:40px;border-radius:8px;border:1px solid var(--border);background:#0f1117;object-fit:contain}
.p-name{font-weight:700}
.p-track{font-size:.9rem}
.p-close{margin-left:auto;background:#0f1117;border:1px solid var(--border);color:var(--fg);padding:6px 10px;border-radius:8px;cursor:pointer}
#pAudio{width:100%}
.p-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px}

/* playing highlight */
.station.playing{
  border:2px solid var(--accent);
  background:rgba(16,185,129,.10);
  border-radius:10px;
  transition:all .25s ease;
}

/* compacte play-knop in card */
.play-btn{margin-top:8px}
CSS);

/* assets/js/app.js — sticky player, now-playing via api/np.php, YT-koppeling */
$files['assets/js/app.js'] = base64_encode(<<<'JS'
// assets/js/app.js
let activeLi = null;
let nowTimer = null;
let currentStation = null;
let currentNow = '';

const $ = (sel,root=document)=>root.querySelector(sel);
const $$= (sel,root=document)=>[...root.querySelectorAll(sel)];

async function fetchStations(params={}){
  const qs = new URLSearchParams(params).toString();
  const res = await fetch(`api/search.php?${qs}`, {headers:{'Accept':'application/json'}});
  const data = await res.json();
  return data.items || [];
}

function renderStations(stations){
  const ul = $('#stationList');
  ul.innerHTML = '';
  if(!stations.length){ ul.innerHTML = '<li class="muted">Geen resultaten.</li>'; return; }

  stations.forEach(s=>{
    const li = document.createElement('li');
    li.className = 'station';
    const flag = s.countrycode ? `<img class="flag" src="https://flagcdn.com/20x15/${s.countrycode.toLowerCase()}.png" alt="${s.country}">` : '';
    const pills = [
      s.tags ? `<span class="badge">${s.tags}</span>` : '',
      s.country ? `<span class="badge">${flag} ${s.country}</span>` : '',
      s.language ? `<span class="badge">${s.language}</span>` : '',
      s.bitrate ? `<span class="badge">${s.bitrate} kbps</span>` : ''
    ].join('');

    li.innerHTML = `
      <h3>${s.name||'(naamloos)'}</h3>
      ${s.favicon ? `<img class="logo" src="${s.favicon}" alt="" onerror="this.style.display='none'">` : ''}
      <div class="pills">${pills}</div>
      <button class="play-btn" type="button">? Afspelen</button>
      <div class="links">
        ${s.homepage ? `<a href="${s.homepage}" target="_blank" rel="noopener">
          <svg class="icon" viewBox="0 0 24 24"><path fill="currentColor" d="M10 13a5 5 0 0 1 0-7l1-1a5 5 0 0 1 7 7l-1 1"/></svg> Website
        </a>` : ''}
        <a href="${s.url_resolved||s.url}" target="_blank" rel="noopener">
          <svg class="icon" viewBox="0 0 24 24"><path fill="currentColor" d="M14 3h7v7h-2V6.41l-9.29 9.3-1.42-1.42 9.3-9.29H14V3z"/><path fill="currentColor" d="M5 5h7v2H7v10h10v-5h2v7H5z"/></svg> Open stream
        </a>
      </div>
    `;
    li.querySelector('.play-btn').addEventListener('click', ()=> playStation(s, li));
    ul.appendChild(li);
  });
}

function playStation(station, li){
  const audio = $('#pAudio');
  const name  = $('#pName');
  const track = $('#pTrack');
  const logo  = $('#pLogo');
  const player= $('#player');

  $$('.station.playing').forEach(el=>el.classList.remove('playing'));
  if (activeLi && activeLi!==li) activeLi.classList.remove('playing');
  activeLi = li; li.classList.add('playing');
  currentStation = station;

  player.classList.remove('hidden');
  name.textContent = station.name || '(naamloos)';
  track.textContent = '—';
  if (station.favicon) { logo.src = station.favicon; logo.style.display='block'; } else { logo.style.display='none'; }

  audio.src = station.url_resolved || station.url;
  audio.play().catch(()=>{});

  if (nowTimer) clearInterval(nowTimer);
  fetchNowPlaying(audio.src).then(t=>{ currentNow = t||''; track.textContent = currentNow || '—'; updateYTLink(); });
  nowTimer = setInterval(async ()=>{
    const t = await fetchNowPlaying(audio.src);
    if (t) { currentNow = t; track.textContent = t; updateYTLink(); }
  }, 30000);
}

async function fetchNowPlaying(streamUrl){
  try{
    const res = await fetch('api/np.php?u=' + encodeURIComponent(streamUrl));
    if(!res.ok) return null;
    const j = await res.json();
    return j && j.now ? j.now : null;
  }catch(e){ return null; }
}

function initPlayer(){
  const audio = $('#pAudio');
  const player= $('#player');
  $('#pClose').addEventListener('click', ()=>{
    audio.pause(); player.classList.add('hidden');
    if (activeLi) activeLi.classList.remove('playing');
  });
  $('#pStop').addEventListener('click', ()=>{
    audio.pause(); audio.removeAttribute('src'); audio.load();
    if (activeLi) activeLi.classList.remove('playing');
  });
  // YT deeplink (werkt ook zonder OAuth: brengt je naar yt.php met query)
  $('#pYT').addEventListener('click', (e)=>{
    if (!window.__YT_READY__) {
      // nog niet geconfigureerd: laat gewoon door naar yt.php (met query), waar uitleg staat
    }
    const q = encodeURIComponent((currentNow || '').trim() || (currentStation?.name || ''));
    e.currentTarget.href = 'yt.php?q=' + q;
  });
}

function updateYTLink(){
  const a = $('#pYT'); if (!a) return;
  const q = encodeURIComponent((currentNow || '').trim() || (currentStation?.name || ''));
  a.href = 'yt.php?q=' + q;
}

document.addEventListener('DOMContentLoaded', ()=>{
  initPlayer();
  const form = document.getElementById('searchForm');

  document.getElementById('resetBtn').addEventListener('click', ()=>{
    form.reset(); document.getElementById('stationList').innerHTML='';
  });

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const params = Object.fromEntries(new FormData(form).entries());
    const items = await fetchStations(params);
    renderStations(items);
  });
});
JS);

/* api/search.php */
$files['api/search.php'] = base64_encode(<<<'PHP'
<?php
error_reporting(E_ALL); ini_set('display_errors','1');
header('Content-Type: application/json; charset=utf-8');
require __DIR__.'/../config.php';

$q          = gp('q');
$tag        = gp('tag');
$country    = gp('country');
$language   = gp('language');
$min_bitrate= (int)gp('min_bitrate', 0);
$order      = gp('order', 'clickcount');
$page       = max(1, (int)gp('page', 1));
$limit      = max(1, min(200, (int)gp('limit', $CFG['default_limit'] ?? 100)));
$offset     = ($page - 1) * $limit;

$params = [
  'limit'=>$limit,'offset'=>$offset,'hidebroken'=>'true','is_https'=>'true','order'=>$order
];
if ($q!=='') $params['name'] = $q;
if ($tag!=='') $params['tag'] = $tag;
if ($country!=='') $params['country'] = $country;
if ($language!=='') $params['language'] = $language;
if ($min_bitrate>0) $params['bitrate_min'] = $min_bitrate;

$json = http_get_json_from_endpoints('/json/stations/search', $params);
$items = json_decode($json,true); if(!is_array($items)) $items = [];

$out=[];
foreach ($items as $s){
  $out[] = [
    'stationuuid'=>$s['stationuuid']??'',
    'name'       =>$s['name']??'',
    'url'        =>$s['url']??'',
    'url_resolved'=>$s['url_resolved']??'',
    'homepage'   =>$s['homepage']??'',
    'favicon'    =>$s['favicon']??'',
    'tags'       =>$s['tags']??'',
    'country'    =>$s['country']??'',
    'countrycode'=>$s['countrycode']??'',
    'language'   =>$s['language']??'',
    'bitrate'    =>$s['bitrate']??null,
  ];
}
echo json_encode(['items'=>$out], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
PHP);

/* api/np.php — Now Playing met echte ICY reader + fallbacks */
$files['api/np.php'] = base64_encode(<<<'PHP'
<?php
declare(strict_types=1);
ini_set('display_errors','0'); error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

$u = isset($_GET['u']) ? trim((string)$_GET['u']) : '';
if ($u==='' || !preg_match('#^https?://#i',$u)) { echo '{"now":null}'; exit; }

function curlget($url,$timeout=6){
  if (!function_exists('curl_init')) return false;
  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>$timeout,
    CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_ENCODING=>'',
    CURLOPT_USERAGENT=>'OnlineRadioPHP/1.0',
  ]);
  $res = curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  return ($res!==false && $code>=200 && $code<300) ? $res : false;
}

/** ICY metadata reader (socket) */
function icy_now_playing(string $url, int $timeout=6): ?string {
  $p = parse_url($url);
  if (!$p || empty($p['host'])) return null;
  $scheme = strtolower($p['scheme'] ?? 'http');
  $host   = $p['host'];
  $port   = isset($p['port']) ? (int)$p['port'] : ($scheme==='https' ? 443 : 80);
  $path   = ($p['path'] ?? '/').(isset($p['query']) ? ('?'.$p['query']) : '');

  $transport = ($scheme==='https') ? 'ssl://' : '';
  $fp = @stream_socket_client(($transport?$transport:'').$host.":".$port, $errno, $errstr, $timeout,
                              STREAM_CLIENT_CONNECT, stream_context_create([
                                'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true,'peer_name'=>$host],
                                'http'=>['timeout'=>$timeout]
                              ]));
  if(!$fp) return null;
  stream_set_timeout($fp,$timeout);

  $req = "GET ".$path." HTTP/1.1\r\n".
         "Host: ".$host."\r\n".
         "User-Agent: OnlineRadioPHP/1.0\r\n".
         "Icy-MetaData: 1\r\n".
         "Connection: close\r\n\r\n";
  fwrite($fp,$req);

  // headers
  $headers = '';
  while(!feof($fp)){
    $line = fgets($fp, 4096);
    if ($line===false) break;
    $headers .= $line;
    if (rtrim($line)==='') break;
    if (strlen($headers) > 32768) break;
  }
  if (!preg_match('/icy-metaint:\s*(\d+)/i', $headers, $m)) { fclose($fp); return null; }
  $metaint = (int)$m[1]; if ($metaint <= 0){ fclose($fp); return null; }

  // skip audio bytes
  $toSkip = $metaint;
  while($toSkip > 0 && !feof($fp)){
    $chunk = fread($fp, min(8192, $toSkip));
    if ($chunk === false) break;
    $toSkip -= strlen($chunk);
  }

  // read 1 metadata block
  $lenByte = fgetc($fp);
  if ($lenByte === false) { fclose($fp); return null; }
  $len = ord($lenByte) * 16;
  if ($len === 0) { fclose($fp); return null; }

  $meta = '';
  while(strlen($meta) < $len && !feof($fp)){
    $chunk = fread($fp, $len - strlen($meta));
    if ($chunk === false) break;
    $meta .= $chunk;
  }
  fclose($fp);

  if ($meta && preg_match("#StreamTitle='([^']*)'#", $meta, $mm)) {
    $title = trim($mm[1]);
    return ($title !== '') ? $title : null;
  }
  return null;
}

$now = icy_now_playing($u);

// Icecast JSON / HTML (fallback)
if (!$now){
  $base = preg_replace('#(\.m3u8?|/stream.*)$#i','',$u);
  if ($base){
    foreach (['/status-json.xsl','/status-json.xsl?mount=','/status.xsl'] as $p){
      $res = curlget($base.$p);
      if ($res){
        $j = json_decode($res,true);
        if (isset($j['icestats']['source'])){
          $src = $j['icestats']['source']; if (isset($src[0])) $src = $src[0];
          $now = $src['title'] ?? ($src['artist']??null);
          if ($now) break;
        } elseif (preg_match('#Current Song</td>\s*<td[^>]*>(.*?)</td>#is',$res,$m)){
          $now = trim(strip_tags($m[1])); break;
        }
      }
    }
  }
}

// Shoutcast 7.html / stats (fallback)
if (!$now){
  $base2 = preg_replace('#/;\\?*.*$#','',$u);
  foreach (['/7.html','/stats?json=1'] as $p){
    $res = curlget($base2.$p);
    if ($res){
      if ($p==='/7.html' && strpos($res,',')!==false){
        $parts = explode(',', $res, 8);
        $now = trim($parts[count($parts)-1] ?? '');
        if ($now==='') $now=null;
      } else {
        $j = json_decode($res,true);
        if (isset($j['songtitle'])) $now = $j['songtitle'];
      }
      if ($now) break;
    }
  }
}

echo json_encode(['now'=>$now?:null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
PHP);

/* api/play.php — optioneel plays loggen */
$files['api/play.php'] = base64_encode(<<<'PHP'
<?php
error_reporting(E_ALL); ini_set('display_errors','1');
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../config.php';

$raw = file_get_contents('php://input'); $in = json_decode($raw,true) ?: [];
$uuid = isset($in['stationuuid']) ? trim((string)$in['stationuuid']) : '';
$name = isset($in['name']) ? trim((string)$in['name']) : '';

$pdo = plays_db();
if (!$pdo){ echo json_encode(['ok'=>true,'logged'=>false]); exit; }

try{
  $stmt=$pdo->prepare("INSERT INTO plays(stationuuid,name,played_at,ip) VALUES(?,?,?,?)");
  $stmt->execute([$uuid?:null,$name?:null,time(),$_SERVER['REMOTE_ADDR']??null]);
  echo json_encode(['ok'=>true,'logged'=>true]);
}catch(Throwable $e){
  http_response_code(200); echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
}
PHP);

/* assets for YouTube helper page (optional, same as earlier) */
$files['api/google_client.php'] = base64_encode(<<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
function gcfg(){ global $CFG; return $CFG['google']; }
function g_has_config(): bool {
  $g = gcfg();
  return $g['client_id']!=='PASTE_CLIENT_ID.apps.googleusercontent.com' && $g['client_secret']!=='PASTE_CLIENT_SECRET' && !empty($g['redirect_uri']);
}
function g_token_load(){ $f=gcfg()['token_file']; return file_exists($f) ? json_decode(file_get_contents($f),true) : null; }
function g_token_save(array $tok){ $f=gcfg()['token_file']; @mkdir(dirname($f),0775,true); file_put_contents($f,json_encode($tok)); }
function http_json($method,$url,$headers=[],$body=null,$timeout=12){
  $ch=curl_init($url);
  $h=array_merge(['Accept: application/json','User-Agent: OnlineRadioPHP/1.0'], $headers);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_CONNECTTIMEOUT=>$timeout,CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_HTTPHEADER=>$h,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_CUSTOMREQUEST=>$method,]);
  if($body!==null){ curl_setopt($ch,CURLOPT_POSTFIELDS,$body); }
  $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
  return [$code,$res,$err];
}
function g_refresh_if_needed(){
  $tok=g_token_load(); if(!$tok) return null;
  if(isset($tok['expires_at']) && time() < $tok['expires_at']-60) return $tok;
  if(empty($tok['refresh_token'])) return $tok;
  $g=gcfg();
  [$code,$res,$err]=http_json('POST','https://oauth2.googleapis.com/token',[
    'Content-Type: application/x-www-form-urlencoded',
  ], http_build_query([
    'client_id'=>$g['client_id'],'client_secret'=>$g['client_secret'],
    'grant_type'=>'refresh_token','refresh_token'=>$tok['refresh_token'],
  ]));
  if($code===200){
    $j=json_decode($res,true);
    $tok['access_token']=$j['access_token'];
    $tok['expires_in']=$j['expires_in']??3600;
    $tok['expires_at']=time()+($tok['expires_in']??3600);
    g_token_save($tok);
  }
  return $tok;
}
function yt_api_get($path,$params=[]){
  $g=gcfg(); $tok=g_refresh_if_needed(); if(!$tok) return [401,null];
  $url='https://www.googleapis.com/youtube/v3'.$path.'?'.http_build_query($params);
  [$code,$res,$err]=http_json('GET',$url,['Authorization: Bearer '.$tok['access_token']]);
  return [$code, $res ? json_decode($res,true) : null];
}
function yt_api_post($path,$params,$bodyObj){
  $tok=g_refresh_if_needed(); if(!$tok) return [401,null];
  $url='https://www.googleapis.com/youtube/v3'.$path.'?'.http_build_query($params);
  [$code,$res,$err]=http_json('POST',$url,[
    'Authorization: Bearer '.$tok['access_token'],
    'Content-Type: application/json'
  ], json_encode($bodyObj));
  return [$code, $res ? json_decode($res,true) : null];
}
PHP);

$files['auth/google-start.php'] = base64_encode(<<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
$g = $CFG['google'];
if ($g['client_id']==='PASTE_CLIENT_ID.apps.googleusercontent.com'){ echo "Configureer eerst GOOGLE CLIENT in config.php"; exit; }
$state = bin2hex(random_bytes(12));
session_start(); $_SESSION['g_state']=$state;
$params = [
  'client_id'=>$g['client_id'],
  'redirect_uri'=>$g['redirect_uri'],
  'response_type'=>'code',
  'scope'=>implode(' ',$g['scopes']),
  'access_type'=>'offline',
  'prompt'=>'consent',
  'state'=>$state,
];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params));
exit;
PHP);

$files['auth/google-callback.php'] = base64_encode(<<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../api/google_client.php';
session_start();

if (!isset($_GET['state']) || !hash_equals($_SESSION['g_state'] ?? '', $_GET['state'])) { echo "State mismatch"; exit; }
if (!isset($_GET['code'])) { echo "Geen authorization code"; exit; }

$g=$CFG['google'];
[$code,$res,$err] = http_json('POST','https://oauth2.googleapis.com/token',[
  'Content-Type: application/x-www-form-urlencoded',
], http_build_query([
  'code'=>$_GET['code'],
  'client_id'=>$g['client_id'],
  'client_secret'=>$g['client_secret'],
  'redirect_uri'=>$g['redirect_uri'],
  'grant_type'=>'authorization_code',
]));
if ($code!==200){ echo "Token exchange faalde ($code)"; exit; }
$j=json_decode($res,true);
$tok = [
  'access_token'=>$j['access_token'],
  'expires_in'=>$j['expires_in']??3600,
  'expires_at'=>time()+($j['expires_in']??3600),
  'refresh_token'=>$j['refresh_token'] ?? null,
  'scope'=>$j['scope'] ?? '',
  'token_type'=>$j['token_type'] ?? 'Bearer',
];
g_token_save($tok);
header('Location: /yt.php');
exit;
PHP);

/* yt.php — kies playlist, zoek & voeg toe (optioneel) */
$files['yt.php'] = base64_encode(<<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/config.php';
require __DIR__.'/api/google_client.php';

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$hasCfg = g_has_config();
$tok = $hasCfg ? g_token_load() : null;

?><!doctype html>
<meta charset="utf-8">
<link rel="stylesheet" href="assets/css/style.css">
<div class="container card">
  <h1>YouTube playlist</h1>
  <?php if(!$hasCfg): ?>
    <p class="muted">YouTube integratie is nog niet geconfigureerd. Vul <code>client_id</code>, <code>client_secret</code> en <code>redirect_uri</code> in <code>config.php</code>, en zet dezelfde redirect in je Google Cloud Console OAuth2 client.</p>
  <?php else: ?>
    <?php if(!$tok): ?>
      <p><a class="btn" href="auth/google-start.php">Sign in with Google</a></p>
    <?php else: 
      // Playlists
      [$c,$pl] = yt_api_get('/playlists',['part'=>'snippet,contentDetails','mine'=>'true','maxResults'=>50]);
      if($c!==200){ echo "<p class='muted'>Kon playlists niet ophalen (HTTP $c). Probeer opnieuw <a href='auth/google-start.php'>Sign in</a>.</p>"; }
      // Zoeken op YouTube
      $vid = null; $searchRes = null;
      if($q!==''){
        [$c2,$sr] = yt_api_get('/search',['part'=>'snippet','q'=>$q,'type'=>'video','maxResults'=>5]);
        if($c2===200) $searchRes=$sr['items'] ?? [];
        if(!empty($searchRes)){ $vid = $searchRes[0]['id']['videoId'] ?? null; }
      }
    ?>
      <form method="post">
        <label>Playlist:</label>
        <select name="playlist">
          <?php foreach(($pl['items']??[]) as $p): ?>
            <option value="<?php echo h($p['id']); ?>"><?php echo h($p['snippet']['title']); ?></option>
          <?php endforeach; ?>
        </select>
        <label>Zoekterm (pas aan indien nodig):</label>
        <input type="text" name="q" value="<?php echo h($q); ?>" style="width:100%">
        <button class="btn" name="act" value="search" type="submit">Zoek</button>
        <button class="btn" name="act" value="add" type="submit" <?php echo $vid?'':'disabled'; ?>>Voeg 1e resultaat toe</button>
      </form>
      <?php if($searchRes!==null): ?>
        <h3>Zoekresultaten</h3>
        <ol>
          <?php foreach($searchRes as $it): $v=$it['id']['videoId']; ?>
            <li>
              <a href="https://www.youtube.com/watch?v=<?php echo h($v); ?>" target="_blank" rel="noopener">
                <?php echo h($it['snippet']['title']); ?>
              </a>
              <?php if(isset($_POST['playlist'])): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="playlist" value="<?php echo h($_POST['playlist']); ?>">
                  <input type="hidden" name="q" value="<?php echo h($q); ?>">
                  <input type="hidden" name="videoId" value="<?php echo h($v); ?>">
                  <button class="btn" name="act" value="addSpecific" type="submit">Voeg toe</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <?php
      if(($_POST['act']??'') === 'search'){
        header('Location: yt.php?q='.urlencode($_POST['q'])); exit;
      }
      if(($_POST['act']??'') === 'add' && !empty($_POST['playlist']) && $vid){
        [$c3,$ins] = yt_api_post('/playlistItems', ['part'=>'snippet'], [
          'snippet'=>['playlistId'=>$_POST['playlist'],'resourceId'=>['kind'=>'youtube#video','videoId'=>$vid]]
        ]);
        echo $c3===200 ? "<p>? Toegevoegd aan playlist.</p>" : "<p>? Mislukt (HTTP $c3)</p>";
      }
      if(($_POST['act']??'') === 'addSpecific' && !empty($_POST['playlist']) && !empty($_POST['videoId'])){
        [$c4,$ins2] = yt_api_post('/playlistItems', ['part'=>'snippet'], [
          'snippet'=>['playlistId'=>$_POST['playlist'],'resourceId'=>['kind'=>'youtube#video','videoId'=>$_POST['videoId']]]
        ]);
        echo $c4===200 ? "<p>? Toegevoegd aan playlist.</p>" : "<p>? Mislukt (HTTP $c4)</p>";
      }
      ?>
    <?php endif; ?>
  <?php endif; ?>
  <p><a href="index.php">? Terug</a></p>
</div>
PHP);

/* diag.php */
$files['diag.php'] = base64_encode(<<<'PHP'
<?php
header('Content-Type: text/plain; charset=utf-8');
require __DIR__.'/config.php';
echo "== Online Radio • Diagnose ==\n";
echo "PHP: ".PHP_VERSION."\n";
echo "pdo_sqlite: ".(extension_loaded('pdo_sqlite')?'yes':'no')."\n";
echo "Cache dir: ".__DIR__."/cache\n";
echo "DB file: ".plays_db_path()."\n";
$payload = http_get_json_from_endpoints('/json/stations/search', ['limit'=>1,'hidebroken'=>'true','is_https'=>'true']);
echo "Radio Browser reachable: ".(strlen((string)$payload)>2?'yes':'no')."\n";
$g=$CFG['google'];
$ytReady = ($g['client_id']!=='PASTE_CLIENT_ID.apps.googleusercontent.com' && $g['client_secret']!=='PASTE_CLIENT_SECRET' && !empty($g['redirect_uri']));
echo "YouTube OAuth configured: ".($ytReady?'yes':'no')."\n";
PHP);

/* ---------- WRITE ---------- */
try{
  foreach($files as $p=>$b){ write_b64($p,$b); }
  foreach(['cache','data','assets/img','api','auth','assets/js','assets/css'] as $d){
    $f=__DIR__.'/'.$d; if(!is_dir($f)) @mkdir($f,0775,true); ok($d.'/');
  }
  out("\nKLAAR ?  Ga naar /diag.php (moet 'reachable: yes' tonen), daarna naar /");
  out("YouTube (optioneel): zet in Google Cloud Console de redirect URI op https://jouwdomein/auth/google-callback.php en vul client_id/secret in config.php.");
  out("Tip: verwijder bootstrap.php na installatie.");
}catch(Throwable $e){
  fail("Installatiefout: ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine());
}
