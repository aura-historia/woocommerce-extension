// @ts-check

const BASE_URL = process.env.WP_BASE_URL || "http://localhost:8888";
const SETTINGS_PATH = "/wp-admin/admin.php?page=aura-historia-partner-connect";
const AUTO_START_KEY = "ahpcOauthAutoStart";

/**
 * Answers browser requests to other origins, such as the admin bar Gravatar,
 * with an empty response, so the tests make no outbound requests.
 *
 * @param {import("@playwright/test").Page} page Page to restrict.
 * @return {Promise<void>}
 */
async function blockExternalRequests(page) {
    const origin = new URL(BASE_URL).origin;

    await page.route(
        (url) => url.origin !== origin,
        (route) => route.fulfill({ status: 204 }),
    );
}

/**
 * Whether a URL is the plugin settings page.
 *
 * @param {URL} url URL to check.
 * @return {boolean}
 */
function isSettingsUrl(url) {
    return (
        url.pathname.endsWith("/wp-admin/admin.php") &&
        url.searchParams.get("page") === "aura-historia-partner-connect"
    );
}

/**
 * Whether a URL is the admin-post.php endpoint that starts OAuth.
 *
 * @param {URL} url URL to check.
 * @return {boolean}
 */
function isOauthStartUrl(url) {
    return url.pathname.endsWith("/wp-admin/admin-post.php");
}

/**
 * Records OAuth start submissions and uncaught page errors.
 *
 * Each start is sent to WordPress, but its redirect is captured instead of
 * followed, so no request reaches Aura Historia. Other origins are blocked.
 *
 * @param {import("@playwright/test").Page} page Page to observe.
 * @return {Promise<{
 *     starts: {body: URLSearchParams, status: number, location: string}[],
 *     errors: Error[],
 * }>}
 */
async function observeOauthStarts(page) {
    /** @type {{body: URLSearchParams, status: number, location: string}[]} */
    const starts = [];
    /** @type {Error[]} */
    const errors = [];

    await blockExternalRequests(page);
    await page.route(isOauthStartUrl, async (route) => {
        const request = route.request();
        const body = new URLSearchParams(request.postData() || "");

        if ("POST" !== request.method() || "ahpc_start_oauth" !== body.get("action")) {
            await route.fallback();
            return;
        }

        const response = await route.fetch({ maxRedirects: 0 });

        starts.push({
            body,
            status: response.status(),
            location: response.headers().location || "",
        });
        // Opt in to view transitions like wp-admin does. Otherwise Chromium reports
        // an aborted transition on this same-origin stub, which the real
        // cross-origin redirect never triggers.
        await route.fulfill({
            contentType: "text/html",
            body:
                "<!doctype html><style>@view-transition { navigation: auto; }</style>" +
                "<title>Aura Historia authorization</title>",
        });
    });
    page.on("pageerror", (error) => errors.push(error));

    return { starts, errors };
}

/**
 * Disconnects the store and clears stored OAuth errors and fake broker
 * transactions through the e2e fake broker mu-plugin.
 *
 * @param {import("@playwright/test").Page} page Logged-in page.
 * @return {Promise<void>}
 */
async function resetStore(page) {
    const response = await page.request.get("/wp-admin/admin-post.php?action=ahpc_e2e_reset");

    if (!response.ok()) {
        throw new Error(
            `Resetting the store failed with HTTP ${response.status()}. The browser tests need ` +
                "the e2e fake broker mu-plugin and AHPC_E2E_FAKE_BROKER, as configured in CI.",
        );
    }
}

/**
 * Reads the auto-start marker from the page's session storage.
 *
 * @param {import("@playwright/test").Page} page Page to read.
 * @return {Promise<string|null>}
 */
function readAutoStartMarker(page) {
    return page.evaluate((key) => window.sessionStorage.getItem(key), AUTO_START_KEY);
}

module.exports = {
    AUTO_START_KEY,
    BASE_URL,
    SETTINGS_PATH,
    blockExternalRequests,
    isOauthStartUrl,
    isSettingsUrl,
    observeOauthStarts,
    readAutoStartMarker,
    resetStore,
};
