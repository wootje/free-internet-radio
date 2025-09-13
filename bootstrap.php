<?php
/**
 * Online Radio++ — BOOTSTRAP
 * - Accounts, favorieten, reCAPTCHA, lockout, activatie, wachtwoord-reset
 * - Mail: SMTP of mail()
 * - YouTube + Spotify per gebruiker
 * - Sticky player + Now Playing (ICY + fallbacks) + optionele AcoustID
 * - Filters, facetten, stats, cron voor dode URL's
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors','1'); error_reporting(E_ALL);

function out($s){ echo $s.(str_ends_with($s,"\n")?'':"\n"); }
function ok($s){ out("✔ $s"); }
function fail($s){ out("❌ $s"); exit(1); }
if (!is_writable(__DIR__)) fail("Webroot niet schrijfbaar: ".__DIR__);
if (version_compare(PHP_VERSION,'8.0.0','<')) fail("PHP 8.0+ vereist.");

function put($rel,$txt){
  $full=__DIR__.'/'.$rel; $dir=dirname($full);
  if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) fail("Kon map niet maken: $dir");
  if(file_put_contents($full,$txt)===false) fail("Kon bestand niet schrijven: $rel");
  ok($rel);
}

/* ============ .htaccess ============ */
put('.htaccess', "Options -Indexes\nAddDefaultCharset UTF-8\n");

/* ============ config.php ============ */
put('config.php', <<<'PHP'
<?php
declare(strict_types=1);

$CFG = [
  'site_name' => 'Online Radio++',
  'site_url'  => (isset($_SERVER['HTTP_HOST']) ? ( ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https://' : 'http://' ).$_SERVER['HTTP_HOST'] ) : ''),

  'rb_endpoints'  => ['https://de1.api.radio-browser.info','https://nl1.api.radio-browser.info','https://all.api.radio-browser.info'],
  'cache_ttl'     => 600,
  'timeout'       => 12,
  'default_limit' => 100,

  /* E-mail */
  'mail' => [
    'driver'   => 'smtp',               // 'smtp' of 'mail'
    'from'     => 'no-reply@example.com',
    'from_name'=> 'Online Radio++',
    // SMTP settings (gebruikt als driver == 'smtp')
    'host'     => 'smtp.example.com',
    'port'     => 587,
    'security' => 'STARTTLS',           // 'SSL', 'TLS', 'STARTTLS' of ''
    'username' => 'smtp-user',
    'password' => 'smtp-pass',
  ],

  /* reCAPTCHA v2 (checkbox). Als leeg, wordt reCAPTCHA overgeslagen. */
  'recaptcha' => [
    'site_key' => '',                   // bijv. 6Lc... (client)
    'secret'   => '',                   // bijv. 6Lc... (server)
  ],

  /* OAuth global defaults (gebruikers kunnen eigen credentials invullen in Portaal) */
  'google' => [
    'client_id'     => 'PASTE_CLIENT_ID.apps.googleusercontent.com',
    'client_secret' => 'PASTE_CLIENT_SECRET',
    'redirect_uri'  => (isset($_SERVER['HTTP_HOST']) ? ( ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . '/auth/google-callback.php' ) : ''),
    'scopes'        => ['https://www.googleapis.com/auth/youtube'],
  ],
  'spotify' => [
    'client_id'     => 'PASTE_SPOTIFY_CLIENT_ID',
    'client_secret' => 'PASTE_SPOTIFY_CLIENT_SECRET',
    'redirect_uri'  => (isset($_SERVER['HTTP_HOST']) ? ( ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . '/auth/spotify-callback.php' ) : ''),
    'scopes'        => ['playlist-modify-private','playlist-modify-public'],
  ],

  /* AcoustID / MusicBrainz (optioneel fingerprint): requires ffmpeg + fpcalc */
  'acoustid' => [
    'apikey' => '',  // Vraag gratis API key aan op https://acoustid.org/
  ],
];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function gp($k,$def=''){ return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $def; }
function pp($k,$def=''){ return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $def; }

function start_session(){ if (session_status()!==PHP_SESSION_ACTIVE) session_start(); }
function me(){ start_session(); return $_SESSION['uid'] ?? null; }

/* DB (SQLite) */
function db_path(){ return __DIR__.'/data/app.db'; }
function db(){
  static $pdo=null; if($pdo) return $pdo;
  if(!extension_loaded('pdo_sqlite')) return null;
  @mkdir(__DIR__.'/data',0775,true);
  $pdo = new PDO('sqlite:'.db_path(), null, null, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
  $pdo->exec("PRAGMA journal_mode=WAL;");
  $pdo->exec("CREATE TABLE IF NOT EXISTS users(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    pass TEXT NOT NULL,
    active INT DEFAULT 0,
    act_token TEXT,
    failcount INT DEFAULT 0,
    lockuntil INT DEFAULT 0,
    yt_token TEXT DEFAULT NULL,
    sp_token TEXT DEFAULT NULL,
    yt_clientid TEXT DEFAULT NULL,
    yt_secret TEXT DEFAULT NULL,
    yt_redirect TEXT DEFAULT NULL,
    sp_clientid TEXT DEFAULT NULL,
    sp_secret TEXT DEFAULT NULL,
    sp_redirect TEXT DEFAULT NULL,
    created_at INTEGER NOT NULL
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS favorites(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    stationuuid TEXT NOT NULL,
    name TEXT,
    favicon TEXT,
    added_at INTEGER NOT NULL,
    UNIQUE(user_id, stationuuid)
  )");
  $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets(
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INT NOT NULL,
    token TEXT NOT NULL,
    expires INT NOT NULL
  )");
  return $pdo;
}

/* Cache */
function cache_get($key){
  $f = __DIR__.'/cache/'.sha1($key).'.json';
  if (file_exists($f)) {
    global $CFG; $age=time()-filemtime($f);
    if ($age < ($CFG['cache_ttl']??600)) return file_get_contents($f);
  }
  return null;
}
function cache_set($key,$val){
  @mkdir(__DIR__.'/cache',0775,true);
  @file_put_contents(__DIR__.'/cache/'.sha1($key).'.json',$val);
}

/* Radio Browser HTTP */
function rb_get_json(string $path, array $query=[]): string{
  global $CFG;
  $qs=http_build_query($query); $cache_key=$path.'?'.$qs;
  if($c=cache_get($cache_key)) return $c;
  $heads=['Accept: application/json','User-Agent: OnlineRadioPP/1.0'];
  foreach($CFG['rb_endpoints'] as $base){
    $url=rtrim($base,'/').$path.($qs?('?'.$qs):'');
    if(function_exists('curl_init')){
      foreach([CURL_IPRESOLVE_V4,CURL_IPRESOLVE_WHATEVER] as $ip){
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>$CFG['timeout'],CURLOPT_TIMEOUT=>$CFG['timeout'],CURLOPT_HTTPHEADER=>$heads,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_ENCODING=>'',
          CURLOPT_IPRESOLVE=>$ip]);
        $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if($res!==false && $code>=200 && $code<300){ cache_set($cache_key,$res); return $res; }
      }
    }
    if(filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)){
      $ctx=stream_context_create(['http'=>['timeout'=>$CFG['timeout'],'method'=>'GET','header'=>"User-Agent: OnlineRadioPP/1.0\r\nAccept: application/json\r\n"]]);
      $res=@file_get_contents($url,false,$ctx);
      if($res!==false){ cache_set($cache_key,$res); return $res; }
    }
  }
  return '[]';
}

/* YouTube config per user (fallback op global) */
function yt_cfg_for_user(): array {
  global $CFG; $pdo=db(); $uid=me();
  $g=$CFG['google'];
  if($pdo && $uid){
    $st=$pdo->prepare("SELECT yt_clientid, yt_secret, yt_redirect FROM users WHERE id=?");
    $st->execute([$uid]); $r=$st->fetch();
    if(!empty($r['yt_clientid']) && !empty($r['yt_secret']) && !empty($r['yt_redirect'])){
      $g['client_id']=$r['yt_clientid']; $g['client_secret']=$r['yt_secret']; $g['redirect_uri']=$r['yt_redirect'];
    }
  }
  return $g;
}
function yt_ready_for_user(): bool {
  $g=yt_cfg_for_user();
  return $g['client_id']!=='PASTE_CLIENT_ID.apps.googleusercontent.com' && $g['client_secret']!=='PASTE_CLIENT_SECRET' && !empty($g['redirect_uri']);
}

/* Spotify config per user (fallback op global) */
function sp_cfg_for_user(): array {
  global $CFG; $pdo=db(); $uid=me();
  $s=$CFG['spotify'];
  if($pdo && $uid){
    $st=$pdo->prepare("SELECT sp_clientid, sp_secret, sp_redirect FROM users WHERE id=?");
    $st->execute([$uid]); $r=$st->fetch();
    if(!empty($r['sp_clientid']) && !empty($r['sp_secret']) && !empty($r['sp_redirect'])){
      $s['client_id']=$r['sp_clientid']; $s['client_secret']=$r['sp_secret']; $s['redirect_uri']=$r['sp_redirect'];
    }
  }
  return $s;
}
function sp_ready_for_user(): bool {
  $s=sp_cfg_for_user();
  return $s['client_id']!=='PASTE_SPOTIFY_CLIENT_ID' && $s['client_secret']!=='PASTE_SPOTIFY_CLIENT_SECRET' && !empty($s['redirect_uri']);
}
PHP);

/* ============ lib/mail.php (SMTP of mail()) ============ */
put('lib/mail.php', <<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';

function mail_send(string $to, string $subject, string $body, string $alt=''): bool {
  global $CFG; $m=$CFG['mail'];
  $from = $m['from']; $fname=$m['from_name'] ?? 'noreply';
  $subject = '=?UTF-8?B?'.base64_encode($subject).'?=';
  $boundary = 'b'.bin2hex(random_bytes(8));
  $headers = [];
  $headers[]="From: ".sprintf('"%s" <%s>', addslashes($fname), $from);
  $headers[]="MIME-Version: 1.0";
  $headers[]="Content-Type: multipart/alternative; boundary=\"$boundary\"";
  $bodyAlt = $alt ?: strip_tags(str_replace(["<br>","<br/>","<br />"], "\n", $body));
  $msg = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$bodyAlt\r\n\r\n".
         "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$body\r\n\r\n--$boundary--\r\n";

  if(($m['driver'] ?? 'smtp') === 'mail'){
    return mail($to, $subject, $msg, implode("\r\n",$headers));
  }

  // SMTP
  $host=$m['host']; $port=(int)$m['port']; $sec=strtoupper($m['security']??'');
  $user=$m['username']; $pass=$m['password'];
  $remote = ($sec==='SSL' ? "ssl://$host" : $host);
  $fp = @fsockopen($remote, $port, $errno, $errstr, 15);
  if(!$fp) return false;
  $read=function()use($fp){ $r=''; while($l=fgets($fp,515)){ $r.=$l; if(preg_match('/^\d{3} /',$l)) break; } return $r; };
  $send=function($cmd)use($fp){ fputs($fp,$cmd."\r\n"); };
  $code=function($s){ return (int)substr(trim($s),0,3); };

  if($code($read())!==220){ fclose($fp); return false; }
  $send("EHLO ".$_SERVER['SERVER_NAME'] ?? 'localhost'); $ehlo=$read();
  if($sec==='STARTTLS'){ $send("STARTTLS"); if($code($read())!==220){ fclose($fp); return false; } if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)){ fclose($fp); return false; } $send("EHLO ".$_SERVER['SERVER_NAME'] ?? 'localhost'); $read(); }
  if($user){ $send("AUTH LOGIN"); if($code($read())!==334){ fclose($fp); return false; }
    $send(base64_encode($user)); if($code($read())!==334){ fclose($fp); return false; }
    $send(base64_encode($pass)); if($code($read())!==235){ fclose($fp); return false; } }
  $send("MAIL FROM:<$from>"); if($code($read())>=400){ fclose($fp); return false; }
  $send("RCPT TO:<$to>");     if($code($read())>=400){ fclose($fp); return false; }
  $send("DATA");              if($code($read())!==354){ fclose($fp); return false; }
  $data = "To: <$to>\r\nSubject: $subject\r\n".implode("\r\n",$headers)."\r\n\r\n".$msg."\r\n.";
  fputs($fp,$data."\r\n");
  if($code($read())>=400){ fclose($fp); return false; }
  $send("QUIT"); fclose($fp); return true;
}
PHP);

