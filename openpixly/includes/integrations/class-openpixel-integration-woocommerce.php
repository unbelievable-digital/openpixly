<?php
/**
 * WooCommerce integration.
 *
 * Emits provider-agnostic events on the bus:
 *   view_item       – single product page
 *   add_to_cart     – woocommerce_add_to_cart (classic, AJAX and Store API/blocks)
 *   begin_checkout  – checkout page
 *   purchase        – order-received page (browser) + payment complete (server)
 *   sign_up         – handled by core via user_register
 *
 * Also captures every provider's attribution cookies (OpenAI `__oppref` /
 * `__obref`, Meta `_fbp` / `_fbc`, ...) at checkout so the server-side event
 * can carry them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Integration_WooCommerce {

	const META_PIXEL_FIRED = '_openpixel_pixel_fired';
	const META_CAPI_QUEUED = '_openpixel_capi_queued';
	const META_ATTRIBUTION = '_openpixel_'; // + context key, e.g. _openpixel_oppref

	/** @var OpenPixel_Core */
	private $core;

	public function __construct( OpenPixel_Core $core ) {
		$this->core = $core;
	}

	public function init() {
		if ( ! $this->is_tracking_enabled() ) {
			return;
		}

		// Browser events.
		add_filter( 'openpixel_page_view_item', array( $this, 'label_wc_pages' ) );
		add_action( 'openpixel_prepare', array( $this, 'prepare_page_events' ), 10, 1 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'track_add_to_cart' ), 10, 6 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_pending_events_fragment' ) );

		// Attribution cookies -> order meta.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_attribution_on_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'store_attribution_on_order' ), 10, 1 );

		// Server-side purchase.
		add_action( 'woocommerce_payment_complete', array( $this, 'track_server_purchase' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'track_server_purchase' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'track_server_purchase' ) );
	}

	/**
	 * At least one enabled provider wants WooCommerce events. Providers
	 * without a "woocommerce" setting are treated as opted in.
	 */
	private function is_tracking_enabled() {
		foreach ( $this->core->get_providers() as $provider ) {
			if ( ! $provider->is_enabled() ) {
				continue;
			}
			if ( false !== $provider->get_setting( 'woocommerce', true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Page-level events (run before <head>)
	 * ------------------------------------------------------------------ */

	public function prepare_page_events( OpenPixel_Event_Bus $bus ) {
		if ( is_order_received_page() ) {
			$this->prepare_thank_you( $bus );
			return;
		}

		if ( is_product() ) {
			$this->prepare_product_view( $bus );
			return;
		}

		if ( is_checkout() ) {
			$this->prepare_checkout( $bus );
		}
	}

	/**
	 * WooCommerce endpoints all render under the Checkout/My Account pages;
	 * give the ones that matter for attribution their own page ids.
	 */
	public function label_wc_pages( $item ) {
		if ( is_order_received_page() ) {
			return array( 'id' => 'order-received', 'name' => 'Order received' );
		}
		if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
			return array( 'id' => 'order-pay', 'name' => 'Order payment' );
		}
		return $item;
	}

	private function prepare_product_view( OpenPixel_Event_Bus $bus ) {
		// A classic (non-AJAX) add-to-cart re-renders the product page in the
		// same request; that reload is not a new product view.
		if ( did_action( 'woocommerce_add_to_cart' ) || ! empty( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) {
			return;
		}

		$bus->track(
			array(
				'name'         => 'view_item',
				'currency'     => get_woocommerce_currency(),
				'value'        => (float) wc_get_price_to_display( $product ),
				'content_type' => 'product',
				'source'       => 'woocommerce',
				'items'        => array( $this->product_item( $product, 1 ) ),
			)
		);
	}

	private function prepare_checkout( OpenPixel_Event_Bus $bus ) {
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return;
		}

		$items = array();
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$items[] = $this->product_item( $product, (int) $cart_item['quantity'], $cart_item );
		}

		$bus->track(
			array(
				'name'         => 'begin_checkout',
				'event_id'     => 'checkout_' . $cart->get_cart_hash(),
				'currency'     => get_woocommerce_currency(),
				'value'        => (float) $cart->get_total( 'edit' ),
				'content_type' => 'product',
				'source'       => 'woocommerce',
				'items'        => $items,
			)
		);
	}

	private function prepare_thank_you( OpenPixel_Event_Bus $bus ) {
		$order = $this->get_order_from_received_page();
		if ( ! $order ) {
			return;
		}

		// Billing details improve matching even for guests.
		$bus->set_user( $this->order_user_data( $order ) );

		if ( $order->get_meta( self::META_PIXEL_FIRED ) ) {
			return; // page refresh — only fire the purchase once
		}

		$bus->track(
			array(
				'name'         => 'purchase',
				'event_id'     => $this->purchase_event_id( $order ),
				'currency'     => $order->get_currency(),
				'value'        => (float) $order->get_total(),
				'content_type' => 'product',
				'source'       => 'woocommerce',
				'items'        => $this->order_items( $order ),
			)
		);

		$order->update_meta_data( self::META_PIXEL_FIRED, time() );
		$order->save_meta_data();
	}

	private function get_order_from_received_page() {
		$order_id = absint( get_query_var( 'order-received' ) );
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- order key is the credential here, checked with hash_equals below.
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}

		return $order;
	}

	/* ---------------------------------------------------------------------
	 * Add to cart (persisted: the response is usually AJAX or a redirect)
	 * ------------------------------------------------------------------ */

	public function track_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return;
		}

		$quantity = max( 1, (int) $quantity );
		$price    = (float) wc_get_price_to_display( $product );

		$item            = $this->product_item( $product, $quantity );
		$item['variant'] = $this->variation_attributes( $variation );

		$this->core->get_bus()->track(
			array(
				'name'         => 'add_to_cart',
				'event_id'     => 'cart_' . $cart_item_key . '_' . time(),
				'currency'     => get_woocommerce_currency(),
				'value'        => $price * $quantity,
				'content_type' => 'product',
				'source'       => 'woocommerce',
				'items'        => array( $item ),
			),
			true
		);
	}

	/**
	 * Classic AJAX add-to-cart: ship pending events back inside the
	 * fragments response so assets/js/openpixel.js can fire them immediately.
	 */
	public function add_pending_events_fragment( $fragments ) {
		$events = $this->core->get_bus()->drain_persisted();
		if ( ! $events ) {
			return $fragments;
		}

		$payloads = $this->core->build_payloads( $events );
		if ( ! $payloads ) {
			return $fragments;
		}

		$fragments['#openpixel-pending'] = '<div id="openpixel-pending" hidden data-openpixel-events="' . esc_attr( wp_json_encode( $payloads ) ) . '"></div>';

		return $fragments;
	}

	/* ---------------------------------------------------------------------
	 * Server-side purchase (Conversions API)
	 * ------------------------------------------------------------------ */

	public function store_attribution_on_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		foreach ( $this->attribution_cookies() as $key => $cookie ) {
			$value = isset( $_COOKIE[ $cookie ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) : '';
			if ( '' !== $value ) {
				$order->update_meta_data( self::META_ATTRIBUTION . $key, $value );
			}
		}
	}

	/**
	 * Attribution cookies of all enabled providers: context key => cookie name.
	 */
	private function attribution_cookies() {
		$cookies = array();
		foreach ( $this->core->get_providers() as $provider ) {
			if ( $provider->is_enabled() ) {
				$cookies = array_merge( $cookies, $provider->get_attribution_cookies() );
			}
		}
		return $cookies;
	}

	public function track_server_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_CAPI_QUEUED ) ) {
			return;
		}

		$paid_at = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();

		$attribution = array();
		foreach ( array_keys( $this->attribution_cookies() ) as $key ) {
			$attribution[ $key ] = $order->get_meta( self::META_ATTRIBUTION . $key );
		}

		$this->core->get_bus()->track(
			array(
				'name'         => 'purchase',
				'event_id'     => $this->purchase_event_id( $order ),
				'currency'     => $order->get_currency(),
				'value'        => (float) $order->get_total(),
				'content_type' => 'product',
				'source'       => 'woocommerce',
				'items'        => $this->order_items( $order, true ),
				'user'         => $this->order_user_data( $order ),
				'channel'      => 'server',
				'context'      => array_merge(
					$attribution,
					array(
						'timestamp_ms'  => $paid_at ? $paid_at->getTimestamp() * 1000 : (int) round( microtime( true ) * 1000 ),
						'action_source' => 'web',
						'source_url'    => $order->get_checkout_order_received_url(),
						'ip_address'    => $order->get_customer_ip_address(),
						'user_agent'    => $order->get_customer_user_agent(),
					)
				),
			)
		);

		$order->update_meta_data( self::META_CAPI_QUEUED, time() );
		$order->save_meta_data();
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function purchase_event_id( WC_Order $order ) {
		return 'order_' . $order->get_id();
	}

	private function product_item( WC_Product $product, $quantity, $cart_item = null ) {
		$item = array(
			'id'       => (string) $product->get_id(),
			'name'     => wp_strip_all_tags( $product->get_name() ),
			'quantity' => (int) $quantity,
			'price'    => (float) wc_get_price_to_display( $product ),
		);

		if ( $product->get_parent_id() ) {
			$item['group_id'] = (string) $product->get_parent_id();
		}

		if ( $cart_item && ! empty( $cart_item['variation'] ) ) {
			$item['variant'] = $this->variation_attributes( $cart_item['variation'] );
		}

		return apply_filters( 'openpixel_wc_product_item', $item, $product, $quantity );
	}

	private function order_items( WC_Order $order, $with_variants = false ) {
		$items = array();

		foreach ( $order->get_items( 'line_item' ) as $line ) {
			$product  = $line->get_product();
			$quantity = (int) $line->get_quantity();
			$unit     = $quantity > 0 ? (float) $line->get_total() / $quantity : 0;

			$item = array(
				'id'       => (string) ( $line->get_variation_id() ? $line->get_variation_id() : $line->get_product_id() ),
				'name'     => wp_strip_all_tags( $line->get_name() ),
				'quantity' => $quantity,
				'price'    => $unit,
			);

			if ( $line->get_variation_id() ) {
				$item['group_id'] = (string) $line->get_product_id();
			}

			if ( $with_variants && $product instanceof WC_Product_Variation ) {
				$item['variant'] = $this->variation_attributes( $product->get_variation_attributes() );
			}

			$items[] = apply_filters( 'openpixel_wc_product_item', $item, $product, $quantity );
		}

		return $items;
	}

	private function variation_attributes( $attributes ) {
		$variant = array();
		foreach ( (array) $attributes as $key => $value ) {
			if ( '' === $value || ! is_scalar( $value ) ) {
				continue;
			}
			$key             = str_replace( array( 'attribute_pa_', 'attribute_' ), '', (string) $key );
			$variant[ $key ] = (string) $value;
		}
		return $variant;
	}

	private function order_user_data( WC_Order $order ) {
		return array(
			'email'       => $order->get_billing_email(),
			'phone'       => $order->get_billing_phone(),
			'external_id' => $order->get_customer_id() ? (string) $order->get_customer_id() : '',
			'first_name'  => $order->get_billing_first_name(),
			'last_name'   => $order->get_billing_last_name(),
			'country'     => $order->get_billing_country(),
			'city'        => $order->get_billing_city(),
			'region'      => $order->get_billing_state(),
			'postal_code' => $order->get_billing_postcode(),
		);
	}
}
