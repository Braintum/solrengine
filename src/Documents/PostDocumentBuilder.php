<?php
/**
 * Builds documents from WordPress posts.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Documents;

use SolrEngine\Contracts\Document;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Contracts\Result;
use WP_Post;
use WP_Term;

/**
 * Turns a post into an index document.
 *
 * Decides three things: whether the object may be indexed at all, which fields
 * it contributes, and what shape each value takes.
 *
 * The visibility rules are conservative by default and enforced here rather
 * than at query time. Not indexing private content is a stronger guarantee than
 * filtering it out of results, because it cannot be undone by a bug in a filter
 * or by a future code path that forgets one.
 *
 * @since 0.3.0
 */
final class PostDocumentBuilder {

	/**
	 * Which meta keys may be indexed.
	 *
	 * @var MetaAllowlist
	 */
	private $allowlist;

	/**
	 * Site identifier written on every document.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Largest content length indexed, in bytes.
	 *
	 * @var int
	 */
	private $max_content_bytes;

	/**
	 * Constructor.
	 *
	 * @param MetaAllowlist $allowlist         Allowed meta keys.
	 * @param int           $site_id           Site identifier.
	 * @param int           $max_content_bytes Content truncation limit.
	 */
	public function __construct( MetaAllowlist $allowlist, int $site_id, int $max_content_bytes = 524288 ) {
		$this->allowlist         = $allowlist;
		$this->site_id           = $site_id;
		$this->max_content_bytes = $max_content_bytes;
	}

	/**
	 * Whether a post should be indexed.
	 *
	 * @since 0.3.0
	 *
	 * @param WP_Post $post Post to consider.
	 * @return Result Success when indexable; a failure whose code says why not.
	 */
	public function should_index( WP_Post $post ): Result {
		$verdict = $this->assess( $post );

		/**
		 * Filters whether one object is indexed.
		 *
		 * Return a failure Result to veto, with a code explaining why. The
		 * reason is recorded, so a vetoed object shows up as "skipped" rather
		 * than as silently missing.
		 *
		 * @since 0.3.0
		 *
		 * @param Result  $verdict Whether to index, and why not if not.
		 * @param WP_Post $post    The post being considered.
		 */
		$verdict = apply_filters( 'solrengine_should_index_object', $verdict, $post );

		return $verdict instanceof Result ? $verdict : Result::success();
	}

	/**
	 * Build a document.
	 *
	 * @since 0.3.0
	 *
	 * @param WP_Post $post Post to map.
	 * @return Result Success carrying a Document, or a failure.
	 */
	public function build( WP_Post $post ): Result {
		$indexable = $this->should_index( $post );

		if ( $indexable->is_failure() ) {
			return $indexable;
		}

		$ref    = new DocumentRef( $this->site_id, 'post', $post->ID );
		$fields = array();

		$this->set( $fields, DocumentFields::SITE, FieldType::INT, $this->site_id );
		$this->set( $fields, DocumentFields::OBJECT_TYPE, FieldType::STRING, 'post' );
		$this->set( $fields, DocumentFields::OBJECT_ID, FieldType::INT, $post->ID );
		$this->set( $fields, DocumentFields::POST_TYPE, FieldType::STRING, $post->post_type );
		$this->set( $fields, DocumentFields::STATUS, FieldType::STRING, $post->post_status );
		$this->set( $fields, DocumentFields::VISIBILITY, FieldType::STRING, $this->visibility_of( $post ) );

		// Title is indexed twice on purpose: analysed for searching, and as a
		// string for sorting, which needs docValues and cannot be analysed.
		$this->set( $fields, DocumentFields::TITLE, FieldType::TEXT, $post->post_title );
		$this->set( $fields, DocumentFields::TITLE_SORT, FieldType::STRING, $post->post_title );

		$this->set( $fields, DocumentFields::EXCERPT, FieldType::TEXT, $this->excerpt_of( $post ) );

		// Password-protected posts are indexed as metadata only. Their content
		// is the thing the password protects.
		if ( '' === $post->post_password ) {
			$this->set( $fields, DocumentFields::CONTENT, FieldType::TEXT, $this->content_of( $post ) );
		}

		$this->set( $fields, DocumentFields::SLUG, FieldType::STRING, $post->post_name );
		$this->set( $fields, DocumentFields::URL, FieldType::STRING, get_permalink( $post ) );
		$this->set( $fields, DocumentFields::AUTHOR_ID, FieldType::INT, $post->post_author );
		$this->set( $fields, DocumentFields::AUTHOR_NAME, FieldType::STRING, $this->author_of( $post ) );
		$this->set( $fields, DocumentFields::DATE, FieldType::DATE, $post->post_date_gmt );
		$this->set( $fields, DocumentFields::MODIFIED, FieldType::DATE, $post->post_modified_gmt );
		$this->set( $fields, DocumentFields::MENU_ORDER, FieldType::INT, $post->menu_order );
		$this->set( $fields, DocumentFields::COMMENTS, FieldType::INT, $post->comment_count );
		$this->set( $fields, DocumentFields::PARENT, FieldType::INT, $post->post_parent );

		$this->add_thumbnail( $fields, $post );
		$this->add_taxonomies( $fields, $post );
		$this->add_meta( $fields, $post );

		$this->set( $fields, DocumentFields::SCHEMA_VERSION, FieldType::INT, SOLRENGINE_SCHEMA_CONTRACT_VERSION );
		$this->set( $fields, DocumentFields::INDEXED_AT, FieldType::DATE, time() );

		$document = new Document( $ref, $fields );

		/**
		 * Filters a document immediately before it is queued for indexing.
		 *
		 * The primary customisation point. Field names must follow SolrEngine's
		 * conventions, since anything else has no dynamic field to land in and
		 * will be rejected by the backend.
		 *
		 * @since 0.3.0
		 *
		 * @param Document $document The built document.
		 * @param WP_Post  $post     The post it was built from.
		 */
		$document = apply_filters( 'solrengine_document', $document, $post );

		return $document instanceof Document
			? Result::success( $document )
			: Result::failure( 'filter_returned_non_document', 'A solrengine_document filter returned something that is not a Document.' );
	}

