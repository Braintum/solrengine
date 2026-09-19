<?php
/**
 * Builds a SchemaState from a Solr schema response.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Schema;

use SolrEngine\Contracts\SchemaState;

/**
 * Turns Solr's /schema response into the shape the planner understands.
 *
 * Pure, and separate from the backend that fetches it, so the parsing can be
 * exercised against a real server's output without a transport, and so the
 * backend test and the production path cannot diverge in how they read a
 * schema.
 *
 * @since 0.2.0
 */
final class SchemaStateFactory {

	/**
	 * Build a state from a decoded /schema response.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @param bool                $mutable  Whether the Schema API accepts writes.
	 * @return SchemaState
	 */
	public function from_response( array $response, bool $mutable ): SchemaState {
		$schema = is_array( $response['schema'] ?? null ) ? $response['schema'] : array();

		$copy_fields = array();

		foreach ( (array) ( $schema['copyFields'] ?? array() ) as $rule ) {
			if ( isset( $rule['source'], $rule['dest'] ) ) {
				$copy_fields[] = $rule['source'] . '=>' . $rule['dest'];
			}
		}

		$types = array();

		foreach ( (array) ( $schema['fieldTypes'] ?? array() ) as $type ) {
			if ( isset( $type['name'] ) ) {
				$types[] = (string) $type['name'];
			}
		}

		return new SchemaState(
			$this->key_by_name( (array) ( $schema['fields'] ?? array() ) ),
			$this->key_by_name( (array) ( $schema['dynamicFields'] ?? array() ) ),
			$copy_fields,
			$types,
			$mutable,
			(string) ( $schema['uniqueKey'] ?? '' )
		);
	}

	/**
	 * Index a list of schema entries by their name.
	 *
	 * @param array<int,mixed> $entries Entries from Solr.
	 * @return array<string,array<string,mixed>>
	 */
	private function key_by_name( array $entries ): array {
		$keyed = array();

		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) && isset( $entry['name'] ) ) {
				$keyed[ (string) $entry['name'] ] = $entry;
			}
		}

		return $keyed;
	}
}
