<?php
/**
 * Integration tests for the webhook manager.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Webhook_Manager;

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
     * Prevents real HTTP requests during backend sync, webhook ping, or delivery.
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

        return [
            "headers" => [],
            "body" => "",
            "response" => [
                "code" => 200,
                "message" => "OK",
            ],
            "cookies" => [],
            "filename" => null,
        ];
    }

    /**
     * It creates the three managed webhooks with the configured settings.
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
                "enabled" => true,
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

        $registration_requests = array_values(
            array_filter($this->http_requests, static function ($request) use (
                $shop_id,
            ) {
                return "https://example.com/api/v1/shops/" . $shop_id ===
                    $request["url"];
            }),
        );

        $this->assertCount(1, $registration_requests);
        $this->assertSame(
            "PATCH",
            strtoupper($registration_requests[0]["args"]["method"]),
        );
        $this->assertSame(
            $api_key,
            $registration_requests[0]["args"]["headers"]["x-api-key"],
        );
        $this->assertStringContainsString(
            '"woocommerceWebhookSecret":"test-secret"',
            $registration_requests[0]["args"]["body"],
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

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" =>
                    "aurahistoria_originaltoken_abcdefghijklmnopqrstuvwxyz1234567",
                "secret" => "original-secret",
                "enabled" => true,
            ],
            false,
        );

        $manager->sync_webhooks();
        $first_ids = $manager->get_webhook_ids();

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "shop_id" => $shop_id,
                "api_key" =>
                    "aurahistoria_updatedtoken_abcdefghijklmnopqrstuvwxyz7654321",
                "secret" => "updated-secret",
                "enabled" => false,
            ],
            false,
        );

        $result = $manager->sync_webhooks();
        $second_ids = $manager->get_webhook_ids();

        $this->assertTrue($result);
        $this->assertSame($first_ids, $second_ids);

        foreach (array_keys($manager->get_managed_topics()) as $topic) {
            $webhook = new WC_Webhook($second_ids[$topic]);

            $this->assertSame("paused", $webhook->get_status());
            $this->assertSame(
                "https://example.com/api/v1/webhooks/woocommerce/" . $shop_id,
                $webhook->get_delivery_url(),
            );
            $this->assertSame("updated-secret", $webhook->get_secret());
        }
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
                "enabled" => true,
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
                "enabled" => true,
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
                "enabled" => true,
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
}
