<?php
/**
 * Plugin Name:       Openpixly – Conversion Tracking & Product Feed for OpenAI Ads
 * Plugin URI:        https://github.com/unbelievable-digital/openpixly
 * Description:       Conversion pixel manager for WordPress and WooCommerce. Ships the OpenAI (ChatGPT Ads) Measurement Pixel and the Meta Pixel with their Conversions APIs: page views, product views, add to cart, checkout, purchases and registrations, plus product feeds for both catalogs.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Unbelievable Digital
 * Author URI:        https://unbelievable.digital
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       openpixly
 * WC requires at least: 6.0
 * WC tested up to:   9.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPENPIXEL_VERSION', '1.3.0' );
define( 'OPENPIXEL_PLUGIN_FILE', __FILE__ );
define( 'OPENPIXEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPENPIXEL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OPENPIXEL_OPTION_KEY', 'openpixel_settings' );

require_once OPENPIXEL_PLUGIN_DIR . 'includes/class-openpixel-money.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/class-openpixel-hash.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/class-openpixel-event-bus.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/class-openpixel-provider.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/class-openpixel-capi-client.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/providers/class-openpixel-openai-capi.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/providers/class-openpixel-provider-openai.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/providers/class-openpixel-meta-capi.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/providers/class-openpixel-provider-meta.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/integrations/class-openpixel-integration-woocommerce.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/feed/class-openpixel-feed-writer.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/feed/class-openpixel-product-feed.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/class-openpixel-core.php';
require_once OPENPIXEL_PLUGIN_DIR . 'includes/class-openpixel-admin.php';

/**
 * Boot the plugin.
 */
function openpixel_run() {
	$core = new OpenPixel_Core();
	$core->init();

	if ( is_admin() ) {
		$admin = new OpenPixel_Admin( $core );
		$admin->init();
	}

	$GLOBALS['openpixel_core'] = $core;
}
add_action( 'plugins_loaded', 'openpixel_run', 20 );

/**
 * Convenience accessor for other plugins/themes:
 *
 *   openpixel()->get_bus()->track( array( 'name' => 'generate_lead' ) );
 *
 * @return OpenPixel_Core|null
 */
function openpixel() {
	return isset( $GLOBALS['openpixel_core'] ) ? $GLOBALS['openpixel_core'] : null;
}

/**
 * Declare WooCommerce HPOS compatibility (we only use CRUD order APIs).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

function openpixel_activate() {
	if ( false === get_option( OPENPIXEL_OPTION_KEY ) ) {
		add_option( OPENPIXEL_OPTION_KEY, array() );
	}
}
register_activation_hook( __FILE__, 'openpixel_activate' );
