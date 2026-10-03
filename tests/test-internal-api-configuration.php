<?php
/**
 * Integration tests for the generated internal API configuration.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Backend_Api_Client;
use AuraHistoria\PartnerConnect\InternalApi\Configuration;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

/**
 * Tests WordPress-safe file path handling in the generated internal API client.
 */
class Test_AHPC_Internal_Api_Configuration extends WP_UnitTestCase
{
    /**
     * Optional upload directory override injected through the `upload_dir` filter.
     *
     * @var array<string,string>|null
     */
    protected $upload_dir_override = null;

    protected $backend_client_filter = null;

    /**
     * Test teardown.
     *
     * @return void
     */
    public function tearDown(): void
    {
        remove_filter("upload_dir", [$this, "filter_upload_dir"]);
        if (null !== $this->backend_client_filter) {
            remove_filter("ahpc_backend_guzzle_client", $this->backend_client_filter);
            $this->backend_client_filter = null;
        }
        $this->upload_dir_override = null;

        parent::tearDown();
    }

    /**
     * Applies an upload directory override when a test requests one.
     *
     * @param array<string,string> $uploads Current uploads data.
     * @return array<string,string>
     */
    public function filter_upload_dir($uploads)
    {
        if (!is_array($this->upload_dir_override)) {
            return $uploads;
        }

        return array_merge($uploads, $this->upload_dir_override);
    }

    /**
     * Verifies the generated client uses a plugin-managed uploads directory by default.
     *
     * @return void
     */
    public function test_temp_folder_defaults_to_plugin_uploads_directory()
    {
        $configuration = new Configuration();
        $uploads = wp_upload_dir();
        $expected_base = wp_normalize_path(
            trailingslashit($uploads["basedir"]) .
                "aura-historia-partner-connect/internal-api",
        );

        $this->assertEmpty($uploads["error"]);
        $this->assertStringStartsWith(
            $expected_base,
            wp_normalize_path($configuration->getTempFolderPath()),
        );
        $this->assertDirectoryExists($configuration->getTempFolderPath());
    }

    /**
     * Verifies requested temp folders are still constrained to the plugin uploads area.
     *
     * @return void
     */
    public function test_temp_folder_path_is_scoped_to_plugin_uploads_directory()
    {
        $configuration = new Configuration();
        $configuration->setTempFolderPath("../../outside/location");

        $uploads = wp_upload_dir();
        $expected_base = wp_normalize_path(
            trailingslashit($uploads["basedir"]) .
                "aura-historia-partner-connect/internal-api",
        );

        $this->assertEmpty($uploads["error"]);
        $this->assertStringStartsWith(
            $expected_base,
            wp_normalize_path($configuration->getTempFolderPath()),
        );
        $this->assertDirectoryExists($configuration->getTempFolderPath());
    }

    /**
     * Verifies debug output paths are scoped to the plugin uploads area.
     *
     * @return void
     */
    public function test_debug_file_path_is_scoped_to_plugin_uploads_directory()
    {
        $configuration = new Configuration();
        $configuration->setDebugFile("../../custom.log");

        $uploads = wp_upload_dir();
        $expected_prefix = wp_normalize_path(
            trailingslashit($uploads["basedir"]) .
                "aura-historia-partner-connect/internal-api/",
        );
        $actual = wp_normalize_path($configuration->getDebugFile());

        $this->assertEmpty($uploads["error"]);
        $this->assertStringStartsWith($expected_prefix, $actual);
        $this->assertStringEndsWith("/custom.log", $actual);
    }

    /**
     * Verifies no hardcoded uploads path is derived when WordPress reports an error.
     *
     * @return void
     */
    public function test_temp_folder_is_empty_when_upload_directory_is_unavailable()
    {
        $this->upload_dir_override = [
            "path" => "",
            "basedir" => "",
            "url" => "",
            "baseurl" => "",
            "subdir" => "",
            "error" => "Uploads unavailable",
        ];
        add_filter("upload_dir", [$this, "filter_upload_dir"]);

        $configuration = new Configuration();
        $configuration->setDebugFile("../../custom.log");

        $this->assertSame("", $configuration->getTempFolderPath());
        $this->assertSame("php://output", $configuration->getDebugFile());
    }

