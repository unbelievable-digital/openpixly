<?php
/**
 * OpenAI Conversions API client.
 * https://developers.openai.com/ads/conversions-api
 *
 * POST https://bzr.openai.com/v1/events?pid=<PIXEL-ID>
 * Authorization: Bearer <API-KEY>
 *
 * Queueing, retries and logging live in OpenPixel_CAPI_Client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_OpenAI_CAPI extends OpenPixel_CAPI_Client {

	const ENDPOINT           = 'https://bzr.openai.com/v1/events';
	const ACTION_HOOK        = 'openpixel_openai_capi_send';
	const INTEGRATION_SOURCE = 'openpixly-wordpress';

	/** @var OpenPixel_Provider_OpenAI */
	private $provider;

	public function __construct( OpenPixel_Provider_OpenAI $provider ) {
		$this->provider = $provider;
		parent::__construct();
	}

	public function is_configured() {
		return (bool) $this->provider->get_setting( 'capi_api_key' ) && $this->provider->get_pixel_ids();
	}

	protected function build_event( array $event ) {
		return $this->provider->to_capi_event( $event );
	}

	protected function describe( array $api_event ) {
		return sprintf( '%s (%s)', $api_event['id'], $api_event['type'] );
	}

	protected function delivered( array $api_event, $result ) {
		do_action( 'openpixel_openai_capi_delivered', $api_event, $result );
	}

	/**
	 * Send a batch synchronously.
	 *
	 * @param array $events Up to 1000 Conversions API event objects.
	 * @param bool  $test   Sets validate_only: validate without saving.
	 * @return array|WP_Error Decoded response body on 2xx, WP_Error otherwise.
	 */
	public function send( array $events, $test = false ) {
		$api_key   = (string) $this->provider->get_setting( 'capi_api_key' );
		$pixel_ids = $this->provider->get_pixel_ids();

		if ( ! $api_key || ! $pixel_ids ) {
			return new WP_Error( 'openpixel_capi_unconfigured', __( 'Conversions API key or Pixel ID missing.', 'openpixly' ) );
		}

		$body = array(
			'validate_only'      => (bool) $test,
			'integration_source' => self::INTEGRATION_SOURCE,
			'events'             => array_values( $events ),
		);

		$last = null;
		foreach ( $pixel_ids as $pixel_id ) {
			$url = self::ENDPOINT . '?pid=' . rawurlencode( $pixel_id );

			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
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
				$message = is_array( $json ) && isset( $json['error'] )
					? wp_json_encode( $json['error'] )
					: substr( $raw, 0, 500 );

				return new WP_Error(
					'openpixel_capi_http_' . $code,
					sprintf( 'HTTP %d: %s', $code, $message ),
					array( 'status' => $code, 'pixel_id' => $pixel_id )
				);
			}

			$last = is_array( $json ) ? $json : array( 'raw' => $raw );
		}

		return null === $last ? array() : $last;
	}
}
