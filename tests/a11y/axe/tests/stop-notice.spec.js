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
 * A11Y-AQ-01 - the stop notice (issue #20) with axe-core, keyboard and focus.
 *
 * axe checks the markup against WCAG 2.1 A/AA; it cannot tell whether the dialog behaves. So the
 * test also walks it with the keyboard: focus in the dialog when it opens, the feedback behind it
 * out of reach, Escape closes it like OK, and focus lands on the notice that stays on the page -
 * not on the top of the document, where a screen reader user would have to start over.
 *
 * What a screen reader actually says is checked by the NVDA suite (../screenreader).
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const {env, loginAs, runAttemptToItsEnd} = require('../../support/env');

/** The rule sets the plugin promises: WCAG 2.1 level A and AA. */
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

/**
 * axe on a part of the page; the violations are attached, so a failure can be read, not guessed.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').TestInfo} testInfo
 * @param {string} name
 * @param {string|null} include CSS selector of the part, null for the page.
 * @returns {Promise<Array>} The violations.
 */
const scan = async(page, testInfo, name, include) => {
    let builder = new AxeBuilder({page}).withTags(WCAG);
    if (include) {
        builder = builder.include(include);
    }
    const result = await builder.analyze();
    await testInfo.attach('axe-' + name + '.json', {
        body: JSON.stringify(result.violations, null, 2),
        contentType: 'application/json',
    });
    return result.violations.map((v) => v.id + ' (' + v.impact + '): ' + v.nodes.map((n) => n.target.join(' ')).join(', '));
};

test.describe('A11Y-AQ-01 - the stop notice is accessible', () => {
    test('dialog: named, focused, modal, no WCAG violation; closed by keyboard onto the notice', async({page}, testInfo) => {
        await loginAs(page, env.user, env.password);
        await runAttemptToItsEnd(page);

        const dialog = page.getByRole('dialog', {name: 'Your test has ended'});
        await test.step('the dialog opens, named by its title, with the reason in it', async() => {
            await expect(dialog).toBeVisible();
            await expect(dialog).toContainText('There are no further suitable questions for you at the moment');
            await testInfo.attach('dialog.png', {body: await page.screenshot(), contentType: 'image/png'});
        });

        await test.step('focus is inside the dialog, and Tab keeps it there', async() => {
            await expect.poll(() => dialog.evaluate((d) => d.contains(document.activeElement))).toBe(true);
            for (let i = 0; i < 4; i++) {
                await page.keyboard.press('Tab');
                expect(await dialog.evaluate((d) => d.contains(document.activeElement)), 'Tab ' + (i + 1)).toBe(true);
            }
        });

        await test.step('the feedback behind the dialog is out of reach', async() => {
            const feedback = page.locator('[data-region="adaptivequiz-attempt-feedback"]');
            await expect(feedback).toBeHidden();
            expect(await feedback.evaluate((f) => f.inert)).toBe(true);
        });

        await test.step('axe: the dialog has no WCAG 2.1 A/AA violation', async() => {
            expect(await scan(page, testInfo, 'dialog', '.modal-dialog')).toEqual([]);
        });

        await test.step('Escape closes it like OK; focus lands on the notice on the page', async() => {
            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            const notice = page.locator('[data-region="adaptivequiz-stopnotice"]');
            await expect(notice).toBeVisible();
            await expect(notice).toBeFocused();
            // Named by its heading, so a screen reader announces what it is landing on.
            await expect(notice).toHaveAccessibleName('Your test has ended');
            await expect(page.locator('[data-region="adaptivequiz-attempt-feedback"]')).toBeVisible();
        });

        await test.step('axe: the result page with the notice has no WCAG 2.1 A/AA violation', async() => {
            expect(await scan(page, testInfo, 'result-page', '#region-main')).toEqual([]);
        });

        await test.step('the confirmation is stored: after a reload no dialog, the notice is text', async() => {
            // The acknowledgement goes out on close; give it the time to arrive.
            await page.waitForLoadState('networkidle');
            await page.reload();
            await expect(page.locator('[data-region="adaptivequiz-stopnotice"]')).toContainText('Your test has ended');
            await expect(page.getByRole('dialog')).toHaveCount(0);
        });
    });
});
