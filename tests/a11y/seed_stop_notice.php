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
 * Builds the fixture of the accessibility suites: an attempt that ends with the stop notice (issue #20).
 *
 * The same situation as the Behat scenario "No suitable question left": questions on levels 1 and 3
 * only, five allowed. After two answers there is nothing left to ask, the attempt ends on its own
 * and the result page explains why - in the dialog the suites check.
 *
 * Every run creates a new participant, so each run meets a fresh attempt; course, question bank and
 * activity are reused. Prints the values the suites need as KEY=value lines.
 *
 * Usage, from anywhere:
 *     php seed_stop_notice.php --moodle=/path/to/moodle [--password=...] [--user=a11ystudent]
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// The script finds the config.php it was pointed at first, so the usual include cannot come first.

$options = getopt('', ['moodle:', 'password::', 'user::']);
if (empty($options['moodle'])) {
    fwrite(STDERR, "--moodle=/path/to/moodle is required.\n");
    exit(1);
}
$password = $options['password'] ?? 'A11y-Test-123!';
$username = $options['user'] ?? ('a11ystudent' . date('His'));

require($options['moodle'] . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/enrollib.php');
// The data generators live under lib/testing and are not autoloaded outside the test runners.
require_once($CFG->libdir . '/testing/generator/lib.php');
// The question generator builds its questions with the helpers of the question tests, which extend
// PHPUnit classes; outside the test runners they come from Composer's autoloader (Moodle 5.1+ keeps
// vendor/ next to public/, older versions inside the code root).
foreach ([dirname($CFG->dirroot) . '/vendor/autoload.php', $CFG->dirroot . '/vendor/autoload.php'] as $autoload) {
    if (file_exists($autoload)) {
        require_once($autoload);
        break;
    }
}

\core\session\manager::set_user(get_admin());

/**
 * Sends cron output to STDERR: STDOUT of this script is the environment for the suites.
 *
 * @param string $string
 * @param string $eol
 */
function a11y_seed_mtrace(string $string, string $eol): void {
    fwrite(STDERR, $string . $eol);
}

// Moodle 5.0+ moves question banks into qbank activities with ad-hoc tasks after install. Until
// they have run, question_bank_helper::has_bank_migration_task_completed_successfully() is false
// and view.php tells the participant that the item bank is not configured - no "Start attempt".
// A fresh CI site never runs cron, so the seed runs them. Moodle 4.5 has no such tasks.
$CFG->mtrace_wrapper = 'a11y_seed_mtrace';
$defaultbank = $CFG->corequestion_defaultqbankmod ?? 'qbank';
foreach (['transfer_question_categories', 'transfer_questions'] as $taskname) {
    $classname = "\\mod_{$defaultbank}\\task\\{$taskname}";
    if (class_exists($classname) && \core\task\manager::get_adhoc_tasks($classname)) {
        \core\cron::run_adhoc_tasks(time(), 0, false, null, null, $classname);
    }
}
$bankhelper = \core_question\local\bank\question_bank_helper::class;
$migrationcheck = class_exists($bankhelper) && method_exists($bankhelper, 'has_bank_migration_task_completed_successfully');
if ($migrationcheck && !$bankhelper::has_bank_migration_task_completed_successfully()) {
    fwrite(STDERR, "The question bank migration tasks did not complete; the activity will not be attemptable.\n");
    exit(1);
}
$generator = new testing_data_generator();
$questions = $generator->get_plugin_generator('core_question');

$course = $DB->get_record('course', ['shortname' => 'A11Y1']);
if (!$course) {
    $course = $generator->create_course(['fullname' => 'Accessibility', 'shortname' => 'A11Y1', 'numsections' => 1]);
}

// The participant: new on every run, so the attempt is always the first one.
$user = $DB->get_record('user', ['username' => $username]);
if (!$user) {
    $user = $generator->create_user([
        'username' => $username, 'password' => $password,
        'firstname' => 'Ada', 'lastname' => 'Accessible', 'email' => $username . '@example.com',
    ]);
}
$generator->enrol_user($user->id, $course->id, 'student');

$quiz = $DB->get_record('adaptivequiz', ['course' => $course->id, 'name' => 'Stop notice']);
if (!$quiz) {
    $qbank = $generator->create_module('qbank', ['course' => $course->id, 'name' => 'A11y bank', 'idnumber' => 'a11yqbank']);
    $category = $questions->create_question_category(['contextid' => context_module::instance($qbank->cmid)->id]);
    foreach (['First question' => 'adpq_1', 'Second question' => 'adpq_3'] as $text => $tag) {
        $question = $questions->create_question('truefalse', null, [
            'category' => $category->id, 'name' => $text, 'questiontext' => ['text' => $text, 'format' => FORMAT_HTML],
        ]);
        $context = context::instance_by_id($category->contextid);
        core_tag_tag::set_item_tags('core_question', 'question', $question->id, $context, [$tag]);
    }
    $quiz = $generator->create_module('adaptivequiz', [
        'course' => $course->id, 'name' => 'Stop notice', 'idnumber' => 'a11ystopnotice',
        'startinglevel' => 1, 'lowestlevel' => 1, 'highestlevel' => 3,
        'minimumquestions' => 1, 'maximumquestions' => 5, 'standarderror' => 1,
    ]);
    $generator->get_plugin_generator('mod_adaptivequiz')->create_link_with_question_bank([
        'adaptivequizid' => $quiz->id, 'qbankid' => $qbank->id,
    ]);
}
$cm = get_coursemodule_from_instance('adaptivequiz', $quiz->id, $course->id, false, MUST_EXIST);

echo 'A11Y_USER=' . $username . "\n";
echo 'A11Y_PASSWORD=' . $password . "\n";
echo 'A11Y_ACTIVITY_URL=/mod/adaptivequiz/view.php?id=' . $cm->id . "\n";
fwrite(STDERR, "Stop notice fixture ready: course {$course->id}, activity {$cm->id}, participant {$username}.\n");
