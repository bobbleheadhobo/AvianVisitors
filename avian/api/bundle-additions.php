<?php
// Station-owned drawings that fill gaps in a downloaded bundle.
//
// A downloaded bundle is immutable and hash-checked, so a bird it has no
// drawing for cannot be added to it. Drawings made here in the bundle's style
// live beside it instead, one folder per bundle id:
//
//   /var/lib/avian-visitors/bundle-additions/<bundle-id>/additions.json
//   /var/lib/avian-visitors/bundle-additions/<bundle-id>/<sha256>.png
//
// additions.json: {"schema_version": 1, "bundle_id": ..., "assets": {slug:
// {"perched"|"flight": {"sha256", "bytes"}}}, "dims": {...}, "masks": {...}},
// the tables in the bundle's own format. An addition only ever fills a
// species the bundle lacks; the bundle's own art always wins. Anything
// malformed is ignored, so a bad addition leaves the bundle as it was.
// Installed by dev/bundle-addon.py, root-owned. Not an endpoint: Caddy's
// allow-list keeps it unreachable.

declare(strict_types=1);

require_once __DIR__ . '/bundle-runtime.php';

const AVIAN_BUNDLE_ADDITIONS_ROOT_DEFAULT = '/var/lib/avian-visitors/bundle-additions';
const AVIAN_BUNDLE_ADDITIONS_MAX_BYTES = 1_048_576;
const AVIAN_BUNDLE_ADDITIONS_MAX_SPECIES = 500;

function avian_bundle_additions_root(): string {
    $configured = getenv('AVIAN_BUNDLE_ADDITIONS_ROOT');
    $root = is_string($configured) && $configured !== ''
        ? $configured : AVIAN_BUNDLE_ADDITIONS_ROOT_DEFAULT;
    if ($root[0] !== '/' || strlen($root) > 512 || str_contains($root, "\0")
        || in_array('..', explode('/', $root), true)) return '';
    return rtrim($root, '/');
}

/** Not writable by group or others, and root's own in production. */
function avian_bundle_additions_safe(array $stat, int $type): bool {
    $production = avian_bundle_additions_root() === AVIAN_BUNDLE_ADDITIONS_ROOT_DEFAULT;
    return avian_bundle_mode_type($stat) === $type
        && (((int)($stat['mode'] ?? 0)) & 0022) === 0
        && ($type !== 0100000 || (int)($stat['nlink'] ?? 0) === 1)
        && (!$production || ((int)($stat['uid'] ?? -1) === 0 && (int)($stat['gid'] ?? -1) === 0));
}

function avian_bundle_additions_directory(string $bundleId): string {
    $root = avian_bundle_additions_root();
    if ($root === '' || !avian_bundle_id_valid($bundleId)) return '';
    foreach ([$root, $root . '/' . $bundleId] as $directory) {
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        if (!is_array($stat) || !avian_bundle_additions_safe($stat, 0040000)) return '';
    }
    return $root . '/' . $bundleId;
}

/** @return array{0:resource,1:array}|null */
function avian_bundle_additions_open(string $path, int $maxBytes): ?array {
    clearstatcache(true, $path);
    $before = @lstat($path);
    if (!is_array($before) || !avian_bundle_additions_safe($before, 0100000)
        || (int)$before['size'] > $maxBytes) return null;
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return null;
    $opened = fstat($handle);
    if (!is_array($opened) || !avian_bundle_additions_safe($opened, 0100000)
        || (int)$opened['dev'] !== (int)$before['dev'] || (int)$opened['ino'] !== (int)$before['ino']
        || (int)$opened['size'] !== (int)$before['size']) {
        fclose($handle);
        return null;
    }
    return [$handle, $opened];
}

function avian_bundle_additions_geometry_valid(string $kind, $value): bool {
    if ($kind === 'dims') {
        return is_array($value) && array_is_list($value) && count($value) === 2
            && is_int($value[0]) && is_int($value[1])
            && $value[0] >= 1 && $value[1] >= 1 && $value[0] <= 560 && $value[1] <= 560;
    }
    if (!is_array($value) || array_is_list($value)) return false;
    $keys = array_keys($value);
    sort($keys, SORT_STRING);
    if ($keys !== ['bits', 'h', 'w']) return false;
    $w = $value['w'];
    $h = $value['h'];
    $bits = $value['bits'];
    $decoded = is_string($bits) && strlen($bits) <= 2048 ? base64_decode($bits, true) : false;
    return is_int($w) && is_int($h) && $w >= 1 && $h >= 1 && $w <= 93 && $h <= 93
        && is_string($decoded) && strlen($decoded) === intdiv($w * $h + 7, 8)
        && trim($decoded, "\0") !== '';
}

