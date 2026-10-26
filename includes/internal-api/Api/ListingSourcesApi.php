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
 * ## Overview  The Aura Historia API powers the Aura Historia antiques platform and its partner-facing integrations. It exposes product discovery, listing source discovery, personalization, partner onboarding, and integration workflows on top of Aura Historia's serverless AWS platform.  This reference is designed for two audiences: - **Internal developers** building Aura Historia product-listings, operational tooling, and backoffice workflows - **External partners and integrators** synchronizing inventory, consuming platform data, or   integrating with delegated access  ## Core API domains  - **ProductListings and listing-sources** — search, retrieve, and explore product-listings, related product-listings, product   history, and listing source data - **Personalization** — manage watchlists, saved search filters, and user notifications - **Partner workflows** — submit partnership applications, maintain partnerships, ingest catalog data   in batches, and receive WooCommerce webhooks - **Identity and access** — manage user accounts, Aura Historia access tokens, and OAuth 2.0   clients and token flows - **Billing** — create Stripe checkout, billing-portal, and subscription-management sessions  ## Authentication model  The API uses more than one authentication scheme depending on the route:  - **`BearerAuth`** — Cognito **access** JWT bearer tokens for authenticated user, admin, and   partner-user workflows. Cognito ID tokens are not accepted as API credentials. - **`AccessTokenAuth`** — Aura Historia opaque bearer access tokens created via   `/v1/me/access-tokens` or OAuth 2.0, primarily intended for partner automations and server-to-server   ingestion scenarios.  When a route lists multiple security schemes, any one of the listed schemes may be accepted. Some read endpoints can also be called without authentication, while a valid user token may enrich the response with personalized state such as watchlist, notification, or search-filter metadata. Aura Historia access tokens receive only the sections their read scopes permit; see `ProductListingUserStateData`.  ## MCP interface  The separate `https://mcp.aura-historia.com/v1` Streamable HTTP endpoint accepts opaque Aura access tokens only. Public calls may omit credentials; invalid supplied credentials receive HTTP 401, protected operations require their declared scopes, and insufficient grants receive HTTP 403 with the complete required scope set. Credential-free protected-resource metadata is served on the MCP host at `/.well-known/oauth-protected-resource` and `/.well-known/oauth-protected-resource/v1`. See [the MCP contract](https://github.com/aura-historia/backend/blob/develop/docs/mcp.md) for protocol and discovery details.  ## Data and response conventions  - **PATCH:** an omitted request member remains unchanged. A documented nullable PATCH member   accepts `null` to clear its value; `null` for any other member returns `400 BAD_BODY_VALUE`.   Use `[]`, not `null`, to replace a non-null collection with an empty collection. Empty HTTP   bodies are invalid, `{}` is a valid object-PATCH no-op, and the partner-product PATCH `[]`   remains a valid empty batch. Responses may omit absent optional values. - Product detail, watchlist, and saved-search match reads accept optional **`language`** and   **`currency`** parameters. Currency defaults to `EUR`. Detail pricing contains the seller's source   amounts, converted display amounts, and FX valuation metadata. Active product-listings use the latest persisted   snapshot; sold product-listings use their immutable sale snapshot. Product history preserves immutable source values and accepts optional `currency` for historical FX display projections.   History text is not localized. - Request-wide REST errors are returned as **`application/problem+json`** using a consistent RFC 9457-style structure   with `status`, `title`, `error`, optional `source`, and optional `detail`. OAuth protocol errors instead return `application/json` with a standard OAuth `error` - Many list and search endpoints use **cursor-based pagination** via `searchAfter` - Origin API responses include server-generated `X-Request-Id` and `X-Correlation-Id` headers. Clients may send `X-Correlation-Id` to preserve a trace only when it is a 1–128-character ASCII value containing letters, digits, `.`, `_`, or `-`; invalid values are replaced. Both headers are CORS-exposed; CloudFront removes them from viewer responses on the six cache-enabled discovery behaviors so shared cache hits cannot replay origin IDs. - Request bodies are limited to 1 MiB and requests time out after 30 seconds. A declared `Content-Length` above 1 MiB is rejected at the edge with `413` (`BAD_BODY_VALUE`, JSON body) before authentication or route handling. - `POST /v1/webhooks/loops` is limited to 64 KiB and has a 10-second processing budget; it requires a valid Loops signature but no Aura bearer. - `GET /v1/health` is a process liveness check. `GET /v1/ready` returns `204` only when the configured PostgreSQL and OpenSearch dependencies are reachable; it otherwise returns `503`. - WooCommerce webhook ingestion responds with **`204 No Content`** after signed validation. A mapped raw-capture command receives `204` only after confirmed shared FIFO admission; authorized ignored create/update status events are no-op `204`. Queue admission is not raw capture, provider receipt persistence, canonical normalization, or search visibility. Capture conflicts are handled by the consumer, not returned synchronously; existing partner Product batch writes remain synchronous.  ## Environment endpoints  - **Development:** `https://api.stage.aura-historia.com` - **Production:** `https://api.aura-historia.com`  ## Notes for integrators  - Treat Aura Historia identifiers such as `listingSourceId`, `productListingId`, `eventId`, `userSearchFilterId`,   and `partnershipApplicationId` as opaque values - For partner product ingestion, `sourceListingId` is the partner-controlled identifier and should   remain stable within a listing source - Endpoints marked `deprecated` or `x-disabled` are retained for reference only and should not be   used for new integrations
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
        'assignShippingDefault' => [
            'application/json',
        ],
        'clearShippingDefault' => [
            'application/json',
        ],
        'createShippingProfile' => [
            'application/json',
        ],
        'getShippingDefault' => [
            'application/json',
        ],
        'getShippingProfile' => [
            'application/json',
        ],
        'listShippingProfiles' => [
            'application/json',
        ],
        'putWoocommerceListingSourceIngestionConfiguration' => [
            'application/json',
        ],
        'replaceShippingProfile' => [
            'application/json',
        ],
        'restoreShippingProfile' => [
            'application/json',
        ],
        'retireShippingProfile' => [
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
     * Operation assignShippingDefault
     *
     * Assign active source shipping default
     *
     * @param  string $listing_source_id listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\AssignShippingDefaultData $assign_shipping_default_data assign_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['assignShippingDefault'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError
     */
    public function assignShippingDefault($listing_source_id, $assign_shipping_default_data, string $contentType = self::contentTypes['assignShippingDefault'][0])
    {
        list($response) = $this->assignShippingDefaultWithHttpInfo($listing_source_id, $assign_shipping_default_data, $contentType);
        return $response;
    }

    /**
     * Operation assignShippingDefaultWithHttpInfo
     *
     * Assign active source shipping default
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\AssignShippingDefaultData $assign_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['assignShippingDefault'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError, HTTP status code, HTTP response headers (array of strings)
     */
    public function assignShippingDefaultWithHttpInfo($listing_source_id, $assign_shipping_default_data, string $contentType = self::contentTypes['assignShippingDefault'][0])
    {
        $request = $this->assignShippingDefaultRequest($listing_source_id, $assign_shipping_default_data, $contentType);

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


            switch($statusCode) {
                case 200:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData',
                        $request,
                        $response,
                    );
                case 400:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 401:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 403:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 404:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 409:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 500:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 503:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
            }



            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        (string) $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
            }

            return $this->handleResponseWithDataType(
                '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData',
                $request,
                $response,
            );
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
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
     * Operation assignShippingDefaultAsync
     *
     * Assign active source shipping default
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\AssignShippingDefaultData $assign_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['assignShippingDefault'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function assignShippingDefaultAsync($listing_source_id, $assign_shipping_default_data, string $contentType = self::contentTypes['assignShippingDefault'][0])
    {
        return $this->assignShippingDefaultAsyncWithHttpInfo($listing_source_id, $assign_shipping_default_data, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation assignShippingDefaultAsyncWithHttpInfo
     *
     * Assign active source shipping default
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\AssignShippingDefaultData $assign_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['assignShippingDefault'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function assignShippingDefaultAsyncWithHttpInfo($listing_source_id, $assign_shipping_default_data, string $contentType = self::contentTypes['assignShippingDefault'][0])
    {
        $returnType = '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData';
        $request = $this->assignShippingDefaultRequest($listing_source_id, $assign_shipping_default_data, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    if ($returnType === '\SplFileObject') {
                        $content = $response->getBody(); //stream goes to serializer
                    } else {
                        $content = (string) $response->getBody();
                        if ($returnType !== 'string') {
                            $content = json_decode($content);
                        }
                    }

                    return [
                        ObjectSerializer::deserialize($content, $returnType, []),
                        $response->getStatusCode(),
                        $response->getHeaders()
                    ];
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
     * Create request for operation 'assignShippingDefault'
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\AssignShippingDefaultData $assign_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['assignShippingDefault'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function assignShippingDefaultRequest($listing_source_id, $assign_shipping_default_data, string $contentType = self::contentTypes['assignShippingDefault'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling assignShippingDefault'
            );
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $listing_source_id)) {
            throw new \InvalidArgumentException("invalid value for \"listing_source_id\" when calling ListingSourcesApi.assignShippingDefault, must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'assign_shipping_default_data' is set
        if ($assign_shipping_default_data === null || (is_array($assign_shipping_default_data) && count($assign_shipping_default_data) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $assign_shipping_default_data when calling assignShippingDefault'
            );
        }


        $resourcePath = '/v1/listing-sources/{listing_source_id}/shipping-default';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;



        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listing_source_id}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/json', 'application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (isset($assign_shipping_default_data)) {
            if (stripos($headers['Content-Type'], 'application/json') !== false) {
                # if Content-Type contains "application/json", json_encode the body
                $httpBody = \GuzzleHttp\Utils::jsonEncode(ObjectSerializer::sanitizeForSerialization($assign_shipping_default_data));
            } else {
                $httpBody = $assign_shipping_default_data;
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
     * Operation clearShippingDefault
     *
     * Clear source shipping default
     *
     * @param  string $listing_source_id listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ClearShippingDefaultData $clear_shipping_default_data clear_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['clearShippingDefault'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError
     */
    public function clearShippingDefault($listing_source_id, $clear_shipping_default_data, string $contentType = self::contentTypes['clearShippingDefault'][0])
    {
        list($response) = $this->clearShippingDefaultWithHttpInfo($listing_source_id, $clear_shipping_default_data, $contentType);
        return $response;
    }

    /**
     * Operation clearShippingDefaultWithHttpInfo
     *
     * Clear source shipping default
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ClearShippingDefaultData $clear_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['clearShippingDefault'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError, HTTP status code, HTTP response headers (array of strings)
     */
    public function clearShippingDefaultWithHttpInfo($listing_source_id, $clear_shipping_default_data, string $contentType = self::contentTypes['clearShippingDefault'][0])
    {
        $request = $this->clearShippingDefaultRequest($listing_source_id, $clear_shipping_default_data, $contentType);

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


            switch($statusCode) {
                case 200:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData',
                        $request,
                        $response,
                    );
                case 400:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 401:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 403:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 404:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 409:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 500:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 503:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
            }



            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        (string) $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
            }

            return $this->handleResponseWithDataType(
                '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData',
                $request,
                $response,
            );
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
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
     * Operation clearShippingDefaultAsync
     *
     * Clear source shipping default
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ClearShippingDefaultData $clear_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['clearShippingDefault'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function clearShippingDefaultAsync($listing_source_id, $clear_shipping_default_data, string $contentType = self::contentTypes['clearShippingDefault'][0])
    {
        return $this->clearShippingDefaultAsyncWithHttpInfo($listing_source_id, $clear_shipping_default_data, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation clearShippingDefaultAsyncWithHttpInfo
     *
     * Clear source shipping default
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ClearShippingDefaultData $clear_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['clearShippingDefault'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function clearShippingDefaultAsyncWithHttpInfo($listing_source_id, $clear_shipping_default_data, string $contentType = self::contentTypes['clearShippingDefault'][0])
    {
        $returnType = '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData';
        $request = $this->clearShippingDefaultRequest($listing_source_id, $clear_shipping_default_data, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    if ($returnType === '\SplFileObject') {
                        $content = $response->getBody(); //stream goes to serializer
                    } else {
                        $content = (string) $response->getBody();
                        if ($returnType !== 'string') {
                            $content = json_decode($content);
                        }
                    }

                    return [
                        ObjectSerializer::deserialize($content, $returnType, []),
                        $response->getStatusCode(),
                        $response->getHeaders()
                    ];
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
     * Create request for operation 'clearShippingDefault'
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ClearShippingDefaultData $clear_shipping_default_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['clearShippingDefault'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function clearShippingDefaultRequest($listing_source_id, $clear_shipping_default_data, string $contentType = self::contentTypes['clearShippingDefault'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling clearShippingDefault'
            );
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $listing_source_id)) {
            throw new \InvalidArgumentException("invalid value for \"listing_source_id\" when calling ListingSourcesApi.clearShippingDefault, must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'clear_shipping_default_data' is set
        if ($clear_shipping_default_data === null || (is_array($clear_shipping_default_data) && count($clear_shipping_default_data) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $clear_shipping_default_data when calling clearShippingDefault'
            );
        }


        $resourcePath = '/v1/listing-sources/{listing_source_id}/shipping-default';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;



        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listing_source_id}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/json', 'application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (isset($clear_shipping_default_data)) {
            if (stripos($headers['Content-Type'], 'application/json') !== false) {
                # if Content-Type contains "application/json", json_encode the body
                $httpBody = \GuzzleHttp\Utils::jsonEncode(ObjectSerializer::sanitizeForSerialization($clear_shipping_default_data));
            } else {
                $httpBody = $clear_shipping_default_data;
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
            'DELETE',
            $operationHost . $resourcePath . ($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );
    }

    /**
     * Operation createShippingProfile
     *
     * Create source shipping profile
     *
     * @param  string $listing_source_id listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\CreateShippingProfileData $create_shipping_profile_data create_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['createShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError
     */
    public function createShippingProfile($listing_source_id, $create_shipping_profile_data, string $contentType = self::contentTypes['createShippingProfile'][0])
    {
        list($response) = $this->createShippingProfileWithHttpInfo($listing_source_id, $create_shipping_profile_data, $contentType);
        return $response;
    }

    /**
     * Operation createShippingProfileWithHttpInfo
     *
     * Create source shipping profile
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\CreateShippingProfileData $create_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['createShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError, HTTP status code, HTTP response headers (array of strings)
     */
    public function createShippingProfileWithHttpInfo($listing_source_id, $create_shipping_profile_data, string $contentType = self::contentTypes['createShippingProfile'][0])
    {
        $request = $this->createShippingProfileRequest($listing_source_id, $create_shipping_profile_data, $contentType);

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


            switch($statusCode) {
                case 200:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $request,
                        $response,
                    );
                case 400:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 401:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 403:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 404:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 409:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 500:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 503:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
            }



            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        (string) $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
            }

            return $this->handleResponseWithDataType(
                '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                $request,
                $response,
            );
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
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
     * Operation createShippingProfileAsync
     *
     * Create source shipping profile
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\CreateShippingProfileData $create_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['createShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function createShippingProfileAsync($listing_source_id, $create_shipping_profile_data, string $contentType = self::contentTypes['createShippingProfile'][0])
    {
        return $this->createShippingProfileAsyncWithHttpInfo($listing_source_id, $create_shipping_profile_data, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation createShippingProfileAsyncWithHttpInfo
     *
     * Create source shipping profile
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\CreateShippingProfileData $create_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['createShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function createShippingProfileAsyncWithHttpInfo($listing_source_id, $create_shipping_profile_data, string $contentType = self::contentTypes['createShippingProfile'][0])
    {
        $returnType = '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData';
        $request = $this->createShippingProfileRequest($listing_source_id, $create_shipping_profile_data, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    if ($returnType === '\SplFileObject') {
                        $content = $response->getBody(); //stream goes to serializer
                    } else {
                        $content = (string) $response->getBody();
                        if ($returnType !== 'string') {
                            $content = json_decode($content);
                        }
                    }

                    return [
                        ObjectSerializer::deserialize($content, $returnType, []),
                        $response->getStatusCode(),
                        $response->getHeaders()
                    ];
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
     * Create request for operation 'createShippingProfile'
     *
     * @param  string $listing_source_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\CreateShippingProfileData $create_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['createShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function createShippingProfileRequest($listing_source_id, $create_shipping_profile_data, string $contentType = self::contentTypes['createShippingProfile'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling createShippingProfile'
            );
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $listing_source_id)) {
            throw new \InvalidArgumentException("invalid value for \"listing_source_id\" when calling ListingSourcesApi.createShippingProfile, must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'create_shipping_profile_data' is set
        if ($create_shipping_profile_data === null || (is_array($create_shipping_profile_data) && count($create_shipping_profile_data) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $create_shipping_profile_data when calling createShippingProfile'
            );
        }


        $resourcePath = '/v1/listing-sources/{listing_source_id}/shipping-profiles';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;



        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listing_source_id}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/json', 'application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (isset($create_shipping_profile_data)) {
            if (stripos($headers['Content-Type'], 'application/json') !== false) {
                # if Content-Type contains "application/json", json_encode the body
                $httpBody = \GuzzleHttp\Utils::jsonEncode(ObjectSerializer::sanitizeForSerialization($create_shipping_profile_data));
            } else {
                $httpBody = $create_shipping_profile_data;
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
            'POST',
            $operationHost . $resourcePath . ($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );
    }

    /**
     * Operation getShippingDefault
     *
     * Read explicit source shipping default
     *
     * @param  string $listing_source_id listing_source_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingDefault'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError
     */
    public function getShippingDefault($listing_source_id, string $contentType = self::contentTypes['getShippingDefault'][0])
    {
        list($response) = $this->getShippingDefaultWithHttpInfo($listing_source_id, $contentType);
        return $response;
    }

    /**
     * Operation getShippingDefaultWithHttpInfo
     *
     * Read explicit source shipping default
     *
     * @param  string $listing_source_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingDefault'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError, HTTP status code, HTTP response headers (array of strings)
     */
    public function getShippingDefaultWithHttpInfo($listing_source_id, string $contentType = self::contentTypes['getShippingDefault'][0])
    {
        $request = $this->getShippingDefaultRequest($listing_source_id, $contentType);

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


            switch($statusCode) {
                case 200:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData',
                        $request,
                        $response,
                    );
                case 400:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 401:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 403:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 404:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 409:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 500:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 503:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
            }



            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        (string) $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
            }

            return $this->handleResponseWithDataType(
                '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData',
                $request,
                $response,
            );
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
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
     * Operation getShippingDefaultAsync
     *
     * Read explicit source shipping default
     *
     * @param  string $listing_source_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingDefault'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function getShippingDefaultAsync($listing_source_id, string $contentType = self::contentTypes['getShippingDefault'][0])
    {
        return $this->getShippingDefaultAsyncWithHttpInfo($listing_source_id, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation getShippingDefaultAsyncWithHttpInfo
     *
     * Read explicit source shipping default
     *
     * @param  string $listing_source_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingDefault'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function getShippingDefaultAsyncWithHttpInfo($listing_source_id, string $contentType = self::contentTypes['getShippingDefault'][0])
    {
        $returnType = '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingDefaultData';
        $request = $this->getShippingDefaultRequest($listing_source_id, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    if ($returnType === '\SplFileObject') {
                        $content = $response->getBody(); //stream goes to serializer
                    } else {
                        $content = (string) $response->getBody();
                        if ($returnType !== 'string') {
                            $content = json_decode($content);
                        }
                    }

                    return [
                        ObjectSerializer::deserialize($content, $returnType, []),
                        $response->getStatusCode(),
                        $response->getHeaders()
                    ];
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
     * Create request for operation 'getShippingDefault'
     *
     * @param  string $listing_source_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingDefault'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function getShippingDefaultRequest($listing_source_id, string $contentType = self::contentTypes['getShippingDefault'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling getShippingDefault'
            );
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $listing_source_id)) {
            throw new \InvalidArgumentException("invalid value for \"listing_source_id\" when calling ListingSourcesApi.getShippingDefault, must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }


        $resourcePath = '/v1/listing-sources/{listing_source_id}/shipping-default';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;



        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listing_source_id}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/json', 'application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (count($formParams) > 0) {
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
            'GET',
            $operationHost . $resourcePath . ($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );
    }

    /**
     * Operation getShippingProfile
     *
     * Get source shipping profile
     *
     * @param  string $listing_source_id listing_source_id (required)
     * @param  string $shipping_profile_id shipping_profile_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError
     */
    public function getShippingProfile($listing_source_id, $shipping_profile_id, string $contentType = self::contentTypes['getShippingProfile'][0])
    {
        list($response) = $this->getShippingProfileWithHttpInfo($listing_source_id, $shipping_profile_id, $contentType);
        return $response;
    }

    /**
     * Operation getShippingProfileWithHttpInfo
     *
     * Get source shipping profile
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError, HTTP status code, HTTP response headers (array of strings)
     */
    public function getShippingProfileWithHttpInfo($listing_source_id, $shipping_profile_id, string $contentType = self::contentTypes['getShippingProfile'][0])
    {
        $request = $this->getShippingProfileRequest($listing_source_id, $shipping_profile_id, $contentType);

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


            switch($statusCode) {
                case 200:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $request,
                        $response,
                    );
                case 400:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 401:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 403:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 404:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 409:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 500:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 503:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
            }



            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        (string) $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
            }

            return $this->handleResponseWithDataType(
                '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                $request,
                $response,
            );
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
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
     * Operation getShippingProfileAsync
     *
     * Get source shipping profile
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function getShippingProfileAsync($listing_source_id, $shipping_profile_id, string $contentType = self::contentTypes['getShippingProfile'][0])
    {
        return $this->getShippingProfileAsyncWithHttpInfo($listing_source_id, $shipping_profile_id, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation getShippingProfileAsyncWithHttpInfo
     *
     * Get source shipping profile
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function getShippingProfileAsyncWithHttpInfo($listing_source_id, $shipping_profile_id, string $contentType = self::contentTypes['getShippingProfile'][0])
    {
        $returnType = '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData';
        $request = $this->getShippingProfileRequest($listing_source_id, $shipping_profile_id, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    if ($returnType === '\SplFileObject') {
                        $content = $response->getBody(); //stream goes to serializer
                    } else {
                        $content = (string) $response->getBody();
                        if ($returnType !== 'string') {
                            $content = json_decode($content);
                        }
                    }

                    return [
                        ObjectSerializer::deserialize($content, $returnType, []),
                        $response->getStatusCode(),
                        $response->getHeaders()
                    ];
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
     * Create request for operation 'getShippingProfile'
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['getShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function getShippingProfileRequest($listing_source_id, $shipping_profile_id, string $contentType = self::contentTypes['getShippingProfile'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling getShippingProfile'
            );
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $listing_source_id)) {
            throw new \InvalidArgumentException("invalid value for \"listing_source_id\" when calling ListingSourcesApi.getShippingProfile, must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'shipping_profile_id' is set
        if ($shipping_profile_id === null || (is_array($shipping_profile_id) && count($shipping_profile_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $shipping_profile_id when calling getShippingProfile'
            );
        }
        if (!preg_match("/^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $shipping_profile_id)) {
            throw new \InvalidArgumentException("invalid value for \"shipping_profile_id\" when calling ListingSourcesApi.getShippingProfile, must conform to the pattern /^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }


        $resourcePath = '/v1/listing-sources/{listing_source_id}/shipping-profiles/{shipping_profile_id}';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;



        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listing_source_id}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }
        // path params
        if ($shipping_profile_id !== null) {
            $resourcePath = str_replace(
                '{shipping_profile_id}',
                ObjectSerializer::toPathValue($shipping_profile_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/json', 'application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (count($formParams) > 0) {
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
            'GET',
            $operationHost . $resourcePath . ($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );
    }

    /**
     * Operation listShippingProfiles
     *
     * List source shipping profiles
     *
     * @param  string $listing_source_id listing_source_id (required)
     * @param  int|null $size size (optional, default to 21)
     * @param  string|null $after after (optional)
     * @param  string|null $object_key Exact stable object key belonging to the authenticated producer. Omit integrationMethod for SOURCE identity. (optional)
     * @param  string|null $integration_method Enabled provider method for objectKey lookup; requires objectKey. (optional)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['listShippingProfiles'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileSummaryData[]|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError
     */
    public function listShippingProfiles($listing_source_id, $size = 21, $after = null, $object_key = null, $integration_method = null, string $contentType = self::contentTypes['listShippingProfiles'][0])
    {
        list($response) = $this->listShippingProfilesWithHttpInfo($listing_source_id, $size, $after, $object_key, $integration_method, $contentType);
        return $response;
    }

    /**
     * Operation listShippingProfilesWithHttpInfo
     *
     * List source shipping profiles
     *
     * @param  string $listing_source_id (required)
     * @param  int|null $size (optional, default to 21)
     * @param  string|null $after (optional)
     * @param  string|null $object_key Exact stable object key belonging to the authenticated producer. Omit integrationMethod for SOURCE identity. (optional)
     * @param  string|null $integration_method Enabled provider method for objectKey lookup; requires objectKey. (optional)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['listShippingProfiles'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileSummaryData[]|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError, HTTP status code, HTTP response headers (array of strings)
     */
    public function listShippingProfilesWithHttpInfo($listing_source_id, $size = 21, $after = null, $object_key = null, $integration_method = null, string $contentType = self::contentTypes['listShippingProfiles'][0])
    {
        $request = $this->listShippingProfilesRequest($listing_source_id, $size, $after, $object_key, $integration_method, $contentType);

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


            switch($statusCode) {
                case 200:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileSummaryData[]',
                        $request,
                        $response,
                    );
                case 400:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 401:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 403:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 404:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 409:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 500:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 503:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
            }



            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        (string) $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
            }

            return $this->handleResponseWithDataType(
                '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileSummaryData[]',
                $request,
                $response,
            );
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileSummaryData[]',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
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
     * Operation listShippingProfilesAsync
     *
     * List source shipping profiles
     *
     * @param  string $listing_source_id (required)
     * @param  int|null $size (optional, default to 21)
     * @param  string|null $after (optional)
     * @param  string|null $object_key Exact stable object key belonging to the authenticated producer. Omit integrationMethod for SOURCE identity. (optional)
     * @param  string|null $integration_method Enabled provider method for objectKey lookup; requires objectKey. (optional)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['listShippingProfiles'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function listShippingProfilesAsync($listing_source_id, $size = 21, $after = null, $object_key = null, $integration_method = null, string $contentType = self::contentTypes['listShippingProfiles'][0])
    {
        return $this->listShippingProfilesAsyncWithHttpInfo($listing_source_id, $size, $after, $object_key, $integration_method, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation listShippingProfilesAsyncWithHttpInfo
     *
     * List source shipping profiles
     *
     * @param  string $listing_source_id (required)
     * @param  int|null $size (optional, default to 21)
     * @param  string|null $after (optional)
     * @param  string|null $object_key Exact stable object key belonging to the authenticated producer. Omit integrationMethod for SOURCE identity. (optional)
     * @param  string|null $integration_method Enabled provider method for objectKey lookup; requires objectKey. (optional)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['listShippingProfiles'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function listShippingProfilesAsyncWithHttpInfo($listing_source_id, $size = 21, $after = null, $object_key = null, $integration_method = null, string $contentType = self::contentTypes['listShippingProfiles'][0])
    {
        $returnType = '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileSummaryData[]';
        $request = $this->listShippingProfilesRequest($listing_source_id, $size, $after, $object_key, $integration_method, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    if ($returnType === '\SplFileObject') {
                        $content = $response->getBody(); //stream goes to serializer
                    } else {
                        $content = (string) $response->getBody();
                        if ($returnType !== 'string') {
                            $content = json_decode($content);
                        }
                    }

                    return [
                        ObjectSerializer::deserialize($content, $returnType, []),
                        $response->getStatusCode(),
                        $response->getHeaders()
                    ];
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
     * Create request for operation 'listShippingProfiles'
     *
     * @param  string $listing_source_id (required)
     * @param  int|null $size (optional, default to 21)
     * @param  string|null $after (optional)
     * @param  string|null $object_key Exact stable object key belonging to the authenticated producer. Omit integrationMethod for SOURCE identity. (optional)
     * @param  string|null $integration_method Enabled provider method for objectKey lookup; requires objectKey. (optional)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['listShippingProfiles'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function listShippingProfilesRequest($listing_source_id, $size = 21, $after = null, $object_key = null, $integration_method = null, string $contentType = self::contentTypes['listShippingProfiles'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling listShippingProfiles'
            );
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $listing_source_id)) {
            throw new \InvalidArgumentException("invalid value for \"listing_source_id\" when calling ListingSourcesApi.listShippingProfiles, must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        if ($size !== null && $size > 100) {
            throw new \InvalidArgumentException('invalid value for "$size" when calling ListingSourcesApi.listShippingProfiles, must be smaller than or equal to 100.');
        }
        if ($size !== null && $size < 1) {
            throw new \InvalidArgumentException('invalid value for "$size" when calling ListingSourcesApi.listShippingProfiles, must be bigger than or equal to 1.');
        }

        if ($after !== null && !preg_match("/^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $after)) {
            throw new \InvalidArgumentException("invalid value for \"after\" when calling ListingSourcesApi.listShippingProfiles, must conform to the pattern /^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        if ($object_key !== null && strlen($object_key) > 128) {
            throw new \InvalidArgumentException('invalid length for "$object_key" when calling ListingSourcesApi.listShippingProfiles, must be smaller than or equal to 128.');
        }
        if ($object_key !== null && strlen($object_key) < 1) {
            throw new \InvalidArgumentException('invalid length for "$object_key" when calling ListingSourcesApi.listShippingProfiles, must be bigger than or equal to 1.');
        }
        if ($object_key !== null && !preg_match("/^[!-~]+$/", $object_key)) {
            throw new \InvalidArgumentException("invalid value for \"object_key\" when calling ListingSourcesApi.listShippingProfiles, must conform to the pattern /^[!-~]+$/.");
        }



        $resourcePath = '/v1/listing-sources/{listing_source_id}/shipping-profiles';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;

        // query params
        $queryParams = array_merge($queryParams, ObjectSerializer::toQueryValue(
            $size,
            'size', // param base name
            'integer', // openApiType
            'form', // style
            true, // explode
            false // required
        ) ?? []);
        // query params
        $queryParams = array_merge($queryParams, ObjectSerializer::toQueryValue(
            $after,
            'after', // param base name
            'string', // openApiType
            'form', // style
            true, // explode
            false // required
        ) ?? []);
        // query params
        $queryParams = array_merge($queryParams, ObjectSerializer::toQueryValue(
            $object_key,
            'objectKey', // param base name
            'string', // openApiType
            'form', // style
            true, // explode
            false // required
        ) ?? []);
        // query params
        $queryParams = array_merge($queryParams, ObjectSerializer::toQueryValue(
            $integration_method,
            'integrationMethod', // param base name
            'string', // openApiType
            'form', // style
            true, // explode
            false // required
        ) ?? []);


        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listing_source_id}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/json', 'application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (count($formParams) > 0) {
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
            'GET',
            $operationHost . $resourcePath . ($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );
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


        $resourcePath = '/v1/listing-sources/{listingSourceId}/ingestion-configurations/woocommerce';
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
     * Operation replaceShippingProfile
     *
     * Replace advertised profile content and evidence
     *
     * @param  string $listing_source_id listing_source_id (required)
     * @param  string $shipping_profile_id shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ReplaceShippingProfileData $replace_shipping_profile_data replace_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['replaceShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError
     */
    public function replaceShippingProfile($listing_source_id, $shipping_profile_id, $replace_shipping_profile_data, string $contentType = self::contentTypes['replaceShippingProfile'][0])
    {
        list($response) = $this->replaceShippingProfileWithHttpInfo($listing_source_id, $shipping_profile_id, $replace_shipping_profile_data, $contentType);
        return $response;
    }

    /**
     * Operation replaceShippingProfileWithHttpInfo
     *
     * Replace advertised profile content and evidence
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ReplaceShippingProfileData $replace_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['replaceShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError, HTTP status code, HTTP response headers (array of strings)
     */
    public function replaceShippingProfileWithHttpInfo($listing_source_id, $shipping_profile_id, $replace_shipping_profile_data, string $contentType = self::contentTypes['replaceShippingProfile'][0])
    {
        $request = $this->replaceShippingProfileRequest($listing_source_id, $shipping_profile_id, $replace_shipping_profile_data, $contentType);

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


            switch($statusCode) {
                case 200:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $request,
                        $response,
                    );
                case 400:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 401:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 403:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 404:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 409:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 500:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 503:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
            }



            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        (string) $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
            }

            return $this->handleResponseWithDataType(
                '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                $request,
                $response,
            );
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
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
     * Operation replaceShippingProfileAsync
     *
     * Replace advertised profile content and evidence
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ReplaceShippingProfileData $replace_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['replaceShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function replaceShippingProfileAsync($listing_source_id, $shipping_profile_id, $replace_shipping_profile_data, string $contentType = self::contentTypes['replaceShippingProfile'][0])
    {
        return $this->replaceShippingProfileAsyncWithHttpInfo($listing_source_id, $shipping_profile_id, $replace_shipping_profile_data, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation replaceShippingProfileAsyncWithHttpInfo
     *
     * Replace advertised profile content and evidence
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ReplaceShippingProfileData $replace_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['replaceShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function replaceShippingProfileAsyncWithHttpInfo($listing_source_id, $shipping_profile_id, $replace_shipping_profile_data, string $contentType = self::contentTypes['replaceShippingProfile'][0])
    {
        $returnType = '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData';
        $request = $this->replaceShippingProfileRequest($listing_source_id, $shipping_profile_id, $replace_shipping_profile_data, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    if ($returnType === '\SplFileObject') {
                        $content = $response->getBody(); //stream goes to serializer
                    } else {
                        $content = (string) $response->getBody();
                        if ($returnType !== 'string') {
                            $content = json_decode($content);
                        }
                    }

                    return [
                        ObjectSerializer::deserialize($content, $returnType, []),
                        $response->getStatusCode(),
                        $response->getHeaders()
                    ];
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
     * Create request for operation 'replaceShippingProfile'
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ReplaceShippingProfileData $replace_shipping_profile_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['replaceShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function replaceShippingProfileRequest($listing_source_id, $shipping_profile_id, $replace_shipping_profile_data, string $contentType = self::contentTypes['replaceShippingProfile'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling replaceShippingProfile'
            );
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $listing_source_id)) {
            throw new \InvalidArgumentException("invalid value for \"listing_source_id\" when calling ListingSourcesApi.replaceShippingProfile, must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'shipping_profile_id' is set
        if ($shipping_profile_id === null || (is_array($shipping_profile_id) && count($shipping_profile_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $shipping_profile_id when calling replaceShippingProfile'
            );
        }
        if (!preg_match("/^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $shipping_profile_id)) {
            throw new \InvalidArgumentException("invalid value for \"shipping_profile_id\" when calling ListingSourcesApi.replaceShippingProfile, must conform to the pattern /^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'replace_shipping_profile_data' is set
        if ($replace_shipping_profile_data === null || (is_array($replace_shipping_profile_data) && count($replace_shipping_profile_data) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $replace_shipping_profile_data when calling replaceShippingProfile'
            );
        }


        $resourcePath = '/v1/listing-sources/{listing_source_id}/shipping-profiles/{shipping_profile_id}';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;



        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listing_source_id}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }
        // path params
        if ($shipping_profile_id !== null) {
            $resourcePath = str_replace(
                '{shipping_profile_id}',
                ObjectSerializer::toPathValue($shipping_profile_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/json', 'application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (isset($replace_shipping_profile_data)) {
            if (stripos($headers['Content-Type'], 'application/json') !== false) {
                # if Content-Type contains "application/json", json_encode the body
                $httpBody = \GuzzleHttp\Utils::jsonEncode(ObjectSerializer::sanitizeForSerialization($replace_shipping_profile_data));
            } else {
                $httpBody = $replace_shipping_profile_data;
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
     * Operation restoreShippingProfile
     *
     * Restore shipping profile explicitly
     *
     * @param  string $listing_source_id listing_source_id (required)
     * @param  string $shipping_profile_id shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['restoreShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError
     */
    public function restoreShippingProfile($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['restoreShippingProfile'][0])
    {
        list($response) = $this->restoreShippingProfileWithHttpInfo($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, $contentType);
        return $response;
    }

    /**
     * Operation restoreShippingProfileWithHttpInfo
     *
     * Restore shipping profile explicitly
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['restoreShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError, HTTP status code, HTTP response headers (array of strings)
     */
    public function restoreShippingProfileWithHttpInfo($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['restoreShippingProfile'][0])
    {
        $request = $this->restoreShippingProfileRequest($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, $contentType);

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


            switch($statusCode) {
                case 200:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $request,
                        $response,
                    );
                case 400:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 401:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 403:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 404:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 409:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 500:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 503:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
            }



            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        (string) $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
            }

            return $this->handleResponseWithDataType(
                '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                $request,
                $response,
            );
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
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
     * Operation restoreShippingProfileAsync
     *
     * Restore shipping profile explicitly
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['restoreShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function restoreShippingProfileAsync($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['restoreShippingProfile'][0])
    {
        return $this->restoreShippingProfileAsyncWithHttpInfo($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation restoreShippingProfileAsyncWithHttpInfo
     *
     * Restore shipping profile explicitly
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['restoreShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function restoreShippingProfileAsyncWithHttpInfo($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['restoreShippingProfile'][0])
    {
        $returnType = '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData';
        $request = $this->restoreShippingProfileRequest($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    if ($returnType === '\SplFileObject') {
                        $content = $response->getBody(); //stream goes to serializer
                    } else {
                        $content = (string) $response->getBody();
                        if ($returnType !== 'string') {
                            $content = json_decode($content);
                        }
                    }

                    return [
                        ObjectSerializer::deserialize($content, $returnType, []),
                        $response->getStatusCode(),
                        $response->getHeaders()
                    ];
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
     * Create request for operation 'restoreShippingProfile'
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['restoreShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function restoreShippingProfileRequest($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['restoreShippingProfile'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling restoreShippingProfile'
            );
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $listing_source_id)) {
            throw new \InvalidArgumentException("invalid value for \"listing_source_id\" when calling ListingSourcesApi.restoreShippingProfile, must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'shipping_profile_id' is set
        if ($shipping_profile_id === null || (is_array($shipping_profile_id) && count($shipping_profile_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $shipping_profile_id when calling restoreShippingProfile'
            );
        }
        if (!preg_match("/^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $shipping_profile_id)) {
            throw new \InvalidArgumentException("invalid value for \"shipping_profile_id\" when calling ListingSourcesApi.restoreShippingProfile, must conform to the pattern /^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'shipping_profile_lifecycle_data' is set
        if ($shipping_profile_lifecycle_data === null || (is_array($shipping_profile_lifecycle_data) && count($shipping_profile_lifecycle_data) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $shipping_profile_lifecycle_data when calling restoreShippingProfile'
            );
        }


        $resourcePath = '/v1/listing-sources/{listing_source_id}/shipping-profiles/{shipping_profile_id}/restore';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;



        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listing_source_id}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }
        // path params
        if ($shipping_profile_id !== null) {
            $resourcePath = str_replace(
                '{shipping_profile_id}',
                ObjectSerializer::toPathValue($shipping_profile_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/json', 'application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (isset($shipping_profile_lifecycle_data)) {
            if (stripos($headers['Content-Type'], 'application/json') !== false) {
                # if Content-Type contains "application/json", json_encode the body
                $httpBody = \GuzzleHttp\Utils::jsonEncode(ObjectSerializer::sanitizeForSerialization($shipping_profile_lifecycle_data));
            } else {
                $httpBody = $shipping_profile_lifecycle_data;
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
            'POST',
            $operationHost . $resourcePath . ($query ? "?{$query}" : ''),
            $headers,
            $httpBody
        );
    }

    /**
     * Operation retireShippingProfile
     *
     * Retire shipping profile
     *
     * @param  string $listing_source_id listing_source_id (required)
     * @param  string $shipping_profile_id shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['retireShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError
     */
    public function retireShippingProfile($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['retireShippingProfile'][0])
    {
        list($response) = $this->retireShippingProfileWithHttpInfo($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, $contentType);
        return $response;
    }

    /**
     * Operation retireShippingProfileWithHttpInfo
     *
     * Retire shipping profile
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['retireShippingProfile'] to see the possible values for this operation
     *
     * @throws \AuraHistoria\PartnerConnect\InternalApi\ApiException on non-2xx response or if the response body is not in the expected format
     * @throws \InvalidArgumentException
     * @return array of \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError|\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError, HTTP status code, HTTP response headers (array of strings)
     */
    public function retireShippingProfileWithHttpInfo($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['retireShippingProfile'][0])
    {
        $request = $this->retireShippingProfileRequest($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, $contentType);

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


            switch($statusCode) {
                case 200:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $request,
                        $response,
                    );
                case 400:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 401:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 403:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 404:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 409:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 500:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
                case 503:
                    return $this->handleResponseWithDataType(
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ApiError',
                        $request,
                        $response,
                    );
            }



            if ($statusCode < 200 || $statusCode > 299) {
                throw new ApiException(
                    sprintf(
                        '[%d] Error connecting to the API (%s)',
                        $statusCode,
                        (string) $request->getUri()
                    ),
                    $statusCode,
                    $response->getHeaders(),
                    (string) $response->getBody()
                );
            }

            return $this->handleResponseWithDataType(
                '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                $request,
                $response,
            );
        } catch (ApiException $e) {
            switch ($e->getCode()) {
                case 200:
                    $data = ObjectSerializer::deserialize(
                        $e->getResponseBody(),
                        '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData',
                        $e->getResponseHeaders()
                    );
                    $e->setResponseObject($data);
                    throw $e;
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
     * Operation retireShippingProfileAsync
     *
     * Retire shipping profile
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['retireShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function retireShippingProfileAsync($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['retireShippingProfile'][0])
    {
        return $this->retireShippingProfileAsyncWithHttpInfo($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, $contentType)
            ->then(
                function ($response) {
                    return $response[0];
                }
            );
    }

    /**
     * Operation retireShippingProfileAsyncWithHttpInfo
     *
     * Retire shipping profile
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['retireShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function retireShippingProfileAsyncWithHttpInfo($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['retireShippingProfile'][0])
    {
        $returnType = '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileData';
        $request = $this->retireShippingProfileRequest($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, $contentType);

        return $this->client
            ->sendAsync($request, $this->createHttpClientOption())
            ->then(
                function ($response) use ($returnType) {
                    if ($returnType === '\SplFileObject') {
                        $content = $response->getBody(); //stream goes to serializer
                    } else {
                        $content = (string) $response->getBody();
                        if ($returnType !== 'string') {
                            $content = json_decode($content);
                        }
                    }

                    return [
                        ObjectSerializer::deserialize($content, $returnType, []),
                        $response->getStatusCode(),
                        $response->getHeaders()
                    ];
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
     * Create request for operation 'retireShippingProfile'
     *
     * @param  string $listing_source_id (required)
     * @param  string $shipping_profile_id (required)
     * @param  \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileLifecycleData $shipping_profile_lifecycle_data (required)
     * @param  string $contentType The value for the Content-Type header. Check self::contentTypes['retireShippingProfile'] to see the possible values for this operation
     *
     * @throws \InvalidArgumentException
     * @return \GuzzleHttp\Psr7\Request
     */
    public function retireShippingProfileRequest($listing_source_id, $shipping_profile_id, $shipping_profile_lifecycle_data, string $contentType = self::contentTypes['retireShippingProfile'][0])
    {

        // verify the required parameter 'listing_source_id' is set
        if ($listing_source_id === null || (is_array($listing_source_id) && count($listing_source_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $listing_source_id when calling retireShippingProfile'
            );
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $listing_source_id)) {
            throw new \InvalidArgumentException("invalid value for \"listing_source_id\" when calling ListingSourcesApi.retireShippingProfile, must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'shipping_profile_id' is set
        if ($shipping_profile_id === null || (is_array($shipping_profile_id) && count($shipping_profile_id) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $shipping_profile_id when calling retireShippingProfile'
            );
        }
        if (!preg_match("/^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $shipping_profile_id)) {
            throw new \InvalidArgumentException("invalid value for \"shipping_profile_id\" when calling ListingSourcesApi.retireShippingProfile, must conform to the pattern /^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        // verify the required parameter 'shipping_profile_lifecycle_data' is set
        if ($shipping_profile_lifecycle_data === null || (is_array($shipping_profile_lifecycle_data) && count($shipping_profile_lifecycle_data) === 0)) {
            throw new \InvalidArgumentException(
                'Missing the required parameter $shipping_profile_lifecycle_data when calling retireShippingProfile'
            );
        }


        $resourcePath = '/v1/listing-sources/{listing_source_id}/shipping-profiles/{shipping_profile_id}';
        $formParams = [];
        $queryParams = [];
        $headerParams = [];
        $httpBody = '';
        $multipart = false;



        // path params
        if ($listing_source_id !== null) {
            $resourcePath = str_replace(
                '{listing_source_id}',
                ObjectSerializer::toPathValue($listing_source_id),
                $resourcePath
            );
        }
        // path params
        if ($shipping_profile_id !== null) {
            $resourcePath = str_replace(
                '{shipping_profile_id}',
                ObjectSerializer::toPathValue($shipping_profile_id),
                $resourcePath
            );
        }


        $headers = $this->headerSelector->selectHeaders(
            ['application/json', 'application/problem+json', ],
            $contentType,
            $multipart
        );

        // for model (json/xml)
        if (isset($shipping_profile_lifecycle_data)) {
            if (stripos($headers['Content-Type'], 'application/json') !== false) {
                # if Content-Type contains "application/json", json_encode the body
                $httpBody = \GuzzleHttp\Utils::jsonEncode(ObjectSerializer::sanitizeForSerialization($shipping_profile_lifecycle_data));
            } else {
                $httpBody = $shipping_profile_lifecycle_data;
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
            'DELETE',
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
