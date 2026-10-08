<?php
require_once __DIR__ . '/../helpers.php';

/* ---------- CSV template (before any output) ---------- */
if (($_GET['template'] ?? '') === '1') {
    require_admin();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="timecards-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['staff', 'clock_in', 'clock_out', 'brand', 'note']);
    fputcsv($out, ['Sam Rai', '2026-09-01 09:00', '2026-09-01 17:30', 'Brand One', 'backfilled']);
    fputcsv($out, ['Tora', '2026-09-01 18:00', '2026-09-02 02:15', '', 'night shift']);
    fclose($out);
    exit;
}

require_admin();

const IMPORT_MAX_ROWS = 2000;

/** Accepts "2026-09-01 09:00", "2026-09-01T09:00", "01/09/2026 09:00" (day first). */
function parse_local_dt(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return null;
    $v = str_replace('T', ' ', $v);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'j/n/Y H:i:s', 'j/n/Y H:i',
              'd-m-Y H:i', 'j-n-Y H:i'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $v, app_tz());
        if ($dt && $dt->format($fmt) === $v) return $dt->format('Y-m-d H:i:s');
    }
    return null;
}

/** Parse + validate the uploaded file into rows we can show and then commit. */
function read_rows(string $path): array
{
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException('could not read the uploaded file.');

    // staff and brand lookups (case-insensitive by name, or by numeric id)
    $staffByName = []; $staffById = [];
    foreach (db()->query('SELECT id, name, is_active FROM staff')->fetchAll() as $s) {
        $staffByName[mb_strtolower(trim($s['name']))] = $s;
        $staffById[(int) $s['id']] = $s;
    }
    $brandByName = [];
    foreach (db()->query('SELECT id, name FROM brands')->fetchAll() as $b) {
        $brandByName[mb_strtolower(trim($b['name']))] = (int) $b['id'];
    }

    $dupe = db()->prepare('SELECT COUNT(*) FROM time_entries WHERE staff_id = ? AND clock_in = ?');

    $rows = []; $header = null; $line = 0;
    while (($data = fgetcsv($fh)) !== false) {
        $line++;
        if ($data === [null] || (count($data) === 1 && trim((string) $data[0]) === '')) continue;

        if ($header === null) {
            $probe = array_map(function ($h) { return mb_strtolower(trim((string) $h)); }, $data);
            if (in_array('staff', $probe, true) || in_array('clock_in', $probe, true)) { $header = $probe; continue; }
            $header = ['staff', 'clock_in', 'clock_out', 'brand', 'note']; // headerless file
        }

        $get = function (string $key) use ($header, $data) {
            $i = array_search($key, $header, true);
            return $i === false ? '' : trim((string) ($data[$i] ?? ''));
        };

        $r = ['line' => $line, 'staff' => $get('staff'), 'in' => $get('clock_in'),
              'out' => $get('clock_out'), 'brand' => $get('brand'), 'note' => $get('note'),
              'staff_id' => null, 'brand_id' => null, 'in_utc' => null, 'out_utc' => null,
              'status' => 'ok', 'msg' => ''];

        // staff
        $key = mb_strtolower($r['staff']);
        if ($r['staff'] === '') { $r['status'] = 'error'; $r['msg'] = 'no staff given'; }
        elseif (ctype_digit($r['staff']) && isset($staffById[(int) $r['staff']])) { $r['staff_id'] = (int) $r['staff']; }
        elseif (isset($staffByName[$key])) { $r['staff_id'] = (int) $staffByName[$key]['id']; }
        else { $r['status'] = 'error'; $r['msg'] = 'staff not found'; }

        // times (given in local time, stored as UTC)
        if ($r['status'] === 'ok') {
            $inLocal = parse_local_dt($r['in']);
            if ($inLocal === null) { $r['status'] = 'error'; $r['msg'] = 'clock in not a valid date/time'; }
            else {
                $r['in_utc'] = local_to_utc($inLocal);
                if ($r['out'] !== '') {
                    $outLocal = parse_local_dt($r['out']);
                    if ($outLocal === null) { $r['status'] = 'error'; $r['msg'] = 'clock out not a valid date/time'; }
                    else {
                        $r['out_utc'] = local_to_utc($outLocal);
                        if ($r['out_utc'] !== null && $r['in_utc'] !== null && $r['out_utc'] <= $r['in_utc']) {
                            $r['status'] = 'error'; $r['msg'] = 'clock out is not after clock in';
                        }
                    }
                }
            }
        }

        // brand (optional)
        if ($r['status'] === 'ok' && $r['brand'] !== '') {
            $bk = mb_strtolower($r['brand']);
            if (isset($brandByName[$bk])) $r['brand_id'] = $brandByName[$bk];
            else { $r['status'] = 'warn'; $r['msg'] = 'brand not found — will import with no brand'; }
        }

        // already there?
        if ($r['status'] !== 'error' && $r['staff_id'] && $r['in_utc']) {
            $dupe->execute([$r['staff_id'], $r['in_utc']]);
            if ((int) $dupe->fetchColumn() > 0) { $r['status'] = 'skip'; $r['msg'] = 'already imported'; }
        }

        $rows[] = $r;
        if (count($rows) >= IMPORT_MAX_ROWS) { break; }
    }
    fclose($fh);
    return $rows;
}

