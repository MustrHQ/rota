<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

$DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

/* ----------------------------- actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    if ($staffId > 0) {
        $sched = $_POST['sched'] ?? [];   // dow => "1" if scheduled
        $start = $_POST['start'] ?? [];
        $end   = $_POST['end'] ?? [];

        $up = db()->prepare(
            'INSERT INTO schedules (staff_id, dow, expected_start, expected_end)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE expected_start = VALUES(expected_start), expected_end = VALUES(expected_end)'
        );
        $del = db()->prepare('DELETE FROM schedules WHERE staff_id = ? AND dow = ?');

        foreach ($DAYS as $dow => $label) {
            $on = !empty($sched[$dow]);
            $st = trim($start[$dow] ?? '');
            $en = trim($end[$dow] ?? '');
            if ($on && preg_match('/^\d{2}:\d{2}$/', $st)) {
                $enVal = preg_match('/^\d{2}:\d{2}$/', $en) ? $en . ':00' : null;
                $up->execute([$staffId, $dow, $st . ':00', $enVal]);
            } else {
                $del->execute([$staffId, $dow]);
            }
        }
        flash_set('Schedule saved.');
    }
    header('Location: schedules.php?staff=' . $staffId);
    exit;
}

/* ----------------------------- data (overview + export) ------------- */
$activeStaff = db()->query('SELECT id, name FROM staff WHERE is_active = 1 ORDER BY name')->fetchAll();

$rota = []; // staff_id => dow => "HH:MM" or "HH:MM–HH:MM"
foreach (db()->query('SELECT staff_id, dow, expected_start, expected_end FROM schedules')->fetchAll() as $r) {
    $s  = substr($r['expected_start'], 0, 5);
    $en = $r['expected_end'] ? substr($r['expected_end'], 0, 5) : '';
    $rota[(int) $r['staff_id']][(int) $r['dow']] = $en !== '' ? ($s . '–' . $en) : $s;
}

