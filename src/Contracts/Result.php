<?php
/**
 * Operation outcome.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * The outcome of an operation that can fail.
 *
 * SolrEngine does not throw across the integration boundary. A backend that is
 * unreachable, misconfigured or slow is an expected condition, not an
 * exceptional one, and WordPress must keep rendering either way (plan
 * Principle 1). Every backend call therefore returns one of these.
 *
 * Immutable. Construct through the static factories rather than directly.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class Result {

	/**
	 * Whether the operation succeeded.
	 *
	 * @var bool
	 */
	private $ok;

	/**
	 * Stable machine-readable failure code, empty on success.
	 *
	 * @var string
	 */
	private $code;

	/**
	 * Human-readable message, safe to log but never to render unescaped.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Arbitrary payload carried by a successful result.
	 *
	 * @var mixed
	 */
	private $value;

	/**
	 * Additional structured context for logging and diagnostics.
	 *
	 * @var array<string,mixed>
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param bool                $ok      Success flag.
	 * @param string              $code    Failure code.
	 * @param string              $message Failure message.
	 * @param mixed               $value   Success payload.
	 * @param array<string,mixed> $context Diagnostic context.
	 */
	private function __construct( bool $ok, string $code, string $message, $value, array $context ) {
		$this->ok      = $ok;
		$this->code    = $code;
		$this->message = $message;
		$this->value   = $value;
		$this->context = $context;
	}

	/**
	 * A successful outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Optional payload.
	 * @return self
	 */
	public static function success( $value = null ): self {
		return new self( true, '', '', $value, array() );
	}

	/**
	 * A failed outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $code    Stable machine code, for example
	 *                                     "backend_unavailable". Never shown to
	 *                                     visitors; used for branching and tests.
	 * @param string              $message Human-readable explanation.
	 * @param array<string,mixed> $context Diagnostic context. Must never contain
	 *                                     credentials; see Credentials::redact().
	 * @return self
	 */
	public static function failure( string $code, string $message = '', array $context = array() ): self {
		return new self( false, $code, $message, null, $context );
	}

	/**
	 * Whether the operation succeeded.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_ok(): bool {
		return $this->ok;
	}

	/**
	 * Whether the operation failed.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_failure(): bool {
		return ! $this->ok;
	}

	/**
	 * The failure code, or an empty string on success.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * The failure message, or an empty string on success.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * Diagnostic context.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,mixed>
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * The success payload.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $fallback Returned when the result is a failure.
	 * @return mixed
	 */
	public function value( $fallback = null ) {
		return $this->ok ? $this->value : $fallback;
	}
}
