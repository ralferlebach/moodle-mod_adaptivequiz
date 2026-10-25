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
use context_course;
use context_module;
use mod_adaptivequiz\local\attempt;
use mod_adaptivequiz\local\request_timing;
use question_usage_by_activity;
use stdClass;

/**
 * The timeline of a request through the item administration (mod_adaptivequiz #17).
 *
 * This branch carries no test CAT model: the built-in algorithm drives the path, with one question on
 * the starting level so that the selection is the same on every run.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_adaptivequiz\cat_session
 * @covers     \mod_adaptivequiz\local\attempt
 */
final class cat_session_timeline_test extends advanced_testcase {
    /** @var stdClass The activity, with its context. */
    private stdClass $adaptivequiz;

    /** @var int Id of the only question on the starting level. */
    private int $questionid;

    /**
     * An activity with one question on its starting level.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $questions = $generator->get_plugin_generator('core_question');
        $course = $generator->create_course();
        $category = $questions->create_question_category(['contextid' => context_course::instance($course->id)->id]);
        $question = $questions->create_question('truefalse', null, ['category' => $category->id]);
        $questions->create_question_tag(['questionid' => $question->id, 'tag' => 'adpq_3']);
        $this->questionid = (int) $question->id;
        $this->adaptivequiz = $generator->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'questionpool' => [$category->id], 'lowestlevel' => 1, 'highestlevel' => 5,
            'startinglevel' => 3, 'minimumquestions' => 1, 'maximumquestions' => 3, 'standarderror' => 5,
        ]);
        $this->adaptivequiz->context = context_module::instance($this->adaptivequiz->cmid);
        request_timing::reset_request();
    }

    /**
     * Leaves nothing for the next test.
     */
    protected function tearDown(): void {
        request_timing::reset_request();
        parent::tearDown();
    }

    /**
     * The first request of a new person, as attempt.php makes it.
     *
     * @return attempt
     */
    private function first_request(): attempt {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $attempt = new attempt($this->adaptivequiz, $user->id);
        $attempt->set_level(3);
        cat_session::run_item_administration(0, $this->adaptivequiz, $this->adaptivequiz->context, $attempt);
        return $attempt;
    }

    /**
     * The first item of a new attempt shows its parts, each with duration and memory.
     */
    public function test_timeline_of_the_first_item(): void {
        $attempt = $this->first_request();

        $spans = [];
        $children = [];
        $usage = null;
        foreach (request_timing::get_spans() as $span) {
            $spans[$span['name']] = $span;
            if ($span['name'] === 'question_usage') {
                $usage = $span['depth'];
                continue;
            }
            if ($usage !== null && $span['depth'] <= $usage) {
                $usage = null;
            }
            if ($usage !== null && $span['depth'] === $usage + 1) {
                $children[] = $span['name'];
            }
        }
        $expected = ['lock_wait', 'attempt_create_lock_wait', 'attempt_create', 'select_item', 'load_question',
            'question_usage', 'quba_add', 'quba_start', 'quba_save'];
        foreach ($expected as $name) {
            $this->assertArrayHasKey($name, $spans, $name);
            $this->assertGreaterThanOrEqual(0, $spans[$name]['ms'], $name);
            $this->assertIsInt($spans[$name]['meta']['memkb'], $name);
            $this->assertIsInt($spans[$name]['meta']['peakkb'], $name);
        }
        $this->assertSame(['quba_add', 'quba_start', 'quba_save'], $children);
        $this->assertSame(1, $spans['question_usage']['meta']['slots']);
        $this->assertIsInt($spans['load_question']['meta']['dbreads']);
        $this->assertTrue($spans['attempt_create_lock_wait']['meta']['acquired']);

        // The request knows its attempt: the first question of it.
        $record = $attempt->get_attempt();
        $this->assertSame(['attemptid' => (int) $record->id, 'questionnumber' => 1], request_timing::attempt());
    }

    /**
     * Answering shows its parts: the usage loaded, the answer processed and saved.
     */
    public function test_timeline_of_an_answer(): void {
        $attempt = $this->first_request();
        $slot = $attempt->get_question_slot_number();
        $uniqueid = (int) $attempt->get_attempt()->uniqueid;
        request_timing::reset();

        cat_session::process_administered_item_result(
            $uniqueid,
            $this->adaptivequiz,
            new attempt($this->adaptivequiz, $GLOBALS['USER']->id),
            function (question_usage_by_activity $quba) use ($slot): void {
                $quba->process_all_actions(time(), $quba->prepare_simulated_post_data([$slot => ['answer' => true]]));
                $quba->finish_all_questions(time());
            }
        );

        $this->assertSame(
            ['answer_lock_wait', 'quba_load', 'quba_process', 'quba_save'],
            array_column(request_timing::get_spans(), 'name')
        );
    }

    /**
     * Measuring changes nothing: with a full buffer - nothing recorded - the same question in the same slot.
     */
    public function test_selection_is_the_same_with_and_without_measuring(): void {
        $measured = $this->first_request();
        $this->assertNotEmpty(request_timing::get_spans());

        request_timing::reset_request();
        for ($i = 0; $i < request_timing::MAX_SPANS; $i++) {
            request_timing::measure('filler', fn() => null);
        }
        $unmeasured = $this->first_request();
        $this->assertSame(['filler'], array_unique(array_column(request_timing::get_spans(), 'name')));
        $this->assertGreaterThan(0, request_timing::summary()['dropped']);

        $this->assertSame($measured->get_question_slot_number(), $unmeasured->get_question_slot_number());
        foreach ([$measured, $unmeasured] as $attempt) {
            $quba = $attempt->get_quba();
            $this->assertSame($this->questionid, (int) $quba->get_question($attempt->get_question_slot_number())->id);
            $this->assertCount(1, $quba->get_slots());
        }
    }
}
