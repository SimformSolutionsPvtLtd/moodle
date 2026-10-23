<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to move OIDC users (and their auth_oidc_token rows) from one email domain to another.
 *
 * Dry-run is the default. Nothing is written unless --mode=apply --confirm is given.
 *
 * @package    core
 * @subpackage cli
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once(__DIR__ . '/oidc_domain_migrate_lib.php');

$longoptions = [
    'help' => false,
    'from' => null,
    'to' => null,
    'mode' => 'dry-run',
    'csv' => null,
    'userids' => null,
    'batch' => 200,
    'outdir' => null,
    'log' => null,
    'include-manual' => false,
    'include-admins' => false,
    'confirm' => false,
];
[$options, $unrecognized] = cli_get_params($longoptions, ['h' => 'help']);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)), 2);
}

if ($options['help']) {
    echo <<<EOF
Moves OIDC users from one email domain to another (user.username, user.email and the
auth_oidc_token username / oidcusername / useridentifier columns).

Options:
-h, --help            Print this help
    --from=DOMAIN     Old domain, e.g. simformsolutions.com
    --to=DOMAIN       New domain, e.g. simform.com
    --mode=MODE       dry-run (default), apply or rollback
    --csv=FILE        Optional old_email,new_email mapping, wins over the domain swap
    --userids=1,2,3   Only these user ids (use for the pilot batch)
    --batch=N         Users per transaction (default 200)
    --outdir=DIR      Where snapshot, change log and report go (default: dataroot/oidc_domain_migrate/<time>)
    --log=FILE        changes.jsonl of an earlier apply run (rollback mode only)
    --include-manual  Also migrate auth=manual users (email/username only; default is OIDC users only)
    --include-admins  Also migrate site admin accounts (skipped by default)
    --confirm         Required for apply and rollback

Examples:
\$ php admin/cli/oidc_domain_migrate.php --from=simformsolutions.com --to=simform.com
\$ php admin/cli/oidc_domain_migrate.php --from=simformsolutions.com --to=simform.com --mode=apply --confirm --userids=1609
\$ php admin/cli/oidc_domain_migrate.php --mode=rollback --confirm --log=/path/changes.jsonl

EOF;
    exit(0);
}

$mode = $options['mode'];
if (!in_array($mode, ['dry-run', 'apply', 'rollback'], true)) {
    cli_error('--mode must be dry-run, apply or rollback', 2);
}
if (!$DB->get_manager()->table_exists('auth_oidc_token')) {
    cli_error('Table auth_oidc_token does not exist - is auth_oidc installed?');
}
if ($mode !== 'dry-run' && empty($options['confirm'])) {
    cli_error("--mode=$mode needs --confirm. Take a full database backup first.", 2);
}

$batchsize = max(1, (int) $options['batch']);
$outdir = $options['outdir'] ?: $CFG->dataroot . '/oidc_domain_migrate/' . date('Ymd-His');
if ($mode !== 'dry-run' || $options['outdir']) {
    if (!is_dir($outdir) && !mkdir($outdir, 0770, true)) {
        cli_error("Cannot create $outdir");
    }
}

if (!empty($CFG->maintenance_enabled) === false && $mode !== 'dry-run') {
    cli_problem('Warning: maintenance mode is off. Users logging in during the migration may hit a half-updated row.');
}

