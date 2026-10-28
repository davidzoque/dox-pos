<?php
/**
 * El aviso de las tiendas en varios idiomas o monedas, para quien no tiene Dox POS Pro.
 *
 * Si la tienda usa Polylang, WPML o un plugin de varias monedas (Price Based on Country, CURCY, WOOCS, Aelia),
 * la caja gratuita vende con el precio base de la tienda y edita solo la ficha principal. Se avisa una vez,
 * y solo en las pantallas del propio plugin (Ajustes y la caja), con opción de cerrarlo (por usuario).
 * Nada en el escritorio de WordPress ni en la web: guía 11 del directorio de WordPress.org.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Qué plugin de idiomas o de monedas tiene la tienda, para el aviso. '' si ninguno. Se mira al pintar
 * (después de plugins_loaded), no al cargar el archivo.
 *
 * @return string Nombre del primero que se encuentra.
 */
function dox_pos_international_plugin() {
	if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_default_language' ) ) {
		return 'Polylang';
	}
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		return 'WPML';
	}
	if ( function_exists( 'wcpbc_get_zone_by_country' ) || class_exists( 'WC_Product_Price_Based_Country' ) || defined( 'WCPBC_PLUGIN_FILE' ) ) {
		return 'Price Based on Country';
	}
	if ( defined( 'WOOMULTI_CURRENCY_F_VERSION' ) || defined( 'WOOMULTI_CURRENCY_VERSION' ) || class_exists( 'WOOMULTI_CURRENCY_F_Data' ) ) {
		return 'CURCY';
	}
	if ( class_exists( 'WOOCS' ) || defined( 'WOOCS_VERSION' ) ) {
		return 'WOOCS';
	}
	if ( class_exists( 'Aelia\WC\CurrencySwitcher\WC_Aelia_CurrencySwitcher' ) || isset( $GLOBALS['woocommerce-aelia-currencyswitcher'] ) ) {
		return 'Aelia Currency Switcher';
	}
	return '';
}

/**
 * ¿Toca enseñar el aviso a este usuario? Sin Pro, con uno de esos plugins, siendo quien administra la tienda
 * y sin haberlo cerrado.
 *
 * @return bool
 */
function dox_pos_international_notice_due() {
	if ( dox_pos_pro_has_international() || '' === dox_pos_international_plugin() ) { // Lo barato primero: sin ninguno de esos plugins no se pregunta nada a la base de datos.
		return false;
	}
	if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
		return false;
	}
	return ! get_user_meta( get_current_user_id(), 'dox_pos_intl_notice_closed', true );
}

/**
 * El HTML del aviso (el mismo texto en Ajustes y en la caja), o '' si no toca.
 *
 * @param string $context 'settings' o 'caja': cambia solo la clase con la que se pinta.
 * @return string
 */
function dox_pos_international_notice_html( $context ) {
	if ( ! dox_pos_international_notice_due() ) {
		return '';
	}
	$text = __( 'Several languages or currencies? Dox POS Pro sells in each one and keeps translations in sync.', 'dox-pos' );
	return '<div class="dox-intl-notice dox-intl-' . esc_attr( $context ) . '" role="status">'
		. '<span>' . esc_html( $text ) . ' <a href="' . esc_url( dox_pos_site_url( 'plans' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'See Dox POS Pro', 'dox-pos' ) . '</a></span>'
		. '<button type="button" class="dox-intl-close" aria-label="' . esc_attr__( 'Dismiss', 'dox-pos' ) . '">&times;</button>'
		. '</div>';
}

// En la caja, bajo la cabecera.
add_action( 'dox_pos_after_header', 'dox_pos_international_notice_caja' );
function dox_pos_international_notice_caja() {
	echo wp_kses( dox_pos_international_notice_html( 'caja' ), dox_pos_international_notice_tags() );
}

/**
 * Las etiquetas que lleva el aviso, para wp_kses().
 *
 * @return array
 */
function dox_pos_international_notice_tags() {
	return array(
		'div'    => array( 'class' => array(), 'role' => array() ),
		'span'   => array(),
		'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array() ),
		'button' => array( 'type' => array(), 'class' => array(), 'aria-label' => array() ),
	);
}

// Cerrarlo: una marca en el usuario. Lo piden la caja y Ajustes con su propio nonce de REST.
add_action( 'dox_pos_rest_routes', 'dox_pos_international_notice_route', 5, 3 );
function dox_pos_international_notice_route( $ns ) {
	register_rest_route(
		$ns,
		'/notices/international',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dox_pos_rest_international_notice_close',
			'permission_callback' => static function () {
				return current_user_can( 'manage_woocommerce' );
			},
		)
	);
}

function dox_pos_rest_international_notice_close() {
	update_user_meta( get_current_user_id(), 'dox_pos_intl_notice_closed', 1 );
	return rest_ensure_response( array( 'ok' => true ) );
}
