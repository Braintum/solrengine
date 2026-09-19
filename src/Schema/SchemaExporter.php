<?php
/**
 * Renders a contract as Solr configuration.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Schema;

use SolrEngine\Contracts\SchemaContract;
use SolrEngine\Contracts\SchemaField;

/**
 * Turns a schema contract into XML an administrator can paste into a schema.
 *
 * The fallback that makes the whole strategy honest. A managed Solr service, a
 * locked-down cluster, or an account without schema authorisation cannot be
 * changed through the Schema API — and without this, those customers would have
 * no route at all, which would quietly make "bring your own Solr" mean "bring
 * your own Solr that we are allowed to modify".
 *
 * Generated from the same contract that drives the Schema API plan, so the two
 * cannot drift.
 *
 * Pure: no Solr, no WordPress, no network.
 *
 * @since 0.1.0
 */
final class SchemaExporter {

	/**
	 * Render a contract as a managed-schema fragment.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaContract $contract Contract to render.
	 * @return string
	 */
	public function to_xml( SchemaContract $contract ): string {
		$lines = array();

		$lines[] = '<!--';
		$lines[] = '  SolrEngine schema contract version ' . $contract->version() . '.';
		$lines[] = '';
		$lines[] = '  Paste these into your managed-schema or schema.xml, inside <schema>.';
		$lines[] = '  Every name is prefixed "se_" so nothing here can collide with fields';
		$lines[] = '  belonging to another application in the same collection.';
		$lines[] = '';
		$lines[] = '  Field types used (string, text_general, pint, plong, pfloat, pdouble,';
		$lines[] = '  pdate, boolean) are all present in Solr\'s default configset. If yours';
		$lines[] = '  lacks any of them, define them before adding these fields.';
		$lines[] = '-->';
		$lines[] = '';

		$lines[] = '<!-- Dynamic patterns. These carry arbitrary WordPress metadata, so';
		$lines[] = '     indexing a new custom field needs no schema change at all. -->';

		foreach ( $contract->dynamic_fields() as $field ) {
			$lines[] = $this->field_element( 'dynamicField', $field );
		}

		$lines[] = '';
		$lines[] = '<!-- Concrete fields. -->';

		foreach ( $contract->concrete_fields() as $field ) {
			$lines[] = $this->field_element( 'field', $field );
		}

		$lines[] = '';
		$lines[] = '<!-- Copy rules. Note that none chains into another: Solr states that';
		$lines[] = '     "no copy feeds into another copy", so the taxonomy source is';
		$lines[] = '     declared twice rather than copied onward. -->';

		foreach ( $contract->copy_fields() as $rule ) {
			$lines[] = sprintf(
				'<copyField source="%s" dest="%s"/>',
				$this->attribute( $rule->source() ),
				$this->attribute( $rule->destination() )
			);
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Render one field or dynamic field element.
	 *
	 * @param string      $element Element name.
	 * @param SchemaField $field   Field to render.
	 * @return string
	 */
	private function field_element( string $element, SchemaField $field ): string {
		$attributes = sprintf(
			'name="%s" type="%s" indexed="%s" stored="%s"',
			$this->attribute( $field->name() ),
			$this->attribute( $field->type() ),
			$field->is( 'indexed' ) ? 'true' : 'false',
			$field->is( 'stored' ) ? 'true' : 'false'
		);

		if ( $field->is( 'multiValued' ) ) {
			$attributes .= ' multiValued="true"';
		}

		if ( $field->is( 'docValues' ) ) {
			$attributes .= ' docValues="true"';
		}

		if ( $field->is( 'required' ) ) {
			$attributes .= ' required="true"';
		}

		return sprintf( '<%s %s/>', $element, $attributes );
	}

	/**
	 * Escape a value for use in an XML attribute.
	 *
	 * The values are ours rather than a user's, but generating markup without
	 * escaping is a habit worth not having.
	 *
	 * @param string $value Value to escape.
	 * @return string
	 */
	private function attribute( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
