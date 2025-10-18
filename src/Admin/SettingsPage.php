<?php
/**
 * SettingsPage class file.
 *
 * @package SolrEngine\Admin
 */

namespace SolrEngine\Admin;

use SolrEngine\Container;
use SolrEngine\Config;

/**
 * Class SettingsPage
 *
 * Registers an admin settings page under "Settings → WP Solr Search"
 */
class SettingsPage {

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	protected Container $container;

	/**
	 * Config instance.
	 *
	 * @var Config
	 */
	protected Config $config;

	/**
	 * Option group.
	 *
	 * @var string
	 */
	protected string $option_group = 'solrengine_settings_group';

	/**
	 * Option name.
	 *
	 * @var string
	 */
	protected string $option_name = 'solrengine_settings';

	/**
	 * Constructor.
	 *
	 * @param Container $container Container instance.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
		$this->config    = $container->get( 'config' );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
	}

	/**
	 * Add settings page to menu.
	 */
	public function addMenu(): void {
		add_options_page(
			__( 'WP Solr Search', 'solrengine' ),
			__( 'WP Solr Search', 'solrengine' ),
			'manage_options',
			'solrengine-search',
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Register settings and fields.
	 */
	public function registerSettings(): void {
		register_setting( $this->option_group, $this->option_name );

		add_settings_section(
			'solrengine_main',
			__( 'Solr Configuration', 'solrengine' ),
			function () {
				echo '<p>' . esc_html__( 'Configure Solr connection and indexing options.', 'solrengine' ) . '</p>';
			},
			'solrengine-search'
		);

		add_settings_field(
			'solr_endpoint',
			__( 'Solr Endpoint', 'solrengine' ),
			array( $this, 'renderEndpointField' ),
			'solrengine-search',
			'solrengine_main'
		);
	}

	/**
	 * Render the Solr endpoint field.
	 */
	public function renderEndpointField(): void {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['solr_endpoint'] ) ? $options['solr_endpoint'] : $this->config->get( 'solr.endpoint' );
		?>
		<input
			type="text"
			name="<?php echo esc_attr( $this->option_name ); ?>[solr_endpoint]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Example: http://127.0.0.1:8983/solr/wordpress', 'solrengine' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public function renderPage(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Solr Search Settings', 'solrengine' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_group );
				do_settings_sections( 'solrengine-search' );
				submit_button( __( 'Save Settings', 'solrengine' ) );
				?>
			</form>
		</div>
		<?php
	}
}