	/**
	 * Decide whether a post is indexable, before filters.
	 *
	 * @param WP_Post $post Post to assess.
	 * @return Result
	 */
	private function assess( WP_Post $post ): Result {
		if ( wp_is_post_revision( $post->ID ) ) {
			return Result::failure( 'revision', 'Revisions are never indexed.' );
		}

		if ( wp_is_post_autosave( $post->ID ) ) {
			return Result::failure( 'autosave', 'Autosaves are never indexed.' );
		}

		$type = get_post_type_object( $post->post_type );

		if ( null === $type ) {
			return Result::failure( 'unknown_post_type', 'The post type is not registered.' );
		}

		$status = get_post_status_object( $post->post_status );

		if ( null === $status || ! $status->public ) {
			// Covers draft, pending, future, trash, auto-draft and any private
			// status a plugin registers. Scheduled posts arrive through the
			// status transition when they are published.
			return Result::failure( 'not_public_status', 'Only publicly visible content is indexed by default.' );
		}

		return Result::success();
	}

	/**
	 * How visible this post is, as a single value.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function visibility_of( WP_Post $post ): string {
		if ( '' !== $post->post_password ) {
			return 'password';
		}

		return 'private' === $post->post_status ? 'private' : 'public';
	}

	/**
	 * The excerpt, generated from content when none is set.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function excerpt_of( WP_Post $post ): string {
		if ( '' !== trim( $post->post_excerpt ) ) {
			return $post->post_excerpt;
		}

		if ( '' !== $post->post_password ) {
			return '';
		}

		return wp_trim_words( $this->content_of( $post ), 55, '' );
	}

	/**
	 * Post content as plain text.
	 *
	 * Shortcodes are stripped rather than executed. Running the_content here
	 * would execute arbitrary plugin code inside a background job, which is
	 * slow, unpredictable, and a way for a shortcode with side effects to fire
	 * once per indexed post.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function content_of( WP_Post $post ): string {
		$content = $post->post_content;

		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$content = excerpt_remove_blocks( $content );
		}

		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$content = (string) preg_replace( '/\s+/u', ' ', $content );
		$content = trim( $content );

		if ( strlen( $content ) > $this->max_content_bytes ) {
			// Truncating on a character boundary, so a multi-byte sequence is
			// never cut in half and rejected as invalid UTF-8 by the backend.
			$content = (string) mb_strcut( $content, 0, $this->max_content_bytes, 'UTF-8' );
		}

		return $content;
	}

	/**
	 * The author's display name.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function author_of( WP_Post $post ): string {
		$name = get_the_author_meta( 'display_name', (int) $post->post_author );

		return is_string( $name ) ? $name : '';
	}

	/**
	 * Add the featured image URL.
	 *
	 * @param array<string,mixed> $fields Fields being built.
	 * @param WP_Post             $post   Post.
	 * @return void
	 */
	private function add_thumbnail( array &$fields, WP_Post $post ): void {
		$thumbnail_id = (int) get_post_thumbnail_id( $post );

		if ( $thumbnail_id <= 0 ) {
			return;
		}

		$this->set( $fields, DocumentFields::THUMBNAIL_ID, FieldType::INT, $thumbnail_id );

		$url = wp_get_attachment_image_url( $thumbnail_id, 'medium' );

		if ( is_string( $url ) ) {
			$this->set( $fields, DocumentFields::THUMBNAIL, FieldType::STRING, $url );
		}
	}

