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
 * German strings for the adaptive quiz activity.
 *
 * Deliberately not a full translation. The plugin's German strings normally come from
 * AMOS (lang.moodle.org), and a downloaded language pack takes precedence over the
 * one shipped here - so a half-finished translation in the repository would be
 * overwritten for anyone who installs the German pack, while quietly diverging for
 * everyone who does not.
 *
 * What belongs here are strings that AMOS does not have yet: the two buttons on the
 * attempt result page were added with the plugin itself and exist in no language pack.
 *
 * @package    mod_adaptivequiz
 * @copyright  2013 onwards Remote-Learner {@link http://www.remote-learner.ca/}
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['attemptcreatelocktimeout'] = 'Ihr Versuch wird gerade in einem anderen Fenster oder Tab gestartet. Bitte warten Sie einen Moment und versuchen Sie es erneut.';
$string['attemptexpiredended'] = 'Die Zeit für Ihren vorherigen Versuch war abgelaufen, deshalb wurde er beendet. Sie können einen neuen Versuch beginnen.';
$string['btnbacktotest'] = 'Zurück zum Test';
$string['btncontinuetocourse'] = 'Weiter zum Kurs';
$string['catmodelsessionerror'] = '{$a}';
$string['gradingmode'] = 'Bewertung';
$string['gradingmode_help'] = 'Ob die Aktivität eine Bewertung in die Bewertungsübersicht schreibt. Ohne Bewertung werden Versuche wie immer gemessen und beurteilt - ein gültiges Ergebnis bleibt gültig, Feedback und die Abschlussregel „gültiges Ergebnis“ funktionieren -, aber es gibt keine Note, keine Bestehensgrenze und keine Bewertung für LTI. Ein Abschluss über das Erhalten oder Bestehen einer Bewertung ist dann nicht möglich.';
$string['gradingmode_off'] = 'Keine Bewertung';
$string['gradingmode_on'] = 'Bewertet';
$string['gradingmodeoffcompletion'] = 'Ohne Bewertung gibt es keine Bewertung, die erhalten oder bestanden werden kann. Verwenden Sie stattdessen die Abschlussregel „gültiges Ergebnis“.';
$string['keepgrades'] = 'Bestehende Bewertungen bei geänderten Bewertungseinstellungen behalten';
$string['keepgrades_help'] = 'Ist das gesetzt, berechnet eine Änderung von Bewertung, Linkfunktion, Bestehensgrenze oder Steilheit - und der Itembatterie, die eine Linkfunktion liest - die Bewertungen abgeschlossener Versuche nicht neu; neue Versuche werden mit den aktuellen Einstellungen bewertet. Gezielt neu berechnen kann eine Administratorin mit cli/regrade_results.php --relink (erst Vorschau, dann --confirm). Moodle führt die Bestehensgrenze je Bewertungsaspekt, nicht je Versuch: Eine neue Bestehensgrenze kann daher weiterhin ändern, wer bestanden hat, und den Abschluss über eine bestandene Bewertung, obwohl die Bewertungen gleich bleiben.';
$string['linkfunction'] = 'Linkfunktion';
$string['linkfunction_default'] = 'Vorgabe der Ergebnisquelle';
$string['linkfunction_fisher_rao'] = 'Fisher-Rao-Bogenlänge (Information der Itembatterie)';
$string['linkfunction_guttman_pass'] = 'Alles oder nichts an der Bestehensgrenze';
$string['linkfunction_help'] = 'Wie aus einem Ergebnis eine Bewertung zwischen 0 und 100 % wird. L und U sind die Grenzen des Ergebnisbereichs, P die Bestehensgrenze, k die Steilheit.<ul><li>Linear: gleichmäßig von L (0 %) bis U (100 %).</li><li>Stückweise linear: L bis P auf 0-50 %, P bis U auf 50-100 %. Braucht eine Bestehensgrenze innerhalb des Bereichs.</li><li>Logistisch, Mitte: eine S-Kurve mit 50 % in der Mitte des Bereichs; sie erreicht 0 und 100 % nicht.</li><li>Logistisch, Bestehensgrenze: eine S-Kurve mit 50 % an der Bestehensgrenze.</li><li>Alles oder nichts: 100 % ab der Bestehensgrenze, darunter 0 %.</li><li>Fisher-Rao: der Anteil der Messgenauigkeit der Itembatterie zwischen L und dem Ergebnis - die Skala zählt dort am meisten, wo die Items am genauesten messen. Verwendet wird die Itembatterie, wie sie jetzt kalibriert ist. Ist der Wert nicht berechenbar, erhält der Versuch keine Bewertung.</li></ul>';
$string['linkfunction_linear'] = 'Linear';
$string['linkfunction_logistic_mid'] = 'Logistisch, 50 % in der Mitte des Bereichs';
$string['linkfunction_logistic_pass'] = 'Logistisch, 50 % an der Bestehensgrenze';
$string['linkfunction_piecewise_pass50'] = 'Stückweise linear, Bestehensgrenze bei 50 %';
$string['linkneedspassscore'] = 'Diese Linkfunktion braucht eine Bestehensgrenze.';
$string['linknoinformation'] = 'Die Ergebnisquelle bietet für diese Aktivität keine verwendbare Testinformation; die Fisher-Rao-Linkfunktion kann keine Bewertungen berechnen.';
$string['linkpassinsiderange'] = 'Für diese Linkfunktion muss die Bestehensgrenze echt innerhalb des Ergebnisbereichs liegen.';
$string['linksteepness'] = 'Steilheit k';
$string['linksteepness_help'] = 'Steilheit der logistischen Linkfunktionen: je größer k, desto steiler die S-Kurve um ihre Mitte. Leer bedeutet 1.';
$string['linksteepnessinvalid'] = 'Die Steilheit muss eine Zahl über 0 sein.';
$string['privacy:metadata:adaptivequiz_attempt:stopcode'] = 'Warum der Versuch endete, als Code.';
$string['privacy:metadata:adaptivequiz_attempt:stopnoticeack'] = 'Wann die Person den Hinweis zum Ende des Versuchs bestätigt hat.';
$string['restartattemptbtn'] = 'Neuen Versuch beginnen';
$string['restartattemptconfirm'] = 'Ihr laufender Versuch wird beendet und so gewertet, wie er ist. Neuen Versuch beginnen?';
$string['restartnotallowed'] = 'Ein weiterer Versuch ist nicht erlaubt; der laufende Versuch kann nur fortgesetzt werden.';
$string['resumeattemptbtn'] = 'Versuch fortsetzen';
$string['resumeattemptinfo'] = 'Sie haben einen laufenden Versuch, begonnen am {$a}.';
$string['resumeattemptitemexpired'] = 'Die Zeit für die Frage, bei der Sie waren, ist abgelaufen. Wenn Sie fortsetzen, erhalten Sie eine neue Frage; die abgelaufene zählt als nicht gelöst.';
$string['resumenewquestionbtn'] = 'Mit neuer Frage fortsetzen';
$string['stopnotice_error'] = 'Ihr Test konnte wegen eines technischen Problems nicht fortgesetzt werden. Ihre abgeschickten Antworten sind gespeichert. Bitte wenden Sie sich an die für den Test verantwortliche Person.';
$string['stopnotice_manual'] = 'Ihr Versuch wurde von einer Lehrperson beendet. Ihre abgeschickten Antworten sind gespeichert.';
$string['stopnotice_noeligiblebeforeminimum'] = 'Für Sie standen nicht genug passende Fragen zur Verfügung, um die Mindestlänge des Tests zu erreichen; deshalb wurde er vorzeitig beendet. Ihre abgeschickten Antworten sind gespeichert; ob sie ein Ergebnis ergeben, steht unten.';
$string['stopnotice_noeligiblequestions'] = 'Für Sie stehen derzeit keine weiteren passenden Fragen zur Verfügung, deshalb wurde der Test beendet. Ihre abgeschickten Antworten sind gespeichert.';
$string['stopnotice_restarted'] = 'Sie haben diesen Versuch beendet, um einen neuen zu beginnen. Ihre abgegebenen Antworten sind gespeichert.';
$string['stopnotice_scalescomplete'] = 'Alle Teilbereiche des Tests sind so weit erfasst, wie der Test es verlangt; weitere Fragen waren nicht nötig.';
$string['stopnotice_targetprecision'] = 'Ihre Antworten reichten aus, um Ihr Ergebnis mit der geforderten Genauigkeit zu bestimmen; weitere Fragen waren nicht nötig.';
$string['stopnotice_timelimit'] = 'Die für diesen Test verfügbare Bearbeitungszeit ist abgelaufen, deshalb wurde der Test automatisch beendet. Die vorher abgeschickten Antworten sind gespeichert; eine noch nicht abgeschickte Frage zählt nicht.';
$string['stopnotice_timelimit_limit'] = 'Die Bearbeitungszeit betrug {$a}.';
$string['stopnotice_unknown'] = 'Ihr Test wurde automatisch beendet. Ihre abgeschickten Antworten sind gespeichert.';
$string['stopnoticetitle_error'] = 'Ihr Test wurde wegen eines technischen Problems beendet';
$string['stopnoticetitle_info'] = 'Ihr Test wurde beendet';
