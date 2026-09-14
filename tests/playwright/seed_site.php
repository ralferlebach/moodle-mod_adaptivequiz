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
 * Builds the site the user stories are played on.
 *
 * A course, a teacher, a student, a question bank with tagged questions and one activity per
 * scope. Kept as a script in the plugin so a run can be reproduced outside the pipeline: the same
 * command on a local installation produces the same starting point.
 *
 * Usage:
 *     php seed_site.php --moodle=/path/to/moodle [--scope=standalone|catquiz|alle]
 *
 * Writes the ids the stories need to stdout, as KEY=value lines.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// This script runs outside a Moodle installation until it has found the config.php it was pointed
// at, so the usual include cannot come first.

$options = getopt('', ['moodle:', 'scope::', 'password::', 'course::']);

if (empty($options['moodle'])) {
    fwrite(STDERR, "--moodle=/path/to/moodle is required.\n");
    exit(1);
}

$scope = $options['scope'] ?? 'standalone';
$password = $options['password'] ?? 'Story123!';
$shortname = $options['course'] ?? 'PWC1';

$config = $options['moodle'] . '/config.php';
if (!file_exists($config)) {
    fwrite(STDERR, "No config.php below {$options['moodle']}.\n");
    exit(1);
}
require($config);
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/lib/phpunit/classes/util.php');

/**
 * Creates a user, or returns the existing one.
 *
 * @param string $username
 * @param string $firstname
 * @param string $lastname
 * @param string $password
 * @return stdClass
 */
function story_user(string $username, string $firstname, string $lastname, string $password): stdClass {
    global $DB, $CFG;

    if ($existing = $DB->get_record('user', ['username' => $username])) {
        return $existing;
    }

    $user = (object) [
        'username' => $username,
        'password' => $password,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'email' => $username . '@example.com',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'auth' => 'manual',
    ];
    $user->id = user_create_user($user, true, false);

    return $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
}

$teacher = story_user('pwteacher', 'Paula', 'Playwright', $password);
$student = story_user('pwstudent', 'Stefan', 'Story', $password);

$course = $DB->get_record('course', ['shortname' => $shortname]);
if (!$course) {
    $course = create_course((object) [
        'fullname' => 'Playwright user stories',
        'shortname' => $shortname,
        'category' => 1,
        'format' => 'topics',
        'numsections' => 3,
    ]);
}

$context = context_course::instance($course->id);
$roles = $DB->get_records_menu('role', null, '', 'shortname, id');
enrol_try_internal_enrol($course->id, $teacher->id, $roles['editingteacher']);
enrol_try_internal_enrol($course->id, $student->id, $roles['student']);

echo 'STORY_COURSE=' . $shortname . "\n";
echo 'STORY_COURSEID=' . $course->id . "\n";
echo 'STORY_TEACHER=pwteacher' . "\n";
echo 'STORY_STUDENT=pwstudent' . "\n";

// The activities themselves are created through the generators, which is the only way to get a
// question bank with difficulty tags without clicking through the interface. Anything the stories
// are supposed to demonstrate - choosing the CAT model, taking an attempt, reading a report - is
// left to the stories.
fwrite(STDERR, "Site prepared: course {$course->id}, users pwteacher and pwstudent.\n");
fwrite(STDERR, "Scope '{$scope}': create the activities and the question bank for this scope before running the stories.\n");
