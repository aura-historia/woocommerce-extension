<?php
/**
 * Pure, bounded translation of acquired WooCommerce shipping facts.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Maps advertised configuration, without executing expressions or checkout.
 */
class Shipping_Mapper
{
    const OBJECT_KEY = "woocommerce:shipping:core:v1";
    const REFERENCE_RELEASE = "iso-codes-4.20.1";
    const INTEGRATION = ["kind" => "PROVIDER", "method" => "WOOCOMMERCE"];

    // Reviewed provider codes. In particular, numeric WooCommerce state codes
    // are NOT ISO subdivision codes and must never be blindly prefixed.
    private const STATES = [
        "DE" => "BW BY BE BB HB HH HE MV NI NW RP SL SN ST SH TH",
        "US" => "AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY",
        "CA" => "AB BC MB NB NL NS NT NU ON PE QC SK YT",
        "AU" => "ACT NSW NT QLD SA TAS VIC WA",
        "AT" => "1 2 3 4 5 6 7 8 9",
        "CH" => "AG AI AR BE BL BS FR GE GL GR JU LU NE NW OW SG SH SO SZ TG TI UR VD VS ZG ZH",
    ];
    private const COUNTRIES = "AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW";

    public static function applicability()
    {
        return ["class" => null, "variants" => null, "quantity" => null, "threshold" => null];
    }

    public static function class_code($id)
    {
        return $id ? "wc:class:" . (int) $id : "wc:class:none";
    }

    /** Exact, nonnegative minor units; no floats, rounding or overflow. */
    public static function minor_units($decimal, $currency)
    {
        if (!in_array($currency, InternalApi\Model\ShippingCurrencyCode::getAllowableEnumValues(), true)) {
            return null;
        }
        $exponent = in_array($currency, ["JPY", "KRW"], true) ? 0 : 2;
        if (!is_string($decimal) || !preg_match('/\A([0-9]+)(?:\.([0-9]+))?\z/', trim($decimal), $match)) {
            return null;
        }
        $fraction = $match[2] ?? "";
        if ("" !== trim(substr($fraction, $exponent), "0")) {
            return null;
        }
        $digits = ltrim($match[1] . str_pad(substr($fraction, 0, $exponent), $exponent, "0"), "0");
        $digits = "" === $digits ? "0" : $digits;
        $maximum = (string) PHP_INT_MAX;
        return strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
            ? null : (int) $digits;
    }

    /** Only a decimal constant or a single quantity multiplication is accepted. */
    public static function cost($expression, $currency)
    {
        $expression = trim((string) $expression);
        $basis = "PER_SHIPMENT";
        if (preg_match('/\A(?:([0-9]+(?:\.[0-9]+)?)\s*\*\s*\[qty\]|\[qty\]\s*\*\s*([0-9]+(?:\.[0-9]+)?))\z/', $expression, $match)) {
            $expression = "" !== $match[1] ? $match[1] : $match[2];
            $basis = "PER_ITEM";
        } elseif ("[qty]" === $expression) {
            $expression = "1";
            $basis = "PER_ITEM";
        }
        $amount = self::minor_units($expression, $currency);
        return null === $amount ? null : ["amount" => $amount, "basis" => $basis];
    }

