<?php
/**
 * Administrative status endpoint.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Rest;

use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Contracts\IndexStatus;
use SolrEngine\Contracts\JobQueue;
use SolrEngine\Diagnostics\ConnectionDiagnostic;
use SolrEngine\Indexing\FullSync;
use SolrEngine\Support\Container;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reports system health to administrators.
 *
 * Administrative, so unlike the search endpoint it has a real permission
 * callback. Returning `__return_true` from an admin route is a common enough
 * mistake that WordPress core ships a warning for it; here the capability is
 * checked explicitly and the route exposes connection details that a visitor
 * has no business seeing.
 *
 * @since 0.4.0
 */
final class StatusController {

	/**
	 * Service container, for services that may not be constructible.
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
	 * Register the route.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			SearchController::NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Report status.
	 *
	 * @since 0.4.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$diagnostic = $this->container->get( 'diagnostics.connection' );

		$backends = $this->container->get( 'backends' );

		$queue = $this->container->get( 'indexing.queue' );

		$sync = $this->container->get( 'indexing.full_sync' );

		$documents = 0;
		$schema    = 0;

		$index = $backends->index();

		if ( null !== $index ) {
			$status = $index->status();

			if ( $status->is_ok() && $status->value() instanceof IndexStatus ) {
				$documents = $status->value()->document_count();
				$schema    = $status->value()->schema_version();
			}
		}

		return new WP_REST_Response(
			array(
				'plugin_version'  => SOLRENGINE_VERSION,
				'schema_contract' => array(
					'required' => SOLRENGINE_SCHEMA_CONTRACT_VERSION,
					'applied'  => $schema,
				),
				'connection'      => $diagnostic->run()->to_array(),
				'index'           => array(
					'documents' => $documents,
					'backend'   => $backends->selected_id(),
				),
				'queue'           => array(
					'driver'    => ( new \ReflectionClass( $queue ) )->getShortName(),
					'available' => $queue->is_available(),
					'pending'   => $queue->pending_count(),
				),
				'sync'            => $sync instanceof FullSync ? $sync->state() : null,
			)
		);
	}
}
