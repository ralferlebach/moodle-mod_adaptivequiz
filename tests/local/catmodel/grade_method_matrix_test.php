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
use context_module;
use mod_adaptivequiz\local\attempt\attempt_state;
use mod_adaptivequiz\local\result\attempt_result;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Issue #25 DoD5 (re-audit of 20.11.2026): the grading methods over several attempts of one person, as a matrix.
 *
 * Uses the fixture CAT model (catmodel/testcatmodel), like result_contract_test - not part of the release package.
 *
 * Every sequence of attempts is played once per grading method - graded live, attempt by attempt, as the host does it
 * when an attempt is completed - and the grade is then regraded from scratch with each method. The rules (issue #14):
 * only attempts with a valid result count; first and last follow the order the attempts were finished in (then their
 * id); highest compares percentages, and of two equal ones the earlier attempt counts; without a valid attempt there
 * is no grade - never 0.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_get_user_grades')]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_update_grades')]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_complete_attempt')]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_attempt_counts_before')]
final class grade_method_matrix_test extends advanced_testcase {
    /** @var stdClass */
    private stdClass $course;

    /** @var stdClass */
    private stdClass $adaptivequiz;

    /** @var stdClass */
    private stdClass $user;

    /** @var int Next question usage id. */
    private int $uniqueid = 9000;

    /**
     * Sequences of attempts: [percentage or null for an invalid result, finished at], and the grade per method.
     *
     * @return array
     */
    public static function sequences(): array {
        $h = ADAPTIVEQUIZ_GRADEHIGHEST;
        $f = ADAPTIVEQUIZ_ATTEMPTFIRST;
        $l = ADAPTIVEQUIZ_ATTEMPTLAST;
        return [
            'three valid attempts' => [[[40, 1000], [70, 2000], [55, 3000]], [$h => 70, $f => 40, $l => 55]],
            'one valid between invalid ones' => [[[null, 1000], [60, 2000], [null, 3000]], [$h => 60, $f => 60, $l => 60]],
            'only invalid attempts' => [[[null, 1000], [null, 2000]], [$h => null, $f => null, $l => null]],
            'a tie of the highest' => [[[50, 1000], [80, 2000], [80, 3000], [20, 4000]], [$h => 80, $f => 50, $l => 20]],
            'finished in another order than started' => [
                [[10, 3000], [90, 1000], [50, 2000]], [$h => 90, $f => 90, $l => 10],
            ],
            'an invalid attempt after a valid one' => [[[65, 1000], [null, 2000]], [$h => 65, $f => 65, $l => 65]],
            'the boundaries 0 % and 100 %' => [[[0, 1000], [100, 2000], [0, 3000]], [$h => 100, $f => 0, $l => 0]],
        ];
    }

