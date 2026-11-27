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

namespace mod_adaptivequiz\local\attempt;

use advanced_testcase;
use context_module;
use stdClass;

/**
 * Reset and deletion remove the attempts they read, each with its question usage (#27 AK03, DoD01).
 *
 * CLARA, 27.11.2026: an attempt row was gone while its question usage and its CAT attempt were left. A reset read the
 * attempts of an activity, removed their usages and then the rows of the whole activity - also the row of an attempt
 * started in between, whose usage and CAT data stayed. The start in between is simulated deterministically: the
 * attempt is created after the read and before the removal.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers ::adaptivequiz_reset_attempts
 * @covers ::adaptivequiz_delete_instance_data
 */
final class lifecycle_generation_test extends advanced_testcase {
    /**
     * An activity without a CAT model.
     *
     * @return stdClass
     */
    private function activity(): stdClass {
        $course = $this->getDataGenerator()->create_course();
        return $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
    }

    /**
     * An attempt in progress with a question usage of its own.
     *
     * @param stdClass $adaptivequiz
     * @return stdClass the attempt row
     */
    private function attempt(stdClass $adaptivequiz): stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $context = context_module::instance($adaptivequiz->cmid);
        $quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($quba);
        $id = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id, 'userid' => $user->id, 'uniqueid' => $quba->get_id(),
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 0,
            'difficultysum' => 0, 'standarderror' => 999, 'measure' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        return $DB->get_record('adaptivequiz_attempt', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Whether the row and the usage of an attempt exist.
     *
     * @param stdClass $attempt
     * @return bool[] row, usage
     */
    private function exists(stdClass $attempt): array {
        global $DB;
        return [
            $DB->record_exists('adaptivequiz_attempt', ['id' => $attempt->id]),
            $DB->record_exists('question_usages', ['id' => $attempt->uniqueid]),
        ];
    }

    /**
     * A reset removes the attempts it read; an attempt started after the read keeps its row with its usage.
     * RED before: its row was removed, its usage stayed without it.
     */
    public function test_a_reset_leaves_an_attempt_started_after_its_read(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
        $this->resetAfterTest();
        $adaptivequiz = $this->activity();
        $first = $this->attempt($adaptivequiz);
        $second = $this->attempt($adaptivequiz);
        $read = $DB->get_records('adaptivequiz_attempt', ['instance' => $adaptivequiz->id]);
        // A start in between: after the read of the reset, before its removal.
        $late = $this->attempt($adaptivequiz);

        adaptivequiz_reset_attempts($adaptivequiz, $read);

        $this->assertSame([false, false], $this->exists($first));
        $this->assertSame([false, false], $this->exists($second));
        $this->assertSame([true, true], $this->exists($late), 'The attempt started meanwhile keeps its row and its usage.');
    }

    /**
     * Deleting the activity removes each attempt with its usage, also one started while it runs: no usage without its
     * row, and no row left.
     */
    public function test_deleting_the_activity_removes_each_attempt_with_its_usage(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
        $this->resetAfterTest();
        $adaptivequiz = $this->activity();
        $attempts = [$this->attempt($adaptivequiz), $this->attempt($adaptivequiz)];

        adaptivequiz_delete_instance_data($adaptivequiz);

        foreach ($attempts as $attempt) {
            $this->assertSame([false, false], $this->exists($attempt));
        }
    }
}
