<?php
/**
 * Plugin Name:       Dox POS
 * Plugin URI:        https://doxstudio.com
 * Description:       La caja para la tienda que vende por WhatsApp e Instagram: registra ventas, apartados y mercancía desde el frontend (/caja), sin entrar a wp-admin, con pedidos, productos, historial, kardex y Excel. Cada venta es un pedido de WooCommerce y el inventario baja solo. Con Dox POS Pro, el asistente de negocio.
 * Version:           0.20.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Dox Studio
 * Author URI:        https://doxstudio.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dox-pos
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * Update URI:        https://github.com/davidzoque/dox-pos
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOX_POS_VERSION', '0.20.0' );
define( 'DOX_POS_FILE', __FILE__ );
define( 'DOX_POS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DOX_POS_URL', plugin_dir_url( __FILE__ ) );
define( 'DOX_POS_SLUG', 'caja' );        // La ruta de fábrica: dominio.com/caja (se cambia en los ajustes)
define( 'DOX_POS_CAP', 'dox_pos_use' ); // El permiso que abre la puerta.

// Mientras no esté en WordPress.org, las actualizaciones llegan desde las releases del repositorio de
// GitHub: el workflow arma el zip limpio con cada etiqueta v* y Plugin Update Checker lo sirve. La
// compilación para WordPress.org va sin esta carpeta vendor y sin la cabecera Update URI.
$dox_pos_puc = DOX_POS_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $dox_pos_puc ) ) {
	require_once $dox_pos_puc;
	$dox_pos_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( 'https://github.com/davidzoque/dox-pos/', __FILE__, 'dox-pos' );
	$dox_pos_updater->setBranch( 'main' );
	$dox_pos_updater->getVcsApi()->enableReleaseAssets();
}

require_once DOX_POS_PATH . 'includes/roles.php';
require_once DOX_POS_PATH . 'includes/page.php';
require_once DOX_POS_PATH . 'includes/catalog.php';
require_once DOX_POS_PATH . 'includes/settings.php';
require_once DOX_POS_PATH . 'includes/shipping.php';
require_once DOX_POS_PATH . 'includes/orders.php';
require_once DOX_POS_PATH . 'includes/entries.php';
require_once DOX_POS_PATH . 'includes/stock-log.php';
require_once DOX_POS_PATH . 'includes/products.php';
require_once DOX_POS_PATH . 'includes/inventory.php';
require_once DOX_POS_PATH . 'includes/history.php';
require_once DOX_POS_PATH . 'includes/api.php';
require_once DOX_POS_PATH . 'includes/install.php';

/**
 * Compatibilidad con HPOS (pedidos en tabla propia) y con los bloques de carrito y checkout.
 */
add_action( 'before_woocommerce_init', 'dox_pos_declare_compat' );
function dox_pos_declare_compat() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DOX_POS_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DOX_POS_FILE, true );
	}
}

/**
 * La URL de la caja (la ruta se decide en los ajustes).
 *
 * @return string
 */
function dox_pos_url() {
	return home_url( '/' . dox_pos_slug() . '/' );
}

register_activation_hook( DOX_POS_FILE, 'dox_pos_activate' );
function dox_pos_activate() {
	dox_pos_install();
}

register_deactivation_hook( DOX_POS_FILE, 'dox_pos_deactivate' );
function dox_pos_deactivate() {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'dox_pos_clean_images' ); // La limpieza diaria de fotos sin usar.
	}
	flush_rewrite_rules();
}
