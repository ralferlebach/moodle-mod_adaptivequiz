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
 * axe-core suite of mod_adaptivequiz: markup, keyboard and focus of the dialogs.
 *
 * Linux and headless - unlike the NVDA suite next door, which needs Windows. Video and the axe
 * results are kept for a green run as well: they are the evidence an accessibility review reads.
 *
 * A11Y_CHROMIUM points at a local Chromium when the bundled browser is not installed.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const path = require('node:path');
const {defineConfig, devices} = require('@playwright/test');

const exportdir = process.env.A11Y_EXPORT_DIR ? path.resolve(process.env.A11Y_EXPORT_DIR) : __dirname;

module.exports = defineConfig({
    testDir: './tests',
    timeout: 5 * 60 * 1000,
    expect: {timeout: 20 * 1000},
    workers: 1,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    outputDir: path.join(exportdir, 'test-results'),
    reporter: process.env.CI ? [
        ['list'],
        ['html', {open: 'never', outputFolder: path.join(exportdir, 'playwright-report')}],
        ['json', {outputFile: path.join(exportdir, 'results.json')}],
        ['junit', {outputFile: path.join(exportdir, 'junit.xml')}],
    ] : [['list']],
    use: {
        baseURL: process.env.A11Y_BASE_URL,
        video: 'on',
        screenshot: 'on',
        trace: 'retain-on-failure',
        ignoreHTTPSErrors: true,
        ...(process.env.A11Y_CHROMIUM ? {launchOptions: {executablePath: process.env.A11Y_CHROMIUM}} : {}),
    },
    projects: [{name: 'chromium', use: {...devices['Desktop Chrome']}}],
});
