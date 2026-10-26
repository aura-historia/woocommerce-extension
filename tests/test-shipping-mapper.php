<?php
/**
 * Real PHP mapper output and official-client protocol regressions.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\Shipping_Mapper;
use AuraHistoria\PartnerConnect\InternalApi\ObjectSerializer;
use AuraHistoria\PartnerConnect\InternalApi\Model\CreateShippingProfileData;
use AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingDeliveryCommandData;

class Test_AHPC_Shipping_Mapper extends WP_UnitTestCase
{
    public static function provider_fixtures()
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . "/fixtures/woocommerce-shipping.json"), true);
        $cases = [];
        foreach ($fixtures["cases"] as $case) {
            $cases[$case["name"]] = [$case];
        }
        return $cases;
    }

    public static function capture(array $case)
    {
        $capture = array_replace(["complete" => true, "zones" => [], "currency" => "EUR", "class_ids" => [0, 17],
            "shipping_enabled" => true, "shipping_countries" => [], "restricted_countries" => false,
            "tax_enabled" => false, "runtime_filters" => false, "continents" => [], "block_pickup" => false], $case["settings"] ?? []);
        foreach ($case["zones"] as $zone) {
            $methods = [];
            foreach ($zone["methods"] as $index => $method) {
                $options = "flat_rate" === $method["id"] ? array_replace(["cost" => "", "type" => "class", "tax_status" => "taxable", "class_costs" => [0 => "", 17 => ""]], $method)
                    : array_replace(["requires" => "", "min_amount" => "0", "ignore_discounts" => "no"], $method);
                unset($options["id"], $options["core"]);
                $methods[] = ["id" => $method["id"], "core" => $method["core"] ?? true, "instance_id" => $index + 1, "options" => $options];
            }
            $zone["methods"] = $methods;
            $capture["zones"][] = $zone;
        }
        return $capture;
    }

    /** @dataProvider provider_fixtures */
    public function test_provider_to_aura_fixtures(array $case)
    {
        $mapped = (new Shipping_Mapper())->profile(self::capture($case));
        $this->assertIsArray($mapped);
        $this->assertSame(Shipping_Mapper::OBJECT_KEY, $mapped["object_key"]);
        foreach ($case["expect"] as $path => $expected) {
            $value = $mapped["content"];
            foreach (explode(".", $path) as $part) {
                $this->assertIsArray($value, $path);
                $this->assertArrayHasKey($part, $value, $path);
                $value = $value[$part];
            }
            $this->assertSame($expected, $value, $path);
        }
        foreach ($case["limitations"] ?? [] as $limitation) {
            $this->assertContains($limitation, $mapped["limitations"]);
        }
        $body = $this->create_body($mapped["content"]);
        $serialized = $this->roundtrip($body, CreateShippingProfileData::class);
        $this->assertEquals(json_decode(wp_json_encode($mapped["content"])), $serialized->content);
        $this->assertInstanceOf(stdClass::class, $serialized->import->observation);
        $this->assertStringContainsString(".123456", $serialized->import->acquiredAt);
        $this->assertSame("WOOCOMMERCE", $serialized->import->integration->method);
        $this->assertFalse(property_exists($serialized->import->integration, "domain"));
        $this->assertFileDoesNotExist("/tmp/ahpc-unsafe");
    }

    private function create_body(array $content)
    {
        return ["creationKey" => "fixture-create", "content" => $content, "evidence" => [], "import" => [
            "integration" => Shipping_Mapper::INTEGRATION, "sequence" => 1, "requestKey" => "fixture-request", "objectKey" => Shipping_Mapper::OBJECT_KEY,
            "acquiredAt" => "2026-10-15T10:00:00.123456Z", "observation" => (object) [],
        ]];
    }

    private function roundtrip($body, $model)
    {
        return json_decode(wp_json_encode(ObjectSerializer::sanitizeForSerialization(ObjectSerializer::deserialize(wp_json_encode($body), $model, []))));
    }

    public function test_merged_backend_shipping_fixtures_roundtrip()
    {
        $fixtures = json_decode(file_get_contents(__DIR__ . "/fixtures/shipping-imports.json"));
        foreach (["create", "providerCreate"] as $key) {
            $out = $this->roundtrip($fixtures->$key, CreateShippingProfileData::class);
            $this->assertEquals($fixtures->$key->content, $out->content);
            $this->assertEquals($fixtures->$key->import->observation, $out->import->observation);
        }
        foreach (["membership", "providerMembership"] as $key) {
            $out = $this->roundtrip($fixtures->$key, ProductListingDeliveryCommandData::class);
            $this->assertEquals($fixtures->$key->delivery, $out->delivery);
        }
        $this->assertSame("object", \AuraHistoria\PartnerConnect\InternalApi\Model\ShippingImportWriteData::openAPITypes()["observation"]);
        $spec = file_get_contents(dirname(__DIR__) . "/openapi/internal-api.filtered.yaml");
        $this->assertStringContainsString("maximum: 9223372036854775807", $spec);
        $this->assertStringContainsString("maximum: 18446744073709551615", $spec);
    }

    public static function money_cases()
    {
        return [["0.29", "EUR", 29], ["001.20", "EUR", 120], ["5.000", "EUR", 500], ["0.001", "EUR", null],
            ["150", "JPY", 150], ["150.10", "JPY", null], ["99.00", "KRW", 99], ["1e3", "EUR", null], ["-1", "EUR", null],
            ["1,20", "EUR", null], ["92233720368547758.07", "EUR", PHP_INT_MAX], ["92233720368547758.08", "EUR", null], ["1", "XXX", null]];
    }

    /** @dataProvider money_cases */
    public function test_minor_units_are_exact($value, $currency, $expected)
    {
        $this->assertSame($expected, Shipping_Mapper::minor_units($value, $currency));
    }

    public static function quantity_costs()
    {
        return [["0 * [qty]", 0], ["[qty] * 0", 0], ["00.00 * [qty]", 0], ["2.03 * [qty]", 203], ["[qty]", 100]];
    }

    /** @dataProvider quantity_costs */
    public function test_quantity_costs_preserve_explicit_zero($expression, $amount)
    {
        $this->assertSame(["amount" => $amount, "basis" => "PER_ITEM"], Shipping_Mapper::cost($expression, "EUR"));
    }

    public function test_variants_preserve_identity_without_universal_first_class()
    {
        $mapper = new Shipping_Mapper();
        $mapped = $mapper->item(["id" => 123, "class_ids" => [17, 18], "variants" => ["wc:variation:125", "wc:variation:126"], "virtual" => false]);
        $this->assertSame("123", $mapped["source_listing_id"]);
        $this->assertSame(["codes" => [], "completeness" => "PARTIAL"], $mapped["rate_inputs"]["classes"]);
        $this->assertSame(["wc:variation:125", "wc:variation:126"], $mapped["rate_inputs"]["variants"]);
        $same = $mapper->item(["id" => 123, "class_ids" => [17, 17], "variants" => ["wc:variation:125", "wc:variation:126"], "virtual" => false]);
        $this->assertSame(["codes" => ["wc:class:17"], "completeness" => "COMPLETE"], $same["rate_inputs"]["classes"]);
        $virtual = $mapper->item(["id" => 456, "class_ids" => [0], "variants" => [], "virtual" => true]);
        $this->assertSame(["kind" => "SUPPRESS_INHERITANCE"], $virtual["selection"]);
        $this->assertSame(["wc:class:none"], $virtual["rate_inputs"]["classes"]["codes"]);
        $this->assertArrayNotHasKey("shipping", $virtual);
    }

    public function test_incomplete_and_oversized_scopes_are_never_complete_replacements()
    {
        $mapper = new Shipping_Mapper();
        $capture = self::capture(["zones" => []]);
        $capture["complete"] = false;
        $this->assertWPError($mapper->profile($capture));
        $capture["complete"] = true;
        $capture["zones"] = array_fill(0, 65, ["id" => 0, "locations" => [], "methods" => []]);
        $this->assertWPError($mapper->profile($capture));
    }
}
