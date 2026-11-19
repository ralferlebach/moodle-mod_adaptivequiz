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

namespace mod_adaptivequiz\task;

use core\task\adhoc_task;
use mod_adaptivequiz\local\result\result_service;

/**
 * Brings every activity into the result contract after the upgrade (issue #14).
 *
 * Completed attempts without a result snapshot get one from their result provider, and every
 * grade item is rebuilt as 0-100 with the grades from the snapshots. Attempts that already have a
 * snapshot keep it. Safe to run again.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot_results extends adhoc_task {
    /**
     * Snapshots and regrades every activity.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $instances = $DB->get_recordset('adaptivequiz', null, 'id');
        foreach ($instances as $adaptivequiz) {
            try {
                $count = result_service::snapshot_missing($adaptivequiz);
                adaptivequiz_update_grades($adaptivequiz);
                mtrace("adaptivequiz {$adaptivequiz->id}: {$count} result snapshot(s), grades rebuilt.");
            } catch (\Throwable $e) {
                // One broken activity must not keep all others from their grades.
                mtrace("adaptivequiz {$adaptivequiz->id}: " . $e->getMessage());
            }
        }
        $instances->close();
    }
}
