<?php
/**
 * Plugin Name: Aura Historia Partner Connect e2e fake broker
 * Description: Test-only stand-in for the webapp OAuth broker and the Aura Historia API. Loaded only by the browser tests.
 *
 * The browser tests map this directory into wp-content/mu-plugins and define
 * AHPC_E2E_FAKE_BROKER. Nothing here ships with the plugin.
 *
 * @package AuraHistoria\PartnerConnect
 */

if (!defined("ABSPATH") || !defined("AHPC_E2E_FAKE_BROKER") || !AHPC_E2E_FAKE_BROKER) {
    return;
}

const AHPC_E2E_BROKER_OPTION = "ahpc_e2e_fake_broker";

/**
 * Encodes bytes as base64url without padding.
 *
 * @param string $bytes Bytes.
 * @return string
 */
function ahpc_e2e_base64url($bytes)
{
    return rtrim(strtr(base64_encode($bytes), "+/", "-_"), "=");
}

/**
 * @param int   $status Status.
 * @param array $body   Body.
 * @return \GuzzleHttp\Promise\PromiseInterface
 */
function ahpc_e2e_json($status, array $body)
{
    return \GuzzleHttp\Promise\Create::promiseFor(
        new \GuzzleHttp\Psr7\Response($status, ["Content-Type" => "application/json"], wp_json_encode($body)),
    );
}

/**
 * Broker side of protocol version 2, persisted across requests.
 *
 * @param \Psr\Http\Message\RequestInterface $request Request.
 * @return \GuzzleHttp\Promise\PromiseInterface
 */
function ahpc_e2e_broker_handler($request)
{
    $body = json_decode((string) $request->getBody(), true);
    $body = is_array($body) ? $body : [];
    $store = get_option(AHPC_E2E_BROKER_OPTION, []);
    $store = is_array($store) ? $store : [];
    $path = $request->getUri()->getPath();

    if (2 !== ($body["protocol_version"] ?? null)) {
        return ahpc_e2e_json(400, ["error" => "unsupported_protocol_version"]);
    }

    if (\AuraHistoria\PartnerConnect\OAuth_Broker_Client::INITIATION_PATH === $path) {
        if ("S256" !== ($body["completion_challenge_method"] ?? null) || isset($body["code_verifier"]) || isset($body["completion_secret"])) {
            return ahpc_e2e_json(400, ["error" => "invalid_request"]);
        }
        $transaction_id = "obt_" . bin2hex(random_bytes(12));
        $reference = "ref_" . bin2hex(random_bytes(16));
        $store[$transaction_id] = [
            "reference" => $reference,
            "client_state" => (string) $body["client_state"],
            "challenge" => (string) $body["completion_challenge"],
            "redirect" => (string) $body["merchant_redirect_uri"],
            "code_hash" => "",
            "outcome" => "",
            "consumed" => false,
        ];
        update_option(AHPC_E2E_BROKER_OPTION, $store, false);
        $base = untrailingslashit(AHPC_OAUTH_BROKER_BASE_URL);

        return ahpc_e2e_json(201, [
            "protocol_version" => 2,
            "transaction_id" => $transaction_id,
            "authorization_url" => $base . "/en/oauth/authorize?" . http_build_query([
                "client_id" => (string) $body["client_id"],
                "redirect_uri" => $base . "/api/oauth/client/redirect-broker/woocommerce",
                "response_type" => "code",
                "scope" => (string) $body["scope"],
                "state" => $reference,
                "code_challenge" => ahpc_e2e_base64url(random_bytes(32)),
                "code_challenge_method" => "S256",
                "requires_listing_source_id" => "true",
            ], "", "&", PHP_QUERY_RFC3986),
            "expires_at" => gmdate("Y-m-d\\TH:i:s\\Z", time() + 600),
        ]);
    }

    if (\AuraHistoria\PartnerConnect\OAuth_Broker_Client::COMPLETION_PATH === $path) {
        $transaction_id = (string) ($body["transaction_id"] ?? "");
        $transaction = $store[$transaction_id] ?? null;
        if (!$transaction || "" === $transaction["code_hash"]) {
            return ahpc_e2e_json(400, ["error" => "invalid_completion_code"]);
        }
        if ($transaction["consumed"]) {
            return ahpc_e2e_json(409, ["error" => "transaction_consumed"]);
        }
        if (!hash_equals($transaction["code_hash"], hash("sha256", (string) ($body["completion_code"] ?? "")))) {
            return ahpc_e2e_json(400, ["error" => "invalid_completion_code"]);
        }
        if (!hash_equals($transaction["challenge"], ahpc_e2e_base64url(hash("sha256", (string) ($body["completion_secret"] ?? ""), true)))) {
            return ahpc_e2e_json(401, ["error" => "invalid_completion_proof"]);
        }
        $store[$transaction_id]["consumed"] = true;
        update_option(AHPC_E2E_BROKER_OPTION, $store, false);
        $result = [
            "protocol_version" => 2,
            "outcome" => $transaction["outcome"],
            "transaction_id" => $transaction_id,
            "client_state" => $transaction["client_state"],
        ];
        if ("approved" === $transaction["outcome"]) {
            $result += [
                "listing_source_id" => "ls_01jw7j4azge00800000000000g",
                "access_token" => "aurahistoria_e2e_fake_token",
                "token_type" => "Bearer",
                "scope" => \AuraHistoria\PartnerConnect\Plugin::OAUTH_SCOPE,
            ];
        }

        return ahpc_e2e_json(200, $result);
    }

    return ahpc_e2e_json(404, ["error" => "not_found"]);
}

