<?php
// Station actions under Tools: back up, restore, reboot, shut down.
//
//   GET  ?backup=1                 stream a full backup (.tar) to the browser
//   GET  ?size=1                   estimated backup size
//   GET  ?restore=1                restore progress
//   POST (octet-stream)            one chunk of a backup being uploaded;
//                                  X-Upload-Offset / X-Upload-Total headers
//   POST {action, confirm}         reboot | poweroff | restore-start | restore-clear
//
// Every privileged step runs through /usr/local/sbin/avian-station-control,
// a root-owned helper the web server may call with fixed actions only.
//
// A backup holds the station's secrets (API keys, notification webhooks)
// and a restore puts back birdnet.conf, which BirdNET-Pi's services run as
// shell. Both need a password-backed admin session, not just the LAN's
// implicit trust:
//   GET ?proof=1   204 with such a session; send the password once
//                  (Authorization + X-Avian-Credential) to make one

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';

const STATION_CONTROL = '/usr/local/sbin/avian-station-control';
const STATION_RESTORE_DIR = '/var/lib/avian-visitors/restore';
const STATION_STAGED = STATION_RESTORE_DIR . '/upload.tar';
const STATION_CHUNK_MAX = 4 * 1024 * 1024;           // matches the page's chunk size
const STATION_UPLOAD_MAX = 64 * 1024 * 1024 * 1024;  // a sanity cap, not a quota

avian_require_admin();
$tz = getenv('AVIAN_STATION_TIMEZONE');
if (is_string($tz) && in_array($tz, timezone_identifiers_list(), true)) date_default_timezone_set($tz);

function station_json(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function station_control(string $action): array {
    if (!is_executable(STATION_CONTROL)) {
        return [503, ['ok' => false, 'error' => 'station control is not installed']];
    }
    $out = [];
    $rc = 0;
    exec('sudo -n ' . escapeshellarg(STATION_CONTROL) . ' ' . escapeshellarg($action) . ' 2>&1', $out, $rc);
    $decoded = json_decode(implode("\n", $out), true);
    if (!is_array($decoded)) return [500, ['ok' => false, 'error' => 'station control gave no answer']];
    return [$rc === 0 ? 200 : 409, $decoded];
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    if (isset($_GET['proof'])) {
        avian_require_admin_proof();
        http_response_code(204);
        exit;
    }
    if (isset($_GET['backup'])) {
        avian_require_admin_proof();
        if (!is_executable(STATION_CONTROL)) station_json(503, ['ok' => false, 'error' => 'station control is not installed']);
        $name = 'birdnet-backup-' . date('Y-m-d-His') . '.tar';
        header('Content-Type: application/x-tar');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) @ob_end_clean();
        @set_time_limit(0);
        passthru('sudo -n ' . escapeshellarg(STATION_CONTROL) . ' backup');
        exit;
    }
    if (isset($_GET['size'])) {
        [$status, $body] = station_control('backup-size');
        station_json($status, $body);
    }
    if (isset($_GET['restore'])) {
        [$status, $body] = station_control('restore-status');
        station_json($status, $body);
    }
    station_json(400, ['ok' => false, 'error' => 'unknown request']);
}

if ($method !== 'POST') station_json(405, ['ok' => false, 'error' => 'POST required']);
if (($_SERVER['HTTP_X_AVIAN_ACTION'] ?? null) !== '1') station_json(403, ['ok' => false, 'error' => 'missing action header']);

$type = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));

// ---- restore upload, one chunk at a time ------------------------------
if ($type === 'application/octet-stream') {
    avian_require_admin_proof();
    $offset = filter_var($_SERVER['HTTP_X_UPLOAD_OFFSET'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $total = filter_var($_SERVER['HTTP_X_UPLOAD_TOTAL'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($offset === false || $total === false || $total > STATION_UPLOAD_MAX || $offset >= $total) {
        station_json(400, ['ok' => false, 'error' => 'bad upload position']);
    }
    if (!is_dir(STATION_RESTORE_DIR) || !is_writable(STATION_RESTORE_DIR)) {
        station_json(503, ['ok' => false, 'error' => 'restore staging is not set up']);
    }
    if ($offset === 0) {
        $free = @disk_free_space(STATION_RESTORE_DIR);
        if (is_float($free) && $free < $total * 2.2) {
            station_json(507, ['ok' => false, 'error' => 'not enough free space to stage and unpack this backup']);
        }
    }
    clearstatcache(true, STATION_STAGED);
    $have = is_file(STATION_STAGED) ? (int)filesize(STATION_STAGED) : 0;
    if ($offset !== 0 && $offset !== $have) {
        station_json(409, ['ok' => false, 'error' => 'upload out of order', 'have' => $have]);
    }
    $in = fopen('php://input', 'rb');
    $out = @fopen(STATION_STAGED, $offset === 0 ? 'wb' : 'ab');
    if (!$in || !$out) station_json(500, ['ok' => false, 'error' => 'could not write the upload']);
    $written = stream_copy_to_stream($in, $out, STATION_CHUNK_MAX + 1);
    fclose($in);
    fclose($out);
    if ($written === false || $written > STATION_CHUNK_MAX) {
        @unlink(STATION_STAGED);
        station_json(413, ['ok' => false, 'error' => 'upload chunk too large']);
    }
    clearstatcache(true, STATION_STAGED);
    station_json(200, ['ok' => true, 'have' => (int)filesize(STATION_STAGED), 'total' => $total]);
}

// ---- actions --------------------------------------------------------------
avian_require_json_action();
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) station_json(400, ['ok' => false, 'error' => 'bad json']);
$action = (string)($body['action'] ?? '');
$confirm = (string)($body['confirm'] ?? '');
// The page sends these words only after the person confirms, so a stray
// request can't reboot the station or overwrite its data.
$expected = [
    'reboot' => 'reboot-station',
    'poweroff' => 'shut-down-station',
    'restore-start' => 'restore-backup',
    'restore-clear' => 'discard-upload',
];
if (!isset($expected[$action]) || !hash_equals($expected[$action], $confirm)) {
    station_json(400, ['ok' => false, 'error' => 'confirmation required']);
}
if ($action === 'restore-start' || $action === 'restore-clear') avian_require_admin_proof();
[$status, $result] = station_control($action);
station_json($status, $result);
