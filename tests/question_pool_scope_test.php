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
use stdClass;

/**
 * How many questions are left per difficulty is a property of one activity, not of a session.
 *
 * The count lives in $SESSION->adpqtagquestsum. It used to be a flat array with no reference to an
 * activity: two adaptive quizzes taken one after the other in the same session shared it, and the
 * second one worked from numbers that described the first one's question pool.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_adaptivequiz\local\fetchquestion::class)]
final class question_pool_scope_test extends advanced_testcase {
    /**
     * Builds an activity whose pool holds the given number of questions on each given level.
     *
     * @param stdClass $course The course to build in.
     * @param int $questionsperlevel How many questions to create on each level.
     * @param int[] $levels The difficulty levels to fill.
     * @return stdClass The activity instance, with its context attached.
     */
    private function create_activity(stdClass $course, int $questionsperlevel, array $levels): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $coregenerator = $this->getDataGenerator();
        /** @var \mod_adaptivequiz_generator $modgenerator */
        $modgenerator = $coregenerator->get_plugin_generator('mod_adaptivequiz');
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $coregenerator->get_plugin_generator('core_question');
        /** @var \mod_qbank_generator $qbankgenerator */
        $qbankgenerator = $coregenerator->get_plugin_generator('mod_qbank');

        $qbank = $qbankgenerator->create_instance(['course' => $course->id]);
        $qbankcm = get_coursemodule_from_instance('qbank', $qbank->id, 0, false, MUST_EXIST);
        $qcategory = question_get_default_category(context_module::instance($qbankcm->id)->id);

        foreach ($levels as $level) {
            for ($created = 0; $created < $questionsperlevel; $created++) {
                $question = $questiongenerator->create_question('truefalse', null, ['category' => $qcategory->id]);
                $questiongenerator->create_question_tag([
                    'questionid' => $question->id,
                    'tag' => \ADAPTIVEQUIZ_QUESTION_TAG . $level,
                ]);
            }
        }

        $instance = $modgenerator->create_instance([
            'course' => $course->id,
            'startinglevel' => 5,
            'lowestlevel' => 4,
            'highestlevel' => 6,
            'minimumquestions' => 1,
            'maximumquestions' => 10,
        ]);
        $modgenerator->create_link_with_question_bank([
            'adaptivequizid' => $instance->id,
            'qbankid' => $qbank->id,
        ]);

        $activity = clone($instance);
        $activity->context = context_module::instance($instance->cmid);

        return $activity;
    }

    /**
     * Each activity is answered from its own question pool.
     *
     * There is no shared state left to get this wrong - the pool sizes are read per activity and
     * the questions an attempt has seen are subtracted - but that is exactly what this pins: a
     * narrow activity taken first must not make a wide one look empty.
     */
    public function test_each_activity_is_answered_from_its_own_pool(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $narrow = $this->create_activity($course, 1, [5]);
        $wide = $this->create_activity($course, 3, [4, 5, 6]);

        // Take the narrow activity first and use up its only question.
        $attempt = new \mod_adaptivequiz\local\attempt($narrow, $user->id);
        $attempt->set_level(5);
        $attempt->get_attempt();
        $attempt->initialize_quba();
        $this->assertFalse(
            cat_session::administer_next_item($narrow, $attempt)->item_administration_is_to_stop()
        );

        // The wide activity has a pool of its own and must see it.
        $attempt = new \mod_adaptivequiz\local\attempt($wide, $user->id);
        $attempt->set_level(5);
        $attempt->get_attempt();
        $attempt->initialize_quba();
        $evaluation = cat_session::administer_next_item($wide, $attempt);

        $this->assertFalse(
            $evaluation->item_administration_is_to_stop(),
            'The second activity refused a question although its own pool holds nine: '
                . $evaluation->stoppage_reason()
        );
    }

    /**
     * A difficulty whose questions are all used up counts as empty.
     *
     * The count is derived, not booked: the questions the attempt has already seen are subtracted
     * from the pool every time the next item is asked for.
     */
    public function test_used_questions_are_subtracted_from_the_pool(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $activity = $this->create_activity($course, 1, [5]);

        $fetch = new \mod_adaptivequiz\local\fetchquestion($activity, 5, 4, 6);
        $all = $fetch->fetch_questions();
        $this->assertCount(1, $all, 'The pool of the activity does not hold the expected question.');

        $fetch = new \mod_adaptivequiz\local\fetchquestion($activity, 5, 4, 6);
        $this->assertEmpty(
            $fetch->fetch_questions($all),
            'A question already used in the attempt was offered again.'
        );
    }
}
