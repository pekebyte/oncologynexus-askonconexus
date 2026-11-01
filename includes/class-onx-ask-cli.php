<?php
/**
 * WP-CLI commands.
 *
 *   wp onx-ask index [--post_id=<id>] [--force] [--days=<n>]
 *   wp onx-ask status
 *   wp onx-ask ask "question"
 *   wp onx-ask flush [--yes]
 *
 * `index` without --post_id queues a full reindex and then drains the queue
 * synchronously, which is exactly what you want on a deploy or a maintenance
 * window (no waiting for WP-Cron).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class OncologyNexus_Ask_CLI {

	/** @var OncologyNexus_Ask_Service */
	private $service;

	/** @var OncologyNexus_Ask_Indexer */
	private $indexer;

	/** @var OncologyNexus_Ask_Vector_Store */
	private $store;

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	public function __construct(
		OncologyNexus_Ask_Service $service,
		OncologyNexus_Ask_Indexer $indexer,
		OncologyNexus_Ask_Vector_Store $store,
		OncologyNexus_Ask_Settings $settings
	) {
		$this->service  = $service;
		$this->indexer  = $indexer;
		$this->store    = $store;
		$this->settings = $settings;
	}

	/**
	 * Index the site's content.
	 *
	 * ## OPTIONS
	 *
	 * [--post_id=<id>]
	 * : Index a single post instead of the whole corpus.
	 *
	 * [--force]
	 * : Re-embed even when the stored hash matches.
	 *
	 * [--days=<days>]
	 * : Only reindex content modified in the last N days.
	 * ---
	 * default: 0
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp onx-ask index
	 *     wp onx-ask index --post_id=123 --force
	 *     wp onx-ask index --days=7
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,mixed>  $assoc_args Associative args.
	 */
	public function index( $args, $assoc_args ) {
		$post_id = isset( $assoc_args['post_id'] ) ? absint( $assoc_args['post_id'] ) : 0;
		$force   = isset( $assoc_args['force'] ) && (bool) $assoc_args['force'];
		$days    = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : 0;

		if ( $post_id > 0 ) {
			WP_CLI::log( sprintf( 'Indexing post #%d…', $post_id ) );
			$result = $this->indexer->index_post( $post_id, $force );

			if ( ! empty( $result['error'] ) ) {
				WP_CLI::error( (string) $result['error'] );
			}
			if ( ! empty( $result['deleted'] ) ) {
				WP_CLI::success( 'The post is no longer indexable and was removed from the index.' );
				return;
			}
			if ( ! empty( $result['skipped'] ) ) {
				WP_CLI::success( 'Already up to date (use --force to re-embed).' );
				return;
			}

			WP_CLI::success(
				sprintf(
					'Indexed %d chunk(s)%s.',
					isset( $result['chunks'] ) ? (int) $result['chunks'] : 0,
					! empty( $result['warning'] ) ? ' — warning: ' . $result['warning'] : ''
				)
			);
			return;
		}

		if ( ! $this->settings->is_configured() ) {
			WP_CLI::warning( 'No chat / embedding provider is fully configured. Indexing will fall back to lexical retrieval.' );
		}

		$mode  = $days > 0 ? 'recent' : 'all';
		$count = $this->indexer->queue_reindex(
			$mode,
			array(
				'days' => $days > 0 ? $days : 7,
			)
		);

		if ( 0 === $count ) {
			WP_CLI::success( 'Nothing to index.' );
			return;
		}

		WP_CLI::log( sprintf( 'Queued %d post(s). Draining the queue…', $count ) );

		$guard = 0;
		while ( (bool) get_option( OncologyNexus_Ask_Indexer::RUNNING_OPTION, false ) ) {
			$this->indexer->process_batch();
			$guard++;

			$done  = (int) get_option( OncologyNexus_Ask_Indexer::DONE_OPTION, 0 );
			$total = (int) get_option( OncologyNexus_Ask_Indexer::TOTAL_OPTION, 0 );
			WP_CLI::log( sprintf( '  %d / %d indexed', $done, $total ) );

			// Safety valve: 500 batches × 5 posts is far beyond any real corpus.
			if ( $guard > 500 ) {
				WP_CLI::warning( 'Stopped after 500 batches. Run `wp onx-ask index` again to finish.' );
				break;
			}
		}

		$stats = $this->store->stats();
		WP_CLI::success(
			sprintf(
				'Done. %d document(s), %d chunk(s), %d embedded.',
				(int) $stats['documents'],
				(int) $stats['chunks'],
				(int) $stats['embedded_chunks']
			)
		);

		if ( ! empty( $stats['missing_vectors'] ) ) {
			WP_CLI::warning(
				sprintf(
					'%d chunk(s) have no embedding (lexical-only). Check the provider key and run `wp onx-ask index --force`.',
					(int) $stats['missing_vectors']
				)
			);
		}
	}

	/**
	 * Show index and provider status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp onx-ask status
	 */
	public function status() {
		$status = $this->indexer->status();
		$stats  = $this->store->stats();
		$chat   = $this->settings->chat_config();
		$embed  = $this->settings->embedding_config();

		$rows = array(
			array(
				'key'   => 'Enabled',
				'value' => $this->settings->is_enabled() ? 'yes' : 'no',
			),
			array(
				'key'   => 'Configured',
				'value' => $this->settings->is_configured() ? 'yes' : 'no',
			),
			array(
				'key'   => 'Chat',
				'value' => $chat['provider'] . ' / ' . $chat['model'],
			),
			array(
				'key'   => 'Embeddings',
				'value' => $embed['provider'] . ' / ' . $embed['model'],
			),
			array(
				'key'   => 'Documents',
				'value' => (string) (int) $stats['documents'],
			),
			array(
				'key'   => 'Chunks',
				'value' => (string) (int) $stats['chunks'],
			),
			array(
				'key'   => 'Embedded chunks',
				'value' => (string) (int) $stats['embedded_chunks'],
			),
			array(
				'key'   => 'Missing vectors',
				'value' => (string) (int) $stats['missing_vectors'],
			),
			array(
				'key'   => 'Indexable posts',
				'value' => (string) (int) $status['indexable_posts'],
			),
			array(
				'key'   => 'Indexed posts',
				'value' => (string) (int) $status['indexed_posts'],
			),
			array(
				'key'   => 'Running',
				'value' => $status['running'] ? sprintf( '%d / %d', $status['queued_done'], $status['queued_total'] ) : 'no',
			),
			array(
				'key'   => 'Last indexed',
				'value' => $status['last_index_at'] ? $status['last_index_at'] . ' UTC' : 'never',
			),
			array(
				'key'   => 'Last complete',
				'value' => $status['last_complete'] ? $status['last_complete'] . ' UTC' : 'never',
			),
			array(
				'key'   => 'Index version',
				'value' => $status['index_version'],
			),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );

		if ( ! empty( $status['last_error'] ) ) {
			WP_CLI::warning( 'Last indexing warning: ' . $status['last_error'] );
		}
		if ( ! empty( $status['errors'] ) ) {
			WP_CLI::warning( 'Recent indexing errors:' );
			foreach ( array_slice( array_reverse( $status['errors'] ), 0, 5 ) as $error ) {
				WP_CLI::log( sprintf( '  #%d %s (%s UTC)', (int) $error['post_id'], $error['message'], $error['time'] ) );
			}
		}
	}

	/**
	 * Ask a question through the live RAG pipeline.
	 *
	 * ## OPTIONS
	 *
	 * <question>
	 * : The question to ask.
	 *
	 * [--post_types=<types>]
	 * : Comma-separated post types to search.
	 *
	 * [--json]
	 * : Print the full JSON payload.
	 *
	 * ## EXAMPLES
	 *
	 *     wp onx-ask ask "What are the latest KRAS G12C advances?"
	 *
	 * @param array<int,string>   $args       Positional args.
	 * @param array<string,mixed> $assoc_args Associative args.
	 */
	public function ask( $args, $assoc_args ) {
		$question = isset( $args[0] ) ? trim( (string) $args[0] ) : '';
		if ( '' === $question ) {
			WP_CLI::error( 'Please provide a question.' );
		}

		$post_types = array();
		if ( ! empty( $assoc_args['post_types'] ) ) {
			$post_types = array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['post_types'] ) ) );
		}

		$result = $this->service->ask(
			array(
				'question'   => $question,
				'post_types' => $post_types,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $assoc_args['json'] ) ) {
			WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::line( (string) $result['answer'] );
		WP_CLI::log( '' );

		if ( ! empty( $result['sources'] ) ) {
			WP_CLI::log( 'Sources:' );
			foreach ( $result['sources'] as $source ) {
				WP_CLI::line(
					sprintf(
						'  [%d] %s — %s',
						(int) $source['index'],
						$source['title'],
						$source['url']
					)
				);
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				'Provider: %s / %s · cached: %s · index ready: %s',
				$result['provider'] ? $result['provider'] : 'fallback',
				$result['model'] ? $result['model'] : 'n/a',
				! empty( $result['cached'] ) ? 'yes' : 'no',
				! empty( $result['index_ready'] ) ? 'yes' : 'no'
			)
		);
	}

	/**
	 * Delete every indexed document and chunk.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp onx-ask flush --yes
	 *
	 * @param array<int,string>   $args       Positional args.
	 * @param array<string,mixed> $assoc_args Associative args.
	 */
	public function flush( $args, $assoc_args ) {
		WP_CLI::confirm( 'This deletes every indexed document and chunk. Continue?', $assoc_args );
		$this->indexer->flush();
		WP_CLI::success( 'Index flushed. Run `wp onx-ask index` to rebuild it.' );
	}
}
