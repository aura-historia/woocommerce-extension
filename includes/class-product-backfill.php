<?php
/**
 * Product backfill.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Schedules and processes asynchronous product backfills via Action Scheduler.
 *
 * When the plugin connects to the Aura Historia backend with valid settings,
 * all existing WooCommerce products are pushed to the backend endpoint
 * `PUT /api/v1/shops/{shopId}/products` in batches of {@see BATCH_SIZE}.
 *
 * Using PUT means the backfill is idempotent: re-running it (e.g. after
 * reconnecting a shop) updates changed products and skips unchanged ones.
 *
 * Action Scheduler (bundled with WooCommerce) is used so that large catalogs
 * do not block the HTTP response and can be retried automatically on failure.
 */
class Product_Backfill
{
    /**
     * Action Scheduler hook name for processing a single product batch.
     */
    const ACTION_HOOK = "ahpc_backfill_products_batch";

    /**
     * Action Scheduler group that owns all backfill actions.
     */
    const ACTION_GROUP = "ahpc-partner-connect";

    /**
     * Number of products per batch sent to the backend.
     */
    const BATCH_SIZE = 100;

    /**
     * Plugin text domain.
     */
    const TEXT_DOMAIN = "aura-historia-partner-connect";

    /**
     * Schedules a fresh product backfill for the given shop.
     *
     * Any pending backfill batches are cancelled before the new one is
     * enqueued so that settings changes always trigger a clean restart.
     *
     * @param string $shop_id Shop UUID.
     * @return bool Whether the backfill was successfully scheduled.
     */
    public function schedule_backfill($shop_id)
    {
        if (!function_exists("as_enqueue_async_action")) {
            return false;
        }

        $shop_id = Webhook_Manager::normalize_shop_id($shop_id);

        if (!Webhook_Manager::is_valid_shop_id($shop_id)) {
            return false;
        }

        // Cancel any previously scheduled batches before starting fresh.
        $this->cancel_backfill();

        as_enqueue_async_action(
            self::ACTION_HOOK,
            [$shop_id, 1],
            self::ACTION_GROUP,
        );

        return true;
    }

    /**
     * Cancels all pending backfill batches.
     *
     * @return void
     */
    public function cancel_backfill()
    {
        if (!function_exists("as_unschedule_all_actions")) {
            return;
        }

        as_unschedule_all_actions(self::ACTION_HOOK, null, self::ACTION_GROUP);
    }

    /**
     * Returns whether a backfill batch is currently scheduled or running.
     *
     * @return bool
     */
    public function is_backfill_scheduled()
    {
        if (!function_exists("as_has_scheduled_action")) {
            return false;
        }

        return (bool) as_has_scheduled_action(
            self::ACTION_HOOK,
            null,
            self::ACTION_GROUP,
        );
    }

    /**
     * Processes a single product batch.
     *
     * Invoked by Action Scheduler via the {@see ACTION_HOOK} action.
     * Fetches up to {@see BATCH_SIZE} products for the given page, serialises
     * each one using the WooCommerce REST API v3 format, and posts the batch to
     * the backend.  If the page was full (i.e. there may be more products), the
     * next page is immediately re-enqueued.
     *
     * Throwing an exception causes Action Scheduler to retry this batch
     * automatically, so backend errors propagate as exceptions.
     *
     * @param string $shop_id Shop UUID, as stored in the scheduled action args.
     * @param int    $page    One-based page number within the product catalog.
     * @return void
     * @throws \RuntimeException When the backend rejects the batch, so Action Scheduler retries it.
     */
    public function process_batch($shop_id, $page)
    {
        $shop_id = Webhook_Manager::normalize_shop_id((string) $shop_id);
        $page = max(1, (int) $page);

        // Validate that the current settings still match the scheduled shop.
        $settings = get_option(Webhook_Manager::OPTION_SETTINGS, []);

        if (!is_array($settings)) {
            return;
        }

        $stored_shop_id = Webhook_Manager::normalize_shop_id(
            isset($settings["shop_id"]) ? (string) $settings["shop_id"] : "",
        );
        $api_key = isset($settings["api_key"]) ? (string) $settings["api_key"] : "";

        if (
            $stored_shop_id !== $shop_id ||
            "" === $api_key ||
            !Webhook_Manager::is_valid_shop_id($shop_id) ||
            !Webhook_Manager::is_valid_api_key($api_key)
        ) {
            // Settings no longer valid for this shop; abort silently.
            return;
        }

        // Fetch the product IDs for this page.
        $product_ids = $this->get_product_ids($page);

        if (empty($product_ids)) {
            // No more products; backfill complete.
            return;
        }

        // Serialise each product in WC REST API v3 format.
        $payloads = [];

        foreach ($product_ids as $product_id) {
            $payload = $this->build_product_payload((int) $product_id);

            if (!empty($payload) && is_array($payload)) {
                $payloads[] = $payload;
            }
        }

        if (!empty($payloads)) {
            $client = new Backend_Api_Client(
                Webhook_Manager::get_backend_base_url(),
            );
            $result = $client->put_shop_products($shop_id, $api_key, $payloads);

            if (is_wp_error($result)) {
                // Throw so Action Scheduler retries this batch automatically.
                throw new \RuntimeException(
                    sprintf(
                        "Aura Historia backfill batch (page %d) failed: %s",
                        $page,
                        $result->get_error_message(),
                    ),
                );
            }
        }

        // Schedule the next page only when this page was a full batch,
        // indicating there may be more products.
        if (
            count($product_ids) >= self::BATCH_SIZE &&
            function_exists("as_enqueue_async_action")
        ) {
            as_enqueue_async_action(
                self::ACTION_HOOK,
                [$shop_id, $page + 1],
                self::ACTION_GROUP,
            );
        }
    }

    /**
     * Returns the product IDs for a given page, ordered by ascending ID.
     *
     * Both `publish` and `private` products are intentionally included so that
     * privately listed products (accessible by direct URL but hidden from the
     * shop archive) are also represented in the Aura Historia backend catalog.
     *
     * @param int $page One-based page number.
     * @return int[]
     */
    private function get_product_ids($page)
    {
        if (!function_exists("wc_get_products")) {
            return [];
        }

        $ids = wc_get_products([
            "limit" => self::BATCH_SIZE,
            "paged" => $page,
            "status" => ["publish", "private"],
            "orderby" => "ID",
            "order" => "ASC",
            "return" => "ids",
        ]);

        return is_array($ids) ? array_map("intval", $ids) : [];
    }

    /**
     * Builds the WooCommerce REST API v3 representation of a single product.
     *
     * Dispatches a GET request through the WP REST server to
     * `/wc/v3/products/{id}` so that routes are properly initialised and the
     * returned data matches what live WooCommerce REST API calls return.  This
     * approach does not involve any webhook machinery.
     *
     * @param int $product_id WooCommerce product ID.
     * @return array<string,mixed>|null Serialised product data, or null on failure.
     */
    private function build_product_payload($product_id)
    {
        if (!function_exists("wc_get_product") || $product_id <= 0) {
            return null;
        }

        if (!wc_get_product($product_id)) {
            return null;
        }

        $server = rest_get_server();
        $request = new \WP_REST_Request(
            "GET",
            "/wc/v3/products/{$product_id}",
        );
        $request->set_query_params(["context" => "view"]);

        try {
            $response = $server->dispatch($request);
        } catch (\Throwable $e) {
            return null;
        }

        if (
            !$response instanceof \WP_REST_Response ||
            $response->is_error()
        ) {
            return null;
        }

        $data = $server->response_to_data($response, false);

        return is_array($data) && !empty($data) ? $data : null;
    }
}
