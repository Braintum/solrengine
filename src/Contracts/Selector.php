<?php
/**
 * Describes a set of documents without listing them.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * A structured description of which documents an operation applies to.
 *
 * Exists so that "remove everything for this site" is one request rather than
 * an enumeration of a million identifiers. Deliberately not a query string: a
 * backend translates these fields into whatever its engine understands, and
 * accepting raw syntax here would put engine vocabulary above the adapter and
 * make the Cloud backend impossible.
 *
 * Immutable.
 *
 * @since 0.3.0 Unstable until 1.0.
 */
final class Selector {

	/**
	 * Site to restrict to, or null for any.
	 *
	 * @var int|null
	 */
	private $site_id;

	/**
	 * Object type to restrict to, or an empty string for any.
	 *
	 * @var string
	 */
	private $object_type;

	/**
	 * Post type to restrict to, or an empty string for any.
	 *
	 * @var string
	 */
	private $post_type;

	/**
	 * Schema contract version to restrict to, or 0 for any.
	 *
	 * @var int
	 */
	private $older_than_schema;

	/**
	 * Constructor.
	 *
	 * @param int|null $site_id           Site to restrict to.
	 * @param string   $object_type       Object type to restrict to.
	 * @param string   $post_type         Post type to restrict to.
	 * @param int      $older_than_schema Match documents below this contract version.
	 */
	public function __construct(
		?int $site_id = null,
		string $object_type = '',
		string $post_type = '',
		int $older_than_schema = 0
	) {
		$this->site_id           = $site_id;
		$this->object_type       = $object_type;
		$this->post_type         = $post_type;
		$this->older_than_schema = $older_than_schema;
	}

	/**
	 * Site to restrict to, or null.
	 *
	 * @since 0.3.0
	 *
	 * @return int|null
	 */
	public function site_id(): ?int {
		return $this->site_id;
	}

	/**
	 * Object type to restrict to, or an empty string.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function object_type(): string {
		return $this->object_type;
	}

	/**
	 * Post type to restrict to, or an empty string.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function post_type(): string {
		return $this->post_type;
	}

	/**
	 * Schema contract version below which documents match, or 0.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	public function older_than_schema(): int {
		return $this->older_than_schema;
	}

	/**
	 * Whether this selector constrains anything at all.
	 *
	 * A backend must refuse an unconstrained selector. "Delete everything"
	 * should be an explicit, separate decision, not something reachable by
	 * forgetting to set a field.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function is_unconstrained(): bool {
		return null === $this->site_id
			&& '' === $this->object_type
			&& '' === $this->post_type
			&& 0 === $this->older_than_schema;
	}
}
