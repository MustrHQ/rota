<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

// only these two are allowed; map to real table names (never trust input as a table)
$TYPES = [
    'brand' => ['table' => 'brands', 'one' => 'Brand', 'many' => 'Brands'],
    'role'  => ['table' => 'roles',  'one' => 'Role',  'many' => 'Roles'],
];

function resolve_type(array $TYPES, ?string $t): string
{
    return isset($TYPES[$t]) ? $t : 'brand';
}

/* ----------------------------- actions ----------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type   = resolve_type($TYPES, $_POST['type'] ?? '');
    $table  = $TYPES[$type]['table'];
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '' && mb_strlen($name) <= 80) {
            db()->prepare("INSERT INTO {$table} (name) VALUES (?)")->execute([$name]);
            flash_set($TYPES[$type]['one'] . ' added.');
        } else {
            flash_set('Enter a name (up to 80 characters).', 'err');
        }
    } elseif ($action === 'rename') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id && $name !== '' && mb_strlen($name) <= 80) {
            db()->prepare("UPDATE {$table} SET name = ? WHERE id = ?")->execute([$name, $id]);
            flash_set('Renamed.');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("UPDATE {$table} SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        flash_set('Updated.');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
        flash_set('Deleted.');
    }
    header('Location: lists.php?type=' . $type);
    exit;
}

/* ----------------------------- view -------------------------------- */
$type  = resolve_type($TYPES, $_GET['type'] ?? '');
$table = $TYPES[$type]['table'];
$items = db()->query("SELECT id, name, is_active FROM {$table} ORDER BY name")->fetchAll();

// usage counts (for a gentle heads-up before deleting)
$usage = [];
if ($type === 'brand') {
    foreach (db()->query('SELECT brand_id, COUNT(*) c FROM time_entries WHERE brand_id IS NOT NULL GROUP BY brand_id')->fetchAll() as $r) {
        $usage[(int) $r['brand_id']] = (int) $r['c'];
    }
} else {
    foreach (db()->query('SELECT role_id, COUNT(*) c FROM staff_roles GROUP BY role_id')->fetchAll() as $r) {
        $usage[(int) $r['role_id']] = (int) $r['c'];
    }
}

admin_header('Brands & roles', 'lists.php');
flash_render();
?>

<div class="panel">
    <div class="panel-head" style="gap:6px">
        <div class="actions">
            <a class="btn small <?= $type === 'brand' ? '' : 'ghost' ?>" href="lists.php?type=brand">Brands</a>
            <a class="btn small <?= $type === 'role' ? '' : 'ghost' ?>" href="lists.php?type=role">Roles</a>
        </div>
    </div>

    <form method="post" class="filters" style="margin-bottom:20px">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <input type="hidden" name="action" value="add">
        <div class="field" style="min-width:240px"><label>New <?= e(strtolower($TYPES[$type]['one'])) ?>
            <input type="text" name="name" maxlength="80" required placeholder="Name">
        </label></div>
        <button class="btn" type="submit">Add</button>
    </form>

    <div class="table-wrap"><table class="grid">
        <thead><tr><th><?= e($TYPES[$type]['one']) ?></th><th>Status</th><th class="num">Used by</th><th></th></tr></thead>
        <tbody>
        <?php if (!$items): ?>
            <tr class="empty-row"><td colspan="4">Nothing here yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $it):
            $id = (int) $it['id'];
            $u  = $usage[$id] ?? 0;
            $delWarn = $type === 'brand'
                ? 'Delete this brand? Past entries will keep their hours but lose the brand label.'
                : 'Delete this role? It will be removed from anyone who has it.';
        ?>
            <tr style="<?= $it['is_active'] ? '' : 'opacity:.6' ?>">
                <td>
                    <form method="post" class="actions" style="gap:6px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="type" value="<?= e($type) ?>">
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="text" name="name" value="<?= e($it['name']) ?>" maxlength="80" style="width:auto;min-width:180px;margin:0">
                        <button class="btn-link" type="submit">Save</button>
                    </form>
                </td>
                <td><?= $it['is_active'] ? '<span class="badge on">active</span>' : '<span class="badge off">inactive</span>' ?></td>
                <td class="num"><?= $u ?></td>
                <td>
                    <div class="actions">
                        <form class="inline-form" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="type" value="<?= e($type) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <button class="btn-link" type="submit"><?= $it['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                        <form class="inline-form" method="post" onsubmit="return confirm('<?= e($delWarn) ?>');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="type" value="<?= e($type) ?>">
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
    <p class="hint">Deactivating hides a <?= e(strtolower($TYPES[$type]['one'])) ?> from the kiosk and new assignments without touching past records.</p>
</div>

<?php admin_footer(); ?>
