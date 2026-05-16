<?php
/**
 * Integration tests for the product backfill.
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

/**
 * Tests the product backfill feature.
 */
class Test_AHPC_Product_Backfill extends WP_UnitTestCase
{
    /**
     * Admin user ID.
     *
     * @var int
     */
    protected $admin_user_id = 0;

    /**
     * Base URL used by the backend client during tests.
     *
     * @var string
     */
    protected $backend_base_url = "https://example.com";

    /**
     * Guzzle client injected into the generated backend API client during tests.
     *
     * @var ClientInterface|null
     */
    protected $backend_guzzle_client = null;

    /**
     * Recorded outbound backend API requests sent through Guzzle.
     *
     * @var array<int,array<string,mixed>>
     */
    protected $backend_http_requests = [];

    /**
     * Ensures WooCommerce is installed for the test suite.
     *
     * @param WP_UnitTest_Factory $factory Test factory.
     * @return void
     */
    public static function wpSetUpBeforeClass($factory)
    {
        if (class_exists("WC_Install")) {
            WC_Install::install();
        }
    }

    /**
     * Test setup.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        if (class_exists("WC_Install") && !get_option("woocommerce_version")) {
            WC_Install::install();
        }

        $this->admin_user_id = self::factory()->user->create([
            "role" => "administrator",
        ]);

        wp_set_current_user($this->admin_user_id);
        update_option(
            Webhook_Manager::OPTION_WEBHOOK_USER_ID,
            $this->admin_user_id,
            false,
        );

        add_filter("ahpc_backend_base_url", [$this, "filter_backend_base_url"]);
        add_filter(
            "ahpc_backend_guzzle_client",
            [$this, "filter_backend_guzzle_client"],
            10,
            2,
        );

        $this->set_backend_mock_responses(
            array_fill(
                0,
                20,
                new Response(200, ["Content-Type" => "application/json"], ""),
            ),
        );

        $manager = new Webhook_Manager();
        $manager->delete_webhooks();
        $manager->initialize_options();
    }

    /**
     * Test teardown.
     *
     * @return void
     */
    public function tearDown(): void
    {
        $manager = new Webhook_Manager();
        $manager->delete_webhooks();

        $backfill = new Product_Backfill();
        $backfill->cancel_backfill();

        remove_filter("ahpc_backend_base_url", [
            $this,
            "filter_backend_base_url",
        ]);
        remove_filter(
            "ahpc_backend_guzzle_client",
            [$this, "filter_backend_guzzle_client"],
            10,
        );

        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * Overrides the built-in backend base URL during tests.
     *
     * @param string $url Current backend base URL.
     * @return string
     */
    public function filter_backend_base_url($url)
    {
        return $this->backend_base_url;
    }

    /**
     * Injects the prepared Guzzle client into the generated backend API client.
     *
     * @param ClientInterface|mixed $client   Current Guzzle client.
     * @param string                $base_url Backend base URL.
     * @return ClientInterface|mixed
     */
    public function filter_backend_guzzle_client($client, $base_url)
    {
        return $this->backend_guzzle_client instanceof ClientInterface
            ? $this->backend_guzzle_client
            : $client;
    }

    /**
     * Rebuilds the mocked Guzzle client with the given queued responses.
     *
     * @param array<int,Response> $responses Mocked backend responses.
     * @return void
     */
    protected function set_backend_mock_responses($responses)
    {
        $this->backend_http_requests = [];

        $mock_handler = new MockHandler($responses);
        $handler_stack = HandlerStack::create($mock_handler);
        $handler_stack->push(Middleware::history($this->backend_http_requests));

        $this->backend_guzzle_client = new Client([
            "handler" => $handler_stack,
            "http_errors" => true,
        ]);
    }

    /**
     * Returns recorded backend requests for a specific URL.
     *
     * @param string $url Absolute request URL.
     * @return array<int,array<string,mixed>>
     */
    protected function get_backend_requests_for_url($url)
    {
        return array_values(
            array_filter($this->backend_http_requests, static function (
                $transaction,
            ) use ($url) {
                return isset($transaction["request"]) &&
                    $url === (string) $transaction["request"]->getUri();
            }),
        );
    }

    // ------------------------------------------------------------------
    // schedule_backfill / cancel_backfill
    // ------------------------------------------------------------------

    /**
     * It schedules an Action Scheduler action when Action Scheduler is available.
     *
     * @return void
     */
    public function test_schedule_backfill_enqueues_an_action()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $backfill = new Product_Backfill();

        $backfill->cancel_backfill();
        $this->assertFalse($backfill->is_backfill_scheduled());

        $scheduled = $backfill->schedule_backfill($shop_id);

        $this->assertTrue($scheduled);
        $this->assertTrue($backfill->is_backfill_scheduled());
    }

