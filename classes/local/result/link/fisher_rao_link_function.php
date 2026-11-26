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
 * Normalised Fisher-Rao arc length (issue #25): p = 100 * A(L, score) / A(L, U), A(a, b) = integral of sqrt(I_B(t))
 * from a to b, with I_B the test information of the calibrated item battery as it is now.
 *
 * The arc length is a monotone transformation of the scale that stretches it where the items measure precisely. It
 * is no probability of passing. Nothing is substituted: an unusable test information (missing, negative, not
 * finite), a zero denominator or a quadrature that does not reach its error bound give no value.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fisher_rao_link_function implements link_function {
    /** @var string Identifier of this function. */
    public const ID = 'fisher_rao';

    /** @var int Version of the mapping. */
    public const VERSION = 1;

    /**
     * The identifier.
     *
     * @return string
     */
    public function get_id(): string {
        return self::ID;
    }

    /**
     * The version of the mapping.
     *
     * @return int
     */
    public function get_version(): int {
        return self::VERSION;
    }

    /**
     * The parameters this function cannot do without.
     *
     * @return string[]
     */
    public function required_parameters(): array {
        return [link_parameters::INFORMATION];
    }

    /**
     * The share of the range the score reaches, in percent, before clamping.
     *
     * @param float $score
     * @param link_parameters $parameters
     * @return float|null
     */
    public function percentage(float $score, link_parameters $parameters): ?float {
        if ($parameters->information === null) {
            return null;
        }
        $score = max($parameters->lower, min($parameters->upper, $score));
        try {
            $below = arc_length::between($parameters->information, $parameters->lower, $score);
            $above = arc_length::between($parameters->information, $score, $parameters->upper);
        } catch (\RangeException $e) {
            return null;
        }
        $total = $below + $above;
        if (!is_finite($total) || $total <= 0.0) {
            return null;
        }
        return 100.0 * $below / $total;
    }
}
