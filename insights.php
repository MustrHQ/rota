<?php
/**
 * MustrHQ — timecard insights.
 * One place that works out exceptions (missed out-punches, no-shows, late-ins)
 * and clean timecards for a date range, so the home tiles, the Exceptions page
 * and the Schedule planner always agree.
 *
 * Requires helpers.php to be loaded first.
 */

/** Period keys used across the admin: [from, to, label] as local Y-m-d. */
function period_bounds(string $key): array
{
    $tz = app_tz();
    $today = new DateTime('today', $tz);
    switch ($key) {
        case 'today':
            return [$today->format('Y-m-d'), $today->format('Y-m-d'), 'Today'];
        case 'lastweek':
            $mon = (clone $today)->modify('monday this week')->modify('-7 days');
            return [$mon->format('Y-m-d'), (clone $mon)->modify('+6 days')->format('Y-m-d'), 'Last week'];
        case 'month':
            return [(clone $today)->modify('first day of this month')->format('Y-m-d'), $today->format('Y-m-d'), 'This month'];
        case 'week':
        default:
            $mon = (clone $today)->modify('monday this week');
            return [$mon->format('Y-m-d'), (clone $mon)->modify('+6 days')->format('Y-m-d'), 'This week'];
    }
}

function period_keys(): array
{
    return ['today' => 'Today', 'week' => 'This week', 'lastweek' => 'Last week', 'month' => 'This month'];
}

/** Epoch of a local date + local time ("09:00:00"). */
function local_epoch(string $date, string $time): int
{
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . (strlen($time) === 5 ? $time . ':00' : $time), app_tz());
    return $dt ? (int) $dt->format('U') : 0;
}

/** Active staff keyed by id, with their weekly schedule (dow => [start, end|null]). */
function staff_with_schedules(?int $brandId = null): array
{
    $sql = 'SELECT s.id, s.name, s.photo, s.hourly_rate FROM staff s WHERE s.is_active = 1';
    $args = [];
    if ($brandId) {
        $sql .= ' AND EXISTS (SELECT 1 FROM staff_brands sb WHERE sb.staff_id = s.id AND sb.brand_id = ?)';
        $args[] = $brandId;
    }
    $q = db()->prepare($sql . ' ORDER BY s.name');
    $q->execute($args);
    $staff = [];
    foreach ($q->fetchAll() as $r) {
        $r['sched'] = [];
        $staff[(int) $r['id']] = $r;
    }
    if (!$staff) return [];
    foreach (db()->query('SELECT staff_id, dow, expected_start, expected_end FROM schedules')->fetchAll() as $r) {
        $sid = (int) $r['staff_id'];
        if (isset($staff[$sid])) $staff[$sid]['sched'][(int) $r['dow']] = [$r['expected_start'], $r['expected_end']];
    }
    return $staff;
}

/** Entries whose clock-in falls in [from 00:00, to+1 00:00) local, plus every still-open entry. */
function entries_between(string $from, string $to): array
{
    [$a] = local_day_range($from);
    [, $b] = local_day_range($to);
    $q = db()->prepare(
        'SELECT te.id, te.staff_id, te.brand_id, te.clock_in, te.clock_out, b.name AS brand
         FROM time_entries te LEFT JOIN brands b ON b.id = te.brand_id
         WHERE (te.clock_in >= ? AND te.clock_in < ?) OR te.clock_out IS NULL
         ORDER BY te.clock_in'
    );
    $q->execute([$a, $b]);
    return $q->fetchAll();
}

/**
 * The core: exceptions + clean count for a period.
 * Returns ['missed_out'=>[], 'no_show'=>[], 'late'=>[], 'must_fix'=>int,
 *          'clean'=>int, 'worked'=>int, 'from'=>, 'to'=>]
 */
