<?php
/**
 * Response parsing tests.
 *
 * A response is data that crossed a network. A malformed one must cost one
 * missing result, never a fatal error on a page a visitor is looking at.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Search\ResponseParser;

/**
 * @covers \SolrEngine\Search\ResponseParser
 * @covers \SolrEngine\Contracts\SearchResults
 * @covers \SolrEngine\Contracts\SearchHit
 */
final class ResponseParserTest extends TestCase {

	private function query(): SearchQuery {
		return new SearchQuery( 'laptops', 1 );
	}

	public function test_a_normal_response_is_parsed(): void {
		$results = ( new ResponseParser() )->parse(
			array(
				'responseHeader' => array( 'QTime' => 7 ),
				'response'       => array(
					'numFound' => 42,
					'docs'     => array(
						array(
							'id'                        => 'se:1:post:9',
							DocumentFields::SITE        => 1,
							DocumentFields::OBJECT_TYPE => 'post',
							DocumentFields::OBJECT_ID   => 9,
							DocumentFields::TITLE       => 'A laptop',
							'score'                     => 3.25,
						),
					),
				),
			),
			$this->query()
		);

		$this->assertSame( 42, $results->total() );
		$this->assertSame( 7, $results->took_ms() );
		$this->assertSame( array( 9 ), $results->object_ids() );
		$this->assertSame( 3.25, $results->hits()[0]->score() );
	}

	public function test_a_document_without_an_object_id_is_dropped(): void {
		// It cannot be rendered or linked. Dropping it loses one result;
		// returning it would produce a link to nothing.
		$results = ( new ResponseParser() )->parse(
			array(
				'response' => array(
					'numFound' => 2,
					'docs'     => array(
						array(
							'id'                      => 'se:1:post:1',
							DocumentFields::OBJECT_ID => 1,
						),
						array( 'id' => 'broken' ),
					),
				),
			),
			$this->query()
		);

		$this->assertCount( 1, $results->hits() );
	}

	public function test_a_malformed_response_yields_empty_results(): void {
		foreach ( array( array(), array( 'response' => 'nonsense' ), array( 'response' => array( 'docs' => 'nope' ) ) ) as $body ) {
			$results = ( new ResponseParser() )->parse( $body, $this->query() );

			$this->assertTrue( $results->is_empty() );
			$this->assertSame( 0, $results->total() );
		}
	}

	public function test_single_element_arrays_are_flattened(): void {
		// Solr returns an array for any field declared multi-valued, even when
		// it holds one value. A caller rendering a title should not need to
		// know which fields those are.
		$results = ( new ResponseParser() )->parse(
			array(
				'response' => array(
					'numFound' => 1,
					'docs'     => array(
						array(
							DocumentFields::OBJECT_ID => 5,
							DocumentFields::TITLE     => array( 'Only one' ),
						),
					),
				),
			),
			$this->query()
		);

		$this->assertSame( 'Only one', $results->hits()[0]->field( DocumentFields::TITLE ) );
	}

	public function test_multi_value_fields_stay_arrays(): void {
		$results = ( new ResponseParser() )->parse(
			array(
				'response' => array(
					'numFound' => 1,
					'docs'     => array(
						array(
							DocumentFields::OBJECT_ID => 5,
							DocumentFields::taxonomy_terms( 'category' ) => array( 'A', 'B' ),
						),
					),
				),
			),
			$this->query()
		);

		$this->assertSame(
			array( 'A', 'B' ),
			$results->hits()[0]->field( DocumentFields::taxonomy_terms( 'category' ) )
		);
	}

	public function test_highlights_are_attached_to_the_right_document(): void {
		$results = ( new ResponseParser() )->parse(
			array(
				'response'     => array(
					'numFound' => 2,
					'docs'     => array(
						array(
							'id'                      => 'se:1:post:1',
							DocumentFields::OBJECT_ID => 1,
						),
						array(
							'id'                      => 'se:1:post:2',
							DocumentFields::OBJECT_ID => 2,
						),
					),
				),
				'highlighting' => array(
					'se:1:post:2' => array( DocumentFields::CONTENT => array( 'a <mark>laptop</mark> here' ) ),
				),
			),
			$this->query()
		);

		$this->assertSame( array(), $results->hits()[0]->highlights() );
		$this->assertSame(
			array( 'a <mark>laptop</mark> here' ),
			$results->hits()[1]->highlights()[ DocumentFields::CONTENT ]
		);
	}

	public function test_page_count_follows_the_requested_page_size(): void {
		$results = ( new ResponseParser() )->parse(
			array(
				'response' => array(
					'numFound' => 95,
					'docs'     => array(),
				),
			),
			( new SearchQuery( 'x', 1 ) )->with_pagination( 1, 10 )
		);

		$this->assertSame( 10, $results->total_pages() );
	}

	public function test_the_strategy_defaults_to_standard(): void {
		$results = ( new ResponseParser() )->parse( array(), $this->query() );

		$this->assertSame( 'standard', $results->strategy() );
		$this->assertSame( 'fuzzy', $results->with_strategy( 'fuzzy' )->strategy() );
	}
}
