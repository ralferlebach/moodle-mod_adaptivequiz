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

namespace mod_adaptivequiz\local\catmodel;

/**
 * A CAT model cannot go on with an attempt, and says why in words for the person (CAT model, upstream issue #96).
 *
 * Thrown by a CAT model while it selects the next item. The activity catches exactly this type: the person
 * goes back to the activity page with the message, the attempt stays as it is - not completed, nothing
 * changed. Any other exception is an error and shown as such.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class catmodel_session_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param string $usermessage The message for the person, already in their language.
     * @param string|null $debuginfo For developers and the log only.
     */
    public function __construct(string $usermessage, ?string $debuginfo = null) {
        parent::__construct('catmodelsessionerror', 'adaptivequiz', '', $usermessage, $debuginfo);
    }

    /**
     * The message for the person - without the debug information that the exception message carries
     * when debugging is on.
     *
     * @return string
     */
    public function get_user_message(): string {
        return (string) $this->a;
    }
}
