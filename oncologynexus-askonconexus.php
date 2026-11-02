<?php
/**
 * Plugin Name:       OncologyNexus - Ask Onconexus
 * Plugin URI:        https://oncologynexus.com/
 * Description:       Retrieval-augmented generation (RAG) assistant for the headless Next.js frontend. Indexes every published piece of site content, retrieves the most relevant passages with hybrid dense + lexical search, and answers questions through OpenAI, Anthropic Claude or Google Gemini with inline citations.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Pedro Molina
 * Author URI:        https://www.pekebyte.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       onx-ask
 * Domain Path:       /languages
 *
 * REST endpoints (namespace /wp-json/oncologynexus/v1/):
 *   POST /ask                 Ask a question. Public, rate-limited, origin-checked.
 *   GET  /ask/status          Index + configuration status for the frontend.
 *   GET  /ask/suggestions     Starter questions configured in the admin.
 *   POST /ask/reindex         Admin: queue a full / recent / single reindex.
 *   GET  /ask/index-status    Admin: live indexing progress.
 *   POST /ask/flush           Admin: drop every indexed chunk.
 *   POST /ask/test            Admin: test a chat / embedding provider.
 *   GET  /ask/logs            Admin: recent questions.
 *
 * WP-CLI:
 *   wp onx-ask index [--post_id=<id>] [--force]
 *   wp onx-ask status
 *   wp onx-ask ask "question"
 *   wp onx-ask flush
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ONX_ASK_VERSION', '1.0.0' );
define( 'ONX_ASK_FILE', __FILE__ );
define( 'ONX_ASK_DIR', plugin_dir_path( __FILE__ ) );
define( 'ONX_ASK_URL', plugin_dir_url( __FILE__ ) );
define( 'ONX_ASK_OPTION', 'onx_ask_settings' );
define( 'ONX_ASK_DB_VERSION', '1.0.0' );

require_once ONX_ASK_DIR . 'includes/class-onx-ask-settings.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-http.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-content.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-chunker.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-embeddings.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-vector-store.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-llm.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-indexer.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-retriever.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-security.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-service.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-rest.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-admin.php';
require_once ONX_ASK_DIR . 'includes/class-onx-ask-installer.php';

register_activation_hook( __FILE__, array( 'OncologyNexus_Ask_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'OncologyNexus_Ask_Installer', 'deactivate' ) );

/**
 * Main plugin container. Wires the services together once per request.
 */
final class OncologyNexus_Ask {

	/** @var OncologyNexus_Ask|null */
	private static $instance = null;

	/** @var OncologyNexus_Ask_Settings */
	public $settings;

	/** @var OncologyNexus_Ask_Content */
	public $content;

	/** @var OncologyNexus_Ask_Chunker */
	public $chunker;

	/** @var OncologyNexus_Ask_Embeddings */
	public $embeddings;

	/** @var OncologyNexus_Ask_Vector_Store */
	public $store;

	/** @var OncologyNexus_Ask_LLM */
	public $llm;

	/** @var OncologyNexus_Ask_Retriever */
	public $retriever;

	/** @var OncologyNexus_Ask_Indexer */
	public $indexer;

	/** @var OncologyNexus_Ask_Security */
	public $security;

	/** @var OncologyNexus_Ask_Service */
	public $service;

	/** @var OncologyNexus_Ask_REST */
	public $rest;

	/** @var OncologyNexus_Ask_Admin|null */
	public $admin = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings   = new OncologyNexus_Ask_Settings();
		$this->content    = new OncologyNexus_Ask_Content( $this->settings );
		$this->chunker    = new OncologyNexus_Ask_Chunker( $this->settings );
		$this->embeddings = new OncologyNexus_Ask_Embeddings( $this->settings );
		$this->store      = new OncologyNexus_Ask_Vector_Store();
		$this->llm        = new OncologyNexus_Ask_LLM( $this->settings );
		$this->retriever  = new OncologyNexus_Ask_Retriever( $this->settings, $this->embeddings, $this->store, $this->llm );
		$this->indexer    = new OncologyNexus_Ask_Indexer( $this->settings, $this->content, $this->chunker, $this->embeddings, $this->store );
		$this->security   = new OncologyNexus_Ask_Security( $this->settings );
		$this->service    = new OncologyNexus_Ask_Service( $this->settings, $this->retriever, $this->llm, $this->store, $this->security );
		$this->rest       = new OncologyNexus_Ask_REST( $this->settings, $this->service, $this->indexer, $this->store, $this->llm, $this->embeddings, $this->security );

		if ( is_admin() ) {
			$this->admin = new OncologyNexus_Ask_Admin( $this->settings, $this->indexer, $this->store, $this->llm, $this->embeddings );
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		OncologyNexus_Ask_Installer::maybe_upgrade();
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'onx-ask', false, dirname( plugin_basename( ONX_ASK_FILE ) ) . '/languages' );
	}
}

/**
 * Accessor used by templates, WP-CLI and third-party integrations.
 *
 * @return OncologyNexus_Ask
 */
function onx_ask() {
	return OncologyNexus_Ask::instance();
}

add_action( 'plugins_loaded', 'onx_ask', 5 );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		require_once ONX_ASK_DIR . 'includes/class-onx-ask-cli.php';
		$plugin = onx_ask();
		WP_CLI::add_command(
			'onx-ask',
			new OncologyNexus_Ask_CLI( $plugin->service, $plugin->indexer, $plugin->store, $plugin->settings )
		);
	},
	20
);
