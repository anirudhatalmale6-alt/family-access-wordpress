<?php
/**
 * Registration: the form fields, the not-a-robot check and the approval queue.
 *
 * New members are created with the "Family Member" role and, by default, with
 * no pages unlocked at all and a pending flag. Nobody gets in until the site
 * owner ticks the boxes for them. That is what keeps a family site off the
 * open internet even though the registration form is public.
 */

defined( 'ABSPATH' ) || exit;

class FFAC_Registration {

	const ROLE        = 'ffac_member';
	const META_STATUS = 'ffac_status';   // 'pending' | 'approved' | 'blocked'
	const META_FIELDS = 'ffac_profile';  // the extra sign-up answers

	public static function init() {
		add_action( 'init', array( __CLASS__, 'handle_submit' ) );

		// A pending or blocked member cannot log in, whatever password they type.
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_unapproved' ), 10, 2 );

		// Members have no business in the dashboard.
		add_action( 'admin_init', array( __CLASS__, 'keep_members_out_of_admin' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
	}

	/**
	 * The extra fields asked for at sign-up, beyond username/email/password.
	 * Kept in one place so the admin instructions and the form never drift apart.
	 */
	public static function fields() {
		return array(
			'first_name'   => array( 'label' => 'First name', 'type' => 'text', 'required' => true, 'autocomplete' => 'given-name' ),
			'last_name'    => array( 'label' => 'Last name', 'type' => 'text', 'required' => true, 'autocomplete' => 'family-name' ),
			'phone'        => array( 'label' => 'Phone number', 'type' => 'tel', 'required' => false, 'autocomplete' => 'tel' ),
			'town'         => array( 'label' => 'Town / city', 'type' => 'text', 'required' => false, 'autocomplete' => 'address-level2' ),
			'country'      => array( 'label' => 'Country', 'type' => 'text', 'required' => false, 'autocomplete' => 'country-name' ),
			'relationship' => array( 'label' => 'How are you connected to the family?', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. cousin, family friend, in-law' ),
			'note'         => array( 'label' => 'Anything you would like to add', 'type' => 'textarea', 'required' => false ),
		);
	}

	/**
	 * Process a submitted registration form.
	 */
	public static function handle_submit() {
		if ( empty( $_POST['ffac_register'] ) ) {
			return;
		}

		$errors   = array();
		$settings = FFAC_Settings::all();
		$post     = wp_unslash( $_POST );

		if ( ! isset( $post['ffac_register_nonce'] ) || ! wp_verify_nonce( $post['ffac_register_nonce'], 'ffac_register' ) ) {
			$errors[] = 'Your form session had expired. Please fill the form in again.';
			self::bounce( $errors, $post );
		}

		// --- the not-a-robot checks -------------------------------------------------
		$errors = array_merge( $errors, self::check_human( $post ) );

		// --- the account fields -----------------------------------------------------
		$username = sanitize_user( $post['ffac_username'] ?? '', true );
		$email    = sanitize_email( $post['ffac_email'] ?? '' );
		$pass     = (string) ( $post['ffac_password'] ?? '' );
		$pass2    = (string) ( $post['ffac_password2'] ?? '' );

		if ( strlen( $username ) < 3 ) {
			$errors[] = 'Please choose a username of at least 3 characters.';
		} elseif ( ! validate_username( $username ) ) {
			$errors[] = 'That username contains characters that are not allowed.';
		} elseif ( username_exists( $username ) ) {
			$errors[] = 'That username is already taken.';
		}

		if ( ! is_email( $email ) ) {
			$errors[] = 'Please enter a valid email address.';
		} elseif ( email_exists( $email ) ) {
			$errors[] = 'There is already an account using that email address.';
		}

		if ( strlen( $pass ) < 8 ) {
			$errors[] = 'Please use a password of at least 8 characters.';
		}
		if ( $pass !== $pass2 ) {
			$errors[] = 'The two passwords do not match.';
		}

		$profile = array();
		foreach ( self::fields() as $key => $field ) {
			$value = trim( (string) ( $post[ 'ffac_' . $key ] ?? '' ) );
			$value = 'textarea' === $field['type'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			if ( $field['required'] && '' === $value ) {
				$errors[] = sprintf( 'Please fill in: %s.', $field['label'] );
			}
			$profile[ $key ] = $value;
		}

		if ( $errors ) {
			self::bounce( $errors, $post );
		}

		// --- create the account -----------------------------------------------------
		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $pass,
				'first_name'   => $profile['first_name'],
				'last_name'    => $profile['last_name'],
				'display_name' => trim( $profile['first_name'] . ' ' . $profile['last_name'] ),
				'role'         => self::ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::bounce( array( $user_id->get_error_message() ), $post );
		}

		update_user_meta( $user_id, self::META_FIELDS, $profile );
		update_user_meta( $user_id, self::META_STATUS, $settings['require_approval'] ? 'pending' : 'approved' );
		FFAC_Access::save_slots( $user_id, array() ); // No pages until the admin says so.

		self::notify_admin( $user_id, $profile );

		$target = add_query_arg(
			'ffac',
			$settings['require_approval'] ? 'registered_pending' : 'registered',
			FFAC_Settings::page_url( 'login_page', wp_login_url() )
		);
		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * Three layers, none of which asks a real person to read squiggly text:
	 * a tick box, a honeypot field no human can see, and a minimum fill-in time.
	 * Google reCAPTCHA v2 replaces the tick box if the owner adds his keys.
	 */
	protected static function check_human( array $post ) {
		$errors = array();
		$mode   = FFAC_Settings::get( 'captcha_mode', 'builtin' );

		// Honeypot: bots fill in every field they find.
		if ( ! empty( $post['ffac_website'] ) ) {
			$errors[] = 'Your submission looked automated. Please try again.';
		}

		// Timing: a form filled in under four seconds was not typed by a person.
		$started = (int) ( $post['ffac_started'] ?? 0 );
		if ( $started && ( time() - $started ) < 4 ) {
			$errors[] = 'That was submitted very quickly. Please take a moment and send it again.';
		}

		if ( 'off' === $mode ) {
			return $errors;
		}

		if ( 'recaptcha' === $mode ) {
			$secret = FFAC_Settings::get( 'recaptcha_secret' );
			$token  = $post['g-recaptcha-response'] ?? '';

			if ( ! $secret ) {
				return $errors; // Misconfigured: do not lock real people out.
			}
			if ( ! $token ) {
				$errors[] = 'Please complete the "I am not a robot" check.';
				return $errors;
			}

			$response = wp_remote_post(
				'https://www.google.com/recaptcha/api/siteverify',
				array(
					'timeout' => 10,
					'body'    => array(
						'secret'   => $secret,
						'response' => $token,
						'remoteip' => self::client_ip(),
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$errors[] = 'The robot check could not be reached. Please try once more.';
				return $errors;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $body['success'] ) ) {
				$errors[] = 'The "I am not a robot" check did not pass. Please try again.';
			}
			return $errors;
		}

		// Built-in check: the tick box plus a one-line sum.
		if ( empty( $post['ffac_human'] ) ) {
			$errors[] = 'Please tick the "I am not a robot" box.';
		}

		$expected = (string) ( $post['ffac_sum_hash'] ?? '' );
		$given    = trim( (string) ( $post['ffac_sum'] ?? '' ) );
		if ( ! $expected || ! hash_equals( $expected, self::sum_hash( $given ) ) ) {
			$errors[] = 'The answer to the simple sum was not correct.';
		}

		return $errors;
	}

	/**
	 * Hash of the expected answer, salted with the site's own keys, so the answer
	 * travels through the form without ever being readable in the page source.
	 */
	public static function sum_hash( $answer ) {
		return hash_hmac( 'sha256', trim( (string) $answer ), wp_salt( 'ffac_sum' ) );
	}

	protected static function client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Send the form back to the register page with the errors and the typed
	 * values preserved, so nobody has to type it all again.
	 */
	protected static function bounce( array $errors, array $post ) {
		$keep = array();
		foreach ( array_merge( array( 'username', 'email' ), array_keys( self::fields() ) ) as $key ) {
			if ( isset( $post[ 'ffac_' . $key ] ) ) {
				$keep[ $key ] = sanitize_text_field( $post[ 'ffac_' . $key ] );
			}
		}

		$token = self::token_from_post( $post );
		set_transient( 'ffac_form_' . $token, array( 'errors' => $errors, 'values' => $keep ), 10 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array( 'ffac' => 'error', 'ft' => $token ),
			FFAC_Settings::page_url( 'register_page', home_url( '/' ) )
		);
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * A one-shot ticket that carries "what you just typed" through the redirect.
	 *
	 * The form puts a random token in a hidden field; the failure redirect hands
	 * it back in the URL and it is destroyed the moment it is read. Tying it to
	 * the browser this way — rather than to an IP address — keeps two people on
	 * the same home broadband connection from ever seeing each other's form.
	 */
	public static function new_token() {
		return wp_generate_password( 20, false, false );
	}

	public static function token_from_post( array $post ) {
		$token = isset( $post['ffac_ft'] ) ? sanitize_key( $post['ffac_ft'] ) : '';
		return $token ? $token : self::new_token();
	}

	public static function token_from_url() {
		return isset( $_GET['ft'] ) ? sanitize_key( wp_unslash( $_GET['ft'] ) ) : '';
	}

	/**
	 * Pull back anything the last failed submission left behind.
	 */
	public static function take_flash() {
		$empty = array( 'errors' => array(), 'values' => array() );

		$token = self::token_from_url();
		if ( ! $token ) {
			return $empty;
		}

		$flash = get_transient( 'ffac_form_' . $token );
		delete_transient( 'ffac_form_' . $token );

		return is_array( $flash ) ? $flash : $empty;
	}

	/**
	 * Tell the site owner somebody is waiting to be let in.
	 */
	protected static function notify_admin( $user_id, array $profile ) {
		$to = FFAC_Settings::get( 'notify_email' );
		if ( ! $to || ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}

		$user  = get_userdata( $user_id );
		$lines = array(
			'A new person has registered on ' . get_bloginfo( 'name' ) . '.',
			'',
			'Name:     ' . $user->display_name,
			'Username: ' . $user->user_login,
			'Email:    ' . $user->user_email,
		);
		foreach ( self::fields() as $key => $field ) {
			if ( in_array( $key, array( 'first_name', 'last_name' ), true ) ) {
				continue;
			}
			if ( ! empty( $profile[ $key ] ) ) {
				$lines[] = $field['label'] . ': ' . $profile[ $key ];
			}
		}
		$lines[] = '';
		$lines[] = 'They cannot see anything yet. Approve them and tick the pages they may open here:';
		$lines[] = admin_url( 'admin.php?page=ffac-members' );

		wp_mail(
			$to,
			sprintf( '[%s] New member waiting for approval: %s', get_bloginfo( 'name' ), $user->display_name ),
			implode( "\n", $lines )
		);
	}

	/**
	 * Refuse the login of anyone who has not been approved.
	 */
	public static function block_unapproved( $user, $password ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $user;
		}
		if ( ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			return $user; // Staff accounts are not part of the approval queue.
		}

		$status = get_user_meta( $user->ID, self::META_STATUS, true );

		if ( 'blocked' === $status ) {
			return new WP_Error( 'ffac_blocked', 'This account has been suspended. Please contact the site owner.' );
		}
		if ( 'pending' === $status ) {
			return new WP_Error( 'ffac_pending', 'Your account is waiting to be approved by the site owner. You will be able to sign in once that is done.' );
		}

		return $user;
	}

	/**
	 * Members typing /wp-admin/ get sent back to their menu page.
	 */
	public static function keep_members_out_of_admin() {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		if ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( FFAC_Settings::page_url( 'menu_page' ), 302 );
		exit;
	}

	public static function hide_admin_bar( $show ) {
		if ( is_user_logged_in() && ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return $show;
	}
}
