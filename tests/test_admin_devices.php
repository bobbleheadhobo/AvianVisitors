<?php
declare(strict_types=1);

// Remembered devices ("keep me signed in"): admin-devices.php.

require_once dirname(__DIR__) . '/avian/api/admin-auth.php';

$checks = 0;
$failures = 0;

function check(bool $condition, string $label): void {
    global $checks, $failures;
    $checks++;
    if ($condition) return;
    $failures++;
    fwrite(STDERR, "FAIL: $label\n");
}

function state_with(string $verifier, bool $required = true, string $epoch = '1'): array {
    return [
        'valid' => true, 'required' => $required, 'epoch' => $epoch,
        'verifier' => $verifier, 'configured' => true, 'error' => null,
    ];
}

function device_files(string $dir): array {
    return array_values(array_filter(scandir($dir) ?: [], static fn($n) => $n[0] !== '.'));
}

function rm_tree(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') rm_tree($path . '/' . $name);
    }
    rmdir($path);
}

$tmp = sys_get_temp_dir() . '/avian-admin-devices-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$dir = $tmp . '/devices';
mkdir($dir, 0770);
$sessionDir = $tmp . '/sessions';
mkdir($sessionDir, 0700);
session_save_path($sessionDir);
putenv('AV_DEVICE_DIR=' . $dir);
putenv('AV_DEVICE_TEST_METADATA=1');

$verifier = (string)password_hash('first password', PASSWORD_BCRYPT, ['cost' => 4]);
$state = state_with($verifier);
$https = [
    'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '192.168.0.106',
    'HTTP_HOST' => 'merlin.example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '443',
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0 Mobile Safari/537.36',
];

// Issue.
$_COOKIE = [];
check(avian_device_issue($https, $state), 'a device is remembered after sign-in');
$cookie = (string)($_COOKIE[AVIAN_DEVICE_COOKIE] ?? '');
check(preg_match('/\A[a-f0-9]{32}\.[a-f0-9]{64}\z/D', $cookie) === 1, 'device cookie is selector.validator');
[$selector, $validator] = explode('.', $cookie);
$files = device_files($dir);
check($files === [$selector], 'one file per device, named by its selector');
$stored = (string)file_get_contents($dir . '/' . $selector);
check(!str_contains($stored, $validator), 'the validator itself is never stored');
check(str_contains($stored, hash('sha256', $validator)), 'only the validator hash is stored');
check((fileperms($dir . '/' . $selector) & 0777) === 0600, 'device file is private to the web server');
check(avian_device_remembered($https, $state), 'the issuing browser is remembered');

// HTTPS-only on a public hostname even when the proxy's TLS is hidden.
check(avian_device_secure(['HTTP_HOST' => 'merlin.example.com']), 'public hostname over a plain proxy hop is Secure');
check(avian_device_secure(['HTTP_HOST' => '192.168.0.112', 'HTTPS' => 'on']), 'direct HTTPS is Secure');
check(!avian_device_secure(['HTTP_HOST' => '192.168.0.112']), 'plain HTTP to the station address is not');
check(!avian_device_secure(['HTTP_HOST' => 'birdnet.local:80']), 'plain HTTP to a .local name is not');

// Wrong or malformed cookies.
$_COOKIE = [AVIAN_DEVICE_COOKIE => $selector . '.' . str_repeat('0', 64)];
check(!avian_device_remembered($https, $state), 'a wrong validator is rejected');
check(is_file($dir . '/' . $selector), 'a wrong guess does not delete the real device');
avian_device_forget($https);
check(is_file($dir . '/' . $selector), 'sign-out with a wrong validator cannot delete the device');
foreach (['', 'abc', $selector, $selector . '.' . $validator . 'x', '../' . $selector . '.' . $validator,
          strtoupper($selector) . '.' . $validator] as $bad) {
    $_COOKIE = [AVIAN_DEVICE_COOKIE => $bad];
    check(!avian_device_remembered($https, $state), 'malformed cookie is rejected: ' . json_encode($bad));
}

// Restoring a session.
$_COOKIE = [AVIAN_DEVICE_COOKIE => $cookie];
check(avian_device_restore_session($https, $state), 'a remembered device restores a session');
$restored = (string)($_COOKIE[AVIAN_ADMIN_SESSION_NAME] ?? '');
check($restored !== '', 'the restored session is visible to the rest of the request');
check(avian_admin_session_valid($https, $state), 'the restored session is a normal valid admin session');
$_COOKIE = [AVIAN_DEVICE_COOKIE => $selector . '.' . str_repeat('1', 64)];
check(!avian_device_restore_session($https, $state), 'a wrong validator restores nothing');

