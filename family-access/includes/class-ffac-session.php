<?php
/**
 * Session behaviour.
 *
 * Three separate rules, all of them required by the brief:
 *
 *  1. The login cookie is a browser session cookie. Close the browser, the
 *     session is gone. WordPress does that already when "remember me" is off,
 *     so we make sure "remember me" can never be switched on.
 *
 *  2. Leaving the site logs you out. The browser fires a beacon when the tab is
 *     closed or the visitor navigates to a site that is not this one. Clicks on
 *     internal links are ignored, so moving around inside the site is fine.
 *
 *  3. Idle timeout. If the browser stops checking in for longer than the
 *     configured number of minutes, the next request is logged out. This is the
 *     safety net for the cases a browser gives us no event at all.
 *
 * Returning visitors are remembered by a separate, harmless cookie that holds
 * nothing but the username, so the login form can greet them and pre-fill the
 * box. It carries no authentication whatsoever.
 */

defined( 'ABSPATH' ) || exit;

class FFAC_Session {

	const META_SEEN    = 'ffac_last_seen';
	const META_LEFT    = 'ffac_left_at';
	const COOKIE_KNOWN = 'ffac_known_user';

	/** A leave beacon that is followed by a request this quickly is treated as a false alarm. */
	const LEAVE_GRACE = 3;

