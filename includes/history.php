<?php
/**
 * El historial: las ventas, la caja del día (el cierre) y los movimientos de inventario (el
 * kardex). Las ventas y los ingresos salen de los pedidos de WooCommerce, que ya guardan
 * vendedora, canal, forma de pago y total; los movimientos, de la tabla del kardex
 * (stock-log.php). Todo se baja en Excel con el mismo generador del inventario.
 *
 * Quién ve qué: administradores y gerentes ven todo, con el costo y la ganancia de cada venta
 * (costs.php); una persona con el rol Caja ve solo sus ventas de hoy, para cuadrar su día, sin
 * los totales del negocio ni los costos.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DOX_POS_SOLD = array( 'processing', 'enviado', 'completed' ); // Vendido: pagado, o contraentrega en camino.
const DOX_POS_OPEN = array( 'on-hold', 'pending', 'failed' );       // Sin pagar todavía: apartados y pagos por confirmar.

/**
 * ¿Ve todo el historial? Administradores y gerentes de tienda.
 */
function dox_pos_history_full() {
	return current_user_can( 'manage_woocommerce' );
}

/**
 * El periodo, ya validado: fechas de la tienda (su zona horaria) y sus límites en segundos.
 * Sin fechas, hoy; como mucho, un año.
 *
 * @param string $from AAAA-MM-DD.
 * @param string $to   AAAA-MM-DD.
 * @return array{from:string,to:string,start:int,end:int,days:int}
 */
function dox_pos_history_range( $from, $to ) {
	$today = wp_date( 'Y-m-d' );
	$ok    = fn( $d ) => is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) );
	$from  = $ok( $from ) ? $from : $today;
	$to    = $ok( $to ) ? $to : $from;
	if ( $to < $from ) {
		list( $from, $to ) = array( $to, $from );
	}
	$tz    = wp_timezone();
	$start = new DateTime( $from . ' 00:00:00', $tz );
	$end   = new DateTime( $to . ' 23:59:59', $tz );
	if ( $end->getTimestamp() - $start->getTimestamp() > YEAR_IN_SECONDS ) {
		$start = ( clone $end )->modify( '-1 year' )->setTime( 0, 0, 0 );
		$from  = $start->format( 'Y-m-d' );
	}
	return array(
		'from'  => $from,
		'to'    => $to,
		'start' => $start->getTimestamp(),
		'end'   => $end->getTimestamp(),
		'days'  => (int) round( ( $end->getTimestamp() - $start->getTimestamp() ) / DAY_IN_SECONDS ),
	);
}

/**
 * Los pedidos del periodo, los más recientes primero: los de la caja y, si el ajuste lo dice,
 * los de la web y los manuales. Con $seller, solo los que registró esa persona.
 *
 * @param array $range  Lo que devuelve dox_pos_history_range().
 * @param int   $seller Usuario, o 0 para todos.
 * @return WC_Order[]
 */
function dox_pos_history_orders( $range, $seller = 0 ) {
	$args = array(
		'limit'        => -1,
		'type'         => 'shop_order',
		'orderby'      => 'date',
		'order'        => 'DESC',
		'date_created' => $range['start'] . '...' . $range['end'],
		'status'       => array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-enviado', 'wc-completed', 'wc-cancelled', 'wc-failed', 'wc-refunded' ),
	);
	if ( ! dox_pos_show_web_orders() || $seller ) {
		$args['created_via'] = DOX_POS_VIA;
	}
	$orders = wc_get_orders( $args );
	if ( $seller ) {
		$orders = array_values( array_filter( $orders, fn( $o ) => (int) $o->get_meta( '_dox_pos_seller' ) === (int) $seller ) );
	}
	return $orders;
}

function dox_pos_order_units( $order ) {
	$u = 0;
	foreach ( $order->get_items() as $item ) {
		$u += (int) $item->get_quantity();
	}
	return $u;
}

/**
 * Las ventas del periodo: totales, el reparto por canal, forma de pago, vendedora y día, y la lista.
 *
 * @param string $from   AAAA-MM-DD.
 * @param string $to     AAAA-MM-DD.
 * @param int    $seller Solo las de esta persona (0 = todas).
 * @param int    $limit  Cuántos pedidos van en la lista (0 = todos, para el Excel).
 * @return array
 */
