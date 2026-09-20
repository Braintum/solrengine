<?php
/**
 * Translates WordPress events into dirty-set marks.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Documents\MetaAllowlist;
use WP_Post;

/**
 * Listens for content changes and records what needs reindexing.
 *
 * Every listener does the same trivial thing: mark an object dirty. None of
 * them queues work, builds a document or makes a request. That separation is
 * what makes a bulk edit cheap, and it is why indexing can never slow down a
 * save.
 *
 * The hook choices matter more than they look:
 *
 * - wp_after_insert_post, not save_post. save_post fires before terms and meta
 *   are written, so a document built from it carries the previous taxonomy and
 *   meta. The 0.1.0 prototype used save_post and would have indexed stale
 *   values on every edit.
 * - before_delete_post, not deleted_post. The post and its meta must still
 *   exist so the reference can be built.
 * - transition_post_status decides between indexing and removal, which is how
 *   unpublishing removes a document. The prototype had no status handling at
 *   all, so unpublished content stayed searchable forever.
 *
 * @since 0.3.0
 */
final class HookListeners {

	/**
	 * Where dirty objects are collected.
	 *
	 * @var DirtySet
	 */
	private $dirty;

	/**
	 * Which meta keys are indexed.
	 *
	 * @var MetaAllowlist
	 */
	private $allowlist;

	/**
	 * Post types eligible for indexing.
	 *
	 * @var string[]
	 */
	private $post_types;

	/**
	 * Site identifier.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Constructor.
	 *
	 * @param DirtySet      $dirty      Dirty set to fill.
	 * @param MetaAllowlist $allowlist  Allowed meta keys.
	 * @param string[]      $post_types Eligible post types.
	 * @param int           $site_id    Site identifier.
	 */
	public function __construct( DirtySet $dirty, MetaAllowlist $allowlist, array $post_types, int $site_id ) {
		$this->dirty      = $dirty;
		$this->allowlist  = $allowlist;
		$this->post_types = $post_types;
		$this->site_id    = $site_id;
	}

	/**
	 * Attach every listener.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Priority 20 so plugins that write meta on the same hook have run.
		add_action( 'wp_after_insert_post', array( $this, 'on_insert' ), 20, 4 );
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 2 );
		add_action( 'wp_trash_post', array( $this, 'on_trash' ) );
		add_action( 'untrashed_post', array( $this, 'on_untrash' ) );

		add_action( 'added_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'on_meta_change' ), 10, 3 );

		add_action( 'set_object_terms', array( $this, 'on_terms_changed' ), 10, 6 );
	}

	/**
	 * A post was created or updated.
	 *
	 * @since 0.3.0
	 *
	 * @param int          $post_id Post identifier.
	 * @param WP_Post      $post    Post object.
	 * @param bool         $update  Whether this was an update.
	 * @param WP_Post|null $before  Post before the change.
	 * @return void
	 */
	public function on_insert( int $post_id, $post, $update = false, $before = null ): void {
		unset( $update, $before );

		if ( ! $post instanceof WP_Post || ! $this->is_eligible( $post->post_type ) ) {
			return;
		}

		$this->dirty->mark_for_index( $this->ref( $post_id ), 'post_saved' );
	}

	/**
	 * A post changed status.
	 *
	 * @since 0.3.0
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public function on_status_change( string $new_status, string $old_status, $post ): void {
		if ( ! $post instanceof WP_Post || ! $this->is_eligible( $post->post_type ) ) {
			return;
		}

		if ( $new_status === $old_status ) {
			return;
		}

		$status = get_post_status_object( $new_status );

		if ( null !== $status && $status->public ) {
			// Covers scheduled posts reaching their publish time, which is the
			// only event that makes them indexable.
			$this->dirty->mark_for_index( $this->ref( $post->ID ), 'became_public' );

			return;
		}

		$this->dirty->mark_for_delete( $this->ref( $post->ID ), 'no_longer_public' );
	}

	/**
	 * A post is about to be deleted permanently.
	 *
	 * @since 0.3.0
	 *
	 * @param int          $post_id Post identifier.
	 * @param WP_Post|null $post    Post object.
	 * @return void
	 */
	public function on_delete( int $post_id, $post = null ): void {
		if ( $post instanceof WP_Post && ! $this->is_eligible( $post->post_type ) ) {
			return;
		}

		// Deleted unconditionally when the type cannot be determined. Removing
		// a document that was never there is a no-op; leaving one behind is a
		// correctness and privacy failure.
		$this->dirty->mark_for_delete( $this->ref( $post_id ), 'deleted' );
	}

