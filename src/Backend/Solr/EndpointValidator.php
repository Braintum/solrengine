<?php
/**
 * Validates an administrator-supplied Solr address.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Solr;

use SolrEngine\Contracts\Result;

/**
 * Decides whether an address is safe to send a request to.
 *
 * This is the plugin's main defence against server-side request forgery. An
 * administrator types a URL and the server fetches it, which is SSRF by
 * construction.
 *
 * The usual WordPress answer is wp_safe_remote_get(), and **it cannot be used
 * here**. wp_http_validate_url() rejects loopback and private ranges and
 * permits only ports 80, 443 and 8080 — while the overwhelmingly common Solr
 * deployment is http://127.0.0.1:8983/solr, blocked on both counts. Filtering
 * http_request_host_is_external to allow it would weaken the protection for
 * every other plugin on the site. So SolrEngine validates the address itself,
 * and permits private targets only when an administrator has explicitly said
 * that is what they meant.
 *
 * What this does not fully close: DNS rebinding. The address checked here can
 * differ from the address connected to later. The resolved addresses are
 * recorded on the Endpoint so the transport can compare them at request time,
 * which narrows the window without eliminating it. The attacker also already
 * needs manage_options, which is close to full control of the site. The
 * security documentation states both facts rather than implying completeness.
 *
 * @since 0.1.0
 */
final class EndpointValidator {

	/**
	 * Ports an endpoint may use unless configured otherwise.
	 *
	 * 8983 is Solr's default, 8443 a common TLS termination port, and 80 and
	 * 443 cover reverse proxies. Anything else is far more likely to be a
	 * probe than a Solr server, so it must be opted into.
	 *
	 * @var int[]
	 */
	public const DEFAULT_PORTS = array( 80, 443, 8443, 8983 );

	/**
	 * Addresses that are never reachable, whatever the administrator says.
	 *
	 * Cloud instance metadata services. Reaching one of these yields
	 * credentials for the host's cloud account, so no acknowledgement checkbox
	 * makes it acceptable.
	 *
	 * @var string[]
	 */
	private const ALWAYS_BLOCKED = array(
		'169.254.169.254',
		'fd00:ec2::254',
		'100.100.100.200',
	);

	/**
	 * Ports this validator accepts.
	 *
	 * @var int[]
	 */
	private $allowed_ports;

	/**
	 * Resolves a host name to addresses.
	 *
	 * Injected so the unit tests can exercise every range decision without
	 * touching DNS.
	 *
	 * @var callable
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param int[]         $allowed_ports Ports to accept.
	 * @param callable|null $resolver      Host to address list resolver.
	 */
	public function __construct( array $allowed_ports = array(), ?callable $resolver = null ) {
		$this->allowed_ports = array() === $allowed_ports ? self::DEFAULT_PORTS : $allowed_ports;

		$this->resolver = null !== $resolver
			? $resolver
			: static function ( string $host ): array {
				$addresses = gethostbynamel( $host );

				return is_array( $addresses ) ? $addresses : array();
			};
	}

