<?php
/**
 * Instant-search fast path.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Rest;

use SolrEngine\Backend\Solr\CurlTransport;
use SolrEngine\Backend\Solr\Endpoint;
use SolrEngine\Contracts\Suggestions;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Search\SuggestPayload;
use SolrEngine\Search\SuggestionParser;
use SolrEngine\Search\SuggestTranslator;
use SolrEngine\Support\Config;
use SolrEngine\Support\Credentials;
use SolrEngine\Support\Encryption;

/**
 * Answers autocomplete without loading the plugin stack.
 *
 * **Why this exists.** {@see SearchController::handle_suggest()} is correct and
 * stays the documented endpoint. It is also reached through `rest_api_init`,
 * which every active plugin uses to register its own routes. Measured on a
 * WooCommerce site with OPcache warm, a suggest request spent 830ms getting to
 * the callback and 67ms answering it: 92% of the latency was other people's
 * plugins booting, on the one request type that fires on every keystroke.
 *
 * No amount of tuning inside the callback fixes a cost paid before it runs, so
 * this endpoint is not reached through the REST API at all. `instant.php` boots
 * WordPress with `SHORTINIT` — database and options, no plugins, no theme, no
 * REST server — and calls this class. That is ~45ms of bootstrap instead of
 * ~830ms.
 *
 * **What is given up, and why it is acceptable here.** No plugin or theme is
 * loaded, so hooks do not fire and integrations do not contribute. An
 * autocomplete list of eight titles is the one surface where that trade reads as
 * a feature: it is a navigational hint, not the search results page. Anything
 * that must respect a site's filters belongs on the REST route, which still
 * answers exactly as before. A site that needs hooks here turns the fast path
 * off and loses only latency.
 *
 * **What is not given up.** The security properties are the request's, not the
 * stack's, so they are all kept: the site scope and the public-visibility
 * filter are injected by {@see SuggestTranslator} and unreachable from the
 * query string; the typed text is bound as a Solr parameter and never becomes
 * syntax; credentials are decrypted through {@see Encryption} from the same
 * salts; the endpoint is the validated one from configuration.
 *
 * @since 0.10.2
 */
final class InstantEndpoint {

	/**
	 * Seconds a browser and any shared cache may reuse a reply.
	 *
	 * Short, because the index changes. Long enough that a visitor deleting
	 * back through what they typed is served from cache rather than from Solr.
	 *
	 * @var int
	 */
	private const CACHE_SECONDS = 30;

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
	 * Constructor.
	 *
	 * @param Config      $config      Configuration.
	 * @param Credentials $credentials Credential store.
	 */
	public function __construct( Config $config, Credentials $credentials ) {
		$this->config      = $config;
		$this->credentials = $credentials;
	}

	/**
	 * Build from configuration, with no container and no backend registry.
	 *
	 * Both of those assume a booted plugin. This endpoint has one job and
	 * resolves the three things it needs directly.
	 *
	 * @since 0.10.2
	 *
	 * @return self
	 */
	public static function create(): self {
		return new self( new Config(), new Credentials( new Encryption() ) );
	}

	/**
	 * Read the request, answer it, and send the response.
	 *
	 * @since 0.10.2
	 *
	 * @param array<string,mixed> $params Request parameters, normally `$_GET`.
	 * @param int                 $site_id Site to search within.
	 * @return void
	 */
	public function handle( array $params, int $site_id ): void {
		$prefix = isset( $params['q'] ) && is_scalar( $params['q'] ) ? (string) $params['q'] : '';
		$limit  = isset( $params['limit'] ) ? (int) $params['limit'] : 0;

		if ( $limit < 1 ) {
			$limit = $this->config->get_int( 'search.instant_limit', 8 );
		}

		$query = new SuggestQuery( $prefix, $site_id, $limit );

		// One character matches most of the index and tells the visitor
		// nothing. Answered without a round trip, exactly as the backend does.
		if ( ! $query->is_long_enough() ) {
			$this->send( new Suggestions( array(), $query->prefix() ) );

			return;
		}

		$endpoint = $this->endpoint();

		if ( null === $endpoint ) {
			// Not configured. An empty list is the honest answer and lets the
			// field carry on working; the REST route reports the misconfiguration
			// to anyone equipped to act on it.
			$this->send( new Suggestions( array(), $query->prefix() ) );

			return;
		}

		$transport = new CurlTransport(
			$endpoint,
			$this->authorization(),
			// Deliberately far below the general transport's 10s. A suggestion
			// that arrives after the visitor has typed two more letters is not
			// a suggestion, so failing fast beats answering late.
			max( 1, (int) ceil( $this->config->get_int( 'search.instant_timeout_ms', 1500 ) / 1000 ) ),
			$this->config->get_bool( 'connection.verify_tls', true )
		);

		$translator = new SuggestTranslator();
		$result     = $transport->get( '/select', $translator->translate( $query ) );

		if ( $result->is_failure() ) {
			// A visitor whose suggestions stop appearing gets a quiet field, not
			// an error in a dropdown. The same choice the REST route makes.
			$this->send( new Suggestions( array(), $query->prefix() ) );

			return;
		}

		$this->send(
			( new SuggestionParser() )->parse(
				(array) $result->value( array() ),
				$query->site_id(),
				$query->prefix()
			)
		);
	}

	/**
	 * Send the JSON response.
	 *
	 * @param Suggestions $suggestions Suggestions to render.
	 * @return void
	 */
	private function send( Suggestions $suggestions ): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			// Private: a suggestion list is scoped to one site's public
			// documents, but caching it in a shared proxy would outlive an
			// unpublish. The browser may reuse it; nothing in between may.
			header( 'Cache-Control: private, max-age=' . self::CACHE_SECONDS );
			header( 'X-Robots-Tag: noindex' );
			// The response is JSON read by fetch(), never a document. Both
			// headers are cheap insurance against a browser deciding otherwise.
			header( 'X-Content-Type-Options: nosniff' );
		}

		echo wp_json_encode( ( new SuggestPayload() )->render( $suggestions ) );
	}

	/**
	 * Build the endpoint from stored configuration.
	 *
	 * Constructed rather than validated. {@see EndpointValidator} resolves DNS,
	 * which is the right thing to do when an administrator saves an address and
	 * the wrong thing to do on every keystroke. The stored address was validated
	 * when it was saved.
	 *
	 * @return Endpoint|null Null when Solr is not configured.
	 */
	private function endpoint(): ?Endpoint {
		$url        = $this->config->get_string( 'connection.endpoint', '' );
		$collection = $this->config->get_string( 'connection.collection', '' );

		if ( '' === $url || '' === $collection ) {
			return null;
		}

		// parse_url(), not wp_parse_url(): the wrapper lives in http.php, which
		// SHORTINIT does not load, and it exists only to patch a PHP bug fixed
		// long before this plugin's 7.4 floor. The value is a stored address that
		// EndpointValidator already accepted, not request input.
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return new Endpoint(
			$scheme,
			strtolower( (string) $parts['host'] ),
			$port,
			rtrim( (string) ( $parts['path'] ?? '/solr' ), '/' ),
			$collection
		);
	}

	/**
	 * Build the Authorization header, or an empty string.
	 *
	 * @return string
	 */
	private function authorization(): string {
		if ( ! $this->credentials->has_password() ) {
			return '';
		}

		$password = $this->credentials->password();

		if ( $password->is_failure() ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'Basic ' . base64_encode( $this->credentials->username() . ':' . $password->value( '' ) );
	}
}
