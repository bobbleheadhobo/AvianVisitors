<?php
// Remembered devices: "keep me signed in on this device" for the admin
// drawer. A device gets a long-lived HttpOnly cookie holding a random
// selector and validator. The station keeps only the validator's SHA-256,
// one file per device, so reading the store cannot sign anyone in.
//
// A remembered device only re-creates the ordinary short admin session.
// Changing the password or the LAN policy, and the actions that ask for the
// password again, still need the password itself. A new password (or policy)
// invalidates every remembered device, and locking the drawer forgets the
// device it was locked on.
//
// Settings picks how long a device stays signed in, and "sign out every
// device" bumps a generation that every remembered device and every admin
// session is bound to. Both live in policy.json beside the device files.

declare(strict_types=1);

const AVIAN_DEVICE_COOKIE = 'avian_device';
const AVIAN_DEVICE_DEFAULT_DIR = '/var/lib/avian-visitors/devices';
const AVIAN_DEVICE_DAY_CHOICES = [1, 7, 30, 90, 180, 365]; // "stay signed in for", unused days
const AVIAN_DEVICE_DEFAULT_DAYS = 90;
const AVIAN_DEVICE_ABSOLUTE_SECONDS = 365 * 86400; // and never past a year from sign-in
const AVIAN_DEVICE_MAX = 20;
const AVIAN_DEVICE_MAX_BYTES = 1024;
const AVIAN_DEVICE_POLICY_FILE = 'policy.json';

function avian_device_dir(): string {
    $override = getenv('AV_DEVICE_DIR');
    if (PHP_SAPI === 'cli' && is_string($override) && $override !== '') return $override;
    return AVIAN_DEVICE_DEFAULT_DIR;
}

/** root:caddy 0770, not a link. Anything else and the feature stays off. */
function avian_device_dir_is_valid(string $dir): bool {
    clearstatcache(true, $dir);
    $stat = @lstat($dir);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0040000) return false;
    if (PHP_SAPI === 'cli' && getenv('AV_DEVICE_TEST_METADATA') === '1') return true;
    if (!function_exists('posix_getgrnam')) return false;
    $group = posix_getgrnam('caddy');
    return is_array($group) && isset($group['gid'])
        && (int)($stat['uid'] ?? -1) === 0
        && (int)($stat['gid'] ?? -1) === (int)$group['gid']
        && (($stat['mode'] ?? 0) & 0777) === 0770;
}

/**
 * How long devices stay signed in, and the sign-out-everywhere generation.
 * A missing file is the defaults (a fresh station). An unreadable or invalid
 * one turns remembering off and binds sessions to a fixed "unreadable" tag,
 * so password sign-in keeps working.
 *
 * @return array{ok:bool,days:int,generation:int}
 */
function avian_device_policy(bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $dir = avian_device_dir();
    $path = $dir . '/' . AVIAN_DEVICE_POLICY_FILE;
    clearstatcache(true, $path);
    if (!avian_device_dir_is_valid($dir)) {
        return $cache = ['ok' => false, 'days' => AVIAN_DEVICE_DEFAULT_DAYS, 'generation' => 0];
    }
    $stat = @lstat($path);
    if ($stat === false) {
        return $cache = ['ok' => true, 'days' => AVIAN_DEVICE_DEFAULT_DAYS, 'generation' => 0];
    }
    $raw = (($stat['mode'] ?? 0) & 0170000) === 0100000 && (int)($stat['size'] ?? 0) <= AVIAN_DEVICE_MAX_BYTES
        ? @file_get_contents($path, false, null, 0, AVIAN_DEVICE_MAX_BYTES)
        : false;
    $policy = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($policy)
        || ($policy['v'] ?? null) !== 1
        || !in_array($policy['days'] ?? null, AVIAN_DEVICE_DAY_CHOICES, true)
        || !is_int($policy['generation'] ?? null)
        || $policy['generation'] < 0) {
        return $cache = ['ok' => false, 'days' => AVIAN_DEVICE_DEFAULT_DAYS, 'generation' => -1];
    }
    return $cache = ['ok' => true, 'days' => $policy['days'], 'generation' => $policy['generation']];
}

/** Bound into every admin session fingerprint (admin-auth.php). */
function avian_device_generation_tag(): string {
    $policy = avian_device_policy();
    return $policy['generation'] < 0 ? 'unreadable' : (string)$policy['generation'];
}

function avian_device_idle_seconds(): int {
    return avian_device_policy()['days'] * 86400;
}

/** Slide a device's expiry this often; a quarter of the window, at most daily. */
function avian_device_extend_every(): int {
    return min(86400, intdiv(avian_device_idle_seconds(), 4));
}

function avian_device_write_policy(int $days, int $generation): bool {
    if (!in_array($days, AVIAN_DEVICE_DAY_CHOICES, true) || $generation < 0) return false;
    $dir = avian_device_dir();
    if (!avian_device_dir_is_valid($dir)
        || !avian_device_write($dir, AVIAN_DEVICE_POLICY_FILE, [
            'v' => 1, 'days' => $days, 'generation' => $generation,
        ])) return false;
    avian_device_policy(true);
    return true;
}