/* ============ lib/recaptcha.php ============ */
put('lib/recaptcha.php', <<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
function recaptcha_ok(): bool {
  global $CFG;
  $secret=$CFG['recaptcha']['secret']??''; if(!$secret) return true; // uit wanneer leeg
  $resp=$_POST['g-recaptcha-response'] ?? '';
  if(!$resp) return false;
  $ch=curl_init('https://www.google.com/recaptcha/api/siteverify');
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['secret'=>$secret,'response'=>$resp,'remoteip'=>$_SERVER['REMOTE_ADDR']??''])]);
  $res=curl_exec($ch); curl_close($ch);
  $j=json_decode($res,true); return (bool)($j['success'] ?? false);
}
function recaptcha_widget(): string {
  global $CFG; $site=$CFG['recaptcha']['site_key']??'';
  if(!$site) return ''; // geen widget
  return '<div class="g-recaptcha" data-sitekey="'.htmlspecialchars($site).'"></div><script src="https://www.google.com/recaptcha/api.js" async defer></script>';
}
PHP);

/* ============ lib/oauth_youtube.php ============ */
put('lib/oauth_youtube.php', <<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';

function yt_user_token(){ $pdo=db(); if(!$pdo||!me()) return null; $st=$pdo->prepare("SELECT yt_token FROM users WHERE id=?"); $st->execute([me()]); $t=$st->fetchColumn(); return $t?json_decode($t,true):null; }
function yt_save_token($tok){ $pdo=db(); $st=$pdo->prepare("UPDATE users SET yt_token=? WHERE id=?"); $st->execute([json_encode($tok), me()]); }

function http_json($method,$url,$headers=[],$body=null,$timeout=15){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>$timeout,
    CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json','User-Agent: OnlineRadioPP/1.0'],$headers),CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
  if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
  $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code,$res];
}
function yt_refresh_if_needed(){
  $tok=yt_user_token(); if(!$tok) return null;
  if(isset($tok['expires_at']) && time() < $tok['expires_at']-60) return $tok;
  if(empty($tok['refresh_token'])) return $tok;
  $g=yt_cfg_for_user();
  [$c,$res]=http_json('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],
    http_build_query(['client_id'=>$g['client_id'],'client_secret'=>$g['client_secret'],'grant_type'=>'refresh_token','refresh_token'=>$tok['refresh_token']]));
  if($c===200){ $j=json_decode($res,true); $tok['access_token']=$j['access_token']; $tok['expires_in']=$j['expires_in']??3600; $tok['expires_at']=time()+($tok['expires_in']??3600); yt_save_token($tok); }
  return $tok;
}
function yt_get($path,$params=[]){
  $tok=yt_refresh_if_needed(); if(!$tok) return [401,null];
  $url='https://www.googleapis.com/youtube/v3'.$path.'?'.http_build_query($params);
  [$c,$res]=http_json('GET',$url,['Authorization: Bearer '.$tok['access_token']]); return [$c, $res?json_decode($res,true):null];
}
function yt_post($path,$params,$obj){
  $tok=yt_refresh_if_needed(); if(!$tok) return [401,null];
  $url='https://www.googleapis.com/youtube/v3'.$path.'?'.http_build_query($params);
  [$c,$res]=http_json('POST',$url,['Authorization: Bearer '.$tok['access_token'],'Content-Type: application/json'], json_encode($obj));
  return [$c, $res?json_decode($res,true):null];
}
PHP);

/* ============ lib/oauth_spotify.php ============ */
put('lib/oauth_spotify.php', <<<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';

function sp_user_token(){ $pdo=db(); if(!$pdo||!me()) return null; $st=$pdo->prepare("SELECT sp_token FROM users WHERE id=?"); $st->execute([me()]); $t=$st->fetchColumn(); return $t?json_decode($t,true):null; }
function sp_save_token($tok){ $pdo=db(); $st=$pdo->prepare("UPDATE users SET sp_token=? WHERE id=?"); $st->execute([json_encode($tok), me()]); }

function http_json_sp($method,$url,$headers=[],$body=null,$timeout=15){
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>$timeout,
    CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json','User-Agent: OnlineRadioPP/1.0'],$headers),CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
  if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS=>$body);
  $res=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code,$res];
}
function sp_refresh_if_needed(){
  $tok=sp_user_token(); if(!$tok) return null;
  if(isset($tok['expires_at']) && time() < $tok['expires_at']-60) return $tok;
  if(empty($tok['refresh_token'])) return $tok;
  $s=sp_cfg_for_user();
  [$c,$res]=http_json_sp('POST','https://accounts.spotify.com/api/token',[
    'Authorization: Basic '.base64_encode($s['client_id'].':'.$s['client_secret']),
    'Content-Type: application/x-www-form-urlencoded'
  ], http_build_query(['grant_type'=>'refresh_token','refresh_token'=>$tok['refresh_token']]));
  if($c===200){ $j=json_decode($res,true); $tok['access_token']=$j['access_token']; $tok['expires_in']=$j['expires_in']??3600; $tok['expires_at']=time()+($tok['expires_in']??3600); sp_save_token($tok); }
  return $tok;
}
function sp_get($path,$params=[]){
  $tok=sp_refresh_if_needed(); if(!$tok) return [401,null];
  $url='https://api.spotify.com/v1'.$path.($params?('?'.http_build_query($params)):'');
  [$c,$res]=http_json_sp('GET',$url,['Authorization: Bearer '.$tok['access_token']]); return [$c, $res?json_decode($res,true):null];
}
function sp_post($path,$params=[],$obj=null){
  $tok=sp_refresh_if_needed(); if(!$tok) return [401,null];
  $url='https://api.spotify.com/v1'.$path.($params?('?'.http_build_query($params)):'');
  [$c,$res]=http_json_sp('POST',$url,['Authorization: Bearer '.$tok['access_token'],'Content-Type: application/json'], $obj?json_encode($obj):'');
  return [$c, $res?json_decode($res,true):null];
}
PHP);

