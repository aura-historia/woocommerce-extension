# Copilot instructions for this repository

## Project purpose

This repository contains a lean WordPress plugin for WooCommerce.

Its job is to automatically create and maintain exactly three WooCommerce webhooks for the installing shop:

- `product.created`
- `product.updated`
- `product.deleted`

The delivery target is the Aura Historia backend endpoint hardcoded into the plugin.

## Architecture

Keep the plugin small and follow the current structure:

- `aura-historia-partner-connect.php` — plugin header, bootstrap, and hardcoded backend base URL constant
- `includes/class-plugin.php` — WordPress/WooCommerce bootstrapping, admin UI, settings page, lightweight validation, and manual sync action
- `includes/class-webhook-manager.php` — webhook ownership, idempotent sync, backend secret registration, pause/delete behavior, drift recovery, and endpoint resolution
- `uninstall.php` — uninstall cleanup
- `tests/` — WordPress integration tests
- `.wp-env.json`, `package.json`, and `composer.json` — local development and test tooling

Do not introduce unnecessary frameworks, service containers, build systems, or abstractions unless explicitly requested.

## Behavioral rules

When changing functionality, preserve these invariants:

1. The plugin owns exactly three managed WooCommerce webhooks.
2. Webhook creation and updates must be idempotent.
3. The plugin must not create duplicate webhooks.
4. Settings changes must update the managed webhooks.
5. Manual edits or deletions of plugin-owned webhooks should be repaired by the plugin sync flow.
6. Deactivation should pause plugin-owned webhooks.
7. Uninstall should remove plugin-owned webhooks and plugin options.
8. The plugin only handles the three product topics above.
9. Keep the settings surface minimal.
10. The delivery endpoint URL should stay hardcoded in the plugin, not user-configurable in wp-admin, unless explicitly requested.
11. The WooCommerce webhook secret should be auto-generated and hidden from the merchant.
12. Merchant-configurable settings should be limited to the backend Shop ID, backend API key, and enable/disable state.

## WordPress and WooCommerce conventions

- Follow WordPress plugin best practices.
- Keep compatibility with the plugin's declared minimums, especially PHP `7.4`.
- Prefer WordPress and WooCommerce APIs over custom infrastructure.
- For webhook management, use WooCommerce's `WC_Webhook` CRUD API unless a cleanup fallback genuinely requires direct database access.
- Sanitize all settings input and escape all admin output.
- Use capability checks such as `manage_woocommerce` for admin actions.
- Use nonces for privileged actions.
- Keep public-facing behavior conservative and wordpress.org-friendly.

## Backend integration rules

- The plugin must PATCH the generated webhook secret to `/api/v1/shops/{shopId}` before activating delivery.
- The plugin must send webhook deliveries to `/api/v1/webhooks/woocommerce/{shopId}`.
- The backend API key is a separate credential from the WooCommerce webhook secret.
- The backend API key must be validated lightly in the UI before saving.
- The Shop ID must be validated lightly as a UUID before saving.
- The plugin should add `x-api-key` to outgoing webhook requests as safely as possible.
- Prefer adding `x-api-key` late in the WordPress HTTP stack if that avoids leaking the header into WooCommerce delivery logs.

## WordPress.org mindset

Assume this plugin is intended for eventual submission to WordPress.org.

That means:

- keep the plugin lean
- avoid admin spam
- avoid hidden tracking or telemetry
- avoid unnecessary remote calls beyond the configured webhook delivery behavior and local development tooling
- keep `readme.txt` accurate when behavior changes
- keep release artifacts clean and development-only files excluded

## Webhook-specific notes

- `product.deleted` follows WooCommerce's built-in semantics and is triggered when a product is trashed.
- Deleted payloads only contain an `id`.
- WooCommerce signs deliveries with `X-WC-Webhook-Signature`.
- The generated secret is a signing secret, not a standalone Authorization header.
- Do not add catalog backfill behavior unless explicitly requested.

## Local development and tests

Use the existing local workflow:

- `npm install`
- `npm run env:start`
- `npm run env:update`
- `npm test`

Notes:

- Local development uses `@wordpress/env`.
- `.wp-env.json` currently installs WooCommerce from WordPress.org.
- `AHPC_FORCE_SYNC_DELIVERY` is enabled locally so webhook delivery happens synchronously.
- Tests should avoid real outbound HTTP and should mock webhook requests.
- Tests override the hardcoded backend base URL through the `ahpc_backend_base_url` filter.
- The test suite depends on PHPUnit Polyfills installed via Composer inside `wp-env`.

When changing behavior, add or update tests when sensible, especially around:

- managed webhook creation
- backend secret registration
- outgoing `x-api-key` header behavior
- idempotent updates
- pause/delete cleanup
- drift recovery
- hardcoded endpoint behavior

## Editing guidance

Prefer targeted, minimal changes.

Before changing architecture, ask whether the change is really needed. In most cases, the right answer for this repository is the simplest WordPress-native implementation.

If you change behavior, update the relevant docs too:

- `README.md`
- `readme.txt`