/** Settings: how many unused days a remembered device lasts. */
function avian_device_set_days(int $days): bool {
    $policy = avian_device_policy(true);
    if (!$policy['ok']) return false;
    return avian_device_write_policy($days, $policy['generation']);
}

/**
 * Sign out every device: every remembered device and every open admin
 * session, this browser's included. The caller re-issues a session for the
 * person who pressed the button.
 */
function avian_device_revoke_all(): bool {
    $policy = avian_device_policy(true);
    // An unreadable policy restarts the count; the "unreadable" session tag
    // still differs from the new generation, so sessions end either way.
    $next = $policy['generation'] < 0 ? 1 : $policy['generation'] + 1;
    if (!avian_device_write_policy($policy['days'], $next)) return false;
    $dir = avian_device_dir();
    foreach (@scandir($dir) ?: [] as $name) {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $name) === 1) @unlink($dir . '/' . $name);
    }
    return true;
}

function avian_device_binding(array $state, string $selector): string {
    // Same inputs as the session fingerprint: a new password, epoch, LAN
    // policy, or sign-out-everywhere changes the binding and every stored
    // device stops matching.
    $policy = !empty($state['required']) ? '1' : '0';
    $verifier = is_string($state['verifier'] ?? null) ? $state['verifier'] : 'invalid';
    return hash_hmac(
        'sha256',
        'avian-admin-device-v2:' . $policy . ':' . (string)($state['epoch'] ?? 'invalid')
            . ':' . avian_device_generation_tag() . ':' . $selector,
        $verifier
    );
}

/** @return array{0:string,1:string}|null selector, validator */
function avian_device_cookie_parts(): ?array {
    $cookie = $_COOKIE[AVIAN_DEVICE_COOKIE] ?? null;
    if (!is_string($cookie)
        || preg_match('/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/D', $cookie, $m) !== 1) return null;
    return [$m[1], $m[2]];
}

function avian_device_secure(array $server): bool {
    // Caddy replaces the proxy's X-Forwarded-Proto with its own plain-HTTP
    // hop, so a TLS-terminating proxy is invisible here. A token this
    // long-lived is HTTPS-only on every public hostname; only a station
    // address (LAN IP, .local) reached over plain HTTP gets it without.
    return avian_request_is_https($server)
        || !avian_local_host((string)($server['HTTP_HOST'] ?? ''));
}

