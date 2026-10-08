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
	$cfg['counter_only'] = dox_pos_is_counter_only(); // El cajero de mostrador solo ve esta pestaña y entra por ella.
	if ( $cfg['counter_only'] ) {
		$cfg['open_tab'] = 'mostrador';
	}
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
		'zxing'    => DOX_POS_URL . 'assets/vendor/zxing/zxing.min.js?ver=0.21.3', // El lector para la cámara donde el navegador no trae uno (Safari); se pide solo al usarla.
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
	dox_pos_counter_register_ticket();
	$ver = DOX_POS_VERSION . '.' . (int) filemtime( DOX_POS_PATH . 'assets/js/mostrador.js' );
	wp_enqueue_script( 'dox-pos-mostrador', DOX_POS_URL . 'assets/js/mostrador.js', array( 'dox-pos-caja', 'dox-pos-ticket', 'wp-i18n' ), $ver, true );
	wp_set_script_translations( 'dox-pos-mostrador', 'dox-pos', is_dir( DOX_POS_PATH . 'languages' ) ? DOX_POS_PATH . 'languages' : '' );
}

/**
 * ticket.js: el dibujo del ticket, que comparten el Mostrador y la vista previa de Ajustes.
 */
function dox_pos_counter_register_ticket() {
	$ver = DOX_POS_VERSION . '.' . (int) filemtime( DOX_POS_PATH . 'assets/js/ticket.js' );
	wp_register_script( 'dox-pos-ticket', DOX_POS_URL . 'assets/js/ticket.js', array( 'wp-i18n' ), $ver, true );
	wp_set_script_translations( 'dox-pos-ticket', 'dox-pos', is_dir( DOX_POS_PATH . 'languages' ) ? DOX_POS_PATH . 'languages' : '' );
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
			'permission_callback' => 'dox_pos_counter_rest_permission',
			'args'                => array( 'code' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		)
	);
	register_rest_route( $ns, '/counter/quote', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_counter_quote', 'permission_callback' => 'dox_pos_counter_rest_permission' ) );
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

// ---------- el turno: abrir y cerrar caja ----------

/**
 * La tabla de los turnos. Se crea aparte de dox_pos_install() con su propia versión, para que
 * aparezca también al subir el archivo sin cambiar la versión del plugin. Un turno es la caja
 * abierta por alguien con una base de efectivo hasta que se cierra contando lo que hay. El
 * gratuito usa una sola caja ("main"); la columna register queda para el Pro (varias cajas).
 */
const DOX_POS_SHIFTS_DB = '2'; // 2: también el rol "Cajero de mostrador".
add_action( 'init', 'dox_pos_counter_maybe_install', 21 );
function dox_pos_counter_maybe_install() {
	if ( get_option( 'dox_pos_shifts_db' ) === DOX_POS_SHIFTS_DB ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = $wpdb->prefix . 'dox_pos_shifts';
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			register varchar(64) NOT NULL DEFAULT 'main',
			status varchar(10) NOT NULL DEFAULT 'open',
			opened_at datetime NOT NULL,
			opened_by bigint(20) unsigned NOT NULL DEFAULT 0,
			opened_name varchar(190) NOT NULL DEFAULT '',
			float_cash decimal(15,2) NOT NULL DEFAULT 0,
			closed_at datetime DEFAULT NULL,
			closed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			closed_name varchar(190) NOT NULL DEFAULT '',
			expected decimal(15,2) DEFAULT NULL,
			counted decimal(15,2) DEFAULT NULL,
			summary longtext,
			note text,
			PRIMARY KEY  (id),
			KEY register_status (register,status),
			KEY opened_at (opened_at)
		) {$charset};"
	);
	dox_pos_install_counter_role(); // Por si el plugin se subió por archivo sin cambiar de versión.
	update_option( 'dox_pos_shifts_db', DOX_POS_SHIFTS_DB, false );
}

/**
 * La caja con la que trabaja esta petición. El gratuito tiene una sola ("main"); el Pro, con varias
 * cajas, la saca de lo que manda el equipo (filtro dox_pos_counter_register).
 *
 * @return string
 */
function dox_pos_counter_register() {
	$r = sanitize_key( (string) apply_filters( 'dox_pos_counter_register', 'main' ) );
	return '' !== $r ? $r : 'main';
}

/**
 * Quién atiende el mostrador: el usuario con la sesión, o (en el Pro, con cajeros por PIN) quien
 * se identificó con su PIN en ese equipo. Las ventas, la caja y las devoluciones quedan a su nombre.
 *
 * @return WP_User
 */
