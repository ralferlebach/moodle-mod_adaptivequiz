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

/**
 * The grading settings in German as in English (issue #25 AK3): grading mode, link functions, steepness, pass score,
 * result source, keeping grades, the completion rule "valid result" and the stop notices.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class lang_de_grading_test extends \basic_testcase {
    /** @var string[] The prefixes of the strings of the grading settings and the stop notices. */
    private const PREFIXES = ['gradingmode', 'keepgrades', 'link', 'passscore', 'resultsource', 'completionvalidresult',
        'stopnotice'];

    /**
     * The strings of a language file.
     *
     * @param string $lang
     * @return array
     */
    private static function strings(string $lang): array {
        global $CFG;
        $string = [];
        include($CFG->dirroot . '/mod/adaptivequiz/lang/' . $lang . '/adaptivequiz.php');
        return $string;
    }

    /**
     * Every English string of the grading settings has its German one, with the same placeholders.
     */
    public function test_every_grading_string_is_in_german(): void {
        $en = self::strings('en');
        $de = self::strings('de');
        $keys = array_filter(array_keys($en), function (string $key): bool {
            foreach (self::PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }
            return false;
        });
        $this->assertGreaterThan(30, count($keys));
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $de, "German string $key");
            preg_match_all('/\{\$a(->\w+)?\}/', $en[$key], $placeholders);
            foreach (array_unique($placeholders[0]) as $placeholder) {
                $this->assertStringContainsString($placeholder, $de[$key], "$key keeps $placeholder");
            }
        }
    }
}
