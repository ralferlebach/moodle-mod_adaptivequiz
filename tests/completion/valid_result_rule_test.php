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
use cm_info;
use mod_adaptivequiz\local\attempt\attempt_state;
use stdClass;

/**
 * The completion rule that asks for a valid result, not merely a completed attempt.
 *
 * Whether a result is valid is decided by the CAT model of the activity, which records it on the
 * attempt. The host only reads the flag - so a technically completed attempt with an unusable
 * result must not satisfy this rule.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(custom_completion::class)]
final class valid_result_rule_test extends advanced_testcase {
    /**
     * Creates an activity with one completed attempt of the given validity.
     *
     * @param int $resultvalid Whether the attempt produced a valid result.
     * @return array{0: cm_info, 1: stdClass}
     */
    private function activity_with_attempt(int $resultvalid): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $adaptivequiz = $generator->create_module('adaptivequiz', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionattemptcompleted' => 1,
            'completionvalidresult' => 1,
        ]);
        $user = $generator->create_user();

        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id,
            'userid' => $user->id,
            'uniqueid' => 0,
            'attemptstate' => attempt_state::COMPLETED,
            'attemptstopcriteria' => 'done',
            'questionsattempted' => 5,
            'difficultysum' => 25.0,
            'standarderror' => 0.4,
            'measure' => 0.6,
            'timecreated' => time() - 100,
            'timemodified' => time(),
            'timefinished' => time(),
            'resultvalid' => $resultvalid,
        ]);

        $cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id, $course->id, false, MUST_EXIST);

        return [cm_info::create($cm), $user];
    }

    /**
     * A completed attempt with a valid result satisfies the rule.
     */
    public function test_valid_result_completes_the_activity(): void {
        $this->resetAfterTest();
        [$cm, $user] = $this->activity_with_attempt(1);

        $completion = new custom_completion($cm, (int) $user->id);

        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_state('completionvalidresult'));
    }

    /**
     * A completed attempt without a valid result does not.
     */
    public function test_completed_attempt_without_a_valid_result_does_not_complete(): void {
        $this->resetAfterTest();
        [$cm, $user] = $this->activity_with_attempt(0);

        $completion = new custom_completion($cm, (int) $user->id);

        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_state('completionvalidresult'));

        // The older rule looks only at the state of the attempt and is satisfied.
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_state('completionattemptcompleted'));
    }

    /**
     * Both rules are offered and described.
     */
    public function test_both_rules_are_defined(): void {
        $this->resetAfterTest();
        [$cm, $user] = $this->activity_with_attempt(1);

        $completion = new custom_completion($cm, (int) $user->id);

        $this->assertSame(
            ['completionattemptcompleted', 'completionvalidresult'],
            custom_completion::get_defined_custom_rules()
        );
        $this->assertArrayHasKey('completionvalidresult', $completion->get_custom_rule_descriptions());
    }
}
