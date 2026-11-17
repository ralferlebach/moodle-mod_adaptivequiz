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
use mod_adaptivequiz\task\regrade_activity;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Grading settings of the activity (issue #25): no grading, the link function, keeping existing grades.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(result_service::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_grade_item_update')]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_update_instance')]
final class grading_settings_test extends advanced_testcase {
    /** @var stdClass */
    private stdClass $course;

    /** @var stdClass */
    private stdClass $adaptivequiz;

    /** @var stdClass */
    private stdClass $user;

    /** @var int Next question usage id. */
    private int $uniqueid = 9000;

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
     * The activity record.
     *
     * @return stdClass
     */
    private function record(): stdClass {
        global $DB;
        return $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id], '*', MUST_EXIST);
    }

    /**
     * Changes settings of the activity directly.
     *
     * @param array $fields
     */
    private function set(array $fields): void {
        global $DB;
        foreach ($fields as $field => $value) {
            $DB->set_field('adaptivequiz', $field, $value, ['id' => $this->adaptivequiz->id]);
        }
    }

    /**
     * Runs an attempt to completion with the given result.
     *
     * @param attempt_result $result
     * @return stdClass
     */
    private function complete(attempt_result $result): stdClass {
        global $DB;
        $uniqueid = ++$this->uniqueid;
        test_provider::set_result($uniqueid, $result);
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => $uniqueid,
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 5,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 3.0,
            'timecreated' => time(), 'timemodified' => time(), 'timefinished' => 1000 + $uniqueid,
        ]);
        adaptivequiz_complete_attempt(
            $uniqueid,
            $this->record(),
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
     * The grade item of the activity.
     *
     * @return grade_item|null None when the activity never had one.
     */
    private function item(): ?grade_item {
        return grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'adaptivequiz', 'iteminstance' => $this->adaptivequiz->id,
            'courseid' => $this->course->id, 'itemnumber' => 0,
        ]) ?: null;
    }

    /**
     * Whether the activity has no grade item with a value: none at all, or one without a value type.
     *
     * @return bool
     */
    private function not_graded(): bool {
        $item = $this->item();
        return $item === null || (int) $item->gradetype === GRADE_TYPE_NONE;
    }

    /**
     * Without grading: a valid result stays valid, completes "valid result", and there is no grade - not 0.
     */
    public function test_no_grading_keeps_the_result_valid_without_a_grade(): void {
        $this->resetAfterTest();
        $this->activity([
            'gradingmode' => result_service::GRADING_OFF, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionvalidresult' => 1,
        ]);

        $attempt = $this->complete(attempt_result::valid(1.2, -4.0, 4.0));

        $this->assertEquals(1, $attempt->resultvalid);
        $this->assertSame(attempt_result::STATUS_VALID, $attempt->resultstatus);
        $this->assertSame(result_service::REASON_GRADING_OFF, $attempt->resultreason);
        $this->assertEqualsWithDelta(1.2, (float) $attempt->resultscore, 1e-9);
        $this->assertNull($attempt->resultpercent);
        $this->assertTrue($this->not_graded());
        $this->assertNull($this->grade());
        $this->assertNull(result_service::pass_percentage($this->record()));
        $cm = get_fast_modinfo($this->course)->get_cm($this->adaptivequiz->cmid);
        $state = (new completion_info($this->course))->get_data($cm, false, $this->user->id)->completionstate;
        $this->assertEquals(COMPLETION_COMPLETE, $state);
    }

    /**
     * An invalid result without grading stays invalid: the grading mode does not judge the measurement.
     */
    public function test_no_grading_keeps_an_invalid_result_invalid(): void {
        $this->resetAfterTest();
        $this->activity(['gradingmode' => result_service::GRADING_OFF]);

        $attempt = $this->complete(attempt_result::invalid('fraction_all_correct'));

        $this->assertEquals(0, $attempt->resultvalid);
        $this->assertSame('fraction_all_correct', $attempt->resultreason);
    }

    /**
     * None -> graded -> none: the grade follows, computed from the stored score; nothing becomes 0.
     */
    public function test_switching_grading_on_and_off(): void {
        $this->resetAfterTest();
        $this->activity(['gradingmode' => result_service::GRADING_OFF]);
        $this->complete(attempt_result::valid(1.2, -4.0, 4.0));
        $this->assertNull($this->grade());

        $this->set(['gradingmode' => result_service::GRADING_ON]);
        $report = result_service::regrade($this->record(), [], true);
        $this->assertSame(1, $report->relinked);
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-5);
        $this->assertSame(GRADE_TYPE_VALUE, (int) $this->item()->gradetype);

        $this->set(['gradingmode' => result_service::GRADING_OFF]);
        result_service::regrade($this->record(), [], true);
        $this->assertTrue($this->not_graded());
        $this->assertNull($this->grade());
    }

    /**
     * The activity's link function wins over the default of the result source, with its pass score.
     *
     * @return array
     */
    public static function links(): array {
        return [
            'all or nothing, above P' => ['guttman_pass', 1.0, 1.2, 100.0],
            'all or nothing, below P' => ['guttman_pass', 1.0, 0.5, 0.0],
            'piecewise at P' => ['piecewise_pass50', 1.2, 1.2, 50.0],
            'logistic at P' => ['logistic_pass', 1.2, 1.2, 50.0],
            'logistic in the middle' => ['logistic_mid', null, 0.0, 50.0],
        ];
    }

    /**
     * The activity's link function.
     *
     * @param string $link
     * @param float|null $pass
     * @param float $score
     * @param float $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('links')]
    public function test_the_activity_link_function(string $link, ?float $pass, float $score, float $expected): void {
        $this->resetAfterTest();
        $this->activity(['linkfunction' => $link, 'passscore' => $pass]);

        $attempt = $this->complete(attempt_result::valid($score, -4.0, 4.0));

        $this->assertSame($link, $attempt->resultlink);
        $this->assertSame(1, (int) $attempt->resultlinkversion);
        $this->assertEqualsWithDelta($expected, (float) $attempt->resultpercent, 1e-9);
        $this->assertEqualsWithDelta($expected, $this->grade(), 1e-5);
    }

    /**
     * Fisher-Rao with the test information of the result source; without one the measurement stays valid and there
     * is no grade, never 0 and never the linear value.
     */
    public function test_fisher_rao(): void {
        $this->resetAfterTest();
        set_config('information', json_encode(['rasch' => [-1.0, 0.0, 1.0]]), 'adaptivequizcatmodel_testcatmodel');
        $this->activity(['linkfunction' => 'fisher_rao']);
        $attempt = $this->complete(attempt_result::valid(0.0, -4.0, 4.0));
        $this->assertEqualsWithDelta(50.0, (float) $attempt->resultpercent, 1e-7);

        set_config('information', '', 'adaptivequizcatmodel_testcatmodel');
        $attempt = $this->complete(attempt_result::valid(1.0, -4.0, 4.0));
        $this->assertEquals(1, $attempt->resultvalid);
        $this->assertSame(result_service::REASON_LINK_NOT_COMPUTABLE, $attempt->resultreason);
        $this->assertNull($attempt->resultpercent);
        $this->assertSame(['linkfunction', 'linknoinformation', null], result_service::link_settings_error($this->record()));
    }

    /**
     * A changed link function computes the existing grades again - after a preview that writes nothing.
     */
    public function test_relink_with_preview(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->complete(attempt_result::valid(1.2, -4.0, 4.0));
        $this->assertEqualsWithDelta(65.0, $this->grade(), 1e-5);

        $this->set(['linkfunction' => 'piecewise_pass50', 'passscore' => 1.2]);
        $stored = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id]);
        $preview = result_service::relink($this->record(), $stored, false);
        $this->assertEqualsWithDelta(65.0, $preview->before, 1e-5);
        $this->assertEqualsWithDelta(50.0, $preview->after, 1e-9);
        $percent = (float) $DB->get_field('adaptivequiz_attempt', 'resultpercent', ['id' => $attempt->id]);
        $this->assertEqualsWithDelta(65.0, $percent, 1e-5);

        result_service::regrade($this->record(), [], true);
        $this->assertEqualsWithDelta(50.0, $this->grade(), 1e-5);
        $this->assertSame('piecewise_pass50', $DB->get_field('adaptivequiz_attempt', 'resultlink', ['id' => $attempt->id]));
    }

    /**
     * Saving changed grading settings: with keepgrades nothing is queued, without it a relinking regrade.
     *
     * @return array
     */
    public static function keep(): array {
        return ['keep existing grades' => [1, 0], 'apply to existing grades' => [0, 1]];
    }

    /**
     * Saving the settings.
     *
     * @param int $keepgrades
     * @param int $tasks
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('keep')]
    public function test_saving_respects_keepgrades(int $keepgrades, int $tasks): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->activity(['keepgrades' => $keepgrades]);
        $this->complete(attempt_result::valid(1.2, -4.0, 4.0));
        $before = $this->grade();

        $record = $this->record();
        $record->instance = $record->id;
        $record->coursemodule = $this->adaptivequiz->cmid;
        $record->linkfunction = 'guttman_pass';
        $record->passscore = 2.0;
        adaptivequiz_update_instance($record);

        $queued = \core\task\manager::get_adhoc_tasks(regrade_activity::class);
        $this->assertCount($tasks, $queued);
        if ($tasks) {
            $this->assertTrue(!empty(reset($queued)->get_custom_data()->relink));
            $this->expectOutputRegex('/1 grade\(s\) computed again/');
            $this->runAdhocTasks(regrade_activity::class);
            $this->assertEqualsWithDelta(0.0, $this->grade(), 1e-9);
        } else {
            $this->assertEqualsWithDelta($before, $this->grade(), 1e-9);
        }
    }

    /**
     * Settings a link function cannot work with are refused where they are set.
     */
    public function test_link_settings_errors(): void {
        $this->resetAfterTest();
        $this->activity();
        $settings = $this->record();

        $settings->linkfunction = 'piecewise_pass50';
        $settings->passscore = null;
        $this->assertSame('linkneedspassscore', result_service::link_settings_error($settings)[1]);
        $settings->passscore = 4.0;
        $this->assertSame('linkpassinsiderange', result_service::link_settings_error($settings)[1]);
        $settings->passscore = 0.0;
        $this->assertNull(result_service::link_settings_error($settings));

        $settings->linkfunction = 'logistic_mid';
        $settings->linksteepness = 0;
        $this->assertSame('linksteepnessinvalid', result_service::link_settings_error($settings)[1]);
        $settings->linksteepness = 2.5;
        $this->assertNull(result_service::link_settings_error($settings));

        $settings->linkfunction = 'nosuchfunction';
        $this->assertSame('resulterrorlinkfunction', result_service::link_settings_error($settings)[1]);

        $settings->gradingmode = result_service::GRADING_OFF;
        $this->assertNull(result_service::link_settings_error($settings), 'Without grading nothing is checked.');
    }
}
