<?php
/**
 * Relevance settings tests.
 *
 * The minimum-match expression is the one setting on the tuning screen where a
 * typo does not degrade search, it stops search: Solr rejects the whole request,
 * so every search on the site fails until somebody edits the value back. That is
 * why it is validated at save time rather than passed through.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Search\RelevanceSettings;

/**
 * @covers \SolrEngine\Search\RelevanceSettings
 */
final class RelevanceSettingsTest extends TestCase {

	/**
	 * @dataProvider valid_expressions
	 *
	 * @param string $expression Expression to check.
	 */
	public function test_valid_minimum_match_expressions( string $expression ): void {
		$this->assertTrue(
			RelevanceSettings::is_valid_minimum_match( $expression ),
			$expression . ' should be accepted'
		);
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function valid_expressions(): array {
		return array(
			array( '2' ),
			array( '-1' ),
			array( '75%' ),
			array( '-25%' ),
			array( '100%' ),
			array( '2<75%' ),
			array( '2<-25%' ),
			array( '2<75% 5<90%' ),
			array( '  3<50%  ' ),
		);
	}

	/**
	 * @dataProvider invalid_expressions
	 *
	 * @param string $expression Expression to check.
	 */
	public function test_invalid_minimum_match_expressions( string $expression ): void {
		$this->assertFalse(
			RelevanceSettings::is_valid_minimum_match( $expression ),
			$expression . ' should be refused'
		);
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function invalid_expressions(): array {
		return array(
			array( '' ),
			array( 'most' ),
			array( '75%%' ),
			array( '101%' ),
			array( '2<' ),
			array( '<75%' ),
			array( '0<75%' ),
			array( '2<75%<3' ),
			// Two bare specifications. Solr reads a space-separated list as
			// conditional pairs, so this is not "either of these".
			array( '75% 2' ),
		);
	}

	public function test_a_rejected_expression_falls_back_to_the_default(): void {
		$settings = RelevanceSettings::from_input(
			array(
				'field_boosts'  => array( 'se_txt_title' => '5' ),
				'minimum_match' => 'most of them',
			)
		);

		$this->assertFalse( $settings->is_clean() );
		$this->assertSame( '2<75%', $settings->to_config()['minimum_match'] );
	}

	public function test_boosts_are_kept_with_their_fields(): void {
		$settings = RelevanceSettings::from_input(
			array(
				'field_boosts' => array(
					'se_txt_title'   => '8',
					'se_txt_content' => '1.5',
				),
			)
		);

		$this->assertTrue( $settings->is_clean() );
		$this->assertSame(
			array(
				'se_txt_title'   => 8.0,
				'se_txt_content' => 1.5,
			),
			$settings->to_config()['field_boosts']
		);
	}

	public function test_a_zero_boost_removes_the_field(): void {
		$settings = RelevanceSettings::from_input(
			array(
				'field_boosts' => array(
					'se_txt_title'   => '5',
					'se_txt_content' => '0',
				),
			)
		);

		// Zero is a decision, not an error: it means "stop searching this
		// field", so it is accepted without complaint and the field is dropped.
		$this->assertTrue( $settings->is_clean() );
		$this->assertArrayNotHasKey( 'se_txt_content', $settings->to_config()['field_boosts'] );
	}

	public function test_zeroing_every_field_is_refused(): void {
		$settings = RelevanceSettings::from_input(
			array(
				'field_boosts' => array(
					'se_txt_title'   => '0',
					'se_txt_content' => '0',
				),
			)
		);

		$this->assertFalse( $settings->is_clean() );

		// No field_boosts key at all, which RelevanceProfile reads as "use the
		// defaults". A site that searched nothing would return nothing to every
		// visitor, and the screen that would undo it is not the one they use.
		$this->assertArrayNotHasKey( 'field_boosts', $settings->to_config() );
	}

	public function test_an_unreasonable_boost_is_capped_and_reported(): void {
		$settings = RelevanceSettings::from_input(
			array( 'field_boosts' => array( 'se_txt_title' => '5000' ) )
		);

		$this->assertFalse( $settings->is_clean() );
		$this->assertSame(
			RelevanceSettings::MAX_BOOST,
			$settings->to_config()['field_boosts']['se_txt_title']
		);
	}

	public function test_a_negative_boost_is_refused(): void {
		$settings = RelevanceSettings::from_input(
			array(
				'field_boosts' => array(
					'se_txt_title'   => '-5',
					'se_txt_content' => '1',
				),
			)
		);

		$this->assertFalse( $settings->is_clean() );
		$this->assertArrayNotHasKey( 'se_txt_title', $settings->to_config()['field_boosts'] );
	}

	public function test_unknown_fields_are_ignored(): void {
		$settings = RelevanceSettings::from_input(
			array(
				'field_boosts' => array(
					'se_txt_title'      => '5',
					'; drop everything' => '9',
				),
			)
		);

		// Silently, not as a problem. A name that is not on the list did not
		// come from the form, and the alternative is letting a hand-made
		// request put an arbitrary field name into every query the site runs.
		$this->assertTrue( $settings->is_clean() );
		$this->assertSame( array( 'se_txt_title' => 5.0 ), $settings->to_config()['field_boosts'] );
	}

	public function test_the_tie_breaker_is_bounded(): void {
		$high = RelevanceSettings::from_input(
			array(
				'field_boosts' => array( 'se_txt_title' => '5' ),
				'tie'          => '4',
			)
		);

		$this->assertFalse( $high->is_clean() );
		$this->assertSame( 0.1, $high->to_config()['tie'] );

		$fine = RelevanceSettings::from_input(
			array(
				'field_boosts' => array( 'se_txt_title' => '5' ),
				'tie'          => '0.3',
			)
		);

		$this->assertTrue( $fine->is_clean() );
		$this->assertSame( 0.3, $fine->to_config()['tie'] );
	}

	public function test_product_fields_are_tunable_even_without_woocommerce(): void {
		// Kept on the list deliberately, so a store's tuning survives
		// deactivating WooCommerce for an afternoon.
		$this->assertArrayHasKey( 'se_txt_wc_sku', RelevanceSettings::tunable_fields() );
		$this->assertArrayNotHasKey( 'se_txt_wc_sku', RelevanceSettings::tunable_fields( false ) );
	}
}
