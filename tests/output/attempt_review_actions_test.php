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

use advanced_testcase;
use moodle_url;

/**
 * The way out of the attempt review page (issue #22).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempt_review_actions::class)]
final class attempt_review_actions_test extends advanced_testcase {
    /**
     * Two activities in one course, one person with an attempt in each.
     *
     * @return array [course, [cm, attempt] of the first, [cm, attempt] of the second, user]
     */
    private function fixture(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $pairs = [];
        foreach ([1, 2] as $n) {
            $activity = $this->getDataGenerator()->create_module('adaptivequiz', ['course' => $course->id]);
            $id = $DB->insert_record('adaptivequiz_attempt', (object) [
                'instance' => $activity->id, 'userid' => $user->id, 'uniqueid' => 900 + $n, 'attemptstate' => 'complete',
                'attemptstopcriteria' => '', 'questionsattempted' => 2, 'difficultysum' => 0, 'standarderror' => 0.3,
                'measure' => 0, 'timecreated' => 1, 'timemodified' => 2,
            ]);
            $pairs[] = [
                get_coursemodule_from_instance('adaptivequiz', $activity->id),
                $DB->get_record('adaptivequiz_attempt', ['id' => $id]),
            ];
        }
        return [$course, $pairs[0], $pairs[1], $user];
    }

    /**
     * Back leads to this person's attempts in this activity, the course link to the activity's course - from the
     * attempt, whatever the referring page was.
     */
    public function test_targets_come_from_the_attempt(): void {
        $this->resetAfterTest();
        [$course, [$cm1, $attempt1], [$cm2, $attempt2], $user] = $this->fixture();
        $_SERVER['HTTP_REFERER'] = 'https://elsewhere.example.org/somewhere';

        $first = attempt_review_actions::for_attempt($attempt1, $cm1, $user, 'incourse');
        $second = attempt_review_actions::for_attempt($attempt2, $cm2, $user, 'incourse');

        $this->assertTrue($first->backurl->compare(
            new moodle_url('/mod/adaptivequiz/viewattemptreport.php', ['userid' => $user->id, 'cmid' => $cm1->id]),
            URL_MATCH_PARAMS
        ));
        $this->assertTrue($second->backurl->compare(
            new moodle_url('/mod/adaptivequiz/viewattemptreport.php', ['userid' => $user->id, 'cmid' => $cm2->id]),
            URL_MATCH_PARAMS
        ), 'Each attempt back to its own activity.');
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        $this->assertTrue($first->courseurl->compare($courseurl, URL_MATCH_PARAMS));
        $this->assertStringNotContainsString('elsewhere', $first->backurl->out(false));
    }

    /**
     * Embedded (LTI) keeps both actions; a popup or a secure window only the way back.
     */
    public function test_layouts(): void {
        $this->resetAfterTest();
        [, [$cm, $attempt], , $user] = $this->fixture();

        foreach (['incourse', 'embedded', 'frametop'] as $layout) {
            $this->assertNotNull(attempt_review_actions::for_attempt($attempt, $cm, $user, $layout)->courseurl, $layout);
        }
        foreach (['popup', 'secure'] as $layout) {
            $this->assertNull(attempt_review_actions::for_attempt($attempt, $cm, $user, $layout)->courseurl, $layout);
        }
    }

    /**
     * Rendered as a named navigation region with a primary and a secondary link, each with a readable name.
     */
    public function test_markup(): void {
        global $PAGE;
        $this->resetAfterTest();
        [, [$cm, $attempt], , $user] = $this->fixture();
        $PAGE->set_url('/mod/adaptivequiz/reviewattempt.php', ['attempt' => $attempt->id]);

        $html = $PAGE->get_renderer('mod_adaptivequiz')->render(
            attempt_review_actions::for_attempt($attempt, $cm, $user, 'embedded')
        );

        $this->assertMatchesRegularExpression(
            '~<nav [^>]*aria-label="' . preg_quote(get_string('reviewactions', 'adaptivequiz'), '~') . '"~',
            $html
        );
        $this->assertMatchesRegularExpression('~<a class="btn btn-primary[^"]*" href="[^"]*viewattemptreport\.php~', $html);
        $this->assertStringContainsString(s(get_string('reviewbacktoattempts', 'adaptivequiz', fullname($user))), $html);
        $this->assertMatchesRegularExpression('~<a class="btn btn-outline-secondary[^"]*" href="[^"]*course/view\.php~', $html);
        $this->assertStringContainsString(get_string('reviewtocourse', 'adaptivequiz'), $html);
        $this->assertLessThan(strpos($html, 'btn-outline-secondary'), strpos($html, 'btn-primary'), 'Back comes first.');
    }
}
