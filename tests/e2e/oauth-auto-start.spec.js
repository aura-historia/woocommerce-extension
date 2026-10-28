// @ts-check
const { createHash } = require("node:crypto");
const { test, expect } = require("@playwright/test");
const {
    SETTINGS_PATH,
    isOauthStartUrl,
    isSettingsUrl,
    observeOauthStarts,
    readAutoStartMarker,
} = require("./helpers");

const OAUTH_SCOPE =
    "product-listings:read product-listings:write listing-sources:read listing-sources:write";
const CONNECT_BUTTON = { name: "Connect with Aura Historia" };

/**
 * Loads the settings page and waits until no automatic start can still be pending.
 *
 * @param {import("@playwright/test").Page} page Page to navigate.
 * @param {string}                         path Settings page path.
 * @return {Promise<void>}
 */
async function openSettingsWithoutAutoStart(page, path = SETTINGS_PATH) {
    await page.goto(path);
    await page.waitForLoadState("networkidle");
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

test("first visit starts OAuth once even though the form has a control named submit", async ({
    page,
}) => {
    const observed = await observeOauthStarts(page);
    const { starts } = observed;

    // The settings page submits itself while loading, so only wait for it to commit.
    await page.goto(SETTINGS_PATH, { waitUntil: "commit" });
    await expectOauthStarts(observed, 1);
    await expect(page).toHaveURL(isOauthStartUrl);

    // WordPress accepted the nonce and redirected to the authorization endpoint.
    expect(starts[0].body.get("_wpnonce")).toBeTruthy();
    expect(starts[0].status).toBe(302);

    const authorization = new URL(starts[0].location);
    const query = authorization.searchParams;
    expect(authorization.pathname).toBe("/oauth/authorize");
    expect(query.get("client_id")).toMatch(/^oc_[0-9a-z]{26}$/);
    expect(query.get("response_type")).toBe("code");
    expect(query.get("scope")).toBe(OAUTH_SCOPE);
    expect(query.get("requires_listing_source_id")).toBe("true");
    expect(query.get("code_challenge_method")).toBe("S256");

    const state = JSON.parse(
        Buffer.from(query.get("state") || "", "base64url").toString("utf8"),
    );
    expect(isSettingsUrl(new URL(state.redirect_uri))).toBe(true);
    expect(state.client_state).toBeTruthy();
    expect(query.get("code_challenge")).toBe(
        createHash("sha256").update(state.code_verifier).digest("base64url"),
    );

    // Returning in the same tab must not start OAuth again.
    await openSettingsWithoutAutoStart(page);
    expect(starts).toHaveLength(1);
    expect(await readAutoStartMarker(page)).toBe("1");
    expect(
        await page
            .locator("#ahpc-oauth-start-form")
            .evaluate((form) => /** @type {any} */ (form).submit instanceof HTMLInputElement),
    ).toBe(true);

    // The manual button still starts OAuth.
    await page.getByRole("button", CONNECT_BUTTON).click();
    await expectOauthStarts(observed, 2);
    expect(starts[1].status).toBe(302);
});

test("an OAuth failure return does not start OAuth automatically", async ({ page }) => {
    const { starts, errors } = await observeOauthStarts(page);

    await openSettingsWithoutAutoStart(page, `${SETTINGS_PATH}&ahpc_oauth=failed`);

    expect(starts).toEqual([]);
    expect(errors).toEqual([]);
    expect(await readAutoStartMarker(page)).toBeNull();
    await expect(page.getByRole("button", CONNECT_BUTTON)).toBeVisible();
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

    await openSettingsWithoutAutoStart(page);

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

    await openSettingsWithoutAutoStart(page);
    await expectOauthStarts(observed, 0);

    await page.getByRole("button", CONNECT_BUTTON).click();
    await expectOauthStarts(observed, 1);
});
