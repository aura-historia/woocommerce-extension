<?php
/** Production wire contract checks with in-memory HTTP responses. */

use AuraHistoria\PartnerConnect\InternalApi\Api\ListingSourcesApi;
use AuraHistoria\PartnerConnect\InternalApi\Api\OAuthApi;
use AuraHistoria\PartnerConnect\InternalApi\Api\ProductListingsApi;
use AuraHistoria\PartnerConnect\InternalApi\Configuration;
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
    public function test_connection_and_backfill_use_the_production_wire_contract()
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ["Content-Type" => "application/json"], json_encode([
                "access_token" => "fake-opaque-token", "token_type" => "Bearer",
                "scope" => "product-listings:read product-listings:write listing-sources:read listing-sources:write",
            ])),
            new Response(204),
            new Response(202, ["Content-Type" => "application/json"], json_encode([
                "submissionId" => "01970f22-2bf0-7000-8000-000000000010", "acceptedCount" => 1, "failures" => [],
            ])),
        ]));
        $stack->push(Middleware::history($history));
        $http = new Client(["handler" => $stack]);
        $config = (new Configuration())->setHost("https://api.aura-historia.com");
        $code = "01970f22-2bf0-7000-8000-000000000099";
        $source = "ls_01jw7j4azge00800000000000g";

        $token = (new OAuthApi($http, $config))->oauthTokenByThirdPartyCode($code);
        $this->assertSame("Bearer", $token->getTokenType());
        $this->assertNull($token->getExpiresIn());
        $config->setAccessToken($token->getAccessToken());
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
        $this->assertSame("/v1/oauth/tokens/by-third-party-code/" . $code, $history[0]["request"]->getUri()->getPath());
        $this->assertFalse($history[0]["request"]->hasHeader("Authorization"));
        $this->assertSame("/v1/listing-sources/$source/ingestion-configurations/woocommerce", $history[1]["request"]->getUri()->getPath());
        $this->assertSame("Bearer fake-opaque-token", $history[1]["request"]->getHeaderLine("Authorization"));
        $this->assertSame("fake-signing-secret", json_decode((string) $history[1]["request"]->getBody(), true)["webhookSecret"]);
        $this->assertSame("/v1/listing-sources/$source/product-listings/async", $history[2]["request"]->getUri()->getPath());
        $this->assertSame("stable-retry-key", $history[2]["request"]->getHeaderLine("Idempotency-Key"));
        $this->assertSame($payloads, json_decode((string) $history[2]["request"]->getBody(), true));
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

    private function assert_import_equals($expected, $actual)
    {
        $this->assertSame((new DateTimeImmutable($expected->acquiredAt))->format("U.u"), (new DateTimeImmutable($actual->acquiredAt))->format("U.u"));
        $expected = clone $expected;
        $actual = clone $actual;
        unset($expected->acquiredAt, $actual->acquiredAt);
        $this->assertEquals($expected, $actual);
    }
}
