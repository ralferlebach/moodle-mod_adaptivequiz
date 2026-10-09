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
 * Where the results of an activity come from (issue #14).
 *
 * The engine decides what a result is: whether it is valid, its score, the range of the score.
 * The host decides what Moodle does with it: the percentage, the gradebook, the pass mark, which
 * of several attempts counts, completion. The built-in algorithm and every CAT model answer the
 * same two questions through this interface, and the host treats their answers the same way.
 *
 * A CAT model offers a provider by implementing this interface below its
 * local\catmodel\result namespace.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface result_provider {
    /**
     * What the results of this activity look like.
     *
     * Called with the instance record, and from the activity form with the submitted settings
     * merged into it - a provider reads what it needs and must not rely on the record being saved.
     *
     * @param stdClass $adaptivequiz The instance record, or the form data merged into it.
     * @return result_definition
     */
    public function get_result_definition(stdClass $adaptivequiz): result_definition;

    /**
     * The result of one completed attempt.
     *
     * Called once the attempt is complete and the CAT model has been told so, i.e. after its
     * post_complete_attempt_callback. Must answer without the result page ever being opened.
     *
     * @param stdClass $adaptivequiz The instance record.
     * @param stdClass $attempt The adaptivequiz_attempt record.
     * @return attempt_result
     */
    public function get_attempt_result(stdClass $adaptivequiz, stdClass $attempt): attempt_result;
}
