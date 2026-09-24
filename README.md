# MustrHQ Rota

[![Latest release](https://img.shields.io/github/v/release/MustrHQ/rota?label=release)](https://github.com/MustrHQ/rota/releases/latest)
[![Licence: AGPL-3.0](https://img.shields.io/badge/licence-AGPL--3.0-blue.svg)](LICENSE)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)
![MySQL](https://img.shields.io/badge/MySQL-MariaDB-4479a1.svg)

Open-source staff time clock, rota and attendance — plain PHP + MySQL, no build step, runs on shared cPanel hosting. A wall-tablet kiosk for clock in/out, a polished admin panel, and installable as an app.

A staff clock-in / clock-out system for delivery-only ("dark") kitchens. A wall-mounted tablet acts as a kiosk where staff tap their name and a 4-digit PIN to start or end a shift. A separate admin area tracks hours, wages, brands, roles, and late/no-show attendance.

Plain PHP + MySQL. No framework, no Composer, no build step — upload the files, run the installer, done.

Part of the [MustrHQ](https://mustrhq.app) open-source set, alongside [MustrHQ Stock](https://github.com/MustrHQ/stock).

**Quick start:** download the latest zip from [Releases](https://github.com/MustrHQ/rota/releases/latest), upload it to your hosting, open `install.php` and follow the steps. Full details under [Setup](#setup-cpanel).

---

## What's built

- **Kiosk** (`kiosk/`) — tap name → PIN → clock in/out. Live wall clock and a per-person elapsed timer. A tablet is paired to the kiosk with a **code** (see below). If the code is tied to a brand, that kiosk shows only the staff assigned to that brand and tags their clock-ins with it automatically; a general code shows everyone and asks people who work more than one brand. Failed-PIN lockout.
- **Dashboard** — who's on shift right now (with live elapsed), hours logged today, and today's attendance vs. each person's schedule (on time / late / no-show).
- **Timesheets** — filter by staff and date range, edit any entry, add missed punches by hand (any date, including past days), delete.
- **Import** — bulk-upload past clock in/out records from a CSV, with a preview and validation step before anything is written. Good for moving over from paper or a spreadsheet.
- **Staff** — add/edit people, set 4-digit PIN, hourly rate, brands, roles, and an optional photo. People are deactivated (not deleted) so past hours stay intact.
- **Brands & roles** — add, rename, activate/deactivate, delete.
- **Schedules** — a lightweight recurring "expected start" per person per weekday (drives the late/no-show flags), a whole-team rota grid, and export of the rota as **CSV, PDF, or JPG**.
- **Kiosks** — create and manage pairing codes for your tablets; optionally tie each to a brand.
- **Admins** — add or remove admin accounts and change passwords (every admin has equal access).
- **Reports** — hours and wage totals per staff and labour cost per brand for any date range, with CSV export.

---

## Setup (cPanel)

The easy way is the built-in web installer — no editing files, no phpMyAdmin import.

1. **Upload the files.** Put the contents of this folder somewhere web-accessible, e.g. `public_html/clock/`. (In cPanel File Manager you can upload the ZIP and Extract it.)

2. **Create a MySQL database + user** in cPanel (MySQL® Databases), and give the user access to the database. (The installer fills in the tables; it can't create the database itself on shared hosting.)

3. **Run the installer.** Visit `install.php` in your browser (e.g. `https://your-site/clock/install.php`). It checks your server, asks for the database details and your admin login, tests the connection, writes `config.php`, builds the tables, and creates your admin account. If it can't write `config.php` itself (folder not writable), it shows you the exact file to create by hand, then continues.

4. **Delete `install.php`** (and `admin/setup.php`) when it's done — the installer reminds you. It refuses to re-run once an admin exists, but removing it is cleaner and safer.

5. **Log in** at `admin/login.php` and add your staff, brands, and (optionally) schedules. **Open the kiosk** on the tablet at `kiosk/index.php`.

> Run the installer promptly after uploading, and delete it afterwards. On a fresh, unconfigured copy it is the one page that can set the site up, so don't leave it sitting on a public server.

### Prefer to do it by hand?

You can skip the installer: edit `config.php` with your database details (and `APP_TZ`, `CURRENCY`), import `schema.sql` in phpMyAdmin, make `uploads/` writable, then visit `admin/setup.php` to create the first admin and delete that file.

---

## Upgrading an existing install

When you drop a newer version over a site that's already set up:

1. **Keep your existing `config.php`.** The copy in the download is a blank placeholder — if you overwrite your real one, the app loses its database details. Replace every other file, but leave `config.php` in place. (The installer won't help here: it deliberately locks itself once an admin account exists.)
2. **Run the database update.** Log in and visit `admin/upgrade.php` once. It creates any new tables (such as kiosk codes) and leaves your existing tables and data untouched. It's safe to run more than once.

That's it — the new pages appear in the top navigation.

**Or update from the admin panel.** Once you're set up, **Update** in the top nav lets you upload a release zip and apply it in place — no FTP. It preserves your `config.php` and `uploads/`, saves a rollback backup to `backups/` first, and ignores any unsafe paths in the zip. After a file update, run the **database update** (linked from that page) if the new version needs it. Because it overwrites live files, keep admin passwords strong and take a database backup before big updates.

---

## Install the kiosk as a tablet app

The kiosk is a PWA, so a tablet (or phone) can install it as a real app — its own icon, launches fullscreen with no browser bar. No app store, no APK.

1. Serve the site over **HTTPS** (cPanel's free AutoSSL is fine). PWAs require it.
2. On the tablet, open the kiosk in **Chrome** and choose **Install app** (menu ⋮ → *Install app* / *Add to Home screen*). On an iPad, use Safari → *Share* → *Add to Home Screen*.
3. Launch it from the new icon — it opens fullscreen, ready for staff to tap and clock in.

To lock a wall tablet to just this app, use Android's **screen pinning** (Settings → Security → *App pinning*), so staff can't wander off to other apps.

---

## Setting up a kiosk (codes)

Each tablet is paired to the kiosk with a short code you create in the admin area under **Kiosks**.

1. In **Kiosks**, add a code. Give it a label (e.g. "Front station") and, if you want, tie it to a brand. Leave the brand as "All brands" for a general kiosk.
2. On the tablet, open `kiosk/index.php`. You'll see a setup screen — type the code once. That device is remembered from then on.
   (You can also open `kiosk/index.php?code=YOURCODE` to set it up in one step.)
3. If the code is tied to a brand, every clock-in on that tablet is tagged with that brand automatically — no brand prompt — and the tablet lists only the staff assigned to that brand, so each station shows just its own team. A general code shows everyone and asks staff who work more than one brand. (Assign brands to people under **Staff**.)

Creating your first active code turns kiosk pairing **on**: from then on, a device must be set up with a code before it can use the clock. Delete or deactivate all codes to make the kiosk open again. To move a tablet to a different code, open it with `?code=` again; to reset a tablet, use the small **Device settings** link at the bottom of the kiosk (or `?unpair=1`).

> **Legacy key.** If you were already using a single `KIOSK_KEY` in `config.php`, it still works exactly as before, alongside any codes. New setups don't need it.

---

## How time is handled

- Every punch is stored in **UTC** and only converted to your timezone (`APP_TZ`) for display.
- Duration is the difference between two timestamps, so **shifts that cross midnight** (e.g. 8pm–2am) are correct, and so are the two clocks-change nights each year — no lost or doubled hour.
- All admin times are entered and shown in `APP_TZ`.

## Late / no-show

These come from **Schedules**, not from fixed shifts you assign each day. Set the days and expected start times a person normally works. On a scheduled day:

- clock-in more than `GRACE_MINUTES` after the expected start → **Late**;
- no clock-in by `NOSHOW_AFTER_MINUTES` past the expected start (or past the expected end, if you set one) → **No-show**.

Anyone without a schedule for that day is never flagged. All thresholds live in `config.php`.

## Pay totals

Reports total only **completed** shifts (those with a clock-out), so an in-progress shift never inflates wages. Any open shifts in the range are noted separately. Wages = hours × the person's hourly rate.

---

## Filling in past days

Three ways to record history, depending on how much there is:

- **One entry** — Timesheets, *Add entry manually*. The date and time fields accept any date, so backdating a forgotten shift is just typing it in. You can also edit or delete any existing entry.
- **A lot of entries** — the **Import** page. Download the template, fill it in (`staff`, `clock_in`, `clock_out`, `brand`, `note`), upload it, and review the preview before committing. Rows already present are detected and skipped, so re-uploading the same file won't duplicate anything. Times are read in your configured timezone and stored as UTC, so overnight shifts and DST are handled.
- **Still-open shifts** — leave `clock_out` blank on import, or fix them from the dashboard's *Needs fixing* list.

One thing worth knowing: the **rota is a recurring weekly pattern**, not dated rows. Editing it changes the pattern from now on rather than rewriting history — the record of what people actually worked lives in timecards, which is what reports and pay totals use.

## Keep it locked down

- **Delete `install.php`** as soon as setup is done. The dashboard warns you while it's still there.
- **Don't leave release zips in the web root.** Anything like `mustr.zip` sitting next to `index.php` can be downloaded by anyone. Delete it after updating (the dashboard flags these too).
- **Keep `index.php` and `.htaccess` in the web root.** Together they stop the plain domain showing a file listing: `.htaccess` disables directory indexes and blocks `.sql`, `.zip`, `.md` and dotfiles, and `index.php` sends visitors to the sign-in page.
- **Serve the site over HTTPS** (cPanel AutoSSL is free). PINs and admin passwords travel over the wire.
- `uploads/` can't execute scripts and `backups/` can't be fetched at all, both via their own `.htaccess`.

## Good to know

- **Forgot to clock out?** The **dashboard flags it** under *Needs attention* once a shift has been open past `OPEN_SHIFT_ALERT_HOURS` (default 16) — most likely a missed punch. Hit **Fix** to jump straight to that timesheet entry and set the real clock-out time.
- **Accidental / rushed taps.** If the same person taps again within `CLOCK_COOLDOWN_SECONDS` (default 30) of clocking in or out, the repeat is politely ignored so a just-started shift isn't instantly closed (or a just-ended one reopened) in a rush. It's per-person, so it never blocks the next member of staff.
- **Clocking in doesn't need a schedule.** Anyone active can clock in and their hours count; schedules only drive the late/no-show flags. People who work without a schedule show as *Worked (no schedule)* on the dashboard.
- **Tunable settings** live in `config.php`: `GRACE_MINUTES` and `NOSHOW_AFTER_MINUTES` (lateness/no-show grace), `OPEN_SHIFT_ALERT_HOURS`, `CLOCK_COOLDOWN_SECONDS`, and the PIN lockout (`PIN_MAX_ATTEMPTS`, `PIN_LOCK_SECONDS`). File updates never overwrite `config.php`, so your values are safe.
- **PINs** are 4 digits and stored hashed. Because the number space is small, the kiosk locks a person out for a short time after several wrong tries. For a wall-mounted tablet with physical supervision this is a reasonable balance; treat PINs as convenience, not high security.
- **Deleting a brand** leaves past entries intact but drops the brand label on them. **Deleting a role** removes it from anyone who had it. Deactivating instead keeps everything and just hides it from the kiosk.

## File map

```
install.php         web installer (run once, then delete)
config.php          settings + DB credentials
db.php              PDO connection
helpers.php         sessions, CSRF, time conversion, admin layout
schema.sql          database tables + starter data
assets/
  style.css         kiosk + admin styles (responsive)
  kiosk.js          kiosk interactivity
  logo-primary.svg  MustrHQ logo (dark surfaces off, for light backgrounds)
  logo-light.svg    white logo for the dark kiosk
  logo-mark.svg     symbol only
kiosk/
  index.php         the tablet screen
  clock.php         clock in/out endpoint (JSON)
  manifest.php      web app manifest (installable PWA)
  sw.js             service worker (offline shell)
  icons/            app icons
admin/
  setup.php         one-time first-admin creation (delete after use)
  upgrade.php       apply database updates (create new tables) — safe to re-run
  update.php        upload a zip and update the app in place (with backup)
  login.php  logout.php
  index.php         dashboard
  staff.php         staff + PIN + rate + brands + roles + photo
  timesheets.php    view / edit / add / delete entries (any date)
  import.php        bulk import past timecards from CSV
  schedules.php     recurring expected starts + rota grid + CSV/PDF/JPG export
  lists.php         brands & roles
  kiosks.php        kiosk pairing codes (per-tablet, optional brand)
  admins.php        admin accounts
  reports.php       hours, wages, CSV
uploads/            staff photos (script execution blocked via .htaccess)
backups/            rollback backups from the in-admin updater (web access denied)
```

---

## Contributing

Bug reports, fixes and ideas are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).
Please report security issues privately, as described in [SECURITY.md](SECURITY.md).

## Licence

MustrHQ Rota is free software, released under the
[GNU Affero General Public Licence v3.0](LICENSE) (AGPL-3.0).

You can use it, change it and host it for your own business at no cost. If you modify it and
let other people use your modified version over a network — for example, running it as a
hosted service — you must make your modified source code available to them under the same licence.
