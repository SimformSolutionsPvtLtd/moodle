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
 * Pure decision logic for oidc_domain_migrate.php (no database access, so it can be tested on its own).
 *
 * @package    core
 * @subpackage cli
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('CLI_SCRIPT') || defined('OIDCMIG_TESTING') || die();

/** Token columns that carry the Microsoft username and must follow the user's new domain. */
const OIDCMIG_TOKEN_FIELDS = ['username', 'oidcusername', 'useridentifier'];

/**
 * Work out the new value of one identity string.
 *
 * A CSV mapping entry wins over the domain swap. Returns null when the value has nothing to change.
 *
 * @param string $value Current value (username, email, ...).
 * @param string $from Old domain, without "@".
 * @param string $to New domain, without "@".
 * @param array $map Lowercase old address => lowercase new address.
 * @return string|null New lowercase value, or null if it is not on the old domain and not in the map.
 */
function oidcmig_new_value(string $value, string $from, string $to, array $map): ?string {
    $lower = strtolower(trim($value));
    if ($lower === '') {
        return null;
    }
    if (isset($map[$lower])) {
        return $map[$lower] === $lower ? null : $map[$lower];
    }
    $suffix = '@' . strtolower($from);
    if (strlen($lower) > strlen($suffix) && substr($lower, -strlen($suffix)) === $suffix) {
        return substr($lower, 0, -strlen($suffix)) . '@' . strtolower($to);
    }
    return null;
}

/**
 * Decide what to do with one user.
 *
 * Context keys ($ctx):
 *  - usernames: lowercase username => user id, every non-deleted user on the local mnet host.
 *  - emails: lowercase email => list of user ids.
 *  - allowsameemail: bool, $CFG->allowaccountssameemail.
 *  - tokensbyuser: userid => list of token objects (id, userid, username, oidcusername, useridentifier).
 *  - tokensbyusername: lowercase token username => list of token objects.
 *  - tokenowners: lowercase oidcusername => list of userids.
 *  - admins: list of site admin user ids.
 *  - planned: (passed by reference) lowercase new username => user id already claimed in this run.
 *
 * @param stdClass $user Row from {user}.
 * @param string $from Old domain.
 * @param string $to New domain.
 * @param array $map CSV mapping.
 * @param array $ctx Context, see above.
 * @return array ['status' => migrate|already_done|conflict|skipped, 'reason' => string, 'notes' => string[],
 *               'changes' => list of ['table', 'id', 'field', 'old', 'new']]
 */
