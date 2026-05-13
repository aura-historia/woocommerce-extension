#!/usr/bin/env node
/**
 * Generates includes/class-backend-api-client.php from the pinned OpenAPI spec.
 *
 * Usage: npm run generate:api
 *
 * The output file is committed to the repository. Regenerating with the same
 * pinned spec commit produces identical output, making builds deterministic.
 *
 * Requires: js-yaml (npm install)
 */

'use strict';

const https = require( 'node:https' );
const path  = require( 'node:path' );
const fs    = require( 'node:fs' );
const yaml  = require( 'js-yaml' );

// ---------------------------------------------------------------------------
// Config — update SPEC_COMMIT when you want to pick up a newer spec version.
// ---------------------------------------------------------------------------

const SPEC_REPO       = 'aura-historia/internal-api';
const SPEC_COMMIT     = 'cce00e537cbc3a7dccab84b4346e1473bea6f1a4';
const SPEC_FILE       = 'swagger.yaml';
const SPEC_RAW_URL    = `https://raw.githubusercontent.com/${ SPEC_REPO }/${ SPEC_COMMIT }/${ SPEC_FILE }`;
const SPEC_BROWSE_URL = `https://github.com/${ SPEC_REPO }/blob/${ SPEC_COMMIT }/${ SPEC_FILE }`;

const OUTPUT_FILE = path.resolve( __dirname, '..', 'includes', 'class-backend-api-client.php' );

// ---------------------------------------------------------------------------
// HTTP fetch — follows a single redirect (raw.githubusercontent redirects)
// ---------------------------------------------------------------------------

/**
 * Fetches the contents of a URL as a UTF-8 string.
 *
 * @param {string} url
 * @returns {Promise<string>}
 */
function httpsGet( url ) {
return new Promise( ( resolve, reject ) => {
https
.get( url, { headers: { 'User-Agent': 'ahpc-openapi-gen/1.0' } }, ( res ) => {
if ( res.statusCode >= 300 && res.statusCode < 400 && res.headers.location ) {
return httpsGet( res.headers.location ).then( resolve ).catch( reject );
}
if ( res.statusCode !== 200 ) {
return reject( new Error( `HTTP ${ res.statusCode } for ${ url }` ) );
}
const chunks = [];
res.on( 'data', ( chunk ) => chunks.push( chunk ) );
res.on( 'end', () => resolve( Buffer.concat( chunks ).toString( 'utf8' ) ) );
res.on( 'error', reject );
} )
.on( 'error', reject );
} );
}

// ---------------------------------------------------------------------------
// PHP code generation
// ---------------------------------------------------------------------------

/**
 * Collects HTTP 4xx/5xx entries from an OpenAPI responses map.
 *
 * @param {Record<string,any>} responses
 * @returns {{ code: number, description: string }[]}
 */
function collectErrorResponses( responses ) {
return Object.entries( responses || {} )
.filter( ( [ code ] ) => parseInt( code, 10 ) >= 400 )
.map( ( [ code, obj ] ) => ( {
code:        parseInt( code, 10 ),
description: ( obj.description || '' ).split( '\n' )[ 0 ].trim(),
} ) );
}

/**
 * Generates the PHP source for class-backend-api-client.php.
 *
 * @param {Record<string,any>} spec Parsed OpenAPI spec object.
 * @returns {string}
 */
