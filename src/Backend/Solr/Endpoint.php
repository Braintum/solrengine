<?php
/**
 * A validated Solr endpoint.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Solr;

/**
 * Where Solr is, in a form that cannot be malformed.
 *
 * Only EndpointValidator constructs these, so holding one is proof that the
 * address passed every check in the security model. Code that builds requests
 * therefore never has to re-validate, and cannot accidentally skip validation.
 *
 * The base URL is immutable. The 0.1.0 prototype mutated its endpoint string on
 * every index call by appending the core name, so the address degraded to
 * ".../solr/post/post/post/..." and indexing failed after the first document.
 * Paths are built per request here, from a base that never changes.
 *
 * @since 0.1.0
 */
final class Endpoint {

	/**
	 * Scheme, "http" or "https".
	 *
	 * @var string
	 */
	private $scheme;

	/**
	 * Host name or IP literal.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Port.
	 *
	 * @var int
	 */
	private $port;

	/**
	 * Base path, always ending in "/solr" and never in a slash.
	 *
	 * @var string
	 */
	private $base_path;

	/**
	 * Collection or core name. May be empty before one is chosen.
	 *
	 * @var string
	 */
	private $collection;

	/**
	 * Addresses the host resolved to at validation time.
	 *
	 * @var string[]
	 */
	private $resolved_ips;

	/**
	 * Constructor.
	 *
	 * @param string   $scheme       Scheme.
	 * @param string   $host         Host.
	 * @param int      $port         Port.
	 * @param string   $base_path    Base path ending in "/solr".
	 * @param string   $collection   Collection or core name.
	 * @param string[] $resolved_ips Addresses seen at validation time.
	 */
	public function __construct(
		string $scheme,
		string $host,
		int $port,
		string $base_path,
		string $collection = '',
		array $resolved_ips = array()
	) {
		$this->scheme       = $scheme;
		$this->host         = $host;
		$this->port         = $port;
		$this->base_path    = $base_path;
		$this->collection   = $collection;
		$this->resolved_ips = $resolved_ips;
	}

	/**
	 * The base URL, ending in "/solr" with no trailing slash.
	 *
	 * Solr 10 requires base URLs to be root paths ending in "/solr".
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function base_url(): string {
		return sprintf( '%s://%s:%d%s', $this->scheme, $this->host, $this->port, $this->base_path );
	}

	/**
	 * A URL for an administrative path, outside any collection.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path relative to the base, with a leading slash.
	 * @return string
	 */
	public function admin_url( string $path ): string {
		return $this->base_url() . '/' . ltrim( $path, '/' );
	}

	/**
	 * A URL for a path within the configured collection.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path relative to the collection, with a leading slash.
	 * @return string
	 */
	public function collection_url( string $path ): string {
		return $this->base_url() . '/' . $this->collection . '/' . ltrim( $path, '/' );
	}

	/**
	 * Scheme.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function scheme(): string {
		return $this->scheme;
	}

	/**
	 * Host.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function host(): string {
		return $this->host;
	}

	/**
	 * Port.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function port(): int {
		return $this->port;
	}

	/**
	 * Collection or core name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function collection(): string {
		return $this->collection;
	}

	/**
	 * Whether the connection is encrypted.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_secure(): bool {
		return 'https' === $this->scheme;
	}

	/**
	 * Addresses the host resolved to when it was validated.
	 *
	 * Kept so the transport can compare them against what the host resolves to
	 * at request time. That narrows the DNS rebinding window without closing
	 * it; see the security documentation, which says so plainly rather than
	 * implying the problem is solved.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function resolved_ips(): array {
		return $this->resolved_ips;
	}

	/**
	 * The same endpoint pointed at a different collection.
	 *
	 * @since 0.1.0
	 *
	 * @param string $collection Collection or core name.
	 * @return self
	 */
	public function with_collection( string $collection ): self {
		return new self(
			$this->scheme,
			$this->host,
			$this->port,
			$this->base_path,
			$collection,
			$this->resolved_ips
		);
	}

	/**
	 * A description safe to show an administrator or write to a log.
	 *
	 * Never contains credentials, because credentials are never part of an
	 * Endpoint: the validator rejects any URL carrying them.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function describe(): string {
		return '' === $this->collection
			? $this->base_url()
			: $this->base_url() . '/' . $this->collection;
	}
}
