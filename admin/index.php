<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

$today = today_local_date();
$dow   = today_local_dow();
list($dayStart, $dayEnd) = local_day_range($today);
$now = time();

/* --- open shifts: split "on shift now" from likely forgotten clock-outs --- */
$openRows = db()->query(
    "SELECT te.id, te.clock_in, s.name, b.name AS brand
     FROM time_entries te
     JOIN staff s ON s.id = te.staff_id
     LEFT JOIN brands b ON b.id = te.brand_id
     WHERE te.clock_out IS NULL
     ORDER BY te.clock_in"
)->fetchAll();

$onNow = []; $missedOut = [];
$alertSecs = OPEN_SHIFT_ALERT_HOURS * 3600;
foreach ($openRows as $r) {
    $r['age'] = duration_seconds($r['clock_in'], null); // open -> now (seconds)
    if ($r['age'] >= $alertSecs) $missedOut[] = $r; else $onNow[] = $r;
}

/* --- hours logged today (open shifts count up to now) --- */
$todayRows = db()->prepare('SELECT clock_in, clock_out FROM time_entries WHERE clock_in >= ? AND clock_in < ?');
$todayRows->execute([$dayStart, $dayEnd]);
$secsToday = 0;
foreach ($todayRows->fetchAll() as $r) $secsToday += duration_seconds($r['clock_in'], $r['clock_out']);

/* --- today's attendance vs the recurring schedule --- */
$sched = db()->prepare(
    "SELECT sc.staff_id, sc.expected_start, sc.expected_end, s.name
     FROM schedules sc
     JOIN staff s ON s.id = sc.staff_id AND s.is_active = 1
     WHERE sc.dow = ?
     ORDER BY sc.expected_start, s.name"
);
$sched->execute([$dow]);
$schedRows = $sched->fetchAll();

$firstIn = [];
$fi = db()->prepare('SELECT staff_id, MIN(clock_in) AS f FROM time_entries WHERE clock_in >= ? AND clock_in < ? GROUP BY staff_id');
$fi->execute([$dayStart, $dayEnd]);
foreach ($fi->fetchAll() as $r) $firstIn[(int) $r['staff_id']] = $r['f'];

function local_time_to_epoch(string $date, string $time): int
{
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time, app_tz());
    return $dt ? (int) $dt->format('U') : 0;
}

$attendance = []; $lateCount = 0; $noshowCount = 0;
foreach ($schedRows as $r) {
    $sid = (int) $r['staff_id'];
    $expStart = local_time_to_epoch($today, $r['expected_start']);
    $expEnd   = $r['expected_end'] ? local_time_to_epoch($today, $r['expected_end']) : null;
    if ($expEnd !== null && $expEnd <= $expStart) $expEnd += 86400; // overnight end
    $status = 'pending'; $label = 'Not in yet'; $detail = '';

    if (isset($firstIn[$sid])) {
        $inEpoch = strtotime($firstIn[$sid] . ' UTC');
        $detail = 'in ' . fmt_time_local($firstIn[$sid]);
        if ($inEpoch > $expStart + GRACE_MINUTES * 60) {
            $mins = (int) round(($inEpoch - $expStart) / 60);
            $status = 'late'; $label = 'Late ' . $mins . 'm'; $lateCount++;
        } else {
            $status = 'ontime'; $label = 'On time';
        }
    } else {
        $cutoff = $expStart + NOSHOW_AFTER_MINUTES * 60;
        if (($expEnd !== null && $now > $expEnd) || $now > $cutoff) {
            $status = 'noshow'; $label = 'No-show'; $noshowCount++;
        }
    }

    $attendance[] = [
        'name' => $r['name'], 'exp' => substr($r['expected_start'], 0, 5),
        'status' => $status, 'label' => $label, 'detail' => $detail,
    ];
}

/* --- people who clocked in today without a schedule (still counted) --- */
$scheduledIds = array_map(static fn($r) => (int) $r['staff_id'], $schedRows);
$unschedIds = array_diff(array_keys($firstIn), $scheduledIds);
if ($unschedIds) {
    $in = implode(',', array_map('intval', $unschedIds));
    foreach (db()->query("SELECT id, name FROM staff WHERE id IN ($in) AND is_active = 1 ORDER BY name")->fetchAll() as $n) {
        $attendance[] = [
            'name' => $n['name'], 'exp' => '—',
            'status' => 'unscheduled', 'label' => 'Worked (no schedule)',
            'detail' => 'in ' . fmt_time_local($firstIn[(int) $n['id']]),
        ];
    }
}

$missedCount = count($missedOut);
$issues = $missedCount + $lateCount + $noshowCount;

/* --- leftover files that shouldn't be reachable on a live site --- */
$root = dirname(__DIR__);
$risks = [];
if (is_file($root . '/install.php')) {
    $risks[] = ['install.php', 'The installer is still on the server. Delete it — anyone who reaches it can probe your setup.'];
}
foreach (glob($root . '/*.zip') ?: [] as $z) {
    $risks[] = [basename($z), 'A release archive sits in your web root and can be downloaded. Delete it once the update is applied.'];
}
if (!is_file($root . '/.htaccess')) {
    $risks[] = ['.htaccess', 'Missing from the web root, so visitors may see a file listing. Re-upload it from the release.'];
}
if (!is_file($root . '/index.php')) {
    $risks[] = ['index.php', 'Missing from the web root, so the plain domain may show a file listing. Re-upload it from the release.'];
}

