<?php
/**
 * Local WooCommerce acquisition, scheduling and typed publication integration.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Shipping_Acquisition;
use AuraHistoria\PartnerConnect\Shipping_Mapper;
use AuraHistoria\PartnerConnect\Shipping_Publisher;
use AuraHistoria\PartnerConnect\Shipping_Sync;
use AuraHistoria\PartnerConnect\Plugin;
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
    private $default_profile = null;
    private $missing = [];
    private $ownership = [];
    private $lose_response = false;
    private $conflict_once = false;
    private $unreachable = [];
    private $lose_delivery = false;
    private $check_versions = false;
    private $reject = [];
    private $zones = [];
    private $products = [];
    private $classes = [];
    private $receipts = [];
    private $withdrawn = [];

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
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);
        add_filter("ahpc_backend_base_url", [$this, "base_url"]);
        add_filter("ahpc_backend_guzzle_client", [$this, "http_client"]);
        add_filter("pre_http_request", [$this, "block_outbound"], 10, 3);
        add_filter("pre_http_request", [$this, "catalog"], 20, 3);
    }

    public function tearDown(): void
    {
        remove_filter("ahpc_backend_base_url", [$this, "base_url"]);
        remove_filter("ahpc_backend_guzzle_client", [$this, "http_client"]);
        remove_filter("pre_http_request", [$this, "block_outbound"], 10);
        remove_filter("pre_http_request", [$this, "catalog"], 20);
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

    /** Signed managed product deliveries: deletion withdraws the catalog listing, an update restores it. */
    public function catalog($preempt, $args, $url)
    {
        if (Webhook_Manager::get_webhook_endpoint_url(self::SOURCE) !== $url) {
            return $preempt;
        }
        $headers = array_change_key_case($args["headers"], CASE_LOWER);
        $this->assertSame(base64_encode(hash_hmac("sha256", $args["body"], "signature-only", true)), $headers["x-wc-webhook-signature"]);
        $this->assertSame("Bearer " . self::TOKEN, $headers["authorization"]);
        $this->withdrawn[json_decode($args["body"], true)["id"]] = "product.deleted" === $headers["x-wc-webhook-topic"];
        return ["headers" => [], "body" => "", "response" => ["code" => 202, "message" => "Accepted"], "cookies" => [], "filename" => null];
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
        if (str_ends_with($path, "/ingestion-configurations/woocommerce") && "PUT" === $method) {
            return new Response(204);
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
            $content = str_ends_with($path, "/restore") || "DELETE" === $method ? $this->profile["content"] : $body["content"];
            $this->profile = ["shippingProfileId" => self::PROFILE, "listingSourceId" => self::SOURCE, "managementRevision" => $revision, "termsRevision" => $revision, "lifecycle" => "DELETE" === $method ? "RETIRED" : "ACTIVE", "content" => $content];
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
                $this->default_profile = "DELETE" === $method ? null : self::PROFILE;
            }
            return $this->reply(["listingSourceId" => self::SOURCE, "revision" => $this->default_revision, "shippingProfileId" => $this->default_profile, "eligibility" => null]);
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
            // A withdrawn listing stays readable, but commands never change or restore it.
            if (!empty($this->withdrawn[$id])) {
                return $this->reply(["error" => "PRODUCT_LISTING_WITHDRAWN"], 409);
            }
            if (!empty($this->unreachable[$id])) {
                throw new ConnectException("write not received", $request);
            }
            $component = "UNCHANGED" !== $body["delivery"]["value"]["selection"]["state"] ? "selection" : "inputs";
            if (!empty($this->ownership[$component])) {
                return $this->reply(["error" => "SHIPPING_IMPORT_OWNERSHIP_CONFLICT"], 409);
            }
            if (($body["delivery"]["value"]["selection"]["value"]["kind"] ?? null) === ($this->reject[$id] ?? false)) {
                return $this->reply(["error" => "VALIDATION_FAILED"], 422);
            }
            if ($this->check_versions && $body["expectedVersion"] !== $version) {
                return $this->reply(["error" => "SHIPPING_IMPORT_OUT_OF_ORDER"], 409);
            }
            if ($this->conflict_once) {
                $this->conflict_once = false;
                $this->versions[$id] = $version + 1;
                return $this->reply(["error" => "SHIPPING_IMPORT_OUT_OF_ORDER"], 409);
            }
            $this->listing_bodies[$id][$component] = $body;
            $this->versions[$id] = $version + 1;
            $result = ["productListingId" => self::LISTING, "expectedVersion" => $version + 1, "outcome" => "CHANGED"];
            if ($this->lose_delivery) {
                $this->lose_delivery = false;
                $this->receipts[$key] = [(string) $request->getBody(), $result];
                throw new ConnectException("response lost", $request);
            }
            return $this->reply($result);
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
        update_option("woocommerce_flat_rate_" . $instance . "_settings", ["title" => "Flat rate", "cost" => $cost, "type" => "class", "tax_status" => "none"]);
        return $zone;
    }

    private function tick()
    {
        $publisher = new Shipping_Publisher();
        $state = $publisher->get_status(self::SOURCE);
        $this->assertNotEmpty($state["wake"]);
        as_unschedule_all_actions(Shipping_Publisher::ACTION_HOOK, [self::SOURCE, $state["wake"]], Shipping_Publisher::ACTION_GROUP);
        do_action(Shipping_Publisher::ACTION_HOOK, self::SOURCE, $state["wake"]);
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

    private function item_writes($id)
    {
        return array_values(array_filter($this->writes(), static function ($request) use ($id) { return str_ends_with($request->getUri()->getPath(), "/by-source-id/" . $id . "/delivery"); }));
    }

    /** External and grouped products are outside the supported shipping types. */
    private function unsupported($product)
    {
        $product->set_name("Unsupported shipping fixture");
        $product->set_status("publish");
        $product->save();
        $this->products[] = $product;
        return $product;
    }

    /** Logical components of deferred entries, optionally only those without an exact request. */
    private static function deferred_components(array $state, $request_less = false)
    {
        $entries = array_filter($state["deferred"], static function ($entry) use ($request_less) { return !$request_less || empty($entry["pending"]); });
        return array_values(array_map(static function ($entry) { return $entry["item_id"] . ":" . $entry["component"]; }, $entries));
    }

    private static function import(array $request)
    {
        return end($request["arguments"])["import"];
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

    public function test_refreshes_keep_one_entry_per_unsupported_component_while_supported_items_continue()
    {
        $zone = $this->zone("DE", "80");
        $supported = $this->product();
        $external = $this->unsupported(new WC_Product_External());
        $grouped = $this->unsupported(new WC_Product_Grouped());
        $components = [];
        foreach ([$external, $grouped] as $product) {
            array_push($components, $product->get_id() . ":selection", $product->get_id() . ":inputs");
        }
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish();
        $this->assertSame("partial", $state["status"]);
        $this->assertSame("ahpc_shipping_unsupported_product_type", $state["last_error"]);
        $this->assertSame($components, self::deferred_components($state));
        $sequence = $this->listing_bodies[$supported->get_id()]["inputs"]["import"]["sequence"];
        foreach (["manual", "configuration", "during traversal"] as $refresh) {
            if ("configuration" === $refresh) {
                $methods = $zone->get_shipping_methods();
                $method = reset($methods);
                update_option($method->get_instance_option_key(), ["cost" => "95", "type" => "class", "tax_status" => "none"]);
            }
            $this->assertTrue($publisher->queue(self::SOURCE));
            if ("during traversal" === $refresh) {
                $limit = 60;
                do {
                    $state = $this->tick();
                } while ($state["item_id"] !== $external->get_id() && --$limit > 0);
                // Replay is over; the restarted traversal revisits retained components.
                $this->assertSame([$external->get_id(), 0], [$state["item_id"], $state["retry_remaining"]]);
                $this->assertTrue($publisher->queue(self::SOURCE));
                $this->assertTrue($publisher->get_status(self::SOURCE)["refresh_requested"]);
            }
            $state = $this->finish(100);
            $this->assertSame("partial", $state["status"], $refresh);
            $this->assertSame($components, self::deferred_components($state), $refresh);
            $this->assertSame([null, null, null, null], array_column($state["deferred"], "pending"), $refresh);
            $current = $this->listing_bodies[$supported->get_id()]["inputs"]["import"]["sequence"];
            $this->assertGreaterThan($sequence, $current, $refresh);
            $sequence = $current;
        }
        $this->assertSame(9500, $this->profile["content"]["terms"]["shipping"]["coverage"]["zones"][0]["services"][0]["rate"]["price"]["amount"]["amount_minor"]);
        $this->assertArrayNotHasKey($external->get_id(), $this->listing_bodies);
        $this->assertArrayNotHasKey($grouped->get_id(), $this->listing_bodies);
    }

    public function test_uncertain_requests_survive_refreshes_and_rotation_before_current_facts_are_reacquired()
    {
        $this->zone();
        $supported = $this->product();
        $uncertain = $this->product();
        $external = $this->unsupported(new WC_Product_External());
        $this->check_versions = true;
        $this->unreachable[$uncertain->get_id()] = true;
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish();
        $this->assertSame("partial", $state["status"]);
        $components = [];
        foreach ([$uncertain, $external] as $product) {
            array_push($components, $product->get_id() . ":selection", $product->get_id() . ":inputs");
        }
        $this->assertSame($components, self::deferred_components($state));
        $retained = array_values(array_filter(array_column($state["deferred"], "pending")));
        $this->assertSame(["selection", "inputs"], array_column($retained, "kind"));
        $settings = get_option(Webhook_Manager::OPTION_SETTINGS);
        $settings["access_token"] = self::TOKEN . "rotated";
        update_option(Webhook_Manager::OPTION_SETTINGS, $settings, false);
        $sent = count($this->item_writes($uncertain->get_id()));
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish(100);
        // Replay resent each exact request with the current token. Traversal
        // sent nothing new for components still waiting on them; no component
        // took another slot.
        $this->assertSame("partial", $state["status"]);
        $this->assertSame($components, self::deferred_components($state));
        $this->assertSame(array_map("wp_json_encode", $retained), array_map("wp_json_encode", array_values(array_filter(array_column($state["deferred"], "pending")))));
        $writes = array_slice($this->item_writes($uncertain->get_id()), $sent);
        $this->assertCount(2 * Shipping_Publisher::MAX_ATTEMPTS, $writes);
        foreach ($writes as $write) {
            $this->assertSame("Bearer " . self::TOKEN . "rotated", $write->getHeaderLine("Authorization"));
        }
        foreach ($retained as $request) {
            $bodies = $this->bodies_for($uncertain->get_id(), $request);
            $this->assertCount(2 * Shipping_Publisher::MAX_ATTEMPTS, $bodies);
            $this->assertSame(array_fill(0, count($bodies), $bodies[0]), $bodies);
        }
        // A scoped refresh does not acquire a component behind its retained request either.
        $sent = count($this->item_writes($uncertain->get_id()));
        $this->assertTrue($publisher->queue_items(self::SOURCE, [$uncertain->get_id()]));
        $state = $this->finish();
        $this->assertSame([], $state["targets"]);
        $this->assertSame($components, self::deferred_components($state));
        $this->assertCount($sent, $this->item_writes($uncertain->get_id()));
        $this->unreachable = [];
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish(100);
        $this->assertSame("partial", $state["status"]);
        $this->assertSame(array_slice($components, 2), self::deferred_components($state));
        // The selection request committed on its exact replay, which made the
        // retained inputs request stale: it was rejected and that component was
        // reacquired. Only then did traversal acquire current facts for both.
        $writes = array_map(static function ($write) { return json_decode((string) $write->getBody(), true)["import"]; }, array_slice($this->item_writes($uncertain->get_id()), $sent));
        $this->assertCount(5, $writes);
        $this->assertSame([self::import($retained[0])["requestKey"], self::import($retained[1])["requestKey"]], array_column(array_slice($writes, 0, 2), "requestKey"));
        $this->assertCount(5, array_unique(array_column($writes, "requestKey")));
        $sequences = array_column($writes, "sequence");
        $ordered = $sequences;
        sort($ordered);
        $this->assertSame($ordered, $sequences);
        $this->assertCount(5, array_unique($sequences));
        $this->assertSame(array_slice($sequences, 3), [$this->listing_bodies[$uncertain->get_id()]["selection"]["import"]["sequence"], $this->listing_bodies[$uncertain->get_id()]["inputs"]["import"]["sequence"]]);
        $this->assertArrayHasKey("inputs", $this->listing_bodies[$supported->get_id()]);
    }

    /** Every sent body carrying the exact request's key. */
    private function bodies_for($id, array $request)
    {
        $key = self::import($request)["requestKey"];
        $bodies = array_map(static function ($write) { return (string) $write->getBody(); }, $this->item_writes($id));
        return array_values(array_filter($bodies, static function ($body) use ($key) { return json_decode($body, true)["import"]["requestKey"] === $key; }));
    }

    public function test_interrupted_replay_keeps_its_entry_when_duplicate_checkpoints_are_compacted()
    {
        $this->zone();
        $listed = $this->product();
        $external = $this->unsupported(new WC_Product_External());
        $this->missing[$listed->get_id()] = true;
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish();
        $components = [$listed->get_id() . ":selection", $listed->get_id() . ":inputs", $external->get_id() . ":selection", $external->get_id() . ":inputs"];
        $this->assertSame($components, self::deferred_components($state));
        // The catalog listing now exists, but the first replayed write loses its response.
        $this->missing = [];
        $this->lose_delivery = true;
        $this->assertTrue($publisher->queue(self::SOURCE));
        $limit = 10;
        do {
            $state = $this->tick();
        } while (empty($state["pending"]) && --$limit > 0);
        $this->assertTrue($state["replaying"]);
        $this->assertSame([$listed->get_id(), "selection"], [$state["pending"]["item_id"], $state["pending"]["kind"]]);
        $in_flight = $state["pending"];
        // The worker stops before its retry runs, on a checkpoint carrying the
        // duplicate request-less entries that release 0.3.0 accumulated.
        as_unschedule_all_actions(Shipping_Publisher::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP);
        $state["deferred"] = array_merge($state["deferred"], $state["deferred"]);
        update_option(Shipping_Publisher::state_option(self::SOURCE), $state, false);
        $this->assertTrue($publisher->queue(self::SOURCE));
        $resumed = $publisher->get_status(self::SOURCE);
        $this->assertSame($components, self::deferred_components($resumed));
        $this->assertSame(4, $resumed["retry_remaining"]);
        $this->assertSame(wp_json_encode($in_flight), wp_json_encode($resumed["pending"]));
        $state = $this->finish(100);
        $this->assertSame("partial", $state["status"]);
        $this->assertSame(array_slice($components, 2), self::deferred_components($state));
        $key = self::import($in_flight)["requestKey"];
        $sent = array_values(array_filter($this->item_writes($listed->get_id()), static function ($write) use ($key) { return str_contains((string) $write->getBody(), $key); }));
        $this->assertCount(2, $sent);
        $this->assertSame((string) $sent[0]->getBody(), (string) $sent[1]->getBody());
        foreach (["selection", "inputs"] as $component) {
            $this->assertGreaterThan(self::import($in_flight)["sequence"], $this->listing_bodies[$listed->get_id()][$component]["import"]["sequence"]);
        }
    }

    public function test_definitely_rejected_request_is_not_retained_so_corrected_facts_publish()
    {
        $this->zone();
        $product = $this->product();
        $this->reject[$product->get_id()] = "EXPLICIT";
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish();
        $this->assertSame("partial", $state["status"]);
        // The backend answered, so nothing committed: keep the component only.
        $this->assertSame([$product->get_id() . ":selection"], self::deferred_components($state));
        $this->assertSame([null], array_column($state["deferred"], "pending"));
        // The merchant corrects the product; only the corrected body is accepted.
        $product->set_virtual(true);
        $product->save();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertSame(["state" => "SET", "value" => ["kind" => "SUPPRESS_INHERITANCE"]], $this->listing_bodies[$product->get_id()]["selection"]["delivery"]["value"]["selection"]);
    }

    public function test_traversal_request_fills_a_request_less_entry_instead_of_taking_a_slot()
    {
        $this->zone();
        $product = $this->product();
        $this->missing[$product->get_id()] = true;
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $limit = 20;
        do {
            $state = $this->tick();
        } while (!$state["deferred"] && --$limit > 0);
        $this->assertSame([$product->get_id() . ":selection"], self::deferred_components($state, true));
        // A refresh requested mid-run restarts traversal without a replay pass.
        // The listing exists now, but writes for it never arrive.
        $this->missing = [];
        $this->unreachable[$product->get_id()] = true;
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish();
        $this->assertSame("partial", $state["status"]);
        $this->assertSame([$product->get_id() . ":selection", $product->get_id() . ":inputs"], self::deferred_components($state));
        $writes = $this->item_writes($product->get_id());
        $this->assertCount(2 * Shipping_Publisher::MAX_ATTEMPTS, $writes);
        foreach ($state["deferred"] as $index => $entry) {
            $this->assertSame(self::import($entry["pending"])["requestKey"], json_decode((string) $writes[$index * Shipping_Publisher::MAX_ATTEMPTS]->getBody(), true)["import"]["requestKey"]);
        }
    }

    public function test_interrupted_replay_at_full_capacity_resends_its_request_without_reacquiring()
    {
        $this->zone();
        $product = $this->product();
        $this->unreachable[$product->get_id()] = true;
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $limit = 10;
        do {
            $state = $this->tick();
        } while (empty($state["pending"]) && --$limit > 0);
        $request = $state["pending"];
        $this->assertSame([$product->get_id(), "selection"], [$request["item_id"], $request["kind"]]);
        // A worker died while replaying this request at the head of a full list.
        // Exact requests retained for other listings fill the rest of the bound.
        as_unschedule_all_actions(Shipping_Publisher::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP);
        $state["deferred"] = [["item_id" => $product->get_id(), "component" => "selection", "pending" => $request]];
        for ($id = 1; count($state["deferred"]) < Shipping_Publisher::MAX_DEFERRED; $id++) {
            $other = $request;
            $other["item_id"] = PHP_INT_MAX - $id;
            $other["arguments"][0] = (string) $other["item_id"];
            $other["arguments"][1]["import"]["requestKey"] = "wc-shipping-retained-" . $id;
            $state["deferred"][] = ["item_id" => $other["item_id"], "component" => "selection", "pending" => $other];
        }
        $state["replaying"] = true;
        update_option(Shipping_Publisher::state_option(self::SOURCE), $state, false);
        $this->assertTrue($publisher->queue(self::SOURCE));
        $queued = $publisher->get_status(self::SOURCE);
        $this->assertEmpty($queued["held_pending"] ?? null);
        $this->assertSame(wp_json_encode($request), wp_json_encode($queued["pending"]));
        $state = $this->finish(150);
        $this->assertSame("partial", $state["status"]);
        // The interrupted request still owns its component's only entry. No
        // other request was acquired for that component while it stayed open.
        $this->assertSame([$product->get_id() . ":selection", $product->get_id() . ":inputs"], self::deferred_components($state));
        $this->assertSame(wp_json_encode($request), wp_json_encode($state["deferred"][0]["pending"]));
        $selection = array_values(array_filter($this->item_writes($product->get_id()), static function ($write) {
            return "UNCHANGED" !== json_decode((string) $write->getBody(), true)["delivery"]["value"]["selection"]["state"];
        }));
        $this->assertCount(1 + Shipping_Publisher::MAX_ATTEMPTS, $selection);
        foreach ($selection as $write) {
            $this->assertSame(self::import($request)["requestKey"], json_decode((string) $write->getBody(), true)["import"]["requestKey"]);
        }
    }

    public function test_full_deferred_capacity_reuses_retained_components_and_stops_at_a_distinct_failure()
    {
        $this->zone();
        $external = $this->unsupported(new WC_Product_External());
        $supported = $this->product();
        $uncertain = $this->product();
        $this->unreachable[$uncertain->get_id()] = true;
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        // Retained work from earlier runs already fills the bound.
        $state = $publisher->get_status(self::SOURCE);
        for ($id = 1; count($state["deferred"]) < Shipping_Publisher::MAX_DEFERRED - 2; $id++) {
            $state["deferred"][] = ["item_id" => PHP_INT_MAX - $id, "component" => "selection", "pending" => null];
        }
        foreach (["selection", "inputs"] as $component) {
            $state["deferred"][] = ["item_id" => $external->get_id(), "component" => $component, "pending" => null];
        }
        update_option(Shipping_Publisher::state_option(self::SOURCE), $state, false);
        $state = $this->finish();
        $this->assertSame("partial", $state["status"]);
        $this->assertCount(Shipping_Publisher::MAX_DEFERRED, $state["deferred"]);
        $this->assertArrayHasKey("inputs", $this->listing_bodies[$supported->get_id()]);
        // A distinct uncertain write needs a new slot: stop at its exact checkpoint.
        $this->assertSame([$uncertain->get_id(), "selection", $supported->get_id()], [$state["item_id"], $state["component"], $state["cursor"]]);
        $sent = array_map(static function ($write) { return (string) $write->getBody(); }, $this->item_writes($uncertain->get_id()));
        $this->assertCount(Shipping_Publisher::MAX_ATTEMPTS, $sent);
        $this->assertCount(1, array_unique($sent));
        $wire = json_decode($sent[0], true)["import"];
        $this->assertSame([self::import($state["pending"])["requestKey"], self::import($state["pending"])["sequence"]], [$wire["requestKey"], $wire["sequence"]]);
        $this->assertNotContains($uncertain->get_id(), array_column($state["deferred"], "item_id"));
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

    public function test_failed_local_recheck_retains_pending_removal_and_schedules_retry()
    {
        global $wpdb;
        $product = $this->product();
        $this->start_ongoing();
        $product->set_status("trash");
        $product->save();
        $this->drain_changes();
        $unavailable = function () {
            return new Client(["handler" => HandlerStack::create(function ($request) {
                if ("GET" !== $request->getMethod()) {
                    return Create::rejectionFor(new ConnectException("offline", $request));
                }
                return Create::promiseFor($this->backend($request));
            })]);
        };
        add_filter("ahpc_backend_guzzle_client", $unavailable, 20);
        try { $pending = $this->tick()["pending"]; } finally { remove_filter("ahpc_backend_guzzle_client", $unavailable, 20); }
        $this->assertSame("remove_selection", $pending["kind"]);
        $failed_read = static function ($sql) use ($wpdb, $product) {
            return str_contains($sql, "SELECT * FROM {$wpdb->posts} WHERE ID = " . $product->get_id())
                ? "SELECT * FROM ahpc_missing_shipping_test_table" : $sql;
        };
        $suppressed = $wpdb->suppress_errors(true);
        add_filter("query", $failed_read);
        try { $state = $this->tick(); } finally {
            remove_filter("query", $failed_read);
            $wpdb->suppress_errors($suppressed);
        }
        $this->assertSame($pending, $state["pending"]);
        $this->assertSame("ahpc_shipping_removal_not_confirmed", $state["last_error"]);
        $this->assertTrue(as_has_scheduled_action(Shipping_Publisher::ACTION_HOOK, [self::SOURCE, $state["wake"]], Shipping_Publisher::ACTION_GROUP));
        $this->assertSame("complete", $this->finish()["status"]);
    }

    public function test_new_product_auto_draft_does_not_retry_shipping_publication()
    {
        $this->start_ongoing();
        $id = wp_insert_post(["post_type" => "product", "post_status" => "auto-draft", "post_title" => "Auto Draft"]);
        $this->assertGreaterThan(0, $id);
        $this->products[] = wc_get_product($id);
        $this->missing[$id] = true;
        $this->assertTrue(Shipping_Publisher::confirmed_removed($id));

        $this->drain_changes();
        $state = $this->finish();
        $this->assertSame("complete", $state["status"]);
        $this->assertSame([], $state["deferred"]);
        $this->assertSame("", $state["last_error"]);
        $this->assertArrayNotHasKey($id, $this->listing_bodies);
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_rescheduling_a_published_product_clears_owned_shipping_until_republication()
    {
        $product = $this->product();
        $this->start_ongoing();
        $product->set_date_created(time() + DAY_IN_SECONDS);
        $product->set_status("future");
        $product->save();
        $this->assertSame("future", get_post_status($product->get_id()));
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        foreach (["selection", "inputs"] as $component) {
            $field = "selection" === $component ? "selection" : "rateInputs";
            $this->assertSame("CLEAR", $this->listing_bodies[$product->get_id()][$component]["delivery"]["value"][$field]["state"]);
        }
        $product->set_date_created(time());
        $product->set_status("publish");
        $product->save();
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertSame("SET", $this->listing_bodies[$product->get_id()]["selection"]["delivery"]["value"]["selection"]["state"]);
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

    public function test_trash_after_catalog_withdrawal_retains_refused_cleanup_while_unrelated_item_progresses()
    {
        $this->zone();
        $trashed = $this->product();
        $id = $trashed->get_id();
        update_option(Webhook_Manager::OPTION_WEBHOOK_USER_ID, get_current_user_id(), false);
        $this->assertTrue((new Webhook_Manager())->sync_webhooks());
        $this->start_ongoing();
        $member = Shipping_Sync::MEMBER_PREFIX . self::SOURCE . "_" . $id;
        $this->assertSame((string) $id, get_option($member));
        $imported = $this->listing_bodies[$id];
        // An unrelated product waits inside a requested full traversal.
        $unrelated = $this->product();
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        // Real Trash: the managed product.deleted delivery withdraws the listing first.
        $this->catalog_request(static function () use ($trashed) { $trashed->delete(false); });
        $this->assertSame("trash", get_post_status($id));
        $this->assertTrue($this->withdrawn[$id]);
        $sent = count($this->item_writes($id));
        $this->drain_changes();
        $state = $this->finish();
        $this->assertSame("complete", $state["status"]);
        $this->assertSame([], $state["removals"]);
        $this->assertSame(0, $state["conflicts"]);
        $this->assertSame(["state" => "SET", "value" => ["kind" => "EXPLICIT", "profile_id" => self::PROFILE]], $this->listing_bodies[$unrelated->get_id()]["selection"]["delivery"]["value"]["selection"]);
        $this->assertSame("SET", $this->listing_bodies[$unrelated->get_id()]["inputs"]["delivery"]["value"]["rateInputs"]["state"]);
        // One refused clear, not a revision-conflict loop. Nothing claims it
        // committed: the identity stays retained with an explicit marker.
        $refused = array_slice($this->item_writes($id), $sent);
        $this->assertCount(1, $refused);
        $this->assertSame(["state" => "CLEAR"], json_decode((string) $refused[0]->getBody(), true)["delivery"]["value"]["selection"]);
        $this->assertSame($imported, $this->listing_bodies[$id]);
        $this->assertSame($id . Shipping_Sync::MEMBER_WITHDRAWN, get_option($member));
        $this->assertSame(1, (new Shipping_Sync())->status()["withdrawn"]);
        $this->assertTrue($this->withdrawn[$id]);
        foreach ($this->requests as $request) {
            $this->assertStringNotContainsString("/product-listings/async", (string) $request->getUri());
        }
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
        // Periodic reconciliation does not resend the refused clear.
        $binding = Shipping_Sync::binding();
        do_action(Shipping_Sync::RECONCILE_HOOK, $binding["source"], $binding["generation"]);
        foreach (as_get_scheduled_actions(["hook" => Shipping_Sync::SWEEP_HOOK, "group" => Shipping_Publisher::ACTION_GROUP, "status" => ActionScheduler_Store::STATUS_PENDING]) as $action) {
            do_action_ref_array(Shipping_Sync::SWEEP_HOOK, $action->get_args());
        }
        $this->assertSame(["configuration"], $this->dirty_scopes());
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertCount($sent + 1, $this->item_writes($id));
        // Restore from Trash: the product.updated delivery restores the listing,
        // and shipping reacquires both components.
        $this->catalog_request(static function () use ($id) { wp_untrash_post($id); });
        $this->products[] = wc_get_product($id);
        $this->assertSame("publish", get_post_status($id));
        $this->assertFalse($this->withdrawn[$id]);
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        $body = $this->listing_bodies[$id];
        $this->assertSame(["state" => "SET", "value" => ["kind" => "EXPLICIT", "profile_id" => self::PROFILE]], $body["selection"]["delivery"]["value"]["selection"]);
        $this->assertGreaterThan($imported["inputs"]["import"]["sequence"], $body["inputs"]["import"]["sequence"]);
        $this->assertArrayHasKey("observation", $body["inputs"]["import"]);
        $this->assertSame((string) $id, get_option($member));
        $this->assertSame(0, (new Shipping_Sync())->status()["withdrawn"]);
    }

    public function test_publication_to_a_withdrawn_listing_waits_like_catalog_lag_without_stalling_the_source()
    {
        $this->zone();
        $lagging = $this->product();
        $other = $this->product();
        // Restored locally; the catalog has not applied the restoration yet.
        $this->withdrawn[$lagging->get_id()] = true;
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->queue(self::SOURCE));
        $state = $this->finish();
        $this->assertSame("partial", $state["status"]);
        $this->assertSame(0, $state["conflicts"]);
        $this->assertSame([$lagging->get_id() . ":selection", $lagging->get_id() . ":inputs"], self::deferred_components($state));
        $this->assertSame([null, null], array_column($state["deferred"], "pending"));
        $this->assertArrayHasKey("inputs", $this->listing_bodies[$other->get_id()]);
        // A lifecycle rejection retries boundedly and never restarts the shared profile.
        $this->assertCount(2 * Shipping_Publisher::MAX_ATTEMPTS, $this->item_writes($lagging->get_id()));
        $this->assertSame(1, $this->profile["managementRevision"]);
        $this->withdrawn = [];
        $this->assertTrue($publisher->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertArrayHasKey("inputs", $this->listing_bodies[$lagging->get_id()]);
    }

    public function test_failed_read_before_a_removal_request_fails_closed_without_touching_traversal()
    {
        global $wpdb;
        $product = $this->product();
        $id = $product->get_id();
        $this->start_ongoing();
        $sent = count($this->item_writes($id));
        $product->set_status("trash");
        $product->save();
        $this->drain_changes();
        $failed_read = static function ($sql) use ($wpdb, $id) {
            return str_contains($sql, "SELECT * FROM {$wpdb->posts} WHERE ID = " . $id) ? "SELECT * FROM ahpc_missing_shipping_test_table" : $sql;
        };
        $suppressed = $wpdb->suppress_errors(true);
        add_filter("query", $failed_read);
        try { $state = $this->finish(); } finally {
            remove_filter("query", $failed_read);
            $wpdb->suppress_errors($suppressed);
        }
        // Absence was never confirmed: the unsent removal owns its failure.
        $this->assertSame("failed", $state["status"]);
        $this->assertSame("ahpc_shipping_removal_not_confirmed", $state["last_error"]);
        $this->assertSame([["id" => $id, "component" => "selection"]], $state["removals"]);
        $this->assertSame([], $state["deferred"]);
        $this->assertSame([0, "selection"], [$state["item_id"], $state["component"]]);
        $this->assertCount($sent, $this->item_writes($id));
        $this->assertTrue((new Shipping_Publisher())->queue(self::SOURCE));
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertSame("CLEAR", $this->listing_bodies[$id]["inputs"]["delivery"]["value"]["rateInputs"]["state"]);
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

    /** One request with the managed webhooks enqueued and delivered at its end. */
    private function catalog_request(callable $change)
    {
        $webhooks = [];
        foreach (WC_Data_Store::load("webhook")->get_webhooks_ids("active") as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $webhook->enqueue();
            $webhooks[] = $webhook;
        }
        $GLOBALS["wc_queued_webhooks"] = [];
        add_filter("woocommerce_webhook_deliver_async", "__return_false");
        try {
            $change();
            wc_webhook_execute_queue();
            // WooCommerce saves a delivered webhook; the next request's boot resynchronizes it.
            $this->assertTrue((new Webhook_Manager())->maybe_sync_webhooks());
        } finally {
            remove_filter("woocommerce_webhook_deliver_async", "__return_false");
            $GLOBALS["wc_queued_webhooks"] = [];
            foreach ($webhooks as $webhook) {
                foreach ($webhook->get_hooks() as $hook) {
                    remove_action($hook, [$webhook, "process"]);
                }
            }
        }
    }

    private function drain_changes()
    {
        $binding = Shipping_Sync::binding();
        as_unschedule_all_actions(Shipping_Sync::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP);
        do_action(Shipping_Sync::ACTION_HOOK, $binding["source"], $binding["generation"]);
    }

    private function start_ongoing()
    {
        (new Shipping_Sync())->initialize(true);
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
    }

    private function dirty_scopes()
    {
        global $wpdb;
        $binding = Shipping_Sync::binding();
        $prefix = Shipping_Sync::DIRTY_PREFIX . $binding["source"] . "_" . $binding["generation"] . "_";
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like($prefix) . "%"));
        return array_map(static function ($name) use ($prefix) { return substr($name, strlen($prefix)); }, $names);
    }

    public static function refresh_triggers()
    {
        return array_map(static function ($trigger) { return [$trigger]; }, ["zone_create", "zone_order", "zone_locations", "zone_delete", "fallback_method", "method_delete", "method_settings", "method_enablement", "rest_method_order", "class_create", "class_edit", "class_delete", "class_assignment", "product_virtual", "unit", "currency", "country_restriction", "tax"]);
    }

    /** @dataProvider refresh_triggers */
    public function test_local_trigger_to_refresh_scope_matrix($trigger)
    {
        $zone = $this->zone();
        $product = $this->product();
        $term = wp_insert_term("Existing shipping class", "product_shipping_class");
        $class_id = (int) $term["term_id"];
        $this->classes[] = $class_id;
        $methods = $zone->get_shipping_methods();
        $method = reset($methods);
        if (in_array($trigger, ["method_enablement", "rest_method_order"], true)) {
            // Core's REST response expects the complete settings form, as
            // saved by the admin, including dynamically added class fields.
            $current = new WC_Shipping_Flat_Rate($method->get_instance_id());
            $options = get_option($method->get_instance_option_key());
            foreach ($current->get_instance_form_fields() as $key => $field) {
                $options[$key] = $options[$key] ?? ($field["default"] ?? "");
            }
            update_option($method->get_instance_option_key(), $options);
        }
        $this->start_ongoing();
        $before = count($this->requests);
        $scope = "configuration";
        switch ($trigger) {
            case "zone_create": $this->zone("FR"); break;
            case "zone_order": $zone->set_zone_order(10); $zone->save(); break;
            case "zone_locations": $zone->set_zone_locations([]); $zone->add_location("FR", "country"); $zone->save(); break;
            case "zone_delete": $zone->delete(true); break;
            case "fallback_method": WC_Shipping_Zones::get_zone(0)->add_shipping_method("free_shipping"); break;
            case "method_delete": $zone->delete_shipping_method($method->get_instance_id()); break;
            case "method_settings": update_option($method->get_instance_option_key(), ["cost" => "95"]); break;
            case "method_enablement":
            case "rest_method_order":
                $request = new WP_REST_Request("PUT", "/wc/v3/shipping/zones/" . $zone->get_id() . "/methods/" . $method->get_instance_id());
                $request->set_param("method_enablement" === $trigger ? "enabled" : "order", "method_enablement" === $trigger ? false : 5);
                $response = rest_get_server()->dispatch($request);
                $this->assertLessThan(400, $response->get_status(), wp_json_encode($response->get_data()));
                break;
            case "class_create": $term = wp_insert_term("New shipping class", "product_shipping_class"); $this->classes[] = (int) $term["term_id"]; break;
            case "class_edit": wp_update_term($class_id, "product_shipping_class", ["slug" => "changed-class"]); break;
            case "class_delete": wp_delete_term($class_id, "product_shipping_class"); break;
            case "class_assignment": wp_set_object_terms($product->get_id(), [$class_id], "product_shipping_class"); $scope = "item_" . $product->get_id(); break;
            case "product_virtual": $product->set_virtual(true); $product->save(); $scope = "item_" . $product->get_id(); break;
            case "unit": update_option("woocommerce_weight_unit", "kg" === get_option("woocommerce_weight_unit") ? "lbs" : "kg"); break;
            case "currency": update_option("woocommerce_currency", "USD"); break;
            case "country_restriction": update_option("woocommerce_ship_to_countries", "specific"); break;
            case "tax": update_option("woocommerce_calc_taxes", "yes"); break;
        }
        $this->assertSame($before, count($this->requests), "Save hooks must not perform remote I/O.");
        $this->assertContains($scope, $this->dirty_scopes());
        // Currency changes also gate the existing signed webhook configuration.
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_method_price_only_hook_flows_through_scheduler_mapper_and_typed_requests()
    {
        $zone = $this->zone("DE", "80");
        $product = $this->product();
        $this->start_ongoing();
        $this->ownership = ["selection" => true];
        $before = count($this->requests);
        $methods = $zone->get_shipping_methods();
        $method = reset($methods);
        update_option($method->get_instance_option_key(), ["cost" => "95", "tax_status" => "none"]);
        $this->assertSame($before, count($this->requests));
        $this->assertTrue(as_has_scheduled_action(Shipping_Sync::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP));
        $this->drain_changes();
        $state = $this->finish();
        $this->assertSame(1, $state["protected"]);
        $price = $this->profile["content"]["terms"]["shipping"]["coverage"]["zones"][0]["services"][0]["rate"]["price"];
        $this->assertSame(9500, $price["amount"]["amount_minor"]);
        $this->assertArrayHasKey("inputs", $this->listing_bodies[$product->get_id()]);
        foreach ($this->listing_bodies[$product->get_id()] as $body) {
            $this->assertSame(["state" => "UNCHANGED"], $body["delivery"]["value"]["collection"]);
        }
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_old_initial_retry_cannot_restore_80_after_hook_refresh_publishes_95()
    {
        $zone = $this->zone("DE", "80");
        (new Shipping_Sync())->initialize(true);
        $this->lose_response = true;
        $this->drain_changes();
        $old = $this->tick();
        $this->assertNotNull($old["pending"]);
        $methods = $zone->get_shipping_methods();
        $method = reset($methods);
        update_option($method->get_instance_option_key(), ["cost" => "95", "tax_status" => "none"]);
        $this->drain_changes();
        $this->assertTrue((new Shipping_Publisher())->queue(self::SOURCE));
        $this->tick();
        $writes = $this->writes();
        $this->assertSame((string) $writes[0]->getBody(), (string) $writes[1]->getBody());
        $this->assertSame("complete", $this->finish()["status"]);
        $before = count($this->requests);
        do_action(Shipping_Publisher::ACTION_HOOK, self::SOURCE, $old["wake"]);
        $this->assertSame($before, count($this->requests));
        $this->assertSame(9500, $this->profile["content"]["terms"]["shipping"]["coverage"]["zones"][0]["services"][0]["rate"]["price"]["amount"]["amount_minor"]);
        $writes = $this->writes();
        $last = json_decode((string) end($writes)->getBody(), true);
        $this->assertGreaterThan($old["pending"]["arguments"][0]["import"]["sequence"], $last["import"]["sequence"]);
    }

    public function test_source_busy_retains_dirty_ticket_and_successful_scoped_work_does_not_scan_catalog()
    {
        $product = $this->product();
        $this->start_ongoing();
        $publisher = new Shipping_Publisher();
        $this->assertTrue($publisher->acquire_source_lock(self::SOURCE));
        $product->set_virtual(true);
        $product->save();
        $this->drain_changes();
        $this->assertContains("item_" . $product->get_id(), $this->dirty_scopes());
        $publisher->release_source_lock();
        $before = count($this->writes());
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertCount($before + 2, $this->writes());
        $this->assertSame([], $this->dirty_scopes());
    }

    public function test_new_hook_during_ticket_acknowledgement_is_not_lost()
    {
        $this->start_ongoing();
        $sync = new Shipping_Sync();
        $sync->configuration_changed();
        $fired = false;
        $filter = static function ($sql) use (&$fired, $sync) {
            if (!$fired && str_starts_with($sql, "DELETE FROM") && str_contains($sql, Shipping_Sync::DIRTY_PREFIX) && str_contains($sql, "option_value")) {
                $fired = true;
                $sync->configuration_changed();
            }
            return $sql;
        };
        add_filter("query", $filter);
        try { $this->drain_changes(); } finally { remove_filter("query", $filter); }
        $this->assertTrue($fired);
        $this->assertSame(["configuration"], $this->dirty_scopes());
    }

    public function test_periodic_reconciliation_finds_missed_settings_and_confirmed_item_removal()
    {
        global $wpdb;
        $zone = $this->zone("DE", "80");
        $product = $this->product();
        $id = $product->get_id();
        $this->start_ongoing();
        $methods = $zone->get_shipping_methods();
        $method = reset($methods);
        // Deliberately bypass notifications to model plugin/direct storage gaps.
        $wpdb->update($wpdb->options, ["option_value" => maybe_serialize(["cost" => "95", "tax_status" => "none"])], ["option_name" => $method->get_instance_option_key()]);
        wp_cache_delete($method->get_instance_option_key(), "options");
        wp_cache_delete("alloptions", "options");
        $wpdb->update($wpdb->posts, ["post_status" => "draft"], ["ID" => $id]);
        clean_post_cache($id);
        $binding = Shipping_Sync::binding();
        do_action(Shipping_Sync::RECONCILE_HOOK, $binding["source"], $binding["generation"]);
        $actions = as_get_scheduled_actions(["hook" => Shipping_Sync::SWEEP_HOOK, "group" => Shipping_Publisher::ACTION_GROUP, "status" => ActionScheduler_Store::STATUS_PENDING]);
        $this->assertNotEmpty($actions);
        foreach ($actions as $action) {
            do_action_ref_array(Shipping_Sync::SWEEP_HOOK, $action->get_args());
        }
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertSame("CLEAR", $this->listing_bodies[$id]["inputs"]["delivery"]["value"]["rateInputs"]["state"]);
        $this->assertFalse(get_option(Shipping_Sync::MEMBER_PREFIX . self::SOURCE . "_" . $id));
        $this->assertSame(9500, $this->profile["content"]["terms"]["shipping"]["coverage"]["zones"][0]["services"][0]["rate"]["price"]["amount"]["amount_minor"]);
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_unavailable_scheduler_retains_visible_dirty_work_without_browser_recovery()
    {
        $this->start_ongoing();
        as_unschedule_all_actions(Shipping_Sync::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP);
        add_filter("pre_as_schedule_single_action", "__return_zero");
        try { (new Shipping_Sync())->configuration_changed(); } finally { remove_filter("pre_as_schedule_single_action", "__return_zero"); }
        $status = (new Shipping_Sync())->status();
        $this->assertGreaterThan(0, $status["oldest_pending"]);
        $this->assertSame("scheduling_failed", $status["error"]);
        (new Shipping_Sync())->initialize();
        $this->assertTrue(as_has_scheduled_action(Shipping_Sync::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP));
    }

    public function test_shutdown_fences_work_and_records_committed_profile_retirement()
    {
        $this->zone();
        $this->start_ongoing();
        (new Shipping_Sync())->configuration_changed();
        $this->drain_changes();
        $old = (new Shipping_Publisher())->get_status(self::SOURCE);
        Plugin::deactivate();
        $this->assertFalse(Shipping_Sync::binding()["enabled"]);
        $this->assertSame("ahpc_shipping_connection_changed", (new Shipping_Publisher())->queue(self::SOURCE)->get_error_code());
        $this->assertFalse(as_has_scheduled_action(Shipping_Sync::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP));
        $this->assertSame("committed", get_option(Shipping_Publisher::OPTION_CLEANUP)["result"]);
        $this->assertSame("RETIRED", $this->profile["lifecycle"]);
        $before = count($this->requests);
        do_action(Shipping_Publisher::ACTION_HOOK, self::SOURCE, $old["wake"]);
        $this->assertSame($before, count($this->requests));
        (new Shipping_Sync())->initialize(true);
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertSame("ACTIVE", $this->profile["lifecycle"]);
        $this->assertNotSame($old["generation"], (new Shipping_Publisher())->get_status(self::SOURCE)["generation"]);
    }

    public function test_unreachable_shutdown_preserves_terms_without_expiry_and_reports_failure()
    {
        $this->zone();
        $this->start_ongoing();
        $profile = $this->profile;
        $block = static function () { return new Client(["handler" => HandlerStack::create(static function ($request) { return Create::rejectionFor(new ConnectException("offline", $request)); })]); };
        add_filter("ahpc_backend_guzzle_client", $block, 20);
        try { Plugin::deactivate(); } finally { remove_filter("ahpc_backend_guzzle_client", $block, 20); }
        $this->assertSame($profile, $this->profile);
        $this->assertSame("unconfirmed", get_option(Shipping_Publisher::OPTION_CLEANUP)["result"]);
        $this->assertFalse(Shipping_Sync::binding()["enabled"]);
        $this->assertStringNotContainsString("expiresAt", wp_json_encode($this->profile));
        $this->assertStringNotContainsString("freshUntil", wp_json_encode($this->profile));
        $this->assertFalse(as_has_scheduled_action(Shipping_Publisher::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP));
    }

    public function test_manual_shipping_repair_requires_capability_and_nonce()
    {
        wp_set_current_user(0);
        $result = ahpc_plugin()->queue_manual_shipping();
        $this->assertSame("ahpc_shipping_forbidden", $result->get_error_code());
        wp_set_current_user(self::factory()->user->create(["role" => "administrator"]));
        $this->expectException(WPDieException::class);
        $_REQUEST["_wpnonce"] = "invalid";
        try { ahpc_plugin()->handle_shipping_request(); } finally { unset($_REQUEST["_wpnonce"]); }
    }

    public function test_variation_changes_and_inherited_class_changes_refresh_only_parent_shipping()
    {
        $variable = new WC_Product_Variable();
        $variable->set_status("publish");
        $variable->set_name("Variable shipping fixture");
        $variable->save();
        $this->products[] = $variable;
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($variable->get_id());
        $variation->set_status("publish");
        $variation->set_regular_price("100");
        $variation->save();
        $this->products[] = $variation;
        WC_Product_Variable::sync($variable);
        $term = wp_insert_term("Inherited shipping class", "product_shipping_class");
        $this->classes[] = (int) $term["term_id"];
        $this->start_ongoing();
        $before = count($this->writes());
        $variable->set_shipping_class_id($term["term_id"]);
        $variable->save();
        $variation->set_virtual(true);
        $variation->save();
        $this->assertSame(["item_" . $variable->get_id()], $this->dirty_scopes());
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertCount($before + 2, $this->writes());
        $this->assertSame("SUPPRESS_INHERITANCE", $this->listing_bodies[$variable->get_id()]["selection"]["delivery"]["value"]["selection"]["value"]["kind"]);
        $this->assertArrayNotHasKey($variation->get_id(), $this->listing_bodies);
        $variation->delete(true);
        $this->assertSame(["item_" . $variable->get_id()], $this->dirty_scopes());
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_connection_change_fences_old_publisher_and_notification_actions()
    {
        $this->zone();
        $this->start_ongoing();
        (new Shipping_Sync())->configuration_changed();
        $this->drain_changes();
        $old_binding = Shipping_Sync::binding();
        $old_state = (new Shipping_Publisher())->get_status(self::SOURCE);
        $settings = get_option(Webhook_Manager::OPTION_SETTINGS);
        $settings["listing_source_id"] = "ls_01jw7j4azge008000000000006";
        update_option(Webhook_Manager::OPTION_SETTINGS, $settings, false);
        $before = count($this->requests);
        do_action(Shipping_Sync::ACTION_HOOK, self::SOURCE, $old_binding["generation"]);
        do_action(Shipping_Publisher::ACTION_HOOK, self::SOURCE, $old_state["wake"]);
        $this->assertSame($before, count($this->requests));
        $this->assertNotSame($old_binding["generation"], Shipping_Sync::generation());
        $this->assertSame("unconfirmed_connection_change", get_option(Shipping_Publisher::OPTION_CLEANUP)["result"]);
        $this->assertSame("yes", get_option(Webhook_Manager::OPTION_NEEDS_SYNC));
    }

    public function test_current_token_rotation_retains_exact_pending_request_with_active_binding()
    {
        $this->zone();
        (new Shipping_Sync())->initialize(true);
        $this->drain_changes();
        $this->lose_response = true;
        $state = $this->tick();
        $generation = Shipping_Sync::generation();
        $settings = get_option(Webhook_Manager::OPTION_SETTINGS);
        $settings["access_token"] = self::TOKEN . "rotated";
        update_option(Webhook_Manager::OPTION_SETTINGS, $settings, false);
        $this->tick();
        $writes = $this->writes();
        $this->assertSame((string) $writes[0]->getBody(), (string) $writes[1]->getBody());
        $this->assertSame("Bearer " . $settings["access_token"], $writes[1]->getHeaderLine("Authorization"));
        $this->assertSame($generation, Shipping_Sync::generation());
        $this->assertNotEmpty($state["pending"]);
    }

    public function test_observed_authorization_failure_stops_jobs_and_requires_new_acquisition()
    {
        $this->start_ongoing();
        (new Shipping_Sync())->configuration_changed();
        $this->drain_changes();
        $generation = Shipping_Sync::generation();
        $deny = static function () { return new Client(["handler" => HandlerStack::create(static function () { return Create::promiseFor(new Response(403, ["Content-Type" => "application/json"], '{"error":"FORBIDDEN"}')); })]); };
        add_filter("ahpc_backend_guzzle_client", $deny, 20);
        try { $this->assertSame("failed", $this->tick()["status"]); } finally { remove_filter("ahpc_backend_guzzle_client", $deny, 20); }
        $this->assertFalse(Shipping_Sync::binding()["enabled"]);
        $this->assertSame("authorization_changed", (new Shipping_Publisher())->get_status(self::SOURCE)["last_error"]);
        $this->assertNotSame($generation, Shipping_Sync::generation());
        (new Shipping_Sync())->initialize(true);
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);
        $this->drain_changes();
        $this->assertSame("complete", $this->finish()["status"]);
        $this->assertNull((new Shipping_Publisher())->get_status(self::SOURCE)["pending"]);
    }

    public function test_lifecycle_cleanup_preserves_manual_equal_value_profile_takeover()
    {
        $this->zone();
        $this->start_ongoing();
        $profile = $this->profile;
        $this->ownership["profile"] = true;
        Plugin::deactivate();
        $this->assertSame($profile, $this->profile);
        $report = get_option(Shipping_Publisher::OPTION_CLEANUP);
        $this->assertSame("protected", $report["profile"]);
        $this->assertSame("unconfirmed", $report["result"]);
        $this->assertStringNotContainsString(self::TOKEN, wp_json_encode($report));
    }

    public function test_private_pending_content_stays_out_of_status_and_unreachable_uninstall_stops_local_work()
    {
        $this->zone();
        $this->start_ongoing();
        $profile = $this->profile;
        (new Shipping_Sync())->configuration_changed();
        $this->drain_changes();
        $this->lose_response = true;
        $this->assertNotNull($this->tick()["pending"]);
        $render = new ReflectionMethod(Plugin::class, "render_shipping_status");
        ob_start();
        $render->invoke(ahpc_plugin(), self::SOURCE);
        $html = ob_get_clean();
        $this->assertStringContainsString("Oldest pending shipping work", $html);
        foreach ([self::TOKEN, "signature-only", "requestKey", "acquiredAt", "expectedRevision", "woocommerce:shipping:core:v1"] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
        // The preceding ambiguous write committed unchanged terms. Uninstall
        // cannot invent a successful remote withdrawal when Aura is offline.
        $profile = $this->profile;
        $block = static function () { return new Client(["handler" => HandlerStack::create(static function ($request) { return Create::rejectionFor(new ConnectException("offline", $request)); })]); };
        add_filter("ahpc_backend_guzzle_client", $block, 20);
        if (!defined("WP_UNINSTALL_PLUGIN")) {
            define("WP_UNINSTALL_PLUGIN", "aura-historia-partner-connect/aura-historia-partner-connect.php");
        }
        try { require dirname(__DIR__) . "/uninstall.php"; } finally { remove_filter("ahpc_backend_guzzle_client", $block, 20); }
        $this->assertSame($profile, $this->profile);
        $this->assertFalse(get_option(Shipping_Sync::OPTION_BINDING));
        $this->assertFalse(get_option(Shipping_Publisher::OPTION_CLEANUP));
        $this->assertSame([], (new Shipping_Publisher())->get_status(self::SOURCE));
        foreach ([Shipping_Sync::ACTION_HOOK, Shipping_Sync::RECONCILE_HOOK, Shipping_Sync::SWEEP_HOOK, Shipping_Publisher::ACTION_HOOK] as $hook) {
            $this->assertFalse(as_has_scheduled_action($hook, null, Shipping_Publisher::ACTION_GROUP));
        }
    }

    public function test_running_reconciliation_cannot_recreate_jobs_after_lifecycle_stop()
    {
        $this->start_ongoing();
        $ids = as_get_scheduled_actions(["hook" => Shipping_Sync::RECONCILE_HOOK, "group" => Shipping_Publisher::ACTION_GROUP,
            "status" => ActionScheduler_Store::STATUS_PENDING, "per_page" => 1], "ids");
        $this->assertCount(1, $ids);
        $stop = static function ($id, $action) {
            if (Shipping_Sync::RECONCILE_HOOK === $action->get_hook()) {
                Shipping_Sync::stop();
            }
        };
        add_action("action_scheduler_after_execute", $stop, 10, 2);
        try { ActionScheduler::runner()->process_action($ids[0]); } finally { remove_action("action_scheduler_after_execute", $stop, 10); }
        $this->assertSame(ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status($ids[0]));
        $this->assertFalse(Shipping_Sync::binding()["enabled"]);
        $this->assertFalse(as_has_scheduled_action(Shipping_Sync::RECONCILE_HOOK, null, Shipping_Publisher::ACTION_GROUP));
        $this->assertFalse(as_has_scheduled_action(Shipping_Sync::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP));
    }
}