// Rollback ------------------------------------------------------------------------------------------------------------
if ($mode === 'rollback') {
    if (empty($options['log']) || !is_readable($options['log'])) {
        cli_error('--log must point at a readable changes.jsonl', 2);
    }
    $entries = [];
    foreach (file($options['log'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $entry = json_decode($line, true);
        if (!is_array($entry) || !isset($entry['table'], $entry['id'], $entry['field'], $entry['old'], $entry['new'])) {
            cli_error('Change log has an unreadable line: ' . $line);
        }
        $entries[] = $entry;
    }
    $entries = array_reverse($entries);
    $done = $skipped = 0;
    foreach (array_chunk($entries, $batchsize) as $chunk) {
        $tx = $DB->start_delegated_transaction();
        foreach ($chunk as $e) {
            $current = $DB->get_field($e['table'], $e['field'], ['id' => $e['id']], IGNORE_MISSING);
            if ($current !== $e['new']) {
                cli_problem("Skip {$e['table']}#{$e['id']}.{$e['field']}: now '" . var_export($current, true) .
                    "', expected '{$e['new']}'");
                $skipped++;
                continue;
            }
            $DB->set_field($e['table'], $e['field'], $e['old'], ['id' => $e['id']]);
            $done++;
        }
        $tx->allow_commit();
    }
    purge_caches();
    cli_writeln("Rollback finished: $done values restored, $skipped skipped.");
    exit($skipped ? 1 : 0);
}

// Dry-run / apply -----------------------------------------------------------------------------------------------------
foreach (['from', 'to'] as $required) {
    if (empty($options[$required]) || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $options[$required])) {
        cli_error("--$required must be a domain such as example.com", 2);
    }
}
$from = strtolower($options['from']);
$to = strtolower($options['to']);
if ($from === $to) {
    cli_error('--from and --to are the same domain', 2);
}

try {
    $map = $options['csv'] ? oidcmig_read_map($options['csv']) : [];
} catch (RuntimeException $e) {
    cli_error($e->getMessage());
}

// Candidates.
$auths = $options['include-manual'] ? ['oidc', 'manual'] : ['oidc'];
[$authsql, $params] = $DB->get_in_or_equal($auths, SQL_PARAMS_NAMED, 'auth');
$params['mnet'] = $CFG->mnet_localhost_id;
$params['likeu'] = '%@' . $DB->sql_like_escape($from);
$params['likee'] = '%@' . $DB->sql_like_escape($from);
$match = $DB->sql_like('username', ':likeu', false) . ' OR ' . $DB->sql_like('email', ':likee', false);
if ($map) {
    [$insqlu, $pu] = $DB->get_in_or_equal(array_keys($map), SQL_PARAMS_NAMED, 'mapu');
    [$insqle, $pe] = $DB->get_in_or_equal(array_keys($map), SQL_PARAMS_NAMED, 'mape');
    $match .= " OR LOWER(username) $insqlu OR LOWER(email) $insqle";
    $params += $pu + $pe;
}
$where = "deleted = 0 AND mnethostid = :mnet AND auth $authsql AND ($match)";
if (!empty($options['userids'])) {
    $ids = array_filter(array_map('intval', explode(',', $options['userids'])));
    if (!$ids) {
        cli_error('--userids must be a comma separated list of ids', 2);
    }
    [$idsql, $pi] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'uid');
    $where .= " AND id $idsql";
    $params += $pi;
}
$candidates = $DB->get_records_select('user', $where, $params, 'id');

// Context for conflict detection.
$ctx = [
    'usernames' => [], 'emails' => [], 'allowsameemail' => !empty($CFG->allowaccountssameemail),
    'tokensbyuser' => [], 'tokensbyusername' => [], 'tokenowners' => [],
    'admins' => $options['include-admins'] ? [] : array_map('intval', explode(',', (string) $CFG->siteadmins)),
    'planned' => [],
];
$rs = $DB->get_recordset_select('user', 'deleted = 0 AND mnethostid = :m', ['m' => $CFG->mnet_localhost_id],
    '', 'id, username, email');
foreach ($rs as $row) {
    $ctx['usernames'][strtolower($row->username)] = (int) $row->id;
    $ctx['emails'][strtolower($row->email)][] = (int) $row->id;
}
$rs->close();
foreach ($DB->get_records('auth_oidc_token') as $token) {
    $ctx['tokensbyuser'][$token->userid][] = $token;
    $ctx['tokensbyusername'][strtolower($token->username)][] = $token;
    $ctx['tokenowners'][strtolower($token->oidcusername)][] = (int) $token->userid;
}

// Plan.
$plans = [];
foreach ($candidates as $user) {
    $plans[$user->id] = oidcmig_plan_user($user, $from, $to, $map, $ctx) + ['user' => $user];
}

$counts = ['migrate' => 0, 'already_done' => 0, 'conflict' => 0, 'skipped' => 0, 'failed' => 0, 'migrated' => 0];
foreach ($plans as $plan) {
    $counts[$plan['status']]++;
}
cli_writeln('Candidates: ' . count($candidates) . " (domain $from -> $to, mode $mode)");

// Snapshot before touching anything.
if ($mode === 'apply') {
    $snapshot = [];
    foreach ($plans as $id => $plan) {
        if ($plan['status'] === 'migrate') {
            $snapshot[] = [
                'user' => $plan['user'],
                'tokens' => array_values(array_filter($ctx['tokensbyuser'][$id] ?? [])),
            ];
        }
    }
    if (file_put_contents($outdir . '/snapshot.json', json_encode($snapshot, JSON_PRETTY_PRINT)) === false) {
        cli_error('Cannot write snapshot, nothing was changed.');
    }
    cli_writeln("Snapshot written to $outdir/snapshot.json");
}

