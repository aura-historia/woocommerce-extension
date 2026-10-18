<?php
/**
 * CreateShippingProfileData
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
 * CreateShippingProfileData Class Doc Comment
 *
 * @category Class
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 * @implements \ArrayAccess<string, mixed>
 */
class CreateShippingProfileData implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'CreateShippingProfileData';

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'import' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingImportWriteData',
        'creation_key' => 'string',
        'content' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingProfileContentData',
        'evidence' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingEvidenceInputData[]'
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'import' => null,
        'creation_key' => null,
        'content' => null,
        'evidence' => null
    ];

    /**
     * Array of nullable properties. Used for (de)serialization
     *
     * @var boolean[]
     */
    protected static array $openAPINullables = [
        'import' => false,
        'creation_key' => false,
        'content' => false,
        'evidence' => false
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
        'import' => 'import',
        'creation_key' => 'creationKey',
        'content' => 'content',
        'evidence' => 'evidence'
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'import' => 'setImport',
        'creation_key' => 'setCreationKey',
        'content' => 'setContent',
        'evidence' => 'setEvidence'
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'import' => 'getImport',
        'creation_key' => 'getCreationKey',
        'content' => 'getContent',
        'evidence' => 'getEvidence'
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
        $this->setIfExists('import', $data ?? [], null);
        $this->setIfExists('creation_key', $data ?? [], null);
        $this->setIfExists('content', $data ?? [], null);
        $this->setIfExists('evidence', $data ?? [], null);
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

        if ($this->container['creation_key'] === null) {
            $invalidProperties[] = "'creation_key' can't be null";
        }
        if ((mb_strlen($this->container['creation_key']) > 128)) {
            $invalidProperties[] = "invalid value for 'creation_key', the character length must be smaller than or equal to 128.";
        }

        if ((mb_strlen($this->container['creation_key']) < 1)) {
            $invalidProperties[] = "invalid value for 'creation_key', the character length must be bigger than or equal to 1.";
        }

        if (!preg_match("/^[!-~]+$/", $this->container['creation_key'])) {
            $invalidProperties[] = "invalid value for 'creation_key', must be conform to the pattern /^[!-~]+$/.";
        }

        if ($this->container['content'] === null) {
            $invalidProperties[] = "'content' can't be null";
        }
        if ($this->container['evidence'] === null) {
            $invalidProperties[] = "'evidence' can't be null";
        }
        if ((count($this->container['evidence']) > 32)) {
            $invalidProperties[] = "invalid value for 'evidence', number of items must be less than or equal to 32.";
        }

        if ((count($this->container['evidence']) < 0)) {
            $invalidProperties[] = "invalid value for 'evidence', number of items must be greater than or equal to 0.";
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
     * Gets import
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingImportWriteData|null
     */
    public function getImport()
    {
        return $this->container['import'];
    }

    /**
     * Sets import
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingImportWriteData|null $import import
     *
     * @return self
     */
    public function setImport($import)
    {
        if (is_null($import)) {
            throw new \InvalidArgumentException('non-nullable import cannot be null');
        }
        $this->container['import'] = $import;

        return $this;
    }

    /**
     * Gets creation_key
     *
     * @return string
     */
    public function getCreationKey()
    {
        return $this->container['creation_key'];
    }

    /**
     * Sets creation_key
     *
     * @param string $creation_key creation_key
     *
     * @return self
     */
    public function setCreationKey($creation_key)
    {
        if (is_null($creation_key)) {
            throw new \InvalidArgumentException('non-nullable creation_key cannot be null');
        }
        if ((mb_strlen($creation_key) > 128)) {
            throw new \InvalidArgumentException('invalid length for $creation_key when calling CreateShippingProfileData., must be smaller than or equal to 128.');
        }
        if ((mb_strlen($creation_key) < 1)) {
            throw new \InvalidArgumentException('invalid length for $creation_key when calling CreateShippingProfileData., must be bigger than or equal to 1.');
        }
        if ((!preg_match("/^[!-~]+$/", ObjectSerializer::toString($creation_key)))) {
            throw new \InvalidArgumentException("invalid value for \$creation_key when calling CreateShippingProfileData., must conform to the pattern /^[!-~]+$/.");
        }

        $this->container['creation_key'] = $creation_key;

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
     * Gets evidence
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingEvidenceInputData[]
     */
    public function getEvidence()
    {
        return $this->container['evidence'];
    }

    /**
     * Sets evidence
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingEvidenceInputData[] $evidence evidence
     *
     * @return self
     */
    public function setEvidence($evidence)
    {
        if (is_null($evidence)) {
            throw new \InvalidArgumentException('non-nullable evidence cannot be null');
        }

        if ((count($evidence) > 32)) {
            throw new \InvalidArgumentException('invalid value for $evidence when calling CreateShippingProfileData., number of items must be less than or equal to 32.');
        }
        if ((count($evidence) < 0)) {
            throw new \InvalidArgumentException('invalid length for $evidence when calling CreateShippingProfileData., number of items must be greater than or equal to 0.');
        }
        $this->container['evidence'] = $evidence;

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
