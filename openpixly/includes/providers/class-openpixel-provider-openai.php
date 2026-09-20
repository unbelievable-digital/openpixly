<?php
/**
 * OpenAI (ChatGPT Ads) Measurement Pixel provider.
 *
 * Implements https://developers.openai.com/ads/measurement-pixel:
 *  - official loader from https://bzrcdn.openai.com/sdk/oaiq.min.js
 *  - oaiq("init", { pixelId, debug, user })
 *  - oaiq("consent", false) before init when consent is required
 *  - oaiq("measure", event, data, options)
 *  - <noscript> image tag fallback (https://developers.openai.com/ads/image-tag)
 *  - Conversions API delegation (see OpenPixel_OpenAI_CAPI)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Provider_OpenAI extends OpenPixel_Provider {

	const SDK_URL       = 'https://bzrcdn.openai.com/sdk/oaiq.min.js';
	const IMAGE_TAG_URL = 'https://bzr.openai.com/v1/sdk/events';

	/**
	 * Normalized bus event name => array( openai event name, data type ).
	 */
	const EVENT_MAP = array(
		'page_view'      => array( 'page_viewed', 'contents' ),
		'view_item'      => array( 'contents_viewed', 'contents' ),
		'add_to_cart'    => array( 'items_added', 'contents' ),
		'begin_checkout' => array( 'checkout_started', 'contents' ),
		'purchase'       => array( 'order_created', 'contents' ),
		'sign_up'        => array( 'registration_completed', 'customer_action' ),
		'generate_lead'  => array( 'lead_created', 'customer_action' ),
		'schedule'       => array( 'appointment_scheduled', 'customer_action' ),
		'subscribe'      => array( 'subscription_created', 'plan_enrollment' ),
		'start_trial'    => array( 'trial_started', 'plan_enrollment' ),
		'custom'         => array( 'custom', 'custom' ),
	);

	/** @var OpenPixel_OpenAI_CAPI */
	private $capi;

	public function __construct() {
		// Instantiated eagerly: the CAPI class registers the Action Scheduler
		// callback in its constructor, and cron requests never call capi()
		// otherwise ("no callbacks are registered" failures).
		$this->capi = new OpenPixel_OpenAI_CAPI( $this );
	}

	public function get_id() {
		return 'openai';
	}

	public function get_label() {
		return __( 'OpenAI (ChatGPT Ads Measurement Pixel)', 'openpixly' );
	}

	public function get_description() {
		return __( 'Create a Pixel ID (and, for server-side events, a Conversions API key) in the Conversions tab of ChatGPT Ads Manager.', 'openpixly' );
	}

	public function get_fields() {
		return array(
			'enabled'           => array(
				'label'   => __( 'Enable OpenAI pixel', 'openpixly' ),
				'type'    => 'checkbox',
				'default' => false,
			),
			'pixel_id'          => array(
				'label'       => __( 'Pixel ID', 'openpixly' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'From Ads Manager > Conversions. Separate multiple Pixel IDs with commas; every event is sent to all of them.', 'openpixly' ),
			),
			'debug'             => array(
				'label'       => __( 'Debug mode', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Logs SDK activity to the browser console. Turn off in production.', 'openpixly' ),
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
				'description' => __( 'With "Require consent", the pixel starts with consent = false. Grant it from your cookie banner by calling window.openPixel.grantConsent(), or via the WP Consent API ("marketing" category), which is detected automatically.', 'openpixly' ),
			),
			'advanced_matching' => array(
				'label'       => __( 'Send hashed customer data', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Improves conversion matching. Email, phone and names are normalized and SHA-256 hashed on the server before they reach the browser; country/city/region/postal code are sent as plain text, as the docs require.', 'openpixly' ),
			),
			'noscript'          => array(
				'label'       => __( 'No-JavaScript fallback', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Adds a <noscript> image tag that records page_viewed when JavaScript is unavailable.', 'openpixly' ),
			),
			'woocommerce'       => array(
				'section'     => __( 'WooCommerce', 'openpixly' ),
				'label'       => __( 'Track WooCommerce events', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'contents_viewed (product pages), items_added (add to cart), checkout_started, order_created (thank-you page) and registration_completed.', 'openpixly' ),
			),
			'capi_enabled'      => array(
				'section'     => __( 'Conversions API (server-side)', 'openpixly' ),
				'label'       => __( 'Send orders through the Conversions API', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Sends order_created from the server when payment completes, using the same event ID as the browser event so OpenAI deduplicates them. More reliable than the browser pixel alone.', 'openpixly' ),
			),
			'capi_api_key'      => array(
				'label'       => __( 'Conversions API key', 'openpixly' ),
				'type'        => 'password',
				'default'     => '',
				'description' => __( 'Leave blank to keep the saved key.', 'openpixly' ),
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

	/* ---------------------------------------------------------------------
	 * Browser output
	 * ------------------------------------------------------------------ */

	public function render_head( OpenPixel_Event_Bus $bus ) {
		$pixel_ids = $this->get_pixel_ids();
		$nonce     = apply_filters( 'openpixel_script_nonce', '' );
		$nonce_attr = $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';

		$require_consent = 'require' === $this->get_setting( 'consent_mode' );
		if ( $require_consent && apply_filters( 'openpixel_consent_granted', false, $this->get_id() ) ) {
			$require_consent = false;
		}

		$user = array();
		if ( $this->get_setting( 'advanced_matching' ) && $bus->get_user() ) {
			$user = OpenPixel_Hash::pixel_user( $bus->get_user() );
		}

		echo "\n<!-- OpenAI Measurement Pixel (Openpixly plugin) -->\n";
		echo '<script' . $nonce_attr . ">\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "(function (w, d, s, u) {\n"
			. "  if (w.oaiq) return;\n"
			. "  var q = function () { q.q.push(arguments); };\n"
			. "  q.q = [];\n"
			. "  w.oaiq = q;\n"
			. "  var js = d.createElement(s);\n"
			. "  js.async = true;\n"
			. "  js.src = u;\n"
			. "  var f = d.getElementsByTagName(s)[0];\n"
			. "  f.parentNode.insertBefore(js, f);\n"
			. '})(window, document, "script", ' . wp_json_encode( self::SDK_URL ) . ");\n";

		if ( $require_consent ) {
			echo "oaiq(\"consent\", false);\n";
		}

		foreach ( $pixel_ids as $pixel_id ) {
			$init = array( 'pixelId' => $pixel_id );
			if ( $this->get_setting( 'debug' ) ) {
				$init['debug'] = true;
			}
			if ( $user ) {
				$init['user'] = $user;
			}
			echo 'oaiq("init", ' . wp_json_encode( $init ) . ");\n";
		}

		echo "</script>\n<!-- / OpenAI Measurement Pixel -->\n";
	}

	public function render_footer( OpenPixel_Event_Bus $bus ) {
		if ( ! $this->get_setting( 'noscript' ) ) {
			return;
		}
		if ( 'require' === $this->get_setting( 'consent_mode' ) && ! apply_filters( 'openpixel_consent_granted', false, $this->get_id() ) ) {
			// Docs: render the image tag only after any required consent.
			return;
		}

		echo "<noscript>\n";
		foreach ( $this->get_pixel_ids() as $pixel_id ) {
			$url = self::IMAGE_TAG_URL . '?pid=' . rawurlencode( $pixel_id ) . '&event=page_viewed&data[type]=contents';
			echo '<img src="' . esc_url( $url ) . '" width="1" height="1" style="display:none" alt="" />' . "\n";
		}
		echo "</noscript>\n";
	}

	public function get_prelude_payloads( OpenPixel_Event_Bus $bus ) {
		// User data that only became known after <head> (e.g. billing details
		// on the thank-you page) is sent with a second init, as the docs
		// describe. Only needed when it differs from what render_head sent —
		// core sets the user on the bus before wp_head, so nothing to do here
		// unless a later integration adds more data.
		return array();
	}

	public function to_browser_payload( array $event ) {
		if ( ! isset( self::EVENT_MAP[ $event['name'] ] ) ) {
			return null;
		}

		list( $openai_event, $data_type ) = self::EVENT_MAP[ $event['name'] ];

		$data = $this->build_data( $event, $data_type, false );

		$options = array();
		if ( ! empty( $event['event_id'] ) ) {
			$options['event_id'] = (string) $event['event_id'];
		}
		if ( 'custom' === $openai_event ) {
			$options['custom_event_name'] = $event['custom_name'];
		}

		$args = array( 'measure', $openai_event, $data );
		if ( $options ) {
			$args[] = $options;
		}

		return array(
			'provider' => $this->get_id(),
			'event_id' => ! empty( $event['event_id'] ) ? (string) $event['event_id'] : '',
			'args'     => $args,
		);
	}

	/**
	 * Build the `data` object for an event, following the documented shape.
	 *
	 * @param array  $event     Normalized event.
	 * @param string $data_type contents | customer_action | plan_enrollment | custom
	 * @param bool   $for_capi  Allow Conversions-API-only content fields.
	 * @return array
	 */
	public function build_data( array $event, $data_type, $for_capi ) {
		$data = array( 'type' => $data_type );

		if ( null !== $event['value'] && $event['currency'] ) {
			$data['amount']   = OpenPixel_Money::to_minor( $event['value'], $event['currency'] );
			$data['currency'] = $event['currency'];
		}

		if ( 'customer_action' !== $data_type ) {
			if ( in_array( $data_type, array( 'plan_enrollment', 'custom' ), true ) && $event['plan_id'] ) {
				$data['plan_id'] = (string) $event['plan_id'];
			}

			$contents = $this->build_contents( $event, $for_capi );
			if ( $contents ) {
				$data['contents'] = $contents;
			}
		}

		return $data;
	}

	private function build_contents( array $event, $for_capi ) {
		$contents     = array();
		$content_type = $event['content_type'] ? (string) $event['content_type'] : 'product';

		foreach ( $event['items'] as $item ) {
			$content = array();

			if ( '' !== $item['id'] ) {
				$content['id'] = $item['id'];
			}
			if ( $for_capi && '' !== $item['group_id'] ) {
				$content['group_id'] = $item['group_id'];
			}
			if ( '' !== $item['name'] ) {
				$content['name'] = $item['name'];
			}
			$content['content_type'] = $content_type;

			if ( $item['quantity'] > 0 ) {
				$content['quantity'] = $item['quantity'];
			}
			if ( null !== $item['price'] && $event['currency'] ) {
				$content['amount']   = OpenPixel_Money::to_minor( $item['price'], $event['currency'] );
				$content['currency'] = $event['currency'];
			}
			if ( $for_capi && $item['variant'] ) {
				$variant = array();
				foreach ( $item['variant'] as $k => $v ) {
					if ( is_scalar( $v ) ) {
						$variant[ (string) $k ] = (string) $v;
					}
				}
				if ( $variant ) {
					$content['variant_dict'] = $variant;
				}
			}

			if ( $content ) {
				$contents[] = $content;
			}
		}

		return $contents;
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
			'oppref' => '__oppref',
			'obref'  => '__obref',
		);
	}

	public function get_server_test_description() {
		if ( ! $this->get_setting( 'capi_enabled' ) ) {
			return '';
		}
		return __( 'Sends one order_created event with validate_only = true. Nothing is recorded; OpenAI only checks the key and payload.', 'openpixly' );
	}

	public function send_server_test( array $event ) {
		$result = $this->capi()->send( array( $this->to_capi_event( $event ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return __( 'OpenAI Conversions API accepted the test event (validate_only). Credentials and payload are valid.', 'openpixly' );
	}

	/**
	 * Map a normalized event to a Conversions API event object.
	 *
	 * @param array $event Normalized bus event (channel = server).
	 * @return array|null
	 */
	public function to_capi_event( array $event ) {
		if ( ! isset( self::EVENT_MAP[ $event['name'] ] ) ) {
			return null;
		}

		list( $openai_event, $data_type ) = self::EVENT_MAP[ $event['name'] ];
		$ctx = $event['context'];

		$id = ! empty( $event['event_id'] ) ? (string) $event['event_id'] : 'openpixel_' . wp_generate_uuid4();

		$capi_event = array(
			'id'            => $id,
			'type'          => $openai_event,
			'timestamp_ms'  => ! empty( $ctx['timestamp_ms'] ) ? (int) $ctx['timestamp_ms'] : (int) round( microtime( true ) * 1000 ),
			'action_source' => ! empty( $ctx['action_source'] ) ? $ctx['action_source'] : 'web',
			'source_url'    => ! empty( $ctx['source_url'] ) ? $ctx['source_url'] : home_url( '/' ),
			'data'          => $this->build_data( $event, $data_type, true ),
		);

		if ( 'custom' === $openai_event ) {
			$capi_event['custom_event_name'] = $event['custom_name'];
		}
		if ( ! empty( $ctx['oppref'] ) ) {
			$capi_event['oppref'] = (string) $ctx['oppref'];
		}

		$raw_user = $event['user'];
		foreach ( array( 'ip_address', 'user_agent', 'obref' ) as $key ) {
			if ( ! empty( $ctx[ $key ] ) ) {
				$raw_user[ $key ] = $ctx[ $key ];
			}
		}
		$user = OpenPixel_Hash::capi_user( $raw_user );
		if ( $user ) {
			$capi_event['user'] = $user;
		}

		return $capi_event;
	}
}
