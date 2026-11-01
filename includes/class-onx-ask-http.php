<?php
/**
 * Small JSON HTTP client used by the LLM and embedding providers.
 *
 * All provider calls go through WordPress' HTTP API so proxies, TLS settings and
 * the `http_request_args` filters of the host work as expected. Requests are
 * retried on transient provider errors (429 / 5xx) with a short backoff.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_HTTP {

	/**
	 * POST a JSON body and decode the JSON response.
	 *
	 * @param string               $url     Endpoint.
	 * @param array<string,mixed>  $body    JSON-serialisable body.
	 * @param array<string,string> $headers Request headers.
	 * @param int                  $timeout Timeout in seconds.
	 * @param int                  $retries Number of retries for 429/5xx.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function post_json( $url, array $body, array $headers = array(), $timeout = 45, $retries = 1 ) {
		$headers['Content-Type'] = isset( $headers['Content-Type'] ) ? $headers['Content-Type'] : 'application/json';
		$args = array(
			'timeout'     => max( 5, (int) $timeout ),
			'redirection' => 3,
			'headers'     => $headers,
			'body'        => wp_json_encode( $body ),
			'data_format' => 'body',
		);

		return self::request( 'POST', $url, $args, $retries );
	}

	/**
	 * GET a JSON endpoint.
	 *
	 * @param string               $url     Endpoint.
	 * @param array<string,string> $headers Request headers.
	 * @param int                  $timeout Timeout in seconds.
	 * @param int                  $retries Retries.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_json( $url, array $headers = array(), $timeout = 20, $retries = 1 ) {
		$args = array(
			'timeout'     => max( 5, (int) $timeout ),
			'redirection' => 3,
			'headers'     => $headers,
		);

		return self::request( 'GET', $url, $args, $retries );
	}

	/**
	 * @param string              $method  HTTP method.
	 * @param string              $url     Endpoint.
	 * @param array<string,mixed> $args    wp_remote_* args.
	 * @param int                 $retries Retries.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function request( $method, $url, array $args, $retries ) {
		$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 45;
		$timeout = (int) apply_filters( 'onx_ask_http_timeout', $timeout, $url );

		$attempt = 0;
		do {
			$response = 'GET' === $method
				? wp_remote_get( $url, $args )
				: wp_remote_post( $url, $args );

			if ( is_wp_error( $response ) ) {
				if ( $attempt < $retries ) {
					$attempt++;
					usleep( 400000 * $attempt );
					continue;
				}
				return new WP_Error(
					'onx_ask_http_error',
					sprintf(
						/* translators: %s: HTTP error message. */
						__( 'Could not reach the AI provider: %s', 'onx-ask' ),
						$response->get_error_message()
					)
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = (string) wp_remote_retrieve_body( $response );
			$data   = json_decode( $raw, true );

			if ( $status >= 200 && $status < 300 ) {
				if ( ! is_array( $data ) ) {
					return new WP_Error( 'onx_ask_bad_json', __( 'The AI provider returned an invalid JSON response.', 'onx-ask' ) );
				}
				return $data;
			}

			// Retry rate limits and transient upstream failures.
			if ( in_array( $status, array( 408, 409, 425, 429, 500, 502, 503, 504 ), true ) && $attempt < $retries ) {
				$attempt++;
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$delay       = is_numeric( $retry_after ) ? min( 5, max( 1, (int) $retry_after ) ) : $attempt;
				usleep( (int) ( $delay * 1000000 ) );
				continue;
			}

			$message = self::extract_error_message( $data, $raw );
			return new WP_Error(
				'onx_ask_provider_error',
				sprintf(
					/* translators: 1: HTTP status, 2: provider error message. */
					__( 'The AI provider returned HTTP %1$d: %2$s', 'onx-ask' ),
					$status,
					$message
				),
				array( 'status' => $status )
			);
		} while ( $attempt <= $retries );

		return new WP_Error( 'onx_ask_http_error', __( 'The AI provider request failed.', 'onx-ask' ) );
	}

	/**
	 * Best-effort extraction of a readable error from an OpenAI / Anthropic /
	 * Gemini error envelope. Keeps the message short and never echoes headers.
	 *
	 * @param mixed  $data Decoded body.
	 * @param string $raw  Raw body.
	 * @return string
	 */
	public static function extract_error_message( $data, $raw = '' ) {
		$message = '';

		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				$message = (string) $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$message = $data['error'];
			} elseif ( isset( $data['message'] ) ) {
				$message = (string) $data['message'];
			} elseif ( isset( $data[0]['error']['message'] ) ) {
				$message = (string) $data[0]['error']['message'];
			}
		}

		if ( '' === $message && '' !== $raw ) {
			$message = wp_strip_all_tags( substr( $raw, 0, 300 ) );
		}

		$message = trim( preg_replace( '/\s+/', ' ', $message ) );
		return '' !== $message ? $message : __( 'Unknown provider error.', 'onx-ask' );
	}
}