/**
 * The additions for a downloaded bundle, less any species its index already
 * draws. Null when there are none or they are malformed.
 *
 * @return array{revision:string,assets:array,dims:array,masks:array}|null
 */
function avian_bundle_additions(array $active, ?array $index = null): ?array {
    if (!empty($active['included']) || !isset($active['id'])) return null;
    $directory = avian_bundle_additions_directory((string)$active['id']);
    if ($directory === '') return null;
    $opened = avian_bundle_additions_open($directory . '/additions.json', AVIAN_BUNDLE_ADDITIONS_MAX_BYTES);
    if ($opened === null) return null;
    [$handle, $stat] = $opened;
    $raw = stream_get_contents($handle, AVIAN_BUNDLE_ADDITIONS_MAX_BYTES + 1);
    fclose($handle);
    if (!is_string($raw) || strlen($raw) !== (int)$stat['size']) return null;
    try { $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { return null; }
    if (!is_array($value) || array_is_list($value)) return null;
    $keys = array_keys($value);
    sort($keys, SORT_STRING);
    if ($keys !== ['assets', 'bundle_id', 'dims', 'masks', 'schema_version']
        || $value['schema_version'] !== 1 || $value['bundle_id'] !== $active['id']
        || !is_array($value['assets']) || array_is_list($value['assets'])
        || count($value['assets']) > AVIAN_BUNDLE_ADDITIONS_MAX_SPECIES
        || !is_array($value['dims']) || !is_array($value['masks'])) return null;

    $assets = [];
    $dims = [];
    $masks = [];
    $expected = [];
    foreach ($value['assets'] as $slug => $poses) {
        if (!is_string($slug) || !avian_bundle_slug_valid($slug)
            || !is_array($poses) || array_is_list($poses) || $poses === []) return null;
        foreach ($poses as $pose => $object) {
            if (($pose !== 'perched' && $pose !== 'flight') || !is_array($object)) return null;
            $objectKeys = array_keys($object);
            sort($objectKeys, SORT_STRING);
            if ($objectKeys !== ['bytes', 'sha256'] || !is_string($object['sha256'])
                || !avian_bundle_revision_valid($object['sha256']) || !is_int($object['bytes'])
                || $object['bytes'] < 67 || $object['bytes'] > AVIAN_BUNDLE_MAX_OBJECT_BYTES) return null;
            $expected[] = $slug . ($pose === 'flight' ? '-2' : '');
        }
        if (isset($index['assets'][$slug])) continue;   // the bundle's own art wins
        $assets[$slug] = $poses;
    }
    $dimKeys = array_keys($value['dims']);
    $maskKeys = array_keys($value['masks']);
    sort($expected, SORT_STRING);
    sort($dimKeys, SORT_STRING);
    sort($maskKeys, SORT_STRING);
    if ($dimKeys !== $expected || $maskKeys !== $expected) return null;
    foreach ($expected as $key) {
        if (!avian_bundle_additions_geometry_valid('dims', $value['dims'][$key])
            || !avian_bundle_additions_geometry_valid('masks', $value['masks'][$key])) return null;
        $slug = str_ends_with($key, '-2') ? substr($key, 0, -2) : $key;
        if (!isset($assets[$slug])) continue;
        $dims[$key] = $value['dims'][$key];
        $masks[$key] = $value['masks'][$key];
    }
    return ['revision' => hash('sha256', $raw), 'assets' => $assets, 'dims' => $dims, 'masks' => $masks];
}

/**
 * Open an addition's PNG for $slug and pose 1 (perched) or 2 (flight),
 * checked against its recorded hash.
 *
 * @return array{0:resource,1:array,2:string}|null handle, stat, sha256
 */
function avian_bundle_open_addition(array $active, ?array $index, string $slug, int $pose): ?array {
    $additions = avian_bundle_additions($active, $index);
    $poseName = $pose === 1 ? 'perched' : ($pose === 2 ? 'flight' : '');
    $object = $additions['assets'][$slug][$poseName] ?? null;
    if (!is_array($object)) return null;
    $sha = $object['sha256'];
    $opened = avian_bundle_additions_open(
        avian_bundle_additions_directory((string)$active['id']) . '/' . $sha . '.png',
        AVIAN_BUNDLE_MAX_OBJECT_BYTES
    );
    if ($opened === null) return null;
    [$handle, $stat] = $opened;
    $header = fread($handle, 24);
    rewind($handle);
    $hash = hash_init('sha256');
    if ((int)$stat['size'] !== $object['bytes'] || !is_string($header)
        || avian_bundle_png_dimensions($header) === null
        || hash_update_stream($hash, $handle) !== $object['bytes']
        || !hash_equals($sha, hash_final($hash))) {
        fclose($handle);
        return null;
    }
    rewind($handle);
    return [$handle, $stat, $sha];
}
