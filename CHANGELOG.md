# Changelog

All notable changes to MustrHQ Rota. Versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

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
