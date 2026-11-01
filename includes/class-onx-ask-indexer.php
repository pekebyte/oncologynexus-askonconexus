<?php
/**
 * Index lifecycle.
 *
 * - On publish/update, a post is queued for reindexing (debounced via cron).
 * - On trash/delete/unpublish, its document and chunks are removed.
 * - A full / recent reindex runs in small cron batches so shared hosts never
 *   time out; progress is exposed to the admin UI.
 * - A daily sync catches edits made through imports, WP-CLI or the REST API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Indexer {

	const QUEUE_OPTION      = 'onx_ask_index_queue';
	const TOTAL_OPTION      = 'onx_ask_index_total';
	const DONE_OPTION       = 'onx_ask_index_done';
	const RUNNING_OPTION    = 'onx_ask_index_running';
	const STARTED_OPTION    = 'onx_ask_index_started';
	const ERRORS_OPTION     = 'onx_ask_index_errors';
	const LAST_SYNC_OPTION  = 'onx_ask_last_sync_gmt';
	const LAST_ERROR_OPTION = 'onx_ask_last_index_error';

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	/** @var OncologyNexus_Ask_Content */
	private $content;

	/** @var OncologyNexus_Ask_Chunker */
	private $chunker;

	/** @var OncologyNexus_Ask_Embeddings */
	private $embeddings;

	/** @var OncologyNexus_Ask_Vector_Store */
	private $store;

	public function __construct(
		OncologyNexus_Ask_Settings $settings,
		OncologyNexus_Ask_Content $content,
		OncologyNexus_Ask_Chunker $chunker,
		OncologyNexus_Ask_Embeddings $embeddings,
		OncologyNexus_Ask_Vector_Store $store
	) {
		$this->settings   = $settings;
		$this->content    = $content;
		$this->chunker    = $chunker;
		$this->embeddings = $embeddings;
		$this->store      = $store;

		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'wp_trash_post', array( $this, 'on_delete_post' ) );
		add_action( 'set_object_terms', array( $this, 'on_set_object_terms' ), 20, 1 );

		add_action( 'onx_ask_index_post', array( $this, 'index_post' ), 10, 2 );
		add_action( 'onx_ask_index_batch', array( $this, 'process_batch' ) );
		add_action( 'onx_ask_daily_sync', array( $this, 'daily_sync' ) );
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this is an update.
	 */
	public function on_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( $this->content->is_indexable( $post ) ) {
			$this->schedule_single( (int) $post_id, 10 );
		} else {
			$existing = $this->store->get_document( (int) $post_id );
			$this->store->delete_document( (int) $post_id );
			if ( $existing ) {
				$this->bump_version();
			}
		}
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public function on_delete_post( $post_id ) {
		$this->store->delete_document( (int) $post_id );
		$this->bump_version();
	}

	/**
	 * @param int $object_id Object ID.
	 */
	public function on_set_object_terms( $object_id ) {
		$post = get_post( $object_id );
		if ( $post instanceof WP_Post && $this->content->is_indexable( $post ) ) {
			$this->schedule_single( (int) $object_id, 15 );
		}
	}

	/**
	 * @param int $post_id Post ID.
	 * @param int $delay   Delay in seconds.
	 */
	public function schedule_single( $post_id, $delay = 10 ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		$args = array( $post_id );
		if ( wp_next_scheduled( 'onx_ask_index_post', $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, (int) $delay ), 'onx_ask_index_post', $args );
	}

	/**
	 * Index (or re-index) one post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Ignore the content hash.
	 * @return array<string,mixed>
	 */
	public function index_post( $post_id, $force = false ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->content->is_indexable( $post ) ) {
			$existing = $this->store->get_document( $post_id );
			$this->store->delete_document( $post_id );
			if ( $existing ) {
				$this->bump_version();
			}
			return array( 'post_id' => $post_id, 'deleted' => true );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 );
		}

		$document = $this->content->build_document( $post );
		if ( is_wp_error( $document ) ) {
			$this->record_error( $post_id, $document->get_error_message() );
			return array( 'post_id' => $post_id, 'error' => $document->get_error_message() );
		}

		$embedding_config = $this->settings->embedding_config();
		$chunk_settings   = (int) $this->settings->get( 'chunk_size', 1800 ) . ':' . (float) $this->settings->get( 'chunk_overlap', 0.15 ) . ':' . (int) $this->settings->get( 'max_chunks_per_doc', 80 );
		$hash             = md5( $document['content_hash'] . '|' . $this->embeddings->signature() . '|' . $chunk_settings );

		$existing = $this->store->get_document( $post_id );
		if ( ! $force && $existing && isset( $existing['content_hash'] ) && $existing['content_hash'] === $hash && (int) $existing['chunk_count'] > 0 ) {
			// Only trust an existing row when its vectors are usable. A lexical-only
			// row is retried as soon as an embedding key is configured.
			$embedded_ok = isset( $existing['embedding_status'] ) && 'ok' === $existing['embedding_status'];
			if ( $embedded_ok || ! $this->embedding_expected() ) {
				return array( 'post_id' => $post_id, 'skipped' => true );
			}
		}

		$chunks = $this->chunker->chunk( $document['text'], $document );
		if ( empty( $chunks ) ) {
			$this->store->delete_document( $post_id );
			$this->bump_version();
			return array( 'post_id' => $post_id, 'empty' => true );
		}

		$embedding_texts = array();
		foreach ( $chunks as $chunk ) {
			$embedding_texts[] = $this->chunker->embedding_text( $chunk, $document );
		}

		$vectors = $this->embeddings->embed_texts( $embedding_texts );
		$warning = '';
		if ( is_wp_error( $vectors ) ) {
			// Keep the lexical half of hybrid retrieval alive even when the
			// provider is down or the key has no quota. A forced reindex retries.
			$warning = $vectors->get_error_message();
			$vectors = array();
		}

		$dim = 0;
		if ( ! empty( $vectors ) && isset( $vectors[0] ) && is_array( $vectors[0] ) ) {
			$dim = count( $vectors[0] );
		}

		$result = $this->store->replace_document(
			$document,
			$chunks,
			$vectors,
			$embedding_config['provider'],
			$embedding_config['model'],
			$dim
		);

		if ( is_wp_error( $result ) ) {
			$this->record_error( $post_id, $result->get_error_message() );
			return array( 'post_id' => $post_id, 'error' => $result->get_error_message() );
		}

		// Persist the hash that was actually indexed (not the raw content hash).
		global $wpdb;
		$wpdb->update(
			$this->store->documents_table(),
			array( 'content_hash' => $hash ),
			array( 'post_id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);

		update_option( self::LAST_ERROR_OPTION, $warning, false );
		update_option( 'onx_ask_last_index_at', current_time( 'mysql', true ), false );
		$this->bump_version();

		/**
		 * Fires after a single document has been indexed.
		 *
		 * @param int                 $post_id Post ID.
		 * @param array<string,mixed> $result  Result.
		 */
		do_action( 'onx_ask_post_indexed', $post_id, array( 'chunks' => count( $chunks ), 'warning' => $warning ) );

		return array(
			'post_id' => $post_id,
			'chunks'  => count( $chunks ),
			'embedded' => ! empty( $vectors ),
			'warning' => $warning,
		);
	}

	/**
	 * Queue a full / recent / single reindex.
	 *
	 * @param string              $mode all|recent|single.
	 * @param array<string,mixed> $args post_id, days.
	 * @return int Number of posts queued.
	 */
	public function queue_reindex( $mode = 'all', array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'post_id' => 0,
				'days'    => 7,
			)
		);

		if ( 'single' === $mode && (int) $args['post_id'] <= 0 ) {
			return 0;
		}

		$this->cancel_queue();

		$query = array(
			'orderby' => 'modified',
			'order'   => 'ASC',
		);

		if ( 'single' === $mode ) {
			$query['include_ids'] = array( (int) $args['post_id'] );
		} elseif ( 'recent' === $mode ) {
			$query['since_gmt'] = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $args['days'] ) * DAY_IN_SECONDS ) );
		}

		$ids = $this->content->get_indexable_post_ids( $query );
		if ( empty( $ids ) ) {
			if ( 'single' === $mode ) {
				// The post is no longer indexable (trashed, unpublished, excluded).
				$post_id  = (int) $args['post_id'];
				$existing = $this->store->get_document( $post_id );
				$this->store->delete_document( $post_id );
				if ( $existing ) {
					$this->bump_version();
				}
			}
			update_option( self::RUNNING_OPTION, false, false );
			update_option( self::TOTAL_OPTION, 0, false );
			update_option( self::DONE_OPTION, 0, false );
			return 0;
		}

		update_option( self::QUEUE_OPTION, implode( ',', array_map( 'absint', $ids ) ), false );
		update_option( self::TOTAL_OPTION, count( $ids ), false );
		update_option( self::DONE_OPTION, 0, false );
		update_option( self::RUNNING_OPTION, true, false );
		update_option( self::STARTED_OPTION, current_time( 'mysql', true ), false );
		update_option( self::ERRORS_OPTION, array(), false );
		$this->bump_version();

		wp_clear_scheduled_hook( 'onx_ask_index_batch' );
		wp_schedule_single_event( time() + 1, 'onx_ask_index_batch' );

		return count( $ids );
	}

	/**
	 * Process the next slice of the queue.
	 */
	public function process_batch() {
		if ( ! get_option( self::RUNNING_OPTION ) ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$queue = (string) get_option( self::QUEUE_OPTION, '' );
		$ids   = array_values( array_filter( array_map( 'absint', explode( ',', $queue ) ) ) );

		if ( empty( $ids ) ) {
			$this->finish_queue();
			return;
		}

		/**
		 * Number of documents indexed per cron tick.
		 *
		 * @param int $batch_size Batch size.
		 */
		$batch_size = max( 1, (int) apply_filters( 'onx_ask_index_batch_size', 5 ) );
		$started    = microtime( true );
		$processed  = 0;
		$errors     = (array) get_option( self::ERRORS_OPTION, array() );

		while ( ! empty( $ids ) && $processed < $batch_size ) {
			$post_id = (int) array_shift( $ids );
			try {
				$result = $this->index_post( $post_id, true );
			} catch ( \Throwable $e ) {
				$result = array( 'post_id' => $post_id, 'error' => $e->getMessage() );
			}
			$processed++;

			if ( ! empty( $result['error'] ) ) {
				$errors[] = array(
					'post_id' => $post_id,
					'message' => (string) $result['error'],
					'time'    => current_time( 'mysql', true ),
				);
				$errors   = array_slice( $errors, -10 );
			}

			if ( ( microtime( true ) - $started ) > 25 ) {
				break;
			}
		}

		$done = (int) get_option( self::DONE_OPTION, 0 ) + $processed;
		update_option( self::DONE_OPTION, $done, false );
		update_option( self::QUEUE_OPTION, implode( ',', $ids ), false );
		update_option( self::ERRORS_OPTION, $errors, false );

		do_action( 'onx_ask_index_progress', $done, (int) get_option( self::TOTAL_OPTION, 0 ) );

		if ( empty( $ids ) ) {
			$this->finish_queue();
			return;
		}

		wp_clear_scheduled_hook( 'onx_ask_index_batch' );
		wp_schedule_single_event( time() + 5, 'onx_ask_index_batch' );
	}

	/**
	 * Daily incremental sync: reindex modified posts and remove orphans.
	 */
	public function daily_sync() {
		$since = (string) get_option( self::LAST_SYNC_OPTION, '' );
		if ( '' === $since ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
		}

		$ids = $this->content->get_indexable_post_ids(
			array(
				'since_gmt' => $since,
				'limit'     => 100,
				'orderby'   => 'modified',
				'order'     => 'ASC',
			)
		);

		foreach ( $ids as $post_id ) {
			try {
				$this->index_post( $post_id, false );
			} catch ( \Throwable $e ) {
				$this->record_error( (int) $post_id, $e->getMessage() );
			}
		}

		$this->cleanup_orphans( 200 );
		update_option( self::LAST_SYNC_OPTION, current_time( 'mysql', true ), false );
	}

	/**
	 * Remove documents whose post is gone, unpublished or excluded.
	 *
	 * @param int $limit Max documents to inspect.
	 */
	public function cleanup_orphans( $limit = 200 ) {
		global $wpdb;

		$docs = $wpdb->get_col(
			$wpdb->prepare( 'SELECT post_id FROM ' . $this->store->documents_table() . ' ORDER BY id ASC LIMIT %d', max( 1, (int) $limit ) )
		);

		foreach ( (array) $docs as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post || ! $this->content->is_indexable( $post ) ) {
				$this->store->delete_document( (int) $post_id );
			}
		}
	}

	public function cancel_queue() {
		update_option( self::QUEUE_OPTION, '', false );
		update_option( self::RUNNING_OPTION, false, false );
		wp_clear_scheduled_hook( 'onx_ask_index_batch' );
	}

	public function flush() {
		$this->cancel_queue();
		$this->store->flush();
		update_option( self::TOTAL_OPTION, 0, false );
		update_option( self::DONE_OPTION, 0, false );
		update_option( self::ERRORS_OPTION, array(), false );
		update_option( self::LAST_ERROR_OPTION, '', false );
	}

	private function finish_queue() {
		update_option( self::QUEUE_OPTION, '', false );
		update_option( self::RUNNING_OPTION, false, false );
		update_option( 'onx_ask_last_complete', current_time( 'mysql', true ), false );
		wp_clear_scheduled_hook( 'onx_ask_index_batch' );
		do_action( 'onx_ask_index_complete', (int) get_option( self::TOTAL_OPTION, 0 ) );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $message Error.
	 */
	private function record_error( $post_id, $message ) {
		$errors   = (array) get_option( self::ERRORS_OPTION, array() );
		$errors[] = array(
			'post_id' => (int) $post_id,
			'message' => (string) $message,
			'time'    => current_time( 'mysql', true ),
		);
		update_option( self::ERRORS_OPTION, array_slice( $errors, -10 ), false );
		update_option( self::LAST_ERROR_OPTION, (string) $message, false );
	}

	/**
	 * Whether the configured embedding provider can produce vectors right now.
	 *
	 * @return bool
	 */
	private function embedding_expected() {
		$config = $this->settings->embedding_config();
		if ( 'local' === $config['provider'] ) {
			return true;
		}
		return '' !== $this->settings->api_key( $config['provider'] );
	}

	private function bump_version() {
		update_option( 'onx_ask_index_version', time() . '-' . wp_rand( 1000, 9999 ), false );
	}

	/**
	 * Live status for the admin UI and REST endpoint.
	 *
	 * @return array<string,mixed>
	 */
	public function status() {
		$total     = (int) get_option( self::TOTAL_OPTION, 0 );
		$done      = (int) get_option( self::DONE_OPTION, 0 );
		$remaining = (string) get_option( self::QUEUE_OPTION, '' );
		$pending   = '' === $remaining ? 0 : count( array_filter( explode( ',', $remaining ) ) );
		$stats     = $this->store->stats();

		return array(
			'running'        => (bool) get_option( self::RUNNING_OPTION, false ),
			'queued_total'   => $total,
			'queued_done'    => $done,
			'queued_pending' => $pending,
			'percent'        => $total > 0 ? min( 100, (int) round( ( $done / $total ) * 100 ) ) : 0,
			'started_at'     => (string) get_option( self::STARTED_OPTION, '' ),
			'last_complete'  => (string) get_option( 'onx_ask_last_complete', '' ),
			'last_index_at'  => (string) get_option( 'onx_ask_last_index_at', '' ),
			'last_sync_at'   => (string) get_option( self::LAST_SYNC_OPTION, '' ),
			'last_error'     => (string) get_option( self::LAST_ERROR_OPTION, '' ),
			'errors'         => (array) get_option( self::ERRORS_OPTION, array() ),
			'index_version'  => (string) get_option( 'onx_ask_index_version', '' ),
			'indexable_posts'=> $this->content->count_indexable_posts(),
			'indexed_posts'  => (int) $stats['documents'],
			'chunks'         => (int) $stats['chunks'],
			'embedded_chunks'=> (int) $stats['embedded_chunks'],
			'configured'     => $this->settings->is_configured(),
			'post_types'     => $this->settings->post_types(),
		);
	}
}
