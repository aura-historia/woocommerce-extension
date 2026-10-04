<?php
/**
 * Backend API client.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

use AuraHistoria\PartnerConnect\InternalApi\Api\ListingSourcesApi;
use AuraHistoria\PartnerConnect\InternalApi\Api\OAuthApi;
use AuraHistoria\PartnerConnect\InternalApi\Api\ProductListingsApi;
use AuraHistoria\PartnerConnect\InternalApi\ApiException;
use AuraHistoria\PartnerConnect\InternalApi\Configuration;
use AuraHistoria\PartnerConnect\InternalApi\Model\AsyncProductListingBatchReport;
use AuraHistoria\PartnerConnect\InternalApi\Model\OAuthTokenResponseData;
use AuraHistoria\PartnerConnect\InternalApi\Model\PutWoocommerceListingSourceIngestionConfigurationData;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use WP_Error;

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Guzzle-backed client for the typed internal backend API.
 */
class Backend_Api_Client
{
    private $base_url = "";

    /**
     * @param string|null $base_url Backend base URL override.
     */
    public function __construct($base_url = null)
    {
        $resolved = null === $base_url
            ? Webhook_Manager::get_backend_base_url()
            : esc_url_raw(trim((string) $base_url), ["http", "https"]);
        $this->base_url = $resolved ? untrailingslashit($resolved) : "";
    }

    /**
     * Replaces the write-only WooCommerce configuration before enabling delivery.
     *
     * @param string      $listing_source_id ListingSource TypeID.
     * @param string      $access_token      Aura bearer token.
     * @param string      $secret            Webhook signing secret.
     * @param string|null $currency          Supported currency or null to clear.
     * @param string|null $language          Supported language or null to clear.
     * @return true|WP_Error
     */
    public function put_woocommerce_listing_source_ingestion_configuration(
        $listing_source_id,
        $access_token,
        $secret,
        $currency,
        $language
    ) {
        $listing_source_id = Webhook_Manager::normalize_listing_source_id($listing_source_id);
        if ("" === $this->base_url || !Webhook_Manager::is_valid_listing_source_id($listing_source_id)) {
            return $this->invalid_listing_source_error();
        }
        if (!$this->is_runtime_available()) {
            return $this->unavailable_error();
        }

        $body = new PutWoocommerceListingSourceIngestionConfigurationData();
        $body->setWebhookSecret($secret);
        $body->setCurrency($currency);
        $body->setLanguage($language);

        try {
            list(, $status) = $this->create_listing_sources_api($access_token)
                ->putWoocommerceListingSourceIngestionConfigurationWithHttpInfo($listing_source_id, $body);
            if (201 === $status || 204 === $status) {
                return true;
            }
        } catch (ApiException $exception) {
            return $this->translate_api_exception($exception);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid_request_error();
        } catch (\Throwable $exception) {
            return $this->transport_error();
        }

        return new WP_Error("ahpc_backend_invalid_response", __("The backend returned an unexpected configuration response.", "aura-historia-partner-connect"));
    }

