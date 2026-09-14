<?php
/**
 * Who may open which numbered page, and the guard that enforces it.
 *
 * Permissions live in user meta as a list of slot numbers, e.g. array(1,3,7).
 * The guard runs on template_redirect, so typing the URL straight into the
 * address bar is blocked exactly like clicking a greyed out tile.
 */

defined( 'ABSPATH' ) || exit;

class FFAC_Access {

	const META = 'ffac_allowed_slots';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'guard' ), 1 );

		// Keep protected pages out of search results and menus for people who cannot open them.
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_search' ) );
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'filter_menu_items' ), 10, 2 );

		// Block themes build their navigation from get_pages(), which ignores the
		// menu filter above. Without this the page titles leak into the header.
		add_filter( 'get_pages', array( __CLASS__, 'filter_page_list' ) );
	}

	/**
	 * The slots a user is allowed to open.
	 */
	public static function allowed_slots( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}

		// An administrator or editor always sees everything, otherwise the client
		// could lock himself out of his own site.
		if ( self::is_manager( $user_id ) ) {
			return range( 1, FFAC_SLOTS );
		}

		$slots = get_user_meta( $user_id, self::META, true );
		if ( ! is_array( $slots ) ) {
			return array();
		}

		$slots = array_map( 'intval', $slots );
		$slots = array_filter(
			$slots,
			function ( $slot ) {
				return $slot >= 1 && $slot <= FFAC_SLOTS;
			}
		);

		sort( $slots );
		return array_values( array_unique( $slots ) );
	}

	public static function save_slots( $user_id, array $slots ) {
		$clean = array();
		foreach ( $slots as $slot ) {
			$slot = (int) $slot;
			if ( $slot >= 1 && $slot <= FFAC_SLOTS ) {
				$clean[] = $slot;
			}
		}
		sort( $clean );
		update_user_meta( $user_id, self::META, array_values( array_unique( $clean ) ) );
	}

	public static function user_can_slot( $user_id, $slot ) {
		return in_array( (int) $slot, self::allowed_slots( $user_id ), true );
	}

	/**
	 * Anyone who can edit the site is treated as staff, not as a member.
	 */
	public static function is_manager( $user_id ) {
		$user = get_userdata( $user_id );
		return $user && ( user_can( $user, 'manage_options' ) || user_can( $user, 'edit_pages' ) );
	}

	/**
	 * The gate. Runs before anything is rendered.
	 */
	public static function guard() {
		if ( is_admin() || ! is_singular( 'page' ) ) {
			return;
		}

		$page_id = get_queried_object_id();
		$slot    = FFAC_Pages::slot_for_page( $page_id );
		$menu_id = (int) FFAC_Settings::get( 'menu_page' );

		// The menu page itself is members only, but it is not one of the 12.
		if ( ! $slot && $menu_id && $page_id === $menu_id ) {
			if ( ! is_user_logged_in() ) {
				self::bounce_to_login( $page_id );
			}
			return;
		}

		if ( ! $slot ) {
			return; // A normal public page: About, Contact, the landing page.
		}

		if ( ! is_user_logged_in() ) {
			self::bounce_to_login( $page_id );
		}

		if ( ! self::user_can_slot( get_current_user_id(), $slot ) ) {
			$url = add_query_arg(
				array(
					'ffac' => 'denied',
					'code' => FFAC_Pages::code( $slot ),
				),
				FFAC_Settings::page_url( 'menu_page' )
			);
			wp_safe_redirect( $url, 302 );
			exit;
		}
	}

	/**
	 * Send a logged out visitor to the login page, remembering where they wanted to go.
	 */
	protected static function bounce_to_login( $page_id ) {
		$url = add_query_arg(
			array(
				'ffac'        => 'login_required',
				'redirect_to' => rawurlencode( get_permalink( $page_id ) ),
			),
			FFAC_Settings::page_url( 'login_page', wp_login_url( get_permalink( $page_id ) ) )
		);
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * Do not leak protected page titles through the site search.
	 */
	public static function filter_search( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$hidden = self::hidden_ids_for_current_user();
		if ( $hidden ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $hidden ) );
		}
	}

	/**
	 * Strip protected pages out of any nav menu the visitor is not allowed to open.
	 */
	public static function filter_menu_items( $items, $menu ) {
		if ( is_admin() || ! is_array( $items ) ) {
			return $items;
		}

		$hidden = self::hidden_ids_for_current_user();
		if ( ! $hidden ) {
			return $items;
		}

		foreach ( $items as $key => $item ) {
			if ( 'page' === $item->object && in_array( (int) $item->object_id, $hidden, true ) ) {
				unset( $items[ $key ] );
			}
		}

		return array_values( $items );
	}

	/**
	 * Strip protected pages out of page lists on the front end — the header
	 * navigation of a block theme, a page-list widget, a sitemap block.
	 */
	public static function filter_page_list( $pages ) {
		if ( is_admin() || ! is_array( $pages ) ) {
			return $pages;
		}

		$hidden = self::hidden_ids_for_current_user();
		if ( ! $hidden ) {
			return $pages;
		}

		foreach ( $pages as $key => $page ) {
			if ( isset( $page->ID ) && in_array( (int) $page->ID, $hidden, true ) ) {
				unset( $pages[ $key ] );
			}
		}

		return array_values( $pages );
	}

	/**
	 * Protected page IDs the current visitor may not open.
	 */
	public static function hidden_ids_for_current_user() {
		$map     = FFAC_Pages::map();
		$allowed = is_user_logged_in() ? self::allowed_slots( get_current_user_id() ) : array();
		$hidden  = array();

		foreach ( $map as $slot => $id ) {
			if ( $id && ! in_array( (int) $slot, $allowed, true ) ) {
				$hidden[] = (int) $id;
			}
		}

		return $hidden;
	}
}
