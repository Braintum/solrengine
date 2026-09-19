<?php
/**
 * Field name constants and value coercion.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Documents;

use SolrEngine\Contracts\FieldType;

/**
 * The names SolrEngine writes, and the rules for coercing values into them.
 *
 * One place for both, because a field name and the shape its value must take
 * are the same decision. Splitting them is how a date ends up written in the
 * wrong format: the 0.1.0 prototype emitted ISO 8601 with a numeric offset into
 * a field Solr requires to be UTC with a trailing Z, so strict date fields
 * would have rejected every document.
 *
 * @since 0.3.0
 */
final class DocumentFields {

	// Identity and ownership. Written on every document, always.
	public const SITE        = 'se_i_site';
	public const OBJECT_TYPE = 'se_s_object_type';
	public const OBJECT_ID   = 'se_i_object';
	public const POST_TYPE   = 'se_s_post_type';
	public const STATUS      = 'se_s_status';
	public const VISIBILITY  = 'se_s_visibility';
	public const LANG        = 'se_s_lang';

	// Searchable text.
	public const TITLE   = 'se_txt_title';
	public const EXCERPT = 'se_txt_excerpt';
	public const CONTENT = 'se_txt_content';

	// Display and sorting.
	public const TITLE_SORT   = 'se_s_title';
	public const URL          = 'se_s_url';
	public const SLUG         = 'se_s_slug';
	public const AUTHOR_ID    = 'se_i_author';
	public const AUTHOR_NAME  = 'se_s_author_name';
	public const DATE         = 'se_dt_date';
	public const MODIFIED     = 'se_dt_modified';
	public const MENU_ORDER   = 'se_i_menu_order';
	public const COMMENTS     = 'se_i_comments';
	public const PARENT       = 'se_i_parent';
	public const THUMBNAIL    = 'se_s_thumbnail_url';
	public const THUMBNAIL_ID = 'se_i_thumbnail';

	// Bookkeeping.
	public const SCHEMA_VERSION = 'se_i_schema_version';
	public const INDEXED_AT     = 'se_dt_indexed';

	/**
	 * Field holding a taxonomy's term names.
	 *
	 * Must stay under the "se_ss_tax_" prefix: the contract's copy rules use
	 * that glob to feed both the catch-all and the taxonomy aggregate, and a
	 * different prefix would silently stop taxonomy terms being searchable.
	 *
	 * @since 0.3.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public static function taxonomy_terms( string $taxonomy ): string {
		return 'se_ss_tax_' . self::sanitise( $taxonomy );
	}

	/**
	 * Field holding a taxonomy's term identifiers.
	 *
	 * Deliberately outside the "se_ss_tax_" glob. Term IDs are for filtering,
	 * not for searching, and copying numeric IDs into the searchable text would
	 * make a search for "34" match every post in term 34.
	 *
	 * @since 0.3.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public static function taxonomy_ids( string $taxonomy ): string {
		return 'se_is_taxid_' . self::sanitise( $taxonomy );
	}

	/**
	 * Field holding a taxonomy's materialised ancestor paths.
	 *
	 * Also outside the "se_ss_tax_" glob, for the same reason: "12/34" is a
	 * filtering construct, not something anyone searches for.
	 *
	 * @since 0.3.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	public static function taxonomy_paths( string $taxonomy ): string {
		return 'se_ss_taxpath_' . self::sanitise( $taxonomy );
	}

	/**
	 * Field holding an allowlisted meta value.
	 *
	 * @since 0.3.0
	 *
	 * @param string $type Type prefix from FieldType.
	 * @param string $key  Meta key.
	 * @return string
	 */
	public static function meta( string $type, string $key ): string {
		return FieldType::field( $type, 'meta_' . self::sanitise( $key ) );
	}

	/**
	 * Make an arbitrary WordPress name safe to use inside a field name.
	 *
	 * Solr requires alphanumerics and underscores. WordPress permits far more
	 * in a meta key, so anything else becomes an underscore. Collisions are
	 * possible in principle — "a-b" and "a.b" both become "a_b" — and the meta
	 * allowlist rejects a key whose sanitised form is already taken, so the
	 * ambiguity is caught at configuration time rather than silently merging
	 * two fields at index time.
	 *
	 * @since 0.3.0
	 *
	 * @param string $name Name to sanitise.
	 * @return string
	 */
	public static function sanitise( string $name ): string {
		$clean = (string) preg_replace( '/[^A-Za-z0-9_]/', '_', $name );
		$clean = trim( $clean, '_' );

		return '' === $clean ? 'unnamed' : strtolower( $clean );
	}

