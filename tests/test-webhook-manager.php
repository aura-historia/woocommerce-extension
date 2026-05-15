<?php
/**
 * Integration tests for the webhook manager.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Plugin;
use AuraHistoria\PartnerConnect\Webhook_Manager;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Tests the managed WooCommerce webhooks.
 */
class Test_AHPC_Webhook_Manager extends WP_UnitTestCase
{
    /**
     * Admin user ID.
     *
     * @var int
     */
    protected $admin_user_id = 0;

    /**
     * Base URL used by the webhook manager during tests.
     *
     * @var string
     */
    protected $backend_base_url = "https://example.com";

    /**
     * Recorded outbound HTTP requests.
     *
     * @var array<int,array<string,mixed>>
     */
    protected $http_requests = [];

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

        add_filter("pre_http_request", [$this, "mock_http_request"], 10, 3);
        add_filter("ahpc_backend_base_url", [$this, "filter_backend_base_url"]);
        add_filter(
            "ahpc_backend_guzzle_client",
            [$this, "filter_backend_guzzle_client"],
            10,
            2,
        );

        $this->set_backend_mock_responses(
            array_fill(0, 10, $this->mock_backend_registration_response()),
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

        remove_filter("pre_http_request", [$this, "mock_http_request"], 10);
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
     * Rebuilds the mocked backend Guzzle client with the given queued responses.
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
     * Builds a mocked backend registration response.
     *
     * @param int               $status_code HTTP status code.
     * @param array<string,mixed>|null $body Typed response payload.
     * @return Response
     */
    protected function mock_backend_registration_response(
        $status_code = 200,
        $body = null,
    ) {
        if (null === $body) {
            $body = [
                "shopId" => "123e4567-e89b-12d3-a456-426614174000",
                "shopSlugId" => "test-shop",
                "name" => "Test Shop",
                "shopType" => "COMMERCIAL_DEALER",
                "domains" => ["example.com"],
                "partnerStatus" => "PARTNERED",
                "created" => "2024-01-01T10:00:00Z",
                "updated" => "2024-01-01T12:00:00Z",
            ];
        }

        return new Response(
            $status_code,
            ["Content-Type" => "application/json"],
            wp_json_encode($body),
        );
    }

    /**
     * Returns recorded backend requests for a specific absolute URL.
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

    /**
     * Renders the plugin settings page for assertions.
     *
     * @param array<string,string> $query_args Query arguments to inject.
     * @return string
     */
    protected function render_plugin_settings_page($query_args = [])
    {
        update_option(
            Webhook_Manager::OPTION_PLUGIN_VERSION,
            AHPC_VERSION,
            false,
        );
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);

        $original_get = $_GET;
        $_GET = $query_args;

        $plugin = new Plugin();

        ob_start();
        $plugin->render_settings_page();
        $output = (string) ob_get_clean();

        $_GET = $original_get;

        return $output;
    }

    /**
     * Prevents real WordPress HTTP requests during webhook ping or delivery.
     *
     * @param mixed  $preempt Existing preempted response.
     * @param array  $args HTTP arguments.
     * @param string $url Request URL.
     * @return array
     */
    public function mock_http_request($preempt, $args, $url)
    {
        $this->http_requests[] = [
            "url" => $url,
            "args" => $args,
        ];

        return $this->mock_json_response(200, "");
    }

    /**
     * Builds a mocked JSON HTTP response.
     *
     * @param int          $status_code HTTP status code.
     * @param array|string $body        Response body payload.
     * @return array
     */
    protected function mock_json_response($status_code, $body)
    {
        $message = $status_code >= 200 && $status_code < 300 ? "OK" : "Error";

        return [
            "headers" => [],
            "body" => is_string($body) ? $body : wp_json_encode($body),
            "response" => [
                "code" => $status_code,
                "message" => $message,
            ],
            "cookies" => [],
            "filename" => null,
        ];
    }

    /**
     * It creates the three managed webhooks when connection details are present.
     *
     * @return void
     */
    public function test_sync_webhooks_creates_three_active_webhooks()
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

        $manager = new Webhook_Manager();
        $result = $manager->sync_webhooks();
        $ids = $manager->get_webhook_ids();

