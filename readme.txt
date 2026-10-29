=== Aura Historia Partner Connect ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.3.0
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
* imports supported local shipping configuration and scoped item class/variation inputs through separate typed APIs after connection, local changes, periodic reconciliation and the Import shipping repair action

The plugin keeps the settings surface intentionally small. Merchants do not manually enter Aura Historia credentials in wp-admin. The OAuth flow sets and stores:

* ListingSource ID (`ls_` TypeID)
* Aura Historia access token

Merchants do not enter:

* a webhook delivery URL
* a webhook secret

This plugin is intended for merchants who already use Aura Historia. Once OAuth completes, the plugin sends product data to Aura Historia so the connected catalog can stay in sync.

== External services ==

This plugin connects to Aura Historia, a hosted service required for the plugin to work.

It sends data after a merchant connects the store through Aura Historia OAuth, and later when WooCommerce sends managed webhook events or the plugin runs a product backfill or shipping import. These background jobs run without an open admin page.

The service is used to:

* register the generated WooCommerce webhook secret and current store locale details
* receive ongoing product webhook deliveries
* receive background product backfill batches
* store typed shipping profiles, source defaults, listing selections and class/variation inputs acquired locally by the plugin

Data sent to the service may include:

* ListingSource ID (`ls_` TypeID)
* Aura Historia access token in the bearer `Authorization` header for backend API calls
* Aura Historia access token in a bearer `Authorization` header on managed live webhook deliveries
* generated WooCommerce webhook secret
* store language and currency
* unchanged signed WooCommerce webhook payloads for `product.created`, `product.updated`, and `product.deleted`, including any WooCommerce descriptions
* published products during backfill: WooCommerce product ID as `sourceListingId`, canonical URL, image URL array, optional localized title, optional tagged monetary price (exact integer currency minor units; non-representable prices omitted), and optional availability. Backfill does not send description/body yet (follow-up #93).
* shipping import: supported geographic shipping rules and ordered zones, enabled service identifiers, exact/conditional rates and currency, pickup availability without addresses, source-scoped class/variation codes, WooCommerce product IDs, producer operation keys/order and actual acquisition time. Shipping requests do not contain credentials in their bodies, whole settings/metadata, customers, orders, addresses or full product descriptions.

Service endpoints:

* `GET https://aura-historia.com/oauth/authorize`
* `GET https://aura-historia.com/api/oauth/client/redirect-broker/woocommerce`
* `GET https://api.aura-historia.com/v1/oauth/tokens/by-third-party-code/{thirdPartyCode}`
* `PUT https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce`
* `POST https://api.aura-historia.com/v1/webhooks/woocommerce/{listingSourceId}`
* `POST https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/product-listings/async`
* `GET/POST https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/shipping-profiles`
* `GET/PUT/DELETE https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/shipping-profiles/{shippingProfileId}` and `POST .../{shippingProfileId}/restore`
* `GET/PUT/DELETE https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/shipping-default`
* `GET/PATCH https://api.aura-historia.com/v1/listing-sources/{listingSourceId}/product-listings/by-source-id/{sourceListingId}/delivery`

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
5. Approve the Aura Historia OAuth connection when prompted. If the approval page does not open automatically, click Connect with Aura Historia.

The production public OAuth client ID is bundled; merchants do not need to configure it. For stage or local development, supply a separate registered canonical UUIDv7 `oc_` client ID via `AHPC_OAUTH_CLIENT_ID` (constant or environment variable). Keep the API URL and broker redirect URI in that same environment. Configure the same client ID and its matching secret in the webapp redirect broker, register the broker redirect URI, and grant `product-listings:read product-listings:write listing-sources:read listing-sources:write`. Reconnect older connections to grant the read scopes shipping needs for identity/revision recovery. The broker must return an `ls_` ListingSource ID in its external `listing_source_id` callback field; UUID Shop IDs are rejected. The API uses `https://api.aura-historia.com` in production and `https://api.stage.aura-historia.com` for stage. After OAuth completes, the plugin configures WooCommerce ingestion and syncs the managed webhooks.

== Frequently Asked Questions ==

= Do I need an Aura Historia account? =

Yes. This plugin is intended for merchants who already use Aura Historia and administer a ListingSource they can authorize during OAuth.

= Which WooCommerce events are sent? =

Only `product.created`, `product.updated`, and `product.deleted`.

= What data is sent to Aura Historia? =

After configuration, the plugin sends the generated WooCommerce webhook secret, store language and currency, unchanged signed live product webhook payloads, published product listing data during backfill, and typed shipping rules and item inputs during shipping import. The backfill never sends description/body; live WooCommerce webhook bodies remain untouched. See `External services` above.

= Can I change the delivery URL or webhook secret in wp-admin? =

No. The plugin keeps the Aura Historia delivery URL built in and generates the WooCommerce webhook secret automatically.

= What happens if I edit or delete one of the managed webhooks? =

The plugin first stages all managed webhooks paused with the desired signing secret, then applies that secret, supported store currency, and language through the WooCommerce ingestion-configuration PUT. Only after it succeeds does sync activate the webhooks. Changing WooCommerce currency or WordPress site language pauses delivery immediately until the new configuration is registered. Unsupported store currencies or configuration failures leave them paused for retry.

= Does this plugin backfill existing products? =

Yes. Once configuration succeeds, the plugin schedules an initial backfill once per canonical lowercase UUIDv7 `ls_` ListingSource ID. If setup or queueing fails, it keeps that first run pending for a later healthy request. It submits published products in background batches of up to 100 to the async ProductListings API. A `202` confirms queue admission, not completed creation or immediate search visibility. Retries reuse the same ordered request and idempotency key; a product-ID cursor prevents skips when earlier products are removed. Routine webhook repairs never start a fresh CREATE run. The manual action safely resumes pending work without changing its snapshot/key, leaves already queued work alone, or starts a new full run only when idle, and its confirmation states which one happened.

= What happens when the plugin is disabled? =

Plugin-owned webhooks are paused on deactivation so WooCommerce stops sending deliveries. Shipping jobs are fenced/canceled, and the plugin attempts protected retirement of its shared shipping profile and clearing of its source default. This is not source authorization revocation. Unconfirmed cleanup is visible after reactivation; review retained assertions in Aura Historia. Existing assertions have no automatic expiry.

= Which shipping settings are supported? =

Ordered zones including fallback zone 0, country restrictions, reviewed state codes, exact postcodes and reviewed German postcode forms, core constant/quantity flat rates, bounded per-class quantity charges, conditional free shipping and conservative local pickup. Shared base/class surcharges, per-order class maxima, coupon/tax/cart context, unreviewed geography, third-party methods and other expressions remain uncertain or externally calculated. Nothing runs checkout or evaluates arbitrary expressions. Zones without locations retain their position and cover the permitted shipping countries. Class charges are ignored when no shipping classes exist, matching WooCommerce. The complete support matrix is in the repository README.

= How do shipping imports recover? =

Import shipping resumes immutable pending work and retries missing catalog listings without recreating products. Each background action makes at most one write; automatic retries and retained deferred work are bounded. Progress, manual protection and capability limits appear on the settings page. Shipping policy is shared in profiles, while item inputs remain separate. Incomplete reads preserve previous assertions; only confirmed local removals or complete scoped replacement remove owned assertions. Manual edits remain protected. A restored product cancels unsent removals and refreshes its selection and inputs.

Local hooks observe zone/location/order changes (including fallback zone 0), method addition/deletion/enablement and instance settings, shipping classes, core settings/units/currency, product inputs and variation inheritance. Save requests only mark durable identities. Background workers reread current facts and reuse the initial/manual publisher. Missed changes converge through six-hour reconciliation with bounded product traversal and pages of 20 previously published identities. Unsupported plugin behavior remains conservative; failed or incomplete reads never establish removal. No shipping refresh recreates catalog products.

WooCommerce Action Scheduler and a working WordPress cron or server runner are required. Low-traffic sites or DISABLE_WP_CRON installations need a server cron trigger or an Action Scheduler CLI runner, for example `wp action-scheduler run --group=aura-historia-shipping`. No browser session is required. The settings page shows oldest pending/failed work, deferred components, protected edits and last confirmed API write. Search indexing completes separately. Automatic attempts stop after five failures; up to 100 deferred components are retained before stopping at a checkpoint. Repeated refreshes reuse the entry of a component that is already deferred, such as an unsupported product type, and send no new request for a component until its retained request is resolved. Periodic/manual refreshes resume retained work.

Shipping assertions remain valid until updated, withdrawn or their source loses authority, without expiry or keepalive, including after 30 days. Reconnect/reactivation requires a fresh local acquisition; old jobs cannot relabel/replay their captures into a new connection. Valid Aura token rotation preserves immutable retries and uses the current token at execution. Deactivation/reconnect/uninstall attempt existing protected cleanup where possible and record whether it committed. Cleanup preserves manual terms and does not revoke authorization. Uninstall removes local shipping jobs/state and records a bounded diagnostic if remote cleanup was not confirmed. Lost credentials or unreachable Aura can leave assertions eligible until explicitly changed in Aura.

No WooCommerce REST key/secret is needed or exported; the backend never calls the plugin. Scheduled action arguments, status and diagnostics exclude credentials, raw settings and pending request contents. The three signed product webhook bodies and their receiver remain unchanged. The repository README documents hook and supported-version coverage.

Rescheduling a published product for future publication clears its owned shipping components until it is published again. A failed local removal recheck retains the original request and bounded retries; only confirmed restoration cancels a pending removal.

== Changelog ==

= 0.3.0 =
* Bundles the production OAuth client ID and uses the production `/v1` API contract.
* The plugin now auto-configures with Aura Historia on installation. No more manual insertion of credentials needed. Just install, activate, and approve the OAuth connection when prompted.
* Adds background initial/manual shipping imports with conservative local mapping, resumable retries and protection for manual Aura edits.
* Refreshes shipping after local changes and bounded periodic reconciliation, with durable retry scopes, connection fencing and recorded lifecycle cleanup.

= 0.1.0 =
* Initial release.
