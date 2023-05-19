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

namespace mod_assign\task;
defined('MOODLE_INTERNAL') || die();

/**
 * A schedule task for submission not submitted reminder cron.
 *
 * @package   mod_assign
 * @copyright 2019 Simey Lameze <simey@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_not_submitted_email_reminder extends \core\task\scheduled_task {
    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('submissionreminder', 'mod_assign');
    }

    /**
     * Run assignment cron.
     */
    public function execute() {
        global $DB, $USER, $CFG;
        require_once($CFG->libdir . '/moodlelib.php');
        $trace = new \text_progress_trace();
        $assignments = $DB->get_records('assign', null, '', '*');
        foreach($assignments as $a) {
            if($a->duedate < (time() - 86400) || $a->duedate > (time())) {
                continue;
            }
            $cm = get_coursemodule_from_instance('assign', $a->id);
            $assignurl = new \moodle_url('/mod/assign/view.php', array('id' => $cm->id));
            $course = $DB->get_record('course', array('id' => $a->course));
            $courseurl = new \moodle_url('/course/view.php', array('id' => $course->id));
            $enrols = $DB->get_records('enrol', array('courseid' => $a->course), '', 'id');
            $enrolids = array();
            foreach($enrols as $enrol) {
                $enrolids[] = $enrol->id;
            }
            list($insql, $inparams) = $DB->get_in_or_equal($enrolids);
            $sql = "select distinct(userid), id from {user_enrolments} where enrolid $insql";
            $ues = $DB->get_records_sql($sql, $inparams);
            foreach($ues as $ue) {
                if(!$DB->get_records('assign_submission', array('assignment' => $a->id, 'userid' => $ue->userid, 'status' => "submitted"), '', '*')) {
                    $context = \context_course::instance($course->id);
                    $roles = get_user_roles($context, $ue->userid, true);
                    $roletypes = array_map(function($o) {
                        return $o->archetype;
                    }, $roles);
                    if (in_array("student", $roletypes) || in_array("user", $roletypes)) {
                        $user = $DB->get_record('user', array('id' => $ue->userid));
                        $body = "Hi ". $user->firstname . " " . $user->lastname .",<br/><br/>" . "You have not submitted your assignment yet, Please submit your assignment and inform your mentor.<br/><br/>" . "<a href='" . $courseurl . "'>" . $course->fullname . "</a> : <a href='" . $assignurl . "'>" . $a->name . "</a>". "<br/><br/>Thanks," . "<br/>Admin";
                        email_to_user($user, $USER, 'Assignment Not Submitted Yet', 'Reminder for Assignment Submission', $body);
                        $trace->output("notifying user $ue->userid - $user->email that assignment not submitted yet in $course->fullname");
                    }
                }
            }
        }
        $trace->finished();
    }
       
}
