<?php
require_once __DIR__ . '/../helpers.php';

// --- Device pairing (kiosk setup) ---
$pair = kiosk_pairing();

// Pair or re-pair this device via ?code=XXXX (or the legacy ?key=).
if (isset($_GET['code']) || isset($_GET['key'])) {
    $resolved = kiosk_resolve((string) ($_GET['code'] ?? $_GET['key'] ?? ''));
    if ($resolved) {
        kiosk_set_cookie($resolved['code']);
        if ($resolved['id'] > 0) {
            db()->prepare('UPDATE kiosk_codes SET last_used_at = ? WHERE id = ?')->execute([now_utc(), $resolved['id']]);
        }
        header('Location: index.php');
        exit;
    }
    $setupError = 'That code was not recognised.';
    $pair = null;
}

// Unpair this device.
if (isset($_GET['unpair'])) {
    kiosk_clear_cookie();
    header('Location: index.php');
    exit;
}

// If pairing is required and this device isn't paired, show the setup screen.
if (kiosk_gating_on() && !$pair) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kiosk_code'])) {
        csrf_check();
        $resolved = kiosk_resolve((string) $_POST['kiosk_code']);
        if ($resolved) {
            kiosk_set_cookie($resolved['code']);
            if ($resolved['id'] > 0) {
                db()->prepare('UPDATE kiosk_codes SET last_used_at = ? WHERE id = ?')->execute([now_utc(), $resolved['id']]);
            }
            header('Location: index.php');
            exit;
        }
        $setupError = 'That code was not recognised.';
    }
    $setupError = $setupError ?? '';
    ?>
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Set up · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css<?= asset_v('style.css') ?>">
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="#01216C">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
    <link rel="apple-touch-icon" href="icons/icon-180.png">
    </head>
    <body class="kiosk">
    <div style="min-height:100vh;display:grid;place-items:center;padding:24px">
        <form method="post" class="sheet" style="width:min(430px,100%)">
            <img class="k-logo" style="margin:0 auto 14px" src="../assets/logo-light.svg<?= asset_v('logo-light.svg') ?>" alt="<?= e(APP_NAME) ?>">
            <div class="sheet-name">Set up this device</div>
            <div class="sheet-sub">Enter the kiosk code from your admin panel</div>
            <?php if ($setupError): ?><div class="pin-msg" style="min-height:auto;margin:0 0 12px"><?= e($setupError) ?></div><?php endif; ?>
            <?= csrf_field() ?>
            <input type="text" name="kiosk_code" autofocus autocomplete="off" autocapitalize="characters" spellcheck="false"
                   placeholder="e.g. K7P2QX9M"
                   style="width:100%;text-align:center;text-transform:uppercase;letter-spacing:.18em;font-family:var(--font-mono);font-size:22px;padding:14px;border-radius:12px;border:1px solid var(--k-line);background:var(--k-surface2);color:var(--k-ink)">
            <button class="brand-btn" type="submit" style="margin-top:14px;width:100%">Set up kiosk</button>
        </form>
    </div>
    <script>if('serviceWorker' in navigator){navigator.serviceWorker.register('sw.js').catch(function(){});}</script>
    </body>
    </html>
    <?php
    exit;
}

// Active staff — for a brand-bound kiosk, only people assigned to that brand.
if ($pair && $pair['brand_id'] !== null) {
    $stmt = db()->prepare(
        'SELECT s.id, s.name, s.photo FROM staff s
         JOIN staff_brands sb ON sb.staff_id = s.id AND sb.brand_id = ?
         WHERE s.is_active = 1 ORDER BY s.name'
    );
    $stmt->execute([$pair['brand_id']]);
    $staff = $stmt->fetchAll();
} else {
    $staff = db()->query('SELECT id, name, photo FROM staff WHERE is_active = 1 ORDER BY name')->fetchAll();
}

// Open shifts -> map staff_id => clock_in (UTC).
$openMap = [];
foreach (db()->query('SELECT staff_id, clock_in FROM time_entries WHERE clock_out IS NULL')->fetchAll() as $r) {
    $openMap[(int) $r['staff_id']] = $r['clock_in'];
}