admin_header('Dashboard', 'index.php');
flash_render();
?>

<?php $tzNow = new DateTime('now', app_tz()); ?>
<section class="daybar">
    <div class="daybar-time">
        <div class="daybar-clock" id="dayclock"><?= e($tzNow->format('H:i:s')) ?></div>
        <div class="daybar-date"><?= e($tzNow->format('l, j F Y')) ?></div>
    </div>
    <div class="daybar-figs">
        <div class="dfig"><div class="dfig-n"><?= count($onNow) ?></div><div class="dfig-l">on shift now</div></div>
        <div class="dfig"><div class="dfig-n"><?= e(fmt_duration($secsToday)) ?></div><div class="dfig-l">logged today</div></div>
        <?php if ($issues === 0): ?>
            <div class="dfig"><div class="dfig-n"><span class="dot"></span></div><div class="dfig-l">all clear</div></div>
        <?php else: ?>
            <a class="dfig" href="<?= $missedOut ? '#fix' : '#attendance' ?>">
                <div class="dfig-n"><span class="dot <?= ($missedCount || $noshowCount) ? 'bad' : 'warn' ?>"></span><?= $issues ?></div>
                <div class="dfig-l">need attention</div>
            </a>
        <?php endif; ?>
    </div>
</section>

<?php if ($risks): ?>
<div class="panel" id="security">
    <div class="panel-head"><h2>Tidy up before going live</h2></div>
    <p class="hint" style="margin-top:-4px">These files are reachable from the web and should be removed in cPanel File Manager or over FTP.</p>
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>File</th><th>Why it matters</th></tr></thead>
        <tbody>
        <?php foreach ($risks as $r): ?>
            <tr><td class="mono"><?= e($r[0]) ?></td><td><?= e($r[1]) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($missedOut): ?>
<div class="panel" id="fix">
    <div class="panel-head"><h2>Needs fixing</h2></div>
    <p class="hint" style="margin-top:-4px">These shifts have been open longer than <?= (int) OPEN_SHIFT_ALERT_HOURS ?> hours — most likely a missed clock-out. Set the real finish time so hours and pay stay right.</p>
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Name</th><th>Brand</th><th>Clocked in</th><th class="num">Open for</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($missedOut as $r): ?>
            <tr>
                <td><?= e($r['name']) ?></td>
                <td><?= $r['brand'] ? e($r['brand']) : '<span class="muted">—</span>' ?></td>
                <td class="mono"><?= e(fmt_dt_local($r['clock_in'], 'D j M, H:i')) ?></td>
                <td class="num"><?= e(fmt_duration($r['age'])) ?></td>
                <td><a class="btn small" href="timesheets.php?edit=<?= (int) $r['id'] ?>">Fix time</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h2>On shift now</h2><a class="btn small" href="timesheets.php">Timesheets</a></div>
    <?php if (!$onNow): ?>
        <p class="muted">Nobody is clocked in.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Name</th><th>Brand</th><th>Since</th><th class="num">Elapsed</th></tr></thead>
        <tbody>
        <?php foreach ($onNow as $r): ?>
            <tr>
                <td><?= e($r['name']) ?></td>
                <td><?= $r['brand'] ? e($r['brand']) : '<span class="muted">—</span>' ?></td>
                <td class="mono"><?= e(fmt_time_local($r['clock_in'])) ?></td>
                <td class="num" data-since="<?= e((string) strtotime($r['clock_in'] . ' UTC')) ?>">—</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<div class="panel" id="attendance">
    <div class="panel-head"><h2>Today's attendance</h2><a class="btn small ghost" href="schedules.php">Edit schedules</a></div>
    <?php if (!$attendance): ?>
        <p class="muted">No one is scheduled or clocked in today yet. Set expected start times under Schedules to track late arrivals and no-shows.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Name</th><th>Expected</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($attendance as $a): ?>
            <tr>
                <td><?= e($a['name']) ?></td>
                <td class="mono"><?= e($a['exp']) ?></td>
                <td>
                    <span class="badge <?= e($a['status']) ?>"><?= e($a['label']) ?></span>
                    <?php if ($a['detail']): ?><span class="muted" style="margin-left:8px"><?= e($a['detail']) ?></span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<script>
(function () {
    var offset = <?= time() ?> - Date.now() / 1000;
    var tz = <?= json_encode(APP_TZ) ?>;
    var clockEl = document.getElementById('dayclock');
    var timeFmt = null;
    try { timeFmt = new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }); } catch (e) {}
    function pad(n){ return (n < 10 ? '0' : '') + n; }
    function dur(s){ if (s < 0) s = 0; var h = Math.floor(s/3600), m = Math.floor((s%3600)/60); return h + 'h ' + pad(m) + 'm'; }
    function tick(){
        var now = Date.now()/1000 + offset;
        if (clockEl) {
            var d = new Date(now * 1000);
            clockEl.textContent = timeFmt ? timeFmt.format(d) : d.toTimeString().slice(0, 8);
        }
        document.querySelectorAll('[data-since]').forEach(function (el) {
            var s = parseInt(el.getAttribute('data-since'), 10);
            if (s) el.textContent = dur(now - s);
        });
    }
    tick(); setInterval(tick, 1000);
})();
</script>

<?php admin_footer(); ?>
