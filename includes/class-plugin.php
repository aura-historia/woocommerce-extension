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
    const OAUTH_SCOPE = "products:write shops:manage";
    const OAUTH_STATE_TRANSIENT_PREFIX = "ahpc_oauth_state_";
    const OAUTH_STATE_TTL = 600;

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
        $manager->pause_webhooks();
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
            [$this, "maybe_add_webhook_api_key_header"],
            10,
            2,
        );
        add_action("woocommerce_loaded", [$this, "bootstrap_woocommerce"]);

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
        if ($this->woocommerce_bootstrapped) {
            return;
        }

        $this->woocommerce_bootstrapped = true;
        $this->manager = new Webhook_Manager();

        $this->manager->initialize_options();

        if (defined("AHPC_FORCE_SYNC_DELIVERY") && AHPC_FORCE_SYNC_DELIVERY) {
            add_filter("woocommerce_webhook_deliver_async", "__return_false");
        }

        if (class_exists(Product_Backfill::class)) {
            add_action(
                Product_Backfill::ACTION_HOOK,
                static function ($shop_id, $page) {
                    (new Product_Backfill())->process_batch($shop_id, $page);
                },
                10,
                2,
            );
        }

        add_action(
            "woocommerce_webhook_updated",
            [$this, "maybe_mark_managed_webhook_out_of_sync"],
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
            $this->manager->mark_sync_required();
        }

        $this->manager->maybe_sync_webhooks();
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
            "shop_id" => Webhook_Manager::normalize_shop_id(
                $current["shop_id"],
            ),
            "api_key" => Webhook_Manager::normalize_api_key(
                $current["api_key"],
            ),
            "secret" => !empty($current["secret"])
                ? sanitize_text_field((string) $current["secret"])
                : Webhook_Manager::generate_secret(),
        ];

        if (is_array($input)) {
            if (array_key_exists("shop_id", $input)) {
                $shop_id = Webhook_Manager::normalize_shop_id(
                    wp_unslash($input["shop_id"]),
                );

                if (
                    "" === $shop_id ||
                    Webhook_Manager::is_valid_shop_id($shop_id)
                ) {
                    $sanitized["shop_id"] = $shop_id;
                } else {
                    add_settings_error(
                        Webhook_Manager::OPTION_SETTINGS,
                        "ahpc_invalid_shop_id",
                        __(
                            "The Shop ID doesn't look right. Copy it again from Aura Historia and try once more.",
                            "aura-historia-partner-connect",
                        ),
                    );
                }
            }

            if (array_key_exists("api_key", $input)) {
                $api_key = Webhook_Manager::normalize_api_key(
                    wp_unslash($input["api_key"]),
                );

                if ("" === $api_key) {
                    $sanitized["api_key"] = "";
                } elseif (Webhook_Manager::is_valid_api_key($api_key)) {
                    $sanitized["api_key"] = $api_key;
                } else {
                    add_settings_error(
                        Webhook_Manager::OPTION_SETTINGS,
                        "ahpc_invalid_api_key",
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
     * Adds the Aura Historia access token to outgoing webhook requests.
     *
     * This intentionally uses the lower-level `http_request_args` filter instead of
     * `woocommerce_webhook_http_args` so WooCommerce's own delivery logger does not
     * capture the x-api-key value in webhook delivery logs.
     *
     * @param array  $args HTTP request arguments.
     * @param string $url  Request URL.
     * @return array
     */
    public function maybe_add_webhook_api_key_header($args, $url)
    {
        if (!$this->manager instanceof Webhook_Manager) {
            return $args;
        }

        $settings = $this->manager->get_settings();

        if (
            !Webhook_Manager::is_valid_shop_id($settings["shop_id"]) ||
            !Webhook_Manager::is_valid_api_key($settings["api_key"])
        ) {
            return $args;
        }

        $webhook_endpoint_url = Webhook_Manager::get_webhook_endpoint_url(
            $settings["shop_id"],
        );

        if (
            "" === $webhook_endpoint_url ||
            untrailingslashit($url) !== untrailingslashit($webhook_endpoint_url)
        ) {
            return $args;
        }

        $is_webhook_delivery =
            $this->has_request_header($args, "X-WC-Webhook-ID") ||
            $this->has_request_header($args, "X-WC-Webhook-Topic") ||
            $this->has_request_header($args, "X-WC-Webhook-Signature");

        if (!$is_webhook_delivery) {
            return $args;
        }

        if (!isset($args["headers"]) || !is_array($args["headers"])) {
            $args["headers"] = [];
        }

        if (!$this->has_request_header($args, "x-api-key")) {
            $args["headers"]["x-api-key"] = $settings["api_key"];
        }

        return $args;
    }

    /**
     * Returns whether the request already contains a header, using
     * case-insensitive matching.
     *
     * @param array  $args        HTTP request arguments.
     * @param string $header_name Header name to check.
     * @return bool
     */
    private function has_request_header($args, $header_name)
    {
        if (empty($args["headers"]) || !is_array($args["headers"])) {
            return false;
        }

        $normalized_target = strtolower($header_name);

        foreach ($args["headers"] as $key => $value) {
            if (
                strtolower((string) $key) === $normalized_target &&
                !empty($value)
            ) {
                return true;
            }
        }

        return false;
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
                ? $this->manager->sync_webhooks()
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

        $authorization_url = $this->create_oauth_authorization_url();

        if (is_wp_error($authorization_url)) {
            $this->store_oauth_error($authorization_url->get_error_message());

            wp_safe_redirect(
                add_query_arg("ahpc_oauth", "failed", $this->get_settings_page_url()),
            );
            exit();
        }

        wp_redirect($authorization_url, 302, "Aura Historia Partner Connect");
        exit();
    }

    /**
     * Handles the OAuth callback when Aura Historia redirects back to wp-admin.
     *
     * @return void
     */
    public function maybe_handle_oauth_callback()
    {
        if (!$this->is_settings_page_request()) {
            return;
        }

        $third_party_exchange_code = $this->get_query_param(
            "third_party_exchange_code",
        );
        $partner_shop_id = $this->get_query_param("partner_shop_id");
        $oauth_error = $this->get_query_param("error");

        if ("" === $third_party_exchange_code && "" === $oauth_error) {
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

        if ("" !== $oauth_error) {
            $result = $this->handle_oauth_error_callback(
                $oauth_error,
                $this->get_query_param("error_description"),
                $this->get_query_param("state"),
            );
        } else {
            $result = $this->complete_oauth_connection(
                $partner_shop_id,
                $third_party_exchange_code,
                $this->get_query_param("state"),
            );
        }

        if (is_wp_error($result)) {
            $this->store_oauth_error($result->get_error_message());
            $redirect_url = add_query_arg(
                "ahpc_oauth",
                "failed",
                $this->get_settings_page_url(),
            );
        } else {
            delete_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR);
            $redirect_url = add_query_arg(
                "ahpc_oauth",
                "connected",
                $this->get_settings_page_url(),
            );
        }

        wp_safe_redirect($redirect_url);
        exit();
    }

    /**
     * Builds an Aura Historia OAuth authorization URL and records CSRF state.
     *
     * @return string|WP_Error Authorization URL or error.
     */
    public function create_oauth_authorization_url()
    {
        $client_id = $this->get_oauth_client_id();
        $broker_redirect_uri = $this->get_oauth_broker_redirect_uri();
        $authorize_url = $this->get_oauth_authorize_url();
        $callback_url = $this->get_oauth_callback_url();

        if (!Webhook_Manager::is_valid_shop_id($client_id)) {
            return new WP_Error(
                "ahpc_oauth_invalid_client_id",
                __(
                    "Aura Historia OAuth is not fully configured: the OAuth client ID is invalid.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (
            "" === $broker_redirect_uri ||
            "" === $authorize_url ||
            !$this->is_valid_oauth_final_redirect_uri($broker_redirect_uri) ||
            !$this->is_valid_oauth_final_redirect_uri($authorize_url)
        ) {
            return new WP_Error(
                "ahpc_oauth_invalid_endpoint",
                __(
                    "Aura Historia OAuth is not fully configured: the authorization endpoint is invalid.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (!$this->is_valid_oauth_final_redirect_uri($callback_url)) {
            return new WP_Error(
                "ahpc_oauth_invalid_callback_url",
                __(
                    "The WordPress admin URL must use HTTPS before this store can connect to Aura Historia. HTTP is only allowed for localhost development.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $code_verifier = $this->generate_oauth_code_verifier();
        $client_state = $this->generate_oauth_state();
        $broker_state = [
            "redirect_uri" => $callback_url,
            "code_verifier" => $code_verifier,
            "client_state" => $client_state,
        ];
        $encoded_state = $this->base64url_encode(wp_json_encode($broker_state));

        set_transient(
            $this->get_oauth_state_transient_name($client_state),
            [
                "user_id" => get_current_user_id(),
                "redirect_uri" => $callback_url,
                "created" => time(),
            ],
            self::OAUTH_STATE_TTL,
        );

        return add_query_arg(
            [
                "client_id" => $client_id,
                "redirect_uri" => $broker_redirect_uri,
                "code_challenge" => $this->create_oauth_code_challenge(
                    $code_verifier,
                ),
                "response_type" => "code",
                "code_challenge_method" => "S256",
                "scope" => self::OAUTH_SCOPE,
                "requires_partner_shop_id" => "true",
                "state" => $encoded_state,
            ],
            $authorize_url,
        );
    }

    /**
     * Completes the OAuth callback by exchanging the broker code and storing
     * connection settings locally.
     *
     * @param string $partner_shop_id            Partner shop UUID returned by Aura Historia.
     * @param string $third_party_exchange_code  Short-lived one-time exchange code.
     * @param string $client_state               CSRF state forwarded by the broker.
     * @return true|WP_Error
     */
    public function complete_oauth_connection(
        $partner_shop_id,
        $third_party_exchange_code,
        $client_state,
    ) {
        if (!current_user_can("manage_woocommerce")) {
            return new WP_Error(
                "ahpc_oauth_forbidden",
                __(
                    "You are not allowed to connect this store to Aura Historia.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $state_result = $this->consume_oauth_state($client_state);

        if (is_wp_error($state_result)) {
            return $state_result;
        }

        $shop_id = Webhook_Manager::normalize_shop_id($partner_shop_id);
        $exchange_code = Webhook_Manager::normalize_shop_id(
            $third_party_exchange_code,
        );

        if (!Webhook_Manager::is_valid_shop_id($shop_id)) {
            return new WP_Error(
                "ahpc_oauth_invalid_shop_id",
                __(
                    "Aura Historia did not return a valid partner Shop ID.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (!Webhook_Manager::is_valid_shop_id($exchange_code)) {
            return new WP_Error(
                "ahpc_oauth_invalid_exchange_code",
                __(
                    "Aura Historia did not return a valid OAuth exchange code.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if ("" === Webhook_Manager::get_backend_base_url()) {
            return new WP_Error(
                "ahpc_oauth_missing_backend_base_url",
                __(
                    "Aura Historia is not fully configured inside the plugin. Define AHPC_BACKEND_BASE_URL before connecting a store.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $client = new Backend_Api_Client(Webhook_Manager::get_backend_base_url());
        $token_response = $client->oauth_token_by_third_party_code(
            $exchange_code,
        );

        if (is_wp_error($token_response)) {
            return new WP_Error(
                "ahpc_oauth_exchange_failed",
                sprintf(
                    /* translators: %s: backend error detail. */
                    __(
                        "Aura Historia could not complete the OAuth token exchange: %s",
                        "aura-historia-partner-connect",
                    ),
                    $token_response->get_error_message(),
                ),
            );
        }

        $access_token = Webhook_Manager::normalize_api_key(
            $token_response->getAccessToken(),
        );
        $token_type = strtoupper(
            sanitize_text_field((string) $token_response->getTokenType()),
        );
        $scope = sanitize_text_field((string) $token_response->getScope());

        if ("BEARER" !== $token_type) {
            return new WP_Error(
                "ahpc_oauth_invalid_token_type",
                __(
                    "Aura Historia returned an unsupported OAuth token type for this store.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (!Webhook_Manager::is_valid_api_key($access_token)) {
            return new WP_Error(
                "ahpc_oauth_invalid_access_token",
                __(
                    "Aura Historia returned an invalid access token for this store.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        if (!$this->has_required_oauth_scopes($scope)) {
            return new WP_Error(
                "ahpc_oauth_missing_scope",
                __(
                    "Aura Historia did not grant the permissions required to manage this shop and sync products.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $settings = $this->get_current_settings();
        $settings["shop_id"] = $shop_id;
        $settings["api_key"] = $access_token;

        if (empty($settings["secret"])) {
            $settings["secret"] = Webhook_Manager::generate_secret();
        }

        update_option(Webhook_Manager::OPTION_SETTINGS, $settings, false);
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes", false);
        delete_option(Webhook_Manager::OPTION_LAST_SYNC_ERROR);
        delete_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR);

        if ($this->is_woocommerce_available()) {
            $was_bootstrapped = $this->woocommerce_bootstrapped;
            $this->bootstrap_woocommerce();

            if ($was_bootstrapped && $this->manager instanceof Webhook_Manager) {
                $sync_result = $this->manager->sync_webhooks();

                if (is_wp_error($sync_result)) {
                    return $sync_result;
                }
            } elseif ($this->manager instanceof Webhook_Manager) {
                $sync_error = $this->manager->get_last_sync_error();

                if ("" !== $sync_error) {
                    return new WP_Error("ahpc_oauth_sync_failed", $sync_error);
                }
            }
        }

        return true;
    }

    /**
     * Handles an OAuth authorization error callback.
     *
     * @param string $error             OAuth error code.
     * @param string $error_description Optional OAuth error description.
     * @param string $client_state      CSRF state forwarded by the broker.
     * @return WP_Error
     */
    private function handle_oauth_error_callback(
        $error,
        $error_description,
        $client_state,
    ) {
        $state_result = $this->consume_oauth_state($client_state);

        if (is_wp_error($state_result)) {
            return $state_result;
        }

        $message = sanitize_text_field($error_description);

        if ("" === $message) {
            $message = sanitize_text_field($error);
        }

        if ("" === $message) {
            $message = __(
                "Aura Historia did not authorize the connection.",
                "aura-historia-partner-connect",
            );
        }

        return new WP_Error("ahpc_oauth_authorization_failed", $message);
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
                "queued",
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
     * Queues a fresh full product backfill using the currently saved settings.
     *
     * @return true|WP_Error
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
            !Webhook_Manager::is_valid_shop_id($settings["shop_id"]) ||
            !Webhook_Manager::is_valid_api_key($settings["api_key"])
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

        $sync_result = $this->manager->sync_webhooks();

        if (is_wp_error($sync_result)) {
            return $sync_result;
        }

        if (!(new Product_Backfill())->schedule_backfill($settings["shop_id"])) {
            return new WP_Error(
                "ahpc_backfill_failed",
                __(
                    "The product backfill could not be queued. Check the backfill status below and the WooCommerce Action Scheduler screen for more detail.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        return true;
    }

    /**
     * Marks the plugin webhooks as out of sync after a manual edit or deletion.
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

        $settings = $this->get_current_settings();
        $backend_base_url = Webhook_Manager::get_backend_base_url();
        $webhook_endpoint_url = Webhook_Manager::get_webhook_endpoint_url(
            $settings["shop_id"],
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
        $logs_url = admin_url("admin.php?page=wc-status&tab=logs");
        $webhooks_url = admin_url(
            "admin.php?page=wc-settings&tab=advanced&section=webhooks",
        );
        $is_connected =
            Webhook_Manager::is_valid_shop_id($settings["shop_id"]) &&
            Webhook_Manager::is_valid_api_key($settings["api_key"]);
        $should_auto_start_oauth =
            $this->is_woocommerce_available() &&
            !$is_connected &&
            "failed" !== $oauth_status &&
            "" === $oauth_error &&
            !empty($backend_base_url);
        $connection_status = $this->get_connection_status(
            $settings,
            $sync_error,
            $last_sync_at,
        );
        $backfill_status = $this->get_backfill_status($settings);
        $hide_default_updated_notice = false;
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
			<?php $this->render_setting_messages($hide_default_updated_notice); ?>

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
            "Managed WooCommerce webhooks synced successfully.",
            "aura-historia-partner-connect",
        ),
    ); ?>
			<?php endif; ?>

			<?php if ("queued" === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "success",
        esc_html__(
            "A fresh product backfill was queued. Existing products will be re-sent in the background.",
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
            "Aura Historia connection completed and managed webhooks synced.",
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
			<?php elseif (!$is_connected): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Connect with Aura Historia to authorize this WooCommerce store. Product updates start automatically after the OAuth connection completes.",
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
        "The plugin connects through Aura Historia OAuth. It stores the returned Shop ID and access token locally, but never displays the access token in wp-admin.",
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
						try {
							if (window.sessionStorage && window.sessionStorage.getItem(storageKey)) {
								return;
							}
							if (window.sessionStorage) {
								window.sessionStorage.setItem(storageKey, "1");
							}
						} catch (error) {}

						var form = document.getElementById("ahpc-oauth-start-form");
						if (form) {
							form.submit();
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
          "Aura Historia Shop ID",
          "aura-historia-partner-connect",
      ); ?></th>
						<td>
							<?php if (Webhook_Manager::is_valid_shop_id($settings["shop_id"])): ?>
								<code><?php echo esc_html($settings["shop_id"]); ?></code>
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
            "The token is saved locally for webhook delivery and product backfill requests, but it is hidden from administrators after OAuth completes.",
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

			<h2><?php echo esc_html__(
       "Existing product backfill",
       "aura-historia-partner-connect",
   ); ?></h2>
			<p><?php echo esc_html__(
       "Use this if the initial product backfill did not start, was interrupted, or you want to re-send the entire current catalog. It queues a fresh background backfill for the connected Aura Historia shop and replaces any pending backfill batches.",
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
        "The backfill runs in the background via Action Scheduler. On large catalogs it may take some time, and queueing it again restarts the pending backfill from the beginning.",
        "aura-historia-partner-connect",
    ); ?></p>
			<?php endif; ?>

			<h2><?php echo esc_html__(
       "Managed webhooks",
       "aura-historia-partner-connect",
   ); ?></h2>
			<p>
				<?php
    echo esc_html__(
        "The plugin owns exactly three WooCommerce webhooks and keeps them in sync with the built-in backend endpoint pattern and the OAuth connection above.",
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
     * @param bool $hide_updated_notice Whether the generic updated notice should be hidden.
     * @return void
     */
    private function render_setting_messages($hide_updated_notice = false)
    {
        if (!function_exists("get_settings_errors")) {
            return;
        }

        foreach (
            get_settings_errors(Webhook_Manager::OPTION_SETTINGS)
            as $error
        ) {
            $code = isset($error["code"]) ? (string) $error["code"] : "";
            $type = isset($error["type"]) ? (string) $error["type"] : "error";
            $message = isset($error["message"])
                ? (string) $error["message"]
                : "";

            if ($hide_updated_notice && "settings_updated" === $code) {
                continue;
            }

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
            !Webhook_Manager::is_valid_shop_id($settings["shop_id"]) ||
            !Webhook_Manager::is_valid_api_key($settings["api_key"])
        ) {
            return [
                "type" => "warning",
                "label" => __("Not verified", "aura-historia-partner-connect"),
                "message" => __(
                    "Connect with Aura Historia to verify this store and receive an access token.",
                    "aura-historia-partner-connect",
                ),
            ];
        }

        if ("yes" === get_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes")) {
            return [
                "type" => "warning",
                "label" => __("Sync pending", "aura-historia-partner-connect"),
                "message" => __(
                    "The OAuth connection is waiting for the next webhook sync before Aura Historia can be verified.",
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
                        "Aura Historia did not accept the saved OAuth connection: %s",
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
                    "Aura Historia accepted the stored Shop ID and access token during the most recent webhook sync.",
                    "aura-historia-partner-connect",
                ),
            ];
        }

        return [
            "type" => "warning",
            "label" => __("Not verified", "aura-historia-partner-connect"),
            "message" => __(
                "Run a webhook sync to verify the stored Aura Historia OAuth connection.",
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
                            'The most recent product backfill completed successfully at %1$s using Action Scheduler hook "%2$s".',
                            "aura-historia-partner-connect",
                        ),
                        $completed_at,
                        $hook,
                    )
                    : sprintf(
                        /* translators: %s: Action Scheduler hook name. */
                        __(
                            'The most recent product backfill completed successfully using Action Scheduler hook "%s".',
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
                sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Action Scheduler hook "%s" will retry the batch if another attempt is allowed.',
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
                "label" => __("Failed", "aura-historia-partner-connect"),
                "message" => $message,
            ];
        }

        if (
            !Webhook_Manager::is_valid_shop_id($settings["shop_id"]) ||
            !Webhook_Manager::is_valid_api_key($settings["api_key"])
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
            !isset($_GET[$key]) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice query parameter.
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

        if (empty($settings["secret"])) {
            $settings["secret"] = Webhook_Manager::generate_secret();
        }

        $settings["shop_id"] = Webhook_Manager::normalize_shop_id(
            $settings["shop_id"],
        );
        $settings["api_key"] = Webhook_Manager::normalize_api_key(
            $settings["api_key"],
        );
        $settings["secret"] = sanitize_text_field((string) $settings["secret"]);

        return $settings;
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
         * @param string $client_id OAuth client UUID.
         */
        $client_id = apply_filters("ahpc_oauth_client_id", $client_id);

        return Webhook_Manager::normalize_shop_id($client_id);
    }

    /**
     * Returns the configured OAuth broker redirect URI.
     *
     * @return string
     */
    public function get_oauth_broker_redirect_uri()
    {
        $redirect_uri = defined("AHPC_OAUTH_BROKER_REDIRECT_URI")
            ? AHPC_OAUTH_BROKER_REDIRECT_URI
            : "";

        /**
         * Filters the OAuth broker redirect URI used for Aura Historia connection.
         *
         * @param string $redirect_uri Broker redirect URI.
         */
        $redirect_uri = apply_filters(
            "ahpc_oauth_broker_redirect_uri",
            $redirect_uri,
        );
        $redirect_uri = esc_url_raw(trim((string) $redirect_uri), [
            "http",
            "https",
        ]);

        return $redirect_uri ? $redirect_uri : "";
    }

    /**
     * Returns the Aura Historia authorization endpoint URL.
     *
     * @return string
     */
    public function get_oauth_authorize_url()
    {
        $broker_redirect_uri = $this->get_oauth_broker_redirect_uri();
        $parts = $broker_redirect_uri ? wp_parse_url($broker_redirect_uri) : [];
        $authorize_url = "https://aura-historia.com/oauth/authorize";

        if (
            is_array($parts) &&
            !empty($parts["scheme"]) &&
            !empty($parts["host"])
        ) {
            $authorize_url =
                strtolower((string) $parts["scheme"]) .
                "://" .
                strtolower((string) $parts["host"]);

            if (!empty($parts["port"])) {
                $authorize_url .= ":" . absint($parts["port"]);
            }

            $authorize_url .= "/oauth/authorize";
        }

        /**
         * Filters the OAuth authorization endpoint URL used for Aura Historia connection.
         *
         * @param string $authorize_url Authorization endpoint URL.
         */
        $authorize_url = apply_filters(
            "ahpc_oauth_authorize_url",
            $authorize_url,
        );
        $authorize_url = esc_url_raw(trim((string) $authorize_url), [
            "http",
            "https",
        ]);

        return $authorize_url ? $authorize_url : "";
    }

    /**
     * Returns the wp-admin callback URL used as the final OAuth redirect URI.
     *
     * @return string
     */
    public function get_oauth_callback_url()
    {
        return $this->get_settings_page_url();
    }

    /**
     * Returns whether the current request targets the plugin settings page.
     *
     * @return bool
     */
    private function is_settings_page_request()
    {
        return self::PAGE_SLUG === $this->get_query_param("page");
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
     * Consumes and validates a one-time OAuth CSRF state value.
     *
     * @param string $client_state State value forwarded by the broker.
     * @return true|WP_Error
     */
    private function consume_oauth_state($client_state)
    {
        $client_state = sanitize_text_field((string) $client_state);

        if (
            "" === $client_state ||
            1 !== preg_match('/\A[A-Za-z0-9_-]{32,}\z/', $client_state)
        ) {
            return new WP_Error(
                "ahpc_oauth_invalid_state",
                __(
                    "The Aura Historia connection callback did not include a valid security state. Start the connection again from this settings page.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $transient_name = $this->get_oauth_state_transient_name($client_state);
        $state = get_transient($transient_name);
        delete_transient($transient_name);

        if (!is_array($state)) {
            return new WP_Error(
                "ahpc_oauth_expired_state",
                __(
                    "The Aura Historia connection callback has expired. Start the connection again from this settings page.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        $expected_user_id = isset($state["user_id"])
            ? absint($state["user_id"])
            : 0;

        if ($expected_user_id && get_current_user_id() !== $expected_user_id) {
            return new WP_Error(
                "ahpc_oauth_user_mismatch",
                __(
                    "The Aura Historia connection was started by a different administrator. Start the connection again from this account.",
                    "aura-historia-partner-connect",
                ),
            );
        }

        return true;
    }

    /**
     * Returns the transient name used for one OAuth state value.
     *
     * @param string $client_state OAuth client state.
     * @return string
     */
    private function get_oauth_state_transient_name($client_state)
    {
        return self::OAUTH_STATE_TRANSIENT_PREFIX .
            hash("sha256", (string) $client_state);
    }

    /**
     * Returns whether a final OAuth redirect URI is safe for the broker.
     *
     * @param string $redirect_uri Final redirect URI.
     * @return bool
     */
    private function is_valid_oauth_final_redirect_uri($redirect_uri)
    {
        $parts = wp_parse_url($redirect_uri);

        if (
            !is_array($parts) ||
            empty($parts["scheme"]) ||
            empty($parts["host"]) ||
            !empty($parts["user"]) ||
            !empty($parts["pass"])
        ) {
            return false;
        }

        $scheme = strtolower((string) $parts["scheme"]);
        $host = strtolower(trim((string) $parts["host"], "[]"));

        if ("https" === $scheme) {
            return true;
        }

        if ("http" !== $scheme) {
            return false;
        }

        return in_array($host, ["localhost", "127.0.0.1", "::1"], true);
    }

    /**
     * Generates an OAuth PKCE verifier.
     *
     * @return string
     */
    private function generate_oauth_code_verifier()
    {
        try {
            return $this->base64url_encode(random_bytes(64));
        } catch (\Exception $exception) {
            unset($exception);
            return wp_generate_password(64, false, false);
        }
    }

    /**
     * Generates an OAuth client-side CSRF state value.
     *
     * @return string
     */
    private function generate_oauth_state()
    {
        try {
            return $this->base64url_encode(random_bytes(32));
        } catch (\Exception $exception) {
            unset($exception);
            return wp_generate_password(48, false, false);
        }
    }

    /**
     * Creates an S256 PKCE challenge for a verifier.
     *
     * @param string $code_verifier PKCE code verifier.
     * @return string
     */
    private function create_oauth_code_challenge($code_verifier)
    {
        return $this->base64url_encode(
            hash("sha256", (string) $code_verifier, true),
        );
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
     * Returns whether an OAuth token scope string grants all plugin requirements.
     *
     * @param string $scope OAuth scope string.
     * @return bool
     */
    private function has_required_oauth_scopes($scope)
    {
        $granted_scopes = preg_split('/\s+/', trim((string) $scope));

        if (!is_array($granted_scopes)) {
            $granted_scopes = [];
        }

        return in_array("products:write", $granted_scopes, true) &&
            in_array("shops:manage", $granted_scopes, true);
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
