<?php
/**
 * Stable reference to one indexed object.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Identifies one indexable object without carrying its content.
 *
 * The indexing queue moves references, never documents. A job that carried a
 * built document would index whatever the content was when the job was
 * created, not when it ran, which produces stale documents whenever a post is
 * edited twice in quick succession. References are also far smaller, which
 * matters when a term rename fans out across fifty thousand posts.
 *
 * The composite identity is what makes IDs collision-free across post types,
 * object types and sites. See ADR-002.
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class DocumentRef {

	/**
	 * Site identifier. Always written, even on single-site installs.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Object type, for example "post", "term" or "user".
	 *
	 * @var string
	 */
	private $object_type;

	/**
	 * WordPress object identifier.
	 *
	 * @var int
	 */
	private $object_id;

	/**
	 * Constructor.
	 *
	 * @param int    $site_id     Site identifier.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object identifier.
	 */
	public function __construct( int $site_id, string $object_type, int $object_id ) {
		$this->site_id     = $site_id;
		$this->object_type = $object_type;
		$this->object_id   = $object_id;
	}

	/**
	 * Rebuild a reference from a document ID.
	 *
	 * The inverse of document_id(). Needed because a failure is reported by the
	 * backend against a document ID, and a human looking at a list of failures
	 * wants a post to open rather than a string to decode.
	 *
	 * Returns null for anything that is not one of our IDs, including an ID
	 * from a future format. A malformed record in a log is a row to skip, not
	 * an error to raise.
	 *
	 * @since 0.8.0
	 *
	 * @param string $id Document ID.
	 * @return self|null
	 */
	public static function from_document_id( string $id ): ?self {
		$parts = explode( ':', $id );

		if ( 4 !== count( $parts ) || 'se' !== $parts[0] ) {
			return null;
		}

		if ( '' === $parts[2] || ! ctype_digit( $parts[1] ) || ! ctype_digit( $parts[3] ) ) {
			return null;
		}

		return new self( (int) $parts[1], $parts[2], (int) $parts[3] );
	}

	/**
	 * Site identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function site_id(): int {
		return $this->site_id;
	}

	/**
	 * Object type.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function object_type(): string {
		return $this->object_type;
	}

	/**
	 * Object identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function object_id(): int {
		return $this->object_id;
	}

	/**
	 * The backend-facing document identifier.
	 *
	 * Format: "se:{site_id}:{object_type}:{object_id}". Stable across reindexes,
	 * so writes are upserts and a rebuild is idempotent.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function document_id(): string {
		return sprintf( 'se:%d:%s:%d', $this->site_id, $this->object_type, $this->object_id );
	}

	/**
	 * Deduplication key for the request-scoped dirty set.
	 *
	 * Identical to the document ID today. It exists separately because
	 * coalescing is a WordPress-side concern and the document ID is a
	 * backend-side one; keeping them distinct means changing the ID format
	 * later does not silently change deduplication behaviour.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function dedupe_key(): string {
		return $this->document_id();
	}

	/**
	 * Whether this reference points at the same object as another.
	 *
	 * @since 0.1.0
	 *
	 * @param DocumentRef $other Reference to compare against.
	 * @return bool
	 */
	public function equals( DocumentRef $other ): bool {
		return $this->dedupe_key() === $other->dedupe_key();
	}
}
