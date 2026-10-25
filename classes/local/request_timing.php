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
 * Durations of the host's own steps in this request: waiting for a lock, creating the attempt, asking
 * the CAT model, loading the question, the question usage (mod_adaptivequiz #17).
 *
 * Trace decision: always measure, never persist. The host keeps its spans in memory only - a handful
 * of hrtime() and memory_get_usage() calls per request, cheap enough to run always - and writes
 * nothing anywhere. A CAT model that keeps a trace of its requests (off by default) reads the spans
 * with get_spans() and stores them next to its own; without such a trace the spans end with the
 * request. Nothing of a question or an answer is recorded: span names are fixed strings from the
 * code, meta holds numbers and flags only.
 *
 * Contract for a CAT model (it checks with method_exists(), older hosts lack the newer methods):
 * - get_spans(): the spans - name, depth, offsetms, ms, meta.
 * - reset(): forgets the spans once they are read; the request id and attempt stay.
 * - request_id(): the correlation id of this request, also sent as X-ALiSe-Request-Id.
 * - attempt(): ['attemptid' => int, 'questionnumber' => int] once the host knows the attempt, else null.
 * - summary(): memory of the request so far (memkb, peakkb, peakrealkb) and spans, dropped.
 *
 * Offsets are milliseconds since the start of the request ($_SERVER['REQUEST_TIME_FLOAT']), so they
 * share one time axis with whatever else measures the request. Every closed span carries memkb,
 * memdeltakb, peakkb and peakgrewkb in its meta: memory at its end, the change during it, the peak of
 * the request so far and how much that peak grew during the span. The peak is never reset - that
 * would falsify the peak of anyone else measuring the request.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_timing {
    /** @var int Most spans kept per request; further ones are only counted (a loop must not grow the buffer). */
    public const MAX_SPANS = 500;

    /** @var int[] Error types that end the request - a fatal span is recorded for them. */
    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    /** @var array Recorded spans, in the order they started. */
    private static array $spans = [];

    /** @var array Open spans, innermost last: ['index' => ?int, 'name' => string]; index null when dropped. */
    private static array $open = [];

    /** @var int Spans not kept because the buffer was full. */
    private static int $dropped = 0;

    /** @var string|null Correlation id of this request, made on first use. */
    private static ?string $requestid = null;

    /** @var array|null The attempt this request works on. */
    private static ?array $attempt = null;

    /** @var bool Whether on_shutdown() is registered for this process. */
    private static bool $shutdownregistered = false;

    /** @var callable|null Test seam: stands in for error_get_last() in on_shutdown(). */
    public static $lasterror = null;

    /**
     * Opens a span.
     *
     * @param string $name Fixed name from the code.
     */
    public static function start(string $name): void {
        if (!self::$shutdownregistered) {
            self::$shutdownregistered = true;
            \core_shutdown_manager::register_function([self::class, 'on_shutdown']);
        }
        if (count(self::$spans) >= self::MAX_SPANS) {
            self::$dropped++;
            self::$open[] = ['index' => null, 'name' => $name];
            return;
        }
        self::$spans[] = [
            'name' => $name,
            'depth' => count(self::$open),
            'offsetms' => self::offsetms(),
            'startns' => hrtime(true),
            'startmem' => memory_get_usage(),
            'startpeak' => memory_get_peak_usage(),
            'ms' => null,
            'meta' => [],
        ];
        self::$open[] = ['index' => array_key_last(self::$spans), 'name' => $name];
    }

    /**
     * Closes the innermost open span of that name, and any span left open inside it.
     *
     * @param string $name
     * @param array $meta Numbers and flags only, e.g. a slot count; anything else is dropped.
     */
    public static function stop(string $name, array $meta = []): void {
        $now = hrtime(true);
        for ($i = count(self::$open) - 1; $i >= 0; $i--) {
            if (self::$open[$i]['name'] !== $name) {
                continue;
            }
            while (count(self::$open) > $i) {
                $entry = array_pop(self::$open);
                if ($entry['index'] !== null) {
                    self::close($entry['index'], $now);
                }
            }
            if ($entry['index'] !== null) {
                self::$spans[$entry['index']]['meta'] = array_filter(
                    $meta,
                    fn($v) => is_int($v) || is_float($v) || is_bool($v)
                ) + self::$spans[$entry['index']]['meta'];
            }
            return;
        }
    }

    /**
     * Ends a span: duration and memory.
     *
     * @param int $index
     * @param int $now hrtime(true) at the end.
     */
    private static function close(int $index, int $now): void {
        $span = &self::$spans[$index];
        $memory = memory_get_usage();
        $peak = memory_get_peak_usage();
        $span['ms'] = round(($now - $span['startns']) / 1e6, 3);
        $span['meta'] = [
            'memkb' => intdiv($memory, 1024),
            'memdeltakb' => (int) round(($memory - $span['startmem']) / 1024),
            'peakkb' => intdiv($peak, 1024),
            'peakgrewkb' => (int) round(max(0, $peak - $span['startpeak']) / 1024),
        ] + $span['meta'];
    }

    /**
     * Runs a function inside a span and returns its result.
     *
     * A step that throws still ends its span, marked threw; the exception goes on unchanged.
     *
     * @param string $name
     * @param callable $fn
     * @param array $meta Numbers and flags only, set on the span when it ends.
     * @return mixed
     */
    public static function measure(string $name, callable $fn, array $meta = []) {
        self::start($name);
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            self::stop($name, ['threw' => true] + $meta);
            throw $e;
        }
        self::stop($name, $meta);
        return $result;
    }

    /**
     * Like measure(), and counts the database reads of the step as meta dbreads.
     *
     * For loading a question: 0 reads means the question bank answered from its cache. Whether it did
     * is not asked directly - that would be a second cache lookup on every load - the read counter of
     * the connection says it for free.
     *
     * @param string $name
     * @param callable $fn
     * @return mixed
     */
    public static function measure_db(string $name, callable $fn) {
        global $DB;
        $before = $DB->perf_get_reads();
        $reads = fn() => ['dbreads' => $DB->perf_get_reads() - $before];
        self::start($name);
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            self::stop($name, ['threw' => true] + $reads());
            throw $e;
        }
        self::stop($name, $reads());
        return $result;
    }

    /**
     * The spans of this request: name, depth, offsetms, ms, meta.
     *
     * @return array
     */
    public static function get_spans(): array {
        return array_map(function (array $span): array {
            unset($span['startns'], $span['startmem'], $span['startpeak']);
            return $span;
        }, self::$spans);
    }

    /**
     * Memory of the request so far, and how many spans were kept and dropped.
     *
     * @return array memkb, peakkb, peakrealkb, spans, dropped.
     */
    public static function summary(): array {
        return [
            'memkb' => intdiv(memory_get_usage(), 1024),
            'peakkb' => intdiv(memory_get_peak_usage(), 1024),
            'peakrealkb' => intdiv(memory_get_peak_usage(true), 1024),
            'spans' => count(self::$spans),
            'dropped' => self::$dropped,
        ];
    }

    /**
     * The correlation id of this request.
     *
     * An id a proxy or the browser sent as X-Request-Id is taken over when it is well-formed, so the
     * request can be followed through the web server's log; otherwise a random one is made.
     *
     * @return string
     */
    public static function request_id(): string {
        if (self::$requestid === null) {
            $incoming = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
            self::$requestid = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{8,64}\z/', $incoming)
                ? $incoming
                : bin2hex(random_bytes(8));
        }
        return self::$requestid;
    }

    /**
     * Sends the correlation id back as X-ALiSe-Request-Id, unless output has already begun.
     *
     * @return bool Whether the header was sent.
     */
    public static function send_request_id_header(): bool {
        if (headers_sent()) {
            return false;
        }
        header('X-ALiSe-Request-Id: ' . self::request_id());
        return true;
    }

    /**
     * Records the attempt this request works on.
     *
     * @param int $attemptid Id of the attempt record.
     * @param int $questionnumber Number of the question this request administers, 1 for the first.
     */
    public static function set_attempt(int $attemptid, int $questionnumber): void {
        self::$attempt = ['attemptid' => $attemptid, 'questionnumber' => $questionnumber];
    }

    /**
     * The attempt this request works on, once the host knows it.
     *
     * @return array|null ['attemptid' => int, 'questionnumber' => int]
     */
    public static function attempt(): ?array {
        return self::$attempt;
    }

    /**
     * At the end of the request: a fatal error closes the spans it interrupted and leaves a fatal span.
     *
     * Registered with the shutdown manager on the first span, so it runs before anything registered
     * later - a CAT model that writes its trace at shutdown already finds the fatal span. Only the
     * type, the line and the file below the Moodle root are kept, never the message: it can carry
     * data of the request.
     */
    public static function on_shutdown(): void {
        $error = self::$lasterror !== null ? (self::$lasterror)() : error_get_last();
        if (!is_array($error) || !in_array((int) ($error['type'] ?? 0), self::FATAL_TYPES, true)) {
            return;
        }
        $now = hrtime(true);
        while (self::$open) {
            $entry = array_pop(self::$open);
            if ($entry['index'] !== null) {
                self::close($entry['index'], $now);
                self::$spans[$entry['index']]['meta']['open'] = true;
            }
        }
        // Recorded even with a full buffer: it is what the trace is read for.
        self::$spans[] = [
            'name' => 'fatal',
            'depth' => 0,
            'offsetms' => self::offsetms(),
            'ms' => 0.0,
            'meta' => [
                'type' => (int) $error['type'],
                'line' => (int) ($error['line'] ?? 0),
                'where' => self::where((string) ($error['file'] ?? '')),
            ],
        ];
    }

    /**
     * A file as a path below the Moodle root - no server paths in a trace.
     *
     * @param string $file
     * @return string
     */
    private static function where(string $file): string {
        global $CFG;
        $file = str_replace('\\', '/', $file);
        foreach ([$CFG->dirroot ?? null, isset($CFG->root) ? $CFG->root : null] as $root) {
            if (is_string($root) && $root !== '') {
                $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
                if (strpos($file, $root) === 0) {
                    return substr($file, strlen($root));
                }
            }
        }
        $pos = strpos($file, '/mod/adaptivequiz/');
        return $pos !== false ? substr($file, $pos + 1) : basename($file);
    }

    /**
     * Milliseconds since the start of the request, if known.
     *
     * @return float|null
     */
    private static function offsetms(): ?float {
        return isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 3)
            : null;
    }

    /**
     * Forgets the spans of this request; the request id and the attempt stay.
     */
    public static function reset(): void {
        self::$spans = [];
        self::$open = [];
        self::$dropped = 0;
    }

    /**
     * Forgets everything - spans, request id, attempt, test seam. For tests: one process, many requests.
     */
    public static function reset_request(): void {
        self::reset();
        self::$requestid = null;
        self::$attempt = null;
        self::$lasterror = null;
    }
}
