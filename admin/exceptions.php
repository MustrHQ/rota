<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../insights.php';
require_admin();

$period = $_GET['period'] ?? 'week';
if (!isset(period_keys()[$period])) $period = 'week';
[$from, $to, $label] = period_bounds($period);
$type = $_GET['type'] ?? 'all';
if (!in_array($type, ['all', 'missed', 'noshow', 'late'], true)) $type = 'all';

$ex = timecard_exceptions($from, $to);

/* flatten into one list, newest first */
$rows = [];
foreach ($ex['missed_out'] as $m) {
    $d = utc_to_local($m['entry']['clock_in']);
    $rows[] = ['kind' => 'missed', 'date' => $d ? $d->format('Y-m-d') : '', 'staff' => $m['staff'],
        'what' => 'Clocked in ' . fmt_dt_local($m['entry']['clock_in'], 'D j M, H:i') . ' and never clocked out',
        'detail' => 'open ' . fmt_duration($m['age']),
        'fix' => 'timesheets.php?edit=' . (int) $m['entry']['id'], 'fixlabel' => 'Set clock-out'];
}
foreach ($ex['no_show'] as $n) {
    $in = $n['date'] . 'T' . $n['expected'];
    $out = $n['expected_end'] ? $n['date'] . 'T' . $n['expected_end'] : '';
    if ($out !== '' && $n['expected_end'] <= $n['expected']) {
        $out = (new DateTime($n['date']))->modify('+1 day')->format('Y-m-d') . 'T' . $n['expected_end'];
    }
    $rows[] = ['kind' => 'noshow', 'date' => $n['date'], 'staff' => $n['staff'],
        'what' => 'On the rota from ' . $n['expected'] . ($n['expected_end'] ? '–' . $n['expected_end'] : '') . ', no clock-in',
        'detail' => '',
        'fix' => 'timesheets.php?staff=' . (int) $n['staff']['id'] . '&add_in=' . urlencode($in) . '&add_out=' . urlencode($out) . '#entry-form',
        'fixlabel' => 'Add punch'];
}
foreach ($ex['late'] as $l) {
    $rows[] = ['kind' => 'late', 'date' => $l['date'], 'staff' => $l['staff'],
        'what' => 'Due ' . $l['expected'] . ', clocked in ' . fmt_time_local($l['entry']['clock_in']),
        'detail' => fmt_mins($l['mins']) . ' late',
        'fix' => 'timesheets.php?edit=' . (int) $l['entry']['id'], 'fixlabel' => 'Review'];
}
usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));
$counts = ['all' => count($rows), 'missed' => count($ex['missed_out']), 'noshow' => count($ex['no_show']), 'late' => count($ex['late'])];
if ($type !== 'all') $rows = array_values(array_filter($rows, fn($r) => $r['kind'] === $type));

$kindLabel = ['missed' => 'Missed out-punch', 'noshow' => 'Unexcused absence', 'late' => 'Late in'];
$kindClass = ['missed' => 'noshow', 'noshow' => 'noshow', 'late' => 'late'];

$fmtRange = (new DateTime($from))->format('j M') . ' – ' . (new DateTime($to))->format('j M Y');

admin_header('Timecard exceptions', 'exceptions.php', $label . ' · ' . $fmtRange);
flash_render();
?>

<div class="toolbar">
    <div class="seg">
        <?php foreach (period_keys() as $k => $lbl): ?>
            <a href="?period=<?= e($k) ?>&type=<?= e($type) ?>" class="<?= $k === $period ? 'on' : '' ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="kpis">
    <a class="kpi <?= $type === 'all' ? 'on' : '' ?>" href="?period=<?= e($period) ?>&type=all">
        <b class="<?= $counts['all'] ? 't-bad' : '' ?>"><?= $counts['all'] ?></b><span>Must fix</span></a>
    <a class="kpi <?= $type === 'missed' ? 'on' : '' ?>" href="?period=<?= e($period) ?>&type=missed">
        <b><?= $counts['missed'] ?></b><span>Missed out-punches</span></a>
    <a class="kpi <?= $type === 'noshow' ? 'on' : '' ?>" href="?period=<?= e($period) ?>&type=noshow">
        <b><?= $counts['noshow'] ?></b><span>Unexcused absences</span></a>
    <a class="kpi <?= $type === 'late' ? 'on' : '' ?>" href="?period=<?= e($period) ?>&type=late">
        <b><?= $counts['late'] ?></b><span>Late in</span></a>
    <a class="kpi" href="timesheets.php?from=<?= e($from) ?>&to=<?= e(min($to, today_local_date())) ?>">
        <b class="t-ok"><?= (int) $ex['clean'] ?></b><span>Clean timecards</span></a>
</div>

<div class="panel">
    <?php if (!$rows): ?>
        <div class="allclear">
            <span class="ring ok"><?= admin_icon('check') ?></span>
            <div><b>Nothing to fix.</b><p class="muted">Every shift <?= e(strtolower($label)) ?> has a clean clock in and out.</p></div>
        </div>
    <?php else: ?>
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Date</th><th>Staff</th><th>Exception</th><th>Details</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="Date" class="mono"><?= e((new DateTime($r['date']))->format('D j M')) ?></td>
                <td data-label="Staff"><span class="who-cell"><?= staff_avatar($r['staff'], 'avatar sm') ?><?= e($r['staff']['name']) ?></span></td>
                <td data-label="Exception"><span class="badge <?= e($kindClass[$r['kind']]) ?>"><?= e($kindLabel[$r['kind']]) ?></span></td>
                <td data-label="Details"><?= e($r['what']) ?><?php if ($r['detail']): ?> <span class="muted">· <?= e($r['detail']) ?></span><?php endif; ?></td>
                <td data-label=""><a class="btn small" href="<?= e($r['fix']) ?>"><?= e($r['fixlabel']) ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<p class="hint">Late and no-show are judged against each person's rota with a <?= (int) GRACE_MINUTES ?>-minute grace; a shift open more than <?= (int) OPEN_SHIFT_ALERT_HOURS ?> hours counts as a missed out-punch. People not on the rota for a day are never flagged.</p>

<?php admin_footer(); ?>
