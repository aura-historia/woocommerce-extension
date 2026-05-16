=== Aura Historia Partner Connect ===
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT
Tags: woocommerce, webhooks, products, integration

Automatically creates and maintains the WooCommerce product webhooks your Aura Historia backend needs.

== Description ==

This plugin owns exactly three WooCommerce webhooks and keeps them in sync with your configured settings:

* `product.created`
* `product.updated`
* `product.deleted`

Features:

* auto-registers the managed WooCommerce webhooks
* uses a built-in backend base URL configured in code
* lets the merchant configure only a Shop ID and API key, then starts delivery automatically once both are valid
* auto-generates the WooCommerce webhook secret and keeps it hidden from the merchant
* PATCHes the generated secret to `/api/v1/shops/{shopId}` before activating delivery
* sends webhook deliveries to `/api/v1/webhooks/woocommerce/{shopId}`
* includes `x-api-key` on outgoing webhook requests
* asynchronously backfills all existing products to `PUT /api/v1/shops/{shopId}/products` in batches of 100 via Action Scheduler when a valid connection is configured
* repairs plugin-owned webhooks after manual edits or deletions
* pauses plugin-owned webhooks on deactivation
* removes plugin-owned webhooks on uninstall
* includes a small admin screen under WooCommerce
* includes local development support via `@wordpress/env`

Important notes:

* Store owners do not configure the delivery endpoint URL in wp-admin.
* Store owners do not configure the webhook secret in wp-admin.
* WooCommerce uses the generated secret to create the `X-WC-Webhook-Signature` header.
* `product.deleted` is WooCommerce's built-in delete topic and fires when a product is trashed.
* Deleted payloads only include an `id`.
* Saving a valid Shop ID and API key sends product data to your configured external endpoint.
* The current backfill status is shown on `WooCommerce > Aura Historia`.
* Merchants can manually queue a fresh full product backfill from `WooCommerce > Aura Historia`.

== Installation ==

1. Install and activate WooCommerce.
2. Upload this plugin to `/wp-content/plugins/` or install it through the WordPress plugin screen.
3. To point the plugin at the production backend, add the following line to `wp-config.php` (or set the `AHPC_BACKEND_BASE_URL` PHP environment variable in your server environment):
   `define( 'AHPC_BACKEND_BASE_URL', 'https://api.aura-historia.com' );`
   The plugin defaults to the development/staging URL when neither override is present.
4. Activate the plugin.
5. Go to `WooCommerce > Aura Historia`.
6. Enter the Shop ID from Aura Historia for this store.
7. Enter the Aura Historia API key for that store.
8. Save the settings. Delivery starts automatically once both values are valid.

== Frequently Asked Questions ==

= Does this backfill my existing products? =

Yes. When a valid Shop ID and API key are saved, the plugin automatically backfills all existing WooCommerce products to `PUT /api/v1/shops/{shopId}/products`. Products are sent in batches of 100 via Action Scheduler (bundled with WooCommerce) so large catalogs do not block the page. Batches that fail are retried automatically by Action Scheduler. The backfill is restarted whenever valid connection settings are saved, the current backfill status is shown on `WooCommerce > Aura Historia`, and merchants can manually queue a fresh full backfill from that screen.

= Which events are sent? =

Only `product.created`, `product.updated`, and `product.deleted`.

= What happens when the plugin is disabled? =

It pauses the plugin-owned webhooks so WooCommerce stops sending deliveries.

= Do merchants need to enter a webhook secret? =

No. The plugin generates the WooCommerce webhook secret automatically and keeps it hidden.

= Can I use my backend API key here? =

Yes. The plugin sends the configured API key in the `x-api-key` header for the shop registration PATCH call and for outgoing webhook requests.

= Can I test this locally? =

Yes. The repository includes `@wordpress/env` configuration and WordPress integration tests.

== Changelog ==

= 0.1.0 =
* Initial release.