/* ============ auth & activation & reset ============ */
put('auth.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/config.php';
require __DIR__.'/lib/mail.php';
require __DIR__.'/lib/recaptcha.php';
start_session();
$pdo = db();

$action = gp('a','');
if ($action==='logout'){ session_destroy(); header('Location: /'); exit; }

$err = ''; $msg='';

if ($_SERVER['REQUEST_METHOD']==='POST'){
  if ($action==='register'){
    $email = strtolower(pp('email')); $pass = pp('pass'); $pass2 = pp('pass2');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) $err='Ongeldig e-mailadres';
    elseif ($pass!==$pass2) $err='Wachtwoorden komen niet overeen';
    elseif (!recaptcha_ok()) $err='reCAPTCHA mislukt';
    else {
      try{
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("INSERT INTO users(email,pass,active,act_token,created_at) VALUES(?,?,?,?,?)")
            ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), 0, $token, time()]);
        // mail
        $link = rtrim($CFG['site_url'],'/').'/activate.php?token='.$token;
        $body = '<p>Welkom bij '.$CFG['site_name'].'!</p><p>Activeer je account: <a href="'.$link.'">'.$link.'</a></p><p>Website: <a href="'.h($CFG['site_url']).'">'.h($CFG['site_url']).'</a></p>';
        mail_send($email, 'Activeer je account', $body);
        $msg='Account aangemaakt. Controleer je e-mail om te activeren.';
      }catch(Throwable $e){ $err='E-mail is al in gebruik.'; }
    }
  }
  if ($action==='login'){
    $email = strtolower(pp('email')); $pass = pp('pass');
    $st=$pdo->prepare("SELECT id,pass,active,failcount,lockuntil FROM users WHERE email=?"); $st->execute([$email]);
    if ($row=$st->fetch()){
      if($row['lockuntil']>time()){ $err='Account tijdelijk geblokkeerd. Probeer later opnieuw.'; }
      elseif(!$row['active']){ $err='Account nog niet geactiveerd (check e-mail).'; }
      elseif (password_verify($pass, $row['pass'])){ $_SESSION['uid']=(int)$row['id']; $pdo->prepare("UPDATE users SET failcount=0,lockuntil=0 WHERE id=?")->execute([$row['id']]); header('Location: /'); exit; }
      else {
        $fc=(int)$row['failcount']+1; $lu=($fc>=3)?(time()+3600):0;
        $pdo->prepare("UPDATE users SET failcount=?, lockuntil=? WHERE id=?")->execute([$fc,$lu,$row['id']]);
        $err = $fc>=3 ? 'Te veel mislukte pogingen. Geblokkeerd voor 60 minuten.' : 'Onjuiste login.';
      }
    } else { $err='Onjuiste login.'; }
  }
}

?><!doctype html><meta charset="utf-8"><title>Login / Registratie</title>
<link rel="stylesheet" href="assets/css/style.css">
<div class="container card">
  <h1><?php echo me()?'Account':'Login / Registreren'; ?></h1>
  <?php if($err): ?><p style="color:#f87171"><?php echo h($err); ?></p><?php endif; ?>
  <?php if($msg): ?><p style="color:#10b981"><?php echo h($msg); ?></p><?php endif; ?>

  <?php if(!me()): ?>
  <div class="grid">
    <form method="post" action="?a=login" class="card">
      <h2>Inloggen</h2>
      <input name="email" type="email" placeholder="E-mail" required>
      <input name="pass" type="password" placeholder="Wachtwoord" required>
      <button>Inloggen</button>
      <p><a href="/forgot.php">Wachtwoord vergeten?</a></p>
    </form>

    <form method="post" action="?a=register" class="card">
      <h2>Registreren</h2>
      <input name="email" type="email" placeholder="E-mail" required>
      <input name="pass" type="password" placeholder="Wachtwoord" required>
      <input name="pass2" type="password" placeholder="Herhaal wachtwoord" required>
      <?php echo recaptcha_widget(); ?>
      <button>Account aanmaken</button>
    </form>
  </div>
  <?php else: ?>
    <p>Ingelogd als user #<?php echo (int)me(); ?> — <a href="/auth.php?a=logout">Uitloggen</a></p>
    <form method="post" action="?a=pw" class="card">
      <h2>Wachtwoord wijzigen</h2>
      <input name="old" type="password" placeholder="Oud wachtwoord" required>
      <input name="new" type="password" placeholder="Nieuw wachtwoord" required>
      <input name="new2" type="password" placeholder="Herhaal nieuw wachtwoord" required>
      <button disabled>Niet hier — ga naar Portaal</button>
    </form>
    <p><a href="/portal.php">← Terug naar portaal</a></p>
  <?php endif; ?>
</div>
PHP);

put('activate.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/config.php';
$pdo=db();
$tok=gp('token');
if(!$tok){ die('Geen token.'); }
$st=$pdo->prepare("SELECT id FROM users WHERE act_token=?"); $st->execute([$tok]);
if($r=$st->fetch()){
  $pdo->prepare("UPDATE users SET active=1, act_token=NULL WHERE id=?")->execute([$r['id']]);
  echo "Account geactiveerd. <a href='/auth.php'>Inloggen</a>";
}else{ echo "Ongeldige token."; }
PHP);

put('forgot.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/config.php'; require __DIR__.'/lib/mail.php';
$pdo=db(); $msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $email=strtolower(pp('email'));
  $st=$pdo->prepare("SELECT id FROM users WHERE email=? AND active=1"); $st->execute([$email]);
  if($u=$st->fetch()){
    $tok=bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO password_resets(user_id,token,expires) VALUES(?,?,?)")->execute([$u['id'],$tok,time()+3600]);
    $link=rtrim($CFG['site_url'],'/').'/reset.php?token='.$tok;
    mail_send($email,'Wachtwoord reset','Klik om te resetten: <a href="'.$link.'">'.$link.'</a>');
  }
  $msg='Als dit e-mailadres bekend is, is er een resetmail verstuurd.';
}
?><!doctype html><meta charset="utf-8"><title>Wachtwoord vergeten</title>
<link rel="stylesheet" href="assets/css/style.css">
<div class="container card">
  <h1>Wachtwoord vergeten</h1>
  <form method="post">
    <input type="email" name="email" required placeholder="E-mail">
    <button>Verstuur resetlink</button>
  </form>
  <p class="muted"><?php echo h($msg); ?></p>
  <p><a href="/auth.php">← Terug</a></p>
</div>
PHP);

put('reset.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/config.php';
$pdo=db();
$tok=gp('token'); if(!$tok) die('Geen token.');
$st=$pdo->prepare("SELECT * FROM password_resets WHERE token=? AND expires>?"); $st->execute([$tok,time()]);
if(!$rec=$st->fetch()){ die('Ongeldige of verlopen token.'); }
if($_SERVER['REQUEST_METHOD']==='POST'){
  $p1=pp('pass'); $p2=pp('pass2');
  if($p1 && $p1===$p2){
    $pdo->prepare("UPDATE users SET pass=? WHERE id=?")->execute([password_hash($p1,PASSWORD_DEFAULT),$rec['user_id']]);
    $pdo->prepare("DELETE FROM password_resets WHERE user_id=?")->execute([$rec['user_id']]);
    echo "Wachtwoord aangepast. <a href='/auth.php'>Inloggen</a>";
    exit;
  } else $err='Wachtwoorden komen niet overeen.';
}
?><!doctype html><meta charset="utf-8"><title>Reset wachtwoord</title>
<link rel="stylesheet" href="assets/css/style.css">
<div class="container card">
  <h1>Reset wachtwoord</h1>
  <?php if(!empty($err)) echo '<p style="color:#f87171">'.h($err).'</p>'; ?>
  <form method="post">
    <input type="password" name="pass" required placeholder="Nieuw wachtwoord">
    <input type="password" name="pass2" required placeholder="Herhaal wachtwoord">
    <button>Opslaan</button>
  </form>
</div>
PHP);

