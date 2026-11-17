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

use mod_adaptivequiz\local\result\link\arc_length;
use mod_adaptivequiz\local\result\link\link_function_registry;
use mod_adaptivequiz\local\result\link\link_parameters;

/**
 * The six link functions of the host (issue #25): values, boundaries, extremes, Fisher-Rao against analytic curves.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(link_function_registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(arc_length::class)]
final class link_functions_test extends \advanced_testcase {
    /** @var float */
    private const L = -4.0;

    /** @var float */
    private const U = 4.0;

    /**
     * The percentage of a function.
     *
     * @param string $id
     * @param float $score
     * @param float|null $pass
     * @param float $k
     * @param \Closure|null $info
     * @return float|null
     */
    private static function p(string $id, float $score, ?float $pass = null, float $k = 1.0, ?\Closure $info = null): ?float {
        return result_service::percentage($score, self::L, self::U, $id, new link_parameters(self::L, self::U, $pass, $k, $info));
    }

    /**
     * Function, score, pass, k, expected percent (null: not computable).
     *
     * @return array
     */
    public static function values(): array {
        return [
            'linear at L' => ['linear', -4.0, null, 1.0, 0.0],
            'linear at U' => ['linear', 4.0, null, 1.0, 100.0],
            'linear in the middle' => ['linear', 0.0, null, 1.0, 50.0],
            'linear clamped below' => ['linear', -9.0, null, 1.0, 0.0],
            'piecewise at P' => ['piecewise_pass50', 1.0, 1.0, 1.0, 50.0],
            'piecewise at L' => ['piecewise_pass50', -4.0, 1.0, 1.0, 0.0],
            'piecewise at U' => ['piecewise_pass50', 4.0, 1.0, 1.0, 100.0],
            'piecewise half below P' => ['piecewise_pass50', -1.5, 1.0, 1.0, 25.0],
            'piecewise half above P' => ['piecewise_pass50', 2.5, 1.0, 1.0, 75.0],
            'piecewise without P' => ['piecewise_pass50', 0.0, null, 1.0, null],
            'piecewise with P at L' => ['piecewise_pass50', 0.0, -4.0, 1.0, null],
            'piecewise with P at U' => ['piecewise_pass50', 0.0, 4.0, 1.0, null],
            'logistic middle at M' => ['logistic_mid', 0.0, null, 1.0, 50.0],
            'logistic middle, k = 2, one above M' => ['logistic_mid', 1.0, null, 2.0, 100 / (1 + exp(-2))],
            'logistic pass at P' => ['logistic_pass', 1.5, 1.5, 1.0, 50.0],
            'logistic pass, one below P' => ['logistic_pass', 0.5, 1.5, 1.0, 100 / (1 + exp(1))],
            'logistic pass without P' => ['logistic_pass', 0.5, null, 1.0, null],
            'guttman at P' => ['guttman_pass', 1.0, 1.0, 1.0, 100.0],
            'guttman just below P' => ['guttman_pass', 0.9999, 1.0, 1.0, 0.0],
            'guttman above P' => ['guttman_pass', 3.0, 1.0, 1.0, 100.0],
            'guttman without P' => ['guttman_pass', 3.0, null, 1.0, null],
        ];
    }

    /**
     * The values of the table.
     *
     * @param string $id
     * @param float $score
     * @param float|null $pass
     * @param float $k
     * @param float|null $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('values')]
    public function test_values(string $id, float $score, ?float $pass, float $k, ?float $expected): void {
        $actual = self::p($id, $score, $pass, $k);
        if ($expected === null) {
            $this->assertNull($actual);
        } else {
            $this->assertEqualsWithDelta($expected, $actual, 1e-9);
        }
    }

    /**
     * The logistic functions stay finite and within 0-100 for any steepness and score; they do not reach 0 or 100 at
     * the ends of the range for a moderate k.
     */
    public function test_logistic_extremes(): void {
        foreach ([1e-6, 1.0, 50.0, 1e6] as $k) {
            foreach ([-800.0, -4.0, 0.0, 4.0, 800.0] as $score) {
                foreach (['logistic_mid', 'logistic_pass'] as $id) {
                    $p = self::p($id, $score, 0.0, $k);
                    $this->assertTrue(is_finite($p) && $p >= 0.0 && $p <= 100.0, "$id k=$k score=$score");
                }
            }
        }
        $this->assertGreaterThan(0.0, self::p('logistic_mid', -4.0));
        $this->assertLessThan(100.0, self::p('logistic_mid', 4.0));
    }

    /**
     * Every function is monotone in the score.
     */
    public function test_monotone(): void {
        $info = fn(float $t): float => exp(-($t - 1) ** 2);
        foreach (link_function_registry::host_functions() as $function) {
            $previous = -INF;
            for ($t = -4.0; $t <= 4.0; $t += 0.05) {
                $p = self::p($function->get_id(), $t, 0.5, 1.5, $info);
                $this->assertGreaterThanOrEqual($previous - 1e-9, $p, $function->get_id() . " at $t");
                $previous = $p;
            }
        }
    }

    /**
     * Fisher-Rao against curves with a closed form.
     *
     * @return array information, score, expected percent
     */
    public static function fisher_rao_cases(): array {
        return [
            // Constant information: the arc length is the distance - the linear function.
            'constant' => [fn(float $t): float => 2.25, 1.0, 62.5],
            // I(t) = (t - L)^2: sqrt is t - L, the arc length (t - L)^2 / 2: p = ((t - L) / (U - L))^2.
            'quadratic' => [fn(float $t): float => ($t + 4.0) ** 2, 0.0, 25.0],
            // I(t) = exp(t): sqrt is exp(t / 2): p = (exp(t/2) - exp(L/2)) / (exp(U/2) - exp(L/2)).
            'exponential' => [fn(float $t): float => exp($t), 1.0, 100 * (exp(0.5) - exp(-2)) / (exp(2) - exp(-2))],
            // Plateau: sqrt(I) = 1 / (1 + exp(8t)), almost no information above 0. Antiderivative t - ln(1 + exp(8t)) / 8.
            'plateau above 0' => [
                fn(float $t): float => (1 / (1 + exp(8 * $t))) ** 2,
                2.0,
                100 * ((2 - log(1 + exp(16)) / 8) - (-4 - log(1 + exp(-32)) / 8))
                    / ((4 - log(1 + exp(32)) / 8) - (-4 - log(1 + exp(-32)) / 8)),
            ],
        ];
    }

    /**
     * Fisher-Rao values.
     *
     * @param \Closure $info
     * @param float $score
     * @param float $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fisher_rao_cases')]
    public function test_fisher_rao_closed_forms(\Closure $info, float $score, float $expected): void {
        $this->assertEqualsWithDelta($expected, self::p('fisher_rao', $score, null, 1.0, $info), 1e-6);
    }

    /**
     * A step in the information cannot be integrated to the error bound: no grade, not an approximation.
     */
    public function test_fisher_rao_refuses_a_step(): void {
        $this->assertNull(self::p('fisher_rao', 2.0, null, 1.0, fn(float $t): float => $t < 0.1 ? 1.0 : 0.0));
    }

    /**
     * The test information of a Rasch battery: Fisher-Rao matches a fine fixed-step Simpson rule, ends at 0 and 100,
     * and is 50 % at the centre of a battery symmetric around 0.
     */
    public function test_fisher_rao_rasch_battery(): void {
        $difficulties = [-2.0, -1.0, -0.5, 0.0, 0.5, 1.0, 2.0];
        $info = function (float $t) use ($difficulties): float {
            $sum = 0.0;
            foreach ($difficulties as $b) {
                $p = 1 / (1 + exp(-($t - $b)));
                $sum += $p * (1 - $p);
            }
            return $sum;
        };
        $simpson = function (float $a, float $b) use ($info): float {
            $n = 20000;
            $h = ($b - $a) / $n;
            $sum = sqrt($info($a)) + sqrt($info($b));
            for ($i = 1; $i < $n; $i++) {
                $sum += ($i % 2 ? 4 : 2) * sqrt($info($a + $i * $h));
            }
            return $sum * $h / 3;
        };
        foreach ([-3.0, -1.2, 0.7, 2.9] as $score) {
            $expected = 100 * $simpson(self::L, $score) / $simpson(self::L, self::U);
            $this->assertEqualsWithDelta($expected, self::p('fisher_rao', $score, null, 1.0, \Closure::fromCallable($info)), 1e-6);
        }
        $this->assertEqualsWithDelta(50.0, self::p('fisher_rao', 0.0, null, 1.0, \Closure::fromCallable($info)), 1e-7);
        $this->assertEqualsWithDelta(0.0, self::p('fisher_rao', self::L, null, 1.0, \Closure::fromCallable($info)), 1e-12);
        $this->assertEqualsWithDelta(100.0, self::p('fisher_rao', self::U, null, 1.0, \Closure::fromCallable($info)), 1e-12);
    }

    /**
     * No grade where Fisher-Rao cannot be computed: no information, none at all, negative, not finite.
     *
     * @return array
     */
    public static function fisher_rao_failures(): array {
        return [
            'no information source' => [null],
            'zero everywhere' => [fn(float $t): float => 0.0],
            'negative' => [fn(float $t): float => -1.0],
            'not finite' => [fn(float $t): float => NAN],
            'infinite' => [fn(float $t): float => INF],
        ];
    }

    /**
     * Fisher-Rao gives no value, never 0 or a linear substitute.
     *
     * @param \Closure|null $info
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fisher_rao_failures')]
    public function test_fisher_rao_not_computable(?\Closure $info): void {
        $this->assertNull(self::p('fisher_rao', 1.0, null, 1.0, $info));
    }

    /**
     * The registry offers all six host functions, version 1, and each says which parameters it needs.
     */
    public function test_registry(): void {
        $this->resetAfterTest();
        $ids = array_map(fn($f) => $f->get_id(), link_function_registry::host_functions());
        $this->assertSame(['linear', 'piecewise_pass50', 'logistic_mid', 'logistic_pass', 'guttman_pass', 'fisher_rao'], $ids);
        foreach ($ids as $id) {
            $this->assertSame($id, link_function_registry::get($id)->get_id());
            $this->assertSame(1, link_function_registry::get($id)->get_version());
        }
        $this->assertSame([link_parameters::PASS], link_function_registry::get('piecewise_pass50')->required_parameters());
        $this->assertSame([link_parameters::INFORMATION], link_function_registry::get('fisher_rao')->required_parameters());
        $this->assertSame([], link_function_registry::get('logistic_mid')->required_parameters());
    }
}
