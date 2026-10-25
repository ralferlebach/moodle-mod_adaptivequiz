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
 * Durations of the host's steps in one request.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_adaptivequiz\local\request_timing
 */
final class request_timing_test extends \basic_testcase {
    /** @var mixed The incoming X-Request-Id before the test. */
    private $incomingid;

    /**
     * Starts and ends every test with no spans.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->incomingid = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        request_timing::reset_request();
    }

    /**
     * Leaves no spans for the next test.
     */
    protected function tearDown(): void {
        if ($this->incomingid === null) {
            unset($_SERVER['HTTP_X_REQUEST_ID']);
        } else {
            $_SERVER['HTTP_X_REQUEST_ID'] = $this->incomingid;
        }
        request_timing::reset_request();
        parent::tearDown();
    }

    /**
     * Nested spans keep their depth; the outer one lasts at least as long as the inner one.
     */
    public function test_nested_spans(): void {
        $value = request_timing::measure('administer_item', function () {
            request_timing::start('lock_wait');
            request_timing::stop('lock_wait', ['acquired' => true, 'who' => 'text is dropped']);
            return 7;
        });

        $this->assertSame(7, $value);
        $spans = array_column(request_timing::get_spans(), null, 'name');
        $this->assertSame(0, $spans['administer_item']['depth']);
        $this->assertSame(1, $spans['lock_wait']['depth']);
        $this->assertGreaterThanOrEqual($spans['lock_wait']['ms'], $spans['administer_item']['ms']);
        $this->assertTrue($spans['lock_wait']['meta']['acquired']);
        $this->assertArrayNotHasKey('who', $spans['lock_wait']['meta'], 'Text never reaches a span.');
        foreach (['startns', 'startmem', 'startpeak'] as $internal) {
            $this->assertArrayNotHasKey($internal, $spans['lock_wait']);
        }
    }

    /**
     * A step that throws still ends its span, marked threw, and the exception goes on unchanged.
     */
    public function test_span_ends_when_the_step_throws(): void {
        $thrown = new \coding_exception('no item');
        try {
            request_timing::measure('select_item', function () use ($thrown) {
                throw $thrown;
            });
            $this->fail('The exception was swallowed.');
        } catch (\coding_exception $e) {
            $this->assertSame($thrown, $e);
        }

        $span = request_timing::get_spans()[0];
        $this->assertNotNull($span['ms']);
        $this->assertTrue($span['meta']['threw']);
    }

    /**
     * A step that does not throw carries no threw flag; meta given to measure() reaches the span.
     */
    public function test_measure_passes_meta(): void {
        request_timing::measure('select_item', fn() => null, ['retry' => 2]);

        $meta = request_timing::get_spans()[0]['meta'];
        $this->assertSame(2, $meta['retry']);
        $this->assertArrayNotHasKey('threw', $meta);
    }

    /**
     * Stopping a span ends the spans left open inside it.
     */
    public function test_stop_closes_inner_spans(): void {
        request_timing::start('question_usage');
        request_timing::start('forgotten');
        request_timing::stop('question_usage');

        foreach (request_timing::get_spans() as $span) {
            $this->assertNotNull($span['ms'], $span['name']);
        }
    }

    /**
     * Every span carries the memory at its end, its change, the peak and how much the peak grew.
     */
    public function test_memory_per_span(): void {
        $kept = null;
        request_timing::measure('quba_load', function () use (&$kept) {
            $kept = str_repeat('x', 2 * 1024 * 1024);
        });

        $meta = request_timing::get_spans()[0]['meta'];
        foreach (['memkb', 'memdeltakb', 'peakkb', 'peakgrewkb'] as $key) {
            $this->assertIsInt($meta[$key], $key);
        }
        $this->assertGreaterThanOrEqual(1900, $meta['memdeltakb'], 'Two megabytes were allocated in the span.');
        $this->assertGreaterThanOrEqual($meta['memkb'], $meta['peakkb']);
        $this->assertGreaterThanOrEqual(0, $meta['peakgrewkb']);
        $this->assertSame(2 * 1024 * 1024, strlen($kept));
    }

    /**
     * The summary names the memory of the request and the spans kept and dropped.
     */
    public function test_summary(): void {
        request_timing::measure('a', fn() => null);

        $summary = request_timing::summary();
        foreach (['memkb', 'peakkb', 'peakrealkb', 'spans', 'dropped'] as $key) {
            $this->assertIsInt($summary[$key], $key);
        }
        $this->assertSame(1, $summary['spans']);
        $this->assertSame(0, $summary['dropped']);
        $this->assertGreaterThanOrEqual($summary['memkb'], $summary['peakkb']);
    }

    /**
     * A full buffer keeps the first spans, counts the rest, and a dropped span closes nothing else.
     */
    public function test_buffer_is_limited(): void {
        for ($i = 0; $i < request_timing::MAX_SPANS - 1; $i++) {
            request_timing::measure('filler', fn() => null);
        }
        request_timing::start('outer');
        request_timing::start('outer');
        request_timing::stop('outer');
        $last = request_timing::get_spans()[request_timing::MAX_SPANS - 1];
        $this->assertSame('outer', $last['name']);
        $this->assertNull($last['ms'], 'Stopping the dropped inner span closed the kept outer one.');
        request_timing::stop('outer');
        for ($i = 0; $i < 20; $i++) {
            request_timing::measure('more', fn() => null);
        }

        $this->assertCount(request_timing::MAX_SPANS, request_timing::get_spans());
        $this->assertNotNull(request_timing::get_spans()[request_timing::MAX_SPANS - 1]['ms']);
        $this->assertSame(21, request_timing::summary()['dropped']);
    }

