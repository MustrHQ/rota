<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

// This page needs the kiosk_codes table. If a site was set up before this
// feature existed, point them at the one-click database update.
try {
    db()->query('SELECT 1 FROM kiosk_codes LIMIT 1');
} catch (Throwable $ex) {
    admin_header('Kiosks', 'kiosks.php', 'Pairing codes for your tablets');
    echo '<div class="flash warn">This feature needs a quick database update. <a href="upgrade.php">Run it now</a>, then come back.</div>';
    admin_footer();
    exit;
}

function unique_code(): string
{
    $chk = db()->prepare('SELECT COUNT(*) FROM kiosk_codes WHERE code = ?');
    do {
        $c = gen_kiosk_code();
        $chk->execute([$c]);
    } while ((int) $chk->fetchColumn() > 0);
    return $c;
}

/* ----------------------------- actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $label = trim($_POST['label'] ?? '');
        $brand = ($_POST['brand_id'] ?? '') !== '' ? (int) $_POST['brand_id'] : null;
        $code  = strtoupper(trim($_POST['code'] ?? ''));
        if ($label === '' || mb_strlen($label) > 80) {
            flash_set('Give the kiosk a label (up to 80 characters).', 'err');
        } else {
            if ($code === '') {
                $code = unique_code();
            } elseif (!preg_match('/^[A-Z0-9]{4,40}$/', $code)) {
                flash_set('A custom code must be 4–40 letters or numbers.', 'err');
                header('Location: kiosks.php'); exit;
            } else {
                $chk = db()->prepare('SELECT COUNT(*) FROM kiosk_codes WHERE code = ?');
                $chk->execute([$code]);
                if ((int) $chk->fetchColumn() > 0) {
                    flash_set('That code is already in use — pick another.', 'err');
                    header('Location: kiosks.php'); exit;
                }
            }
            db()->prepare('INSERT INTO kiosk_codes (code, label, brand_id, created_at) VALUES (?,?,?,?)')
                ->execute([$code, $label, $brand, now_utc()]);
            flash_set('Kiosk code created: ' . $code . '.');
        }
    } elseif ($action === 'update') {
        $id    = (int) ($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $brand = ($_POST['brand_id'] ?? '') !== '' ? (int) $_POST['brand_id'] : null;
        if ($id && $label !== '' && mb_strlen($label) <= 80) {
            db()->prepare('UPDATE kiosk_codes SET label = ?, brand_id = ? WHERE id = ?')->execute([$label, $brand, $id]);
            flash_set('Kiosk updated.');
        }
    } elseif ($action === 'regen') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare('UPDATE kiosk_codes SET code = ? WHERE id = ?')->execute([unique_code(), $id]);
            flash_set('New code generated. Any device using the old code will need setting up again.');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE kiosk_codes SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash_set('Updated.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM kiosk_codes WHERE id = ?')->execute([$id]);
        flash_set('Kiosk code deleted.');
    }
    header('Location: kiosks.php');
    exit;
}

/* ----------------------------- view -------------------------------- */
$allBrands = db()->query('SELECT id, name, is_active FROM brands ORDER BY name')->fetchAll();
$codes = db()->query(
    'SELECT k.*, b.name AS brand_name FROM kiosk_codes k
     LEFT JOIN brands b ON b.id = k.brand_id ORDER BY k.label'
)->fetchAll();

admin_header('Kiosks', 'kiosks.php');
flash_render();
?>

<div class="panel">
    <h2>How kiosk setup works</h2>
    <p class="muted" style="max-width:74ch">
        Create a code for each tablet. On the tablet, open <code>kiosk/index.php</code> and type the code once — that device stays set up until you unpair it.
        If a code is tied to a brand, that kiosk tags every clock-in with that brand automatically (no brand prompt). A code with no brand asks staff who work more than one brand.
        <?php if (defined('KIOSK_KEY') && KIOSK_KEY !== ''): ?><br><br>Your old single <code>KIOSK_KEY</code> from <code>config.php</code> still works too.<?php endif; ?>
    </p>
</div>

<div class="panel">
    <div class="panel-head"><h2>Add a kiosk code</h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-grid">
            <label>Label
                <input type="text" name="label" maxlength="80" required placeholder="e.g. Front station">
            </label>
            <label>Brand for this kiosk
                <select name="brand_id">
                    <option value="">All brands (ask staff)</option>
                    <?php foreach ($allBrands as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?><?= $b['is_active'] ? '' : ' (inactive)' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>Custom code (optional)
            <input type="text" name="code" maxlength="40" placeholder="leave blank to generate one" style="max-width:280px;text-transform:uppercase">
            <div class="hint">4–40 letters or numbers. Leave blank and we'll make a short one for you.</div>
        </label>
        <div class="actions" style="margin-top:6px"><button class="btn" type="submit">Add kiosk code</button></div>
    </form>
</div>

<div class="panel">
    <div class="table-wrap"><table class="grid">
        <thead><tr><th>Kiosk</th><th>Code</th><th>Status</th><th>Last used</th><th></th></tr></thead>
        <tbody>
        <?php if (!$codes): ?>
            <tr class="empty-row"><td colspan="5">No kiosk codes yet. Add one above, then set up your tablet with it.</td></tr>
        <?php endif; ?>
        <?php foreach ($codes as $k):
            $id = (int) $k['id'];
        ?>
            <tr style="<?= $k['is_active'] ? '' : 'opacity:.6' ?>">
                <td data-label="Kiosk">
                    <form method="post" class="actions" style="gap:6px;align-items:flex-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="text" name="label" value="<?= e($k['label']) ?>" maxlength="80" style="width:auto;min-width:150px;margin:0">
                        <select name="brand_id" style="width:auto;margin:0">
                            <option value="">All brands</option>
                            <?php foreach ($allBrands as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $k['brand_id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn-link" type="submit">Save</button>
                    </form>
                    <div class="hint">On the tablet: open the kiosk and enter this code, or visit <code>kiosk/index.php?code=<?= e($k['code']) ?></code></div>
                </td>
                <td data-label="Code">
                    <span class="mono" style="font-size:16px;letter-spacing:.08em"><?= e($k['code']) ?></span>
                    <form class="inline-form" method="post" onsubmit="return confirm('Generate a new code? The current one stops working.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="regen">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button class="btn-link" type="submit">Regenerate</button>
                    </form>
                </td>
                <td data-label="Status">
                    <?= $k['is_active'] ? '<span class="badge on">active</span>' : '<span class="badge off">inactive</span>' ?>
                    <?php if ($k['brand_name']): ?><br><span class="tag"><?= e($k['brand_name']) ?></span><?php else: ?><br><span class="muted" style="font-size:12.5px">all brands</span><?php endif; ?>
                </td>
                <td data-label="Last used" class="mono"><?= $k['last_used_at'] ? e(fmt_dt_local($k['last_used_at'], 'j M, H:i')) : '<span class="muted">never</span>' ?></td>
                <td data-label="">
                    <div class="actions">
                        <form class="inline-form" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn-link" type="submit"><?= $k['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <form class="inline-form" method="post" onsubmit="return confirm('Delete this kiosk code? Any device using it will be logged out.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $id ?>">
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
