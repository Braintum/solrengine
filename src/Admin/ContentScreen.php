<?php
/**
 * What gets indexed, and how it is mapped.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Admin;

use SolrEngine\Contracts\FieldType;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Documents\IndexedTypes;
use SolrEngine\Documents\MetaAllowlist;
use SolrEngine\Documents\MetaKeys;
use SolrEngine\Indexing\ReindexNotice;
use SolrEngine\Support\Config;

/**
 * The screen that stops power users needing the filesystem.
 *
 * Until now, choosing which post types to index and which meta keys to map meant
 * editing an option by hand or writing a filter — which is to say that the two
 * settings that decide what a site's search can find were the two settings with
 * no interface. That is what this screen is for.
 *
 * Three things it is careful about.
 *
 * **The field name is shown, not hidden.** Allowlisting "event_date" as a date
 * produces se_dt_meta_event_date, and an integrator writing a filter or a facet
 * needs that name. Showing it also makes the type decision concrete: the same key
 * declared as text is a different field, and seeing both names side by side
 * explains why changing the type needs a reindex.
 *
 * **A type is chosen, never guessed.** This is the decision Solr's schemaless
 * mode makes by looking at the first value it happens to see, which is why a meta
 * key that usually holds a date and once holds "N/A" breaks indexing permanently
 * under schemaless and loses one value here.
 *
 * **Changing anything here changes nothing already indexed.** Adding a field does
 * not populate it in documents that are already written, so the screen says so
 * and records that a reindex is outstanding rather than letting the setting look
 * as though it took effect.
 *
 * @since 0.8.0
 */
final class ContentScreen extends Screen {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'solrengine-content';

	/**
	 * Form action.
	 *
	 * @var string
	 */
	public const ACTION = 'solrengine_save_content';

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Reindex notice.
	 *
	 * @var ReindexNotice
	 */
	private $notice;

	/**
	 * Constructor.
	 *
	 * @param Config        $config Configuration.
	 * @param ReindexNotice $notice Reindex notice.
	 */
	public function __construct( Config $config, ReindexNotice $notice ) {
		$this->config = $config;
		$this->notice = $notice;
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
		return __( 'Content', 'solrengine' );
	}

	/**
	 * Save the submitted settings.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function handle_save(): void {
		$this->require_manage();
		check_admin_referer( self::ACTION );

		$before_types = $this->configured_types();
		$before_meta  = $this->configured_meta();

		// Read once, here, beside the nonce check that makes reading it
		// legitimate. Everything below works on this array rather than on the
		// superglobal.
		$request = (array) wp_unslash( $_POST );

		$types = array();

		foreach ( (array) ( $request['post_types'] ?? array() ) as $type ) {
			$type = sanitize_key( (string) $type );

			if ( '' !== $type && post_type_exists( $type ) ) {
				$types[] = $type;
			}
		}

		$allowlist = new MetaAllowlist();
		$problems  = array();

		foreach ( self::submitted_meta( $request ) as $key => $type ) {
			$added = $allowlist->add( $key, $type );

			if ( $added->is_failure() ) {
				$problems[] = $added->message();
			}
		}

		$this->config->set( 'index.post_types', array_values( array_unique( $types ) ) );
		$this->config->set( 'index.meta_allowlist', $allowlist->all() );
		$this->config->set( 'index.stock_quantity', ! empty( $request['stock_quantity'] ) );

		// Compared after saving, against what was there before. Any change to
		// either set changes what a document should contain, and none of it
		// reaches a document that is already indexed.
		if ( $before_types !== $this->configured_types() ) {
			$this->notice->require_reindex( __( 'the indexed post types changed', 'solrengine' ) );
		} elseif ( $before_meta !== $allowlist->all() ) {
			$this->notice->require_reindex( __( 'the indexed meta fields changed', 'solrengine' ) );
		}

		// The discovery is cached for an hour, which is right while somebody
		// reads the screen and wrong the moment they act on it: adding a field
		// is usually followed by installing the plugin that writes another one.
		MetaKeys::forget();

		if ( array() !== $problems ) {
			$this->back( 'partial', implode( ' ', $problems ) );
		}

		if ( array() === $types ) {
			// Saved, not refused. Indexing nothing is a legitimate thing to want
			// on a site that is being taken apart, and refusing the save would
			// mean the only way to get there is the database. But it is not what
			// anybody means to do by accident, so it is said out loud.
			$this->back( 'nothing' );
		}

		$this->back( 'saved' );
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

		$configured = $this->configured_types();
		$effective  = IndexedTypes::all( $this->config );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content', 'solrengine' ); ?></h1>

			<?php
			$this->render_notice(
				array(
					'saved'   => array( 'success', __( 'Saved.', 'solrengine' ) ),
					'partial' => array( 'warning', __( 'Saved, but some entries were rejected:', 'solrengine' ) ),
					'nothing' => array( 'warning', __( 'Saved. No post types are selected, so nothing will be indexed.', 'solrengine' ) ),
				)
			);
			?>

			<?php $this->render_reindex_notice(); ?>

			<?php $this->open_form( self::ACTION ); ?>

			<h2><?php esc_html_e( 'What is indexed', 'solrengine' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Post types', 'solrengine' ); ?></th>
					<td>
						<?php foreach ( $this->available_types() as $name => $label ) : ?>
							<p style="margin:.2em 0">
								<label>
									<input
										type="checkbox"
										name="post_types[]"
										value="<?php echo esc_attr( $name ); ?>"
										<?php checked( in_array( $name, $configured, true ) ); ?>
									/>
									<?php echo esc_html( $label ); ?>
									<code><?php echo esc_html( $name ); ?></code>
								</label>
							</p>
						<?php endforeach; ?>

						<?php $added = array_values( array_diff( $effective, $configured ) ); ?>
						<?php if ( array() !== $added ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: comma-separated list of post type names. */
									esc_html__( 'Also indexed, added by an integration rather than by this screen: %s. Removing one means removing the code that adds it.', 'solrengine' ),
									// Escaped one name at a time, because escaping
									// the joined string would print the markup
									// that joins it.
									esc_html( implode( ', ', $added ) )
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Custom fields', 'solrengine' ); ?></h2>

