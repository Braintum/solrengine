<?php
/**
 * SearchWidget class file.
 *
 * @package SolrEngine\Front
 */

namespace SolrEngine\Front;

use SolrEngine\Container;

/**
 * Frontend widget to render a Solr search form.
 */
class SearchWidget extends \WP_Widget {

	/**
	 * Container instance.
	 *
	 * @var Container|null
	 */
	protected ?Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container|null $container Container instance.
	 */
	public function __construct( ?Container $container = null ) {
		$this->container = $container;

		parent::__construct(
			'solrengine_search_widget',
			__( 'SolrEngine Search Bar', 'solrengine' ),
			array( 'description' => __( 'Adds a powerful search box.', 'solrengine' ) )
		);
	}

	/**
	 * Render the widget.
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Widget instance settings.
	 */
	public function widget( $args, $instance ) {
		echo wp_kses_post( $args['before_widget'] );
		if ( ! empty( $instance['title'] ) ) {
			echo wp_kses_post( $args['before_title'] . $instance['title'] . $args['after_title'] );
		}

		$query = esc_attr( isset( $_GET['q'] ) ? wp_unslash( $_GET['q'] ) : '' ); // phpcs:ignore
		?>
		<form action="<?php echo esc_url( home_url( '/solrengine-search/' ) ); ?>" method="get" class="solrengine-search-form">
			<input type="text" name="q" value="<?php echo esc_attr( $query ); ?>"
				   placeholder="<?php esc_attr_e( 'Search...', 'solrengine' ); ?>" />
			<button type="submit"><?php esc_html_e( 'Search', 'solrengine' ); ?></button>
		</form>
		<?php
		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Render the widget form in admin.
	 *
	 * @param array $instance Widget instance settings.
	 */
	public function form( $instance ) {
		$title = esc_attr( isset( $instance['title'] ) ? $instance['title'] : __( 'Search', 'solrengine' ) );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'solrengine' ); ?>
			</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text"
				   value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<?php
	}

	/**
	 * Update widget settings.
	 *
	 * @param array $new_instance New instance settings.
	 * @param array $old_instance Old instance settings.
	 * @return array Updated instance settings.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance          = array();
		$instance['title'] = sanitize_text_field( $new_instance['title'] );
		return $instance;
	}

	/**
	 * Get the container instance.
	 *
	 * @return Container|null
	 */
	protected function getContainer(): ?Container {
		if ( $this->container ) {
			return $this->container;
		}

		// Fallback to get container from global plugin instance.
		if ( function_exists( 'solrengine_search_bootstrap' ) ) {
			$plugin = solrengine_search_bootstrap();
			return $plugin->container();
		}

		return null;
	}

	/**
	 * Get widget display name for block editor.
	 *
	 * @return string
	 */
	public static function get_widget_display_name(): string {
		return __( 'SolrEngine Search Bar', 'solrengine' );
	}

	/**
	 * Get widget description for block editor.
	 *
	 * @return string
	 */
	public static function get_widget_description(): string {
		return __( 'Adds a powerful Solr-powered search box to your site.', 'solrengine' );
	}
}
