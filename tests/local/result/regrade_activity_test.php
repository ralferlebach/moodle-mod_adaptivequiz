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
use mod_adaptivequiz\task\regrade_activity;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Saving the settings brings the grades of existing attempts up to date, without a command line.
 *
 * Grading switched on after attempts were completed left those attempts without grades: the settings
 * only updated the grade item. Saving the settings now queues the regrade of the activity.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adaptivequiz\task\regrade_activity
 * @covers     ::adaptivequiz_update_instance
 */
final class regrade_activity_test extends advanced_testcase {
    /**
     * An activity on levels 1 to 11 with one completed attempt of ability 0 (50 %).
     *
     * @return array [activity, participant]
     */
    private function activity_with_attempt(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id, 'lowestlevel' => 1, 'highestlevel' => 11,
        ]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id, 'userid' => $user->id, 'uniqueid' => 9101,
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 5,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 0.0,
            'timecreated' => time(), 'timemodified' => time(), 'timefinished' => time(),
        ]);
        adaptivequiz_complete_attempt(
            9101,
            $DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]),
            context_module::instance($adaptivequiz->cmid),
            (int) $user->id,
            '0.3',
            'done'
        );
        return [$adaptivequiz, $user];
    }

    /**
     * The grade the gradebook holds for a participant.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $user
     * @return float|null
     */
    private function grade(stdClass $adaptivequiz, stdClass $user): ?float {
        $grades = grade_get_grades($adaptivequiz->course, 'mod', 'adaptivequiz', $adaptivequiz->id, $user->id);
        $grade = $grades->items[0]->grades[$user->id]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Saving the settings queues the regrade; the task writes the missing grade.
     */
    public function test_saving_the_settings_regrades_the_existing_attempts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$adaptivequiz, $user] = $this->activity_with_attempt();
        // As with grading switched off when the attempt was completed: no grade in the gradebook.
        $DB->delete_records('grade_grades', ['userid' => $user->id]);
        $this->assertNull($this->grade($adaptivequiz, $user));

        $record = $DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]);
        $record->instance = $adaptivequiz->id;
        $record->coursemodule = $adaptivequiz->cmid;
        adaptivequiz_update_instance($record);

        $tasks = \core\task\manager::get_adhoc_tasks(regrade_activity::class);
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $adaptivequiz->id, (int) reset($tasks)->get_custom_data()->instanceid);

        // A second save before the task ran adds no second task.
        adaptivequiz_update_instance($record);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(regrade_activity::class));

        $this->expectOutputRegex('/1 with a valid result; grades rebuilt/');
        $this->runAdhocTasks(regrade_activity::class);
        $this->assertEqualsWithDelta(50.0, $this->grade($adaptivequiz, $user), 1e-5);
    }

    /**
     * Without completed attempts nothing is queued, and a task for a deleted activity does nothing.
     */
    public function test_nothing_to_regrade(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
        $record = $DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]);
        $record->instance = $adaptivequiz->id;
        $record->coursemodule = $adaptivequiz->cmid;
        adaptivequiz_update_instance($record);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(regrade_activity::class));

        $task = new regrade_activity();
        $task->set_custom_data((object) ['instanceid' => 999999]);
        $task->execute();
        $this->expectOutputString('');
    }
}
