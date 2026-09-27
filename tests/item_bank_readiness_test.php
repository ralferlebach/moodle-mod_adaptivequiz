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

        $adaptivequiz = (object) ['id' => 97001, 'catmodel' => null];

        $this->assertFalse(item_bank::is_ready_for_attempt($adaptivequiz));
    }

    /**
     * With the CATquiz model, CATquiz decides - and says yes once its scale holds an item.
     */
    public function test_with_the_catquiz_model_catquiz_decides(): void {
        global $DB;

        if (!class_exists(\local_catquiz\catquiz_handler::class)) {
            $this->markTestSkipped('The CATquiz model is not installed.');
        }
        $this->resetAfterTest();

        $scaleid = (int) $DB->insert_record('local_catquiz_catscales', (object) [
            'parentid' => 0, 'name' => 'scale', 'contextid' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_catquiz_tests', (object) [
            'componentid' => 97002, 'component' => 'mod_adaptivequiz', 'catscaleid' => $scaleid, 'contextid' => 1,
            'courseid' => 1, 'name' => 'test', 'json' => '{}', 'status' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $adaptivequiz = (object) ['id' => 97002, 'catmodel' => 'catquiz'];

        // No item yet: not ready, and the host's rules are not consulted at all.
        $this->assertFalse(item_bank::is_ready_for_attempt($adaptivequiz));

        $DB->insert_record('local_catquiz_items', (object) [
            'componentid' => 1, 'componentname' => 'question', 'catscaleid' => $scaleid, 'contextid' => 1,
            'status' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->assertTrue(
            item_bank::is_ready_for_attempt($adaptivequiz),
            'A CATquiz test with an item must be ready although no question bank is linked to the instance.'
        );
    }
}
