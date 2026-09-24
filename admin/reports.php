<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

function valid_date(?string $d): bool
{
    if (!$d) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

// default range: this week's Monday -> today
$todayDt = new DateTime('now', app_tz());
$n = (int) $todayDt->format('N');
$defFrom = (clone $todayDt)->modify('-' . ($n - 1) . ' days')->format('Y-m-d');
$defTo   = $todayDt->format('Y-m-d');

$from = $_GET['from'] ?? $defFrom; if (!valid_date($from)) $from = $defFrom;
$to   = $_GET['to']   ?? $defTo;   if (!valid_date($to))   $to   = $defTo;

list($rangeStart, ) = local_day_range($from);
list(, $rangeEnd)   = local_day_range($to);

// completed shifts in range
$done = db()->prepare(
    'SELECT te.clock_in, te.clock_out, te.brand_id, te.staff_id,
            s.name AS staff_name, s.hourly_rate, b.name AS brand_name
     FROM time_entries te
     JOIN staff s ON s.id = te.staff_id
     LEFT JOIN brands b ON b.id = te.brand_id
     WHERE te.clock_out IS NOT NULL AND te.clock_in >= ? AND te.clock_in < ?
     ORDER BY s.name'
);
$done->execute([$rangeStart, $rangeEnd]);
$rows = $done->fetchAll();

// still-open shifts in range (excluded from totals)
$openStmt = db()->prepare('SELECT COUNT(*) FROM time_entries WHERE clock_out IS NULL AND clock_in >= ? AND clock_in < ?');
$openStmt->execute([$rangeStart, $rangeEnd]);
$openCount = (int) $openStmt->fetchColumn();

// aggregate
$perStaff = [];
$perBrand = [];
foreach ($rows as $r) {
    $sid  = (int) $r['staff_id'];
    $secs = duration_seconds($r['clock_in'], $r['clock_out']);
    $rate = (float) $r['hourly_rate'];

    if (!isset($perStaff[$sid])) {
        $perStaff[$sid] = ['name' => $r['staff_name'], 'shifts' => 0, 'secs' => 0, 'rate' => $rate];
    }
    $perStaff[$sid]['shifts']++;
    $perStaff[$sid]['secs'] += $secs;

    $bkey  = $r['brand_id'] !== null ? (int) $r['brand_id'] : 0;
    $bname = $r['brand_name'] ?? 'No brand';
    if (!isset($perBrand[$bkey])) {
        $perBrand[$bkey] = ['name' => $bname, 'secs' => 0, 'wage' => 0.0];
    }
    $perBrand[$bkey]['secs'] += $secs;
    $perBrand[$bkey]['wage'] += ($secs / 3600) * $rate;
}

// finalise per-staff figures
$grandSecs = 0; $grandWage = 0.0;
foreach ($perStaff as $sid => &$row) {
    $hours = hours_decimal($row['secs']);
    $row['hours'] = $hours;
    $row['wage']  = round($hours * $row['rate'], 2);
    $grandSecs   += $row['secs'];
    $grandWage   += $row['wage'];
}
unset($row);
uasort($perStaff, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

/* ----------------------------- CSV export ----------------------------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hours_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Staff', 'Completed shifts', 'Hours', 'Rate (' . CURRENCY . ')', 'Wage (' . CURRENCY . ')']);
    foreach ($perStaff as $row) {
        fputcsv($out, [$row['name'], $row['shifts'], number_format($row['hours'], 2, '.', ''), number_format($row['rate'], 2, '.', ''), number_format($row['wage'], 2, '.', '')]);
    }
    fputcsv($out, ['TOTAL', '', number_format(hours_decimal($grandSecs), 2, '.', ''), '', number_format($grandWage, 2, '.', '')]);
    fclose($out);
    exit;
}

$qs = 'from=' . urlencode($from) . '&to=' . urlencode($to);

admin_header('Reports', 'reports.php');
flash_render();
?>

<div class="panel">
    <form method="get" class="filters">
        <div class="field"><label>From <input type="date" name="from" value="<?= e($from) ?>"></label></div>
        <div class="field"><label>To <input type="date" name="to" value="<?= e($to) ?>"></label></div>
        <button class="btn" type="submit">Show</button>
        <a class="btn ghost" href="reports.php?<?= $qs ?>&export=csv">Download CSV</a>
    </form>
    <p class="hint">Totals cover completed shifts with a clock-out, from <?= e($from) ?> to <?= e($to) ?> (<?= e(APP_TZ) ?>).
        <?php if ($openCount > 0): ?><strong><?= $openCount ?></strong> shift<?= $openCount === 1 ? '' : 's' ?> still in progress <?= $openCount === 1 ? 'is' : 'are' ?> not included.<?php endif; ?>
    </p>
</div>

<div class="panel">
    <h2>By staff</h2>
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Staff</th><th class="num">Shifts</th><th class="num">Hours</th><th class="num">Rate</th><th class="num">Wages</th></tr></thead>
        <tbody>
        <?php if (!$perStaff): ?>
            <tr class="empty-row"><td colspan="5">No completed shifts in this range.</td></tr>
        <?php endif; ?>
        <?php foreach ($perStaff as $row): ?>
            <tr>
                <td><?= e($row['name']) ?></td>
                <td class="num"><?= (int) $row['shifts'] ?></td>
                <td class="num"><?= e(fmt_duration($row['secs'])) ?> <span class="muted">(<?= number_format($row['hours'], 2) ?>)</span></td>
                <td class="num"><?= e(money($row['rate'])) ?></td>
                <td class="num"><?= e(money($row['wage'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($perStaff): ?>
            <tr class="total">
                <td>Total</td>
                <td class="num"></td>
                <td class="num"><?= e(fmt_duration($grandSecs)) ?> <span class="muted">(<?= number_format(hours_decimal($grandSecs), 2) ?>)</span></td>
                <td class="num"></td>
                <td class="num"><?= e(money($grandWage)) ?></td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="panel">
    <h2>By brand</h2>
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Brand</th><th class="num">Hours</th><th class="num">Labour cost</th></tr></thead>
        <tbody>
        <?php if (!$perBrand): ?>
            <tr class="empty-row"><td colspan="3">No completed shifts in this range.</td></tr>
        <?php endif; ?>
        <?php
        uasort($perBrand, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        foreach ($perBrand as $b): ?>
            <tr>
                <td><?= e($b['name']) ?></td>
                <td class="num"><?= e(fmt_duration($b['secs'])) ?></td>
                <td class="num"><?= e(money(round($b['wage'], 2))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="hint">Brand labour cost uses each person's hourly rate for the shifts they logged under that brand.</p>
</div>

<?php admin_footer(); ?>
