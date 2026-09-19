<?php
/**
 * Schema contract conformance against a real Solr.
 *
 * The unit tests check the contract against Solr's documented rules. This
 * checks it against Solr itself, which is the only authority that counts.
 *
 * It exists because the rules in question are not fully documented. Solr's
 * dynamic-fields page says nothing about where the wildcard may appear; only
 * the copy-fields page does, and only for copyField. The original field naming
 * scheme was built on that gap and was unimplementable. A test that applies the
 * real contract to a real server is what stops that recurring.
 *
 * Needs no WordPress. Skips unless SOLRENGINE_TEST_SOLR_URL is set:
 *
 *     docker run --rm -p 8983:8983 solr:10 solr-precreate conformance
 *     SOLRENGINE_TEST_SOLR_URL=http://127.0.0.1:8983/solr \
 *     SOLRENGINE_TEST_SUITE=backend vendor/bin/phpunit --testsuite backend
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Schema\SchemaStateFactory;

/**
 * @covers \SolrEngine\Schema\ContractRegistry
 * @covers \SolrEngine\Contracts\SchemaContract
 */
final class SchemaConformanceTest extends TestCase {

	/**
	 * Base Solr URL, ending in /solr.
	 *
	 * @var string
	 */
	private static $base = '';

	/**
	 * Collection created for this run.
	 *
	 * @var string
	 */
	private static $collection = '';

	public static function setUpBeforeClass(): void {
		$base = getenv( 'SOLRENGINE_TEST_SOLR_URL' );

		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}

		self::$base = rtrim( $base, '/' );

		/*
		 * The core must already exist. Creating one here is not portable:
		 * CoreAdmin CREATE with configSet=_default fails on the official Solr
		 * image, because the image keeps its configsets somewhere the core
		 * root does not see. Precreating is the documented way to start Solr
		 * anyway, so the test asks for a core rather than inventing one.
		 */
		$collection = getenv( 'SOLRENGINE_TEST_SOLR_COLLECTION' );

