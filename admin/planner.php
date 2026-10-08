<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../insights.php';
require_admin();

$tz = app_tz();
$todayDt = new DateTime('today', $tz);
$today = $todayDt->format('Y-m-d');

/* week (always starts Monday) */
$wk = $_GET['week'] ?? '';
$wkDt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $wk) ? DateTime::createFromFormat('Y-m-d', $wk, $tz) : null;
if (!$wkDt) $wkDt = clone $todayDt;
$wkDt->setTime(0, 0)->modify('monday this week');
$weekStart = $wkDt->format('Y-m-d');
$weekEnd = (clone $wkDt)->modify('+6 days')->format('Y-m-d');
$prevWeek = (clone $wkDt)->modify('-7 days')->format('Y-m-d');
$nextWeek = (clone $wkDt)->modify('+7 days')->format('Y-m-d');

$brandId = (int) ($_GET['brand'] ?? 0);
$brands = db()->query('SELECT id, name FROM brands WHERE is_active = 1 ORDER BY name')->fetchAll();

$staff = staff_with_schedules($brandId ?: null);
$entries = entries_between($weekStart, $weekEnd);
$ex = ($weekStart <= $today) ? timecard_exceptions($weekStart, $weekEnd) : ['no_show' => [], 'late' => [], 'missed_out' => []];

$noShow = []; foreach ($ex['no_show'] as $n) $noShow[(int) $n['staff']['id']][$n['date']] = true;
$lateIds = []; foreach ($ex['late'] as $l) $lateIds[(int) $l['entry']['id']] = $l['mins'];
$missedIds = []; foreach ($ex['missed_out'] as $m) $missedIds[(int) $m['entry']['id']] = true;

/* days of the week */
$days = [];
for ($i = 0; $i < 7; $i++) {
    $d = (clone $wkDt)->modify("+$i days");
    $days[] = ['date' => $d->format('Y-m-d'), 'dow' => (int) $d->format('N'),
               'start' => (int) $d->format('U'), 'label' => $d->format('D'), 'num' => $d->format('j M')];
}
$now = time();

/* per-staff bars */
$rows = []; $coverage = array_fill(0, 7, 0); $workedCount = array_fill(0, 7, 0);
foreach ($staff as $sid => $s) {
    $row = ['s' => $s, 'cells' => [], 'planned' => 0, 'worked' => 0];
    foreach ($days as $i => $d) {
        $cell = ['plan' => [], 'act' => [], 'noshow' => !empty($noShow[$sid][$d['date']])];
        $dayStart = $d['start'];
        $dayEnd = $dayStart + 86400;
        // planned shift starting today
        if (isset($s['sched'][$d['dow']])) {
            [$st, $en] = $s['sched'][$d['dow']];
            $a = local_epoch($d['date'], $st);
            $b = $en ? local_epoch($d['date'], $en) : $a + 8 * 3600;
            if ($b <= $a) $b += 86400;
            $cell['plan'][] = ['l' => day_pct($a, $dayStart), 'r' => day_pct(min($b, $dayEnd), $dayStart),
                               'txt' => short_span($st, $en), 'full' => substr($st, 0, 5) . ($en ? '–' . substr($en, 0, 5) : ''), 'cont' => $b > $dayEnd];
            $row['planned'] += ($b - $a);
            $coverage[$i]++;
        }
        // tail of yesterday's overnight shift
        $prevDow = $d['dow'] === 1 ? 7 : $d['dow'] - 1;
        if (isset($s['sched'][$prevDow]) && $s['sched'][$prevDow][1]) {
            [$pst, $pen] = $s['sched'][$prevDow];
            if (strcmp($pen, $pst) <= 0) {
                $prevDate = (new DateTime($d['date'], $tz))->modify('-1 day')->format('Y-m-d');
                $b = local_epoch($prevDate, $pen) + 86400;
                $cell['plan'][] = ['l' => 0, 'r' => day_pct($b, $dayStart), 'txt' => '', 'cont' => false, 'tail' => true];
            }
        }
        // actual worked time overlapping this day
        foreach ($entries as $e) {
            if ((int) $e['staff_id'] !== $sid) continue;
            $a = strtotime($e['clock_in'] . ' UTC');
            $open = $e['clock_out'] === null;
            $b = $open ? $now : strtotime($e['clock_out'] . ' UTC');
            if ($b <= $dayStart || $a >= $dayEnd) continue;
            $id = (int) $e['id'];
            $cls = $open ? (isset($missedIds[$id]) ? 'missed' : 'live') : 'done';
            $cell['act'][] = ['l' => day_pct(max($a, $dayStart), $dayStart), 'r' => day_pct(min($b, $dayEnd), $dayStart),
                              'cls' => $cls, 'late' => $lateIds[$id] ?? 0, 'id' => $id,
                              'tip' => fmt_time_local($e['clock_in']) . '–' . ($open ? 'now' : fmt_time_local($e['clock_out']))
                                       . ($e['brand'] ? ' · ' . $e['brand'] : '')];
            $row['worked'] += min($b, $dayEnd) - max($a, $dayStart);
            if ($a >= $dayStart) $workedCount[$i]++;
        }
        $row['cells'][] = $cell;
    }
    $rows[] = $row;
}

