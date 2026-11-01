<?php
/**
 * Hybrid retriever.
 *
 * Retrieval pipeline:
 *   1. Optional query rewrite (conversation-aware, standalone search query).
 *   2. Dense cosine similarity over int8 embeddings.
 *   3. BM25 lexical scoring over the same candidate chunks.
 *   4. Min-max normalisation + weighted fusion + recency / title boosts.
 *   5. MMR diversification with a per-document cap.
 *   6. Context assembly with numbered citations.
 *
 * This is deliberately a hybrid: embeddings handle paraphrase and synonymy
 * ("lung cancer" ↔ "NSCLC"), BM25 handles exact medical terminology, acronyms
 * and drug names that embedding models sometimes blur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Retriever {

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	/** @var OncologyNexus_Ask_Embeddings */
	private $embeddings;

	/** @var OncologyNexus_Ask_Vector_Store */
	private $store;

	/** @var OncologyNexus_Ask_LLM */
	private $llm;

	/** @var string[] */
	private static $stopwords = array(
		'a', 'about', 'above', 'after', 'again', 'against', 'all', 'am', 'an', 'and', 'any', 'are', 'as', 'at',
		'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but', 'by', 'can', 'could',
		'did', 'do', 'does', 'doing', 'down', 'during', 'each', 'few', 'for', 'from', 'further', 'had', 'has',
		'have', 'having', 'he', 'her', 'here', 'hers', 'herself', 'him', 'himself', 'his', 'how', 'i', 'if',
		'in', 'into', 'is', 'it', 'its', 'itself', 'just', 'me', 'more', 'most', 'my', 'myself', 'no', 'nor',
		'not', 'now', 'of', 'off', 'on', 'once', 'only', 'or', 'other', 'our', 'ours', 'ourselves', 'out',
		'over', 'own', 'same', 'she', 'should', 'so', 'some', 'such', 'than', 'that', 'the', 'their', 'theirs',
		'them', 'themselves', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'to', 'too',
		'under', 'until', 'up', 'very', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who',
		'whom', 'why', 'will', 'with', 'would', 'you', 'your', 'yours', 'yourself', 'yourselves', 'tell',
		'please', 'give', 'find', 'show', 'explain', 'summarize', 'summarise', 'list', 'latest', 'recent',
	);

	public function __construct(
		OncologyNexus_Ask_Settings $settings,
		OncologyNexus_Ask_Embeddings $embeddings,
		OncologyNexus_Ask_Vector_Store $store,
		OncologyNexus_Ask_LLM $llm
	) {
		$this->settings   = $settings;
		$this->embeddings = $embeddings;
		$this->store      = $store;
		$this->llm        = $llm;
	}

	/**
	 * Rewrite a follow-up question into a standalone search query.
	 *
	 * @param string                                   $question Latest question.
	 * @param array<int,array{role:string,content:string}> $history  Prior turns.
	 * @return string
	 */
	public function rewrite_query( $question, array $history = array() ) {
		$question = trim( (string) $question );
		if ( ! $this->settings->get( 'enable_query_rewrite', true ) || '' === $question ) {
			return $question;
		}

		$history = $this->llm->sanitize_messages( $history );
		if ( empty( $history ) ) {
			return $question;
		}

		$cache_key = 'onx_ask_qr_' . md5( $question . '|' . wp_json_encode( $history ) );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$conversation = array();
		foreach ( array_slice( $history, -4 ) as $message ) {
			$conversation[] = ( 'assistant' === $message['role'] ? 'Assistant: ' : 'User: ' ) . $message['content'];
		}

		$system = 'You rewrite questions into standalone search queries for an oncology content library. '
			. 'Include the cancer type, biomarker, drug, trial, conference or content type implied by the conversation. '
			. 'Do not answer the question. Return only the rewritten search query, at most 30 words, with no quotes or labels.';

		$user = "Conversation:\n" . implode( "\n", $conversation ) . "\n\nLatest question: " . $question;

		$result = $this->llm->complete_simple(
			$system,
			$user,
			array(
				'max_tokens'  => 90,
				'temperature' => 0.0,
				'timeout'     => 20,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $question;
		}

		$result = trim( preg_replace( '/^["\'\s]+|["\'\s]+$/u', '', (string) $result ) );
		$result = preg_replace( '/\s+/', ' ', $result );
		$result = mb_substr( $result, 0, 300 );

		if ( '' === $result ) {
			return $question;
		}

		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Retrieve and diversify chunks.
	 *
	 * @param string              $query Search query.
	 * @param array<string,mixed> $args  post_types, top_k, context_chunks, max_per_doc, max_scan.
	 * @return array<int,array<string,mixed>>
	 */
	public function retrieve( $query, array $args = array() ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'post_types'     => array(),
				'top_k'          => (int) $this->settings->get( 'top_k', 40 ),
				'context_chunks' => (int) $this->settings->get( 'context_chunks', 8 ),
				'max_per_doc'    => (int) $this->settings->get( 'max_per_doc', 2 ),
				'max_scan'       => (int) $this->settings->get( 'max_scan_chunks', 5000 ),
			)
		);

		$max_scan = max( 200, (int) $args['max_scan'] );
		/**
		 * Filter how many chunks are scanned per query. Raise it for larger
		 * corpora; consider an external vector store beyond ~50k chunks.
		 *
		 * @param int    $max_scan Max chunks.
		 * @param string $query    Query.
		 */
		$max_scan = (int) apply_filters( 'onx_ask_max_scan_chunks', $max_scan, $query );

		$chunks = $this->store->get_chunks(
			array(
				'post_types' => (array) $args['post_types'],
				'limit'      => $max_scan,
			)
		);

		/**
		 * Filter the candidate set before scoring.
		 *
		 * @param array<int,array<string,mixed>> $chunks Candidate chunks.
		 * @param string                         $query  Query.
		 */
		$chunks = apply_filters( 'onx_ask_candidate_chunks', $chunks, $query );

		if ( empty( $chunks ) ) {
			return array();
		}

		$query_vector = $this->embeddings->embed_text( $query );
		if ( is_wp_error( $query_vector ) ) {
			$query_vector = array();
		}

		$query_tokens = array_values( array_unique( $this->tokenize( $query ) ) );
		if ( empty( $query_tokens ) ) {
			$query_tokens = array_values( array_unique( $this->tokenize( $query, false ) ) );
		}

		$query_token_set = array_fill_keys( $query_tokens, true );
		$stats           = array();
		$df              = array();
		$total_length    = 0;
		$count           = count( $chunks );

		// First pass: token statistics for BM25. Only counts for query terms are
		// retained, which keeps memory flat even for thousands of chunks.
		foreach ( $chunks as $index => $chunk ) {
			$haystack = ( isset( $chunk['title'] ) ? $chunk['title'] : '' ) . ' '
				. ( isset( $chunk['heading'] ) ? $chunk['heading'] : '' ) . ' '
				. ( isset( $chunk['content'] ) ? $chunk['content'] : '' );

			$tokens = $this->tokenize( $haystack, false );
			$length = count( $tokens );
			$tf     = array();

			foreach ( $tokens as $token ) {
				if ( isset( $query_token_set[ $token ] ) ) {
					$tf[ $token ] = isset( $tf[ $token ] ) ? $tf[ $token ] + 1 : 1;
				}
			}
			foreach ( array_keys( $tf ) as $token ) {
				$df[ $token ] = isset( $df[ $token ] ) ? $df[ $token ] + 1 : 1;
			}

			$stats[ $index ] = array(
				'tf'     => $tf,
				'length' => $length,
			);
			$total_length += $length;
		}

		$avg_length = $count > 0 ? max( 1.0, $total_length / $count ) : 1.0;
		$k1         = 1.2;
		$b          = 0.75;
		$now        = time();
		$query_norm = $this->normalize_text( $query );

		$scored = array();
		foreach ( $chunks as $index => $chunk ) {
			$dense = 0.0;
			if ( ! empty( $query_vector ) && ! empty( $chunk['embedding'] ) ) {
				$dense = $this->store->cosine( $chunk['embedding'], (float) $chunk['embedding_scale'], $query_vector );
			}

			$lexical = 0.0;
			$tf      = $stats[ $index ]['tf'];
			$length  = max( 1, (int) $stats[ $index ]['length'] );
			foreach ( $query_tokens as $token ) {
				if ( empty( $tf[ $token ] ) ) {
					continue;
				}
				$term_df = isset( $df[ $token ] ) ? (int) $df[ $token ] : 0;
				if ( $term_df <= 0 ) {
					continue;
				}
				$idf      = log( 1 + ( ( $count - $term_df + 0.5 ) / ( $term_df + 0.5 ) ) );
				$denom    = $tf[ $token ] + $k1 * ( 1 - $b + $b * ( $length / $avg_length ) );
				$lexical += $idf * ( ( $tf[ $token ] * ( $k1 + 1 ) ) / max( 0.0001, $denom ) );
			}

			$recency = 0.0;
			if ( ! empty( $chunk['modified_gmt'] ) ) {
				$timestamp = strtotime( $chunk['modified_gmt'] . ' UTC' );
				if ( $timestamp ) {
					$days    = max( 0, ( $now - $timestamp ) / DAY_IN_SECONDS );
					$recency = exp( -$days / 365.0 );
				}
			}

			$title_tokens = array_fill_keys( $this->tokenize( isset( $chunk['title'] ) ? $chunk['title'] : '' ), true );
			$title_hits   = 0;
			foreach ( $query_tokens as $token ) {
				if ( isset( $title_tokens[ $token ] ) ) {
					$title_hits++;
				}
			}
			$title_boost = ! empty( $query_tokens ) ? ( $title_hits / count( $query_tokens ) ) : 0.0;

			$phrase_boost = 0.0;
			if ( mb_strlen( $query_norm ) >= 8 ) {
				$haystack = $this->normalize_text(
					( isset( $chunk['title'] ) ? $chunk['title'] : '' ) . ' ' .
					( isset( $chunk['heading'] ) ? $chunk['heading'] : '' ) . ' ' .
					( isset( $chunk['content'] ) ? $chunk['content'] : '' )
				);
				if ( false !== mb_strpos( $haystack, $query_norm ) ) {
					$phrase_boost = 1.0;
				}
			}

			$scored[] = array(
				'chunk'        => $chunk,
				'dense'        => $dense,
				'lexical'      => $lexical,
				'recency'      => $recency,
				'title_boost'  => $title_boost,
				'phrase_boost' => $phrase_boost,
			);
		}

		$dense_scores   = array_column( $scored, 'dense' );
		$lexical_scores = array_column( $scored, 'lexical' );
		$dense_range    = $this->range( $dense_scores );
		$lexical_range  = $this->range( $lexical_scores );

		$dense_weight   = (float) $this->settings->get( 'dense_weight', 0.65 );
		$lexical_weight = (float) $this->settings->get( 'lexical_weight', 0.35 );
		$recency_weight = (float) $this->settings->get( 'recency_weight', 0.05 );
		$total_weight   = max( 0.0001, $dense_weight + $lexical_weight );

		foreach ( $scored as $index => $row ) {
			$dense_norm   = $this->min_max( (float) $row['dense'], $dense_range );
			$lexical_norm = $this->min_max( (float) $row['lexical'], $lexical_range );

			$scored[ $index ]['score'] = (
				( $dense_weight * $dense_norm ) +
				( $lexical_weight * $lexical_norm )
			) / $total_weight
				+ ( $recency_weight * (float) $row['recency'] )
				+ ( 0.06 * (float) $row['title_boost'] )
				+ ( 0.05 * (float) $row['phrase_boost'] );
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$top_k = max( 1, (int) $args['top_k'] );
		$scored = array_slice( $scored, 0, $top_k );

		$selected = $this->mmr(
			$scored,
			max( 1, (int) $args['context_chunks'] ),
			max( 1, (int) $args['max_per_doc'] ),
			(float) $this->settings->get( 'mmr_lambda', 0.7 )
		);

		$results = array();
		foreach ( $selected as $row ) {
			$chunk   = $row['chunk'];
			$results[] = array(
				'chunk_id'     => (int) $chunk['id'],
				'post_id'      => (int) $chunk['post_id'],
				'title'        => (string) $chunk['title'],
				'url'          => (string) $chunk['url'],
				'path'         => (string) $chunk['path'],
				'post_type'    => (string) $chunk['post_type'],
				'modified_gmt' => (string) $chunk['modified_gmt'],
				'heading'      => (string) $chunk['heading'],
				'content'      => (string) $chunk['content'],
				'score'        => (float) $row['score'],
				'dense'        => (float) $row['dense'],
				'lexical'      => (float) $row['lexical'],
				'embedding'    => (string) $chunk['embedding'],
				'embedding_scale' => (float) $chunk['embedding_scale'],
			);
		}

		/**
		 * Filter the final retrieved chunks.
		 *
		 * @param array<int,array<string,mixed>> $results Results.
		 * @param string                         $query   Query.
		 */
		return apply_filters( 'onx_ask_retrieved_chunks', $results, $query );
	}

	/**
	 * Maximal Marginal Relevance diversification.
	 *
	 * @param array<int,array<string,mixed>> $scored       Scored rows.
	 * @param int                            $limit        Chunks to keep.
	 * @param int                            $max_per_doc  Max chunks per post.
	 * @param float                          $lambda       Relevance vs. diversity.
	 * @return array<int,array<string,mixed>>
	 */
	private function mmr( array $scored, $limit, $max_per_doc, $lambda ) {
		if ( count( $scored ) <= $limit ) {
			return $scored;
		}

		$lambda   = max( 0.0, min( 1.0, (float) $lambda ) );
		$scores   = array_column( $scored, 'score' );
		$range    = $this->range( $scores );
		$selected = array();
		$vectors  = array();
		$per_doc  = array();

		// Decode vectors for the candidate pool once.
		foreach ( $scored as $index => $row ) {
			$vectors[ $index ] = ! empty( $row['chunk']['embedding'] )
				? $this->store->decode_vector( $row['chunk']['embedding'], (float) $row['chunk']['embedding_scale'] )
				: array();
		}

		$pick = function ( $ignore_doc_cap ) use ( &$selected, &$per_doc, &$vectors, $scored, $scores, $range, $lambda, $limit, $max_per_doc ) {
			while ( count( $selected ) < $limit ) {
				$best_index = null;
				$best_value = -INF;

				foreach ( $scored as $index => $row ) {
					if ( isset( $selected[ $index ] ) ) {
						continue;
					}

					$post_id = (int) $row['chunk']['post_id'];
					if ( ! $ignore_doc_cap && isset( $per_doc[ $post_id ] ) && $per_doc[ $post_id ] >= $max_per_doc ) {
						continue;
					}

					$relevance = $this->min_max( (float) $row['score'], $range );
					$similarity = 0.0;

					if ( ! empty( $vectors[ $index ] ) ) {
						foreach ( array_keys( $selected ) as $chosen_index ) {
							if ( empty( $vectors[ $chosen_index ] ) ) {
								continue;
							}
							$similarity = max( $similarity, $this->cosine_vectors( $vectors[ $index ], $vectors[ $chosen_index ] ) );
						}
					}

					$value = ( $lambda * $relevance ) - ( ( 1 - $lambda ) * $similarity );
					if ( $value > $best_value ) {
						$best_value = $value;
						$best_index = $index;
					}
				}

				if ( null === $best_index ) {
					return;
				}

				$selected[ $best_index ] = true;
				$post_id                 = (int) $scored[ $best_index ]['chunk']['post_id'];
				$per_doc[ $post_id ]     = isset( $per_doc[ $post_id ] ) ? $per_doc[ $post_id ] + 1 : 1;
			}
		};

		$pick( false );

		// If the per-document cap was too strict, fill the remaining slots.
		if ( count( $selected ) < $limit ) {
			$pick( true );
		}

		$out = array();
		foreach ( array_keys( $selected ) as $index ) {
			$out[] = $scored[ $index ];
		}

		// Preserve score order rather than selection order.
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Assemble a prompt context and a citation list from retrieved chunks.
	 *
	 * @param array<int,array<string,mixed>> $chunks    Retrieved chunks.
	 * @param int                            $max_chars Character budget.
	 * @return array{context:string,sources:array<int,array<string,mixed>>}
	 */
	public function build_context( array $chunks, $max_chars = 12000 ) {
		$max_chars = max( 1000, (int) $max_chars );
		$context   = '';
		$sources   = array();
		$by_post   = array();

		foreach ( $chunks as $chunk ) {
			$post_id = (int) $chunk['post_id'];
			$content = trim( (string) $chunk['content'] );
			if ( '' === $content ) {
				continue;
			}

			if ( ! isset( $by_post[ $post_id ] ) ) {
				$by_post[ $post_id ] = count( $sources ) + 1;
				$type_obj            = get_post_type_object( $chunk['post_type'] );
				$sources[]           = array(
					'index'      => $by_post[ $post_id ],
					'post_id'    => $post_id,
					'title'      => (string) $chunk['title'],
					'url'        => (string) $chunk['url'],
					'path'       => (string) $chunk['path'],
					'type'       => (string) $chunk['post_type'],
					'type_label' => $type_obj && isset( $type_obj->labels->singular_name ) ? $type_obj->labels->singular_name : (string) $chunk['post_type'],
					'excerpt'    => mb_substr( preg_replace( '/\s+/u', ' ', $content ), 0, 260 ),
					'heading'    => (string) $chunk['heading'],
					'score'      => (float) $chunk['score'],
					'cited'      => false,
				);
			}

			$number = $by_post[ $post_id ];
			$header = sprintf( '[%d] %s (%s)', $number, $chunk['title'], $sources[ $number - 1 ]['type_label'] );
			if ( ! empty( $chunk['heading'] ) ) {
				$header .= "\nSection: " . $chunk['heading'];
			}
			$header .= "\nURL: " . $chunk['url'] . "\n";

			$remaining = $max_chars - strlen( $context );
			if ( $remaining <= 200 ) {
				break;
			}

			if ( strlen( $header ) + strlen( $content ) > $remaining ) {
				$content = mb_substr( $content, 0, max( 0, $remaining - strlen( $header ) - 20 ) ) . '…';
			}

			$context .= ( '' === $context ? '' : "\n\n" ) . $header . $content;
			if ( strlen( $context ) >= $max_chars ) {
				break;
			}
		}

		return array(
			'context' => $context,
			'sources' => $sources,
		);
	}

	/**
	 * @param string $text       Text.
	 * @param bool   $remove_stop Remove stopwords.
	 * @return string[]
	 */
	public function tokenize( $text, $remove_stop = true ) {
		$text = $this->normalize_text( $text );
		$text = str_replace( array( '’', '‘', "'" ), '', $text );
		$words = preg_split( '/[^\p{L}\p{N}]+/u', $text );
		$out   = array();

		foreach ( (array) $words as $word ) {
			$word = trim( (string) $word );
			if ( mb_strlen( $word, 'UTF-8' ) < 2 ) {
				continue;
			}
			if ( $remove_stop && in_array( $word, self::$stopwords, true ) ) {
				continue;
			}
			$out[] = $this->stem( $word );
		}

		return $out;
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private function normalize_text( $text ) {
		$text = mb_strtolower( (string) $text, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * Very light English stemmer (enough for oncology terms and plurals).
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private function stem( $word ) {
		$length = strlen( $word );
		if ( $length > 4 && 'ies' === substr( $word, -3 ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}
		if ( $length > 4 && preg_match( '/(s|x|z|ch|sh)es$/', $word ) ) {
			return substr( $word, 0, -2 );
		}
		if ( $length > 3 && 's' === substr( $word, -1 ) && 'ss' !== substr( $word, -2 ) ) {
			return substr( $word, 0, -1 );
		}
		if ( $length > 5 && 'ing' === substr( $word, -3 ) ) {
			return substr( $word, 0, -3 );
		}
		if ( $length > 4 && 'ed' === substr( $word, -2 ) ) {
			return substr( $word, 0, -2 );
		}
		return $word;
	}

	/**
	 * @param float[] $values Values.
	 * @return array{min:float,max:float}
	 */
	private function range( array $values ) {
		if ( empty( $values ) ) {
			return array( 'min' => 0.0, 'max' => 0.0 );
		}
		return array(
			'min' => (float) min( $values ),
			'max' => (float) max( $values ),
		);
	}

	/**
	 * @param float               $value Value.
	 * @param array{min:float,max:float} $range Range.
	 * @return float
	 */
	private function min_max( $value, array $range ) {
		$span = (float) $range['max'] - (float) $range['min'];
		if ( $span <= 0.0 ) {
			return 0.0;
		}
		return max( 0.0, min( 1.0, ( (float) $value - (float) $range['min'] ) / $span ) );
	}

	/**
	 * @param float[] $a Vector.
	 * @param float[] $b Vector.
	 * @return float
	 */
	private function cosine_vectors( array $a, array $b ) {
		$dot  = 0.0;
		$an   = 0.0;
		$bn   = 0.0;
		$size = min( count( $a ), count( $b ) );

		for ( $i = 0; $i < $size; $i++ ) {
			$dot += $a[ $i ] * $b[ $i ];
			$an  += $a[ $i ] * $a[ $i ];
			$bn  += $b[ $i ] * $b[ $i ];
		}

		if ( $an <= 0.0 || $bn <= 0.0 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $an ) * sqrt( $bn ) );
	}
}
