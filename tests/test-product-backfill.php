<?php
/**
 * Integration tests for product-listing backfill admission.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Product_Backfill;
use AuraHistoria\PartnerConnect\Webhook_Manager;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class Test_AHPC_Product_Backfill extends WP_UnitTestCase
{
    const SOURCE_ID = "ls_01arz3ndektsv4rrffq69g5fav";
    const OTHER_SOURCE_ID = "ls_01arz3ndektsv4rrffq69g5faw";
    const TOKEN = "aurahistoria_accesstoken_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";
    const ENDPOINT = "https://example.com/api/v1/listing-sources/" . self::SOURCE_ID . "/product-listings/async";

    /** @var ClientInterface|null */
    private $backend_client;
    /** @var array */
    private $requests = [];
    /** @var int */
    private $admin_user_id;

    public static function wpSetUpBeforeClass($factory)
    {
        if (class_exists("WC_Install")) {
            WC_Install::install();
        }
    }

    public function setUp(): void
    {
        parent::setUp();
        if (class_exists("WC_Install") && !get_option("woocommerce_version")) {
            WC_Install::install();
        }
        $this->admin_user_id = self::factory()->user->create(["role" => "administrator"]);
        wp_set_current_user($this->admin_user_id);
        add_filter("ahpc_backend_base_url", [$this, "backend_url"]);
        add_filter("ahpc_backend_guzzle_client", [$this, "backend_client"], 10, 2);
        (new Webhook_Manager())->delete_webhooks();
        (new Webhook_Manager())->initialize_options();
        delete_option(Product_Backfill::OPTION_BATCH);
        delete_option(Product_Backfill::OPTION_STATE);
        $this->configure_connection();
        $this->mock_responses([$this->admission(1)]);
    }

    public function tearDown(): void
    {
        (new Webhook_Manager())->delete_webhooks();
        (new Product_Backfill())->cancel_backfill();
        delete_option(Product_Backfill::OPTION_BATCH);
        delete_option(Product_Backfill::OPTION_STATE);
        remove_filter("ahpc_backend_base_url", [$this, "backend_url"]);
        remove_filter("ahpc_backend_guzzle_client", [$this, "backend_client"], 10);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function backend_url($url)
    {
        return "https://example.com";
    }

    public function backend_client($client, $base_url)
    {
        return $this->backend_client instanceof ClientInterface ? $this->backend_client : $client;
    }

    private function configure_connection($source_id = self::SOURCE_ID, $token = self::TOKEN)
    {
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $source_id,
            "access_token" => $token,
            "secret" => "test-secret",
        ], false);
    }

    private function admission($accepted, $failures = [], $submission_id = "submission-safe")
    {
        return new Response(202, ["Content-Type" => "application/json"], wp_json_encode([
            "submissionId" => $submission_id,
            "acceptedCount" => $accepted,
            "failures" => $failures,
        ]));
    }

    private function mock_responses(array $responses)
    {
        $this->requests = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));
        $this->backend_client = new Client(["handler" => $stack, "http_errors" => true]);
    }

    private function add_product($name, $status = "publish", $stock = "instock")
    {
        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_status($status);
        $product->set_stock_status($stock);
        $product->save();
        return $product;
    }

    private function request($index = 0)
    {
        $this->assertArrayHasKey($index, $this->requests);
        return $this->requests[$index]["request"];
    }

    private function pending_retry_args(array $snapshot, $page = 1)
    {
        $this->assertGreaterThanOrEqual(1, $snapshot["retry_attempt"]);
        $args = [
            self::SOURCE_ID,
            $page,
            "retry-" . hash("sha256", $snapshot["idempotency_key"] . ":" . $snapshot["retry_attempt"]),
        ];
        $this->assertTrue((bool) as_has_scheduled_action(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP));
        $this->assertGreaterThan(time(), as_next_scheduled_action(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP));

        return $args;
    }

    public function test_schedules_only_valid_listing_sources_and_cancel_cleans_snapshot()
    {
        $backfill = new Product_Backfill();
        $this->assertFalse($backfill->schedule_backfill("not-a-listing-source"));
        $this->assertTrue($backfill->schedule_backfill(self::SOURCE_ID));
        $this->assertTrue($backfill->is_backfill_scheduled());
        update_option(Product_Backfill::OPTION_BATCH, ["payloads" => ["temporary"]], false);
        $backfill->cancel_backfill();
        $this->assertFalse($backfill->is_backfill_scheduled());
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_webhook_deletion_cancels_backfill_and_pending_snapshot()
    {
        $backfill = new Product_Backfill();
        $this->assertTrue($backfill->schedule_backfill(self::SOURCE_ID));
        update_option(Product_Backfill::OPTION_BATCH, ["payloads" => ["temporary"]], false);

        (new Webhook_Manager())->delete_webhooks();

        $this->assertFalse($backfill->is_backfill_scheduled());
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_aborts_without_matching_valid_connection()
    {
        $product = $this->add_product("Guarded");
        try {
            $this->configure_connection(self::OTHER_SOURCE_ID);
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $this->configure_connection(self::SOURCE_ID, "");
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            delete_option(Webhook_Manager::OPTION_SETTINGS);
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $this->assertSame([], $this->requests);
        } finally {
            $product->delete(true);
        }
    }

    public function test_submits_only_published_products_with_listing_contract()
    {
        $published = $this->add_product("Alpha &amp; Beta");
        $published->set_description("<p>Must not leave the store</p>");
        $published->set_short_description("<p>Neither should this short description</p>");
        $published->set_regular_price("42.69");
        $published->save();
        $published_id = (string) $published->get_id();
        $this->assertNotEmpty($published->get_description());
        $this->assertNotEmpty($published->get_short_description());
        $private = $this->add_product("Private", "private");
        $draft = $this->add_product("Draft", "draft");
        wp_set_current_user(0);
        try {
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
        } finally {
            wp_set_current_user($this->admin_user_id);
            $published->delete(true);
            $private->delete(true);
            $draft->delete(true);
        }

        $request = $this->request();
        $this->assertSame(self::ENDPOINT, (string) $request->getUri());
        $this->assertSame("POST", $request->getMethod());
        $this->assertSame("Bearer " . self::TOKEN, $request->getHeaderLine("Authorization"));
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $request->getHeaderLine("Idempotency-Key"));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertCount(1, $body);
        $this->assertSame($published_id, $body[0]["sourceListingId"]);
        $this->assertSame(["text" => "Alpha & Beta", "language" => "en"], $body[0]["title"]);
        $this->assertArrayNotHasKey("description", $body[0]);
        $this->assertArrayNotHasKey("shopsProductId", $body[0]);
        $this->assertSame("IN_STOCK", $body[0]["availability"]);
        $this->assertSame("MONETARY", $body[0]["price"]["type"]);
        $this->assertSame(4269, $body[0]["price"]["amount"]);
        $this->assertSame(get_woocommerce_currency(), $body[0]["price"]["currency"]);
        $this->assertSame([], $body[0]["images"]);
        $this->assertMatchesRegularExpression('~^https?://[^/]+/~', $body[0]["url"]);
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
        $state = (new Product_Backfill())->get_status_details();
        $this->assertSame(Product_Backfill::STATUS_COMPLETE, $state["status"]);
        $this->assertSame("0", $state["permanent_failure_count"]);
    }

    public function test_maps_stock_and_omits_optional_price_and_title()
    {
        $backorder = $this->add_product("Backordered", "publish", "onbackorder");
        $out = $this->add_product("Sold out", "publish", "outofstock");
        $this->mock_responses([$this->admission(2)]);
        $blank_name = static function ($name, $product) use ($out) {
            return $product->get_id() === $out->get_id() ? "" : $name;
        };
        add_filter("woocommerce_product_get_name", $blank_name, 10, 2);
        try {
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $body = json_decode((string) $this->request()->getBody(), true);
            $this->assertSame("BACK_ORDER", $body[0]["availability"]);
            $this->assertSame("OUT_OF_STOCK", $body[1]["availability"]);
            $this->assertSame("Backordered", $body[0]["title"]["text"]);
            $this->assertArrayNotHasKey("title", $body[1]);
            $this->assertArrayNotHasKey("price", $body[0]);
        } finally {
            remove_filter("woocommerce_product_get_name", $blank_name, 10);
            $backorder->delete(true);
            $out->delete(true);
        }
    }

    public function test_valid_decimal_prices_use_minor_units_and_invalid_prices_are_omitted()
    {
        $prices = [
            "0.50", "12.30", "12abc", "1,234.56", "12.3.4", "1e3", "-12.34",
            "999999999999999999999999999999999999", "12.345", "0.001",
            "12.", ".12", "+12", "12 34", "12\n34", "12.3000",
            "000.0100", "0.00",
            substr((string) PHP_INT_MAX, 0, -2) . "." . substr((string) PHP_INT_MAX, -2),
            (string) PHP_INT_MAX . ".01",
        ];
        $products = [];
        $price_by_id = [];
        $this->mock_responses([$this->admission(count($prices))]);
        $price_filter = static function ($price, $product) use (&$price_by_id) {
            return isset($price_by_id[$product->get_id()])
                ? $price_by_id[$product->get_id()]
                : $price;
        };
        try {
            foreach ($prices as $index => $raw_price) {
                $product = $this->add_product("Price item " . $index);
                $product->set_regular_price("9.99");
                $product->save();
                $products[] = $product;
                $price_by_id[$product->get_id()] = $raw_price;
            }
            add_filter("woocommerce_product_get_price", $price_filter, 10, 2);
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $body = json_decode((string) $this->request()->getBody(), true);
            $this->assertCount(count($prices), $body);
            $this->assertSame(50, $body[0]["price"]["amount"]);
            $this->assertSame(1230, $body[1]["price"]["amount"]);
            foreach (array_slice($body, 2, 13) as $payload) {
                $this->assertArrayNotHasKey("price", $payload);
            }
            $this->assertSame(1230, $body[15]["price"]["amount"]);
            $this->assertSame(1, $body[16]["price"]["amount"]);
            $this->assertSame(0, $body[17]["price"]["amount"]);
            $this->assertSame(PHP_INT_MAX, $body[18]["price"]["amount"]);
            $this->assertArrayNotHasKey("price", $body[19]);
        } finally {
            remove_filter("woocommerce_product_get_price", $price_filter, 10);
            foreach ($products as $product) {
                $product->delete(true);
            }
        }
    }

    public function test_eur_price_uses_exact_two_digit_minor_units()
    {
        update_option("woocommerce_currency", "EUR");
        $prices = ["42.69", "42.690", "42.5", "0", "0.50", "42.001"];
        $products = [];
        $by_id = [];
        $price_filter = static function ($price, $product) use (&$by_id) {
            return isset($by_id[$product->get_id()]) ? $by_id[$product->get_id()] : $price;
        };
        $this->mock_responses([$this->admission(count($prices))]);
        try {
            foreach ($prices as $raw_price) {
                $product = $this->add_product("Euro item");
                $product->set_regular_price("9.99");
                $product->save();
                $products[] = $product;
                $by_id[$product->get_id()] = $raw_price;
            }
            add_filter("woocommerce_product_get_price", $price_filter, 10, 2);
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $body = json_decode((string) $this->request()->getBody(), true);
            foreach ([4269, 4269, 4250, 0, 50] as $index => $expected) {
                $this->assertSame($expected, $body[$index]["price"]["amount"]);
            }
            $this->assertArrayNotHasKey("price", $body[5]);
        } finally {
            remove_filter("woocommerce_product_get_price", $price_filter, 10);
            foreach ($products as $product) {
                $product->delete(true);
            }
        }
    }

    public function test_price_minor_units_ignore_woocommerce_display_decimals()
    {
        $product = $this->add_product("Display precision");
        $product->set_regular_price("9.99");
        $product->save();
        $price = static function () { return "12.34"; };
        $decimals = static function () { return 0; };
        add_filter("woocommerce_product_get_price", $price);
        add_filter("wc_get_price_decimals", $decimals);
        try {
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $body = json_decode((string) $this->request()->getBody(), true);
            $this->assertSame(1234, $body[0]["price"]["amount"]);
        } finally {
            remove_filter("woocommerce_product_get_price", $price);
            remove_filter("wc_get_price_decimals", $decimals);
            $product->delete(true);
        }
    }

    public function test_jpy_uses_zero_minor_digits_even_with_two_display_decimals()
    {
        update_option("woocommerce_currency", "JPY");
        $products = [];
        $prices = ["123", "123.00", "00012.000", "123.01", "12.5", "12abc"];
        $by_id = [];
        $price_filter = static function ($price, $product) use (&$by_id) {
            return isset($by_id[$product->get_id()]) ? $by_id[$product->get_id()] : $price;
        };
        $display_decimals = static function () { return 2; };
        $this->mock_responses([$this->admission(count($prices))]);
        try {
            foreach ($prices as $raw_price) {
                $product = $this->add_product("Yen item");
                $product->set_regular_price("9");
                $product->save();
                $products[] = $product;
                $by_id[$product->get_id()] = $raw_price;
            }
            add_filter("woocommerce_product_get_price", $price_filter, 10, 2);
            add_filter("wc_get_price_decimals", $display_decimals);
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $body = json_decode((string) $this->request()->getBody(), true);
            $this->assertSame(["type" => "MONETARY", "currency" => "JPY", "amount" => 123], $body[0]["price"]);
            $this->assertSame(123, $body[1]["price"]["amount"]);
            $this->assertSame(12, $body[2]["price"]["amount"]);
            foreach (array_slice($body, 3) as $payload) {
                $this->assertArrayNotHasKey("price", $payload);
            }
        } finally {
            remove_filter("woocommerce_product_get_price", $price_filter, 10);
            remove_filter("wc_get_price_decimals", $display_decimals);
            foreach ($products as $product) {
                $product->delete(true);
            }
        }
    }

    public function test_omits_availability_when_stock_status_is_unknown_or_missing()
    {
        $unknown = $this->add_product("Unknown stock");
        $missing = $this->add_product("Missing stock");
        $this->mock_responses([$this->admission(2)]);
        $stock_status = static function ($status, $product) use ($unknown, $missing) {
            if ($product->get_id() === $unknown->get_id()) {
                return "unsupported";
            }

            return $product->get_id() === $missing->get_id() ? "" : $status;
        };
        add_filter("woocommerce_product_get_stock_status", $stock_status, 10, 2);
        try {
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $body = json_decode((string) $this->request()->getBody(), true);
            $this->assertCount(2, $body);
            $this->assertArrayNotHasKey("availability", $body[0]);
            $this->assertArrayNotHasKey("availability", $body[1]);
        } finally {
            remove_filter("woocommerce_product_get_stock_status", $stock_status, 10);
            $unknown->delete(true);
            $missing->delete(true);
        }
    }

    public function test_includes_absolute_featured_and_gallery_image_urls()
    {
        $featured_id = wp_insert_attachment([
            "post_title" => "Featured",
            "post_mime_type" => "image/jpeg",
            "guid" => "https://example.com/featured.jpg",
        ], "featured.jpg");
        $gallery_id = wp_insert_attachment([
            "post_title" => "Gallery",
            "post_mime_type" => "image/jpeg",
            "guid" => "https://example.com/gallery.jpg",
        ], "gallery.jpg");
        $product = $this->add_product("With images");
        $product->set_image_id($featured_id);
        $product->set_gallery_image_ids([$gallery_id, $featured_id]);
        $product->save();
        $image_url = static function ($url, $attachment_id) use ($featured_id, $gallery_id) {
            if ($featured_id === $attachment_id) {
                return "https://example.com/featured.jpg";
            }
            return $gallery_id === $attachment_id ? "https://example.com/gallery.jpg" : $url;
        };
        add_filter("wp_get_attachment_url", $image_url, 10, 2);
        try {
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $body = json_decode((string) $this->request()->getBody(), true);
            $this->assertSame([
                "https://example.com/featured.jpg",
                "https://example.com/gallery.jpg",
            ], $body[0]["images"]);
        } finally {
            remove_filter("wp_get_attachment_url", $image_url, 10);
            $product->delete(true);
            wp_delete_attachment($featured_id, true);
            wp_delete_attachment($gallery_id, true);
        }
    }

    public function test_missing_canonical_url_is_a_recorded_permanent_failure()
    {
        $product = $this->add_product("No URL");
        $filter = static function ($url, $post) use ($product) {
            return (int) $post->ID === $product->get_id() ? "/relative-only" : $url;
        };
        add_filter("post_type_link", $filter, 10, 2);
        try {
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $this->assertSame([], $this->requests);
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
            $state = (new Product_Backfill())->get_status_details();
            $this->assertSame(Product_Backfill::STATUS_FAILED, $state["status"]);
            $this->assertSame("1", $state["failed_count"]);
            $this->assertSame("1", $state["permanent_failure_count"]);
            $this->assertStringContainsString("permanent listing failures", $state["last_error"]);
        } finally {
            remove_filter("post_type_link", $filter, 10);
            $product->delete(true);
        }
    }

    public function test_empty_page_completes_without_outbound_request()
    {
        (new Product_Backfill())->process_batch(self::SOURCE_ID, 99999);
        $this->assertSame([], $this->requests);
        $this->assertSame(Product_Backfill::STATUS_COMPLETE, (new Product_Backfill())->get_status_details()["status"]);
    }

    public function test_empty_final_page_preserves_earlier_permanent_failure_status()
    {
        update_option(Product_Backfill::OPTION_STATE, [
            "permanent_failure_count" => "2",
            "last_error" => "Earlier permanent listing failures.",
        ], false);

        (new Product_Backfill())->process_batch(self::SOURCE_ID, 99999);

        $state = (new Product_Backfill())->get_status_details();
        $this->assertSame(Product_Backfill::STATUS_FAILED, $state["status"]);
        $this->assertSame("2", $state["permanent_failure_count"]);
        $this->assertNotEmpty($state["completed_at"]);
        $this->assertSame([], $this->requests);
    }

    public function test_retryable_request_error_reuses_exact_ordered_snapshot_and_key()
    {
        $product = $this->add_product("Before retry");
        $this->mock_responses([
            new Response(503, ["Content-Type" => "application/problem+json"], '{"detail":"secret backend detail"}'),
            $this->admission(1),
        ]);
        $backfill = new Product_Backfill();
        try {
            $this->assertTrue($backfill->schedule_backfill(self::SOURCE_ID));
            $this->assertTrue((bool) as_has_scheduled_action(
                Product_Backfill::ACTION_HOOK,
                [self::SOURCE_ID, 1],
                Product_Backfill::ACTION_GROUP,
            ));
            try {
                $backfill->process_batch(self::SOURCE_ID, 1);
                $this->fail("Expected retryable request failure");
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString("secret backend detail", $exception->getMessage());
            }
            $snapshot = get_option(Product_Backfill::OPTION_BATCH);
            $this->assertSame("Before retry", $snapshot["payloads"][0]["title"]["text"]);
            $this->assertSame($product->get_id(), $snapshot["last_product_id"]);
            $this->assertSame("0", $backfill->get_status_details()["last_product_id"]);
            $this->assertSame((string) $this->request()->getHeaderLine("Idempotency-Key"), $snapshot["idempotency_key"]);
            $args = $this->pending_retry_args($snapshot);
            $product->set_name("After retry");
            $product->save();
            $this->assertNotFalse(has_action(Product_Backfill::ACTION_HOOK));
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP);
            do_action_ref_array(Product_Backfill::ACTION_HOOK, $args);
            $this->assertSame((string) $this->request()->getBody(), (string) $this->request(1)->getBody());
            $this->assertSame($this->request()->getHeaderLine("Idempotency-Key"), $this->request(1)->getHeaderLine("Idempotency-Key"));
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
            $backfill->process_batch(self::SOURCE_ID, 1);
            $this->assertCount(2, $this->requests);
            $this->assertSame(Product_Backfill::STATUS_COMPLETE, $backfill->get_status_details()["status"]);
            $this->assertSame((string) $product->get_id(), $backfill->get_status_details()["last_product_id"]);
            $this->assertFalse((bool) as_has_scheduled_action(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP));
        } finally {
            $product->delete(true);
        }
    }

    public function test_second_retry_uses_distinct_action_args_but_the_same_request()
    {
        $product = $this->add_product("Retry twice");
        $this->mock_responses([
            new Response(503, ["Content-Type" => "application/problem+json"], "{}"),
            new Response(503, ["Content-Type" => "application/problem+json"], "{}"),
            $this->admission(1),
        ]);
        $backfill = new Product_Backfill();
        try {
            try {
                $backfill->process_batch(self::SOURCE_ID, 1);
                $this->fail("Expected first retry");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("request failed", $exception->getMessage());
            }
            $first_args = $this->pending_retry_args(get_option(Product_Backfill::OPTION_BATCH));
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, $first_args, Product_Backfill::ACTION_GROUP);
            try {
                do_action_ref_array(Product_Backfill::ACTION_HOOK, $first_args);
                $this->fail("Expected second retry");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("request failed", $exception->getMessage());
            }
            $second_args = $this->pending_retry_args(get_option(Product_Backfill::OPTION_BATCH));
            $this->assertNotSame($first_args[2], $second_args[2]);
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, $second_args, Product_Backfill::ACTION_GROUP);
            do_action_ref_array(Product_Backfill::ACTION_HOOK, $second_args);
            $this->assertCount(3, $this->requests);
            $this->assertSame((string) $this->request()->getBody(), (string) $this->request(2)->getBody());
            $this->assertSame($this->request()->getHeaderLine("Idempotency-Key"), $this->request(2)->getHeaderLine("Idempotency-Key"));
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
        } finally {
            $product->delete(true);
        }
    }

    public function test_retry_queue_failure_keeps_the_original_snapshot_and_reports_failure()
    {
        $product = $this->add_product("Keep snapshot");
        $this->mock_responses([new Response(503, ["Content-Type" => "application/problem+json"], "{}")]);
        $reject_retry_write = static function ($value, $old_value) {
            return !empty($value["retry_attempt"]) ? $old_value : $value;
        };
        add_filter("pre_update_option_" . Product_Backfill::OPTION_BATCH, $reject_retry_write, 10, 2);
        try {
            try {
                (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
                $this->fail("Expected failure to persist retry metadata");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("could not persist its retry", $exception->getMessage());
            }
            $snapshot = get_option(Product_Backfill::OPTION_BATCH);
            $this->assertSame($this->request()->getHeaderLine("Idempotency-Key"), $snapshot["idempotency_key"]);
            $this->assertArrayNotHasKey("retry_attempt", $snapshot);
            $this->assertFalse((bool) as_has_scheduled_action(Product_Backfill::ACTION_HOOK, null, Product_Backfill::ACTION_GROUP));
            $this->assertSame(Product_Backfill::STATUS_FAILED, (new Product_Backfill())->get_status_details()["status"]);
        } finally {
            remove_filter("pre_update_option_" . Product_Backfill::OPTION_BATCH, $reject_retry_write, 10);
            $product->delete(true);
        }
    }

    public function test_preexisting_snapshot_reuses_original_payload_and_key()
    {
        $payloads = [[
            "sourceListingId" => "legacy-item",
            "url" => "https://example.com/item",
            "images" => [],
        ]];
        update_option(Product_Backfill::OPTION_BATCH, [
            "listing_source_id" => self::SOURCE_ID,
            "page" => 1,
            "product_count" => 1,
            "payloads" => $payloads,
            "idempotency_key" => "existing-key",
        ], false);

        (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);

        $this->assertSame("existing-key", $this->request()->getHeaderLine("Idempotency-Key"));
        $this->assertSame($payloads, json_decode((string) $this->request()->getBody(), true));
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_202_with_any_retryable_failure_replays_exact_batch_before_counting_permanent_failures()
    {
        $products = [
            $this->add_product("Accepted"),
            $this->add_product("Retryable"),
            $this->add_product("Permanent"),
        ];
        $this->mock_responses([
            $this->admission(1, [
                ["index" => 1, "error" => "TEMPORARY_FAILURE", "retryable" => true],
                ["index" => 2, "error" => "BAD_LISTING", "retryable" => false],
            ]),
            $this->admission(2, [
                ["index" => 2, "error" => "BAD_LISTING", "retryable" => false],
            ], "second-admission"),
        ]);
        $backfill = new Product_Backfill();
        try {
            try {
                $backfill->process_batch(self::SOURCE_ID, 1);
                $this->fail("Expected retryable listing failure");
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString("TEMPORARY_FAILURE", $exception->getMessage());
                $this->assertStringNotContainsString("BAD_LISTING", $exception->getMessage());
            }
            $snapshot = get_option(Product_Backfill::OPTION_BATCH);
            $this->assertCount(3, $snapshot["payloads"]);
            $args = $this->pending_retry_args($snapshot);
            $this->assertSame("0", $backfill->get_status_details()["permanent_failure_count"]);
            $products[1]->set_name("Edited during retry");
            $products[1]->save();

            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP);
            do_action_ref_array(Product_Backfill::ACTION_HOOK, $args);
            $this->assertSame((string) $this->request()->getBody(), (string) $this->request(1)->getBody());
            $this->assertSame($snapshot["idempotency_key"], $this->request(1)->getHeaderLine("Idempotency-Key"));
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
            $state = $backfill->get_status_details();
            $this->assertSame(Product_Backfill::STATUS_FAILED, $state["status"]);
            $this->assertSame("1", $state["permanent_failure_count"]);
            $this->assertSame("1", $state["failed_count"]);
            $this->assertSame("second-admission", $state["submission_id"]);
        } finally {
            foreach ($products as $product) {
                $product->delete(true);
            }
        }
    }

    public function test_nonretryable_request_error_is_terminal_and_does_not_leak_details()
    {
        $product = $this->add_product("Bad request");
        $this->mock_responses([new Response(401, ["Content-Type" => "application/problem+json"], '{"detail":"private backend credential"}')]);
        try {
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $state = (new Product_Backfill())->get_status_details();
            $this->assertSame(Product_Backfill::STATUS_FAILED, $state["status"]);
            $this->assertStringNotContainsString("private backend credential", $state["last_error"]);
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
        } finally {
            $product->delete(true);
        }
    }

    public function test_permanent_listing_failures_are_recorded_with_safe_metadata()
    {
        $accepted = $this->add_product("Accepted");
        $rejected = $this->add_product("Rejected");
        $this->mock_responses([$this->admission(1, [[
            "index" => 1,
            "sourceListingId" => (string) $rejected->get_id(),
            "error" => "BAD_LISTING",
            "retryable" => false,
        ]])]);
        try {
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $state = (new Product_Backfill())->get_status_details();
            $this->assertSame(Product_Backfill::STATUS_FAILED, $state["status"]);
            $this->assertSame("1", $state["failed_count"]);
            $this->assertSame("1", $state["permanent_failure_count"]);
            $this->assertSame("1", $state["accepted_count"]);
            $this->assertSame("submission-safe", $state["submission_id"]);
            $this->assertStringNotContainsString("BAD_LISTING", $state["last_error"]);
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
        } finally {
            $accepted->delete(true);
            $rejected->delete(true);
        }
    }

    public function test_full_page_schedules_a_next_batch_with_a_different_key()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $products = [];
        $this->mock_responses([$this->admission(Product_Backfill::BATCH_SIZE), $this->admission(1)]);
        try {
            for ($i = 0; $i <= Product_Backfill::BATCH_SIZE; ++$i) {
                $products[] = $this->add_product("Batch item " . $i);
            }

            $backfill = new Product_Backfill();
            $backfill->process_batch(self::SOURCE_ID, 1);
            $this->assertTrue($backfill->is_backfill_scheduled());
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
            $this->assertTrue((bool) as_has_scheduled_action(
                Product_Backfill::ACTION_HOOK,
                [self::SOURCE_ID, 2],
                Product_Backfill::ACTION_GROUP,
            ));
            $this->assertSame("1", $backfill->get_status_details()["last_completed_page"]);
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, [self::SOURCE_ID, 2], Product_Backfill::ACTION_GROUP);
            $backfill->process_batch(self::SOURCE_ID, 2);

            $first = json_decode((string) $this->request()->getBody(), true);
            $second = json_decode((string) $this->request(1)->getBody(), true);
            $this->assertCount(Product_Backfill::BATCH_SIZE, $first);
            $this->assertCount(1, $second);
            $this->assertSame((string) $products[0]->get_id(), $first[0]["sourceListingId"]);
            $this->assertSame((string) $products[Product_Backfill::BATCH_SIZE]->get_id(), $second[0]["sourceListingId"]);
            $this->assertNotSame($this->request()->getHeaderLine("Idempotency-Key"), $this->request(1)->getHeaderLine("Idempotency-Key"));
        } finally {
            foreach ($products as $product) {
                $product->delete(true);
            }
        }
    }

    public function test_unpublishing_an_earlier_product_does_not_skip_the_next_batch()
    {
        $products = [];
        $this->mock_responses([$this->admission(Product_Backfill::BATCH_SIZE), $this->admission(1)]);
        try {
            for ($i = 0; $i <= Product_Backfill::BATCH_SIZE; ++$i) {
                $products[] = $this->add_product("Cursor item " . $i);
            }
            $backfill = new Product_Backfill();
            $backfill->process_batch(self::SOURCE_ID, 1);
            $this->assertSame((string) $products[99]->get_id(), $backfill->get_status_details()["last_product_id"]);
            $products[0]->set_status("draft");
            $products[0]->save();
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, [self::SOURCE_ID, 2], Product_Backfill::ACTION_GROUP);
            $backfill->process_batch(self::SOURCE_ID, 2);
            $body = json_decode((string) $this->request(1)->getBody(), true);
            $this->assertCount(1, $body);
            $this->assertSame((string) $products[100]->get_id(), $body[0]["sourceListingId"]);
        } finally {
            foreach ($products as $product) {
                $product->delete(true);
            }
        }
    }

    public function test_cursor_advances_past_id_that_disappears_during_payload_build()
    {
        $products = [];
        $this->mock_responses([$this->admission(Product_Backfill::BATCH_SIZE - 1), $this->admission(1)]);
        try {
            for ($i = 0; $i <= Product_Backfill::BATCH_SIZE; ++$i) {
                $products[] = $this->add_product("Lifecycle item " . $i);
            }
            $last_on_first_page = $products[99];
            $last_id = $last_on_first_page->get_id();
            $preceding_id = $products[98]->get_id();
            $remove_product = static function ($name, $product) use ($preceding_id, $last_on_first_page) {
                if ($product->get_id() === $preceding_id) {
                    $last_on_first_page->delete(true);
                }
                return $name;
            };
            add_filter("woocommerce_product_get_name", $remove_product, 10, 2);
            try {
                $backfill = new Product_Backfill();
                $backfill->process_batch(self::SOURCE_ID, 1);
            } finally {
                remove_filter("woocommerce_product_get_name", $remove_product, 10);
            }
            $this->assertSame((string) $last_id, $backfill->get_status_details()["last_product_id"]);
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, [self::SOURCE_ID, 2], Product_Backfill::ACTION_GROUP);
            $backfill->process_batch(self::SOURCE_ID, 2);
            $body = json_decode((string) $this->request(1)->getBody(), true);
            $this->assertCount(1, $body);
            $this->assertSame((string) $products[100]->get_id(), $body[0]["sourceListingId"]);
        } finally {
            foreach ($products as $product) {
                $product->delete(true);
            }
        }
    }

    public function test_permanent_and_missing_url_failures_continue_through_later_pages()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $products = [];
        $this->mock_responses([
            $this->admission(98, [["index" => 0, "error" => "BAD_LISTING", "retryable" => false]]),
            $this->admission(1, [], "last-page-submission"),
        ]);
        try {
            for ($i = 0; $i <= Product_Backfill::BATCH_SIZE; ++$i) {
                $products[] = $this->add_product("Catalog item " . $i);
            }
            $missing_id = $products[0]->get_id();
            $filter = static function ($url, $post) use ($missing_id) {
                return (int) $post->ID === $missing_id ? "/invalid" : $url;
            };
            add_filter("post_type_link", $filter, 10, 2);
            try {
                $backfill = new Product_Backfill();
                $backfill->process_batch(self::SOURCE_ID, 1);
                $first_body = json_decode((string) $this->request()->getBody(), true);
                $this->assertCount(99, $first_body);
                $this->assertSame((string) $products[1]->get_id(), $first_body[0]["sourceListingId"]);
                $this->assertTrue($backfill->is_backfill_scheduled());
                $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
                $this->assertSame("2", $backfill->get_status_details()["permanent_failure_count"]);
                $this->assertSame("2", $backfill->get_status_details()["failed_count"]);

                // Run page two inline rather than leaving a pending action that
                // makes the status UI report "scheduled" after our assertion.
                as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, [self::SOURCE_ID, 2], Product_Backfill::ACTION_GROUP);
                $backfill->process_batch(self::SOURCE_ID, 2);
                $second_body = json_decode((string) $this->request(1)->getBody(), true);
                $this->assertSame((string) $products[Product_Backfill::BATCH_SIZE]->get_id(), $second_body[0]["sourceListingId"]);
                $this->assertNotSame($this->request()->getHeaderLine("Idempotency-Key"), $this->request(1)->getHeaderLine("Idempotency-Key"));
                $state = $backfill->get_status_details();
                $this->assertSame(Product_Backfill::STATUS_FAILED, $state["status"]);
                $this->assertSame("2", $state["permanent_failure_count"]);
                $this->assertSame("0", $state["failed_count"]);
                $this->assertSame("last-page-submission", $state["submission_id"]);
                $this->assertStringContainsString("permanent listing failures", $state["last_error"]);
                $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
            } finally {
                remove_filter("post_type_link", $filter, 10);
            }
        } finally {
            foreach ($products as $product) {
                $product->delete(true);
            }
        }
    }

    public function test_pending_page_handoff_does_not_resend_batch_or_lose_previous_failures()
    {
        update_option(Product_Backfill::OPTION_STATE, [
            "last_completed_page" => "1",
            "permanent_failure_count" => "2",
            "last_counted_batch" => hash("sha256", "admitted-key"),
        ], false);
        update_option(Product_Backfill::OPTION_BATCH, [
            "listing_source_id" => self::SOURCE_ID,
            "page" => 1,
            "product_count" => Product_Backfill::BATCH_SIZE,
            "local_failures" => 0,
            "payloads" => [["sourceListingId" => "already-admitted"]],
            "idempotency_key" => "admitted-key",
            "handoff_pending" => true,
        ], false);

        (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);

        $this->assertSame([], $this->requests);
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
        $this->assertTrue((bool) as_has_scheduled_action(
            Product_Backfill::ACTION_HOOK,
            [self::SOURCE_ID, 2],
            Product_Backfill::ACTION_GROUP,
        ));
        $this->assertSame("2", (new Product_Backfill())->get_status_details()["permanent_failure_count"]);
    }

    public function test_all_rejected_admission_report_is_terminal_and_cleans_snapshot()
    {
        $product = $this->add_product("Rejected report");
        $this->mock_responses([new Response(400, ["Content-Type" => "application/json"], wp_json_encode([
            "submissionId" => "rejected-submission",
            "acceptedCount" => 0,
            "failures" => [[
                "index" => 0,
                "error" => "INVALID_LISTING",
                "retryable" => false,
            ]],
        ]))]);
        try {
            (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
            $state = (new Product_Backfill())->get_status_details();
            $this->assertSame(Product_Backfill::STATUS_FAILED, $state["status"]);
            $this->assertSame("1", $state["failed_count"]);
            $this->assertSame("0", $state["accepted_count"]);
            $this->assertSame("rejected-submission", $state["submission_id"]);
            $this->assertSame("1", $state["permanent_failure_count"]);
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
        } finally {
            $product->delete(true);
        }
    }

    public function test_retryable_all_rejected_report_keeps_the_batch_for_replay()
    {
        $product = $this->add_product("Try again");
        $this->mock_responses([new Response(503, ["Content-Type" => "application/json"], wp_json_encode([
            "submissionId" => "retry-submission",
            "acceptedCount" => 0,
            "failures" => [["index" => 0, "error" => "TEMPORARY_FAILURE", "retryable" => true]],
        ]))]);
        try {
            try {
                (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
                $this->fail("Expected a retryable batch report");
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString("TEMPORARY_FAILURE", $exception->getMessage());
            }
            $state = (new Product_Backfill())->get_status_details();
            $this->assertSame("0", $state["accepted_count"]);
            $this->assertSame("1", $state["failed_count"]);
            $this->assertSame("retry-submission", $state["submission_id"]);
            $snapshot = get_option(Product_Backfill::OPTION_BATCH);
            $this->assertSame($this->request()->getHeaderLine("Idempotency-Key"), $snapshot["idempotency_key"]);
            $this->pending_retry_args($snapshot);
        } finally {
            $product->delete(true);
        }
    }

    public function test_fresh_run_discards_old_snapshot_and_uses_new_key()
    {
        $product = $this->add_product("Original");
        $this->mock_responses([
            new Response(503, ["Content-Type" => "application/problem+json"], "{}"),
            $this->admission(1),
        ]);
        $backfill = new Product_Backfill();
        try {
            try {
                $backfill->process_batch(self::SOURCE_ID, 1);
            } catch (RuntimeException $exception) {
                // A new run must never reuse a previous run's key or payload.
            }
            $product->set_name("Fresh");
            $product->save();
            $this->assertTrue($backfill->schedule_backfill(self::SOURCE_ID));
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
            $backfill->process_batch(self::SOURCE_ID, 1);
            $this->assertNotSame($this->request()->getHeaderLine("Idempotency-Key"), $this->request(1)->getHeaderLine("Idempotency-Key"));
            $this->assertSame("Fresh", json_decode((string) $this->request(1)->getBody(), true)[0]["title"]["text"]);
        } finally {
            $product->delete(true);
        }
    }
}
