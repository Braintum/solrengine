<?php
/**
 * Result of a diagnostic run.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Diagnostics;

/**
 * A list of independent checks and how each one went.
 *
 * Rendered by the admin screen, the REST endpoint and WP-CLI alike, so all
 * three report exactly the same facts in the same order. A diagnostic that
 * said different things depending on where you ran it would be worse than
 * none.
 *
 * @since 0.1.0
 */
final class DiagnosticReport {

	/**
	 * Checks in the order they ran.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $checks = array();

	/**
	 * Record a check that passed.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $id      Stable identifier.
	 * @param string              $label   Human label.
	 * @param string              $detail  What was found.
	 * @param array<string,mixed> $context Extra structured detail.
	 * @return void
	 */
	public function pass( string $id, string $label, string $detail, array $context = array() ): void {
		$this->add( 'pass', $id, $label, $detail, '', $context );
	}

	/**
	 * Record a check that worked but deserves attention.
	 *
	 * A warning never blocks. An unencrypted connection to localhost and a
	 * schema that needs three additive changes are both real things to tell an
	 * administrator, and neither is a failure.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $id      Stable identifier.
	 * @param string              $label   Human label.
	 * @param string              $detail  What was found.
	 * @param array<string,mixed> $context Extra structured detail.
	 * @return void
	 */
	public function warn( string $id, string $label, string $detail, array $context = array() ): void {
		$this->add( 'warn', $id, $label, $detail, '', $context );
	}

	/**
	 * Record a check that failed.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $id      Stable identifier.
	 * @param string              $label   Human label.
	 * @param string              $detail  What went wrong, in plain language.
	 * @param string              $code    Stable machine code for branching.
	 * @param array<string,mixed> $context Extra structured detail.
	 * @return void
	 */
	public function fail( string $id, string $label, string $detail, string $code = '', array $context = array() ): void {
		$this->add( 'fail', $id, $label, $detail, $code, $context );
	}

	/**
	 * Every check.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function checks(): array {
		return $this->checks;
	}

	/**
	 * Whether every check passed or warned.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		foreach ( $this->checks as $check ) {
			if ( 'fail' === $check['status'] ) {
				return false;
			}
		}

		return array() !== $this->checks;
	}

	/**
	 * Whether anything warned.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_warnings(): bool {
		foreach ( $this->checks as $check ) {
			if ( 'warn' === $check['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The first failure, or null when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,mixed>|null
	 */
	public function first_failure(): ?array {
		foreach ( $this->checks as $check ) {
			if ( 'fail' === $check['status'] ) {
				return $check;
			}
		}

		return null;
	}

	/**
	 * The report as a plain array, for REST and WP-CLI.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'usable'   => $this->is_usable(),
			'warnings' => $this->has_warnings(),
			'checks'   => $this->checks,
		);
	}

	/**
	 * Append a check.
	 *
	 * @param string              $status  One of pass, warn, fail.
	 * @param string              $id      Stable identifier.
	 * @param string              $label   Human label.
	 * @param string              $detail  Detail.
	 * @param string              $code    Machine code.
	 * @param array<string,mixed> $context Extra detail.
	 * @return void
	 */
	private function add( string $status, string $id, string $label, string $detail, string $code, array $context ): void {
		$this->checks[] = array(
			'status'  => $status,
			'id'      => $id,
			'label'   => $label,
			'detail'  => $detail,
			'code'    => $code,
			'context' => $context,
		);
	}
}
