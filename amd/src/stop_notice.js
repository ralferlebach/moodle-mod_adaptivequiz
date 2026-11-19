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
 * Explains an unexpected end of the attempt before the feedback is shown (issue #20).
 *
 * The notice is on the page as text, so it is there without JavaScript too. With JavaScript the
 * feedback stays hidden - for sighted users and screen readers alike - until the participant
 * confirms the notice in a dialog; the confirmation is stored, so the dialog does not come back.
 *
 * @module     mod_adaptivequiz/stop_notice
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalAlert from 'core/local/modal/alert';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';

/**
 * Hides or shows the feedback for everyone.
 *
 * @param {HTMLElement} feedback
 * @param {boolean} hidden
 */
const setHidden = (feedback, hidden) => {
    feedback.hidden = hidden;
    feedback.inert = hidden;
    if (hidden) {
        feedback.setAttribute('aria-hidden', 'true');
    } else {
        feedback.removeAttribute('aria-hidden');
    }
};

/**
 * Shows the notice in a dialog; the feedback follows its confirmation.
 *
 * @param {number} attemptid
 */
export const init = async(attemptid) => {
    const notice = document.querySelector('[data-region="adaptivequiz-stopnotice"]');
    const feedback = document.querySelector('[data-region="adaptivequiz-attempt-feedback"]');
    if (!notice || !feedback) {
        return;
    }

    setHidden(feedback, true);
    // The dialog says it; the copy on the page would be read twice.
    setHidden(notice, true);

    let released = false;
    const release = () => {
        if (released) {
            return;
        }
        released = true;
        setHidden(notice, false);
        setHidden(feedback, false);
        notice.focus();
        Ajax.call([{
            methodname: 'mod_adaptivequiz_acknowledge_stop_notice',
            args: {attemptid: attemptid},
        }])[0].catch(Notification.exception);
    };

    try {
        const modal = await ModalAlert.create({
            title: notice.querySelector('[data-region="title"]').textContent,
            body: notice.querySelector('[data-region="text"]').outerHTML,
            removeOnClose: true,
        });
        // The dialog is named by its title; it is described by the reason, so a screen reader reads both.
        // The root of a Moodle modal is itself the element with role="dialog" (core/modal template); a search
        // below it found nothing, and the description was never set.
        const root = modal.getRoot()[0];
        const dialog = root.matches('[role="dialog"]') ? root : root.querySelector('[role="dialog"]');
        const reason = modal.getBody()[0].querySelector('[data-region="text"]');
        if (dialog && reason) {
            reason.id = 'adaptivequiz-stopnotice-dialog-text';
            dialog.setAttribute('aria-describedby', reason.id);
        }
        modal.getRoot().on(ModalEvents.hidden, release);
        modal.show();
    } catch (error) {
        // Without the dialog the feedback must not stay hidden.
        release();
        Notification.exception(error);
    }
};
