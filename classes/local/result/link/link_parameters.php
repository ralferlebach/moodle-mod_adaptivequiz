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

namespace mod_adaptivequiz\local\result\link;

/**
 * What a link function may use besides the score (issue #25).
 *
 * The range comes from the result, the pass score and the steepness from the activity, the test information from
 * the result source - for the item battery as it is calibrated now: there is no versioning of items or settings.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class link_parameters {
    /** @var string The pass score. */
    public const PASS = 'pass';

    /** @var string The test information of the item battery. */
    public const INFORMATION = 'information';

    /** @var float Default steepness of the logistic functions. */
    public const DEFAULT_STEEPNESS = 1.0;

    /**
     * Constructor.
     *
     * @param float $lower Lowest score of the range (L).
     * @param float $upper Highest score of the range (U), above $lower.
     * @param float|null $pass Pass score P in the units of the range, if the activity has one.
     * @param float $steepness Steepness k of the logistic functions, above 0.
     * @param \Closure|null $information Test information I_B(t) of the item battery, if the result source offers it.
     */
    public function __construct(
        /** @var float Lowest score of the range. */
        public readonly float $lower,
        /** @var float Highest score of the range. */
        public readonly float $upper,
        /** @var float|null Pass score. */
        public readonly ?float $pass = null,
        /** @var float Steepness k. */
        public readonly float $steepness = self::DEFAULT_STEEPNESS,
        /** @var \Closure|null Test information. */
        public readonly ?\Closure $information = null
    ) {
    }

    /**
     * The same parameters on another range.
     *
     * @param float $lower
     * @param float $upper
     * @return self
     */
    public function with_range(float $lower, float $upper): self {
        return new self($lower, $upper, $this->pass, $this->steepness, $this->information);
    }
}
