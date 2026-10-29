<h1 align="center">Aura Historia Partner Connect</h1>

<p align="center">
  <strong>Lean WooCommerce plugin that keeps Aura Historia product webhooks configured, synced, and repairable.</strong>
</p>

<!-- Primary badges row -->
<p align="center">
  <a href="https://aura-historia.com">
    <img src="https://img.shields.io/badge/Aura%20Historia-Website-8B4513?style=flat" alt="Aura Historia website" />
  </a>

  <a href="https://github.com/aura-historia/woocommerce-extension/actions/workflows/integrate.yml">
    <img src="https://github.com/aura-historia/woocommerce-extension/actions/workflows/integrate.yml/badge.svg" alt="CI" />
  </a>

  <img src="https://img.shields.io/badge/License-GPLv2%20or%20later-blue?style=flat" alt="GPLv2 or later" />
</p>

<!-- Tech requirements row -->
<p align="center">
  <img src="https://img.shields.io/badge/WordPress-6.5%2B-21759B?style=flat&logo=wordpress&logoColor=white" alt="WordPress 6.5+" />
  <img src="https://img.shields.io/badge/WooCommerce-required-96588A?style=flat&logo=woocommerce&logoColor=white" alt="WooCommerce required" />
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat&logo=php&logoColor=white" alt="PHP 8.2+" />
</p>

<!-- WordPress plugin live stats row -->
<p align="center">
  <a href="https://wordpress.org/plugins/aura-historia-partner-connect/">
    <img src="https://img.shields.io/wordpress/plugin/dt/aura-historia-partner-connect" alt="Downloads" />
  </a>

  <a href="https://wordpress.org/plugins/aura-historia-partner-connect/">
    <img src="https://img.shields.io/wordpress/plugin/v/aura-historia-partner-connect" alt="Version" />
  </a>

  <a href="https://wordpress.org/plugins/aura-historia-partner-connect/">
    <img src="https://img.shields.io/wordpress/plugin/installs/aura-historia-partner-connect" alt="Active Installs" />
  </a>

  <a href="https://wordpress.org/plugins/aura-historia-partner-connect/">
    <img src="https://img.shields.io/wordpress/plugin/rating/aura-historia-partner-connect" alt="Rating" />
  </a>

  <a href="https://wordpress.org/plugins/aura-historia-partner-connect/">
    <img src="https://img.shields.io/wordpress/plugin/last-updated/aura-historia-partner-connect" alt="Last Updated" />
  </a>

  <a href="https://wordpress.org/plugins/aura-historia-partner-connect/">
    <img src="https://img.shields.io/wordpress/plugin/wp-version/aura-historia-partner-connect" alt="WP Version" />
  </a>
</p>

<!-- Logo -->
<p align="center">
  <img src="https://aura-historia.com/logo-banner.png" alt="Aura Historia" />
</p>

## Overview

Aura Historia Partner Connect is a focused WordPress plugin for WooCommerce stores that already use Aura Historia.

It owns exactly three WooCommerce product webhooks and publishes supported local shipping configuration separately through Aura Historia's typed APIs for the connected ListingSource.

Managed webhook topics:

- `product.created`
- `product.updated`
- `product.deleted`

## At a glance

- creates and maintains exactly three managed WooCommerce webhooks
- keeps webhook sync idempotent and repairs manual drift
- generates the WooCommerce signing secret automatically and keeps it hidden from merchants
- starts an OAuth connection flow from the settings page instead of asking merchants to paste credentials
- exchanges the OAuth broker code for an Aura Historia access token and stores it locally
- sends the generated secret to Aura Historia before activating delivery
- injects bearer `Authorization` into signed, managed webhook deliveries late in the WordPress HTTP stack without rewriting WooCommerce's signed body
- submits published catalog listings to the async ProductListings API in background batches after connection; admission does not mean ingestion is complete
- imports supported shipping policy and item class membership through resumable Action Scheduler work after connection, local changes, periodic reconciliation and manual repair
- pauses plugin-owned webhooks on deactivation
- removes plugin-owned webhooks and plugin options on uninstall

## Compatibility

| Item | Value |
| --- | --- |
| Plugin version | `0.3.0` |
| WordPress | `6.5+` |
| WooCommerce | Required |
| PHP | `8.2+` |
| License | `GPLv2 or later` |
| Release artifact | `aura-historia-partner-connect.zip` |

