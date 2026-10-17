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

use stdClass;

/**
 * What can be done with the open attempt of a person, decided on the server (issue #5).
 *
 * An attempt in progress used to be continued silently by "Start attempt", however long ago it was left.
 * The activity page now asks: continue, or end it and start anew. Two clocks change the offer. When the
 * time for the attempt is over, the attempt is ended at once - with the same completion as any other end
 * - and only a new start is offered. When only the time for the question on the screen is over, the
 * attempt can go on with a new question. The limits come from the CAT model; the built-in algorithm sets
 * none, so its attempts are always resumable.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resume_state {
    /** @var string No attempt is open. */
    public const NONE = 'none';

    /** @var string The open attempt can be continued where it was left. */
    public const RESUMABLE = 'resumable';

    /** @var string The time for the pending question is over; the attempt goes on with a new one. */
    public const ITEM_EXPIRED = 'itemexpired';

    /** @var string The time for the attempt is over: it is ended, a new start is offered. */
    public const EXPIRED = 'expired';

    /** @var array|null Limits ['attempt' => s, 'item' => s] in place of the CAT model's, for tests only. */
    public static ?array $testlimits = null;

    /**
     * The open attempt of a person in an activity, the newest if - from an older version - there are several.
     *
     * @param stdClass $adaptivequiz
     * @param int $userid
     * @return stdClass|null adaptivequiz_attempt record.
     */
    public static function open_attempt(stdClass $adaptivequiz, int $userid): ?stdClass {
        global $DB;
        $records = $DB->get_records(
            'adaptivequiz_attempt',
            ['instance' => $adaptivequiz->id, 'userid' => $userid, 'attemptstate' => attempt_state::IN_PROGRESS],
            'timemodified DESC, id DESC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * Classifies an open attempt.
     *
     * @param stdClass $adaptivequiz The activity record.
     * @param stdClass|null $attempt The open attempt, null for none.
     * @param int|null $now The time to judge by; now when null.
     * @return string One of the constants.
     */
    public static function classify(stdClass $adaptivequiz, ?stdClass $attempt, ?int $now = null): string {
        if ($attempt === null || $attempt->attemptstate !== attempt_state::IN_PROGRESS) {
            return self::NONE;
        }
        $now ??= time();

        $attemptlimit = self::$testlimits !== null ? (self::$testlimits['attempt'] ?? null)
            : stop_reason::time_limit($adaptivequiz, $attempt);
        if ($attemptlimit !== null && $now - (int) $attempt->timecreated > $attemptlimit) {
            return self::EXPIRED;
        }

        $itemlimit = self::item_time_limit($adaptivequiz, $attempt);
        $pending = self::pending_slot($attempt);
        if ($itemlimit !== null && $pending !== null && $now - $pending['since'] > $itemlimit) {
            return self::ITEM_EXPIRED;
        }
        return self::RESUMABLE;
    }

    /**
     * The question on the screen that was not answered yet, and since when it is shown.
     *
     * @param stdClass $attempt adaptivequiz_attempt record.
     * @return array|null ['slot' => int, 'since' => int], null when no question is pending.
     */
    public static function pending_slot(stdClass $attempt): ?array {
        if (empty($attempt->uniqueid)) {
            return null;
        }
        $quba = \question_engine::load_questions_usage_by_activity((int) $attempt->uniqueid);
        $slots = $quba->get_slots();
        if (!$slots) {
            return null;
        }
        $slot = (int) end($slots);
        if (!$quba->get_question_state($slot)->is_active()) {
            return null;
        }
        // Shown with its first step.
        return ['slot' => $slot, 'since' => (int) $quba->get_question_attempt($slot)->get_step(0)->get_timecreated()];
    }

    /**
     * The time for one question, as the CAT model reports it; null when there is none.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attempt
     * @return int|null
     */
    public static function item_time_limit(stdClass $adaptivequiz, stdClass $attempt): ?int {
        if (self::$testlimits !== null) {
            return self::$testlimits['item'] ?? null;
        }
        if (empty($adaptivequiz->catmodel)) {
            return null;
        }
        $functions = get_plugin_list_with_function('adaptivequizcatmodel', 'item_time_limit');
        $function = $functions['adaptivequizcatmodel_' . $adaptivequiz->catmodel] ?? null;
        if ($function === null) {
            return null;
        }
        $limit = $function($adaptivequiz, $attempt);
        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }
}
