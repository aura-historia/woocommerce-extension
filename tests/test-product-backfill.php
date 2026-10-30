<?php
/**
 * Integration tests for product-listing backfill admission.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\InternalApi\Model\CurrencyData;
use AuraHistoria\PartnerConnect\Option_Lease;
use AuraHistoria\PartnerConnect\Plugin;
use AuraHistoria\PartnerConnect\Product_Backfill;
use AuraHistoria\PartnerConnect\Webhook_Manager;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class Test_AHPC_Product_Backfill extends WP_UnitTestCase
{
    const SOURCE_ID = "ls_01jw7j4azge008000000000003";
    const OTHER_SOURCE_ID = "ls_01jw7j4azge00800000000000g";
    const TOKEN = "aurahistoria_accesstoken_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";
    const RECONNECTED_TOKEN = "aurahistoria_accesstoken_reconnected_abcdefghijklmnopqrstuvwxyz12345";
    const ENDPOINT = "https://example.com/v1/listing-sources/" . self::SOURCE_ID . "/product-listings/async";

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

    /**
     * Answers each request through $respond, which may run other work while
     * the request is in flight before it returns the response.
     */
    private function respond_with(callable $respond)
    {
        $this->requests = [];
        $handler = function ($request) use ($respond) {
            $this->requests[] = ["request" => $request];
            return new FulfilledPromise($respond(count($this->requests)));
        };
        $this->backend_client = new Client(["handler" => HandlerStack::create($handler), "http_errors" => true]);
    }

    /** Lets the batch lease run out, as if its holder had been suspended. */
    private function expire_lease()
    {
        global $wpdb;
        $lease = json_decode((string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            Product_Backfill::OPTION_LOCK,
        )), true);
        $this->assertIsArray($lease);
        $lease["until"] = time() - 1;
        $wpdb->update($wpdb->options, ["option_value" => wp_json_encode($lease)], ["option_name" => Product_Backfill::OPTION_LOCK]);
        wp_cache_delete(Product_Backfill::OPTION_LOCK, "options");
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

    /** Returns the arguments of the queued actions for a page. */
    private function queued_actions($page, $source = self::SOURCE_ID)
    {
        $matches = [];
        foreach (as_get_scheduled_actions([
            "hook" => Product_Backfill::ACTION_HOOK,
            "group" => Product_Backfill::ACTION_GROUP,
            "status" => ActionScheduler_Store::STATUS_PENDING,
            "per_page" => 100,
        ]) as $action) {
            $args = $action->get_args();
            if ($source === $args[0] && $page === $args[1]) {
                $matches[] = $args;
            }
        }

        return $matches;
    }

    /** Returns the arguments of the only queued action for a page. */
    private function queued_args($page, $source = self::SOURCE_ID)
    {
        $matches = $this->queued_actions($page, $source);
        $this->assertCount(1, $matches);
        // Only the wake-up token, never a credential, travels with the action.
        $this->assertCount(3, $matches[0]);
        $this->assertStringStartsWith("wake-", $matches[0][2]);
        $this->assertStringNotContainsString(self::TOKEN, wp_json_encode($matches[0]));

        return $matches[0];
    }

    /** Runs the queued action for a page the way Action Scheduler would. */
    private function run_queued($page, $source = self::SOURCE_ID)
    {
        $args = $this->queued_args($page, $source);
        as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP);
        do_action_ref_array(Product_Backfill::ACTION_HOOK, $args);

        return $args;
    }

    private function pending_retry_args($page = 1)
    {
        $args = $this->queued_args($page);
        $this->assertGreaterThan(time(), as_next_scheduled_action(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP));

        return $args;
    }

    public function test_schedules_only_valid_listing_sources_and_cancel_cleans_snapshot()
    {
        $backfill = new Product_Backfill();
        $this->assertFalse($backfill->schedule_backfill("not-a-listing-source"));
        $this->assertFalse($backfill->schedule_backfill("oc_01jw7j4azge00800000000000g"));
        $this->assertFalse($backfill->schedule_backfill("ls_" . str_repeat("i", 26)));
        $this->assertFalse($backfill->is_backfill_scheduled());
        $this->assertTrue($backfill->schedule_backfill(self::SOURCE_ID));
        $this->assertTrue($backfill->is_backfill_scheduled());
        update_option(Product_Backfill::OPTION_BATCH, ["payloads" => ["temporary"]], false);
        $backfill->cancel_backfill();
        $this->assertFalse($backfill->is_backfill_scheduled());
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_start_or_resume_preserves_orphaned_request_and_reports_unsafe_snapshot()
    {
        $backfill = new Product_Backfill();
        $snapshot = [
            "listing_source_id" => self::SOURCE_ID,
            "page" => 1,
            "product_count" => 1,
            "payloads" => [["sourceListingId" => "42", "url" => "https://example.com/42", "images" => []]],
            "idempotency_key" => "saved-key",
            "retry_attempt" => 1,
        ];
        update_option(Product_Backfill::OPTION_BATCH, $snapshot, false);
        $this->assertSame(Product_Backfill::START_RESUMED, $backfill->start_or_resume_backfill(self::SOURCE_ID));
        $this->queued_args(1);
        $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
        $this->assertSame(Product_Backfill::START_ALREADY_QUEUED, $backfill->start_or_resume_backfill(self::SOURCE_ID));
        $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));

        as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, null, Product_Backfill::ACTION_GROUP);
        $snapshot["listing_source_id"] = self::OTHER_SOURCE_ID;
        update_option(Product_Backfill::OPTION_BATCH, $snapshot, false);
        $this->assertFalse($backfill->start_or_resume_backfill(self::SOURCE_ID));
        $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
        $status = $backfill->get_status_details();
        $this->assertSame(Product_Backfill::STATUS_FAILED, $status["status"]);
        $this->assertNotSame("", $status["last_error"]);
        $this->assertFalse($backfill->is_backfill_scheduled());
    }

    public function test_safe_manual_start_waits_for_terminal_run_then_uses_a_new_key()
    {
        $product = $this->add_product("New run");
        $backfill = new Product_Backfill();
        try {
            $this->mock_responses([$this->admission(1), $this->admission(1)]);
            $this->assertSame(Product_Backfill::START_FRESH, $backfill->start_or_resume_backfill(self::SOURCE_ID));
            $this->assertSame(Product_Backfill::START_ALREADY_QUEUED, $backfill->start_or_resume_backfill(self::SOURCE_ID));
            $this->run_queued(1);
            $first_key = $this->request()->getHeaderLine("Idempotency-Key");
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH, false));
            $this->assertSame(Product_Backfill::STATUS_COMPLETE, $backfill->get_status_details()["status"]);
            $this->assertSame(Product_Backfill::START_FRESH, $backfill->start_or_resume_backfill(self::SOURCE_ID));
            $this->run_queued(1);
            $this->assertNotSame($first_key, $this->request(1)->getHeaderLine("Idempotency-Key"));
        } finally {
            $product->delete(true);
        }
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

    public function test_stale_action_and_missing_credentials_preserve_pending_snapshot()
    {
        $snapshot = ["listing_source_id" => self::SOURCE_ID, "page" => 1,
            "payloads" => [["sourceListingId" => "123"]], "idempotency_key" => "unchanged-key", "product_count" => 1];
        update_option(Product_Backfill::OPTION_BATCH, $snapshot, false);
        $backfill = new Product_Backfill();

        $backfill->process_batch(self::OTHER_SOURCE_ID, 1);
        $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
        $this->configure_connection(self::SOURCE_ID, "");
        $backfill->process_batch(self::SOURCE_ID, 1);
        $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
        update_option(Webhook_Manager::OPTION_SETTINGS, "invalid", false);
        $backfill->process_batch(self::SOURCE_ID, 1);
        $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
        $this->assertSame([], $this->requests);
    }

    public function test_database_read_failure_does_not_report_completion()
    {
        global $wpdb;
        $break_query = static function ($sql, $query) {
            return $query->get("ahpc_backfill_page") ? "SELECT ID FROM ahpc_missing_test_table" : $sql;
        };
        add_filter("posts_request", $break_query, 10, 2);
        $suppressed = $wpdb->suppress_errors(true);
        try {
            try {
                (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
                $this->fail("A failed product read must stop the batch.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("Products could not be read", $exception->getMessage());
            }
        } finally {
            remove_filter("posts_request", $break_query, 10);
            $wpdb->suppress_errors($suppressed);
        }
        $state = (new Product_Backfill())->get_status_details();
        $this->assertSame(Product_Backfill::STATUS_FAILED, $state["status"]);
        $this->assertSame("", $state["completed_at"]);
        $this->assertSame("0", $state["last_product_id"]);
        $this->assertSame([], $this->requests);
    }

    public function test_backfill_cursor_does_not_filter_nested_queries()
    {
        $earlier = $this->add_product("Earlier product");
        $later = $this->add_product("Later product");
        $nested_ids = null;
        $nested_query = static function ($where, $query) use ($earlier, &$nested_ids) {
            if ($query->get("ahpc_backfill_page")) {
                $nested = new WP_Query(["post_type" => "product", "post__in" => [$earlier->get_id()], "fields" => "ids"]);
                $nested_ids = $nested->posts;
            }
            return $where;
        };
        add_filter("posts_where", $nested_query, 20, 2);
        try {
            $method = new ReflectionMethod(Product_Backfill::class, "get_product_ids");
            $ids = $method->invoke(new Product_Backfill(), $earlier->get_id());
            $this->assertSame([$later->get_id()], $ids);
            $this->assertSame([$earlier->get_id()], array_map("intval", $nested_ids));
        } finally {
            remove_filter("posts_where", $nested_query, 20);
            $earlier->delete(true);
            $later->delete(true);
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

    public function test_minor_unit_exponents_cover_the_entire_generated_currency_enum()
    {
        $expected = [
            "EUR" => 2, "GBP" => 2, "USD" => 2, "AUD" => 2,
            "CAD" => 2, "NZD" => 2, "CNY" => 2, "BRL" => 2,
            "PLN" => 2, "TRY" => 2, "JPY" => 0, "CZK" => 2,
            "RUB" => 2, "AED" => 2, "SAR" => 2, "HKD" => 2,
            "SGD" => 2, "CHF" => 2,
            "ZAR" => 2, "SEK" => 2, "DKK" => 2, "NOK" => 2,
            "KRW" => 0, "INR" => 2, "TWD" => 2, "HUF" => 2,
            "RON" => 2, "MXN" => 2, "THB" => 2, "ARS" => 2,
        ];
        $this->assertEqualsCanonicalizing(array_keys($expected), CurrencyData::getAllowableEnumValues());

        $constant = (new ReflectionClass(Product_Backfill::class))->getReflectionConstant("CURRENCY_MINOR_UNIT_EXPONENTS");
        $this->assertSame($expected, $constant->getValue());

        $convert = new ReflectionMethod(Product_Backfill::class, "convert_price_to_minor_units");
        $convert->setAccessible(true);
        $backfill = new Product_Backfill();
        foreach ($expected as $currency => $exponent) {
            $this->assertSame(0 === $exponent ? 12 : 1200, $convert->invoke($backfill, "12.00", $currency), $currency);
            $this->assertSame(0 === $exponent ? null : 1234, $convert->invoke($backfill, "12.34", $currency), $currency);
        }
        $this->assertNull($convert->invoke($backfill, "12.34", "XYZ"));
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
            try {
                $this->run_queued(1);
                $this->fail("Expected retryable request failure");
            } catch (RuntimeException $exception) {
                $this->assertStringNotContainsString("secret backend detail", $exception->getMessage());
            }
            $snapshot = get_option(Product_Backfill::OPTION_BATCH);
            $this->assertSame("Before retry", $snapshot["payloads"][0]["title"]["text"]);
            $this->assertSame($product->get_id(), $snapshot["last_product_id"]);
            $this->assertSame("0", $backfill->get_status_details()["last_product_id"]);
            $this->assertSame((string) $this->request()->getHeaderLine("Idempotency-Key"), $snapshot["idempotency_key"]);
            $args = $this->pending_retry_args();
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
            $first_args = $this->pending_retry_args();
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, $first_args, Product_Backfill::ACTION_GROUP);
            try {
                do_action_ref_array(Product_Backfill::ACTION_HOOK, $first_args);
                $this->fail("Expected second retry");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("request failed", $exception->getMessage());
            }
            $second_args = $this->pending_retry_args();
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
        $reject_retry = static function ($pre, $timestamp) {
            return $timestamp > time() ? 0 : $pre;
        };
        add_filter("pre_as_schedule_single_action", $reject_retry, 10, 2);
        try {
            try {
                (new Product_Backfill())->process_batch(self::SOURCE_ID, 1);
                $this->fail("Expected failure to queue the retry");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("could not schedule its retry", $exception->getMessage());
            }
            $snapshot = get_option(Product_Backfill::OPTION_BATCH);
            $this->assertSame($this->request()->getHeaderLine("Idempotency-Key"), $snapshot["idempotency_key"]);
            $this->assertArrayNotHasKey("retry_attempt", $snapshot);
            $this->assertFalse((bool) as_has_scheduled_action(Product_Backfill::ACTION_HOOK, null, Product_Backfill::ACTION_GROUP));
            $this->assertSame(Product_Backfill::STATUS_FAILED, (new Product_Backfill())->get_status_details()["status"]);
        } finally {
            remove_filter("pre_as_schedule_single_action", $reject_retry, 10);
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
            $args = $this->pending_retry_args();
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
            $this->assertSame("1", $backfill->get_status_details()["last_completed_page"]);
            $this->run_queued(2);

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
            $this->run_queued(2);
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
            $this->run_queued(2);
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
                $this->run_queued(2);
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
        $this->queued_args(2);
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
            $this->pending_retry_args();
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
            $this->run_queued(1);
            $this->assertNotSame($this->request()->getHeaderLine("Idempotency-Key"), $this->request(1)->getHeaderLine("Idempotency-Key"));
            $this->assertSame("Fresh", json_decode((string) $this->request(1)->getBody(), true)[0]["title"]["text"]);
        } finally {
            $product->delete(true);
        }
    }

    public function test_interleaved_callbacks_for_one_page_keep_one_batch_and_key()
    {
        $product = $this->add_product("Interleaved");
        try {
            $this->assertSame(Product_Backfill::START_FRESH, (new Product_Backfill())->start_or_resume_backfill(self::SOURCE_ID));
            $args = $this->queued_args(1);
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP);
            // A second runner starts a copy of the same action while the first
            // is still building the batch, before either has saved a snapshot.
            $interleaved = false;
            $second = function ($name) use ($args, &$interleaved) {
                if (!$interleaved) {
                    $interleaved = true;
                    do_action_ref_array(Product_Backfill::ACTION_HOOK, $args);
                    $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH, false));
                    $this->assertSame([], $this->requests);
                }
                return $name;
            };
            add_filter("woocommerce_product_get_name", $second);
            try {
                do_action_ref_array(Product_Backfill::ACTION_HOOK, $args);
            } finally {
                remove_filter("woocommerce_product_get_name", $second);
            }

            $this->assertTrue($interleaved);
            $this->assertCount(1, $this->requests);
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH, false));
            $state = (new Product_Backfill())->get_status_details();
            $this->assertSame(Product_Backfill::STATUS_COMPLETE, $state["status"]);
            $this->assertSame("1", $state["last_completed_page"]);
            $this->assertSame((string) $product->get_id(), $state["last_product_id"]);

            // The contended copy was kept, not dropped; it is superseded now.
            $this->assertSame($args, $this->pending_retry_args(1));
            $this->run_queued(1);
            $this->assertCount(1, $this->requests);
            $this->assertSame($state, (new Product_Backfill())->get_status_details());
        } finally {
            $product->delete(true);
        }
    }

    public function test_lock_contention_keeps_the_wakeup_until_the_holder_releases()
    {
        $product = $this->add_product("Contended");
        $holder = new Option_Lease(Product_Backfill::OPTION_LOCK);
        $backfill = new Product_Backfill();
        try {
            $this->assertTrue($holder->acquire(Product_Backfill::LOCK_SECONDS));
            // Nothing is queued yet, and a busy batch never reports a start.
            $this->assertFalse($backfill->start_or_resume_backfill(self::SOURCE_ID));
            $this->assertFalse($backfill->is_backfill_scheduled());
            $holder->release();

            $this->assertSame(Product_Backfill::START_FRESH, $backfill->start_or_resume_backfill(self::SOURCE_ID));
            $this->assertTrue($holder->acquire(Product_Backfill::LOCK_SECONDS));
            $args = $this->run_queued(1);
            $this->assertSame([], $this->requests);
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH, false));
            $this->assertSame($args, $this->pending_retry_args(1));
            $this->assertSame(Product_Backfill::START_ALREADY_QUEUED, $backfill->start_or_resume_backfill(self::SOURCE_ID));
            $holder->release();

            $this->run_queued(1);
            $this->assertCount(1, $this->requests);
            $this->assertSame(Product_Backfill::STATUS_COMPLETE, $backfill->get_status_details()["status"]);
        } finally {
            $holder->release();
            $product->delete(true);
        }
    }

    public function test_expired_holder_cannot_overwrite_its_successor()
    {
        $product = $this->add_product("Expired holder");
        try {
            $this->assertSame(Product_Backfill::START_FRESH, (new Product_Backfill())->start_or_resume_backfill(self::SOURCE_ID));
            $args = $this->queued_args(1);
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP);
            $successor = null;
            $this->respond_with(function ($count) use ($args, &$successor) {
                if (1 === $count) {
                    // The first holder stalls past its lease. A successor takes
                    // over, replays the persisted request and finishes the run.
                    $this->expire_lease();
                    do_action_ref_array(Product_Backfill::ACTION_HOOK, $args);
                    $successor = get_option(Product_Backfill::OPTION_STATE);
                    return new Response(503, ["Content-Type" => "application/problem+json"], "{}");
                }
                return $this->admission(1);
            });

            do_action_ref_array(Product_Backfill::ACTION_HOOK, $args);

            $this->assertCount(2, $this->requests);
            $this->assertSame((string) $this->request()->getBody(), (string) $this->request(1)->getBody());
            $this->assertSame($this->request()->getHeaderLine("Idempotency-Key"), $this->request(1)->getHeaderLine("Idempotency-Key"));
            $this->assertSame(Product_Backfill::STATUS_COMPLETE, $successor["status"]);
            $this->assertSame($successor, get_option(Product_Backfill::OPTION_STATE));
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH, false));
            $this->assertFalse((bool) as_has_scheduled_action(Product_Backfill::ACTION_HOOK, null, Product_Backfill::ACTION_GROUP));
        } finally {
            $product->delete(true);
        }
    }

    public static function connection_changes()
    {
        return [
            "cancellation" => ["cancel"],
            "source replacement" => ["replace"],
            "same-source reconnect" => ["reconnect"],
        ];
    }

    /** @dataProvider connection_changes */
    public function test_worker_suspended_across_a_connection_change_cannot_mutate_its_successor($change)
    {
        $product = $this->add_product("Suspended worker");
        try {
            $this->assertSame(Product_Backfill::START_FRESH, (new Product_Backfill())->start_or_resume_backfill(self::SOURCE_ID));
            $args = $this->queued_args(1);
            as_unschedule_all_actions(Product_Backfill::ACTION_HOOK, $args, Product_Backfill::ACTION_GROUP);
            $successor = null;
            $this->respond_with(function ($count) use ($change, &$successor) {
                if (1 !== $count) {
                    return $this->admission(1);
                }
                // The connection changes while the first request is in flight.
                $backfill = new Product_Backfill();
                if ("reconnect" === $change) {
                    $this->configure_connection(self::SOURCE_ID, self::RECONNECTED_TOKEN);
                } elseif ("replace" === $change) {
                    $this->configure_connection(self::OTHER_SOURCE_ID);
                    $backfill->cancel_backfill();
                    $this->assertSame(Product_Backfill::START_FRESH, $backfill->start_or_resume_backfill(self::OTHER_SOURCE_ID));
                } else {
                    $backfill->cancel_backfill();
                }
                $successor = [get_option(Product_Backfill::OPTION_STATE), get_option(Product_Backfill::OPTION_BATCH, false)];
                // The change revoked the old credential; applied as a terminal
                // rejection, this answer would end the successor's run.
                return new Response(401, ["Content-Type" => "application/problem+json"], '{"detail":"revoked"}');
            });

            do_action_ref_array(Product_Backfill::ACTION_HOOK, $args);

            $this->assertSame($successor, [get_option(Product_Backfill::OPTION_STATE), get_option(Product_Backfill::OPTION_BATCH, false)]);
            $this->assertCount(1, $this->requests);

            if ("cancel" === $change) {
                $this->assertSame(Product_Backfill::STATUS_NOT_SCHEDULED, $successor[0]["status"]);
                $this->assertFalse((bool) as_has_scheduled_action(Product_Backfill::ACTION_HOOK, null, Product_Backfill::ACTION_GROUP));
                return;
            }

            if ("replace" === $change) {
                $this->assertSame([], $this->queued_actions(1));
                $this->queued_args(1, self::OTHER_SOURCE_ID);
                return;
            }

            // The same wake-up replays the unchanged request with the current
            // credential, then finishes the run.
            $this->assertSame(self::SOURCE_ID, $successor[1]["listing_source_id"]);
            $this->run_queued(1);
            $this->assertCount(2, $this->requests);
            $this->assertSame((string) $this->request()->getBody(), (string) $this->request(1)->getBody());
            $this->assertSame($this->request()->getHeaderLine("Idempotency-Key"), $this->request(1)->getHeaderLine("Idempotency-Key"));
            $this->assertSame("Bearer " . self::RECONNECTED_TOKEN, $this->request(1)->getHeaderLine("Authorization"));
            $this->assertSame(Product_Backfill::STATUS_COMPLETE, (new Product_Backfill())->get_status_details()["status"]);
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH, false));
        } finally {
            $product->delete(true);
        }
    }

    public function test_action_queued_by_the_previous_release_continues_its_run_once()
    {
        // 0.3.1 queued [source, page] and suffixed retries with "retry-<sha256>".
        $payloads = [["sourceListingId" => "legacy", "url" => "https://example.com/legacy", "images" => []]];
        update_option(Product_Backfill::OPTION_BATCH, [
            "listing_source_id" => self::SOURCE_ID,
            "page" => 1,
            "product_count" => 1,
            "payloads" => $payloads,
            "idempotency_key" => "legacy-key",
            "retry_attempt" => 1,
        ], false);
        update_option(Product_Backfill::OPTION_STATE, ["status" => Product_Backfill::STATUS_FAILED], false);
        $legacy_retry = [self::SOURCE_ID, 1, "retry-" . hash("sha256", "legacy-key:1")];

        do_action_ref_array(Product_Backfill::ACTION_HOOK, $legacy_retry);

        $this->assertCount(1, $this->requests);
        $this->assertSame("legacy-key", $this->request()->getHeaderLine("Idempotency-Key"));
        $this->assertSame($payloads, json_decode((string) $this->request()->getBody(), true));
        $this->assertSame(Product_Backfill::STATUS_COMPLETE, (new Product_Backfill())->get_status_details()["status"]);

        // Once the run has a wake-up, a leftover action of the old shape is ignored.
        do_action_ref_array(Product_Backfill::ACTION_HOOK, [self::SOURCE_ID, 1]);
        do_action_ref_array(Product_Backfill::ACTION_HOOK, $legacy_retry);
        $this->assertCount(1, $this->requests);
        $this->assertSame(Product_Backfill::STATUS_COMPLETE, (new Product_Backfill())->get_status_details()["status"]);
    }

    public function test_counted_batch_left_by_a_stalled_holder_is_handed_off_without_resending()
    {
        // The holder counted page 1, then stalled before clearing the
        // snapshot or queueing page 2, and its lease expired.
        update_option(Product_Backfill::OPTION_BATCH, [
            "listing_source_id" => self::SOURCE_ID,
            "page" => 1,
            "product_count" => Product_Backfill::BATCH_SIZE,
            "last_product_id" => PHP_INT_MAX - 1,
            "local_failures" => 0,
            "payloads" => [["sourceListingId" => "counted", "url" => "https://example.com/counted", "images" => []]],
            "idempotency_key" => "counted-key",
        ], false);
        update_option(Product_Backfill::OPTION_STATE, [
            "status" => Product_Backfill::STATUS_RUNNING,
            "last_completed_page" => "1",
            "last_product_id" => (string) (PHP_INT_MAX - 1),
            "last_counted_batch" => hash("sha256", "counted-key"),
            "permanent_failure_count" => "1",
        ], false);
        $backfill = new Product_Backfill();

        $this->assertSame(Product_Backfill::START_RESUMED, $backfill->start_or_resume_backfill(self::SOURCE_ID));
        $this->run_queued(1);

        $this->assertSame([], $this->requests);
        $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH, false));
        $this->assertSame("1", $backfill->get_status_details()["permanent_failure_count"]);
        $this->run_queued(2);
        $this->assertSame([], $this->requests);
        $state = $backfill->get_status_details();
        $this->assertSame("2", $state["last_completed_page"]);
        $this->assertSame("1", $state["permanent_failure_count"]);
        $this->assertNotSame("", $state["completed_at"]);
    }

    private function retryable_failures()
    {
        $request = new Request("POST", self::ENDPOINT);
        $problem = static function ($status) {
            return new Response($status, ["Content-Type" => "application/problem+json"], "{}");
        };

        return [
            new ConnectException("Timed out", $request),
            $problem(503),
            $problem(429),
            $problem(500),
            $problem(408),
            $problem(502),
            new ConnectException("Timed out", $request),
            $problem(504),
            $problem(503),
            $problem(503),
        ];
    }

    public function test_retryable_failures_back_off_then_stop_and_manual_resume_replays_the_batch()
    {
        $product = $this->add_product("Outage");
        $failures = $this->retryable_failures();
        $this->assertCount(Product_Backfill::MAX_ATTEMPTS, $failures);
        $this->mock_responses(array_merge($failures, [$this->admission(1)]));
        $backfill = new Product_Backfill();
        try {
            $this->assertTrue($backfill->schedule_backfill(self::SOURCE_ID));
            $delays = [];

            foreach (array_keys($failures) as $index) {
                $attempt = $index + 1;
                $args = $this->queued_args(1);
                try {
                    $this->run_queued(1);
                    $this->fail("Expected a retryable failure");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString("request failed", $exception->getMessage());
                }
                $this->assertCount($attempt, $this->requests);
                $snapshot = get_option(Product_Backfill::OPTION_BATCH);
                $this->assertSame($this->request()->getHeaderLine("Idempotency-Key"), $snapshot["idempotency_key"]);
                $this->assertSame($snapshot["idempotency_key"], $this->request($index)->getHeaderLine("Idempotency-Key"));
                $this->assertSame((string) $this->request()->getBody(), (string) $this->request($index)->getBody());
                $status = $backfill->get_status_details();
                $this->assertSame((string) $attempt, $status["attempts"]);
                if (Product_Backfill::MAX_ATTEMPTS === $attempt) {
                    break;
                }

                $this->assertSame(Product_Backfill::STATUS_RETRY_SCHEDULED, $status["status"]);
                $this->assertNotSame($args[2], $this->queued_args(1)[2]);
                $this->assertEqualsWithDelta(time() + Product_Backfill::retry_delay($attempt), $status["next_scheduled_at"], 5);
                $delays[] = Product_Backfill::retry_delay($attempt);
            }

            $this->assertSame([60, 120, 240, 480, 960, 1920, 3600, 3600, 3600], $delays);
            $status = $backfill->get_status_details();
            $this->assertSame(Product_Backfill::STATUS_STOPPED, $status["status"]);
            $this->assertSame([], $this->queued_actions(1));
            $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
            // The action of the last attempt cannot continue a stopped batch.
            do_action_ref_array(Product_Backfill::ACTION_HOOK, $args);
            $this->assertCount(Product_Backfill::MAX_ATTEMPTS, $this->requests);

            // A manual resume after recovery replays the retained request.
            $product->set_name("Edited while stopped");
            $product->save();
            $this->assertSame(Product_Backfill::START_RESUMED, $backfill->start_or_resume_backfill(self::SOURCE_ID));
            $this->assertSame("0", $backfill->get_status_details()["attempts"]);
            $this->run_queued(1);
            $this->assertCount(Product_Backfill::MAX_ATTEMPTS + 1, $this->requests);
            $this->assertSame((string) $this->request()->getBody(), (string) $this->request(Product_Backfill::MAX_ATTEMPTS)->getBody());
            $this->assertSame($snapshot["idempotency_key"], $this->request(Product_Backfill::MAX_ATTEMPTS)->getHeaderLine("Idempotency-Key"));
            $this->assertFalse(get_option(Product_Backfill::OPTION_BATCH));
            $this->assertSame(Product_Backfill::STATUS_COMPLETE, $backfill->get_status_details()["status"]);
        } finally {
            $product->delete(true);
        }
    }

    public function test_deactivation_stops_the_backfill_until_a_manual_resume()
    {
        $product = $this->add_product("Paused by deactivation");
        $this->mock_responses([
            new Response(503, ["Content-Type" => "application/problem+json"], "{}"),
            $this->admission(1),
        ]);
        $backfill = new Product_Backfill();
        try {
            $this->assertTrue($backfill->schedule_backfill(self::SOURCE_ID));
            try {
                $this->run_queued(1);
                $this->fail("Expected a retryable failure");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("request failed", $exception->getMessage());
            }
            $retry = $this->queued_args(1);
            $snapshot = get_option(Product_Backfill::OPTION_BATCH);

            Plugin::deactivate();
            $this->assertSame([], $this->queued_actions(1));
            $status = $backfill->get_status_details();
            $this->assertSame(Product_Backfill::STATUS_STOPPED, $status["status"]);
            $this->assertStringContainsString("deactivated", $status["last_error"]);
            $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
            do_action_ref_array(Product_Backfill::ACTION_HOOK, $retry);
            $this->assertCount(1, $this->requests);

            $this->assertSame(Product_Backfill::START_RESUMED, $backfill->start_or_resume_backfill(self::SOURCE_ID));
            $this->run_queued(1);
            $this->assertCount(2, $this->requests);
            $this->assertSame($snapshot["idempotency_key"], $this->request(1)->getHeaderLine("Idempotency-Key"));
            $this->assertSame((string) $this->request()->getBody(), (string) $this->request(1)->getBody());
            $this->assertSame(Product_Backfill::STATUS_COMPLETE, $backfill->get_status_details()["status"]);
        } finally {
            $product->delete(true);
        }
    }

    public function test_run_stopped_between_batches_resumes_at_its_next_page()
    {
        $first = $this->add_product("Already admitted");
        $second = $this->add_product("Next page");
        $this->mock_responses([$this->admission(1)]);
        $backfill = new Product_Backfill();
        try {
            // Page 1 was admitted and page 2 queued when work was stopped.
            update_option(Product_Backfill::OPTION_STATE, [
                "status" => Product_Backfill::STATUS_SCHEDULED,
                "last_completed_page" => "1",
                "last_product_id" => (string) $first->get_id(),
                "wake" => "wake-page-two",
            ], false);
            $backfill->stop_backfill("Stopped for this test.");
            $this->assertSame(Product_Backfill::STATUS_STOPPED, $backfill->get_status_details()["status"]);
            do_action_ref_array(Product_Backfill::ACTION_HOOK, [self::SOURCE_ID, 2, "wake-page-two"]);
            $this->assertSame([], $this->requests);

            $this->assertSame(Product_Backfill::START_RESUMED, $backfill->start_or_resume_backfill(self::SOURCE_ID));
            $this->run_queued(2);
            $body = json_decode((string) $this->request()->getBody(), true);
            $this->assertSame([(string) $second->get_id()], array_column($body, "sourceListingId"));
            $state = $backfill->get_status_details();
            $this->assertSame(Product_Backfill::STATUS_COMPLETE, $state["status"]);
            $this->assertSame("2", $state["last_completed_page"]);
        } finally {
            $first->delete(true);
            $second->delete(true);
        }
    }
}
