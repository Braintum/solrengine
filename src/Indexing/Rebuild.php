<?php
/**
 * Zero-downtime index rebuild.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Backend\Solr\CollectionName;
use SolrEngine\Contracts\LifecycleBackend;
use SolrEngine\Contracts\Logger;
use SolrEngine\Contracts\Result;
use SolrEngine\Support\Config;

/**
 * Builds a replacement index beside the live one, then switches to it.
 *
 * The answer to the one thing a full reindex cannot do well. Reindexing in place
 * means every search during the run is answered by an index that is partly
 * rebuilt, so results are wrong for as long as it takes — hours, on a large
 * catalogue — and there is no way back if the run turns out to be wrong. The
 * plan's section 10.3 calls for building alongside instead, and for two
 * strategies rather than one, because Solr aliases are a SolrCloud feature and
 * its own documentation warns against CoreAdmin SWAP in cloud mode.
 *
 * Five states, and the switch is its own step:
 *
 *   plan  →  building  →  ready  →  switched
 *                ↓          ↓
 *              failed     failed
 *
 * Nothing moves traffic automatically. A rebuild that finished is not the same
 * claim as a rebuild that is correct, and the verification between them compares
 * the replacement's document count against what WordPress says it should be. A
 * count that does not match stops the switch, which is the entire point of
 * building alongside.
 *
 * Nothing is deleted, ever, as part of a switch. The previous index stays until
 * an administrator says otherwise, because it is the only way back.
 *
 * @since 0.8.0
 */
final class Rebuild {

	/**
	 * Option holding rebuild state.
	 *
	 * @var string
	 */
	public const OPTION = 'solrengine_rebuild';

	/**
	 * Channel the rebuild's synchronisation runs on.
	 *
	 * @var string
	 */
	public const CHANNEL = 'rebuild';

	/**
	 * Proportion of documents that may be missing and still verify.
	 *
	 * The same tolerance the reconciler uses, for the same reason: two counts
	 * taken from two systems moments apart differ on any site that is being
	 * edited, and a threshold of zero would fail every rebuild on a busy site.
	 *
	 * @var float
	 */
	private const TOLERANCE = 0.02;

	/**
	 * Backend registry.
	 *
	 * @var BackendRegistry
	 */
	private $backends;

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Drift reporter, which owns the expected document count.
	 *
	 * @var Reconciler
	 */
	private $reconciler;

	/**
	 * Reindex notice, cleared when a rebuild goes live.
	 *
	 * @var ReindexNotice
	 */
	private $notice;

	/**
	 * Builds a synchronisation that writes to a named index.
	 *
	 * A closure rather than a constructed object, because a rebuild needs a full
	 * sync pointed somewhere that is not known until it starts, and Bootstrap is
	 * the only place that knows how an indexer, a queue and a document builder
	 * are assembled. Passing the closure keeps that knowledge there.
	 *
	 * @var callable(string):?FullSync
	 */
	private $sync_factory;

	/**
	 * Constructor.
	 *
	 * @param BackendRegistry            $backends     Backend registry.
	 * @param Config                     $config       Configuration.
	 * @param Logger                     $logger       Logger.
	 * @param Reconciler                 $reconciler   Drift reporter.
	 * @param ReindexNotice              $notice       Reindex notice.
	 * @param callable(string):?FullSync $sync_factory Builds a sync for a target.
	 */
	public function __construct(
		BackendRegistry $backends,
		Config $config,
		Logger $logger,
		Reconciler $reconciler,
		ReindexNotice $notice,
		callable $sync_factory
	) {
		$this->backends     = $backends;
		$this->config       = $config;
		$this->logger       = $logger;
		$this->reconciler   = $reconciler;
		$this->notice       = $notice;
		$this->sync_factory = $sync_factory;
	}

