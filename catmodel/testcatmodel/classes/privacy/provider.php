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

namespace adaptivequizcatmodel_testcatmodel\privacy;

use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use mod_adaptivequiz\privacy\adaptivequizcatmodel_provider;

/**
 * Records which privacy requests the activity passed on to this CAT model.
 *
 * It stores no data of its own - it only says that it was asked, which is what the contract test
 * needs to know.
 *
 * @package    adaptivequizcatmodel_testcatmodel
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    adaptivequizcatmodel_provider,
    \core_privacy\local\metadata\null_provider {
    /**
     * Returns the reason why this CAT model stores no personal data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }

    /**
     * Records an export request.
     *
     * @param int $userid The user to export for.
     * @param context_module $context The context of the activity.
     * @param array $subcontext Where the activity has placed its own export.
     */
    public static function export_catmodel_user_data(int $userid, context_module $context, array $subcontext): void {
        self::record('export', $userid);
    }

    /**
     * Records a deletion request for a whole context.
     *
     * @param context_module $context The context of the activity.
     */
    public static function delete_catmodel_data_for_all_users_in_context(context_module $context): void {
        self::record('delete_context', 0);
    }

    /**
     * Records a deletion request for one user.
     *
     * @param int $userid The user to delete for.
     * @param context_module $context The context of the activity.
     */
    public static function delete_catmodel_data_for_user(int $userid, context_module $context): void {
        self::record('delete_user', $userid);
    }

    /**
     * Records a deletion request for a list of users.
     *
     * @param approved_userlist $userlist The approved users, carrying the context.
     */
    public static function delete_catmodel_data_for_users(approved_userlist $userlist): void {
        self::record('delete_users', count($userlist->get_userids()));
    }

    /**
     * Records a request to name the users this CAT model holds data for.
     *
     * @param userlist $userlist The userlist to add to, carrying the context.
     */
    public static function add_catmodel_users_to_userlist(userlist $userlist): void {
        self::record('userlist', 0);
    }

    /**
     * Notes one request.
     *
     * @param string $what Which request came in.
     * @param int $detail A user id or a count, depending on the request.
     */
    private static function record(string $what, int $detail): void {
        $GLOBALS['adaptivequizcatmodel_testcatmodel_privacy'][] = $what . ':' . $detail;
    }

    /**
     * Forgets the requests recorded so far.
     */
    public static function reset(): void {
        $GLOBALS['adaptivequizcatmodel_testcatmodel_privacy'] = [];
    }

    /**
     * Returns the requests recorded so far.
     *
     * @return string[]
     */
    public static function recorded(): array {
        return $GLOBALS['adaptivequizcatmodel_testcatmodel_privacy'] ?? [];
    }

    /**
     * Adds no metadata: this CAT model stores nothing.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        return $collection;
    }
}
