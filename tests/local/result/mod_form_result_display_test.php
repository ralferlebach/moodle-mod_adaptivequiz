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
require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/mod_form.php');

/**
 * The activity settings show where results come from and what the pass score means (issue #14).
 *
 * A teacher enters the pass score in the units of the result source; the gradebook gets a
 * percentage. The form shows the source with its range and the resulting grade to pass.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adaptivequiz_mod_form
 */
final class mod_form_result_display_test extends advanced_testcase {
    /**
     * The settings form of an existing activity, as a teacher opens it.
     *
     * @param array $settings Activity settings.
     * @return \MoodleQuickForm
     */
    private function form(array $settings): \MoodleQuickForm {
        global $PAGE;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id] + $settings);
        $course = get_course($course->id);
        $PAGE->set_course($course);
        $cm = get_coursemodule_from_instance('adaptivequiz', $activity->id);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);

        $form = new mod_adaptivequiz_mod_form($data, $cw->section, $cm, $course);
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        return $property->getValue($form);
    }

    /**
     * The text of a static element of the form.
     *
     * @param \MoodleQuickForm $mform
     * @param string $name
     * @return string
     */
    private function text(\MoodleQuickForm $mform, string $name): string {
        $this->assertTrue($mform->elementExists($name), "The form shows '$name'.");
        return html_to_text($mform->getElement($name)->toHtml(), 0, false);
    }

    /**
     * The built-in algorithm: range = the difficulty levels; 6 of 1..11 is 50 %.
     */
    public function test_builtin_source_range_and_pass_percentage(): void {
        $this->resetAfterTest();
        $mform = $this->form(['lowestlevel' => 1, 'highestlevel' => 11, 'passscore' => 6.0]);

        $source = $this->text($mform, 'resultsource');
        $this->assertStringContainsString('1', $source);
        $this->assertStringContainsString('11', $source);
        $this->assertSame(
            get_string('passpercentagevalue', 'adaptivequiz', (object) ['score' => '6', 'percent' => format_float(50, 2)]),
            $this->text($mform, 'passpercentage')
        );
    }

    /**
     * Without a pass score the form says that the gradebook has no grade to pass.
     */
    public function test_no_pass_score(): void {
        $this->resetAfterTest();
        $mform = $this->form(['lowestlevel' => 1, 'highestlevel' => 11]);

        $this->assertSame(get_string('passpercentagenone', 'adaptivequiz'), $this->text($mform, 'passpercentage'));
    }

    /**
     * A stored pass score outside the range has no grade to pass, and the form says so.
     */
    public function test_pass_score_outside_the_range(): void {
        $this->resetAfterTest();
        $settings = (object) ['lowestlevel' => 1, 'highestlevel' => 11, 'passscore' => 30, 'catmodel' => '', 'id' => null];

        $this->assertSame(
            get_string('passpercentageinvalid', 'adaptivequiz'),
            mod_adaptivequiz_mod_form::describe_pass_percentage($settings)
        );
    }
}
