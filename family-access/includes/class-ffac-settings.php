<?php
/**
 * Plugin settings, stored as one option so the admin screens stay simple.
 */

defined( 'ABSPATH' ) || exit;

class FFAC_Settings {

	const OPTION = 'ffac_settings';

	/**
	 * Defaults. Anything missing from the stored option falls back to here, so a
	 * future version can add a key without a migration step.
	 */
	public static function defaults() {
		return array(
			'menu_page'          => 0,     // Page the member lands on after login.
			'login_page'         => 0,
			'register_page'      => 0,
			'idle_minutes'       => 20,    // Inactivity before the session is dropped.
			'ping_interval'      => 60,    // Seconds between browser keep-alive pings.
			'logout_on_leave'    => 1,     // Log out when they navigate away / close the tab.
			'remember_username'  => 1,     // Pre-fill the username box for returning members.
			'require_approval'   => 1,     // New registrations wait for the admin.
			'harden_login'       => 1,     // Stop WordPress giving the usernames away.
			'captcha_mode'       => 'builtin', // 'builtin' | 'recaptcha' | 'off'
			'recaptcha_site'     => '',
			'recaptcha_secret'   => '',
			'notify_email'       => '',    // Blank = the site admin email.
			'denied_message'     => 'That page has not been unlocked for your account yet.',
		);
	}

	/**
	 * All settings, defaults merged in.
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function save( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * URL of one of the three special pages, falling back to home so a half
	 * configured site never redirects into a 404.
	 */
	public static function page_url( $which, $fallback = '' ) {
		$id = (int) self::get( $which, 0 );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return get_permalink( $id );
		}
		if ( $fallback ) {
			return $fallback;
		}
		return home_url( '/' );
	}
}
