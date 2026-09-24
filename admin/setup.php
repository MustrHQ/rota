<?php
require_once __DIR__ . '/../helpers.php';

$existing = (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();

// Once an admin exists this page is closed. Delete the file afterwards.
if ($existing > 0) {
    http_response_code(403);
    $done = true;
} else {
    $done = false;
}

$error = '';
if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u  = trim($_POST['username'] ?? '');
    $p  = $_POST['password'] ?? '';
    $p2 = $_POST['confirm'] ?? '';

    if ($u === '' || strlen($u) > 60) {
        $error = 'Choose a username (up to 60 characters).';
    } elseif (strlen($p) < 8) {
        $error = 'Use a password of at least 8 characters.';
    } elseif ($p !== $p2) {
        $error = 'The two passwords do not match.';
    } else {
        $stmt = db()->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$u, password_hash($p, PASSWORD_DEFAULT), now_utc()]);
        $created = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css<?= asset_v('style.css') ?>">
</head>
<body class="admin centered">
<div class="card auth-card">
    <img class="brand-logo" style="height:32px;margin-bottom:18px" src="../assets/logo-primary.svg<?= asset_v('logo-primary.svg') ?>" alt="<?= e(APP_NAME) ?>">
    <?php if ($done): ?>
        <h1>Setup already done</h1>
        <p class="muted">An admin account already exists. For safety, delete <code>admin/setup.php</code> from your server.</p>
        <a class="btn" href="login.php">Go to log in</a>
    <?php elseif (!empty($created)): ?>
        <h1>Admin created</h1>
        <p class="muted">You can now log in. Please delete <code>admin/setup.php</code> from your server so it can't be used again.</p>
        <a class="btn" href="login.php">Go to log in</a>
    <?php else: ?>
        <h1>Create the first admin</h1>
        <p class="muted">This is the manager login for the whole system.</p>
        <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <label>Username
                <input type="text" name="username" required maxlength="60" value="<?= e($_POST['username'] ?? '') ?>">
            </label>
            <label>Password
                <input type="password" name="password" required minlength="8">
            </label>
            <label>Confirm password
                <input type="password" name="confirm" required minlength="8">
            </label>
            <button class="btn" type="submit">Create admin</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