function dox_pos_counter_actor() {
	$u = apply_filters( 'dox_pos_counter_actor', wp_get_current_user() );
	return $u instanceof WP_User && $u->exists() ? $u : wp_get_current_user();
}

/**
 * El turno abierto de la caja, o null.
 *
 * @param string|null $register La caja; de fábrica, la de esta petición.
 * @return object|null
 */
function dox_pos_counter_open_shift( $register = null ) {
	global $wpdb;
	$register = null === $register ? dox_pos_counter_register() : $register;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dox_pos_shifts WHERE register = %s AND status = 'open' ORDER BY id DESC LIMIT 1", $register ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

function dox_pos_counter_get_shift( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dox_pos_shifts WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Abre la caja con su base. Si ya hay un turno abierto, devuelve ese (dos toques seguidos no abren dos).
 *
 * @param float $float El efectivo con que empieza el cajón.
 * @return object
 */
function dox_pos_counter_open( $float ) {
	global $wpdb;
	// Dos toques a la vez (o dos equipos de la misma caja) no abren dos turnos.
	$lock = 'dox_pos_shift_' . dox_pos_counter_register();
	$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );
	try {
		return dox_pos_counter_open_locked( $float );
	} finally {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

function dox_pos_counter_open_locked( $float ) {
	global $wpdb;
	$open = dox_pos_counter_open_shift();
	if ( $open ) {
		return $open;
	}
	$user = dox_pos_counter_actor();
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'dox_pos_shifts',
		array(
			'register'    => dox_pos_counter_register(),
			'status'      => 'open',
			'opened_at'   => current_time( 'mysql', true ),
			'opened_by'   => $user->ID,
			'opened_name' => $user->display_name,
			'float_cash'  => max( 0, (float) $float ),
		)
	);
	$id = (int) $wpdb->insert_id; // Antes de update_option, que hace su propia consulta y lo cambia.
	update_option( 'dox_pos_last_float', max( 0, (float) $float ), false ); // La próxima vez se propone la misma base.
	$shift = dox_pos_counter_get_shift( $id );
	do_action( 'dox_pos_counter_shift_opened', $shift );
	return $shift;
}

/**
 * Lo que pasó en un turno: ventas, lo cobrado por cada forma de pago, lo devuelto y el efectivo que
 * tendría que haber en el cajón (la base, más lo cobrado en efectivo, menos lo devuelto en efectivo).
 *
 * @param object $shift El turno.
 * @return array
 */
function dox_pos_counter_summary( $shift ) {
	$by     = array();
	$count  = 0;
	$total  = 0.0;
	$titles = wp_list_pluck( dox_pos_counter_payments(), 'title' );
	$orders = wc_get_orders( array( 'type' => 'shop_order', 'limit' => -1, 'status' => array_keys( wc_get_order_statuses() ), 'meta_key' => '_dox_pos_shift', 'meta_value' => (int) $shift->id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	foreach ( $orders as $o ) {
		if ( in_array( $o->get_status(), array( 'cancelled', 'failed', 'pending' ), true ) ) {
			continue; // Anulada después: no entró dinero.
		}
		++$count;
		$total += (float) $o->get_total();
		foreach ( dox_pos_counter_order_payments( $o ) as $p ) {
			if ( ! isset( $by[ $p['key'] ] ) ) {
				$by[ $p['key'] ] = array( 'key' => $p['key'], 'title' => $titles[ $p['key'] ] ?? $p['title'], 'amount' => 0.0 );
			}
			$by[ $p['key'] ]['amount'] += (float) $p['amount'];
		}
	}
	$refund_cash  = 0.0;
	$refund_other = 0.0;
	$refunds      = wc_get_orders( array( 'type' => 'shop_order_refund', 'limit' => -1, 'meta_key' => '_dox_pos_shift', 'meta_value' => (int) $shift->id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	foreach ( $refunds as $r ) {
		if ( 'efectivo' === $r->get_meta( '_dox_pos_refund_method' ) ) {
			$refund_cash += abs( (float) $r->get_amount() );
		} else {
			$refund_other += abs( (float) $r->get_amount() );
		}
	}
	$cash = isset( $by['efectivo'] ) ? $by['efectivo']['amount'] : 0.0;
	$dec  = wc_get_price_decimals();
	$sum = array(
		'orders'       => $count,
		'total'        => round( $total, $dec ),
		'payments'     => array_values( array_map( function ( $p ) use ( $dec ) { $p['amount'] = round( $p['amount'], $dec ); return $p; }, $by ) ),
		'float'        => round( (float) $shift->float_cash, $dec ),
		'cash_sales'   => round( $cash, $dec ),
		'refund_cash'  => round( $refund_cash, $dec ),
		'refund_other' => round( $refund_other, $dec ),
		'expected'     => round( (float) $shift->float_cash + $cash - $refund_cash, $dec ),
		'extra_cash'   => array(), // Lo que mueve el efectivo además de ventas y devoluciones (el Pro: entradas y salidas), [{label, amount}].
	);
	// El Pro añade sus movimientos de efectivo en extra_cash y los suma a expected.
	return apply_filters( 'dox_pos_counter_summary', $sum, $shift );
}

/**
 * Cierra la caja: lo contado contra lo esperado. Guarda el resumen tal como quedó.
 *
 * @param float  $counted El efectivo contado en el cajón.
 * @param string $note    Una nota (por qué falta o sobra).
 * @return array|WP_Error El turno cerrado con su resumen.
 */
function dox_pos_counter_close( $counted, $note = '' ) {
	global $wpdb;
	$shift = dox_pos_counter_open_shift();
	if ( ! $shift ) {
		return new WP_Error( 'dox_pos_caja_cerrada', __( 'The till is not open.', 'dox-pos' ) );
	}
	$sum  = dox_pos_counter_summary( $shift );
	$user = dox_pos_counter_actor();
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'dox_pos_shifts',
		array(
			'status'      => 'closed',
			'closed_at'   => current_time( 'mysql', true ),
			'closed_by'   => $user->ID,
			'closed_name' => $user->display_name,
			'expected'    => $sum['expected'],
			'counted'     => max( 0, (float) $counted ),
			'summary'     => wp_json_encode( $sum ),
			'note'        => sanitize_textarea_field( $note ),
		),
		array( 'id' => (int) $shift->id, 'status' => 'open' ) // Si otro equipo la cerró un instante antes, este cierre no lo pisa.
	);
	if ( ! $wpdb->rows_affected ) {
		return new WP_Error( 'dox_pos_caja_cerrada', __( 'The till is not open.', 'dox-pos' ) );
	}
	$closed = dox_pos_counter_format_shift( dox_pos_counter_get_shift( (int) $shift->id ) );
	do_action( 'dox_pos_counter_shift_closed', $closed );
	return $closed;
}

/**
 * Un turno para la caja: fechas en la hora de la tienda y, si está abierto, su resumen al momento.
 */
function dox_pos_counter_format_shift( $shift ) {
	if ( ! $shift ) {
		return null;
	}
	$open = 'open' === $shift->status;
	$sum  = $open ? dox_pos_counter_summary( $shift ) : json_decode( (string) $shift->summary, true );
	$fmt  = function ( $gmt ) {
		return $gmt ? wp_date( get_option( 'time_format' ), strtotime( $gmt . ' UTC' ) ) : '';
	};
	$day  = function ( $gmt ) {
		return $gmt ? wp_date( get_option( 'date_format' ), strtotime( $gmt . ' UTC' ) ) : '';
	};
	return array(
		'id'          => (int) $shift->id,
		'register'    => (string) $shift->register,
		'register_name' => (string) apply_filters( 'dox_pos_counter_register_name', '', $shift->register ), // El nombre de la caja (el Pro, con varias).
		'open'        => $open,
		'opened_at'   => $fmt( $shift->opened_at ),
		'opened_day'  => $day( $shift->opened_at ),
		'opened_name' => $shift->opened_name,
		'closed_at'   => $fmt( $shift->closed_at ),
		'closed_day'  => $day( $shift->closed_at ),
		'closed_name' => $shift->closed_name,
		'counted'     => null === $shift->counted ? null : (float) $shift->counted,
		'difference'  => null === $shift->counted ? null : round( (float) $shift->counted - (float) $shift->expected, wc_get_price_decimals() ),
		'note'        => (string) $shift->note,
		'summary'     => is_array( $sum ) ? $sum : array(),
	);
}

// ---------- cobrar con varias formas de pago ----------

/**
 * Cómo se pagó una venta del Mostrador: [{key, title, amount}], lo que cada forma de pago aportó al
 * total (en efectivo, sin el cambio). Las ventas de antes de guardarlo, con su única forma de pago.
 */
function dox_pos_counter_order_payments( $order ) {
	$parts = $order->get_meta( '_dox_pos_payments' );
	if ( is_array( $parts ) && $parts ) {
		return $parts;
	}
	$key = (string) $order->get_meta( '_dox_pos_pay_key' );
	return array( array( 'key' => $key ? $key : 'otro', 'title' => $order->get_payment_method_title(), 'amount' => (float) $order->get_total() ) );
}

/**
 * Reparte el cobro entre las formas de pago y lo valida contra el total del pedido. Lo de más solo
 * puede venir del efectivo, y es el cambio.
 *
 * @param array  $parts   [{key, amount}] como llegó, o vacío para una sola forma de pago.
 * @param string $pay_key La forma de pago elegida (cuando es una sola).
 * @param float  $total   El total del pedido.
 * @param float  $tendered En efectivo: con cuánto pagó.
 * @return array|WP_Error { parts: [{key,title,amount}], change, tendered }
 */
function dox_pos_counter_split( $parts, $pay_key, $total, $tendered ) {
	$methods = dox_pos_counter_payments();
	$dec     = wc_get_price_decimals();
	$out     = array();
	if ( ! $parts ) {
		$parts = array( array( 'key' => $pay_key, 'amount' => 'efectivo' === $pay_key && null !== $tendered ? $tendered : $total ) );
	}
	$sum  = 0.0;
	$cash = 0.0;
	foreach ( (array) $parts as $p ) {
		$key = sanitize_key( is_array( $p ) ? ( $p['key'] ?? '' ) : '' );
		$amt = round( (float) wc_format_decimal( is_array( $p ) ? ( $p['amount'] ?? 0 ) : 0 ), $dec );
		if ( ! isset( $methods[ $key ] ) || $amt <= 0 ) {
			continue;
		}
		$sum += $amt;
		if ( 'efectivo' === $key ) {
			$cash += $amt;
		}
		$out[] = array( 'key' => $key, 'title' => $methods[ $key ]['title'], 'amount' => $amt );
	}
	$over = round( $sum - $total, $dec );
	if ( ! $out || $over < 0 ) {
		return new WP_Error( 'dox_pos_falta_dinero', sprintf( /* translators: %s: order total */ __( 'The total is %s and the money received does not cover it.', 'dox-pos' ), html_entity_decode( wp_strip_all_tags( wc_price( $total ) ) ) ), array( 'total' => $total ) );
	}
	if ( $over > $cash ) {
		return new WP_Error( 'dox_pos_de_mas', __( 'Only cash can be more than the total (that is the change). Check the amounts.', 'dox-pos' ) );
	}
	// El cambio sale del efectivo: lo que el efectivo aportó al total es lo entregado menos el cambio.
	if ( $over > 0 ) {
		foreach ( $out as &$p ) {
			if ( 'efectivo' === $p['key'] ) {
				$p['amount'] = round( $p['amount'] - $over, $dec );
				break;
			}
		}
		unset( $p );
	}
	return array( 'parts' => $out, 'change' => $over, 'tendered' => $cash > 0 ? $cash : null );
}

// ---------- el ticket ----------

/**
 * Lo que lleva el ticket de una venta (o el comprobante de una devolución): la tienda, las líneas,
 * los totales, cómo se pagó y el cambio. La caja lo pinta y lo manda a imprimir.
 *
 * @param WC_Order $order El pedido.
 * @return array
 */
function dox_pos_counter_receipt( $order ) {
	$lines = array();
	foreach ( $order->get_items() as $item_id => $item ) {
		$qty     = (int) $item->get_quantity();
		$p       = $item->get_product();
		$lines[] = array(
			'id'         => (int) $item_id,
			'name'       => $item->get_name(),
			'sku'        => $p ? $p->get_sku( 'edit' ) : '',
			'qty'        => $qty,
			'unit'       => $qty ? (float) $item->get_subtotal() / $qty : 0,
			'total'      => (float) $item->get_subtotal(),
			'refundable' => max( 0, $qty - abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) ),
			'gross'      => $qty ? dox_pos_counter_unit_refund( $order, $item ) : 0, // Lo que se devuelve por unidad, con su parte del descuento y del impuesto.
		);
	}
	$discount = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( (float) $fee->get_total() < 0 ) {
			$discount += abs( (float) $fee->get_total() );
		}
	}
	$taxes = array();
	foreach ( $order->get_tax_totals() as $t ) {
		$taxes[] = array( 'label' => $t->label, 'amount' => (float) $t->amount );
	}
	$s = dox_pos_counter_receipt_settings();
	return array(
		'id'       => $order->get_id(),
		'number'   => $order->get_order_number(),
		'date'     => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
		'seller'   => (string) $order->get_meta( '_dox_pos_seller_name' ),
		'customer' => trim( $order->get_formatted_billing_full_name() ),
		'lines'    => $lines,
		'subtotal' => (float) $order->get_subtotal(),
		'discount' => $discount,
		'taxes'    => $taxes,
		'included' => wc_prices_include_tax(),
		'total'    => (float) $order->get_total(),
		'refunded' => (float) $order->get_total_refunded(),
		'payments' => array_map( function ( $p ) { return array( 'title' => $p['title'], 'amount' => (float) $p['amount'] ); }, dox_pos_counter_order_payments( $order ) ),
		'tendered' => '' !== (string) $order->get_meta( '_dox_pos_tendered' ) ? (float) $order->get_meta( '_dox_pos_tendered' ) : null,
		'change'   => '' !== (string) $order->get_meta( '_dox_pos_change' ) ? (float) $order->get_meta( '_dox_pos_change' ) : null,
		'pay_key'  => (string) $order->get_meta( '_dox_pos_pay_key' ),
		'code'     => 'DOXPOS-' . $order->get_id(), // El código de barras del ticket: escanearlo abre la devolución.
		'store'    => $s,
	);
}

/**
 * Lo que vale una unidad devuelta: lo que pagó por ella, con su parte del descuento (un cargo
 * negativo del pedido, repartido según lo que pesa cada línea) y del impuesto.
 */
function dox_pos_counter_unit_refund( $order, $item ) {
	$items = 0.0;
	foreach ( $order->get_items() as $it ) {
		$items += (float) $it->get_total() + (float) $it->get_total_tax();
	}
	$fees = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( (float) $fee->get_total() < 0 ) {
			$fees += (float) $fee->get_total() + (float) $fee->get_total_tax();
		}
	}
	$factor = $items > 0 ? ( $items + $fees ) / $items : 1;
	$qty    = max( 1, (int) $item->get_quantity() );
	return ( (float) $item->get_total() + (float) $item->get_total_tax() ) * $factor / $qty;
}

/**
 * El encabezado y el pie del ticket. De fábrica, el nombre y la dirección de la tienda en WooCommerce.
 */
function dox_pos_counter_receipt_settings() {
	$s       = dox_pos_sales();
	$c       = WC()->countries;
	$address = implode( ', ', array_filter( array( $c->get_base_address(), $c->get_base_city() ) ) );
	$default = implode( "\n", array_filter( array( $address ) ) );
	$show    = array();
	foreach ( dox_pos_counter_receipt_parts() as $k => $label ) {
		$show[ $k ] = ! isset( $s['receipt_show'] ) || ! empty( $s['receipt_show'][ $k ] ); // De fábrica, todo.
	}
	$out = array(
		'name'   => dox_pos_brand_name(),
		'logo'   => ! isset( $s['receipt_logo'] ) || ! empty( $s['receipt_logo'] ) ? dox_pos_logo_url() : '',
		'header' => isset( $s['receipt_header'] ) ? (string) $s['receipt_header'] : $default,
		'footer' => isset( $s['receipt_footer'] ) ? (string) $s['receipt_footer'] : __( 'Thank you for your purchase!', 'dox-pos' ),
		'width'  => isset( $s['receipt_width'] ) && 58 === (int) $s['receipt_width'] ? 58 : 80,
		'size'   => in_array( $s['receipt_size'] ?? '', array( 'small', 'large' ), true ) ? $s['receipt_size'] : 'normal',
		'show'   => $show,
	);
	return apply_filters( 'dox_pos_counter_receipt_settings', $out ); // El Pro añade lo suyo (el editor completo).
}

/**
 * Lo que el ticket puede enseñar u ocultar, con su nombre en los ajustes.
 *
 * @return array<string,string>
 */
function dox_pos_counter_receipt_parts() {
	return array(
		'sku'      => __( 'SKU of each product', 'dox-pos' ),
		'seller'   => __( 'Who served', 'dox-pos' ),
		'customer' => __( 'Customer name', 'dox-pos' ),
		'taxes'    => __( 'Tax breakdown', 'dox-pos' ),
		'barcode'  => __( 'Barcode (for returns)', 'dox-pos' ),
	);
}

/**
 * Busca un pedido por lo que se escaneó o escribió: el código del ticket (DOXPOS-123), el número
 * del pedido (2481, #2481) o su ID.
 */
function dox_pos_counter_find_order( $q ) {
	$q = trim( (string) $q );
	if ( preg_match( '/^DOXPOS-(\d+)$/i', $q, $m ) ) {
		return wc_get_order( (int) $m[1] );
	}
	$n = ltrim( $q, '#' );
	if ( ! ctype_digit( $n ) ) {
		return null;
	}
	$o = wc_get_order( (int) $n );
	if ( $o instanceof WC_Order && (string) $o->get_order_number() === $n ) {
		return $o;
	}
	// Con un plugin de números de pedido, el número no es el ID.
	$found = wc_get_orders( array( 'type' => 'shop_order', 'limit' => 1, 'meta_key' => '_order_number', 'meta_value' => $n ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	return $found ? $found[0] : ( $o instanceof WC_Order ? $o : null );
}

// ---------- devoluciones ----------

/**
 * Devuelve piezas de un pedido: vuelven al inventario y sale el dinero, en efectivo del cajón o por
 * el medio con que pagó (la tarjeta se devuelve en el datáfono; aquí solo se apunta). Es un
 * reembolso de WooCommerce, así que los informes lo descuentan solos.
 *
 * @param int    $order_id El pedido.
 * @param array  $lines    [{id (de la línea), qty}].
 * @param string $method   "efectivo" u "original".
 * @param string $reason   Por qué.
 * @return array|WP_Error
 */
function dox_pos_counter_refund( $order_id, $lines, $method, $reason = '' ) {
	global $wpdb;
	// Una devolución a la vez por pedido: dos peticiones iguales a la vez pasarían las dos la cuenta de
	// lo que queda por devolver y sacarían el dinero dos veces.
	$lock = 'dox_pos_refund_' . (int) $order_id;
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
		return new WP_Error( 'dox_pos_ocupado', __( 'That order is being returned on another device. Try again in a moment.', 'dox-pos' ) );
	}
	try {
		return dox_pos_counter_refund_locked( $order_id, $lines, $method, $reason );
	} finally {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

/**
 * Lo que el Mostrador deja ver y devolver. Quien administra la tienda, cualquier pedido pagado (un
 * cliente de la web puede devolver en la tienda). Los demás, solo las ventas hechas en el Mostrador.
 *
 * @param WC_Order|null $order El pedido.
 * @return WC_Order|WP_Error
 */
function dox_pos_counter_order_access( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'dox_pos_no_pedido', __( 'That order does not exist.', 'dox-pos' ) );
	}
	if ( ! current_user_can( 'manage_woocommerce' ) && ! $order->get_meta( '_dox_pos_counter' ) ) {
		return new WP_Error( 'dox_pos_no_pedido', __( 'That order does not exist.', 'dox-pos' ) ); // Lo mismo que si no existiera: no se adivinan pedidos.
	}
	return $order;
}

/**
 * La devolución, ya con el pedido bloqueado (ver dox_pos_counter_refund).
 */
function dox_pos_counter_refund_locked( $order_id, $lines, $method, $reason = '' ) {
	$order = dox_pos_counter_order_access( wc_get_order( (int) $order_id ) );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	if ( ! in_array( $order->get_status(), array( 'completed', 'processing' ), true ) ) {
		return new WP_Error( 'dox_pos_no_devolver', __( 'This order was not paid: there is nothing to return.', 'dox-pos' ) );
	}
	$shift = dox_pos_counter_open_shift();
	if ( 'efectivo' === $method && ! $shift ) {
		return new WP_Error( 'dox_pos_caja_cerrada', __( 'Open the till to give cash back.', 'dox-pos' ) );
	}
	// La misma línea dos veces cuenta como una con la suma (si no, cada una pasaría sola la comprobación).
	$want = array();
	foreach ( (array) $lines as $l ) {
		$item_id = (int) ( is_array( $l ) ? ( $l['id'] ?? 0 ) : 0 );
		$qty     = (int) ( is_array( $l ) ? ( $l['qty'] ?? 0 ) : 0 );
		if ( $item_id && $qty > 0 ) {
			$want[ $item_id ] = ( $want[ $item_id ] ?? 0 ) + $qty;
		}
	}
	$dec    = wc_get_price_decimals();
	$items  = array();
	$amount = 0.0;
	foreach ( $want as $item_id => $qty ) {
		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$left = (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
		if ( $qty > $left ) {
			return new WP_Error( 'dox_pos_de_mas', sprintf( /* translators: 1: units, 2: product */ __( 'Only %1$d of %2$s can still be returned.', 'dox-pos' ), $left, $item->get_name() ) );
		}
		// Su parte del total y de cada impuesto, rebajada con el descuento del pedido.
		$unit   = dox_pos_counter_unit_refund( $order, $item );
		$gross  = (float) $item->get_total() + (float) $item->get_total_tax();
		$factor = $gross > 0 ? $unit * (int) $item->get_quantity() / $gross : 0;
		$share  = $qty / max( 1, (int) $item->get_quantity() );
		$taxes  = array();
		$tsum   = 0.0;
		foreach ( (array) ( $item->get_taxes()['total'] ?? array() ) as $rate => $t ) {
			$taxes[ $rate ] = round( (float) $t * $share * $factor, $dec );
			$tsum          += $taxes[ $rate ];
		}
		$net               = round( (float) $item->get_total() * $share * $factor, $dec );
		$items[ $item_id ] = array( 'qty' => $qty, 'refund_total' => $net, 'refund_tax' => $taxes );
		$amount           += $net + $tsum;
	}
	if ( ! $items ) {
		return new WP_Error( 'dox_pos_sin_lineas', __( 'Choose what is being returned.', 'dox-pos' ) );
	}
	$amount = min( round( $amount, $dec ), (float) $order->get_remaining_refund_amount() );
	// Quien no administra devuelve en efectivo como mucho lo que se pagó en efectivo (y aún no se
	// devolvió): una venta con tarjeta no puede salir del cajón.
	if ( 'efectivo' === $method && ! current_user_can( 'manage_woocommerce' ) ) {
		$cash = 0.0;
		foreach ( dox_pos_counter_order_payments( $order ) as $p ) {
			$cash += 'efectivo' === ( $p['key'] ?? '' ) ? (float) $p['amount'] : 0;
		}
		foreach ( $order->get_refunds() as $r ) {
			$cash -= 'efectivo' === $r->get_meta( '_dox_pos_refund_method' ) ? (float) $r->get_amount() : 0;
		}
		if ( $amount > round( $cash, $dec ) + 0.00001 ) {
			return new WP_Error( 'dox_pos_no_efectivo', __( 'This sale was not paid in cash (or that part was already returned): give the money back by the same payment method.', 'dox-pos' ) );
		}
	}
	$user   = dox_pos_counter_actor();
	// Si se cobró en un lector conectado (el del Pro), el dinero vuelve a la tarjeta aquí, antes de
	// registrar la devolución: si el banco no la acepta, no queda nada a medias.
	$paid_back = apply_filters( 'dox_pos_counter_refund_payment', true, $order, $amount, $method );
	if ( is_wp_error( $paid_back ) ) {
		return $paid_back;
	}
	$refund = wc_create_refund(
		array(
			'order_id'       => $order->get_id(),
			'amount'         => $amount,
			'reason'         => $reason ? sanitize_text_field( $reason ) : __( 'Returned at the counter', 'dox-pos' ),
			'line_items'     => $items,
			'restock_items'  => true,   // Las piezas vuelven al inventario.
			'refund_payment' => false,  // El dinero lo da la tienda: del cajón o en el datáfono.
		)
	);
	if ( is_wp_error( $refund ) ) {
		return $refund;
	}
	$refund->update_meta_data( '_dox_pos_refund_method', 'efectivo' === $method ? 'efectivo' : 'original' );
	$refund->update_meta_data( '_dox_pos_seller_name', $user->display_name );
	if ( $shift ) {
		$refund->update_meta_data( '_dox_pos_shift', (int) $shift->id );
	}
	$refund->save();
	$order->add_order_note( sprintf( /* translators: 1: amount, 2: user, 3: how */ __( 'Returned at the counter: %1$s by %2$s (%3$s).', 'dox-pos' ), html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) ), $user->display_name, 'efectivo' === $method ? __( 'cash from the drawer', 'dox-pos' ) : __( 'same payment method', 'dox-pos' ) ) );
	return array(
		'amount'  => $amount,
		'method'  => 'efectivo' === $method ? 'efectivo' : 'original',
		'to_card' => 'card' === $paid_back, // El lector conectado ya devolvió el dinero a la tarjeta.
		'receipt' => dox_pos_counter_receipt( wc_get_order( $order->get_id() ) ),
		'shift'   => $shift ? dox_pos_counter_format_shift( dox_pos_counter_get_shift( (int) $shift->id ) ) : null,
	);
}

// ---------- más rutas ----------

/**
 * El permiso de las rutas del Mostrador: el de la caja, y además el Mostrador encendido en Ajustes.
 * También lo usan las rutas del Mostrador del Pro.
 */
function dox_pos_counter_rest_permission( $request = null ) {
	if ( ! dox_pos_counter_on() ) {
		return new WP_Error( 'dox_pos_sin_mostrador', __( 'The Counter is turned off in Settings.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	return dox_pos_rest_permission( $request );
}

add_action( 'rest_api_init', 'dox_pos_counter_routes_2' );
function dox_pos_counter_routes_2() {
	$ns   = 'dox-pos/v1';
	$perm = 'dox_pos_counter_rest_permission';
	register_rest_route( $ns, '/counter/shift', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_counter_shift', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/counter/shift/open', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_counter_shift_open', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/counter/shift/close', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_counter_shift_close', 'permission_callback' => $perm ) );
	register_rest_route(
		$ns,
		'/counter/order',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dox_pos_rest_counter_order',
			'permission_callback' => $perm,
			'args'                => array( 'q' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		)
	);
	register_rest_route( $ns, '/counter/refund', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_counter_refund', 'permission_callback' => $perm ) );
}

function dox_pos_rest_counter_shift() {
	return rest_ensure_response(
		array(
			'shift'      => dox_pos_counter_format_shift( dox_pos_counter_open_shift() ),
			'last_float' => (float) get_option( 'dox_pos_last_float', 0 ),
		)
	);
}

function dox_pos_rest_counter_shift_open( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();
	return rest_ensure_response( array( 'shift' => dox_pos_counter_format_shift( dox_pos_counter_open( (float) wc_format_decimal( $b['float'] ?? 0 ) ) ) ) );
}

function dox_pos_rest_counter_shift_close( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();
	$r = dox_pos_counter_close( (float) wc_format_decimal( $b['counted'] ?? 0 ), (string) ( $b['note'] ?? '' ) );
	return dox_pos_rest_out( is_wp_error( $r ) ? $r : array( 'shift' => $r, 'store' => dox_pos_counter_receipt_settings() ) );
}

function dox_pos_rest_counter_order( WP_REST_Request $request ) {
	$o = dox_pos_counter_order_access( dox_pos_counter_find_order( $request->get_param( 'q' ) ) );
	return rest_ensure_response( array( 'receipt' => is_wp_error( $o ) ? null : dox_pos_counter_receipt( $o ) ) );
}

function dox_pos_rest_counter_refund( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();
	return dox_pos_rest_out( dox_pos_counter_refund( (int) ( $b['order'] ?? 0 ), (array) ( $b['lines'] ?? array() ), sanitize_key( $b['method'] ?? 'efectivo' ), (string) ( $b['reason'] ?? '' ) ) );
}

/**
 * Lo que necesita la vista previa del ticket en Ajustes: la tienda, el formato del dinero y una venta
 * de ejemplo con la forma de dox_pos_counter_receipt() (con impuesto aparte o incluido, como la tienda).
 *
 * @return array
 */
function dox_pos_counter_receipt_sample() {
	$included = wc_prices_include_tax();
	$dec      = wc_get_price_decimals();
	$f        = 0 === $dec ? 1000 : 1; // Sin decimales (pesos), los precios de ejemplo van en miles: un vestido de 150.000, no de 150.
	$lines    = array(
		array( 'name' => __( 'Ella dress · M · Pink', 'dox-pos' ), 'sku' => 'ED83M', 'qty' => 1, 'unit' => 150 * $f, 'total' => 150 * $f ),
		array( 'name' => __( 'Wool scarf · Camel', 'dox-pos' ), 'sku' => 'WS21C', 'qty' => 2, 'unit' => 22 * $f, 'total' => 44 * $f ),
	);
	$sub   = 194 * $f;
	$on    = wc_tax_enabled(); // El impuesto de ejemplo, solo si la tienda cobra impuestos.
	$tax   = $on ? round( $included ? $sub - $sub / 1.06 : $sub * 0.06, $dec ) : 0;
	$total = $included || ! $on ? $sub : $sub + $tax;
	$step  = 10 * $f;
	$paid  = ( floor( $total / $step ) + 1 ) * $step; // El billete redondo siguiente.
	return array(
		'name'   => dox_pos_brand_name(),
		'logo'   => dox_pos_logo_url(),
		'money'  => array(
			'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'pos'      => get_option( 'woocommerce_currency_pos', 'left' ),
			'thousand' => wc_get_price_thousand_separator(),
			'decimal'  => wc_get_price_decimal_separator(),
			'decimals' => $dec,
		),
		'sample' => array(
			'number'   => '2481',
			'date'     => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'seller'   => dox_pos_counter_actor()->display_name,
			'customer' => __( 'Marta Ruiz', 'dox-pos' ),
			'lines'    => $lines,
			'subtotal' => $sub,
			'discount' => 0,
			'taxes'    => $on ? array( array( 'label' => __( 'Tax', 'dox-pos' ), 'amount' => $tax ) ) : array(),
			'included' => $included,
			'total'    => $total,
			'payments' => array( array( 'title' => __( 'Cash payment', 'dox-pos' ), 'amount' => $total ) ),
			'tendered' => $paid,
			'change'   => round( $paid - $total, $dec ),
			'code'     => 'DOXPOS-2481',
		),
	);
}
