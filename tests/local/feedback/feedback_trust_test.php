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

namespace mod_adaptivequiz\local\feedback;

use advanced_testcase;
use context_course;
use context_module;
use mod_adaptivequiz\output\attempt_feedback;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');
require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/mod_form.php');

/**
 * The attempt feedback keeps the markup and scripts of a trusted author, and only theirs (issue #21).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(feedback_trust::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_feedback::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('adaptivequiz_update_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('xmldb_adaptivequiz_upgrade')]
final class feedback_trust_test extends advanced_testcase {
    /** @var string Markup a teacher writes: link, list, table, classes, data attributes, an image file, a script. */
    private const HTML = '<div class="aqfb" data-aq="42"><p>Intro <a href="https://example.org">Link</a></p>'
        . '<ul><li>One</li></ul><table><tbody><tr><td>Cell</td></tr></tbody></table>'
        . '<p><img src="@@PLUGINFILE@@/pic.png" alt="Pic"></p><p>Ability {{abilitymeasure}}</p>'
        . '<script>document.body.dataset.aqfb = "ran";</script></div>';

    /** @var stdClass */
    private stdClass $course;

    /** @var stdClass The activity record. */
    private stdClass $adaptivequiz;

    /** @var stdClass An editing teacher. */
    private stdClass $teacher;

    /**
     * A course with an activity and an editing teacher.
     */
    private function setup_activity(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $instance = $this->getDataGenerator()->create_module('adaptivequiz', [
            'course' => $this->course->id, 'lowestlevel' => 1, 'highestlevel' => 10,
        ]);
        $this->adaptivequiz = $this->record($instance->id);
    }

    /**
     * The stored activity.
     *
     * @param int|null $id
     * @return stdClass
     */
    private function record(?int $id = null): stdClass {
        global $DB;
        return $DB->get_record('adaptivequiz', ['id' => $id ?? $this->adaptivequiz->id], '*', MUST_EXIST);
    }

    /**
     * Saves the settings with this feedback, as the activity form submits them.
     *
     * @param string $text
     * @param array $extra More submitted fields.
     * @param int|null $itemid The editor's draft area, when it came from the form.
     * @return stdClass The stored activity.
     */
    private function save(string $text, array $extra = [], ?int $itemid = null): stdClass {
        $data = $this->record();
        $data->coursemodule = get_coursemodule_from_instance('adaptivequiz', $data->id)->id;
        $data->instance = $data->id;
        $data->attemptfeedbackenable = 1;
        $data->attemptfeedbackeditor = ['text' => $text, 'format' => FORMAT_HTML];
        if ($itemid !== null) {
            $data->attemptfeedbackeditor['itemid'] = $itemid;
        }
        foreach ($extra as $field => $value) {
            $data->$field = $value;
        }
        adaptivequiz_update_instance($data);
        return $this->record();
    }

    /**
     * The settings form of the activity, as the current user opens it.
     *
     * @return \mod_adaptivequiz_mod_form
     */
    private function form(): \mod_adaptivequiz_mod_form {
        global $PAGE;
        $course = get_course($this->course->id);
        $PAGE->set_course($course);
        $cm = get_coursemodule_from_instance('adaptivequiz', $this->adaptivequiz->id);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        return new \mod_adaptivequiz_mod_form($data, $cw->section, $cm, $course);
    }

    /**
     * Takes the capability from editing teachers in the course.
     */
    private function distrust_teachers(): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(feedback_trust::CAPABILITY, CAP_PROHIBIT, $roleid, context_course::instance($this->course->id));
    }

    /**
     * The feedback page's text for a finished attempt.
     *
     * @return string
     */
    private function shown(): string {
        global $PAGE;
        $adaptivequiz = $this->record();
        $cm = get_coursemodule_from_instance('adaptivequiz', $adaptivequiz->id);
        $attempt = (object) ['id' => 0, 'measure' => 0.0, 'instance' => $adaptivequiz->id, 'userid' => 2];
        $feedback = attempt_feedback::create($adaptivequiz, $cm, $attempt);
        return $feedback->export_for_template($PAGE->get_renderer('core'))['feedbacktext'];
    }

    /**
     * Where content could be lost, stage by stage: request, database, editor again, a second save, the page.
     *
     * The production report ("scripts are removed") is not reproduced by the code path itself; this pins each
     * stage, so the stage that changes something is named by the failing assertion. What does remove scripts:
     * the site setting "Content cleaning everywhere" (an administrator's choice that wins), an author without
     * the capability (the contract), and plain text format, which turns a script's line breaks into <br>.
     */
    public function test_each_stage_keeps_the_trusted_text(): void {
        global $CFG;
        $this->setup_activity();
        $this->setUser($this->teacher);

        $this->assertSame(self::HTML, clean_param(self::HTML, PARAM_RAW), 'Request: the form takes PARAM_RAW.');

        $stored = $this->save(self::HTML);
        $this->assertSame(self::HTML, $stored->attemptfeedback, 'Database.');
        $this->assertEquals(1, $stored->attemptfeedbacktrusted);
        $this->assertEquals(FORMAT_HTML, $stored->attemptfeedbackformat);

        $defaults = (array) $stored;
        $this->form()->data_preprocessing($defaults);
        $editor = $defaults['attemptfeedbackeditor'];
        $this->assertSame(
            str_replace('@@PLUGINFILE@@', '', self::HTML),
            preg_replace('~https?://[^"]*/draftfile\.php/\d+/user/draft/\d+~', '', $editor['text']),
            'Editor, opened again: the same text, file URLs pointing to the draft area.'
        );
        $this->assertEquals(FORMAT_HTML, $editor['format']);

        $second = $this->save($editor['text'], [], (int) $editor['itemid']);
        $this->assertSame(self::HTML, $second->attemptfeedback, 'Second save.');

        $shown = $this->shown();
        foreach (['<script>', 'data-aq="42"', 'class="aqfb"', '<table>', '<ul>', 'href="https://example.org"'] as $part) {
            $this->assertStringContainsString($part, $shown, "Page: $part");
        }
        $this->assertStringContainsString('/mod_adaptivequiz/attemptfeedback/0/pic.png', $shown, 'File URL rewritten.');
        $this->assertStringNotContainsString('{{abilitymeasure}}', $shown, 'Placeholder replaced.');

        $CFG->forceclean = 1;
        $this->assertStringNotContainsString('<script>', $this->shown(), '"Content cleaning everywhere" wins.');
    }

    /**
     * An author without the capability keeps markup but loses scripts, and the feedback is not trusted.
     */
    public function test_untrusted_author_cannot_store_a_script(): void {
        $this->setup_activity();
        $this->distrust_teachers();
        $this->setUser($this->teacher);

        $stored = $this->save(self::HTML);

        $this->assertStringNotContainsString('<script', $stored->attemptfeedback);
        $this->assertStringContainsString('<table>', $stored->attemptfeedback);
        $this->assertStringContainsString('href="https://example.org"', $stored->attemptfeedback);
        $this->assertEquals(0, $stored->attemptfeedbacktrusted);
        $this->assertStringNotContainsString('<script', $this->shown());
    }

    /**
     * The request cannot claim trust.
     */
    public function test_request_cannot_claim_trust(): void {
        $this->setup_activity();
        $this->distrust_teachers();
        $this->setUser($this->teacher);

        $stored = $this->save(self::HTML, ['attemptfeedbacktrusted' => 1]);

        $this->assertEquals(0, $stored->attemptfeedbacktrusted);
        $this->assertStringNotContainsString('<script', $stored->attemptfeedback);
    }

    /**
     * A teacher without the capability who saves other settings keeps a trusted author's feedback as it is.
     */
    public function test_untrusted_save_keeps_a_trusted_feedback(): void {
        $this->setup_activity();
        $this->setAdminUser();
        $this->save(self::HTML);
        $this->distrust_teachers();
        $this->setUser($this->teacher);

        $stored = $this->save(self::HTML, ['name' => 'Renamed']);

        $this->assertSame('Renamed', $stored->name);
        $this->assertSame(self::HTML, $stored->attemptfeedback);
        $this->assertEquals(1, $stored->attemptfeedbacktrusted);
    }

    /**
     * Untrusted feedback in the database - from wherever - is cleaned when shown.
     */
    public function test_untrusted_feedback_is_cleaned_when_shown(): void {
        global $DB;
        $this->setup_activity();
        $DB->update_record('adaptivequiz', (object) [
            'id' => $this->adaptivequiz->id, 'attemptfeedbackenable' => 1, 'attemptfeedback' => self::HTML,
            'attemptfeedbackformat' => FORMAT_HTML, 'attemptfeedbacktrusted' => 0,
        ]);

        $shown = $this->shown();

        $this->assertStringNotContainsString('<script', $shown);
        $this->assertStringContainsString('<table>', $shown);
    }

    /**
     * Restoring keeps the mark only for a trusted restoring user; backups without the mark count as trusted.
     */
    public function test_restored_mark(): void {
        $this->setup_activity();
        $context = context_course::instance($this->course->id);
        $this->setUser($this->teacher);
        $this->assertSame(1, feedback_trust::restored_mark((object) ['attemptfeedbacktrusted' => 1], $context));
        $this->assertSame(0, feedback_trust::restored_mark((object) ['attemptfeedbacktrusted' => 0], $context));
        $this->assertSame(1, feedback_trust::restored_mark((object) [], $context), 'Backup from before the mark.');

        $this->distrust_teachers();
        $this->assertSame(0, feedback_trust::restored_mark((object) ['attemptfeedbacktrusted' => 1], $context));
        $this->assertSame(0, feedback_trust::restored_mark((object) [], $context));
    }

    /**
     * The form tells an author without the capability that scripts are removed.
     */
    public function test_form_tells_an_untrusted_author(): void {
        $this->setup_activity();
        $this->distrust_teachers();
        $this->setUser($this->teacher);
        $mform = (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($this->form());

        $this->assertTrue($mform->elementExists('attemptfeedbacktrustnotice'));
        $this->assertStringContainsString(
            get_string('attemptfeedbackuntrusted', 'adaptivequiz'),
            $mform->getElement('attemptfeedbacktrustnotice')->toHtml()
        );
        $cm = get_coursemodule_from_instance('adaptivequiz', $this->adaptivequiz->id);
        $this->assertFalse(feedback_trust::is_trusted_author(context_module::instance($cm->id)));
    }

    /**
     * Feedback stored in another format is offered to the editor as HTML: edited as plain text, the editor
     * would fall back to a text area and the page turn a script's line breaks into <br>.
     */
    public function test_editor_always_offers_html(): void {
        global $DB;
        $this->setup_activity();
        $this->setAdminUser();
        $this->save('<p>Text</p>');
        $DB->set_field('adaptivequiz', 'attemptfeedbackformat', FORMAT_MOODLE, ['id' => $this->adaptivequiz->id]);

        $defaults = (array) $this->record();
        $this->form()->data_preprocessing($defaults);

        $this->assertEquals(FORMAT_HTML, $defaults['attemptfeedbackeditor']['format']);
    }

    /**
     * The upgrade keeps existing feedback trusted - it was shown without cleaning so far - and marks HTML as HTML.
     */
    public function test_upgrade_marks_existing_feedback(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/adaptivequiz/db/upgrade.php');
        $this->setup_activity();
        $plain = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $this->course->id]);
        $DB->update_record('adaptivequiz', (object) [
            'id' => $this->adaptivequiz->id, 'attemptfeedback' => '<p>Hi</p><script>x()</script>',
            'attemptfeedbackformat' => FORMAT_MOODLE, 'attemptfeedbacktrusted' => 0,
        ]);
        $DB->update_record('adaptivequiz', (object) [
            'id' => $plain->id, 'attemptfeedback' => "Line one\nLine two", 'attemptfeedbackformat' => FORMAT_MOODLE,
            'attemptfeedbacktrusted' => 0,
        ]);
        set_config('version', 2026100910, 'mod_adaptivequiz');

        $this->assertTrue(xmldb_adaptivequiz_upgrade(2026100910));
        unset_config('upgraderunning');
        unset($CFG->upgraderunning);

        $html = $this->record();
        $this->assertEquals(1, $html->attemptfeedbacktrusted);
        $this->assertEquals(FORMAT_HTML, $html->attemptfeedbackformat);
        $text = $this->record($plain->id);
        $this->assertEquals(1, $text->attemptfeedbacktrusted);
        $this->assertEquals(FORMAT_MOODLE, $text->attemptfeedbackformat, 'Plain text keeps its format and line breaks.');
    }
}
