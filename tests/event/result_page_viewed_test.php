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

namespace mod_adaptivequiz\event;

use advanced_testcase;
use context_module;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

/**
 * Viewing a result page is its own event, and only the owner's view of their attempt counts (#15).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\event\result_page_viewed
 * @covers ::adaptivequiz_result_page_attempt
 */
final class result_page_viewed_test extends advanced_testcase {
    /**
     * Files a completed attempt of a new activity.
     *
     * @param int|null $userid Owner; a new user if null.
     * @return array{0: \stdClass, 1: \stdClass} The course module and the attempt.
     */
    private function completed_attempt(?int $userid = null): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $userid = $userid ?? (int) $this->getDataGenerator()->create_user()->id;
        $instance = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('adaptivequiz', $instance->id);
        $now = time();
        $attemptid = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $instance->id, 'userid' => $userid, 'uniqueid' => 77000 + $instance->id,
            'attemptstate' => 'complete', 'attemptstopcriteria' => '', 'questionsattempted' => 3,
            'difficultysum' => 0, 'standarderror' => 0.3, 'measure' => 0.2, 'timefinished' => $now,
            'resultvalid' => 1, 'resultstatus' => 'valid', 'timecreated' => $now, 'timemodified' => $now,
        ]);

        return [$cm, $DB->get_record('adaptivequiz_attempt', ['id' => $attemptid])];
    }

    /**
     * The event describes the attempt, in the activity's context, as the owner's read.
     */
    public function test_the_event_describes_the_view(): void {
        $this->resetAfterTest();
        [$cm, $attempt] = $this->completed_attempt();
        $context = context_module::instance($cm->id);

        $event = result_page_viewed::create_from_attempt($attempt, $context);

        $this->assertSame('adaptivequiz_attempt', $event->objecttable);
        $this->assertEquals($attempt->id, $event->objectid);
        $this->assertEquals($cm->id, $event->contextinstanceid);
        $this->assertEquals($attempt->userid, $event->userid);
        $this->assertSame('r', $event->crud);
        $this->assertSame(\core\event\base::LEVEL_PARTICIPATING, $event->edulevel);
        $this->assertSame(
            ['db' => 'adaptivequiz_attempt', 'restore' => 'adaptiveattempts'],
            result_page_viewed::get_objectid_mapping()
        );
        $this->assertEquals($attempt->instance, $event->other['instanceid']);
        $this->assertEquals('valid', $event->other['resultstatus']);
        $this->assertEquals(1, $event->other['resultvalid']);
        $this->assertEquals($attempt->timefinished, $event->other['timefinished']);

        $url = $event->get_url()->out(false);
        $this->assertStringContainsString('/mod/adaptivequiz/attemptfinished.php', $url);
        $this->assertStringContainsString('uattid=' . $attempt->uniqueid, $url);
        $this->assertStringContainsString('cmid=' . $cm->id, $url);
    }

    /**
     * The owner gets the attempt; someone else, or a request naming another instance, does not.
     */
    public function test_only_the_owner_reaches_the_page(): void {
        $this->resetAfterTest();
        [$cm, $attempt] = $this->completed_attempt();
        $other = (int) $this->getDataGenerator()->create_user()->id;

        $mine = adaptivequiz_result_page_attempt($cm, (int) $attempt->uniqueid, (int) $cm->instance, (int) $attempt->userid);
        $this->assertEquals($attempt->id, $mine->id);

        $sink = $this->redirectEvents();
        foreach (
            [
            'someone else' => [(int) $cm->instance, $other],
            'another instance named' => [(int) $cm->instance + 1000, (int) $attempt->userid],
            ] as $case => [$instance, $userid]
        ) {
            try {
                adaptivequiz_result_page_attempt($cm, (int) $attempt->uniqueid, $instance, $userid);
                $this->fail("$case reached the result page.");
            } catch (moodle_exception $e) {
                $this->assertSame('notyourattempt', $e->errorcode);
            }
        }
        $this->assertSame([], array_filter($sink->get_events(), fn($e) => $e instanceof result_page_viewed));
        $sink->close();
    }

    /**
     * An attempt of one activity is not shown through another activity's course module.
     *
     * The request names the attempt's own instance, so a check against the requested instance
     * alone passes; what binds the page is the instance of the course module it runs in.
     */
    public function test_an_attempt_is_bound_to_its_activity(): void {
        $this->resetAfterTest();
        [, $attempt] = $this->completed_attempt();
        [$othercm] = $this->completed_attempt((int) $attempt->userid);

        $this->expectException(moodle_exception::class);
        adaptivequiz_result_page_attempt($othercm, (int) $attempt->uniqueid, (int) $attempt->instance, (int) $attempt->userid);
    }
}