		self::$collection = is_string( $collection ) && '' !== $collection ? $collection : 'wordpress';
	}

	protected function setUp(): void {
		if ( '' === self::$base ) {
			$this->markTestSkipped( 'Set SOLRENGINE_TEST_SOLR_URL to run backend tests.' );
		}
	}

	// -- The contract applies cleanly --------------------------------------

	public function test_every_required_field_type_exists_in_the_default_configset(): void {
		// The planner reports a missing type as a blocker rather than creating
		// it. That is only reasonable if the types are genuinely standard.
		$response = $this->get( '/schema/fieldtypes' );
		$present  = array();

		foreach ( (array) ( $response['fieldTypes'] ?? array() ) as $type ) {
			$present[] = $type['name'];
		}

		foreach ( array_keys( ContractRegistry::REQUIRED_TYPES ) as $required ) {
			$this->assertContains( $required, $present, "Solr's default configset lacks {$required}" );
		}
	}

	public function test_the_contract_applies_and_is_idempotent(): void {
		$contract = ( new ContractRegistry() )->current();
		$planner  = new SchemaPlanner();

		$plan = $planner->plan( $contract, $this->live_state() );

		$this->assertSame(
			array(),
			$plan->blockers(),
			'A stock Solr should have nothing blocking the contract: ' . implode( ' ', $plan->blockers() )
		);

		// Every operation the planner emits must be one Solr accepts. On a
		// core that already has the contract this loop is empty, which is
		// itself the idempotence the next assertion checks.
		foreach ( $plan->operations() as $operation ) {
			$response = $this->post( '/schema', array( $operation->command() => $operation->payload() ) );

			$this->assertSame(
				0,
				$response['responseHeader']['status'] ?? -1,
				$operation->description() . ' was rejected: ' . wp_json_encode( $response['error'] ?? array() )
			);
		}

		// Re-planning against the changed schema must find nothing left to do.
		// This is what proves the planner reads back what it wrote: a mismatch
		// between what it adds and what it recognises would otherwise make it
		// re-apply the same fields on every run.
		$second = $planner->plan( $contract, $this->live_state() );

		$this->assertTrue(
			$second->is_empty(),
			'The planner still wants changes after applying its own plan: '
				. implode( ' | ', $second->describe() )
		);
	}

	/**
	 * Read the live schema through the same parser production uses.
	 */
	private function live_state(): \SolrEngine\Contracts\SchemaState {
		return ( new SchemaStateFactory() )->from_response( $this->get( '/schema' ), true );
	}

	/**
	 * @depends test_the_contract_applies_and_is_idempotent
	 */
	public function test_applying_the_contract_twice_is_not_silently_destructive(): void {
		// The planner never re-adds an existing field, but if a future change
		// made it do so, Solr must reject rather than replace.
		$field    = ( new ContractRegistry() )->current()->concrete_fields()[0];
		$response = $this->post( '/schema', array( 'add-field' => $field->to_payload() ) );

		$this->assertNotSame( 0, $response['responseHeader']['status'] ?? -1 );
	}

	// -- The rules the naming scheme depends on ----------------------------

	/**
	 * @depends test_the_contract_applies_and_is_idempotent
	 */
	public function test_a_mid_name_wildcard_dynamic_field_is_rejected(): void {
		// This is the exact pattern the original plan required. Solr's own
		// message: "should have either a leading or a trailing asterisk".
		$response = $this->post(
			'/schema',
			array(
				'add-dynamic-field' => array(
					'name'    => 'se_*_txt',
					'type'    => 'text_general',
					'indexed' => true,
					'stored'  => true,
				),
			)
		);

		$this->assertNotSame(
			0,
			$response['responseHeader']['status'] ?? -1,
			'Solr accepted a mid-name wildcard; the Phase 1 naming correction may no longer be needed.'
		);
	}

	/**
	 * @depends test_the_contract_applies_and_is_idempotent
	 */
	public function test_a_mid_name_wildcard_copyfield_source_is_rejected(): void {
		$response = $this->post(
			'/schema',
			array(
				'add-copy-field' => array(
					'source' => 'se_*_ss',
					'dest'   => 'se_all',
				),
			)
		);

		$this->assertNotSame( 0, $response['responseHeader']['status'] ?? -1 );
	}

	// -- The fields actually behave as intended ----------------------------

	/**
	 * @depends test_the_contract_applies_and_is_idempotent
	 */
	public function test_indexed_documents_populate_the_derived_fields(): void {
		$this->post(
			'/update',
			array(
				array(
					'id'                     => 'se:1:post:4211',
					'se_i_site'              => 1,
					'se_s_object_type'       => 'post',
					'se_i_object'            => 4211,
					'se_s_post_type'         => 'post',
					'se_txt_title'           => 'Lenovo ThinkPad review',
					'se_txt_content'         => 'A durable laptop with a superb keyboard.',
					'se_txt_excerpt'         => 'Durable laptop.',
					'se_ss_tax_category'     => array( 'Hardware', 'Laptops' ),
					'se_ss_taxpath_category' => array( '12', '12/34' ),
					'se_is_taxid_category'   => array( 12, 34 ),
					'se_f_meta_price'        => 1299.99,
					'se_b_meta_featured'     => true,
					'se_dt_date'             => '2026-09-19T10:00:00Z',
				),
			),
			array( 'commit' => 'true' )
		);

		$expectations = array(
			'the catch-all receives titles'           => 'se_all:ThinkPad',
			'the catch-all receives content'          => 'se_all:keyboard',
			'the catch-all receives excerpts'         => 'se_all:Durable',
			'the taxonomy glob reaches the catch-all' => 'se_all:Laptops',
			'the taxonomy aggregate is populated'     => 'se_taxterms:Hardware',
			'the suggester source is populated'       => 'se_suggest:Lenovo',
			'float filtering works'                   => 'se_f_meta_price:[1000 TO 2000]',
			'boolean filtering works'                 => 'se_b_meta_featured:true',
			'date filtering works'                    => 'se_dt_date:[2026-01-01T00:00:00Z TO NOW]',
			'multi-valued int filtering works'        => 'se_is_taxid_category:34',
			'hierarchy paths are filterable'          => 'se_ss_taxpath_category:12/34',
		);

		foreach ( $expectations as $label => $query ) {
			// Scoped to this test's own document. Other suites share the core,
			// and "exactly one match" would otherwise fail as soon as any
			// other document happened to contain the same word.
			$response = $this->get(
				'/select',
				array(
					'q'    => $query,
					'fq'   => 'id:"se:1:post:4211"',
					'rows' => 0,
				)
			);

			$this->assertSame(
				1,
				(int) ( $response['response']['numFound'] ?? 0 ),
				'Failed: ' . $label
			);
		}
	}

	/**
	 * @depends test_indexed_documents_populate_the_derived_fields
	 */
	public function test_the_site_scope_filter_isolates_sites(): void {
		// On a shared collection this filter is the only thing separating
		// tenants, so it is a security control, not a convenience.
		$response = $this->get(
			'/select',
			array(
				'q'    => '*:*',
				'fq'   => 'se_i_site:2',
				'rows' => 0,
			)
		);

		$this->assertSame( 0, (int) ( $response['response']['numFound'] ?? 0 ) );
	}

	/**
	 * @depends test_indexed_documents_populate_the_derived_fields
	 */
	public function test_facetable_fields_really_have_docvalues(): void {
		// The naming conventions are supposed to guarantee this. Faceting on a
		// field without docValues is slow rather than broken, so nothing else
		// would notice if the conventions slipped.
		$response = $this->get(
			'/select',
			array(
				'q'          => '*:*',
				'rows'       => 0,
				'json.facet' => (string) wp_json_encode(
					array(
						'category' => array(
							'type'  => 'terms',
							'field' => 'se_ss_tax_category',
						),
						'price'    => array(
							'type'  => 'range',
							'field' => 'se_f_meta_price',
							'start' => 0,
							'end'   => 2000,
							'gap'   => 500,
						),
					)
				),
			)
		);

		$categories = $response['facets']['category']['buckets'] ?? array();
		$prices     = $response['facets']['price']['buckets'] ?? array();
		$names      = array_column( $categories, 'val' );

		$this->assertNotEmpty( $categories );
		$this->assertContains( 'Hardware', $names );
		$this->assertContains( 'Laptops', $names );
		$this->assertNotEmpty( array_filter( $prices, static fn( $b ) => $b['count'] > 0 ) );
	}

	// -- Plumbing ----------------------------------------------------------

	/**
	 * @param string              $path  Path within the collection.
	 * @param array<string,mixed> $query Query parameters.
	 * @return array<string,mixed>
	 */
	private function get( string $path, array $query = array() ): array {
		$query['wt'] = 'json';

		return self::request(
			'GET',
			self::$base . '/' . self::$collection . $path . '?' . http_build_query( $query ),
			null
		);
	}

	/**
	 * @param string              $path  Path within the collection.
	 * @param mixed               $body  JSON payload.
	 * @param array<string,mixed> $query Query parameters.
	 * @return array<string,mixed>
	 */
	private function post( string $path, $body, array $query = array() ): array {
		$query['wt'] = 'json';

		return self::request(
			'POST',
			self::$base . '/' . self::$collection . $path . '?' . http_build_query( $query ),
			$body
		);
	}

	/**
	 * Perform an HTTP request without WordPress.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Absolute URL.
	 * @param mixed  $body   JSON payload, or null.
	 * @return array<string,mixed>
	 */
	private static function request( string $method, string $url, $body ): array {
		$options = array(
			'http' => array(
				'method'        => $method,
				'timeout'       => 30,
				'ignore_errors' => true,
				'header'        => "Accept: application/json\r\n",
			),
		);

		if ( null !== $body ) {
			$options['http']['header'] .= "Content-Type: application/json\r\n";
			$options['http']['content'] = (string) wp_json_encode( $body );
		}

		// ignore_errors is set above, so a 4xx or 5xx still returns a body and
		// the test can assert on Solr's own error payload. A false here means
		// the connection itself failed, which the caller surfaces as a missing
		// responseHeader rather than a PHP warning.
		set_error_handler( static fn(): bool => true );
		$raw = file_get_contents( $url, false, stream_context_create( $options ) );
		restore_error_handler();

		if ( false === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );

		return is_array( $decoded ) ? $decoded : array();
	}
}
