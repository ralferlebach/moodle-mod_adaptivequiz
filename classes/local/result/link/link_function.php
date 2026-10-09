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
 * Maps a score on a range onto a share of that range (issue #14).
 *
 * The host owns the mapping; an engine only names the function it wants by its identifier. The
 * caller has checked the range (upper > lower, both finite) and clamps the outcome to 0-100.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface link_function {
    /**
     * The identifier an engine uses to ask for this function.
     *
     * @return string
     */
    public function get_id(): string;

    /**
     * The share of the range the score reaches, in percent, before clamping.
     *
     * @param float $score
     * @param float $lower
     * @param float $upper
     * @return float
     */
    public function percentage(float $score, float $lower, float $upper): float;
}