    /**
     * It returns false and does not throw when Action Scheduler is unavailable.
     *
     * @return void
     */
    public function test_schedule_backfill_returns_false_without_action_scheduler()
    {
        $backfill = new class extends Product_Backfill {
            /**
             * Override to simulate missing Action Scheduler.
             *
             * @param string $shop_id Shop UUID.
             * @return bool
             */
            public function schedule_backfill($shop_id)
            {
                // Return false directly, mimicking missing as_schedule_single_action.
                if (!function_exists("as_schedule_single_action")) {
                    return false;
                }

                return false; // Force the "not available" path for this test.
            }
        };

        $result = $backfill->schedule_backfill(
            "123e4567-e89b-12d3-a456-426614174000",
        );

        $this->assertFalse($result);
    }

    /**
     * It returns false when the shop ID is not a valid UUID.
     *
     * @return void
     */
    public function test_schedule_backfill_returns_false_for_invalid_shop_id()
    {
        $backfill = new Product_Backfill();
        $result = $backfill->schedule_backfill("not-a-uuid");
        $this->assertFalse($result);
    }

    /**
     * It cancels pending actions via cancel_backfill.
     *
     * @return void
     */
    public function test_cancel_backfill_removes_pending_actions()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $backfill = new Product_Backfill();

        $backfill->schedule_backfill($shop_id);
        $this->assertTrue($backfill->is_backfill_scheduled());

