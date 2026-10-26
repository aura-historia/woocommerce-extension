<?php
/**
 * ShippingProfileData
 *
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
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped


namespace AuraHistoria\PartnerConnect\InternalApi\Model;

use \ArrayAccess;
use \AuraHistoria\PartnerConnect\InternalApi\ObjectSerializer;

/**
 * ShippingProfileData Class Doc Comment
 *
 * @category Class
 * @description Protected management detail; retains retired references and site assertions, excludes evidence. Never expose this DTO as public collection addresses.
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 * @implements \ArrayAccess<string, mixed>
 */
class ShippingProfileData implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'ShippingProfileData';

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'shipping_profile_id' => 'string',
        'listing_source_id' => 'string',
        'management_revision' => 'int',
        'terms_revision' => 'int',
        'lifecycle' => 'string',
        'content' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileContentData'
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'shipping_profile_id' => null,
        'listing_source_id' => null,
        'management_revision' => null,
        'terms_revision' => null,
        'lifecycle' => null,
        'content' => null
    ];

    /**
     * Array of nullable properties. Used for (de)serialization
     *
     * @var boolean[]
     */
    protected static array $openAPINullables = [
        'shipping_profile_id' => false,
        'listing_source_id' => false,
        'management_revision' => false,
        'terms_revision' => false,
        'lifecycle' => false,
        'content' => false
    ];

    /**
     * If a nullable field gets set to null, insert it here
     *
     * @var boolean[]
     */
    protected array $openAPINullablesSetToNull = [];

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @return array
     */
    public static function openAPITypes()
    {
        return self::$openAPITypes;
    }

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @return array
     */
    public static function openAPIFormats()
    {
        return self::$openAPIFormats;
    }

    /**
     * Array of nullable properties
     *
     * @return array
     */
    protected static function openAPINullables(): array
    {
        return self::$openAPINullables;
    }

    /**
     * Array of nullable field names deliberately set to null
     *
     * @return boolean[]
     */
    private function getOpenAPINullablesSetToNull(): array
    {
        return $this->openAPINullablesSetToNull;
    }

    /**
     * Setter - Array of nullable field names deliberately set to null
     *
     * @param boolean[] $openAPINullablesSetToNull
     */
    private function setOpenAPINullablesSetToNull(array $openAPINullablesSetToNull): void
    {
        $this->openAPINullablesSetToNull = $openAPINullablesSetToNull;
    }

    /**
     * Checks if a property is nullable
     *
     * @param string $property
     * @return bool
     */
    public static function isNullable(string $property): bool
    {
        return self::openAPINullables()[$property] ?? false;
    }

    /**
     * Checks if a nullable property is set to null.
     *
     * @param string $property
     * @return bool
     */
    public function isNullableSetToNull(string $property): bool
    {
        return in_array($property, $this->getOpenAPINullablesSetToNull(), true);
    }

    /**
     * Array of attributes where the key is the local name,
     * and the value is the original name
     *
     * @var string[]
     */
    protected static $attributeMap = [
        'shipping_profile_id' => 'shippingProfileId',
        'listing_source_id' => 'listingSourceId',
        'management_revision' => 'managementRevision',
        'terms_revision' => 'termsRevision',
        'lifecycle' => 'lifecycle',
        'content' => 'content'
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'shipping_profile_id' => 'setShippingProfileId',
        'listing_source_id' => 'setListingSourceId',
        'management_revision' => 'setManagementRevision',
        'terms_revision' => 'setTermsRevision',
        'lifecycle' => 'setLifecycle',
        'content' => 'setContent'
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'shipping_profile_id' => 'getShippingProfileId',
        'listing_source_id' => 'getListingSourceId',
        'management_revision' => 'getManagementRevision',
        'terms_revision' => 'getTermsRevision',
        'lifecycle' => 'getLifecycle',
        'content' => 'getContent'
    ];

    /**
     * Array of attributes where the key is the local name,
     * and the value is the original name
     *
     * @return array
     */
    public static function attributeMap()
    {
        return self::$attributeMap;
    }

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @return array
     */
    public static function setters()
    {
        return self::$setters;
    }

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @return array
     */
    public static function getters()
    {
        return self::$getters;
    }

    /**
     * The original name of the model.
     *
     * @return string
     */
    public function getModelName()
    {
        return self::$openAPIModelName;
    }

    public const LIFECYCLE_ACTIVE = 'ACTIVE';
    public const LIFECYCLE_RETIRED = 'RETIRED';

    /**
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getLifecycleAllowableValues()
    {
        return [
            self::LIFECYCLE_ACTIVE,
            self::LIFECYCLE_RETIRED,
        ];
    }

    /**
     * Associative array for storing property values
     *
     * @var mixed[]
     */
    protected $container = [];

    /**
     * Constructor
     *
     * @param mixed[]|null $data Associated array of property values
     *                      initializing the model
     */
    public function __construct(?array $data = null)
    {
        $this->setIfExists('shipping_profile_id', $data ?? [], null);
        $this->setIfExists('listing_source_id', $data ?? [], null);
        $this->setIfExists('management_revision', $data ?? [], null);
        $this->setIfExists('terms_revision', $data ?? [], null);
        $this->setIfExists('lifecycle', $data ?? [], null);
        $this->setIfExists('content', $data ?? [], null);
    }

    /**
     * Sets $this->container[$variableName] to the given data or to the given default Value; if $variableName
     * is nullable and its value is set to null in the $fields array, then mark it as "set to null" in the
     * $this->openAPINullablesSetToNull array
     *
     * @param string $variableName
     * @param array  $fields
     * @param mixed  $defaultValue
     */
    private function setIfExists(string $variableName, array $fields, $defaultValue): void
    {
        if (self::isNullable($variableName) && array_key_exists($variableName, $fields) && is_null($fields[$variableName])) {
            $this->openAPINullablesSetToNull[] = $variableName;
        }

        $this->container[$variableName] = $fields[$variableName] ?? $defaultValue;
    }

    /**
     * Show all the invalid properties with reasons.
     *
     * @return array invalid properties with reasons
     */
    public function listInvalidProperties()
    {
        $invalidProperties = [];

        if ($this->container['shipping_profile_id'] === null) {
            $invalidProperties[] = "'shipping_profile_id' can't be null";
        }
        if (!preg_match("/^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $this->container['shipping_profile_id'])) {
            $invalidProperties[] = "invalid value for 'shipping_profile_id', must be conform to the pattern /^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/.";
        }

        if ($this->container['listing_source_id'] === null) {
            $invalidProperties[] = "'listing_source_id' can't be null";
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $this->container['listing_source_id'])) {
            $invalidProperties[] = "invalid value for 'listing_source_id', must be conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.";
        }

        if ($this->container['management_revision'] === null) {
            $invalidProperties[] = "'management_revision' can't be null";
        }
        if (($this->container['management_revision'] > 9223372036854775807)) {
            $invalidProperties[] = "invalid value for 'management_revision', must be smaller than or equal to 9223372036854775807.";
        }

        if (($this->container['management_revision'] < 1)) {
            $invalidProperties[] = "invalid value for 'management_revision', must be bigger than or equal to 1.";
        }

        if ($this->container['terms_revision'] === null) {
            $invalidProperties[] = "'terms_revision' can't be null";
        }
        if (($this->container['terms_revision'] > 9223372036854775807)) {
            $invalidProperties[] = "invalid value for 'terms_revision', must be smaller than or equal to 9223372036854775807.";
        }

        if (($this->container['terms_revision'] < 1)) {
            $invalidProperties[] = "invalid value for 'terms_revision', must be bigger than or equal to 1.";
        }

        if ($this->container['lifecycle'] === null) {
            $invalidProperties[] = "'lifecycle' can't be null";
        }
        $allowedValues = $this->getLifecycleAllowableValues();
        if (!is_null($this->container['lifecycle']) && !in_array($this->container['lifecycle'], $allowedValues, true)) {
            $invalidProperties[] = sprintf(
                "invalid value '%s' for 'lifecycle', must be one of '%s'",
                $this->container['lifecycle'],
                implode("', '", $allowedValues)
            );
        }

        if ($this->container['content'] === null) {
            $invalidProperties[] = "'content' can't be null";
        }
        return $invalidProperties;
    }

    /**
     * Validate all the properties in the model
     * return true if all passed
     *
     * @return bool True if all properties are valid
     */
    public function valid()
    {
        return count($this->listInvalidProperties()) === 0;
    }


    /**
     * Gets shipping_profile_id
     *
     * @return string
     */
    public function getShippingProfileId()
    {
        return $this->container['shipping_profile_id'];
    }

    /**
     * Sets shipping_profile_id
     *
     * @param string $shipping_profile_id shipping_profile_id
     *
     * @return self
     */
    public function setShippingProfileId($shipping_profile_id)
    {
        if (is_null($shipping_profile_id)) {
            throw new \InvalidArgumentException('non-nullable shipping_profile_id cannot be null');
        }

        if ((!preg_match("/^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/", ObjectSerializer::toString($shipping_profile_id)))) {
            throw new \InvalidArgumentException("invalid value for \$shipping_profile_id when calling ShippingProfileData., must conform to the pattern /^shp_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        $this->container['shipping_profile_id'] = $shipping_profile_id;

        return $this;
    }

    /**
     * Gets listing_source_id
     *
     * @return string
     */
    public function getListingSourceId()
    {
        return $this->container['listing_source_id'];
    }

    /**
     * Sets listing_source_id
     *
     * @param string $listing_source_id listing_source_id
     *
     * @return self
     */
    public function setListingSourceId($listing_source_id)
    {
        if (is_null($listing_source_id)) {
            throw new \InvalidArgumentException('non-nullable listing_source_id cannot be null');
        }

        if ((!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", ObjectSerializer::toString($listing_source_id)))) {
            throw new \InvalidArgumentException("invalid value for \$listing_source_id when calling ShippingProfileData., must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        $this->container['listing_source_id'] = $listing_source_id;

        return $this;
    }

    /**
     * Gets management_revision
     *
     * @return int
     */
    public function getManagementRevision()
    {
        return $this->container['management_revision'];
    }

    /**
     * Sets management_revision
     *
     * @param int $management_revision management_revision
     *
     * @return self
     */
    public function setManagementRevision($management_revision)
    {
        if (is_null($management_revision)) {
            throw new \InvalidArgumentException('non-nullable management_revision cannot be null');
        }

        if (($management_revision > 9223372036854775807)) {
            throw new \InvalidArgumentException('invalid value for $management_revision when calling ShippingProfileData., must be smaller than or equal to 9223372036854775807.');
        }
        if (($management_revision < 1)) {
            throw new \InvalidArgumentException('invalid value for $management_revision when calling ShippingProfileData., must be bigger than or equal to 1.');
        }

        $this->container['management_revision'] = $management_revision;

        return $this;
    }

    /**
     * Gets terms_revision
     *
     * @return int
     */
    public function getTermsRevision()
    {
        return $this->container['terms_revision'];
    }

    /**
     * Sets terms_revision
     *
     * @param int $terms_revision terms_revision
     *
     * @return self
     */
    public function setTermsRevision($terms_revision)
    {
        if (is_null($terms_revision)) {
            throw new \InvalidArgumentException('non-nullable terms_revision cannot be null');
        }

        if (($terms_revision > 9223372036854775807)) {
            throw new \InvalidArgumentException('invalid value for $terms_revision when calling ShippingProfileData., must be smaller than or equal to 9223372036854775807.');
        }
        if (($terms_revision < 1)) {
            throw new \InvalidArgumentException('invalid value for $terms_revision when calling ShippingProfileData., must be bigger than or equal to 1.');
        }

        $this->container['terms_revision'] = $terms_revision;

        return $this;
    }

    /**
     * Gets lifecycle
     *
     * @return string
     */
    public function getLifecycle()
    {
        return $this->container['lifecycle'];
    }

    /**
     * Sets lifecycle
     *
     * @param string $lifecycle lifecycle
     *
     * @return self
     */
    public function setLifecycle($lifecycle)
    {
        if (is_null($lifecycle)) {
            throw new \InvalidArgumentException('non-nullable lifecycle cannot be null');
        }
        $allowedValues = $this->getLifecycleAllowableValues();
        if (!in_array($lifecycle, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value '%s' for 'lifecycle', must be one of '%s'",
                    $lifecycle,
                    implode("', '", $allowedValues)
                )
            );
        }
        $this->container['lifecycle'] = $lifecycle;

        return $this;
    }

    /**
     * Gets content
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileContentData
     */
    public function getContent()
    {
        return $this->container['content'];
    }

    /**
     * Sets content
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileContentData $content content
     *
     * @return self
     */
    public function setContent($content)
    {
        if (is_null($content)) {
            throw new \InvalidArgumentException('non-nullable content cannot be null');
        }
        $this->container['content'] = $content;

        return $this;
    }
    /**
     * Returns true if offset exists. False otherwise.
     *
     * @param integer|string $offset Offset
     *
     * @return boolean
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->container[$offset]);
    }

    /**
     * Gets offset.
     *
     * @param integer|string $offset Offset
     *
     * @return mixed|null
     */
    #[\ReturnTypeWillChange]
    public function offsetGet(mixed $offset)
    {
        return $this->container[$offset] ?? null;
    }

    /**
     * Sets value based on offset.
     *
     * @param int|null $offset Offset
     * @param mixed    $value  Value to be set
     *
     * @return void
     */
    public function offsetSet($offset, $value): void
    {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }

    /**
     * Unsets offset.
     *
     * @param integer|string $offset Offset
     *
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->container[$offset]);
    }

    /**
     * Serializes the object to a value that can be serialized natively by json_encode().
     * @link https://www.php.net/manual/en/jsonserializable.jsonserialize.php
     *
     * @return mixed Returns data which can be serialized by json_encode(), which is a value
     * of any type other than a resource.
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
       return ObjectSerializer::sanitizeForSerialization($this);
    }

    /**
     * Gets the string presentation of the object
     *
     * @return string
     */
    public function __toString()
    {
        return json_encode(
            ObjectSerializer::sanitizeForSerialization($this),
            JSON_PRETTY_PRINT
        );
    }

    /**
     * Gets a header-safe presentation of the object
     *
     * @return string
     */
    public function toHeaderValue()
    {
        return json_encode(ObjectSerializer::sanitizeForSerialization($this));
    }
}
