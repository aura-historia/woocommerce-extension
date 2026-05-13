=== Aura Historia Partner Connect ===
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 7.4
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
* uses a built-in delivery endpoint configured in code
* updates those webhooks when the signing secret or enabled state changes
* repairs plugin-owned webhooks after manual edits or deletions
* pauses plugin-owned webhooks on deactivation
* removes plugin-owned webhooks on uninstall
* includes a small admin screen under WooCommerce
* includes local development support via `@wordpress/env`

Important notes:

* Store owners do not configure the delivery endpoint URL in wp-admin.
* WooCommerce uses the configured secret to generate the `X-WC-Webhook-Signature` header.
* `product.deleted` is WooCommerce's built-in delete topic and fires when a product is trashed.
* Deleted payloads only include an `id`.
* Enabling the plugin to deliver events sends product data to your configured external endpoint.

== Installation ==

1. Install and activate WooCommerce.
2. Before uploading the plugin, replace the demo `AHPC_WEBHOOK_ENDPOINT_URL` value in `aura-historia-partner-connect.php` with your real SaaS webhook endpoint.
3. Upload this plugin to `/wp-content/plugins/` or install it through the WordPress plugin screen.
4. Activate the plugin.
5. Go to `WooCommerce > Partner Connect`.
6. Enter the webhook signing secret used by your backend.
7. Enable delivery and save the settings.

== Frequently Asked Questions ==

= Does this backfill my existing products? =

No. The plugin only manages future WooCommerce webhook events.

= Which events are sent? =

Only `product.created`, `product.updated`, and `product.deleted`.

= What happens when the plugin is disabled? =

It pauses the plugin-owned webhooks so WooCommerce stops sending deliveries.

= Can I use an API key from my backend as the secret? =

Yes, if your backend uses that value as the shared signing secret for validating the WooCommerce `X-WC-Webhook-Signature` header. The value is not sent as a standalone API key or `Authorization` header.

= Can I test this locally? =

Yes. The repository includes `@wordpress/env` configuration and WordPress integration tests.

== Changelog ==

= 0.1.0 =
* Initial release.