// Brands per staff -> map staff_id => [{id,name}].
$brandMap = [];
$brandsQ = db()->query(
    'SELECT sb.staff_id, b.id, b.name FROM staff_brands sb
     JOIN brands b ON b.id = sb.brand_id AND b.is_active = 1 ORDER BY b.name'
)->fetchAll();
foreach ($brandsQ as $r) {
    $brandMap[(int) $r['staff_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $a = $parts[0][0] ?? '';
    $b = isset($parts[1]) ? ($parts[1][0] ?? '') : '';
    return strtoupper($a . $b);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/style.css<?= asset_v('style.css') ?>">
<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="#01216C">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<link rel="apple-touch-icon" href="icons/icon-180.png">
</head>
<body class="kiosk">
<?php
// Show whoever is on shift first — the wall should answer "who's working?" instantly.
usort($staff, function ($a, $b) use ($openMap) {
    $ao = isset($openMap[(int) $a['id']]) ? 0 : 1;
    $bo = isset($openMap[(int) $b['id']]) ? 0 : 1;
    return $ao === $bo ? strcasecmp($a['name'], $b['name']) : $ao - $bo;
});
$onCount = 0;
foreach ($staff as $s) { if (isset($openMap[(int) $s['id']])) $onCount++; }
$kTz = new DateTime('now', app_tz());
?>
<header class="k-top">
    <div class="k-ident">
        <img class="k-logo" src="../assets/logo-light.svg<?= asset_v('logo-light.svg') ?>" alt="<?= e(APP_NAME) ?>">
        <?php if (!empty($pair['brand'])): ?><div class="k-place"><?= e($pair['brand']) ?></div><?php endif; ?>
    </div>
    <div class="k-now">
        <div class="k-clock" id="clock">--:--:--</div>
        <div class="k-meta">
            <span class="k-date"><?= e($kTz->format('D j M')) ?></span>
            <span class="k-onnow<?= $onCount ? ' live' : '' ?>">
                <span class="dot"></span><span id="oncount"><?= $onCount ?></span> on shift
            </span>
        </div>
    </div>
</header>

<main class="k-main">
<p class="k-hint">Tap your name to start or end your shift</p>

<?php if (!$staff): ?>
    <div class="k-empty">
        <?php if ($pair && $pair['brand_id'] !== null): ?>
            <h2>No staff assigned to <?= e($pair['brand'] ?? 'this kitchen') ?> yet</h2>
            <p>In the admin area, add this brand to the people who work here.</p>
        <?php else: ?>
            <h2>No staff added yet</h2>
            <p>Add people in the admin area, then they'll show up here.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="k-grid">
    <?php foreach ($staff as $s):
        $id = (int) $s['id'];
        $on = isset($openMap[$id]);
        $since = $on ? strtotime($openMap[$id] . ' UTC') : '';
        $bl = $brandMap[$id] ?? [];
    ?>
    <button class="tile <?= $on ? 'on' : 'off' ?>"
            data-id="<?= $id ?>"
            data-name="<?= e($s['name']) ?>"
            data-status="<?= $on ? 'on' : 'off' ?>"
            data-since="<?= e((string) $since) ?>"
            data-brands='<?= e(json_encode($bl)) ?>'>
        <span class="tile-face">
            <?php if (!empty($s['photo'])): ?>
                <img src="../uploads/<?= e($s['photo']) ?>" alt="">
            <?php else: ?>
                <span class="tile-initials"><?= e(initials($s['name'])) ?></span>
            <?php endif; ?>
        </span>
        <span class="tile-name"><?= e($s['name']) ?></span>
        <span class="tile-state">
            <span class="dot"></span>
            <span class="tile-state-text"><?= $on ? 'On shift' : 'Off' ?></span>
            <span class="tile-elapsed" data-elapsed></span>
        </span>
    </button>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</main>

<!-- PIN overlay -->
<div class="overlay" id="pin-overlay" hidden>
    <div class="sheet">
        <button class="sheet-close" data-cancel aria-label="Cancel">✕</button>
        <div class="sheet-name" id="pin-name"></div>
        <div class="sheet-sub">Enter your 4-digit PIN</div>
        <div class="dots" id="pin-dots"><span></span><span></span><span></span><span></span></div>
        <div class="pin-msg" id="pin-msg"></div>
        <div class="keypad">
            <button data-key="1">1</button><button data-key="2">2</button><button data-key="3">3</button>
            <button data-key="4">4</button><button data-key="5">5</button><button data-key="6">6</button>
            <button data-key="7">7</button><button data-key="8">8</button><button data-key="9">9</button>
            <button class="key-blank" disabled></button>
            <button data-key="0">0</button>
            <button class="key-back" data-back aria-label="Delete">⌫</button>
        </div>
    </div>
</div>

<!-- Brand overlay -->
<div class="overlay" id="brand-overlay" hidden>
    <div class="sheet">
        <button class="sheet-close" data-cancel aria-label="Cancel">✕</button>
        <div class="sheet-name" id="brand-name"></div>
        <div class="sheet-sub">Which brand are you working for?</div>
        <div class="brand-choices" id="brand-choices"></div>
    </div>
</div>

<!-- Result overlay -->
<div class="overlay result" id="result-overlay" hidden>
    <div class="result-inner">
        <div class="result-mark" id="result-mark"></div>
        <div class="result-big" id="result-big"></div>
        <div class="result-sub" id="result-sub"></div>
    </div>
</div>

<?php if ($pair): ?>
<a class="k-devlink" href="index.php?unpair=1" onclick="return confirm('Unpair this device from the kiosk? You will need the code to set it up again.')">Device settings</a>
<?php endif; ?>

<script>
window.KIOSK = {
    now: <?= time() ?>,          // server time (UTC epoch, seconds)
    tz: <?= json_encode(APP_TZ) ?>,
    csrf: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="../assets/kiosk.js<?= asset_v('kiosk.js') ?>"></script>
<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('sw.js').catch(function(){});}</script>
</body>
</html>
