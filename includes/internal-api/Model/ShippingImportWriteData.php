<?php
/**
 * ShippingImportWriteData
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
 * ShippingImportWriteData Class Doc Comment
 *
 * @category Class
 * @description Optional import semantics on existing commands. objectKey is required only on profile creation; it identifies a stable source/producer/integration object independently of requestKey. sequence increases per touched component within the current source lifecycle; it is producer acquisition order, never receive time. Omitted observation does not renew observation time. Creation, changed normalized profile terms, changed default assignment and changed listing assertions and first claims of equal implicit selections require observation; explicit owned-component removals do not renew observation time. Acquisition ordering uses microsecond precision. Equal terms with observation explicitly refresh acquisition evidence without semantic revision changes. Exact requestKey replay returns current committed state without effects for 90 days; changed reuse conflicts permanently. Current source/grant/integration authority is rechecked even on replay. Imports cannot modify manually owned components. 409 codes: SHIPPING_IMPORT_AUTHORITY_STALE, SHIPPING_IMPORT_OWNERSHIP_CONFLICT, SHIPPING_IMPORT_REQUEST_CONFLICT, SHIPPING_IMPORT_OUT_OF_ORDER, SHIPPING_IMPORT_IDENTITY_EXISTS. Refetch and reconcile conflicts; never rebase an old payload onto the newest revision. Missing catalog listing is retryable 404 SHIPPING_IMPORT_DEPENDENCY_MISSING; retain the record until catalog creation commits. No shipping write creates or restores catalog products.
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 * @implements \ArrayAccess<string, mixed>
 */
