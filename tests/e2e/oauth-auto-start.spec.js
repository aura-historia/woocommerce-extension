// @ts-check
const { test, expect } = require("@playwright/test");
const {
    BASE_URL,
    SETTINGS_PATH,
    isOauthStartUrl,
    isSettingsUrl,
    observeOauthStarts,
    readAutoStartMarker,
    resetStore,
} = require("./helpers");

const OAUTH_SCOPE =
    "product-listings:read product-listings:write listing-sources:read listing-sources:write";
const CONNECT_BUTTON = { name: "Connect with Aura Historia" };

/**
 * Loads the settings page and checks that it has not navigated away.
 *
 * An unexpected automatic start can still be in flight after this returns, so
 * callers finish with a manual start and assert the exact start count.
 *
 * @param {import("@playwright/test").Page} page Page to navigate.
 * @param {string}                         path Settings page path.
 * @return {Promise<void>}
 */
async function openSettings(page, path = SETTINGS_PATH) {
    await page.goto(path);
    await expect(page).toHaveURL(isSettingsUrl);
}

/**
 * Waits for the expected number of OAuth starts, reporting page errors on failure.
 *
 * @param {{starts: unknown[], errors: Error[]}} observed Observed starts and errors.
 * @param {number}                               count    Expected start count.
 * @return {Promise<void>}
 */
async function expectOauthStarts(observed, count) {
    await expect
        .poll(
            () => ({
                errors: observed.errors.map((error) => error.message),
                starts: observed.starts.length,
            }),
            { timeout: 15000 },
        )
        .toEqual({ errors: [], starts: count });
}

test.beforeEach(async ({ page }) => {
    await resetStore(page);
});

test("first visit starts OAuth once even though the form has a control named submit", async ({
    page,
}) => {
    const observed = await observeOauthStarts(page);
    const { starts } = observed;

    // The settings page submits itself while loading, so only wait for it to commit.
    await page.goto(SETTINGS_PATH, { waitUntil: "commit" });
    await expectOauthStarts(observed, 1);
    await expect(page).toHaveURL(isOauthStartUrl);

    // WordPress accepted the nonce, registered a broker transaction server to
    // server and redirected to the broker's authorization URL.
    expect(starts[0].body.get("_wpnonce")).toBeTruthy();
    expect(starts[0].status).toBe(302);

    const authorization = new URL(starts[0].location);
    const query = authorization.searchParams;
    expect(authorization.origin).toBe(new URL(BASE_URL).origin);
    expect(authorization.pathname).toBe("/en/oauth/authorize");
    expect(query.get("client_id")).toMatch(/^oc_[0-9a-z]{26}$/);
    expect(query.get("response_type")).toBe("code");
    expect(query.get("scope")).toBe(OAUTH_SCOPE);
    expect(query.get("requires_listing_source_id")).toBe("true");
    expect(query.get("code_challenge_method")).toBe("S256");

    // The browser carries only the broker's opaque reference: no verifier,
    // completion secret or readable broker-state payload.
    expect(query.has("code_verifier")).toBe(false);
    expect(starts[0].location).not.toMatch(/completion_secret|client_state|code_verifier/);
    expect(() =>
        JSON.parse(Buffer.from(query.get("state") || "", "base64url").toString("utf8")),
    ).toThrow();

    // Returning in the same tab must not start OAuth again.
    await openSettings(page);
    expect(await readAutoStartMarker(page)).toBe("1");
    expect(
        await page
            .locator("#ahpc-oauth-start-form")
            .evaluate((form) => /** @type {any} */ (form).submit instanceof HTMLInputElement),
    ).toBe(true);

    // The manual button still starts OAuth, and it is the only further start.
    await page.getByRole("button", CONNECT_BUTTON).click();
    await expectOauthStarts(observed, 2);
    expect(starts[1].status).toBe(302);
});

test("an OAuth failure return does not start OAuth automatically", async ({ page }) => {
    const observed = await observeOauthStarts(page);

    await openSettings(page, `${SETTINGS_PATH}&ahpc_oauth=failed`);
    expect(await readAutoStartMarker(page)).toBeNull();

    await page.getByRole("button", CONNECT_BUTTON).click();
    await expectOauthStarts(observed, 1);
});

test("a failed automatic start is not recorded and is retried on the next visit", async ({
    page,
}) => {
    const observed = await observeOauthStarts(page);

    await page.addInitScript(() => {
        const nativeSubmit = HTMLFormElement.prototype.submit;

        HTMLFormElement.prototype.submit = function () {
            if (!window.sessionStorage.getItem("ahpcE2eSubmitFailed")) {
                window.sessionStorage.setItem("ahpcE2eSubmitFailed", "1");
                throw new Error("Simulated submit failure");
            }

            return nativeSubmit.call(this);
        };
    });

    await openSettings(page);

    expect(observed.errors.map((error) => error.message)).toEqual([
        "Simulated submit failure",
    ]);
    expect(observed.starts).toEqual([]);
    expect(await readAutoStartMarker(page)).toBeNull();

    observed.errors.length = 0;
    await page.reload({ waitUntil: "commit" });
    await expectOauthStarts(observed, 1);
});

test("without session storage OAuth waits for the manual button", async ({ page }) => {
    const observed = await observeOauthStarts(page);

    await page.addInitScript(() => {
        Object.defineProperty(window, "sessionStorage", {
            configurable: true,
            get() {
                throw new DOMException("Storage is blocked.", "SecurityError");
            },
        });
    });

    await openSettings(page);

    await page.getByRole("button", CONNECT_BUTTON).click();
    await expectOauthStarts(observed, 1);
});
