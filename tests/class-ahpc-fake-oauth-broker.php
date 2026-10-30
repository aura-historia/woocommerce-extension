<?php
/**
 * In-memory OAuth broker for integration tests.
 *
 * @package AuraHistoria\PartnerConnect
 */

use AuraHistoria\PartnerConnect\OAuth_Broker_Client;
use AuraHistoria\PartnerConnect\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Implements the broker side of protocol version 2 the way the webapp does:
 * it stores the completion challenge, issues a fresh completion code only when
 * a result is ready, and redeems a result once for the matching code and
 * secret. Tests can replace any response to exercise failure handling.
 */
class AHPC_Fake_OAuth_Broker
{
    const BASE_URL = "https://broker.example";

    /** @var array<string,array<string,mixed>> */
    public $transactions = [];

    /** @var array<int,array{url:string,body:array,options:array,headers:array}> */
    public $requests = [];

    /** @var array<int,Response|Throwable|callable> Responses used before the default behavior. */
    public $queue = [];

    /** @var callable|null Called with the request body before a default completion response. */
    public $before_completion = null;

    /** @var string|null */
    public $last_transaction_id = null;

    /**
     * @return Client
     */
    public function client()
    {
        return new Client(["handler" => HandlerStack::create([$this, "handle"])]);
    }

    /**
     * Guzzle handler.
     *
     * @param RequestInterface $request Request.
     * @param array            $options Request options.
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function handle(RequestInterface $request, array $options)
    {
        $body = json_decode((string) $request->getBody(), true);
        $this->requests[] = [
            "url" => (string) $request->getUri(),
            "method" => $request->getMethod(),
            "body" => is_array($body) ? $body : [],
            "raw" => (string) $request->getBody(),
            "options" => $options,
            "headers" => $request->getHeaders(),
        ];

        if ($this->queue) {
            $next = array_shift($this->queue);
            if (is_callable($next)) {
                $next = $next($body, $request);
            }

            return $next instanceof \Throwable ? Create::rejectionFor($next) : Create::promiseFor($next);
        }

        $path = $request->getUri()->getPath();
        if (OAuth_Broker_Client::INITIATION_PATH === $path) {
            return Create::promiseFor($this->initiate(is_array($body) ? $body : []));
        }
        if (OAuth_Broker_Client::COMPLETION_PATH === $path) {
            if ($this->before_completion) {
                ($this->before_completion)($body);
            }

            return Create::promiseFor($this->complete(is_array($body) ? $body : []));
        }

        return Create::promiseFor(self::json(404, ["error" => "not_found"]));
    }

    /**
     * Simulates consent and returns the merchant callback fields.
     *
     * @param string|null $transaction_id Transaction, default the latest.
     * @param array       $result         Overrides for the ready result.
     * @return array{transaction_id:string,state:string,completion_code:string}
     */
    public function approve($transaction_id = null, array $result = [])
    {
        $transaction_id = $transaction_id ?: $this->last_transaction_id;
        $code = rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
        $this->transactions[$transaction_id]["code_hash"] = hash("sha256", $code);
        $this->transactions[$transaction_id]["result"] = array_merge([
            "outcome" => "approved",
            "listing_source_id" => "ls_01jw7j4azge00800000000000g",
            "access_token" => "aurahistoria_abcdefghijk_verylongtokenvalue",
            "token_type" => "Bearer",
            "scope" => Plugin::OAUTH_SCOPE,
        ], $result);

        return [
            "transaction_id" => $transaction_id,
            "state" => $this->transactions[$transaction_id]["client_state"],
            "completion_code" => $code,
        ];
    }

