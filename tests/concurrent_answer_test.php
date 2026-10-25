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

use advanced_testcase;
use context_module;
use mod_adaptivequiz\local\attempt;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once(__DIR__ . '/fixtures/concurrent_processes.php');

/**
 * Two answers to the same slot at the same time - a double click on "Submit", a second tab (audit 18.10.).
 *
 * Two processes with connections of their own take the path attempt.php takes: the answer is processed
 * and saved, then the next question is administered under the item lock. One answer step is recorded,
 * one next question added, the attempt counts one answer, and neither request ends on an error page.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversMethod(cat_session::class, 'process_administered_item_result')]
final class concurrent_answer_test extends advanced_testcase {
    use \mod_adaptivequiz_concurrent_processes;

    /** @var stdClass The activity, with its context. */
    private stdClass $adaptivequiz;

    /** @var stdClass The person answering. */
    private stdClass $user;

    /** @var stdClass The course. */
    private stdClass $course;

    /** @var int The question bank of the activities. */
    private int $qbankid;

    /**
     * An activity driven by the built-in algorithm, with questions on every level.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The processes would not see what this one wrote inside the test's transaction.
        $this->preventResetByRollback();

        $generator = $this->getDataGenerator();
        $course = $this->course = $generator->create_course();
        $qbank = $generator->get_plugin_generator('mod_qbank')->create_instance(['course' => $course->id]);
        $this->qbankid = (int) $qbank->id;
        $qbankcm = get_coursemodule_from_instance('qbank', $qbank->id, 0, false, MUST_EXIST);
        $category = question_get_default_category(context_module::instance($qbankcm->id)->id);
        $questions = $generator->get_plugin_generator('core_question');
        for ($level = 1; $level <= 10; $level++) {
            for ($copy = 0; $copy < 2; $copy++) {
                $question = $questions->create_question('truefalse', null, ['category' => $category->id]);
                $questions->create_question_tag(['questionid' => $question->id, 'tag' => 'adpq_' . $level]);
            }
        }
        $this->use_activity([]);
        $this->user = $generator->create_user();
        $this->setUser($this->user);
    }

    /**
     * Makes the activity under test, on the questions of the course.
     *
     * @param array $settings Overrides of the activity's settings.
     */
    private function use_activity(array $settings): void {
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz');
        $instance = $modgenerator->create_instance($settings + [
            'course' => $this->course->id, 'startinglevel' => 5, 'lowestlevel' => 1, 'highestlevel' => 10,
            'minimumquestions' => 1, 'maximumquestions' => 10, 'standarderror' => 5,
        ]);
        $modgenerator->create_link_with_question_bank(['adaptivequizid' => $instance->id, 'qbankid' => $this->qbankid]);
        $this->adaptivequiz = clone($instance);
        $this->adaptivequiz->context = context_module::instance($instance->cmid);
    }

    /**
     * One POST of the answer, the way attempt.php handles it - up to the question it would show.
     *
     * @param int $uniqueid
     * @param array $postdata The form as it was rendered.
     * @param int $slowms How long the request that processed the answer takes before it goes on - the
     *      window in which a duplicate catches up.
     * @return array The slot and question shown, or finished; or the error page the request would end on.
     */
    private function post_answer(int $uniqueid, array $postdata, int $slowms = 0): array {
        $attempt = new attempt($this->adaptivequiz, $this->user->id);
        $context = $this->adaptivequiz->context;
        try {
            $result = cat_session::process_administered_item_result(
                $uniqueid,
                $this->adaptivequiz,
                $attempt,
                function (\question_usage_by_activity $quba) use ($postdata): void {
                    $quba->process_all_actions(time(), $postdata);
                    $quba->finish_all_questions(time());
                },
                $context
            );
        } catch (\question_out_of_sequence_exception $e) {
            return ['errorpage' => 'submissionoutofsequencefriendlymessage'];
        } catch (\Exception $e) {
            return ['errorpage' => 'errorprocessingresponses: ' . get_class($e) . ': ' . $e->getMessage()];
        }
        if ($result->processed && $slowms) {
            usleep($slowms * 1000);
        }
        if ($result->attempt_is_to_stop()) {
            if (!$result->completed) {
                cat_session::complete_attempt(
                    $uniqueid,
                    $this->adaptivequiz,
                    $context,
                    (int) $this->user->id,
                    (string) $result->standarderror,
                    $result->stoppagereason,
                    $result->stopcode
                );
            }
            return ['finished' => true];
        }
        $attempt->set_level($result->nextdifficulty ?? (int) $this->adaptivequiz->startinglevel);
        if ($result->answereddifficulty !== null) {
            $attempt->set_last_difficulty_level($result->answereddifficulty);
        }
        if ($attempt->continue_attempt($uniqueid) === null) {
            return ['finished' => true];
        }
        $attempt->initialize_quba();
        $evaluation = cat_session::administer_next_item($this->adaptivequiz, $attempt);
        if ($evaluation === null) {
            return $attempt->still_in_progress() ? ['redirect' => 'view'] : ['finished' => true];
        }
        if ($evaluation->item_administration_is_to_stop()) {
            cat_session::complete_attempt(
                $uniqueid,
                $this->adaptivequiz,
                $context,
                (int) $this->user->id,
                (string) $result->standarderror,
                (string) $evaluation->stoppage_reason(),
                $evaluation->stop_code()
            );
            return ['finished' => true];
        }
        $slot = (int) $attempt->get_question_slot_number();
        return ['slot' => $slot, 'questionid' => (int) $attempt->get_quba()->get_question($slot)->id];
    }

    /**
     * The first request: the attempt and its first question, as the person sees it.
     *
     * @return array [usage id, slot, post data of the form answering it]
     */
    private function first_question(): array {
        $attempt = new attempt($this->adaptivequiz, $this->user->id);
        $attempt->set_level((int) $this->adaptivequiz->startinglevel);
        $attempt->get_attempt();
        $attempt->initialize_quba();
        $this->assertNotNull(cat_session::administer_next_item($this->adaptivequiz, $attempt));
        $quba = $attempt->get_quba();
        $slot = (int) $attempt->get_question_slot_number();
        return [(int) $quba->get_id(), $slot, $quba->prepare_simulated_post_data([$slot => ['answer' => 1]])];
    }

    /**
     * Two answers to the same slot at the same time are processed once.
     */
    public function test_two_answers_to_the_same_slot_at_the_same_time(): void {
        global $DB;
        $this->require_concurrent_processes();
        // The first request: the attempt and its first question, as the person sees it.
        [$uniqueid, $slot, $postdata] = $this->first_question();

        $answers = $this->at_the_same_time(2, fn() => $this->post_answer($uniqueid, $postdata));

        foreach ($answers as $i => $answer) {
            $this->assertArrayNotHasKey('error', $answer, "Process $i: " . json_encode($answer));
            $answer = $answers[$i] = $answer['ok'];
            $this->assertArrayNotHasKey('errorpage', $answer, "Process $i ended on an error page: " . json_encode($answer));
            $this->assertArrayHasKey('slot', $answer, "Process $i shows a question: " . json_encode($answer));
        }
        $this->assertSame($answers[0], $answers[1], 'Both requests show the same next question.');
        $this->assertSame($slot + 1, $answers[0]['slot']);

        $record = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid], '*', MUST_EXIST);
        $this->assertEquals(1, $record->questionsattempted, 'The answer was counted once.');
        $this->assertEquals(2, $DB->count_records('question_attempts', ['questionusageid' => $uniqueid]), 'One next slot.');
        $questionattemptid = $DB->get_field(
            'question_attempts',
            'id',
            ['questionusageid' => $uniqueid, 'slot' => $slot],
            MUST_EXIST
        );
        $this->assertEquals(
            1,
            $DB->count_records_select(
                'question_attempt_steps',
                'questionattemptid = :id AND ' . $DB->sql_like('state', ':graded'),
                ['id' => $questionattemptid, 'graded' => 'graded%']
            ),
            'One answer step.'
        );
        $this->assertEquals(
            3,
            $DB->count_records('question_attempt_steps', ['questionattemptid' => $questionattemptid]),
            'The start of the question, the answer and its grading - nothing processed twice.'
        );
    }

    /**
     * The same form posted twice, one after the other: the second POST changes nothing and shows the next question.
     *
     * Without the check the question engine finds it out of sequence and the request ends on an error page.
     */
    public function test_the_same_answer_posted_again_changes_nothing(): void {
        global $DB;
        [$uniqueid, $slot, $postdata] = $this->first_question();

        $first = $this->post_answer($uniqueid, $postdata);
        $second = $this->post_answer($uniqueid, $postdata);

        $this->assertArrayHasKey('slot', $first, json_encode($first));
        $this->assertSame($first, $second, 'The second POST shows the question the first one left.');
        $record = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid], '*', MUST_EXIST);
        $this->assertEquals(1, $record->questionsattempted, 'The answer was counted once.');
        $this->assertEquals(2, $DB->count_records('question_attempts', ['questionusageid' => $uniqueid]));
    }

    /**
     * While another request holds the attempt the answer is left alone, without an error.
     */
    public function test_an_answer_waits_for_the_other_request_and_is_left_alone(): void {
        global $CFG, $DB;
        // A lock that one process cannot take twice, as two requests cannot.
        $CFG->lock_factory = '\\core\\lock\\db_record_lock_factory';
        [$uniqueid, $slot, $postdata] = $this->first_question();
        $held = \core\lock\lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_itemadministration_' . $this->adaptivequiz->id . '_' . $this->user->id, 0);
        $this->assertNotFalse($held);
        cat_session::$locktimeout = 0;
        $request = new attempt($this->adaptivequiz, $this->user->id);
        $helper = function (\question_usage_by_activity $quba) use ($postdata): void {
            $quba->process_all_actions(time(), $postdata);
            $quba->finish_all_questions(time());
        };
        try {
            $result = cat_session::process_administered_item_result($uniqueid, $this->adaptivequiz, $request, $helper);
            $processed = $result->processed;
        } finally {
            $held->release();
            cat_session::$locktimeout = 10;
        }

        $this->assertFalse($processed);
        $record = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid], '*', MUST_EXIST);
        $this->assertEquals(0, $record->questionsattempted);
        $this->assertTrue(\question_engine::load_questions_usage_by_activity($uniqueid)->get_question_state($slot)->is_active());
    }

    /**
     * Settings under which an answer ends the attempt - by the maximum, or by the standard error - and how
     * many answers come before it.
     *
     * @return array
     */
    public static function ending_settings(): array {
        return [
            'fixed length of one' => [['minimumquestions' => 1, 'maximumquestions' => 1], 0],
            // The estimate starts with the second answer; 45 % is reached with it.
            'standard error reached' => [['minimumquestions' => 1, 'maximumquestions' => 10, 'standarderror' => 45], 1],
        ];
    }

    /**
     * Two POSTs of the answer that ends the attempt, at the same time: it is completed once (audit 18.10.).
     *
     * The request that processed the answer is slow to go on; before, the duplicate found the attempt still
     * running in that window, administered and completed it itself - CAT model, grades and event twice -
     * or, ended by the standard error, even added another question to it.
     *
     * @param array $settings
     * @param int $before Answers before the one that ends the attempt.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ending_settings')]
    public function test_the_ending_answer_twice_completes_the_attempt_once(array $settings, int $before): void {
        global $DB;
        $this->require_concurrent_processes();
        $this->use_activity($settings);
        // The completion events of the processes, written at once to the database.
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        [$uniqueid, , $postdata] = $this->first_question();
        for ($i = 0; $i < $before; $i++) {
            $shown = $this->post_answer($uniqueid, $postdata);
            $this->assertArrayHasKey('slot', $shown, json_encode($shown));
            $postdata = \question_engine::load_questions_usage_by_activity($uniqueid)
                ->prepare_simulated_post_data([$shown['slot'] => ['answer' => 1]]);
        }

        $answers = $this->at_the_same_time(2, fn() => $this->post_answer($uniqueid, $postdata, 400));

        foreach ($answers as $i => $answer) {
            $this->assertSame(['ok' => ['finished' => true]], $answer, "Process $i: " . json_encode($answer));
        }
        $record = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid], '*', MUST_EXIST);
        $this->assertSame(\mod_adaptivequiz\local\attempt\attempt_state::COMPLETED, $record->attemptstate);
        $this->assertEquals($before + 1, $record->questionsattempted, 'The answer was counted once.');
        $this->assertEquals(
            $before + 1,
            $DB->count_records('question_attempts', ['questionusageid' => $uniqueid]),
            'No further question.'
        );
        $this->assertEquals(
            1,
            $DB->count_records('adaptivequiz_attempt', ['instance' => $this->adaptivequiz->id, 'userid' => $this->user->id]),
            'No new attempt.'
        );
        $this->assertEquals(
            1,
            $DB->count_records('logstore_standard_log', ['eventname' => '\mod_adaptivequiz\event\attempt_completed']),
            'Completed once.'
        );
        // The grade is recomputed from the attempts, not added up: one completed attempt, one grade.
        $item = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'adaptivequiz', 'iteminstance' => $this->adaptivequiz->id,
        ]);
        $this->assertNotFalse($item);
        $grades = $DB->get_records('grade_grades', ['itemid' => $item->id, 'userid' => $this->user->id]);
        $this->assertCount(1, $grades, 'One grade.');
    }

    /**
     * A request that dies after the answer was saved - before the next question - and the person posts again.
     *
     * The retry finds the answer processed, adds the next question once, and the answer is counted once.
     */
    public function test_a_retry_after_a_request_died_shows_the_next_question_once(): void {
        global $DB;
        [$uniqueid, $slot, $postdata] = $this->first_question();
        // The first request: the answer is processed, then the request ends - no item administration.
        cat_session::process_administered_item_result(
            $uniqueid,
            $this->adaptivequiz,
            new attempt($this->adaptivequiz, $this->user->id),
            function (\question_usage_by_activity $quba) use ($postdata): void {
                $quba->process_all_actions(time(), $postdata);
                $quba->finish_all_questions(time());
            },
            $this->adaptivequiz->context
        );

        $retry = $this->post_answer($uniqueid, $postdata);
        $again = $this->post_answer($uniqueid, $postdata);

        $this->assertArrayHasKey('slot', $retry, json_encode($retry));
        $this->assertSame($slot + 1, $retry['slot']);
        $this->assertSame($retry, $again, 'A further retry shows the same question.');
        $record = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid], '*', MUST_EXIST);
        $this->assertEquals(1, $record->questionsattempted, 'The answer was counted once.');
        $this->assertEquals(2, $DB->count_records('question_attempts', ['questionusageid' => $uniqueid]), 'One next slot.');
        $this->assertEquals(
            1,
            $DB->count_records('adaptivequiz_attempt', ['instance' => $this->adaptivequiz->id, 'userid' => $this->user->id])
        );
    }
}
