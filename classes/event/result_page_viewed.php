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

namespace mod_adaptivequiz\event;

use context_module;
use core\event\base;
use moodle_url;
use stdClass;

/**
 * The owner of a completed attempt has opened its result page (issue #15).
 *
 * Two milestones, two events: attempt_completed says the test has ended; this event says only that
 * its result page was actually opened. A test can end without the page ever being opened, and a
 * page can be opened any number of times - each view is an event, and which one was the first is
 * for the consumer to decide.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_page_viewed extends base {
    /**
     * Builds the event from the stored attempt - nothing is taken from the request.
     *
     * @param stdClass $attempt The row of adaptivequiz_attempt, already checked to belong to the viewer.
     * @param context_module $context The context of the activity.
     * @return self
     */
    public static function create_from_attempt(stdClass $attempt, context_module $context): self {
        return self::create([
            'objectid' => (int) $attempt->id,
            'context' => $context,
            'userid' => (int) $attempt->userid,
            'relateduserid' => (int) $attempt->userid,
            'other' => [
                'instanceid' => (int) $attempt->instance,
                'uniqueid' => (int) $attempt->uniqueid,
                'timefinished' => isset($attempt->timefinished) ? (int) $attempt->timefinished : null,
                'resultstatus' => $attempt->resultstatus ?? null,
                'resultvalid' => isset($attempt->resultvalid) ? (int) $attempt->resultvalid : null,
            ],
        ]);
    }

    /**
     * Get name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventresultpageviewed', 'adaptivequiz');
    }

    /**
     * Get description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' viewed the result page of the attempt with id '$this->objectid' " .
            "for the adaptive quiz with course module id '$this->contextinstanceid'.";
    }

    /**
     * Returns the result page of this attempt.
     *
     * @return moodle_url
     */
    public function get_url() {
        return new moodle_url('/mod/adaptivequiz/attemptfinished.php', [
            'cmid' => $this->contextinstanceid,
            'id' => $this->other['instanceid'],
            'uattid' => $this->other['uniqueid'],
        ]);
    }

    /**
     * Object id mapping for backup and restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'adaptivequiz_attempt', 'restore' => 'adaptiveattempts'];
    }

    /**
     * Other mapping: none of the values is an id that restore has to translate but instanceid.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['instanceid' => ['db' => 'adaptivequiz', 'restore' => 'adaptivequiz']];
    }

    /**
     * Init method.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'adaptivequiz_attempt';
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }
}
