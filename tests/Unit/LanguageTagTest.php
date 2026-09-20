<?php
/**
 * Language tag tests.
 *
 * Four systems spell a language four ways: WordPress says en_US, Polylang says
 * en, WPML says en with a locale beside it, and the index holds a BCP-47 tag. The
 * failure mode of getting this wrong is silent — a document indexed as "en_US"
 * and a query filtered on "en-US" simply do not match — so the normalisation is
 * tested rather than assumed.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Language\LanguageTag;

/**
 * @covers \SolrEngine\Language\LanguageTag
 */
final class LanguageTagTest extends TestCase {

	/**
	 * @dataProvider locales
	 *
	 * @param string $input    Locale in any spelling.
	 * @param string $expected Normalised tag.
	 */
	public function test_locales_normalise( string $input, string $expected ): void {
		$this->assertSame( $expected, LanguageTag::normalise( $input ) );
	}

	/**
	 * @return array<int,array{0:string,1:string}>
	 */
	public function locales(): array {
		return array(
			array( 'en', 'en' ),
			array( 'en_US', 'en-US' ),
			array( 'en-us', 'en-US' ),
			array( 'pt_BR', 'pt-BR' ),
			array( 'PT_br', 'pt-BR' ),
			array( 'zh_Hans', 'zh-Hans' ),
			array( 'zh_hans_CN', 'zh-Hans-CN' ),
			array( 'haw', 'haw' ),
			// WordPress ships this. "formal" describes how the interface
			// addresses the reader, not how German is written, and the two
			// analyse identically — so carrying it would split one language in
			// two for no gain.
			array( 'de_DE_formal', 'de-DE' ),
			array( '  fr_FR  ', 'fr-FR' ),
			array( 'es_419', 'es-419' ),
		);
	}

	/**
	 * @dataProvider unusable
	 *
	 * @param string $input Locale that names no language.
	 */
	public function test_unusable_locales_are_undetermined( string $input ): void {
		// BCP-47 reserves "und" for this. It is written rather than left absent
		// so "we do not know" and "nothing has indexed this yet" stay apart.
		$this->assertSame( LanguageTag::UNKNOWN, LanguageTag::normalise( $input ) );
		$this->assertFalse( LanguageTag::is_known( $input ) );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function unusable(): array {
		return array(
			array( '' ),
			array( '   ' ),
			array( '_' ),
			array( 'e' ),
			array( 'english' ),
			array( '123' ),
			array( 'e1' ),
		);
	}

	public function test_the_primary_subtag_drops_the_region(): void {
		// Solr ships one analyser per language, not per locale: there is a
		// text_pt and no text_pt_BR, because the two stem the same way.
		$this->assertSame( 'pt', LanguageTag::primary( 'pt_BR' ) );
		$this->assertSame( 'pt', LanguageTag::primary( 'pt-PT' ) );
		$this->assertSame( 'zh', LanguageTag::primary( 'zh_Hans' ) );
		$this->assertSame( '', LanguageTag::primary( 'nonsense-at-all' ) );
	}

	public function test_field_names_derive_from_the_primary_subtag(): void {
		$this->assertSame( 'text_de', LanguageTag::field_type( 'de_DE' ) );
		$this->assertSame( 'txtde', LanguageTag::field_prefix( 'de_DE' ) );
		$this->assertSame( 'se_txtde_*', LanguageTag::field_pattern( 'de_DE' ) );
	}

	public function test_an_unknown_language_has_no_fields(): void {
		$this->assertSame( '', LanguageTag::field_type( '' ) );
		$this->assertSame( '', LanguageTag::field_prefix( '' ) );
		$this->assertSame( '', LanguageTag::field_pattern( '' ) );
	}

	/**
	 * @dataProvider existing_patterns
	 *
	 * @param string $pattern An existing dynamic field pattern.
	 */
	public function test_language_fields_cannot_collide_with_an_existing_pattern( string $pattern ): void {
		// This is why there is no separator: `se_txt_*` matches `se_txt_en_title`,
		// so a per-language field written with one would land in the
		// language-neutral field type whenever its own pattern was missing —
		// silently, and with no error anywhere.
		$prefix = rtrim( $pattern, '*' );

		foreach ( array( 'en', 'de', 'sv', 'pt', 'haw', 'is' ) as $subtag ) {
			$field = str_replace( '*', 'title', LanguageTag::field_pattern( $subtag ) );

			$this->assertStringStartsNotWith(
				$prefix,
				$field,
				$field . ' must not be matched by ' . $pattern
			);
		}
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function existing_patterns(): array {
		return array(
			array( 'se_txt_*' ),
			array( 'se_txts_*' ),
			array( 'se_s_*' ),
			array( 'se_ss_*' ),
			array( 'se_i_*' ),
			array( 'se_is_*' ),
			array( 'se_l_*' ),
			array( 'se_ls_*' ),
			array( 'se_f_*' ),
			array( 'se_d_*' ),
			array( 'se_b_*' ),
			array( 'se_dt_*' ),
			array( 'se_dts_*' ),
		);
	}
}
