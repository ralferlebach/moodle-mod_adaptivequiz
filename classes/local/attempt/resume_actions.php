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

use context_module;
use core\lock\lock_config;
use mod_adaptivequiz\cat_session;
use stdClass;

/**
 * What a person can do with an open attempt from the activity page (issue #5).
 *
 * Each action names the attempt it is meant for and runs under the lock of item administration: a double
 * click, a reload or a second tab then finds the attempt already ended - or the question already replaced
 * - and does nothing more. Ending goes through the completion every other end uses.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resume_actions {
    /**
     * Ends the open attempt to start a new one.
     *
     * @param stdClass $adaptivequiz
     * @param context_module $context
     * @param int $userid
     * @param int $attemptid The attempt the person chose to end.
     * @return bool Whether it was ended now; false when it had ended already or is not the person's.
     */
    public static function restart(stdClass $adaptivequiz, context_module $context, int $userid, int $attemptid): bool {
        return self::locked($adaptivequiz, $userid, function () use ($adaptivequiz, $context, $userid, $attemptid): bool {
            $attempt = self::own_open_attempt($adaptivequiz, $userid, $attemptid);
            if ($attempt === null) {
                return false;
            }
            return adaptivequiz_complete_attempt_by_id(
                $attemptid,
                $adaptivequiz,
                $context,
                get_string('stopnotice_restarted', 'adaptivequiz'),
                stop_reason::RESTARTED
            );
        });
    }

    /**
     * Ends an attempt whose time is over, as the time limit would have (issue #5).
     *
     * @param stdClass $adaptivequiz
     * @param context_module $context
     * @param int $userid
     * @param int $attemptid
     * @return bool Whether it was ended now.
     */
    public static function end_expired(stdClass $adaptivequiz, context_module $context, int $userid, int $attemptid): bool {
        return self::locked($adaptivequiz, $userid, function () use ($adaptivequiz, $context, $userid, $attemptid): bool {
            $attempt = self::own_open_attempt($adaptivequiz, $userid, $attemptid);
            if ($attempt === null || resume_state::classify($adaptivequiz, $attempt) !== resume_state::EXPIRED) {
                return false;
            }
            return adaptivequiz_complete_attempt_by_id(
                $attemptid,
                $adaptivequiz,
                $context,
                get_string('stopnotice_timelimit', 'adaptivequiz'),
                stop_reason::TIMELIMIT
            );
        });
    }

    /**
     * Gives up the question whose time is over, so that the attempt goes on with a new one (issue #5).
     *
     * The question is finished unanswered - it counts as not solved, as for anyone who gives up - and
     * the next request administers a new question instead of showing it again. No slot is added here.
     *
     * @param stdClass $adaptivequiz
     * @param int $userid
     * @param int $attemptid
     * @return bool Whether a question was given up now.
     */
    public static function new_question(stdClass $adaptivequiz, int $userid, int $attemptid): bool {
        return self::locked($adaptivequiz, $userid, function () use ($adaptivequiz, $userid, $attemptid): bool {
            global $DB;
            $attempt = self::own_open_attempt($adaptivequiz, $userid, $attemptid);
            if ($attempt === null || resume_state::classify($adaptivequiz, $attempt) !== resume_state::ITEM_EXPIRED) {
                return false;
            }
            $pending = resume_state::pending_slot($attempt);
            $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->uniqueid);
            $quba->finish_question($pending['slot'], time());
            \question_engine::save_questions_usage_by_activity($quba);
            // Counted like an answered question: the CAT model scores it as not solved.
            $DB->update_record('adaptivequiz_attempt', (object) [
                'id' => $attempt->id,
                'questionsattempted' => (int) $attempt->questionsattempted + 1,
                'timemodified' => time(),
            ]);
            return true;
        });
    }

    /**
     * The open attempt of the given id, if it is the person's.
     *
     * @param stdClass $adaptivequiz
     * @param int $userid
     * @param int $attemptid
     * @return stdClass|null
     */
    private static function own_open_attempt(stdClass $adaptivequiz, int $userid, int $attemptid): ?stdClass {
        global $DB;
        $attempt = $DB->get_record('adaptivequiz_attempt', [
            'id' => $attemptid, 'instance' => $adaptivequiz->id, 'userid' => $userid,
            'attemptstate' => attempt_state::IN_PROGRESS,
        ]);
        return $attempt ?: null;
    }

    /**
     * Runs an action under the lock of the person's item administration.
     *
     * @param stdClass $adaptivequiz
     * @param int $userid
     * @param callable $action
     * @return bool What the action returns; false when the lock could not be had.
     */
    private static function locked(stdClass $adaptivequiz, int $userid, callable $action): bool {
        global $CFG;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
        $lock = lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_itemadministration_' . $adaptivequiz->id . '_' . $userid, cat_session::$locktimeout);
        if (!$lock) {
            return false;
        }
        try {
            return $action();
        } finally {
            $lock->release();
        }
    }
}
