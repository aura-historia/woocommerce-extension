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

        load_plugin_textdomain(
            Webhook_Manager::TEXT_DOMAIN,
            false,
            dirname(AHPC_PLUGIN_BASENAME) . "/languages",
        );

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
            add_action("admin_post_ahpc_sync_webhooks", [
                $this,
                "handle_sync_request",
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
                Webhook_Manager::TEXT_DOMAIN,
            ),
            esc_html__("Aura Historia", Webhook_Manager::TEXT_DOMAIN),
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
                            Webhook_Manager::TEXT_DOMAIN,
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
                            "The API key doesn't look right. Copy it again from Aura Historia and try once more.",
                            Webhook_Manager::TEXT_DOMAIN,
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
     * Adds the backend API key to outgoing webhook requests.
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

        $args["headers"]["x-api-key"] = $settings["api_key"];

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
                    Webhook_Manager::TEXT_DOMAIN,
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
                        Webhook_Manager::TEXT_DOMAIN,
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
                    Webhook_Manager::TEXT_DOMAIN,
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
                    Webhook_Manager::TEXT_DOMAIN,
                ),
            );
        }

        if (!class_exists(Product_Backfill::class)) {
            return new WP_Error(
                "ahpc_backfill_unavailable",
                __(
                    "The product backfill component is not available right now.",
                    Webhook_Manager::TEXT_DOMAIN,
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
                    "Save a valid Shop ID and API key before queueing a full product backfill.",
                    Webhook_Manager::TEXT_DOMAIN,
                ),
            );
        }

        if (!$this->manager instanceof Webhook_Manager) {
            return new WP_Error(
                "ahpc_backfill_unavailable",
                __(
                    "The webhook manager could not be initialized.",
                    Webhook_Manager::TEXT_DOMAIN,
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
                    Webhook_Manager::TEXT_DOMAIN,
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
                Webhook_Manager::TEXT_DOMAIN,
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
                esc_html__("Settings", Webhook_Manager::TEXT_DOMAIN),
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
        $sync_success =
            isset($_GET["ahpc_synced"]) &&
            "1" === wp_unslash($_GET["ahpc_synced"]);
        $backfill_request_status = isset($_GET["ahpc_backfill"])
            ? sanitize_key(wp_unslash($_GET["ahpc_backfill"]))
            : "";
        $settings_updated =
            isset($_GET["settings-updated"]) &&
            "true" === wp_unslash($_GET["settings-updated"]);
        $logs_url = admin_url("admin.php?page=wc-status&tab=logs");
        $webhooks_url = admin_url(
            "admin.php?page=wc-settings&tab=advanced&section=webhooks",
        );
        $has_shop_id = "" !== $settings["shop_id"];
        $has_api_key = "" !== $settings["api_key"];
        $connection_status = $this->get_connection_status($settings);
        $backfill_status = $this->get_backfill_status($settings);
        $hide_default_updated_notice =
            $settings_updated &&
            empty($sync_error) &&
            "success" === $connection_status["type"];
        ?>
		<div class="wrap">
			<h1><?php echo esc_html__(
       "Aura Historia Partner Connect",
       Webhook_Manager::TEXT_DOMAIN,
   ); ?></h1>
			<p><?php echo esc_html__(
       "Connect this WooCommerce store to Aura Historia so your products can appear there and stay up to date automatically.",
       Webhook_Manager::TEXT_DOMAIN,
   ); ?></p>
			<?php $this->render_setting_messages($hide_default_updated_notice); ?>

			<?php if (!$this->is_woocommerce_available()): ?>
				<?php $this->render_inline_notice(
        "error",
        esc_html__(
            "WooCommerce is not active, so the managed webhooks cannot be created yet.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif ($sync_success): ?>
				<?php $this->render_inline_notice(
        "success",
        esc_html__(
            "Managed WooCommerce webhooks synced successfully.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php endif; ?>

			<?php if ("queued" === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "success",
        esc_html__(
            "A fresh product backfill was queued. Existing products will be re-sent in the background.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif ("invalid" === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Save a valid Shop ID and API key before queueing a full product backfill.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif ("unavailable" === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "error",
        esc_html__(
            "The product backfill could not be queued because WooCommerce or Action Scheduler is not available yet.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif ("failed" === $backfill_request_status): ?>
				<?php $this->render_inline_notice(
        "error",
        esc_html__(
            "The product backfill could not be queued. Check the backfill status below for more detail.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php endif; ?>

			<?php if (!empty($sync_error)): ?>
				<?php $this->render_inline_notice("error", esc_html($sync_error)); ?>
			<?php elseif ($settings_updated && "success" === $connection_status["type"]): ?>
				<?php $this->render_inline_notice(
        "success",
        esc_html__(
            "Configuration saved and Aura Historia connection verified.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif (empty($backend_base_url)): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Aura Historia is not fully configured inside the plugin yet. Define AHPC_BACKEND_BASE_URL before connecting a store.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif (!$has_shop_id && !$has_api_key): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Add your Shop ID and API key from Aura Historia to connect this store. Product updates start automatically after both are saved.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif (!$has_shop_id): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Add the Shop ID from Aura Historia to finish connecting this store.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif (!Webhook_Manager::is_valid_shop_id($settings["shop_id"])): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "The Shop ID doesn't look right. Copy it again from Aura Historia and try once more.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif (!$has_api_key): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Add the API key from Aura Historia to finish connecting this store.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif (!Webhook_Manager::is_valid_api_key($settings["api_key"])): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "The API key doesn't look right. Copy it again from Aura Historia and try once more.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif (empty($webhook_endpoint_url)): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "The webhook delivery URL could not be built from the current settings.",
            Webhook_Manager::TEXT_DOMAIN,
        ),
    ); ?>
			<?php elseif ("error" === $connection_status["type"]): ?>
				<?php $this->render_inline_notice(
        "error",
        esc_html($connection_status["message"]),
    ); ?>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php settings_fields(Webhook_Manager::SETTINGS_GROUP); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="ahpc-shop-id"><?php echo esc_html__(
            "Shop ID",
            Webhook_Manager::TEXT_DOMAIN,
        ); ?></label>
							</th>
							<td>
								<input id="ahpc-shop-id" name="<?php echo esc_attr(
            Webhook_Manager::OPTION_SETTINGS,
        ); ?>[shop_id]" type="text" class="regular-text" value="<?php echo esc_attr(
    $settings["shop_id"],
); ?>" spellcheck="false" />
								<p class="description"><?php echo esc_html__(
            "Paste the Shop ID from Aura Historia for this store. It tells Aura Historia where your WooCommerce products should appear.",
            Webhook_Manager::TEXT_DOMAIN,
        ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ahpc-api-key"><?php echo esc_html__(
            "API key",
            Webhook_Manager::TEXT_DOMAIN,
        ); ?></label>
							</th>
							<td>
								<input id="ahpc-api-key" name="<?php echo esc_attr(
            Webhook_Manager::OPTION_SETTINGS,
        ); ?>[api_key]" type="text" class="regular-text" value="<?php echo esc_attr(
    $settings["api_key"],
); ?>" autocomplete="off" spellcheck="false" />
								<p class="description"><?php echo esc_html__(
            "Paste the API key from Aura Historia for this store. It lets Aura Historia securely receive product updates from your shop.",
            Webhook_Manager::TEXT_DOMAIN,
        ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__(
           "Connection status",
           Webhook_Manager::TEXT_DOMAIN,
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
           "Product backfill",
           Webhook_Manager::TEXT_DOMAIN,
       ); ?></th>
							<td>
								<strong><?php echo esc_html($backfill_status["label"]); ?></strong>
								<p class="description"><?php echo esc_html($backfill_status["message"]); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(
        esc_html__("Save changes", Webhook_Manager::TEXT_DOMAIN),
    ); ?>
			</form>

			<h2><?php echo esc_html__(
       "Existing product backfill",
       Webhook_Manager::TEXT_DOMAIN,
   ); ?></h2>
			<p><?php echo esc_html__(
       "Use this if the initial product backfill did not start, was interrupted, or you want to re-send the entire current catalog. It queues a fresh background backfill for the saved Shop ID and replaces any pending backfill batches.",
       Webhook_Manager::TEXT_DOMAIN,
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
             Webhook_Manager::TEXT_DOMAIN,
         ),
         "secondary",
         "submit",
         false,
     ); ?>
				</form>
				<p class="description"><?php echo esc_html__(
        "The backfill runs in the background via Action Scheduler. On large catalogs it may take some time, and queueing it again restarts the pending backfill from the beginning.",
        Webhook_Manager::TEXT_DOMAIN,
    ); ?></p>
			<?php endif; ?>

			<h2><?php echo esc_html__(
       "Managed webhooks",
       Webhook_Manager::TEXT_DOMAIN,
   ); ?></h2>
			<p>
				<?php
    echo esc_html__(
        "The plugin owns exactly three WooCommerce webhooks and keeps them in sync with the built-in backend endpoint pattern and the settings above.",
        Webhook_Manager::TEXT_DOMAIN,
    );
    if ($last_sync_at) {
        echo " " .
            sprintf(
                /* translators: %s: formatted sync time. */
                esc_html__("Last sync: %s.", Webhook_Manager::TEXT_DOMAIN),
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
							<th><?php echo esc_html__("Topic", Webhook_Manager::TEXT_DOMAIN); ?></th>
							<th><?php echo esc_html__("Webhook ID", Webhook_Manager::TEXT_DOMAIN); ?></th>
							<th><?php echo esc_html__("Status", Webhook_Manager::TEXT_DOMAIN); ?></th>
							<th><?php echo esc_html__("Delivery URL", Webhook_Manager::TEXT_DOMAIN); ?></th>
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
        Webhook_Manager::TEXT_DOMAIN,
    ); ?></p>
			<?php endif; ?>

			<p>
				<a class="button button-secondary" href="<?php echo esc_url(
        $webhooks_url,
    ); ?>"><?php echo esc_html__(
    "Open WooCommerce webhooks",
    Webhook_Manager::TEXT_DOMAIN,
); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url(
        $logs_url,
    ); ?>"><?php echo esc_html__(
    "Open webhook delivery logs",
    Webhook_Manager::TEXT_DOMAIN,
); ?></a>
			</p>

			<?php if ($this->is_woocommerce_available()): ?>
				<form method="post" action="<?php echo esc_url(
        admin_url("admin-post.php"),
    ); ?>">
					<input type="hidden" name="action" value="ahpc_sync_webhooks" />
					<?php wp_nonce_field("ahpc_sync_webhooks"); ?>
					<?php submit_button(
         esc_html__("Sync webhooks now", Webhook_Manager::TEXT_DOMAIN),
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
     * @param array<string,mixed> $settings Current plugin settings.
     * @return array<string,string>
     */
    private function get_connection_status($settings)
    {
        if ("" === Webhook_Manager::get_backend_base_url()) {
            return [
                "type" => "warning",
                "label" => __("Not configured", Webhook_Manager::TEXT_DOMAIN),
                "message" => __(
                    "Define AHPC_BACKEND_BASE_URL before verifying the Aura Historia connection.",
                    Webhook_Manager::TEXT_DOMAIN,
                ),
            ];
        }

        if (
            !Webhook_Manager::is_valid_shop_id($settings["shop_id"]) ||
            !Webhook_Manager::is_valid_api_key($settings["api_key"])
        ) {
            return [
                "type" => "warning",
                "label" => __("Not verified", Webhook_Manager::TEXT_DOMAIN),
                "message" => __(
                    "Save a valid Shop ID and API key to verify the Aura Historia connection.",
                    Webhook_Manager::TEXT_DOMAIN,
                ),
            ];
        }

        $client = new Backend_Api_Client(
            Webhook_Manager::get_backend_base_url(),
        );
        $result = $client->verify_shop_connection(
            $settings["shop_id"],
            $settings["api_key"],
        );

        if (is_wp_error($result)) {
            return [
                "type" => "error",
                "label" => __("Check failed", Webhook_Manager::TEXT_DOMAIN),
                "message" => sprintf(
                    /* translators: %s: connection check error detail. */
                    __(
                        "Aura Historia did not accept the saved Shop ID and API key: %s",
                        Webhook_Manager::TEXT_DOMAIN,
                    ),
                    $result->get_error_message(),
                ),
            ];
        }

        return [
            "type" => "success",
            "label" => __("Connected", Webhook_Manager::TEXT_DOMAIN),
            "message" => __(
                "Aura Historia is responding and the saved Shop ID and API key are still valid.",
                Webhook_Manager::TEXT_DOMAIN,
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
                "label" => __("Unavailable", Webhook_Manager::TEXT_DOMAIN),
                "message" => __(
                    "The product backfill component is not loaded right now.",
                    Webhook_Manager::TEXT_DOMAIN,
                ),
            ];
        }

        if (!function_exists("as_schedule_single_action")) {
            return [
                "label" => __("Unavailable", Webhook_Manager::TEXT_DOMAIN),
                "message" => sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Action Scheduler is not available, so the product backfill action "%s" cannot be queued.',
                        Webhook_Manager::TEXT_DOMAIN,
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
                        Webhook_Manager::TEXT_DOMAIN,
                    ),
                    $hook,
                    $scheduled_at,
                )
                : sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Action Scheduler hook "%s" is queued and waiting for Action Scheduler to finish initializing.',
                        Webhook_Manager::TEXT_DOMAIN,
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
                            Webhook_Manager::TEXT_DOMAIN,
                        ),
                        $completed_at,
                    );
            }

            return [
                "label" => __("Queued", Webhook_Manager::TEXT_DOMAIN),
                "message" => $message,
            ];
        }

        if (Product_Backfill::STATUS_RUNNING === $status) {
            $message = $started_at
                ? sprintf(
                    /* translators: 1: Action Scheduler hook name, 2: formatted time. */
                    __(
                        'Action Scheduler hook "%1$s" started processing at %2$s.',
                        Webhook_Manager::TEXT_DOMAIN,
                    ),
                    $hook,
                    $started_at,
                )
                : sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Action Scheduler hook "%s" is currently processing existing products.',
                        Webhook_Manager::TEXT_DOMAIN,
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
                            Webhook_Manager::TEXT_DOMAIN,
                        ),
                        $completed_at,
                    );
            }

            return [
                "label" => __("Running", Webhook_Manager::TEXT_DOMAIN),
                "message" => $message,
            ];
        }

        if (Product_Backfill::STATUS_COMPLETE === $status) {
            return [
                "label" => __("Completed", Webhook_Manager::TEXT_DOMAIN),
                "message" => $completed_at
                    ? sprintf(
                        /* translators: 1: formatted time, 2: Action Scheduler hook name. */
                        __(
                            'The most recent product backfill completed successfully at %1$s using Action Scheduler hook "%2$s".',
                            Webhook_Manager::TEXT_DOMAIN,
                        ),
                        $completed_at,
                        $hook,
                    )
                    : sprintf(
                        /* translators: %s: Action Scheduler hook name. */
                        __(
                            'The most recent product backfill completed successfully using Action Scheduler hook "%s".',
                            Webhook_Manager::TEXT_DOMAIN,
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
                        Webhook_Manager::TEXT_DOMAIN,
                    ),
                    $failed_at,
                    $last_error,
                );
            } elseif ($last_error) {
                $message = sprintf(
                    /* translators: %s: error detail. */
                    __(
                        "The most recent product backfill batch failed: %s",
                        Webhook_Manager::TEXT_DOMAIN,
                    ),
                    $last_error,
                );
            } else {
                $message = __(
                    "The most recent product backfill batch failed.",
                    Webhook_Manager::TEXT_DOMAIN,
                );
            }

            $message .=
                " " .
                sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Action Scheduler hook "%s" will retry the batch if another attempt is allowed.',
                        Webhook_Manager::TEXT_DOMAIN,
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
                            Webhook_Manager::TEXT_DOMAIN,
                        ),
                        $completed_at,
                    );
            }

            return [
                "label" => __("Failed", Webhook_Manager::TEXT_DOMAIN),
                "message" => $message,
            ];
        }

        if (
            !Webhook_Manager::is_valid_shop_id($settings["shop_id"]) ||
            !Webhook_Manager::is_valid_api_key($settings["api_key"])
        ) {
            return [
                "label" => __("Not queued", Webhook_Manager::TEXT_DOMAIN),
                "message" => sprintf(
                    /* translators: %s: Action Scheduler hook name. */
                    __(
                        'Save a valid Shop ID and API key to queue the product backfill action "%s".',
                        Webhook_Manager::TEXT_DOMAIN,
                    ),
                    $hook,
                ),
            ];
        }

        $message = sprintf(
            /* translators: %s: Action Scheduler hook name. */
            __(
                'No product backfill batch is currently queued. The plugin uses Action Scheduler hook "%s" to backfill existing products after a successful sync.',
                Webhook_Manager::TEXT_DOMAIN,
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
                        Webhook_Manager::TEXT_DOMAIN,
                    ),
                    $completed_at,
                );
        }

        return [
            "label" => __("Not queued", Webhook_Manager::TEXT_DOMAIN),
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