class ShippingImportWriteData implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'ShippingImportWriteData';

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'acquired_at' => '\DateTime',
        'integration' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryIntegrationData',
        'sequence' => 'int',
        'request_key' => 'string',
        'object_key' => 'string',
        'observation' => 'object'
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'acquired_at' => 'date-time',
        'integration' => null,
        'sequence' => null,
        'request_key' => null,
        'object_key' => null,
        'observation' => null
    ];

    /**
     * Array of nullable properties. Used for (de)serialization
     *
     * @var boolean[]
     */
    protected static array $openAPINullables = [
        'acquired_at' => false,
        'integration' => false,
        'sequence' => false,
        'request_key' => false,
        'object_key' => true,
        'observation' => true
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
        'acquired_at' => 'acquiredAt',
        'integration' => 'integration',
        'sequence' => 'sequence',
        'request_key' => 'requestKey',
        'object_key' => 'objectKey',
        'observation' => 'observation'
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'acquired_at' => 'setAcquiredAt',
        'integration' => 'setIntegration',
        'sequence' => 'setSequence',
        'request_key' => 'setRequestKey',
        'object_key' => 'setObjectKey',
        'observation' => 'setObservation'
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'acquired_at' => 'getAcquiredAt',
        'integration' => 'getIntegration',
        'sequence' => 'getSequence',
        'request_key' => 'getRequestKey',
        'object_key' => 'getObjectKey',
        'observation' => 'getObservation'
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
        $this->setIfExists('acquired_at', $data ?? [], null);
        $this->setIfExists('integration', $data ?? [], null);
        $this->setIfExists('sequence', $data ?? [], null);
        $this->setIfExists('request_key', $data ?? [], null);
        $this->setIfExists('object_key', $data ?? [], null);
        $this->setIfExists('observation', $data ?? [], null);
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

        if ($this->container['acquired_at'] === null) {
            $invalidProperties[] = "'acquired_at' can't be null";
        }
        if ($this->container['integration'] === null) {
            $invalidProperties[] = "'integration' can't be null";
        }
        if ($this->container['sequence'] === null) {
            $invalidProperties[] = "'sequence' can't be null";
        }
        if (($this->container['sequence'] > 9223372036854775807)) {
            $invalidProperties[] = "invalid value for 'sequence', must be smaller than or equal to 9223372036854775807.";
        }

        if (($this->container['sequence'] < 1)) {
            $invalidProperties[] = "invalid value for 'sequence', must be bigger than or equal to 1.";
        }

        if ($this->container['request_key'] === null) {
            $invalidProperties[] = "'request_key' can't be null";
        }
        if ((mb_strlen($this->container['request_key']) > 128)) {
            $invalidProperties[] = "invalid value for 'request_key', the character length must be smaller than or equal to 128.";
        }

        if ((mb_strlen($this->container['request_key']) < 1)) {
            $invalidProperties[] = "invalid value for 'request_key', the character length must be bigger than or equal to 1.";
        }

        if (!preg_match("/^[!-~]+$/", $this->container['request_key'])) {
            $invalidProperties[] = "invalid value for 'request_key', must be conform to the pattern /^[!-~]+$/.";
        }

        if (!is_null($this->container['object_key']) && (mb_strlen($this->container['object_key']) > 128)) {
            $invalidProperties[] = "invalid value for 'object_key', the character length must be smaller than or equal to 128.";
        }

        if (!is_null($this->container['object_key']) && (mb_strlen($this->container['object_key']) < 1)) {
            $invalidProperties[] = "invalid value for 'object_key', the character length must be bigger than or equal to 1.";
        }

        if (!is_null($this->container['object_key']) && !preg_match("/^[!-~]+$/", $this->container['object_key'])) {
            $invalidProperties[] = "invalid value for 'object_key', must be conform to the pattern /^[!-~]+$/.";
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
     * Gets acquired_at
     *
     * @return \DateTime
     */
    public function getAcquiredAt()
    {
        return $this->container['acquired_at'];
    }

    /**
     * Sets acquired_at
     *
     * @param \DateTime $acquired_at Successful acquisition time for every command, including removal/correction; must follow the latest source/integration authority change.
     *
     * @return self
     */
    public function setAcquiredAt($acquired_at)
    {
        if (is_null($acquired_at)) {
            throw new \InvalidArgumentException('non-nullable acquired_at cannot be null');
        }
        $this->container['acquired_at'] = $acquired_at;

        return $this;
    }

    /**
     * Gets integration
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryIntegrationData
     */
    public function getIntegration()
    {
        return $this->container['integration'];
    }

    /**
     * Sets integration
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryIntegrationData $integration integration
     *
     * @return self
     */
    public function setIntegration($integration)
    {
        if (is_null($integration)) {
            throw new \InvalidArgumentException('non-nullable integration cannot be null');
        }
        $this->container['integration'] = $integration;

        return $this;
    }

    /**
     * Gets sequence
     *
     * @return int
     */
    public function getSequence()
    {
        return $this->container['sequence'];
    }

    /**
     * Sets sequence
     *
     * @param int $sequence sequence
     *
     * @return self
     */
    public function setSequence($sequence)
    {
        if (is_null($sequence)) {
            throw new \InvalidArgumentException('non-nullable sequence cannot be null');
        }

        if (($sequence > 9223372036854775807)) {
            throw new \InvalidArgumentException('invalid value for $sequence when calling ShippingImportWriteData., must be smaller than or equal to 9223372036854775807.');
        }
        if (($sequence < 1)) {
            throw new \InvalidArgumentException('invalid value for $sequence when calling ShippingImportWriteData., must be bigger than or equal to 1.');
        }

        $this->container['sequence'] = $sequence;

        return $this;
    }

    /**
     * Gets request_key
     *
     * @return string
     */
    public function getRequestKey()
    {
        return $this->container['request_key'];
    }

    /**
     * Sets request_key
     *
     * @param string $request_key request_key
     *
     * @return self
     */
    public function setRequestKey($request_key)
    {
        if (is_null($request_key)) {
            throw new \InvalidArgumentException('non-nullable request_key cannot be null');
        }
        if ((mb_strlen($request_key) > 128)) {
            throw new \InvalidArgumentException('invalid length for $request_key when calling ShippingImportWriteData., must be smaller than or equal to 128.');
        }
        if ((mb_strlen($request_key) < 1)) {
            throw new \InvalidArgumentException('invalid length for $request_key when calling ShippingImportWriteData., must be bigger than or equal to 1.');
        }
        if ((!preg_match("/^[!-~]+$/", ObjectSerializer::toString($request_key)))) {
            throw new \InvalidArgumentException("invalid value for \$request_key when calling ShippingImportWriteData., must conform to the pattern /^[!-~]+$/.");
        }

        $this->container['request_key'] = $request_key;

        return $this;
    }

    /**
     * Gets object_key
     *
     * @return string|null
     */
    public function getObjectKey()
    {
        return $this->container['object_key'];
    }

    /**
     * Sets object_key
     *
     * @param string|null $object_key object_key
     *
     * @return self
     */
    public function setObjectKey($object_key)
    {
        if (is_null($object_key)) {
            array_push($this->openAPINullablesSetToNull, 'object_key');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('object_key', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        if (!is_null($object_key) && (mb_strlen($object_key) > 128)) {
            throw new \InvalidArgumentException('invalid length for $object_key when calling ShippingImportWriteData., must be smaller than or equal to 128.');
        }
        if (!is_null($object_key) && (mb_strlen($object_key) < 1)) {
            throw new \InvalidArgumentException('invalid length for $object_key when calling ShippingImportWriteData., must be bigger than or equal to 1.');
        }
        if (!is_null($object_key) && (!preg_match("/^[!-~]+$/", ObjectSerializer::toString($object_key)))) {
            throw new \InvalidArgumentException("invalid value for \$object_key when calling ShippingImportWriteData., must conform to the pattern /^[!-~]+$/.");
        }

        $this->container['object_key'] = $object_key;

        return $this;
    }

    /**
     * Gets observation
     *
     * @return object|null
     */
    public function getObservation()
    {
        return $this->container['observation'];
    }

    /**
     * Sets observation
     *
     * @param object|null $observation Explicit successful new acquisition marker (empty object). Uses the parent acquiredAt, which cannot be in the future. Shipping assertions have no freshness or expiry deadline. Replays and ordinary writes without observation never advance observation time. Evidence-only corrections and lifecycle/removal commands cannot supply observation.
     *
     * @return self
     */
    public function setObservation($observation)
    {
        if (is_null($observation)) {
            array_push($this->openAPINullablesSetToNull, 'observation');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('observation', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['observation'] = $observation;

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
