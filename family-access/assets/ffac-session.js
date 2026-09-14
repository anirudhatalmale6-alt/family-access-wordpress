/*
 * Session watchdog.
 *
 * Two jobs:
 *   1. Tell the server this tab is still being used, so the idle timer does not fire.
 *   2. Tell the server the moment the member leaves the site, so they are signed
 *      out. Moving between pages of this site is not leaving.
 *
 * The rule for "leaving": we assume every unload is a departure, unless we saw
 * the member start a navigation that stays on this site (a click on one of our
 * own links, or a form being submitted) a moment earlier.
 */
(function () {
	'use strict';

	if (typeof window.ffacSession === 'undefined') {
		return;
	}

	var cfg = window.ffacSession;
	var internalNav = false;
	var internalNavTimer = null;
	var beaconSent = false;

	function post(action, useBeacon) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce);

		if (useBeacon && navigator.sendBeacon) {
			navigator.sendBeacon(cfg.ajaxUrl, body);
			return;
		}

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
			body: body.toString()
		}).catch(function () { /* offline is not an error worth shouting about */ });
	}

	/* ------------------------------------------------------- keep alive */

	function ping() {
		if (document.visibilityState === 'hidden') {
			return; // A background tab is not activity.
		}
		post('ffac_ping', false);
	}

	if (cfg.pingInterval > 0) {
		setInterval(ping, cfg.pingInterval);
	}

	/* -------------------------------------------------- internal navigation */

	function markInternal() {
		internalNav = true;
		// If the navigation never happens (a cancelled click, a "#" link), let the
		// flag expire so a genuine departure a minute later is still caught.
		clearTimeout(internalNavTimer);
		internalNavTimer = setTimeout(function () {
			internalNav = false;
		}, 5000);
	}

	function isInternal(url) {
		try {
			var target = new URL(url, window.location.href);
			return target.origin === window.location.origin;
		} catch (e) {
			return false;
		}
	}

	document.addEventListener('click', function (event) {
		var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
		if (!link) {
			return;
		}

		var href = link.getAttribute('href') || '';

		// A new tab or a download does not unload this page at all.
		if (link.target && link.target !== '_self') {
			return;
		}
		if (href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0) {
			return;
		}

		// The sign-out link handles the session itself; do not race it with a beacon.
		if (href.indexOf('ffac_logout=') !== -1) {
			markInternal();
			return;
		}

		if (isInternal(href)) {
			markInternal();
		}
	}, true);

	document.addEventListener('submit', function (event) {
		var form = event.target;
		var action = form.getAttribute('action') || window.location.href;
		if (isInternal(action)) {
			markInternal();
		}
	}, true);

	// A scripted navigation (Elementor popups, single page bits) still counts.
	window.addEventListener('popstate', markInternal);

	/* ------------------------------------------------------------- leaving */

	function leaving() {
		try {
			window.sessionStorage.setItem('ffacHidAt', String(Date.now()));
		} catch (e) { /* private mode: no storage, carry on */ }

		if (!cfg.logoutOnLeave || internalNav || beaconSent) {
			return;
		}
		beaconSent = true;
		post('ffac_leave', true);
	}

	// pagehide is the reliable one across browsers, including mobile Safari.
	window.addEventListener('pagehide', leaving);
	window.addEventListener('beforeunload', leaving);

	/*
	 * The back button can restore this page straight out of the browser cache,
	 * with no request to the server at all. Two different things look identical
	 * from here, so we go on how long the page was gone:
	 *
	 *   back within a few seconds  -> they were moving around inside the site,
	 *                                 tell the server we are still here;
	 *   back after longer          -> they had gone somewhere else, so reload and
	 *                                 let the server end the session properly.
	 */
	window.addEventListener('pageshow', function (event) {
		if (!event.persisted) {
			return;
		}

		var hidAt = 0;
		try {
			hidAt = parseInt(window.sessionStorage.getItem('ffacHidAt') || '0', 10);
		} catch (e) { /* no storage */ }

		var away = hidAt ? (Date.now() - hidAt) / 1000 : 0;

		if (cfg.logoutOnLeave && away > 3) {
			window.location.reload();
			return;
		}

		beaconSent = false;
		internalNav = false;
		post('ffac_ping', false);
	});
}());