function generatePhp( spec ) {
const apiVersion = ( spec.info && spec.info.version ) ? spec.info.version : 'unknown';

const patchShopOp =
spec.paths &&
spec.paths[ '/api/v1/shops/{shopId}' ] &&
spec.paths[ '/api/v1/shops/{shopId}' ].patch;

if ( ! patchShopOp ) {
throw new Error( 'Expected PATCH /api/v1/shops/{shopId} operation not found in spec.' );
}

const errorResponses = collectErrorResponses( patchShopOp.responses );

const today = new Date().toISOString().slice( 0, 10 );

const lines = [];

lines.push( '<?php' );
lines.push( '/**' );
lines.push( ' * Generated Backend API client.' );
lines.push( ' *' );
lines.push( ' * @package AuraHistoria\\PartnerConnect' );
lines.push( ' *' );
lines.push( ' * THIS FILE IS AUTO-GENERATED — do not edit manually.' );
lines.push( ' * Regenerate with: npm run generate:api' );
lines.push( ' *' );
lines.push( ` * OpenAPI spec version : ${ apiVersion }` );
lines.push( ` * Spec commit          : ${ SPEC_COMMIT }` );
lines.push( ` * Spec source          : ${ SPEC_BROWSE_URL }` );
lines.push( ` * Generated on         : ${ today }` );
lines.push( ' */' );
lines.push( '' );
lines.push( 'namespace AuraHistoria\\PartnerConnect;' );
lines.push( '' );
lines.push( 'use WP_Error;' );
lines.push( '' );
lines.push( "if ( ! defined( 'ABSPATH' ) ) {" );
lines.push( '\texit();' );
lines.push( '}' );
lines.push( '' );
lines.push( '/**' );
lines.push( ' * Typed HTTP client for the Aura Historia Backend API.' );
lines.push( ' *' );
lines.push( ' * Generated from the OpenAPI spec at:' );
lines.push( ` * ${ SPEC_BROWSE_URL }` );
lines.push( ' *' );
lines.push( " * All HTTP communication goes through WordPress's wp_safe_remote_request() so" );
lines.push( ' * that WordPress filters and proxy settings are respected.' );
lines.push( ' */' );
lines.push( 'class Backend_Api_Client {' );
lines.push( '' );
lines.push( '\t/**' );
lines.push( '\t * Backend base URL without a trailing slash.' );
lines.push( '\t *' );
lines.push( '\t * @var string' );
lines.push( '\t */' );
lines.push( '\tprivate string $base_url;' );
lines.push( '' );
lines.push( '\t/**' );
lines.push( '\t * @param string $base_url Backend base URL (trailing slash is stripped).' );
lines.push( '\t */' );
lines.push( '\tpublic function __construct( string $base_url ) {' );
lines.push( '\t\t$this->base_url = untrailingslashit( $base_url );' );
lines.push( '\t}' );
lines.push( '' );
lines.push( '\t// -------------------------------------------------------------------------' );
lines.push( '\t// Operations' );
lines.push( '\t// -------------------------------------------------------------------------' );
lines.push( '' );
lines.push( '\t/**' );
lines.push( '\t * Update shop details (partial).' );
lines.push( '\t *' );
lines.push( '\t * operationId : patchShopById' );
lines.push( '\t * Method      : PATCH' );
lines.push( '\t * Path        : /api/v1/shops/{shopId}' );
lines.push( '\t *' );
lines.push( '\t * The $body array accepts PatchShopData fields as specified in the OpenAPI' );
lines.push( '\t * schema. Plugin-specific fields not yet present in the spec (e.g.' );
lines.push( '\t * woocommerceWebhookSecret) may also be included and are forwarded as-is.' );
if ( errorResponses.length ) {
lines.push( '\t *' );
lines.push( '\t * Error responses defined in the spec:' );
for ( const { code, description } of errorResponses ) {
lines.push( `\t *   - HTTP ${ code }: ${ description }` );
}
}
lines.push( '\t *' );
lines.push( '\t * @param string              $shop_id Shop UUID.' );
lines.push( '\t * @param string              $api_key Partner API key (x-api-key header).' );
lines.push( '\t * @param array<string,mixed> $body    Partial PatchShopData fields to update.' );
lines.push( '\t * @return true|WP_Error' );
lines.push( '\t */' );
lines.push( '\tpublic function patch_shop_by_id(' );
lines.push( '\t\tstring $shop_id,' );
lines.push( '\t\tstring $api_key,' );
lines.push( '\t\tarray $body,' );
lines.push( '\t): true|WP_Error {' );
lines.push( "\t\t\$url = \$this->base_url . '/api/v1/shops/' . rawurlencode( \$shop_id );" );
lines.push( '' );
lines.push( "\t\t\$response = wp_safe_remote_request( \$url, [" );
lines.push( "\t\t\t'method'      => 'PATCH'," );
lines.push( "\t\t\t'timeout'     => 15," );
lines.push( "\t\t\t'redirection' => 0," );
lines.push( "\t\t\t'httpversion' => '1.1'," );
lines.push( "\t\t\t'blocking'    => true," );
lines.push( "\t\t\t'headers'     => [" );
lines.push( "\t\t\t\t'Content-Type' => 'application/json'," );
lines.push( "\t\t\t\t'Accept'       => 'application/json'," );
lines.push( "\t\t\t\t'x-api-key'    => \$api_key," );
lines.push( "\t\t\t]," );
lines.push( "\t\t\t'body' => wp_json_encode( \$body )," );
lines.push( "\t\t] );" );
lines.push( '' );
lines.push( "\t\tif ( is_wp_error( \$response ) ) {" );
lines.push( "\t\t\treturn new WP_Error(" );
lines.push( "\t\t\t\t'ahpc_backend_request_failed'," );
lines.push( "\t\t\t\tsprintf(" );
lines.push( "\t\t\t\t\t/* translators: %s: WP_Error message. */" );
lines.push( "\t\t\t\t\t__(" );
lines.push( "\t\t\t\t\t\t'Backend request failed: %s'," );
lines.push( "\t\t\t\t\t\t'aura-historia-partner-connect'," );
lines.push( "\t\t\t\t\t)," );
lines.push( "\t\t\t\t\t\$response->get_error_message()," );
lines.push( "\t\t\t\t)," );
lines.push( "\t\t\t);" );
lines.push( "\t\t}" );
lines.push( '' );
lines.push( "\t\t\$status = (int) wp_remote_retrieve_response_code( \$response );" );
lines.push( '' );
lines.push( "\t\tif ( \$status >= 200 && \$status < 300 ) {" );
lines.push( "\t\t\treturn true;" );
lines.push( "\t\t}" );
lines.push( '' );
lines.push( "\t\treturn new WP_Error(" );
lines.push( "\t\t\t'ahpc_backend_request_failed'," );
lines.push( "\t\t\t\$this->format_error_response( \$response, \$status )," );
lines.push( "\t\t);" );
lines.push( "\t}" );
lines.push( '' );
lines.push( '\t// -------------------------------------------------------------------------' );
lines.push( '\t// Helpers' );
lines.push( '\t// -------------------------------------------------------------------------' );
lines.push( '' );
lines.push( '\t/**' );
lines.push( '\t * Builds a human-readable error string from a backend HTTP error response.' );
lines.push( '\t *' );
lines.push( '\t * Inspects the ApiError schema fields defined in the spec (message, error,' );
lines.push( '\t * detail) and appends the first non-empty value found to the HTTP status line.' );
lines.push( '\t *' );
lines.push( '\t * @param array $response WordPress HTTP response array.' );
lines.push( '\t * @param int   $status   HTTP status code.' );
lines.push( '\t * @return string' );
lines.push( '\t */' );
lines.push( '\tprivate function format_error_response( array $response, int $status ): string {' );
lines.push( '\t\t$message = sprintf(' );
lines.push( '\t\t\t/* translators: %d: HTTP status code. */' );
lines.push( '\t\t\t__(' );
lines.push( "\t\t\t\t'The backend returned HTTP %d.'," );
lines.push( "\t\t\t\t'aura-historia-partner-connect'," );
lines.push( '\t\t\t),' );
lines.push( '\t\t\t$status,' );
lines.push( '\t\t);' );
lines.push( '' );
lines.push( "\t\t\$body = trim( (string) wp_remote_retrieve_body( \$response ) );" );
lines.push( '' );
lines.push( "\t\tif ( '' !== \$body ) {" );
lines.push( "\t\t\t\$decoded = json_decode( \$body, true );" );
lines.push( "\t\t\tif ( is_array( \$decoded ) ) {" );
lines.push( "\t\t\t\tforeach ( [ 'message', 'error', 'detail' ] as \$key ) {" );
lines.push( "\t\t\t\t\tif ( ! empty( \$decoded[ \$key ] ) && is_string( \$decoded[ \$key ] ) ) {" );
lines.push( "\t\t\t\t\t\t\$message .= ' ' . sanitize_text_field(" );
lines.push( "\t\t\t\t\t\t\twp_strip_all_tags( \$decoded[ \$key ] )," );
lines.push( "\t\t\t\t\t\t);" );
lines.push( "\t\t\t\t\t\tbreak;" );
lines.push( "\t\t\t\t\t}" );
lines.push( "\t\t\t\t}" );
lines.push( "\t\t\t}" );
lines.push( "\t\t}" );
lines.push( '' );
lines.push( "\t\treturn \$message;" );
lines.push( "\t}" );
lines.push( '}' );
lines.push( '' );

return lines.join( '\n' );
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
console.log( 'Fetching OpenAPI spec…' );
console.log( `  commit : ${ SPEC_COMMIT }` );
console.log( `  url    : ${ SPEC_RAW_URL }` );

const raw  = await httpsGet( SPEC_RAW_URL );
const spec = yaml.load( raw );

const apiTitle   = ( spec.info && spec.info.title )   ? spec.info.title   : 'API';
const apiVersion = ( spec.info && spec.info.version ) ? spec.info.version : '?';
console.log( `Parsed spec: ${ apiTitle } v${ apiVersion }` );

const php = generatePhp( spec );

fs.writeFileSync( OUTPUT_FILE, php, 'utf8' );
console.log( `Generated: ${ path.relative( process.cwd(), OUTPUT_FILE ) }` );
}

main().catch( ( err ) => {
console.error( 'Error:', err.message );
process.exit( 1 );
} );
