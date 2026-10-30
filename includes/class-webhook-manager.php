<?php
/**
 * Webhook manager.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

use WC_Data_Store;
use WC_Webhook;
use WP_Error;

if (!defined("ABSPATH")) {
    exit();
}

require_once __DIR__ . "/class-type-id-validator.php";

/**
 * Manages the WooCommerce webhooks owned by this plugin.
 */
class Webhook_Manager
{
    const OPTION_SETTINGS = "ahpc_settings";
    const OPTION_WEBHOOK_IDS = "ahpc_webhook_ids";
    const OPTION_WEBHOOK_USER_ID = "ahpc_webhook_user_id";
    const OPTION_NEEDS_SYNC = "ahpc_needs_sync";
    const OPTION_PLUGIN_VERSION = "ahpc_plugin_version";
    const OPTION_LAST_SYNC_ERROR = "ahpc_last_sync_error";
    const OPTION_LAST_SYNC_AT = "ahpc_last_sync_at";
    const OPTION_LAST_OAUTH_ERROR = "ahpc_last_oauth_error";
    /** Sync lease, separate from the backfill and shipping locks. */
    const OPTION_SYNC_LOCK = "ahpc_webhook_sync_lock";
    /** Sync lease lifetime; far longer than staging, one PUT and activation. */
    const SYNC_LOCK_SECONDS = 120;
    /** Seconds an interactive sync waits for another request's sync; covers one PUT timeout. */
    const SYNC_WAIT_SECONDS = 20;
    /** Changes whenever the connection or store context changes. */
    const OPTION_SYNC_CONTEXT = "ahpc_sync_context";
    /** Background reconciliation attempts of the current context. */
    const OPTION_SYNC_RETRY = "ahpc_webhook_sync_retry";
    /** Action Scheduler hook of the background reconciliation. */
    const SYNC_ACTION_HOOK = "ahpc_reconcile_webhooks";
    /** Shared with the catalog backfill; the hook keeps the actions apart. */
    const SYNC_ACTION_GROUP = "ahpc-partner-connect";
    /** Seconds before the first automatic retry; each further retry waits twice as long. */
    const SYNC_RETRY_DELAY = 60;
    /** Longest wait between the backed-off attempts. */
    const SYNC_MAX_RETRY_DELAY = 3600;
    /** Failed attempts in a row that back off; later ones wait SYNC_SLOW_RETRY_DELAY. */
    const SYNC_BACKOFF_ATTEMPTS = 10;
    /** Seconds between attempts after the backoff, and after a non-retryable failure. */
    const SYNC_SLOW_RETRY_DELAY = 21600;
    /** Reconciliation status: queued for the first attempt. */
    const SYNC_PENDING = "pending";
    /** Reconciliation status: a retryable failure, another attempt is queued. */
    const SYNC_RETRYING = "retrying";
    /** Reconciliation status: a failure that retrying alone rarely fixes; still retried slowly. */
    const SYNC_NEEDS_ATTENTION = "needs_attention";
    /** Reconciliation status: the current configuration is confirmed. */
    const SYNC_CONFIRMED = "confirmed";
    const SETTINGS_GROUP = "ahpc_settings_group";
    const API_VERSION = 3;
    const PRODUCT_DELETED_HOOK = "ahpc_product_permanently_deleted";

    /**
     * Whether a sync operation is currently in progress.
     *
     * @var bool
     */
    private $syncing = false;

    /**
     * Context and kind of the running sync, for recording its outcome.
     *
     * @var array{context:string,automatic:bool}|null
     */
    private $attempt = null;

    /**
     * Returns the default plugin settings.
     *
     * @return array<string,mixed>
     */
    public static function default_settings()
    {
        return [
            "listing_source_id" => "",
            "access_token" => "",
            "secret" => "",
        ];
    }

    /**
     * Generates a new webhook secret.
     *
     * @return string
     */
    public static function generate_secret()
    {
        return wp_generate_password(40, false, false);
    }

    /**
     * Normalizes a listing source TypeID.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @return string
     */
    public static function normalize_listing_source_id($listing_source_id)
    {
        return is_string($listing_source_id) ? $listing_source_id : "";
    }

    /**
     * Checks the canonical ls_ TypeID format and UUIDv7 payload.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @return bool
     */
    public static function is_valid_listing_source_id($listing_source_id)
    {
        return Type_ID_Validator::is_valid($listing_source_id, "ls_");
    }

    /**
     * Preserves an opaque bearer token exactly as issued. Altering whitespace or
     * control characters here could turn an invalid credential into a valid one.
     *
     * @param mixed $access_token Aura Historia access token.
     * @return string
     */
    public static function normalize_access_token($access_token)
    {
        return is_string($access_token) ? $access_token : "";
    }

    /**
     * Accepts nonempty RFC 6750 bearer-token characters without assuming a
     * particular backend token prefix. Whitespace and control bytes cannot enter
     * an Authorization header.
     *
     * @param mixed $access_token Aura Historia access token.
     * @return bool
     */
    public static function is_valid_access_token($access_token)
    {
        return is_string($access_token) &&
            1 === preg_match('/\A[A-Za-z0-9\-._~+\/]+=*\z/', $access_token);
    }

    /**
     * Returns the hardcoded backend base URL.
     *
     * @return string
     */
    public static function get_backend_base_url()
    {
        $url = defined("AHPC_BACKEND_BASE_URL") ? AHPC_BACKEND_BASE_URL : "";

        /**
         * Filters the hardcoded backend base URL.
         *
         * @param string $url Backend base URL.
         */
        $url = apply_filters("ahpc_backend_base_url", $url);

        $url = esc_url_raw(trim((string) $url), ["http", "https"]);

        return $url ? untrailingslashit($url) : "";
    }

    /**
     * Returns the backend webhook delivery URL.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @return string
     */
    public static function get_webhook_endpoint_url($listing_source_id)
    {
        $base_url = self::get_backend_base_url();

        if (!$base_url || !self::is_valid_listing_source_id($listing_source_id)) {
            return "";
        }

        return $base_url .
            "/v1/webhooks/woocommerce/" .
            rawurlencode($listing_source_id);
    }

    /**
     * Initializes the plugin options.
     *
     * @return void
     */
    public function initialize_options()
    {
        $settings = get_option(self::OPTION_SETTINGS, false);

        if (false === $settings) {
            $settings = self::default_settings();
            $settings["secret"] = self::generate_secret();
            add_option(self::OPTION_SETTINGS, $settings, "", false);
        } else {
            $this->get_settings();
        }

        if (false === get_option(self::OPTION_WEBHOOK_IDS, false)) {
            add_option(self::OPTION_WEBHOOK_IDS, [], "", false);
        }

        if (false === get_option(self::OPTION_NEEDS_SYNC, false)) {
            add_option(self::OPTION_NEEDS_SYNC, "yes", "", false);
        }
    }

