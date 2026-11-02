<?php
/**
 * Embedding providers.
 *
 * Supported:
 *  - OpenAI  text-embedding-3-small / text-embedding-3-large (optional dimensions)
 *  - Gemini  gemini-embedding-001 / text-embedding-004 (optional dimensions)
 *  - Local   deterministic feature-hashing vectors (no API key, lexical-dense)
 *
 * Anthropic does not expose an embeddings API; Claude is used for generation
 * only. Pair it with OpenAI, Gemini or `local` embeddings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Embeddings {

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	public function __construct( OncologyNexus_Ask_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Embed a batch of texts.
	 *
	 * @param string[] $texts Texts.
	 * @return array<int,float[]>|WP_Error Vectors indexed like the input.
	 */
	public function embed_texts( array $texts ) {
		$texts = array_values( array_filter( array_map( 'strval', $texts ), 'strlen' ) );
		if ( empty( $texts ) ) {
			return array();
		}

		$config = $this->settings->embedding_config();

		if ( 'local' === $config['provider'] ) {
			$vectors = array();
			foreach ( $texts as $text ) {
				$vectors[] = $this->local_embed( $text, $config['dimensions'] );
			}
			return $vectors;
		}

		$key = $this->settings->api_key( $config['provider'] );
		if ( '' === $key ) {
			return new WP_Error(
				'onx_ask_missing_key',
				sprintf(
					/* translators: %s: provider name. */
					__( 'No API key is configured for the %s embedding provider.', 'onx-ask' ),
					$config['provider']
				)
			);
		}

		$batch_size = 'gemini' === $config['provider'] ? 16 : 32;
		/**
		 * Filter the embedding batch size.
		 *
		 * @param int    $batch_size Batch size.
		 * @param string $provider   Provider.
		 */
		$batch_size = max( 1, (int) apply_filters( 'onx_ask_embedding_batch_size', $batch_size, $config['provider'] ) );

		$vectors = array();
		foreach ( array_chunk( $texts, $batch_size ) as $batch ) {
			$result = 'gemini' === $config['provider']
				? $this->gemini_embed( $batch, $key, $config )
				: $this->openai_embed( $batch, $key, $config );

			if ( is_wp_error( $result ) ) {
				// One bad item should not poison the whole document. Retry the
				// batch one text at a time and surface the first real failure.
				if ( count( $batch ) > 1 ) {
					foreach ( $batch as $single ) {
						$one = 'gemini' === $config['provider']
							? $this->gemini_embed( array( $single ), $key, $config )
							: $this->openai_embed( array( $single ), $key, $config );
						if ( is_wp_error( $one ) ) {
							return $one;
						}
						$vectors[] = $one[0];
					}
					continue;
				}
				return $result;
			}

			foreach ( $result as $vector ) {
				$vectors[] = $vector;
			}
		}

		return $vectors;
	}

	/**
	 * Embed one text.
	 *
	 * @param string $text Text.
	 * @return float[]|WP_Error
	 */
	public function embed_text( $text ) {
		$result = $this->embed_texts( array( (string) $text ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result[0] ) ? $result[0] : new WP_Error( 'onx_ask_empty_embedding', __( 'The provider returned no embedding.', 'onx-ask' ) );
	}

	/**
	 * Provider / model / dimensions signature used in document hashes.
	 *
	 * @return string
	 */
	public function signature() {
		$config = $this->settings->embedding_config();
		$dims   = $config['dimensions'];
		if ( 'local' === $config['provider'] && $dims <= 0 ) {
			$dims = 512;
		}
		return $config['provider'] . ':' . $config['model'] . ':' . $dims;
	}

	/**
	 * @param string[]             $texts  Texts.
	 * @param string               $key    API key.
	 * @param array<string,mixed>  $config Config.
	 * @return array<int,float[]>|WP_Error
	 */
	private function openai_embed( array $texts, $key, array $config ) {
		$base = $this->normalize_base( $config['provider'], $this->settings->get( 'openai_base_url', 'https://api.openai.com/v1' ) );
		$url  = $base . '/embeddings';

		$body = array(
			'model' => $config['model'],
			'input' => array_values( $texts ),
		);

		$use_dimensions = $config['dimensions'] > 0 && false !== strpos( $config['model'], 'text-embedding-3' );
		if ( $use_dimensions ) {
			$body['dimensions'] = (int) $config['dimensions'];
		}

		$data = OncologyNexus_Ask_HTTP::post_json(
			$url,
			$body,
			array( 'Authorization' => 'Bearer ' . $key ),
			60
		);

		// OpenAI-compatible gateways occasionally reject `dimensions`.
		if ( is_wp_error( $data ) && isset( $body['dimensions'] ) ) {
			$message = $data->get_error_message();
			if ( false !== stripos( $message, 'dimension' ) || false !== stripos( $message, 'unsupported' ) ) {
				unset( $body['dimensions'] );
				$data = OncologyNexus_Ask_HTTP::post_json( $url, $body, array( 'Authorization' => 'Bearer ' . $key ), 60 );
			}
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error( 'onx_ask_embeddings_error', __( 'OpenAI returned no embeddings.', 'onx-ask' ) );
		}

		// The API guarantees order, but sort by index defensively.
		usort(
			$data['data'],
			static function ( $a, $b ) {
				return (int) ( isset( $a['index'] ) ? $a['index'] : 0 ) <=> (int) ( isset( $b['index'] ) ? $b['index'] : 0 );
			}
		);

		$vectors = array();
		foreach ( $data['data'] as $row ) {
			if ( empty( $row['embedding'] ) || ! is_array( $row['embedding'] ) ) {
				return new WP_Error( 'onx_ask_embeddings_error', __( 'OpenAI returned an empty embedding.', 'onx-ask' ) );
			}
			$vectors[] = $this->normalize( array_map( 'floatval', $row['embedding'] ) );
		}

		if ( count( $vectors ) !== count( $texts ) ) {
			return new WP_Error( 'onx_ask_embeddings_mismatch', __( 'The embedding provider returned a different number of vectors than requested.', 'onx-ask' ) );
		}

		return $vectors;
	}

	/**
	 * @param string[]            $texts  Texts.
	 * @param string              $key    API key.
	 * @param array<string,mixed> $config Config.
	 * @return array<int,float[]>|WP_Error
	 */
	private function gemini_embed( array $texts, $key, array $config ) {
		$base  = $this->normalize_base( 'gemini', $this->settings->get( 'gemini_base_url', 'https://generativelanguage.googleapis.com/v1beta' ) );
		$model = $this->normalize_gemini_model( $config['model'] );
		$url   = $base . '/models/' . rawurlencode( $model ) . ':batchEmbedContents';
		$auth  = array( 'x-goog-api-key' => $key );

		$requests = array();
		foreach ( $texts as $text ) {
			$request = array(
				'model'   => 'models/' . $model,
				'content' => array(
					'parts' => array( array( 'text' => (string) $text ) ),
				),
			);
			if ( $config['dimensions'] > 0 ) {
				$request['outputDimensionality'] = (int) $config['dimensions'];
			}
			$requests[] = $request;
		}

		$data = OncologyNexus_Ask_HTTP::post_json( $url, array( 'requests' => $requests ), $auth, 60 );

		if ( is_wp_error( $data ) && $config['dimensions'] > 0 ) {
			$message = $data->get_error_message();
			if ( false !== stripos( $message, 'dimensionality' ) || false !== stripos( $message, 'unknown' ) ) {
				foreach ( $requests as &$request ) {
					unset( $request['outputDimensionality'] );
				}
				unset( $request );
				$data = OncologyNexus_Ask_HTTP::post_json( $url, array( 'requests' => $requests ), $auth, 60 );
			}
		}

		if ( is_wp_error( $data ) ) {
			// Some Gemini-compatible proxies only implement the single endpoint.
			$single = array();
			foreach ( $texts as $text ) {
				$one = $this->gemini_embed_one( $text, $key, $base, $model, $config );
				if ( is_wp_error( $one ) ) {
					return $one;
				}
				$single[] = $one;
			}
			return $single;
		}

		$rows = isset( $data['embeddings'] ) && is_array( $data['embeddings'] ) ? $data['embeddings'] : array();
		if ( empty( $rows ) && isset( $data['embedding']['values'] ) ) {
			$rows = array( $data['embedding'] );
		}

		$vectors = array();
		foreach ( $rows as $row ) {
			$values = isset( $row['values'] ) && is_array( $row['values'] ) ? $row['values'] : array();
			if ( empty( $values ) ) {
				return new WP_Error( 'onx_ask_embeddings_error', __( 'Gemini returned an empty embedding.', 'onx-ask' ) );
			}
			$vectors[] = $this->normalize( array_map( 'floatval', $values ) );
		}

		if ( count( $vectors ) !== count( $texts ) ) {
			return new WP_Error( 'onx_ask_embeddings_mismatch', __( 'The embedding provider returned a different number of vectors than requested.', 'onx-ask' ) );
		}

		return $vectors;
	}

	/**
	 * @param string              $text   Text.
	 * @param string              $key    Key.
	 * @param string              $base   Base URL.
	 * @param string              $model  Model.
	 * @param array<string,mixed> $config Config.
	 * @return float[]|WP_Error
	 */
	private function gemini_embed_one( $text, $key, $base, $model, array $config ) {
		$url  = $base . '/models/' . rawurlencode( $model ) . ':embedContent';
		$body = array(
			'content' => array(
				'parts' => array( array( 'text' => (string) $text ) ),
			),
		);
		if ( $config['dimensions'] > 0 ) {
			$body['outputDimensionality'] = (int) $config['dimensions'];
		}

		$data = OncologyNexus_Ask_HTTP::post_json( $url, $body, array( 'x-goog-api-key' => $key ), 60 );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$values = isset( $data['embedding']['values'] ) && is_array( $data['embedding']['values'] ) ? $data['embedding']['values'] : array();
		if ( empty( $values ) ) {
			return new WP_Error( 'onx_ask_embeddings_error', __( 'Gemini returned an empty embedding.', 'onx-ask' ) );
		}

		return $this->normalize( array_map( 'floatval', $values ) );
	}

	/**
	 * Deterministic local embedding (feature hashing over words + bigrams).
	 *
	 * This is a genuinely useful fallback: it requires no key, keeps the dense
	 * half of hybrid retrieval working, and is combined with BM25 for exact
	 * terminology. It is not a substitute for a real embedding model on a large
	 * corpus — the admin can switch providers and reindex at any time.
	 *
	 * @param string $text Text.
	 * @param int    $dims Dimensions (0 = 512).
	 * @return float[]
	 */
	private function local_embed( $text, $dims = 0 ) {
		$dims = $dims > 0 ? $dims : 512;
		$dims = max( 64, min( 4096, $dims ) );

		$tokens = $this->tokenize( (string) $text );
		$counts = array_count_values( $tokens );
		$vector = array_fill( 0, $dims, 0.0 );

		foreach ( $counts as $token => $tf ) {
			$hash = (int) hexdec( substr( md5( (string) $token ), 0, 7 ) );
			$idx  = $hash % $dims;
			$sign = ( ( $hash >> 8 ) & 1 ) ? -1.0 : 1.0;
			$weight = 1.0 + log( (float) $tf );
			$vector[ $idx ] += $sign * $weight;
		}

		return $this->normalize( $vector );
	}

	/**
	 * @param string $text Text.
	 * @return string[]
	 */
	private function tokenize( $text ) {
		$text  = mb_strtolower( (string) $text, 'UTF-8' );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text );
		$words = array_values( array_filter( (array) $words, static function ( $word ) {
			return is_string( $word ) && mb_strlen( $word, 'UTF-8' ) >= 2;
		} ) );

		// Cap pathological documents so indexing stays predictable.
		$words = array_slice( $words, 0, 1500 );

		$tokens = $words;
		$count  = count( $words );
		for ( $i = 1; $i < $count; $i++ ) {
			$tokens[] = $words[ $i - 1 ] . '_' . $words[ $i ];
		}

		return $tokens;
	}

	/**
	 * L2-normalise a vector.
	 *
	 * @param float[] $vector Vector.
	 * @return float[]
	 */
	public function normalize( array $vector ) {
		$norm = 0.0;
		foreach ( $vector as $value ) {
			$norm += $value * $value;
		}
		$norm = sqrt( $norm );

		if ( $norm <= 0.0 ) {
			return array_map( static function () {
				return 0.0;
			}, $vector );
		}

		foreach ( $vector as $i => $value ) {
			$vector[ $i ] = $value / $norm;
		}

		return array_values( $vector );
	}

	/**
	 * @param string $provider Provider.
	 * @param string $base     Configured base.
	 * @return string
	 */
	private function normalize_base( $provider, $base ) {
		$base = untrailingslashit( trim( (string) $base ) );

		if ( '' === $base ) {
			$defaults = array(
				'openai'    => 'https://api.openai.com/v1',
				'anthropic' => 'https://api.anthropic.com/v1',
				'gemini'    => 'https://generativelanguage.googleapis.com/v1beta',
			);
			$base = isset( $defaults[ $provider ] ) ? $defaults[ $provider ] : '';
		}

		if ( 'openai' === $provider && ! preg_match( '#/v\d+(beta)?$#', $base ) ) {
			$base .= '/v1';
		}
		if ( 'anthropic' === $provider && ! preg_match( '#/v\d+$#', $base ) ) {
			$base .= '/v1';
		}
		if ( 'gemini' === $provider && ! preg_match( '#/v\d+(beta)?$#', $base ) ) {
			$base .= '/v1beta';
		}

		return $base;
	}

	/**
	 * @param string $model Model name.
	 * @return string
	 */
	private function normalize_gemini_model( $model ) {
		$model = trim( (string) $model );
		$model = preg_replace( '#^models/#', '', $model );
		return $model ? $model : 'gemini-embedding-001';
	}

	/**
	 * Test a provider connection without spending meaningful tokens.
	 *
	 * @param string $provider openai|anthropic|gemini|local.
	 * @param string $target   chat|embedding.
	 * @return array{ok:bool,message:string,dimensions?:int}
	 */
	public function test_connection( $provider = '', $target = 'embedding' ) {
		$config   = $this->settings->embedding_config();
		$provider = $provider ? $provider : $config['provider'];

		if ( 'local' === $provider ) {
			$vector = $this->local_embed( 'OncologyNexus embedding test', 256 );
			return array(
				'ok'         => true,
				'message'    => __( 'Local embeddings are ready.', 'onx-ask' ),
				'dimensions' => count( $vector ),
			);
		}

		$key = $this->settings->api_key( $provider );
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: provider. */
					__( 'No API key configured for %s.', 'onx-ask' ),
					$provider
				),
			);
		}

		if ( 'embedding' === $target ) {
			$result = $this->embed_text( 'OncologyNexus embedding test' );
			if ( is_wp_error( $result ) ) {
				return array( 'ok' => false, 'message' => $result->get_error_message() );
			}
			return array(
				'ok'         => true,
				'message'    => __( 'Embedding request succeeded.', 'onx-ask' ),
				'dimensions' => count( $result ),
			);
		}

		// Chat key probe: a models listing is free and fast.
		if ( 'openai' === $provider ) {
			$base = $this->normalize_base( 'openai', $this->settings->get( 'openai_base_url', 'https://api.openai.com/v1' ) );
			$data = OncologyNexus_Ask_HTTP::get_json( $base . '/models', array( 'Authorization' => 'Bearer ' . $key ), 20 );
		} elseif ( 'anthropic' === $provider ) {
			$base = $this->normalize_base( 'anthropic', $this->settings->get( 'anthropic_base_url', 'https://api.anthropic.com/v1' ) );
			$data = OncologyNexus_Ask_HTTP::get_json(
				$base . '/models',
				array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
				),
				20
			);
		} else {
			$base = $this->normalize_base( 'gemini', $this->settings->get( 'gemini_base_url', 'https://generativelanguage.googleapis.com/v1beta' ) );
			$data = OncologyNexus_Ask_HTTP::get_json( $base . '/models', array( 'x-goog-api-key' => $key ), 20 );
		}

		if ( is_wp_error( $data ) ) {
			return array( 'ok' => false, 'message' => $data->get_error_message() );
		}

		return array( 'ok' => true, 'message' => __( 'Provider key is valid.', 'onx-ask' ) );
	}
}
