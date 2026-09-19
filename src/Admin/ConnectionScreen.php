<?php
/**
 * Connection settings screen.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Admin;

use SolrEngine\Backend\Solr\EndpointValidator;
use SolrEngine\Diagnostics\ConnectionDiagnostic;
use SolrEngine\Diagnostics\DiagnosticReport;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaExporter;
use SolrEngine\Support\Config;
use SolrEngine\Support\Credentials;

/**
 * Where an administrator points SolrEngine at their Solr server.
 *
 * Deliberately one screen. The plan's section 17 argues for six in total, not
 * twelve, because every extra top-level item lengthens the setup path for
 * someone who is not a Solr expert.
 *
 * Three behaviours worth noting, each fixing something the 0.1.0 prototype got
 * wrong:
 *
 * - It reads the same option it writes. The prototype's settings page wrote
 *   solrengine_settings while its configuration read solrengine_solr_endpoint,
 *   so saving the endpoint had no effect whatsoever.
 * - A value pinned by a constant in wp-config.php is shown as such, and its
 *   field is disabled rather than accepting input that would be discarded.
 * - The password is never rendered back into the form. The field shows whether
 *   one is set and offers to replace it.
 *
 * @since 0.1.0
 */
final class ConnectionScreen {

	/**
	 * Menu and page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'solrengine';

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Connection diagnostic.
	 *
	 * @var ConnectionDiagnostic
	 */
	private $diagnostic;

	/**
	 * Contract registry.
	 *
	 * @var ContractRegistry
	 */
	private $contracts;

	/**
	 * Schema exporter.
	 *
	 * @var SchemaExporter
	 */
	private $exporter;

	/**
	 * Constructor.
	 *
	 * @param Config               $config      Configuration.
	 * @param Credentials          $credentials Credential store.
	 * @param ConnectionDiagnostic $diagnostic  Connection diagnostic.
	 * @param ContractRegistry     $contracts   Contract registry.
	 * @param SchemaExporter       $exporter    Schema exporter.
	 */
	public function __construct(
		Config $config,
		Credentials $credentials,
		ConnectionDiagnostic $diagnostic,
		ContractRegistry $contracts,
		SchemaExporter $exporter
	) {
		$this->config      = $config;
		$this->credentials = $credentials;
		$this->diagnostic  = $diagnostic;
		$this->contracts   = $contracts;
		$this->exporter    = $exporter;
	}