/* ============ portal.php (YT/Spotify per user + favorieten) ============ */
put('portal.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/config.php';
start_session(); if(!me()){ header('Location: /auth.php'); exit; }
$pdo=db();
$ok=''; $err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(pp('act')==='save_oauth'){
    $pdo->prepare("UPDATE users SET yt_clientid=?, yt_secret=?, yt_redirect=?, sp_clientid=?, sp_secret=?, sp_redirect=? WHERE id=?")
        ->execute([pp('yt_clientid'),pp('yt_secret'),pp('yt_redirect'),pp('sp_clientid'),pp('sp_secret'),pp('sp_redirect'),me()]);
    $ok='OAuth instellingen opgeslagen.';
  }
}

$st=$pdo->prepare("SELECT email, yt_token, sp_token, yt_clientid, yt_secret, yt_redirect, sp_clientid, sp_secret, sp_redirect FROM users WHERE id=?");
$st->execute([me()]); $u=$st->fetch();

$favs = $pdo->prepare("SELECT * FROM favorites WHERE user_id=? ORDER BY added_at DESC");
$favs->execute([me()]); $favs=$favs->fetchAll();

?><!doctype html><meta charset="utf-8"><title>Portaal</title>
<link rel="stylesheet" href="assets/css/style.css">
<div class="container card">
  <h1>Gebruikersportaal</h1>
  <?php if($ok): ?><p style="color:#10b981"><?php echo h($ok); ?></p><?php endif; ?>
  <?php if($err): ?><p style="color:#f87171"><?php echo h($err); ?></p><?php endif; ?>
  <p>Ingelogd als: <strong><?php echo h($u['email']); ?></strong> — <a href="/auth.php?a=logout">Uitloggen</a></p>

  <h2>OAuth instellingen (optioneel per gebruiker)</h2>
  <form method="post" class="card">
    <input type="hidden" name="act" value="save_oauth">
    <h3>YouTube</h3>
    <input name="yt_clientid" placeholder="Client ID" value="<?php echo h($u['yt_clientid']); ?>">
    <input name="yt_secret" placeholder="Client Secret" value="<?php echo h($u['yt_secret']); ?>">
    <input name="yt_redirect" placeholder="Redirect URI" value="<?php echo h($u['yt_redirect']); ?>">
    <p><?php if(empty($u['yt_token'])): ?><a class="btn" href="/auth/google-start.php">Koppel Google</a><?php else: ?>✅ Token aanwezig — <a class="btn-secondary" href="/auth/google-unlink.php">Ontkoppel</a><?php endif; ?></p>

    <h3>Spotify</h3>
    <input name="sp_clientid" placeholder="Client ID" value="<?php echo h($u['sp_clientid']); ?>">
    <input name="sp_secret" placeholder="Client Secret" value="<?php echo h($u['sp_secret']); ?>">
    <input name="sp_redirect" placeholder="Redirect URI" value="<?php echo h($u['sp_redirect']); ?>">
    <p><?php if(empty($u['sp_token'])): ?><a class="btn" href="/auth/spotify-start.php">Koppel Spotify</a><?php else: ?>✅ Token aanwezig — <a class="btn-secondary" href="/auth/spotify-unlink.php">Ontkoppel</a><?php endif; ?></p>

    <button>Opslaan</button>
  </form>

  <h2>Favorieten</h2>
  <?php if(!$favs): ?><p class="muted">Nog geen favorieten.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach($favs as $f): ?>
        <li class="station">
          <div style="display:flex;align-items:center;gap:10px">
            <?php if($f['favicon']): ?><img class="logo" src="<?php echo h($f['favicon']); ?>" alt=""><?php endif; ?>
            <div><strong><?php echo h($f['name']?:$f['stationuuid']); ?></strong><br><small class="muted"><?php echo h($f['stationuuid']); ?></small></div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <p><a href="/">← Terug naar radio</a></p>
</div>
PHP);

