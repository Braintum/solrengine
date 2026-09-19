<?php
/**
 * Writes documents to a real Solr and reads them back.
 *
 * The unit tests check that field names and coerced values are what we intend.
 * This checks that Solr agrees: that every field a real document carries lands
 * in a dynamic pattern, that the derived fields fill, and that a delete really
 * removes.
 *
 * Needs no WordPress. Documents are constructed directly rather than built from
 * posts, because what is under test here is the contract between the document
 * shape and the index, not WordPress's data model.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\Document;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Schema\SchemaStateFactory;

/**
 * @covers \SolrEngine\Contracts\Document
 * @covers \SolrEngine\Documents\DocumentFields
 */
final class IndexRoundTripTest extends TestCase {

	/**
	 * Base Solr URL.
	 *
	 * @var string
	 */
	private static $base = '';

	/**
	 * Collection under test.
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

		$collection = getenv( 'SOLRENGINE_TEST_SOLR_COLLECTION' );

		self::$collection = is_string( $collection ) && '' !== $collection ? $collection : 'wordpress';

		// Apply the contract, so this suite can run against a fresh core
		// without depending on the conformance suite having run first.
		$planner = new SchemaPlanner();
		$state   = ( new SchemaStateFactory() )->from_response( self::call( 'GET', '/schema', null, array() ), true );

		foreach ( $planner->plan( ( new ContractRegistry() )->current(), $state )->operations() as $operation ) {
			self::call( 'POST', '/schema', array( $operation->command() => $operation->payload() ), array() );
		}
	}

	protected function setUp(): void {
		if ( '' === self::$base ) {
			$this->markTestSkipped( 'Set SOLRENGINE_TEST_SOLR_URL to run backend tests.' );
		}
	}

	public function test_a_full_document_survives_a_round_trip(): void {
		$ref      = new DocumentRef( 1, 'post', 9001 );
		$document = new Document( $ref, $this->realistic_fields() );

		$response = $this->write( array( $document ) );

		$this->assertSame(
			0,
			$response['responseHeader']['status'] ?? -1,
			'Solr rejected a document built from our own conventions: ' . wp_json_encode( $response['error'] ?? array() )
		);

		$found = $this->select(
			array(
				'q'  => 'id:"' . $ref->document_id() . '"',
				'fl' => '*',
			)
		);

		$this->assertSame( 1, (int) ( $found['response']['numFound'] ?? 0 ) );

		$stored = $found['response']['docs'][0];

		$this->assertSame( $ref->document_id(), $stored['id'] );
		$this->assertSame( 'Lenovo ThinkPad X1', $stored[ DocumentFields::TITLE ] );
		$this->assertSame( 1299.99, round( (float) $stored[ DocumentFields::meta( FieldType::FLOAT, 'price' ) ], 2 ) );
	}

	/**
	 * @depends test_a_full_document_survives_a_round_trip
	 */
	public function test_writing_the_same_reference_twice_updates_rather_than_duplicates(): void {
		// Identifiers are stable, so a replayed job must be a no-op rather than
		// a second copy. The whole retry design rests on this.
		$ref = new DocumentRef( 1, 'post', 9001 );

		$fields                          = $this->realistic_fields();
		$fields[ DocumentFields::TITLE ] = 'Lenovo ThinkPad X1 Carbon';

		$this->write( array( new Document( $ref, $fields ) ) );

		$found = $this->select(
			array(
				'q'  => 'id:"' . $ref->document_id() . '"',
				'fl' => 'id,' . DocumentFields::TITLE,
			)
		);

		$this->assertSame( 1, (int) ( $found['response']['numFound'] ?? 0 ) );
		$this->assertSame( 'Lenovo ThinkPad X1 Carbon', $found['response']['docs'][0][ DocumentFields::TITLE ] );
	}

