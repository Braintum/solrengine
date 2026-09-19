<?php
/**
 * Relevance profile tests.
 *
 * An integration contributes fields a site owner has never heard of — a SKU, a
 * set of product attributes — and must be able to weight them sensibly out of the
 * box. What it must not do is overrule a decision the site owner has made about
 * one of them, which is what it did until 0.8.0.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Search\RelevanceProfile;

/**
 * @covers \SolrEngine\Search\RelevanceProfile
 */
final class RelevanceProfileTest extends TestCase {

	public function test_an_integration_field_is_added_when_nothing_is_configured(): void {
		$profile = RelevanceProfile::from_config( array(), array( 'se_txt_wc_sku' => 8.0 ) );

		$this->assertSame( 8.0, $profile->field_boosts()['se_txt_wc_sku'] );
		$this->assertArrayHasKey( 'se_txt_title', $profile->field_boosts() );
	}

	public function test_a_configured_boost_survives_an_integration_default(): void {
		$profile = RelevanceProfile::from_config(
			array( 'field_boosts' => array( 'se_txt_wc_sku' => 2.0 ) ),
			array( 'se_txt_wc_sku' => 8.0 )
		);

		// Until 0.8.0 the integration's value was merged over the top, so a
		// store that turned its SKU weighting down got it put back on every
		// request and the setting appeared to do nothing.
		$this->assertSame( 2.0, $profile->field_boosts()['se_txt_wc_sku'] );
	}

	public function test_a_field_switched_off_stays_off(): void {
		$profile = RelevanceProfile::from_config(
			array(
				'field_boosts' => array(
					'se_txt_title'  => 5.0,
					'se_txt_wc_sku' => 0,
				),
			),
			array( 'se_txt_wc_sku' => 8.0 )
		);

		// Zero means "stop searching this field". It has to survive the merge
		// as a decision rather than looking like a field nobody mentioned.
		$this->assertArrayNotHasKey( 'se_txt_wc_sku', $profile->field_boosts() );
	}

	public function test_query_fields_render_as_a_solr_field_list(): void {
		$profile = RelevanceProfile::from_config(
			array(
				'field_boosts' => array(
					'se_txt_title'   => 5.0,
					'se_txt_content' => 1.0,
				),
			)
		);

		// A boost of exactly one is written without a caret: it is the default
		// weighting, and "field^1" is noise in a parameter somebody debugs.
		$this->assertSame( 'se_txt_title^5 se_txt_content', $profile->query_fields() );
	}

	public function test_recency_rounds_now_to_the_hour(): void {
		$profile = RelevanceProfile::from_config( array( 'recency_boost' => true ) );

		// An unrounded NOW makes every query unique, so Solr's caches never hit
		// and the boost costs far more than it is worth.
		$this->assertStringContainsString( 'NOW/HOUR', $profile->boost_function() );
	}

	public function test_recency_is_off_by_default(): void {
		$this->assertSame( '', RelevanceProfile::from_config( array() )->boost_function() );
	}
}