put('auth/google-start.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/../config.php'; start_session(); if(!me()){ header('Location: /auth.php'); exit; }
$g=yt_cfg_for_user();
if($g['client_id']==='PASTE_CLIENT_ID.apps.googleusercontent.com'){ echo "YouTube OAuth niet geconfigureerd."; exit; }
$_SESSION['g_state']=bin2hex(random_bytes(12));
$params=['client_id'=>$g['client_id'],'redirect_uri'=>$g['redirect_uri'],'response_type'=>'code','scope'=>implode(' ',$g['scopes']),'access_type'=>'offline','prompt'=>'consent','state'=>$_SESSION['g_state']];
header('Location: https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($params)); exit;
PHP);

put('auth/google-callback.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/../config.php'; require __DIR__.'/../lib/oauth_youtube.php'; start_session();
if(!me()){ echo "Niet ingelogd"; exit; }
if(!isset($_GET['state']) || !hash_equals($_SESSION['g_state']??'', $_GET['state'])){ echo "State mismatch"; exit; }
if(!isset($_GET['code'])){ echo "Geen code"; exit; }
$g=yt_cfg_for_user();
[$c,$res]=http_json('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],
  http_build_query(['code'=>$_GET['code'],'client_id'=>$g['client_id'],'client_secret'=>$g['client_secret'],'redirect_uri'=>$g['redirect_uri'],'grant_type'=>'authorization_code']));
if($c!==200){ echo "Token exchange mislukt ($c)"; exit; }
$j=json_decode($res,true);
$tok=['access_token'=>$j['access_token'],'expires_in'=>$j['expires_in']??3600,'expires_at'=>time()+($j['expires_in']??3600),'refresh_token'=>$j['refresh_token']??null,'scope'=>$j['scope']??'', 'token_type'=>$j['token_type']??'Bearer'];
yt_save_token($tok);
header('Location: /portal.php'); exit;
PHP);

put('auth/google-unlink.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/../config.php'; start_session(); if(!me()){ header('Location: /auth.php'); exit; }
$pdo=db(); $pdo->prepare("UPDATE users SET yt_token=NULL WHERE id=?")->execute([me()]);
header('Location: /portal.php');
PHP);

put('auth/spotify-start.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/../config.php'; start_session(); if(!me()){ header('Location: /auth.php'); exit; }
$s=sp_cfg_for_user();
if($s['client_id']==='PASTE_SPOTIFY_CLIENT_ID'){ echo "Spotify OAuth niet geconfigureerd."; exit; }
$_SESSION['s_state']=bin2hex(random_bytes(12));
$params=['client_id'=>$s['client_id'],'response_type'=>'code','redirect_uri'=>$s['redirect_uri'],'scope'=>implode(' ',$s['scopes']),'state'=>$_SESSION['s_state']];
header('Location: https://accounts.spotify.com/authorize?'.http_build_query($params)); exit;
PHP);

put('auth/spotify-callback.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/../config.php'; require __DIR__.'/../lib/oauth_spotify.php'; start_session();
if(!me()){ echo "Niet ingelogd"; exit; }
if(!isset($_GET['state']) || !hash_equals($_SESSION['s_state']??'', $_GET['state'])){ echo "State mismatch"; exit; }
if(!isset($_GET['code'])){ echo "Geen code"; exit; }
$s=sp_cfg_for_user();
[$c,$res]=http_json_sp('POST','https://accounts.spotify.com/api/token',[
  'Authorization: Basic '.base64_encode($s['client_id'].':'.$s['client_secret']),
  'Content-Type: application/x-www-form-urlencoded'
], http_build_query(['grant_type'=>'authorization_code','code'=>$_GET['code'],'redirect_uri'=>$s['redirect_uri']]));
if($c!==200){ echo "Token exchange mislukt ($c)"; exit; }
$j=json_decode($res,true);
$tok=['access_token'=>$j['access_token'],'expires_in'=>$j['expires_in']??3600,'expires_at'=>time()+($j['expires_in']??3600),'refresh_token'=>$j['refresh_token']??null,'scope'=>$j['scope']??''];
sp_save_token($tok);
header('Location: /portal.php'); exit;
PHP);

put('auth/spotify-unlink.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/../config.php'; start_session(); if(!me()){ header('Location: /auth.php'); exit; }
$pdo=db(); $pdo->prepare("UPDATE users SET sp_token=NULL WHERE id=?")->execute([me()]);
header('Location: /portal.php');
PHP);

/* ============ index + assets (UI) ============ */
put('assets/css/style.css', <<<'CSS'
:root{ --bg:#0f1117; --card:#1a1d29; --fg:#e5e7eb; --muted:#9ca3af; --accent:#10b981; --border:#2e3444; }
*{box-sizing:border-box} body{margin:0;font:16px/1.5 system-ui,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--fg)}
a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline}
.container{max-width:1200px;margin:0 auto;padding:16px}
header{background:var(--card);border-bottom:1px solid var(--border)}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin:12px 0}
.muted{color:var(--muted)}
.grid{display:grid;gap:8px} .grid-6{grid-template-columns:repeat(6,1fr)}
@media (max-width:960px){.grid-6{grid-template-columns:repeat(2,1fr)}}
input,select,button,.btn{padding:10px;border-radius:10px;border:1px solid var(--border);background:#0f1117;color:var(--fg)}
button,.btn{background:var(--accent);border:none;font-weight:600;cursor:pointer;text-align:center;display:inline-block}
.btn-secondary{background:#374151} .btn.hidden{display:none}
.flex{display:flex;align-items:center} .gap{gap:8px}
.layout{display:grid;grid-template-columns:1fr 320px;gap:16px}
@media (max-width:1100px){.layout{grid-template-columns:1fr}}
.list{list-style:none;margin:0;padding:0;display:grid;gap:12px}
.station{border:1px solid var(--border);border-radius:10px;padding:14px;background:#141826}
.s-head{display:flex;align-items:center;gap:12px}
.logo{width:40px;height:40px;border-radius:6px;border:1px solid var(--border);object-fit:contain}
.links{margin-top:8px;display:flex;gap:12px;align-items:center}
.links a{font-size:13px;display:inline-flex;gap:6px;align-items:center;opacity:.9}
.links a:hover{opacity:1}
.badge{display:inline-flex;align-items:center;background:#374151;color:#fff;padding:2px 6px;border-radius:6px;font-size:.75rem;margin:2px 6px 0 0}
.badge img.flag{width:16px;height:12px;object-fit:cover;border-radius:2px;vertical-align:-2px;margin-right:6px}
.toggle{cursor:pointer} .collapsed{display:none}
.player{ position:fixed; right:16px; bottom:16px; width:360px; max-width:92vw; background:var(--card); border:1px solid var(--border); border-radius:14px; box-shadow:0 8px 24px rgba(0,0,0,.35); padding:12px; z-index:9999;}
.player.hidden{ display:none; } .p-head{display:flex; align-items:center; gap:10px; margin-bottom:8px}
#pLogo{width:40px;height:40px;border-radius:8px;border:1px solid var(--border);background:#0f1117;object-fit:contain}
.p-name{font-weight:700} .p-track{font-size:.9rem}
.p-close{margin-left:auto;background:#0f1117;border:1px solid var(--border);color:var(--fg);padding:6px 10px;border-radius:8px;cursor:pointer}
#pAudio{width:100%} .p-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px}
.station.playing{border:2px solid var(--accent);background:rgba(16,185,129,.10);transition:all .25s ease}
.play-btn{margin-top:8px}
CSS);

put('assets/js/app.js', <<<'JS'
const $=(s,r=document)=>r.querySelector(s); const $$=(s,r=document)=>[...r.querySelectorAll(s)];
let activeLi=null, nowTimer=null, currentStation=null, currentNow='';

async function rbSearch(params={}){
  const res = await fetch('api/search.php?'+new URLSearchParams(params)); const j=await res.json(); return j.items||[];
}
async function rbPopular(){ const res=await fetch('api/search.php?popular=1'); const j=await res.json(); return j.items||[]; }

function renderStations(list){
  const ul=$('#stationList'); ul.innerHTML='';
  if(!list.length){ ul.innerHTML='<li class="muted">Geen resultaten.</li>'; return; }
  for(const s of list){
    const li=document.createElement('li'); li.className='station';
    const flag = s.countrycode ? `<img class="flag" src="https://flagcdn.com/20x15/${s.countrycode.toLowerCase()}.png" alt="${s.country}">` : '';
    li.innerHTML = `
      <div class="s-head">
        ${s.favicon?`<img class="logo" src="${s.favicon}" onerror="this.style.display='none'">`: '<div class="logo" style="background:#0f1117"></div>'}
        <div style="flex:1 1 auto">
          <div><strong>${s.name||'(naamloos)'}</strong></div>
          <div class="muted" style="font-size:.85rem">${s.bitrate?`${s.bitrate} kbps · `:''}${s.language||''}</div>
        </div>
        <button class="fav btn-secondary" title="Favoriet">★</button>
      </div>
      <div class="pills" style="margin-top:6px">
        ${s.tags?`<span class="badge">${s.tags}</span>`:''}
        ${s.country?`<span class="badge">${flag} ${s.country}</span>`:''}
      </div>
      <div class="flex gap" style="margin-top:8px">
        <button class="play-btn">▶ Afspelen</button>
        ${window.__STATE__.yt?' <a class="btn" id="ytBtn">YouTube</a>':''}
        ${window.__STATE__.sp?' <a class="btn" id="spBtn">Spotify</a>':''}
      </div>
      <div class="links">
        ${s.homepage?`<a href="${s.homepage}" target="_blank" rel="noopener">🌐 Website</a>`:''}
        <a href="${s.url_resolved||s.url}" target="_blank" rel="noopener">↗ Open stream</a>
      </div>`;
    li.querySelector('.play-btn').addEventListener('click',()=>playStation(s,li));
    li.querySelector('.fav').addEventListener('click',()=>toggleFav(s,li));
    const y=li.querySelector('#ytBtn'); if(y) y.addEventListener('click',e=>{ e.preventDefault(); const q=encodeURIComponent((currentNow||'').trim() || (s.name||'')); location.href='yt.php?q='+q; });
    const sp=li.querySelector('#spBtn'); if(sp) sp.addEventListener('click',e=>{ e.preventDefault(); const q=encodeURIComponent((currentNow||'').trim() || (s.name||'')); location.href='sp.php?q='+q; });
    ul.appendChild(li);
  }
  updateFacets(list); updateStats(list);
}

function updateStats(list){
  const el=$('#statsBox');
  if(!list.length){ el.textContent='Geen data.'; return; }
  const cnt=list.length, avg=Math.round(list.reduce((a,b)=>a+(b.bitrate||0),0)/cnt || 0);
  const countries=[...new Set(list.map(x=>x.country).filter(Boolean))].length;
  el.innerHTML=`<div>Stations: <strong>${cnt}</strong></div><div>Gem. bitrate: <strong>${avg} kbps</strong></div><div>Landen: <strong>${countries}</strong></div>`;
}
function updateFacets(list){
  const el=$('#facets'); if(!list.length){ el.innerHTML='—'; return; }
  const topTags = {}; list.forEach(s=>{ (s.tags||'').split(',').map(t=>t.trim()).filter(Boolean).forEach(t=> topTags[t]=(topTags[t]||0)+1 ); });
  const sorted=Object.entries(topTags).sort((a,b)=>b[1]-a[1]).slice(0,50);
  el.innerHTML = sorted.map(([t,c])=>`<span class="badge">${t} · ${c}</span>`).join(' ') || '—';
}

function playStation(s, li){
  const audio=$('#pAudio'), name=$('#pName'), track=$('#pTrack'), logo=$('#pLogo'), player=$('#player');
  $$('.station.playing').forEach(el=>el.classList.remove('playing')); activeLi=li; li.classList.add('playing');
  currentStation=s; name.textContent=s.name||'(naamloos)'; track.textContent='—';
  if(s.favicon){ logo.src=s.favicon; logo.style.display='block'; } else { logo.style.display='none'; }
  player.classList.remove('hidden');
  audio.src=s.url_resolved||s.url; audio.play().catch(()=>{});
  if(nowTimer) clearInterval(nowTimer);
  fetch('api/np.php?u='+encodeURIComponent(audio.src)).then(r=>r.json()).then(async j=>{
    currentNow=j.now||''; 
    if(!currentNow){ const jf=await (await fetch('api/fp.php?u='+encodeURIComponent(audio.src))).json(); if(jf && jf.title){ currentNow = jf.artist? (jf.artist+' - '+jf.title) : jf.title; } }
    track.textContent=currentNow||'—'; updateDeepLinks();
  });
  nowTimer=setInterval(async ()=>{
    const j = await (await fetch('api/np.php?u='+encodeURIComponent(audio.src))).json();
    if(j.now){ currentNow=j.now; track.textContent=currentNow; updateDeepLinks(); }
  }, 30000);
}
function initPlayer(){
  const audio=$('#pAudio'), player=$('#player');
  $('#pClose').addEventListener('click',()=>{ audio.pause(); player.classList.add('hidden'); if(activeLi) activeLi.classList.remove('playing'); });
  $('#pStop').addEventListener('click',()=>{ audio.pause(); audio.removeAttribute('src'); audio.load(); if(activeLi) activeLi.classList.remove('playing'); });
}
function updateDeepLinks(){
  const q=encodeURIComponent((currentNow||'').trim() || (currentStation?.name||'')); 
  const y=$('#pYT'); if(y) y.href='yt.php?q='+q;
  const s=$('#pSP'); if(s) s.href='sp.php?q='+q;
}

// favorieten
async function toggleFav(s, li){
  if(!window.__STATE__.logged){ alert('Log eerst in om favorieten te gebruiken.'); return; }
  const res = await fetch('api/fav.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({act:'toggle', stationuuid:s.stationuuid, name:s.name, favicon:s.favicon})});
  const j = await res.json(); if(j.ok){ li.classList.toggle('fav-on', j.faved); }
}

// multi-select params
function getFormParams(form){
  const obj={};
  $$('select[multiple]',form).forEach(sel=> obj[sel.name]=[...sel.selectedOptions].map(o=>o.value) );
  obj.q = form.q.value.trim();
  obj.order = form.order.value;
  return obj;
}
function clientFilter(items, p){
  return items.filter(s=>{
    const okTag = !p.tag?.length || (s.tags||'').split(',').some(t=> p.tag.includes(t.trim()));
    const okCountry = !p.country?.length || (p.country.includes(s.country));
    const okLang = !p.language?.length || (p.language.includes(s.language));
    const okBit = !p.min_bitrate?.length || (s.bitrate && p.min_bitrate.some(x=> +x <= +s.bitrate));
    const okQ = !p.q || ((s.name||'').toLowerCase().includes(p.q.toLowerCase()) || (s.tags||'').toLowerCase().includes(p.q.toLowerCase()));
    return okTag && okCountry && okLang && okBit && okQ;
  });
}

async function loadPopular(){ const items=await rbPopular(); renderStations(items); }

document.addEventListener('DOMContentLoaded', async ()=>{
  $$('.toggle').forEach(t => t.addEventListener('click',()=>{ const tgt=$(t.dataset.tgt); tgt.classList.toggle('collapsed'); }));
  initPlayer();
  const form=$('#searchForm');
  $('#resetBtn').addEventListener('click', ()=>{ form.reset(); loadPopular(); });
  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const p=getFormParams(form);
    const items=await rbSearch({ order:p.order||'clickcount', limit:200 });
    renderStations(clientFilter(items,p));
  });
  await loadPopular();
});
JS);

put('index.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/config.php';
start_session();
$logged = (bool)me();
$ytReady = yt_ready_for_user() && (function(){ $pdo=db(); $st=$pdo->prepare("SELECT yt_token FROM users WHERE id=?"); $st->execute([me()]); return (bool)$st->fetchColumn(); })();
$spReady = sp_ready_for_user() && (function(){ $pdo=db(); $st=$pdo->prepare("SELECT sp_token FROM users WHERE id=?"); $st->execute([me()]); return (bool)$st->fetchColumn(); })();
?><!doctype html>
<html lang="nl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Online Radio++</title>
<link rel="stylesheet" href="assets/css/style.css">
<script>window.__STATE__={logged:<?php echo $logged?'true':'false'; ?>, yt:<?php echo $ytReady?'true':'false'; ?>, sp:<?php echo $spReady?'true':'false'; ?>};</script>
<script defer src="assets/js/app.js"></script>
</head><body>
<header class="container">
  <h1>Online Radio++</h1>
  <p class="muted">Via <a href="https://www.radio-browser.info" target="_blank" rel="noopener">Radio Browser</a>.
   — <?php if($logged): ?><a href="/portal.php">Portaal</a> · <a href="/auth.php?a=logout">Uitloggen</a><?php else: ?><a href="/auth.php">Inloggen/Registreren</a><?php endif; ?></p>
</header>

<div class="container layout">
  <main>
    <section class="filters card">
      <form id="searchForm" class="grid grid-6">
        <input type="search" name="q" placeholder="Zoek op naam / tags / url">
        <select name="tag" multiple size="5" title="Tags (genres)">
          <option>pop</option><option>rock</option><option>dance</option><option>electronic</option>
          <option>news</option><option>jazz</option><option>classical</option><option>hiphop</option>
          <option>80s</option><option>90s</option><option>country</option><option>house</option>
        </select>
        <select name="country" multiple size="5" title="Landen">
          <option>Netherlands</option><option>Belgium</option><option>Germany</option><option>France</option>
          <option>United Kingdom</option><option>United States Of America</option><option>Spain</option>
          <option>Italy</option><option>Canada</option><option>Brazil</option><option>Sweden</option>
          <option>Norway</option><option>Poland</option><option>Mexico</option><option>Japan</option>
        </select>
        <select name="language" multiple size="5" title="Talen">
          <option>Dutch</option><option>English</option><option>German</option><option>French</option>
          <option>Spanish</option><option>Italian</option><option>Portuguese</option><option>Hindi</option>
        </select>
        <select name="min_bitrate" multiple size="5" title="Min. bitrate">
          <option>64</option><option>96</option><option>128</option><option>192</option><option>256</option><option>320</option>
        </select>
        <select name="order">
          <option value="clickcount">Populair</option><option value="bitrate">Bitrate</option><option value="name">Naam</option>
        </select>
        <div class="flex gap">
          <button type="submit">Zoeken</button>
          <button type="button" id="resetBtn" class="btn-secondary">Reset</button>
        </div>
      </form>
    </section>

    <section class="card">
      <h2>Stations</h2>
      <ul id="stationList" class="list"><li class="muted">Laden… (top 25)</li></ul>
    </section>
  </main>

  <aside class="side">
    <section class="card">
      <h2 class="toggle" data-tgt="#tags">Tags &amp; Facetten <span class="muted">(klik)</span></h2>
      <div id="tags" class="collapsed">
        <div id="facets"></div>
      </div>
    </section>

    <section class="card">
      <h2>Stats</h2>
      <div id="statsBox" class="muted">Nog geen data.</div>
    </section>
  </aside>
</div>

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
    <button id="pStop" class="btn-secondary" title="Stop">■ Stop</button>
    <?php if($ytReady): ?><a id="pYT" class="btn" href="yt.php">➕ YouTube</a><?php endif; ?>
    <?php if($spReady): ?><a id="pSP" class="btn" href="sp.php">➕ Spotify</a><?php endif; ?>
  </div>
</aside>

<footer class="container muted"><p>© <?php echo date('Y'); ?> Online Radio++</p></footer>
</body></html>
PHP);

/* ============ API: search / np / fp / fav ============ */
put('api/search.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');
$popular = isset($_GET['popular']);
$limit   = max(1, min(200, (int)($_GET['limit'] ?? ($popular?25:($CFG['default_limit']??100)))));
$order   = $_GET['order'] ?? ($popular?'clickcount':'name');
$params = ['limit'=>$limit,'offset'=>0,'hidebroken'=>'true','is_https'=>'true','order'=>$order];
$json = rb_get_json('/json/stations/search', $params);
$rows = json_decode($json,true) ?: [];
$dead = []; $deadFile=__DIR__.'/../data/dead_urls.json';
if (is_file($deadFile)){ $dead=json_decode(file_get_contents($deadFile),true) ?: []; $dead=array_flip($dead); }
$out=[];
foreach($rows as $s){
  $u = $s['url_resolved'] ?? $s['url'] ?? '';
  if ($u && isset($dead[$u])) continue;
  $out[]=[
    'stationuuid'=>$s['stationuuid']??'','name'=>$s['name']??'','url'=>$s['url']??'',
    'url_resolved'=>$s['url_resolved']??'','homepage'=>$s['homepage']??'','favicon'=>$s['favicon']??'',
    'tags'=>$s['tags']??'','country'=>$s['country']??'','countrycode'=>$s['countrycode']??'','language'=>$s['language']??'',
    'bitrate'=>$s['bitrate']??null,
  ];
}
echo json_encode(['items'=>$out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
PHP);

put('api/np.php', <<<'PHP'
<?php
declare(strict_types=1);
ini_set('display_errors','0'); error_reporting(0);
header('Content-Type: application/json; charset=utf-8');
$u = isset($_GET['u']) ? trim((string)$_GET['u']) : '';
if ($u==='' || !preg_match('#^https?://#i',$u)) { echo '{"now":null}'; exit; }
function curlget($url,$timeout=6){
  if (!function_exists('curl_init')) return false;
  $ch = curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
    CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>$timeout,
    CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_ENCODING=>'',
    CURLOPT_USERAGENT=>'OnlineRadioPP/1.0',]);
  $res = curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  return ($res!==false && $code>=200 && $code<300) ? $res : false;
}
function icy_now(string $url, int $timeout=6): ?string{
  $p=parse_url($url); if(!$p||empty($p['host'])) return null;
  $scheme=strtolower($p['scheme']??'http'); $host=$p['host']; $port=$p['port']??($scheme==='https'?443:80);
  $path=($p['path']??'/').(isset($p['query'])?('?'.$p['query']):'');
  $transport=$scheme==='https'?'ssl://':'';
  $fp=@stream_socket_client(($transport?$transport:'').$host.":".$port,$errno,$err,$timeout,STREAM_CLIENT_CONNECT,
    stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true,'peer_name'=>$host]]));
  if(!$fp) return null; stream_set_timeout($fp,$timeout);
  fwrite($fp, "GET $path HTTP/1.1\r\nHost: $host\r\nUser-Agent: OnlineRadioPP/1.0\r\nIcy-MetaData: 1\r\nConnection: close\r\n\r\n");
  $hdr=''; while(!feof($fp)){ $l=fgets($fp,4096); if($l===false)break; $hdr.=$l; if(rtrim($l)=='')break; if(strlen($hdr)>32768)break; }
  if(!preg_match('/icy-metaint:\s*(\d+)/i',$hdr,$m)){ fclose($fp); return null; }
  $mi=(int)$m[1]; if($mi<=0){ fclose($fp); return null; }
  $skip=$mi; while($skip>0 && !feof($fp)){ $c=fread($fp,min(8192,$skip)); if($c===false)break; $skip-=strlen($c); }
  $lb=fgetc($fp); if($lb===false){ fclose($fp); return null; } $len=ord($lb)*16; if($len===0){ fclose($fp); return null; }
  $meta=''; while(strlen($meta)<$len && !feof($fp)){ $c=fread($fp,$len-strlen($meta)); if($c===false)break; $meta.=$c; }
  fclose($fp);
  if($meta && preg_match("#StreamTitle='([^']*)'#",$meta,$mm)){ $t=trim($mm[1]); return $t!==''?$t:null; }
  return null;
}
$now = icy_now($u);
if(!$now){
  $base=preg_replace('#(\.m3u8?|/stream.*)$#i','',$u);
  if($base){
    foreach(['/status-json.xsl','/status-json.xsl?mount=','/status.xsl'] as $p){
      $res=curlget($base.$p);
      if($res){
        $j=json_decode($res,true);
        if(isset($j['icestats']['source'])){ $src=$j['icestats']['source']; if(isset($src[0]))$src=$src[0]; $now=$src['title']??($src['artist']??null); if($now)break; }
        elseif(preg_match('#Current Song</td>\s*<td[^>]*>(.*?)</td>#is',$res,$m)){ $now=trim(strip_tags($m[1])); break; }
      }
    }
  }
}
if(!$now){
  $base2=preg_replace('#/;\\?*.*$#','',$u);
  foreach(['/7.html','/stats?json=1'] as $p){
    $res=curlget($base2.$p);
    if($res){
      if($p==='/7.html' && strpos($res,',')!==false){ $parts=explode(',',$res,8); $now=trim($parts[count($parts)-1]??''); if($now==='')$now=null; }
      else { $j=json_decode($res,true); if(isset($j['songtitle'])) $now=$j['songtitle']; }
      if($now)break;
    }
  }
}
echo json_encode(['now'=>$now?:null], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
PHP);

