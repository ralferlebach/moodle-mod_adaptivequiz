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

use coding_exception;
use core\lock\lock_config;
use core_tag_tag;
use mod_adaptivequiz\local\attempt;
use mod_adaptivequiz\local\catalgo;
use moodle_exception;
use mod_adaptivequiz\local\catmodel\catmodel_resolver;
use mod_adaptivequiz\local\itemadministration\default_item_administration_factory;
use mod_adaptivequiz\local\itemadministration\item_administration_evaluation;
use mod_adaptivequiz\local\itemadministration\item_administration_factory;
use question_bank;
use question_engine;
use question_usage_by_activity;
use stdClass;

/**
 * The parts of a running CAT session that must not live inside attempt.php.
 *
 * attempt.php is a script: it cannot be instantiated in a test, so anything decided there is
 * decided untested. Whatever can be answered without the request context belongs here instead.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cat_session {
    /**
     * Returns the item administration factory that drives the given activity.
     *
     * The CAT model of the activity if it offers one, the built-in algorithm otherwise. This is
     * the single place where the activity decides who picks the next question.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @return item_administration_factory
     */
    public static function item_administration_factory_for(stdClass $adaptivequiz): item_administration_factory {
        $factory = catmodel_resolver::handler($adaptivequiz->catmodel ?? null, item_administration_factory::class);

        return $factory ?? new default_item_administration_factory();
    }

    /**
     * Administers the next item of a running attempt, whoever picks it.
     *
     * The whole step runs under a lock keyed on activity and user. Without it a double click or a
     * concurrent AJAX request can have two requests pick a question at the same time, each adding
     * its own slot for the same item. A CAT attempt only ever has one active unanswered slot.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @param attempt $attempt The running attempt, with its question usage already initialised.
     * @return item_administration_evaluation|null The evaluation, or null when a concurrent
     *      request is already administering an item for this attempt.
     */
    public static function administer_next_item(stdClass $adaptivequiz, attempt $attempt): ?item_administration_evaluation {
        global $USER;

        $lock = lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_itemadministration_' . $adaptivequiz->id . '_' . $USER->id, 10);

        if (!$lock) {
            return null;
        }

        try {
            return self::administer_next_item_locked($adaptivequiz, $attempt);
        } finally {
            $lock->release();
        }
    }

    /**
     * Does the work of administer_next_item(), with the lock already held.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @param attempt $attempt The running attempt.
     * @return item_administration_evaluation
     */
    private static function administer_next_item_locked(
        stdClass $adaptivequiz,
        attempt $attempt
    ): item_administration_evaluation {
        // A CAT model sets up its own record for the attempt and starts its clock. It has to learn
        // about a new attempt before the first item is administered - afterwards its estimate would
        // already be expected to exist.
        if ($attempt->was_just_created()) {
            catmodel_resolver::callback(
                $adaptivequiz->catmodel ?? null,
                'post_create_attempt_callback',
                $adaptivequiz,
                $attempt
            );
        }

        $quba = $attempt->get_quba();
        $slots = $quba->get_slots();

        $administration = self::item_administration_factory_for($adaptivequiz)
            ->item_administration_implementation($quba, $attempt, $adaptivequiz);

        $evaluation = $administration->evaluate_ability_to_administer_next_item(
            !empty($slots) ? end($slots) : null
        );

        if ($evaluation->item_administration_is_to_stop()) {
            return $evaluation;
        }

        $slot = $evaluation->next_item()->quba_slot();

        if ($slot !== null) {
            // The built-in algorithm has put the question into the usage itself and answers with
            // its slot. The number still has to be written back: attempt.php reads it from the
            // attempt to render the question.
            $attempt->set_question_slot_number($slot);

            return $evaluation;
        }

        // A CAT model only names the question - the usage belongs to the host, so the host puts it in.
        $questionid = $evaluation->next_item()->question_id();

        // On a reload the CAT model may name a different question than the one already sitting in
        // the active, unanswered slot. Reusing that slot is the intended outcome: appending a new
        // one would make the visible question number grow with every reload.
        $existing = self::active_slot_for_question($quba, $questionid) ?? self::any_active_slot($quba);

        if ($existing !== null) {
            $attempt->set_question_slot_number($existing);

            return $evaluation;
        }

        $slot = $quba->add_question(question_bank::load_question($questionid));

        if (!$quba->get_question_state($slot)->is_active()) {
            $quba->start_question($slot);
            question_engine::save_questions_usage_by_activity($quba);

            if (count($quba->get_slots()) == 1) {
                $attempt->set_quba_id($quba->get_id());
            }
        }

        $attempt->set_question_slot_number($slot);

        return $evaluation;
    }

    /**
     * Returns the active slot holding the given question, if there is one.
     *
     * @param question_usage_by_activity $quba The question usage of the attempt.
     * @param int $questionid Id of the question to look for.
     * @return int|null
     */
    private static function active_slot_for_question(question_usage_by_activity $quba, int $questionid): ?int {
        foreach ($quba->get_slots() as $slot) {
            if ((int) $quba->get_question($slot)->id === $questionid && $quba->get_question_state($slot)->is_active()) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Returns any active slot of the usage, if there is one.
     *
     * @param question_usage_by_activity $quba The question usage of the attempt.
     * @return int|null
     */
    private static function any_active_slot(question_usage_by_activity $quba): ?int {
        foreach ($quba->get_slots() as $slot) {
            if ($quba->get_question_state($slot)->is_active()) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Processes the answer to the item that was administered last.
     *
     * The host runs the question engine and then hands over: to the CAT model of the activity if
     * there is one, otherwise to the built-in algorithm, which recalculates the ability estimate
     * and decides whether the attempt goes on.
     *
     * @param int $uniqueid Id of the question usage of the attempt.
     * @param stdClass $adaptivequiz The activity instance record.
     * @param attempt $attempt The running attempt.
     * @param callable $qubahelper Applies the submitted actions to the question usage.
     * @return item_result_processing
     */
    public static function process_administered_item_result(
        int $uniqueid,
        stdClass $adaptivequiz,
        attempt $attempt,
        callable $qubahelper
    ): item_result_processing {
        global $USER;

        $attemptrecord = $attempt->get_attempt();

        if (!adaptivequiz_uniqueid_part_of_attempt($uniqueid, (int) $adaptivequiz->id, (int) $USER->id)) {
            throw new moodle_exception('uniquenotpartofattempt', 'adaptivequiz');
        }

        $quba = question_engine::load_questions_usage_by_activity($uniqueid);
        $qubahelper($quba);
        question_engine::save_questions_usage_by_activity($quba);

        $result = new item_result_processing();

        if (!empty($adaptivequiz->catmodel)) {
            // The CAT model keeps its own estimate; the host only records that a question was answered.
            catmodel_resolver::callback(
                $adaptivequiz->catmodel,
                'post_process_item_result_callback',
                $quba,
                $adaptivequiz,
                $attempt
            );
            adaptivequiz_update_attempt_data($uniqueid, $adaptivequiz->id, $USER->id, 0, 0, 0);

            return $result;
        }

        $result->answereddifficulty = self::difficulty_of_last_administered_item($quba);

        $minattemptreached = adaptivequiz_min_attempts_reached($uniqueid, $adaptivequiz->id, $USER->id);
        $algorithm = new catalgo($quba, (int) $attemptrecord->id, $minattemptreached, $result->answereddifficulty);

        $result->nextdifficulty = $algorithm->perform_calculation_steps();
        $result->standarderror = (float) $algorithm->get_standarderror();

        $updated = adaptivequiz_update_attempt_data(
            $uniqueid,
            $adaptivequiz->id,
            $USER->id,
            $algorithm->get_levellogit(),
            $result->standarderror,
            $algorithm->get_measure()
        );

        if (!$updated) {
            throw new moodle_exception('unableupdatediffsum', 'adaptivequiz');
        }

        $result->stoppagereason = (string) $algorithm->get_status();

        if ($result->attempt_is_to_stop()) {
            return $result;
        }

        // Nothing to book here: how many questions of a difficulty are left is derived from the
        // pool and the questions this attempt has already seen, both read when the next item is
        // asked for. There is no running count that could fall out of step.

        return $result;
    }

    /**
     * Returns the difficulty level of the question in the last slot of the usage.
     *
     * Read from the tag of the question, not from the request: the level decides the whole
     * recalculation, and a posted value can say anything.
     *
     * @param question_usage_by_activity $quba The question usage of the attempt.
     * @return int
     */
    private static function difficulty_of_last_administered_item(question_usage_by_activity $quba): int {
        $slots = $quba->get_slots();

        if (empty($slots)) {
            throw new coding_exception('The question usage holds no administered item.');
        }

        $question = $quba->get_question(end($slots));
        $tags = core_tag_tag::get_item_tags('core_question', 'question', $question->id);

        foreach ($tags as $tag) {
            if (str_starts_with($tag->name, ADAPTIVEQUIZ_QUESTION_TAG)) {
                return (int) substr($tag->name, strlen(ADAPTIVEQUIZ_QUESTION_TAG));
            }
        }

        throw new coding_exception('The administered question carries no difficulty tag.');
    }
}
