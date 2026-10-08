<?php
// AvianVisitors - web app manifest. The installed app is named after the
// station's SITE_NAME, so a home-screen icon always matches the site title.
// Public on purpose: browsers fetch manifests without credentials, and the
// name is already public through birdnet-api.php.

declare(strict_types=1);

define('AVIAN_BIRDNET_API_LIBRARY_ONLY', true);
require __DIR__ . '/birdnet-api.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');

$name = publicSiteName($CONF_PATH);

echo json_encode([
    'id' => '/',
    'name' => $name,
    'short_name' => $name,
    'description' => 'A live bird collage from your window.',
    'start_url' => '/',
    'scope' => '/',
    'display' => 'standalone',
    'background_color' => '#fcfcfb',
    'theme_color' => '#fcfcfb',
    'icons' => [
        ['src' => '/favicon.png', 'sizes' => '256x256', 'type' => 'image/png', 'purpose' => 'any'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