function oidcmig_plan_user(stdClass $user, string $from, string $to, array $map, array &$ctx): array {
    $result = ['status' => 'already_done', 'reason' => '', 'notes' => [], 'changes' => []];

    if ($user->username === 'guest' || in_array((int) $user->id, $ctx['admins'], true)) {
        $result['status'] = 'skipped';
        $result['reason'] = 'guest or site admin account is never touched';
        return $result;
    }

    $newusername = oidcmig_new_value($user->username, $from, $to, $map);
    $newemail = oidcmig_new_value($user->email, $from, $to, $map);

    foreach (['username' => $newusername, 'email' => $newemail] as $field => $new) {
        if ($new !== null && !filter_var($new, FILTER_VALIDATE_EMAIL)) {
            $result['status'] = 'skipped';
            $result['reason'] = "new $field '$new' is not a valid email address";
            return $result;
        }
    }

    // Tokens: linked by userid, or orphaned (userid 0) and carrying this user's old username.
    $tokens = $ctx['tokensbyuser'][$user->id] ?? [];
    $oldusername = strtolower($user->username);
    foreach ($ctx['tokensbyusername'][$oldusername] ?? [] as $token) {
        if ((int) $token->userid === 0) {
            $tokens[] = $token;
            $result['notes'][] = "orphan token {$token->id} (no userid) will be changed";
        } else if ((int) $token->userid !== (int) $user->id) {
            $result['status'] = 'conflict';
            $result['reason'] = "token {$token->id} with this username belongs to user {$token->userid}";
            return $result;
        }
    }
    if (count($tokens) > 1) {
        $result['notes'][] = count($tokens) . ' token rows found, all will be changed';
    }

    // Conflicts on the new username / email.
    if ($newusername !== null) {
        $owner = $ctx['usernames'][$newusername] ?? null;
        if ($owner !== null && (int) $owner !== (int) $user->id) {
            $result['status'] = 'conflict';
            $result['reason'] = "username $newusername already belongs to user $owner";
            return $result;
        }
        $claimed = $ctx['planned'][$newusername] ?? null;
        if ($claimed !== null && (int) $claimed !== (int) $user->id) {
            $result['status'] = 'conflict';
            $result['reason'] = "username $newusername is also the target of user $claimed";
            return $result;
        }
    }
    if ($newemail !== null && empty($ctx['allowsameemail'])) {
        $others = array_diff($ctx['emails'][$newemail] ?? [], [(int) $user->id]);
        if ($others) {
            $result['status'] = 'conflict';
            $result['reason'] = "email $newemail already used by user " . implode(',', $others);
            return $result;
        }
    }

    $changes = [];
    if ($newusername !== null) {
        $changes[] = ['table' => 'user', 'id' => (int) $user->id, 'field' => 'username',
            'old' => $user->username, 'new' => $newusername];
    }
    if ($newemail !== null) {
        $changes[] = ['table' => 'user', 'id' => (int) $user->id, 'field' => 'email',
            'old' => $user->email, 'new' => $newemail];
    }
    foreach ($tokens as $token) {
        foreach (OIDCMIG_TOKEN_FIELDS as $field) {
            $new = oidcmig_new_value((string) $token->{$field}, $from, $to, $map);
            if ($new === null) {
                continue;
            }
            // Another user's token already carrying this Microsoft username would be deleted by the plugin at next login.
            if ($field === 'oidcusername') {
                $others = array_diff($ctx['tokenowners'][$new] ?? [], [0, (int) $user->id]);
                if ($others) {
                    $result['status'] = 'conflict';
                    $result['reason'] = "token username $new already linked to user " . implode(',', $others);
                    return $result;
                }
            }
            $changes[] = ['table' => 'auth_oidc_token', 'id' => (int) $token->id, 'field' => $field,
                'old' => $token->{$field}, 'new' => $new];
        }
    }

    if (!$changes) {
        return $result;
    }
    if ($newusername !== null) {
        $ctx['planned'][$newusername] = (int) $user->id;
    }
    if (!empty($user->suspended)) {
        $result['notes'][] = 'user is suspended (left unchanged, they still cannot log in)';
    }
    if (!$tokens) {
        $result['notes'][] = 'no OIDC token yet, it will be created at first login';
    }
    $result['status'] = 'migrate';
    $result['changes'] = $changes;
    return $result;
}

/**
 * Parse the optional mapping CSV (old_email,new_email, header row allowed).
 *
 * @param string $path File to read.
 * @return array Lowercase old => lowercase new.
 * @throws RuntimeException On unreadable file or a bad row.
 */
function oidcmig_read_map(string $path): array {
    $handle = @fopen($path, 'r');
    if (!$handle) {
        throw new RuntimeException("Cannot read mapping file $path");
    }
    $map = [];
    $line = 0;
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $line++;
        if (count($row) === 1 && trim((string) $row[0]) === '') {
            continue;
        }
        $old = strtolower(trim((string) ($row[0] ?? '')));
        $new = strtolower(trim((string) ($row[1] ?? '')));
        if ($line === 1 && !filter_var($old, FILTER_VALIDATE_EMAIL)) {
            continue; // Header row.
        }
        if (!filter_var($old, FILTER_VALIDATE_EMAIL) || !filter_var($new, FILTER_VALIDATE_EMAIL)) {
            fclose($handle);
            throw new RuntimeException("Mapping file line $line: both columns must be valid email addresses");
        }
        if (isset($map[$old]) && $map[$old] !== $new) {
            fclose($handle);
            throw new RuntimeException("Mapping file line $line: $old is mapped twice with different targets");
        }
        $map[$old] = $new;
    }
    fclose($handle);
    return $map;
}