## Who this is for

This plugin is for merchants and integrators who already have:

- an Aura Historia account
- a ListingSource in Aura Historia
- a WooCommerce store that should sync product events to Aura Historia

It is **not** a general-purpose WooCommerce webhook manager.

## How it works

1. A merchant installs the plugin and opens `WooCommerce > Aura Historia`.
2. If the store is not connected yet, the settings page starts the Aura Historia OAuth flow automatically, once per browser tab. After a failed or denied attempt, or when the tab cannot record the attempt in session storage, the merchant uses the Connect button instead.
3. Aura Historia redirects back to the plugin settings page with a selected `ls_` ListingSource ID and a short-lived third-party exchange code. The webapp requests source selection with `requires_listing_source_id=true` and returns the selected `ls_` ID in `listing_source_id`.
4. The plugin exchanges that code for an Aura Historia access token and stores the ListingSource ID and token locally.
5. The plugin generates a WooCommerce webhook signing secret and applies that exact secret, store currency, and language via `PUT /v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce` before enabling delivery.
6. The plugin stages the three managed WooCommerce webhooks paused with the desired signing secret before updating Aura Historia, then activates them only after provider configuration succeeds. Changes to WooCommerce currency or WordPress site language pause delivery immediately and require another provider sync. Unsupported store currencies leave the webhooks paused until corrected. The next request repeats this staged sync when a managed webhook is deleted or one of its managed fields (name, topic, status, delivery URL, secret, user or API version) no longer matches what the sync applies, for example after a merchant edit or when WooCommerce disables the webhook after repeated failed deliveries. Reactivation resets the failure count. WooCommerce's own save after each delivery, which only records the failure count, does not trigger a sync.
7. WooCommerce sends unchanged, signed live webhook bodies to `POST /v1/webhooks/woocommerce/{listingSourceId}` with bearer authorization injected late.
   WooCommerce's own `product.deleted` topic only fires when a product or variation is trashed. The managed `product.deleted` webhook, and no merchant webhook, also fires after WordPress has removed a parent product's row permanently, for example through `DELETE /wc/v3/products/{id}?force=true`, `WC_Product::delete(true)`, Empty Trash, the scheduled Trash purge or a site with Trash disabled. The body is WooCommerce's signed `{"id": <product ID>}`. A delete that fails or never runs sends nothing, and neither does a deletion made after `switch_to_blog()`. Permanently deleting a variation sends nothing. Trashing one keeps WooCommerce's standard deletion with the variation's own ID, which matches no listing, so a published parent keeps its listing. Permanently deleting a trashed product can send the identical deletion message again; it never recreates the listing. Like every WooCommerce webhook delivery, a deletion is attempted once and is not retried after a failed response.
8. After provider/webhook configuration succeeds, the plugin schedules an initial full CREATE backfill once per ListingSource. If setup or queueing fails, the initial run remains pending and retries on a later healthy bootstrap. Returning to a previously started source does not replay it automatically. Batches of up to 100 go to `POST /v1/listing-sources/{listingSourceId}/product-listings/async`; HTTP `202` means admission, not completed creation. Routine webhook repairs never start a new CREATE run unless that source's first run is still pending. The manual action resumes a pending batch with its original payload and idempotency key, leaves queued work alone, and starts a fresh run only when idle; its confirmation notice names which of these happened.
9. A separate shipping job acquires local settings, publishes the common profile and source default, then attaches existing catalog listings and scoped class/variation inputs. **Import shipping** resumes interrupted work or requests a fresh acquisition. It does not restart catalog creation. Neither job needs an open wp-admin page.

## Shipping support

Shipping assertions are advertised terms. Aura owns validation, inheritance and destination assessment. The plugin never simulates checkout, executes cost expressions, requests live carrier rates or derives packed dimensions from item dimensions.