        $this->assertTrue($result);
        $this->assertCount(3, $ids);

        foreach ($manager->get_managed_topics() as $topic => $label) {
            $this->assertArrayHasKey($topic, $ids);

            $webhook = new WC_Webhook($ids[$topic]);

            $this->assertSame(
                $manager->get_webhook_name($topic),
                $webhook->get_name(),
            );
            $this->assertSame($topic, $webhook->get_topic());
            $this->assertSame("active", $webhook->get_status());
            $this->assertSame(
                "https://example.com/api/v1/webhooks/woocommerce/" . $shop_id,
                $webhook->get_delivery_url(),
            );
            $this->assertSame("test-secret", $webhook->get_secret());
            $this->assertSame($this->admin_user_id, $webhook->get_user_id());
            $this->assertSame("wp_api_v3", $webhook->get_api_version());
        }

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id,
        );

        $this->assertCount(1, $registration_requests);
        $this->assertSame(
            "PATCH",
            strtoupper($registration_requests[0]["request"]->getMethod()),
        );
        $this->assertSame(
            $api_key,
            $registration_requests[0]["request"]->getHeaderLine("x-api-key"),
        );
        $this->assertStringContainsString(
            '"woocommerceWebhookSecret":"test-secret"',
            (string) $registration_requests[0]["request"]->getBody(),
        );

        $delivery_requests = array_values(
            array_filter($this->http_requests, static function ($request) use (
                $shop_id,
            ) {
                return "https://example.com/api/v1/webhooks/woocommerce/" .
                    $shop_id ===
                    $request["url"];
            }),
        );

        $this->assertNotEmpty($delivery_requests);

        foreach ($delivery_requests as $request) {
            $this->assertSame(
                $api_key,
                $request["args"]["headers"]["x-api-key"],
            );
        }
    }

    /**
     * It updates existing managed webhooks instead of duplicating them.
     *
     * @return void
     */
    public function test_sync_webhooks_reuses_existing_webhooks()
    {
        $manager = new Webhook_Manager();
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $original_api_key =
            "aurahistoria_originaltoken_abcdefghijklmnopqrstuvwxyz1234567";
        $updated_api_key =
            "aurahistoria_updatedtoken_abcdefghijklmnopqrstuvwxyz7654321";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" => $original_api_key,
                "secret" => "original-secret",
            ],
            false,
        );

        $manager->sync_webhooks();
        $first_ids = $manager->get_webhook_ids();

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" => $updated_api_key,
                "secret" => "updated-secret",
            ],
            false,
        );

        $result = $manager->sync_webhooks();
        $second_ids = $manager->get_webhook_ids();

        $this->assertTrue($result);
        $this->assertSame($first_ids, $second_ids);

        foreach (array_keys($manager->get_managed_topics()) as $topic) {
            $webhook = new WC_Webhook($second_ids[$topic]);

            $this->assertSame("active", $webhook->get_status());
            $this->assertSame(
                "https://example.com/api/v1/webhooks/woocommerce/" . $shop_id,
                $webhook->get_delivery_url(),
            );
            $this->assertSame("updated-secret", $webhook->get_secret());
        }

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id,
        );

        $this->assertCount(2, $registration_requests);
        $this->assertSame(
            $updated_api_key,
            $registration_requests[1]["request"]->getHeaderLine("x-api-key"),
        );
    }

    /**
     * It keeps managed webhooks paused until both connection values are saved.
     *
     * @return void
     */
    public function test_sync_webhooks_pauses_delivery_until_connection_is_complete()
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

        $manager = new Webhook_Manager();
        $result = $manager->sync_webhooks();
        $ids = $manager->get_webhook_ids();

        $this->assertTrue($result);
        $this->assertCount(3, $ids);

        foreach ($ids as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame("paused", $webhook->get_status());
            $this->assertSame(
                "https://example.com/api/v1/webhooks/woocommerce/" . $shop_id,
                $webhook->get_delivery_url(),
            );
        }

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id,
        );

        $this->assertSame([], $registration_requests);
    }

    /**
     * It deletes the managed webhooks and clears the plugin options.
     *
     * @return void
     */
    public function test_delete_webhooks_removes_managed_webhooks_and_options()
    {
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => "123e4567-e89b-12d3-a456-426614174000",
                "api_key" =>
                    "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567",
                "secret" => "test-secret",
            ],
            false,
        );

        $manager = new Webhook_Manager();
        $manager->sync_webhooks();
        $webhook_ids = $manager->get_webhook_ids();

        $manager->delete_webhooks();

        $this->assertFalse(get_option(Webhook_Manager::OPTION_SETTINGS, false));
        $this->assertFalse(
            get_option(Webhook_Manager::OPTION_WEBHOOK_IDS, false),
        );

        foreach ($webhook_ids as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame(0, $webhook->get_id());
        }
    }

    /**
     * It pauses every managed webhook on demand.
     *
     * @return void
     */
    public function test_pause_webhooks_sets_status_to_paused()
    {
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => "123e4567-e89b-12d3-a456-426614174000",
                "api_key" =>
                    "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567",
                "secret" => "test-secret",
            ],
            false,
        );

        $manager = new Webhook_Manager();
        $manager->sync_webhooks();
        $manager->pause_webhooks();

        foreach ($manager->get_webhook_ids() as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame("paused", $webhook->get_status());
        }
    }

    /**
     * It refuses to activate webhook delivery when the connection data is invalid.
     *
     * @return void
     */
    public function test_sync_webhooks_pauses_delivery_when_connection_data_is_invalid()
    {
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => "not-a-uuid",
                "api_key" => "invalid-key",
                "secret" => "test-secret",
            ],
            false,
        );

        $manager = new Webhook_Manager();
        $result = $manager->sync_webhooks();
        $ids = $manager->get_webhook_ids();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertCount(3, $ids);

        foreach ($ids as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame("paused", $webhook->get_status());
        }
    }

    /**
     * It surfaces typed backend error details from the OpenAPI response body.
     *
     * @return void
     */
    public function test_sync_webhooks_surfaces_backend_api_error_details()
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

        $this->set_backend_mock_responses([
            $this->mock_backend_registration_response(401, [
                "status" => 401,
                "title" => "Unauthorized",
                "error" => "PARTNER_SHOP_API_KEY_MISMATCH",
                "detail" => "Missing or empty 'x-api-key' header.",
            ]),
        ]);

        $manager = new Webhook_Manager();
        $result = $manager->sync_webhooks();
        $ids = $manager->get_webhook_ids();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(
            "ahpc_backend_registration_failed",
            $result->get_error_code(),
        );
        $this->assertStringContainsString(
            "HTTP 401",
            $result->get_error_message(),
        );
        $this->assertStringContainsString(
            "Missing or empty 'x-api-key' header.",
            $result->get_error_message(),
        );
        $this->assertCount(3, $ids);

        foreach ($ids as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame("paused", $webhook->get_status());
        }
    }

    /**
     * It shows the live Aura Historia connection status and uses an empty JSON
     * object for the lightweight health check.
     *
     * @return void
     */
    public function test_render_settings_page_shows_connected_status()
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

        $this->set_backend_mock_responses([
            $this->mock_backend_registration_response(),
        ]);

        $output = $this->render_plugin_settings_page();
        $requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id,
        );

        $this->assertStringContainsString("Connection status", $output);
        $this->assertStringContainsString("Connected", $output);
        $this->assertStringContainsString(
            "saved Shop ID and API key are still valid",
            $output,
        );
        $this->assertCount(1, $requests);
        $this->assertSame("{}", (string) $requests[0]["request"]->getBody());
    }

    /**
     * It shows a dedicated success notice after a verified settings save.
     *
     * @return void
     */
    public function test_render_settings_page_shows_verified_save_success_notice()
    {
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => "123e4567-e89b-12d3-a456-426614174000",
                "api_key" =>
                    "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567",
                "secret" => "test-secret",
            ],
            false,
        );

        $this->set_backend_mock_responses([
            $this->mock_backend_registration_response(),
        ]);

        $output = $this->render_plugin_settings_page([
            "settings-updated" => "true",
        ]);

        $this->assertStringContainsString(
            "Configuration saved and Aura Historia connection verified.",
            $output,
        );
    }
}
