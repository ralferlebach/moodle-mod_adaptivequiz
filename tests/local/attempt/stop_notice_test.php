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
     * The time-limit notice says how long the attempt lasted.
     */
    public function test_time_limit_notice_names_the_duration(): void {
        $this->resetAfterTest();
        $notice = stop_reason::notice((object) [
            'stopcode' => stop_reason::TIMELIMIT, 'timecreated' => 1000, 'timefinished' => 1000 + 25 * 60,
        ]);
        $this->assertStringContainsString(format_time(25 * 60), $notice->text);
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
}
