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
use completion_info;
use context_module;
use grade_item;
use mod_adaptivequiz\local\attempt\attempt_state;
use mod_adaptivequiz\local\result\link\link_function_registry;
use mod_adaptivequiz\local\result\link\linear_link_function;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Gradebook and completion share one result (issue #14), on the host's own algorithm.
 *
 * The Moodle 5 line proves these points with a test CAT model; this line has none, so they are
 * proven here with the built-in provider and with snapshots of different ranges - the host code is
 * the same.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\local\result\result_service
 * @covers \mod_adaptivequiz\local\result\link\link_function_registry
 * @covers ::adaptivequiz_get_user_grades
 * @covers ::adaptivequiz_sync_gradepass
 * @covers ::adaptivequiz_reevaluate_pass_completion
 * @covers ::adaptivequiz_update_instance
 */
final class grade_completion_contract_test extends advanced_testcase {
    /** @var stdClass The course. */
    private stdClass $course;

    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var stdClass The participant. */
    private stdClass $user;

    /** @var int Next question usage id. */
    private int $uniqueid = 9500;

    /**
     * An activity on levels 1 to 11 with completion enabled.
     *
     * @param array $settings
     */
    private function activity(array $settings = []): void {
        global $CFG;
        $CFG->enablecompletion = 1;
        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $this->course->id, 'lowestlevel' => 1, 'highestlevel' => 11,
        ] + $settings);
        $this->user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Runs an attempt of the built-in algorithm to completion.
     *
     * @param float $measure Ability in logits.
     * @param int $questions Answered questions; 0 gives an invalid result.
     * @return stdClass
     */
    private function complete(float $measure, int $questions = 5): stdClass {
        global $DB;
        $uniqueid = ++$this->uniqueid;
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => $uniqueid,
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => $questions,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => $measure,
            'timecreated' => time(), 'timemodified' => time(), 'timefinished' => 1000 + $uniqueid,
        ]);
        adaptivequiz_complete_attempt(
            $uniqueid,
            $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
            context_module::instance($this->adaptivequiz->cmid),
            (int) $this->user->id,
            'done'
        );
        return $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid]);
    }

    /**
     * A completed attempt with a snapshot taken earlier, on a range of its own.
     *
     * @param float $score
     * @param float $lower
     * @param float $upper
     * @param int $finished
     */
    private function snapshot(float $score, float $lower, float $upper, int $finished): void {
        global $DB;
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => ++$this->uniqueid,
            'attemptstate' => attempt_state::COMPLETED, 'attemptstopcriteria' => 'done', 'questionsattempted' => 5,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 0.0,
            'timecreated' => $finished, 'timemodified' => $finished, 'timefinished' => $finished,
            'resultstatus' => attempt_result::STATUS_VALID, 'resultvalid' => 1, 'resultscore' => $score,
            'resultlower' => $lower, 'resultupper' => $upper,
            'resultpercent' => result_service::percentage($score, $lower, $upper, 'linear'),
            'resultlink' => 'linear', 'resultlinkversion' => 1, 'resulttime' => $finished,
        ]);
    }

    /**
     * The participant's grade.
     *
     * @return float|null
     */
    private function grade(): ?float {
        $grades = grade_get_grades($this->course->id, 'mod', 'adaptivequiz', $this->adaptivequiz->id, $this->user->id);
        $grade = $grades->items[0]->grades[$this->user->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * The participant's completion state.
     *
     * @return int
     */
    private function completion_state(): int {
        $cm = get_fast_modinfo($this->course)->get_cm($this->adaptivequiz->cmid);
        return (int) (new completion_info($this->course))->get_data($cm, false, $this->user->id)->completionstate;
    }

    /**
     * Saves settings the way the activity form does and runs the regrade it queues.
     *
     * @param array $changes
     */
    private function save_settings(array $changes): void {
        global $DB;
        $data = $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]);
        foreach ($changes as $field => $value) {
            $data->$field = $value;
        }
        $data->coursemodule = $this->adaptivequiz->cmid;
        $data->instance = $this->adaptivequiz->id;
        adaptivequiz_update_instance($data);
        ob_start();
        $this->runAdhocTasks(\mod_adaptivequiz\task\regrade_activity::class);
        ob_end_clean();
    }

    /**
     * Pass/fail completion follows the same snapshot and pass mark as the gradebook.
     */
    public function test_pass_completion_follows_the_grade(): void {
        $this->resetAfterTest();
        $this->activity([
            'passscore' => 6.0, 'grademethod' => ADAPTIVEQUIZ_ATTEMPTLAST,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1, 'completionpassgrade' => 1,
        ]);
        $this->assertEqualsWithDelta(50.0, (float) grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'adaptivequiz', 'iteminstance' => $this->adaptivequiz->id,
        ])->gradepass, 1e-5, 'Pass score 6 on levels 1..11.');

        $this->complete(0.0, 0);
        $this->assertNull($this->grade());
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion_state(), 'An invalid result neither passes nor fails.');

        $low = $this->complete(-2.0);
        $this->assertLessThan(50.0, (float) $low->resultpercent);
        $this->assertSame(COMPLETION_COMPLETE_FAIL, $this->completion_state());

        $high = $this->complete(2.0);
        $this->assertGreaterThan(50.0, (float) $high->resultpercent);
        $this->assertEqualsWithDelta((float) $high->resultpercent, $this->grade(), 1e-5);
        $this->assertSame(COMPLETION_COMPLETE_PASS, $this->completion_state());
    }

    /**
     * Highest compares percentages, not scores: 8 of 0..10 beats 9 of 0..20.
     */
    public function test_highest_compares_percentages(): void {
        $this->resetAfterTest();
        $this->activity(['grademethod' => ADAPTIVEQUIZ_GRADEHIGHEST]);
        $this->snapshot(8.0, 0.0, 10.0, 1000);
        $this->snapshot(9.0, 0.0, 20.0, 2000);

        adaptivequiz_update_grades($this->adaptivequiz);

        $this->assertEqualsWithDelta(80.0, $this->grade(), 1e-5);
    }

    /**
     * A new pass score in the settings re-evaluates pass/fail of results that already exist.
     */
    public function test_changed_pass_score_reevaluates_completion(): void {
        $this->resetAfterTest();
        $this->activity([
            'passscore' => 6.0,
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1, 'completionpassgrade' => 1,
        ]);
        $this->complete(-2.0);
        $this->assertSame(COMPLETION_COMPLETE_FAIL, $this->completion_state());

        $this->save_settings(['passscore' => 1.0]);
        $this->assertSame(COMPLETION_COMPLETE_PASS, $this->completion_state());

        $this->save_settings(['passscore' => 10.0]);
        $this->assertSame(COMPLETION_COMPLETE_FAIL, $this->completion_state());
    }

    /**
     * A new grading method in the settings regrades the attempts that exist.
     */
    public function test_changed_grading_method_regrades(): void {
        $this->resetAfterTest();
        $this->activity(['grademethod' => ADAPTIVEQUIZ_ATTEMPTLAST]);
        $this->snapshot(8.0, 0.0, 10.0, 1000);
        $this->snapshot(2.0, 0.0, 10.0, 2000);
        adaptivequiz_update_grades($this->adaptivequiz);
        $this->assertEqualsWithDelta(20.0, $this->grade(), 1e-5, 'Last.');

        $this->save_settings(['grademethod' => ADAPTIVEQUIZ_GRADEHIGHEST]);
        $this->assertEqualsWithDelta(80.0, $this->grade(), 1e-5, 'Highest.');

        $this->save_settings(['grademethod' => ADAPTIVEQUIZ_ATTEMPTFIRST]);
        $this->assertEqualsWithDelta(80.0, $this->grade(), 1e-5, 'First.');
    }

    /**
     * 'linear' resolves stably, and a snapshot records the version it was computed with.
     */
    public function test_linear_is_stable_and_versioned(): void {
        $this->resetAfterTest();
        $this->activity();

        $function = link_function_registry::get('linear');
        $this->assertInstanceOf(linear_link_function::class, $function);
        $this->assertSame('linear', $function->get_id());
        $this->assertSame(1, $function->get_version());
        $this->assertEqualsWithDelta(65.0, $function->percentage(1.2, -4.0, 4.0), 1e-9);

        $attempt = $this->complete(0.0);
        $this->assertSame('linear', $attempt->resultlink);
        $this->assertEquals(1, $attempt->resultlinkversion);
    }
}
