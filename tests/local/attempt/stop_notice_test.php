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

namespace mod_adaptivequiz\local\attempt;

use advanced_testcase;
use context_module;
use mod_adaptivequiz\external\acknowledge_stop_notice;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

/**
 * The result page explains an unexpected end of the attempt (issue #20).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\local\attempt\stop_reason
 * @covers \mod_adaptivequiz\external\acknowledge_stop_notice
 */
final class stop_notice_test extends advanced_testcase {
    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var stdClass The participant. */
    private stdClass $user;

    /** @var int Next question usage id. */
    private int $uniqueid = 9700;

    /**
     * Codes, whether there is a notice, and the text it shows.
     *
     * @return array
     */
    public static function codes(): array {
        return [
            'maximum: the regular end' => [stop_reason::MAXIMUM, stop_reason::NOTICE_NONE, null],
            'time limit' => [stop_reason::TIMELIMIT, stop_reason::NOTICE_INFO, 'stopnotice_timelimit'],
            'no eligible question' => [stop_reason::NO_ELIGIBLE, stop_reason::NOTICE_INFO, 'stopnotice_noeligiblequestions'],
            'target precision' => [stop_reason::TARGET_PRECISION, stop_reason::NOTICE_INFO, 'stopnotice_targetprecision'],
            'closed by a teacher' => [stop_reason::MANUAL, stop_reason::NOTICE_INFO, 'stopnotice_manual'],
            'technical error' => [stop_reason::ERROR, stop_reason::NOTICE_ERROR, 'stopnotice_error'],
            'unknown code' => ['somethingnew', stop_reason::NOTICE_INFO, 'stopnotice_unknown'],
            'no code: ended before codes' => [null, stop_reason::NOTICE_NONE, null],
        ];
    }

    /**
     * Which end gets which notice - and errors are never shown as a regular end.
     *
     * @param string|null $code
     * @param string $kind
     * @param string|null $stringkey
     * @dataProvider codes
     */
    public function test_notice_per_code(?string $code, string $kind, ?string $stringkey): void {
        $this->resetAfterTest();
        $this->assertSame($kind, stop_reason::notice_kind($code));

        $notice = stop_reason::notice((object) ['stopcode' => $code, 'timecreated' => 1000, 'timefinished' => 1000 + 25 * 60]);
        if ($stringkey === null) {
            $this->assertNull($notice);
            return;
        }
        $this->assertStringStartsWith(get_string($stringkey, 'adaptivequiz', (object) ['duration' => '']), $notice->text);
        $this->assertSame(
            get_string($kind === stop_reason::NOTICE_ERROR ? 'stopnoticetitle_error' : 'stopnoticetitle_info', 'adaptivequiz'),
            $notice->title
        );
    }

    /**
     * The time-limit notice names the configured limit - not "finished minus started", which for an attempt closed
     * by the scheduled task three hours later was "3 hours" at a limit of one.
     */
    public function test_time_limit_notice_names_the_limit_not_the_closing_time(): void {
        $this->resetAfterTest();
        $attempt = (object) ['stopcode' => stop_reason::TIMELIMIT, 'timecreated' => 1000, 'timefinished' => 1000 + 3 * HOURSECS];

        $text = stop_reason::notice($attempt, HOURSECS)->text;
        $this->assertStringContainsString(get_string('stopnotice_timelimit_limit', 'adaptivequiz', format_time(HOURSECS)), $text);
        $this->assertStringNotContainsString(format_time(3 * HOURSECS), $text);

        $nolimit = stop_reason::notice($attempt)->text;
        $this->assertSame(get_string('stopnotice_timelimit', 'adaptivequiz'), $nolimit, 'No limit known.');
        $this->assertNull(stop_reason::time_limit((object) ['catmodel' => ''], $attempt), 'The built-in algorithm has none.');
    }

