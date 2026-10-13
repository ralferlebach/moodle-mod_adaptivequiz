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
 * The explicit regrade of an activity (issue #19).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(result_service::class)]
final class regrade_test extends advanced_testcase {
    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var stdClass[] The participants. */
    private array $users = [];

    /** @var int Next question usage id. */
    private int $uniqueid = 9500;

    /**
     * An activity on levels 1 to 11 with two participants.
     *
     * @param string $grademethod
     */
    private function activity(string $grademethod = ADAPTIVEQUIZ_GRADEHIGHEST): void {
        $course = $this->getDataGenerator()->create_course();
        $this->adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id, 'lowestlevel' => 1, 'highestlevel' => 11, 'grademethod' => $grademethod,
        ]);
        $this->users = [
            $this->getDataGenerator()->create_and_enrol($course, 'student'),
            $this->getDataGenerator()->create_and_enrol($course, 'student'),
        ];
    }

    /**
     * Runs an attempt to completion.
     *
     * @param int $user Index of the participant.
     * @param float $measure Ability in logits; 0 is 50 %.
     * @param int $questions Answered questions; 0 gives no result.
     * @return int adaptivequiz_attempt.id
     */
    private function complete(int $user, float $measure, int $questions = 5): int {
        global $DB;

        $uniqueid = ++$this->uniqueid;
        $userid = (int) $this->users[$user]->id;
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $userid, 'uniqueid' => $uniqueid,
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => $questions,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => $measure,
            'timecreated' => time(), 'timemodified' => time(), 'timefinished' => 1000 + $uniqueid,
        ]);
        adaptivequiz_complete_attempt(
            $uniqueid,
            $this->record(),
            context_module::instance($this->adaptivequiz->cmid),
            $userid,
            '0.3',
            'done'
        );
        return (int) $DB->get_field('adaptivequiz_attempt', 'id', ['uniqueid' => $uniqueid]);
    }

    /**
     * The activity as stored.
     *
     * @return stdClass
     */
    private function record(): stdClass {
        global $DB;
        return $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]);
    }

    /**
     * The gradebook grade of a participant.
     *
     * @param int $user Index of the participant.
     * @return float|null
     */
    private function grade(int $user): ?float {
        $userid = $this->users[$user]->id;
        $grades = grade_get_grades($this->adaptivequiz->course, 'mod', 'adaptivequiz', $this->adaptivequiz->id, $userid);
        $grade = $grades->items[0]->grades[$userid]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Everything the regrade could touch: snapshots and grades.
     *
     * @return array
     */
    private function state(): array {
        global $DB;
        return [
            $DB->get_records(
                'adaptivequiz_attempt',
                ['instance' => $this->adaptivequiz->id],
                'id',
                'id, resultvalid, resultstatus, resultreason, resultscore, resultlower, resultupper, resultpercent, resulttime'
            ),
            [$this->grade(0), $this->grade(1)],
        ];
    }

    /**
     * Twice is once: a second regrade changes neither snapshot nor grade.
     */
    public function test_regrade_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();

        $this->complete(0, 0.0);
        $this->complete(0, 1.0);
        $this->complete(1, 0.0, 0);
        // An attempt from before the result contract: no snapshot yet.
        $old = $this->complete(1, 0.0);
        $DB->update_record('adaptivequiz_attempt', (object) ['id' => $old, 'resulttime' => null, 'resultpercent' => null]);

        $first = result_service::regrade($this->record());
        $state = $this->state();
        $second = result_service::regrade($this->record());

        $this->assertSame(1, $first->snapshots, 'Only the missing snapshot is taken.');
        $this->assertSame(0, $second->snapshots);
        $this->assertEquals($state, $this->state());
        $this->assertEqualsWithDelta(50.0, $this->grade(1), 1e-5, 'The old attempt is graded from its new snapshot.');
    }

    /**
     * An attempt without a valid result gives no grade - not 0 - and is reported with its reason.
     */
    public function test_invalid_attempts_give_no_grade_and_are_reported(): void {
        $this->resetAfterTest();
        $this->activity();

        $this->complete(0, 0.0);
        $invalid = $this->complete(1, 0.0, 0);

        $report = result_service::regrade($this->record());

        $this->assertSame(2, $report->attempts);
        $this->assertSame(1, $report->valid);
        $this->assertSame([$invalid => builtin_result_provider::REASON_NO_ANSWER], $report->ungraded);
        $this->assertEqualsWithDelta(50.0, $this->grade(0), 1e-5);
        $this->assertNull($this->grade(1));
    }

    /**
     * A result judged invalid after the fact loses its grade with the regrade of that attempt only.
     *
     * The production case: an attempt with 3 of at least 12 answers was graded. Once the CAT model
     * judged it again, its snapshot is retaken; the snapshots of other attempts stay as they were.
     */
    public function test_resnapshot_applies_to_the_named_attempts_only(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();

        $short = $this->complete(0, 2.0);
        $other = $this->complete(1, 0.0);
        $this->assertGreaterThan(50.0, $this->grade(0));

        // The judgement changed: now no answer counts.
        $DB->set_field('adaptivequiz_attempt', 'questionsattempted', 0, ['id' => $short]);
        $DB->set_field('adaptivequiz_attempt', 'questionsattempted', 0, ['id' => $other]);
        $before = $DB->get_record('adaptivequiz_attempt', ['id' => $other]);

        result_service::regrade($this->record());
        $this->assertGreaterThan(50.0, $this->grade(0), 'Without --resnapshot the snapshot stands.');

        $report = result_service::regrade($this->record(), [$short]);

        $this->assertSame(1, $report->snapshots);
        $this->assertNull($this->grade(0), 'Invalid now: no grade.');
        $this->assertEquals($before, $DB->get_record('adaptivequiz_attempt', ['id' => $other]), 'Not named, not touched.');
        $this->assertEqualsWithDelta(50.0, $this->grade(1), 1e-5);
    }

    /**
     * The regrade follows the grading method of the activity.
     */
    public function test_regrade_follows_the_grading_method(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity(ADAPTIVEQUIZ_ATTEMPTFIRST);

        $this->complete(0, 0.0);
        $this->complete(0, 1.0);
        $this->complete(0, 0.0, 0);
        $first = $this->grade(0);
        $grades = [];

        foreach ([ADAPTIVEQUIZ_GRADEHIGHEST, ADAPTIVEQUIZ_ATTEMPTLAST, ADAPTIVEQUIZ_ATTEMPTFIRST] as $method) {
            $DB->set_field('adaptivequiz', 'grademethod', $method, ['id' => $this->adaptivequiz->id]);
            result_service::regrade($this->record());
            $grades[$method] = $this->grade(0);
        }

        $this->assertEqualsWithDelta(50.0, $first, 1e-5);
        $this->assertGreaterThan(50.0, $grades[ADAPTIVEQUIZ_GRADEHIGHEST]);
        $this->assertSame($grades[ADAPTIVEQUIZ_GRADEHIGHEST], $grades[ADAPTIVEQUIZ_ATTEMPTLAST], 'The last valid attempt.');
        $this->assertEqualsWithDelta(50.0, $grades[ADAPTIVEQUIZ_ATTEMPTFIRST], 1e-5);
    }

    /**
     * The command line script runs the regrade.
     */
    public function test_cli_runs_the_regrade(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/mod/adaptivequiz/cli/regrade_results.php');
        $this->assertStringContainsString('result_service::regrade($adaptivequiz, $resnapshot)', $source);
    }
}
