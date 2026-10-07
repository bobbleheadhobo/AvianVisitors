<?php
// Notification targets for Settings > Notifications.
//
// BirdNET-Pi sends notifications through Apprise, reading one target URL
// per line from ~/BirdNET-Pi/apprise.txt (bird alerts, the weekly report,
// and Avian Visitors' live-listening alerts all use it). The classic
// Settings page writes that file directly, which this hardened station
// doesn't allow, so the file is created group-writable at install and
// rewritten in place here.
//
//   GET                              targets (masked) and the message template
//   POST {action:"save", targets}    replace the targets (one URL per line)
//   POST {action:"message", body}    replace the message template (body.txt)
//   POST {action:"test"}             send the real template, filled in with
//                                    the latest detection, now
//
// The template's $variables are BirdNET-Pi's (scripts/utils/notifications.py);
// the test fills them the same way.
//
// When to notify (APPRISE_* switches) is ordinary config, saved by config.php.

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';

avian_require_admin();

$appriseFile = dirname(__DIR__, 2) . '/apprise.txt';
$appriseBin = dirname(__DIR__, 2) . '/birdnet/bin/apprise';
$bodyFile = dirname(__DIR__, 2) . '/body.txt';
$confPath = '/etc/birdnet/birdnet.conf';

function notify_conf(string $path): array {
    $out = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
            $v = trim($m[2]);
            if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') $v = substr($v, 1, -1);
            $out[$m[1]] = $v;
        }
    }
    return $out;
}

/** Rewrite a group-writable file in place under a lock (the folder itself is
 *  not writable by the web server). */
function notify_write(string $file, string $text): bool {
    $h = @fopen($file, 'c+');
    if (!$h || !flock($h, LOCK_EX)) return false;
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, $text);
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    return true;
}

function notify_json(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

/** "discord webhook 1557…" or "mailto · example.com/…" - enough to recognise
 *  a target, never its secret (a Discord webhook's ID is not; its token is). */
function notify_mask(string $url): string {
    if (preg_match('~\A(?:https://(?:[a-z]+\.)?discord(?:app)?\.com/api/webhooks|discord:/)/(\d{4})\d*/~i', $url, $m)) {
        return 'discord webhook ' . $m[1] . '…';
    }
    $scheme = strtolower((string)strtok($url, ':'));
    $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
    return $scheme . ($host !== '' ? ' · ' . $host . '/…' : '');
}

/** @return string[] */
function notify_targets(string $file): array {
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== '' && $l[0] !== '#'));
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $targets = notify_targets($appriseFile);
    notify_json(200, [
        'ok' => true,
        'targets' => array_map('notify_mask', $targets),
        'writable' => is_file($appriseFile) && is_writable($appriseFile),
        'body' => (string)@file_get_contents($bodyFile),
        'body_writable' => is_file($bodyFile) && is_writable($bodyFile),
        // What "listen" links use while no station address is set.
        'default_site' => 'http://' . gethostname() . '.local',
    ]);
}

avian_require_json_action();
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) notify_json(400, ['ok' => false, 'error' => 'bad json']);
$action = (string)($body['action'] ?? '');

if ($action === 'save') {
    $raw = (string)($body['targets'] ?? '');
    if (strlen($raw) > 4000) notify_json(400, ['ok' => false, 'error' => 'too long']);
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw)), 'strlen'));
    if (count($lines) > 10) notify_json(400, ['ok' => false, 'error' => 'at most 10 targets']);
    foreach ($lines as $line) {
        // An Apprise URL: scheme://something, printable, no spaces.
        if (!preg_match('~\A[a-z][a-z0-9+.-]{1,20}://[^\s\x00-\x1f\x7f]{3,1000}\z~i', $line)) {
            notify_json(400, ['ok' => false, 'error' => 'each line must be one notification URL, like https://discord.com/api/webhooks/…']);
        }
    }
    if (!is_file($appriseFile) || !is_writable($appriseFile)) {
        notify_json(503, ['ok' => false, 'error' => 'apprise.txt is missing or not writable; run sudo /usr/local/sbin/avian-service-refresh on the station']);
    }
    if (!notify_write($appriseFile, $lines ? implode("\n", $lines) . "\n" : '')) {
        notify_json(500, ['ok' => false, 'error' => 'could not write apprise.txt']);
    }
    notify_json(200, ['ok' => true, 'targets' => array_map('notify_mask', $lines)]);
}

