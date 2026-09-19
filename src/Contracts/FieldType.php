<?php
/**
 * The type families a document field can have.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Maps a logical type onto SolrEngine's field naming convention.
 *
 * A class of constants rather than an enum, because the supported PHP floor is
 * 7.4 (ADR-011).
 *
 * The prefix is what makes arbitrary metadata indexable without a schema
 * change: "price" declared as a float becomes "se_f_meta_price", which the
 * se_f_* dynamic pattern already covers. Choosing a type is therefore the only
 * thing an administrator has to decide when allowlisting a meta key, and it is
 * exactly the decision Solr's schemaless mode makes silently and wrongly by
 * guessing from the first value it sees.
 *
 * @since 0.3.0 Unstable until 1.0.
 */
final class FieldType {

	public const TEXT       = 'txt';
	public const TEXT_MULTI = 'txts';
	public const STRING     = 's';
	public const STRINGS    = 'ss';
	public const INT        = 'i';
	public const INTS       = 'is';
	public const LONG       = 'l';
	public const LONGS      = 'ls';
	public const FLOAT      = 'f';
	public const DOUBLE     = 'd';
	public const BOOL       = 'b';
	public const DATE       = 'dt';
	public const DATES      = 'dts';

	/**
	 * Every supported prefix.
	 *
	 * @var string[]
	 */
	private const ALL = array(
		self::TEXT,
		self::TEXT_MULTI,
		self::STRING,
		self::STRINGS,
		self::INT,
		self::INTS,
		self::LONG,
		self::LONGS,
		self::FLOAT,
		self::DOUBLE,
		self::BOOL,
		self::DATE,
		self::DATES,
	);

	/**
	 * Prefixes that hold more than one value.
	 *
	 * @var string[]
	 */
	private const MULTI = array(
		self::TEXT_MULTI,
		self::STRINGS,
		self::INTS,
		self::LONGS,
		self::DATES,
	);

	/**
	 * Whether a prefix is one SolrEngine understands.
	 *
	 * @since 0.3.0
	 *
	 * @param string $type Type prefix.
	 * @return bool
	 */
	public static function is_valid( string $type ): bool {
		return in_array( $type, self::ALL, true );
	}

	/**
	 * Whether a prefix holds multiple values.
	 *
	 * @since 0.3.0
	 *
	 * @param string $type Type prefix.
	 * @return bool
	 */
	public static function is_multi( string $type ): bool {
		return in_array( $type, self::MULTI, true );
	}

	/**
	 * Build a field name from a type and a logical name.
	 *
	 * @since 0.3.0
	 *
	 * @param string $type Type prefix, one of this class's constants.
	 * @param string $name Logical name, for example "meta_price".
	 * @return string Field name, for example "se_f_meta_price".
	 */
	public static function field( string $type, string $name ): string {
		return 'se_' . $type . '_' . $name;
	}

	/**
	 * Every supported prefix.
	 *
	 * @since 0.3.0
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return self::ALL;
	}
}
