<?php
/**
 * The register of the 12 numbered member pages.
 *
 * A "slot" is the number the client sees: 1 = Page-01 ... 12 = Page-12.
 * Each slot points at a normal WordPress page, so the page itself can be
 * rebuilt, renamed or redesigned in Elementor without touching permissions.
 */

defined( 'ABSPATH' ) || exit;

class FFAC_Pages {

	const OPTION = 'ffac_page_map';   // array slot => page ID
	const TITLES = 'ffac_page_titles'; // array slot => friendly label for the menu tile

	public static function init() {
		// Keep the map honest: if a page is deleted, drop it out of the map.
		add_action( 'before_delete_post', array( __CLASS__, 'forget_deleted_page' ) );
	}

	/**
	 * slot => page ID, always 12 entries, missing ones as 0.
	 */
	public static function map() {
		$stored = get_option( self::OPTION, array() );
		$map    = array();
		for ( $slot = 1; $slot <= FFAC_SLOTS; $slot++ ) {
			$map[ $slot ] = isset( $stored[ $slot ] ) ? (int) $stored[ $slot ] : 0;
		}
		return $map;
	}

	public static function save_map( array $map ) {
		$clean = array();
		for ( $slot = 1; $slot <= FFAC_SLOTS; $slot++ ) {
			$clean[ $slot ] = isset( $map[ $slot ] ) ? (int) $map[ $slot ] : 0;
		}
		update_option( self::OPTION, $clean );
	}

	public static function page_id( $slot ) {
		$map  = self::map();
		$slot = (int) $slot;
		return isset( $map[ $slot ] ) ? (int) $map[ $slot ] : 0;
	}

	/**
	 * Which slot does this page ID belong to? 0 when the page is not protected.
	 */
	public static function slot_for_page( $page_id ) {
		$page_id = (int) $page_id;
		if ( ! $page_id ) {
			return 0;
		}
		foreach ( self::map() as $slot => $id ) {
			if ( $id === $page_id ) {
				return (int) $slot;
			}
		}
		return 0;
	}

	/**
	 * "Page-01" style code used everywhere in the UI.
	 */
	public static function code( $slot ) {
		return sprintf( 'Page-%02d', (int) $slot );
	}

	/**
	 * Label shown on the menu tile. The admin may set a friendly name; if not we
	 * use the page's own title, and if the slot is empty we still show the code
	 * so the grid never collapses.
	 */
	public static function label( $slot ) {
		$titles = get_option( self::TITLES, array() );
		if ( ! empty( $titles[ $slot ] ) ) {
			return $titles[ $slot ];
		}
		$id = self::page_id( $slot );
		if ( $id ) {
			$title = get_the_title( $id );
			if ( $title ) {
				return $title;
			}
		}
		return self::code( $slot );
	}

	public static function save_titles( array $titles ) {
		$clean = array();
		for ( $slot = 1; $slot <= FFAC_SLOTS; $slot++ ) {
			$clean[ $slot ] = isset( $titles[ $slot ] ) ? sanitize_text_field( $titles[ $slot ] ) : '';
		}
		update_option( self::TITLES, $clean );
	}

	/**
	 * Create any of the 12 pages that do not exist yet. Returns how many were made.
	 * Safe to run more than once: a slot that already points at a live page is left alone.
	 */
	public static function ensure_pages() {
		$map     = self::map();
		$created = 0;

		for ( $slot = 1; $slot <= FFAC_SLOTS; $slot++ ) {
			if ( $map[ $slot ] && 'trash' !== get_post_status( $map[ $slot ] ) && get_post_status( $map[ $slot ] ) ) {
				continue;
			}
			$code = self::code( $slot );
			$id   = wp_insert_post(
				array(
					'post_title'   => $code,
					'post_name'    => strtolower( $code ),
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => sprintf(
						'<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->',
						esc_html( $code . ' — open this page in Elementor and build it however you like. Who can see it is controlled under Family Access > Member Access.' )
					),
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				$map[ $slot ] = (int) $id;
				$created++;
			}
		}

		self::save_map( $map );
		return $created;
	}

	/**
	 * A deleted page must not leave a stale ID in the map, otherwise a brand new
	 * page that happens to reuse the ID would silently inherit the protection.
	 */
	public static function forget_deleted_page( $post_id ) {
		$slot = self::slot_for_page( $post_id );
		if ( ! $slot ) {
			return;
		}
		$map          = self::map();
		$map[ $slot ] = 0;
		self::save_map( $map );
	}

	/**
	 * Every protected page ID, for quick lookups.
	 */
	public static function protected_ids() {
		return array_values( array_filter( self::map() ) );
	}
}