// Apply in batches. Each batch is one transaction; a failing batch is rolled back and reported.
if ($mode === 'apply') {
    $changelog = $outdir . '/changes.jsonl';
    $tomigrate = array_keys(array_filter($plans, function ($p) {
        return $p['status'] === 'migrate';
    }));
    foreach (array_chunk($tomigrate, $batchsize) as $batch) {
        $applied = [];
        $tx = $DB->start_delegated_transaction();
        try {
            foreach ($batch as $id) {
                $plan = $plans[$id];
                $userupdate = (object) ['id' => $id];
                foreach ($plan['changes'] as $c) {
                    // Optimistic check: the row must still hold the value we planned from.
                    $current = $DB->get_field($c['table'], $c['field'], ['id' => $c['id']], MUST_EXIST);
                    if ($current !== $c['old']) {
                        throw new moodle_exception('generalexceptionmessage', 'error', '',
                            "{$c['table']}#{$c['id']}.{$c['field']} changed since planning");
                    }
                    if ($c['table'] === 'user') {
                        if ($c['field'] === 'username' && $DB->record_exists_select('user',
                            'LOWER(username) = :u AND id <> :id AND mnethostid = :m',
                            ['u' => $c['new'], 'id' => $id, 'm' => $CFG->mnet_localhost_id])) {
                            throw new moodle_exception('generalexceptionmessage', 'error', '',
                                "username {$c['new']} was taken meanwhile");
                        }
                        $userupdate->{$c['field']} = $c['new'];
                    } else {
                        $DB->set_field($c['table'], $c['field'], $c['new'], ['id' => $c['id']]);
                    }
                    $applied[] = $c;
                }
                if (count((array) $userupdate) > 1) {
                    user_update_user($userupdate, false, true);
                }
            }
            $tx->allow_commit();
            $lines = '';
            foreach ($applied as $c) {
                $lines .= json_encode($c + ['time' => time()]) . "\n";
            }
            file_put_contents($changelog, $lines, FILE_APPEND | LOCK_EX);
            foreach ($batch as $id) {
                $plans[$id]['status'] = 'migrated';
                $counts['migrate']--;
                $counts['migrated']++;
            }
        } catch (Throwable $e) {
            try {
                $tx->rollback($e);
            } catch (Throwable $ignored) {
                // Rollback rethrows the original exception; the batch is undone either way.
            }
            foreach ($batch as $id) {
                $plans[$id]['status'] = 'failed';
                $plans[$id]['reason'] = 'batch rolled back: ' . $e->getMessage();
                $counts['migrate']--;
                $counts['failed']++;
            }
            cli_problem('Batch rolled back: ' . $e->getMessage());
        }
    }

    // Verify what was written.
    $bad = 0;
    foreach ($plans as $id => $plan) {
        if ($plan['status'] !== 'migrated') {
            continue;
        }
        foreach ($plan['changes'] as $c) {
            if ($DB->get_field($c['table'], $c['field'], ['id' => $c['id']]) !== $c['new']) {
                $bad++;
                $plans[$id]['status'] = 'failed';
                $plans[$id]['reason'] = "verification failed on {$c['table']}.{$c['field']}";
                $counts['migrated']--;
                $counts['failed']++;
                break;
            }
        }
    }
    purge_caches();
    cli_writeln("Verification: $bad mismatches. Caches purged.");
}

// Report.
$report = fopen($mode === 'dry-run' && !is_dir($outdir) ? 'php://stdout' : $outdir . '/report.csv', 'w');
fputcsv($report, ['userid', 'old_username', 'new_username', 'old_email', 'new_email', 'status', 'reason', 'notes'], ',', '"', '');
foreach ($plans as $id => $plan) {
    $new = ['username' => '', 'email' => ''];
    foreach ($plan['changes'] as $c) {
        if ($c['table'] === 'user') {
            $new[$c['field']] = $c['new'];
        }
    }
    fputcsv($report, [$id, $plan['user']->username, $new['username'], $plan['user']->email, $new['email'],
        $plan['status'], $plan['reason'], implode('; ', $plan['notes'])], ',', '"', '');
}
fclose($report);

cli_writeln(sprintf('Summary: %d to migrate, %d migrated, %d already done, %d conflicts, %d skipped, %d failed.',
    $counts['migrate'], $counts['migrated'], $counts['already_done'], $counts['conflict'], $counts['skipped'],
    $counts['failed']));
if (is_dir($outdir)) {
    cli_writeln("Report: $outdir/report.csv");
}
if ($mode === 'dry-run') {
    cli_writeln('Dry-run only, nothing was changed. Re-run with --mode=apply --confirm after a full database backup.');
}
cli_writeln('Still to do by hand: OIDC "User restrictions" and "Domain hint" settings, suspended users, other places that name the old domain.');

exit(($counts['conflict'] || $counts['failed']) ? 1 : 0);
