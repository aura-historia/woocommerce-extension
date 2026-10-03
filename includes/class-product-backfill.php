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
 * published WooCommerce products are submitted to the listing-source async
 * admission endpoint in batches of {@see BATCH_SIZE}. A batch snapshot and
 * idempotency key are retained until it has been admitted and its successor
 * scheduled, so request-wide retries cannot change the submitted payload.
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

    /** Pending batch snapshot; deliberately separate from admin-visible status. */
    const OPTION_BATCH = "ahpc_backfill_batch";

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

    /** Seconds between attempts to replay an unchanged batch. */
    const RETRY_DELAY = 60;

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
     * Schedules a fresh product backfill for the listing source.
     *
     * Any pending backfill batches are cancelled before the new one is
     * enqueued so that settings changes always trigger a clean restart.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @return bool Whether the backfill was successfully scheduled.
     */
    public function schedule_backfill($listing_source_id)
    {
        $listing_source_id = Webhook_Manager::normalize_listing_source_id($listing_source_id);

        if (!Webhook_Manager::is_valid_listing_source_id($listing_source_id)) {
            return false;
        }

        if (
            !function_exists("as_schedule_single_action") ||
            !function_exists("as_unschedule_all_actions")
        ) {
            $this->record_failed(
                __(
                    "Action Scheduler is not available, so the product backfill could not be queued.",
                    "aura-historia-partner-connect",
                ),
            );

            return false;
        }

        if (!$this->is_action_scheduler_ready()) {
            self::$deferred_operation = [
                "type" => "schedule",
                "listing_source_id" => $listing_source_id,
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

            $this->reset_admission_status();
            $this->record_scheduled();

            return true;
        }

        return $this->schedule_backfill_now($listing_source_id);
    }

    /**
     * Cancels all pending backfill batches.
     *
     * @return void
     */
    public function cancel_backfill()
    {
        delete_option(self::OPTION_BATCH);

        if (!function_exists("as_unschedule_all_actions")) {
            $this->record_not_scheduled();
            return;
        }

        if (!$this->is_action_scheduler_ready()) {
            self::$deferred_operation = [
                "type" => "cancel",
                "listing_source_id" => "",
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
     * @param string $listing_source_id Listing source TypeID in the action args.
     * @param int    $page One-based catalog page.
     * @return void
     * @throws \RuntimeException On retryable request or listing admission failure.
     */
    public function process_batch($listing_source_id, $page)
    {
        $listing_source_id = Webhook_Manager::normalize_listing_source_id((string) $listing_source_id);
        $page = max(1, (int) $page);
        $settings = get_option(Webhook_Manager::OPTION_SETTINGS, []);

        if (!is_array($settings)) {
            delete_option(self::OPTION_BATCH);
            return;
        }

        $stored_id = Webhook_Manager::normalize_listing_source_id(
            isset($settings["listing_source_id"]) ? (string) $settings["listing_source_id"] : "",
        );
        $access_token = isset($settings["access_token"])
            ? (string) $settings["access_token"]
            : "";

        if (
            $stored_id !== $listing_source_id ||
            !Webhook_Manager::is_valid_listing_source_id($listing_source_id) ||
            !Webhook_Manager::is_valid_access_token($access_token)
        ) {
            delete_option(self::OPTION_BATCH);
            return;
        }

        $snapshot = get_option(self::OPTION_BATCH, []);

        if (
            $page <= (int) $this->get_state()["last_completed_page"] &&
            !(
                is_array($snapshot) &&
                isset($snapshot["listing_source_id"], $snapshot["page"]) &&
                $snapshot["listing_source_id"] === $listing_source_id &&
                (int) $snapshot["page"] === $page &&
                !empty($snapshot["handoff_pending"])
            )
        ) {
            // Ignore a delayed duplicate after this page has already advanced.
            return;
        }

        if (
            is_array($snapshot) &&
            isset($snapshot["listing_source_id"], $snapshot["page"]) &&
            $snapshot["listing_source_id"] === $listing_source_id &&
            (int) $snapshot["page"] !== $page
        ) {
            // Another page has an in-flight retry; never overwrite its snapshot.
            return;
        }

        if (
            !is_array($snapshot) ||
            !isset($snapshot["listing_source_id"], $snapshot["page"], $snapshot["payloads"], $snapshot["idempotency_key"], $snapshot["product_count"]) ||
            $snapshot["listing_source_id"] !== $listing_source_id ||
            (int) $snapshot["page"] !== $page ||
            !is_array($snapshot["payloads"]) ||
            !is_string($snapshot["idempotency_key"])
        ) {
            $product_ids = $this->get_product_ids($page);

            if (empty($product_ids)) {
                delete_option(self::OPTION_BATCH);
                $this->record_complete($page);
                return;
            }

            $payloads = [];
            $local_failures = 0;

            foreach ($product_ids as $product_id) {
                $payload = $this->build_product_payload((int) $product_id);

                if (is_wp_error($payload)) {
                    ++$local_failures;
                } elseif (is_array($payload)) {
                    $payloads[] = $payload;
                }
            }

            $snapshot = [
                "listing_source_id" => $listing_source_id,
                "page" => $page,
                "product_count" => count($product_ids),
                "local_failures" => $local_failures,
                "payloads" => $payloads,
                "idempotency_key" => wp_generate_uuid4(),
            ];
            // Never send a batch whose request cannot be replayed unchanged.
            if (!update_option(self::OPTION_BATCH, $snapshot, false)) {
                $message = sprintf("Aura Historia backfill batch (page %d) could not save its request snapshot.", $page);
                $this->record_failed($message);
                throw new \RuntimeException(esc_html($message));
            }
        }

        // Older pending snapshots predate local failure accounting; retain
        // their exact payload and idempotency key when resuming a retry.
        $snapshot["local_failures"] = isset($snapshot["local_failures"])
            ? (int) $snapshot["local_failures"]
            : 0;

        if (!empty($snapshot["handoff_pending"])) {
            $this->schedule_next_page($snapshot, $listing_source_id, $page);
            return;
        }

        $this->record_running();
        $report = ["accepted_count" => 0, "failures" => [], "submission_id" => ""];

        if (!empty($snapshot["payloads"])) {
            $client = new Backend_Api_Client(Webhook_Manager::get_backend_base_url());
            $result = $client->post_async_partner_product_listings(
                $listing_source_id,
                $access_token,
                $snapshot["payloads"],
                $snapshot["idempotency_key"],
            );

            if (is_wp_error($result)) {
                $error_data = $result->get_error_data();
                $has_report = is_array($error_data) &&
                    isset($error_data["accepted_count"], $error_data["failures"], $error_data["submission_id"]) &&
                    is_array($error_data["failures"]);
                $message = sprintf("Aura Historia backfill batch (page %d) request failed.", $page);

                if ($has_report) {
                    $report = $error_data;
                    $message = sprintf("Aura Historia backfill batch (page %d) had listing admission failures.", $page);
                }

                if (is_array($error_data) && !empty($error_data["retryable"])) {
                    if ($has_report) {
                        $this->record_admission($report, (int) $snapshot["local_failures"]);
                    }
                    $this->schedule_retry($snapshot, $listing_source_id, $page, $message);
                    // Never include free-form backend details in Action Scheduler logs.
                    throw new \RuntimeException(esc_html($message));
                }

                if (!$has_report) {
                    $this->record_failed($message);
                    $this->cancel_batch_retries($snapshot, $listing_source_id, $page);
                    delete_option(self::OPTION_BATCH);
                    return;
                }
            } else {
                $report = $result;
            }

            $this->record_admission($report, (int) $snapshot["local_failures"]);

            foreach ($report["failures"] as $failure) {
                if (!empty($failure["retryable"])) {
                    $message = sprintf("Aura Historia backfill batch (page %d) has retryable listing admission failures.", $page);
                    $this->schedule_retry($snapshot, $listing_source_id, $page, $message);
                    throw new \RuntimeException(esc_html($message));
                }
            }
        } else {
            $this->record_admission($report, (int) $snapshot["local_failures"]);
        }

        $this->record_permanent_failures(
            $snapshot,
            count($report["failures"]) + (int) $snapshot["local_failures"],
            $page,
        );
        $this->cancel_batch_retries($snapshot, $listing_source_id, $page);

        if ((int) $snapshot["product_count"] >= self::BATCH_SIZE) {
            $this->schedule_next_page($snapshot, $listing_source_id, $page);
            return;
        }

        if (!delete_option(self::OPTION_BATCH)) {
            $message = sprintf("Aura Historia backfill batch (page %d) could not clear its request snapshot.", $page);
            $this->record_failed($message);
            throw new \RuntimeException(esc_html($message));
        }
        $this->record_complete($page);
    }

    /**
     * Queues a delayed retry with distinct Action Scheduler args, while the
     * original two callback arguments and persisted request remain unchanged.
     *
     * @param array  $snapshot Persisted batch snapshot.
     * @param string $listing_source_id Listing source TypeID.
     * @param int    $page Catalog page.
     * @param string $message Safe failure message.
     * @return void
     */
    private function schedule_retry(array $snapshot, $listing_source_id, $page, $message)
    {
        $attempt = isset($snapshot["retry_attempt"])
            ? min(PHP_INT_MAX - 1, max(0, (int) $snapshot["retry_attempt"])) + 1
            : 1;
        $snapshot["retry_attempt"] = $attempt;

        if (!update_option(self::OPTION_BATCH, $snapshot, false)) {
            $failure = sprintf("Aura Historia backfill batch (page %d) could not persist its retry.", $page);
            $this->record_failed($failure);
            throw new \RuntimeException(esc_html($failure));
        }

        $args = [$listing_source_id, $page, $this->retry_token($snapshot["idempotency_key"], $attempt)];
        $action_id = function_exists("as_schedule_single_action")
            ? as_schedule_single_action(time() + self::RETRY_DELAY, self::ACTION_HOOK, $args, self::ACTION_GROUP, true)
            : 0;

        if (!$action_id && !(function_exists("as_has_scheduled_action") &&
            as_has_scheduled_action(self::ACTION_HOOK, $args, self::ACTION_GROUP))) {
            $failure = sprintf("Aura Historia backfill batch (page %d) could not schedule its retry.", $page);
            $this->record_failed($failure);
            throw new \RuntimeException(esc_html($failure));
        }

        $this->record_failed($message);
    }

    /**
     * Removes outstanding retries for a completed or terminal batch.
     *
     * @param array  $snapshot Persisted batch snapshot.
     * @param string $listing_source_id Listing source TypeID.
     * @param int    $page Catalog page.
     * @return void
     */
    private function cancel_batch_retries(array $snapshot, $listing_source_id, $page)
    {
        if (!function_exists("as_unschedule_all_actions")) {
            return;
        }

        $attempts = isset($snapshot["retry_attempt"]) ? max(0, (int) $snapshot["retry_attempt"]) : 0;

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            as_unschedule_all_actions(
                self::ACTION_HOOK,
                [$listing_source_id, $page, $this->retry_token($snapshot["idempotency_key"], $attempt)],
                self::ACTION_GROUP,
            );
        }

        as_unschedule_all_actions(self::ACTION_HOOK, [$listing_source_id, $page], self::ACTION_GROUP);
    }

    /**
     * @param string $idempotency_key Persisted backend key.
     * @param int    $attempt Retry number.
     * @return string Non-sensitive uniqueness argument.
     */
    private function retry_token($idempotency_key, $attempt)
    {
        return "retry-" . hash("sha256", $idempotency_key . ":" . $attempt);
    }

    /**
     * Hands off a committed full page without letting page two see page one's
     * snapshot. A failed enqueue restores a handoff-only snapshot: retries
     * can enqueue the successor without re-sending an admitted batch.
     *
     * @param array  $snapshot Persisted batch snapshot.
     * @param string $listing_source_id Listing source TypeID.
     * @param int    $page Completed catalog page.
     * @return void
     */
    private function schedule_next_page(array $snapshot, $listing_source_id, $page)
    {
        if (!delete_option(self::OPTION_BATCH)) {
            $message = sprintf("Aura Historia backfill batch (page %d) could not clear its request snapshot.", $page);
            $this->record_failed($message);
            throw new \RuntimeException(esc_html($message));
        }

        $this->record_scheduled();
        $args = [$listing_source_id, $page + 1];
        $action_id = function_exists("as_schedule_single_action")
            ? as_schedule_single_action(time(), self::ACTION_HOOK, $args, self::ACTION_GROUP, true)
            : 0;

        $current_snapshot = get_option(self::OPTION_BATCH, []);

        if ($action_id || (function_exists("as_has_scheduled_action") &&
            as_has_scheduled_action(self::ACTION_HOOK, $args, self::ACTION_GROUP)) ||
            (int) $this->get_state()["last_completed_page"] >= $page + 1 ||
            (is_array($current_snapshot) && isset($current_snapshot["listing_source_id"], $current_snapshot["page"]) &&
                $current_snapshot["listing_source_id"] === $listing_source_id && (int) $current_snapshot["page"] === $page + 1)) {
            return;
        }

        $snapshot["handoff_pending"] = true;
        $message = sprintf("Aura Historia backfill batch (page %d) could not schedule page %d.", $page, $page + 1);

        if (!update_option(self::OPTION_BATCH, $snapshot, false)) {
            $this->record_failed($message);
            throw new \RuntimeException(esc_html($message));
        }

        $this->schedule_retry($snapshot, $listing_source_id, $page, $message);
        throw new \RuntimeException(esc_html($message));
    }

    /**
     * Commits a page's permanent failures before handing off to the next page.
     * A handoff-only retry does not count the same failures again.
     *
     * @param array $snapshot Persisted batch snapshot.
     * @param int   $count Number of permanently failed listings on this page.
     * @param int   $page Page number for a safe status message.
     * @return void
     */
    private function record_permanent_failures(array $snapshot, $count, $page)
    {
        $state = $this->get_state();
        $batch_hash = hash("sha256", $snapshot["idempotency_key"]);

        if ($state["last_counted_batch"] === $batch_hash) {
            return;
        }

        $changes = [
            "last_counted_batch" => $batch_hash,
            "last_completed_page" => $page,
        ];

        if ($count > 0) {
            $changes["permanent_failure_count"] = (int) $state["permanent_failure_count"] + $count;
            $changes["failed_at"] = current_time("mysql");
            $changes["last_error"] = sprintf(
                "Aura Historia backfill batch (page %d) had %d permanent listing failures.",
                $page,
                $count,
            );
        }

        if (!$this->update_state($changes)) {
            throw new \RuntimeException(esc_html(sprintf(
                "Aura Historia backfill batch (page %d) could not save its admission outcome.",
                $page,
            )));
        }
    }

    /**
     * Keeps only safe admission counts and a bounded submission identifier in status.
     *
     * @param array $report Validated backend admission report.
     * @param int   $local_failures Listings without a valid canonical URL.
     * @return void
     */
    private function record_admission(array $report, $local_failures)
    {
        $this->update_state([
            "accepted_count" => (int) $report["accepted_count"],
            "failed_count" => count($report["failures"]) + $local_failures,
            "submission_id" => sanitize_text_field((string) $report["submission_id"]),
        ]);
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
                isset($operation["listing_source_id"])
                    ? (string) $operation["listing_source_id"]
                    : "",
            );
            return;
        }

        $backfill->cancel_backfill();
    }

    /**
     * Returns the product IDs for a given page, ordered by ascending ID.
     *
     * Only public, published products may be admitted.
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
            "status" => "publish",
            "orderby" => "ID",
            "order" => "ASC",
            "return" => "ids",
        ]);

        return is_array($ids) ? array_map("intval", $ids) : [];
    }

    /**
     * Builds one partner listing for async admission.
     *
     * @param int $product_id WooCommerce product ID.
     * @return array<string,mixed>|\WP_Error|null Product data or a local admission failure.
     */
    private function build_product_payload($product_id)
    {
        if (!function_exists("wc_get_product") || $product_id <= 0) {
            return null;
        }

        $product = wc_get_product($product_id);

        if (!$product || "publish" !== $product->get_status()) {
            return null;
        }

        $url = $this->normalize_url_value(get_permalink($product_id));

        if ("" === $url) {
            return new \WP_Error(
                "ahpc_backfill_missing_product_url",
                "A published product has no absolute canonical URL.",
            );
        }

        $payload = [
            "sourceListingId" => (string) $product_id,
            "url" => $url,
            "images" => $this->get_product_image_urls($product),
        ];

        $availability = $this->get_product_availability($product);

        if (null !== $availability) {
            $payload["availability"] = $availability;
        }

        $language = Store_Locale::get_language();
        $title = $this->normalize_text_value(
            method_exists($product, "get_name") ? $product->get_name() : "",
        );

        if ("" !== $title) {
            $payload["title"] = [
                "text" => $title,
                "language" => $language,
            ];
        }

        $price = $this->build_price_payload($product);

        if (is_array($price)) {
            $payload["price"] = $price;
        }

        return $payload;
    }

    /**
     * Builds strict backend price data in minor currency units.
     *
     * @param object $product WooCommerce product object.
     * @return array<string,mixed>|null
     */
    private function build_price_payload($product)
    {
        $raw_price = method_exists($product, "get_price")
            ? (string) $product->get_price()
            : "";

        if ("" === trim($raw_price)) {
            return null;
        }

        $currency = Store_Locale::get_currency();

        if (null === $currency) {
            return null;
        }

        $amount = $this->convert_price_to_minor_units($raw_price);

        if (null === $amount) {
            return null;
        }

        return [
            "type" => "MONETARY",
            "currency" => $currency,
            "amount" => $amount,
        ];
    }

    /**
     * Converts a WooCommerce decimal price string to minor currency units.
     *
     * @param string $price WooCommerce decimal price string.
     * @return int|null
     */
    private function convert_price_to_minor_units($price)
    {
        $price = trim((string) $price);

        // wc_format_decimal strips non-numeric characters; reject them first
        // rather than turning a malformed price into a different valid amount.
        if (1 !== preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $price)) {
            return null;
        }

        $decimals = function_exists("wc_get_price_decimals")
            ? max(0, (int) wc_get_price_decimals())
            : 2;
        $normalized = function_exists("wc_format_decimal")
            ? (string) wc_format_decimal($price, $decimals, false)
            : $price;

        if (1 !== preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $normalized)) {
            return null;
        }

        $parts = explode(".", $normalized, 2);
        $whole = $parts[0];
        $fraction = isset($parts[1]) ? $parts[1] : "";
        $fraction = substr(str_pad($fraction, $decimals, "0"), 0, $decimals);
        $amount = ltrim($whole . $fraction, "0");

        if (strlen($amount) > strlen((string) PHP_INT_MAX) ||
            (strlen($amount) === strlen((string) PHP_INT_MAX) && strcmp($amount, (string) PHP_INT_MAX) > 0)
        ) {
            return null;
        }

        return "" === $amount ? 0 : (int) $amount;
    }

    /**
     * Maps WooCommerce stock status to listing availability.
     *
     * @param object $product WooCommerce product object.
     * @return string|null Null when WooCommerce reports no supported stock status.
     */
    private function get_product_availability($product)
    {
        $status = (string) $product->get_stock_status();

        if ("instock" === $status) {
            return "IN_STOCK";
        }

        if ("outofstock" === $status) {
            return "OUT_OF_STOCK";
        }

        return "onbackorder" === $status ? "BACK_ORDER" : null;
    }

    /**
     * Returns absolute image URLs for the product.
     *
     * @param object $product WooCommerce product object.
     * @return string[]
     */
    private function get_product_image_urls($product)
    {
        $image_ids = [];

        if (method_exists($product, "get_image_id")) {
            $image_ids[] = (int) $product->get_image_id();
        }

        if (method_exists($product, "get_gallery_image_ids")) {
            $gallery_ids = $product->get_gallery_image_ids();

            if (is_array($gallery_ids)) {
                $image_ids = array_merge($image_ids, $gallery_ids);
            }
        }

        $urls = [];

        foreach (array_unique(array_map("intval", $image_ids)) as $image_id) {
            if ($image_id <= 0) {
                continue;
            }

            $url = $this->normalize_url_value(wp_get_attachment_url($image_id));

            if ("" !== $url) {
                $urls[] = $url;
            }
        }

        return array_values($urls);
    }

    /**
     * Normalizes HTML-rich content to plain text for the backend schema.
     *
     * @param mixed $value Raw text or HTML content.
     * @return string
     */
    private function normalize_text_value($value)
    {
        $text = html_entity_decode(
            wp_strip_all_tags((string) $value),
            ENT_QUOTES,
            "UTF-8",
        );
        $text = preg_replace("/\s+/u", " ", trim($text));

        return is_string($text) ? $text : trim((string) $value);
    }

    /**
     * Normalizes a URL for the backend schema.
     *
     * @param mixed $value Raw URL value.
     * @return string
     */
    private function normalize_url_value($value)
    {
        $url = esc_url_raw(trim((string) $value), ["http", "https"]);

        $parts = is_string($url) ? wp_parse_url($url) : false;

        return is_array($parts) && !empty($parts["host"]) &&
            in_array(isset($parts["scheme"]) ? strtolower($parts["scheme"]) : "", ["http", "https"], true)
            ? $url
            : "";
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
     * @param string $listing_source_id Listing source TypeID.
     * @return bool
     */
    private function schedule_backfill_now($listing_source_id)
    {
        $this->cancel_backfill_actions();
        delete_option(self::OPTION_BATCH);

        $action_id = as_schedule_single_action(
            time(),
            self::ACTION_HOOK,
            [$listing_source_id, 1],
            self::ACTION_GROUP,
            true,
        );

        if (!$action_id) {
            $this->record_failed(
                __(
                    "The initial product backfill batch could not be scheduled.",
                    "aura-historia-partner-connect",
                ),
            );

            return false;
        }

        $this->reset_admission_status();
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
            "last_completed_page" => "0",
            "accepted_count" => "",
            "failed_count" => "",
            "permanent_failure_count" => "0",
            "last_counted_batch" => "",
            "submission_id" => "",
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
     * @return bool Whether the state was persisted.
     */
    private function update_state($changes)
    {
        $state = $this->get_state();

        foreach ($changes as $key => $value) {
            $state[$key] = (string) $value;
        }

        return update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * Resets admission details when starting a distinct backfill run.
     *
     * @return void
     */
    private function reset_admission_status()
    {
        $this->update_state([
            "last_completed_page" => "0",
            "accepted_count" => "",
            "failed_count" => "",
            "permanent_failure_count" => "0",
            "last_counted_batch" => "",
            "submission_id" => "",
            "completed_at" => "",
        ]);
    }

    /**
     * Records that a backfill batch is queued.
     *
     * @return void
     */
    private function record_scheduled()
    {
        $changes = [
            "status" => self::STATUS_SCHEDULED,
            "scheduled_at" => current_time("mysql"),
            "started_at" => "",
        ];

        if (0 === (int) $this->get_state()["permanent_failure_count"]) {
            $changes["failed_at"] = "";
            $changes["last_error"] = "";
        }

        $this->update_state($changes);
    }

    /**
     * Records that a backfill batch is currently running.
     *
     * @return void
     */
    private function record_running()
    {
        $changes = [
            "status" => self::STATUS_RUNNING,
            "started_at" => current_time("mysql"),
        ];

        if (0 === (int) $this->get_state()["permanent_failure_count"]) {
            $changes["failed_at"] = "";
            $changes["last_error"] = "";
        }

        $this->update_state($changes);
    }

    /**
     * Records that the most recent backfill run finished submitting pages.
     *
     * @param int $page Final page (including an empty trailing page).
     * @return void
     */
    private function record_complete($page)
    {
        $state = $this->get_state();
        $has_permanent_failures = (int) $state["permanent_failure_count"] > 0;
        $changes = [
            "last_completed_page" => max($page, (int) $state["last_completed_page"]),
            "status" => $has_permanent_failures ? self::STATUS_FAILED : self::STATUS_COMPLETE,
            "scheduled_at" => "",
            "started_at" => "",
            "completed_at" => current_time("mysql"),
        ];

        if ($has_permanent_failures) {
            $changes["last_error"] = sprintf(
                "Aura Historia backfill finished with %d permanent listing failures.",
                (int) $this->get_state()["permanent_failure_count"],
            );
        } else {
            $changes["failed_at"] = "";
            $changes["last_error"] = "";
        }

        $this->update_state($changes);
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
            "last_completed_page" => "0",
            "scheduled_at" => "",
            "started_at" => "",
            "failed_at" => "",
            "last_error" => "",
            "accepted_count" => "",
            "failed_count" => "",
            "permanent_failure_count" => "0",
            "last_counted_batch" => "",
            "submission_id" => "",
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
