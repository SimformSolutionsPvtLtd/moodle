# Moodle Upgrade Plan — 4.1.3+ → 5.3 LTS

Staged upgrade plan for this fork (direct core edits, no isolated `local/` plugins, custom `custompage/` module). Not executed yet — for review. Work through stages top to bottom; do not skip ahead.

## Status legend
`[ ]` Not started · `[~]` In progress · `[x]` Done

## Stage 0 — Baseline & safety net

| # | Status | Task |
|---|--------|------|
| 0.1 | [ ] | Full DB dump of current install |
| 0.2 | [ ] | Full file copy of current `moodle` root |
| 0.3 | [ ] | Diff this fork against pristine upstream `moodle/moodle` @ `MOODLE_401_STABLE` — produce manifest of every modified core file |
| 0.4 | [ ] | Stand up/confirm staging clone (separate DB container, not production data) |

## Stage 1 — Land on Moodle 4.4/4.5 LTS

| # | Status | Task | Requirement |
|---|--------|------|-------------|
| 1.1 | [ ] | Bump PHP to ≥ 8.1 (staging) | PHP 8.1–8.3 |
| 1.2 | [ ] | Confirm MySQL stays compatible | MySQL 8.0 (no bump needed) |
| 1.3 | [ ] | Replace core files with 4.4/4.5 release | — |
| 1.4 | [ ] | Run Moodle upgrade (Site administration → Notifications) | — |
| 1.5 | [ ] | Re-apply Stage 0.3 core-file diffs on new core, resolve conflicts | — |
| 1.6 | [ ] | Full regression: PHPUnit + Behat | — |
| 1.7 | [ ] | Manual check: custom frontpage/login (`custompage/frontpage.php`) | — |

## Stage 2 — Move to Moodle 5.0/5.1

| # | Status | Task | Requirement |
|---|--------|------|-------------|
| 2.1 | [ ] | Confirm site is on 4.5+ (hard prerequisite for 5.1) | — |
| 2.2 | [ ] | Bump PHP to ≥ 8.2 | PHP 8.2–8.4 |
| 2.3 | [ ] | Bump MySQL to ≥ 8.4 | MySQL 8.4 / MariaDB 10.11 |
| 2.4 | [ ] | Repoint Apache `DocumentRoot`/vhost to new `/public/` subdirectory | breaking change in 5.1 |
| 2.5 | [ ] | Update anything referencing old root layout (`config.php` includes, `scripts/run-stage.sh`, deployment scripts) | — |
| 2.6 | [ ] | Run `composer install --no-dev` — `vendor/` now mandatory at runtime | — |
| 2.7 | [ ] | Install/confirm `sodium` PHP extension | new requirement |
| 2.8 | [ ] | Set `max_input_vars ≥ 5000` in php.ini | new requirement |
| 2.9 | [ ] | Re-apply core-file diffs, resolve conflicts | — |
| 2.10 | [ ] | Full regression: PHPUnit + Behat + manual pass | — |

## Stage 3 — Move to Moodle 5.2/5.3 LTS

| # | Status | Task | Requirement |
|---|--------|------|-------------|
| 3.1 | [ ] | Bump PHP to ≥ 8.3 (Apache, not just CLI) | PHP 8.3–8.4 |
| 3.2 | [ ] | Confirm MySQL/MariaDB floor unchanged | MySQL 8.4 / MariaDB 10.11 |
| 3.3 | [ ] | Review any third-party plugins against new Composer-native plugin distribution | 5.2 change |
| 3.4 | [ ] | Replace core files with 5.3 LTS release | — |
| 3.5 | [ ] | Re-apply core-file diffs, resolve conflicts | — |
| 3.6 | [ ] | Run `admin/cli/check_database_schema.php` | — |
| 3.7 | [ ] | Full regression: PHPUnit + Behat + manual pass | — |

## Cross-cutting notes

- Core-diff reconciliation (Stage 0.3, repeated at 1.5 / 2.9 / 3.5) is the real cost center — this fork has no plugin isolation, so every core upgrade risks silently overwriting custom logic.
- Don't bump Apache's PHP version ahead of the stage's floor — track CLI vs Apache PHP versions separately at each step.
- Never upgrade against production data directly — staging clone only, per Stage 0.4.

## Version/requirement reference

| Moodle version | Min PHP | Max PHP | Min MySQL | Min MariaDB | Key breaking change |
|---|---|---|---|---|---|
| 4.1 (current) | 7.4 | 8.1 | 5.7 | 10.4 | — |
| 4.2–4.3 | 8.0 | 8.2 | 8.0 | 10.6.7 | PHP 7 support dropped |
| 4.4–4.5 LTS | 8.1 | 8.3 | 8.0 | 10.6.7 | — |
| 5.0 | 8.2 | 8.4 | 8.4 | 10.11 | Oracle DB dropped, 64-bit PHP only |
| 5.1 | 8.2 | 8.4 | 8.4 | 10.11 | `/public/` webroot move, mandatory Composer vendor, requires 4.5+ first |
| 5.2 | 8.3 | 8.4 | 8.4 | 10.11 | Composer-native plugin distribution |
| 5.3 LTS | 8.3 | 8.4 | 8.4 | 10.11 | Requires 4.5+ prerequisite; unreleased as of Sept 2026 |