	/**
	 * Register the screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_solrengine_save_connection', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the menu entry.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'SolrEngine', 'solrengine' ),
			__( 'SolrEngine', 'solrengine' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-search',
			80
		);
	}

	/**
	 * Save the submitted connection settings.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'solrengine' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'solrengine_save_connection' );

		$endpoint   = isset( $_POST['endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['endpoint'] ) ) : '';
		$collection = isset( $_POST['collection'] ) ? sanitize_text_field( wp_unslash( $_POST['collection'] ) ) : '';
		$username   = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$private    = ! empty( $_POST['allow_private_ip'] );
		$verify_tls = ! empty( $_POST['verify_tls'] );

		$this->config->set( 'connection.endpoint', $endpoint );
		$this->config->set( 'connection.collection', $collection );
		$this->config->set( 'connection.allow_private_ip', $private );
		$this->config->set( 'connection.verify_tls', $verify_tls );

		$notice = 'saved';

		// An empty password field means "leave it alone", not "clear it".
		// Treating a blank submit as a deletion would wipe credentials every
		// time somebody changed the timeout.
		if ( isset( $_POST['password'] ) && '' !== $_POST['password'] ) {
			$stored = $this->credentials->store(
				$username,
				// Not sanitised: a password is an opaque byte string, and
				// sanitize_text_field() would silently mangle valid ones.
				// It is never rendered, only sent as an Authorization header.
				(string) wp_unslash( $_POST['password'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			);

			if ( $stored->is_failure() ) {
				$notice = 'credentials_failed';
			}
		} elseif ( ! empty( $_POST['clear_password'] ) ) {
			$this->credentials->forget();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::SLUG,
					'solrengine_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$endpoint   = $this->config->get_string( 'connection.endpoint' );
		$collection = $this->config->get_string( 'connection.collection' );
		$tested     = null;

		if ( '' !== $endpoint ) {
			$tested = $this->diagnostic->run();
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SolrEngine', 'solrengine' ); ?></h1>

			<?php $this->render_notice(); ?>

			<h2><?php esc_html_e( 'Connection', 'solrengine' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="solrengine_save_connection"/>
				<?php wp_nonce_field( 'solrengine_save_connection' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="solrengine-endpoint"><?php esc_html_e( 'Solr address', 'solrengine' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="solrengine-endpoint"
								name="endpoint"
								class="regular-text code"
								value="<?php echo esc_attr( $endpoint ); ?>"
								placeholder="https://solr.example.com:8983/solr"
								<?php disabled( $this->config->is_locked( 'connection.endpoint' ) ); ?>
							/>
							<p class="description">
								<?php esc_html_e( 'The base address of your Solr server, ending in /solr. A collection name pasted here is ignored; enter it in the next field.', 'solrengine' ); ?>
								<?php if ( $this->config->is_locked( 'connection.endpoint' ) ) : ?>
									<br/><strong><?php esc_html_e( 'Set in wp-config.php.', 'solrengine' ); ?></strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="solrengine-collection"><?php esc_html_e( 'Collection or core', 'solrengine' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="solrengine-collection"
								name="collection"
								class="regular-text code"
								value="<?php echo esc_attr( $collection ); ?>"
								placeholder="wordpress"
								<?php disabled( $this->config->is_locked( 'connection.collection' ) ); ?>
							/>
							<p class="description">
								<?php esc_html_e( 'SolrEngine does not create this for you. Create it on your Solr server first.', 'solrengine' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="solrengine-username"><?php esc_html_e( 'Username', 'solrengine' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="solrengine-username"
								name="username"
								class="regular-text"
								autocomplete="off"
								value="<?php echo esc_attr( $this->credentials->username() ); ?>"
								<?php disabled( 'constant' === $this->credentials->source() ); ?>
							/>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="solrengine-password"><?php esc_html_e( 'Password', 'solrengine' ); ?></label>
						</th>
						<td>
							<?php if ( 'constant' === $this->credentials->source() ) : ?>
								<p><strong><?php esc_html_e( 'Set in wp-config.php.', 'solrengine' ); ?></strong></p>
								<p class="description">
									<?php esc_html_e( 'This is the most secure option. Credentials defined there are never written to the database.', 'solrengine' ); ?>
								</p>
							<?php else : ?>
								<input
									type="password"
									id="solrengine-password"
									name="password"
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo esc_attr( $this->credentials->has_password() ? __( 'Saved. Type to replace.', 'solrengine' ) : __( 'Not set', 'solrengine' ) ); ?>"
								/>
								<?php if ( $this->credentials->has_password() ) : ?>
									<p>
										<label>
											<input type="checkbox" name="clear_password" value="1"/>
											<?php esc_html_e( 'Remove the saved password', 'solrengine' ); ?>
										</label>
									</p>
								<?php endif; ?>
								<p class="description">
									<?php esc_html_e( 'Stored encrypted. That protects it if your database is exposed, but not if the server itself is compromised, because the key lives in wp-config.php. For stronger protection, define SOLRENGINE_SOLR_USER and SOLRENGINE_SOLR_PASSWORD there instead.', 'solrengine' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Network', 'solrengine' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="allow_private_ip"
									value="1"
									<?php checked( $this->config->get_bool( 'connection.allow_private_ip' ) ); ?>
								/>
								<?php esc_html_e( 'My Solr server is on this machine or a private network', 'solrengine' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Required for addresses like 127.0.0.1 or 10.x.x.x. SolrEngine refuses private addresses unless you confirm, because a plugin that fetches any address an administrator types can be used to probe internal infrastructure. Cloud metadata addresses stay blocked either way.', 'solrengine' ); ?>
							</p>

							<label>
								<input
									type="checkbox"
									name="verify_tls"
									value="1"
									<?php checked( $this->config->get_bool( 'connection.verify_tls', true ) ); ?>
								/>
								<?php esc_html_e( 'Verify TLS certificates', 'solrengine' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Leave this on unless your Solr uses a self-signed certificate you trust.', 'solrengine' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save connection', 'solrengine' ) ); ?>
			</form>

			<?php if ( null !== $tested ) : ?>
				<h2><?php esc_html_e( 'Diagnosis', 'solrengine' ); ?></h2>
				<?php $this->render_report( $tested ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Schema', 'solrengine' ); ?></h2>
			<p>
				<?php esc_html_e( 'If SolrEngine cannot change your schema, or you would rather do it yourself, add the following to your managed-schema file. These are the same changes SolrEngine would make.', 'solrengine' ); ?>
			</p>
			<textarea
				readonly
				rows="14"
				class="large-text code"
				onclick="this.select()"
			><?php echo esc_textarea( $this->exporter->to_xml( $this->contracts->current() ) ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Render the diagnostic report.
	 *
	 * @param DiagnosticReport $report Report to render.
	 * @return void
	 */
	private function render_report( DiagnosticReport $report ): void {
		$marks = array(
			'pass' => '&#10003;',
			'warn' => '&#9888;',
			'fail' => '&#10007;',
		);

		$colours = array(
			'pass' => '#008a20',
			'warn' => '#bd8600',
			'fail' => '#d63638',
		);

		echo '<table class="widefat striped" style="max-width:900px">';
		echo '<tbody>';

		foreach ( $report->checks() as $check ) {
			$status = (string) $check['status'];

			printf(
				'<tr><td style="width:2em;color:%s;font-weight:700">%s</td><td style="width:14em"><strong>%s</strong></td><td>%s',
				esc_attr( $colours[ $status ] ?? 'inherit' ),
				esc_html( html_entity_decode( $marks[ $status ] ?? '', ENT_QUOTES, 'UTF-8' ) ),
				esc_html( (string) $check['label'] ),
				esc_html( (string) $check['detail'] )
			);

			if ( ! empty( $check['context']['operations'] ) ) {
				echo '<ul style="list-style:disc;margin:.5em 0 0 1.5em">';

				foreach ( (array) $check['context']['operations'] as $operation ) {
					echo '<li><code>' . esc_html( (string) $operation ) . '</code></li>';
				}

				echo '</ul>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
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
				. esc_html__( 'Connection settings saved.', 'solrengine' )
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
