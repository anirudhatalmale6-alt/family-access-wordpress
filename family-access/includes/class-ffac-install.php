<?php
/**
 * First-run setup. Creates the member role, the three key pages and the twelve
 * numbered pages, then points the settings at them. Running it again changes
 * nothing that already exists.
 */

defined( 'ABSPATH' ) || exit;

class FFAC_Install {

	public static function activate() {
		self::add_role();
		self::create_key_pages();
		FFAC_Pages::ensure_pages();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * A role that can do nothing at all except be logged in. Page access is
	 * handled by this plugin, not by capabilities, so the role stays this bare.
	 */
	protected static function add_role() {
		remove_role( FFAC_Registration::ROLE );
		add_role(
			FFAC_Registration::ROLE,
			'Family Member',
			array( 'read' => true )
		);
	}

	/**
	 * The landing/login/register/menu pages, each with its shortcode already on it.
	 */
	protected static function create_key_pages() {
		$settings = FFAC_Settings::all();

		$wanted = array(
			'login_page'    => array(
				'title'   => 'Login',
				'slug'    => 'login',
				'content' => '[family_login]',
			),
			'register_page' => array(
				'title'   => 'Register',
				'slug'    => 'register',
				'content' => '[family_register]',
			),
			'menu_page'     => array(
				'title'   => 'Members Menu',
				'slug'    => 'members-menu',
				'content' => "[family_greeting before=\"Welcome,\"] [family_logout]\n\n[family_menu columns=\"4\"]",
			),
		);

		foreach ( $wanted as $key => $spec ) {
			// Already configured and the page is still there? Leave it alone.
			if ( ! empty( $settings[ $key ] ) && get_post_status( $settings[ $key ] ) ) {
				continue;
			}

			$existing = get_page_by_path( $spec['slug'] );
			if ( $existing ) {
				$settings[ $key ] = (int) $existing->ID;
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_title'   => $spec['title'],
					'post_name'    => $spec['slug'],
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_content' => $spec['content'],
				)
			);

			if ( $id && ! is_wp_error( $id ) ) {
				$settings[ $key ] = (int) $id;
			}
		}

		// Also create the simple public pages if the site has none of them yet.
		foreach ( array( 'About' => 'about', 'Contact' => 'contact' ) as $title => $slug ) {
			if ( ! get_page_by_path( $slug ) ) {
				wp_insert_post(
					array(
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_content' => sprintf( '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->', esc_html( $title . ' — build this page in Elementor.' ) ),
					)
				);
			}
		}

		FFAC_Settings::save( $settings );
	}
}
