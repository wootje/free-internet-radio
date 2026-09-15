<?php
/**
 * Online Radio++ — Single-file bootstrap.php
 * Build 1.6.9 (English interface + one-time administrator setup + GitHub documentation)
 */

declare(strict_types=1);
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int { return strlen($string); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}
// The Now Playing parser must also work on shared hosting without the mbstring extension.
if (!function_exists('mb_check_encoding')) {
    function mb_check_encoding(string $value, ?string $encoding = null): bool {
        $encoding = strtoupper((string)($encoding ?: 'UTF-8'));
        if ($encoding === 'UTF-8' || $encoding === 'UTF8') return preg_match('//u', $value) === 1;
        return true;
    }
}
if (!function_exists('mb_convert_encoding')) {
    function mb_convert_encoding(string $string, string $toEncoding, $fromEncoding = null): string {
        if (!function_exists('iconv')) return $string;
        $fromList = is_array($fromEncoding) ? $fromEncoding : preg_split('/\s*,\s*/', (string)$fromEncoding, -1, PREG_SPLIT_NO_EMPTY);
        if (!$fromList) $fromList = ['Windows-1252', 'ISO-8859-1', 'UTF-8'];
        foreach ($fromList as $from) {
            $converted = @iconv((string)$from, $toEncoding . '//IGNORE', $string);
            if (is_string($converted) && $converted !== '') return $converted;
        }
        return $string;
    }
}
ini_set('default_charset', 'UTF-8');
header_remove('X-Powered-By');
// Keep pages/sitemaps crawler-friendly; explicit no-store headers are only sent by AJAX JSON responses.
session_cache_limiter('');
session_name('orppx');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly'=>true, 'samesite'=>'Lax',
    'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'path'=>'/',
]);
session_start();

/* Important for long-running jobs */
ignore_user_abort(true);
set_time_limit(0);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_flush(); }
ob_implicit_flush(true);

/* =========================
   CONFIG
   ========================= */
const APP_NAME    = 'Online Radio++';
const APP_VERSION = '1.6.9';
const SITE_URL    = ''; // empty = auto-detect
const DB_PATH     = __DIR__ . '/orppx.sqlite';
const PAGE_SIZE   = 60;
const DEBUG       = false;

/* Server-side cache: speeds up station lists/search/results on shared hosting */
const CACHE_ENABLED      = true;
const CACHE_DIR          = __DIR__ . '/orppx-cache';
const CACHE_TTL_STATIONS = 300; // 5 minutes for station list/search pages
const CACHE_TTL_COUNTS   = 300; // 5 minutes for counters/footer totals
const CACHE_TTL_RECENT   = 120; // 2 minutes for recently played
const CACHE_TTL_FAVORITES = 300; // 5 minutes for per-user favorite IDs
const CACHE_TTL_NOWPLAYING = 12; // keep successful current-track metadata fresh
const CACHE_TTL_NOWPLAYING_EMPTY = 45; // do not repeatedly probe metadata-less streams
const CACHE_TTL_SEO        = 3600; // 1 hour for sitemap / SEO helper data

/* Now Playing lookup (server-side ICY metadata) */
const NOWPLAYING_TIMEOUT_SECONDS = 6;
const NOWPLAYING_MAX_SKIP_BYTES  = 262144; // safety cap before metadata block
const NOWPLAYING_MAX_META_BYTES  = 8192;

const MAIL_FROM = ''; // set a verified sender address to enable password-recovery/admin email

const IMPORT_MAX_URLS_PER_STEP = 15000;
const IMPORT_MAX_SECONDS_PER_STEP = 8;

/* Dead scan server job */
const DEADSCAN_STEP_MAX_SECONDS = 8;          // keep requests short, safe on shared hosting
const DEADSCAN_DEFAULT_BATCH    = 20;         // per step (roughly "parallel" from UI)
const DEADSCAN_MAX_BATCH        = 50;         // cap
const STREAM_CHECK_ATTEMPT_SECONDS = 4.0;     // strict admin scan: one audio-prefix test
const PLAYBACK_START_TIMEOUT_MS = 8000;       // visitor: no playback progress within 8 seconds
const PLAYBACK_SUCCESS_SECONDS = 2;           // require playback progress, not just loaded headers

/* This block is physically removed after the first successful setup or upgrade.
 * Do not add permanent or emergency credentials anywhere else in this file. */
/* ORPPX_FIRST_RUN_SEED_BEGIN */
function orppx_first_run_seed(): array {
    return ['username' => 'admin', 'password' => 'admin'];
}
/* ORPPX_FIRST_RUN_SEED_END */

/* =========================
   HELPERS
   ========================= */

function site_url(): string {
    if (SITE_URL !== '') return rtrim(SITE_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/bootstrap.php';
    $dir    = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $scheme . '://' . $host . ($dir !== '' ? $dir : '');
}

function debug_log($msg): void {
    if (!DEBUG) return;
    error_log('[ORPPX] ' . (is_scalar($msg) ? $msg : print_r($msg, true)));
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* State-changing station actions are POST-only and protected against CSRF. */
function csrf_token(): string {
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_valid(): bool {
    $given = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($given) && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $given);
}
function require_ajax_post_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        json_response(['ok'=>false, 'error'=>'POST required.'], 405);
    }
    if (!csrf_valid()) json_response(['ok'=>false, 'error'=>'Your page has expired. Refresh the page and try again.'], 403);
}

/** A bounded set of lock files; independent of whether response caching is enabled. */
function station_lock(string $name, float $waitSeconds = 0.0) {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    if (!is_dir(CACHE_DIR) || !is_writable(CACHE_DIR)) return false;
    $file = CACHE_DIR . '/' . cache_prefix_safe($name) . '.lock.php';
    $fp = @fopen($file, 'c+');
    if (!$fp) return false;
    $deadline = microtime(true) + max(0.0, $waitSeconds);
    do {
        if (@flock($fp, LOCK_EX | LOCK_NB)) {
            if ((int)@filesize($file) === 0) { @fwrite($fp, "<?php exit; ?>\n"); @fflush($fp); }
            return $fp;
        }
        if (microtime(true) >= $deadline) break;
        usleep(20000);
    } while (true);
    @fclose($fp);
    return false;
}
function station_unlock($lock): void {
    if (is_resource($lock)) { @flock($lock, LOCK_UN); @fclose($lock); }
}
function station_catalog_lock() {
    $lock = station_lock('station_catalog', 2.0);
    if (!$lock) throw new RuntimeException('The station database is busy, or orppx-cache is not writable. Please try again.');
    return $lock;
}
function station_transaction(callable $work) {
    $lock = station_catalog_lock();
    $pdo = null;
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $result = $work($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally { station_unlock($lock); }
}



function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function now_iso(): string { return date('c'); }

function time_ago(?string $iso): string {
    if (!$iso) return 'never';
    $ts = strtotime($iso);
    if (!$ts) return 'never';
    $diff = time() - $ts;
    if ($diff < 60) return $diff . 's ago';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}


/* =========================
   SERVER-SIDE CACHE
   ========================= */

function cache_prefix_safe(string $prefix): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $prefix) ?: 'cache';
}

function cache_dir_ready(): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!CACHE_ENABLED) { $ready = false; return false; }

    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    if (!is_dir(CACHE_DIR)) { $ready = false; return false; }

    // Prevent direct downloads when the cache folder is web-accessible (Apache/shared hosting).
    $htaccess = CACHE_DIR . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\n", LOCK_EX);
    }
    $index = CACHE_DIR . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) @file_put_contents($index, '', LOCK_EX);

    $ready = is_writable(CACHE_DIR);
    return $ready;
}

function cache_file_path(string $prefix, array $parts): string {
    $safePrefix = cache_prefix_safe($prefix);
    $payload = json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return CACHE_DIR . DIRECTORY_SEPARATOR . $safePrefix . '_' . hash('sha256', (string)$payload) . '.cache.php';
}

function cache_get(string $prefix, array $parts, int $ttlSeconds) {
    if ($ttlSeconds <= 0 || !cache_dir_ready()) return null;
    $file = cache_file_path($prefix, $parts);
    // fstat() and the bytes refer to the SAME opened file, even during an atomic
    // replacement. Do not unlink an expired path: another worker may just renew it.
    $fp = @fopen($file, 'rb');
    if (!$fp) return null;
    try {
        $stat = @fstat($fp);
        if (!is_array($stat) || time() - (int)$stat['mtime'] >= $ttlSeconds) return null;
        $raw = @stream_get_contents($fp);
    } finally { @fclose($fp); }
    if (!is_string($raw) || $raw === '') return null;
    $pos = strpos($raw, "\n");
    if ($pos === false) return null;
    $data = json_decode(substr($raw, $pos + 1), true);
    return json_last_error() === JSON_ERROR_NONE ? $data : null;
}

function cache_set(string $prefix, array $parts, $data): void {
    if (!cache_dir_ready()) return;
    $file = cache_file_path($prefix, $parts);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) return;
    // A unique PHP-protected temporary file; readers never see half-written JSON.
    $tmp = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(6)) . '.tmp.php';
    $payload = "<?php exit; ?>\n" . $json;
    if (@file_put_contents($tmp, $payload, LOCK_EX) === strlen($payload)) {
        if (!@rename($tmp, $file)) @unlink($tmp);
        clearstatcache(true, $file);
    } else { @unlink($tmp); }
    cache_maintenance();
}

/** Best-effort, incremental cleanup. Never remove lock files or database data. */
function cache_maintenance(): void {
    static $attempted = false;
    if ($attempted || !cache_dir_ready()) return;
    $attempted = true;
    $stateFile = CACHE_DIR . '/maintenance.state.php';
    $raw = @file_get_contents($stateFile);
    $state = is_string($raw) ? json_decode(substr($raw, (int)strpos($raw, "\n") + 1), true) : null;
    if (is_array($state) && (int)($state['next'] ?? 0) > time()) return;
    $lock = station_lock('cache_maintenance', 0.0);
    if (!$lock) return;
    try {
        $raw = @file_get_contents($stateFile);
        $state = is_string($raw) ? json_decode(substr($raw, (int)strpos($raw, "\n") + 1), true) : null;
        if (is_array($state) && (int)($state['next'] ?? 0) > time()) return;
        $offset = max(0, (int)($state['offset'] ?? 0));
        $it = new DirectoryIterator(CACHE_DIR);
        try { $it->seek($offset); } catch (OutOfBoundsException $e) { $it->rewind(); }
        if (!$it->valid()) $it->rewind();
        $t0 = microtime(true);
        $checked = 0;
        // Keep files longer than any cache TTL. Old revision files become misses
        // immediately; their physical files are reclaimed in small later batches.
        $retention = max(CACHE_TTL_SEO, CACHE_TTL_STATIONS, CACHE_TTL_COUNTS,
            CACHE_TTL_RECENT, CACHE_TTL_FAVORITES, CACHE_TTL_NOWPLAYING,
            CACHE_TTL_NOWPLAYING_EMPTY) + 300;
        while ($it->valid() && $checked < 512 && microtime(true) - $t0 < 0.025) {
            $checked++;
            if (!$it->isDot() && !$it->isLink() && $it->isFile()) {
                $name = $it->getFilename();
                $isCache = str_ends_with($name, '.cache.php');
                $isTemp = (bool)preg_match('/\.cache\.php\.[a-z0-9.]+\.(?:tmp|tmp\.php)$/D', $name);
                if (($isCache || $isTemp) && time() - $it->getMTime() > ($isTemp ? 300 : $retention)) {
                    @unlink($it->getPathname());
                }
            }
            $it->next();
        }
        $nextState = ['next'=>time() + 60, 'offset'=>$it->valid() ? $it->key() : 0];
        @file_put_contents($stateFile, "<?php exit; ?>\n" . json_encode($nextState), LOCK_EX);
    } catch (Throwable $e) {
        // Cleanup is optional; unreadable/disappearing files must not break pages.
        debug_log('Cache cleanup skipped: ' . $e->getMessage());
    } finally { station_unlock($lock); }
}

function cache_delete_prefix(string $prefix): int {
    if (!cache_dir_ready()) return 0;
    $safePrefix = cache_prefix_safe($prefix);
    $files = glob(CACHE_DIR . DIRECTORY_SEPARATOR . $safePrefix . '_*.cache.php');
    if (!is_array($files)) return 0;
    $n = 0;
    foreach ($files as $f) {
        if (@unlink($f)) $n++;
    }
    return $n;
}

function cache_clear_all(): int {
    if (!cache_dir_ready()) return 0;
    $files = glob(CACHE_DIR . DIRECTORY_SEPARATOR . '*.cache.php');
    if (!is_array($files)) return 0;
    $n = 0;
    foreach ($files as $f) {
        if (@unlink($f)) $n++;
    }
    return $n;
}

function cache_invalidate_stations(): void {
    // Catalogue mutations already increment station_catalog_state.revision in
    // their DB transaction. Lists/counts/favourites/sitemap use that revision in
    // their keys, so even a late old request cannot republish a deleted station.
    // Do not glob/unlink every cache file on every scan step, and do not discard
    // the current-track caches of unrelated, still-playing stations.
    cache_maintenance();
}


function cache_stats(): array {
    if (!cache_dir_ready()) return ['enabled'=>CACHE_ENABLED, 'ready'=>false, 'files'=>0, 'bytes'=>0, 'path'=>CACHE_DIR];
    $files = glob(CACHE_DIR . DIRECTORY_SEPARATOR . '*.cache.php');
    if (!is_array($files)) $files = [];
    $bytes = 0;
    foreach ($files as $f) $bytes += (int)@filesize($f);
    return ['enabled'=>CACHE_ENABLED, 'ready'=>true, 'files'=>count($files), 'bytes'=>$bytes, 'path'=>CACHE_DIR];
}

/* =========================
   SEO / GOOGLE INDEXING
   ========================= */

function seo_base_path(): string {
    $path = (string)(parse_url(site_url(), PHP_URL_PATH) ?? '');
    return rtrim($path, '/');
}

function seo_abs_url(string $query = ''): string {
    $query = trim($query);
    if ($query === '') return site_url() . '/';
    if ($query[0] === '?') return site_url() . '/' . $query;
    return site_url() . '/' . ltrim($query, '/');
}

function seo_xml_escape(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function seo_slug(string $text, int $maxLen = 80): string {
    $text = trim($text);
    if ($text === '') return 'radio-station';
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if (is_string($ascii) && $ascii !== '') $text = $ascii;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?: '';
    $text = trim($text, '-');
    if ($text === '') $text = 'radio-station';
    if (strlen($text) > $maxLen) $text = rtrim(substr($text, 0, $maxLen), '-');
    return $text !== '' ? $text : 'radio-station';
}

function station_public_url(array $station): string {
    $id = (int)($station['id'] ?? 0);
    $slug = seo_slug((string)($station['name'] ?? 'radio-station'));
    return seo_abs_url('?page=station&id=' . $id . '&slug=' . rawurlencode($slug));
}

function seo_sitemap_rows(): array {
    $cacheKey = ['v'=>APP_VERSION, 'catalog'=>station_catalog_revision(), 'limit'=>49000];
    $cached = cache_get('seo_sitemap_rows', $cacheKey, CACHE_TTL_SEO);
    if (is_array($cached) && isset($cached['rows']) && is_array($cached['rows'])) return $cached['rows'];

    $rows = [];
    try {
        $rows = db()->query("\n            SELECT id, name, homepage, created_at, last_check_at, total_plays\n            FROM stations\n            WHERE COALESCE(is_dead, 0) = 0\n            ORDER BY total_plays DESC, name ASC, id ASC\n            LIMIT 49000\n        ")->fetchAll();
        cache_set('seo_sitemap_rows', $cacheKey, ['rows'=>$rows]);
    } catch (Exception $e) { /* ignore */ }
    return $rows;
}

function seo_output_sitemap(): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');

    $urls = [];
    $urls[] = ['loc'=>seo_abs_url('?page=home'), 'priority'=>'1.0', 'changefreq'=>'daily', 'lastmod'=>date('c')];
    $urls[] = ['loc'=>seo_abs_url('?page=recent'), 'priority'=>'0.6', 'changefreq'=>'hourly', 'lastmod'=>date('c')];

    foreach (seo_sitemap_rows() as $st) {
        $last = (string)($st['last_check_at'] ?: $st['created_at'] ?: '');
        $ts = $last !== '' ? strtotime($last) : false;
        $urls[] = [
            'loc' => station_public_url($st),
            'priority' => '0.8',
            'changefreq' => 'weekly',
            'lastmod' => $ts ? date('c', $ts) : date('c'),
        ];
    }

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($urls as $u) {
        echo "  <url>\n";
        echo "    <loc>" . seo_xml_escape((string)$u['loc']) . "</loc>\n";
        echo "    <lastmod>" . seo_xml_escape((string)$u['lastmod']) . "</lastmod>\n";
        echo "    <changefreq>" . seo_xml_escape((string)$u['changefreq']) . "</changefreq>\n";
        echo "    <priority>" . seo_xml_escape((string)$u['priority']) . "</priority>\n";
        echo "  </url>\n";
    }
    echo "</urlset>\n";
    exit;
}

function seo_robots_text(): string {
    $base = seo_base_path();
    $cachePath = ($base !== '' ? $base : '') . '/orppx-cache/';
    return "User-agent: *\n"
         . "Allow: /\n"
         . "Disallow: " . $cachePath . "\n"
         . "Disallow: " . ($base !== '' ? $base : '') . "/?ajax=\n"
         . "Sitemap: " . seo_abs_url('?sitemap=xml') . "\n";
}

function seo_output_robots(): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=3600');
    echo seo_robots_text();
    exit;
}

function seo_ensure_static_robots_file(): void {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'robots.txt';
    $text = seo_robots_text();
    $current = is_file($path) ? @file_get_contents($path) : false;
    if ($current !== $text && is_writable(__DIR__)) {
        @file_put_contents($path, $text, LOCK_EX);
    }
}

function seo_meta_context(string $page, string $q, int $p, ?array $station): array {
    $title = APP_NAME . ' - listen to online radio';
    $description = 'Listen to free online radio stations. Search, play and save favorite radio stations, and find the current track on Spotify or YouTube Music.';
    $canonical = seo_abs_url('?page=home');
    $robots = 'index,follow,max-snippet:-1,max-image-preview:large,max-video-preview:-1';
    $type = 'website';

    if ($page === 'station' && $station) {
        $name = truncate_title((string)$station['name'], 90);
        $title = $name . ' live stream | ' . APP_NAME;
        $description = 'Listen live to ' . $name . ' via Online Radio++. Play the stream instantly and search for the current track on Spotify, YouTube Music or Last.fm.';
        $canonical = station_public_url($station);
        $type = 'music.radio_station';
    } elseif ($page === 'recent') {
        $title = 'Recently played radio stations | ' . APP_NAME;
        $description = 'View recently played online radio stations and start the livestream instantly.';
        $canonical = seo_abs_url('?page=recent');
    } elseif ($page === 'home' && $q !== '') {
        $title = 'Radio search: ' . truncate_title($q, 80) . ' | ' . APP_NAME;
        $description = 'Search results for online radio stations on Online Radio++.';
        $canonical = seo_abs_url('?page=home');
        $robots = 'noindex,follow';
    } elseif ($page === 'home') {
        $title = 'Listen to online radio - find free radio stations | ' . APP_NAME;
        $canonical = seo_abs_url('?page=home' . ($p > 1 ? '&p=' . max(1, $p) : ''));
    } else {
        $robots = 'noindex,nofollow';
        $canonical = seo_abs_url('?page=' . rawurlencode($page));
    }

    return ['title'=>$title, 'description'=>$description, 'canonical'=>$canonical, 'robots'=>$robots, 'type'=>$type];
}

function seo_jsonld(string $page, string $q, ?array $station): string {
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => APP_NAME,
        'url' => seo_abs_url(''),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => seo_abs_url('?page=home&q={search_term_string}'),
            'query-input' => 'required name=search_term_string',
        ],
    ];

    if ($page === 'station' && $station) {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'RadioStation',
            'name' => (string)($station['name'] ?? APP_NAME),
            'url' => station_public_url($station),
            'isAccessibleForFree' => true,
        ];
        $homepage = trim((string)($station['homepage'] ?? ''));
        if (preg_match('#^https?://#i', $homepage)) $data['sameAs'] = $homepage;
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($json) ? $json : '{}';
}

/* =========================
   CAPTCHA (simple)
   ========================= */
function captcha_new(string $key): array {
    $a = random_int(1, 9);
    $b = random_int(1, 9);
    $_SESSION['captcha_'.$key] = (string)($a + $b);
    return [$a, $b];
}
function captcha_check(string $key, string $answer): bool {
    $expected = $_SESSION['captcha_'.$key] ?? null;
    if ($expected === null) return false;
    return trim($answer) === trim((string)$expected);
}

/* =========================
   DB + MIGRATIONS
   ========================= */

/* =========================
   ONE-TIME INSTALLATION
   ========================= */

class InstallationException extends RuntimeException {}

