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
        "https://auth.example/api/oauth/client/redirect-broker/woocommerce";

    /**
     * OAuth client ID used by tests.
     *
     * @var string
     */
    protected $oauth_client_id = "oc_00000000000000000000000000";

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
        $status_code = 204,
        $body = null,
    ) {
        if (null === $body) {
            $body = "";
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
        $scope = "product-listings:write listing-sources:write",
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
                "listing_source_id" => "",
                "access_token" => "",
                "secret" => "test-secret",
            ],
            false,
        );

        $output = $this->render_plugin_settings_page();

        $this->assertStringContainsString("Connect with Aura Historia", $output);
        $this->assertStringContainsString("ahpc-oauth-start-form", $output);
        $this->assertStringContainsString("ahpc_start_oauth", $output);
        $this->assertStringContainsString("form.submit", $output);
        $this->assertStringNotContainsString("id=\"ahpc-listing-source-id\"", $output);
        $this->assertStringNotContainsString("id=\"ahpc-access-token\"", $output);
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
        $this->assertSame("product-listings:write listing-sources:write", $query["scope"]);
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
        $listing_source_id = "ls_00000000000000000000000000";
        $exchange_code = "01970f22-2bf0-7000-8000-000000000099";
        $access_token =
            "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => "",
                "access_token" => "",
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
            $this->mock_backend_registration_response(201),
        ]);

        $result = $plugin->complete_oauth_connection(
            $listing_source_id,
            $exchange_code,
            $broker_state["client_state"],
        );

        $this->assertTrue($result);

        $settings = get_option(Webhook_Manager::OPTION_SETTINGS, []);
        $this->assertSame($listing_source_id, $settings["listing_source_id"]);
        $this->assertSame($access_token, $settings["access_token"]);
        $this->assertSame("test-secret", $settings["secret"]);
        $this->assertSame(
            $listing_source_id,
            get_option("ahpc_initial_backfill_listing_source_id"),
        );
        $this->assertTrue((new Product_Backfill())->is_backfill_scheduled());

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
            "https://example.com/api/v1/listing-sources/" . $listing_source_id . "/ingestion-configurations/woocommerce",
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

        // Reauthorizing the same source must not replay already-created listings.
        $snapshot = ["idempotency_key" => "original-key", "payloads" => [["sourceListingId" => "42"]]];
        update_option(Product_Backfill::OPTION_BATCH, $snapshot, false);
        $next_url = $plugin->create_oauth_authorization_url();
        $next_parts = wp_parse_url($next_url);
        parse_str($next_parts["query"], $next_query);
        $next_state = json_decode($this->base64url_decode($next_query["state"]), true);
        $this->set_backend_mock_responses([
            $this->mock_oauth_token_response($access_token),
            $this->mock_backend_registration_response(201),
        ]);
        $this->assertTrue($plugin->complete_oauth_connection(
            $listing_source_id,
            $exchange_code,
            $next_state["client_state"],
        ));
        $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
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
            "ls_00000000000000000000000000",
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
        $listing_source_id = "ls_00000000000000000000000000";
        $exchange_code = "01970f22-2bf0-7000-8000-000000000099";
        $access_token =
            "aurahistoria_abcdefghijk_verylongtokenvalue";

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
                "product-listings:write listing-sources:write",
                "MAC",
            ),
        ]);

        $result = $plugin->complete_oauth_connection(
            $listing_source_id,
            $exchange_code,
            $broker_state["client_state"],
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(
            "ahpc_oauth_exchange_failed",
            $result->get_error_code(),
        );
        $this->assertFalse(
            Webhook_Manager::is_valid_access_token(
                get_option(Webhook_Manager::OPTION_SETTINGS, [])["access_token"],
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
        $listing_source_id = "ls_00000000000000000000000000";
        $exchange_code = "01970f22-2bf0-7000-8000-000000000099";
        $access_token =
            "aurahistoria_abcdefghijk_verylongtokenvalue";

        $plugin = new Plugin();
        $authorization_url = $plugin->create_oauth_authorization_url();
        $parts = wp_parse_url($authorization_url);
        parse_str($parts["query"], $query);
        $broker_state = json_decode(
            $this->base64url_decode($query["state"]),
            true,
        );

        $this->set_backend_mock_responses([
            $this->mock_oauth_token_response($access_token, "product-listings:write"),
        ]);

        $result = $plugin->complete_oauth_connection(
            $listing_source_id,
            $exchange_code,
            $broker_state["client_state"],
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame("ahpc_oauth_missing_scope", $result->get_error_code());
        $this->assertFalse(
            Webhook_Manager::is_valid_access_token(
                get_option(Webhook_Manager::OPTION_SETTINGS, [])["access_token"],
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
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
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
                "https://example.com/api/v1/webhooks/woocommerce/" . $listing_source_id,
                $webhook->get_delivery_url(),
            );
            $this->assertSame("test-secret", $webhook->get_secret());
            $this->assertSame($this->admin_user_id, $webhook->get_user_id());
            $this->assertSame("wp_api_v3", $webhook->get_api_version());
        }

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/listing-sources/" . $listing_source_id . "/ingestion-configurations/woocommerce",
        );

        $this->assertCount(1, $registration_requests);
        $this->assertSame(
            "PUT",
            strtoupper($registration_requests[0]["request"]->getMethod()),
        );
        $this->assertSame(
            "Bearer " . $access_token,
            $registration_requests[0]["request"]->getHeaderLine("Authorization"),
        );
        $this->assertStringContainsString(
            '"webhookSecret":"test-secret"',
            (string) $registration_requests[0]["request"]->getBody(),
        );
        $this->assertStringContainsString(
            '"language":"en"',
            (string) $registration_requests[0]["request"]->getBody(),
        );

        $delivery_requests = array_values(
            array_filter($this->http_requests, static function ($request) use (
                $listing_source_id,
            ) {
                return "https://example.com/api/v1/webhooks/woocommerce/" .
                    $listing_source_id ===
                    $request["url"];
            }),
        );

        $this->assertSame([], $delivery_requests);
    }

    /**
     * It adds bearer auth only to signed deliveries of managed webhooks.
     *
     * @return void
     */
    public function test_plugin_adds_access_token_to_real_webhook_deliveries()
    {
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
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

        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());
        $id = $manager->get_webhook_ids()["product.created"];
        $body = '{"id":30}';
        $args = [
            "headers" => [
                "x-wc-webhook-id" => (string) $id,
                "x-wc-webhook-topic" => "product.created",
                "x-wc-webhook-signature" => base64_encode(hash_hmac("sha256", $body, "test-secret", true)),
            ],
            "body" => $body,
        ];
        $url = Webhook_Manager::get_webhook_endpoint_url($listing_source_id);
        $filtered_args = apply_filters("http_request_args", $args, $url);

        $this->assertSame("Bearer " . $access_token, $filtered_args["headers"]["Authorization"]);
        $this->assertSame($body, $filtered_args["body"]);
        $this->assertArrayNotHasKey("x-api-key", $filtered_args["headers"]);

        foreach ([
            ["url" => $url . "/other", "args" => $args],
            ["url" => $url, "args" => array_merge($args, ["body" => "changed"])],
            ["url" => $url, "args" => array_replace_recursive($args, ["headers" => ["x-wc-webhook-id" => "999999"]])],
            ["url" => $url, "args" => ["headers" => [], "body" => "ping"]],
        ] as $case) {
            $this->assertSame($case["args"], apply_filters("http_request_args", $case["args"], $case["url"]));
        }
        $args["headers"]["aUtHoRiZaTiOn"] = "Other credential";
        $this->assertSame($args, apply_filters("http_request_args", $args, $url));
    }

    /**
     * WooCommerce's signed product payload receives bearer auth only after its
     * own HTTP arguments have been built, without changing the signed body.
     *
     * @return void
     */
    public function test_real_woocommerce_delivery_receives_late_bearer_auth()
    {
        $listing_source_id = "ls_" . str_repeat("0", 26);
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";
        $product = new WC_Product_Simple();
        $product->set_name("Webhook delivery fixture");
        $product->set_status("publish");
        $product->save();

        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $listing_source_id,
            "access_token" => $access_token,
            "secret" => "test-secret",
        ]);
        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());
        $webhook = new WC_Webhook($manager->get_webhook_ids()["product.created"]);
        $this->http_requests = [];
        $woocommerce_args = [];
        $capture_args = static function ($args) use (&$woocommerce_args) {
            $woocommerce_args[] = $args;
            return $args;
        };
        add_filter("woocommerce_webhook_http_args", $capture_args);
        try {
            $webhook->deliver($product->get_id());
        } finally {
            remove_filter("woocommerce_webhook_http_args", $capture_args);
            $product->delete(true);
        }

        $delivery_url = Webhook_Manager::get_webhook_endpoint_url($listing_source_id);
        $requests = array_values(array_filter($this->http_requests, static function ($request) use ($delivery_url) {
            return $request["url"] === $delivery_url;
        }));
        $this->assertNotEmpty($woocommerce_args);
        $this->assertNotEmpty($requests);
        $args = $requests[0]["args"];
        $wc_args = $woocommerce_args[0];
        $headers = array_change_key_case($args["headers"], CASE_LOWER);
        $this->assertSame($wc_args["body"], $args["body"]);
        $this->assertArrayNotHasKey(
            "authorization",
            array_change_key_case($wc_args["headers"], CASE_LOWER),
        );
        $this->assertSame("Bearer " . $access_token, $headers["authorization"]);
        $this->assertSame((string) $webhook->get_id(), (string) $headers["x-wc-webhook-id"]);
        $this->assertSame("product.created", $headers["x-wc-webhook-topic"]);
        $this->assertSame(
            base64_encode(hash_hmac("sha256", $args["body"], "test-secret", true)),
            $headers["x-wc-webhook-signature"],
        );
        $this->assertArrayNotHasKey("x-api-key", $headers);
    }

    /**
     * The plugin, not option initialization, registers the late HTTP filter.
     *
     * @return void
     */
    public function test_plugin_owns_delivery_filter_registration()
    {
        Plugin::instance()->boot();
        $callback = [Webhook_Manager::class, "authorize_delivery"];
        $this->assertSame(11, has_filter("http_request_args", $callback));

        (new Webhook_Manager())->initialize_options();
        $this->assertSame(11, has_filter("http_request_args", $callback));
    }

    /**
     * It does not authorize webhook ping requests, even when signed.
     *
     * @return void
     */
    public function test_plugin_does_not_add_access_token_to_webhook_pings()
    {
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
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

        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());
        $id = $manager->get_webhook_ids()["product.created"];
        $body = "webhook_id=" . $id;
        $args = [
            "headers" => [
                "X-WC-Webhook-ID" => (string) $id,
                "X-WC-Webhook-Topic" => "product.created",
                "X-WC-Webhook-Signature" => base64_encode(hash_hmac("sha256", $body, "test-secret", true)),
            ],
            "body" => $body,
        ];
        $this->assertSame(
            $args,
            apply_filters("http_request_args", $args, Webhook_Manager::get_webhook_endpoint_url($listing_source_id)),
        );
    }

    /**
     * It does not emit WooCommerce delivery pings when paused managed webhooks
     * transition to an active configured endpoint.
     *
     * @return void
     */
    public function test_sync_webhooks_does_not_emit_ping_requests_when_activating_existing_webhooks()
    {
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => "",
                "access_token" => "",
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
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
                "secret" => "test-secret",
            ],
            false,
        );

        $second_result = $manager->sync_webhooks();
        $second_ids = $manager->get_webhook_ids();
        $delivery_requests = array_values(
            array_filter($this->http_requests, static function ($request) use (
                $listing_source_id,
            ) {
                return "https://example.com/api/v1/webhooks/woocommerce/" .
                    $listing_source_id ===
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
        $listing_source_id = "ls_00000000000000000000000000";
        $original_access_token =
            "aurahistoria_fixture_originaltokenvalue";
        $updated_access_token =
            "aurahistoria_fixture_updatedtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $original_access_token,
                "secret" => "original-secret",
            ],
            false,
        );

        $manager->sync_webhooks();
        $first_ids = $manager->get_webhook_ids();

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $updated_access_token,
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
                "https://example.com/api/v1/webhooks/woocommerce/" . $listing_source_id,
                $webhook->get_delivery_url(),
            );
            $this->assertSame("updated-secret", $webhook->get_secret());
        }

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/listing-sources/" . $listing_source_id . "/ingestion-configurations/woocommerce",
        );

        $this->assertCount(2, $registration_requests);
        $this->assertSame(
            "Bearer " . $updated_access_token,
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
        $listing_source_id = "ls_00000000000000000000000000";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => "",
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
                "https://example.com/api/v1/webhooks/woocommerce/" . $listing_source_id,
                $webhook->get_delivery_url(),
            );
        }

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/listing-sources/" . $listing_source_id . "/ingestion-configurations/woocommerce",
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
                "listing_source_id" => "ls_00000000000000000000000000",
                "access_token" =>
                    "aurahistoria_abcdefghijk_verylongtokenvalue",
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
                "listing_source_id" => "ls_00000000000000000000000000",
                "access_token" =>
                    "aurahistoria_abcdefghijk_verylongtokenvalue",
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
                "listing_source_id" => "not-a-typeid",
                "access_token" => "invalid key",
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
     * It reports backend status without displaying potentially sensitive details.
     *
     * @return void
     */
    public function test_sync_webhooks_surfaces_backend_api_error_details()
    {
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
                "secret" => "test-secret",
            ],
            false,
        );

        $this->set_backend_mock_responses([
            $this->mock_backend_registration_response(401, [
                "status" => 401,
                "title" => "Unauthorized",
                "error" => "UNAUTHORIZED",
                "detail" => "Missing or empty Authorization header. " . $access_token . " test-secret",
            ]),
        ]);

        $manager = new Webhook_Manager();
        $result = $manager->sync_webhooks();
        $ids = $manager->get_webhook_ids();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(
            "ahpc_backend_configuration_failed",
            $result->get_error_code(),
        );
        $this->assertStringContainsString(
            "HTTP 401",
            $result->get_error_message(),
        );
        $this->assertStringNotContainsString(
            "Missing or empty Authorization header.",
            $result->get_error_message(),
        );
        $this->assertStringNotContainsString($access_token, $result->get_error_message());
        $this->assertStringNotContainsString("test-secret", $manager->get_last_sync_error());
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
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
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
        $this->assertStringContainsString("most recent sync", $output);
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

        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
                "secret" => "test-secret",
            ],
            false,
        );

        $backfill = new Product_Backfill();
        $backfill->cancel_backfill();
        $this->assertTrue($backfill->schedule_backfill($listing_source_id));

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
                "listing_source_id" => "ls_00000000000000000000000000",
                "access_token" =>
                    "aurahistoria_abcdefghijk_verylongtokenvalue",
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

        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
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
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
                "secret" => "test-secret",
            ],
            false,
        );

        $product = new WC_Product_Simple();
        $product->set_name("Status Product");
        $product->set_status("publish");
        $product->save();

        $this->set_backend_mock_responses([
            new Response(
                202,
                ["Content-Type" => "application/json"],
                wp_json_encode([
                    "submissionId" => "submission-status-test",
                    "acceptedCount" => 1,
                    "failures" => [],
                ]),
            ),
        ]);

        $backfill = new Product_Backfill();
        $backfill->process_batch($listing_source_id, 1);

        $output = $this->render_plugin_settings_page();

        $this->assertStringContainsString("Product backfill", $output);
        $this->assertStringContainsString("Completed", $output);
        $this->assertStringContainsString("finished submitting", $output);
        $this->assertStringContainsString(
            "Backend ingestion may still be processing",
            $output,
        );
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
                "listing_source_id" => "ls_00000000000000000000000000",
                "access_token" =>
                    "aurahistoria_abcdefghijk_verylongtokenvalue",
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
            "Aura Historia authorization completed and webhook sync submitted. Product listings are processed asynchronously.",
            $output,
        );
        $this->assertCount($request_count, $this->backend_http_requests);
    }

    /**
     * It includes the store currency in the PUT configuration payload.
     *
     * @return void
     */
    public function test_put_configuration_includes_supported_currency()
    {
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option("woocommerce_currency", "EUR", false);
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
                "secret" => "test-secret",
            ],
            false,
        );

        $manager = new Webhook_Manager();
        $manager->sync_webhooks();

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/listing-sources/" . $listing_source_id . "/ingestion-configurations/woocommerce",
        );

        $this->assertCount(1, $registration_requests);
        $this->assertStringContainsString(
            '"currency":"EUR"',
            (string) $registration_requests[0]["request"]->getBody(),
        );
    }

    /**
     * It blocks unsupported WooCommerce currencies without calling the backend.
     *
     * @return void
     */
    public function test_unsupported_currency_blocks_registration_and_delivery()
    {
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        update_option("woocommerce_currency", "INR", false);
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
                "secret" => "test-secret",
            ],
            false,
        );

        $manager = new Webhook_Manager();
        $result = $manager->sync_webhooks();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame("ahpc_unsupported_currency", $result->get_error_code());
        $this->assertSame([], $this->backend_http_requests);
        $this->assertSame("yes", get_option(Webhook_Manager::OPTION_NEEDS_SYNC));
        foreach ($manager->get_webhook_ids() as $webhook_id) {
            $this->assertSame("paused", (new WC_Webhook($webhook_id))->get_status());
        }

        update_option("woocommerce_currency", "EUR", false);
        $this->assertTrue($manager->sync_webhooks());
        $this->assertCount(1, $this->backend_http_requests);
        foreach ($manager->get_webhook_ids() as $webhook_id) {
            $this->assertSame("active", (new WC_Webhook($webhook_id))->get_status());
        }
    }

    /**
     * It includes the store language in the PUT configuration payload derived
     * from the WordPress locale.
     *
     * @return void
     */
    public function test_put_configuration_includes_locale_derived_language()
    {
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        add_filter("locale", static function () {
            return "de_DE";
        });

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
                "secret" => "test-secret",
            ],
            false,
        );

        $manager = new Webhook_Manager();
        $manager->sync_webhooks();

        remove_all_filters("locale");

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/listing-sources/" . $listing_source_id . "/ingestion-configurations/woocommerce",
        );

        $this->assertCount(1, $registration_requests);
        $this->assertStringContainsString(
            '"language":"de"',
            (string) $registration_requests[0]["request"]->getBody(),
        );
    }

    /**
     * It falls back to "en" when the WordPress locale does not map to a
     * supported backend language.
     *
     * @return void
     */
    public function test_put_configuration_uses_en_fallback_for_unsupported_locale()
    {
        $listing_source_id = "ls_00000000000000000000000000";
        $access_token = "aurahistoria_abcdefghijk_verylongtokenvalue";

        add_filter("locale", static function () {
            return "xx_XX";
        });

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "listing_source_id" => $listing_source_id,
                "access_token" => $access_token,
                "secret" => "test-secret",
            ],
            false,
        );

        $manager = new Webhook_Manager();
        $manager->sync_webhooks();

        remove_all_filters("locale");

        $registration_requests = $this->get_backend_requests_for_url(
            "https://example.com/api/v1/listing-sources/" . $listing_source_id . "/ingestion-configurations/woocommerce",
        );

        $this->assertCount(1, $registration_requests);
        $this->assertStringContainsString(
            '"language":"en"',
            (string) $registration_requests[0]["request"]->getBody(),
        );
    }

    /**
     * Opaque tokens are not tied to a historical prefix, but must be safe in
     * an Authorization bearer header without changing their bytes.
     *
     * @return void
     */
    public function test_access_token_accepts_opaque_bearer_values_and_rejects_whitespace()
    {
        foreach (
            [
                "aurahistoria_abcdefghijk_verylongtokenvalue",
                "opaque-token.v1+with/symbols~and_padding==",
                "a",
            ] as $valid
        ) {
            $this->assertSame($valid, Webhook_Manager::normalize_access_token($valid));
            $this->assertTrue(Webhook_Manager::is_valid_access_token($valid));
        }

        foreach (
            [
                "",
                " ",
                "token with space",
                " token",
                "token ",
                "token\tvalue",
                "token\nvalue",
                "token\r\nX-Injected: yes",
                "token\0value",
                "token\x7fvalue",
                "token=value",
                "token:value",
            ] as $invalid
        ) {
            $this->assertSame($invalid, Webhook_Manager::normalize_access_token($invalid));
            $this->assertFalse(Webhook_Manager::is_valid_access_token($invalid));
        }
        $this->assertFalse(Webhook_Manager::is_valid_access_token(null));
        $this->assertSame("", Webhook_Manager::normalize_access_token(null));
    }

    /**
     * Invalid saved credentials must not be sent to the backend or used in
     * webhook Authorization headers.
     *
     * @return void
     */
    public function test_sync_rejects_unsafe_bearer_token_without_backend_request()
    {
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => "ls_" . str_repeat("0", 26),
            "access_token" => "aurahistoria_abcdefghijk_verylongtokenvalue\r\nX-Injected: yes",
            "secret" => "test-secret",
        ]);

        $manager = new Webhook_Manager();
        $result = $manager->sync_webhooks();
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame("ahpc_invalid_access_token", $result->get_error_code());
        $this->assertSame([], $this->backend_http_requests);
        foreach ($manager->get_webhook_ids() as $id) {
            $this->assertSame("paused", (new WC_Webhook($id))->get_status());
        }
    }

    public function test_listing_source_id_is_strict_and_does_not_migrate_legacy_id()
    {
        $valid = "ls_" . str_repeat("0", 26);
        $this->assertTrue(Webhook_Manager::is_valid_listing_source_id($valid));
        $this->assertSame($valid, Webhook_Manager::normalize_listing_source_id("  LS_" . str_repeat("0", 26) . "  "));
        foreach ([
            "ls_" . str_repeat("0", 25),
            "ls_" . str_repeat("0", 27),
            "LS_" . str_repeat("0", 26),
            "ls_" . str_repeat("i", 26),
            "ls_" . str_repeat("l", 26),
            "ls_" . str_repeat("o", 26),
            "ls_" . str_repeat("u", 26),
            "123e4567-e89b-12d3-a456-426614174000",
        ] as $invalid) {
            $this->assertFalse(Webhook_Manager::is_valid_listing_source_id($invalid));
            $this->assertSame("", Webhook_Manager::get_webhook_endpoint_url($invalid));
        }
        $legacy_settings = [
            "shop_id" => "123e4567-e89b-12d3-a456-426614174000",
            "api_key" => "aurahistoria_abcdefghijk_verylongtokenvalue",
            "secret" => "test-secret",
        ];
        update_option(Webhook_Manager::OPTION_SETTINGS, $legacy_settings);
        $settings = (new Webhook_Manager())->get_settings();
        $this->assertSame(Webhook_Manager::default_settings()["listing_source_id"], $settings["listing_source_id"]);
        $this->assertSame("", $settings["access_token"]);
        $this->assertSame("test-secret", $settings["secret"]);
        $this->assertCount(3, $settings);
        $this->assertSame($legacy_settings, get_option(Webhook_Manager::OPTION_SETTINGS));
    }

    public function test_put_requires_documented_success_status()
    {
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => "ls_" . str_repeat("0", 26),
            "access_token" => "aurahistoria_abcdefghijk_verylongtokenvalue",
            "secret" => "test-secret",
        ]);
        $this->set_backend_mock_responses([$this->mock_backend_registration_response(200)]);
        $manager = new Webhook_Manager();
        $this->assertInstanceOf(WP_Error::class, $manager->sync_webhooks());
        foreach ($manager->get_webhook_ids() as $id) {
            $this->assertSame("paused", (new WC_Webhook($id))->get_status());
        }
    }

    public function test_failed_put_pauses_previously_active_webhooks_and_retries()
    {
        $id = "ls_" . str_repeat("0", 26);
        $token = "aurahistoria_abcdefghijk_verylongtokenvalue";
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => $token,
            "secret" => "initial-secret",
        ]);
        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());
        $ids = $manager->get_webhook_ids();

        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => $token,
            "secret" => "rotated-secret",
        ]);
        $this->set_backend_mock_responses([
            $this->mock_backend_registration_response(503, ["detail" => "Try again later"]),
        ]);
        $result = $manager->sync_webhooks();
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame($ids, $manager->get_webhook_ids());
        foreach ($ids as $webhook_id) {
            $this->assertSame("paused", (new WC_Webhook($webhook_id))->get_status());
        }
        $this->assertSame("yes", get_option(Webhook_Manager::OPTION_NEEDS_SYNC));
        $this->assertSame("rotated-secret", (new WC_Webhook(reset($ids)))->get_secret());

        $this->set_backend_mock_responses([$this->mock_backend_registration_response(201)]);
        $this->assertTrue($manager->sync_webhooks());
        $this->assertSame($ids, $manager->get_webhook_ids());
        foreach ($ids as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame("active", $webhook->get_status());
            $this->assertSame("rotated-secret", $webhook->get_secret());
        }
    }

    /**
     * A paused-stage persistence failure must not send a new secret to Aura.
     *
     * @return void
     */
    public function test_paused_stage_failure_does_not_update_backend()
    {
        $id = "ls_" . str_repeat("0", 26);
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => "test-token",
            "secret" => "old-secret",
        ]);
        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());
        $ids = $manager->get_webhook_ids();
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => "test-token",
            "secret" => "new-secret",
        ]);
        $this->set_backend_mock_responses([$this->mock_backend_registration_response()]);
        $failed_once = false;
        $fail_stage = static function ($webhook_id) use (&$failed_once, $ids) {
            if (!$failed_once && (int) $webhook_id === (int) $ids["product.updated"] &&
                "paused" === (new WC_Webhook($webhook_id))->get_status()) {
                $failed_once = true;
                throw new \RuntimeException("sensitive test-token should not surface");
            }
        };
        add_action("woocommerce_webhook_updated", $fail_stage, 20, 1);
        try {
            $result = $manager->sync_webhooks();
        } finally {
            remove_action("woocommerce_webhook_updated", $fail_stage, 20);
        }
        $this->assertTrue($failed_once);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame([], $this->backend_http_requests);
        $this->assertSame("yes", get_option(Webhook_Manager::OPTION_NEEDS_SYNC));
        foreach ($ids as $webhook_id) {
            $this->assertSame("paused", (new WC_Webhook($webhook_id))->get_status());
        }
        $this->set_backend_mock_responses([$this->mock_backend_registration_response()]);
        $this->assertTrue($manager->sync_webhooks());
    }

    /**
     * Failure activating one webhook after a successful secret rotation must
     * pause the entire set; retry may safely activate the new secret.
     *
     * @return void
     */
    public function test_activation_failure_after_backend_put_pauses_rotated_webhooks()
    {
        $id = "ls_" . str_repeat("0", 26);
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => "test-token",
            "secret" => "old-secret",
        ]);
        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());
        $ids = $manager->get_webhook_ids();
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => "test-token",
            "secret" => "new-secret",
        ]);
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "yes", false);
        $this->set_backend_mock_responses([$this->mock_backend_registration_response()]);
        $failed_once = false;
        $fail_activation = static function ($webhook_id) use (&$failed_once, $ids) {
            if (!$failed_once && (int) $webhook_id === (int) $ids["product.updated"] &&
                "active" === (new WC_Webhook($webhook_id))->get_status()) {
                $failed_once = true;
                throw new \RuntimeException("sensitive old-secret test-token should not surface");
            }
        };
        add_action("woocommerce_webhook_updated", $fail_activation, 20, 1);
        try {
            $result = $manager->sync_webhooks();
        } finally {
            remove_action("woocommerce_webhook_updated", $fail_activation, 20);
        }
        $this->assertTrue($failed_once);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertCount(1, $this->backend_http_requests);
        $this->assertSame("yes", get_option(Webhook_Manager::OPTION_NEEDS_SYNC));
        $this->assertStringNotContainsString("test-token", $result->get_error_message());
        $this->assertStringNotContainsString("old-secret", $result->get_error_message());
        foreach ($ids as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame("paused", $webhook->get_status());
            $this->assertSame("new-secret", $webhook->get_secret());
        }
        $this->set_backend_mock_responses([$this->mock_backend_registration_response()]);
        $this->assertTrue($manager->sync_webhooks());
        foreach ($ids as $webhook_id) {
            $webhook = new WC_Webhook($webhook_id);
            $this->assertSame("active", $webhook->get_status());
            $this->assertSame("new-secret", $webhook->get_secret());
        }
    }

    /**
     * All three managed webhooks must exist in the paused state before the
     * first backend registration request is sent.
     *
     * @return void
     */
    public function test_initial_registration_only_sees_paused_webhooks()
    {
        $id = "ls_" . str_repeat("0", 26);
        update_option("woocommerce_currency", "EUR", false);
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => "test-token",
            "secret" => "test-secret",
        ]);

        $observed = [];
        $this->backend_guzzle_client = new Client([
            "handler" => static function ($request, $options) use (&$observed) {
                $manager = new Webhook_Manager();
                foreach ($manager->get_webhook_ids() as $topic => $webhook_id) {
                    $webhook = new WC_Webhook($webhook_id);
                    $observed[$topic] = [$webhook->get_status(), $webhook->get_secret()];
                }
                return new \GuzzleHttp\Promise\FulfilledPromise(new Response(204));
            },
        ]);

        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());
        $this->assertCount(3, $observed);
        foreach ($observed as $snapshot) {
            $this->assertSame(["paused", "test-secret"], $snapshot);
        }
    }

    /**
     * The pause stage must persist a rotated secret before sending the PUT.
     *
     * @return void
     */
    public function test_all_local_webhooks_are_paused_and_saved_before_backend_put()
    {
        $id = "ls_" . str_repeat("0", 26);
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => "original-token",
            "secret" => "original-secret",
        ]);
        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());
        $ids = $manager->get_webhook_ids();

        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => "rotated-token",
            "secret" => "rotated-secret",
        ]);
        $observed = [];
        $this->backend_guzzle_client = new Client([
            "handler" => static function ($request, $options) use ($ids, &$observed) {
                foreach ($ids as $topic => $webhook_id) {
                    $webhook = new WC_Webhook($webhook_id);
                    $observed[$topic] = [$webhook->get_status(), $webhook->get_secret()];
                }
                return new \GuzzleHttp\Promise\FulfilledPromise(new Response(204));
            },
        ]);

        $this->assertTrue($manager->sync_webhooks());
        $this->assertSame($ids, $manager->get_webhook_ids());
        $this->assertCount(3, $observed);
        foreach ($observed as $snapshot) {
            $this->assertSame(["paused", "rotated-secret"], $snapshot);
        }
        foreach ($ids as $webhook_id) {
            $this->assertSame("active", (new WC_Webhook($webhook_id))->get_status());
        }
    }

    /**
     * A currency change must pause existing deliveries until a valid retry.
     *
     * @return void
     */
    public function test_unsupported_currency_pauses_existing_webhooks_before_retry()
    {
        $id = "ls_" . str_repeat("0", 26);
        update_option("woocommerce_currency", "EUR", false);
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => "test-token",
            "secret" => "test-secret",
        ]);
        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());
        $ids = $manager->get_webhook_ids();

        update_option("woocommerce_currency", "INR", false);
        $this->set_backend_mock_responses([$this->mock_backend_registration_response()]);
        $result = $manager->sync_webhooks();
        $this->assertSame("ahpc_unsupported_currency", $result->get_error_code());
        $this->assertSame([], $this->backend_http_requests);
        $this->assertSame($ids, $manager->get_webhook_ids());
        foreach ($ids as $webhook_id) {
            $this->assertSame("paused", (new WC_Webhook($webhook_id))->get_status());
        }

        update_option("woocommerce_currency", "EUR", false);
        $this->assertTrue($manager->maybe_sync_webhooks());
        $this->assertCount(1, $this->backend_http_requests);
        foreach ($ids as $webhook_id) {
            $this->assertSame("active", (new WC_Webhook($webhook_id))->get_status());
        }
    }

    /**
     * Webhook drift reconciliation must not reset product backfill progress.
     *
     * @return void
     */
    public function test_generic_sync_does_not_restart_in_flight_or_completed_backfill()
    {
        $id = "ls_" . str_repeat("0", 26);
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $id,
            "access_token" => "test-token",
            "secret" => "test-secret",
        ]);
        $manager = new Webhook_Manager();
        $this->assertTrue($manager->sync_webhooks());

        foreach ([Product_Backfill::STATUS_RUNNING, Product_Backfill::STATUS_COMPLETE] as $status) {
            $state = [
                "status" => $status,
                "last_completed_page" => "3",
                "accepted_count" => "25",
                "completed_at" => "2026-01-01 12:00:00",
            ];
            $batch = ["page" => 4, "listing_source_id" => $id];
            update_option(Product_Backfill::OPTION_STATE, $state, false);
            update_option(Product_Backfill::OPTION_BATCH, $batch, false);

            $this->assertTrue($manager->sync_webhooks());
            $this->assertSame($state, get_option(Product_Backfill::OPTION_STATE));
            $this->assertSame($batch, get_option(Product_Backfill::OPTION_BATCH));
        }
    }
}
