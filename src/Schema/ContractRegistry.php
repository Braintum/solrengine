<?php
/**
 * Schema contract definitions.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Schema;

use SolrEngine\Contracts\CopyFieldRule;
use SolrEngine\Contracts\SchemaContract;
use SolrEngine\Contracts\SchemaField;

/**
 * Builds the schema contract for a given version.
 *
 * The contract is data, not behaviour, which is what lets the same definition
 * drive schema comparison, the Schema API plan, the exported XML and the
 * shipped configset without any of them drifting from the others.
 *
 * Every name is prefixed "se_" so SolrEngine can share a collection with
 * another application safely, and so its own fields are identifiable as a set.
 * Every dynamic pattern carries a trailing wildcard, because Solr accepts only
 * leading or trailing asterisks and these patterns are copyField sources. See
 * the Phase 1 addendum to ADR-002.
 *
 * @since 0.1.0
 */
final class ContractRegistry {

	/**
	 * Field types the contract relies on.
	 *
	 * All are present in Solr's default configset. SolrEngine will not create
	 * field types for itself: an analyser definition is a far larger commitment
	 * than a field, and getting one wrong on a customer's cluster is not
	 * recoverable by adding another field. Where a type is missing, the plan
	 * reports a blocker and the administrator applies the shipped configset.
	 *
	 * @var array<string,string>
	 */
	public const REQUIRED_TYPES = array(
		'string'       => 'Exact values, sorting and faceting.',
		'text_general' => 'Analysed text for searching.',
		'pint'         => 'Integers.',
		'plong'        => 'Large integers.',
		'pfloat'       => 'Decimals.',
		'pdouble'      => 'Wide decimals.',
		'pdate'        => 'Timestamps.',
		'boolean'      => 'Flags.',
	);

	/**
	 * The highest contract version this build knows how to satisfy.
	 *
	 * @var int
	 */
	public const LATEST = 1;

	/**
	 * Primary subtags this site wants analysed fields for.
	 *
	 * @var string[]
	 */
	private $languages;

	/**
	 * Constructor.
	 *
	 * Languages are part of the contract rather than an argument to every caller
	 * of it, because five places ask for the current contract — the diagnostic,
	 * the planner, the exporter, the CLI and the Tools screen — and a contract
	 * that meant something different depending on who asked would produce a plan
	 * that disagreed with the schema it was compared against.
	 *
	 * They do not change the contract *version*. An analysed field for German is
	 * one site's configuration, not a new revision of what SolrEngine requires,
	 * and bumping the version for it would tell every other site on every other
	 * server that they are out of date.
	 *
	 * @param string[] $languages Primary subtags, for example "en", "de".
	 */
	public function __construct( array $languages = array() ) {
		$this->languages = array_values( array_unique( array_map( 'strval', $languages ) ) );
	}

	/**
	 * Build a contract.
	 *
	 * An unknown version falls back to the latest rather than failing. A site
	 * whose stored state names a version from a newer build — after a
	 * downgrade, or a partially restored backup — should still get a usable
	 * contract and a schema diff to look at, instead of a fatal error on the
	 * one screen that would have explained the problem.
	 *
	 * @since 0.1.0
	 *
	 * @param int $version Contract version.
	 * @return SchemaContract
	 */
	public function get( int $version ): SchemaContract {
		$resolved = ( $version >= 1 && $version <= self::LATEST ) ? $version : self::LATEST;

		return new SchemaContract(
			$resolved,
			array_merge( $this->fields_v1(), LanguageFields::fields( $this->languages ) ),
			$this->copy_fields_v1()
		);
	}

	/**
	 * The subtags this contract carries analysed fields for.
	 *
	 * @since 0.9.0
	 *
	 * @return string[]
	 */
	public function languages(): array {
		return $this->languages;
	}

	/**
	 * The current contract.
	 *
	 * @since 0.1.0
	 *
	 * @return SchemaContract
	 */
	public function current(): SchemaContract {
		return $this->get( SOLRENGINE_SCHEMA_CONTRACT_VERSION );
	}

