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

namespace mod_adaptivequiz\local\catmodel;

use mod_adaptivequiz\local\request_timing;

/**
 * A CAT model whose own trace is on switches the host's request timing on (mod_adaptivequiz #17), through the callback
 * adaptivequizcatmodel_<name>_wants_request_timing() - here the one of the fixture CAT model.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(request_timing::class)]
final class request_timing_catmodel_test extends \advanced_testcase {
    /**
     * Every test decides from scratch.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        request_timing::$force = null;
        request_timing::reset_request();
        unset($GLOBALS['adaptivequizcatmodel_testcatmodel_wantstiming']);
    }

    /**
     * Nothing left for the next test.
     */
    protected function tearDown(): void {
        request_timing::reset_request();
        unset($GLOBALS['adaptivequizcatmodel_testcatmodel_wantstiming']);
        parent::tearDown();
    }

    /**
     * A CAT model whose own trace is on asks for the spans.
     */
    public function test_a_cat_model_asks_for_it(): void {
        $GLOBALS['adaptivequizcatmodel_testcatmodel_wantstiming'] = true;
        $this->assertTrue(request_timing::enabled());
        $GLOBALS['adaptivequizcatmodel_testcatmodel_wantstiming'] = false;
        request_timing::reset();
        $this->assertFalse(request_timing::enabled());
    }
}
