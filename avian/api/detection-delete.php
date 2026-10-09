<?php
// Deletes one detection, opened from an alert's "see this bird" link.
//
//   POST {file: "<recording file name>"}
//
// The row in birds.db, its mp3, spectrogram and any shifted copy go. The web
// server cannot write the database, so the work runs through the root-owned
// /usr/local/sbin/avian-admin-control, which checks the name and acts as the
// BirdNET-Pi user. Excluding the species is a separate call to
// species-lists.php.

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';

avian_require_admin();
avian_require_json_action();

const DETECTION_ADMIN_CONTROL = '/usr/local/sbin/avian-admin-control';

function detection_json(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
$file = is_array($body) ? basename(trim((string)($body['file'] ?? ''))) : '';
if ($file === '' || !preg_match('/-birdnet-.*\.(mp3|wav|flac|ogg|m4a|aac|opus)$/', $file)) {
    detection_json(400, ['ok' => false, 'error' => 'a recording file name is required']);
}
if (!is_executable(DETECTION_ADMIN_CONTROL)) {
    detection_json(503, ['ok' => false, 'error' => 'admin control is not installed']);
}

$out = [];
$rc = 0;
exec('sudo -n ' . escapeshellarg(DETECTION_ADMIN_CONTROL) . ' detection-delete '
    . escapeshellarg($file) . ' 2>/dev/null', $out, $rc);
$decoded = json_decode(implode("\n", $out), true);
if (!is_array($decoded)) detection_json(500, ['ok' => false, 'error' => 'admin control gave no answer']);
if ($rc !== 0 || empty($decoded['ok'])) {
    $error = (string)($decoded['error'] ?? 'delete failed');
    detection_json($error === 'no detection has that file name' ? 404 : 409, ['ok' => false, 'error' => $error]);
}
detection_json(200, $decoded);
