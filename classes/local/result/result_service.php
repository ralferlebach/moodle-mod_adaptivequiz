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
     * @return float|null Null when the score or the range cannot carry a percentage.
     * @throws result_configuration_exception For an unknown link function.
     */
    public static function percentage(?float $score, ?float $lower, ?float $upper, string $linkfunction): ?float {
        $function = link_function_registry::get($linkfunction);
        if ($score === null || !is_finite($score) || !result_definition::is_valid_range($lower, $upper)) {
            return null;
        }
        return max(0.0, min(100.0, $function->percentage($score, $lower, $upper)));
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
            'resultreason' => null,
            'resulttime' => time(),
        ];

        $provider = self::provider($adaptivequiz);
        if ($provider === null) {
            // Validity stays whatever the CAT model wrote; there is just nothing to grade.
            $update->resultreason = self::REASON_NO_PROVIDER;
        } else {
            $result = $provider->get_attempt_result($adaptivequiz, $attempt);
            foreach ((array) self::evaluate($result) as $field => $value) {
                $update->$field = $value;
            }
        }

        $DB->update_record('adaptivequiz_attempt', $update);
        return $DB->get_record('adaptivequiz_attempt', ['id' => $attempt->id], '*', MUST_EXIST);
    }

    /**
     * The snapshot fields for an engine's result.
     *
     * @param attempt_result $result
     * @return stdClass resultvalid, resultstatus and the result* fields.
     */
    private static function evaluate(attempt_result $result): stdClass {
        $fields = (object) [
            'resultvalid' => 0,
            'resultstatus' => attempt_result::STATUS_INVALID,
            'resultreason' => $result->reason !== '' ? $result->reason : null,
            'resultlink' => $result->linkfunction,
        ];
        if (!$result->valid) {
            return $fields;
        }

        $fields->resultscore = $result->score;
        $fields->resultlower = $result->lower;
        $fields->resultupper = $result->upper;

        try {
            $percent = self::percentage($result->score, $result->lower, $result->upper, $result->linkfunction);
        } catch (result_configuration_exception $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            $fields->resultreason = self::REASON_LINK_UNKNOWN;
            return $fields;
        }

        if (!result_definition::is_valid_range($result->lower, $result->upper)) {
            $fields->resultreason = self::REASON_RANGE_INVALID;
            return $fields;
        }
        if ($percent === null) {
            $fields->resultreason = self::REASON_SCORE_INVALID;
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
        if (!isset($adaptivequiz->passscore) || $adaptivequiz->passscore === '' || !is_numeric($adaptivequiz->passscore)) {
            return null;
        }
        $definition = self::definition($adaptivequiz);
        if (!$definition->is_gradable() || self::passscore_error($definition, (float) $adaptivequiz->passscore) !== null) {
            return null;
        }
        try {
            return self::percentage(
                (float) $adaptivequiz->passscore,
                $definition->lower,
                $definition->upper,
                $definition->linkfunction
            );
        } catch (result_configuration_exception $e) {
            return null;
        }
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
}