/** Kept outside the response cache: clearing the cache must not reopen setup. */
function installation_lock() {
    $path = __DIR__ . '/orppx-install.lock.php';
    $fp = @fopen($path, 'c+b');
    if (!$fp) throw new InstallationException('PHP cannot open the installation lock. Allow PHP to write the application directory, then reload. Do not use world-writable permissions.');
    $deadline = microtime(true) + 10.0;
    do {
        if (@flock($fp, LOCK_EX | LOCK_NB)) {
            if ((int)fstat($fp)['size'] === 0) {
                fwrite($fp, "<?php http_response_code(404); exit; ?>\n");
                fflush($fp);
            }
            return $fp;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    fclose($fp);
    throw new InstallationException('Another request is finishing setup. Please reload in a few seconds.');
}

/** Remove the entire credential seed, not just disable it or hide it in a hash. */
function installation_remove_seed(): void {
    $path = __FILE__;
    $source = @file_get_contents($path);
    if (!is_string($source)) throw new InstallationException('The application source could not be read for installation cleanup.');
    // Construct the delimiters so their complete text occurs only in the removable block.
    $begin = '/* ORPPX_FIRST_RUN_' . 'SEED_BEGIN */';
    $end = '/* ORPPX_FIRST_RUN_' . 'SEED_END */';
    $start = strpos($source, $begin);
    $finish = strpos($source, $end);
    if ($start === false && $finish === false) return; // Already sanitized, including crash recovery.
    if ($start === false || $finish === false || $finish <= $start
        || substr_count($source, $begin) !== 1 || substr_count($source, $end) !== 1) {
        throw new InstallationException('Installation cleanup found an incomplete seed block. Restore the release file without changing the database, then reload.');
    }
    if (!is_writable($path) || !is_writable(dirname($path))) {
        throw new InstallationException('Setup is paused: PHP must temporarily be allowed to replace bootstrap.php and write its directory to remove the one-time login data. Correct the file ownership/permissions and reload. Existing data has not been reset.');
    }
    $clean = substr($source, 0, $start)
        . '/* One-time administrator seed removed after installation. */'
        . substr($source, $finish + strlen($end));
    if (function_exists('token_get_all')) token_get_all($clean, TOKEN_PARSE);
    $tmp = dirname($path) . '/.orppx-source-' . bin2hex(random_bytes(12)) . '.php';
    $fp = @fopen($tmp, 'x+b');
    if (!$fp) throw new InstallationException('Setup cannot create the temporary source file. Check the application directory permissions and available disk space.');
    try {
        @chmod($tmp, 0600);
        $offset = 0;
        while ($offset < strlen($clean)) {
            $written = fwrite($fp, substr($clean, $offset));
            if ($written === false || $written === 0) throw new InstallationException('Installation cleanup could not write the complete source file. Check available disk space.');
            $offset += $written;
        }
        if (!fflush($fp)) throw new InstallationException('Installation cleanup could not flush the source file.');
        if (function_exists('fsync') && !fsync($fp)) throw new InstallationException('Installation cleanup could not synchronize the source file.');
        fclose($fp); $fp = null;
        $mode = @fileperms($path);
        if (!@chmod($tmp, $mode === false ? 0644 : ($mode & 0777))) {
            throw new InstallationException('Installation cleanup could not preserve the source file permissions.');
        }
        // Avoid overwriting a deployment performed while this request was running.
        $current = @file_get_contents($path);
        if (!is_string($current) || !hash_equals(hash('sha256', $source), hash('sha256', $current))) {
            throw new InstallationException('The source file changed during setup. Please reload to use the newly uploaded version.');
        }
        // Same-directory rename: never truncate the live PHP file in place.
        if (!@rename($tmp, $path)) throw new InstallationException('Setup could not replace bootstrap.php. Check file ownership and directory permissions, then reload.');
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
        $verified = @file_get_contents($path);
        if (!is_string($verified) || !hash_equals(hash('sha256', $clean), hash('sha256', $verified))) {
            throw new InstallationException('The cleaned source file could not be verified. Restore the release file and reload; keep the database.');
        }
    } finally {
        if (is_resource($fp)) fclose($fp);
        if (is_file($tmp)) @unlink($tmp);
    }
}

/** The marker contains no credentials and survives deleting/replacing the database. */
function installation_write_marker(): void {
    $path = __DIR__ . '/orppx-installed.php';
    if (is_file($path)) return;
    $fp = @fopen($path, 'xb');
    if (!$fp) throw new InstallationException('Setup cannot write its installation marker. Check the application directory permissions and reload.');
    try {
        $text = "<?php http_response_code(404); exit; ?>\nOnline Radio++ installation marker. Keep this file with the database.\n";
        if (fwrite($fp, $text) !== strlen($text) || !fflush($fp)) {
            throw new InstallationException('Setup could not save the installation marker. Check available disk space.');
        }
        if (function_exists('fsync')) fsync($fp);
    } finally { fclose($fp); }
}

/** Called with the installation lock held, after the schema is migrated. */
function installation_prepare(PDO $pdo, bool $hadUsersTable): void {
    $row = $pdo->query('SELECT * FROM app_installation WHERE id = 1')->fetch();
    if ($row) return; // Never re-create defaults just because a users table becomes empty.
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $seed = function_exists('orppx_first_run_seed') ? orppx_first_run_seed() : null;
    $createdId = null;
    if ($count === 0) {
        if ($hadUsersTable || is_file(__DIR__ . '/orppx-installed.php')) {
            throw new InstallationException('No users were found in an existing installation. Default accounts will not be recreated. Restore a database backup or follow the recovery instructions in README.md.');
        }
        if (!is_array($seed)) {
            throw new InstallationException('This source has already completed setup and cannot initialize an empty database. For an intentional new installation, use a fresh release file in a new directory.');
        }
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash, is_admin, must_change_password, created_at) VALUES (?, NULL, ?, 1, 1, ?)');
        $stmt->execute([(string)$seed['username'], password_hash((string)$seed['password'], PASSWORD_DEFAULT), now_iso()]);
        $createdId = (int)$pdo->lastInsertId();
        $_SESSION['installation_notice'] = 'The initial administrator account has been created. Log in with the initial credentials documented in README.md, then choose your own password.';
    } elseif (is_array($seed)) {
        // Preserve upgraded accounts. Only require replacement of an unchanged old default.
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_admin = 1');
        $stmt->execute([(string)$seed['username']]);
        $old = $stmt->fetch();
        if ($old && user_password_matches($old, (string)$seed['password'])) {
            $pdo->prepare('UPDATE users SET must_change_password = 1 WHERE id = ?')->execute([(int)$old['id']]);
        }
    }
    unset($seed);
    $pdo->prepare('INSERT INTO app_installation (id, initialized_at, initial_user_id, cleanup_complete) VALUES (1, ?, ?, 0)')
        ->execute([now_iso(), $createdId]);
}

/** Detect an uploaded seed even if OPcache is still executing the previous cleaned file. */
function installation_source_has_seed(): bool {
    if (function_exists('orppx_first_run_seed')) return true;
    // The release keeps the seed in the configuration header. Avoid rereading the whole app.
    $prefix = @file_get_contents(__FILE__, false, null, 0, 32768);
    if (!is_string($prefix)) throw new InstallationException('The source file cannot be read to verify installation cleanup.');
    return strpos($prefix, '/* ORPPX_FIRST_RUN_' . 'SEED_BEGIN */') !== false;
}

function installation_finish(PDO $pdo): void {
    $row = $pdo->query('SELECT cleanup_complete FROM app_installation WHERE id = 1')->fetch();
    if (!$row) throw new InstallationException('Installation state is missing. Restore the database before continuing.');
    installation_write_marker();
    // A pristine update may contain the seed again, even on an initialized database.
    if (!(int)$row['cleanup_complete'] || installation_source_has_seed()) {
        installation_remove_seed();
        if (!(int)$row['cleanup_complete']) $pdo->exec('UPDATE app_installation SET cleanup_complete = 1 WHERE id = 1');
    }
}

function installation_error_page(Throwable $error): void {
    error_log('[ORPPX setup] ' . $error->getMessage());
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Retry-After: 10');
    $message = $error instanceof InstallationException ? $error->getMessage()
        : 'The application could not initialize. Check that PDO SQLite is enabled, the database directory is writable, and the server error log has no storage or database errors.';
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup needs attention — Online Radio++</title>'
        . '<body style="font-family:system-ui,sans-serif;max-width:760px;margin:8vh auto;padding:24px;line-height:1.6;background:#10141b;color:#f5f5f5">'
        . '<h1>Setup needs attention</h1><p>' . h($message) . '</p><p>Correct the problem and reload. Do not delete an existing database or its installation marker to bypass this screen.</p>'
        . '<p>See the installation and recovery sections in README.md.</p></body></html>';
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new InstallationException('PDO SQLite is required. Enable the pdo_sqlite PHP extension in your hosting control panel, then reload.');
    }
    $lock = installation_lock();
    $connection = null;
    try {
        $connection = new PDO('sqlite:' . DB_PATH);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $connection->exec('PRAGMA busy_timeout = 5000');
        $connection->exec('PRAGMA journal_mode = WAL');
        $connection->exec('PRAGMA synchronous = NORMAL');
        $connection->exec('PRAGMA temp_store = MEMORY');
        $connection->exec('PRAGMA cache_size = -20000');
        $hadUsersTable = (bool)$connection->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
        $connection->beginTransaction();
        init_schema($connection);
        installation_prepare($connection, $hadUsersTable);
        $connection->commit();
        // Commit the hashed account first, then remove the seed; interrupted cleanup is resumable.
        installation_finish($connection);
        $pdo = $connection;
        return $pdo;
    } catch (Throwable $e) {
        if ($connection instanceof PDO && $connection->inTransaction()) $connection->rollBack();
        throw $e;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function table_columns(PDO $pdo, string $table): array {
    $cols = [];
    try {
        $rows = $pdo->query("PRAGMA table_info(".$table.")")->fetchAll();
        foreach ($rows as $r) {
            $cols[strtolower((string)$r['name'])] = true;
        }
    } catch (Exception $e) { /* ignore */ }
    return $cols;
}

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
    $cols = table_columns($pdo, $table);
    if (isset($cols[strtolower($column)])) return;
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
}

function user_hash_columns(PDO $pdo): array {
    static $cols = null;
    if ($cols !== null) return $cols;

    $info = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $hasPasshash = false;
    $hasPasswordHash = false;
    foreach ($info as $r) {
        $name = strtolower((string)$r['name']);
        if ($name === 'passhash') $hasPasshash = true;
        if ($name === 'password_hash') $hasPasswordHash = true;
    }
    $result = [];
    if ($hasPasshash) $result[] = 'passhash';
    if ($hasPasswordHash) $result[] = 'password_hash';
    if (!$result) $result[] = 'password_hash';
    $cols = $result;
    return $cols;
}

function user_hash_column(PDO $pdo): string {
    static $col = null;
    if ($col !== null) return $col;

    $rows = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $hasPasswordHash = false;
    $hasPasshash = false;
    foreach ($rows as $r) {
        $name = strtolower((string)$r['name']);
        if ($name === 'password_hash') $hasPasswordHash = true;
        if ($name === 'passhash') $hasPasshash = true;
    }
    $col = $hasPasswordHash ? 'password_hash' : ($hasPasshash ? 'passhash' : 'password_hash');
    return $col;
}

/** Ensure import_jobs schema exists even on older DBs */
function ensure_import_jobs_schema(PDO $pdo): void {
    ensure_column($pdo, 'import_jobs', 'file_size',   'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'import_jobs', 'byte_offset', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'import_jobs', 'imported',    'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'import_jobs', 'skipped',     'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'import_jobs', 'status',      "TEXT NOT NULL DEFAULT 'queued'");
    ensure_column($pdo, 'import_jobs', 'error_msg',   'TEXT');
    ensure_column($pdo, 'import_jobs', 'created_at',  'TEXT NOT NULL DEFAULT ""');
    ensure_column($pdo, 'import_jobs', 'updated_at',  'TEXT NOT NULL DEFAULT ""');
}

/** Dead scan job schema */
function ensure_dead_scan_jobs_schema(PDO $pdo): void {
    // We keep it as single active job table (supports resume across refresh)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS dead_scan_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            status TEXT NOT NULL DEFAULT 'paused',      -- running|paused|done|error
            last_station_id INTEGER NOT NULL DEFAULT 0,
            processed INTEGER NOT NULL DEFAULT 0,
            total INTEGER NOT NULL DEFAULT 0,
            dead_count INTEGER NOT NULL DEFAULT 0,
            fail_count INTEGER NOT NULL DEFAULT 0,
            batch_size INTEGER NOT NULL DEFAULT 20,
            error_msg TEXT,
            started_at TEXT NOT NULL DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT ''
        )
    ");

    // Ensure columns if older versions exist
    ensure_column($pdo, 'dead_scan_jobs', 'status', "TEXT NOT NULL DEFAULT 'paused'");
    ensure_column($pdo, 'dead_scan_jobs', 'last_station_id', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'dead_scan_jobs', 'processed', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'dead_scan_jobs', 'total', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'dead_scan_jobs', 'dead_count', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'dead_scan_jobs', 'fail_count', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'dead_scan_jobs', 'batch_size', 'INTEGER NOT NULL DEFAULT 20');
    ensure_column($pdo, 'dead_scan_jobs', 'error_msg', 'TEXT');
    ensure_column($pdo, 'dead_scan_jobs', 'started_at', "TEXT NOT NULL DEFAULT ''");
    ensure_column($pdo, 'dead_scan_jobs', 'updated_at', "TEXT NOT NULL DEFAULT ''");
}

function init_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT,
            password_hash TEXT,
            is_admin INTEGER NOT NULL DEFAULT 0,
            last_seen_at TEXT,
            created_at TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            stream_url TEXT NOT NULL,
            homepage TEXT,
            country TEXT,
            tags TEXT,
            total_plays INTEGER NOT NULL DEFAULT 0,
            total_opens INTEGER NOT NULL DEFAULT 0,
            total_errors INTEGER NOT NULL DEFAULT 0,
            is_dead INTEGER NOT NULL DEFAULT 0,
            last_check_at TEXT,
            created_at TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS plays (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            station_id INTEGER NOT NULL,
            user_id INTEGER,
            source TEXT NOT NULL,
            created_at TEXT NOT NULL,
            ip TEXT,
            user_agent TEXT
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS favorites (
            user_id INTEGER NOT NULL,
            station_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (user_id, station_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            created_at TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS import_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL,
            file_size INTEGER NOT NULL DEFAULT 0,
            byte_offset INTEGER NOT NULL DEFAULT 0,
            imported INTEGER NOT NULL DEFAULT 0,
            skipped INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'queued',
            error_msg TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");
    ensure_import_jobs_schema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mail_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL,
            body TEXT NOT NULL,
            last_sent_user_id INTEGER NOT NULL DEFAULT 0,
            sent INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'running',
            error_msg TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )
    ");

    ensure_dead_scan_jobs_schema($pdo);

    ensure_column($pdo, 'stations', 'total_opens', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'stations', 'total_errors', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'stations', 'is_dead', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'stations', 'last_check_at', 'TEXT');
    ensure_column($pdo, 'users', 'password_hash', 'TEXT');
    ensure_column($pdo, 'users', 'email', 'TEXT');
    ensure_column($pdo, 'users', 'last_seen_at', 'TEXT');
    ensure_column($pdo, 'users', 'favorites_revision', 'INTEGER NOT NULL DEFAULT 0');
    ensure_playback_health_schema($pdo);

    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_stations_stream_url ON stations(stream_url)"); }
    catch (Exception $e) { /* ignore */ }

    ensure_column($pdo, 'users', 'must_change_password', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column($pdo, 'users', 'auth_revision', 'INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_installation (
        id INTEGER PRIMARY KEY CHECK (id = 1), initialized_at TEXT NOT NULL,
        initial_user_id INTEGER, cleanup_complete INTEGER NOT NULL DEFAULT 0
    )');

}

/* =========================
   AUTH
   ========================= */

function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    if ((int)($_SESSION['auth_revision'] ?? 0) !== (int)$user['auth_revision']) {
        unset($_SESSION['user_id'], $_SESSION['auth_revision']);
        return null;
    }

    $cache = $user;
    return $user;
}

function touch_last_seen(): void {
    $u = current_user();
    if (!$u) return;
    try { db()->prepare("UPDATE users SET last_seen_at = ? WHERE id = ?")->execute([now_iso(), (int)$u['id']]); }
    catch (Exception $e) { /* ignore */ }
}

function require_admin(): void {
    $u = current_user();
    if (!$u || (int)$u['is_admin'] !== 1) {
        http_response_code(403);
        echo "Admin only";
        exit;
    }
}

/** Use a modern canonical hash exclusively; never fall back to an old password on a mismatch. */
function user_password_matches(array $user, string $password): bool {
    if ($password === '' || str_contains($password, "\0")) return false;
    foreach (['password_hash', 'passhash', 'password'] as $column) {
        $stored = (string)($user[$column] ?? '');
        if ($stored === '') continue;
        $info = password_get_info($stored);
        if (($info['algoName'] ?? 'unknown') !== 'unknown') return password_verify($password, $stored);
        // Compatibility with pre-hash databases, migrated immediately after a successful login.
        if (hash_equals($stored, $password) || (preg_match('/^[a-f0-9]{32}$/i', $stored) && hash_equals(strtolower($stored), md5($password)))) return true;
    }
    return false;
}

function new_password_error(string $password): string {
    if (str_contains($password, "\0")) return 'The password contains an invalid character.';
    $characters = preg_match_all('/./us', $password);
    if ($characters === false) return 'Use valid UTF-8 characters in your password.';
    if ($characters < 12) return 'Use a password containing at least 12 characters.';
    if (strlen($password) > 72) return 'Use a password of at most 72 UTF-8 bytes.';
    return '';
}

/** Replace ALL supported hash columns and erase legacy plaintext, preventing old-password reuse. */
function user_write_password(PDO $pdo, int $userId, string $password, bool $invalidateSessions = true, bool $mustChange = false): void {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $columns = table_columns($pdo, 'users');
    $sets = []; $values = [];
    foreach (['password_hash', 'passhash'] as $column) {
        if (isset($columns[$column])) { $sets[] = $column . ' = ?'; $values[] = $hash; }
    }
    if (isset($columns['password'])) $sets[] = "password = ''";
    $sets[] = 'must_change_password = ?'; $values[] = $mustChange ? 1 : 0;
    if ($invalidateSessions) $sets[] = 'auth_revision = auth_revision + 1';
    $values[] = $userId;
    $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
}

/* Login: only database-backed accounts; there are no emergency logins. */
function handle_login(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return [];
    if (!csrf_valid()) return ['Your page has expired. Refresh the page and try again.'];
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') return ['Please enter username and password.'];
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user || !user_password_matches($user, $password)) return ['Invalid login details.'];
    $canonical = (string)($user['password_hash'] ?? '');
    if ($canonical === '' || password_needs_rehash($canonical, PASSWORD_DEFAULT)) {
        user_write_password($pdo, (int)$user['id'], $password, false, (int)$user['must_change_password'] === 1);
    }
    $pdo->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')->execute([now_iso(), (int)$user['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['auth_revision'] = (int)$user['auth_revision'];
    unset($_SESSION['csrf_token']);
    $target = (int)$user['must_change_password'] === 1 ? 'account' : 'home';
    header('Location: ' . site_url() . '/?page=' . $target, true, 303);
    exit;
}

/** Run before any HTML or AJAX action, so a required password change cannot be bypassed. */
function enforce_password_change(): void {
    $user = current_user();
    if (!$user || !(int)$user['must_change_password']) return;
    $page = (string)($_GET['page'] ?? 'home');
    if (isset($_GET['ajax'])) {
        json_response(['ok'=>false, 'error'=>'password_change_required', 'message'=>'Change your initial password before using this account.'], 403);
    }
    if (!in_array($page, ['account', 'logout'], true) || isset($_GET['sitemap']) || isset($_GET['robots'])) {
        header('Location: ' . site_url() . '/?page=account', true, 303);
        exit;
    }
}

function handle_account_update(): void {
    $user = current_user();
    if (!$user) { header('Location: ' . site_url() . '/?page=login', true, 303); exit; }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $errors = [];
    $old = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['password'] ?? '');
    $repeat = (string)($_POST['password2'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    if (!csrf_valid()) $errors[] = 'Your page has expired. Refresh the page and try again.';
    elseif (!user_password_matches($user, $old)) $errors[] = 'The current password is incorrect.';
    elseif ($new !== $repeat) $errors[] = 'The new passwords do not match.';
    elseif (($reason = new_password_error($new)) !== '') $errors[] = $reason;
    elseif (user_password_matches($user, $new)) $errors[] = 'Choose a password different from your current password.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address, or leave the field empty.';
    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $stmt->execute([$email, (int)$user['id']]);
            if ($email !== '' && $stmt->fetch()) throw new InvalidArgumentException('That email address is already in use.');
            // Invalidate old sessions and reset tokens along with the old password.
            user_write_password($pdo, (int)$user['id'], $new);
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email !== '' ? $email : null, (int)$user['id']]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int)$user['id']]);
            $stmt = $pdo->prepare('SELECT auth_revision FROM users WHERE id = ?');
            $stmt->execute([(int)$user['id']]);
            $revision = (int)$stmt->fetchColumn();
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['auth_revision'] = $revision;
            unset($_SESSION['csrf_token']);
            $_SESSION['account_success'] = 'Your password has been changed. Other sessions have been signed out. You can now use your account normally.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            debug_log($e->getMessage());
            $errors[] = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Your account could not be updated. Please try again.';
        }
    }
    $_SESSION['account_errors'] = $errors;
    header('Location: ' . site_url() . '/?page=account', true, 303);
    exit;
}

function render_account_form(?array $user): void {
    if (!$user) { echo '<p>Please log in to manage your account.</p>'; return; }
    $errors = $_SESSION['account_errors'] ?? [];
    $success = (string)($_SESSION['account_success'] ?? '');
    unset($_SESSION['account_errors'], $_SESSION['account_success']);
    ?>
    <h2>My account</h2>
    <?php if ((int)$user['must_change_password']): ?>
        <div class="box"><h3>Choose your own password</h3><p>Your account is using an initial password. Change it before continuing. Removing the one-time credentials from the PHP file does not change the password stored for your account.</p></div>
    <?php endif; ?>
    <?php if ($errors): ?><p class="error-msg" role="alert"><?= h(implode(' ', $errors)) ?></p><?php endif; ?>
    <?php if ($success !== ''): ?><p class="ok-msg" role="status"><?= h($success) ?></p><?php endif; ?>
    <form method="post" action="?page=account" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <label>Username<input type="text" value="<?= h((string)$user['username']) ?>" readonly autocomplete="username"></label>
        <label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>New password<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
        <label>Repeat new password<input type="password" name="password2" minlength="12" autocomplete="new-password" required></label>
        <label>Email address (optional, for password recovery)<input type="email" name="email" value="<?= h((string)($user['email'] ?? '')) ?>" autocomplete="email"></label>
        <p class="file-meta">Use at least 12 characters and at most 72 UTF-8 bytes. A long, unique passphrase is recommended.</p>
        <button type="submit">Save new password</button>
    </form>
    <?php
}

/* Registration */
function handle_register(): array {
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valid()) return ['Your page has expired. Refresh the page and try again.'];
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $captcha   = trim($_POST['captcha'] ?? '');

        if ($username === '' || $email === '' || $password === '' || $password2 === '') {
            $errors[] = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif ($password !== $password2) {
            $errors[] = 'Passwords do not match.';
        } elseif (($reason = new_password_error($password)) !== '') {
            $errors[] = $reason;
        } elseif (!captcha_check('register', $captcha)) {
            $errors[] = 'CAPTCHA incorrect. Please try again.';
        } else {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $errors[] = 'Username is already in use.';
            } else {
                $stmt2 = $pdo->prepare("SELECT 1 FROM users WHERE email = ?");
                $stmt2->execute([$email]);
                if ($stmt2->fetch()) {
                    $errors[] = 'Email is already in use.';
                } else {
                    $hashCol = user_hash_column($pdo);
                    $hash    = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO users (username, email, {$hashCol}, is_admin, last_seen_at, created_at)
                                   VALUES (?, ?, ?, 0, ?, ?)")
                        ->execute([$username, $email, $hash, now_iso(), now_iso()]);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$pdo->lastInsertId();
                    $_SESSION['auth_revision'] = 0;
                    unset($_SESSION['csrf_token']);
                    header('Location: ' . site_url() . '/?page=home');
                    exit;
                }
            }
        }
    }
    return $errors;
}

/* =========================
   FORGOT PASSWORD
   ========================= */

function send_mail_basic(string $to, string $subject, string $body): bool {
    if (MAIL_FROM === '' || !filter_var(MAIL_FROM, FILTER_VALIDATE_EMAIL)) return false;
    $headers = [];
    $headers[] = 'From: ' . MAIL_FROM;
    $headers[] = 'Reply-To: ' . MAIL_FROM;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $to = str_replace(["\r","\n"], '', $to);
    $subject = str_replace(["\r","\n"], '', $subject);
    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

function handle_forgot(): array {
    $errors = [];
    $okMsg  = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valid()) return [['Your page has expired. Refresh the page and try again.'], ''];
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $captcha  = trim($_POST['captcha'] ?? '');

        if ($username === '' || $email === '') {
            $errors[] = 'Please fill in username and email.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (!captcha_check('forgot', $captcha)) {
            $errors[] = 'CAPTCHA incorrect. Please try again.';
        } else {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND email = ?");
            $stmt->execute([$username, $email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expires = date('c', time() + 3600);

                $pdo->prepare("
                    INSERT INTO password_resets (user_id, token_hash, expires_at, used_at, created_at)
                    VALUES (?, ?, ?, NULL, ?)
                ")->execute([(int)$user['id'], $tokenHash, $expires, now_iso()]);

                $link = site_url() . '/?page=reset&token=' . urlencode($token);
                $body = "Hello {$user['username']},\n\n"
                      . "You requested a password reset for Online Radio++.\n"
                      . "Open this link to set a new password (valid for 1 hour):\n\n"
                      . $link . "\n\n"
                      . "If you did not request this, ignore this email.\n";
                send_mail_basic($email, 'Online Radio++ - Password reset', $body);
            }

            $okMsg = 'If the account exists, a reset link has been sent.';
        }
    }

    return [$errors, $okMsg];
}

function handle_reset_password(): array {
    $errors = [];
    $okMsg  = '';

    $token = trim($_GET['token'] ?? '');
    if ($token === '') {
        $errors[] = 'Invalid reset token.';
        return [$errors, $okMsg];
    }

    $pdo = db();
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("
        SELECT pr.*, u.username
        FROM password_resets pr
        JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL
        ORDER BY pr.id DESC
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();
    if (!$row) { $errors[] = 'Invalid or already used token.'; return [$errors, $okMsg]; }
    if (strtotime($row['expires_at']) < time()) { $errors[] = 'This token has expired.'; return [$errors, $okMsg]; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_valid()) return [['Your page has expired. Refresh the page and try again.'], ''];
        $pw1 = $_POST['password'] ?? '';
        $pw2 = $_POST['password2'] ?? '';
        if ($pw1 === '' || $pw2 === '') $errors[] = 'Please fill in both password fields.';
        elseif ($pw1 !== $pw2) $errors[] = 'Passwords do not match.';
        elseif (($reason = new_password_error($pw1)) !== '') $errors[] = $reason;
        else {
            $pdo->beginTransaction();
            try {
                $consume = $pdo->prepare('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL');
                $consume->execute([now_iso(), (int)$row['id']]);
                if ($consume->rowCount() !== 1) throw new RuntimeException('The reset token was already used.');
                user_write_password($pdo, (int)$row['user_id'], $pw1);
                $pdo->commit();
                $okMsg = 'Password updated. You can log in now.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'The reset could not be completed. Please request a new reset link.';
            }
        }
    }

    return [$errors, $okMsg];
}

/* =========================
   STATIONS / QUERIES
   ========================= */

function truncate_title(string $t, int $len = 150): string {
    if (mb_strlen($t) <= $len) return $t;
    return mb_substr($t, 0, $len - 1) . '...';
}

function get_station(int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM stations WHERE id = ?");
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    return $s ?: null;
}

function nowplaying_to_utf8(string $s): string {
    if ($s === '' || mb_check_encoding($s, 'UTF-8')) return $s;

    // ICY/Shoutcast metadata is very often Windows-1252 or ISO-8859-1.
    $converted = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
    if (is_string($converted) && $converted !== '') return $converted;

    if (function_exists('iconv')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
        if (is_string($converted) && $converted !== '') return $converted;
    }
    return $s;
}

function nowplaying_clean_text(string $s): string {
    $s = nowplaying_to_utf8($s);
    $s = str_replace(["\0", "\r", "\n", "\t"], ' ', $s);
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?: $s;
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s) ?: $s;
    $s = trim($s, " \t\n\r\0\x0B-–—|/");

    // Some stations send placeholders instead of real artist/title data.
    if ($s === '' || preg_match('/^(unknown|n\\/?a|none|null|advert|advertisement|commercial|no metadata)$/i', $s)) return '';
    if (mb_strlen($s) > 240) $s = mb_substr($s, 0, 240);
    return $s;
}

/**
 * Read one ICY metadata field without breaking on apostrophes inside a title
 * such as: StreamTitle='Guns N' Roses - Paradise City';
 */
function nowplaying_metadata_field(string $metadata, string $field): string {
    if ($metadata === '') return '';
    $metadata = nowplaying_to_utf8($metadata);
    $fieldLen = strlen($field);
    $offset = 0;

    while (($pos = stripos($metadata, $field, $offset)) !== false) {
        $p = $pos + $fieldLen;
        while (isset($metadata[$p]) && ctype_space($metadata[$p])) $p++;
        if (!isset($metadata[$p]) || $metadata[$p] !== '=') { $offset = $p; continue; }
        $p++;
        while (isset($metadata[$p]) && ctype_space($metadata[$p])) $p++;
        if (!isset($metadata[$p])) return '';

        $quote = $metadata[$p];
        if ($quote === "'" || $quote === '"') {
            $start = ++$p;
            $len = strlen($metadata);
            while ($p < $len) {
                $q = strpos($metadata, $quote, $p);
                if ($q === false) break;
                $after = substr($metadata, $q + 1);
                // A real field terminator is quote + semicolon followed by another key or only padding/end.
                if (preg_match('/^\s*;(?:\s*[A-Za-z][A-Za-z0-9_-]*\s*=|\s*\x00*\s*$)/s', $after)) {
                    return nowplaying_clean_text(substr($metadata, $start, $q - $start));
                }
                $p = $q + 1;
            }

            // Tolerant fallback for non-standard servers: use the last quote before field/end padding.
            $tail = substr($metadata, $start);
            if (preg_match('/^(.*)[\'\"]\s*;?/s', $tail, $m)) return nowplaying_clean_text((string)$m[1]);
            return nowplaying_clean_text($tail);
        }

        $end = strpos($metadata, ';', $p);
        if ($end === false) $end = strpos($metadata, "\0", $p);
        if ($end === false) $end = strlen($metadata);
        return nowplaying_clean_text(substr($metadata, $p, $end - $p));
    }
    return '';
}

function nowplaying_extract_title(string $metadata): string {
    if ($metadata === '') return '';
    $metadata = nowplaying_to_utf8($metadata);

    $streamTitle = nowplaying_metadata_field($metadata, 'StreamTitle');
    if ($streamTitle !== '') return $streamTitle;

    // A few servers use artist/title fields instead of StreamTitle.
    $artist = nowplaying_metadata_field($metadata, 'artist');
    $song   = nowplaying_metadata_field($metadata, 'title');
    if ($artist !== '' && $song !== '') return nowplaying_clean_text($artist . ' - ' . $song);
    if ($song !== '') return $song;

    // JSON-ish metadata fallback used by a small number of relay servers.
    if (preg_match('/"artist"\s*:\s*"([^"]*)"/i', $metadata, $am)) {
        $artist = nowplaying_clean_text(stripcslashes((string)$am[1]));
    }
    if (preg_match('/"(?:title|song|songtitle)"\s*:\s*"([^"]*)"/i', $metadata, $tm)) {
        $song = nowplaying_clean_text(stripcslashes((string)$tm[1]));
    }
    if ($artist !== '' && $song !== '') return nowplaying_clean_text($artist . ' - ' . $song);
    return $song;
}

function stream_read_exact($fp, int $bytes, float $deadline): string {
    $data = '';
    $bytes = max(0, $bytes);
    while (strlen($data) < $bytes && !feof($fp) && microtime(true) < $deadline) {
        $chunk = @fread($fp, min(8192, $bytes - strlen($data)));
        if ($chunk === false) break;
        if ($chunk === '') {
            $meta = @stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) break;
            usleep(20000);
            continue;
        }
        $data .= $chunk;
    }
    return $data;
}

