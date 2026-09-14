<?php
/**
 * Plugin Name:       Family Access
 * Plugin URI:        https://github.com/anirudhatalmale6-alt/family-access
 * Description:       Private members area for a family &amp; friends website. Per-user access to 12 numbered pages, secure registration with a not-a-robot check, automatic logout when the visitor leaves, and a menu page that greys out anything the member is not allowed to open.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Anirudha Talmale
 * License:           GPL-2.0-or-later
 * Text Domain:       family-access
 *
 * Everything this plugin renders on the front end comes out of a shortcode, so every
 * page stays fully editable in Elementor. Nothing here locks the design down.
 */

defined( 'ABSPATH' ) || exit;

define( 'FFAC_VERSION', '1.0.0' );
define( 'FFAC_FILE', __FILE__ );
define( 'FFAC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFAC_URL', plugin_dir_url( __FILE__ ) );

/** How many numbered member pages the site has. Page-01 ... Page-12. */
define( 'FFAC_SLOTS', 12 );

require_once FFAC_DIR . 'includes/class-ffac-settings.php';
require_once FFAC_DIR . 'includes/class-ffac-pages.php';
require_once FFAC_DIR . 'includes/class-ffac-access.php';
require_once FFAC_DIR . 'includes/class-ffac-session.php';
require_once FFAC_DIR . 'includes/class-ffac-auth.php';
require_once FFAC_DIR . 'includes/class-ffac-registration.php';
require_once FFAC_DIR . 'includes/class-ffac-shortcodes.php';
require_once FFAC_DIR . 'includes/class-ffac-admin.php';
require_once FFAC_DIR . 'includes/class-ffac-install.php';

/**
 * Boot every component once WordPress has loaded its own pluggable functions.
 */
function ffac_boot() {
	FFAC_Pages::init();
	FFAC_Access::init();
	FFAC_Session::init();
	FFAC_Auth::init();
	FFAC_Registration::init();
	FFAC_Shortcodes::init();

	if ( is_admin() ) {
		FFAC_Admin::init();
	}
}
add_action( 'plugins_loaded', 'ffac_boot' );

register_activation_hook( __FILE__, array( 'FFAC_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FFAC_Install', 'deactivate' ) );

/**
 * Front-end styles and the session watchdog script.
 */
function ffac_front_assets() {
	wp_enqueue_style( 'ffac', FFAC_URL . 'assets/ffac.css', array(), FFAC_VERSION );

	if ( ! is_user_logged_in() ) {
		return;
	}

	$settings = FFAC_Settings::all();

	wp_enqueue_script( 'ffac-session', FFAC_URL . 'assets/ffac-session.js', array(), FFAC_VERSION, true );
	wp_localize_script(
		'ffac-session',
		'ffacSession',
		array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'ffac_session' ),
			'pingInterval'  => max( 30, (int) $settings['ping_interval'] ) * 1000,
			'logoutOnLeave' => ! empty( $settings['logout_on_leave'] ),
			'home'          => home_url( '/' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'ffac_front_assets' );
