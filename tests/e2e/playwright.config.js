// @ts-check
const os = require("node:os");
const path = require("node:path");
const { defineConfig, devices } = require("@playwright/test");
const { BASE_URL } = require("./helpers");

module.exports = defineConfig({
    testDir: __dirname,
    globalSetup: require.resolve("./global-setup.js"),
    outputDir: path.join(__dirname, "../../test-results/e2e"),
    // The tests share one WordPress site, so run them one at a time.
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: !!process.env.CI,
    reporter: process.env.CI ? [["github"], ["list"]] : "list",
    use: {
        baseURL: BASE_URL,
        // Keep the admin session outside the plugin folder that wp-env serves.
        storageState: path.join(os.tmpdir(), "ahpc-e2e-admin.json"),
        trace: "retain-on-failure",
    },
    projects: [
        {
            name: "chromium",
            use: { ...devices["Desktop Chrome"] },
        },
    ],
});
