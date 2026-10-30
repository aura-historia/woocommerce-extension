<?php
/**
 * Plugin bootstrap.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

use WP_Error;

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Main plugin bootstrap class.
 */
class Plugin
{
    const PAGE_SLUG = "aura-historia-partner-connect";
    const OAUTH_SCOPE = "product-listings:read product-listings:write listing-sources:read listing-sources:write";
    /** The single active connection attempt, including its completion secret. Never autoloaded. */
    const OPTION_OAUTH_ATTEMPT = "ahpc_oauth_attempt";
    /** Lease serializing attempt starts and completions. */
    const OPTION_OAUTH_LOCK = "ahpc_oauth_lock";
    /** Local attempt lifetime; the broker's expiry can only shorten it. */
    const OAUTH_ATTEMPT_TTL = 900;
    /** Longer than initiation, or than redemption plus replacing the previous connection. */
    const OAUTH_LOCK_SECONDS = 180;
    const OAUTH_LOCK_WAIT = 20;
    const OAUTH_PHASE_AWAITING_CALLBACK = "awaiting_callback";
    const OAUTH_PHASE_REDEEMING = "redeeming";
    /** The only callback fields besides the static page parameter. */
    const OAUTH_CALLBACK_PARAMETERS = ["transaction_id", "state", "completion_code"];
    /** Fields of the retired handshake. Their presence is rejected, never interpreted. */
    const OAUTH_LEGACY_PARAMETERS = ["third_party_exchange_code", "listing_source_id", "code", "error", "error_description", "access_token"];
    /** 32 random bytes as base64url without padding. */
    const OAUTH_STATE_PATTERN = '/\A[A-Za-z0-9_-]{43}\z/';
    const OPTION_INITIAL_BACKFILL_PENDING_SOURCE = "ahpc_initial_backfill_pending_source";
    const OPTION_INITIAL_BACKFILL_STARTED_SOURCES = "ahpc_initial_backfill_started_sources";
    const OPTION_INITIAL_BACKFILL_ERROR = "ahpc_initial_backfill_error";

    /**
     * Plugin singleton.
     *
     * @var Plugin|null
     */
    private static $instance = null;

    /**
     * Webhook manager instance.
     *
     * @var Webhook_Manager|null
     */
    private $manager = null;

    /**
     * Whether the plugin boot sequence has run.
     *
     * @var bool
     */
    private $booted = false;

    /**
     * Whether WooCommerce-specific bootstrap has run.
     *
     * @var bool
     */
    private $woocommerce_bootstrapped = false;

    /**
     * Returns the plugin singleton.
     *
     * @return Plugin
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Handles plugin activation.
     *
     * @return void
     */
    public static function activate()
    {
        $settings = get_option(Webhook_Manager::OPTION_SETTINGS, []);

        if (!is_array($settings)) {
            $settings = [];
        }

        $settings = wp_parse_args(
            $settings,
            Webhook_Manager::default_settings(),
        );

        if (empty($settings["secret"])) {
            $settings["secret"] = Webhook_Manager::generate_secret();
        }

        update_option(Webhook_Manager::OPTION_SETTINGS, $settings, false);
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes", false);
        // Activation resumes background reconciliation with a fresh attempt.
        (new Webhook_Manager())->reset_sync_retries();

        (new Shipping_Sync())->initialize(true);

        if (current_user_can("manage_woocommerce")) {
            update_option(
                Webhook_Manager::OPTION_WEBHOOK_USER_ID,
                get_current_user_id(),
                false,
            );
        }
    }

    /**
     * Handles plugin deactivation.
     *
     * @return void
     */
    public static function deactivate()
    {
        $manager = new Webhook_Manager();
        // Fence a running sync first, so it cannot activate delivery again
        // after the pause. The retained backfill batch waits for a manual
        // resume.
        $manager->stop_sync();
        $manager->pause_webhooks();
        (new Product_Backfill())->stop_backfill(__(
            "The plugin was deactivated.",
            "aura-historia-partner-connect",
        ));
        Shipping_Sync::stop();
        (new Shipping_Publisher())->withdraw();
        // A pending connection attempt holds a completion secret.
        delete_option(self::OPTION_OAUTH_ATTEMPT);
    }

    /**
     * Boots the plugin.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        add_filter("plugin_action_links_" . AHPC_PLUGIN_BASENAME, [
            $this,
            "add_plugin_action_links",
        ]);
        add_filter(
            "http_request_args",
            [Webhook_Manager::class, "authorize_delivery"],
            11,
            2,
        );
        // WooCommerce resolves topic hooks when it loads active webhooks on
        // init, so this must be registered before then.
        add_filter(
            "woocommerce_webhook_topic_hooks",
            [Webhook_Manager::class, "add_permanent_deletion_hook"],
            10,
            2,
        );
        add_action(
            "deleted_post",
            [Webhook_Manager::class, "announce_permanent_deletion"],
            10,
            2,
        );
        add_action(
            "update_option_" . Webhook_Manager::OPTION_SETTINGS,
            [Webhook_Manager::class, "connection_settings_changed"],
            10,
            2,
        );
        add_action("woocommerce_loaded", [$this, "bootstrap_woocommerce"]);
        add_action("update_option_woocommerce_currency", [$this, "on_store_locale_option_updated"], 10, 2);
        add_action("update_option_WPLANG", [$this, "on_store_locale_option_updated"], 10, 2);
        add_action("added_option", [$this, "on_store_locale_option_added"], 10, 2);

        if (is_admin()) {
            add_action("admin_menu", [$this, "register_admin_page"]);
            add_action("admin_init", [$this, "register_settings"]);
            add_action("admin_init", [$this, "maybe_handle_oauth_callback"]);
            add_filter(
                "option_page_capability_" . Webhook_Manager::SETTINGS_GROUP,
                [$this, "filter_settings_page_capability"],
            );
            add_action("admin_post_ahpc_sync_webhooks", [
                $this,
                "handle_sync_request",
            ]);
            add_action("admin_post_ahpc_start_oauth", [
                $this,
                "handle_oauth_start_request",
            ]);
            add_action("admin_post_ahpc_queue_backfill", [
                $this,
                "handle_backfill_request",
            ]);
            add_action("admin_post_ahpc_import_shipping", [$this, "handle_shipping_request"]);
            add_action("admin_notices", [
                $this,
                "maybe_show_dependency_notice",
            ]);
        }

        if (did_action("woocommerce_loaded")) {
            $this->bootstrap_woocommerce();
        }
    }

    /**
     * Boots WooCommerce-specific behavior.
     *
     * @return void
     */
    public function bootstrap_woocommerce()
    {
        // Wait for WordPress locale setup. Ordinary requests only do local
        // work here: the remote reconciliation runs in Action Scheduler.
        if (!did_action("init")) {
            add_action("init", [$this, "bootstrap_woocommerce"], -1);
            return;
        }

        if ($this->woocommerce_bootstrapped) {
            return;
        }

        $this->woocommerce_bootstrapped = true;
        $this->manager = new Webhook_Manager();

        $this->manager->initialize_options();

        $shipping = new Shipping_Publisher();
        $shipping_sync = new Shipping_Sync();
        $shipping_sync->register();
        if (did_action("action_scheduler_init")) {
            $shipping_sync->initialize();
        }
        add_action(Shipping_Publisher::ACTION_HOOK, [$shipping, "process"], 10, 2);
        add_action("action_scheduler_init", [$shipping, "maybe_queue_initial"], 20);

        if (defined("AHPC_FORCE_SYNC_DELIVERY") && AHPC_FORCE_SYNC_DELIVERY) {
            add_filter("woocommerce_webhook_deliver_async", "__return_false");
        }

        if (class_exists(Product_Backfill::class)) {
            add_action(
                Product_Backfill::ACTION_HOOK,
                static function ($listing_source_id, $page, $wake = "") {
                    (new Product_Backfill())->process_batch($listing_source_id, $page, $wake);
                },
                10,
                3,
            );
        }

        add_action(Webhook_Manager::SYNC_ACTION_HOOK, [$this, "reconcile_webhooks"], 10, 1);

        add_action(
            "woocommerce_webhook_updated",
            [$this, "maybe_mark_edited_managed_webhook_out_of_sync"],
            10,
            1,
        );
        add_action(
            "woocommerce_webhook_deleted",
            [$this, "maybe_mark_managed_webhook_out_of_sync"],
            10,
            2,
        );

        if (
            AHPC_VERSION !==
            get_option(Webhook_Manager::OPTION_PLUGIN_VERSION, "")
        ) {
            // An update resumes stopped reconciliation once; the required
            // sync itself is recorded durably.
            $this->manager->mark_sync_required();
            $this->manager->reset_sync_retries();
            update_option(Webhook_Manager::OPTION_PLUGIN_VERSION, AHPC_VERSION, false);
        }

        if ("no" === get_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes")) {
            $this->maybe_schedule_pending_initial_backfill();
            if (did_action("action_scheduler_init")) {
                $shipping->maybe_queue_initial();
            }
        } elseif (did_action("action_scheduler_init")) {
            $this->manager->maybe_queue_sync();
        } else {
            add_action("action_scheduler_init", [$this->manager, "maybe_queue_sync"], 20);
        }
    }

    /**
     * Runs the background webhook reconciliation. Once it confirms the
     * configuration, a still pending initial backfill and shipping import
     * are queued without waiting for another request.
     *
     * @param string $wake Wake-up token in the action args.
     * @return void
     */
    public function reconcile_webhooks($wake = "")
    {
        if (
            $this->manager instanceof Webhook_Manager &&
            true === $this->manager->reconcile($wake)
        ) {
            $this->maybe_schedule_pending_initial_backfill();
            (new Shipping_Publisher())->maybe_queue_initial();
        }
    }

    /**
     * Pause deliveries immediately when the provider's currency or language changes.
     * The next normal sync will register the new context before resuming delivery.
     *
     * @param mixed $old_value Previous option value.
     * @param mixed $new_value New option value.
     * @return void
     */
    public function on_store_locale_option_updated($old_value, $new_value)
    {
        if ($old_value === $new_value || !$this->is_woocommerce_available()) {
            return;
        }

        $manager = $this->manager instanceof Webhook_Manager
            ? $this->manager
            : new Webhook_Manager();
        $manager->mark_context_changed();
        $manager->pause_webhooks();
    }

    /**
     * Newly created locale options also change the context sent to Aura.
     *
     * @param string $option Option name.
     * @param mixed  $value  New option value.
     * @return void
     */
    public function on_store_locale_option_added($option, $value)
    {
        if ("WPLANG" === $option || "woocommerce_currency" === $option) {
            $this->on_store_locale_option_updated(null, $value);
        }
    }

