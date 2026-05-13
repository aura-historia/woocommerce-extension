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
* lets the merchant configure only a Shop ID and API key
* auto-generates the WooCommerce webhook secret and keeps it hidden from the merchant
* PATCHes the generated secret to `/api/v1/shops/{shopId}` before activating delivery
* sends webhook deliveries to `/api/v1/webhooks/woocommerce/{shopId}`
* includes `x-api-key` on outgoing webhook requests
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
* Enabling the plugin to deliver events sends product data to your configured external endpoint.

== Installation ==

1. Install and activate WooCommerce.
2. Before uploading the plugin, replace the demo `AHPC_BACKEND_BASE_URL` value in `aura-historia-partner-connect.php` with your real SaaS API base URL.
3. Upload this plugin to `/wp-content/plugins/` or install it through the WordPress plugin screen.
4. Activate the plugin.
5. Go to `WooCommerce > Partner Connect`.
6. Enter the Shop ID UUID from your backend.
7. Enter the Aura Historia API key for that shop.
8. Enable delivery and save the settings.

== Frequently Asked Questions ==

= Does this backfill my existing products? =

No. The plugin only manages future WooCommerce webhook events.

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
