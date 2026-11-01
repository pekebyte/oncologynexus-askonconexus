<?php
/**
 * Turns a WordPress post (core content, taxonomies, excerpt, Yoast description
 * and ACF / custom-field prose) into one clean document for chunking.
 *
 * The indexer deliberately indexes *rendered editorial meaning*, not template
 * markup: ACF flexible-content arrays are walked recursively and only prose-like
 * values are kept. This is what makes doctor profiles, conference pages and
 * podcast pages searchable even when `post_content` is empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Content {

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	public function __construct( OncologyNexus_Ask_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param int|WP_Post $post Post or ID.
	 * @return bool
	 */
	public function is_indexable( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$types = $this->settings->post_types();
		$ok    = in_array( $post->post_type, $types, true )
			&& 'publish' === $post->post_status
			&& '' === (string) $post->post_password
			&& ! in_array( (int) $post->ID, $this->settings->excluded_ids(), true );

		/**
		 * Filter whether a post is part of the RAG corpus.
		 *
		 * @param bool    $ok   Whether to index.
		 * @param WP_Post $post Post object.
		 */
		return (bool) apply_filters( 'onx_ask_is_indexable', $ok, $post );
	}

	/**
	 * Build the full document payload for a post.
	 *
	 * @param int|WP_Post $post Post or ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function build_document( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'onx_ask_invalid_post', __( 'The post could not be loaded.', 'onx-ask' ) );
		}

		$permalink = get_permalink( $post );
		if ( ! $permalink ) {
			$permalink = home_url( '/?p=' . (int) $post->ID );
		}

		$title      = $this->clean_text( get_the_title( $post ) );
		$type_obj   = get_post_type_object( $post->post_type );
		$type_label = $type_obj && isset( $type_obj->labels->singular_name ) ? $type_obj->labels->singular_name : $post->post_type;
		$published  = get_post_time( 'Y-m-d', true, $post );
		$author     = (int) $post->post_author ? get_the_author_meta( 'display_name', (int) $post->post_author ) : '';

		$content_text = $this->html_to_text( (string) $post->post_content );

		$excerpt = trim( (string) $post->post_excerpt );
		if ( '' === $excerpt ) {
			foreach ( array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description' ) as $meta_key ) {
				$candidate = get_post_meta( (int) $post->ID, $meta_key, true );
				if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
					$excerpt = $candidate;
					break;
				}
			}
		}
		if ( '' === $excerpt && '' !== $content_text ) {
			$excerpt = mb_substr( $content_text, 0, 320 );
		}
		$excerpt = $this->clean_text( $excerpt );

		$topics = $this->get_term_names( $post );
		$meta   = $this->extract_meta_text( (int) $post->ID );

		$parts = array(
			$title,
			sprintf( 'Type: %s', $type_label ),
		);
		if ( $published ) {
			$parts[] = sprintf( 'Published: %s', $published );
		}
		if ( $author ) {
			$parts[] = sprintf( 'Author: %s', $author );
		}
		if ( $topics ) {
			$parts[] = 'Topics: ' . implode( ', ', $topics );
		}
		if ( $excerpt ) {
			$parts[] = 'Summary: ' . $excerpt;
		}
		if ( $content_text ) {
			$parts[] = $content_text;
		}
		if ( $meta ) {
			$parts[] = $meta;
		}

		$text = implode( "\n\n", array_values( array_filter( array_map( 'trim', $parts ) ) ) );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		/**
		 * Filter the plain-text document before it is chunked and embedded.
		 *
		 * @param string  $text Plain text.
		 * @param WP_Post $post Post object.
		 */
		$text = (string) apply_filters( 'onx_ask_document_text', $text, $post );

		$path = wp_make_link_relative( $permalink );
		if ( ! $path ) {
			$path = $permalink;
		}

		return array(
			'post_id'      => (int) $post->ID,
			'post_type'    => (string) $post->post_type,
			'title'        => $title,
			'url'          => $permalink,
			'path'         => $path,
			'modified_gmt' => (string) $post->post_modified_gmt,
			'content_hash' => md5( $text ),
			'text'         => $text,
			'terms'        => $topics,
		);
	}

	/**
	 * Public taxonomy names attached to a post.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	private function get_term_names( WP_Post $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		$names      = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, $taxonomy->name, array( 'fields' => 'names' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term_name ) {
				$term_name = $this->clean_text( $term_name );
				if ( '' !== $term_name ) {
					$names[] = $term_name;
				}
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Walk all public post meta (ACF stores fields there) and keep prose values.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function extract_meta_text( $post_id ) {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return '';
		}

		$parts = array();
		$seen  = array();

		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( $this->is_internal_meta_key( $key ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				$value = maybe_unserialize( $value );
				$this->flatten_value( $value, $key, $parts, $seen, 0 );
				if ( strlen( implode( "\n", $parts ) ) > 120000 ) {
					break 2;
				}
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * @param string $key Meta key.
	 * @return bool
	 */
	private function is_internal_meta_key( $key ) {
		if ( '' === $key || '_' === $key[0] ) {
			return true;
		}

		return (bool) preg_match(
			'/(elementor|yoast|rank_math|aioseo|uagb|astra|bricks|flatsome|divi|wpbakery|vc_|wpml|polylang|presto|snippet|edit_lock|edit_last|oembed|pingme|encloseme|wp_page_template|wp_old_slug|menu_item|mega_menu|tracking|analytics|gtm|pixel)/i',
			$key
		);
	}

	/**
	 * Keys that look like prose.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private function meta_key_included( $key ) {
		return (bool) preg_match(
			'/(title|heading|subheading|text|content|body|description|desc$|summary|excerpt|quote|label|name|bio|definition|answer|question|intro|copy|paragraph|role|specialty|affiliation|institution|education|position|degree|experience|about|overview|mission|vision|history|treatment|diagnosis|symptom|cause|risk|benefit|side|effect|outcome|study|trial|result|conclusion|method|objective|background|author|speaker|guest|host|topic|tag|keyword|alias|synonym|note|highlight|message|testimonial|review|credential|certification|award|publication|research|interest|focus|expertise|language|location|city|country|address|organization|company|job|department|program|event|session|agenda|abstract|reading|duration|episode|season|series|transcript|show|takeaway|key_point|bullet|faq|alt|caption|mission|cancer|tumor|tumour|disease|biomarker|mutation|histology|oncology|metastatic|metastasis|stage|grade|regimen|drug|therapy|clinical|conference|guideline|efficacy|safety|survival|screening|biopsy|pathology|genomic|molecular|immunotherapy|chemotherapy|radiation|surgery|transplant|dose|dosage|approval|indication|cohort|endpoint|journal|presentation|poster|faculty|moderator|panel|profile)/i',
			$key
		);
	}

	/**
	 * Keys that are almost always structural / identifiers.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private function meta_key_excluded( $key ) {
		return (bool) preg_match(
			'/(url|link|href|src|image|img|icon|logo|photo|thumbnail|file|video|embed|iframe|media|attachment|gallery|color|background|css|class|style|code|html|json|schema|template|layout|column|width|height|size|align|margin|padding|order|position|enable|disable|active|status|type|field|group|key$|id$|object|relation|taxonomy|term_id|post_id|user_id|map|lat|lng|coordinate|phone|email|fax|zip|nonce|token|secret|password|hash|slug|permalink|header|footer|script|sponsor|price|currency|date|time|year|count|number|sort|weight|priority|parent|children|target|rel|nofollow|open_in|new_tab|download|filename|mime|metadata|settings|config|option)/i',
			$key
		);
	}

	/**
	 * Recursively collect prose from a meta / ACF value.
	 *
	 * @param mixed                $value Value.
	 * @param string               $key   Current key.
	 * @param string[]             $parts Collected parts.
	 * @param array<string,true>   $seen  Dedupe hashes.
	 * @param int                  $depth Depth.
	 */
	private function flatten_value( $value, $key, array &$parts, array &$seen, $depth ) {
		if ( $depth > 8 || count( $parts ) > 500 ) {
			return;
		}

		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			$only_known = $this->meta_key_excluded( $key ) && ! $this->meta_key_included( $key );

			foreach ( $value as $child_key => $child_value ) {
				if ( is_int( $child_key ) ) {
					$this->flatten_value( $child_value, $key, $parts, $seen, $depth + 1 );
					continue;
				}

				$child_key = (string) $child_key;
				if ( $only_known
					&& ! $this->meta_key_included( $child_key )
					&& ! in_array( $child_key, array( 'alt', 'caption', 'title', 'description', 'name' ), true )
				) {
					continue;
				}

				$this->flatten_value( $child_value, $child_key, $parts, $seen, $depth + 1 );
			}
			return;
		}

		if ( ! is_string( $value ) ) {
			return;
		}

		$value = trim( $value );
		if ( strlen( $value ) < 3 ) {
			return;
		}

		// Skip URLs, IDs, hashes, serialised arrays and tiny JSON blobs.
		if ( preg_match( '#^(https?://|//|/wp-content/|/wp-includes/)#i', $value ) ) {
			return;
		}
		if ( preg_match( '/^[0-9,.\s-]+$/', $value ) ) {
			return;
		}
		if ( preg_match( '/^[a-f0-9]{32,}$/i', $value ) ) {
			return;
		}
		if ( preg_match( '/^a:\d+:\{/', $value ) ) {
			return;
		}
		if ( strlen( $value ) < 200 && preg_match( '/^\s*[\{\[].*[\}\]]\s*$/s', $value ) ) {
			return;
		}

		$included = $this->meta_key_included( $key );
		$excluded = $this->meta_key_excluded( $key );

		if ( $excluded && ! $included ) {
			return;
		}
		if ( ! $included && strlen( $value ) < 60 ) {
			return;
		}

		if ( strlen( $value ) > 20000 ) {
			$value = mb_strcut( $value, 0, 20000, 'UTF-8' );
		}

		$text = $this->html_to_text( $value );
		if ( strlen( $text ) < 3 ) {
			return;
		}

		$hash = md5( $text );
		if ( isset( $seen[ $hash ] ) ) {
			return;
		}
		$seen[ $hash ] = true;
		$parts[]       = $text;
	}

	/**
	 * Convert HTML (Gutenberg, ACF wysiwyg, Elementor leftovers) to clean text.
	 *
	 * Headings are preserved as `## heading` markers so the chunker can keep
	 * section context for each passage.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public function html_to_text( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}

		// Remove non-content elements entirely.
		$html = preg_replace( '#<(script|style|noscript|template|svg|canvas|iframe)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<!--.*?-->#s', ' ', $html );

		// Preserve heading hierarchy for the chunker.
		$html = preg_replace( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', "\n\n## $2\n\n", $html );
		$html = preg_replace( '#<li\b[^>]*>#i', "\n- ", $html );
		$html = preg_replace( '#<(br|hr)\b[^>]*>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|section|article|header|footer|li|tr|td|th|blockquote|figure|figcaption|ul|ol|table|h[1-6])\b[^>]*>#i', "\n", $html );

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Drop shortcode shells (keep their inner text when it exists).
		$text = preg_replace( '/\[[^\]\n]{1,120}\]/', ' ', $text );

		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF" ), ' ', $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/ *\n */', "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * @param string $text Text.
	 * @return string
	 */
	private function clean_text( $text ) {
		$text = $this->html_to_text( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * IDs of published posts that belong in the corpus.
	 *
	 * @param array<string,mixed> $args since_gmt, limit, offset, order, include_ids.
	 * @return int[]
	 */
	public function get_indexable_post_ids( array $args = array() ) {
		global $wpdb;

		$types = $this->settings->post_types();
		if ( empty( $types ) ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'since_gmt'  => '',
				'limit'      => 0,
				'offset'     => 0,
				'order'      => 'ASC',
				'orderby'    => 'ID',
				'include_ids' => array(),
			)
		);

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql          = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish' AND post_password = ''";
		$params       = $types;

		if ( ! empty( $args['since_gmt'] ) ) {
			$sql     .= ' AND post_modified_gmt >= %s';
			$params[] = $args['since_gmt'];
		}

		$exclude = $this->settings->excluded_ids();
		if ( ! empty( $exclude ) ) {
			$sql     .= ' AND ID NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
			$params   = array_merge( $params, $exclude );
		}

		$include = array_filter( array_map( 'absint', (array) $args['include_ids'] ) );
		if ( ! empty( $include ) ) {
			$sql     .= ' AND ID IN (' . implode( ',', array_fill( 0, count( $include ), '%d' ) ) . ')';
			$params   = array_merge( $params, $include );
		}

		$orderby = 'modified' === $args['orderby'] ? 'post_modified_gmt' : 'ID';
		$order   = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$sql    .= " ORDER BY {$orderby} {$order}";

		if ( (int) $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = (int) $args['limit'];
			if ( (int) $args['offset'] > 0 ) {
				$sql     .= ' OFFSET %d';
				$params[] = (int) $args['offset'];
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Number of published posts in the corpus.
	 *
	 * @return int
	 */
	public function count_indexable_posts() {
		global $wpdb;

		$types = $this->settings->post_types();
		if ( empty( $types ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql          = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish' AND post_password = ''";
		$params       = $types;

		$exclude = $this->settings->excluded_ids();
		if ( ! empty( $exclude ) ) {
			$sql     .= ' AND ID NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
			$params   = array_merge( $params, $exclude );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}
}
