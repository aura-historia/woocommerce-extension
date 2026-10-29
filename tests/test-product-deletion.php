<?php
/**
 * Integration tests for permanent product deletion delivery.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Webhook_Manager;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;

/**
 * A permanently deleted parent product withdraws its listing through the
 * managed product.deleted webhook, exactly as a trashed product does.
 */
class Test_AHPC_Product_Deletion extends WP_UnitTestCase
{
    const SOURCE = "ls_01jw7j4azge00800000000000g";
    const TOKEN = "aurahistoria_abcdefghijk_verylongtokenvalue";
    const SECRET = "deletion-test-secret";
    const MERCHANT_URL = "https://merchant.example/hooks/product-deleted";

    /**
     * Captured outbound webhook deliveries.
     *
     * @var array<int,array{url:string,args:array}>
     */
    private $deliveries = [];

    /**
     * Webhooks enqueued for the current simulated request.
     *
     * @var WC_Webhook[]
     */
    private $enqueued = [];

    public static function wpSetUpBeforeClass($factory)
    {
        WC_Install::install();
    }

    public function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(["role" => "administrator"]));
        add_filter("ahpc_backend_base_url", [$this, "base_url"]);
        add_filter("ahpc_backend_guzzle_client", [$this, "registration_client"]);
        add_filter("pre_http_request", [$this, "capture_delivery"], 10, 3);
        // Deliver WooCommerce's shutdown queue on demand instead of via Action Scheduler.
        add_filter("woocommerce_webhook_deliver_async", "__return_false");

        $manager = new Webhook_Manager();
        $manager->delete_webhooks();
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => self::SOURCE,
            "access_token" => self::TOKEN,
            "secret" => self::SECRET,
        ], false);
        update_option(Webhook_Manager::OPTION_WEBHOOK_USER_ID, get_current_user_id(), false);
        $this->assertTrue($manager->sync_webhooks());
        $GLOBALS["wc_queued_webhooks"] = [];
    }

    public function tearDown(): void
    {
        $GLOBALS["wc_queued_webhooks"] = [];
        remove_filter("ahpc_backend_base_url", [$this, "base_url"]);
        remove_filter("ahpc_backend_guzzle_client", [$this, "registration_client"]);
        remove_filter("pre_http_request", [$this, "capture_delivery"], 10);
        remove_filter("woocommerce_webhook_deliver_async", "__return_false");
        (new Webhook_Manager())->delete_webhooks();
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function base_url($url)
    {
        return "https://example.com";
    }

    /** Confirms every ingestion configuration PUT. */
    public function registration_client($client)
    {
        return new Client(["handler" => HandlerStack::create(static function () {
            return Create::promiseFor(new Response(204));
        })]);
    }

    public function capture_delivery($preempt, $args, $url)
    {
        $this->deliveries[] = ["url" => $url, "args" => $args];
        return ["headers" => [], "body" => "", "response" => ["code" => 204, "message" => "No Content"], "cookies" => [], "filename" => null];
    }

    /**
     * Enqueues the active webhooks as WooCommerce does on init of each request.
     *
     * @return void
     */
    private function start_request()
    {
        foreach ($this->enqueued as $webhook) {
            foreach ($webhook->get_hooks() as $hook) {
                remove_action($hook, [$webhook, "process"]);
            }
        }
        $this->enqueued = [];
        $GLOBALS["wc_queued_webhooks"] = [];
        foreach (WC_Data_Store::load("webhook")->get_webhooks_ids("active") as $id) {
            $webhook = new WC_Webhook($id);
            $webhook->enqueue();
            $this->enqueued[] = $webhook;
        }
    }

    /**
     * Runs WooCommerce's shutdown delivery queue.
     *
     * @return array<int,array{url:string,args:array}>
     */
    private function finish_request()
    {
        $this->deliveries = [];
        wc_webhook_execute_queue();
        $GLOBALS["wc_queued_webhooks"] = [];
        return $this->deliveries;
    }

    /**
     * Reduces deliveries to URL, topic and exact body.
     *
     * @param array<int,array{url:string,args:array}> $deliveries Deliveries.
     * @param string|null                              $topic      Only this topic.
     * @return array<int,array{0:string,1:string,2:string}>
     */
    private function summarize(array $deliveries, $topic = null)
    {
        $summary = [];
        foreach ($deliveries as $delivery) {
            $headers = array_change_key_case($delivery["args"]["headers"], CASE_LOWER);
            if (null === $topic || $topic === $headers["x-wc-webhook-topic"]) {
                $summary[] = [$delivery["url"], $headers["x-wc-webhook-topic"], $delivery["args"]["body"]];
            }
        }
        return $summary;
    }

    private function endpoint()
    {
        return Webhook_Manager::get_webhook_endpoint_url(self::SOURCE);
    }

    private function deletion($product_id)
    {
        return [$this->endpoint(), "product.deleted", wp_json_encode(["id" => $product_id])];
    }

    /**
     * Asserts WooCommerce's exact signed deletion body and managed bearer auth.
     *
     * @param array{url:string,args:array} $delivery   Captured delivery.
     * @param int                          $product_id Expected source listing ID.
     * @return void
     */
    private function assert_signed_deletion(array $delivery, $product_id)
    {
        $headers = array_change_key_case($delivery["args"]["headers"], CASE_LOWER);
        $body = $delivery["args"]["body"];
        $this->assertSame($this->endpoint(), $delivery["url"]);
        $this->assertSame('{"id":' . $product_id . "}", $body);
        $this->assertSame("product.deleted", $headers["x-wc-webhook-topic"]);
        $this->assertSame(
            (new Webhook_Manager())->get_webhook_ids()["product.deleted"],
            (int) $headers["x-wc-webhook-id"],
        );
        $this->assertSame(base64_encode(hash_hmac("sha256", $body, self::SECRET, true)), $headers["x-wc-webhook-signature"]);
        $this->assertSame("Bearer " . self::TOKEN, $headers["authorization"]);
    }

    private function published_product($name = "Deletion fixture")
    {
        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_status("publish");
        $product->set_regular_price("10");
        $product->save();
        return $product->get_id();
    }

    /**
     * @return array{0:int,1:int[]} Parent ID and variation IDs.
     */
    private function published_variable_product()
    {
        $parent = new WC_Product_Variable();
        $parent->set_name("Variable deletion fixture");
        $parent->set_status("publish");
        $parent->save();
        $variation_ids = [];
        foreach (["20", "30"] as $price) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent->get_id());
            $variation->set_status("publish");
            $variation->set_regular_price($price);
            $variation->save();
            $variation_ids[] = $variation->get_id();
        }
        WC_Product_Variable::sync($parent->get_id());
        return [$parent->get_id(), $variation_ids];
    }

    private function rest_delete($route, $force)
    {
        $request = new WP_REST_Request("DELETE", $route);
        $request->set_param("force", $force);
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
    }

    private function assert_absent($post_id)
    {
        clean_post_cache($post_id);
        $this->assertNull(get_post($post_id));
    }

    public function test_rest_force_delete_withdraws_published_product_without_trash()
    {
        $product_id = $this->published_product();

        $this->start_request();
        $this->rest_delete("/wc/v3/products/" . $product_id, true);
        $deliveries = $this->finish_request();

        $this->assert_absent($product_id);
        $this->assertSame([$this->deletion($product_id)], $this->summarize($deliveries));
        $this->assert_signed_deletion($deliveries[0], $product_id);
    }

    public function test_crud_force_delete_withdraws_simple_and_variable_parent_products()
    {
        $simple_id = $this->published_product();
        [$parent_id, $variation_ids] = $this->published_variable_product();

        $this->start_request();
        wc_get_product($simple_id)->delete(true);
        $deliveries = $this->finish_request();
        $this->assert_absent($simple_id);
        $this->assertSame([$this->deletion($simple_id)], $this->summarize($deliveries));
        $this->assert_signed_deletion($deliveries[0], $simple_id);

        // WooCommerce removes the variations with their parent; only the
        // parent is a catalog listing.
        $this->start_request();
        wc_get_product($parent_id)->delete(true);
        $deliveries = $this->finish_request();
        $this->assert_absent($parent_id);
        foreach ($variation_ids as $variation_id) {
            $this->assert_absent($variation_id);
        }
        $this->assertSame([$this->deletion($parent_id)], $this->summarize($deliveries, "product.deleted"));
        $this->assert_signed_deletion($this->find_deletion($deliveries), $parent_id);
    }

    public function test_rest_force_delete_of_variable_product_withdraws_only_parent()
    {
        [$parent_id, $variation_ids] = $this->published_variable_product();

        $this->start_request();
        $this->rest_delete("/wc/v3/products/" . $parent_id, true);
        $deliveries = $this->finish_request();

        $this->assert_absent($parent_id);
        foreach ($variation_ids as $variation_id) {
            $this->assert_absent($variation_id);
        }
        $this->assertSame([$this->deletion($parent_id)], $this->summarize($deliveries, "product.deleted"));
    }

    public function test_trash_then_permanent_delete_repeats_the_same_signed_deletion()
    {
        $product_id = $this->published_product();

        $this->start_request();
        $this->rest_delete("/wc/v3/products/" . $product_id, false);
        $trashed = $this->finish_request();
        $this->assertSame("trash", get_post_status($product_id));
        $this->assertSame([$this->deletion($product_id)], $this->summarize($trashed));

        $this->start_request();
        $this->rest_delete("/wc/v3/products/" . $product_id, true);
        $deleted = $this->finish_request();
        $this->assert_absent($product_id);
        // The repeated message is byte-identical and nothing recreates the listing.
        $this->assertSame([$this->deletion($product_id)], $this->summarize($deleted));
        $this->assert_signed_deletion($deleted[0], $product_id);
        $this->assertSame($trashed[0]["args"]["body"], $deleted[0]["args"]["body"]);
        $this->assertSame($trashed[0]["args"]["headers"]["X-WC-Webhook-Signature"], $deleted[0]["args"]["headers"]["X-WC-Webhook-Signature"]);

        // Emptying the Trash in wp-admin or WP-CLI calls wp_delete_post().
        $emptied_id = $this->published_product("Emptied fixture");
        $this->start_request();
        wc_get_product($emptied_id)->delete(false);
        $this->finish_request();
        $this->start_request();
        wp_delete_post($emptied_id, true);
        $this->assertSame([$this->deletion($emptied_id)], $this->summarize($this->finish_request()));

        // Trash and deletion within one request deliver one message.
        $same_request_id = $this->published_product("Same request fixture");
        $this->start_request();
        wc_get_product($same_request_id)->delete(false);
        wp_delete_post($same_request_id, true);
        $this->assertSame([$this->deletion($same_request_id)], $this->summarize($this->finish_request()));
    }

    public function test_variation_deletion_keeps_published_parent_listing()
    {
        [$parent_id, $variation_ids] = $this->published_variable_product();

        $this->start_request();
        $this->rest_delete("/wc/v3/products/" . $parent_id . "/variations/" . $variation_ids[0], true);
        $this->assertSame([], $this->summarize($this->finish_request(), "product.deleted"));
        $this->assert_absent($variation_ids[0]);

        $this->start_request();
        wc_get_product($variation_ids[1])->delete(true);
        $this->assertSame([], $this->summarize($this->finish_request(), "product.deleted"));
        $this->assert_absent($variation_ids[1]);

        clean_post_cache($parent_id);
        $this->assertSame("publish", get_post_status($parent_id));
    }

    public function test_failed_permanent_delete_is_not_reported()
    {
        global $wpdb;
        $product_id = $this->published_product();
        $statement = "DELETE FROM `{$wpdb->posts}` WHERE `ID` = " . $product_id;
        // WooCommerce CRUD reports success even when the row delete fails.
        $fail_row_delete = static function ($query) use ($statement, $wpdb) {
            return $statement === $query ? "DELETE FROM `{$wpdb->posts}` WHERE 0 = 1" : $query;
        };

        $this->start_request();
        add_filter("query", $fail_row_delete);
        try {
            wc_get_product($product_id)->delete(true);
        } finally {
            remove_filter("query", $fail_row_delete);
        }

        $this->assertSame([], $this->finish_request());
        clean_post_cache($product_id);
        $this->assertSame("publish", get_post_status($product_id));
    }

    public function test_merchant_webhooks_and_unrelated_posts_are_unaffected()
    {
        $merchant = new WC_Webhook();
        $merchant->set_name("Merchant product deletions");
        $merchant->set_topic("product.deleted");
        $merchant->set_delivery_url(self::MERCHANT_URL);
        $merchant->set_secret("merchant-secret");
        $merchant->set_user_id(get_current_user_id());
        $merchant->set_status("paused");
        $merchant->set_api_version(3);
        $merchant->save();
        // Activate without WooCommerce's one-time ping for a new webhook.
        $merchant->set_pending_delivery(false);
        $merchant->set_status("active");
        $merchant->save();
        $managed_ids = (new Webhook_Manager())->get_webhook_ids();

        $this->assertContains(Webhook_Manager::PRODUCT_DELETED_HOOK, (new WC_Webhook($managed_ids["product.deleted"]))->get_hooks());
        $this->assertContains("wp_trash_post", (new WC_Webhook($managed_ids["product.deleted"]))->get_hooks());
        $this->assertNotContains(Webhook_Manager::PRODUCT_DELETED_HOOK, (new WC_Webhook($managed_ids["product.updated"]))->get_hooks());
        $this->assertSame(["wp_trash_post"], (new WC_Webhook($merchant->get_id()))->get_hooks());

        $kept_id = $this->published_product("Kept fixture");
        $deleted_id = $this->published_product();
        $page_id = self::factory()->post->create(["post_type" => "page", "post_status" => "publish"]);
        $auto_draft_id = wp_insert_post(["post_type" => "product", "post_status" => "auto-draft", "post_title" => "Auto Draft"]);

        $this->start_request();
        $this->rest_delete("/wc/v3/products/" . $deleted_id, true);
        wp_delete_post($page_id, true);
        wp_delete_post($auto_draft_id, true);
        $this->assertSame([$this->deletion($deleted_id)], $this->summarize($this->finish_request()));

        clean_post_cache($kept_id);
        $this->assertSame("publish", get_post_status($kept_id));

        // Trash keeps WooCommerce's semantics for every product.deleted webhook.
        $this->start_request();
        wc_get_product($kept_id)->delete(false);
        $this->assertEqualsCanonicalizing(
            [$this->deletion($kept_id), [self::MERCHANT_URL, "product.deleted", wp_json_encode(["id" => $kept_id])]],
            $this->summarize($this->finish_request()),
        );
    }

    public function test_paused_managed_webhook_does_not_deliver_permanent_deletion()
    {
        $product_id = $this->published_product();
        (new Webhook_Manager())->pause_webhooks();

        $this->start_request();
        wc_get_product($product_id)->delete(true);

        $this->assert_absent($product_id);
        $this->assertSame([], $this->finish_request());
    }

    /**
     * @param array<int,array{url:string,args:array}> $deliveries Deliveries.
     * @return array{url:string,args:array}
     */
    private function find_deletion(array $deliveries)
    {
        foreach ($deliveries as $delivery) {
            if ("product.deleted" === array_change_key_case($delivery["args"]["headers"], CASE_LOWER)["x-wc-webhook-topic"]) {
                return $delivery;
            }
        }
        $this->fail("No product.deleted delivery was sent.");
    }
}
