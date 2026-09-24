<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

$done = null; $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'upgrade') {
        try {
            $n = apply_schema_file(db(), __DIR__ . '/../schema.sql');
            flash_set('Database is up to date (' . $n . ' statement' . ($n === 1 ? '' : 's') . ' applied).');
        } catch (Throwable $e) {
            flash_set('Update failed: ' . $e->getMessage(), 'err');
        }
        header('Location: upgrade.php');
        exit;
    }
}

// current tables (for reassurance)
$tables = [];
try {
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) { $tables[] = $r[0]; }
} catch (Throwable $e) { /* ignore */ }

admin_header('Database update', 'index.php');
flash_render();
?>

<div class="panel">
    <h2>Apply database updates</h2>
    <p class="muted" style="max-width:74ch">
        This creates any tables added by newer versions (for example, kiosk codes) and fills in the starter brands and roles if they're missing.
        It's safe to run more than once — existing tables and data are left alone.
    </p>
    <form method="post" style="margin-top:12px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upgrade">
        <button class="btn" type="submit">Run database update</button>
    </form>
</div>

<div class="panel">
    <h2>Tables in your database</h2>
    <?php if ($tables): ?>
        <p><?php foreach ($tables as $t): ?><span class="tag mono"><?= e($t) ?></span> <?php endforeach; ?></p>
    <?php else: ?>
        <p class="muted">Could not list tables.</p>
    <?php endif; ?>
</div>

<?php admin_footer(); ?>