	/**
	 * What a rebuild would do, without doing any of it.
	 *
	 * @since 0.8.0
	 *
	 * @return Result Success carrying the plan, or a failure explaining why a
	 *                rebuild is not available on this server.
	 */
	public function plan(): Result {
		$lifecycle = $this->backends->lifecycle();

		if ( null === $lifecycle ) {
			return Result::failure(
				'unsupported',
				__( 'This backend cannot build a replacement index. Use a full reindex instead.', 'solrengine' )
			);
		}

		$style = $lifecycle->rebuild_style();

		if ( LifecycleBackend::STYLE_NONE === $style ) {
			return Result::failure(
				'unsupported',
				__( 'SolrEngine could not work out how to build a replacement index on this server. Use a full reindex instead.', 'solrengine' )
			);
		}

		$live     = $this->config->get_string( 'connection.collection' );
		$existing = $lifecycle->list_indexes();
		$names    = $existing->is_ok() ? array_map( 'strval', (array) $existing->value( array() ) ) : array();

		return Result::success(
			array(
				'style'      => $style,
				'live'       => $live,
				'resolved'   => (string) $lifecycle->resolve_index( $live )->value( $live ),
				'suggested'  => CollectionName::next( $live, $names ),
				'existing'   => $names,
				// Whether that list is the server's answer or the absence of
				// one. Credentials that can read a collection but not list the
				// cluster are ordinary, and a failed listing must not be read
				// as "there are no indexes".
				'listed'     => $existing->is_ok(),
				// Whether SolrEngine can make the index itself, or has to be
				// given the name of one that exists. The admin screen asks a
				// different question in each case.
				'can_create' => $lifecycle->can_create_indexes(),
				'pinned'     => $this->config->is_locked( 'connection.collection' ),
			)
		);
	}

	/**
	 * Begin a rebuild.
	 *
	 * @since 0.8.0
	 *
	 * @param string $target Index to build into, or empty to create one.
	 * @return Result Success carrying the rebuild state, or a failure.
	 */
	public function start( string $target = '' ): Result {
		$state = $this->state();

		if ( null !== $state && in_array( $state['state'], array( 'building', 'ready' ), true ) ) {
			return Result::failure(
				'already_running',
				__( 'A rebuild is already under way. Finish or discard it first.', 'solrengine' )
			);
		}

		$planned = $this->plan();

		if ( $planned->is_failure() ) {
			return $planned;
		}

		$plan      = (array) $planned->value( array() );
		$lifecycle = $this->backends->lifecycle();

		if ( null === $lifecycle ) {
			return Result::failure( 'unsupported', __( 'This backend cannot build a replacement index.', 'solrengine' ) );
		}

		if ( LifecycleBackend::STYLE_POINTER === $plan['style'] && (bool) $plan['pinned'] ) {
			// Refused before anything is built rather than after. On this server
			// the switch is a configuration change, and a collection pinned in
			// wp-config.php cannot be changed from here — so the rebuild would
			// run for an hour and then have nowhere to go.
			return Result::failure(
				'pinned',
				__( 'Your collection name is set in wp-config.php, and on this server switching over means changing it. Remove SOLRENGINE_SOLR_COLLECTION, or switch by editing that file once the new index is built.', 'solrengine' )
			);
		}

		if ( '' === $target ) {
			$target = (string) $plan['suggested'];

			if ( ! (bool) $plan['listed'] ) {
				// The suggested name is derived from what is already on the
				// server. Without that list it is a guess, and the way it can
				// be wrong is by naming an abandoned rebuild's index, which
				// would index into something somebody may still be reading.
				return Result::failure(
					'cannot_list',
					__( 'SolrEngine could not list the indexes on your Solr, so it cannot choose a safe name for a new one. Name one yourself, or give SolrEngine credentials that can read the cluster.', 'solrengine' )
				);
			}
		} elseif ( ! CollectionName::is_valid( $target ) ) {
			return Result::failure( 'invalid_target', __( 'That is not a usable index name.', 'solrengine' ) );
		} elseif ( $target === (string) $plan['resolved'] || $target === (string) $plan['live'] ) {
			return Result::failure(
				'target_is_live',
				__( 'That is the index already serving searches. A rebuild has to write somewhere else — that is what makes it safe.', 'solrengine' )
			);
		}

		$created = false;

		// Checked before a single document is written. A target that does not
		// exist fails on every write instead of once, and the error a caller
		// would see is Solr's "no such collection" repeated a hundred thousand
		// times rather than a sentence naming the thing that is missing.
		if ( (bool) $plan['listed'] && ! in_array( $target, (array) $plan['existing'], true ) ) {
			if ( ! (bool) $plan['can_create'] ) {
				return Result::failure(
					'no_such_index',
					sprintf(
						/* translators: %s: index name. */
						__( 'There is no core called "%s" on your Solr, and SolrEngine cannot create one on a server that is not running SolrCloud. Create it there first.', 'solrengine' ),
						$target
					)
				);
			}

			$made    = $lifecycle->create_index( $target );
			$created = $made->is_ok();

			if ( ! $created ) {
				return Result::failure(
					$made->code(),
					sprintf(
						/* translators: 1: index name, 2: reason from the server. */
						__( 'SolrEngine could not create "%1$s". %2$s', 'solrengine' ),
						$target,
						$made->message()
					)
				);
			}
		}

		$sync = ( $this->sync_factory )( $target );

		if ( ! $sync instanceof FullSync ) {
			return Result::failure(
				'no_backend',
				__( 'SolrEngine could not reach that index. Check the name and the connection.', 'solrengine' )
			);
		}

		// Any leftover progress from an abandoned rebuild, discarded before a
		// new one starts. Resuming somebody else's half-finished run into a
		// freshly created index would skip everything before its cursor.
		$sync->forget();

		$started = $sync->start();

		if ( $started->is_failure() ) {
			return $started;
		}

		$this->save(
			array(
				'state'      => 'building',
				'style'      => (string) $plan['style'],
				'live'       => (string) $plan['live'],
				'previous'   => (string) $plan['resolved'],
				'target'     => $target,
				'created'    => $created,
				'started_at' => time(),
				'updated_at' => time(),
				'expected'   => 0,
				'actual'     => 0,
				'message'    => '',
			)
		);

		$this->logger->info(
			'Rebuild started',
			array(
				'target' => $target,
				'style'  => (string) $plan['style'],
			)
		);

		return Result::success( $this->state() );
	}