| Local configuration | Translation and boundary |
| --- | --- |
| Ordered zones and zone `0` | Preserve WooCommerce order, including the explicit last fallback. Zones without locations cover all permitted shipping countries. All enabled method instances are considered. A completely read zone with no delivery methods has an explicit negative service; failed/incomplete reads preserve previous assertions. |
| Countries, continents and selling/shipping restrictions | ISO countries and bounded continent expansion; restrictions also constrain fallback coverage. Oversized or unsupported geographic sets remain unresolved at their original position, preventing a later zone from becoming a definite offer. |
| States | Explicit code translation for Germany, US states/DC, Canada, Australia, Austria and Switzerland. Other codes remain unresolved; WooCommerce numeric state codes are never assumed to be ISO codes. |
| Postcodes | Exact codes within one whole country; German numeric prefixes and five-digit inclusive ranges retain leading zeroes. State/postcode intersections, country-ambiguous forms and other wildcards/ranges remain unresolved. |
| Core flat rate, without class charges | A nonnegative decimal constant maps to exact minor units per shipment. `amount * [qty]`, `[qty] * amount` and `[qty]` map per item. Blank cost establishes no rate; explicit zero can establish free shipping. Fractional minor units, overflow and other expressions remain externally calculated/uncertain. |
| Core class costs | With zero/blank base and per-class calculation, pure quantity charges map to class-qualified per-item rates. The no-class code is distinct; WooCommerce ignores class charges when no shipping classes exist. Shared base plus class surcharges, fixed per-class fees and per-order maximum-class calculations need package/cart context and remain externally calculated; acquisition retains their separate meaning and diagnostic. |
| Core free shipping | Unconditional free shipping is represented directly. Minimum amounts are typed seller-order conditions where necessary; coupon, either/both, discount and tax/subtotal context remain uncertain. A coupon OR minimum rule is not reduced to a mandatory minimum. No cart total or coupon state is invented. |
| Core local pickup | Independent collection subject to confirmation. Zone restrictions, pickup locations and appointment details cannot establish universal collection eligibility or disclose a merchant address. Block pickup is likewise uncertain. |
| Third-party methods and runtime filters | Unknown eligibility and externally calculated price; settings are never enumerated or sent. Core subclasses are treated as extensions. |
| Product/variation membership | Simple/variable products use the parent WooCommerce product ID matching `sourceListingId` in catalog ingestion; variations keep `wc:variation:<id>` codes and inherited class IDs. Different available variation classes remain partial/unknown rather than choosing the first. Virtual or mixed virtual/physical products suppress inherited delivery without asserting prohibition or free shipping. External, grouped and extension product types preserve previous assertions for review. |
| Currency, tax and units | Decimal-string conversion uses supported currency exponents with checked integer bounds. Taxable shipping marks tax excluded, without calculating tax. Weight/dimension units are acquired for change detection; no unsupported dimensional rate or packing conversion is published. |

The local API surface is covered by the repository's WooCommerce version matrix (10.6.2 through 11.0 and latest). Unsupported settings and capability limits are visible in the shipping import status. Product descriptions remain separate work (#93).

### Automatic refresh and hook coverage

Local saves coalesce durable configuration or parent-product identities. They never traverse shipping configuration or call Aura. A background action rereads current facts through the same acquisition, mapper and publisher used by initial/manual imports. Product changes affect shipping only; they do not queue catalog CREATE, UPSERT or withdrawal operations.

| Local change | Notification and refresh scope |
| --- | --- |
| Zone creation, locations, precedence/order, deletion, fallback zone 0 | `woocommerce_after_shipping_zone_object_save`, `woocommerce_delete_shipping_zone` and allowlisted `woocommerce_update_non_option_setting` identifiers; shared configuration and bounded product traversal. |
| Method addition, deletion, enablement | `woocommerce_shipping_zone_method_added`, `woocommerce_shipping_zone_method_deleted`, `woocommerce_delete_shipping_zone_method`, `woocommerce_shipping_zone_method_status_toggled`; shared configuration. |
| Method settings, including a price-only change | WordPress added/updated/deleted option notifications restricted to `woocommerce_<method>_<instance>_settings`; shared configuration. Core admin and REST instance settings use this path independently of zone saves. No option values enter the dirty queue. |
| REST method ordering | Successful mutation responses on the local `/wc/v1..3/shipping/zones/<id>/methods[/<id>]` routes; shared configuration. These controllers can update ordering without a zone save or method action. |
| Shipping class creation, edits, deletion | Taxonomy-specific create/edit/delete actions; shared configuration and product traversal. |
| Class assignment, product type, virtual/physical flags, dimensions/weight, variation availability and inheritance | Product/variation CRUD saves, scoped taxonomy assignments, status transitions, allowlisted product meta and stock-status actions; only the parent product's selection and inputs. Variation deletion retains its parent identity before deletion. |
| Core settings | Narrow option notifications for currency, units, selling/shipping countries, taxes and pickup settings; shared configuration. Currency still pauses signed product deliveries until the existing provider configuration succeeds. |
| Unpublication, rescheduling, trash, deletion, restoration | Product status/deletion actions mark identities. Workers independently confirm absence/unpublication before owned removals, including products rescheduled for future publication. Failed local rechecks retain the pending removal and its bounded retries; confirmed restoration reacquires both components. |

