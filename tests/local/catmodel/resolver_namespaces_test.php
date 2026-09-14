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

use advanced_testcase;
use mod_adaptivequiz\local\itemadministration\item_administration_factory;

/**
 * A CAT model is found in either namespace layout its handlers may use.
 *
 * The host keeps its item administration under local\itemadministration, the CATquiz adapter keeps
 * its own under local\catmodel\itemadministration. Both are legitimate, and the resolver has to
 * look in both - a CAT model whose factory is not found simply never drives anything, silently.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(catmodel_resolver::class)]
final class resolver_namespaces_test extends advanced_testcase {
    /**
     * A CAT model keeping its item administration the way the CATquiz adapter does is found.
     *
     * The fixture CAT model uses local\\catmodel\\itemadministration, exactly like
     * adaptivequizcatmodel_catquiz. Before this was searched, the adapter's factory was invisible
     * to the host - silently, because a CAT model that offers no factory simply falls back to the
     * built-in algorithm.
     */
    public function test_fixture_catmodel_is_found(): void {
        $this->resetAfterTest();

        $this->assertInstanceOf(
            item_administration_factory::class,
            catmodel_resolver::handler('testcatmodel', item_administration_factory::class)
        );
    }
}
