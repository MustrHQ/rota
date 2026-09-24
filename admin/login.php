<?php
require_once __DIR__ . '/../helpers.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$admins = (int) db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $stmt = db()->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$u]);
    $admin = $stmt->fetch();
    if ($admin && password_verify($p, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id']   = (int) $admin['id'];
        $_SESSION['admin_name'] = $admin['username'];
        header('Location: index.php');
        exit;
    }
    $error = 'Wrong username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css<?= asset_v('style.css') ?>">
</head>
<body class="admin centered">
<div class="card auth-card">
    <img class="brand-logo" style="height:32px;margin-bottom:18px" src="../assets/logo-primary.svg<?= asset_v('logo-primary.svg') ?>" alt="<?= e(APP_NAME) ?>">
    <h1>Log in</h1>
    <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
    <?php if ($admins === 0): ?>
        <div class="flash warn">No admin exists yet. <a href="setup.php">Create the first admin</a>.</div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label>Username
            <input type="text" name="username" required autofocus>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button class="btn" type="submit">Log in</button>
    </form>
</div>
</body>
</html>
