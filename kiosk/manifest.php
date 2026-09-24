<?php
// Web App Manifest — makes the kiosk installable as a fullscreen tablet app.
require __DIR__ . '/../config.php';
header('Content-Type: application/manifest+json; charset=utf-8');

$name = defined('APP_NAME') && APP_NAME !== '' ? APP_NAME : 'MustrHQ';

echo json_encode([
    'name'             => $name,
    'short_name'       => mb_substr($name, 0, 12),
    'description'      => 'Staff clock in and out',
    'start_url'        => 'index.php',
    'scope'            => './',
    'display'          => 'fullscreen',
    'display_override' => ['fullscreen', 'standalone'],
    'orientation'      => 'any',
    'background_color' => '#01216C',
    'theme_color'      => '#01216C',
    'icons'            => [
        ['src' => 'icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
