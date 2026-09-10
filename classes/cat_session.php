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

namespace mod_adaptivequiz;

use mod_adaptivequiz\local\catmodel\catmodel_resolver;
use mod_adaptivequiz\local\itemadministration\default_item_administration_factory;
use mod_adaptivequiz\local\itemadministration\item_administration_factory;
use stdClass;

/**
 * The parts of a running CAT session that must not live inside attempt.php.
 *
 * attempt.php is a script: it cannot be instantiated in a test, so anything decided there is
 * decided untested. Whatever can be answered without the request context belongs here instead.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cat_session {
    /**
     * Returns the item administration factory that drives the given activity.
     *
     * The CAT model of the activity if it offers one, the built-in algorithm otherwise. This is
     * the single place where the activity decides who picks the next question.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @return item_administration_factory
     */
    public static function item_administration_factory_for(stdClass $adaptivequiz): item_administration_factory {
        $factory = catmodel_resolver::handler($adaptivequiz->catmodel ?? null, item_administration_factory::class);

        return $factory ?? new default_item_administration_factory();
    }
}
