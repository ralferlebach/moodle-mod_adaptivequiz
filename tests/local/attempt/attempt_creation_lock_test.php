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

namespace mod_adaptivequiz\local;

use advanced_testcase;
use mod_adaptivequiz\local\attempt\attempt_state;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once(__DIR__ . '/../../fixtures/attempt_creation_lock_test_factory.php');

/**
 * Two first requests at once - a double click on "Start" - make one attempt (issue #5).
 *
 * The check for an open attempt and the creation of a new one run under a lock; the request that waited
 * for it finds the attempt the other one created.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\local\attempt::get_attempt
 */
final class attempt_creation_lock_test extends advanced_testcase {
    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var stdClass The person taking it. */
    private stdClass $user;

    /**
     * An activity, a person, the lock factory of the test.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->adaptivequiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
        ]);
        $this->user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $CFG->lock_factory = '\\' . attempt_creation_lock_test_factory::class;
        attempt_creation_lock_test_factory::$before = null;
        attempt_creation_lock_test_factory::$resources = [];
        attempt::$createlocktimeout = 10;
        request_timing::reset_request();
    }

    /**
     * Resets the hook.
     */
    protected function tearDown(): void {
        attempt_creation_lock_test_factory::$before = null;
        attempt::$createlocktimeout = 10;
        request_timing::reset_request();
        parent::tearDown();
    }

    /**
     * Holds the creation lock as the other request would.
     *
     * @return \core\lock\lock
     */
    private function hold_creation_lock(): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_attemptcreate_' . $this->adaptivequiz->id . '_' . $this->user->id, 0);
        $this->assertNotFalse($lock);
        return $lock;
    }

    /**
     * Without the lock no attempt is created: the other request may be about to create one (issue #17).
     *
     * Before, a request whose wait for the lock timed out created an attempt anyway - the double attempt
     * of issue #5 again, only later.
     */
    public function test_no_attempt_is_created_without_the_lock(): void {
        $held = $this->hold_creation_lock();
        attempt::$createlocktimeout = 0;
        try {
            (new attempt($this->adaptivequiz, $this->user->id))->get_attempt();
            $this->fail('An attempt was created without the lock.');
        } catch (\moodle_exception $e) {
            $this->assertSame('attemptcreatelocktimeout', $e->errorcode);
        } finally {
            $held->release();
        }

        $this->assertCount(0, $this->open_attempts());
        $waits = array_values(array_filter(request_timing::get_spans(), fn($s) => $s['name'] === 'attempt_create_lock_wait'));
        $this->assertCount(1, $waits);
        $this->assertFalse($waits[0]['meta']['acquired']);
        $this->assertSame([], array_filter(request_timing::get_spans(), fn($s) => $s['name'] === 'attempt_create'));
    }

    /**
     * The lock was not had in time, but the other request has created its attempt meanwhile: it is taken.
     */
    public function test_without_the_lock_the_attempt_of_the_other_is_taken(): void {
        global $DB;
        $held = $this->hold_creation_lock();
        attempt::$createlocktimeout = 0;
        $first = null;
        attempt_creation_lock_test_factory::$before = function (string $resource) use (&$first, $DB) {
            if (strpos($resource, 'adaptivequiz_attemptcreate_') !== 0 || $first !== null) {
                return;
            }
            $now = time();
            $first = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
                'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => 0,
                'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 0,
                'difficultysum' => 0, 'standarderror' => 999, 'measure' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        };
        try {
            $attempt = new attempt($this->adaptivequiz, $this->user->id);
            $record = $attempt->get_attempt();
        } finally {
            $held->release();
        }

        $this->assertSame($first, (int) $record->id);
        $this->assertSame([], array_filter(request_timing::get_spans(), fn($s) => $s['name'] === 'attempt_create'));
        $this->assertCount(1, $this->open_attempts());
    }

    /**
     * Creating the attempt shows in the timeline: the wait for the lock and the insert (issue #17).
     */
    public function test_creation_is_measured(): void {
        (new attempt($this->adaptivequiz, $this->user->id))->get_attempt();

        $spans = array_column(request_timing::get_spans(), null, 'name');
        $this->assertTrue($spans['attempt_create_lock_wait']['meta']['acquired']);
        $this->assertGreaterThanOrEqual(0, $spans['attempt_create']['ms']);
        $this->assertArrayHasKey('memkb', $spans['attempt_create']['meta']);

        // A later request finds the attempt and neither waits nor creates.
        request_timing::reset();
        (new attempt($this->adaptivequiz, $this->user->id))->get_attempt();
        $this->assertSame([], request_timing::get_spans());
    }

    /**
     * The open attempts of the person.
     *
     * @return array
     */
    private function open_attempts(): array {
        global $DB;
        return $DB->get_records('adaptivequiz_attempt', [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'attemptstate' => attempt_state::IN_PROGRESS,
        ]);
    }

    /**
     * The other request creates its attempt while this one waits for the lock: this one takes it.
     */
    public function test_the_request_that_waited_takes_the_attempt_of_the_other(): void {
        global $DB;
        $first = null;
        attempt_creation_lock_test_factory::$before = function (string $resource) use (&$first, $DB) {
            if (strpos($resource, 'adaptivequiz_attemptcreate_') !== 0 || $first !== null) {
                return;
            }
            $now = time();
            $first = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
                'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => 0,
                'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 0,
                'difficultysum' => 0, 'standarderror' => 999, 'measure' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        };

        $attempt = (new attempt($this->adaptivequiz, $this->user->id))->get_attempt();

        $this->assertNotNull($first, 'The lock for creating an attempt was asked for.');
        $this->assertSame($first, (int) $attempt->id, 'The attempt of the other request.');
        $this->assertCount(1, $this->open_attempts());
    }

    /**
     * Alone, a first request creates one attempt; a later one takes it without asking for the lock.
     */
    public function test_a_first_request_creates_one_attempt(): void {
        $attempt = (new attempt($this->adaptivequiz, $this->user->id))->get_attempt();
        $again = (new attempt($this->adaptivequiz, $this->user->id))->get_attempt();

        $this->assertSame((int) $attempt->id, (int) $again->id);
        $this->assertCount(1, $this->open_attempts());
        $this->assertSame(
            ['adaptivequiz_attemptcreate_' . $this->adaptivequiz->id . '_' . $this->user->id],
            array_values(array_filter(
                attempt_creation_lock_test_factory::$resources,
                fn($r) => strpos($r, 'adaptivequiz_attemptcreate_') === 0
            )),
            'Only the request that creates the attempt waits for the lock.'
        );
        // The lock is free again.
        $lock = \core\lock\lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_attemptcreate_' . $this->adaptivequiz->id . '_' . $this->user->id, 0);
        $this->assertNotFalse($lock);
        $lock->release();
    }
}
