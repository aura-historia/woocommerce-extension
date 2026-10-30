// @ts-check
const { test, expect } = require("@playwright/test");
const { SETTINGS_PATH, isSettingsUrl, observeOauthStarts, resetStore } = require("./helpers");

const SOURCE_ID = "ls_01jw7j4azge00800000000000g";
const CALLBACK_FIELDS = ["completion_code", "page", "state", "transaction_id"];

test.beforeEach(async ({ page }) => {
    await resetStore(page);
});

test.afterEach(async ({ page }) => {
    await resetStore(page);
});

/**
 * Lets the settings page start OAuth on its own and returns the broker's
 * authorization URL. The start's redirect is captured, not followed.
 *
 * @param {import("@playwright/test").Page} page Page.
 * @return {Promise<URL>}
 */
async function startConnection(page) {
    const observed = await observeOauthStarts(page);

    await page.goto(SETTINGS_PATH, { waitUntil: "commit" });
    await expect.poll(() => observed.starts.length, { timeout: 15000 }).toBe(1);
    expect(observed.starts[0].status).toBe(302);

    return new URL(observed.starts[0].location);
}

/**
 * Returns the fake broker's consent URL for an authorization URL.
 *
 * @param {URL}    authorization Authorization URL.
 * @param {string} decision      approve or deny.
 * @return {string}
 */
function consentPath(authorization, decision = "approve") {
    const reference = encodeURIComponent(authorization.searchParams.get("state") || "");

    return `/wp-admin/admin-post.php?action=ahpc_e2e_consent&decision=${decision}&state=${reference}`;
}

/**
 * Returns the merchant callback URL the broker redirects to, without following it.
 *
 * @param {import("@playwright/test").Page} page          Page.
 * @param {URL}                            authorization Authorization URL.
 * @return {Promise<URL>}
 */
async function brokerCallback(page, authorization) {
    const response = await page.request.get(consentPath(authorization), { maxRedirects: 0 });
    expect(response.status()).toBe(302);

    return new URL(response.headers().location);
}

/**
 * @param {URL} url URL.
 * @return {string[]}
 */
function queryKeys(url) {
    return [...url.searchParams.keys()].sort();
}

test("connecting through the broker stores the connection and cleans the address bar", async ({
    page,
}) => {
    const authorization = await startConnection(page);
    /** @type {URL[]} */
    const callbacks = [];
    page.on("request", (request) => {
        const url = new URL(request.url());
        if (url.searchParams.has("completion_code")) {
            callbacks.push(url);
        }
    });

    await page.goto(consentPath(authorization));

    await expect(page).toHaveURL(
        (url) => isSettingsUrl(url) && url.searchParams.get("ahpc_oauth") === "connected",
    );
    expect(queryKeys(new URL(page.url()))).toEqual(["ahpc_oauth", "page"]);
    expect(callbacks).toHaveLength(1);
    expect(isSettingsUrl(callbacks[0])).toBe(true);
    expect(queryKeys(callbacks[0])).toEqual(CALLBACK_FIELDS);

    await expect(page.getByText("Aura Historia authorization completed")).toBeVisible();
    await expect(page.getByText(SOURCE_ID, { exact: true })).toBeVisible();
    await expect(page.getByRole("button", { name: "Reconnect with Aura Historia" })).toBeVisible();

    const html = await page.content();
    for (const field of ["completion_code", "state", "transaction_id"]) {
        expect(html).not.toContain(callbacks[0].searchParams.get(field));
    }
    expect(html).not.toContain("aurahistoria_e2e_fake_token");

    // Replaying the callback never redeems again and keeps the connection.
    // The rejection is reported once and not stored.
    await page.goto(callbacks[0].toString());
    await expect(page).toHaveURL(
        (url) => isSettingsUrl(url) && url.searchParams.get("ahpc_oauth") === "rejected",
    );
    await expect(page.getByText("has expired")).toBeVisible();
    await expect(page.getByText(SOURCE_ID, { exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByText("has expired")).toHaveCount(0);
});

test("a browser-supplied listing source cannot complete or change the connection", async ({
    page,
}) => {
    const authorization = await startConnection(page);
    const callback = await brokerCallback(page, authorization);
    const tampered = new URL(callback);
    tampered.searchParams.set("listing_source_id", "ls_01jw7j4azge008000000000003");

    await page.goto(tampered.toString());
    await expect(page).toHaveURL(
        (url) => isSettingsUrl(url) && url.searchParams.get("ahpc_oauth") === "rejected",
    );
    await expect(page.getByText("no longer accepts")).toBeVisible();
    await expect(page.getByRole("button", { name: "Connect with Aura Historia" })).toBeVisible();

    // The rejected callback did not consume the attempt.
    await page.goto(callback.toString());
    await expect(page).toHaveURL(
        (url) => isSettingsUrl(url) && url.searchParams.get("ahpc_oauth") === "connected",
    );
    await expect(page.getByText(SOURCE_ID, { exact: true })).toBeVisible();
    await expect(page.getByText("ls_01jw7j4azge008000000000003", { exact: true })).toHaveCount(0);
});

test("a denied connection leaves the store disconnected", async ({ page }) => {
    const authorization = await startConnection(page);

    await page.goto(consentPath(authorization, "deny"));

    await expect(page).toHaveURL(
        (url) => isSettingsUrl(url) && url.searchParams.get("ahpc_oauth") === "failed",
    );
    await expect(page.getByText("was not approved")).toBeVisible();
    await expect(page.getByRole("button", { name: "Connect with Aura Historia" })).toBeVisible();
    await expect(page.getByText(SOURCE_ID, { exact: true })).toHaveCount(0);
});
