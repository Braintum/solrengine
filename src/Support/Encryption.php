<?php
/**
 * Symmetric encryption for stored secrets.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Contracts\Result;

/**
 * Encrypts values at rest using a key derived from the site's salts.
 *
 * What this protects against: a leaked database dump. A backup, a staging
 * clone or an exported table no longer yields a usable Solr password.
 *
 * What this does NOT protect against: anything that can read wp-config.php.
 * The key is derived from AUTH_KEY and SECURE_AUTH_KEY, which sit beside the
 * code, so code execution on the host defeats it completely. This is
 * obfuscation against database disclosure, not encryption in the sense a
 * security reviewer means it, and ADR-009 says so in those words. Operators
 * who need more are pointed at constants instead.
 *
 * Rotating the site's salts invalidates stored ciphertext. That is detected
 * and surfaced as "re-enter your Solr password" rather than failing silently.
 *
 * @since 0.1.0
 */
final class Encryption {

	/**
	 * Prefix marking a value as produced by this class.
	 *
	 * Versioned so that a future change of algorithm can be recognised and
	 * migrated rather than guessed at.
	 *
	 * @var string
	 */
	private const PREFIX = 'se1:';

	/**
	 * Whether encryption is possible in this environment.
	 *
	 * Requires the sodium extension, which has shipped with PHP since 7.2 but
	 * can be disabled, and requires the site's salts to have been customised
	 * away from the installer's placeholders.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return false;
		}

		return '' !== $this->key_material();
	}

	/**
	 * Encrypt a value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return Result Success carrying the ciphertext string, or a failure.
	 */
	public function encrypt( string $plaintext ): Result {
		if ( ! $this->is_available() ) {
			return Result::failure(
				'encryption_unavailable',
				'The sodium extension or the site salts are unavailable.'
			);
		}

		$key   = $this->key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		sodium_memzero( $key );

		// Transport encoding for binary ciphertext so it survives a database
		// text column. Not obfuscation: the plaintext is already encrypted.
		$encoded = base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return Result::success( self::PREFIX . $encoded );
	}

	/**
	 * Decrypt a value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ciphertext Value produced by encrypt().
	 * @return Result Success carrying the plaintext string, or a failure whose
	 *                code is "salts_rotated" when the value is well-formed but
	 *                undecryptable, which is the case worth telling the user
	 *                about.
	 */
	public function decrypt( string $ciphertext ): Result {
		if ( ! $this->is_available() ) {
			return Result::failure(
				'encryption_unavailable',
				'The sodium extension or the site salts are unavailable.'
			);
		}

		if ( 0 !== strpos( $ciphertext, self::PREFIX ) ) {
			return Result::failure( 'not_encrypted', 'The stored value is not in the expected format.' );
		}

		// Strict mode, so malformed stored data is rejected rather than
		// silently decoding to garbage that then fails authentication.
		$raw = base64_decode( substr( $ciphertext, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return Result::failure( 'malformed', 'The stored value is truncated or corrupt.' );
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key    = $this->key();

		$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

		sodium_memzero( $key );

		if ( false === $plaintext ) {
			return Result::failure(
				'salts_rotated',
				'The stored value cannot be decrypted. The site salts have probably changed.'
			);
		}

		return Result::success( $plaintext );
	}

	/**
	 * Derive the encryption key from the site's salts.
	 *
	 * @return string Raw 32-byte key.
	 */
	private function key(): string {
		return sodium_crypto_generichash(
			$this->key_material(),
			'',
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}

	/**
	 * Gather key material from the site's salts.
	 *
	 * Returns an empty string when the salts are missing or still hold the
	 * installer's placeholder text, because deriving a key from a value every
	 * WordPress site shares would be worse than not encrypting: it would look
	 * like protection while providing none.
	 *
	 * @return string
	 */
	private function key_material(): string {
		$material = '';

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = constant( $constant );

			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			if ( false !== strpos( $value, 'put your unique phrase here' ) ) {
				continue;
			}

			$material .= $value;
		}

		return strlen( $material ) >= 32 ? $material : '';
	}
}
