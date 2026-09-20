<?php
/**
 * Schema, reindexing and history.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Admin;

use SolrEngine\Contracts\LifecycleBackend;
use SolrEngine\Contracts\SchemaPlan;
use SolrEngine\Indexing\FullSync;
use SolrEngine\Indexing\Rebuild;
use SolrEngine\Indexing\ReindexNotice;
use SolrEngine\Indexing\RunHistory;
use SolrEngine\Schema\AppliedSchema;
use SolrEngine\Support\Config;
use SolrEngine\Support\Container;

/**
 * The screen for the things that change the index rather than the settings.
 *
 * Three jobs that belong together because they are the same job seen from three
 * angles: what the schema needs, how content gets into the index, and what
 * happened the last fifty times it did.
 *
 * The schema section is the one with teeth. SolrEngine can modify a customer's
 * Solr schema, and ADR-003 turns on the distinction between having that
 * capability and having consent for it: the plan is computed, shown in full,
 * and applied only when somebody presses the button. Blockers are shown as
 * blockers rather than as failures, because an administrator whose schema is
 * read-only still has a route — the exported configuration below it.
 *
 * The rebuild section is where "reindex" stops meaning "make search wrong for
 * two hours". It builds a replacement index alongside the live one, verifies the
 * document count, and switches only then.
 *
 * @since 0.8.0
 */
final class ToolsScreen extends Screen {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'solrengine-tools';

	/**
	 * Schema apply action.
	 *
	 * @var string
	 */
	public const ACTION_SCHEMA = 'solrengine_apply_schema';

	/**
	 * Indexing action.
	 *
	 * @var string
	 */
	public const ACTION_INDEX = 'solrengine_run_index';