$todayIdx = null;
foreach ($days as $i => $d) if ($d['date'] === $today) $todayIdx = $i;
$nowPct = $todayIdx !== null ? day_pct($now, $days[$todayIdx]['start']) : null;

$q = '&brand=' . $brandId;
$title = (new DateTime($weekStart))->format('j M') . ' – ' . (new DateTime($weekEnd))->format('j M Y');

admin_header('Schedule planner', 'planner.php', 'Rota against what was actually worked · ' . $title);
?>

<div class="toolbar">
    <div class="seg">
        <a href="?week=<?= e($prevWeek) . $q ?>" aria-label="Previous week"><?= admin_icon('chev-l') ?></a>
        <a href="?week=<?= e($today) . $q ?>" class="<?= $weekStart <= $today && $today <= $weekEnd ? 'on' : '' ?>">This week</a>
        <a href="?week=<?= e($nextWeek) . $q ?>" aria-label="Next week"><?= admin_icon('chev') ?></a>
    </div>
    <form method="get" class="tb-filter">
        <input type="hidden" name="week" value="<?= e($weekStart) ?>">
        <select name="brand" onchange="this.form.submit()" aria-label="Location">
            <option value="0">All locations</option>
            <?php foreach ($brands as $b): ?><option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === $brandId ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
        </select>
    </form>
    <div class="legend">
        <span><i class="lg plan"></i>On rota</span>
        <span><i class="lg done"></i>Worked</span>
        <span><i class="lg live"></i>On shift</span>
        <span><i class="lg late"></i>Late in</span>
        <span><i class="lg noshow"></i>No-show</span>
    </div>
</div>

<div class="planner-wrap">
<div class="planner" style="--today: <?= $todayIdx === null ? -1 : $todayIdx ?>">
    <div class="pl-head pl-name">Name <small>[<?= count($rows) ?>]</small></div>
    <?php foreach ($days as $i => $d): ?>
        <div class="pl-head pl-day <?= $i === $todayIdx ? 'is-today' : '' ?>">
            <b><?= e($d['label']) ?> <?= e($d['num']) ?></b>
            <span class="ticks"><i>00</i><i>06</i><i>12</i><i>18</i></span>
        </div>
    <?php endforeach; ?>
    <div class="pl-head pl-tot">Hours</div>

    <div class="pl-cov pl-name">On rota</div>
    <?php foreach ($days as $i => $d): ?>
        <div class="pl-cov <?= $i === $todayIdx ? 'is-today' : '' ?>">
            <span class="covn"><?= $coverage[$i] ?></span>
            <?php if ($d['date'] <= $today): ?><small><?= $workedCount[$i] ?> clocked in</small><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <div class="pl-cov pl-tot"></div>

    <?php if (!$rows): ?>
        <div class="pl-empty">No active staff<?= $brandId ? ' at this location' : '' ?>.</div>
    <?php endif; ?>

    <?php foreach ($rows as $r): ?>
        <div class="pl-name pl-person"><?= staff_avatar($r['s'], 'avatar sm') ?><span><?= e($r['s']['name']) ?></span></div>
        <?php foreach ($r['cells'] as $i => $c): ?>
            <div class="pl-cell <?= $i === $todayIdx ? 'is-today' : '' ?> <?= $c['noshow'] ? 'is-noshow' : '' ?>">
                <?php foreach ($c['plan'] as $p): ?>
                    <span class="bar plan <?= !empty($p['tail']) ? 'tail' : '' ?> <?= $p['cont'] ? 'cont' : '' ?>" style="left:<?= round($p['l'], 2) ?>%;width:<?= round(max(1.5, $p['r'] - $p['l']), 2) ?>%" title="On rota <?= e($p['full'] ?? '') ?>"><?php if ($p['txt']): ?><em><?= e($p['txt']) ?></em><?php endif; ?></span>
                <?php endforeach; ?>
                <?php foreach ($c['act'] as $a): ?>
                    <a class="bar act <?= e($a['cls']) ?> <?= $a['late'] ? 'late' : '' ?>" href="timesheets.php?edit=<?= (int) $a['id'] ?>" style="left:<?= round($a['l'], 2) ?>%;width:<?= round(max(1.5, $a['r'] - $a['l']), 2) ?>%" title="<?= e($a['tip']) ?><?= $a['late'] ? ' · ' . fmt_mins((int) $a['late']) . ' late' : '' ?>"></a>
                <?php endforeach; ?>
                <?php if ($c['noshow']): ?><span class="ns-tag">No-show</span><?php endif; ?>
                <?php if ($i === $todayIdx && $nowPct !== null): ?><span class="nowline" style="left:<?= round($nowPct, 2) ?>%"></span><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="pl-tot pl-hours">
            <b class="mono"><?= e(number_format($r['worked'] / 3600, 1)) ?></b>
            <small class="mono">of <?= e(number_format($r['planned'] / 3600, 1)) ?></small>
        </div>
    <?php endforeach; ?>
</div>
</div>

<p class="hint">Outlined bars are the rota; solid bars are actual clock in to clock out (green while still on shift). Click a worked bar to open that timecard. The rota is each person's weekly pattern — edit it under <a href="schedules.php">Rota</a>.</p>

<?php admin_footer(); ?>
