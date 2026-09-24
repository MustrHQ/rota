<?php
require_once __DIR__ . '/db.php';

// If the app hasn't been set up yet, send visitors to the installer.
// (Every page that includes this file lives one level deep, e.g. /admin or /kiosk.)
if (defined('DB_NAME') && DB_NAME === 'your_db_name' && is_file(__DIR__ . '/install.php')) {
    header('Location: ../install.php');
    exit;
}

// We store everything in UTC internally and only convert for display.
date_default_timezone_set('UTC');

// Defaults for settings a newer version may use that an older config.php lacks
// (file updates preserve config.php, so new settings won't be there yet).
if (!defined('OPEN_SHIFT_ALERT_HOURS')) define('OPEN_SHIFT_ALERT_HOURS', 16);
if (!defined('CLOCK_COOLDOWN_SECONDS')) define('CLOCK_COOLDOWN_SECONDS', 30);

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/* ---------------------------------------------------------------- output */

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------ CSRF */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        exit('Your session expired. Go back, reload the page and try again.');
    }
}

/* ------------------------------------------------------------ admin auth */

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

function current_admin_name(): string
{
    return $_SESSION['admin_name'] ?? '';
}

/* ------------------------------------------------------------------ time */

function app_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) $tz = new DateTimeZone(APP_TZ);
    return $tz;
}

function utc_tz(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) $tz = new DateTimeZone('UTC');
    return $tz;
}

/** Current UTC time as 'Y-m-d H:i:s' for storing. */
function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Today's date in the app timezone, 'Y-m-d'. */
function today_local_date(): string
{
    return (new DateTime('now', app_tz()))->format('Y-m-d');
}

/** Day of week (1=Mon .. 7=Sun) in the app timezone. */
function today_local_dow(): int
{
    return (int)(new DateTime('now', app_tz()))->format('N');
}

/** Convert a stored UTC datetime string to a DateTime in the app timezone. */
function utc_to_local(?string $utc): ?DateTime
{
    if (!$utc) return null;
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $utc, utc_tz());
    if (!$dt) return null;
    $dt->setTimezone(app_tz());
    return $dt;
}

/**
 * Convert a local datetime typed by an admin ('Y-m-d H:i' or 'Y-m-dTH:i')
 * into a UTC 'Y-m-d H:i:s' string. Returns null if the input is empty/invalid.
 */
