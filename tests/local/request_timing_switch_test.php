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
 * The host's request timing is off unless somebody reads it (mod_adaptivequiz #17: production default off). The CAT model
 * that asks for it: tests/local/catmodel/request_timing_catmodel_test.php (with the fixture CAT model, not shipped).
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(request_timing::class)]
final class request_timing_switch_test extends \advanced_testcase {
    /**
     * Every test decides from scratch.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        request_timing::$force = null;
        request_timing::reset_request();
    }

    /**
     * Nothing left for the next test.
     */
    protected function tearDown(): void {
        request_timing::reset_request();
        parent::tearDown();
    }

    /**
     * Off by default: no span, no shutdown handler, the step runs as it is and returns its result.
     */
    public function test_off_by_default(): void {
        $this->assertFalse(request_timing::enabled());
        request_timing::start('lock_wait');
        request_timing::stop('lock_wait', ['acquired' => true]);
        $this->assertSame(42, request_timing::measure('select_item', fn() => 42));
        $this->assertSame('q', request_timing::measure_db('load_question', fn() => 'q'));
        $this->assertSame([], request_timing::get_spans());
        $this->assertSame(0, request_timing::summary()['spans']);
        // A step that throws throws as before.
        $this->expectException(\RuntimeException::class);
        request_timing::measure('select_item', function () {
            throw new \RuntimeException('step');
        });
    }

    /**
     * The setting switches it on for every request.
     */
    public function test_the_setting_switches_it_on(): void {
        set_config(request_timing::CONFIG, 1, 'adaptivequiz');
        $this->assertTrue(request_timing::enabled());
        request_timing::measure('select_item', fn() => null);
        $this->assertSame(['select_item'], array_column(request_timing::get_spans(), 'name'));
    }

    /**
     * Decided once per request - a span is never half measured -, again after reset().
     */
    public function test_decided_once_per_request(): void {
        $this->assertFalse(request_timing::enabled());
        set_config(request_timing::CONFIG, 1, 'adaptivequiz');
        $this->assertFalse(request_timing::enabled(), 'The request goes on as it began.');
        request_timing::reset();
        $this->assertTrue(request_timing::enabled());
        request_timing::start('question_usage');
        set_config(request_timing::CONFIG, 0, 'adaptivequiz');
        request_timing::stop('question_usage');
        $this->assertNotNull(request_timing::get_spans()[0]['ms'], 'The span it began is ended.');
    }
}
