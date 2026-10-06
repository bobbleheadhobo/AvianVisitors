<?php
// A pass into the classic BirdNET-Pi pages for an unlocked admin session.
//
// The classic pages sit behind HTTP Basic auth in Caddy. Some browsers and
// reverse proxies never show the Basic login box, so the menu's "classic"
// link comes here first:
//
//   GET ?open=1    admin session required: set a signed "avian_classic"
//                  cookie (8 hours, path /) and go on to /index.php
//   GET ?check=1   Caddy's forward_auth for classic requests that carry
//                  the cookie: 204 when it (or Basic credentials) is valid,
//                  otherwise 401 with the usual Basic challenge
//
// The cookie is signed with the admin password's stored verifier and the
// auth epoch, so changing the password or the auth policy voids every pass.

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';

const CLASSIC_COOKIE = 'avian_classic';
const CLASSIC_TTL = 28800;

function classic_signature(array $state, int $expires): string {
    return hash_hmac('sha256', "classic|$expires|" . (string)$state['epoch'], (string)$state['verifier']);
}

/** Classic pages are open at all (a password is set, LAN policy off). */
function classic_available(array $state): bool {
    return !empty($state['valid']) && !empty($state['configured']) && empty($state['required']);
}

function classic_cookie_valid(array $state, string $value): bool {
    if (preg_match('/\A([0-9]{10})\.([a-f0-9]{64})\z/D', $value, $m) !== 1) return false;
    $expires = (int)$m[1];
    if ($expires < time() || $expires > time() + CLASSIC_TTL) return false;
    return hash_equals(classic_signature($state, $expires), $m[2]);
}

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$state = avian_admin_state();

if (isset($_GET['check'])) {
    if (classic_available($state)) {
        if (classic_cookie_valid($state, (string)($_COOKIE[CLASSIC_COOKIE] ?? ''))) {
            http_response_code(204);
            exit;
        }
        // A stale pass: fall back to the Basic credentials the gate would
        // have asked for without the cookie.
        $user = $_SERVER['PHP_AUTH_USER'] ?? null;
        $pass = $_SERVER['PHP_AUTH_PW'] ?? null;
        if (is_string($user) && is_string($pass) && hash_equals('birdnet', $user)
            && avian_admin_password_matches($pass, $state)) {
            http_response_code(204);
            exit;
        }
    }
    header('WWW-Authenticate: Basic realm="restricted"');
    http_response_code(401);
    exit;
}

if (isset($_GET['open'])) {
    avian_require_admin();
    if (!classic_available($state)) avian_api_fail(404, 'the classic pages are switched off');
    $expires = time() + CLASSIC_TTL;
    setcookie(CLASSIC_COOKIE, $expires . '.' . classic_signature($state, $expires), [
        'expires' => $expires,
        'path' => '/',
        'secure' => avian_request_is_https($_SERVER),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Location: /index.php', true, 303);
    exit;
}

avian_api_fail(400, 'unknown request');
