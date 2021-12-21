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
 * The send email queue notifications task.
 *
 * @package   enrol_manual
 * @author    Farhan Karmali <farhan6318@gmail.com>
 * @copyright Farhan Karmali
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace enrol_manual\task;

defined('MOODLE_INTERNAL') || die();

/**
 * The send email queue notifications task.
 *
 * @package   enrol_manual
 * @author    Farhan Karmali <farhan6318@gmail.com>
 * @copyright Farhan Karmali
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_email_notifications_for_manual extends \core\task\scheduled_task {

    /**
     * Name for this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('sendemailnotificationsformanual', 'enrol_manual');
    }

    /**
     * Run task for sending email queue notifications.
     */
    public function execute() {
        global $DB, $USER, $CFG;
        require_once($CFG->libdir . '/moodlelib.php');

        $ues = $DB->get_records('user_enrolments', array('emailsent'=>'0'), '', '*');
        foreach($ues as $ue) {
            if ($user = $DB->get_record('user', array('id'=>$ue->userid), '*', MUST_EXIST)) {
                if ($enrol = $DB->get_record('enrol', array('id'=>$ue->enrolid), '*', MUST_EXIST)) {
                    if ($course = $DB->get_record('course', array('id'=>$enrol->courseid), '*', MUST_EXIST)) {
                        $courselink = $CFG->wwwroot . "/course/view.php?id=" . $course->id;
                        $body = "Hi ". $user->firstname . " " . $user->lastname .",<br/><br/>" . "You have been enrolled to <strong>" . " " . $course->fullname . "</strong> course.<br/><br/>" . "Please <a href='" . $courselink . "'>Click here</a> to view your course." . "<br/><br/>" . "Thanks," . "<br/>Admin";
                        email_to_user($user, $USER, 'Enrollment Notification', 'You have been enrolled to course', $body);
                        if (!is_object($ue)) {
                            $ue = (object) $ue;
                        }
                        $ue->emailsent = 1;
                        $DB->update_record('user_enrolments', $ue);
                    }
                }
            }
        }
    }

}