    /**
     * Queue a first CREATE run only after the current provider configuration is healthy.
     * A failed queue attempt retains the pending source for a later bootstrap.
     *
     * @return true|WP_Error
     */
    public function maybe_schedule_pending_initial_backfill()
    {
        $pending = get_option(self::OPTION_INITIAL_BACKFILL_PENDING_SOURCE, "");
        if ("" === $pending) {
            return true;
        }

        $settings = $this->get_current_settings();
        if (
            $pending !== $settings["listing_source_id"] ||
            !Webhook_Manager::is_valid_listing_source_id($pending) ||
            !Webhook_Manager::is_valid_access_token($settings["access_token"]) ||
            "no" !== get_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes") ||
            !class_exists(Product_Backfill::class)
        ) {
            return true;
        }

        $started = get_option(self::OPTION_INITIAL_BACKFILL_STARTED_SOURCES, []);
        $started = is_array($started) ? $started : [];
        if (isset($started[$pending])) {
            delete_option(self::OPTION_INITIAL_BACKFILL_PENDING_SOURCE);
            delete_option(self::OPTION_INITIAL_BACKFILL_ERROR);
            return true;
        }

        // Do not claim that the run was scheduled until Action Scheduler has
        // actually accepted it; deferring the attempt also preserves failures.
        if (!did_action("action_scheduler_init")) {
            add_action("action_scheduler_init", [$this, "maybe_schedule_pending_initial_backfill"]);
            return true;
        }

        if (!(new Product_Backfill())->start_or_resume_backfill($pending)) {
            $message = __("The initial product backfill could not be queued. It will be retried on a later request.", "aura-historia-partner-connect");
            update_option(self::OPTION_INITIAL_BACKFILL_ERROR, $message, false);
            return new WP_Error("ahpc_backfill_failed", $message);
        }

        $started[$pending] = true;
        update_option(self::OPTION_INITIAL_BACKFILL_STARTED_SOURCES, $started, false);
        delete_option(self::OPTION_INITIAL_BACKFILL_PENDING_SOURCE);
        delete_option(self::OPTION_INITIAL_BACKFILL_ERROR);
        return true;
    }

    /**
     * Registers the plugin settings page.
     *
     * @return void
     */
    public function register_admin_page()
    {
        add_submenu_page(
            "woocommerce",
            esc_html__(
                "Aura Historia Partner Connect",
                "aura-historia-partner-connect",
            ),
            esc_html__("Aura Historia", "aura-historia-partner-connect"),
            "manage_woocommerce",
            self::PAGE_SLUG,
            [$this, "render_settings_page"],
        );
    }

    /**
     * Registers the plugin settings.
     *
     * @return void
     */
    public function register_settings()
    {
        register_setting(
            Webhook_Manager::SETTINGS_GROUP,
            Webhook_Manager::OPTION_SETTINGS,
            [
                "type" => "array",
                "sanitize_callback" => [$this, "sanitize_settings"],
                "default" => Webhook_Manager::default_settings(),
                "show_in_rest" => false,
            ],
        );
    }

    /**
     * Aligns the settings form capability with the WooCommerce submenu capability.
     *
     * @param string $capability Current capability.
     * @return string
     */
    public function filter_settings_page_capability($capability)
    {
        unset($capability);

        return "manage_woocommerce";
    }

    /**
     * Sanitizes the plugin settings.
     *
     * @param mixed $input Settings input.
     * @return array<string,mixed>
     */
    public function sanitize_settings($input)
    {
        $current = get_option(Webhook_Manager::OPTION_SETTINGS, []);

        if (!is_array($current)) {
            $current = [];
        }

        $current = wp_parse_args($current, Webhook_Manager::default_settings());

        $sanitized = [
            "listing_source_id" => Webhook_Manager::normalize_listing_source_id(
                $current["listing_source_id"],
            ),
            "access_token" => Webhook_Manager::normalize_access_token(
                $current["access_token"],
            ),
            "secret" => !empty($current["secret"])
                ? sanitize_text_field((string) $current["secret"])
                : Webhook_Manager::generate_secret(),
        ];

        if (is_array($input)) {
            if (array_key_exists("listing_source_id", $input)) {
                $listing_source_id = Webhook_Manager::normalize_listing_source_id(
                    wp_unslash($input["listing_source_id"]),
                );

                if (
                    "" === $listing_source_id ||
                    Webhook_Manager::is_valid_listing_source_id($listing_source_id)
                ) {
                    $sanitized["listing_source_id"] = $listing_source_id;
                } else {
                    add_settings_error(
                        Webhook_Manager::OPTION_SETTINGS,
                        "ahpc_invalid_listing_source_id",
                        __(
                            "The Listing Source ID doesn't look right. Reconnect this store and try once more.",
                            "aura-historia-partner-connect",
                        ),
                    );
                }
            }

            if (array_key_exists("access_token", $input)) {
                $access_token = Webhook_Manager::normalize_access_token(
                    wp_unslash($input["access_token"]),
                );

                if ("" === $access_token) {
                    $sanitized["access_token"] = "";
                } elseif (Webhook_Manager::is_valid_access_token($access_token)) {
                    $sanitized["access_token"] = $access_token;
                } else {
                    add_settings_error(
                        Webhook_Manager::OPTION_SETTINGS,
                        "ahpc_invalid_access_token",
                        __(
                            "The stored Aura Historia access token doesn't look right. Reconnect this store and try once more.",
                            "aura-historia-partner-connect",
                        ),
                    );
                }
            }
        }

        if ("" === $sanitized["secret"]) {
            $sanitized["secret"] = Webhook_Manager::generate_secret();
        }

        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes", false);

        return $sanitized;
    }


