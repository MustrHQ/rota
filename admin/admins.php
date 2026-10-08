<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

$meId = (int) ($_SESSION['admin_id'] ?? 0);

/* ----------------------------- actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $u  = trim($_POST['username'] ?? '');
        $p  = $_POST['password'] ?? '';
        $p2 = $_POST['confirm'] ?? '';
        if ($u === '' || mb_strlen($u) > 60) {
            flash_set('Enter a username (up to 60 characters).', 'err');
        } elseif (strlen($p) < 8) {
            flash_set('Use a password of at least 8 characters.', 'err');
        } elseif ($p !== $p2) {
            flash_set('The passwords do not match.', 'err');
        } else {
            $chk = db()->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
            $chk->execute([$u]);
            if ((int) $chk->fetchColumn() > 0) {
                flash_set('That username is already taken.', 'err');
            } else {
                db()->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?,?,?)')
                    ->execute([$u, password_hash($p, PASSWORD_DEFAULT), now_utc()]);
                flash_set('Admin added.');
            }
        }
    } elseif ($action === 'passwd') {
        $id = (int) ($_POST['id'] ?? 0);
        $p  = $_POST['password'] ?? '';
        $p2 = $_POST['confirm'] ?? '';
        if (strlen($p) < 8) {
            flash_set('Use a password of at least 8 characters.', 'err');
        } elseif ($p !== $p2) {
            flash_set('The passwords do not match.', 'err');
        } elseif ($id > 0) {
            db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
            flash_set('Password changed.');
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $total = (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($id === $meId) {
            flash_set("You can't delete the account you're logged in with.", 'err');
        } elseif ($total <= 1) {
            flash_set('There must always be at least one admin.', 'err');
        } elseif ($id > 0) {
            db()->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
            flash_set('Admin removed.');
        }
    }
    header('Location: admins.php');
    exit;
}

/* ----------------------------- view -------------------------------- */
$admins = db()->query('SELECT id, username, created_at FROM admins ORDER BY username')->fetchAll();

admin_header('Admins', 'admins.php', 'Who can sign in to this admin area');
flash_render();
?>

<div class="panel">
    <div class="panel-head"><h2>Add an admin</h2></div>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <label>Username<input type="text" name="username" maxlength="60" required></label>
            <label>Password<input type="password" name="password" minlength="8" required></label>
        </div>
        <label>Confirm password<input type="password" name="confirm" minlength="8" required></label>
        <div class="actions" style="margin-top:6px"><button class="btn" type="submit">Add admin</button></div>
    </form>
</div>

<div class="panel">
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Username</th><th>Added</th><th>Change password</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($admins as $a):
            $id = (int) $a['id'];
            $isMe = $id === $meId;
        ?>
            <tr>
                <td data-label="Username"><?= e($a['username']) ?><?php if ($isMe): ?> <span class="badge on">you</span><?php endif; ?></td>
                <td data-label="Added" class="mono"><?= e(fmt_date_local($a['created_at'])) ?></td>
                <td data-label="Change password">
                    <form method="post" class="actions" style="gap:6px" autocomplete="off" onsubmit="return this.password.value === this.confirm.value || (alert('Passwords do not match.'), false);">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="passwd">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="password" name="password" placeholder="New password" minlength="8" required style="width:auto;min-width:150px;margin:0">
                        <input type="password" name="confirm" placeholder="Confirm" minlength="8" required style="width:auto;min-width:130px;margin:0">
                        <button class="btn-link" type="submit">Save</button>
                    </form>
                </td>
                <td data-label="">
                    <?php if (!$isMe): ?>
                        <form class="inline-form" method="post" onsubmit="return confirm('Remove this admin?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn-link danger" type="submit">Delete</button>
                        </form>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="hint">Every admin has the same access. You can't delete your own account or the last remaining admin.</p>
</div>

<?php admin_footer(); ?>