function dox_pos_history_sales( $from, $to, $seller = 0, $limit = 300 ) {
	$range  = dox_pos_history_range( $from, $to );
	$orders = dox_pos_history_orders( $range, $seller );
	$see    = dox_pos_can_see_costs(); // El costo y la ganancia: solo para quien administra.
	$losses = dox_pos_can_see_losses(); // Las pérdidas anotadas en los pedidos: también solo para quien administra.
	$t      = array( 'orders' => 0, 'units' => 0, 'sold' => 0.0, 'avg' => 0.0, 'pending_n' => 0, 'pending' => 0.0, 'cancelled_n' => 0 );
	if ( $see ) {
		// La ganancia suma solo las ventas con el costo de todas sus líneas; las que no lo tienen se cuentan aparte.
		$t += array( 'cost' => 0.0, 'profit' => 0.0, 'revenue' => 0.0, 'margin' => null, 'profit_n' => 0, 'no_cost_n' => 0 );
	}
	if ( $losses ) {
		$t += array( 'loss' => 0.0, 'loss_n' => 0 );
	}
	$by  = array( 'channel' => array(), 'payment' => array(), 'seller' => array(), 'day' => array() );
	$add = function ( &$arr, $key, $total, $units, $profit = null ) {
		$key = '' === (string) $key ? __( 'No data', 'dox-pos' ) : (string) $key;
		if ( ! isset( $arr[ $key ] ) ) {
			$arr[ $key ] = array( 'name' => $key, 'n' => 0, 'units' => 0, 'total' => 0.0, 'profit' => 0.0 );
		}
		$arr[ $key ]['n']++;
		$arr[ $key ]['units'] += $units;
		$arr[ $key ]['total'] += $total;
		if ( null !== $profit ) {
			$arr[ $key ]['profit'] += $profit;
		}
	};
	$items = array();
	foreach ( $orders as $o ) {
		$f          = dox_pos_format_order( $o );
		$f['units'] = dox_pos_order_units( $o );
		$f['day']   = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'Y-m-d' ) : $range['from'];
		$status     = $o->get_status();
		$pr         = null;
		if ( $see ) {
			$pr                = dox_pos_order_profit( $o );
			$f['cost']         = $pr['cost'];
			$f['profit']       = $pr['profit'];
			$f['margin']       = null === $pr['profit'] ? null : dox_pos_margin( $pr['profit'], $pr['revenue'] );
			$f['cost_missing'] = $pr['missing']; // Líneas sin costo: con alguna, la ganancia de esa venta no se sabe.
		}
		if ( $losses ) {
			$f['loss']      = dox_pos_order_loss( $o );
			$f['loss_note'] = (string) $o->get_meta( '_dox_pos_loss_note' );
		}
		if ( in_array( $status, DOX_POS_SOLD, true ) ) {
			$t['orders']++;
			$t['units'] += $f['units'];
			$t['sold']  += $f['total'];
			if ( $losses && $f['loss'] > 0 ) {
				$t['loss'] += $f['loss'];
				$t['loss_n']++;
			}
			$profit      = null;
			if ( $pr ) {
				if ( null !== $pr['profit'] ) {
					$t['cost']    += $pr['cost'];
					$t['profit']  += $pr['profit'];
					$t['revenue'] += $pr['revenue'];
					$t['profit_n']++;
					$profit = $pr['profit'];
				} else {
					$t['no_cost_n']++;
				}
			}
			$add( $by['channel'], $f['channel'], $f['total'], $f['units'], $profit );
			$add( $by['payment'], $f['payment'], $f['total'], $f['units'], $profit );
			$add( $by['seller'], $f['seller'] ? $f['seller'] : ( 'caja' === $f['origin'] ? '' : $f['origin_label'] ), $f['total'], $f['units'], $profit );
			$add( $by['day'], $f['day'], $f['total'], $f['units'], $profit );
		} elseif ( in_array( $status, DOX_POS_OPEN, true ) ) {
			$t['pending_n']++;
			$t['pending'] += $f['total'];
		} else {
			$t['cancelled_n']++;
		}
		if ( ! $limit || count( $items ) < $limit ) {
			$items[] = $f;
		}
	}
	$t['avg'] = $t['orders'] ? $t['sold'] / $t['orders'] : 0.0;
	if ( $see ) {
		$t['margin'] = dox_pos_margin( $t['profit'], $t['revenue'] );
	}
	$desc = fn( $a, $b ) => $b['total'] <=> $a['total'];
	usort( $by['channel'], $desc );
	usort( $by['payment'], $desc );
	usort( $by['seller'], $desc );
	ksort( $by['day'] );
	return array(
		'from'       => $range['from'],
		'to'         => $range['to'],
		'totals'     => $t,
		'by_channel' => array_values( $by['channel'] ),
		'by_payment' => array_values( $by['payment'] ),
		'by_seller'  => array_values( $by['seller'] ),
		'by_day'     => array_values( $by['day'] ),
		'items'      => $items,
		'count'      => count( $orders ),
		'costs'      => $see,
		'losses'     => $losses,
	);
}

