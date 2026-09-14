<?php
/**
 * The instructions, written for the site owner and living inside the dashboard
 * so they cannot be lost. The same text is in INSTRUCTIONS.md in the plugin folder.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap ffac-wrap ffac-help">
	<h1>How to use Family Access</h1>

	<p class="ffac-lede">
		Everything on this page is about who gets to see what. The look of the site stays
		entirely yours — every page here is a normal WordPress page you can open in Elementor.
	</p>

	<h2>1. Letting a new person in</h2>
	<ol>
		<li>They fill in the <strong>Register</strong> page on the site.</li>
		<li>You get an email, and a counter appears next to <strong>Family Access</strong> in this menu.</li>
		<li>Open <a href="<?php echo esc_url( admin_url( 'admin.php?page=ffac-members' ) ); ?>">Member Access</a>. They will be in the list with the status <em>Waiting</em>.</li>
		<li>Tick the numbered pages you want them to see, set the status to <strong>Approved</strong>, and press <strong>Save access</strong>.</li>
	</ol>
	<p>Until you do that they cannot sign in at all, and even once approved they only see what you ticked.</p>

	<h2>2. Changing what somebody can see</h2>
	<p>
		Same screen. Tick or untick, press Save. The change is live immediately — if they are signed in
		at that moment, the tile greys out the next time their menu page loads, and the page itself stops
		opening straight away.
	</p>
	<p>
		The <strong>tick all</strong> button on the right of each row turns the whole row on or off in one go.
	</p>

	<h2>3. Turning somebody off</h2>
	<p>Set their status to <strong>Blocked</strong>. They keep their account but cannot sign in. That is nearly always better than deleting them, because deleting loses their details for good.</p>

	<h2>4. The twelve numbered pages</h2>
	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ffac-pages' ) ); ?>">Numbered Pages</a> is where
		<code>Page-01</code> to <code>Page-12</code> are pointed at real pages.
	</p>
	<ul>
		<li>To rebuild one, just open it in Elementor and design it. Nothing here needs changing.</li>
		<li>To use a different page for a number, pick it from the dropdown and save. Everyone's permissions follow the number, not the page, so nobody needs re-ticking.</li>
		<li>The <strong>Label on the tile</strong> box is what appears under the number on the menu. Leave it empty and the page's own title is used.</li>
	</ul>

	<h2>5. The menu page</h2>
	<p>
		This is where members land after signing in. It holds the shortcode <code>[family_menu]</code>, which
		draws the twelve tiles. Allowed pages are solid and clickable; everything else is greyed out, dashed,
		and does nothing at all when clicked.
	</p>
	<p>Useful extras you can drop anywhere in Elementor with a Shortcode widget:</p>
	<ul>
		<li><code>[family_menu columns="3"]</code> — the tiles, in however many columns you like (1 to 6).</li>
		<li><code>[family_greeting]</code> — "Welcome, Jane".</li>
		<li><code>[family_logout]</code> — a sign-out button.</li>
		<li><code>[family_login]</code> and <code>[family_register]</code> — the two forms.</li>
	</ul>

	<h2>6. Signing out</h2>
	<p>Members are signed out in three ways, all of which you control under <a href="<?php echo esc_url( admin_url( 'admin.php?page=ffac-settings' ) ); ?>">Settings</a>:</p>
	<ul>
		<li><strong>Leaving the site</strong> — closing the tab or going to another website ends the session. Clicking between pages of this site does not.</li>
		<li><strong>Going idle</strong> — no activity for the number of minutes you set.</li>
		<li><strong>Closing the browser</strong> — the sign-in is a session cookie, so it never survives a browser restart.</li>
	</ul>
	<p>
		A returning member is greeted by name and their username is filled in for them, but they are
		<strong>never</strong> signed in automatically — the password is always asked for.
	</p>

	<h2>7. The not-a-robot check</h2>
	<p>Out of the box the register page uses a tick box and a one-line sum, which needs no keys and no account anywhere. If you would rather use Google reCAPTCHA:</p>
	<ol>
		<li>Get a v2 "I'm not a robot" site key and secret key from Google.</li>
		<li>Paste both into <a href="<?php echo esc_url( admin_url( 'admin.php?page=ffac-settings' ) ); ?>">Settings</a> and switch the check to reCAPTCHA.</li>
	</ol>
	<p>A hidden trap field and a minimum fill-in time run either way, and catch most automated sign-ups on their own.</p>

	<h2>8. Things worth knowing</h2>
	<div class="ffac-callout">
		<ul>
			<li>Administrators and editors always see all twelve pages. You cannot lock yourself out.</li>
			<li>Members cannot reach the WordPress dashboard; if they try, they are sent back to their menu.</li>
			<li>A page a member is not allowed to open is also kept out of the site search and out of any menu.</li>
			<li>Five wrong passwords pauses that username for fifteen minutes.</li>
			<li>If you delete one of the twelve pages, its slot empties rather than quietly pointing at whatever comes next.</li>
		</ul>
	</div>
</div>