put('api/fp.php', <<<'PHP'
<?php
// OPTIONAL fingerprint via ffmpeg + fpcalc + AcoustID
declare(strict_types=1);
require __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');
$u = isset($_GET['u']) ? trim((string)$_GET['u']) : '';
$api = $CFG['acoustid']['apikey'] ?? '';
if(!$u || !$api){ echo '{"ok":false}'; exit; }

$tmp = sys_get_temp_dir().'/orpp_'.bin2hex(random_bytes(4)).'.wav';
$fpcalc = trim(shell_exec('which fpcalc 2>/dev/null') ?? '');
$ffmpeg = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
if(!$fpcalc || !$ffmpeg){ echo '{"ok":false}'; exit; }

@unlink($tmp);
// 8s sample; downmix; 44100 mono wav
$cmd = escapeshellcmd($ffmpeg).' -y -i '.escapeshellarg($u).' -t 8 -ac 1 -ar 44100 -vn -sn '.escapeshellarg($tmp).' 2>/dev/null';
exec($cmd,$o,$rc); if($rc!==0 || !is_file($tmp)){ echo '{"ok":false}'; exit; }
$fpout = shell_exec(escapeshellcmd($fpcalc).' -json '.escapeshellarg($tmp).' 2>/dev/null'); @unlink($tmp);
if(!$fpout){ echo '{"ok":false}'; exit; }
$j = json_decode($fpout,true); if(empty($j['fingerprint']) || empty($j['duration'])){ echo '{"ok":false}'; exit; }
$fp = $j['fingerprint']; $dur = (int)$j['duration'];

$ch=curl_init('https://api.acoustid.org/v2/lookup');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>http_build_query(['client'=>$api,'meta'=>'recordings+releasegroups+compress','fingerprint'=>$fp,'duration'=>$dur])]);
$res=curl_exec($ch); curl_close($ch);
$ans=json_decode($res,true);
$title=null; $artist=null; $album=null;
if(($ans['status']??'')==='ok' && !empty($ans['results'][0]['recordings'][0])){
  $rec=$ans['results'][0]['recordings'][0];
  $title = $rec['title'] ?? null;
  $artist = $rec['artists'][0]['name'] ?? null;
  $album = $rec['releasegroups'][0]['title'] ?? null;
}
echo json_encode(['ok'=>true,'title'=>$title,'artist'=>$artist,'album'=>$album], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
PHP);

