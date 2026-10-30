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
 * One batch lease serializes snapshot allocation, admission checkpoints and
 * page handoff across requests; every checkpoint commits only while the lease
 * is still held. Each queued action carries a random wake-up token, and only
 * the action holding the run's current wake-up may process. Duplicates,
 * superseded retries and actions of a cancelled or replaced run do nothing.
 * Credentials are read at execution time, never stored in action arguments.
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

    /** Batch lease, separate from the webhook sync and shipping locks. */
    const OPTION_LOCK = "ahpc_backfill_lock";

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

    /** Start outcome: an idle source queued a fresh full catalog run. */
    const START_FRESH = "started";

    /** Start outcome: a retained interrupted batch was requeued unchanged. */
    const START_RESUMED = "resumed";

    /** Start outcome: queued or running work was left unchanged. */
    const START_ALREADY_QUEUED = "already_queued";

    /**
     * Number of products per batch sent to the backend.
     */
    const BATCH_SIZE = 100;

    /** Seconds between attempts to replay an unchanged batch. */
    const RETRY_DELAY = 60;

    /** Lease lifetime; far longer than building and admitting one batch. */
    const LOCK_SECONDS = 120;

    /** Internal signal: the lease or connection changed while a batch ran. */
    private const STALE_WORKER = "ahpc_backfill_stale_worker";

    /** Minor-unit exponents for every currently supported backend currency. */
    private const CURRENCY_MINOR_UNIT_EXPONENTS = [
        "EUR" => 2,
        "GBP" => 2,
        "USD" => 2,
        "AUD" => 2,
        "CAD" => 2,
        "NZD" => 2,
        "CNY" => 2,
        "BRL" => 2,
        "PLN" => 2,
        "TRY" => 2,
        "JPY" => 0,
        "CZK" => 2,
        "RUB" => 2,
        "AED" => 2,
        "SAR" => 2,
        "HKD" => 2,
        "SGD" => 2,
        "CHF" => 2,
        "ZAR" => 2,
        "SEK" => 2,
        "DKK" => 2,
        "NOK" => 2,
        "KRW" => 0,
        "INR" => 2,
        "TWD" => 2,
        "HUF" => 2,
        "RON" => 2,
        "MXN" => 2,
        "THB" => 2,
        "ARS" => 2,
    ];

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
     * Batch lease shared by every request that touches the run.
     *
     * @var Option_Lease
     */
    private $lease;

    public function __construct()
    {
        $this->lease = new Option_Lease(self::OPTION_LOCK);
    }

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

            // Fence the current run now; its fresh successor is queued once
            // Action Scheduler is ready.
            $this->lease->seize(self::LOCK_SECONDS);
            try {
                $this->update_state(array_merge(
                    $this->fresh_run_changes(),
                    ["wake" => wp_generate_uuid4()],
                ));
            } finally {
                $this->lease->release();
            }

            return true;
        }

        return $this->schedule_backfill_now($listing_source_id);
    }

    /**
     * Starts an idle backfill or resumes an orphaned batch for this source.
     *
     * Unlike schedule_backfill(), this never cancels scheduled work or discards
     * a saved request snapshot. A saved batch is only resumed for the exact
     * requested source; an unknown snapshot must be investigated, not reset.
     * Callers can use this for both initial connection and manual starts.
     * Requires Action Scheduler to be initialized; false means nothing was
     * started, so the caller may retry after action_scheduler_init.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @return string|false A START_* outcome, or false when nothing is queued.
     */
    public function start_or_resume_backfill($listing_source_id)
    {
        $listing_source_id = Webhook_Manager::normalize_listing_source_id($listing_source_id);

        if (!Webhook_Manager::is_valid_listing_source_id($listing_source_id)) {
            return false;
        }

        $settings = get_option(Webhook_Manager::OPTION_SETTINGS, []);
        if (
            !is_array($settings) ||
            Webhook_Manager::normalize_listing_source_id(
                isset($settings["listing_source_id"]) ? (string) $settings["listing_source_id"] : "",
            ) !== $listing_source_id
        ) {
            return false;
        }

        if (
            !function_exists("as_schedule_single_action") ||
            !function_exists("as_has_scheduled_action")
        ) {
            $this->record_failed(__(
                "Action Scheduler is not available, so the product backfill could not be queued.",
                "aura-historia-partner-connect",
            ));
            return false;
        }

        if (!$this->is_action_scheduler_ready()) {
            $this->record_failed(__(
                "Action Scheduler is not ready, so the product backfill could not be queued.",
                "aura-historia-partner-connect",
            ));
            return false;
        }

        if (is_array(self::$deferred_operation)) {
            return false;
        }

        return $this->start_or_resume_backfill_now($listing_source_id);
    }

    /**
     * Cancels all pending backfill batches.
     *
     * @return void
     */
    public function cancel_backfill()
    {
        // Take the batch from any running callback, so it cannot checkpoint
        // over this reset. The reset's new wake-up rejects queued work.
        $this->lease->seize(self::LOCK_SECONDS);

        try {
            $this->lease->delete(self::OPTION_BATCH);

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
        } finally {
            $this->lease->release();
        }
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

        return $this->wake_queued($this->get_state()["wake"]);
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
            // A superseded copy waiting to be ignored is not queued work.
            $args = "" === $state["wake"] ? null : $this->wake_action_args($state["wake"]);
            $next_action = "" === $state["wake"] || null !== $args
                ? as_next_scheduled_action(self::ACTION_HOOK, $args, self::ACTION_GROUP)
                : false;

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
     * @param string $wake Wake-up token in the action args; earlier releases queued none.
     * @return void
     * @throws \RuntimeException On retryable request or listing admission failure.
     */
    public function process_batch($listing_source_id, $page, $wake = "")
    {
        $listing_source_id = Webhook_Manager::normalize_listing_source_id((string) $listing_source_id);
        $page = max(1, (int) $page);
        $args = [$listing_source_id, $page];

        if (is_string($wake) && "" !== $wake) {
            $args[] = $wake;
        }

        // Earlier releases queued [source, page] with an optional "retry-"
        // uniqueness suffix. Such an action can only continue a run that has
        // not been given a wake-up yet.
        $wake = is_string($wake) && !str_starts_with($wake, "retry-") ? $wake : "";

        if (null === $this->current_access_token($listing_source_id)) {
            // A stale action must not delete another connection's pending
            // request. Missing credentials may also be repaired before replay.
            return;
        }

        if (!$this->lease->acquire(self::LOCK_SECONDS)) {
            // Another callback owns the batch. Keep this wake-up rather than
            // dropping it; once superseded, the copy is ignored on entry.
            $this->requeue_if_current($args, $wake);
            return;
        }

        try {
            $this->process_owned_batch($listing_source_id, $page, $wake);
        } catch (\RuntimeException $exception) {
            if (self::STALE_WORKER !== $exception->getMessage()) {
                throw $exception;
            }

            // The lease or connection changed mid-batch, and nothing was
            // checkpointed over newer state. If this wake-up is still current,
            // it later replays the unchanged persisted request.
            $this->requeue_if_current($args, $wake);
        } finally {
            $this->lease->release();
        }
    }

    /**
     * Processes the batch while holding the lease.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @param int    $page One-based catalog page.
     * @param string $wake Wake-up token of the running action.
     * @return void
     * @throws \RuntimeException On retryable failure, or STALE_WORKER.
     */
    private function process_owned_batch($listing_source_id, $page, $wake)
    {
        $state = $this->get_state();

        if ($wake !== $state["wake"]) {
            // Cancelled, replaced by a newer run, superseded by a later
            // wake-up of this run, or already finished.
            return;
        }

        $snapshot = Option_Lease::read(self::OPTION_BATCH, []);

        if (
            $page <= (int) $state["last_completed_page"] &&
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
            $product_ids = $this->get_product_ids((int) $state["last_product_id"]);

            if (is_wp_error($product_ids)) {
                $this->record_failed($product_ids->get_error_message());
                throw new \RuntimeException(esc_html($product_ids->get_error_message()));
            }

            if (empty($product_ids)) {
                $this->lease->delete(self::OPTION_BATCH);
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
                "last_product_id" => max($product_ids),
                "local_failures" => $local_failures,
                "payloads" => $payloads,
                "idempotency_key" => wp_generate_uuid4(),
            ];
            // Never send a batch whose request cannot be replayed unchanged.
            if (!$this->lease->write(self::OPTION_BATCH, $snapshot)) {
                $this->assert_owner();
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
            // Resolve ownership and credentials immediately before sending.
            $access_token = $this->assert_current($listing_source_id);
            $client = new Backend_Api_Client(Webhook_Manager::get_backend_base_url());
            $result = $client->post_async_partner_product_listings(
                $listing_source_id,
                $access_token,
                $snapshot["payloads"],
                $snapshot["idempotency_key"],
            );
            // Checkpoint an outcome only while still owning the batch under the
            // connection that produced it. Otherwise the same request replays.
            $this->assert_current($listing_source_id, $access_token);

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
                    $this->schedule_retry($listing_source_id, $page, $message);
                    // Never include free-form backend details in Action Scheduler logs.
                    throw new \RuntimeException(esc_html($message));
                }

                if (!$has_report) {
                    // Retire the wake-up: no copy may rebuild a failed page.
                    $this->update_state(array_merge(
                        $this->failed_changes($message),
                        ["wake" => wp_generate_uuid4()],
                    ));
                    $this->lease->delete(self::OPTION_BATCH);
                    return;
                }
            } else {
                $report = $result;
            }

            $this->record_admission($report, (int) $snapshot["local_failures"]);

            foreach ($report["failures"] as $failure) {
                if (!empty($failure["retryable"])) {
                    $message = sprintf("Aura Historia backfill batch (page %d) has retryable listing admission failures.", $page);
                    $this->schedule_retry($listing_source_id, $page, $message);
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

        if ((int) $snapshot["product_count"] >= self::BATCH_SIZE) {
            $this->schedule_next_page($snapshot, $listing_source_id, $page);
            return;
        }

        if (!$this->lease->delete(self::OPTION_BATCH)) {
            $this->assert_owner();
            $message = sprintf("Aura Historia backfill batch (page %d) could not clear its request snapshot.", $page);
            $this->record_failed($message);
            throw new \RuntimeException(esc_html($message));
        }
        $this->record_complete($page);
    }

    /**
     * Queues a delayed replay under a new wake-up. The persisted request, its
     * payload and idempotency key stay unchanged.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @param int    $page Catalog page.
     * @param string $message Safe failure message.
     * @return void
     */
    private function schedule_retry($listing_source_id, $page, $message)
    {
        if (!$this->queue_wake($listing_source_id, $page, self::RETRY_DELAY, $this->failed_changes($message))) {
            $this->assert_owner();
            $failure = sprintf("Aura Historia backfill batch (page %d) could not schedule its retry.", $page);
            $this->record_failed($failure);
            throw new \RuntimeException(esc_html($failure));
        }
    }

    /**
     * Makes a new wake-up current, then queues the only action carrying it.
     * The state names the wake-up first, so the action never runs ahead of it.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @param int    $page Catalog page.
     * @param int    $delay Seconds until the action is due.
     * @param array  $changes State changes saved with the wake-up.
     * @return bool Whether the action was queued.
     */
    private function queue_wake($listing_source_id, $page, $delay, array $changes = [])
    {
        $wake = wp_generate_uuid4();
        $changes["wake"] = $wake;

        if (!$this->update_state($changes) || !function_exists("as_schedule_single_action")) {
            return false;
        }

        return (bool) as_schedule_single_action(
            time() + $delay,
            self::ACTION_HOOK,
            [$listing_source_id, $page, $wake],
            self::ACTION_GROUP,
            true,
        );
    }

    /**
     * Queues this callback's action again when the lease was unavailable or
     * lost, unless a later wake-up or a reset already superseded it.
     *
     * @param array  $args Original action arguments.
     * @param string $wake Wake-up token of the action.
     * @return void
     */
    private function requeue_if_current(array $args, $wake)
    {
        if (!function_exists("as_schedule_single_action") || $wake !== $this->get_state()["wake"]) {
            return;
        }

        // Not unique: the running action itself still has these arguments.
        as_schedule_single_action(time() + self::RETRY_DELAY, self::ACTION_HOOK, $args, self::ACTION_GROUP);
    }

    /**
     * Returns whether the action carrying this wake-up is queued or running.
     *
     * @param string $wake Wake-up token.
     * @return bool
     */
    private function wake_queued($wake)
    {
        if ("" === $wake) {
            // A run from an earlier release queued actions without wake-ups.
            return (bool) as_has_scheduled_action(self::ACTION_HOOK, null, self::ACTION_GROUP);
        }

        return null !== $this->wake_action_args($wake);
    }

    /**
     * Returns the arguments of the queued or running action carrying a wake-up.
     *
     * @param string $wake Wake-up token.
     * @return array|null
     */
    private function wake_action_args($wake)
    {
        $actions = as_get_scheduled_actions([
            "hook" => self::ACTION_HOOK,
            "group" => self::ACTION_GROUP,
            "status" => [\ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING],
            "search" => $wake,
            "per_page" => 1,
        ]);
        $action = is_array($actions) ? reset($actions) : false;

        return $action instanceof \ActionScheduler_Action ? $action->get_args() : null;
    }

    /**
     * @return void
     * @throws \RuntimeException STALE_WORKER when a successor or reset took the lease.
     */
    private function assert_owner()
    {
        if (!$this->lease->held()) {
            throw new \RuntimeException(esc_html(self::STALE_WORKER));
        }
    }

    /**
     * Confirms the lease and the connection, optionally the one a request used.
     *
     * @param string      $listing_source_id Listing source TypeID.
     * @param string|null $access_token Access token a request was sent with.
     * @return string Current access token.
     * @throws \RuntimeException STALE_WORKER when either changed.
     */
    private function assert_current($listing_source_id, $access_token = null)
    {
        $this->assert_owner();
        $current = $this->current_access_token($listing_source_id);

        if (null === $current || (null !== $access_token && $current !== $access_token)) {
            throw new \RuntimeException(esc_html(self::STALE_WORKER));
        }

        return $current;
    }

    /**
     * Reads the stored connection, bypassing a cache another request made stale.
     *
     * @param string $listing_source_id Listing source TypeID of the action.
     * @return string|null Access token, or null unless this source is connected.
     */
    private function current_access_token($listing_source_id)
    {
        $settings = Option_Lease::read(Webhook_Manager::OPTION_SETTINGS, []);

        if (!is_array($settings)) {
            return null;
        }

        $stored_id = Webhook_Manager::normalize_listing_source_id(
            isset($settings["listing_source_id"]) ? (string) $settings["listing_source_id"] : "",
        );
        $access_token = isset($settings["access_token"])
            ? (string) $settings["access_token"]
            : "";

        return $stored_id === $listing_source_id &&
            Webhook_Manager::is_valid_listing_source_id($listing_source_id) &&
            Webhook_Manager::is_valid_access_token($access_token)
            ? $access_token
            : null;
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
        if (!$this->lease->delete(self::OPTION_BATCH)) {
            $this->assert_owner();
            $message = sprintf("Aura Historia backfill batch (page %d) could not clear its request snapshot.", $page);
            $this->record_failed($message);
            throw new \RuntimeException(esc_html($message));
        }

        if ($this->queue_wake($listing_source_id, $page + 1, 0, $this->scheduled_changes())) {
            return;
        }

        $this->assert_owner();
        $snapshot["handoff_pending"] = true;
        $message = sprintf("Aura Historia backfill batch (page %d) could not schedule page %d.", $page, $page + 1);

        if (!$this->lease->write(self::OPTION_BATCH, $snapshot)) {
            $this->assert_owner();
            $this->record_failed($message);
            throw new \RuntimeException(esc_html($message));
        }

        $this->schedule_retry($listing_source_id, $page, $message);
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
            "last_product_id" => isset($snapshot["last_product_id"])
                ? (int) $snapshot["last_product_id"]
                : (int) $state["last_product_id"],
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
            $this->assert_owner();
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
     * Returns published product IDs strictly after the committed cursor.
     *
     * Using a keyset avoids losing products when earlier posts are unpublished
     * or deleted between batches. The cursor advances past all selected IDs,
     * including products that disappear before their payload is built.
     *
     * @param int $after_id Last selected product ID from the previous batch.
     * @return int[]|\WP_Error
     */
    private function get_product_ids($after_id)
    {
        if (!function_exists("wc_get_product")) {
            return new \WP_Error("ahpc_backfill_products_unavailable", "WooCommerce products could not be read. Retry the product backfill.");
        }

        global $wpdb;
        $after_id = max(0, (int) $after_id);
        $where_after_id = static function ($where, $query) use ($wpdb, $after_id) {
            if (!$query->get("ahpc_backfill_page")) {
                return $where;
            }
            return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after_id);
        };

        // Scope the keyset constraint to this query; get_posts() suppresses
        // filters by default, so use WP_Query directly for the ID selection.
        add_filter("posts_where", $where_after_id, 10, 2);
        try {
            $query = new \WP_Query([
                "post_type" => "product",
                "post_status" => "publish",
                "posts_per_page" => self::BATCH_SIZE,
                "orderby" => "ID",
                "order" => "ASC",
                "fields" => "ids",
                "no_found_rows" => true,
                "ahpc_backfill_page" => true,
            ]);
            if ($wpdb->last_error) {
                return new \WP_Error("ahpc_backfill_product_read_failed", "Products could not be read. Retry the product backfill.");
            }
        } finally {
            remove_filter("posts_where", $where_after_id);
        }

        return array_map("intval", $query->posts);
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

        $amount = $this->convert_price_to_minor_units($raw_price, $currency);

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
     * Converts a decimal string exactly, without display rounding or floats.
     * Only explicitly mapped currencies can be sent; unknown enum additions
     * omit optional price rather than assuming a minor-unit exponent.
     * Extra fractional digits are accepted only when they are all zero.
     *
     * @param string $price WooCommerce decimal price string.
     * @param string $currency Supported ISO currency code.
     * @return int|null
     */
    private function convert_price_to_minor_units($price, $currency)
    {
        $price = trim((string) $price);

        if (1 !== preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/D', $price)) {
            return null;
        }

        if (!array_key_exists($currency, self::CURRENCY_MINOR_UNIT_EXPONENTS)) {
            return null;
        }

        $decimals = self::CURRENCY_MINOR_UNIT_EXPONENTS[$currency];
        $parts = explode(".", $price, 2);
        $fraction = isset($parts[1]) ? $parts[1] : "";

        if (strlen($fraction) > $decimals &&
            "" !== trim(substr($fraction, $decimals), "0")
        ) {
            return null;
        }

        $whole = ltrim($parts[0], "0");
        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, "0");
        $amount = ltrim($whole . $fraction, "0");
        $max = (string) PHP_INT_MAX;

        if (strlen($amount) > strlen($max) ||
            (strlen($amount) === strlen($max) && strcmp($amount, $max) > 0)
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
     * Queues only missing work, retaining any uncertain or in-flight snapshot.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @return string|false A START_* outcome, or false when nothing is queued.
     */
    private function start_or_resume_backfill_now($listing_source_id)
    {
        if ($this->wake_queued($this->get_state()["wake"])) {
            return self::START_ALREADY_QUEUED;
        }

        if (!$this->lease->acquire(self::LOCK_SECONDS)) {
            // A callback is between its checks and nothing current is queued.
            // Report that nothing started, so the caller can try again later.
            return false;
        }

        try {
            if ($this->wake_queued($this->get_state()["wake"])) {
                return self::START_ALREADY_QUEUED;
            }

            $snapshot = Option_Lease::read(self::OPTION_BATCH, false);
            $page = 1;

            if (false !== $snapshot) {
                if (
                    !is_array($snapshot) ||
                    !isset($snapshot["listing_source_id"], $snapshot["page"], $snapshot["payloads"], $snapshot["product_count"], $snapshot["idempotency_key"]) ||
                    $snapshot["listing_source_id"] !== $listing_source_id ||
                    !(is_int($snapshot["page"]) || (is_string($snapshot["page"]) && ctype_digit($snapshot["page"]))) ||
                    (int) $snapshot["page"] < 1 ||
                    !is_array($snapshot["payloads"]) ||
                    !(is_int($snapshot["product_count"]) || (is_string($snapshot["product_count"]) && ctype_digit($snapshot["product_count"]))) ||
                    (int) $snapshot["product_count"] < 1 ||
                    !is_string($snapshot["idempotency_key"]) ||
                    "" === $snapshot["idempotency_key"]
                ) {
                    $this->record_failed(__(
                        "A saved product backfill batch could not be safely resumed.",
                        "aura-historia-partner-connect",
                    ));
                    return false;
                }

                $page = (int) $snapshot["page"];
                if ($page <= (int) $this->get_state()["last_completed_page"] && empty($snapshot["handoff_pending"])) {
                    $this->record_failed(__(
                        "A saved product backfill batch could not be safely resumed.",
                        "aura-historia-partner-connect",
                    ));
                    return false;
                }
            }

            $changes = false === $snapshot ? $this->fresh_run_changes() : $this->scheduled_changes();

            if (!$this->queue_wake($listing_source_id, $page, 0, $changes)) {
                $this->record_failed(__(
                    "The product backfill batch could not be scheduled.",
                    "aura-historia-partner-connect",
                ));
                return false;
            }

            return false === $snapshot ? self::START_FRESH : self::START_RESUMED;
        } finally {
            $this->lease->release();
        }
    }

    /**
     * Schedules the initial backfill batch immediately.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @return bool
     */
    private function schedule_backfill_now($listing_source_id)
    {
        // Take the batch from any running callback; it cannot checkpoint over
        // the fresh run, whose new wake-up also rejects older actions.
        $this->lease->seize(self::LOCK_SECONDS);

        try {
            $this->cancel_backfill_actions();
            $this->lease->delete(self::OPTION_BATCH);

            if (!$this->queue_wake($listing_source_id, 1, 0, $this->fresh_run_changes())) {
                $this->record_failed(
                    __(
                        "The initial product backfill batch could not be scheduled.",
                        "aura-historia-partner-connect",
                    ),
                );

                return false;
            }

            return true;
        } finally {
            $this->lease->release();
        }
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
     * Returns the stored backfill state with guaranteed defaults. It is read
     * from the database because another request may have advanced the run.
     *
     * @return array<string,string>
     */
    private function get_state()
    {
        $state = Option_Lease::read(self::OPTION_STATE, []);

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
            "last_product_id" => "0",
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
            // The only queued action allowed to process next. A run that has
            // stopped holds a value that no action carries.
            "wake" => "",
        ];
    }

    /**
     * Persists the current backfill state under the batch lease. A caller
     * outside a callback takes the lease briefly; while a callback owns the
     * batch, that callback's status is current and the write is skipped.
     *
     * @param array<string,string> $changes State changes to store.
     * @return bool Whether the state was persisted.
     */
    private function update_state($changes)
    {
        $owned = $this->lease->owned();

        if (!$owned && !$this->lease->acquire(self::LOCK_SECONDS)) {
            return false;
        }

        try {
            $state = $this->get_state();

            foreach ($changes as $key => $value) {
                $state[$key] = (string) $value;
            }

            return $this->lease->write(self::OPTION_STATE, $state);
        } finally {
            if (!$owned) {
                $this->lease->release();
            }
        }
    }

    /**
     * Resets admission details and status when starting a distinct backfill run.
     *
     * @return array<string,string>
     */
    private function fresh_run_changes()
    {
        return array_merge($this->scheduled_changes(), [
            "last_completed_page" => "0",
            "last_product_id" => "0",
            "accepted_count" => "",
            "failed_count" => "",
            "permanent_failure_count" => "0",
            "last_counted_batch" => "",
            "submission_id" => "",
            "completed_at" => "",
            "failed_at" => "",
            "last_error" => "",
        ]);
    }

    /**
     * Status changes for a queued backfill batch.
     *
     * @return array<string,string>
     */
    private function scheduled_changes()
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

        return $changes;
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
     * Records that the most recent backfill run finished submitting pages,
     * and retires its wake-up.
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
            "wake" => wp_generate_uuid4(),
        ];

        if ($has_permanent_failures) {
            $changes["last_error"] = sprintf(
                "Aura Historia backfill finished with %d permanent listing failures.",
                (int) $state["permanent_failure_count"],
            );
        } else {
            $changes["failed_at"] = "";
            $changes["last_error"] = "";
        }

        $this->update_state($changes);
    }

    /**
     * Records that no backfill is currently scheduled, and retires the
     * cancelled run's wake-up.
     *
     * @return void
     */
    private function record_not_scheduled()
    {
        $this->update_state([
            "status" => self::STATUS_NOT_SCHEDULED,
            "last_completed_page" => "0",
            "last_product_id" => "0",
            "scheduled_at" => "",
            "started_at" => "",
            "failed_at" => "",
            "last_error" => "",
            "accepted_count" => "",
            "failed_count" => "",
            "permanent_failure_count" => "0",
            "last_counted_batch" => "",
            "submission_id" => "",
            "wake" => wp_generate_uuid4(),
        ]);
    }

    /**
     * Status changes for a failed backfill attempt.
     *
     * @param string $message Error detail.
     * @return array<string,string>
     */
    private function failed_changes($message)
    {
        return [
            "status" => self::STATUS_FAILED,
            "scheduled_at" => "",
            "started_at" => "",
            "failed_at" => current_time("mysql"),
            "last_error" => sanitize_text_field((string) $message),
        ];
    }

    /**
     * Records that the most recent backfill attempt failed.
     *
     * @param string $message Error detail.
     * @return void
     */
    private function record_failed($message)
    {
        $this->update_state($this->failed_changes($message));
    }
}
