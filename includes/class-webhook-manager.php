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
    const SETTINGS_GROUP = "ahpc_settings_group";
    const TEXT_DOMAIN = "aura-historia-partner-connect";
    const API_VERSION = 3;

    /**
     * Whether a sync operation is currently in progress.
     *
     * @var bool
     */
    private $syncing = false;

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
            "/api/v1/webhooks/woocommerce/" .
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
     * Marks the managed webhooks for synchronization.
     *
     * @return void
     */
    public function mark_sync_required()
    {
        update_option(self::OPTION_NEEDS_SYNC, "yes", false);
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
     * Returns whether a sync should run, then runs it if required.
     *
     * @return bool
     */
    public function maybe_sync_webhooks()
    {
        if ("yes" !== get_option(self::OPTION_NEEDS_SYNC, "yes")) {
            return true;
        }

        return !is_wp_error($this->sync_webhooks());
    }

    /**
     * Synchronizes the managed webhooks.
     *
     * @return true|WP_Error
     */
    public function sync_webhooks()
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

        $this->initialize_options();
        $this->syncing = true;

        try {
            $settings = $this->get_settings();
            $listing_source_id = $settings["listing_source_id"];
            $access_token = $settings["access_token"];
            $endpoint_url = self::get_webhook_endpoint_url($listing_source_id);
            $user_id = $this->resolve_webhook_user_id();
            $webhook_ids = $this->get_webhook_ids();
            $setup_error = null;
            $has_listing_source_id = "" !== $listing_source_id;
            $has_access_token = "" !== $access_token;

            if (!$user_id) {
                $this->pause_webhooks_best_effort();
                return $this->record_sync_error(
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
                if (!wc_is_webhook_valid_topic($topic)) {
                    $this->pause_webhooks_best_effort();
                    return $this->record_sync_error(
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
                    $this->pause_webhooks_best_effort();
                    return $this->record_sync_error(
                        new WP_Error(
                            "ahpc_webhook_load_failed",
                            __("A managed webhook could not be loaded. Retry the synchronization.", "aura-historia-partner-connect"),
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
                    );
                }

                if (is_wp_error($save_result)) {
                    $this->pause_webhooks_best_effort();
                    return $this->record_sync_error($save_result);
                }

                $webhook_ids[$topic] = absint($webhook->get_id());
                // Preserve newly created IDs even if a later stage fails.
                update_option(self::OPTION_WEBHOOK_IDS, $webhook_ids, false);
            }

            if (!$setup_error && "" !== $listing_source_id && "" !== $access_token) {
                $registration_result = $this->reconcile_ingestion_configuration(
                    $listing_source_id,
                    $access_token,
                    $settings["secret"],
                );
                if (is_wp_error($registration_result)) {
                    $setup_error = $registration_result;
                } else {
                    foreach (array_keys($this->get_managed_topics()) as $topic) {
                        try {
                            $webhook = $this->load_managed_webhook($topic, $webhook_ids);
                            if (!$webhook) {
                                throw new \RuntimeException("Missing managed webhook");
                            }
                        } catch (\Throwable $exception) {
                            $setup_error = new WP_Error(
                                "ahpc_webhook_load_failed",
                                __("A managed webhook could not be loaded. Retry the synchronization.", "aura-historia-partner-connect"),
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
                $this->pause_webhooks_best_effort();
                // Webhooks are paused; leave sync required so a later attempt can
                // register the secret before activating delivery.
                return $this->record_sync_error($setup_error);
            }

            update_option(self::OPTION_NEEDS_SYNC, "no", false);
            update_option(
                self::OPTION_LAST_SYNC_AT,
                current_time("mysql"),
                false,
            );
            delete_option(self::OPTION_LAST_SYNC_ERROR);

            return true;
        } finally {
            $this->syncing = false;
        }
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
     * ingestion-configuration error wording.
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

        return new WP_Error("ahpc_backend_configuration_failed", $message);
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
        delete_option("ahpc_initial_backfill_pending_source");
        delete_option("ahpc_initial_backfill_started_sources");
        delete_option("ahpc_initial_backfill_error");

        if (class_exists(Product_Backfill::class)) {
            (new Product_Backfill())->cancel_backfill();
        }

        delete_option("ahpc_backfill_state");
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
            $webhook->save();
        } catch (\Throwable $exception) {
            return new WP_Error(
                "ahpc_webhook_save_failed",
                __("A managed webhook could not be saved. Retry the synchronization.", "aura-historia-partner-connect"),
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

                        if ($this->is_managed_webhook($webhook)) {
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
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name),
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
