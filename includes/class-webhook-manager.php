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
            "shop_id" => "",
            "api_key" => "",
            "secret" => "",
            "enabled" => false,
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
     * Normalizes a shop UUID.
     *
     * @param string $shop_id Shop UUID.
     * @return string
     */
    public static function normalize_shop_id($shop_id)
    {
        return strtolower(trim(sanitize_text_field((string) $shop_id)));
    }

    /**
     * Returns whether the given shop ID looks like a UUID.
     *
     * @param string $shop_id Shop UUID.
     * @return bool
     */
    public static function is_valid_shop_id($shop_id)
    {
        return 1 ===
            preg_match(
                "/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i",
                self::normalize_shop_id($shop_id),
            );
    }

    /**
     * Normalizes an API key.
     *
     * @param string $api_key API key.
     * @return string
     */
    public static function normalize_api_key($api_key)
    {
        return trim(sanitize_text_field((string) $api_key));
    }

    /**
     * Returns whether the given API key matches the expected lightweight format.
     *
     * @param string $api_key API key.
     * @return bool
     */
    public static function is_valid_api_key($api_key)
    {
        return 1 ===
            preg_match(
                "/\Aaurahistoria_[A-Za-z0-9]{6,}_[A-Za-z0-9]{12,}\z/",
                self::normalize_api_key($api_key),
            );
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
     * Returns the backend shop registration URL.
     *
     * @param string $shop_id Shop UUID.
     * @return string
     */
    public static function get_shop_registration_url($shop_id)
    {
        $shop_id = self::normalize_shop_id($shop_id);
        $base_url = self::get_backend_base_url();

        if (!$base_url || !self::is_valid_shop_id($shop_id)) {
            return "";
        }

        return $base_url . "/api/v1/shops/" . rawurlencode($shop_id);
    }

    /**
     * Returns the backend webhook delivery URL.
     *
     * @param string $shop_id Shop UUID.
     * @return string
     */
    public static function get_webhook_endpoint_url($shop_id)
    {
        $shop_id = self::normalize_shop_id($shop_id);
        $base_url = self::get_backend_base_url();

        if (!$base_url || !self::is_valid_shop_id($shop_id)) {
            return "";
        }

        return $base_url .
            "/api/v1/webhooks/woocommerce/" .
            rawurlencode($shop_id);
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

        $settings = wp_parse_args($settings, self::default_settings());

        $settings["shop_id"] = isset($settings["shop_id"])
            ? self::normalize_shop_id($settings["shop_id"])
            : "";
        $settings["api_key"] = isset($settings["api_key"])
            ? self::normalize_api_key($settings["api_key"])
            : "";
        $settings["secret"] = isset($settings["secret"])
            ? sanitize_text_field((string) $settings["secret"])
            : "";
        $settings["enabled"] = !empty($settings["enabled"]);

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
            "product.created" => __("Product created", self::TEXT_DOMAIN),
            "product.updated" => __("Product updated", self::TEXT_DOMAIN),
            "product.deleted" => __("Product deleted", self::TEXT_DOMAIN),
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
                        self::TEXT_DOMAIN,
                    ),
                ),
            );
        }

        $this->initialize_options();
        $this->syncing = true;

        try {
            $settings = $this->get_settings();
            $shop_id = $settings["shop_id"];
            $api_key = $settings["api_key"];
            $endpoint_url = self::get_webhook_endpoint_url($shop_id);
            $user_id = $this->resolve_webhook_user_id();
            $webhook_ids = $this->get_webhook_ids();
            $setup_error = null;

            if (!$user_id) {
                return $this->record_sync_error(
                    new WP_Error(
                        "ahpc_missing_user",
                        __(
                            "No administrator or shop manager account was found for webhook delivery context.",
                            self::TEXT_DOMAIN,
                        ),
                    ),
                );
            }

            if (!empty($settings["enabled"])) {
                if (!self::get_backend_base_url()) {
                    $setup_error = new WP_Error(
                        "ahpc_missing_backend_base_url",
                        __(
                            "The built-in backend base URL is empty. Define AHPC_BACKEND_BASE_URL before enabling delivery.",
                            self::TEXT_DOMAIN,
                        ),
                    );
                } elseif (!self::is_valid_shop_id($shop_id)) {
                    $setup_error = new WP_Error(
                        "ahpc_invalid_shop_id",
                        __(
                            "The configured Shop ID must be a valid UUID before delivery can be enabled.",
                            self::TEXT_DOMAIN,
                        ),
                    );
                } elseif (!self::is_valid_api_key($api_key)) {
                    $setup_error = new WP_Error(
                        "ahpc_invalid_api_key",
                        __(
                            "The configured API key does not match the expected Aura Historia format.",
                            self::TEXT_DOMAIN,
                        ),
                    );
                } elseif ("" === $endpoint_url) {
                    $setup_error = new WP_Error(
                        "ahpc_empty_webhook_endpoint_url",
                        __(
                            "The built-in webhook delivery URL could not be built from the current configuration.",
                            self::TEXT_DOMAIN,
                        ),
                    );
                } else {
                    $registration_result = $this->register_webhook_secret(
                        $shop_id,
                        $api_key,
                        $settings["secret"],
                    );

                    if (is_wp_error($registration_result)) {
                        $setup_error = $registration_result;
                    }
                }
            }

            $desired_status = $this->get_desired_status(
                $settings,
                $endpoint_url,
                $setup_error,
            );

            foreach (array_keys($this->get_managed_topics()) as $topic) {
                if (!wc_is_webhook_valid_topic($topic)) {
                    return $this->record_sync_error(
                        new WP_Error(
                            "ahpc_invalid_topic",
                            sprintf(
                                /* translators: %s: webhook topic. */
                                __(
                                    'WooCommerce does not recognise the webhook topic "%s".',
                                    self::TEXT_DOMAIN,
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
                } catch (\Exception $exception) {
                    return $this->record_sync_error(
                        new WP_Error(
                            "ahpc_webhook_load_failed",
                            $exception->getMessage(),
                        ),
                    );
                }

                $webhook->set_name($this->get_webhook_name($topic));
                $webhook->set_topic($topic);
                $webhook->set_status($desired_status);
                $webhook->set_delivery_url($endpoint_url);
                $webhook->set_secret($settings["secret"]);
                $webhook->set_user_id($user_id);
                $webhook->set_api_version(self::API_VERSION);

                try {
                    $webhook->save();
                } catch (\Exception $exception) {
                    return $this->record_sync_error(
                        new WP_Error(
                            "ahpc_webhook_save_failed",
                            $exception->getMessage(),
                        ),
                    );
                }

                $webhook_ids[$topic] = absint($webhook->get_id());
            }

            update_option(self::OPTION_WEBHOOK_IDS, $webhook_ids, false);
            update_option(self::OPTION_PLUGIN_VERSION, AHPC_VERSION, false);
            update_option(self::OPTION_NEEDS_SYNC, "no", false);
            update_option(
                self::OPTION_LAST_SYNC_AT,
                current_time("mysql"),
                false,
            );

            if ($setup_error) {
                return $this->record_sync_error($setup_error, false);
            }

            delete_option(self::OPTION_LAST_SYNC_ERROR);

            return true;
        } finally {
            $this->syncing = false;
        }
    }

    /**
     * Pushes the generated WooCommerce webhook secret to the backend.
     *
     * @param string $shop_id Shop UUID.
     * @param string $api_key Backend API key.
     * @param string $secret  Generated webhook secret.
     * @return true|WP_Error
     */
    private function register_webhook_secret($shop_id, $api_key, $secret)
    {
        $url = self::get_shop_registration_url($shop_id);

        if ("" === $url) {
            return new WP_Error(
                "ahpc_invalid_registration_url",
                __(
                    "The backend registration URL could not be built from the configured Shop ID.",
                    self::TEXT_DOMAIN,
                ),
            );
        }

        $response = wp_safe_remote_request($url, [
            "method" => "PATCH",
            "timeout" => 15,
            "redirection" => 0,
            "httpversion" => "1.1",
            "blocking" => true,
            "headers" => [
                "Content-Type" => "application/json",
                "Accept" => "application/json",
                "x-api-key" => $api_key,
            ],
            "body" => wp_json_encode([
                "woocommerceWebhookSecret" => $secret,
            ]),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                "ahpc_backend_registration_failed",
                sprintf(
                    /* translators: %s: WP_Error message. */
                    __(
                        "The backend rejected the secret registration request: %s",
                        self::TEXT_DOMAIN,
                    ),
                    $response->get_error_message(),
                ),
            );
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);

        if ($response_code < 200 || $response_code >= 300) {
            $response_message = $this->extract_response_message($response);
            $message = sprintf(
                /* translators: %d: HTTP response code. */
                __(
                    "The backend returned HTTP %d while storing the WooCommerce webhook secret.",
                    self::TEXT_DOMAIN,
                ),
                $response_code,
            );

            if ("" !== $response_message) {
                $message .= " " . $response_message;
            }

            return new WP_Error("ahpc_backend_registration_failed", $message);
        }

        return true;
    }

    /**
     * Extracts a short error message from a backend HTTP response.
     *
     * @param array $response HTTP response.
     * @return string
     */
    private function extract_response_message($response)
    {
        $body = trim((string) wp_remote_retrieve_body($response));

        if ("" === $body) {
            return "";
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            foreach (["message", "error", "detail"] as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    return sanitize_text_field(
                        wp_strip_all_tags($decoded[$key]),
                    );
                }
            }
        }

        return sanitize_text_field(
            wp_html_excerpt(wp_strip_all_tags($body), 200, "…"),
        );
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
                $wpdb->delete(
                    $wpdb->prefix . "wc_webhooks",
                    ["webhook_id" => $webhook_id],
                    ["%d"],
                );
            }
        }

        delete_option(self::OPTION_SETTINGS);
        delete_option(self::OPTION_WEBHOOK_IDS);
        delete_option(self::OPTION_WEBHOOK_USER_ID);
        delete_option(self::OPTION_NEEDS_SYNC);
        delete_option(self::OPTION_PLUGIN_VERSION);
        delete_option(self::OPTION_LAST_SYNC_ERROR);
        delete_option(self::OPTION_LAST_SYNC_AT);
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
                        : __("Missing", self::TEXT_DOMAIN),
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
     * Returns the desired webhook status for the current settings.
     *
     * @param array<string,mixed> $settings     Plugin settings.
     * @param string              $endpoint_url Hardcoded delivery endpoint.
     * @param WP_Error|null       $setup_error  Setup error, if any.
     * @return string
     */
    private function get_desired_status(
        $settings,
        $endpoint_url,
        $setup_error = null,
    ) {
        return !empty($settings["enabled"]) &&
            !$setup_error &&
            !empty($endpoint_url)
            ? "active"
            : "paused";
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
        $webhook_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT webhook_id FROM {$table_name} WHERE name = %s AND topic = %s ORDER BY webhook_id DESC LIMIT 1",
                $this->get_webhook_name($topic),
                $topic,
            ),
        );

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
        $found_table = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name),
        );

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
