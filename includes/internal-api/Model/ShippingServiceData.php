<?php
/**
 * ShippingServiceData
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
 * ShippingServiceData Class Doc Comment
 *
 * @category Class
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 * @implements \ArrayAccess<string, mixed>
 */
class ShippingServiceData implements ModelInterface, ArrayAccess, \JsonSerializable
{
    public const DISCRIMINATOR = null;

    /**
     * The original name of the model.
     *
     * @var string
     */
    protected static $openAPIModelName = 'ShippingServiceData';

    /**
     * Array of property to type mappings. Used for (de)serialization
     *
     * @var string[]
     */
    protected static $openAPITypes = [
        'code' => 'string',
        'applicability' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingApplicabilityData',
        'eligibility' => 'string',
        'rate' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingRateData',
        'lead_times' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingLeadTimesData',
        'features' => 'string[]',
        'arranger' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingActorData',
        'performer' => '\AuraHistoria\PartnerConnect\InternalApi\Model\ShippingActorData'
    ];

    /**
     * Array of property to format mappings. Used for (de)serialization
     *
     * @var string[]
     * @phpstan-var array<string, string|null>
     * @psalm-var array<string, string|null>
     */
    protected static $openAPIFormats = [
        'code' => null,
        'applicability' => null,
        'eligibility' => null,
        'rate' => null,
        'lead_times' => null,
        'features' => null,
        'arranger' => null,
        'performer' => null
    ];

    /**
     * Array of nullable properties. Used for (de)serialization
     *
     * @var boolean[]
     */
    protected static array $openAPINullables = [
        'code' => false,
        'applicability' => false,
        'eligibility' => true,
        'rate' => true,
        'lead_times' => false,
        'features' => false,
        'arranger' => true,
        'performer' => true
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
        'code' => 'code',
        'applicability' => 'applicability',
        'eligibility' => 'eligibility',
        'rate' => 'rate',
        'lead_times' => 'lead_times',
        'features' => 'features',
        'arranger' => 'arranger',
        'performer' => 'performer'
    ];

    /**
     * Array of attributes to setter functions (for deserialization of responses)
     *
     * @var string[]
     */
    protected static $setters = [
        'code' => 'setCode',
        'applicability' => 'setApplicability',
        'eligibility' => 'setEligibility',
        'rate' => 'setRate',
        'lead_times' => 'setLeadTimes',
        'features' => 'setFeatures',
        'arranger' => 'setArranger',
        'performer' => 'setPerformer'
    ];

