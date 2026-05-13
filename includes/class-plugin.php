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
            esc_html__("Partner Connect", Webhook_Manager::TEXT_DOMAIN),
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
            "endpoint_url" => "",
            "secret" => !empty($current["secret"])
                ? sanitize_text_field((string) $current["secret"])
                : Webhook_Manager::generate_secret(),
            "enabled" => false,
        ];

        if (is_array($input)) {
            if (isset($input["endpoint_url"])) {
                $sanitized["endpoint_url"] = esc_url_raw(
                    trim(wp_unslash($input["endpoint_url"])),
                    ["http", "https"],
                );
            }

            if (isset($input["secret"])) {
                $sanitized["secret"] = sanitize_text_field(
                    wp_unslash($input["secret"]),
                );
            }

            $sanitized["enabled"] = !empty($input["enabled"]);
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
     * @param int        $webhook_id Webhook ID.
     * @param WC_Webhook $webhook Optional webhook instance.
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
        ?>
		<div class="wrap">
			<h1><?php echo esc_html__(
       "Aura Historia Partner Connect",
       Webhook_Manager::TEXT_DOMAIN,
   ); ?></h1>
			<p><?php echo esc_html__(
       "This plugin manages three WooCommerce webhooks for product.created, product.updated, and product.deleted.",
       Webhook_Manager::TEXT_DOMAIN,
   ); ?></p>

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
			<?php elseif (
       empty($settings["enabled"]) ||
       empty($settings["endpoint_url"])
   ): ?>
				<?php $this->render_inline_notice(
        "warning",
        esc_html__(
            "Webhook delivery is currently paused. Add a delivery URL and enable delivery when you are ready to send events to your SaaS backend.",
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
								<label for="ahpc-endpoint-url"><?php echo esc_html__(
            "Delivery URL",
            Webhook_Manager::TEXT_DOMAIN,
        ); ?></label>
							</th>
							<td>
								<input id="ahpc-endpoint-url" name="<?php echo esc_attr(
            Webhook_Manager::OPTION_SETTINGS,
        ); ?>[endpoint_url]" type="url" class="regular-text code" value="<?php echo esc_attr(
    $settings["endpoint_url"],
); ?>" placeholder="https://example.com/webhooks/woocommerce" />
								<p class="description"><?php echo esc_html__(
            "Use your SaaS webhook ingress URL. The plugin will update all three managed WooCommerce webhooks to use this destination.",
            Webhook_Manager::TEXT_DOMAIN,
        ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ahpc-secret"><?php echo esc_html__(
            "Shared secret",
            Webhook_Manager::TEXT_DOMAIN,
        ); ?></label>
							</th>
							<td>
								<input id="ahpc-secret" name="<?php echo esc_attr(
            Webhook_Manager::OPTION_SETTINGS,
        ); ?>[secret]" type="text" class="regular-text code" value="<?php echo esc_attr(
    $settings["secret"],
); ?>" />
								<p class="description"><?php echo esc_html__(
            "WooCommerce signs each webhook payload with this secret in the X-WC-Webhook-Signature header.",
            Webhook_Manager::TEXT_DOMAIN,
        ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__(
           "Enable delivery",
           Webhook_Manager::TEXT_DOMAIN,
       ); ?></th>
							<td>
								<label for="ahpc-enabled">
									<input id="ahpc-enabled" name="<?php echo esc_attr(
             Webhook_Manager::OPTION_SETTINGS,
         ); ?>[enabled]" type="checkbox" value="1" <?php checked(
    !empty($settings["enabled"]),
); ?> />
									<?php echo esc_html__(
             "Send product webhook events to the configured delivery URL.",
             Webhook_Manager::TEXT_DOMAIN,
         ); ?>
								</label>
								<p class="description"><?php echo esc_html__(
            "When unchecked, the plugin keeps its managed WooCommerce webhooks in the paused state.",
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
        "The plugin owns exactly three WooCommerce webhooks and keeps them in sync with the settings above.",
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

        $settings["endpoint_url"] = isset($settings["endpoint_url"])
            ? esc_url_raw((string) $settings["endpoint_url"], ["http", "https"])
            : "";
        $settings["secret"] = sanitize_text_field((string) $settings["secret"]);
        $settings["enabled"] = !empty($settings["enabled"]);

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
