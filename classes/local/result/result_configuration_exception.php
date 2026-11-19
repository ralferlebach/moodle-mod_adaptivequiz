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

use moodle_exception;

/**
 * A result source that is configured in a way no grade can be computed from (issue #14).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_configuration_exception extends moodle_exception {
    /**
     * Constructor.
     *
     * @param string $errorcode String identifier in mod_adaptivequiz.
     * @param mixed $a Value for the string.
     */
    public function __construct(string $errorcode, $a = null) {
        parent::__construct($errorcode, 'adaptivequiz', '', $a);
    }
}
