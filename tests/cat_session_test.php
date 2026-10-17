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

namespace mod_adaptivequiz;

use adaptivequizcatmodel_testcatmodel\local\catmodel\itemadministration\serve_fixed_question_administration;
use advanced_testcase;
use context_module;
use mod_adaptivequiz\local\attempt;
use mod_adaptivequiz\local\attempt\stop_reason;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

/**
 * Tests of the item administration step of a running attempt.
 *
 * A CAT model names the next question, the host puts it into the question usage. That step must
 * never produce a second slot for an item that already has an active one - on a reload, on a
 * double click, or when the CAT model changes its mind between two requests.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cat_session::class)]
final class cat_session_test extends advanced_testcase {
    /** @var stdClass The activity instance under test. */
    private stdClass $adaptivequiz;

    /** @var stdClass The user taking the attempt. */
    private stdClass $user;

    /** @var int[] Ids of the questions available to the activity. */
    private array $questionids = [];

    /**
     * Builds an activity with questions on the starting level and a user to take it.
     *
     * @param string|null $catmodel Name of the CAT model to configure, null for the built-in one.
     */
    private function set_up_activity(?string $catmodel = null): void {
        $coregenerator = $this->getDataGenerator();
        /** @var \mod_adaptivequiz_generator $modgenerator */
        $modgenerator = $coregenerator->get_plugin_generator('mod_adaptivequiz');
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $coregenerator->get_plugin_generator('core_question');
        /** @var \mod_qbank_generator $qbankgenerator */
        $qbankgenerator = $coregenerator->get_plugin_generator('mod_qbank');

        $course = $coregenerator->create_course();

        $qbank = $qbankgenerator->create_instance(['course' => $course->id]);
        $qbankcm = get_coursemodule_from_instance('qbank', $qbank->id, 0, false, MUST_EXIST);
        $qcat = question_get_default_category(context_module::instance($qbankcm->id)->id);

        for ($copy = 0; $copy < 4; $copy++) {
            $question = $questiongenerator->create_question('truefalse', null, ['category' => $qcat->id]);
            $questiongenerator->create_question_tag(['questionid' => $question->id, 'tag' => 'adpq_5']);
            $this->questionids[] = (int) $question->id;
        }

        $instance = $modgenerator->create_instance([
            'course' => $course->id,
            'startinglevel' => 5,
            'lowestlevel' => 1,
            'highestlevel' => 10,
            'minimumquestions' => 1,
            'maximumquestions' => 10,
            'catmodel' => $catmodel ?? '',
        ]);
        $modgenerator->create_link_with_question_bank([
            'adaptivequizid' => $instance->id,
            'qbankid' => $qbank->id,
        ]);

        $this->adaptivequiz = clone($instance);
        $this->adaptivequiz->context = context_module::instance($instance->cmid);
        $this->user = $coregenerator->create_user();
        $this->setUser($this->user);
    }

    /**
     * Returns a fresh attempt object with its question usage initialised, as a new request would.
     *
     * @return attempt
     */
    private function new_request(): attempt {
        $attempt = new attempt($this->adaptivequiz, $this->user->id);
        $attempt->set_level((int) $this->adaptivequiz->startinglevel);
        $attempt->get_attempt();
        $attempt->initialize_quba();

        return $attempt;
    }

    /**
     * Resets what the fixture CAT model answers.
     */
    protected function tearDown(): void {
        serve_fixed_question_administration::$questionid = null;
        serve_fixed_question_administration::$slot = null;
        serve_fixed_question_administration::$questionsequence = [];

        parent::tearDown();
    }

    /**
     * The built-in algorithm answers with a slot, and that slot reaches the attempt.
     */
    public function test_built_in_algorithm_writes_the_slot_back(): void {
        $this->resetAfterTest();
        $this->set_up_activity();

        $attempt = $this->new_request();
        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);

        $this->assertNotNull($evaluation);
        $this->assertFalse($evaluation->item_administration_is_to_stop());
        $this->assertSame(1, $attempt->get_question_slot_number());
    }

    /**
     * A question named by the CAT model is put into the usage by the host.
     */
    public function test_question_named_by_the_catmodel_is_added_once(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        serve_fixed_question_administration::$questionid = $this->questionids[0];

        $attempt = $this->new_request();
        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);

        $this->assertNotNull($evaluation);
        $this->assertSame(1, $attempt->get_question_slot_number());
        $this->assertCount(1, $attempt->get_quba()->get_slots());
    }

    /**
     * A second request without an answer reuses the slot instead of adding another one.
     */
    public function test_reload_reuses_the_active_slot(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        serve_fixed_question_administration::$questionid = $this->questionids[0];

        $first = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $first);

        $second = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $second);

        $this->assertSame(1, $second->get_question_slot_number(), 'The reload moved to a new slot.');
        $this->assertCount(1, $second->get_quba()->get_slots(), 'The reload added a second question.');
    }

    /**
     * Even a CAT model that changes its mind must not get a second active slot.
     */
    public function test_a_different_question_on_reload_still_reuses_the_slot(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');

        serve_fixed_question_administration::$questionid = $this->questionids[0];
        $first = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $first);

        // The CAT model now names a different question, as it may after a reload.
        serve_fixed_question_administration::$questionid = $this->questionids[1];
        $second = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $second);

        $this->assertCount(
            1,
            $second->get_quba()->get_slots(),
            'A changed choice on reload appended a slot instead of reusing the active one.'
        );
        $this->assertSame(1, $second->get_question_slot_number());
    }

    /**
     * A CAT model may answer with a slot instead of a question, and that slot must reach the attempt.
     *
     * The built-in algorithm sets the slot number on the attempt itself, so this write-back only
     * shows when a CAT model answers by slot. Without it the attempt keeps whatever number it
     * carried before, and rendering the question fails.
     */
    public function test_slot_named_by_the_catmodel_reaches_the_attempt(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');

        // Put a question into the usage first, so there is a slot to name.
        serve_fixed_question_administration::$questionid = $this->questionids[0];
        $first = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $first);

        serve_fixed_question_administration::$questionid = null;
        serve_fixed_question_administration::$slot = 1;

        $second = $this->new_request();
        $this->assertSame(0, $second->get_question_slot_number());

        cat_session::administer_next_item($this->adaptivequiz, $second);

        $this->assertSame(1, $second->get_question_slot_number());
    }

    /**
     * Answers the question in the given slot of the attempt.
     *
     * @param attempt $attempt The running attempt.
     * @param bool $correct Whether to answer correctly.
     * @return callable The helper cat_session applies to the question usage.
     */
    private function answer_helper(attempt $attempt, bool $correct): callable {
        $slot = $attempt->get_question_slot_number();

        return function (\question_usage_by_activity $quba) use ($slot, $correct): void {
            $time = time();
            $quba->process_all_actions($time, $quba->prepare_simulated_post_data([
                $slot => ['answer' => $correct],
            ]));
            $quba->finish_all_questions($time);
        };
    }

    /**
     * Processing an answer recalculates the ability estimate of the attempt.
     */
    public function test_answer_is_processed_by_the_built_in_algorithm(): void {
        global $DB;

        $this->resetAfterTest();
        $this->set_up_activity();

        $attempt = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $attempt);

        $before = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $attempt->get_quba()->get_id()]);

        $result = cat_session::process_administered_item_result(
            (int) $attempt->get_quba()->get_id(),
            $this->adaptivequiz,
            $attempt,
            $this->answer_helper($attempt, true)
        );

        $after = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $attempt->get_quba()->get_id()]);

        $this->assertSame(5, $result->answereddifficulty, 'The difficulty was not read from the question tag.');
        $this->assertEquals(
            $before->questionsattempted + 1,
            $after->questionsattempted,
            'The answered question was not counted.'
        );
        $this->assertNotEquals(
            $before->difficultysum,
            $after->difficultysum,
            'The answered difficulty was not added to the running sum.'
        );
        $this->assertNotEquals('', (string) $result->standarderror);
    }

    /**
     * With a CAT model the host records the answer but leaves the estimate to the subplugin.
     */
    public function test_answer_of_a_catmodel_instance_is_handed_over(): void {
        global $DB;

        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        serve_fixed_question_administration::$questionid = $this->questionids[0];

        $attempt = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $attempt);

        adaptivequizcatmodel_testcatmodel_reset_processed_items();

        $result = cat_session::process_administered_item_result(
            (int) $attempt->get_quba()->get_id(),
            $this->adaptivequiz,
            $attempt,
            $this->answer_helper($attempt, true)
        );

        $this->assertNull($result->answereddifficulty, 'The host judged the answer although a CAT model is in use.');
        $this->assertFalse($result->attempt_is_to_stop());
        $this->assertSame(
            1,
            adaptivequizcatmodel_testcatmodel_processed_items(),
            'The CAT model was not told about the answered item.'
        );

        $after = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $attempt->get_quba()->get_id()]);
        $this->assertEquals(1, $after->questionsattempted);
    }

    /**
     * A CAT model learns about a new attempt exactly once, before the first item.
     */
    public function test_catmodel_is_told_about_a_new_attempt_once(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        serve_fixed_question_administration::$questionid = $this->questionids[0];

        adaptivequizcatmodel_testcatmodel_reset_created();

        $first = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $first);
        $this->assertSame(1, adaptivequizcatmodel_testcatmodel_created_attempts());

        // A second request continues the same attempt and must not announce it again.
        $second = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $second);
        $this->assertSame(
            1,
            adaptivequizcatmodel_testcatmodel_created_attempts(),
            'A continued attempt was announced as a new one.'
        );
    }

    /**
     * A CAT model that stops is passed through unchanged, without touching the usage.
     */
    public function test_stopping_catmodel_is_passed_through(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');

        $attempt = $this->new_request();
        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);

        $this->assertNotNull($evaluation);
        $this->assertTrue($evaluation->item_administration_is_to_stop());
        $this->assertCount(0, $attempt->get_quba()->get_slots());
    }
    /**
     * Answers the first item and returns the next request of the same attempt.
     *
     * @return attempt
     */
    private function after_answering_the_first_item(): attempt {
        $attempt = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $attempt);
        cat_session::process_administered_item_result(
            (int) $attempt->get_quba()->get_id(),
            $this->adaptivequiz,
            $attempt,
            $this->answer_helper($attempt, true)
        );

        return $this->new_request();
    }

    /**
     * A CAT model that names an answered item again is asked once more, and the test goes on.
     *
     * The host must not end a test because of it - a test always goes on: a CAT model
     * keeping its own exclusion set names another item when asked again.
     */
    public function test_an_answered_item_is_asked_for_again_and_the_test_goes_on(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        serve_fixed_question_administration::$questionid = $this->questionids[0];
        $attempt = $this->after_answering_the_first_item();

        // Named again: the answered one first, then another one.
        serve_fixed_question_administration::$questionsequence = [$this->questionids[0], $this->questionids[1]];
        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);
        $this->assertDebuggingCalled();

        $this->assertFalse($evaluation->item_administration_is_to_stop(), 'The test was ended.');
        $slots = $attempt->get_quba()->get_slots();
        $this->assertCount(2, $slots);
        $this->assertEquals($this->questionids[1], $attempt->get_quba()->get_question(end($slots))->id);
    }

    /**
     * A CAT model that insists on an answered item still gets it; the test goes on.
     *
     * The item is administered once more and stays visible as a technical duplicate in the history.
     */
    public function test_an_insisting_cat_model_gets_its_item_and_the_test_goes_on(): void {
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        serve_fixed_question_administration::$questionid = $this->questionids[0];
        $attempt = $this->after_answering_the_first_item();

        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);
        $this->assertDebuggingCalledCount(3);

        $this->assertFalse($evaluation->item_administration_is_to_stop(), 'The test was ended.');
        $slots = $attempt->get_quba()->get_slots();
        $this->assertCount(2, $slots, 'The test did not go on with the item.');
        $this->assertEquals($this->questionids[0], $attempt->get_quba()->get_question(end($slots))->id);
    }

    /**
     * The maximum limits every question asked: with 3, no fourth slot - whatever the CAT model
     * names (issue #19). In production a test with a maximum of 25 asked 33 questions, the CAT
     * model counting each repeated item once.
     */
    public function test_no_slot_beyond_the_maximum(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        $DB->set_field('adaptivequiz', 'maximumquestions', 3, ['id' => $this->adaptivequiz->id]);
        $this->adaptivequiz->maximumquestions = 3;

        // The CAT model names questions without ever stopping on its own.
        serve_fixed_question_administration::$questionid = $this->questionids[3];
        serve_fixed_question_administration::$questionsequence = array_slice($this->questionids, 0, 3);
        for ($asked = 1; $asked <= 3; $asked++) {
            $attempt = $this->new_request();
            $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);
            $this->assertFalse($evaluation->item_administration_is_to_stop(), "Question $asked must be asked.");
            cat_session::process_administered_item_result(
                (int) $attempt->get_quba()->get_id(),
                $this->adaptivequiz,
                $attempt,
                $this->answer_helper($attempt, true)
            );
        }

        $attempt = $this->new_request();
        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);

        $this->assertTrue($evaluation->item_administration_is_to_stop(), 'A fourth question was administered.');
        $this->assertSame(get_string('maxquestattempted', 'adaptivequiz'), $evaluation->stoppage_reason());
        $this->assertSame(stop_reason::MAXIMUM, $evaluation->stop_code(), 'The regular end, without a notice (issue #20).');
        $this->assertCount(3, $attempt->get_quba()->get_slots());
    }

    /**
     * Repeated requests after the maximum - a reload, a double click - add nothing either.
     */
    public function test_repeated_requests_after_the_maximum_add_no_slot(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_up_activity('testcatmodel');
        $DB->set_field('adaptivequiz', 'maximumquestions', 1, ['id' => $this->adaptivequiz->id]);
        $this->adaptivequiz->maximumquestions = 1;
        serve_fixed_question_administration::$questionid = $this->questionids[0];
        $attempt = $this->after_answering_the_first_item();

        serve_fixed_question_administration::$questionid = $this->questionids[1];
        for ($request = 0; $request < 3; $request++) {
            $attempt = $this->new_request();
            $this->assertTrue(cat_session::administer_next_item($this->adaptivequiz, $attempt)->item_administration_is_to_stop());
        }
        $this->assertCount(1, $attempt->get_quba()->get_slots());
    }

    /**
     * A concurrent request - a double click, a second tab - adds no slot while another one administers.
     *
     * The lock is held as the first request would hold it; the second request gets nothing and
     * leaves the usage alone. Once the lock is free, the next request administers as usual (issue #19).
     */
    public function test_a_concurrent_request_adds_no_slot(): void {
        global $CFG;
        $this->resetAfterTest();
        // A lock that one process cannot take twice, as two requests cannot.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        $this->set_up_activity('testcatmodel');
        serve_fixed_question_administration::$questionid = $this->questionids[0];

        $held = \core\lock\lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_itemadministration_' . $this->adaptivequiz->id . '_' . $this->user->id, 0);
        $this->assertNotFalse($held);
        // The lock factory counts in whole seconds: two seconds wait at least one.
        cat_session::$locktimeout = 2;
        try {
            $attempt = $this->new_request();
            \mod_adaptivequiz\local\request_timing::reset();
            $this->assertNull(cat_session::administer_next_item($this->adaptivequiz, $attempt), 'The lock was not respected.');
            $this->assertCount(0, $attempt->get_quba()->get_slots());
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
        $this->assertSame([], array_filter(
            \mod_adaptivequiz\local\request_timing::get_spans(),
            fn($span) => $span['name'] === 'select_item'
        ), 'Nothing was selected while waiting.');

        $attempt = $this->new_request();
        $this->assertNotNull(cat_session::administer_next_item($this->adaptivequiz, $attempt));
        $this->assertCount(1, $attempt->get_quba()->get_slots());
    }

    /**
     * A fixed-length test (minimum = maximum) ends with an estimate (issue #14).
     *
     * The attempt record counts the answer being processed only afterwards, so the algorithm was
     * never ready on the last answer: the attempt ended at measure 0, standard error 0 - graded
     * as the middle of the range, whatever was answered.
     */
    public function test_fixed_length_test_ends_with_an_estimate(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_up_activity();
        $DB->set_field('adaptivequiz', 'minimumquestions', 2, ['id' => $this->adaptivequiz->id]);
        $DB->set_field('adaptivequiz', 'maximumquestions', 2, ['id' => $this->adaptivequiz->id]);
        $this->adaptivequiz->minimumquestions = 2;
        $this->adaptivequiz->maximumquestions = 2;

        $measures = [];
        foreach ([true, false] as $correct) {
            $this->user = $this->getDataGenerator()->create_user();
            $this->setUser($this->user);
            for ($answered = 0; $answered < 2; $answered++) {
                $attempt = $this->new_request();
                $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);
                $this->assertFalse($evaluation->item_administration_is_to_stop());
                cat_session::process_administered_item_result(
                    (int) $attempt->get_quba()->get_id(),
                    $this->adaptivequiz,
                    $attempt,
                    $this->answer_helper($attempt, $correct)
                );
            }
            $record = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $attempt->get_quba()->get_id()]);
            $this->assertGreaterThan(0.0, (float) $record->standarderror, 'The last answer leaves an estimate.');
            $measures[$correct ? 'right' : 'wrong'] = (float) $record->measure;
        }
        $this->assertGreaterThan($measures['wrong'], $measures['right'], 'The estimate follows the answers.');
    }

    /**
     * An answer is stored before the test can end (issue #20, DoD 3): what the notice of an automatic end says -
     * "the answers you submitted are saved" - holds for the answer that was being processed when the time ran out.
     */
    public function test_answer_is_stored_before_the_test_ends(): void {
        global $DB;
        $this->resetAfterTest();
        $this->set_up_activity();
        $attempt = $this->new_request();
        cat_session::administer_next_item($this->adaptivequiz, $attempt);
        $uniqueid = (int) $attempt->get_quba()->get_id();

        $answer = $this->answer_helper($attempt, true);
        cat_session::process_administered_item_result($uniqueid, $this->adaptivequiz, $attempt, $answer);
        adaptivequiz_complete_attempt(
            $uniqueid,
            $this->adaptivequiz,
            $this->adaptivequiz->context,
            (int) $this->user->id,
            '0.3',
            'The time limit was reached.',
            stop_reason::TIMELIMIT
        );

        $quba = \question_engine::load_questions_usage_by_activity($uniqueid);
        $this->assertTrue($quba->get_question_state(1)->is_finished(), 'The answer is stored.');
        $this->assertSame('gradedright', (string) $quba->get_question_state(1));
        $record = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid]);
        $this->assertSame(stop_reason::TIMELIMIT, $record->stopcode);
        $this->assertStringContainsString(
            get_string('stopnotice_timelimit', 'adaptivequiz'),
            \mod_adaptivequiz\local\attempt\stop_reason::notice($record)->text
        );
    }
}
