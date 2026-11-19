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

namespace mod_adaptivequiz\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_adaptivequiz\local\attempt\attempt_state;

/**
 * The participant confirmed the notice about how an attempt ended (issue #20).
 *
 * Stored on the attempt, so the notice is not shown again - after a reload, on going back or on
 * another device. Only the participant's own completed attempt; nothing else of the attempt changes.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class acknowledge_stop_notice extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'Id of the attempt (adaptivequiz_attempt.id).'),
        ]);
    }

    /**
     * Stores the confirmation.
     *
     * @param int $attemptid
     * @return array
     */
    public static function execute(int $attemptid): array {
        global $DB, $USER;

        ['attemptid' => $attemptid] = self::validate_parameters(self::execute_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('adaptivequiz', $attempt->instance, 0, false, MUST_EXIST);
        // The course, not the activity: the result page is readable while completion hides the activity
        // (attemptfinished.php), and so must its confirmation be. Validating the module context checked
        // the activity's visibility, the confirmation failed and the notice came back on every visit.
        // What protects the attempt is the check below: own, completed attempt only.
        self::validate_context(\context_course::instance($cm->course));

        if ((int) $attempt->userid !== (int) $USER->id || $attempt->attemptstate !== attempt_state::COMPLETED) {
            throw new \moodle_exception('nopermissions', 'error', '', 'acknowledge the notice of this attempt');
        }
        if (empty($attempt->stopnoticeack)) {
            $DB->set_field('adaptivequiz_attempt', 'stopnoticeack', time(), ['id' => $attempt->id]);
        }
        return ['acknowledged' => true];
    }

    /**
     * Return values definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'acknowledged' => new external_value(PARAM_BOOL, 'Whether the confirmation is stored.'),
        ]);
    }
}
