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

Its job is intentionally narrow: it owns exactly three WooCommerce product webhooks and keeps them correctly configured for the connected Aura Historia ListingSource without creating duplicates or exposing unnecessary settings.

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
6. The plugin creates or repairs the three managed WooCommerce webhooks only after provider configuration succeeds.
7. WooCommerce sends unchanged, signed live webhook bodies to `POST /api/v1/webhooks/woocommerce/{listingSourceId}` with bearer authorization injected late.
8. The plugin can also submit published products to `POST /api/v1/listing-sources/{listingSourceId}/product-listings/async` in batches of up to 100. HTTP `202` reports queue admission, not completed creation; no `submissionId` polling occurs.

## External service behavior

This plugin depends on the Aura Historia service.

### What gets sent

Depending on the action, the plugin may send:

- ListingSource ID (`ls_` TypeID)
- Aura Historia access token in bearer `Authorization` headers for API calls and managed live webhook deliveries
- generated WooCommerce webhook signing secret for provider configuration (never as an Authorization header)
- store language and currency
- unchanged WooCommerce signed product webhook payloads, including any descriptions supplied by WooCommerce
- published product listings during backfill: WooCommerce product ID as `sourceListingId`, canonical URL, image URL array, optional localized title, optional monetary price in integer minor units, and optional availability. Backfill does **not** send description/body (tracked in [#93](https://github.com/aura-historia/woocommerce-extension/issues/93)).

### When it gets sent

- when a merchant connects the store through Aura Historia OAuth
- when a webhook sync registers the generated WooCommerce signing secret
- when WooCommerce triggers one of the managed webhook events
- when the plugin schedules or processes a product backfill

### Service endpoints

- `GET https://aura-historia.com/oauth/authorize`
- `GET https://aura-historia.com/api/oauth/client/redirect-broker/woocommerce`
- `GET https://api.aura-historia.com/api/v1/oauth/tokens/by-third-party-code/{thirdPartyCode}`
- `PUT https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce`
- `POST https://api.aura-historia.com/api/v1/webhooks/woocommerce/{listingSourceId}`
- `POST https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/product-listings/async`

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

The plugin does **not** ship a production or stage OAuth client ID. Before connecting, register a WooCommerce OAuth client in each environment and configure its **actual** `oc_` TypeID as `AHPC_OAUTH_CLIENT_ID` in the plugin deployment. Configure the same client ID and its matching client secret in that environment's webapp OAuth redirect broker. Register the broker redirect URI and authorize exactly `product-listings:write listing-sources:write`. Ensure the broker selects a ListingSource and sends an `ls_` ID in its `partner_shop_id` callback field; an old Shop UUID is rejected. The broker redirect URI defaults to `https://aura-historia.com/api/oauth/client/redirect-broker/woocommerce` for production; configure the stage URI separately.

An absent or malformed client ID disables connection rather than sending a fabricated value.

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
- async ProductListings admission, exact-batch idempotent retries, and backfill failure reporting

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
| `includes/class-product-backfill.php` | Background catalog resend via Action Scheduler |
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
