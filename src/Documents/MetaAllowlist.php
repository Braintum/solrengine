<?php
/**
 * Which post meta keys may be indexed.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Documents;

use SolrEngine\Contracts\FieldType;
use SolrEngine\Contracts\Result;

/**
 * The set of meta keys an administrator has chosen to index, with their types.
 *
 * An allowlist rather than a blocklist, and empty by default. The 0.1.0
 * prototype indexed every meta key a post had, including private "_"-prefixed
 * ones, which puts licence keys, API tokens, internal editorial notes and
 * personal data into a search index. In a future hosted mode it would put them
 * on someone else's server.
 *
 * Each entry carries a declared type. That is the one thing an administrator
 * must decide per key, and it is exactly the decision Solr's schemaless mode
 * makes by guessing from the first value it happens to see — which is why a
 * meta key that usually holds a date and once holds "N/A" breaks indexing
 * permanently under schemaless and merely drops one value here.
 *
 * @since 0.3.0
 */
final class MetaAllowlist {

	/**
	 * Declared types keyed by meta key.
	 *
	 * @var array<string,string>
	 */
	private $types = array();

	/**
	 * Meta keys keyed by their sanitised field suffix, for collision detection.
	 *
	 * @var array<string,string>
	 */
	private $by_field = array();

	/**
	 * Build from stored configuration.
	 *
	 * Invalid entries are dropped rather than failing the whole list, because
	 * one bad row in stored configuration should not stop a site indexing.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string,string> $config Meta key to type prefix.
	 * @return self
	 */
	public static function from_config( array $config ): self {
		$list = new self();

		foreach ( $config as $key => $type ) {
			$list->add( (string) $key, (string) $type );
		}

		return $list;
	}

	/**
	 * Add a key.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key  Meta key.
	 * @param string $type Type prefix from FieldType.
	 * @return Result
	 */
	public function add( string $key, string $type ): Result {
		$key = trim( $key );

		if ( '' === $key ) {
			return Result::failure( 'empty_key', __( 'A meta key cannot be empty.', 'solrengine' ) );
		}

		if ( ! FieldType::is_valid( $type ) ) {
			return Result::failure(
				'bad_type',
				sprintf(
					/* translators: %s: the type that was given. */
					__( '"%s" is not a field type SolrEngine understands.', 'solrengine' ),
					$type
				)
			);
		}

		$field = DocumentFields::sanitise( $key );

		// Two different meta keys can sanitise to the same field name, for
		// example "a-b" and "a.b". Catching that here means an administrator
		// sees a clear message at configuration time, rather than two keys
		// silently overwriting each other in every document.
		if ( isset( $this->by_field[ $field ] ) && $this->by_field[ $field ] !== $key ) {
			return Result::failure(
				'field_collision',
				sprintf(
					/* translators: 1: meta key being added, 2: meta key already present. */
					__( '"%1$s" would use the same index field as "%2$s". Rename one of them.', 'solrengine' ),
					$key,
					$this->by_field[ $field ]
				)
			);
		}

		$this->types[ $key ]      = $type;
		$this->by_field[ $field ] = $key;

		return Result::success();
	}

	/**
	 * Whether a key is allowed.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public function allows( string $key ): bool {
		return isset( $this->types[ $key ] );
	}

	/**
	 * The declared type for a key, or an empty string.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	public function type_of( string $key ): string {
		return $this->types[ $key ] ?? '';
	}

	/**
	 * Every allowed key with its type.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string,string>
	 */
	public function all(): array {
		return $this->types;
	}

	/**
	 * Whether anything is allowed at all.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->types;
	}

	/**
	 * Whether a meta key is private by WordPress convention.
	 *
	 * Private keys are not blocked outright, because plugins legitimately store
	 * useful data in them and a store's product price lives in "_price". They
	 * are hidden from the allowlist UI by default and require a deliberate
	 * choice, which is the difference between a considered decision and an
	 * accident.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_private( string $key ): bool {
		return 0 === strpos( $key, '_' );
	}
}
