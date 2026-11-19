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

namespace mod_adaptivequiz;

use advanced_testcase;
use context_module;
use mod_adaptivequiz\local\attempt\attempt_state;
use stdClass;

/**
 * Deleting an attempt removes its question usage, also when the usage has no question attempts.
 *
 * On MySQL and MariaDB question_engine::delete_questions_usage_by_activity() deletes the usage through a join with
 * its question attempts, so a usage without question attempts stays behind; PostgreSQL deletes it. Every deletion
 * path therefore deletes the usage itself as well.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::adaptivequiz_delete_attempt
 * @covers ::adaptivequiz_reset_userdata
 * @covers ::adaptivequiz_delete_instance
 */
final class question_usage_deletion_test extends advanced_testcase {
    /**
     * An activity with an attempt whose question usage has no question attempts.
     *
     * @param stdClass $course
     * @return array{0: stdClass, 1: stdClass}
     */
    private function activity_with_attempt(stdClass $course): array {
        global $DB;
        $generator = $this->getDataGenerator();
        $adaptivequiz = $generator->create_module('adaptivequiz', ['course' => $course->id]);
        $user = $generator->create_user();
        $quba = \question_engine::make_questions_usage_by_activity(
            'mod_adaptivequiz',
            context_module::instance($adaptivequiz->cmid)
        );
        $quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($quba);
        $attemptid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id, 'userid' => $user->id, 'uniqueid' => $quba->get_id(),
            'attemptstate' => attempt_state::COMPLETED, 'attemptstopcriteria' => 'done', 'questionsattempted' => 0,
            'difficultysum' => 0.0, 'standarderror' => 0.5, 'measure' => 0.5, 'timecreated' => time() - 100,
            'timemodified' => time(),
        ]);
        return [$adaptivequiz, $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST)];
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
     * Each path removes the attempt and its usage, and nothing of another course.
     *
     * @dataProvider paths
     * @param string $path
     */
    public function test_the_usage_goes_with_the_attempt(string $path): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
        $this->resetAfterTest();
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $course = $this->getDataGenerator()->create_course();
        [$adaptivequiz, $attempt] = $this->activity_with_attempt($course);
        [, $other] = $this->activity_with_attempt($this->getDataGenerator()->create_course());
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));
        $this->assertSame(0, $DB->count_records('question_attempts', ['questionusageid' => $attempt->uniqueid]));

        match ($path) {
            'attempt' => adaptivequiz_delete_attempt($adaptivequiz, $attempt),
            'reset' => adaptivequiz_reset_userdata((object) ['courseid' => $course->id, 'reset_adaptivequiz_all' => 1]),
            'activity' => course_delete_module((int) $adaptivequiz->cmid),
        };

        $this->assertFalse($DB->record_exists('adaptivequiz_attempt', ['id' => $attempt->id]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]), 'usage left behind');
        $this->assertTrue($DB->record_exists('adaptivequiz_attempt', ['id' => $other->id]));
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $other->uniqueid]));
    }
}
