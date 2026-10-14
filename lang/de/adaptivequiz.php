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
 * German strings of mod_adaptivequiz that the language packs do not have yet (issue #20).
 *
 * Every other string comes from the German language pack or falls back to English.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['privacy:metadata:adaptivequiz_attempt:stopcode'] = 'Warum der Versuch endete, als Code.';
$string['privacy:metadata:adaptivequiz_attempt:stopnoticeack'] = 'Wann die Person den Hinweis zum Ende des Versuchs bestätigt hat.';
$string['stopnotice_error'] = 'Ihr Test konnte wegen eines technischen Problems nicht fortgesetzt werden. Ihre abgeschickten Antworten sind gespeichert. Bitte wenden Sie sich an die für den Test verantwortliche Person.';
$string['stopnotice_manual'] = 'Ihr Versuch wurde von einer Lehrperson beendet. Ihre abgeschickten Antworten sind gespeichert.';
$string['stopnotice_noeligiblequestions'] = 'Für Sie stehen derzeit keine weiteren passenden Fragen zur Verfügung, deshalb wurde der Test beendet. Ihre abgeschickten Antworten sind gespeichert.';
$string['stopnotice_targetprecision'] = 'Ihre Antworten reichten aus, um Ihr Ergebnis mit der geforderten Genauigkeit zu bestimmen; weitere Fragen waren nicht nötig.';
$string['stopnotice_timelimit'] = 'Die für diesen Test verfügbare Bearbeitungszeit ist abgelaufen, deshalb wurde der Test automatisch beendet. Die vorher abgeschickten Antworten sind gespeichert; eine noch nicht abgeschickte Frage zählt nicht.';
$string['stopnotice_timelimit_duration'] = 'Ihr Versuch dauerte {$a->duration}.';
$string['stopnotice_unknown'] = 'Ihr Test wurde automatisch beendet. Ihre abgeschickten Antworten sind gespeichert.';
$string['stopnoticetitle_error'] = 'Ihr Test wurde wegen eines technischen Problems beendet';
$string['stopnoticetitle_info'] = 'Ihr Test wurde beendet';
