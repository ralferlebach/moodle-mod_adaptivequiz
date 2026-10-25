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
use context_module;
use core_component;
use core_tag_tag;
use mod_adaptivequiz\local\attempt;
use mod_adaptivequiz\local\catalgo;
use mod_adaptivequiz\local\fetchquestion;
use mod_adaptivequiz\local\itemadministration\default_item_administration_factory;
use mod_adaptivequiz\local\itemadministration\item_administration_factory;
use mod_adaptivequiz\local\request_timing;
use moodle_exception;
use question_bank;
use question_engine;
use question_usage_by_activity;
use stdClass;

/**
 * High level API class to manage the process of CAT.
 *
 * @package    mod_adaptivequiz
 * @copyright  2024 Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cat_session {
    /** @var int Seconds a request waits for the lock of its attempt (mod_adaptivequiz #17: settable for tests). */
    public static int $locktimeout = 10;

    /** @var int How often the CAT model is asked when it names an item already answered. */
    private const MAX_ASKS = 3;


    /**
     * Chooses the next question and adds it to the quba instance to be presented to the user.
     *
     * @param int $uniqueid
     * @param stdClass $adaptivequiz
     * @param context_module $context
     * @param attempt $adaptiveattempt
     */
    public static function run_item_administration(
        int $uniqueid,
        stdClass $adaptivequiz,
        context_module $context,
        attempt $adaptiveattempt
    ): void {
        global $USER;

        // Issue #6: serialise item administration per attempt (adaptive quiz
        // instance + user). This closes the double-click / concurrent-request
        // race in which two requests would both select a question and each add
        // its own QUBA slot for the same item. AJAX and full page requests both
        // go through here, so the lock covers both.
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_adaptivequiz');
        $lockkey = 'adaptivequiz_itemadministration_' . $adaptivequiz->id . '_' . $USER->id;
        // How long the request waited for the lock is measured on its own: up to ten seconds of it
        // would otherwise look like slow item selection.
        request_timing::start('lock_wait');
        $lock = $lockfactory->get_lock($lockkey, self::$locktimeout);
        request_timing::stop('lock_wait', ['acquired' => (bool) $lock]);
        if (!$lock) {
            // A concurrent request for the same attempt is already administering
            // an item; bail out rather than risk creating a duplicate slot.
            return;
        }

        try {
            self::run_item_administration_locked($uniqueid, $adaptivequiz, $context, $adaptiveattempt);
        } finally {
            $lock->release();
        }
    }

    /**
     * The item administration body, executed while holding the per-attempt lock.
     *
     * @param int $uniqueid
     * @param stdClass $adaptivequiz
     * @param context_module $context
     * @param attempt $adaptiveattempt
     */
    private static function run_item_administration_locked(
        int $uniqueid,
        stdClass $adaptivequiz,
        context_module $context,
        attempt $adaptiveattempt
    ): void {
        global $USER;

        // A request for an attempt that another request completed meanwhile - the duplicate POST of the
        // answer that ended it, which found the attempt still running before it waited for this lock (audit
        // 18.10.). It changes nothing: no completion of its own, and no new attempt, which get_attempt() would
        // create now that none is in progress. The attempt.php shows the result of the attempt instead.
        if ($uniqueid != 0) {
            $finished = self::finished_attempt($uniqueid, $adaptivequiz, (int) $USER->id);
            if ($finished !== null) {
                $adaptiveattempt->set_status(
                    (string) ($finished->attemptstopcriteria ?: get_string('completed')),
                    $finished->stopcode ?: null
                );
                return;
            }
        }

        $attemptrecord = $adaptiveattempt->get_attempt();
        // Which attempt and which question this request is about - the first question of a new attempt or a
        // later one (mod_adaptivequiz #17). attempt.php reaches the attempt only here.
        request_timing::set_attempt((int) $attemptrecord->id, (int) $attemptrecord->questionsattempted + 1);
        $quba = $adaptiveattempt->initialize_quba($context);

        $message = $adaptiveattempt->get_status();
        if (!empty($message)) {
            // The attempt has been flagged as having a stoppage reason, complete it and exit here.
            self::complete_attempt_once(
                $uniqueid,
                $adaptivequiz,
                $context,
                $USER->id,
                $message,
                $adaptiveattempt->get_stop_code()
            );

            return;
        }

        $itemadministrationfactory = $adaptivequiz->catmodel
            ? self::catmodel_item_administration_factory($adaptivequiz->catmodel)
            : new default_item_administration_factory();

        $slots = $quba->get_slots();
        $previousslot = !empty($slots) ? end($slots) : null;

        $itemadministration = $itemadministrationfactory->item_administration_implementation(
            $quba,
            $adaptiveattempt,
            $adaptivequiz
        );
        $itemadministrationevaluation = request_timing::measure(
            'select_item',
            fn() => $itemadministration->evaluate_ability_to_administer_next_item($previousslot)
        );

        if ($itemadministrationevaluation->item_administration_is_to_stop()) {
            $noquestionsfetchedforattempt = $uniqueid == 0;
            if ($noquestionsfetchedforattempt) {
                $reason = (string) $itemadministrationevaluation->stoppage_reason();
                if ($adaptivequiz->catmodel && $reason !== '') {
                    // A CAT model that cannot start the attempt says why - e.g. settings no attempt can meet (CAT
                    // model, upstream issue #60). The activity shows that reason instead of the generic error; the
                    // empty attempt is left as it is and starts once the settings are corrected.
                    throw new \mod_adaptivequiz\local\catmodel\catmodel_session_exception($reason);
                }
                // The script will try to complete an 'empty' attempt as it couldn't fetch the first question for some reason.
                // This is an invalid behaviour, which could be caused by a misconfigured questions pool. Stop it here.
                throw new moodle_exception('attemptnofirstquestion', 'adaptivequiz');
            }

            self::complete_attempt_once(
                $uniqueid,
                $adaptivequiz,
                $context,
                $USER->id,
                $itemadministrationevaluation->stoppage_reason(),
                $itemadministrationevaluation->stop_code()
            );

            // In case it was an alternative CAT implementation set the attempt status.
            if (empty($adaptiveattempt->get_status())) {
                $adaptiveattempt->set_status(
                    $itemadministrationevaluation->stoppage_reason(),
                    $itemadministrationevaluation->stop_code()
                );
            }

            return;
        }

        $slot = $itemadministrationevaluation->next_item()->quba_slot();
        if (is_null($slot)) {
            $questionid = $itemadministrationevaluation->next_item()->question_id();

            // Issue #6: defensive guard against duplicate slots. If an active
            // slot for this question already exists (for example on a reload of
            // an unanswered item, or from a concurrent request that ran just
            // before the lock was acquired), reuse it instead of adding a second
            // slot for the same item. Reusing is the intended, normal outcome
            // here, so this deliberately does not raise a debugging notice.
            //
            // On a resume/reload the alternative CAT model may re-select a
            // DIFFERENT next item than the one already sitting in the active,
            // unanswered slot (the catquiz progress drops the unanswered last
            // question, so its strategy picks afresh). A CAT attempt only ever
            // has a single active unanswered slot at a time, so if the by-question
            // lookup misses, fall back to reusing whatever active slot exists
            // rather than appending a new one - otherwise the visible question
            // (slot) number keeps growing across every resume/reload.
            $existingslot = self::find_active_slot_for_question($quba, $questionid)
                ?? self::find_any_active_slot($quba);
            if ($existingslot !== null) {
                $adaptiveattempt->set_question_slot_number($existingslot);

                return;
            }

            // A question already answered in this usage is asked for again, a few times (issue #126
            // of the CAT model's plugin): a CAT model that keeps its own exclusion set names another
            // one. The test always goes on - if a CAT model insists, its item is administered once
            // more and stays visible as a technical duplicate; ending the test is never the answer.
            $asked = 1;
            while (self::find_completed_slot_for_question($quba, (int) $questionid) !== null && $asked < self::MAX_ASKS) {
                debugging(
                    "Question {$questionid} was named again by the CAT model although it was already "
                        . 'administered in this attempt; asking once more.',
                    DEBUG_DEVELOPER
                );
                // Each further ask is a selection of its own in the timeline, numbered (mod_adaptivequiz #17).
                $again = request_timing::measure(
                    'select_item',
                    fn() => $itemadministration->evaluate_ability_to_administer_next_item($previousslot),
                    ['retry' => $asked]
                );
                $asked++;
                if ($again->item_administration_is_to_stop()) {
                    // A regular stop by the CAT model's own criteria, not because of the repetition.
                    self::complete_attempt_once(
                        $uniqueid,
                        $adaptivequiz,
                        $context,
                        $USER->id,
                        $again->stoppage_reason(),
                        $again->stop_code()
                    );
                    if (empty($adaptiveattempt->get_status())) {
                        $adaptiveattempt->set_status($again->stoppage_reason(), $again->stop_code());
                    }
                    return;
                }
                if ($again->next_item()->quba_slot() !== null) {
                    break;
                }
                $questionid = $again->next_item()->question_id();
            }
            if (self::find_completed_slot_for_question($quba, (int) $questionid) !== null) {
                debugging(
                    "Question {$questionid} was named again {$asked} times; it is administered once more "
                        . 'as a technical duplicate and the test continues.',
                    DEBUG_DEVELOPER
                );
            }
            // The activity's maximum limits every question asked - productive, pilot and technical
            // duplicates alike (issue #19). A CAT model counts in its own way (each question once,
            // say); the usage is the host's, and it gets no slot beyond the maximum.
            if (self::maximum_reached($adaptivequiz, $quba)) {
                $reason = get_string('maxquestattempted', 'adaptivequiz');
                self::complete_attempt_once(
                    $uniqueid,
                    $adaptivequiz,
                    $context,
                    $USER->id,
                    $reason,
                    \mod_adaptivequiz\local\attempt\stop_reason::MAXIMUM
                );
                if (empty($adaptiveattempt->get_status())) {
                    $adaptiveattempt->set_status($reason, \mod_adaptivequiz\local\attempt\stop_reason::MAXIMUM);
                }
                return;
            }

            // The question bank takes no lock while loading - the only lock on this path is the item lock
            // (lock_wait). dbreads 0: the question came from the cache (mod_adaptivequiz #17).
            $question = request_timing::measure_db('load_question', fn() => question_bank::load_question($questionid));
            request_timing::start('question_usage');
            $slot = request_timing::measure('quba_add', fn() => $quba->add_question($question));

            if (!$quba->get_question_state($slot)->is_active()) {
                request_timing::measure('quba_start', fn() => $quba->start_question($slot));
                request_timing::measure('quba_save', fn() => question_engine::save_questions_usage_by_activity($quba));

                // If this is the first question, set quba id for the attempt.
                if (count($quba->get_slots()) == 1) {
                    $adaptiveattempt->set_quba_id($quba->get_id());
                }
            }
            request_timing::stop('question_usage', ['slots' => count($quba->get_slots())]);

            $adaptiveattempt->set_question_slot_number($slot);

            return;
        }

        /* The CAT model returned an EXISTING slot instead of a question id - the
           normal outcome when an unanswered item is re-served after a reload or a
           resume. The slot number still has to be written to the attempt, because
           attempt.php reads it back via get_question_slot_number() to render the
           question. Without this the attempt kept whatever slot number it carried
           before, and the next access to that slot failed with
           "There is no question_attempt number in this attempt".
           The built-in default_item_administration never hit this because it sets
           the slot number itself before returning from_quba_slot(); a sub-plugin
           that only returns the slot cannot do that. */
        $adaptiveattempt->set_question_slot_number($slot);
    }

    /**
     * The attempt of a usage, if it is no longer in progress.
     *
     * @param int $uniqueid Id of the question usage of the attempt.
     * @param stdClass $adaptivequiz
     * @param int $userid
     * @return stdClass|null The attempt record, or null while it runs (or does not exist).
     */
    private static function finished_attempt(int $uniqueid, stdClass $adaptivequiz, int $userid): ?stdClass {
        global $DB;
        $record = $DB->get_record(
            'adaptivequiz_attempt',
            ['uniqueid' => $uniqueid, 'instance' => $adaptivequiz->id, 'userid' => $userid],
            'id, attemptstate, attemptstopcriteria, stopcode'
        );
        if (!$record || $record->attemptstate === \mod_adaptivequiz\local\attempt\attempt_state::IN_PROGRESS) {
            return null;
        }
        return $record;
    }

    /**
     * Completes the attempt of a usage once: an attempt completed already stays as it is (audit 18.10.).
     *
     * Called with the item lock held. Two requests that both reached the end of the same attempt each ran the
     * whole completion - CAT model, result, grades, event.
     *
     * @param int $uniqueid
     * @param stdClass $adaptivequiz
     * @param context_module $context
     * @param int $userid
     * @param string $statusmessage
     * @param string|null $stopcode
     * @return bool Whether the attempt was completed now.
     */
    private static function complete_attempt_once(
        int $uniqueid,
        stdClass $adaptivequiz,
        context_module $context,
        int $userid,
        string $statusmessage,
        ?string $stopcode = null
    ): bool {
        if ($uniqueid != 0 && self::finished_attempt($uniqueid, $adaptivequiz, $userid) !== null) {
            return false;
        }
        request_timing::measure('complete_attempt', fn() => adaptivequiz_complete_attempt(
            $uniqueid,
            $adaptivequiz,
            $context,
            $userid,
            $statusmessage,
            $stopcode
        ));
        return true;
    }

    /**
     * Whether the usage already holds as many questions as the activity allows.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @param question_usage_by_activity $quba The question usage of the attempt.
     * @return bool
     */
    public static function maximum_reached(stdClass $adaptivequiz, question_usage_by_activity $quba): bool {
        $maximum = (int) ($adaptivequiz->maximumquestions ?? 0);
        return $maximum > 0 && count($quba->get_slots()) >= $maximum;
    }

    /**
     * Returns a slot of the usage in which the question has already been administered and closed.
     *
     * @param question_usage_by_activity $quba
     * @param int $questionid
     * @return int|null
     */
    private static function find_completed_slot_for_question(question_usage_by_activity $quba, int $questionid): ?int {
        foreach ($quba->get_slots() as $slot) {
            if ((int) $quba->get_question($slot)->id === $questionid && !$quba->get_question_state($slot)->is_active()) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Finds an active (unanswered) QUBA slot that already holds the given question.
     *
     * Used as a defensive guard against duplicate slots for the same item
     * (Issue #6).
     *
     * @param question_usage_by_activity $quba
     * @param int $questionid
     * @return int|null The slot number, or null if no active slot holds the question.
     */
    private static function find_active_slot_for_question(
        question_usage_by_activity $quba,
        int $questionid
    ): ?int {
        foreach ($quba->get_slots() as $slot) {
            if (
                (int) $quba->get_question($slot)->id === (int) $questionid
                && $quba->get_question_state($slot)->is_active()
            ) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Finds any active (unanswered) QUBA slot, regardless of which question it
     * holds.
     *
     * A CAT attempt only ever has a single active unanswered slot at a time (the
     * item currently presented to the user). When a fresh item is requested while
     * such a slot already exists, we are resuming or reloading and must re-present
     * that slot rather than appending a new one - otherwise the slot (question)
     * number grows on every resume/reload (Issue #6).
     *
     * @param question_usage_by_activity $quba
     * @return int|null The slot number, or null if there is no active slot.
     */
    private static function find_any_active_slot(
        question_usage_by_activity $quba
    ): ?int {
        foreach ($quba->get_slots() as $slot) {
            if ($quba->get_question_state($slot)->is_active()) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Processes the submitted question answer and operates on the given attempt instance changing its state.
     *
     * Two POSTs of the same answer at once - a double click on "Submit", a second tab - ran side by side:
     * both loaded the usage, both wrote an answer step with the same sequence number, and one of them ended
     * on an error page (audit 18.10.). The answer is processed under the item lock now, and a POST whose
     * answer another request has already processed - the question engine sees it out of sequence - changes
     * nothing; the caller goes on to the question the attempt is at.
     *
     * @param int $uniqueid
     * @param stdClass $adaptivequiz
     * @param attempt $adaptiveattempt
     * @param callable $qubahelper Used to change behavior when processing a submitted question.
     * @param context_module|null $context With it, an answer that ends the attempt completes it right here,
     *      under the same lock - a duplicate POST then finds it completed, and never administers a question
     *      after the end (audit 18.10.).
     * @return bool Whether this request processed the answer - false for a duplicate, or when another
     *      request held the attempt all along.
     */
    public static function process_administered_item_result(
        int $uniqueid,
        stdClass $adaptivequiz,
        attempt $adaptiveattempt,
        callable $qubahelper,
        ?context_module $context = null
    ): bool {
        global $USER;

        if (!adaptivequiz_uniqueid_part_of_attempt($uniqueid, $adaptivequiz->id, $USER->id)) {
            throw new moodle_exception('uniquenotpartofattempt', 'adaptivequiz');
        }

        request_timing::start('answer_lock_wait');
        $lock = \core\lock\lock_config::get_lock_factory('mod_adaptivequiz')
            ->get_lock('adaptivequiz_itemadministration_' . $adaptivequiz->id . '_' . $USER->id, self::$locktimeout);
        request_timing::stop('answer_lock_wait', ['acquired' => (bool) $lock]);
        if (!$lock) {
            // Another request has been working on this attempt all along. The answer is left alone - the
            // question it belongs to is shown again, still open, and can be submitted once more.
            return false;
        }

        try {
            return self::process_administered_item_result_locked(
                $uniqueid,
                $adaptivequiz,
                $adaptiveattempt,
                $qubahelper,
                $context
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Does the work of process_administered_item_result(), with the lock held.
     *
     * @param int $uniqueid
     * @param stdClass $adaptivequiz
     * @param attempt $adaptiveattempt
     * @param callable $qubahelper
     * @param context_module|null $context Completes an attempt the answer ends, when given.
     * @return bool Whether the answer was processed - false for a duplicate.
     */
    private static function process_administered_item_result_locked(
        int $uniqueid,
        stdClass $adaptivequiz,
        attempt $adaptiveattempt,
        callable $qubahelper,
        ?context_module $context
    ): bool {
        global $USER;

        // The attempt of this usage, completed meanwhile by the request whose duplicate this is: nothing to do -
        // and get_attempt() must not run, it would start a new attempt now that none is in progress (audit 18.10.).
        if (self::finished_attempt($uniqueid, $adaptivequiz, (int) $USER->id) !== null) {
            return false;
        }
        // Read under the lock: a request that waited must see what the one before it counted.
        $attemptrec = $adaptiveattempt->get_attempt();

        // Each part of handling the answer on its own in the timeline (mod_adaptivequiz #17).
        $quba = request_timing::measure('quba_load', fn() => question_engine::load_questions_usage_by_activity($uniqueid));
        try {
            request_timing::measure('quba_process', fn() => $qubahelper($quba));
        } catch (\question_out_of_sequence_exception $e) {
            // The form was submitted before, and that answer is processed: this POST is its duplicate.
            return false;
        }
        request_timing::measure('quba_save', fn() => question_engine::save_questions_usage_by_activity($quba));

        // If this is a custom CAT model, update the attempt, call the callback and exit.
        if ($adaptivequiz->catmodel) {
            request_timing::measure(
                'catmodel_item_result',
                fn() => self::catmodel_post_process_item_result($quba, $adaptivequiz, $adaptiveattempt)
            );
            adaptivequiz_update_attempt_data($uniqueid, $adaptivequiz->id, $USER->id, 0, 0, 0);

            return true;
        }

        $slots = $quba->get_slots();
        $slot = $slots[count($slots) - 1];

        $question = $quba->get_question($slot);

        $questiontags = core_tag_tag::get_item_tags('core_question', 'question', $question->id);
        $questiontags = array_filter($questiontags, function (core_tag_tag $tag): bool {
            return substr($tag->name, 0, strlen(ADAPTIVEQUIZ_QUESTION_TAG)) === ADAPTIVEQUIZ_QUESTION_TAG;
        });
        $questiontag = array_shift($questiontags);

        $difflevel = substr($questiontag->name, strlen(ADAPTIVEQUIZ_QUESTION_TAG));

        // Issue #14: the algorithm only estimates from the answer that reaches the minimum on, and
        // the attempt record does not count the answer being processed yet. The last answer the
        // maximum allows ends the attempt in any case, so it has to leave an estimate behind - a
        // fixed-length test (minimum = maximum) would otherwise end without one, at measure 0.
        $lastallowedanswer = (int) $attemptrec->questionsattempted + 1 >= (int) $adaptivequiz->maximumquestions;
        $minattemptreached = $lastallowedanswer
            || adaptivequiz_min_attempts_reached($uniqueid, $adaptivequiz->id, $USER->id);

        $algo = new catalgo($quba, (int) $attemptrec->id, $minattemptreached, (int) $difflevel);
        $nextdiff = $algo->perform_calculation_steps();

        $adaptiveattempt->set_level($nextdiff);

        $difflogit = $algo->get_levellogit();
        $standarderror = $algo->get_standarderror();
        $measure = $algo->get_measure();
        $everythingokay = adaptivequiz_update_attempt_data($uniqueid, $adaptivequiz->id, $USER->id, $difflogit, $standarderror,
            $measure);

        // Something went wrong with updating the attempt.
        if (!$everythingokay) {
            throw new moodle_exception('unableupdatediffsum', 'adaptivequiz');
        }

        // Check whether the status property is empty.
        $message = $algo->get_status();
        if (!empty($message)) {
            $adaptiveattempt->set_status($message, $algo->get_stop_code());
            if ($context !== null) {
                self::complete_attempt_once($uniqueid, $adaptivequiz, $context, (int) $USER->id, $message, $algo->get_stop_code());
            }

            return true;
        }

        // Lastly decrement the sum of questions for the attempted difficulty level.
        $fetchquestion = new fetchquestion($adaptivequiz, (int) $difflevel, $adaptivequiz->lowestlevel,
            $adaptivequiz->highestlevel);
        $tagquestcount = $fetchquestion->get_tagquestsum();
        $tagquestcount = $fetchquestion->decrement_question_sum_from_difficulty($tagquestcount, $difflevel);
        $fetchquestion->set_tagquestsum($tagquestcount);

        $fetchquestion->store_tagquestsum_in_session();

        return true;
    }

    /**
     * Tries to instantiate implementation of the factory for the given CAT model.
     *
     * @param string $catmodel
     * @return item_administration_factory
     * @throws coding_exception
     */
    private static function catmodel_item_administration_factory(string $catmodel): item_administration_factory {
        $implementations = core_component::get_component_classes_in_namespace(
            "adaptivequizcatmodel_$catmodel",
            'local\catmodel\itemadministration'
        );
        if (empty($implementations)) {
            throw new coding_exception(
                'implementations of the item_administration_factory interface could not be found for the selected CAT model'
            );
        }

        $classnames = array_filter(array_keys($implementations), function (string $classname): bool {
            return is_subclass_of($classname, '\mod_adaptivequiz\local\itemadministration\item_administration_factory');
        });

        if (empty($classnames)) {
            throw new coding_exception(
                'implementations of the item_administration_factory interface could not be found for the selected CAT model'
            );
        }

        if (count($classnames) > 1) {
            throw new coding_exception('only one implementation of the item_administration_factory interface is expected');
        }

        $classname = array_shift($classnames);

        return new $classname();
    }

    /**
     * Calls custom CAT model's callback if it could be found.
     *
     * When the callback cannot be executed the method silently exits.
     *
     * @param question_usage_by_activity $quba
     * @param stdClass $adaptivequiz
     * @param attempt $attempt
     */
    private static function catmodel_post_process_item_result(
        question_usage_by_activity $quba,
        stdClass $adaptivequiz,
        attempt $attempt
    ): void {
        $catmodelcomponentname = 'adaptivequizcatmodel_' . $adaptivequiz->catmodel;
        $pluginswithfunction = get_plugin_list_with_function('adaptivequizcatmodel', 'post_process_item_result_callback');
        if (!array_key_exists($catmodelcomponentname, $pluginswithfunction)) {
            return;
        }

        $functionname = $pluginswithfunction[$catmodelcomponentname];
        $functionname($quba, $adaptivequiz, $attempt);
    }
}
