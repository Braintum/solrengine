<?php
/**
 * What visitors type must not reach a log.
 *
 * The privacy document promises that search queries are not logged. The debug log
 * recorded every Solr request URL, query string included, so with debug
 * logging on, `q=` carried each search into the log file. These tests send a
 * synthetic marker through the real transport and assert it appears in no log
 * context, on success and on failure alike.
 *
 * Here rather than in the unit suite because HttpTransport reads credentials,
 * and Credentials reads options.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Integration;

use SolrEngine\Backend\Solr\Endpoint;
use SolrEngine\Backend\Solr\HttpTransport;
use SolrEngine\Backend\Solr\Sender;
use SolrEngine\Contracts\Logger;
use SolrEngine\Contracts\Result;
use SolrEngine\Support\Credentials;
use SolrEngine\Support\Encryption;
use WP_UnitTestCase;

/**
 * @covers \SolrEngine\Backend\Solr\HttpTransport
 */
final class LogPrivacyTest extends WP_UnitTestCase {

	private const MARKER = 'solrengine-audit-marker';

	public function test_a_successful_search_logs_no_search_text(): void {
		$logger = $this->logger();

		$this->transport( $logger, $this->ok() )
			->get_in( 'wordpress', 'select', $this->query() );

		$this->assertNotEmpty( $logger->entries, 'The request was logged at all.' );
		$this->assertMarkerAbsent( $logger );
	}

	public function test_a_failed_search_logs_no_search_text(): void {
		$logger = $this->logger();

		$this->transport( $logger, Result::failure( 'timeout', 'Solr did not answer in time.' ) )
			->get_in( 'wordpress', 'select', $this->query() );

		$this->assertNotEmpty( $logger->entries, 'The failure was logged at all.' );
		$this->assertMarkerAbsent( $logger );
	}

	public function test_the_logged_url_keeps_the_handler_and_parameter_names(): void {
		$logger = $this->logger();

		$this->transport( $logger, $this->ok() )
			->get_in( 'wordpress', 'select', $this->query() );

		$url = (string) $logger->entries[0]['context']['url'];

		$this->assertStringStartsWith( 'http://solr.test:8983/solr/wordpress/select?', $url );
		$this->assertStringContainsString( 'q', $url );
		$this->assertStringContainsString( 'rows', $url );
		$this->assertStringNotContainsString( '=', $url );
	}

	private function ok(): Result {
		return Result::success(
			array(
				'status' => 200,
				'body'   => '{}',
			)
		);
	}

	/**
	 * @return array<string,scalar>
	 */
	private function query(): array {
		return array(
			'q'    => 'my name is ' . self::MARKER,
			'df'   => 'se_txt_title',
			'rows' => 10,
		);
	}

	private function assertMarkerAbsent( Logger $logger ): void {
		$this->assertStringNotContainsString( self::MARKER, (string) wp_json_encode( $logger->entries ) );
	}

	private function transport( Logger $logger, Result $reply ): HttpTransport {
		$sender = new class( $reply ) implements Sender {

			/**
			 * @var Result
			 */
			private $reply;

			public function __construct( Result $reply ) {
				$this->reply = $reply;
			}

			public function send( string $method, string $url, array $headers, ?string $body, int $timeout, bool $verify_tls, int $max_bytes ): Result {
				return $this->reply;
			}
		};

		return new HttpTransport(
			new Endpoint( 'http', 'solr.test', 8983, '/solr', 'wordpress' ),
			new Credentials( new Encryption() ),
			$logger,
			5,
			true,
			$sender
		);
	}

	private function logger(): Logger {
		return new class() implements Logger {

			/**
			 * @var array<int,array{level:string,message:string,context:array<string,mixed>}>
			 */
			public $entries = array();

			/**
			 * @param string              $level   Level.
			 * @param string              $message Message.
			 * @param array<string,mixed> $context Context.
			 */
			private function record( string $level, string $message, array $context ): void {
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}

			public function debug( string $message, array $context = array() ): void {
				$this->record( 'debug', $message, $context );
			}

			public function info( string $message, array $context = array() ): void {
				$this->record( 'info', $message, $context );
			}

			public function warning( string $message, array $context = array() ): void {
				$this->record( 'warning', $message, $context );
			}

			public function error( string $message, array $context = array() ): void {
				$this->record( 'error', $message, $context );
			}
		};
	}
}
