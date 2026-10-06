<?php
/* Avian Visitors stats for the classic pages (views.php?view=Streamlit).
 *
 * Server-rendered from birds.db: the year calendar is the range filter, and
 * every panel below answers for the chosen span. Ranges travel in the URL
 * (from, to, species) so every view is a plain link. Styles live in
 * homepage/static/avian-stats.css.
 */

if (!function_exists('get_db')) {
  require_once 'scripts/common.php';
}

$db = get_db();
$config = get_config();
$lat = floatval($config['LATITUDE'] ?? 0);
$lon = floatval($config['LONGITUDE'] ?? 0);

/* ---- helpers --------------------------------------------------------- */

function as_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function as_n($n) { return number_format((float)$n); }
function as_valid_date($s) {
  if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
  $d = DateTime::createFromFormat('!Y-m-d', $s);
  return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}
function as_day($ymd, $fmt = 'D j M') { return date($fmt, strtotime($ymd)); }
function as_url($params) {
  $base = ['view' => 'Streamlit'];
  return 'views.php?' . http_build_query(array_merge($base, array_filter($params, function ($v) { return $v !== null && $v !== ''; })));
}
function as_rows($db, $sql, $bind = []) {
  $st = $db->prepare($sql);
  foreach ($bind as $k => $v) $st->bindValue($k, $v);
  $res = $st->execute();
  $out = [];
  while ($r = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
  return $out;
}
function as_minutes($time) { return intval(substr($time, 0, 2)) * 60 + intval(substr($time, 3, 2)); }
function as_clock($min) { $min = (($min % 1440) + 1440) % 1440; return sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }
function as_slug($sci) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($sci)), '-'); }
function as_sun($ymd, $lat, $lon) {
  static $cache = [];
  if (isset($cache[$ymd])) return $cache[$ymd];
  if (!$lat && !$lon) return $cache[$ymd] = null;
  $info = date_sun_info(strtotime($ymd . ' 12:00'), $lat, $lon);
  if (!is_int($info['sunrise'] ?? null) || !is_int($info['sunset'] ?? null)) return $cache[$ymd] = null;
  $rise = intval(date('G', $info['sunrise'])) * 60 + intval(date('i', $info['sunrise']));
  $set = intval(date('G', $info['sunset'])) * 60 + intval(date('i', $info['sunset']));
  // When the station clock isn't in local time (e.g. UTC for a station in
  // the Americas), sunset lands before sunrise. Sun-relative figures would
  // then be fiction, so report no sun rather than a wrong one.
  if ($set <= $rise) return $cache[$ymd] = null;
  return $cache[$ymd] = ['rise' => $rise, 'set' => $set];
}
function as_streaks($dates) {
  // Longest and current run of consecutive days in a sorted date list.
  $best = 0; $bestEnd = null; $run = 0; $prev = null;
  foreach ($dates as $d) {
    $t = strtotime($d);
    $run = ($prev !== null && $t - $prev <= 90000 && $t - $prev >= 82800) ? $run + 1 : 1;
    if ($run > $best) { $best = $run; $bestEnd = $d; }
    $prev = $t;
  }
  return ['best' => $best, 'bestEnd' => $bestEnd, 'last' => $run, 'lastEnd' => $prev ? date('Y-m-d', $prev) : null];
}

/* ---- range --------------------------------------------------------- */

$today = date('Y-m-d');
$first = $db->querySingle('SELECT MIN(Date) FROM detections');
$preset = $_GET['range'] ?? '';
$from = as_valid_date($_GET['from'] ?? '');
$to = as_valid_date($_GET['to'] ?? '');
if (!$from || !$to) {
  $presets = ['7' => 6, '30' => 29, '90' => 89];
  if ($preset === 'year') { $from = date('Y-01-01'); $to = $today; }
  elseif ($preset === 'all') { $from = $first ?: $today; $to = $today; }
  else { if (!isset($presets[$preset])) $preset = '30'; $from = date('Y-m-d', strtotime("-{$presets[$preset]} days")); $to = $today; }
} else {
  $preset = '';
}
if ($from > $to) { [$from, $to] = [$to, $from]; }
$spanDays = intval(round((strtotime($to) - strtotime($from)) / 86400)) + 1;
$prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($from . " -{$spanDays} days"));
$rangeBind = [':from' => $from, ':to' => $to];

/* ---- all-time daily totals (calendar + records) ------------------- */

$days = [];
foreach (as_rows($db, 'SELECT Date d, COUNT(*) n, COUNT(DISTINCT Sci_Name) s FROM detections GROUP BY Date ORDER BY Date') as $r) {
  $days[$r['d']] = ['n' => intval($r['n']), 's' => intval($r['s'])];
}

/* ---- species: all time and in range -------------------------------- */

