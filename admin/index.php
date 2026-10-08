<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../insights.php';
require_admin();

$tz = app_tz();
$nowDt = new DateTime('now', $tz);
$today = $nowDt->format('Y-m-d');
$dow = (int) $nowDt->format('N');

/* ---------- Manage timecards tile: period switcher ---------- */
$period = $_GET['period'] ?? 'week';
if (!isset(period_keys()[$period])) $period = 'week';
[$pFrom, $pTo, $pLabel] = period_bounds($period);
$ex = timecard_exceptions($pFrom, $pTo);

/* ---------- Today ---------- */
$exToday = ($period === 'today') ? $ex : timecard_exceptions($today, $today);
$staffAll = staff_with_schedules();
$scheduledToday = 0;
foreach ($staffAll as $s) if (isset($s['sched'][$dow])) $scheduledToday++;

$openRows = db()->query(
    "SELECT te.id, te.staff_id, te.clock_in, s.name, s.photo, b.name AS brand
     FROM time_entries te JOIN staff s ON s.id = te.staff_id
     LEFT JOIN brands b ON b.id = te.brand_id
     WHERE te.clock_out IS NULL ORDER BY te.clock_in"
)->fetchAll();
$onNow = [];
foreach ($openRows as $r) {
    if (duration_seconds($r['clock_in'], null) < OPEN_SHIFT_ALERT_HOURS * 3600) $onNow[] = $r;
}

[$ds, $de] = local_day_range($today);
$tq = db()->prepare('SELECT te.clock_in, te.clock_out, s.hourly_rate FROM time_entries te JOIN staff s ON s.id = te.staff_id WHERE te.clock_in >= ? AND te.clock_in < ?');
$tq->execute([$ds, $de]);
$secsToday = 0; $costToday = 0.0; $arrivedToday = [];
foreach ($tq->fetchAll() as $r) {
    $sec = duration_seconds($r['clock_in'], $r['clock_out']);
    $secsToday += $sec;
    $costToday += $sec / 3600 * (float) $r['hourly_rate'];
}
$fi = db()->prepare('SELECT DISTINCT staff_id FROM time_entries WHERE clock_in >= ? AND clock_in < ?');
$fi->execute([$ds, $de]);
$arrived = count($fi->fetchAll());

/* ---------- Today's attendance list ---------- */
$attendance = [];
foreach ($staffAll as $sid => $s) {
    if (!isset($s['sched'][$dow])) continue;
    [$st, $en] = $s['sched'][$dow];
    $row = ['s' => $s, 'exp' => substr($st, 0, 5) . ($en ? '–' . substr($en, 0, 5) : ''), 'status' => 'pending', 'label' => 'Not in yet', 'detail' => ''];
    foreach ($exToday['late'] as $l) if ((int) $l['staff']['id'] === $sid) { $row['status'] = 'late'; $row['label'] = 'Late ' . fmt_mins($l['mins']); $row['detail'] = 'in ' . fmt_time_local($l['entry']['clock_in']); }
    foreach ($exToday['no_show'] as $n) if ((int) $n['staff']['id'] === $sid) { $row['status'] = 'noshow'; $row['label'] = 'No-show'; }
    if ($row['status'] === 'pending') {
        $q = db()->prepare('SELECT MIN(clock_in) FROM time_entries WHERE staff_id = ? AND clock_in >= ? AND clock_in < ?');
        $q->execute([$sid, $ds, $de]);
        if ($f = $q->fetchColumn()) { $row['status'] = 'ontime'; $row['label'] = 'On time'; $row['detail'] = 'in ' . fmt_time_local($f); }
    }
    $attendance[] = $row;
}
$order = ['noshow' => 0, 'late' => 1, 'pending' => 2, 'ontime' => 3];
usort($attendance, fn($a, $b) => $order[$a['status']] <=> $order[$b['status']]);