    /**
     * Injects only mocked backend responses; no outbound HTTP is performed.
     *
     * @param array $responses Mock Guzzle responses.
     * @param array $history  Captured requests.
     * @return void
     */
    private function mock_backend(array $responses, array &$history)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new Client(["handler" => $stack]);
        $this->backend_client_filter = static function () use ($client) {
            return $client;
        };
        add_filter("ahpc_backend_guzzle_client", $this->backend_client_filter);
    }

    public function test_woocommerce_configuration_put_accepts_created_and_no_content()
    {
        $history = [];
        $this->mock_backend([new Response(201), new Response(204)], $history);
        $client = new Backend_Api_Client("https://example.com");
        $id = "ls_01jw7j4azge008000000000003";
        $this->assertTrue($client->put_woocommerce_listing_source_ingestion_configuration($id, "test-token", "test-secret", "EUR", "de"));
        $this->assertTrue($client->put_woocommerce_listing_source_ingestion_configuration($id, "test-token", "test-secret", null, null));
        $this->assertSame("/api/v1/listing-sources/{$id}/ingestion-configurations/woocommerce", $history[0]["request"]->getUri()->getPath());
        $this->assertSame("Bearer test-token", $history[0]["request"]->getHeaderLine("Authorization"));
        $this->assertSame(["webhookSecret" => "test-secret", "currency" => "EUR", "language" => "de"], json_decode((string) $history[0]["request"]->getBody(), true));
        $this->assertSame(["webhookSecret" => "test-secret", "currency" => null, "language" => null], json_decode((string) $history[1]["request"]->getBody(), true));
    }

    public function test_async_batch_reports_preserve_original_indices_and_retry_key()
    {
        $history = [];
        $report = ["submissionId" => "plis1:submission.42", "acceptedCount" => 1, "failures" => [["index" => 1, "sourceListingId" => "sku-2", "error" => "ENQUEUE_UNCONFIRMED", "retryable" => true]]];
        $this->mock_backend([new Response(202, ["Content-Type" => "application/json"], wp_json_encode($report)), new Response(202, ["Content-Type" => "application/json"], wp_json_encode(["submissionId" => "plis1_empty", "acceptedCount" => 0, "failures" => []]))], $history);
        $client = new Backend_Api_Client("https://example.com");
        $id = "ls_01jw7j4azge008000000000003";
        $payloads = [["sourceListingId" => "sku-1", "url" => "https://example.com/1", "images" => []], ["sourceListingId" => "sku-2", "url" => "https://example.com/2", "images" => []]];
        $this->assertSame(["accepted_count" => 1, "failures" => [["index" => 1, "error" => "ENQUEUE_UNCONFIRMED", "retryable" => true, "source_listing_id" => "sku-2"]], "submission_id" => "plis1:submission.42"], $client->post_async_partner_product_listings($id, "test-token", $payloads, "catalog-1"));
        $this->assertSame("/api/v1/listing-sources/{$id}/product-listings/async", $history[0]["request"]->getUri()->getPath());
        $this->assertSame("catalog-1", $history[0]["request"]->getHeaderLine("Idempotency-Key"));
        $this->assertSame($payloads, json_decode((string) $history[0]["request"]->getBody(), true));
        $this->assertSame("plis1_empty", $client->post_async_partner_product_listings($id, "test-token", [], "catalog-empty")["submission_id"]);
        $this->assertSame("[]", (string) $history[1]["request"]->getBody());
    }

    public function test_202_zero_admitted_failures_are_still_a_report()
    {
        $history = [];
        $this->mock_backend([new Response(202, ["Content-Type" => "application/json"], wp_json_encode([
            "submissionId" => "submission:validation.1",
            "acceptedCount" => 0,
            "failures" => [
                ["index" => 0, "error" => "BAD_BODY_VALUE", "retryable" => false],
                ["index" => 1, "sourceListingId" => "other-secret", "error" => "PRODUCT_LISTING_INGESTION_PAYLOAD_TOO_LARGE", "retryable" => false],
            ],
        ]))], $history);
        $client = new Backend_Api_Client("https://example.com");
        $result = $client->post_async_partner_product_listings(
            "ls_01jw7j4azge008000000000003",
            "token",
            [["sourceListingId" => "sku-1"], ["sourceListingId" => "sku-2"]],
            "key-202",
        );
        $this->assertSame([
            "accepted_count" => 0,
            "failures" => [
                ["index" => 0, "error" => "BAD_BODY_VALUE", "retryable" => false],
                ["index" => 1, "error" => "PRODUCT_LISTING_INGESTION_PAYLOAD_TOO_LARGE", "retryable" => false],
            ],
            "submission_id" => "submission:validation.1",
        ], $result);
        $this->assertCount(1, $history);
    }

    public function test_non_2xx_report_is_not_a_success_and_retains_retryability()
    {
        $history = [];
        $this->mock_backend([new Response(503, ["Content-Type" => "application/json"], wp_json_encode(["submissionId" => "plis1_uncertain", "acceptedCount" => 0, "failures" => [["index" => 0, "sourceListingId" => "one", "error" => "ENQUEUE_UNCONFIRMED", "retryable" => true]]]))], $history);
        $result = (new Backend_Api_Client("https://example.com"))->post_async_partner_product_listings("ls_01jw7j4azge008000000000003", "token", [["sourceListingId" => "one"]], "catalog-2");
        $this->assertWPError($result);
        $this->assertSame("ahpc_backend_batch_rejected", $result->get_error_code());
        $this->assertSame(["accepted_count" => 0, "failures" => [["index" => 0, "error" => "ENQUEUE_UNCONFIRMED", "retryable" => true, "source_listing_id" => "one"]], "submission_id" => "plis1_uncertain", "response_code" => 503, "retryable" => true], $result->get_error_data());
    }

    public function test_validation_report_and_problem_are_distinct_from_transport_failures()
    {
        $history = [];
        $this->mock_backend([
            new Response(400, ["Content-Type" => "application/json"], wp_json_encode(["submissionId" => "plis1_invalid", "acceptedCount" => 0, "failures" => [["index" => 0, "error" => "BAD_BODY_VALUE", "retryable" => false]]])),
            new Response(503, ["Content-Type" => "application/problem+json"], wp_json_encode(["status" => 503, "title" => "Unavailable", "error" => "PRODUCT_LISTING_TEMPORARILY_UNAVAILABLE", "detail" => "private text"])),
        ], $history);
        $client = new Backend_Api_Client("https://example.com");
        $id = "ls_01jw7j4azge008000000000003";
        $payloads = [["sourceListingId" => "one"]];
        $this->assertWPError($client->post_async_partner_product_listings($id, "token", $payloads, "invalid,key"));
        $this->assertCount(0, $history);
        $validation = $client->post_async_partner_product_listings($id, "token", $payloads, "key-1");
        $this->assertSame("ahpc_backend_batch_rejected", $validation->get_error_code());
        $this->assertFalse($validation->get_error_data()["retryable"]);
        $this->assertSame(400, $validation->get_error_data()["response_code"]);
        $this->assertSame([["index" => 0, "error" => "BAD_BODY_VALUE", "retryable" => false]], $validation->get_error_data()["failures"]);
        $problem = $client->post_async_partner_product_listings($id, "token", $payloads, "key-2");
        $this->assertSame("ahpc_backend_http_error", $problem->get_error_code());
        $this->assertTrue($problem->get_error_data()["retryable"]);
        $this->assertSame("PRODUCT_LISTING_TEMPORARILY_UNAVAILABLE", $problem->get_error_data()["backend_error"]);
        $this->assertStringNotContainsString("private text", $problem->get_error_message());
    }

    public function test_http_500_problem_is_retryable_but_http_500_batch_report_uses_failure_flags()
    {
        $history = [];
        $this->mock_backend([
            new Response(500, ["Content-Type" => "application/problem+json"], wp_json_encode(["status" => 500, "error" => "INTERNAL_SERVER_ERROR", "detail" => "private test-secret"])),
            new Response(500, ["Content-Type" => "application/json"], wp_json_encode([
                "submissionId" => "plis1_rejected",
                "acceptedCount" => 0,
                "failures" => [["index" => 0, "error" => "BAD_BODY_VALUE", "retryable" => false]],
                "detail" => "private test-secret",
            ])),
        ], $history);
        $client = new Backend_Api_Client("https://example.com");
        $id = "ls_01jw7j4azge008000000000003";
        $problem = $client->put_woocommerce_listing_source_ingestion_configuration($id, "test-token", "test-secret", "EUR", "de");
        $this->assertWPError($problem);
        $this->assertSame("ahpc_backend_http_error", $problem->get_error_code());
        $this->assertSame(["response_code" => 500, "retryable" => true, "backend_error" => "INTERNAL_SERVER_ERROR"], $problem->get_error_data());
        $this->assertStringNotContainsString("test-secret", $problem->get_error_message());

        $report = $client->post_async_partner_product_listings($id, "test-token", [["sourceListingId" => "one"]], "key-500");
        $this->assertWPError($report);
        $this->assertSame("ahpc_backend_batch_rejected", $report->get_error_code());
        $this->assertSame([
            "accepted_count" => 0,
            "failures" => [["index" => 0, "error" => "BAD_BODY_VALUE", "retryable" => false]],
            "submission_id" => "plis1_rejected",
            "response_code" => 500,
            "retryable" => false,
        ], $report->get_error_data());
        $this->assertStringNotContainsString("test-secret", $report->get_error_message());
        $this->assertCount(2, $history);
    }

    public function test_problem_and_transport_errors_do_not_expose_secrets()
    {
        $history = [];
        $this->mock_backend([new Response(400, ["Content-Type" => "application/problem+json"], wp_json_encode(["status" => 400, "title" => "Bad Request", "error" => "BAD_BODY_VALUE", "detail" => "test-secret"])), new ConnectException("test-token", new Request("POST", "https://example.com"))], $history);
        $client = new Backend_Api_Client("https://example.com");
        $id = "ls_01jw7j4azge008000000000003";
        $problem = $client->put_woocommerce_listing_source_ingestion_configuration($id, "test-token", "test-secret", "EUR", "de");
        $this->assertWPError($problem);
        $this->assertSame("BAD_BODY_VALUE", $problem->get_error_data()["backend_error"]);
        $this->assertStringNotContainsString("test-secret", $problem->get_error_message());
        $transport = $client->post_async_partner_product_listings($id, "test-token", [["sourceListingId" => "one"]], "catalog-3");
        $this->assertWPError($transport);
        $this->assertTrue($transport->get_error_data()["retryable"]);
        $this->assertStringNotContainsString("test-token", $transport->get_error_message());
    }

    public function test_oauth_exchange_requires_uuid_v7_independently_of_listing_source_id()
    {
        $history = [];
        $this->mock_backend([new Response(200, ["Content-Type" => "application/json"], wp_json_encode(["access_token" => "test-token", "token_type" => "BEARER", "scope" => "product-listings:write"]))], $history);
        $client = new Backend_Api_Client("https://example.com");
        $this->assertWPError($client->oauth_token_by_third_party_code("550e8400-e29b-41d4-a716-446655440000"));
        $this->assertWPError($client->oauth_token_by_third_party_code("ls_01jw7j4azge008000000000003"));
        $response = $client->oauth_token_by_third_party_code("01970f22-2bf0-7000-8000-000000000099");
        $this->assertSame("test-token", $response->getAccessToken());
        $this->assertCount(1, $history);
    }
}