Hook review uses the actual WooCommerce zone, AJAX and REST controller implementations, including the [zone source reference](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-shipping-zone.html). Tests exercise real CRUD/option/taxonomy/REST paths in the supported-version CI matrix. Generic order/customer events are not observed.

Every six hours, reconciliation requests a bounded full refresh and scans previously published product identities in pages of 20 for confirmed removals. It skips identities whose removal Aura refused because the catalog listing is withdrawn. Publisher actions traverse one product/component at a time with a fixed upper product-ID bound; reconciliation pages also have a fixed upper bound. This recovers missed hooks and supported data changed by extensions. Direct database writes that leave WooCommerce/object caches stale are only visible after those caches become current. Arbitrary plugin settings, dynamic checkout filters, third-party rate semantics and unsupported product types remain conservative/unsupported; reconciliation does not interpret them or treat incomplete reads as absence.

### Scheduler operation and status

WooCommerce's Action Scheduler must initialize, and WordPress cron or a server runner must execute it. Low-traffic sites and sites with `DISABLE_WP_CRON` need a real cron trigger or an Action Scheduler CLI runner, for example `wp action-scheduler run --group=aura-historia-shipping`. Work requires no open browser or wp-admin page. All shipping actions use the separate `aura-historia-shipping` group and contain identities/generations/cursors only; workers load the current Aura token from protected local settings. Valid token rotation preserves exact pending requests.

The settings page shows the oldest dirty/unfinished work, queue failures, deferred components, protected manual edits, removed products whose last shipping stays with a withdrawn listing and last confirmed API write. A queued action or an attempt is not a confirmed write; a confirmed write is not completed search indexing. If progress stops, inspect WooCommerce Scheduled Actions and the server runner. Dirty tickets survive queue failure and source-lock contention; a concurrent save cannot be erased by an older worker's acknowledgement. Periodic reconciliation and the existing capability/nonce-protected **Import shipping** action repair missed changes without catalog recreation. Automatic attempts stop after five failures; at most 100 deferred components are retained before publication stops at its checkpoint. Periodic/manual refreshes can resume this retained work.

### Publication and recovery

Each action makes at most one typed write, uses a product ID cursor with a fixed upper bound, and persists an immutable pending request before sending it. The job retries up to five times with bounded delays. It retains up to 100 deferred components, then stops at its checkpoint instead of dropping work. Each deferred component keeps one entry across refreshes: a repeated failed acquisition, such as an unsupported product type, reuses it, and no further request is sent for a component while its retained request is unconfirmed. Replay resolves that exact request before a refresh reacquires current facts. A request the API definitely rejected (a non-retryable 4xx) cannot have committed, so only its component is retained and replay reacquires current facts. Manual resumption retries those components without catalog CREATE calls. Restoring a product cancels unsent removal components and reacquires its selection and inputs. Aura rejects shipping commands for a withdrawn catalog listing with `409 PRODUCT_LISTING_WITHDRAWN`; Trash usually withdraws the listing before shipping cleanup runs. This lifecycle state is not a revision conflict and does not count toward the five conflicts that stop a run for review. A refused removal is not reported as committed and does not hold up other products: the product's identity stays indexed with a withdrawn marker, the settings page counts it, and its previous assertion remains with the withdrawn listing. Shipping never restores or recreates a listing to clear it. A later local removal of that product sends the clear again; restoring it reacquires both components and replaces the marker. A write for a published product whose listing is still withdrawn, such as a restoration the catalog has not applied yet, retries like a missing listing and then retains only its component. A complete run can still report protected manual edits and uncertain rates. Successful API writes are committed; indexing remains eventual.