	/**
	 * Validate an address.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url           Address as the administrator typed it.
	 * @param string $collection    Collection or core name. May be empty.
	 * @param bool   $allow_private Whether the administrator has confirmed that
	 *                              a private or loopback target is intended.
	 * @return Result Success carrying an Endpoint, or a failure whose code
	 *                names the specific rule that rejected it.
	 */
	public function validate( string $url, string $collection = '', bool $allow_private = false ): Result {
		$url = trim( $url );

		if ( '' === $url ) {
			return Result::failure( 'empty_url', __( 'Enter the address of your Solr server.', 'solrengine' ) );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return Result::failure( 'unparseable', __( 'That address could not be understood. It should look like https://solr.example.com:8983/solr', 'solrengine' ) );
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return Result::failure( 'bad_scheme', __( 'The address must start with http:// or https://', 'solrengine' ) );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return Result::failure(
				'credentials_in_url',
				__( 'Remove the username and password from the address. Enter them in the credential fields instead, where they are not written into logs.', 'solrengine' )
			);
		}

		if ( isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return Result::failure( 'not_a_base_url', __( 'The address must not contain a query string or fragment.', 'solrengine' ) );
		}

		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		if ( ! in_array( $port, $this->allowed_ports, true ) ) {
			return Result::failure(
				'port_not_allowed',
				sprintf(
					/* translators: 1: the port, 2: comma-separated list of permitted ports. */
					__( 'Port %1$d is not permitted. Allowed ports are %2$s. A site owner can change this list if the server genuinely listens elsewhere.', 'solrengine' ),
					$port,
					implode( ', ', $this->allowed_ports )
				)
			);
		}

		$addresses = $this->resolve( $host );

		if ( array() === $addresses ) {
			return Result::failure(
				'unresolvable',
				sprintf(
					/* translators: %s: the host name. */
					__( 'The host "%s" could not be resolved. Check the address and your DNS.', 'solrengine' ),
					$host
				)
			);
		}

		foreach ( $addresses as $address ) {
			$verdict = $this->classify( $address );

			if ( 'blocked' === $verdict ) {
				return Result::failure(
					'blocked_address',
					sprintf(
						/* translators: 1: host name, 2: IP address. */
						__( '"%1$s" resolves to %2$s, which SolrEngine will never connect to. Addresses in this range expose cloud credentials or internal infrastructure.', 'solrengine' ),
						$host,
						$address
					)
				);
			}

			if ( 'private' === $verdict && ! $allow_private ) {
				return Result::failure(
					'private_address',
					sprintf(
						/* translators: 1: host name, 2: IP address. */
						__( '"%1$s" resolves to %2$s, a private or local address. This is normal for a Solr server on the same machine or network. Confirm that you meant this to enable it.', 'solrengine' ),
						$host,
						$address
					),
					array( 'needs_acknowledgement' => true )
				);
			}
		}

		if ( 'https' !== $scheme && ! $this->is_loopback_host( $host, $addresses ) && ! $allow_private ) {
			return Result::failure(
				'insecure_scheme',
				__( 'Use https for a Solr server that is not on this machine. Credentials and indexed content would otherwise travel in plain text.', 'solrengine' )
			);
		}

		$base_path = $this->normalise_path( (string) ( $parts['path'] ?? '' ) );
		$name      = trim( $collection );

		if ( '' !== $name && ! $this->is_valid_collection( $name ) ) {
			return Result::failure(
				'bad_collection',
				__( 'The collection name may contain only letters, numbers, underscores, hyphens and dots, and must start with a letter or number.', 'solrengine' )
			);
		}

		return Result::success( new Endpoint( $scheme, $host, $port, $base_path, $name, $addresses ) );
	}

	/**
	 * Normalise the base path so it ends in "/solr".
	 *
	 * Solr 10 requires base URLs to be root paths ending in "/solr", and
	 * administrators routinely paste an address with the collection already
	 * appended, or with a trailing slash, or with nothing at all.
	 *
	 * @param string $path Path as supplied.
	 * @return string Path with a leading slash and no trailing slash.
	 */
	private function normalise_path( string $path ): string {
		$path = '/' . trim( $path, '/' );

		if ( '/' === $path ) {
			return '/solr';
		}

		$segments = explode( '/', trim( $path, '/' ) );
		$solr_at  = array_search( 'solr', $segments, true );

		if ( false !== $solr_at ) {
			// Discard anything after "/solr", which is usually a collection
			// name the administrator pasted along with the address.
			$segments = array_slice( $segments, 0, (int) $solr_at + 1 );

			return '/' . implode( '/', $segments );
		}

		return $path . '/solr';
	}

