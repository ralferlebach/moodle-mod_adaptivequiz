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
     */
    private function activity(int $grademethod): void {
        test_provider::reset();
        $this->course = $this->getDataGenerator()->create_course();
        $this->adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $this->course->id, 'catmodel' => 'testcatmodel', 'grademethod' => $grademethod,
        ]);
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Completes an attempt with the given result, as the host does at the end of an attempt.
     *
     * @param float|null $percent The percentage of a valid result on 0..100, null for an invalid one.
     * @param int $finished
     */
    private function complete(?float $percent, int $finished): void {
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
            'done'
        );
        // The order of the attempts is the order they were finished in, whatever time the completion stamped.
        $DB->set_field('adaptivequiz_attempt', 'timefinished', $finished, ['uniqueid' => $uniqueid]);
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
}
