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
 * @package   mod_adaptivequiz
 * @copyright  2013 onwards Remote-Learner {@link http://www.remote-learner.ca/}
 * @copyright  2022 onwards Vitaly Potenko <potenkov@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/adaptivequiz/locallib.php');
require_once($CFG->dirroot . '/tag/lib.php');

use mod_adaptivequiz\cat_session;
use mod_adaptivequiz\local\attempt;
use mod_adaptivequiz\local\request_timing;

$id = required_param('cmid', PARAM_INT); // Course module id.
$uniqueid  = optional_param('uniqueid', 0, PARAM_INT);  // Unique id of the attempt.

if (!$cm = get_coursemodule_from_id('adaptivequiz', $id)) {
    throw new moodle_exception('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    throw new moodle_exception('coursemisconf');
}

global $USER, $DB, $SESSION;

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
$passwordattempt = false;

try {
    $adaptivequiz  = $DB->get_record('adaptivequiz', array('id' => $cm->instance), '*', MUST_EXIST);
} catch (dml_exception $e) {
    $url = new moodle_url('/mod/adaptivequiz/attempt.php', array('cmid' => $id));
    $debuginfo = '';

    if (!empty($e->debuginfo)) {
        $debuginfo = $e->debuginfo;
    }

    throw new moodle_exception('invalidmodule', 'error', $url, $e->getMessage(), $debuginfo);
}

// Setup page global for standard viewing.
$viewurl = new moodle_url('/mod/adaptivequiz/view.php', array('id' => $cm->id));
$PAGE->set_url('/mod/adaptivequiz/view.php', array('id' => $cm->id));
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
        $SESSION->passwordcheckedadpq = array();

        if (0 == strcmp($data->quizpassword, $adaptivequiz->password)) {
            $SESSION->passwordcheckedadpq[$adaptivequiz->id] = true;
        } else {
            $SESSION->passwordcheckedadpq[$adaptivequiz->id] = false;
            $passwordattempt = true;
        }
    }
}

// Issue #5: without the password nothing is started - no attempt, no CAT record, no question, no clock.
if (!empty($adaptivequiz->password) && empty(adaptivequiz_user_entered_password($adaptivequiz->id))) {
    if ($passwordattempt) {
        $mform->set_data(['message' => get_string('wrongpassword', 'adaptivequiz')]);
    }
    $PAGE->set_heading(format_string($course->fullname));
    echo $output->header();
    $mform->display();
    echo $output->print_footer();
    exit;
}

// Issue #5: what the person chose for an open attempt on the activity page. Each action names its attempt and
// is done once; the redirect afterwards keeps a reload from sending it again.
$resumeaction = optional_param('resume', '', PARAM_ALPHA);
if ($resumeaction !== '') {
    require_sesskey();
    $resumeattemptid = required_param('attemptid', PARAM_INT);
    if ($resumeaction === 'restart' && adaptivequiz_allowed_attempt($adaptivequiz->attempts, $count + 1)) {
        \mod_adaptivequiz\local\attempt\resume_actions::restart($adaptivequiz, $context, (int) $USER->id, $resumeattemptid);
    } else if ($resumeaction === 'newquestion') {
        \mod_adaptivequiz\local\attempt\resume_actions::new_question($adaptivequiz, (int) $USER->id, $resumeattemptid);
    }
    redirect(new moodle_url('/mod/adaptivequiz/attempt.php', ['cmid' => $cm->id]));
}

// Create an instance of the adaptiveattempt class.
$adaptiveattempt = new attempt($adaptivequiz, $USER->id);
// The correlation id of this request, before any output or redirect: the browser, a proxy log and a CAT
// model's trace can be matched on it (mod_adaptivequiz #17).
request_timing::send_request_id_header();
$standarderror = 0.0;
$message = '';

