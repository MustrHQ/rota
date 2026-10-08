<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

$UPLOAD_DIR = __DIR__ . '/../uploads/';
$errors = [];

/* ----------------------------- actions ----------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE staff SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash_set('Staff status updated.');
        header('Location: staff.php');
        exit;
    }

    if ($action === 'resetpin') {
        $id = (int) ($_POST['id'] ?? 0);
        $new = pin_random();
        $q = db()->prepare('UPDATE staff SET pin_hash = ?, pin_enc = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?');
        $q->execute([password_hash($new, PASSWORD_DEFAULT), pin_encrypt($new), $id]);
        $nm = db()->prepare('SELECT name FROM staff WHERE id = ?');
        $nm->execute([$id]);
        flash_set('New PIN for ' . ($nm->fetchColumn() ?: 'that person') . ': ' . $new . ' — write it down now.');
        header('Location: staff.php');
        exit;
    }

    if ($action === 'save') {
        $id     = (int) ($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $pin    = trim($_POST['pin'] ?? '');
        $rate   = $_POST['hourly_rate'] ?? '0';
        $active = isset($_POST['is_active']) ? 1 : 0;
        $roles  = array_map('intval', $_POST['roles'] ?? []);
        $brands = array_map('intval', $_POST['brands'] ?? []);

        if ($name === '' || mb_strlen($name) > 100) {
            $errors[] = 'Enter a name (up to 100 characters).';
        }
        if ($id === 0 && !preg_match('/^\d{4}$/', $pin)) {
            $errors[] = 'Set a 4-digit PIN.';
        }
        if ($pin !== '' && !preg_match('/^\d{4}$/', $pin)) {
            $errors[] = 'The PIN must be exactly 4 digits.';
        }
        if (!is_numeric($rate) || (float) $rate < 0) {
            $errors[] = 'Enter a valid hourly rate.';
        }
        $rate = round((float) $rate, 2);

        // optional photo upload
        $photoName = null; $removeOld = false;
        if (!empty($_FILES['photo']['name']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp  = $_FILES['photo']['tmp_name'];
            $size = (int) $_FILES['photo']['size'];
            if ($size > 2 * 1024 * 1024) {
                $errors[] = 'Photo must be 2 MB or smaller.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($map[$mime])) {
                    $errors[] = 'Photo must be a JPG, PNG or WebP image.';
                } else {
                    $photoName = bin2hex(random_bytes(8)) . '.' . $map[$mime];
                    if (!move_uploaded_file($tmp, $UPLOAD_DIR . $photoName)) {
                        $errors[] = 'Could not save the photo. Check that uploads/ is writable.';
                        $photoName = null;
                    } else {
                        $removeOld = true;
                    }
                }
            }
        }

        if (!$errors) {
            $pdo = db();
            if ($id === 0) {
                $stmt = $pdo->prepare('INSERT INTO staff (name, pin_hash, pin_enc, hourly_rate, photo, is_active, created_at) VALUES (?,?,?,?,?,?,?)');
                $stmt->execute([$name, password_hash($pin, PASSWORD_DEFAULT), pin_encrypt($pin), $rate, $photoName, $active, now_utc()]);
                $id = (int) $pdo->lastInsertId();
            } else {
                // fetch old photo if we're replacing it
                if ($removeOld) {
                    $old = $pdo->prepare('SELECT photo FROM staff WHERE id = ?');
                    $old->execute([$id]);
                    $oldPhoto = $old->fetchColumn();
                }
                if ($pin !== '') {
                    $stmt = $pdo->prepare('UPDATE staff SET name=?, pin_hash=?, pin_enc=?, hourly_rate=?, is_active=? WHERE id=?');
                    $stmt->execute([$name, password_hash($pin, PASSWORD_DEFAULT), pin_encrypt($pin), $rate, $active, $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE staff SET name=?, hourly_rate=?, is_active=? WHERE id=?');
                    $stmt->execute([$name, $rate, $active, $id]);
                }
                if ($photoName !== null) {
                    $pdo->prepare('UPDATE staff SET photo=? WHERE id=?')->execute([$photoName, $id]);
                    if (!empty($oldPhoto) && is_file($UPLOAD_DIR . $oldPhoto)) {
                        @unlink($UPLOAD_DIR . $oldPhoto);
                    }
                }
            }

            // sync roles and brands
            $pdo->prepare('DELETE FROM staff_roles WHERE staff_id=?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO staff_roles (staff_id, role_id) VALUES (?,?)');
            foreach (array_unique($roles) as $rid) { $ins->execute([$id, $rid]); }

            $pdo->prepare('DELETE FROM staff_brands WHERE staff_id=?')->execute([$id]);
            $insb = $pdo->prepare('INSERT INTO staff_brands (staff_id, brand_id) VALUES (?,?)');
            foreach (array_unique($brands) as $bid) { $insb->execute([$id, $bid]); }

            flash_set('Staff saved.');
            header('Location: staff.php');
            exit;
        }
    }
}

/* ----------------------------- view -------------------------------- */

