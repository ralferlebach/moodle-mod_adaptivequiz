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

use stdClass;

/**
 * A result source that knows the test information of the item battery of an activity (issue #25).
 *
 * The Fisher-Rao link function needs I_B(t) = sum of the item information at ability t, for the battery as it is
 * calibrated now - not as it was when an attempt ran: items and settings are not versioned.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface test_information_source {
    /**
     * The test information of the activity's item battery.
     *
     * @param stdClass $adaptivequiz
     * @return \Closure|null fn(float $t): float, finite and non-negative; null when the battery has no usable items.
     */
    public function test_information(stdClass $adaptivequiz): ?\Closure;
}
