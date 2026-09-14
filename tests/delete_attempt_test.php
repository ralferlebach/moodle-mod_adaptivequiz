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
 * Deleting an attempt removes the attempt and its answers.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_delete_attempt')]
final class delete_attempt_test extends advanced_testcase {
    /**
     * Creates an activity with one completed attempt and returns both records.
     *
     * @param string $catmodel Name of the CAT model to configure, empty for the built-in algorithm.
     * @return array{0: stdClass, 1: stdClass}
     */
    private function create_activity_with_attempt(string $catmodel = ''): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $adaptivequiz = $generator->create_module('adaptivequiz', [
            'course' => $course->id,
            'catmodel' => $catmodel,
        ]);
        $user = $generator->create_user();

        $quba = \question_engine::make_questions_usage_by_activity(
            'mod_adaptivequiz',
            context_module::instance($adaptivequiz->cmid)
        );
        $quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($quba);

        $attemptid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id,
            'userid' => $user->id,
            'uniqueid' => $quba->get_id(),
            'attemptstate' => attempt_state::COMPLETED,
            'attemptstopcriteria' => 'done',
            'questionsattempted' => 3,
            'difficultysum' => 15.0,
            'standarderror' => 0.5,
            'measure' => 0.5,
            'timecreated' => time() - 100,
            'timemodified' => time(),
            'timefinished' => time(),
        ]);

        return [$adaptivequiz, $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST)];
    }

    /**
     * The attempt and its question usage are gone afterwards.
     */
    public function test_attempt_and_its_answers_are_removed(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $this->resetAfterTest();
        [$adaptivequiz, $attempt] = $this->create_activity_with_attempt();

        adaptivequiz_delete_attempt($adaptivequiz, $attempt);

        $this->assertFalse($DB->record_exists('adaptivequiz_attempt', ['id' => $attempt->id]));
        $this->assertFalse($DB->record_exists('question_usages', ['id' => $attempt->uniqueid]));
    }

    /**
     * Attempts of other activities are untouched.
     */
    public function test_only_the_given_attempt_is_removed(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $this->resetAfterTest();
        [$adaptivequiz, $attempt] = $this->create_activity_with_attempt();
        [, $other] = $this->create_activity_with_attempt();

        adaptivequiz_delete_attempt($adaptivequiz, $attempt);

        $this->assertTrue($DB->record_exists('adaptivequiz_attempt', ['id' => $other->id]));
    }
}
