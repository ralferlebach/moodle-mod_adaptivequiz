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

namespace mod_adaptivequiz\completion;

use advanced_testcase;
use completion_info;
use context_module;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Completing an attempt lets Moodle re-evaluate the completion rules; it does not decide itself (#121).
 *
 * The observer of attempt_completed set COMPLETION_COMPLETE for every completed attempt - also one
 * without a valid result, which the valid-result rule has to leave incomplete - and in v-3.0 it
 * ignored the valid-result rule altogether. The whole path is exercised here, from
 * adaptivequiz_complete_attempt() to the stored completion state.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\attempt_state_change_observers
 * @covers ::adaptivequiz_complete_attempt
 */
final class completion_reevaluation_test extends advanced_testcase {
    /**
     * The eight combinations of the issue: rules on the activity, validity of the result.
     *
     * @return array
     */
    public static function combinations(): array {
        return [
            'attempt completed, invalid' => [1, 0, 0, COMPLETION_COMPLETE],
            'attempt completed, valid' => [1, 0, 1, COMPLETION_COMPLETE],
            'valid result, invalid' => [0, 1, 0, COMPLETION_INCOMPLETE],
            'valid result, valid' => [0, 1, 1, COMPLETION_COMPLETE],
            'both rules, invalid' => [1, 1, 0, COMPLETION_INCOMPLETE],
            'both rules, valid' => [1, 1, 1, COMPLETION_COMPLETE],
            'no rule, invalid' => [0, 0, 0, COMPLETION_INCOMPLETE],
            'no rule, valid' => [0, 0, 1, COMPLETION_INCOMPLETE],
        ];
    }

    /**
     * Completing an attempt gives the state the rules decide, and no second attempt.
     *
     * @dataProvider combinations
     * @param int $attemptcompleted completionattemptcompleted
     * @param int $validresult completionvalidresult
     * @param int $resultvalid Whether the attempt has a valid result.
     * @param int $expected The expected completion state.
     */
    public function test_completion_follows_the_rules(
        int $attemptcompleted,
        int $validresult,
        int $resultvalid,
        int $expected
    ): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $CFG->enablecompletion = 1;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionattemptcompleted' => $attemptcompleted,
            'completionvalidresult' => $validresult,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id);
        $context = context_module::instance($cm->id);

        // The built-in algorithm decides validity from the result snapshot (issue #14): an attempt
        // with answered questions has a result, one without has none.
        $now = time();
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id, 'userid' => $student->id, 'uniqueid' => 88000 + $adaptivequiz->id,
            'attemptstate' => 'inprogress', 'attemptstopcriteria' => '', 'questionsattempted' => $resultvalid ? 3 : 0,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 0.2,
            'resultvalid' => $resultvalid, 'resultstatus' => $resultvalid ? 'valid' : 'invalid',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $this->setUser($student);

        adaptivequiz_complete_attempt(
            88000 + $adaptivequiz->id,
            $DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]),
            $context,
            (int) $student->id,
            '0.3',
            'done'
        );

        $data = (new completion_info($course))->get_data(get_fast_modinfo($course)->get_cm($cm->id), false, $student->id);
        $this->assertEquals($expected, (int) $data->completionstate, 'Completion does not follow the rules.');

        $custom = new custom_completion(get_fast_modinfo($course)->get_cm($cm->id), (int) $student->id);
        foreach (['completionattemptcompleted' => $attemptcompleted, 'completionvalidresult' => $validresult] as $rule => $on) {
            if ($on) {
                $this->assertEquals(
                    $custom->get_state($rule) === COMPLETION_COMPLETE,
                    $expected === COMPLETION_COMPLETE || $rule === 'completionattemptcompleted',
                    "The rule $rule and the stored state disagree."
                );
            }
        }

        $this->assertSame(
            1,
            $DB->count_records('adaptivequiz_attempt', ['instance' => $adaptivequiz->id]),
            'A new attempt appeared.'
        );
    }
}
