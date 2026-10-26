<?php
/**
 * LanguageData
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
use \AuraHistoria\PartnerConnect\InternalApi\ObjectSerializer;

/**
 * LanguageData Class Doc Comment
 *
 * @category Class
 * @description Supported language codes (ISO 639-1 canonical values): - de: German (includes de-DE, de-AT, de-CH, de-LU, de-LI) - en: English (includes en-US, en-GB, en-AU, en-CA, en-NZ, and backend-specific accepted alias en_IE) - fr: French (includes fr-FR, fr-CA, fr-BE, fr-CH, fr-LU) - es: Spanish (includes es-ES, es-MX, es-AR, es-CO, es-CL, es-PE, es-VE) - it: Italian (includes it-IT, it-CH) - zh: Chinese (Simplified) (includes zh-CN, zh-Hans) - pt: Portuguese (includes pt-PT, pt-BR) - pl: Polish (includes pl-PL) - tr: Turkish (includes tr-TR) - nl: Dutch (includes nl-NL, nl-BE) - cs: Czech (includes cs-CZ) - ja: Japanese (includes ja-JP) - ru: Russian (includes ru-RU) - ar: Arabic (includes ar-SA, ar-EG, ar-AE)  &#x60;de&#x60;, &#x60;en&#x60;, &#x60;fr&#x60;, &#x60;es&#x60;, and &#x60;it&#x60; are fully supported localization/translation-target languages. &#x60;zh&#x60;, &#x60;pt&#x60;, &#x60;pl&#x60;, &#x60;tr&#x60;, &#x60;nl&#x60;, &#x60;cs&#x60;, &#x60;ja&#x60;, &#x60;ru&#x60;, and &#x60;ar&#x60; are ingestion-only languages: they can appear in stored/native content and are accepted anywhere &#x60;LanguageData&#x60; is used, but backend-generated translations and fallback localized strings are not produced in these languages.
 * @package  AuraHistoria\PartnerConnect\InternalApi
 * @author   OpenAPI Generator team
 * @link     https://openapi-generator.tech
 */
class LanguageData
{
    /**
     * Possible values of this enum
     */
    public const DE = 'de';

    public const EN = 'en';

    public const FR = 'fr';

    public const ES = 'es';

    public const IT = 'it';

    public const ZH = 'zh';

    public const PT = 'pt';

    public const PL = 'pl';

    public const TR = 'tr';

    public const NL = 'nl';

    public const CS = 'cs';

    public const JA = 'ja';

    public const RU = 'ru';

    public const AR = 'ar';

    /**
     * Gets allowable values of the enum
     * @return string[]
     */
    public static function getAllowableEnumValues()
    {
        return [
            self::DE,
            self::EN,
            self::FR,
            self::ES,
            self::IT,
            self::ZH,
            self::PT,
            self::PL,
            self::TR,
            self::NL,
            self::CS,
            self::JA,
            self::RU,
            self::AR
        ];
    }
}