    /**
     * An activity with a participant.
     */
    private function activity(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->adaptivequiz = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $course->id, 'lowestlevel' => 1, 'highestlevel' => 11,
        ]);
        $this->user = $this->getDataGenerator()->create_and_enrol($course, 'student');
    }

    /**
     * Runs an attempt to completion with a stop code.
     *
     * @param string|null $code
     * @return stdClass The completed attempt.
     */
    private function complete(?string $code): stdClass {
        global $DB;
        $uniqueid = ++$this->uniqueid;
        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => $uniqueid,
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 3,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 0.0, 'timecreated' => time() - 600,
            'timemodified' => time(),
        ]);
        adaptivequiz_complete_attempt(
            $uniqueid,
            $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
            context_module::instance($this->adaptivequiz->cmid),
            (int) $this->user->id,
            'Stopped',
            $code
        );
        return $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid]);
    }

    /**
     * The code is stored with the completion; a later completion does not rewrite why it ended.
     */
    public function test_completion_stores_the_first_code(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();

        $attempt = $this->complete(stop_reason::TIMELIMIT);
        $this->assertSame(stop_reason::TIMELIMIT, $attempt->stopcode);

        adaptivequiz_complete_attempt(
            (int) $attempt->uniqueid,
            $DB->get_record('adaptivequiz', ['id' => $this->adaptivequiz->id]),
            context_module::instance($this->adaptivequiz->cmid),
            (int) $this->user->id,
            'Again',
            stop_reason::MANUAL
        );
        $this->assertSame(stop_reason::TIMELIMIT, $DB->get_field('adaptivequiz_attempt', 'stopcode', ['id' => $attempt->id]));
    }

    /**
     * The participant confirms once; a second confirmation keeps the first time.
     */
    public function test_confirmation_is_stored_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $this->setUser($this->user);
        $attempt = $this->complete(stop_reason::NO_ELIGIBLE);

        $this->assertTrue(acknowledge_stop_notice::execute((int) $attempt->id)['acknowledged']);
        $acknowledged = (int) $DB->get_field('adaptivequiz_attempt', 'stopnoticeack', ['id' => $attempt->id]);
        $this->assertGreaterThan(0, $acknowledged);

        $this->waitForSecond();
        acknowledge_stop_notice::execute((int) $attempt->id);
        $this->assertSame($acknowledged, (int) $DB->get_field('adaptivequiz_attempt', 'stopnoticeack', ['id' => $attempt->id]));
    }

    /**
     * Only the participant confirms, and only a completed attempt.
     */
    public function test_confirmation_is_the_participants_own(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->complete(stop_reason::TIMELIMIT);

        $other = $this->getDataGenerator()->create_and_enrol(get_course($this->adaptivequiz->course), 'student');
        $this->setUser($other);
        try {
            acknowledge_stop_notice::execute((int) $attempt->id);
            $this->fail('Someone else confirmed the notice.');
        } catch (\moodle_exception $e) {
            $this->assertEmpty($DB->get_field('adaptivequiz_attempt', 'stopnoticeack', ['id' => $attempt->id]));
        }

        $this->setUser($this->user);
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', attempt_state::IN_PROGRESS, ['id' => $attempt->id]);
        $this->expectException(\moodle_exception::class);
        acknowledge_stop_notice::execute((int) $attempt->id);
    }

    /**
     * The confirmation works while completion hides the activity: the result page is readable then, and its
     * notice must not come back on every visit (issue #20).
     */
    public function test_confirmation_while_the_activity_is_hidden(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $attempt = $this->complete(stop_reason::NO_ELIGIBLE);
        $DB->set_field('course_modules', 'visible', 0, ['id' => $this->adaptivequiz->cmid]);
        rebuild_course_cache($this->adaptivequiz->course, true);
        $this->setUser($this->user);

        $this->assertTrue(acknowledge_stop_notice::execute((int) $attempt->id)['acknowledged']);
        $this->assertNotEmpty($DB->get_field('adaptivequiz_attempt', 'stopnoticeack', ['id' => $attempt->id]));
    }

    /**
     * Notice and confirmation change nothing of the result: same snapshot and grade with or without a code, and
     * the confirmation touches only its own field.
     */
    public function test_notice_changes_no_result(): void {
        global $DB;
        $this->resetAfterTest();
        $this->activity();
        $regular = $this->complete(stop_reason::MAXIMUM);
        $stopped = $this->complete(stop_reason::NO_ELIGIBLE);
        $result = ['resultstatus', 'resultvalid', 'resultscore', 'resultlower', 'resultupper', 'resultpercent', 'measure'];
        foreach ($result as $field) {
            $this->assertEquals($regular->$field, $stopped->$field, $field);
        }

        $this->setUser($this->user);
        acknowledge_stop_notice::execute((int) $stopped->id);
        $after = $DB->get_record('adaptivequiz_attempt', ['id' => $stopped->id]);
        $this->assertNotEmpty($after->stopnoticeack);
        unset($after->stopnoticeack, $stopped->stopnoticeack);
        $this->assertEquals($stopped, $after, 'The confirmation changed nothing else of the attempt.');
    }

    /**
     * Every notice string exists in English and German, with the same placeholders.
     */
    public function test_notice_strings_in_english_and_german(): void {
        global $CFG;
        $strings = [];
        foreach (['en', 'de'] as $lang) {
            $string = [];
            include($CFG->dirroot . '/mod/adaptivequiz/lang/' . $lang . '/adaptivequiz.php');
            $strings[$lang] = array_filter($string, fn($key) => str_starts_with($key, 'stopnotice'), ARRAY_FILTER_USE_KEY);
        }
        $this->assertNotEmpty($strings['en']);
        ksort($strings['en']);
        ksort($strings['de']);
        $this->assertSame(array_keys($strings['en']), array_keys($strings['de']), 'The same strings in both languages.');
        foreach ($strings['en'] as $key => $text) {
            preg_match_all('~\{\$a[^}]*\}~', $text, $en);
            preg_match_all('~\{\$a[^}]*\}~', $strings['de'][$key], $de);
            $this->assertSame($en[0], $de[0], "Placeholders of $key.");
        }
        // The limit is Moodle's own duration: singular and plural.
        $this->assertNotSame(format_time(MINSECS), format_time(2 * MINSECS));
    }
}