    /** @return array|\WP_Error Canonical profile content and bounded diagnostic codes. */
    public function profile(array $capture)
    {
        if (empty($capture["complete"])) {
            return self::error("incomplete_configuration");
        }
        $limitations = [];
        $zones = [];
        $collection = null;
        foreach ($capture["zones"] as $zone) {
            $areas = $this->areas($zone, $capture, $limitations);
            if (!$areas) {
                continue;
            }
            $services = [];
            foreach ($zone["methods"] as $method) {
                if (!empty($method["core"]) && !$this->supported_settings($method)) {
                    return self::error("unsupported_method_settings");
                }
                if ("local_pickup" === $method["id"] && !empty($method["core"])) {
                    // Zone-dependent collection cannot be advertised worldwide.
                    $collection = ["applicability" => self::applicability(), "dependency" => "INDEPENDENT", "eligibility" => "SUBJECT_TO_CONFIRMATION", "site" => null, "arrangement" => null];
                    $limitations[] = "zone_scoped_pickup";
                    continue;
                }
                $services = array_merge($services, $this->services($method, $capture, $limitations));
            }
            if (!$services) {
                $services[] = $this->service("wc:zone:" . $zone["id"] . ":no-delivery", !empty($capture["runtime_filters"]) ? null : "NOT_OFFERED");
            }
            if (count($services) > 64) {
                return self::error("too_many_services");
            }
            $zones[] = ["areas" => $areas, "applicability" => self::applicability(), "services" => $services];
        }
        if (count($zones) > 64) {
            return self::error("too_many_zones");
        }
        if (!empty($capture["block_pickup"])) {
            $collection = ["applicability" => self::applicability(), "dependency" => "INDEPENDENT", "eligibility" => "SUBJECT_TO_CONFIRMATION", "site" => null, "arrangement" => null];
            $limitations[] = "block_pickup_locations";
        }
        if (!$capture["shipping_enabled"]) {
            $zones = [];
        }
        return [
            "object_key" => self::OBJECT_KEY,
            "content" => ["name" => "WooCommerce shipping", "attributedPartyId" => null, "terms" => [
                "schema" => "SHIP_TERMS_V1",
                "shipping" => ["kind" => "COVERAGE", "coverage" => [
                    "reference_release" => self::REFERENCE_RELEASE, "completeness" => "COMPLETE",
                    "applicability" => self::applicability(), "exclusions" => [], "zones" => $zones, "dispatch_origin" => null,
                ]],
                "collection" => $collection,
            ]],
            "limitations" => array_values(array_unique($limitations)),
        ];
    }

    private function areas(array $zone, array $capture, array &$limitations)
    {
        $allowed = $capture["shipping_countries"];
        $restricted = $capture["restricted_countries"];
        $areas = [];
        $postcodes = [];
        $country_codes = [];
        $subdivision = false;
        foreach ($zone["locations"] as $location) {
            $code = $location["code"];
            if ("postcode" === $location["type"]) {
                $postcodes[] = $code;
            } elseif ("country" === $location["type"] && in_array($code, explode(" ", self::COUNTRIES), true)) {
                if (!$restricted || in_array($code, $allowed, true)) {
                    $country_codes[] = $code;
                    $areas[] = $this->area("COUNTRY", $code);
                }
            } elseif ("state" === $location["type"]) {
                $parts = explode(":", $code, 2);
                if ($restricted && !in_array($parts[0], $allowed, true)) {
                    continue;
                }
                $subdivision = true;
                $state = $parts[1] ?? "";
                // Core WooCommerce uses DE:DE-BW, whereas US/CA/AU/CH
                // use short codes (US:CA). Also retain the reviewed DE:BW
                // spelling. Do not manufacture DE-DE-BW or prefix arbitrary codes.
                if ("DE" === $parts[0] && 0 === strpos($state, "DE-")) {
                    $state = substr($state, 3);
                }
                if (count($parts) === 2 && isset(self::STATES[$parts[0]]) && in_array($state, explode(" ", self::STATES[$parts[0]]), true)) {
                    $areas[] = $this->area("SUBDIVISION", $parts[0] . "-" . $state);
                } else {
                    $areas[] = $this->unresolved("unsupported_subdivision", $limitations);
                }
            } elseif ("continent" === $location["type"] && isset($capture["continents"][$code])) {
                foreach ($capture["continents"][$code] as $country) {
                    if (!$restricted || in_array($country, $allowed, true)) {
                        if (in_array($country, explode(" ", self::COUNTRIES), true)) {
                            $areas[] = $this->area("COUNTRY", $country);
                            $country_codes[] = $country;
                        } else {
                            $areas[] = $this->unresolved("unsupported_country", $limitations);
                        }
                    }
                }
            } else {
                $areas[] = $this->unresolved("unsupported_zone_location", $limitations);
            }
        }
        if (0 === $zone["id"] || !$zone["locations"]) {
            // Core also matches a zone with no locations worldwide. Keep that
            // zone in its original position and apply the store restrictions.
            $areas = [["kind" => "ANYWHERE"]];
            if ($restricted) {
                $areas = [];
                foreach ($allowed as $code) {
                    $areas[] = in_array($code, explode(" ", self::COUNTRIES), true) ? $this->area("COUNTRY", $code) : $this->unresolved("unsupported_country", $limitations);
                }
            }
        } elseif ($postcodes) {
            // WooCommerce combines region ORs with a postal AND. Aura areas are
            // ORs, so only a single whole-country scope can be translated exactly.
            $countries = array_values(array_unique($country_codes));
            if ($subdivision || count($countries) !== 1 || count($areas) !== count($country_codes)) {
                $areas = [$this->unresolved("postal_region_intersection", $limitations)];
            } else {
                $areas = [];
                foreach ($postcodes as $postcode) {
                    $form = null;
                    if ("DE" === $countries[0] && preg_match('/\A([0-9]{1,4})\*\z/', $postcode, $match)) {
                        $form = ["kind" => "GERMAN_PREFIX", "prefix" => $match[1]];
                    } elseif ("DE" === $countries[0] && preg_match('/\A([0-9]{5})\.\.\.([0-9]{5})\z/', $postcode, $match) && strcmp($match[1], $match[2]) <= 0) {
                        $form = ["kind" => "GERMAN_RANGE", "first" => $match[1], "last" => $match[2]];
                    } elseif (preg_match('/\A[A-Za-z0-9][A-Za-z0-9 -]{0,63}\z/', $postcode)) {
                        $form = ["kind" => "EXACT", "code" => $postcode];
                    }
                    $areas[] = $form ? ["kind" => "POSTAL", "version" => "POSTAL_RULE_V1", "country" => $countries[0], "reference_release" => self::REFERENCE_RELEASE, "form" => $form] : $this->unresolved("unsupported_postcode", $limitations);
                }
            }
        }
        if (!$areas && !$restricted) {
            // Unrecognized locations must not expose a later definite offer.
            $areas[] = $this->unresolved("empty_zone_locations", $limitations);
        }
        if (count($areas) > 64) {
            // Do not truncate a matching set and accidentally expose later zones.
            return [$this->unresolved("too_many_zone_areas", $limitations)];
        }
        return array_values(array_unique($areas, SORT_REGULAR));
    }

