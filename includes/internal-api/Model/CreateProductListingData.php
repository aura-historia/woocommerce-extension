<?php
/**
 * CreateProductListingData
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
 * CreateProductListingData Class Doc Comment
 *
 * @category Class
 * @description Data for creating a single product via the partner batch-create endpoint.
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 * @implements \ArrayAccess<string, mixed>
 */
class CreateProductListingData implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'CreateProductListingData';

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'source_listing_id' => 'string',
        'title' => '\AuraHistoria\PartnerConnect\InternalApi\Model\LocalizedTextData',
        'description' => '\AuraHistoria\PartnerConnect\InternalApi\Model\LocalizedTextData',
        'price' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingPriceData',
        'price_estimate_min' => '\AuraHistoria\PartnerConnect\InternalApi\Model\PriceData',
        'price_estimate_max' => '\AuraHistoria\PartnerConnect\InternalApi\Model\PriceData',
        'availability' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ListingAvailabilityData',
        'url' => 'string',
        'images' => 'string[]',
        'auction' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingAuctionData'
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'source_listing_id' => null,
        'title' => null,
        'description' => null,
        'price' => null,
        'price_estimate_min' => null,
        'price_estimate_max' => null,
        'availability' => null,
        'url' => 'uri',
        'images' => 'uri',
        'auction' => null
    ];

    /**
     * Array of nullable properties. Used for (de)serialization
     *
     * @var boolean[]
     */
    protected static array $openAPINullables = [
        'source_listing_id' => false,
        'title' => true,
        'description' => true,
        'price' => true,
        'price_estimate_min' => true,
        'price_estimate_max' => true,
        'availability' => true,
        'url' => false,
        'images' => false,
        'auction' => false
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
        'source_listing_id' => 'sourceListingId',
        'title' => 'title',
        'description' => 'description',
        'price' => 'price',
        'price_estimate_min' => 'priceEstimateMin',
        'price_estimate_max' => 'priceEstimateMax',
        'availability' => 'availability',
        'url' => 'url',
        'images' => 'images',
        'auction' => 'auction'
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'source_listing_id' => 'setSourceListingId',
        'title' => 'setTitle',
        'description' => 'setDescription',
        'price' => 'setPrice',
        'price_estimate_min' => 'setPriceEstimateMin',
        'price_estimate_max' => 'setPriceEstimateMax',
        'availability' => 'setAvailability',
        'url' => 'setUrl',
        'images' => 'setImages',
        'auction' => 'setAuction'
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'source_listing_id' => 'getSourceListingId',
        'title' => 'getTitle',
        'description' => 'getDescription',
        'price' => 'getPrice',
        'price_estimate_min' => 'getPriceEstimateMin',
        'price_estimate_max' => 'getPriceEstimateMax',
        'availability' => 'getAvailability',
        'url' => 'getUrl',
        'images' => 'getImages',
        'auction' => 'getAuction'
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
        $this->setIfExists('source_listing_id', $data ?? [], null);
        $this->setIfExists('title', $data ?? [], null);
        $this->setIfExists('description', $data ?? [], null);
        $this->setIfExists('price', $data ?? [], null);
        $this->setIfExists('price_estimate_min', $data ?? [], null);
        $this->setIfExists('price_estimate_max', $data ?? [], null);
        $this->setIfExists('availability', $data ?? [], null);
        $this->setIfExists('url', $data ?? [], null);
        $this->setIfExists('images', $data ?? [], null);
        $this->setIfExists('auction', $data ?? [], null);
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

        if ($this->container['source_listing_id'] === null) {
            $invalidProperties[] = "'source_listing_id' can't be null";
        }
        if ($this->container['url'] === null) {
            $invalidProperties[] = "'url' can't be null";
        }
        if ($this->container['images'] === null) {
            $invalidProperties[] = "'images' can't be null";
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
     * Gets source_listing_id
     *
     * @return string
     */
    public function getSourceListingId()
    {
        return $this->container['source_listing_id'];
    }

    /**
     * Sets source_listing_id
     *
     * @param string $source_listing_id The listing source's own identifier for the product. Must be unique within the listing source.
     *
     * @return self
     */
    public function setSourceListingId($source_listing_id)
    {
        if (is_null($source_listing_id)) {
            throw new \InvalidArgumentException('non-nullable source_listing_id cannot be null');
        }
        $this->container['source_listing_id'] = $source_listing_id;

        return $this;
    }

    /**
     * Gets title
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\LocalizedTextData|null
     */
    public function getTitle()
    {
        return $this->container['title'];
    }

    /**
     * Sets title
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\LocalizedTextData|null $title Optional localized title. Omit or send `null` when no title is available.
     *
     * @return self
     */
    public function setTitle($title)
    {
        if (is_null($title)) {
            array_push($this->openAPINullablesSetToNull, 'title');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('title', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['title'] = $title;

        return $this;
    }

    /**
     * Gets description
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\LocalizedTextData|null
     */
    public function getDescription()
    {
        return $this->container['description'];
    }

    /**
     * Sets description
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\LocalizedTextData|null $description Optional localized description. Omit or send `null` when no description is available.
     *
     * @return self
     */
    public function setDescription($description)
    {
        if (is_null($description)) {
            array_push($this->openAPINullablesSetToNull, 'description');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('description', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['description'] = $description;

        return $this;
    }

    /**
     * Gets price
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingPriceData|null
     */
    public function getPrice()
    {
        return $this->container['price'];
    }

    /**
     * Sets price
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingPriceData|null $price Optional asking-price assertion for the product.
     *
     * @return self
     */
    public function setPrice($price)
    {
        if (is_null($price)) {
            array_push($this->openAPINullablesSetToNull, 'price');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('price', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['price'] = $price;

        return $this;
    }

    /**
     * Gets price_estimate_min
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\PriceData|null
     */
    public function getPriceEstimateMin()
    {
        return $this->container['price_estimate_min'];
    }

    /**
     * Sets price_estimate_min
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\PriceData|null $price_estimate_min Optional lower bound of the estimated price range
     *
     * @return self
     */
    public function setPriceEstimateMin($price_estimate_min)
    {
        if (is_null($price_estimate_min)) {
            array_push($this->openAPINullablesSetToNull, 'price_estimate_min');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('price_estimate_min', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['price_estimate_min'] = $price_estimate_min;

        return $this;
    }

    /**
     * Gets price_estimate_max
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\PriceData|null
     */
    public function getPriceEstimateMax()
    {
        return $this->container['price_estimate_max'];
    }

    /**
     * Sets price_estimate_max
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\PriceData|null $price_estimate_max Optional upper bound of the estimated price range
     *
     * @return self
     */
    public function setPriceEstimateMax($price_estimate_max)
    {
        if (is_null($price_estimate_max)) {
            array_push($this->openAPINullablesSetToNull, 'price_estimate_max');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('price_estimate_max', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['price_estimate_max'] = $price_estimate_max;

        return $this;
    }

    /**
     * Gets availability
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ListingAvailabilityData|null
     */
    public function getAvailability()
    {
        return $this->container['availability'];
    }

    /**
     * Sets availability
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ListingAvailabilityData|null $availability Omit or send `null` when Aura has no reliable current availability assertion.
     *
     * @return self
     */
    public function setAvailability($availability)
    {
        if (is_null($availability)) {
            array_push($this->openAPINullablesSetToNull, 'availability');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('availability', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['availability'] = $availability;

        return $this;
    }

    /**
     * Gets url
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->container['url'];
    }

    /**
     * Sets url
     *
     * @param string $url URL to the product on the listing source's website
     *
     * @return self
     */
    public function setUrl($url)
    {
        if (is_null($url)) {
            throw new \InvalidArgumentException('non-nullable url cannot be null');
        }
        $this->container['url'] = $url;

        return $this;
    }

    /**
     * Gets images
     *
     * @return string[]
     */
    public function getImages()
    {
        return $this->container['images'];
    }

    /**
     * Sets images
     *
     * @param string[] $images List of image URLs for the product. May be empty.
     *
     * @return self
     */
    public function setImages($images)
    {
        if (is_null($images)) {
            throw new \InvalidArgumentException('non-nullable images cannot be null');
        }
        $this->container['images'] = $images;

        return $this;
    }

    /**
     * Gets auction
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingAuctionData|null
     */
    public function getAuction()
    {
        return $this->container['auction'];
    }

    /**
     * Sets auction
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingAuctionData|null $auction Omit for no Auction or lot changes, or send an asserted nested Auction/lot-facts patch. `null` is invalid. `auctionId` must identify an existing Auction for this ListingSource.
     *
     * @return self
     */
    public function setAuction($auction)
    {
        if (is_null($auction)) {
            throw new \InvalidArgumentException('non-nullable auction cannot be null');
        }
        $this->container['auction'] = $auction;

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