	/**
	 * Process one batch of the replacement index's build.
	 *
	 * The queue calls this, not the synchronisation directly, because the
	 * synchronisation for a rebuild only exists once the target is known, and
	 * the target is in the rebuild's state rather than in the job.
	 *
	 * @since 0.8.0
	 *
	 * @return Result
	 */
	public function run_batch(): Result {
		$state = $this->state();

		if ( null === $state || 'building' !== $state['state'] ) {
			return Result::failure( 'not_building', 'No rebuild is being built.' );
		}

		$sync = ( $this->sync_factory )( (string) $state['target'] );

		if ( ! $sync instanceof FullSync ) {
			return Result::failure( 'no_backend', 'The replacement index cannot be reached.' );
		}

		return $sync->run_batch();
	}

	/**
	 * Check whether the replacement index is complete and correct.
	 *
	 * Safe to call repeatedly, and expected to be: a count taken the moment a
	 * run finishes can be short, because writes become visible on Solr's commit
	 * schedule rather than on ours. A short count is reported as "not yet", not
	 * as a failure.
	 *
	 * @since 0.8.0
	 *
	 * @return Result Success carrying the rebuild state, or a failure.
	 */
	public function verify(): Result {
		$state = $this->state();

		if ( null === $state ) {
			return Result::failure( 'no_rebuild', __( 'No rebuild is in progress.', 'solrengine' ) );
		}

		$sync = ( $this->sync_factory )( (string) $state['target'] );

		if ( ! $sync instanceof FullSync ) {
			return Result::failure( 'no_backend', __( 'SolrEngine cannot reach the new index.', 'solrengine' ) );
		}

		$run = $sync->state();

		if ( null === $run || 'complete' !== $run['state'] ) {
			$state['state']   = 'building';
			$state['message'] = null === $run
				? __( 'Indexing has not started.', 'solrengine' )
				: sprintf(
					/* translators: 1: run state, 2: processed count. */
					__( 'Indexing is %1$s — %2$s objects so far.', 'solrengine' ),
					(string) $run['state'],
					number_format_i18n( (int) $run['processed'] )
				);

			$this->save( $state );

			return Result::success( $state );
		}

		$lifecycle = $this->backends->lifecycle();

		if ( null === $lifecycle ) {
			return Result::failure( 'unsupported', __( 'This backend cannot verify a replacement index.', 'solrengine' ) );
		}

		$counted = $lifecycle->count_documents( (string) $state['target'] );

		if ( $counted->is_failure() ) {
			return $counted;
		}

		$expected = $this->reconciler->expected_count();
		$actual   = (int) $counted->value( 0 );

		$state['expected']   = $expected;
		$state['actual']     = $actual;
		$state['updated_at'] = time();

		if ( $this->is_complete_enough( $expected, $actual ) ) {
			$state['state']   = 'ready';
			$state['message'] = '';
		} else {
			$state['state']   = 'building';
			$state['message'] = sprintf(
				/* translators: 1: documents found, 2: documents expected. */
				__( 'The new index holds %1$s documents and should hold about %2$s. Solr makes writes visible about a minute after they are sent, so check again shortly; if the number stays low, look at the failures in the run history.', 'solrengine' ),
				number_format_i18n( $actual ),
				number_format_i18n( $expected )
			);
		}

		$this->save( $state );

		return Result::success( $state );
	}

