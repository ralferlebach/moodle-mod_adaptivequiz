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
use mod_adaptivequiz\local\attempt\attempt_state;
use question_usage_by_activity;
use stdClass;

/**
 * Runs whole attempts against recorded reference values of the built-in algorithm.
 *
 * Every row of a fixture file is one answered question with the difficulty sum, standard error and
 * ability measure the algorithm produced at that step. The test replays the answers and compares
 * step by step - the only test that pins the algorithm as a whole rather than one of its parts.
 *
 * Ported from ralferlebach/v-3.0. The recorded values are unchanged; what changed is the setup
 * (question banks instead of course-level question categories) and the call into the host, which
 * now goes through cat_session.
 *
 * @package    mod_adaptivequiz
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_adaptivequiz\local\catalgo::class)]
final class calculation_steps_test extends advanced_testcase {
    /**
     * Reads a fixture file into a list of associative rows.
     *
     * @param string $filename Name of the file below tests/fixtures/calcsteps.
     * @return array[]
     */
    private function fixture_rows(string $filename): array {
        $handle = fopen(__DIR__ . '/fixtures/calcsteps/' . $filename, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '\\');

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = array_combine($header, $line);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Builds the activity and its question pool from the fixtures.
     *
     * @param int $instancenumber Which row of instances.csv to use.
     * @return array{0: stdClass, 1: stdClass} The activity instance and the user taking it.
     */
    private function set_up_from_fixtures(int $instancenumber): array {
        global $SESSION;

        global $CFG;
        require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

        $coregenerator = $this->getDataGenerator();
        /** @var \mod_adaptivequiz_generator $modgenerator */
        $modgenerator = $coregenerator->get_plugin_generator('mod_adaptivequiz');
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $coregenerator->get_plugin_generator('core_question');
        /** @var \mod_qbank_generator $qbankgenerator */
        $qbankgenerator = $coregenerator->get_plugin_generator('mod_qbank');

        $course = $coregenerator->create_course();
        $user = $coregenerator->create_user();

        $qbank = $qbankgenerator->create_instance(['course' => $course->id]);
        $qbankcm = get_coursemodule_from_instance('qbank', $qbank->id, 0, false, MUST_EXIST);
        $qcategory = question_get_default_category(context_module::instance($qbankcm->id)->id);

        $pool = $this->fixture_rows('questionpool.csv');
        foreach ($pool as $row) {
            for ($created = 0; $created < (int) $row['questionsnum']; $created++) {
                $question = $questiongenerator->create_question('truefalse', null, ['category' => $qcategory->id]);
                $questiongenerator->create_question_tag([
                    'questionid' => $question->id,
                    'tag' => \ADAPTIVEQUIZ_QUESTION_TAG . $row['difficultylevel'],
                ]);
            }
        }

        $settings = null;
        foreach ($this->fixture_rows('instances.csv') as $row) {
            if ((int) $row['instancenumber'] === $instancenumber) {
                $settings = $row;
                break;
            }
        }
        $this->assertNotNull($settings, "No instance {$instancenumber} in instances.csv.");

        $instance = $modgenerator->create_instance([
            'course' => $course->id,
            'lowestlevel' => $settings['lowestlevel'],
            'highestlevel' => $settings['highestlevel'],
            'startinglevel' => $settings['startinglevel'],
            'minimumquestions' => $settings['minimumquestions'],
            'maximumquestions' => $settings['maximumquestions'],
            'standarderror' => $settings['standarderror'],
        ]);
        $modgenerator->create_link_with_question_bank([
            'adaptivequizid' => $instance->id,
            'qbankid' => $qbank->id,
        ]);

        $adaptivequiz = clone($instance);
        $adaptivequiz->context = context_module::instance($instance->cmid);

        // The fetching class keeps the number of questions per difficulty in the session. Outside a
        // request nothing fills it, so the fixtures do.
        $SESSION->adpqtagquestsum = [];
        foreach ($pool as $row) {
            $SESSION->adpqtagquestsum[$instance->id][$row['difficultylevel']] = (int) $row['questionsnum'];
        }

        $this->setUser($user);

        return [$adaptivequiz, $user];
    }

    /**
     * Replays a recorded attempt and compares every step with the recorded values.
     *
     * @param string $stepsfixturesfile Name of the file holding the recorded steps.
     * @param int $instancenumber Which activity settings to use.
     * @param string $stoppagereason The reason the attempt is expected to stop with.
     * @param int[] $continueslots Slots at which the participant leaves and returns without answering.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('calculation_steps_provider')]
    public function test_calculation_steps(
        string $stepsfixturesfile,
        int $instancenumber,
        string $stoppagereason,
        array $continueslots
    ): void {
        global $DB;

        $this->resetAfterTest();

        [$adaptivequiz, $user] = $this->set_up_from_fixtures($instancenumber);
        $steps = $this->fixture_rows($stepsfixturesfile);

        $message = '';
        $uniqueid = 0;
        $nextdifficulty = null;
        $answereddifficulty = null;
        $standarderror = 0.0;

        $index = 0;
        while (true) {
            // What attempt.php does before asking for the next item.
            $attempt = new attempt($adaptivequiz, $user->id);
            $attempt->set_level($nextdifficulty ?? (int) $adaptivequiz->startinglevel);
            if ($answereddifficulty !== null) {
                $attempt->set_last_difficulty_level($answereddifficulty);
            }
            $attempt->get_attempt();
            $attempt->initialize_quba();

            $evaluation = cat_session::administer_next_item($adaptivequiz, $attempt);
            $this->assertNotNull($evaluation);

            if ($evaluation->item_administration_is_to_stop()) {
                $message = $evaluation->stoppage_reason();
                adaptivequiz_complete_attempt(
                    $uniqueid,
                    $adaptivequiz,
                    $adaptivequiz->context,
                    (int) $user->id,
                    (string) $standarderror,
                    $message
                );
                break;
            }

            $this->assertArrayHasKey($index, $steps, 'The attempt administered more items than were recorded.');
            $step = $steps[$index];

            $slot = $attempt->get_question_slot_number();
            $uniqueid = (int) $attempt->get_quba()->get_id();

            if (in_array($slot, $continueslots, true)) {
                // The participant leaves without answering and comes back: the same item has to be
                // administered again, not a new one.
                $returning = new attempt($adaptivequiz, $user->id);
                $returning->set_level($nextdifficulty ?? (int) $adaptivequiz->startinglevel);
                if ($answereddifficulty !== null) {
                    $returning->set_last_difficulty_level($answereddifficulty);
                }
                $returning->get_attempt();
                $returning->initialize_quba();
                cat_session::administer_next_item($adaptivequiz, $returning);

                $this->assertSame($slot, $returning->get_question_slot_number());
                $attempt = $returning;
            }

            $correct = $step['correctwrong'] === 'C';
            $result = cat_session::process_administered_item_result(
                $uniqueid,
                $adaptivequiz,
                $attempt,
                function (question_usage_by_activity $quba) use ($slot, $correct): void {
                    $time = time();
                    $quba->process_all_actions($time, $quba->prepare_simulated_post_data([
                        $slot => ['answer' => $correct],
                    ]));
                    $quba->finish_all_questions($time);
                }
            );

            $nextdifficulty = $result->nextdifficulty;
            $answereddifficulty = $result->answereddifficulty;
            $standarderror = $result->standarderror;

            $record = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid], '*', MUST_EXIST);
            $this->assertEquals(
                [
                    'difficultysum' => (float) $step['difficultysum'],
                    'standarderror' => (float) $step['standarderrorraw'],
                    'measure' => (float) $step['measureraw'],
                ],
                [
                    'difficultysum' => (float) $record->difficultysum,
                    'standarderror' => (float) $record->standarderror,
                    'measure' => (float) $record->measure,
                ],
                'Step ' . ($index + 1) . ' does not match the recorded values.'
            );

            $index++;

            if ($result->attempt_is_to_stop()) {
                $message = $result->stoppagereason;
                adaptivequiz_complete_attempt(
                    $uniqueid,
                    $adaptivequiz,
                    $adaptivequiz->context,
                    (int) $user->id,
                    (string) $result->standarderror,
                    $message
                );
                break;
            }
        }

        $this->assertSame($stoppagereason, $message);

        $record = $DB->get_record('adaptivequiz_attempt', ['uniqueid' => $uniqueid], '*', MUST_EXIST);
        $this->assertEquals(count($steps), $record->questionsattempted, 'A different number of questions was answered.');

        $last = $steps[count($steps) - 1];
        $this->assertEquals(
            [
                'difficultysum' => (float) $last['difficultysum'],
                'standarderror' => (float) $last['standarderrorraw'],
                'measure' => (float) $last['measureraw'],
            ],
            [
                'difficultysum' => (float) $record->difficultysum,
                'standarderror' => (float) $record->standarderror,
                'measure' => (float) $record->measure,
            ],
            'The completed attempt does not keep the values of the last step.'
        );
    }

    /**
     * The recorded attempts, one per stoppage reason worth pinning.
     *
     * @return array[]
     */
    public static function calculation_steps_provider(): array {
        return [
            'unable to fetch a question for level 14' => ['1.csv', 1, 'Unable to fetch a question for level 14', []],
            'maximum number of questions attempted, instance 2' => ['3.csv', 2, 'Maximum number of questions attempted', []],
            'maximum number of questions attempted, instance 3' => ['5.csv', 3, 'Maximum number of questions attempted', []],
            'maximum number of questions attempted, instance 4' => ['6.csv', 4, 'Maximum number of questions attempted', []],
            'standard error 11 within the limits' => [
                '2.csv',
                1,
                'Calculated standard error of 11 is within the limits imposed by the activity 11',
                [],
            ],
            'unable to fetch a question for level 1' => ['4.csv', 1, 'Unable to fetch a question for level 1', []],
            'standard error 8 within the limits' => [
                '7.csv',
                5,
                'Calculated standard error of 8 is within the limits imposed by the activity 8',
                [],
            ],
            'maximum number of questions attempted, instance 6' => ['8.csv', 6, 'Maximum number of questions attempted', []],
            'continued attempt, unable to fetch a question for level 14' => [
                '1.csv',
                1,
                'Unable to fetch a question for level 14',
                [4, 8, 14, 19],
            ],
        ];
    }
}
