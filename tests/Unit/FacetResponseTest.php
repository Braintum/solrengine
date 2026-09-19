<?php
/**
 * Facet response parsing tests.
 *
 * Exercised without a server, which is the point of keeping the parser free of
 * both WordPress and the transport: response shapes that would take a fixture
 * cluster to produce are one array literal here.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\ResponseParser;

/**
 * @covers \SolrEngine\Search\ResponseParser
 * @covers \SolrEngine\Contracts\FacetResult
 * @covers \SolrEngine\Contracts\FacetBucket
 */
final class FacetResponseTest extends TestCase {

	private function parser(): ResponseParser {
		$registry = new FacetRegistry();

		$registry->register(
			FacetDefinition::terms( 'topic', 'se_is_taxid_topic', 'Topic', FieldType::INT )
		);
		$registry->register(
			FacetDefinition::hierarchy( 'category', 'category', 'se_ss_taxpath_category', 'Category' )
		);
		$registry->register(
			FacetDefinition::range( 'price', 'se_f_meta_price', 'Price', 0.0, 500.0, 250.0 )
		);

		return new ResponseParser( $registry );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function response( array $facets ): array {
		return array(
			'responseHeader' => array( 'QTime' => 7 ),
			'response'       => array(
				'numFound' => 3,
				'docs'     => array(),
			),
			'facets'         => $facets,
		);
	}

	public function test_buckets_are_read_with_their_counts(): void {
		$query = ( new SearchQuery( 'laptop', 1 ) )->with_facets( array( 'topic' ) );

		$results = $this->parser()->parse(
			$this->response(
				array(
					'count' => 3,
					'topic' => array(
						'buckets' => array(
							array(
								'val'   => 42,
								'count' => 2,
							),
							array(
								'val'   => 17,
								'count' => 1,
							),
						),
					),
				)
			),
			$query
		);

		$buckets = $results->facet( 'topic' )->buckets();

		$this->assertCount( 2, $buckets );
		$this->assertSame( '42', $buckets[0]->value() );
		$this->assertSame( 2, $buckets[0]->count() );
		$this->assertSame( 'Topic', $results->facet( 'topic' )->label() );
	}

	public function test_a_selected_value_is_marked_on_its_bucket(): void {
		$query = ( new SearchQuery( '', 1 ) )
			->with_filter( 'topic', array( 42 ) )
			->with_facets( array( 'topic' ) );

		$results = $this->parser()->parse(
			$this->response(
				array(
					'topic' => array(
						'buckets' => array(
							array(
								'val'   => 42,
								'count' => 2,
							),
							array(
								'val'   => 17,
								'count' => 9,
							),
						),
					),
				)
			),
			$query
		);

		$buckets = $results->facet( 'topic' )->buckets();

		$this->assertTrue( $buckets[0]->is_selected() );
		$this->assertFalse( $buckets[1]->is_selected() );
		$this->assertSame( array( '42' ), $results->facet( 'topic' )->selected() );
	}

	public function test_a_facet_that_counted_nothing_is_still_present(): void {
		// A filter panel has to keep rendering the control a visitor needs in
		// order to undo their own selection, even when its counts are zero.
		$query = ( new SearchQuery( '', 1 ) )->with_facets( array( 'topic' ) );

		$results = $this->parser()->parse( $this->response( array( 'count' => 0 ) ), $query );

		$this->assertNotNull( $results->facet( 'topic' ) );
		$this->assertTrue( $results->facet( 'topic' )->is_empty() );
	}

	public function test_a_query_without_facets_carries_none(): void {
		$results = $this->parser()->parse(
			$this->response(
				array(
					'topic' => array(
						'buckets' => array(
							array(
								'val'   => 1,
								'count' => 1,
							),
						),
					),
				)
			),
			new SearchQuery( 'laptop', 1 )
		);

		$this->assertSame( array(), $results->facets() );
	}

	public function test_the_drill_down_position_is_reported_back(): void {
		$query = ( new SearchQuery( '', 1 ) )->with_facet( 'category', '1/12/34' );

		$results = $this->parser()->parse(
			$this->response(
				array(
					'category' => array(
						'buckets' => array(
							array(
								'val'   => '2/12/34/56',
								'count' => 4,
							),
						),
					),
				)
			),
			$query
		);

		// The panel needs it to render a way back up the tree.
		$this->assertSame( '1/12/34', $results->facet( 'category' )->parent() );
		$this->assertSame( '2/12/34/56', $results->facet( 'category' )->buckets()[0]->value() );
	}

	public function test_a_range_bucket_keeps_a_value_a_caller_can_send_back(): void {
		$query = ( new SearchQuery( '', 1 ) )->with_facets( array( 'price' ) );

		$results = $this->parser()->parse(
			$this->response(
				array(
					'price' => array(
						'buckets' => array(
							array(
								'val'   => 0.0,
								'count' => 1,
							),
							array(
								'val'   => 250.0,
								'count' => 0,
							),
						),
					),
				)
			),
			$query
		);

		$buckets = $results->facet( 'price' )->buckets();

		// Rendered without exponent or trailing zeroes, because the string is
		// the filter value: "2.5E+2" would stop matching the band it names.
		$this->assertSame( '0', $buckets[0]->value() );
		$this->assertSame( '250', $buckets[1]->value() );

		// An empty band is kept. A price histogram with holes in it misleads.
		$this->assertCount( 2, $buckets );
	}

	public function test_a_malformed_facet_section_does_not_break_a_page(): void {
		$query = ( new SearchQuery( '', 1 ) )->with_facets( array( 'topic' ) );

		$results = $this->parser()->parse(
			$this->response(
				array(
					'topic' => array(
						'buckets' => array(
							'not a bucket',
							array( 'count' => 3 ),
							array(
								'val'   => 42,
								'count' => 2,
							),
						),
					),
				)
			),
			$query
		);

		$this->assertCount( 1, $results->facet( 'topic' )->buckets() );
	}
}