    /**
     * Adds bearer auth only to signed deliveries of our managed webhooks.
     *
     * @param array  $args HTTP request arguments.
     * @param string $url  Request URL.
     * @return array
     */
    public static function authorize_delivery($args, $url)
    {
        $manager = new self();
        $settings = $manager->get_settings();
        if (
            !self::is_valid_listing_source_id($settings["listing_source_id"]) ||
            !self::is_valid_access_token($settings["access_token"]) ||
            self::get_webhook_endpoint_url($settings["listing_source_id"]) !== $url ||
            !is_array($args) ||
            empty($args["headers"]) ||
            !is_array($args["headers"])
        ) {
            return $args;
        }

        $headers = array_change_key_case($args["headers"], CASE_LOWER);
        $id = isset($headers["x-wc-webhook-id"])
            ? absint($headers["x-wc-webhook-id"])
            : 0;
        $topic = isset($headers["x-wc-webhook-topic"])
            ? $headers["x-wc-webhook-topic"]
            : "";
        $ids = $manager->get_webhook_ids();
        $payload = isset($args["body"]) && is_string($args["body"])
            ? json_decode($args["body"], true)
            : null;
        $signature = isset($headers["x-wc-webhook-signature"])
            ? $headers["x-wc-webhook-signature"]
            : "";
        if (
            !$id ||
            !is_string($topic) ||
            !isset($ids[$topic]) ||
            $ids[$topic] !== $id ||
            !is_string($signature) ||
            !is_array($payload) ||
            !isset($payload["id"]) ||
            !hash_equals(
                base64_encode(hash_hmac("sha256", $args["body"], $settings["secret"], true)),
                $signature,
            ) ||
            array_key_exists("authorization", $headers)
        ) {
            return $args;
        }

        $args["headers"]["Authorization"] = "Bearer " . $settings["access_token"];
        return $args;
    }

    /**
     * WooCommerce's product.deleted topic only listens to wp_trash_post, so a
     * permanent deletion without Trash never reaches the backend. Subscribe the
     * managed product.deleted webhook, and no merchant webhook, to permanent
     * deletions as well. Delivery stays WooCommerce's signed {"id"} payload.
     *
     * @param array<string,array<int,string>> $topic_hooks Topic hook map.
     * @param WC_Webhook|null                 $webhook     Webhook being enqueued.
     * @return array<string,array<int,string>>
     */
    public static function add_permanent_deletion_hook($topic_hooks, $webhook = null)
    {
        if (!is_array($topic_hooks) || !$webhook instanceof WC_Webhook || !$webhook->get_id()) {
            return $topic_hooks;
        }

        $ids = (new self())->get_webhook_ids();
        if (!isset($ids["product.deleted"]) || $ids["product.deleted"] !== absint($webhook->get_id())) {
            return $topic_hooks;
        }

        $hooks = isset($topic_hooks["product.deleted"]) ? (array) $topic_hooks["product.deleted"] : [];
        if (!in_array(self::PRODUCT_DELETED_HOOK, $hooks, true)) {
            $hooks[] = self::PRODUCT_DELETED_HOOK;
        }
        $topic_hooks["product.deleted"] = $hooks;

        return $topic_hooks;
    }

    /**
     * Announces a parent product whose row WordPress has actually deleted;
     * deleted_post never fires for a failed delete, so an unreadable or still
     * present product is never reported. Variations have no listing of their
     * own, and deleting one must not withdraw its published parent. After
     * switch_to_blog() the deleted ID belongs to another site's catalog, while
     * the loaded webhooks still deliver to the current site's source.
     *
     * @param int          $post_id Deleted post ID.
     * @param \WP_Post|null $post   Deleted post as read before deletion.
     * @return void
     */
    public static function announce_permanent_deletion($post_id, $post = null)
    {
        if (
            (is_multisite() && ms_is_switched()) ||
            !$post instanceof \WP_Post ||
            absint($post_id) !== (int) $post->ID ||
            "product" !== $post->post_type ||
            "auto-draft" === $post->post_status
        ) {
            return;
        }

        /**
         * Fires after a parent product row was permanently deleted. The name
         * equals PRODUCT_DELETED_HOOK; a literal lets Plugin Check verify it.
         *
         * @param int $product_id Deleted product ID, the catalog source listing ID.
         */
        do_action("ahpc_product_permanently_deleted", (int) $post->ID);
    }

    /**
     * Marks the managed webhooks for synchronization.
     *
     * @return void
     */
    public function mark_sync_required()
    {
        update_option(self::OPTION_NEEDS_SYNC, "yes", false);
    }

    /**
     * Marks a change of the context a sync registers: the connection
     * settings, store currency or language. A sync that already read the
     * previous context neither activates nor reports it as synchronized.
     *
     * @return void
     */
    public function mark_context_changed()
    {
        update_option(self::OPTION_SYNC_CONTEXT, wp_generate_uuid4(), false);
        $this->mark_sync_required();
    }

    /**
     * Fences in-flight syncs after the registered connection changed, and
     * pauses delivery until the background reconciliation registers the new
     * one. A valid token rotation keeps the context: deliveries read the
     * token late.
     *
     * @param mixed $old_value Previous settings.
     * @param mixed $value     New settings.
     * @return void
     */
    public static function connection_settings_changed($old_value, $value)
    {
        $old_value = is_array($old_value) ? $old_value : [];
        $value = is_array($value) ? $value : [];

        if (
            ($old_value["listing_source_id"] ?? "") !== ($value["listing_source_id"] ?? "") ||
            ($old_value["secret"] ?? "") !== ($value["secret"] ?? "") ||
            self::is_valid_access_token($old_value["access_token"] ?? "") !==
                self::is_valid_access_token($value["access_token"] ?? "")
        ) {
            $manager = new self();
            $manager->mark_context_changed();
            $manager->pause_webhooks();
        }
    }

    /**
     * Returns the stored settings.
     *
     * @return array<string,mixed>
     */
    public function get_settings()
    {
        $settings = get_option(self::OPTION_SETTINGS, []);

        if (!is_array($settings)) {
            $settings = [];
        }

        $stored = wp_parse_args($settings, self::default_settings());
        $settings = [
            "listing_source_id" => self::normalize_listing_source_id(
                $stored["listing_source_id"],
            ),
            "access_token" => self::normalize_access_token(
                $stored["access_token"],
            ),
            "secret" => sanitize_text_field((string) $stored["secret"]),
        ];

        if ("" === $settings["secret"]) {
            $settings["secret"] = self::generate_secret();
            update_option(self::OPTION_SETTINGS, $settings, false);
        }

        return $settings;
    }

    /**
     * Returns the topics managed by the plugin.
     *
     * @return array<string,string>
     */
    public function get_managed_topics()
    {
        return [
            "product.created" => __(
                "Product created",
                "aura-historia-partner-connect",
            ),
            "product.updated" => __(
                "Product updated",
                "aura-historia-partner-connect",
            ),
            "product.deleted" => __(
                "Product deleted",
                "aura-historia-partner-connect",
            ),
        ];
    }

    /**
     * Returns the name used for a managed webhook.
     *
     * @param string $topic Webhook topic.
     * @return string
     */
    public function get_webhook_name($topic)
    {
        return sprintf("Aura Historia Partner Connect - %s", $topic);
    }

    /**
     * Returns the stored managed webhook IDs.
     *
     * @return array<string,int>
     */
    public function get_webhook_ids()
    {
        $webhook_ids = get_option(self::OPTION_WEBHOOK_IDS, []);

        if (!is_array($webhook_ids)) {
            return [];
        }

        $normalized_ids = [];

        foreach ($webhook_ids as $topic => $webhook_id) {
            $normalized_ids[(string) $topic] = absint($webhook_id);
        }

        return $normalized_ids;
    }

    /**
     * Returns whether a sync should run, then runs it if required. Only
     * interactive admin actions call this; ordinary requests queue the
     * background reconciliation instead.
     *
     * @param int $wait Seconds to wait for a sync running in another request.
     * @return bool
     */
    public function maybe_sync_webhooks($wait = 0)
    {
        if ("yes" !== get_option(self::OPTION_NEEDS_SYNC, "yes")) {
            return true;
        }

        return !is_wp_error($this->sync_webhooks($wait));
    }

