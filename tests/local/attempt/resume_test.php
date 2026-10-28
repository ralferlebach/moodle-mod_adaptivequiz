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
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

/**
 * An open attempt is continued, started anew or ended - by choice, once, on the server (issue #5).
 *
 * This line has no fixture CAT model; the limits a CAT model reports are set through resume_state::$testlimits.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\local\attempt\resume_state
 * @covers \mod_adaptivequiz\local\attempt\resume_actions
 * @covers ::adaptivequiz_complete_attempt_by_id
 */
final class resume_test extends advanced_testcase {
    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var context_module Its context. */
    private context_module $context;

    /** @var stdClass The person taking it. */
    private stdClass $user;

    /**
     * An activity and a person.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->adaptivequiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'lowestlevel' => 1, 'highestlevel' => 10, 'startinglevel' => 5,
            'minimumquestions' => 1, 'maximumquestions' => 5, 'standarderror' => 5, 'attempts' => 0,
        ]);
        $cm = get_coursemodule_from_instance('adaptivequiz', $this->adaptivequiz->id, $course->id, false, MUST_EXIST);
        $this->context = context_module::instance($cm->id);
        $this->user = $this->getDataGenerator()->create_user();
        $this->setUser($this->user);
        resume_state::$testlimits = [];
    }

    /**
     * Forgets the limits.
     */
    protected function tearDown(): void {
        resume_state::$testlimits = null;
        parent::tearDown();
    }

