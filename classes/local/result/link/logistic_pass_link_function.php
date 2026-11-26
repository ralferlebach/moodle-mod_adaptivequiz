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
 * Logistic with 50 % at the pass score (issue #25): p = 100 / (1 + exp(-k (score - P))).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class logistic_pass_link_function implements link_function {
    /** @var string Identifier of this function. */
    public const ID = 'logistic_pass';

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
        return [link_parameters::PASS];
    }

    /**
     * The share of the range the score reaches, in percent, before clamping.
     *
     * @param float $score
     * @param link_parameters $parameters
     * @return float|null
     */
    public function percentage(float $score, link_parameters $parameters): ?float {
        if ($parameters->pass === null) {
            return null;
        }
        return logistic::percent($parameters->steepness * ($score - $parameters->pass));
    }
}
