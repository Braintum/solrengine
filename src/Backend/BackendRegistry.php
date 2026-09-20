<?php
/**
 * Backend registry.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend;

use SolrEngine\Contracts\IndexBackend;
use SolrEngine\Contracts\LifecycleBackend;
use SolrEngine\Contracts\SchemaBackend;
use SolrEngine\Contracts\SearchBackend;
use SolrEngine\Support\Config;

/**
 * Holds the available backends and reports which one is selected.
 *
 * Public API from 1.0. The Cloud adapter and every add-on register through
 * this, which is what makes "SolrEngine Cloud" a backend choice rather than a
 * paywall — the distinction ADR-010 depends on for WordPress.org compliance.
 *
 * Backends register lazily through factories. Selecting "solr" must not
 * construct the Cloud adapter, and registering a backend must not open a
 * connection.
 *
 * @since 0.1.0
 */
final class BackendRegistry {

	/**
	 * Search backend factories keyed by identifier.
	 *
	 * @var array<string,callable>
	 */
	private $search_factories = array();

	/**
	 * Index backend factories keyed by identifier.
	 *
	 * @var array<string,callable>
	 */
	private $index_factories = array();

	/**
	 * Human-readable labels keyed by identifier.
	 *
	 * @var array<string,string>
	 */
	private $labels = array();

	/**
	 * Resolved instances keyed by "kind:identifier".
	 *
	 * @var array<string,mixed>
	 */
	private $resolved = array();

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Register a backend.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $id             Stable lowercase identifier, for example
	 *                                 "solr". Stored in configuration, so it must
	 *                                 not change once released.
	 * @param string   $label          Human-readable name for the admin UI.
	 * @param callable $search_factory Returns a SearchBackend.
	 * @param callable $index_factory  Returns an IndexBackend.
	 * @return void
	 */
	public function register( string $id, string $label, callable $search_factory, callable $index_factory ): void {
		$this->search_factories[ $id ] = $search_factory;
		$this->index_factories[ $id ]  = $index_factory;
		$this->labels[ $id ]           = $label;
	}

	/**
	 * Whether a backend is registered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Backend identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->search_factories[ $id ] );
	}

	/**
	 * Registered backends as identifier to label.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,string>
	 */
	public function available(): array {
		return $this->labels;
	}

	/**
	 * The selected backend identifier.
	 *
	 * Falls back to the only registered backend when the stored selection is
	 * unknown, which is what happens if an add-on providing a backend is
	 * deactivated while selected. Returning a stale identifier there would make
	 * every call fail with a confusing error instead of degrading to the
	 * backend that is actually present.
	 *
	 * @since 0.1.0
	 *
	 * @return string Identifier, or an empty string when nothing is registered.
	 */
	public function selected_id(): string {
		$configured = $this->config->get_string( 'connection.backend', 'solr' );

		if ( $this->has( $configured ) ) {
			return $configured;
		}

		$ids = array_keys( $this->search_factories );

		return array() === $ids ? '' : (string) $ids[0];
	}

	/**
	 * The backend answering searches, which is usually the selected one.
	 *
	 * Usually, not always. Splitting search from indexing is Principle 2 made
	 * concrete, and §21.1 names the payoff: "a site can index to one backend
	 * while searching another during a migration". That is the entire migration
	 * mechanism — fill the new backend while the old one keeps answering
	 * visitors, then move one setting.
	 *
	 * Without it, moving between backends means a window in which the new index
	 * is half full and serving, which is the same failure a reindex in place has
	 * and which Phase 7 built a whole rebuild mechanism to avoid. This is that
	 * idea one level up.
	 *
	 * @since 0.10.0
	 *
	 * @return string
	 */
	public function search_id(): string {
		$override = $this->config->get_string( 'connection.search_backend', '' );

		if ( '' !== $override && $this->has( $override ) ) {
			return $override;
		}

		return $this->selected_id();
	}

	/**
	 * Whether this site is indexing into one backend and searching another.
	 *
	 * @since 0.10.0
	 *
	 * @return bool
	 */
	public function is_migrating(): bool {
		return $this->search_id() !== $this->selected_id();
	}

	/**
	 * The selected search backend, or null when none is available.
	 *
	 * @since 0.1.0
	 *
	 * @return SearchBackend|null
	 */
	public function search(): ?SearchBackend {
		$id = $this->search_id();

		if ( '' === $id ) {
			return null;
		}

		$instance = $this->resolve( 'search', $id, $this->search_factories );

		return $instance instanceof SearchBackend ? $instance : null;
	}

	/**
	 * The selected index backend, or null when none is available.
	 *
	 * @since 0.1.0
	 *
	 * @return IndexBackend|null
	 */
	public function index(): ?IndexBackend {
		$id = $this->selected_id();

		if ( '' === $id ) {
			return null;
		}

		$instance = $this->resolve( 'index', $id, $this->index_factories );

		return $instance instanceof IndexBackend ? $instance : null;
	}

	/**
	 * The selected schema backend, or null when it does not manage schemas.
	 *
	 * Separate from search() and index() because schema management is the one
	 * capability a backend may legitimately not have: a managed service owns
	 * its own schema, and a locked-down Solr will not permit changes. Callers
	 * that need it must handle null rather than assuming the search backend
	 * also implements it.
	 *
	 * @since 0.1.0
	 *
	 * @return SchemaBackend|null
	 */
	public function schema(): ?SchemaBackend {
		// Follows the *index* backend, not the search one. A schema is applied
		// where documents are written, and during a migration those are two
		// different places — preparing the old backend's schema for content it
		// is no longer receiving would be exactly backwards.
		$id = $this->selected_id();

		if ( '' === $id ) {
			return null;
		}

		$instance = $this->resolve( 'index', $id, $this->index_factories );

		return $instance instanceof SchemaBackend ? $instance : null;
	}

	/**
	 * The selected lifecycle backend, or null when it cannot rebuild.
	 *
	 * Separate for the same reason schema() is: a managed backend rebuilds its
	 * own indexes on its own schedule and would report nothing useful here, and
	 * callers must handle null rather than assuming every backend can build a
	 * replacement index alongside the live one.
	 *
	 * @since 0.8.0
	 *
	 * @return LifecycleBackend|null
	 */
	public function lifecycle(): ?LifecycleBackend {
		$id = $this->selected_id();

		if ( '' === $id ) {
			return null;
		}

		$instance = $this->resolve( 'index', $id, $this->index_factories );

		return $instance instanceof LifecycleBackend ? $instance : null;
	}

	/**
	 * Resolve and memoise one backend instance.
	 *
	 * @param string                 $kind      "search" or "index".
	 * @param string                 $id        Backend identifier.
	 * @param array<string,callable> $factories Factory map to use.
	 * @return mixed
	 */
	private function resolve( string $kind, string $id, array $factories ) {
		$key = $kind . ':' . $id;

		if ( ! array_key_exists( $key, $this->resolved ) ) {
			$this->resolved[ $key ] = isset( $factories[ $id ] ) ? ( $factories[ $id ] )() : null;
		}

		return $this->resolved[ $key ];
	}
}
