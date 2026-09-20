<?php
/**
 * Network administration.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Admin;

use SolrEngine\Multisite\NetworkConfig;
use SolrEngine\Support\Config;
use SolrEngine\Support\Credentials;

/**
 * One Solr, many sites, one place to say so.
 *
 * A network administrator with four hundred sites cannot type an endpoint into
 * four hundred settings screens, and should not have to trust that nobody
 * changes it afterwards. This screen answers both: it sets the values every site
 * inherits, and decides which of them a site may override.
 *
 * Three decisions it makes visible.
 *
 * **Sites are off until switched on.** Activating a plugin network-wide must not
 * start four hundred full syncs against one cluster, which is what §20 warns
 * about and what happens by default in most plugins that grow a network screen
 * late. A site that is off behaves exactly as it did before the plugin existed.
 *
 * **A default and a pin are different things.** A default is what a site gets
 * when it has chosen nothing; a pin is a value it cannot change. Both are stored
 * once, and the checkbox beside each field decides which it is.
 *
 * **Topology is a deployment choice.** One collection per site or one shared
 * collection is a decision about the Solr cluster, not about SolrEngine: every
 * document already carries its site identifier and every query already filters on
 * it, so the choice changes a collection name and nothing else.
 *
 * @since 0.9.0
 */
final class NetworkScreen {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'solrengine-network';

	/**
	 * Form action, handled through network_admin_edit_.
	 *
	 * @var string
	 */
	public const ACTION = 'solrengine_save_network';

	/**
	 * Paths this screen offers to set for the whole network.
	 *
	 * Deliberately short. Everything here is a property of the Solr cluster,
	 * which is the thing a network has one of; anything that is a property of a
	 * site — which post types it indexes, how it weights a title — stays with the
	 * site, because a network administrator has no useful opinion about it.
	 *
	 * @var string[]
	 */
	private const SHARED_PATHS = array(
		'connection.endpoint',
		'connection.collection',
		'connection.allow_private_ip',
		'connection.verify_tls',
	);

	/**
	 * Network configuration.
	 *
	 * @var NetworkConfig
	 */
	private $network;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Site configuration, for reading what a constant has pinned.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param NetworkConfig $network     Network configuration.
	 * @param Credentials   $credentials Credential store.
	 * @param Config        $config      Site configuration.
	 */
	public function __construct( NetworkConfig $network, Credentials $credentials, Config $config ) {
		$this->network     = $network;
		$this->credentials = $credentials;
		$this->config      = $config;
	}

