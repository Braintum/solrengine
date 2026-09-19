<?php
/**
 * Index lifecycle contract.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Builds a replacement index alongside the live one and switches to it.
 *
 * The fourth backend contract, separate from the other three for the same
 * reason SchemaBackend is separate: not every backend has it, and the ones that
 * do have it differently. The plan's section 10.3 gives two strategies chosen by
 * detected mode, because Solr's own documentation rules out a single one — a
 * standard alias "can be used to atomically swap which backing collection is
 * live", and aliases are a SolrCloud feature, while the CoreAdmin guide warns
 * "Do not use SWAP with a SolrCloud node."
 *
 * So the contract reports which strategy it has rather than pretending to one:
 *
 * - STYLE_ALIAS: the live name is already an alias, so it can be repointed.
 *   Genuinely zero-downtime, and the switch is atomic on the server. Note the
 *   precondition: Solr refuses to create an alias whose name is taken by a real
 *   collection, so a site whose configured name is a collection cannot have this
 *   strategy until somebody creates an alias on the server.
 * - STYLE_POINTER: the backend cannot, and the caller switches SolrEngine's own
 *   configured index instead. One request may still be answered by the old index
 *   while the next is answered by the new one, which is a far smaller window
 *   than a rebuild in place and is reversible from WordPress.
 * - STYLE_NONE: no replacement strategy. Rebuilding means rebuilding in place.
 *
 * Implementations must never delete an index as part of switching. What to do
 * with the previous index is the administrator's decision, taken once they can
 * see that the new one works.
 *
 * @since 0.8.0 Unstable until 1.0.
 */
interface LifecycleBackend {

	/**
	 * The backend creates indexes and repoints an alias.
	 *
	 * @var string
	 */
	public const STYLE_ALIAS = 'alias';

	/**
	 * The caller repoints its own configuration at an index that already exists.
	 *
	 * @var string
	 */
	public const STYLE_POINTER = 'pointer';

	/**
	 * No replacement strategy is available.
	 *
	 * @var string
	 */
	public const STYLE_NONE = 'none';

	/**
	 * Which replacement strategy this backend supports.
	 *
	 * @since 0.8.0
	 *
	 * @return string One of the STYLE_ constants.
	 */
	public function rebuild_style(): string;

	/**
	 * Every index this backend can see.
	 *
	 * Used to derive a name for the next generation, and to let an
	 * administrator on a user-managed server choose an existing core to build
	 * into rather than typing its name from memory.
	 *
	 * @since 0.8.0
	 *
	 * @return Result Success carrying a list of names, or a failure.
	 */
	public function list_indexes(): Result;

	/**
	 * Whether this backend can create an index at all.
	 *
	 * Asked before offering to make one, so the caller can put a different
	 * question to the administrator — "which existing index should I build
	 * into?" — rather than offering a button that will fail.
	 *
	 * @since 0.8.0
	 *
	 * @return bool
	 */
	public function can_create_indexes(): bool;

	/**
	 * Create an index configured like the live one.
	 *
	 * Implementations must copy the live index's configuration rather than
	 * choosing one: a replacement built on a different configset is not a
	 * replacement, and the difference would only surface as changed relevance
	 * once it was already serving traffic.
	 *
	 * Independent of the strategy: a backend that repoints configuration may
	 * still be able to create the index it will point at, and a backend on a
	 * server that creates nothing reports a failure with the code "unsupported"
	 * so the caller can ask for the name of an index that already exists.
	 *
	 * @since 0.8.0
	 *
	 * @param string $name Name for the new index.
	 * @return Result
	 */
	public function create_index( string $name ): Result;

	/**
	 * What the live name currently resolves to.
	 *
	 * Returns the name itself when it is a real index rather than an alias,
	 * which is what makes the first rebuild on a site that has never used one
	 * behave the same as every rebuild after it.
	 *
	 * @since 0.8.0
	 *
	 * @param string $name Configured index or alias name.
	 * @return Result Success carrying the resolved index name, or a failure.
	 */
	public function resolve_index( string $name ): Result;

	/**
	 * Point the live name at an index.
	 *
	 * Only meaningful for STYLE_ALIAS. Under STYLE_POINTER the caller changes
	 * its own configuration instead, so nothing on the server moves.
	 *
	 * @since 0.8.0
	 *
	 * @param string $name   Live name, which becomes an alias.
	 * @param string $target Index the name should resolve to.
	 * @return Result
	 */
	public function point_index( string $name, string $target ): Result;

	/**
	 * Delete an index.
	 *
	 * Never called as part of a switch. Exposed so an administrator can reclaim
	 * the space taken by a previous generation once they have decided they no
	 * longer need to roll back to it.
	 *
	 * @since 0.8.0
	 *
	 * @param string $name Index to delete.
	 * @return Result
	 */
	public function drop_index( string $name ): Result;

	/**
	 * How many documents an index holds.
	 *
	 * The verification step. A replacement index with the wrong document count
	 * must never be switched to, and the only way to know is to ask the index
	 * that is not yet live.
	 *
	 * @since 0.8.0
	 *
	 * @param string $name Index to count.
	 * @return Result Success carrying an int, or a failure.
	 */
	public function count_documents( string $name ): Result;
}
