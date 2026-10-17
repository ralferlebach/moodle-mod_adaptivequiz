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

namespace mod_adaptivequiz\local\feedback;

use context;
use stdClass;

/**
 * Trust contract of the activity's attempt feedback (issue #21).
 *
 * The feedback is HTML written by teachers. It may hold markup and scripts - but only from an author
 * who is trusted to: one with mod/adaptivequiz:trustedfeedback (RISK_XSS) where the activity lives.
 *
 * - Saving: the server decides. A trusted author's text is stored as written and marked trusted. Any
 *   other author's text is cleaned (clean_text: markup kept, scripts and unsafe attributes removed) and
 *   marked untrusted - unless it is exactly the text already stored, which is kept with its mark, so an
 *   untrusted teacher who only changes another setting does not strip a trusted author's script.
 *   Nothing in the request can set the mark.
 * - Showing: trusted text is not cleaned again (noclean), untrusted text is (format_text's default).
 *   Moodle's "Content cleaning everywhere" (forceclean) still wins: an administrator's choice.
 * - Restoring: the mark survives only if the restoring user is trusted in the target course.
 *
 * The feedback a CAT model provides is not this text and does not pass through here.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class feedback_trust {
    /** @var string Who may author active content in the feedback. */
    public const CAPABILITY = 'mod/adaptivequiz:trustedfeedback';

    /**
     * Whether the current user may author active content here.
     *
     * @param context $context The activity's context, or the course's while it is being created.
     * @return bool
     */
    public static function is_trusted_author(context $context): bool {
        return has_capability(self::CAPABILITY, $context);
    }

    /**
     * Applies the contract to the feedback about to be saved: sets attemptfeedback and attemptfeedbacktrusted.
     *
     * @param stdClass $adaptivequiz The instance as it will be saved; attemptfeedback holds the submitted text.
     * @param context $context The activity's context, or the course's while it is being created.
     * @param stdClass|null $stored The instance as stored, null for a new one.
     * @return void
     */
    public static function apply_on_save(stdClass $adaptivequiz, context $context, ?stdClass $stored = null): void {
        $text = (string) ($adaptivequiz->attemptfeedback ?? '');
        if (self::is_trusted_author($context)) {
            $adaptivequiz->attemptfeedback = $text;
            $adaptivequiz->attemptfeedbacktrusted = 1;
            return;
        }
        if ($stored !== null && $text === (string) $stored->attemptfeedback) {
            // Unchanged: neither cleaned nor re-marked - it is not this author's text.
            $adaptivequiz->attemptfeedbacktrusted = (int) ($stored->attemptfeedbacktrusted ?? 0);
            return;
        }
        $adaptivequiz->attemptfeedback = $text === '' ? '' : clean_text($text, FORMAT_HTML);
        $adaptivequiz->attemptfeedbacktrusted = 0;
    }

    /**
     * The feedback as HTML for the page, cleaned unless its author was trusted.
     *
     * @param string $text Feedback text (placeholders and file URLs already resolved).
     * @param int $format Its format.
     * @param bool $trusted The stored mark.
     * @param context $context The activity's context, for filters.
     * @return string
     */
    public static function format(string $text, int $format, bool $trusted, context $context): string {
        return trim(format_text($text, $format, [
            'noclean' => $trusted,
            'para' => false,
            'filter' => true,
            'context' => $context,
            'overflowdiv' => true,
        ]));
    }

    /**
     * The mark of a restored activity: kept only if the restoring user is trusted in the target course.
     *
     * Backups from before the mark existed carry none; their feedback was shown without cleaning, so it
     * counts as trusted - again only for a trusted restoring user.
     *
     * @param stdClass $data The restored activity record.
     * @param context $context The target course's context.
     * @return int
     */
    public static function restored_mark(stdClass $data, context $context): int {
        if (!self::is_trusted_author($context)) {
            return 0;
        }
        return isset($data->attemptfeedbacktrusted) ? (int) !empty($data->attemptfeedbacktrusted) : 1;
    }
}