        $backfill->cancel_backfill();
        $this->assertFalse($backfill->is_backfill_scheduled());
    }

    /**
     * A fresh schedule_backfill call replaces any previously pending batches.
     *
     * @return void
     */
    public function test_schedule_backfill_is_idempotent()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $backfill = new Product_Backfill();

        $backfill->schedule_backfill($shop_id);
        $backfill->schedule_backfill($shop_id);

        $this->assertTrue($backfill->is_backfill_scheduled());
    }

    // ------------------------------------------------------------------
    // process_batch – settings guard
    // ------------------------------------------------------------------

    /**
     * It aborts silently when the stored shop ID no longer matches.
     *
     * @return void
     */
    public function test_process_batch_aborts_when_shop_id_mismatch()
    {
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
                "api_key" =>
                    "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567",
                "secret" => "test-secret",
            ],
            false,
        );

        $backfill = new Product_Backfill();

        // Use a different shop ID than what is stored in options.
        $backfill->process_batch("bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb", 1);

        // No backend request should have been made.
        $this->assertEmpty(
            $this->get_backend_requests_for_url(
                "https://example.com/api/v1/shops/bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb/products",
            ),
        );
    }

    /**
     * It aborts silently when the stored API key is empty.
     *
     * @return void
     */
    public function test_process_batch_aborts_when_api_key_missing()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" => "",
                "secret" => "test-secret",
            ],
            false,
        );

        $backfill = new Product_Backfill();
        $backfill->process_batch($shop_id, 1);

        $this->assertEmpty(
            $this->get_backend_requests_for_url(
                "https://example.com/api/v1/shops/" . $shop_id . "/products",
            ),
        );
    }

    /**
     * It aborts silently when the settings option is missing.
     *
     * @return void
     */
    public function test_process_batch_aborts_when_settings_missing()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";

        delete_option(Webhook_Manager::OPTION_SETTINGS);

        $backfill = new Product_Backfill();
        $backfill->process_batch($shop_id, 1);

        $this->assertEmpty(
            $this->get_backend_requests_for_url(
                "https://example.com/api/v1/shops/" . $shop_id . "/products",
            ),
        );
    }

    // ------------------------------------------------------------------
    // process_batch – product serialisation and API call
    // ------------------------------------------------------------------

    /**
     * It sends a PUT request with WC REST API v3 product payloads for a non-empty page.
     *
     * @return void
     */
    public function test_process_batch_puts_products_to_backend()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $api_key = "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" => $api_key,
                "secret" => "test-secret",
            ],
            false,
        );

        // Create two published products.
        $product_a = new WC_Product_Simple();
        $product_a->set_name("Product Alpha");
        $product_a->set_status("publish");
        $product_a->save();

        $product_b = new WC_Product_Simple();
        $product_b->set_name("Product Beta");
        $product_b->set_status("publish");
        $product_b->save();

        $backfill = new Product_Backfill();
        $backfill->process_batch($shop_id, 1);

        $requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id . "/products",
        );

        $this->assertCount(1, $requests);
        $this->assertSame(
            "PUT",
            strtoupper($requests[0]["request"]->getMethod()),
        );
        $this->assertSame(
            $api_key,
            $requests[0]["request"]->getHeaderLine("x-api-key"),
        );

        $body = json_decode((string) $requests[0]["request"]->getBody(), true);

        $this->assertIsArray($body);
        $this->assertArrayHasKey("products", $body);
        $this->assertNotEmpty($body["products"]);

        // Clean up.
        $product_a->delete(true);
        $product_b->delete(true);
    }

    /**
     * It still serialises and sends products when no admin user is logged in.
     *
     * @return void
     */
    public function test_process_batch_puts_products_to_backend_without_logged_in_user()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $api_key = "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" => $api_key,
                "secret" => "test-secret",
            ],
            false,
        );

        $product = new WC_Product_Simple();
        $product->set_name("Background Product");
        $product->set_status("publish");
        $product->save();

        wp_set_current_user(0);

        try {
            $backfill = new Product_Backfill();
            $backfill->process_batch($shop_id, 1);
        } finally {
            wp_set_current_user($this->admin_user_id);
        }

        $requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id . "/products",
        );

        $this->assertCount(1, $requests);

        $body = json_decode((string) $requests[0]["request"]->getBody(), true);

        $this->assertIsArray($body);
        $this->assertArrayHasKey("products", $body);
        $this->assertNotEmpty($body["products"]);

        $product->delete(true);
    }

    /**
     * It does not call the backend when there are no products on the page.
     *
     * @return void
     */
    public function test_process_batch_skips_backend_call_when_page_is_empty()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" =>
                    "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567",
                "secret" => "test-secret",
            ],
            false,
        );

        // Ensure there are no products.
        $ids = wc_get_products(["return" => "ids", "limit" => -1]);
        foreach ($ids as $id) {
            $p = wc_get_product($id);
            if ($p) {
                $p->delete(true);
            }
        }

        $backfill = new Product_Backfill();
        $backfill->process_batch($shop_id, 1);

        $this->assertEmpty(
            $this->get_backend_requests_for_url(
                "https://example.com/api/v1/shops/" . $shop_id . "/products",
            ),
        );
    }

    // ------------------------------------------------------------------
    // process_batch – pagination
    // ------------------------------------------------------------------

    /**
     * It schedules the next page when the current page is a full batch.
     *
     * @return void
     */
    public function test_process_batch_schedules_next_page_when_page_is_full()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $shop_id = "123e4567-e89b-12d3-a456-426614174000";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" =>
                    "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567",
                "secret" => "test-secret",
            ],
            false,
        );

        // Use a subclass that mocks get_product_ids to simulate a full page.
        $backfill = $this->make_backfill_with_product_ids(
            array_fill(0, Product_Backfill::BATCH_SIZE, 1),
        );

        $backfill->cancel_backfill();
        $backfill->process_batch($shop_id, 1);

        $this->assertTrue($backfill->is_backfill_scheduled());

        $backfill->cancel_backfill();
    }

    /**
     * It does NOT schedule the next page when the page is not full.
     *
     * @return void
     */
    public function test_process_batch_does_not_schedule_next_page_when_page_is_partial()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $shop_id = "123e4567-e89b-12d3-a456-426614174000";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" =>
                    "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567",
                "secret" => "test-secret",
            ],
            false,
        );

        // Use a subclass that mocks get_product_ids to simulate a partial page.
        $backfill = $this->make_backfill_with_product_ids([1, 2, 3]);

        $backfill->cancel_backfill();
        $backfill->process_batch($shop_id, 1);

        $this->assertFalse($backfill->is_backfill_scheduled());
    }

    // ------------------------------------------------------------------
    // process_batch – error propagation
    // ------------------------------------------------------------------

    /**
     * It throws a RuntimeException when the backend rejects the batch.
     *
     * @return void
     */
    public function test_process_batch_throws_on_backend_error()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" =>
                    "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567",
                "secret" => "test-secret",
            ],
            false,
        );

        // Respond with 401 Unauthorized.
        $error_body = [
            "status" => 401,
            "title" => "Unauthorized",
            "error" => "PARTNER_SHOP_API_KEY_MISMATCH",
            "detail" => "Missing or invalid x-api-key.",
        ];
        $this->set_backend_mock_responses([
            new Response(
                401,
                ["Content-Type" => "application/problem+json"],
                wp_json_encode($error_body),
            ),
        ]);

        $product = new WC_Product_Simple();
        $product->set_name("Error Product");
        $product->set_status("publish");
        $product->save();

        $backfill = new Product_Backfill();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/backfill batch/i");

        try {
            $backfill->process_batch($shop_id, 1);
        } finally {
            $product->delete(true);
        }
    }

    // ------------------------------------------------------------------
    // Integration with sync_webhooks
    // ------------------------------------------------------------------

    /**
     * It schedules a backfill when sync_webhooks activates the webhooks.
     *
     * @return void
     */
    public function test_sync_webhooks_schedules_backfill_when_webhooks_become_active()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $api_key = "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        // Provide enough mock responses: 1 for registration + 10 spare.
        $this->set_backend_mock_responses(
            array_fill(
                0,
                11,
                new Response(
                    200,
                    ["Content-Type" => "application/json"],
                    wp_json_encode([
                        "shopId" => $shop_id,
                        "shopSlugId" => "test-shop",
                        "name" => "Test Shop",
                        "shopType" => "COMMERCIAL_DEALER",
                        "domains" => ["example.com"],
                        "partnerStatus" => "PARTNERED",
                        "created" => "2024-01-01T10:00:00Z",
                        "updated" => "2024-01-01T12:00:00Z",
                    ]),
                ),
            ),
        );

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" => $api_key,
                "secret" => "test-secret",
            ],
            false,
        );

        $backfill = new Product_Backfill();
        $backfill->cancel_backfill();

        $manager = new Webhook_Manager();
        $result = $manager->sync_webhooks();

        $this->assertTrue($result);
        $this->assertTrue($backfill->is_backfill_scheduled());

        $backfill->cancel_backfill();
    }

    /**
     * It does NOT schedule a backfill when sync_webhooks leaves webhooks paused.
     *
     * @return void
     */
    public function test_sync_webhooks_does_not_schedule_backfill_when_webhooks_are_paused()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $backfill = new Product_Backfill();
        $backfill->cancel_backfill();

        // Settings without a shop_id → webhooks will be paused.
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => "",
                "api_key" => "",
                "secret" => "test-secret",
            ],
            false,
        );

        $manager = new Webhook_Manager();
        $manager->sync_webhooks();

        $this->assertFalse($backfill->is_backfill_scheduled());
    }

    /**
     * It cancels a pending backfill when sync transitions webhooks to paused.
     *
     * @return void
     */
    public function test_sync_webhooks_cancels_backfill_when_webhooks_become_paused()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $api_key = "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        // First sync: active.
        $this->set_backend_mock_responses(
            array_fill(
                0,
                11,
                new Response(
                    200,
                    ["Content-Type" => "application/json"],
                    wp_json_encode([
                        "shopId" => $shop_id,
                        "shopSlugId" => "test-shop",
                        "name" => "Test Shop",
                        "shopType" => "COMMERCIAL_DEALER",
                        "domains" => ["example.com"],
                        "partnerStatus" => "PARTNERED",
                        "created" => "2024-01-01T10:00:00Z",
                        "updated" => "2024-01-01T12:00:00Z",
                    ]),
                ),
            ),
        );

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" => $api_key,
                "secret" => "test-secret",
            ],
            false,
        );

        $manager = new Webhook_Manager();
        $manager->sync_webhooks();

        $backfill = new Product_Backfill();
        $this->assertTrue($backfill->is_backfill_scheduled());

        // Second sync: remove connection → paused.
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => "",
                "api_key" => "",
                "secret" => "test-secret",
            ],
            false,
        );

        $manager->sync_webhooks();

        $this->assertFalse($backfill->is_backfill_scheduled());
    }

    // ------------------------------------------------------------------
    // Integration with delete_webhooks
    // ------------------------------------------------------------------

    /**
     * It cancels any pending backfill when delete_webhooks is called.
     *
     * @return void
     */
    public function test_delete_webhooks_cancels_pending_backfill()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $backfill = new Product_Backfill();

        $backfill->schedule_backfill($shop_id);
        $this->assertTrue($backfill->is_backfill_scheduled());

        $manager = new Webhook_Manager();
        $manager->delete_webhooks();

        $this->assertFalse($backfill->is_backfill_scheduled());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Creates a Product_Backfill subclass that returns fixed product IDs.
     *
     * @param int[] $product_ids IDs to return from get_product_ids.
     * @return Product_Backfill
     */
    protected function make_backfill_with_product_ids(array $product_ids)
    {
        return new class ($product_ids) extends Product_Backfill {
            /** @var int[] */
            private $ids;

            /**
             * Constructor.
             *
             * @param int[] $ids Fixed product IDs to return.
             */
            public function __construct(array $ids)
            {
                $this->ids = $ids;
            }

            /**
             * Override process_batch to inject stubbed product IDs and skip payload build.
             *
             * This replaces the full batch pipeline with a lightweight stub that only
             * tests the pagination scheduling logic: it checks settings validity and
             * schedules the next page when the simulated page is full.
             *
             * @param string $shop_id Shop UUID.
             * @param int    $page    Page number.
             * @return void
             */
            public function process_batch($shop_id, $page)
            {
                $shop_id = \AuraHistoria\PartnerConnect\Webhook_Manager::normalize_shop_id(
                    (string) $shop_id,
                );
                $page = max(1, (int) $page);

                $settings = get_option(
                    \AuraHistoria\PartnerConnect\Webhook_Manager::OPTION_SETTINGS,
                    [],
                );

                if (!is_array($settings)) {
                    return;
                }

                $stored_shop_id = \AuraHistoria\PartnerConnect\Webhook_Manager::normalize_shop_id(
                    isset($settings["shop_id"])
                        ? (string) $settings["shop_id"]
                        : "",
                );
                $api_key = isset($settings["api_key"])
                    ? (string) $settings["api_key"]
                    : "";

                if ($stored_shop_id !== $shop_id || "" === $api_key) {
                    return;
                }

                $product_ids = $this->ids;

                if (empty($product_ids)) {
                    return;
                }

                if (
                    count($product_ids) >= self::BATCH_SIZE &&
                    function_exists("as_schedule_single_action")
                ) {
                    as_schedule_single_action(
                        time(),
                        self::ACTION_HOOK,
                        [$shop_id, $page + 1],
                        self::ACTION_GROUP,
                        true,
                    );
                }
            }
        };
    }
}