	/**
	 * Whether a collection name is one Solr and this plugin will accept.
	 *
	 * Rejects rather than sanitises. Stripping the offending characters would
	 * turn "../../admin/collections" into "....admincollections", which is a
	 * valid-looking name that does not exist, so the administrator would see
	 * "collection not found" and have no idea why. Silently rewriting input is
	 * the same mistake the 0.1.0 prototype made by running post content
	 * through sanitize_text_field() before indexing it.
	 *
	 * The leading-character rule also removes any chance of a name that is
	 * purely dots being used to walk the URL path.
	 *
	 * @param string $collection Name as supplied.
	 * @return bool
	 */
	private function is_valid_collection( string $collection ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/', $collection );
	}

	/**
	 * Resolve a host, treating an IP literal as already resolved.
	 *
	 * @param string $host Host name or IP literal.
	 * @return string[]
	 */
	private function resolve( string $host ): array {
		$literal = trim( $host, '[]' );

		if ( false !== filter_var( $literal, FILTER_VALIDATE_IP ) ) {
			return array( $literal );
		}

		return ( $this->resolver )( $host );
	}

	/**
	 * Decide what kind of address this is.
	 *
	 * @param string $address IP address.
	 * @return string One of "blocked", "private" or "public".
	 */
	private function classify( string $address ): string {
		if ( in_array( strtolower( $address ), self::ALWAYS_BLOCKED, true ) ) {
			return 'blocked';
		}

		if ( false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
			return 'blocked';
		}

		// Link-local covers the whole metadata range and is never a Solr server.
		if ( $this->in_v4_range( $address, '169.254.0.0', 16 ) ) {
			return 'blocked';
		}

		if ( $this->is_loopback_address( $address ) ) {
			return 'private';
		}

		$is_private = false === filter_var(
			$address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE
		);

		if ( $is_private ) {
			return 'private';
		}

		// Carrier-grade NAT. Not private by RFC 1918, not routable either.
		if ( $this->in_v4_range( $address, '100.64.0.0', 10 ) ) {
			return 'private';
		}

		// Multicast and broadcast. PHP's NO_RES_RANGE flag does not cover
		// 224.0.0.0/4, which a unit test caught: relying on the flag alone
		// would have let a multicast address through as public.
		if ( $this->in_v4_range( $address, '224.0.0.0', 4 ) || '255.255.255.255' === $address ) {
			return 'blocked';
		}

		if ( 0 === stripos( $address, 'ff' ) && false !== strpos( $address, ':' ) ) {
			// IPv6 multicast, ff00::/8.
			return 'blocked';
		}

		$is_reserved = false === filter_var(
			$address,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_RES_RANGE
		);

		// Unspecified and other reserved ranges. Nothing legitimate here.
		return $is_reserved ? 'blocked' : 'public';
	}

	/**
	 * Whether an address is a loopback address.
	 *
	 * @param string $address IP address.
	 * @return bool
	 */
	private function is_loopback_address( string $address ): bool {
		if ( '::1' === $address ) {
			return true;
		}

		return $this->in_v4_range( $address, '127.0.0.0', 8 );
	}

	/**
	 * Whether the endpoint is on this machine.
	 *
	 * Loopback is the one case where plain http is acceptable without comment:
	 * the traffic never leaves the host.
	 *
	 * @param string   $host      Host name.
	 * @param string[] $addresses Resolved addresses.
	 * @return bool
	 */
	private function is_loopback_host( string $host, array $addresses ): bool {
		if ( 'localhost' === $host ) {
			return true;
		}

		foreach ( $addresses as $address ) {
			if ( ! $this->is_loopback_address( $address ) ) {
				return false;
			}
		}

		return array() !== $addresses;
	}

	/**
	 * Whether an IPv4 address falls inside a CIDR range.
	 *
	 * @param string $address IP address.
	 * @param string $subnet  Range base address.
	 * @param int    $bits    Prefix length.
	 * @return bool
	 */
	private function in_v4_range( string $address, string $subnet, int $bits ): bool {
		$ip   = ip2long( $address );
		$base = ip2long( $subnet );

		if ( false === $ip || false === $base ) {
			return false;
		}

		$mask = -1 << ( 32 - $bits );

		return ( $ip & $mask ) === ( $base & $mask );
	}
}
