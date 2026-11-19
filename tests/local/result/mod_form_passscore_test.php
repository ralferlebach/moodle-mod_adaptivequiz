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
use mod_adaptivequiz_mod_form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/mod_form.php');

/**
 * The pass score in the activity settings (issue #14).
 *
 * Set in the units of the result source, checked against its range, handed to the gradebook and to
 * "receive a passing grade" as a percentage.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz_mod_form
 */
final class mod_form_passscore_test extends advanced_testcase {
    /**
     * The settings form of an activity on levels 1 to 11, and the data it would submit.
     *
     * @return array [form, data]
     */
    private function form(): array {
        global $CFG, $PAGE;
        $CFG->enablecompletion = 1;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id, 'lowestlevel' => 1, 'highestlevel' => 11,
        ]);
        $this->setAdminUser();
        // The page of the form: the form reads the course from it.
        $course = get_course($course->id);
        $PAGE->set_course($course);

        $cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        $form = new mod_adaptivequiz_mod_form($data, $cw->section, $cm, $course);

        // As submitted without restrictions; the stored null is not what the form sends.
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category();
        $data = (array) $data + ['lowestlevel' => 1, 'highestlevel' => 11, 'questionpool' => [$category->id]];
        $data['availabilityconditionsjson'] = '';
        return [$form, $data];
    }

    /**
     * Within the range: accepted; outside: refused with the range in the message.
     */
    public function test_passscore_is_checked_against_the_range(): void {
        $this->resetAfterTest();
        [$form, $data] = $this->form();

        $this->assertArrayNotHasKey('passscore', $form->validation(['passscore' => '8.5'] + $data, []));
        $this->assertArrayNotHasKey('passscore', $form->validation(['passscore' => ''] + $data, []));

        $errors = $form->validation(['passscore' => '12'] + $data, []);
        $this->assertArrayHasKey('passscore', $errors);
        $this->assertStringContainsString('11', $errors['passscore']);

        $this->assertArrayHasKey('passscore', $form->validation(['passscore' => 'abc'] + $data, []));
    }

    /**
     * "Receive a passing grade" needs a pass score - and is satisfied by one.
     */
    public function test_passing_grade_completion_needs_a_passscore(): void {
        $this->resetAfterTest();
        [$form, $data] = $this->form();
        $completion = ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1, 'completionpassgrade' => 1];

        $this->assertArrayHasKey('completionpassgrade', $form->validation(['passscore' => ''] + $completion + $data, []));
        $this->assertArrayNotHasKey('completionpassgrade', $form->validation(['passscore' => '8.5'] + $completion + $data, []));
    }

    /**
     * The saved data carries the pass score as a number and its percentage as the pass mark.
     */
    public function test_postprocessing_converts_the_passscore(): void {
        $this->resetAfterTest();
        [$form, $data] = $this->form();

        $submitted = (object) (['passscore' => '8.5'] + $data);
        $form->data_postprocessing($submitted);
        $this->assertSame(8.5, $submitted->passscore);
        $this->assertEqualsWithDelta(75.0, $submitted->gradepass, 1e-9);

        $submitted = (object) (['passscore' => ''] + $data);
        $form->data_postprocessing($submitted);
        $this->assertNull($submitted->passscore);
        $this->assertEquals(0, $submitted->gradepass);
    }
}