$showForm = isset($_GET['new']) || isset($_GET['edit']) || !empty($errors);
$editId   = (int) ($_GET['edit'] ?? 0);

$allRoles  = db()->query('SELECT id, name, is_active FROM roles ORDER BY name')->fetchAll();
$allBrands = db()->query('SELECT id, name, is_active FROM brands ORDER BY name')->fetchAll();

$hasPinCol = false;
try { db()->query('SELECT pin_enc FROM staff LIMIT 1'); $hasPinCol = true; } catch (Throwable $e) { $hasPinCol = false; }

admin_header('Staff', 'staff.php', 'People, PINs, pay rates, brands and roles');
flash_render();

if ($errors) {
    echo '<div class="flash err">' . implode('<br>', array_map('e', $errors)) . '</div>';
}

if ($showForm):
    $s = ['id' => 0, 'name' => '', 'hourly_rate' => '0.00', 'photo' => null, 'is_active' => 1];
    $asgRoles = $asgBrands = [];
    if ($editId) {
        $stmt = db()->prepare('SELECT * FROM staff WHERE id = ?');
        $stmt->execute([$editId]);
        $s = $stmt->fetch() ?: $s;
        $rq = db()->prepare('SELECT role_id FROM staff_roles WHERE staff_id=?'); $rq->execute([$editId]);
        $asgRoles = array_map('intval', array_column($rq->fetchAll(), 'role_id'));
        $bq = db()->prepare('SELECT brand_id FROM staff_brands WHERE staff_id=?'); $bq->execute([$editId]);
        $asgBrands = array_map('intval', array_column($bq->fetchAll(), 'brand_id'));
    }
    // if the form was re-shown after an error, prefer submitted values
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $s['name'] = $_POST['name'] ?? $s['name'];
        $s['hourly_rate'] = $_POST['hourly_rate'] ?? $s['hourly_rate'];
        $s['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        $asgRoles = array_map('intval', $_POST['roles'] ?? $asgRoles);
        $asgBrands = array_map('intval', $_POST['brands'] ?? $asgBrands);
        $editId = (int) ($_POST['id'] ?? $editId);
        $s['id'] = $editId;
    }
?>
    <div class="panel">
        <div class="panel-head"><h2><?= $s['id'] ? 'Edit staff' : 'Add staff' ?></h2><a class="btn ghost small" href="staff.php">Back to list</a></div>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <div class="form-grid">
                <label>Name
                    <input type="text" name="name" required maxlength="100" value="<?= e($s['name']) ?>">
                </label>
                <label>Hourly rate (<?= e(CURRENCY) ?>)
                    <input type="number" name="hourly_rate" step="0.01" min="0" value="<?= e($s['hourly_rate']) ?>">
                </label>
                <label>4-digit PIN
                    <input type="text" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4"
                           placeholder="<?= $s['id'] ? 'leave blank to keep current' : 'e.g. 4921' ?>"
                           <?= $s['id'] ? '' : 'required' ?>>
                    <div class="hint">Staff type this at the kiosk. Leading zeros are fine.</div>
                </label>
                <label>Photo (optional)
                    <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
                    <?php if (!empty($s['photo'])): ?>
                        <div class="hint">Current: <img class="avatar" src="../uploads/<?= e($s['photo']) ?>" alt=""> uploading a new one replaces it.</div>
                    <?php endif; ?>
                </label>
            </div>

            <label style="margin-top:6px">Brands this person can work for</label>
            <div class="check-grid">
                <?php foreach ($allBrands as $b): ?>
                    <label><input type="checkbox" name="brands[]" value="<?= (int) $b['id'] ?>" <?= in_array((int) $b['id'], $asgBrands, true) ? 'checked' : '' ?>>
                        <?= e($b['name']) ?><?= $b['is_active'] ? '' : ' <span class="muted">(inactive)</span>' ?></label>
                <?php endforeach; ?>
                <?php if (!$allBrands): ?><span class="muted">No brands yet — add some under Brands &amp; roles.</span><?php endif; ?>
            </div>

            <label style="margin-top:14px">Roles</label>
            <div class="check-grid">
                <?php foreach ($allRoles as $r): ?>
                    <label><input type="checkbox" name="roles[]" value="<?= (int) $r['id'] ?>" <?= in_array((int) $r['id'], $asgRoles, true) ? 'checked' : '' ?>>
                        <?= e($r['name']) ?><?= $r['is_active'] ? '' : ' <span class="muted">(inactive)</span>' ?></label>
                <?php endforeach; ?>
                <?php if (!$allRoles): ?><span class="muted">No roles yet — add some under Brands &amp; roles.</span><?php endif; ?>
            </div>

            <label class="check-grid" style="margin-top:14px"><span style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?>> Active (shows on the kiosk)</span></label>

            <div class="actions" style="margin-top:16px">
                <button class="btn" type="submit">Save</button>
                <a class="btn ghost" href="staff.php">Cancel</a>
            </div>
        </form>
    </div>
