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
 * What the results of an activity look like: whether they can be graded, and on which range.
 *
 * The range is in the units of the engine that produces the results - logits, scale points,
 * whatever it measures in. The host never interprets the units; it only maps a score between
 * lower and upper onto 0-100 % (issue #14).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result_definition {
    /**
     * Constructor.
     *
     * @param bool $supportsgrading Whether the results are meant for the gradebook at all.
     * @param float|null $lower Lowest score of the range, in the engine's units.
     * @param float|null $upper Highest score of the range, in the engine's units.
     * @param string $label Human-readable name of the result source, shown in the settings.
     * @param string $linkfunction Identifier of the function that maps a score onto 0-100 %.
     */
    public function __construct(
        /** @var bool Whether the results are meant for the gradebook at all. */
        public readonly bool $supportsgrading,
        /** @var float|null Lowest score of the range. */
        public readonly ?float $lower,
        /** @var float|null Highest score of the range. */
        public readonly ?float $upper,
        /** @var string Human-readable name of the result source. */
        public readonly string $label,
        /** @var string Identifier of the link function. */
        public readonly string $linkfunction = linear_link_function::ID
    ) {
    }

    /**
     * A source whose results do not go to the gradebook.
     *
     * @param string $label Human-readable name of the result source.
     * @return self
     */
    public static function not_gradable(string $label): self {
        return new self(false, null, null, $label);
    }

    /**
     * Whether the range can carry a percentage: both bounds finite, upper above lower.
     *
     * A range with upper <= lower is a configuration error, not a case to be rescued with an
     * epsilon - it yields no grade and no pass/fail.
     *
     * @return bool
     */
    public function has_valid_range(): bool {
        return self::is_valid_range($this->lower, $this->upper);
    }

    /**
     * Whether two bounds form a range a percentage can be computed on.
     *
     * @param float|null $lower
     * @param float|null $upper
     * @return bool
     */
    public static function is_valid_range(?float $lower, ?float $upper): bool {
        return $lower !== null && $upper !== null && is_finite($lower) && is_finite($upper) && $upper > $lower;
    }

    /**
     * Whether results can be graded: the source says so and the range is usable.
     *
     * @return bool
     */
    public function is_gradable(): bool {
        return $this->supportsgrading && $this->has_valid_range();
    }
}
