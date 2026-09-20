<?php
/**
 * Base class every pixel provider (OpenAI now; Meta, Google, ... later) extends.
 *
 * A provider:
 *  - declares its settings fields (the admin screen renders them generically),
 *  - prints its loader in <head> / <body>,
 *  - turns normalized bus events into browser payloads consumed by assets/js/openpixel.js,
 *  - optionally handles server-side events (Conversions APIs).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class OpenPixel_Provider {

	/** @var array Saved settings merged with defaults. Set by core. */
	protected $settings = array();

	/** Unique key, e.g. "openai". */
	abstract public function get_id();

	/** Human readable name, e.g. "OpenAI (ChatGPT Ads Measurement Pixel)". */
	abstract public function get_label();

	/** Short helper text for the settings section. */
	public function get_description() {
		return '';
	}

	/**
	 * Declarative settings fields.
	 *
	 * Each entry: key => array(
	 *   'label'       => string,
	 *   'type'        => 'checkbox' | 'text' | 'password' | 'select' | 'textarea',
	 *   'default'     => mixed,
	 *   'description' => string,
	 *   'options'     => array( value => label ) // select only
	 *   'section'     => string                  // optional grouping heading
	 * )
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'enabled' => array(
				'label'   => __( 'Enabled', 'openpixly' ),
				'type'    => 'checkbox',
				'default' => false,
			),
		);
	}

	public function get_defaults() {
		$defaults = array();
		foreach ( $this->get_fields() as $key => $field ) {
			$defaults[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
		}
		return $defaults;
	}

	/**
	 * Sanitize raw input using the field definitions.
	 *
	 * @param array $input
	 * @return array
	 */
	public function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = array();

		foreach ( $this->get_fields() as $key => $field ) {
			$type  = isset( $field['type'] ) ? $field['type'] : 'text';
			$value = isset( $input[ $key ] ) ? $input[ $key ] : null;

			switch ( $type ) {
				case 'checkbox':
					$output[ $key ] = ! empty( $value );
					break;

				case 'select':
					$options        = isset( $field['options'] ) ? $field['options'] : array();
					$output[ $key ] = ( null !== $value && array_key_exists( $value, $options ) )
						? $value
						: ( isset( $field['default'] ) ? $field['default'] : '' );
					break;

				case 'textarea':
					$output[ $key ] = null === $value ? '' : sanitize_textarea_field( $value );
					break;

				case 'password':
					// Keep the stored value if the field was submitted blank.
					if ( null === $value || '' === trim( (string) $value ) ) {
						$output[ $key ] = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : '';
					} else {
						$output[ $key ] = trim( (string) $value );
					}
					break;

				default:
					$output[ $key ] = null === $value ? '' : sanitize_text_field( $value );
			}
		}

		return $output;
	}

	public function set_settings( array $settings ) {
		$this->settings = wp_parse_args( $settings, $this->get_defaults() );
	}

	public function get_settings() {
		return $this->settings;
	}

	public function get_setting( $key, $default = null ) {
		return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $default;
	}

	public function is_enabled() {
		return ! empty( $this->settings['enabled'] );
	}

	/**
	 * Whether this provider should output anything on the current request.
	 * Core already checks is_enabled(); override for role/consent gating.
	 */
	public function should_render() {
		return true;
	}

	/** Print loader / init markup in <head>. */
	public function render_head( OpenPixel_Event_Bus $bus ) {}

	/** Print markup right before </body> (noscript fallbacks etc.). */
	public function render_footer( OpenPixel_Event_Bus $bus ) {}

	/**
	 * Payloads to run in the browser *before* the event payloads (e.g. a
	 * second init carrying hashed user data). Each payload is
	 * array( 'provider' => id, 'args' => array(...) ).
	 *
	 * @return array
	 */
	public function get_prelude_payloads( OpenPixel_Event_Bus $bus ) {
		return array();
	}

	/**
	 * Turn a normalized event into a browser payload, or null to skip it.
	 *
	 * @param array $event Normalized bus event.
	 * @return array|null array( 'provider' => id, 'args' => array(...), 'event_id' => string )
	 */
	abstract public function to_browser_payload( array $event );

	/**
	 * Handle a server-channel event (Conversions API). No-op by default.
	 *
	 * @param array $event Normalized bus event with channel = server.
	 */
	public function handle_server_event( array $event ) {}

	/**
	 * First-party attribution cookies the provider's SDK sets. Integrations
	 * capture them at checkout and hand them back in the server event's
	 * `context` under the same key.
	 *
	 * @return array context key => cookie name
	 */
	public function get_attribution_cookies() {
		return array();
	}

	/**
	 * What the admin "Send test event" button does for this provider, or ''
	 * when there is nothing to test (no server channel, or it is switched off).
	 */
	public function get_server_test_description() {
		return '';
	}

	/**
	 * Send a synthetic server event in the API's test / validate mode.
	 *
	 * @param array $event Normalized sample purchase.
	 * @return string|WP_Error Success message for the admin notice.
	 */
	public function send_server_test( array $event ) {
		return new WP_Error( 'openpixel_no_server_test', __( 'This provider has no server-side test.', 'openpixly' ) );
	}
}
