# Aura Historia Partner Connect

This repository contains a lean WordPress plugin that keeps exactly three WooCommerce webhooks in sync for your SaaS backend:

- `product.created`
- `product.updated`
- `product.deleted`

The plugin auto-registers those webhooks, repairs manual edits or deletions, exposes a small admin settings screen, pauses its webhooks on deactivation, and removes them on uninstall.

## Connection model

The plugin uses a built-in backend base URL defined in code.

Before you upload the plugin to a real store, replace the demo value of `AHPC_BACKEND_BASE_URL` in `aura-historia-partner-connect.php` with your real SaaS API base URL.

Store owners do **not** configure:

- the webhook endpoint URL
- the webhook secret

Store owners **do** configure:

- `Shop ID`
- `API key`

As soon as both values are saved and valid, the plugin starts delivery automatically.

When a valid Shop ID and API key are saved, the plugin:

1. auto-generates and stores a hidden WooCommerce webhook secret
2. uses a typed OpenAPI-backed backend client to `PATCH /api/v1/shops/{shopId}` with the configured `x-api-key`
3. keeps the WooCommerce webhooks active only if that backend sync succeeds
4. sends webhook deliveries to `/api/v1/webhooks/woocommerce/{shopId}`
5. includes the configured `x-api-key` on outgoing webhook requests

WooCommerce also signs the request body with `X-WC-Webhook-Signature` using the generated secret.

## Local development

### Prerequisites

- Docker
- PHP with Composer
- Node.js
- npm

### Start a local WordPress + WooCommerce site

1. Install PHP dependencies:
   - `composer install`
2. Install Node.js dependencies:
   - `npm install`
3. Start the local environment:
   - `npm run env:start`
4. Open WordPress:
   - `http://localhost:8888`
5. Log in with:
   - username: `admin`
   - password: `password`

The repository ships with a `.wp-env.json` file that:

- mounts this plugin into WordPress
- installs WooCommerce from WordPress.org
- enables `WP_DEBUG`
- defines `AHPC_FORCE_SYNC_DELIVERY=true` so webhook delivery happens synchronously in local development

If you want completely reproducible local or CI runs, pin the WooCommerce ZIP in `.wp-env.json` to a specific version instead of `latest-stable`.

If port `8888` is already busy, create a local `.wp-env.override.json` file and set a different `port` there.

## Manual local test flow

1. Replace the demo `AHPC_BACKEND_BASE_URL` with a test or staging backend base URL that supports both:
   - `PATCH /api/v1/shops/{shopId}`
   - `POST /api/v1/webhooks/woocommerce/{shopId}`
2. Start the local environment.
3. In WordPress admin, go to `WooCommerce > Aura Historia`.
4. Enter the Shop ID and API key from Aura Historia and save the settings.
5. Create, update, and trash a product.
6. Confirm the backend accepted the setup PATCH call and then received the webhook deliveries.

Useful WooCommerce screens:

- `WooCommerce > Settings > Advanced > Webhooks`
- `WooCommerce > Status > Logs`

## Useful commands

- `composer install` — install the runtime PHP dependencies, including Guzzle
- `npm run env:start` — start WordPress locally
- `npm run env:update` — restart and refresh remote sources
- `npm run env:stop` — stop the local environment
- `npm run env:reset` — reset the local database
- `npm run env:destroy` — remove the local environment entirely
- `npm run wp -- plugin list` — run WP-CLI commands inside the environment
- `npm run openapi:generate` — regenerate the generated Guzzle backend client from the pinned internal API spec
- `npm test` — run the PHP integration tests
- `git archive --format=zip --output aura-historia-partner-connect.zip --prefix=aura-historia-partner-connect/ HEAD` — creates a source archive; when building a release ZIP, make sure the Composer-installed `vendor/` directory is bundled too

## OpenAPI client generation

The plugin uses a generated Guzzle-based OpenAPI client so backend calls stay strongly typed as the integration grows.

The generator setup is pinned and deterministic:

- upstream source: `https://github.com/aura-historia/internal-api`
- pinned commit: `a9464cd344463588656e57ce3c52481e5f1f74ce`
- generator image: `openapitools/openapi-generator-cli:v7.22.0`
- config: `openapi/internal-api-client.config.json`
- filtered spec snapshot: `openapi/internal-api.filtered.yaml`

Regenerate the client with:

- `npm run openapi:generate`

## Tests

The test suite uses WordPress integration tests and runs inside `wp-env`.

`npm test` installs the required PHPUnit Polyfills dependency through Composer inside the `wp-env` test container and then runs the suite.

Current coverage focuses on the plugin's core contract:

- creating the three managed WooCommerce webhooks
- syncing the hidden secret to the backend
- attaching `x-api-key` to outgoing webhook requests
- updating those webhooks idempotently without duplicates
- pausing managed webhooks when requested

Run the tests with:

- `npm test`

## Behavior notes

- `product.deleted` uses WooCommerce's built-in topic and fires when a product is trashed.
- Deleted payloads only include an `id`.
- WooCommerce signs webhook requests with `X-WC-Webhook-Signature`.
- The plugin only manages future events. It does not backfill an existing catalog.

## Release notes

The repository already includes the basics you need for a WordPress.org-style release:

- plugin metadata in `aura-historia-partner-connect.php`
- a WordPress.org `readme.txt`
- `.gitattributes` rules to exclude dev-only files from release archives

The repository license is currently MIT, which is GPL-compatible. If you want the most typical WordPress.org release posture, you may still choose to align the top-level license to `GPL-2.0-or-later` before publishing.
