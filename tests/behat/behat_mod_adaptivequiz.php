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

use Behat\Mink\Exception\ExpectationException;

/**
 * Custom steps definition.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_adaptivequiz extends behat_base {
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
}