    /**
     * Synchronizes the managed webhooks now, on behalf of a merchant action.
     * A failure restarts the automatic attempts with a fresh backoff.
     *
     * @param int $wait Seconds to wait for a sync running in another request.
     * @return true|WP_Error
     */
    public function sync_webhooks($wait = 0)
    {
        return $this->run_sync($wait, null);
    }

    /**
     * Action Scheduler callback of the background reconciliation. Only the
     * action carrying the current wake-up syncs; an action queued for an
     * earlier attempt, context or connection does nothing.
     *
     * @param string $wake Wake-up token in the action args.
     * @return true|WP_Error|null Null when the action was obsolete.
     */
    public function reconcile($wake = "")
    {
        if (!is_string($wake) || "" === $wake || $wake !== $this->get_sync_retry(true)["wake"]) {
            return null;
        }

        $result = $this->run_sync(0, $wake);

        if (
            is_wp_error($result) &&
            "ahpc_sync_in_progress" === $result->get_error_code() &&
            $wake === $this->get_sync_retry(true)["wake"] &&
            function_exists("as_schedule_single_action")
        ) {
            // A sync holding the lease records its own outcome and retires
            // this wake-up. A request that was only queueing does not, so keep
            // the wake-up. Not unique: this running action still carries it.
            as_schedule_single_action(time() + self::SYNC_RETRY_DELAY, self::SYNC_ACTION_HOOK, [$wake], self::SYNC_ACTION_GROUP);
        }

        return $result;
    }

    /**
     * Queues the background reconciliation while a sync is required, unless
     * an action for the current attempt is already queued. Runs on ordinary
     * requests, so it never contacts the backend and normally costs one
     * Action Scheduler lookup.
     *
     * @return bool Whether this call queued an action.
     */
    public function maybe_queue_sync()
    {
        if (
            "yes" !== get_option(self::OPTION_NEEDS_SYNC, "yes") ||
            !function_exists("as_schedule_single_action") ||
            !function_exists("as_has_scheduled_action") ||
            !did_action("action_scheduler_init")
        ) {
            return false;
        }

        $state = $this->get_sync_retry();
        if ($this->sync_action_queued($state["wake"])) {
            return false;
        }

        // Concurrent requests must not queue competing attempts. A sync that
        // holds the lease schedules its own retry.
        $lease = new Option_Lease(self::OPTION_SYNC_LOCK);
        if (!$lease->acquire(self::SYNC_LOCK_SECONDS)) {
            return false;
        }

        try {
            $state = $this->get_sync_retry(true);
            if (
                "yes" !== Option_Lease::read(self::OPTION_NEEDS_SYNC, "yes") ||
                $this->sync_action_queued($state["wake"])
            ) {
                return false;
            }

            // Keep the backoff of a retry whose action went missing.
            return $this->queue_sync_action($lease, $state, max(0, $state["next_at"] - time()));
        } finally {
            $lease->release();
        }
    }

    /**
     * Clears the automatic attempts so the next request queues a fresh
     * reconciliation, for example after activation or a plugin update.
     *
     * @return void
     */
    public function reset_sync_retries()
    {
        delete_option(self::OPTION_SYNC_RETRY);
    }

    /**
     * Stops background reconciliation on deactivation. Any queued or running
     * action loses its wake-up, and a running sync loses its lease, so it can
     * neither activate delivery nor record an outcome afterwards. Activation
     * resumes with a fresh attempt.
     *
     * @return void
     */
    public function stop_sync()
    {
        $lease = new Option_Lease(self::OPTION_SYNC_LOCK);
        $lease->seize(self::SYNC_LOCK_SECONDS);

        try {
            $this->reset_sync_retries();
            $this->unschedule_sync_actions();
        } finally {
            $lease->release();
        }
    }

    /**
     * Returns the reconciliation status for the settings page.
     *
     * @return array{status:string,attempts:int,backoff_attempts:int,next_at:int}
     */
    public function get_sync_status()
    {
        $state = $this->get_sync_retry();

        if ("yes" !== get_option(self::OPTION_NEEDS_SYNC, "yes")) {
            $status = self::SYNC_CONFIRMED;
        } else {
            $status = "" !== $state["status"] ? $state["status"] : self::SYNC_PENDING;
        }

        return [
            "status" => $status,
            "attempts" => $state["attempts"],
            "backoff_attempts" => self::SYNC_BACKOFF_ATTEMPTS,
            "next_at" => self::SYNC_CONFIRMED === $status ? 0 : $state["next_at"],
        ];
    }

    /**
     * Returns the seconds to wait after a number of failed attempts in a row:
     * exponential backoff capped at an hour, then a slow period that keeps
     * retrying until the backend is back.
     *
     * @param int $attempts Failed attempts so far, at least one.
     * @return int
     */
    public static function sync_retry_delay($attempts)
    {
        if ((int) $attempts >= self::SYNC_BACKOFF_ATTEMPTS) {
            return self::SYNC_SLOW_RETRY_DELAY;
        }

        $exponent = min(max(0, (int) $attempts - 1), 16);

        return (int) min(self::SYNC_RETRY_DELAY * (2 ** $exponent), self::SYNC_MAX_RETRY_DELAY);
    }

    /**
     * Returns the automatic attempts of the current context. Attempts left by
     * another connection, currency or language count as a fresh start, so a
     * context change is attempted at once, without a slowed-down backoff.
     *
     * @param bool $fresh Read from the database instead of the option cache.
     * @return array{context:string,status:string,attempts:int,next_at:int,wake:string}
     */
    private function get_sync_retry($fresh = false)
    {
        return self::normalize_sync_retry(
            $fresh ? Option_Lease::read(self::OPTION_SYNC_RETRY, []) : get_option(self::OPTION_SYNC_RETRY, []),
            (string) ($fresh ? Option_Lease::read(self::OPTION_SYNC_CONTEXT, "") : get_option(self::OPTION_SYNC_CONTEXT, "")),
        );
    }

    /**
     * @param mixed  $state   Stored attempts.
     * @param string $context Current sync context.
     * @return array{context:string,status:string,attempts:int,next_at:int,wake:string}
     */
    private static function normalize_sync_retry($state, $context)
    {
        $state = is_array($state) && ($state["context"] ?? null) === $context ? $state : [];
        $status = $state["status"] ?? "";

        return [
            "context" => $context,
            "status" => in_array($status, [self::SYNC_PENDING, self::SYNC_RETRYING, self::SYNC_NEEDS_ATTENTION], true) ? $status : "",
            "attempts" => max(0, (int) ($state["attempts"] ?? 0)),
            "next_at" => max(0, (int) ($state["next_at"] ?? 0)),
            "wake" => isset($state["wake"]) && is_string($state["wake"]) ? $state["wake"] : "",
        ];
    }

