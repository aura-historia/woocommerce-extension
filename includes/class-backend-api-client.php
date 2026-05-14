<?php
/**
 * Generated Backend API client.
 *
 * @package AuraHistoria\PartnerConnect
 *
 * THIS FILE IS AUTO-GENERATED — do not edit manually.
 * Regenerate with: npm run generate:api
 *
 * OpenAPI spec version : 1.0.0
 * Spec commit          : f06b903bf8641d5505aafc89462fd2f356f50386
 * Spec source          : https://github.com/aura-historia/internal-api/blob/f06b903bf8641d5505aafc89462fd2f356f50386/swagger.yaml
 * Generated on         : 2026-05-14
 */

namespace AuraHistoria\PartnerConnect;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Typed HTTP client for the Aura Historia Backend API.
 *
 * Generated from the OpenAPI spec at:
 * https://github.com/aura-historia/internal-api/blob/f06b903bf8641d5505aafc89462fd2f356f50386/swagger.yaml
 *
 * All HTTP communication goes through WordPress's wp_safe_remote_request() so
 * that WordPress filters and proxy settings are respected.
 */
class Backend_Api_Client {

	/**
	 * Backend base URL without a trailing slash.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * @param string $base_url Backend base URL (trailing slash is stripped).
	 */
	public function __construct( string $base_url ) {
		$this->base_url = untrailingslashit( $base_url );
	}

	// -------------------------------------------------------------------------
	// Operations
	// -------------------------------------------------------------------------

	/**
	 * Update shop details (partial).
	 *
	 * operationId : patchShopById
	 * Method      : PATCH
	 * Path        : /api/v1/shops/{shopId}
	 *
	 * The $body array accepts PatchShopData fields as specified in the OpenAPI
	 * schema. See the spec for supported fields (e.g. woocommerceWebhookSecret).
	 *
	 * Error responses defined in the spec:
	 *   - HTTP 400: Bad request - invalid or missing shop ID, empty body, or invalid JSON
	 *   - HTTP 401: Unauthorized – invalid or missing Cognito JWT when using bearer auth, or missing/malformed/mismatched `x-api-key` when using partner API-key auth.
	 *   - HTTP 403: Forbidden – caller is not allowed to update this shop.
	 *   - HTTP 404: Shop not found
	 *   - HTTP 500: Internal server error
	 *
	 * @param string              $shop_id Shop UUID.
	 * @param string              $api_key Partner API key (x-api-key header).
	 * @param array<string,mixed> $body    Partial PatchShopData fields to update.
	 * @return true|WP_Error
	 */
	public function patch_shop_by_id(
		string $shop_id,
		string $api_key,
		array $body,
	): true|WP_Error {
		$url = $this->base_url . '/api/v1/shops/' . rawurlencode( $shop_id );

		$response = wp_safe_remote_request( $url, [
			'method'      => 'PATCH',
			'timeout'     => 15,
			'redirection' => 0,
			'httpversion' => '1.1',
			'blocking'    => true,
			'headers'     => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'x-api-key'    => $api_key,
			],
			'body' => wp_json_encode( $body ),
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ahpc_backend_request_failed',
				sprintf(
					/* translators: %s: WP_Error message. */
					__(
						'Backend request failed: %s',
						'aura-historia-partner-connect',
					),
					$response->get_error_message(),
				),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return true;
		}

		return new WP_Error(
			'ahpc_backend_request_failed',
			$this->format_error_response( $response, $status ),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds a human-readable error string from a backend HTTP error response.
	 *
	 * Inspects the ApiError schema fields defined in the spec (message, error,
	 * detail) and appends the first non-empty value found to the HTTP status line.
	 *
	 * @param array $response WordPress HTTP response array.
	 * @param int   $status   HTTP status code.
	 * @return string
	 */
	private function format_error_response( array $response, int $status ): string {
		$message = sprintf(
			/* translators: %d: HTTP status code. */
			__(
				'The backend returned HTTP %d.',
				'aura-historia-partner-connect',
			),
			$status,
		);

		$body = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( '' !== $body ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				foreach ( [ 'message', 'error', 'detail' ] as $key ) {
					if ( ! empty( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
						$message .= ' ' . sanitize_text_field(
							wp_strip_all_tags( $decoded[ $key ] ),
						);
						break;
					}
				}
			}
		}

		return $message;
	}
}
