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

/**
 * Callbacks of the neutral CAT model used to test the subplugin contract.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns the URL of the attempts report this CAT model offers.
 *
 * @param stdClass $adaptivequiz The activity instance record.
 * @param stdClass $cm The course module record of that activity.
 * @return moodle_url
 */
function adaptivequizcatmodel_testcatmodel_attempts_report_url(stdClass $adaptivequiz, stdClass $cm): moodle_url {
    return new moodle_url('/mod/adaptivequiz/view.php', ['id' => $cm->id, 'testcatmodelreport' => 1]);
}
