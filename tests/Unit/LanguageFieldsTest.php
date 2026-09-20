<?php
/**
 * Per-language field tests.
 *
 * The rule these enforce is ADR-003's second: SolrEngine never creates a field
 * type. A language whose analyser the server does not have is not an error and
 * not a silent failure — its content is indexed and searchable through the
 * neutral fields, and only stemming is missing.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\SchemaState;
use SolrEngine\Schema\LanguageFields;

/**
 * @covers \SolrEngine\Schema\LanguageFields
 */
final class LanguageFieldsTest extends TestCase {

	/**
	 * A schema with the given field types and dynamic patterns.
	 *
	 * @param string[] $types    Field type names.
	 * @param string[] $patterns Dynamic field patterns.
	 * @return SchemaState
	 */
	private function schema( array $types, array $patterns = array() ): SchemaState {
		$dynamic = array();

		foreach ( $patterns as $pattern ) {
			$dynamic[ $pattern ] = array( 'name' => $pattern );
		}

		return new SchemaState( array(), $dynamic, array(), $types, true, 'id' );
	}

	public function test_only_languages_the_server_can_analyse_are_available(): void {
		$state = $this->schema( array( 'text_general', 'text_en', 'text_de' ) );

		$this->assertSame(
			array( 'en', 'de' ),
			LanguageFields::available( $state, array( 'en', 'de', 'ja' ) )
		);
	}

	public function test_a_server_with_no_language_analysers_supports_none(): void {
		// Solr's own _default configset ships text_general and text_en and
		// nothing else, so this is the common case rather than an edge one.
		$state = $this->schema( array( 'text_general' ) );

		$this->assertSame( array(), LanguageFields::available( $state, array( 'de', 'fr' ) ) );
	}

	public function test_regional_variants_collapse_onto_one_analyser(): void {
		$state = $this->schema( array( 'text_pt' ) );

		$this->assertSame(
			array( 'pt' ),
			LanguageFields::available( $state, array( 'pt-BR', 'pt-PT' ) )
		);
	}

	public function test_presence_is_about_the_pattern_not_the_type(): void {
		// The two are separate questions. A server can define text_de without
		// anybody having added se_txtde_*, and writing into a pattern that does
		// not exist makes Solr reject the whole document — so one unconfigured
		// language would stop a site indexing entirely.
		$state = $this->schema( array( 'text_de' ) );

		$this->assertSame( array( 'de' ), LanguageFields::available( $state, array( 'de' ) ) );
		$this->assertSame( array(), LanguageFields::present( $state, array( 'de' ) ) );

		$applied = $this->schema( array( 'text_de' ), array( 'se_txtde_*' ) );

		$this->assertSame( array( 'de' ), LanguageFields::present( $applied, array( 'de' ) ) );
	}

	public function test_fields_are_patterns_and_are_not_stored(): void {
		$fields = LanguageFields::fields( array( 'de' ) );

		$this->assertCount( 1, $fields );
		$this->assertSame( 'se_txtde_*', $fields[0]->name() );
		$this->assertSame( 'text_de', $fields[0]->type() );
		$this->assertTrue( $fields[0]->is_dynamic() );

		// Unstored deliberately: these exist to be searched, and the text is
		// already stored in the neutral field beside them.
		$this->assertFalse( $fields[0]->is( 'stored' ) );
	}

	public function test_duplicate_and_unusable_subtags_are_dropped(): void {
		$fields = LanguageFields::fields( array( 'de', 'de_DE', 'de-AT', '', 'nonsense' ) );

		$this->assertCount( 1, $fields );
		$this->assertSame( 'se_txtde_*', $fields[0]->name() );
	}

	public function test_no_subtags_means_no_fields(): void {
		// A monolingual site adds nothing to its schema, which is what makes
		// this feature free for the sites that do not need it.
		$this->assertSame( array(), LanguageFields::fields( array() ) );
	}
}
