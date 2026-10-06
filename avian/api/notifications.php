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
//   GET                              which targets are set (masked)
//   POST {action:"save", targets}    replace the targets (one URL per line)
//   POST {action:"test"}             send a test notification now
//
// When to notify (APPRISE_* switches) is ordinary config, saved by config.php.

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';

avian_require_admin();

$appriseFile = dirname(__DIR__, 2) . '/apprise.txt';
$appriseBin = dirname(__DIR__, 2) . '/birdnet/bin/apprise';

function notify_json(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

/** "discord · discord.com/…" - enough to recognise a target, never its secret. */
function notify_mask(string $url): string {
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
    $h = fopen($appriseFile, 'c+');
    if (!$h || !flock($h, LOCK_EX)) notify_json(500, ['ok' => false, 'error' => 'could not lock apprise.txt']);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, $lines ? implode("\n", $lines) . "\n" : '');
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    notify_json(200, ['ok' => true, 'targets' => array_map('notify_mask', $lines)]);
}

if ($action === 'test') {
    if (!notify_targets($appriseFile)) notify_json(409, ['ok' => false, 'error' => 'save a target first']);
    if (!is_executable($appriseBin)) notify_json(503, ['ok' => false, 'error' => 'apprise is not installed']);
    $out = [];
    $rc = 0;
    exec(escapeshellarg($appriseBin)
        . ' -t ' . escapeshellarg('BirdNET-Pi test')
        . ' -b ' . escapeshellarg('Test notification from your station. If you can read this, notifications work.')
        . ' --config ' . escapeshellarg($appriseFile) . ' 2>&1', $out, $rc);
    if ($rc === 0) notify_json(200, ['ok' => true]);
    // Apprise's own words, minus anything that could echo a URL back.
    $why = preg_replace('~[a-z][a-z0-9+.-]*://\S+~i', '(target)', implode(' ', array_slice($out, -3)));
    notify_json(502, ['ok' => false, 'error' => 'the test did not go through' . ($why ? ': ' . $why : '')]);
}

notify_json(400, ['ok' => false, 'error' => 'unknown action']);
