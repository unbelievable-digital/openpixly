<?php
/**
 * Wires providers, the event bus, integrations and front-end output together.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenPixel_Core {

	/** @var OpenPixel_Provider[] */
	private $providers = array();

	/** @var OpenPixel_Event_Bus */
	private $bus;

	public function __construct() {
		$this->bus = new OpenPixel_Event_Bus();
	}

	public function init() {
		$this->register_providers();

		add_action( 'openpixel_server_event', array( $this, 'dispatch_server_event' ) );

		// Registrations that happen outside WooCommerce (wp-login.php, membership plugins, ...).
		add_action( 'user_register', array( $this, 'track_registration' ), 20 );

		if ( $this->is_frontend() ) {
			add_action( 'wp', array( $this, 'prepare_frontend' ), 5 );
			add_action( 'wp_head', array( $this, 'output_head' ), 1 );
			// Priority 20: WP Consent API registers its script at 10, and our
			// runtime must load after it (issue #1).
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
			add_filter( 'openpixel_consent_granted', array( $this, 'consent_from_wp_consent_api' ), 5, 2 );
			add_action( 'wp_footer', array( $this, 'output_footer' ), 30 );
		}

		if ( class_exists( 'WooCommerce' ) ) {
			$wc = new OpenPixel_Integration_WooCommerce( $this );
			$wc->init();
		}

		$this->feed = new OpenPixel_Product_Feed();
		$this->feed->init();

		do_action( 'openpixel_init', $this );
	}

	/* ---------------------------------------------------------------------
	 * Providers
	 * ------------------------------------------------------------------ */

	private function register_providers() {
		$providers = array( new OpenPixel_Provider_OpenAI(), new OpenPixel_Provider_Meta() );

		/**
		 * Register additional pixel providers (Google, TikTok, ...).
		 *
		 * @param OpenPixel_Provider[] $providers
		 */
		$providers = apply_filters( 'openpixel_pixel_providers', $providers );

		$all_settings = get_option( OPENPIXEL_OPTION_KEY, array() );

		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof OpenPixel_Provider ) {
				continue;
			}
			$id    = $provider->get_id();
			$saved = isset( $all_settings[ $id ] ) && is_array( $all_settings[ $id ] ) ? $all_settings[ $id ] : array();
			$provider->set_settings( $saved );
			$this->providers[ $id ] = $provider;
		}
	}

	/** @return OpenPixel_Provider[] */
	public function get_providers() {
		return $this->providers;
	}

	/** @return OpenPixel_Provider|null */
	public function get_provider( $id ) {
		return isset( $this->providers[ $id ] ) ? $this->providers[ $id ] : null;
	}

	/** Providers that are enabled and want to render on this request. */
	private function get_active_providers() {
		$active = array();
		foreach ( $this->providers as $id => $provider ) {
			if ( $provider->is_enabled() && $provider->should_render() ) {
				$active[ $id ] = $provider;
			}
		}
		return $active;
	}

	/** @var OpenPixel_Product_Feed */
	private $feed;

	public function get_feed() {
		return $this->feed;
	}

	public function get_bus() {
		return $this->bus;
	}

	/* ---------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	public function dispatch_server_event( array $event ) {
		foreach ( $this->providers as $provider ) {
			if ( $provider->is_enabled() && $this->provider_wants( $provider, $event ) ) {
				$provider->handle_server_event( $event );
			}
		}
	}

	/**
	 * Events tagged with a `source` (e.g. "woocommerce") only reach providers
	 * whose setting of that name is on. Providers without such a setting are
	 * treated as opted in.
	 */
	private function provider_wants( OpenPixel_Provider $provider, array $event ) {
		if ( empty( $event['source'] ) ) {
			return true;
		}
		return false !== $provider->get_setting( $event['source'], true );
	}

	public function track_registration( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$this->bus->track(
			array(
				'name'     => 'sign_up',
				'event_id' => 'reg_' . $user_id,
				'user'     => array(
					'email'       => $user->user_email,
					'external_id' => (string) $user_id,
					'first_name'  => $user->first_name,
					'last_name'   => $user->last_name,
				),
				'_user_id' => $user_id,
			),
			true
		);
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	private function is_frontend() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		return true;
	}

	/**
	 * Runs once the main query is known: set user data for advanced
	 * matching and queue page_viewed. Integrations hook `openpixel_prepare`.
	 */
	public function prepare_frontend() {
		if ( is_feed() || is_embed() || is_robots() || is_trackback() ) {
			return;
		}

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$this->bus->set_user(
				array(
					'email'       => $user->user_email,
					'external_id' => (string) $user->ID,
					'first_name'  => $user->first_name,
					'last_name'   => $user->last_name,
				)
			);
		}

		if ( apply_filters( 'openpixel_track_page_view', true ) ) {
			$this->bus->track( $this->build_page_view_event() );
		}

		/**
		 * Integrations add page-specific events / user data here, before
		 * <head> is printed.
		 */
		do_action( 'openpixel_prepare', $this->bus, $this );
	}

	private function build_page_view_event() {
		$item = array( 'id' => '', 'name' => '' );

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post ) {
				$item['id']   = (string) $post->ID;
				$item['name'] = wp_strip_all_tags( get_the_title( $post ) );
			}
		} elseif ( is_front_page() || is_home() ) {
			$item['id']   = 'home';
			$item['name'] = wp_strip_all_tags( get_bloginfo( 'name' ) );
		} elseif ( is_archive() ) {
			$item['id']   = 'archive';
			$item['name'] = wp_strip_all_tags( get_the_archive_title() );
		} elseif ( is_search() ) {
			$item['id']   = 'search';
			$item['name'] = 'Search';
		} elseif ( is_404() ) {
			$item['id']   = '404';
			$item['name'] = 'Not found';
		}

		/**
		 * Let integrations relabel the page (e.g. WooCommerce's order-received
		 * endpoint is technically the Checkout page).
		 *
		 * @param array $item array( 'id' => string, 'name' => string )
		 */
		$item = apply_filters( 'openpixel_page_view_item', $item );

		return array(
			'name'         => 'page_view',
			'content_type' => 'page',
			'items'        => ! empty( $item['id'] ) ? array( $item ) : array(),
		);
	}

	public function enqueue_assets() {
		if ( ! $this->get_active_providers() ) {
			return;
		}

		// Depend on wp-consent-api when present so window.wp_has_consent exists
		// before openpixel.js runs; footer script order is otherwise per template.
		$deps = wp_script_is( 'wp-consent-api', 'registered' ) ? array( 'wp-consent-api' ) : array();

		wp_enqueue_script(
			'openpixel',
			OPENPIXEL_PLUGIN_URL . 'assets/js/openpixel.js',
			$deps,
			OPENPIXEL_VERSION,
			true
		);

		$config = array(
			'providers' => array(),
		);
		foreach ( $this->get_active_providers() as $id => $provider ) {
			$config['providers'][ $id ] = array(
				'consentMode' => $provider->get_setting( 'consent_mode', 'default' ),
			);
		}

		wp_add_inline_script( 'openpixel', 'window.openPixelConfig = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Default for the `openpixel_consent_granted` filter: when a consent
	 * management plugin has registered with the WP Consent API, trust its
	 * "marketing" category on the server so providers do not print
	 * consent=false on a page the visitor already opted in to.
	 *
	 * Only when a consent type is set: without a CMP, wp_has_consent()
	 * returns true for everything, which would defeat "Require consent".
	 */
	public function consent_from_wp_consent_api( $granted, $provider_id ) {
		if ( $granted || ! function_exists( 'wp_has_consent' ) || ! function_exists( 'wp_get_consent_type' ) ) {
			return $granted;
		}
		if ( ! wp_get_consent_type() ) {
			return $granted;
		}
		return (bool) wp_has_consent( 'marketing' );
	}

	public function output_head() {
		foreach ( $this->get_active_providers() as $provider ) {
			$provider->render_head( $this->bus );
		}
	}

	public function output_footer() {
		$active = $this->get_active_providers();
		if ( ! $active ) {
			return;
		}

		foreach ( $active as $provider ) {
			$provider->render_footer( $this->bus );
		}

		$payloads = $this->build_payloads( $this->bus->drain_browser_events(), $active );

		// Placeholder replaced by WooCommerce AJAX fragments (see integration).
		echo '<div id="openpixel-pending" hidden></div>' . "\n";

		if ( ! $payloads ) {
			return;
		}

		$nonce      = apply_filters( 'openpixel_script_nonce', '' );
		$nonce_attr = $nonce ? ' nonce="' . esc_attr( $nonce ) . '"' : '';

		echo '<script' . $nonce_attr . '>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. 'window.openPixelEvents=(window.openPixelEvents||[]).concat(' . wp_json_encode( $payloads ) . ');'
			. 'if(window.openPixel&&window.openPixel.flush){window.openPixel.flush();}'
			. "</script>\n";
	}

	/**
	 * Convert normalized events to per-provider browser payloads.
	 *
	 * @param array           $events    Normalized events.
	 * @param OpenPixel_Provider[] $providers Active providers.
	 * @return array
	 */
	public function build_payloads( array $events, array $providers = array() ) {
		if ( ! $providers ) {
			$providers = $this->get_active_providers();
		}

		$payloads = array();

		foreach ( $providers as $provider ) {
			foreach ( $provider->get_prelude_payloads( $this->bus ) as $payload ) {
				$payloads[] = $payload;
			}
		}

		foreach ( $events as $event ) {
			foreach ( $providers as $provider ) {
				if ( ! $this->provider_wants( $provider, $event ) ) {
					continue;
				}
				$payload = $provider->to_browser_payload( $event );
				if ( $payload ) {
					$payloads[] = $payload;
				}
			}
		}

		return $payloads;
	}
}
