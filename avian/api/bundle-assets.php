<?php
// Public, read-only geometry inventory for the exact active bird bundle.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/bundle-runtime.php';
require_once __DIR__ . '/bundle-additions.php';

function avian_bundle_assets_fail(int $status, string $error): void {
    http_response_code($status);
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    avian_bundle_assets_fail(405, 'GET required');
}
foreach ($_GET as $key => $value) {
    if (($key !== 'v' && $key !== 't') || !is_string($value)
        || preg_match('/\A[0-9A-Za-z._-]{1,80}\z/D', $value) !== 1) {
        avian_bundle_assets_fail(400, 'unsupported query');
    }
}

$active = avian_bundle_active_state();
if ($active['status'] !== 'ok') {
    avian_bundle_assets_fail(503, 'active bundle state is unavailable');
}
$tableLock = !empty($active['included'])
    ? avian_bundle_open_included_table_read_lock()
    : null;
if ($tableLock === false) {
    avian_bundle_assets_fail(503, 'active bundle inventory is changing');
}

// The ETag needs only the file-identity revision, so an unchanged client
// (the frame polls this every few minutes) gets its 304 before the ~820 KB
// tables are read and decoded.
$contentRevision = avian_bundle_content_revision($active, $tableLock);
if ($contentRevision === null) {
    avian_bundle_close_included_table_read_lock($tableLock);
    avian_bundle_assets_fail(503, 'active bundle inventory is unavailable');
}
// Station additions (bundle-additions.php) change the tables too, so they
// change the tag: the frame redraws when a drawing is added.
$additions = avian_bundle_additions($active);
$etag = '"avian-bundle-' . $active['revision']
    . ($active['included'] || isset($active['selection_revision']) ? '-' . $contentRevision : '')
    . ($additions !== null ? '-add-' . substr($additions['revision'], 0, 16) : '') . '"';
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    avian_bundle_close_included_table_read_lock($tableLock);
    http_response_code(304);
    exit;
}

$dims = avian_bundle_read_table($active, 'dims');
$masks = avian_bundle_read_table($active, 'masks');
if ($dims === null || $masks === null) {
    avian_bundle_close_included_table_read_lock($tableLock);
    avian_bundle_assets_fail(503, 'active bundle inventory is unavailable');
}
$dimKeys = array_keys($dims);
$maskKeys = array_keys($masks);
sort($dimKeys, SORT_STRING);
sort($maskKeys, SORT_STRING);
if ($dimKeys !== $maskKeys) {
    avian_bundle_close_included_table_read_lock($tableLock);
    avian_bundle_assets_fail(503, 'active bundle inventory is unavailable');
}

$species = [];
$poseCount = count($dims);
if (!$active['included']) {
    $index = avian_bundle_active_index($active);
    if ($index === null) avian_bundle_assets_fail(503, 'active bundle inventory is unavailable');
    $expected = [];
    foreach ($index['assets'] as $slug => $poses) {
        $species[$slug] = true;
        foreach ($poses as $pose => $_object) {
            $number = $pose === 'perched' ? 1 : ($pose === 'flight' ? 2 : (int)substr($pose, 8));
            $expected[] = $slug . ($number === 1 ? '' : '-' . $number);
        }
    }
    sort($expected, SORT_STRING);
    $actual = array_keys($dims);
    sort($actual, SORT_STRING);
    if ($expected !== $actual) {
        avian_bundle_assets_fail(503, 'active bundle inventory is inconsistent');
    }
    $additions = avian_bundle_additions($active, $index);
    if ($additions !== null) {
        foreach (array_keys($additions['assets']) as $slug) $species[$slug] = true;
        $dims += $additions['dims'];
        $masks += $additions['masks'];
        $poseCount = count($dims);
    }
} else {
    foreach (array_keys($dims) as $key) {
        $species[str_ends_with($key, '-2') ? substr($key, 0, -2) : $key] = true;
    }
}

avian_bundle_close_included_table_read_lock($tableLock);

echo json_encode([
    'ok' => true,
    'active' => [
        'id' => $active['id'],
        'version' => $active['version'],
        'revision' => $active['revision'],
        'included' => $active['included'],
        'name' => $active['name'],
        'content_revision' => $contentRevision,
        'species_count' => count($species),
        'pose_count' => $poseCount,
    ] + (isset($active['selection_revision']) ? ['selection_revision' => $active['selection_revision']] : []),
    'dims' => $dims,
    'masks' => $masks,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