    private function area($kind, $code)
    {
        return ["kind" => $kind, "code" => $code, "reference_release" => self::REFERENCE_RELEASE];
    }

    private function unresolved($code, array &$limitations)
    {
        $limitations[] = $code;
        return ["kind" => "UNRESOLVED", "assertion" => $code];
    }

    private function service($code, $eligibility, $rate = null, $applicability = null)
    {
        return ["code" => $code, "applicability" => $applicability ?? self::applicability(), "eligibility" => $eligibility, "rate" => $rate,
            "lead_times" => ["handling" => null, "transit" => null, "total" => null], "features" => [], "arranger" => null, "performer" => null];
    }

    private function rate($amount, $currency, $basis, $taxable = false)
    {
        return ["price" => null === $amount ? ["kind" => "EXTERNALLY_CALCULATED", "currency" => $currency]
            : (0 === $amount ? ["kind" => "FREE", "currency" => $currency] : ["kind" => "FIXED", "amount" => ["amount_minor" => $amount, "currency" => $currency]]),
            "basis" => $basis, "qualifying_scope" => null, "components" => $taxable ? (object) ["TAX" => "EXCLUDED"] : (object) []];
    }

    private function services(array $method, array $capture, array &$limitations)
    {
        $code = "wc:" . $method["id"] . ":" . $method["instance_id"];
        $currency = $capture["currency"];
        if (empty($method["core"]) || !empty($capture["runtime_filters"])) {
            $limitations[] = "runtime_shipping_method";
            return [$this->service($code, null, $this->rate(null, $currency, null))];
        }
        $options = $method["options"];
        if ("free_shipping" === $method["id"]) {
            $condition = $options["requires"];
            $applicability = self::applicability();
            $eligibility = "" === $condition ? "OFFERED" : null;
            if (in_array($condition, ["min_amount", "both"], true)) {
                $minimum = self::minor_units($options["min_amount"], $currency);
                if (null !== $minimum) {
                    $applicability["threshold"] = ["minimum" => ["amount_minor" => $minimum, "currency" => $currency], "scope" => "SELLER_ORDER"];
                }
            }
            if (null === $eligibility) {
                $limitations[] = "free_shipping_" . (in_array($condition, ["min_amount", "coupon", "both", "either"], true) ? $condition : "unsupported_condition");
            }
            return [$this->service($code, $eligibility, $this->rate(0, $currency, "PER_SHIPMENT"), $applicability)];
        }
        if ("flat_rate" !== $method["id"]) {
            $limitations[] = "unsupported_shipping_method";
            return [$this->service($code, null, $this->rate(null, $currency, null))];
        }
        $base = self::cost($options["cost"], $currency);
        $has_class_costs = false;
        foreach ($options["class_costs"] as $expression) {
            $has_class_costs = $has_class_costs || "" !== $expression;
        }
        $taxable = $capture["tax_enabled"] && "taxable" === $options["tax_status"];
        if (!$has_class_costs) {
            if ("" === $options["cost"]) {
                return [$this->service($code, "NOT_OFFERED")];
            }
            if (null !== $base) {
                return [$this->service($code, "OFFERED", $this->rate($base["amount"], $currency, $base["basis"], $taxable))];
            }
            $limitations[] = "unsupported_cost_expression";
        } elseif ("class" === $options["type"] && ("" === $options["cost"] || (null !== $base && 0 === $base["amount"]))) {
            // With no shared base, per-class quantity-only costs are truly per
            // item even in mixed-class packages. Fixed class fees are not.
            $services = [];
            foreach ($options["class_costs"] as $class => $expression) {
                $cost = self::cost($expression, $currency);
                if ("" !== $expression && (null === $cost || (0 !== $cost["amount"] && "PER_ITEM" !== $cost["basis"]))) {
                    $services = [];
                    break;
                }
                $applicability = self::applicability();
                $applicability["class"] = self::class_code($class);
                $offered = "" !== $expression || "" !== $options["cost"];
                $services[] = $this->service($code . ":" . self::class_code($class), $offered ? "OFFERED" : "NOT_OFFERED",
                    $offered ? $this->rate($cost["amount"] ?? 0, $currency, "PER_ITEM", $taxable) : null, $applicability);
            }
            if ($services) {
                return $services;
            }
            $limitations[] = "class_costs_per_class";
        } else {
            $limitations[] = "order" === $options["type"] ? "class_costs_per_order" : "class_costs_per_class";
        }
        return [$this->service($code, null, $this->rate(null, $currency, "PER_SHIPMENT", $taxable))];
    }

