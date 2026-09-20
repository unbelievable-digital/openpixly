<?php
/**
 * Meta (Facebook & Instagram) Pixel provider.
 *
 * Implements https://developers.facebook.com/docs/meta-pixel:
 *  - official loader from https://connect.facebook.net/en_US/fbevents.js
 *  - fbq("init", pixelId, advancedMatching) with server-hashed customer data
 *  - fbq("consent", "revoke") before init when consent is required
 *  - fbq("track" | "trackCustom", event, params, { eventID })
 *  - <noscript> image tag fallback (https://www.facebook.com/tr)
 *  - Conversions API delegation (see OpenPixel_Meta_CAPI)
 *
 * Amounts are decimals in major units (Meta's `value`), content ids are the
 * WooCommerce product / variation ids, identical to `id` in the catalog feed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Provider_Meta extends OpenPixel_Provider {

	const SDK_URL       = 'https://connect.facebook.net/en_US/fbevents.js';
	const IMAGE_TAG_URL = 'https://www.facebook.com/tr';

	/**
	 * Normalized bus event name => Meta standard event.
	 * `custom` goes out through trackCustom with the custom name.
	 */
	const EVENT_MAP = array(
		'page_view'      => 'PageView',
		'view_item'      => 'ViewContent',
		'add_to_cart'    => 'AddToCart',
		'begin_checkout' => 'InitiateCheckout',
		'purchase'       => 'Purchase',
		'sign_up'        => 'CompleteRegistration',
		'generate_lead'  => 'Lead',
		'schedule'       => 'Schedule',
		'subscribe'      => 'Subscribe',
		'start_trial'    => 'StartTrial',
	);

	/** Values Meta accepts for the server event's action_source. */
	const ACTION_SOURCES = array( 'email', 'website', 'app', 'phone_call', 'chat', 'physical_store', 'system_generated', 'business_messaging', 'other' );

	/** @var OpenPixel_Meta_CAPI */
	private $capi;

	public function __construct() {
		// Eager for the same reason as the OpenAI client: the constructor
		// registers the Action Scheduler callback.
		$this->capi = new OpenPixel_Meta_CAPI( $this );
	}

	public function get_id() {
		return 'meta';
	}

	public function get_label() {
		return __( 'Meta (Facebook & Instagram Pixel)', 'openpixly' );
	}

	public function get_description() {
		return __( 'Find the Pixel (dataset) ID in Meta Events Manager > Data sources. For server-side events, generate a Conversions API access token under the dataset\'s Settings.', 'openpixly' );
	}

	public function get_fields() {
		return array(
			'enabled'           => array(
				'label'   => __( 'Enable Meta pixel', 'openpixly' ),
				'type'    => 'checkbox',
				'default' => false,
			),
			'pixel_id'          => array(
				'label'       => __( 'Pixel ID', 'openpixly' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'From Events Manager. Separate multiple Pixel IDs with commas; every event is sent to all of them.', 'openpixly' ),
			),
			'exclude_admins'    => array(
				'label'       => __( 'Do not track administrators', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Skip the pixel entirely for logged-in users who can manage options.', 'openpixly' ),
			),
			'consent_mode'      => array(
				'label'       => __( 'Consent', 'openpixly' ),
				'type'        => 'select',
				'default'     => 'default',
				'options'     => array(
					'default' => __( 'Measure immediately (SDK default)', 'openpixly' ),
					'require' => __( 'Require consent first', 'openpixly' ),
				),
				'description' => __( 'With "Require consent", the pixel starts with fbq("consent", "revoke"). Grant it from your cookie banner by calling window.openPixel.grantConsent(), or via the WP Consent API ("marketing" category), which is detected automatically.', 'openpixly' ),
			),
			'advanced_matching' => array(
				'label'       => __( 'Send hashed customer data', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Advanced matching. Email, phone, names, city, region, postal code, country and customer id are normalized and SHA-256 hashed on the server before they reach the browser.', 'openpixly' ),
			),
			'noscript'          => array(
				'label'       => __( 'No-JavaScript fallback', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Adds a <noscript> image tag that records PageView when JavaScript is unavailable.', 'openpixly' ),
			),
			'woocommerce'       => array(
				'section'     => __( 'WooCommerce', 'openpixly' ),
				'label'       => __( 'Track WooCommerce events', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'ViewContent (product pages), AddToCart, InitiateCheckout, Purchase (thank-you page) and CompleteRegistration. Content ids match the Meta catalog feed.', 'openpixly' ),
			),
			'capi_enabled'      => array(
				'section'     => __( 'Conversions API (server-side)', 'openpixly' ),
				'label'       => __( 'Send orders through the Conversions API', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Sends Purchase from the server when payment completes, using the same event ID as the browser event so Meta deduplicates them.', 'openpixly' ),
			),
			'capi_access_token' => array(
				'label'       => __( 'Conversions API access token', 'openpixly' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Leave blank to keep the saved token.', 'openpixly' ),
			),
			'capi_test_code'    => array(
				'label'       => __( 'Test event code', 'openpixly' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'From Events Manager > Test events (e.g. TEST12345). Only used by the "Send test event" button; real orders never carry it.', 'openpixly' ),
			),
		);
	}

	public function get_pixel_ids() {
		$raw = (string) $this->get_setting( 'pixel_id', '' );
		$ids = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return array_values( array_unique( $ids ) );
	}

	public function should_render() {
		if ( ! $this->get_pixel_ids() ) {
			return false;
		}
		if ( $this->get_setting( 'exclude_admins' ) && current_user_can( 'manage_options' ) ) {
			return false;
		}
		return true;
	}

	public function capi() {
		return $this->capi;
	}

	private function consent_pending() {
		return 'require' === $this->get_setting( 'consent_mode' )
			&& ! apply_filters( 'openpixel_consent_granted', false, $this->get_id() );
	}

	/* ---------------------------------------------------------------------
	 * Browser output
	 * ------------------------------------------------------------------ */

	public function render_head( OpenPixel_Event_Bus $bus ) {
		$nonce      = apply_filters( 'openpixel_script_nonce', '' );
		$nonce_attr = $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';

		$user = array();
		if ( $this->get_setting( 'advanced_matching' ) && $bus->get_user() ) {
			$user = OpenPixel_Hash::meta_user( $bus->get_user() );
		}

		echo "\n<!-- Meta Pixel (Openpixly plugin) -->\n";
		echo '<script' . $nonce_attr . ">\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "!function (f, b, e, v, n, t, s) {\n"
			. "  if (f.fbq) return;\n"
			. "  n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };\n"
			. "  if (!f._fbq) f._fbq = n;\n"
			. "  n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];\n"
			. "  t = b.createElement(e); t.async = !0; t.src = v;\n"
			. "  s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);\n"
			. '}(window, document, "script", ' . wp_json_encode( self::SDK_URL ) . ");\n";

		if ( $this->consent_pending() ) {
			echo "fbq(\"consent\", \"revoke\");\n";
		}

		foreach ( $this->get_pixel_ids() as $pixel_id ) {
			echo 'fbq("init", ' . wp_json_encode( (string) $pixel_id ) . ( $user ? ', ' . wp_json_encode( $user ) : '' ) . ");\n";
		}

		echo "</script>\n<!-- / Meta Pixel -->\n";
	}

	public function render_footer( OpenPixel_Event_Bus $bus ) {
		if ( ! $this->get_setting( 'noscript' ) || $this->consent_pending() ) {
			return;
		}

		echo "<noscript>\n";
		foreach ( $this->get_pixel_ids() as $pixel_id ) {
			$url = self::IMAGE_TAG_URL . '?id=' . rawurlencode( $pixel_id ) . '&ev=PageView&noscript=1';
			echo '<img src="' . esc_url( $url ) . '" width="1" height="1" style="display:none" alt="" />' . "\n";
		}
		echo "</noscript>\n";
	}

	public function to_browser_payload( array $event ) {
		$meta_event = $this->meta_event_name( $event );
		if ( '' === $meta_event ) {
			return null;
		}

		$params = $this->build_custom_data( $event );

		$args = array(
			'custom' === $event['name'] ? 'trackCustom' : 'track',
			$meta_event,
			$params ? $params : new stdClass(),
		);
		if ( ! empty( $event['event_id'] ) ) {
			$args[] = array( 'eventID' => (string) $event['event_id'] );
		}

		return array(
			'provider' => $this->get_id(),
			'event_id' => ! empty( $event['event_id'] ) ? (string) $event['event_id'] : '',
			'args'     => $args,
		);
	}

	private function meta_event_name( array $event ) {
		if ( 'custom' === $event['name'] ) {
			return (string) $event['custom_name'];
		}
		return isset( self::EVENT_MAP[ $event['name'] ] ) ? self::EVENT_MAP[ $event['name'] ] : '';
	}

	/**
	 * Meta `custom_data` / fbq params: value + currency, content_ids,
	 * contents[{ id, quantity, item_price }], content_type, num_items.
	 *
	 * @param array $event Normalized event.
	 * @return array
	 */
	public function build_custom_data( array $event ) {
		$data = array();

		if ( 'page_view' === $event['name'] ) {
			return $data; // PageView takes no parameters.
		}

		if ( null !== $event['value'] && $event['currency'] ) {
			$data['value']    = round( (float) $event['value'], OpenPixel_Money::exponent( $event['currency'] ) );
			$data['currency'] = $event['currency'];
		}

		if ( $event['plan_id'] ) {
			$data['content_name'] = (string) $event['plan_id'];
		}

		// Meta only knows content_type product / product_group.
		if ( ! in_array( $event['content_type'], array( '', 'product' ), true ) ) {
			return $data;
		}

		$ids       = array();
		$contents  = array();
		$num_items = 0;

		foreach ( $event['items'] as $item ) {
			if ( '' === $item['id'] ) {
				continue;
			}
			$quantity = $item['quantity'] > 0 ? $item['quantity'] : 1;
			$content  = array(
				'id'       => $item['id'],
				'quantity' => $quantity,
			);
			if ( null !== $item['price'] && $event['currency'] ) {
				$content['item_price'] = round( (float) $item['price'], OpenPixel_Money::exponent( $event['currency'] ) );
			}

			$ids[]      = $item['id'];
			$contents[] = $content;
			$num_items += $quantity;
		}

		if ( $ids ) {
			$data['content_ids']  = $ids;
			$data['contents']     = $contents;
			$data['content_type'] = 'product';
			$data['num_items']    = $num_items;

			if ( 1 === count( $event['items'] ) && '' !== $event['items'][0]['name'] ) {
				$data['content_name'] = $event['items'][0]['name'];
			}
		}

		return $data;
	}

	/* ---------------------------------------------------------------------
	 * Server-side (Conversions API)
	 * ------------------------------------------------------------------ */

	public function handle_server_event( array $event ) {
		if ( ! $this->is_enabled() || ! $this->get_setting( 'capi_enabled' ) ) {
			return;
		}
		$this->capi()->queue_event( $event );
	}

	public function get_attribution_cookies() {
		return array(
			'fbp' => '_fbp',
			'fbc' => '_fbc',
		);
	}

	public function get_server_test_description() {
		if ( ! $this->get_setting( 'capi_enabled' ) ) {
			return '';
		}
		return __( 'Sends one Purchase event carrying your test event code. It appears under Events Manager > Test events and is not counted as a conversion.', 'openpixly' );
	}

	public function send_server_test( array $event ) {
		$result = $this->capi()->send( array( $this->to_capi_event( $event ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return __( 'Meta Conversions API accepted the test event. Check Events Manager > Test events.', 'openpixly' );
	}

	/**
	 * Map a normalized event to a Conversions API server event.
	 *
	 * @param array $event Normalized bus event (channel = server).
	 * @return array|null
	 */
	public function to_capi_event( array $event ) {
		$meta_event = $this->meta_event_name( $event );
		if ( '' === $meta_event ) {
			return null;
		}

		$ctx = $event['context'];

		$action_source = ! empty( $ctx['action_source'] ) ? (string) $ctx['action_source'] : 'website';
		if ( ! in_array( $action_source, self::ACTION_SOURCES, true ) ) {
			$action_source = 'website'; // the bus default "web" included
		}

		$capi_event = array(
			'event_name'       => $meta_event,
			'event_time'       => ! empty( $ctx['timestamp_ms'] ) ? (int) floor( $ctx['timestamp_ms'] / 1000 ) : time(),
			'event_id'         => ! empty( $event['event_id'] ) ? (string) $event['event_id'] : 'openpixel_' . wp_generate_uuid4(),
			'action_source'    => $action_source,
			'event_source_url' => ! empty( $ctx['source_url'] ) ? $ctx['source_url'] : home_url( '/' ),
		);

		$user_data = OpenPixel_Hash::meta_user( $event['user'] );
		$plain     = array(
			'client_ip_address' => 'ip_address',
			'client_user_agent' => 'user_agent',
			'fbp'               => 'fbp',
			'fbc'               => 'fbc',
		);
		foreach ( $plain as $meta_key => $ctx_key ) {
			if ( ! empty( $ctx[ $ctx_key ] ) ) {
				$user_data[ $meta_key ] = (string) $ctx[ $ctx_key ];
			}
		}
		// Meta rejects an empty object; the test button has no customer.
		$capi_event['user_data'] = $user_data ? $user_data : array( 'client_user_agent' => 'Openpixly' );

		$custom_data = $this->build_custom_data( $event );
		if ( 'purchase' === $event['name'] && 0 === strpos( (string) $event['event_id'], 'order_' ) ) {
			$custom_data['order_id'] = substr( (string) $event['event_id'], 6 );
		}
		if ( $custom_data ) {
			$capi_event['custom_data'] = $custom_data;
		}

		return $capi_event;
	}
}
