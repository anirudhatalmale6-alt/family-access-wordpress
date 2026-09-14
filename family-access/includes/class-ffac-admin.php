<?php
/**
 * The admin side: one screen to tick who can see what, one to say which page is
 * which number, one for the settings, and the written instructions.
 */

defined( 'ABSPATH' ) || exit;

class FFAC_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_forms' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		// The same tick boxes on the normal user edit screen.
		add_action( 'show_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_user_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_user_fields' ) );

		// A count bubble on the menu while people are waiting for approval.
		add_action( 'admin_notices', array( __CLASS__, 'pending_notice' ) );
	}

	public static function menu() {
		$pending = count( self::pending_users() );
		$title   = 'Family Access';
		if ( $pending ) {
			$title .= sprintf( ' <span class="update-plugins count-%1$d"><span class="plugin-count">%1$d</span></span>', $pending );
		}

		add_menu_page( 'Family Access', $title, self::CAP, 'ffac-members', array( __CLASS__, 'screen_members' ), 'dashicons-groups', 71 );
		add_submenu_page( 'ffac-members', 'Member Access', 'Member Access', self::CAP, 'ffac-members', array( __CLASS__, 'screen_members' ) );
		add_submenu_page( 'ffac-members', 'Numbered Pages', 'Numbered Pages', self::CAP, 'ffac-pages', array( __CLASS__, 'screen_pages' ) );
		add_submenu_page( 'ffac-members', 'Settings', 'Settings', self::CAP, 'ffac-settings', array( __CLASS__, 'screen_settings' ) );
		add_submenu_page( 'ffac-members', 'How to use this', 'How to use this', self::CAP, 'ffac-help', array( __CLASS__, 'screen_help' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'ffac-' ) && ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'ffac-admin', FFAC_URL . 'assets/ffac-admin.css', array(), FFAC_VERSION );
	}

	/* --------------------------------------------------------------- forms */

	public static function handle_forms() {
		if ( ! current_user_can( self::CAP ) || empty( $_POST['ffac_admin_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['ffac_admin_action'] ) );
		check_admin_referer( 'ffac_admin_' . $action );

		$post = wp_unslash( $_POST );

		switch ( $action ) {
			case 'save_access':
				$rows = isset( $post['ffac_user'] ) && is_array( $post['ffac_user'] ) ? $post['ffac_user'] : array();
				foreach ( $rows as $user_id => $row ) {
					$user_id = (int) $user_id;
					if ( ! $user_id || FFAC_Access::is_manager( $user_id ) ) {
						continue;
					}
					FFAC_Access::save_slots( $user_id, isset( $row['slots'] ) ? (array) $row['slots'] : array() );

					$status = isset( $row['status'] ) ? sanitize_key( $row['status'] ) : 'approved';
					if ( in_array( $status, array( 'pending', 'approved', 'blocked' ), true ) ) {
						update_user_meta( $user_id, FFAC_Registration::META_STATUS, $status );
					}
				}
				self::redirect( 'ffac-members', 'saved' );
				break;

			case 'save_pages':
				$map = isset( $post['ffac_map'] ) ? (array) $post['ffac_map'] : array();
				FFAC_Pages::save_map( $map );
				FFAC_Pages::save_titles( isset( $post['ffac_title'] ) ? (array) $post['ffac_title'] : array() );
				self::redirect( 'ffac-pages', 'saved' );
				break;

			case 'create_pages':
				$made = FFAC_Pages::ensure_pages();
				self::redirect( 'ffac-pages', 'created', array( 'n' => $made ) );
				break;

			case 'save_settings':
				FFAC_Settings::save(
					array(
						'menu_page'         => (int) ( $post['menu_page'] ?? 0 ),
						'login_page'        => (int) ( $post['login_page'] ?? 0 ),
						'register_page'     => (int) ( $post['register_page'] ?? 0 ),
						'idle_minutes'      => max( 5, min( 480, (int) ( $post['idle_minutes'] ?? 20 ) ) ),
						'ping_interval'     => max( 30, min( 600, (int) ( $post['ping_interval'] ?? 60 ) ) ),
						'logout_on_leave'   => empty( $post['logout_on_leave'] ) ? 0 : 1,
						'remember_username' => empty( $post['remember_username'] ) ? 0 : 1,
						'require_approval'  => empty( $post['require_approval'] ) ? 0 : 1,
						'captcha_mode'      => in_array( $post['captcha_mode'] ?? '', array( 'builtin', 'recaptcha', 'off' ), true ) ? $post['captcha_mode'] : 'builtin',
						'recaptcha_site'    => sanitize_text_field( $post['recaptcha_site'] ?? '' ),
						'recaptcha_secret'  => sanitize_text_field( $post['recaptcha_secret'] ?? '' ),
						'notify_email'      => sanitize_email( $post['notify_email'] ?? '' ),
						'denied_message'    => sanitize_text_field( $post['denied_message'] ?? '' ),
					)
				);
				self::redirect( 'ffac-settings', 'saved' );
				break;
		}
	}

	protected static function redirect( $page, $flag, array $extra = array() ) {
		$args = array_merge( array( 'page' => $page, 'ffac_msg' => $flag ), $extra );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	protected static function flash() {
		if ( empty( $_GET['ffac_msg'] ) ) {
			return;
		}
		$flag = sanitize_key( wp_unslash( $_GET['ffac_msg'] ) );
		$map  = array(
			'saved'   => 'Saved.',
			'created' => sprintf( '%d missing page(s) created.', isset( $_GET['n'] ) ? (int) $_GET['n'] : 0 ),
		);
		if ( isset( $map[ $flag ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $flag ] ) );
		}
	}

	/* ------------------------------------------------------------- screens */

	public static function screen_members() {
		$users = self::member_users();
		?>
		<div class="wrap ffac-wrap">
			<h1>Member Access</h1>
			<?php self::flash(); ?>

			<p class="ffac-lede">
				Tick the numbered pages each person is allowed to open. Anything not ticked is greyed out on
				their menu page and cannot be reached even by typing the address in directly.
			</p>

			<?php if ( ! $users ) : ?>
				<p>No members yet. People appear here as soon as they register.</p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'ffac_admin_save_access' ); ?>
					<input type="hidden" name="ffac_admin_action" value="save_access">

					<table class="widefat striped ffac-grid">
						<thead>
							<tr>
								<th class="ffac-col-person">Member</th>
								<th class="ffac-col-status">Status</th>
								<?php for ( $slot = 1; $slot <= FFAC_SLOTS; $slot++ ) : ?>
									<th class="ffac-col-slot" title="<?php echo esc_attr( FFAC_Pages::label( $slot ) ); ?>">
										<?php echo esc_html( sprintf( '%02d', $slot ) ); ?>
									</th>
								<?php endfor; ?>
								<th class="ffac-col-all">All</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $users as $user ) : ?>
							<?php
							$allowed = FFAC_Access::allowed_slots( $user->ID );
							$status  = get_user_meta( $user->ID, FFAC_Registration::META_STATUS, true );
							$status  = $status ? $status : 'approved';
							$profile = get_user_meta( $user->ID, FFAC_Registration::META_FIELDS, true );
							?>
							<tr>
								<td class="ffac-col-person">
									<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
									<span class="ffac-muted"><?php echo esc_html( $user->user_login ); ?> &middot; <?php echo esc_html( $user->user_email ); ?></span>
									<?php if ( ! empty( $profile['relationship'] ) ) : ?>
										<br><span class="ffac-muted"><?php echo esc_html( $profile['relationship'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="ffac-col-status">
									<select name="ffac_user[<?php echo (int) $user->ID; ?>][status]">
										<?php foreach ( array( 'approved' => 'Approved', 'pending' => 'Waiting', 'blocked' => 'Blocked' ) as $value => $label ) : ?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<?php for ( $slot = 1; $slot <= FFAC_SLOTS; $slot++ ) : ?>
									<td class="ffac-col-slot">
										<input type="checkbox"
											name="ffac_user[<?php echo (int) $user->ID; ?>][slots][]"
											value="<?php echo (int) $slot; ?>"
											<?php checked( in_array( $slot, $allowed, true ) ); ?>>
									</td>
								<?php endfor; ?>
								<td class="ffac-col-all">
									<button type="button" class="button-link ffac-toggle-row" data-row="<?php echo (int) $user->ID; ?>">tick all</button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<p><button type="submit" class="button button-primary">Save access</button></p>
				</form>

				<script>
				document.querySelectorAll('.ffac-toggle-row').forEach(function (btn) {
					btn.addEventListener('click', function () {
						var row = btn.closest('tr');
						var boxes = row.querySelectorAll('input[type=checkbox]');
						var anyOff = Array.prototype.some.call(boxes, function (b) { return !b.checked; });
						boxes.forEach(function (b) { b.checked = anyOff; });
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function screen_pages() {
		$map   = FFAC_Pages::map();
		$title = get_option( FFAC_Pages::TITLES, array() );
		$pages = get_pages( array( 'sort_column' => 'menu_order,post_title', 'post_status' => 'publish,draft,private' ) );
		?>
		<div class="wrap ffac-wrap">
			<h1>Numbered Pages</h1>
			<?php self::flash(); ?>

			<p class="ffac-lede">
				This is where Page-01 to Page-12 are pointed at real pages on the site. Change the page here and
				the permissions follow it — you never have to re-tick anybody.
			</p>

			<form method="post" style="margin-bottom:1.5em">
				<?php wp_nonce_field( 'ffac_admin_create_pages' ); ?>
				<input type="hidden" name="ffac_admin_action" value="create_pages">
				<button type="submit" class="button">Create any missing pages for me</button>
				<span class="ffac-muted">Makes a blank published page for every empty slot below. Safe to press more than once.</span>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'ffac_admin_save_pages' ); ?>
				<input type="hidden" name="ffac_admin_action" value="save_pages">

				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:110px">Menu item</th>
							<th>WordPress page</th>
							<th>Label on the tile <span class="ffac-muted">(optional)</span></th>
							<th style="width:120px">Edit</th>
						</tr>
					</thead>
					<tbody>
					<?php for ( $slot = 1; $slot <= FFAC_SLOTS; $slot++ ) : ?>
						<tr>
							<td><strong><?php echo esc_html( FFAC_Pages::code( $slot ) ); ?></strong></td>
							<td>
								<select name="ffac_map[<?php echo (int) $slot; ?>]">
									<option value="0">— not set —</option>
									<?php foreach ( $pages as $page ) : ?>
										<option value="<?php echo (int) $page->ID; ?>" <?php selected( $map[ $slot ], $page->ID ); ?>>
											<?php echo esc_html( $page->post_title ); ?><?php echo 'publish' === $page->post_status ? '' : ' (' . esc_html( $page->post_status ) . ')'; ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<input type="text" class="regular-text" name="ffac_title[<?php echo (int) $slot; ?>]"
									value="<?php echo esc_attr( $title[ $slot ] ?? '' ); ?>"
									placeholder="<?php echo esc_attr( FFAC_Pages::code( $slot ) ); ?>">
							</td>
							<td>
								<?php if ( $map[ $slot ] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $map[ $slot ] ) ); ?>">Edit page</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endfor; ?>
					</tbody>
				</table>

				<p><button type="submit" class="button button-primary">Save pages</button></p>
			</form>
		</div>
		<?php
	}

	public static function screen_settings() {
		$s     = FFAC_Settings::all();
		$pages = get_pages( array( 'sort_column' => 'menu_order,post_title' ) );
		?>
		<div class="wrap ffac-wrap">
			<h1>Family Access Settings</h1>
			<?php self::flash(); ?>

			<form method="post">
				<?php wp_nonce_field( 'ffac_admin_save_settings' ); ?>
				<input type="hidden" name="ffac_admin_action" value="save_settings">

				<h2 class="title">Key pages</h2>
				<table class="form-table" role="presentation">
					<?php
					foreach ( array(
						'menu_page'     => array( 'Menu page', 'Where members land after signing in. Put the [family_menu] shortcode on it.' ),
						'login_page'    => array( 'Login page', 'Put the [family_login] shortcode on it.' ),
						'register_page' => array( 'Register page', 'Put the [family_register] shortcode on it.' ),
					) as $key => $info ) :
						?>
						<tr>
							<th scope="row"><label for="ffac-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $info[0] ); ?></label></th>
							<td>
								<select id="ffac-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
									<option value="0">— not set —</option>
									<?php foreach ( $pages as $page ) : ?>
										<option value="<?php echo (int) $page->ID; ?>" <?php selected( $s[ $key ], $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html( $info[1] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2 class="title">Sessions</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffac-idle">Sign out after</label></th>
						<td>
							<input type="number" id="ffac-idle" name="idle_minutes" min="5" max="480" value="<?php echo (int) $s['idle_minutes']; ?>" class="small-text"> minutes of inactivity
							<p class="description">The safety net. 20 minutes suits most family sites.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Leaving the site</th>
						<td>
							<label><input type="checkbox" name="logout_on_leave" value="1" <?php checked( $s['logout_on_leave'] ); ?>>
								Sign the member out when they close the tab or go to another website</label>
							<p class="description">Moving between pages on this site does not sign anyone out.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffac-ping">Check-in interval</label></th>
						<td>
							<input type="number" id="ffac-ping" name="ping_interval" min="30" max="600" value="<?php echo (int) $s['ping_interval']; ?>" class="small-text"> seconds
							<p class="description">How often an open page tells the server it is still being used. Leave at 60 unless you have a reason.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Returning members</th>
						<td>
							<label><input type="checkbox" name="remember_username" value="1" <?php checked( $s['remember_username'] ); ?>>
								Remember the username and greet them by name</label>
							<p class="description">The password is never remembered and nobody is ever signed in automatically.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Registration</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">New accounts</th>
						<td>
							<label><input type="checkbox" name="require_approval" value="1" <?php checked( $s['require_approval'] ); ?>>
								Must be approved by me before they can sign in</label>
							<p class="description">Strongly recommended. Without it anyone who finds the register page can create a working account.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffac-captcha">Not-a-robot check</label></th>
						<td>
							<select id="ffac-captcha" name="captcha_mode">
								<option value="builtin" <?php selected( $s['captcha_mode'], 'builtin' ); ?>>Built in (tick box + simple sum) — no keys needed</option>
								<option value="recaptcha" <?php selected( $s['captcha_mode'], 'recaptcha' ); ?>>Google reCAPTCHA v2</option>
								<option value="off" <?php selected( $s['captcha_mode'], 'off' ); ?>>Off</option>
							</select>
							<p class="description">A hidden trap field and a minimum fill-in time are always on, whichever you choose.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ffac-rsite">reCAPTCHA site key</label></th>
						<td><input type="text" id="ffac-rsite" class="regular-text" name="recaptcha_site" value="<?php echo esc_attr( $s['recaptcha_site'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffac-rsecret">reCAPTCHA secret key</label></th>
						<td><input type="text" id="ffac-rsecret" class="regular-text" name="recaptcha_secret" value="<?php echo esc_attr( $s['recaptcha_secret'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ffac-notify">Send new-member alerts to</label></th>
						<td>
							<input type="email" id="ffac-notify" class="regular-text" name="notify_email" value="<?php echo esc_attr( $s['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						</td>
					</tr>
				</table>

				<h2 class="title">Wording</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ffac-denied">When a member opens a page they are not allowed</label></th>
						<td><input type="text" id="ffac-denied" class="large-text" name="denied_message" value="<?php echo esc_attr( $s['denied_message'] ); ?>"></td>
					</tr>
				</table>

				<p><button type="submit" class="button button-primary">Save settings</button></p>
			</form>
		</div>
		<?php
	}

	public static function screen_help() {
		include FFAC_DIR . 'views/help.php';
	}

	/* --------------------------------------------------------- user screen */

	public static function user_fields( $user ) {
		if ( ! current_user_can( self::CAP ) || FFAC_Access::is_manager( $user->ID ) ) {
			return;
		}

		$allowed = FFAC_Access::allowed_slots( $user->ID );
		$status  = get_user_meta( $user->ID, FFAC_Registration::META_STATUS, true );
		$status  = $status ? $status : 'approved';
		?>
		<h2>Family Access</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Account status</th>
				<td>
					<select name="ffac_status">
						<?php foreach ( array( 'approved' => 'Approved', 'pending' => 'Waiting for approval', 'blocked' => 'Blocked' ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">Pages this member may open</th>
				<td>
					<?php wp_nonce_field( 'ffac_user_fields', 'ffac_user_nonce' ); ?>
					<fieldset class="ffac-user-slots">
						<?php for ( $slot = 1; $slot <= FFAC_SLOTS; $slot++ ) : ?>
							<label>
								<input type="checkbox" name="ffac_slots[]" value="<?php echo (int) $slot; ?>" <?php checked( in_array( $slot, $allowed, true ) ); ?>>
								<?php echo esc_html( FFAC_Pages::code( $slot ) ); ?>
								<span class="ffac-muted"><?php echo esc_html( FFAC_Pages::label( $slot ) ); ?></span>
							</label>
						<?php endfor; ?>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_user_fields( $user_id ) {
		if ( ! current_user_can( self::CAP ) || FFAC_Access::is_manager( $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['ffac_user_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ffac_user_nonce'] ) ), 'ffac_user_fields' ) ) {
			return;
		}

		FFAC_Access::save_slots( $user_id, isset( $_POST['ffac_slots'] ) ? (array) wp_unslash( $_POST['ffac_slots'] ) : array() );

		$status = isset( $_POST['ffac_status'] ) ? sanitize_key( wp_unslash( $_POST['ffac_status'] ) ) : '';
		if ( in_array( $status, array( 'pending', 'approved', 'blocked' ), true ) ) {
			update_user_meta( $user_id, FFAC_Registration::META_STATUS, $status );
		}
	}

	/* ---------------------------------------------------------------- misc */

	public static function member_users() {
		return get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 500,
				'exclude' => self::manager_ids(),
			)
		);
	}

	protected static function manager_ids() {
		$ids = array();
		foreach ( get_users( array( 'capability' => 'edit_pages', 'number' => 200, 'fields' => 'ID' ) ) as $id ) {
			$ids[] = (int) $id;
		}
		return $ids;
	}

	public static function pending_users() {
		return get_users(
			array(
				'meta_key'   => FFAC_Registration::META_STATUS,
				'meta_value' => 'pending',
				'number'     => 100,
				'fields'     => 'ID',
			)
		);
	}

	public static function pending_notice() {
		$screen = get_current_screen();
		if ( ! $screen || false !== strpos( $screen->id, 'ffac-members' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$pending = count( self::pending_users() );
		if ( ! $pending ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">Review them now</a>.</p></div>',
			esc_html( sprintf( _n( '%d person is waiting to be approved on the family site.', '%d people are waiting to be approved on the family site.', $pending, 'family-access' ), $pending ) ),
			esc_url( admin_url( 'admin.php?page=ffac-members' ) )
		);
	}
}
