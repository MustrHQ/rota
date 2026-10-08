# Changelog

All notable changes to MustrHQ Rota. Versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

## 1.1.0
### Added
- **Exceptions** page: every timecard problem for a period in one list — missed clock-outs,
  unexcused absences and late arrivals — each with a one-click fix.
- **Schedule planner**: a week timeline of the rota against actual worked time, with no-shows,
  a live "now" line, per-day coverage and planned-vs-worked hours. Filter by location.
- **Look up and reset PINs** from Staff. PINs are kept hashed for the kiosk, plus a copy encrypted
  with `APP_KEY` so admins can read them back. Reset sets a new random PIN and clears any lockout.

### Changed
- The dashboard is now **Home**: a welcome banner with a live clock and tiles for timecards that
  need fixing, who is on shift, today's attendance and labour cost, and quick links.
- Refreshed MustrHQ logo files and app icons.
- README screenshots of every main screen, using made-up demo data.

### Upgrading
- Run **Database update** (`admin/upgrade.php`) once to add the new PIN column.
- Add `define('APP_KEY', 'a-long-random-string');` to your `config.php`. File updates never
  touch `config.php`, so existing installs don't get it automatically.

## 1.0.0
First public release, under the GNU AGPL-3.0.
- Wall-tablet kiosk: tap name, enter a 4-digit PIN, clock in or out. Installable as an app (PWA).
- Kiosk pairing codes, optionally tied to a brand so each station shows only its own team.
- Dashboard: who is on shift, hours today, and on-time / late / no-show against schedules.
- Timesheets: filter, edit, add missed punches for any date, delete.
- CSV import of past timecards, with preview and duplicate detection.
- Staff with PINs, hourly rates, brands, roles and photos; deactivate rather than delete.
- Recurring schedules, whole-team rota grid, and rota export as CSV, PDF or JPG.
- Reports: hours, wages and labour cost per brand, with CSV export.
- Web installer, in-admin updater with rollback backups, and a re-runnable database upgrade.
- UTC storage, so shifts across midnight and clock changes are counted correctly.
