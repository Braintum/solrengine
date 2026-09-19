<?php
/**
 * Connection diagnostic.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Diagnostics;

use SolrEngine\Backend\Solr\Endpoint;
use SolrEngine\Backend\Solr\EndpointValidator;
use SolrEngine\Backend\Solr\HttpTransport;
use SolrEngine\Backend\Solr\SolrBackend;
use SolrEngine\Contracts\Logger;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaExporter;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Support\Config;
use SolrEngine\Support\Credentials;

/**
 * Answers "is this working?" with a list of independent facts.
 *
 * One boolean would be useless here. "Cannot connect" covers a firewall, a
 * wrong port, a wrong password, a missing collection and a schema without the
 * Schema API — five problems with five different remedies. So the diagnostic
 * reports each check separately and keeps going where it usefully can, which
 * is what the plan's section 10.5 asks for.
 *
 * Checks stop early only where continuing is meaningless: there is nothing to
 * learn about a collection on a host that does not resolve.
 *
 * Named "Diagnostic" rather than "ConnectionTest" because the latter matches
 * the universal PHPUnit *Test.php convention, and production code that looks
 * like a test file misleads readers, packaging globs and coverage configuration.
 *
 * @since 0.1.0
 */
final class ConnectionDiagnostic {

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
	 * Endpoint validator.
	 *
	 * @var EndpointValidator
	 */
	private $validator;

	/**
	 * Contract registry.
	 *
	 * @var ContractRegistry
	 */
	private $contracts;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Config            $config      Configuration.
	 * @param Credentials       $credentials Credential store.
	 * @param EndpointValidator $validator   Endpoint validator.
	 * @param ContractRegistry  $contracts   Contract registry.
	 * @param Logger            $logger      Logger.
	 */
	public function __construct(
		Config $config,
		Credentials $credentials,
		EndpointValidator $validator,
		ContractRegistry $contracts,
		Logger $logger
	) {
		$this->config      = $config;
		$this->credentials = $credentials;
		$this->validator   = $validator;
		$this->contracts   = $contracts;
		$this->logger      = $logger;
	}

	/**
	 * Run the diagnostic against the stored configuration.
	 *
	 * @since 0.1.0
	 *
	 * @return DiagnosticReport
	 */
	public function run(): DiagnosticReport {
		return $this->run_for(
			$this->config->get_string( 'connection.endpoint' ),
			$this->config->get_string( 'connection.collection' ),
			$this->config->get_bool( 'connection.allow_private_ip' )
		);
	}

	/**
	 * Run the diagnostic against a specific address.
	 *
	 * Used by the settings screen so an administrator can test before saving,
	 * rather than having to save a broken address to find out it is broken.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url           Address to test.
	 * @param string $collection    Collection name.
	 * @param bool   $allow_private Whether private addresses are acknowledged.
	 * @return DiagnosticReport
	 */
	public function run_for( string $url, string $collection, bool $allow_private ): DiagnosticReport {
		$report = new DiagnosticReport();

		$endpoint = $this->validator->validate( $url, $collection, $allow_private );

		if ( $endpoint->is_failure() ) {
			$report->fail(
				'address',
				__( 'Address', 'solrengine' ),
				$endpoint->message(),
				$endpoint->code(),
				$endpoint->context()
			);

			return $report;
		}

		$target = $endpoint->value();

		$report->pass(
			'address',
			__( 'Address', 'solrengine' ),
			sprintf(
				/* translators: %s: the normalised Solr base URL. */
				__( 'Valid. SolrEngine will use %s', 'solrengine' ),
				$target->describe()
			)
		);

		if ( ! $target->is_secure() ) {
			$report->warn(
				'transport',
				__( 'Transport security', 'solrengine' ),
				__( 'This connection is not encrypted. That is acceptable on the same machine, but credentials and content would travel in plain text over any network.', 'solrengine' )
			);
		}

		$backend = $this->backend_for( $target );

		$reachable = $backend->capabilities();

		if ( '' === $reachable->version() ) {
			$report->fail(
				'reachable',
				__( 'Server', 'solrengine' ),
				__( 'No Solr server answered at that address. Check that it is running, and that a firewall is not in the way.', 'solrengine' ),
				'unreachable'
			);

			return $report;
		}

		$report->pass(
			'reachable',
			__( 'Server', 'solrengine' ),
			sprintf(
				/* translators: 1: Solr version, 2: deployment mode. */
				__( 'Solr %1$s, %2$s mode', 'solrengine' ),
				$reachable->version(),
				$reachable->mode()
			)
		);

		$this->check_version( $report, $reachable->version() );

		$report->pass(
			'authentication',
			__( 'Authentication', 'solrengine' ),
			$this->credentials->has_password()
				? sprintf(
					/* translators: %s: where the credentials come from. */
					__( 'Accepted. Credentials: %s', 'solrengine' ),
					$this->credentials->describe()
				)
				: __( 'The server did not ask for credentials.', 'solrengine' )
		);

		if ( '' === $target->collection() ) {
			$report->fail(
				'collection',
				__( 'Collection', 'solrengine' ),
				__( 'No collection or core name is configured.', 'solrengine' ),
				'no_collection'
			);

			return $report;
		}

		$ping = $backend->ping();

		if ( $ping->is_failure() ) {
			$report->fail(
				'collection',
				__( 'Collection', 'solrengine' ),
				'not_found' === $ping->code()
					? sprintf(
						/* translators: %s: collection name. */
						__( 'The collection "%s" does not exist on this server. Create it, or correct the name.', 'solrengine' ),
						$target->collection()
					)
					: $ping->message(),
				$ping->code()
			);

			return $report;
		}

		$report->pass(
			'collection',
			__( 'Collection', 'solrengine' ),
			sprintf(
				/* translators: %s: collection name. */
				__( '"%s" exists and is responding.', 'solrengine' ),
				$target->collection()
			)
		);

		$this->check_schema( $report, $backend, $reachable->supports( 'schema_api' ) );

		$report->pass(
			'facets',
			__( 'Faceted search', 'solrengine' ),
			$reachable->supports( 'json_facets' )
				? __( 'The JSON Facet API is available.', 'solrengine' )
				: __( 'Not detected. Faceted search will be unavailable.', 'solrengine' )
		);

		return $report;
	}

