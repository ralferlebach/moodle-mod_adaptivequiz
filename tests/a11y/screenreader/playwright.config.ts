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
 * Screen reader suite of mod_adaptivequiz (NVDA through Guidepup), after the suite of local_adele.
 *
 * The only suite that checks what a screen reader SAYS. axe-core (../axe) checks the markup and the
 * keyboard; here NVDA reads the page and the test reads NVDA's spoken phrase log.
 *
 * From Guidepup's own requirements:
 * - Windows only: NVDA exists nowhere else, and @guidepup/playwright throws while being imported on
 *   any other platform. This directory must never be pulled into another suite's testDir.
 * - Not headless, never parallel: a screen reader is one instance on a visible desktop.
 * - Playwright 1.57 or newer: own directory, own lockfile.
 *
 * Slow by nature - NVDA speaks in real time - so the timeouts are generous. No retries: a run that
 * passes only on the second attempt says nothing about what a person heard on the first.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import path from 'node:path';
import {defineConfig, devices, ReporterDescription} from '@playwright/test';
import {screenReaderConfig} from '@guidepup/playwright';

const exportDir = process.env.A11Y_EXPORT_DIR ? path.resolve(process.env.A11Y_EXPORT_DIR) : __dirname;

const reporter: ReporterDescription[] = process.env.CI
    ? [
        ['list'],
        ['html', {open: 'never', outputFolder: path.join(exportDir, 'playwright-report')}],
        ['json', {outputFile: path.join(exportDir, 'results.json')}],
        ['junit', {outputFile: path.join(exportDir, 'junit.xml')}],
    ]
    : [['list']];

export default defineConfig({
    ...screenReaderConfig,
    testDir: './tests',
    outputDir: path.join(exportDir, 'test-results'),
    timeout: 15 * 60_000,
    expect: {timeout: 30_000},
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter,
    use: {
        ...screenReaderConfig.use,
        baseURL: process.env.A11Y_BASE_URL,
        // The recording of a passing run is the point: it lets a sighted reviewer follow what was heard.
        video: 'on',
        screenshot: 'on',
        trace: 'retain-on-failure',
        ignoreHTTPSErrors: true,
    },
    projects: [{name: 'chromium', use: {...devices['Desktop Chrome'], channel: 'chrome'}}],
});
