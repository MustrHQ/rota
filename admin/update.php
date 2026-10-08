<?php
require_once __DIR__ . '/../helpers.php';
require_admin();

/**
 * Take a zip of the current code (for rollback) before we overwrite anything.
 * Skips config.php (secrets), uploads/ (photos) and backups/ (itself).
 */
function backup_code(string $root, string $backupDir): ?string
{
    if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) return null;
    $file = $backupDir . '/backup-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return null;

    $dirIt = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($dirIt, function ($cur) {
        $n = $cur->getFilename();
        if ($cur->isDir() && ($n === 'backups' || $n === 'uploads')) return false; // don't recurse into these
        return true;
    });
    foreach (new RecursiveIteratorIterator($filter) as $f) {
        if ($f->isDir()) continue;
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
        if ($rel === 'config.php') continue;
        $zip->addFile($f->getPathname(), $rel);
    }
    $zip->close();
    return is_file($file) ? $file : null;
}

/**
 * Extract an uploaded zip over the app, safely.
 * Strips a single wrapper folder if present, blocks path traversal, and never
 * touches config.php, uploads/ or backups/.
 */
function apply_update(string $zipPath, string $root): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('the file is not a readable zip.');

    // Detect a single common top-level folder (e.g. "kitchen-clock/") to strip.
    $top = null; $common = true;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if ($n === false || $n === '') continue;
        $seg = explode('/', str_replace('\\', '/', $n), 2)[0];
        if ($seg === '') { $common = false; break; }
        if ($top === null) $top = $seg;
        elseif ($top !== $seg) { $common = false; break; }
    }
    $prefix = ($common && $top !== null && $top !== '') ? $top . '/' : '';

    $written = 0; $preserved = 0; $rejected = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        $name = str_replace('\\', '/', $name);
        $rel = ($prefix !== '' && strpos($name, $prefix) === 0) ? substr($name, strlen($prefix)) : $name;
        if ($rel === '') continue;

        // safety: no absolute paths, no traversal, no null bytes
        if ($rel[0] === '/' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) { $rejected++; continue; }

        // never overwrite these
        if ($rel === 'config.php'
            || $rel === 'uploads' || strpos($rel, 'uploads/') === 0
            || $rel === 'backups' || strpos($rel, 'backups/') === 0) { $preserved++; continue; }

        $dest = $root . '/' . $rel;
        if (substr($rel, -1) === '/') { if (!is_dir($dest)) @mkdir($dest, 0755, true); continue; }
        $dir = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $data = $zip->getFromIndex($i);
        if ($data === false || file_put_contents($dest, $data) === false) { $rejected++; continue; }
        $written++;
    }
    $zip->close();
    return ['written' => $written, 'preserved' => $preserved, 'rejected' => $rejected];
}

/* ----------------------------- action ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    csrf_check();
    $root = dirname(__DIR__);
    $ok = true;
    if (!class_exists('ZipArchive')) {
        flash_set("This server doesn't have PHP's zip extension (ZipArchive), so uploads can't be extracted. Update the files over FTP/File Manager instead.", 'err'); $ok = false;
    } elseif (empty($_POST['confirm'])) {
        flash_set('Please tick the box to confirm you have a backup before updating.', 'err'); $ok = false;
    } elseif (!isset($_FILES['package']) || ($_FILES['package']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_set('No file was uploaded, or it was larger than the server upload limit.', 'err'); $ok = false;
    } elseif (!is_uploaded_file($_FILES['package']['tmp_name'])) {
        flash_set('The upload could not be verified. Please try again.', 'err'); $ok = false;
    } elseif (strtolower((string) pathinfo($_FILES['package']['name'], PATHINFO_EXTENSION)) !== 'zip') {
        flash_set('Please choose a .zip file.', 'err'); $ok = false;
    }

    if ($ok) {
        try {
            $backup = backup_code($root, $root . '/backups');
            $res = apply_update($_FILES['package']['tmp_name'], $root);
            $msg = 'Update applied — ' . $res['written'] . ' file(s) written';
            if ($res['preserved']) $msg .= ', ' . $res['preserved'] . ' preserved (config.php & uploads)';
            if ($res['rejected'])  $msg .= ', ' . $res['rejected'] . ' skipped as unsafe';
            $msg .= $backup
                ? ('. A rollback backup was saved to backups/' . basename($backup) . '.')
                : '. (Heads up: a backup could not be written — check the backups/ folder is writable.)';
            flash_set($msg);
        } catch (Throwable $e) {
            flash_set('Update failed: ' . $e->getMessage() . ' Nothing was changed if this happened before extraction; otherwise restore from your backup.', 'err');
        }
    }
    header('Location: update.php');
    exit;
}

/* ----------------------------- view -------------------------------- */
$backups = [];
$bdir = dirname(__DIR__) . '/backups';
if (is_dir($bdir)) {
    foreach (glob($bdir . '/backup-*.zip') ?: [] as $b) $backups[] = basename($b);
    rsort($backups);
}

admin_header('Updates', 'update.php', 'Upload a release and keep your data');
flash_render();
?>

<div class="panel">
    <h2>Update the app from a zip</h2>
    <p class="muted" style="max-width:74ch">
        Upload a release zip (the same download you'd normally extract over the site). The files are extracted in place, so the app updates without FTP.
        Your <code>config.php</code> and everything in <code>uploads/</code> are never overwritten, and a backup of the current code is saved to <code>backups/</code> first so you can roll back.
    </p>
    <div class="flash warn" style="margin-top:6px">
        This replaces application files on your live site. Only trusted admins should use it. Take a database backup (phpMyAdmin → Export) before a big update, and if a new version needs database changes, run the <a href="upgrade.php">database update</a> afterwards.
    </div>

    <form method="post" enctype="multipart/form-data" style="margin-top:14px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="apply">
        <label>Release zip
            <input type="file" name="package" accept=".zip,application/zip" required>
            <div class="hint">A zip of the app. A single wrapping folder (like <code>kitchen-clock/</code>) is handled automatically.</div>
        </label>
        <label class="check-inline" style="display:flex;align-items:flex-start;gap:9px;margin:12px 0 16px">
            <input type="checkbox" name="confirm" value="1" style="width:auto;margin:3px 0 0" required>
            <span>I've backed up the database (and I understand this overwrites the app files).</span>
        </label>
        <button class="btn" type="submit">Upload &amp; update</button>
    </form>
</div>

<div class="panel">
    <h2>Rollback backups</h2>
    <?php if ($backups): ?>
        <p class="muted">Saved in the <code>backups/</code> folder. To roll back, extract one of these over the site (via File Manager). Newest first:</p>
        <p><?php foreach (array_slice($backups, 0, 12) as $b): ?><span class="tag mono"><?= e($b) ?></span> <?php endforeach; ?></p>
    <?php else: ?>
        <p class="muted">No backups yet — one is created automatically the first time you run an update.</p>
    <?php endif; ?>
</div>

<?php admin_footer(); ?>
