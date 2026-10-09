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

use adaptivequizcatmodel_testcatmodel\local\catmodel\result\result_provider as test_provider;
use advanced_testcase;
use completion_info;
use context_module;
use grade_item;
use mod_adaptivequiz\local\attempt\attempt_state;
use mod_adaptivequiz\local\result\attempt_result;
use mod_adaptivequiz\local\result\result_service;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * A CAT model's results reach the gradebook and completion only through the contract (issue #14).
 *
 * The test CAT model reports whatever the test registers; the host has to turn that into
 * percentages, grades, a pass mark and completion - and nothing else.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(result_service::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_get_user_grades')]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_complete_attempt')]
final class result_contract_test extends advanced_testcase {
    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var stdClass The participant. */
    private stdClass $user;

    /** @var int Next question usage id. */
    private int $uniqueid = 7000;

    /**
     * An activity with the test CAT model.
     *
     * @param array $settings
     */
    private function activity(array $settings = []): void {
        global $CFG;
        $CFG->enablecompletion = 1;
        test_provider::reset();
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $this->course->id, 'catmodel' => 'testcatmodel',
        ] + $settings);
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Runs an attempt to completion with the given result.
     *
     * @param attempt_result $result What the CAT model reports.
     * @return stdClass The completed attempt.
     */
    private function complete(attempt_result $result): stdClass {
        global $DB;

        $uniqueid = ++$this->uniqueid;
        test_provider::set_result($uniqueid, $result);
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => $uniqueid,
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 5,
            // The host's own measure would give a grade; it must not be used.
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 3.0,
            'timecreated' => time(), 'timemodified' => time(), 'timefinished' => 1000 + $uniqueid,
        ]);
        adaptivequiz_complete_attempt(
            $uniqueid,
            $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
            context_module::instance($this->adaptivequiz->cmid),
            (int) $this->user->id,
            '0.3',
            'done'
        );
        return $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid]);
    }

    /**
     * The grade the gradebook holds for the participant.
     *
     * @return float|null
     */
    private function grade(): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'adaptivequiz', $this->adaptivequiz->id, $this->user->id);
        $grade = $grades->items[0]->grades[$this->user->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Regrades the activity with the given grading method.
     *
     * @param int $grademethod
     */
    private function regrade(int $grademethod): void {
        global $DB;
        $DB->set_field('adaptivequiz', 'grademethod', $grademethod, ['id' => $this->adaptivequiz->id]);
        adaptivequiz_update_grades($DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]));
    }

    /**
     * Score 1.2 on -4..4 is 65 %, on the CAT model's range - not the host's levels.
     */
    public function test_valid_result_is_graded_on_its_own_range(): void {
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(attempt_result::valid(1.2, -4.0, 4.0));

        $this->assertEquals(1, $attempt->resultvalid);
        $this->assertEqualsWithDelta(65.0, (float) $attempt->resultpercent, 1e-5);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-5);
    }

    /**
     * Score 50 on 0..100 is 50 % - a lower bound of 0 is a bound like any other.
     */
    public function test_zero_lower_bound(): void {
        $this->resetAfterTest();
        $this->activity();

        $this->complete(attempt_result::valid(50.0, 0.0, 100.0));

        $this->assertEqualsWithDelta(50.0, $this->grade(), 1e-5);
    }

    /**
     * An invalid result gives no grade - null, not 0, and not the host's measure either.
     */
    public function test_invalid_result_gives_no_grade(): void {
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(attempt_result::invalid('fraction_all_correct'));

        $this->assertEquals(0, $attempt->resultvalid);
        $this->assertSame(attempt_result::STATUS_INVALID, $attempt->resultstatus);
        $this->assertSame('fraction_all_correct', $attempt->resultreason);
        $this->assertNull($attempt->resultscore);
        $this->assertNull($attempt->resultpercent);
        $this->assertNull($this->grade());
    }

    /**
     * A later invalid attempt does not wipe the grade of an earlier valid one - nor turn it into 0.
     */
    public function test_invalid_attempt_after_valid_one_keeps_the_valid_grade(): void {
        $this->resetAfterTest();
        $this->activity(['grademethod' => ADAPTIVEQUIZ_ATTEMPTLAST]);

        $this->complete(attempt_result::valid(0.0, -4.0, 4.0));
        $this->complete(attempt_result::invalid('not_measured'));

        $this->assertEqualsWithDelta(50.0, $this->grade(), 1e-5);
    }

    /**
     * A range with upper <= lower is an error: no grade, no pass/fail, no valid result.
     */
    public function test_invalid_range_gives_no_grade(): void {
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(attempt_result::valid(2.0, 2.0, 2.0));

        $this->assertEquals(0, $attempt->resultvalid);
        $this->assertSame(result_service::REASON_RANGE_INVALID, $attempt->resultreason);
        $this->assertNull($attempt->resultpercent);
        $this->assertNull($this->grade());
    }

    /**
     * A link function the host does not know is an error, not a quiet linear grade.
     */
    public function test_unknown_link_function_gives_no_grade(): void {
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(attempt_result::valid(1.0, -4.0, 4.0, 'logistic'));

        $this->assertDebuggingCalled();
        $this->assertEquals(0, $attempt->resultvalid);
        $this->assertSame(result_service::REASON_LINK_UNKNOWN, $attempt->resultreason);
        $this->assertNull($this->grade());
    }

    /**
     * First, last and highest choose among the valid attempts only; highest compares percentages.
     */
    public function test_grading_methods_consider_only_valid_attempts(): void {
        $this->resetAfterTest();
        $this->activity();

        $this->complete(attempt_result::invalid('not_measured'));
        $this->complete(attempt_result::valid(-0.8, -4.0, 4.0));   // 40 %.
        $this->complete(attempt_result::valid(0.0, -4.0, 4.0));    // 50 %.
        $this->complete(attempt_result::valid(30.0, 0.0, 100.0));  // 30 %, the highest score on another range.
        $this->complete(attempt_result::invalid('fraction_all_incorrect'));

        $this->regrade(ADAPTIVEQUIZ_ATTEMPTFIRST);
        $this->assertEqualsWithDelta(40.0, $this->grade(), 1e-5, 'First valid attempt.');

        $this->regrade(ADAPTIVEQUIZ_ATTEMPTLAST);
        $this->assertEqualsWithDelta(30.0, $this->grade(), 1e-5, 'Last valid attempt.');

        $this->regrade(ADAPTIVEQUIZ_GRADEHIGHEST);
        $this->assertEqualsWithDelta(50.0, $this->grade(), 1e-5, 'Highest percentage, not highest score.');
    }

    /**
     * A regrade of the whole activity clears the grade of someone left without a valid attempt.
     */
    public function test_regrade_of_all_users_clears_a_grade_without_valid_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(attempt_result::valid(1.2, -4.0, 4.0));
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-5);

        // The engine withdraws the result; the snapshot is taken again.
        test_provider::set_result((int) $attempt->uniqueid, attempt_result::invalid('recalibrated'));
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]);
        result_service::snapshot($adaptivequiz, $attempt, true);
        adaptivequiz_update_grades($adaptivequiz);

        $this->assertNull($this->grade());
    }

    /**
     * Deleting the only valid attempt leaves no grade, even with invalid attempts left.
     */
    public function test_deleting_the_valid_attempt_removes_the_grade(): void {
        $this->resetAfterTest();
        $this->activity();

        $valid = $this->complete(attempt_result::valid(1.2, -4.0, 4.0));
        $this->complete(attempt_result::invalid('not_measured'));
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-5);

        global $DB;
        adaptivequiz_delete_attempt($DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]), $valid);

        $this->assertNull($this->grade());
    }

    /**
     * A CAT model without a result source: no grade item value, no grade from the host's measure.
     */
    public function test_catmodel_without_provider_has_no_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();

        // A CAT model that is not (or no longer) installed has no result provider.
        $DB->set_field('adaptivequiz', 'catmodel', 'notinstalled', ['id' => $this->adaptivequiz->id]);
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]);
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => 6999,
            'attemptstate' => attempt_state::COMPLETED, 'attemptstopcriteria' => '', 'questionsattempted' => 5,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 3.0, 'resultvalid' => 1,
            'timecreated' => time(), 'timemodified' => time(), 'timefinished' => time(),
        ]);

        result_service::snapshot_missing($adaptivequiz);
        adaptivequiz_update_grades($adaptivequiz);

        $attempt = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => 6999]);
        $this->assertSame(result_service::REASON_NO_PROVIDER, $attempt->resultreason);
        $this->assertNull($attempt->resultpercent);
        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'adaptivequiz', 'iteminstance' => $adaptivequiz->id]);
        $this->assertEquals(GRADE_TYPE_NONE, $item->gradetype);
    }

    /**
     * Gradebook and completion share one truth: a valid result at or above the pass score passes,
     * a valid one below fails, an invalid one neither passes nor fails.
     */
    public function test_pass_grade_completion_follows_the_snapshot(): void {
        $this->resetAfterTest();
        $this->activity([
            'passscore' => 1.0,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
        ]);
        $item = grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'adaptivequiz', 'iteminstance' => $this->adaptivequiz->id]);
        $this->assertEqualsWithDelta(62.5, (float) $item->gradepass, 1e-5, 'Pass score 1 on -4..4.');

        $state = function (): int {
            $cm = get_fast_modinfo($this->course)->get_cm($this->adaptivequiz->cmid);
            return (int) (new completion_info($this->course))->get_data($cm, false, $this->user->id)->completionstate;
        };

        $this->complete(attempt_result::invalid('not_measured'));
        $this->assertSame(COMPLETION_INCOMPLETE, $state(), 'An invalid result neither passes nor fails.');

        $this->complete(attempt_result::valid(0.0, -4.0, 4.0));
        $this->assertSame(COMPLETION_COMPLETE_FAIL, $state(), 'Valid, below the pass score.');

        $this->complete(attempt_result::valid(1.0, -4.0, 4.0));
        $this->assertSame(COMPLETION_COMPLETE_PASS, $state(), 'Valid, at the pass score.');
    }

    /**
     * The valid-result rule reads the same snapshot as the gradebook.
     */
    public function test_valid_result_rule_follows_the_snapshot(): void {
        $this->resetAfterTest();
        $this->activity(['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionvalidresult' => 1]);
        $cm = fn() => get_fast_modinfo($this->course)->get_cm($this->adaptivequiz->cmid);

        $this->complete(attempt_result::valid(2.0, 2.0, 2.0));
        $this->assertSame(
            COMPLETION_INCOMPLETE,
            (new \mod_adaptivequiz\completion\custom_completion($cm(), (int) $this->user->id))->get_state('completionvalidresult'),
            'A result without a usable range is not valid for completion either.'
        );

        $this->complete(attempt_result::valid(1.0, -4.0, 4.0));
        $this->assertSame(
            COMPLETION_COMPLETE,
            (new \mod_adaptivequiz\completion\custom_completion($cm(), (int) $this->user->id))->get_state('completionvalidresult')
        );
    }
}
