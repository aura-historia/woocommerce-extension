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
     * Returns the hardcoded delivery endpoint URL.
     *
     * @return string
     */
    public static function get_endpoint_url()
    {
        $url = defined("AHPC_WEBHOOK_ENDPOINT_URL")
            ? AHPC_WEBHOOK_ENDPOINT_URL
            : "";

        /**
         * Filters the hardcoded webhook delivery endpoint.
         *
         * @param string $url Delivery URL.
         */
        $url = apply_filters("ahpc_webhook_endpoint_url", $url);

        return esc_url_raw(trim((string) $url), ["http", "https"]);
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
            $endpoint_url = self::get_endpoint_url();
            $user_id = $this->resolve_webhook_user_id();
            $desired_status = $this->get_desired_status(
                $settings,
                $endpoint_url,
            );
            $webhook_ids = $this->get_webhook_ids();

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
            delete_option(self::OPTION_LAST_SYNC_ERROR);

            return true;
        } finally {
            $this->syncing = false;
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
     * @param array<string,mixed> $settings Plugin settings.
     * @param string              $endpoint_url Hardcoded delivery endpoint.
     * @return string
     */
    private function get_desired_status($settings, $endpoint_url)
    {
        return !empty($settings["enabled"]) && !empty($endpoint_url)
            ? "active"
            : "paused";
    }

    /**
     * Loads an existing managed webhook or creates a new one.
     *
     * @param string            $topic Webhook topic.
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
     * @param string            $topic Webhook topic.
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
     * @param int              $webhook_id Webhook ID.
     * @param WC_Webhook|null  $webhook Optional webhook instance.
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
     * @param WP_Error|string $error Error instance or message.
     * @return WP_Error
     */
    private function record_sync_error($error)
    {
        if (is_wp_error($error)) {
            $message = $error->get_error_message();
        } else {
            $message = (string) $error;
            $error = new WP_Error("ahpc_sync_failed", $message);
        }

        update_option(self::OPTION_LAST_SYNC_ERROR, $message, false);
        update_option(self::OPTION_NEEDS_SYNC, "yes", false);

        return $error;
    }
}
