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

/**
 * Handles our own events to make some reactive changes, for example, update activity completion state (if completion is enabled).
 *
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_adaptivequiz;

use completion_info;
use core\event\base;

class attempt_state_change_observers {

    public static function attempt_completed(base $event): void {
        global $DB;

        // Update completion state if enabled.
        if (!$attempt = $event->get_record_snapshot('adaptivequiz_attempt', $event->objectid)) {
            return;
        }
        if (!$adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $attempt->instance])) {
            return;
        }
        if (!$course = $DB->get_record('course', ['id' => $adaptivequiz->course])) {
            return;
        }

        $completion = new completion_info($course);
        if (!$completion->is_enabled()) {
            return;
        }
        // Either rule makes a completed attempt relevant for completion (issue #121). v-3.0 had lost
        // completionvalidresult here, so an activity completed by a valid result was never
        // re-evaluated.
        if (empty($adaptivequiz->completionattemptcompleted) && empty($adaptivequiz->completionvalidresult)) {
            return;
        }
        if (!$cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id, $adaptivequiz->course)) {
            return;
        }
        // Completion rules apply to automatic tracking only. With manual tracking the person ticks the
        // box themselves; the observer used to tick it for them.
        if ((int) $cm->completion !== COMPLETION_TRACKING_AUTOMATIC) {
            return;
        }

        // Moodle re-evaluates the configured rules; custom_completion decides. Setting COMPLETE here
        // declared every completed attempt a completion - also one without a valid result, which
        // the valid-result rule must leave incomplete (issue #121).
        $completion->update_state($cm, COMPLETION_UNKNOWN, (int) $attempt->userid);
    }
}