	/**
	 * A post was trashed.
	 *
	 * @since 0.3.0
	 *
	 * @param int $post_id Post identifier.
	 * @return void
	 */
	public function on_trash( $post_id ): void {
		$this->dirty->mark_for_delete( $this->ref( (int) $post_id ), 'trashed' );
	}

	/**
	 * A post was restored from the trash.
	 *
	 * @since 0.3.0
	 *
	 * @param int $post_id Post identifier.
	 * @return void
	 */
	public function on_untrash( $post_id ): void {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || ! $this->is_eligible( $post->post_type ) ) {
			return;
		}

		// Marked for indexing rather than assumed public. The builder decides,
		// because a post restored to draft must stay out of the index.
		$this->dirty->mark_for_index( $this->ref( (int) $post_id ), 'untrashed' );
	}

	/**
	 * A meta value changed.
	 *
	 * @since 0.3.0
	 *
	 * @param int|string $meta_id  Meta row identifier.
	 * @param int        $post_id  Post identifier.
	 * @param string     $meta_key Meta key.
	 * @return void
	 */
	public function on_meta_change( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );

		$key = (string) $meta_key;

		// Only keys that actually reach the index, plus the featured image,
		// which is stored as meta but is mapped explicitly. Without this test
		// every transient counter and edit lock would trigger a reindex.
		if ( '_thumbnail_id' !== $key && ! $this->allowlist->allows( $key ) ) {
			return;
		}

		$post = get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post || ! $this->is_eligible( $post->post_type ) ) {
			return;
		}

		$this->dirty->mark_for_index( $this->ref( (int) $post_id ), 'meta_changed' );
	}

	/**
	 * A post's terms changed.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $object_id  Object identifier.
	 * @param array  $terms      Terms set.
	 * @param array  $tt_ids     Term taxonomy identifiers.
	 * @param string $taxonomy   Taxonomy name.
	 * @param bool   $append     Whether terms were appended.
	 * @param array  $old_tt_ids Previous term taxonomy identifiers.
	 * @return void
	 */
	public function on_terms_changed( $object_id, $terms, $tt_ids, $taxonomy, $append = false, $old_tt_ids = array() ): void {
		unset( $terms, $append );

		// set_object_terms fires even when nothing actually changed, which
		// happens on every save for every attached taxonomy. Comparing the
		// before and after avoids reindexing on a no-op.
		if ( $this->same_terms( (array) $tt_ids, (array) $old_tt_ids ) ) {
			return;
		}

		$post = get_post( (int) $object_id );

		if ( ! $post instanceof WP_Post || ! $this->is_eligible( $post->post_type ) ) {
			return;
		}

		$taxonomy_object = get_taxonomy( (string) $taxonomy );

		if ( false === $taxonomy_object || ! $taxonomy_object->public ) {
			return;
		}

		$this->dirty->mark_for_index( $this->ref( (int) $object_id ), 'terms_changed' );
	}

	/**
	 * Whether two term identifier lists hold the same members.
	 *
	 * @param array<int,mixed> $current  Current identifiers.
	 * @param array<int,mixed> $previous Previous identifiers.
	 * @return bool
	 */
	private function same_terms( array $current, array $previous ): bool {
		$a = array_map( 'intval', $current );
		$b = array_map( 'intval', $previous );

		sort( $a );
		sort( $b );

		return $a === $b;
	}

	/**
	 * Whether a post type is configured for indexing.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	private function is_eligible( string $post_type ): bool {
		// The list is trusted as given, and deliberately not filtered again here.
		// It arrives from IndexedTypes::all(), which is the one place the
		// solrengine_indexable_post_types filter is applied — the single source
		// Phase 6 reduced this question to after two copies of it had let content
		// reach the index on save and vanish on the next full sync.
		//
		// Re-applying the filter here made that one place two again. It was
		// harmless for an idempotent filter and wrong for any other: a filter
		// that appends a post type appended it twice, and one that counts or
		// logs ran on every save of every post.
		return in_array( $post_type, $this->post_types, true );
	}

	/**
	 * Build a reference for a post.
	 *
	 * @param int $post_id Post identifier.
	 * @return DocumentRef
	 */
	private function ref( int $post_id ): DocumentRef {
		return new DocumentRef( $this->site_id, 'post', $post_id );
	}
}
