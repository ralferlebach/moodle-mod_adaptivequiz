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
 * The host brings 'linear'. A CAT model can add its own: every class in its namespace
 * local\catmodel\result\link that implements link_function is offered, under its identifier, to
 * the gradebook and the pass decision alike - without a line of grading code of its own. An
 * identifier offered twice is a configuration error (identifiers are stable; a CAT model cannot
 * redefine 'linear'), and so is an identifier the host does not know: there is no fallback to
 * linear, because a silently substituted mapping would put wrong grades into the gradebook
 * without anyone noticing.
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
     * All link functions: the host's own and those the installed CAT models offer.
     *
     * @return link_function[]
     * @throws result_configuration_exception When two of them use the same identifier.
     */
    public static function all(): array {
        $functions = [new linear_link_function()];
        foreach (array_keys(\core_component::get_plugin_list('adaptivequizcatmodel')) as $catmodel) {
            $classes = \core_component::get_component_classes_in_namespace(
                'adaptivequizcatmodel_' . $catmodel,
                'local\\catmodel\\result\\link'
            );
            foreach (array_keys($classes) as $classname) {
                if (is_subclass_of($classname, link_function::class) && !(new \ReflectionClass($classname))->isAbstract()) {
                    $functions[] = new $classname();
                }
            }
        }

        $ids = [];
        foreach ($functions as $function) {
            if (isset($ids[$function->get_id()])) {
                throw new result_configuration_exception('resulterrorlinkfunctionduplicate', $function->get_id());
            }
            $ids[$function->get_id()] = true;
        }
        return $functions;
    }
}