    /**
     * The database reads of a step are counted.
     */
    public function test_measure_db_counts_reads(): void {
        global $DB;
        request_timing::measure_db('load_question', fn() => $DB->get_record('user', ['id' => 1]));
        request_timing::measure_db('nothing', fn() => null);

        $spans = array_column(request_timing::get_spans(), null, 'name');
        $this->assertGreaterThanOrEqual(1, $spans['load_question']['meta']['dbreads']);
        $this->assertSame(0, $spans['nothing']['meta']['dbreads']);
    }

    /**
     * A well-formed incoming X-Request-Id is taken over and stays for the request.
     */
    public function test_request_id_takes_over_a_valid_header(): void {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'Proxy-0123-abcd';

        $this->assertSame('Proxy-0123-abcd', request_timing::request_id());
        request_timing::reset();
        $this->assertSame('Proxy-0123-abcd', request_timing::request_id(), 'Reading the spans keeps the id.');
    }

    /**
     * An ill-formed incoming id is dropped for a random one.
     *
     * @dataProvider invalid_ids
     * @param string $incoming
     */
    public function test_request_id_drops_an_invalid_header(string $incoming): void {
        $_SERVER['HTTP_X_REQUEST_ID'] = $incoming;

        $id = request_timing::request_id();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        $this->assertSame($id, request_timing::request_id(), 'One id per request.');
        request_timing::reset_request();
        $this->assertNotSame($id, request_timing::request_id(), 'The next request has its own.');
    }

    /**
     * Incoming ids that are not taken over.
     *
     * @return array
     */
    public static function invalid_ids(): array {
        return [
            'too short' => ['abc-123'],
            'too long' => [str_repeat('a', 65)],
            'space' => ['abcd efgh'],
            'header injection' => ["abcdefgh\r\nSet-Cookie: x=1"],
            'trailing newline' => ["abcdefgh\n"],
            'other characters' => ['abcd_efgh;'],
        ];
    }

    /**
     * Without an incoming id one is made.
     */
    public function test_request_id_without_header(): void {
        unset($_SERVER['HTTP_X_REQUEST_ID']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', request_timing::request_id());
    }

    /**
     * The attempt of the request is known once the host sets it.
     */
    public function test_attempt(): void {
        $this->assertNull(request_timing::attempt());
        request_timing::set_attempt(42, 3);
        request_timing::reset();

        $this->assertSame(['attemptid' => 42, 'questionnumber' => 3], request_timing::attempt());
    }

    /**
     * The first span registers the shutdown handler.
     */
    public function test_shutdown_handler_is_registered(): void {
        request_timing::start('a');
        request_timing::stop('a');

        $callbacks = (new \ReflectionProperty(\core_shutdown_manager::class, 'callbacks'))->getValue();
        $this->assertContains(
            [request_timing::class, 'on_shutdown'],
            array_map(fn($entry) => $entry[0], $callbacks)
        );
    }

    /**
     * A fatal error closes the open spans and leaves a fatal span - type, line, file, never the message.
     */
    public function test_fatal_error_at_shutdown(): void {
        global $CFG;
        request_timing::start('administer_item');
        request_timing::start('select_item');
        request_timing::$lasterror = fn() => [
            'type' => E_ERROR,
            'message' => 'Allowed memory exhausted while reading the secret answer',
            'file' => $CFG->dirroot . '/mod/adaptivequiz/classes/cat_session.php',
            'line' => 123,
        ];

        request_timing::on_shutdown();

        $spans = request_timing::get_spans();
        $this->assertCount(3, $spans);
        foreach ([0, 1] as $i) {
            $this->assertNotNull($spans[$i]['ms'], $spans[$i]['name']);
            $this->assertTrue($spans[$i]['meta']['open'], $spans[$i]['name']);
        }
        $this->assertSame('fatal', $spans[2]['name']);
        $this->assertSame(
            ['type' => E_ERROR, 'line' => 123, 'where' => 'mod/adaptivequiz/classes/cat_session.php'],
            $spans[2]['meta']
        );
        $this->assertStringNotContainsString('secret', json_encode($spans));
        $this->assertStringNotContainsString($CFG->dirroot, json_encode($spans), 'No server path.');
    }

    /**
     * A file outside the Moodle tree is named by its base name only.
     */
    public function test_fatal_error_outside_the_tree(): void {
        request_timing::$lasterror = fn() => ['type' => E_COMPILE_ERROR, 'file' => '/srv/secret/x.php', 'line' => 1];

        request_timing::on_shutdown();

        $this->assertSame('x.php', request_timing::get_spans()[0]['meta']['where']);
    }

    /**
     * A warning, or no error at all, changes nothing at shutdown.
     */
    public function test_no_fatal_error_at_shutdown(): void {
        request_timing::start('administer_item');
        request_timing::$lasterror = fn() => ['type' => E_WARNING, 'file' => __FILE__, 'line' => 1, 'message' => 'x'];
        request_timing::on_shutdown();
        request_timing::$lasterror = fn() => null;
        request_timing::on_shutdown();

        $spans = request_timing::get_spans();
        $this->assertCount(1, $spans);
        $this->assertNull($spans[0]['ms'], 'Still open - no fatal error ended it.');
    }
}