	/**
	 * @depends test_a_full_document_survives_a_round_trip
	 */
	public function test_a_batch_writes_every_document(): void {
		$documents = array();

		for ( $i = 1; $i <= 25; $i++ ) {
			$documents[] = new Document(
				new DocumentRef( 1, 'post', 9100 + $i ),
				array(
					DocumentFields::SITE        => 1,
					DocumentFields::OBJECT_TYPE => 'post',
					DocumentFields::OBJECT_ID   => 9100 + $i,
					DocumentFields::POST_TYPE   => 'post',
					DocumentFields::TITLE       => 'Batch item ' . $i,
				)
			);
		}

		$this->write( $documents );

		$found = $this->select(
			array(
				'q'    => DocumentFields::POST_TYPE . ':post',
				'fq'   => 'se_i_object:[9101 TO 9125]',
				'rows' => 0,
			)
		);

		$this->assertSame( 25, (int) ( $found['response']['numFound'] ?? 0 ) );
	}

	/**
	 * @depends test_a_batch_writes_every_document
	 */
	public function test_a_malformed_document_is_rejected_without_taking_the_batch_with_it(): void {
		// The behaviour the indexer's one-at-a-time retry depends on: Solr
		// refuses the whole batch, so the caller must isolate rather than
		// assume a partial write happened.
		$good = new Document(
			new DocumentRef( 1, 'post', 9200 ),
			array(
				DocumentFields::SITE      => 1,
				DocumentFields::OBJECT_ID => 9200,
				DocumentFields::TITLE     => 'Perfectly fine',
			)
		);

		$bad = new Document(
			new DocumentRef( 1, 'post', 9201 ),
			array(
				DocumentFields::SITE      => 1,
				DocumentFields::OBJECT_ID => 9201,
				// A non-date in a date field. Exactly what an unvalidated meta
				// value would produce without the coercion layer.
				DocumentFields::DATE      => 'last Tuesday',
			)
		);

		$response = $this->write( array( $good, $bad ) );

		$this->assertNotSame( 0, $response['responseHeader']['status'] ?? -1 );

		// Retrying the good one alone succeeds, which is what Indexer does.
		$retry = $this->write( array( $good ) );

		$this->assertSame( 0, $retry['responseHeader']['status'] ?? -1 );
	}

	/**
	 * @depends test_a_batch_writes_every_document
	 */
	public function test_deleting_by_identifier_removes_the_document(): void {
		$ref = new DocumentRef( 1, 'post', 9101 );

		self::call(
			'POST',
			'/update',
			array( 'delete' => array( $ref->document_id() ) ),
			array( 'commit' => 'true' )
		);

		$found = $this->select(
			array(
				'q'    => 'id:"' . $ref->document_id() . '"',
				'rows' => 0,
			)
		);

		$this->assertSame( 0, (int) ( $found['response']['numFound'] ?? 0 ) );
	}

	/**
	 * @depends test_a_batch_writes_every_document
	 */
	public function test_deleting_by_selector_removes_a_whole_site(): void {
		// The selector path exists so removing a site is one request rather
		// than an enumeration of a million identifiers.
		$this->write(
			array(
				new Document(
					new DocumentRef( 77, 'post', 1 ),
					array(
						DocumentFields::SITE      => 77,
						DocumentFields::OBJECT_ID => 1,
						DocumentFields::TITLE     => 'Belongs to another site',
					)
				),
			)
		);

		$this->assertSame(
			1,
			(int) ( $this->select(
				array(
					'q'    => '*:*',
					'fq'   => 'se_i_site:77',
					'rows' => 0,
				)
			)['response']['numFound'] ?? 0 )
		);

		self::call(
			'POST',
			'/update',
			array( 'delete' => array( 'query' => 'se_i_site:77' ) ),
			array( 'commit' => 'true' )
		);

		$this->assertSame(
			0,
			(int) ( $this->select(
				array(
					'q'    => '*:*',
					'fq'   => 'se_i_site:77',
					'rows' => 0,
				)
			)['response']['numFound'] ?? 0 )
		);

		// Other sites are untouched.
		$this->assertGreaterThan(
			0,
			(int) ( $this->select(
				array(
					'q'    => '*:*',
					'fq'   => 'se_i_site:1',
					'rows' => 0,
				)
			)['response']['numFound'] ?? 0 )
		);
	}