	/**
	 * Rebuild action.
	 *
	 * @var string
	 */
	public const ACTION_REBUILD = 'solrengine_run_rebuild';

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Tools', 'solrengine' );
	}

	/**
	 * Apply the pending schema plan.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function handle_schema(): void {
		$this->require_manage();
		check_admin_referer( self::ACTION_SCHEMA );

		$backend = $this->container->get( 'backends' )->schema();

		if ( null === $backend ) {
			$this->back( 'schema_unavailable' );
		}

		$planned = $backend->plan( $this->container->get( 'schema.contracts' )->current() );

		if ( $planned->is_failure() ) {
			$this->back( 'schema_failed', $planned->message() );
		}

		$plan = $planned->value();

		if ( ! $plan instanceof SchemaPlan ) {
			$this->back( 'schema_failed' );
		}

		$applied = $backend->apply( $plan );

		if ( $applied->is_failure() ) {
			$this->back( 'schema_failed', $applied->message() );
		}

		$this->record_schema_state();

		if ( $plan->requires_reindex() ) {
			// A new field or copy rule holds nothing until each document is
			// written again. Solr does not backfill, so saying the schema is
			// up to date without saying this would be half the truth.
			$this->reindex_notice()->require_reindex( __( 'the Solr schema gained fields that existing documents do not have', 'solrengine' ) );
		}

		$this->back( 'schema_applied' );
	}

	/**
	 * Start, cancel or resume a full index.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function handle_index(): void {
		$this->require_manage();
		check_admin_referer( self::ACTION_INDEX );

		$sync = $this->container->get( 'indexing.full_sync' );

		if ( ! $sync instanceof FullSync ) {
			$this->back( 'no_backend' );
		}

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';

		switch ( $mode ) {
			case 'cancel':
				$result = $sync->cancel();
				break;
			case 'resume':
				$result = $sync->resume();
				break;
			default:
				$result = $sync->start();
				break;
		}

		if ( $result->is_failure() ) {
			$this->back( 'index_failed', $result->message() );
		}

		$this->back( 'cancel' === $mode ? 'index_cancelled' : 'index_started' );
	}

	/**
	 * Drive the rebuild state machine.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function handle_rebuild(): void {
		$this->require_manage();
		check_admin_referer( self::ACTION_REBUILD );

		$rebuild = $this->container->get( 'indexing.rebuild' );

		if ( ! $rebuild instanceof Rebuild ) {
			$this->back( 'no_backend' );
		}

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

		switch ( $step ) {
			case 'verify':
				$result = $rebuild->verify();
				break;
			case 'switch':
				$result = $rebuild->switch_over();
				break;
			case 'discard':
				$result = $rebuild->discard();
				break;
			case 'drop':
				$result = $rebuild->drop_previous();
				break;
			case 'dismiss':
				$rebuild->dismiss();

				$this->back( 'rebuild_dismissed' );

				return;
			default:
				$result = $rebuild->start(
					isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : ''
				);
				break;
		}

		if ( $result->is_failure() ) {
			$this->back( 'rebuild_failed', $result->message() );
		}

		$this->back( 'rebuild_' . ( '' === $step ? 'started' : $step ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->can_manage() ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tools', 'solrengine' ); ?></h1>

			<?php
			$this->render_notice(
				array(
					'schema_applied'     => array( 'success', __( 'The schema was updated.', 'solrengine' ) ),
					'schema_failed'      => array( 'error', __( 'The schema could not be updated.', 'solrengine' ) ),
					'schema_unavailable' => array( 'error', __( 'This backend does not expose schema management.', 'solrengine' ) ),
					'index_started'      => array( 'success', __( 'Indexing has started. It continues in the background; you can leave this page.', 'solrengine' ) ),
					'index_cancelled'    => array( 'success', __( 'Indexing was cancelled. What has already been indexed stays in the index.', 'solrengine' ) ),
					'index_failed'       => array( 'error', __( 'Indexing could not start.', 'solrengine' ) ),
					'no_backend'         => array( 'error', __( 'Solr is not reachable. Check the connection settings.', 'solrengine' ) ),
					'rebuild_started'    => array( 'success', __( 'The replacement index is being built. Searches are still answered by the current one.', 'solrengine' ) ),
					'rebuild_verify'     => array( 'success', __( 'Checked.', 'solrengine' ) ),
					'rebuild_switch'     => array( 'success', __( 'Searches are now answered by the new index. The previous one is still there if you need to go back.', 'solrengine' ) ),
					'rebuild_discard'    => array( 'success', __( 'The rebuild was discarded.', 'solrengine' ) ),
					'rebuild_drop'       => array( 'success', __( 'The previous index was deleted.', 'solrengine' ) ),
					'rebuild_dismissed'  => array( 'success', __( 'Dismissed.', 'solrengine' ) ),
					'rebuild_failed'     => array( 'error', __( 'The rebuild could not continue.', 'solrengine' ) ),
				)
			);
			?>

			<?php $this->render_schema_section(); ?>
			<?php $this->render_index_section(); ?>
			<?php $this->render_rebuild_section(); ?>
			<?php $this->render_history_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render the schema comparison and apply control.
	 *
	 * @return void
	 */
	private function render_schema_section(): void {
		$contracts = $this->container->get( 'schema.contracts' );
		$exporter  = $this->container->get( 'schema.exporter' );
		$contract  = $contracts->current();
		$backend   = $this->container->get( 'backends' )->schema();

		echo '<h2>' . esc_html__( 'Schema', 'solrengine' ) . '</h2>';

		if ( null === $backend ) {
			echo '<p>' . esc_html__( 'Solr is not reachable, so its schema cannot be compared. The configuration SolrEngine needs is below.', 'solrengine' ) . '</p>';
			$this->render_schema_export( $exporter->to_xml( $contract ) );

			return;
		}

		if ( ! $backend->is_manageable() ) {
			// A managed backend owns its own schema, and §14.4 asks the UI to
			// adapt to that rather than to the backend's name. Showing a plan
			// that cannot be applied, or an export nobody can paste anywhere,
			// would be offering work that does nothing.
			//
			// Until 0.10.0 nothing called this method, so the path existed only
			// as prose in a contract. A second adapter is what made it real.
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html__( 'This backend manages its own schema. There is nothing to check or apply from here, and nothing you need to do.', 'solrengine' )
			);

			return;
		}

		$planned = $backend->plan( $contract );

		if ( $planned->is_failure() ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: reason from the server. */
						__( 'The schema could not be read: %s', 'solrengine' ),
						$planned->message()
					)
				)
			);

			$this->render_schema_export( $exporter->to_xml( $contract ) );

			return;
		}

		$plan = $planned->value();

		$this->render_schema_verdict( $plan );

		if ( array() !== $plan->blockers() ) {
			echo '<div class="notice notice-warning inline"><p><strong>'
				. esc_html__( 'SolrEngine will not apply these changes itself:', 'solrengine' )
				. '</strong></p><ul style="list-style:disc;margin:0 0 1em 2em">';

			foreach ( $plan->blockers() as $blocker ) {
				echo '<li>' . esc_html( $blocker ) . '</li>';
			}

			echo '</ul></div>';
		}

		if ( ! $plan->is_empty() ) {
			echo '<p>' . esc_html__( 'The following would be added. Nothing is changed or removed — SolrEngine only ever adds fields, and never replaces one it did not create.', 'solrengine' ) . '</p>';
			echo '<ul style="list-style:disc;margin:0 0 1em 2em">';

			foreach ( $plan->describe() as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}

			echo '</ul>';
		}

		if ( $plan->is_applicable() ) {
			$this->open_form( self::ACTION_SCHEMA );

			printf(
				'<p><button type="submit" class="button button-primary">%s</button></p>',
				esc_html(
					sprintf(
						/* translators: %d: number of changes. */
						_n( 'Apply %d change', 'Apply %d changes', count( $plan->operations() ), 'solrengine' ),
						count( $plan->operations() )
					)
				)
			);

			echo '</form>';
		}

		$this->render_schema_export( $exporter->to_xml( $contract ) );
	}

	/**
	 * Render what is known about the applied schema.
	 *
	 * @param SchemaPlan $plan Current plan.
	 * @return void
	 */
	private function render_schema_verdict( SchemaPlan $plan ): void {
		$applied = new AppliedSchema( $this->container->get( 'config' ) );
		$backend = $this->container->get( 'backends' )->schema();
		$live    = null === $backend ? null : $backend->applied_version();

		$verdict = $applied->compare(
			SOLRENGINE_SCHEMA_CONTRACT_VERSION,
			null !== $live && $live->is_ok() ? (int) $live->value( 0 ) : 0,
			$this->live_fingerprint()
		);

		$messages = array(
			AppliedSchema::CURRENT    => array(
				'notice-success',
				__( 'The schema is up to date and unchanged since SolrEngine last checked it.', 'solrengine' ),
			),
			AppliedSchema::BEHIND     => array(
				'notice-warning',
				__( 'The schema does not yet have everything this version of SolrEngine needs.', 'solrengine' ),
			),
			AppliedSchema::UNRECORDED => array(
				'notice-info',
				__( 'The schema has what SolrEngine needs, but SolrEngine did not put it there — it was applied by hand, or the collection was prepared before the plugin was installed. That is fine; there is simply nothing to compare future changes against yet.', 'solrengine' ),
			),
			AppliedSchema::ALTERED    => array(
				'notice-warning',
				__( 'The fields SolrEngine owns are not the ones it last saw. Somebody has changed the schema. Indexing may fail in ways the error messages do not explain.', 'solrengine' ),
			),
		);

		list( $class, $message ) = $messages[ $verdict ] ?? $messages[ AppliedSchema::UNRECORDED ];

		printf(
			'<div class="notice %s inline"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: required contract version, 2: when it was last verified. */
					__( 'Contract version %1$d required. %2$s', 'solrengine' ),
					SOLRENGINE_SCHEMA_CONTRACT_VERSION,
					$applied->verified_at() > 0
						? sprintf(
							/* translators: %s: human time difference. */
							__( 'Last verified %s ago.', 'solrengine' ),
							human_time_diff( $applied->verified_at() )
						)
						: __( 'Never verified from this site.', 'solrengine' )
				)
			)
		);

		if ( $plan->requires_reindex() && ! $plan->is_empty() ) {
			echo '<p class="description">'
				. esc_html__( 'Applying these changes will need a reindex afterwards: Solr never rewrites documents that are already indexed, so a field added today stays empty until each document is written again.', 'solrengine' )
				. '</p>';
		}
	}

	/**
	 * Render the exported configuration.
	 *
	 * @param string $xml Schema fragment.
	 * @return void
	 */
	private function render_schema_export( string $xml ): void {
		?>
		<h3><?php esc_html_e( 'Apply it yourself', 'solrengine' ); ?></h3>
		<p>
			<?php esc_html_e( 'If SolrEngine cannot change your schema, or you would rather do it by hand, add the following to your managed-schema file. These are the same changes it would make.', 'solrengine' ); ?>
		</p>
		<textarea readonly rows="12" class="large-text code" onclick="this.select()"><?php echo esc_textarea( $xml ); ?></textarea>
		<?php
	}

	/**
	 * Render the full reindex control.
	 *
	 * @return void
	 */
	private function render_index_section(): void {
		$sync   = $this->container->get( 'indexing.full_sync' );
		$notice = $this->reindex_notice();

		echo '<h2>' . esc_html__( 'Reindex', 'solrengine' ) . '</h2>';

		if ( $notice->is_required() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: reason, 2: human time difference. */
						__( 'A reindex is outstanding: %1$s, %2$s ago.', 'solrengine' ),
						$notice->reason(),
						human_time_diff( $notice->since() )
					)
				)
			);
		}

		if ( ! $sync instanceof FullSync ) {
			echo '<p>' . esc_html__( 'Solr is not reachable, so indexing cannot start.', 'solrengine' ) . '</p>';

			return;
		}

		$run = $sync->state();

		if ( null !== $run ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: run state, 2: processed count, 3: expected total, 4: failure count. */
						__( 'Last run: %1$s — %2$s of about %3$s objects, %4$s failed.', 'solrengine' ),
						(string) $run['state'],
						number_format_i18n( (int) $run['processed'] ),
						number_format_i18n( (int) $run['total'] ),
						number_format_i18n( (int) $run['failed'] )
					)
				)
			);
		}

		$running = null !== $run && 'running' === $run['state'];

		$this->open_form( self::ACTION_INDEX );

		if ( $running ) {
			echo '<input type="hidden" name="mode" value="cancel"/>';
			submit_button( __( 'Stop indexing', 'solrengine' ), 'secondary', 'submit', false );
		} else {
			printf( '<input type="hidden" name="mode" value="%s"/>', esc_attr( null !== $run && 'complete' !== $run['state'] ? 'resume' : 'start' ) );
			submit_button(
				null !== $run && 'complete' !== $run['state']
					? __( 'Resume indexing', 'solrengine' )
					: __( 'Index everything now', 'solrengine' ),
				'secondary',
				'submit',
				false
			);
		}

		echo '</form>';

		echo '<p class="description" style="margin-top:1em">'
			. esc_html__( 'A reindex writes over the live index while it runs, so searches are answered from a partly rebuilt index until it finishes. On a large site, build a replacement index instead.', 'solrengine' )
			. '</p>';

		echo '<p class="description">'
			. esc_html__( 'On the command line, where there is no web-server timeout to fight:', 'solrengine' )
			. '</p><pre class="code" style="padding:1em;background:#f6f7f7;max-width:820px"><code>wp solrengine index</code></pre>';
	}

	/**
	 * Render the zero-downtime rebuild section.
	 *
	 * @return void
	 */
	private function render_rebuild_section(): void {
		$rebuild = $this->container->get( 'indexing.rebuild' );

		echo '<h2>' . esc_html__( 'Build a replacement index', 'solrengine' ) . '</h2>';

		if ( ! $rebuild instanceof Rebuild ) {
			echo '<p>' . esc_html__( 'Not available: Solr is not reachable.', 'solrengine' ) . '</p>';

			return;
		}

		$state = $rebuild->state();

		if ( null !== $state ) {
			$this->render_rebuild_progress( $state );

			return;
		}

		$planned = $rebuild->plan();

		if ( $planned->is_failure() ) {
			printf( '<p>%s</p>', esc_html( $planned->message() ) );

			return;
		}

		$plan = (array) $planned->value( array() );

		echo '<p>' . esc_html__( 'Content is indexed into a second index while the current one keeps answering searches. Nothing switches over until the new index has been checked against what WordPress holds, and the old one is kept afterwards so you can go back.', 'solrengine' ) . '</p>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				LifecycleBackend::STYLE_ALIAS === $plan['style']
					? sprintf(
						/* translators: %s: alias name. */
						__( 'Your Solr is in SolrCloud mode and "%s" is an alias, so the switch is one atomic operation on the server and no request sees a half-switched index.', 'solrengine' ),
						(string) $plan['live']
					)
					: __( 'SolrEngine will switch the collection it is configured to use. One request may be answered by the old index while the next is answered by the new one — a far shorter window than reindexing in place, and reversible from here.', 'solrengine' )
			)
		);

		$this->open_form( self::ACTION_REBUILD );

		if ( (bool) $plan['can_create'] ) {
			printf(
				'<p>%s <code>%s</code></p>',
				esc_html__( 'SolrEngine will create:', 'solrengine' ),
				esc_html( (string) $plan['suggested'] )
			);
		} else {
			printf(
				'<p><label for="solrengine-rebuild-target">%s</label><br/><input type="text" id="solrengine-rebuild-target" name="target" class="regular-text code" required/></p><p class="description">%s</p>',
				esc_html__( 'Name of an empty core to build into', 'solrengine' ),
				esc_html__( 'This Solr is not in SolrCloud mode, so SolrEngine cannot create a core for you. Create one on the server with the same configuration as the current one, then name it here.', 'solrengine' )
			);

			if ( array() !== (array) $plan['existing'] ) {
				$names = array();

				foreach ( (array) $plan['existing'] as $name ) {
					// Escaped one at a time. Escaping the joined string would
					// escape the markup joining it, and print the tags.
					$names[] = '<code>' . esc_html( (string) $name ) . '</code>';
				}

				printf(
					'<p class="description">%s %s</p>',
					esc_html__( 'Cores on this server:', 'solrengine' ),
					implode( ', ', $names ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each name escaped above.
				);
			}
		}

		submit_button( __( 'Start building', 'solrengine' ), 'secondary', 'submit', false );

		echo '</form>';
	}

	/**
	 * Render an in-progress or finished rebuild.
	 *
	 * @param array<string,mixed> $state Rebuild state.
	 * @return void
	 */
	private function render_rebuild_progress( array $state ): void {
		printf(
			'<table class="widefat striped" style="max-width:820px"><tbody>%s%s%s</tbody></table>',
			sprintf(
				'<tr><td style="width:14em"><strong>%s</strong></td><td><code>%s</code></td></tr>',
				esc_html__( 'Building into', 'solrengine' ),
				esc_html( (string) $state['target'] )
			),
			sprintf(
				'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
				esc_html__( 'State', 'solrengine' ),
				esc_html( (string) $state['state'] )
			),
			sprintf(
				'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
				esc_html__( 'Documents', 'solrengine' ),
				esc_html(
					sprintf(
						/* translators: 1: documents in the new index, 2: documents expected. */
						__( '%1$s of about %2$s', 'solrengine' ),
						number_format_i18n( (int) $state['actual'] ),
						number_format_i18n( (int) $state['expected'] )
					)
				)
			)
		);

		if ( '' !== (string) $state['message'] ) {
			printf( '<p>%s</p>', esc_html( (string) $state['message'] ) );
		}

		echo '<p>';

		if ( 'switched' === $state['state'] ) {
			if ( '' !== (string) $state['previous'] ) {
				$this->rebuild_button( 'drop', __( 'Delete the previous index', 'solrengine' ) );
			}

			$this->rebuild_button( 'dismiss', __( 'Done', 'solrengine' ) );

			echo '</p><p class="description">'
				. esc_html__( 'The previous index is not deleted automatically, and will not be. It is the way back if something turns out to be wrong two days from now, and a plugin deleting a customer\'s index on a timer is not a thing this one does.', 'solrengine' )
				. '</p>';

			return;
		}

		if ( 'ready' === $state['state'] ) {
			$this->rebuild_button( 'switch', __( 'Switch searches to the new index', 'solrengine' ), true );
		} else {
			$this->rebuild_button( 'verify', __( 'Check progress', 'solrengine' ) );
		}

		$this->rebuild_button( 'discard', __( 'Discard this rebuild', 'solrengine' ) );

		echo '</p>';
	}

	/**
	 * Render one rebuild step button, each in its own form.
	 *
	 * One form per button rather than one form with several: a form with two
	 * submit buttons submits the first one when somebody presses Enter, and the
	 * first one here would be "switch".
	 *
	 * @param string $step    Step name.
	 * @param string $label   Button label.
	 * @param bool   $primary Whether it is the primary action.
	 * @return void
	 */
	private function rebuild_button( string $step, string $label, bool $primary = false ): void {
		echo '<span style="margin-right:.5em;display:inline-block">';

		$this->open_form( self::ACTION_REBUILD );

		printf( '<input type="hidden" name="step" value="%s"/>', esc_attr( $step ) );

		submit_button( $label, $primary ? 'primary' : 'secondary', 'submit', false );

		echo '</form></span>';
	}

	/**
	 * Render the run history.
	 *
	 * @return void
	 */
	private function render_history_section(): void {
		$history = $this->container->get( 'indexing.history' );

		echo '<h2>' . esc_html__( 'History', 'solrengine' ) . '</h2>';

		if ( ! $history instanceof RunHistory ) {
			return;
		}

		$runs = $history->recent( 15 );

		if ( array() === $runs ) {
			echo '<p>' . esc_html__( 'Nothing has been indexed from this site yet.', 'solrengine' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1000px"><thead><tr>'
				. '<th scope="col">' . esc_html__( 'Started', 'solrengine' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Kind', 'solrengine' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Result', 'solrengine' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Indexed', 'solrengine' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Failed', 'solrengine' ) . '</th>'
				. '<th scope="col">' . esc_html__( 'Took', 'solrengine' ) . '</th>'
				. '</tr></thead><tbody>';

			foreach ( $runs as $run ) {
				$finished = (int) $run['finished_at'];
				$started  = (int) $run['started_at'];

				$when = wp_date( 'Y-m-d H:i', $started );

				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( is_string( $when ) ? $when : '' ),
					esc_html( (string) $run['kind'] . ( '' !== (string) $run['target'] ? ' → ' . (string) $run['target'] : '' ) ),
					esc_html( (string) $run['state'] ),
					esc_html( number_format_i18n( (int) $run['written'] ) ),
					esc_html( number_format_i18n( (int) $run['failed'] ) ),
					esc_html(
						$finished > $started
							? human_time_diff( $started, $finished )
							: '—'
					)
				);

				if ( '' !== (string) $run['message'] ) {
					printf(
						'<tr><td colspan="6" class="description">%s</td></tr>',
						esc_html( (string) $run['message'] )
					);
				}
			}

			echo '</tbody></table>';
		}

		$this->render_failures( $history );
	}

	/**
	 * Render the most recent per-object failures.
	 *
	 * @param RunHistory $history Run history.
	 * @return void
	 */
	private function render_failures( RunHistory $history ): void {
		$failures = $history->failures( 0, 20 );

		if ( array() === $failures ) {
			return;
		}

		echo '<h3>' . esc_html__( 'What failed', 'solrengine' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width:1000px"><tbody>';

		foreach ( $failures as $failure ) {
			$id    = (int) $failure['object_id'];
			$title = $id > 0 ? get_the_title( $id ) : '';

			printf(
				'<tr><td style="width:24em">%s</td><td>%s</td></tr>',
				'' === $title
					? esc_html( (string) $failure['object_type'] . ' ' . $id )
					: sprintf(
						'<a href="%s">%s</a>',
						esc_url( (string) get_edit_post_link( $id ) ),
						esc_html( $title )
					),
				esc_html( (string) $failure['message'] )
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">'
			. esc_html__( 'At most a hundred failures are recorded per run. The count in the table above is the real total.', 'solrengine' )
			. '</p>';
	}

	/**
	 * Record the schema state after an apply.
	 *
	 * @return void
	 */
	private function record_schema_state(): void {
		$backend = $this->container->get( 'backends' )->schema();

		if ( null === $backend ) {
			return;
		}

		$version = $backend->applied_version();

		( new AppliedSchema( $this->container->get( 'config' ) ) )->record(
			$version->is_ok() ? (int) $version->value( 0 ) : 0,
			$this->live_fingerprint()
		);
	}

	/**
	 * The fingerprint of the live schema's SolrEngine fields.
	 *
	 * @return string
	 */
	private function live_fingerprint(): string {
		$backend = $this->container->get( 'backends' )->schema();

		if ( null === $backend ) {
			return '';
		}

		$state = $backend->describe();

		if ( $state->is_failure() ) {
			return '';
		}

		return $this->container->get( 'schema.planner' )->fingerprint( $state->value() );
	}

	/**
	 * The reindex notice.
	 *
	 * @return ReindexNotice
	 */
	private function reindex_notice(): ReindexNotice {
		$notice = $this->container->get( 'indexing.reindex_notice' );

		return $notice instanceof ReindexNotice
			? $notice
			: new ReindexNotice( $this->container->get( 'config' ) );
	}
}
