<?php
/**
 * El Mostrador: la caja registradora de la tienda física. Es una pestaña más de la caja (la
 * misma ruta), que aparece solo si la tienda la enciende en Ajustes > Ventas. Vende con el
 * escáner de códigos de barras, cobra en efectivo con el cambio, en tarjeta (el datáfono de la
 * tienda, por fuera) o con cualquier otra forma de pago, y cada venta es un pedido de WooCommerce
 * como los de Vender: mismo inventario, mismo kardex, mismo historial.
 *
 * Qué equipo abre en el Mostrador lo decide el propio equipo (se marca en el navegador), no el
 * usuario: la misma persona atiende el mostrador en el ordenador y contesta WhatsApp en el teléfono.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ¿La tienda tiene el Mostrador encendido? De fábrica no: una tienda que vende solo por chat no lo ve.
 */
function dox_pos_counter_on() {
	$s = dox_pos_sales();
	return ! empty( $s['counter'] );
}

/**
 * El canal con el que quedan las ventas del Mostrador: el canal "en mano" de la tienda (In person,
 * En persona...) si tiene uno, para que el historial las cuente junto a las que ya registraba así.
 */
function dox_pos_counter_channel() {
	foreach ( dox_pos_channels() as $ch ) {
		if ( ! empty( $ch['pickup'] ) ) {
			return $ch['name'];
		}
	}
	return __( 'In person', 'dox-pos' );
}

/**
 * Las formas de pago del Mostrador. Efectivo y tarjeta siempre están, aunque la tienda las haya
 * apagado para Vender (ahí suele vender por transferencia); el resto son las de la tienda que
 * quedan pagadas al registrar (contra reembolso no tiene sentido en un mostrador).
 *
 * @return array<string,array{id:string,title:string,paid:bool}>
 */
function dox_pos_counter_payments() {
	$all  = dox_pos_payment_methods();
	$base = array(
		'efectivo' => array( 'id' => 'dox_pos_cash', 'title' => __( 'Cash payment', 'dox-pos' ), 'paid' => true ),
		'tarjeta'  => array( 'id' => 'dox_pos_card', 'title' => __( 'Card', 'dox-pos' ), 'paid' => true ),
	);
	$out = array();
	foreach ( $base as $key => $m ) {
		$out[ $key ] = $all[ $key ] ?? $m; // Con el nombre que le puso la tienda, si lo tiene.
	}
	foreach ( $all as $key => $m ) {
		if ( ! isset( $out[ $key ] ) && ! empty( $m['paid'] ) ) {
			$out[ $key ] = $m;
		}
	}
	return $out;
}

/**
 * Los botones rápidos: lo que se vende sin código de barras (una bolsa, un arreglo, una tarjeta
 * regalo). Se eligen desde el propio Mostrador; aquí van los IDs, en su orden.
 *
 * @return int[]
 */
function dox_pos_counter_quick_ids() {
	$ids = get_option( 'dox_pos_counter_quick', array() );
	return array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
}

/**
 * Los botones rápidos con la forma de un resultado de búsqueda (los borrados o sin publicar se saltan).
 *
 * @return array
 */
function dox_pos_counter_quick() {
	$out = array();
	foreach ( dox_pos_counter_quick_ids() as $id ) {
		$item = dox_pos_format_product( $id );
		if ( $item ) {
			$out[] = $item;
		}
	}
	return $out;
}

/**
 * Lo que leyó el escáner: un SKU o un GTIN/EAN/UPC (el campo de WooCommerce "GTIN, UPC, EAN o
 * ISBN"), exacto. Devuelve el producto con la forma del buscador y, si el código es de una talla,
 * cuál es. Si el código es del producto variable (no de una talla), sin talla: la caja la pregunta.
 *
 * @param string $code Lo que llegó.
 * @return array|null { item, vid }
 */
function dox_pos_counter_scan( $code ) {
	$code = trim( (string) $code );
	if ( '' === $code ) {
		return null;
	}
	$id = (int) wc_get_product_id_by_sku( $code );
	if ( ! $id && function_exists( 'wc_get_product_id_by_global_unique_id' ) ) {
		$id = (int) wc_get_product_id_by_global_unique_id( $code );
	}
	if ( ! $id ) {
		return null;
	}
	$pid  = dox_pos_parent_id( $id );
	$item = $pid ? dox_pos_format_product( $pid ) : null;
	if ( ! $item ) {
		return null;
	}
	$vid = 0;
	if ( $pid !== $id ) {
		$vid = $id; // Una talla concreta.
	} elseif ( 1 === count( $item['variations'] ) ) {
		$vid = (int) $item['variations'][0]['id']; // Un producto simple: su única "talla".
	}
	return array( 'item' => $item, 'vid' => $vid );
}