	/**
	 * A document with one field of every family a real post produces.
	 *
	 * @return array<string,mixed>
	 */
	private function realistic_fields(): array {
		return array(
			DocumentFields::SITE                         => 1,
			DocumentFields::OBJECT_TYPE                  => 'post',
			DocumentFields::OBJECT_ID                    => 9001,
			DocumentFields::POST_TYPE                    => 'post',
			DocumentFields::STATUS                       => 'publish',
			DocumentFields::VISIBILITY                   => 'public',
			DocumentFields::TITLE                        => 'Lenovo ThinkPad X1',
			DocumentFields::TITLE_SORT                   => 'Lenovo ThinkPad X1',
			DocumentFields::EXCERPT                      => 'A durable laptop.',
			DocumentFields::CONTENT                      => 'A durable laptop with a superb keyboard and long battery life.',
			DocumentFields::SLUG                         => 'lenovo-thinkpad-x1',
			DocumentFields::URL                          => 'https://example.test/lenovo-thinkpad-x1/',
			DocumentFields::AUTHOR_ID                    => 3,
			DocumentFields::AUTHOR_NAME                  => 'Alex Reviewer',
			DocumentFields::DATE                         => '2026-09-19T10:00:00Z',
			DocumentFields::MODIFIED                     => '2026-09-19T11:30:00Z',
			DocumentFields::MENU_ORDER                   => 0,
			DocumentFields::COMMENTS                     => 12,
			DocumentFields::PARENT                       => 0,
			DocumentFields::THUMBNAIL                    => 'https://example.test/x1.jpg',
			DocumentFields::THUMBNAIL_ID                 => 55,
			DocumentFields::SCHEMA_VERSION               => 1,
			DocumentFields::INDEXED_AT                   => '2026-09-19T12:00:00Z',

			DocumentFields::taxonomy_terms( 'category' ) => array( 'Hardware', 'Laptops' ),
			DocumentFields::taxonomy_ids( 'category' )   => array( 12, 34 ),
			DocumentFields::taxonomy_paths( 'category' ) => array( '12', '12/34' ),
			DocumentFields::taxonomy_terms( 'post_tag' ) => array( 'lenovo', 'thinkpad' ),

			DocumentFields::meta( FieldType::FLOAT, 'price' ) => 1299.99,
			DocumentFields::meta( FieldType::BOOL, 'featured' ) => true,
			DocumentFields::meta( FieldType::INT, 'stock' ) => 42,
			DocumentFields::meta( FieldType::STRINGS, 'colours' ) => array( 'black', 'silver' ),
			DocumentFields::meta( FieldType::DATE, 'released' ) => '2026-01-15T00:00:00Z',
		);
	}

	/**
	 * @param Document[] $documents Documents to write.
	 * @return array<string,mixed>
	 */
	private function write( array $documents ): array {
		$payloads = array();

		foreach ( $documents as $document ) {
			$payloads[] = $document->to_payload();
		}

		return self::call( 'POST', '/update', $payloads, array( 'commit' => 'true' ) );
	}

	/**
	 * @param array<string,mixed> $query Query parameters.
	 * @return array<string,mixed>
	 */
	private function select( array $query ): array {
		return self::call( 'GET', '/select', null, $query );
	}

	/**
	 * @param string              $method HTTP method.
	 * @param string              $path   Path within the collection.
	 * @param mixed               $body   JSON payload, or null.
	 * @param array<string,mixed> $query  Query parameters.
	 * @return array<string,mixed>
	 */
	private static function call( string $method, string $path, $body, array $query ): array {
		$query['wt'] = 'json';

		$url = self::$base . '/' . self::$collection . $path . '?' . http_build_query( $query );

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
