<?php
/**
 * Event debugger: a ring buffer of the last events the bus saw, with each
 * provider's payload and the Conversions API outcome, shown under
 * Settings > Pixel Manager > Events.
 *
 * Off by default; "Enable for 24 hours" turns it on, and it switches itself
 * off afterwards so a forgotten debugger does not keep writing options.
 * Raw customer data is never stored: the `user` field is reduced to the
 * list of keys that were present.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Event_Log {

	const OPTION       = 'openpixel_event_log';
	const OPTION_UNTIL = 'openpixel_event_log_until';
	const MAX_ENTRIES  = 50;
	const DURATION     = DAY_IN_SECONDS;

	/** @var OpenPixel_Core */
	private $core;

	public function __construct( OpenPixel_Core $core ) {
		$this->core = $core;
	}

	public function init() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		add_action( 'openpixel_event_tracked', array( $this, 'record_event' ), 10, 2 );
		add_action( 'openpixel_capi_result', array( $this, 'record_result' ), 10, 5 );
	}

	public function is_enabled() {
		return (int) get_option( self::OPTION_UNTIL, 0 ) > time();
	}

	public function enabled_until() {
		return (int) get_option( self::OPTION_UNTIL, 0 );
	}

	public function enable() {
		update_option( self::OPTION_UNTIL, time() + self::DURATION, false );
	}

	public function disable() {
		delete_option( self::OPTION_UNTIL );
	}

	public function clear() {
		delete_option( self::OPTION );
	}

	/** @return array Newest first. */
	public function get_entries() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? array_reverse( $entries ) : array();
	}

	/* ---------------------------------------------------------------------
	 * Recording
	 * ------------------------------------------------------------------ */

	public function record_event( array $event, $persist ) {
		$payloads = array();
		foreach ( $this->core->get_providers() as $id => $provider ) {
			if ( ! $provider->is_enabled() ) {
				continue;
			}
			if ( ! $this->core->provider_wants( $provider, $event ) ) {
				$payloads[ $id ] = 'source off';
				continue;
			}
			if ( 'server' === $event['channel'] ) {
				// The API payload is recorded by record_result() when it is sent.
				$payloads[ $id ] = $provider->get_setting( 'capi_enabled' ) ? 'queued' : 'capi off';
				continue;
			}
			$payload         = $provider->to_browser_payload( $event );
			$payloads[ $id ] = $payload ? $payload['args'] : 'skipped';
		}

		$entry = array(
			'time'     => time(),
			'name'     => $event['name'],
			'custom'   => $event['custom_name'],
			'event_id' => $event['event_id'],
			'channel'  => $event['channel'],
			'source'   => $event['source'],
			'persist'  => (bool) $persist,
			'request'  => $this->request_label(),
			'value'    => $event['value'],
			'currency' => $event['currency'],
			'items'    => count( $event['items'] ),
			'user'     => array_keys( array_filter( $event['user'] ) ),
			'payloads' => $payloads,
			'results'  => array(),
		);

		$this->append( $entry );
	}

	/**
	 * @param string         $provider_id
	 * @param array          $api_event
	 * @param array|WP_Error $result
	 * @param int            $attempt
	 * @param bool           $retry
	 */
	public function record_result( $provider_id, array $api_event, $result, $attempt, $retry ) {
		$provider = $this->core->get_provider( $provider_id );
		$event_id = '';
		if ( $provider && method_exists( $provider, 'capi' ) ) {
			$event_id = $provider->capi()->event_id_of( $api_event );
		}

		$outcome = array(
			'time'    => time(),
			'attempt' => (int) $attempt,
			'ok'      => ! is_wp_error( $result ),
			'message' => is_wp_error( $result ) ? $result->get_error_message() : '',
			'retry'   => (bool) $retry,
			'payload' => $this->redact( $api_event ),
		);

		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? $entries : array();

		for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
			if ( 'server' === $entries[ $i ]['channel'] && $entries[ $i ]['event_id'] === $event_id ) {
				$entries[ $i ]['results'][ $provider_id ] = $outcome;
				update_option( self::OPTION, $entries, false );
				return;
			}
		}

		// Event tracked before the debugger was enabled (or a retry of an old one).
		$this->append(
			array(
				'time'     => time(),
				'name'     => isset( $api_event['type'] ) ? $api_event['type'] : ( isset( $api_event['event_name'] ) ? $api_event['event_name'] : '' ),
				'custom'   => '',
				'event_id' => $event_id,
				'channel'  => 'server',
				'source'   => '',
				'persist'  => false,
				'request'  => $this->request_label(),
				'value'    => null,
				'currency' => '',
				'items'    => 0,
				'user'     => array(),
				'payloads' => array( $provider_id => 'queued' ),
				'results'  => array( $provider_id => $outcome ),
			)
		);
	}

	private function append( array $entry ) {
		$entries   = get_option( self::OPTION, array() );
		$entries   = is_array( $entries ) ? $entries : array();
		$entries[] = $entry;
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $entries, false );
	}

	/** Hashed user data is pseudonymous but still not worth keeping around. */
	private function redact( array $api_event ) {
		foreach ( array( 'user', 'user_data' ) as $key ) {
			if ( isset( $api_event[ $key ] ) && is_array( $api_event[ $key ] ) ) {
				$api_event[ $key ] = array_keys( $api_event[ $key ] );
			}
		}
		return $api_event;
	}

	private function request_label() {
		if ( wp_doing_cron() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return 'cron';
		}
		if ( wp_doing_ajax() ) {
			return 'ajax';
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}
		if ( is_admin() ) {
			return 'admin';
		}
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			return (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		}
		return '';
	}
}
