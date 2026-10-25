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

namespace mod_adaptivequiz;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/adaptivequiz/lib.php');

use advanced_testcase;
use mod_adaptivequiz\local\attempt\attempt_state;
use PHPUnit\Framework\Attributes\CoversFunction;
use stdClass;

/**
 * The markup of the activity is Bootstrap 5 (Moodle 5.x).
 *
 * Bootstrap 5 dropped the Bootstrap 4 spacing, text and badge classes and reads data-bs-* only: an
 * old class is simply not styled any more, an old data attribute opens nothing. The templates, the
 * PHP classes and lib.php print markup, so all of them are read - templates without their comments,
 * PHP by its string literals (a comment may name the old class, a method may be called close()).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('adaptivequiz_print_recent_mod_activity')]
final class templates_bootstrap5_test extends advanced_testcase {
    /** Bootstrap 4 classes Bootstrap 5 dropped or renamed, matched against single class names. */
    private const BS4_CLASS = '/^(?:sr-only(?:-focusable)?|text-(?:sm-|md-|lg-|xl-)?(?:left|right)'
        . '|float-(?:sm-|md-|lg-|xl-)?(?:left|right)|font-weight-[a-z]+|font-italic'
        . '|badge-(?:primary|secondary|success|danger|warning|info|light|dark|pill)|close|no-gutters|media|media-body'
        . '|form-group|form-row|form-inline|input-group-(?:append|prepend)'
        . '|custom-(?:control|select|checkbox|radio|switch|file|range)[\w-]*|btn-block|jumbotron|embed-responsive[\w-]*'
        . '|dropdown-menu-(?:sm-|md-|lg-|xl-)?(?:left|right)|[mp][lr]-(?:sm-|md-|lg-|xl-)?(?:n?[0-5]|auto)'
        . '|card-deck|card-columns|pull-(?:left|right)|thead-(?:dark|light)|rounded-(?:left|right)'
        . '|border-(?:left|right)(?:-0)?)$/';

    /** The Bootstrap 4 data attributes. */
    private const BS4_ATTRIBUTE = '/(?<![\w-])data-(?:toggle|dismiss|target|parent|ride|slide|slide-to|spy|offset)(?![\w-])/';

    /** Class names that are words as well: findings only where a class is meant. */
    private const AMBIGUOUS = ['close', 'media'];

    /**
     * The Bootstrap 4 classes and attributes in a piece of text.
     *
     * @param string $text
     * @param bool $classcontext The text is a list of classes.
     * @return string[]
     */
    private static function findings(string $text, bool $classcontext): array {
        $found = [];
        if (preg_match(self::BS4_ATTRIBUTE, $text, $m)) {
            $found[] = $m[0];
        }
        foreach (preg_split('/[^\w-]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (preg_match(self::BS4_CLASS, $token) && ($classcontext || !in_array($token, self::AMBIGUOUS, true))) {
                $found[] = $token;
            }
        }
        if (!$classcontext && preg_match_all('/class\s*=\s*\\\\?["\']([^"\'\\\\]*)/', $text, $lists)) {
            foreach ($lists[1] as $list) {
                $found = array_merge($found, self::findings($list, true));
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * Bootstrap 4 markup in a file.
     *
     * @param string $path
     * @return string[] "line: finding"
     */
    private static function findings_in_file(string $path): array {
        $source = file_get_contents($path);
        $pieces = [];
        if (str_ends_with($path, '.mustache')) {
            $source = preg_replace_callback('/\{\{!.*?\}\}/s', fn($c) => str_repeat("\n", substr_count($c[0], "\n")), $source);
            foreach (explode("\n", $source) as $number => $line) {
                $pieces[] = [$number + 1, $line, false];
                preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/', $line, $lists);
                foreach ($lists[2] as $list) {
                    $pieces[] = [$number + 1, preg_replace('/\{\{[^}]*\}\}/', ' ', $list), true];
                }
            }
        } else {
            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
                    $pieces[] = [$token[2], $token[1], false];
                }
            }
            preg_match_all("/['\"]class['\"]\s*=>\s*(['\"])(.*?)\\1/", $source, $lists, PREG_OFFSET_CAPTURE);
            foreach ($lists[2] as [$list, $offset]) {
                $pieces[] = [substr_count(substr($source, 0, $offset), "\n") + 1, $list, true];
            }
        }
        $found = [];
        foreach ($pieces as [$line, $text, $classcontext]) {
            foreach (self::findings($text, $classcontext) as $finding) {
                $found[] = $line . ': ' . $finding;
            }
        }
        return array_values(array_unique($found));
    }

    /**
     * The files of the activity that print markup: templates, PHP classes, lib.php and renderer.php.
     *
     * The CAT model subplugins are not part of it - each has its own markup and its own tests.
     *
     * @return string[]
     */
    private static function markup_files(): array {
        global $CFG;
        $root = $CFG->dirroot . '/mod/adaptivequiz';
        $files = [$root . '/lib.php', $root . '/renderer.php'];
        foreach (['templates', 'classes'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['mustache', 'php'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        return array_filter($files, 'is_file');
    }

    /**
     * No Bootstrap 4 classes or data attributes in the markup of the activity.
     */
    public function test_no_bootstrap4_markup(): void {
        global $CFG;
        $root = $CFG->dirroot . '/mod/adaptivequiz/';

        $found = [];
        foreach (self::markup_files() as $file) {
            foreach (self::findings_in_file($file) as $finding) {
                $found[] = substr($file, strlen($root)) . ':' . $finding;
            }
        }
        $this->assertSame([], $found);
    }

    /**
     * The guard finds what it is there for and leaves words, comments and Bootstrap 5 alone.
     */
    public function test_guard_has_teeth(): void {
        $this->assertSame(['ml-1'], self::findings('<span class="badge bg-primary ml-1">', false));
        $this->assertSame(['text-right', 'font-weight-bold'], self::findings('col-4 text-right font-weight-bold', true));
        $this->assertSame(['data-toggle'], self::findings('<a data-toggle="tab">', false));
        $this->assertSame(['close'], self::findings('<button class="close">', false));
        $this->assertSame([], self::findings('close', false));
        $this->assertSame([], self::findings('<span class="badge text-bg-primary ms-1 me-1 text-end fw-bold">', false));

        $file = make_request_directory() . '/x.mustache';
        file_put_contents($file, "{{! The old class was mr-1. }}\n<span class=\"me-1 {{#a}}mr-1{{/a}}\">");
        $this->assertSame(['2: mr-1'], self::findings_in_file($file));
    }

    /**
     * The templates this was found in render Bootstrap 5.
     */
    public function test_templates_render_bootstrap5(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $bank = $OUTPUT->render_from_template(
            'mod_adaptivequiz/question_bank',
            ['url' => '#', 'name' => 'Bank', 'label' => 'This course']
        );
        $this->assertStringContainsString('class="badge text-bg-primary ms-1"', $bank);

        $distribution = $OUTPUT->render_from_template('mod_adaptivequiz/attempt_answers_distribution_report', [
            'showchartstacked' => true, 'userid' => 5, 'adaptivequizid' => 3,
        ]);
        $this->assertStringContainsString('<span class="me-1">', $distribution);
        $this->assertStringNotContainsString('mr-1', $distribution);

        $parameter = $OUTPUT->render_from_template('mod_adaptivequiz/item_administration_parameter', [
            'name' => 'Starting level', 'value' => 5, 'error' => true,
        ]);
        $this->assertStringContainsString('class="col-4 text-end fw-bold"', $parameter);
        $this->assertStringContainsString('text-bg-danger', $parameter);
    }

    /**
     * The link to the profile in the recent activity is a moodle_url: below a wwwroot with a path, and
     * escaped once - the string with "&amp;" in it came out as "&amp;amp;course=".
     */
    public function test_recent_activity_links_to_the_profile(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->wwwroot = 'https://example.org/sub/moodle';

        $activity = new stdClass();
        $activity->user = (object) [
            'id' => 2, 'picture' => '', 'firstname' => 'user', 'lastname' => 'phpunit', 'middlename' => '',
            'alternatename' => '', 'firstnamephonetic' => '', 'lastnamephonetic' => '', 'imagealt' => '',
            'email' => 'a@a.com',
        ];
        $activity->content = (object) ['attemptstate' => attempt_state::IN_PROGRESS, 'questionsattempted' => '12'];
        $activity->timestamp = 1234;
        $activity->type = 'mod_adaptivequiz';
        $activity->name = 'Quiz';
        $activity->cmid = 99;

        $output = adaptivequiz_print_recent_mod_activity($activity, 7, false, ['mod_adaptivequiz' => 'adaptivequiz'], true, true);

        $this->assertStringContainsString('href="https://example.org/sub/moodle/user/view.php?id=2&amp;course=7"', $output);
        $this->assertStringNotContainsString('&amp;amp;', $output);
    }
}
