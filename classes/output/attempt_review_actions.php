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

namespace mod_adaptivequiz\output;

use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The way out of the attempt review page: back to the person's attempts, or to the course (issue #22).
 *
 * The page has breadcrumbs, but launched through LTI or embedded it has neither header nor breadcrumbs, so the
 * actions are on the page itself. Both targets come from the attempt and its activity - never from the
 * referring page, which may be anything. In a popup or a secure window a course page has no place; there only
 * the way back is offered.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_review_actions implements renderable, templatable {
    /** @var string[] Page layouts without a course to go to. */
    public const LAYOUTS_WITHOUT_COURSE = ['popup', 'secure'];

    /**
     * Constructor.
     *
     * @param moodle_url $backurl The person's attempts in this activity.
     * @param moodle_url|null $courseurl The course, or null where it has no place.
     * @param string $fullname The person's name, for the button.
     */
    public function __construct(
        /** @var moodle_url The person's attempts in this activity. */
        public readonly moodle_url $backurl,
        /** @var moodle_url|null The course, or null where it has no place. */
        public readonly ?moodle_url $courseurl,
        /** @var string The person's name. */
        private readonly string $fullname
    ) {
    }

    /**
     * The actions for an attempt under review.
     *
     * @param stdClass $attempt adaptivequiz_attempt
     * @param stdClass $cm The course module of the attempt's activity.
     * @param stdClass $user The person whose attempt it is.
     * @param string $pagelayout The layout the page is shown in.
     * @return self
     */
    public static function for_attempt(stdClass $attempt, stdClass $cm, stdClass $user, string $pagelayout): self {
        return new self(
            new moodle_url('/mod/adaptivequiz/viewattemptreport.php', ['userid' => $attempt->userid, 'cmid' => $cm->id]),
            in_array($pagelayout, self::LAYOUTS_WITHOUT_COURSE, true)
                ? null
                : new moodle_url('/course/view.php', ['id' => $cm->course]),
            fullname($user)
        );
    }

    /**
     * Data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'backurl' => $this->backurl->out(false),
            'backlabel' => get_string('reviewbacktoattempts', 'adaptivequiz', $this->fullname),
            'courseurl' => $this->courseurl ? $this->courseurl->out(false) : null,
        ];
    }
}
