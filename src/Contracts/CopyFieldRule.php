<?php
/**
 * One copyField rule.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * A copy from one field into another.
 *
 * Two Solr constraints shape every rule SolrEngine declares. Wildcards may only
 * lead or trail, and "no copy feeds into another copy" — chains are prohibited.
 * Where an aggregate would naturally be built by chaining, the same source is
 * declared twice instead, which the guide permits: "the same source field can be
 * copied to multiple destination fields."
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class CopyFieldRule {

	/**
	 * Source field or pattern.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Destination field.
	 *
	 * @var string
	 */
	private $destination;

	/**
	 * Why this rule exists.
	 *
	 * @var string
	 */
	private $purpose;

	/**
	 * Constructor.
	 *
	 * @param string $source      Source field or pattern.
	 * @param string $destination Destination field.
	 * @param string $purpose     Human explanation.
	 */
	public function __construct( string $source, string $destination, string $purpose = '' ) {
		$this->source      = $source;
		$this->destination = $destination;
		$this->purpose     = $purpose;
	}

	/**
	 * Source field or pattern.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Destination field.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function destination(): string {
		return $this->destination;
	}

	/**
	 * Why this rule exists.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function purpose(): string {
		return $this->purpose;
	}

	/**
	 * Whether Solr will accept this rule.
	 *
	 * A wildcard destination is only legal when the source has one too.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		$source_wild      = false !== strpos( $this->source, '*' );
		$destination_wild = false !== strpos( $this->destination, '*' );

		if ( $destination_wild && ! $source_wild ) {
			return false;
		}

		foreach ( array( $this->source, $this->destination ) as $name ) {
			if ( false === strpos( $name, '*' ) ) {
				continue;
			}

			if ( 1 !== substr_count( $name, '*' ) ) {
				return false;
			}

			if ( 0 !== strpos( $name, '*' ) && '*' !== substr( $name, -1 ) ) {
				return false;
			}
		}

		return '' !== $this->source && '' !== $this->destination;
	}

	/**
	 * A stable key identifying this rule.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->source . '=>' . $this->destination;
	}

	/**
	 * The rule as a Schema API payload.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,string>
	 */
	public function to_payload(): array {
		return array(
			'source' => $this->source,
			'dest'   => $this->destination,
		);
	}
}
