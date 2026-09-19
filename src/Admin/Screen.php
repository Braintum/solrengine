<?php
/**
 * Shared behaviour for admin screens.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Admin;

/**
 * The parts of an admin screen that must never differ between screens.
 *
 * Not a framework. It holds exactly the things that are dangerous to write four
 * times: the capability check, the nonce check, the redirect that carries a
 * notice, and the reading of that notice back. Each of those is one line of
 * code and one way to get it subtly wrong, and a screen that forgets the nonce
 * check looks identical to one that has it.
 *
 * Presentation is deliberately not here. Every screen renders its own markup,
 * because a shared renderer is how admin pages end up describing themselves
 * through configuration arrays that are harder to read than the HTML.
 *
 * @since 0.8.0
 */
abstract class Screen {

	/**
	 * This screen's page slug.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * The menu label.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Render the screen.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	abstract public function render(): void;

	/**
	 * Add this screen to the SolrEngine menu.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			ConnectionScreen::SLUG,
			$this->label(),
			$this->label(),
			'manage_options',
			$this->slug(),
			array( $this, 'render' )
		);
	}

	/**
	 * Whether the current user may see or change anything here.
	 *
	 * @since 0.8.0
	 *
	 * @return bool
	 */
	protected function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Refuse the request unless this user may change settings.
	 *
	 * Half of the pair. The other half — check_admin_referer() — is deliberately
	 * not here, and is written out in every handler instead.
	 *
	 * That looks like the repetition this class exists to remove, and it is the
	 * one piece of repetition worth keeping. A nonce check is the thing a
	 * security review greps each handler for, static analysis cannot follow one
	 * into a parent class, and the check that answers "did this user ask for
	 * this" is the one a reader should be able to see without opening another
	 * file. The capability check that answers "may they" is safe to share,
	 * because a handler that omits it is caught by the nonce line next to it
	 * being absent too.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	protected function require_manage(): void {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'solrengine' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Return to this screen carrying a notice, and stop.
	 *
	 * A redirect rather than rendering in place. A screen rendered from a POST
	 * re-submits everything it just did when the reader presses reload, and for
	 * a screen whose buttons start an index rebuild that is not a cosmetic
	 * problem.
	 *
	 * @since 0.8.0
	 *
	 * @param string $notice Notice key.
	 * @param string $detail Optional detail carried alongside it.
	 * @return void
	 */
	protected function back( string $notice = '', string $detail = '' ): void {
		$args = array( 'page' => $this->slug() );

		if ( '' !== $notice ) {
			$args['solrengine_notice'] = $notice;
		}

		if ( '' !== $detail ) {
			// Carried in the URL rather than in an option, so two administrators
			// acting at once never read each other's messages.
			$args['solrengine_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );

		exit;
	}

	/**
	 * The notice key this request was redirected with.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	protected function notice_key(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect flag, changing nothing.
		return isset( $_GET['solrengine_notice'] ) ? sanitize_key( wp_unslash( $_GET['solrengine_notice'] ) ) : '';
	}

	/**
	 * The detail carried with the notice, if any.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	protected function notice_detail(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect flag, changing nothing.
		if ( ! isset( $_GET['solrengine_detail'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect flag, changing nothing.
		$encoded = sanitize_text_field( wp_unslash( $_GET['solrengine_detail'] ) );

		// Sanitised on both sides of the decode. Decoding can reintroduce the
		// characters the first pass removed, and this value is a message from
		// one of our own redirects only by convention — a URL is a thing anyone
		// can hand an administrator.
		return sanitize_text_field( rawurldecode( $encoded ) );
	}

	/**
	 * Render the notice for this request.
	 *
	 * @since 0.8.0
	 *
	 * @param array<string,array{0:string,1:string}> $messages Level and message, keyed by notice.
	 * @return void
	 */
	protected function render_notice( array $messages ): void {
		$key = $this->notice_key();

		if ( '' === $key || ! isset( $messages[ $key ] ) ) {
			return;
		}

		list( $level, $message ) = $messages[ $key ];

		$detail = $this->notice_detail();

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $level ),
			esc_html( '' === $detail ? $message : $message . ' ' . $detail )
		);
	}

	/**
	 * Open a form that posts to admin-post.php.
	 *
	 * @since 0.8.0
	 *
	 * @param string $action Action name, used for both the hook and the nonce.
	 * @return void
	 */
	protected function open_form( string $action ): void {
		printf(
			'<form method="post" action="%s"><input type="hidden" name="action" value="%s"/>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $action )
		);

		wp_nonce_field( $action );
	}
}
