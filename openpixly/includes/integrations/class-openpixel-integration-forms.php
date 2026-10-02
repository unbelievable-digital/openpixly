<?php
/**
 * Lead form integrations: Contact Form 7, WPForms, Gravity Forms.
 *
 * Every successful submission becomes a `generate_lead` event on the bus
 * with `channel => 'both'`: the server copy goes out through the
 * Conversions APIs right away (forms usually submit over AJAX or redirect,
 * where a browser-only event is easily lost) and the browser copy is
 * persisted for the next page. For AJAX forms the browser payloads are
 * also returned inside the form's own response so assets/js/openpixel.js
 * can fire them immediately; both copies share the event_id.
 *
 * Customer data is read from the submitted fields by type (email / phone /
 * name fields) with a name-based fallback, and can be adjusted with the
 * `openpixel_lead_user` filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Integration_Forms {

	/** @var OpenPixel_Core */
	private $core;

	public function __construct( OpenPixel_Core $core ) {
		$this->core = $core;
	}

	public function init() {
		if ( ! $this->is_tracking_enabled() ) {
			return;
		}

		if ( class_exists( 'WPCF7' ) ) {
			// A valid, non-spam submission is the lead; whether SMTP later
			// delivers the notification is not the visitor's conversion.
			add_action( 'wpcf7_before_send_mail', array( $this, 'cf7_submitted' ) );
			add_filter( 'wpcf7_feedback_response', array( $this, 'cf7_response' ), 10, 2 );
		}

		if ( function_exists( 'wpforms' ) ) {
			add_action( 'wpforms_process_complete', array( $this, 'wpforms_submitted' ), 10, 4 );
			add_filter( 'wpforms_ajax_submit_success_response', array( $this, 'wpforms_response' ), 10, 3 );
		}

		if ( class_exists( 'GFForms' ) ) {
			add_action( 'gform_after_submission', array( $this, 'gf_submitted' ), 10, 2 );
			add_filter( 'gform_confirmation', array( $this, 'gf_confirmation' ), 10, 4 );
		}
	}

	private function is_tracking_enabled() {
		foreach ( $this->core->get_providers() as $provider ) {
			if ( $provider->is_enabled() ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Contact Form 7
	 * ------------------------------------------------------------------ */

	public function cf7_submitted( $contact_form ) {
		$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		$posted     = $submission ? (array) $submission->get_posted_data() : array();

		$values = array();
		foreach ( $posted as $key => $value ) {
			$values[ (string) $key ] = array( 'type' => $this->cf7_type( $contact_form, $key ), 'value' => $value );
		}

		$this->track_lead( 'cf7', (string) $contact_form->id(), (string) $contact_form->title(), '', $values );
	}

	private function cf7_type( $contact_form, $name ) {
		if ( ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return '';
		}
		foreach ( $contact_form->scan_form_tags( array( 'name' => $name ) ) as $tag ) {
			return (string) $tag->basetype; // email, tel, text, ...
		}
		return '';
	}

	/** Attach pending browser payloads to the REST feedback response (event.detail.apiResponse in JS). */
	public function cf7_response( $response, $result ) {
		if ( is_array( $response ) && isset( $response['status'] ) && in_array( $response['status'], array( 'mail_sent', 'mail_failed' ), true ) ) {
			$payloads = $this->pending_payloads();
			if ( $payloads ) {
				$response['openpixel'] = $payloads;
			}
		}
		return $response;
	}

	/* ---------------------------------------------------------------------
	 * WPForms
	 * ------------------------------------------------------------------ */

	public function wpforms_submitted( $fields, $entry, $form_data, $entry_id ) {
		$values = array();
		foreach ( (array) $fields as $id => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			$name = isset( $field['name'] ) ? (string) $field['name'] : (string) $id;

			if ( 'name' === $type && ( isset( $field['first'] ) || isset( $field['last'] ) ) ) {
				$values[ $name . ' first' ] = array( 'type' => 'first_name', 'value' => isset( $field['first'] ) ? $field['first'] : '' );
				$values[ $name . ' last' ]  = array( 'type' => 'last_name', 'value' => isset( $field['last'] ) ? $field['last'] : '' );
				continue;
			}
			$values[ $name ] = array( 'type' => $type, 'value' => isset( $field['value'] ) ? $field['value'] : '' );
		}

		$form_id = isset( $form_data['id'] ) ? (string) $form_data['id'] : '';
		$title   = isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : '';

		$this->track_lead( 'wpforms', $form_id, $title, (string) $entry_id, $values );
	}

	public function wpforms_response( $response, $form_id, $form_data ) {
		$payloads = $this->pending_payloads();
		if ( $payloads && is_array( $response ) ) {
			$response['openpixel'] = $payloads;
		}
		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Gravity Forms
	 * ------------------------------------------------------------------ */

	public function gf_submitted( $entry, $form ) {
		$values = array();
		foreach ( (array) $form['fields'] as $field ) {
			$id   = (string) $field->id;
			$type = (string) $field->type;

			if ( 'name' === $type ) {
				$values[ $id . '.3' ] = array( 'type' => 'first_name', 'value' => isset( $entry[ $id . '.3' ] ) ? $entry[ $id . '.3' ] : '' );
				$values[ $id . '.6' ] = array( 'type' => 'last_name', 'value' => isset( $entry[ $id . '.6' ] ) ? $entry[ $id . '.6' ] : '' );
				continue;
			}
			if ( isset( $entry[ $id ] ) ) {
				$label                   = (string) $field->label;
				$values[ $label ? $label : $id ] = array( 'type' => $type, 'value' => $entry[ $id ] );
			}
		}

		$this->track_lead( 'gf', (string) $form['id'], (string) $form['title'], isset( $entry['id'] ) ? (string) $entry['id'] : '', $values );
	}

	/** AJAX confirmations are injected into the page; a script tag inside them runs. */
	public function gf_confirmation( $confirmation, $form, $entry, $ajax ) {
		if ( ! $ajax || ! is_string( $confirmation ) ) {
			return $confirmation;
		}
		$payloads = $this->pending_payloads();
		if ( ! $payloads ) {
			return $confirmation;
		}
		return $confirmation . '<script>window.openPixelEvents=(window.openPixelEvents||[]).concat(' . wp_json_encode( $payloads ) . ');if(window.openPixel&&window.openPixel.flush){window.openPixel.flush();}</script>';
	}

	/* ---------------------------------------------------------------------
	 * Shared
	 * ------------------------------------------------------------------ */

	/**
	 * @param string $source   cf7 | wpforms | gf
	 * @param string $form_id
	 * @param string $title
	 * @param string $entry_id Empty when the plugin has no entries.
	 * @param array  $values   label => array( 'type' => string, 'value' => mixed )
	 */
	private function track_lead( $source, $form_id, $title, $entry_id, array $values ) {
		$user = $this->extract_user( $values );

		/**
		 * Adjust the customer data read from a lead form.
		 *
		 * @param array  $user    email, phone, first_name, last_name
		 * @param array  $values  label => array( type, value )
		 * @param string $source  cf7 | wpforms | gf
		 * @param string $form_id
		 */
		$user = apply_filters( 'openpixel_lead_user', $user, $values, $source, $form_id );

		$this->core->get_bus()->track(
			array(
				'name'         => 'generate_lead',
				'event_id'     => 'lead_' . $source . '_' . $form_id . '_' . ( $entry_id ? $entry_id : substr( md5( uniqid( '', true ) ), 0, 12 ) ),
				'content_type' => 'form',
				'items'        => array( array( 'id' => $form_id, 'name' => wp_strip_all_tags( $title ) ) ),
				'user'         => $user,
				'channel'      => 'both',
				'source'       => 'forms',
			),
			true
		);
	}

	/**
	 * Field type first (email / tel / phone / first_name / last_name / name),
	 * then the field label as a hint, then any value that is an email.
	 */
	private function extract_user( array $values ) {
		$user = array( 'email' => '', 'phone' => '', 'first_name' => '', 'last_name' => '' );

		foreach ( $values as $label => $field ) {
			$value = $this->scalar( $field['value'] );
			if ( '' === $value ) {
				continue;
			}
			$type  = strtolower( (string) $field['type'] );
			$label = strtolower( (string) $label );

			if ( ( 'email' === $type || false !== strpos( $label, 'email' ) || false !== strpos( $label, 'e-mail' ) ) && ! $user['email'] && is_email( $value ) ) {
				$user['email'] = $value;
			} elseif ( ( in_array( $type, array( 'tel', 'phone' ), true ) || false !== strpos( $label, 'phone' ) || false !== strpos( $label, 'tel' ) ) && ! $user['phone'] ) {
				$user['phone'] = $value;
			} elseif ( 'first_name' === $type || false !== strpos( $label, 'first' ) ) {
				$user['first_name'] = $user['first_name'] ? $user['first_name'] : $value;
			} elseif ( 'last_name' === $type || false !== strpos( $label, 'last' ) || false !== strpos( $label, 'surname' ) ) {
				$user['last_name'] = $user['last_name'] ? $user['last_name'] : $value;
			} elseif ( ( 'name' === $type || false !== strpos( $label, 'name' ) ) && ! $user['first_name'] && ! $user['last_name'] ) {
				$parts              = preg_split( '/\s+/', trim( $value ), 2 );
				$user['first_name'] = $parts[0];
				$user['last_name']  = isset( $parts[1] ) ? $parts[1] : '';
			}
		}

		if ( ! $user['email'] ) {
			foreach ( $values as $field ) {
				$value = $this->scalar( $field['value'] );
				if ( is_email( $value ) ) {
					$user['email'] = $value;
					break;
				}
			}
		}

		return array_filter( $user );
	}

	private function scalar( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** Browser payloads for events persisted during this request (AJAX responses). */
	private function pending_payloads() {
		$events = $this->core->get_bus()->drain_persisted();
		return $events ? $this->core->build_payloads( $events ) : array();
	}
}
