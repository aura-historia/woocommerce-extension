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
- imports supported shipping policy and item class membership through separate, resumable Action Scheduler work after connection and on manual request
- pauses plugin-owned webhooks on deactivation
- removes plugin-owned webhooks and plugin options on uninstall

## Compatibility

| Item | Value |
| --- | --- |
| Plugin version | `0.2.0` |
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
2. If the store is not connected yet, the settings page starts the Aura Historia OAuth flow automatically; the Connect button is available as a fallback.
3. Aura Historia redirects back to the plugin settings page with a selected `ls_` ListingSource ID and a short-lived third-party exchange code. The broker may use the external callback field `partner_shop_id`, but its value must be an `ls_` ID.
4. The plugin exchanges that code for an Aura Historia access token and stores the ListingSource ID and token locally.
5. The plugin generates a WooCommerce webhook signing secret and applies that exact secret, store currency, and language via `PUT /api/v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce` before enabling delivery.
6. The plugin stages the three managed WooCommerce webhooks paused with the desired signing secret before updating Aura Historia, then activates them only after provider configuration succeeds. Changes to WooCommerce currency or WordPress site language pause delivery immediately and require another provider sync. Unsupported store currencies leave the webhooks paused until corrected.
7. WooCommerce sends unchanged, signed live webhook bodies to `POST /api/v1/webhooks/woocommerce/{listingSourceId}` with bearer authorization injected late.
8. After provider/webhook configuration succeeds, the plugin schedules an initial full CREATE backfill once per ListingSource. If setup or queueing fails, the initial run remains pending and retries on a later healthy bootstrap. Returning to a previously started source does not replay it automatically. Batches of up to 100 go to `POST /api/v1/listing-sources/{listingSourceId}/product-listings/async`; HTTP `202` means admission, not completed creation. Routine webhook repairs never start a new CREATE run unless that source's first run is still pending. The manual action resumes a pending batch with its original payload and idempotency key, leaves queued work alone, and starts a fresh run only when idle.
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

The local API surface is covered by the repository's WooCommerce version matrix (10.6.2 through 11.0 and latest). Unsupported settings and capability limits are visible in the shipping import status. Product descriptions (#93) and ongoing shipping hooks/reconciliation (#98) remain separate work.

### Publication and recovery

Each action makes at most one typed write, uses a product ID cursor with a fixed upper bound, and persists an immutable pending request before sending it. The job retries up to five times with bounded delays. It retains up to 100 deferred components, then stops at its checkpoint instead of dropping work. Manual resumption retries those components without catalog CREATE calls. Restoring a product cancels unsent removal components and reacquires its selection and inputs. A complete run can still report protected manual edits and uncertain rates. Successful API writes are committed; indexing remains eventual.

Profile identity is `woocommerce:shipping:core:v1` within the authenticated source/producer/`WOOCOMMERCE` scope. Collection lookup recovers active, retired or manually managed identity; creation keys and operation request keys serve different purposes. Profile, default, selection and input updates use fresh protected revisions. Manual ownership conflicts preserve that component, while other item inputs can progress. Other conflicts discard rejected content and reacquire before a new operation; they never rebase old content onto a new revision.

`Shipping_Acquisition`, `Shipping_Mapper` and `Shipping_Publisher` are the shared implementation for W01 and W02. All callers must use the publisher's source queue/lock and persistent monotonic sequence. Configuration is compared during traversal and items are reread around remote revision reads. A configuration change republishes the profile before dependent work. A newer requested refresh follows an unchanged pending retry and reacquires configuration; stale wake-ups and expired lock holders cannot checkpoint over newer work. Source lifecycle revisions are internal to Aura and never supplied by the plugin.

W02 can call `queue($source)` for policy/full refresh, `queue_items($source, $product_ids)` for up to 100 scoped item refreshes, and `queue_item_removal($source, $product_id)` for confirmed removals. These enqueue identities and acquire facts inside the worker; outside captures never receive a newer sequence. A busy source must be retried. Dirty in-flight targets receive another acquisition. Global configuration changes revisit the bounded product scope, with repeated configuration churn stopping for review after five restarts.

Every successful new acquisition supplies its actual microsecond `acquiredAt` and `observation: {}`. Exact retries retain their original body, keys, sequence and time. Assertions have no expiry/keepalive. No `freshUntil`/`expiresAt` is sent; evidence uses only `verifiedAt`. A complete profile replacement explicitly removes obsolete zones/methods. `queue_item_removal()` rechecks confirmed local absence/unpublication and clears only owned selection/input components, with no observation. Missing pages, failed reads and unsupported products never imply removal. No arbitrary metadata creates item shipping overrides.

