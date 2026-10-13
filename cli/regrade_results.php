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
 * Rebuilds the grades of activities from their result snapshots (issue #19).
 *
 * Safe to repeat: attempts with a snapshot keep it unless named with --resnapshot, and every grade
 * follows the grading method from the valid results alone. Attempts without a valid result are
 * listed with their reason - they get no grade, not an invented one.
 *
 * Usage: php mod/adaptivequiz/cli/regrade_results.php (--instance=N | --all) [--resnapshot=1,2,3]
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_adaptivequiz\local\result\result_service;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['instance' => 0, 'all' => false, 'resnapshot' => '', 'help' => false],
    ['h' => 'help']
);
if ($options['help'] || $unrecognised || (empty($options['instance']) && !$options['all'])) {
    cli_writeln('Rebuilds the grades of adaptive quiz activities from their result snapshots.');
    cli_writeln('  --instance=N        The activity (adaptivequiz.id).');
    cli_writeln('  --all               Every activity.');
    cli_writeln('  --resnapshot=1,2,3  Retake the snapshot of these attempts (adaptivequiz_attempt.id), e.g. after');
    cli_writeln('                      the CAT model judged them again. The range of today is used for them.');
    exit($options['help'] ? 0 : 1);
}

$resnapshot = array_values(array_filter(array_map('intval', explode(',', (string) $options['resnapshot']))));
$conditions = $options['all'] ? null : ['id' => (int) $options['instance']];
$instances = $DB->get_recordset('adaptivequiz', $conditions, 'id');
foreach ($instances as $adaptivequiz) {
    $report = result_service::regrade($adaptivequiz, $resnapshot);
    cli_writeln("adaptivequiz {$adaptivequiz->id}: {$report->attempts} completed attempt(s), {$report->snapshots} snapshot(s) "
        . "taken, {$report->valid} with a valid result.");
    foreach ($report->ungraded as $attemptid => $reason) {
        cli_writeln("  attempt $attemptid: no grade" . ($reason !== '' ? " ($reason)" : ''));
    }
}
$instances->close();