	/**
	 * Check the Solr version against the support policy.
	 *
	 * @param DiagnosticReport $report  Report to add to.
	 * @param string           $version Reported version.
	 * @return void
	 */
	private function check_version( DiagnosticReport $report, string $version ): void {
		// ADR-011: 10.x recommended, 9.10+ supported, older warned but never
		// blocked. Everything below 9.10 is End of Life upstream.
		if ( version_compare( $version, '10.0', '>=' ) ) {
			return;
		}

		if ( version_compare( $version, '9.10', '>=' ) ) {
			$report->warn(
				'version',
				__( 'Solr version', 'solrengine' ),
				__( 'Supported. Solr 10 is recommended, and is what SolrEngine is primarily tested against.', 'solrengine' )
			);

			return;
		}

		$report->warn(
			'version',
			__( 'Solr version', 'solrengine' ),
			sprintf(
				/* translators: %s: the detected Solr version. */
				__( 'Solr %s is past end of life upstream and receives no security fixes. SolrEngine will still try to work with it, but please upgrade to 9.10 or 10.', 'solrengine' ),
				$version
			)
		);
	}

	/**
	 * Check the schema against the current contract.
	 *
	 * @param DiagnosticReport $report      Report to add to.
	 * @param SolrBackend      $backend     Backend to ask.
	 * @param bool             $api_present Whether the Schema API is usable.
	 * @return void
	 */
	private function check_schema( DiagnosticReport $report, SolrBackend $backend, bool $api_present ): void {
		$plan = $backend->plan( $this->contracts->current() );

		if ( $plan->is_failure() ) {
			$report->fail(
				'schema',
				__( 'Schema', 'solrengine' ),
				__( 'The schema could not be read.', 'solrengine' ),
				$plan->code()
			);

			return;
		}

		$schema_plan = $plan->value();

		if ( $schema_plan->is_empty() && array() === $schema_plan->blockers() ) {
			$report->pass(
				'schema',
				__( 'Schema', 'solrengine' ),
				sprintf(
					/* translators: %d: schema contract version. */
					__( 'Ready. Contract version %d is satisfied.', 'solrengine' ),
					$schema_plan->target_version()
				)
			);

			return;
		}

		if ( array() !== $schema_plan->blockers() ) {
			$report->fail(
				'schema',
				__( 'Schema', 'solrengine' ),
				implode( ' ', $schema_plan->blockers() ),
				'schema_blocked',
				array( 'can_export' => true )
			);

			return;
		}

		$report->warn(
			'schema',
			__( 'Schema', 'solrengine' ),
			sprintf(
				/* translators: %d: number of pending schema changes. */
				_n(
					'%d change is needed before indexing can start.',
					'%d changes are needed before indexing can start.',
					count( $schema_plan->operations() ),
					'solrengine'
				),
				count( $schema_plan->operations() )
			),
			array(
				'can_apply'  => $api_present,
				'can_export' => true,
				'operations' => $schema_plan->describe(),
			)
		);
	}

	/**
	 * Build a backend for a given endpoint.
	 *
	 * @param Endpoint $endpoint Endpoint to talk to.
	 * @return SolrBackend
	 */
	private function backend_for( Endpoint $endpoint ): SolrBackend {
		$transport = new HttpTransport(
			$endpoint,
			$this->credentials,
			$this->logger,
			$this->config->get_int( 'connection.read_timeout', 10 ),
			$this->config->get_bool( 'connection.verify_tls', true )
		);

		return new SolrBackend( $transport, new SchemaPlanner(), new SchemaExporter() );
	}
}
