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
 * Adaptive quiz attempt script.
 *
 * @copyright  2013 onwards Remote-Learner {@link http://www.remote-learner.ca/}
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/tag/lib.php');

use mod_adaptivequiz\local\attempt;
use mod_adaptivequiz\cat_session;
use mod_adaptivequiz\local\catalgo;
use mod_adaptivequiz\local\fetchquestion;
use mod_adaptivequiz\output\attempt_debug_info;

$id = required_param('cmid', PARAM_INT); // Course module id.
$uniqueid  = optional_param('uniqueid', 0, PARAM_INT);  // Unique id of the attempt.
$difflevel  = optional_param('dl', 0, PARAM_INT);  // Difficulty level of question.

if (!$cm = get_coursemodule_from_id('adaptivequiz', $id)) {
    throw new moodle_exception('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', ['id' => $cm->course])) {
    throw new moodle_exception('coursemisconf');
}

global $USER, $DB, $SESSION;

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
$passwordattempt = false;

try {
    $adaptivequiz  = $DB->get_record('adaptivequiz', ['id' => $cm->instance], '*', MUST_EXIST);
} catch (dml_exception $e) {
    $url = new moodle_url('/mod/adaptivequiz/attempt.php', ['cmid' => $id]);
    $debuginfo = '';

    if (!empty($e->debuginfo)) {
        $debuginfo = $e->debuginfo;
    }

    throw new moodle_exception('invalidmodule', 'error', $url, $e->getMessage(), $debuginfo);
}

// Setup page global for standard viewing.
$viewurl = new moodle_url('/mod/adaptivequiz/view.php', ['id' => $cm->id]);
$PAGE->set_url('/mod/adaptivequiz/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($adaptivequiz->name));
$PAGE->set_context($context);
$PAGE->activityheader->disable();
$PAGE->add_body_class('limitedwidth');

// Check if the user has the attempt capability.
require_capability('mod/adaptivequiz:attempt', $context);

// Check if the user has any previous attempts at this activity.
$count = adaptivequiz_count_user_previous_attempts($adaptivequiz->id, $USER->id);

if (!adaptivequiz_allowed_attempt($adaptivequiz->attempts, $count)) {
    throw new moodle_exception('noattemptsallowed', 'adaptivequiz');
}

// Create an instance of the module renderer class.
$output = $PAGE->get_renderer('mod_adaptivequiz');
// Setup password required form.
$mform = $output->display_password_form($cm->id);
// Check if a password is required.
if (!empty($adaptivequiz->password)) {
    // Check if the user has alredy entered in their password.
    $condition = adaptivequiz_user_entered_password($adaptivequiz->id);

    if (empty($condition) && $mform->is_cancelled()) {
        // Return user to landing page.
        redirect($viewurl);
    } else if (empty($condition) && $data = $mform->get_data()) {
        $SESSION->passwordcheckedadpq = [];

        if (0 == strcmp($data->quizpassword, $adaptivequiz->password)) {
            $SESSION->passwordcheckedadpq[$adaptivequiz->id] = true;
        } else {
            $SESSION->passwordcheckedadpq[$adaptivequiz->id] = false;
            $passwordattempt = true;
        }
    }
}

// Create an instance of the adaptiveattempt class.
$adaptiveattempt = new attempt($adaptivequiz, $USER->id);
$nextdiff = null;
$standarderror = 0.0;
$message = '';

// If uniqueid is not empty, process the responses.
if (!empty($uniqueid) && confirm_sesskey()) {
    try {
        $qubahelper = function (question_usage_by_activity $quba): void {
            $time = time();
            $quba->process_all_actions($time);
            $quba->finish_all_questions($time);
        };

        $itemresult = cat_session::process_administered_item_result(
            (int) $uniqueid,
            $adaptivequiz,
            $adaptiveattempt,
            $qubahelper
        );

        $difflevel = $itemresult->answereddifficulty;
        $nextdiff = $itemresult->nextdifficulty;
        $standarderror = $itemresult->standarderror;

        if ($itemresult->attempt_is_to_stop()) {
            adaptivequiz_complete_attempt(
                $uniqueid,
                $adaptivequiz,
                $context,
                $USER->id,
                $standarderror,
                $itemresult->stoppagereason
            );

            $param = ['cmid' => $cm->id, 'id' => $cm->instance, 'uattid' => $uniqueid];
            redirect(new moodle_url('/mod/adaptivequiz/attemptfinished.php', $param));
        }
    } catch (question_out_of_sequence_exception $e) {
        $url = new moodle_url('/mod/adaptivequiz/attempt.php', ['cmid' => $id]);
        throw new moodle_exception('submissionoutofsequencefriendlymessage', 'question', $url);
    } catch (Exception $e) {
        $url = new moodle_url('/mod/adaptivequiz/attempt.php', ['cmid' => $id]);
        $debuginfo = '';

        if (!empty($e->debuginfo)) {
            $debuginfo = $e->debuginfo;
        }

        throw new moodle_exception('errorprocessingresponses', 'question', $url, $e->getMessage(), $debuginfo);
    }
}

$adaptivequiz->context = $context;
$adaptivequiz->cm = $cm;

// If value is null then set the difficulty level to the starting level for the attempt.
if (!is_null($nextdiff)) {
    $adaptiveattempt->set_level((int) $nextdiff);
} else {
    $adaptiveattempt->set_level((int) $adaptivequiz->startinglevel);
}

// If we have a previous difficulty level, pass that off to the attempt so that it
// can modify the next-question search process based on this level.
if (isset($difflevel) && !is_null($difflevel)) {
    $adaptiveattempt->set_last_difficulty_level($difflevel);
}

// Ask for the next item. Which implementation answers depends on the activity: the built-in
// algorithm, or the CAT model configured for this instance. The host does not know the difference.
$adaptiveattempt->get_attempt();
$adaptiveattempt->initialize_quba();

$evaluation = cat_session::administer_next_item($adaptivequiz, $adaptiveattempt);

if ($evaluation === null) {
    // A concurrent request is already administering an item for this attempt. Send the user back to
    // the activity rather than risk a second slot for the same item.
    redirect(new moodle_url('/mod/adaptivequiz/view.php', ['id' => $cm->id]));
}

if ($evaluation->item_administration_is_to_stop()) {
    $message = $evaluation->stoppage_reason();

    // The standard error comes from processing the last answer, if there was one.
    $noquestionsfetchedforattempt = $uniqueid == 0;
    if ($noquestionsfetchedforattempt) {
        // The script will try to complete an 'empty' attempt as it couldn't fetch the first question for some reason.
        // This is an invalid behaviour, which could be caused by a misconfigured questions pool. Stop it here.
        throw new moodle_exception(
            'attemptnofirstquestion',
            'adaptivequiz',
            (new moodle_url('/mod/adaptivequiz/view.php', ['id' => $cm->id]))->out()
        );
    }

    adaptivequiz_complete_attempt($uniqueid, $adaptivequiz, $context, $USER->id, $standarderror, $message);
    // Redirect the user to the attemptfeedback page.
    $param = ['cmid' => $cm->id, 'id' => $cm->instance, 'uattid' => $uniqueid];
    $url = new moodle_url('/mod/adaptivequiz/attemptfinished.php', $param);
    redirect($url);
}

// The slot was resolved and written back by cat_session::administer_next_item().
$slot = $adaptiveattempt->get_question_slot_number();
// Retrieve the question_usage_by_activity object.
$quba = $adaptiveattempt->get_quba();
// If $nextdiff is null then this is either a new attempt or a continuation of an previous attempt.  Calculate the current
// difficulty level the attempt should be at.
if (is_null($nextdiff)) {
    // Calculate the current difficulty level.
    $adaptivequiz->lowestlevel = (int) $adaptivequiz->lowestlevel;
    $adaptivequiz->highestlevel = (int) $adaptivequiz->highestlevel;
    $adaptivequiz->startinglevel = (int) $adaptivequiz->startinglevel;
    // Create an instance of the catalgo class, however constructor arguments are not important.
    $algo = new catalgo($quba, 1, false, 1);
    $level = $algo->get_current_diff_level($quba, $adaptivequiz->startinglevel, $adaptivequiz);
} else {
    // Retrieve the currently set difficulty level.
    $level = $adaptiveattempt->get_level();
}

$headtags = $output->init_metadata($quba, $slot);
$PAGE->requires->js_init_call(
    'M.mod_adaptivequiz.init_attempt_form',
    [$viewurl->out(), $adaptivequiz->browsersecurity],
    false,
    $output->adaptivequiz_get_js_module()
);

// Init secure window if enabled.
if (!empty($adaptivequiz->browsersecurity)) {
    $PAGE->blocks->show_only_fake_blocks();
    $output->init_browser_security();
} else {
    $PAGE->set_heading(format_string($course->fullname));
}

echo $output->header();

// Check if the user entered a password.
$condition = adaptivequiz_user_entered_password($adaptivequiz->id);

if (!empty($adaptivequiz->password) && empty($condition)) {
    if ($passwordattempt) {
        $mform->set_data(['message' => get_string('wrongpassword', 'adaptivequiz')]);
    }

    $mform->display();
} else {
    $attemptrecord = $adaptiveattempt->get_attempt();

    if ($adaptivequiz->showattemptprogress) {
        echo $output->container_start('attempt-progress-container');
        echo $output->attempt_progress($attemptrecord->questionsattempted, $adaptivequiz->maximumquestions);
        echo $output->container_end();
    }

    echo $output->question_submit_form($id, $quba, $slot, $level, $attemptrecord->questionsattempted + 1);

    if ($adaptivequiz->debuginfoenable) {
        echo $output->container_start();
        echo $output->render(new attempt_debug_info($attemptrecord));
        echo $output->container_end();
    }
}

echo $output->print_footer();
