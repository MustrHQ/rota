# Contributing to MustrHQ Rota

Thanks for taking the time to help. MustrHQ Rota runs on wall tablets in real kitchens, so the
bar is simple: changes should keep it easy to install on ordinary shared hosting and quick to
use with a floury thumb at the start of a shift.

## Ground rules

- **Plain PHP and MySQL.** No frameworks, no Composer, no Node, no build step. Anything that
  cannot be uploaded to cPanel and run as-is will not be merged.
- **PHP 7.4 or newer** must keep working. Avoid features that need a newer version.
- **Times are stored in UTC** and converted to `APP_TZ` only for display. Keep it that way, so
  overnight shifts and clock changes stay correct.
- **Security first.** Prepared statements for all SQL, escaped output, a CSRF token on every
  admin form, and `require_admin()` on every admin page.
- **Database changes** go in `schema.sql` *and* `admin/upgrade.php`, and must upgrade existing
  installs without touching data — add tables and columns, never drop or rewrite.
- **New settings** need a default in `helpers.php`, because file updates never overwrite an
  existing `config.php`.

## Reporting a bug

Open an issue using the **Bug report** template. The most useful reports include the version,
PHP and MySQL versions, what you did, what you expected and what happened instead. Screenshots
help.

**Security problems should not go in a public issue** — see [SECURITY.md](SECURITY.md).

## Suggesting a feature

Open an issue using the **Feature request** template and describe the job you are trying to
get done in the kitchen, not just the button you would add.

## Sending a change

1. Fork the repository and create a branch from `main`.
2. Set up a local copy: any PHP 7.4+ with MySQL/MariaDB works (MAMP, Laravel Herd, XAMPP,
   or `php -S localhost:8000`). Open `install.php` and follow the steps.
3. **Don't commit your `config.php`.** The copy in the repository is a blank template. After the
   installer fills yours in, run `git update-index --skip-worktree config.php` so your database
   details never end up in a commit.
4. Make your change. Keep to the existing style: small functions, readable names, comments that
   explain *why*.
5. Check it at desktop and tablet widths, on both the kiosk and the admin panel.
6. Run `php -l` over any PHP file you touched.
7. If users will notice the change, add a line under **Unreleased** in `CHANGELOG.md`.
8. Open a pull request and fill in the template.

## Licence of contributions

By contributing, you agree that your contribution is licensed under the
[GNU AGPL-3.0](LICENSE), the same licence as the project.

## Code of conduct

Everyone taking part is expected to follow the [Code of Conduct](CODE_OF_CONDUCT.md).