$life = as_rows($db, 'SELECT Sci_Name sci, MAX(Com_Name) com, MIN(Date) first, MAX(Date) last, COUNT(*) n FROM detections GROUP BY Sci_Name');
$lifeBy = [];
foreach ($life as $r) $lifeBy[$r['sci']] = $r;

$inRange = as_rows($db, 'SELECT Sci_Name sci, MAX(Com_Name) com, COUNT(*) n, COUNT(DISTINCT Date) days, MAX(Date || " " || Time) last
  FROM detections WHERE Date BETWEEN :from AND :to GROUP BY Sci_Name ORDER BY n DESC, com', $rangeBind);
$prevCounts = [];
foreach (as_rows($db, 'SELECT Sci_Name sci, COUNT(*) n FROM detections WHERE Date BETWEEN :from AND :to GROUP BY Sci_Name', [':from' => $prevFrom, ':to' => $prevTo]) as $r) {
  $prevCounts[$r['sci']] = intval($r['n']);
}

$rangeTotal = 0; foreach ($inRange as $r) $rangeTotal += $r['n'];
$prevTotal = array_sum($prevCounts);
$newInRange = array_values(array_filter($life, function ($r) use ($from, $to) { return $r['first'] >= $from && $r['first'] <= $to; }));
usort($newInRange, function ($a, $b) { return strcmp($a['first'], $b['first']); });

$busiest = null;
foreach ($days as $d => $v) {
  if ($d < $from || $d > $to) continue;
  if (!$busiest || $v['n'] > $days[$busiest]['n']) $busiest = $d;
}

/* ---- time of day in range ------------------------------------------ */

$bins = array_fill(0, 48, 0);
$riseOffsets = [];
$speciesOffsets = [];
$hourBy = [];   // sci => [24]
foreach (as_rows($db, 'SELECT Date d, Time t, Sci_Name sci FROM detections WHERE Date BETWEEN :from AND :to', $rangeBind) as $r) {
  $m = as_minutes($r['t']);
  $bins[min(47, intdiv($m, 30))]++;
  $h = intdiv($m, 60);
  if (!isset($hourBy[$r['sci']])) $hourBy[$r['sci']] = array_fill(0, 24, 0);
  $hourBy[$r['sci']][$h]++;
  $sun = as_sun($r['d'], $lat, $lon);
  if ($sun) {
    $off = $m - $sun['rise'];
    if ($off >= -90 && $off <= 300) {
      $riseOffsets[] = $off;
      $speciesOffsets[$r['sci']][] = $off;
    }
  }
}
$mid = date('Y-m-d', intdiv(strtotime($from) + strtotime($to), 2));
$sunMid = as_sun($mid, $lat, $lon);
function as_median($a) { if (!$a) return null; sort($a); $c = count($a); return $c % 2 ? $a[intdiv($c, 2)] : intdiv($a[$c / 2 - 1] + $a[$c / 2], 2); }
$chorusOffset = count($riseOffsets) >= 5 ? as_median($riseOffsets) : null;
$earlyRisers = [];
foreach ($speciesOffsets as $sci => $offs) {
  if (count($offs) >= 3) $earlyRisers[$sci] = as_median($offs);
}
asort($earlyRisers);
$earlyRisers = array_slice($earlyRisers, 0, 3, true);
$peakBin = array_search(max($bins), $bins);

/* ---- trend series in range ---------------------------------------- */

$step = $spanDays > 730 ? 'month' : ($spanDays > 120 ? 'week' : 'day');
// A day or two on its own is one bar; show it inside the fortnight that
// ends with it so the span reads against its neighbours.
$trendFrom = $spanDays <= 3 ? date('Y-m-d', strtotime($to . ' -13 days')) : $from;
$series = [];
for ($t = strtotime($trendFrom); $t <= strtotime($to); $t += 86400) {
  $d = date('Y-m-d', $t);
  $key = $step === 'day' ? $d : ($step === 'week' ? date('o-\WW', $t) : date('Y-m', $t));
  if (!isset($series[$key])) $series[$key] = ['label' => $d, 'n' => 0, 's' => 0, 'days' => 0, 'in' => $d >= $from];
  $series[$key]['n'] += $days[$d]['n'] ?? 0;
  $series[$key]['s'] = max($series[$key]['s'], $days[$d]['s'] ?? 0);
  $series[$key]['days']++;
}
$series = array_values($series);
$seriesMax = max(1, max(array_column($series, 'n') ?: [0]));
$sMax = max(1, max(array_column($series, 's') ?: [0]));
$activeDays = 0; foreach ($days as $d => $v) if ($d >= $from && $d <= $to) $activeDays++;

/* ---- records (all time) -------------------------------------------- */

$records = [];
$byS = [];
if ($days) {
  $bd = null; $sd = null;
  foreach ($days as $d => $v) {
    if (!$bd || $v['n'] > $days[$bd]['n']) $bd = $d;
    if (!$sd || $v['s'] > $days[$sd]['s']) $sd = $d;
  }
  $run = as_streaks(array_keys($days));
  $records['busiest'] = $bd;
  $records['richest'] = $sd;
  $records['run'] = $run;
  // Most loyal: longest consecutive-day run for a single species.
  $loyal = null;
  foreach (as_rows($db, 'SELECT DISTINCT Sci_Name sci, Date d FROM detections ORDER BY sci, d') as $r) $byS[$r['sci']][] = $r['d'];
  foreach ($byS as $sci => $ds) {
    $s = as_streaks($ds);
    if (!$loyal || $s['best'] > $loyal['best']) $loyal = $s + ['sci' => $sci];
  }
  $records['loyal'] = $loyal;
  $records['early'] = as_rows($db, "SELECT Date d, Time t, Com_Name com, Sci_Name sci FROM detections WHERE Time >= '03:00:00' ORDER BY Time ASC LIMIT 1")[0] ?? null;
  $records['late'] = as_rows($db, "SELECT Date d, Time t, Com_Name com, Sci_Name sci FROM detections ORDER BY Time DESC LIMIT 1")[0] ?? null;
  $records['sure'] = as_rows($db, 'SELECT Date d, Time t, Com_Name com, Sci_Name sci, Confidence c FROM detections ORDER BY Confidence DESC LIMIT 1')[0] ?? null;
  $once = array_values(array_filter($life, function ($r) { return intval($r['n']) === 1; }));
  $records['once'] = $once;
  $newest = $life;
  usort($newest, function ($a, $b) { return strcmp($b['first'], $a['first']); });
  $records['newest'] = array_slice($newest, 0, 3);
  // Life list growth: cumulative species by first-heard date.
  $growth = [];
  $firsts = array_column($life, 'first');
  sort($firsts);
  foreach ($firsts as $i => $d) $growth[$d] = $i + 1;
  $records['growth'] = $growth;
}

/* ---- deep dive ------------------------------------------------------- */

$pick = $_GET['species'] ?? '';
if (!isset($lifeBy[$pick])) $pick = $inRange[0]['sci'] ?? ($life[0]['sci'] ?? '');
$dive = null;
if ($pick !== '') {
  $b = [':sci' => $pick];
  $dive = $lifeBy[$pick];
  $dive['days'] = intval(as_rows($db, 'SELECT COUNT(DISTINCT Date) n FROM detections WHERE Sci_Name = :sci', $b)[0]['n'] ?? 0);
  $dive['best'] = as_rows($db, 'SELECT Date d, Time t, Confidence c, File_Name f FROM detections WHERE Sci_Name = :sci ORDER BY Confidence DESC, Date DESC LIMIT 1', $b)[0] ?? null;
  $dive['recent'] = as_rows($db, 'SELECT Date d, Time t, Confidence c FROM detections WHERE Sci_Name = :sci ORDER BY Date DESC, Time DESC LIMIT 6', $b);
  $dive['hours'] = array_fill(0, 24, 0);
  foreach (as_rows($db, 'SELECT CAST(substr(Time,1,2) AS INTEGER) h, COUNT(*) n FROM detections WHERE Sci_Name = :sci GROUP BY h', $b) as $r) $dive['hours'][intval($r['h'])] = intval($r['n']);
  $dive['weeks'] = array_fill(0, 53, 0);
  foreach (as_rows($db, "SELECT CAST(strftime('%W', Date) AS INTEGER) w, COUNT(*) n FROM detections WHERE Sci_Name = :sci GROUP BY w", $b) as $r) $dive['weeks'][min(52, intval($r['w']))] = intval($r['n']);
  $dive['inRange'] = 0;
  foreach ($inRange as $r) if ($r['sci'] === $pick) $dive['inRange'] = intval($r['n']);
  $dive['streak'] = as_streaks($byS[$pick] ?? []);
  $slug = as_slug($pick);
  $art = dirname(__DIR__) . '/avian/assets/illustrations/' . $slug . '.png';
  $dive['art'] = file_exists($art) ? '/avian/assets/illustrations/' . $slug . '.png' : null;
}

/* ---- calendar geometry: 53 weeks ending this week ------------------ */

$calEnd = strtotime('saturday this week', strtotime($today));
if (date('w', strtotime($today)) == 6) $calEnd = strtotime($today);
$calStart = strtotime('-52 weeks -6 days', $calEnd);
$levels = array_values(array_filter(array_column($days, 'n')));
sort($levels);
$q = function ($p) use ($levels) { return $levels ? $levels[min(count($levels) - 1, intval(floor($p * (count($levels) - 1))))] : 0; };
$cut = [$q(.25), $q(.5), $q(.75)];
function as_level($n, $cut) { if ($n <= 0) return 0; if ($n <= $cut[0]) return 1; if ($n <= $cut[1]) return 2; if ($n <= $cut[2]) return 3; return 4; }

$sunDay = as_sun($to, $lat, $lon);
$sunMissing = ($lat || $lon) && !$sunMid;
$fmtRange = $from === $to ? as_day($from, 'l j F Y') : as_day($from, date('Y', strtotime($from)) === date('Y', strtotime($to)) ? 'j M' : 'j M Y') . ' – ' . as_day($to, 'j M Y');
?>
<link rel="stylesheet" href="static/avian-stats.css?v=<?php echo filemtime(dirname(__DIR__) . '/homepage/static/avian-stats.css'); ?>">
<div class="av-stats">

  <header class="as-head">
    <div class="as-range">
      <h1 class="as-span"><?php echo as_h($fmtRange); ?></h1>
    </div>
    <nav class="as-presets" aria-label="range">
      <?php foreach (['7' => '7 days', '30' => '30 days', '90' => '90 days', 'year' => 'this year', 'all' => 'all time'] as $k => $label): ?>
        <a href="<?php echo as_h(as_url(['range' => $k, 'species' => $_GET['species'] ?? null])); ?>"<?php echo $preset === (string)$k ? ' aria-current="true"' : ''; ?>><?php echo as_h($label); ?></a>
      <?php endforeach; ?>
    </nav>
  </header>

  <section class="as-cal" aria-label="year calendar">
    <div class="as-cal-scroll" data-cal-scroll>
      <div class="as-cal-months" aria-hidden="false">
        <?php
        $lastMonth = '';
        for ($w = 0; $w < 53; $w++) {
          $t = strtotime("+{$w} weeks", $calStart);
          $m = date('Y-m', strtotime('+6 days', $t));
          if ($m !== $lastMonth) {
            $mStart = $m . '-01'; $mEnd = date('Y-m-t', strtotime($mStart));
            echo '<a style="grid-column:' . ($w + 1) . '" href="' . as_h(as_url(['from' => $mStart, 'to' => min($mEnd, $today), 'species' => $_GET['species'] ?? null])) . '">' . as_h(date('M', strtotime($mStart))) . '</a>';
            $lastMonth = $m;
          }
        }
        ?>
      </div>
      <div class="as-cal-grid">
        <?php
        for ($t = $calStart; $t <= $calEnd; $t += 86400) {
          $d = date('Y-m-d', $t);
          if ($d > $today) { echo '<span class="as-day is-future" aria-hidden="true"></span>'; continue; }
          $v = $days[$d] ?? ['n' => 0, 's' => 0];
          $lvl = as_level($v['n'], $cut);
          $in = $d >= $from && $d <= $to;
          $title = as_day($d, 'D j M Y') . ' · ' . ($v['n'] ? as_n($v['n']) . ' detections · ' . $v['s'] . ' species' : 'no birds');
          echo '<a class="as-day l' . $lvl . ($in ? ' is-in' : '') . ($in && $from === $to ? ' is-pick' : '') . ($d === $today ? ' is-today' : '') . '" href="' . as_h(as_url(['from' => $d, 'to' => $d, 'species' => $_GET['species'] ?? null])) . '" title="' . as_h($title) . '" aria-label="' . as_h($title) . '"></a>';
        }
        ?>
      </div>
      <p class="as-cal-key" aria-hidden="true"><span>fewer</span><i class="l1"></i><i class="l2"></i><i class="l3"></i><i class="l4"></i><span>more birds</span><?php if ($from !== $to): ?><span class="as-cal-key-span"><i class="is-span"></i>in this span</span><?php endif; ?></p>
    </div>
  </section>

  <p class="as-summary">
    <?php if ($rangeTotal === 0): ?>
      No birds were heard <?php echo $from === $to ? 'on ' . as_h(as_day($from, 'l j F')) : 'between ' . as_h(as_day($from)) . ' and ' . as_h(as_day($to)); ?>. Try <a href="<?php echo as_h(as_url(['range' => 'all'])); ?>">all time</a>.
    <?php else: ?>
      <?php echo $from === $to ? 'On ' . as_h(as_day($from, 'l j F')) : 'In these ' . as_n($spanDays) . ' days'; ?>
      you heard <b><?php echo as_n(count($inRange)); ?> species</b> in <b><?php echo as_n($rangeTotal); ?> detections</b><?php if ($spanDays > 1): ?>, about <b><?php echo as_n(round($rangeTotal / max(1, $activeDays))); ?> a day</b> on the <?php echo as_n($activeDays); ?> days with birds<?php endif; ?>.
      <?php if ($newInRange): ?><b><?php echo count($newInRange); ?></b> <?php echo count($newInRange) === 1 ? 'was' : 'were'; ?> new to your list.<?php endif; ?>
      <?php if ($spanDays > 1 && $busiest): ?>The busiest day was <a href="<?php echo as_h(as_url(['from' => $busiest, 'to' => $busiest])); ?>"><?php echo as_h(as_day($busiest)); ?></a> with <b><?php echo as_n($days[$busiest]['n']); ?></b>.<?php endif; ?>
      <?php if ($prevTotal > 0): $pct = round(($rangeTotal - $prevTotal) / $prevTotal * 100); ?>
        That's <b><?php echo abs($pct); ?>% <?php echo $pct >= 0 ? 'more' : 'fewer'; ?></b> than the <?php echo $spanDays === 1 ? 'day' : as_n($spanDays) . ' days'; ?> before.
      <?php endif; ?>
    <?php endif; ?>
  </p>

  <div class="as-pair">
    <section class="as-panel as-trend" aria-labelledby="as-trend-h">
      <h2 id="as-trend-h">Trend</h2>
      <p class="as-sub">detections <?php echo $step === 'day' ? 'a day' : 'a ' . $step; ?> · dots: species heard<?php if ($trendFrom !== $from): ?> · the fortnight around it<?php endif; ?></p>
      <?php if ($rangeTotal === 0): ?>
        <p class="as-empty">Nothing in this span yet.</p>
      <?php else: $nS = count($series); ?>
        <div class="as-bars" style="--n:<?php echo $nS; ?>">
          <?php foreach ($series as $p):
            $h = $p['n'] / $seriesMax * 100;
            $lab = $step === 'day' ? as_day($p['label'], 'D j M') : ($step === 'week' ? 'week of ' . as_day($p['label'], 'j M') : as_day($p['label'], 'F Y'));
            $href = $step === 'day' ? as_url(['from' => $p['label'], 'to' => $p['label']]) : null;
          ?>
            <?php if ($href): ?><a class="as-bar<?php echo $p['in'] ? '' : ' is-out'; ?>" href="<?php echo as_h($href); ?>" title="<?php echo as_h($lab . ' · ' . as_n($p['n']) . ' detections · ' . $p['s'] . ' species'); ?>"><i style="height:<?php echo round($h, 1); ?>%"></i></a>
            <?php else: ?><span class="as-bar" title="<?php echo as_h($lab . ' · ' . as_n($p['n']) . ' detections'); ?>"><i style="height:<?php echo round($h, 1); ?>%"></i></span><?php endif; ?>
          <?php endforeach; ?>
        </div>
        <div class="as-dots" style="--n:<?php echo $nS; ?>" aria-hidden="true">
          <?php foreach ($series as $p): ?><span class="<?php echo $p['in'] ? '' : 'is-out'; ?>"><?php if ($p['s']): ?><i style="bottom:<?php echo round($p['s'] / $sMax * 100, 1); ?>%"></i><?php endif; ?></span><?php endforeach; ?>
        </div>
        <p class="as-axis"><span><?php echo as_h(as_day($series[0]['label'], 'j M')); ?></span><span>peak <?php echo as_n($seriesMax); ?> · <?php echo $sMax; ?> species</span><span><?php echo as_h(as_day(end($series)['label'], 'j M')); ?></span></p>
      <?php endif; ?>
    </section>

    <section class="as-panel as-tod" aria-labelledby="as-tod-h">
      <h2 id="as-tod-h">Time of day</h2>
      <p class="as-sub">every half hour<?php if ($sunMid): ?> · night shaded for <?php echo as_h(as_day($mid, 'j M')); ?><?php endif; ?></p>
      <?php if ($rangeTotal === 0): ?>
        <p class="as-empty">Nothing in this span yet.</p>
      <?php else: $bMax = max(1, max($bins)); ?>
        <div class="as-clock">
          <?php if ($sunMid): ?>
            <span class="as-night" style="left:0;width:<?php echo round($sunMid['rise'] / 1440 * 100, 2); ?>%"></span>
            <span class="as-night" style="left:<?php echo round($sunMid['set'] / 1440 * 100, 2); ?>%;right:0"></span>
          <?php endif; ?>
          <?php foreach ($bins as $i => $n): ?><span class="as-tick" title="<?php echo as_h(as_clock($i * 30) . '–' . as_clock($i * 30 + 30) . ' · ' . as_n($n)); ?>"><i style="height:<?php echo round($n / $bMax * 100, 1); ?>%"></i></span><?php endforeach; ?>
        </div>
        <p class="as-axis as-axis-clock"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></p>
        <dl class="as-facts">
          <?php if ($sunMid): ?><div><dt>sunrise</dt><dd><?php echo as_clock($sunMid['rise']); ?></dd></div><div><dt>sunset</dt><dd><?php echo as_clock($sunMid['set']); ?></dd></div><?php endif; ?>
          <div><dt>busiest</dt><dd><?php echo as_clock($peakBin * 30); ?>–<?php echo as_clock($peakBin * 30 + 30); ?></dd></div>
          <?php if ($chorusOffset !== null): ?><div><dt>dawn chorus</dt><dd><?php echo abs($chorusOffset); ?> min <?php echo $chorusOffset >= 0 ? 'after' : 'before'; ?> sunrise</dd></div><?php endif; ?>
        </dl>
        <?php if ($sunMissing): ?><p class="as-note as-dim">Sunrise and sunset are left off: the station clock is set to <?php echo as_h(date_default_timezone_get()); ?>, not local time, so they wouldn't line up with these detection times.</p><?php endif; ?>
        <?php if ($earlyRisers): ?>
          <p class="as-note">Earliest risers: <?php
            $bits = [];
            foreach ($earlyRisers as $sci => $off) $bits[] = '<a href="' . as_h(as_url(['from' => $from, 'to' => $to, 'species' => $sci])) . '#as-bird">' . as_h($lifeBy[$sci]['com']) . '</a> <span class="as-fig">' . ($off >= 0 ? '+' : '−') . abs($off) . 'm</span>';
            echo implode(', ', $bits);
          ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>

  <?php if ($inRange): $top = array_slice($inRange, 0, 12); $cellMax = 1; foreach ($top as $r) $cellMax = max($cellMax, max($hourBy[$r['sci']])); ?>
  <section class="as-panel as-who" aria-labelledby="as-who-h">
    <h2 id="as-who-h">Who sings when</h2>
    <p class="as-sub">top <?php echo count($top); ?> species by hour · bigger square, more detections</p>
    <div class="as-who-scroll">
      <div class="as-who-grid">
        <span class="as-who-corner"></span>
        <?php for ($h = 0; $h < 24; $h++): ?><span class="as-who-h<?php echo $h % 3 ? ' is-minor' : ''; ?><?php echo $h % 6 ? ' is-off6' : ''; ?>"><?php echo sprintf('%02d', $h); ?></span><?php endfor; ?>
        <?php foreach ($top as $r): ?>
          <a class="as-who-name" href="<?php echo as_h(as_url(['from' => $from, 'to' => $to, 'species' => $r['sci']])); ?>#as-bird"><?php echo as_h($r['com']); ?></a>
          <?php for ($h = 0; $h < 24; $h++):
            $k = $hourBy[$r['sci']][$h];
            $night = $sunMid && ($h * 60 + 59 < $sunMid['rise'] || $h * 60 >= $sunMid['set']);
          ?><span class="as-who-cell<?php echo $night ? ' is-night' : ''; ?>"<?php if ($k): ?> title="<?php echo as_h($r['com'] . ' · ' . sprintf('%02d:00', $h) . ' · ' . $k); ?>"<?php endif; ?>><?php if ($k): ?><i style="--s:<?php echo round(0.34 + 0.66 * sqrt($k / $cellMax), 3); ?>"></i><?php endif; ?></span><?php endfor; ?>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($records): $g = $records['growth']; ?>
  <section class="as-panel as-records" aria-labelledby="as-rec-h">
    <h2 id="as-rec-h">Records</h2>
    <p class="as-sub">all time, since <?php echo as_h(as_day($first, 'j F Y')); ?></p>
    <div class="as-life">
      <p class="as-life-n"><span class="as-big"><?php echo count($life); ?></span> species on your list</p>
      <?php
        // Step line of the life list, drawn in ink.
        $gd = array_keys($g); $t0 = strtotime($gd[0]); $t1 = max(strtotime($today), $t0 + 86400);
        $W = 600; $H = 90; $pts = []; $prevY = $H;
        foreach ($g as $d => $n) {
          $x = round((strtotime($d) - $t0) / ($t1 - $t0) * $W, 1);
          $y = round($H - $n / count($life) * ($H - 6), 1);
          $pts[] = "$x,$prevY"; $pts[] = "$x,$y"; $prevY = $y;
        }
        $pts[] = "$W,$prevY";
      ?>
      <svg class="as-growth" viewBox="0 0 <?php echo $W; ?> <?php echo $H; ?>" preserveAspectRatio="none" role="img" aria-label="Life list growth from <?php echo as_h(as_day($gd[0], 'j M Y')); ?> to today">
        <polyline points="<?php echo implode(' ', $pts); ?>" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke"/>
      </svg>
      <p class="as-axis"><span><?php echo as_h(as_day($gd[0], 'j M Y')); ?></span><span>today</span></p>
    </div>
    <dl class="as-ledger">
      <div><dt>newest</dt><dd><?php
        $bits = [];
        foreach ($records['newest'] as $r) $bits[] = '<a href="' . as_h(as_url(['from' => $from, 'to' => $to, 'species' => $r['sci']])) . '#as-bird">' . as_h($r['com']) . '</a> <span class="as-fig">' . as_h(as_day($r['first'], 'j M')) . '</span>';
        echo implode('<br>', $bits);
      ?></dd></div>
      <div><dt>busiest day</dt><dd><a href="<?php echo as_h(as_url(['from' => $records['busiest'], 'to' => $records['busiest']])); ?>"><?php echo as_h(as_day($records['busiest'], 'D j M Y')); ?></a> <span class="as-fig"><?php echo as_n($days[$records['busiest']]['n']); ?> detections</span></dd></div>
      <div><dt>most species</dt><dd><a href="<?php echo as_h(as_url(['from' => $records['richest'], 'to' => $records['richest']])); ?>"><?php echo as_h(as_day($records['richest'], 'D j M Y')); ?></a> <span class="as-fig"><?php echo $days[$records['richest']]['s']; ?> species</span></dd></div>
      <div><dt>longest run</dt><dd><span class="as-fig"><?php echo $records['run']['best']; ?> days</span> in a row with birds<?php if ($records['run']['bestEnd']): ?>, to <?php echo as_h(as_day($records['run']['bestEnd'], 'j M')); ?><?php endif; ?></dd></div>
      <?php if ($records['loyal']): $L = $records['loyal']; ?><div><dt>most loyal</dt><dd><a href="<?php echo as_h(as_url(['from' => $from, 'to' => $to, 'species' => $L['sci']])); ?>#as-bird"><?php echo as_h($lifeBy[$L['sci']]['com']); ?></a> <span class="as-fig"><?php echo $L['best']; ?> days running</span></dd></div><?php endif; ?>
      <?php if ($records['early']): $E = $records['early']; ?><div><dt>earliest song</dt><dd><span class="as-fig"><?php echo substr($E['t'], 0, 5); ?></span> <?php echo as_h($E['com']); ?>, <?php echo as_h(as_day($E['d'], 'j M')); ?></dd></div><?php endif; ?>
      <?php if ($records['late']): $E = $records['late']; ?><div><dt>latest song</dt><dd><span class="as-fig"><?php echo substr($E['t'], 0, 5); ?></span> <?php echo as_h($E['com']); ?>, <?php echo as_h(as_day($E['d'], 'j M')); ?></dd></div><?php endif; ?>
      <?php if ($records['sure']): $E = $records['sure']; ?><div><dt>surest id</dt><dd><span class="as-fig"><?php echo round($E['c'] * 100); ?>%</span> <?php echo as_h($E['com']); ?>, <?php echo as_h(as_day($E['d'], 'j M')); ?></dd></div><?php endif; ?>
      <?php if ($records['once']): ?><div><dt>heard once</dt><dd><?php
        $bits = [];
        foreach (array_slice($records['once'], 0, 6) as $r) $bits[] = '<a href="' . as_h(as_url(['from' => $from, 'to' => $to, 'species' => $r['sci']])) . '#as-bird">' . as_h($r['com']) . '</a>';
        echo implode(', ', $bits) . (count($records['once']) > 6 ? ' and ' . (count($records['once']) - 6) . ' more' : '');
      ?></dd></div><?php endif; ?>
    </dl>
  </section>
  <?php endif; ?>

  <div class="as-pair as-pair-species">
    <section class="as-panel as-species" aria-labelledby="as-sp-h">
      <h2 id="as-sp-h">Species</h2>
      <p class="as-sub">in this span · change vs the <?php echo $spanDays === 1 ? 'day' : as_n($spanDays) . ' days'; ?> before</p>
      <?php if (!$inRange): ?>
        <p class="as-empty">No species in this span.</p>
      <?php else: $max = intval($inRange[0]['n']); ?>
        <table class="as-table">
          <thead><tr><th scope="col">bird</th><th scope="col" class="num">heard</th><th scope="col" class="num">days</th><th scope="col" class="num">vs before</th></tr></thead>
          <tbody>
          <?php foreach ($inRange as $r):
            $n = intval($r['n']); $p = $prevCounts[$r['sci']] ?? 0; $delta = $n - $p;
            $isNew = $lifeBy[$r['sci']]['first'] >= $from;
          ?>
            <tr<?php echo $r['sci'] === $pick ? ' class="is-picked"' : ''; ?>>
              <td><a href="<?php echo as_h(as_url(['from' => $from, 'to' => $to, 'species' => $r['sci']])); ?>#as-bird"><?php echo as_h($r['com']); ?></a><?php if ($isNew): ?> <span class="as-new">new</span><?php endif; ?><i class="as-sci"><?php echo as_h($r['sci']); ?></i></td>
              <td class="num"><span class="as-inline-bar" style="--w:<?php echo round($n / max(1, $max) * 100, 1); ?>%"></span><?php echo as_n($n); ?></td>
              <td class="num"><?php echo intval($r['days']); ?></td>
              <td class="num as-delta"><?php echo $p === 0 ? ($isNew ? '—' : '+' . $n) : ($delta === 0 ? '0' : ($delta > 0 ? '+' : '−') . as_n(abs($delta))); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <?php if ($dive): $hMax = max(1, max($dive['hours'])); $wMax = max(1, max($dive['weeks'])); ?>
    <section class="as-panel as-bird" id="as-bird" aria-labelledby="as-bird-h">
      <div class="as-bird-head">
        <?php if ($dive['art']): ?><img class="as-bird-art" src="<?php echo as_h($dive['art']); ?>" alt="" loading="lazy"><?php endif; ?>
        <div>
          <h2 id="as-bird-h" class="as-bird-name"><?php echo as_h($dive['com']); ?></h2>
          <p class="as-bird-sci"><?php echo as_h($dive['sci']); ?></p>
        </div>
      </div>
      <dl class="as-ledger as-ledger-tight">
        <div><dt>first heard</dt><dd><?php echo as_h(as_day($dive['first'], 'j M Y')); ?></dd></div>
        <div><dt>last heard</dt><dd><?php echo as_h(as_day($dive['last'], 'j M Y')); ?></dd></div>
        <div><dt>all time</dt><dd><span class="as-fig"><?php echo as_n($dive['n']); ?></span> detections on <span class="as-fig"><?php echo $dive['days']; ?></span> days</dd></div>
        <div><dt>this span</dt><dd><span class="as-fig"><?php echo as_n($dive['inRange']); ?></span></dd></div>
        <div><dt>longest run</dt><dd><span class="as-fig"><?php echo $dive['streak']['best']; ?></span> day<?php echo $dive['streak']['best'] === 1 ? '' : 's'; ?> in a row<?php if ($dive['streak']['lastEnd'] === $today && $dive['streak']['last'] > 1): ?>, <span class="as-fig"><?php echo $dive['streak']['last']; ?></span> and counting<?php endif; ?></dd></div>
        <?php if ($dive['best']): ?><div><dt>best recording</dt><dd><span class="as-fig"><?php echo round($dive['best']['c'] * 100); ?>%</span> on <?php echo as_h(as_day($dive['best']['d'], 'j M')); ?> · <a href="<?php echo as_h('views.php?' . http_build_query(['view' => 'Species Stats', 'species' => $dive['com']])); ?>">listen</a> · <a href="<?php echo as_h('views.php?' . http_build_query(['view' => 'Recordings', 'species' => $dive['sci']])); ?>">all recordings</a></dd></div><?php endif; ?>
      </dl>

      <h3 class="as-mini">daily rhythm, all time<?php if ($sunDay): ?> · sun on <?php echo as_h(as_day($to, 'j M')); ?> <?php echo as_clock($sunDay['rise']); ?>–<?php echo as_clock($sunDay['set']); ?><?php endif; ?></h3>
      <div class="as-rhythm">
        <?php if ($sunDay): ?>
          <span class="as-night" style="left:0;width:<?php echo round($sunDay['rise'] / 1440 * 100, 2); ?>%"></span>
          <span class="as-night" style="left:<?php echo round($sunDay['set'] / 1440 * 100, 2); ?>%;right:0"></span>
        <?php endif; ?>
        <?php foreach ($dive['hours'] as $h => $n): ?><span class="as-tick" title="<?php echo as_h(sprintf('%02d:00', $h) . ' · ' . $n); ?>"><i style="height:<?php echo round($n / $hMax * 100, 1); ?>%"></i></span><?php endforeach; ?>
      </div>
      <p class="as-axis as-axis-clock"><span>00</span><span>06</span><span>12</span><span>18</span><span>24</span></p>

      <h3 class="as-mini">season · weeks of the year</h3>
      <div class="as-season">
        <?php foreach ($dive['weeks'] as $w => $n): ?><span class="l<?php echo $n ? as_level($n, [max(1, $wMax * .25), max(1, $wMax * .5), max(1, $wMax * .75)]) : 0; ?>" title="<?php echo as_h('week ' . ($w + 1) . ' · ' . $n); ?>"></span><?php endforeach; ?>
      </div>
      <p class="as-axis"><span>jan</span><span>apr</span><span>jul</span><span>oct</span><span>dec</span></p>

      <h3 class="as-mini">latest</h3>
      <ol class="as-recent">
        <?php foreach ($dive['recent'] as $r): ?><li><span class="as-fig"><?php echo as_h(as_day($r['d'], 'j M')); ?> <?php echo substr($r['t'], 0, 5); ?></span><span class="as-fig as-dim"><?php echo round($r['c'] * 100); ?>%</span></li><?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>
  </div>

  <p class="as-foot">Looking for the old Streamlit charts? They're still at <a href="/stats" target="_blank" rel="noopener">/stats</a>.</p>
</div>
<script>
  // Phones: start the calendar at today rather than a year ago.
  (function () {
    var s = document.querySelector('[data-cal-scroll]');
    if (!s) return;
    var pin = function () { s.scrollLeft = s.scrollWidth; };
    // Pin now, and again once fonts and late layout have settled, so the
    // scroll width it pins to is the final one.
    pin();
    window.addEventListener('load', pin);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(pin);
  })();
</script>
