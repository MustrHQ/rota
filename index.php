<?php
/**
 * Front door. Nobody should ever see a file listing here.
 * Not installed yet -> the installer. Otherwise -> the admin sign-in.
 */
$configured = false;
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    $configured = defined('DB_NAME') && DB_NAME !== 'your_db_name';
}

if (!$configured && is_file(__DIR__ . '/install.php')) {
    header('Location: install.php');
    exit;
}

header('Location: admin/login.php');
exit;