    /**
     * Submits an unchanged ordered logical batch under a caller-owned retry key.
     * A 202 confirms queue admission only, not listing creation. Never compact or
     * reorder entries when retrying an uncertain batch with the same key.
     *
     * @param string $listing_source_id ListingSource TypeID.
     * @param string $access_token      Aura bearer token.
     * @param array  $payloads          Ordered create objects (up to 100).
     * @param string $idempotency_key    Stable key for retries.
     * @return array{accepted_count:int,failures:array,submission_id:string}|WP_Error
     */
    public function post_async_partner_product_listings($listing_source_id, $access_token, array $payloads, $idempotency_key)
    {
        $listing_source_id = Webhook_Manager::normalize_listing_source_id($listing_source_id);
        if ("" === $this->base_url || !Webhook_Manager::is_valid_listing_source_id($listing_source_id)) {
            return $this->invalid_listing_source_error();
        }
        if (!is_string($idempotency_key) || !preg_match('/^[\x21-\x2B\x2D-\x7E]{1,128}$/D', $idempotency_key) || count($payloads) > 100 || (!empty($payloads) && array_keys($payloads) !== range(0, count($payloads) - 1))) {
            return $this->invalid_request_error();
        }
        if (!$this->is_runtime_available()) {
            return $this->unavailable_error();
        }

        try {
            list($response, $status) = $this->create_product_listings_api($access_token)
                ->postAsyncPartnerProductListingsWithHttpInfo($listing_source_id, $payloads, $idempotency_key);
        } catch (ApiException $exception) {
            $status = (int) $exception->getCode();
            $body = json_decode((string) $exception->getResponseBody(), true);
            if (in_array($status, [400, 413, 500, 503], true) && is_array($body)) {
                $report = $this->parse_batch_report($body, $payloads);
                if (null !== $report && 0 === $report["accepted_count"]) {
                    return new WP_Error(
                        "ahpc_backend_batch_rejected",
                        __("The backend did not confirm admission of this batch.", "aura-historia-partner-connect"),
                        array_merge($report, ["response_code" => $status, "retryable" => $this->has_retryable_failures($report["failures"])]),
                    );
                }
            }
            return $this->translate_api_exception($exception);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid_request_error();
        } catch (\Throwable $exception) {
            return $this->transport_error();
        }

        if (202 !== $status || !$response instanceof AsyncProductListingBatchReport) {
            return new WP_Error("ahpc_backend_invalid_response", __("The backend returned an unexpected batch response.", "aura-historia-partner-connect"), ["retryable" => true]);
        }
        $report = $this->parse_batch_report([
            "submissionId" => $response->getSubmissionId(),
            "acceptedCount" => $response->getAcceptedCount(),
            "failures" => array_map(static function ($failure) {
                return [
                    "index" => $failure->getIndex(),
                    "sourceListingId" => $failure->getSourceListingId(),
                    "error" => $failure->getError(),
                    "retryable" => $failure->getRetryable(),
                ];
            }, (array) $response->getFailures()),
        ], $payloads);
        return null !== $report ? $report : new WP_Error("ahpc_backend_invalid_response", __("The backend returned an unexpected batch response.", "aura-historia-partner-connect"), ["retryable" => true]);
    }