// If uniqueid is not empty the process respones.
if (!empty($uniqueid) && confirm_sesskey()) {
    // Process student's responses.
    try {
        $qubahelper = function (question_usage_by_activity $quba): void {
            $time = time();
            $quba->process_all_actions($time);
            $quba->finish_all_questions($time);
        };
        $processed = request_timing::measure(
            'process_response',
            fn() => cat_session::process_administered_item_result($uniqueid, $adaptivequiz, $adaptiveattempt, $qubahelper, $context)
        );
    } catch (question_out_of_sequence_exception $e) {
        $url = new moodle_url('/mod/adaptivequiz/attempt.php', array('cmid' => $id));
        throw new moodle_exception('submissionoutofsequencefriendlymessage', 'question', $url);

    } catch (Exception $e) {
        $url = new moodle_url('/mod/adaptivequiz/attempt.php', array('cmid' => $id));
        $debuginfo = '';
        if (!empty($e->debuginfo)) {
            $debuginfo = $e->debuginfo;
        }

        throw new moodle_exception('errorprocessingresponses', 'question', $url, $e->getMessage(), $debuginfo);
    }
    // A second POST of an answer already processed (audit 18.10.): nothing was changed. If that answer ended
    // the attempt, its result is shown - the item administration below would start a new attempt.
    if (!$processed) {
        $running = ['uniqueid' => $uniqueid, 'attemptstate' => \mod_adaptivequiz\local\attempt\attempt_state::IN_PROGRESS];
        if (!$DB->record_exists('adaptivequiz_attempt', $running)) {
            $param = ['cmid' => $cm->id, 'id' => $cm->instance, 'uattid' => $uniqueid];
            redirect(new moodle_url('/mod/adaptivequiz/attemptfinished.php', $param));
        }
    }
}

try {
    request_timing::measure(
        'administer_item',
        fn() => cat_session::run_item_administration($uniqueid, $adaptivequiz, $context, $adaptiveattempt)
    );
} catch (\mod_adaptivequiz\local\catmodel\catmodel_session_exception $e) {
    // The CAT model cannot go on with this attempt and says why (CAT model, upstream issue #96). Back to the activity
    // with that message; the attempt is left as it is - completing it would need the same state again.
    redirect(
        new moodle_url('/mod/adaptivequiz/view.php', ['id' => $cm->id]),
        $e->get_user_message(),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}

$attemptcompleted = !empty($adaptiveattempt->get_status());
if ($attemptcompleted) {
    redirect(new moodle_url('/mod/adaptivequiz/attemptfinished.php',
        ['cmid' => $cm->id, 'id' => $cm->instance, 'uattid' => $uniqueid]));
}

$quba = $adaptiveattempt->get_quba();
$slot = $adaptiveattempt->get_question_slot_number();

$headtags = $output->init_metadata($quba, $slot);
$PAGE->requires->js_init_call('M.mod_adaptivequiz.init_attempt_form', array($viewurl->out(), $adaptivequiz->browsersecurity),
    false, $output->adaptivequiz_get_js_module());

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
        $mform->set_data(array('message' => get_string('wrongpassword', 'adaptivequiz')));
    }

    $mform->display();
} else {
    $attemptrecord = $adaptiveattempt->get_attempt();

    if ($adaptivequiz->showattemptprogress) {
        echo $output->container_start('attempt-progress-container');
        // The progress bar names the question being answered, not the number already
        // finished. The line below hands the submit form questionsattempted + 1 for
        // exactly that reason; without the same offset here the bar is one behind and
        // shows "question 0" on the first screen.
        //
        // Corrected here rather than in attempt_progress::__construct(): the class
        // reconstructs itself in without_progress_bar(), so an offset in the
        // constructor would be applied a second time on that path.
        echo $output->attempt_progress($attemptrecord->questionsattempted + 1, $adaptivequiz->maximumquestions);
        echo $output->container_end();
    }

    // The question as it is put on the page, measured on its own (mod_adaptivequiz #17).
    echo request_timing::measure(
        'render_question',
        fn() => $output->question_submit_form($id, $quba, $slot, $attemptrecord->questionsattempted + 1)
    );
}

echo $output->print_footer();
