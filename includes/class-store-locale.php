<?php
/**
 * Store locale helpers.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

use AuraHistoria\PartnerConnect\InternalApi\Model\CurrencyData;
use AuraHistoria\PartnerConnect\InternalApi\Model\LanguageData;

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Provides static helpers for reading WooCommerce store locale settings.
 *
 * Both {@see Webhook_Manager} and {@see Product_Backfill} rely on these
 * helpers so that the supported-currency and supported-language lists stay
 * in sync with the generated OpenAPI client.
 */
class Store_Locale
{
    /**
     * Returns the WooCommerce store currency as an ISO 4217 code.
     *
     * Returns `null` when the store currency is not in the set of currencies
     * supported by the Aura Historia backend.
     *
     * @return string|null ISO 4217 currency code, or null if not supported.
     */
    public static function get_currency()
    {
        $currency = function_exists("get_woocommerce_currency")
            ? strtoupper((string) get_woocommerce_currency())
            : "";

        if (in_array($currency, CurrencyData::getAllowableEnumValues(), true)) {
            return $currency;
        }

        return null;
    }

    /**
     * Returns the canonical Aura Historia language code for the current store.
     *
     * Derives the language from the WordPress locale. Falls back to `"en"`
     * when the locale does not map to a supported language.
     *
     * @return string ISO 639-1 language code (never empty).
     */
    public static function get_language()
    {
        $locale = strtolower(str_replace("-", "_", (string) get_locale()));
        $language = preg_replace("/[^a-z_]/", "", $locale);
        $language = is_string($language) ? strtok($language, "_") : false;

        if (
            is_string($language) &&
            in_array($language, LanguageData::getAllowableEnumValues(), true)
        ) {
            return $language;
        }

        return "en";
    }
}
