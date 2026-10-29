<?php
/**
 * Autocomplete wire-format tests.
 *
 * The shape `instant-search.js` reads is pinned here, so that any surface
 * answering autocomplete renders exactly this body.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\Suggestion;
use SolrEngine\Contracts\Suggestions;
use SolrEngine\Search\SuggestPayload;
use SolrEngine\Search\SuggestionParser;

/**
 * @covers \SolrEngine\Search\SuggestPayload
 * @covers \SolrEngine\Search\SuggestionParser
 */
final class SuggestPayloadTest extends TestCase {

	private function render( Suggestions $suggestions ): array {
		return ( new SuggestPayload() )->render( $suggestions );
	}

	private function parse( array $body, int $site = 1, string $prefix = 'mac' ): Suggestions {
		return ( new SuggestionParser() )->parse( $body, $site, $prefix );
	}

	/**
	 * One Solr document, as the suggest field list returns it.
	 *
	 * @param array<string,mixed> $overrides Fields to change.
	 * @return array<string,mixed>
	 */
	private function doc( array $overrides = array() ): array {
		return array_merge(
			array(
				'se_txt_title'     => 'MacBook Pro',
				'se_s_url'         => 'https://example.com/macbook-pro/',
				'se_i_object'      => 42,
				'se_s_object_type' => 'post',
				'se_s_post_type'   => 'product',
			),
			$overrides
		);
	}

	// -- The wire format ----------------------------------------------------

	public function test_the_response_has_exactly_the_documented_keys(): void {
		$payload = $this->render( new Suggestions( array(), 'mac', 7 ) );

		// A key added here is a key the front end starts receiving, so the set
		// is asserted whole rather than one membership at a time.
		$this->assertSame( array( 'suggestions', 'prefix', 'took_ms' ), array_keys( $payload ) );
	}

	public function test_an_item_has_exactly_the_documented_keys(): void {
		$payload = $this->render(
			new Suggestions(
				array( new Suggestion( 'MacBook Pro', 'https://example.com/mb/', new DocumentRef( 1, 'post', 42 ), 'product' ) ),
				'mac'
			)
		);

		$this->assertSame(
			array( 'text', 'url', 'id', 'post_type' ),
			array_keys( $payload['suggestions'][0] )
		);
	}

	public function test_prefix_and_timing_are_carried_through(): void {
		$payload = $this->render( new Suggestions( array(), 'macb', 13 ) );

		$this->assertSame( 'macb', $payload['prefix'] );
		$this->assertSame( 13, $payload['took_ms'] );
	}

	public function test_a_suggestion_without_a_reference_reports_a_zero_id(): void {
		// Rather than omitting the key, which would make the front end branch
		// on its presence.
		$payload = $this->render( new Suggestions( array( new Suggestion( 'Loose title' ) ), 'loo' ) );

		$this->assertSame( 0, $payload['suggestions'][0]['id'] );
		$this->assertSame( '', $payload['suggestions'][0]['url'] );
	}

	// -- Reading Solr's response --------------------------------------------

	public function test_documents_become_suggestions(): void {
		$suggestions = $this->parse(
			array(
				'responseHeader' => array( 'QTime' => 4 ),
				'response'       => array( 'docs' => array( $this->doc() ) ),
			)
		);

		$this->assertCount( 1, $suggestions->items() );
		$this->assertSame( 'MacBook Pro', $suggestions->items()[0]->text() );
		$this->assertSame( 'product', $suggestions->items()[0]->post_type() );
		$this->assertSame( 42, $suggestions->items()[0]->ref()->object_id() );
		$this->assertSame( 4, $suggestions->took_ms() );
	}

	public function test_a_multi_valued_field_yields_its_first_value(): void {
		// A field created by hand before the schema contract was applied can be
		// multi-valued, and Solr then returns an array where one value is read.
		$suggestions = $this->parse(
			array( 'response' => array( 'docs' => array( $this->doc( array( 'se_txt_title' => array( 'First', 'Second' ) ) ) ) ) )
		);

		$this->assertSame( 'First', $suggestions->items()[0]->text() );
	}

	public function test_a_document_with_no_title_is_dropped(): void {
		// There is nothing to show for it in a list of titles.
		$suggestions = $this->parse(
			array( 'response' => array( 'docs' => array( $this->doc( array( 'se_txt_title' => '' ) ), $this->doc() ) ) )
		);

		$this->assertCount( 1, $suggestions->items() );
	}

	public function test_the_site_scope_comes_from_the_query_not_the_document(): void {
		// The document carries no site field in the suggest field list, and a
		// reference attributed to the wrong site would cross a tenant boundary.
		$suggestions = $this->parse( array( 'response' => array( 'docs' => array( $this->doc() ) ) ), 5 );

		$this->assertSame( 5, $suggestions->items()[0]->ref()->site_id() );
	}

	public function test_a_malformed_response_yields_no_suggestions(): void {
		$this->assertSame( array(), $this->parse( array() )->items() );
		$this->assertSame( array(), $this->parse( array( 'response' => array( 'docs' => array( 'not-an-array' ) ) ) )->items() );
	}

	public function test_a_missing_qtime_reports_zero(): void {
		$this->assertSame( 0, $this->parse( array( 'response' => array( 'docs' => array() ) ) )->took_ms() );
	}

	// -- The round trip ------------------------------------------------------

	public function test_parsing_then_rendering_produces_the_documented_body(): void {
		// The whole round trip the suggest route performs, asserted as one value.
		$body = $this->render(
			$this->parse(
				array(
					'responseHeader' => array( 'QTime' => 6 ),
					'response'       => array( 'docs' => array( $this->doc() ) ),
				),
				1,
				'macb'
			)
		);

		$this->assertSame(
			array(
				'suggestions' => array(
					array(
						'text'      => 'MacBook Pro',
						'url'       => 'https://example.com/macbook-pro/',
						'id'        => 42,
						'post_type' => 'product',
					),
				),
				'prefix'      => 'macb',
				'took_ms'     => 6,
			),
			$body
		);
	}
}
