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
 * What processing the answer to an administered item produced.
 *
 * Returned instead of leaving the values in local variables of attempt.php: the caller needs the
 * next difficulty level, the standard error and the reason to stop, and a value object says which
 * of them are actually defined.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class item_result_processing {
    /** @var int|null Difficulty level of the question that was answered, null when none was. */
    public ?int $answereddifficulty = null;

    /** @var int|null Difficulty level to administer next, null when the algorithm named none. */
    public ?int $nextdifficulty = null;

    /** @var float Standard error after the answer. */
    public float $standarderror = 0.0;

    /** @var string Why the attempt is to stop, empty when it continues. */
    public string $stoppagereason = '';

    /** @var string|null Why, as a code (issue #20); see \mod_adaptivequiz\local\attempt\stop_reason. */
    public ?string $stopcode = null;

    /**
     * @var bool Whether this request processed the answer. False when another request already had: a
     * second POST of the same form - a double click, a second tab - changes nothing and goes on to the
     * question the attempt is at (audit 18.10.).
     */
    public bool $processed = true;

    /** @var bool Whether this request completed the attempt the answer ended, under the item lock (audit 18.10.). */
    public bool $completed = false;

    /**
     * Returns whether the attempt is to stop after this answer.
     *
     * @return bool
     */
    public function attempt_is_to_stop(): bool {
        return $this->stoppagereason !== '';
    }
}
