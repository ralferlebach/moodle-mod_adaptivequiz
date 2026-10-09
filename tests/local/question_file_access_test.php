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

namespace mod_adaptivequiz\local;

use advanced_testcase;
use context_module;

/**
 * Access to the question files of an attempt (issue #18).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(question_file_access::class)]
final class question_file_access_test extends advanced_testcase {
    /**
     * An activity with an attempt of a participant.
     *
     * @param string|null $catmodel
     * @param string $state
     * @return array [adaptivequiz, attempt, context, owner, course]
     */
    private function setup_attempt(?string $catmodel, string $state = 'complete'): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $adaptivequiz = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        if ($catmodel !== null) {
            $DB->set_field('adaptivequiz', 'catmodel', $catmodel, ['id' => $adaptivequiz->id]);
        }
        $adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $adaptivequiz->id]);
        $attempt = (object) [
            'instance' => $adaptivequiz->id, 'userid' => $owner->id, 'uniqueid' => 0, 'attemptstate' => $state,
            'attemptstopcriteria' => '', 'questionsattempted' => 2, 'difficultysum' => 0, 'standarderror' => 0,
            'measure' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ];
        $attempt->id = $DB->insert_record('adaptivequiz_attempt', $attempt);
        $cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id);
        return [$adaptivequiz, $attempt, context_module::instance($cm->id), $owner, $course];
    }

    /**
     * Without a CAT model that releases the review, an owner gets no files of a finished attempt.
     */
    public function test_built_in_algorithm_keeps_finished_attempts_closed_to_owners(): void {
        $this->resetAfterTest();

        [$adaptivequiz, $attempt, $context, $owner, $course] = $this->setup_attempt(null);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->assertFalse(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $owner->id));
        $this->assertTrue(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $teacher->id));
        $this->assertFalse(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $other->id));
    }

    /**
     * A CAT model decides whether the owner reviews; others are unaffected by it.
     */
    public function test_cat_model_releases_the_review(): void {
        $this->resetAfterTest();

        [$adaptivequiz, $attempt, $context, $owner, $course] = $this->setup_attempt('testcatmodel');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        set_config('reviewallowed', 1, 'adaptivequizcatmodel_testcatmodel');
        $this->assertTrue(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $owner->id));
        $this->assertFalse(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $other->id));
        set_config('reviewallowed', 0, 'adaptivequizcatmodel_testcatmodel');
        $this->assertFalse(question_file_access::review_allowed($adaptivequiz, $attempt, $context, (int) $owner->id));
    }

    /**
     * A finished attempt of a hidden activity passes the login of the course; a running one does not.
     */
    public function test_hidden_activity(): void {
        global $DB;
        $this->resetAfterTest();

        [, $attempt, $context, $owner] = $this->setup_attempt('testcatmodel');
        set_config('reviewallowed', 1, 'adaptivequizcatmodel_testcatmodel');
        set_coursemodule_visible($context->instanceid, 0);
        \course_modinfo::clear_instance_cache();
        $this->setUser($owner);

        question_file_access::require_access($attempt);

        $attempt->attemptstate = 'inprogress';
        $DB->update_record('adaptivequiz_attempt', $attempt);
        $this->expectException(\moodle_exception::class);
        question_file_access::require_access($attempt);
    }
}
