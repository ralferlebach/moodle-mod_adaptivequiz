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

use advanced_testcase;
use mod_adaptivequiz\local\catmodel\form\mod_form_extension;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * A CAT model's form preprocessor receives the host's form.
 *
 * The preprocessor was called with the default values only. A CAT model then could not tell the
 * first load of the form from a reload after the user changed something, and wrote its stored
 * settings over the fresh choice - a CAT model's newly chosen scale or activated subscale could not
 * be saved. The form now goes along.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\local\catmodel\form\mod_form_extension
 */
final class preprocessor_receives_form_test extends advanced_testcase {
    /**
     * The form and its submission reach the CAT model.
     */
    public function test_the_form_reaches_the_cat_model(): void {
        $this->resetAfterTest();

        $form = new MoodleQuickForm('mod_adaptivequiz_mod_form', 'post', '');
        $form->updateSubmission(['catmodel' => 'testcatmodel', 'somefield' => 'fresh choice'], []);

        $values = mod_form_extension::preprocess(['catmodel' => 'testcatmodel'], $form);

        $seen = array_values(array_filter($values, fn($key) => str_ends_with($key, '_formseen'), ARRAY_FILTER_USE_KEY));
        $this->assertNotEmpty($seen, 'The CAT model preprocessor was not called.');
        $this->assertStringContainsString('fresh choice', $seen[0], 'The CAT model did not receive the submitted form.');
    }
}