	/**
	 * Fields required by contract version 1.
	 *
	 * @return SchemaField[]
	 */
	private function fields_v1(): array {
		return array(
			// Dynamic patterns. These are what make arbitrary WordPress
			// metadata indexable without any schema change at all, which is
			// the single highest-leverage decision in ADR-003.
			new SchemaField( 'se_txt_*', 'text_general', array( 'docValues' => false ), 'Analysed text, single valued.' ),
			new SchemaField( 'se_txts_*', 'text_general', array( 'multiValued' => true ), 'Analysed text, multi valued.' ),
			new SchemaField( 'se_s_*', 'string', array( 'docValues' => true ), 'Exact values, sorting, faceting.' ),
			new SchemaField(
				'se_ss_*',
				'string',
				array(
					'multiValued' => true,
					'docValues'   => true,
				),
				'Multi-valued exact values for faceting.'
			),
			new SchemaField( 'se_i_*', 'pint', array( 'docValues' => true ), 'Integers.' ),
			new SchemaField(
				'se_is_*',
				'pint',
				array(
					'multiValued' => true,
					'docValues'   => true,
				),
				'Multi-valued integers, such as term IDs.'
			),
			new SchemaField( 'se_l_*', 'plong', array( 'docValues' => true ), 'Large integers.' ),
			new SchemaField(
				'se_ls_*',
				'plong',
				array(
					'multiValued' => true,
					'docValues'   => true,
				),
				'Multi-valued large integers.'
			),
			new SchemaField( 'se_f_*', 'pfloat', array( 'docValues' => true ), 'Decimals, such as prices.' ),
			new SchemaField( 'se_d_*', 'pdouble', array( 'docValues' => true ), 'Wide decimals.' ),
			new SchemaField( 'se_b_*', 'boolean', array( 'docValues' => true ), 'Flags.' ),
			new SchemaField( 'se_dt_*', 'pdate', array( 'docValues' => true ), 'Timestamps.' ),
			new SchemaField(
				'se_dts_*',
				'pdate',
				array(
					'multiValued' => true,
					'docValues'   => true,
				),
				'Multi-valued timestamps.'
			),

			// Concrete fields. Declared explicitly rather than left to a
			// pattern, because each has a job that the generic type would not
			// describe, and because a diff reads better against real names.
			new SchemaField(
				'se_all',
				'text_general',
				array(
					'stored'      => false,
					'multiValued' => true,
				),
				'Catch-all search field. Not stored: it exists to be searched, and storing it would duplicate the content.'
			),
			new SchemaField(
				'se_taxterms',
				'text_general',
				array(
					'stored'      => false,
					'multiValued' => true,
				),
				'Every taxonomy term name, so relevance can weight taxonomy matches with one qf entry. Needed because qf does not accept wildcard field names.'
			),
			new SchemaField(
				'se_suggest',
				'text_general',
				array(
					'stored'      => true,
					'multiValued' => true,
				),
				'Source for the autocomplete suggester, built in Phase 4.'
			),
		);
	}

	/**
	 * Copy rules required by contract version 1.
	 *
	 * Note the deliberate absence of a chain. The taxonomy aggregate is not
	 * built by copying taxonomy fields into se_taxterms and then copying that
	 * into se_all, because Solr states that "no copy feeds into another copy".
	 * The same source is declared twice instead, which is permitted.
	 *
	 * @return CopyFieldRule[]
	 */
	private function copy_fields_v1(): array {
		return array(
			new CopyFieldRule( 'se_txt_title', 'se_all', 'Titles are searchable through the catch-all field.' ),
			new CopyFieldRule( 'se_txt_excerpt', 'se_all', 'Excerpts are searchable through the catch-all field.' ),
			new CopyFieldRule( 'se_txt_content', 'se_all', 'Content is searchable through the catch-all field.' ),
			new CopyFieldRule( 'se_ss_tax_*', 'se_all', 'Taxonomy terms are searchable through the catch-all field.' ),
			new CopyFieldRule( 'se_ss_tax_*', 'se_taxterms', 'Taxonomy terms are aggregated into one boostable field.' ),
			new CopyFieldRule( 'se_txt_title', 'se_suggest', 'Titles feed autocomplete.' ),
		);
	}
}
