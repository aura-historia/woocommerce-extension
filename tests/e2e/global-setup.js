// @ts-check
const { chromium } = require("@playwright/test");
const { SETTINGS_PATH, blockExternalRequests, resetStore } = require("./helpers");

/**
 * Logs in as the wp-env administrator, disconnects the store through the e2e
 * fake broker and checks that the settings page will start OAuth
 * automatically, so a misconfigured site fails with a clear reason.
 *
 * @param {import("@playwright/test").FullConfig} config Playwright config.
 * @return {Promise<void>}
 */
module.exports = async function globalSetup(config) {
    const { baseURL, storageState } = config.projects[0].use;
    const browser = await chromium.launch();

    try {
        const page = await browser.newPage({ baseURL });

        await blockExternalRequests(page);
        await page.goto("/wp-login.php");
        await page.locator("#user_login").fill(process.env.WP_USERNAME || "admin");
        await page.locator("#user_pass").fill(process.env.WP_PASSWORD || "password");
        await page.locator("#wp-submit").click();
        await page.waitForURL("**/wp-admin/**");
        await page.context().storageState({ path: /** @type {string} */ (storageState) });
        await resetStore(page);

        // Fetch the HTML without running its scripts, which would start OAuth.
        const response = await page.request.get(SETTINGS_PATH);
        const html = await response.text();

        if (!response.ok() || !html.includes("ahpcOauthAutoStart")) {
            throw new Error(
                `The settings page at ${baseURL}${SETTINGS_PATH} does not start OAuth automatically. ` +
                    "The e2e tests need a valid oc_ AHPC_OAUTH_CLIENT_ID and AHPC_OAUTH_BROKER_BASE_URL " +
                    "set to the site origin, for example in .wp-env.override.json as in CI.",
            );
        }
    } finally {
        await browser.close();
    }
};
