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

namespace mod_adaptivequiz\local\result\link;
/**
 * The Fisher-Rao arc length of a scale: the integral of sqrt(I(t)) (issue #25).
 *
 * Adaptive Simpson quadrature, deterministic: the same information gives the same nodes and the same value. Each
 * interval is split until the Richardson error estimate is below the tolerance; the tolerance halves with every
 * split, so the total error stays below the requested bound. An interval that would need more than MAXDEPTH
 * splits, or an integral that needs more than MAXEVALUATIONS evaluations, does not meet the bound - that is an
 * error, not an approximation that is passed on.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class arc_length {
    /** @var float Error bound of one integral, relative to its size (absolute for an integral below 1). */
    public const TOLERANCE = 1e-10;

    /** @var int Most evaluations of the information for one integral; more means the bound is not met. */
    public const MAXEVALUATIONS = 100000;

    /** @var int Maximum depth of splitting. */
    public const MAXDEPTH = 40;

    /** @var float Information below 0 by at most this much is rounding and counts as 0. */
    private const NEGATIVE_ROUNDING = 1e-12;

    /**
     * The integral of sqrt(I(t)) from a to b, a <= b.
     *
     * @param \Closure $information I(t), the test information at ability t.
     * @param float $a
     * @param float $b
     * @return float
     * @throws \RangeException When I is not finite, negative, or the bound is not met.
     */
    public static function between(\Closure $information, float $a, float $b): float {
        if ($b < $a) {
            throw new \RangeException('arc length: upper bound below lower bound');
        }
        if ($b === $a) {
            return 0.0;
        }
        $evaluations = 0;
        $f = static function (float $t) use ($information, &$evaluations): float {
            if (++$evaluations > self::MAXEVALUATIONS) {
                throw new \RangeException('arc length: error bound not met');
            }
            $value = $information($t);
            if (!is_float($value) && !is_int($value)) {
                throw new \RangeException('test information is not a number');
            }
            $value = (float) $value;
            if (!is_finite($value) || $value < -self::NEGATIVE_ROUNDING) {
                throw new \RangeException('test information not finite or negative');
            }
            return sqrt(max(0.0, $value));
        };
        $fa = $f($a);
        $fb = $f($b);
        $m = ($a + $b) / 2;
        $fm = $f($m);
        $whole = ($b - $a) / 6 * ($fa + 4 * $fm + $fb);
        $tolerance = self::TOLERANCE * max(1.0, abs($whole));
        return self::refine($f, $a, $b, $fa, $fm, $fb, $whole, $tolerance, 0);
    }

    /**
     * One step of the adaptive Simpson rule.
     *
     * @param \Closure $f
     * @param float $a
     * @param float $b
     * @param float $fa
     * @param float $fm
     * @param float $fb
     * @param float $whole Simpson estimate on [a, b].
     * @param float $tolerance
     * @param int $depth
     * @return float
     */
    private static function refine(
        \Closure $f,
        float $a,
        float $b,
        float $fa,
        float $fm,
        float $fb,
        float $whole,
        float $tolerance,
        int $depth
    ): float {
        $m = ($a + $b) / 2;
        $lm = ($a + $m) / 2;
        $rm = ($m + $b) / 2;
        $flm = $f($lm);
        $frm = $f($rm);
        $left = ($m - $a) / 6 * ($fa + 4 * $flm + $fm);
        $right = ($b - $m) / 6 * ($fm + 4 * $frm + $fb);
        $delta = $left + $right - $whole;
        // A minimum depth, so a curve that happens to fit a parabola at the first three nodes is still looked at.
        if ($depth >= 4 && abs($delta) <= 15 * $tolerance) {
            return $left + $right + $delta / 15;
        }
        if ($depth >= self::MAXDEPTH) {
            throw new \RangeException('arc length: error bound not met');
        }
        return self::refine($f, $a, $m, $fa, $flm, $fm, $left, $tolerance / 2, $depth + 1)
            + self::refine($f, $m, $b, $fm, $frm, $fb, $right, $tolerance / 2, $depth + 1);
    }
}
