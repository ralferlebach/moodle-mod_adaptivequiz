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
 * Deleting an attempt tells the CAT model of the activity about it.
 *
 * The CAT model may hold results of its own for that attempt. Separate from
 * mod_adaptivequiz\delete_attempt_test because this one needs the fixture CAT model.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_delete_attempt')]
final class delete_attempt_catmodel_test extends advanced_testcase {
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
     * The CAT model of the attempt learns about the deletion.
     */
    public function test_catmodel_is_told_about_the_deletion(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $this->resetAfterTest();
        [$adaptivequiz, $attempt] = $this->create_activity_with_attempt('testcatmodel');

        adaptivequizcatmodel_testcatmodel_reset_deletions();

        adaptivequiz_delete_attempt($adaptivequiz, $attempt);

        $this->assertSame(
            [(int) $attempt->uniqueid],
            adaptivequizcatmodel_testcatmodel_deleted_attempts(),
            'The CAT model was not told about the deleted attempt.'
        );
    }

    /**
     * An activity without a CAT model tells nobody.
     */
    public function test_without_a_catmodel_nobody_is_told(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $this->resetAfterTest();
        [$adaptivequiz, $attempt] = $this->create_activity_with_attempt();

        adaptivequizcatmodel_testcatmodel_reset_deletions();

        adaptivequiz_delete_attempt($adaptivequiz, $attempt);

        $this->assertSame([], adaptivequizcatmodel_testcatmodel_deleted_attempts());
    }
}
