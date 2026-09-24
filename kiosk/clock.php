<?php
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');

function jout(array $a): void
{
    echo json_encode($a);
    exit;
}

// Kiosk gate: if pairing is required, this device must be paired.
$pair = kiosk_pairing();
if (kiosk_gating_on() && !$pair) {
    http_response_code(403);
    jout(['ok' => false, 'error' => 'This device is not set up as a kiosk.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jout(['ok' => false, 'error' => 'Method not allowed.']);
}

csrf_check();

$staffId = (int) ($_POST['staff_id'] ?? 0);
$pin     = (string) ($_POST['pin'] ?? '');
$brandId = isset($_POST['brand_id']) && $_POST['brand_id'] !== '' ? (int) $_POST['brand_id'] : null;

if ($staffId <= 0 || $pin === '') {
    jout(['ok' => false, 'error' => 'Missing details. Try again.']);
}

$stmt = db()->prepare('SELECT * FROM staff WHERE id = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$staffId]);
$staff = $stmt->fetch();
if (!$staff) {
    jout(['ok' => false, 'error' => 'That person is not available.']);
}

// Lockout check.
if (!empty($staff['locked_until'])) {
    $until = strtotime($staff['locked_until'] . ' UTC');
    if ($until > time()) {
        jout(['ok' => false, 'error' => 'Too many wrong PINs. Wait a moment.', 'locked' => $until - time()]);
    }
}

// Verify PIN.
if (!password_verify($pin, $staff['pin_hash'])) {
    $fa = (int) $staff['failed_attempts'] + 1;
    if ($fa >= PIN_MAX_ATTEMPTS) {
        $lockUntil = gmdate('Y-m-d H:i:s', time() + PIN_LOCK_SECONDS);
        db()->prepare('UPDATE staff SET failed_attempts = 0, locked_until = ? WHERE id = ?')
            ->execute([$lockUntil, $staffId]);
        jout(['ok' => false, 'error' => 'Locked for ' . PIN_LOCK_SECONDS . ' seconds.', 'locked' => PIN_LOCK_SECONDS]);
    }
    db()->prepare('UPDATE staff SET failed_attempts = ? WHERE id = ?')->execute([$fa, $staffId]);
    $left = PIN_MAX_ATTEMPTS - $fa;
    jout(['ok' => false, 'error' => 'Wrong PIN. ' . $left . ' ' . ($left === 1 ? 'try' : 'tries') . ' left.']);
}

// Correct PIN — clear any failure state.
if ((int) $staff['failed_attempts'] !== 0 || $staff['locked_until'] !== null) {
    db()->prepare('UPDATE staff SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$staffId]);
}

$now = now_utc();

// mark this kiosk as active
if ($pair && ($pair['id'] ?? 0) > 0) {
    db()->prepare('UPDATE kiosk_codes SET last_used_at = ? WHERE id = ?')->execute([$now, $pair['id']]);
}

// Is there an open shift? If so, this tap ends it.
$open = db()->prepare('SELECT id, clock_in FROM time_entries WHERE staff_id = ? AND clock_out IS NULL ORDER BY clock_in DESC LIMIT 1');
$open->execute([$staffId]);
$openRow = $open->fetch();

if ($openRow) {
    // Cooldown: don't let an accidental double-tap instantly close a just-started shift.
    if (duration_seconds($openRow['clock_in'], $now) < CLOCK_COOLDOWN_SECONDS) {
        jout(['ok' => false, 'error' => 'You clocked in a moment ago. Please wait a few seconds before clocking out.']);
    }
    db()->prepare('UPDATE time_entries SET clock_out = ?, updated_at = ? WHERE id = ?')
        ->execute([$now, $now, $openRow['id']]);
    $secs = duration_seconds($openRow['clock_in'], $now);
    jout([
        'ok'      => true,
        'state'   => 'out',
        'name'    => $staff['name'],
        'time'    => fmt_time_local($now),
        'worked'  => fmt_duration($secs),
    ]);
}

// Cooldown: avoid an accidental instant re-clock-in right after clocking out.
$lastOut = db()->prepare('SELECT clock_out FROM time_entries WHERE staff_id = ? AND clock_out IS NOT NULL ORDER BY clock_out DESC LIMIT 1');
$lastOut->execute([$staffId]);
$lo = $lastOut->fetchColumn();
if ($lo !== false && $lo !== null && duration_seconds($lo, $now) < CLOCK_COOLDOWN_SECONDS) {
    jout(['ok' => false, 'error' => 'You clocked out a moment ago. Please wait a few seconds before clocking in again.']);
}

// Otherwise this tap starts a shift.
// On a brand-bound kiosk, only staff assigned to that brand may start a shift here.
if ($pair && $pair['brand_id'] !== null) {
    $chk = db()->prepare('SELECT 1 FROM staff_brands WHERE staff_id = ? AND brand_id = ? LIMIT 1');
    $chk->execute([$staffId, $pair['brand_id']]);
    if (!$chk->fetchColumn()) {
        jout(['ok' => false, 'error' => 'You are not set up to clock in at this kitchen.']);
    }
}

// Work out the brand.
$brandRows = db()->prepare(
    'SELECT b.id, b.name FROM staff_brands sb
     JOIN brands b ON b.id = sb.brand_id AND b.is_active = 1
     WHERE sb.staff_id = ? ORDER BY b.name'
);
$brandRows->execute([$staffId]);
$brands = $brandRows->fetchAll();

$useBrand = null;
$forcedBrandName = null;
if ($pair && $pair['brand_id'] !== null) {
    // This kiosk is tied to a brand: tag every clock-in with it.
    $useBrand = $pair['brand_id'];
    $forcedBrandName = $pair['brand'];
} elseif (count($brands) === 1) {
    $useBrand = (int) $brands[0]['id'];
} elseif (count($brands) > 1) {
    $valid = array_column($brands, 'name', 'id');
    if ($brandId !== null && isset($valid[$brandId])) {
        $useBrand = $brandId;
    } else {
        // Ask which brand this shift is for.
        jout(['ok' => true, 'need_brand' => true, 'name' => $staff['name'], 'brands' => $brands]);
    }
}

$ins = db()->prepare('INSERT INTO time_entries (staff_id, brand_id, clock_in, created_at) VALUES (?, ?, ?, ?)');
$ins->execute([$staffId, $useBrand, $now, $now]);

$brandName = '';
if ($forcedBrandName !== null) {
    $brandName = $forcedBrandName;
} elseif ($useBrand !== null) {
    foreach ($brands as $b) {
        if ((int) $b['id'] === $useBrand) { $brandName = $b['name']; break; }
    }
}

jout([
    'ok'    => true,
    'state' => 'in',
    'name'  => $staff['name'],
    'time'  => fmt_time_local($now),
    'brand' => $brandName,
    'since' => strtotime($now . ' UTC'),
]);
