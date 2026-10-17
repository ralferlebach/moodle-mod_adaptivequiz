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

use adaptivequizcatmodel_testcatmodel\local\catmodel\itemadministration\serve_fixed_question_administration;
use advanced_testcase;
use context_module;
use mod_adaptivequiz\cat_session;
use mod_adaptivequiz\local\attempt;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/catmodel/testcatmodel/lib.php');

/**
 * An open attempt is continued, started anew or ended - by choice, once, on the server (issue #5).
 *
 * The fixture CAT model reports the time limits a test sets. The three states the activity page tells
 * apart, and the actions behind its buttons: each ends with the completion every other end uses, and a
 * second click finds nothing left to do.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(resume_state::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(resume_actions::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_complete_attempt_by_id')]
final class resume_test extends advanced_testcase {
    /** @var stdClass The activity. */
    private stdClass $adaptivequiz;

    /** @var context_module Its context. */
    private context_module $context;

    /** @var stdClass The person taking it. */
    private stdClass $user;

    /** @var int[] Questions of the activity. */
    private array $questionids = [];

    /**
     * An activity with the fixture CAT model, and a person.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->get_plugin_generator('mod_qbank')->create_instance(['course' => $course->id]);
        $qbankcm = get_coursemodule_from_instance('qbank', $qbank->id, 0, false, MUST_EXIST);
        $qcat = question_get_default_category(context_module::instance($qbankcm->id)->id);
        for ($copy = 0; $copy < 3; $copy++) {
            $question = $generator->get_plugin_generator('core_question')
                ->create_question('truefalse', null, ['category' => $qcat->id]);
            $generator->get_plugin_generator('core_question')
                ->create_question_tag(['questionid' => $question->id, 'tag' => 'adpq_5']);
            $this->questionids[] = (int) $question->id;
        }
        $modgenerator = $generator->get_plugin_generator('mod_adaptivequiz');
        $instance = $modgenerator->create_instance([
            'course' => $course->id, 'startinglevel' => 5, 'lowestlevel' => 1, 'highestlevel' => 10,
            'minimumquestions' => 1, 'maximumquestions' => 10, 'catmodel' => 'testcatmodel', 'attempts' => 0,
        ]);
        $modgenerator->create_link_with_question_bank(['adaptivequizid' => $instance->id, 'qbankid' => $qbank->id]);
        $this->adaptivequiz = clone($instance);
        $this->context = context_module::instance($instance->cmid);
        $this->adaptivequiz->context = $this->context;
        $this->user = $generator->create_user();
        $this->setUser($this->user);
        adaptivequizcatmodel_testcatmodel_reset_completions();
        $GLOBALS['adaptivequizcatmodel_testcatmodel_limits'] = [];
    }

    /**
     * Resets the fixture CAT model.
     */
    protected function tearDown(): void {
        serve_fixed_question_administration::$questionid = null;
        $GLOBALS['adaptivequizcatmodel_testcatmodel_limits'] = [];
        parent::tearDown();
    }

    /**
     * An attempt with one question on the screen, shown the given number of seconds ago.
     *
     * @param int $shownago
     * @return stdClass The attempt record.
     */
    private function attempt_with_a_pending_question(int $shownago): stdClass {
        global $DB;
        serve_fixed_question_administration::$questionid = $this->questionids[0];
        $attempt = new attempt($this->adaptivequiz, $this->user->id);
        $attempt->set_level(5);
        $attempt->get_attempt();
        $attempt->initialize_quba();
        $this->assertFalse(cat_session::administer_next_item($this->adaptivequiz, $attempt)->item_administration_is_to_stop());
        $record = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->get_attempt()->id], '*', MUST_EXIST);
        $DB->execute(
            "UPDATE {question_attempt_steps}
                SET timecreated = :shown
              WHERE questionattemptid IN (SELECT id FROM {question_attempts} WHERE questionusageid = :usage)",
            ['shown' => time() - $shownago, 'usage' => $record->uniqueid]
        );
        return $record;
    }

    /**
     * Within both limits the attempt is resumable; past the time for the question, the question is
     * expired; past the time for the attempt, the attempt is. Without limits - the built-in algorithm -
     * it is always resumable.
     */
    public function test_the_three_states(): void {
        global $DB;
        $this->assertSame(resume_state::NONE, resume_state::classify($this->adaptivequiz, null));
        $record = $this->attempt_with_a_pending_question(100);

        $this->assertSame(resume_state::RESUMABLE, resume_state::classify($this->adaptivequiz, $record));
        $GLOBALS['adaptivequizcatmodel_testcatmodel_limits'] = ['item' => 60];
        $this->assertSame(resume_state::ITEM_EXPIRED, resume_state::classify($this->adaptivequiz, $record));
        $GLOBALS['adaptivequizcatmodel_testcatmodel_limits'] = ['item' => 600];
        $this->assertSame(resume_state::RESUMABLE, resume_state::classify($this->adaptivequiz, $record));

        $DB->set_field('adaptivequiz_attempt', 'timecreated', time() - 1000, ['id' => $record->id]);
        $record = $DB->get_record('adaptivequiz_attempt', ['id' => $record->id]);
        $GLOBALS['adaptivequizcatmodel_testcatmodel_limits'] = ['attempt' => 600, 'item' => 60];
        $this->assertSame(resume_state::EXPIRED, resume_state::classify($this->adaptivequiz, $record));

        $builtin = clone($this->adaptivequiz);
        $builtin->catmodel = '';
        $this->assertSame(resume_state::RESUMABLE, resume_state::classify($builtin, $record));
        $this->assertSame((int) $record->id, (int) resume_state::open_attempt($this->adaptivequiz, (int) $this->user->id)->id);
    }

    /**
     * Starting anew ends the open attempt once - completion time, stop code, the CAT model told - and a
     * second click ends nothing more; the next request starts the one new attempt.
     */
    public function test_restart_ends_the_open_attempt_once(): void {
        global $DB;
        $record = $this->attempt_with_a_pending_question(10);

        $this->assertTrue(resume_actions::restart($this->adaptivequiz, $this->context, (int) $this->user->id, (int) $record->id));
        $this->assertFalse(resume_actions::restart($this->adaptivequiz, $this->context, (int) $this->user->id, (int) $record->id));

        $ended = $DB->get_record('adaptivequiz_attempt', ['id' => $record->id]);
        $this->assertSame(attempt_state::COMPLETED, $ended->attemptstate);
        $this->assertSame(stop_reason::RESTARTED, $ended->stopcode);
        $this->assertNotEmpty($ended->timefinished);
        $this->assertSame([(int) $record->uniqueid], adaptivequizcatmodel_testcatmodel_completed_attempts());

        $new = (new attempt($this->adaptivequiz, $this->user->id))->get_attempt();
        $this->assertNotEquals($record->id, $new->id);
        $this->assertSame(1, $DB->count_records('adaptivequiz_attempt', [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'attemptstate' => attempt_state::IN_PROGRESS,
        ]), 'One open attempt.');

        // Someone else's attempt, or one that is not open, is left alone.
        $other = $this->getDataGenerator()->create_user();
        $this->assertFalse(resume_actions::restart($this->adaptivequiz, $this->context, (int) $other->id, (int) $new->id));
        $this->assertSame(attempt_state::IN_PROGRESS, $DB->get_field('adaptivequiz_attempt', 'attemptstate', ['id' => $new->id]));
    }

    /**
     * With the time for the question over, the question is given up - once - and the attempt goes on with
     * a new question in a new slot, not with the old one again.
     */
    public function test_new_question_replaces_the_expired_one(): void {
        global $DB;
        $record = $this->attempt_with_a_pending_question(100);
        $this->assertFalse(resume_actions::new_question($this->adaptivequiz, (int) $this->user->id, (int) $record->id));
        $GLOBALS['adaptivequizcatmodel_testcatmodel_limits'] = ['item' => 60];

        $this->assertTrue(resume_actions::new_question($this->adaptivequiz, (int) $this->user->id, (int) $record->id));
        $this->assertFalse(resume_actions::new_question($this->adaptivequiz, (int) $this->user->id, (int) $record->id));

        $quba = \question_engine::load_questions_usage_by_activity((int) $record->uniqueid);
        $this->assertFalse($quba->get_question_state(1)->is_active());
        $attempted = (int) $DB->get_field('adaptivequiz_attempt', 'questionsattempted', ['id' => $record->id]);
        $this->assertSame((int) $record->questionsattempted + 1, $attempted);

        serve_fixed_question_administration::$questionid = $this->questionids[1];
        $attempt = new attempt($this->adaptivequiz, $this->user->id);
        $attempt->set_level(5);
        $attempt->get_attempt();
        $attempt->initialize_quba();
        cat_session::administer_next_item($this->adaptivequiz, $attempt);
        $this->assertCount(2, $attempt->get_quba()->get_slots(), 'A new slot for the new question.');
        $this->assertSame(resume_state::RESUMABLE, resume_state::classify(
            $this->adaptivequiz,
            $DB->get_record('adaptivequiz_attempt', ['id' => $record->id])
        ));
    }

    /**
     * An attempt out of time is ended as the time limit ends it; one within its time is not.
     */
    public function test_an_expired_attempt_is_ended_with_the_time_limit(): void {
        global $DB;
        $record = $this->attempt_with_a_pending_question(10);
        $GLOBALS['adaptivequizcatmodel_testcatmodel_limits'] = ['attempt' => 600];
        $userid = (int) $this->user->id;
        $this->assertFalse(resume_actions::end_expired($this->adaptivequiz, $this->context, $userid, (int) $record->id));

        $DB->set_field('adaptivequiz_attempt', 'timecreated', time() - 1000, ['id' => $record->id]);
        $this->assertTrue(resume_actions::end_expired($this->adaptivequiz, $this->context, $userid, (int) $record->id));

        $ended = $DB->get_record('adaptivequiz_attempt', ['id' => $record->id]);
        $this->assertSame(attempt_state::COMPLETED, $ended->attemptstate);
        $this->assertSame(stop_reason::TIMELIMIT, $ended->stopcode);
        $this->assertSame([(int) $record->uniqueid], adaptivequizcatmodel_testcatmodel_completed_attempts());
        $this->assertFalse(adaptivequiz_complete_attempt_by_id((int) $record->id, $this->adaptivequiz, $this->context, 'again'));
    }

    /**
     * An attempt without any question - uniqueid 0, of which there can be several - is completed by its id.
     */
    public function test_an_attempt_without_a_question_is_completed_by_its_id(): void {
        global $DB;
        $ids = [];
        foreach ([1, 2] as $i) {
            $ids[] = (int) $DB->insert_record('adaptivequiz_attempt', (object) [
                'instance' => $this->adaptivequiz->id, 'userid' => $this->user->id, 'uniqueid' => 0,
                'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 0,
                'difficultysum' => 0, 'standarderror' => 999, 'measure' => 0, 'timecreated' => time() - $i,
                'timemodified' => time() - $i,
            ]);
        }

        $this->assertTrue(
            adaptivequiz_complete_attempt_by_id($ids[1], $this->adaptivequiz, $this->context, 'x', stop_reason::TIMELIMIT)
        );

        $this->assertSame(attempt_state::COMPLETED, $DB->get_field('adaptivequiz_attempt', 'attemptstate', ['id' => $ids[1]]));
        $this->assertSame(attempt_state::IN_PROGRESS, $DB->get_field('adaptivequiz_attempt', 'attemptstate', ['id' => $ids[0]]));
    }
}
