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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#01216C">
<title>Sign in · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="icon" href="../assets/logo-mark.svg">
<link rel="stylesheet" href="../assets/style.css<?= asset_v('style.css') ?>">
</head>
<body class="auth-body">
<div class="auth">
  <section class="auth-art">
    <img class="auth-logo" src="../assets/logo-light.svg<?= asset_v('logo-light.svg') ?>" alt="<?= e(APP_NAME) ?>">
    <h2>Every shift, <span>counted properly</span>.</h2>
    <p>Staff clock in and out on the tablet. Hours, rotas and wages stay right without anyone chasing paper.</p>
    <ul>
      <li><i>1</i> Tap-and-PIN clock in on any wall tablet</li>
      <li><i>2</i> Rotas, late arrivals and no-shows at a glance</li>
      <li><i>3</i> Hours and wage totals ready to export</li>
    </ul>
    <small>Free and open source · mustrhq.app</small>
  </section>

  <section class="auth-form">
    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <h1>Welcome back</h1>
      <p class="lead">Sign in to manage <?= e(APP_NAME) ?>.</p>
      <?php if ($error): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
      <?php if ($admins === 0): ?>
        <div class="flash warn">No admin account exists yet. <a href="setup.php">Create the first one</a>.</div>
      <?php endif; ?>
      <label>Username
        <input type="text" name="username" required autofocus autocomplete="username">
      </label>
      <label>Password
        <div class="pw">
          <input type="password" name="password" id="pw" required autocomplete="current-password">
          <button type="button" onclick="const i=document.getElementById('pw');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Show':'Hide'">Show</button>
        </div>
      </label>
      <button class="btn" type="submit">Sign in</button>
      <p class="fine">Forgotten your password? Another admin can change it under Admins.</p>
    </form>
  </section>
</div>
</body>
</html>