put('api/fav.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/../config.php';
header('Content-Type: application/json; charset=utf-8');
start_session(); $uid=me();
if(!$uid){ echo json_encode(['ok'=>false,'err'=>'login']); exit; }
$pdo=db();
$in=json_decode(file_get_contents('php://input'),true) ?: [];
$act=$in['act']??''; $uuid=$in['stationuuid']??''; $name=$in['name']??''; $favicon=$in['favicon']??'';
if($act==='toggle' && $uuid!==''){
  $st=$pdo->prepare("SELECT id FROM favorites WHERE user_id=? AND stationuuid=?"); $st->execute([$uid,$uuid]);
  if($st->fetch()){ $pdo->prepare("DELETE FROM favorites WHERE user_id=? AND stationuuid=?")->execute([$uid,$uuid]); echo json_encode(['ok'=>true,'faved'=>false]); }
  else { $pdo->prepare("INSERT OR IGNORE INTO favorites(user_id,stationuuid,name,favicon,added_at) VALUES(?,?,?,?,?)")->execute([$uid,$uuid,$name,$favicon,time()]); echo json_encode(['ok'=>true,'faved'=>true]); }
  exit;
}
echo json_encode(['ok'=>false]);
PHP);

/* ============ yt.php & sp.php (playlist kiezen + zoeken + toevoegen) ============ */
put('yt.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/config.php'; require __DIR__.'/lib/oauth_youtube.php';
start_session(); if(!me()){ header('Location: /auth.php'); exit; }
if(!yt_ready_for_user()){ echo "YouTube OAuth niet geconfigureerd."; exit; }
$q = gp('q');
$tok = yt_user_token();
?><!doctype html><meta charset="utf-8"><link rel="stylesheet" href="assets/css/style.css">
<div class="container card">
  <h1>YouTube</h1>
  <?php if(!$tok): ?>
    <p><a class="btn" href="/auth/google-start.php">Koppel Google</a></p>
  <?php else:
    [$c,$pls]=yt_get('/playlists',['part'=>'snippet,contentDetails','mine'=>'true','maxResults'=>50]);
    $vid=null; $resList=null;
    if($q!==''){ [$c2,$sr]=yt_get('/search',['part'=>'snippet','q'=>$q,'type'=>'video','maxResults'=>5]); if($c2===200){ $resList=$sr['items']??[]; if($resList){ $vid=$resList[0]['id']['videoId']??null; } } }
  ?>
  <form method="post">
    <label>Playlist:</label>
    <select name="pl">
      <?php foreach(($pls['items']??[]) as $p): ?><option value="<?php echo h($p['id']); ?>"><?php echo h($p['snippet']['title']); ?></option><?php endforeach; ?>
    </select>
    <label>Zoekterm:</label>
    <input name="q" value="<?php echo h($q); ?>" style="width:100%">
    <button class="btn" name="act" value="search">Zoek</button>
    <button class="btn" name="act" value="add" <?php echo $vid?'':'disabled'; ?>>Voeg 1e resultaat toe</button>
  </form>
  <?php if($resList!==null): ?>
    <ol>
      <?php foreach($resList as $it): $v=$it['id']['videoId']; ?>
        <li><a target="_blank" rel="noopener" href="https://www.youtube.com/watch?v=<?php echo h($v); ?>"><?php echo h($it['snippet']['title']); ?></a>
          <form method="post" style="display:inline"><input type="hidden" name="q" value="<?php echo h($q); ?>"><input type="hidden" name="pl" value="<?php echo h($_POST['pl']??''); ?>"><input type="hidden" name="v" value="<?php echo h($v); ?>"><button class="btn" name="act" value="addv">Voeg toe</button></form>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif;
  if(($_POST['act']??'')==='search'){ header('Location: yt.php?q='.urlencode($_POST['q'])); exit; }
  if(($_POST['act']??'')==='add' && !empty($_POST['pl']) && $vid){ [$cc,$ins]=yt_post('/playlistItems',['part'=>'snippet'],['snippet'=>['playlistId'=>$_POST['pl'],'resourceId'=>['kind'=>'youtube#video','videoId'=>$vid]]]); echo $cc===200?'<p>✅ Toegevoegd.</p>':'<p>❌ Mislukt.</p>'; }
  if(($_POST['act']??'')==='addv' && !empty($_POST['pl']) && !empty($_POST['v'])){ [$cc,$ins]=yt_post('/playlistItems',['part'=>'snippet'],['snippet'=>['playlistId'=>$_POST['pl'],'resourceId'=>['kind'=>'youtube#video','videoId'=>$_POST['v']]]]); echo $cc===200?'<p>✅ Toegevoegd.</p>':'<p>❌ Mislukt.</p>'; }
  ?>
  <?php endif; ?>
  <p><a href="/">← Terug</a></p>
</div>
PHP);