function avian_device_set_cookie(array $server, string $value, int $expires): void {
    if (headers_sent()) return;
    setcookie(AVIAN_DEVICE_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/avian/',
        'domain' => '',
        'secure' => avian_device_secure($server),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function avian_device_expire_cookie(array $server): void {
    unset($_COOKIE[AVIAN_DEVICE_COOKIE]);
    avian_device_set_cookie($server, '', time() - 42000);
}

/** @return array<string,mixed>|null */
function avian_device_read(string $path): ?array {
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
        || (int)($stat['size'] ?? 0) < 1 || (int)($stat['size'] ?? 0) > AVIAN_DEVICE_MAX_BYTES) return null;
    $raw = @file_get_contents($path, false, null, 0, AVIAN_DEVICE_MAX_BYTES);
    $record = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($record)
        || ($record['v'] ?? null) !== 1
        || !is_string($record['hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $record['hash']) !== 1
        || !is_string($record['bind'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $record['bind']) !== 1
        || !is_int($record['created'] ?? null)
        || !is_int($record['seen'] ?? null)
        || !is_int($record['expires'] ?? null)
        || !is_string($record['label'] ?? null)) return null;
    return $record;
}

function avian_device_write(string $dir, string $selector, array $record): bool {
    $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || strlen($encoded) > AVIAN_DEVICE_MAX_BYTES) return false;
    $temp = @tempnam($dir, '.device-');
    if (!is_string($temp) || dirname($temp) !== $dir) {
        if (is_string($temp)) @unlink($temp);
        return false;
    }
    if (@file_put_contents($temp, $encoded) !== strlen($encoded)
        || !@chmod($temp, 0600)
        || !@rename($temp, $dir . '/' . $selector)) {
        @unlink($temp);
        return false;
    }
    return true;
}

function avian_device_record_live(array $record, string $selector, array $state, int $now): bool {
    return hash_equals($record['bind'], avian_device_binding($state, $selector))
        && $record['created'] <= $now
        && ($now - $record['created']) <= AVIAN_DEVICE_ABSOLUTE_SECONDS
        && $record['expires'] > $now
        // A shorter "stay signed in" setting applies to existing devices too.
        && ($now - $record['seen']) < avian_device_idle_seconds();
}

/** Drop expired, unreadable, and stale-password devices, then keep the newest few. */
function avian_device_prune(string $dir, array $state, int $keep): void {
    $now = time();
    $live = [];
    foreach (@scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        if (preg_match('/\A[a-f0-9]{32}\z/D', $name) !== 1) {
            // Leftover temp files from an interrupted write.
            if (str_starts_with($name, '.device-') && @filemtime($path) < $now - 300) @unlink($path);
            continue;
        }
        $record = avian_device_read($path);
        if ($record === null || !avian_device_record_live($record, $name, $state, $now)) {
            @unlink($path);
            continue;
        }
        $live[$name] = $record['seen'];
    }
    arsort($live);
    foreach (array_slice(array_keys($live), $keep) as $name) @unlink($dir . '/' . $name);
}

function avian_device_label(array $server): string {
    $ua = (string)($server['HTTP_USER_AGENT'] ?? '');
    if (function_exists('listen_agent_label')) return substr(listen_agent_label($ua), 0, 80);
    return substr(preg_replace('/[^\x20-\x7e]/', '', $ua) ?? '', 0, 80);
}

/** Remember this browser after a successful password sign-in. */
function avian_device_issue(array $server, array $state): bool {
    if (empty($state['valid']) || empty($state['configured'])) return false;
    $dir = avian_device_dir();
    if (!avian_device_dir_is_valid($dir) || !avian_device_policy()['ok']) return false;
    // Signing in again replaces this browser's old entry rather than adding one.
    $old = avian_device_cookie_parts();
    if ($old !== null) @unlink($dir . '/' . $old[0]);
    avian_device_prune($dir, $state, AVIAN_DEVICE_MAX - 1);
    try {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
    } catch (Throwable $error) {
        return false;
    }
    $now = time();
    $expires = $now + avian_device_idle_seconds();
    if (!avian_device_write($dir, $selector, [
        'v' => 1,
        'hash' => hash('sha256', $validator),
        'bind' => avian_device_binding($state, $selector),
        'created' => $now,
        'seen' => $now,
        'expires' => $expires,
        'label' => avian_device_label($server),
    ])) return false;
    $_COOKIE[AVIAN_DEVICE_COOKIE] = $selector . '.' . $validator;
    avian_device_set_cookie($server, $selector . '.' . $validator, $expires);
    return true;
}

/**
 * The stored record for this browser's device cookie, or null. A cookie that
 * names a dead record (expired, old password) deletes that record.
 *
 * @return array{selector:string,record:array<string,mixed>}|null
 */
function avian_device_current(array $server, array $state): ?array {
    if (empty($state['valid']) || empty($state['configured']) || !avian_device_policy()['ok']) return null;
    $parts = avian_device_cookie_parts();
    if ($parts === null) return null;
    [$selector, $validator] = $parts;
    $dir = avian_device_dir();
    if (!avian_device_dir_is_valid($dir)) return null;
    $path = $dir . '/' . $selector;
    $record = avian_device_read($path);
    if ($record === null) return null;
    if (!avian_device_record_live($record, $selector, $state, time())) {
        @unlink($path);
        return null;
    }
    // A wrong validator for a real selector is someone else's guess, not this
    // device's expiry: leave the record alone.
    if (!hash_equals($record['hash'], hash('sha256', $validator))) return null;
    return ['selector' => $selector, 'record' => $record];
}

function avian_device_remembered(array $server, ?array $state = null): bool {
    return avian_device_current($server, $state ?? avian_admin_state()) !== null;
}

/** Turn a remembered device into a fresh admin session for this request. */
function avian_device_restore_session(array $server, array $state): bool {
    $device = avian_device_current($server, $state);
    if ($device === null) return false;
    if (!avian_create_admin_session($server, $state)) return false;
    // Later checks in this same request read the session from $_COOKIE.
    $_COOKIE[AVIAN_ADMIN_SESSION_NAME] = session_id();

    $now = time();
    $record = $device['record'];
    if ($now - $record['seen'] >= avian_device_extend_every()) {
        $record['seen'] = $now;
        $record['expires'] = min(
            $now + avian_device_idle_seconds(),
            $record['created'] + AVIAN_DEVICE_ABSOLUTE_SECONDS
        );
        if (avian_device_write(avian_device_dir(), $device['selector'], $record)) {
            avian_device_set_cookie($server, (string)$_COOKIE[AVIAN_DEVICE_COOKIE], $record['expires']);
        }
    }
    return true;
}

/** Forget this browser (lock / sign out). */
function avian_device_forget(array $server): void {
    $parts = avian_device_cookie_parts();
    $dir = avian_device_dir();
    if ($parts !== null && avian_device_dir_is_valid($dir)) {
        $path = $dir . '/' . $parts[0];
        $record = avian_device_read($path);
        // Only the holder of the validator can delete a device.
        if ($record !== null && hash_equals($record['hash'], hash('sha256', $parts[1]))) @unlink($path);
    }
    if (array_key_exists(AVIAN_DEVICE_COOKIE, $_COOKIE)) avian_device_expire_cookie($server);
}

/** How many devices are remembered right now. */
function avian_device_count(array $state): int {
    $dir = avian_device_dir();
    if (!avian_device_dir_is_valid($dir) || !avian_device_policy()['ok']) return 0;
    avian_device_prune($dir, $state, AVIAN_DEVICE_MAX);
    $count = 0;
    foreach (@scandir($dir) ?: [] as $name) {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $name) === 1) $count++;
    }
    return $count;
}
