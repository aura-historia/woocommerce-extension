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
     * Option key used to persist the latest backfill status for the admin UI.
     */
    const OPTION_STATE = "ahpc_backfill_state";

    /**
     * Backfill status: no batch is currently queued.
     */
    const STATUS_NOT_SCHEDULED = "not_scheduled";

    /**
     * Backfill status: a batch is queued.
     */
    const STATUS_SCHEDULED = "scheduled";

    /**
     * Backfill status: a batch is currently being processed.
     */
    const STATUS_RUNNING = "running";

    /**
     * Backfill status: the most recent run completed successfully.
     */
    const STATUS_COMPLETE = "complete";

    /**
     * Backfill status: the most recent run failed.
     */
    const STATUS_FAILED = "failed";

    /**
     * Number of products per batch sent to the backend.
     */
    const BATCH_SIZE = 100;

    /**
     * Plugin text domain.
     */
    const TEXT_DOMAIN = "aura-historia-partner-connect";

    /**
     * Deferred backfill operation to run once Action Scheduler is initialized.
     *
     * @var array<string,string>|null
     */
    private static $deferred_operation = null;

    /**
     * Whether the deferred operation callback has been registered.
     *
     * @var bool
     */
    private static $deferred_operation_registered = false;

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
        $shop_id = Webhook_Manager::normalize_shop_id($shop_id);

        if (!Webhook_Manager::is_valid_shop_id($shop_id)) {
            return false;
        }

        if (
            !function_exists("as_schedule_single_action") ||
            !function_exists("as_unschedule_all_actions")
        ) {
            $this->record_failed(
                __(
                    "Action Scheduler is not available, so the product backfill could not be queued.",
                    self::TEXT_DOMAIN,
                ),
            );

            return false;
        }

        if (!$this->is_action_scheduler_ready()) {
            self::$deferred_operation = [
                "type" => "schedule",
                "shop_id" => $shop_id,
            ];

            if (!self::$deferred_operation_registered) {
                self::$deferred_operation_registered = true;
                add_action(
                    "action_scheduler_init",
                    [self::class, "run_deferred_operation"],
                    10,
                    0,
                );
            }

            $this->record_scheduled();

            return true;
        }

        return $this->schedule_backfill_now($shop_id);
    }

    /**
     * Cancels all pending backfill batches.
     *
     * @return void
     */
    public function cancel_backfill()
    {
        if (!function_exists("as_unschedule_all_actions")) {
            $this->record_not_scheduled();
            return;
        }

        if (!$this->is_action_scheduler_ready()) {
            self::$deferred_operation = [
                "type" => "cancel",
                "shop_id" => "",
            ];

            if (!self::$deferred_operation_registered) {
                self::$deferred_operation_registered = true;
                add_action(
                    "action_scheduler_init",
                    [self::class, "run_deferred_operation"],
                    10,
                    0,
                );
            }

            $this->record_not_scheduled();

            return;
        }

        $this->cancel_backfill_actions();
        $this->record_not_scheduled();
    }

    /**
     * Returns whether a backfill batch is currently scheduled or running.
     *
     * @return bool
     */
    public function is_backfill_scheduled()
    {
        if (
            is_array(self::$deferred_operation) &&
            isset(self::$deferred_operation["type"]) &&
            "schedule" === self::$deferred_operation["type"]
        ) {
            return true;
        }

        if (
            !function_exists("as_has_scheduled_action") ||
            !$this->is_action_scheduler_ready()
        ) {
            return false;
        }

        return (bool) as_has_scheduled_action(
            self::ACTION_HOOK,
            null,
            self::ACTION_GROUP,
        );
    }

    /**
     * Returns the latest product backfill status details for the admin UI.
     *
     * @return array<string,mixed>
     */
    public function get_status_details()
    {
        $state = $this->get_state();
        $next_scheduled_at = 0;

        if (
            is_array(self::$deferred_operation) &&
            isset(self::$deferred_operation["type"]) &&
            "schedule" === self::$deferred_operation["type"]
        ) {
            $state["status"] = self::STATUS_SCHEDULED;
        } elseif (
            function_exists("as_next_scheduled_action") &&
            $this->is_action_scheduler_ready()
        ) {
            $next_action = as_next_scheduled_action(
                self::ACTION_HOOK,
                null,
                self::ACTION_GROUP,
            );

            if (true === $next_action) {
                $state["status"] = self::STATUS_RUNNING;
            } elseif (is_numeric($next_action) && (int) $next_action > 0) {
                $state["status"] = self::STATUS_SCHEDULED;
                $next_scheduled_at = (int) $next_action;
            }
        }

        $state["hook"] = self::ACTION_HOOK;
        $state["next_scheduled_at"] = $next_scheduled_at;

        return $state;
    }

    /**
     * Processes a single product batch.
     *
     * Invoked by Action Scheduler via the {@see ACTION_HOOK} action.
     * Fetches up to {@see BATCH_SIZE} products for the given page, serialises
     * each one using the WooCommerce REST API v3 format, and posts the batch to
     * the backend. If the page was full (i.e. there may be more products), the
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
        $api_key = isset($settings["api_key"])
            ? (string) $settings["api_key"]
            : "";

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
            $this->record_complete();
            return;
        }

        $this->record_running();

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
                $message = sprintf(
                    "Aura Historia backfill batch (page %d) failed: %s",
                    $page,
                    $result->get_error_message(),
                );

                $this->record_failed($message);

                // Throw so Action Scheduler retries this batch automatically.
                throw new \RuntimeException($message);
            }
        }

        // Schedule the next page only when this page was a full batch,
        // indicating there may be more products.
        if (
            count($product_ids) >= self::BATCH_SIZE &&
            function_exists("as_schedule_single_action")
        ) {
            $next_action_id = as_schedule_single_action(
                time(),
                self::ACTION_HOOK,
                [$shop_id, $page + 1],
                self::ACTION_GROUP,
                true,
            );

            if (!$next_action_id) {
                $message = sprintf(
                    "Aura Historia backfill batch (page %d) could not schedule page %d.",
                    $page,
                    $page + 1,
                );

                $this->record_failed($message);
                throw new \RuntimeException($message);
            }

            $this->record_scheduled();

            return;
        }

        $this->record_complete();
    }

    /**
     * Runs a deferred schedule or cancel operation once Action Scheduler is ready.
     *
     * @return void
     */
    public static function run_deferred_operation()
    {
        $operation = self::$deferred_operation;

        self::$deferred_operation = null;
        self::$deferred_operation_registered = false;

        if (!is_array($operation) || empty($operation["type"])) {
            return;
        }

        $backfill = new self();

        if ("schedule" === $operation["type"]) {
            $backfill->schedule_backfill(
                isset($operation["shop_id"])
                    ? (string) $operation["shop_id"]
                    : "",
            );
            return;
        }

        $backfill->cancel_backfill();
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
     * returned data matches what live WooCommerce REST API calls return. This
     * runs under the stored webhook user so Action Scheduler batches can build
     * product payloads even when there is no logged-in admin.
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

        $manager = new Webhook_Manager();
        $restore_user_id = get_current_user_id();
        $webhook_user_id = $manager->resolve_webhook_user_id();

        if ($webhook_user_id && $webhook_user_id !== $restore_user_id) {
            wp_set_current_user($webhook_user_id);
        }

        try {
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
        } finally {
            if ($webhook_user_id && $webhook_user_id !== $restore_user_id) {
                wp_set_current_user($restore_user_id);
            }
        }
    }

    /**
     * Returns whether Action Scheduler has finished initializing.
     *
     * @return bool
     */
    private function is_action_scheduler_ready()
    {
        return did_action("action_scheduler_init") > 0;
    }

    /**
     * Schedules the initial backfill batch immediately.
     *
     * @param string $shop_id Shop UUID.
     * @return bool
     */
    private function schedule_backfill_now($shop_id)
    {
        $this->cancel_backfill_actions();

        $action_id = as_schedule_single_action(
            time(),
            self::ACTION_HOOK,
            [$shop_id, 1],
            self::ACTION_GROUP,
            true,
        );

        if (!$action_id) {
            $this->record_failed(
                __(
                    "The initial product backfill batch could not be scheduled.",
                    self::TEXT_DOMAIN,
                ),
            );

            return false;
        }

        $this->record_scheduled();

        return true;
    }

    /**
     * Unschedules all pending backfill actions without changing the stored state.
     *
     * @return void
     */
    private function cancel_backfill_actions()
    {
        as_unschedule_all_actions(self::ACTION_HOOK, null, self::ACTION_GROUP);
    }

    /**
     * Returns the stored backfill state with guaranteed defaults.
     *
     * @return array<string,string>
     */
    private function get_state()
    {
        $state = get_option(self::OPTION_STATE, []);

        if (!is_array($state)) {
            $state = [];
        }

        return wp_parse_args($state, self::default_state());
    }

    /**
     * Returns the default stored backfill state.
     *
     * @return array<string,string>
     */
    private static function default_state()
    {
        return [
            "status" => self::STATUS_NOT_SCHEDULED,
            "scheduled_at" => "",
            "started_at" => "",
            "completed_at" => "",
            "failed_at" => "",
            "last_error" => "",
        ];
    }

    /**
     * Persists the current backfill state.
     *
     * @param array<string,string> $changes State changes to store.
     * @return void
     */
    private function update_state($changes)
    {
        $state = $this->get_state();

        foreach ($changes as $key => $value) {
            $state[$key] = (string) $value;
        }

        update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * Records that a backfill batch is queued.
     *
     * @return void
     */
    private function record_scheduled()
    {
        $this->update_state([
            "status" => self::STATUS_SCHEDULED,
            "scheduled_at" => current_time("mysql"),
            "started_at" => "",
            "failed_at" => "",
            "last_error" => "",
        ]);
    }

    /**
     * Records that a backfill batch is currently running.
     *
     * @return void
     */
    private function record_running()
    {
        $this->update_state([
            "status" => self::STATUS_RUNNING,
            "started_at" => current_time("mysql"),
            "failed_at" => "",
            "last_error" => "",
        ]);
    }

    /**
     * Records that the most recent backfill run completed successfully.
     *
     * @return void
     */
    private function record_complete()
    {
        $this->update_state([
            "status" => self::STATUS_COMPLETE,
            "scheduled_at" => "",
            "started_at" => "",
            "completed_at" => current_time("mysql"),
            "failed_at" => "",
            "last_error" => "",
        ]);
    }

    /**
     * Records that no backfill is currently scheduled.
     *
     * @return void
     */
    private function record_not_scheduled()
    {
        $this->update_state([
            "status" => self::STATUS_NOT_SCHEDULED,
            "scheduled_at" => "",
            "started_at" => "",
            "failed_at" => "",
            "last_error" => "",
        ]);
    }

    /**
     * Records that the most recent backfill attempt failed.
     *
     * @param string $message Error detail.
     * @return void
     */
    private function record_failed($message)
    {
        $this->update_state([
            "status" => self::STATUS_FAILED,
            "scheduled_at" => "",
            "started_at" => "",
            "failed_at" => current_time("mysql"),
            "last_error" => sanitize_text_field((string) $message),
        ]);
    }
}
