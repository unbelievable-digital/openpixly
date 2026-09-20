/**
 * Openpixly — front-end runtime.
 *
 * PHP emits provider payloads as { provider: "openai", args: [...], event_id }.
 * Each provider registers a handler; the built-in handlers simply forward
 * args to the official SDK queue (window.oaiq, window.fbq). Payloads can arrive:
 *   - inline in the footer (window.openPixelEvents),
 *   - inside WooCommerce AJAX fragments (#openpixel-pending[data-openpixel-events]).
 */
(function (window, document) {
	'use strict';

	var openPixel = (window.openPixel = window.openPixel || {});
	var handlers = {};
	var queue = [];
	var seen = loadSeen();

	function loadSeen() {
		try {
			var raw = window.sessionStorage.getItem('openpixel_seen');
			return raw ? JSON.parse(raw) : {};
		} catch (e) {
			return {};
		}
	}

	function remember(id) {
		if (!id) {
			return;
		}
		seen[id] = 1;
		try {
			var keys = Object.keys(seen);
			if (keys.length > 200) {
				keys.slice(0, keys.length - 200).forEach(function (k) {
					delete seen[k];
				});
			}
			window.sessionStorage.setItem('openpixel_seen', JSON.stringify(seen));
		} catch (e) {
			/* storage unavailable — fine */
		}
	}

	openPixel.register = function (providerId, handler) {
		handlers[providerId] = handler;
		openPixel.flush();
	};

	openPixel.push = function (payload) {
		if (payload && typeof payload === 'object') {
			queue.push(payload);
		}
		openPixel.flush();
	};

	openPixel.flush = function () {
		if (window.openPixelEvents && window.openPixelEvents.length) {
			queue = queue.concat(window.openPixelEvents);
			window.openPixelEvents = [];
		}

		var rest = [];
		queue.forEach(function (payload) {
			var handler = handlers[payload.provider];
			if (!handler) {
				rest.push(payload);
				return;
			}
			// WooCommerce replays cached fragments from sessionStorage on
			// every page load; don't re-send an event we already sent.
			// Providers share event ids, so the key is per provider.
			var key = payload.event_id ? payload.provider + ':' + payload.event_id : '';
			if (key && seen[key]) {
				return;
			}
			try {
				handler(payload);
				remember(key);
			} catch (e) {
				if (window.console && console.error) {
					console.error('[openpixly]', e);
				}
			}
		});
		queue = rest;
	};

	/* ------------------------------------------------------------------
	 * Built-in providers: forward args to the SDK queue
	 * ---------------------------------------------------------------- */

	openPixel.register('openai', function (payload) {
		if (typeof window.oaiq !== 'function' || !payload.args) {
			return;
		}
		window.oaiq.apply(null, payload.args);
	});

	openPixel.register('meta', function (payload) {
		if (typeof window.fbq !== 'function' || !payload.args) {
			return;
		}
		window.fbq.apply(null, payload.args);
	});

	var consentSetters = {
		openai: function (granted) {
			if (typeof window.oaiq === 'function') {
				window.oaiq('consent', granted);
			}
		},
		meta: function (granted) {
			if (typeof window.fbq === 'function') {
				window.fbq('consent', granted ? 'grant' : 'revoke');
			}
		}
	};

	function setConsent(granted) {
		Object.keys(consentSetters).forEach(function (id) {
			consentSetters[id](granted);
		});
	}

	openPixel.grantConsent = function () {
		setConsent(true);
	};

	openPixel.revokeConsent = function () {
		setConsent(false);
	};

	function consentRequired() {
		var providers = (window.openPixelConfig && window.openPixelConfig.providers) || {};
		return Object.keys(providers).some(function (id) {
			return providers[id].consentMode === 'require';
		});
	}

	// WP Consent API (https://wordpress.org/plugins/wp-consent-api/) integration.
	if (consentRequired()) {
		if (typeof window.wp_has_consent === 'function' && window.wp_has_consent('marketing')) {
			openPixel.grantConsent();
		}
		document.addEventListener('wp_listen_for_consent_change', function (e) {
			var detail = e && e.detail;
			if (!detail) {
				return;
			}
			if (detail.marketing === 'allow') {
				openPixel.grantConsent();
			} else if (detail.marketing === 'deny') {
				openPixel.revokeConsent();
			}
		});
	}

	/* ------------------------------------------------------------------
	 * WooCommerce fragments
	 * ---------------------------------------------------------------- */

	function readPendingFragment() {
		var el = document.getElementById('openpixel-pending');
		if (!el) {
			return;
		}
		var raw = el.getAttribute('data-openpixel-events');
		if (!raw) {
			return;
		}
		el.removeAttribute('data-openpixel-events');
		try {
			var payloads = JSON.parse(raw);
			if (Array.isArray(payloads)) {
				payloads.forEach(openPixel.push);
			}
		} catch (e) {
			/* ignore malformed fragment */
		}
	}

	if (window.jQuery) {
		window.jQuery(document.body).on(
			'added_to_cart wc_fragments_refreshed wc_fragments_loaded',
			readPendingFragment
		);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			readPendingFragment();
			openPixel.flush();
		});
	} else {
		readPendingFragment();
		openPixel.flush();
	}
})(window, document);
