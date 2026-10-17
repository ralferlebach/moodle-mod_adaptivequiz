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
use context;
use context_course;
use context_module;
use question_bank;
use question_engine;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * The file of a question in an attempt, served through mod_adaptivequiz_question_pluginfile() (issues #16, #18).
 *
 * Each case asks for a real file of a real question in a real question usage, the way pluginfile.php
 * does: a served file is output, a refused one throws. The cases: owner of a running and of a finished
 * attempt, with the activity open, restricted and hidden; another participant; a teacher; a user
 * without access to the course; and paths that name a question or a slot outside the attempt.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('mod_adaptivequiz_question_pluginfile')]
#[\PHPUnit\Framework\Attributes\CoversClass(question_file_access::class)]
final class question_pluginfile_test extends advanced_testcase {
    /** @var string Content of the file in the question text. */
    private const CONTENT = 'figure of the question';

    /** @var stdClass */
    private stdClass $course;

    /** @var stdClass The activity record. */
    private stdClass $adaptivequiz;

    /** @var context_module */
    private context_module $modcontext;

    /** @var stdClass The participant who owns the attempt. */
    private stdClass $owner;

    /** @var stdClass The attempt record. */
    private stdClass $attempt;

    /** @var stdClass The question in slot 1, with a file in its text. */
    private stdClass $question;

    /** @var stdClass A question of the same bank that is not in the attempt, also with a file. */
    private stdClass $otherquestion;

    /** @var context The context of the question bank. */
    private context $qcontext;

    /**
     * A course, the activity with the test CAT model, two questions with a file each, and an attempt
     * of the owner with the first question in slot 1.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->owner = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_adaptivequiz')->create_instance([
            'course' => $this->course->id, 'highestlevel' => 10, 'lowestlevel' => 1, 'standarderror' => 14,
            'attemptfeedbackeditor' => ['text' => '', 'format' => FORMAT_MOODLE],
        ]);
        $DB->set_field('adaptivequiz', 'catmodel', 'testcatmodel', ['id' => $instance->id]);
        $this->adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $instance->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('adaptivequiz', $instance->id);
        $this->modcontext = context_module::instance($cm->id);

        $this->qcontext = $this->question_bank_context();
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $this->qcontext->id]);
        $this->question = $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]);
        $this->otherquestion = $questiongenerator->create_question('shortanswer', null, ['category' => $category->id]);
        foreach ([$this->question, $this->otherquestion] as $question) {
            get_file_storage()->create_file_from_string([
                'contextid' => $this->qcontext->id, 'component' => 'question', 'filearea' => 'questiontext',
                'itemid' => $question->id, 'filepath' => '/', 'filename' => 'figure.txt',
            ], self::CONTENT);
        }

        $quba = question_engine::make_questions_usage_by_activity('mod_adaptivequiz', $this->modcontext);
        $quba->set_preferred_behaviour('deferredfeedback');
        $slot = $quba->add_question(question_bank::load_question($this->question->id));
        $quba->start_question($slot);
        question_engine::save_questions_usage_by_activity($quba);

        $this->attempt = (object) [
            'instance' => $this->adaptivequiz->id, 'userid' => $this->owner->id, 'uniqueid' => $quba->get_id(),
            'attemptstate' => attempt\attempt_state::COMPLETED, 'attemptstopcriteria' => '', 'questionsattempted' => 1,
            'difficultysum' => 0, 'standarderror' => 0, 'measure' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ];
        $this->attempt->id = $DB->insert_record('adaptivequiz_attempt', $this->attempt);
        set_config('reviewallowed', 1, 'adaptivequizcatmodel_testcatmodel');
    }

    /**
     * The context of a question bank of the course: a question bank module from Moodle 5.0 on, the course before.
     *
     * @return context
     */
    private function question_bank_context(): context {
        if (class_exists('\core_question\local\bank\question_bank_helper')) {
            $qbank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($this->course, true);
            return context_module::instance($qbank->id);
        }
        return context_course::instance($this->course->id);
    }

    /**
     * The file a request of pluginfile.php gets: the content of the file that would be sent.
     *
     * Sending a file writes headers, which a unit test cannot; the request is therefore checked up to
     * the file that mod_adaptivequiz_question_pluginfile() hands to send_stored_file().
     *
     * @param stdClass $question The question named in the path.
     * @param int $slot The slot named in the path.
     * @return string|null The content, null for "not found".
     */
    private function fetch(stdClass $question, int $slot = 1): ?string {
        global $PAGE;
        // Every request of pluginfile.php starts with a fresh page.
        $PAGE = new \moodle_page();
        $file = question_file_access::file_for_request(
            $this->qcontext,
            'question',
            'questiontext',
            (int) $this->attempt->uniqueid,
            $slot,
            [$question->id, 'figure.txt'],
            false
        );
        return $file ? $file->get_content() : null;
    }

