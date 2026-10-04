=== Aura Historia Partner Connect ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.2.0
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
* queues the first published-product CREATE backfill once per ListingSource after successful configuration; failed setup/queueing leaves that first run pending for later retry, while ordinary repairs never start a fresh run

The plugin keeps the settings surface intentionally small. Merchants do not manually enter Aura Historia credentials in wp-admin. The OAuth flow sets and stores:

* ListingSource ID (`ls_` TypeID)
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

* ListingSource ID (`ls_` TypeID)
* Aura Historia access token in the bearer `Authorization` header for backend API calls
* Aura Historia access token in a bearer `Authorization` header on managed live webhook deliveries
* generated WooCommerce webhook secret
* store language and currency
* unchanged signed WooCommerce webhook payloads for `product.created`, `product.updated`, and `product.deleted`, including any WooCommerce descriptions
* published products during backfill: WooCommerce product ID as `sourceListingId`, canonical URL, image URL array, optional localized title, optional tagged monetary price (exact integer currency minor units; non-representable prices omitted), and optional availability. Backfill does not send description/body yet (follow-up #93).

Service endpoints:

* `GET https://aura-historia.com/oauth/authorize`
* `GET https://aura-historia.com/api/oauth/client/redirect-broker/woocommerce`
* `GET https://api.aura-historia.com/api/v1/oauth/tokens/by-third-party-code/{thirdPartyCode}`
* `PUT https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce`
* `POST https://api.aura-historia.com/api/v1/webhooks/woocommerce/{listingSourceId}`
* `POST https://api.aura-historia.com/api/v1/listing-sources/{listingSourceId}/product-listings/async`

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

A real registered canonical UUIDv7 `oc_` OAuth client ID must be supplied via `AHPC_OAUTH_CLIENT_ID` (constant or environment variable); none is bundled. Configure the same client ID and its matching secret in the webapp redirect broker, register the broker redirect URI, and grant exactly `product-listings:write listing-sources:write`. The broker must return an `ls_` ListingSource ID in its external `partner_shop_id` callback field; UUID Shop IDs are rejected. The API uses `https://api.aura-historia.com` in production and `https://api.stage.aura-historia.com` for stage. After OAuth completes, the plugin configures WooCommerce ingestion and syncs the managed webhooks.

== Frequently Asked Questions ==

= Do I need an Aura Historia account? =

Yes. This plugin is intended for merchants who already use Aura Historia and administer a ListingSource they can authorize during OAuth.

= Which WooCommerce events are sent? =

Only `product.created`, `product.updated`, and `product.deleted`.

= What data is sent to Aura Historia? =

After configuration, the plugin sends the generated WooCommerce webhook secret, store language and currency, unchanged signed live product webhook payloads, and published product listing data during backfill. The backfill never sends description/body; live WooCommerce webhook bodies remain untouched. See `External services` above.

= Can I change the delivery URL or webhook secret in wp-admin? =

No. The plugin keeps the Aura Historia delivery URL built in and generates the WooCommerce webhook secret automatically.

= What happens if I edit or delete one of the managed webhooks? =

The plugin first stages all managed webhooks paused with the desired signing secret, then applies that secret, supported store currency, and language through the WooCommerce ingestion-configuration PUT. Only after it succeeds does sync activate the webhooks. Changing WooCommerce currency or WordPress site language pauses delivery immediately until the new configuration is registered. Unsupported store currencies or configuration failures leave them paused for retry.

= Does this plugin backfill existing products? =

Yes. Once configuration succeeds, the plugin schedules an initial backfill once per canonical lowercase UUIDv7 `ls_` ListingSource ID. If setup or queueing fails, it keeps that first run pending for a later healthy request. It submits published products in background batches of up to 100 to the async ProductListings API. A `202` confirms queue admission, not completed creation or immediate search visibility. Retries reuse the same ordered request and idempotency key; a product-ID cursor prevents skips when earlier products are removed. Routine webhook repairs never start a fresh CREATE run. The manual action safely resumes pending work without changing its snapshot/key, leaves already queued work alone, or starts a new full run only when idle.

= What happens when the plugin is disabled? =

Plugin-owned webhooks are paused on deactivation so WooCommerce stops sending deliveries.

== Changelog ==

= 0.2.0 =
* The plugin now auto-configures with Aura Historia on installation. No more manual insertion of credentials needed. Just install, activate, and approve the OAuth connection when prompted.

= 0.1.0 =
* Initial release.
