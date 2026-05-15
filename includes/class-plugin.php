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
            !empty($args["headers"]["X-WC-Webhook-ID"]) ||
            !empty($args["headers"]["X-WC-Webhook-Topic"]);
        $is_webhook_ping =
            !empty($args["body"]) &&
            is_string($args["body"]) &&
            false !== strpos($args["body"], "webhook_id=");

        if (!$is_webhook_delivery && !$is_webhook_ping) {
            return $args;
        }

        if (!isset($args["headers"]) || !is_array($args["headers"])) {
            $args["headers"] = [];
        }

        $args["headers"]["x-api-key"] = $settings["api_key"];

        return $args;
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
        $logs_url = admin_url("admin.php?page=wc-status&tab=logs");
        $webhooks_url = admin_url(
            "admin.php?page=wc-settings&tab=advanced&section=webhooks",
        );
        $has_shop_id = "" !== $settings["shop_id"];
        $has_api_key = "" !== $settings["api_key"];
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
			<?php settings_errors(Webhook_Manager::OPTION_SETTINGS); ?>

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

			<?php if (!empty($sync_error)): ?>
				<?php $this->render_inline_notice("error", esc_html($sync_error)); ?>
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
					</tbody>
				</table>
				<?php submit_button(
        esc_html__("Save changes", Webhook_Manager::TEXT_DOMAIN),
    ); ?>
			</form>

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
