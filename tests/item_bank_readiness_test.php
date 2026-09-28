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

namespace mod_adaptivequiz;

use advanced_testcase;

/**
 * The host asks the CAT model whether the item bank is ready.
 *
 * view.php and the item bank notification judged every instance by the host's own conditions -
 * question banks linked, item administration parameters valid. A CAT model that keeps its items
 * elsewhere never met them, and no attempt could be started. The CAT model now answers for itself
 * through catmodel_item_bank_readiness; without one, nothing changes.
 *
 * Checked with the neutral test CAT model: the host must not know any real one (issue #10).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\item_bank
 */
final class item_bank_readiness_test extends advanced_testcase {
    /**
     * Without a CAT model, the host's own conditions apply - here: nothing linked, not ready.
     */
    public function test_without_a_cat_model_the_host_decides(): void {
        $this->resetAfterTest();

        $this->assertFalse(item_bank::is_ready_for_attempt((object) ['id' => 97001, 'catmodel' => null]));
    }

    /**
     * With a CAT model, its answer counts - in both directions, and without any question bank.
     */
    public function test_with_a_cat_model_the_cat_model_decides(): void {
        $this->resetAfterTest();
        $adaptivequiz = (object) ['id' => 97002, 'catmodel' => 'testcatmodel'];

        set_config('itembankready', 1, 'adaptivequizcatmodel_testcatmodel');
        $this->assertTrue(
            item_bank::is_ready_for_attempt($adaptivequiz),
            'The CAT model reports ready; the host must not overrule it with its own item bank rules.'
        );

        set_config('itembankready', 0, 'adaptivequizcatmodel_testcatmodel');
        $this->assertFalse(item_bank::is_ready_for_attempt($adaptivequiz));
    }
}
