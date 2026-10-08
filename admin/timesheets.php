<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

$errors = [];

/* ----------------------------- actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_entry') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM time_entries WHERE id = ?')->execute([$id]);
        flash_set('Entry deleted.');
        header('Location: timesheets.php');
        exit;
    }

    if ($action === 'save_entry') {
        $id       = (int) ($_POST['id'] ?? 0);
        $staffId  = (int) ($_POST['staff_id'] ?? 0);
        $brandId  = ($_POST['brand_id'] ?? '') !== '' ? (int) $_POST['brand_id'] : null;
        $inUtc    = local_to_utc($_POST['clock_in'] ?? '');
        $outRaw   = trim($_POST['clock_out'] ?? '');
        $outUtc   = $outRaw === '' ? null : local_to_utc($outRaw);
        $note     = trim($_POST['note'] ?? '');
        if ($note === '') $note = null;

        if ($staffId <= 0) $errors[] = 'Choose a staff member.';
        if ($inUtc === null) $errors[] = 'Enter a valid clock-in time.';
        if ($outRaw !== '' && $outUtc === null) $errors[] = 'The clock-out time is not valid.';
        if ($inUtc !== null && $outUtc !== null && strtotime($outUtc . ' UTC') <= strtotime($inUtc . ' UTC')) {
            $errors[] = 'Clock-out must be after clock-in.';
        }

        if (!$errors) {
            $now = now_utc();
            if ($id === 0) {
                db()->prepare('INSERT INTO time_entries (staff_id, brand_id, clock_in, clock_out, note, created_at) VALUES (?,?,?,?,?,?)')
                    ->execute([$staffId, $brandId, $inUtc, $outUtc, $note, $now]);
            } else {
                db()->prepare('UPDATE time_entries SET staff_id=?, brand_id=?, clock_in=?, clock_out=?, note=?, updated_at=? WHERE id=?')
                    ->execute([$staffId, $brandId, $inUtc, $outUtc, $note, $now, $id]);
            }
            flash_set('Entry saved.');
            header('Location: timesheets.php');
            exit;
        }
    }
}

/* ----------------------------- filters ----------------------------- */
function valid_date(?string $d): bool
{
    if (!$d) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

$to   = $_GET['to']   ?? today_local_date();
if (!valid_date($to)) $to = today_local_date();
$from = $_GET['from'] ?? (new DateTime($to, app_tz()))->modify('-6 days')->format('Y-m-d');
if (!valid_date($from)) $from = (new DateTime($to, app_tz()))->modify('-6 days')->format('Y-m-d');
$staffFilter = (int) ($_GET['staff'] ?? 0);

list($rangeStart, ) = local_day_range($from);
list(, $rangeEnd)   = local_day_range($to);

/* ----------------------------- data -------------------------------- */
$allStaff  = db()->query('SELECT id, name, is_active FROM staff ORDER BY is_active DESC, name')->fetchAll();
$allBrands = db()->query('SELECT id, name, is_active FROM brands ORDER BY name')->fetchAll();

$sql = 'SELECT te.*, s.name AS staff_name, b.name AS brand_name
        FROM time_entries te
        JOIN staff s ON s.id = te.staff_id
        LEFT JOIN brands b ON b.id = te.brand_id
        WHERE te.clock_in >= ? AND te.clock_in < ?';
$params = [$rangeStart, $rangeEnd];
if ($staffFilter > 0) { $sql .= ' AND te.staff_id = ?'; $params[] = $staffFilter; }
$sql .= ' ORDER BY te.clock_in DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

/* prefill for edit / add */
$editId = (int) ($_GET['edit'] ?? 0);
$form = ['id' => 0, 'staff_id' => $staffFilter, 'brand_id' => '', 'clock_in' => '', 'clock_out' => '', 'note' => ''];
// prefill a new entry from a link (e.g. fixing a no-show from Exceptions)
if (!$editId && isset($_GET['add_in'])) {
    $ai = (string) $_GET['add_in'];
    $ao = (string) ($_GET['add_out'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $ai)) $form['clock_in'] = $ai;
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $ao)) $form['clock_out'] = $ao;
    $form['note'] = 'added from exceptions';
}
if ($editId) {
    $eq = db()->prepare('SELECT * FROM time_entries WHERE id = ?');
    $eq->execute([$editId]);
    if ($e = $eq->fetch()) {
        $form = [
            'id' => (int) $e['id'],
            'staff_id' => (int) $e['staff_id'],
            'brand_id' => $e['brand_id'] !== null ? (int) $e['brand_id'] : '',
            'clock_in' => local_input_value($e['clock_in']),
            'clock_out' => local_input_value($e['clock_out']),
            'note' => (string) $e['note'],
        ];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $form = [
        'id' => (int) ($_POST['id'] ?? 0),
        'staff_id' => (int) ($_POST['staff_id'] ?? 0),
        'brand_id' => ($_POST['brand_id'] ?? '') !== '' ? (int) $_POST['brand_id'] : '',
        'clock_in' => $_POST['clock_in'] ?? '',
        'clock_out' => $_POST['clock_out'] ?? '',
        'note' => $_POST['note'] ?? '',
    ];
}

admin_header('Timesheets', 'timesheets.php', 'Every clock in and out, editable');
flash_render();
if ($errors) echo '<div class="flash err">' . implode('<br>', array_map('e', $errors)) . '</div>';
?>

<div class="panel" id="entry-form">
    <div class="panel-head"><h2><?= $form['id'] ? 'Edit entry' : 'Add entry manually' ?></h2>
        <?php if ($form['id']): ?><a class="btn ghost small" href="timesheets.php">Cancel edit</a><?php endif; ?>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_entry">
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
        <div class="form-grid">
            <label>Staff
                <select name="staff_id" required>
                    <option value="">Choose…</option>
                    <?php foreach ($allStaff as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $form['staff_id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?><?= $s['is_active'] ? '' : ' (inactive)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Brand (optional)
                <select name="brand_id">
                    <option value="">— none —</option>
                    <?php foreach ($allBrands as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= (string) $form['brand_id'] === (string) $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['name']) ?><?= $b['is_active'] ? '' : ' (inactive)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Clock in
                <input type="datetime-local" name="clock_in" required value="<?= e($form['clock_in']) ?>">
            </label>
            <label>Clock out (leave blank if still on shift)
                <input type="datetime-local" name="clock_out" value="<?= e($form['clock_out']) ?>">
            </label>
        </div>
        <label>Note (optional)
            <input type="text" name="note" maxlength="255" value="<?= e($form['note']) ?>">
        </label>
        <div class="hint">Times are in <?= e(APP_TZ) ?>.</div>
        <div class="actions" style="margin-top:14px">
            <button class="btn" type="submit"><?= $form['id'] ? 'Save changes' : 'Add entry' ?></button>
        </div>
    </form>
</div>

<div class="panel">
    <form method="get" class="filters">
        <div class="field"><label>Staff
            <select name="staff">
                <option value="0">Everyone</option>
                <?php foreach ($allStaff as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $staffFilter ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label></div>
        <div class="field"><label>From <input type="date" name="from" value="<?= e($from) ?>"></label></div>
        <div class="field"><label>To <input type="date" name="to" value="<?= e($to) ?>"></label></div>
        <button class="btn" type="submit">Show</button>
    </form>

    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Date</th><th>Staff</th><th>Brand</th><th>In</th><th>Out</th><th class="num">Duration</th><th></th></tr></thead>
        <tbody>
        <?php if (!$entries): ?>
            <tr class="empty-row"><td colspan="7">No entries in this range.</td></tr>
        <?php endif; ?>
        <?php foreach ($entries as $en):
            $open = $en['clock_out'] === null;
            $secs = duration_seconds($en['clock_in'], $en['clock_out']);
        ?>
            <tr>
                <td data-label="Date" class="mono"><?= e(fmt_date_local($en['clock_in'])) ?></td>
                <td data-label="Staff"><?= e($en['staff_name']) ?><?php if ($en['note']): ?> <span class="muted" title="<?= e($en['note']) ?>">✎</span><?php endif; ?></td>
                <td data-label="Brand"><?= $en['brand_name'] ? e($en['brand_name']) : '<span class="muted">—</span>' ?></td>
                <td data-label="In" class="mono"><?= e(fmt_time_local($en['clock_in'])) ?></td>
                <td data-label="Out" class="mono"><?= $open ? '<span class="badge on">on shift</span>' : e(fmt_time_local($en['clock_out'])) ?></td>
                <td data-label="Duration" class="num"><?= $open ? '<span class="muted">' . e(fmt_duration($secs)) . '</span>' : e(fmt_duration($secs)) ?></td>
                <td data-label="">
                    <div class="actions">
                        <a class="btn-link" href="timesheets.php?edit=<?= (int) $en['id'] ?>">Edit</a>
                        <form class="inline-form" method="post" onsubmit="return confirm('Delete this entry?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_entry">
                            <input type="hidden" name="id" value="<?= (int) $en['id'] ?>">
                            <button class="btn-link danger" type="submit">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<?php admin_footer(); ?>
