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
 * A11Y-AQ-SR-01 - what NVDA says when an attempt ends unexpectedly (issue #20).
 *
 * The dialog has to be announced as a dialog, with its title and the reason, the moment it opens -
 * a blind participant who submits the last answer must not land on a silent page. After closing it,
 * NVDA has to land on the notice on the page and read it, not on the top of the document.
 *
 * The spoken phrase log is attached whatever the outcome: on a failure it is the evidence, on a pass
 * the documentation of what was heard.
 *
 * Windows and NVDA only (see playwright.config.ts).
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import fs from 'node:fs';
import path from 'node:path';
import {nvdaTest} from '@guidepup/playwright';
// eslint-disable-next-line @typescript-eslint/no-require-imports
const {env, loginAs} = require('../../support/env');

const {expect} = nvdaTest;

// Everything NVDA says after a command, not only its first utterance: role and state often come as
// a second one ("dialog", then the title).
nvdaTest.use({nvdaStartOptions: {capture: true}});

/**
 * Keeps what NVDA said as a plain file in the artefact, with commit, date and site on top - a dated
 * record rather than something remembered.
 *
 * @param name File name without extension.
 * @param phrases The spoken phrases.
 */
function archive(name: string, phrases: string[]): void {
    const dir = path.join(process.env.A11Y_EXPORT_DIR || __dirname, 'transcripts');
    fs.mkdirSync(dir, {recursive: true});
    fs.writeFileSync(path.join(dir, `${name}.txt`), [
        `# ${name}`,
        `# recorded ${new Date().toISOString()}`,
        `# commit ${process.env.GITHUB_SHA || 'local'}`,
        `# site ${process.env.A11Y_BASE_URL || 'unknown'}`,
        '',
        ...phrases,
    ].join('\n'), 'utf8');
}

/** Reading steps after the dialog was closed: enough for the notice and the feedback below it. */
const READING_STEPS = 20;

nvdaTest.describe('A11Y-AQ-SR-01 - the end of the test is announced', () => {
    nvdaTest('the dialog is announced with its reason; afterwards NVDA reads the notice', async({page, nvda}, testInfo) => {
        await nvdaTest.step('answer the first question', async() => {
            await loginAs(page, env.user, env.password);
            await page.goto(env.activityUrl);
            // A button on the newer host line (core/single_button), a link styled as one on the legacy line.
            await page.getByRole('button', {name: /start attempt/i})
                .or(page.getByRole('link', {name: /start attempt/i})).first().click();
            await page.getByRole('radio', {name: 'True'}).check();
            await page.getByRole('button', {name: /submit answer/i}).click();
            await page.getByText('Second question').first().waitFor();
            await page.getByRole('radio', {name: 'True'}).check();
        });

        const spoken: string[] = [];
        await nvdaTest.step('submit the last answer; the dialog opens', async() => {
            await nvda.navigateToWebContent();
            await nvda.clearSpokenPhraseLog();
            // Guidepup only logs what NVDA says during an action it runs itself. The click is
            // Playwright's, so it goes through nvda.capture(): everything NVDA says from the click
            // until the dialog is open and the announcement is over is recorded.
            const opening = await nvda.capture(async() => {
                await page.getByRole('button', {name: /submit answer/i}).click();
                await expect(page.getByRole('dialog', {name: 'Your test has ended'})).toBeVisible();
                // NVDA speaks in real time; the opening announcement follows the focus change.
                await page.waitForTimeout(4_000);
            }, {capture: true});
            spoken.push(opening.spokenPhrase);
        });

        await nvdaTest.step('close it with Escape and read on', async() => {
            await nvda.clearSpokenPhraseLog();
            await nvda.press('Escape');
            await expect(page.getByRole('dialog')).toHaveCount(0);
            for (let step = 0; step < READING_STEPS; step++) {
                await nvda.next();
            }
            spoken.push('--- after closing ---', ...await nvda.spokenPhraseLog());
        });

        archive('A11Y-AQ-SR-01-stop-notice', spoken);
        await testInfo.attach('nvda-spoken-phrases.txt', {body: spoken.join('\n'), contentType: 'text/plain'});
        const [opening, afterwards] = spoken.join(' | ').split('--- after closing ---');

        await nvdaTest.step('the dialog is announced as a dialog, with title and reason', async() => {
            expect(opening, 'NVDA must say that a dialog opened').toMatch(/dialog/i);
            expect(opening, 'and read its title').toContain('Your test has ended');
            expect(opening, 'and the reason').toContain('There are no further suitable questions');
        });

        await nvdaTest.step('after closing, NVDA is on the notice and reads it, then the feedback', async() => {
            expect(afterwards, 'the notice, named by its heading').toContain('Your test has ended');
            expect(afterwards, 'the feedback, released after the notice').toMatch(/finished|thank you/i);
        });
    });
});