/**
 * Lo que va a costar la venta, con los impuestos de la tienda física (su dirección en WooCommerce),
 * igual que lo calculará el pedido. Hace falta antes de cobrar: en efectivo el cambio sale de aquí.
 * Con los precios con impuestos incluidos (lo normal en Colombia), el total no cambia; en Estados
 * Unidos el impuesto se suma.
 *
 * @param array $lines    [{id, qty}].
 * @param float $discount Descuento en dinero.
 * @return array { subtotal, discount, tax, total, included }
 */
function dox_pos_counter_quote( $lines, $discount ) {
	$sub      = 0.0;
	$rows     = array(); // [importe, tasas] de cada línea con impuesto.
	$on       = wc_tax_enabled();
	$included = wc_prices_include_tax();
	foreach ( (array) $lines as $l ) {
		$p   = is_array( $l ) ? wc_get_product( (int) ( $l['id'] ?? 0 ) ) : null;
		$qty = is_array( $l ) ? (int) ( $l['qty'] ?? 0 ) : 0;
		if ( ! $p || $qty < 1 ) {
			continue;
		}
		$line = (float) $p->get_price() * $qty;
		$sub += $line;
		if ( $on && $p->is_taxable() ) {
			$rows[] = array( $line, WC_Tax::get_base_tax_rates( $p->get_tax_class() ) );
		}
	}
	$discount = max( 0, min( (float) $discount, $sub ) );
	// El impuesto de cada línea, y el del descuento: WooCommerce le pone impuesto negativo a un cargo
	// negativo, repartido según lo que pesa cada línea, así que el impuesto sale del precio ya rebajado.
	$taxes = array();
	$fee   = 0.0;
	foreach ( $rows as $r ) {
		$taxes[] = array_sum( WC_Tax::calc_tax( $r[0], $r[1], $included ) );
		if ( $discount > 0 && $sub > 0 ) {
			$fee += array_sum( WC_Tax::calc_tax( -$discount * $r[0] / $sub, $r[1], false ) );
		}
	}
	$round = 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' );
	$tax   = $round ? wc_round_tax_total( array_sum( $taxes ) ) : array_sum( array_map( 'wc_round_tax_total', $taxes ) );
	$tax  += wc_round_tax_total( $fee );
	$total = $included ? $sub - $discount : $sub - $discount + $tax;
	return array(
		'subtotal' => wc_format_decimal( $sub, wc_get_price_decimals() ),
		'discount' => wc_format_decimal( $discount, wc_get_price_decimals() ),
		'tax'      => wc_format_decimal( $tax, wc_get_price_decimals() ),
		'total'    => wc_format_decimal( max( 0, $total ), wc_get_price_decimals() ),
		'included' => $included,
	);
}

/**
 * Una venta del Mostrador paga los impuestos del sitio donde está la tienda, no los de la dirección
 * del cliente (que no se pide). Sin esto, con los impuestos "según la dirección de envío" el pedido
 * no encontraría tasa y saldría sin impuesto.
 */
add_filter( 'woocommerce_order_get_tax_location', 'dox_pos_counter_tax_location', 10, 2 );
function dox_pos_counter_tax_location( $args, $order ) {
	if ( ! $order instanceof WC_Order || ! $order->get_meta( '_dox_pos_counter' ) ) {
		return $args;
	}
	$c = WC()->countries;
	return array_merge(
		(array) $args,
		array(
			'country'  => $c->get_base_country(),
			'state'    => $c->get_base_state(),
			'postcode' => $c->get_base_postcode(),
			'city'     => $c->get_base_city(),
		)
	);
}

// ---------- la caja ----------

