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
 * Shared steps for the user stories.
 *
 * @package    mod_adaptivequiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Logs in through the normal login form.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} username
 * @param {string} password
 */
async function login(page, username, password) {
    await page.goto('/login/index.php');
    await page.fill('#username', username);
    await page.fill('#password', password);
    await page.click('#loginbtn');
    await page.waitForLoadState('networkidle');
}

/**
 * Logs the current user out, so the next story starts clean.
 *
 * @param {import('@playwright/test').Page} page
 */
async function logout(page) {
    await page.goto('/login/logout.php');
    const confirm = page.getByRole('button', {name: /continue|weiter/i});
    if (await confirm.count()) {
        await confirm.first().click();
    }
}

/**
 * Records a milestone of a story: a named screenshot that survives a green run.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').TestInfo} testInfo
 * @param {string} name
 */
async function milestone(page, testInfo, name) {
    const shot = await page.screenshot({fullPage: true});
    await testInfo.attach(name, {body: shot, contentType: 'image/png'});
}

/**
 * Opens an activity by its name from the course page.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} courseshortname
 * @param {string} activityname
 */
async function open_activity(page, courseshortname, activityname) {
    await page.goto('/course/view.php?name=' + encodeURIComponent(courseshortname));
    await page.getByRole('link', {name: activityname}).first().click();
    await page.waitForLoadState('networkidle');
}

module.exports = {login, logout, milestone, open_activity};