Deactivation cancels local shipping actions and retains checkpoints; uninstall removes local shipping state. Neither event promises immediate backend withdrawal: Aura assertions continue until updated, withdrawn or their source loses authority. No new webhook, extension callback, reverse backend access, provider domain, WooCommerce REST credential or shared credential store is introduced. The signing secret authenticates webhook bodies; the locally stored Aura token authorizes outbound typed calls.

### Generated client provenance

The client and filtered spec are pinned to backend commit [`8f39fcd549ebc49608bb2722bb219e623c6742e2`](https://github.com/aura-historia/backend/commit/8f39fcd549ebc49608bb2722bb219e623c6742e2) (PR #2146), generated with official OpenAPI Generator **7.22.0**. `tests/fixtures/shipping-imports.json` is copied from that revision; WooCommerce fixtures exercise the real PHP mapper. Later contract changes require review before updating the pin.

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
- on initial/manual shipping import and its background retries

### Service endpoints

- `GET https://aura-historia.com/oauth/authorize`
- `GET https://aura-historia.com/api/oauth/client/redirect-broker/woocommerce`
- `GET https://api.aura-historia.com/api/v1/oauth/tokens/by-third-party-code/{thirdPartyCode}`
- `PUT https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce`
- `POST https://api.aura-historia.com/api/v1/webhooks/woocommerce/{listingSourceId}`
- `POST https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/product-listings/async`
- `GET/POST https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/shipping-profiles`
- `GET/PUT/DELETE https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/shipping-profiles/{shippingProfileId}` and `POST .../{shippingProfileId}/restore`
- `GET/PUT/DELETE https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/shipping-default`
- `GET/PUT https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/product-listings/by-source-id/{sourceListingId}/delivery`

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

The plugin does **not** ship a production or stage OAuth client ID. Before connecting, register a WooCommerce OAuth client in each environment and configure its **actual** `oc_` TypeID as `AHPC_OAUTH_CLIENT_ID` in the plugin deployment. Configure the same client ID and its matching client secret in that environment's webapp OAuth redirect broker. Register the broker redirect URI and authorize `product-listings:read product-listings:write listing-sources:read listing-sources:write`. Shipping needs the read scopes for identity/revision recovery; older connections may need **Reconnect with Aura Historia** to grant them. Ensure the broker selects a ListingSource and sends an `ls_` ID in its `partner_shop_id` callback field; an old Shop UUID is rejected. The broker redirect URI defaults to `https://aura-historia.com/api/oauth/client/redirect-broker/woocommerce` for production; configure the stage URI separately.

An absent or malformed client ID disables connection rather than sending a fabricated value. Both `oc_` OAuth client IDs and `ls_` ListingSource IDs must be canonical lowercase UUIDv7 TypeIDs (including the RFC UUID variant); neither old UUID Shop IDs nor noncanonical uppercase IDs are accepted.

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
- overrides `AHPC_BACKEND_BASE_URL` to `https://api.stage.aura-historia.com` for local development safety; set `AHPC_OAUTH_CLIENT_ID` to a real registered stage client before testing a connection

If you need a different port, create a local `.wp-env.override.json` file.

## Useful commands

- `npm run env:start` — start the local WordPress environment
- `npm run env:update` — refresh remote sources and restart the environment
- `npm run env:stop` — stop the environment
- `npm run env:destroy` — remove the environment entirely
- `npm run wp -- plugin list` — run WP-CLI commands inside the environment
- `npm test` — run the WordPress integration test suite
- `npm run plugin:check` — build the release artifact and run WordPress Plugin Check (PCP) against the shipped plugin contents
- `npm run release:zip` — build the distributable plugin ZIP
- `npm run openapi:generate` — regenerate the typed internal API client

## Testing

The test suite runs inside `wp-env` and uses mocked outbound HTTP.

Coverage focuses on the plugin's main contract, including:

- managed webhook creation
- provider configuration before webhook activation
- OAuth connection and callback handling
- bearer `Authorization` for API calls and signed managed webhook deliveries
- idempotent updates without duplicates
- pause/delete cleanup
- drift recovery
- async ProductListings admission, exact-batch idempotent retries, stable product-ID cursor pagination, and backfill failure reporting
- actual PHP shipping mapper fixtures, generated union/empty-object/microsecond serialization, local zone/variation acquisition, stable profile identity, immutable retries, manual ownership, catalog lag, explicit removal and source coordination

Run the suite with:

```sh
npm test
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
| `includes/class-shipping-publisher.php` | Source ordering, immutable retries, bounded initial/manual publication |
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
- installs production Composer dependencies into that tree
- removes development-only files from the artifact
- excludes the WordPress.org directory assets kept in `assets/`
- writes `aura-historia-partner-connect.zip` to the project root

For WordPress.org submission, use the clean release tree or equivalent release contents rather than the raw development repository checkout.

## License

This project is licensed under **GPLv2 or later**.
