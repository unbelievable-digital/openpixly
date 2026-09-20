<?php
/**
 * Provider-agnostic event bus.
 *
 * Integrations (WooCommerce, forms, ...) push *normalized* events here.
 * Each registered provider turns a normalized event into its own payload
 * (oaiq(...) args for OpenAI, fbq(...) args for Meta, ...).
 *
 * Normalized event shape:
 *
 *   array(
 *     'name'         => 'purchase',            // see NAMES below
 *     'event_id'     => 'order_123',           // optional, used for dedup
 *     'value'        => 25.99,                 // optional, major units
 *     'currency'     => 'USD',                 // required when value is set
 *     'items'        => array(                 // optional
 *       array( 'id' => '123', 'name' => 'Shirt', 'quantity' => 1,
 *              'price' => 25.99, 'group_id' => '99', 'variant' => array( 'size' => 'M' ) ),
 *     ),
 *     'content_type' => 'product',             // product | page | plan
 *     'plan_id'      => 'pro_monthly',         // subscriptions/trials
 *     'custom_name'  => 'quote_requested',     // for name = custom
 *     'user'         => array( 'email' => ..., 'phone' => ..., ... ), // raw, providers hash
 *     'channel'      => 'browser' | 'server',
 *     'source'       => 'woocommerce',         // optional; providers with that setting off skip it
 *     'context'      => array( 'ip_address', 'user_agent', 'source_url', 'timestamp_ms',
 *                              + each provider's attribution cookies: 'oppref', 'obref', 'fbp', 'fbc' ),
 *   )
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Event_Bus {

	const NAMES = array(
		'page_view',
		'view_item',
		'add_to_cart',
		'begin_checkout',
		'purchase',
		'sign_up',
		'generate_lead',
		'schedule',
		'subscribe',
		'start_trial',
		'custom',
	);

	const SESSION_KEY   = 'openpixel_pending_events';
	const TRANSIENT_TTL = 15 * MINUTE_IN_SECONDS;

	/** @var array Events queued during this request for the browser. */
	private $browser_queue = array();

	/** @var array Raw user data shared by all browser events on this page. */
	private $user = array();

	/**
	 * Push a normalized event.
	 *
	 * @param array $event   See class docblock.
	 * @param bool  $persist Store for the next page load (AJAX / redirect flows).
	 */
	public function track( array $event, $persist = false ) {
		$event = $this->normalize( $event );
		if ( ! $event ) {
			return;
		}

		/**
		 * Last chance to change or drop an event. Return null/false to drop.
		 */
		$event = apply_filters( 'openpixel_track_event', $event );
		if ( ! $event ) {
			return;
		}

		if ( 'server' === $event['channel'] ) {
			do_action( 'openpixel_server_event', $event );
			return;
		}

		if ( $persist ) {
			$this->persist( $event );
		} else {
			$this->browser_queue[] = $event;
		}
	}

	/**
	 * Set raw user data (email, phone, names, geo) for advanced matching
	 * on this page's browser events.
	 *
	 * @param array $user
	 */
	public function set_user( array $user ) {
		$this->user = array_merge( $this->user, array_filter( $user ) );
	}

	public function get_user() {
		return $this->user;
	}

	/**
	 * All browser events for this page: persisted ones first, then this
	 * request's. Clears the persisted store.
	 *
	 * @return array
	 */
	public function drain_browser_events() {
		$events = array_merge( $this->drain_persisted(), $this->browser_queue );
		$this->browser_queue = array();
		return $events;
	}

	/**
	 * Events persisted from earlier requests (AJAX add-to-cart, registration
	 * redirects). Clears them.
	 *
	 * @return array
	 */
	public function drain_persisted() {
		$events = array();

		if ( function_exists( 'WC' ) && WC()->session ) {
			$stored = WC()->session->get( self::SESSION_KEY );
			if ( is_array( $stored ) && $stored ) {
				$events = array_merge( $events, $stored );
				WC()->session->set( self::SESSION_KEY, array() );
			}
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			$key    = self::SESSION_KEY . '_user_' . $user_id;
			$stored = get_transient( $key );
			if ( is_array( $stored ) && $stored ) {
				$events = array_merge( $events, $stored );
				delete_transient( $key );
			}
		}

		return $events;
	}

	private function persist( array $event ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			$stored   = WC()->session->get( self::SESSION_KEY );
			$stored   = is_array( $stored ) ? $stored : array();
			$stored[] = $event;
			WC()->session->set( self::SESSION_KEY, $stored );
			return;
		}

		$user_id = ! empty( $event['_user_id'] ) ? (int) $event['_user_id'] : get_current_user_id();
		if ( $user_id ) {
			$key      = self::SESSION_KEY . '_user_' . $user_id;
			$stored   = get_transient( $key );
			$stored   = is_array( $stored ) ? $stored : array();
			$stored[] = $event;
			set_transient( $key, $stored, self::TRANSIENT_TTL );
			return;
		}

		// No place to persist it; fall back to this request's queue.
		$this->browser_queue[] = $event;
	}

	private function normalize( array $event ) {
		if ( empty( $event['name'] ) ) {
			return null;
		}

		$name = (string) $event['name'];
		if ( ! in_array( $name, self::NAMES, true ) ) {
			// Unknown names become custom events with that name.
			$event['custom_name'] = $name;
			$name                 = 'custom';
		}

		$defaults = array(
			'name'         => $name,
			'event_id'     => '',
			'value'        => null,
			'currency'     => '',
			'items'        => array(),
			'content_type' => '',
			'plan_id'      => '',
			'custom_name'  => '',
			'user'         => array(),
			'channel'      => 'browser',
			'source'       => '',
			'context'      => array(),
		);

		$event = wp_parse_args( $event, $defaults );
		$event['name']     = $name;
		$event['currency'] = OpenPixel_Money::normalize_currency( $event['currency'] );
		$event['channel']  = 'server' === $event['channel'] ? 'server' : 'browser';
		$event['source']   = sanitize_key( $event['source'] );

		if ( null !== $event['value'] && '' === $event['currency'] ) {
			// amount without currency is invalid for every provider we know.
			$event['value'] = null;
		}

		if ( 'custom' === $name ) {
			$event['custom_name'] = self::sanitize_custom_name( $event['custom_name'] );
			if ( ! $event['custom_name'] ) {
				return null;
			}
		}

		$items = array();
		foreach ( (array) $event['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items[] = array(
				'id'       => isset( $item['id'] ) ? (string) $item['id'] : '',
				'group_id' => isset( $item['group_id'] ) ? (string) $item['group_id'] : '',
				'name'     => isset( $item['name'] ) ? (string) $item['name'] : '',
				'quantity' => isset( $item['quantity'] ) ? max( 0, (int) $item['quantity'] ) : 0,
				'price'    => isset( $item['price'] ) ? (float) $item['price'] : null,
				'variant'  => ( isset( $item['variant'] ) && is_array( $item['variant'] ) ) ? $item['variant'] : array(),
			);
		}
		$event['items'] = $items;

		return $event;
	}

	/**
	 * 1–64 chars, letters/digits/underscore/dash, must start and end with
	 * a letter or digit, lowercase, not a built-in OpenAI event name.
	 */
	public static function sanitize_custom_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		$name = preg_replace( '/[^a-z0-9_\-]/', '_', $name );
		$name = trim( $name, '_-' );
		$name = substr( $name, 0, 64 );

		$reserved = array(
			'app_installed', 'app_opened', 'appointment_scheduled', 'checkout_started',
			'contents_viewed', 'custom', 'items_added', 'lead_created', 'order_created',
			'page_viewed', 'registration_completed', 'subscription_created', 'trial_started',
		);

		if ( '' === $name || in_array( $name, $reserved, true ) ) {
			return '';
		}

		return $name;
	}
}
