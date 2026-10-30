<?php
/**
 * Aura Historia OAuth broker transaction client.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use WP_Error;

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Validated server-to-server client for the webapp's WooCommerce OAuth broker
 * transactions (protocol version 2). The broker performs consent and the OAuth
 * exchange; this plugin only registers a transaction and later redeems its
 * result with the callback's completion code and the locally held secret.
 *
 * Every request body carries a completion secret or code, so requests use
 * Guzzle directly (no WordPress HTTP hooks), verify TLS, never follow
 * redirects and accept only small JSON bodies. Errors carry safe categories,
 * never request or response bodies.
 */
class OAuth_Broker_Client
{
    const PROTOCOL_VERSION = 2;
    const INITIATION_PATH = "/api/oauth/client/redirect-broker/woocommerce/transactions";
    const COMPLETION_PATH = "/api/oauth/client/redirect-broker/woocommerce/transactions/complete";
    const TIMEOUT = 15;
    const CONNECT_TIMEOUT = 10;
    const MAX_RESPONSE_BYTES = 16384;
    const MAX_URL_LENGTH = 4096;
    /** Opaque broker transaction reference. */
    const TRANSACTION_ID_PATTERN = '/\A[A-Za-z0-9_-]{16,128}\z/';
    /** At least 32 random bytes as base64url without padding. */
    const COMPLETION_CODE_PATTERN = '/\A[A-Za-z0-9_-]{43,512}\z/';
    const ERROR_PATTERN = '/\A[a-z][a-z0-9_]{0,63}\z/';
    /** Authorization routes the broker may send the browser to, on its own origin. */
    const AUTHORIZATION_PATH_PATTERN = '#\A/(?:[a-z]{2}(?:-[a-z]{2})?/)?oauth/authorize\z#';
    const AUTHORIZATION_PARAMETERS = [
        "client_id",
        "redirect_uri",
        "response_type",
        "scope",
        "state",
        "code_challenge",
        "code_challenge_method",
        "requires_listing_source_id",
    ];
    const COMPLETION_FIELDS = [
        "protocol_version",
        "outcome",
        "transaction_id",
        "client_state",
        "listing_source_id",
        "access_token",
        "token_type",
        "scope",
        "expires_in",
        "error",
    ];

    /**
     * Broker origin, such as https://aura-historia.com.
     *
     * @var string
     */
    private $base_url;

    /**
     * @param string $base_url Validated broker origin.
     */
    public function __construct($base_url)
    {
        $this->base_url = self::is_valid_base_url($base_url) ? untrailingslashit($base_url) : "";
    }

