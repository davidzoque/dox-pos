<?php
/**
 * La moneda de la caja y los precios con que vende: un solo sitio para preguntarlo.
 *
 * Aquí no hay ninguna función de varias monedas. La caja vende en la moneda de la tienda y con el precio de
 * WooCommerce, y cada pregunta pasa por estas funciones para que un añadido (Dox POS Pro, con Price Based on
 * Country) pueda contestar otra cosa por los filtros. Sin añadido, devuelven exactamente lo de siempre.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * La moneda en que vende la caja. De fábrica, la de la tienda.
 *
 * @return string Código ISO (USD, COP...).
 */
function dox_pos_currency() {
	return (string) apply_filters( 'dox_pos_currency', get_woocommerce_currency() );
}

/**
 * Cómo se escribe un importe en una moneda: símbolo, dónde va, separadores y decimales. De fábrica, los
 * ajustes de WooCommerce (los mismos para cualquier moneda); un añadido pone los de su zona.
 *
 * @param string $currency La moneda, o vacío para la de la caja.
 * @return array { symbol, pos, thousand, decimal, decimals }
 */
function dox_pos_money_format( $currency = '' ) {
	$currency = '' !== (string) $currency ? strtoupper( (string) $currency ) : dox_pos_currency();
	$format   = array(
		'symbol'   => html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' ),
		'pos'      => get_option( 'woocommerce_currency_pos', 'left' ),
		'thousand' => wc_get_price_thousand_separator(),
		'decimal'  => wc_get_price_decimal_separator(),
		'decimals' => wc_get_price_decimals(),
	);
	return (array) apply_filters( 'dox_pos_money_format', $format, $currency );
}

/**
 * Los decimales de la moneda de la caja (o de otra).
 *
 * @param string $currency La moneda, o vacío para la de la caja.
 * @return int
 */
function dox_pos_decimals( $currency = '' ) {
	return (int) dox_pos_money_format( $currency )['decimals'];
}

/**
 * Lo que vale un producto en la caja.
 *
 * Contextos: 'sell' (lo que paga la clienta: el carrito y el pedido), 'list' (el que se enseña en el catálogo
 * y las listas) y 'edit' (el precio normal que se edita en el formulario).
 *
 * @param WC_Product $product El producto o la variación.
 * @param string     $context sell | list | edit.
 * @return float
 */
function dox_pos_product_price( $product, $context = 'sell' ) {
	$price = 'edit' === $context ? (float) $product->get_regular_price( 'edit' ) : (float) $product->get_price();
	return (float) apply_filters( 'dox_pos_product_price', $price, $product, $context );
}

/**
 * La moneda de los pedidos que cuentan los informes (Caja del día, Historial, Panel, cierre de turno...).
 * Vacío: todos, como siempre. Un añadido la fija para que no se sumen pedidos de monedas distintas.
 *
 * @return string
 */
function dox_pos_report_currency() {
	return strtoupper( (string) apply_filters( 'dox_pos_report_currency', '' ) );
}

/**
 * La moneda con que se apunta un movimiento del saldo a favor, o vacío si es la de siempre (el saldo de
 * antes de las monedas no lleva ninguna). Con un pedido, la de ese pedido; sin él, la de la caja.
 *
 * @param int $order_id El pedido del movimiento, si lo hay.
 * @return string
 */
function dox_pos_credit_currency( $order_id = 0 ) {
	return strtoupper( (string) apply_filters( 'dox_pos_credit_currency', '', (int) $order_id ) );
}