// The expiry slides, at most daily, and never past the absolute cap.
$record = json_decode((string)file_get_contents($dir . '/' . $selector), true);
$record['seen'] = time() - 2 * 86400;
$record['expires'] = time() + 10;
file_put_contents($dir . '/' . $selector, json_encode($record));
$_COOKIE = [AVIAN_DEVICE_COOKIE => $cookie];
check(avian_device_restore_session($https, $state), 'a device near expiry still restores');
$record = json_decode((string)file_get_contents($dir . '/' . $selector), true);
check($record['expires'] >= time() + AVIAN_DEVICE_IDLE_SECONDS - 5, 'use extends the device by 90 days');
$record['created'] = time() - AVIAN_DEVICE_ABSOLUTE_SECONDS + 100;
$record['seen'] = time() - 2 * 86400;
file_put_contents($dir . '/' . $selector, json_encode($record));
avian_device_restore_session($https, $state);
$record = json_decode((string)file_get_contents($dir . '/' . $selector), true);
check($record['expires'] <= $record['created'] + AVIAN_DEVICE_ABSOLUTE_SECONDS, 'never past a year from sign-in');

// Expiry.
$record['expires'] = time() - 1;
file_put_contents($dir . '/' . $selector, json_encode($record));
check(!avian_device_remembered($https, $state), 'an expired device is rejected');
check(!is_file($dir . '/' . $selector), 'an expired device is deleted');
$_COOKIE = [];
avian_device_issue($https, $state);
$cookie = (string)$_COOKIE[AVIAN_DEVICE_COOKIE];
[$selector] = explode('.', $cookie);
$record = json_decode((string)file_get_contents($dir . '/' . $selector), true);
$record['created'] = time() - AVIAN_DEVICE_ABSOLUTE_SECONDS - 1;
file_put_contents($dir . '/' . $selector, json_encode($record));
check(!avian_device_remembered($https, $state), 'a device older than a year is rejected');

// A new password, epoch, or LAN policy forgets every device.
$_COOKIE = [];
avian_device_issue($https, $state);
$cookie = (string)$_COOKIE[AVIAN_DEVICE_COOKIE];
[$selector] = explode('.', $cookie);
$newPassword = state_with((string)password_hash('second password', PASSWORD_BCRYPT, ['cost' => 4]));
check(!avian_device_remembered($https, $newPassword), 'a password change signs the device out');
check(!is_file($dir . '/' . $selector), 'the stale device is deleted');
$_COOKIE = [];
avian_device_issue($https, $state);
check(!avian_device_remembered($https, state_with($verifier, true, '2')), 'a new epoch signs the device out');
$_COOKIE = [];
avian_device_issue($https, $state);
check(!avian_device_remembered($https, state_with($verifier, false)), 'a LAN policy change signs the device out');
$_COOKIE = [];
avian_device_issue($https, $state);
$invalid = $state;
$invalid['valid'] = false;
check(!avian_device_remembered($https, $invalid), 'invalid credential state remembers nothing');

// Signing in again on the same browser replaces its entry.
foreach (device_files($dir) as $name) unlink($dir . '/' . $name);
$_COOKIE = [];
avian_device_issue($https, $state);
avian_device_issue($https, $state);
check(count(device_files($dir)) === 1, 'signing in again replaces the old device entry');

// Sign out.
$cookie = (string)$_COOKIE[AVIAN_DEVICE_COOKIE];
[$selector] = explode('.', $cookie);
avian_logout_admin_session($https);
check(!is_file($dir . '/' . $selector), 'locking the drawer forgets this device');
check(!isset($_COOKIE[AVIAN_DEVICE_COOKIE]), 'locking the drawer drops the device cookie');

// At most AVIAN_DEVICE_MAX devices; the least recently used go first.
for ($i = 0; $i < AVIAN_DEVICE_MAX + 5; $i++) {
    $_COOKIE = [];
    avian_device_issue($https, $state);
}
check(count(device_files($dir)) === AVIAN_DEVICE_MAX, 'the device store is capped');
check(avian_device_remembered($https, $state), 'the newest device survives the cap');

// The feature fails closed when its directory is missing or unsafe.
$_COOKIE = [];
putenv('AV_DEVICE_DIR=' . $tmp . '/missing');
check(!avian_device_issue($https, $state), 'no directory, no remembered device');
symlink($dir, $tmp . '/link');
putenv('AV_DEVICE_DIR=' . $tmp . '/link');
check(!avian_device_issue($https, $state), 'a symlinked directory is refused');
putenv('AV_DEVICE_DIR=' . $dir);
putenv('AV_DEVICE_TEST_METADATA');
check(!avian_device_issue($https, $state), 'a directory not owned root:caddy 0770 is refused');

rm_tree($tmp);
putenv('AV_DEVICE_DIR');

if ($failures > 0) {
    fwrite(STDERR, "$failures of $checks checks failed\n");
    exit(1);
}
echo "admin device tests passed ($checks checks)\n";