	public static function init() {
		// Rule 1: never issue a long lived "remember me" cookie.
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'cookie_lifetime' ), 99, 3 );
		add_filter( 'login_form_defaults', array( __CLASS__, 'hide_remember_box' ) );

		// Rules 2 and 3 are checked on every front-end request.
		add_action( 'init', array( __CLASS__, 'enforce' ), 5 );

		// Browser check-ins.
		add_action( 'wp_ajax_ffac_ping', array( __CLASS__, 'ajax_ping' ) );
		add_action( 'wp_ajax_ffac_leave', array( __CLASS__, 'ajax_leave' ) );
		add_action( 'wp_ajax_nopriv_ffac_ping', array( __CLASS__, 'ajax_noop' ) );
		add_action( 'wp_ajax_nopriv_ffac_leave', array( __CLASS__, 'ajax_noop' ) );

		// Remember the username of a returning member (not the password, not the session).
		add_action( 'wp_login', array( __CLASS__, 'remember_username' ), 10, 2 );
		add_action( 'clear_auth_cookie', array( __CLASS__, 'clear_activity' ) );
	}

	/**
	 * Cap how long an auth cookie is valid for. Even a stolen cookie dies with
	 * the idle window rather than living for a fortnight.
	 */
	public static function cookie_lifetime( $length, $user_id, $remember ) {
		$idle = max( 5, (int) FFAC_Settings::get( 'idle_minutes', 20 ) ) * MINUTE_IN_SECONDS;
		// A little headroom so the cookie never expires before our own idle check runs.
		return $idle + ( 5 * MINUTE_IN_SECONDS );
	}

	public static function hide_remember_box( $defaults ) {
		$defaults['remember'] = false;
		return $defaults;
	}

	/**
	 * The enforcement pass. Runs early on every request.
	 */
	public static function enforce() {
		if ( ! is_user_logged_in() || wp_doing_cron() ) {
			return;
		}

		/*
		 * The session rules are for members, never for the people who run the site.
		 *
		 * This used to bail out only on `is_admin()`, which looks right and is not:
		 * the moment the owner clicked through to the front of his own site to see
		 * how a page looked, the member idle timer applied to him and logged him
		 * out — of the dashboard as well, because it is one session. He would go
		 * back to wp-admin and be staring at the login screen with no idea why.
		 *
		 * Judge the person, not the screen they happen to be on.
		 */
		if ( FFAC_Access::is_manager( get_current_user_id() ) ) {
			return;
		}

		// The ping is the browser saying "still here" — it must not be judged as idle.
		$action    = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$is_ping   = in_array( $action, array( 'ffac_ping', 'ffac_leave' ), true );
		$user_id   = get_current_user_id();
		$now       = time();
		$settings  = FFAC_Settings::all();

		// Rule 2 — a leave beacon was received. Anything other than an immediate
		// follow-up request means they really did go away.
		$left_at = (int) get_user_meta( $user_id, self::META_LEFT, true );
		if ( $left_at && ! $is_ping ) {
			if ( ( $now - $left_at ) >= self::LEAVE_GRACE ) {
				self::log_out_and_bounce( 'left' );
			}
			// Too quick to be a real departure — a race with an internal link. Carry on.
			delete_user_meta( $user_id, self::META_LEFT );
		}

		// Rule 3 — idle timeout.
		$idle_limit = max( 5, (int) $settings['idle_minutes'] ) * MINUTE_IN_SECONDS;
		$last_seen  = (int) get_user_meta( $user_id, self::META_SEEN, true );

		if ( $last_seen && ( $now - $last_seen ) > $idle_limit ) {
			self::log_out_and_bounce( 'timeout' );
		}

		// Touch the clock. Only write when it actually moved, to keep the
		// options/usermeta table from taking a write on every single hit.
		if ( ! $last_seen || ( $now - $last_seen ) > 30 ) {
			update_user_meta( $user_id, self::META_SEEN, $now );
		}
	}

	/**
	 * End the session and send the visitor to the login page with a reason.
	 */
	protected static function log_out_and_bounce( $reason ) {
		$user_id = get_current_user_id();
		delete_user_meta( $user_id, self::META_LEFT );
		delete_user_meta( $user_id, self::META_SEEN );
		wp_logout();

		if ( wp_doing_ajax() ) {
			wp_send_json_error( array( 'reason' => $reason ), 401 );
		}

		$url = add_query_arg( 'ffac', $reason, FFAC_Settings::page_url( 'login_page', wp_login_url() ) );
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * Keep-alive from an open tab.
	 */
	public static function ajax_ping() {
		check_ajax_referer( 'ffac_session', 'nonce' );
		$user_id = get_current_user_id();
		delete_user_meta( $user_id, self::META_LEFT );
		update_user_meta( $user_id, self::META_SEEN, time() );
		wp_send_json_success( array( 'ok' => true ) );
	}

	/**
	 * The visitor is leaving the site. Mark it; the session dies on the next request.
	 * sendBeacon cannot read a response, so there is nothing useful to return.
	 */
	public static function ajax_leave() {
		check_ajax_referer( 'ffac_session', 'nonce' );
		update_user_meta( get_current_user_id(), self::META_LEFT, time() );
		wp_send_json_success();
	}

	public static function ajax_noop() {
		wp_send_json_error( array( 'reason' => 'not-logged-in' ), 401 );
	}

	/**
	 * Store the username in a plain cookie so a returning member is greeted by
	 * name and the username box is filled in. They still have to type a password.
	 */
	public static function remember_username( $user_login, $user = null ) {
		if ( ! FFAC_Settings::get( 'remember_username' ) ) {
			return;
		}
		setcookie(
			self::COOKIE_KNOWN,
			rawurlencode( $user_login ),
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false, // Nothing secret in it; the login form reads it.
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * The username the browser last logged in with, or ''.
	 */
	public static function known_username() {
		if ( ! FFAC_Settings::get( 'remember_username' ) || empty( $_COOKIE[ self::COOKIE_KNOWN ] ) ) {
			return '';
		}
		return sanitize_user( rawurldecode( wp_unslash( $_COOKIE[ self::COOKIE_KNOWN ] ) ), true );
	}

	/**
	 * On logout, clear the activity markers so the next login starts clean.
	 */
	public static function clear_activity() {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			delete_user_meta( $user_id, self::META_LEFT );
			delete_user_meta( $user_id, self::META_SEEN );
		}
	}
}
