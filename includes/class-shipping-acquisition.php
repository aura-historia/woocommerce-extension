<?php
/**
 * Allowlisted local WooCommerce acquisition; no REST credentials or checkout.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

if (!defined("ABSPATH")) {
    exit();
}

class Shipping_Acquisition
{
    /** Actual successful read time. Retries must retain this value unchanged. */
    public static function acquired_at()
    {
        return (new \DateTimeImmutable("now", new \DateTimeZone("UTC")))->format("Y-m-d\TH:i:s.u\Z");
    }

    /** Read twice to detect configuration changes during traversal. */
    public function configuration()
    {
        try {
            $first = $this->read_configuration();
            $second = $this->read_configuration();
            if (is_wp_error($first) || is_wp_error($second)) {
                return is_wp_error($first) ? $first : $second;
            }
            $fingerprint = self::fingerprint($first);
            if ($fingerprint !== self::fingerprint($second)) {
                return Shipping_Mapper::error("configuration_changed");
            }
            return ["configuration" => $second, "fingerprint" => $fingerprint, "acquired_at" => self::acquired_at()];
        } catch (\Throwable $exception) {
            return Shipping_Mapper::error("configuration_read_failed");
        }
    }

    private function read_configuration()
    {
        global $wpdb;
        if (!class_exists("WC_Shipping_Zones") || !function_exists("WC") || !WC()->countries || !WC()->shipping()) {
            return Shipping_Mapper::error("woocommerce_unavailable");
        }
        $currency = Store_Locale::get_currency();
        if (null === $currency || !in_array($currency, InternalApi\Model\ShippingCurrencyCode::getAllowableEnumValues(), true)) {
            return Shipping_Mapper::error("unsupported_currency");
        }
        if (!in_array(get_option("woocommerce_ship_to_countries", ""), ["", "all", "specific", "disabled"], true)
            || !in_array(get_option("woocommerce_allowed_countries", "all"), ["all", "all_except", "specific"], true)) {
            return Shipping_Mapper::error("unsupported_country_settings");
        }
        // WooCommerce's shipping singleton memoizes this collection for the
        // request. The supported taxonomy API observes class edits during a run.
        $classes = get_terms(["taxonomy" => "product_shipping_class", "hide_empty" => false, "orderby" => "term_id", "order" => "ASC"]);
        if (!is_array($classes) || count($classes) > 63 || $wpdb->last_error) {
            return Shipping_Mapper::error("class_read_failed");
        }
        $class_ids = [0];
        foreach ($classes as $class) {
            $class_ids[] = (int) $class->term_id;
        }
        sort($class_ids, SORT_NUMERIC);
        $zone_objects = \WC_Shipping_Zones::get_shipping_zones();
        if (!is_array($zone_objects) || count($zone_objects) > 63 || $wpdb->last_error) {
            return Shipping_Mapper::error("zone_read_failed");
        }
        // The zone collection excludes "Locations not covered by your other
        // zones". Avoid get_zones()'s admin HTML/settings expansion as well.
        $zone_objects[] = \WC_Shipping_Zones::get_zone(0);
        $store = \WC_Data_Store::load("shipping-zone");
        $zones = [];
        foreach ($zone_objects as $zone) {
            if (!$zone || null === $zone->get_id() || $wpdb->last_error) {
                return Shipping_Mapper::error("zone_disappeared");
            }
            $id = (int) $zone->get_id();
            $locations = [];
            $zone_locations = $zone->get_zone_locations();
            if (!is_array($zone_locations) || count($zone_locations) > 64 || $wpdb->last_error) {
                return Shipping_Mapper::error("zone_location_read_failed");
            }
            foreach ($zone_locations as $location) {
                $locations[] = ["type" => (string) $location->type, "code" => (string) $location->code];
            }
            $methods = [];
            // This public data-store API retains enabled methods whose plugin
            // is unavailable. They must stay unknown, not become a no-method
            // negative. It returns only identifiers/order/enabled flags.
            $instances = $store->get_methods($id, true);
            if (!is_array($instances) || count($instances) > 64 || $wpdb->last_error) {
                return Shipping_Mapper::error("method_read_failed");
            }
            usort($instances, static function ($left, $right) {
                return [(int) $left->method_order, (int) $left->instance_id] <=> [(int) $right->method_order, (int) $right->instance_id];
            });
            foreach ($instances as $row) {
                $instance = \WC_Shipping_Zones::get_shipping_method((int) $row->instance_id);
                if ($wpdb->last_error) {
                    return Shipping_Mapper::error("method_read_failed");
                }
                $method = ["id" => (string) $row->method_id, "instance_id" => (int) $row->instance_id, "core" => false, "options" => []];
                if (!preg_match('/\A[a-z0-9_-]{1,64}\z/', $method["id"])) {
                    $method["id"] = "unrecognized";
                }
                $expected = ["flat_rate" => "WC_Shipping_Flat_Rate", "free_shipping" => "WC_Shipping_Free_Shipping", "local_pickup" => "WC_Shipping_Local_Pickup"];
                $method["core"] = $instance && isset($expected[$method["id"]]) && get_class($instance) === $expected[$method["id"]];
                if ($method["core"] && "flat_rate" === $method["id"]) {
                    $method["options"] = ["cost" => (string) $instance->get_option("cost", ""), "tax_status" => (string) $instance->get_option("tax_status", "taxable"), "type" => (string) $instance->get_option("type", "class"), "class_costs" => []];
                    // Core ignores all class charges, including no_class_cost,
                    // when the shipping-class taxonomy is empty.
                    foreach ($classes ? $class_ids : [] as $class_id) {
                        $legacy = $class_id ? get_term($class_id, "product_shipping_class") : null;
                        $default = $legacy && !is_wp_error($legacy) ? $instance->get_option("class_cost_" . $legacy->slug, "") : "";
                        $method["options"]["class_costs"][$class_id] = (string) $instance->get_option($class_id ? "class_cost_" . $class_id : "no_class_cost", $default);
                    }
                } elseif ($method["core"] && "free_shipping" === $method["id"]) {
                    foreach (["requires" => "", "min_amount" => "0", "ignore_discounts" => "no"] as $key => $default) {
                        $method["options"][$key] = (string) $instance->get_option($key, $default);
                    }
                }
                // Third-party settings can contain credentials. Never enumerate them.
                $methods[] = $method;
            }
            $zones[] = ["id" => $id, "order" => (int) $zone->get_zone_order(), "locations" => $locations, "methods" => $methods];
        }
        $continents = [];
        foreach (WC()->countries->get_continents() as $code => $continent) {
            $continents[$code] = array_values($continent["countries"]);
        }
        $runtime_filters = $this->has_runtime_filters();
        $pickup = get_option("woocommerce_pickup_location_settings", []);
        $shipping_countries = array_keys(WC()->countries->get_shipping_countries());
        $all_countries = array_keys(WC()->countries->get_countries());
        return ["complete" => true, "zones" => $zones, "class_ids" => $class_ids, "currency" => $currency,
            "weight_unit" => get_option("woocommerce_weight_unit", "kg"), "dimension_unit" => get_option("woocommerce_dimension_unit", "cm"),
            "shipping_enabled" => wc_shipping_enabled(), "shipping_countries" => $shipping_countries,
            "restricted_countries" => (bool) array_diff($all_countries, $shipping_countries),
            "tax_enabled" => wc_tax_enabled(), "runtime_filters" => $runtime_filters, "continents" => $continents,
            "block_pickup" => is_array($pickup) && in_array($pickup["enabled"] ?? false, [true, "yes", "1", 1], true)];
    }

    private function has_runtime_filters()
    {
        global $wp_filter;
        foreach (["woocommerce_package_rates", "woocommerce_shipping_packages", "woocommerce_shipping_zone_shipping_methods", "woocommerce_get_zone_criteria", "woocommerce_evaluate_shipping_cost_args", "woocommerce_flat_rate_shipping_add_rate", "woocommerce_shipping_method_add_rate", "woocommerce_shipping_rate_cost", "woocommerce_shipping_rate_taxes", "woocommerce_shipping_flat_rate_is_available", "woocommerce_shipping_free_shipping_is_available", "woocommerce_shipping_flat_rate_instance_option", "woocommerce_shipping_free_shipping_instance_option"] as $hook) {
            if (!has_filter($hook)) {
                continue;
            }
            foreach ($wp_filter[$hook]->callbacks as $callbacks) {
                foreach ($callbacks as $callback) {
                    $function = $callback["function"];
                    if (is_array($function) && is_object($function[0])) {
                        $class = get_class($function[0]);
                        // Reviewed core Blocks behavior: pickup package filtering
                        // and hiding costs until an address is entered do not
                        // modify advertised delivery rates. Pickup stays uncertain.
                        if ("woocommerce_shipping_packages" === $hook && "Automattic\\WooCommerce\\Blocks\\Shipping\\ShippingController" === $class
                            && in_array($function[1], ["filter_shipping_packages", "remove_shipping_if_no_address"], true)) {
                            continue;
                        }
                        // The legacy core method registers this action even
                        // with no extra rates. Nonempty legacy rules stay unknown.
                        if ("woocommerce_flat_rate_shipping_add_rate" === $hook && "WC_Shipping_Legacy_Flat_Rate" === $class
                            && "calculate_extra_shipping" === $function[1] && "" === (string) $function[0]->get_option("options", "")) {
                            continue;
                        }
                    }
                    return true;
                }
            }
        }
        return false;
    }

    /** Product facts stay separate from the common policy. */
    public function item($product_id)
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product || "publish" !== $product->get_status()) {
                return Shipping_Mapper::error("item_unavailable");
            }
            if (!$product->is_type(["simple", "variable"])) {
                return Shipping_Mapper::error("unsupported_product_type");
            }
            $classes = [(int) $product->get_shipping_class_id()];
            $variants = [];
            $virtual = $product->is_virtual();
            if ($product->is_type("variable")) {
                $classes = [];
                $virtual = false;
                $children = $product->get_children();
                if (count($children) > 64) {
                    return Shipping_Mapper::error("too_many_variants");
                }
                foreach ($children as $child_id) {
                    $variation = wc_get_product($child_id);
                    if (!$variation) {
                        return Shipping_Mapper::error("variation_read_failed");
                    }
                    if ("publish" !== $variation->get_status() || !$variation->is_in_stock() || "" === $variation->get_price()) {
                        continue;
                    }
                    $variants[] = "wc:variation:" . $variation->get_id();
                    // WooCommerce's getter resolves inherited parent membership.
                    $classes[] = (int) $variation->get_shipping_class_id();
                    // A mixed physical/virtual parent cannot offer physical
                    // delivery universally without a selected variant.
                    $virtual = $virtual || $variation->is_virtual();
                }
                if (!$variants) {
                    return Shipping_Mapper::error("no_available_variants");
                }
            }
            return ["id" => (int) $product_id, "class_ids" => $classes, "variants" => $variants, "virtual" => $virtual];
        } catch (\Throwable $exception) {
            return Shipping_Mapper::error("item_read_failed");
        }
    }

    /** Stable keyset, using WooCommerce's product data store query extension. */
    public function product_ids($after_id, $limit, $upper_id)
    {
        global $wpdb;
        $filter = static function ($where, $query) use ($wpdb, $after_id, $upper_id) {
            if (!$query->get("ahpc_shipping_page")) {
                return $where;
            }
            return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d AND {$wpdb->posts}.ID <= %d", $after_id, $upper_id);
        };
        add_filter("posts_where", $filter, 10, 2);
        try {
            $query = new \WP_Query(["post_type" => "product", "post_status" => "publish", "posts_per_page" => $limit,
                "orderby" => "ID", "order" => "ASC", "fields" => "ids", "no_found_rows" => true, "ahpc_shipping_page" => true]);
            if ($wpdb->last_error) {
                return Shipping_Mapper::error("product_page_failed");
            }
            return array_map("intval", $query->posts);
        } finally {
            remove_filter("posts_where", $filter, 10);
        }
    }

    public function upper_product_id()
    {
        global $wpdb;
        try {
            $ids = wc_get_products(["status" => "publish", "limit" => 1, "orderby" => "ID", "order" => "DESC", "return" => "ids"]);
            if (!is_array($ids) || $wpdb->last_error) {
                return Shipping_Mapper::error("product_page_failed");
            }
            return $ids ? (int) $ids[0] : 0;
        } catch (\Throwable $exception) {
            return Shipping_Mapper::error("product_page_failed");
        }
    }

    public static function fingerprint($value)
    {
        $json = wp_json_encode($value);
        if (!is_string($json)) {
            throw new \RuntimeException("shipping_encoding_failed");
        }
        return hash("sha256", $json);
    }
}
