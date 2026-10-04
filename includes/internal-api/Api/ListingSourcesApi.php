<?php
/**
 * ListingSourcesApi
 * PHP version 8.1
 *
 * @category Class
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 */

/**
 * Aura Historia API Reference
 *
 * ## Overview  The Aura Historia API powers the Aura Historia antiques platform and its partner-facing integrations. It exposes product discovery, listing source discovery, personalization, partner onboarding, and integration workflows on top of Aura Historia's serverless AWS platform.  This reference is designed for two audiences: - **Internal developers** building Aura Historia product-listings, operational tooling, and backoffice workflows - **External partners and integrators** synchronizing inventory, consuming platform data, or   integrating with delegated access  ## Core API domains  - **ProductListings and listing-sources** — search, retrieve, and explore product-listings, related product-listings, product   history, and listing source data - **Personalization** — manage watchlists, saved search filters, and user notifications - **Partner workflows** — submit partnership applications, maintain partnerships, ingest catalog data   in batches, and receive WooCommerce webhooks - **Identity and access** — manage user accounts, Aura Historia access tokens, and OAuth 2.0   clients and token flows - **Billing** — create Stripe checkout, billing-portal, and subscription-management sessions  ## Authentication model  The API uses more than one authentication scheme depending on the route:  - **`BearerAuth`** — Cognito **access** JWT bearer tokens for authenticated user, admin, and   partner-user workflows. Cognito ID tokens are not accepted as API credentials. - **`AccessTokenAuth`** — Aura Historia opaque bearer access tokens created via   `/api/v1/me/access-tokens` or OAuth 2.0, primarily intended for partner automations and server-to-server   ingestion scenarios.  When a route lists multiple security schemes, any one of the listed schemes may be accepted. Some read endpoints can also be called without authentication, while a valid user token may enrich the response with personalized state such as watchlist, notification, or search-filter metadata.  ## Data and response conventions  - **PATCH:** an omitted request member remains unchanged. A documented nullable PATCH member   accepts `null` to clear its value; `null` for any other member returns `400 BAD_BODY_VALUE`.   Use `[]`, not `null`, to replace a non-null collection with an empty collection. Empty HTTP   bodies are invalid, `{}` is a valid object-PATCH no-op, and the partner-product PATCH `[]`   remains a valid empty batch. Responses may omit absent optional values. - Product detail, watchlist, and saved-search match reads accept optional **`language`** and   **`currency`** parameters. Currency defaults to `EUR`. Detail pricing contains the seller's source   amounts, converted display amounts, and FX valuation metadata. Active product-listings use the latest persisted   snapshot; sold product-listings use their immutable sale snapshot. Product history remains immutable, source-only,   and is not localized. - Request-wide errors are returned as **`application/problem+json`** using a consistent RFC 9457-style structure   with `status`, `title`, `error`, optional `source`, and optional `detail` - Many list and search endpoints use **cursor-based pagination** via `searchAfter` - Origin API responses include server-generated `X-Request-Id` and `X-Correlation-Id` headers. Clients may send `X-Correlation-Id` to preserve a trace only when it is a 1–128-character ASCII value containing letters, digits, `.`, `_`, or `-`; invalid values are replaced. Both headers are CORS-exposed; CloudFront removes them from viewer responses on the six cache-enabled discovery behaviors so shared cache hits cannot replay origin IDs. - Requests are limited to 1 MiB and time out after 30 seconds. - `GET /api/v1/health` is a process liveness check. `GET /api/v1/ready` returns `204` only when the configured PostgreSQL and OpenSearch dependencies are reachable; it otherwise returns `503`. - WooCommerce webhook ingestion responds with **`204 No Content`** after signed validation. A mapped raw-capture command receives `204` only after confirmed shared FIFO admission; authorized ignored create/update status events are no-op `204`. Queue admission is not raw capture, provider receipt persistence, canonical normalization, or search visibility. Capture conflicts are handled by the consumer, not returned synchronously; existing partner Product batch writes remain synchronous.  ## Environment endpoints  - **Development:** `https://api.stage.aura-historia.com` - **Production:** `https://api.aura-historia.com`  ## Notes for integrators  - Treat Aura Historia identifiers such as `listingSourceId`, `productListingId`, `eventId`, `userSearchFilterId`,   and `partnershipApplicationId` as opaque values - For partner product ingestion, `sourceListingId` is the partner-controlled identifier and should   remain stable within a listing source - Endpoints marked `deprecated` or `x-disabled` are retained for reference only and should not be   used for new integrations
 *
 * The version of the OpenAPI document: 1.0.0
 * Generated by: https://openapi-generator.tech
 * Generator version: 7.22.0
 */