    private function supported_settings(array $method)
    {
        $options = $method["options"];
        if ("flat_rate" === $method["id"]) {
            return in_array($options["type"] ?? null, ["class", "order"], true) && in_array($options["tax_status"] ?? null, ["taxable", "none"], true);
        }
        if ("free_shipping" === $method["id"]) {
            return in_array($options["requires"] ?? null, ["", "min_amount", "coupon", "both", "either"], true)
                && in_array($options["ignore_discounts"] ?? null, ["yes", "no"], true);
        }
        return true;
    }

    /** Map only scoped inputs; no physical eligibility or arbitrary item metadata. */
    public function item(array $item)
    {
        $classes = array_values(array_unique(array_map([self::class, "class_code"], $item["class_ids"])));
        $unambiguous = count($classes) === 1;
        return [
            "source_listing_id" => (string) $item["id"],
            "selection" => ["kind" => $item["virtual"] ? "SUPPRESS_INHERITANCE" : "INHERIT_SOURCE_DEFAULT"],
            "rate_inputs" => ["classes" => ["codes" => $unambiguous ? $classes : [], "completeness" => $unambiguous ? "COMPLETE" : "PARTIAL"],
                "variants" => $item["variants"] ?: null],
        ];
    }

    public static function error($code)
    {
        return new \WP_Error("ahpc_shipping_" . $code, __("Shipping acquisition needs review. Previous assertions were preserved.", "aura-historia-partner-connect"));
    }
}
