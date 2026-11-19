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

namespace mod_adaptivequiz\local\result;

use advanced_testcase;
use mod_adaptivequiz\local\result\link\link_function_registry;

/**
 * Normalisation of results onto 0-100 % and the pass score (issue #14).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\local\result\result_service
 * @covers \mod_adaptivequiz\local\result\result_definition
 * @covers \mod_adaptivequiz\local\result\link\link_function_registry
 */
final class result_service_test extends advanced_testcase {
    /**
     * Scores, ranges and the percentage they give.
     *
     * @return array
     */
    public static function percentages(): array {
        return [
            'negative lower bound' => [1.2, -4.0, 4.0, 65.0],
            'zero lower bound' => [50.0, 0.0, 100.0, 50.0],
            'lower bound itself' => [-4.0, -4.0, 4.0, 0.0],
            'upper bound itself' => [4.0, -4.0, 4.0, 100.0],
            'below the range is clamped' => [-7.5, -4.0, 4.0, 0.0],
            'above the range is clamped' => [9.0, -4.0, 4.0, 100.0],
            'entirely negative range' => [-3.0, -5.0, -1.0, 50.0],
        ];
    }

    /**
     * 100 * (score - lower) / (upper - lower), limited to 0-100.
     *
     * @param float $score
     * @param float $lower
     * @param float $upper
     * @param float $expected
     * @dataProvider percentages
     */
    public function test_percentage(float $score, float $lower, float $upper, float $expected): void {
        $this->assertEqualsWithDelta($expected, result_service::percentage($score, $lower, $upper, 'linear'), 1e-9);
    }

    /**
     * Ranges that cannot carry a percentage.
     *
     * @return array
     */
    public static function invalid_ranges(): array {
        return [
            'upper equals lower' => [2.0, 2.0],
            'upper below lower' => [4.0, -4.0],
            'no lower bound' => [null, 4.0],
            'no upper bound' => [-4.0, null],
            'infinite' => [-INF, 4.0],
        ];
    }

    /**
     * Upper <= lower is an error: no percentage - and no epsilon that makes one up.
     *
     * @param float|null $lower
     * @param float|null $upper
     * @dataProvider invalid_ranges
     */
    public function test_invalid_range_gives_no_percentage(?float $lower, ?float $upper): void {
        $this->assertNull(result_service::percentage(1.0, $lower, $upper, 'linear'));
        $this->assertFalse((new result_definition(true, $lower, $upper, 'x'))->is_gradable());
    }

    /**
     * No score, no percentage; a score that is not a number neither.
     */
    public function test_missing_score_gives_no_percentage(): void {
        $this->assertNull(result_service::percentage(null, -4.0, 4.0, 'linear'));
        $this->assertNull(result_service::percentage(NAN, -4.0, 4.0, 'linear'));
    }

    /**
     * An unknown link function is a configuration error, not a quiet fallback to linear.
     */
    public function test_unknown_link_function_is_an_error(): void {
        $this->expectException(result_configuration_exception::class);
        result_service::percentage(1.0, -4.0, 4.0, 'logistic');
    }

    /**
     * The pass score must lie within the range, bounds included.
     */
    public function test_passscore_must_lie_within_the_range(): void {
        $definition = new result_definition(true, -4.0, 4.0, 'x');

        $this->assertNull(result_service::passscore_error($definition, -4.0));
        $this->assertNull(result_service::passscore_error($definition, 0.0));
        $this->assertNull(result_service::passscore_error($definition, 4.0));
        $this->assertSame('passscoreoutofrange', result_service::passscore_error($definition, 4.5));
        $this->assertSame('passscoreoutofrange', result_service::passscore_error($definition, -4.01));
        $this->assertSame('passscorenorange', result_service::passscore_error(new result_definition(true, 2.0, 2.0, 'x'), 2.0));
        $this->assertSame('passscorenorange', result_service::passscore_error(result_definition::not_gradable('x'), 0.0));
    }

    /**
     * The built-in source reports lowest to highest level.
     */
    public function test_builtin_definition_is_the_level_range(): void {
        $this->resetAfterTest();

        $definition = result_service::definition((object) ['catmodel' => '', 'lowestlevel' => 1, 'highestlevel' => 11]);

        $this->assertTrue($definition->is_gradable());
        $this->assertSame(1.0, $definition->lower);
        $this->assertSame(11.0, $definition->upper);
        $this->assertSame('linear', $definition->linkfunction);
    }

    /**
     * A CAT model that is configured but not installed has no result source - and no fatal.
     */
    public function test_catmodel_not_installed_has_no_provider(): void {
        $this->resetAfterTest();

        $adaptivequiz = (object) ['catmodel' => 'notinstalled'];

        $this->assertNull(result_service::provider($adaptivequiz));
        $this->assertFalse(result_service::definition($adaptivequiz)->supportsgrading);
    }

    /**
     * The pass percentage follows the pass score; no or a misfit pass score gives none.
     */
    public function test_pass_percentage(): void {
        $this->resetAfterTest();

        $adaptivequiz = (object) ['catmodel' => '', 'lowestlevel' => 1, 'highestlevel' => 11, 'passscore' => '6'];
        $this->assertEqualsWithDelta(50.0, result_service::pass_percentage($adaptivequiz), 1e-9);

        $adaptivequiz->passscore = null;
        $this->assertNull(result_service::pass_percentage($adaptivequiz));

        $adaptivequiz->passscore = '12';
        $this->assertNull(result_service::pass_percentage($adaptivequiz), 'Outside the range.');
    }
}
