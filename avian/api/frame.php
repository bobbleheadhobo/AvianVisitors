<?php
// The e-paper frame's look, for Settings > Frame.
//
// The frame is drawn by frame/display.py on this station (output = "esphome")
// into ~/BirdSongs/Extracted/frame, which the ESP32 fetches. The keys the site
// may change, with their defaults and limits, live in frame/web_settings.json;
// display.py reads the same file. Saved values go to settings.json in the
// frame state folder, where each one overrides that key in config.toml.
// install-server.sh creates the folder (writable by the web server, readable
// by the renderer) and a systemd path unit that re-renders the frame as soon
// as settings.json or the refresh file changes.
//
//   GET                               values, defaults, limits, frame status
//   POST {action:"save", values}      save some or all of the settings
//   POST {action:"refresh"}           re-render the frame now

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';

avian_require_admin();

$schemaFile = dirname(__DIR__, 2) . '/frame/web_settings.json';
$stateDir = getenv('AV_FRAME_DIR') ?: '/var/lib/avian-visitors/frame';
$confPath = '/etc/birdnet/birdnet.conf';

function frame_json(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function frame_schema(string $file): array {
    $schema = json_decode((string)@file_get_contents($file), true);
    return is_array($schema['settings'] ?? null) ? $schema['settings'] : [];
}

function frame_saved(string $dir): array {
    $saved = json_decode((string)@file_get_contents($dir . '/settings.json'), true);
    return is_array($saved) ? $saved : [];
}

/** The rendered frame folder: <EXTRACTED>/frame, served at /frame/. */
function frame_export_dir(string $confPath): string {
    $extracted = '';
    foreach (@file($confPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*EXTRACTED\s*=\s*"?([^"]*)"?\s*$/', $line, $m)) $extracted = $m[1];
    }
    return rtrim($extracted, '/') . '/frame';
}

/** Check one value against its schema entry; returns [ok, clean value or error]. */
function frame_clean(array $spec, $value): array {
    if ($spec['type'] === 'host') {
        // A hostname or IPv4 address, nothing that could reach past the LAN probe.
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?\z/', trim($value))) {
            return [false, 'must be a hostname or IP address'];
        }
        return [true, trim($value)];
    }
    if ($spec['type'] === 'string') {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) return [false, 'must be text'];
        $value = trim($value);
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) return [false, 'must be plain text'];
        if (mb_strlen($value) > (int)$spec['maxlen']) return [false, 'at most ' . $spec['maxlen'] . ' characters'];
        return [true, $value];
    }
    if (is_bool($value) || !is_numeric($value)) return [false, 'must be a number'];
    $value = $spec['type'] === 'int' ? (int)round((float)$value) : (float)$value;
    if ($value < $spec['min'] || $value > $spec['max']) {
        return [false, 'must be between ' . $spec['min'] . ' and ' . $spec['max']];
    }
    return [true, $value];
}

/** Atomic write into the frame state folder, readable by the renderer. */
function frame_write(string $dir, string $name, string $text): bool {
    $tmp = $dir . '/.' . $name . '.tmp';
    if (@file_put_contents($tmp, $text, LOCK_EX) === false) return false;
    @chmod($tmp, 0640);
    return @rename($tmp, $dir . '/' . $name);
}

/** Whether the frame answers on its ESPHome API port. A battery build that
 *  is deep asleep reads as offline between its wakes. */
function frame_online(string $host, int $port): bool {
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $sock = @fsockopen($ip, $port, $errno, $errstr, 1.0);
    if (!$sock) return false;
    fclose($sock);
    return true;
}

$schemaDoc = json_decode((string)@file_get_contents($schemaFile), true);
$device = is_array($schemaDoc['device'] ?? null) ? $schemaDoc['device'] : [];
$schema = frame_schema($schemaFile);
if (!$schema) frame_json(500, ['ok' => false, 'error' => 'frame/web_settings.json is missing']);
$ready = is_dir($stateDir) && is_writable($stateDir);
$hint = 'frame settings are not set up; rerun frame/install-server.sh on the station';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $saved = frame_saved($stateDir);
    $values = [];
    $defaults = [];
    foreach ($schema as $key => $spec) {
        $defaults[$key] = $spec['default'];
        [$ok, $clean] = array_key_exists($key, $saved) ? frame_clean($spec, $saved[$key]) : [false, null];
        $values[$key] = $ok ? $clean : $spec['default'];
    }
    $meta = json_decode((string)@file_get_contents(frame_export_dir($confPath) . '/frame.json'), true);
    $online = isset($_GET['online']) ? frame_online((string)$values['device_host'], (int)($device['port'] ?? 6053)) : null;
    frame_json(200, [
        'ok' => true,
        'ready' => $ready,
        'hint' => $ready ? null : $hint,
        'values' => $values,
        'defaults' => $defaults,
        'limits' => $schema,
        'online' => $online,
        'poll_minutes' => (int)($device['poll_minutes'] ?? 3),
        'frame' => is_array($meta) ? [
            'updated' => (int)($meta['updated'] ?? 0),
            'sig' => (string)($meta['sig_names'] ?? $meta['sig'] ?? ''),
            'width' => (int)($meta['width'] ?? 0),
            'height' => (int)($meta['height'] ?? 0),
        ] : null,
    ]);
}

avian_require_json_action();
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) frame_json(400, ['ok' => false, 'error' => 'bad json']);
if (!$ready) frame_json(503, ['ok' => false, 'error' => $hint]);
$action = (string)($body['action'] ?? '');

if ($action === 'save') {
    $incoming = $body['values'] ?? null;
    if (!is_array($incoming) || !$incoming) frame_json(400, ['ok' => false, 'error' => 'nothing to save']);
    $saved = array_intersect_key(frame_saved($stateDir), $schema);
    $fields = [];
    foreach ($incoming as $key => $value) {
        if (!isset($schema[$key])) { $fields[$key] = 'not a frame setting'; continue; }
        [$ok, $clean] = frame_clean($schema[$key], $value);
        if ($ok) $saved[$key] = $clean;
        else $fields[$key] = $clean;
    }
    if ($fields) frame_json(400, ['ok' => false, 'error' => reset($fields), 'fields' => $fields]);
    ksort($saved);
    if (!frame_write($stateDir, 'settings.json', json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n")) {
        frame_json(500, ['ok' => false, 'error' => 'could not save the frame settings']);
    }
    frame_json(200, ['ok' => true]);
}

if ($action === 'refresh') {
    if (!frame_write($stateDir, 'refresh', (string)microtime(true) . "\n")) {
        frame_json(500, ['ok' => false, 'error' => 'could not ask for a refresh']);
    }
    frame_json(202, ['ok' => true]);
}

frame_json(400, ['ok' => false, 'error' => 'unknown action']);