    /**
     * Makes a new wake-up current, then queues the only action carrying it;
     * actions of earlier attempts are removed. Without Action Scheduler the
     * attempt stays due, and a later request queues it.
     *
     * @param Option_Lease $lease Held sync lease.
     * @param array        $state Attempts to save with the wake-up.
     * @param int          $delay Seconds until the attempt is due.
     * @return bool Whether the action was queued.
     */
    private function queue_sync_action(Option_Lease $lease, array $state, $delay)
    {
        $state["wake"] = "wake-" . wp_generate_uuid4();
        $state["next_at"] = time() + $delay;
        if ("" === $state["status"]) {
            $state["status"] = self::SYNC_PENDING;
        }

        if (
            !$lease->write(self::OPTION_SYNC_RETRY, $state) ||
            !function_exists("as_schedule_single_action") ||
            !did_action("action_scheduler_init")
        ) {
            return false;
        }

        $this->unschedule_sync_actions();

        return (bool) as_schedule_single_action(
            $state["next_at"],
            self::SYNC_ACTION_HOOK,
            [$state["wake"]],
            self::SYNC_ACTION_GROUP,
            true,
        );
    }

    /**
     * Retires the current wake-up, so no queued action syncs again.
     *
     * @param Option_Lease $lease Held sync lease.
     * @param array        $state Attempts to keep for the status.
     * @return bool
     */
    private function retire_sync_wake(Option_Lease $lease, array $state)
    {
        $state["wake"] = "wake-" . wp_generate_uuid4();
        $state["next_at"] = 0;
        $written = $lease->write(self::OPTION_SYNC_RETRY, $state);
        $this->unschedule_sync_actions();

        return $written;
    }

    /**
     * @param string $wake Wake-up token.
     * @return bool Whether the action carrying it is queued or running.
     */
    private function sync_action_queued($wake)
    {
        return "" !== $wake &&
            function_exists("as_has_scheduled_action") &&
            as_has_scheduled_action(self::SYNC_ACTION_HOOK, [$wake], self::SYNC_ACTION_GROUP);
    }

    /**
     * Removes queued reconciliation actions; a running one is left to finish.
     *
     * @return void
     */
    private function unschedule_sync_actions()
    {
        if (function_exists("as_unschedule_all_actions") && did_action("action_scheduler_init")) {
            as_unschedule_all_actions(self::SYNC_ACTION_HOOK, null, self::SYNC_ACTION_GROUP);
        }
    }

    /**
     * Decides when reconciliation tries again after a failure recorded under
     * the lease; it never gives up on its own. Retryable failures (no
     * response, timeout, HTTP 408, 429 or 5xx, or a local save error) back off
     * exponentially for SYNC_BACKOFF_ATTEMPTS attempts, then retry every
     * SYNC_SLOW_RETRY_DELAY. Other failures, such as a rejected token or an
     * unsupported currency, need attention but are still retried at that slow
     * pace, in case the backend caused them. A failure of an interactive sync
     * starts a new series; a changed context is attempted again right away.
     *
     * @param Option_Lease $lease Held sync lease.
     * @param WP_Error     $error Recorded sync error.
     * @return void
     */
    private function schedule_sync_retry(Option_Lease $lease, WP_Error $error)
    {
        if (null === $this->attempt) {
            return;
        }

        $context = (string) Option_Lease::read(self::OPTION_SYNC_CONTEXT, "");
        if ("ahpc_connection_changed" === $error->get_error_code() || $context !== $this->attempt["context"]) {
            $this->queue_sync_action($lease, self::normalize_sync_retry([], $context), 0);
            return;
        }

        $state = self::normalize_sync_retry(Option_Lease::read(self::OPTION_SYNC_RETRY, []), $context);
        // An automatic attempt was counted when it started.
        $state["attempts"] = $this->attempt["automatic"] ? max(1, $state["attempts"]) : 1;
        $data = $error->get_error_data();

        if (!is_array($data) || empty($data["retryable"])) {
            $state["status"] = self::SYNC_NEEDS_ATTENTION;
            $this->queue_sync_action($lease, $state, self::SYNC_SLOW_RETRY_DELAY);
            return;
        }

        $state["status"] = self::SYNC_RETRYING;
        $this->queue_sync_action($lease, $state, self::sync_retry_delay($state["attempts"]));
    }