	/**
	 * Add taxonomy terms, identifiers and hierarchy paths.
	 *
	 * @param array<string,mixed> $fields Fields being built.
	 * @param WP_Post             $post   Post.
	 * @return void
	 */
	private function add_taxonomies( array &$fields, WP_Post $post ): void {
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				// A private taxonomy is usually internal plumbing. Indexing it
				// would put internal labels into public search results.
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );

			if ( ! is_array( $terms ) || array() === $terms ) {
				continue;
			}

			$names = array();
			$ids   = array();
			$paths = array();

			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				$names[] = $term->name;
				$ids[]   = $term->term_id;

				foreach ( $this->paths_for( $term, $taxonomy->name ) as $path ) {
					$paths[] = $path;
				}
			}

			$this->set( $fields, DocumentFields::taxonomy_terms( $taxonomy->name ), FieldType::STRINGS, $names );
			$this->set( $fields, DocumentFields::taxonomy_ids( $taxonomy->name ), FieldType::INTS, $ids );

			if ( $taxonomy->hierarchical ) {
				$this->set( $fields, DocumentFields::taxonomy_paths( $taxonomy->name ), FieldType::STRINGS, $paths );
			}
		}
	}

	/**
	 * Materialised ancestor paths for a term.
	 *
	 * A term at 12 > 34 > 56 yields "0/12", "1/12/34" and "2/12/34/56".
	 * Faceting on this field with a prefix then returns exactly one level of
	 * the tree, with counts that include everything below each child, in a
	 * single request.
	 *
	 * The leading number is the depth, and it is what makes the top level
	 * selectable at all: without it the root of the tree is "every path with no
	 * slash in it", which a prefix cannot express, and the only alternative is
	 * counting the whole tree and discarding most of it in PHP. Added in 0.6.0;
	 * see the Phase 5 addendum to ADR-002. Documents written by an earlier
	 * version carry the unprefixed form and need a reindex before hierarchical
	 * facets find them.
	 *
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy name.
	 * @return string[]
	 */
	private function paths_for( WP_Term $term, string $taxonomy ): array {
		return TermPaths::lineage( $term, $taxonomy );
	}

	/**
	 * Add allowlisted meta values.
	 *
	 * @param array<string,mixed> $fields Fields being built.
	 * @param WP_Post             $post   Post.
	 * @return void
	 */
	private function add_meta( array &$fields, WP_Post $post ): void {
		if ( $this->allowlist->is_empty() ) {
			return;
		}

		foreach ( $this->allowlist->all() as $key => $type ) {
			$raw = get_post_meta( $post->ID, $key, ! FieldType::is_multi( $type ) );

			if ( '' === $raw || array() === $raw || null === $raw ) {
				continue;
			}

			$this->set( $fields, DocumentFields::meta( $type, $key ), $type, $raw );
		}
	}

	/**
	 * Coerce a value and record it, or omit the field.
	 *
	 * Omission is deliberate. A value that cannot be represented loses one
	 * filter; a value written in the wrong shape gets the whole document
	 * rejected, and in a batch of a hundred that is ninety-nine innocent
	 * documents retried one at a time.
	 *
	 * @param array<string,mixed> $fields Fields being built.
	 * @param string              $field  Field name.
	 * @param string              $type   Type prefix.
	 * @param mixed               $value  Raw value.
	 * @return void
	 */
	private function set( array &$fields, string $field, string $type, $value ): void {
		$coerced = DocumentFields::coerce( $type, $value );

		if ( null !== $coerced ) {
			$fields[ $field ] = $coerced;
		}
	}
}
