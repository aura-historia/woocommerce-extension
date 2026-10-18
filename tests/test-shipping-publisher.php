<?php
/**
 * Local WooCommerce acquisition, scheduling and typed publication integration.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Shipping_Acquisition;
use AuraHistoria\PartnerConnect\Shipping_Mapper;
use AuraHistoria\PartnerConnect\Shipping_Publisher;
use AuraHistoria\PartnerConnect\Product_Backfill;
use AuraHistoria\PartnerConnect\Webhook_Manager;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;

class Test_AHPC_Shipping_Publisher extends WP_UnitTestCase
{
    const SOURCE = "ls_01jw7j4azge008000000000003";
    const PROFILE = "shp_01jw7j4azge008000000000004";
    const LISTING = "pl_01jw7j4azge008000000000005";
    const TOKEN = "aurahistoria_accesstoken_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";
    private $requests = [];
    private $profile = null;
    private $versions = [];
    private $listing_bodies = [];
    private $default_revision = 1;
    private $missing = [];
    private $ownership = [];
    private $lose_response = false;
    private $conflict_once = false;
    private $zones = [];
    private $products = [];
    private $classes = [];
    private $receipts = [];

    public static function wpSetUpBeforeClass($factory)
    {
        WC_Install::install();
    }

    public function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(["role" => "administrator"]));
        (new Webhook_Manager())->delete_webhooks();
        update_option(Webhook_Manager::OPTION_SETTINGS, ["listing_source_id" => self::SOURCE, "access_token" => self::TOKEN, "secret" => "signature-only"], false);
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);
        update_option("woocommerce_currency", "EUR");
        update_option("woocommerce_ship_to_countries", "all");
        update_option("woocommerce_allowed_countries", "all");
        add_filter("ahpc_backend_base_url", [$this, "base_url"]);
        add_filter("ahpc_backend_guzzle_client", [$this, "http_client"]);
        add_filter("pre_http_request", [$this, "block_outbound"], 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter("ahpc_backend_base_url", [$this, "base_url"]);
        remove_filter("ahpc_backend_guzzle_client", [$this, "http_client"]);
        remove_filter("pre_http_request", [$this, "block_outbound"], 10);
        (new Webhook_Manager())->delete_webhooks();
        foreach ($this->zones as $zone) {
            $zone->delete(true);
        }
        $fallback = WC_Shipping_Zones::get_zone(0);
        foreach ($fallback->get_shipping_methods() as $method) {
            $fallback->delete_shipping_method($method->get_instance_id());
        }
        foreach ($this->products as $product) {
            $product->delete(true);
        }
        foreach ($this->classes as $id) {
            wp_delete_term($id, "product_shipping_class");
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function base_url($url)
    {
        return "https://shipping.invalid";
    }

    public function block_outbound($preempt, $args, $url)
    {
        return new WP_Error("outbound_blocked", "Unexpected outbound request in shipping tests.");
    }

    public function http_client($client)
    {
        return new Client(["handler" => HandlerStack::create(function ($request) {
            $this->requests[] = $request;
            try {
                return Create::promiseFor($this->backend($request));
            } catch (ConnectException $error) {
                return Create::rejectionFor($error);
            }
        })]);
    }

    private function reply($body, $status = 200)
    {
        return new Response($status, ["Content-Type" => "application/json"], wp_json_encode($body));
    }

    private function backend($request)
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $body = json_decode((string) $request->getBody(), true);
        $key = $body["import"]["requestKey"] ?? null;
        if ($key && isset($this->receipts[$key])) {
            $this->assertSame($this->receipts[$key][0], (string) $request->getBody());
            return $this->reply($this->receipts[$key][1]);
        }
        if ($key) {
            $wire = json_decode((string) $request->getBody());
            if (isset($wire->import->observation)) {
                $this->assertInstanceOf(stdClass::class, $wire->import->observation);
                $this->assertSame([], get_object_vars($wire->import->observation));
            }
            $this->assertStringContainsString(".", $wire->import->acquiredAt);
            $this->assertStringNotContainsString("signature-only", (string) $request->getBody());
            $this->assertStringNotContainsString(self::TOKEN, (string) $request->getBody());
        }
        if (str_ends_with($path, "/shipping-profiles") && "GET" === $method) {
            parse_str($request->getUri()->getQuery(), $query);
            $this->assertSame(Shipping_Mapper::OBJECT_KEY, $query["objectKey"]);
            $this->assertSame("WOOCOMMERCE", $query["integrationMethod"]);
            return $this->reply($this->profile ? [array_diff_key($this->profile, ["content" => true])] : []);
        }
        if (str_contains($path, "/shipping-profiles") && "GET" !== $method) {
            if (!empty($this->ownership["profile"])) {
                return $this->reply(["error" => "SHIPPING_IMPORT_OWNERSHIP_CONFLICT"], 409);
            }
            $revision = ($this->profile["managementRevision"] ?? 0) + 1;
            $content = str_ends_with($path, "/restore") ? $this->profile["content"] : $body["content"];
            $this->profile = ["shippingProfileId" => self::PROFILE, "listingSourceId" => self::SOURCE, "managementRevision" => $revision, "termsRevision" => $revision, "lifecycle" => "ACTIVE", "content" => $content];
            $this->receipts[$key] = [(string) $request->getBody(), $this->profile];
            if ($this->lose_response) {
                $this->lose_response = false;
                throw new ConnectException("response lost", $request);
            }
            return $this->reply($this->profile);
        }
        if (str_ends_with($path, "/shipping-default")) {
            if ("GET" !== $method && !empty($this->ownership["default"])) {
                return $this->reply(["error" => "SHIPPING_IMPORT_OWNERSHIP_CONFLICT"], 409);
            }
            if ("GET" !== $method) {
                $this->default_revision++;
            }
            return $this->reply(["listingSourceId" => self::SOURCE, "revision" => $this->default_revision, "shippingProfileId" => "GET" === $method ? null : self::PROFILE, "eligibility" => null]);
        }
        if (preg_match('~/by-source-id/([0-9]+)/delivery$~', $path, $match)) {
            $id = $match[1];
            if (!empty($this->missing[$id])) {
                return $this->reply(["error" => "SHIPPING_IMPORT_DEPENDENCY_MISSING"], 404);
            }
            $version = $this->versions[$id] ?? 1;
            if ("GET" === $method) {
                return $this->reply(["productListingId" => self::LISTING, "expectedVersion" => $version, "delivery" => null]);
            }
            $component = "UNCHANGED" !== $body["delivery"]["value"]["selection"]["state"] ? "selection" : "inputs";
            if (!empty($this->ownership[$component])) {
                return $this->reply(["error" => "SHIPPING_IMPORT_OWNERSHIP_CONFLICT"], 409);
            }
            if ($this->conflict_once) {
                $this->conflict_once = false;
                $this->versions[$id] = $version + 1;
                return $this->reply(["error" => "SHIPPING_IMPORT_OUT_OF_ORDER"], 409);
            }
            $this->listing_bodies[$id][$component] = $body;
            $this->versions[$id] = $version + 1;
            return $this->reply(["productListingId" => self::LISTING, "expectedVersion" => $version + 1, "outcome" => "CHANGED"]);
        }
        $this->fail("Unexpected request: " . $method . " " . $path);
    }

    private function product($class_id = 0)
    {
        $product = new WC_Product_Simple();
        $product->set_name("Shipping fixture");
        $product->set_status("publish");
        $product->set_regular_price("100");
        $product->set_shipping_class_id($class_id);
        $product->save();
        $this->products[] = $product;
        return $product;
    }

    private function zone($country = "DE", $cost = "8.30", $order = 1)
    {
        $zone = new WC_Shipping_Zone();
        $zone->set_zone_name("Shipping fixture");
        $zone->set_zone_order($order);
        $zone->add_location($country, "country");
        $zone->save();
        $this->zones[] = $zone;
        $instance = $zone->add_shipping_method("flat_rate");
        update_option("woocommerce_flat_rate_" . $instance . "_settings", ["cost" => $cost, "type" => "class", "tax_status" => "none"]);
        return $zone;
    }

    private function tick()
    {
        $publisher = new Shipping_Publisher();
        $state = $publisher->get_status(self::SOURCE);
        $this->assertNotEmpty($state["wake"]);
        as_unschedule_all_actions(Shipping_Publisher::ACTION_HOOK, [self::SOURCE, $state["wake"]], Shipping_Publisher::ACTION_GROUP);
        $publisher->process(self::SOURCE, $state["wake"]);
        return $publisher->get_status(self::SOURCE);
    }

    private function finish($limit = 40)
    {
        do {
            $state = $this->tick();
        } while (in_array($state["status"], ["queued", "running"], true) && --$limit > 0);
        $this->assertGreaterThan(0, $limit, wp_json_encode($state));
        return $state;
    }

    private function writes()
    {
        return array_values(array_filter($this->requests, static function ($request) { return "GET" !== $request->getMethod(); }));
    }

    public function test_local_api_acquisition_orders_zones_and_includes_zero_without_rest_keys()
    {
        $later = $this->zone("DE", "95", 20);
        $first = $this->zone("DE", "80", 1);
        $capture = (new Shipping_Acquisition())->configuration();
        $this->assertIsArray($capture);
        $zones = $capture["configuration"]["zones"];
        $this->assertSame([$first->get_id(), $later->get_id(), 0], array_column($zones, "id"));
        $this->assertSame("80", $zones[0]["methods"][0]["options"]["cost"]);
        $this->assertFalse($capture["configuration"]["runtime_filters"]);
        $mapped = (new Shipping_Mapper())->profile($capture["configuration"]);
        $this->assertSame(8000, $mapped["content"]["terms"]["shipping"]["coverage"]["zones"][0]["services"][0]["rate"]["price"]["amount"]["amount_minor"]);
        $this->assertSame([], $zones[2]["methods"]);
        $encoded = wp_json_encode($capture);
        foreach (["consumer_key", "consumer_secret", "signature-only", self::TOKEN, "address", "description"] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
    }

    public function test_publishes_profiles_before_existing_listings_and_manual_refresh_reuses_identity()
    {
        $this->zone();
        $product = $this->product();
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish();
        $this->assertSame("complete", $state["status"]);
        $this->assertSame(1, $state["processed"]);
        $writes = $this->writes();
        $this->assertSame("POST", $writes[0]->getMethod());
        $this->assertStringEndsWith("/shipping-profiles", $writes[0]->getUri()->getPath());
        $this->assertSame(["kind" => "EXPLICIT", "profile_id" => self::PROFILE], $this->listing_bodies[$product->get_id()]["selection"]["delivery"]["value"]["selection"]["value"]);
        $this->assertSame(["codes" => ["wc:class:none"], "completeness" => "COMPLETE"], $this->listing_bodies[$product->get_id()]["inputs"]["delivery"]["value"]["rateInputs"]["value"]["classes"]);
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $creates = array_filter($this->writes(), static function ($request) { return "POST" === $request->getMethod(); });
        $this->assertCount(1, $creates);
        $this->assertSame(self::PROFILE, $publisher->get_status(self::SOURCE)["profile_id"]);
        $refresh = json_decode((string) $this->writes()[4]->getBody(), true);
        $initial = json_decode((string) $writes[0]->getBody(), true);
        $this->assertSame($initial["content"], $refresh["content"]);
        $this->assertNotSame($initial["import"]["requestKey"], $refresh["import"]["requestKey"]);
        $this->assertGreaterThan($initial["import"]["sequence"], $refresh["import"]["sequence"]);
        $this->assertNotSame($initial["import"]["acquiredAt"], $refresh["import"]["acquiredAt"]);
        foreach ($this->requests as $request) {
            $this->assertSame("Bearer " . self::TOKEN, $request->getHeaderLine("Authorization"));
            $this->assertStringNotContainsString("/product-listings/async", (string) $request->getUri());
            $this->assertStringNotContainsString("freshUntil", (string) $request->getBody());
            $this->assertStringNotContainsString("expiresAt", (string) $request->getBody());
        }
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_empty_location_zone_precedes_fallback_with_country_restrictions()
    {
        update_option("woocommerce_ship_to_countries", "specific");
        update_option("woocommerce_specific_ship_to_countries", ["DE"]);
        $zone = $this->zone();
        $zone->set_zone_locations([]);
        $zone->save();
        $matched = WC_Shipping_Zones::get_zone_matching_package(["destination" => ["country" => "DE", "state" => "", "postcode" => "10115"]]);
        $this->assertSame($zone->get_id(), $matched->get_id());
        $capture = (new Shipping_Acquisition())->configuration();
        $this->assertIsArray($capture);
        $mapped = (new Shipping_Mapper())->profile($capture["configuration"]);
        $zones = $mapped["content"]["terms"]["shipping"]["coverage"]["zones"];
        $this->assertCount(2, $zones);
        $this->assertSame("DE", $zones[0]["areas"][0]["code"]);
        $this->assertSame(830, $zones[0]["services"][0]["rate"]["price"]["amount"]["amount_minor"]);
    }

    public function test_no_class_charge_is_ignored_when_no_shipping_classes_exist()
    {
        $zone = $this->zone("DE", "5");
        $methods = $zone->get_shipping_methods();
        $method = reset($methods);
        update_option("woocommerce_flat_rate_" . $method->get_instance_id() . "_settings", ["cost" => "5", "no_class_cost" => "10", "type" => "class", "tax_status" => "none"]);
        $capture = (new Shipping_Acquisition())->configuration();
        $this->assertIsArray($capture);
        $this->assertSame([0], $capture["configuration"]["class_ids"]);
        $this->assertSame([], $capture["configuration"]["zones"][0]["methods"][0]["options"]["class_costs"]);
        $mapped = (new Shipping_Mapper())->profile($capture["configuration"]);
        $service = $mapped["content"]["terms"]["shipping"]["coverage"]["zones"][0]["services"][0];
        $this->assertSame("OFFERED", $service["eligibility"]);
        $this->assertSame(500, $service["rate"]["price"]["amount"]["amount_minor"]);
    }

    public function test_lost_response_retries_byte_identically_with_current_credentials()
    {
        $this->zone();
        $this->lose_response = true;
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->tick();
        $this->assertNotEmpty($state["pending"]);
        $saved = $state["pending"];
        $settings = get_option(Webhook_Manager::OPTION_SETTINGS);
        $settings["access_token"] = self::TOKEN . "rotated";
        update_option(Webhook_Manager::OPTION_SETTINGS, $settings, false);
        $this->tick();
        $writes = $this->writes();
        $this->assertSame((string) $writes[0]->getBody(), (string) $writes[1]->getBody());
        $this->assertSame(1, $this->profile["managementRevision"]);
        $this->assertSame("Bearer " . self::TOKEN . "rotated", $writes[1]->getHeaderLine("Authorization"));
        $this->assertStringNotContainsString(self::TOKEN, wp_json_encode($saved));
        $this->assertStringNotContainsString("signature-only", wp_json_encode($saved));
        $this->assertSame("complete", $this->finish()["status"]);
    }

    public function test_catalog_lag_is_bounded_and_shipping_only_manual_resume_retries_missing_items()
    {
        $this->zone();
        $first = $this->product();
        $second = $this->product();
        $this->missing[$first->get_id()] = true;
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish();
        $this->assertSame("partial", $state["status"]);
        $this->assertCount(2, $state["deferred"]);
        $this->assertArrayHasKey($second->get_id(), $this->listing_bodies);
        $this->missing = [];
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertArrayHasKey($first->get_id(), $this->listing_bodies);
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_manual_component_ownership_preserves_other_item_inputs()
    {
        $this->zone();
        $product = $this->product();
        $this->ownership = ["default" => true, "selection" => true];
        $this->assertTrue((new Shipping_Publisher())->queue(self::SOURCE));
        $state = $this->finish();
        $this->assertSame("complete", $state["status"]);
        $this->assertSame(2, $state["protected"]);
        $this->assertArrayNotHasKey("selection", $this->listing_bodies[$product->get_id()]);
        $this->assertArrayHasKey("inputs", $this->listing_bodies[$product->get_id()]);
        foreach ($this->listing_bodies[$product->get_id()] as $body) {
            $this->assertSame(["state" => "UNCHANGED"], $body["delivery"]["value"]["shipping"]);
            $this->assertSame(["state" => "UNCHANGED"], $body["delivery"]["value"]["collection"]);
        }
    }

    public function test_conflict_reacquires_item_instead_of_rebasing_old_content()
    {
        $this->zone();
        $product = $this->product();
        $this->assertTrue((new Shipping_Publisher())->queue(self::SOURCE));
        $this->tick();
        $this->tick();
        $this->conflict_once = true;
        $rejected = $this->tick();
        $this->assertNull($rejected["pending"]);
        $product->set_virtual(true);
        $product->save();
        $this->assertSame("complete", $this->finish()["status"]);
        $writes = array_values(array_filter($this->writes(), static function ($request) { return str_ends_with($request->getUri()->getPath(), "/delivery"); }));
        $old = json_decode((string) $writes[0]->getBody(), true);
        $fresh = json_decode((string) $writes[1]->getBody(), true);
        $this->assertNotSame($old["import"]["requestKey"], $fresh["import"]["requestKey"]);
        $this->assertGreaterThan($old["import"]["sequence"], $fresh["import"]["sequence"]);
        $this->assertSame("EXPLICIT", $old["delivery"]["value"]["selection"]["value"]["kind"]);
        $this->assertSame("SUPPRESS_INHERITANCE", $fresh["delivery"]["value"]["selection"]["value"]["kind"]);
    }

    public function test_configuration_change_during_traversal_republishes_common_profile_before_assignments()
    {
        $zone = $this->zone("DE", "80");
        $this->product();
        $this->assertTrue((new Shipping_Publisher())->queue(self::SOURCE));
        $this->tick();
        $this->tick();
        $methods = $zone->get_shipping_methods();
        $method = reset($methods);
        update_option("woocommerce_flat_rate_" . $method->get_instance_id() . "_settings", ["cost" => "95", "type" => "class", "tax_status" => "none"]);
        $this->assertSame("profile", $this->tick()["phase"]);
        $state = $this->finish();
        $this->assertSame("complete", $state["status"]);
        $price = $this->profile["content"]["terms"]["shipping"]["coverage"]["zones"][0]["services"][0]["rate"]["price"];
        $this->assertSame("FIXED", $price["kind"], wp_json_encode($state["limitations"] ?? []));
        $this->assertSame(9500, $price["amount"]["amount_minor"]);
    }

    public function test_source_lock_stale_wakes_and_uninstall_preserve_coordination()
    {
        $first = new Shipping_Publisher();
        $other = new Shipping_Publisher();
        $this->assertTrue($first->acquire_source_lock(self::SOURCE));
        $this->assertFalse($other->acquire_source_lock(self::SOURCE));
        $first->release_source_lock();
        $this->assertTrue($other->acquire_source_lock(self::SOURCE));
        $other->release_source_lock();
        $this->assertTrue($first->queue(self::SOURCE));
        $before = $first->get_status(self::SOURCE);
        $first->process(self::SOURCE, "old-wake");
        $this->assertSame($before, $first->get_status(self::SOURCE));
        $this->assertCount(0, $this->requests);
        Shipping_Publisher::uninstall();
        $this->assertSame([], $first->get_status(self::SOURCE));
        $this->assertFalse(get_option(Shipping_Publisher::order_option(self::SOURCE)));
        $this->assertFalse(as_has_scheduled_action(Shipping_Publisher::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP));
    }

    public function test_real_variation_class_inheritance_and_mixed_membership()
    {
        $class = wp_insert_term("Fragile fixture", "product_shipping_class");
        $other = wp_insert_term("Large fixture", "product_shipping_class");
        $this->classes = [$class["term_id"], $other["term_id"]];
        $parent = new WC_Product_Variable();
        $parent->set_name("Variable shipping fixture");
        $parent->set_status("publish");
        $parent->set_shipping_class_id($class["term_id"]);
        $parent->save();
        $this->products[] = $parent;
        $children = [];
        foreach ([0, $other["term_id"]] as $class_id) {
            $child = new WC_Product_Variation();
            $child->set_parent_id($parent->get_id());
            $child->set_status("publish");
            $child->set_regular_price("25");
            $child->set_shipping_class_id($class_id);
            $child->save();
            $children[] = $child;
        }
        WC_Product_Variable::sync($parent->get_id());
        $facts = (new Shipping_Acquisition())->item($parent->get_id());
        $this->assertIsArray($facts);
        $this->assertEqualsCanonicalizing([$class["term_id"], $other["term_id"]], $facts["class_ids"]);
        $this->assertCount(2, $facts["variants"]);
        $mapped = (new Shipping_Mapper())->item($facts);
        $this->assertSame(["codes" => [], "completeness" => "PARTIAL"], $mapped["rate_inputs"]["classes"]);
    }

    public function test_cached_option_miss_cannot_steal_an_active_source_lock()
    {
        $first = new Shipping_Publisher();
        $other = new Shipping_Publisher();
        $name = "ahpc_shipping_lock_" . self::SOURCE;
        $this->assertTrue($first->acquire_source_lock(self::SOURCE));
        try {
            $lock = get_option($name);
            // Simulate another PHP request that cached absence before the
            // first worker inserted its lease. add_option() would upsert it.
            $missing = wp_cache_get("notoptions", "options");
            $missing = is_array($missing) ? $missing : [];
            $missing[$name] = true;
            wp_cache_set("notoptions", $missing, "options");
            $this->assertFalse($other->acquire_source_lock(self::SOURCE));
            $this->assertSame($lock, get_option($name));
        } finally {
            $first->release_source_lock();
            $other->release_source_lock();
        }
    }

    public static function stale_checkpoint_caches()
    {
        return [[false], [true]];
    }

    /** @dataProvider stale_checkpoint_caches */
    public function test_source_lock_reads_latest_checkpoint_despite_cached_state($cached_miss)
    {
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $old = $publisher->get_status(self::SOURCE);
        $current = $this->tick();
        $this->assertSame("default", $current["phase"]);
        $name = Shipping_Publisher::state_option(self::SOURCE);
        if ($cached_miss) {
            $missing = wp_cache_get("notoptions", "options");
            $missing = is_array($missing) ? $missing : [];
            $missing[$name] = true;
            wp_cache_set("notoptions", $missing, "options");
        } else {
            wp_cache_set($name, maybe_serialize($old), "options");
        }
        $this->assertTrue($publisher->queue(self::SOURCE));
        $queued = $publisher->get_status(self::SOURCE);
        $this->assertSame($current["wake"], $queued["wake"]);
        $this->assertSame($current["phase"], $queued["phase"]);
        $this->assertSame($current["profile_id"], $queued["profile_id"]);
        $this->assertTrue($queued["refresh_requested"]);
    }

    public function test_confirmed_local_removal_clears_only_owned_components_without_observation()
    {
        $this->zone();
        $product = $this->product();
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertWPError($publisher->queue_item_removal(self::SOURCE, $product->get_id()));
        $product->set_status("trash");
        $product->save();
        $this->assertTrue($publisher->queue_item_removal(self::SOURCE, $product->get_id()));
        $this->assertSame("complete", $this->finish()["status"]);
        foreach (["selection", "inputs"] as $component) {
            $body = $this->listing_bodies[$product->get_id()][$component];
            $this->assertArrayNotHasKey("observation", $body["import"]);
            $this->assertSame(["state" => "CLEAR"], $body["delivery"]["value"]["selection" === $component ? "selection" : "rateInputs"]);
            $this->assertSame(["state" => "UNCHANGED"], $body["delivery"]["value"]["shipping"]);
        }
    }

    public function test_failed_configuration_preserves_profile_and_old_observation_request()
    {
        $this->zone();
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $profile = $this->profile;
        $count = count($this->writes());
        update_option("woocommerce_currency", "XXX");
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("failed", $this->finish()["status"]);
        $this->assertSame($profile, $this->profile);
        $this->assertCount($count, $this->writes());
    }

    public static function removal_progress()
    {
        return [[0], [1]];
    }

    /** @dataProvider removal_progress */
    public function test_restored_product_cancels_unsent_removals_and_reacquires_both_components($completed_removals)
    {
        $product = $this->product();
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $product->set_status("trash");
        $product->save();
        $this->assertTrue($publisher->queue_item_removal(self::SOURCE, $product->get_id()));
        for ($index = 0; $index < $completed_removals; $index++) {
            $this->tick();
        }
        $product->set_status("publish");
        $product->set_virtual(true);
        $product->save();
        $state = $this->finish();
        $this->assertSame("complete", $state["status"]);
        $this->assertSame([], $state["removals"]);
        $this->assertSame([], $state["targets"]);
        $body = $this->listing_bodies[$product->get_id()];
        $this->assertSame(["state" => "SET", "value" => ["kind" => "SUPPRESS_INHERITANCE"]], $body["selection"]["delivery"]["value"]["selection"]);
        $this->assertSame("SET", $body["inputs"]["delivery"]["value"]["rateInputs"]["state"]);
        $this->assertArrayHasKey("observation", $body["inputs"]["import"]);
    }

    public function test_cursor_survives_earlier_deletion_and_run_has_fixed_upper_bound()
    {
        $first = $this->product();
        $second = $this->product();
        $this->assertTrue((new Shipping_Publisher())->queue(self::SOURCE));
        $this->tick();
        $this->tick();
        $this->tick();
        $this->tick();
        $first->delete(true);
        $later = $this->product();
        $state = $this->finish();
        $this->assertSame("complete", $state["status"]);
        $this->assertArrayHasKey($second->get_id(), $this->listing_bodies);
        $this->assertArrayNotHasKey($later->get_id(), $this->listing_bodies);
    }

    public function test_scoped_refresh_reuses_initial_mapper_profile_identity_and_ordering()
    {
        $this->zone();
        $product = $this->product();
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $initial = $this->listing_bodies[$product->get_id()];
        $this->assertTrue($publisher->queue_items(self::SOURCE, [$product->get_id()]));
        $this->assertSame("complete", $this->finish()["status"]);
        $scoped = $this->listing_bodies[$product->get_id()];
        foreach (["selection", "inputs"] as $component) {
            $this->assertSame($initial[$component]["delivery"], $scoped[$component]["delivery"]);
            $this->assertGreaterThan($initial[$component]["import"]["sequence"], $scoped[$component]["import"]["sequence"]);
            $this->assertNotSame($initial[$component]["import"]["requestKey"], $scoped[$component]["import"]["requestKey"]);
        }
        $this->assertSame(self::PROFILE, $publisher->get_status(self::SOURCE)["profile_id"]);
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_unavailable_method_plugin_remains_unknown_in_local_acquisition()
    {
        $id = WC_Data_Store::load("shipping-zone")->add_method(0, "unavailable_plugin", 1);
        $this->assertGreaterThan(0, $id);
        $capture = (new Shipping_Acquisition())->configuration();
        $this->assertIsArray($capture);
        $methods = end($capture["configuration"]["zones"])["methods"];
        $this->assertSame("unavailable_plugin", $methods[0]["id"]);
        $this->assertFalse($methods[0]["core"]);
        $mapped = (new Shipping_Mapper())->profile($capture["configuration"]);
        $service = end($mapped["content"]["terms"]["shipping"]["coverage"]["zones"])["services"][0];
        $this->assertNull($service["eligibility"]);
        $this->assertSame("EXTERNALLY_CALCULATED", $service["rate"]["price"]["kind"]);
    }

    public static function runtime_hooks()
    {
        return [["woocommerce_package_rates"], ["woocommerce_get_zone_criteria"], ["woocommerce_shipping_method_add_rate"]];
    }

    /** @dataProvider runtime_hooks */
    public function test_real_runtime_filters_preserve_uncertainty_beside_reviewed_core_hooks($hook)
    {
        $this->zone();
        add_filter($hook, "__return_empty_array");
        try {
            $capture = (new Shipping_Acquisition())->configuration();
            $this->assertIsArray($capture);
            $this->assertTrue($capture["configuration"]["runtime_filters"]);
            $mapped = (new Shipping_Mapper())->profile($capture["configuration"]);
            $service = $mapped["content"]["terms"]["shipping"]["coverage"]["zones"][0]["services"][0];
            $this->assertNull($service["eligibility"]);
            $this->assertSame("EXTERNALLY_CALCULATED", $service["rate"]["price"]["kind"]);
        } finally {
            remove_filter($hook, "__return_empty_array");
        }
    }

    public function test_equal_manual_profile_takeover_stops_import_without_reclaiming_identity()
    {
        $this->zone();
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $profile = $this->profile;
        $this->ownership["profile"] = true;
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("needs_review", $this->finish()["status"]);
        $this->assertSame($profile, $this->profile);
        $this->assertSame("SHIPPING_IMPORT_OWNERSHIP_CONFLICT", $publisher->get_status(self::SOURCE)["last_error"]);
    }

    public function test_retired_profile_is_restored_then_reobserved_with_the_same_identity()
    {
        $this->zone();
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $this->profile["lifecycle"] = "RETIRED";
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $restores = array_values(array_filter($this->writes(), static function ($request) { return str_ends_with($request->getUri()->getPath(), "/restore"); }));
        $this->assertCount(1, $restores);
        $this->assertArrayNotHasKey("observation", json_decode((string) $restores[0]->getBody(), true)["import"]);
        $this->assertSame(self::PROFILE, $publisher->get_status(self::SOURCE)["profile_id"]);
        $this->assertSame("ACTIVE", $this->profile["lifecycle"]);
    }
}
