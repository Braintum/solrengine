<?php
/**
 * One autocomplete suggestion.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Something a visitor can pick from an autocomplete list.
 *
 * Carries a URL, because SolrEngine's suggestions are documents rather than
 * query completions. A visitor typing "macb" wants to reach the MacBook review,
 * not to be handed the word "macbook" and made to press enter again.
 *
 * Immutable.
 *
 * @since 0.5.0 Unstable until 1.0.
 */
final class Suggestion {

	/**
	 * Text shown in the list.
	 *
	 * @var string
	 */
	private $text;

	/**
	 * Where picking it goes.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * What it refers to, when it refers to an object.
	 *
	 * @var DocumentRef|null
	 */
	private $ref;

	/**
	 * Post type, for grouping or an icon.
	 *
	 * @var string
	 */
	private $post_type;

	/**
	 * Constructor.
	 *
	 * @param string           $text      Text shown in the list.
	 * @param string           $url       Where picking it goes.
	 * @param DocumentRef|null $ref       What it refers to.
	 * @param string           $post_type Post type.
	 */
	public function __construct( string $text, string $url = '', ?DocumentRef $ref = null, string $post_type = '' ) {
		$this->text      = $text;
		$this->url       = $url;
		$this->ref       = $ref;
		$this->post_type = $post_type;
	}

	/**
	 * Text shown in the list.
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function text(): string {
		return $this->text;
	}

	/**
	 * Where picking it goes.
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * What it refers to, or null.
	 *
	 * @since 0.5.0
	 *
	 * @return DocumentRef|null
	 */
	public function ref(): ?DocumentRef {
		return $this->ref;
	}

	/**
	 * Post type.
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function post_type(): string {
		return $this->post_type;
	}
}
