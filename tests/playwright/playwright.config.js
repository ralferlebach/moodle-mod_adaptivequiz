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

/**
 * Playwright configuration for the manual user story runs.
 *
 * These runs are not a second unit test suite. They walk the product the way a person does and
 * record what they saw, so a release can be reviewed without rebuilding the environment. Video and
 * screenshots are kept on success as well - a green run with nothing to look at answers no
 * question a reviewer actually has.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {defineConfig} = require('@playwright/test');

module.exports = defineConfig({
    testDir: './stories',
    // The stories walk whole workflows; a single one can take minutes.
    timeout: 5 * 60 * 1000,
    expect: {timeout: 15 * 1000},
    // Never in parallel: the stories share one Moodle site and one set of users.
    workers: 1,
    fullyParallel: false,
    retries: 0,
    reporter: [
        ['list'],
        ['html', {outputFolder: '../../playwright-report', open: 'never'}],
    ],
    use: {
        baseURL: process.env.MOODLE_WWWROOT || 'http://localhost:8000',
        // 'on', not 'retain-on-failure': the recording is the deliverable, not a debugging aid.
        video: 'on',
        screenshot: 'on',
        trace: 'on',
        viewport: {width: 1440, height: 900},
        ignoreHTTPSErrors: true,
    },
    outputDir: '../../playwright-artifacts',
});
