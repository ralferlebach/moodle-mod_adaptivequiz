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
 * User stories of the activity on its own, with the built-in algorithm.
 *
 * Each story is one thing a person wants to get done, walked end to end. The point is not to
 * assert every detail - the PHPUnit and Behat suites do that - but to show that the workflow holds
 * together in a browser, and to leave a recording of it.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {login, logout, milestone, open_activity} = require('./helpers');

const COURSE = process.env.STORY_COURSE || 'PWC1';
const ACTIVITY = process.env.STORY_ACTIVITY || 'Adaptive quiz';

test.describe('mod_adaptivequiz on its own', () => {
    test.afterEach(async ({page}) => {
        await logout(page);
    });

    test('A teacher creates an adaptive quiz and links a question bank', async ({page}, testInfo) => {
        await login(page, 'pwteacher', process.env.STORY_PASSWORD || 'Story123!');
        await page.goto('/course/view.php?name=' + encodeURIComponent(COURSE));
        await milestone(page, testInfo, '01-course-page');

        await page.goto('/course/modedit.php?add=adaptivequiz&course=' +
            encodeURIComponent(process.env.STORY_COURSEID || '2') + '&section=1');
        await expect(page.locator('#id_name')).toBeVisible();
        await milestone(page, testInfo, '02-activity-form');

        // The settings of the built-in algorithm have to be on the form when no CAT model is chosen.
        await expect(page.locator('#id_startinglevel')).toBeVisible();
        await expect(page.locator('#id_maximumquestions')).toBeVisible();
        await milestone(page, testInfo, '03-builtin-algorithm-settings');
    });

    test('A student takes an attempt and reaches the result page', async ({page}, testInfo) => {
        await login(page, 'pwstudent', process.env.STORY_PASSWORD || 'Story123!');
        await open_activity(page, COURSE, ACTIVITY);
        await milestone(page, testInfo, '01-activity-page');

        await page.getByRole('button', {name: /start attempt/i}).click();
        await page.waitForLoadState('networkidle');
        await milestone(page, testInfo, '02-first-question');

        // Answer up to the configured maximum; the attempt ends on its own.
        for (let step = 0; step < 25; step++) {
            const answer = page.locator('input[type=radio]').first();
            if (!await answer.count()) {
                break;
            }
            await answer.check();
            await page.getByRole('button', {name: /submit answer/i}).click();
            await page.waitForLoadState('networkidle');
        }

        await milestone(page, testInfo, '03-attempt-finished');
        await expect(page.locator('body')).toContainText(/finished|abgeschlossen|thank you/i);
    });

    test('A teacher reviews the attempts report', async ({page}, testInfo) => {
        await login(page, 'pwteacher', process.env.STORY_PASSWORD || 'Story123!');
        await open_activity(page, COURSE, ACTIVITY);
        await milestone(page, testInfo, '01-activity-page-as-teacher');

        // Without a CAT model the built-in report is shown.
        await expect(page.locator('body')).toContainText(/report|bericht/i);
        await milestone(page, testInfo, '02-attempts-report');
    });
});
