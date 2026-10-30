<?php
/** Production wire contract checks with in-memory HTTP responses. */

use AuraHistoria\PartnerConnect\InternalApi\Api\ListingSourcesApi;
use AuraHistoria\PartnerConnect\InternalApi\Api\ProductListingsApi;
use AuraHistoria\PartnerConnect\InternalApi\Configuration;
use AuraHistoria\PartnerConnect\InternalApi\Model\AssignShippingDefaultData;
use AuraHistoria\PartnerConnect\InternalApi\Model\CreateShippingProfileData;
use AuraHistoria\PartnerConnect\InternalApi\Model\ProductListingDeliveryCommandData;
use AuraHistoria\PartnerConnect\InternalApi\Model\PutWoocommerceListingSourceIngestionConfigurationData;
use AuraHistoria\PartnerConnect\InternalApi\ObjectSerializer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class Test_AHPC_Production_Api_Contract extends TestCase
{
    public function test_configuration_and_backfill_use_the_production_wire_contract()
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(204),
            new Response(202, ["Content-Type" => "application/json"], json_encode([
                "submissionId" => "01970f22-2bf0-7000-8000-000000000010", "acceptedCount" => 1, "failures" => [],
            ])),
        ]));
        $stack->push(Middleware::history($history));
        $http = new Client(["handler" => $stack]);
        $config = (new Configuration())->setHost("https://api.aura-historia.com");
        $source = "ls_01jw7j4azge00800000000000g";

        // The connection's token comes from the webapp broker, not this API.
        $config->setAccessToken("fake-opaque-token");
        (new ListingSourcesApi($http, $config))->putWoocommerceListingSourceIngestionConfiguration(
            $source,
            new PutWoocommerceListingSourceIngestionConfigurationData([
                "webhook_secret" => "fake-signing-secret", "currency" => "EUR", "language" => "de",
            ]),
        );
        $payloads = [["sourceListingId" => "42", "url" => "https://merchant.example/product/42", "images" => []]];
        [$report, $status] = (new ProductListingsApi($http, $config))->postAsyncPartnerProductListingsWithHttpInfo($source, $payloads, "stable-retry-key");
        $this->assertSame(202, $status);
        $this->assertSame(1, $report->getAcceptedCount());
        $this->assertSame("/v1/listing-sources/$source/ingestion-configurations/woocommerce", $history[0]["request"]->getUri()->getPath());
        $this->assertSame("Bearer fake-opaque-token", $history[0]["request"]->getHeaderLine("Authorization"));
        $this->assertSame("fake-signing-secret", json_decode((string) $history[0]["request"]->getBody(), true)["webhookSecret"]);
        $this->assertSame("/v1/listing-sources/$source/product-listings/async", $history[1]["request"]->getUri()->getPath());
        $this->assertSame("stable-retry-key", $history[1]["request"]->getHeaderLine("Idempotency-Key"));
        $this->assertSame($payloads, json_decode((string) $history[1]["request"]->getBody(), true));
    }

    public function test_production_shipping_fixtures_preserve_empty_objects_and_timestamps()
    {
        $fixtures = json_decode(file_get_contents(dirname(__DIR__) . "/fixtures/shipping-imports.json"));
        foreach (["create", "providerCreate", "membership", "providerMembership"] as $key) {
            $fixtures->$key->import->acquiredAt = "2026-10-15T10:00:00.123456Z";
        }
        foreach (["create", "providerCreate"] as $key) {
            $model = ObjectSerializer::deserialize(json_encode($fixtures->$key), CreateShippingProfileData::class, []);
            $actual = json_decode(json_encode(ObjectSerializer::sanitizeForSerialization($model)));
            $this->assertEquals($fixtures->$key->content, $actual->content);
            $this->assert_import_equals($fixtures->$key->import, $actual->import);
        }
        foreach (["membership", "providerMembership"] as $key) {
            $model = ObjectSerializer::deserialize(json_encode($fixtures->$key), ProductListingDeliveryCommandData::class, []);
            $actual = json_decode(json_encode(ObjectSerializer::sanitizeForSerialization($model)));
            $this->assertEquals($fixtures->$key->delivery, $actual->delivery);
            $this->assert_import_equals($fixtures->$key->import, $actual->import);
        }
    }

    public function test_shipping_publication_uses_production_paths_and_preserves_retry_requests()
    {
        $fixtures = json_decode(file_get_contents(dirname(__DIR__) . "/fixtures/shipping-imports.json"));
        $fixtures->providerCreate->import->acquiredAt = "2026-10-15T10:00:00.123456Z";
        $source = "ls_01jw7j4azge00800000000000g";
        $profile = "shp_01jw7j4azge008000000000004";
        $profile_response = new Response(200, ["Content-Type" => "application/json"], json_encode([
            "shippingProfileId" => $profile, "listingSourceId" => $source,
            "managementRevision" => 1, "termsRevision" => 1, "lifecycle" => "ACTIVE",
            "content" => $fixtures->providerCreate->content,
        ]));
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            $profile_response,
            $profile_response,
            new Response(200, ["Content-Type" => "application/json"], json_encode([
                "listingSourceId" => $source, "revision" => 2,
                "shippingProfileId" => $profile, "eligibility" => "ACTIVE",
            ])),
            new Response(200, ["Content-Type" => "application/json"], json_encode([
                "productListingId" => "pl_01jw7j4azge008000000000005",
                "expectedVersion" => 2, "outcome" => "CHANGED",
            ])),
        ]));
        $stack->push(Middleware::history($history));
        $http = new Client(["handler" => $stack]);
        $config = (new Configuration())->setHost("https://api.aura-historia.com")->setAccessToken("fake-opaque-token");
        $api = new ListingSourcesApi($http, $config);
        $create = ObjectSerializer::deserialize(json_encode($fixtures->providerCreate), CreateShippingProfileData::class, []);
        $this->assertSame($profile, $api->createShippingProfile($source, $create)->getShippingProfileId());
        $this->assertSame($profile, $api->createShippingProfile($source, $create)->getShippingProfileId());
        $default = $api->assignShippingDefault($source, new AssignShippingDefaultData([
            "expected_revision" => 1, "shipping_profile_id" => $profile,
        ]));
        $this->assertSame(2, $default->getRevision());
        $membership = ObjectSerializer::deserialize(json_encode($fixtures->providerMembership), ProductListingDeliveryCommandData::class, []);
        $result = (new ProductListingsApi($http, $config))->changeProductListingDelivery($source, "42", $membership);
        $this->assertSame("CHANGED", $result->getOutcome());

        foreach ($history as $entry) {
            $this->assertSame("Bearer fake-opaque-token", $entry["request"]->getHeaderLine("Authorization"));
        }
        $this->assertSame("POST", $history[0]["request"]->getMethod());
        $this->assertSame("/v1/listing-sources/$source/shipping-profiles", $history[0]["request"]->getUri()->getPath());
        $this->assertSame((string) $history[0]["request"]->getBody(), (string) $history[1]["request"]->getBody());
        $create_body = json_decode((string) $history[0]["request"]->getBody());
        $this->assertSame($fixtures->providerCreate->creationKey, $create_body->creationKey);
        $this->assert_import_equals($fixtures->providerCreate->import, $create_body->import);
        $this->assertSame("PUT", $history[2]["request"]->getMethod());
        $this->assertSame("/v1/listing-sources/$source/shipping-default", $history[2]["request"]->getUri()->getPath());
        $this->assertSame(["expectedRevision" => 1, "shippingProfileId" => $profile], json_decode((string) $history[2]["request"]->getBody(), true));
        $this->assertSame("PATCH", $history[3]["request"]->getMethod());
        $this->assertSame("/v1/listing-sources/$source/product-listings/by-source-id/42/delivery", $history[3]["request"]->getUri()->getPath());
        $membership_body = json_decode((string) $history[3]["request"]->getBody());
        $this->assertEquals($fixtures->providerMembership->delivery, $membership_body->delivery);
        $this->assert_import_equals($fixtures->providerMembership->import, $membership_body->import);
    }

    private function assert_import_equals($expected, $actual)
    {
        $this->assertSame((new DateTimeImmutable($expected->acquiredAt))->format("U.u"), (new DateTimeImmutable($actual->acquiredAt))->format("U.u"));
        $expected = clone $expected;
        $actual = clone $actual;
        unset($expected->acquiredAt, $actual->acquiredAt);
        $this->assertEquals($expected, $actual);
    }
}
