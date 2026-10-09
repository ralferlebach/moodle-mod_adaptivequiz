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

namespace mod_adaptivequiz\local;

/**
 * Durations of the host's own steps in this request: waiting for the lock, asking the CAT model,
 * loading the question, the question usage.
 *
 * The host only measures; it stores nothing. A CAT model that keeps a trace of its requests can read
 * the spans with get_spans() and put them next to its own. A handful of hrtime() calls per request -
 * cheap enough to run always.
 *
 * Offsets are milliseconds since the start of the request ($_SERVER['REQUEST_TIME_FLOAT']), so they
 * share one time axis with whatever else measures the request.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_timing {
    /** @var array Recorded spans, in the order they started. */
    private static array $spans = [];

    /** @var int[] Indices of the open spans, innermost last. */
    private static array $open = [];

    /**
     * Opens a span.
     *
     * @param string $name Fixed name from the code.
     */
    public static function start(string $name): void {
        self::$spans[] = [
            'name' => $name,
            'depth' => count(self::$open),
            'offsetms' => isset($_SERVER['REQUEST_TIME_FLOAT'])
                ? round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 3)
                : null,
            'startns' => hrtime(true),
            'ms' => null,
            'meta' => [],
        ];
        self::$open[] = array_key_last(self::$spans);
    }

    /**
     * Closes the innermost open span of that name, and any span left open inside it.
     *
     * @param string $name
     * @param array $meta Numbers only, e.g. a slot count.
     */
    public static function stop(string $name, array $meta = []): void {
        $now = hrtime(true);
        for ($i = count(self::$open) - 1; $i >= 0; $i--) {
            if (self::$spans[self::$open[$i]]['name'] !== $name) {
                continue;
            }
            while (count(self::$open) > $i) {
                $index = array_pop(self::$open);
                self::$spans[$index]['ms'] = round(($now - self::$spans[$index]['startns']) / 1e6, 3);
            }
            self::$spans[$index]['meta'] = array_filter($meta, fn($v) => is_int($v) || is_float($v) || is_bool($v));
            return;
        }
    }

    /**
     * Runs a function inside a span and returns its result.
     *
     * @param string $name
     * @param callable $fn
     * @return mixed
     */
    public static function measure(string $name, callable $fn) {
        self::start($name);
        try {
            return $fn();
        } finally {
            self::stop($name);
        }
    }

    /**
     * The spans of this request: name, depth, offsetms, ms, meta.
     *
     * @return array
     */
    public static function get_spans(): array {
        return array_map(function (array $span): array {
            unset($span['startns']);
            return $span;
        }, self::$spans);
    }

    /**
     * Forgets the spans of this request.
     */
    public static function reset(): void {
        self::$spans = [];
        self::$open = [];
    }
}
