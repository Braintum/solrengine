<?php
/**
 * Backend credential storage.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Contracts\Result;

/**
 * Stores and retrieves the credentials used to reach the search backend.
 *
 * Ranked by preference, per ADR-009:
 *
 * 1. PHP constants in wp-config.php. Not in the database, not in a dump, not
 *    changeable through a compromised admin session. Detected automatically.
 * 2. An encrypted, non-autoloaded option. Defends against database disclosure
 *    only; see Encryption for what that is and is not worth.
 * 3. Never plaintext, never returned to the browser, never in a log, never in
 *    a support export.
 *
 * The secret leaves this class only through password(), which callers use to
 * build an outbound request and nothing else. Everything the admin screens
 * need is answered by has_password(), source() and describe().
 *
 * @since 0.1.0
 */
final class Credentials {

	/**
	 * Option holding encrypted credentials. Never autoloaded.
	 *
	 * @var string
	 */
	public const OPTION = 'solrengine_credentials';

	/**
	 * Network option holding credentials shared by every site.
	 *
	 * @var string
	 */
	public const NETWORK_OPTION = 'solrengine_network_credentials';

	/**
	 * Constant that may hold the username.
	 *
	 * @var string
	 */
	private const CONST_USER = 'SOLRENGINE_SOLR_USER';

	/**
	 * Constant that may hold the password.
	 *
	 * @var string
	 */
	private const CONST_PASSWORD = 'SOLRENGINE_SOLR_PASSWORD';

	/**
	 * Encryption service.
	 *
	 * @var Encryption
	 */
	private $encryption;

	/**
	 * Constructor.
	 *
	 * @param Encryption $encryption Encryption service.
	 */
	public function __construct( Encryption $encryption ) {
		$this->encryption = $encryption;
	}

	/**
	 * Where the credentials currently come from.
	 *
	 * @since 0.1.0
	 *
	 * @return string One of "constant", "option" or "none".
	 */
	public function source(): string {
		if ( $this->constant_password() !== null ) {
			return 'constant';
		}

		$stored = $this->stored( self::OPTION );

		if ( ! empty( $stored['password'] ) ) {
			return 'option';
		}

		if ( ! empty( $this->stored( self::NETWORK_OPTION )['password'] ) ) {
			return 'network';
		}

		return 'none';
	}

	/**
	 * Whether a password is configured at all.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_password(): bool {
		return 'none' !== $this->source();
	}

	/**
	 * The configured username, or an empty string.
	 *
	 * Usernames are not secret and may be displayed.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function username(): string {
		if ( defined( self::CONST_USER ) ) {
			$value = constant( self::CONST_USER );

			if ( is_string( $value ) ) {
				return $value;
			}
		}

		$stored = $this->stored( self::OPTION );

		if ( isset( $stored['username'] ) && '' !== (string) $stored['username'] ) {
			return (string) $stored['username'];
		}

		$networked = $this->stored( self::NETWORK_OPTION );

		return isset( $networked['username'] ) ? (string) $networked['username'] : '';
	}

	/**
	 * The configured password.
	 *
	 * Call this only when building an outbound request. Never pass the value
	 * into a template, a log line, a REST response or an exception message.
	 *
	 * @since 0.1.0
	 *
	 * @return Result Success carrying the password string, or a failure. The
	 *                failure code "salts_rotated" means a password is stored but
	 *                can no longer be read, which needs a different message to
	 *                the user than "no password configured".
	 */
	public function password(): Result {
		$from_constant = $this->constant_password();

		if ( null !== $from_constant ) {
			return Result::success( $from_constant );
		}

		$stored = $this->stored( self::OPTION );

		if ( ! empty( $stored['password'] ) ) {
			return $this->encryption->decrypt( (string) $stored['password'] );
		}

		// Falling back to the network's copy, not merging with it. A site that
		// has entered its own credentials means them; a site that has entered
		// none uses the cluster's. The encryption key comes from the site salts
		// in wp-config.php, which a network shares, so one encrypted value is
		// readable from every site without re-encrypting it per site.
		$networked = $this->stored( self::NETWORK_OPTION );

		if ( ! empty( $networked['password'] ) ) {
			return $this->encryption->decrypt( (string) $networked['password'] );
		}

		return Result::failure( 'not_configured', 'No password is configured.' );
	}

	/**
	 * Store a username and password.
	 *
	 * Refuses when a constant is in force, because writing a value that will
	 * never be read is the failure mode ADR-009 exists to prevent.
	 *
	 * @since 0.1.0
	 *
	 * @param string $username Username. May be empty.
	 * @param string $password Password. May be empty to clear it.
	 * @return Result
	 */
	public function store( string $username, string $password ): Result {
		if ( defined( self::CONST_PASSWORD ) ) {
			return Result::failure(
				'pinned_by_constant',
				'Credentials are set in wp-config.php and cannot be changed here.'
			);
		}

		if ( '' === $password ) {
			delete_option( self::OPTION );

			return Result::success();
		}

		if ( ! $this->encryption->is_available() ) {
			return Result::failure(
				'encryption_unavailable',
				'Credentials cannot be stored securely on this server. Set SOLRENGINE_SOLR_USER and SOLRENGINE_SOLR_PASSWORD in wp-config.php instead.'
			);
		}

		$encrypted = $this->encryption->encrypt( $password );

		if ( $encrypted->is_failure() ) {
			return $encrypted;
		}

		$saved = update_option(
			self::OPTION,
			array(
				'username' => $username,
				'password' => $encrypted->value( '' ),
			),
			false
		);

		return $saved
			? Result::success()
			: Result::failure( 'not_saved', 'The credentials could not be written to the database.' );
	}

