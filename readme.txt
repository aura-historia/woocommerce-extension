=== Aura Historia Partner Connect ===
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
Contributors: aurahistoria
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: woocommerce, webhooks, product-sync, catalog-sync, aura-historia

Connects WooCommerce to Aura Historia by creating and maintaining the product webhooks your store needs.

== Description ==

Aura Historia Partner Connect connects a WooCommerce store to Aura Historia.

After you connect the store through Aura Historia OAuth, the plugin automatically:

* creates and maintains exactly three WooCommerce product webhooks:
  * `product.created`
  * `product.updated`
  * `product.deleted`
* keeps those managed webhooks in sync without creating duplicates
* repairs plugin-owned webhooks after manual edits or deletion
* generates and stores the WooCommerce webhook signing secret automatically
* sends webhook deliveries to Aura Historia using the built-in endpoint pattern
* pauses plugin-owned webhooks on deactivation
* removes plugin-owned webhooks and plugin options on uninstall
* can re-send the current catalog in the background after a successful connection

The plugin keeps the settings surface intentionally small. Merchants do not manually enter Aura Historia credentials in wp-admin. The OAuth flow sets and stores:

* Shop ID
* Aura Historia access token

Merchants do not enter:

* a webhook delivery URL
* a webhook secret

This plugin is intended for merchants who already use Aura Historia. Once OAuth completes, the plugin sends product data to Aura Historia so the connected catalog can stay in sync.

== External services ==

This plugin connects to Aura Historia, a hosted service required for the plugin to work.

It sends data after a merchant connects the store through Aura Historia OAuth, and later when WooCommerce sends managed webhook events or the plugin runs a product backfill.

The service is used to:

* register the generated WooCommerce webhook secret and current store locale details
* receive ongoing product webhook deliveries
* receive background product backfill batches

Data sent to the service may include:

* Shop ID
* Aura Historia access token in the bearer `Authorization` header for backend API calls
* Aura Historia access token in the `x-api-key` header for WooCommerce webhook deliveries
* generated WooCommerce webhook secret
* store language and currency
* product webhook payloads for `product.created`, `product.updated`, and `product.deleted`
* existing product data during an automatic or manual backfill

Service endpoints:

* `GET https://aura-historia.com/oauth/authorize`
* `GET https://aura-historia.com/api/oauth/client/redirect-broker`
* `GET https://api.aura-historia.com/api/v1/oauth/tokens/by-third-party-code/{thirdPartyCode}`
* `PATCH https://api.aura-historia.com/api/v1/shops/{shopId}`
* `POST https://api.aura-historia.com/api/v1/webhooks/woocommerce/{shopId}`
* `PUT https://api.aura-historia.com/api/v1/shops/{shopId}/products`

Service provider and policies:

* [Website](https://aura-historia.com)
* [Privacy Policy](https://aura-historia.com/privacy)
* [Terms and conditions](https://aura-historia.com/terms-and-conditions)
* [Imprint](https://aura-historia.com/imprint)

== Installation ==

1. Install and activate WooCommerce.
2. Install Aura Historia Partner Connect via the WordPress Plugin Directory.
3. Activate the plugin.
4. Go to `WooCommerce > Aura Historia`.
5. Approve the Aura Historia OAuth connection when prompted.

After OAuth completes, the plugin syncs the managed webhooks automatically and starts sending product updates to Aura Historia.

== Frequently Asked Questions ==

= Do I need an Aura Historia account? =

Yes. This plugin is intended for merchants who already use Aura Historia and have a partner shop they can authorize during OAuth.

= Which WooCommerce events are sent? =

Only `product.created`, `product.updated`, and `product.deleted`.

= What data is sent to Aura Historia? =

After configuration, the plugin sends the generated WooCommerce webhook secret, store language and currency, product webhook payloads, and existing product data during backfill. See the `External services` section above for the full overview.

= Can I change the delivery URL or webhook secret in wp-admin? =

No. The plugin keeps the Aura Historia delivery URL built in and generates the WooCommerce webhook secret automatically.

= What happens if I edit or delete one of the managed webhooks? =

The plugin repairs plugin-owned webhooks during its sync flow so the required configuration is restored.

= Does this plugin backfill existing products? =

Yes. After a successful connection, the plugin can send the current catalog to Aura Historia in the background. Merchants can also manually restart that backfill from `WooCommerce > Aura Historia`.

= What happens when the plugin is disabled? =

Plugin-owned webhooks are paused on deactivation so WooCommerce stops sending deliveries.

== Changelog ==

= 0.1.0 =
* Initial release.