/**
 * NOTE: This class is auto generated by OpenAPI Generator (https://openapi-generator.tech).
 * https://openapi-generator.tech
 * Do not edit the class manually.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose


namespace AuraHistoria\PartnerConnect\InternalApi\Api;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use AuraHistoria\PartnerConnect\InternalApi\ApiException;
use AuraHistoria\PartnerConnect\InternalApi\Configuration;
use AuraHistoria\PartnerConnect\InternalApi\FormDataProcessor;
use AuraHistoria\PartnerConnect\InternalApi\HeaderSelector;
use AuraHistoria\PartnerConnect\InternalApi\ObjectSerializer;

/**
 * ListingSourcesApi Class Doc Comment
 *
 * @category Class
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 */
class ListingSourcesApi
{
    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var Configuration
     */
    protected $config;

    /**
     * @var HeaderSelector
     */
    protected $headerSelector;

    /**
     * @var int Host index
     */
    protected $hostIndex;

    /** @var string[] $contentTypes **/
    public const contentTypes = [
        'putWoocommerceListingSourceIngestionConfiguration' => [
            'application/json',
        ],
    ];

    /**
     * @param ClientInterface $client
     * @param Configuration   $config
     * @param HeaderSelector  $selector
     * @param int             $hostIndex (Optional) host index to select the list of hosts if defined in the OpenAPI spec
     */
    public function __construct(
        ?ClientInterface $client = null,
        ?Configuration $config = null,
        ?HeaderSelector $selector = null,
        int $hostIndex = 0
    ) {
        $this->client = $client ?: new Client();
        $this->config = $config ?: Configuration::getDefaultConfiguration();
        $this->headerSelector = $selector ?: new HeaderSelector();
        $this->hostIndex = $hostIndex;
    }

    /**
     * Set the host index
     *
     * @param int $hostIndex Host index (required)
     */
    public function setHostIndex($hostIndex): void
    {
        $this->hostIndex = $hostIndex;
    }

    /**
     * Get the host index
     *
     * @return int Host index
     */
    public function getHostIndex()
    {
        return $this->hostIndex;
    }

