<?php
/**
 * Vector store.
 *
 * Vectors are quantised to int8 and stored as base64 in MySQL. That keeps a
 * 1536-dimension embedding at ~2 KB (instead of ~8 KB as float32) while cosine
 * similarity stays accurate to a few decimal places — plenty for retrieval.
 *
 * Tables:
 *   {prefix}onx_ask_documents  One row per indexed post (title, URL, hash, status).
 *   {prefix}onx_ask_chunks     One row per passage (text + quantised vector).
 *   {prefix}onx_ask_logs       Recent questions for admin analytics (optional).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Vector_Store {

	/**
	 * @return string
	 */
	public function documents_table() {
		global $wpdb;
		return $wpdb->prefix . 'onx_ask_documents';
	}

	/**
	 * @return string
	 */
	public function chunks_table() {
		global $wpdb;
		return $wpdb->prefix . 'onx_ask_chunks';
	}

	/**
	 * @return string
	 */
	public function logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'onx_ask_logs';
	}

	/**
	 * Create / upgrade the schema.
	 */
	public function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$docs    = $this->documents_table();
		$chunks  = $this->chunks_table();
		$logs    = $this->logs_table();

		$sql = "CREATE TABLE {$docs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			post_type varchar(64) NOT NULL DEFAULT '',
			title text NOT NULL,
			url text NOT NULL,
			path text NOT NULL,
			modified_gmt datetime DEFAULT NULL,
			content_hash char(32) NOT NULL DEFAULT '',
			embedding_provider varchar(32) NOT NULL DEFAULT '',
			embedding_model varchar(120) NOT NULL DEFAULT '',
			embedding_dim smallint(5) unsigned NOT NULL DEFAULT 0,
			embedding_status varchar(16) NOT NULL DEFAULT '',
			chunk_count smallint(5) unsigned NOT NULL DEFAULT 0,
			indexed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY post_type (post_type),
			KEY modified_gmt (modified_gmt)
		) {$charset};

		CREATE TABLE {$chunks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			document_id bigint(20) unsigned NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			chunk_index smallint(5) unsigned NOT NULL DEFAULT 0,
			heading text NULL,
			content longtext NOT NULL,
			content_hash char(32) NOT NULL DEFAULT '',
			token_count smallint(5) unsigned NOT NULL DEFAULT 0,
			embedding longtext NULL,
			embedding_scale double NOT NULL DEFAULT 0,
			embedding_dim smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY document_id (document_id),
			KEY post_id (post_id)
		) {$charset};

		CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question text NOT NULL,
			provider varchar(32) NOT NULL DEFAULT '',
			model varchar(120) NOT NULL DEFAULT '',
			cache_hit tinyint(1) NOT NULL DEFAULT 0,
			sources_count smallint(5) unsigned NOT NULL DEFAULT 0,
			answer_chars int(11) NOT NULL DEFAULT 0,
			latency_ms int(11) NOT NULL DEFAULT 0,
			ip_hash char(32) NOT NULL DEFAULT '',
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );

		update_option( 'onx_ask_db_version', ONX_ASK_DB_VERSION, false );
	}

	/**
	 * Quantise a float vector to int8 + scale.
	 *
	 * @param float[] $vector Vector.
	 * @return array{data:string,scale:float,dim:int}
	 */
	public function encode_vector( array $vector ) {
		$max = 0.0;
		foreach ( $vector as $value ) {
			$abs = abs( (float) $value );
			if ( $abs > $max ) {
				$max = $abs;
			}
		}
		if ( $max <= 0.0 ) {
			$max = 1.0;
		}

		$scale  = $max / 127.0;
		$packed = '';
		foreach ( $vector as $value ) {
			$q = (int) round( ( (float) $value ) / $scale );
			$q = max( -127, min( 127, $q ) );
			$packed .= pack( 'c', $q );
		}

		return array(
			'data'  => base64_encode( $packed ),
			'scale' => $scale,
			'dim'   => count( $vector ),
		);
	}

	/**
	 * Cosine similarity between a stored int8 vector and a float query vector.
	 *
	 * @param string  $encoded Base64 int8 vector.
	 * @param float   $scale   Quantisation scale.
	 * @param float[] $query   Query vector.
	 * @return float
	 */
	public function cosine( $encoded, $scale, array $query ) {
		if ( '' === (string) $encoded || $scale <= 0 || empty( $query ) ) {
			return 0.0;
		}

		$raw = base64_decode( (string) $encoded, true );
		if ( false === $raw || '' === $raw ) {
			return 0.0;
		}

		$ints = unpack( 'c*', $raw );
		if ( ! is_array( $ints ) || empty( $ints ) ) {
			return 0.0;
		}

		$qnorm = 0.0;
		foreach ( $query as $value ) {
			$qnorm += ( (float) $value ) * ( (float) $value );
		}
		if ( $qnorm <= 0.0 ) {
			return 0.0;
		}

		$dot   = 0.0;
		$vnorm = 0.0;
		$index = 0;
		foreach ( $ints as $value ) {
			if ( ! isset( $query[ $index ] ) ) {
				break;
			}
			$f      = (float) $value * (float) $scale;
			$dot   += $f * (float) $query[ $index ];
			$vnorm += $f * $f;
			$index++;
		}

		if ( $vnorm <= 0.0 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $qnorm ) * sqrt( $vnorm ) );
	}

	/**
	 * Decode a stored int8 vector back to floats (used by MMR diversification).
	 *
	 * @param string $encoded Base64 int8 vector.
	 * @param float  $scale   Quantisation scale.
	 * @return float[]
	 */
	public function decode_vector( $encoded, $scale ) {
		if ( '' === (string) $encoded || $scale <= 0 ) {
			return array();
		}

		$raw = base64_decode( (string) $encoded, true );
		if ( false === $raw || '' === $raw ) {
			return array();
		}

		$ints = unpack( 'c*', $raw );
		if ( ! is_array( $ints ) ) {
			return array();
		}

		$vector = array();
		foreach ( $ints as $value ) {
			$vector[] = (float) $value * (float) $scale;
		}
		return $vector;
	}

	/**
	 * Replace the index for a single document.
	 *
	 * @param array<string,mixed> $document Document metadata.
	 * @param array<int,array{content:string,heading:string,chars:int}> $chunks Chunks.
	 * @param array<int,float[]>  $vectors  Embeddings (same order as chunks).
	 * @param string              $provider Embedding provider.
	 * @param string              $model    Embedding model.
	 * @param int                 $dim      Embedding dimensions.
	 * @return true|WP_Error
	 */
	public function replace_document( array $document, array $chunks, array $vectors, $provider, $model, $dim ) {
		global $wpdb;

		$post_id = isset( $document['post_id'] ) ? (int) $document['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return new WP_Error( 'onx_ask_invalid_document', __( 'Invalid document.', 'onx-ask' ) );
		}

		$this->delete_document( $post_id );

		$now          = current_time( 'mysql', true );
		$has_vectors  = ! empty( $vectors ) && count( $vectors ) === count( $chunks );
		$status       = $has_vectors ? 'ok' : ( empty( $chunks ) ? 'empty' : 'lexical' );

		$inserted = $wpdb->insert(
			$this->documents_table(),
			array(
				'post_id'            => $post_id,
				'post_type'          => isset( $document['post_type'] ) ? (string) $document['post_type'] : '',
				'title'              => isset( $document['title'] ) ? (string) $document['title'] : '',
				'url'                => isset( $document['url'] ) ? (string) $document['url'] : '',
				'path'               => isset( $document['path'] ) ? (string) $document['path'] : '',
				'modified_gmt'       => ! empty( $document['modified_gmt'] ) ? (string) $document['modified_gmt'] : $now,
				'content_hash'       => isset( $document['content_hash'] ) ? (string) $document['content_hash'] : '',
				'embedding_provider' => (string) $provider,
				'embedding_model'    => (string) $model,
				'embedding_dim'      => (int) $dim,
				'embedding_status'   => $status,
				'chunk_count'        => count( $chunks ),
				'indexed_at'         => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'onx_ask_db_error', $wpdb->last_error ? $wpdb->last_error : __( 'Could not store the document.', 'onx-ask' ) );
		}

		$document_id = (int) $wpdb->insert_id;

		foreach ( $chunks as $index => $chunk ) {
			$vector = isset( $vectors[ $index ] ) && is_array( $vectors[ $index ] ) ? $vectors[ $index ] : array();
			$packed = ! empty( $vector ) ? $this->encode_vector( $vector ) : array( 'data' => '', 'scale' => 0, 'dim' => 0 );

			$row = array(
				'document_id'     => $document_id,
				'post_id'         => $post_id,
				'chunk_index'     => (int) $index,
				'heading'         => isset( $chunk['heading'] ) ? (string) $chunk['heading'] : '',
				'content'         => isset( $chunk['content'] ) ? (string) $chunk['content'] : '',
				'content_hash'    => md5( isset( $chunk['content'] ) ? (string) $chunk['content'] : '' ),
				'token_count'     => min( 65535, (int) ceil( strlen( isset( $chunk['content'] ) ? (string) $chunk['content'] : '' ) / 4 ) ),
				'embedding'       => $packed['data'],
				'embedding_scale' => (float) $packed['scale'],
				'embedding_dim'   => (int) $packed['dim'],
				'created_at'      => $now,
			);

			$ok = $wpdb->insert(
				$this->chunks_table(),
				$row,
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%f', '%d', '%s' )
			);

			if ( false === $ok ) {
				$this->delete_document( $post_id );
				return new WP_Error( 'onx_ask_db_error', $wpdb->last_error ? $wpdb->last_error : __( 'Could not store a chunk.', 'onx-ask' ) );
			}
		}

		return true;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function get_document( $post_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $this->documents_table() . ' WHERE post_id = %d', (int) $post_id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public function delete_document( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$wpdb->delete( $this->chunks_table(), array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $this->documents_table(), array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Load candidate chunks for retrieval.
	 *
	 * @param array<string,mixed> $args post_types, limit, offset, include_embeddings.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_chunks( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'post_types' => array(),
				'limit'      => 5000,
				'offset'     => 0,
			)
		);

		$docs   = $this->documents_table();
		$chunks = $this->chunks_table();

		$sql    = "SELECT c.id, c.document_id, c.post_id, c.chunk_index, c.heading, c.content,
					c.embedding, c.embedding_scale, c.embedding_dim,
					d.title, d.url, d.path, d.post_type, d.modified_gmt, d.embedding_status
				FROM {$chunks} c
				INNER JOIN {$docs} d ON d.post_id = c.post_id
				WHERE 1=1";
		$params = array();

		$types = array_filter( array_map( 'sanitize_key', (array) $args['post_types'] ) );
		if ( ! empty( $types ) ) {
			$sql     .= ' AND d.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params   = array_merge( $params, array_values( $types ) );
		}

		$sql     .= ' ORDER BY c.id ASC LIMIT %d OFFSET %d';
		$params[] = max( 1, (int) $args['limit'] );
		$params[] = max( 0, (int) $args['offset'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string[] $post_types Optional filter.
	 * @return int
	 */
	public function count_chunks( array $post_types = array() ) {
		global $wpdb;

		$docs   = $this->documents_table();
		$chunks = $this->chunks_table();
		$sql    = "SELECT COUNT(c.id) FROM {$chunks} c INNER JOIN {$docs} d ON d.post_id = c.post_id WHERE 1=1";
		$params = array();

		$types = array_filter( array_map( 'sanitize_key', $post_types ) );
		if ( ! empty( $types ) ) {
			$sql     .= ' AND d.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params   = array_merge( $params, array_values( $types ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function stats() {
		global $wpdb;

		$docs   = $this->documents_table();
		$chunks = $this->chunks_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$document_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$docs}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$chunk_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$embedded_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks} WHERE embedding <> ''" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_indexed = $wpdb->get_var( "SELECT MAX(indexed_at) FROM {$docs}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$providers = $wpdb->get_results( "SELECT DISTINCT embedding_provider, embedding_model, embedding_dim, embedding_status FROM {$docs}", ARRAY_A );

		return array(
			'documents'        => $document_count,
			'chunks'           => $chunk_count,
			'embedded_chunks'  => $embedded_count,
			'missing_vectors'  => max( 0, $chunk_count - $embedded_count ),
			'last_indexed'     => $last_indexed ? (string) $last_indexed : '',
			'embedding_models' => is_array( $providers ) ? $providers : array(),
		);
	}

	/**
	 * Drop every indexed document and chunk.
	 */
	public function flush() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->chunks_table() );
		$wpdb->query( 'DELETE FROM ' . $this->documents_table() );
		update_option( 'onx_ask_index_version', (string) time(), false );
	}

	/**
	 * @param array<string,mixed> $data Log payload.
	 */
	public function log_query( array $data ) {
		global $wpdb;

		$wpdb->insert(
			$this->logs_table(),
			array(
				'question'      => isset( $data['question'] ) ? (string) $data['question'] : '',
				'provider'      => isset( $data['provider'] ) ? (string) $data['provider'] : '',
				'model'         => isset( $data['model'] ) ? (string) $data['model'] : '',
				'cache_hit'     => ! empty( $data['cache_hit'] ) ? 1 : 0,
				'sources_count' => isset( $data['sources_count'] ) ? (int) $data['sources_count'] : 0,
				'answer_chars'  => isset( $data['answer_chars'] ) ? (int) $data['answer_chars'] : 0,
				'latency_ms'    => isset( $data['latency_ms'] ) ? (int) $data['latency_ms'] : 0,
				'ip_hash'       => isset( $data['ip_hash'] ) ? (string) $data['ip_hash'] : '',
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		// Keep the log table small without a cron dependency.
		if ( 1 === wp_rand( 1, 25 ) ) {
			$this->prune_logs();
		}
	}

	/**
	 * @param int $limit Number of rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_logs( $limit = 25 ) {
		global $wpdb;
		$sql = 'SELECT id, question, provider, model, cache_hit, sources_count, answer_chars, latency_ms, created_at
			FROM ' . $this->logs_table() . ' ORDER BY id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, max( 1, (int) $limit ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Keep the newest 1000 log rows.
	 */
	public function prune_logs() {
		global $wpdb;
		$table = $this->logs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$threshold = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET 1000" );
		if ( $threshold > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $threshold ) );
		}
	}

	/**
	 * Delete all plugin data (uninstall).
	 */
	public function drop_tables() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->chunks_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->documents_table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->logs_table() );
	}
}
