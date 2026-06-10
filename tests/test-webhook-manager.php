<?php
/**
 * Integration tests for the webhook manager.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Plugin;
use AuraHistoria\PartnerConnect\Product_Backfill;
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
     * HTTPS admin base URL used by OAuth tests.
     *
     * @var string
     */
    protected $admin_base_url = "https://merchant.example/wp-admin/";

    /**
     * OAuth authorization endpoint used by tests.
     *
     * @var string
     */
    protected $oauth_authorize_url = "https://auth.example/oauth/authorize";

    /**
     * OAuth broker redirect URI used by tests.
     *
     * @var string
     */
    protected $oauth_broker_redirect_uri =
        "https://auth.example/api/oauth/client/redirect-broker";

    /**
     * OAuth client ID used by tests.
     *
     * @var string
     */
    protected $oauth_client_id = "019e7e6a-052b-78a3-9f57-eaaf619ca5ac";

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
        add_filter("admin_url", [$this, "filter_admin_url"], 10, 4);
        add_filter("ahpc_oauth_client_id", [$this, "filter_oauth_client_id"]);
        add_filter("ahpc_oauth_authorize_url", [
            $this,
            "filter_oauth_authorize_url",
        ]);
        add_filter("ahpc_oauth_broker_redirect_uri", [
            $this,
            "filter_oauth_broker_redirect_uri",
        ]);

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
        remove_filter("admin_url", [$this, "filter_admin_url"], 10);
        remove_filter("ahpc_oauth_client_id", [$this, "filter_oauth_client_id"]);
        remove_filter("ahpc_oauth_authorize_url", [
            $this,
            "filter_oauth_authorize_url",
        ]);
        remove_filter("ahpc_oauth_broker_redirect_uri", [
            $this,
            "filter_oauth_broker_redirect_uri",
        ]);
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
     * Forces an HTTPS admin URL so OAuth callback validation passes in tests.
     *
     * @param string $url     Current admin URL.
     * @param string $path    Requested admin path.
     * @param int|null $blog_id Blog ID.
     * @param string|null $scheme URL scheme.
     * @return string
     */
    public function filter_admin_url($url, $path = "", $blog_id = null, $scheme = null)
    {
        unset($url, $blog_id, $scheme);

        return $this->admin_base_url . ltrim((string) $path, "/");
    }

    /**
     * Overrides the OAuth client ID during tests.
     *
     * @param string $client_id Current client ID.
     * @return string
     */
    public function filter_oauth_client_id($client_id)
    {
        unset($client_id);

        return $this->oauth_client_id;
    }

    /**
     * Overrides the OAuth authorization endpoint during tests.
     *
     * @param string $authorize_url Current authorization endpoint.
     * @return string
     */
    public function filter_oauth_authorize_url($authorize_url)
    {
        unset($authorize_url);

        return $this->oauth_authorize_url;
    }

    /**
     * Overrides the OAuth broker redirect URI during tests.
     *
     * @param string $redirect_uri Current broker redirect URI.
     * @return string
     */
    public function filter_oauth_broker_redirect_uri($redirect_uri)
    {
        unset($redirect_uri);

        return $this->oauth_broker_redirect_uri;
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
                "createdBy" => "SYSTEM",
                "updatedBy" => "SYSTEM",
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
     * Builds a mocked OAuth token exchange response.
     *
     * @param string $access_token Access token to return.
     * @param string $scope        Granted OAuth scope string.
     * @param string $token_type   OAuth token type to return.
     * @return Response
     */
    protected function mock_oauth_token_response(
        $access_token,
        $scope = "products:write shops:manage",
        $token_type = "BEARER",
    ) {
        return new Response(
            200,
            ["Content-Type" => "application/json"],
            wp_json_encode([
                "access_token" => $access_token,
                "token_type" => $token_type,
                "expires_in" => null,
                "scope" => $scope,
            ]),
        );
    }

    /**
     * Decodes base64url test values.
     *
     * @param string $value Encoded value.
     * @return string
     */
    protected function base64url_decode($value)
    {
        $value = (string) $value;
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat("=", 4 - $padding);
        }

        return (string) base64_decode(strtr($value, "-_", "+/"));
    }

    /**
     * Encodes raw bytes using base64url without padding for test assertions.
     *
     * @param string $value Raw value.
     * @return string
     */
    protected function base64url_encode($value)
    {
        return rtrim(strtr(base64_encode((string) $value), "+/", "-_"), "=");
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
     * It aligns the settings save capability with the WooCommerce submenu capability.
     *
     * @return void
     */
    public function test_settings_page_capability_matches_manage_woocommerce()
    {
        if (function_exists("set_current_screen")) {
            set_current_screen("dashboard");
        }

        $plugin = new Plugin();
        $plugin->boot();

        $this->assertSame(
            "manage_woocommerce",
            apply_filters(
                "option_page_capability_" . Webhook_Manager::SETTINGS_GROUP,
                "manage_options",
            ),
        );
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
     * It renders the OAuth connection CTA instead of manual credentials.
     *
     * @return void
     */
    public function test_render_settings_page_uses_oauth_connection_cta()
    {
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => "",
                "api_key" => "",
                "secret" => "test-secret",
            ],
            false,
        );

        $output = $this->render_plugin_settings_page();

        $this->assertStringContainsString("Connect with Aura Historia", $output);
        $this->assertStringContainsString("ahpc-oauth-start-form", $output);
        $this->assertStringContainsString("ahpc_start_oauth", $output);
        $this->assertStringContainsString("form.submit", $output);
        $this->assertStringNotContainsString("id=\"ahpc-shop-id\"", $output);
        $this->assertStringNotContainsString("id=\"ahpc-api-key\"", $output);
    }

    /**
     * It builds an OAuth authorization URL with PKCE and broker state.
     *
     * @return void
     */
    public function test_create_oauth_authorization_url_uses_pkce_and_broker_state()
    {
        $plugin = new Plugin();
        $authorization_url = $plugin->create_oauth_authorization_url();

        $this->assertIsString($authorization_url);

        $parts = wp_parse_url($authorization_url);
        $this->assertIsArray($parts);
        $this->assertSame("https", $parts["scheme"]);
        $this->assertSame("auth.example", $parts["host"]);
        $this->assertSame("/oauth/authorize", $parts["path"]);

        parse_str($parts["query"], $query);

        $this->assertSame($this->oauth_client_id, $query["client_id"]);
        $this->assertSame(
            $this->oauth_broker_redirect_uri,
            $query["redirect_uri"],
        );
        $this->assertSame("code", $query["response_type"]);
        $this->assertSame("S256", $query["code_challenge_method"]);
        $this->assertSame("products:write shops:manage", $query["scope"]);
        $this->assertSame("true", $query["requires_partner_shop_id"]);
        $this->assertNotEmpty($query["code_challenge"]);
        $this->assertNotEmpty($query["state"]);

        $broker_state = json_decode(
            $this->base64url_decode($query["state"]),
            true,
        );

        $this->assertIsArray($broker_state);
        $this->assertSame(
            $this->admin_base_url . "admin.php?page=" . Plugin::PAGE_SLUG,
            $broker_state["redirect_uri"],
        );
        $this->assertMatchesRegularExpression(
            "/\A[A-Za-z0-9_-]{43,128}\z/",
            $broker_state["code_verifier"],
        );
        $this->assertMatchesRegularExpression(
            "/\A[A-Za-z0-9_-]{32,}\z/",
            $broker_state["client_state"],
        );
        $this->assertSame(
            $this->base64url_encode(
                hash("sha256", $broker_state["code_verifier"], true),
            ),
            $query["code_challenge"],
        );

        $stored_state = get_transient(
            Plugin::OAUTH_STATE_TRANSIENT_PREFIX .
                hash("sha256", $broker_state["client_state"]),
        );

        $this->assertIsArray($stored_state);
        $this->assertSame($this->admin_user_id, $stored_state["user_id"]);
    }

    /**
     * It exchanges the OAuth broker code, stores local credentials, and syncs webhooks.
     *
     * @return void
     */
    public function test_complete_oauth_connection_stores_token_and_syncs_webhooks()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $exchange_code = "01970f22-2bf0-7000-8000-000000000099";
        $access_token =
            "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => "",
                "api_key" => "",
                "secret" => "test-secret",
            ],
            false,
        );

        $plugin = new Plugin();
        $authorization_url = $plugin->create_oauth_authorization_url();
        $parts = wp_parse_url($authorization_url);
        parse_str($parts["query"], $query);
        $broker_state = json_decode(
            $this->base64url_decode($query["state"]),
            true,
        );

        $this->set_backend_mock_responses([
            $this->mock_oauth_token_response($access_token),
            $this->mock_backend_registration_response(200, [
                "shopId" => $shop_id,
                "shopSlugId" => "test-shop",
                "name" => "Test Shop",
                "shopType" => "COMMERCIAL_DEALER",
                "domains" => ["example.com"],
                "partnerStatus" => "PARTNERED",
                "createdBy" => "SYSTEM",
                "updatedBy" => "SYSTEM",
                "created" => "2024-01-01T10:00:00Z",
                "updated" => "2024-01-01T12:00:00Z",
            ]),
        ]);

        $result = $plugin->complete_oauth_connection(
            $shop_id,
            $exchange_code,
            $broker_state["client_state"],
        );

        $this->assertTrue($result);

        $settings = get_option(Webhook_Manager::OPTION_SETTINGS, []);
        $this->assertSame($shop_id, $settings["shop_id"]);
        $this->assertSame($access_token, $settings["api_key"]);
        $this->assertSame("test-secret", $settings["secret"]);

        $oauth_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/oauth/tokens/by-third-party-code/" .
                $exchange_code,
        );
        $this->assertCount(1, $oauth_requests);
        $this->assertSame(
            "GET",
            strtoupper($oauth_requests[0]["request"]->getMethod()),
        );
        $this->assertSame(
            "",
            $oauth_requests[0]["request"]->getHeaderLine("Authorization"),
        );

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id,
        );
        $this->assertCount(1, $registration_requests);
        $this->assertSame(
            "Bearer " . $access_token,
            $registration_requests[0]["request"]->getHeaderLine(
                "Authorization",
            ),
        );

        $manager = new Webhook_Manager();
        $this->assertCount(3, $manager->get_webhook_ids());

        foreach ($manager->get_webhook_ids() as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame("active", $webhook->get_status());
        }
    }

    /**
     * It rejects OAuth callbacks with missing or expired CSRF state before exchange.
     *
     * @return void
     */
    public function test_complete_oauth_connection_rejects_invalid_state()
    {
        $plugin = new Plugin();
        $result = $plugin->complete_oauth_connection(
            "123e4567-e89b-12d3-a456-426614174000",
            "01970f22-2bf0-7000-8000-000000000099",
            "invalid-state",
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame("ahpc_oauth_invalid_state", $result->get_error_code());
        $this->assertSame([], $this->backend_http_requests);
    }

    /**
     * It rejects OAuth token responses with an unsupported token type.
     *
     * @return void
     */
    public function test_complete_oauth_connection_rejects_invalid_token_type()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $exchange_code = "01970f22-2bf0-7000-8000-000000000099";
        $access_token =
            "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        $plugin = new Plugin();
        $authorization_url = $plugin->create_oauth_authorization_url();
        $parts = wp_parse_url($authorization_url);
        parse_str($parts["query"], $query);
        $broker_state = json_decode(
            $this->base64url_decode($query["state"]),
            true,
        );

        $this->set_backend_mock_responses([
            $this->mock_oauth_token_response(
                $access_token,
                "products:write shops:manage",
                "MAC",
            ),
        ]);

        $result = $plugin->complete_oauth_connection(
            $shop_id,
            $exchange_code,
            $broker_state["client_state"],
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(
            "ahpc_oauth_invalid_token_type",
            $result->get_error_code(),
        );
        $this->assertFalse(
            Webhook_Manager::is_valid_api_key(
                get_option(Webhook_Manager::OPTION_SETTINGS, [])["api_key"],
            ),
        );
    }

    /**
     * It rejects OAuth token responses that omit required scopes.
     *
     * @return void
     */
    public function test_complete_oauth_connection_rejects_missing_scope()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $exchange_code = "01970f22-2bf0-7000-8000-000000000099";
        $access_token =
            "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        $plugin = new Plugin();
        $authorization_url = $plugin->create_oauth_authorization_url();
        $parts = wp_parse_url($authorization_url);
        parse_str($parts["query"], $query);
        $broker_state = json_decode(
            $this->base64url_decode($query["state"]),
            true,
        );

        $this->set_backend_mock_responses([
            $this->mock_oauth_token_response($access_token, "products:write"),
        ]);

        $result = $plugin->complete_oauth_connection(
            $shop_id,
            $exchange_code,
            $broker_state["client_state"],
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame("ahpc_oauth_missing_scope", $result->get_error_code());
        $this->assertFalse(
            Webhook_Manager::is_valid_api_key(
                get_option(Webhook_Manager::OPTION_SETTINGS, [])["api_key"],
            ),
        );
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
            "Bearer " . $api_key,
            $registration_requests[0]["request"]->getHeaderLine("Authorization"),
        );
        $this->assertStringContainsString(
            '"woocommerceWebhookSecret":"test-secret"',
            (string) $registration_requests[0]["request"]->getBody(),
        );
        $this->assertStringContainsString(
            '"woocommerceLanguage":"en"',
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

        $this->assertSame([], $delivery_requests);
    }

    /**
     * It adds the Aura Historia access token to real webhook deliveries as x-api-key.
     *
     * @return void
     */
    public function test_plugin_adds_access_token_to_real_webhook_deliveries()
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
        update_option(
            Webhook_Manager::OPTION_PLUGIN_VERSION,
            AHPC_VERSION,
            false,
        );
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);

        $plugin = new Plugin();
        $plugin->bootstrap_woocommerce();

        $args = [
            "headers" => [
                "x-wc-webhook-id" => "20",
                "x-wc-webhook-topic" => "product.created",
                "x-wc-webhook-signature" => "test-signature",
            ],
            "body" => '{"id":30}',
        ];

        $filtered_args = $plugin->maybe_add_webhook_api_key_header(
            $args,
            "https://example.com/api/v1/webhooks/woocommerce/" . $shop_id,
        );

        $this->assertSame($api_key, $filtered_args["headers"]["x-api-key"]);
    }

    /**
     * It does not add the Aura Historia access token to webhook ping requests.
     *
     * @return void
     */
    public function test_plugin_does_not_add_access_token_to_webhook_pings()
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
        update_option(
            Webhook_Manager::OPTION_PLUGIN_VERSION,
            AHPC_VERSION,
            false,
        );
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);

        $plugin = new Plugin();
        $plugin->bootstrap_woocommerce();

        $args = [
            "headers" => [],
            "body" => "webhook_id=20",
        ];

        $filtered_args = $plugin->maybe_add_webhook_api_key_header(
            $args,
            "https://example.com/api/v1/webhooks/woocommerce/" . $shop_id,
        );

        $this->assertArrayNotHasKey("x-api-key", $filtered_args["headers"]);
    }

    /**
     * It does not emit WooCommerce delivery pings when paused managed webhooks
     * transition to an active configured endpoint.
     *
     * @return void
     */
    public function test_sync_webhooks_does_not_emit_ping_requests_when_activating_existing_webhooks()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $api_key = "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

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
        $first_result = $manager->sync_webhooks();
        $first_ids = $manager->get_webhook_ids();

        $this->assertTrue($first_result);
        $this->assertCount(3, $first_ids);

        $this->http_requests = [];
        $this->set_backend_mock_responses([
            $this->mock_backend_registration_response(),
        ]);

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" => $api_key,
                "secret" => "test-secret",
            ],
            false,
        );

        $second_result = $manager->sync_webhooks();
        $second_ids = $manager->get_webhook_ids();
        $delivery_requests = array_values(
            array_filter($this->http_requests, static function ($request) use (
                $shop_id,
            ) {
                return "https://example.com/api/v1/webhooks/woocommerce/" .
                    $shop_id ===
                    $request["url"];
            }),
        );

        $this->assertTrue($second_result);
        $this->assertSame($first_ids, $second_ids);
        $this->assertSame([], $delivery_requests);
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
            "Bearer " . $updated_api_key,
            $registration_requests[1]["request"]->getHeaderLine("Authorization"),
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
                "error" => "UNAUTHORIZED",
                "detail" => "Missing or empty Authorization header.",
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
            "Missing or empty Authorization header.",
            $result->get_error_message(),
        );
        $this->assertCount(3, $ids);

        foreach ($ids as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame("paused", $webhook->get_status());
        }
    }

    /**
     * It shows the saved Aura Historia connection status without triggering
     * another remote verification request during page rendering.
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

        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());

        $request_count = count($this->backend_http_requests);
        $output = $this->render_plugin_settings_page();

        $this->assertStringContainsString("Connection status", $output);
        $this->assertStringContainsString("Connected", $output);
        $this->assertStringContainsString("most recent webhook sync", $output);
        $this->assertCount($request_count, $this->backend_http_requests);
    }

    /**
     * It shows the queued backfill action details on the settings page.
     *
     * @return void
     */
    public function test_render_settings_page_shows_queued_backfill_status()
    {
        if (!function_exists("as_next_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

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

        $backfill = new Product_Backfill();
        $backfill->cancel_backfill();
        $this->assertTrue($backfill->schedule_backfill($shop_id));

        $output = $this->render_plugin_settings_page();

        $this->assertStringContainsString("Product backfill", $output);
        $this->assertStringContainsString("Queued", $output);
        $this->assertStringContainsString(
            Product_Backfill::ACTION_HOOK,
            $output,
        );
    }

    /**
     * It shows the manual full backfill action and explanation on the settings page.
     *
     * @return void
     */
    public function test_render_settings_page_shows_manual_backfill_action()
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

        $output = $this->render_plugin_settings_page();

        $this->assertStringContainsString("Existing product backfill", $output);
        $this->assertStringContainsString(
            "Re-send all existing products",
            $output,
        );
        $this->assertStringContainsString(
            "initial product backfill did not start",
            $output,
        );
    }

    /**
     * It queues a fresh manual backfill for valid saved settings.
     *
     * @return void
     */
    public function test_queue_manual_backfill_schedules_backfill()
    {
        if (!function_exists("as_has_scheduled_action")) {
            $this->markTestSkipped("Action Scheduler is not available.");
        }

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

        $backfill = new Product_Backfill();
        $backfill->cancel_backfill();

        $plugin = new Plugin();
        $result = $plugin->queue_manual_backfill();

        $this->assertTrue($result);
        $this->assertTrue($backfill->is_backfill_scheduled());
    }

    /**
     * It shows the latest successful backfill details on the settings page.
     *
     * @return void
     */
    public function test_render_settings_page_shows_completed_backfill_status()
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
        $product->set_name("Status Product");
        $product->set_status("publish");
        $product->save();

        $this->set_backend_mock_responses([
            new Response(202, ["Content-Type" => "application/json"], "[]"),
        ]);

        $backfill = new Product_Backfill();
        $backfill->process_batch($shop_id, 1);

        $output = $this->render_plugin_settings_page();

        $this->assertStringContainsString("Product backfill", $output);
        $this->assertStringContainsString("Completed", $output);
        $this->assertStringContainsString("completed successfully", $output);
        $this->assertStringContainsString(
            Product_Backfill::ACTION_HOOK,
            $output,
        );

        $product->delete(true);
    }

    /**
     * It shows a dedicated success notice after an OAuth connection callback.
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

        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());

        $request_count = count($this->backend_http_requests);
        $output = $this->render_plugin_settings_page([
            "ahpc_oauth" => "connected",
        ]);

        $this->assertStringContainsString(
            "Aura Historia connection completed and managed webhooks synced.",
            $output,
        );
        $this->assertCount($request_count, $this->backend_http_requests);
    }

    /**
     * It includes the store currency in the PATCH registration payload.
     *
     * @return void
     */
    public function test_patch_registration_includes_supported_currency()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $api_key = "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        update_option("woocommerce_currency", "EUR", false);
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

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id,
        );

        $this->assertCount(1, $registration_requests);
        $this->assertStringContainsString(
            '"woocommerceCurrency":"EUR"',
            (string) $registration_requests[0]["request"]->getBody(),
        );
    }

    /**
     * It omits the currency from the PATCH payload when the store currency is
     * not supported by the backend.
     *
     * @return void
     */
    public function test_patch_registration_omits_unsupported_currency()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $api_key = "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        update_option("woocommerce_currency", "XYZ", false);
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

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id,
        );

        $this->assertCount(1, $registration_requests);
        $this->assertStringNotContainsString(
            "woocommerceCurrency",
            (string) $registration_requests[0]["request"]->getBody(),
        );
    }

    /**
     * It includes the store language in the PATCH registration payload derived
     * from the WordPress locale.
     *
     * @return void
     */
    public function test_patch_registration_includes_locale_derived_language()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $api_key = "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        add_filter("locale", static function () {
            return "de_DE";
        });

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

        remove_all_filters("locale");

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id,
        );

        $this->assertCount(1, $registration_requests);
        $this->assertStringContainsString(
            '"woocommerceLanguage":"de"',
            (string) $registration_requests[0]["request"]->getBody(),
        );
    }

    /**
     * It falls back to "en" when the WordPress locale does not map to a
     * supported backend language.
     *
     * @return void
     */
    public function test_patch_registration_uses_en_fallback_for_unsupported_locale()
    {
        $shop_id = "123e4567-e89b-12d3-a456-426614174000";
        $api_key = "aurahistoria_abcdefghijk_abcdefghijklmnopqrstuvwxyz1234567";

        add_filter("locale", static function () {
            return "xx_XX";
        });

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

        remove_all_filters("locale");

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/shops/" . $shop_id,
        );

        $this->assertCount(1, $registration_requests);
        $this->assertStringContainsString(
            '"woocommerceLanguage":"en"',
            (string) $registration_requests[0]["request"]->getBody(),
        );
    }
}
