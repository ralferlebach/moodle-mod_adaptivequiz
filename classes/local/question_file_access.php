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

namespace mod_adaptivequiz\local;

use context_module;
use mod_adaptivequiz\local\attempt\attempt_state;
use moodle_exception;
use stdClass;

/**
 * Who may load the files of the questions in an attempt: images, STACK plots, attachments.
 *
 * A running attempt keeps the rules it always had: the activity must be open to the user, the
 * attempt must be their current one and still in progress.
 *
 * A finished attempt is reviewed, not taken. The review must also work when the activity has since
 * been hidden or restricted - the result page allows exactly that - so the activity's visibility is
 * not asked again; logging in to the course still is. The owner may load the files when the CAT
 * model of the activity releases the review of its questions; others need the report capability.
 * Without a CAT model that says so, an owner gets no files of a finished attempt - as before.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class question_file_access {
    /** @var string Callback a CAT model offers: may the owner review the questions of a finished attempt? */
    public const REVIEW_CALLBACK = 'completed_attempt_review_allowed';

    /**
     * Checks access to the question files of an attempt and logs the user in to the course.
     *
     * @param stdClass $attemptrec The adaptivequiz_attempt record the question usage belongs to.
     * @return void
     * @throws moodle_exception When access is refused.
     */
    public static function require_access(stdClass $attemptrec): void {
        global $CFG, $DB, $USER;

        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $attemptrec->instance], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $adaptivequiz->course], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id, $adaptivequiz->course, false, MUST_EXIST);
        $modcontext = context_module::instance($cm->id);

        if ($attemptrec->attemptstate === attempt_state::IN_PROGRESS) {
            require_login($course, true, $cm);
            $canattempt = has_capability('mod/adaptivequiz:attempt', $modcontext);
            if (!$canattempt && !has_capability('mod/adaptivequiz:viewreport', $modcontext)) {
                throw new moodle_exception('nopermission', 'adaptivequiz');
            }
            if ((int) $attemptrec->userid !== (int) $USER->id) {
                require_capability('mod/adaptivequiz:viewreport', $modcontext);
                return;
            }
            require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
            $count = adaptivequiz_count_user_previous_attempts($adaptivequiz->id, $USER->id);
            if (!adaptivequiz_allowed_attempt($adaptivequiz->attempts, $count)) {
                throw new moodle_exception('noattemptsallowed', 'adaptivequiz');
            }
            if (!adaptivequiz_uniqueid_part_of_attempt($attemptrec->uniqueid, $cm->instance, $USER->id)) {
                throw new moodle_exception('uniquenotpartofattempt', 'adaptivequiz');
            }
            return;
        }

        // A finished attempt: the course, not the activity - the result page does the same.
        require_login($course, false);
        if (!self::review_allowed($adaptivequiz, $attemptrec, $modcontext, (int) $USER->id)) {
            throw new moodle_exception('nopermission', 'adaptivequiz');
        }
    }

    /**
     * Whether a user may load the question files of a finished attempt.
     *
     * Logging in to the course is checked by the caller.
     *
     * @param stdClass $adaptivequiz The activity.
     * @param stdClass $attemptrec The finished attempt.
     * @param context_module $modcontext The context of the activity.
     * @param int $userid The user asking.
     * @return bool
     */
    public static function review_allowed(
        stdClass $adaptivequiz,
        stdClass $attemptrec,
        context_module $modcontext,
        int $userid
    ): bool {
        if ($attemptrec->attemptstate === attempt_state::IN_PROGRESS) {
            return false;
        }
        if ((int) $attemptrec->userid !== $userid) {
            return has_capability('mod/adaptivequiz:viewreport', $modcontext, $userid);
        }
        $canattempt = has_capability('mod/adaptivequiz:attempt', $modcontext, $userid);
        if (!$canattempt && !has_capability('mod/adaptivequiz:viewreport', $modcontext, $userid)) {
            return false;
        }
        return self::catmodel_releases_review($adaptivequiz, $attemptrec);
    }

    /**
     * Whether the CAT model of the activity releases the questions of a finished attempt to its owner.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attemptrec
     * @return bool
     */
    private static function catmodel_releases_review(stdClass $adaptivequiz, stdClass $attemptrec): bool {
        if (empty($adaptivequiz->catmodel)) {
            return false;
        }
        $functions = get_plugin_list_with_function('adaptivequizcatmodel', self::REVIEW_CALLBACK);
        $component = 'adaptivequizcatmodel_' . $adaptivequiz->catmodel;
        if (!array_key_exists($component, $functions)) {
            return false;
        }
        return $functions[$component]($adaptivequiz, $attemptrec) === true;
    }
}