function timecard_exceptions(string $from, string $to): array
{
    $tz = app_tz();
    $now = time();
    $today = (new DateTime('today', $tz))->format('Y-m-d');
    $staff = staff_with_schedules();
    $entries = entries_between($from, $to);

    // first clock-in per staff per local date (within the period)
    $first = [];
    $completedInRange = [];
    foreach ($entries as $e) {
        $local = utc_to_local($e['clock_in']);
        if (!$local) continue;
        $d = $local->format('Y-m-d');
        if ($d < $from || $d > $to) continue;
        $sid = (int) $e['staff_id'];
        if (!isset($first[$sid][$d])) $first[$sid][$d] = $e;
        if ($e['clock_out'] !== null) $completedInRange[(int) $e['id']] = true;
    }

    $missed = []; $noShow = []; $late = []; $lateIds = [];

    // missed out-punches: any shift open longer than the alert threshold
    foreach ($entries as $e) {
        if ($e['clock_out'] !== null) continue;
        $age = duration_seconds($e['clock_in'], null);
        if ($age >= OPEN_SHIFT_ALERT_HOURS * 3600 && isset($staff[(int) $e['staff_id']])) {
            $missed[] = ['entry' => $e, 'staff' => $staff[(int) $e['staff_id']], 'age' => $age];
        }
    }

    // scheduled days: late or no-show
    $cur = DateTime::createFromFormat('Y-m-d', $from, $tz);
    $last = min($to, $today);
    while ($cur && $cur->format('Y-m-d') <= $last) {
        $d = $cur->format('Y-m-d');
        $dow = (int) $cur->format('N');
        foreach ($staff as $sid => $s) {
            if (!isset($s['sched'][$dow])) continue;
            [$st, $en] = $s['sched'][$dow];
            $expStart = local_epoch($d, $st);
            $expEnd = $en ? local_epoch($d, $en) : null;
            if ($expEnd !== null && $expEnd <= $expStart) $expEnd += 86400;
            if (isset($first[$sid][$d])) {
                $in = strtotime($first[$sid][$d]['clock_in'] . ' UTC');
                if ($in > $expStart + GRACE_MINUTES * 60) {
                    $late[] = ['staff' => $s, 'date' => $d, 'expected' => substr($st, 0, 5),
                               'mins' => (int) round(($in - $expStart) / 60), 'entry' => $first[$sid][$d]];
                    $lateIds[(int) $first[$sid][$d]['id']] = true;
                }
            } else {
                $cutoff = $expStart + NOSHOW_AFTER_MINUTES * 60;
                if ($now > $cutoff || ($expEnd !== null && $now > $expEnd)) {
                    $noShow[] = ['staff' => $s, 'date' => $d, 'expected' => substr($st, 0, 5),
                                 'expected_end' => $en ? substr($en, 0, 5) : null];
                }
            }
        }
        $cur->modify('+1 day');
    }

    $worked = count($completedInRange);
    $clean = 0;
    foreach (array_keys($completedInRange) as $id) if (!isset($lateIds[$id])) $clean++;

    usort($late, fn($a, $b) => strcmp($b['date'], $a['date']));
    usort($noShow, fn($a, $b) => strcmp($b['date'], $a['date']));

    return [
        'missed_out' => $missed, 'no_show' => $noShow, 'late' => $late,
        'must_fix' => count($missed) + count($noShow) + count($late),
        'clean' => $clean, 'worked' => $worked, 'from' => $from, 'to' => $to,
    ];
}

/** 23 -> "23m", 375 -> "6h 15m". */
function fmt_mins(int $m): string
{
    if ($m < 60) return $m . 'm';
    return intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . 'm' : '');
}

/** Compact rota label for a narrow bar: "10:00"+"18:00" -> "10–18", "16:00"+"23:30" -> "16–23:30". */
function short_span(string $a, ?string $b): string
{
    $t = fn($x) => substr($x, 3, 2) === '00' ? (string) (int) substr($x, 0, 2) : substr($x, 0, 5);
    $lbl = $t($a) . ($b ? '–' . $t($b) : '');
    return mb_strlen($lbl) > 5 ? substr($a, 0, 5) : $lbl;   // too long for a bar: start time only
}

/** Hour-of-day as a percentage of 24h, for positioning bars. */
function day_pct(int $epoch, int $dayStart): float
{
    return max(0.0, min(100.0, ($epoch - $dayStart) / 864));
}

/** A small round avatar (photo or initials). */
function staff_avatar(array $s, string $cls = 'avatar'): string
{
    if (!empty($s['photo'])) {
        return '<img class="' . e($cls) . '" src="../uploads/' . e($s['photo']) . '" alt="">';
    }
    return '<span class="' . e($cls) . '">' . e(name_initials($s['name'])) . '</span>';
}
