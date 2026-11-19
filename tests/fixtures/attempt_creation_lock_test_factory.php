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

namespace mod_adaptivequiz\local;

/**
 * A lock factory that lets a test act as the other request, at the moment the lock is asked for.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_creation_lock_test_factory extends \core\lock\db_record_lock_factory {
    /** @var callable|null Runs before a lock is taken, with the resource. */
    public static $before = null;

    /** @var string[] The resources locks were asked for. */
    public static array $resources = [];

    /**
     * Takes a lock - after the other request had its turn.
     *
     * @param string $resource
     * @param int $timeout
     * @param int $maxlifetime
     * @return \core\lock\lock|false
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        self::$resources[] = $resource;
        if (self::$before) {
            (self::$before)($resource);
        }
        return parent::get_lock($resource, $timeout, $maxlifetime);
    }
}