    /**
     * Simulates a denial on the consent page.
     *
     * @param string|null $transaction_id Transaction, default the latest.
     * @return array{transaction_id:string,state:string,completion_code:string}
     */
    public function deny($transaction_id = null)
    {
        return $this->approve($transaction_id, ["outcome" => "denied"]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function completion_requests()
    {
        return array_values(array_filter($this->requests, static function ($request) {
            return OAuth_Broker_Client::COMPLETION_PATH === wp_parse_url($request["url"], PHP_URL_PATH);
        }));
    }

    /**
     * @param array $body Initiation body.
     * @return Response
     */
    private function initiate(array $body)
    {
        foreach (["client_id", "merchant_redirect_uri", "client_state", "scope", "completion_challenge"] as $field) {
            if (!isset($body[$field]) || !is_string($body[$field]) || "" === $body[$field]) {
                return self::json(400, ["error" => "invalid_request"]);
            }
        }
        if (2 !== ($body["protocol_version"] ?? null)) {
            return self::json(400, ["error" => "unsupported_protocol_version"]);
        }
        if ("S256" !== ($body["completion_challenge_method"] ?? null)) {
            return self::json(400, ["error" => "invalid_request"]);
        }

        $transaction_id = "obt_" . bin2hex(random_bytes(12));
        $this->last_transaction_id = $transaction_id;
        $this->transactions[$transaction_id] = $body + ["consumed" => false];

        return self::json(201, [
            "protocol_version" => 2,
            "transaction_id" => $transaction_id,
            "authorization_url" => self::authorization_url($body["client_id"], $body["scope"], "ref_" . bin2hex(random_bytes(16))),
            "expires_at" => gmdate("Y-m-d\\TH:i:s\\Z", time() + 600),
        ]);
    }

    /**
     * @param array $body Completion body.
     * @return Response
     */
    private function complete(array $body)
    {
        if (2 !== ($body["protocol_version"] ?? null)) {
            return self::json(400, ["error" => "unsupported_protocol_version"]);
        }

        $transaction_id = isset($body["transaction_id"]) && is_string($body["transaction_id"]) ? $body["transaction_id"] : "";
        if (!isset($this->transactions[$transaction_id])) {
            return self::json(400, ["error" => "invalid_completion_code"]);
        }

        $transaction = $this->transactions[$transaction_id];
        if ($transaction["consumed"]) {
            return self::json(409, ["error" => "transaction_consumed"]);
        }
        if (empty($transaction["code_hash"])) {
            return self::json(409, ["error" => "transaction_pending"]);
        }
        if (!is_string($body["completion_code"] ?? null) || !hash_equals($transaction["code_hash"], hash("sha256", $body["completion_code"]))) {
            return self::json(400, ["error" => "invalid_completion_code"]);
        }
        $challenge = rtrim(strtr(base64_encode(hash("sha256", (string) ($body["completion_secret"] ?? ""), true)), "+/", "-_"), "=");
        if (!is_string($body["completion_secret"] ?? null) || !hash_equals($transaction["completion_challenge"], $challenge)) {
            return self::json(401, ["error" => "invalid_completion_proof"]);
        }

        $this->transactions[$transaction_id]["consumed"] = true;
        $result = $transaction["result"];
        if ("approved" !== $result["outcome"]) {
            $result = array_intersect_key($result, ["outcome" => true, "error" => true]);
        }

        return self::json(200, array_merge([
            "protocol_version" => 2,
            "transaction_id" => $transaction_id,
            "client_state" => $transaction["client_state"],
        ], $result));
    }

    /**
     * @param string $client_id Client ID.
     * @param string $scope     Scope.
     * @param string $state     Opaque broker reference.
     * @return string
     */
    public static function authorization_url($client_id, $scope, $state)
    {
        return self::BASE_URL . "/en/oauth/authorize?" . http_build_query([
            "client_id" => $client_id,
            "redirect_uri" => self::BASE_URL . "/api/oauth/client/redirect-broker/woocommerce",
            "response_type" => "code",
            "scope" => $scope,
            "state" => $state,
            "code_challenge" => str_repeat("A", 43),
            "code_challenge_method" => "S256",
            "requires_listing_source_id" => "true",
        ], "", "&", PHP_QUERY_RFC3986);
    }

    /**
     * @param int   $status Status.
     * @param array $body   Body.
     * @return Response
     */
    public static function json($status, array $body)
    {
        return new Response($status, ["Content-Type" => "application/json"], wp_json_encode($body));
    }
}
