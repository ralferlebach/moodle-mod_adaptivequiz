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
 * Maps a score on a range onto a share of that range, in percent (issues #14, #25).
 *
 * The host owns the mapping; the activity chooses the function, an engine names its default. The caller has checked
 * the range (upper > lower, both finite) and clamps the outcome to 0-100. A function that cannot compute a value -
 * a parameter it needs is missing, the test information is unusable - returns null: no grade, never a substitute.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface link_function {
    /**
     * The identifier an activity or an engine uses to ask for this function.
     *
     * @return string
     */
    public function get_id(): string;

    /**
     * The version of the mapping behind the identifier.
     *
     * Raised whenever the same identifier would map a score differently. A snapshot records the
     * version it was taken with, so a later change is visible and never applied to it silently.
     *
     * @return int
     */
    public function get_version(): int;

    /**
     * The parameters this function cannot do without.
     *
     * @return string[] link_parameters::PASS, link_parameters::INFORMATION
     */
    public function required_parameters(): array;

    /**
     * The share of the range the score reaches, in percent, before clamping.
     *
     * @param float $score
     * @param link_parameters $parameters
     * @return float|null Null when the function cannot compute a value with these parameters.
     */
    public function percentage(float $score, link_parameters $parameters): ?float;
}