if ($action === 'message') {
    $text = str_replace("\r\n", "\n", (string)($body['body'] ?? ''));
    if (trim($text) === '') notify_json(400, ['ok' => false, 'error' => 'the message is empty']);
    if (strlen($text) > 2000 || !mb_check_encoding($text, 'UTF-8') || strpos($text, "\0") !== false) {
        notify_json(400, ['ok' => false, 'error' => 'the message must be plain text under 2000 characters']);
    }
    if (!is_file($bodyFile) || !is_writable($bodyFile)) {
        notify_json(503, ['ok' => false, 'error' => 'body.txt is not writable; run sudo /usr/local/sbin/avian-service-refresh on the station']);
    }
    if (!notify_write($bodyFile, rtrim($text) . "\n")) notify_json(500, ['ok' => false, 'error' => 'could not write body.txt']);
    notify_json(200, ['ok' => true]);
}

if ($action === 'test') {
    if (!notify_targets($appriseFile)) notify_json(409, ['ok' => false, 'error' => 'save a target first']);
    if (!is_executable($appriseBin)) notify_json(503, ['ok' => false, 'error' => 'apprise is not installed']);
    // Fill the saved template the way the notifier does, with the station's
    // latest real detection (a sample bird if there is none yet).
    $conf = notify_conf($confPath);
    $d = null;
    try {
        $db = new SQLite3(dirname(__DIR__, 2) . '/scripts/birds.db', SQLITE3_OPEN_READONLY);
        $db->busyTimeout(1000);
        $d = $db->querySingle('SELECT Sci_Name, Com_Name, Confidence, File_Name, Date, Time FROM detections ORDER BY Date DESC, Time DESC LIMIT 1', true) ?: null;
        $db->close();
    } catch (Throwable $e) {
    }
    $d = $d ?: ['Sci_Name' => 'Haemorhous mexicanus', 'Com_Name' => 'House Finch', 'Confidence' => 0.87,
        'File_Name' => 'House_Finch-87-' . date('Y-m-d') . '-birdnet-' . date('H:i:s') . '.mp3',
        'Date' => date('Y-m-d'), 'Time' => date('H:i:s')];
    $site = rtrim((string)($conf['BIRDNETPI_URL'] ?? ''), '/');
    if ($site === '') $site = 'http://' . gethostname() . '.local';
    $listen = $site . '?filename=' . $d['File_Name'];
    $template = (string)@file_get_contents($bodyFile);
    $image = '';
    if (strpos($template, '$image') !== false || strpos($template, '$flickrimage') !== false) {
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $j = json_decode((string)@file_get_contents('http://localhost/api/v1/image/' . rawurlencode($d['Sci_Name']), false, $ctx), true);
        $image = (string)($j['data']['image_url'] ?? '');
    }
    $braces = strpos($template, '{') !== false;
    $fill = [
        '$sciname' => $d['Sci_Name'], '$comname' => $d['Com_Name'],
        '$confidencepct' => (string)round($d['Confidence'] * 100), '$confidence' => (string)$d['Confidence'],
        '$listenurl' => $listen, '$friendlyurl' => '[Listen here](' . $listen . ')',
        '$birdurl' => $site . '/#sci=' . rawurlencode($d['Sci_Name']) . '&rec=' . rawurlencode(basename($d['File_Name'])),
        '$date' => $d['Date'], '$time' => $d['Time'], '$week' => date('W', strtotime($d['Date'])),
        '$latitude' => (string)($conf['LATITUDE'] ?? ''), '$longitude' => (string)($conf['LONGITUDE'] ?? ''),
        '$cutoff' => (string)($conf['CONFIDENCE'] ?? ''), '$sens' => (string)($conf['SENSITIVITY'] ?? ''),
        '$flickrimage' => $braces ? $image : '', '$image' => $braces ? $image : '',
        '$overlap' => (string)($conf['OVERLAP'] ?? ''), '$reason' => 'test',
    ];
    $text = $template;
    foreach ($fill as $k => $v) $text = str_replace($k, $v, $text);   // in the notifier's order
    $title = html_entity_decode((string)($conf['APPRISE_NOTIFICATION_TITLE'] ?? 'BirdNET-Pi'), ENT_QUOTES);
    foreach ($fill as $k => $v) $title = str_replace($k, $v, $title);   // the notifier fills titles too
    $out = [];
    $rc = 0;
    exec(escapeshellarg($appriseBin)
        . ' -t ' . escapeshellarg($title . ' (test)')
        . ' -b ' . escapeshellarg($text !== '' ? $text : 'Test notification from your station.')
        . ($image !== '' && preg_match('~\Ahttps://~', $image) ? ' --attach ' . escapeshellarg($image) : '')
        . ' --config ' . escapeshellarg($appriseFile) . ' 2>&1', $out, $rc);
    if ($rc === 0) notify_json(200, ['ok' => true]);
    // Apprise's own words, minus anything that could echo a URL back.
    $why = preg_replace('~[a-z][a-z0-9+.-]*://\S+~i', '(target)', implode(' ', array_slice($out, -3)));
    notify_json(502, ['ok' => false, 'error' => 'the test did not go through' . ($why ? ': ' . $why : '')]);
}

notify_json(400, ['ok' => false, 'error' => 'unknown action']);
