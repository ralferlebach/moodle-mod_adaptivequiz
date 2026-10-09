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
use grade_item;
use mod_adaptivequiz\local\attempt\attempt_state;
use mod_adaptivequiz\task\snapshot_results;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * The built-in algorithm goes through the same result pipeline as a CAT model (issue #14).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\local\result\builtin_result_provider
 * @covers \mod_adaptivequiz\local\result\result_service
 * @covers \mod_adaptivequiz\task\snapshot_results
 * @covers ::adaptivequiz_get_user_grades
 * @covers ::adaptivequiz_grade_item_update
 */
final class builtin_grading_test extends advanced_testcase {
    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var stdClass The participant. */
    private stdClass $user;

    /** @var int Next question usage id. */
    private int $uniqueid = 9000;

    /**
     * An activity on levels 1 to 11.
     *
     * @param array $settings
     */
    private function activity(array $settings = []): void {
        $course = $this->getDataGenerator()->create_course();
        $this->adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id, 'lowestlevel' => 1, 'highestlevel' => 11,
        ] + $settings);
        $this->user = $this->getDataGenerator()->create_and_enrol($course, 'student');
    }

    /**
     * Runs an attempt to completion.
     *
     * @param float $measure Ability measure in logits.
     * @param int $questions Number of answered questions.
     * @return stdClass The completed attempt.
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
     * The grade the gradebook holds for the participant.
     *
     * @return float|null
     */
    private function grade(): ?float {
        $grades = grade_get_grades($this->adaptivequiz->course, 'mod', 'adaptivequiz', $this->adaptivequiz->id, $this->user->id);
        $grade = $grades->items[0]->grades[$this->user->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * The grade item of the activity.
     *
     * @return grade_item
     */
    private function item(): grade_item {
        return grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'adaptivequiz', 'iteminstance' => $this->adaptivequiz->id, 'itemnumber' => 0,
        ]);
    }

    /**
     * The grade item is 0-100, whatever the levels.
     */
    public function test_grade_item_is_zero_to_hundred(): void {
        $this->resetAfterTest();
        $this->activity();

        $item = $this->item();
        $this->assertEquals(GRADE_TYPE_VALUE, $item->gradetype);
        $this->assertEquals(0, (float) $item->grademin);
        $this->assertEquals(100, (float) $item->grademax);
    }

    /**
     * An ability of 0 logits is the middle of the levels: 6 of 1..11, 50 %.
     */
    public function test_valid_attempt_is_graded_in_percent(): void {
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(0.0);

        $this->assertEquals(1, $attempt->resultvalid);
        $this->assertSame(attempt_result::STATUS_VALID, $attempt->resultstatus);
        $this->assertEqualsWithDelta(6.0, (float) $attempt->resultscore, 1e-5);
        $this->assertEqualsWithDelta(1.0, (float) $attempt->resultlower, 1e-9);
        $this->assertEqualsWithDelta(11.0, (float) $attempt->resultupper, 1e-9);
        $this->assertEqualsWithDelta(50.0, (float) $attempt->resultpercent, 1e-5);
        $this->assertSame('linear', $attempt->resultlink);
        $this->assertNotEmpty($attempt->resulttime);
        $this->assertEqualsWithDelta(50.0, $this->grade(), 1e-5);
    }

    /**
     * An attempt without a single answer measured nothing: no grade - not the grade of ability 0.
     */
    public function test_attempt_without_answers_has_no_grade(): void {
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(0.0, 0);

        $this->assertEquals(0, $attempt->resultvalid);
        $this->assertSame(builtin_result_provider::REASON_NO_ANSWER, $attempt->resultreason);
        $this->assertNull($attempt->resultpercent);
        $this->assertNull($this->grade());
    }

    /**
     * A later change of the levels does not change what a finished attempt was worth.
     */
    public function test_changed_range_keeps_the_historical_percentage(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();

        $this->complete(0.0);
        $DB->set_field('adaptivequiz', 'highestlevel', 21, ['id' => $this->adaptivequiz->id]);
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]);

        result_service::snapshot_missing($adaptivequiz);
        adaptivequiz_update_grades($adaptivequiz);

        $this->assertEqualsWithDelta(50.0, $this->grade(), 1e-5);
        $this->assertEquals(100, (float) $this->item()->grademax);
    }

    /**
     * The pass score in level units becomes the grade item's pass percentage.
     */
    public function test_passscore_becomes_gradepass(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity(['passscore' => 8.5]);

        $this->assertEqualsWithDelta(75.0, (float) $this->item()->gradepass, 1e-5);

        // A new range re-evaluates the pass mark - the pass score stays in its units.
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]);
        $adaptivequiz->lowestlevel = 6;
        adaptivequiz_grade_item_update($adaptivequiz);
        $this->assertEqualsWithDelta(50.0, (float) $this->item()->gradepass, 1e-5);

        // Removing the pass score removes the pass mark.
        $adaptivequiz->passscore = null;
        adaptivequiz_grade_item_update($adaptivequiz);
        $this->assertEquals(0, (float) $this->item()->gradepass);
    }

    /**
     * After the upgrade the task gives older attempts their snapshot and rebuilds the grade item.
     */
    public function test_task_brings_older_attempts_into_the_contract(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(0.0);
        // As before the upgrade: no snapshot, and a grade item on the levels.
        $DB->update_record('adaptivequiz_attempt', (object) [
            'id' => $attempt->id, 'resulttime' => null, 'resultpercent' => null, 'resultvalid' => 0,
        ]);
        $item = $this->item();
        $item->grademax = 11;
        $item->grademin = 1;
        $item->update();

        $this->expectOutputRegex('/1 result snapshot/');
        (new snapshot_results())->execute();

        $percent = (float) $DB->get_field('adaptivequiz_attempt', 'resultpercent', ['id' => $attempt->id]);
        $this->assertEqualsWithDelta(50.0, $percent, 1e-5);
        $this->assertEquals(100, (float) $this->item()->grademax);
        $this->assertEqualsWithDelta(50.0, $this->grade(), 1e-5);
    }

    /**
     * A snapshot is taken once: a later change of the levels does not replace it.
     */
    public function test_snapshot_is_taken_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(0.0);
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]);
        $adaptivequiz->highestlevel = 21;

        $again = result_service::snapshot($adaptivequiz, $attempt);
        $this->assertEquals($attempt->resultupper, $again->resultupper, 'The range of the moment of completion.');
        $this->assertEquals($attempt->resultscore, $again->resultscore);

        $forced = result_service::snapshot($adaptivequiz, $attempt, true);
        $this->assertEqualsWithDelta(11.0, (float) $forced->resultscore, 1e-5, 'Forced: the middle of 1..21.');
    }
}