<?php else:
    $staff = db()->query(
        'SELECT s.*, (SELECT COUNT(*) FROM time_entries te WHERE te.staff_id = s.id AND te.clock_out IS NULL) AS open_count
         FROM staff s ORDER BY s.is_active DESC, s.name'
    )->fetchAll();

    $roleMap = $brandMap = [];
    foreach (db()->query('SELECT sr.staff_id, r.name FROM staff_roles sr JOIN roles r ON r.id = sr.role_id')->fetchAll() as $r) {
        $roleMap[(int) $r['staff_id']][] = $r['name'];
    }
    foreach (db()->query('SELECT sb.staff_id, b.name FROM staff_brands sb JOIN brands b ON b.id = sb.brand_id')->fetchAll() as $r) {
        $brandMap[(int) $r['staff_id']][] = $r['name'];
    }
    function initials_admin(string $n): string {
        $p = preg_split('/\s+/', trim($n));
        return strtoupper(($p[0][0] ?? '') . (isset($p[1]) ? ($p[1][0] ?? '') : ''));
    }
?>
    <div class="panel">
        <div class="panel-head"><h2>Staff</h2><a class="btn" href="staff.php?new=1">Add staff</a></div>
        <div class="table-wrap"><table class="grid">
            <thead><tr><th></th><th>Name</th><th>PIN</th><th>Brands</th><th>Roles</th><th class="num">Rate</th><th>State</th><th></th></tr></thead>
            <tbody>
            <?php if (!$staff): ?>
                <tr class="empty-row"><td colspan="8">No staff yet. Add your first person to see them on the kiosk.</td></tr>
            <?php endif; ?>
            <?php foreach ($staff as $s):
                $id = (int) $s['id'];
                $on = (int) $s['open_count'] > 0;
            ?>
                <tr style="<?= $s['is_active'] ? '' : 'opacity:.55' ?>">
                    <td data-label="">
                        <?php if (!empty($s['photo'])): ?>
                            <img class="avatar" src="../uploads/<?= e($s['photo']) ?>" alt="">
                        <?php else: ?>
                            <span class="avatar"><?= e(initials_admin($s['name'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Name"><?= e($s['name']) ?><?= $s['is_active'] ? '' : ' <span class="badge off">inactive</span>' ?></td>
                    <td data-label="PIN">
                        <?php $shown = $hasPinCol ? pin_decrypt($s['pin_enc'] ?? null) : null; ?>
                        <?php if ($shown !== null): ?>
                            <span class="pinbox">
                                <span class="mono dots">••••</span>
                                <span class="mono val" hidden><?= e($shown) ?></span>
                                <button type="button" class="btn-link" onclick="var b=this.closest('.pinbox');var d=b.querySelector('.dots'),v=b.querySelector('.val');var show=d.hidden;d.hidden=!show;v.hidden=show;this.textContent=show?'Show':'Hide';">Show</button>
                            </span>
                        <?php else: ?>
                            <span class="muted mono">••••</span>
                            <span class="hint" style="margin:0 0 0 6px;display:inline">reset to see</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Brands"><?php foreach (($brandMap[$id] ?? []) as $n) echo '<span class="tag">' . e($n) . '</span>'; ?></td>
                    <td data-label="Roles"><?php foreach (($roleMap[$id] ?? []) as $n) echo '<span class="tag">' . e($n) . '</span>'; ?></td>
                    <td data-label="Rate" class="num"><?= e(money((float) $s['hourly_rate'])) ?></td>
                    <td data-label="State"><?= $on ? '<span class="badge on">on shift</span>' : '<span class="badge off">off</span>' ?></td>
                    <td data-label="">
                        <div class="actions">
                            <a class="btn-link" href="staff.php?edit=<?= $id ?>">Edit</a>
                            <form class="inline-form" method="post" onsubmit="return confirm('Give this person a new random PIN? The old one stops working immediately.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resetpin">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="btn-link" type="submit">Reset PIN</button>
                            </form>
                            <form class="inline-form" method="post" onsubmit="return confirm('<?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?> this person?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $id ?>">
                                <button class="btn-link" type="submit"><?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="hint">Staff are deactivated rather than deleted, so their hours stay intact for past pay periods.</p>
    </div>
<?php endif;

admin_footer();
