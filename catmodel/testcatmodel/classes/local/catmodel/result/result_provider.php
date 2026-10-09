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

namespace adaptivequizcatmodel_testcatmodel\local\catmodel\result;

use mod_adaptivequiz\local\result\attempt_result;
use mod_adaptivequiz\local\result\result_definition;
use mod_adaptivequiz\local\result\result_provider as host_result_provider;
use stdClass;

/**
 * Results of the test CAT model, set by the test.
 *
 * The range comes from the plugin setting 'resultdefinition' (JSON: supportsgrading, lower,
 * upper, link), the result of an attempt from what the test registered for its question usage
 * with {@see self::set_result()}. An attempt nothing was registered for is not finalised.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result_provider implements host_result_provider {
    /**
     * Registers the result the provider reports for an attempt.
     *
     * @param int $uniqueid Question usage id of the attempt.
     * @param attempt_result $result
     */
    public static function set_result(int $uniqueid, attempt_result $result): void {
        $GLOBALS['adaptivequizcatmodel_testcatmodel_results'][$uniqueid] = $result;
    }

    /**
     * Forgets all registered results.
     */
    public static function reset(): void {
        $GLOBALS['adaptivequizcatmodel_testcatmodel_results'] = [];
    }

    /**
     * The range from the plugin setting, -4 to 4 by default.
     *
     * @param stdClass $adaptivequiz
     * @return result_definition
     */
    public function get_result_definition(stdClass $adaptivequiz): result_definition {
        $config = json_decode((string) get_config('adaptivequizcatmodel_testcatmodel', 'resultdefinition'), true) ?: [];
        $config += ['supportsgrading' => true, 'lower' => -4.0, 'upper' => 4.0, 'link' => 'linear'];
        return new result_definition(
            (bool) $config['supportsgrading'],
            $config['lower'] === null ? null : (float) $config['lower'],
            $config['upper'] === null ? null : (float) $config['upper'],
            'Test CAT model',
            (string) $config['link']
        );
    }

    /**
     * What the test registered for the attempt.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attempt
     * @return attempt_result
     */
    public function get_attempt_result(stdClass $adaptivequiz, stdClass $attempt): attempt_result {
        return $GLOBALS['adaptivequizcatmodel_testcatmodel_results'][(int) $attempt->uniqueid]
            ?? attempt_result::invalid('not_finalised');
    }
}