function local_to_utc(string $localStr): ?string
{
    $localStr = trim(str_replace('T', ' ', $localStr));
    if ($localStr === '') return null;
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $f) {
        $dt = DateTime::createFromFormat($f, $localStr, app_tz());
        if ($dt && $dt->format($f) === $localStr) {
            $dt->setTimezone(utc_tz());
            return $dt->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/** Format a stored UTC time for display in the app timezone. */
function fmt_dt_local(?string $utc, string $fmt = 'D j M, H:i'): string
{
    $dt = utc_to_local($utc);
    return $dt ? $dt->format($fmt) : '—';
}
function fmt_time_local(?string $utc): string { return fmt_dt_local($utc, 'H:i'); }
function fmt_date_local(?string $utc): string { return fmt_dt_local($utc, 'D j M Y'); }

/** Value for an <input type="datetime-local"> from a stored UTC time. */
function local_input_value(?string $utc): string
{
    $dt = utc_to_local($utc);
    return $dt ? $dt->format('Y-m-d\TH:i') : '';
}

/** Seconds between two UTC datetimes; an open shift counts up to now. */
function duration_seconds(string $inUtc, ?string $outUtc): int
{
    $in  = strtotime($inUtc . ' UTC');
    $out = $outUtc ? strtotime($outUtc . ' UTC') : time();
    $d = $out - $in;
    return $d > 0 ? $d : 0;
}

function fmt_duration(int $secs): string
{
    $h = intdiv($secs, 3600);
    $m = intdiv($secs % 3600, 60);
    return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'm';
}

function hours_decimal(int $secs): float
{
    return round($secs / 3600, 2);
}

function money(float $n): string
{
    return CURRENCY . number_format($n, 2);
}

/** UTC [start,end) range covering one local calendar day ('Y-m-d'). */
function local_day_range(string $localDate): array
{
    $start = DateTime::createFromFormat('Y-m-d H:i:s', $localDate . ' 00:00:00', app_tz());
    if (!$start) $start = new DateTime('today', app_tz());
    $end = clone $start;
    $end->modify('+1 day');
    $start->setTimezone(utc_tz());
    $end->setTimezone(utc_tz());
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

/* ---------------------------------------------------------- admin layout */

/**
 * Cache-buster for our own CSS/JS: appends the file's last-modified time.
 * Without this, browsers keep serving an old stylesheet after an update.
 */
function asset_v(string $file): string
{
    $m = @filemtime(__DIR__ . '/assets/' . $file);
    return $m ? '?v=' . $m : '';
}

/** Up-to-two-letter initials for an avatar (e.g. "Sam Rai" -> "SR", "alex" -> "AL"). */
function name_initials(string $s): string
{
    $s = trim($s);
    if ($s === '') return '?';
    $parts = preg_split('/\s+/', $s);
    if (count($parts) >= 2) {
        return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(mb_substr($s, 0, 2));
}

function admin_header(string $title, string $active = ''): void
{
    $nav = [
        'index.php'      => 'Dashboard',
        'timesheets.php' => 'Timesheets',
        'import.php'     => 'Import',
        'staff.php'      => 'Staff',
        'schedules.php'  => 'Schedules',
        'lists.php'      => 'Brands & roles',
        'kiosks.php'     => 'Kiosks',
        'reports.php'    => 'Reports',
        'admins.php'     => 'Admins',
        'update.php'     => 'Update',
    ];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . e(APP_NAME) . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="../assets/style.css' . asset_v('style.css') . '"></head>';
    echo '<body class="admin">';
    echo '<header class="topbar">';
    echo '<a class="brand" href="index.php">'
       . '<img class="brand-logo" src="../assets/logo-primary.svg' . asset_v('logo-primary.svg') . '" alt="' . e(APP_NAME) . '">'
       . '</a>';
    echo '<nav class="mainnav">';
    foreach ($nav as $file => $label) {
        $cls = ($file === $active) ? ' class="on"' : '';
        echo '<a href="' . e($file) . '"' . $cls . '>' . e($label) . '</a>';
    }
    echo '</nav><div class="topbar-right">';
    echo '<a class="kiosk-link" href="../kiosk/index.php" target="_blank" rel="noopener">Open kiosk</a>';
    $who = current_admin_name();
    echo '<span class="whochip"><span class="avatar-sm">' . e(name_initials($who)) . '</span><span class="who">' . e($who) . '</span></span>';
    echo '<a class="ghost" href="logout.php">Log out</a>';
    echo '</div></header><main class="wrap">';
    echo '<h1 class="page-title">' . e($title) . '</h1>';
}

function admin_footer(): void
{
    echo '</main></body></html>';
}

/** Small helper for a one-line flash notice after redirects. */
function flash_set(string $msg, string $kind = 'ok'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];
}
function flash_render(): void
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="flash ' . e($f['kind']) . '">' . e($f['msg']) . '</div>';
    }
}

/* ------------------------------------------------------------- kiosk gate */

/** A short, readable code (no 0/O/1/I/L to avoid confusion on a tablet). */
function gen_kiosk_code(int $len = 8): string
{
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max = strlen($alpha) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alpha[random_int(0, $max)];
    return $out;
}

/** Pairing is required if a legacy key is set, or any active kiosk code exists. */
function kiosk_gating_on(): bool
{
    if (defined('KIOSK_KEY') && KIOSK_KEY !== '') return true;
    try {
        return (int) db()->query('SELECT COUNT(*) FROM kiosk_codes WHERE is_active = 1')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false; // kiosk_codes table not present yet
    }
}

/** Resolve a code string to a pairing array, or null if it isn't valid. */
function kiosk_resolve(string $code): ?array
{
    $code = trim($code);
    if ($code === '') return null;
    if (defined('KIOSK_KEY') && KIOSK_KEY !== '' && hash_equals(KIOSK_KEY, $code)) {
        return ['id' => 0, 'code' => $code, 'label' => 'Kiosk', 'brand_id' => null, 'brand' => null];
    }
    try {
        $stmt = db()->prepare(
            'SELECT k.id, k.code, k.label, k.brand_id, b.name AS brand
             FROM kiosk_codes k LEFT JOIN brands b ON b.id = k.brand_id
             WHERE k.code = ? AND k.is_active = 1 LIMIT 1'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) return null;
    return [
        'id'       => (int) $row['id'],
        'code'     => $row['code'],
        'label'    => $row['label'],
        'brand_id' => $row['brand_id'] !== null ? (int) $row['brand_id'] : null,
        'brand'    => $row['brand'],
    ];
}

/** The pairing for the current device (from its cookie), or null. */
function kiosk_pairing(): ?array
{
    $val = $_COOKIE['kclock_kiosk'] ?? '';
    return $val !== '' ? kiosk_resolve($val) : null;
}

function kiosk_set_cookie(string $code): void
{
    setcookie('kclock_kiosk', $code, [
        'expires'  => time() + 60 * 60 * 24 * 365,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function kiosk_clear_cookie(): void
{
    setcookie('kclock_kiosk', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Apply schema.sql idempotently: create any missing tables and seed the
 * starter brands/roles only if those tables are empty. Returns statements run.
 */
function apply_schema_file(PDO $pdo, string $file): int
{
    $sql = file_get_contents($file);
    if ($sql === false) throw new RuntimeException('Could not read schema.sql.');
    $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
    $sql = preg_replace('/--[^\r\n]*/', '', $sql);
    $sql = preg_replace('/#[^\r\n]*/', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)), function ($s) { return $s !== ''; });
    $count = 0;
    foreach ($statements as $st) {
        if (preg_match('/^INSERT\s+INTO\s+brands\b/i', $st)) {
            if ((int) $pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn() > 0) continue;
        } elseif (preg_match('/^INSERT\s+INTO\s+roles\b/i', $st)) {
            if ((int) $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn() > 0) continue;
        }
        $pdo->exec($st);
        $count++;
    }
    return $count;
}
