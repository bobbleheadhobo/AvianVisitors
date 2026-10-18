<?php
declare(strict_types=1);

// The stats "by hour" ledger follows the window picker: 7D and ALL sum each
// hour of the day across the window. It used to cover only the current day,
// so every window read "no detections heard" each morning before the first
// bird.
define('AVIAN_BIRDNET_API_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/avian/api/birdnet-api.php';

$checks = 0;
function checkHourly(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$db = new SQLite3(':memory:');
$db->exec('CREATE TABLE detections (Date TEXT, Time TEXT, Sci_Name TEXT, Com_Name TEXT)');
$add = function (string $date, string $time, string $sci, string $com) use ($db): void {
    $st = $db->prepare('INSERT INTO detections VALUES (:d, :t, :s, :c)');
    $st->bindValue(':d', $date); $st->bindValue(':t', $time);
    $st->bindValue(':s', $sci); $st->bindValue(':c', $com);
    $st->execute();
};
$add('2026-10-17', '07:10:00', 'Haemorhous mexicanus', 'House Finch');
$add('2026-10-17', '07:40:00', 'Haemorhous mexicanus', 'House Finch');
$add('2026-10-16', '07:05:00', 'Haemorhous mexicanus', 'House Finch');
$add('2026-10-16', '17:00:00', 'Psaltriparus minimus', 'Bushtit');
$add('2026-09-01', '09:00:00', 'Turdus migratorius', 'American Robin');

// Early morning, nothing heard yet today.
$ctx = ['date' => '2026-10-18', 'today' => '2026-10-18', 'is_today' => true, 'anchor' => '2026-10-18 05:45:00'];
$hoursOf = function (array $species): array {
    $out = [];
    foreach ($species['hours'] as $h) $out[$h['hour']] = $h['n'];
    return $out;
};

$day = hourlyLedger($db, $ctx, 24, false, 15);
checkHourly($day['span'] === 'day' && $day['species'] === [], '24H is today only, and empty before the first bird');

$week = hourlyLedger($db, $ctx, 168, false, 15);
checkHourly($week['span'] === 'window', '7D reports a window span');
checkHourly(count($week['species']) === 2, '7D holds the week\'s species, not today\'s');
checkHourly($week['species'][0]['com'] === 'House Finch' && $week['species'][0]['total'] === 3, '7D sums across days, busiest first');
checkHourly($hoursOf($week['species'][0]) === [7 => 3], '7D adds the same hour of different days together');

$all = hourlyLedger($db, $ctx, 1000000, false, 15);
checkHourly($all['span'] === 'all' && count($all['species']) === 3, 'ALL reaches the oldest detection');

$limited = hourlyLedger($db, $ctx, 1000000, false, 1);
checkHourly(count($limited['species']) === 1 && $limited['species'][0]['total'] === 3, 'limit keeps the top species with every hour');

$past = hourlyLedger($db, ['date' => '2026-10-16', 'today' => '2026-10-18', 'is_today' => false, 'anchor' => '2026-10-16 23:59:59'], 168, false, 15);
checkHourly($past['species'][0]['total'] === 1 && count($past['species']) === 2, 'a past date ends the window at that date');

$scoped = hourlyLedger($db, ['date' => '2026-10-17'] + $ctx, 1000000, true, 15);
checkHourly($scoped['span'] === 'day' && $scoped['species'][0]['total'] === 2, 'an educator scope keeps its one day');

echo "hourly window tests passed ({$checks} checks)\n";
