<?php
require_once(__DIR__ . '/config.php');
require_once($CFG->libdir.'/enrollib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

// 🔴 COURSE IDs TO FIX
$courseids = [597, 588, 37, 587, 590, 589, 591, 598, 592];

$now = time();
$seven_days_ago = strtotime('-7 days');

foreach ($courseids as $courseid) {

    // Skip invalid courses
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        continue;
    }

    $context = context_course::instance($courseid);
    $users = get_enrolled_users($context);

    foreach ($users as $user) {

        // Check if user already has last access (NOT "Never")
        $exists = $DB->record_exists('user_lastaccess', [
            'userid'   => $user->id,
            'courseid' => $courseid
        ]);

        // ONLY handle users with "Never"
        if ($exists) {
            continue;
        }

        // Random timestamp in last 7 days
        $randomtime = rand($seven_days_ago, $now);

        // Insert last access record
        $DB->insert_record('user_lastaccess', [
            'userid'     => $user->id,
            'courseid'   => $courseid,
            'timeaccess' => $randomtime
        ]);
    }
}

echo "✅ Updated 'Never' last-access users to a random date within the last 7 days.";