    /**
     * An activity with the test CAT model and the given grading method.
     *
     * @param int $grademethod
     * @param array $settings Further settings of the activity (completion); they switch completion on for the site.
     */
    private function activity(int $grademethod, array $settings = []): void {
        global $CFG;
        test_provider::reset();
        if ($settings) {
            $CFG->enablecompletion = 1;
        }
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => $settings ? 1 : 0]);
        $this->adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', $settings + [
            'course' => $this->course->id, 'catmodel' => 'testcatmodel', 'grademethod' => $grademethod,
        ]);
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Completes an attempt with the given result, as the host does at the end of an attempt.
     *
     * @param float|null $percent The percentage of a valid result on 0..100, null for an invalid one.
     * @param int $finished
     * @param string|null $stopcode Why the attempt ended (stop_reason), null for none.
     * @return int The question usage id of the attempt.
     */
    private function complete(?float $percent, int $finished, ?string $stopcode = null): int {
        global $DB;
        $uniqueid = ++$this->uniqueid;
        test_provider::set_result(
            $uniqueid,
            $percent === null ? attempt_result::invalid('not_measured') : attempt_result::valid($percent, 0.0, 100.0)
        );
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => $uniqueid,
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 5,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 3.0,
            'timecreated' => time(), 'timemodified' => time(), 'timefinished' => $finished,
        ]);
        adaptivequiz_complete_attempt(
            $uniqueid,
            $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
            context_module::instance($this->adaptivequiz->cmid),
            (int) $this->user->id,
            '0.3',
            'done',
            $stopcode
        );
        // The order of the attempts is the order they were finished in, whatever time the completion stamped.
        $DB->set_field('adaptivequiz_attempt', 'timefinished', $finished, ['uniqueid' => $uniqueid]);
        return $uniqueid;
    }

    /**
     * The grade of the gradebook.
     *
     * @return float|null
     */
    private function grade(): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'adaptivequiz', $this->adaptivequiz->id, $this->user->id);
        $grade = $grades->items[0]->grades[$this->user->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Each method, live and regraded, over the sequence.
     *
     * @param array $attempts
     * @param array $expected Grade per method (the activity's maximum grade is 100).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sequences')]
    public function test_every_grading_method_over_several_attempts(array $attempts, array $expected): void {
        global $DB;
        $this->resetAfterTest();
        foreach ($expected as $method => $grade) {
            $this->activity((int) $method);
            foreach ($attempts as [$percent, $finished]) {
                $this->complete($percent === null ? null : (float) $percent, $finished);
            }
            $label = "method $method";
            if ($grade === null) {
                $this->assertNull($this->grade(), "$label, live: no valid attempt, no grade - not 0.");
            } else {
                $this->assertEqualsWithDelta((float) $grade, $this->grade(), 1e-5, "$label, live.");
            }
            // Regraded from scratch with every method: the same rule, whatever method graded live.
            foreach ($expected as $other => $othergrade) {
                $DB->set_field('adaptivequiz', 'grademethod', $other, ['id' => $this->adaptivequiz->id]);
                adaptivequiz_update_grades($DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]));
                if ($othergrade === null) {
                    $this->assertNull($this->grade(), "$label, regraded with $other.");
                } else {
                    $this->assertEqualsWithDelta((float) $othergrade, $this->grade(), 1e-5, "$label, regraded with $other.");
                }
            }
        }
    }

    /**
     * The state of a completion rule of the activity for the person.
     *
     * @param string|null $rule A custom rule, null for the state of the activity (the gradebook rules).
     * @return int
     */
    private function completion(?string $rule = null): int {
        $cm = get_fast_modinfo($this->course->id)->get_cm($this->adaptivequiz->cmid);
        if ($rule !== null) {
            return (new \mod_adaptivequiz\completion\custom_completion($cm, (int) $this->user->id))->get_state($rule);
        }
        return (int) (new \completion_info(get_course($this->course->id)))->get_data($cm, false, $this->user->id)
            ->completionstate;
    }

    /**
     * Re-audit of 22.11.2026 (DoD5): the same attempt finalised again changes nothing - its grade, its place in the
     * order, its end time, its stop code and its result stay; the completion by id says it was completed already.
     *
     * The callers keep a second completion from running at all (cat_session: only a running attempt; closeattempt.php:
     * an error for a completed one; concurrent_answer_test: the ending answer twice completes once). The layer below
     * did not: called again, it overwrote the message of the end and told the CAT model, the gradebook and the log once
     * more. It leaves a completed attempt as it is now, as the completion by id always did.
     */
    public function test_finalising_an_attempt_again_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $expected = [ADAPTIVEQUIZ_GRADEHIGHEST => 70, ADAPTIVEQUIZ_ATTEMPTFIRST => 40, ADAPTIVEQUIZ_ATTEMPTLAST => 55];
        foreach ($expected as $method => $grade) {
            $this->activity((int) $method);
            $this->complete(40.0, 1000, 'maximumquestions');
            $middle = $this->complete(70.0, 2000, 'targetprecision');
            $this->complete(55.0, 3000, 'maximumquestions');
            $fields = 'uniqueid, attemptstate, attemptstopcriteria, timefinished, stopcode, resultvalid, resultpercent';
            $sink = $this->redirectEvents();
            $before = $DB->get_records('adaptivequiz_attempt', ['instance' => $this->adaptivequiz->id], 'uniqueid', $fields);
            $this->assertEqualsWithDelta((float) $grade, $this->grade(), 1e-5, "method $method, before.");

            // Finalised again, with another message and stop code - a second request, a recovery run.
            adaptivequiz_complete_attempt(
                $middle,
                $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
                context_module::instance($this->adaptivequiz->cmid),
                (int) $this->user->id,
                '0.3',
                'again',
                'error'
            );
            $after = $DB->get_records('adaptivequiz_attempt', ['instance' => $this->adaptivequiz->id], 'uniqueid', $fields);
            $this->assertEquals($before, $after, "method $method: the attempts as they were, the message included.");
            $completed = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_adaptivequiz\event\attempt_completed);
            $this->assertCount(0, $completed, "method $method: no second completion event.");
            $sink->close();
            $this->assertEqualsWithDelta((float) $grade, $this->grade(), 1e-5, "method $method, finalised again.");
            $id = (int) $DB->get_field('adaptivequiz_attempt', 'id', ['uniqueid' => $middle]);
            $this->assertFalse(adaptivequiz_complete_attempt_by_id(
                $id,
                $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
                context_module::instance($this->adaptivequiz->cmid),
                'again'
            ), 'Completed already: completing it by id does nothing.');
            $this->assertEqualsWithDelta((float) $grade, $this->grade(), 1e-5, "method $method, completed by id.");
        }
    }

    /**
     * Sequences with a technical stop: [percentage or null, finished at, stop code], and the grade per method.
     *
     * @return array
     */
    public static function technical_stops(): array {
        $h = ADAPTIVEQUIZ_GRADEHIGHEST;
        $f = ADAPTIVEQUIZ_ATTEMPTFIRST;
        $l = ADAPTIVEQUIZ_ATTEMPTLAST;
        return [
            'technical stop before a valid measurement, then after one' => [
                [[40, 1000, 'maximumquestions'], [null, 2000, 'error'], [70, 3000, 'error']],
                [$h => 70, $f => 40, $l => 70],
            ],
            'technical stop after a valid measurement is the first valid attempt' => [
                [[null, 1000, 'error'], [60, 2000, 'error'], [30, 3000, 'targetprecision']],
                [$h => 60, $f => 60, $l => 30],
            ],
            'technical stop before a valid measurement is the last attempt' => [
                [[50, 1000, 'targetprecision'], [null, 2000, 'error']],
                [$h => 50, $f => 50, $l => 50],
            ],
            'only technical stops before a measurement' => [
                [[null, 1000, 'error'], [null, 2000, 'error']],
                [$h => null, $f => null, $l => null],
            ],
        ];
    }

    /**
     * Re-audit of 22.11.2026 (DoD5): a technical stop counts by its result, not by its stop code. Before a valid
     * measurement the CAT model reports no valid result, and the attempt does not count; after one, the measurement
     * is valid and counts like any other. The stop code stays the technical one (issue #23 keeps them apart).
     *
     * @param array $attempts
     * @param array $expected Grade per method.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('technical_stops')]
    public function test_a_technical_stop_counts_by_its_result(array $attempts, array $expected): void {
        global $DB;
        $this->resetAfterTest();
        foreach ($expected as $method => $grade) {
            $this->activity((int) $method, [
                'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionvalidresult' => 1,
            ]);
            foreach ($attempts as [$percent, $finished, $stopcode]) {
                $uniqueid = $this->complete($percent === null ? null : (float) $percent, $finished, $stopcode);
                $this->assertSame($stopcode, $DB->get_field('adaptivequiz_attempt', 'stopcode', ['uniqueid' => $uniqueid]));
            }
            if ($grade === null) {
                $this->assertNull($this->grade(), "method $method: no valid measurement, no grade.");
                $this->assertSame(COMPLETION_INCOMPLETE, $this->completion('completionvalidresult'));
            } else {
                $this->assertEqualsWithDelta((float) $grade, $this->grade(), 1e-5, "method $method.");
                $this->assertSame(COMPLETION_COMPLETE, $this->completion('completionvalidresult'));
            }
        }
    }

    /**
     * Sequences for the completion: [percentage or null, finished at], and per method the state of "receive a
     * passing grade" (pass score 50 %).
     *
     * @return array
     */
    public static function completions(): array {
        $h = ADAPTIVEQUIZ_GRADEHIGHEST;
        $f = ADAPTIVEQUIZ_ATTEMPTFIRST;
        $l = ADAPTIVEQUIZ_ATTEMPTLAST;
        $pass = COMPLETION_COMPLETE_PASS;
        $fail = COMPLETION_COMPLETE_FAIL;
        return [
            'passed, then failed' => [[[70, 1000], [30, 2000]], [$h => $pass, $f => $pass, $l => $fail]],
            'failed, then passed' => [[[30, 1000], [70, 2000]], [$h => $pass, $f => $fail, $l => $pass]],
            'failed, then invalid' => [[[30, 1000], [null, 2000]], [$h => $fail, $f => $fail, $l => $fail]],
            'exactly at the pass score last' => [[[49, 1000], [50, 2000]], [$h => $pass, $f => $fail, $l => $pass]],
            'only invalid attempts' => [
                [[null, 1000], [null, 2000]],
                [$h => COMPLETION_INCOMPLETE, $f => COMPLETION_INCOMPLETE, $l => COMPLETION_INCOMPLETE],
            ],
        ];
    }

    /**
     * Re-audit of 22.11.2026 (DoD5): gradebook and completion over several attempts share the grade of the grading
     * method - live and after a regrade with another method. "Receive a passing grade" follows the grade the method
     * picks; "valid result" holds once any attempt had one; "attempt completed" once any attempt was completed.
     *
     * @param array $attempts
     * @param array $expected Completion state of the activity per method.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('completions')]
    public function test_completion_over_several_attempts(array $attempts, array $expected): void {
        global $DB;
        $this->resetAfterTest();
        set_config('resultdefinition', json_encode(['lower' => 0.0, 'upper' => 100.0]), 'adaptivequizcatmodel_testcatmodel');
        $anyvalid = (bool) array_filter($attempts, fn($a) => $a[0] !== null);
        foreach ($expected as $method => $state) {
            $this->activity((int) $method, [
                'passscore' => 50.0, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1,
                'completionpassgrade' => 1, 'completionvalidresult' => 1, 'completionattemptcompleted' => 1,
            ]);
            foreach ($attempts as [$percent, $finished]) {
                $this->complete($percent === null ? null : (float) $percent, $finished);
            }
            $this->assertSame($state, $this->completion(), "method $method, live.");
            $this->assertSame($anyvalid ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE, $this->completion('completionvalidresult'));
            $this->assertSame(COMPLETION_COMPLETE, $this->completion('completionattemptcompleted'));
            // Regraded with every method: the completion follows the grade the method picks now.
            foreach ($expected as $other => $otherstate) {
                $DB->set_field('adaptivequiz', 'grademethod', $other, ['id' => $this->adaptivequiz->id]);
                adaptivequiz_update_grades($DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]));
                $this->assertSame($otherstate, $this->completion(), "method $method, regraded with $other.");
            }
        }
    }
}
