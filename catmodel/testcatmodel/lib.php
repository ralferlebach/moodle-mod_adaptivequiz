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

/**
 * Callbacks of the neutral CAT model used to test the subplugin contract.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns the URL of the attempts report this CAT model offers.
 *
 * @param stdClass $adaptivequiz The activity instance record.
 * @param stdClass $cm The course module record of that activity.
 * @return moodle_url
 */
function adaptivequizcatmodel_testcatmodel_attempts_report_url(stdClass $adaptivequiz, stdClass $cm): moodle_url {
    return new moodle_url('/mod/adaptivequiz/view.php', ['id' => $cm->id, 'testcatmodelreport' => 1]);
}

/**
 * Records that the host told this CAT model about a completed attempt.
 *
 * @param stdClass $adaptivequiz The activity instance record.
 * @param context_module $context The context of that activity.
 * @param int $userid The user the attempt belongs to.
 * @param stdClass $attempt The completed attempt record.
 */
function adaptivequizcatmodel_testcatmodel_post_complete_attempt_callback(
    stdClass $adaptivequiz,
    context_module $context,
    int $userid,
    stdClass $attempt
): void {
    $GLOBALS['adaptivequizcatmodel_testcatmodel_completions'][] = (int) $attempt->uniqueid;
}

/**
 * Forgets the completions recorded so far.
 */
function adaptivequizcatmodel_testcatmodel_reset_completions(): void {
    $GLOBALS['adaptivequizcatmodel_testcatmodel_completions'] = [];
}

/**
 * Returns the question usage ids of the completions recorded so far.
 *
 * @return int[]
 */
function adaptivequizcatmodel_testcatmodel_completed_attempts(): array {
    return $GLOBALS['adaptivequizcatmodel_testcatmodel_completions'] ?? [];
}

/**
 * Records that the host told this CAT model about a deleted attempt.
 *
 * @param stdClass $adaptivequiz The activity instance record.
 * @param stdClass $attempt The attempt that was deleted.
 */
function adaptivequizcatmodel_testcatmodel_post_delete_attempt_callback(
    stdClass $adaptivequiz,
    stdClass $attempt
): void {
    $GLOBALS['adaptivequizcatmodel_testcatmodel_deletions'][] = (int) $attempt->uniqueid;
}

/**
 * Forgets the deletions recorded so far.
 */
function adaptivequizcatmodel_testcatmodel_reset_deletions(): void {
    $GLOBALS['adaptivequizcatmodel_testcatmodel_deletions'] = [];
}

/**
 * Returns the question usage ids of the deletions recorded so far.
 *
 * @return int[]
 */
function adaptivequizcatmodel_testcatmodel_deleted_attempts(): array {
    return $GLOBALS['adaptivequizcatmodel_testcatmodel_deletions'] ?? [];
}

/**
 * Records that the host handed an answered item to this CAT model.
 *
 * @param question_usage_by_activity $quba The question usage of the attempt.
 * @param stdClass $adaptivequiz The activity instance record.
 * @param \mod_adaptivequiz\local\attempt $attempt The running attempt.
 */
function adaptivequizcatmodel_testcatmodel_post_process_item_result_callback(
    question_usage_by_activity $quba,
    stdClass $adaptivequiz,
    \mod_adaptivequiz\local\attempt $attempt
): void {
    $GLOBALS['adaptivequizcatmodel_testcatmodel_processeditems'] =
        ($GLOBALS['adaptivequizcatmodel_testcatmodel_processeditems'] ?? 0) + 1;
}

/**
 * Forgets the answered items recorded so far.
 */
function adaptivequizcatmodel_testcatmodel_reset_processed_items(): void {
    $GLOBALS['adaptivequizcatmodel_testcatmodel_processeditems'] = 0;
}

/**
 * Returns how many answered items were handed over so far.
 *
 * @return int
 */
function adaptivequizcatmodel_testcatmodel_processed_items(): int {
    return $GLOBALS['adaptivequizcatmodel_testcatmodel_processeditems'] ?? 0;
}

/**
 * Returns the feedback this CAT model shows instead of the one configured on the activity.
 *
 * @param stdClass $adaptivequiz The activity instance record.
 * @param stdClass $cm The course module record of that activity.
 * @param stdClass $attemptrecord The finished attempt.
 * @return string
 */
function adaptivequizcatmodel_testcatmodel_attempt_finished_feedback(
    stdClass $adaptivequiz,
    stdClass $cm,
    stdClass $attemptrecord
): string {
    return 'Feedback from the test CAT model.';
}

/**
 * Records that the host told this CAT model about a newly created attempt.
 *
 * @param stdClass $adaptivequiz The activity instance record.
 * @param \mod_adaptivequiz\local\attempt $attempt The attempt that was created.
 */
function adaptivequizcatmodel_testcatmodel_post_create_attempt_callback(
    stdClass $adaptivequiz,
    \mod_adaptivequiz\local\attempt $attempt
): void {
    $GLOBALS['adaptivequizcatmodel_testcatmodel_created'] =
        ($GLOBALS['adaptivequizcatmodel_testcatmodel_created'] ?? 0) + 1;
}

/**
 * Forgets the created attempts recorded so far.
 */
function adaptivequizcatmodel_testcatmodel_reset_created(): void {
    $GLOBALS['adaptivequizcatmodel_testcatmodel_created'] = 0;
}

/**
 * Returns how many newly created attempts were announced so far.
 *
 * @return int
 */
function adaptivequizcatmodel_testcatmodel_created_attempts(): int {
    return $GLOBALS['adaptivequizcatmodel_testcatmodel_created'] ?? 0;
}