    /**
     * Synchronizes the managed webhooks.
     *
     * @param int         $wait Seconds to wait for a sync running in another request.
     * @param string|null $wake Wake-up token of a background reconciliation.
     * @return true|WP_Error
     */
    private function run_sync($wait, $wake)
    {
        if (
            !class_exists("WC_Webhook") ||
            !function_exists("wc_is_webhook_valid_topic")
        ) {
            return $this->record_sync_error(
                new WP_Error(
                    "ahpc_missing_woocommerce",
                    __(
                        "WooCommerce webhook APIs are not available yet.",
                        "aura-historia-partner-connect",
                    ),
                ),
            );
        }

        // Never interleave two read/create/register/activate sequences. The
        // holder records its own outcome; a sync stays required until one
        // succeeds for the current settings.
        $lease = new Option_Lease(self::OPTION_SYNC_LOCK);
        if (!$lease->acquire(self::SYNC_LOCK_SECONDS, $wait)) {
            return new WP_Error(
                "ahpc_sync_in_progress",
                __(
                    "Another request is synchronizing the webhooks right now. Try again in a moment.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        try {
            if (null !== $wake) {
                // Recheck under the lease: another sync may have finished or
                // retired this attempt since the action started.
                $state = $this->get_sync_retry(true);
                if ($wake !== $state["wake"]) {
                    return new WP_Error(
                        "ahpc_sync_superseded",
                        __(
                            "Another synchronization took over before this one finished. Retry the synchronization.",
                            "aura-historia-partner-connect",
                        ),
                    );
                }

                if ("yes" !== Option_Lease::read(self::OPTION_NEEDS_SYNC, "yes")) {
                    return true;
                }

                // Count the attempt before any outbound request, so one that
                // ends without recording an outcome, for example after a fatal
                // error, still backs off.
                ++$state["attempts"];
                $state["next_at"] = time() + self::sync_retry_delay($state["attempts"]);
                if (!$lease->write(self::OPTION_SYNC_RETRY, $state)) {
                    return $this->abandon_sync();
                }
            }

            $this->initialize_options();
            $this->syncing = true;
            $settings = $this->read_current_settings();
            // Read after the settings, which may generate a missing secret.
            $context = (string) Option_Lease::read(self::OPTION_SYNC_CONTEXT, "");
            $this->attempt = ["context" => $context, "automatic" => null !== $wake];
            $listing_source_id = $settings["listing_source_id"];
            $access_token = $settings["access_token"];
            $endpoint_url = self::get_webhook_endpoint_url($listing_source_id);
            $user_id = $this->resolve_webhook_user_id();
            $webhook_ids = $this->get_webhook_ids();
            $setup_error = null;
            $has_listing_source_id = "" !== $listing_source_id;
            $has_access_token = "" !== $access_token;

            if (!$user_id) {
                return $this->fail_sync(
                    $lease,
                    new WP_Error(
                        "ahpc_missing_user",
                        __(
                            "No administrator or shop manager account was found for webhook delivery context.",
                            "aura-historia-partner-connect",
                        ),
                    ),
                );
            }

            if ($has_listing_source_id || $has_access_token) {
                if (!self::get_backend_base_url()) {
                    $setup_error = new WP_Error(
                        "ahpc_missing_backend_base_url",
                        __(
                            "Aura Historia is not fully configured inside the plugin. Define AHPC_BACKEND_BASE_URL before connecting a store.",
                            "aura-historia-partner-connect",
                        ),
                    );
                } elseif ($has_listing_source_id && !self::is_valid_listing_source_id($listing_source_id)) {
                    $setup_error = new WP_Error(
                        "ahpc_invalid_listing_source_id",
                        __(
                            "The connection returned an invalid Aura Historia Listing Source ID. Reconnect this store and try once more.",
                            "aura-historia-partner-connect",
                        ),
                    );
                } elseif ($has_access_token && !self::is_valid_access_token($access_token)) {
                    $setup_error = new WP_Error(
                        "ahpc_invalid_access_token",
                        __(
                            "The OAuth connection returned an invalid Aura Historia access token. Reconnect this store and try once more.",
                            "aura-historia-partner-connect",
                        ),
                    );
                } elseif (!$has_listing_source_id || !$has_access_token) {
                    $setup_error = null;
                } elseif ("" === $endpoint_url) {
                    $setup_error = new WP_Error(
                        "ahpc_empty_webhook_endpoint_url",
                        __(
                            "The built-in webhook delivery URL could not be built from the current configuration.",
                            "aura-historia-partner-connect",
                        ),
                    );
                } elseif (null === Store_Locale::get_currency()) {
                    $setup_error = new WP_Error(
                        "ahpc_unsupported_currency",
                        __(
                            "The WooCommerce store currency is not supported by Aura Historia. Select a supported currency before connecting this store.",
                            "aura-historia-partner-connect",
                        ),
                    );
                }
            }

            // Persist all local changes in a paused state before sending the
            // signing secret to the backend. A failed PUT must never leave an
            // old active webhook delivering with a new or unregistered secret.
            foreach (array_keys($this->get_managed_topics()) as $topic) {
                if (!$lease->held()) {
                    // A successor owns the webhooks; never create or save a copy.
                    return $this->abandon_sync();
                }

                if (!wc_is_webhook_valid_topic($topic)) {
                    return $this->fail_sync(
                        $lease,
                        new WP_Error(
                            "ahpc_invalid_topic",
                            sprintf(
                                /* translators: %s: webhook topic. */
                                __(
                                    'WooCommerce does not recognise the webhook topic "%s".',
                                    "aura-historia-partner-connect",
                                ),
                                $topic,
                            ),
                        ),
                    );
                }

                try {
                    $webhook = $this->get_or_create_webhook(
                        $topic,
                        $webhook_ids,
                    );
                } catch (\Throwable $exception) {
                    return $this->fail_sync(
                        $lease,
                        new WP_Error(
                            "ahpc_webhook_load_failed",
                            __("A managed webhook could not be loaded. Retry the synchronization.", "aura-historia-partner-connect"),
                            ["retryable" => true],
                        ),
                    );
                }

                try {
                    $save_result = $this->save_managed_webhook(
                        $webhook,
                        $topic,
                        "paused",
                        $endpoint_url,
                        $settings["secret"],
                        $user_id,
                    );
                } catch (\Throwable $exception) {
                    $save_result = new WP_Error(
                        "ahpc_webhook_save_failed",
                        __("A managed webhook could not be saved. Retry the synchronization.", "aura-historia-partner-connect"),
                        ["retryable" => true],
                    );
                }

                if (is_wp_error($save_result)) {
                    return $this->fail_sync($lease, $save_result);
                }

                $webhook_ids[$topic] = absint($webhook->get_id());
                // Preserve newly created IDs even if a later stage fails.
                if (!$lease->write(self::OPTION_WEBHOOK_IDS, $webhook_ids)) {
                    return $this->abandon_sync();
                }
            }

            if (!$setup_error && "" !== $listing_source_id && "" !== $access_token) {
                if (!$lease->held()) {
                    return $this->abandon_sync();
                }

                $registration_result = $this->reconcile_ingestion_configuration(
                    $listing_source_id,
                    $access_token,
                    $settings["secret"],
                );
                if (is_wp_error($registration_result)) {
                    $setup_error = $registration_result;
                } elseif (!$lease->held()) {
                    // A successor may have registered another context.
                    return $this->abandon_sync();
                } elseif (Option_Lease::read(self::OPTION_SYNC_CONTEXT, "") !== $context) {
                    // Activate only the context the backend just accepted.
                    $setup_error = self::connection_changed_error();
                } else {
                    foreach (array_keys($this->get_managed_topics()) as $topic) {
                        // Deactivation or a successor may take the lease, and
                        // another request may change the context, between saves.
                        if (!$lease->held()) {
                            return $this->abandon_sync();
                        }
                        if (Option_Lease::read(self::OPTION_SYNC_CONTEXT, "") !== $context) {
                            $setup_error = self::connection_changed_error();
                            break;
                        }

                        try {
                            $webhook = $this->load_managed_webhook($topic, $webhook_ids);
                            if (!$webhook) {
                                throw new \RuntimeException("Missing managed webhook");
                            }
                        } catch (\Throwable $exception) {
                            $setup_error = new WP_Error(
                                "ahpc_webhook_load_failed",
                                __("A managed webhook could not be loaded. Retry the synchronization.", "aura-historia-partner-connect"),
                                ["retryable" => true],
                            );
                            break;
                        }

                        try {
                            $save_result = $this->save_managed_webhook(
                                $webhook,
                                $topic,
                                "active",
                                $endpoint_url,
                                $settings["secret"],
                                $user_id,
                            );
                        } catch (\Throwable $exception) {
                            $save_result = new WP_Error(
                                "ahpc_webhook_save_failed",
                                __("A managed webhook could not be saved. Retry the synchronization.", "aura-historia-partner-connect"),
                                ["retryable" => true],
                            );
                        }
                        if (is_wp_error($save_result)) {
                            $setup_error = $save_result;
                            break;
                        }
                    }
                }
            }

            update_option(self::OPTION_PLUGIN_VERSION, AHPC_VERSION, false);

            if ($setup_error) {
                // Webhooks are paused; leave sync required so a later attempt can
                // register the secret before activating delivery.
                return $this->fail_sync($lease, $setup_error);
            }

            // Report success only while still holding the lease, so a sync
            // that expired never marks a successor's context as synchronized.
            if (!$lease->write(self::OPTION_NEEDS_SYNC, "no")) {
                return $this->abandon_sync();
            }

            if (Option_Lease::read(self::OPTION_SYNC_CONTEXT, "") !== $context) {
                // The context changed while delivery was activated: pause it
                // and register the new context right away.
                return $this->fail_sync($lease, self::connection_changed_error());
            }

            // A confirmed configuration ends the automatic attempts.
            $this->retire_sync_wake($lease, self::normalize_sync_retry([], $context));

            update_option(
                self::OPTION_LAST_SYNC_AT,
                current_time("mysql"),
                false,
            );
            delete_option(self::OPTION_LAST_SYNC_ERROR);

            return true;
        } finally {
            $this->syncing = false;
            $this->attempt = null;
            $lease->release();
        }
    }

    /**
     * @return WP_Error
     */
    private static function connection_changed_error()
    {
        return new WP_Error(
            "ahpc_connection_changed",
            __(
                "The connection changed during the synchronization. Delivery stays paused until the next synchronization.",
                "aura-historia-partner-connect",
            ),
        );
    }

    /**
     * Pauses delivery and records a failed sync, unless a successor already
     * owns the webhooks and its outcome, then decides on the next attempt.
     *
     * @param Option_Lease $lease Sync lease.
     * @param WP_Error     $error Sync error.
     * @return WP_Error
     */
    private function fail_sync(Option_Lease $lease, WP_Error $error)
    {
        if (!$lease->held()) {
            return $this->abandon_sync();
        }

        $this->pause_webhooks_best_effort();
        $this->record_sync_error($error);
        $this->schedule_sync_retry($lease, $error);

        return $error;
    }

    /**
     * Ends a sync that lost its lease. A successor owns the webhooks and its
     * outcome, so this sync only keeps synchronization required.
     *
     * @return WP_Error
     */
    private function abandon_sync()
    {
        $this->mark_sync_required();

        return new WP_Error(
            "ahpc_sync_superseded",
            __(
                "Another synchronization took over before this one finished. Retry the synchronization.",
                "aura-historia-partner-connect",
            ),
        );
    }

    /**
     * Reads the settings again; another request may have reconnected the
     * store since this request cached them.
     *
     * @return array<string,mixed>
     */
    private function read_current_settings()
    {
        wp_cache_delete(self::OPTION_SETTINGS, "options");
        wp_cache_delete("notoptions", "options");

        return $this->get_settings();
    }

    /**
     * Reconciles the WooCommerce ingestion configuration with the backend.
     *
     * Also sends the current store currency and language so that the backend
     * can immediately process WooCommerce webhook payloads without requiring a
     * separate configuration step.
     *
     * @param string $listing_source_id Listing source TypeID.
     * @param string $access_token Aura Historia access token.
     * @param string $secret  Generated webhook secret.
     * @return true|WP_Error
     */
    private function reconcile_ingestion_configuration($listing_source_id, $access_token, $secret)
    {
        $client = new Backend_Api_Client(self::get_backend_base_url());
        $response = $client->put_woocommerce_listing_source_ingestion_configuration(
            $listing_source_id,
            $access_token,
            $secret,
            Store_Locale::get_currency(),
            Store_Locale::get_language(),
        );

        if (is_wp_error($response)) {
            return $this->translate_backend_configuration_error($response);
        }

        return true;
    }

    /**
     * Converts a low-level backend client error into the existing admin-facing
     * ingestion-configuration error wording. The error data only says whether
     * the unchanged PUT may succeed when retried.
     *
     * @param WP_Error $error Backend client error.
     * @return WP_Error
     */
    private function translate_backend_configuration_error(WP_Error $error)
    {
        $error_code = $error->get_error_code();
        // Backend/transport messages can include URLs or credentials. Only
        // expose the bounded HTTP status and our own fixed wording.
        $error_data = $error->get_error_data($error_code);
        $response_code =
            is_array($error_data) && isset($error_data["response_code"])
                ? (int) $error_data["response_code"]
                : 0;
        // An unexpected success status leaves the outcome uncertain; the PUT
        // is idempotent, so it is retried like a lost response.
        $retry = [
            "retryable" => "ahpc_backend_invalid_response" === $error_code ||
                (is_array($error_data) && !empty($error_data["retryable"])),
        ];

        if ("ahpc_backend_invalid_url" === $error_code) {
            return new WP_Error(
                "ahpc_invalid_configuration_url",
                __(
                    "The backend configuration URL could not be built from the configured Listing Source ID.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if ("ahpc_backend_client_unavailable" === $error_code) {
            return new WP_Error(
                "ahpc_backend_configuration_failed",
                __(
                    "The plugin installation is incomplete and cannot contact Aura Historia right now.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if ("ahpc_backend_invalid_request" === $error_code) {
            return new WP_Error(
                "ahpc_backend_configuration_failed",
                __("The backend request could not be prepared.", "aura-historia-partner-connect"),
            );
        }

        if ("ahpc_backend_request_failed" === $error_code) {
            return new WP_Error(
                "ahpc_backend_configuration_failed",
                __("The backend configuration request could not be confirmed. Try again.", "aura-historia-partner-connect"),
                $retry,
            );
        }

        if ($response_code > 0) {
            $message = sprintf(
                /* translators: %d: HTTP response code. */
                __(
                    "The backend returned HTTP %d while storing the WooCommerce ingestion configuration.",
                    "aura-historia-partner-connect",
                ),
                $response_code,
            );
        } else {
            $message = __(
                "The backend returned an invalid response while storing the WooCommerce ingestion configuration.",
                "aura-historia-partner-connect",
            );
        }

        return new WP_Error("ahpc_backend_configuration_failed", $message, $retry);
    }

    /**
     * Attempts to pause every managed webhook after an interrupted sync.
     * Keep the original sync error; a failed pause will be retried next time.
     *
     * @return void
     */
    private function pause_webhooks_best_effort()
    {
        $webhook_ids = $this->get_webhook_ids();

        foreach (array_keys($this->get_managed_topics()) as $topic) {
            try {
                $webhook = $this->load_managed_webhook($topic, $webhook_ids);
                if ($webhook && "paused" !== $webhook->get_status()) {
                    $webhook->set_status("paused");
                    if (method_exists($webhook, "set_pending_delivery")) {
                        $webhook->set_pending_delivery(false);
                    }
                    $webhook->save();
                }
            } catch (\Throwable $exception) {
                // Continue pausing the other topics; retry the failed one on sync.
            }
        }
    }

    /**
     * Pauses all managed webhooks.
     *
     * @return void
     */
    public function pause_webhooks()
    {
        $webhook_ids = $this->get_webhook_ids();

        foreach (array_keys($this->get_managed_topics()) as $topic) {
            $webhook_id = isset($webhook_ids[$topic])
                ? absint($webhook_ids[$topic])
                : absint($this->find_existing_webhook_id($topic));

            if (!$webhook_id) {
                continue;
            }

            if (class_exists("WC_Webhook")) {
                $webhook = $this->load_webhook($webhook_id);

                if (!$webhook || "paused" === $webhook->get_status()) {
                    continue;
                }

                $webhook->set_status("paused");
                $webhook->save();
                continue;
            }

            $this->pause_webhook_in_database($webhook_id);
        }
    }

    /**
     * Deletes all plugin-owned webhooks and plugin options.
     *
     * @return void
     */
    public function delete_webhooks()
    {
        global $wpdb;

        $webhook_ids = $this->get_webhook_ids();
        $seen_ids = [];

        foreach (array_keys($this->get_managed_topics()) as $topic) {
            $webhook = $this->load_managed_webhook($topic, $webhook_ids);
            $webhook_id = $webhook
                ? absint($webhook->get_id())
                : absint($this->find_existing_webhook_id($topic));

            if (!$webhook_id || isset($seen_ids[$webhook_id])) {
                continue;
            }

            $seen_ids[$webhook_id] = true;

            if ($webhook instanceof WC_Webhook) {
                $webhook->delete(true);
                continue;
            }

            if (isset($wpdb) && $this->webhook_table_exists()) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce CRUD may be unavailable during cleanup, so this fallback removes only this plugin's managed webhook row.
                $wpdb->delete(
                    $wpdb->prefix . "wc_webhooks",
                    ["webhook_id" => $webhook_id],
                    ["%d"],
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            }
        }

        delete_option(self::OPTION_SETTINGS);
        delete_option(self::OPTION_WEBHOOK_IDS);
        delete_option(self::OPTION_WEBHOOK_USER_ID);
        delete_option(self::OPTION_NEEDS_SYNC);
        delete_option(self::OPTION_PLUGIN_VERSION);
        delete_option(self::OPTION_LAST_SYNC_ERROR);
        delete_option(self::OPTION_LAST_SYNC_AT);
        delete_option(self::OPTION_LAST_OAUTH_ERROR);
        delete_option(self::OPTION_SYNC_LOCK);
        delete_option(self::OPTION_SYNC_CONTEXT);
        $this->stop_sync();
        delete_option("ahpc_initial_backfill_pending_source");
        delete_option("ahpc_initial_backfill_started_sources");
        delete_option("ahpc_initial_backfill_error");
        delete_option("ahpc_oauth_attempt");
        delete_option("ahpc_oauth_lock");
        wp_clear_scheduled_hook("ahpc_discard_expired_oauth_attempt");

        if (class_exists(Product_Backfill::class)) {
            (new Product_Backfill())->cancel_backfill();
        }

        delete_option("ahpc_backfill_state");
        delete_option("ahpc_backfill_lock");
        if (class_exists(Shipping_Publisher::class)) {
            Shipping_Publisher::uninstall();
        }
    }

    /**
     * Returns the current webhook summaries for the settings page.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_webhook_summaries()
    {
        if (!class_exists("WC_Webhook")) {
            return [];
        }

        $webhook_ids = $this->get_webhook_ids();
        $summaries = [];

        foreach ($this->get_managed_topics() as $topic => $label) {
            $webhook = $this->load_managed_webhook($topic, $webhook_ids);

            $summaries[] = [
                "topic" => $topic,
                "label" => $label,
                "name" => $this->get_webhook_name($topic),
                "id" => $webhook ? absint($webhook->get_id()) : 0,
                "status" =>
                    $webhook && method_exists($webhook, "get_i18n_status")
                        ? $webhook->get_i18n_status()
                        : __("Missing", "aura-historia-partner-connect"),
                "delivery_url" => $webhook ? $webhook->get_delivery_url() : "",
            ];
        }

        return $summaries;
    }

    /**
     * Returns the last synchronization error message.
     *
     * @return string
     */
    public function get_last_sync_error()
    {
        return (string) get_option(self::OPTION_LAST_SYNC_ERROR, "");
    }

    /**
     * Returns the raw last synchronization timestamp.
     *
     * @return string
     */
    public function get_last_sync_at()
    {
        return (string) get_option(self::OPTION_LAST_SYNC_AT, "");
    }


    /**
     * Loads an existing managed webhook or creates a new one.
     *
     * @param string            $topic       Webhook topic.
     * @param array<string,int> $webhook_ids Stored webhook IDs.
     * @return WC_Webhook
     */
    private function get_or_create_webhook($topic, $webhook_ids)
    {
        $webhook = $this->load_managed_webhook($topic, $webhook_ids);

        if ($webhook) {
            return $webhook;
        }

        return new WC_Webhook();
    }

    /**
     * Saves a managed webhook while avoiding WooCommerce's built-in ping flow.
     *
     * WooCommerce automatically sends a delivery ping when an active webhook is
     * saved with a changed delivery URL or a pending-delivery flag. The plugin
     * does not use these pings, so it first persists any delivery URL change in
     * a paused state and always clears the pending-delivery flag.
     *
     * @param WC_Webhook $webhook      Webhook instance.
     * @param string     $topic        Webhook topic.
     * @param string     $status       Desired webhook status.
     * @param string     $delivery_url Desired delivery URL.
     * @param string     $secret       Signing secret.
     * @param int        $user_id      Delivery user ID.
     * @return true|WP_Error
     */
    private function save_managed_webhook(
        $webhook,
        $topic,
        $status,
        $delivery_url,
        $secret,
        $user_id,
    ) {
        $current_delivery_url = (string) $webhook->get_delivery_url("edit");
        $requires_paused_url_update =
            $webhook->get_id() &&
            "active" === $status &&
            untrailingslashit($current_delivery_url) !==
                untrailingslashit($delivery_url);

        if ($requires_paused_url_update) {
            $this->apply_managed_webhook_configuration(
                $webhook,
                $topic,
                "paused",
                $delivery_url,
                $secret,
                $user_id,
            );

            $save_result = $this->persist_managed_webhook($webhook);

            if (is_wp_error($save_result)) {
                return $save_result;
            }
        }

        $this->apply_managed_webhook_configuration(
            $webhook,
            $topic,
            $status,
            $delivery_url,
            $secret,
            $user_id,
        );

        return $this->persist_managed_webhook($webhook);
    }

    /**
     * Applies the managed webhook configuration to a webhook instance.
     *
     * @param WC_Webhook $webhook      Webhook instance.
     * @param string     $topic        Webhook topic.
     * @param string     $status       Desired webhook status.
     * @param string     $delivery_url Desired delivery URL.
     * @param string     $secret       Signing secret.
     * @param int        $user_id      Delivery user ID.
     * @return void
     */
    private function apply_managed_webhook_configuration(
        $webhook,
        $topic,
        $status,
        $delivery_url,
        $secret,
        $user_id,
    ) {
        $webhook->set_name($this->get_webhook_name($topic));
        $webhook->set_topic($topic);
        $webhook->set_status($status);
        $webhook->set_delivery_url($delivery_url);
        $webhook->set_secret($secret);
        $webhook->set_user_id($user_id);
        $webhook->set_api_version(self::API_VERSION);

        if (method_exists($webhook, "set_pending_delivery")) {
            $webhook->set_pending_delivery(false);
        }

        // Activation restores WooCommerce's full failure allowance. A webhook
        // it disabled after repeated failures would otherwise keep its count,
        // and a single further failure would disable it and force another sync.
        if ("active" === $status) {
            $webhook->set_failure_count(0);
        }
    }

    /**
     * Persists a managed webhook and converts exceptions to WP_Error.
     *
     * @param WC_Webhook $webhook Webhook instance.
     * @return true|WP_Error
     */
    private function persist_managed_webhook($webhook)
    {
        try {
            if (!$webhook->save()) {
                throw new \RuntimeException("Managed webhook was not persisted");
            }
        } catch (\Throwable $exception) {
            return new WP_Error(
                "ahpc_webhook_save_failed",
                __("A managed webhook could not be saved. Retry the synchronization.", "aura-historia-partner-connect"),
                ["retryable" => true],
            );
        }

        return true;
    }

    /**
     * Loads a managed webhook by topic.
     *
     * @param string            $topic       Webhook topic.
     * @param array<string,int> $webhook_ids Stored webhook IDs.
     * @return WC_Webhook|null
     */
    private function load_managed_webhook($topic, $webhook_ids)
    {
        $stored_id = isset($webhook_ids[$topic])
            ? absint($webhook_ids[$topic])
            : 0;

        if ($stored_id) {
            $webhook = $this->load_webhook($stored_id);

            if ($webhook) {
                return $webhook;
            }
        }

        $recovered_id = $this->find_existing_webhook_id($topic);

        if (!$recovered_id) {
            return null;
        }

        return $this->load_webhook($recovered_id);
    }

    /**
     * Loads a webhook by ID.
     *
     * @param int $webhook_id Webhook ID.
     * @return WC_Webhook|null
     */
    private function load_webhook($webhook_id)
    {
        if (!class_exists("WC_Webhook") || !$webhook_id) {
            return null;
        }

        $webhook = new WC_Webhook($webhook_id);

        if (!$webhook->get_id()) {
            return null;
        }

        return $webhook;
    }

    /**
     * Attempts to recover an existing managed webhook by its unique name.
     *
     * @param string $topic Webhook topic.
     * @return int
     */
    private function find_existing_webhook_id($topic)
    {
        if (class_exists("WC_Data_Store")) {
            $data_store = WC_Data_Store::load("webhook");

            if ($data_store && method_exists($data_store, "search_webhooks")) {
                $matched_ids = $data_store->search_webhooks([
                    "search" => $this->get_webhook_name($topic),
                    "limit" => 10,
                    "order" => "DESC",
                    "orderby" => "id",
                    "paginate" => false,
                ]);

                if (is_array($matched_ids)) {
                    foreach ($matched_ids as $matched_id) {
                        $webhook = $this->load_webhook(absint($matched_id));

                        if (!$webhook) {
                            continue;
                        }

                        if ($this->get_webhook_name($topic) === $webhook->get_name("edit") && $topic === $webhook->get_topic("edit")) {
                            return absint($webhook->get_id());
                        }
                    }
                }
            }
        }

        global $wpdb;

        if (!isset($wpdb) || !$this->webhook_table_exists()) {
            return 0;
        }

        $table_name = $wpdb->prefix . "wc_webhooks";
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WooCommerce's data store API may be unavailable here; the fallback performs a scoped lookup against the current site's webhook table.
        $webhook_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT webhook_id FROM {$table_name} WHERE name = %s AND topic = %s ORDER BY webhook_id DESC LIMIT 1",
                $this->get_webhook_name($topic),
                $topic,
            ),
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return absint($webhook_id);
    }

    /**
     * Returns whether a sync operation is currently running.
     *
     * @return bool
     */
    public function is_syncing()
    {
        return $this->syncing;
    }

    /**
     * Returns whether the given webhook ID belongs to this plugin.
     *
     * @param int             $webhook_id Webhook ID.
     * @param WC_Webhook|null $webhook    Optional webhook instance.
     * @return bool
     */
    public function owns_webhook_id($webhook_id, $webhook = null)
    {
        $webhook_id = absint($webhook_id);

        if (!$webhook_id) {
            return false;
        }

        if (in_array($webhook_id, $this->get_webhook_ids(), true)) {
            return true;
        }

        if ($webhook instanceof WC_Webhook) {
            return $this->is_managed_webhook($webhook);
        }

        $loaded_webhook = $this->load_webhook($webhook_id);

        return $loaded_webhook
            ? $this->is_managed_webhook($loaded_webhook)
            : false;
    }

    /**
     * Returns whether a saved webhook needs the sync to repair it. WooCommerce
     * also saves a webhook after every delivery to track its failure count,
     * so only the fields the sync applies count, never failure counts,
     * pending deliveries or dates.
     *
     * @param int $webhook_id Webhook ID.
     * @return bool
     */
    public function has_managed_webhook_drift($webhook_id)
    {
        $webhook_id = absint($webhook_id);
        $webhook_ids = $this->get_webhook_ids();
        $topic = $webhook_id ? array_search($webhook_id, $webhook_ids, true) : false;

        if (false === $topic) {
            // The sync adopts an untracked managed webhook only in place of a
            // missing tracked one, and never changes a same-named copy.
            $webhook = $this->load_webhook($webhook_id);
            if (!$webhook || !$this->is_managed_webhook($webhook)) {
                return false;
            }

            $managed_webhook = $this->load_managed_webhook($webhook->get_topic("edit"), $webhook_ids);

            return $managed_webhook && $webhook_id === absint($managed_webhook->get_id());
        }

        // Only a successful last sync leaves the configuration below in place.
        if ("no" !== get_option(self::OPTION_NEEDS_SYNC, "yes")) {
            return true;
        }

        $webhook = $this->load_webhook($webhook_id);
        if (!$webhook) {
            return true;
        }

        // Apply the sync's configuration without saving it. WooCommerce only
        // records a change for a raw value that differs from the stored one.
        $settings = $this->get_settings();
        $this->apply_managed_webhook_configuration(
            $webhook,
            $topic,
            "" !== $settings["listing_source_id"] && "" !== $settings["access_token"]
                ? "active"
                : "paused",
            self::get_webhook_endpoint_url($settings["listing_source_id"]),
            $settings["secret"],
            $this->resolve_webhook_user_id(),
        );
        $managed_fields = ["name", "topic", "status", "delivery_url", "secret", "user_id", "api_version"];

        return [] !== array_intersect_key($webhook->get_changes(), array_flip($managed_fields));
    }

    /**
     * Resolves the user context WooCommerce should use for webhook payload generation.
     *
     * @return int
     */
    public function resolve_webhook_user_id()
    {
        $stored_user_id = absint(get_option(self::OPTION_WEBHOOK_USER_ID, 0));

        if (
            $stored_user_id &&
            user_can($stored_user_id, "manage_woocommerce")
        ) {
            return $stored_user_id;
        }

        $current_user_id = get_current_user_id();

        if (
            $current_user_id &&
            user_can($current_user_id, "manage_woocommerce")
        ) {
            update_option(
                self::OPTION_WEBHOOK_USER_ID,
                $current_user_id,
                false,
            );
            return $current_user_id;
        }

        $candidate_ids = get_users([
            "capability" => "manage_woocommerce",
            "fields" => "ID",
            "orderby" => "ID",
            "order" => "ASC",
        ]);

        foreach ($candidate_ids as $candidate_id) {
            $candidate_id = absint($candidate_id);

            if (
                $candidate_id &&
                user_can($candidate_id, "manage_woocommerce")
            ) {
                update_option(
                    self::OPTION_WEBHOOK_USER_ID,
                    $candidate_id,
                    false,
                );
                return $candidate_id;
            }
        }

        return 0;
    }

    /**
     * Returns whether the webhook matches this plugin's naming convention.
     *
     * @param WC_Webhook $webhook Webhook instance.
     * @return bool
     */
    private function is_managed_webhook($webhook)
    {
        foreach (array_keys($this->get_managed_topics()) as $topic) {
            if (
                $this->get_webhook_name($topic) === $webhook->get_name() &&
                $topic === $webhook->get_topic()
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pauses a webhook directly in the database when WooCommerce classes are unavailable.
     *
     * @param int $webhook_id Webhook ID.
     * @return void
     */
    private function pause_webhook_in_database($webhook_id)
    {
        global $wpdb;

        if (!isset($wpdb) || !$webhook_id || !$this->webhook_table_exists()) {
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce CRUD may be unavailable during deactivation, so this fallback only pauses the targeted managed webhook row.
        $wpdb->update(
            $wpdb->prefix . "wc_webhooks",
            [
                "status" => "paused",
                "date_modified" => current_time("mysql"),
                "date_modified_gmt" => current_time("mysql", 1),
            ],
            [
                "webhook_id" => $webhook_id,
            ],
            ["%s", "%s", "%s"],
            ["%d"],
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /**
     * Returns whether the WooCommerce webhook table exists.
     *
     * @return bool
     */
    private function webhook_table_exists()
    {
        global $wpdb;

        if (!isset($wpdb)) {
            return false;
        }

        $table_name = $wpdb->prefix . "wc_webhooks";
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- This table-existence check is only used to guard the narrow direct-database fallback paths in this class.
        $found_table = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($table_name)),
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

        return $table_name === $found_table;
    }

    /**
     * Stores a sync error and returns it.
     *
     * @param WP_Error|string $error              Error instance or message.
     * @param bool            $requires_resync    Whether another automatic sync should be attempted.
     * @return WP_Error
     */
    private function record_sync_error($error, $requires_resync = true)
    {
        if (is_wp_error($error)) {
            $message = $error->get_error_message();
        } else {
            $message = (string) $error;
            $error = new WP_Error("ahpc_sync_failed", $message);
        }

        update_option(self::OPTION_LAST_SYNC_ERROR, $message, false);
        update_option(
            self::OPTION_NEEDS_SYNC,
            $requires_resync ? "yes" : "no",
            false,
        );

        return $error;
    }
}