    /**
     * @return Configuration
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Operation putWoocommerceListingSourceIngestionConfiguration
     *
     * Replace WooCommerce ListingSource ingestion configuration
     *
     * @param  string $listing_source_id Strict &#x60;ls_&#x60; ListingSource TypeID. (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\PutWoocommerceListingSourceIngestionConfigurationData $put_woocommerce_listing_source_ingestion_configuration_data put_woocommerce_listing_source_ingestion_configuration_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return void
     */
    public function putWoocommerceListingSourceIngestionConfiguration($listing_source_id, $put_woocommerce_listing_source_ingestion_configuration_data, string $contentType = self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'][0])
    {
        $this->putWoocommerceListingSourceIngestionConfigurationWithHttpInfo($listing_source_id, $put_woocommerce_listing_source_ingestion_configuration_data, $contentType);
    }

    /**
     * Operation putWoocommerceListingSourceIngestionConfigurationWithHttpInfo
     *
     * Replace WooCommerce ListingSource ingestion configuration
     *
     * @param  string $listing_source_id Strict &#x60;ls_&#x60; ListingSource TypeID. (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\PutWoocommerceListingSourceIngestionConfigurationData $put_woocommerce_listing_source_ingestion_configuration_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of null, HTTP status code, HTTP response headers (array of strings)
     */
    public function putWoocommerceListingSourceIngestionConfigurationWithHttpInfo($listing_source_id, $put_woocommerce_listing_source_ingestion_configuration_data, string $contentType = self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'][0])
    {
        $request = $this->putWoocommerceListingSourceIngestionConfigurationRequest($listing_source_id, $put_woocommerce_listing_source_ingestion_configuration_data, $contentType);

        try {
            $options = $this->createHttpClientOption();
            try {
                $response = $this->client->send($request, $options);
            } catch (RequestException $e) {
                throw new ApiException(
                    "[{$e->getCode()}] {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e->getResponse() ? $e->getResponse()->getHeaders() : null,
                    $e->getResponse() ? (string) $e->getResponse()->getBody() : null
                );
            } catch (ConnectException $e) {
                throw new ApiException(
                    "[{$e->getCode()}] {$e->getMessage()}",
                    (int) $e->getCode(),
                    null,
                    null
                );
            }

            $statusCode = $response->getStatusCode();


            return [null, $statusCode, $response->getHeaders()];
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 400:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
                case 401:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
                case 403:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
                case 404:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
                case 409:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
                case 500:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
                case 503:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
            }


            throw $e;
        }
    }

    /**
     * Operation putWoocommerceListingSourceIngestionConfigurationAsync
     *
     * Replace WooCommerce ListingSource ingestion configuration
     *
     * @param  string $listing_source_id Strict &#x60;ls_&#x60; ListingSource TypeID. (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\PutWoocommerceListingSourceIngestionConfigurationData $put_woocommerce_listing_source_ingestion_configuration_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function putWoocommerceListingSourceIngestionConfigurationAsync($listing_source_id, $put_woocommerce_listing_source_ingestion_configuration_data, string $contentType = self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'][0])
    {
        return $this->putWoocommerceListingSourceIngestionConfigurationAsyncWithHttpInfo($listing_source_id, $put_woocommerce_listing_source_ingestion_configuration_data, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation putWoocommerceListingSourceIngestionConfigurationAsyncWithHttpInfo
     *
     * Replace WooCommerce ListingSource ingestion configuration
     *
     * @param  string $listing_source_id Strict &#x60;ls_&#x60; ListingSource TypeID. (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\PutWoocommerceListingSourceIngestionConfigurationData $put_woocommerce_listing_source_ingestion_configuration_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function putWoocommerceListingSourceIngestionConfigurationAsyncWithHttpInfo($listing_source_id, $put_woocommerce_listing_source_ingestion_configuration_data, string $contentType = self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'][0])
    {
        $returnType = '';
        $request = $this->putWoocommerceListingSourceIngestionConfigurationRequest($listing_source_id, $put_woocommerce_listing_source_ingestion_configuration_data, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    return [null, $response->getStatusCode(), $response->getHeaders()];
                },
                function ($exception) {
                    $response = $exception->getResponse();
                    $statusCode = $response->getStatusCode();
                    throw new ApiException(
                        sprintf(
                            '[%d] Error connecting to the API (%s)',
                            $statusCode,
                            $exception->getRequest()->getUri()
                        ),
                        $statusCode,
                        $response->getHeaders(),
                        (string) $response->getBody()
                    );
                }
            );
    }

    /**
     * Create request for operation 'putWoocommerceListingSourceIngestionConfiguration'
     *
     * @param  string $listing_source_id Strict &#x60;ls_&#x60; ListingSource TypeID. (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\PutWoocommerceListingSourceIngestionConfigurationData $put_woocommerce_listing_source_ingestion_configuration_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function putWoocommerceListingSourceIngestionConfigurationRequest($listing_source_id, $put_woocommerce_listing_source_ingestion_configuration_data, string $contentType = self::contentTypes['putWoocommerceListingSourceIngestionConfiguration'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling putWoocommerceListingSourceIngestionConfiguration'
            );
        }

        // verify the required parameter 'put_woocommerce_listing_source_ingestion_configuration_data' is set
        if ($put_woocommerce_listing_source_ingestion_configuration_data === null || (is_array($put_woocommerce_listing_source_ingestion_configuration_data) && count($put_woocommerce_listing_source_ingestion_configuration_data) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $put_woocommerce_listing_source_ingestion_configuration_data when calling putWoocommerceListingSourceIngestionConfiguration'
            );
        }


        $resourcePath = '/api/v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;



        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listingSourceId}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (isset($put_woocommerce_listing_source_ingestion_configuration_data)) {
            if (stripos($headers['Content-Type'], 'application/json') !== false) {
                # if Content-Type contains "application/json", json_encode the body
                $httpBody = \GuzzleHttp\Utils::jsonEncode(ObjectSerializer::sanitizeForSerialization($put_woocommerce_listing_source_ingestion_configuration_data));
            } else {
                $httpBody = $put_woocommerce_listing_source_ingestion_configuration_data;
            }
        } elseif (count($formParams) > 0) {
            if ($multipart) {
                $multipartContents = [];
                foreach ($formParams as $formParamName => $formParamValue) {
                    $formParamValueItems = is_array($formParamValue) ? $formParamValue : [$formParamValue];
                    foreach ($formParamValueItems as $formParamValueItem) {
                        $multipartContents[] = [
                            'name' => $formParamName,
                            'contents' => $formParamValueItem
                        ];
                    }
                }
                // for HTTP post (form)
                $httpBody = new MultipartStream($multipartContents);

            } elseif (stripos($headers['Content-Type'], 'application/json') !== false) {
                # if Content-Type contains "application/json", json_encode the form parameters
                $httpBody = \GuzzleHttp\Utils::jsonEncode($formParams);
            } else {
                // for HTTP post (form)
                $httpBody = ObjectSerializer::buildQuery($formParams);
            }
        }

        // this endpoint requires Bearer (opaque) authentication (access token)
        if (!empty($this->config->getAccessToken())) {
            $headers['Authorization'] = 'Bearer ' . $this->config->getAccessToken();
        }
        // this endpoint requires Bearer (JWT) authentication (access token)
        if (!empty($this->config->getAccessToken())) {
            $headers['Authorization'] = 'Bearer ' . $this->config->getAccessToken();
        }

        $defaultHeaders = [];
        if ($this->config->getUserAgent()) {
            $defaultHeaders['User-Agent'] = $this->config->getUserAgent();
        }

        $headers = array_merge(
            $defaultHeaders,
            $headerParams,
            $headers
        );

        $operationHost = $this->config->getHost();
        $query = ObjectSerializer::buildQuery($queryParams);
        return new Request(
            'PUT',
            $operationHost . $resourcePath . ($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );
    }

    /**
     * Create http client option
     *
     * @throws \RuntimeException on file opening failure
     * @return array of http client options
     */
    protected function createHttpClientOption()
    {
        $options = [];
        if ($this->config->getDebug()) {
            $options[RequestOptions::DEBUG] = fopen($this->config->getDebugFile(), 'a');
            if (!$options[RequestOptions::DEBUG]) {
                throw new \RuntimeException('Failed to open the debug file: ' . $this->config->getDebugFile());
            }
        }

        if ($this->config->getCertFile()) {
            $options[RequestOptions::CERT] = $this->config->getCertFile();
        }

        if ($this->config->getKeyFile()) {
            $options[RequestOptions::SSL_KEY] = $this->config->getKeyFile();
        }

        return $options;
    }

    private function handleResponseWithDataType(
        string $dataType,
        RequestInterface $request,
        ResponseInterface $response
    ): array {
        if ($dataType === '\SplFileObject') {
            $content = $response->getBody(); //stream goes to serializer
        } else {
            $content = (string) $response->getBody();
            if ($dataType !== 'string') {
                try {
                    $content = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new ApiException(
                        sprintf(
                            'Error JSON decoding server response (%s)',
                            $request->getUri()
                        ),
                        $response->getStatusCode(),
                        $response->getHeaders(),
                        $content
                    );
                }
            }
        }

        return [
            ObjectSerializer::deserialize($content, $dataType, []),
            $response->getStatusCode(),
            $response->getHeaders()
        ];
    }

    private function responseWithinRangeCode(
        string $rangeCode,
        int $statusCode
    ): bool {
        $left = (int) ($rangeCode[0].'00');
        $right = (int) ($rangeCode[0].'99');

        return $statusCode >= $left && $statusCode <= $right;
    }
}