/* CSV export of the whole rota */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rota.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['Staff'], array_values($DAYS)));
    foreach ($activeStaff as $s) {
        $row = [$s['name']];
        foreach ($DAYS as $dow => $label) {
            $row[] = $rota[(int) $s['id']][$dow] ?? '';
        }
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/* ------------------------- printable rota (PDF / JPG) -------------- */
if (($_GET['view'] ?? '') === 'print') {
    $today = today_local_date();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(APP_NAME) ?> — Rota</title>
<style>
  :root{font-family:-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
  body{margin:24px;color:#1a1d22}
  h1{font-size:20px;margin:0 0 2px}
  .sub{color:#6b7280;font-size:13px;margin:0 0 16px}
  table{border-collapse:collapse;width:100%}
  th,td{border:1px solid #cbd2da;padding:8px 10px;text-align:center;font-size:13px}
  th{background:#eef1f4}
  th:first-child,td:first-child{text-align:left;font-weight:600;white-space:nowrap}
  td.t{font-family:ui-monospace,Menlo,Consolas,monospace}
  .muted{color:#aab2bd}
  .toolbar{margin:0 0 16px;display:flex;gap:8px;flex-wrap:wrap}
  .btn{font:inherit;font-size:13px;padding:8px 12px;border:1px solid #cbd2da;border-radius:8px;background:#fff;cursor:pointer;text-decoration:none;color:#1a1d22}
  .btn.primary{background:#1a1d22;color:#fff;border-color:#1a1d22}
  @media print{ .toolbar{display:none} body{margin:0} @page{size:A4 landscape;margin:12mm} }
</style>
</head>
<body>
<div class="toolbar">
    <button class="btn primary" onclick="window.print()">Save as PDF (Print)</button>
    <button class="btn" id="jpgBtn" type="button">Download JPG</button>
    <a class="btn" href="schedules.php">Back to admin</a>
</div>
<h1><?= e(APP_NAME) ?> — Weekly rota</h1>
<p class="sub">Times shown in <?= e(APP_TZ) ?>. Generated <?= e($today) ?>.</p>

<?php if (!$activeStaff): ?>
    <p class="muted">No active staff yet.</p>
<?php else: ?>
<table id="rota">
    <thead><tr><th>Staff</th><?php foreach ($DAYS as $d): ?><th><?= e(substr($d, 0, 3)) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($activeStaff as $s): $sid = (int) $s['id']; ?>
        <tr><td><?= e($s['name']) ?></td>
        <?php foreach ($DAYS as $dow => $label): $c = $rota[$sid][$dow] ?? ''; ?>
            <td class="t"><?= $c !== '' ? e($c) : '<span class="muted">·</span>' ?></td>
        <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<script>
(function () {
    var btn = document.getElementById('jpgBtn');
    var table = document.getElementById('rota');
    if (!btn || !table) { if (btn) btn.style.display = 'none'; return; }
    var SANS = '-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif';
    var MONO = 'ui-monospace,Menlo,Consolas,monospace';

    btn.addEventListener('click', function () {
        var rows = [].slice.call(table.querySelectorAll('tr')).map(function (tr) {
            return [].slice.call(tr.children).map(function (cell) {
                var t = cell.textContent.trim();
                return t === '\u00b7' ? '' : t;
            });
        });
        if (!rows.length) return;
        var ncols = rows[0].length;
        var dpr = window.devicePixelRatio || 1;
        var pad = 24, titleH = 54, headH = 34, rowH = 30;

        var cvs = document.createElement('canvas');
        var ctx = cvs.getContext('2d');

        // measure column widths
        ctx.font = '600 14px ' + SANS;
        var nameW = 140;
        rows.forEach(function (r) { nameW = Math.max(nameW, ctx.measureText(r[0]).width + 24); });
        var dayW = 64;
        rows.forEach(function (r) { for (var c = 1; c < ncols; c++) dayW = Math.max(dayW, ctx.measureText(r[c]).width + 20); });
        dayW = Math.min(dayW, 200);

        var gridW = nameW + dayW * (ncols - 1);
        var totalRows = rows.length;           // incl. header
        var dataRows = totalRows - 1;
        var gridH = headH + rowH * dataRows;
        var W = pad * 2 + gridW;
        var H = pad * 2 + titleH + gridH;

        cvs.width = Math.ceil(W * dpr);
        cvs.height = Math.ceil(H * dpr);
        ctx.scale(dpr, dpr);

        // background
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, W, H);

        // title
        ctx.textAlign = 'left';
        ctx.fillStyle = '#1a1d22'; ctx.font = '700 18px ' + SANS;
        ctx.fillText(<?= json_encode(APP_NAME . ' — Weekly rota') ?>, pad, pad + 18);
        ctx.fillStyle = '#6b7280'; ctx.font = '13px ' + SANS;
        ctx.fillText('Times in ' + <?= json_encode(APP_TZ) ?> + ' \u00b7 ' + <?= json_encode($today) ?>, pad, pad + 38);

        var x0 = pad, y0 = pad + titleH;
        function colX(c) { return c === 0 ? x0 : x0 + nameW + dayW * (c - 1); }
        function colW(c) { return c === 0 ? nameW : dayW; }

        // header background
        ctx.fillStyle = '#eef1f4'; ctx.fillRect(x0, y0, gridW, headH);

        // cell text
        for (var r = 0; r < totalRows; r++) {
            var ry = (r === 0) ? y0 : y0 + headH + rowH * (r - 1);
            var rh = (r === 0) ? headH : rowH;
            var ty = ry + rh / 2 + 4.5;
            for (var c = 0; c < ncols; c++) {
                var txt = rows[r][c] || '';
                ctx.fillStyle = '#1a1d22';
                if (r === 0) ctx.font = '700 13px ' + SANS;
                else ctx.font = '13px ' + (c === 0 ? SANS : MONO);
                if (c === 0) { ctx.textAlign = 'left'; ctx.fillText(txt, colX(c) + 10, ty); }
                else if (txt) { ctx.textAlign = 'center'; ctx.fillText(txt, colX(c) + colW(c) / 2, ty); }
            }
        }

        // borders
        ctx.strokeStyle = '#cbd2da'; ctx.lineWidth = 1;
        for (var vc = 0; vc <= ncols; vc++) {
            var vx = (vc === 0) ? x0 : x0 + nameW + dayW * (vc - 1);
            ctx.beginPath(); ctx.moveTo(vx + 0.5, y0); ctx.lineTo(vx + 0.5, y0 + gridH); ctx.stroke();
        }
        ctx.beginPath(); ctx.moveTo(x0, y0 + 0.5); ctx.lineTo(x0 + gridW, y0 + 0.5); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(x0, y0 + headH + 0.5); ctx.lineTo(x0 + gridW, y0 + headH + 0.5); ctx.stroke();
        for (var hr = 1; hr <= dataRows; hr++) {
            var hy = y0 + headH + rowH * hr;
            ctx.beginPath(); ctx.moveTo(x0, hy + 0.5); ctx.lineTo(x0 + gridW, hy + 0.5); ctx.stroke();
        }

        cvs.toBlob(function (blob) {
            if (!blob) { alert('Could not create the image.'); return; }
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'rota-' + <?= json_encode($today) ?> + '.jpg';
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
        }, 'image/jpeg', 0.95);
    });
})();
</script>
</body>
</html>
    <?php
    exit;
}

/* ----------------------------- per-staff editor -------------------- */
$staffId = (int) ($_GET['staff'] ?? 0);

$existing = [];
if ($staffId) {
    $q = db()->prepare('SELECT dow, expected_start, expected_end FROM schedules WHERE staff_id = ?');
    $q->execute([$staffId]);
    foreach ($q->fetchAll() as $r) {
        $existing[(int) $r['dow']] = [
            'start' => substr($r['expected_start'], 0, 5),
            'end'   => $r['expected_end'] ? substr($r['expected_end'], 0, 5) : '',
        ];
    }
}

admin_header('Rota', 'schedules.php', 'Expected start times, and the weekly rota to share');
flash_render();
?>

<div class="panel">
    <div class="panel-head"><h2>Weekly rota</h2>
        <div class="actions">
            <a class="btn small ghost" href="schedules.php?view=print" target="_blank" rel="noopener">Print / PDF / JPG</a>
            <a class="btn small ghost" href="schedules.php?export=csv">CSV</a>
        </div>
    </div>
    <?php if (!$activeStaff): ?>
        <p class="muted">No active staff yet.</p>
    <?php else: ?>
    <div class="rota-scroll">
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Staff</th><?php foreach ($DAYS as $d): ?><th><?= e(substr($d, 0, 3)) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($activeStaff as $s): $sid = (int) $s['id']; ?>
            <tr>
                <td><?= e($s['name']) ?></td>
                <?php foreach ($DAYS as $dow => $label): $cell = $rota[$sid][$dow] ?? ''; ?>
                    <td class="mono"><?= $cell !== '' ? e($cell) : '<span class="muted">·</span>' ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    </div>
    <?php endif; ?>
</div>

<div class="panel">
    <form method="get" class="filters">
        <div class="field" style="min-width:240px"><label>Staff member
            <select name="staff" onchange="this.form.submit()">
                <option value="0">Choose…</option>
                <?php foreach ($activeStaff as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $staffId ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label></div>
        <noscript><button class="btn" type="submit">Load</button></noscript>
    </form>

    <?php if (!$staffId): ?>
        <p class="muted">Pick someone to set the days and times they're normally expected to start.</p>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="staff_id" value="<?= $staffId ?>">
        <div class="table-wrap"><table class="grid">
            <thead><tr><th>Day</th><th>Scheduled</th><th>Expected start</th><th>Expected end (optional)</th></tr></thead>
            <tbody>
            <?php foreach ($DAYS as $dow => $label):
                $has = isset($existing[$dow]);
                $st  = $existing[$dow]['start'] ?? '';
                $en  = $existing[$dow]['end'] ?? '';
            ?>
                <tr>
                    <td data-label="Day"><?= e($label) ?></td>
                    <td data-label="Scheduled"><input type="checkbox" name="sched[<?= $dow ?>]" value="1" <?= $has ? 'checked' : '' ?>></td>
                    <td data-label="Expected start"><input type="time" name="start[<?= $dow ?>]" value="<?= e($st) ?>" style="max-width:160px;margin:0"></td>
                    <td data-label="Expected end (optional)"><input type="time" name="end[<?= $dow ?>]" value="<?= e($en) ?>" style="max-width:160px;margin:0"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="actions" style="margin-top:14px"><button class="btn" type="submit">Save schedule</button></div>
    </form>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>How flagging works</h2>
    <p class="muted" style="max-width:70ch">
        On a scheduled day, a clock-in more than <strong><?= (int) GRACE_MINUTES ?> minutes</strong> after the expected start shows as <span class="badge late">Late</span> on the dashboard.
        If someone hasn't clocked in by <strong><?= (int) NOSHOW_AFTER_MINUTES ?> minutes</strong> past their expected start (or past their expected end, if set), they show as <span class="badge noshow">No-show</span>.
        Anyone without a schedule for the day is never flagged. Times are in <?= e(APP_TZ) ?>.
    </p>
</div>

<?php admin_footer(); ?>