	/**
	 * Store credentials for every site in a network.
	 *
	 * Written once by a network administrator rather than typed into four
	 * hundred settings screens. A site that stores its own still wins: the
	 * network value is a fallback, not a pin, because one site in a network
	 * legitimately talking to a different Solr is a smaller surprise than a site
	 * whose password cannot be corrected from its own screen.
	 *
	 * @since 0.9.0
	 *
	 * @param string $username Username. May be empty.
	 * @param string $password Password. May be empty to clear it.
	 * @return Result
	 */
	public function store_for_network( string $username, string $password ): Result {
		if ( ! is_multisite() ) {
			return Result::failure( 'not_a_network', 'This is not a multisite network.' );
		}

		if ( defined( self::CONST_PASSWORD ) ) {
			return Result::failure(
				'pinned_by_constant',
				'Credentials are set in wp-config.php and cannot be changed here.'
			);
		}

		if ( '' === $password ) {
			delete_site_option( self::NETWORK_OPTION );

			return Result::success();
		}

		if ( ! $this->encryption->is_available() ) {
			return Result::failure(
				'encryption_unavailable',
				'Credentials cannot be stored securely on this server. Set SOLRENGINE_SOLR_USER and SOLRENGINE_SOLR_PASSWORD in wp-config.php instead.'
			);
		}

		$encrypted = $this->encryption->encrypt( $password );

		if ( $encrypted->is_failure() ) {
			return $encrypted;
		}

		$saved = update_site_option(
			self::NETWORK_OPTION,
			array(
				'username' => $username,
				'password' => $encrypted->value( '' ),
			)
		);

		return $saved
			? Result::success()
			: Result::failure( 'not_saved', 'The credentials could not be written to the database.' );
	}

	/**
	 * Whether the network has credentials of its own.
	 *
	 * @since 0.9.0
	 *
	 * @return bool
	 */
	public function has_network_password(): bool {
		return ! empty( $this->stored( self::NETWORK_OPTION )['password'] );
	}

	/**
	 * Remove stored credentials.
	 *
	 * Only this site's. A network administrator removes the network's from the
	 * network screen, and a site clearing its own must not silently disarm every
	 * other site in the network.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Read one credential store, always as an array.
	 *
	 * @param string $option Option name.
	 * @return array<string,mixed>
	 */
	private function stored( string $option ): array {
		$value = self::NETWORK_OPTION === $option
			? ( is_multisite() ? get_site_option( $option, array() ) : array() )
			: get_option( $option, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * A description safe to render in the admin UI.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function describe(): string {
		switch ( $this->source() ) {
			case 'constant':
				return __( 'Set in wp-config.php', 'solrengine' );
			case 'option':
				return __( 'Saved and encrypted', 'solrengine' );
			case 'network':
				return __( 'Set for the whole network', 'solrengine' );
			default:
				return __( 'Not configured', 'solrengine' );
		}
	}

	/**
	 * Strip anything secret out of a context array before it is logged.
	 *
	 * Log hygiene is not optional: a password in a debug log is a disclosure
	 * whether or not anyone reads it, and a URL with embedded credentials is a
	 * common way for one to arrive there.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string,mixed> $context Context to clean.
	 * @return array<string,mixed>
	 */
	public static function redact( array $context ): array {
		$secret_keys = array( 'password', 'pass', 'secret', 'token', 'api_key', 'authorization', 'auth' );

		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $secret_keys, true ) ) {
				$context[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
				continue;
			}

			if ( is_string( $value ) ) {
				$context[ $key ] = self::redact_url_credentials( $value );
			}
		}

		return $context;
	}

	/**
	 * Replace credentials embedded in a URL with a placeholder.
	 *
	 * @param string $value Possibly a URL.
	 * @return string
	 */
	private static function redact_url_credentials( string $value ): string {
		if ( false === strpos( $value, '@' ) ) {
			return $value;
		}

		return (string) preg_replace(
			'#(?<scheme>https?://)(?:[^/@:\s]+)(?::[^/@\s]*)?@#i',
			'$1[redacted]@',
			$value
		);
	}

	/**
	 * The password from a constant, or null when none is defined.
	 *
	 * @return string|null
	 */
	private function constant_password(): ?string {
		if ( ! defined( self::CONST_PASSWORD ) ) {
			return null;
		}

		$value = constant( self::CONST_PASSWORD );

		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
