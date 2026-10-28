// @ts-check

const SETTINGS_PATH = "/wp-admin/admin.php?page=aura-historia-partner-connect";
const AUTO_START_KEY = "ahpcOauthAutoStart";

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
 * followed, so no request reaches Aura Historia.
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
    SETTINGS_PATH,
    isOauthStartUrl,
    isSettingsUrl,
    observeOauthStarts,
    readAutoStartMarker,
};
