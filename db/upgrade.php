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

/**
 * Contains function with the definition of upgrade steps for the plugin.
 *
 * @package   mod_adaptivequiz
 * @copyright 2013 Remote-Learner {@link http://www.remote-learner.ca/}
 * @copyright 2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines upgrade steps for the plugin.
 *
 * @param mixed $oldversion
 */
function xmldb_adaptivequiz_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2014020400) {
        // Define field grademethod.
        $table = new xmldb_table('adaptivequiz');
        $field = new xmldb_field('grademethod', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, 1, 'startinglevel');

        // Conditionally add field grademethod.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Quiz savepoint reached.
        upgrade_mod_savepoint(true, 2014020400, 'adaptivequiz');
    }

    if ($oldversion < 2022012600) {
        $table = new xmldb_table('adaptivequiz');
        $field = new xmldb_field('showabilitymeasure', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, false, '0',
            'attemptfeedbackformat');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2022012600, 'adaptivequiz');
    }

    if ($oldversion < 2022092600) {
        $table = new xmldb_table('adaptivequiz');
        $field = new xmldb_field('completionattemptcompleted', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, false, 0);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2022092600, 'adaptivequiz');
    }

    if ($oldversion < 2022110200) {
        $table = new xmldb_table('adaptivequiz');
        $field = new xmldb_field('showattemptprogress', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 0,
            'showabilitymeasure');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2022110200, 'adaptivequiz');
    }

    if ($oldversion < 2024082100) {
        $table = new xmldb_table('adaptivequiz');
        $field = new xmldb_field('catmodel', XMLDB_TYPE_CHAR, 255);

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2024082100, 'adaptivequiz');
    }

    if ($oldversion < 2026082100) {
        // Issue #5: authoritative, immutable completion timestamp on the attempt.
        // NULL while the attempt is running; set exactly once at the transition
        // to COMPLETED. Additive and idempotent.
        $table = new xmldb_table('adaptivequiz_attempt');
        $field = new xmldb_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'measure');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026082100, 'adaptivequiz');
    }

    if ($oldversion < 2026082105) {
        // Issue #8: couple activity completion to a valid CAT result. Add the
        // per-attempt result fields and the per-activity completion rule flag.
        // Additive and idempotent.
        $table = new xmldb_table('adaptivequiz_attempt');
        $field = new xmldb_field('resultstatus', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'timefinished');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('resultvalid', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'resultstatus');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('adaptivequiz');
        $field = new xmldb_field(
            'completionvalidresult',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'completionattemptcompleted'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026082105, 'adaptivequiz');
    }

    return true;
}