    /**
     * Accepts an HTTPS origin, or plain HTTP only on a loopback host for local
     * development. Paths, queries, fragments and credentials are rejected so
     * the endpoints and the authorization origin are fully determined.
     *
     * @param mixed $url Configured broker base URL.
     * @return bool
     */
    public static function is_valid_base_url($url)
    {
        if (!is_string($url) || "" === $url || strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        $parts = wp_parse_url($url);

        return is_array($parts) &&
            self::is_allowed_origin($parts) &&
            (!isset($parts["path"]) || "" === $parts["path"] || "/" === $parts["path"]) &&
            !isset($parts["query"]) &&
            !isset($parts["fragment"]);
    }

    /**
     * Whether a parsed URL is HTTPS, or HTTP on a loopback host, without
     * credentials.
     *
     * @param array<string,mixed> $parts Parsed URL.
     * @return bool
     */
    public static function is_allowed_origin(array $parts)
    {
        if (empty($parts["scheme"]) || empty($parts["host"]) || isset($parts["user"]) || isset($parts["pass"])) {
            return false;
        }

        $scheme = strtolower((string) $parts["scheme"]);
        $host = strtolower(trim((string) $parts["host"], "[]"));

        return "https" === $scheme ||
            ("http" === $scheme && in_array($host, ["localhost", "127.0.0.1", "::1"], true));
    }

    /**
     * @return string Broker initiation endpoint, or an empty string.
     */
    public function get_initiation_url()
    {
        return "" === $this->base_url ? "" : $this->base_url . self::INITIATION_PATH;
    }

    /**
     * @return string Broker completion endpoint, or an empty string.
     */
    public function get_completion_url()
    {
        return "" === $this->base_url ? "" : $this->base_url . self::COMPLETION_PATH;
    }

    /**
     * Registers a broker transaction.
     *
     * @param array{client_id:string,merchant_redirect_uri:string,client_state:string,scope:string,completion_challenge:string} $request Initiation fields.
     * @return array{transaction_id:string,authorization_url:string,expires_at:int}|WP_Error
     */
    public function initiate(array $request)
    {
        $response = $this->post($this->get_initiation_url(), [
            "protocol_version" => self::PROTOCOL_VERSION,
            "client_id" => (string) $request["client_id"],
            "merchant_redirect_uri" => (string) $request["merchant_redirect_uri"],
            "client_state" => (string) $request["client_state"],
            "scope" => (string) $request["scope"],
            "completion_challenge" => (string) $request["completion_challenge"],
            "completion_challenge_method" => "S256",
        ], [200, 201]);

        if (is_wp_error($response)) {
            return $response;
        }

        $expires_at = isset($response["expires_at"]) ? self::parse_timestamp($response["expires_at"]) : 0;

        if (
            self::PROTOCOL_VERSION !== ($response["protocol_version"] ?? null) ||
            !isset($response["transaction_id"], $response["authorization_url"]) ||
            !is_string($response["transaction_id"]) ||
            1 !== preg_match(self::TRANSACTION_ID_PATTERN, $response["transaction_id"]) ||
            !$this->is_trusted_authorization_url($response["authorization_url"], $request["client_id"], $request["scope"]) ||
            $expires_at <= 0
        ) {
            return self::invalid_response_error();
        }

        if ($expires_at <= time()) {
            return new WP_Error(
                "ahpc_oauth_broker_expired",
                __("Aura Historia returned a connection attempt that has already expired. Check this server's clock, then start the connection again.", "aura-historia-partner-connect"),
            );
        }

        return [
            "transaction_id" => $response["transaction_id"],
            "authorization_url" => $response["authorization_url"],
            "expires_at" => $expires_at,
        ];
    }

    /**
     * Redeems a ready broker result once. Validates the result's shape and
     * formats; the caller compares the echoed transaction and state with its
     * own local attempt.
     *
     * @param string $transaction_id    Locally stored transaction ID.
     * @param string $completion_code   Code delivered by the merchant callback.
     * @param string $completion_secret Locally retained completion secret.
     * @return array<string,string>|WP_Error An approved, denied or failed outcome.
     */
    public function complete($transaction_id, $completion_code, $completion_secret)
    {
        $response = $this->post($this->get_completion_url(), [
            "protocol_version" => self::PROTOCOL_VERSION,
            "transaction_id" => (string) $transaction_id,
            "completion_code" => (string) $completion_code,
            "completion_secret" => (string) $completion_secret,
        ], [200]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (
            self::PROTOCOL_VERSION !== ($response["protocol_version"] ?? null) ||
            array_diff(array_keys($response), self::COMPLETION_FIELDS) ||
            !isset($response["outcome"], $response["transaction_id"], $response["client_state"]) ||
            !is_string($response["transaction_id"]) ||
            !is_string($response["client_state"])
        ) {
            return self::invalid_response_error();
        }

        $result = [
            "outcome" => $response["outcome"],
            "transaction_id" => $response["transaction_id"],
            "client_state" => $response["client_state"],
        ];

        if ("denied" === $response["outcome"] || "failed" === $response["outcome"]) {
            $credential_fields = array_intersect(array_keys($response), ["listing_source_id", "access_token", "token_type", "scope", "expires_in"]);
            if ($credential_fields || (isset($response["error"]) && !self::is_error_code($response["error"]))) {
                return self::invalid_response_error();
            }
            $result["error"] = isset($response["error"]) ? $response["error"] : "";
            return $result;
        }

        // Tokens without a refresh flow must not expire; any expiry or
        // unexpected credential metadata is unsupported rather than ignored.
        if (
            "approved" !== $response["outcome"] ||
            isset($response["error"]) ||
            (array_key_exists("expires_in", $response) && null !== $response["expires_in"]) ||
            !isset($response["listing_source_id"], $response["access_token"], $response["token_type"], $response["scope"]) ||
            !is_string($response["listing_source_id"]) ||
            !is_string($response["access_token"]) ||
            !is_string($response["token_type"]) ||
            !is_string($response["scope"]) ||
            strlen($response["access_token"]) > 4096 ||
            strlen($response["scope"]) > 1024
        ) {
            return self::invalid_response_error();
        }

        return $result + [
            "listing_source_id" => $response["listing_source_id"],
            "access_token" => $response["access_token"],
            "token_type" => $response["token_type"],
            "scope" => $response["scope"],
        ];
    }

    /**
     * Checks that the browser is sent only to the configured broker origin's
     * authorization route with the requested client and scope, and that the
     * URL carries no verifier, secret, token or readable broker state.
     *
     * @param mixed  $url       Returned authorization URL.
     * @param string $client_id Configured OAuth client ID.
     * @param string $scope     Requested scope.
     * @return bool
     */
    public function is_trusted_authorization_url($url, $client_id, $scope)
    {
        if (
            "" === $this->base_url ||
            !is_string($url) ||
            strlen($url) > self::MAX_URL_LENGTH ||
            preg_match('/[\s\x00-\x1F\x7F]/', $url)
        ) {
            return false;
        }

        $parts = wp_parse_url($url);
        $origin = wp_parse_url($this->base_url);

        if (
            !is_array($parts) ||
            !is_array($origin) ||
            !self::is_allowed_origin($parts) ||
            !self::same_origin($parts, $origin) ||
            isset($parts["fragment"]) ||
            !isset($parts["path"], $parts["query"]) ||
            1 !== preg_match(self::AUTHORIZATION_PATH_PATTERN, $parts["path"])
        ) {
            return false;
        }

        $query = self::parse_unique_query($parts["query"]);

        if (
            null === $query ||
            array_diff(array_keys($query), self::AUTHORIZATION_PARAMETERS) ||
            ($query["client_id"] ?? null) !== $client_id ||
            ($query["response_type"] ?? null) !== "code" ||
            ($query["scope"] ?? null) !== $scope ||
            !isset($query["state"]) ||
            "" === $query["state"] ||
            strlen($query["state"]) > 1024 ||
            self::is_readable_state($query["state"]) ||
            (isset($query["code_challenge_method"]) && "S256" !== $query["code_challenge_method"]) ||
            (isset($query["requires_listing_source_id"]) && "true" !== $query["requires_listing_source_id"])
        ) {
            return false;
        }

        if (isset($query["redirect_uri"])) {
            $redirect = wp_parse_url($query["redirect_uri"]);
            if (!is_array($redirect) || !self::is_allowed_origin($redirect) || !self::same_origin($redirect, $origin) || isset($redirect["fragment"])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parses a query string, rejecting duplicate or array-style keys.
     *
     * @param string $query Raw query string.
     * @return array<string,string>|null
     */
    public static function parse_unique_query($query)
    {
        $values = [];

        foreach (explode("&", (string) $query) as $pair) {
            if ("" === $pair) {
                continue;
            }

            $separator = strpos($pair, "=");
            $key = urldecode(false === $separator ? $pair : substr($pair, 0, $separator));
            $value = false === $separator ? "" : urldecode(substr($pair, $separator + 1));

            if ("" === $key || false !== strpbrk($key, "[]") || array_key_exists($key, $values)) {
                return null;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * Whether the value is a broker error identifier.
     *
     * @param mixed $error Error code.
     * @return bool
     */
    public static function is_error_code($error)
    {
        return is_string($error) && 1 === preg_match(self::ERROR_PATTERN, $error);
    }

    /**
     * Sends one JSON request and returns the decoded object of an expected
     * status, or a safe error.
     *
     * @param string        $url      Configured endpoint.
     * @param array         $body     Request fields.
     * @param array<int>    $statuses Accepted HTTP statuses.
     * @return array<string,mixed>|WP_Error
     */
    private function post($url, array $body, array $statuses)
    {
        if ("" === $url) {
            return new WP_Error(
                "ahpc_oauth_broker_not_configured",
                __("Aura Historia OAuth is not fully configured: the connection service URL is invalid.", "aura-historia-partner-connect"),
            );
        }

        if (!class_exists(GuzzleClient::class)) {
            return new WP_Error(
                "ahpc_oauth_broker_unavailable",
                __("The plugin's HTTP client is not available, so the connection cannot start. Reinstall the plugin and try again.", "aura-historia-partner-connect"),
            );
        }

        $client = new GuzzleClient();
        $filtered = apply_filters("ahpc_oauth_broker_guzzle_client", $client, $url);
        $client = $filtered instanceof ClientInterface ? $filtered : $client;
        $limit = self::MAX_RESPONSE_BYTES;

        try {
            // Request options override any client defaults: a redirect must
            // never forward the secret-bearing body to another endpoint.
            $response = $client->request("POST", $url, [
                "body" => wp_json_encode($body),
                "headers" => [
                    "Accept" => "application/json",
                    "Content-Type" => "application/json",
                    "Cache-Control" => "no-store",
                ],
                "allow_redirects" => false,
                "verify" => true,
                "http_errors" => false,
                "timeout" => self::TIMEOUT,
                "connect_timeout" => self::CONNECT_TIMEOUT,
                "version" => "1.1",
                "on_headers" => static function ($response) use ($limit) {
                    $length = $response->getHeaderLine("Content-Length");
                    if ("" !== $length && (!ctype_digit($length) || (int) $length > $limit)) {
                        throw new \RuntimeException("Response too large.");
                    }
                },
                "sink" => self::bounded_sink($limit),
            ]);
        } catch (\Throwable $exception) {
            unset($exception);
            return self::unavailable_error();
        }

        $status = (int) $response->getStatusCode();
        $raw = (string) $response->getBody();

        if (strlen($raw) > $limit) {
            return self::invalid_response_error();
        }

        $data = json_decode($raw, true, 8);

        if (!in_array($status, $statuses, true)) {
            return self::rejection_error($status, is_array($data) ? $data : []);
        }

        return is_array($data) && !array_is_list($data) ? $data : self::invalid_response_error();
    }

    /**
     * A response sink that stops the transfer once it exceeds the limit.
     *
     * @param int $limit Maximum body bytes.
     * @return StreamInterface
     */
    private static function bounded_sink($limit)
    {
        return new class(Utils::streamFor(Utils::tryFopen("php://temp", "w+")), $limit) implements StreamInterface {
            use StreamDecoratorTrait {
                __construct as private decorate;
            }

            /** @var StreamInterface */
            private $stream;

            /** @var int */
            private $limit;

            public function __construct(StreamInterface $stream, $limit)
            {
                $this->decorate($stream);
                $this->limit = (int) $limit;
            }

            public function write($string): int
            {
                // A short write makes cURL abort the transfer.
                if ((int) $this->stream->getSize() + strlen((string) $string) > $this->limit) {
                    return 0;
                }

                return $this->stream->write($string);
            }
        };
    }

    /**
     * Maps a broker error response to a safe, actionable message. Only a
     * bounded error identifier from the body is kept.
     *
     * @param int                 $status HTTP status.
     * @param array<string,mixed> $data   Decoded body, if any.
     * @return WP_Error
     */
    private static function rejection_error($status, array $data)
    {
        $error = isset($data["error"]) && self::is_error_code($data["error"]) ? $data["error"] : "";
        $error_data = ["response_code" => $status];
        if ("" !== $error) {
            $error_data["broker_error"] = $error;
        }

        if (408 === $status || 429 === $status || $status >= 500 || "temporarily_unavailable" === $error) {
            return self::unavailable_error($error_data);
        }

        switch ($error) {
            case "unsupported_protocol_version":
                $message = __("Aura Historia no longer accepts this plugin's connection protocol. Update Aura Historia Partner Connect, then start the connection again.", "aura-historia-partner-connect");
                break;
            case "invalid_client":
            case "invalid_scope":
                $message = __("Aura Historia did not accept this plugin's OAuth client configuration. Check AHPC_OAUTH_CLIENT_ID for this environment.", "aura-historia-partner-connect");
                break;
            case "invalid_destination":
            case "unauthorized_destination":
                $message = __("Aura Historia did not accept this store's address as a connection destination. Make sure WordPress uses its public HTTPS admin address, then start the connection again.", "aura-historia-partner-connect");
                break;
            case "transaction_expired":
                $message = __("The Aura Historia connection attempt has expired. Start the connection again from this page.", "aura-historia-partner-connect");
                break;
            case "transaction_consumed":
                $message = __("This Aura Historia connection result was already used. Start the connection again from this page.", "aura-historia-partner-connect");
                break;
            case "transaction_pending":
                $message = __("Aura Historia had not finished this connection attempt. Start the connection again from this page.", "aura-historia-partner-connect");
                break;
            case "invalid_completion_code":
            case "invalid_completion_proof":
                $message = __("Aura Historia could not verify this connection attempt. Start the connection again from this page.", "aura-historia-partner-connect");
                break;
            default:
                $message = __("Aura Historia rejected the connection request. Start the connection again from this page.", "aura-historia-partner-connect");
        }

        return new WP_Error("ahpc_oauth_broker_rejected", $message, $error_data);
    }

    /**
     * @param array<string,mixed> $data Safe error data.
     * @return WP_Error
     */
    private static function unavailable_error(array $data = [])
    {
        return new WP_Error(
            "ahpc_oauth_broker_unavailable",
            __("Aura Historia could not be reached to complete this step. Wait a few minutes, then start the connection again from this page.", "aura-historia-partner-connect"),
            $data,
        );
    }

    /**
     * @return WP_Error
     */
    private static function invalid_response_error()
    {
        return new WP_Error(
            "ahpc_oauth_broker_invalid_response",
            __("Aura Historia returned an unexpected connection response, so no settings were changed. Start the connection again from this page.", "aura-historia-partner-connect"),
        );
    }

    /**
     * @param array<string,mixed> $a Parsed URL.
     * @param array<string,mixed> $b Parsed URL.
     * @return bool
     */
    private static function same_origin(array $a, array $b)
    {
        $scheme = strtolower((string) $a["scheme"]);

        return $scheme === strtolower((string) $b["scheme"]) &&
            strtolower((string) $a["host"]) === strtolower((string) $b["host"]) &&
            self::port($a, $scheme) === self::port($b, $scheme);
    }

    /**
     * @param array<string,mixed> $parts  Parsed URL.
     * @param string              $scheme Lowercase scheme.
     * @return int
     */
    private static function port(array $parts, $scheme)
    {
        return isset($parts["port"]) ? (int) $parts["port"] : ("https" === $scheme ? 443 : 80);
    }

    /**
     * Rejects the retired handshake, whose state was readable base64url JSON.
     *
     * @param string $state Authorization state.
     * @return bool
     */
    private static function is_readable_state($state)
    {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]+\z/', $state)) {
            return false;
        }

        $decoded = base64_decode(strtr($state, "-_", "+/"), true);

        return is_string($decoded) && is_array(json_decode($decoded, true));
    }

    /**
     * Parses an RFC 3339 timestamp.
     *
     * @param mixed $value Timestamp string.
     * @return int Unix time, or 0 when invalid.
     */
    private static function parse_timestamp($value)
    {
        if (!is_string($value) || 1 !== preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            return 0;
        }

        $timestamp = strtotime($value);

        return false === $timestamp ? 0 : (int) $timestamp;
    }
}
