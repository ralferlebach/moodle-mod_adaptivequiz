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

    /**
     * Hides the page header, the navigation bars and the breadcrumbs, as a page launched through LTI or embedded
     * shows it (issue #22).
     *
     * @Given /^the page is shown without header and breadcrumbs$/
     */
    public function the_page_is_shown_without_header_and_breadcrumbs(): void {
        if (!$this->running_javascript()) {
            throw new \Moodle\BehatExtension\Exception\SkippedException('Needs JavaScript.');
        }
        $this->execute_script("
            document.querySelectorAll('#page-header, .breadcrumb, nav.navbar, .secondary-navigation, #page-navbar')
                .forEach(function(node) { node.remove(); });
        ");
    }

    /**
     * A finished attempt of a user that ended with a stop code, as the host stores it (issue #20).
     *
     * @Given /^"([^"]*)" has finished an attempt at "([^"]*)" that ended with "([^"]*)"$/
     *
     * @param string $username
     * @param string $idnumber The activity's ID number.
     * @param string $code The stop code.
     */
    public function has_finished_an_attempt_that_ended_with(string $username, string $idnumber, string $code): void {
        global $DB;
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $now = time();
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $cm->instance, 'userid' => $userid, 'uniqueid' => 990000 + $userid, 'attemptstate' => 'complete',
            'attemptstopcriteria' => 'Stopped', 'questionsattempted' => 2, 'difficultysum' => 0, 'standarderror' => 0.3,
            'measure' => 0, 'timecreated' => $now - 600, 'timemodified' => $now, 'timefinished' => $now, 'stopcode' => $code,
        ]);
    }

    /**
     * Opens the result page of the logged-in user's last attempt at an activity (issue #20).
     *
     * @Given /^I open the result page of my last attempt at "([^"]*)"$/
     *
     * @param string $idnumber The activity's ID number.
     */
    public function i_open_the_result_page_of_my_last_attempt_at(string $idnumber): void {
        global $DB;
        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        // The browser's user, not the CLI's $USER.
        $user = $this->get_session_user();
        $attempts = $DB->get_records(
            'adaptivequiz_attempt',
            ['instance' => $cm->instance, 'userid' => $user->id],
            'id DESC',
            'id, uniqueid',
            0,
            1
        );
        $attempt = reset($attempts);
        if (!$attempt) {
            throw new ExpectationException('No attempt at ' . $idnumber, $this->getSession());
        }
        $url = new moodle_url('/mod/adaptivequiz/attemptfinished.php', [
            'cmid' => $cm->id, 'id' => $cm->instance, 'uattid' => $attempt->uniqueid,
        ]);
        $this->execute('behat_general::i_visit', [$url->out_as_local_url(false)]);
    }

    /**
     * Hides an activity from participants (issue #16).
     *
     * @Given /^the adaptivequiz activity "([^"]*)" is hidden from participants$/
     *
     * @param string $idnumber The activity's ID number.
     */
    public function the_adaptivequiz_activity_is_hidden_from_participants(string $idnumber): void {
        global $DB;
        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        set_coursemodule_visible($cm->id, 0);
        rebuild_course_cache($cm->course, true);
    }

    /**
     * Requests the review page (reviewattempt.php) of the last attempt of a user with the session of the browser
     * and tells whether it shows the review (issue #16: the review follows the activity, it is a report page).
     *
     * The request goes past the browser page on purpose: a refused page is a Moodle error page, which would end
     * the scenario. Shown means HTTP 200 with the heading of the review; refused means neither.
     *
     * @Then /^the review page of the last attempt of "([^"]*)" is (shown to|refused to) me$/
     *
     * @param string $username
     * @param string $outcome
     */
    public function the_review_page_of_the_last_attempt_is(string $username, string $outcome): void {
        global $DB;
        $userid = (int) $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $attempts = $DB->get_records('adaptivequiz_attempt', ['userid' => $userid], 'id DESC', 'id', 0, 1);
        $attempt = reset($attempts);
        if (!$attempt) {
            throw new ExpectationException('No attempt of ' . $username, $this->getSession());
        }
        $url = (new moodle_url('/mod/adaptivequiz/reviewattempt.php', ['attempt' => $attempt->id]))->out(false);
        $script = 'return (function() {'
            . ' var x = new XMLHttpRequest(); x.open("GET", ' . json_encode($url) . ', false); x.send();'
            . ' return JSON.stringify({status: x.status, review: x.responseText.indexOf("reviewing attempt by") !== -1,'
            . '   url: x.responseURL});'
            . '})();';
        $result = json_decode((string) $this->getSession()->evaluateScript($script), true);
        $shown = $result['status'] === 200 && $result['review'];
        $report = sprintf(
            '%s: HTTP %d, review: %s, final URL %s',
            $url,
            $result['status'],
            $result['review'] ? 'yes' : 'no',
            $result['url']
        );
        $file = getenv('CATQUIZ_NETWORK_EVIDENCE');
        if ($file) {
            file_put_contents(
                $file,
                date('H:i:s') . ' [' . $this->get_session_user()->username . '] ' . $report . "\n",
                FILE_APPEND
            );
        }
        if (($outcome === 'shown to') !== $shown) {
            throw new ExpectationException("Expected the review to be $outcome me - $report", $this->getSession());
        }
    }

    /**
     * Puts a small PNG into the file area of the attempt feedback, as the editor of the form would (issue #21).
     *
     * @Given /^the attempt feedback of "([^"]*)" has the file "([^"]*)"$/
     *
     * @param string $idnumber The activity's ID number.
     * @param string $filename
     */
    public function the_attempt_feedback_has_the_file(string $idnumber, string $filename): void {
        global $DB;
        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        // A red 4 x 4 PNG.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAEE'
            . 'lEQVR4nGM4o6QERwzEcQDE4xEBYJBBnwAAAABJRU5ErkJggg==');
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cm->id)->id, 'component' => 'mod_adaptivequiz',
            'filearea' => 'attemptfeedback', 'itemid' => 0, 'filepath' => '/', 'filename' => $filename,
        ], $png);
    }

    /**
     * Checks the text of the attempt feedback in the activity form: each row must be contained (issue #21).
     *
     * @Then /^the attempt feedback in the form contains:$/
     *
     * @param TableNode $table One column, one expected piece of text per row.
     */
    public function the_attempt_feedback_in_the_form_contains(TableNode $table): void {
        $text = (string) $this->getSession()->evaluateScript(
            'return (document.querySelector("textarea[name=\'attemptfeedbackeditor[text]\']") || {}).value || "";'
        );
        foreach ($table->getRows() as $row) {
            if (strpos($text, $row[0]) === false) {
                throw new ExpectationException(
                    'Not in the attempt feedback: ' . $row[0] . "\nText: " . $text,
                    $this->getSession()
                );
            }
        }
    }

    /**
     * Adds a hidden field to the form of the page - a request changed in the browser (issue #21).
     *
     * @When /^I add the field "([^"]*)" with the value "([^"]*)" to the form$/
     *
     * @param string $name
     * @param string $value
     */
    public function i_add_the_field_with_the_value_to_the_form(string $name, string $value): void {
        $this->getSession()->executeScript('(function() {'
            . ' var form = document.querySelector("form.mform"); var input = document.createElement("input");'
            . ' input.type = "hidden"; input.name = ' . json_encode($name) . '; input.value = ' . json_encode($value) . ';'
            . ' form.appendChild(input); })();');
    }

    /**
     * Checks an image of the page: decoded by the browser, and its URL answers HTTP 200 with an image from
     * pluginfile.php when requested with the session of the browser (issue #21).
     *
     * @Then /^the image "([^"]*)" on the page was delivered as a file$/
     *
     * @param string $alt
     */
    public function the_image_on_the_page_was_delivered_as_a_file(string $alt): void {
        $alt = json_encode($alt);
        $script = 'return (function() {'
            . ' var img = Array.from(document.querySelectorAll("img"))'
            . '   .find(function(i) { return i.alt === ' . $alt . '; });'
            . ' if (!img) { return JSON.stringify({missing: true}); }'
            . ' if (!img.complete) { return JSON.stringify({loading: true}); }'
            . ' var x = new XMLHttpRequest(); x.open("GET", img.currentSrc || img.src, false); x.send();'
            . ' return JSON.stringify({src: img.currentSrc || img.src, width: img.naturalWidth, status: x.status,'
            . '   type: x.getResponseHeader("Content-Type")});'
            . '})();';
        $this->spin(function () use ($script) {
            $r = json_decode((string) $this->getSession()->evaluateScript($script), true);
            $ok = empty($r['missing']) && empty($r['loading']) && $r['width'] > 0 && $r['status'] === 200
                && strpos((string) $r['type'], 'image/') === 0 && strpos($r['src'], '/pluginfile.php/') !== false;
            if (!$ok) {
                throw new ExpectationException('Image not delivered: ' . json_encode($r), $this->getSession());
            }
            $file = getenv('CATQUIZ_NETWORK_EVIDENCE');
            if ($file) {
                file_put_contents($file, date('H:i:s') . ' feedback image ' . json_encode($r) . "\n", FILE_APPEND);
            }
            return true;
        });
    }

    /**
     * Checks the mark the server stored with the attempt feedback (issue #21).
     *
     * @Then /^the stored attempt feedback of "([^"]*)" is (trusted|not trusted)$/
     *
     * @param string $idnumber The activity's ID number.
     * @param string $state
     */
    public function the_stored_attempt_feedback_is(string $idnumber, string $state): void {
        global $DB;
        $cm = $DB->get_record('course_modules', ['idnumber' => $idnumber], '*', MUST_EXIST);
        $record = $DB->get_record('adaptivequiz', ['id' => $cm->instance], 'attemptfeedback, attemptfeedbacktrusted', MUST_EXIST);
        if ((bool) $record->attemptfeedbacktrusted !== ($state === 'trusted')) {
            throw new ExpectationException("The stored feedback is not $state", $this->getSession());
        }
        if ($state === 'not trusted' && preg_match('~<script|onerror=|onload=|javascript:|<iframe~i', $record->attemptfeedback)) {
            throw new ExpectationException('Active content stored: ' . $record->attemptfeedback, $this->getSession());
        }
    }

    /**
     * Checks the name and the description of an open dialog: named by its title, described by the reason
     * (issue #20).
     *
     * @Then /^the "([^"]*)" dialogue is named by its title and described by "([^"]*)"$/
     *
     * @param string $title
     * @param string $text
     */
    public function the_dialogue_is_named_and_described(string $title, string $text): void {
        $script = 'return (function() {'
            . ' var d = document.querySelector(".modal.show[role=dialog]");'
            . ' if (!d) { return JSON.stringify({}); }'
            . ' var name = (d.getAttribute("aria-labelledby") || "").split(" ").map(function(id) {'
            . '   var e = document.getElementById(id); return e ? e.textContent.trim() : ""; }).join(" ");'
            . ' var desc = (d.getAttribute("aria-describedby") || "").split(" ").map(function(id) {'
            . '   var e = document.getElementById(id); return e ? e.textContent.trim() : ""; }).join(" ");'
            . ' return JSON.stringify({name: name, description: desc, modal: d.getAttribute("aria-modal")});'
            . '})();';
        $r = json_decode((string) $this->getSession()->evaluateScript($script), true);
        if (strpos((string) ($r['name'] ?? ''), $title) === false || strpos((string) ($r['description'] ?? ''), $text) === false) {
            throw new ExpectationException('Dialog name/description: ' . json_encode($r), $this->getSession());
        }
    }

    /**
     * Presses Tab several times and checks that the focus never leaves the open dialog (issue #20).
     *
     * @Then /^the focus stays in the open dialogue when I press tab (\d+) times$/
     *
     * @param int $times
     */
    public function the_focus_stays_in_the_open_dialogue(int $times): void {
        $inside = 'return (function() { var d = document.querySelector(".modal.show [role=dialog], .modal.show");'
            . ' return !!d && d.contains(document.activeElement); })();';
        for ($i = 0; $i <= $times; $i++) {
            if (!$this->getSession()->evaluateScript($inside)) {
                $where = $this->getSession()->evaluateScript('return document.activeElement ? '
                    . 'document.activeElement.outerHTML.substring(0, 120) : "none";');
                throw new ExpectationException("The focus left the dialog after $i Tab: $where", $this->getSession());
            }
            if ($i < $times) {
                $this->execute('behat_general::i_press_named_key', ['', 'tab']);
            }
        }
    }

    /**
     * Checks whether the attempt feedback is hidden from assistive technology and keyboard (issue #20).
     *
     * @Then /^the attempt feedback is (hidden from|available to) assistive technology$/
     *
     * @param string $state
     */
    public function the_attempt_feedback_is_for_assistive_technology(string $state): void {
        $script = 'return (function() { var f = document.querySelector("[data-region=adaptivequiz-attempt-feedback]");'
            . ' return f ? JSON.stringify({hidden: f.hidden, inert: !!f.inert, aria: f.getAttribute("aria-hidden")})'
            . ' : "{}"; })();';
        $r = json_decode((string) $this->getSession()->evaluateScript($script), true);
        $hidden = !empty($r['hidden']) && !empty($r['inert']) && ($r['aria'] ?? '') === 'true';
        $available = isset($r['hidden']) && empty($r['hidden']) && empty($r['inert']) && empty($r['aria']);
        if (($state === 'hidden from' && !$hidden) || ($state === 'available to' && !$available)) {
            throw new ExpectationException(
                "Attempt feedback not $state assistive technology: " . json_encode($r),
                $this->getSession()
            );
        }
    }

    /**
     * Reaches a link with Tab alone and follows it with Enter, as a keyboard user does (issue #22).
     *
     * @When /^I follow the "([^"]*)" link with the keyboard$/
     *
     * @param string $name The accessible name (text) of the link.
     */
    public function i_follow_the_link_with_the_keyboard(string $name): void {
        $this->getSession()->executeScript('document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0);');
        $check = 'return (function() { var e = document.activeElement;'
            . ' return e && e.tagName === "A" && e.textContent.trim() === ' . json_encode($name) . '; })();';
        for ($i = 0; $i < 80; $i++) {
            $this->execute('behat_general::i_press_named_key', ['', 'tab']);
            if ($this->getSession()->evaluateScript($check)) {
                $this->execute('behat_general::i_press_named_key', ['', 'enter']);
                $this->execute('behat_general::wait_until_the_page_is_ready');
                return;
            }
        }
        throw new ExpectationException("\"$name\" was not reached with Tab", $this->getSession());
    }
}
