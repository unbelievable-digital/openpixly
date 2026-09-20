<?php
/**
 * Meta Conversions API client.
 * https://developers.facebook.com/docs/marketing-api/conversions-api/using-the-api
 *
 * POST https://graph.facebook.com/<VERSION>/<PIXEL-ID>/events
 * Body: { data: [ events ], access_token, test_event_code? }
 *
 * Meta has no validate-only mode: a test sends the event with the
 * `test_event_code` from Events Manager > Test events, which shows it there
 * without counting it as a conversion.
 *
 * Queueing, retries and logging live in OpenPixel_CAPI_Client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Meta_CAPI extends OpenPixel_CAPI_Client {

	const ENDPOINT      = 'https://graph.facebook.com/';
	const GRAPH_VERSION = 'v25.0';
	const ACTION_HOOK   = 'openpixel_meta_capi_send';

	/** @var OpenPixel_Provider_Meta */
	private $provider;

	public function __construct( OpenPixel_Provider_Meta $provider ) {
		$this->provider = $provider;
		parent::__construct();
	}

	public function is_configured() {
		return (bool) $this->provider->get_setting( 'capi_access_token' ) && $this->provider->get_pixel_ids();
	}

	protected function build_event( array $event ) {
		return $this->provider->to_capi_event( $event );
	}

	protected function describe( array $api_event ) {
		return sprintf( '%s (Meta %s)', $api_event['event_id'], $api_event['event_name'] );
	}

	protected function delivered( array $api_event, $result ) {
		do_action( 'openpixel_meta_capi_delivered', $api_event, $result );
	}

	/**
	 * Send a batch synchronously.
	 *
	 * @param array $events Up to 1000 server event objects.
	 * @param bool  $test   Attach the saved test event code.
	 * @return array|WP_Error Decoded response body on 2xx, WP_Error otherwise.
	 */
	public function send( array $events, $test = false ) {
		$token     = (string) $this->provider->get_setting( 'capi_access_token' );
		$pixel_ids = $this->provider->get_pixel_ids();

		if ( ! $token || ! $pixel_ids ) {
			return new WP_Error( 'openpixel_meta_capi_unconfigured', __( 'Meta Conversions API access token or Pixel ID missing.', 'openpixly' ) );
		}

		// The token travels in the body, not the URL, so it stays out of access logs.
		$body = array(
			'data'         => array_values( $events ),
			'access_token' => $token,
		);

		if ( $test ) {
			$code = trim( (string) $this->provider->get_setting( 'capi_test_code' ) );
			if ( '' === $code ) {
				return new WP_Error( 'openpixel_meta_capi_no_test_code', __( 'Enter the test event code from Events Manager > Test events first; without it the event would be recorded as a real conversion.', 'openpixly' ) );
			}
			$body['test_event_code'] = $code;
		}

		$last = null;
		foreach ( $pixel_ids as $pixel_id ) {
			$url = self::ENDPOINT . self::GRAPH_VERSION . '/' . rawurlencode( $pixel_id ) . '/events';

			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = wp_remote_retrieve_body( $response );
			$json = json_decode( $raw, true );

			if ( $code < 200 || $code >= 300 ) {
				$message = is_array( $json ) && isset( $json['error']['message'] )
					? (string) $json['error']['message']
					: substr( $raw, 0, 500 );

				return new WP_Error(
					'openpixel_meta_capi_http_' . $code,
					sprintf( 'HTTP %d: %s', $code, $message ),
					array( 'status' => $code, 'pixel_id' => $pixel_id )
				);
			}

			$last = is_array( $json ) ? $json : array( 'raw' => $raw );
		}

		return null === $last ? array() : $last;
	}
}
