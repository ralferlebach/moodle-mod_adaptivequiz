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
 * The logistic function in percent, without overflow for any finite argument (issue #25).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class logistic {
    /**
     * 100 / (1 + exp(-z)).
     *
     * exp() is only ever called with a non-positive argument, so it never overflows; for large |z| the result is
     * 0 or 100 exactly where the double runs out of precision.
     *
     * @param float $z
     * @return float
     */
    public static function percent(float $z): float {
        if ($z >= 0) {
            return 100.0 / (1.0 + exp(-$z));
        }
        $e = exp($z);
        return 100.0 * $e / (1.0 + $e);
    }
}
