<?php
/**
 * Backend API client.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

use AuraHistoria\PartnerConnect\InternalApi\Api\ShopsApi;
use AuraHistoria\PartnerConnect\InternalApi\ApiException;
use AuraHistoria\PartnerConnect\InternalApi\Configuration;
use AuraHistoria\PartnerConnect\InternalApi\Model\ApiError;
use AuraHistoria\PartnerConnect\InternalApi\Model\GetShopData;
use AuraHistoria\PartnerConnect\InternalApi\Model\PatchShopData;
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
    /**
     * Plugin text domain.
     */
    const TEXT_DOMAIN = "aura-historia-partner-connect";

    /**
     * Backend base URL.
     *
     * @var string
     */
    private $base_url = "";

    /**
     * Constructor.
     *
     * @param string|null $base_url Backend base URL override.
     */
    public function __construct($base_url = null)
    {
        $resolved_base_url =
            null === $base_url
                ? Webhook_Manager::get_backend_base_url()
                : esc_url_raw(trim((string) $base_url), ["http", "https"]);

        $this->base_url = $resolved_base_url
            ? untrailingslashit($resolved_base_url)
            : "";
    }

    /**
     * Calls `PATCH /api/v1/shops/{shopId}` using the generated OpenAPI client.
     *
     * @param string        $shop_id      Shop UUID.
     * @param string        $api_key      Backend API key.
     * @param PatchShopData $request_body Typed request payload.
     * @return GetShopData|WP_Error
     */
    public function patch_shop_by_id(
        $shop_id,
        $api_key,
        PatchShopData $request_body,
    ) {
        $shop_id = Webhook_Manager::normalize_shop_id($shop_id);

        if (
            "" === $this->base_url ||
            !Webhook_Manager::is_valid_shop_id($shop_id)
        ) {
            return new WP_Error(
                "ahpc_backend_invalid_url",
                __(
                    "The backend shop URL could not be built from the configured Shop ID.",
                    self::TEXT_DOMAIN,
                ),
            );
        }

        if (!$this->is_runtime_available()) {
            return new WP_Error(
                "ahpc_backend_client_unavailable",
                __(
                    "The plugin's generated backend API client dependencies are not available.",
                    self::TEXT_DOMAIN,
                ),
            );
        }

        try {
            $response = $this->create_shops_api($api_key)->patchShopById(
                $shop_id,
                $request_body,
            );
        } catch (ApiException $exception) {
            return $this->translate_api_exception($exception);
        } catch (\InvalidArgumentException $exception) {
            return new WP_Error(
                "ahpc_backend_invalid_request",
                $this->sanitize_error_fragment($exception->getMessage()),
            );
        } catch (\Throwable $throwable) {
            return new WP_Error(
                "ahpc_backend_request_failed",
                $this->sanitize_error_fragment($throwable->getMessage()),
            );
        }

        if (!$response instanceof GetShopData) {
            return new WP_Error(
                "ahpc_backend_invalid_response",
                __(
                    "The backend returned an unexpected response shape.",
                    self::TEXT_DOMAIN,
                ),
            );
        }

        return $response;
    }

    /**
     * Performs a lightweight connection check with an empty JSON object body.
     *
     * @param string $shop_id Shop UUID.
     * @param string $api_key Backend API key.
     * @return GetShopData|WP_Error
     */
    public function verify_shop_connection($shop_id, $api_key)
    {
        return $this->patch_shop_by_id($shop_id, $api_key, new PatchShopData());
    }

    /**
     * Calls `PUT /api/v1/shops/{shopId}/products` using the generated OpenAPI
     * client, upserting a batch of WooCommerce product objects for backfill.
     *
     * Using PUT (rather than POST) means re-running the backfill is safe: the
     * backend detects whether each product is new, changed, or unchanged and
     * applies the appropriate action.
     *
     * @param string  $shop_id  Shop UUID.
     * @param string  $api_key  Backend API key.
     * @param array[] $products Array of WooCommerce product objects in WC REST API v3 format.
     * @return true|WP_Error
     */
    public function put_shop_products($shop_id, $api_key, array $products)
    {
        $shop_id = Webhook_Manager::normalize_shop_id($shop_id);

        if (
            "" === $this->base_url ||
            !Webhook_Manager::is_valid_shop_id($shop_id)
        ) {
            return new WP_Error(
                "ahpc_backend_invalid_url",
                __(
                    "The backend shop URL could not be built from the configured Shop ID.",
                    self::TEXT_DOMAIN,
                ),
            );
        }

        if (!$this->is_runtime_available()) {
            return new WP_Error(
                "ahpc_backend_client_unavailable",
                __(
                    "The plugin's generated backend API client dependencies are not available.",
                    self::TEXT_DOMAIN,
                ),
            );
        }

        try {
            $this->create_shops_api($api_key)->upsertShopProducts(
                $shop_id,
                $products,
            );
        } catch (ApiException $exception) {
            return $this->translate_api_exception($exception);
        } catch (\InvalidArgumentException $exception) {
            return new WP_Error(
                "ahpc_backend_invalid_request",
                $this->sanitize_error_fragment($exception->getMessage()),
            );
        } catch (\Throwable $throwable) {
            return new WP_Error(
                "ahpc_backend_request_failed",
                $this->sanitize_error_fragment($throwable->getMessage()),
            );
        }

        return true;
    }

    /**
     * Returns whether the generated client runtime dependencies are available.
     *
     * @return bool
     */
    private function is_runtime_available()
    {
        return class_exists(ShopsApi::class) &&
            class_exists(GuzzleClient::class);
    }

    /**
     * Creates the generated `ShopsApi` client.
     *
     * @param string $api_key Backend API key.
     * @return ShopsApi
     */
    private function create_shops_api($api_key)
    {
        $configuration = new Configuration();
        $configuration->setHost($this->base_url);
        $configuration->setApiKey("x-api-key", $api_key);

        return new ShopsApi($this->create_http_client(), $configuration);
    }

    /**
     * Creates the underlying Guzzle HTTP client.
     *
     * A filter is provided so tests can inject a mock client without performing
     * real outbound backend requests.
     *
     * @return ClientInterface
     */
    private function create_http_client()
    {
        $client = new GuzzleClient([
            "timeout" => 15,
            "allow_redirects" => false,
            "http_errors" => true,
            "version" => "1.1",
        ]);

        $filtered_client = apply_filters(
            "ahpc_backend_guzzle_client",
            $client,
            $this->base_url,
        );

        return $filtered_client instanceof ClientInterface
            ? $filtered_client
            : $client;
    }

    /**
     * Converts a generated API exception into a WordPress error.
     *
     * @param ApiException $exception Generated API exception.
     * @return WP_Error
     */
    private function translate_api_exception(ApiException $exception)
    {
        $response_code = (int) $exception->getCode();
        $response_object = method_exists($exception, "getResponseObject")
            ? $exception->getResponseObject()
            : null;
        $message = "";

        if ($response_object instanceof ApiError) {
            foreach (
                [
                    $response_object->getDetail(),
                    $response_object->getTitle(),
                    $response_object->getError(),
                ]
                as $candidate
            ) {
                $message = $this->sanitize_error_fragment($candidate);

                if ("" !== $message) {
                    break;
                }
            }
        }

        if ("" === $message) {
            $response_body = method_exists($exception, "getResponseBody")
                ? (string) $exception->getResponseBody()
                : "";
            $message = $this->extract_response_message($response_body);
        }

        if ("" === $message) {
            $message = $this->sanitize_error_fragment($exception->getMessage());
        }

        if ($response_code > 0) {
            return new WP_Error("ahpc_backend_http_error", $message, [
                "response_code" => $response_code,
            ]);
        }

        return new WP_Error("ahpc_backend_request_failed", $message);
    }

    /**
     * Extracts a short message from a raw backend response body.
     *
     * @param string $body Raw response body.
     * @return string
     */
    private function extract_response_message($body)
    {
        $body = trim((string) $body);

        if ("" === $body) {
            return "";
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            foreach (["detail", "title", "error", "message"] as $key) {
                if (!empty($decoded[$key]) && is_string($decoded[$key])) {
                    return $this->sanitize_error_fragment($decoded[$key]);
                }
            }
        }

        return sanitize_text_field(
            wp_html_excerpt(wp_strip_all_tags($body), 200, "…"),
        );
    }

    /**
     * Sanitizes a backend-provided error fragment for admin display.
     *
     * @param mixed $message Raw message value.
     * @return string
     */
    private function sanitize_error_fragment($message)
    {
        if (empty($message) || !is_string($message)) {
            return "";
        }

        return sanitize_text_field(wp_strip_all_tags($message));
    }
}
