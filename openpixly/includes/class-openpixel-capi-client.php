<?php
/**
 * Shared delivery logic for server-to-server conversion APIs.
 *
 * Events are delivered asynchronously (Action Scheduler when WooCommerce
 * ships it, WP-Cron otherwise) with a few retries, and logged to the
 * WooCommerce logger under the "openpixly" source when available.
 *
 * Subclasses define ACTION_HOOK and must be instantiated eagerly (provider
 * constructor): the callback is registered here, and cron requests never
 * reach a lazily created client ("no callbacks are registered" failures).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class OpenPixel_CAPI_Client {

	const MAX_ATTEMPTS = 4;

	public function __construct() {
		add_action( static::ACTION_HOOK, array( $this, 'deliver' ), 10, 2 );
	}

	/** Credentials present. */
	abstract public function is_configured();

	/**
	 * Map a normalized bus event to this API's event object, or null to skip.
	 *
	 * @param array $event Normalized bus event (channel = server).
	 * @return array|null
	 */
	abstract protected function build_event( array $event );

	/**
	 * Send a batch synchronously.
	 *
	 * @param array $events API event objects.
	 * @param bool  $test   Validate / test mode; nothing is recorded as a real conversion.
	 * @return array|WP_Error Decoded response body on 2xx, WP_Error (data: status) otherwise.
	 */
	abstract public function send( array $events, $test = false );

	/** Short "id (type)" label for log lines. */
	abstract protected function describe( array $api_event );

	/** Called after a successful delivery. */
	protected function delivered( array $api_event, $result ) {}

	/**
	 * Queue a normalized server event for async delivery.
	 *
	 * @param array $event Normalized bus event.
	 */
	public function queue_event( array $event ) {
		if ( ! $this->is_configured() ) {
			$this->log( 'Conversions API not configured (missing credentials or Pixel ID); dropping event ' . $event['name'] . '.', 'warning' );
			return;
		}

		$api_event = $this->build_event( $event );
		if ( ! $api_event ) {
			return;
		}

		$this->schedule( $api_event, 1, 0 );
	}

	private function schedule( array $api_event, $attempt, $delay ) {
		$args = array( $api_event, (int) $attempt );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, static::ACTION_HOOK, $args, 'openpixly' );
			return;
		}

		wp_schedule_single_event( time() + $delay, static::ACTION_HOOK, $args );
	}

	/**
	 * Action Scheduler / WP-Cron callback.
	 *
	 * @param array $api_event API event object.
	 * @param int   $attempt   1-based attempt counter.
	 */
	public function deliver( $api_event, $attempt = 1 ) {
		if ( ! is_array( $api_event ) ) {
			return;
		}

		$result = $this->send( array( $api_event ), false );

		if ( is_wp_error( $result ) ) {
			$this->log(
				sprintf( 'Event %s attempt %d failed: %s', $this->describe( $api_event ), $attempt, $result->get_error_message() ),
				'error'
			);

			if ( $attempt < self::MAX_ATTEMPTS && $this->is_retryable( $result ) ) {
				$this->schedule( $api_event, $attempt + 1, 5 * MINUTE_IN_SECONDS * $attempt );
			}
			return;
		}

		$this->log( sprintf( 'Event %s delivered.', $this->describe( $api_event ) ), 'info' );
		$this->delivered( $api_event, $result );
	}

	private function is_retryable( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$status = (int) $data['status'];
			// 4xx (other than 408/429) means the payload is wrong; retrying won't help.
			return $status >= 500 || 408 === $status || 429 === $status;
		}
		return true; // network errors
	}

	public function log( $message, $level = 'info' ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'openpixly' ) );
			return;
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[openpixly] ' . strtoupper( $level ) . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