	/**
	 * Format a timestamp the way Solr's date fields require.
	 *
	 * UTC, with a trailing Z. Not ISO 8601 with a numeric offset, which is what
	 * get_post_time( 'c' ) returns and what the prototype wrote.
	 *
	 * @since 0.3.0
	 *
	 * @param string|int $value A MySQL datetime in GMT, or a Unix timestamp.
	 * @return string|null Formatted timestamp, or null when unusable.
	 */
	public static function date( $value ): ?string {
		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', (int) $value );
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		// WordPress stores "0000-00-00 00:00:00" for an unset date, which is
		// not a date and must not reach a strict date field.
		if ( 0 === strpos( $value, '0000-00-00' ) ) {
			return null;
		}

		$timestamp = strtotime( $value . ' UTC' );

		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Coerce a value into the shape its field type requires.
	 *
	 * Returns null when the value cannot be represented, and the caller then
	 * omits the field rather than writing something Solr will reject. Omitting
	 * one field loses one filter; a rejected document loses the whole record.
	 *
	 * @since 0.3.0
	 *
	 * @param string $type  Type prefix from FieldType.
	 * @param mixed  $value Raw value.
	 * @return mixed|null
	 */
	public static function coerce( string $type, $value ) {
		if ( null === $value ) {
			return null;
		}

		if ( FieldType::is_multi( $type ) ) {
			return self::coerce_many( $type, $value );
		}

		if ( is_array( $value ) ) {
			// A single-valued field given an array takes the first usable
			// member, because a plugin storing one value as a one-element
			// array is common and rejecting it would surprise people.
			$value = reset( $value );

			if ( false === $value ) {
				return null;
			}
		}

		return self::coerce_one( $type, $value );
	}

	/**
	 * Coerce a value for a multi-valued field.
	 *
	 * @param string $type  Type prefix.
	 * @param mixed  $value Raw value.
	 * @return array<int,mixed>|null
	 */
	private static function coerce_many( string $type, $value ): ?array {
		$single = self::single_of( $type );
		$items  = is_array( $value ) ? $value : array( $value );
		$out    = array();

		foreach ( $items as $item ) {
			$coerced = self::coerce_one( $single, $item );

			if ( null !== $coerced ) {
				$out[] = $coerced;
			}
		}

		return array() === $out ? null : array_values( array_unique( $out, SORT_REGULAR ) );
	}

	/**
	 * Coerce one scalar.
	 *
	 * @param string $type  Type prefix.
	 * @param mixed  $value Raw value.
	 * @return mixed|null
	 */
	private static function coerce_one( string $type, $value ) {
		if ( is_object( $value ) || is_array( $value ) ) {
			return null;
		}

		switch ( $type ) {
			case FieldType::INT:
			case FieldType::LONG:
				return is_numeric( $value ) ? (int) $value : null;

			case FieldType::FLOAT:
			case FieldType::DOUBLE:
				return is_numeric( $value ) ? (float) $value : null;

			case FieldType::BOOL:
				return self::to_bool( $value );

			case FieldType::DATE:
				return self::date( is_bool( $value ) ? null : $value );

			default:
				$string = is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value;

				return '' === $string ? null : $string;
		}
	}

	/**
	 * The single-valued equivalent of a multi-valued type.
	 *
	 * @param string $type Type prefix.
	 * @return string
	 */
	private static function single_of( string $type ): string {
		$map = array(
			FieldType::TEXT_MULTI => FieldType::TEXT,
			FieldType::STRINGS    => FieldType::STRING,
			FieldType::INTS       => FieldType::INT,
			FieldType::LONGS      => FieldType::LONG,
			FieldType::DATES      => FieldType::DATE,
		);

		return $map[ $type ] ?? $type;
	}

	/**
	 * Interpret a value as a boolean the way WordPress data tends to mean it.
	 *
	 * Meta is stored as strings, so "0", "no" and "" all mean false in
	 * practice even though PHP would disagree about some of them.
	 *
	 * @param mixed $value Raw value.
	 * @return bool|null
	 */
	private static function to_bool( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$normalised = strtolower( trim( (string) $value ) );

		if ( in_array( $normalised, array( '1', 'true', 'yes', 'on' ), true ) ) {
			return true;
		}

		if ( in_array( $normalised, array( '0', 'false', 'no', 'off', '' ), true ) ) {
			return false;
		}

		return null;
	}
}