    /**
     * Handles the manual sync action.
     *
     * @return void
     */
    public function handle_sync_request()
    {
        if (!current_user_can("manage_woocommerce")) {
            wp_die(
                esc_html__(
                    "You are not allowed to sync these webhooks.",
                    "aura-historia-partner-connect",
                ),
                403,
            );
        }

        check_admin_referer("ahpc_sync_webhooks");

        $redirect_url = $this->get_settings_page_url();

        if (!$this->is_woocommerce_available()) {
            wp_safe_redirect(add_query_arg("ahpc_synced", "0", $redirect_url));
            exit();
        }

        $this->bootstrap_woocommerce();

        $result =
            $this->manager instanceof Webhook_Manager
                ? $this->manager->sync_webhooks(Webhook_Manager::SYNC_WAIT_SECONDS)
                : new WP_Error(
                    "ahpc_missing_manager",
                    __(
                        "The webhook manager could not be initialized.",
                        "aura-historia-partner-connect",
                    ),
                );

        if (!is_wp_error($result)) {
            $redirect_url = add_query_arg("ahpc_synced", "1", $redirect_url);
        } else {
            $redirect_url = add_query_arg("ahpc_synced", "0", $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit();
    }

    /**
     * Handles the OAuth connect action.
     *
     * @return void
     */
    public function handle_oauth_start_request()
    {
        if (!current_user_can("manage_woocommerce")) {
            wp_die(
                esc_html__(
                    "You are not allowed to connect this store to Aura Historia.",
                    "aura-historia-partner-connect",
                ),
                403,
            );
        }

        check_admin_referer("ahpc_start_oauth");

        $authorization_url = $this->start_oauth_connection();

        if (is_wp_error($authorization_url)) {
            $this->store_oauth_error($authorization_url->get_error_message());

            wp_safe_redirect(
                add_query_arg("ahpc_oauth", "failed", $this->get_settings_page_url()),
            );
            exit();
        }

        // The authorization URL was checked against the configured broker
        // origin. Only that configured host becomes a redirect target.
        $broker_host = wp_parse_url($this->get_oauth_broker_base_url(), PHP_URL_HOST);

        add_filter(
            "allowed_redirect_hosts",
            static function ($hosts) use ($broker_host) {
                $hosts[] = strtolower((string) $broker_host);

                return array_values(
                    array_unique(
                        array_filter(
                            array_map("strtolower", (array) $hosts),
                        ),
                    ),
                );
            },
        );

        nocache_headers();
        wp_safe_redirect(
            $authorization_url,
            302,
            "Aura Historia Partner Connect",
        );
        exit();
    }

    /**
     * Handles the merchant callback when the Aura Historia broker returns the
     * browser to wp-admin. The callback carries only the transaction ID, the
     * original client state and a one-time completion code; anything else,
     * including fields of the retired handshake, is rejected unread.
     *
     * @return void
     */
    public function maybe_handle_oauth_callback()
    {
        if (!$this->is_settings_page_request()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parameter names are compared with fixed lists; the attempt's state is verified below.
        $present = array_intersect(array_keys($_GET), array_merge(self::OAUTH_CALLBACK_PARAMETERS, self::OAUTH_LEGACY_PARAMETERS));

        if (!$present) {
            return;
        }

        if (!current_user_can("manage_woocommerce")) {
            wp_die(
                esc_html__(
                    "You are not allowed to connect this store to Aura Historia.",
                    "aura-historia-partner-connect",
                ),
                403,
            );
        }

        $query = OAuth_Broker_Client::parse_unique_query($this->get_raw_query_string());
        $expected = array_merge(["page"], self::OAUTH_CALLBACK_PARAMETERS);

        if (array_intersect($present, self::OAUTH_LEGACY_PARAMETERS)) {
            $result = new WP_Error(
                "ahpc_oauth_legacy_callback",
                __(
                    "Aura Historia returned a connection result in a format this plugin version no longer accepts, so nothing was changed. Make sure the plugin is up to date, then start the connection again from this page.",
                    "aura-historia-partner-connect",
                ),
            );
        } elseif (
            null === $query ||
            count($query) !== count($expected) ||
            array_diff($expected, array_keys($query))
        ) {
            $result = new WP_Error(
                "ahpc_oauth_invalid_callback",
                __(
                    "The Aura Historia connection callback was incomplete or contained unexpected fields, so nothing was changed. Start the connection again from this page.",
                    "aura-historia-partner-connect",
                ),
            );
        } else {
            $result = $this->complete_oauth_connection(
                $query["transaction_id"],
                $query["state"],
                $query["completion_code"],
            );
        }

        $status = "connected";

        if (is_wp_error($result)) {
            $data = $result->get_error_data();

            if (is_array($data) && !empty($data["connection_saved"])) {
                // The credentials were stored; only the follow-up setup failed.
                // Its status is shown separately and retried in the background.
                $status = "saved";
            } else {
                $this->store_oauth_error($result->get_error_message());
                $status = "failed";
            }
        }

        if ("failed" !== $status) {
            delete_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR);
        }

        // Drop the transaction, state and completion code from the address bar.
        nocache_headers();
        wp_safe_redirect(
            add_query_arg("ahpc_oauth", $status, $this->get_settings_page_url()),
        );
        exit();
    }

    /**
     * Starts a connection: registers a broker transaction server-to-server and
     * stores the single active attempt. Starting again replaces any earlier
     * attempt, so only the newest one can complete.
     *
     * The completion secret never leaves this server except in the completion
     * request body; the browser only receives the broker's authorization URL.
     *
     * @return string|WP_Error Authorization URL or error.
     */
    public function start_oauth_connection()
    {
        if (!current_user_can("manage_woocommerce")) {
            return $this->oauth_forbidden_error();
        }

        $context = $this->get_oauth_context();

        if (is_wp_error($context)) {
            return $context;
        }

        $client_state = $this->generate_oauth_random_value();
        $completion_secret = $this->generate_oauth_random_value();

        if (is_wp_error($client_state)) {
            return $client_state;
        }

        if (is_wp_error($completion_secret)) {
            return $completion_secret;
        }

        $lease = new Option_Lease(self::OPTION_OAUTH_LOCK);

        if (!$lease->acquire(self::OAUTH_LOCK_SECONDS, self::OAUTH_LOCK_WAIT)) {
            return $this->oauth_busy_error();
        }

        try {
            // A restart retires the previous attempt, whether or not this one
            // can be registered.
            if (!$lease->delete(self::OPTION_OAUTH_ATTEMPT)) {
                return $this->oauth_storage_error();
            }

            $broker = new OAuth_Broker_Client($context["broker_base_url"]);
            $transaction = $broker->initiate([
                "client_id" => $context["client_id"],
                "merchant_redirect_uri" => $context["merchant_redirect_uri"],
                "client_state" => $client_state,
                "scope" => $context["scope"],
                "completion_challenge" => $this->base64url_encode(
                    hash("sha256", $completion_secret, true),
                ),
            ]);

            if (is_wp_error($transaction)) {
                return $transaction;
            }

            if (false !== strpos($transaction["authorization_url"], $completion_secret)) {
                return new WP_Error(
                    "ahpc_oauth_broker_invalid_response",
                    __(
                        "Aura Historia returned an unexpected connection response, so no settings were changed. Start the connection again from this page.",
                        "aura-historia-partner-connect",
                    ),
                );
            }

            $now = time();
            $attempt = $context + [
                "protocol_version" => OAuth_Broker_Client::PROTOCOL_VERSION,
                "user_id" => get_current_user_id(),
                "transaction_id" => $transaction["transaction_id"],
                "client_state_hash" => hash("sha256", $client_state),
                "completion_secret" => $completion_secret,
                "created_at" => $now,
                "expires_at" => min($now + self::OAUTH_ATTEMPT_TTL, $transaction["expires_at"]),
                "phase" => self::OAUTH_PHASE_AWAITING_CALLBACK,
            ];

            if (!$lease->write(self::OPTION_OAUTH_ATTEMPT, $attempt)) {
                return $this->oauth_storage_error();
            }

            return $transaction["authorization_url"];
        } finally {
            $lease->release();
        }
    }

    /**
     * Completes the stored attempt with the callback's completion code and the
     * locally held completion secret, then stores the broker's token and
     * ListingSource as one pair.
     *
     * The attempt is checked against the current administrator, transaction,
     * state, configuration, expiry and phase before anything is consumed. A
     * claimed attempt is redeemed at most once: whatever the broker answers,
     * the attempt and its secret are then discarded, and a retry needs a new
     * start. Unsuccessful results leave the existing connection unchanged.
     *
     * @param string $transaction_id  Transaction ID from the callback.
     * @param string $client_state    State from the callback.
     * @param string $completion_code Completion code from the callback.
     * @return true|WP_Error An error with connection_saved data when only the
     *                       follow-up setup failed.
     */
    public function complete_oauth_connection(
        $transaction_id,
        $client_state,
        $completion_code,
    ) {
        if (!current_user_can("manage_woocommerce")) {
            return $this->oauth_forbidden_error();
        }

        if (
            !is_string($transaction_id) ||
            !is_string($client_state) ||
            !is_string($completion_code) ||
            1 !== preg_match(OAuth_Broker_Client::TRANSACTION_ID_PATTERN, $transaction_id) ||
            1 !== preg_match(self::OAUTH_STATE_PATTERN, $client_state) ||
            1 !== preg_match(OAuth_Broker_Client::COMPLETION_CODE_PATTERN, $completion_code)
        ) {
            return new WP_Error(
                "ahpc_oauth_invalid_state",
                __(
                    "The Aura Historia connection callback did not include a valid security state. Start the connection again from this settings page.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $checked = $this->check_oauth_attempt(
            Option_Lease::read(self::OPTION_OAUTH_ATTEMPT, null),
            $transaction_id,
            $client_state,
        );

        if (is_wp_error($checked)) {
            if ("ahpc_oauth_expired_state" === $checked->get_error_code()) {
                $this->discard_expired_oauth_attempt();
            }

            return $checked;
        }

        $lease = new Option_Lease(self::OPTION_OAUTH_LOCK);

        if (!$lease->acquire(self::OAUTH_LOCK_SECONDS, self::OAUTH_LOCK_WAIT)) {
            return $this->oauth_busy_error();
        }

        try {
            // Another callback may have claimed or replaced the attempt while
            // this one waited for the lease.
            $attempt = $this->check_oauth_attempt(
                Option_Lease::read(self::OPTION_OAUTH_ATTEMPT, null),
                $transaction_id,
                $client_state,
            );

            if (is_wp_error($attempt)) {
                return $attempt;
            }

            $claimed = $attempt;
            $claimed["phase"] = self::OAUTH_PHASE_REDEEMING;

            if (!$lease->write(self::OPTION_OAUTH_ATTEMPT, $claimed)) {
                return $this->oauth_busy_error();
            }

            $result = (new OAuth_Broker_Client($attempt["broker_base_url"]))->complete(
                $attempt["transaction_id"],
                $completion_code,
                $attempt["completion_secret"],
            );

            // One redemption per attempt, whatever the outcome. An ambiguous
            // failure is never retried with the same code.
            $lease->delete(self::OPTION_OAUTH_ATTEMPT);
            unset($attempt["completion_secret"], $claimed);

            if (is_wp_error($result)) {
                return $result;
            }

            $connection = $this->validate_oauth_result($result, $attempt, $client_state);

            if (is_wp_error($connection)) {
                return $connection;
            }

            $saved = $this->save_oauth_connection(
                $lease,
                $connection["listing_source_id"],
                $connection["access_token"],
            );

            if (is_wp_error($saved)) {
                return $saved;
            }
        } finally {
            $lease->release();
        }

        return $this->finish_oauth_connection($connection["listing_source_id"]);
    }

    /**
     * Checks a stored attempt against the current request and configuration
     * without changing it.
     *
     * @param mixed  $attempt        Stored attempt.
     * @param string $transaction_id Transaction ID from the callback.
     * @param string $client_state   State from the callback.
     * @return array<string,mixed>|WP_Error
     */
    private function check_oauth_attempt($attempt, $transaction_id, $client_state)
    {
        $keys = ["protocol_version", "user_id", "transaction_id", "client_state_hash", "completion_secret", "client_id", "broker_base_url", "merchant_redirect_uri", "scope", "expires_at", "phase"];

        if (!is_array($attempt) || array_diff($keys, array_keys($attempt))) {
            return new WP_Error(
                "ahpc_oauth_expired_state",
                __(
                    "The Aura Historia connection callback has expired. Start the connection again from this settings page.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (absint($attempt["user_id"]) !== get_current_user_id()) {
            return new WP_Error(
                "ahpc_oauth_user_mismatch",
                __(
                    "The Aura Historia connection was started by a different administrator. Start the connection again from this account.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (
            !is_string($attempt["transaction_id"]) ||
            !is_string($attempt["client_state_hash"]) ||
            !hash_equals($attempt["transaction_id"], $transaction_id)
        ) {
            return new WP_Error(
                "ahpc_oauth_transaction_mismatch",
                __(
                    "This Aura Historia connection result does not belong to the latest connection attempt started here. If you started the connection more than once, finish the newest attempt or start again from this page.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (!hash_equals($attempt["client_state_hash"], hash("sha256", $client_state))) {
            return new WP_Error(
                "ahpc_oauth_invalid_state",
                __(
                    "The Aura Historia connection callback did not include a valid security state. Start the connection again from this settings page.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $context = $this->get_oauth_context();

        if (
            OAuth_Broker_Client::PROTOCOL_VERSION !== $attempt["protocol_version"] ||
            is_wp_error($context) ||
            $context["client_id"] !== $attempt["client_id"] ||
            $context["broker_base_url"] !== $attempt["broker_base_url"] ||
            $context["merchant_redirect_uri"] !== $attempt["merchant_redirect_uri"] ||
            $context["scope"] !== $attempt["scope"]
        ) {
            return new WP_Error(
                "ahpc_oauth_context_changed",
                __(
                    "The Aura Historia connection settings or this site's admin address changed after the connection started. Start the connection again from this page.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if ((int) $attempt["expires_at"] <= time()) {
            return new WP_Error(
                "ahpc_oauth_expired_state",
                __(
                    "The Aura Historia connection callback has expired. Start the connection again from this settings page.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (self::OAUTH_PHASE_AWAITING_CALLBACK !== $attempt["phase"]) {
            return new WP_Error(
                "ahpc_oauth_attempt_used",
                __(
                    "This Aura Historia connection result is already being processed or was already used. Start the connection again from this page if the store is not connected.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        return $attempt;
    }

    /**
     * Checks a broker result against the redeemed attempt. The token and the
     * ListingSource come only from this one authenticated response.
     *
     * @param array<string,string> $result       Broker completion result.
     * @param array<string,mixed>  $attempt      Redeemed attempt.
     * @param string               $client_state State from the callback.
     * @return array{listing_source_id:string,access_token:string}|WP_Error
     */
    private function validate_oauth_result(array $result, array $attempt, $client_state)
    {
        if (
            !hash_equals($attempt["transaction_id"], $result["transaction_id"]) ||
            !hash_equals($client_state, $result["client_state"])
        ) {
            return new WP_Error(
                "ahpc_oauth_result_mismatch",
                __(
                    "Aura Historia returned a connection result for a different attempt, so no settings were changed. Start the connection again from this page.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if ("denied" === $result["outcome"]) {
            return new WP_Error(
                "ahpc_oauth_authorization_denied",
                __(
                    "The Aura Historia connection was not approved, so nothing was changed.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if ("approved" !== $result["outcome"]) {
            return new WP_Error(
                "ahpc_oauth_authorization_failed",
                "" !== $result["error"]
                    ? sprintf(
                        /* translators: %s: broker error identifier. */
                        __(
                            "Aura Historia could not complete the connection (%s), so nothing was changed. Start the connection again from this page.",
                            "aura-historia-partner-connect",
                        ),
                        $result["error"],
                    )
                    : __(
                        "Aura Historia could not complete the connection, so nothing was changed. Start the connection again from this page.",
                        "aura-historia-partner-connect",
                    ),
            );
        }

        if (!Webhook_Manager::is_valid_listing_source_id($result["listing_source_id"])) {
            return new WP_Error(
                "ahpc_oauth_invalid_listing_source_id",
                __(
                    "Aura Historia did not return a valid Listing Source ID.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (0 !== strcasecmp("Bearer", $result["token_type"])) {
            return new WP_Error(
                "ahpc_oauth_invalid_token_type",
                __(
                    "Aura Historia returned an unsupported OAuth token type for this store.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (!Webhook_Manager::is_valid_access_token($result["access_token"])) {
            return new WP_Error(
                "ahpc_oauth_invalid_access_token",
                __(
                    "Aura Historia returned an invalid access token for this store.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (!$this->is_requested_oauth_scope($result["scope"], $attempt["scope"])) {
            return new WP_Error(
                "ahpc_oauth_missing_scope",
                __(
                    "Aura Historia did not grant the permissions required to manage the listing source, sync product listings and publish shipping.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        return [
            "listing_source_id" => $result["listing_source_id"],
            "access_token" => $result["access_token"],
        ];
    }

    /**
     * Replaces the stored connection while the attempt lease is held. The
     * settings write is fenced by the lease, so a completion that outlived its
     * lease cannot overwrite a newer connection.
     *
     * @param Option_Lease $lease             Held attempt lease.
     * @param string       $listing_source_id Validated ListingSource ID.
     * @param string       $access_token      Validated access token.
     * @return true|WP_Error
     */
    private function save_oauth_connection(Option_Lease $lease, $listing_source_id, $access_token)
    {
        $not_saved = new WP_Error(
            "ahpc_oauth_not_saved",
            __(
                "Aura Historia approved the connection, but this site could not save it, so the previous connection is unchanged. Start the connection again from this page.",
                "aura-historia-partner-connect",
            ),
        );

        if (!$lease->held()) {
            return $not_saved;
        }

        $previous = Option_Lease::read(Webhook_Manager::OPTION_SETTINGS, []);
        $settings = $this->get_current_settings();
        Shipping_Sync::stop();
        if (Webhook_Manager::is_valid_listing_source_id($settings["listing_source_id"])) {
            (new Shipping_Publisher())->withdraw();
        }
        if ($settings["listing_source_id"] !== $listing_source_id) {
            if (class_exists(Product_Backfill::class)) {
                (new Product_Backfill())->cancel_backfill();
            }
            delete_option(self::OPTION_INITIAL_BACKFILL_PENDING_SOURCE);
        }
        $started = get_option(self::OPTION_INITIAL_BACKFILL_STARTED_SOURCES, []);
        if (!is_array($started) || !isset($started[$listing_source_id])) {
            update_option(self::OPTION_INITIAL_BACKFILL_PENDING_SOURCE, $listing_source_id, false);
        }

        $settings["listing_source_id"] = $listing_source_id;
        $settings["access_token"] = $access_token;

        if (empty($settings["secret"])) {
            $settings["secret"] = Webhook_Manager::generate_secret();
        }

        if (!$lease->write(Webhook_Manager::OPTION_SETTINGS, $settings)) {
            (new Shipping_Sync())->initialize(true);
            return $not_saved;
        }

        // The fenced write bypasses update_option(), so apply its connection
        // change handling explicitly.
        Webhook_Manager::connection_settings_changed($previous, $settings);
        (new Shipping_Sync())->initialize(true);
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes", false);
        delete_option(Webhook_Manager::OPTION_LAST_SYNC_ERROR);
        delete_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR);
        delete_option(self::OPTION_INITIAL_BACKFILL_ERROR);

        return true;
    }

    /**
     * Confirms the configuration for a newly saved connection while the
     * merchant waits. Failures here never touch the saved credentials, and
     * the background reconciliation retries them.
     *
     * @param string $listing_source_id Saved ListingSource ID.
     * @return true|WP_Error
     */
    private function finish_oauth_connection($listing_source_id)
    {
        if ($this->is_woocommerce_available()) {
            $this->bootstrap_woocommerce();

            // The merchant waits for this confirmation; ordinary requests
            // leave it to the background reconciliation.
            if ($this->manager instanceof Webhook_Manager) {
                $sync_result = $this->manager->sync_webhooks(Webhook_Manager::SYNC_WAIT_SECONDS);

                if (is_wp_error($sync_result)) {
                    return $this->connection_saved_error($sync_result);
                }
            }

            $backfill_result = $this->maybe_schedule_pending_initial_backfill();
            if (is_wp_error($backfill_result)) {
                return $this->connection_saved_error($backfill_result);
            }
        }

        (new Shipping_Publisher())->queue($listing_source_id);
        return true;
    }

    /**
     * Marks a follow-up error as happening after the connection was saved.
     *
     * @param WP_Error $error Follow-up error.
     * @return WP_Error
     */
    private function connection_saved_error(WP_Error $error)
    {
        $data = $error->get_error_data();

        return new WP_Error(
            $error->get_error_code(),
            $error->get_error_message(),
            array_merge(is_array($data) ? $data : [], ["connection_saved" => true]),
        );
    }

    /**
     * Handles the manual backfill action.
     *
     * @return void
     */
    public function handle_backfill_request()
    {
        if (!current_user_can("manage_woocommerce")) {
            wp_die(
                esc_html__(
                    "You are not allowed to queue a product backfill.",
                    "aura-historia-partner-connect",
                ),
                403,
            );
        }

        check_admin_referer("ahpc_queue_backfill");

        $redirect_url = $this->get_settings_page_url();
        $result = $this->queue_manual_backfill();

        if (!is_wp_error($result)) {
            $redirect_url = add_query_arg(
                "ahpc_backfill",
                $result,
                $redirect_url,
            );
        } else {
            $status = "failed";

            switch ($result->get_error_code()) {
                case "ahpc_backfill_unavailable":
                    $status = "unavailable";
                    break;
                case "ahpc_backfill_invalid_settings":
                    $status = "invalid";
                    break;
            }

            $redirect_url = add_query_arg(
                "ahpc_backfill",
                $status,
                $redirect_url,
            );
        }

        wp_safe_redirect($redirect_url);
        exit();
    }

    /**
     * Resumes pending work, or queues a fresh full run only when idle.
     *
     * @return string|WP_Error A Product_Backfill::START_* outcome.
     */
    public function queue_manual_backfill()
    {
        if (!$this->is_woocommerce_available()) {
            return new WP_Error(
                "ahpc_backfill_unavailable",
                __(
                    "WooCommerce is not active, so the product backfill cannot be queued yet.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (!class_exists(Product_Backfill::class)) {
            return new WP_Error(
                "ahpc_backfill_unavailable",
                __(
                    "The product backfill component is not available right now.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $this->bootstrap_woocommerce();

        $settings = $this->get_current_settings();

        if (
            !Webhook_Manager::is_valid_listing_source_id($settings["listing_source_id"]) ||
            !Webhook_Manager::is_valid_access_token($settings["access_token"])
        ) {
            return new WP_Error(
                "ahpc_backfill_invalid_settings",
                __(
                    "Connect this store to Aura Historia before queueing a full product backfill.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (!$this->manager instanceof Webhook_Manager) {
            return new WP_Error(
                "ahpc_backfill_unavailable",
                __(
                    "The webhook manager could not be initialized.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $sync_result = $this->manager->sync_webhooks(Webhook_Manager::SYNC_WAIT_SECONDS);

        if (is_wp_error($sync_result)) {
            return $sync_result;
        }

        $outcome = (new Product_Backfill())->start_or_resume_backfill($settings["listing_source_id"]);
        if (!$outcome) {
            return new WP_Error(
                "ahpc_backfill_failed",
                __(
                    "The product backfill could not be queued. Check the backfill status below and the WooCommerce Action Scheduler screen for more detail.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        // A manual start also satisfies a still-pending initial run.
        $backfill_result = $this->maybe_schedule_pending_initial_backfill();
        if (is_wp_error($backfill_result)) {
            return $backfill_result;
        }
        return $outcome;
    }

    /** Queues a separate manual shipping run after the normal configuration gate. */
    public function queue_manual_shipping()
    {
        if (!current_user_can("manage_woocommerce")) {
            return new WP_Error("ahpc_shipping_forbidden", __("You are not allowed to import shipping.", "aura-historia-partner-connect"));
        }
        $settings = $this->get_current_settings();
        if (!$this->is_woocommerce_available() || !Webhook_Manager::is_valid_listing_source_id($settings["listing_source_id"])
            || !Webhook_Manager::is_valid_access_token($settings["access_token"])) {
            return Shipping_Mapper::error("not_connected");
        }
        $this->bootstrap_woocommerce();
        $result = $this->manager->maybe_sync_webhooks(Webhook_Manager::SYNC_WAIT_SECONDS);
        if (!$result) {
            return Shipping_Mapper::error("configuration_not_ready");
        }
        (new Shipping_Sync())->initialize(true);
        return (new Shipping_Publisher())->queue($settings["listing_source_id"]);
    }

    public function handle_shipping_request()
    {
        if (!current_user_can("manage_woocommerce")) {
            wp_die(esc_html__("You are not allowed to import shipping.", "aura-historia-partner-connect"), 403);
        }
        check_admin_referer("ahpc_import_shipping");
        $result = $this->queue_manual_shipping();
        wp_safe_redirect(add_query_arg("ahpc_shipping", is_wp_error($result) ? "failed" : "queued", $this->get_settings_page_url()));
        exit();
    }

    /**
     * Marks the plugin webhooks as out of sync after a managed field changed.
     * WooCommerce also saves a webhook after every delivery, so a save alone
     * is no reason to stage the webhooks paused and repeat the backend PUT.
     *
     * @param int $webhook_id Webhook ID.
     * @return void
     */
    public function maybe_mark_edited_managed_webhook_out_of_sync($webhook_id)
    {
        if (!$this->manager instanceof Webhook_Manager) {
            return;
        }

        if ($this->manager->is_syncing()) {
            return;
        }

        if ($this->manager->has_managed_webhook_drift($webhook_id)) {
            $this->manager->mark_sync_required();
        }
    }

    /**
     * Marks the plugin webhooks as out of sync after a deletion.
     *
     * @param int             $webhook_id Webhook ID.
     * @param \WC_Webhook|null $webhook   Optional webhook instance.
     * @return void
     */
    public function maybe_mark_managed_webhook_out_of_sync(
        $webhook_id,
        $webhook = null,
    ) {
        if (!$this->manager instanceof Webhook_Manager) {
            return;
        }

        if ($this->manager->is_syncing()) {
            return;
        }

        if ($this->manager->owns_webhook_id($webhook_id, $webhook)) {
            $this->manager->mark_sync_required();
        }
    }

    /**
     * Shows a dependency notice when WooCommerce is unavailable.
     *
     * @return void
     */
    public function maybe_show_dependency_notice()
    {
        if (
            $this->is_woocommerce_available() ||
            !current_user_can("activate_plugins")
        ) {
            return;
        }

        $screen = function_exists("get_current_screen")
            ? get_current_screen()
            : null;

        if ($screen && !in_array($screen->id, ["plugins", "dashboard"], true)) {
            return;
        }

        echo '<div class="notice notice-error"><p>' .
            esc_html__(
                "Aura Historia Partner Connect requires WooCommerce to be installed and active.",
                "aura-historia-partner-connect",
            ) .
            "</p></div>";
    }

    /**
     * Adds a settings link to the plugin row.
     *
     * @param array<int,string> $links Existing links.
     * @return array<int,string>
     */
    public function add_plugin_action_links($links)
    {
        array_unshift(
            $links,
            sprintf(
                '<a href="%s">%s</a>',
                esc_url($this->get_settings_page_url()),
                esc_html__("Settings", "aura-historia-partner-connect"),
            ),
        );

        return $links;
    }

    /**
     * Renders the settings page.
     *
     * @return void
     */
    public function render_settings_page()
    {
        if (
            $this->is_woocommerce_available() &&
            !$this->woocommerce_bootstrapped
        ) {
            $this->bootstrap_woocommerce();
        }

        $this->discard_expired_oauth_attempt();

        $settings = $this->get_current_settings();
        $backend_base_url = Webhook_Manager::get_backend_base_url();
        $oauth_broker_base_url = $this->get_oauth_broker_base_url();
        $webhook_endpoint_url = Webhook_Manager::get_webhook_endpoint_url(
            $settings["listing_source_id"],
        );
        $sync_error =
            $this->manager instanceof Webhook_Manager
                ? $this->manager->get_last_sync_error()
                : (string) get_option(
                    Webhook_Manager::OPTION_LAST_SYNC_ERROR,
                    "",
                );
        $last_sync_at =
            $this->manager instanceof Webhook_Manager
                ? $this->manager->get_last_sync_at()
                : (string) get_option(Webhook_Manager::OPTION_LAST_SYNC_AT, "");
        $summaries =
            $this->manager instanceof Webhook_Manager
                ? $this->manager->get_webhook_summaries()
                : [];
        $sync_success = "1" === $this->get_query_param("ahpc_synced");
        $backfill_request_status = sanitize_key(
            $this->get_query_param("ahpc_backfill"),
        );
        $oauth_status = sanitize_key($this->get_query_param("ahpc_oauth"));
        $oauth_error = (string) get_option(
            Webhook_Manager::OPTION_LAST_OAUTH_ERROR,
            "",
        );
        $initial_backfill_error = (string) get_option(self::OPTION_INITIAL_BACKFILL_ERROR, "");
        $logs_url = admin_url("admin.php?page=wc-status&tab=logs");
        $webhooks_url = admin_url(
            "admin.php?page=wc-settings&tab=advanced&section=webhooks",
        );
        $is_connected =
            Webhook_Manager::is_valid_listing_source_id($settings["listing_source_id"]) &&
            Webhook_Manager::is_valid_access_token($settings["access_token"]);
        $should_auto_start_oauth =
            $this->is_woocommerce_available() &&
            !$is_connected &&
            "failed" !== $oauth_status &&
            "" === $oauth_error &&
            !empty($backend_base_url) &&
            "" !== $oauth_broker_base_url &&
            $this->is_valid_oauth_client_id($this->get_oauth_client_id());
        $connection_status = $this->get_connection_status(
            $settings,
            $sync_error,
            $last_sync_at,
        );
        $backfill_status = $this->get_backfill_status($settings);
        ?>
		<div class="wrap">
			<h1><?php echo esc_html__(
       "Aura Historia Partner Connect",
       "aura-historia-partner-connect",
   ); ?></h1>
			<p><?php echo esc_html__(
       "Connect this WooCommerce store to Aura Historia so your products can appear there and stay up to date automatically.",
       "aura-historia-partner-connect",
   ); ?></p>
			<?php $this->render_setting_messages(); ?>

			<?php if (!$this->is_woocommerce_available()): ?>
				<?php $this->render_inline_notice(
        "error",
        esc_html__(
            "WooCommerce is not active, so the managed webhooks cannot be created yet.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif ($sync_success): ?>
				<?php $this->render_inline_notice(
        "success",
        esc_html__(
            "Managed WooCommerce webhooks synced. Backend ingestion may still be processing asynchronously.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php endif; ?>

			<?php if (Product_Backfill::START_FRESH === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "success",
        esc_html__(
            "A fresh full product backfill was queued. Existing listings will be submitted for asynchronous processing in the background.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif (Product_Backfill::START_RESUMED === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "success",
        esc_html__(
            "The interrupted product backfill was resumed with its saved batch unchanged; no fresh run was started. Remaining listings will be submitted for asynchronous processing in the background.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif (Product_Backfill::START_ALREADY_QUEUED === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "info",
        esc_html__(
            "A product backfill is already queued or running, so it was left unchanged and no fresh run was started. Its listings are submitted for asynchronous processing in the background.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif ("invalid" === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Connect this store to Aura Historia before queueing a full product backfill.",
            "aura-historia-partner-connect",
        ),
    ); ?>
				<?php elseif ("unavailable" === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "error",
        esc_html__(
            "The product backfill could not be queued because WooCommerce or Action Scheduler is not available yet.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif ("failed" === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "error",
        esc_html__(
            "The product backfill could not be queued. Check the backfill status below for more detail.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php endif; ?>

			<?php if ("connected" === $oauth_status): ?>
				<?php $this->render_inline_notice(
        "success",
        esc_html__(
            "Aura Historia authorization completed and webhook sync submitted. Product listings are processed asynchronously.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif ("saved" === $oauth_status): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Aura Historia authorization completed and the connection was saved, but its setup has not finished yet. The plugin keeps retrying in the background, as shown below; you do not need to reconnect.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php endif; ?>

			<?php if (!empty($oauth_error)): ?>
				<?php $this->render_inline_notice("error", esc_html($oauth_error)); ?>
			<?php elseif (!empty($sync_error)): ?>
				<?php $this->render_inline_notice("error", esc_html($sync_error)); ?>
			<?php elseif (empty($backend_base_url)): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Aura Historia is not fully configured inside the plugin yet. Define AHPC_BACKEND_BASE_URL before connecting a store.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif (!$this->is_valid_oauth_client_id($this->get_oauth_client_id()) && !$is_connected): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Define a valid oc_ OAuth client ID with AHPC_OAUTH_CLIENT_ID before connecting this store.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif ("" === $oauth_broker_base_url && !$is_connected): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Define a valid HTTPS Aura Historia origin with AHPC_OAUTH_BROKER_BASE_URL before connecting this store.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif (!$is_connected): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Connect with Aura Historia to authorize this WooCommerce store. Product listings are admitted for asynchronous processing after the connection completes.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif (empty($webhook_endpoint_url)): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "The webhook delivery URL could not be built from the current connection.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php elseif ("error" === $connection_status["type"]): ?>
				<?php $this->render_inline_notice(
        "error",
        esc_html($connection_status["message"]),
    ); ?>
			<?php endif; ?>

			<h2><?php echo esc_html__(
        "Aura Historia connection",
        "aura-historia-partner-connect",
    ); ?></h2>
			<p><?php echo esc_html__(
        "The plugin connects through Aura Historia OAuth. It stores the returned Listing Source ID and access token locally, but never displays the access token in wp-admin.",
        "aura-historia-partner-connect",
    ); ?></p>

			<?php if ($this->is_woocommerce_available()): ?>
				<form id="ahpc-oauth-start-form" method="post" action="<?php echo esc_url(
        admin_url("admin-post.php"),
    ); ?>">
					<input type="hidden" name="action" value="ahpc_start_oauth" />
					<?php wp_nonce_field("ahpc_start_oauth"); ?>
					<?php submit_button(
          $is_connected
              ? esc_html__(
                  "Reconnect with Aura Historia",
                  "aura-historia-partner-connect",
              )
              : esc_html__(
                  "Connect with Aura Historia",
                  "aura-historia-partner-connect",
              ),
          $is_connected ? "secondary" : "primary",
          "submit",
          false,
      ); ?>
				</form>
				<p class="description"><?php echo esc_html__(
          "You will be sent to Aura Historia to approve access, then returned here automatically.",
          "aura-historia-partner-connect",
      ); ?></p>
			<?php endif; ?>

			<?php if ($should_auto_start_oauth): ?>
				<script>
					(function () {
						var storageKey = "ahpcOauthAutoStart";
						var form = document.getElementById("ahpc-oauth-start-form");
						var storage;

						if (!form) {
							return;
						}

						// The attempt is recorded per tab to prevent redirect loops. If it
						// cannot be recorded, leave the start to the manual button.
						try {
							storage = window.sessionStorage;
							if (!storage || storage.getItem(storageKey)) {
								return;
							}
							storage.setItem(storageKey, "1");
						} catch (error) {
							return;
						}

						try {
							// The button named "submit" shadows form.submit, so call the
							// native method directly.
							HTMLFormElement.prototype.submit.call(form);
						} catch (error) {
							// Only a submission the browser rejects is retried on the next
							// visit. A sent request counts as the tab's attempt.
							storage.removeItem(storageKey);
							throw error;
						}
					})();
				</script>
				<noscript>
					<p class="description"><?php echo esc_html__(
          "If the Aura Historia connection does not start automatically, use the Connect with Aura Historia button.",
          "aura-historia-partner-connect",
      ); ?></p>
				</noscript>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__(
          "Connection status",
          "aura-historia-partner-connect",
      ); ?></th>
						<td>
							<strong><?php echo esc_html($connection_status["label"]); ?></strong>
							<p class="description"><?php echo esc_html(
            $connection_status["message"],
        ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__(
          "Aura Historia Listing Source ID",
          "aura-historia-partner-connect",
      ); ?></th>
						<td>
							<?php if (Webhook_Manager::is_valid_listing_source_id($settings["listing_source_id"])): ?>
								<code><?php echo esc_html($settings["listing_source_id"]); ?></code>
							<?php else: ?>
								&mdash;
							<?php endif; ?>
							<p class="description"><?php echo esc_html__(
            "This is set automatically by the Aura Historia OAuth flow.",
            "aura-historia-partner-connect",
        ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__(
          "Access token",
          "aura-historia-partner-connect",
      ); ?></th>
						<td>
							<strong><?php echo $is_connected
             ? esc_html__("Stored", "aura-historia-partner-connect")
             : esc_html__("Not stored", "aura-historia-partner-connect"); ?></strong>
							<p class="description"><?php echo esc_html__(
            "The access token is saved locally for webhook delivery, product backfill and shipping requests, but it is hidden from administrators after OAuth completes.",
            "aura-historia-partner-connect",
        ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__(
          "Product backfill",
          "aura-historia-partner-connect",
      ); ?></th>
						<td>
							<strong><?php echo esc_html($backfill_status["label"]); ?></strong>
							<p class="description"><?php echo esc_html($backfill_status["message"]); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ("" !== $initial_backfill_error): ?>
				<?php $this->render_inline_notice("error", esc_html($initial_backfill_error)); ?>
			<?php endif; ?>

			<h2><?php echo esc_html__(
       "Existing product backfill",
       "aura-historia-partner-connect",
   ); ?></h2>
			<p><?php echo esc_html__(
       "Use this to resume an interrupted backfill or, once the previous run has finished, start a fresh full catalog run. Pending batches and their retry keys are never replaced.",
       "aura-historia-partner-connect",
   ); ?></p>
			<?php if ($this->is_woocommerce_available()): ?>
				<form method="post" action="<?php echo esc_url(
        admin_url("admin-post.php"),
    ); ?>">
					<input type="hidden" name="action" value="ahpc_queue_backfill" />
					<?php wp_nonce_field("ahpc_queue_backfill"); ?>
					<?php submit_button(
         esc_html__(
             "Re-send all existing products",
             "aura-historia-partner-connect",
         ),
         "secondary",
         "submit",
         false,
     ); ?>
				</form>
				<p class="description"><?php echo esc_html__(
        "Backfill runs in the background via Action Scheduler. While a run is active, this action resumes missing work or leaves already queued work unchanged.",
        "aura-historia-partner-connect",
    ); ?></p>
			<?php endif; ?>

			<?php $this->render_shipping_status($settings["listing_source_id"]); ?>
			<h2><?php echo esc_html__(
       "Managed webhooks",
       "aura-historia-partner-connect",
   ); ?></h2>
			<p>
				<?php
    echo esc_html__(
        "The plugin owns exactly three WooCommerce webhooks and keeps them in sync with the built-in backend endpoint pattern and the OAuth connection above. A successful sync means delivery was admitted, not that every listing has finished processing.",
        "aura-historia-partner-connect",
    );
    if ($last_sync_at) {
        echo " " .
            sprintf(
                /* translators: %s: formatted sync time. */
                esc_html__("Last sync: %s.", "aura-historia-partner-connect"),
                esc_html(
                    mysql2date(
                        get_option("date_format") .
                            " " .
                            get_option("time_format"),
                        $last_sync_at,
                    ),
                ),
            );
    }
    ?>
			</p>

			<?php if (!empty($summaries)): ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__("Topic", "aura-historia-partner-connect"); ?></th>
							<th><?php echo esc_html__(
           "Webhook ID",
           "aura-historia-partner-connect",
       ); ?></th>
							<th><?php echo esc_html__("Status", "aura-historia-partner-connect"); ?></th>
							<th><?php echo esc_html__(
           "Delivery URL",
           "aura-historia-partner-connect",
       ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($summaries as $summary): ?>
							<tr>
								<td><code><?php echo esc_html($summary["topic"]); ?></code></td>
								<td><?php echo $summary["id"]
            ? esc_html((string) $summary["id"])
            : "&mdash;"; ?></td>
								<td><?php echo esc_html($summary["status"]); ?></td>
								<td><?php echo !empty($summary["delivery_url"])
            ? esc_html($summary["delivery_url"])
            : "&mdash;"; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<p><?php echo esc_html__(
        "Managed webhook details will appear here after WooCommerce is available and a sync has run.",
        "aura-historia-partner-connect",
    ); ?></p>
			<?php endif; ?>

			<p>
				<a class="button button-secondary" href="<?php echo esc_url(
        $webhooks_url,
    ); ?>"><?php echo esc_html__(
    "Open WooCommerce webhooks",
    "aura-historia-partner-connect",
); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url(
        $logs_url,
    ); ?>"><?php echo esc_html__(
    "Open webhook delivery logs",
    "aura-historia-partner-connect",
); ?></a>
			</p>

			<?php if ($this->is_woocommerce_available()): ?>
				<form method="post" action="<?php echo esc_url(
        admin_url("admin-post.php"),
    ); ?>">
					<input type="hidden" name="action" value="ahpc_sync_webhooks" />
					<?php wp_nonce_field("ahpc_sync_webhooks"); ?>
					<?php submit_button(
         esc_html__("Sync webhooks now", "aura-historia-partner-connect"),
         "secondary",
         "submit",
         false,
     ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
    }

    /**
     * Renders settings messages for the plugin page.
     *
     * @return void
     */
    private function render_setting_messages()
    {
        if (!function_exists("get_settings_errors")) {
            return;
        }

        foreach (
            get_settings_errors(Webhook_Manager::OPTION_SETTINGS)
            as $error
        ) {
            $type = isset($error["type"]) ? (string) $error["type"] : "error";
            $message = isset($error["message"])
                ? (string) $error["message"]
                : "";

            $this->render_inline_notice(
                "updated" === $type ? "success" : $type,
                esc_html($message),
            );
        }
    }

    /**
     * Returns the current Aura Historia connection status for the settings page.
     *
     * @param array<string,mixed> $settings     Current plugin settings.
     * @param string              $sync_error   Last saved sync error.
     * @param string              $last_sync_at Last successful sync timestamp.
     * @return array<string,string>
     */
    private function get_connection_status(
        $settings,
        $sync_error = "",
        $last_sync_at = "",
    ) {
        if ("" === Webhook_Manager::get_backend_base_url()) {
            return [
                "type" => "warning",
                "label" => __(
                    "Not configured",
                    "aura-historia-partner-connect",
                ),
                "message" => __(
                    "Define AHPC_BACKEND_BASE_URL before verifying the Aura Historia connection.",
                    "aura-historia-partner-connect",
                ),
            ];
        }

        if (
            !Webhook_Manager::is_valid_listing_source_id($settings["listing_source_id"]) ||
            !Webhook_Manager::is_valid_access_token($settings["access_token"])
        ) {
            return [
                "type" => "warning",
                "label" => __("Not verified", "aura-historia-partner-connect"),
                "message" => __(
                    "Connect with Aura Historia to authorize this store and receive an access token.",
                    "aura-historia-partner-connect",
                ),
            ];
        }

        $sync = $this->manager instanceof Webhook_Manager
            ? $this->manager->get_sync_status()
            : ["status" => "", "attempts" => 0, "backoff_attempts" => 0, "next_at" => 0];
        $next_at = $this->format_admin_timestamp($sync["next_at"]);
        $next_at = "" !== $next_at ? $next_at : __("the next opportunity", "aura-historia-partner-connect");
        $slow_hours = (int) round(Webhook_Manager::SYNC_SLOW_RETRY_DELAY / HOUR_IN_SECONDS);

        if (Webhook_Manager::SYNC_NEEDS_ATTENTION === $sync["status"]) {
            $message = "" !== $sync_error
                ? sprintf(
                    /* translators: %s: connection check error detail. */
                    __("Aura Historia did not accept the webhook configuration: %s", "aura-historia-partner-connect"),
                    $sync_error,
                )
                : __("Aura Historia did not accept the webhook configuration.", "aura-historia-partner-connect");
            $message .= " " . sprintf(
                /* translators: 1: retry interval in hours, 2: formatted time. */
                _n(
                    'Delivery stays paused. This usually needs a fix, such as reconnecting the store or selecting a supported currency, but the plugin keeps trying on its own every %1$d hour; the next attempt runs at %2$s.',
                    'Delivery stays paused. This usually needs a fix, such as reconnecting the store or selecting a supported currency, but the plugin keeps trying on its own every %1$d hours; the next attempt runs at %2$s.',
                    $slow_hours,
                    "aura-historia-partner-connect",
                ),
                $slow_hours,
                $next_at,
            );

            return [
                "type" => "error",
                "label" => __("Needs attention", "aura-historia-partner-connect"),
                "message" => $message,
            ];
        }

        if (Webhook_Manager::SYNC_RETRYING === $sync["status"]) {
            $message = sprintf(
                /* translators: 1: error detail, 2: failed attempts in a row, 3: formatted time. */
                __(
                    'Aura Historia did not confirm the webhook configuration: %1$s Delivery stays paused. Failed attempts so far: %2$d. The plugin retries on its own; the next attempt runs in the background at %3$s.',
                    "aura-historia-partner-connect",
                ),
                "" !== $sync_error ? $sync_error : __("The request could not be confirmed.", "aura-historia-partner-connect"),
                $sync["attempts"],
                $next_at,
            );

            if ($sync["backoff_attempts"] && $sync["attempts"] >= $sync["backoff_attempts"]) {
                $message .= " " . sprintf(
                    /* translators: %d: retry interval in hours. */
                    _n(
                        "Aura Historia has been unavailable for a while, so it is now tried every %d hour.",
                        "Aura Historia has been unavailable for a while, so it is now tried every %d hours.",
                        $slow_hours,
                        "aura-historia-partner-connect",
                    ),
                    $slow_hours,
                );
            }

            return [
                "type" => "warning",
                "label" => __("Retry scheduled", "aura-historia-partner-connect"),
                "message" => $message,
            ];
        }

        // A queued fresh attempt is pending, even after an earlier error.
        if ("yes" === get_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes")) {
            return [
                "type" => "warning",
                "label" => __("Sync pending", "aura-historia-partner-connect"),
                "message" => __(
                    "The webhook configuration is queued for background synchronization. Product listing ingestion has not been admitted yet.",
                    "aura-historia-partner-connect",
                ),
            ];
        }

        if ("" !== $sync_error) {
            return [
                "type" => "error",
                "label" => __("Check failed", "aura-historia-partner-connect"),
                "message" => sprintf(
                    /* translators: %s: connection check error detail. */
                    __(
                        "Aura Historia did not accept the listing source webhook registration: %s",
                        "aura-historia-partner-connect",
                    ),
                    $sync_error,
                ),
            ];
        }

        if ("" !== $last_sync_at) {
            return [
                "type" => "success",
                "label" => __("Connected", "aura-historia-partner-connect"),
                "message" => __(
                    "Aura Historia accepted the listing source webhook registration during the most recent sync. Product listings are processed asynchronously; this does not confirm their publication.",
                    "aura-historia-partner-connect",
                ),
            ];
        }

        return [
            "type" => "warning",
            "label" => __("Not verified", "aura-historia-partner-connect"),
            "message" => __(
                "Run a webhook sync to submit the listing source registration. Product listings are processed asynchronously.",
                "aura-historia-partner-connect",
            ),
        ];
    }

    /**
     * Returns the current product backfill status for the settings page.
     *
     * @param array<string,mixed> $settings Current plugin settings.
     * @return array<string,string>
     */
    private function get_backfill_status($settings)
    {
        if (!class_exists(Product_Backfill::class)) {
            return [
                "label" => __("Unavailable", "aura-historia-partner-connect"),
                "message" => __(
                    "The product backfill component is not loaded right now.",
                    "aura-historia-partner-connect",
                ),
            ];
        }

        if (!function_exists("as_schedule_single_action")) {
            return [
                "label" => __("Unavailable", "aura-historia-partner-connect"),
                "message" => sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Action Scheduler is not available, so the product backfill action "%s" cannot be queued.',
                        "aura-historia-partner-connect",
                    ),
                    Product_Backfill::ACTION_HOOK,
                ),
            ];
        }

        $details = (new Product_Backfill())->get_status_details();
        $hook = isset($details["hook"])
            ? (string) $details["hook"]
            : Product_Backfill::ACTION_HOOK;
        $status = isset($details["status"])
            ? (string) $details["status"]
            : Product_Backfill::STATUS_NOT_SCHEDULED;
        $next_scheduled_at = isset($details["next_scheduled_at"])
            ? absint($details["next_scheduled_at"])
            : 0;
        $scheduled_at = $next_scheduled_at
            ? $this->format_admin_timestamp($next_scheduled_at)
            : $this->format_admin_datetime(
                isset($details["scheduled_at"])
                    ? (string) $details["scheduled_at"]
                    : "",
            );
        $started_at = $this->format_admin_datetime(
            isset($details["started_at"])
                ? (string) $details["started_at"]
                : "",
        );
        $completed_at = $this->format_admin_datetime(
            isset($details["completed_at"])
                ? (string) $details["completed_at"]
                : "",
        );
        $failed_at = $this->format_admin_datetime(
            isset($details["failed_at"]) ? (string) $details["failed_at"] : "",
        );
        $last_error = isset($details["last_error"])
            ? (string) $details["last_error"]
            : "";
        $attempts = isset($details["attempts"]) ? absint($details["attempts"]) : 0;
        $backoff_attempts = isset($details["backoff_attempts"]) ? absint($details["backoff_attempts"]) : 0;

        if (Product_Backfill::STATUS_RETRY_SCHEDULED === $status) {
            $message = sprintf(
                /* translators: 1: error detail, 2: failed attempts in a row, 3: formatted time. */
                __(
                    '%1$s Failed attempts so far: %2$d. The unchanged batch is sent again automatically with the same retry key at %3$s.',
                    "aura-historia-partner-connect",
                ),
                "" !== $last_error ? $last_error : __("The most recent product backfill batch could not be confirmed.", "aura-historia-partner-connect"),
                $attempts,
                "" !== $scheduled_at ? $scheduled_at : __("the next opportunity", "aura-historia-partner-connect"),
            );

            if ($backoff_attempts && $attempts >= $backoff_attempts) {
                $slow_hours = (int) round(Product_Backfill::SLOW_RETRY_DELAY / HOUR_IN_SECONDS);
                $message .= " " . sprintf(
                    /* translators: %d: retry interval in hours. */
                    _n(
                        "Aura Historia has been unavailable for a while, so it is now tried every %d hour.",
                        "Aura Historia has been unavailable for a while, so it is now tried every %d hours.",
                        $slow_hours,
                        "aura-historia-partner-connect",
                    ),
                    $slow_hours,
                );
            }

            return [
                "label" => __("Retry scheduled", "aura-historia-partner-connect"),
                "message" => $message,
            ];
        }

        if (Product_Backfill::STATUS_STOPPED === $status) {
            $message = "" !== $last_error
                ? $last_error
                : __("The product backfill stopped.", "aura-historia-partner-connect");

            $message .= " " . __(
                "Use Re-send all existing products to resume it: a pending batch is sent again unchanged with its retry key, otherwise the run continues with its next page.",
                "aura-historia-partner-connect",
            );

            return [
                "label" => __("Stopped", "aura-historia-partner-connect"),
                "message" => $message,
            ];
        }

        if (Product_Backfill::STATUS_SCHEDULED === $status) {
            $message = $scheduled_at
                ? sprintf(
                    /* translators: 1: Action Scheduler hook name, 2: formatted time. */
                    __(
                        'Action Scheduler hook "%1$s" is queued to run at %2$s.',
                        "aura-historia-partner-connect",
                    ),
                    $hook,
                    $scheduled_at,
                )
                : sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Action Scheduler hook "%s" is queued and waiting for Action Scheduler to finish initializing.',
                        "aura-historia-partner-connect",
                    ),
                    $hook,
                );

            if ($completed_at) {
                $message .=
                    " " .
                    sprintf(
                        /* translators: %s: formatted time. */
                        __(
                            "Last successful backfill: %s.",
                            "aura-historia-partner-connect",
                        ),
                        $completed_at,
                    );
            }

            return [
                "label" => __("Queued", "aura-historia-partner-connect"),
                "message" => $message,
            ];
        }

        if (Product_Backfill::STATUS_RUNNING === $status) {
            $message = $started_at
                ? sprintf(
                    /* translators: 1: Action Scheduler hook name, 2: formatted time. */
                    __(
                        'Action Scheduler hook "%1$s" started processing at %2$s.',
                        "aura-historia-partner-connect",
                    ),
                    $hook,
                    $started_at,
                )
                : sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Action Scheduler hook "%s" is currently processing existing products.',
                        "aura-historia-partner-connect",
                    ),
                    $hook,
                );

            if ($completed_at) {
                $message .=
                    " " .
                    sprintf(
                        /* translators: %s: formatted time. */
                        __(
                            "Last successful backfill: %s.",
                            "aura-historia-partner-connect",
                        ),
                        $completed_at,
                    );
            }

            return [
                "label" => __("Running", "aura-historia-partner-connect"),
                "message" => $message,
            ];
        }

        if (Product_Backfill::STATUS_COMPLETE === $status) {
            return [
                "label" => __("Completed", "aura-historia-partner-connect"),
                "message" => $completed_at
                    ? sprintf(
                        /* translators: 1: formatted time, 2: Action Scheduler hook name. */
                        __(
                            'The most recent product backfill finished submitting at %1$s using Action Scheduler hook "%2$s". Backend ingestion may still be processing.',
                            "aura-historia-partner-connect",
                        ),
                        $completed_at,
                        $hook,
                    )
                    : sprintf(
                        /* translators: %s: Action Scheduler hook name. */
                        __(
                            'The most recent product backfill finished submitting using Action Scheduler hook "%s". Backend ingestion may still be processing.',
                            "aura-historia-partner-connect",
                        ),
                        $hook,
                    ),
            ];
        }

        if (Product_Backfill::STATUS_FAILED === $status) {
            if ($failed_at && $last_error) {
                $message = sprintf(
                    /* translators: 1: formatted time, 2: error detail. */
                    __(
                        'The most recent product backfill batch failed at %1$s: %2$s',
                        "aura-historia-partner-connect",
                    ),
                    $failed_at,
                    $last_error,
                );
            } elseif ($last_error) {
                $message = sprintf(
                    /* translators: %s: error detail. */
                    __(
                        "The most recent product backfill batch failed: %s",
                        "aura-historia-partner-connect",
                    ),
                    $last_error,
                );
            } else {
                $message = __(
                    "The most recent product backfill batch failed.",
                    "aura-historia-partner-connect",
                );
            }

            $message .=
                " " .
                __(
                    "It is not retried automatically. Use Re-send all existing products to resume retained work or start a fresh run.",
                    "aura-historia-partner-connect",
                );

            if ($completed_at) {
                $message .=
                    " " .
                    sprintf(
                        /* translators: %s: formatted time. */
                        __(
                            "Last successful backfill: %s.",
                            "aura-historia-partner-connect",
                        ),
                        $completed_at,
                    );
            }

            return [
                "label" => __("Failed", "aura-historia-partner-connect"),
                "message" => $message,
            ];
        }

        if (
            !Webhook_Manager::is_valid_listing_source_id($settings["listing_source_id"]) ||
            !Webhook_Manager::is_valid_access_token($settings["access_token"])
        ) {
            return [
                "label" => __("Not queued", "aura-historia-partner-connect"),
                "message" => sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Connect this store to Aura Historia to queue the product backfill action "%s".',
                        "aura-historia-partner-connect",
                    ),
                    $hook,
                ),
            ];
        }

        $message = sprintf(
            /* translators: %s: Action Scheduler hook name. */
            __(
                'No product backfill batch is currently queued. The plugin uses Action Scheduler hook "%s" to backfill existing products after a successful sync.',
                "aura-historia-partner-connect",
            ),
            $hook,
        );

        if ($completed_at) {
            $message .=
                " " .
                sprintf(
                    /* translators: %s: formatted time. */
                    __(
                        "Last successful backfill: %s.",
                        "aura-historia-partner-connect",
                    ),
                    $completed_at,
                );
        }

        return [
            "label" => __("Not queued", "aura-historia-partner-connect"),
            "message" => $message,
        ];
    }

    /** Bounded progress and capability diagnostics; never display saved payloads. */
    private function render_shipping_status($source)
    {
        $state = (new Shipping_Publisher())->get_status($source);
        echo '<h2>' . esc_html__("Shipping import", "aura-historia-partner-connect") . '</h2>';
        echo '<p>' . esc_html__("Shipping changes refresh automatically in the background. Import shipping repairs missed changes and resumes interrupted work.", "aura-historia-partner-connect") . '</p>';
        $queue = (new Shipping_Sync())->status();
        $oldest = $queue["oldest_pending"];
        if (in_array($state["status"] ?? "", ["queued", "running", "partial", "failed", "needs_review"], true)) {
            $oldest = $oldest ? min($oldest, $state["queued_at"] ?? $oldest) : ($state["queued_at"] ?? 0);
        }
        if ($oldest) {
            echo '<p>' . sprintf(
                /* translators: %s: oldest pending shipping work time. */
                esc_html__('Oldest pending shipping work: %s. If this does not advance, check WooCommerce Scheduled Actions and the server cron runner.', "aura-historia-partner-connect"),
                esc_html($this->format_admin_timestamp($oldest))
            ) . '</p>';
        }
        if ($queue["error"]) {
            echo '<p><code>' . esc_html($queue["error"]) . '</code></p>';
        }
        $cleanup = get_option(Shipping_Publisher::OPTION_CLEANUP, []);
        if (is_array($cleanup) && $cleanup && !in_array($cleanup["result"] ?? "", ["committed", "not_published"], true)) {
            echo '<p>' . esc_html__("Previous shipping cleanup was not confirmed. Review retained assertions in Aura Historia; they have no automatic expiry. Import shipping acquires current terms for this connection.", "aura-historia-partner-connect") . '</p>';
        }
        if ("failed" === $this->get_query_param("ahpc_shipping")) {
            $this->render_inline_notice("error", esc_html__("Shipping could not be queued. Check the connection and import status before trying again.", "aura-historia-partner-connect"));
        } elseif ("queued" === $this->get_query_param("ahpc_shipping")) {
            $this->render_inline_notice("success", esc_html__("Shipping import queued. Progress appears below as background work completes.", "aura-historia-partner-connect"));
        }
        $labels = ["queued" => __("Queued", "aura-historia-partner-connect"), "running" => __("Running", "aura-historia-partner-connect"),
            "complete" => __("Complete", "aura-historia-partner-connect"), "partial" => __("Partial — retry needed", "aura-historia-partner-connect"),
            "failed" => __("Failed — retry needed", "aura-historia-partner-connect"), "needs_review" => __("Needs review", "aura-historia-partner-connect")];
        echo '<p><strong>' . esc_html($labels[$state["status"] ?? ""] ?? __("Not queued", "aura-historia-partner-connect")) . '</strong></p>';
        if ($state) {
            if (!empty($state["committed_at"])) {
                echo '<p>' . sprintf(
                    /* translators: %s: last confirmed shipping write time. */
                    esc_html__('Last confirmed API write: %s. Search indexing completes separately.', "aura-historia-partner-connect"),
                    esc_html($this->format_admin_timestamp($state["committed_at"]))
                ) . '</p>';
            }
            echo '<p>' . sprintf(
                /* translators: 1: processed products, 2: pending components, 3: protected manual components. */
                esc_html__('Processed products: %1$d. Deferred components: %2$d. Protected manual edits: %3$d.', "aura-historia-partner-connect"),
                (int) ($state["processed"] ?? 0), count($state["deferred"] ?? []), (int) ($state["protected"] ?? 0)
            ) . '</p>';
            if ($queue["withdrawn"]) {
                echo '<p>' . sprintf(
                    /* translators: %d: removed products whose last shipping remains on their withdrawn listing. */
                    esc_html__('Removed products whose last shipping stays with their withdrawn listing: %d. Aura Historia does not change withdrawn listings, and shipping imports never restore one. Restoring a product from the Trash imports its current shipping.', "aura-historia-partner-connect"),
                    (int) $queue["withdrawn"]
                ) . '</p>';
            }
            if (!empty($state["limitations"])) {
                echo '<p>' . esc_html__("Some rates need checkout context and remain uncertain. See the documented shipping support matrix.", "aura-historia-partner-connect") . '</p>';
            }
            if (!empty($state["last_error"])) {
                echo '<p><code>' . esc_html($state["last_error"]) . '</code></p>';
            }
        }
        if ($this->is_woocommerce_available()) {
            echo '<form method="post" action="' . esc_url(admin_url("admin-post.php")) . '"><input type="hidden" name="action" value="ahpc_import_shipping" />';
            wp_nonce_field("ahpc_import_shipping");
            submit_button(__("Import shipping", "aura-historia-partner-connect"), "secondary", "submit", false);
            echo '</form>';
        }
    }

    /**
     * Formats a MySQL datetime for the admin UI.
     *
     * @param string $datetime MySQL datetime.
     * @return string
     */
    private function format_admin_datetime($datetime)
    {
        if ("" === $datetime) {
            return "";
        }

        return mysql2date(
            get_option("date_format") . " " . get_option("time_format"),
            $datetime,
        );
    }

    /**
     * Formats a Unix timestamp for the admin UI.
     *
     * @param int $timestamp Unix timestamp.
     * @return string
     */
    private function format_admin_timestamp($timestamp)
    {
        if ($timestamp <= 0) {
            return "";
        }

        return wp_date(
            get_option("date_format") . " " . get_option("time_format"),
            $timestamp,
        );
    }

    /**
     * Returns a sanitized query parameter value.
     *
     * @param string $key Query parameter key.
     * @return string
     */
    private function get_query_param($key)
    {
        $value = filter_input(INPUT_GET, $key, FILTER_UNSAFE_RAW);

        if (is_string($value)) {
            return sanitize_text_field($value);
        }

        if (
            !isset($_GET[$key]) || !is_string($_GET[$key]) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state is checked separately; other parameters select notices only.
        ) {
            return "";
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only admin notice query parameter.
        return sanitize_text_field(wp_unslash((string) $_GET[$key]));
    }

    /**
     * Returns the current settings with guaranteed defaults.
     *
     * @return array<string,mixed>
     */
    private function get_current_settings()
    {
        if ($this->manager instanceof Webhook_Manager) {
            return $this->manager->get_settings();
        }

        $settings = get_option(Webhook_Manager::OPTION_SETTINGS, []);

        if (!is_array($settings)) {
            $settings = [];
        }

        $settings = wp_parse_args(
            $settings,
            Webhook_Manager::default_settings(),
        );

        return [
            "listing_source_id" => Webhook_Manager::normalize_listing_source_id(
                $settings["listing_source_id"],
            ),
            "access_token" => Webhook_Manager::normalize_access_token(
                $settings["access_token"],
            ),
            "secret" => !empty($settings["secret"])
                ? sanitize_text_field((string) $settings["secret"])
                : Webhook_Manager::generate_secret(),
        ];
    }

    /**
     * Returns the configured OAuth client ID.
     *
     * @return string
     */
    public function get_oauth_client_id()
    {
        $client_id = defined("AHPC_OAUTH_CLIENT_ID") ? AHPC_OAUTH_CLIENT_ID : "";

        /**
         * Filters the OAuth client ID used for Aura Historia connection.
         *
         * @param string $client_id OAuth client TypeID (oc_ prefix).
         */
        $client_id = apply_filters("ahpc_oauth_client_id", $client_id);

        return is_string($client_id) ? $client_id : "";
    }

    /**
     * Checks the canonical oc_ TypeID format for an OAuth client.
     *
     * @param string $client_id OAuth client ID.
     * @return bool
     */
    private function is_valid_oauth_client_id($client_id)
    {
        return Type_ID_Validator::is_valid($client_id, "oc_");
    }

    /**
     * Returns the configured OAuth broker origin, or an empty string when it
     * is not a valid HTTPS (or loopback HTTP) origin.
     *
     * @return string
     */
    public function get_oauth_broker_base_url()
    {
        $base_url = defined("AHPC_OAUTH_BROKER_BASE_URL")
            ? AHPC_OAUTH_BROKER_BASE_URL
            : "";

        /**
         * Filters the Aura Historia OAuth broker origin. The initiation and
         * completion endpoints and the allowed authorization origin are
         * derived from it.
         *
         * @param string $base_url Broker origin, such as https://aura-historia.com.
         */
        $base_url = apply_filters("ahpc_oauth_broker_base_url", $base_url);
        $base_url = is_string($base_url) ? trim($base_url) : "";

        return OAuth_Broker_Client::is_valid_base_url($base_url)
            ? untrailingslashit($base_url)
            : "";
    }

    /**
     * Returns the wp-admin callback URL the broker returns the browser to.
     *
     * @return string
     */
    public function get_oauth_callback_url()
    {
        return $this->get_settings_page_url();
    }

    /**
     * Returns the configuration a connection attempt is bound to.
     *
     * @return array{client_id:string,broker_base_url:string,merchant_redirect_uri:string,scope:string}|WP_Error
     */
    private function get_oauth_context()
    {
        $client_id = $this->get_oauth_client_id();

        if (!$this->is_valid_oauth_client_id($client_id)) {
            return new WP_Error(
                "ahpc_oauth_invalid_client_id",
                __(
                    "Aura Historia OAuth is not fully configured: the OAuth client ID is invalid.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $broker_base_url = $this->get_oauth_broker_base_url();

        if ("" === $broker_base_url) {
            return new WP_Error(
                "ahpc_oauth_invalid_endpoint",
                __(
                    "Aura Historia OAuth is not fully configured: the connection service URL is invalid.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $callback_url = $this->get_oauth_callback_url();

        if (!$this->is_valid_oauth_callback_url($callback_url)) {
            return new WP_Error(
                "ahpc_oauth_invalid_callback_url",
                __(
                    "The WordPress admin URL must use HTTPS before this store can connect to Aura Historia. HTTP is only allowed for localhost development.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        return [
            "client_id" => $client_id,
            "broker_base_url" => $broker_base_url,
            "merchant_redirect_uri" => $callback_url,
            "scope" => self::OAUTH_SCOPE,
        ];
    }

    /**
     * Returns whether the current request targets the plugin settings page.
     *
     * @return bool
     */
    private function is_settings_page_request()
    {
        global $pagenow;

        return "admin.php" === $pagenow && self::PAGE_SLUG === $this->get_query_param("page");
    }

    /**
     * Returns the raw query string, which still shows duplicated parameters.
     *
     * @return string
     */
    private function get_raw_query_string()
    {
        if (!isset($_SERVER["QUERY_STRING"]) || !is_string($_SERVER["QUERY_STRING"])) {
            return "";
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed strictly by OAuth_Broker_Client::parse_unique_query() and validated field by field.
        return wp_unslash($_SERVER["QUERY_STRING"]);
    }

    /**
     * Stores the latest OAuth error for admin display.
     *
     * @param string $message Error message.
     * @return void
     */
    private function store_oauth_error($message)
    {
        update_option(
            Webhook_Manager::OPTION_LAST_OAUTH_ERROR,
            sanitize_text_field((string) $message),
            false,
        );
    }

    /**
     * Removes an expired attempt and its completion secret. Skipped while a
     * start or completion holds the lease.
     *
     * @return void
     */
    private function discard_expired_oauth_attempt()
    {
        $attempt = Option_Lease::read(self::OPTION_OAUTH_ATTEMPT, null);

        if (null === $attempt || (is_array($attempt) && isset($attempt["expires_at"]) && (int) $attempt["expires_at"] > time())) {
            return;
        }

        $lease = new Option_Lease(self::OPTION_OAUTH_LOCK);

        if (!$lease->acquire(self::OAUTH_LOCK_SECONDS)) {
            return;
        }

        try {
            $attempt = Option_Lease::read(self::OPTION_OAUTH_ATTEMPT, null);
            if (null !== $attempt && (!is_array($attempt) || !isset($attempt["expires_at"]) || (int) $attempt["expires_at"] <= time())) {
                $lease->delete(self::OPTION_OAUTH_ATTEMPT);
            }
        } finally {
            $lease->release();
        }
    }

    /**
     * Returns whether a URL may receive the broker's callback: HTTPS, or HTTP
     * only on a loopback host for local development.
     *
     * @param string $url Callback URL.
     * @return bool
     */
    private function is_valid_oauth_callback_url($url)
    {
        $parts = wp_parse_url($url);

        return is_array($parts) &&
            OAuth_Broker_Client::is_allowed_origin($parts) &&
            !isset($parts["fragment"]);
    }

    /**
     * Generates 32 bytes from the system CSPRNG as base64url without padding.
     * There is deliberately no weaker fallback.
     *
     * @return string|WP_Error
     */
    protected function generate_oauth_random_value()
    {
        try {
            return $this->base64url_encode(random_bytes(32));
        } catch (\Throwable $exception) {
            unset($exception);

            return new WP_Error(
                "ahpc_oauth_random_unavailable",
                __(
                    "This server could not generate secure random values, so the connection was not started. Ask your host to check PHP's random number source.",
                    "aura-historia-partner-connect",
                ),
            );
        }
    }

    /**
     * Encodes raw bytes using base64url without padding.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function base64url_encode($value)
    {
        return rtrim(strtr(base64_encode((string) $value), "+/", "-_"), "=");
    }

    /**
     * Returns whether a granted scope string is exactly the requested set:
     * nothing required is missing and nothing unrequested was added.
     *
     * @param string $granted   Granted scope string.
     * @param string $requested Requested scope string.
     * @return bool
     */
    private function is_requested_oauth_scope($granted, $requested)
    {
        $granted_scopes = explode(" ", (string) $granted);
        $requested_scopes = explode(" ", (string) $requested);
        sort($granted_scopes);
        sort($requested_scopes);

        return $granted_scopes === $requested_scopes;
    }

    /**
     * @return WP_Error
     */
    private function oauth_forbidden_error()
    {
        return new WP_Error(
            "ahpc_oauth_forbidden",
            __(
                "You are not allowed to connect this store to Aura Historia.",
                "aura-historia-partner-connect",
            ),
        );
    }

    /**
     * @return WP_Error
     */
    private function oauth_busy_error()
    {
        return new WP_Error(
            "ahpc_oauth_busy",
            __(
                "Another Aura Historia connection step is still running on this site. Wait a minute, then start the connection again from this page.",
                "aura-historia-partner-connect",
            ),
        );
    }

    /**
     * @return WP_Error
     */
    private function oauth_storage_error()
    {
        return new WP_Error(
            "ahpc_oauth_storage_failed",
            __(
                "This site could not save the connection attempt. Try again; if the problem persists, check the WordPress database.",
                "aura-historia-partner-connect",
            ),
        );
    }

    /**
     * Returns the settings page URL.
     *
     * @return string
     */
    private function get_settings_page_url()
    {
        return admin_url("admin.php?page=" . self::PAGE_SLUG);
    }

    /**
     * Returns whether WooCommerce is active.
     *
     * @return bool
     */
    private function is_woocommerce_available()
    {
        return class_exists("WooCommerce") ||
            class_exists("WC_Webhook") ||
            did_action("woocommerce_loaded");
    }

    /**
     * Renders an inline admin notice.
     *
     * @param string $type Notice type.
     * @param string $message Notice message.
     * @return void
     */
    private function render_inline_notice($type, $message)
    {
        printf(
            '<div class="notice notice-%1$s inline"><p>%2$s</p></div>',
            esc_attr($type),
            wp_kses_post($message),
        );
    }
}