	/**
	 * Add the menu entry.
	 *
	 * Under Settings in the network dashboard, which is where a network
	 * administrator looks for something that configures the network rather than
	 * one site.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'SolrEngine', 'solrengine' ),
			__( 'SolrEngine', 'solrengine' ),
			'manage_network_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Save the submitted settings.
	 *
	 * Posted to edit.php rather than admin-post.php, which is how the network
	 * dashboard handles a form: options.php does not exist there, and a network
	 * option written from a site request would be written by whoever happened to
	 * be looking at site 12.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'solrengine' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$request = (array) wp_unslash( $_POST );

		$this->network->set( 'connection.endpoint', sanitize_text_field( (string) ( $request['endpoint'] ?? '' ) ) );
		$this->network->set( 'connection.collection', sanitize_text_field( (string) ( $request['collection'] ?? '' ) ) );
		$this->network->set( 'connection.allow_private_ip', ! empty( $request['allow_private_ip'] ) );
		$this->network->set( 'connection.verify_tls', ! empty( $request['verify_tls'] ) );

		$pins = array();

		foreach ( (array) ( $request['pinned'] ?? array() ) as $path ) {
			$path = sanitize_text_field( (string) $path );

			if ( in_array( $path, self::SHARED_PATHS, true ) ) {
				$pins[] = $path;
			}
		}

		$this->network->set_pinned( $pins );
		$this->network->set_topology( sanitize_key( (string) ( $request['topology'] ?? '' ) ) );
		$this->network->set_collection_pattern( sanitize_text_field( (string) ( $request['pattern'] ?? '' ) ) );

		$sites = array();

		foreach ( (array) ( $request['sites'] ?? array() ) as $site_id ) {
			$sites[] = (int) $site_id;
		}

		$this->network->set_enabled_sites( $sites );

		$notice = 'saved';

		// An empty password means "leave it alone", the same rule the site
		// screen follows. Treating a blank submit as a deletion would disarm
		// every site in the network the first time somebody changed a checkbox.
		if ( isset( $request['password'] ) && '' !== $request['password'] ) {
			$stored = $this->credentials->store_for_network(
				sanitize_text_field( (string) ( $request['username'] ?? '' ) ),
				// Not sanitised: a password is an opaque byte string and
				// sanitize_text_field() would mangle valid ones. It is never
				// rendered, only sent as an Authorization header.
				(string) $request['password'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			);

			if ( $stored->is_failure() ) {
				$notice = 'credentials_failed';
			}
		} elseif ( ! empty( $request['clear_password'] ) ) {
			$this->credentials->store_for_network( '', '' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'solrengine_notice' => $notice,
				),
				network_admin_url( 'settings.php' )
			)
		);

		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SolrEngine', 'solrengine' ); ?></h1>

			<?php $this->render_notice(); ?>

			<p>
				<?php esc_html_e( 'Settings here apply to every site in the network. A site can change any of them for itself unless you lock it.', 'solrengine' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::ACTION ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<h2><?php esc_html_e( 'Connection', 'solrengine' ); ?></h2>

				<table class="form-table" role="presentation">
					<?php
					$this->text_row(
						'endpoint',
						'connection.endpoint',
						__( 'Solr address', 'solrengine' ),
						__( 'The base address of your Solr server, ending in /solr.', 'solrengine' )
					);

					$this->text_row(
						'collection',
						'connection.collection',
						__( 'Collection or core', 'solrengine' ),
						__( 'Used as-is under a shared collection. Under one collection per site it is the fallback for a site with no pattern.', 'solrengine' )
					);
					?>

					<tr>
						<th scope="row">
							<label for="solrengine-network-username"><?php esc_html_e( 'Username', 'solrengine' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="solrengine-network-username"
								name="username"
								class="regular-text"
								autocomplete="off"
								value="<?php echo esc_attr( $this->credentials->username() ); ?>"
							/>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="solrengine-network-password"><?php esc_html_e( 'Password', 'solrengine' ); ?></label>
						</th>
						<td>
							<?php if ( 'constant' === $this->credentials->source() ) : ?>
								<p><strong><?php esc_html_e( 'Set in wp-config.php, which is the most secure option and applies to every site already.', 'solrengine' ); ?></strong></p>
							<?php else : ?>
								<input
									type="password"
									id="solrengine-network-password"
									name="password"
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo esc_attr( $this->credentials->has_network_password() ? __( 'Saved. Type to replace.', 'solrengine' ) : __( 'Not set', 'solrengine' ) ); ?>"
								/>
								<?php if ( $this->credentials->has_network_password() ) : ?>
									<p>
										<label>
											<input type="checkbox" name="clear_password" value="1"/>
											<?php esc_html_e( 'Remove the network password', 'solrengine' ); ?>
										</label>
									</p>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'Stored encrypted, once, and readable by every site because a network shares the keys in wp-config.php. A site that has entered its own credentials keeps them.', 'solrengine' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Network', 'solrengine' ); ?></th>
						<td>
							<?php
							$this->checkbox( 'allow_private_ip', 'connection.allow_private_ip', __( 'Solr is on this machine or a private network', 'solrengine' ) );
							$this->checkbox( 'verify_tls', 'connection.verify_tls', __( 'Verify TLS certificates', 'solrengine' ), true );
							?>
						</td>
					</tr>
				</table>

				<?php $this->render_topology(); ?>
				<?php $this->render_sites(); ?>

				<?php submit_button( __( 'Save network settings', 'solrengine' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the topology choice.
	 *
	 * @return void
	 */
	private function render_topology(): void {
		$topology = $this->network->topology();

		?>
		<h2><?php esc_html_e( 'Collections', 'solrengine' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'How many', 'solrengine' ); ?></th>
				<td>
					<p>
						<label>
							<input
								type="radio"
								name="topology"
								value="<?php echo esc_attr( NetworkConfig::TOPOLOGY_PER_SITE ); ?>"
								<?php checked( NetworkConfig::TOPOLOGY_PER_SITE === $topology ); ?>
							/>
							<?php esc_html_e( 'One collection per site', 'solrengine' ); ?>
						</label>
						<br/>
						<span class="description">
							<?php esc_html_e( 'Isolated relevance and configuration, and deleting a site is dropping a collection. A network of several hundred sites means several hundred collections, which strains SolrCloud cluster state.', 'solrengine' ); ?>
						</span>
					</p>

					<p>
						<label>
							<input
								type="radio"
								name="topology"
								value="<?php echo esc_attr( NetworkConfig::TOPOLOGY_SHARED ); ?>"
								<?php checked( NetworkConfig::TOPOLOGY_SHARED === $topology ); ?>
							/>
							<?php esc_html_e( 'One collection for the whole network', 'solrengine' ); ?>
						</label>
						<br/>
						<span class="description">
							<?php esc_html_e( 'One schema to manage and efficient for many small sites. Sites share relevance statistics, and one site reindexing affects the others. Every document already carries its site, and every query already filters on it, so results never cross between sites.', 'solrengine' ); ?>
						</span>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="solrengine-pattern"><?php esc_html_e( 'Naming pattern', 'solrengine' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="solrengine-pattern"
						name="pattern"
						class="regular-text code"
						placeholder="wordpress_%d"
						value="<?php echo esc_attr( $this->network->collection_pattern() ); ?>"
					/>
					<p class="description">
						<?php
						printf(
							/* translators: 1: the literal token %d, 2: an example pattern. */
							esc_html__( 'Used only for one collection per site. %1$s becomes the site ID, so %2$s makes site 7 use wordpress_7. Leave it empty to let each site name its own.', 'solrengine' ),
							'<code>%d</code>',
							'<code>wordpress_%d</code>'
						);
						?>
						<br/>
						<?php esc_html_e( 'SolrEngine does not create collections here. Create them on your Solr first.', 'solrengine' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the per-site opt-in list.
	 *
	 * @return void
	 */
	private function render_sites(): void {
		$enabled = $this->network->enabled_sites();

		$sites = get_sites(
			array(
				'number'  => 500,
				'orderby' => 'id',
			)
		);

		?>
		<h2><?php esc_html_e( 'Sites', 'solrengine' ); ?></h2>

		<p>
			<?php esc_html_e( 'SolrEngine does nothing on a site until you switch it on here. That is deliberate: switching on four hundred sites at once would start four hundred full indexes against one Solr server. Switch them on in batches, and index each one before moving to the next.', 'solrengine' ); ?>
		</p>

		<?php if ( count( $sites ) >= 500 ) : ?>
			<p class="description">
				<?php esc_html_e( 'Showing the first 500 sites. On a larger network, use "wp solrengine network enable" from the command line.', 'solrengine' ); ?>
			</p>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:820px">
			<thead>
				<tr>
					<th scope="col" style="width:5em"><?php esc_html_e( 'On', 'solrengine' ); ?></th>
					<th scope="col" style="width:5em"><?php esc_html_e( 'ID', 'solrengine' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Site', 'solrengine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sites as $site ) : ?>
					<?php $id = (int) $site->blog_id; ?>
					<tr>
						<td>
							<label>
								<input
									type="checkbox"
									name="sites[]"
									value="<?php echo esc_attr( (string) $id ); ?>"
									<?php checked( in_array( $id, $enabled, true ) ); ?>
								/>
								<span class="screen-reader-text"><?php esc_html_e( 'Use SolrEngine on this site', 'solrengine' ); ?></span>
							</label>
						</td>
						<td><?php echo esc_html( (string) $id ); ?></td>
						<td>
							<?php echo esc_html( (string) $site->domain . (string) $site->path ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a text field with its lock checkbox.
	 *
	 * @param string $name        Form field name.
	 * @param string $path        Configuration dot path.
	 * @param string $label       Field label.
	 * @param string $description Field description.
	 * @return void
	 */
	private function text_row( string $name, string $path, string $label, string $description ): void {
		$value    = $this->network->get( $path );
		$constant = 'constant' === $this->config->lock_source( $path );

		?>
		<tr>
			<th scope="row">
				<label for="solrengine-network-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input
					type="text"
					id="solrengine-network-<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					class="regular-text code"
					value="<?php echo esc_attr( is_scalar( $value ) ? (string) $value : '' ); ?>"
					<?php disabled( $constant ); ?>
				/>
				<?php $this->lock_checkbox( $path, $constant ); ?>
				<p class="description">
					<?php echo esc_html( $description ); ?>
					<?php if ( $constant ) : ?>
						<br/><strong><?php esc_html_e( 'Set in wp-config.php, which overrides this and every site.', 'solrengine' ); ?></strong>
					<?php endif; ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a checkbox with its lock checkbox.
	 *
	 * @param string $name     Form field name.
	 * @param string $path     Configuration dot path.
	 * @param string $label    Field label.
	 * @param bool   $fallback Value when nothing is stored.
	 * @return void
	 */
	private function checkbox( string $name, string $path, string $label, bool $fallback = false ): void {
		$value = $this->network->get( $path );

		?>
		<p>
			<label>
				<input
					type="checkbox"
					name="<?php echo esc_attr( $name ); ?>"
					value="1"
					<?php checked( null === $value ? $fallback : (bool) $value ); ?>
				/>
				<?php echo esc_html( $label ); ?>
			</label>
			<?php $this->lock_checkbox( $path, false ); ?>
		</p>
		<?php
	}

	/**
	 * Render the "sites cannot change this" checkbox for one path.
	 *
	 * @param string $path     Configuration dot path.
	 * @param bool   $constant Whether a constant already pins it.
	 * @return void
	 */
	private function lock_checkbox( string $path, bool $constant ): void {
		?>
		<label style="margin-left:1em">
			<input
				type="checkbox"
				name="pinned[]"
				value="<?php echo esc_attr( $path ); ?>"
				<?php checked( $this->network->is_pinned( $path ) ); ?>
				<?php disabled( $constant ); ?>
			/>
			<?php esc_html_e( 'Sites cannot change this', 'solrengine' ); ?>
		</label>
		<?php
	}

	/**
	 * Render the notice from a redirect, if any.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect flag.
		$notice = isset( $_GET['solrengine_notice'] ) ? sanitize_key( wp_unslash( $_GET['solrengine_notice'] ) ) : '';

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Network settings saved.', 'solrengine' )
				. '</p></div>';

			return;
		}

		if ( 'credentials_failed' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>'
				. esc_html__( 'The settings were saved, but the password could not be stored securely. Set SOLRENGINE_SOLR_USER and SOLRENGINE_SOLR_PASSWORD in wp-config.php instead.', 'solrengine' )
				. '</p></div>';
		}
	}
}