    /**
     * An open attempt with one question on the screen, shown the given number of seconds ago.
     *
     * @param int $shownago
     * @return stdClass
     */
    private function attempt_with_a_pending_question(int $shownago): stdClass {
        global $DB;
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category([
            'contextid' => \context_course::instance($this->adaptivequiz->course)->id,
        ]);
        $question = $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question('truefalse', null, ['category' => $category->id]);
        $quba = \question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $this->context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $slot = $quba->add_question(\question_bank::load_question($question->id));
        $quba->start_question($slot);
        \question_engine::save_questions_usage_by_activity($quba);
        $id = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => $quba->get_id(),
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 0,
            'difficultysum' => 0, 'standarderror' => 999, 'measure' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->execute(
            "UPDATE {question_attempt_steps}
                SET timecreated = :shown
              WHERE questionattemptid IN (SELECT id FROM {question_attempts} WHERE questionusageid = :usage)",
            ['shown' => time() - $shownago, 'usage' => $quba->get_id()]
        );
        return $DB->get_record('adaptivequiz_attempt', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Within both limits the attempt is resumable; past the time for the question, the question is expired;
     * past the time for the attempt, the attempt is.
     */
    public function test_the_three_states(): void {
        global $DB;
        $this->assertSame(resume_state::NONE, resume_state::classify($this->adaptivequiz, null));
        $record = $this->attempt_with_a_pending_question(100);

        $this->assertSame(resume_state::RESUMABLE, resume_state::classify($this->adaptivequiz, $record));
        resume_state::$testlimits = ['item' => 60];
        $this->assertSame(resume_state::ITEM_EXPIRED, resume_state::classify($this->adaptivequiz, $record));
        resume_state::$testlimits = ['item' => 600];
        $this->assertSame(resume_state::RESUMABLE, resume_state::classify($this->adaptivequiz, $record));

        $DB->set_field('adaptivequiz_attempt', 'timecreated', time() - 1000, ['id' => $record->id]);
        $record = $DB->get_record('adaptivequiz_attempt', ['id' => $record->id]);
        resume_state::$testlimits = ['attempt' => 600, 'item' => 60];
        $this->assertSame(resume_state::EXPIRED, resume_state::classify($this->adaptivequiz, $record));
        $this->assertSame((int) $record->id, (int) resume_state::open_attempt($this->adaptivequiz, (int) $this->user->id)->id);
    }

    /**
     * Starting anew ends the open attempt once, with its stop code, and a second click ends nothing more.
     */
    public function test_restart_ends_the_open_attempt_once(): void {
        global $DB;
        $record = $this->attempt_with_a_pending_question(10);
        $userid = (int) $this->user->id;

        $this->assertTrue(resume_actions::restart($this->adaptivequiz, $this->context, $userid, (int) $record->id));
        $this->assertFalse(resume_actions::restart($this->adaptivequiz, $this->context, $userid, (int) $record->id));

        $ended = $DB->get_record('adaptivequiz_attempt', ['id' => $record->id]);
        $this->assertSame(attempt_state::COMPLETED, $ended->attemptstate);
        $this->assertSame(stop_reason::RESTARTED, $ended->stopcode);
        $this->assertNotEmpty($ended->timefinished);

        $new = (new \mod_adaptivequiz\local\attempt($this->adaptivequiz, $userid))->get_attempt();
        $this->assertNotEquals($record->id, $new->id);
        $other = $this->getDataGenerator()->create_user();
        $this->assertFalse(resume_actions::restart($this->adaptivequiz, $this->context, (int) $other->id, (int) $new->id));
    }

    /**
     * With the time for the question over, the question is given up once and counted.
     */
    public function test_new_question_gives_up_the_expired_one(): void {
        global $DB;
        $record = $this->attempt_with_a_pending_question(100);
        $userid = (int) $this->user->id;
        $this->assertFalse(resume_actions::new_question($this->adaptivequiz, $userid, (int) $record->id));
        resume_state::$testlimits = ['item' => 60];

        $this->assertTrue(resume_actions::new_question($this->adaptivequiz, $userid, (int) $record->id));
        $this->assertFalse(resume_actions::new_question($this->adaptivequiz, $userid, (int) $record->id));

        $quba = \question_engine::load_questions_usage_by_activity((int) $record->uniqueid);
        $this->assertFalse($quba->get_question_state(1)->is_active());
        $this->assertSame(1, (int) $DB->get_field('adaptivequiz_attempt', 'questionsattempted', ['id' => $record->id]));
    }

    /**
     * An attempt out of time is ended as the time limit ends it; one within its time is not.
     */
    public function test_an_expired_attempt_is_ended_with_the_time_limit(): void {
        global $DB;
        $record = $this->attempt_with_a_pending_question(10);
        $userid = (int) $this->user->id;
        resume_state::$testlimits = ['attempt' => 600];
        $this->assertFalse(resume_actions::end_expired($this->adaptivequiz, $this->context, $userid, (int) $record->id));

        $DB->set_field('adaptivequiz_attempt', 'timecreated', time() - 1000, ['id' => $record->id]);
        $this->assertTrue(resume_actions::end_expired($this->adaptivequiz, $this->context, $userid, (int) $record->id));

        $this->assertSame(stop_reason::TIMELIMIT, $DB->get_field('adaptivequiz_attempt', 'stopcode', ['id' => $record->id]));
        $this->assertFalse(adaptivequiz_complete_attempt_by_id((int) $record->id, $this->adaptivequiz, $this->context, 'again'));
    }

    /**
     * The time of the attempt runs out while the choice is on the screen; the person then starts anew. The time
     * limit ended the attempt - its stop reason says so - not the new start.
     */
    public function test_restart_of_an_attempt_out_of_time_records_the_time_limit(): void {
        global $DB;
        $record = $this->attempt_with_a_pending_question(10);
        resume_state::$testlimits = ['attempt' => 600];
        $DB->set_field('adaptivequiz_attempt', 'timecreated', time() - 1000, ['id' => $record->id]);

        $this->assertTrue(resume_actions::restart($this->adaptivequiz, $this->context, (int) $this->user->id, (int) $record->id));

        $ended = $DB->get_record('adaptivequiz_attempt', ['id' => $record->id]);
        $this->assertSame(attempt_state::COMPLETED, $ended->attemptstate);
        $this->assertSame(stop_reason::TIMELIMIT, $ended->stopcode);
        $this->assertNotEmpty($ended->timefinished);
    }
}
