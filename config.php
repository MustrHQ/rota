<?php
/**
 * MustrHQ — configuration
 * Edit the values below, then import schema.sql into your MySQL database.
 */

// ---- Database (from your cPanel MySQL account) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// ---- App settings ----
define('APP_NAME', 'MustrHQ');   // shown on the kiosk and admin
define('APP_TZ', 'Europe/London');     // all times are shown in this timezone
define('CURRENCY', '£');               // prefix for wage totals

// ---- Attendance rules ----
define('GRACE_MINUTES', 10);           // minutes after expected start before a clock-in counts as "late"
define('NOSHOW_AFTER_MINUTES', 60);    // minutes past expected start with no clock-in => "no-show" (today view)
define('OPEN_SHIFT_ALERT_HOURS', 16);  // a shift still open after this many hours => a forgotten clock-out (dashboard alert)
define('CLOCK_COOLDOWN_SECONDS', 30);  // ignore an accidental repeat tap by the same person within this many seconds

// ---- Kiosk PIN safety ----
define('PIN_MAX_ATTEMPTS', 5);         // wrong PINs in a row before a short lockout
define('PIN_LOCK_SECONDS', 60);        // how long that person is locked out

// ---- Kiosk gate ----
// Leave empty to allow the kiosk page for anyone who has the URL.
// Set a secret string to lock it: first visit must be kiosk/index.php?key=YOUR_SECRET
// (that browser is then remembered). See README.
define('KIOSK_KEY', '');

// ---- Session cookie name ----
define('SESSION_NAME', 'kclock');
