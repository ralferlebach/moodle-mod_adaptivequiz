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

namespace mod_adaptivequiz\local\catmodel;

use adaptivequizcatmodel_testcatmodel\privacy\provider as catmodelprovider;
use context_module;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\tests\provider_testcase;
use mod_adaptivequiz\local\attempt\attempt_state;
use mod_adaptivequiz\privacy\provider;
use stdClass;

/**
 * The activity passes every privacy request on to the CAT models installed.
 *
 * A CAT model keeps results of its own for an attempt. The activity cannot know their shape, so it
 * has to ask - otherwise an export is incomplete and a deletion leaves data behind.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class privacy_delegation_test extends provider_testcase {
    /** @var stdClass The activity instance under test. */
    private stdClass $adaptivequiz;

    /** @var context_module The context of that activity. */
    private context_module $context;

    /** @var stdClass The user with an attempt. */
    private stdClass $user;

    /**
     * Builds an activity driven by the fixture CAT model, with one attempt.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->adaptivequiz = $generator->create_module('adaptivequiz', [
            'course' => $course->id,
            'catmodel' => 'testcatmodel',
        ]);
        $this->context = context_module::instance($this->adaptivequiz->cmid);
        $this->user = $generator->create_user();

        $DB->insert_record('adaptivequiz_attempt', (object) [
            'instance' => $this->adaptivequiz->id,
            'userid' => $this->user->id,
            'uniqueid' => 0,
            'attemptstate' => attempt_state::COMPLETED,
            'attemptstopcriteria' => 'done',
            'questionsattempted' => 3,
            'difficultysum' => 15.0,
            'standarderror' => 0.5,
            'measure' => 0.5,
            'timecreated' => time() - 100,
            'timemodified' => time(),
        ]);

        catmodelprovider::reset();
    }

    /**
     * The metadata says that CAT model subplugins hold data of their own.
     */
    public function test_metadata_links_to_the_subplugin_type(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('mod_adaptivequiz'));

        $types = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\plugintype_link) {
                $types[] = $item->get_name();
            }
        }

        $this->assertContains('adaptivequizcatmodel', $types);
    }

    /**
     * An export asks the CAT model as well.
     */
    public function test_export_is_passed_on(): void {
        $this->export_context_data_for_user((int) $this->user->id, $this->context, 'mod_adaptivequiz');

        $this->assertContains('export:' . $this->user->id, catmodelprovider::recorded());
    }

    /**
     * Deleting one user is passed on.
     */
    public function test_delete_for_user_is_passed_on(): void {
        provider::delete_data_for_user(
            new approved_contextlist($this->user, 'mod_adaptivequiz', [$this->context->id])
        );

        $this->assertContains('delete_user:' . $this->user->id, catmodelprovider::recorded());
    }

    /**
     * Deleting a whole context is passed on.
     */
    public function test_delete_for_context_is_passed_on(): void {
        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertContains('delete_context:0', catmodelprovider::recorded());
    }

    /**
     * Deleting an approved list of users is passed on.
     */
    public function test_delete_for_users_is_passed_on(): void {
        provider::delete_data_for_users(
            new approved_userlist($this->context, 'mod_adaptivequiz', [(int) $this->user->id])
        );

        $this->assertContains('delete_users:1', catmodelprovider::recorded());
    }

    /**
     * Collecting the users of a context asks the CAT model too.
     */
    public function test_userlist_is_passed_on(): void {
        provider::get_users_in_context(new userlist($this->context, 'mod_adaptivequiz'));

        $this->assertContains('userlist:0', catmodelprovider::recorded());
    }
}
