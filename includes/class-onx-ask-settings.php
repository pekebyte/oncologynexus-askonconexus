<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * API keys can live in the database (admin UI) or, preferably, in wp-config.php:
 *
 *   define( 'ONX_ASK_OPENAI_API_KEY', 'sk-...' );
 *   define( 'ONX_ASK_ANTHROPIC_API_KEY', 'sk-ant-...' );
 *   define( 'ONX_ASK_GEMINI_API_KEY', 'AIza...' );
 *
 * Constants always win over the database value, so keys never need to be copied
 * into the database on production.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Settings {

	const OPTION = ONX_ASK_OPTION;

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/**
	 * Default configuration.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults() {
		$defaults = array(
			// General.
			'enabled'                 => true,
			'brand_name'              => 'Ask OncoNexus',
			'disclaimer'              => 'Educational content only — not medical advice. Always consult a qualified clinician.',
			'suggested_questions'     => "What are the latest advances in KRAS G12C inhibitors?\nWhich articles cover PD-L1 testing in NSCLC?\nSummarize recent conference highlights on CAR-T therapy.",
			'system_prompt'           => '',

			// Chat provider.
			'chat_provider'           => 'openai',
			'chat_model'              => 'gpt-4o-mini',
			'temperature'             => 0.2,
			'max_output_tokens'       => 900,

			// Embeddings.
			'embedding_provider'      => 'openai',
			'embedding_model'         => 'text-embedding-3-small',
			'embedding_dimensions'    => 0,

			// Credentials / endpoints.
			'openai_api_key'          => '',
			'anthropic_api_key'       => '',
			'gemini_api_key'          => '',
			'openai_base_url'         => 'https://api.openai.com/v1',
			'anthropic_base_url'      => 'https://api.anthropic.com/v1',
			'gemini_base_url'         => 'https://generativelanguage.googleapis.com/v1beta',

			// Indexed content.
			'post_types'              => array( 'post', 'page', 'doctor', 'podcast', 'video', 'conference-post', 'glossary_term' ),
			'exclude_ids'             => '',

			// Chunking.
			'chunk_size'              => 1800,
			'chunk_overlap'           => 0.15,
			'max_chunks_per_doc'      => 80,

			// Retrieval.
			'top_k'                   => 40,
			'context_chunks'          => 8,
			'max_per_doc'             => 2,
			'max_context_chars'       => 12000,
			'max_scan_chunks'         => 5000,
			'dense_weight'            => 0.65,
			'lexical_weight'          => 0.35,
			'recency_weight'          => 0.05,
			'mmr_lambda'              => 0.70,
			'enable_query_rewrite'    => true,

			// Answer cache.
			'enable_answer_cache'     => true,
			'cache_ttl'               => 3600,

			// Public access.
			'allowed_origins'         => '*',
			'rate_limit_requests'     => 20,
			'rate_limit_window'       => 600,
			'daily_request_limit'     => 1000,
			'max_question_chars'      => 500,

			// Privacy / lifecycle.
			'enable_logging'          => true,
			'delete_data'             => true,
		);

		/**
		 * Filter the default settings before they are merged with the stored option.
		 *
		 * @param array<string,mixed> $defaults Defaults.
		 */
		return apply_filters( 'onx_ask_default_settings', $defaults );
	}

	/**
	 * Full settings array (defaults + stored values).
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = array_merge( $this->defaults(), $stored );
		return self::$cache;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is missing.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	public function is_enabled() {
		return (bool) $this->get( 'enabled', true );
	}

	/**
	 * API key for a provider. Constants take precedence over the database.
	 *
	 * @param string $provider openai|anthropic|gemini.
	 * @return string
	 */
	public function api_key( $provider ) {
		$constant = 'ONX_ASK_' . strtoupper( $provider ) . '_API_KEY';
		if ( defined( $constant ) && constant( $constant ) ) {
			return trim( (string) constant( $constant ) );
		}

		return trim( (string) $this->get( $provider . '_api_key', '' ) );
	}

	public function has_key( $provider ) {
		return '' !== $this->api_key( $provider );
	}

	public function key_is_constant( $provider ) {
		return defined( 'ONX_ASK_' . strtoupper( $provider ) . '_API_KEY' );
	}

	/**
	 * Chat provider configuration.
	 *
	 * @return array{provider:string,model:string,temperature:float,max_tokens:int}
	 */
	public function chat_config() {
		$provider = $this->get( 'chat_provider', 'openai' );
		if ( ! in_array( $provider, array( 'openai', 'anthropic', 'gemini' ), true ) ) {
			$provider = 'openai';
		}

		return array(
			'provider'    => $provider,
			'model'       => (string) $this->get( 'chat_model', 'gpt-4o-mini' ),
			'temperature' => (float) $this->get( 'temperature', 0.2 ),
			'max_tokens'  => (int) $this->get( 'max_output_tokens', 900 ),
		);
	}

	/**
	 * Embedding provider configuration.
	 *
	 * @return array{provider:string,model:string,dimensions:int}
	 */
	public function embedding_config() {
		$provider = $this->get( 'embedding_provider', 'openai' );
		if ( ! in_array( $provider, array( 'openai', 'gemini', 'local' ), true ) ) {
			$provider = 'local';
		}

		return array(
			'provider'   => $provider,
			'model'      => (string) $this->get( 'embedding_model', 'text-embedding-3-small' ),
			'dimensions' => max( 0, (int) $this->get( 'embedding_dimensions', 0 ) ),
		);
	}

	/**
	 * Whether chat + embeddings are usable with the current credentials.
	 */
	public function is_configured() {
		$chat = $this->chat_config();
		if ( ! $this->has_key( $chat['provider'] ) ) {
			return false;
		}

		$embeddings = $this->embedding_config();
		if ( 'local' !== $embeddings['provider'] && ! $this->has_key( $embeddings['provider'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Selected post types, intersected with post types that actually exist.
	 *
	 * @return string[]
	 */
	public function post_types() {
		$selected = $this->get( 'post_types', array() );
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}
		$selected = array_map( 'sanitize_key', $selected );
		$available = array_keys( $this->available_post_types() );

		/**
		 * Filter the post types that are indexed for RAG.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_intersect( $selected, $available ) );
	}

	/**
	 * Public post types available to index, keyed by name.
	 *
	 * @return array<string,string> name => label
	 */
	public function available_post_types() {
		$objects = get_post_types( array( 'public' => true ), 'objects' );
		$skip    = array(
			'attachment',
			'wp_block',
			'wp_navigation',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_font_family',
			'wp_font_face',
			'headless_template',
			'headless_snippet',
			'elementor_library',
			'elementor_snippet',
			'e-landing-page',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'nav_menu_item',
		);

		$types = array();
		foreach ( $objects as $name => $object ) {
			if ( in_array( $name, $skip, true ) ) {
				continue;
			}
			if ( 0 === strpos( $name, 'wp_' ) || 0 === strpos( $name, 'e-' ) ) {
				continue;
			}
			$types[ $name ] = isset( $object->labels->singular_name ) && $object->labels->singular_name
				? $object->labels->singular_name
				: $name;
		}

		ksort( $types );

		/**
		 * Filter the post types offered in the admin and available for indexing.
		 *
		 * @param array<string,string> $types name => label.
		 */
		return apply_filters( 'onx_ask_indexable_post_types', $types );
	}

	/**
	 * @return string[]
	 */
	public function suggested_questions() {
		$raw = (string) $this->get( 'suggested_questions', '' );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_slice( $out, 0, 8 );
	}

	/**
	 * @return int[]
	 */
	public function excluded_ids() {
		$raw = (string) $this->get( 'exclude_ids', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$ids = preg_split( '/[^0-9]+/', $raw );
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Allowed browser origins. `*` disables the origin check.
	 *
	 * @return string[]
	 */
	public function allowed_origins() {
		$raw = (string) $this->get( 'allowed_origins', '*' );
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$out = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( '*' === $line ) {
				return array( '*' );
			}
			$out[] = untrailingslashit( $line );
		}
		return $out;
	}

	/**
	 * Persist settings, bypassing the sanitiser (already-sanitised values).
	 *
	 * @param array<string,mixed> $values Values.
	 */
	public function update( array $values ) {
		self::$cache = null;
		update_option( self::OPTION, $values, false );
	}

	public function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Settings API sanitiser.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$current = $this->all();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$out = $current;

		$booleans = array( 'enabled', 'enable_query_rewrite', 'enable_answer_cache', 'enable_logging', 'delete_data' );
		foreach ( $booleans as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] );
		}

		$text_fields = array(
			'brand_name',
			'chat_provider',
			'chat_model',
			'embedding_provider',
			'embedding_model',
			'openai_base_url',
			'anthropic_base_url',
			'gemini_base_url',
		);
		foreach ( $text_fields as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		$out['chat_provider']      = in_array( $out['chat_provider'], array( 'openai', 'anthropic', 'gemini' ), true ) ? $out['chat_provider'] : 'openai';
		$out['embedding_provider'] = in_array( $out['embedding_provider'], array( 'openai', 'gemini', 'local' ), true ) ? $out['embedding_provider'] : 'local';

		$out['openai_base_url']    = esc_url_raw( $out['openai_base_url'] );
		$out['anthropic_base_url'] = esc_url_raw( $out['anthropic_base_url'] );
		$out['gemini_base_url']    = esc_url_raw( $out['gemini_base_url'] );

		// Secrets: blank means "keep the existing key".
		foreach ( array( 'openai_api_key', 'anthropic_api_key', 'gemini_api_key' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) ) {
				$out[ $key ] = trim( sanitize_text_field( (string) $input[ $key ] ) );
			}
		}

		$out['disclaimer']      = isset( $input['disclaimer'] ) ? wp_kses_post( (string) $input['disclaimer'] ) : $current['disclaimer'];
		$out['system_prompt']   = isset( $input['system_prompt'] ) ? sanitize_textarea_field( (string) $input['system_prompt'] ) : $current['system_prompt'];
		$out['suggested_questions'] = isset( $input['suggested_questions'] ) ? sanitize_textarea_field( (string) $input['suggested_questions'] ) : $current['suggested_questions'];

		$numbers = array(
			'temperature'          => array( 0, 2, 'float' ),
			'max_output_tokens'    => array( 64, 8192, 'int' ),
			'embedding_dimensions' => array( 0, 4096, 'int' ),
			'chunk_size'           => array( 400, 8000, 'int' ),
			'chunk_overlap'        => array( 0, 0.5, 'float' ),
			'max_chunks_per_doc'   => array( 5, 400, 'int' ),
			'top_k'                => array( 5, 200, 'int' ),
			'context_chunks'       => array( 1, 20, 'int' ),
			'max_per_doc'          => array( 1, 10, 'int' ),
			'max_context_chars'    => array( 1000, 60000, 'int' ),
			'max_scan_chunks'      => array( 200, 50000, 'int' ),
			'dense_weight'         => array( 0, 1, 'float' ),
			'lexical_weight'       => array( 0, 1, 'float' ),
			'recency_weight'       => array( 0, 1, 'float' ),
			'mmr_lambda'           => array( 0, 1, 'float' ),
			'cache_ttl'            => array( 0, 86400, 'int' ),
			'rate_limit_requests'  => array( 0, 1000, 'int' ),
			'rate_limit_window'    => array( 60, 86400, 'int' ),
			'daily_request_limit'  => array( 0, 100000, 'int' ),
			'max_question_chars'   => array( 50, 2000, 'int' ),
		);
		foreach ( $numbers as $key => $rule ) {
			if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
				continue;
			}
			$value = 'float' === $rule[2] ? (float) $input[ $key ] : (int) $input[ $key ];
			$value = max( $rule[0], min( $rule[1], $value ) );
			$out[ $key ] = $value;
		}

		$available = array_keys( $this->available_post_types() );
		$types     = isset( $input['post_types'] ) && is_array( $input['post_types'] ) ? $input['post_types'] : array();
		$types     = array_map( 'sanitize_key', $types );
		$out['post_types'] = array_values( array_intersect( $types, $available ) );

		$out['exclude_ids'] = $this->sanitize_id_list( isset( $input['exclude_ids'] ) ? (string) $input['exclude_ids'] : '' );

		$origins = isset( $input['allowed_origins'] ) ? (string) $input['allowed_origins'] : '*';
		$out['allowed_origins'] = $this->sanitize_origins( $origins );

		// Avoid an all-zero retrieval score.
		if ( 0.0 === (float) $out['dense_weight'] && 0.0 === (float) $out['lexical_weight'] ) {
			$out['dense_weight']   = 0.65;
			$out['lexical_weight'] = 0.35;
		}

		return $out;
	}

	/**
	 * @param string $value Raw comma-separated list.
	 * @return string
	 */
	private function sanitize_id_list( $value ) {
		$ids = preg_split( '/[^0-9]+/', $value );
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		return implode( ',', array_values( array_unique( $ids ) ) );
	}

	/**
	 * @param string $value Newline separated origins.
	 * @return string
	 */
	private function sanitize_origins( $value ) {
		$lines = preg_split( '/\r\n|\r|\n/', $value );
		$out = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( '*' === $line ) {
				return '*';
			}
			$url = esc_url_raw( $line, array( 'http', 'https' ) );
			if ( $url ) {
				$out[] = untrailingslashit( $url );
			}
		}
		return implode( "\n", array_values( array_unique( $out ) ) );
	}
}
