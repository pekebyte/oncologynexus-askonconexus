<?php
/**
 * Structure-aware chunker.
 *
 * Strategy:
 *  - `html_to_text()` in the content class leaves `## Heading` markers, so we
 *    chunk section by section instead of cutting a document mid-thought.
 *  - Paragraphs are packed up to `chunk_size` characters, then split by sentence
 *    when a single paragraph is too long.
 *  - Each chunk starts with the tail of the previous one (`chunk_overlap`), which
 *    keeps answers that straddle a boundary retrievable.
 *  - Every chunk keeps its heading so the retriever can show "Section: ..." and
 *    the LLM gets local context.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Chunker {

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	public function __construct( OncologyNexus_Ask_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param string              $text Full document text.
	 * @param array<string,mixed> $meta Optional document metadata.
	 * @return array<int,array{content:string,heading:string,chars:int}>
	 */
	public function chunk( $text, array $meta = array() ) {
		$text = $this->normalize( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		$size      = max( 400, (int) $this->settings->get( 'chunk_size', 1800 ) );
		$overlap   = (float) $this->settings->get( 'chunk_overlap', 0.15 );
		$overlap   = max( 0.0, min( 0.5, $overlap ) );
		$max_chunks = max( 5, (int) $this->settings->get( 'max_chunks_per_doc', 80 ) );

		$sections = $this->split_sections( $text );
		$chunks   = array();

		foreach ( $sections as $section ) {
			$heading    = $section['heading'];
			$body       = trim( $section['body'] );
			if ( '' === $body ) {
				continue;
			}
			$paragraphs = preg_split( '/\n{2,}/', $body );
			$buffer     = '';

			foreach ( (array) $paragraphs as $paragraph ) {
				$paragraph = trim( $paragraph );
				if ( '' === $paragraph ) {
					continue;
				}

				if ( strlen( $paragraph ) > $size ) {
					if ( '' !== $buffer ) {
						$chunks[] = $this->make_chunk( $buffer, $heading );
						$buffer   = '';
					}
					$pieces = $this->split_long_text( $paragraph, $size );
					$tail   = '';
					foreach ( $pieces as $piece ) {
						if ( '' !== $tail ) {
							$piece = $this->tail( $tail, (int) floor( $size * $overlap ) ) . ' ' . $piece;
						}
						$chunks[] = $this->make_chunk( $piece, $heading );
						$tail     = $piece;
					}
					continue;
				}

				$candidate = '' === $buffer ? $paragraph : $buffer . "\n\n" . $paragraph;
				if ( strlen( $candidate ) <= $size ) {
					$buffer = $candidate;
					continue;
				}

				$chunks[] = $this->make_chunk( $buffer, $heading );
				$carry    = $this->tail( $buffer, (int) floor( $size * $overlap ) );
				$buffer   = '' === $carry ? $paragraph : $carry . "\n\n" . $paragraph;
			}

			if ( '' !== trim( $buffer ) ) {
				$chunks[] = $this->make_chunk( $buffer, $heading );
			}
		}

		// Merge tiny trailing fragments into the previous chunk when possible.
		$chunks = $this->merge_tiny( $chunks, $size );

		// Cap the number of chunks per document. The head of a document almost
		// always carries the thesis; keep that first and let admins raise the cap
		// for unusually long reports.
		if ( count( $chunks ) > $max_chunks ) {
			$chunks = array_slice( $chunks, 0, $max_chunks );
		}

		/**
		 * Filter the chunks for a single document.
		 *
		 * @param array<int,array{content:string,heading:string,chars:int}> $chunks Chunks.
		 * @param array<string,mixed>                                        $meta   Document metadata.
		 */
		return apply_filters( 'onx_ask_chunks', $chunks, $meta );
	}

	/**
	 * Build the text that is embedded for a chunk. The document title and section
	 * heading are prepended (contextual retrieval), while the stored `content`
	 * stays clean for citations.
	 *
	 * @param array{content:string,heading:string} $chunk Chunk.
	 * @param array<string,mixed>                  $meta  Document meta.
	 * @return string
	 */
	public function embedding_text( array $chunk, array $meta = array() ) {
		$title = isset( $meta['title'] ) ? (string) $meta['title'] : '';
		$type  = isset( $meta['post_type'] ) ? (string) $meta['post_type'] : '';

		$header = array();
		if ( '' !== $title ) {
			$header[] = 'Title: ' . $title;
		}
		if ( '' !== $type ) {
			$header[] = 'Content type: ' . $type;
		}
		if ( ! empty( $chunk['heading'] ) ) {
			$header[] = 'Section: ' . $chunk['heading'];
		}

		return implode( "\n", $header ) . "\n\n" . $chunk['content'];
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private function normalize( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/ *\n */', "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Split on `## Heading` markers produced by Content::html_to_text().
	 *
	 * @param string $text Text.
	 * @return array<int,array{heading:string,body:string}>
	 */
	private function split_sections( $text ) {
		$lines    = explode( "\n", $text );
		$sections = array();
		$heading  = '';
		$buffer   = array();

		foreach ( $lines as $line ) {
			if ( preg_match( '/^##\s+(.+)$/', trim( $line ), $matches ) ) {
				if ( ! empty( $buffer ) ) {
					$sections[] = array(
						'heading' => $heading,
						'body'    => implode( "\n", $buffer ),
					);
					$buffer = array();
				}
				$heading = trim( wp_strip_all_tags( $matches[1] ) );
				continue;
			}
			$buffer[] = $line;
		}

		if ( ! empty( $buffer ) ) {
			$sections[] = array(
				'heading' => $heading,
				'body'    => implode( "\n", $buffer ),
			);
		}

		if ( empty( $sections ) ) {
			$sections[] = array(
				'heading' => '',
				'body'    => $text,
			);
		}

		return $sections;
	}

	/**
	 * Split a long paragraph on sentence boundaries.
	 *
	 * @param string $text Text.
	 * @param int    $size Target size in characters.
	 * @return string[]
	 */
	private function split_long_text( $text, $size ) {
		$sentences = preg_split( '/(?<=[\.\!\?])\s+(?=[A-Z0-9“"\(\[])/u', $text );
		if ( ! is_array( $sentences ) || count( $sentences ) < 2 ) {
			return $this->split_by_words( $text, $size );
		}

		$pieces = array();
		$buffer = '';
		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( '' === $sentence ) {
				continue;
			}
			$candidate = '' === $buffer ? $sentence : $buffer . ' ' . $sentence;
			if ( strlen( $candidate ) <= $size ) {
				$buffer = $candidate;
				continue;
			}
			if ( '' !== $buffer ) {
				$pieces[] = $buffer;
			}
			$buffer = $sentence;
		}
		if ( '' !== $buffer ) {
			$pieces[] = $buffer;
		}

		// A single monster sentence still needs to be split.
		$out = array();
		foreach ( $pieces as $piece ) {
			if ( strlen( $piece ) > $size * 1.4 ) {
				$out = array_merge( $out, $this->split_by_words( $piece, $size ) );
			} else {
				$out[] = $piece;
			}
		}

		return $out;
	}

	/**
	 * @param string $text Text.
	 * @param int    $size Target size.
	 * @return string[]
	 */
	private function split_by_words( $text, $size ) {
		$words  = preg_split( '/\s+/', $text );
		$pieces = array();
		$buffer = '';
		foreach ( (array) $words as $word ) {
			$candidate = '' === $buffer ? $word : $buffer . ' ' . $word;
			if ( strlen( $candidate ) > $size && '' !== $buffer ) {
				$pieces[] = $buffer;
				$buffer   = $word;
				continue;
			}
			$buffer = $candidate;
		}
		if ( '' !== $buffer ) {
			$pieces[] = $buffer;
		}
		return $pieces;
	}

	/**
	 * @param string $text    Chunk text.
	 * @param string $heading Heading.
	 * @return array{content:string,heading:string,chars:int}
	 */
	private function make_chunk( $text, $heading ) {
		$text = trim( $text );
		return array(
			'content' => $text,
			'heading' => trim( $heading ),
			'chars'   => strlen( $text ),
		);
	}

	/**
	 * @param string $text Text.
	 * @param int    $length Length.
	 * @return string
	 */
	private function tail( $text, $length ) {
		$length = max( 0, (int) $length );
		if ( 0 === $length || strlen( $text ) <= $length ) {
			return '';
		}
		$tail = substr( $text, -$length );
		// Start on a word boundary so the overlap reads naturally.
		$space = strpos( $tail, ' ' );
		if ( false !== $space && $space < 40 ) {
			$tail = substr( $tail, $space + 1 );
		}
		return trim( $tail );
	}

	/**
	 * Merge fragments under 120 characters into the previous chunk.
	 *
	 * @param array<int,array{content:string,heading:string,chars:int}> $chunks Chunks.
	 * @param int                                                       $size   Max size.
	 * @return array<int,array{content:string,heading:string,chars:int}>
	 */
	private function merge_tiny( array $chunks, $size ) {
		$out = array();
		foreach ( $chunks as $chunk ) {
			$previous = count( $out ) - 1;
			if ( $previous >= 0 && strlen( $chunk['content'] ) < 120 ) {
				$merged = $out[ $previous ]['content'] . "\n\n" . $chunk['content'];
				if ( strlen( $merged ) <= $size * 1.15 ) {
					$out[ $previous ]['content'] = $merged;
					$out[ $previous ]['chars']   = strlen( $merged );
					continue;
				}
			}
			$out[] = $chunk;
		}
		return $out;
	}
}