add_filter( 'dox_pos_cfg', 'dox_pos_counter_cfg' );
function dox_pos_counter_cfg( $cfg ) {
	if ( ! dox_pos_counter_on() ) {
		$cfg['counter'] = null;
		return $cfg;
	}
	$pays = array();
	foreach ( dox_pos_counter_payments() as $key => $m ) {
		$pays[] = array( 'key' => $key, 'title' => $m['title'] );
	}
	$cfg['counter'] = array(
		'payments' => $pays,
		'quick'    => dox_pos_counter_quick(),
		'edit'     => current_user_can( 'manage_woocommerce' ), // ¿Elige los botones rápidos?
		'taxes'    => wc_tax_enabled() && ! wc_prices_include_tax(), // ¿El impuesto se suma al precio? Entonces el total lo calcula la tienda.
		'bills'    => dox_pos_counter_bills(),
	);
	return $cfg;
}

/**
 * Los billetes de la moneda de la tienda, para proponer con cuánto paga el cliente. Los de las
 * monedas más comunes; en las demás, una serie que sirve en casi todas.
 *
 * @return float[]
 */
function dox_pos_counter_bills() {
	$bills = array(
		'USD' => array( 1, 5, 10, 20, 50, 100 ),
		'CAD' => array( 5, 10, 20, 50, 100 ),
		'EUR' => array( 5, 10, 20, 50, 100, 200 ),
		'GBP' => array( 5, 10, 20, 50 ),
		'MXN' => array( 20, 50, 100, 200, 500, 1000 ),
		'COP' => array( 1000, 2000, 5000, 10000, 20000, 50000, 100000 ),
		'CLP' => array( 1000, 2000, 5000, 10000, 20000 ),
		'PEN' => array( 10, 20, 50, 100, 200 ),
		'ARS' => array( 1000, 2000, 10000, 20000 ),
	);
	$cur = get_woocommerce_currency();
	return apply_filters( 'dox_pos_counter_bills', $bills[ $cur ] ?? array( 1, 5, 10, 20, 50, 100 ), $cur );
}

add_action( 'dox_pos_scripts', 'dox_pos_counter_scripts' );
function dox_pos_counter_scripts( $cfg ) {
	if ( empty( $cfg['counter'] ) ) {
		return;
	}
	$ver = DOX_POS_VERSION . '.' . (int) filemtime( DOX_POS_PATH . 'assets/js/mostrador.js' );
	wp_enqueue_script( 'dox-pos-mostrador', DOX_POS_URL . 'assets/js/mostrador.js', array( 'dox-pos-caja', 'wp-i18n' ), $ver, true );
	wp_set_script_translations( 'dox-pos-mostrador', 'dox-pos', is_dir( DOX_POS_PATH . 'languages' ) ? DOX_POS_PATH . 'languages' : '' );
}

// ---------- la API ----------

add_action( 'rest_api_init', 'dox_pos_counter_routes' );
function dox_pos_counter_routes() {
	$ns = 'dox-pos/v1';
	register_rest_route(
		$ns,
		'/counter/scan',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dox_pos_rest_counter_scan',
			'permission_callback' => 'dox_pos_rest_permission',
			'args'                => array( 'code' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		)
	);
	register_rest_route( $ns, '/counter/quote', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_counter_quote', 'permission_callback' => 'dox_pos_rest_permission' ) );
	register_rest_route(
		$ns,
		'/counter/quick',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dox_pos_rest_counter_quick',
			'permission_callback' => function () {
				return current_user_can( 'manage_woocommerce' );
			},
		)
	);
}

function dox_pos_rest_counter_scan( WP_REST_Request $request ) {
	// Un código que no existe no es un error (el lector lee de todo): sin producto, item vacío.
	$r = dox_pos_counter_scan( $request->get_param( 'code' ) );
	return rest_ensure_response( $r ? $r : array( 'item' => null, 'vid' => 0 ) );
}

function dox_pos_rest_counter_quote( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();
	return rest_ensure_response( dox_pos_counter_quote( $b['lines'] ?? array(), (float) ( $b['discount'] ?? 0 ) ) );
}

function dox_pos_rest_counter_quick( WP_REST_Request $request ) {
	$b   = (array) $request->get_json_params();
	$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', (array) ( $b['ids'] ?? array() ) ) ) ) ), 0, 24 );
	$ids = array_values( array_filter( $ids, fn( $id ) => 'product' === get_post_type( $id ) ) );
	update_option( 'dox_pos_counter_quick', $ids, false );
	return rest_ensure_response( array( 'items' => dox_pos_counter_quick() ) );
}
