<?php
/**
 * ShippingCollectionData
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
 * ShippingCollectionData Class Doc Comment
 *
 * @category Class
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 * @implements \ArrayAccess<string, mixed>
 */
class ShippingCollectionData implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'ShippingCollectionData';

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'applicability' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingApplicabilityData',
        'dependency' => 'string',
        'eligibility' => 'string',
        'site' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingSiteData',
        'arrangement' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingCollectionArrangementData'
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'applicability' => null,
        'dependency' => null,
        'eligibility' => null,
        'site' => null,
        'arrangement' => null
    ];

    /**
     * Array of nullable properties. Used for (de)serialization
     *
     * @var boolean[]
     */
    protected static array $openAPINullables = [
        'applicability' => false,
        'dependency' => false,
        'eligibility' => true,
        'site' => true,
        'arrangement' => true
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
        'applicability' => 'applicability',
        'dependency' => 'dependency',
        'eligibility' => 'eligibility',
        'site' => 'site',
        'arrangement' => 'arrangement'
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'applicability' => 'setApplicability',
        'dependency' => 'setDependency',
        'eligibility' => 'setEligibility',
        'site' => 'setSite',
        'arrangement' => 'setArrangement'
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'applicability' => 'getApplicability',
        'dependency' => 'getDependency',
        'eligibility' => 'getEligibility',
        'site' => 'getSite',
        'arrangement' => 'getArrangement'
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

    public const DEPENDENCY_INDEPENDENT = 'INDEPENDENT';
    public const DEPENDENCY_SHIPPING = 'SHIPPING';
    public const ELIGIBILITY_OFFERED = 'OFFERED';
    public const ELIGIBILITY_NOT_OFFERED = 'NOT_OFFERED';
    public const ELIGIBILITY_SUBJECT_TO_CONFIRMATION = 'SUBJECT_TO_CONFIRMATION';

    /**
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getDependencyAllowableValues()
    {
        return [
            self::DEPENDENCY_INDEPENDENT,
            self::DEPENDENCY_SHIPPING,
        ];
    }

    /**
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getEligibilityAllowableValues()
    {
        return [
            self::ELIGIBILITY_OFFERED,
            self::ELIGIBILITY_NOT_OFFERED,
            self::ELIGIBILITY_SUBJECT_TO_CONFIRMATION,
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
        $this->setIfExists('applicability', $data ?? [], null);
        $this->setIfExists('dependency', $data ?? [], null);
        $this->setIfExists('eligibility', $data ?? [], null);
        $this->setIfExists('site', $data ?? [], null);
        $this->setIfExists('arrangement', $data ?? [], null);
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

        if ($this->container['applicability'] === null) {
            $invalidProperties[] = "'applicability' can't be null";
        }
        if ($this->container['dependency'] === null) {
            $invalidProperties[] = "'dependency' can't be null";
        }
        $allowedValues = $this->getDependencyAllowableValues();
        if (!is_null($this->container['dependency']) && !in_array($this->container['dependency'], $allowedValues, true)) {
            $invalidProperties[] = sprintf(
                "invalid value '%s' for 'dependency', must be one of '%s'",
                $this->container['dependency'],
                implode("', '", $allowedValues)
            );
        }

        if ($this->container['eligibility'] === null && !$this->isNullableSetToNull('eligibility')) {
            $invalidProperties[] = "'eligibility' is required";
        }
        $allowedValues = $this->getEligibilityAllowableValues();
        if (!is_null($this->container['eligibility']) && !in_array($this->container['eligibility'], $allowedValues, true)) {
            $invalidProperties[] = sprintf(
                "invalid value '%s' for 'eligibility', must be one of '%s'",
                $this->container['eligibility'],
                implode("', '", $allowedValues)
            );
        }

        if ($this->container['site'] === null && !$this->isNullableSetToNull('site')) {
            $invalidProperties[] = "'site' is required";
        }
        if ($this->container['arrangement'] === null && !$this->isNullableSetToNull('arrangement')) {
            $invalidProperties[] = "'arrangement' is required";
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
     * Gets applicability
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingApplicabilityData
     */
    public function getApplicability()
    {
        return $this->container['applicability'];
    }

    /**
     * Sets applicability
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingApplicabilityData $applicability applicability
     *
     * @return self
     */
    public function setApplicability($applicability)
    {
        if (is_null($applicability)) {
            throw new \InvalidArgumentException('non-nullable applicability cannot be null');
        }
        $this->container['applicability'] = $applicability;

        return $this;
    }

    /**
     * Gets dependency
     *
     * @return string
     */
    public function getDependency()
    {
        return $this->container['dependency'];
    }

    /**
     * Sets dependency
     *
     * @param string $dependency dependency
     *
     * @return self
     */
    public function setDependency($dependency)
    {
        if (is_null($dependency)) {
            throw new \InvalidArgumentException('non-nullable dependency cannot be null');
        }
        $allowedValues = $this->getDependencyAllowableValues();
        if (!in_array($dependency, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value '%s' for 'dependency', must be one of '%s'",
                    $dependency,
                    implode("', '", $allowedValues)
                )
            );
        }
        $this->container['dependency'] = $dependency;

        return $this;
    }

    /**
     * Gets eligibility
     *
     * @return string|null
     */
    public function getEligibility()
    {
        return $this->container['eligibility'];
    }

    /**
     * Sets eligibility
     *
     * @param string|null $eligibility eligibility
     *
     * @return self
     */
    public function setEligibility($eligibility)
    {
        if (is_null($eligibility)) {
            array_push($this->openAPINullablesSetToNull, 'eligibility');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('eligibility', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $allowedValues = $this->getEligibilityAllowableValues();
        if (!is_null($eligibility) && !in_array($eligibility, $allowedValues, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value '%s' for 'eligibility', must be one of '%s'",
                    $eligibility,
                    implode("', '", $allowedValues)
                )
            );
        }
        $this->container['eligibility'] = $eligibility;

        return $this;
    }

    /**
     * Gets site
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingSiteData|null
     */
    public function getSite()
    {
        return $this->container['site'];
    }

    /**
     * Sets site
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingSiteData|null $site site
     *
     * @return self
     */
    public function setSite($site)
    {
        if (is_null($site)) {
            array_push($this->openAPINullablesSetToNull, 'site');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('site', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['site'] = $site;

        return $this;
    }

    /**
     * Gets arrangement
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingCollectionArrangementData|null
     */
    public function getArrangement()
    {
        return $this->container['arrangement'];
    }

    /**
     * Sets arrangement
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingCollectionArrangementData|null $arrangement arrangement
     *
     * @return self
     */
    public function setArrangement($arrangement)
    {
        if (is_null($arrangement)) {
            array_push($this->openAPINullablesSetToNull, 'arrangement');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('arrangement', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['arrangement'] = $arrangement;

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
