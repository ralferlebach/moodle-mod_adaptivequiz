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

namespace adaptivequizcatmodel_testcatmodel\local\catmodel\result\link;

use mod_adaptivequiz\local\result\link\link_function;

/**
 * A link function a CAT model brings itself (issue #14), for the tests of the host's registry.
 *
 * Version 1 squares the linear share, version 2 takes its square root. The version comes from the
 * plugin setting 'linkversion', so a test can change the mapping behind the identifier.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class test_square_link_function implements link_function {
    /** @var string Identifier of this function. */
    public const ID = 'testsquare';

    /**
     * The identifier.
     *
     * @return string
     */
    public function get_id(): string {
        return self::ID;
    }

    /**
     * The version from the plugin setting, 1 by default.
     *
     * @return int
     */
    public function get_version(): int {
        return (int) (get_config('adaptivequizcatmodel_testcatmodel', 'linkversion') ?: 1);
    }

    /**
     * Version 1: square of the linear share; version 2: its square root.
     *
     * @param float $score
     * @param float $lower
     * @param float $upper
     * @return float
     */
    public function percentage(float $score, float $lower, float $upper): float {
        $share = ($score - $lower) / ($upper - $lower);
        return 100.0 * ($this->get_version() === 2 ? sqrt(max(0.0, $share)) : $share * $share);
    }
}
