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
use mod_adaptivequiz\local\catmodel\catmodel_resolver;
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
     * The file a request of pluginfile.php asks for, after every check; null when there is none.
     *
     * Throws when the user has no access to the attempt; returns null when the file does not belong to
     * the question in the slot, its question type keeps it back, or it does not exist.
     *
     * @param \context $context Context named in the path.
     * @param string $component Component of the file.
     * @param string $filearea File area of the file.
     * @param int $qubaid The question usage named in the path.
     * @param int $slot The slot named in the path.
     * @param array $args The rest of the path: item id, file path and name.
     * @param bool $forcedownload
     * @return \stored_file|null
     * @throws moodle_exception When access to the attempt is refused.
     */
    public static function file_for_request(
        \context $context,
        string $component,
        string $filearea,
        int $qubaid,
        int $slot,
        array $args,
        bool $forcedownload
    ): ?\stored_file {
        global $DB;
        $attemptrec = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $qubaid], '*', MUST_EXIST);
        // A running attempt and the review of a finished one follow different rules.
        self::require_access($attemptrec);
        if (!self::file_of_attempt($qubaid, $slot, $component, $filearea, $args, $forcedownload)) {
            return null;
        }
        $fullpath = '/' . $context->id . '/' . $component . '/' . $filearea . '/' . implode('/', $args);
        $file = get_file_storage()->get_file_by_hash(sha1($fullpath));
        if (!$file || $file->is_directory()) {
            return null;
        }
        return $file;
    }

    /**
     * Whether a file belongs to the question in a slot of the usage and its question type releases it.
     *
     * The access to the attempt is checked by require_access(); this ties the file to it. The path of a
     * question file names the question, not the attempt - without this check, access to any attempt
     * would open the files of every question whose context and id were guessed.
     *
     * @param int $qubaid The question usage of the attempt.
     * @param int $slot The slot named in the path.
     * @param string $component Component of the file.
     * @param string $filearea File area of the file.
     * @param array $args The rest of the path: item id, file path and name.
     * @param bool $forcedownload
     * @return bool
     */
    public static function file_of_attempt(
        int $qubaid,
        int $slot,
        string $component,
        string $filearea,
        array $args,
        bool $forcedownload
    ): bool {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        $quba = \question_engine::load_questions_usage_by_activity($qubaid);
        if (!in_array($slot, array_map('intval', $quba->get_slots()), true)) {
            return false;
        }
        // The options of the attempt and review pages: the question shows its feedback there.
        return $quba->check_file_access($slot, new \question_display_options(), $component, $filearea, $args, $forcedownload);
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
        $released = catmodel_resolver::callback($adaptivequiz->catmodel ?? null, self::REVIEW_CALLBACK, $adaptivequiz, $attemptrec);
        return $released === true;
    }
}
