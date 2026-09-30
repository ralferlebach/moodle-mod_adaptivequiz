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
 * Adaptive quiz attempt script
 *
 * @package    mod_adaptivequiz
 * @copyright  2013 Remote-Learner {@link http://www.remote-learner.ca/}
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');

use mod_adaptivequiz\output\ability_measure;

$cmid = required_param('cmid', PARAM_INT); // Course module id.
$instance = required_param('id', PARAM_INT); // Activity instance id.
$uniqueid = required_param('uattid', PARAM_INT); // Attempt unique id.

if (!$cm = get_coursemodule_from_id('adaptivequiz', $cmid)) {
    throw new moodle_exception('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    throw new moodle_exception('coursemisconf');
}

$adaptivequiz = $DB->get_record('adaptivequiz', ['id' => $cm->instance], '*', MUST_EXIST);

$abilitymeasurerenderable = null;
if ($adaptivequiz->showabilitymeasure) {
    $abilitymeasurevalue = $DB->get_field('adaptivequiz_attempt', 'measure', ['uniqueid' => $uniqueid], MUST_EXIST);
    $abilitymeasurerenderable = ability_measure::of_attempt_on_adaptive_quiz($adaptivequiz, $abilitymeasurevalue);
}

// Deliberately without the course module: passing $cm makes require_login()
// enforce $cm->uservisible, and that is false as soon as the activity - or the
// section it sits in - is unavailable. A common setup restricts the section on
// completion of this very quiz, so finishing it would take the result page away
// at the moment the participant wants to read it.
//
// Course access is still required, and the ownership check below is what actually
// protects this page: it only ever shows the attempt of the person asking.
// Teachers and managers reach other people's attempts through the reports.
//
// The consequence is intended and worth stating plainly: hiding the activity no
// longer hides the result page. attempt.php is untouched and still enforces
// visibility, so a hidden activity cannot be continued - only its finished result
// can be read.
require_login($course);
$context = context_module::instance($cm->id);

// TODO - check if user has capability to attempt.

// Loaded once, after the login and only if it is this user's attempt of this activity; the event,
// the feedback of the CAT model and the rest of the page all use this row.
$attempt = adaptivequiz_result_page_attempt($cm, $uniqueid, $instance, (int) $USER->id);

// The result page of a completed attempt was opened (issue #15). Every view is an event; which was
// the first is for the consumer to decide. No visibility check here: an own completed result stays
// readable even if completion has since hidden the activity.
if ($attempt->attemptstate === \mod_adaptivequiz\local\attempt\attempt_state::COMPLETED) {
    \mod_adaptivequiz\event\result_page_viewed::create_from_attempt($attempt, $context)->trigger();
}

// The require_login() call above omits the course module, so it does not set the
// module on the page either - and the navigation then reads properties off a null
// course module while building the secondary nav. Setting it here restores that
// without reintroducing the visibility check, which is the whole point of the call
// above: a completed activity may be hidden while its result stays readable.
$PAGE->set_cm($cm, $course);
$PAGE->set_url('/mod/adaptivequiz/view.php', array('id' => $cm->id));
$PAGE->set_title(format_string($adaptivequiz->name));
$PAGE->set_context($context);
$PAGE->activityheader->disable();
$PAGE->add_body_class('limitedwidth');

$output = $PAGE->get_renderer('mod_adaptivequiz');

// Init secure window if enabled.
$popup = false;
if (!empty($adaptivequiz->browsersecurity)) {
    $PAGE->blocks->show_only_fake_blocks();
    $output->init_browser_security(false);
    $popup = true;
} else {
    $PAGE->set_heading(format_string($course->fullname));
}

$attemptfeedback = $adaptivequiz->attemptfeedback;
if (!empty($adaptivequiz->catmodel)) {
    // Try wire up the custom feedback from the sub-plugin being used. If it's implemented in a sub-plugin, it always has
    // a precedence over the default feedback provided by the activity.
    $pluginswithfunction = get_plugin_list_with_function('adaptivequizcatmodel', 'attempt_finished_feedback');
    $catmodelcomponentname = 'adaptivequizcatmodel_' . $adaptivequiz->catmodel;
    if (array_key_exists($catmodelcomponentname, $pluginswithfunction)) {
        $functionname = $pluginswithfunction[$catmodelcomponentname];

        $attemptfeedback = $functionname($adaptivequiz, $cm, $attempt);
    }
}

echo $output->header();
echo $output->attempt_feedback($attemptfeedback, $cm->id, $abilitymeasurerenderable, $popup);
echo $output->footer();