function nowplaying_http_get_small(string $url, float $timeoutSeconds = 1.8, int $maxBytes = 262144): string {
    if (!preg_match('#^https?://#i', $url)) return '';
    $timeoutSeconds = max(0.5, min(4.0, $timeoutSeconds));
    $deadline = microtime(true) + $timeoutSeconds;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: OnlineRadioPP-NowPlaying/" . APP_VERSION . "\r\nAccept: application/json,text/plain,*/*\r\nConnection: close\r\n",
            'follow_location' => 1,
            'max_redirects' => 2,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer'=>false, 'verify_peer_name'=>false],
    ]);

    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp === false) return '';
    @stream_set_timeout($fp, (int)ceil($timeoutSeconds));
    $data = '';
    while (!feof($fp) && strlen($data) < $maxBytes && microtime(true) < $deadline) {
        $chunk = @fread($fp, min(8192, $maxBytes - strlen($data)));
        if ($chunk === false) break;
        if ($chunk === '') {
            $meta = @stream_get_meta_data($fp);
            if (!empty($meta['timed_out'])) break;
            usleep(15000);
            continue;
        }
        $data .= $chunk;
    }
    @fclose($fp);
    return $data;
}

function nowplaying_origin(string $streamUrl): string {
    $p = @parse_url($streamUrl);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return '';
    $origin = strtolower((string)$p['scheme']) . '://' . (string)$p['host'];
    if (isset($p['port'])) $origin .= ':' . (int)$p['port'];
    return $origin;
}

function nowplaying_icecast_status(string $streamUrl): array {
    $origin = nowplaying_origin($streamUrl);
    if ($origin === '') return ['ok'=>false, 'title'=>''];

    $raw = nowplaying_http_get_small($origin . '/status-json.xsl', 1.8, 262144);
    if ($raw === '' || $raw[0] === '<') return ['ok'=>false, 'title'=>''];
    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json['icestats']['source'])) return ['ok'=>false, 'title'=>''];

    $sources = $json['icestats']['source'];
    if (!is_array($sources)) return ['ok'=>false, 'title'=>''];
    if (isset($sources['listenurl']) || isset($sources['title']) || isset($sources['server_name'])) $sources = [$sources];

    $targetPath = (string)(parse_url($streamUrl, PHP_URL_PATH) ?? '/');
    if ($targetPath === '') $targetPath = '/';
    $best = null;
    $bestScore = -1;

    foreach ($sources as $src) {
        if (!is_array($src)) continue;
        $listen = (string)($src['listenurl'] ?? $src['listen_url'] ?? '');
        $listenPath = $listen !== '' ? (string)(parse_url($listen, PHP_URL_PATH) ?? '') : '';
        $score = 0;
        if ($listenPath !== '' && rtrim($listenPath, '/') === rtrim($targetPath, '/')) $score = 100;
        elseif ($listenPath !== '' && basename($listenPath) === basename($targetPath)) $score = 60;
        elseif (count($sources) === 1) $score = 20;
        if ($score > $bestScore) { $best = $src; $bestScore = $score; }
    }

    if (!is_array($best)) return ['ok'=>false, 'title'=>''];
    $artist = nowplaying_clean_text((string)($best['artist'] ?? ''));
    $title = nowplaying_clean_text((string)($best['title'] ?? $best['yp_currently_playing'] ?? ''));
    if ($artist !== '' && $title !== '' && stripos($title, $artist) === false) $title = nowplaying_clean_text($artist . ' - ' . $title);
    if ($title === '') return ['ok'=>false, 'title'=>''];
    return ['ok'=>true, 'title'=>$title, 'source'=>'icecast-status'];
}

function nowplaying_shoutcast_status(string $streamUrl): array {
    $origin = nowplaying_origin($streamUrl);
    if ($origin === '') return ['ok'=>false, 'title'=>''];

    foreach ([1, 2] as $sid) {
        $raw = nowplaying_http_get_small($origin . '/stats?sid=' . $sid . '&json=1', 1.5, 131072);
        if ($raw === '' || $raw[0] === '<') continue;
        $json = json_decode($raw, true);
        if (!is_array($json)) continue;
        $title = nowplaying_clean_text((string)($json['songtitle'] ?? $json['song'] ?? $json['currentSong'] ?? ''));
        if ($title !== '') return ['ok'=>true, 'title'=>$title, 'source'=>'shoutcast-status'];
    }
    return ['ok'=>false, 'title'=>''];
}

function nowplaying_fetch_status_fallback(string $streamUrl): array {
    $r = nowplaying_icecast_status($streamUrl);
    if (!empty($r['ok']) && !empty($r['title'])) return $r;
    $r = nowplaying_shoutcast_status($streamUrl);
    if (!empty($r['ok']) && !empty($r['title'])) return $r;
    return ['ok'=>true, 'title'=>'', 'source'=>'none', 'error'=>'No status metadata'];
}

function nowplaying_fetch_from_stream(string $url): array {
    $deadline = microtime(true) + NOWPLAYING_TIMEOUT_SECONDS;
    $headers = "Icy-MetaData: 1\r\n"
             . "User-Agent: OnlineRadioPP-NowPlaying/" . APP_VERSION . "\r\n"
             . "Accept: */*\r\n"
             . "Connection: close\r\n";

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => NOWPLAYING_TIMEOUT_SECONDS,
            'header' => $headers,
            'follow_location' => 1,
            'max_redirects' => 3,
            'ignore_errors' => true,
        ],
        // Radio streams often use imperfect TLS chains; metadata lookup should not break because of that.
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp === false) {
        $fallback = nowplaying_fetch_status_fallback($url);
        if (!empty($fallback['title'])) return $fallback;
        return ['ok'=>false, 'title'=>'', 'source'=>'none', 'error'=>'Stream not reachable'];
    }
    @stream_set_timeout($fp, NOWPLAYING_TIMEOUT_SECONDS);

    $responseHeaders = $http_response_header ?? [];
    $metaInt = 0;
    foreach ($responseHeaders as $line) {
        $line = trim((string)$line);
        if (preg_match('/^icy-metaint\s*:\s*(\d+)/i', $line, $m)) $metaInt = (int)$m[1];
    }

    // Important: icy-name/icy-title response headers normally identify the STATION,
    // not the current song. Never use them as Spotify/YouTube/Last.fm search text.
    if ($metaInt <= 0) {
        // Fallback for rare relay streams that expose StreamTitle in early plain data.
        $sample = stream_read_exact($fp, 65536, $deadline);
        @fclose($fp);
        $title = nowplaying_extract_title($sample);
        if ($title !== '') return ['ok'=>true, 'title'=>$title, 'source'=>'sample'];
        return nowplaying_fetch_status_fallback($url);
    }

    if ($metaInt > NOWPLAYING_MAX_SKIP_BYTES) {
        @fclose($fp);
        return nowplaying_fetch_status_fallback($url);
    }

    // Some streams leave the first metadata block empty. Keep reading until the
    // deadline instead of giving up after only three blocks.
    for ($i = 0; $i < 8 && microtime(true) < $deadline; $i++) {
        $audio = stream_read_exact($fp, $metaInt, $deadline);
        if (strlen($audio) < $metaInt) break;

        $lenByte = stream_read_exact($fp, 1, $deadline);
        if ($lenByte === '') break;
        $metaLen = ord($lenByte) * 16;
        if ($metaLen <= 0) continue;

        // ICY length byte has a protocol maximum of 4080 bytes, but keep a safety cap.
        $readLen = min($metaLen, NOWPLAYING_MAX_META_BYTES);
        $metadata = stream_read_exact($fp, $readLen, $deadline);
        if ($metaLen > $readLen) stream_read_exact($fp, $metaLen - $readLen, $deadline); // keep alignment

        $title = nowplaying_extract_title($metadata);
        if ($title !== '') {
            @fclose($fp);
            return ['ok'=>true, 'title'=>$title, 'source'=>'streamtitle'];
        }
    }

    @fclose($fp);
    $fallback = nowplaying_fetch_status_fallback($url);
    if (!empty($fallback['title'])) return $fallback;
    return ['ok'=>true, 'title'=>'', 'source'=>'none', 'error'=>'No current track title found'];
}

/** A cached response carries its original age, never a new apparent observation time. */
function nowplaying_cached_response(array $cacheKey): ?array {
    $cached = cache_get('now_playing', $cacheKey, max(CACHE_TTL_NOWPLAYING, CACHE_TTL_NOWPLAYING_EMPTY));
    if (!is_array($cached) || !isset($cached['ok'], $cached['checked_unix'])) return null;
    $age = max(0, time() - (int)$cached['checked_unix']);
    $ttl = !empty($cached['title']) ? CACHE_TTL_NOWPLAYING : CACHE_TTL_NOWPLAYING_EMPTY;
    if ($age >= $ttl) return null;
    $cached['age_seconds'] = $age;
    $cached['retry_after'] = max(1, $ttl - $age);
    $cached['cached'] = true;
    return $cached;
}

function nowplaying_for_station(int $stationId): array {
    // Always verify existence/current URL before using metadata. A deleted station
    // must not be brought back by an old cache file.
    $station = get_station($stationId);
    if (!$station) return ['ok'=>false, 'title'=>'', 'error'=>'Station not found', 'retry_after'=>45];
    $url = normalize_stream_url_server((string)($station['stream_url'] ?? ''));
    if ($url === '') return ['ok'=>false, 'title'=>'', 'error'=>'Invalid stream URL', 'retry_after'=>45];
    $cacheKey = ['v'=>APP_VERSION, 'station_id'=>$stationId, 'url'=>$url];
    $cached = nowplaying_cached_response($cacheKey);
    if ($cached !== null) return $cached;

    $lock = null;
    if (cache_dir_ready()) {
        // Fixed-size lock pool: bounded disk use even with a very large catalogue.
        // A hash collision merely postpones one other station's lookup briefly.
        $bucket = hexdec(substr(hash('sha256', $stationId . '|' . $url), 0, 4)) % 128;
        $lock = station_lock('now_playing_refresh_' . $bucket, 0.0);
        if (!$lock) {
            $cached = nowplaying_cached_response($cacheKey);
            return $cached ?? ['ok'=>true, 'title'=>'', 'station_id'=>$stationId,
                'source'=>'none', 'refreshing'=>true, 'retry_after'=>2];
        }
    }
    try {
        // Another worker may have completed between the first read and our lock.
        $cached = nowplaying_cached_response($cacheKey);
        if ($cached !== null) return $cached;
        $result = nowplaying_fetch_from_stream($url);
        $result['title'] = nowplaying_clean_text((string)($result['title'] ?? ''));
        $result['station_id'] = $stationId;
        $result['station_name'] = (string)($station['name'] ?? '');
        $result['checked_at'] = now_iso();
        $result['checked_unix'] = time();
        $result['age_seconds'] = 0;
        $result['retry_after'] = $result['title'] !== '' ? CACHE_TTL_NOWPLAYING : CACHE_TTL_NOWPLAYING_EMPTY;
        $result['cached'] = false;
        cache_set('now_playing', $cacheKey, $result);
        return $result;
    } finally { station_unlock($lock); }
}

/* =========================
   PLAYBACK HEALTH: normal -> hidden -> restored/deleted
   ========================= */

