<?php
/**
 * Instant-search fast path.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Rest;

use SolrEngine\Bootstrap;
use SolrEngine\Contracts\Suggestions;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Search\SearchService;
use SolrEngine\Search\SuggestPayload;
use SolrEngine\Support\Container;
use SolrEngine\Support\CookieViewer;

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
 * REST server — and calls this class.
 *
 * **What it is not.** It is not a second implementation of search. It resolves
 * `search.service` out of {@see Bootstrap::services()} and calls
 * {@see SearchService::suggest()} — the same service, backend, transport,
 * translator and parser the REST route uses, from the same definitions. This
 * class contributes no search logic of its own: it reads the request, names the
 * two services whose definitions assume a full boot, and renders the reply with
 * the shared {@see SuggestPayload}. Everything else about how a suggestion is
 * produced lives where it always did.
 *
 * **What is given up.** No plugin or theme is loaded, so hooks do not fire and
 * integrations do not contribute: `solrengine_suggest_query` and
 * `solrengine_suggestions` have no listeners here, and no taxonomy is registered,
 * so filtered autocomplete is not available and this endpoint accepts no filter
 * parameters. An autocomplete list of eight titles is the one surface where that
 * trade reads as a feature rather than a loss. A site that needs its filters to
 * run during autocomplete turns the fast path off — see
 * `solrengine_instant_fast_path` — and gets the REST route, unchanged.
 *
 * **What is not given up.** The security properties belong to the query, not the
 * bootstrap: the site scope and the public-visibility filter are injected by
 * {@see \SolrEngine\Search\SuggestTranslator} and unreachable from the query
 * string, typed text is bound as a Solr parameter and never becomes syntax, the
 * endpoint is the validated one from configuration, and credentials are decrypted
 * from the same salts. The circuit breaker is shared too, so a backend this path
 * finds broken is a backend the results page also backs off from.
 *
 * @since 0.10.2
 */
final class InstantEndpoint {

	/**
	 * Seconds a browser may reuse a reply.
	 *
	 * Short, because the index changes. Long enough that a visitor deleting back
	 * through what they typed is served from cache rather than from Solr.
	 *
	 * @var int
	 */
	private const CACHE_SECONDS = 30;

	/**
	 * Resolved services.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container with SolrEngine's services.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Build for a SHORTINIT request.
	 *
	 * @since 0.10.2
	 *
	 * @return self
	 */
	public static function create(): self {
		return new self( self::container() );
	}

	/**
	 * SolrEngine's container, adjusted for a process with no plugins or theme.
	 *
	 * The factories come from {@see Bootstrap::services()} unchanged. Two are
	 * replaced, and only because their definitions assume a boot this process
	 * did not perform:
	 *
	 * - `search.taxonomies` reads `get_taxonomies()`, and no taxonomy is
	 *   registered here — nothing has run `init`. An empty list is the truth
	 *   about this process rather than a shortcut, and autocomplete does not
	 *   read it: {@see \SolrEngine\Backend\Solr\SolrBackend} builds its own
	 *   unfiltered {@see \SolrEngine\Search\SuggestTranslator}, and this endpoint
	 *   accepts no filter parameters, so nothing is silently dropped.
	 * - `viewer` asks `is_user_logged_in()`, which needs the user stack. See
	 *   {@see CookieViewer} for what replaces it and why noticing the cookie is
	 *   enough here.
	 *
	 * Everything else — configuration, credentials, endpoint validation, the
	 * transport, the backend registry, the search service — resolves from the
	 * definitions every other request uses.
	 *
	 * @since 0.10.2
	 *
	 * @return Container
	 */
	public static function container(): Container {
		$bootstrap = Bootstrap::instance();
		$container = $bootstrap->services();

		$container->share(
			'search.taxonomies',
			static function (): array {
				return array();
			}
		);

		$container->share(
			'viewer',
			static function (): CookieViewer {
				return new CookieViewer();
			}
		);

		// Normally attached to `init`, which does not run here.
		$bootstrap->register_backends();

		return $container;
	}

	/**
	 * Read the request, answer it, and send the response.
	 *
	 * @since 0.10.2
	 *
	 * @param array<string,mixed> $params  Request parameters, normally `$_GET`.
	 * @param int                 $site_id Site to search within.
	 * @return void
	 */
	public function handle( array $params, int $site_id ): void {
		$prefix = isset( $params['q'] ) && is_scalar( $params['q'] ) ? (string) $params['q'] : '';
		$limit  = isset( $params['limit'] ) ? (int) $params['limit'] : 0;

		if ( $limit < 1 ) {
			$limit = $this->container->get( 'config' )->get_int( 'search.instant_limit', 8 );
		}

		$query  = new SuggestQuery( $prefix, $site_id, $limit );
		$result = $this->container->get( 'search.service' )->suggest( $query );

		if ( $result->is_failure() ) {
			// A visitor whose suggestions stop appearing gets a quiet field, not
			// an error in a dropdown. The same choice the REST route makes, for
			// an unconfigured backend, an open breaker and a Solr that is down
			// alike.
			$this->send( new Suggestions( array(), $query->prefix() ) );

			return;
		}

		$suggestions = $result->value();

		$this->send(
			$suggestions instanceof Suggestions
				? $suggestions
				: new Suggestions( array(), $query->prefix() )
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
			// The response is JSON read by fetch(), never a document.
			header( 'X-Content-Type-Options: nosniff' );
		}

		echo wp_json_encode( ( new SuggestPayload() )->render( $suggestions ) );
	}
}