/* ----------------------------- actions ----------------------------- */
$rows = $_SESSION['import_rows'] ?? null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        unset($_SESSION['import_rows']);
        if (!isset($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'No file was uploaded, or it was too large for the server.';
        } elseif (!is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $error = 'The upload could not be verified. Please try again.';
        } else {
            try {
                $rows = read_rows($_FILES['csv']['tmp_name']);
                if (!$rows) $error = 'That file had no rows in it.';
                else $_SESSION['import_rows'] = $rows;
            } catch (Throwable $e) {
                $error = 'Could not read that file: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'commit') {
        $rows = $_SESSION['import_rows'] ?? [];
        if (!$rows) {
            flash_set('Nothing to import — upload a file first.', 'err');
        } else {
            $ins = db()->prepare('INSERT INTO time_entries (staff_id, brand_id, clock_in, clock_out, note, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
            $now = now_utc(); $done = 0; $skipped = 0; $failed = 0;
            foreach ($rows as $r) {
                if ($r['status'] === 'error' || $r['status'] === 'skip') { $skipped++; continue; }
                try {
                    $ins->execute([$r['staff_id'], $r['brand_id'], $r['in_utc'], $r['out_utc'],
                                   ($r['note'] !== '' ? $r['note'] : 'imported'), $now, $now]);
                    $done++;
                } catch (Throwable $e) { $failed++; }
            }
            unset($_SESSION['import_rows']);
            $msg = $done . ' entr' . ($done === 1 ? 'y' : 'ies') . ' imported';
            if ($skipped) $msg .= ', ' . $skipped . ' skipped';
            if ($failed) $msg .= ', ' . $failed . ' failed';
            flash_set($msg . '.', $failed ? 'warn' : 'ok');
        }
        header('Location: import.php');
        exit;
    } elseif ($action === 'discard') {
        unset($_SESSION['import_rows']);
        header('Location: import.php');
        exit;
    }
}

$counts = ['ok' => 0, 'warn' => 0, 'skip' => 0, 'error' => 0];
foreach ($rows ?? [] as $r) { $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1; }
$importable = $counts['ok'] + $counts['warn'];

admin_header('Import timecards', 'import.php', 'Bring in past days from a spreadsheet');
flash_render();
if ($error) echo '<div class="flash err">' . e($error) . '</div>';
?>

<div class="panel">
    <div class="panel-head"><h2>Import past timecards</h2>
        <a class="btn small ghost" href="import.php?template=1">Download template</a>
    </div>
    <p class="muted" style="max-width:76ch">
        Upload a CSV of clock in/out records from previous days — handy when moving over from paper, a spreadsheet, or another system.
        Columns: <code>staff</code>, <code>clock_in</code>, <code>clock_out</code>, <code>brand</code>, <code>note</code>.
        Staff can be the name as it appears under Staff, or the numeric id. Leave <code>clock_out</code> blank for a shift that was never closed.
    </p>
    <p class="muted" style="max-width:76ch">
        Times are read in <strong><?= e(APP_TZ) ?></strong>, the same as everywhere else in the admin, and stored as UTC — so overnight shifts and daylight-saving changes come out right.
        Nothing is written until you review the preview and confirm.
    </p>
    <form method="post" enctype="multipart/form-data" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="preview">
        <label>CSV file<input type="file" name="csv" accept=".csv,text/csv" required></label>
        <button class="btn" type="submit">Check file</button>
    </form>
</div>

<?php if ($rows): ?>
<div class="panel">
    <div class="panel-head"><h2>Preview</h2>
        <div class="actions">
            <form class="inline-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="discard"><button class="btn-link" type="submit">Discard</button></form>
            <?php if ($importable): ?>
            <form class="inline-form" method="post" onsubmit="return confirm('Import <?= (int) $importable ?> entries?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="commit">
                <button class="btn" type="submit">Import <?= (int) $importable ?> entr<?= $importable === 1 ? 'y' : 'ies' ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <p class="hint" style="margin-top:-4px">
        <?= (int) $counts['ok'] ?> ready<?php if ($counts['warn']): ?>, <?= (int) $counts['warn'] ?> with a warning<?php endif; ?><?php if ($counts['skip']): ?>, <?= (int) $counts['skip'] ?> already imported<?php endif; ?><?php if ($counts['error']): ?>, <?= (int) $counts['error'] ?> with errors (these are left out)<?php endif; ?>.
    </p>

    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Line</th><th>Staff</th><th>Clock in</th><th>Clock out</th><th>Brand</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $cls = $r['status'] === 'error' ? 'noshow' : ($r['status'] === 'warn' ? 'late' : ($r['status'] === 'skip' ? 'off' : 'on'));
            $label = $r['status'] === 'error' ? 'error' : ($r['status'] === 'warn' ? 'warning' : ($r['status'] === 'skip' ? 'skipped' : 'ready'));
        ?>
            <tr>
                <td data-label="Line" class="mono"><?= (int) $r['line'] ?></td>
                <td data-label="Staff"><?= e($r['staff']) ?></td>
                <td data-label="Clock in" class="mono"><?= $r['in_utc'] ? e(fmt_dt_local($r['in_utc'], 'j M Y, H:i')) : e($r['in']) ?></td>
                <td data-label="Clock out" class="mono"><?= $r['out_utc'] ? e(fmt_dt_local($r['out_utc'], 'j M Y, H:i')) : ($r['out'] !== '' ? e($r['out']) : '<span class="muted">still open</span>') ?></td>
                <td data-label="Brand"><?= $r['brand'] !== '' ? e($r['brand']) : '<span class="muted">—</span>' ?></td>
                <td data-label="Status"><span class="badge <?= $cls ?>"><?= e($label) ?></span><?php if ($r['msg']): ?> <span class="muted" style="font-size:13px"><?= e($r['msg']) ?></span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
