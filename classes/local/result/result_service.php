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

namespace mod_adaptivequiz\local\result;

use coding_exception;
use mod_adaptivequiz\local\attempt\attempt_state;
use mod_adaptivequiz\local\catmodel\catmodel_resolver;
use mod_adaptivequiz\local\result\link\link_function_registry;
use mod_adaptivequiz\local\result\link\link_parameters;
use stdClass;

/**
 * The one place where results become percentages, grades and pass marks (issue #14).
 *
 * Every completed attempt gets a snapshot of its result: valid or not, score, range, percentage,
 * link function. The gradebook and the completion rules read the snapshot, never the engine
 * directly, so they cannot disagree - and a later change of the range or the engine's data does
 * not rewrite what an attempt was worth when it was finished.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result_service {
    /** @var string The activity's CAT model offers no result provider. */
    public const REASON_NO_PROVIDER = 'no_result_provider';

    /** @var string The range of the result has upper <= lower or is not finite. */
    public const REASON_RANGE_INVALID = 'range_invalid';

    /** @var string The result names a link function the host does not know. */
    public const REASON_LINK_UNKNOWN = 'link_function_unknown';

    /** @var string The score is not a finite number. */
    public const REASON_SCORE_INVALID = 'score_invalid';

    /** @var string The activity is not graded: the result is valid, there is no grade (issue #25). */
    public const REASON_GRADING_OFF = 'grading_off';

    /** @var string The link function cannot compute a grade with the activity's parameters (issue #25). */
    public const REASON_LINK_NOT_COMPUTABLE = 'link_not_computable';

    /** @var int The activity is graded. */
    public const GRADING_ON = 1;

    /** @var int The activity is not graded (issue #25). */
    public const GRADING_OFF = 0;

    /**
     * Whether an activity is graded (issue #25).
     *
     * @param stdClass $adaptivequiz
     * @return bool
     */
    public static function is_graded(stdClass $adaptivequiz): bool {
        return !isset($adaptivequiz->gradingmode) || (int) $adaptivequiz->gradingmode !== self::GRADING_OFF;
    }

    /**
     * The link function of an activity: its own setting, else the default of its result source (issue #25).
     *
     * @param stdClass $adaptivequiz
     * @param result_definition|null $definition The result definition, if already known.
     * @return string
     */
    public static function link_function_id(stdClass $adaptivequiz, ?result_definition $definition = null): string {
        if (!empty($adaptivequiz->linkfunction)) {
            return (string) $adaptivequiz->linkfunction;
        }
        return ($definition ?? self::definition($adaptivequiz))->linkfunction;
    }

    /**
     * The parameters of the activity's link function on a range (issue #25).
     *
     * The test information is only asked for when the function needs it: it reads the item battery.
     *
     * @param stdClass $adaptivequiz
     * @param float $lower
     * @param float $upper
     * @param string $linkfunction
     * @return link_parameters
     */
    public static function link_parameters(
        stdClass $adaptivequiz,
        float $lower,
        float $upper,
        string $linkfunction
    ): link_parameters {
        $pass = isset($adaptivequiz->passscore) && is_numeric($adaptivequiz->passscore) ? (float) $adaptivequiz->passscore : null;
        $steepness = isset($adaptivequiz->linksteepness) && is_numeric($adaptivequiz->linksteepness)
            && (float) $adaptivequiz->linksteepness > 0 ? (float) $adaptivequiz->linksteepness : link_parameters::DEFAULT_STEEPNESS;
        $information = null;
        try {
            $required = link_function_registry::get($linkfunction)->required_parameters();
            $needsinfo = in_array(link_parameters::INFORMATION, $required, true);
        } catch (result_configuration_exception $e) {
            $needsinfo = false;
        }
        if ($needsinfo) {
            $provider = self::provider($adaptivequiz);
            $information = $provider instanceof test_information_source ? $provider->test_information($adaptivequiz) : null;
        }
        return new link_parameters($lower, $upper, $pass, $steepness, $information);
    }

    /**
     * The result provider of an activity: its CAT model's, or the built-in one.
     *
     * A CAT model that offers none has no gradable results - the host does not fall back to its
     * own measure, which the CAT model does not maintain.
     *
     * @param stdClass $adaptivequiz
     * @return result_provider|null
     */
    public static function provider(stdClass $adaptivequiz): ?result_provider {
        if (!catmodel_resolver::is_configured($adaptivequiz->catmodel ?? null)) {
            return new builtin_result_provider();
        }
        try {
            $provider = catmodel_resolver::handler($adaptivequiz->catmodel, result_provider::class);
        } catch (coding_exception $e) {
            // The CAT model is configured but no longer installed: nothing can report its results.
            return null;
        }
        return $provider instanceof result_provider ? $provider : null;
    }

    /**
     * What the results of an activity look like.
     *
     * @param stdClass $adaptivequiz
     * @return result_definition
     */
    public static function definition(stdClass $adaptivequiz): result_definition {
        $provider = self::provider($adaptivequiz);
        if ($provider === null) {
            return result_definition::not_gradable(get_string('resultsourcenone', 'adaptivequiz'));
        }
        return $provider->get_result_definition($adaptivequiz);
    }

    /**
     * Maps a score onto 0-100 % of its range.
     *
     * @param float|null $score
     * @param float|null $lower
     * @param float|null $upper
     * @param string $linkfunction
     * @param link_parameters|null $parameters Pass score, steepness and test information; the range is taken from
     *      $lower and $upper. Null for none.
     * @return float|null Null when the score or the range cannot carry a percentage, or the function cannot compute
     *      one with these parameters.
     * @throws result_configuration_exception For an unknown link function.
     */
    public static function percentage(
        ?float $score,
        ?float $lower,
        ?float $upper,
        string $linkfunction,
        ?link_parameters $parameters = null
    ): ?float {
        $function = link_function_registry::get($linkfunction);
        if ($score === null || !is_finite($score) || !result_definition::is_valid_range($lower, $upper)) {
            return null;
        }
        $parameters = $parameters ? $parameters->with_range($lower, $upper) : new link_parameters($lower, $upper);
        $percent = $function->percentage($score, $parameters);
        if ($percent === null || !is_finite($percent)) {
            return null;
        }
        return max(0.0, min(100.0, $percent));
    }

    /**
     * Records the result of a completed attempt, once.
     *
     * Runs when the attempt is completed, after the CAT model has been told, and from the task
     * that brings older attempts into the contract. An attempt that already has a snapshot keeps
     * it unless $force is set.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attempt The adaptivequiz_attempt record; it is read again from the database.
     * @param bool $force Replace an existing snapshot.
     * @return stdClass The attempt record as it stands afterwards.
     */
    public static function snapshot(stdClass $adaptivequiz, stdClass $attempt, bool $force = false): stdClass {
        global $DB;

        // The CAT model may have written to the attempt in its completion callback.
        $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
        if ($attempt->attemptstate !== attempt_state::COMPLETED || (!empty($attempt->resulttime) && !$force)) {
            return $attempt;
        }

        $update = (object) [
            'id' => $attempt->id,
            'resultscore' => null,
            'resultlower' => null,
            'resultupper' => null,
            'resultpercent' => null,
            'resultlink' => null,
            'resultlinkversion' => null,
            'resultreason' => null,
            'resulttime' => time(),
        ];

        $provider = self::provider($adaptivequiz);
        if ($provider === null) {
            // Validity stays whatever the CAT model wrote; there is just nothing to grade.
            $update->resultreason = self::REASON_NO_PROVIDER;
        } else {
            $result = $provider->get_attempt_result($adaptivequiz, $attempt);
            foreach ((array) self::evaluate($result, $adaptivequiz) as $field => $value) {
                $update->$field = $value;
            }
        }

        $DB->update_record('adaptivequiz_attempt', $update);
        return $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
    }

    /**
     * The snapshot fields for an engine's result.
     *
     * The validity of the measurement and the grade are apart (issue #25): an activity that is not graded, or a link
     * function that cannot compute a grade, leaves a valid result valid - without a percentage, never with 0.
     *
     * @param attempt_result $result
     * @param stdClass $adaptivequiz The activity, for its grading settings.
     * @return stdClass resultvalid, resultstatus and the result* fields.
     */
    private static function evaluate(attempt_result $result, stdClass $adaptivequiz): stdClass {
        $fields = (object) [
            'resultvalid' => 0,
            'resultstatus' => attempt_result::STATUS_INVALID,
            'resultreason' => $result->reason !== '' ? $result->reason : null,
            'resultlink' => $result->linkfunction,
            'resultlinkversion' => null,
        ];
        if (!$result->valid) {
            return $fields;
        }

        $fields->resultscore = $result->score;
        $fields->resultlower = $result->lower;
        $fields->resultupper = $result->upper;

        if (!self::is_graded($adaptivequiz)) {
            // Issue #25: the measurement is judged as always; there is just no grade.
            if (!result_definition::is_valid_range($result->lower, $result->upper)) {
                $fields->resultreason = self::REASON_RANGE_INVALID;
                return $fields;
            }
            if ($result->score === null || !is_finite($result->score)) {
                $fields->resultreason = self::REASON_SCORE_INVALID;
                return $fields;
            }
            $fields->resultlink = null;
            $fields->resultreason = self::REASON_GRADING_OFF;
            $fields->resultvalid = 1;
            $fields->resultstatus = attempt_result::STATUS_VALID;
            return $fields;
        }

        // Issue #25: the activity's link function, else the one the result names.
        $linkfunction = !empty($adaptivequiz->linkfunction) ? (string) $adaptivequiz->linkfunction : $result->linkfunction;
        $fields->resultlink = $linkfunction;
        try {
            $parameters = result_definition::is_valid_range($result->lower, $result->upper)
                ? self::link_parameters($adaptivequiz, (float) $result->lower, (float) $result->upper, $linkfunction)
                : null;
            $percent = self::percentage($result->score, $result->lower, $result->upper, $linkfunction, $parameters);
            $fields->resultlinkversion = link_function_registry::get($linkfunction)->get_version();
        } catch (result_configuration_exception $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            $fields->resultreason = self::REASON_LINK_UNKNOWN;
            return $fields;
        }

        if (!result_definition::is_valid_range($result->lower, $result->upper)) {
            $fields->resultreason = self::REASON_RANGE_INVALID;
            return $fields;
        }
        if ($result->score === null || !is_finite($result->score)) {
            $fields->resultreason = self::REASON_SCORE_INVALID;
            return $fields;
        }
        if ($percent === null) {
            // A valid measurement the activity's link function cannot turn into a grade: no grade, no substitute.
            $fields->resultreason = self::REASON_LINK_NOT_COMPUTABLE;
            $fields->resultvalid = 1;
            $fields->resultstatus = attempt_result::STATUS_VALID;
            return $fields;
        }

        $fields->resultpercent = $percent;
        $fields->resultvalid = 1;
        $fields->resultstatus = attempt_result::STATUS_VALID;
        return $fields;
    }

    /**
     * The pass mark of an activity in percent, from its pass score in the engine's units.
     *
     * @param stdClass $adaptivequiz
     * @return float|null Null when no pass score is set or it cannot be mapped.
     */
    public static function pass_percentage(stdClass $adaptivequiz): ?float {
        if (!self::is_graded($adaptivequiz)) {
            return null;
        }
        if (!isset($adaptivequiz->passscore) || $adaptivequiz->passscore === '' || !is_numeric($adaptivequiz->passscore)) {
            return null;
        }
        $definition = self::definition($adaptivequiz);
        if (!$definition->is_gradable() || self::passscore_error($definition, (float) $adaptivequiz->passscore) !== null) {
            return null;
        }
        $linkfunction = self::link_function_id($adaptivequiz, $definition);
        try {
            return self::percentage(
                (float) $adaptivequiz->passscore,
                $definition->lower,
                $definition->upper,
                $linkfunction,
                self::link_parameters($adaptivequiz, $definition->lower, $definition->upper, $linkfunction)
            );
        } catch (result_configuration_exception $e) {
            return null;
        }
    }

    /**
     * Why the link settings of an activity cannot compute grades, or null when they can (issue #25).
     *
     * Checked when the settings are saved, so a misconfiguration is seen there - not found later as attempts without
     * a grade.
     *
     * @param stdClass $adaptivequiz The settings as they would be saved.
     * @return array|null [field, string identifier, string argument] or null.
     */
    public static function link_settings_error(stdClass $adaptivequiz): ?array {
        if (!self::is_graded($adaptivequiz)) {
            return null;
        }
        $definition = self::definition($adaptivequiz);
        $linkfunction = self::link_function_id($adaptivequiz, $definition);
        try {
            $function = link_function_registry::get($linkfunction);
        } catch (result_configuration_exception $e) {
            return ['linkfunction', 'resulterrorlinkfunction', $linkfunction];
        }
        if (isset($adaptivequiz->linksteepness) && $adaptivequiz->linksteepness !== '' && $adaptivequiz->linksteepness !== null) {
            $k = $adaptivequiz->linksteepness;
            if (!is_numeric($k) || !is_finite((float) $k) || (float) $k <= 0) {
                return ['linksteepness', 'linksteepnessinvalid', null];
            }
        }
        $required = $function->required_parameters();
        $pass = isset($adaptivequiz->passscore) && is_numeric($adaptivequiz->passscore) ? (float) $adaptivequiz->passscore : null;
        if (in_array(link_parameters::PASS, $required, true)) {
            if ($pass === null) {
                return ['passscore', 'linkneedspassscore', null];
            }
            if ($definition->has_valid_range() && !($definition->lower < $pass && $pass < $definition->upper)) {
                return ['passscore', 'linkpassinsiderange', null];
            }
        }
        if (in_array(link_parameters::INFORMATION, $required, true)) {
            if (!$definition->has_valid_range()) {
                return ['linkfunction', 'linknoinformation', null];
            }
            $parameters = self::link_parameters($adaptivequiz, $definition->lower, $definition->upper, $linkfunction);
            if ($parameters->information === null || $function->percentage($definition->upper, $parameters) === null) {
                return ['linkfunction', 'linknoinformation', null];
            }
        }
        return null;
    }

    /**
     * Why a pass score does not fit a result definition.
     *
     * @param result_definition $definition
     * @param float $passscore In the engine's units.
     * @return string|null Identifier of the error string, or null when the pass score fits.
     */
    public static function passscore_error(result_definition $definition, float $passscore): ?string {
        if (!$definition->is_gradable()) {
            return 'passscorenorange';
        }
        if (!is_finite($passscore) || $passscore < $definition->lower || $passscore > $definition->upper) {
            return 'passscoreoutofrange';
        }
        return null;
    }

    /**
     * The grade of a snapshot under the activity's settings of today (issue #25).
     *
     * Score and range stay as the snapshot has them - what the attempt measured is not asked again. Only the step from
     * score to percentage is redone: grading on or off, link function, pass score, steepness and the test information
     * of the item battery as it is now. A snapshot without a valid measurement is left as it is.
     *
     * @param stdClass $adaptivequiz
     * @param stdClass $attempt adaptivequiz_attempt with its snapshot.
     * @param bool $write Store the new grade; false only computes it (a preview).
     * @return stdClass|null before, after (percent or null), reason, link - null when the snapshot has no measurement.
     */
    public static function relink(stdClass $adaptivequiz, stdClass $attempt, bool $write = true): ?stdClass {
        global $DB;
        if (
            empty($attempt->resulttime) || empty($attempt->resultvalid) || !is_numeric($attempt->resultscore ?? null)
            || !result_definition::is_valid_range(
                is_numeric($attempt->resultlower ?? null) ? (float) $attempt->resultlower : null,
                is_numeric($attempt->resultupper ?? null) ? (float) $attempt->resultupper : null
            )
        ) {
            return null;
        }
        $score = (float) $attempt->resultscore;
        $lower = (float) $attempt->resultlower;
        $upper = (float) $attempt->resultupper;
        $update = (object) ['id' => $attempt->id, 'resultpercent' => null, 'resultlink' => null, 'resultlinkversion' => null];
        if (!self::is_graded($adaptivequiz)) {
            $update->resultreason = self::REASON_GRADING_OFF;
        } else {
            $linkfunction = self::link_function_id($adaptivequiz);
            $update->resultlink = $linkfunction;
            try {
                $parameters = self::link_parameters($adaptivequiz, $lower, $upper, $linkfunction);
                $update->resultpercent = self::percentage($score, $lower, $upper, $linkfunction, $parameters);
                $update->resultlinkversion = link_function_registry::get($linkfunction)->get_version();
                $update->resultreason = $update->resultpercent === null ? self::REASON_LINK_NOT_COMPUTABLE : null;
            } catch (result_configuration_exception $e) {
                $update->resultreason = self::REASON_LINK_UNKNOWN;
            }
        }
        if ($write) {
            $DB->update_record('adaptivequiz_attempt', $update);
        }
        return (object) [
            'attemptid' => (int) $attempt->id,
            'before' => $attempt->resultpercent === null ? null : (float) $attempt->resultpercent,
            'after' => $update->resultpercent,
            'reason' => $update->resultreason,
            'link' => $update->resultlink,
        ];
    }

    /**
     * Gives every completed attempt of an activity that has none a snapshot.
     *
     * @param stdClass $adaptivequiz
     * @return int Number of attempts that got one.
     */
    public static function snapshot_missing(stdClass $adaptivequiz): int {
        global $DB;

        $attempts = $DB->get_records_select(
            'adaptivequiz_attempt',
            'instance = :instance AND attemptstate = :state AND resulttime IS NULL',
            ['instance' => $adaptivequiz->id, 'state' => attempt_state::COMPLETED],
            'id'
        );
        foreach ($attempts as $attempt) {
            self::snapshot($adaptivequiz, $attempt);
        }
        return count($attempts);
    }

    /**
     * Rebuilds the grades of an activity from its result snapshots - explicitly, and safe to repeat (issue #19).
     *
     * Completed attempts without a snapshot get one. A snapshot is only retaken for the attempts
     * named in $resnapshot - after the CAT model has judged them again, say: a retaken snapshot reads
     * the result range of today, not of the moment of completion. Every grade then follows the
     * grading method from the valid snapshots alone; a participant without one gets no grade, never 0.
     *
     * With $relink, the grade of every snapshot is computed again from its score under today's grading settings
     * (issue #25) - the step a settings change triggers unless the activity keeps existing grades.
     *
     * @param stdClass $adaptivequiz The activity instance record.
     * @param int[] $resnapshot Attempts (adaptivequiz_attempt.id) whose snapshot is retaken.
     * @param bool $relink Compute the grade of every snapshot again from its score.
     * @return stdClass attempts, snapshots, relinked, valid, ungraded (reason by attempt id).
     */
    public static function regrade(stdClass $adaptivequiz, array $resnapshot = [], bool $relink = false): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');

        $report = (object) ['attempts' => 0, 'snapshots' => 0, 'relinked' => 0, 'valid' => 0, 'ungraded' => []];
        $resnapshot = array_flip(array_map('intval', $resnapshot));
        $attempts = $DB->get_records(
            'adaptivequiz_attempt',
            ['instance' => $adaptivequiz->id, 'attemptstate' => attempt_state::COMPLETED],
            'id'
        );
        foreach ($attempts as $attempt) {
            $force = isset($resnapshot[(int) $attempt->id]);
            if ($force || empty($attempt->resulttime)) {
                $attempt = self::snapshot($adaptivequiz, $attempt, $force);
                $report->snapshots++;
            } else if ($relink && self::relink($adaptivequiz, $attempt) !== null) {
                $attempt = $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
                $report->relinked++;
            }
            $report->attempts++;
            if (!empty($attempt->resultvalid) && $attempt->resultpercent !== null) {
                $report->valid++;
            } else {
                $report->ungraded[(int) $attempt->id] = (string) ($attempt->resultreason ?? '');
            }
        }

        adaptivequiz_update_grades($adaptivequiz);
        return $report;
    }
}
