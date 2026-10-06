<?php
// Live listening from outside the home network (through the reverse proxy).
//
//   POST {action:"grant"}            admin session -> one-use 15 s stream URL
//   GET  ?grant=<token>              the stream itself (audio/mpeg relay)
//   POST {action:"set", enabled}     the remote-listen switch
//   GET  ?status=1                   who is listening now, and the switch
//   GET  ?log=1                      recent remote listening sessions
//   GET  ?check=1                    loopback: is this admin session still valid
//
// The raw /stream stays LAN-only in Caddy. This relay only serves a visitor
// holding an unlocked admin session (behind the proxy's own login), only
// while the switch is on, for at most 30 minutes per connection, and every
// session is logged and announced through Apprise (see live-listen-store.php).

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/live-listen-store.php';

const LISTEN_GRANTS_KEY = 'live_listen_grants';
const LISTEN_CHECK_EVERY = 30;   // seconds between session re-checks mid-stream
const LISTEN_BEAT_EVERY = 5;     // seconds between session heartbeats

$tz = getenv('AVIAN_STATION_TIMEZONE');
if (is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) date_default_timezone_set($tz);

function listen_json(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body);
    exit;
}

/** One-use stream grant, bound to this admin session (like the Educators
 *  audio grant, but usable through the proxy). */
function listen_create_grant(array $server): ?string {
    $state = avian_admin_state();
    if (!avian_admin_session_valid($server, $state, false, true, true, false)) return null;
    $token = bin2hex(random_bytes(24));
    $now = time();
    $grants = $_SESSION[LISTEN_GRANTS_KEY] ?? [];
    if (!is_array($grants)) $grants = [];
    foreach ($grants as $k => $g) {
        if (!is_array($g) || (int)($g['expires'] ?? 0) < $now) unset($grants[$k]);
    }
    if (count($grants) >= 4) $grants = [];
    $grants[hash('sha256', $token)] = ['expires' => $now + 15];
    $_SESSION[LISTEN_GRANTS_KEY] = $grants;
    session_write_close();
    return $token;
}

/** Spend a grant; returns the admin session id it belongs to. */
function listen_consume_grant(array $server, string $token): ?string {
    $state = avian_admin_state();
    if (!avian_admin_session_valid($server, $state, false, true, true, false)) return null;
    $cookie = session_id();
    $key = hash('sha256', $token);
    $grants = $_SESSION[LISTEN_GRANTS_KEY] ?? [];
    $grant = is_array($grants) ? ($grants[$key] ?? null) : null;
    if (is_array($grants)) {
        unset($grants[$key]);
        $_SESSION[LISTEN_GRANTS_KEY] = $grants;
    }
    session_write_close();
    if (!is_array($grant) || (int)($grant['expires'] ?? 0) < time()
        || preg_match('/\A[A-Za-z0-9,-]{16,128}\z/D', $cookie) !== 1) {
        return null;
    }
    return $cookie;
}

/** Mid-stream: ask ourselves over loopback whether the admin session that
 *  opened this stream is still valid (locked, logged out, password changed
 *  all end it). A separate request because this one already sent audio. */
function listen_session_still_valid(string $cookie): bool {
    $socket = @stream_socket_client('tcp://127.0.0.1:80', $errno, $error, 1.0);
    if (!is_resource($socket)) return false;
    stream_set_timeout($socket, 2);
    $request = "GET /avian/api/live-listen.php?check=1 HTTP/1.0\r\n"
        . "Host: localhost\r\n"
        . 'Cookie: ' . AVIAN_ADMIN_SESSION_NAME . "=$cookie\r\n"
        . "Connection: close\r\n\r\n";
    if (@fwrite($socket, $request) !== strlen($request)) { fclose($socket); return false; }
    $line = @fgets($socket, 256);
    fclose($socket);
    return is_string($line) && preg_match('/\AHTTP\/1[.][01][ \t]+204\b/', $line) === 1;
}

/** @return resource|null an exclusive relay slot */
function listen_slot() {
    for ($i = 0; $i < LISTEN_SLOTS; $i++) {
        $h = @fopen(LISTEN_DIR . "/slot-$i.lock", 'c');
        if (!is_resource($h)) continue;
        if (@flock($h, LOCK_EX | LOCK_NB)) return $h;
        fclose($h);
    }
    return null;
}

/** @return array{0:resource,1:string}|null icecast's /stream, headers checked */
function listen_open_upstream(): ?array {
    $socket = @stream_socket_client('tcp://127.0.0.1:8000', $errno, $error, 1.0);
    if (!is_resource($socket)) return null;
    stream_set_timeout($socket, 1);
    $request = "GET /stream HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\nUser-Agent: AvianVisitors-LiveListen\r\n\r\n";
    if (@fwrite($socket, $request) !== strlen($request)) { fclose($socket); return null; }
    $received = '';
    while (strlen($received) <= 16384) {
        $chunk = @fread($socket, 4096);
        if (!is_string($chunk) || $chunk === '') { fclose($socket); return null; }
        $received .= $chunk;
        if (preg_match('/\r?\n\r?\n/', $received, $m, PREG_OFFSET_CAPTURE)) {
            $end = $m[0][1];
            $headers = substr($received, 0, $end);
            $body = substr($received, $end + strlen($m[0][0]));
            if (!preg_match('/\AHTTP\/1[.][01][ \t]+200\b/', $headers)
                || !preg_match('/^Content-Type:[ \t]*audio\/mpeg\b/mi', $headers)) {
                fclose($socket);
                return null;
            }
            return [$socket, $body];
        }
    }
    fclose($socket);
    return null;
}

