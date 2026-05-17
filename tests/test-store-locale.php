<?php
/**
 * Integration tests for Store_Locale helpers.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Store_Locale;

/**
 * Tests the Store_Locale currency and language helpers.
 */
class Test_AHPC_Store_Locale extends WP_UnitTestCase
{
    /**
     * Ensures WooCommerce is installed for the test suite.
     *
     * @param WP_UnitTest_Factory $factory Test factory.
     * @return void
     */
    public static function wpSetUpBeforeClass($factory)
    {
        if (class_exists("WC_Install")) {
            WC_Install::install();
        }
    }

    /**
     * Test setup.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        if (class_exists("WC_Install") && !get_option("woocommerce_version")) {
            WC_Install::install();
        }
    }

    // -------------------------------------------------------------------------
    // Store_Locale::get_currency()
    // -------------------------------------------------------------------------

    /**
     * It returns the store currency when it is supported by the backend.
     *
     * @return void
     */
    public function test_get_currency_returns_supported_currency()
    {
        update_option("woocommerce_currency", "EUR", false);

        $this->assertSame("EUR", Store_Locale::get_currency());
    }

    /**
     * It normalises the currency code to upper-case before validation.
     *
     * @return void
     */
    public function test_get_currency_normalises_to_uppercase()
    {
        update_option("woocommerce_currency", "gbp", false);

        $this->assertSame("GBP", Store_Locale::get_currency());
    }

    /**
     * It returns null when the store currency is not supported by the backend.
     *
     * @return void
     */
    public function test_get_currency_returns_null_for_unsupported_currency()
    {
        update_option("woocommerce_currency", "XYZ", false);

        $this->assertNull(Store_Locale::get_currency());
    }

    /**
     * It returns null when the store currency option is empty.
     *
     * @return void
     */
    public function test_get_currency_returns_null_for_empty_currency()
    {
        update_option("woocommerce_currency", "", false);

        $this->assertNull(Store_Locale::get_currency());
    }

    /**
     * It returns a non-null value for every currency in the CurrencyData enum.
     *
     * @return void
     */
    public function test_get_currency_accepts_all_supported_currencies()
    {
        $supported = [
            "EUR",
            "GBP",
            "USD",
            "AUD",
            "CAD",
            "NZD",
            "CNY",
            "BRL",
            "PLN",
            "TRY",
            "JPY",
            "CZK",
            "RUB",
            "AED",
            "SAR",
            "HKD",
            "SGD",
            "CHF",
        ];

        foreach ($supported as $code) {
            update_option("woocommerce_currency", $code, false);
            $this->assertSame(
                $code,
                Store_Locale::get_currency(),
                "Expected get_currency() to return '{$code}'.",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Store_Locale::get_language()
    // -------------------------------------------------------------------------

    /**
     * It returns the two-letter language code for a recognised locale.
     *
     * @return void
     */
    public function test_get_language_returns_language_for_recognised_locale()
    {
        add_filter("locale", static function () {
            return "de_DE";
        });

        $result = Store_Locale::get_language();
        remove_all_filters("locale");

        $this->assertSame("de", $result);
    }

    /**
     * It handles locale strings that use hyphens instead of underscores.
     *
     * @return void
     */
    public function test_get_language_handles_hyphen_locale()
    {
        add_filter("locale", static function () {
            return "fr-FR";
        });

        $result = Store_Locale::get_language();
        remove_all_filters("locale");

        $this->assertSame("fr", $result);
    }

    /**
     * It returns "en" as a fallback for an unrecognised locale.
     *
     * @return void
     */
    public function test_get_language_falls_back_to_en_for_unknown_locale()
    {
        add_filter("locale", static function () {
            return "xx_XX";
        });

        $result = Store_Locale::get_language();
        remove_all_filters("locale");

        $this->assertSame("en", $result);
    }

    /**
     * It returns "en" as a fallback for an empty locale.
     *
     * @return void
     */
    public function test_get_language_falls_back_to_en_for_empty_locale()
    {
        add_filter("locale", static function () {
            return "";
        });

        $result = Store_Locale::get_language();
        remove_all_filters("locale");

        $this->assertSame("en", $result);
    }

    /**
     * It returns the correct language for every supported locale prefix.
     *
     * @return void
     */
    public function test_get_language_accepts_all_supported_languages()
    {
        $supported = [
            "de_DE" => "de",
            "en_US" => "en",
            "fr_FR" => "fr",
            "es_ES" => "es",
            "it_IT" => "it",
            "zh_CN" => "zh",
            "pt_PT" => "pt",
            "pl_PL" => "pl",
            "tr_TR" => "tr",
            "nl_NL" => "nl",
            "cs_CZ" => "cs",
            "ja_JP" => "ja",
            "ru_RU" => "ru",
            "ar_SA" => "ar",
        ];

        foreach ($supported as $locale => $expected_language) {
            add_filter("locale", static function () use ($locale) {
                return $locale;
            });

            $result = Store_Locale::get_language();
            remove_all_filters("locale");

            $this->assertSame(
                $expected_language,
                $result,
                "Expected get_language() to return '{$expected_language}' for locale '{$locale}'.",
            );
        }
    }
}