Profile identity is `woocommerce:shipping:core:v1` within the authenticated source/producer/`WOOCOMMERCE` scope. Collection lookup recovers active, retired or manually managed identity; creation keys and operation request keys serve different purposes. Profile, default, selection and input updates use fresh protected revisions. Manual ownership conflicts preserve that component, while other item inputs can progress. Other revision conflicts discard rejected content and reacquire before a new operation; they never rebase old content onto a new revision.

`Shipping_Acquisition`, `Shipping_Mapper` and `Shipping_Publisher` are the shared implementation for W01 and W02. All callers must use the publisher's source queue/lock and persistent monotonic sequence. Configuration is compared during traversal and items are reread around remote revision reads. A configuration change republishes the profile before dependent work. A newer requested refresh follows an unchanged pending retry and reacquires configuration; stale wake-ups and expired lock holders cannot checkpoint over newer work. Source lifecycle revisions are internal to Aura and never supplied by the plugin.

`Shipping_Sync` calls `queue($source)` for policy/full refresh, `queue_items($source, $product_ids)` for up to 100 scoped item refreshes, and `queue_item_removal($source, $product_id)` for confirmed removals. These enqueue identities and acquire facts inside the worker; outside captures never receive a newer sequence. A busy source retains its dirty ticket for retry. Dirty in-flight targets receive another acquisition. Global configuration changes revisit the bounded product scope, with repeated configuration churn stopping for review after five restarts.

Every successful new acquisition supplies its actual microsecond `acquiredAt` and `observation: {}`. Exact retries retain their original body, keys, sequence and time. Assertions have no expiry/keepalive. No `freshUntil`/`expiresAt` is sent; protected evidence links use `reference` and `assertion`. A complete profile replacement explicitly removes obsolete zones/methods. `queue_item_removal()` rechecks confirmed local absence/unpublication and clears only owned selection/input components, with no observation. Missing pages, failed reads and unsupported products never imply removal. No arbitrary metadata creates item shipping overrides.

Source changes, explicit reconnects and deactivation fence old workers and cancel local shipping actions. Reconnect/reactivation starts a new local acquisition, retaining monotonic source ordering without relabeling old requests. Changes to the configured API target/signing context also fence old captures. Valid token rotation alone keeps the immutable retry. Expired/canceled lock holders cannot checkpoint over newer work.

Before an OAuth replacement, on deactivation and during uninstall, the plugin attempts existing protected default-clear/profile-retirement commands using the current credentials, where it has attempted publication. It records committed, protected and unconfirmed cleanup separately. This retires the common owned profile; it does not revoke source authorization or claim removal of independently managed/manual item terms. A credential replacement observed only after the old credentials were lost reports unconfirmed cleanup. Failure preserves previous assertions and their observation age. There is no automatic expiry, including after 30 days. The settings page directs merchants to review retained Aura assertions when cleanup is unconfirmed; uninstall records a bounded diagnostic if cleanup cannot be confirmed, then removes plugin-owned jobs, dirty tickets, identity indexes, checkpoints and options. No pending request contents or credentials are shown in status or diagnostics.

No new webhook, extension callback, reverse backend access, provider domain, WooCommerce REST credential or shared credential store is introduced. The signing secret authenticates webhook bodies; the locally stored Aura token authorizes outbound typed calls.

### Generated client provenance