$server = $_SERVER;
$method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
$direct = avian_is_direct_local_request($server);

// ---- loopback session check -------------------------------------------
if ($method === 'GET' && isset($_GET['check'])) {
    if ((string)($server['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || !$direct) listen_json(404, ['ok' => false]);
    http_response_code(avian_admin_session_valid($server, null, false, false, false, false) ? 204 : 403);
    exit;
}

// ---- the stream ---------------------------------------------------------
if ($method === 'GET' && isset($_GET['grant'])) {
    // Media elements ask for "bytes=0-"; a live stream answers that with
    // the whole stream. Any other range can't be served.
    if (isset($server['HTTP_RANGE']) && trim((string)$server['HTTP_RANGE']) !== 'bytes=0-') {
        listen_json(416, ['ok' => false, 'error' => 'range requests are not supported']);
    }
    if (!$direct && !listen_remote_enabled()) listen_json(403, ['ok' => false, 'error' => 'remote listening is turned off']);
    $token = is_string($_GET['grant']) ? $_GET['grant'] : '';
    $cookie = preg_match('/\A[a-f0-9]{48}\z/D', $token) === 1 ? listen_consume_grant($server, $token) : null;
    if ($cookie === null) listen_json(401, ['ok' => false, 'error' => 'invalid or expired listen grant']);

    $slot = listen_slot();
    if (!is_resource($slot)) listen_json(429, ['ok' => false, 'error' => 'two people are already listening remotely']);
    $upstream = listen_open_upstream();
    if (!is_array($upstream)) {
        flock($slot, LOCK_UN); fclose($slot);
        listen_json(503, ['ok' => false, 'error' => 'live audio is unavailable']);
    }
    [$audio, $first] = $upstream;
    $sessionId = listen_session_begin($server, $cookie);

    header('Content-Type: audio/mpeg');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) @ob_end_clean();
    @set_time_limit(0);
    ignore_user_abort(false);

    $started = time();
    $nextBeat = $started + LISTEN_BEAT_EVERY;
    $nextCheck = $started + LISTEN_CHECK_EVERY;
    if ($first !== '') { echo $first; flush(); }
    while (!connection_aborted() && time() - $started < LISTEN_MAX_SECONDS) {
        $now = time();
        if ($now >= $nextBeat) {
            if ($sessionId !== null) listen_session_beat($sessionId);
            if (!$direct && !listen_remote_enabled()) break;   // switched off: cut it now
            $nextBeat = $now + LISTEN_BEAT_EVERY;
        }
        if ($now >= $nextCheck) {
            if (!listen_session_still_valid($cookie)) break;
            $nextCheck = $now + LISTEN_CHECK_EVERY;
        }
        $chunk = @fread($audio, 16384);
        if (is_string($chunk) && $chunk !== '') { echo $chunk; flush(); continue; }
        $meta = stream_get_meta_data($audio);
        if (!empty($meta['eof']) || empty($meta['timed_out'])) break;
    }
    if ($sessionId !== null) listen_session_beat($sessionId);
    fclose($audio);
    flock($slot, LOCK_UN);
    fclose($slot);
    exit;
}

// ---- status / log -------------------------------------------------------
if ($method === 'GET') {
    avian_require_admin();
    if (isset($_GET['log'])) {
        listen_json(200, ['ok' => true, 'sessions' => listen_log(), 'store' => listen_db() !== null]);
    }
    listen_json(200, ['ok' => true, 'remote_enabled' => listen_remote_enabled(), 'store' => listen_db() !== null]
        + listen_counts());
}

// ---- actions ------------------------------------------------------------
avian_require_json_action();
$body = json_decode((string)file_get_contents('php://input'), true);
$action = is_array($body) ? ($body['action'] ?? '') : '';

if ($action === 'grant') {
    if (!$direct && !listen_remote_enabled()) listen_json(403, ['ok' => false, 'error' => 'remote listening is turned off']);
    $token = listen_create_grant($server);
    if ($token === null) listen_json(401, ['ok' => false, 'error' => 'unlock the admin menu to listen']);
    listen_json(200, ['ok' => true, 'url' => '/avian/api/live-listen.php?grant=' . $token, 'expires_in' => 15]);
}
if ($action === 'set') {
    avian_require_admin();
    if (!is_bool($body['enabled'] ?? null)) listen_json(400, ['ok' => false, 'error' => 'enabled must be true or false']);
    if (!listen_set_remote_enabled($body['enabled'])) {
        listen_json(503, ['ok' => false, 'error' => 'listening store is unavailable']);
    }
    listen_json(200, ['ok' => true, 'remote_enabled' => listen_remote_enabled()]);
}
listen_json(400, ['ok' => false, 'error' => 'unknown action']);
