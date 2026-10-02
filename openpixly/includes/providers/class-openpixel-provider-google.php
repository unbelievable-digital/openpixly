<?php
/**
 * Google (Google Ads + Google Analytics 4) provider via the Google tag.
 *
 *  - loader https://www.googletagmanager.com/gtag/js?id=<first id>
 *  - gtag("consent", "default", { all denied }) before config when consent is required
 *    (https://developers.google.com/tag-platform/security/guides/consent)
 *  - gtag("config", id) per Measurement ID (G-…) and Conversion ID (AW-…)
 *  - gtag("set", "user_data", { sha256_… }) for enhanced conversions
 *    (https://support.google.com/google-ads/answer/13258081)
 *  - GA4 ecommerce events (https://developers.google.com/analytics/devguides/collection/ga4/ecommerce)
 *    and Google Ads conversions gtag("event", "conversion", { send_to: "AW-…/label" })
 *
 * Browser only: Google Ads offline conversion imports need OAuth and are out
 * of scope. GA4's config sends page_view itself, so the bus page_view is skipped.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Provider_Google extends OpenPixel_Provider {

	const SDK_URL = 'https://www.googletagmanager.com/gtag/js';

	/** Bus event name => GA4 event name. page_view is sent by config. */
	const EVENT_MAP = array(
		'view_item'      => 'view_item',
		'add_to_cart'    => 'add_to_cart',
		'begin_checkout' => 'begin_checkout',
		'purchase'       => 'purchase',
		'sign_up'        => 'sign_up',
		'generate_lead'  => 'generate_lead',
		'schedule'       => 'schedule',
		'subscribe'      => 'subscribe',
		'start_trial'    => 'start_trial',
	);

	/** Bus events that can carry a Google Ads conversion label. */
	const CONVERSION_EVENTS = array( 'purchase', 'sign_up', 'generate_lead', 'begin_checkout', 'add_to_cart' );

	const CONSENT_TYPES = array( 'ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage' );

	public function get_id() {
		return 'google';
	}

	public function get_label() {
		return __( 'Google (Ads & Analytics 4)', 'openpixly' );
	}

	public function get_description() {
		return __( 'Enter a GA4 Measurement ID (G-…) and/or a Google Ads Conversion ID (AW-…). Ads conversions are only sent for events with a conversion label; find labels under Goals > Conversions > the action > Tag setup.', 'openpixly' );
	}

	public function get_fields() {
		$fields = array(
			'enabled'           => array(
				'label'   => __( 'Enable Google tag', 'openpixly' ),
				'type'    => 'checkbox',
				'default' => false,
			),
			'ga4_id'            => array(
				'label'       => __( 'GA4 Measurement ID', 'openpixly' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'G-XXXXXXXXXX. Receives page_view (sent by the tag itself), view_item, add_to_cart, begin_checkout, purchase, sign_up and generate_lead. Comma-separate several IDs.', 'openpixly' ),
			),
			'ads_id'            => array(
				'label'       => __( 'Google Ads Conversion ID', 'openpixly' ),
				'type'        => 'text',
				'default'     => '',
				'description' => __( 'AW-XXXXXXXXX. Required for the conversion labels below.', 'openpixly' ),
			),
			'exclude_admins'    => array(
				'label'       => __( 'Do not track administrators', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Skip the tag entirely for logged-in users who can manage options.', 'openpixly' ),
			),
			'consent_mode'      => array(
				'label'       => __( 'Consent', 'openpixly' ),
				'type'        => 'select',
				'default'     => 'default',
				'options'     => array(
					'default' => __( 'Measure immediately (SDK default)', 'openpixly' ),
					'require' => __( 'Require consent first (Consent Mode v2, all types denied)', 'openpixly' ),
				),
				'description' => __( 'With "Require consent", the tag starts with ad_storage, ad_user_data, ad_personalization and analytics_storage denied. Grant them from your cookie banner by calling window.openPixel.grantConsent(), or via the WP Consent API ("marketing" category), which is detected automatically.', 'openpixly' ),
			),
			'advanced_matching' => array(
				'label'       => __( 'Send hashed customer data', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'Enhanced conversions. Email, phone and names are normalized and SHA-256 hashed on the server; city, region, postal code and country are sent as plain text, as Google requires. Turn on "Enhanced conversions" for the conversion action in Google Ads too.', 'openpixly' ),
			),
			'woocommerce'       => array(
				'section'     => __( 'WooCommerce', 'openpixly' ),
				'label'       => __( 'Track WooCommerce events', 'openpixly' ),
				'type'        => 'checkbox',
				'default'     => true,
				'description' => __( 'GA4 ecommerce events with items[] (item_id = product id, item_variant from attributes) and the Ads conversions configured below.', 'openpixly' ),
			),
		);

		$labels = array(
			'purchase'       => __( 'Purchase conversion label', 'openpixly' ),
			'sign_up'        => __( 'Sign-up conversion label', 'openpixly' ),
			'generate_lead'  => __( 'Lead conversion label', 'openpixly' ),
			'begin_checkout' => __( 'Begin checkout conversion label', 'openpixly' ),
			'add_to_cart'    => __( 'Add to cart conversion label', 'openpixly' ),
		);
		$first = true;
		foreach ( $labels as $event => $label ) {
			$fields[ 'label_' . $event ] = array(
				'label'       => $label,
				'type'        => 'text',
				'default'     => '',
				'description' => $first ? __( 'The part after the slash in "AW-XXXXXXXXX/AbC-dEfGhIjK". Leave blank to send no Ads conversion for this event.', 'openpixly' ) : '',
			);
			if ( $first ) {
				$fields[ 'label_' . $event ]['section'] = __( 'Google Ads conversion labels', 'openpixly' );
				$first = false;
			}
		}

		return $fields;
	}

	private function ids( $key ) {
		$raw = (string) $this->get_setting( $key, '' );
		$ids = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return array_values( array_unique( $ids ) );
	}

	public function get_ga4_ids() {
		return $this->ids( 'ga4_id' );
	}

	public function get_ads_id() {
		$ids = $this->ids( 'ads_id' );
		return $ids ? $ids[0] : '';
	}

	public function get_all_ids() {
		return array_values( array_unique( array_merge( $this->get_ga4_ids(), $this->ids( 'ads_id' ) ) ) );
	}

	public function should_render() {
		if ( ! $this->get_all_ids() ) {
			return false;
		}
		if ( $this->get_setting( 'exclude_admins' ) && current_user_can( 'manage_options' ) ) {
			return false;
		}
		return true;
	}

	private function consent_pending() {
		return 'require' === $this->get_setting( 'consent_mode' )
			&& ! apply_filters( 'openpixel_consent_granted', false, $this->get_id() );
	}

	/* ---------------------------------------------------------------------
	 * Browser output
	 * ------------------------------------------------------------------ */

	public function render_head( OpenPixel_Event_Bus $bus ) {
		$ids        = $this->get_all_ids();
		$nonce      = apply_filters( 'openpixel_script_nonce', '' );
		$nonce_attr = $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';

		$user = array();
		if ( $this->get_setting( 'advanced_matching' ) && $bus->get_user() ) {
			$user = OpenPixel_Hash::google_user( $bus->get_user() );
		}

		echo "\n<!-- Google tag (Openpixly plugin) -->\n";
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript, WordPress.Security.EscapeOutput.OutputNotEscaped -- the tag must sit in <head> before any gtag() call; $nonce_attr is built with esc_attr() above.
		echo '<script async' . $nonce_attr . ' src="' . esc_url( self::SDK_URL . '?id=' . rawurlencode( $ids[0] ) ) . '"></script>' . "\n";
		echo '<script' . $nonce_attr . ">\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo "window.dataLayer = window.dataLayer || [];\n";
		echo "function gtag(){dataLayer.push(arguments);}\n";

		if ( $this->consent_pending() ) {
			echo 'gtag("consent", "default", ' . wp_json_encode( array_fill_keys( self::CONSENT_TYPES, 'denied' ) ) . ");\n";
		}

		echo "gtag(\"js\", new Date());\n";

		foreach ( $ids as $id ) {
			$config = array();
			if ( 0 === strpos( $id, 'AW-' ) && $this->get_setting( 'advanced_matching' ) ) {
				$config['allow_enhanced_conversions'] = true;
			}
			echo 'gtag("config", ' . wp_json_encode( $id ) . ( $config ? ', ' . wp_json_encode( $config ) : '' ) . ");\n";
		}

		if ( $user ) {
			echo 'gtag("set", "user_data", ' . wp_json_encode( $user ) . ");\n";
		}

		echo "</script>\n<!-- / Google tag -->\n";
	}

	public function skip_reason( array $event ) {
		if ( 'page_view' === $event['name'] ) {
			return __( 'page_view is sent by gtag("config") itself', 'openpixly' );
		}
		return parent::skip_reason( $event );
	}

	public function to_browser_payload( array $event ) {
		if ( 'page_view' === $event['name'] ) {
			return null; // gtag("config") sends page_view itself.
		}

		$name = 'custom' === $event['name'] ? $event['custom_name'] : ( isset( self::EVENT_MAP[ $event['name'] ] ) ? self::EVENT_MAP[ $event['name'] ] : '' );
		if ( '' === $name ) {
			return null;
		}

		$event_id = ! empty( $event['event_id'] ) ? (string) $event['event_id'] : '';
		$payloads = array();

		$ga4 = $this->get_ga4_ids();
		if ( $ga4 ) {
			$params            = $this->build_params( $event );
			$params['send_to'] = $ga4;
			$payloads[]        = array(
				'provider' => $this->get_id(),
				'event_id' => $event_id,
				'args'     => array( 'event', $name, $params ),
			);
		}

		$ads   = $this->get_ads_id();
		$label = in_array( $event['name'], self::CONVERSION_EVENTS, true ) ? trim( (string) $this->get_setting( 'label_' . $event['name'], '' ) ) : '';
		if ( $ads && $label ) {
			$conversion = array( 'send_to' => $ads . '/' . $label );
			if ( null !== $event['value'] && $event['currency'] ) {
				$conversion['value']    = $this->money( $event['value'], $event['currency'] );
				$conversion['currency'] = $event['currency'];
			}
			if ( 'purchase' === $event['name'] && 0 === strpos( $event_id, 'order_' ) ) {
				$conversion['transaction_id'] = substr( $event_id, 6 );
			}
			$payloads[] = array(
				'provider' => $this->get_id(),
				// Distinct replay-dedup key: the GA4 call above shares the event id.
				'event_id' => $event_id ? $event_id . ':ads' : '',
				'args'     => array( 'event', 'conversion', $conversion ),
			);
		}

		return $payloads ? $payloads : null;
	}

	/**
	 * GA4 event parameters: value, currency, items[], transaction_id.
	 */
	public function build_params( array $event ) {
		$params = array();

		if ( null !== $event['value'] && $event['currency'] ) {
			$params['value']    = $this->money( $event['value'], $event['currency'] );
			$params['currency'] = $event['currency'];
		}
		if ( 'purchase' === $event['name'] && 0 === strpos( (string) $event['event_id'], 'order_' ) ) {
			$params['transaction_id'] = substr( (string) $event['event_id'], 6 );
		}
		if ( $event['plan_id'] ) {
			$params['item_name'] = (string) $event['plan_id'];
		}

		$items = array();
		foreach ( $event['items'] as $item ) {
			if ( '' === $item['id'] ) {
				continue;
			}
			$row = array( 'item_id' => $item['id'] );
			if ( '' !== $item['name'] ) {
				$row['item_name'] = $item['name'];
			}
			if ( null !== $item['price'] && $event['currency'] ) {
				$row['price'] = $this->money( $item['price'], $event['currency'] );
			}
			if ( $item['quantity'] > 0 ) {
				$row['quantity'] = $item['quantity'];
			}
			if ( $item['variant'] ) {
				$row['item_variant'] = implode( ' / ', array_map( 'strval', array_filter( $item['variant'], 'is_scalar' ) ) );
			}
			$items[] = $row;
		}
		if ( $items && in_array( $event['content_type'], array( '', 'product' ), true ) ) {
			$params['items'] = $items;
		}

		return $params;
	}

	private function money( $amount, $currency ) {
		return round( (float) $amount, OpenPixel_Money::exponent( $currency ) );
	}
}
