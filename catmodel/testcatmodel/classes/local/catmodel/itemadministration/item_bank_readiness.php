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

namespace adaptivequizcatmodel_testcatmodel\local\catmodel\itemadministration;

use mod_adaptivequiz\local\catmodel\itemadministration\catmodel_item_bank_readiness;
use stdClass;

/**
 * Readiness of the test CAT model's item bank, switchable for tests.
 *
 * The test CAT model serves its questions itself and does not use the host's item bank. Whether it
 * reports ready is a plugin setting, so a test can check that the host follows the answer in both
 * directions without knowing anything about a real CAT model.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_bank_readiness implements catmodel_item_bank_readiness {
    /**
     * Returns whether an attempt can be started for this instance as far as items are concerned.
     *
     * @param stdClass $adaptivequiz The instance record.
     * @return bool
     */
    public function is_item_bank_ready(stdClass $adaptivequiz): bool {
        $setting = get_config('adaptivequizcatmodel_testcatmodel', 'itembankready');

        // Ready unless a test says otherwise: the model serves its own questions.
        return $setting === false || (bool) $setting;
    }
}