add_filter("ahpc_oauth_broker_guzzle_client", static function () {
    return new \GuzzleHttp\Client(["handler" => \GuzzleHttp\HandlerStack::create(static function ($request) {
        return ahpc_e2e_broker_handler($request);
    })]);
});

// The Aura Historia API accepts the ingestion configuration and nothing else,
// so background backfill and shipping work simply retries later.
add_filter("ahpc_backend_guzzle_client", static function () {
    return new \GuzzleHttp\Client(["handler" => \GuzzleHttp\HandlerStack::create(static function ($request) {
        $is_configuration = "PUT" === $request->getMethod() &&
            "/ingestion-configurations/woocommerce" === substr($request->getUri()->getPath(), -strlen("/ingestion-configurations/woocommerce"));

        return \GuzzleHttp\Promise\Create::promiseFor(
            $is_configuration
                ? new \GuzzleHttp\Psr7\Response(204)
                : new \GuzzleHttp\Psr7\Response(503, ["Content-Type" => "application/problem+json"], "{}"),
        );
    })]);
});

// Stands in for the consent page: approve or deny the transaction behind the
// authorization URL's opaque reference, then return to the merchant callback
// the way the broker does.
add_action("admin_post_ahpc_e2e_consent", static function () {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Test-only fixture.
    $reference = isset($_GET["state"]) ? sanitize_text_field(wp_unslash($_GET["state"])) : "";
    $decision = isset($_GET["decision"]) ? sanitize_key(wp_unslash($_GET["decision"])) : "approve";
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    $store = get_option(AHPC_E2E_BROKER_OPTION, []);

    foreach ((array) $store as $transaction_id => $transaction) {
        if ($reference !== $transaction["reference"]) {
            continue;
        }

        $code = ahpc_e2e_base64url(random_bytes(32));
        $store[$transaction_id]["code_hash"] = hash("sha256", $code);
        $store[$transaction_id]["outcome"] = "deny" === $decision ? "denied" : "approved";
        update_option(AHPC_E2E_BROKER_OPTION, $store, false);

        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Returns to the stored merchant callback.
        wp_redirect(add_query_arg([
            "transaction_id" => $transaction_id,
            "state" => $transaction["client_state"],
            "completion_code" => $code,
        ], $transaction["redirect"]));
        exit();
    }

    wp_die("Unknown transaction.", 400);
});

// Returns the site to a disconnected store without stored errors.
add_action("admin_post_ahpc_e2e_reset", static function () {
    $manager = new \AuraHistoria\PartnerConnect\Webhook_Manager();
    $manager->delete_webhooks();
    $manager->initialize_options();
    delete_option(AHPC_E2E_BROKER_OPTION);
    wp_die("reset", "", ["response" => 200]);
});
