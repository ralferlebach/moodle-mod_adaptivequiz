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

namespace mod_adaptivequiz\local\catmodel;

use advanced_testcase;
use context_module;
use mod_adaptivequiz\local\attempt\attempt_state;
use stdClass;

/**
 * A deletion the CAT model cannot complete changes nothing; run again, it completes.
 *
 * Deleting an attempt, resetting the course and deleting the activity are each one transaction together with the
 * CAT model's deletion. Before, the attempt and its question usage were gone when the CAT model failed - its data
 * stayed behind with nothing left to delete it by.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_delete_attempt')]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_reset_userdata')]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_delete_instance')]
final class deletion_transaction_test extends advanced_testcase {
    /**
     * An activity of the test CAT model with an attempt that has a question usage.
     *
     * @return array{0: stdClass, 1: stdClass}
     */
    private function activity_with_attempt(): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $adaptivequiz = $generator->create_module('adaptivequiz', ['course' => $course->id, 'catmodel' => 'testcatmodel']);
        $user = $generator->create_user();
        $quba = \question_engine::make_questions_usage_by_activity(
            'mod_adaptivequiz',
            context_module::instance($adaptivequiz->cmid)
        );
        $quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($quba);
        $attemptid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id, 'userid' => $user->id, 'uniqueid' => $quba->get_id(),
            'attemptstate' => attempt_state::COMPLETED, 'attemptstopcriteria' => 'done', 'questionsattempted' => 3,
            'difficultysum' => 15.0, 'standarderror' => 0.5, 'measure' => 0.5, 'timecreated' => time() - 100,
            'timemodified' => time(), 'timefinished' => time(),
        ]);
        return [$adaptivequiz, $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST)];
    }

    /**
     * What the deletion touches.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attempt
     * @return array
     */
    private static function state(stdClass $adaptivequiz, stdClass $attempt): array {
        global $DB;
        return [
            'activity' => $DB->record_exists('adaptivequiz', ['id' => $adaptivequiz->id]),
            'cm' => $DB->record_exists('course_modules', ['id' => $adaptivequiz->cmid]),
            'attempt' => $DB->record_exists('adaptivequiz_attempt', ['id' => $attempt->id]),
            'usage' => $DB->record_exists('question_usages', ['id' => $attempt->uniqueid]),
        ];
    }

    /**
     * Deletes a course module the way the running Moodle version wants it.
     *
     * Moodle 5.2 deprecates course_delete_module() in favour of core_courseformat\local\cmactions::delete();
     * 5.1 does not have the latter.
     *
     * @param int $cmid
     * @param int $courseid
     */
    private static function delete_module(int $cmid, int $courseid): void {
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            \core_courseformat\formatactions::cm($courseid)->delete($cmid);
        } else {
            course_delete_module($cmid);
        }
    }

    /**
     * The paths.
     *
     * @return array
     */
    public static function paths(): array {
        return ['attempt' => ['attempt'], 'reset' => ['reset'], 'activity' => ['activity']];
    }

    /**
     * The CAT model fails: everything stays; it works again: the same deletion completes.
     *
     * @param string $path
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('paths')]
    public function test_a_failing_catmodel_changes_nothing(string $path): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
        require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/testcatmodel/lib.php');
        $this->resetAfterTest();
        // The deletions are transactions under test: no test transaction around them (PostgreSQL).
        $this->preventResetByRollback();
        set_config('coursebinenable', 0, 'tool_recyclebin');
        [$adaptivequiz, $attempt] = $this->activity_with_attempt();
        $other = $this->activity_with_attempt();
        $before = self::state($adaptivequiz, $attempt);
        $otherbefore = self::state(...$other);
        $delete = function () use ($path, $adaptivequiz, $attempt): void {
            match ($path) {
                'attempt' => adaptivequiz_delete_attempt($adaptivequiz, $attempt),
                'reset' => adaptivequiz_reset_userdata(
                    (object) ['courseid' => $adaptivequiz->course, 'reset_adaptivequiz_all' => 1]
                ),
                'activity' => self::delete_module((int) $adaptivequiz->cmid, (int) $adaptivequiz->course),
            };
        };

        adaptivequizcatmodel_testcatmodel_fail_deletions(true);
        try {
            $delete();
            $this->fail('The deletion must fail');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('deletion failed on purpose', $e->debuginfo);
        } finally {
            adaptivequizcatmodel_testcatmodel_fail_deletions(false);
        }
        $this->assertSame($before, self::state($adaptivequiz, $attempt), 'nothing deleted');

        $delete();
        $after = self::state($adaptivequiz, $attempt);
        $this->assertFalse($after['attempt']);
        $this->assertFalse($after['usage']);
        $this->assertSame($path === 'activity' ? false : true, $after['activity']);
        $this->assertSame($otherbefore, self::state(...$other), 'the other activity is untouched');
    }
}
