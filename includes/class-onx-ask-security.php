<?php
/**
 * Public-endpoint hardening: origin allow-list, rate limiting, input bounds.
 *
 * The ask endpoint is intentionally public (the Next.js export has no server),
 * so it is protected by:
 *  - an origin allow-list (configurable; `*` disables the check, an empty
 *    list denies browser requests),
 *  - a per-IP sliding-window rate limit,
 *  - a global daily request ceiling,
 *  - strict question / history length limits.
 *
 * API keys never leave WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Security {

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	public function __construct( OncologyNexus_Ask_Settings $settings ) {
		$this->settings = $settings;
		add_filter( 'rest_pre_serve_request', array( $this, 'cors_headers' ), 15, 3 );
		add_filter( 'rest_allowed_cors_headers', array( $this, 'allowed_cors_headers' ) );
	}

	/**
	 * Verify the browser origin for public ask requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_origin( WP_REST_Request $request ) {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			// Server-side calls (WP-CLI, cron, curl) have no Origin header.
			return true;
		}

		$allowed = $this->settings->allowed_origins();
		if ( in_array( '*', $allowed, true ) ) {
			return true;
		}

		// An empty allow-list fails closed: a browser request must match an
		// explicit origin (or pass the filter below).
		$origin = untrailingslashit( $origin );
		foreach ( $allowed as $candidate ) {
			if ( $origin === untrailingslashit( $candidate ) ) {
				return true;
			}
		}

		/**
		 * Filter whether an origin is allowed to call the public ask endpoint.
		 *
		 * @param bool   $allowed Whether allowed.
		 * @param string $origin  Origin header.
		 */
		$allowed_by_filter = (bool) apply_filters( 'onx_ask_allow_origin', false, $origin );
		if ( $allowed_by_filter ) {
			return true;
		}

		return new WP_Error(
			'onx_ask_origin_denied',
			__( 'This site is not allowed to use the assistant.', 'onx-ask' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Sliding-window rate limit.
	 *
	 * @return true|WP_Error
	 */
	public function check_rate_limit() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		/**
		 * Short-circuit rate limiting for trusted integrations.
		 *
		 * @param bool $bypass Whether to bypass.
		 */
		if ( apply_filters( 'onx_ask_bypass_rate_limit', false ) ) {
			return true;
		}

		$limit  = max( 0, (int) $this->settings->get( 'rate_limit_requests', 20 ) );
		$window = max( 60, (int) $this->settings->get( 'rate_limit_window', 600 ) );
		$daily  = max( 0, (int) $this->settings->get( 'daily_request_limit', 1000 ) );
		$now    = time();
		$ip     = $this->client_ip();

		if ( $limit > 0 ) {
			$key  = 'onx_ask_rl_' . md5( $ip );
			$hits = get_transient( $key );
			$hits = is_array( $hits ) ? $hits : array();
			$hits = array_values(
				array_filter(
					$hits,
					static function ( $timestamp ) use ( $now, $window ) {
						return (int) $timestamp > ( $now - $window );
					}
				)
			);

			if ( count( $hits ) >= $limit ) {
				$retry = max( 1, (int) $hits[0] + $window - $now );
				return new WP_Error(
					'onx_ask_rate_limited',
					__( 'You are asking questions too quickly. Please wait a moment and try again.', 'onx-ask' ),
					array(
						'status'      => 429,
						'retry_after' => $retry,
					)
				);
			}

			$hits[] = $now;
			set_transient( $key, $hits, $window );
		}

		if ( $daily > 0 ) {
			$day_key = 'onx_ask_day_' . gmdate( 'Ymd' );
			$count   = (int) get_transient( $day_key );
			if ( $count >= $daily ) {
				return new WP_Error(
					'onx_ask_daily_limit',
					__( 'The assistant has reached its daily request limit. Please try again tomorrow.', 'onx-ask' ),
					array( 'status' => 429 )
				);
			}
			set_transient( $day_key, $count + 1, DAY_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Add CORS headers for our namespace so the static Next.js origin can call
	 * the endpoint cross-origin (including the JSON preflight).
	 *
	 * @param bool             $served  Whether the request has been served.
	 * @param WP_HTTP_Response $result  Response.
	 * @param WP_REST_Request  $request Request.
	 * @return bool
	 */
	public function cors_headers( $served, $result, $request ) {
		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/oncologynexus/v1/ask' ) ) {
			return $served;
		}

		$origin  = (string) $request->get_header( 'origin' );
		$allowed = $this->settings->allowed_origins();

		if ( in_array( '*', $allowed, true ) ) {
			$allow_origin = '*';
		} elseif ( '' !== $origin && ! empty( $allowed ) && in_array( untrailingslashit( $origin ), array_map( 'untrailingslashit', $allowed ), true ) ) {
			$allow_origin = $origin;
		} else {
			$allow_origin = '';
		}

		if ( '' !== $allow_origin ) {
			header( 'Access-Control-Allow-Origin: ' . $allow_origin );
			header( 'Vary: Origin', false );
		}
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, X-WP-Nonce, Cache-Control, Authorization' );
		header( 'Access-Control-Max-Age: 600' );

		return $served;
	}

	/**
	 * @param string[] $headers Allowed headers.
	 * @return string[]
	 */
	public function allowed_cors_headers( $headers ) {
		$headers[] = 'Cache-Control';
		return $headers;
	}

	/**
	 * @return string
	 */
	public function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Only trust X-Forwarded-For when the site is behind a known proxy.
		 *
		 * @param bool $trust Whether to trust the header.
		 */
		if ( apply_filters( 'onx_ask_trust_proxy_headers', false ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$candidate = trim( (string) $forwarded[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		}

		return $ip ? $ip : 'unknown';
	}

	/**
	 * Hashed IP for logs (no raw addresses stored).
	 *
	 * @return string
	 */
	public function ip_hash() {
		return md5( $this->client_ip() . wp_salt( 'nonce' ) );
	}

	/**
	 * @param mixed $question Raw question.
	 * @return string|WP_Error
	 */
	public function sanitize_question( $question ) {
		if ( ! is_string( $question ) ) {
			return new WP_Error( 'onx_ask_invalid_question', __( 'Please enter a question.', 'onx-ask' ), array( 'status' => 400 ) );
		}

		$question = wp_strip_all_tags( $question );
		$question = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $question );
		$question = preg_replace( '/\s+/u', ' ', $question );
		$question = trim( (string) $question );

		if ( mb_strlen( $question, 'UTF-8' ) < 2 ) {
			return new WP_Error( 'onx_ask_invalid_question', __( 'Please enter a question.', 'onx-ask' ), array( 'status' => 400 ) );
		}

		$max = max( 50, (int) $this->settings->get( 'max_question_chars', 500 ) );
		if ( mb_strlen( $question, 'UTF-8' ) > $max ) {
			$question = mb_substr( $question, 0, $max, 'UTF-8' );
		}

		return $question;
	}

	/**
	 * @param mixed $history Raw conversation.
	 * @return array<int,array{role:string,content:string}>
	 */
	public function sanitize_history( $history ) {
		if ( ! is_array( $history ) ) {
			return array();
		}

		$out = array();
		foreach ( array_slice( $history, -8 ) as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}
			$role = 'assistant' === $message['role'] ? 'assistant' : 'user';
			$text = sanitize_textarea_field( (string) $message['content'] );
			$text = preg_replace( '/\s+/u', ' ', $text );
			$text = trim( (string) $text );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'role'    => $role,
				'content' => mb_substr( $text, 0, 3000, 'UTF-8' ),
			);
		}

		return $out;
	}

	/**
	 * Restrict requested post types to those that are actually indexed.
	 *
	 * @param mixed $types Raw types.
	 * @return string[]
	 */
	public function sanitize_post_types( $types ) {
		if ( ! is_array( $types ) ) {
			return array();
		}

		$types     = array_map( 'sanitize_key', $types );
		$available = $this->settings->post_types();
		return array_values( array_intersect( $types, $available ) );
	}
}