function ensure_playback_health_schema(PDO $pdo): void {
    // Kept separate from the large stations table: no per-station bulk backfill.
    $pdo->exec("CREATE TABLE IF NOT EXISTS station_health (
        station_id INTEGER PRIMARY KEY, revision INTEGER NOT NULL DEFAULT 0,
        first_actor TEXT, first_browser TEXT, first_failed_at TEXT,
        last_failure TEXT, last_success_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS playback_attempts (
        token_hash TEXT PRIMARY KEY, station_id INTEGER NOT NULL,
        actor TEXT NOT NULL, browser_id TEXT NOT NULL, revision INTEGER NOT NULL,
        url_hash TEXT NOT NULL, issued_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
        result TEXT NOT NULL DEFAULT 'pending'
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_playback_station ON playback_attempts(station_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_playback_expiry ON playback_attempts(expires_at)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS station_catalog_state (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('INSERT OR IGNORE INTO station_catalog_state(id, revision) VALUES (1, 0)');
    ensure_column($pdo, 'dead_scan_jobs', 'policy', "TEXT NOT NULL DEFAULT 'legacy'");
    ensure_column($pdo, 'dead_scan_jobs', 'alive_count', 'INTEGER NOT NULL DEFAULT 0');
    // Do not silently turn a previously running conservative scan into a destructive scan.
    $pdo->exec("UPDATE dead_scan_jobs SET status='paused', error_msg='Old scan policy. Start a new strict scan from the beginning.'
        WHERE policy <> 'strict-v1' AND status='running'");
}
function station_catalog_revision(): int {
    return (int)db()->query('SELECT revision FROM station_catalog_state WHERE id=1')->fetchColumn();
}
function station_catalog_changed(PDO $pdo): void {
    $pdo->exec('UPDATE station_catalog_state SET revision=revision+1 WHERE id=1');
}

/** A first-party, HTTP-only random browser cookie, not an IP address or fingerprint. */
function playback_browser_id(): string {
    static $id = null;
    if ($id !== null) return $id;
    $raw = (string)($_COOKIE['orppx_visitor'] ?? ($_SESSION['playback_browser'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/D', $raw)) $raw = bin2hex(random_bytes(32));
    $_SESSION['playback_browser'] = $raw;
    if (!headers_sent() && (string)($_COOKIE['orppx_visitor'] ?? '') !== $raw) {
        setcookie('orppx_visitor', $raw, [
            'expires'=>time() + 180 * 86400, 'path'=>seo_base_path() ?: '/',
            'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly'=>true, 'samesite'=>'Lax',
        ]);
    }
    $id = hash('sha256', 'browser:' . $raw);
    return $id;
}
function playback_identity(): array {
    $browser = playback_browser_id();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    return ['actor'=>$uid > 0 ? hash('sha256', 'user:' . $uid) : $browser, 'browser'=>$browser];
}
function playback_begin_allowed(): bool {
    $now = time();
    $times = array_values(array_filter((array)($_SESSION['playback_begins'] ?? []),
        fn($t) => is_int($t) && $t > $now - 60));
    if (count($times) >= 40) return false;
    $times[] = $now; $_SESSION['playback_begins'] = $times;
    return true;
}
function playback_state(PDO $pdo, int $stationId): array {
    $q = $pdo->prepare('SELECT is_dead FROM stations WHERE id=?');
    $q->execute([$stationId]);
    $state = $q->fetchColumn();
    return ['ok'=>true, 'station_id'=>$stationId, 'missing'=>$state === false,
        'deleted'=>false, 'hidden'=>$state !== false && (int)$state !== 0,
        'restored'=>false];
}
function playback_begin(int $stationId, array $identity): array {
    return station_transaction(function(PDO $pdo) use ($stationId, $identity): array {
        $station = get_station($stationId);
        if (!$station) return ['ok'=>true, 'missing'=>true, 'station_id'=>$stationId];
        $pdo->prepare('INSERT OR IGNORE INTO station_health(station_id) VALUES (?)')->execute([$stationId]);
        $q = $pdo->prepare('SELECT revision FROM station_health WHERE station_id=?');
        $q->execute([$stationId]);
        $revision = (int)$q->fetchColumn();
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO playback_attempts(token_hash, station_id, actor, browser_id, revision, url_hash, issued_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([hash('sha256', $token), $stationId,
                $identity['actor'], $identity['browser'], $revision, hash('sha256', (string)$station['stream_url']), time(), time() + 86400]);
        // Indexed cleanup, no cron required. At most one day of attempts retained.
        $pdo->prepare('DELETE FROM playback_attempts WHERE expires_at < ?')->execute([time()]);
        return ['ok'=>true, 'station_id'=>$stationId, 'attempt'=>$token, 'hidden'=>(int)$station['is_dead'] !== 0];
    });
}

/** Atomic decisions; only an attempt begun AFTER quarantine can be the second test. */
function playback_result(int $stationId, string $token, string $outcome, string $reason, array $identity): array {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !in_array($outcome, ['success','failure'], true)) {
        return ['ok'=>false, 'error'=>'Invalid playback test.'];
    }
    $result = station_transaction(function(PDO $pdo) use ($stationId, $token, $outcome, $reason, $identity): array {
        $state = playback_state($pdo, $stationId);
        if ($state['missing']) return $state + ['message'=>'This radio station has already been removed.'];
        $q = $pdo->prepare('SELECT * FROM playback_attempts WHERE token_hash=? AND station_id=?');
        $q->execute([hash('sha256', $token), $stationId]);
        $attempt = $q->fetch();
        if (!$attempt || (int)$attempt['expires_at'] < time()
            || !hash_equals((string)$attempt['actor'], $identity['actor'])
            || !hash_equals((string)$attempt['browser_id'], $identity['browser'])) {
            return ['ok'=>false, 'error'=>'Playback test expired. Press Play to start a new test.'];
        }
        $station = get_station($stationId);
        $q = $pdo->prepare('SELECT * FROM station_health WHERE station_id=?');
        $q->execute([$stationId]);
        $health = $q->fetch();
        if (!$health || !hash_equals((string)$attempt['url_hash'], hash('sha256', (string)$station['stream_url']))
            || (int)$attempt['revision'] !== (int)$health['revision']) {
            return $state + ['stale'=>true, 'message'=>'The station was tested in the meantime. This older result was ignored. Press Play for a new test.'];
        }
        // A successful attempt may later fail, but duplicate failure events are never a second test.
        if (in_array($attempt['result'], ['failure','stale'], true) || ($outcome === 'success' && $attempt['result'] === 'success')) {
            return $state + ['duplicate'=>true, 'message'=>'This playback result has already been processed.'];
        }
        if ($outcome === 'success') {
            if (time() - (int)$attempt['issued_at'] < PLAYBACK_SUCCESS_SECONDS) {
                return $state + ['pending'=>true, 'message'=>'Waiting for actual playback progress.'];
            }
            $restored = (int)$station['is_dead'] !== 0;
            $nextRevision = (int)$health['revision'] + ($restored ? 1 : 0);
            $pdo->prepare('UPDATE stations SET is_dead=0, last_check_at=? WHERE id=?')->execute([now_iso(), $stationId]);
            $pdo->prepare('UPDATE station_health SET revision=?, first_actor=NULL, first_browser=NULL,
                first_failed_at=NULL, last_failure=NULL, last_success_at=? WHERE station_id=?')
                ->execute([$nextRevision, now_iso(), $stationId]);
            $pdo->prepare("UPDATE playback_attempts SET result='success', revision=? WHERE token_hash=?")
                ->execute([$nextRevision, hash('sha256', $token)]);
            if ($restored) station_catalog_changed($pdo);
            return array_merge($state, ['hidden'=>false, 'restored'=>$restored, 'changed'=>$restored,
                'message'=>$restored ? 'This radio works again. It is visible normally and its failure status has been cleared.' : 'Playback confirmed.']);
        }
        $pdo->prepare("UPDATE playback_attempts SET result='failure' WHERE token_hash=?")->execute([hash('sha256', $token)]);
        $independent = !empty($health['first_actor']) && !empty($health['first_browser'])
            && !hash_equals((string)$health['first_actor'], $identity['actor'])
            && !hash_equals((string)$health['first_browser'], $identity['browser']);
        if ((int)$station['is_dead'] === 2 && $independent) {
            station_delete_rows($pdo, $stationId);
            station_catalog_changed($pdo);
            return array_merge($state, ['deleted'=>true, 'hidden'=>false, 'changed'=>true,
                'message'=>'Sorry, this radio failed for a second visitor. It has been removed.']);
        }
        if ((int)$station['is_dead'] !== 2 || empty($health['first_actor'])) {
            $pdo->prepare('UPDATE station_health SET revision=revision+1, first_actor=?, first_browser=?,
                first_failed_at=?, last_failure=? WHERE station_id=?')
                ->execute([$identity['actor'], $identity['browser'], now_iso(), substr($reason, 0, 240), $stationId]);
            $pdo->prepare('UPDATE stations SET is_dead=2, total_errors=COALESCE(total_errors,0)+1, last_check_at=? WHERE id=?')
                ->execute([now_iso(), $stationId]);
            station_catalog_changed($pdo);
            return array_merge($state, ['hidden'=>true, 'changed'=>true,
                'message'=>'Sorry, this radio does not seem to work. It is hidden and marked as possibly defective, awaiting another visitor\'s test.']);
        }
        return array_merge($state, ['hidden'=>true, 'same_visitor'=>true,
            'message'=>'This radio remains hidden. A repeated failure from the same account or browser does not count as a second visitor.']);
    });
    if (!empty($result['changed'])) cache_invalidate_stations();
    $result['stations_total'] = stations_count();
    return $result;
}
function station_delete_rows(PDO $pdo, int $stationId): void {
    foreach (['favorites','plays','station_health','playback_attempts'] as $table) {
        $pdo->prepare('DELETE FROM ' . $table . ' WHERE station_id=?')->execute([$stationId]);
    }
    $pdo->prepare('DELETE FROM stations WHERE id=?')->execute([$stationId]);
}
function suspect_stations(array $identity, bool $admin, int $page): array {
    $where = 's.is_dead=2'; $params = [];
    if (!$admin) {
        $where .= ' AND (h.first_actor IS NULL OR (h.first_actor <> ? AND h.first_browser <> ?))';
        $params = [$identity['actor'], $identity['browser']];
    }
    $from = ' FROM stations s LEFT JOIN station_health h ON h.station_id=s.id WHERE ' . $where;
    $q = db()->prepare('SELECT COUNT(*)' . $from); $q->execute($params); $total = (int)$q->fetchColumn();
    $q = db()->prepare('SELECT s.*, h.first_failed_at, h.last_failure' . $from . ' ORDER BY h.first_failed_at ASC, s.id ASC LIMIT ? OFFSET ?');
    foreach ($params as $i=>$v) $q->bindValue($i + 1, $v, PDO::PARAM_STR);
    $q->bindValue(count($params) + 1, PAGE_SIZE, PDO::PARAM_INT);
    $q->bindValue(count($params) + 2, (max(1,$page)-1)*PAGE_SIZE, PDO::PARAM_INT);
    $q->execute();
    return [$q->fetchAll(), $total];
}


function stations_count(): int {
    $cacheKey = ['v'=>APP_VERSION, 'catalog'=>station_catalog_revision(), 'page_size'=>PAGE_SIZE];
    $cached = cache_get('stations_count', $cacheKey, CACHE_TTL_COUNTS);
    if (is_array($cached) && isset($cached['count'])) return (int)$cached['count'];

    try {
        $count = (int)db()->query("SELECT COUNT(*) FROM stations WHERE COALESCE(is_dead,0)=0")->fetchColumn();
        cache_set('stations_count', $cacheKey, ['count'=>$count]);
        return $count;
    } catch (Exception $e) { return 0; }
}

function search_stations(string $q, int $page): array {
    $q = trim($q);
    $page = max(1, (int)$page);
    $cacheKey = ['v'=>APP_VERSION, 'catalog'=>station_catalog_revision(), 'q'=>$q, 'page'=>$page, 'page_size'=>PAGE_SIZE];
    $cached = cache_get('stations_search', $cacheKey, CACHE_TTL_STATIONS);
    if (is_array($cached) && isset($cached['rows'], $cached['total']) && is_array($cached['rows'])) {
        return [$cached['rows'], (int)$cached['total']];
    }

    $pdo = db();
    $offset = max(0, ($page - 1) * PAGE_SIZE);

    static $cols = null;
    if ($cols === null) {
        $cols = ['name'=>false,'title'=>false,'tags'=>false,'stream_url'=>false];
        $rows = $pdo->query("PRAGMA table_info(stations)")->fetchAll();
        foreach ($rows as $r) {
            $name = strtolower((string)$r['name']);
            if (isset($cols[$name])) $cols[$name] = true;
        }
    }

    $sqlWhere = "COALESCE(is_dead,0)=0";
    $params   = [];
    if ($q !== '') {
        $likeCols = [];
        if ($cols['name'])       $likeCols[] = "name LIKE :q";
        if ($cols['title'])      $likeCols[] = "title LIKE :q";
        if ($cols['tags'])       $likeCols[] = "tags LIKE :q";
        if ($cols['stream_url']) $likeCols[] = "stream_url LIKE :q";
        if ($likeCols) {
            $sqlWhere .= " AND (" . implode(" OR ", $likeCols) . ")";
            $params[':q'] = '%' . $q . '%';
        } else {
            $sqlWhere .= " AND 1=0";
        }
    }

    $sql = "SELECT * FROM stations WHERE {$sqlWhere}
            ORDER BY total_plays DESC, id ASC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    $stmt->bindValue(':limit', PAGE_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $countKey = ['v'=>APP_VERSION, 'catalog'=>$cacheKey['catalog'], 'q'=>$q];
    $cachedCount = $q !== '' ? cache_get('stations_search_count', $countKey, CACHE_TTL_COUNTS) : null;
    if ($q === '') {
        $total = stations_count();
    } elseif (is_array($cachedCount) && isset($cachedCount['count'])) {
        $total = (int)$cachedCount['count'];
    } else {
        $cntSql = "SELECT COUNT(*) FROM stations WHERE {$sqlWhere}";
        $cnt = $pdo->prepare($cntSql);
        foreach ($params as $k => $v) $cnt->bindValue($k, $v, PDO::PARAM_STR);
        $cnt->execute();
        $total = (int)$cnt->fetchColumn();
        cache_set('stations_search_count', $countKey, ['count'=>$total]);
    }

    cache_set('stations_search', $cacheKey, ['rows'=>$rows, 'total'=>$total]);
    return [$rows, $total];
}

function recent_stations(int $limit = 100): array {
    $limit = max(1, min(500, (int)$limit));
    $latestPlayId = (int)db()->query('SELECT COALESCE(MAX(id),0) FROM plays')->fetchColumn();
    $cacheKey = ['v'=>APP_VERSION, 'catalog'=>station_catalog_revision(), 'latest_play'=>$latestPlayId, 'limit'=>$limit];
    $cached = cache_get('recent_stations', $cacheKey, CACHE_TTL_RECENT);
    if (is_array($cached) && isset($cached['rows']) && is_array($cached['rows'])) return $cached['rows'];

    $pdo = db();
    $sql = "
        SELECT s.*, p.last_play
        FROM stations s
        JOIN (SELECT station_id, MAX(created_at) AS last_play FROM plays GROUP BY station_id) p
            ON s.id = p.station_id
        WHERE COALESCE(s.is_dead,0)=0
        ORDER BY p.last_play DESC
        LIMIT {$limit}
    ";
    $rows = $pdo->query($sql)->fetchAll();
    cache_set('recent_stations', $cacheKey, ['rows'=>$rows]);
    return $rows;
}

function user_favorites_ids(int $userId): array {
    $userId = max(0, (int)$userId);
    if ($userId <= 0) return [];

    $rev = db()->prepare('SELECT favorites_revision FROM users WHERE id=?');
    $rev->execute([$userId]);
    $revision = $rev->fetchColumn();
    if ($revision === false) return [];
    $cacheKey = ['v'=>APP_VERSION, 'catalog'=>station_catalog_revision(),
        'user_id'=>$userId, 'favorites_revision'=>(int)$revision];
    $cached = cache_get('favorites_user', $cacheKey, CACHE_TTL_FAVORITES);
    if (is_array($cached) && isset($cached['ids']) && is_array($cached['ids'])) {
        return array_map('intval', $cached['ids']);
    }

    $stmt = db()->prepare("SELECT station_id FROM favorites WHERE user_id = ?");
    $stmt->execute([$userId]);
    $ids = [];
    foreach ($stmt->fetchAll() as $row) $ids[] = (int)$row['station_id'];
    cache_set('favorites_user', $cacheKey, ['ids'=>$ids]);
    return $ids;
}

/* =========================
   PLAYS / FAVORITES
   ========================= */

function track_play(int $stationId, string $source): void {
    $user = current_user();
    station_transaction(function(PDO $pdo) use ($stationId, $source, $user): void {
        // INSERT ... SELECT cannot create orphan history for a station just removed.
        $stmt = $pdo->prepare('INSERT INTO plays (station_id, user_id, source, created_at, ip, user_agent)
            SELECT id, ?, ?, ?, ?, ? FROM stations WHERE id = ?');
        $stmt->execute([$user ? (int)$user['id'] : null, $source, now_iso(),
            (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), $stationId]);
        if ($source === 'play') $pdo->prepare('UPDATE stations SET total_plays = total_plays + 1 WHERE id = ?')->execute([$stationId]);
        elseif ($source === 'open_url') $pdo->prepare('UPDATE stations SET total_opens = total_opens + 1 WHERE id = ?')->execute([$stationId]);
    });
    // recent_stations() keys its cache by the latest play ID; no directory scan needed.
}

function toggle_favorite(int $stationId, int $userId, ?bool $desired = null): bool {
    $on = station_transaction(function(PDO $pdo) use ($stationId, $userId, $desired): bool {
        $exists = $pdo->prepare('SELECT 1 FROM stations WHERE id = ?');
        $exists->execute([$stationId]);
        if (!$exists->fetchColumn()) throw new OutOfBoundsException('Station not found.');
        $stmt = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND station_id = ?');
        $stmt->execute([$userId, $stationId]);
        $on = $desired ?? !$stmt->fetchColumn();
        if ($on) $pdo->prepare('INSERT OR IGNORE INTO favorites (user_id, station_id, created_at) VALUES (?, ?, ?)')->execute([$userId, $stationId, now_iso()]);
        else $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND station_id = ?')->execute([$userId, $stationId]);
        $pdo->prepare('UPDATE users SET favorites_revision=COALESCE(favorites_revision,0)+1 WHERE id=?')->execute([$userId]);
        return $on;
    });
    cache_maintenance();
    return $on;
}

/* =========================
   URL NORMALISATION (DIRECT)
   ========================= */

function normalize_stream_url_server(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    if (strpos($raw, '//') === 0) {
        $raw = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https:' : 'http:') . $raw;
    } elseif (!preg_match('#^https?://#i', $raw)) $raw = 'https://' . $raw;
    $host = strtolower((string)parse_url($raw, PHP_URL_HOST));
    $ownHost = strtolower((string)parse_url(site_url(), PHP_URL_HOST));
    if ($host === '' || ($ownHost !== '' && $host === $ownHost)) return '';
    return $raw;
}
function build_stream_url_server(string $raw): string { return normalize_stream_url_server($raw); }

/** Delete the station and dependent data as one transaction. Never reuse station IDs. */
function delete_station_completely(int $stationId, ?string $expectedUrl = null, bool $invalidate = true): bool {
    $deleted = station_transaction(function(PDO $pdo) use ($stationId, $expectedUrl): bool {
        $stmt = $pdo->prepare('SELECT stream_url FROM stations WHERE id = ?');
        $stmt->execute([$stationId]);
        $url = $stmt->fetchColumn();
        if ($url === false || ($expectedUrl !== null && $url !== $expectedUrl)) return false;
        station_delete_rows($pdo, $stationId);
        station_catalog_changed($pdo);
        return true;
    });
    if ($deleted && $invalidate) cache_invalidate_stations();
    return $deleted;
}

function admin_delete_station_catalog(bool $all): array {
    $result = station_transaction(function(PDO $pdo) use ($all): array {
        $where = $all ? '' : ' WHERE is_dead = 1';
        $count = (int)$pdo->query('SELECT COUNT(*) FROM stations' . $where)->fetchColumn();
        if ($all) {
            $pdo->exec('DELETE FROM favorites');
            $pdo->exec('DELETE FROM plays');
        } else {
            $pdo->exec('DELETE FROM favorites WHERE station_id IN (SELECT id FROM stations WHERE is_dead = 1)');
            $pdo->exec('DELETE FROM plays WHERE station_id IN (SELECT id FROM stations WHERE is_dead = 1)');
        }
        foreach (['station_health','playback_attempts'] as $table) {
            $pdo->exec('DELETE FROM ' . $table . ($all ? '' : ' WHERE station_id IN (SELECT id FROM stations WHERE is_dead=1)'));
        }
        $pdo->exec('DELETE FROM stations' . $where);
        station_catalog_changed($pdo);
        $cancelled = 0;
        if ($all) {
            $pdo->exec('DELETE FROM dead_scan_jobs');
            $stmt = $pdo->prepare("UPDATE import_jobs SET status='cancelled', error_msg=?, updated_at=? WHERE status IN ('queued','running')");
            $stmt->execute(['Cancelled when all radio stations were removed.', now_iso()]);
            $cancelled = $stmt->rowCount();
        }
        return ['deleted'=>$count, 'cancelled_imports'=>$cancelled];
    });
    cache_invalidate_stations();
    cache_delete_prefix('stream_check');
    return $result;
}

/* =========================
   VERIFIED DEAD-STREAM CHECK
   ========================= */

function stream_probe_result(string $state, string $reason): array {
    return ['state'=>$state, 'reason'=>$reason];
}

function stream_public_ip(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    // Reject mapped/transition IPv6 and multicast as well as PHP's private/reserved ranges.
    if (str_contains($ip, ':')) {
        $bin = @inet_pton($ip);
        if (!$bin || (ord($bin[0]) & 0xe0) !== 0x20) return false; // public unicast 2000::/3 only
        if (str_starts_with(strtolower($ip), '2002:') || substr($bin, 0, 4) === hex2bin('20010000')) return false;
    } else {
        $parts = array_map('intval', explode('.', $ip));
        if ($parts[0] >= 224 || ($parts[0] === 100 && $parts[1] >= 64 && $parts[1] <= 127)) return false;
        if ($parts[0] === 198 && in_array($parts[1], [18, 19], true)) return false;
    }
    return true;
}

/** Resolve once and connect to that IP: redirects cannot reach a private server. */
function stream_probe_target(string $url): array {
    $p = @parse_url($url);
    if (!is_array($p) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http','https'], true)
        || empty($p['host']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return ['error'=>'Invalid stream URL.'];
    }
    $host = strtolower(trim((string)$p['host'], '[]'));
    $port = (int)($p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80));
    if ($port < 1 || $port > 65535) return ['error'=>'Invalid stream port.'];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        if (preg_match('/^[0-9.]+$/', $host) || !preg_match('/^[a-z0-9.-]+$/i', $host)
            || !str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            return ['error'=>'This host cannot be checked automatically.'];
        }
        $ips = @gethostbynamel($host) ?: [];
        if (!$ips && function_exists('dns_get_record')) {
            foreach ((@dns_get_record($host, DNS_AAAA) ?: []) as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
    }
    if (!$ips) return ['error'=>'DNS lookup failed.'];
    foreach ($ips as $ip) if (!stream_public_ip($ip)) return ['error'=>'Private or reserved network addresses are not checked automatically.'];
    return ['host'=>$host, 'port'=>$port, 'scheme'=>strtolower($p['scheme']), 'ip'=>$ips[0],
        'path'=>($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '')];
}

function stream_absolute_redirect(string $base, string $location): string {
    $location = trim($location);
    if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location)) return '';
    if (preg_match('#^https?://#i', $location)) return $location;
    $b = parse_url($base);
    if (!is_array($b) || empty($b['host'])) return '';
    if (str_starts_with($location, '//')) return $b['scheme'] . ':' . $location;
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $location)) return '';
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if ($location[0] === '?') return $origin . ($b['path'] ?? '/') . $location;
    $path = $location[0] === '/' ? $location : rtrim(str_replace('\\', '/', dirname($b['path'] ?? '/')), '/') . '/' . $location;
    $parts = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '..') array_pop($parts);
        elseif ($segment !== '.' && $segment !== '') $parts[] = $segment;
    }
    return $origin . '/' . implode('/', $parts);
}

/** Read at most a small prefix. Works without cURL or allow_url_fopen. No TLS bypass. */
function stream_probe_http(string $url, float $deadline): array {
    for ($redirects = 0; $redirects <= 3; $redirects++) {
        if (microtime(true) >= $deadline) return ['error'=>'Stream check timed out.'];
        $t = stream_probe_target($url);
        if (isset($t['error'])) return $t;
        if (!function_exists('stream_socket_client')) return ['error'=>'Outbound stream checks are not supported by this hosting configuration.'];
        $ipAddress = str_contains($t['ip'], ':') ? '[' . $t['ip'] . ']' : $t['ip'];
        $ctx = stream_context_create(['ssl'=>['verify_peer'=>true, 'verify_peer_name'=>true,
            'peer_name'=>$t['host'], 'SNI_enabled'=>true, 'disable_compression'=>true]]);
        $errno = 0; $errstr = '';
        $socket = @stream_socket_client(($t['scheme'] === 'https' ? 'tls' : 'tcp') . '://' . $ipAddress . ':' . $t['port'],
            $errno, $errstr, max(0.1, $deadline - microtime(true)), STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            // Strict mode treats connection, DNS, TLS and timeout failures as non-working.
            $refused = in_array($errno, [61, 111, 10061], true);
            return ['error'=>$refused ? 'Stream connection refused.' : 'Could not verify the stream (network or TLS error).', 'refused'=>$refused];
        }
        try {
            $remaining = max(0.05, $deadline - microtime(true));
            @stream_set_timeout($socket, (int)$remaining, (int)(($remaining - floor($remaining)) * 1000000));
            $hostHeader = str_contains($t['host'], ':') ? '[' . $t['host'] . ']' : $t['host'];
            if (($t['scheme'] === 'https' && $t['port'] !== 443) || ($t['scheme'] === 'http' && $t['port'] !== 80)) $hostHeader .= ':' . $t['port'];
            $request = 'GET ' . ($t['path'] ?: '/') . " HTTP/1.0\r\nHost: " . $hostHeader
                . "\r\nUser-Agent: Mozilla/5.0 (compatible; OnlineRadioPP-StreamCheck/" . APP_VERSION . ")\r\n"
                . "Accept: */*\r\nAccept-Encoding: identity\r\nIcy-MetaData: 0\r\nConnection: close\r\n\r\n";
            if (@fwrite($socket, $request) !== strlen($request)) return ['error'=>'Could not send stream check.'];
            $head = ''; $body = ''; $status = 0; $headers = []; $completeHeaders = false;
            while (microtime(true) < $deadline && strlen($head) < 32768 && !feof($socket)) {
                $remaining = max(0.01, $deadline - microtime(true));
                @stream_set_timeout($socket, (int)$remaining, (int)(($remaining - floor($remaining)) * 1000000));
                $line = @fgets($socket, 8192);
                if ($line === false) break;
                $head .= $line;
                if (preg_match('#^(?:HTTP/\d(?:\.\d)?|ICY)\s+(\d{3})#i', $line, $m)) {
                    $status = (int)$m[1]; $headers = [];
                } elseif (trim($line) === '') {
                    if ($status >= 100 && $status < 200) continue;
                    $completeHeaders = true;
                    break;
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
            }
            if ($status === 0 || !$completeHeaders) return ['error'=>'No complete HTTP/ICY response.'];
            if (in_array($status, [301,302,303,307,308], true)) {
                $url = stream_absolute_redirect($url, (string)($headers['location'] ?? ''));
                if ($url === '') return ['error'=>'Invalid stream redirect.'];
                continue;
            }
            if ($status >= 200 && $status < 300) {
                $remaining = max(0.05, $deadline - microtime(true));
                @stream_set_timeout($socket, (int)$remaining, (int)(($remaining - floor($remaining)) * 1000000));
                while (strlen($body) < 2048 && microtime(true) < $deadline && !feof($socket)) {
                    $remaining = max(0.01, $deadline - microtime(true));
                    @stream_set_timeout($socket, (int)$remaining, (int)(($remaining - floor($remaining)) * 1000000));
                    $chunk = @fread($socket, 2048 - strlen($body));
                    if ($chunk === false || $chunk === '') break;
                    $body .= $chunk;
                }
            }
            $meta = stream_get_meta_data($socket);
            return ['status'=>$status, 'headers'=>$headers, 'body'=>$body, 'eof'=>feof($socket), 'timed_out'=>!empty($meta['timed_out'])];
        } finally { @fclose($socket); }
    }
    return ['error'=>'Too many stream redirects.'];
}

function stream_classify_probe(array $r): array {
    if (isset($r['error'])) return stream_probe_result('unknown', (string)$r['error']);
    $code = (int)($r['status'] ?? 0);
    if ($code < 200 || $code >= 300) return stream_probe_result('unknown', 'HTTP ' . $code . ': no direct audio response.');
    $body = (string)($r['body'] ?? '');
    $type = strtolower((string)($r['headers']['content-type'] ?? ''));
    $prefix = ltrim($body);
    $prefix = preg_replace('/^[0-9a-f]+(?:;[^\r\n]*)?\r?\n/i', '', $prefix, 1) ?? $prefix;
    // Playlist types can start with "audio/": test BEFORE the generic audio type.
    if (str_starts_with($prefix, '#EXTM3U') || stripos($prefix, '[playlist]') === 0
        || str_contains($type, 'mpegurl') || str_contains($type, 'scpls') || str_contains($type, 'xspf')
        || str_contains($type, 'dash+xml') || str_contains($type, 'application/pls')) {
        return stream_probe_result('unknown', 'Playlist/HLS/DASH instead of a directly verified audio stream.');
    }
    if (strlen($body) < 256) return stream_probe_result('unknown', 'No sufficient audio data within the check time.');
    if (str_contains($type, 'text/') || str_contains($type, 'json') || str_contains($type, 'xml')
        || preg_match('/^<(?:!doctype|html|head|body|\?xml)\b/i', $prefix)
        || preg_match('/captcha|access\s+denied|cf-chl-|checking your browser|just a moment|rate limit/i', $prefix)) {
        return stream_probe_result('unknown', 'Web page, access block or other non-audio response.');
    }
    $signature = str_starts_with($body, 'ID3') || str_starts_with($body, 'OggS') || str_starts_with($body, 'fLaC')
        || (str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WAVE')
        || (strlen($body) >= 2 && ord($body[0]) === 255 && (ord($body[1]) & 0xe0) === 0xe0)
        || substr($body, 4, 4) === 'ftyp';
    $audioType = str_starts_with($type, 'audio/') && preg_match('/[\x00-\x08\x0e-\x1f\x80-\xff]/', $body);
    if ($signature || $audioType) return stream_probe_result('alive', 'Direct audio data received.');
    return stream_probe_result('unknown', 'Response could not be confirmed as direct audio.');
}

/** Strict ADMIN scan: any non-audio result is removed, without a second probe. */
function check_single_stream(int $stationId, bool $useCache = false, bool $invalidate = true): array {
    $station = get_station($stationId);
    if (!$station) return ['ok'=>true, 'deleted'=>false, 'missing'=>true, 'station_id'=>$stationId];
    $rawUrl = (string)$station['stream_url'];
    $lock = station_lock('stream_probe_' . ($stationId % 64));
    if (!$lock) return ['ok'=>true, 'pending'=>true, 'station_id'=>$stationId,
        'error'=>'A stream check is already running, or the cache directory is not writable.'];
    try {
        $url = normalize_stream_url_server($rawUrl);
        $deadline = microtime(true) + STREAM_CHECK_ATTEMPT_SECONDS;
        $probe = $url === '' ? stream_probe_result('unknown', 'Invalid or blocked stream URL.')
            : stream_classify_probe(stream_probe_http($url, $deadline));
        if (microtime(true) > $deadline) $probe = stream_probe_result('unknown', 'No timely audio response within the strict check budget.');
        $alive = $probe['state'] === 'alive';
        // Do not use the old response cache: every explicit scan is a fresh check.
        $applied = station_transaction(function(PDO $pdo) use ($stationId, $rawUrl, $alive): array {
            $current = get_station($stationId);
            if (!$current) return ['missing'=>true];
            if ((string)$current['stream_url'] !== $rawUrl) return ['pending'=>true];
            if (!$alive) {
                station_delete_rows($pdo, $stationId);
                station_catalog_changed($pdo);
                return ['deleted'=>true];
            }
            $restored = (int)$current['is_dead'] !== 0;
            $pdo->prepare('UPDATE stations SET is_dead=0, last_check_at=? WHERE id=?')->execute([now_iso(), $stationId]);
            // Supersede any older browser report, including a first failure, after this fresh successful test.
            $pdo->prepare('INSERT OR IGNORE INTO station_health(station_id) VALUES (?)')->execute([$stationId]);
            $pdo->prepare('UPDATE station_health SET revision=revision+1, first_actor=NULL, first_browser=NULL,
                first_failed_at=NULL, last_failure=NULL, last_success_at=? WHERE station_id=?')->execute([now_iso(), $stationId]);
            if ($restored) station_catalog_changed($pdo);
            return ['alive'=>true, 'restored'=>$restored];
        });
        if ($invalidate && (!empty($applied['deleted']) || !empty($applied['restored']))) cache_invalidate_stations();
        return array_merge(['ok'=>true, 'deleted'=>false, 'alive'=>false, 'dead'=>!$alive, 'station_id'=>$stationId,
            'station'=>['id'=>$stationId,'name'=>(string)$station['name']],
            'error'=>$alive ? null : (string)$probe['reason'], 'checked_at'=>now_iso()], $applied);
    } finally { station_unlock($lock); }
}


/* =========================
   DEAD SCAN JOB (pause/resume)
   ========================= */

function deadscan_get_job(): ?array {
    $r = db()->query('SELECT * FROM dead_scan_jobs ORDER BY id DESC LIMIT 1')->fetch();
    return $r ?: null;
}
function deadscan_create_new(int $batchSize): array {
    $pdo = db();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM stations')->fetchColumn();
    $batchSize = max(1, min(DEADSCAN_MAX_BATCH, $batchSize));
    $pdo->prepare("INSERT INTO dead_scan_jobs
        (status, last_station_id, processed, total, dead_count, fail_count, batch_size, error_msg, started_at, updated_at, policy, alive_count)
        VALUES ('running', 0, 0, ?, 0, 0, ?, NULL, ?, ?, 'strict-v1', 0)")->execute([$total, $batchSize, now_iso(), now_iso()]);
    return deadscan_get_job() ?: [];
}
function deadscan_start_or_resume(int $batchSize): array {
    return station_transaction(function(PDO $pdo) use ($batchSize): array {
        $job = deadscan_get_job();
        if (!$job || ($job['status'] ?? '') === 'done' || ($job['policy'] ?? '') !== 'strict-v1') return deadscan_create_new($batchSize);
        $pdo->prepare("UPDATE dead_scan_jobs SET status='running', batch_size=?, error_msg=NULL, updated_at=? WHERE id=?")
            ->execute([max(1, min(DEADSCAN_MAX_BATCH, $batchSize)), now_iso(), (int)$job['id']]);
        return deadscan_get_job() ?: $job;
    });
}
function deadscan_pause(): array {
    $job = deadscan_get_job();
    if (!$job) return ['ok'=>false, 'error'=>'No scan job exists yet'];
    db()->prepare("UPDATE dead_scan_jobs SET status='paused', updated_at=? WHERE id=? AND status='running'")->execute([now_iso(), (int)$job['id']]);
    $job = deadscan_get_job() ?: $job;
    $job['ok'] = true;
    return $job;
}
function deadscan_reset(): array {
    db()->exec('DELETE FROM dead_scan_jobs');
    return ['ok'=>true];
}

/** No session lock or SQL transaction is held while waiting on external audio. */
function deadscan_step(): array {
    $worker = station_lock('deadscan_worker');
    if (!$worker) return array_merge(deadscan_get_job() ?: [], ['ok'=>true, 'busy'=>true]);
    $changed = false;
    try {
        $pdo = db();
        $job = deadscan_get_job();
        if (!$job) return ['ok'=>true, 'status'=>'paused', 'paused'=>true, 'reset'=>true];
        if (($job['status'] ?? '') !== 'running' || ($job['policy'] ?? '') !== 'strict-v1') {
            return array_merge($job, ['ok'=>true, 'paused'=>true]);
        }
        $t0 = microtime(true);
        $batch = max(1, min(DEADSCAN_MAX_BATCH, (int)$job['batch_size']));
        $stmt = $pdo->prepare('SELECT id FROM stations WHERE id > ? ORDER BY id ASC LIMIT ?');
        $stmt->bindValue(1, (int)$job['last_station_id'], PDO::PARAM_INT);
        $stmt->bindValue(2, $batch, PDO::PARAM_INT); $stmt->execute();
        $rows = $stmt->fetchAll();
        $processedNow = 0; $deadNow = 0; $aliveNow = 0;
        $lastId = (int)$job['last_station_id'];
        $removed = []; $details = []; $restored = []; $systemError = '';
        foreach ($rows as $row) {
            if (microtime(true) - $t0 >= DEADSCAN_STEP_MAX_SECONDS) break;
            $latest = deadscan_get_job();
            if (!$latest || (int)$latest['id'] !== (int)$job['id'] || $latest['status'] !== 'running') break;
            $sid = (int)$row['id'];
            try { $r = check_single_stream($sid, false, false); }
            catch (Throwable $e) {
                debug_log('Strict stream scan: ' . $e->getMessage());
                $systemError = 'Scan stopped by a local database/code error at #' . $sid . '. This station was NOT evaluated; fix the error and resume.';
                break; // Never classify a broken scan implementation as a broken radio station.
            }
            if (!empty($r['pending'])) break;
            $processedNow++; $lastId = $sid;
            if (!empty($r['deleted'])) {
                $changed = true; $deadNow++; $removed[] = $sid;
                $details[] = ['id'=>$sid, 'reason'=>(string)($r['error'] ?? 'No immediate audio response.')];
            } elseif (!empty($r['alive'])) {
                $aliveNow++;
                if (!empty($r['restored'])) { $changed = true; $restored[] = $sid; }
            }
        }
        $latest = deadscan_get_job();
        if (!$latest || (int)$latest['id'] !== (int)$job['id']) {
            return ['ok'=>true, 'status'=>'paused', 'paused'=>true, 'reset'=>true, 'removed_ids'=>$removed];
        }
        $next = $pdo->prepare('SELECT 1 FROM stations WHERE id > ? LIMIT 1'); $next->execute([$lastId]);
        $done = !$next->fetchColumn();
        $pdo->prepare("UPDATE dead_scan_jobs SET last_station_id=?, processed=processed+?, dead_count=dead_count+?,
            alive_count=alive_count+?, error_msg=?, updated_at=?, status=CASE WHEN ? <> '' THEN 'error'
            WHEN status='running' AND ?=1 THEN 'done' ELSE status END WHERE id=?")
            ->execute([$lastId, $processedNow, $deadNow, $aliveNow, $systemError ?: null, now_iso(), $systemError, $done ? 1 : 0, (int)$job['id']]);
        $out = deadscan_get_job() ?: $latest;
        return array_merge($out, ['ok'=>true, 'done'=>$out['status'] === 'done', 'paused'=>$out['status'] === 'paused',
            'removed_ids'=>$removed, 'removed_details'=>$details, 'restored_ids'=>$restored,
            'stations_total'=>(int)$pdo->query('SELECT COUNT(*) FROM stations WHERE COALESCE(is_dead,0)=0')->fetchColumn(),
            'step'=>['processed_now'=>$processedNow, 'dead_now'=>$deadNow, 'alive_now'=>$aliveNow,
                'seconds'=>round(microtime(true) - $t0, 3), 'last_station_id'=>$lastId]]);
    } finally {
        if ($changed) cache_invalidate_stations();
        station_unlock($worker);
    }
}


/* =========================
   IMPORT FILE DISCOVERY (ROOT)
   ========================= */

function list_root_import_files(bool $includeTxt = true): array {
    $dir = __DIR__;
    $files = @scandir($dir);
    if (!is_array($files)) return [];

    $out = [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($path)) continue;

        $lower = strtolower($f);
        $ok = (str_ends_with($lower, '.m3u') || str_ends_with($lower, '.m3u8'));
        if ($includeTxt && str_ends_with($lower, '.txt')) $ok = true;
        if (!$ok) continue;
        if (!is_readable($path)) continue;

        $out[] = ['name'=>$f,'size'=>(int)@filesize($path),'mtime'=>(int)@filemtime($path)];
    }

    usort($out, fn($a,$b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/* =========================
   M3U/TXT CHUNK PARSER (resumable)
   ========================= */

function import_step_file(string $filePath, int $startOffset, int $maxUrls, int $maxSeconds): array {
    $pdo = db();
    $t0  = microtime(true);

    $handle = @fopen($filePath, 'rb');
    if (!$handle) return ['ok'=>false,'error'=>'Cannot open file'];

    $size = (int)@filesize($filePath);
    $offset = max(0, $startOffset);

    if ($offset > 0) {
        @fseek($handle, $offset);
        @fgets($handle); // drop partial line
        $offset = (int)@ftell($handle);
    } else {
        @fseek($handle, 0);
    }

    $imported = 0;
    $skipped  = 0;
    $urlsProcessed = 0;
    $currentName = null;

    $insertStmt = $pdo->prepare("INSERT OR IGNORE INTO stations (name, stream_url, created_at) VALUES (?, ?, ?)");
    $now = now_iso();

    $startedTx = false;
    try {
        if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $startedTx = true; }

        while (!feof($handle)) {
            if ((microtime(true) - $t0) >= max(1, $maxSeconds)) break;
            if ($urlsProcessed >= max(1, $maxUrls)) break;

            $line = @fgets($handle);
            if ($line === false) break;
            $line = trim($line);
            if ($line === '' || strcasecmp($line, '#EXTM3U') === 0) continue;

            if (stripos($line, '#EXTINF:') === 0) {
                $parts = explode(',', $line, 2);
                $name  = isset($parts[1]) ? trim($parts[1]) : 'Unknown';
                if ($name === '') $name = 'Unknown';
                if (mb_strlen($name) > 150) $name = mb_substr($name, 0, 150);
                $currentName = $name;
                continue;
            }

            if ($line[0] === '#') continue;

            $urlRaw = $line;
            if ($urlRaw === '') {
                $skipped++; $currentName = null; continue;
            }

            $norm = normalize_stream_url_server($urlRaw);
            if ($norm === '') { $skipped++; $currentName = null; continue; }

            $nameToUse = $currentName ?: $norm;

            $insertStmt->execute([$nameToUse, $norm, $now]);
            $ch = (int)$pdo->query("SELECT changes()")->fetchColumn();
            if ($ch === 1) $imported++; else $skipped++;

            $urlsProcessed++;
            $currentName = null;
        }

        $newOffset = (int)@ftell($handle);

        if ($imported > 0) station_catalog_changed($pdo);
        if ($startedTx && $pdo->inTransaction()) $pdo->commit();
        if ($imported > 0) cache_invalidate_stations();
        fclose($handle);

        return [
            'ok'=>true,
            'imported'=>$imported,
            'skipped'=>$skipped,
            'urls_processed'=>$urlsProcessed,
            'offset'=>$newOffset,
            'size'=>$size,
            'done'=>($newOffset >= $size),
        ];
    } catch (Exception $e) {
        if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
        fclose($handle);
        return ['ok'=>false,'error'=>'Import error: '.$e->getMessage()];
    }
}

/* =========================
   IMPORT JOB HELPERS
   ========================= */

function import_job_create(string $filename): int {
    $pdo = db();
    ensure_import_jobs_schema($pdo);

    $path = __DIR__ . DIRECTORY_SEPARATOR . $filename;
    $size = is_file($path) ? (int)@filesize($path) : 0;

    $cols = table_columns($pdo, 'import_jobs');

    $fields = [];
    $values = [];

    $fields[] = 'filename';   $values[] = $filename;

    if (isset($cols['file_size']))   { $fields[] = 'file_size';   $values[] = $size; }
    if (isset($cols['byte_offset'])) { $fields[] = 'byte_offset'; $values[] = 0; }
    if (isset($cols['imported']))    { $fields[] = 'imported';    $values[] = 0; }
    if (isset($cols['skipped']))     { $fields[] = 'skipped';     $values[] = 0; }
    if (isset($cols['status']))      { $fields[] = 'status';      $values[] = 'queued'; }
    if (isset($cols['error_msg']))   { $fields[] = 'error_msg';   $values[] = null; }

    $now = now_iso();
    if (isset($cols['created_at']))  { $fields[] = 'created_at';  $values[] = $now; }
    if (isset($cols['updated_at']))  { $fields[] = 'updated_at';  $values[] = $now; }

    $ph = implode(',', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO import_jobs (" . implode(',', $fields) . ") VALUES ({$ph})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return (int)$pdo->lastInsertId();
}

function import_job_list_active(): array {
    $pdo = db();
    ensure_import_jobs_schema($pdo);
    return $pdo->query("SELECT * FROM import_jobs WHERE status IN ('queued','running') ORDER BY id ASC")->fetchAll();
}

function import_job_get(int $jobId): ?array {
    $pdo = db();
    ensure_import_jobs_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM import_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function import_job_update(int $jobId, array $fields): void {
    $pdo = db();
    ensure_import_jobs_schema($pdo);

    $sets = [];
    $params = [];
    foreach ($fields as $k => $v) {
        $sets[] = $k . ' = ?';
        $params[] = $v;
    }

    $cols = table_columns($pdo, 'import_jobs');
    if (isset($cols['updated_at'])) {
        $sets[] = "updated_at = ?";
        $params[] = now_iso();
    }

    $params[] = $jobId;
    $sql = "UPDATE import_jobs SET " . implode(', ', $sets) . " WHERE id = ?";
    $pdo->prepare($sql)->execute($params);
}

/* =========================
   ADMIN USERS + BULK MAIL HELPERS
   ========================= */

function admin_list_users(): array {
    return db()->query("SELECT id, username, email, is_admin, last_seen_at, created_at FROM users ORDER BY id ASC")->fetchAll();
}
function admin_set_user_password(int $userId, string $newPass): void {
    $reason = new_password_error($newPass);
    if ($reason !== '') throw new InvalidArgumentException($reason);
    user_write_password(db(), $userId, $newPass);
}

function admin_set_user_admin(int $userId, int $isAdmin): void {
    db()->prepare("UPDATE users SET is_admin = ? WHERE id = ?")->execute([$isAdmin ? 1 : 0, $userId]);
}
function admin_delete_users(array $ids, int $currentUserId): int {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0 && $x !== $currentUserId));
    if (!$ids) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("DELETE FROM users WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    return $stmt->rowCount();
}
function admin_delete_inactive_since(string $dateYmd, int $currentUserId): int {
    $dateYmd = trim($dateYmd);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) return 0;
    $cut = $dateYmd . 'T00:00:00+00:00';
    $stmt = db()->prepare("DELETE FROM users WHERE id <> ? AND (last_seen_at IS NULL OR last_seen_at < ?)");
    $stmt->execute([$currentUserId, $cut]);
    return $stmt->rowCount();
}



function admin_get_user(int $userId): ?array {
    $stmt = db()->prepare("SELECT id, username, email, is_admin, last_seen_at, created_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function admin_generate_password(int $length = 16): string {
    $length = max(12, min(64, $length));
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%*-_+=';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function admin_search_users(string $query = '', int $limit = 250): array {
    $pdo = db();
    $query = trim($query);
    $limit = max(10, min(500, $limit));

    if ($query === '') {
        $stmt = $pdo->prepare("SELECT id, username, email, is_admin, last_seen_at, created_at FROM users ORDER BY id ASC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    $like = '%' . $query . '%';
    if (ctype_digit($query)) {
        $stmt = $pdo->prepare("SELECT id, username, email, is_admin, last_seen_at, created_at
                               FROM users
                               WHERE id = :id OR username LIKE :q OR email LIKE :q
                               ORDER BY id ASC
                               LIMIT :limit");
        $stmt->bindValue(':id', (int)$query, PDO::PARAM_INT);
    } else {
        $stmt = $pdo->prepare("SELECT id, username, email, is_admin, last_seen_at, created_at
                               FROM users
                               WHERE username LIKE :q OR email LIKE :q
                               ORDER BY id ASC
                               LIMIT :limit");
    }
    $stmt->bindValue(':q', $like, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function admin_create_user_account(string $username, string $email, string $password, int $isAdmin): array {
    $pdo = db();
    $username = trim($username);
    $email = trim($email);
    $password = (string)$password;

    if ($username === '' || mb_strlen($username) < 2) {
        return ['ok'=>false, 'error'=>'Username is required and must contain at least 2 characters.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok'=>false, 'error'=>'Please enter a valid email address.'];
    }

    $generated = false;
    if ($password === '') {
        $password = admin_generate_password(16);
        $generated = true;
    }

    $reason = new_password_error($password);
    if ($reason !== '') return ['ok'=>false, 'error'=>$reason];
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        return ['ok'=>false, 'error'=>'A user with this username or email address already exists.'];
    }

    $hashCol = user_hash_column($pdo);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, email, {$hashCol}, is_admin, last_seen_at, created_at)
                   VALUES (?, ?, ?, ?, NULL, ?)")
        ->execute([$username, $email, $hash, $isAdmin ? 1 : 0, now_iso()]);

    return ['ok'=>true, 'user_id'=>(int)$pdo->lastInsertId(), 'password'=>$password, 'generated'=>$generated];
}

function admin_delete_user_full(int $userId, int $currentUserId): array {
    if ($userId <= 0) return ['ok'=>false, 'error'=>'Invalid user.'];
    if ($userId === $currentUserId) return ['ok'=>false, 'error'=>'You cannot delete your own account while you are logged in.'];

    $pdo = db();
    $user = admin_get_user($userId);
    if (!$user) return ['ok'=>false, 'error'=>'User not found.'];

    try {
        if (!$pdo->inTransaction()) $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM favorites WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE plays SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        if ($pdo->inTransaction()) $pdo->commit();
        cache_maintenance();
        return ['ok'=>true, 'username'=>(string)$user['username']];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok'=>false, 'error'=>'Delete failed: ' . $e->getMessage()];
    }
}

function admin_send_password_email(array $user, string $plainPassword, bool $newAccount = false): bool {
    $to = trim((string)($user['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $subject = APP_NAME . ($newAccount ? ' - new account' : ' - new password');
    $body = "Hello " . ((string)($user['username'] ?? 'user')) . ",\n\n"
          . ($newAccount
                ? "An administrator has created an account for you on " . APP_NAME . ".\n\n"
                : "An administrator has changed your password for " . APP_NAME . ".\n\n")
          . "Website: " . site_url() . "/?page=login\n"
          . "Username: " . ((string)($user['username'] ?? '')) . "\n"
          . "New password: " . $plainPassword . "\n\n"
          . "Please log in and change your password if needed.\n";

    return send_mail_basic($to, $subject, $body);
}

function mail_job_create(string $subject, string $body): int {
    $pdo = db();
    $pdo->prepare("
        INSERT INTO mail_jobs (subject, body, last_sent_user_id, sent, status, error_msg, created_at, updated_at)
        VALUES (?, ?, 0, 0, 'running', NULL, ?, ?)
    ")->execute([$subject, $body, now_iso(), now_iso()]);
    return (int)$pdo->lastInsertId();
}
function mail_job_get(int $jobId): ?array {
    $stmt = db()->prepare("SELECT * FROM mail_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $r = $stmt->fetch();
    return $r ?: null;
}
function mail_job_update(int $jobId, array $fields): void {
    $pdo = db();
    $sets = [];
    $params = [];
    foreach ($fields as $k => $v) { $sets[] = $k . ' = ?'; $params[] = $v; }
    $sets[] = "updated_at = ?";
    $params[] = now_iso();
    $params[] = $jobId;
    $pdo->prepare("UPDATE mail_jobs SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
}
function mail_job_step(int $jobId, int $batchSize = 50): array {
    $job = mail_job_get($jobId);
    if (!$job) return ['ok'=>false,'error'=>'Job not found'];
    if ($job['status'] !== 'running') return ['ok'=>true,'done'=>true,'sent'=>(int)$job['sent'],'status'=>$job['status']];

    $pdo = db();
    $lastId = (int)$job['last_sent_user_id'];
    $batchSize = max(1, min(200, $batchSize));

    $stmt = $pdo->prepare("SELECT id, email, username FROM users WHERE email IS NOT NULL AND email <> '' AND id > ? ORDER BY id ASC LIMIT ?");
    $stmt->bindValue(1, $lastId, PDO::PARAM_INT);
    $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (!$rows) {
        mail_job_update($jobId, ['status'=>'done']);
        return ['ok'=>true,'done'=>true,'sent'=>(int)$job['sent'],'status'=>'done'];
    }

    $sentNow = 0;
    $lastProcessed = $lastId;

    foreach ($rows as $r) {
        $lastProcessed = (int)$r['id'];
        $to = (string)$r['email'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        $body = "Hello " . ($r['username'] ?? 'user') . ",\n\n" . (string)$job['body'] . "\n";
        if (send_mail_basic($to, (string)$job['subject'], $body)) $sentNow++;
        usleep(25000);
    }

    mail_job_update($jobId, [
        'last_sent_user_id' => $lastProcessed,
        'sent' => ((int)$job['sent'] + $sentNow),
    ]);

    return ['ok'=>true,'done'=>false,'sent_now'=>$sentNow,'sent_total'=>((int)$job['sent'] + $sentNow),'last_user_id'=>$lastProcessed];
}

/* Every entry point completes one-time setup before doing any application work. */
try { db(); } catch (Throwable $e) { installation_error_page($e); }
enforce_password_change();
if (isset($_SESSION['installation_notice']) && !isset($_GET['page']) && !isset($_GET['ajax']) && !isset($_GET['sitemap']) && !isset($_GET['robots'])) {
    header('Location: ' . site_url() . '/?page=login', true, 303);
    exit;
}

/* Public SEO endpoints */
if (isset($_GET['sitemap']) && (string)$_GET['sitemap'] === 'xml') {
    seo_output_sitemap();
}
if (isset($_GET['robots'])) {
    seo_output_robots();
}

/* =========================
   AJAX HANDLERS
   ========================= */

if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'] ?? '';

    try {
        if ($ajax === 'track_play' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $stationId = (int)($_POST['station_id'] ?? 0);
            $source    = $_POST['source'] ?? 'play';
            if ($stationId > 0 && in_array($source, ['play', 'open_url'], true)) {
                track_play($stationId, $source);
                json_response(['ok' => true]);
            } else json_response(['ok'=>false,'error'=>'Invalid parameters'], 400);
        }

        if ($ajax === 'toggle_fav') {
            require_ajax_post_csrf();
            $user = current_user();
            if (!$user) json_response(['ok'=>false,'error'=>'login_required'], 403);
            $stationId = (int)($_POST['station_id'] ?? 0);
            if ($stationId <= 0) json_response(['ok'=>false,'error'=>'Invalid station'], 400);
            $desired = isset($_POST['favorite']) ? (string)$_POST['favorite'] === '1' : null;
            try { $on = toggle_favorite($stationId, (int)$user['id'], $desired); }
            catch (OutOfBoundsException $e) { json_response(['ok'=>false, 'error'=>'Station not found.'], 404); }
            json_response(['ok'=>true, 'favorite'=>$on, 'station_id'=>$stationId]);
        }

        if ($ajax === 'player_favorite') {
            $stationId = (int)($_GET['station_id'] ?? 0);
            $station = $stationId > 0 ? get_station($stationId) : null;
            $user = current_user();
            $favorite = false;
            if ($user && $station) {
                $s = db()->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND station_id = ?');
                $s->execute([(int)$user['id'], $stationId]);
                $favorite = (bool)$s->fetchColumn();
            }
            json_response(['ok'=>true, 'station_id'=>$stationId, 'exists'=>(bool)$station,
                'logged_in'=>(bool)$user, 'favorite'=>$favorite, 'hidden'=>$station && (int)$station['is_dead'] !== 0]);
        }

        if ($ajax === 'playback_begin') {
            require_ajax_post_csrf();
            $stationId = (int)($_POST['station_id'] ?? 0);
            if ($stationId <= 0) json_response(['ok'=>false,'error'=>'Invalid station.'], 400);
            current_user(); // Validate an existing login before deriving the actor key.
            $identity = playback_identity();
            if (!playback_begin_allowed()) json_response(['ok'=>false, 'error'=>'Too many playback attempts. Wait one minute and try again.'], 429);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            json_response(playback_begin($stationId, $identity));
        }
        if ($ajax === 'report_stream_error' || $ajax === 'report_stream_success') {
            require_ajax_post_csrf();
            $stationId = (int)($_POST['station_id'] ?? 0);
            if ($stationId <= 0) json_response(['ok'=>false, 'error'=>'Invalid station.'], 400);
            current_user();
            $identity = playback_identity();
            $token = (string)($_POST['attempt'] ?? '');
            $reason = (string)($_POST['reason'] ?? 'browser-playback-failed');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $result = playback_result($stationId, $token, $ajax === 'report_stream_success' ? 'success' : 'failure', $reason, $identity);
            json_response($result, !empty($result['ok']) ? 200 : 409);
        }


        if ($ajax === 'now_playing') {
            $stationId = (int)($_GET['station_id'] ?? 0);
            if ($stationId <= 0) json_response(['ok'=>false,'title'=>'','error'=>'Invalid station'], 400);

            // This lookup can wait on an external radio server. Do not keep the PHP
            // session locked while that happens, otherwise favorites/navigation and
            // other AJAX requests from the same browser can appear to freeze.
            if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
            ignore_user_abort(false);
            @set_time_limit(NOWPLAYING_TIMEOUT_SECONDS + 10);
            json_response(nowplaying_for_station($stationId));
        }

        if ($ajax === 'cache_status') {
            require_admin();
            json_response(['ok'=>true,'cache'=>cache_stats()]);
        }

        if ($ajax === 'cache_clear') {
            require_admin();
            $deleted = cache_clear_all();
            json_response(['ok'=>true,'deleted'=>$deleted,'cache'=>cache_stats()]);
        }

        /* Dead scan job: status/start/pause/step/reset */
        if ($ajax === 'deadscan_status') {
            require_admin();
            $job = deadscan_get_job();
            json_response(['ok'=>true,'job'=>$job]);
        }

        if ($ajax === 'deadscan_start') {
            require_ajax_post_csrf();
            require_admin();
            $batch = (int)($_POST['batch'] ?? DEADSCAN_DEFAULT_BATCH);
            $batch = max(1, min(DEADSCAN_MAX_BATCH, $batch));
            if ((string)($_POST['strict_confirm'] ?? '') !== '1') {
                json_response(['ok'=>false, 'error'=>'Confirm strict deletion before starting this scan.'], 400);
            }
            $job = deadscan_start_or_resume($batch);
            json_response(['ok'=>true,'job'=>$job]);
        }

        if ($ajax === 'deadscan_pause') {
            require_ajax_post_csrf();
            require_admin();
            $job = deadscan_pause();
            json_response(['ok'=>true,'job'=>$job]);
        }

        if ($ajax === 'deadscan_step') {
            require_ajax_post_csrf();
            require_admin();
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            @set_time_limit(30);
            json_response(deadscan_step());
        }

        if ($ajax === 'deadscan_reset') {
            require_ajax_post_csrf();
            require_admin();
            json_response(deadscan_reset());
        }

        if ($ajax === 'check_stream') {
            require_ajax_post_csrf();
            require_admin();
            $stationId = (int)($_POST['station_id'] ?? 0);
            if ($stationId <= 0) json_response(['ok'=>false,'error'=>'Invalid id'], 400);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            if ((string)($_POST['strict_confirm'] ?? '') !== '1') json_response(['ok'=>false,'error'=>'Strict deletion confirmation required.'], 400);
            json_response(check_single_stream($stationId));
        }

        if ($ajax === 'import_create_jobs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            require_admin();
            $names = $_POST['files'] ?? [];
            if (!is_array($names)) $names = [];

            $allowed = array_map(fn($x) => $x['name'], list_root_import_files(true));
            $allowedSet = array_fill_keys($allowed, true);

            $created = [];
            foreach ($names as $n) {
                $n = (string)$n;
                if (!isset($allowedSet[$n])) continue;
                $created[] = import_job_create($n);
            }
            json_response(['ok'=>true,'created'=>$created]);
        }

        if ($ajax === 'import_active_jobs') {
            require_admin();
            $jobs = import_job_list_active();
            json_response(['ok'=>true,'jobs'=>$jobs]);
        }

        if ($ajax === 'import_step') {
            require_admin();
            // Held until this request exits, including progress updates.
            $importCatalogLock = station_catalog_lock();
            $jobId = (int)($_GET['job_id'] ?? 0);
            if ($jobId <= 0) json_response(['ok'=>false,'error'=>'Invalid job id'], 400);

            $job = import_job_get($jobId);
            if (!$job) json_response(['ok'=>false,'error'=>'Job not found'], 404);

            if (($job['status'] ?? '') === 'cancelled') {
                json_response(['ok'=>true, 'done'=>true, 'cancelled'=>true, 'job'=>$job, 'step'=>['imported'=>0, 'skipped'=>0]]);
            }
            $filename = (string)$job['filename'];
            $path = __DIR__ . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path) || !is_readable($path)) {
                import_job_update($jobId, ['status'=>'error','error_msg'=>'File missing/unreadable']);
                json_response(['ok'=>false,'error'=>'File missing/unreadable']);
            }

            if (($job['status'] ?? '') === 'done') {
                json_response(['ok'=>true,'done'=>true,'job'=>$job]);
            }

            import_job_update($jobId, ['status'=>'running','error_msg'=>null]);

            $step = import_step_file($path, (int)($job['byte_offset'] ?? 0), IMPORT_MAX_URLS_PER_STEP, IMPORT_MAX_SECONDS_PER_STEP);
            if (!$step['ok']) {
                import_job_update($jobId, ['status'=>'error','error_msg'=>$step['error'] ?? 'Unknown']);
                json_response(['ok'=>false,'error'=>$step['error'] ?? 'Unknown']);
            }

            $newImported = (int)($job['imported'] ?? 0) + (int)$step['imported'];
            $newSkipped  = (int)($job['skipped'] ?? 0) + (int)$step['skipped'];
            $newOffset   = (int)$step['offset'];
            $size        = (int)$step['size'];
            $done        = ($newOffset >= $size);

            import_job_update($jobId, [
                'imported' => $newImported,
                'skipped'  => $newSkipped,
                'byte_offset' => $newOffset,
                'file_size' => $size,
                'status' => $done ? 'done' : 'running',
            ]);

            $job2 = import_job_get($jobId);
            json_response(['ok'=>true,'done'=>$done,'step'=>$step,'job'=>$job2]);
        }

        if ($ajax === 'mail_step') {
            require_admin();
            $jobId = (int)($_GET['job_id'] ?? 0);
            if ($jobId <= 0) json_response(['ok'=>false,'error'=>'Invalid job id'], 400);
            json_response(mail_job_step($jobId, 50));
        }

        json_response(['ok'=>false,'error'=>'Unknown ajax'], 404);

    } catch (Throwable $e) {
        debug_log($e->getMessage());
        json_response(['ok'=>false,'error'=>'Server action failed. Please try again or check the server log.'], 500);
    }
}

/* =========================
   ROUTING PREP
   ========================= */

$page = $_GET['page'] ?? 'home';
$q    = trim($_GET['q'] ?? '');
$p    = max(1, (int)($_GET['p'] ?? 1));
$isAjaxPage = isset($_GET['ajax_page']);
if (in_array($page, ['admin', 'admin_users'], true)) require_admin();

$login_errors    = [];
$register_errors = [];
$admin_flash     = '';
$forgot_errors   = [];
$forgot_ok       = '';
$reset_errors    = [];
$reset_ok        = '';

if ($page === 'account') {
    handle_account_update();
} elseif ($page === 'login') {
    $login_errors = handle_login();
} elseif ($page === 'register') {
    $register_errors = handle_register();
} elseif ($page === 'forgot') {
    [$forgot_errors, $forgot_ok] = handle_forgot();
} elseif ($page === 'reset') {
    [$reset_errors, $reset_ok] = handle_reset_password();
} elseif ($page === 'logout') {
    unset($_SESSION['user_id'], $_SESSION['auth_revision'], $_SESSION['csrf_token']);
    session_regenerate_id(true);
    header('Location: ' . site_url() . '/?page=home');
    exit;
}

// Handle destructive form submissions before rendering/counting (POST -> Redirect -> GET).
if ($page === 'admin' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (isset($_POST['delete_all_stations']) || isset($_POST['delete_dead']))) {
    require_admin();
    $all = isset($_POST['delete_all_stations']);
    if (!csrf_valid()) {
        $admin_flash = 'Nothing was deleted: your page has expired. Refresh the page and try again.';
    } elseif ($all && trim((string)($_POST['delete_confirmation'] ?? '')) !== 'DELETE') {
        $admin_flash = 'Nothing was deleted. Type DELETE to confirm removal of all radio stations.';
    } else {
        try {
            $r = admin_delete_station_catalog($all);
            $admin_flash = (int)$r['deleted'] . ' radio station(s) permanently removed, including their favorites and play history.';
            if ($all) $admin_flash .= ' User accounts were kept. Active scans were reset and ' . (int)$r['cancelled_imports'] . ' import job(s) cancelled.';
        } catch (Throwable $e) {
            debug_log($e->getMessage());
            $admin_flash = 'Removal did not complete. The database may be busy, or orppx-cache may not be writable. Please try again.';
        }
    }
    $_SESSION['station_admin_flash'] = $admin_flash;
    header('Location: ' . site_url() . '/?page=admin', true, 303);
    exit;
}
if ($page === 'admin' && isset($_SESSION['station_admin_flash'])) {
    $admin_flash = (string)$_SESSION['station_admin_flash'];
    unset($_SESSION['station_admin_flash']);
}

touch_last_seen();

$seoStation = null;
if ($page === 'station') {
    $seoStationId = max(0, (int)($_GET['id'] ?? 0));
    if ($seoStationId > 0) $seoStation = get_station($seoStationId);
    if ($seoStation && (int)$seoStation['is_dead'] !== 0) $seoStation = null;
}
$seoMeta = seo_meta_context($page, $q, $p, $seoStation);
if ($page === 'station' && !$seoStation) $seoMeta['robots'] = 'noindex,follow';
$seoJsonLd = seo_jsonld($page, $q, $seoStation);
if (!$isAjaxPage && !isset($_GET['ajax'])) {
    seo_ensure_static_robots_file();
}

/* footer info (full page only) */
$footerStationsTotal = 0;
if (!$isAjaxPage) {
    $footerStationsTotal = stations_count();
}

$u = current_user();
$userId = $u['id'] ?? null;
$favIds = $userId ? user_favorites_ids((int)$userId) : [];
csrf_token();
playback_browser_id();
// Per-session action tokens/favorites must never be stored in a shared page cache.
header('Cache-Control: private, no-store');
if ($isAjaxPage) {
    render_orppx_content($page, $q, $p, $u, $favIds, $login_errors, $register_errors, $forgot_errors, $forgot_ok, $reset_errors, $reset_ok, $admin_flash);
    exit;
}

/* from here HTML in part 2 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="generator" content="Online Radio++ <?= h(APP_VERSION) ?>">
    <title><?= h((string)$seoMeta['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= h((string)$seoMeta['description']) ?>">
    <meta name="robots" content="<?= h((string)$seoMeta['robots']) ?>">
    <link rel="canonical" href="<?= h((string)$seoMeta['canonical']) ?>">
    <link rel="sitemap" type="application/xml" href="<?= h(seo_abs_url('?sitemap=xml')) ?>">
    <meta property="og:site_name" content="<?= h(APP_NAME) ?>">
    <meta property="og:type" content="<?= h((string)$seoMeta['type']) ?>">
    <meta property="og:title" content="<?= h((string)$seoMeta['title']) ?>">
    <meta property="og:description" content="<?= h((string)$seoMeta['description']) ?>">
    <meta property="og:url" content="<?= h((string)$seoMeta['canonical']) ?>">
    <meta name="twitter:card" content="summary">
    <script type="application/ld+json"><?= $seoJsonLd ?></script>
    <style>
        :root {
            --bg: #05070a;
            --bg-card: #10141b;
            --accent: #00c27a;
            --accent-soft: rgba(0, 194, 122, 0.15);
            --text: #f5f5f5;
            --muted: #8b91a1;
            --danger: #ff4b4b;
            --orange: #ff9800;
            --blue: #00bcd4;
            --fav-on: #fff4a0;
            --fav-off: #555a63;

            --admin-h2: #00c27a;
            --admin-h3: #00bcd4;
            --admin-h4: #ff9800;
            --admin-sep: #263145;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background: #10141b url(background/lightblue.jpg) no-repeat center center fixed;
            background-size: cover;
        }
        a { color: var(--blue); text-decoration: none; }
        a:hover { text-decoration: underline; }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 18px;
            background: rgba(5,7,10,0.9);
            border-bottom: 1px solid #202633;
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(8px);
            gap: 10px;
        }
        .brand { display: flex; align-items: center; gap: 6px; font-weight: 600; letter-spacing: .05em; white-space: nowrap; }
        .brand-pill {
            width: 28px; height: 28px; border-radius: 999px;
            background: radial-gradient(circle at 30% 20%, #00ffe0, #006eff);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 14px; color: #fff;
            flex: 0 0 auto;
        }
        nav { display: flex; align-items: center; gap: 12px; }
        .nav-link { padding: 6px 10px; border-radius: 999px; font-size: 13px; color: var(--muted); white-space: nowrap; }
        .nav-link.active { background: var(--accent-soft); color: var(--accent); }

        .search-box { display: flex; align-items: center; gap: 6px; }
        .search-box input {
            background: #0a0f16;
            border-radius: 999px;
            border: 1px solid #202633;
            padding: 5px 10px;
            color: var(--text);
            font-size: 13px;
            min-width: 220px;
        }
        .search-box button {
            border-radius: 999px;
            border: none;
            padding: 5px 10px;
            font-size: 13px;
            background: var(--accent);
            color: #000;
            cursor: pointer;
            flex: 0 0 auto;
        }
        .auth-links a { font-size: 13px; color: var(--muted); margin-left: 8px; }
        .auth-links span { font-size: 13px; color: var(--muted); }

        /* MOBILE PORTRAIT FIX: header wraps, nav scrolls, no overflow */
        @media (max-width: 720px) {
            header {
                flex-wrap: wrap;
                padding: 10px 12px;
                gap: 8px;
            }
            .brand { flex: 1 1 auto; }
            nav {
                order: 2;
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                padding-bottom: 2px;
            }
            nav::-webkit-scrollbar { height: 6px; }
            .search-box {
                order: 3;
                width: 100%;
            }
            .search-box input {
                min-width: 0;
                width: 100%;
                flex: 1 1 auto;
            }
            .auth-links {
                order: 4;
                width: 100%;
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 6px;
            }
        }

        main { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; padding: 12px 16px 150px; }
        @media (max-width: 720px) {
            main { padding: 10px 10px 190px; }
        }

        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; }
        @media (max-width: 520px) {
            .grid { grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); }
        }

        .station-card {
            position: relative;
            background: var(--bg-card);
            border-radius: 12px;
            padding: 10px 10px 12px;
            border: 1px solid #171c27;
            box-shadow: 0 10px 18px rgba(0,0,0,0.45);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .station-top { display: flex; align-items: center; gap: 8px; }
        .station-logo {
            width: 30px; height: 30px; border-radius: 8px; background: #1d2331;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 700; color: var(--accent);
        }
        .station-title {
            font-size: 14px; font-weight: 500;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            padding-right: 32px; max-width: 180px;
        }
        .station-meta { font-size: 11px; color: var(--muted); }
        .station-actions { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
        .station-top { min-width: 0; }
        .station-top > div:last-child { min-width: 0; flex: 1; }
        .station-logo { flex-shrink: 0; }
        .station-title { max-width: 100%; }

        .btn { border-radius: 999px; border: none; font-size: 12px; padding: 4px 10px; cursor: pointer; white-space: nowrap; }
        .btn-play { background: var(--accent); color: #000; font-weight: 500; }
        .btn-open { background: #151b27; color: var(--muted); border: 1px solid #222938; }
        .btn-play:hover { filter: brightness(1.1); }
        .btn-open:hover { border-color: var(--blue); }
        .btn-details { background: #0f141f; color: var(--blue); border: 1px solid #222938; display:inline-block; }
        .btn-details:hover { border-color: var(--accent); text-decoration:none; }
        .station-detail-page h1 { margin-bottom: 6px; }
        .station-detail-actions { margin-top: 12px; }

        .fav-star {
            position: absolute; top: 6px; right: 8px;
            border: none; background: none; cursor: pointer; font-size: 16px;
            text-shadow: 0 0 6px rgba(0,0,0,0.65);
        }
        .fav-star span { transition: color .18s ease, transform .18s ease, opacity .18s ease; color: var(--fav-off); opacity: .65; }
        .fav-star.on span { color: var(--fav-on); opacity: 1; transform: scale(1.1); }
        .fav-star.disabled { cursor: default; opacity: .4; }

        .pagination { margin-top: 10px; font-size: 12px; display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 3px 7px; border-radius: 999px; border: 1px solid #222938; color: var(--muted); }
        .pagination span.current { border-color: var(--accent); color: var(--accent); }

        .account-page #orppx-player { display: none; }
        .account-page main { padding-bottom: 90px; }

        /* Floating player */
        #orppx-player {
            position: fixed; left: 12px; bottom: 64px; width: 290px;
            background: rgba(7,9,14,0.96);
            border-radius: 14px; border: 1px solid #1f2633;
            box-shadow: 0 14px 28px rgba(0,0,0,0.6);
            padding: 8px 10px 10px;
            z-index: 200;
            cursor: move;
        }
        @media (max-width: 720px) {
            #orppx-player { width: calc(100% - 24px); left: 12px; right: 12px; bottom: 72px; }
        }

        #orppx-player-header { display: flex; align-items: center; justify-content: space-between; gap: 6px; font-size: 12px; }
        #orppx-player-title { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        #orppx-player-status { font-size: 11px; color: var(--muted); }
        #orppx-player-track {
            margin-top: 5px; font-size: 11px; color: var(--accent);
            min-width: 0; max-width: 100%; line-height: 1.5;
            white-space: nowrap; overflow: hidden; text-overflow: clip;
            cursor: default;
        }
        #orppx-player-track-text { display: inline-block; width: max-content; max-width: none; vertical-align: top; }
        #orppx-player-track.empty { color: var(--muted); }
        #orppx-player-track.overflows { cursor: pointer; }
        #orppx-player-track:focus-visible { outline: 1px solid var(--accent); outline-offset: 2px; border-radius: 2px; }
        #orppx-player-track.expanded { white-space: normal; overflow: visible; }
        #orppx-player-track.expanded #orppx-player-track-text {
            width: auto; max-width: 100%; white-space: normal; overflow-wrap: anywhere;
            transform: none !important;
        }
        #orppx-player-controls { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
        #orppx-player-controls button { border-radius: 999px; border: none; padding: 4px 8px; font-size: 11px; cursor: pointer; }
        #orppx-player-play { background: var(--accent); color: #000; font-weight: 500; }
        #orppx-player-stop { background: #151b27; color: var(--muted); border: 1px solid #222938; }
        #orppx-player-volume { flex: 1; }
        #orppx-player-volume input[type=range] { width: 100%; }
        #orppx-player-services { display: flex; gap: 4px; margin-top: 6px; }
        #orppx-player-services button {
            flex: 1; border-radius: 999px; border: none; padding: 3px 4px; font-size: 11px;
            background: #0f141f; color: var(--muted); cursor: pointer;
        }

        .box { background: #0b1018; border-radius: 12px; border: 1px solid #202633; padding: 12px; margin-top: 10px; font-size: 13px; }
        .row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .row button { border-radius: 999px; border: none; padding: 6px 12px; font-size: 12px; background: var(--orange); color: #000; cursor: pointer; }
        .row button.secondary { background: #151b27; color: var(--muted); border: 1px solid #222938; }
        .row progress { width: 220px; }
        .flash-msg { margin: 6px 0; font-size: 13px; color: var(--orange); }
        .error-msg { color: var(--danger); font-size: 13px; margin: 8px 0; }
        .ok-msg { color: var(--accent); font-size: 13px; margin: 8px 0; }

        .auth-form { max-width: 420px; margin-top: 10px; }
        .auth-form input, .auth-form textarea {
            width: 100%; padding: 8px 10px; margin-bottom: 8px;
            border-radius: 8px; border: 1px solid #222938; background: #05070b; color: var(--text);
        }
        .auth-form button { padding: 8px 14px; border-radius: 999px; border: none; background: var(--accent); color: #000; cursor: pointer; }

        .label-badge {
            display: inline-block; border-radius: 999px; padding: 1px 6px;
            font-size: 10px; text-transform: uppercase; letter-spacing: .04em;
            border: 1px solid #263145; color: var(--muted); margin-left: 6px;
        }
        .label-badge.dead { border-color: var(--danger); color: var(--danger); }

        .file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; margin-top: 8px; }
        .file-card {
            background: #0a0f16; border: 1px solid #1f2633; border-radius: 12px;
            padding: 10px; display: flex; align-items: flex-start; gap: 10px;
        }
        .file-card input { transform: translateY(3px); }
        .file-meta { font-size: 12px; color: var(--muted); }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }

        table { width: 100%; border-collapse: collapse; overflow: hidden; border-radius: 12px; }
        th, td { padding: 8px 10px; border-bottom: 1px solid #1f2633; font-size: 13px; }
        th { text-align: left; color: var(--muted); font-weight: 600; }
        tr:hover td { background: rgba(255,255,255,0.03); }

        /* Admin clarity */
        .admin-page h2 { color: var(--admin-h2); margin-top: 6px; }
        .admin-page h3, .admin-page h4 {
            margin-top: 22px;
            padding-top: 14px;
            border-top: 1px solid var(--admin-sep);
        }
        .admin-page h3 { color: var(--admin-h3); }
        .admin-page h4 { color: var(--admin-h4); }

        .admin-page .table-wrap {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-top: 8px;
            border-radius: 12px;
        }
        .admin-page .table-wrap table { min-width: 820px; }
        .admin-page .table-wrap th, .admin-page .table-wrap td { white-space: nowrap; }

        .admin-tabs { display:flex; gap:8px; flex-wrap:wrap; margin: 8px 0 12px; }
        .admin-tabs a { border:1px solid #263145; border-radius:999px; padding:6px 10px; font-size:12px; color:var(--muted); }
        .admin-tabs a.active { border-color:var(--accent); color:var(--accent); background:var(--accent-soft); text-decoration:none; }
        .admin-user-form-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:8px; align-items:end; }
        .admin-user-form-grid input { width:100%; padding:8px 10px; border-radius:8px; border:1px solid #222938; background:#05070b; color:var(--text); }
        .admin-user-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
        .admin-user-actions form { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin:0; }
        .admin-user-actions input[type=password] { min-width:190px; padding:6px 8px; border-radius:8px; border:1px solid #222938; background:#05070b; color:var(--text); }
        .admin-user-actions button, .admin-user-form-grid button { border-radius:999px; border:none; padding:6px 10px; font-size:12px; background:var(--orange); color:#000; cursor:pointer; }
        .admin-user-actions button.secondary, .admin-user-form-grid button.secondary { background:#151b27; color:var(--muted); border:1px solid #222938; }
        .admin-user-actions button.danger { background:var(--danger); color:#fff; }
        .admin-user-actions label, .admin-user-form-grid label { font-size:12px; color:var(--muted); }


        .select {
            background:#05070b;
            color:var(--text);
            border:1px solid #222938;
            border-radius:999px;
            padding:6px 10px;
            font-size:12px;
        }


        #orppx-player-title { flex: 1; min-width: 0; }
        #orppx-player-favorite { flex: 0 0 auto; border: 0; background: transparent; color: var(--fav-off); cursor: pointer; font-size: 22px; line-height: 1; padding: 4px; }
        #orppx-player-favorite.on { color: var(--fav-on); }
        #orppx-player-favorite:disabled { opacity: .45; cursor: default; }
        #orppx-player-favorite:focus-visible { outline: 2px solid var(--accent); border-radius: 6px; }
        #orppx-player-notice { margin-top: 8px; padding: 8px; border-radius: 8px; border: 1px solid #67431f; color: #ffd4a0; background: #20180f; font-size: 12px; line-height: 1.45; cursor: auto; }
        #orppx-player-notice[hidden] { display: none; }
        .station-danger-zone { border-color: var(--danger); }
        .station-danger-zone summary { color: var(--danger); cursor: pointer; font-weight: 600; }
        .station-danger-zone input { max-width: 160px; background: #05070b; border: 1px solid #67431f; border-radius: 6px; color: var(--text); padding: 8px; }
        .row button.danger { background: var(--danger); color: #fff; }

        /* Footer */
        footer#orppx-footer {
            position: fixed;
            left: 0; right: 0; bottom: 0;
            background: rgba(5,7,10,0.92);
            border-top: 1px solid #202633;
            padding: 10px 16px;
            z-index: 150;
            backdrop-filter: blur(8px);
        }
        .footer-inner {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap: 10px;
            font-size: 12px;
            color: var(--muted);
            flex-wrap: wrap;
        }
        .footer-inner strong { color: var(--text); font-weight: 600; }
        .footer-pill {
            padding: 2px 8px;
            border-radius: 999px;
            border: 1px solid #263145;
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
        }
    </style>
</head>
<body class="<?= in_array($page, ['account', 'login', 'register', 'forgot', 'reset'], true) ? 'account-page' : '' ?>">
<?php
$u = current_user();
$userId = $u['id'] ?? null;
$favIds = $userId ? user_favorites_ids((int)$userId) : [];

function render_station_card_inner(array $st, bool $isFav, bool $loggedIn, bool $showLastPlayed = false): void {
    $initial = strtoupper(mb_substr((string)$st['name'], 0, 1));
    $title   = truncate_title((string)$st['name']);
    $plays   = (int)($st['total_plays'] ?? 0);
    $opens   = (int)($st['total_opens'] ?? 0);
    $dead    = (int)($st['is_dead'] ?? 0) === 1;
    ?>
    <button class="fav-star<?= $isFav ? ' on' : '' ?><?= !$loggedIn ? ' disabled' : '' ?>" type="button"
            aria-pressed="<?= $isFav ? 'true' : 'false' ?>" aria-label="Add/remove favorite"
            title="<?= $loggedIn ? 'Add/remove favorite' : 'Log in to use favorites' ?>">
        <span>&#9733;</span>
    </button>
    <div class="station-top">
        <div class="station-logo"><?= h($initial) ?></div>
        <div>
            <div class="station-title"><?= h($title) ?></div>
            <div class="station-meta">
                <?= $plays ?>x play / <?= $opens ?>x open
                <?php if ($dead): ?><span class="label-badge dead">dead</span><?php endif; ?>
                <?php if ($showLastPlayed && !empty($st['last_play'])): ?>
                    <span class="label-badge">last: <?= h(substr((string)$st['last_play'], 0, 16)) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="station-actions">
        <button class="btn btn-play" type="button">Play</button>
        <button class="btn btn-open" type="button" title="Open direct stream URL">Open URL</button>
        <a class="btn btn-details" href="<?= h(station_public_url($st)) ?>" title="Indexable station page">Details</a>
    </div>
    <?php
}

function render_orppx_content(string $page, string $q, int $p, ?array $u, array $favIds,
    array $login_errors, array $register_errors, array $forgot_errors, string $forgot_ok,
    array $reset_errors, string $reset_ok, string &$admin_flash): void {

    $userId = $u['id'] ?? null;

    if ($page === 'account') { render_account_form($u); return; }

    if ($page === 'retest') {
        $admin = $u && (int)$u['is_admin'] === 1;
        [$stations, $total] = suspect_stations(playback_identity(), (bool)$admin, $p);
        ?>
        <h2>Recheck hidden radio stations</h2>
        <p class="file-meta">These stations are hidden from the normal lists after a playback failure. Press Test stream to make a new test in your browser. Successful playback restores the station; a second failure from a different visitor removes it. No station is tested automatically in the background.</p>
        <?php if ($admin): ?><p class="file-meta">Admin view: all hidden stations are shown. Repeated failures from your own account or browser do not count as two visitors. The strict admin scan is separate and can remove a station after one unsuccessful server check.</p><?php endif; ?>
        <?php if (!$stations): ?><p>No hidden stations are available for you to test. Stations you reported yourself are not listed here for a second visitor's test.</p><?php endif; ?>
        <div class="grid">
        <?php foreach ($stations as $st): ?>
            <div class="station-card" data-retest="1" data-id="<?= (int)$st['id'] ?>"
                 data-name="<?= h((string)$st['name']) ?>" data-url="<?= h((string)$st['stream_url']) ?>">
                <?php render_station_card_inner($st, in_array((int)$st['id'], $favIds, true), (bool)$userId); ?>
                <div class="file-meta">Possibly defective · awaiting a new test</div>
                <?php if ($admin && !empty($st['last_failure'])): ?><div class="file-meta"><?= h((string)$st['last_failure']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php $pages = max(1, (int)ceil($total / PAGE_SIZE)); if ($pages > 1): ?>
        <div class="pagination">
            <?php if ($p > 1): ?><a href="?page=retest&amp;p=<?= $p-1 ?>">&laquo; Previous</a><?php endif; ?>
            <span class="current">Page <?= $p ?> / <?= $pages ?></span>
            <?php if ($p < $pages): ?><a href="?page=retest&amp;p=<?= $p+1 ?>">Next &raquo;</a><?php endif; ?>
        </div>
        <?php endif;
        return;
    }


    if ($page === 'station') {
        $stationId = max(0, (int)($_GET['id'] ?? 0));
        $st = $stationId > 0 ? get_station($stationId) : null;
        if (!$st) {
            echo '<h2>Radio station not found</h2><p class="file-meta">This station no longer exists or has been removed.</p>';
            echo '<p><a href="?page=home">Back to all radio stations</a></p>';
            return;
        }
        if ((int)$st['is_dead'] !== 0) {
            echo '<h2>Station temporarily hidden</h2><p>This station is possibly defective and is awaiting a new playback test.</p>';
            echo '<p><a href="?page=retest">Test hidden stations</a></p>';
            return;
        }
        $isFav = $userId && in_array((int)$st['id'], $favIds, true);
        ?>
        <article class="station-detail-page">
            <h1><?= h((string)$st['name']) ?> live stream</h1>
            <p class="file-meta">Listen to free online radio via <?= h(APP_NAME) ?>. Use Play to start the stream; as soon as the station sends metadata, Spotify, YouTube Music and Last.fm will automatically search for the current track.</p>
            <div class="grid" style="max-width:520px;">
                <div class="station-card" data-id="<?= (int)$st['id'] ?>"
                     data-name="<?= h((string)$st['name']) ?>" data-url="<?= h((string)$st['stream_url']) ?>">
                    <?php render_station_card_inner($st, (bool)$isFav, (bool)$userId); ?>
                </div>
            </div>
            <div class="box station-detail-actions">
                <h3>About this radio station</h3>
                <p class="file-meta"><strong>Name:</strong> <?= h((string)$st['name']) ?></p>
                <?php if (!empty($st['tags'])): ?><p class="file-meta"><strong>Tags:</strong> <?= h((string)$st['tags']) ?></p><?php endif; ?>
                <?php if (!empty($st['homepage'])): ?><p class="file-meta"><strong>Website:</strong> <a href="<?= h((string)$st['homepage']) ?>" rel="nofollow noopener" target="_blank">open official website</a></p><?php endif; ?>
                <p><a href="?page=home">Find more online radio stations</a></p>
            </div>
        </article>
        <?php
        return;
    }

    if ($page === 'recent') {
        $stations = recent_stations(100);
        ?>
        <h2>Recently played stations</h2>
        <div class="grid">
            <?php foreach ($stations as $st):
                $isFav = $userId && in_array((int)$st['id'], $favIds, true); ?>
                <div class="station-card" data-id="<?= (int)$st['id'] ?>"
                     data-name="<?= h((string)$st['name']) ?>" data-url="<?= h((string)$st['stream_url']) ?>">
                    <?php render_station_card_inner($st, (bool)$isFav, (bool)$userId, true); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return;
    }

    if ($page === 'favorites') {
        if (!$u) { echo '<p>Please log in to view your favorites.</p>'; return; }
        if (empty($favIds)) { echo '<h2>Favorites</h2><p>You have not added any favorites yet.</p>'; return; }
        $placeholders = implode(',', array_fill(0, count($favIds), '?'));
        $stmtFav = db()->prepare("SELECT * FROM stations WHERE id IN ($placeholders) AND COALESCE(is_dead,0)=0 ORDER BY name ASC");
        $stmtFav->execute($favIds);
        $stations = $stmtFav->fetchAll();
        ?>
        <h2>Favorites</h2>
        <div class="grid">
            <?php foreach ($stations as $st): ?>
                <div class="station-card" data-id="<?= (int)$st['id'] ?>"
                     data-name="<?= h((string)$st['name']) ?>" data-url="<?= h((string)$st['stream_url']) ?>">
                    <?php render_station_card_inner($st, true, true); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return;
    }


    if ($page === 'admin_users') {
        require_admin();

        $currentAdminId = (int)($u['id'] ?? 0);
        $userFlash = '';
        $userError = '';
        $uq = trim((string)($_GET['uq'] ?? ''));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['admin_user_action'] ?? '');
            try {
                if (!csrf_valid()) throw new RuntimeException('Your page has expired. Refresh the page and try again.');
                if ($action === 'add_user') {
                    $newUsername = trim((string)($_POST['new_username'] ?? ''));
                    $newEmail = trim((string)($_POST['new_email'] ?? ''));
                    $newPassword = (string)($_POST['new_password'] ?? '');
                    $newIsAdmin = isset($_POST['new_is_admin']) ? 1 : 0;
                    $sendEmail = isset($_POST['send_login_email']);

                    $created = admin_create_user_account($newUsername, $newEmail, $newPassword, $newIsAdmin);
                    if (!$created['ok']) {
                        $userError = (string)$created['error'];
                    } else {
                        $newUser = admin_get_user((int)$created['user_id']);
                        $mailInfo = '';
                        if ($sendEmail && $newUser) {
                            $sent = admin_send_password_email($newUser, (string)$created['password'], true);
                            $mailInfo = $sent ? ' Login details were sent by email.' : ' Sending the email failed.';
                        }
                        $userFlash = 'User added: ' . $newUsername . '.' . $mailInfo;
                        if (!$sendEmail && !empty($created['generated'])) {
                            $userFlash .= ' Generated password: ' . (string)$created['password'];
                        }
                    }
                } elseif ($action === 'set_password') {
                    $targetId = (int)($_POST['user_id'] ?? 0);
                    $newPass = (string)($_POST['password'] ?? '');
                    $sendEmail = isset($_POST['email_password']);
                    $target = admin_get_user($targetId);
                    if (!$target) {
                        $userError = 'User not found.';
                    } elseif ($newPass === '') {
                        $userError = 'Please enter a new password.';
                    } else {
                        admin_set_user_password($targetId, $newPass);
                        $userFlash = 'Password changed for ' . (string)$target['username'] . '.';
                        if ($sendEmail) {
                            $sent = admin_send_password_email($target, $newPass, false);
                            $userFlash .= $sent ? ' New password was sent by email.' : ' Sending the email failed.';
                        }
                    }
                } elseif ($action === 'send_new_password') {
                    $targetId = (int)($_POST['user_id'] ?? 0);
                    $target = admin_get_user($targetId);
                    if (!$target) {
                        $userError = 'User not found.';
                    } elseif (empty($target['email']) || !filter_var((string)$target['email'], FILTER_VALIDATE_EMAIL)) {
                        $userError = 'This user does not have a valid email address.';
                    } else {
                        $plain = admin_generate_password(16);
                        admin_set_user_password($targetId, $plain);
                        $sent = admin_send_password_email($target, $plain, false);
                        if ($sent) {
                            $userFlash = 'New password generated and sent by email to ' . (string)$target['email'] . '.';
                        } else {
                            $userError = 'Password was changed, but mail() could not send the email. New password: ' . $plain;
                        }
                    }
                } elseif ($action === 'set_admin') {
                    $targetId = (int)($_POST['user_id'] ?? 0);
                    $target = admin_get_user($targetId);
                    $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
                    if (!$target) {
                        $userError = 'User not found.';
                    } elseif ($targetId === $currentAdminId && $isAdmin === 0) {
                        $userError = 'You cannot remove your own admin rights while you are logged in.';
                    } else {
                        admin_set_user_admin($targetId, $isAdmin);
                        $userFlash = 'Admin rights updated for ' . (string)$target['username'] . '.';
                    }
                } elseif ($action === 'delete_user') {
                    $targetId = (int)($_POST['user_id'] ?? 0);
                    $deleted = admin_delete_user_full($targetId, $currentAdminId);
                    if (!$deleted['ok']) $userError = (string)$deleted['error'];
                    else $userFlash = 'User deleted: ' . (string)$deleted['username'] . '.';
                }
            } catch (Exception $e) {
                $userError = 'Action failed: ' . $e->getMessage();
            }
        }

        $users = admin_search_users($uq, 250);
        ?>
        <div class="admin-page">
            <h2>User management</h2>
            <div class="admin-tabs">
                <a href="?page=admin">Admin overview</a>
                <a href="?page=admin_users" class="active">User management</a>
            </div>

            <?php if ($userFlash !== ''): ?><div class="flash-msg"><?= h($userFlash) ?></div><?php endif; ?>
            <?php if ($userError !== ''): ?><div class="error-msg"><?= h($userError) ?></div><?php endif; ?>

            <form method="post" action="?page=admin_users" class="box">
                <h3>Add new user</h3>
                <input type="hidden" name="admin_user_action" value="add_user">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="admin-user-form-grid">
                    <label>Username<br><input type="text" name="new_username" required autocomplete="off"></label>
                    <label>Email address<br><input type="email" name="new_email" required autocomplete="off"></label>
                    <label>Password<br><input type="password" name="new_password" autocomplete="new-password" placeholder="Empty = generate automatically"></label>
                    <label><input type="checkbox" name="new_is_admin" value="1"> Admin rights</label>
                    <label><input type="checkbox" name="send_login_email" value="1" checked> Email login details</label>
                    <button type="submit">Add user</button>
                </div>
                <div class="file-meta" style="margin-top:8px;">The current password is never shown. If the password field is empty, a strong password is generated automatically.</div>
            </form>

            <form method="get" action="" class="box">
                <h3>Search users</h3>
                <input type="hidden" name="page" value="admin_users">
                <div class="row">
                    <input type="text" name="uq" value="<?= h($uq) ?>" placeholder="Search by ID, username or email address" style="min-width:260px;max-width:420px;flex:1;padding:8px 10px;border-radius:999px;border:1px solid #222938;background:#05070b;color:var(--text);">
                    <button type="submit">Search</button>
                    <a class="btn btn-open" href="?page=admin_users">Reset</a>
                    <span class="file-meta"><?= count($users) ?> result(s)</span>
                </div>
            </form>

            <div class="box">
                <h3>Users</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Email</th>
                                <th>Admin</th>
                                <th>Last seen</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $usr):
                            $uid = (int)$usr['id'];
                            $isSelf = ($uid === $currentAdminId);
                        ?>
                            <tr>
                                <td><?= $uid ?></td>
                                <td><strong><?= h((string)$usr['username']) ?></strong><?= $isSelf ? ' <span class="label-badge">you</span>' : '' ?></td>
                                <td><?= h((string)($usr['email'] ?? '')) ?></td>
                                <td>
                                    <form method="post" action="?page=admin_users<?= $uq !== '' ? '&amp;uq=' . urlencode($uq) : '' ?>" class="admin-user-actions">
                                        <input type="hidden" name="admin_user_action" value="set_admin">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                                        <label><input type="checkbox" name="is_admin" value="1" <?= ((int)$usr['is_admin'] === 1) ? 'checked' : '' ?>> admin</label>
                                        <button type="submit" class="secondary">Save</button>
                                    </form>
                                </td>
                                <td><?= h(time_ago((string)($usr['last_seen_at'] ?? ''))) ?></td>
                                <td><?= h(substr((string)($usr['created_at'] ?? ''), 0, 16)) ?></td>
                                <td>
                                    <div class="admin-user-actions">
                                        <form method="post" action="?page=admin_users<?= $uq !== '' ? '&amp;uq=' . urlencode($uq) : '' ?>">
                                            <input type="hidden" name="admin_user_action" value="set_password">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <input type="password" name="password" placeholder="New password" autocomplete="new-password" required>
                                            <label><input type="checkbox" name="email_password" value="1"> email</label>
                                            <button type="submit">Change</button>
                                        </form>
                                        <form method="post" action="?page=admin_users<?= $uq !== '' ? '&amp;uq=' . urlencode($uq) : '' ?>">
                                            <input type="hidden" name="admin_user_action" value="send_new_password">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <button type="submit" class="secondary">Email new password</button>
                                        </form>
                                        <?php if ($isSelf): ?>
                                            <span class="file-meta">Own account cannot be deleted</span>
                                        <?php else: ?>
                                            <form method="post" action="?page=admin_users<?= $uq !== '' ? '&amp;uq=' . urlencode($uq) : '' ?>" onsubmit="return confirm('Permanently delete this user?');">
                                                <input type="hidden" name="admin_user_action" value="delete_user">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <button type="submit" class="danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$users): ?>
                            <tr><td colspan="7" class="file-meta">No users found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
        return;
    }

    if ($page === 'admin') {
        require_admin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_import'])) {
            if (isset($_FILES['m3u']) && is_uploaded_file($_FILES['m3u']['tmp_name'])) {
                $name = basename((string)($_FILES['m3u']['name'] ?? 'upload.m3u'));
                $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['m3u','m3u8','txt'], true)) {
                    $admin_flash = 'Only .m3u/.m3u8/.txt are supported.';
                } else {
                    $safe = 'upload_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
                    $dest = __DIR__ . DIRECTORY_SEPARATOR . $safe;
                    if (@move_uploaded_file($_FILES['m3u']['tmp_name'], $dest)) {
                        $jobId = import_job_create($safe);
                        $admin_flash = 'Upload queued as job #' . $jobId . ' (' . $safe . '). Click "Resume all" to start.';
                    } else $admin_flash = 'Could not move uploaded file.';
                }
            } else $admin_flash = 'No valid file uploaded.';
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cache'])) {
            $deleted = cache_clear_all();
            $admin_flash = ($admin_flash ? $admin_flash . ' ' : '') . 'Cache cleared (' . $deleted . ' file(s)).';
        }

        $rootFiles = list_root_import_files(true);
        $activeJobs = import_job_list_active();
        $cacheStats = cache_stats();
        ?>
        <div class="admin-page">
            <h2>Admin</h2>

            <div class="admin-tabs">
                <a href="?page=admin" class="active">Admin overview</a>
                <a href="?page=admin_users">User management</a>
            </div>
            <?php if ($admin_flash !== ''): ?><div class="flash-msg"><?= h($admin_flash) ?></div><?php endif; ?>

            <details class="box station-danger-zone">
                <summary>Danger zone: delete all radio stations</summary>
                <form method="post" action="?page=admin" id="delete-all-stations-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="delete_all_stations" value="1">
                    <p>This permanently removes <strong><?= stations_count() ?> radio station(s)</strong>, all station favorites and their play history.</p>
                    <p class="file-meta">User accounts are kept. Active scans are reset and queued/running import jobs are cancelled to prevent stations from immediately being imported again. This cannot be undone here. Make a database backup first.</p>
                    <div class="row">
                        <label for="delete-station-confirm">Type <strong>DELETE</strong> to confirm:</label>
                        <input id="delete-station-confirm" name="delete_confirmation" required pattern="DELETE" autocomplete="off" spellcheck="false" placeholder="DELETE">
                        <button type="submit" class="danger">Delete all radio stations</button>
                    </div>
                </form>
            </details>

            <form method="post" class="box">
                <h3>Server cache</h3>
                <div class="row">
                    <button type="submit" name="clear_cache" class="secondary">Clear cache</button>
                    <span class="file-meta">Status: <?= $cacheStats['ready'] ? 'active' : 'not writable / disabled' ?> — <?= (int)$cacheStats['files'] ?> file(s), <?= number_format(((int)$cacheStats['bytes'])/1024, 1) ?> KB</span>
                </div>
                <div class="file-meta">Cache folder: <span class="mono"><?= h((string)$cacheStats['path']) ?></span></div>
                <div class="file-meta">Shared metadata cache: <?= (int)CACHE_TTL_NOWPLAYING ?>s for track titles, <?= (int)CACHE_TTL_NOWPLAYING_EMPTY ?>s for empty results. One metadata refresh at a time per station. Catalogue and favorite changes use versioned keys; old files are cleaned up in small automatic batches.</div>
            </form>

            <div class="box">
                <h3>SEO / Google indexing</h3>
                <p class="file-meta">Public sitemap: <a href="<?= h(seo_abs_url('?sitemap=xml')) ?>" target="_blank" rel="noopener"><?= h(seo_abs_url('?sitemap=xml')) ?></a></p>
                <p class="file-meta">Robots output: <a href="<?= h(seo_abs_url('?robots=1')) ?>" target="_blank" rel="noopener"><?= h(seo_abs_url('?robots=1')) ?></a></p>
                <p class="file-meta">Each station now has a crawlable Details page and is listed in the sitemap.</p>
            </div>

            <div class="box">
                <h3>M3U/TXT import (upload) — resumable + progress</h3>
                <form method="post" enctype="multipart/form-data" class="row">
                    <input type="file" name="m3u" accept=".m3u,.m3u8,.txt" required>
                    <button type="submit" name="upload_import">Queue upload</button>
                </form>
                <div class="file-meta">Upload is queued as a resumable job (no timeouts).</div>
            </div>

            <div class="box" id="root-import-box">
                <h3>Bulk import from root (FTP) — resumable + progress</h3>
                <div class="row">
                    <button type="button" class="secondary" id="btn-select-all">Select all</button>
                    <button type="button" class="secondary" id="btn-select-none">Select none</button>
                    <button type="button" id="btn-start-selected">Start selected import</button>
                    <button type="button" id="btn-resume-all">Resume all</button>
                    <span class="file-meta">Chunked import: up to <?= (int)IMPORT_MAX_URLS_PER_STEP ?> URLs or ~<?= (int)IMPORT_MAX_SECONDS_PER_STEP ?>s per step. Auto-resume supported.</span>
                </div>

                <?php if (!$rootFiles): ?>
                    <p class="file-meta">No .m3u/.m3u8 (or .txt) files found in: <span class="mono"><?= h(__DIR__) ?></span></p>
                    <p class="file-meta">Tip: upload files via FTP to the same folder as <span class="mono">bootstrap.php</span>.</p>
                <?php else: ?>
                    <div class="file-grid" id="root-file-list">
                        <?php foreach ($rootFiles as $f): ?>
                            <label class="file-card">
                                <input type="checkbox" class="root-file" value="<?= h($f['name']) ?>">
                                <div>
                                    <div><strong><?= h($f['name']) ?></strong></div>
                                    <div class="file-meta"><?= number_format((int)$f['size']/1024/1024, 2) ?> MB</div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div style="margin-top:10px;">
                    <progress id="import-progress" value="0" max="100" style="width:320px;"></progress>
                    <span id="import-label" class="file-meta">Idle</span>
                </div>
                <pre id="import-log" class="mono" style="white-space:pre-wrap;background:#05070b;border:1px solid #1f2633;border-radius:12px;padding:10px;margin-top:10px;max-height:220px;overflow:auto;"></pre>

                <h4>Active/resumable jobs</h4>
                <div class="file-meta">(If you refreshed or hit a timeout, this continues where it left off)</div>
                <div id="job-list" class="file-meta">
                    <?php if (!$activeJobs): ?>
                        <div>No active jobs.</div>
                    <?php else: ?>
                        <?php foreach ($activeJobs as $j): ?>
                            <div>
                                #<?= (int)$j['id'] ?> —
                                <strong><?= h((string)$j['filename']) ?></strong>
                                (<?= h((string)$j['status']) ?>) —
                                imported <?= (int)($j['imported'] ?? 0) ?> / skipped <?= (int)($j['skipped'] ?? 0) ?> —
                                offset <?= (int)($j['byte_offset'] ?? 0) ?> / <?= (int)($j['file_size'] ?? 0) ?> bytes
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="box">
                <h3>Possibly defective stations</h3>
                <p class="file-meta">First playback failure: hidden. A fresh successful playback test restores the station and clears the failure status. Only a new failure from another account AND browser removes it. Favorites remain saved while hidden.</p>
                <a class="btn btn-details" href="?page=retest">View / test hidden stations</a>
            </div>

            <!-- Strict scan with pause/resume -->
            <div class="box" id="dead-scan-box">
                <h3>Strict stream scan (pause/resume)</h3>
                <p class="error-msg">Destructive mode: only directly verified audio is kept. DNS/TLS errors, HTTP errors, timeouts, access blocks, playlists and unrecognized responses are deleted immediately. A temporary hosting/network problem can remove working stations too. Back up the database first.</p>
                <p class="file-meta">One check per station; audio must arrive within the <?= (float)STREAM_CHECK_ATTEMPT_SECONDS ?>-second network budget. DNS lookup can take longer depending on hosting. An old-policy scan starts again at the first remaining station.</p>
                <div class="row">
                    <button type="button" id="dead-scan-start" data-action="deadscan-start">Start / Resume</button>
                    <button type="button" class="secondary" id="dead-scan-pause" data-action="deadscan-pause">Pause</button>

                    <label class="file-meta" for="dead-scan-batch" style="margin-left:4px;">Batch:</label>
                    <select id="dead-scan-batch" class="select" title="How many streams per step (higher = faster but heavier)">
                        <?php foreach ([5,10,15,20,25,30,35,40,45,50] as $n): ?>
                            <option value="<?= (int)$n ?>"><?= (int)$n ?></option>
                        <?php endforeach; ?>
                    </select>

                    <progress id="dead-scan-progress" value="0" max="100"></progress>
                    <span id="dead-scan-label" class="file-meta">Idle</span>
                </div>

                <div class="row" style="margin-top:8px;">
                    <button type="button" class="secondary" id="dead-scan-refresh" data-action="deadscan-refresh">Refresh status</button>
                    <button type="button" class="secondary" id="dead-scan-reset" data-action="deadscan-reset" title="Remove scan job (does not undo station checks)">Reset job</button>
                    <span class="file-meta">Only successful audio checks are kept; all other stream outcomes are deleted, including related favorites and play history. A local database/code error stops the scan instead. The scan can be resumed after refresh.</span>
                </div>

                <pre id="dead-scan-log" class="mono" style="white-space:pre-wrap;background:#05070b;border:1px solid #1f2633;border-radius:12px;padding:10px;margin-top:10px;max-height:180px;overflow:auto;"></pre>
            </div>

            <form method="post" action="?page=admin" class="box">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="row">
                    <button type="submit" name="delete_dead">Delete previously marked dead streams</button>
                    <span class="file-meta">Cleanup for old entries already marked as dead. The strict scan above removes every station that fails its direct-audio test.</span>
                </div>
            </form>
        </div>
        <?php
        return;
    }

    if ($page === 'login') {
        ?>
        <h2>Log in</h2>
        <?php if (isset($_SESSION['installation_notice'])): ?>
            <p class="ok-msg"><?= h((string)$_SESSION['installation_notice']) ?></p>
            <?php unset($_SESSION['installation_notice']); ?>
        <?php endif; ?>
        <?php if ($login_errors): ?><div class="error-msg"><?= h(implode(' ', $login_errors)) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="text" name="username" placeholder="Username" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Log in</button>
        </form>
        <p class="file-meta" style="margin-top:10px;"><a href="?page=forgot">Forgot password?</a></p>
        <?php
        return;
    }

    if ($page === 'forgot') {
        [$a,$b] = captcha_new('forgot');
        ?>
        <h2>Forgot password</h2>
        <?php if ($forgot_errors): ?><div class="error-msg"><?= h(implode(' ', $forgot_errors)) ?></div><?php endif; ?>
        <?php if ($forgot_ok !== ''): ?><div class="ok-msg"><?= h($forgot_ok) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="text" name="username" placeholder="Username" required>
            <input type="text" name="email" placeholder="Email address" required>
            <input type="text" name="captcha" placeholder="CAPTCHA: What is <?= (int)$a ?> + <?= (int)$b ?> ?" required>
            <button type="submit">Send reset link</button>
        </form>
        <?php
        return;
    }

    if ($page === 'reset') {
        ?>
        <h2>Reset password</h2>
        <?php if ($reset_errors): ?><div class="error-msg"><?= h(implode(' ', $reset_errors)) ?></div><?php endif; ?>
        <?php if ($reset_ok !== ''): ?>
            <div class="ok-msg"><?= h($reset_ok) ?></div>
            <p class="file-meta"><a href="?page=login">Go to login</a></p>
            <?php return; ?>
        <?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="password" name="password" placeholder="New password" required>
            <input type="password" name="password2" placeholder="Repeat new password" required>
            <button type="submit">Update password</button>
        </form>
        <?php
        return;
    }

    if ($page === 'register') {
        [$a,$b] = captcha_new('register');
        ?>
        <h2>Register</h2>
        <?php if ($register_errors): ?><div class="error-msg"><?= h(implode(' ', $register_errors)) ?></div><?php endif; ?>
        <form method="post" class="auth-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="text" name="username" placeholder="Username" required>
            <input type="text" name="email" placeholder="Email address" required>
            <input type="password" name="password" placeholder="Password" required>
            <input type="password" name="password2" placeholder="Repeat password" required>
            <input type="text" name="captcha" placeholder="CAPTCHA: What is <?= (int)$a ?> + <?= (int)$b ?> ?" required>
            <button type="submit">Create account</button>
        </form>
        <?php
        return;
    }

    [$stations, $total] = search_stations($q, $p);
    $pages = max(1, (int)ceil($total / PAGE_SIZE));
    ?>
    <h2>Stations<?= $q !== '' ? ' - search term: ' . h($q) : '' ?></h2>
    <?php if (!$u): ?><p class="favorites-hint" style="font-size:13px;color:var(--muted);margin:6px 0 10px;">Tip: create a free account to add favorites (star).</p><?php endif; ?>
    <div class="grid">
        <?php foreach ($stations as $st):
            $isFav = $userId && in_array((int)$st['id'], $favIds, true); ?>
            <div class="station-card" data-id="<?= (int)$st['id'] ?>"
                 data-name="<?= h((string)$st['name']) ?>" data-url="<?= h((string)$st['stream_url']) ?>">
                <?php render_station_card_inner($st, (bool)$isFav, (bool)$userId); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($pages > 1): ?>
        <div class="pagination">
            <?php if ($p > 1): ?><a href="?page=home&amp;q=<?= urlencode($q) ?>&amp;p=<?= $p - 1 ?>">&laquo; Previous</a><?php endif; ?>
            <span class="current">Page <?= (int)$p ?> / <?= (int)$pages ?></span>
            <?php if ($p < $pages): ?><a href="?page=home&amp;q=<?= urlencode($q) ?>&amp;p=<?= $p + 1 ?>">Next &raquo;</a><?php endif; ?>
        </div>
    <?php endif;
}

?>

<header>
    <div class="brand"><div class="brand-pill">OR</div><div>ONLINE RADIO++</div></div>

    <form class="search-box" method="get" action="" id="nav-search-form">
        <input type="hidden" name="page" value="home">
        <input type="text" name="q" placeholder="Search station..." value="<?= h($q) ?>">
        <button type="submit">Search</button>
    </form>

    <nav>
        <?php
        $navItems = ['home'=>'Stations','recent'=>'Recently played','retest'=>'Recheck stations'];
        if ($u) { $navItems['favorites'] = 'Favorites'; $navItems['account'] = 'Account'; }
        if ($u && (int)$u['is_admin'] === 1) { $navItems['admin'] = 'Admin'; $navItems['admin_users'] = 'Users'; }
        foreach ($navItems as $slug => $label): ?>
            <a class="nav-link<?= ($slug === $page ? ' active' : '') ?>" href="?page=<?= urlencode($slug) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="auth-links">
        <?php if ($u): ?>
            <span>Logged in as <strong><?= h((string)$u['username']) ?></strong><?= ((int)$u['is_admin'] === 1) ? ' (admin)' : '' ?></span>
            <a href="?page=logout">Log out</a>
        <?php else: ?>
            <a href="?page=login">Log in</a>
            <a href="?page=register">Register</a>
        <?php endif; ?>
    </div>
</header>

<main id="orppx-main">
    <section id="orppx-content">
        <?php render_orppx_content($page, $q, $p, $u, $favIds, $login_errors, $register_errors, $forgot_errors, $forgot_ok, $reset_errors, $reset_ok, $admin_flash); ?>
    </section>
</main>

<div id="orppx-player">
    <div id="orppx-player-header">
        <div id="orppx-player-title">Choose a station...</div>
        <button id="orppx-player-favorite" type="button" disabled aria-pressed="false" aria-label="Choose a station first" title="Choose a station first"><span aria-hidden="true">&#9733;</span></button>
        <div id="orppx-player-status">Paused</div>
    </div>
    <div id="orppx-player-track" class="empty"><span id="orppx-player-track-text">Now playing: choose a station...</span></div>
    <div id="orppx-player-controls">
        <button id="orppx-player-play" type="button">Play</button>
        <button id="orppx-player-stop" type="button">Stop</button>
        <div id="orppx-player-volume"><input id="orppx-volume" type="range" min="0" max="1" step="0.01" value="0.8"></div>
    </div>
    <div id="orppx-player-services">
        <button type="button" data-service="spotify">Spotify</button>
        <button type="button" data-service="ytmusic">YouTube Music</button>
        <button type="button" data-service="lastfm">Last.fm</button>
    </div>
    <div id="orppx-player-notice" role="status" aria-live="polite" hidden></div>
    <audio id="orppx-audio" preload="none"></audio>
</div>

<?php $year = (int)date('Y'); ?>
<footer id="orppx-footer">
    <div class="footer-inner">
        <div><strong>D. Linders © <?= $year ?></strong></div>
        <div class="footer-pill">Total radio stations: <strong id="orppx-stations-total"><?= (int)$footerStationsTotal ?></strong></div>
    </div>
</footer>

<script>
(function () {
    const SITE_URL = <?= json_encode(site_url(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    let isLoggedIn = <?= $u ? 'true' : 'false' ?>;
    const favoriteIds = new Set(<?= json_encode(array_values(array_map('intval', $favIds))) ?>);
    const favoritePending = new Set();
    const favoriteStateVersions = new Map();
    const removedStationIds = new Set();
    const hiddenStationIds = new Set();
    const pendingFailureReports = new Map();
    const failureRequests = new Set();
    let activePlaybackAttempt = null;
    const PLAYBACK_START_TIMEOUT_MS = <?= (int)PLAYBACK_START_TIMEOUT_MS ?>;
    const PLAYBACK_SUCCESS_SECONDS = <?= (int)PLAYBACK_SUCCESS_SECONDS ?>;
    try {
        const stored = JSON.parse(sessionStorage.getItem('orppx-pending-failures') || '[]');
        if (Array.isArray(stored)) stored.slice(-16).forEach(entry => {
            if (entry && Number.isInteger(entry.sid) && entry.sid > 0 && Date.now()-entry.at < 1800000) {
                pendingFailureReports.set(entry.sid,entry); hiddenStationIds.add(entry.sid);
            }
        });
    } catch(e) {}
    let playbackGeneration = 0, errorReportGeneration = -1, playbackWatchdog = null;
    const playerFavBtn = document.getElementById('orppx-player-favorite');
    const noticeEl = document.getElementById('orppx-player-notice');

    const contentEl = document.getElementById('orppx-content');
    const playerEl = document.getElementById('orppx-player');
    const audioEl  = document.getElementById('orppx-audio');
    const titleEl  = document.getElementById('orppx-player-title');
    const statusEl = document.getElementById('orppx-player-status');
    const trackEl  = document.getElementById('orppx-player-track');
    const playBtn  = document.getElementById('orppx-player-play');
    const stopBtn  = document.getElementById('orppx-player-stop');
    const volRange = document.getElementById('orppx-volume');

    let currentStationId=null, currentStationName=null, currentStationUrl=null;
    let currentTrackTitle='';
    let nowPlayingTimer=null;
    let nowPlayingExpiryTimer=null;
    let nowPlayingAbort=null;
    let nowPlayingBusy=false;
    let nowPlayingGeneration=0;
    let nowPlayingLastSuccessAt=0;
    let nowPlayingFailures=0;
    let manuallyStopped=false;

    const NOWPLAYING_POLL_MS = 15000;
    const NOWPLAYING_BROWSER_TIMEOUT_MS = 12000;
    const NOWPLAYING_STALE_MS = 90000;

    function normalizeUrl(raw) {
        raw = (raw || '').trim();
        if (!raw) return '';
        try {
            const resolved = new URL(raw.startsWith('//') ? window.location.protocol + raw : (/^https?:\/\//i.test(raw) ? raw : 'https://' + raw));
            if (resolved.hostname === new URL(SITE_URL).hostname) return '';
        } catch (e) { return ''; }
        if (/^https?:\/\//i.test(raw)) return raw;
        if (raw.startsWith('//')) return window.location.protocol + raw;
        return 'https://' + raw;
    }
    function buildStreamUrl(rawUrl){ return normalizeUrl(rawUrl); }

    // One browser-native animation, only when the current track is too wide.
    // No per-frame JavaScript loop and no extra server/network requests.
    function createTrackScroller(viewport, textNode) {
        if (!viewport || !textNode) return { set: function() {} };
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        let animation = null, frame = null, signature = '';
        let live = false, expanded = false, overflowing = false;

        function syncPlayback() {
            if (!animation) return;
            const paused = document.hidden || audioEl.paused || audioEl.ended
                || viewport.matches(':hover') || document.activeElement === viewport;
            if (paused) animation.pause(); else animation.play();
        }
        function measure() {
            frame = null;
            // Measure the unwrapped, intrinsic width even when the user has expanded it.
            viewport.classList.remove('expanded');
            const available = viewport.clientWidth;
            const distance = Math.max(0, Math.ceil(textNode.scrollWidth - available));
            overflowing = live && available > 0 && distance > 1;
            const wrap = overflowing && (expanded || reducedMotion.matches || !textNode.animate);
            viewport.classList.toggle('expanded', wrap);
            viewport.classList.toggle('overflows', overflowing);
            if (overflowing && !reducedMotion.matches && textNode.animate) {
                viewport.setAttribute('role', 'button');
                viewport.setAttribute('tabindex', '0');
                viewport.setAttribute('aria-expanded', String(wrap));
                viewport.setAttribute('aria-label', textNode.textContent + (wrap ? '. Collapse full track title' : '. Show full track title'));
            } else {
                ['role', 'tabindex', 'aria-expanded', 'aria-label'].forEach(name => viewport.removeAttribute(name));
            }
            const nextSignature = [textNode.textContent, available, distance, live, wrap, reducedMotion.matches].join('|');
            if (signature !== nextSignature) {
                signature = nextSignature;
                if (animation) { animation.cancel(); animation = null; }
                if (overflowing && !wrap && textNode.animate) {
                    const hold = 1600;
                    const duration = Math.max(1000, distance / 28 * 1000) + hold * 2;
                    // Readable pause at BOTH ends, then smoothly back and forth.
                    animation = textNode.animate([
                        { transform: 'translateX(0)', offset: 0 },
                        { transform: 'translateX(0)', offset: hold / duration },
                        { transform: 'translateX(-' + distance + 'px)', offset: 1 - hold / duration },
                        { transform: 'translateX(-' + distance + 'px)', offset: 1 }
                    ], { duration, iterations: Infinity, direction: 'alternate', easing: 'linear', fill: 'both' });
                }
            }
            syncPlayback();
        }
        function scheduleMeasure() {
            if (frame === null) frame = requestAnimationFrame(measure);
        }
        function toggleExpanded() {
            if (!overflowing || reducedMotion.matches) return;
            expanded = !expanded;
            scheduleMeasure();
        }
        viewport.addEventListener('click', toggleExpanded);
        viewport.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault(); toggleExpanded();
            }
        });
        ['mouseenter', 'mouseleave', 'focus', 'blur'].forEach(name => viewport.addEventListener(name, syncPlayback));
        ['playing', 'pause', 'ended', 'error'].forEach(name => audioEl.addEventListener(name, syncPlayback));
        document.addEventListener('visibilitychange', () => { syncPlayback(); if (!document.hidden) scheduleMeasure(); });
        window.addEventListener('resize', scheduleMeasure);
        if (reducedMotion.addEventListener) reducedMotion.addEventListener('change', scheduleMeasure);
        else if (reducedMotion.addListener) reducedMotion.addListener(scheduleMeasure);
        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(scheduleMeasure);
            observer.observe(viewport);
            observer.observe(textNode);
        }
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(scheduleMeasure).catch(() => {});
        scheduleMeasure();
        return {
            set(text, isLive) {
                if (textNode.textContent !== text) {
                    textNode.textContent = text; expanded = false;
                    // Immediately discard any animation of the previous track.
                    if (animation) { animation.cancel(); animation = null; }
                    signature = '';
                }
                live = !!isLive;
                viewport.title = text;
                scheduleMeasure();
            }
        };
    }
    const trackScroller = createTrackScroller(trackEl, document.getElementById('orppx-player-track-text'));

    function setNowPlayingText(text, isLive) {
        const clean = String(text || '').trim();
        currentTrackTitle = isLive && clean ? clean : '';
        if (!trackEl) return;
        const display = clean ? ('Now playing: ' + clean) : 'Now playing: waiting for metadata...';
        trackScroller.set(display, !!isLive && !!clean);
        trackEl.classList.toggle('empty', !clean || !isLive);
    }

    function serviceSearchTerm() {
        // Never use a visually truncated string or an expired cached title for searches.
        expireNowPlayingTitle();
        return (currentTrackTitle || '').trim();
    }

    function expireNowPlayingTitle() {
        if (currentTrackTitle && nowPlayingLastSuccessAt && Date.now() - nowPlayingLastSuccessAt >= NOWPLAYING_STALE_MS) {
            setNowPlayingText('', false);
        }
    }
    function armNowPlayingExpiry() {
        if (nowPlayingExpiryTimer) clearTimeout(nowPlayingExpiryTimer);
        nowPlayingExpiryTimer = null;
        if (!currentTrackTitle || !nowPlayingLastSuccessAt) return;
        const remaining = NOWPLAYING_STALE_MS - (Date.now() - nowPlayingLastSuccessAt);
        if (remaining <= 0) { expireNowPlayingTitle(); return; }
        nowPlayingExpiryTimer = setTimeout(() => {
            nowPlayingExpiryTimer = null;
            expireNowPlayingTitle();
        }, remaining + 20);
    }
    function stopNowPlayingPolling() {
        nowPlayingGeneration++;
        if (nowPlayingTimer) { clearTimeout(nowPlayingTimer); nowPlayingTimer = null; }
        if (nowPlayingExpiryTimer) { clearTimeout(nowPlayingExpiryTimer); nowPlayingExpiryTimer = null; }
        if (nowPlayingAbort) { try { nowPlayingAbort.abort(); } catch(e){} nowPlayingAbort = null; }
        nowPlayingBusy = false;
    }

    function scheduleNowPlayingPoll(stationId, generation, delayMs) {
        if (stationId !== currentStationId || generation !== nowPlayingGeneration || manuallyStopped) return;
        if (nowPlayingTimer) clearTimeout(nowPlayingTimer);
        nowPlayingTimer = setTimeout(() => {
            nowPlayingTimer = null;
            fetchNowPlaying(stationId, generation);
        }, Math.max(1000, delayMs || NOWPLAYING_POLL_MS));
    }

    function fetchNowPlaying(stationId, generation) {
        stationId = stationId || currentStationId;
        generation = (typeof generation === 'number') ? generation : nowPlayingGeneration;
        if (!stationId || stationId !== currentStationId || generation !== nowPlayingGeneration || manuallyStopped) return Promise.resolve();
        if (nowPlayingBusy) {
            scheduleNowPlayingPoll(stationId, generation, 2000);
            return Promise.resolve();
        }
        nowPlayingBusy = true;
        let nextDelay = NOWPLAYING_POLL_MS;
        const controller = ('AbortController' in window) ? new AbortController() : null;
        nowPlayingAbort = controller;
        const timeoutId = controller ? setTimeout(() => controller.abort(), NOWPLAYING_BROWSER_TIMEOUT_MS) : null;

        return fetch(SITE_URL + '/?ajax=now_playing&station_id=' + encodeURIComponent(String(stationId)), {
            credentials: 'same-origin', cache: 'no-store',
            signal: controller ? controller.signal : undefined
        })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (stationId !== currentStationId || generation !== nowPlayingGeneration) return;
            if (data && data.refreshing) {
                nextDelay = 2000; // another visitor's worker is already fetching this station
                expireNowPlayingTitle();
                return;
            }
            const title = data && data.ok ? String(data.title || '').trim() : '';
            const age = Math.max(0, Number(data && data.age_seconds) || 0) * 1000;
            if (title && age < NOWPLAYING_STALE_MS) {
                // A shared cached response is not a brand-new metadata observation.
                nowPlayingLastSuccessAt = Date.now() - age;
                nowPlayingFailures = 0;
                if (title !== currentTrackTitle) setNowPlayingText(title, true);
                armNowPlayingExpiry();
            } else {
                nowPlayingFailures++;
                const retry = Math.max(1, Number(data && data.retry_after) || 45);
                nextDelay = Math.max(15000, Math.min(60000, retry * 1000));
                expireNowPlayingTitle();
                if (!currentTrackTitle) setNowPlayingText('', false);
            }
        })
        .catch(err => {
            if (stationId !== currentStationId || generation !== nowPlayingGeneration) return;
            if (!(err && err.name === 'AbortError')) console.debug('Now Playing lookup failed:', err);
            nowPlayingFailures++;
            nextDelay = Math.min(60000, NOWPLAYING_POLL_MS * Math.pow(2, Math.min(nowPlayingFailures, 2)));
            expireNowPlayingTitle();
            if (!currentTrackTitle) setNowPlayingText('', false);
        })
        .finally(() => {
            if (timeoutId) clearTimeout(timeoutId);
            if (generation === nowPlayingGeneration && nowPlayingAbort === controller) { nowPlayingAbort = null; nowPlayingBusy = false; }
            if (stationId === currentStationId && generation === nowPlayingGeneration && !manuallyStopped) {
                if (document.hidden) nextDelay = Math.max(45000, nextDelay);
                // Avoid synchronising visitors at exactly the same cache boundary.
                scheduleNowPlayingPoll(stationId, generation, nextDelay + Math.floor(Math.random() * 1000));
            }
        });
    }

    function startNowPlayingPolling() {
        stopNowPlayingPolling();
        nowPlayingLastSuccessAt = 0;
        nowPlayingFailures = 0;
        fetchNowPlaying(currentStationId, nowPlayingGeneration);
    }
    function refreshVisibleNowPlaying() {
        expireNowPlayingTitle();
        if (!document.hidden && currentStationId && !manuallyStopped && !audioEl.paused && !nowPlayingBusy) {
            scheduleNowPlayingPoll(currentStationId, nowPlayingGeneration, 1000);
        }
    }
    document.addEventListener('visibilitychange', refreshVisibleNowPlaying);
    window.addEventListener('online', refreshVisibleNowPlaying);

    function showPlayerNotice(message) {
        if (!noticeEl) return;
        noticeEl.textContent = message || '';
        noticeEl.hidden = !message;
    }
    function updatePlayerFavorite() {
        if (!playerFavBtn) return;
        const on = !!currentStationId && favoriteIds.has(currentStationId);
        const pending = !!currentStationId && favoritePending.has(currentStationId);
        playerFavBtn.classList.toggle('on', on);
        playerFavBtn.disabled = !currentStationId || pending;
        playerFavBtn.setAttribute('aria-pressed', String(on));
        const label = !currentStationId ? 'Choose a station first' : !isLoggedIn ? 'Log in to use favorites'
            : on ? 'Remove current station from favorites' : 'Add current station to favorites';
        playerFavBtn.title = label;
        playerFavBtn.setAttribute('aria-label', label);
    }
    function syncFavoriteUi(sid, on) {
        if (on) favoriteIds.add(sid); else favoriteIds.delete(sid);
        document.querySelectorAll('.station-card[data-id="' + sid + '"] .fav-star').forEach(btn => {
            btn.classList.toggle('on', on);
            btn.setAttribute('aria-pressed', String(on));
            btn.disabled = favoritePending.has(sid);
            btn.title = !isLoggedIn ? 'Log in to use favorites' : on ? 'Remove from favorites' : 'Add to favorites';
        });
        updatePlayerFavorite();
    }
    function setStationTotal(total) {
        const el = document.getElementById('orppx-stations-total');
        if (el && Number.isFinite(Number(total))) el.textContent = String(Math.max(0, Number(total)));
    }
    function clearPlaybackWatchdog() {
        if (playbackWatchdog) clearTimeout(playbackWatchdog);
        playbackWatchdog = null;
    }
    function cancelPlaybackAttempt() {
        playbackGeneration++;
        activePlaybackAttempt = null;
        clearPlaybackWatchdog();
    }
    function removeStationFromUi(sid, total) {
        const firstRemoval = !removedStationIds.has(sid) && !hiddenStationIds.has(sid);
        removedStationIds.add(sid);
        hiddenStationIds.delete(sid);
        pendingFailureReports.delete(sid); saveFailureQueue();
        favoriteIds.delete(sid);
        document.querySelectorAll('.station-card[data-id="' + sid + '"]').forEach(card => card.remove());
        if (typeof total === 'number') setStationTotal(total);
        else if (firstRemoval) {
            const countEl = document.getElementById('orppx-stations-total');
            if (countEl) setStationTotal((Number(countEl.textContent) || 0) - 1);
        }
        if (sid === currentStationId) {
            cancelPlaybackAttempt();
            manuallyStopped = true;
            stopNowPlayingPolling();
            try { audioEl.pause(); audioEl.removeAttribute('src'); audioEl.load(); } catch(e) {}
            currentStationId = null; currentStationName = null; currentStationUrl = null;
            setNowPlayingText('', false);
            trackScroller.set('Now playing: choose a station...', false);
            titleEl.textContent = 'Choose a station...';
            statusEl.textContent = 'Removed';
        }
        updatePlayerFavorite();
    }
    async function fetchFavoriteState(sid) {
        if (!sid || favoritePending.has(sid)) return;
        const version = (favoriteStateVersions.get(sid) || 0) + 1;
        favoriteStateVersions.set(sid, version);
        try {
            const r = await fetch(SITE_URL + '/?ajax=player_favorite&station_id=' + encodeURIComponent(sid), {
                credentials:'same-origin', cache:'no-store'
            });
            if (!r.ok) return;
            const data = await r.json();
            if (!data.ok || favoriteStateVersions.get(sid) !== version) return;
            isLoggedIn = !!data.logged_in;
            if (!data.exists) {
                const wasCurrent = currentStationId === sid;
                removeStationFromUi(sid);
                if (wasCurrent) showPlayerNotice('Sorry, this radio station has already been removed.');
            } else if (!favoritePending.has(sid)) syncFavoriteUi(sid, !!data.favorite);
        } catch(e) { /* A failed state lookup never changes a favorite or removes a station. */ }
    }
    async function toggleStationFavorite(sid) {
        if (!sid || removedStationIds.has(sid)) return;
        if (!isLoggedIn) { alert('Log in to add radio stations to your favorites.'); return; }
        if (favoritePending.has(sid)) return;
        const desired = !favoriteIds.has(sid);
        favoritePending.add(sid);
        favoriteStateVersions.set(sid, (favoriteStateVersions.get(sid) || 0) + 1);
        syncFavoriteUi(sid, favoriteIds.has(sid));
        try {
            const fd = new FormData();
            fd.append('csrf_token', CSRF_TOKEN); fd.append('station_id', String(sid));
            fd.append('favorite', desired ? '1' : '0');
            const r = await fetch(SITE_URL + '/?ajax=toggle_fav', {method:'POST', body:fd, credentials:'same-origin', cache:'no-store'});
            const data = await r.json();
            if (!r.ok || !data.ok) {
                if (data.error === 'login_required') { isLoggedIn = false; alert('Log in to use favorites.'); }
                else if (r.status === 404) { removeStationFromUi(sid); showPlayerNotice('This radio station has already been removed.'); }
                else throw new Error(data.error || 'Could not save favorite. Please try again.');
                return;
            }
            syncFavoriteUi(sid, !!data.favorite);
            if (!data.favorite && (new URL(window.location.href)).searchParams.get('page') === 'favorites') {
                document.querySelectorAll('.station-card[data-id="' + sid + '"]').forEach(card => card.remove());
            }
        } catch(e) { showPlayerNotice(e.message || 'Could not save favorite. Please try again.'); }
        finally { favoritePending.delete(sid); syncFavoriteUi(sid, favoriteIds.has(sid)); }
    }
    // A hidden station keeps its favorites and can still be retried in the player.
    function hideStationFromUi(sid, total) {
        const firstHide = !hiddenStationIds.has(sid) && !removedStationIds.has(sid);
        hiddenStationIds.add(sid);
        document.querySelectorAll('.station-card[data-id="' + sid + '"]').forEach(card => card.remove());
        if (typeof total === 'number') setStationTotal(total);
        else if (firstHide) {
            const el = document.getElementById('orppx-stations-total');
            if (el) setStationTotal((Number(el.textContent) || 0) - 1);
        }
    }
    function restoreStationInUi(sid, total) {
        hiddenStationIds.delete(sid);
        if (typeof total === 'number') setStationTotal(total);
        // Reload only the list, not the audio element, so recovered stations reappear.
        ajaxNavigate(window.location.search || '?page=home', false);
    }
    async function playbackPost(action, values) {
        const fd = new FormData(); fd.append('csrf_token', CSRF_TOKEN);
        Object.entries(values || {}).forEach(([key,value]) => fd.append(key,String(value)));
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timer = controller ? setTimeout(() => controller.abort(), 12000) : null;
        try {
            const r = await fetch(SITE_URL + '/?ajax=' + action, {method:'POST', body:fd,
                credentials:'same-origin', cache:'no-store', signal:controller ? controller.signal : undefined});
            const data = await r.json();
            if (!r.ok || !data.ok) throw new Error(data.error || 'Could not save the playback result.');
            return data;
        } finally { if (timer) clearTimeout(timer); }
    }
    function startPlaybackTicket(sid, generation) {
        const attempt = {sid, generation, startedAt:Date.now(), progressStart:null, wallStart:0, successSent:false,
            successRetryAfter:0, token:null};
        // Do not await a request before audio.play(): preserve the visitor's click gesture.
        attempt.promise = playbackPost('playback_begin', {station_id:sid}).then(data => {
            if (data.missing) {
                const same = currentStationId === sid && generation === playbackGeneration;
                removeStationFromUi(sid);
                if (same) showPlayerNotice('This radio station has already been removed.');
                return null;
            }
            attempt.token = data.attempt;
            return data.attempt;
        }).catch(() => null); // audio may still play while the website is temporarily unreachable
        return attempt;
    }
    function saveFailureQueue() {
        try { sessionStorage.setItem('orppx-pending-failures', JSON.stringify(Array.from(pendingFailureReports.values()).slice(-16))); } catch(e) {}
    }
    async function sendFailure(entry) {
        if (failureRequests.has(entry.sid)) return;
        failureRequests.add(entry.sid);
        try {
            if (!entry.token) {
                const begin = await playbackPost('playback_begin', {station_id:entry.sid});
                if (begin.missing) {
                    removeStationFromUi(entry.sid); pendingFailureReports.delete(entry.sid); return;
                }
                entry.token = begin.attempt; saveFailureQueue();
            }
            if (pendingFailureReports.get(entry.sid) !== entry) return; // superseded by a newer attempt
            const data = await playbackPost('report_stream_error', {station_id:entry.sid, attempt:entry.token, reason:entry.reason});
            pendingFailureReports.delete(entry.sid);
            const same = entry.sid === currentStationId && entry.generation === playbackGeneration;
            if (data.deleted || data.missing) {
                removeStationFromUi(entry.sid, data.stations_total);
            } else if (data.hidden) {
                hideStationFromUi(entry.sid, data.stations_total);
                if (same) statusEl.textContent = 'Possibly defective';
            } else if (data.stale) {
                hiddenStationIds.delete(entry.sid);
                if (typeof data.stations_total === 'number') setStationTotal(data.stations_total);
                // A newer test already restored it; this older failure must not hide it forever locally.
                ajaxNavigate(window.location.search || '?page=home', false);
            }
            if (same) showPlayerNotice(data.message || 'Playback result saved.');
        } catch(e) {
            if (entry.sid === currentStationId && entry.generation === playbackGeneration) {
                showPlayerNotice('Sorry, this radio could not be played. It is hidden on this page, but the result could not yet be saved. It will be retried when the website is reachable.');
            }
        } finally { failureRequests.delete(entry.sid); saveFailureQueue(); }
    }
    function flushFailureQueue() {
        if (navigator.onLine === false) return;
        for (const entry of pendingFailureReports.values()) {
            if (Date.now() - entry.at > 1800000) { pendingFailureReports.delete(entry.sid); continue; }
            sendFailure(entry);
        }
        saveFailureQueue();
    }
    async function reportCurrentStreamError(generation, reason = 'No playback progress within the timeout') {
        if (generation !== playbackGeneration || manuallyStopped || !currentStationId || errorReportGeneration === generation) return;
        errorReportGeneration = generation;
        clearPlaybackWatchdog(); stopNowPlayingPolling();
        const sid = currentStationId;
        const attempt = activePlaybackAttempt;
        manuallyStopped = true; // prevents our own pause/load cleanup generating another failure
        hideStationFromUi(sid);
        statusEl.textContent = 'Possibly defective';
        showPlayerNotice('Sorry, this radio does not seem to work. It is hidden while the failed playback is recorded.');
        try { audioEl.pause(); audioEl.removeAttribute('src'); audioEl.load(); } catch(e) {}
        const entry = {sid, generation, reason:String(reason).slice(0,240), token:null, at:Date.now()};
        pendingFailureReports.set(sid, entry); saveFailureQueue();
        if (attempt && attempt.sid === sid) entry.token = await attempt.promise;
        saveFailureQueue();
        await sendFailure(entry);
        if (pendingFailureReports.has(sid)) setTimeout(flushFailureQueue, 15000);
    }
    function armPlaybackWatchdog(generation) {
        if (playbackWatchdog) return; // repeated waiting/stalled events must not restart the deadline
        const progress = audioEl.currentTime;
        playbackWatchdog = setTimeout(() => {
            playbackWatchdog = null;
            if (generation !== playbackGeneration || manuallyStopped || !currentStationId) return;
            if (!audioEl.error && audioEl.currentTime > progress + 0.5) return;
            reportCurrentStreamError(generation);
        }, PLAYBACK_START_TIMEOUT_MS);
    }
    async function confirmPlaybackProgress() {
        const attempt = activePlaybackAttempt;
        if (!attempt || attempt.generation !== playbackGeneration || manuallyStopped || audioEl.paused
            || audioEl.error || audioEl.readyState < 2 || attempt.successSent || Date.now() < attempt.successRetryAfter) return;
        const position = audioEl.currentTime;
        if (!Number.isFinite(position)) return;
        if (attempt.progressStart === null || position < attempt.progressStart) {
            attempt.progressStart = position; attempt.wallStart = Date.now(); return;
        }
        if (position - attempt.progressStart < PLAYBACK_SUCCESS_SECONDS || Date.now() - attempt.wallStart < PLAYBACK_SUCCESS_SECONDS * 1000) return;
        attempt.successSent = true;
        try {
            const token = await attempt.promise;
            if (!token || attempt.generation !== playbackGeneration || manuallyStopped) return;
            const pending = pendingFailureReports.get(attempt.sid);
            if (pending && pending.at < attempt.startedAt) { pendingFailureReports.delete(attempt.sid); saveFailureQueue(); }
            const data = await playbackPost('report_stream_success', {station_id:attempt.sid, attempt:token});
            if (attempt.generation !== playbackGeneration) return;
            if (data.missing || data.deleted) {
                removeStationFromUi(attempt.sid, data.stations_total);
                showPlayerNotice(data.message || 'This radio station has been removed.');
            } else if (data.restored || (!data.hidden && hiddenStationIds.has(attempt.sid))) {
                restoreStationInUi(attempt.sid, data.stations_total);
                showPlayerNotice(data.message);
            } else if (data.pending) {
                attempt.successSent = false; attempt.successRetryAfter = Date.now() + 2000;
            } else if (data.stale && data.hidden) {
                // Reconfirm continuing real playback against the NEW health revision.
                // No second audio stream is opened; only a fresh result ticket is issued.
                activePlaybackAttempt = startPlaybackTicket(attempt.sid, attempt.generation);
                showPlayerNotice('Playback is working. Confirming a fresh test to clear the hidden status...');
            }
        } catch(e) {
            attempt.successSent = false; attempt.successRetryAfter = Date.now() + 10000;
        }
    }
    function handlePlaybackRejection(err, generation) {
        if (generation !== playbackGeneration || manuallyStopped) return;
        // A deliberate Stop/source change is not an unsuccessful attempt to play a station.
        if (err && err.name === 'AbortError') { clearPlaybackWatchdog(); stopNowPlayingPolling(); return; }
        // By request, browser permission/format failures also quarantine the station.
        reportCurrentStreamError(generation, err && err.name ? err.name : 'Playback rejected');
    }


    function setPlayerStation(stationId, name, url, autoPlay) {
        if (removedStationIds.has(stationId)) { showPlayerNotice('This radio station has been removed.'); return; }
        cancelPlaybackAttempt();
        stopNowPlayingPolling();
        currentStationId = stationId; currentStationName = name; currentStationUrl = url; manuallyStopped = false;
        const generation = playbackGeneration;
        activePlaybackAttempt = autoPlay ? startPlaybackTicket(stationId, generation) : null;
        currentTrackTitle = '';
        titleEl.textContent = name;
        statusEl.textContent = 'Buffering...';
        showPlayerNotice('');
        updatePlayerFavorite();
        fetchFavoriteState(stationId);
        setNowPlayingText('Searching metadata...', false);
        try { audioEl.pause(); audioEl.removeAttribute('src'); audioEl.load(); } catch(e) {}
        const baseUrl = buildStreamUrl(url);
        if (!baseUrl) { reportCurrentStreamError(generation, 'Invalid stream URL'); return; }
        // Preserve signed/query-sensitive stream URLs; do not append an arbitrary cache-buster.
        audioEl.src = baseUrl;
        audioEl.load();
        if (autoPlay) {
            startNowPlayingPolling();
            armPlaybackWatchdog(generation);
            try { Promise.resolve(audioEl.play()).catch(err => handlePlaybackRejection(err, generation)); }
            catch(err) { handlePlaybackRejection(err, generation); }
        }
    }

    function trackPlay(source, stationId) {
        const sid = stationId || currentStationId;
        if (!sid) return;
        if (source !== 'play' && source !== 'open_url') return;
        const fd = new FormData();
        fd.append('station_id', String(sid));
        fd.append('source', source);
        fetch(SITE_URL + '/?ajax=track_play', { method:'POST', body:fd, credentials:'same-origin' }).catch(()=>{});
    }

    // draggable
    (function(){
        let offsetX=0, offsetY=0, dragging=false;
        playerEl.addEventListener('mousedown', function(e){
            if (e.target.closest('button,input,a,select,textarea,#orppx-player-notice,#orppx-player-track') || e.target===audioEl) return;
            dragging=true;
            offsetX = e.clientX - playerEl.offsetLeft;
            offsetY = e.clientY - playerEl.offsetTop;
        });
        window.addEventListener('mousemove', function(e){
            if (!dragging) return;
            playerEl.style.left = (e.clientX - offsetX) + 'px';
            playerEl.style.top  = (e.clientY - offsetY) + 'px';
        });
        window.addEventListener('mouseup', ()=>dragging=false);
    })();

    if (volRange) {
        let savedVol = 0.8;
        try { savedVol = parseFloat(localStorage.getItem('orppx-volume') || '0.8'); } catch(e) {}
        audioEl.volume = isNaN(savedVol) ? 0.8 : Math.max(0, Math.min(1, savedVol));
        volRange.value = audioEl.volume.toString();
        volRange.addEventListener('input', function(){
            const v=parseFloat(this.value);
            audioEl.volume=v;
            try { localStorage.setItem('orppx-volume', String(v)); } catch(e) {}
        });
    }

    function initStationCards(scope) {
        (scope || document).querySelectorAll('.station-card').forEach(card => {
            const stationId = parseInt(card.dataset.id, 10);
            if (removedStationIds.has(stationId) || (pendingFailureReports.has(stationId) && card.dataset.retest !== '1')) { card.remove(); return; }
            if (card.dataset.retest !== '1' && !pendingFailureReports.has(stationId)) hiddenStationIds.delete(stationId);
            const name = card.dataset.name;
            const url = card.dataset.url;
            const playBtnCard = card.querySelector('.btn-play');
            const openBtnCard = card.querySelector('.btn-open');
            const favBtn = card.querySelector('.fav-star');
            if (favBtn && !favoritePending.has(stationId)) {
                if (favBtn.classList.contains('on')) favoriteIds.add(stationId); else favoriteIds.delete(stationId);
            }
            syncFavoriteUi(stationId, favoriteIds.has(stationId));
            if (card.dataset.orppxBound === '1') return;
            card.dataset.orppxBound = '1';
            if (playBtnCard && card.dataset.retest === '1') playBtnCard.textContent = 'Test stream';
            if (playBtnCard) playBtnCard.addEventListener('click', () => {
                setPlayerStation(stationId, name, url, true); trackPlay('play', stationId);
            });
            if (openBtnCard) openBtnCard.addEventListener('click', () => {
                const directUrl = buildStreamUrl(url);
                if (!directUrl) return alert('No valid direct stream URL known for this station.');
                trackPlay('open_url', stationId);
                window.open(directUrl, '_blank', 'noopener,noreferrer');
            });
            if (favBtn) favBtn.addEventListener('click', () => toggleStationFavorite(stationId));
        });
        updatePlayerFavorite();
    }
    initStationCards();
    playerFavBtn.addEventListener('click', () => toggleStationFavorite(currentStationId));
    window.addEventListener('focus', () => { fetchFavoriteState(currentStationId); flushFailureQueue(); });
    window.addEventListener('online', flushFailureQueue);
    setTimeout(flushFailureQueue, 500);

    playBtn.addEventListener('click', () => {
        if (!currentStationId || !currentStationUrl) { statusEl.textContent = 'Choose a station first'; return; }
        setPlayerStation(currentStationId, currentStationName, currentStationUrl, true);
    });
    stopBtn.addEventListener('click', () => {
        manuallyStopped = true; cancelPlaybackAttempt(); stopNowPlayingPolling();
        try { audioEl.pause(); audioEl.removeAttribute('src'); audioEl.load(); } catch(e) {}
        statusEl.textContent = 'Paused'; showPlayerNotice('');
    });
    audioEl.addEventListener('playing', () => {
        if (manuallyStopped || !currentStationId) return;
        clearPlaybackWatchdog(); statusEl.textContent = 'Playing'; showPlayerNotice('');
        confirmPlaybackProgress();
        if (!nowPlayingTimer && !nowPlayingBusy) startNowPlayingPolling();
    });
    audioEl.addEventListener('error', () => {
        if (audioEl.error && audioEl.error.code !== 1) reportCurrentStreamError(playbackGeneration, 'MediaError ' + audioEl.error.code);
    });
    audioEl.addEventListener('waiting', () => { if (!manuallyStopped && currentStationId) armPlaybackWatchdog(playbackGeneration); });
    audioEl.addEventListener('stalled', () => { if (!manuallyStopped && currentStationId && !playbackWatchdog) armPlaybackWatchdog(playbackGeneration); });
    audioEl.addEventListener('ended', () => { if (!manuallyStopped) reportCurrentStreamError(playbackGeneration, 'Stream ended'); });
    audioEl.addEventListener('timeupdate', confirmPlaybackProgress);

    document.querySelectorAll('#orppx-player-services button').forEach(btn => {
        btn.addEventListener('click', () => {
            const term = serviceSearchTerm();
            if (!term) return alert('No current track metadata is available yet.');
            const svc = btn.dataset.service;
            let url = '';
            if (svc === 'spotify') url = 'https://open.spotify.com/search/' + encodeURIComponent(term);
            else if (svc === 'ytmusic') url = 'https://music.youtube.com/search?q=' + encodeURIComponent(term);
            else if (svc === 'lastfm') url = 'https://www.last.fm/search?q=' + encodeURIComponent(term);
            if (url) window.open(url, '_blank');
        });
    });

    // ajax navigation
    function ajaxNavigate(url, pushHistory = true) {
        const sep = url.includes('?') ? '&' : '?';
        const ajaxUrl = url + sep + 'ajax_page=1';
        return fetch(ajaxUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); if (r.redirected) { window.location.href = r.url; throw new Error('Page redirected'); } return r.text(); })
            .then(html => {
                contentEl.innerHTML = html;
                initStationCards(contentEl);
                initAdminBindings();

                document.querySelectorAll('.nav-link').forEach(a => a.classList.remove('active'));
                const u = new URL(url, window.location.href);
                const page = u.searchParams.get('page') || 'home';
                const nav = document.querySelector('.nav-link[href*="page='+page+'"]');
                if (nav) nav.classList.add('active');

                if (pushHistory) history.pushState({}, '', url);
                fetchFavoriteState(currentStationId);
            })
            .catch(()=>window.location.href=url);
    }
    document.addEventListener('click', function(e){
        const a = e.target.closest('a');
        if (!a) return;
        const href = a.getAttribute('href') || '';
        if (!href.startsWith('?page=')) return;
        if (['login','register','forgot','reset','logout','account'].includes(new URL(href, window.location.href).searchParams.get('page'))) return;
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
        e.preventDefault();
        ajaxNavigate(href);
    });
    window.addEventListener('popstate', ()=>ajaxNavigate(window.location.search || '?page=home', false));
    const searchForm = document.getElementById('nav-search-form');
    if (searchForm) searchForm.addEventListener('submit', function(e){
        e.preventDefault();
        const fd = new FormData(searchForm);
        ajaxNavigate('?' + new URLSearchParams(fd).toString());
    });

    /* =========================
       Dead scan: server job loop
       ========================= */
    let deadScanLoopRunning = false;
    let deadScanStopRequested = false;
    let deadScanRestartRequested = false;

    function deadScanLog(msg){
        const el = document.getElementById('dead-scan-log');
        if (!el) return;
        el.textContent = (el.textContent + msg + "\n").slice(-40000);
        el.scrollTop = el.scrollHeight;
    }

    function deadScanSetUI(job){
        const prog = document.getElementById('dead-scan-progress');
        const label = document.getElementById('dead-scan-label');
        if (!prog || !label) return;

        if (!job) {
            prog.max = 100; prog.value = 0;
            label.textContent = 'Idle';
            return;
        }

        const total = parseInt(job.total || '0', 10) || 0;
        const processed = parseInt(job.processed || '0', 10) || 0;
        const dead = parseInt(job.dead_count || '0', 10) || 0;
        const alive = parseInt(job.alive_count || '0', 10) || 0;
        const status = job.status || 'paused';

        prog.max = Math.max(1, total);
        prog.value = Math.min(processed, prog.max);

        const pct = total ? Math.floor((processed/total)*100) : 0;
        label.textContent = `${status.toUpperCase()} — ${processed}/${total} processed (${pct}%) — working/kept: ${alive} — removed: ${dead}`;
        if (job.policy !== 'strict-v1') label.textContent += ' — old policy; Start begins a new strict scan';
    }

    async function deadScanFetchStatus(){
        const r = await fetch(SITE_URL + '/?ajax=deadscan_status', { credentials:'same-origin' });
        const d = await r.json();
        if (!d.ok) throw new Error(d.error || 'status failed');
        return d.job || null;
    }

    async function adminPost(action, values) {
        const fd = new FormData(); fd.append('csrf_token', CSRF_TOKEN);
        Object.entries(values || {}).forEach(([key, value]) => fd.append(key, String(value)));
        const r = await fetch(SITE_URL + '/?ajax=' + encodeURIComponent(action), {
            method:'POST', body:fd, credentials:'same-origin', cache:'no-store'
        });
        const d = await r.json();
        if (!r.ok || !d.ok) throw new Error(d.error || 'Admin action failed.');
        return d;
    }
    async function deadScanStart() {
        const sel = document.getElementById('dead-scan-batch');
        let batch = sel ? parseInt(sel.value || '20', 10) : 20;
        if (isNaN(batch) || batch < 1) batch = 20;
        try { localStorage.setItem('orppx-deadscan-batch', String(batch)); } catch(e) {}
        const d = await adminPost('deadscan_start', {batch, strict_confirm:1});
        return d.job || null;
    }
    async function deadScanPause() {
        const d = await adminPost('deadscan_pause'); return d.job || null;
    }
    async function deadScanReset() {
        await adminPost('deadscan_reset'); return true;
    }
    async function deadScanStep() { return adminPost('deadscan_step'); }

    async function deadScanLoop() {
        if (deadScanLoopRunning) return;
        deadScanLoopRunning = true; deadScanStopRequested = false;
        try {
            while (!deadScanStopRequested) {
                const res = await deadScanStep();
                deadScanSetUI(res);
                (res.removed_ids || []).forEach(sid => {
                    const wasCurrent = currentStationId === sid;
                    removeStationFromUi(sid, res.stations_total);
                    if (wasCurrent) showPlayerNotice('This radio station did not pass the strict audio check and was removed.');
                });
                if (typeof res.stations_total === 'number') setStationTotal(res.stations_total);
                if (res.step) deadScanLog(`Step: ${res.step.processed_now} processed | ${res.step.alive_now} working/kept | ${res.step.dead_now} removed | last id ${res.step.last_station_id} | ${res.step.seconds}s`);
                (res.removed_details || []).forEach(item => deadScanLog(`#${item.id} removed: ${item.reason}`));
                (res.restored_ids || []).forEach(sid => { hiddenStationIds.delete(sid); deadScanLog(`#${sid} restored: direct audio received; failure status cleared.`); });
                if (res.error_msg) deadScanLog('ERROR: ' + res.error_msg);
                if (res.paused || (res.status && res.status !== 'running')) break;
                await new Promise(r => setTimeout(r, res.busy ? 1500 : 250));
            }
        } catch(e) { deadScanLog('ERROR: ' + (e.message || e)); }
        finally {
            deadScanLoopRunning = false;
            if (deadScanRestartRequested && !deadScanStopRequested) { deadScanRestartRequested = false; deadScanLoop(); }
        }
    }
    function applySavedBatch() {
        const sel = document.getElementById('dead-scan-batch');
        if (!sel) return;
        try {
            const saved = parseInt(localStorage.getItem('orppx-deadscan-batch') || '20', 10);
            if (!isNaN(saved)) sel.value = String(saved);
        } catch(e) {}
    }
    function initAdminBindings() {
        const box = document.getElementById('dead-scan-box');
        if (!box) return; // No unnecessary admin requests from public station pages.
        applySavedBatch();
        const bind = (id, work) => {
            const btn = document.getElementById(id);
            if (!btn || btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', async e => {
                e.preventDefault(); btn.disabled = true;
                try { await work(); } catch(err) { deadScanLog('ERROR: ' + (err.message || err)); }
                finally { btn.disabled = false; }
            });
        };
        bind('dead-scan-start', async () => {
            if (!confirm('STRICT SCAN: permanently delete EVERY station without a timely direct-audio response, including timeouts, DNS/TLS errors, blocked streams and playlists? Working stations can be lost during a hosting/network problem. Make a backup first.')) return;
            const job = await deadScanStart(); deadScanSetUI(job); deadScanLog('Started/resumed. Strict mode: only verified direct audio is kept. All other stream results are removed.');
            // A previous loop may still be returning from its final request after Pause.
            deadScanStopRequested = false;
            if (deadScanLoopRunning) deadScanRestartRequested = true; else deadScanLoop();
        });
        bind('dead-scan-pause', async () => {
            deadScanRestartRequested = false; deadScanStopRequested = true; const job = await deadScanPause(); deadScanSetUI(job); deadScanLog('Paused after the current check.');
        });
        bind('dead-scan-refresh', async () => {
            const job = await deadScanFetchStatus(); deadScanSetUI(job);
            if (job && job.status === 'running') { deadScanStopRequested = false; deadScanLoop(); }
        });
        bind('dead-scan-reset', async () => {
            if (!confirm('Reset the scan job? Removed radio stations will not be restored.')) return;
            deadScanRestartRequested = false; deadScanStopRequested = true; await deadScanReset(); deadScanSetUI(null); deadScanLog('Job reset.');
        });
        deadScanFetchStatus().then(job => {
            deadScanSetUI(job);
            if (job && job.status === 'running') deadScanLoop();
        }).catch(e => deadScanLog('ERROR: ' + (e.message || e)));
    }
    initAdminBindings();
    document.addEventListener('submit', e => {
        if (!e.target || e.target.id !== 'delete-all-stations-form') return;
        if (!confirm('Permanently delete ALL radio stations, their favorites and play history? Active imports will be cancelled. User accounts will be kept.')) {
            e.preventDefault(); return;
        }
        deadScanStopRequested = true;
    });

    /* =========================
       Root import + mail (existing)
       ========================= */
    function rootImportLog(msg){
        const logEl=document.getElementById('import-log');
        if (!logEl) return;
        logEl.textContent += msg + "\n";
        logEl.scrollTop = logEl.scrollHeight;
    }
    async function rootImportCreateJobsFromSelection(){
        const boxes = Array.from(document.querySelectorAll('.root-file'));
        const files = boxes.filter(b=>b.checked).map(b=>b.value);
        if (!files.length) { alert('Select at least one file.'); return []; }
        const fd=new FormData(); files.forEach(f=>fd.append('files[]', f));
        const res = await fetch(SITE_URL + '/?ajax=import_create_jobs', { method:'POST', body:fd, credentials:'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'create jobs failed');
        return data.created || [];
    }
    async function rootImportGetActiveJobs(){
        const res = await fetch(SITE_URL + '/?ajax=import_active_jobs', { credentials:'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'active jobs failed');
        return data.jobs || [];
    }
    async function rootImportStepJob(jobId){
        const res = await fetch(SITE_URL + '/?ajax=import_step&job_id=' + encodeURIComponent(jobId), { credentials:'same-origin' });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'step failed');
        return data;
    }
    async function rootImportRunJobsRoundRobin(){
        const progEl=document.getElementById('import-progress');
        const labelEl=document.getElementById('import-label');

        if (labelEl) labelEl.textContent='Running...';
        if (progEl) progEl.value=0;

        let jobs = await rootImportGetActiveJobs();
        if (!jobs.length) { if (labelEl) labelEl.textContent='No active jobs'; return; }

        const totalBytes = jobs.reduce((a,j)=>a+(j.file_size||0),0) || 1;

        while (true) {
            jobs = await rootImportGetActiveJobs();
            if (!jobs.length) break;

            const doneBytes = jobs.reduce((a,j)=>a+(j.byte_offset||0),0);
            if (progEl) { progEl.max=totalBytes; progEl.value=doneBytes; }
            if (labelEl) labelEl.textContent='Importing... ' + Math.floor((doneBytes/totalBytes)*100) + '%';

            for (const j of jobs) {
                try {
                    const r = await rootImportStepJob(j.id);
                    const jb = r.job || j;
                    rootImportLog(`Job #${jb.id} ${jb.filename}: +${r.step.imported} imported, +${r.step.skipped} skipped | offset ${jb.byte_offset}/${jb.file_size}`);
                } catch (e) {
                    rootImportLog('ERROR: ' + (e.message || e));
                }
            }
            await new Promise(r=>setTimeout(r,150));
        }

        if (labelEl) labelEl.textContent='Completed';
        rootImportLog('Completed.');
    }

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const id = btn.id || '';
        if (!id) return;

        if (id === 'btn-select-all') { document.querySelectorAll('.root-file').forEach(b=>b.checked=true); return; }
        if (id === 'btn-select-none') { document.querySelectorAll('.root-file').forEach(b=>b.checked=false); return; }

        if (id === 'btn-start-selected') {
            try {
                rootImportLog('Starting selected import...');
                await rootImportCreateJobsFromSelection();
                await rootImportRunJobsRoundRobin();
            } catch (err) {
                rootImportLog('ERROR: ' + (err.message || err));
                alert('Import error: ' + (err.message || err));
            }
            return;
        }

        if (id === 'btn-resume-all') {
            try {
                rootImportLog('Resuming all jobs...');
                await rootImportRunJobsRoundRobin();
            } catch (err2) {
                rootImportLog('ERROR: ' + (err2.message || err2));
                alert('Resume error: ' + (err2.message || err2));
            }
            return;
        }
    }, true);

})();
</script>
</body>
</html>
