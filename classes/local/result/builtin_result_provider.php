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

namespace mod_adaptivequiz\local\result;

use mod_adaptivequiz\local\catalgo;
use stdClass;

/**
 * The results of the host's own algorithm, through the same contract as any CAT model (issue #14).
 *
 * The score is the ability measure mapped onto the difficulty levels of the activity, as the
 * gradebook always showed it; the range is lowest to highest level. A completed attempt without a
 * single answered question has measured nothing and is invalid - it used to get the grade of an
 * ability of 0, in the middle of the range.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class builtin_result_provider implements result_provider {
    /** @var string Reason for an attempt without any answered question. */
    public const REASON_NO_ANSWER = 'no_answer';

    /**
     * Lowest to highest difficulty level of the activity.
     *
     * @param stdClass $adaptivequiz
     * @return result_definition
     */
    public function get_result_definition(stdClass $adaptivequiz): result_definition {
        $label = get_string('resultsourcebuiltin', 'adaptivequiz');
        if (!isset($adaptivequiz->lowestlevel, $adaptivequiz->highestlevel)) {
            return new result_definition(true, null, null, $label);
        }
        return new result_definition(true, (float) $adaptivequiz->lowestlevel, (float) $adaptivequiz->highestlevel, $label);
    }

    /**
     * The ability measure of the attempt on the activity's levels.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attempt
     * @return attempt_result
     */
    public function get_attempt_result(stdClass $adaptivequiz, stdClass $attempt): attempt_result {
        if ((int) ($attempt->questionsattempted ?? 0) <= 0 || !is_numeric($attempt->measure ?? null)) {
            return attempt_result::invalid(self::REASON_NO_ANSWER);
        }

        $lower = (float) $adaptivequiz->lowestlevel;
        $upper = (float) $adaptivequiz->highestlevel;
        $score = (float) catalgo::map_logit_to_scale((float) $attempt->measure, $upper, $lower);

        return attempt_result::valid($score, $lower, $upper);
    }
}