/**
 * La caja: cuánto entró cada día y por qué forma de pago, lo que está por cobrar (contraentregas
 * en camino) y lo apartado sin pagar. Un contraentrega cuenta como cobrado al marcarlo entregado.
 *
 * @param string $from AAAA-MM-DD.
 * @param string $to   AAAA-MM-DD.
 * @return array
 */
function dox_pos_history_cash( $from, $to ) {
	$range   = dox_pos_history_range( $from, $to );
	$orders  = dox_pos_history_orders( $range );
	$see     = dox_pos_can_see_costs();
	$losses  = dox_pos_can_see_losses();
	$days    = array();
	$methods = array();
	$t       = array( 'orders' => 0, 'sold' => 0.0, 'cashed' => 0.0, 'cod' => 0.0, 'cod_n' => 0, 'holds' => 0.0, 'holds_n' => 0 );
	if ( $see ) {
		$t += array( 'cost' => 0.0, 'profit' => 0.0, 'no_cost_n' => 0 ); // La ganancia del día, con las ventas que tienen costo completo.
	}
	if ( $losses ) {
		$t += array( 'loss' => 0.0 ); // Las pérdidas anotadas en las ventas del periodo.
	}
	$pending = array();
	foreach ( $orders as $o ) {
		$f      = dox_pos_format_order( $o );
		$day    = $o->get_date_created() ? $o->get_date_created()->date_i18n( 'Y-m-d' ) : $range['from'];
		$status = $o->get_status();
		if ( ! isset( $days[ $day ] ) ) {
			$days[ $day ] = array( 'day' => $day, 'n' => 0, 'sold' => 0.0, 'cashed' => 0.0, 'methods' => array(), 'cod' => 0.0, 'holds' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'loss' => 0.0 );
		}
		if ( in_array( $status, DOX_POS_SOLD, true ) ) {
			$days[ $day ]['n']++;
			$days[ $day ]['sold'] += $f['total'];
			$t['orders']++;
			$t['sold'] += $f['total'];
			if ( $see ) {
				$pr = dox_pos_order_profit( $o );
				if ( null !== $pr['profit'] ) {
					$days[ $day ]['cost']   += $pr['cost'];
					$days[ $day ]['profit'] += $pr['profit'];
					$t['cost']              += $pr['cost'];
					$t['profit']            += $pr['profit'];
				} else {
					$t['no_cost_n']++;
				}
			}
			if ( $losses ) {
				$loss                  = dox_pos_order_loss( $o );
				$days[ $day ]['loss'] += $loss;
				$t['loss']            += $loss;
			}
			if ( $f['cod'] && 'completed' !== $status ) {
				$days[ $day ]['cod'] += $f['total'];
				$t['cod']           += $f['total'];
				$t['cod_n']++;
				$f['kind']  = 'cod';
				$pending[]  = $f;
			} else {
				$m = $f['payment'] ? $f['payment'] : __( 'No payment method', 'dox-pos' );
				$days[ $day ]['cashed']        += $f['total'];
				$days[ $day ]['methods'][ $m ]  = ( $days[ $day ]['methods'][ $m ] ?? 0 ) + $f['total'];
				$t['cashed']                   += $f['total'];
				if ( ! isset( $methods[ $m ] ) ) {
					$methods[ $m ] = array( 'name' => $m, 'n' => 0, 'total' => 0.0 );
				}
				$methods[ $m ]['n']++;
				$methods[ $m ]['total'] += $f['total'];
			}
		} elseif ( in_array( $status, DOX_POS_OPEN, true ) ) {
			$days[ $day ]['holds'] += $f['total'];
			$t['holds']           += $f['total'];
			$t['holds_n']++;
			$f['kind'] = 'hold';
			$pending[] = $f;
		}
	}
	$days = array_filter( $days, fn( $d ) => $d['n'] || $d['holds'] > 0 );
	ksort( $days );
	usort( $methods, fn( $a, $b ) => $b['total'] <=> $a['total'] );
	return array(
		'from'          => $range['from'],
		'to'            => $range['to'],
		'totals'        => $t,
		'methods'       => array_column( $methods, 'name' ),
		'method_totals' => array_values( $methods ),
		'days'          => array_values( $days ),
		'pending'       => array_slice( $pending, 0, 200 ),
		'costs'         => $see,
		'losses'        => $losses,
	);
}

