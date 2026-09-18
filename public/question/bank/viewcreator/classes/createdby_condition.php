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

namespace qbank_viewcreator;

use core\output\datafilter;
use core_question\local\bank\condition;

/**
 * Filter condition for filtering on creator name
 *
 * @package   qbank_viewcreator
 * @copyright 2025 onwards Catalyst IT EU {@link https://catalyst-eu.net}
 * @author    Mark Johnson <mark.johnson@catalyst-eu.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class createdby_condition extends user_condition {
    #[\Override]
    public function get_title() {
        return get_string('createdby', 'question');
    }

    #[\Override]
    public static function get_condition_key() {
        return 'createdby';
    }

<<<<<<<< HEAD:public/mod/chat/db/upgrade.php
    // Automatically generated Moodle v4.2.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v4.3.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v4.4.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v4.5.0 release upgrade line.
    // Put any upgrade step following this.

    // Automatically generated Moodle v4.1.0 release upgrade line.
    // Put any upgrade step following this.

    return true;
========
    #[\Override]
    protected static function get_table_alias(): string {
        return 'uc';
    }
>>>>>>>> upstream/MOODLE_501_STABLE:public/question/bank/viewcreator/classes/createdby_condition.php
}
