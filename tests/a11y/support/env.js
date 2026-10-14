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
 * What the accessibility suites need from the fixture (seed_stop_notice.php) and the site.
 *
 * Every value is required: a suite that ran against an empty user name would fail far away from
 * the real cause, a seed step that did not run.
 *
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A required environment variable.
 *
 * @param {string} name
 * @returns {string}
 */
const required = (name) => {
    const value = process.env[name];
    if (!value) {
        throw new Error('Environment variable ' + name + ' is not set. It comes from tests/a11y/seed_stop_notice.php; ' +
            'check that the seed ran and that its output reached the environment.');
    }
    return value;
};

const env = {
    get baseUrl() {
        return required('A11Y_BASE_URL');
    },
    get user() {
        return required('A11Y_USER');
    },
    get password() {
        return required('A11Y_PASSWORD');
    },
    get activityUrl() {
        return required('A11Y_ACTIVITY_URL');
    },
};

/**
 * Logs in over HTTP, not through the form: the form is not what these suites are about, and the
 * request API shares its cookies with the page.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} username
 * @param {string} password
 */
const loginAs = async(page, username, password) => {
    await page.goto('about:blank');
    await page.context().clearCookies();
    const api = page.context().request;
    const form = await api.get('/login/index.php');
    const token = /name="logintoken" value="([^"]+)"/.exec(await form.text())?.[1];
    if (!token) {
        throw new Error('No login token on /login/index.php - is this a Moodle site?');
    }
    const response = await api.post('/login/index.php', {form: {anchor: '', logintoken: token, username, password}});
    if (response.url().includes('/login/index.php')) {
        throw new Error('Login rejected for "' + username + '".');
    }
};

/**
 * Runs the attempt of the fixture to its end: two answers, then nothing suitable is left.
 *
 * @param {import('@playwright/test').Page} page
 */
const runAttemptToItsEnd = async(page) => {
    await page.goto(env.activityUrl);
    await page.getByRole('button', {name: /start attempt/i}).or(page.getByRole('link', {name: /start attempt/i})).first().click();
    for (const question of ['First question', 'Second question']) {
        await page.getByText(question).first().waitFor();
        await page.getByRole('radio', {name: 'True'}).check();
        await page.getByRole('button', {name: /submit answer/i}).click();
    }
};

module.exports = {env, loginAs, runAttemptToItsEnd};
