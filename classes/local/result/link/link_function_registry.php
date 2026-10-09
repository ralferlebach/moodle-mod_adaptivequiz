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

use mod_adaptivequiz\local\result\result_configuration_exception;

/**
 * The link functions the host knows (issue #14).
 *
 * For now there is one, 'linear'. An identifier the host does not know is a configuration error:
 * there is no fallback to linear, because a silently substituted mapping would put wrong grades
 * into the gradebook without anyone noticing.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class link_function_registry {
    /**
     * The link function with the given identifier.
     *
     * @param string $id
     * @return link_function
     * @throws result_configuration_exception For an identifier the host does not know.
     */
    public static function get(string $id): link_function {
        foreach (self::all() as $function) {
            if ($function->get_id() === $id) {
                return $function;
            }
        }
        throw new result_configuration_exception('resulterrorlinkfunction', $id);
    }

    /**
     * All link functions, keyed by nothing in particular.
     *
     * @return link_function[]
     */
    public static function all(): array {
        return [new linear_link_function()];
    }
}
