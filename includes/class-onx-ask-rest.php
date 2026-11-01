<?php
/**
 * REST API.
 *
 * Public (consumed by the static Next.js export):
 *   POST /oncologynexus/v1/ask
 *   GET  /oncologynexus/v1/ask/status
 *   GET  /oncologynexus/v1/ask/suggestions
 *
 * Admin (cookie auth + X-WP-Nonce, manage_options):
 *   POST /oncologynexus/v1/ask/reindex
 *   GET  /oncologynexus/v1/ask/index-status
 *   POST /oncologynexus/v1/ask/flush
 *   POST /oncologynexus/v1/ask/test
 *   GET  /oncologynexus/v1/ask/logs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_REST {

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	/** @var OncologyNexus_Ask_Service */
	private $service;

	/** @var OncologyNexus_Ask_Indexer */
	private $indexer;

	/** @var OncologyNexus_Ask_Vector_Store */
	private $store;

	/** @var OncologyNexus_Ask_LLM */
	private $llm;

	/** @var OncologyNexus_Ask_Embeddings */
	private $embeddings;

	/** @var OncologyNexus_Ask_Security */
	private $security;

	public function __construct(
		OncologyNexus_Ask_Settings $settings,
		OncologyNexus_Ask_Service $service,
		OncologyNexus_Ask_Indexer $indexer,
		OncologyNexus_Ask_Vector_Store $store,
		OncologyNexus_Ask_LLM $llm,
		OncologyNexus_Ask_Embeddings $embeddings,
		OncologyNexus_Ask_Security $security
	) {
		$this->settings   = $settings;
		$this->service    = $service;
		$this->indexer    = $indexer;
		$this->store      = $store;
		$this->llm        = $llm;
		$this->embeddings = $embeddings;
		$this->security   = $security;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			'oncologynexus/v1',
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_ask' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'question'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'conversation' => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
					),
					'post_types'  => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
					),
					'session_id'  => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
				),
			)
		);

		register_rest_route(
			'oncologynexus/v1',
			'/ask/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'oncologynexus/v1',
			'/ask/suggestions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_suggestions' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'oncologynexus/v1',
			'/ask/reindex',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_reindex' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'mode'    => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'all',
						'enum'     => array( 'all', 'recent', 'single' ),
					),
					'post_id' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					),
					'days'    => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 7,
					),
				),
			)
		);

		register_rest_route(
			'oncologynexus/v1',
			'/ask/index-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_index_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'oncologynexus/v1',
			'/ask/flush',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_flush' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			'oncologynexus/v1',
			'/ask/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_test' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'target'   => array(
						'required' => false,
						'type'     => 'string',
						'default'  => 'chat',
						'enum'     => array( 'chat', 'embedding' ),
					),
					'provider' => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
				),
			)
		);

		register_rest_route(
			'oncologynexus/v1',
			'/ask/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_logs' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'limit' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 25,
					),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_ask( WP_REST_Request $request ) {
		// The admin playground runs from wp-admin, whose origin may differ from
		// the public frontend allow-list. Authenticated admins skip the check.
		if ( ! current_user_can( 'manage_options' ) ) {
			$origin = $this->security->check_origin( $request );
			if ( is_wp_error( $origin ) ) {
				return $this->error_response( $origin );
			}
		}

		$result = $this->service->ask(
			array(
				'question'     => $request->get_param( 'question' ),
				'conversation' => $request->get_param( 'conversation' ),
				'post_types'   => $request->get_param( 'post_types' ),
				'session_id'   => $request->get_param( 'session_id' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		$response = rest_ensure_response( $result );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}

	/**
	 * @return WP_REST_Response
	 */
	public function handle_status() {
		$response = rest_ensure_response( $this->service->status() );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/**
	 * @return WP_REST_Response
	 */
	public function handle_suggestions() {
		$response = rest_ensure_response(
			array(
				'brand'       => (string) $this->settings->get( 'brand_name', 'Ask OncoNexus' ),
				'suggestions' => $this->settings->suggested_questions(),
				'disclaimer'  => wp_strip_all_tags( (string) $this->settings->get( 'disclaimer', '' ) ),
			)
		);
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_reindex( WP_REST_Request $request ) {
		$mode = (string) $request->get_param( 'mode' );
		$count = $this->indexer->queue_reindex(
			$mode,
			array(
				'post_id' => (int) $request->get_param( 'post_id' ),
				'days'    => (int) $request->get_param( 'days' ),
			)
		);

		return rest_ensure_response(
			array(
				'queued' => (int) $count,
				'mode'   => $mode,
				'status' => $this->indexer->status(),
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public function handle_index_status() {
		$status = $this->indexer->status();
		$status['stats']  = $this->store->stats();
		$status['config'] = array(
			'chat'       => $this->settings->chat_config(),
			'embedding'  => $this->settings->embedding_config(),
			'configured' => $this->settings->is_configured(),
		);
		$status['logs'] = $this->store->get_logs( 15 );

		return rest_ensure_response( $status );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function handle_flush() {
		$this->indexer->flush();
		return rest_ensure_response(
			array(
				'flushed' => true,
				'status'  => $this->indexer->status(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_test( WP_REST_Request $request ) {
		$target   = (string) $request->get_param( 'target' );
		$provider = (string) $request->get_param( 'provider' );

		if ( 'embedding' === $target ) {
			$provider = $provider ? $provider : $this->settings->embedding_config()['provider'];
			$result   = $this->embeddings->test_connection( $provider, 'embedding' );
		} else {
			$provider = $provider ? $provider : $this->settings->chat_config()['provider'];
			$result   = $this->llm->test_connection( $provider );
		}

		return rest_ensure_response(
			array(
				'target'   => $target,
				'provider' => $provider,
				'ok'       => ! empty( $result['ok'] ),
				'message'  => isset( $result['message'] ) ? $result['message'] : '',
				'details'  => $result,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_logs( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'logs' => $this->store->get_logs( (int) $request->get_param( 'limit' ) ),
			)
		);
	}

	/**
	 * Convert a WP_Error to a predictable JSON error envelope.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_REST_Response
	 */
	private function error_response( WP_Error $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		$payload = array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => is_array( $data ) ? $data : array(),
		);

		$response = new WP_REST_Response( $payload, $status );
		if ( is_array( $data ) && ! empty( $data['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) max( 1, (int) $data['retry_after'] ) );
		}
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}
}