put('sp.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/config.php'; require __DIR__.'/lib/oauth_spotify.php';
start_session(); if(!me()){ header('Location: /auth.php'); exit; }
if(!sp_ready_for_user()){ echo "Spotify OAuth niet geconfigureerd."; exit; }
$q = gp('q');
$tok = sp_user_token();
?><!doctype html><meta charset="utf-8"><link rel="stylesheet" href="assets/css/style.css">
<div class="container card">
  <h1>Spotify</h1>
  <?php if(!$tok): ?>
    <p><a class="btn" href="/auth/spotify-start.php">Koppel Spotify</a></p>
  <?php else:
    // user id
    [$cU,$me]=sp_get('/me');
    $uid = $me['id'] ?? null;
    // playlists (eigen)
    [$c,$pls]=sp_get('/me/playlists',['limit'=>50]);
    // zoek track
    $tid=null; $resList=null;
    if($q!==''){
      [$c2,$sr]=sp_get('/search',['type'=>'track','limit'=>5,'q'=>$q]);
      if($c2===200){ $resList=$sr['tracks']['items'] ?? []; if($resList){ $tid=$resList[0]['uri']??null; } }
    }
  ?>
  <form method="post">
    <label>Playlist:</label>
    <select name="pl">
      <?php foreach(($pls['items']??[]) as $p): ?>
        <option value="<?php echo h($p['id']); ?>"><?php echo h($p['name']); ?></option>
      <?php endforeach; ?>
    </select>
    <label>Zoekterm:</label>
    <input name="q" value="<?php echo h($q); ?>" style="width:100%">
    <button class="btn" name="act" value="search">Zoek</button>
    <button class="btn" name="act" value="add" <?php echo $tid?'':'disabled'; ?>>Voeg 1e resultaat toe</button>
  </form>
  <?php if($resList!==null): ?>
    <ol>
      <?php foreach($resList as $it): ?>
        <li><?php echo h(($it['artists'][0]['name']??'').' - '.($it['name']??'')); ?>
          <form method="post" style="display:inline"><input type="hidden" name="q" value="<?php echo h($q); ?>"><input type="hidden" name="pl" value="<?php echo h($_POST['pl']??''); ?>"><input type="hidden" name="uri" value="<?php echo h($it['uri']??''); ?>"><button class="btn" name="act" value="addv">Voeg toe</button></form>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif;

  if(($_POST['act']??'')==='search'){ header('Location: sp.php?q='.urlencode($_POST['q'])); exit; }
  if(($_POST['act']??'')==='add' && !empty($_POST['pl']) && $tid){ [$cc,$ins]=sp_post("/playlists/".rawurlencode($_POST['pl'])."/tracks",[],['uris'=>[$tid]]); echo ($cc===201||$cc===200)?'<p>✅ Toegevoegd.</p>':'<p>❌ Mislukt.</p>'; }
  if(($_POST['act']??'')==='addv' && !empty($_POST['pl']) && !empty($_POST['uri'])){ [$cc,$ins]=sp_post("/playlists/".rawurlencode($_POST['pl'])."/tracks",[],['uris'=>[$_POST['uri']]]); echo ($cc===201||$cc===200)?'<p>✅ Toegevoegd.</p>':'<p>❌ Mislukt.</p>'; }
  ?>
  <?php endif; ?>
  <p><a href="/">← Terug</a></p>
</div>
PHP);

/* ============ diag & cron ============ */
put('diag.php', <<<'PHP'
<?php
require __DIR__.'/config.php';
header('Content-Type: text/plain; charset=utf-8');
echo "== Online Radio++ Diagnose ==\n";
echo "PHP: ".PHP_VERSION."\n";
echo "PDO_SQLITE: ".(extension_loaded('pdo_sqlite')?'yes':'no')."\n";
echo "DB: ".db_path()." ".(is_file(db_path())?'(ok)':'(nieuw)')."\n";
$payload = rb_get_json('/json/stations/search', ['limit'=>1,'hidebroken'=>'true','is_https'=>'true']);
echo "Radio Browser reachable: ".(strlen((string)$payload)>2?'yes':'no')."\n";
echo "YouTube config (user-ready): ".(yt_ready_for_user()?'yes':'no')."\n";
echo "Spotify config (user-ready): ".(sp_ready_for_user()?'yes':'no')."\n";
echo "AcoustID API key: ".(empty(($CFG['acoustid']['apikey']??''))?'no':'yes')."\n";
PHP);

put('cron/check_streams.php', <<<'PHP'
<?php
declare(strict_types=1);
chdir(dirname(__DIR__));
require __DIR__.'/../config.php';

$cache = __DIR__.'/../data/dead_urls.json';
$last = file_exists($cache) ? filemtime($cache) : 0;
if (time() - $last < 23*3600) { echo "Skip (recent run)\n"; exit; }

$json = rb_get_json('/json/stations/search', ['limit'=>500,'order'=>'clickcount','hidebroken'=>'true','is_https'=>'true']);
$rows = json_decode($json,true) ?: [];

$dead=[];
foreach($rows as $s){
  $u = $s['url_resolved'] ?? $s['url'] ?? null; if(!$u) continue;
  $ok=false;
  if(function_exists('curl_init')){
    $ch=curl_init($u);
    curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $ok = curl_exec($ch)!==false && (curl_getinfo($ch,CURLINFO_HTTP_CODE) >= 200);
    curl_close($ch);
  }else{
    $ctx=stream_context_create(['http'=>['method'=>'HEAD','timeout'=>8]]);
    $ok = @fopen($u,'r',false,$ctx)!==false;
  }
  if(!$ok) $dead[]=$u;
}
@mkdir(__DIR__.'/../data',0775,true);
file_put_contents($cache, json_encode(array_values(array_unique($dead))));
echo "Dead: ".count($dead)."\n";
PHP);

/* ============ mappen klaarzetten ============ */
@mkdir(__DIR__.'/assets/js',0775,true);
@mkdir(__DIR__.'/assets/css',0775,true);
@mkdir(__DIR__.'/api',0775,true);
@mkdir(__DIR__.'/auth',0775,true);
@mkdir(__DIR__.'/lib',0775,true);
@mkdir(__DIR__.'/cron',0775,true);
@mkdir(__DIR__.'/data',0775,true);
@mkdir(__DIR__.'/cache',0775,true);

out("\nKLAAR ✅  Ga naar /diag.php en daarna naar /");
out("Cron: php ". __DIR__ ."/cron/check_streams.php (1× per dag)");
out("Veiligheidstip: verwijder bootstrap.php na installatie.");
