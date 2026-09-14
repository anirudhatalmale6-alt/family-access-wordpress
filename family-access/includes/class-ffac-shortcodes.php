<?php
/**
 * Everything the visitor sees is a shortcode, so each page stays an ordinary
 * Elementor page: drop the shortcode widget where you want it, style the rest
 * of the page freely, and nothing here fights the builder.
 *
 *   [family_login]     the sign-in form
 *   [family_register]  the sign-up form with the not-a-robot check
 *   [family_menu]      the 12 numbered tiles, greyed out where not allowed
 *   [family_logout]    a sign-out link or button
 *   [family_greeting]  "Welcome back, Jane" — handy in a header
 */

defined( 'ABSPATH' ) || exit;

class FFAC_Shortcodes {

	public static function init() {
		add_shortcode( 'family_login', array( __CLASS__, 'login_form' ) );
		add_shortcode( 'family_register', array( __CLASS__, 'register_form' ) );
		add_shortcode( 'family_menu', array( __CLASS__, 'menu_grid' ) );
		add_shortcode( 'family_logout', array( __CLASS__, 'logout_link' ) );
		add_shortcode( 'family_greeting', array( __CLASS__, 'greeting' ) );
	}

	/**
	 * Notices driven by the ?ffac= flag the redirects add.
	 */
	protected static function notice() {
		$flag = isset( $_GET['ffac'] ) ? sanitize_key( wp_unslash( $_GET['ffac'] ) ) : '';
		if ( ! $flag ) {
			return '';
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		$messages = array(
			'login_required'     => array( 'info', 'Please sign in to open that page.' ),
			'denied'             => array( 'warn', $code
				? sprintf( '%s — %s', $code, FFAC_Settings::get( 'denied_message' ) )
				: FFAC_Settings::get( 'denied_message' ) ),
			'timeout'            => array( 'warn', 'You were signed out because the page was left idle. Please sign in again.' ),
			'left'               => array( 'info', 'You were signed out when you left the site. Please sign in again.' ),
			'logged_out'         => array( 'ok', 'You have been signed out.' ),
			'registered'         => array( 'ok', 'Your account has been created. You can sign in below.' ),
			'registered_pending' => array( 'ok', 'Thank you. Your details have been sent to the site owner. You will be able to sign in as soon as your account is approved.' ),
		);

		if ( ! isset( $messages[ $flag ] ) ) {
			return '';
		}

		list( $type, $text ) = $messages[ $flag ];
		return sprintf( '<div class="ffac-notice ffac-notice--%s">%s</div>', esc_attr( $type ), esc_html( $text ) );
	}

	/* ---------------------------------------------------------------- login */

	public static function login_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'  => '', // The theme already prints the page title; pass one in if you want a second heading.
				'button' => 'Sign in',
			),
			$atts,
			'family_login'
		);

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			return sprintf(
				'<div class="ffac-card ffac-card--signedin"><p>You are signed in as <strong>%s</strong>.</p><p><a class="ffac-btn" href="%s">Go to the menu</a> <a class="ffac-btn ffac-btn--quiet" href="%s">Sign out</a></p></div>',
				esc_html( $user->display_name ),
				esc_url( FFAC_Settings::page_url( 'menu_page' ) ),
				esc_url( FFAC_Auth::logout_url() )
			);
		}

		$known    = FFAC_Session::known_username();
		$error    = FFAC_Auth::take_error();
		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['redirect_to'] ) ) ) : '';

		ob_start();
		?>
		<div class="ffac-card ffac-login">
			<?php if ( $atts['title'] ) : ?>
				<h2 class="ffac-card__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>

			<?php echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<?php if ( $known ) : ?>
				<p class="ffac-welcome">Welcome back, <strong><?php echo esc_html( self::friendly_name( $known ) ); ?></strong>. Please enter your password.</p>
			<?php endif; ?>

			<?php if ( $error ) : ?>
				<div class="ffac-notice ffac-notice--error"><?php echo esc_html( $error ); ?></div>
			<?php endif; ?>

			<form method="post" action="" class="ffac-form" novalidate>
				<?php wp_nonce_field( 'ffac_login', 'ffac_login_nonce' ); ?>
				<input type="hidden" name="ffac_login" value="1">
				<input type="hidden" name="ffac_ft" value="<?php echo esc_attr( FFAC_Registration::new_token() ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">

				<p class="ffac-field">
					<label for="ffac-login-username">Username</label>
					<input type="text" id="ffac-login-username" name="ffac_username" autocomplete="username"
						value="<?php echo esc_attr( $known ); ?>" required>
				</p>

				<p class="ffac-field">
					<label for="ffac-login-password">Password</label>
					<input type="password" id="ffac-login-password" name="ffac_password" autocomplete="current-password" required>
				</p>

				<p class="ffac-note">You will be signed out automatically when you leave the site or close your browser.</p>

				<p class="ffac-actions">
					<button type="submit" class="ffac-btn"><?php echo esc_html( $atts['button'] ); ?></button>
					<a class="ffac-link" href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Forgotten your password?</a>
				</p>

				<?php $register = FFAC_Settings::get( 'register_page' ); ?>
				<?php if ( $register ) : ?>
					<p class="ffac-alt">No account yet? <a href="<?php echo esc_url( get_permalink( $register ) ); ?>">Register here</a>.</p>
				<?php endif; ?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * First name of a remembered username, falling back to the username itself.
	 */
	protected static function friendly_name( $login ) {
		$user = get_user_by( 'login', $login );
		if ( $user && $user->first_name ) {
			return $user->first_name;
		}
		return $login;
	}

	/* ------------------------------------------------------------- register */

	public static function register_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'  => '',
				'button' => 'Create my account',
			),
			$atts,
			'family_register'
		);

		if ( is_user_logged_in() ) {
			return '<div class="ffac-card"><p>You already have an account and are signed in.</p></div>';
		}

		$flash  = FFAC_Registration::take_flash();
		$values = $flash['values'];
		$errors = $flash['errors'];
		$mode   = FFAC_Settings::get( 'captcha_mode', 'builtin' );

		// The built-in check: a small sum whose answer never appears in the HTML.
		$a   = wp_rand( 2, 9 );
		$b   = wp_rand( 2, 9 );
		$sum = FFAC_Registration::sum_hash( $a + $b );

		ob_start();
		?>
		<div class="ffac-card ffac-register">
			<?php if ( $atts['title'] ) : ?>
				<h2 class="ffac-card__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>

			<?php if ( $errors ) : ?>
				<div class="ffac-notice ffac-notice--error">
					<ul><?php foreach ( $errors as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?></ul>
				</div>
			<?php endif; ?>

			<form method="post" action="" class="ffac-form" novalidate>
				<?php wp_nonce_field( 'ffac_register', 'ffac_register_nonce' ); ?>
				<input type="hidden" name="ffac_register" value="1">
				<input type="hidden" name="ffac_ft" value="<?php echo esc_attr( FFAC_Registration::new_token() ); ?>">
				<input type="hidden" name="ffac_started" value="<?php echo esc_attr( time() ); ?>">

				<?php // Honeypot. Hidden from people, irresistible to bots. ?>
				<div class="ffac-hp" aria-hidden="true">
					<label>Website<input type="text" name="ffac_website" tabindex="-1" autocomplete="off"></label>
				</div>

				<div class="ffac-grid-2">
					<?php foreach ( FFAC_Registration::fields() as $key => $field ) : ?>
						<?php $wide = 'textarea' === $field['type'] || 'relationship' === $key; ?>
						<p class="ffac-field <?php echo $wide ? 'ffac-field--wide' : ''; ?>">
							<label for="ffac-<?php echo esc_attr( $key ); ?>">
								<?php echo esc_html( $field['label'] ); ?>
								<?php if ( $field['required'] ) : ?><span class="ffac-req">*</span><?php endif; ?>
							</label>
							<?php if ( 'textarea' === $field['type'] ) : ?>
								<textarea id="ffac-<?php echo esc_attr( $key ); ?>" name="ffac_<?php echo esc_attr( $key ); ?>" rows="3"><?php echo esc_textarea( $values[ $key ] ?? '' ); ?></textarea>
							<?php else : ?>
								<input type="<?php echo esc_attr( $field['type'] ); ?>"
									id="ffac-<?php echo esc_attr( $key ); ?>"
									name="ffac_<?php echo esc_attr( $key ); ?>"
									value="<?php echo esc_attr( $values[ $key ] ?? '' ); ?>"
									<?php if ( ! empty( $field['autocomplete'] ) ) : ?>autocomplete="<?php echo esc_attr( $field['autocomplete'] ); ?>"<?php endif; ?>
									<?php if ( ! empty( $field['placeholder'] ) ) : ?>placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"<?php endif; ?>
									<?php echo $field['required'] ? 'required' : ''; ?>>
							<?php endif; ?>
						</p>
					<?php endforeach; ?>

					<p class="ffac-field">
						<label for="ffac-username">Choose a username <span class="ffac-req">*</span></label>
						<input type="text" id="ffac-username" name="ffac_username" autocomplete="username"
							value="<?php echo esc_attr( $values['username'] ?? '' ); ?>" required>
					</p>

					<p class="ffac-field">
						<label for="ffac-email">Email address <span class="ffac-req">*</span></label>
						<input type="email" id="ffac-email" name="ffac_email" autocomplete="email"
							value="<?php echo esc_attr( $values['email'] ?? '' ); ?>" required>
					</p>

					<p class="ffac-field">
						<label for="ffac-password">Password <span class="ffac-req">*</span></label>
						<input type="password" id="ffac-password" name="ffac_password" autocomplete="new-password" required>
						<span class="ffac-hint">At least 8 characters.</span>
					</p>

					<p class="ffac-field">
						<label for="ffac-password2">Repeat password <span class="ffac-req">*</span></label>
						<input type="password" id="ffac-password2" name="ffac_password2" autocomplete="new-password" required>
					</p>
				</div>

				<?php if ( 'recaptcha' === $mode && FFAC_Settings::get( 'recaptcha_site' ) ) : ?>
					<div class="ffac-captcha">
						<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( FFAC_Settings::get( 'recaptcha_site' ) ); ?>"></div>
					</div>
					<script src="https://www.google.com/recaptcha/api.js" async defer></script>
				<?php elseif ( 'off' !== $mode ) : ?>
					<div class="ffac-captcha">
						<label class="ffac-check">
							<input type="checkbox" name="ffac_human" value="1"> I am not a robot
						</label>
						<p class="ffac-field ffac-field--sum">
							<label for="ffac-sum">And to be sure — what is <?php echo (int) $a; ?> + <?php echo (int) $b; ?>?</label>
							<input type="text" id="ffac-sum" name="ffac_sum" inputmode="numeric" autocomplete="off" required>
							<input type="hidden" name="ffac_sum_hash" value="<?php echo esc_attr( $sum ); ?>">
						</p>
					</div>
				<?php endif; ?>

				<p class="ffac-actions">
					<button type="submit" class="ffac-btn"><?php echo esc_html( $atts['button'] ); ?></button>
				</p>

				<?php if ( FFAC_Settings::get( 'require_approval' ) ) : ?>
					<p class="ffac-note">Every new account is checked by hand before it is switched on, so there may be a short wait before you can sign in.</p>
				<?php endif; ?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ----------------------------------------------------------- menu grid */

	public static function menu_grid( $atts ) {
		$atts = shortcode_atts(
			array(
				'columns' => 4,
				'title'   => '',
			),
			$atts,
			'family_menu'
		);

		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="ffac-card"><p>Please <a href="%s">sign in</a> to see your pages.</p></div>',
				esc_url( FFAC_Settings::page_url( 'login_page', wp_login_url() ) )
			);
		}

		$user    = wp_get_current_user();
		$allowed = FFAC_Access::allowed_slots( $user->ID );
		$columns = max( 1, min( 6, (int) $atts['columns'] ) );

		ob_start();
		?>
		<div class="ffac-menu" style="--ffac-cols: <?php echo (int) $columns; ?>">
			<?php if ( $atts['title'] ) : ?>
				<h2 class="ffac-menu__title"><?php echo esc_html( $atts['title'] ); ?></h2>
			<?php endif; ?>

			<?php echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<?php if ( ! $allowed ) : ?>
				<div class="ffac-notice ffac-notice--info">
					No pages have been unlocked for your account yet. The site owner will switch them on for you.
				</div>
			<?php endif; ?>

			<div class="ffac-menu__grid">
				<?php for ( $slot = 1; $slot <= FFAC_SLOTS; $slot++ ) : ?>
					<?php
					$code    = FFAC_Pages::code( $slot );
					$label   = FFAC_Pages::label( $slot );
					$page_id = FFAC_Pages::page_id( $slot );
					$open    = in_array( $slot, $allowed, true ) && $page_id && 'publish' === get_post_status( $page_id );
					// Only show a second line when it says something the code does not.
					$show_label = $label && $label !== $code;
					?>
					<?php if ( $open ) : ?>
						<a class="ffac-tile ffac-tile--open" href="<?php echo esc_url( get_permalink( $page_id ) ); ?>">
							<span class="ffac-tile__code"><?php echo esc_html( $code ); ?></span>
							<?php if ( $show_label ) : ?>
								<span class="ffac-tile__label"><?php echo esc_html( $label ); ?></span>
							<?php endif; ?>
						</a>
					<?php else : ?>
						<span class="ffac-tile ffac-tile--locked" aria-disabled="true" role="link" tabindex="-1"
							title="This page is not available to your account">
							<span class="ffac-tile__code"><?php echo esc_html( $code ); ?></span>
							<?php if ( $show_label ) : ?>
								<span class="ffac-tile__label"><?php echo esc_html( $label ); ?></span>
							<?php endif; ?>
						</span>
					<?php endif; ?>
				<?php endfor; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------- small */

	public static function logout_link( $atts ) {
		$atts = shortcode_atts( array( 'text' => 'Sign out', 'class' => 'ffac-btn ffac-btn--quiet' ), $atts, 'family_logout' );

		if ( ! is_user_logged_in() ) {
			return '';
		}

		return sprintf(
			'<a class="%s" href="%s">%s</a>',
			esc_attr( $atts['class'] ),
			esc_url( FFAC_Auth::logout_url() ),
			esc_html( $atts['text'] )
		);
	}

	public static function greeting( $atts ) {
		$atts = shortcode_atts( array( 'before' => 'Welcome,', 'after' => '' ), $atts, 'family_greeting' );

		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user = wp_get_current_user();
		$name = $user->first_name ? $user->first_name : $user->display_name;

		return sprintf(
			'<span class="ffac-greeting">%s <strong>%s</strong>%s</span>',
			esc_html( $atts['before'] ),
			esc_html( $name ),
			esc_html( $atts['after'] )
		);
	}
}
