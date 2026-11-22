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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Behat\Hook\Scope\AfterScenarioScope;

/**
 * local_catquiz #176 (re-audit of 20.11.2026), for the features of mod_adaptivequiz: which scenarios actually ran,
 * and with which result.
 *
 * The evidence of the CI checked the required features by their file - present, tagged, not skipped - but not that
 * they ran: a feature the run did not select would have passed. With ADAPTIVEQUIZ_BEHAT_EXECUTED set to a file, every
 * scenario of the run appends one line of JSON to it when it ends: the feature file, the line of the scenario (of the
 * example row for an outline), its title and its result. .github/scripts/ci_evidence.py checks the required features
 * against it. Without the variable nothing is written.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_adaptivequiz_evidence extends behat_base {
    /**
     * Records the scenario that just ended.
     *
     * @AfterScenario
     * @param AfterScenarioScope $scope
     */
    public function record_executed_scenario(AfterScenarioScope $scope): void {
        $path = getenv('ADAPTIVEQUIZ_BEHAT_EXECUTED');
        if (!$path) {
            return;
        }
        $scenario = $scope->getScenario();
        $result = $scope->getTestResult();
        $line = json_encode([
            'feature' => basename((string) $scope->getFeature()->getFile()),
            'line' => $scenario->getLine(),
            'title' => (string) $scenario->getTitle(),
            'result' => self::result_name($result->getResultCode()),
        ]);
        file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * The name of a result code of Behat.
     *
     * @param int $code
     * @return string
     */
    private static function result_name(int $code): string {
        switch ($code) {
            case \Behat\Testwork\Tester\Result\TestResult::PASSED:
                return 'passed';
            case \Behat\Testwork\Tester\Result\TestResult::SKIPPED:
                return 'skipped';
            case \Behat\Testwork\Tester\Result\TestResult::PENDING:
                return 'pending';
            case \Behat\Testwork\Tester\Result\TestResult::FAILED:
                return 'failed';
            default:
                return 'code ' . $code;
        }
    }
}
