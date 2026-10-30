<?php
/**
 * Integration tests for the OAuth broker transaction protocol (version 2).
 *
 * @package AuraHistoria\PartnerConnect
 */

require_once __DIR__ . "/class-ahpc-fake-oauth-broker.php";

use AuraHistoria\PartnerConnect\OAuth_Broker_Client;
use AuraHistoria\PartnerConnect\Option_Lease;
use AuraHistoria\PartnerConnect\Plugin;
use AuraHistoria\PartnerConnect\Product_Backfill;
use AuraHistoria\PartnerConnect\Shipping_Publisher;
use AuraHistoria\PartnerConnect\Shipping_Sync;
use AuraHistoria\PartnerConnect\Webhook_Manager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Plugin whose secure random source fails.
 */
class AHPC_Test_Plugin_Without_Randomness extends Plugin
{
    protected function generate_oauth_random_value()
    {
        return new WP_Error("ahpc_oauth_random_unavailable", "No secure randomness.");
    }
}

/**
 * Tests connection start, callback validation, proof-protected completion and
 * connection persistence against an in-memory broker.
 */
class Test_AHPC_OAuth_Connection extends WP_UnitTestCase
{
    const SOURCE_A = "ls_01jw7j4azge00800000000000g";
    const SOURCE_B = "ls_01jw7j4azge008000000000003";
    const TOKEN = "aurahistoria_abcdefghijk_verylongtokenvalue";
    const OLD_TOKEN = "aurahistoria_previous_connection_token";
    const CLIENT_ID = "oc_01jw7j4azge00800000000000g";
    const ADMIN_BASE_URL = "https://merchant.example/wp-admin/";
    const SETTINGS_URL = "https://merchant.example/wp-admin/admin.php?page=aura-historia-partner-connect";

    /** @var int */
    protected $admin_user_id = 0;

    /** @var AHPC_Fake_OAuth_Broker */
    protected $broker;

    /** @var string */
    protected $broker_base_url = AHPC_Fake_OAuth_Broker::BASE_URL;

    /** @var string */
    protected $client_id = self::CLIENT_ID;

    /** @var string */
    protected $admin_base_url = self::ADMIN_BASE_URL;

    /** @var string Static query appended to admin URLs, as some plugins do. */
    protected $admin_url_suffix = "";

    /** @var array<int,array<string,mixed>> */
    protected $backend_requests = [];

    /** @var Client */
    protected $backend_client;

    /** @var array<int,string> */
    protected $http_urls = [];

    /** @var string|null */
    protected $original_pagenow = null;

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
        update_option(Webhook_Manager::OPTION_WEBHOOK_USER_ID, $this->admin_user_id, false);

        $this->broker = new AHPC_Fake_OAuth_Broker();
        add_filter("ahpc_oauth_broker_guzzle_client", function () {
            return $this->broker->client();
        });
        add_filter("ahpc_oauth_broker_base_url", function () {
            return $this->broker_base_url;
        });
        add_filter("ahpc_oauth_client_id", function () {
            return $this->client_id;
        });
        add_filter("admin_url", function ($url, $path = "") {
            unset($url);
            return $this->admin_base_url . ltrim((string) $path, "/") . $this->admin_url_suffix;
        }, 10, 2);
        add_filter("ahpc_backend_base_url", static function () {
            return "https://example.com";
        });
        add_filter("ahpc_backend_guzzle_client", function () {
            return $this->backend_client;
        });
        add_filter("pre_http_request", function ($preempt, $args, $url) {
            unset($preempt, $args);
            $this->http_urls[] = $url;
            return ["headers" => [], "body" => "", "response" => ["code" => 200, "message" => "OK"], "cookies" => [], "filename" => null];
        }, 10, 3);
        $this->set_backend_responses(array_fill(0, 10, new Response(204)));

        $manager = new Webhook_Manager();
        $manager->delete_webhooks();
        $manager->initialize_options();

