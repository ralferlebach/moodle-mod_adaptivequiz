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
 * Brings the grades of one activity up to date with its completed attempts.
 *
 * Queued whenever the settings of the activity are saved: switching grading on, changing the grading
 * method or the result settings then reaches the attempts that already exist, without a command line.
 * Completed attempts without a result snapshot get one; attempts with one keep it. Every grade then
 * follows the grading method from the valid results alone - the same as cli/regrade_results.php
 * without --resnapshot. Safe to run again.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regrade_activity extends adhoc_task {
    /**
     * Queues the regrade of an activity, once: a second save before the task ran adds nothing.
     *
     * @param int $instanceid adaptivequiz.id
     * @return void
     */
    public static function queue(int $instanceid): void {
        $task = new self();
        $task->set_custom_data((object) ['instanceid' => $instanceid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Snapshots the missing results and rebuilds the grades of the activity.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $instanceid = (int) ($this->get_custom_data()->instanceid ?? 0);
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $instanceid]);
        if (!$adaptivequiz) {
            // Deleted since it was queued: nothing to grade.
            return;
        }
        $report = result_service::regrade($adaptivequiz);
        mtrace("adaptivequiz {$adaptivequiz->id}: {$report->attempts} completed attempt(s), {$report->snapshots} snapshot(s) "
            . "taken, {$report->valid} with a valid result; grades rebuilt.");
    }
}