    /**
     * Array of attributes to getter functions (for serialization of requests)
     *
     * @var string[]
     */
    protected static $getters = [
        'code' => 'getCode',
        'applicability' => 'getApplicability',
        'eligibility' => 'getEligibility',
        'rate' => 'getRate',
        'lead_times' => 'getLeadTimes',
        'features' => 'getFeatures',
        'arranger' => 'getArranger',
        'performer' => 'getPerformer'
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

    public const ELIGIBILITY_OFFERED = 'OFFERED';
    public const ELIGIBILITY_NOT_OFFERED = 'NOT_OFFERED';
    public const ELIGIBILITY_SUBJECT_TO_CONFIRMATION = 'SUBJECT_TO_CONFIRMATION';
    public const FEATURES_TRACKING = 'TRACKING';
    public const FEATURES_INSURED = 'INSURED';
    public const FEATURES_WHITE_GLOVE = 'WHITE_GLOVE';
    public const FEATURES_INSTALLATION = 'INSTALLATION';
    public const FEATURES_PACKING = 'PACKING';

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
     * Gets allowable values of the enum
     *
     * @return string[]
     */
    public function getFeaturesAllowableValues()
    {
        return [
            self::FEATURES_TRACKING,
            self::FEATURES_INSURED,
            self::FEATURES_WHITE_GLOVE,
            self::FEATURES_INSTALLATION,
            self::FEATURES_PACKING,
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
        $this->setIfExists('code', $data ?? [], null);
        $this->setIfExists('applicability', $data ?? [], null);
        $this->setIfExists('eligibility', $data ?? [], null);
        $this->setIfExists('rate', $data ?? [], null);
        $this->setIfExists('lead_times', $data ?? [], null);
        $this->setIfExists('features', $data ?? [], null);
        $this->setIfExists('arranger', $data ?? [], null);
        $this->setIfExists('performer', $data ?? [], null);
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

        if ($this->container['code'] === null) {
            $invalidProperties[] = "'code' can't be null";
        }
        if ((mb_strlen($this->container['code']) > 128)) {
            $invalidProperties[] = "invalid value for 'code', the character length must be smaller than or equal to 128.";
        }

        if ((mb_strlen($this->container['code']) < 1)) {
            $invalidProperties[] = "invalid value for 'code', the character length must be bigger than or equal to 1.";
        }

        if ($this->container['applicability'] === null) {
            $invalidProperties[] = "'applicability' can't be null";
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

        if ($this->container['rate'] === null && !$this->isNullableSetToNull('rate')) {
            $invalidProperties[] = "'rate' is required";
        }
        if ($this->container['lead_times'] === null) {
            $invalidProperties[] = "'lead_times' can't be null";
        }
        if ($this->container['features'] === null) {
            $invalidProperties[] = "'features' can't be null";
        }
        if ((count($this->container['features']) > 5)) {
            $invalidProperties[] = "invalid value for 'features', number of items must be less than or equal to 5.";
        }

        if ((count($this->container['features']) < 0)) {
            $invalidProperties[] = "invalid value for 'features', number of items must be greater than or equal to 0.";
        }

        if ($this->container['arranger'] === null && !$this->isNullableSetToNull('arranger')) {
            $invalidProperties[] = "'arranger' is required";
        }
        if ($this->container['performer'] === null && !$this->isNullableSetToNull('performer')) {
            $invalidProperties[] = "'performer' is required";
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
     * Gets code
     *
     * @return string
     */
    public function getCode()
    {
        return $this->container['code'];
    }

    /**
     * Sets code
     *
     * @param string $code code
     *
     * @return self
     */
    public function setCode($code)
    {
        if (is_null($code)) {
            throw new \InvalidArgumentException('non-nullable code cannot be null');
        }
        if ((mb_strlen($code) > 128)) {
            throw new \InvalidArgumentException('invalid length for $code when calling ShippingServiceData., must be smaller than or equal to 128.');
        }
        if ((mb_strlen($code) < 1)) {
            throw new \InvalidArgumentException('invalid length for $code when calling ShippingServiceData., must be bigger than or equal to 1.');
        }

        $this->container['code'] = $code;

        return $this;
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
     * Gets rate
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingRateData|null
     */
    public function getRate()
    {
        return $this->container['rate'];
    }

    /**
     * Sets rate
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingRateData|null $rate rate
     *
     * @return self
     */
    public function setRate($rate)
    {
        if (is_null($rate)) {
            array_push($this->openAPINullablesSetToNull, 'rate');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('rate', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['rate'] = $rate;

        return $this;
    }

    /**
     * Gets lead_times
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingLeadTimesData
     */
    public function getLeadTimes()
    {
        return $this->container['lead_times'];
    }

    /**
     * Sets lead_times
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingLeadTimesData $lead_times lead_times
     *
     * @return self
     */
    public function setLeadTimes($lead_times)
    {
        if (is_null($lead_times)) {
            throw new \InvalidArgumentException('non-nullable lead_times cannot be null');
        }
        $this->container['lead_times'] = $lead_times;

        return $this;
    }

    /**
     * Gets features
     *
     * @return string[]
     */
    public function getFeatures()
    {
        return $this->container['features'];
    }

    /**
     * Sets features
     *
     * @param string[] $features features
     *
     * @return self
     */
    public function setFeatures($features)
    {
        if (is_null($features)) {
            throw new \InvalidArgumentException('non-nullable features cannot be null');
        }
        $allowedValues = $this->getFeaturesAllowableValues();
        if (array_diff($features, $allowedValues)) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Invalid value for 'features', must be one of '%s'",
                    implode("', '", $allowedValues)
                )
            );
        }

        if ((count($features) > 5)) {
            throw new \InvalidArgumentException('invalid value for $features when calling ShippingServiceData., number of items must be less than or equal to 5.');
        }
        if ((count($features) < 0)) {
            throw new \InvalidArgumentException('invalid length for $features when calling ShippingServiceData., number of items must be greater than or equal to 0.');
        }
        $this->container['features'] = $features;

        return $this;
    }

    /**
     * Gets arranger
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingActorData|null
     */
    public function getArranger()
    {
        return $this->container['arranger'];
    }

    /**
     * Sets arranger
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingActorData|null $arranger arranger
     *
     * @return self
     */
    public function setArranger($arranger)
    {
        if (is_null($arranger)) {
            array_push($this->openAPINullablesSetToNull, 'arranger');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('arranger', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['arranger'] = $arranger;

        return $this;
    }

    /**
     * Gets performer
     *
     * @return \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingActorData|null
     */
    public function getPerformer()
    {
        return $this->container['performer'];
    }

    /**
     * Sets performer
     *
     * @param \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingActorData|null $performer performer
     *
     * @return self
     */
    public function setPerformer($performer)
    {
        if (is_null($performer)) {
            array_push($this->openAPINullablesSetToNull, 'performer');
        } else {
            $nullablesSetToNull = $this->getOpenAPINullablesSetToNull();
            $index = array_search('performer', $nullablesSetToNull);
            if ($index !== FALSE) {
                unset($nullablesSetToNull[$index]);
                $this->setOpenAPINullablesSetToNull($nullablesSetToNull);
            }
        }
        $this->container['performer'] = $performer;

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