    /**
     * @param string $third_party_exchange_code UUIDv7 single-use code.
     * @return OAuthTokenResponseData|WP_Error
     */
    public function oauth_token_by_third_party_code($third_party_exchange_code)
    {
        $code = is_string($third_party_exchange_code) ? trim($third_party_exchange_code) : "";
        if ("" === $this->base_url || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $code)) {
            return new WP_Error("ahpc_backend_invalid_oauth_exchange_code", __("The OAuth exchange code returned by Aura Historia is invalid or expired.", "aura-historia-partner-connect"));
        }
        if (!$this->is_runtime_available()) {
            return $this->unavailable_error();
        }
        try {
            $response = $this->create_oauth_api()->oauthTokenByThirdPartyCode($code);
        } catch (ApiException $exception) {
            return $this->translate_api_exception($exception);
        } catch (\InvalidArgumentException $exception) {
            return $this->invalid_request_error();
        } catch (\Throwable $exception) {
            return $this->transport_error();
        }
        return $response instanceof OAuthTokenResponseData ? $response : new WP_Error("ahpc_backend_invalid_response", __("Aura Historia returned an unexpected OAuth token response.", "aura-historia-partner-connect"));
    }

    /**
     * Only retain safe report metadata. Echoed source IDs are included only
     * when they match the original item at that index; never include arbitrary
     * backend text or unknown response fields in error data.
     *
     * @param mixed $body     Decoded report.
     * @param array $payloads Original ordered batch.
     * @return array|null
     */
    private function parse_batch_report($body, array $payloads)
    {
        $count = count($payloads);
        if (!is_array($body) || !isset($body["submissionId"], $body["acceptedCount"], $body["failures"]) || !is_string($body["submissionId"]) || "" === $body["submissionId"] || strlen($body["submissionId"]) > 512 || preg_match('/[\x00-\x1F\x7F]/', $body["submissionId"]) || !is_int($body["acceptedCount"]) || !is_array($body["failures"]) || (!empty($body["failures"]) && array_keys($body["failures"]) !== range(0, count($body["failures"]) - 1)) || $body["acceptedCount"] < 0 || $body["acceptedCount"] + count($body["failures"]) !== $count) {
            return null;
        }
        $failures = [];
        $seen = [];
        foreach ($body["failures"] as $failure) {
            if (!is_array($failure) || !isset($failure["index"], $failure["error"], $failure["retryable"]) || !is_int($failure["index"]) || $failure["index"] < 0 || $failure["index"] >= $count || isset($seen[$failure["index"]]) || !is_string($failure["error"]) || !preg_match('/^[A-Z][A-Z0-9_]{0,100}$/D', $failure["error"]) || !is_bool($failure["retryable"])) {
                return null;
            }
            $seen[$failure["index"]] = true;
            $safe_failure = ["index" => $failure["index"], "error" => $failure["error"], "retryable" => $failure["retryable"]];
            $original = $payloads[$failure["index"]];
            if (
                isset($failure["sourceListingId"]) &&
                is_string($failure["sourceListingId"]) &&
                is_array($original) &&
                isset($original["sourceListingId"]) &&
                is_string($original["sourceListingId"]) &&
                "" !== $failure["sourceListingId"] &&
                strlen($failure["sourceListingId"]) <= 512 &&
                !preg_match('/[\x00-\x1F\x7F]/', $failure["sourceListingId"]) &&
                $failure["sourceListingId"] === $original["sourceListingId"]
            ) {
                $safe_failure["source_listing_id"] = $failure["sourceListingId"];
            }
            $failures[] = $safe_failure;
        }
        return ["accepted_count" => $body["acceptedCount"], "failures" => $failures, "submission_id" => $body["submissionId"]];
    }

    private function has_retryable_failures(array $failures)
    {
        foreach ($failures as $failure) {
            if ($failure["retryable"]) {
                return true;
            }
        }
        return false;
    }

    private function is_runtime_available()
    {
        return class_exists(ListingSourcesApi::class) && class_exists(ProductListingsApi::class) && class_exists(OAuthApi::class) && class_exists(GuzzleClient::class);
    }

    private function create_listing_sources_api($token)
    {
        return new ListingSourcesApi($this->create_http_client(), $this->create_configuration($token));
    }

    private function create_product_listings_api($token)
    {
        return new ProductListingsApi($this->create_http_client(), $this->create_configuration($token));
    }

    private function create_oauth_api()
    {
        return new OAuthApi($this->create_http_client(), $this->create_configuration());
    }

    private function create_configuration($token = "")
    {
        $configuration = new Configuration();
        $configuration->setHost($this->base_url);
        if ("" !== $token) {
            $configuration->setAccessToken($token);
        }
        return $configuration;
    }

    private function create_http_client()
    {
        $client = new GuzzleClient(["timeout" => 15, "allow_redirects" => false, "http_errors" => true, "version" => "1.1"]);
        $filtered = apply_filters("ahpc_backend_guzzle_client", $client, $this->base_url);
        return $filtered instanceof ClientInterface ? $filtered : $client;
    }

    private function translate_api_exception(ApiException $exception)
    {
        $status = (int) $exception->getCode();
        $body = json_decode((string) $exception->getResponseBody(), true);
        // Use only the protocol's bounded error identifier; detail and exception
        // messages may contain request URLs, bearer tokens, or webhook secrets.
        $error_code = is_array($body) && isset($body["error"]) && is_string($body["error"]) && preg_match('/^[A-Z][A-Z0-9_]{0,100}$/D', $body["error"]) ? $body["error"] : "";
        $data = ["response_code" => $status, "retryable" => 0 === $status || 408 === $status || 429 === $status || ($status >= 500 && $status <= 599)];
        if ("" !== $error_code) {
            $data["backend_error"] = $error_code;
        }
        return new WP_Error($status > 0 ? "ahpc_backend_http_error" : "ahpc_backend_request_failed", __("The backend request failed. Check the connection and try again.", "aura-historia-partner-connect"), $data);
    }

    private function transport_error()
    {
        return new WP_Error("ahpc_backend_request_failed", __("The backend response could not be confirmed. Retry the unchanged request.", "aura-historia-partner-connect"), ["retryable" => true]);
    }

    private function invalid_listing_source_error()
    {
        return new WP_Error("ahpc_backend_invalid_url", __("The backend listing source URL could not be built from the configured ListingSource ID.", "aura-historia-partner-connect"));
    }

    private function unavailable_error()
    {
        return new WP_Error("ahpc_backend_client_unavailable", __("The plugin's generated backend API client dependencies are not available.", "aura-historia-partner-connect"));
    }

    private function invalid_request_error()
    {
        return new WP_Error("ahpc_backend_invalid_request", __("The backend request is invalid.", "aura-historia-partner-connect"));
    }
}
