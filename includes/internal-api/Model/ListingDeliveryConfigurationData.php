<?php
/**
 * ListingDeliveryConfigurationData
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
 * ## Overview  The Aura Historia API powers the Aura Historia antiques platform and its partner-facing integrations. It exposes product discovery, listing source discovery, personalization, partner onboarding, and integration workflows on top of Aura Historia's serverless AWS platform.  This reference is designed for two audiences: - **Internal developers** building Aura Historia product-listings, operational tooling, and backoffice workflows - **External partners and integrators** synchronizing inventory, consuming platform data, or   integrating with delegated access  ## Core API domains  - **ProductListings and listing-sources** — search, retrieve, and explore product-listings, related product-listings, product   history, and listing source data - **Personalization** — manage watchlists, saved search filters, and user notifications - **Partner workflows** — submit partnership applications, maintain partnerships, ingest catalog data   in batches, and receive WooCommerce webhooks - **Identity and access** — manage user accounts, Aura Historia access tokens, and OAuth 2.0   clients and token flows - **Billing** — create Stripe checkout, billing-portal, and subscription-management sessions  ## Authentication model  The API uses more than one authentication scheme depending on the route:  - **`BearerAuth`** — Cognito **access** JWT bearer tokens for authenticated user, admin, and   partner-user workflows. Cognito ID tokens are not accepted as API credentials. - **`AccessTokenAuth`** — Aura Historia opaque bearer access tokens created via   `/api/v1/me/access-tokens` or OAuth 2.0, primarily intended for partner automations and server-to-server   ingestion scenarios.  When a route lists multiple security schemes, any one of the listed schemes may be accepted. Some read endpoints can also be called without authentication, while a valid user token may enrich the response with personalized state such as watchlist, notification, or search-filter metadata.  ## Data and response conventions  - **PATCH:** an omitted request member remains unchanged. A documented nullable PATCH member   accepts `null` to clear its value; `null` for any other member returns `400 BAD_BODY_VALUE`.   Use `[]`, not `null`, to replace a non-null collection with an empty collection. Empty HTTP   bodies are invalid, `{}` is a valid object-PATCH no-op, and the partner-product PATCH `[]`   remains a valid empty batch. Responses may omit absent optional values. - Product detail, watchlist, and saved-search match reads accept optional **`language`** and   **`currency`** parameters. Currency defaults to `EUR`. Detail pricing contains the seller's source   amounts, converted display amounts, and FX valuation metadata. Active product-listings use the latest persisted   snapshot; sold product-listings use their immutable sale snapshot. Product history preserves immutable source values and accepts optional `currency` for historical FX display projections.   History text is not localized. - Request-wide errors are returned as **`application/problem+json`** using a consistent RFC 9457-style structure   with `status`, `title`, `error`, optional `source`, and optional `detail` - Many list and search endpoints use **cursor-based pagination** via `searchAfter` - Origin API responses include server-generated `X-Request-Id` and `X-Correlation-Id` headers. Clients may send `X-Correlation-Id` to preserve a trace only when it is a 1–128-character ASCII value containing letters, digits, `.`, `_`, or `-`; invalid values are replaced. Both headers are CORS-exposed; CloudFront removes them from viewer responses on the six cache-enabled discovery behaviors so shared cache hits cannot replay origin IDs. - Requests are limited to 1 MiB and time out after 30 seconds. - `POST /api/v1/webhooks/loops` is limited to 64 KiB and has a 10-second processing budget; it requires a valid Loops signature but no Aura bearer. - `GET /api/v1/health` is a process liveness check. `GET /api/v1/ready` returns `204` only when the configured PostgreSQL and OpenSearch dependencies are reachable; it otherwise returns `503`. - WooCommerce webhook ingestion responds with **`204 No Content`** after signed validation. A mapped raw-capture command receives `204` only after confirmed shared FIFO admission; authorized ignored create/update status events are no-op `204`. Queue admission is not raw capture, provider receipt persistence, canonical normalization, or search visibility. Capture conflicts are handled by the consumer, not returned synchronously; existing partner Product batch writes remain synchronous.  ## Environment endpoints  - **Development:** `https://api.stage.aura-historia.com` - **Production:** `https://api.aura-historia.com`  ## Notes for integrators  - Treat Aura Historia identifiers such as `listingSourceId`, `productListingId`, `eventId`, `userSearchFilterId`,   and `partnershipApplicationId` as opaque values - For partner product ingestion, `sourceListingId` is the partner-controlled identifier and should   remain stable within a listing source - Endpoints marked `deprecated` or `x-disabled` are retained for reference only and should not be   used for new integrations
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
 * ListingDeliveryConfigurationData Class Doc Comment
 *
 * @category Class
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 * @implements \ArrayAccess<string, mixed>
 */