			<p>
				<?php esc_html_e( 'Nothing in a custom field is indexed until you list it here. That is deliberate: custom fields hold licence keys, internal notes and personal data as often as they hold anything worth searching, and a search index is the wrong place for any of them.', 'solrengine' ); ?>
			</p>

			<?php $this->render_meta_table(); ?>

			<?php $this->render_stock_option(); ?>

			<?php submit_button( __( 'Save', 'solrengine' ) ); ?>

			</form>
		</div>
		<?php
	}

	/**
	 * Render the allowlist editor.
	 *
	 * @return void
	 */
	private function render_meta_table(): void {
		$mapped = $this->configured_meta();
		$types  = $this->type_labels();

		// Keys already on the list are removed here rather than skipped while
		// rendering: a datalist is not a loop worth putting a branch inside.
		$discovered = array_diff_key( MetaKeys::discover( 60 ), $mapped );

		?>
		<table class="widefat striped" style="max-width:960px">
			<thead>
				<tr>
					<th scope="col" style="width:26em"><?php esc_html_e( 'Custom field', 'solrengine' ); ?></th>
					<th scope="col" style="width:16em"><?php esc_html_e( 'Holds', 'solrengine' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Index field', 'solrengine' ); ?></th>
					<th scope="col" style="width:6em"><?php esc_html_e( 'Remove', 'solrengine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php $row = 0; ?>
				<?php foreach ( $mapped as $key => $type ) : ?>
					<tr>
						<td>
							<input type="hidden" name="meta[<?php echo esc_attr( (string) $row ); ?>][key]" value="<?php echo esc_attr( (string) $key ); ?>"/>
							<code><?php echo esc_html( (string) $key ); ?></code>
							<?php if ( MetaAllowlist::is_private( (string) $key ) ) : ?>
								<br/><span class="description"><?php esc_html_e( 'A private field. Indexed because you asked for it.', 'solrengine' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<select name="meta[<?php echo esc_attr( (string) $row ); ?>][type]">
								<?php foreach ( $types as $value => $label ) : ?>
									<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $type, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<code><?php echo esc_html( DocumentFields::meta( (string) $type, (string) $key ) ); ?></code>
						</td>
						<td>
							<label>
								<input type="checkbox" name="meta[<?php echo esc_attr( (string) $row ); ?>][remove]" value="1"/>
								<span class="screen-reader-text"><?php esc_html_e( 'Remove this field', 'solrengine' ); ?></span>
							</label>
						</td>
					</tr>
					<?php ++$row; ?>
				<?php endforeach; ?>

				<tr>
					<td>
						<input
							type="text"
							name="meta[new][key]"
							class="regular-text code"
							list="solrengine-meta-keys"
							placeholder="<?php esc_attr_e( 'Add a custom field', 'solrengine' ); ?>"
						/>
						<datalist id="solrengine-meta-keys">
							<?php foreach ( $discovered as $key => $count ) : ?>
								<option value="<?php echo esc_attr( (string) $key ); ?>">
									<?php
									printf(
										/* translators: %s: number of posts using this field. */
										esc_attr__( 'used by %s', 'solrengine' ),
										esc_attr( number_format_i18n( (int) $count ) )
									);
									?>
								</option>
							<?php endforeach; ?>
						</datalist>
						<p class="description">
							<?php esc_html_e( 'Start typing to pick from the fields this site already uses, most used first.', 'solrengine' ); ?>
						</p>
					</td>
					<td>
						<select name="meta[new][type]">
							<?php foreach ( $types as $value => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $value ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td colspan="2">
						<span class="description">
							<?php esc_html_e( 'Choose what the field holds. Getting this wrong does not break indexing — values that do not fit the type are skipped — but a date stored as text cannot be sorted or filtered by range.', 'solrengine' ); ?>
						</span>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the exact-stock option, on a store.
	 *
	 * Here rather than on a WooCommerce screen of its own, because it is the
	 * same kind of decision as the allowlist: it changes what a document holds,
	 * and it needs a reindex to take effect.
	 *
	 * @return void
	 */
	private function render_stock_option(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		?>
		<h2><?php esc_html_e( 'Products', 'solrengine' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Stock levels', 'solrengine' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="stock_quantity"
							value="1"
							<?php checked( $this->config->get_bool( 'index.stock_quantity' ) ); ?>
						/>
						<?php esc_html_e( 'Index the exact number in stock', 'solrengine' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Off by default. Availability is indexed either way, as in stock, low stock or out of stock, which is what a shopper filters by. The exact number changes on every order, so indexing it means rewriting a product document every time one sells.', 'solrengine' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render the outstanding-reindex notice.
	 *
	 * @return void
	 */
	private function render_reindex_notice(): void {
		if ( ! $this->notice->is_required() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: 1: reason a reindex is needed, 2: human time difference. */
					__( 'Content already in the index does not reflect a change made %2$s ago: %1$s. Existing documents are never rewritten by a settings change.', 'solrengine' ),
					$this->notice->reason(),
					human_time_diff( $this->notice->since() )
				)
			),
			esc_url( admin_url( 'admin.php?page=' . ToolsScreen::SLUG ) ),
			esc_html__( 'Reindex now', 'solrengine' )
		);
	}

	/**
	 * The post types this screen offers.
	 *
	 * @return array<string,string>
	 */
	private function available_types(): array {
		$out = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				// Left out on purpose. An attachment's searchable content is the
				// file itself, which needs extraction SolrEngine does not do, so
				// offering it would index a library of titles and nothing else.
				continue;
			}

			$out[ (string) $type->name ] = (string) ( $type->labels->name ?? $type->label );
		}

		return $out;
	}

	/**
	 * The post types stored in configuration, as distinct from the effective set.
	 *
	 * @return string[]
	 */
	private function configured_types(): array {
		return array_values( array_map( 'strval', $this->config->get_array( 'index.post_types', array( 'post', 'page' ) ) ) );
	}

	/**
	 * The stored allowlist.
	 *
	 * @return array<string,string>
	 */
	private function configured_meta(): array {
		return array_map( 'strval', $this->config->get_array( 'index.meta_allowlist', array() ) );
	}

	/**
	 * The allowlist as submitted, with removals applied.
	 *
	 * @param array<string,mixed> $request Unslashed request values.
	 * @return array<string,string>
	 */
	private static function submitted_meta( array $request ): array {
		$out = array();

		foreach ( (array) ( $request['meta'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}

			// Not sanitize_key(): a meta key may legitimately contain uppercase
			// letters and characters sanitize_key() strips, and silently
			// rewriting the key would allowlist a key that does not exist.
			$key  = trim( sanitize_text_field( (string) ( $row['key'] ?? '' ) ) );
			$type = sanitize_key( (string) ( $row['type'] ?? '' ) );

			if ( '' === $key ) {
				continue;
			}

			$out[ $key ] = $type;
		}

		return $out;
	}

	/**
	 * The type choices, described in terms of content rather than of Solr.
	 *
	 * @return array<string,string>
	 */
	private function type_labels(): array {
		return array(
			FieldType::TEXT       => __( 'Words to search', 'solrengine' ),
			FieldType::TEXT_MULTI => __( 'Words to search, several values', 'solrengine' ),
			FieldType::STRING     => __( 'An exact value, to filter or sort by', 'solrengine' ),
			FieldType::STRINGS    => __( 'Exact values, several', 'solrengine' ),
			FieldType::INT        => __( 'A whole number', 'solrengine' ),
			FieldType::INTS       => __( 'Whole numbers, several', 'solrengine' ),
			FieldType::LONG       => __( 'A large whole number', 'solrengine' ),
			FieldType::LONGS      => __( 'Large whole numbers, several', 'solrengine' ),
			FieldType::FLOAT      => __( 'A decimal, such as a price', 'solrengine' ),
			FieldType::DOUBLE     => __( 'A precise decimal', 'solrengine' ),
			FieldType::BOOL       => __( 'Yes or no', 'solrengine' ),
			FieldType::DATE       => __( 'A date', 'solrengine' ),
			FieldType::DATES      => __( 'Dates, several', 'solrengine' ),
		);
	}
}
