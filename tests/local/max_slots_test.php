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
use context_course;
use mod_adaptivequiz\cat_session;
use question_engine;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * The activity's maximum limits every question asked (issue #19).
 *
 * In production a test with a maximum of 25 asked 33 questions: the CAT model counted each repeated
 * item once, the host added a slot for every one it was given. The host now adds no slot once the
 * usage holds the maximum - productive, pilot and duplicate questions alike.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\cat_session
 */
final class max_slots_test extends advanced_testcase {
    /**
     * A usage holding the given number of questions, the same one repeated as a CAT model may.
     *
     * @param int $slots
     * @return \question_usage_by_activity
     */
    private function usage(int $slots): \question_usage_by_activity {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => context_course::instance($course->id)->id]);
        $questionid = $generator->create_question('truefalse', null, ['category' => $category->id])->id;
        $question = \question_bank::load_question($questionid);

        $quba = question_engine::make_questions_usage_by_activity('mod_adaptivequiz', context_course::instance($course->id));
        $quba->set_preferred_behaviour('deferredfeedback');
        for ($i = 0; $i < $slots; $i++) {
            $quba->add_question($question);
        }
        return $quba;
    }

    /**
     * Slots, maximum and whether the maximum is reached.
     *
     * @return array
     */
    public static function cases(): array {
        return [
            'max - 1' => [24, 25, false],
            'max' => [25, 25, true],
            'beyond, as in production' => [33, 25, true],
            'no maximum set' => [40, 0, false],
        ];
    }

    /**
     * The maximum counts every slot of the usage.
     *
     * @dataProvider cases
     * @param int $slots
     * @param int $maximum
     * @param bool $reached
     */
    public function test_maximum_counts_every_slot(int $slots, int $maximum, bool $reached): void {
        $this->resetAfterTest();

        $this->assertSame(
            $reached,
            cat_session::maximum_reached((object) ['maximumquestions' => $maximum], $this->usage($slots))
        );
    }

    /**
     * The guard sits right before the only place a CAT model's item becomes a slot.
     *
     * The built-in algorithm adds its slots itself and stops by its own count; a CAT model names a
     * question and the host adds the slot. This branch carries no test CAT model to drive that path,
     * so the test checks the wiring: without the guard there, maximum_reached() would be correct and
     * never asked.
     */
    public function test_guard_precedes_adding_the_slot(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/mod/adaptivequiz/classes/cat_session.php');
        $start = strpos($source, 'private static function run_item_administration_locked(');
        $this->assertNotFalse($start);
        $body = substr($source, $start, strpos($source, "\n    /**", $start) - $start);

        $guard = strpos($body, 'if (self::maximum_reached($adaptivequiz, $quba)) {');
        $add = strpos($body, '$quba->add_question(');
        $this->assertNotFalse($guard, 'The guard is missing.');
        $this->assertNotFalse($add);
        $this->assertLessThan($add, $guard, 'The guard must come before the slot is added.');
        $this->assertSame(1, substr_count($body, '$quba->add_question('), 'No other path adds a slot.');
    }

    /**
     * A concurrent request - a double click, a second tab - does nothing while another one administers.
     *
     * The lock is held as the first request would hold it. The second request must neither start a
     * question usage nor add a slot (issue #19). Without the lock check this activity, which has no
     * questions, would fail on its missing first question instead.
     */
    public function test_a_concurrent_request_does_nothing(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        // A lock that one process cannot take twice, as two requests cannot.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $course = $this->getDataGenerator()->create_course();
        $adaptivequiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'lowestlevel' => 1, 'highestlevel' => 10, 'startinglevel' => 5,
            'minimumquestions' => 1, 'maximumquestions' => 5, 'standarderror' => 5,
        ]);
        $cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $adaptivequiz->context = $context;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $attempt = new attempt($adaptivequiz, $user->id);
        $record = $attempt->get_attempt();

        $held = \core\lock\lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_itemadministration_' . $adaptivequiz->id . '_' . $user->id, 0);
        $this->assertNotFalse($held);
        // The lock factory counts in whole seconds: two seconds wait at least one.
        cat_session::$locktimeout = 2;
        \mod_adaptivequiz\local\request_timing::reset();
        try {
            cat_session::run_item_administration((int) $record->uniqueid, $adaptivequiz, $context, $attempt);
        } finally {
            $held->release();
            cat_session::$locktimeout = 10;
        }
        // The wait shows as such in the timeline, not as slow item selection (issue #17).
        $waits = array_values(array_filter(
            \mod_adaptivequiz\local\request_timing::get_spans(),
            fn($span) => $span['name'] === 'lock_wait'
        ));
        $this->assertCount(1, $waits);
        $this->assertFalse($waits[0]['meta']['acquired']);
        $this->assertGreaterThanOrEqual(900, $waits[0]['ms'], 'The time the request waited for the lock.');

        $after = $DB->get_record('adaptivequiz_attempt', ['id' => $record->id], 'uniqueid, attemptstate', MUST_EXIST);
        $this->assertEquals(0, $after->uniqueid, 'The waiting request started a question usage.');
        $this->assertSame('inprogress', $after->attemptstate);
    }
}
