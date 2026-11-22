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

namespace mod_adaptivequiz\local\result;

use advanced_testcase;
use context_module;
use mod_adaptivequiz\local\attempt\attempt_state;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Issue #25 DoD5 (re-audit of 20.11.2026): the grading methods over several attempts of one person, as a matrix.
 *
 * On this line the host's own measure gives the result (no CAT model): a measure in logits, a valid result from five
 * answered questions on; an attempt without answered questions has no valid result. The expected grade is the
 * percentage the result snapshot of the named attempt holds.
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
 * @covers ::adaptivequiz_get_user_grades
 * @covers ::adaptivequiz_update_grades
 * @covers ::adaptivequiz_complete_attempt
 * @covers ::adaptivequiz_attempt_counts_before
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
     * Sequences of attempts: [measure in logits or null for an invalid result, finished at], and per method the index
     * of the attempt whose percentage is the grade (null: no grade).
     *
     * @return array
     */
    public static function sequences(): array {
        $h = ADAPTIVEQUIZ_GRADEHIGHEST;
        $f = ADAPTIVEQUIZ_ATTEMPTFIRST;
        $l = ADAPTIVEQUIZ_ATTEMPTLAST;
        return [
            'three valid attempts' => [[[-1.0, 1000], [1.5, 2000], [0.4, 3000]], [$h => 1, $f => 0, $l => 2]],
            'one valid between invalid ones' => [[[null, 1000], [0.8, 2000], [null, 3000]], [$h => 1, $f => 1, $l => 1]],
            'only invalid attempts' => [[[null, 1000], [null, 2000]], [$h => null, $f => null, $l => null]],
            'a tie of the highest' => [
                [[0.0, 1000], [2.0, 2000], [2.0, 3000], [-2.0, 4000]], [$h => 1, $f => 0, $l => 3],
            ],
            'finished in another order than started' => [
                [[-2.0, 3000], [2.5, 1000], [0.0, 2000]], [$h => 1, $f => 1, $l => 0],
            ],
            'an invalid attempt after a valid one' => [[[0.6, 1000], [null, 2000]], [$h => 0, $f => 0, $l => 0]],
        ];
    }

    /**
     * An activity with the given grading method.
     *
     * @param int $grademethod
     * @param array $settings Further settings of the activity (completion); they switch completion on for the site.
     */
    private function activity(int $grademethod, array $settings = []): void {
        global $CFG;
        if ($settings) {
            $CFG->enablecompletion = 1;
        }
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => $settings ? 1 : 0]);
        $this->adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', $settings + [
            'course' => $this->course->id, 'lowestlevel' => 1, 'highestlevel' => 11, 'grademethod' => $grademethod,
        ]);
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Completes an attempt with the given result, as the host does at the end of an attempt.
     *
     * @param float|null $measure The measure in logits, null for an attempt without a valid result.
     * @param int $finished
     * @param string|null $stopcode Why the attempt ended (stop_reason), null for none.
     * @return int adaptivequiz_attempt.id
     */
    private function complete(?float $measure, int $finished, ?string $stopcode = null): int {
        global $DB;
        $uniqueid = ++$this->uniqueid;
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => $uniqueid,
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '',
            'questionsattempted' => $measure === null ? 0 : 5,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => $measure ?? 0.0,
            'timecreated' => time(), 'timemodified' => time(), 'timefinished' => $finished,
        ]);
        adaptivequiz_complete_attempt(
            $uniqueid,
            $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
            context_module::instance($this->adaptivequiz->cmid),
            (int) $this->user->id,
            'done',
            $stopcode
        );
        // The order of the attempts is the order they were finished in, whatever time the completion stamped.
        $DB->set_field('adaptivequiz_attempt', 'timefinished', $finished, ['uniqueid' => $uniqueid]);
        return (int) $DB->get_field('adaptivequiz_attempt', 'id', ['uniqueid' => $uniqueid]);
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
     * @param array $expected Per method the index of the attempt that gives the grade (the maximum grade is 100).
     * @dataProvider sequences
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sequences')]
    public function test_every_grading_method_over_several_attempts(array $attempts, array $expected): void {
        global $DB;
        $this->resetAfterTest();
        foreach ($expected as $method => $grade) {
            $this->activity((int) $method);
            $percents = [];
            foreach ($attempts as [$measure, $finished]) {
                $id = $this->complete($measure, $finished);
                $snapshot = $DB->get_record('adaptivequiz_attempt', ['id' => $id], 'resultvalid, resultpercent');
                $this->assertSame($measure === null ? 0 : 1, (int) $snapshot->resultvalid, 'The result as intended.');
                $percents[] = $measure === null ? null : (float) $snapshot->resultpercent;
            }
            $grades = array_map(fn($index) => $index === null ? null : $percents[$index], $expected);
            $grade = $grades[$method];
            $label = "method $method";
            if ($grade === null) {
                $this->assertNull($this->grade(), "$label, live: no valid attempt, no grade - not 0.");
            } else {
                $this->assertEqualsWithDelta((float) $grade, $this->grade(), 1e-5, "$label, live.");
            }
            // Regraded from scratch with every method: the same rule, whatever method graded live.
            foreach ($grades as $other => $othergrade) {
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
     * order, its end time, its message, its stop code and its result stay; no second completion event; the completion
     * by id says it was completed already.
     *
     * The callers keep a second completion from running at all; the layer below did not: called again, it overwrote
     * the message of the end and told the gradebook and the log once more. It leaves a completed attempt as it is now,
     * as the completion by id always did.
     */
    public function test_finalising_an_attempt_again_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        foreach ([ADAPTIVEQUIZ_GRADEHIGHEST, ADAPTIVEQUIZ_ATTEMPTFIRST, ADAPTIVEQUIZ_ATTEMPTLAST] as $method) {
            $this->activity($method);
            $this->complete(-1.0, 1000, 'maximumquestions');
            $middle = $this->complete(1.5, 2000, 'targetprecision');
            $this->complete(0.4, 3000, 'maximumquestions');
            $fields = 'id, attemptstate, attemptstopcriteria, timefinished, stopcode, resultvalid, resultpercent';
            $before = $DB->get_records('adaptivequiz_attempt', ['instance' => $this->adaptivequiz->id], 'id', $fields);
            $grade = $this->grade();
            $this->assertNotNull($grade);
            $sink = $this->redirectEvents();
            adaptivequiz_complete_attempt(
                (int) $DB->get_field('adaptivequiz_attempt', 'uniqueid', ['id' => $middle]),
                $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
                context_module::instance($this->adaptivequiz->cmid),
                (int) $this->user->id,
                'again',
                'error'
            );
            $after = $DB->get_records('adaptivequiz_attempt', ['instance' => $this->adaptivequiz->id], 'id', $fields);
            $this->assertEquals($before, $after, "method $method: the attempts as they were, the message included.");
            $completed = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_adaptivequiz\event\attempt_completed);
            $this->assertCount(0, $completed, "method $method: no second completion event.");
            $sink->close();
            $this->assertEqualsWithDelta($grade, $this->grade(), 1e-5, "method $method, finalised again.");
            $this->assertFalse(adaptivequiz_complete_attempt_by_id(
                $middle,
                $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
                context_module::instance($this->adaptivequiz->cmid),
                'again'
            ), 'Completed already: completing it by id does nothing.');
            $this->assertEqualsWithDelta($grade, $this->grade(), 1e-5, "method $method, completed by id.");
        }
    }

    /**
     * Sequences with a technical stop: [measure or null, finished at, stop code], and per method the index of the
     * attempt that gives the grade.
     *
     * @return array
     */
    public static function technical_stops(): array {
        $h = ADAPTIVEQUIZ_GRADEHIGHEST;
        $f = ADAPTIVEQUIZ_ATTEMPTFIRST;
        $l = ADAPTIVEQUIZ_ATTEMPTLAST;
        return [
            'technical stop before a valid measurement, then after one' => [
                [[-1.0, 1000, 'maximumquestions'], [null, 2000, 'error'], [1.5, 3000, 'error']],
                [$h => 2, $f => 0, $l => 2],
            ],
            'technical stop after a valid measurement is the first valid attempt' => [
                [[null, 1000, 'error'], [0.8, 2000, 'error'], [-0.5, 3000, 'targetprecision']],
                [$h => 1, $f => 1, $l => 2],
            ],
            'technical stop before a valid measurement is the last attempt' => [
                [[0.2, 1000, 'targetprecision'], [null, 2000, 'error']],
                [$h => 0, $f => 0, $l => 0],
            ],
            'only technical stops before a measurement' => [
                [[null, 1000, 'error'], [null, 2000, 'error']],
                [$h => null, $f => null, $l => null],
            ],
        ];
    }

    /**
     * Re-audit of 22.11.2026 (DoD5): a technical stop counts by its result, not by its stop code. Before a valid
     * measurement (no answered question) the result is invalid, and the attempt does not count; after one, the
     * measurement is valid and counts like any other. The stop code stays the technical one (issue #23).
     *
     * @param array $attempts
     * @param array $expected Per method the index of the attempt that gives the grade.
     * @dataProvider technical_stops
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('technical_stops')]
    public function test_a_technical_stop_counts_by_its_result(array $attempts, array $expected): void {
        global $DB;
        $this->resetAfterTest();
        foreach ($expected as $method => $index) {
            $this->activity((int) $method, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionvalidresult' => 1]);
            $percents = [];
            foreach ($attempts as [$measure, $finished, $stopcode]) {
                $id = $this->complete($measure, $finished, $stopcode);
                $record = $DB->get_record('adaptivequiz_attempt', ['id' => $id], 'stopcode, resultvalid, resultpercent');
                $this->assertSame($stopcode, $record->stopcode);
                $this->assertSame($measure === null ? 0 : 1, (int) $record->resultvalid);
                $percents[] = $measure === null ? null : (float) $record->resultpercent;
            }
            if ($index === null) {
                $this->assertNull($this->grade(), "method $method: no valid measurement, no grade.");
                $this->assertSame(COMPLETION_INCOMPLETE, $this->completion('completionvalidresult'));
            } else {
                $this->assertEqualsWithDelta($percents[$index], $this->grade(), 1e-5, "method $method.");
                $this->assertSame(COMPLETION_COMPLETE, $this->completion('completionvalidresult'));
            }
        }
    }

    /**
     * Sequences for the completion: [measure or null, finished at], and per method the state of "receive a passing
     * grade" (pass score 6 on the levels 1..11: 50 %, a measure of 0 logits).
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
            'passed, then failed' => [[[2.0, 1000], [-2.0, 2000]], [$h => $pass, $f => $pass, $l => $fail]],
            'failed, then passed' => [[[-2.0, 1000], [2.0, 2000]], [$h => $pass, $f => $fail, $l => $pass]],
            'failed, then invalid' => [[[-2.0, 1000], [null, 2000]], [$h => $fail, $f => $fail, $l => $fail]],
            'exactly at the pass score last' => [[[-0.5, 1000], [0.0, 2000]], [$h => $pass, $f => $fail, $l => $pass]],
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
     * @dataProvider completions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('completions')]
    public function test_completion_over_several_attempts(array $attempts, array $expected): void {
        global $DB;
        $this->resetAfterTest();
        $anyvalid = (bool) array_filter($attempts, fn($a) => $a[0] !== null);
        foreach ($expected as $method => $state) {
            $this->activity((int) $method, [
                'passscore' => 6.0, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1,
                'completionpassgrade' => 1, 'completionvalidresult' => 1, 'completionattemptcompleted' => 1,
            ]);
            foreach ($attempts as [$measure, $finished]) {
                $this->complete($measure, $finished);
            }
            $this->assertSame($state, $this->completion(), "method $method, live.");
            $this->assertSame($anyvalid ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE, $this->completion('completionvalidresult'));
            $this->assertSame(COMPLETION_COMPLETE, $this->completion('completionattemptcompleted'));
            foreach ($expected as $other => $otherstate) {
                $DB->set_field('adaptivequiz', 'grademethod', $other, ['id' => $this->adaptivequiz->id]);
                adaptivequiz_update_grades($DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]));
                $this->assertSame($otherstate, $this->completion(), "method $method, regraded with $other.");
            }
        }
    }
}
