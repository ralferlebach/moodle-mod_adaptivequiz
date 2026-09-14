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

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\ExpectationException;

/**
 * Custom steps definition.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_adaptivequiz extends behat_base {
    /**
     * Wraps testing of what's displayed as debugging info for the current attempt.
     *
     * @Then /^I should see the following debugging info for the attempt:$/
     *
     * Accepts a table in the following format (all fields are required):
     * | difficultysum | standarderrorraw | measureraw | attemptstopcriteria |
     *
     * Data row example (with a stoppage reason):
     * | -0.5877867 | 0.00000 | 0.00000 | Unable to fetch a question for level 1 |
     *
     * Another data row example (without a stoppage reason):
     * | -0.5877867 | 0.00000 | 0.00000 | none |
     *
     * Only one row is expected per step.
     *
     * @param TableNode $data Debugging info.
     */
    public function i_should_see_attempt_debugging_info(TableNode $data) {
        $attemptinfo = $data->getHash();

        if (count($attemptinfo) > 1) {
            throw new ExpectationException('When debugging an attempt, ' .
                'only one row of attempt params is accepted', $this->getSession());
        }

        $attemptinfo = $attemptinfo[0];

        if (empty($attemptinfo['difficultysum'])) {
            throw new ExpectationException('When debugging an attempt, ' .
                'the \'difficultysum\' column is required', $this->getSession());
        }

        if (empty($attemptinfo['standarderrorraw'])) {
            throw new ExpectationException('When debugging an attempt, ' .
                'the \'standarderrorraw\' column is required', $this->getSession());
        }

        if (empty($attemptinfo['measureraw'])) {
            throw new ExpectationException('When debugging an attempt, ' .
                'the \'measureraw\' column is required', $this->getSession());
        }

        if (empty($attemptinfo['attemptstopcriteria'])) {
            throw new ExpectationException('When debugging an attempt, ' .
                'the \'attemptstopcriteria\' column is required', $this->getSession());
        }

        $difficultysum = get_string('attemptquestion_diffsum', 'adaptivequiz') . ': ' . $attemptinfo['difficultysum'];
        $standarderror = get_string('standarderrorhdr', 'adaptivequiz') . ': ' . $attemptinfo['standarderrorraw'];
        $measure = get_string('attemptquestion_abilitylogits', 'adaptivequiz') . ': ' . $attemptinfo['measureraw'];

        $this->execute(
            "behat_general::should_exist_in_the",
            [ "//div[contains(text(), '{$difficultysum}')]", 'xpath_element', '#attemptdebuginfo', 'css_element']
        );

        $this->execute(
            "behat_general::should_exist_in_the",
            [ "//div[contains(text(), '{$standarderror}')]", 'xpath_element', '#attemptdebuginfo', 'css_element']
        );

        $this->execute(
            "behat_general::should_exist_in_the",
            [ "//div[contains(text(), '{$measure}')]", 'xpath_element', '#attemptdebuginfo', 'css_element']
        );

        if ($attemptinfo['attemptstopcriteria'] === 'none') {
            $stopcriteria = get_string('attemptstopcriteria', 'adaptivequiz');

            $this->execute(
                "behat_general::should_not_exist_in_the",
                [ "//div[contains(text(), '{$stopcriteria}')]", 'xpath_element', '#attemptdebuginfo', 'css_element']
            );

            return;
        }

        $stopcriteria = get_string('attemptstopcriteria', 'adaptivequiz') . ': ' . $attemptinfo['attemptstopcriteria'];

        $this->execute(
            "behat_general::should_exist_in_the",
            [ "//div[contains(text(), '{$stopcriteria}')]", 'xpath_element', '#attemptdebuginfo', 'css_element']
        );
    }

    /**
     * Saves a screenshot of the current page, whatever the outcome of the scenario.
     *
     * Moodle itself only captures a screenshot when a step fails. A passing run leaves no trace at
     * all, so nobody can see what the feature actually produced - which is exactly what a reviewer
     * wants. This step lets a scenario record its own milestones.
     *
     * @Given /^I save a screenshot named "(?P<name_string>(?:[^"]|\\")*)"$/
     * @param string $name File name to save under, without extension.
     */
    public function i_save_a_screenshot_named(string $name): void {
        global $CFG;

        if (!$this->running_javascript()) {
            // Without a real browser there is nothing to capture.
            return;
        }

        $dir = $CFG->behat_faildump_path ?? null;
        if (empty($dir)) {
            throw new ExpectationException(
                'No $CFG->behat_faildump_path is configured, so screenshots cannot be saved.',
                $this->getSession()
            );
        }

        $dir = rtrim($dir, '/') . '/screenshots';
        if (!is_dir($dir)) {
            make_writable_directory($dir);
        }

        $this->saveScreenshot(clean_param($name, PARAM_FILE) . '.png', $dir);
    }
}
