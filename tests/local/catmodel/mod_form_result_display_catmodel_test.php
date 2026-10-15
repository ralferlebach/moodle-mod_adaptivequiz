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
use mod_adaptivequiz\local\result\result_service;
use mod_adaptivequiz_mod_form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/mod_form.php');

/**
 * The activity settings show a CAT model's result source and what the pass score means (issue #14).
 *
 * Needs the fixture CAT model, so it does not ship (.gitattributes); the built-in source is
 * covered by tests/local/result/mod_form_result_display_test.php.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_adaptivequiz_mod_form::class)]
final class mod_form_result_display_catmodel_test extends advanced_testcase {
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
     * A CAT model reports its own range; the pass score is read on it: 1 of -4..4 is 62.5 %.
     */
    public function test_catmodel_source_range_and_pass_percentage(): void {
        $this->resetAfterTest();
        test_provider::reset();
        $mform = $this->form(['catmodel' => 'testcatmodel', 'passscore' => 1.0]);

        $source = $this->text($mform, 'resultsource');
        $this->assertStringContainsString('-4', $source);
        $this->assertStringContainsString('4', $source);
        $this->assertSame(
            get_string('passpercentagevalue', 'adaptivequiz', (object) ['score' => '1', 'percent' => format_float(62.5, 2)]),
            $this->text($mform, 'passpercentage')
        );
    }

    /**
     * A CAT model without numeric results says so in the form; no grade to pass is computed.
     */
    public function test_catmodel_without_numeric_result(): void {
        $this->resetAfterTest();
        test_provider::reset();
        set_config('resultdefinition', json_encode(['supportsgrading' => false]), 'adaptivequizcatmodel_testcatmodel');
        $settings = (object) ['catmodel' => 'testcatmodel', 'passscore' => 1.0, 'id' => null];

        $definition = result_service::definition($settings);
        $this->assertSame(
            get_string('resultsourcenotgradable', 'adaptivequiz', $definition->label),
            mod_adaptivequiz_mod_form::describe_result_source_for($settings)
        );
        $this->assertSame(
            get_string('passpercentageinvalid', 'adaptivequiz'),
            mod_adaptivequiz_mod_form::describe_pass_percentage($settings)
        );
    }
}