/**
 * Los movimientos del kardex en el periodo, con filtros: lo escrito (producto o código), un
 * producto, un tipo de motivo; por páginas, los más recientes primero. Y el resumen: cuántas
 * unidades entraron y salieron, por motivo.
 *
 * @param array $a from, to, q, product, kind, page, per.
 * @return array
 */
function dox_pos_history_stock( $a ) {
	global $wpdb;
	$range = dox_pos_history_range( $a['from'] ?? '', $a['to'] ?? '' );
	$where = array( 'created_at BETWEEN %s AND %s' );
	$args  = array( $range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59' );
	$q     = trim( (string) ( $a['q'] ?? '' ) );
	if ( '' !== $q ) {
		$like    = '%' . $wpdb->esc_like( $q ) . '%';
		$where[] = '( name LIKE %s OR sku LIKE %s )';
		$args[]  = $like;
		$args[]  = $like;
	}
	$pid = (int) ( $a['product'] ?? 0 );
	if ( $pid ) {
		$where[] = 'product_id = %d';
		$args[]  = $pid;
	}
	$kinds = dox_pos_stock_kinds();
	$kind  = (string) ( $a['kind'] ?? '' );
	if ( isset( $kinds[ $kind ] ) ) {
		$where[] = 'reason IN (' . implode( ',', array_fill( 0, count( $kinds[ $kind ] ), '%s' ) ) . ')';
		$args    = array_merge( $args, $kinds[ $kind ] );
	}
	$w     = implode( ' AND ', $where );
	$table = dox_pos_stock_log_table();
	$page  = max( 1, (int) ( $a['page'] ?? 1 ) );
	$per   = max( 1, min( 5000, (int) ( $a['per'] ?? 50 ) ) );
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$w}", $table, ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Las condiciones son fijas y llevan sus marcadores; el sniff no cuenta los argumentos desempaquetados.
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", $table, ...array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Las condiciones son fijas y llevan sus marcadores; el sniff no cuenta los argumentos desempaquetados.
	$sum   = $wpdb->get_results( $wpdb->prepare( "SELECT reason, COUNT(*) n, SUM( CASE WHEN delta > 0 THEN delta ELSE 0 END ) entradas, SUM( CASE WHEN delta < 0 THEN -delta ELSE 0 END ) salidas FROM %i WHERE {$w} GROUP BY reason ORDER BY n DESC", $table, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Las condiciones son fijas y llevan sus marcadores; el sniff no cuenta los argumentos desempaquetados.
	$in    = 0;
	$out   = 0;
	$by    = array();
	foreach ( (array) $sum as $s ) {
		$in  += (int) $s['entradas'];
		$out += (int) $s['salidas'];
		$by[] = array( 'reason' => $s['reason'], 'label' => dox_pos_stock_reason_label( $s['reason'] ), 'n' => (int) $s['n'], 'in' => (int) $s['entradas'], 'out' => (int) $s['salidas'] );
	}
	return array(
		'from'      => $range['from'],
		'to'        => $range['to'],
		'items'     => array_map( 'dox_pos_format_stock_row', (array) $rows ),
		'total'     => $total,
		'page'      => $page,
		'pages'     => max( 1, (int) ceil( $total / $per ) ),
		'in'        => $in,
		'out'       => $out,
		'by_reason' => $by,
		'costs'     => dox_pos_can_see_costs(),
	);
}

function dox_pos_format_stock_row( $r ) {
	// El costo por unidad en ese momento (el de compra si fue una entrada), solo para quien administra.
	$unit = dox_pos_can_see_costs() && isset( $r['unit_cost'] ) && null !== $r['unit_cost'] && '' !== $r['unit_cost'] ? (float) $r['unit_cost'] : null;
	return array(
		'id'           => (int) $r['id'],
		'date'         => mysql2date( 'd/m H:i', $r['created_at'] ),
		'datetime'     => mysql2date( 'd/m/Y H:i', $r['created_at'] ),
		'product_id'   => (int) $r['product_id'],
		'variation_id' => (int) $r['variation_id'],
		'sku'          => (string) $r['sku'],
		'name'         => (string) $r['name'],
		'before'       => null === $r['qty_before'] ? null : (int) $r['qty_before'],
		'after'        => null === $r['qty_after'] ? null : (int) $r['qty_after'],
		'delta'        => (int) $r['delta'],
		'reason'       => (string) $r['reason'],
		'label'        => dox_pos_stock_reason_label( $r['reason'], (int) $r['ref_id'] ),
		'ref'          => (int) $r['ref_id'],
		'user'         => '' !== (string) $r['user_name'] ? (string) $r['user_name'] : ( (int) $r['user_id'] ? '' : __( 'Automatic', 'dox-pos' ) ),
		'note'         => (string) $r['note'],
		'unit_cost'    => $unit,
		'value'        => null === $unit ? null : round( (int) $r['delta'] * $unit, 2 ), // Lo que vale el movimiento al costo.
	);
}

/* =====================================================================
 * En Excel
 * ===================================================================== */

/**
 * Manda uno de los tres informes como .xlsx y termina. Se llama desde
 * /caja/?descargar=ventas|caja|movimientos&desde=&hasta= con la sesión ya comprobada.
 *
 * @param string $what    ventas | caja | movimientos.
 * @param string $from    AAAA-MM-DD.
 * @param string $to      AAAA-MM-DD.
 * @param string $q       Solo movimientos: lo escrito.
 * @param int    $product Solo movimientos: un producto.
 * @param string $kind    Solo movimientos: el tipo de motivo.
 */
function dox_pos_send_history( $what, $from, $to, $q = '', $product = 0, $kind = '' ) {
	$full = dox_pos_history_full();
	if ( ! $full && 'ventas' !== $what ) {
		wp_die( esc_html__( 'That report is for administrators and shop managers.', 'dox-pos' ), '', array( 'response' => 403 ) );
	}
	if ( 'ventas' === $what ) {
		$d    = $full ? dox_pos_history_sales( $from, $to, 0, 0 ) : dox_pos_history_sales( '', '', get_current_user_id(), 0 );
		$file = dox_pos_sales_xlsx( $d );
	} elseif ( 'caja' === $what ) {
		$d    = dox_pos_history_cash( $from, $to );
		$file = dox_pos_cash_xlsx( $d );
	} else {
		$d    = dox_pos_history_stock( array( 'from' => $from, 'to' => $to, 'q' => $q, 'product' => $product, 'kind' => $kind, 'page' => 1, 'per' => 5000 ) );
		$file = dox_pos_stock_xlsx( $d );
	}
	$name = sanitize_file_name( $what . '-' . sanitize_title( remove_accents( dox_pos_brand_name() ) ) . '-' . $d['from'] . ( $d['to'] !== $d['from'] ? '-a-' . $d['to'] : '' ) . '.xlsx' );
	dox_pos_send_xlsx( $file, $name );
}

/**
 * Ventas: una hoja con cada pedido y otra con el resumen por día, canal, forma de pago y vendedora.
 */
function dox_pos_sales_xlsx( $d ) {
	$see  = ! empty( $d['costs'] );
	$cols = array(
		array( __( 'Order', 'dox-pos' ), 'number', 'text', 9 ),
		array( __( 'Date', 'dox-pos' ), 'date', 'text', 13 ),
		array( __( 'Customer', 'dox-pos' ), 'customer', 'text', 24 ),
		array( __( 'City', 'dox-pos' ), 'city', 'text', 20 ),
		array( __( 'Products', 'dox-pos' ), 'items', 'text', 44 ),
		array( __( 'Units', 'dox-pos' ), 'units', 'int', 10 ),
		array( __( 'Channel', 'dox-pos' ), 'channel', 'text', 14 ),
		array( __( 'Salesperson', 'dox-pos' ), 'seller', 'text', 18 ),
		array( __( 'Payment', 'dox-pos' ), 'payment', 'text', 16 ),
		array( __( 'Total', 'dox-pos' ), 'total', 'money', 13 ),
	);
	if ( ! empty( $d['losses'] ) ) { // La pérdida anotada en el pedido, si la hay.
		$cols[] = array( __( 'Loss', 'dox-pos' ), 'loss', 'money', 13 );
	}
	if ( $see ) { // El costo congelado al venderse y lo que dejó; vacío en las ventas sin costo.
		$cols[] = array( __( 'Cost', 'dox-pos' ), 'cost', 'money', 13 );
		$cols[] = array( __( 'Profit', 'dox-pos' ), 'profit', 'money', 13 );
		$cols[] = array( __( 'Margin %', 'dox-pos' ), 'margin', 'int', 10 );
	}
	$cols[] = array( __( 'Status', 'dox-pos' ), 'label', 'text', 18 );
	$rows   = array();
	foreach ( $d['items'] as $p ) {
		$sold        = in_array( $p['status'], array( 'por_enviar', 'enviado', 'entregado' ), true );
		$p['seller'] = $p['seller'] ? $p['seller'] : ( 'caja' === $p['origin'] ? '' : $p['origin_label'] );
		$p['label']  = $p['label'] . ( $p['cod'] && 'entregado' !== $p['status'] && $sold ? ' · ' . __( 'cash on delivery', 'dox-pos' ) : '' );
		if ( $see && ! $sold ) {
			$p['cost']   = null; // Una venta anulada o sin pagar no cuenta.
			$p['profit'] = null;
			$p['margin'] = null;
		}
		$rows[] = $p;
	}
	$n      = 1;
	$lines  = dox_pos_xlsx_rows( $cols, $rows, $n );
	$sheet1 = dox_pos_xlsx_sheet( $cols, array( $lines ), $n );

	// Hoja 2: el resumen, en secciones.
	$cols2 = array(
		array( __( 'Day', 'dox-pos' ), 'name', 'text', 26 ),
		array( __( 'Sales', 'dox-pos' ), 'n', 'int', 10 ),
		array( __( 'Units', 'dox-pos' ), 'units', 'int', 10 ),
		array( __( 'Total', 'dox-pos' ), 'total', 'money', 14 ),
	);
	$sum = array( 'n', 'units', 'total' );
	if ( $see ) {
		$cols2[] = array( __( 'Profit', 'dox-pos' ), 'profit', 'money', 14 );
		$sum[]   = 'profit';
	}
	$n     = 1;
	$lines = array();
	$t     = $d['totals'];
	$lines[] = dox_pos_xlsx_rows( $cols2, array_map( fn( $x ) => array( 'name' => mysql2date( 'D d/m/Y', $x['name'] . ' 12:00:00' ), 'n' => $x['n'], 'units' => $x['units'], 'total' => $x['total'], 'profit' => $x['profit'] ?? null ), $d['by_day'] ), $n, $sum );
	foreach ( array( 'by_channel' => __( 'Channel', 'dox-pos' ), 'by_payment' => __( 'Payment method', 'dox-pos' ), 'by_seller' => __( 'Salesperson', 'dox-pos' ) ) as $key => $title ) {
		if ( ! $d[ $key ] ) {
			continue;
		}
		$n      += 2;
		$c       = $cols2;
		$c[0][0] = $title;
		$lines[] = dox_pos_xlsx_header( $c, $n );
		$lines[] = dox_pos_xlsx_rows( $c, $d[ $key ], $n, $sum );
	}
	$n      += 2;
	$extra   = array(
		array( 'name' => __( 'Not paid yet (layaways and payments to confirm)', 'dox-pos' ), 'n' => $t['pending_n'], 'units' => null, 'total' => $t['pending'], 'profit' => null ),
		array( 'name' => __( 'Cancelled orders', 'dox-pos' ), 'n' => $t['cancelled_n'], 'units' => null, 'total' => null, 'profit' => null ),
	);
	if ( $see ) {
		$extra[] = array( 'name' => __( 'Sales without a complete cost (not counted in the profit)', 'dox-pos' ), 'n' => $t['no_cost_n'], 'units' => null, 'total' => null, 'profit' => null );
		$extra[] = array( 'name' => __( 'Margin on sales with a cost', 'dox-pos' ), 'n' => null, 'units' => null, 'total' => null, 'profit' => null === $t['margin'] ? null : $t['margin'] . ' %' );
	}
	if ( ! empty( $d['losses'] ) && $t['loss'] > 0 ) {
		$extra[] = array( 'name' => __( 'Losses noted on the sales', 'dox-pos' ), 'n' => $t['loss_n'], 'units' => null, 'total' => $t['loss'], 'profit' => null );
	}
	$c       = $cols2;
	$c[0][0] = __( 'Aside', 'dox-pos' );
	if ( $see ) {
		$c[4][2] = 'text'; // El margen va como texto ("43 %").
	}
	$lines[] = dox_pos_xlsx_header( $c, $n );
	$lines[] = dox_pos_xlsx_rows( $c, $extra, $n );
	$sheet2  = dox_pos_xlsx_sheet( $cols2, $lines, 1, false, false );

	return dox_pos_xlsx_workbook(
		array(
			array( 'name' => __( 'Sales', 'dox-pos' ), 'xml' => $sheet1 ),
			array( 'name' => __( 'Summary', 'dox-pos' ), 'xml' => $sheet2 ),
		)
	);
}

/**
 * La caja: una fila por día con lo vendido, lo cobrado por cada forma de pago, lo que está por
 * cobrar y lo apartado; y una segunda hoja con los pedidos por cobrar.
 */
function dox_pos_cash_xlsx( $d ) {
	$see  = ! empty( $d['costs'] );
	$cols = array(
		array( __( 'Day', 'dox-pos' ), 'day', 'text', 16 ),
		array( __( 'Sales', 'dox-pos' ), 'n', 'int', 9 ),
		array( __( 'Sold', 'dox-pos' ), 'sold', 'money', 14 ),
	);
	$sum = array( 'n', 'sold' );
	if ( $see ) {
		$cols[] = array( __( 'Cost', 'dox-pos' ), 'cost', 'money', 14 );
		$cols[] = array( __( 'Profit', 'dox-pos' ), 'profit', 'money', 14 );
		$sum[]  = 'cost';
		$sum[]  = 'profit';
	}
	if ( ! empty( $d['losses'] ) ) {
		$cols[] = array( __( 'Losses', 'dox-pos' ), 'loss', 'money', 14 );
		$sum[]  = 'loss';
	}
	$cols[] = array( __( 'Collected', 'dox-pos' ), 'cashed', 'money', 14 );
	$sum[]  = 'cashed';
	foreach ( $d['methods'] as $i => $m ) {
		$cols[] = array( $m, 'm' . $i, 'money', 14 );
		$sum[]  = 'm' . $i;
	}
	$cols[] = array( __( 'To collect (cash on delivery)', 'dox-pos' ), 'cod', 'money', 16 );
	$cols[] = array( __( 'Unpaid layaway', 'dox-pos' ), 'holds', 'money', 16 );
	$sum[]  = 'cod';
	$sum[]  = 'holds';
	$rows   = array();
	foreach ( $d['days'] as $r ) {
		$row = array( 'day' => mysql2date( 'D d/m/Y', $r['day'] . ' 12:00:00' ), 'n' => $r['n'], 'sold' => $r['sold'], 'cashed' => $r['cashed'], 'cod' => $r['cod'] ? $r['cod'] : null, 'holds' => $r['holds'] ? $r['holds'] : null, 'cost' => $r['cost'] ?? null, 'profit' => $r['profit'] ?? null, 'loss' => ! empty( $r['loss'] ) ? $r['loss'] : null );
		foreach ( $d['methods'] as $i => $m ) {
			$row[ 'm' . $i ] = isset( $r['methods'][ $m ] ) ? $r['methods'][ $m ] : null;
		}
		$rows[] = $row;
	}
	$n      = 1;
	$sheet1 = dox_pos_xlsx_sheet( $cols, array( dox_pos_xlsx_rows( $cols, $rows, $n, $sum ) ), $n );

	$cols2 = array(
		array( __( 'Order', 'dox-pos' ), 'number', 'text', 9 ),
		array( __( 'Date', 'dox-pos' ), 'date', 'text', 13 ),
		array( __( 'Customer', 'dox-pos' ), 'customer', 'text', 24 ),
		array( __( 'Products', 'dox-pos' ), 'items', 'text', 40 ),
		array( __( 'What', 'dox-pos' ), 'kind', 'text', 22 ),
		array( __( 'Status', 'dox-pos' ), 'label', 'text', 18 ),
		array( __( 'Total', 'dox-pos' ), 'total', 'money', 13 ),
	);
	$rows2 = array();
	foreach ( $d['pending'] as $p ) {
		$p['kind'] = 'cod' === $p['kind'] ? __( 'Cash on delivery on its way', 'dox-pos' ) : __( 'Unpaid', 'dox-pos' );
		$rows2[]   = $p;
	}
	$n      = 1;
	$sheet2 = dox_pos_xlsx_sheet( $cols2, array( dox_pos_xlsx_rows( $cols2, $rows2, $n, array( 'total' ) ) ), $n );

	return dox_pos_xlsx_workbook(
		array(
			array( 'name' => __( 'Cash', 'dox-pos' ), 'xml' => $sheet1 ),
			array( 'name' => __( 'To collect', 'dox-pos' ), 'xml' => $sheet2 ),
		)
	);
}

/**
 * Los movimientos: cada fila del kardex, y el resumen por motivo.
 */
function dox_pos_stock_xlsx( $d ) {
	$see  = dox_pos_can_see_costs();
	$cols = array(
		array( __( 'When', 'dox-pos' ), 'datetime', 'text', 16 ),
		array( __( 'SKU', 'dox-pos' ), 'sku', 'text', 13 ),
		array( __( 'Product', 'dox-pos' ), 'name', 'text', 40, 'url' ), // El nombre lleva a su ficha en la tienda.
		array( __( 'Before', 'dox-pos' ), 'before', 'int', 9 ),
		array( __( 'Change', 'dox-pos' ), 'delta', 'int', 9 ),
		array( __( 'After', 'dox-pos' ), 'after', 'int', 9 ),
	);
	if ( $see ) { // El costo por unidad en ese momento y lo que vale el movimiento.
		$cols[] = array( __( 'Unit cost', 'dox-pos' ), 'unit_cost', 'money', 13 );
		$cols[] = array( __( 'Value', 'dox-pos' ), 'value', 'money', 14 );
	}
	$cols[] = array( __( 'Reason', 'dox-pos' ), 'label', 'text', 24 );
	$cols[] = array( __( 'Who', 'dox-pos' ), 'user', 'text', 18 );
	$cols[] = array( __( 'Note', 'dox-pos' ), 'note', 'text', 24 );
	$items  = $d['items'];
	$urls   = dox_pos_product_urls( array_column( $items, 'product_id' ) );
	foreach ( $items as &$it ) {
		$it['url'] = $urls[ (int) $it['product_id'] ] ?? '';
	}
	unset( $it );
	$n      = 1;
	$links  = array();
	$lines  = dox_pos_xlsx_rows( $cols, $items, $n, array(), $links );
	$sheet1 = dox_pos_xlsx_sheet( $cols, array( $lines ), $n, false, true, $links );
	$cols2  = array(
		array( __( 'Reason', 'dox-pos' ), 'label', 'text', 26 ),
		array( __( 'Movements', 'dox-pos' ), 'n', 'int', 13 ),
		array( __( 'In', 'dox-pos' ), 'in', 'int', 11 ),
		array( __( 'Out', 'dox-pos' ), 'out', 'int', 11 ),
	);
	$n      = 1;
	$sheet2 = dox_pos_xlsx_sheet( $cols2, array( dox_pos_xlsx_rows( $cols2, $d['by_reason'], $n, array( 'n', 'in', 'out' ) ) ), $n );
	return dox_pos_xlsx_workbook(
		array(
			array( 'name' => __( 'Movements', 'dox-pos' ), 'xml' => $sheet1, 'links' => $links ),
			array( 'name' => __( 'Summary', 'dox-pos' ), 'xml' => $sheet2 ),
		)
	);
}

/* =====================================================================
 * La API
 * ===================================================================== */

/**
 * Ver el historial completo pide, además de la caja, el permiso de la tienda.
 *
 * @return bool|WP_Error
 */
function dox_pos_rest_history_permission() {
	$ok = dox_pos_rest_permission();
	if ( true !== $ok ) {
		return $ok;
	}
	if ( ! dox_pos_history_full() ) {
		return new WP_Error( 'dox_pos_sin_permiso', __( 'The full history is for administrators and shop managers.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Las ventas: todas para quien ve el historial completo; para el rol Caja, solo las suyas de hoy.
 */
function dox_pos_rest_history_sales( WP_REST_Request $request ) {
	if ( dox_pos_history_full() ) {
		return rest_ensure_response( dox_pos_history_sales( (string) $request->get_param( 'from' ), (string) $request->get_param( 'to' ) ) );
	}
	return rest_ensure_response( dox_pos_history_sales( '', '', get_current_user_id() ) );
}

function dox_pos_rest_history_cash( WP_REST_Request $request ) {
	return rest_ensure_response( dox_pos_history_cash( (string) $request->get_param( 'from' ), (string) $request->get_param( 'to' ) ) );
}

function dox_pos_rest_history_stock( WP_REST_Request $request ) {
	return rest_ensure_response(
		dox_pos_history_stock(
			array(
				'from'    => (string) $request->get_param( 'from' ),
				'to'      => (string) $request->get_param( 'to' ),
				'q'       => (string) $request->get_param( 'q' ),
				'product' => (int) $request->get_param( 'product' ),
				'kind'    => (string) $request->get_param( 'kind' ),
				'page'    => (int) $request->get_param( 'page' ),
			)
		)
	);
}