	/**
	 * Switch searches over to the replacement index.
	 *
	 * @since 0.8.0
	 *
	 * @return Result Success carrying the rebuild state, or a failure.
	 */
	public function switch_over(): Result {
		$state = $this->state();

		if ( null === $state ) {
			return Result::failure( 'no_rebuild', __( 'No rebuild is in progress.', 'solrengine' ) );
		}

		if ( 'ready' !== $state['state'] ) {
			// Verification is not a formality that can be skipped with a flag.
			// Switching an unverified index is the one failure mode this whole
			// mechanism exists to prevent.
			return Result::failure(
				'not_verified',
				__( 'The new index has not been verified yet. Check it first — switching an incomplete index is exactly what building alongside is meant to avoid.', 'solrengine' )
			);
		}

		$target = (string) $state['target'];

		if ( LifecycleBackend::STYLE_ALIAS === $state['style'] ) {
			$lifecycle = $this->backends->lifecycle();

			if ( null === $lifecycle ) {
				return Result::failure( 'unsupported', __( 'This backend cannot switch indexes.', 'solrengine' ) );
			}

			$pointed = $lifecycle->point_index( (string) $state['live'], $target );

			if ( $pointed->is_failure() ) {
				return $pointed;
			}
		} elseif ( ! $this->config->set( 'connection.collection', $target ) ) {
			// The pointer strategy: SolrEngine changes which collection it is
			// configured with. Reversible from WordPress, and it avoids a Solr
			// operation whose safety varies by deployment mode. It can only fail
			// one way, which is the collection being pinned in wp-config.php.
			return Result::failure(
				'pinned',
				__( 'The collection name could not be changed, because it is set in wp-config.php.', 'solrengine' )
			);
		}

		$state['state']      = 'switched';
		$state['message']    = '';
		$state['updated_at'] = time();

		$this->save( $state );

		// The new index was built from the current configuration and the current
		// schema, so whatever was outstanding no longer is.
		$this->notice->clear();

		$this->logger->info(
			'Rebuild switched over',
			array(
				'target'   => $target,
				'previous' => (string) $state['previous'],
			)
		);

		/**
		 * Fires when searches have been switched to a rebuilt index.
		 *
		 * @since 0.8.0
		 *
		 * @param string              $target   Index now serving searches.
		 * @param array<string,mixed> $state    Final rebuild state.
		 */
		do_action( 'solrengine_rebuild_switched', $target, $state );

		return Result::success( $state );
	}

