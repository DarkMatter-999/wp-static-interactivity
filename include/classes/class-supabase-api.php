<?php
/**
 * Supabase API class for handling requests to Supabase.
 *
 * @package DM_Static_Interactivity
 */

namespace DM_Static_Interactivity;

use WP_Error;

/**
 * Supabase API class for handling requests to Supabase.
 *
 * A stateless static utility - there is nothing to instantiate, so this
 * intentionally does not use the Singleton trait.
 *
 * @since 1.0.0
 */
class Supabase_API {

	/**
	 * Default request timeout, in seconds. Batch pushes (e.g. syncing 50
	 * comments/posts at once) can take longer than WP's default 5s timeout.
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 15;

	/**
	 * Makes a request to the Supabase API.
	 *
	 * @param string     $endpoint The API endpoint to call.
	 * @param string     $method The HTTP method to use (default: 'GET').
	 * @param array|null $body The request body (default: null).
	 * @param array      $custom_headers Custom headers to include in the request (default: empty array).
	 *
	 * @return array|WP_Error The response from the Supabase API or a WP_Error on failure.
	 */
	public static function request( $endpoint, $method = 'GET', $body = null, $custom_headers = array() ) {
		$url = Options::supabase_url();
		$key = Options::supabase_secret_key();

		if ( empty( $url ) || empty( $key ) ) {
			return new WP_Error( 'supabase_config_missing', 'Supabase URL or Secret Key is missing.' );
		}

		$url = rtrim( $url, '/' ) . '/rest/v1/' . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => $method,
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array_merge(
				array(
					'apikey'        => $key,
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Prefer'        => 'return=representation',
				),
				$custom_headers
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $response_code >= 400 ) {
			return new WP_Error( 'supabase_api_error', 'Supabase API returned ' . $response_code, $response_body );
		}

		return $response_body;
	}
}
