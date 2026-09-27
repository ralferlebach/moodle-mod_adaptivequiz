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

namespace mod_adaptivequiz\local\catmodel\itemadministration;

use stdClass;

/**
 * Lets a CAT model decide whether its item bank is ready for an attempt.
 *
 * The host's own check - question banks linked to the instance, item administration parameters
 * valid - describes the host's built-in algorithm. A CAT model that administers its items itself
 * keeps them elsewhere and has its own notion of readiness; judged by the host's rules, it was
 * never ready, and no attempt could be started. A CAT model that implements this answers for
 * itself; one that does not keeps the host's check.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface catmodel_item_bank_readiness {
    /**
     * Returns whether an attempt can be started for this instance as far as items are concerned.
     *
     * @param stdClass $adaptivequiz The instance record.
     * @return bool
     */
    public function is_item_bank_ready(stdClass $adaptivequiz): bool;
}