/* ---------- leftover files that shouldn't be live ---------- */
$root = dirname(__DIR__);
$risks = [];
if (is_file($root . '/install.php')) $risks[] = ['install.php', 'The installer is still on the server. Delete it.'];
foreach (glob($root . '/*.zip') ?: [] as $z) $risks[] = [basename($z), 'A release archive is downloadable from your web root. Delete it.'];
if (!is_file($root . '/.htaccess')) $risks[] = ['.htaccess', 'Missing, so visitors may see a file listing. Re-upload it from the release.'];

$hour = (int) $nowDt->format('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$who = current_admin_name();

admin_header('Home', 'index.php', '');
flash_render();
?>

<section class="welcome">
    <div class="welcome-id">
        <span class="welcome-av"><?= e(name_initials($who)) ?></span>
        <div>
            <h2><?= e($greet) ?>, <?= e($who) ?></h2>
            <p><?= e($nowDt->format('l j F Y')) ?> &nbsp;·&nbsp; <?= count($onNow) ?> on shift right now</p>
        </div>
    </div>
    <div class="welcome-clock" id="dayclock"><?= e($nowDt->format('H:i:s')) ?></div>
</section>

<?php if ($risks): ?>
<div class="flash warn"><b>Before going live:</b>
    <?php foreach ($risks as $i => $r): ?><?= $i ? ' · ' : ' ' ?><code><?= e($r[0]) ?></code> <?= e($r[1]) ?><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="tiles">

    <!-- Manage timecards -->
    <section class="tile-card">
        <header class="tc-head">
            <h3>Manage timecards</h3>
            <a class="tc-go" href="exceptions.php?period=<?= e($period) ?>" title="Open exceptions"><?= admin_icon('arrow') ?></a>
        </header>
        <form method="get" class="tc-period">
            <label>Select a time frame
                <select name="period" onchange="this.form.submit()">
                    <?php foreach (period_keys() as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= $k === $period ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button class="btn small" type="submit">Go</button></noscript>
        </form>

        <a class="tc-row" href="exceptions.php?period=<?= e($period) ?>">
            <span class="ring <?= $ex['must_fix'] ? 'bad' : 'ok' ?>"><?= (int) $ex['must_fix'] ?></span>
            <span class="tc-body">
                <b>Must fix</b>
                <?php if ($ex['must_fix']): ?>
                    <?php if ($ex['missed_out']): ?><span><em><?= count($ex['missed_out']) ?></em> Missed out-punch</span><?php endif; ?>
                    <?php if ($ex['no_show']): ?><span><em><?= count($ex['no_show']) ?></em> Unexcused absence</span><?php endif; ?>
                    <?php if ($ex['late']): ?><span><em><?= count($ex['late']) ?></em> Late in</span><?php endif; ?>
                <?php else: ?>
                    <span>Nothing to fix for <?= e(strtolower($pLabel)) ?></span>
                <?php endif; ?>
            </span>
            <span class="chev"><?= admin_icon('chev') ?></span>
        </a>
        <a class="tc-row" href="timesheets.php?from=<?= e($pFrom) ?>&to=<?= e(min($pTo, $today)) ?>">
            <span class="ring neutral"><?= (int) $ex['clean'] ?></span>
            <span class="tc-body"><b>Clean timecards</b><span>Shifts with no exceptions</span></span>
            <span class="chev"><?= admin_icon('chev') ?></span>
        </a>
    </section>

    <!-- On shift now -->
    <section class="tile-card">
        <header class="tc-head">
            <h3>On shift now</h3>
            <a class="tc-go" href="planner.php" title="Schedule planner"><?= admin_icon('arrow') ?></a>
        </header>
        <?php if (!$onNow): ?>
            <p class="tc-empty">Nobody is clocked in.</p>
        <?php else: ?>
            <ul class="onlist">
            <?php foreach (array_slice($onNow, 0, 7) as $r): ?>
                <li>
                    <?= staff_avatar(['name' => $r['name'], 'photo' => $r['photo']], 'avatar sm live') ?>
                    <span class="on-name"><?= e($r['name']) ?><small>In <?= e(fmt_time_local($r['clock_in'])) ?><?= $r['brand'] ? ' · ' . e($r['brand']) : '' ?></small></span>
                    <span class="on-el mono" data-since="<?= (int) strtotime($r['clock_in'] . ' UTC') ?>">—</span>
                </li>
            <?php endforeach; ?>
            </ul>
            <?php if (count($onNow) > 7): ?><p class="tc-more">+<?= count($onNow) - 7 ?> more</p><?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Today -->
    <section class="tile-card">
        <header class="tc-head">
            <h3>Today</h3>
            <a class="tc-go" href="#attendance" title="Today's attendance"><?= admin_icon('arrow') ?></a>
        </header>
        <div class="today-grid">
            <div><b class="mono"><?= $arrived ?><small>/<?= $scheduledToday ?></small></b><span>Arrived / scheduled</span></div>
            <div><b class="mono <?= $exToday['late'] ? 't-warn' : '' ?>"><?= count($exToday['late']) ?></b><span>Late in</span></div>
            <div><b class="mono <?= $exToday['no_show'] ? 't-bad' : '' ?>"><?= count($exToday['no_show']) ?></b><span>No-shows</span></div>
            <div><b class="mono"><?= e(fmt_duration($secsToday)) ?></b><span>Hours logged</span></div>
        </div>
        <div class="today-cost"><span>Labour so far today</span><b class="mono"><?= e(money($costToday)) ?></b></div>
    </section>

    <!-- Quick links -->
    <section class="tile-card">
        <header class="tc-head"><h3>Quick links</h3></header>
        <ul class="qlinks">
            <li><a href="planner.php"><?= admin_icon('planner') ?><span><b>Schedule planner</b>Who's working this week, planned against actual</span></a></li>
            <li><a href="timesheets.php"><?= admin_icon('timesheets') ?><span><b>Add a missed punch</b>Record or correct a clock in or out</span></a></li>
            <li><a href="reports.php"><?= admin_icon('reports') ?><span><b>Hours &amp; wages</b>Totals for payroll, exportable</span></a></li>
            <li><a href="../kiosk/index.php" target="_blank" rel="noopener"><?= admin_icon('kiosks') ?><span><b>Open the kiosk</b>The tablet screen staff tap</span></a></li>
        </ul>
    </section>
</div>

<div class="panel compact-list" id="attendance">
    <div class="panel-head"><h2>Today's attendance</h2><a class="btn small ghost" href="schedules.php">Edit rota</a></div>
    <?php if (!$attendance): ?>
        <p class="muted">No one is on the rota for today. People can still clock in, and their hours count.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Name</th><th>Rota</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($attendance as $a): ?>
            <tr>
                <td data-label="Name"><span class="who-cell"><?= staff_avatar($a['s'], 'avatar sm') ?><?= e($a['s']['name']) ?></span></td>
                <td data-label="Rota" class="mono"><?= e($a['exp']) ?></td>
                <td data-label="Status"><span class="badge <?= e($a['status']) ?>"><?= e($a['label']) ?></span><?php if ($a['detail']): ?> <span class="muted"><?= e($a['detail']) ?></span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<script>
(function () {
    var offset = <?= time() ?> - Date.now() / 1000;
    var clockEl = document.getElementById('dayclock'), fmt = null;
    try { fmt = new Intl.DateTimeFormat('en-GB', { timeZone: <?= json_encode(APP_TZ) ?>, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }); } catch (e) {}
    function pad(n){ return (n < 10 ? '0' : '') + n; }
    function tick(){
        var now = Date.now() / 1000 + offset;
        if (clockEl) { var d = new Date(now * 1000); clockEl.textContent = fmt ? fmt.format(d) : d.toTimeString().slice(0, 8); }
        document.querySelectorAll('[data-since]').forEach(function (el) {
            var s = now - parseInt(el.getAttribute('data-since'), 10); if (s < 0) s = 0;
            el.textContent = Math.floor(s / 3600) + 'h ' + pad(Math.floor((s % 3600) / 60)) + 'm';
        });
    }
    tick(); setInterval(tick, 1000);
})();
</script>

<?php admin_footer(); ?>
