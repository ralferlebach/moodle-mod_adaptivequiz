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

namespace mod_adaptivequiz\local\attempt;

use moodle_exception;
use moodle_url;
use stdClass;

/**
 * The attempt a question page is rendered for (#27 AK01): it exists, it is the person's attempt of this activity, and
 * it is bound to the question usage the page shows.
 *
 * Checked before any output of the page. An attempt removed meanwhile - a reset, a deletion, an engine hygiene of the
 * lab - ends the request with an error instead of a question form (no HTTP 200 with a question of a lost attempt), and
 * no new attempt is started for it.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class page_binding {
    /**
     * The attempt of the page, read again.
     *
     * @param stdClass $adaptivequiz
     * @param int $userid
     * @param int $usageid the question usage the page shows
     * @param int $cmid for the link back to the activity
     * @return stdClass the attempt row; its state may be completed (the caller shows the result then)
     * @throws moodle_exception attemptlost when there is no such attempt
     */
    public static function verify(stdClass $adaptivequiz, int $userid, int $usageid, int $cmid): stdClass {
        global $DB;
        $attempt = $usageid > 0 ? $DB->get_record('adaptivequiz_attempt', [
            'uniqueid' => $usageid,
            'instance' => $adaptivequiz->id,
            'userid' => $userid,
        ]) : false;
        if (!$attempt) {
            throw new moodle_exception(
                'attemptlost',
                'adaptivequiz',
                (new moodle_url('/mod/adaptivequiz/view.php', ['id' => $cmid]))->out(false),
                null,
                'usage ' . $usageid . ', activity ' . $adaptivequiz->id
            );
        }
        return $attempt;
    }
}