    /**
     * Asks for the file and expects it to be refused: access denied, or "not found".
     *
     * @param stdClass $question
     * @param int $slot
     * @param string $why
     */
    private function assert_refused(stdClass $question, int $slot, string $why): void {
        try {
            $this->assertNull($this->fetch($question, $slot), 'Served: ' . $why);
        } catch (\moodle_exception $e) {
            $this->assert_refusal($e, $why);
        }
    }

    /**
     * An exception is a refusal of access, not a fault of the test.
     *
     * @param \moodle_exception $e
     * @param string $why
     */
    private function assert_refusal(\moodle_exception $e, string $why): void {
        $this->assertNotInstanceOf(\coding_exception::class, $e, $e->getMessage());
        $this->assertContains($e->errorcode, ['requireloginerror', 'redirecterrordetected', 'nopermission', 'notenrolled',
            'noattemptsallowed', 'uniquenotpartofattempt'], $why . ': ' . $e->errorcode);
    }

    /**
     * Hides the activity.
     */
    private function hide_activity(): void {
        set_coursemodule_visible($this->modcontext->instanceid, 0);
        \course_modinfo::clear_instance_cache();
    }

    /**
     * Restricts the activity to a date that has passed, the way a teacher closes it.
     */
    private function restrict_activity(): void {
        global $DB;
        $availability = json_encode(\core_availability\tree::get_root_json([
            \availability_date\condition::get_json('<', time() - DAYSECS),
        ]));
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $this->modcontext->instanceid]);
        rebuild_course_cache($this->course->id, true);
    }

    /**
     * The owner of a finished attempt gets the file - with the activity open, restricted and hidden.
     */
    public function test_owner_of_a_finished_attempt(): void {
        $this->setUser($this->owner);
        $this->assertSame(self::CONTENT, $this->fetch($this->question));

        $this->restrict_activity();
        $this->assertFalse(get_fast_modinfo($this->course, $this->owner->id)->get_cm($this->modcontext->instanceid)->uservisible);
        $this->assertSame(self::CONTENT, $this->fetch($this->question), 'Restricted activity.');

        $this->hide_activity();
        $this->assertSame(self::CONTENT, $this->fetch($this->question), 'Hidden activity.');
    }

    /**
     * The test does not release the questions to the owner: no file of a finished attempt.
     */
    public function test_owner_without_released_review(): void {
        set_config('reviewallowed', 0, 'adaptivequizcatmodel_testcatmodel');
        $this->setUser($this->owner);
        $this->assert_refused($this->question, 1, 'review not released');
    }

    /**
     * A running attempt keeps the rules of the activity: open, it serves; restricted or hidden, it does not.
     * The review route does not reopen a running attempt.
     */
    public function test_owner_of_a_running_attempt(): void {
        global $DB;
        $DB->set_field('adaptivequiz_attempt', 'attemptstate', attempt\attempt_state::IN_PROGRESS, ['id' => $this->attempt->id]);
        $this->setUser($this->owner);
        $this->assertSame(self::CONTENT, $this->fetch($this->question));

        $this->restrict_activity();
        $this->assert_refused($this->question, 1, 'running attempt of a restricted activity');

        $this->hide_activity();
        $this->assert_refused($this->question, 1, 'running attempt of a hidden activity');
    }

    /**
     * Another participant gets nothing; a teacher gets the file, also with the activity hidden.
     */
    public function test_other_participant_and_teacher(): void {
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->hide_activity();

        $this->setUser($other);
        $this->assert_refused($this->question, 1, 'attempt of another participant');

        $this->setUser($teacher);
        $this->assertSame(self::CONTENT, $this->fetch($this->question));
    }

    /**
     * Without access to the course, nothing - the owner, too, once unenrolled.
     */
    public function test_no_course_access(): void {
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);
        $this->assert_refused($this->question, 1, 'user outside the course');

        $enrol = enrol_get_plugin('manual');
        $instance = enrol_get_instances($this->course->id, true);
        $instance = reset($instance);
        $enrol->unenrol_user($instance, $this->owner->id);
        $this->setUser($this->owner);
        $this->assert_refused($this->question, 1, 'unenrolled owner');
    }

    /**
     * A path with a question or a slot outside the attempt is refused, even for the owner and a teacher.
     */
    public function test_only_the_question_in_the_slot(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        foreach ([$this->owner, $teacher] as $user) {
            $this->setUser($user);
            $this->assertSame(self::CONTENT, $this->fetch($this->question));
            $this->assert_refused($this->otherquestion, 1, 'question outside the attempt');
            $this->assert_refused($this->question, 2, 'slot outside the attempt');
        }
    }

    /**
     * The callback itself refuses another participant before anything is sent.
     */
    public function test_callback_refuses_another_participant(): void {
        global $PAGE;
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));
        $PAGE = new \moodle_page();
        try {
            mod_adaptivequiz_question_pluginfile(
                $this->course,
                $this->qcontext,
                'question',
                'questiontext',
                (int) $this->attempt->uniqueid,
                1,
                [$this->question->id, 'figure.txt'],
                false,
                ['dontdie' => true]
            );
            $this->fail('Served the file of another participant.');
        } catch (\moodle_exception $e) {
            $this->assert_refusal($e, 'another participant');
        }
    }
}
