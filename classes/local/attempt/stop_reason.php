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
 * Why an attempt ended, as a code - and whether the participant is told on the result page (issue #20).
 *
 * The text in attemptstopcriteria is a message, written in the language of the request that ended
 * the attempt; nothing is decided by reading it. The code is stored next to it
 * (adaptivequiz_attempt.stopcode) by whoever ends the attempt: the host, its built-in algorithm or
 * the CAT model.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stop_reason {
    /** @var string The maximum number of questions was reached: the regular end. */
    public const MAXIMUM = 'maximumquestions';

    /** @var string The measurement is precise enough before the maximum: an end that may come early. */
    public const TARGET_PRECISION = 'targetprecision';

    /** @var string The time for the attempt ran out. */
    public const TIMELIMIT = 'timelimit';

    /** @var string No suitable question was left. */
    public const NO_ELIGIBLE = 'noeligiblequestions';

    /** @var string A teacher closed the attempt. */
    public const MANUAL = 'manual';

    /** @var string The participant ended it to start a new attempt (issue #5). */
    public const RESTARTED = 'restarted';

    /** @var string A technical problem ended the attempt. */
    public const ERROR = 'error';

    /** @var string No notice: the participant expects this end. */
    public const NOTICE_NONE = 'none';

    /** @var string A notice explaining an end the participant may not expect. */
    public const NOTICE_INFO = 'info';

    /** @var string A notice about a technical problem - never shown as a regular end. */
    public const NOTICE_ERROR = 'error';

    /**
     * Whether, and how, the participant is told about this end.
     *
     * The maximum is what a test is meant to end with. An attempt without a code ended before codes
     * were stored: nobody knows why, so nothing is claimed. Any other end - a precise result before
     * the maximum included, which feels just as sudden - gets a notice, a neutral one for a code not
     * known here.
     *
     * @param string|null $code
     * @return string One of the NOTICE_* constants.
     */
    public static function notice_kind(?string $code): string {
        if ($code === null || $code === '' || $code === self::MAXIMUM) {
            return self::NOTICE_NONE;
        }
        return $code === self::ERROR ? self::NOTICE_ERROR : self::NOTICE_INFO;
    }

    /**
     * Title and text of the notice for an attempt, or null when there is none.
     *
     * @param stdClass $attempt adaptivequiz_attempt record.
     * @param int|null $timelimit The attempt's time limit in seconds, when the CAT model knows it.
     * @return stdClass|null title, text, kind and code.
     */
    public static function notice(stdClass $attempt, ?int $timelimit = null): ?stdClass {
        $code = $attempt->stopcode ?? null;
        $kind = self::notice_kind($code);
        if ($kind === self::NOTICE_NONE) {
            return null;
        }

        $known = [self::TIMELIMIT, self::NO_ELIGIBLE, self::TARGET_PRECISION, self::MANUAL, self::RESTARTED, self::ERROR];
        $key = in_array($code, $known, true) ? $code : 'unknown';
        $text = get_string('stopnotice_' . $key, 'adaptivequiz');
        // The configured limit, not "finished minus started": an attempt closed by the scheduled task or
        // submitted late finishes long after its limit, and the notice named that as its duration.
        if ($key === self::TIMELIMIT && $timelimit !== null && $timelimit > 0) {
            $text .= ' ' . get_string('stopnotice_timelimit_limit', 'adaptivequiz', format_time($timelimit));
        }
        return (object) [
            'code' => (string) $code,
            'kind' => $kind,
            'title' => get_string('stopnoticetitle_' . ($kind === self::NOTICE_ERROR ? 'error' : 'info'), 'adaptivequiz'),
            'text' => $text,
        ];
    }

    /**
     * The attempt's time limit in seconds, as its CAT model reports it; null when there is none or it is unknown.
     *
     * @param stdClass $adaptivequiz The activity record.
     * @param stdClass $attempt adaptivequiz_attempt record.
     * @return int|null
     */
    public static function time_limit(stdClass $adaptivequiz, stdClass $attempt): ?int {
        if (empty($adaptivequiz->catmodel)) {
            return null;
        }
        $functions = get_plugin_list_with_function('adaptivequizcatmodel', 'attempt_time_limit');
        $function = $functions['adaptivequizcatmodel_' . $adaptivequiz->catmodel] ?? null;
        if ($function === null) {
            return null;
        }
        $limit = $function($adaptivequiz, $attempt);
        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }
}
