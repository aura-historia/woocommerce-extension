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
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * Prevents real HTTP requests during webhook ping or delivery.
     *
     * @param mixed  $preempt Existing preempted response.
     * @param array  $args HTTP arguments.
     * @param string $url Request URL.
     * @return array
     */
    public function mock_http_request($preempt, $args, $url)
    {
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
        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "endpoint_url" => "https://example.com/hooks",
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
                "https://example.com/hooks",
                $webhook->get_delivery_url(),
            );
            $this->assertSame("test-secret", $webhook->get_secret());
            $this->assertSame($this->admin_user_id, $webhook->get_user_id());
            $this->assertSame("wp_api_v3", $webhook->get_api_version());
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

        update_option(
            Webhook_Manager::OPTION_SETTINGS,
            [
                "endpoint_url" => "https://example.com/original",
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
                "endpoint_url" => "https://example.com/updated",
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
                "https://example.com/updated",
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
                "endpoint_url" => "https://example.com/hooks",
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
                "endpoint_url" => "https://example.com/hooks",
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
}