The client and filtered spec are pinned to backend commit [`223705d48d4040d42faade2274d9dca2fa8cbd7d`](https://github.com/aura-historia/backend/commit/223705d48d4040d42faade2274d9dca2fa8cbd7d) (production release from 2026-10-25), generated with official OpenAPI Generator **7.22.0**. `tests/fixtures/shipping-imports.json` is copied from that revision; WooCommerce fixtures exercise the real PHP mapper. Later contract changes require review before updating the pin.

The generation script normalizes discriminated unions without changing their allowed branches, dispatches to the concrete generated PHP models, and preserves explicit empty objects, empty component maps and acquisition microseconds. Regenerate with `npm run openapi:generate`. An official CLI JAR of exactly the pinned version can be selected with `AHPC_OPENAPI_GENERATOR_JAR`; `AHPC_OPENAPI_SPEC_PATH` can select a locally retrieved copy of the pinned upstream spec for authenticated/offline generation.

## External service behavior

This plugin depends on the Aura Historia service.

### What gets sent

Depending on the action, the plugin may send:

- ListingSource ID (`ls_` TypeID)
- Aura Historia access token in bearer `Authorization` headers for API calls and managed live webhook deliveries
- generated WooCommerce webhook signing secret for provider configuration (never as an Authorization header)
- store language and currency
- unchanged WooCommerce signed product webhook payloads, including any descriptions supplied by WooCommerce
- published product listings during backfill: WooCommerce product ID as `sourceListingId`, canonical URL, image URL array, optional localized title, optional monetary price in exact integer currency minor units (JPY/KRW: 0 digits; explicitly mapped two-decimal currencies: 2; unknown or non-representable prices omitted), and optional availability. Backfill does **not** send description/body (tracked in [#93](https://github.com/aura-historia/woocommerce-extension/issues/93)).
- typed shipping rules, rates, pickup availability without addresses, scoped class/variation codes and product IDs, and bounded producer identity/order/acquisition metadata. Shipping payloads contain no whole settings/metadata, customers, orders, addresses, secrets or full product descriptions.

### When it gets sent

- when a merchant connects the store through Aura Historia OAuth
- when a webhook sync registers the generated WooCommerce signing secret
- when WooCommerce triggers one of the managed webhook events
- on the first successful connection to a ListingSource or when a merchant explicitly queues a full backfill, and while its batches run
- on initial/manual shipping import, local shipping/product changes, periodic reconciliation, background retries and best effort lifecycle cleanup

### Service endpoints

- `GET https://aura-historia.com/oauth/authorize`
- `GET https://aura-historia.com/api/oauth/client/redirect-broker/woocommerce`
- `GET https://api.aura-historia.com/v1/oauth/tokens/by-third-party-code/{thirdPartyCode}`
- `PUT https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce`
- `POST https://api.aura-historia.com/v1/webhooks/woocommerce/{listingSourceId}`
- `POST https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/product-listings/async`
- `GET/POST https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/shipping-profiles`
- `GET/PUT/DELETE https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/shipping-profiles/{shippingProfileId}` and `POST .../{shippingProfileId}/restore`
- `GET/PUT/DELETE https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/shipping-default`
- `GET/PATCH https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/product-listings/by-source-id/{sourceListingId}/delivery`

### Service policies

- Website: <https://aura-historia.com>
- Privacy policy: <https://aura-historia.com/privacy>
- Terms and conditions: <https://aura-historia.com/terms-and-conditions>
- Imprint / contact: <https://aura-historia.com/imprint>

## Installation

### Install on a WooCommerce shop

1. Install and activate WooCommerce.
2. Build or obtain the plugin release ZIP `aura-historia-partner-connect.zip`.
3. Upload the ZIP through `Plugins > Add New > Upload Plugin`, or extract it into `/wp-content/plugins/`.
4. Activate the plugin.
5. Open `WooCommerce > Aura Historia`.
6. Approve the Aura Historia OAuth connection when prompted.

Once OAuth completes, the plugin stores the selected ListingSource ID and access token locally, configures WooCommerce ingestion at Aura Historia, then syncs the managed webhooks.

## Configuration model

The distributed plugin defaults to the production Aura Historia API base URL:

- `https://api.aura-historia.com`

Merchants do **not** manually configure credentials in wp-admin. The OAuth flow sets and stores:

- the Aura Historia ListingSource ID
- an Aura Historia access token

Merchants also do **not** configure:

- the webhook delivery URL
- the webhook secret

### Override the backend base URL for non-production environments

For staging, local development, or custom test environments, override the base URL before the plugin runs.

Using `wp-config.php`:

```php
define( 'AHPC_BACKEND_BASE_URL', 'https://api.stage.aura-historia.com' );
```

Using a server-level environment variable:

```sh
AHPC_BACKEND_BASE_URL=https://api.stage.aura-historia.com
```

Tests can also override the URL via the `ahpc_backend_base_url` filter.

### Configure the OAuth client and broker

The plugin bundles the production public OAuth client ID `oc_01m5vyvraxecksre2zepjcza88`; installation needs no merchant credentials. The matching secret belongs only to the production webapp broker. For stage or local development, register a separate WooCommerce OAuth client and override `AHPC_OAUTH_CLIENT_ID`. Configure the same client ID and its matching client secret in that environment's webapp OAuth redirect broker. Register the broker redirect URI and authorize `product-listings:read product-listings:write listing-sources:read listing-sources:write`. Shipping needs the read scopes for identity/revision recovery; older connections may need **Reconnect with Aura Historia** to grant them. Ensure the broker selects a ListingSource and sends an `ls_` ID in its `listing_source_id` callback field; an old Shop UUID is rejected. The broker redirect URI defaults to `https://aura-historia.com/api/oauth/client/redirect-broker/woocommerce` for production; configure the stage URI separately.

An explicitly empty or malformed client ID disables connection. Without an override, the plugin uses the bundled production client ID. Both `oc_` OAuth client IDs and `ls_` ListingSource IDs must be canonical lowercase UUIDv7 TypeIDs (including the RFC UUID variant); neither old UUID Shop IDs nor noncanonical uppercase IDs are accepted.

Using `wp-config.php`:

```php
define( 'AHPC_OAUTH_CLIENT_ID', '<actual registered oc_ TypeID>' );
define( 'AHPC_OAUTH_BROKER_REDIRECT_URI', 'https://your-broker.example/api/oauth/client/redirect-broker/woocommerce' );
```

Using server-level environment variables:

```sh
AHPC_OAUTH_CLIENT_ID=<actual registered oc_ TypeID>
AHPC_OAUTH_BROKER_REDIRECT_URI=https://your-broker.example/api/oauth/client/redirect-broker/woocommerce
```

Tests can also override these values via the `ahpc_oauth_client_id` and `ahpc_oauth_broker_redirect_uri` filters.

## Local development

### Prerequisites

- Docker
- PHP with Composer
- Node.js
- npm

### Quick start

```sh
composer install
npm install
npm run env:start
```

Then open <http://localhost:8888> and log in with:

- username: `admin`
- password: `password`

### Local environment notes

The repository uses `@wordpress/env` and the checked-in `.wp-env.json`:

- installs WordPress and WooCommerce locally
- enables `WP_DEBUG`
- enables `AHPC_FORCE_SYNC_DELIVERY=true` for synchronous local webhook delivery
- overrides `AHPC_BACKEND_BASE_URL` to `https://api.stage.aura-historia.com` for local development safety
- explicitly leaves `AHPC_OAUTH_CLIENT_ID` empty so the stage environment cannot inherit the bundled production client

Before testing a stage connection, set its registered public client ID in `.wp-env.override.json`, then run `npm run env:update`:

```json
{
  "config": {
    "AHPC_OAUTH_CLIENT_ID": "<actual registered stage oc_ TypeID>"
  }
}
```

The ID must match the stage webapp broker's client. Keep the matching secret in that broker.

If you need a different port, create a local `.wp-env.override.json` file.

## Useful commands

- `npm run env:start` — start the local WordPress environment
- `npm run env:update` — refresh remote sources and restart the environment
- `npm run env:stop` — stop the environment
- `npm run env:destroy` — remove the environment entirely
- `npm run wp -- plugin list` — run WP-CLI commands inside the environment
- `npm test` — run the WordPress integration test suite
- `npm run test:e2e` — run the Playwright browser tests against the development site
- `npm run plugin:check` — build the release artifact and run WordPress Plugin Check (PCP) against the shipped plugin contents
- `npm run release:zip` — build the distributable plugin ZIP
- `npm run openapi:generate` — regenerate the typed internal API client

## Testing

The generated production API contract can be checked locally without WordPress, Docker or network access:

```sh
composer install
npm run test:contracts
```

These checks cover `/v1` request paths, non-expiring OAuth responses, bearer authorization, batch retry keys, shipping publication requests and fixture serialization. They do not replace the WooCommerce integration suite below.

The test suite runs inside `wp-env` and uses mocked outbound HTTP.

Coverage focuses on the plugin's main contract, including:

- managed webhook creation
- provider configuration before webhook activation
- OAuth connection and callback handling
- bearer `Authorization` for API calls and signed managed webhook deliveries
- idempotent updates without duplicates
- pause/delete cleanup
- product deletion through Trash, REST force delete, WooCommerce CRUD and Empty Trash, including variation and failed-delete boundaries
- drift recovery
- async ProductListings admission, exact-batch idempotent retries, stable product-ID cursor pagination, and backfill failure reporting
- actual PHP shipping mapper fixtures, generated union/empty-object/microsecond serialization, local zone/variation acquisition, stable profile identity, immutable retries, manual ownership, catalog lag, explicit removal and source coordination

Run the suite with:

```sh
npm test
```

### Browser tests

Playwright tests in `tests/e2e/` drive the rendered settings page in Chromium against the running development site. They cover the automatic OAuth start, its loop protection and the manual Connect fallback. The tests capture the OAuth authorization redirect and block other origins, so no request leaves the local site.

The development site must be disconnected and have a valid `oc_` `AHPC_OAUTH_CLIENT_ID`. Any format-valid ID works because the authorization request is never sent. Set it in `.wp-env.override.json` as shown above and run `npm run env:update`. A failed OAuth attempt stores an error that disables the automatic start until a connection succeeds. Clear it before running the tests:

```sh
npm run wp -- option delete ahpc_last_oauth_error
```

Then install Chromium (add `--with-deps` on Linux hosts without its system libraries) and run the tests:

```sh
npx playwright install chromium
npm run test:e2e
```

### Plugin Check (PCP)

After the local environment is running, run WordPress Plugin Check against the generated release tree so the results reflect the distributable plugin rather than repository-only development files:

```sh
npm run plugin:check
```

## Architecture

| Path | Responsibility |
| --- | --- |
| `aura-historia-partner-connect.php` | Plugin header, bootstrap, constants, hardcoded backend base URL |
| `includes/class-plugin.php` | WordPress/WooCommerce bootstrap, admin UI, settings handling, manual actions |
| `includes/class-webhook-manager.php` | Webhook ownership, idempotent sync, backend registration, cleanup, drift recovery |
| `includes/class-type-id-validator.php` | Strict canonical UUIDv7 `ls_` and `oc_` TypeID validation |
| `includes/class-product-backfill.php` | Background catalog CREATE admission via Action Scheduler |
| `includes/class-shipping-acquisition.php` | Allowlisted local shipping configuration and item reads |
| `includes/class-shipping-mapper.php` | Pure conservative mapping to Aura shipping types |
| `includes/class-shipping-publisher.php` | Source ordering, immutable retries, bounded shipping publication and protected cleanup |
| `includes/class-shipping-sync.php` | Local notifications, durable dirty scopes, periodic reconciliation and lifecycle fencing |
| `includes/class-backend-api-client.php` | Typed Aura Historia API integration |
| `uninstall.php` | Uninstall cleanup |
| `tests/` | WordPress integration tests |

## Release process

Build the release ZIP with:

```sh
npm run release:zip
```

The release build:

- creates a clean plugin tree in `build-release/`
- copies an explicit allowlist of runtime files, excluding local configuration and credentials
- installs production Composer dependencies from the committed lock file into that tree
- removes development-only files from the artifact
- excludes the WordPress.org directory assets kept in `assets/`
- writes `aura-historia-partner-connect.zip` to the project root

For WordPress.org submission, use the clean release tree or equivalent release contents rather than the raw development repository checkout.

The production OAuth registration and broker credentials are provisioned. The current webapp already selects ListingSources and uses `/v1/oauth/token`; the plugin requests `requires_listing_source_id=true` and consumes `listing_source_id`. Preserve the matching production client ID and server-side secret when redeploying the webapp. The broker uses the production client secret through its server-side Worker binding; never include that secret in the plugin.

Before publishing `0.3.0`, complete the deferred real-store connection check: consent and callback, provider configuration, three active signed product webhooks, initial catalog admission, shipping publication and disconnect/deactivation cleanup. The release-preparation checks deliberately do not install WordPress or execute that flow. A matching Git version tag triggers the WordPress.org deployment workflow; `WP_ORG_USERNAME` and `WP_ORG_PASSWORD` must be configured in repository secrets.

## License

This project is licensed under **GPLv2 or later**.
