<?php
/**
 * ShippingRateData
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
 * ShippingRateData Class Doc Comment
 *
 * @category Class
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 * @implements \ArrayAccess<string, mixed>
 */
class ShippingRateData implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'ShippingRateData';

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'price' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingPriceData',
        'basis' => 'string',
        'qualifying_scope' => 'string',
        'components' => 'array<string,string>'
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'price' => null,
        'basis' => null,
        'qualifying_scope' => null,
        'components' => null
    ];

    /**
     * Array of nullable properties. Used for (de)serialization
     *
     * @var boolean[]
     */
    protected static array $openAPINullables = [
        'price' => false,
        'basis' => true,
        'qualifying_scope' => true,
        'components' => false
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
        'price' => 'price',
        'basis' => 'basis',
        'qualifying_scope' => 'qualifying_scope',
        'components' => 'components'
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'price' => 'setPrice',
        'basis' => 'setBasis',
        'qualifying_scope' => 'setQualifyingScope',
        'components' => 'setComponents'
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'price' => 'getPrice',
        'basis' => 'getBasis',
        'qualifying_scope' => 'getQualifyingScope',
        'components' => 'getComponents'
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

    public const BASIS_PER_ITEM = 'PER_ITEM';
    public const BASIS_PER_LOT = 'PER_LOT';
    public const BASIS_PER_SHIPMENT = 'PER_SHIPMENT';
    public const BASIS_PER_SELLER_ORDER = 'PER_SELLER_ORDER';
    public const QUALIFYING_SCOPE_ITEM = 'ITEM';
    public const QUALIFYING_SCOPE_LOT = 'LOT';
    public const QUALIFYING_SCOPE_SHIPMENT = 'SHIPMENT';
    public const QUALIFYING_SCOPE_SELLER_ORDER = 'SELLER_ORDER';
    public const COMPONENTS_INCLUDED = 'INCLUDED';
    public const COMPONENTS_EXCLUDED = 'EXCLUDED';

    /**
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getBasisAllowableValues()
    {
        return [
            self::BASIS_PER_ITEM,
            self::BASIS_PER_LOT,
            self::BASIS_PER_SHIPMENT,
            self::BASIS_PER_SELLER_ORDER,
        ];
    }

    /**
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getQualifyingScopeAllowableValues()
    {
        return [
            self::QUALIFYING_SCOPE_ITEM,
            self::QUALIFYING_SCOPE_LOT,
            self::QUALIFYING_SCOPE_SHIPMENT,
            self::QUALIFYING_SCOPE_SELLER_ORDER,
        ];
    }

    /**
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getComponentsAllowableValues()
    {
        return [
            self::COMPONENTS_INCLUDED,
            self::COMPONENTS_EXCLUDED,
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
        $this->setIfExists('price', $data ?? [], null);
        $this->setIfExists('basis', $data ?? [], null);
        $this->setIfExists('qualifying_scope', $data ?? [], null);
        $this->setIfExists('components', $data ?? [], null);
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

        if ($this->container['price'] === null) {
            $invalidProperties[] = "'price' can't be null";
        }
        if ($this->container['basis'] === null && !$this->isNullableSetToNull('basis')) {
            $invalidProperties[] = "'basis' is required";
        }
        $allowedValues = $this->getBasisAllowableValues();
        if (!is_null($this->container['basis']) && !in_array($this->container['basis'], $allowedValues, true)) {
            $invalidProperties[] = sprintf(
                "invalid value '%s' for 'basis', must be one of '%s'",
                $this->container['basis'],
                implode("', '", $allowedValues)
            );
        }

        if ($this->container['qualifying_scope'] === null && !$this->isNullableSetToNull('qualifying_scope')) {
            $invalidProperties[] = "'qualifying_scope' is required";
        }
        $allowedValues = $this->getQualifyingScopeAllowableValues();
        if (!is_null($this->container['qualifying_scope']) && !in_array($this->container['qualifying_scope'], $allowedValues, true)) {
            $invalidProperties[] = sprintf(
                "invalid value '%s' for 'qualifying_scope', must be one of '%s'",
                $this->container['qualifying_scope'],
                implode("', '", $allowedValues)
            );
        }

        if ($this->container['components'] === null) {
            $invalidProperties[] = "'components' can't be null";
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
     * Gets price
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingPriceData
     */
    public function getPrice()
    {
        return $this->container['price'];
    }

    /**
     * Sets price
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingPriceData $price price
     *
     * @return self
     */
    public function setPrice($price)
    {
        if (is_null($price)) {
            throw new \InvalidArgumentException('non-nullable price cannot be null');
        }
        $this->container['price'] = $price;

        return $this;
    }

    /**
     * Gets basis
     *
     * @return string|null
     */
    public function getBasis()
    {
        return $this->container['basis'];
    }

    /**
     * Sets basis
     *
     * @param string|null $basis basis
     *
     * @return self
     */
    public function setBasis($basis)
    {
        if (is_null($basis)) {
            array_push($this->openAPINullablesSetToNull, 'basis');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('basis', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $allowedValues = $this->getBasisAllowableValues();
        if (!is_null($basis) && !in_array($basis, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value '%s' for 'basis', must be one of '%s'",
                    $basis,
                    implode("', '", $allowedValues)
                )
            );
        }
        $this->container['basis'] = $basis;

        return $this;
    }

    /**
     * Gets qualifying_scope
     *
     * @return string|null
     */
    public function getQualifyingScope()
    {
        return $this->container['qualifying_scope'];
    }

    /**
     * Sets qualifying_scope
     *
     * @param string|null $qualifying_scope qualifying_scope
     *
     * @return self
     */
    public function setQualifyingScope($qualifying_scope)
    {
        if (is_null($qualifying_scope)) {
            array_push($this->openAPINullablesSetToNull, 'qualifying_scope');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('qualifying_scope', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $allowedValues = $this->getQualifyingScopeAllowableValues();
        if (!is_null($qualifying_scope) && !in_array($qualifying_scope, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value '%s' for 'qualifying_scope', must be one of '%s'",
                    $qualifying_scope,
                    implode("', '", $allowedValues)
                )
            );
        }
        $this->container['qualifying_scope'] = $qualifying_scope;

        return $this;
    }

    /**
     * Gets components
     *
     * @return array<string,string>
     */
    public function getComponents()
    {
        return $this->container['components'];
    }

    /**
     * Sets components
     *
     * @param array<string,string> $components Keys: TRANSPORT, PACKING, INSURANCE, HANDLING, INSTALLATION, IMPORT_DUTY, TAX. Unknown keys are rejected.
     *
     * @return self
     */
    public function setComponents($components)
    {
        if (is_null($components)) {
            throw new \InvalidArgumentException('non-nullable components cannot be null');
        }
        $allowedValues = $this->getComponentsAllowableValues();
        if (array_diff($components, $allowedValues)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value for 'components', must be one of '%s'",
                    implode("', '", $allowedValues)
                )
            );
        }
        $this->container['components'] = $components;

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
