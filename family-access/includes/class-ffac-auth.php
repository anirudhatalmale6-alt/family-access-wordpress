<?php
/**
 * Signing in and signing out from the front-end forms.
 *
 * The WordPress login screen still exists for the site owner, but members never
 * see it: they use the Elementor-built login page, which posts here.
 */

defined( 'ABSPATH' ) || exit;

class FFAC_Auth {

	/** Failed attempts allowed before the account is paused for a while. */
	const MAX_ATTEMPTS = 5;
	const LOCK_MINUTES = 15;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_login' ), 6 );
		add_action( 'init', array( __CLASS__, 'handle_logout' ), 6 );

		// After a normal wp-login.php sign-in, members still land on their menu page.
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );

		if ( FFAC_Settings::get( 'harden_login' ) ) {
			self::harden();
		}
	}

	/**
	 * Stop WordPress handing out the usernames on the site.
	 *
	 * Out of the box, /wp-json/wp/v2/users and /?author=1 will both tell anyone who
	 * asks what the administrator is called. That is half of a username-and-password
	 * pair given away for free, and it is the first thing an automated attack asks for.
	 */
	protected static function harden() {
		// The REST users endpoint, for anyone not signed in.
		add_filter(
			'rest_endpoints',
			function ( $endpoints ) {
				if ( is_user_logged_in() ) {
					return $endpoints;
				}
				foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
					unset( $endpoints[ $route ] );
				}
				return $endpoints;
			}
		);

		// /?author=1 redirecting to /author/admin/ gives the same thing away.
		add_action(
			'template_redirect',
			function () {
				if ( is_user_logged_in() || is_admin() ) {
					return;
				}
				if ( isset( $_GET['author'] ) || is_author() ) {
					wp_safe_redirect( home_url( '/' ), 301 );
					exit;
				}
			},
			0
		);

		// And the author pages listed in the sitemap.
		add_filter(
			'wp_sitemaps_add_provider',
			function ( $provider, $name ) {
				return 'users' === $name ? false : $provider;
			},
			10,
			2
		);

		// wp-login.php says "the password you entered for the username X is
		// incorrect", which confirms X exists. One vague answer instead.
		add_filter(
			'login_errors',
			function () {
				return 'That username and password did not match. Please try again.';
			}
		);
	}

	public static function handle_login() {
		if ( empty( $_POST['ffac_login'] ) ) {
			return;
		}

		$post = wp_unslash( $_POST );

		if ( ! isset( $post['ffac_login_nonce'] ) || ! wp_verify_nonce( $post['ffac_login_nonce'], 'ffac_login' ) ) {
			self::fail( 'Your form session had expired. Please sign in again.' );
		}

		$username = sanitize_user( $post['ffac_username'] ?? '', true );
		$password = (string) ( $post['ffac_password'] ?? '' );

		if ( '' === $username || '' === $password ) {
			self::fail( 'Please enter both your username and your password.' );
		}

		$lock_key = 'ffac_lock_' . md5( strtolower( $username ) );
		$attempts = (int) get_transient( $lock_key );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::fail( sprintf( 'Too many failed attempts. Please wait %d minutes and try again.', self::LOCK_MINUTES ) );
		}

		$user = wp_signon(
			array(
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => false, // Session cookie only — see FFAC_Session.
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			$code = $user->get_error_code();

			// "Waiting for approval" and "blocked" are not wrong passwords, so they
			// must not count towards the lockout — otherwise somebody checking
			// whether they have been approved yet locks their own account out.
			if ( in_array( $code, array( 'ffac_pending', 'ffac_blocked' ), true ) ) {
				self::fail( wp_strip_all_tags( $user->get_error_message() ) );
			}

			set_transient( $lock_key, $attempts + 1, self::LOCK_MINUTES * MINUTE_IN_SECONDS );

			// Everything else gets one vague answer, so the form cannot be used to
			// work out which usernames exist on the site.
			self::fail( 'That username and password did not match. Please try again.' );
		}

		delete_transient( $lock_key );
		wp_set_current_user( $user->ID );
		update_user_meta( $user->ID, FFAC_Session::META_SEEN, time() );
		delete_user_meta( $user->ID, FFAC_Session::META_LEFT );

		wp_safe_redirect( self::destination_for( $user, $post['redirect_to'] ?? '' ), 302 );
		exit;
	}

	/**
	 * Where a member goes after signing in: back to the page they were trying to
	 * open if they are allowed it, otherwise the menu page.
	 */
	public static function destination_for( $user, $requested = '' ) {
		$menu = FFAC_Settings::page_url( 'menu_page' );

		if ( ! $requested ) {
			return $menu;
		}

		$requested = esc_url_raw( rawurldecode( $requested ) );
		$page_id   = url_to_postid( $requested );
		if ( ! $page_id ) {
			return $menu;
		}

		$slot = FFAC_Pages::slot_for_page( $page_id );
		if ( $slot && ! FFAC_Access::user_can_slot( $user->ID, $slot ) ) {
			return add_query_arg( array( 'ffac' => 'denied', 'code' => FFAC_Pages::code( $slot ) ), $menu );
		}

		return $requested;
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( $user instanceof WP_User && ! user_can( $user, 'edit_posts' ) ) {
			return self::destination_for( $user, $requested );
		}
		return $redirect_to;
	}

	/**
	 * The [family_logout] link and the "sign out" button post here.
	 */
	public static function handle_logout() {
		if ( empty( $_GET['ffac_logout'] ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ffac_logout' ) ) {
			return;
		}

		wp_logout();
		wp_safe_redirect( add_query_arg( 'ffac', 'logged_out', FFAC_Settings::page_url( 'login_page', home_url( '/' ) ) ), 302 );
		exit;
	}

	protected static function fail( $message ) {
		$token = FFAC_Registration::token_from_post( wp_unslash( $_POST ) );
		set_transient( 'ffac_login_' . $token, $message, 5 * MINUTE_IN_SECONDS );

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = FFAC_Settings::page_url( 'login_page', wp_login_url() );
		}
		$back = remove_query_arg( array( 'ffac', 'ft' ), $back );

		wp_safe_redirect( add_query_arg( array( 'ffac' => 'login_error', 'ft' => $token ), $back ), 302 );
		exit;
	}

	public static function take_error() {
		$token = FFAC_Registration::token_from_url();
		if ( ! $token ) {
			return '';
		}

		$msg = get_transient( 'ffac_login_' . $token );
		delete_transient( 'ffac_login_' . $token );

		return $msg ? (string) $msg : '';
	}

	public static function logout_url() {
		return wp_nonce_url( add_query_arg( 'ffac_logout', '1', home_url( '/' ) ), 'ffac_logout' );
	}
}
