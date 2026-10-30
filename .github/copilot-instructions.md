# Copilot instructions for this repository

## Project purpose

This repository contains a lean WordPress plugin for WooCommerce.

It automatically creates and maintains exactly three WooCommerce webhooks for the installing shop:

- `product.created`
- `product.updated`
- `product.deleted`

The delivery target is the Aura Historia backend endpoint hardcoded into the plugin. Separate Action Scheduler jobs submit the initial catalog backfill and initial/manual shipping imports through the typed Aura API client.

## Architecture

Keep the plugin small and follow the current structure:

- `aura-historia-partner-connect.php` — plugin header, bootstrap, and hardcoded backend base URL constant
- `includes/class-plugin.php` — WordPress/WooCommerce bootstrapping, admin UI, settings page, lightweight validation, and manual sync action
- `includes/class-webhook-manager.php` — webhook ownership, idempotent sync, background reconciliation with bounded retries, backend secret registration, pause/delete behavior, drift recovery, and endpoint resolution
- `includes/class-product-backfill.php` — separate asynchronous catalog CREATE admission
- `includes/class-option-lease.php` — expiring option-row leases and lease-fenced checkpoints; backfill and webhook sync each use their own lease
- `includes/class-shipping-{acquisition,mapper,publisher}.php` — local shipping reads, conservative mapping and resumable typed publication shared with ongoing reconciliation
- `includes/class-backend-api-client.php` — typed backend requests using the generated client
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
12. Obtain the ListingSource ID and backend access token through OAuth; keep credentials hidden and the signing secret generated locally.

## WordPress and WooCommerce conventions

- Follow WordPress plugin best practices.
- Keep compatibility with the plugin's declared minimums, especially PHP `8.2`.
- Prefer WordPress and WooCommerce APIs over custom infrastructure.
- For webhook management, use WooCommerce's `WC_Webhook` CRUD API unless a cleanup fallback genuinely requires direct database access.
- Sanitize all settings input and escape all admin output.
- Use capability checks such as `manage_woocommerce` for admin actions.
- Use nonces for privileged actions.
- Keep public-facing behavior conservative and wordpress.org-friendly.

## Backend integration rules

- PUT the generated signing secret and store locale to `/v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce` before activating delivery.
- The plugin must send webhook deliveries to `/v1/webhooks/woocommerce/{listingSourceId}`.
- The backend access token is a separate credential from the WooCommerce webhook secret.
- Validate the OAuth access token and required scopes before saving.
- Request ListingSource selection with `requires_listing_source_id=true` and consume the broker's `listing_source_id` callback parameter.
- The bundled public OAuth client ID is for production. Stage and local connections require a separately configured client ID matching their webapp broker; keep client secrets in the broker only.
- Validate canonical UUIDv7 `ls_` ListingSource and `oc_` OAuth client TypeIDs with the shared validator.
- The plugin should add the access token to the webhook bearer `Authorization` header as safely as possible.
- Prefer adding the access token to the webhook bearer `Authorization` header late in the WordPress HTTP stack if that avoids leaking the header into WooCommerce delivery logs.

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

- `product.deleted` follows WooCommerce's built-in semantics and is triggered when a product is trashed. The managed `product.deleted` webhook, and no merchant webhook, is also subscribed to `Webhook_Manager::PRODUCT_DELETED_HOOK`, which fires only from `deleted_post` for a parent `product` row. Variations, auto-drafts, failed deletes and deletions after `switch_to_blog()` never fire it. Deliveries keep WooCommerce's single attempt.
- Deleted payloads only contain an `id`.
- WooCommerce signs deliveries with `X-WC-Webhook-Signature`.
- The generated secret is a signing secret, not a standalone Authorization header.
- Keep catalog backfill separate from live webhooks and shipping publication. Shipping must never restart catalog CREATE work.
- Ordinary requests never call the backend. Automatic webhook reconciliation runs in the `Webhook_Manager::SYNC_ACTION_HOOK` Action Scheduler action, fenced by a wake-up token per attempt; only interactive admin actions sync synchronously. Webhook reconciliation and backfill batches back off exponentially on retryable failures, then retry every 6 hours and never give up on their own; a retried backfill keeps its snapshot and key. Deactivation retires both wake-ups.
- Backfill batches and webhook syncs are serialized by their own `Option_Lease`. Write their checkpoints through the lease, recheck it and the connection before outbound calls, and keep backfill action arguments to the source, page and wake-up token.
- Shipping uses the pinned API contract, stable profile identity, immutable retry requests, actual acquisition times and `observation: {}`; do not add expiry fields or a backend-to-plugin callback.
- Failed or incomplete shipping reads preserve existing assertions. Respect component ownership and reacquire after conflicts; use the shared publisher source lock and ordering for all shipping work.
- `PRODUCT_LISTING_WITHDRAWN` is a catalog lifecycle state, not a revision conflict. Never restore or recreate a listing to clear its shipping, and never report a refused removal as committed.

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
- outgoing webhook bearer `Authorization` access token header behavior
- idempotent updates
- pause/delete cleanup
- drift recovery
- hardcoded endpoint behavior

## Editing guidance

Prefer targeted, minimal changes.

Before changing architecture, ask whether the change is really needed. In most cases, the right answer for this repository is the simplest WordPress-native implementation.

For PHP syntax compatibility, do not rewrite `(new Foo())->method()` or `(new Foo())->property` into `new Foo()->method()` / `new Foo()->property`. Keep the wrapping parentheses around inline `new` expressions when dereferencing them.

If you change behavior, update the relevant docs too:

- `README.md`
- `readme.txt`
