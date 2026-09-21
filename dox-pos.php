<?php
/**
 * Plugin Name:       Dox POS
 * Plugin URI:        https://doxstudio.com/dox-pos/
 * Description:       The register for a shop that sells on WhatsApp and Instagram: record sales, layaways and incoming stock from the frontend (/pos), without wp-admin, with orders, products, history, a stock ledger and Excel files. Every sale is a WooCommerce order, so stock goes down on its own. With Dox POS Pro, the business assistant.
 * Version:           0.41.0
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

define( 'DOX_POS_VERSION', '0.41.0' );
define( 'DOX_POS_FILE', __FILE__ );
define( 'DOX_POS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DOX_POS_URL', plugin_dir_url( __FILE__ ) );
define( 'DOX_POS_SLUG', 'pos' );         // La ruta de fábrica en inglés; la de cada idioma la da dox_pos_default_slug() y se guarda al instalar (dox_pos_freeze_slug).
define( 'DOX_POS_CAP', 'dox_pos_use' ); // El permiso que abre la puerta.
define( 'DOX_POS_DEMO_REF', 'dox-demo-' ); // Marca las filas de demostración que no son pedidos (los crea el Pro; el guardián de aquí las protege).

// dox-pos-repo-only:inicio
// Lo que solo lleva la copia que se reparte desde el repositorio (la que instalan las tiendas por
// GitHub). El zip del directorio de WordPress.org va sin este bloque, sin includes/updater.php y sin
// languages/, porque allí las actualizaciones las sirve el propio directorio (los actualizadores no
// se permiten) y las traducciones llegan de translate.wordpress.org como paquete de idioma, que
// WordPress carga solo (lo pidió la revisión: ni archivos de traducción ni load_plugin_textdomain).
if ( file_exists( DOX_POS_PATH . 'includes/updater.php' ) ) {
	require_once DOX_POS_PATH . 'includes/updater.php';
}

// El español viene en languages/ y esta llamada lo carga: sin ella WordPress solo mira
// wp-content/languages/plugins/. El código va en inglés, que es lo que espera WordPress.org.
add_action( 'init', 'dox_pos_textdomain', 1 );
function dox_pos_textdomain() {
	load_plugin_textdomain( 'dox-pos', false, dirname( plugin_basename( DOX_POS_FILE ) ) . '/languages' );
}

// El paquete trae un solo español (es_ES) y WordPress no pasa de es_CO, es_MX o es_AR a es_ES por su
// cuenta: una tienda con el sitio en "Español de Colombia" veía la caja en inglés. Solo actúa cuando no
// existe el archivo de esa variante, así que una traducción propia (Loco Translate, un paquete en
// wp-content/languages) sigue mandando. Al devolver el .mo de es_ES, WordPress encuentra solo su .l10n.php.
add_filter( 'load_textdomain_mofile', 'dox_pos_spanish_fallback_mo', 10, 2 );
function dox_pos_spanish_fallback_mo( $mofile, $domain ) {
	if ( 'dox-pos' !== $domain || ! preg_match( '/dox-pos-es(_[A-Za-z]+)?\.mo$/', (string) $mofile ) ) {
		return $mofile;
	}
	if ( is_readable( $mofile ) || is_readable( substr( $mofile, 0, -3 ) . '.l10n.php' ) ) {
		return $mofile;
	}
	$es = DOX_POS_PATH . 'languages/dox-pos-es_ES.mo';
	return is_readable( $es ) ? $es : $mofile;
}

// Lo mismo para los textos de caja.js, que viajan en un JSON con el idioma en el nombre.
add_filter( 'load_script_translation_file', 'dox_pos_spanish_fallback_json', 10, 3 );
function dox_pos_spanish_fallback_json( $file, $handle, $domain ) {
	if ( 'dox-pos' !== $domain || ! $file || is_readable( $file ) ) {
		return $file;
	}
	$name = preg_replace( '/^dox-pos-es(_[A-Za-z]+)?-/', 'dox-pos-es_ES-', basename( $file ), 1, $hits );
	if ( ! $hits ) {
		return $file;
	}
	$es = DOX_POS_PATH . 'languages/' . $name;
	return is_readable( $es ) ? $es : $file;
}

// Menú común de los plugins de Dox Studio: la caja sigue donde está, dentro de
// WooCommerce, y solo se asoma a la portada de "Dox Plugins" cuando hay más
// plugins Dox instalados. Cada plugin lleva su copia de dox-core y se ejecuta
// solo la más nueva.
//
// Va dentro del bloque "repo-only" a propósito: dox-core trae su propio dominio
// de traducción (dox-core, que no es el slug del plugin), sus .mo y un
// load_textdomain, las tres cosas que la revisión de WordPress.org pidió quitar.
// La copia del directorio no pierde nada: se sigue apuntando en
// dox_core_register (includes/settings.php), así que sale en la portada en
// cuanto otro plugin Dox traiga el core, y estando sola no había menú que crear.
if ( file_exists( DOX_POS_PATH . 'dox-core/loader.php' ) ) {
	require_once DOX_POS_PATH . 'dox-core/loader.php';
	Dox_Core_Loader::register( require DOX_POS_PATH . 'dox-core/version.php', DOX_POS_PATH . 'dox-core/dox-core.php' );
}
// dox-pos-repo-only:fin

require_once DOX_POS_PATH . 'includes/roles.php';
require_once DOX_POS_PATH . 'includes/page.php';
require_once DOX_POS_PATH . 'includes/catalog.php';
require_once DOX_POS_PATH . 'includes/settings.php';
require_once DOX_POS_PATH . 'includes/shipping.php';
require_once DOX_POS_PATH . 'includes/shipping-setup.php';
require_once DOX_POS_PATH . 'includes/orders.php';
require_once DOX_POS_PATH . 'includes/entries.php';
require_once DOX_POS_PATH . 'includes/stock-log.php';
require_once DOX_POS_PATH . 'includes/costs.php';
require_once DOX_POS_PATH . 'includes/pools.php';
require_once DOX_POS_PATH . 'includes/products.php';
require_once DOX_POS_PATH . 'includes/inventory.php';
require_once DOX_POS_PATH . 'includes/history.php';
require_once DOX_POS_PATH . 'includes/dashboard.php';
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