class ListingDeliveryConfigurationData implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'ListingDeliveryConfigurationData';

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'schema' => 'string',
        'source_id' => 'string',
        'integration' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryIntegrationData',
        'selection' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliverySelectionData',
        'rate_inputs' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryRateInputsData',
        'shipping' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryShippingOverrideData',
        'collection' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryCollectionOverrideData'
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'schema' => null,
        'source_id' => null,
        'integration' => null,
        'selection' => null,
        'rate_inputs' => null,
        'shipping' => null,
        'collection' => null
    ];

    /**
     * Array of nullable properties. Used for (de)serialization
     *
     * @var boolean[]
     */
    protected static array $openAPINullables = [
        'schema' => false,
        'source_id' => false,
        'integration' => false,
        'selection' => true,
        'rate_inputs' => false,
        'shipping' => false,
        'collection' => false
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
        'schema' => 'schema',
        'source_id' => 'source_id',
        'integration' => 'integration',
        'selection' => 'selection',
        'rate_inputs' => 'rate_inputs',
        'shipping' => 'shipping',
        'collection' => 'collection'
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'schema' => 'setSchema',
        'source_id' => 'setSourceId',
        'integration' => 'setIntegration',
        'selection' => 'setSelection',
        'rate_inputs' => 'setRateInputs',
        'shipping' => 'setShipping',
        'collection' => 'setCollection'
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'schema' => 'getSchema',
        'source_id' => 'getSourceId',
        'integration' => 'getIntegration',
        'selection' => 'getSelection',
        'rate_inputs' => 'getRateInputs',
        'shipping' => 'getShipping',
        'collection' => 'getCollection'
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

    public const SCHEMA_LISTING_DELIVERY_V1 = 'LISTING_DELIVERY_V1';

    /**
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getSchemaAllowableValues()
    {
        return [
            self::SCHEMA_LISTING_DELIVERY_V1,
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
        $this->setIfExists('schema', $data ?? [], null);
        $this->setIfExists('source_id', $data ?? [], null);
        $this->setIfExists('integration', $data ?? [], null);
        $this->setIfExists('selection', $data ?? [], null);
        $this->setIfExists('rate_inputs', $data ?? [], null);
        $this->setIfExists('shipping', $data ?? [], null);
        $this->setIfExists('collection', $data ?? [], null);
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

        if ($this->container['schema'] === null) {
            $invalidProperties[] = "'schema' can't be null";
        }
        $allowedValues = $this->getSchemaAllowableValues();
        if (!is_null($this->container['schema']) && !in_array($this->container['schema'], $allowedValues, true)) {
            $invalidProperties[] = sprintf(
                "invalid value '%s' for 'schema', must be one of '%s'",
                $this->container['schema'],
                implode("', '", $allowedValues)
            );
        }

        if ($this->container['source_id'] === null) {
            $invalidProperties[] = "'source_id' can't be null";
        }
        if (!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", $this->container['source_id'])) {
            $invalidProperties[] = "invalid value for 'source_id', must be conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.";
        }

        if ($this->container['integration'] === null) {
            $invalidProperties[] = "'integration' can't be null";
        }
        if ($this->container['selection'] === null && !$this->isNullableSetToNull('selection')) {
            $invalidProperties[] = "'selection' is required";
        }
        if ($this->container['rate_inputs'] === null) {
            $invalidProperties[] = "'rate_inputs' can't be null";
        }
        if ($this->container['shipping'] === null) {
            $invalidProperties[] = "'shipping' can't be null";
        }
        if ($this->container['collection'] === null) {
            $invalidProperties[] = "'collection' can't be null";
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
     * Gets schema
     *
     * @return string
     */
    public function getSchema()
    {
        return $this->container['schema'];
    }

    /**
     * Sets schema
     *
     * @param string $schema schema
     *
     * @return self
     */
    public function setSchema($schema)
    {
        if (is_null($schema)) {
            throw new \InvalidArgumentException('non-nullable schema cannot be null');
        }
        $allowedValues = $this->getSchemaAllowableValues();
        if (!in_array($schema, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value '%s' for 'schema', must be one of '%s'",
                    $schema,
                    implode("', '", $allowedValues)
                )
            );
        }
        $this->container['schema'] = $schema;

        return $this;
    }

    /**
     * Gets source_id
     *
     * @return string
     */
    public function getSourceId()
    {
        return $this->container['source_id'];
    }

    /**
     * Sets source_id
     *
     * @param string $source_id source_id
     *
     * @return self
     */
    public function setSourceId($source_id)
    {
        if (is_null($source_id)) {
            throw new \InvalidArgumentException('non-nullable source_id cannot be null');
        }

        if ((!preg_match("/^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/", ObjectSerializer::toString($source_id)))) {
            throw new \InvalidArgumentException("invalid value for \$source_id when calling ListingDeliveryConfigurationData., must conform to the pattern /^ls_[0-7][0-9a-hjkmnp-tv-z]{25}$/.");
        }

        $this->container['source_id'] = $source_id;

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
     * Gets selection
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliverySelectionData|null
     */
    public function getSelection()
    {
        return $this->container['selection'];
    }

    /**
     * Sets selection
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliverySelectionData|null $selection selection
     *
     * @return self
     */
    public function setSelection($selection)
    {
        if (is_null($selection)) {
            array_push($this->openAPINullablesSetToNull, 'selection');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('selection', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['selection'] = $selection;

        return $this;
    }

    /**
     * Gets rate_inputs
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryRateInputsData
     */
    public function getRateInputs()
    {
        return $this->container['rate_inputs'];
    }

    /**
     * Sets rate_inputs
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryRateInputsData $rate_inputs rate_inputs
     *
     * @return self
     */
    public function setRateInputs($rate_inputs)
    {
        if (is_null($rate_inputs)) {
            throw new \InvalidArgumentException('non-nullable rate_inputs cannot be null');
        }
        $this->container['rate_inputs'] = $rate_inputs;

        return $this;
    }

    /**
     * Gets shipping
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryShippingOverrideData
     */
    public function getShipping()
    {
        return $this->container['shipping'];
    }

    /**
     * Sets shipping
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryShippingOverrideData $shipping shipping
     *
     * @return self
     */
    public function setShipping($shipping)
    {
        if (is_null($shipping)) {
            throw new \InvalidArgumentException('non-nullable shipping cannot be null');
        }
        $this->container['shipping'] = $shipping;

        return $this;
    }

    /**
     * Gets collection
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryCollectionOverrideData
     */
    public function getCollection()
    {
        return $this->container['collection'];
    }

    /**
     * Sets collection
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ListingDeliveryCollectionOverrideData $collection collection
     *
     * @return self
     */
    public function setCollection($collection)
    {
        if (is_null($collection)) {
            throw new \InvalidArgumentException('non-nullable collection cannot be null');
        }
        $this->container['collection'] = $collection;

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
