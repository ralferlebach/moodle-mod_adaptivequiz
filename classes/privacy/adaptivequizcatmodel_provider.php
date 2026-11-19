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

namespace mod_adaptivequiz\privacy;

use context_module;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;

/**
 * What a CAT model subplugin has to implement when it stores personal data.
 *
 * A CAT model holds results of its own for an attempt - estimated abilities per scale, for
 * instance. Those are personal data in a context the activity owns, so the activity has to be able
 * to export and delete them. It cannot know their shape, so it asks the subplugin.
 *
 * A CAT model that stores nothing does not implement this interface; the activity then has nothing
 * to ask it.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface adaptivequizcatmodel_provider extends \core_privacy\local\request\plugin\subplugin_provider {
    /**
     * Exports what the CAT model holds for one user in one activity.
     *
     * @param int $userid The user to export for.
     * @param context_module $context The context of the activity.
     * @param array $subcontext Where the activity has placed its own export.
     */
    public static function export_catmodel_user_data(int $userid, context_module $context, array $subcontext): void;

    /**
     * Deletes what the CAT model holds for everyone in one activity.
     *
     * @param context_module $context The context of the activity.
     */
    public static function delete_catmodel_data_for_all_users_in_context(context_module $context): void;

    /**
     * Deletes what the CAT model holds for one user in one activity.
     *
     * @param int $userid The user to delete for.
     * @param context_module $context The context of the activity.
     */
    public static function delete_catmodel_data_for_user(int $userid, context_module $context): void;

    /**
     * Deletes what the CAT model holds for the given users in one activity.
     *
     * @param approved_userlist $userlist The approved users, carrying the context.
     */
    public static function delete_catmodel_data_for_users(approved_userlist $userlist): void;

    /**
     * Adds the users the CAT model holds data for in the given context.
     *
     * @param userlist $userlist The userlist to add to, carrying the context.
     */
    public static function add_catmodel_users_to_userlist(userlist $userlist): void;
}