	/**
	 * Abandon a rebuild.
	 *
	 * Deletes the replacement index only when SolrEngine created it and it is
	 * not serving searches. An index an administrator named is theirs, and one
	 * this plugin made is rubbish it should clear up — but neither judgement is
	 * worth making about an index somebody might be looking at.
	 *
	 * @since 0.8.0
	 *
	 * @return Result
	 */
	public function discard(): Result {
		$state = $this->state();

		if ( null === $state ) {
			return Result::failure( 'no_rebuild', __( 'No rebuild is in progress.', 'solrengine' ) );
		}

		if ( 'switched' === $state['state'] ) {
			return Result::failure(
				'already_switched',
				__( 'That rebuild is already serving searches. It cannot be discarded.', 'solrengine' )
			);
		}

		$sync = ( $this->sync_factory )( (string) $state['target'] );

		if ( $sync instanceof FullSync ) {
			$sync->forget();
		}

		$dropped = null;

		if ( ! empty( $state['created'] ) ) {
			$lifecycle = $this->backends->lifecycle();

			if ( null !== $lifecycle ) {
				$dropped = $lifecycle->drop_index( (string) $state['target'] );
			}
		}

		delete_option( self::OPTION );

		if ( null !== $dropped && $dropped->is_failure() ) {
			// The rebuild is discarded either way. The leftover index is worth
			// naming, because otherwise it sits on the server consuming disk
			// with nothing to explain what it is.
			return Result::failure(
				'orphaned',
				sprintf(
					/* translators: 1: index name, 2: reason from the server. */
					__( 'The rebuild was discarded, but "%1$s" could not be deleted and is still on your server. %2$s', 'solrengine' ),
					(string) $state['target'],
					$dropped->message()
				)
			);
		}

		return Result::success();
	}

	/**
	 * Delete the index that was live before the last switch.
	 *
	 * Explicit, and never automatic. The plan's section 10.3 suggests deleting
	 * the previous collection after a grace period; this does not, because
	 * "after a grace period" means a plugin deleting a customer's data on a
	 * timer, and the only reason the previous index exists is to be the way back
	 * when something turns out to be wrong two days later.
	 *
	 * @since 0.8.0
	 *
	 * @return Result
	 */
	public function drop_previous(): Result {
		$state = $this->state();

		if ( null === $state || 'switched' !== $state['state'] ) {
			return Result::failure( 'nothing_to_drop', __( 'There is no previous index to remove.', 'solrengine' ) );
		}

		$previous = (string) $state['previous'];

		if ( '' === $previous || $previous === (string) $state['target'] ) {
			return Result::failure( 'nothing_to_drop', __( 'There is no previous index to remove.', 'solrengine' ) );
		}

		$lifecycle = $this->backends->lifecycle();

		if ( null === $lifecycle ) {
			return Result::failure( 'unsupported', __( 'This backend cannot delete indexes.', 'solrengine' ) );
		}

		$dropped = $lifecycle->drop_index( $previous );

		if ( $dropped->is_failure() ) {
			return $dropped;
		}

		$state['previous']   = '';
		$state['updated_at'] = time();

		$this->save( $state );

		return Result::success();
	}

	/**
	 * Forget a completed rebuild.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function dismiss(): void {
		$state = $this->state();

		if ( null !== $state && 'switched' === $state['state'] ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * The current rebuild, or null when there is none.
	 *
	 * @since 0.8.0
	 *
	 * @return array<string,mixed>|null
	 */
	public function state(): ?array {
		$state = get_option( self::OPTION, null );

		return is_array( $state ) && isset( $state['state'] ) ? $state : null;
	}

	/**
	 * Whether a document count is close enough to the expected one.
	 *
	 * @param int $expected Documents WordPress expects.
	 * @param int $actual   Documents the new index holds.
	 * @return bool
	 */
	private function is_complete_enough( int $expected, int $actual ): bool {
		if ( $expected <= 0 ) {
			// Nothing should be indexed, so an empty replacement is correct. A
			// site in this state has no content or no configured post types, and
			// refusing to switch would be refusing to agree with itself.
			return true;
		}

		return $actual >= (int) floor( $expected * ( 1 - self::TOLERANCE ) );
	}

	/**
	 * Persist rebuild state.
	 *
	 * @param array<string,mixed> $state State to store.
	 * @return void
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