        // Callbacks are only recognized on wp-admin/admin.php.
        $this->original_pagenow = $GLOBALS["pagenow"] ?? null;
        $GLOBALS["pagenow"] = "admin.php";
    }

    public function tearDown(): void
    {
        (new Webhook_Manager())->delete_webhooks();
        unset($_SERVER["QUERY_STRING"]);
        $_GET = [];
        $GLOBALS["pagenow"] = $this->original_pagenow;
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * @param array<int,mixed> $responses Backend responses.
     * @return void
     */
    protected function set_backend_responses(array $responses)
    {
        $this->backend_requests = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->backend_requests));
        $this->backend_client = new Client(["handler" => $stack, "http_errors" => true]);
    }

    /**
     * Starts a connection and asserts that it reached the broker.
     *
     * @param Plugin|null $plugin Plugin.
     * @return string Authorization URL.
     */
    protected function start(?Plugin $plugin = null)
    {
        $url = ($plugin ?: new Plugin())->start_oauth_connection();
        $this->assertIsString($url, is_wp_error($url) ? $url->get_error_code() : "");

        return $url;
    }

    /**
     * Completes a connection with callback fields.
     *
     * @param array<string,string> $callback Callback fields.
     * @param Plugin|null          $plugin   Plugin.
     * @return true|WP_Error
     */
    protected function complete(array $callback, ?Plugin $plugin = null)
    {
        return ($plugin ?: new Plugin())->complete_oauth_connection(
            $callback["transaction_id"],
            $callback["state"],
            $callback["completion_code"],
        );
    }

    /**
     * @return mixed Stored attempt read directly from the database.
     */
    protected function attempt()
    {
        return Option_Lease::read(Plugin::OPTION_OAUTH_ATTEMPT, null);
    }

    /**
     * @param string $listing_source_id Source.
     * @param string $access_token      Token.
     * @return void
     */
    protected function store_connection($listing_source_id = self::SOURCE_B, $access_token = self::OLD_TOKEN)
    {
        update_option(Webhook_Manager::OPTION_SETTINGS, [
            "listing_source_id" => $listing_source_id,
            "access_token" => $access_token,
            "secret" => "test-secret",
        ], false);
    }

    /**
     * @return array<string,string>
     */
    protected function settings()
    {
        wp_cache_delete(Webhook_Manager::OPTION_SETTINGS, "options");
        return get_option(Webhook_Manager::OPTION_SETTINGS, []);
    }

    /**
     * Runs the admin_init callback handler for a raw query string and returns
     * the redirect location.
     *
     * @param string $query Raw query string, starting with the page parameter.
     * @return string
     */
    protected function run_callback($query)
    {
        $_SERVER["QUERY_STRING"] = $query;
        $_GET = [];
        parse_str($query, $_GET);
        $allow_admin_host = static function ($hosts) {
            $hosts[] = "merchant.example";
            return $hosts;
        };
        $capture = static function ($location) {
            throw new RuntimeException($location);
        };
        add_filter("allowed_redirect_hosts", $allow_admin_host);
        add_filter("wp_redirect", $capture);
        $location = "";

        try {
            (new Plugin())->maybe_handle_oauth_callback();
            $this->fail("The callback handler did not redirect.");
        } catch (RuntimeException $redirect) {
            $location = $redirect->getMessage();
        } finally {
            remove_filter("wp_redirect", $capture);
            remove_filter("allowed_redirect_hosts", $allow_admin_host);
            unset($_SERVER["QUERY_STRING"]);
            $_GET = [];
        }

        return $location;
    }

    /**
     * @return string One-time callback notice for the current user.
     */
    protected function callback_notice()
    {
        return (string) get_transient(Plugin::OAUTH_NOTICE_TRANSIENT_PREFIX . get_current_user_id());
    }

    /**
     * @param array<string,string> $callback Callback fields.
     * @return string
     */
    protected function callback_query(array $callback)
    {
        return "page=aura-historia-partner-connect&" . http_build_query($callback, "", "&", PHP_QUERY_RFC3986);
    }

    /**
     * @return int Number of backfill actions queued for the first page.
     */
    protected function count_initial_backfill_actions($listing_source_id)
    {
        $count = 0;
        foreach (as_get_scheduled_actions([
            "hook" => Product_Backfill::ACTION_HOOK,
            "group" => Product_Backfill::ACTION_GROUP,
            "status" => ActionScheduler_Store::STATUS_PENDING,
            "per_page" => 100,
        ]) as $action) {
            $args = $action->get_args();
            if ($listing_source_id === $args[0] && 1 === $args[1]) {
                $count++;
            }
        }

        return $count;
    }

    public function test_start_registers_the_transaction_server_to_server_and_keeps_the_secret_local()
    {
        $authorization_url = $this->start();

        $this->assertCount(1, $this->broker->requests);
        $request = $this->broker->requests[0];
        $this->assertSame("POST", $request["method"]);
        $this->assertSame(AHPC_Fake_OAuth_Broker::BASE_URL . OAuth_Broker_Client::INITIATION_PATH, $request["url"]);
        $this->assertFalse($request["options"]["allow_redirects"]);
        $this->assertTrue($request["options"]["verify"]);
        $this->assertSame(OAuth_Broker_Client::TIMEOUT, $request["options"]["timeout"]);
        $body = $request["body"];
        $this->assertSame([
            "protocol_version",
            "client_id",
            "merchant_redirect_uri",
            "client_state",
            "scope",
            "completion_challenge",
            "completion_challenge_method",
        ], array_keys($body));
        $this->assertSame(2, $body["protocol_version"]);
        $this->assertSame(self::CLIENT_ID, $body["client_id"]);
        $this->assertSame(self::SETTINGS_URL, $body["merchant_redirect_uri"]);
        $this->assertSame(Plugin::OAUTH_SCOPE, $body["scope"]);
        $this->assertSame("S256", $body["completion_challenge_method"]);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $body["client_state"]);

        $attempt = $this->attempt();
        $this->assertIsArray($attempt);
        $this->assertSame($this->admin_user_id, $attempt["user_id"]);
        $this->assertSame($this->broker->last_transaction_id, $attempt["transaction_id"]);
        $this->assertSame(Plugin::OAUTH_PHASE_AWAITING_CALLBACK, $attempt["phase"]);
        $this->assertSame(hash("sha256", $body["client_state"]), $attempt["client_state_hash"]);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $attempt["completion_secret"]);
        $this->assertNotSame($body["client_state"], $attempt["completion_secret"]);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash("sha256", $attempt["completion_secret"], true)), "+/", "-_"), "="),
            $body["completion_challenge"],
        );
        $this->assertStringNotContainsString($attempt["completion_secret"], $request["raw"]);
        $this->assertLessThanOrEqual(time() + Plugin::OAUTH_ATTEMPT_TTL, $attempt["expires_at"]);
        $this->assertGreaterThan(time(), $attempt["expires_at"]);

        // The browser only gets the broker's opaque authorization URL.
        $this->assertStringStartsWith(AHPC_Fake_OAuth_Broker::BASE_URL . "/en/oauth/authorize?", $authorization_url);
        $this->assertStringNotContainsString($attempt["completion_secret"], $authorization_url);
        $this->assertStringNotContainsString($body["client_state"], $authorization_url);
        $this->assertStringNotContainsString("code_verifier", $authorization_url);
        parse_str((string) wp_parse_url($authorization_url, PHP_URL_QUERY), $query);
        $this->assertNull(json_decode((string) base64_decode(strtr($query["state"], "-_", "+/")), true));

        // The attempt row holds the secret, so it must never be autoloaded.
        global $wpdb;
        $autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Plugin::OPTION_OAUTH_ATTEMPT));
        $this->assertContains($autoload, ["no", "off"]);
        $this->assertSame([], $this->backend_requests);
    }

    public function test_start_requires_manage_woocommerce()
    {
        wp_set_current_user(self::factory()->user->create(["role" => "subscriber"]));

        $result = (new Plugin())->start_oauth_connection();

        $this->assertWPError($result);
        $this->assertSame("ahpc_oauth_forbidden", $result->get_error_code());
        $this->assertSame([], $this->broker->requests);
        $this->assertNull($this->attempt());
    }

    public function test_start_action_requires_capability_and_nonce()
    {
        wp_set_current_user(self::factory()->user->create(["role" => "subscriber"]));
        try {
            (new Plugin())->handle_oauth_start_request();
            $this->fail("An unauthorized start must stop.");
        } catch (WPDieException $exception) {
            $this->assertSame([], $this->broker->requests);
        }

        wp_set_current_user($this->admin_user_id);
        $_REQUEST["_wpnonce"] = "invalid";
        try {
            (new Plugin())->handle_oauth_start_request();
            $this->fail("A start without a valid nonce must stop.");
        } catch (WPDieException $exception) {
            $this->assertSame([], $this->broker->requests);
            $this->assertNull($this->attempt());
        } finally {
            unset($_REQUEST["_wpnonce"]);
        }
    }

    public function test_start_action_redirects_only_to_the_checked_authorization_url()
    {
        $_REQUEST["_wpnonce"] = wp_create_nonce("ahpc_start_oauth");
        $capture = static function ($location) {
            throw new RuntimeException($location);
        };
        add_filter("wp_redirect", $capture);
        $location = "";
        try {
            (new Plugin())->handle_oauth_start_request();
        } catch (RuntimeException $redirect) {
            $location = $redirect->getMessage();
        } finally {
            unset($_REQUEST["_wpnonce"]);
            remove_filter("wp_redirect", $capture);
        }

        // wp_safe_redirect() falls back to wp-admin unless the broker host was allowed.
        $this->assertStringStartsWith(AHPC_Fake_OAuth_Broker::BASE_URL . "/en/oauth/authorize?", $location);
        $this->assertSame(Plugin::OAUTH_PHASE_AWAITING_CALLBACK, $this->attempt()["phase"]);
    }

    public function test_start_fails_safely_without_secure_randomness()
    {
        $result = (new AHPC_Test_Plugin_Without_Randomness())->start_oauth_connection();

        $this->assertWPError($result);
        $this->assertSame("ahpc_oauth_random_unavailable", $result->get_error_code());
        $this->assertSame([], $this->broker->requests);
        $this->assertNull($this->attempt());
    }

    /**
     * @return array<string,array{0:callable}>
     */
    public function invalid_initiation_responses()
    {
        $valid = static function (array $overrides = [], array $remove = []) {
            return static function ($body) use ($overrides, $remove) {
                $response = array_merge([
                    "protocol_version" => 2,
                    "transaction_id" => "obt_0123456789abcdef01234567",
                    "authorization_url" => AHPC_Fake_OAuth_Broker::authorization_url($body["client_id"], $body["scope"], "ref_opaque"),
                    "expires_at" => gmdate("Y-m-d\\TH:i:s\\Z", time() + 600),
                ], $overrides);
                foreach ($remove as $key) {
                    unset($response[$key]);
                }
                return AHPC_Fake_OAuth_Broker::json(201, $response);
            };
        };
        $url = static function ($mutate) {
            return static function ($body) use ($mutate) {
                return AHPC_Fake_OAuth_Broker::json(201, [
                    "protocol_version" => 2,
                    "transaction_id" => "obt_0123456789abcdef01234567",
                    "authorization_url" => $mutate(AHPC_Fake_OAuth_Broker::authorization_url($body["client_id"], $body["scope"], "ref_opaque"), $body),
                    "expires_at" => gmdate("Y-m-d\\TH:i:s\\Z", time() + 600),
                ]);
            };
        };

        return [
            "not JSON" => [static function () {
                return new Response(201, ["Content-Type" => "text/html"], "<html></html>");
            }],
            "JSON list" => [static function () {
                return new Response(201, ["Content-Type" => "application/json"], "[]");
            }],
            "wrong version" => [$valid(["protocol_version" => 1])],
            "version as string" => [$valid(["protocol_version" => "2"])],
            "missing transaction" => [$valid([], ["transaction_id"])],
            "malformed transaction" => [$valid(["transaction_id" => "short"])],
            "transaction with a slash" => [$valid(["transaction_id" => "obt_0123456789/../abcdef"])],
            "missing expiry" => [$valid([], ["expires_at"])],
            "malformed expiry" => [$valid(["expires_at" => "tomorrow"])],
            "expired result" => [$valid(["expires_at" => gmdate("Y-m-d\\TH:i:s\\Z", time() - 1)])],
            "other host" => [$url(static function ($u) {
                return str_replace("https://broker.example", "https://evil.example", $u);
            })],
            "lookalike host" => [$url(static function ($u) {
                return str_replace("https://broker.example", "https://broker.example.evil.example", $u);
            })],
            "other port" => [$url(static function ($u) {
                return str_replace("https://broker.example", "https://broker.example:8443", $u);
            })],
            "plain HTTP" => [$url(static function ($u) {
                return str_replace("https://", "http://", $u);
            })],
            "userinfo" => [$url(static function ($u) {
                return str_replace("https://", "https://user@", $u);
            })],
            "other route" => [$url(static function ($u) {
                return str_replace("/en/oauth/authorize", "/oauth/authorize/../../logout", $u);
            })],
            "fragment" => [$url(static function ($u) {
                return $u . "#x";
            })],
            "verifier parameter" => [$url(static function ($u) {
                return $u . "&code_verifier=" . str_repeat("v", 43);
            })],
            "unknown parameter" => [$url(static function ($u) {
                return $u . "&listing_source_id=" . self::SOURCE_B;
            })],
            "duplicate parameter" => [$url(static function ($u) {
                return $u . "&scope=product-listings:read";
            })],
            "array parameter" => [$url(static function ($u) {
                return $u . "&state[]=x";
            })],
            "other client" => [$url(static function ($u) {
                return str_replace(self::CLIENT_ID, "oc_01jw7j4azge008000000000003", $u);
            })],
            "other scope" => [$url(static function ($u, $body) {
                return str_replace(rawurlencode($body["scope"]), rawurlencode("product-listings:read"), $u);
            })],
            "readable JSON state" => [$url(static function ($u) {
                $state = rtrim(strtr(base64_encode(wp_json_encode(["code_verifier" => str_repeat("v", 64)])), "+/", "-_"), "=");
                return str_replace("state=ref_opaque", "state=" . $state, $u);
            })],
            "redirect URI on another origin" => [$url(static function ($u) {
                return str_replace(rawurlencode("https://broker.example/api"), rawurlencode("https://evil.example/api"), $u);
            })],
            "wrong challenge method" => [$url(static function ($u) {
                return str_replace("code_challenge_method=S256", "code_challenge_method=plain", $u);
            })],
        ];
    }

    /**
     * @dataProvider invalid_initiation_responses
     */
    public function test_start_rejects_an_invalid_initiation_response($response)
    {
        $this->broker->queue[] = $response;

        $result = (new Plugin())->start_oauth_connection();

        $this->assertWPError($result);
        $this->assertContains($result->get_error_code(), ["ahpc_oauth_broker_invalid_response", "ahpc_oauth_broker_expired"]);
        $this->assertCount(1, $this->broker->requests);
        $this->assertNull($this->attempt());
    }

    public function test_start_reports_broker_failures_safely_and_never_follows_redirects()
    {
        $cases = [
            [new ConnectException("connection refused", new Request("POST", "https://broker.example")), "ahpc_oauth_broker_unavailable"],
            [AHPC_Fake_OAuth_Broker::json(503, ["error" => "temporarily_unavailable"]), "ahpc_oauth_broker_unavailable"],
            [AHPC_Fake_OAuth_Broker::json(429, []), "ahpc_oauth_broker_unavailable"],
            [AHPC_Fake_OAuth_Broker::json(400, ["error" => "unauthorized_destination"]), "ahpc_oauth_broker_rejected"],
            [AHPC_Fake_OAuth_Broker::json(400, ["error" => "unsupported_protocol_version"]), "ahpc_oauth_broker_rejected"],
            [new Response(302, ["Location" => "https://evil.example/collect"]), "ahpc_oauth_broker_rejected"],
            [new Response(201, ["Content-Type" => "application/json"], str_repeat(" ", OAuth_Broker_Client::MAX_RESPONSE_BYTES + 1) . "{}"), "ahpc_oauth_broker_invalid_response"],
            [new Response(201, ["Content-Type" => "application/json", "Content-Length" => (string) (OAuth_Broker_Client::MAX_RESPONSE_BYTES + 1)], "{}"), "ahpc_oauth_broker_invalid_response"],
        ];

        foreach ($cases as $index => [$response, $code]) {
            $this->broker->requests = [];
            $this->broker->queue = [$response];

            $result = (new Plugin())->start_oauth_connection();

            $this->assertWPError($result, (string) $index);
            $this->assertSame($code, $result->get_error_code(), (string) $index);
            $this->assertCount(1, $this->broker->requests, (string) $index);
            $this->assertNull($this->attempt(), (string) $index);
            $this->assertStringNotContainsString("evil.example", $result->get_error_message());
        }

        $this->assertSame("unauthorized_destination", (function () {
            $this->broker->queue = [AHPC_Fake_OAuth_Broker::json(403, ["error" => "unauthorized_destination", "detail" => "secret detail"])];
            $error = (new Plugin())->start_oauth_connection();
            $this->assertStringNotContainsString("secret detail", $error->get_error_message());
            $this->assertStringNotContainsString("secret detail", wp_json_encode($error->get_error_data()));
            return $error->get_error_data()["broker_error"];
        })());
    }

    public function test_start_rejects_invalid_configuration_without_contacting_the_broker()
    {
        foreach (["", "http://broker.example", "https://broker.example/path", "https://broker.example?x=1", "https://user:pass@broker.example", "ftp://broker.example"] as $base_url) {
            $this->broker_base_url = $base_url;
            $result = (new Plugin())->start_oauth_connection();
            $this->assertWPError($result, $base_url);
            $this->assertSame("ahpc_oauth_invalid_endpoint", $result->get_error_code(), $base_url);
        }

        $this->broker_base_url = "http://localhost:8888";
        $this->broker->queue[] = AHPC_Fake_OAuth_Broker::json(500, []);
        $this->assertSame("ahpc_oauth_broker_unavailable", (new Plugin())->start_oauth_connection()->get_error_code());
        $this->assertSame("http://localhost:8888" . OAuth_Broker_Client::INITIATION_PATH, $this->broker->requests[0]["url"]);

        $this->broker->requests = [];
        $this->broker_base_url = AHPC_Fake_OAuth_Broker::BASE_URL;
        $this->admin_base_url = "http://merchant.example/wp-admin/";
        $result = (new Plugin())->start_oauth_connection();
        $this->assertSame("ahpc_oauth_invalid_callback_url", $result->get_error_code());

        $this->admin_base_url = self::ADMIN_BASE_URL;
        $this->client_id = "";
        $result = (new Plugin())->start_oauth_connection();
        $this->assertSame("ahpc_oauth_invalid_client_id", $result->get_error_code());
        $this->assertSame([], $this->broker->requests);
    }

    public function test_a_failed_restart_retires_the_previous_attempt()
    {
        $this->start();
        $first = $this->broker->approve();
        $this->broker->queue[] = AHPC_Fake_OAuth_Broker::json(503, []);

        $this->assertWPError((new Plugin())->start_oauth_connection());
        $this->assertNull($this->attempt());

        $result = $this->complete($first);
        $this->assertSame("ahpc_oauth_expired_state", $result->get_error_code());
        $this->assertSame([], $this->broker->completion_requests());
    }

    public function test_successful_connection_stores_the_broker_pair_and_runs_the_connection_lifecycle()
    {
        $this->store_connection("", "");
        $this->start();
        $callback = $this->broker->approve(null, ["listing_source_id" => self::SOURCE_A, "access_token" => self::TOKEN]);
        $this->set_backend_responses([new Response(201)]);

        $this->assertTrue($this->complete($callback));

        $settings = $this->settings();
        $this->assertSame(self::SOURCE_A, $settings["listing_source_id"]);
        $this->assertSame(self::TOKEN, $settings["access_token"]);
        $this->assertSame("test-secret", $settings["secret"]);
        $this->assertNull($this->attempt());

        $completion = $this->broker->completion_requests();
        $this->assertCount(1, $completion);
        $this->assertSame(AHPC_Fake_OAuth_Broker::BASE_URL . OAuth_Broker_Client::COMPLETION_PATH, $completion[0]["url"]);
        $this->assertSame(["protocol_version", "transaction_id", "completion_code", "completion_secret"], array_keys($completion[0]["body"]));
        $this->assertSame($callback["completion_code"], $completion[0]["body"]["completion_code"]);
        $this->assertFalse($completion[0]["options"]["allow_redirects"]);
        $this->assertTrue($completion[0]["options"]["verify"]);

        $this->assertCount(1, $this->backend_requests);
        $this->assertSame(
            "https://example.com/v1/listing-sources/" . self::SOURCE_A . "/ingestion-configurations/woocommerce",
            (string) $this->backend_requests[0]["request"]->getUri(),
        );
        $this->assertSame("Bearer " . self::TOKEN, $this->backend_requests[0]["request"]->getHeaderLine("Authorization"));
        foreach ((new Webhook_Manager())->get_webhook_ids() as $webhook_id) {
            $this->assertSame("active", (new WC_Webhook($webhook_id))->get_status());
        }
        $this->assertSame([self::SOURCE_A => true], get_option(Plugin::OPTION_INITIAL_BACKFILL_STARTED_SOURCES, []));
        $this->assertSame(1, $this->count_initial_backfill_actions(self::SOURCE_A));
        $this->assertTrue((bool) as_has_scheduled_action(Shipping_Publisher::ACTION_HOOK, null, Shipping_Publisher::ACTION_GROUP));
    }

    public function test_static_callback_query_parameters_must_come_back_unchanged()
    {
        // An admin URL filter, such as a multilingual plugin, adds a parameter.
        $this->admin_url_suffix = "&lang=de";
        $this->start();
        $this->assertSame(self::SETTINGS_URL . "&lang=de", $this->broker->requests[0]["body"]["merchant_redirect_uri"]);
        $callback = $this->broker->approve();
        $query = $this->callback_query($callback);
        $settings_url = self::SETTINGS_URL . "&lang=de";

        $this->assertSame($settings_url . "&ahpc_oauth=rejected", $this->run_callback($query));
        $this->assertSame($settings_url . "&ahpc_oauth=rejected", $this->run_callback($query . "&lang=fr"));
        $this->assertSame([], $this->broker->completion_requests());

        $this->assertSame($settings_url . "&ahpc_oauth=connected", $this->run_callback($query . "&lang=de"));
        $this->assertSame(self::SOURCE_A, $this->settings()["listing_source_id"]);
    }

    public function test_a_start_that_cannot_store_its_attempt_fails_without_redirecting()
    {
        $break_insert = static function ($sql) {
            return false !== strpos($sql, Plugin::OPTION_OAUTH_ATTEMPT) && 0 === stripos(ltrim($sql), "INSERT")
                ? "SELECT * FROM ahpc_missing_table"
                : $sql;
        };
        add_filter("query", $break_insert);
        try {
            $result = (new Plugin())->start_oauth_connection();
        } finally {
            remove_filter("query", $break_insert);
        }

        $this->assertWPError($result);
        $this->assertSame("ahpc_oauth_storage_failed", $result->get_error_code());
        $this->assertNull($this->attempt());
        $this->assertFalse(Option_Lease::read(Plugin::OPTION_OAUTH_LOCK, false));
    }

    public function test_an_abandoned_attempt_is_removed_after_it_expires()
    {
        $this->start();
        $attempt = $this->attempt();
        $scheduled = wp_next_scheduled(Plugin::OAUTH_CLEANUP_HOOK);
        $this->assertSame($attempt["expires_at"] + MINUTE_IN_SECONDS, $scheduled);

        // Too early: the attempt stays and the cleanup is kept.
        do_action(Plugin::OAUTH_CLEANUP_HOOK);
        $this->assertIsArray($this->attempt());
        $this->assertSame($scheduled, wp_next_scheduled(Plugin::OAUTH_CLEANUP_HOOK));

        $attempt["expires_at"] = time() - 1;
        update_option(Plugin::OPTION_OAUTH_ATTEMPT, $attempt, false);
        do_action(Plugin::OAUTH_CLEANUP_HOOK);
        $this->assertNull($this->attempt());

        Plugin::deactivate();
        $this->assertFalse(wp_next_scheduled(Plugin::OAUTH_CLEANUP_HOOK));
    }

    public function test_callback_handler_completes_and_redirects_to_a_clean_settings_url()
    {
        update_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR, "Earlier failure.", false);
        $this->start();
        $callback = $this->broker->approve();

        $location = $this->run_callback($this->callback_query($callback));

        $this->assertSame(self::SETTINGS_URL . "&ahpc_oauth=connected", $location);
        $this->assertSame(self::SOURCE_A, $this->settings()["listing_source_id"]);
        $this->assertFalse(get_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR, false));
    }

    public function test_callback_handler_ignores_other_requests_and_requires_capability()
    {
        $_GET = ["page" => Plugin::PAGE_SLUG, "ahpc_oauth" => "failed"];
        (new Plugin())->maybe_handle_oauth_callback();
        $_GET = ["page" => "other", "completion_code" => str_repeat("c", 43)];
        (new Plugin())->maybe_handle_oauth_callback();
        $GLOBALS["pagenow"] = "options-general.php";
        $_GET = ["page" => Plugin::PAGE_SLUG, "completion_code" => str_repeat("c", 43)];
        (new Plugin())->maybe_handle_oauth_callback();
        $GLOBALS["pagenow"] = "admin.php";

        $this->start();
        $callback = $this->broker->approve();
        wp_set_current_user(self::factory()->user->create(["role" => "subscriber"]));
        $_SERVER["QUERY_STRING"] = $this->callback_query($callback);
        parse_str($_SERVER["QUERY_STRING"], $_GET);
        try {
            (new Plugin())->maybe_handle_oauth_callback();
            $this->fail("An unauthorized callback must stop.");
        } catch (WPDieException $exception) {
            $this->assertSame([], $this->broker->completion_requests());
            $this->assertSame(Plugin::OAUTH_PHASE_AWAITING_CALLBACK, $this->attempt()["phase"]);
        }
    }

    public function test_browser_supplied_source_or_legacy_fields_cannot_complete_or_change_the_connection()
    {
        $this->store_connection();
        $this->start();
        $callback = $this->broker->approve(null, ["listing_source_id" => self::SOURCE_A]);

        foreach ([
            $this->callback_query($callback) . "&listing_source_id=" . self::SOURCE_B,
            $this->callback_query($callback) . "&error=access_denied",
            "page=aura-historia-partner-connect&third_party_exchange_code=01970f22-2bf0-7000-8000-000000000099&listing_source_id=" . self::SOURCE_B . "&state=" . $callback["state"],
            "page=aura-historia-partner-connect&error=access_denied&state=" . $callback["state"],
            "page=aura-historia-partner-connect&access_token=" . self::TOKEN . "&listing_source_id=" . self::SOURCE_B,
        ] as $query) {
            $location = $this->run_callback($query);
            $this->assertSame(self::SETTINGS_URL . "&ahpc_oauth=rejected", $location, $query);
        }

        // Nothing changed, so the rejection is shown once instead of stored.
        $this->assertStringContainsString("no longer accepts", $this->callback_notice());
        $this->assertFalse(get_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR, false));
        $this->assertSame([], $this->broker->completion_requests());
        $this->assertSame([], $this->backend_requests);
        $this->assertSame(self::SOURCE_B, $this->settings()["listing_source_id"]);
        $this->assertSame(self::OLD_TOKEN, $this->settings()["access_token"]);
        $this->assertSame(Plugin::OAUTH_PHASE_AWAITING_CALLBACK, $this->attempt()["phase"]);

        // The untouched attempt still completes, with the broker's source.
        $this->assertSame(self::SETTINGS_URL . "&ahpc_oauth=connected", $this->run_callback($this->callback_query($callback)));
        $this->assertSame(self::SOURCE_A, $this->settings()["listing_source_id"]);
    }

    public function test_duplicate_missing_or_malformed_callback_fields_are_rejected_without_consuming()
    {
        $this->start();
        $callback = $this->broker->approve();
        $query = $this->callback_query($callback);

        foreach ([
            $query . "&state=" . $callback["state"],
            $query . "&completion_code=" . $callback["completion_code"],
            str_replace("state=", "state[]=", $query),
            "page=aura-historia-partner-connect&transaction_id=" . $callback["transaction_id"] . "&state=" . $callback["state"],
            "page=aura-historia-partner-connect&page=aura-historia-partner-connect&" . http_build_query($callback),
            $query . "&extra=1",
            str_replace($callback["completion_code"], "short", $query),
            str_replace($callback["completion_code"], substr($callback["completion_code"], 0, 40) . "%2F%2F%2F", $query),
            str_replace($callback["transaction_id"], "obt_..%2F..%2Fadmin", $query),
            str_replace($callback["state"], $callback["state"] . "x", $query),
        ] as $tampered) {
            $this->assertSame(self::SETTINGS_URL . "&ahpc_oauth=rejected", $this->run_callback($tampered), $tampered);
        }

        $this->assertSame([], $this->broker->completion_requests());
        $this->assertSame(Plugin::OAUTH_PHASE_AWAITING_CALLBACK, $this->attempt()["phase"]);
        $this->assertTrue($this->complete($callback));
    }

    public function test_a_different_administrator_cannot_consume_the_attempt()
    {
        $this->start();
        $callback = $this->broker->approve();
        wp_set_current_user(self::factory()->user->create(["role" => "administrator"]));

        $result = $this->complete($callback);

        $this->assertSame("ahpc_oauth_user_mismatch", $result->get_error_code());
        $this->assertSame([], $this->broker->completion_requests());
        $this->assertSame(Plugin::OAUTH_PHASE_AWAITING_CALLBACK, $this->attempt()["phase"]);

        wp_set_current_user($this->admin_user_id);
        $this->assertTrue($this->complete($callback));
    }

    public function test_mismatched_transaction_or_state_does_not_consume_the_attempt()
    {
        $this->start();
        $callback = $this->broker->approve();

        $other_transaction = array_merge($callback, ["transaction_id" => "obt_ffffffffffffffffffffffff"]);
        $this->assertSame("ahpc_oauth_transaction_mismatch", $this->complete($other_transaction)->get_error_code());
        $other_state = array_merge($callback, ["state" => str_repeat("A", 43)]);
        $this->assertSame("ahpc_oauth_invalid_state", $this->complete($other_state)->get_error_code());
        $no_code = array_merge($callback, ["completion_code" => ""]);
        $this->assertSame("ahpc_oauth_invalid_state", $this->complete($no_code)->get_error_code());

        $this->assertSame([], $this->broker->completion_requests());
        $this->assertTrue($this->complete($callback));
    }

    public function test_changed_environment_or_callback_context_is_rejected()
    {
        foreach ([
            function () {
                $this->broker_base_url = "https://other-broker.example";
            },
            function () {
                $this->client_id = "oc_01jw7j4azge008000000000003";
            },
            function () {
                $this->admin_base_url = "https://other-merchant.example/wp-admin/";
            },
        ] as $change) {
            $this->broker_base_url = AHPC_Fake_OAuth_Broker::BASE_URL;
            $this->client_id = self::CLIENT_ID;
            $this->admin_base_url = self::ADMIN_BASE_URL;
            $this->start();
            $callback = $this->broker->approve();
            $change();

            $this->assertSame("ahpc_oauth_context_changed", $this->complete($callback)->get_error_code());
            $this->assertSame([], $this->broker->completion_requests());
        }
    }

    public function test_an_expired_attempt_is_discarded_without_redemption()
    {
        $this->start();
        $callback = $this->broker->approve();
        $attempt = $this->attempt();
        $attempt["expires_at"] = time() - 1;
        update_option(Plugin::OPTION_OAUTH_ATTEMPT, $attempt, false);

        $this->assertSame("ahpc_oauth_expired_state", $this->complete($callback)->get_error_code());
        $this->assertNull($this->attempt());
        $this->assertSame([], $this->broker->completion_requests());
    }

    public function test_the_attempt_expires_with_the_broker_when_that_is_sooner()
    {
        $this->broker->queue[] = static function ($body) {
            return AHPC_Fake_OAuth_Broker::json(201, [
                "protocol_version" => 2,
                "transaction_id" => "obt_0123456789abcdef01234567",
                "authorization_url" => AHPC_Fake_OAuth_Broker::authorization_url($body["client_id"], $body["scope"], "ref_opaque"),
                "expires_at" => gmdate("Y-m-d\\TH:i:s\\Z", time() + 60),
            ]);
        };
        $this->start();
        $this->assertLessThanOrEqual(time() + 60, $this->attempt()["expires_at"]);

        $this->broker->queue[] = static function ($body) {
            return AHPC_Fake_OAuth_Broker::json(201, [
                "protocol_version" => 2,
                "transaction_id" => "obt_0123456789abcdef01234567",
                "authorization_url" => AHPC_Fake_OAuth_Broker::authorization_url($body["client_id"], $body["scope"], "ref_opaque"),
                "expires_at" => "2999-01-01T00:00:00+01:00",
            ]);
        };
        $this->start();
        $this->assertLessThanOrEqual(time() + Plugin::OAUTH_ATTEMPT_TTL, $this->attempt()["expires_at"]);
    }

    public function test_settings_page_discards_an_expired_attempt_and_never_renders_secrets()
    {
        $this->start();
        $attempt = $this->attempt();
        $request = $this->broker->requests[0]["body"];
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);

        ob_start();
        (new Plugin())->render_settings_page();
        $output = (string) ob_get_clean();
        foreach ([$attempt["completion_secret"], $request["client_state"], $attempt["transaction_id"], $request["completion_challenge"]] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertIsArray($this->attempt());

        $attempt["expires_at"] = time() - 1;
        update_option(Plugin::OPTION_OAUTH_ATTEMPT, $attempt, false);
        ob_start();
        (new Plugin())->render_settings_page();
        ob_end_clean();
        $this->assertNull($this->attempt());
    }

    public function test_only_the_newest_parallel_attempt_can_complete()
    {
        $this->start();
        $older = $this->broker->approve(null, ["listing_source_id" => self::SOURCE_B]);
        $this->start();
        $newer = $this->broker->approve(null, ["listing_source_id" => self::SOURCE_A]);

        $this->assertSame("ahpc_oauth_transaction_mismatch", $this->complete($older)->get_error_code());
        $this->assertTrue($this->complete($newer));
        $this->assertSame("ahpc_oauth_expired_state", $this->complete($older)->get_error_code());

        $this->assertCount(1, $this->broker->completion_requests());
        $this->assertSame(self::SOURCE_A, $this->settings()["listing_source_id"]);
    }

    public function test_a_code_from_another_transaction_or_a_wrong_secret_fails_and_requires_a_restart()
    {
        $this->store_connection();
        $this->start();
        $first = $this->broker->approve();
        $this->start();
        $second = $this->broker->approve();

        $crossed = array_merge($second, ["completion_code" => $first["completion_code"]]);
        $result = $this->complete($crossed);
        $this->assertSame("ahpc_oauth_broker_rejected", $result->get_error_code());
        $this->assertSame("invalid_completion_code", $result->get_error_data()["broker_error"]);
        // The attempt was redeemed once, so even the right code now needs a restart.
        $this->assertSame("ahpc_oauth_expired_state", $this->complete($second)->get_error_code());
        $this->assertCount(1, $this->broker->completion_requests());

        $this->start();
        $third = $this->broker->approve();
        $attempt = $this->attempt();
        $attempt["completion_secret"] = rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
        update_option(Plugin::OPTION_OAUTH_ATTEMPT, $attempt, false);
        $result = $this->complete($third);
        $this->assertSame("ahpc_oauth_broker_rejected", $result->get_error_code());
        $this->assertSame("invalid_completion_proof", $result->get_error_data()["broker_error"]);

        $this->assertSame(self::SOURCE_B, $this->settings()["listing_source_id"]);
        $this->assertSame(self::OLD_TOKEN, $this->settings()["access_token"]);
        $this->assertSame([], $this->backend_requests);
    }

    /**
     * @return array<string,array{0:callable}>
     */
    public function invalid_completion_results()
    {
        $result = static function (array $overrides = [], array $remove = []) {
            return static function ($body, $request, $broker) use ($overrides, $remove) {
                $transaction = $broker->transactions[$body["transaction_id"]];
                $response = array_merge([
                    "protocol_version" => 2,
                    "outcome" => "approved",
                    "transaction_id" => $body["transaction_id"],
                    "client_state" => $transaction["client_state"],
                    "listing_source_id" => self::SOURCE_A,
                    "access_token" => self::TOKEN,
                    "token_type" => "Bearer",
                    "scope" => Plugin::OAUTH_SCOPE,
                ], $overrides);
                foreach ($remove as $key) {
                    unset($response[$key]);
                }
                return AHPC_Fake_OAuth_Broker::json(200, $response);
            };
        };

        return [
            "other transaction" => [$result(["transaction_id" => "obt_ffffffffffffffffffffffff"])],
            "other state" => [$result(["client_state" => str_repeat("B", 43)])],
            "wrong version" => [$result(["protocol_version" => 1])],
            "unknown outcome" => [$result(["outcome" => "pending"])],
            "invalid source" => [$result(["listing_source_id" => "ls_" . str_repeat("0", 26)])],
            "shop UUID source" => [$result(["listing_source_id" => "0190f7a2-2bf0-7000-8000-000000000001"])],
            "token with whitespace" => [$result(["access_token" => " " . self::TOKEN])],
            "empty token" => [$result(["access_token" => ""])],
            "MAC token" => [$result(["token_type" => "MAC"])],
            "missing scope" => [$result(["scope" => "product-listings:write listing-sources:write"])],
            "broader scope" => [$result(["scope" => Plugin::OAUTH_SCOPE . " admin"])],
            "missing token" => [$result([], ["access_token"])],
            "missing source" => [$result([], ["listing_source_id"])],
            "expiring token" => [$result(["expires_in" => 3600])],
            "refresh token" => [$result(["refresh_token" => "refresh"])],
            "resource metadata" => [$result(["resource" => "https://api.aura-historia.com"])],
            "denied with credentials" => [$result(["outcome" => "denied"])],
            "not JSON" => [static function () {
                return new Response(200, ["Content-Type" => "application/json"], "{");
            }],
            "server error" => [static function () {
                return AHPC_Fake_OAuth_Broker::json(500, ["error" => "server_error"]);
            }],
            "consumed" => [static function () {
                return AHPC_Fake_OAuth_Broker::json(409, ["error" => "transaction_consumed"]);
            }],
            "pending" => [static function () {
                return AHPC_Fake_OAuth_Broker::json(409, ["error" => "transaction_pending"]);
            }],
            "expired" => [static function () {
                return AHPC_Fake_OAuth_Broker::json(410, ["error" => "transaction_expired"]);
            }],
            "redirect" => [static function () {
                return new Response(307, ["Location" => "https://evil.example/collect"]);
            }],
            "timeout" => [static function () {
                return new ConnectException("timed out", new Request("POST", "https://broker.example"));
            }],
        ];
    }

    /**
     * @dataProvider invalid_completion_results
     */
    public function test_an_invalid_or_unsuccessful_result_preserves_the_existing_connection($response)
    {
        $this->store_connection();
        $this->start();
        $callback = $this->broker->approve();
        $broker = $this->broker;
        $this->broker->queue[] = static function ($body, $request) use ($response, $broker) {
            return $response($body, $request, $broker);
        };

        $result = $this->complete($callback);

        $this->assertWPError($result);
        $data = $result->get_error_data();
        $this->assertTrue(!is_array($data) || empty($data["connection_saved"]));
        $this->assertSame(self::SOURCE_B, $this->settings()["listing_source_id"]);
        $this->assertSame(self::OLD_TOKEN, $this->settings()["access_token"]);
        $this->assertSame([], $this->backend_requests);
        // An ambiguous or failed redemption is never repeated.
        $this->assertNull($this->attempt());
        $this->assertSame("ahpc_oauth_expired_state", $this->complete($callback)->get_error_code());
        $this->assertCount(1, $this->broker->completion_requests());
        // Initiation and one completion: a redirect is never followed.
        $this->assertCount(2, $this->broker->requests);
        foreach ([$result->get_error_message(), wp_json_encode($result->get_error_data())] as $text) {
            $this->assertStringNotContainsString($callback["completion_code"], $text);
            $this->assertStringNotContainsString(self::TOKEN, $text);
            $this->assertStringNotContainsString("evil.example", $text);
        }
    }

    public function test_denial_and_broker_failure_outcomes_keep_the_existing_connection()
    {
        $this->store_connection();
        $this->start();
        $this->assertSame("ahpc_oauth_authorization_denied", $this->complete($this->broker->deny())->get_error_code());

        $this->start();
        $failed = $this->broker->approve(null, ["outcome" => "failed", "error" => "token_exchange_failed"]);
        $result = $this->complete($failed);
        $this->assertSame("ahpc_oauth_authorization_failed", $result->get_error_code());
        $this->assertStringContainsString("token_exchange_failed", $result->get_error_message());

        $this->start();
        $location = $this->run_callback($this->callback_query($this->broker->deny()));
        $this->assertSame(self::SETTINGS_URL . "&ahpc_oauth=failed", $location);
        $this->assertStringContainsString("not approved", get_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR));

        $this->assertSame(self::SOURCE_B, $this->settings()["listing_source_id"]);
        $this->assertSame(self::OLD_TOKEN, $this->settings()["access_token"]);
        $this->assertSame([], $this->backend_requests);
    }

    public function test_replayed_callbacks_never_redeem_twice_or_repeat_side_effects()
    {
        $this->start();
        $callback = $this->broker->approve();
        $this->assertSame(self::SETTINGS_URL . "&ahpc_oauth=connected", $this->run_callback($this->callback_query($callback)));
        $backend_requests = count($this->backend_requests);
        $settings = $this->settings();

        $this->assertSame(self::SETTINGS_URL . "&ahpc_oauth=rejected", $this->run_callback($this->callback_query($callback)));
        $this->assertSame("ahpc_oauth_expired_state", $this->complete($callback)->get_error_code());

        $this->assertCount(1, $this->broker->completion_requests());
        $this->assertCount($backend_requests, $this->backend_requests);
        $this->assertSame($settings, $this->settings());
        $this->assertSame(1, $this->count_initial_backfill_actions(self::SOURCE_A));
        // The replay is reported once; no error stays on the connected store.
        $this->assertStringContainsString("has expired", $this->callback_notice());
        $this->assertFalse(get_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR, false));
    }

    public function test_a_rejected_callback_notice_is_shown_once_without_starting_oauth()
    {
        $this->store_connection("", "");
        $this->assertSame(self::SETTINGS_URL . "&ahpc_oauth=rejected", $this->run_callback("page=aura-historia-partner-connect&error=access_denied"));
        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);

        $_GET = ["page" => Plugin::PAGE_SLUG, "ahpc_oauth" => "rejected"];
        ob_start();
        (new Plugin())->render_settings_page();
        $first = (string) ob_get_clean();
        ob_start();
        (new Plugin())->render_settings_page();
        $second = (string) ob_get_clean();
        $_GET = ["page" => Plugin::PAGE_SLUG];
        ob_start();
        (new Plugin())->render_settings_page();
        $later = (string) ob_get_clean();

        $this->assertStringContainsString("no longer accepts", $first);
        $this->assertStringNotContainsString("ahpcOauthAutoStart", $first);
        $this->assertStringNotContainsString("no longer accepts", $second);
        // Nothing was stored, so a later visit may start OAuth again.
        $this->assertStringContainsString("ahpcOauthAutoStart", $later);
    }

    public function test_a_simultaneous_callback_sees_the_claim_and_does_not_redeem()
    {
        $this->start();
        $callback = $this->broker->approve();
        $nested = null;
        $this->broker->before_completion = function () use ($callback, &$nested) {
            $this->broker->before_completion = null;
            $this->assertSame(Plugin::OAUTH_PHASE_REDEEMING, $this->attempt()["phase"]);
            $nested = $this->complete($callback);
        };

        $this->assertTrue($this->complete($callback));

        $this->assertSame("ahpc_oauth_attempt_used", $nested->get_error_code());
        $this->assertCount(1, $this->broker->completion_requests());
    }

    public function test_a_stalled_lock_is_recovered_after_it_expires()
    {
        update_option(Plugin::OPTION_OAUTH_LOCK, wp_json_encode(["token" => "crashed", "until" => time() - 1]), false);

        $this->start();
        $this->assertTrue($this->complete($this->broker->approve()));
        $this->assertFalse(Option_Lease::read(Plugin::OPTION_OAUTH_LOCK, false));
    }

    public function test_a_completion_that_lost_its_lease_cannot_overwrite_a_newer_connection()
    {
        $this->store_connection();
        $this->start();
        $callback = $this->broker->approve();
        $this->broker->before_completion = function () {
            // A successor took over the expired lease and saved its own connection.
            $successor = new Option_Lease(Plugin::OPTION_OAUTH_LOCK);
            $this->assertTrue($successor->seize(60));
            update_option(Webhook_Manager::OPTION_SETTINGS, [
                "listing_source_id" => self::SOURCE_B,
                "access_token" => "aurahistoria_newer_connection_token",
                "secret" => "test-secret",
            ], false);
        };

        $result = $this->complete($callback);

        $this->assertSame("ahpc_oauth_not_saved", $result->get_error_code());
        $this->assertSame("aurahistoria_newer_connection_token", $this->settings()["access_token"]);
        $this->assertSame([], $this->backend_requests);
        $this->assertSame("ahpc_oauth_attempt_used", $this->complete($callback)->get_error_code());
    }

    public function test_losing_the_lease_while_replacing_keeps_the_previous_connection_state()
    {
        $this->store_connection();
        update_option(Plugin::OPTION_INITIAL_BACKFILL_PENDING_SOURCE, self::SOURCE_B, false);
        $snapshot = ["idempotency_key" => "previous-key", "payloads" => [["sourceListingId" => "42"]]];
        update_option(Product_Backfill::OPTION_BATCH, $snapshot, false);
        $this->start();
        $callback = $this->broker->approve();
        $seized = false;
        // A successor takes over the lease after the completion checked it,
        // while the previous source's shipping is being stopped.
        $seize = function () use (&$seized) {
            if (!$seized) {
                $seized = (new Option_Lease(Plugin::OPTION_OAUTH_LOCK))->seize(60);
            }
        };
        add_action("add_option_" . Shipping_Sync::OPTION_BINDING, $seize);
        add_action("update_option_" . Shipping_Sync::OPTION_BINDING, $seize);

        $result = $this->complete($callback);

        $this->assertTrue($seized);
        $this->assertSame("ahpc_oauth_not_saved", $result->get_error_code());
        $this->assertSame(self::SOURCE_B, $this->settings()["listing_source_id"]);
        $this->assertSame(self::OLD_TOKEN, $this->settings()["access_token"]);
        $this->assertSame(self::SOURCE_B, get_option(Plugin::OPTION_INITIAL_BACKFILL_PENDING_SOURCE));
        $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
        $this->assertSame([], $this->backend_requests);
    }

    public function test_follow_up_sync_failure_keeps_the_saved_connection_without_a_second_redemption()
    {
        $this->start();
        $callback = $this->broker->approve();
        $this->set_backend_responses([new Response(503, ["Content-Type" => "application/problem+json"], "{}")]);

        $location = $this->run_callback($this->callback_query($callback));

        $this->assertSame(self::SETTINGS_URL . "&ahpc_oauth=saved", $location);
        $this->assertFalse(get_option(Webhook_Manager::OPTION_LAST_OAUTH_ERROR, false));
        $this->assertNotSame("", get_option(Webhook_Manager::OPTION_LAST_SYNC_ERROR, ""));
        $this->assertSame(self::SOURCE_A, $this->settings()["listing_source_id"]);
        $this->assertSame(self::SOURCE_A, get_option(Plugin::OPTION_INITIAL_BACKFILL_PENDING_SOURCE));
        $this->assertNull($this->attempt());

        $this->assertSame("ahpc_oauth_expired_state", $this->complete($callback)->get_error_code());
        $this->assertCount(1, $this->broker->completion_requests());

        update_option(Webhook_Manager::OPTION_NEEDS_SYNC, "no", false);
        $_GET = ["ahpc_oauth" => "saved"];
        ob_start();
        (new Plugin())->render_settings_page();
        $output = (string) ob_get_clean();
        $this->assertStringContainsString("you do not need to reconnect", $output);
    }

    public function test_same_source_reconnect_keeps_a_pending_backfill_snapshot()
    {
        $this->start();
        $this->assertTrue($this->complete($this->broker->approve()));
        $snapshot = ["idempotency_key" => "original-key", "payloads" => [["sourceListingId" => "42"]]];
        update_option(Product_Backfill::OPTION_BATCH, $snapshot, false);

        $this->set_backend_responses([new Response(204)]);
        $this->start();
        $this->assertTrue($this->complete($this->broker->approve()));

        $this->assertSame($snapshot, get_option(Product_Backfill::OPTION_BATCH));
    }

    public function test_deactivation_and_uninstall_remove_the_attempt_and_its_secret()
    {
        $this->start();
        Plugin::deactivate();
        $this->assertNull($this->attempt());

        $this->start();
        update_option(Plugin::OPTION_OAUTH_LOCK, wp_json_encode(["token" => "x", "until" => time() + 60]), false);
        (new Webhook_Manager())->delete_webhooks();
        $this->assertNull($this->attempt());
        $this->assertFalse(Option_Lease::read(Plugin::OPTION_OAUTH_LOCK, false));
    }

    public function test_the_retired_handshake_is_gone()
    {
        $this->assertFalse(method_exists(Plugin::class, "create_oauth_authorization_url"));
        $this->assertFalse(method_exists(\AuraHistoria\PartnerConnect\Backend_Api_Client::class, "oauth_token_by_third_party_code"));
    }
}
