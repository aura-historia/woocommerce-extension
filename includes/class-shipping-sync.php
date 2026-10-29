<?php
/**
 * Local shipping notifications and bounded reconciliation.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

if (!defined("ABSPATH")) {
    exit();
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic dirty tickets and bounded identity scans touch only plugin-owned, non-autoloaded rows. These rows are deliberately read from the database, never from the option/object cache, to observe concurrent saves.

class Shipping_Sync
{
    const ACTION_HOOK = "ahpc_refresh_shipping";
    const RECONCILE_HOOK = "ahpc_reconcile_shipping";
    const SWEEP_HOOK = "ahpc_reconcile_shipping_members";
    const OPTION_BINDING = "ahpc_shipping_binding";
    const OPTION_ERROR = "ahpc_shipping_queue_error";
    const DIRTY_PREFIX = "ahpc_shipping_dirty_";
    const MEMBER_PREFIX = "ahpc_shipping_member_";
    /** Membership value suffix: Aura refused the removal because the listing is withdrawn. */
    const MEMBER_WITHDRAWN = ":withdrawn";
    const INTERVAL = 21600;
    const PAGE_SIZE = 20;

    public function register()
    {
        add_action(self::ACTION_HOOK, [$this, "drain"], 10, 2);
        add_action(self::RECONCILE_HOOK, [$this, "reconcile"], 10, 2);
        add_action(self::SWEEP_HOOK, [$this, "sweep"], 10, 4);
        add_action("action_scheduler_init", [$this, "initialize"], 30);
        foreach (["woocommerce_after_shipping_zone_object_save", "woocommerce_delete_shipping_zone",
            "woocommerce_shipping_zone_method_added", "woocommerce_shipping_zone_method_deleted",
            "woocommerce_delete_shipping_zone_method", "woocommerce_shipping_zone_method_status_toggled"] as $hook) {
            add_action($hook, [$this, "configuration_changed"]);
        }
        add_action("woocommerce_update_non_option_setting", [$this, "non_option_changed"]);
        // REST method ordering writes its own table without a zone save or a
        // method notification. Observe only successful local mutation routes.
        add_filter("rest_request_after_callbacks", [$this, "rest_changed"], 10, 3);
        add_action("updated_option", [$this, "option_changed"], 10, 3);
        add_action("added_option", [$this, "option_added"], 10, 2);
        add_action("deleted_option", [$this, "option_added"]);
        foreach (["created_product_shipping_class", "edited_product_shipping_class", "delete_product_shipping_class"] as $hook) {
            add_action($hook, [$this, "configuration_changed"]);
        }
        foreach (["woocommerce_after_product_object_save", "woocommerce_after_product_variation_object_save"] as $hook) {
            add_action($hook, [$this, "product_saved"]);
        }
        add_action("set_object_terms", [$this, "terms_changed"], 10, 4);
        add_action("transition_post_status", [$this, "status_changed"], 10, 3);
        add_action("before_delete_post", [$this, "post_deleted"], 10, 2);
        foreach (["added_post_meta", "updated_post_meta", "deleted_post_meta"] as $hook) {
            add_action($hook, [$this, "meta_changed"], 10, 3);
        }
        add_action("woocommerce_product_set_stock_status", [$this, "product_changed"]);
        add_action("woocommerce_variation_set_stock_status", [$this, "product_changed"]);
        add_action("update_option_" . Webhook_Manager::OPTION_SETTINGS, [$this, "connection_changed"], 10, 2);
    }

    public static function binding()
    {
        wp_cache_delete(self::OPTION_BINDING, "options");
        wp_cache_delete("notoptions", "options");
        $binding = get_option(self::OPTION_BINDING, []);
        return is_array($binding) ? $binding : [];
    }

    public static function generation()
    {
        return self::binding()["generation"] ?? "";
    }

    public static function settings()
    {
        wp_cache_delete(Webhook_Manager::OPTION_SETTINGS, "options");
        wp_cache_delete("notoptions", "options");
        return (new Webhook_Manager())->get_settings();
    }

    /** A valid token rotation keeps exact requests; reconnect explicitly fences. */
    public function connection_changed($old, $new)
    {
        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];
        if (($old["listing_source_id"] ?? "") !== ($new["listing_source_id"] ?? "")
            || ($old["secret"] ?? "") !== ($new["secret"] ?? "")
            || !Webhook_Manager::is_valid_access_token($new["access_token"] ?? "")) {
            self::stop();
            update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes", false);
            // An option notification cannot safely notify the old source using
            // credentials which have already been replaced. Report that fact.
            if (Webhook_Manager::is_valid_listing_source_id($old["listing_source_id"] ?? "")) {
                $cleanup = get_option(Shipping_Publisher::OPTION_CLEANUP, []);
                if (($cleanup["source"] ?? "") !== $old["listing_source_id"] || !in_array($cleanup["result"] ?? "", ["committed", "not_published"], true)) {
                    update_option(Shipping_Publisher::OPTION_CLEANUP, ["source" => $old["listing_source_id"], "at" => time(), "result" => "unconfirmed_connection_change"], false);
                }
            }
            $this->initialize(true);
        }
    }

    public function initialize($resume = false)
    {
        $settings = self::settings();
        $source = $settings["listing_source_id"];
        if (!Webhook_Manager::is_valid_listing_source_id($source) || !Webhook_Manager::is_valid_access_token($settings["access_token"])) {
            return;
        }
        $binding = self::binding();
        $context = hash("sha256", Webhook_Manager::get_backend_base_url() . ":" . $settings["secret"]);
        if ($binding && !empty($binding["enabled"]) && ($binding["context"] ?? "") !== $context) {
            self::stop();
            update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes", false);
            $resume = true;
        }
        if (!$binding || ($binding["source"] ?? "") !== $source || ($resume && empty(self::binding()["enabled"]))) {
            $binding = ["source" => $source, "generation" => wp_generate_uuid4(), "enabled" => true, "context" => $context];
            update_option(self::OPTION_BINDING, $binding, false);
            $this->configuration_changed();
        }
        if (empty($binding["enabled"]) || !function_exists("as_has_scheduled_action") || !did_action("action_scheduler_init")) {
            return;
        }
        $this->schedule($binding, self::INTERVAL, self::RECONCILE_HOOK);
        if ($this->rows($binding, 1)) {
            $this->schedule($binding);
        }
    }

    public function configuration_changed()
    {
        $this->mark("configuration");
    }

    public function non_option_changed($setting)
    {
        if (is_array($setting) && in_array($setting["id"] ?? "", ["shipping_zone", "zone_name", "zone_locations", "zone_postcodes", "zone_order", "zone_method", "zone_methods_order", "zone_methods_enabled", "zone_method_settings", "shipping_class", "shipping_class_name", "shipping_class_slug"], true)) {
            $this->configuration_changed();
        }
    }

    public function rest_changed($response, $handler, $request)
    {
        if (in_array($request->get_method(), ["POST", "PUT", "PATCH", "DELETE"], true)
            && preg_match('~\A/wc/v[123]/shipping/zones/[0-9]+/methods(?:/[0-9]+|/batch)?/?\z~', $request->get_route())
            && !is_wp_error($response) && rest_ensure_response($response)->get_status() < 400) {
            $this->configuration_changed();
        }
        return $response;
    }

    public function option_changed($option, $old, $new)
    {
        if ($old !== $new) {
            $this->option_added($option);
        }
    }

    public function option_added($option, $value = null)
    {
        if (in_array($option, ["woocommerce_currency", "woocommerce_weight_unit", "woocommerce_dimension_unit",
            "woocommerce_ship_to_countries", "woocommerce_specific_ship_to_countries", "woocommerce_allowed_countries",
            "woocommerce_specific_allowed_countries", "woocommerce_all_except_countries", "woocommerce_calc_taxes",
            "woocommerce_shipping_tax_class", "woocommerce_prices_include_tax", "woocommerce_tax_display_cart",
            "woocommerce_shipping_cost_requires_address", "woocommerce_pickup_location_settings", "woocommerce_pickup_locations",
            "woocommerce_default_country", "woocommerce_shipping_debug_mode"], true)
            || preg_match('/\Awoocommerce_[a-z0-9_-]+_[0-9]+_settings\z/', $option)) {
            $this->configuration_changed();
        }
    }

    public function product_saved($product)
    {
        if ($product instanceof \WC_Product) {
            $this->product_changed($product->get_parent_id() ?: $product->get_id());
        }
    }

    public function product_changed($id)
    {
        $post = get_post($id);
        if ($post && "product_variation" === $post->post_type) {
            $id = $post->post_parent;
        } elseif (!$post || "product" !== $post->post_type) {
            return;
        }
        if ((int) $id > 0) {
            $this->mark("item_" . (int) $id);
        }
    }

    public function terms_changed($id, $terms, $tt_ids, $taxonomy)
    {
        if (in_array($taxonomy, ["product_shipping_class", "product_type"], true)) {
            $this->product_changed($id);
        }
    }

    public function status_changed($new, $old, $post)
    {
        if ($new !== $old && in_array($post->post_type, ["product", "product_variation"], true)) {
            $this->product_changed($post->ID);
        }
    }

    public function post_deleted($id, $post)
    {
        if (in_array($post->post_type, ["product", "product_variation"], true)) {
            // Keep the parent identity before a variation disappears. The
            // worker rechecks absence after the transaction has finished.
            $this->product_changed("product_variation" === $post->post_type ? $post->post_parent : $id);
        }
    }

    public function meta_changed($meta_id, $id, $key)
    {
        if (in_array($key, ["_virtual", "_weight", "_length", "_width", "_height", "_regular_price", "_sale_price", "_price", "_stock_status", "_manage_stock", "_stock"], true)) {
            $this->product_changed($id);
        }
    }

    private function prefix(array $binding)
    {
        return self::DIRTY_PREFIX . $binding["source"] . "_" . $binding["generation"] . "_";
    }

    /** Independent tickets avoid lost updates between simultaneous save requests. */
    private function mark($scope)
    {
        $binding = self::binding();
        if (empty($binding["enabled"])) {
            return;
        }
        global $wpdb;
        $name = $this->prefix($binding) . $scope;
        $ticket = ":" . wp_generate_uuid4();
        $saved = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s, %s, 'no' FROM {$wpdb->options} AS binding WHERE binding.option_name = %s AND binding.option_value = %s ON DUPLICATE KEY UPDATE option_value = CONCAT(LEFT({$wpdb->options}.option_value, 10), %s)",
            $name, time() . $ticket, self::OPTION_BINDING, maybe_serialize($binding), $ticket
        ));
        if (false === $saved) {
            update_option(self::OPTION_ERROR, "dirty_checkpoint_failed", false);
        }
        $this->schedule($binding);
    }

    private function schedule(array $binding, $delay = 5, $hook = self::ACTION_HOOK)
    {
        if (!$this->current($binding["source"], $binding["generation"])) {
            return;
        }
        if (!function_exists("as_schedule_single_action") || !did_action("action_scheduler_init")) {
            update_option(self::OPTION_ERROR, "scheduler_unavailable", false);
            return;
        }
        $args = [$binding["source"], $binding["generation"]];
        // The currently running drain must be able to schedule a successor.
        // Duplicate deliveries are safe because ticket deletion is conditional.
        $pending = as_get_scheduled_actions(["hook" => $hook, "args" => $args,
            "group" => Shipping_Publisher::ACTION_GROUP, "status" => \ActionScheduler_Store::STATUS_PENDING, "per_page" => 1], "ids");
        if (!$pending && !as_schedule_single_action(time() + $delay, $hook, $args, Shipping_Publisher::ACTION_GROUP)) {
            update_option(self::OPTION_ERROR, "scheduling_failed", false);
        }
    }

    private function rows(array $binding, $limit)
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id LIMIT %d",
            $wpdb->esc_like($this->prefix($binding)) . "%", $limit
        ), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) {
            update_option(self::OPTION_ERROR, "dirty_read_failed", false);
            return false;
        }
        return $rows;
    }

    private function current($source, $generation)
    {
        $binding = self::binding();
        $settings = self::settings();
        return !empty($binding["enabled"]) && $source === ($binding["source"] ?? "") && $generation === ($binding["generation"] ?? "")
            && $source === $settings["listing_source_id"] && Webhook_Manager::is_valid_access_token($settings["access_token"]);
    }

    public function drain($source, $generation)
    {
        if (!$this->current($source, $generation)) {
            return;
        }
        $binding = self::binding();
        if ("no" !== get_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes")) {
            update_option(self::OPTION_ERROR, "configuration_not_ready", false);
            $this->schedule($binding, Shipping_Publisher::RETRY_DELAY);
            return;
        }
        global $wpdb;
        $publisher = new Shipping_Publisher();
        $rows = $this->rows($binding, self::PAGE_SIZE);
        if (false === $rows) {
            $this->schedule($binding, Shipping_Publisher::RETRY_DELAY);
            return;
        }
        foreach ($rows as $row) {
            if (!$this->current($source, $generation)) {
                return;
            }
            $scope = substr($row["option_name"], strlen($this->prefix($binding)));
            if ("configuration" === $scope) {
                $result = $publisher->queue($source);
            } else {
                $id = (int) substr($scope, strlen("item_"));
                // Only positive, complete local absence is removal evidence.
                $removed = Shipping_Publisher::confirmed_removed($id);
                $result = $removed ? $publisher->queue_item_removal($source, $id) : $publisher->queue_items($source, [$id]);
            }
            if (is_wp_error($result)) {
                update_option(self::OPTION_ERROR, $result->get_error_code(), false);
                $this->schedule($binding, Shipping_Publisher::RETRY_DELAY);
                return;
            }
            // A hook firing while queue() ran must retain its newer ticket.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $row["option_name"], $row["option_value"]));
        }
        delete_option(self::OPTION_ERROR);
        if ($this->rows($binding, 1)) {
            $this->schedule($binding);
        }
    }

    /** A full refresh is itself traversed one item per publisher action. */
    public function reconcile($source, $generation)
    {
        $binding = self::binding();
        if (empty($binding["enabled"]) || !$this->current($binding["source"], $binding["generation"])) {
            return;
        }
        if ($source !== $binding["source"] || $generation !== $binding["generation"]) {
            return;
        }
        // A recurring AS action can recreate itself after cancellation while
        // its callback is running. Use fenced single actions and schedule the
        // next tick before acquisition, so a crash cannot lose the cadence.
        $this->schedule($binding, self::INTERVAL, self::RECONCILE_HOOK);
        $this->configuration_changed();
        global $wpdb;
        $upper = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(option_id) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::MEMBER_PREFIX . $binding["source"] . "_") . "%"));
        if ($wpdb->last_error) {
            update_option(self::OPTION_ERROR, "membership_scan_failed", false);
            return;
        }
        if ($upper) {
            as_enqueue_async_action(self::SWEEP_HOOK, [$binding["source"], $binding["generation"], 0, $upper], Shipping_Publisher::ACTION_GROUP, true);
        }
    }

    public function sweep($source, $generation, $cursor, $upper)
    {
        if (!$this->current($source, $generation)) {
            return;
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d AND option_id <= %d ORDER BY option_id LIMIT %d",
            $wpdb->esc_like(self::MEMBER_PREFIX . $source . "_") . "%", $cursor, $upper, self::PAGE_SIZE
        ), ARRAY_A);
        if ($wpdb->last_error) {
            update_option(self::OPTION_ERROR, "membership_scan_failed", false);
            return;
        }
        foreach ($rows as $row) {
            $id = (int) $row["option_value"];
            // A removal refused for a withdrawn listing was not missed. Scanning
            // it again would only resend the refused clear; local changes still do.
            if (!str_ends_with($row["option_value"], self::MEMBER_WITHDRAWN) && Shipping_Publisher::confirmed_removed($id)) {
                $this->mark("item_" . $id);
            }
            $cursor = (int) $row["option_id"];
        }
        if (count($rows) === self::PAGE_SIZE) {
            as_enqueue_async_action(self::SWEEP_HOOK, [$source, $generation, $cursor, $upper], Shipping_Publisher::ACTION_GROUP, true);
        }
    }

    public function status()
    {
        global $wpdb;
        $binding = self::binding();
        $rows = !empty($binding["enabled"]) ? $this->rows($binding, 1) : [];
        $withdrawn = Webhook_Manager::is_valid_listing_source_id($binding["source"] ?? "") ? (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
            $wpdb->esc_like(self::MEMBER_PREFIX . $binding["source"] . "_") . "%", "%" . $wpdb->esc_like(self::MEMBER_WITHDRAWN)
        )) : 0;
        return ["oldest_pending" => $rows ? (int) $rows[0]["option_value"] : 0, "error" => (string) get_option(self::OPTION_ERROR, ""), "withdrawn" => $withdrawn];
    }

    public static function stop()
    {
        $binding = self::binding();
        $binding["generation"] = wp_generate_uuid4();
        $binding["enabled"] = false;
        update_option(self::OPTION_BINDING, $binding, false);
        Shipping_Publisher::cancel();
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::DIRTY_PREFIX) . "%"));
        foreach ([self::ACTION_HOOK, self::RECONCILE_HOOK, self::SWEEP_HOOK] as $hook) {
            if (function_exists("as_unschedule_all_actions")) {
                as_unschedule_all_actions($hook, null, Shipping_Publisher::ACTION_GROUP);
            }
        }
    }

    public static function uninstall()
    {
        self::stop();
        global $wpdb;
        foreach ([self::DIRTY_PREFIX, self::MEMBER_PREFIX] as $prefix) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix) . "%"));
        }
        delete_option(self::OPTION_BINDING);
        delete_option(self::OPTION_ERROR);
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
