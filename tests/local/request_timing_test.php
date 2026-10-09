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
    /**
     * Starts and ends every test with no spans.
     */
    protected function setUp(): void {
        parent::setUp();
        request_timing::reset();
    }

    /**
     * Leaves no spans for the next test.
     */
    protected function tearDown(): void {
        request_timing::reset();
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
        $this->assertSame(['acquired' => true], $spans['lock_wait']['meta']);
        $this->assertArrayNotHasKey('startns', $spans['lock_wait']);
    }

    /**
     * A step that throws still ends its span.
     */
    public function test_span_ends_when_the_step_throws(): void {
        try {
            request_timing::measure('select_item', function () {
                throw new \coding_exception('no item');
            });
        } catch (\coding_exception $e) {
            $this->assertSame('no item', $e->a ?? 'no item');
        }

        $this->assertNotNull(request_timing::get_spans()[0]['ms']);
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
}
