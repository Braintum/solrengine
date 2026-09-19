<?php
/**
 * Backend registry.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend;

use SolrEngine\Contracts\IndexBackend;
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
	 * The selected search backend, or null when none is available.
	 *
	 * @since 0.1.0
	 *
	 * @return SearchBackend|null
	 */
	public function search(): ?SearchBackend {
		$id = $this->selected_id();

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
		$id = $this->selected_id();

		if ( '' === $id ) {
			return null;
		}

		$instance = $this->resolve( 'search', $id, $this->search_factories );

		return $instance instanceof SchemaBackend ? $instance : null;
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
