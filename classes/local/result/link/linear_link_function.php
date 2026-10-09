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
 * The linear mapping: 100 * (score - lower) / (upper - lower).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class linear_link_function implements link_function {
    /** @var string Identifier of this function. */
    public const ID = 'linear';

    /**
     * The identifier an engine uses to ask for this function.
     *
     * @return string
     */
    public function get_id(): string {
        return self::ID;
    }

    /**
     * The share of the range the score reaches, in percent, before clamping.
     *
     * @param float $score
     * @param float $lower
     * @param float $upper
     * @return float
     */
    public function percentage(float $score, float $lower, float $upper): float {
        return 100.0 * ($score - $lower) / ($upper - $lower);
    }
}
