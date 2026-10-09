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
use backup;
use backup_controller;
use restore_controller;
use restore_dbops;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Pass score, grading method and result snapshots survive backup and restore (issue #14).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class backup_restore_result_test extends advanced_testcase {
    /**
     * Backs up a course with users and restores it as a new course.
     *
     * @param int $courseid
     * @return int Id of the new course.
     */
    private function backup_and_restore(int $courseid): int {
        global $CFG, $USER;

        $CFG->backup_file_logger_level = backup::LOG_NONE;
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $courseid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $folder = 'restore_' . uniqid();
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $folder);

        $newcourseid = restore_dbops::create_new_course('Restored', 'R', \core_course_category::get_default()->id);
        $rc = new restore_controller(
            $folder,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    /**
     * The settings and the snapshot of a finished attempt arrive unchanged.
     */
    public function test_settings_and_snapshots_are_restored(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id, 'lowestlevel' => 1, 'highestlevel' => 11, 'passscore' => 8.5,
            'grademethod' => ADAPTIVEQUIZ_ATTEMPTLAST,
        ]);
        $quba = \question_engine::make_questions_usage_by_activity(
            'mod_adaptivequiz',
            \context_module::instance($adaptivequiz->cmid)
        );
        $quba->set_preferred_behaviour('deferredfeedback');
        \question_engine::save_questions_usage_by_activity($quba);
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $adaptivequiz->id, 'userid' => $user->id, 'uniqueid' => $quba->get_id(),
            'attemptstate' => 'complete', 'attemptstopcriteria' => 'done', 'questionsattempted' => 4,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 0.1, 'timecreated' => 100, 'timemodified' => 200,
            'timefinished' => 200, 'resultstatus' => 'valid', 'resultvalid' => 1, 'resultscore' => 6.25,
            'resultlower' => 1, 'resultupper' => 11, 'resultpercent' => 52.5, 'resultlink' => 'linear', 'resulttime' => 200,
        ]);

        $newcourseid = $this->backup_and_restore((int) $course->id);

        $restored = $DB->get_record('adaptivequiz', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(8.5, (float) $restored->passscore, 1e-9);
        $this->assertEquals(ADAPTIVEQUIZ_ATTEMPTLAST, $restored->grademethod);

        $attempt = $DB->get_record('adaptivequiz_attempt', ['instance' => $restored->id], '*', MUST_EXIST);
        $this->assertEquals(1, $attempt->resultvalid);
        $this->assertEqualsWithDelta(6.25, (float) $attempt->resultscore, 1e-9);
        $this->assertEqualsWithDelta(1.0, (float) $attempt->resultlower, 1e-9);
        $this->assertEqualsWithDelta(11.0, (float) $attempt->resultupper, 1e-9);
        $this->assertEqualsWithDelta(52.5, (float) $attempt->resultpercent, 1e-9);
        $this->assertSame('linear', $attempt->resultlink);
        $this->assertEquals(200, $attempt->resulttime);
    }
}
