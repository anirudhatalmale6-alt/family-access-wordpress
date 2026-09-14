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
		add_action( 'template_redirect', array( __CLASS__, 'never_cache' ), 0 );

		// Keep protected pages out of search results and menus for people who cannot open them.
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_search' ) );
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'filter_menu_items' ), 10, 2 );

		// Block themes build their navigation from get_pages(), which ignores the
		// menu filter above. Without this the page titles leak into the header.
		add_filter( 'get_pages', array( __CLASS__, 'filter_page_list' ) );

		// Keep the member pages out of the XML sitemap and out of search engines.
		// WordPress publishes every page in /wp-sitemap.xml by default, which would
		// hand Google the address of all twelve.
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'filter_sitemap' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'noindex_member_pages' ), 1 );
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
	 * Keep the members area away from any page cache.
	 *
	 * This matters more than it sounds. A caching plugin stores the HTML of a page
	 * and hands the same copy to the next visitor. Two ways that breaks this site:
	 *
	 *   - the login and register forms carry a one-time security token. Serve a
	 *     stored copy of that page a day later and the token is stale, so every
	 *     sign-in fails with "your form session had expired" and nobody can get in;
	 *   - a members page cached while somebody was signed in could be handed
	 *     straight to a stranger, which would defeat the whole point.
	 *
	 * DONOTCACHEPAGE is the flag the cache plugins agree on — SpeedyCache, WP
	 * Rocket, W3 Total Cache, LiteSpeed and others all honour it — so this works
	 * whichever one the site ends up using.
	 */
	public static function never_cache() {
		if ( is_admin() ) {
			return;
		}

		$sensitive = is_user_logged_in();

		if ( ! $sensitive && is_singular( 'page' ) ) {
			$page_id = get_queried_object_id();
			$special = array_filter(
				array(
					(int) FFAC_Settings::get( 'menu_page' ),
					(int) FFAC_Settings::get( 'login_page' ),
					(int) FFAC_Settings::get( 'register_page' ),
				)
			);
			$sensitive = FFAC_Pages::slot_for_page( $page_id ) || in_array( $page_id, $special, true );
		}

		if ( ! $sensitive ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
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
		if ( ! is_array( $items ) || ! self::is_front_end_request() ) {
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
	 * Every protected page, plus the members menu, comes out of the XML sitemap.
	 * This one is not about the visitor in front of us — a sitemap is read by
	 * search engines, so the exclusion is unconditional.
	 */
	public static function filter_sitemap( $args, $post_type ) {
		if ( 'page' !== $post_type ) {
			return $args;
		}

		$exclude = FFAC_Pages::protected_ids();
		$menu_id = (int) FFAC_Settings::get( 'menu_page' );
		if ( $menu_id ) {
			$exclude[] = $menu_id;
		}

		if ( $exclude ) {
			$args['post__not_in'] = array_merge(
				isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(),
				$exclude
			);
		}

		return $args;
	}

	/**
	 * Belt and braces: tell search engines not to index a member page even if
	 * they somehow arrive at one.
	 */
	public static function noindex_member_pages() {
		if ( ! is_singular( 'page' ) ) {
			return;
		}

		$page_id = get_queried_object_id();
		$menu_id = (int) FFAC_Settings::get( 'menu_page' );

		if ( FFAC_Pages::slot_for_page( $page_id ) || ( $menu_id && $page_id === $menu_id ) ) {
			echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
		}
	}

	/**
	 * Strip protected pages out of page lists on the front end — the header
	 * navigation of a block theme, a page-list widget, a sitemap block.
	 */
	public static function filter_page_list( $pages ) {
		if ( ! is_array( $pages ) || ! self::is_front_end_request() ) {
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
	 * Is this a page being rendered for a visitor?
	 *
	 * The page-list filter must only touch the front end. WP-CLI, cron and the
	 * dashboard all have no visitor to judge, and if we hide pages from them we
	 * break tools the owner relies on — a menu built in the dashboard would come
	 * out missing the very pages it is supposed to contain.
	 */
	protected static function is_front_end_request() {
		if ( is_admin() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return false; // No HTTP request at all: a script, an importer, a seeder.
		}
		return true;
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
