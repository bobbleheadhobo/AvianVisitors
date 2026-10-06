<?php
// The analyzer's three species lists, edited from Tools.
//
//   exclude    never log these species
//   include    log only these species (an empty list means "all")
//   whitelist  log these even when the range filter says they shouldn't be here
//
// Each file holds one "Scientific name_Common name" line per species, the
// format BirdNET-Pi's analysis reads on every recording (no restart needed).
//
//   GET                       the three lists
//   GET  ?q=<text>            species search over the model's labels,
//                             birds this station has heard first
//   POST {action:"add"|"remove", list, sci}

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';

avian_require_admin();

$home = dirname(__DIR__, 2);
const SPECIES_LISTS = ['exclude', 'include', 'whitelist'];

function species_json(int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function species_list_path(string $home, string $list): string {
    return "$home/{$list}_species_list.txt";
}

/** @return array<string,string> sci => "sci_com" label line */
function species_labels(string $home): array {
    static $labels = null;
    if ($labels !== null) return $labels;
    $labels = [];
    $lines = @file("$home/model/labels.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $parts = explode('_', $line, 2);
        if (count($parts) === 2 && $parts[0] !== '') $labels[$parts[0]] = $line;
    }
    return $labels;
}

/** @return array<int,array{sci:string,com:string}> */
function species_list_read(string $home, string $list): array {
    $out = [];
    $lines = @file(species_list_path($home, $list), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = explode('_', $line, 2);
        $out[] = ['sci' => $parts[0], 'com' => $parts[1] ?? $parts[0]];
    }
    usort($out, fn($a, $b) => strcasecmp($a['com'], $b['com']));
    return $out;
}

function species_heard(string $home): array {
    static $heard = null;
    if ($heard !== null) return $heard;
    $heard = [];
    try {
        $db = new SQLite3("$home/scripts/birds.db", SQLITE3_OPEN_READONLY);
        $db->busyTimeout(1000);
        $res = $db->query('SELECT Sci_Name, COUNT(*) n FROM detections GROUP BY Sci_Name');
        while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) $heard[$row['Sci_Name']] = (int)$row['n'];
        $db->close();
    } catch (Throwable $e) {
    }
    return $heard;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    if (isset($_GET['q'])) {
        $q = trim((string)$_GET['q']);
        if (mb_strlen($q) < 2) species_json(200, ['ok' => true, 'results' => []]);
        $heard = species_heard($home);
        $hits = [];
        foreach (species_labels($home) as $sci => $line) {
            $com = substr($line, strlen($sci) + 1);
            $at = stripos($com, $q);
            $atSci = stripos($sci, $q);
            if ($at === false && $atSci === false) continue;
            // Heard birds first, then names that start with the text, then the rest.
            $rank = (isset($heard[$sci]) ? 0 : 2) + (($at === 0 || $atSci === 0) ? 0 : 1);
            $hits[] = ['sci' => $sci, 'com' => $com, 'heard' => $heard[$sci] ?? 0, 'r' => $rank];
        }
        usort($hits, fn($a, $b) => [$a['r'], -$a['heard'], $a['com']] <=> [$b['r'], -$b['heard'], $b['com']]);
        $hits = array_map(fn($h) => ['sci' => $h['sci'], 'com' => $h['com'], 'heard' => $h['heard']], array_slice($hits, 0, 12));
        species_json(200, ['ok' => true, 'results' => $hits]);
    }
    $lists = [];
    $writable = [];
    foreach (SPECIES_LISTS as $list) {
        $path = species_list_path($home, $list);
        $lists[$list] = species_list_read($home, $list);
        $writable[$list] = is_file($path) && is_writable($path);
    }
    species_json(200, ['ok' => true, 'lists' => $lists, 'writable' => $writable]);
}

avian_require_json_action();
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) species_json(400, ['ok' => false, 'error' => 'bad json']);
$action = (string)($body['action'] ?? '');
$list = (string)($body['list'] ?? '');
$sci = trim((string)($body['sci'] ?? ''));
if (!in_array($action, ['add', 'remove'], true) || !in_array($list, SPECIES_LISTS, true) || $sci === '') {
    species_json(400, ['ok' => false, 'error' => 'action, list and species are required']);
}
$labels = species_labels($home);
if ($action === 'add' && !isset($labels[$sci])) species_json(404, ['ok' => false, 'error' => 'unknown species']);

$path = species_list_path($home, $list);
if (!is_file($path) || !is_writable($path)) {
    species_json(503, ['ok' => false, 'error' => "the $list list file is missing or not writable"]);
}
// Rewrite in place under a lock (the folder itself is not writable by the
// web server, so no rename). The analyzer reads whole lines, so a reader
// never sees a half-written entry.
$h = fopen($path, 'c+');
if (!$h || !flock($h, LOCK_EX)) species_json(500, ['ok' => false, 'error' => 'could not lock the list']);
$lines = array_filter(array_map('trim', explode("\n", (string)stream_get_contents($h))), 'strlen');
$lines = array_values(array_filter($lines, fn($l) => explode('_', $l, 2)[0] !== $sci));
if ($action === 'add') $lines[] = $labels[$sci];
ftruncate($h, 0);
rewind($h);
fwrite($h, $lines ? implode("\n", $lines) . "\n" : '');
fflush($h);
flock($h, LOCK_UN);
fclose($h);
species_json(200, ['ok' => true, 'list' => $list, 'entries' => species_list_read($home, $list)]);
