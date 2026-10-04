<?php
/**
 * ProductListingAuctionData
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
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped


namespace AuraHistoria\PartnerConnect\InternalApi\Model;

use \ArrayAccess;
use \AuraHistoria\PartnerConnect\InternalApi\ObjectSerializer;

/**
 * ProductListingAuctionData Class Doc Comment
 *
 * @category Class
 * @description Partner ProductListing nested write patch. &#x60;auctionId&#x60; must identify an existing Auction for the same ListingSource. Omit it to preserve membership, send null to clear membership, or send a value to set it. Omit a lot/timing leaf to preserve it, send null to clear a clearable leaf, or send a value to set it.
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 * @implements \ArrayAccess<string, mixed>
 */
class ProductListingAuctionData implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'ProductListingAuctionData';

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'auction_id' => 'string',
        'lot_number' => 'string',
        'catalogue_position' => 'int',
        'timing' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingAuctionTimesData'
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'auction_id' => null,
        'lot_number' => null,
        'catalogue_position' => 'int64',
        'timing' => null
    ];

    /**
     * Array of nullable properties. Used for (de)serialization
     *
     * @var boolean[]
     */
    protected static array $openAPINullables = [
        'auction_id' => true,
        'lot_number' => true,
        'catalogue_position' => true,
        'timing' => true
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
        'auction_id' => 'auctionId',
        'lot_number' => 'lotNumber',
        'catalogue_position' => 'cataloguePosition',
        'timing' => 'timing'
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'auction_id' => 'setAuctionId',
        'lot_number' => 'setLotNumber',
        'catalogue_position' => 'setCataloguePosition',
        'timing' => 'setTiming'
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'auction_id' => 'getAuctionId',
        'lot_number' => 'getLotNumber',
        'catalogue_position' => 'getCataloguePosition',
        'timing' => 'getTiming'
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
        $this->setIfExists('auction_id', $data ?? [], null);
        $this->setIfExists('lot_number', $data ?? [], null);
        $this->setIfExists('catalogue_position', $data ?? [], null);
        $this->setIfExists('timing', $data ?? [], null);
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

        if (!is_null($this->container['lot_number']) && (mb_strlen($this->container['lot_number']) > 128)) {
            $invalidProperties[] = "invalid value for 'lot_number', the character length must be smaller than or equal to 128.";
        }

        if (!is_null($this->container['catalogue_position']) && ($this->container['catalogue_position'] > 4294967295)) {
            $invalidProperties[] = "invalid value for 'catalogue_position', must be smaller than or equal to 4294967295.";
        }

        if (!is_null($this->container['catalogue_position']) && ($this->container['catalogue_position'] < 1)) {
            $invalidProperties[] = "invalid value for 'catalogue_position', must be bigger than or equal to 1.";
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
     * Gets auction_id
     *
     * @return string|null
     */
    public function getAuctionId()
    {
        return $this->container['auction_id'];
    }

    /**
     * Sets auction_id
     *
     * @param string|null $auction_id Existing same-ListingSource Aura Auction TypeID. Omit to preserve membership; send null to clear it.
     *
     * @return self
     */
    public function setAuctionId($auction_id)
    {
        if (is_null($auction_id)) {
            array_push($this->openAPINullablesSetToNull, 'auction_id');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('auction_id', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['auction_id'] = $auction_id;

        return $this;
    }

    /**
     * Gets lot_number
     *
     * @return string|null
     */
    public function getLotNumber()
    {
        return $this->container['lot_number'];
    }

    /**
     * Sets lot_number
     *
     * @param string|null $lot_number Omit to preserve the lot label; send null to clear it; send a value to set it.
     *
     * @return self
     */
    public function setLotNumber($lot_number)
    {
        if (is_null($lot_number)) {
            array_push($this->openAPINullablesSetToNull, 'lot_number');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('lot_number', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        if (!is_null($lot_number) && (mb_strlen($lot_number) > 128)) {
            throw new \InvalidArgumentException('invalid length for $lot_number when calling ProductListingAuctionData., must be smaller than or equal to 128.');
        }

        $this->container['lot_number'] = $lot_number;

        return $this;
    }

    /**
     * Gets catalogue_position
     *
     * @return int|null
     */
    public function getCataloguePosition()
    {
        return $this->container['catalogue_position'];
    }

    /**
     * Sets catalogue_position
     *
     * @param int|null $catalogue_position Omit to preserve the catalogue position; send null to clear it; send a value to set it.
     *
     * @return self
     */
    public function setCataloguePosition($catalogue_position)
    {
        if (is_null($catalogue_position)) {
            array_push($this->openAPINullablesSetToNull, 'catalogue_position');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('catalogue_position', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }

        if (!is_null($catalogue_position) && ($catalogue_position > 4294967295)) {
            throw new \InvalidArgumentException('invalid value for $catalogue_position when calling ProductListingAuctionData., must be smaller than or equal to 4294967295.');
        }
        if (!is_null($catalogue_position) && ($catalogue_position < 1)) {
            throw new \InvalidArgumentException('invalid value for $catalogue_position when calling ProductListingAuctionData., must be bigger than or equal to 1.');
        }

        $this->container['catalogue_position'] = $catalogue_position;

        return $this;
    }

    /**
     * Gets timing
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingAuctionTimesData|null
     */
    public function getTiming()
    {
        return $this->container['timing'];
    }

    /**
     * Sets timing
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingAuctionTimesData|null $timing timing
     *
     * @return self
     */
    public function setTiming($timing)
    {
        if (is_null($timing)) {
            array_push($this->openAPINullablesSetToNull, 'timing');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('timing', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['timing'] = $timing;

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
