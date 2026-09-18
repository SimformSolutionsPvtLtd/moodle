# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

This is **SimformSolutionsPvtLtd/moodle**, a fork of upstream Moodle (`moodle/moodle`) pinned to the **401 branch (Moodle 4.1.3+, `MOODLE_401_STABLE`)**. It is a customized production LMS, not a fresh Moodle install — the vast majority of the tree is unmodified Moodle core; Simform's changes are scattered, targeted patches on top of it.

- `origin` → `SimformSolutionsPvtLtd/moodle` (this fork)
- `upstream` → `moodle/moodle` (for pulling official security/bug fixes into `MOODLE_401_STABLE`)
- Node engine pinned to `>=16.14.0 <17`; PHP `>=7.4` per `composer.json` (production currently runs PHP 7.4 / MySQL 8.0 — see "In-flight initiatives" below).

## How customizations are made in this fork

Unlike a typical Moodle site (which isolates customizations into plugins under `local/`, custom themes, etc.), this fork's history shows changes applied **directly to Moodle core files** — e.g. `lib/classes/navigation/views/primary.php`, `grade/report/grader/*`, `mod/assign/db/tasks.php`. There are no custom `local/` plugins and the `theme/boost`/`theme/classic` directories are stock. When asked to add a feature, follow the existing convention already established in this repo (direct core edit) unless the user asks you to build a proper plugin — but be aware direct core edits make future upstream merges harder, so flag that tradeoff if it's a large change.

The one exception is `custompage/`, a genuinely separate custom module:
- `custompage/frontpage.php` — a custom landing/login page, wired in via `$CFG->customfrontpageinclude` in `config.php`. It hides Moodle's default chrome (nav, header, footer) with inline CSS/JS for logged-out users and renders a bespoke centered login screen.
- `custompage/assets/` — static assets (e.g. logo) for that page.

When editing the frontpage/login experience, this file is the entry point, not `login/index.php` or theme templates.

## Commands

### PHP / Composer
```
composer install          # installs dev deps: phpunit, behat, vfsstream (require-dev only, no runtime deps)
```

### JS/CSS build (Grunt)
```
npm install
npx grunt                 # default task ('startup'): runs the full JS/CSS/lint pipeline
npx grunt amd             # rebuild AMD JS modules for changed components
npx grunt css             # rebuild theme CSS from SCSS
npx grunt watch           # watch mode
```
Task definitions live under `.grunt/tasks/` (`eslint.js`, `sass.js`, `shifter.js`, `stylelint.js`, `jsdoc.js`, etc.) — check there before assuming a task exists.

Alternatively, core also has `admin/cli/build_theme_css.php` for regenerating theme CSS without Node.

### Linting
```
npx eslint <path>          # config in .eslintrc
```

### Tests
PHPUnit and Behat both require one-time environment initialization against a configured `config.php` before they'll run (standard Moodle requirement — there is no bootstrap-free "just run pytest" equivalent):
```
php admin/tool/phpunit/cli/init.php          # one-time: builds the PHPUnit test DB/dataroot
vendor/bin/phpunit                           # run full suite
vendor/bin/phpunit <path/to/testcase.php>    # run one test file
vendor/bin/phpunit --filter test_method_name # run a single test method

php admin/tool/behat/cli/init.php            # one-time: sets up the Behat test site
php admin/tool/behat/cli/run.php             # run Behat features
php admin/tool/behat/cli/run.php --tags=@mod_assign  # run a tagged subset
```
`phpunit.xml.dist` and `behat.yml.dist` are the dist templates Moodle's init scripts generate real config from — don't hand-edit the generated `phpunit.xml`/`behat.yml` expecting it to survive re-init.

### Common admin/maintenance CLI scripts
Useful when reasoning about or reproducing behavior (all under `admin/cli/`): `cron.php`, `upgrade.php`, `purge_caches.php`, `check_database_schema.php`, `scheduled_task.php` (run one ad-hoc/scheduled task by name), `adhoc_task.php`.

## Local environment

- `config.php` (gitignored in stock Moodle but present/tracked here) points at `wwwroot = http://localhost/Simform/moodle`, MySQL (`mysqli`), and an external `dataroot` at `/var/www/simform/moodledata`. Confirm these before assuming a different local setup.

## In-flight initiatives

- `moodle_mysql_84_upgrade_plan.md` (untracked, repo root) documents a planned staged upgrade: Moodle 4.1.3 → 5.3 LTS, PHP 7.4 → 8.3+, MySQL 8.0 → 8.4, run through a staging clone first. Consult it before touching upgrade/environment-compatibility code so changes align with the planned path rather than conflicting with it.
