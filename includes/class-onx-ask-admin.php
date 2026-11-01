<?php
/**
 * Admin UI: connection, retrieval tuning, content selection, index control,
 * analytics and a live playground.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OncologyNexus_Ask_Admin {

	/** @var OncologyNexus_Ask_Settings */
	private $settings;

	/** @var OncologyNexus_Ask_Indexer */
	private $indexer;

	/** @var OncologyNexus_Ask_Vector_Store */
	private $store;

	/** @var OncologyNexus_Ask_LLM */
	private $llm;

	/** @var OncologyNexus_Ask_Embeddings */
	private $embeddings;

	public function __construct(
		OncologyNexus_Ask_Settings $settings,
		OncologyNexus_Ask_Indexer $indexer,
		OncologyNexus_Ask_Vector_Store $store,
		OncologyNexus_Ask_LLM $llm,
		OncologyNexus_Ask_Embeddings $embeddings
	) {
		$this->settings   = $settings;
		$this->indexer    = $indexer;
		$this->store      = $store;
		$this->llm        = $llm;
		$this->embeddings = $embeddings;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ONX_ASK_FILE ), array( $this, 'action_links' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Ask OncoNexus', 'onx-ask' ),
			__( 'Ask OncoNexus', 'onx-ask' ),
			'manage_options',
			'onx-ask',
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			81
		);
	}

	public function register_settings() {
		register_setting(
			'onx_ask_group',
			ONX_ASK_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => $this->settings->defaults(),
			)
		);
	}

	/**
	 * @param string $hook Current admin page.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_onx-ask' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'onx-ask-admin', ONX_ASK_URL . 'assets/admin.css', array(), ONX_ASK_VERSION );
		wp_enqueue_script( 'onx-ask-admin', ONX_ASK_URL . 'assets/admin.js', array(), ONX_ASK_VERSION, true );
		wp_localize_script(
			'onx-ask-admin',
			'OnxAskAdmin',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'oncologynexus/v1/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'configured'  => (bool) $this->settings->is_configured(),
				'indexReady'  => (int) $this->store->stats()['chunks'] > 0,
				'strings'     => array(
					'confirmFlush' => __( 'This deletes every indexed chunk. You will need to reindex before asking questions. Continue?', 'onx-ask' ),
					'working'      => __( 'Working…', 'onx-ask' ),
					'error'        => __( 'Something went wrong.', 'onx-ask' ),
					'noAnswer'     => __( 'The assistant returned no answer.', 'onx-ask' ),
				),
			)
		);
	}

	/**
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=onx-ask' ) ) . '">' . esc_html__( 'Settings', 'onx-ask' ) . '</a>';
		return $links;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this plugin.', 'onx-ask' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $tab, array( 'settings', 'index', 'playground' ), true ) ) {
			$tab = 'settings';
		}

		$configured = $this->settings->is_configured();
		$stats      = $this->store->stats();
		$ready      = (int) $stats['chunks'] > 0;
		?>
		<div class="wrap onx-ask-wrap">
			<div class="onx-ask-header">
				<span class="dashicons dashicons-format-chat"></span>
				<div class="onx-ask-header-text">
					<h1><?php esc_html_e( 'Ask OncoNexus', 'onx-ask' ); ?></h1>
					<p><?php esc_html_e( 'RAG assistant for the Next.js frontend — every article, news item, doctor, podcast, video, conference page and glossary term.', 'onx-ask' ); ?></p>
				</div>
				<span class="onx-ask-pill <?php echo $configured ? 'is-ok' : 'is-warn'; ?>">
					<?php echo $configured ? esc_html__( 'Provider connected', 'onx-ask' ) : esc_html__( 'Provider not configured', 'onx-ask' ); ?>
				</span>
				<span class="onx-ask-pill <?php echo $ready ? 'is-ok' : 'is-warn'; ?>">
					<?php
					echo $ready
						? esc_html( sprintf( /* translators: %d: chunk count. */ __( '%d chunks indexed', 'onx-ask' ), (int) $stats['chunks'] ) )
						: esc_html__( 'Index empty', 'onx-ask' );
					?>
				</span>
			</div>

			<nav class="onx-ask-tabs" aria-label="<?php esc_attr_e( 'Ask OncoNexus tabs', 'onx-ask' ); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=onx-ask&tab=settings' ) ); ?>" class="onx-ask-tab <?php echo 'settings' === $tab ? 'is-active' : ''; ?>">
					<span class="dashicons dashicons-admin-generic"></span>
					<?php esc_html_e( 'Settings', 'onx-ask' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=onx-ask&tab=index' ) ); ?>" class="onx-ask-tab <?php echo 'index' === $tab ? 'is-active' : ''; ?>">
					<span class="dashicons dashicons-database"></span>
					<?php esc_html_e( 'Index & logs', 'onx-ask' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=onx-ask&tab=playground' ) ); ?>" class="onx-ask-tab <?php echo 'playground' === $tab ? 'is-active' : ''; ?>">
					<span class="dashicons dashicons-testimonial"></span>
					<?php esc_html_e( 'Playground', 'onx-ask' ); ?>
				</a>
			</nav>

			<div class="onx-ask-body">
				<?php
				if ( 'index' === $tab ) {
					$this->render_index_tab();
				} elseif ( 'playground' === $tab ) {
					$this->render_playground_tab();
				} else {
					$this->render_settings_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	private function render_settings_tab() {
		$chat      = $this->settings->chat_config();
		$embedding = $this->settings->embedding_config();
		?>
		<?php settings_errors(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="onx-ask-form">
			<?php settings_fields( 'onx_ask_group' ); ?>

			<div class="onx-ask-card">
				<h2><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e( 'Connection', 'onx-ask' ); ?></h2>
				<p class="onx-ask-muted"><?php esc_html_e( 'The plugin calls the provider from WordPress, so API keys are never exposed to the browser. Define keys in wp-config.php for the safest setup.', 'onx-ask' ); ?></p>

				<div class="onx-ask-grid">
					<div class="onx-ask-field">
						<label class="onx-ask-switch">
							<input type="hidden" name="<?php echo esc_attr( $this->field_name( 'enabled' ) ); ?>" value="0">
							<input type="checkbox" name="<?php echo esc_attr( $this->field_name( 'enabled' ) ); ?>" value="1" <?php checked( 1, (int) $this->settings->get( 'enabled', true ) ); ?>>
							<span><?php esc_html_e( 'Enable the assistant', 'onx-ask' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Turning this off makes the public endpoint return a 503 and hides nothing in the frontend by itself.', 'onx-ask' ); ?></p>
					</div>

					<div class="onx-ask-field">
						<label for="onx-brand"><?php esc_html_e( 'Assistant name', 'onx-ask' ); ?></label>
						<input type="text" id="onx-brand" name="<?php echo esc_attr( $this->field_name( 'brand_name' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'brand_name' ) ); ?>" class="regular-text">
					</div>
				</div>

				<div class="onx-ask-grid">
					<div class="onx-ask-field">
						<label for="onx-chat-provider"><?php esc_html_e( 'Chat provider', 'onx-ask' ); ?></label>
						<select id="onx-chat-provider" name="<?php echo esc_attr( $this->field_name( 'chat_provider' ) ); ?>">
							<option value="openai" <?php selected( 'openai', $chat['provider'] ); ?>>OpenAI</option>
							<option value="anthropic" <?php selected( 'anthropic', $chat['provider'] ); ?>>Anthropic Claude</option>
							<option value="gemini" <?php selected( 'gemini', $chat['provider'] ); ?>>Google Gemini</option>
						</select>
					</div>

					<div class="onx-ask-field">
						<label for="onx-chat-model"><?php esc_html_e( 'Chat model', 'onx-ask' ); ?></label>
						<input type="text" id="onx-chat-model" list="onx-chat-models" name="<?php echo esc_attr( $this->field_name( 'chat_model' ) ); ?>" value="<?php echo esc_attr( $chat['model'] ); ?>" class="regular-text">
						<datalist id="onx-chat-models">
							<?php foreach ( array_unique( array_merge( OncologyNexus_Ask_LLM::model_suggestions( 'openai' ), OncologyNexus_Ask_LLM::model_suggestions( 'anthropic' ), OncologyNexus_Ask_LLM::model_suggestions( 'gemini' ) ) ) as $model ) : ?>
								<option value="<?php echo esc_attr( $model ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
					</div>

					<div class="onx-ask-field">
						<label for="onx-temperature"><?php esc_html_e( 'Temperature', 'onx-ask' ); ?></label>
						<input type="number" id="onx-temperature" step="0.05" min="0" max="2" name="<?php echo esc_attr( $this->field_name( 'temperature' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'temperature' ) ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( '0.1–0.3 keeps answers grounded and repeatable.', 'onx-ask' ); ?></p>
					</div>

					<div class="onx-ask-field">
						<label for="onx-max-tokens"><?php esc_html_e( 'Max output tokens', 'onx-ask' ); ?></label>
						<input type="number" id="onx-max-tokens" min="64" max="8192" name="<?php echo esc_attr( $this->field_name( 'max_output_tokens' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'max_output_tokens' ) ); ?>" class="small-text">
					</div>
				</div>

				<div class="onx-ask-grid">
					<div class="onx-ask-field">
						<label for="onx-embedding-provider"><?php esc_html_e( 'Embedding provider', 'onx-ask' ); ?></label>
						<select id="onx-embedding-provider" name="<?php echo esc_attr( $this->field_name( 'embedding_provider' ) ); ?>">
							<option value="openai" <?php selected( 'openai', $embedding['provider'] ); ?>>OpenAI</option>
							<option value="gemini" <?php selected( 'gemini', $embedding['provider'] ); ?>>Google Gemini</option>
							<option value="local" <?php selected( 'local', $embedding['provider'] ); ?>><?php esc_html_e( 'Local (no API key)', 'onx-ask' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Claude has no embeddings API. Pair Claude with OpenAI, Gemini or local embeddings.', 'onx-ask' ); ?></p>
					</div>

					<div class="onx-ask-field">
						<label for="onx-embedding-model"><?php esc_html_e( 'Embedding model', 'onx-ask' ); ?></label>
						<input type="text" id="onx-embedding-model" list="onx-embedding-models" name="<?php echo esc_attr( $this->field_name( 'embedding_model' ) ); ?>" value="<?php echo esc_attr( $embedding['model'] ); ?>" class="regular-text">
						<datalist id="onx-embedding-models">
							<?php foreach ( array_unique( array_merge( OncologyNexus_Ask_LLM::model_suggestions( 'embedding-openai' ), OncologyNexus_Ask_LLM::model_suggestions( 'embedding-gemini' ) ) ) as $model ) : ?>
								<option value="<?php echo esc_attr( $model ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
					</div>

					<div class="onx-ask-field">
						<label for="onx-embedding-dim"><?php esc_html_e( 'Embedding dimensions', 'onx-ask' ); ?></label>
						<input type="number" id="onx-embedding-dim" min="0" max="4096" name="<?php echo esc_attr( $this->field_name( 'embedding_dimensions' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'embedding_dimensions' ) ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( '0 = provider default. For local embeddings 0 means 512. Changing this requires a reindex.', 'onx-ask' ); ?></p>
					</div>
				</div>

				<h3><?php esc_html_e( 'API keys', 'onx-ask' ); ?></h3>
				<div class="onx-ask-keys">
					<?php
					$this->render_key_field( 'openai', 'OpenAI' );
					$this->render_key_field( 'anthropic', 'Anthropic Claude' );
					$this->render_key_field( 'gemini', 'Google Gemini' );
					?>
				</div>

				<div class="onx-ask-actions">
					<button type="button" class="button" data-onx-action="test" data-target="chat"><?php esc_html_e( 'Test chat provider', 'onx-ask' ); ?></button>
					<button type="button" class="button" data-onx-action="test" data-target="embedding"><?php esc_html_e( 'Test embeddings', 'onx-ask' ); ?></button>
					<span class="onx-ask-inline-result" data-onx-result></span>
				</div>

				<details class="onx-ask-advanced">
					<summary><?php esc_html_e( 'Advanced: provider base URLs (proxies / OpenAI-compatible gateways)', 'onx-ask' ); ?></summary>
					<div class="onx-ask-grid">
						<div class="onx-ask-field">
							<label for="onx-openai-base"><?php esc_html_e( 'OpenAI base URL', 'onx-ask' ); ?></label>
							<input type="url" id="onx-openai-base" name="<?php echo esc_attr( $this->field_name( 'openai_base_url' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'openai_base_url' ) ); ?>" class="regular-text">
						</div>
						<div class="onx-ask-field">
							<label for="onx-anthropic-base"><?php esc_html_e( 'Anthropic base URL', 'onx-ask' ); ?></label>
							<input type="url" id="onx-anthropic-base" name="<?php echo esc_attr( $this->field_name( 'anthropic_base_url' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'anthropic_base_url' ) ); ?>" class="regular-text">
						</div>
						<div class="onx-ask-field">
							<label for="onx-gemini-base"><?php esc_html_e( 'Gemini base URL', 'onx-ask' ); ?></label>
							<input type="url" id="onx-gemini-base" name="<?php echo esc_attr( $this->field_name( 'gemini_base_url' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'gemini_base_url' ) ); ?>" class="regular-text">
						</div>
					</div>
				</details>
			</div>

			<div class="onx-ask-card">
				<h2><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Retrieval', 'onx-ask' ); ?></h2>
				<p class="onx-ask-muted"><?php esc_html_e( 'Hybrid retrieval combines dense cosine similarity with BM25, then diversifies passages with MMR. Defaults work well for most editorial sites.', 'onx-ask' ); ?></p>

				<div class="onx-ask-grid onx-ask-grid-4">
					<?php
					$this->render_number( 'chunk_size', __( 'Chunk size (chars)', 'onx-ask' ), 400, 8000, __( '~1,800 chars ≈ 450 tokens.', 'onx-ask' ) );
					$this->render_number( 'chunk_overlap', __( 'Chunk overlap (0–0.5)', 'onx-ask' ), 0, 0.5, __( '0.15 keeps sentences that straddle a boundary.', 'onx-ask' ), '0.05' );
					$this->render_number( 'max_chunks_per_doc', __( 'Max chunks / document', 'onx-ask' ), 5, 400, __( 'Long reports are capped to control cost.', 'onx-ask' ) );
					$this->render_number( 'top_k', __( 'Candidate pool', 'onx-ask' ), 5, 200, __( 'Chunks kept after fusion, before MMR.', 'onx-ask' ) );
					$this->render_number( 'context_chunks', __( 'Context passages', 'onx-ask' ), 1, 20, __( 'Passages sent to the LLM.', 'onx-ask' ) );
					$this->render_number( 'max_per_doc', __( 'Max chunks per source', 'onx-ask' ), 1, 10, __( 'Diversity guard so one page cannot dominate.', 'onx-ask' ) );
					$this->render_number( 'max_context_chars', __( 'Max context chars', 'onx-ask' ), 1000, 60000, __( 'Token budget for retrieved text.', 'onx-ask' ) );
					$this->render_number( 'max_scan_chunks', __( 'Max scanned chunks', 'onx-ask' ), 200, 50000, __( 'Raise for large corpora; see README for scaling.', 'onx-ask' ) );
					$this->render_number( 'dense_weight', __( 'Dense weight', 'onx-ask' ), 0, 1, __( 'Embedding similarity weight.', 'onx-ask' ), '0.05' );
					$this->render_number( 'lexical_weight', __( 'Lexical (BM25) weight', 'onx-ask' ), 0, 1, __( 'Exact-term weight.', 'onx-ask' ), '0.05' );
					$this->render_number( 'recency_weight', __( 'Recency weight', 'onx-ask' ), 0, 1, __( 'Slight boost for recently updated pages.', 'onx-ask' ), '0.01' );
					$this->render_number( 'mmr_lambda', __( 'MMR lambda', 'onx-ask' ), 0, 1, __( 'Higher = more relevance, lower = more diversity.', 'onx-ask' ), '0.05' );
					?>
				</div>

				<div class="onx-ask-grid">
					<div class="onx-ask-field">
						<label class="onx-ask-switch">
							<input type="hidden" name="<?php echo esc_attr( $this->field_name( 'enable_query_rewrite' ) ); ?>" value="0">
							<input type="checkbox" name="<?php echo esc_attr( $this->field_name( 'enable_query_rewrite' ) ); ?>" value="1" <?php checked( 1, (int) $this->settings->get( 'enable_query_rewrite', true ) ); ?>>
							<span><?php esc_html_e( 'Conversation-aware query rewrite', 'onx-ask' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'One small LLM call turns follow-ups into standalone search queries. Disable to save latency/cost.', 'onx-ask' ); ?></p>
					</div>
					<div class="onx-ask-field">
						<label class="onx-ask-switch">
							<input type="hidden" name="<?php echo esc_attr( $this->field_name( 'enable_answer_cache' ) ); ?>" value="0">
							<input type="checkbox" name="<?php echo esc_attr( $this->field_name( 'enable_answer_cache' ) ); ?>" value="1" <?php checked( 1, (int) $this->settings->get( 'enable_answer_cache', true ) ); ?>>
							<span><?php esc_html_e( 'Cache repeated answers', 'onx-ask' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'Invalidated automatically whenever the index changes.', 'onx-ask' ); ?></p>
					</div>
					<div class="onx-ask-field">
						<label for="onx-cache-ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'onx-ask' ); ?></label>
						<input type="number" id="onx-cache-ttl" min="0" max="86400" name="<?php echo esc_attr( $this->field_name( 'cache_ttl' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'cache_ttl' ) ); ?>" class="small-text">
					</div>
				</div>
			</div>

			<div class="onx-ask-card">
				<h2><span class="dashicons dashicons-admin-page"></span> <?php esc_html_e( 'Content & prompt', 'onx-ask' ); ?></h2>

				<div class="onx-ask-field">
					<label><?php esc_html_e( 'Indexed post types', 'onx-ask' ); ?></label>
					<div class="onx-ask-checks">
						<?php foreach ( $this->settings->available_post_types() as $name => $label ) : ?>
							<label class="onx-ask-check">
								<input type="checkbox" name="<?php echo esc_attr( $this->field_name( 'post_types' ) ); ?>[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $this->settings->post_types(), true ) ); ?>>
								<span><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $name ); ?></code></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Only published, non-password-protected posts are indexed. Attachments and internal templates are always skipped.', 'onx-ask' ); ?></p>
				</div>

				<div class="onx-ask-grid">
					<div class="onx-ask-field">
						<label for="onx-exclude"><?php esc_html_e( 'Excluded post IDs', 'onx-ask' ); ?></label>
						<input type="text" id="onx-exclude" name="<?php echo esc_attr( $this->field_name( 'exclude_ids' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'exclude_ids' ) ); ?>" class="regular-text" placeholder="12, 48, 193">
					</div>
					<div class="onx-ask-field">
						<label for="onx-max-question"><?php esc_html_e( 'Max question length', 'onx-ask' ); ?></label>
						<input type="number" id="onx-max-question" min="50" max="2000" name="<?php echo esc_attr( $this->field_name( 'max_question_chars' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'max_question_chars' ) ); ?>" class="small-text">
					</div>
				</div>

				<div class="onx-ask-field">
					<label for="onx-suggestions"><?php esc_html_e( 'Starter questions (one per line)', 'onx-ask' ); ?></label>
					<textarea id="onx-suggestions" name="<?php echo esc_attr( $this->field_name( 'suggested_questions' ) ); ?>" rows="4" class="large-text"><?php echo esc_textarea( (string) $this->settings->get( 'suggested_questions' ) ); ?></textarea>
				</div>

				<div class="onx-ask-field">
					<label for="onx-disclaimer"><?php esc_html_e( 'Disclaimer (shown under answers)', 'onx-ask' ); ?></label>
					<textarea id="onx-disclaimer" name="<?php echo esc_attr( $this->field_name( 'disclaimer' ) ); ?>" rows="2" class="large-text"><?php echo esc_textarea( (string) $this->settings->get( 'disclaimer' ) ); ?></textarea>
				</div>

				<div class="onx-ask-field">
					<label for="onx-system-prompt"><?php esc_html_e( 'System prompt override (optional)', 'onx-ask' ); ?></label>
					<textarea id="onx-system-prompt" name="<?php echo esc_attr( $this->field_name( 'system_prompt' ) ); ?>" rows="6" class="large-text" placeholder="<?php esc_attr_e( 'Leave blank to use the built-in grounded prompt.', 'onx-ask' ); ?>"><?php echo esc_textarea( (string) $this->settings->get( 'system_prompt' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Placeholders: {brand}, {site}, {date}. Citation and anti-injection rules are always appended.', 'onx-ask' ); ?></p>
				</div>

				<div class="onx-ask-grid">
					<div class="onx-ask-field">
						<label class="onx-ask-switch">
							<input type="hidden" name="<?php echo esc_attr( $this->field_name( 'enable_logging' ) ); ?>" value="0">
							<input type="checkbox" name="<?php echo esc_attr( $this->field_name( 'enable_logging' ) ); ?>" value="1" <?php checked( 1, (int) $this->settings->get( 'enable_logging', true ) ); ?>>
							<span><?php esc_html_e( 'Log questions (no raw IPs)', 'onx-ask' ); ?></span>
						</label>
					</div>
					<div class="onx-ask-field">
						<label class="onx-ask-switch">
							<input type="hidden" name="<?php echo esc_attr( $this->field_name( 'delete_data' ) ); ?>" value="0">
							<input type="checkbox" name="<?php echo esc_attr( $this->field_name( 'delete_data' ) ); ?>" value="1" <?php checked( 1, (int) $this->settings->get( 'delete_data', true ) ); ?>>
							<span><?php esc_html_e( 'Delete tables and settings on uninstall', 'onx-ask' ); ?></span>
						</label>
					</div>
				</div>
			</div>

			<div class="onx-ask-card">
				<h2><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Public access', 'onx-ask' ); ?></h2>
				<div class="onx-ask-grid">
					<div class="onx-ask-field">
						<label for="onx-origins"><?php esc_html_e( 'Allowed origins (one per line)', 'onx-ask' ); ?></label>
						<textarea id="onx-origins" name="<?php echo esc_attr( $this->field_name( 'allowed_origins' ) ); ?>" rows="3" class="large-text"><?php echo esc_textarea( (string) $this->settings->get( 'allowed_origins' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Example: https://oncologynexus.com. Use * to allow any origin (rate limits still apply).', 'onx-ask' ); ?></p>
					</div>
					<div class="onx-ask-field">
						<label for="onx-rate"><?php esc_html_e( 'Requests per window', 'onx-ask' ); ?></label>
						<input type="number" id="onx-rate" min="0" max="1000" name="<?php echo esc_attr( $this->field_name( 'rate_limit_requests' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'rate_limit_requests' ) ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Per IP, per window. 0 disables the per-IP limit.', 'onx-ask' ); ?></p>
					</div>
					<div class="onx-ask-field">
						<label for="onx-window"><?php esc_html_e( 'Window (seconds)', 'onx-ask' ); ?></label>
						<input type="number" id="onx-window" min="60" max="86400" name="<?php echo esc_attr( $this->field_name( 'rate_limit_window' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'rate_limit_window' ) ); ?>" class="small-text">
					</div>
					<div class="onx-ask-field">
						<label for="onx-daily"><?php esc_html_e( 'Daily request ceiling', 'onx-ask' ); ?></label>
						<input type="number" id="onx-daily" min="0" max="100000" name="<?php echo esc_attr( $this->field_name( 'daily_request_limit' ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( 'daily_request_limit' ) ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Global cost guard. 0 disables it.', 'onx-ask' ); ?></p>
					</div>
				</div>
			</div>

			<?php submit_button( __( 'Save settings', 'onx-ask' ) ); ?>
		</form>
		<?php
	}

	/**
	 * @param string $provider Provider.
	 * @param string $label    Label.
	 */
	private function render_key_field( $provider, $label ) {
		$constant = $this->settings->key_is_constant( $provider );
		$existing = $this->settings->api_key( $provider );
		$id       = 'onx-key-' . $provider;
		$name     = $this->field_name( $provider . '_api_key' );
		?>
		<div class="onx-ask-key">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input
				type="password"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value=""
				class="regular-text"
				autocomplete="new-password"
				placeholder="<?php echo esc_attr( $existing ? __( 'Saved — leave blank to keep', 'onx-ask' ) : __( 'Paste API key', 'onx-ask' ) ); ?>"
				<?php disabled( $constant ); ?>
			>
			<?php if ( $constant ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: constant name. */ __( 'Defined in wp-config.php as %s.', 'onx-ask' ), 'ONX_ASK_' . strtoupper( $provider ) . '_API_KEY' ) ); ?></p>
			<?php elseif ( $existing ) : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: last four characters. */ __( 'A key ending in %s is saved. Leave blank to keep it.', 'onx-ask' ), substr( $existing, -4 ) ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Index
	// ---------------------------------------------------------------------

	private function render_index_tab() {
		$status = $this->indexer->status();
		$stats  = $this->store->stats();
		$logs   = $this->store->get_logs( 20 );
		$coverage = $status['indexable_posts'] > 0
			? min( 100, (int) round( ( $status['indexed_posts'] / $status['indexable_posts'] ) * 100 ) )
			: 0;
		?>
		<div class="onx-ask-card">
			<h2><span class="dashicons dashicons-database"></span> <?php esc_html_e( 'Index status', 'onx-ask' ); ?></h2>

			<div class="onx-ask-stats">
				<div class="onx-ask-stat"><strong data-onx-stat="documents"><?php echo esc_html( (string) $status['indexed_posts'] ); ?></strong><span><?php esc_html_e( 'Indexed posts', 'onx-ask' ); ?></span></div>
				<div class="onx-ask-stat"><strong data-onx-stat="chunks"><?php echo esc_html( (string) $status['chunks'] ); ?></strong><span><?php esc_html_e( 'Chunks', 'onx-ask' ); ?></span></div>
				<div class="onx-ask-stat"><strong data-onx-stat="embedded"><?php echo esc_html( (string) $status['embedded_chunks'] ); ?></strong><span><?php esc_html_e( 'Embedded chunks', 'onx-ask' ); ?></span></div>
				<div class="onx-ask-stat"><strong data-onx-stat="coverage"><?php echo esc_html( (string) $coverage ); ?>%</strong><span><?php esc_html_e( 'Corpus coverage', 'onx-ask' ); ?></span></div>
			</div>

			<div class="onx-ask-progress-wrap" data-onx-progress-wrap <?php echo $status['running'] ? '' : 'hidden'; ?>>
				<div class="onx-ask-progress"><div class="onx-ask-progress-bar" data-onx-progress-bar style="width:<?php echo esc_attr( (string) $status['percent'] ); ?>%"></div></div>
				<p data-onx-progress-text>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: done, 2: total. */
							__( 'Indexing %1$d of %2$d posts…', 'onx-ask' ),
							(int) $status['queued_done'],
							(int) $status['queued_total']
						)
					);
					?>
				</p>
			</div>

			<p class="onx-ask-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: indexed posts, 2: indexable posts, 3: last index time. */
						__( '%1$d of %2$d published posts indexed. Last indexed: %3$s.', 'onx-ask' ),
						(int) $status['indexed_posts'],
						(int) $status['indexable_posts'],
						$status['last_index_at'] ? $status['last_index_at'] . ' UTC' : __( 'never', 'onx-ask' )
					)
				);
				?>
			</p>

			<?php if ( ! empty( $status['last_error'] ) ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Last indexing warning:', 'onx-ask' ); ?></strong> <?php echo esc_html( $status['last_error'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $status['configured'] ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Add an API key and choose a provider before indexing — embeddings will otherwise fall back to lexical-only retrieval.', 'onx-ask' ); ?></p></div>
			<?php endif; ?>

			<div class="onx-ask-actions">
				<button type="button" class="button button-primary" data-onx-action="reindex" data-mode="all"><?php esc_html_e( 'Reindex all content', 'onx-ask' ); ?></button>
				<button type="button" class="button" data-onx-action="reindex" data-mode="recent"><?php esc_html_e( 'Reindex last 7 days', 'onx-ask' ); ?></button>
				<button type="button" class="button button-link-delete" data-onx-action="flush"><?php esc_html_e( 'Flush index', 'onx-ask' ); ?></button>
				<button type="button" class="button" data-onx-action="test" data-target="chat"><?php esc_html_e( 'Test chat provider', 'onx-ask' ); ?></button>
				<button type="button" class="button" data-onx-action="test" data-target="embedding"><?php esc_html_e( 'Test embeddings', 'onx-ask' ); ?></button>
				<span class="onx-ask-inline-result" data-onx-result></span>
			</div>

			<p class="description">
				<?php esc_html_e( 'Reindexing runs in small cron batches. Keep this tab open to watch progress; you can close it and the queue continues in the background.', 'onx-ask' ); ?>
			</p>
		</div>

		<?php if ( ! empty( $status['errors'] ) ) : ?>
			<div class="onx-ask-card">
				<h2><?php esc_html_e( 'Recent indexing errors', 'onx-ask' ); ?></h2>
				<ul class="onx-ask-error-list">
					<?php foreach ( array_reverse( $status['errors'] ) as $error ) : ?>
						<li>
							<code>#<?php echo esc_html( (string) $error['post_id'] ); ?></code>
							<?php echo esc_html( (string) $error['message'] ); ?>
							<small><?php echo esc_html( (string) $error['time'] ); ?> UTC</small>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="onx-ask-card">
			<h2><span class="dashicons dashicons-chart-bar"></span> <?php esc_html_e( 'Recent questions', 'onx-ask' ); ?></h2>
			<?php if ( empty( $logs ) ) : ?>
				<p class="onx-ask-muted"><?php esc_html_e( 'No questions logged yet.', 'onx-ask' ); ?></p>
			<?php else : ?>
				<table class="widefat striped onx-ask-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'onx-ask' ); ?></th>
							<th><?php esc_html_e( 'Question', 'onx-ask' ); ?></th>
							<th><?php esc_html_e( 'Provider', 'onx-ask' ); ?></th>
							<th><?php esc_html_e( 'Sources', 'onx-ask' ); ?></th>
							<th><?php esc_html_e( 'Latency', 'onx-ask' ); ?></th>
							<th><?php esc_html_e( 'Cache', 'onx-ask' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $log['created_at'] ); ?></td>
								<td><?php echo esc_html( wp_trim_words( (string) $log['question'], 18, '…' ) ); ?></td>
								<td><?php echo esc_html( (string) $log['provider'] . ' / ' . (string) $log['model'] ); ?></td>
								<td><?php echo esc_html( (string) $log['sources_count'] ); ?></td>
								<td><?php echo esc_html( (string) $log['latency_ms'] ); ?> ms</td>
								<td><?php echo $log['cache_hit'] ? esc_html__( 'hit', 'onx-ask' ) : esc_html__( 'miss', 'onx-ask' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="onx-ask-card">
			<h2><?php esc_html_e( 'Configuration summary', 'onx-ask' ); ?></h2>
			<dl class="onx-ask-dl">
				<dt><?php esc_html_e( 'Chat', 'onx-ask' ); ?></dt><dd><?php echo esc_html( $this->settings->chat_config()['provider'] . ' / ' . $this->settings->chat_config()['model'] ); ?></dd>
				<dt><?php esc_html_e( 'Embeddings', 'onx-ask' ); ?></dt><dd><?php echo esc_html( $this->settings->embedding_config()['provider'] . ' / ' . $this->settings->embedding_config()['model'] ); ?></dd>
				<dt><?php esc_html_e( 'Post types', 'onx-ask' ); ?></dt><dd><?php echo esc_html( implode( ', ', $this->settings->post_types() ) ); ?></dd>
				<dt><?php esc_html_e( 'Index version', 'onx-ask' ); ?></dt><dd><code><?php echo esc_html( $status['index_version'] ); ?></code></dd>
				<dt><?php esc_html_e( 'Last complete run', 'onx-ask' ); ?></dt><dd><?php echo esc_html( $status['last_complete'] ? $status['last_complete'] . ' UTC' : __( 'never', 'onx-ask' ) ); ?></dd>
			</dl>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Playground
	// ---------------------------------------------------------------------

	private function render_playground_tab() {
		$suggestions = $this->settings->suggested_questions();
		?>
		<div class="onx-ask-card">
			<h2><span class="dashicons dashicons-testimonial"></span> <?php esc_html_e( 'Playground', 'onx-ask' ); ?></h2>
			<p class="onx-ask-muted"><?php esc_html_e( 'Ask the live assistant. This uses your configured provider and may incur token costs.', 'onx-ask' ); ?></p>

			<?php if ( ! $this->settings->is_configured() ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'Configure a provider and save an API key first.', 'onx-ask' ); ?></p></div>
			<?php endif; ?>

			<form data-onx-playground-form class="onx-ask-playground-form">
				<label for="onx-playground-question" class="screen-reader-text"><?php esc_html_e( 'Question', 'onx-ask' ); ?></label>
				<textarea id="onx-playground-question" name="question" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Ask about a cancer type, biomarker, drug, trial, conference or doctor…', 'onx-ask' ); ?>"></textarea>
				<div class="onx-ask-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Ask', 'onx-ask' ); ?></button>
					<span class="onx-ask-inline-result" data-onx-playground-status></span>
				</div>
			</form>

			<?php if ( ! empty( $suggestions ) ) : ?>
				<div class="onx-ask-suggestions">
					<?php foreach ( $suggestions as $suggestion ) : ?>
						<button type="button" class="button" data-onx-suggestion="<?php echo esc_attr( $suggestion ); ?>"><?php echo esc_html( $suggestion ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="onx-ask-answer" data-onx-playground-answer hidden></div>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * @param string $key Key.
	 * @return string
	 */
	private function field_name( $key ) {
		return ONX_ASK_OPTION . '[' . $key . ']';
	}

	/**
	 * @param string $key     Key.
	 * @param string $label   Label.
	 * @param int    $min     Min.
	 * @param int    $max     Max.
	 * @param string $help    Help.
	 * @param string $step    Step.
	 */
	private function render_number( $key, $label, $min, $max, $help = '', $step = '1' ) {
		$id = 'onx-' . str_replace( '_', '-', $key );
		?>
		<div class="onx-ask-field">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<input type="number" id="<?php echo esc_attr( $id ); ?>" min="<?php echo esc_attr( (string) $min ); ?>" max="<?php echo esc_attr( (string) $max ); ?>" step="<?php echo esc_attr( $step ); ?>" name="<?php echo esc_attr( $this->field_name( $key ) ); ?>" value="<?php echo esc_attr( (string) $this->settings->get( $key ) ); ?>" class="small-text">
			<?php if ( $help ) : ?>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
