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

use mod_adaptivequiz\local\result\link\linear_link_function;

/**
 * The result of one completed attempt, as its engine reports it (issue #14).
 *
 * A valid result carries a score and the range it was measured on - the range of that moment, so a
 * later change of the activity does not reinterpret it. An invalid result carries no score at all:
 * whatever diagnostic value the engine computed stays with the engine and never reaches the
 * gradebook, not even as 0.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attempt_result {
    /** @var string Status written for a valid result. */
    public const STATUS_VALID = 'valid';

    /** @var string Status written for an invalid result. */
    public const STATUS_INVALID = 'invalid';

    /**
     * Constructor - use {@see self::valid()} or {@see self::invalid()}.
     *
     * @param bool $valid
     * @param float|null $score
     * @param float|null $lower
     * @param float|null $upper
     * @param string $reason
     * @param string $linkfunction
     */
    private function __construct(
        /** @var bool Whether the engine considers the result valid. */
        public readonly bool $valid,
        /** @var float|null The score, in the engine's units; null for an invalid result. */
        public readonly ?float $score,
        /** @var float|null Lower bound of the range the score was measured on. */
        public readonly ?float $lower,
        /** @var float|null Upper bound of the range the score was measured on. */
        public readonly ?float $upper,
        /** @var string Machine-readable reason, empty for a valid result. */
        public readonly string $reason,
        /** @var string Identifier of the link function that maps the score onto 0-100 %. */
        public readonly string $linkfunction
    ) {
    }

    /**
     * A valid result.
     *
     * @param float $score The score, in the engine's units.
     * @param float $lower Lower bound of the range at the time of the attempt.
     * @param float $upper Upper bound of the range at the time of the attempt.
     * @param string $linkfunction Identifier of the link function.
     * @return self
     */
    public static function valid(float $score, float $lower, float $upper, string $linkfunction = linear_link_function::ID): self {
        return new self(true, $score, $lower, $upper, '', $linkfunction);
    }

    /**
     * An invalid result: no score, only the reason.
     *
     * @param string $reason Machine-readable reason, e.g. 'no_answer' or 'fraction_all_correct'.
     * @return self
     */
    public static function invalid(string $reason): self {
        return new self(false, null, null, null, $reason, linear_link_function::ID);
    }
}
