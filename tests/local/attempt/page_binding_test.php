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

namespace mod_adaptivequiz\local\attempt;

use advanced_testcase;
use moodle_exception;

/**
 * The attempt a question page is rendered for (#27 AK01): the person's attempt of this activity with this usage, else
 * an error before any output.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \mod_adaptivequiz\local\attempt\page_binding
 */
final class page_binding_test extends advanced_testcase {
    /**
     * The attempt of the page, its completion meanwhile, and every way it is not the attempt of the page.
     */
    public function test_the_page_is_bound_to_its_attempt(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
        $other = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $id = $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $quiz->id, 'userid' => $user->id, 'uniqueid' => 4711,
            'attemptstate' => attempt_state::IN_PROGRESS, 'attemptstopcriteria' => '', 'questionsattempted' => 1,
            'difficultysum' => 0, 'standarderror' => 1, 'measure' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->assertSame((int) $id, (int) page_binding::verify($quiz, (int) $user->id, 4711, (int) $quiz->cmid)->id);
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', attempt_state::COMPLETED, ['id' => $id]);
        $this->assertSame(
            attempt_state::COMPLETED,
            page_binding::verify($quiz, (int) $user->id, 4711, (int) $quiz->cmid)->attemptstate,
            'Completed meanwhile: the page shows the result.'
        );

        $lost = [
            'another person' => [$quiz, (int) $stranger->id, 4711],
            'another activity' => [$other, (int) $user->id, 4711],
            'another usage' => [$quiz, (int) $user->id, 4712],
            'no usage' => [$quiz, (int) $user->id, 0],
        ];
        $DB->delete_records('adaptivequiz_attempt', ['id' => $id]);
        $lost['removed meanwhile'] = [$quiz, (int) $user->id, 4711];
        foreach ($lost as $case => [$activity, $userid, $usage]) {
            try {
                page_binding::verify($activity, $userid, $usage, (int) $activity->cmid);
                $this->fail($case . ': no error');
            } catch (moodle_exception $e) {
                $this->assertSame('attemptlost', $e->errorcode, $case);
            }
        }
        $this->assertSame(0, $DB->count_records('adaptivequiz_attempt'), 'No attempt is started for a lost one.');
    }
}
