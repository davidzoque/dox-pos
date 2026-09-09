<?php
/**
 * El Panel: las cifras del día para quien administra, en una pestaña que luce como un cuadro de
 * mando. Vendido hoy con ayer al lado, la semana y el mes contra los anteriores a esta misma
 * altura, los últimos catorce días en barras, lo cobrado hoy por forma de pago, lo que hay por
 * cobrar, los pedidos por atender, lo más vendido y el inventario. Los días anteriores se
 * calculan una vez por hora; lo de hoy, cada vez que se abre.
 *
 * También lo más vendido de los últimos treinta días, que Vender enseña antes de buscar nada.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Todo lo que pinta el Panel. Los añadidos meten sus tarjetas en 'extra' con el filtro
 * dox_pos_dashboard: [{label, money|text, sub, go}] (go: la pestaña que abre, como en el #).
 *
 * @return array
 */
function dox_pos_dashboard() {
	$see    = dox_pos_can_see_costs();
	$losses = dox_pos_can_see_losses();
	$tz     = wp_timezone();
	$today  = wp_date( 'Y-m-d' );
	$t0     = new DateTime( $today . ' 12:00:00', $tz ); // Al mediodía: sumar días no tropieza con cambios de hora.

	// Hoy, en vivo: las ventas y la caja.
	$sales = dox_pos_history_sales( $today, $today, 0, 0 );
	$cash  = dox_pos_history_cash( $today, $today );
	$ts    = $sales['totals'];
	$tc    = $cash['totals'];

	// Los días anteriores, guardados una hora, más hoy.
	$days           = dox_pos_dashboard_past( $today );
	$days[ $today ] = array( 'day' => $today, 'n' => $ts['orders'], 'units' => $ts['units'], 'total' => $ts['sold'], 'profit' => $see ? $ts['profit'] : 0.0 );
	$sum            = function ( $from, $to ) use ( $days, $see ) {
		$t = array( 'sold' => 0.0, 'orders' => 0, 'units' => 0, 'profit' => $see ? 0.0 : null );
		foreach ( $days as $d => $r ) {
			if ( $d < $from || $d > $to ) {
				continue;
			}
			$t['sold']   += (float) $r['total'];
			$t['orders'] += (int) $r['n'];
			$t['units']  += (int) $r['units'];
			if ( $see ) {
				$t['profit'] += (float) $r['profit'];
			}
		}
		$t['sold'] = round( $t['sold'], 2 );
		return $t;
	};

	$yday   = ( clone $t0 )->modify( '-1 day' )->format( 'Y-m-d' );
	$monday = ( clone $t0 )->modify( '-' . ( (int) $t0->format( 'N' ) - 1 ) . ' days' );
	$week   = $sum( $monday->format( 'Y-m-d' ), $today );
	$lweek  = $sum( ( clone $monday )->modify( '-7 days' )->format( 'Y-m-d' ), ( clone $t0 )->modify( '-7 days' )->format( 'Y-m-d' ) );
	$month  = $sum( $t0->format( 'Y-m-01' ), $today );
	$lm1    = ( clone $t0 )->modify( 'first day of last month' );
	$lsame  = $lm1->format( 'Y-m-' ) . str_pad( (string) min( (int) $t0->format( 'j' ), (int) $lm1->format( 't' ) ), 2, '0', STR_PAD_LEFT );
	$lmonth = $sum( $lm1->format( 'Y-m-d' ), $lsame );

	$series = array();
	for ( $i = 13; $i >= 0; $i-- ) {
		$d        = ( clone $t0 )->modify( '-' . $i . ' days' )->format( 'Y-m-d' );
		$r        = $days[ $d ] ?? null;
		$series[] = array( 'day' => $d, 'total' => $r ? round( (float) $r['total'], 2 ) : 0.0, 'n' => $r ? (int) $r['n'] : 0 );
	}

	$top = array();
	foreach ( array_slice( dox_pos_top_products( 30 ), 0, 5 ) as $x ) {
		$p = wc_get_product( $x['id'] );
		if ( ! $p || 'trash' === $p->get_status() ) {
			continue;
		}
		$top[] = array(
			'id'    => (int) $x['id'],
			'name'  => $p->get_name(),
			'image' => $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : '',
			'units' => (int) $x['units'],
			'total' => round( (float) $x['total'], 2 ),
		);
	}

	$data = array(
		'date_label' => ucfirst( wp_date( /* translators: formato de fecha de PHP: https://wordpress.org/documentation/article/customize-date-and-time-format/ */ _x( 'l, F j', 'fecha con día de la semana', 'dox-pos' ) ) ),
		'today'      => array(
			'sold'      => $ts['sold'],
			'orders'    => $ts['orders'],
			'units'     => $ts['units'],
			'avg'       => $ts['avg'],
			'cashed'    => $tc['cashed'],
			'profit'    => $see ? $ts['profit'] : null,
			'margin'    => $see ? $ts['margin'] : null,
			'no_cost_n' => $see ? $ts['no_cost_n'] : 0,
			'loss'      => $losses ? $ts['loss'] : null,
			'loss_n'    => $losses ? $ts['loss_n'] : 0,
		),
		'yesterday'  => $sum( $yday, $yday ),
		'week'       => $week + array( 'prev' => $lweek['sold'] ),
		'month'      => $month + array( 'prev' => $lmonth['sold'] ),
		'series'     => $series,
		'methods'    => $cash['method_totals'],
		'pending'    => dox_pos_dashboard_pending(),
		'top'        => $top,
		'stock'      => dox_pos_dashboard_stock(),
		'costs'      => $see,
		'losses'     => $losses,
		'extra'      => array(),
	);
	return apply_filters( 'dox_pos_dashboard', $data );
}

/**
 * Las ventas por día desde el 1 del mes pasado hasta ayer, guardadas una hora. Se rehacen
 * al cambiar de día, que ayer ya es otro.
 *
 * @param string $today AAAA-MM-DD.
 * @return array día => {day, n, units, total, profit}
 */
function dox_pos_dashboard_past( $today ) {
	$c = get_transient( 'dox_pos_dash_past' );
	if ( is_array( $c ) && ( $c['day'] ?? '' ) === $today && isset( $c['days'] ) ) {
		return $c['days'];
	}
	$t0   = new DateTime( $today . ' 12:00:00', wp_timezone() );
	$from = ( clone $t0 )->modify( 'first day of last month' )->format( 'Y-m-d' );
	$to   = ( clone $t0 )->modify( '-1 day' )->format( 'Y-m-d' );
	$days = array();
	foreach ( dox_pos_history_sales( $from, $to, 0, 0 )['by_day'] as $r ) {
		$days[ $r['name'] ] = array( 'day' => $r['name'], 'n' => (int) $r['n'], 'units' => (int) $r['units'], 'total' => (float) $r['total'], 'profit' => (float) $r['profit'] );
	}
	set_transient( 'dox_pos_dash_past', array( 'day' => $today, 'days' => $days ), HOUR_IN_SECONDS );
	return $days;
}

/**
 * Los pedidos que todavía no terminaron, contados como los entiende la caja: por enviar, en
 * camino, apartados, pagos de la web por confirmar y pedidos de la web sin pagar. Y lo que
 * hay por cobrar: los contraentrega en camino y los apartados.
 *
 * @return array
 */
function dox_pos_dashboard_pending() {
	$args = array(
		'limit'   => 300,
		'type'    => 'shop_order',
		'orderby' => 'date',
		'order'   => 'DESC',
		'status'  => array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-enviado', 'wc-failed' ),
	);
	if ( ! dox_pos_show_web_orders() ) {
		$args['created_via'] = DOX_POS_VIA;
	}
	$out = array( 'por_enviar' => 0, 'enviado' => 0, 'apartado' => 0, 'por_confirmar' => 0, 'sin_pagar' => 0, 'cod' => 0.0, 'cod_n' => 0, 'holds' => 0.0, 'holds_n' => 0 );
	foreach ( wc_get_orders( $args ) as $o ) {
		$status = $o->get_status();
		$total  = (float) $o->get_total();
		if ( in_array( $status, array( 'processing', 'enviado' ), true ) ) {
			$out[ 'processing' === $status ? 'por_enviar' : 'enviado' ]++;
			if ( 'cod' === $o->get_payment_method() ) {
				$out['cod'] += $total;
				$out['cod_n']++;
			}
		} elseif ( 'caja' === dox_pos_order_origin( $o ) ) { // En la caja, sin pagar es un apartado (también si el link de pago falló).
			$out['apartado']++;
			$out['holds'] += $total;
			$out['holds_n']++;
		} elseif ( 'on-hold' === $status ) {
			$out['por_confirmar']++;
		} elseif ( 'pending' === $status ) {
			$out['sin_pagar']++;
		}
	}
	$out['cod']   = round( $out['cod'], 2 );
	$out['holds'] = round( $out['holds'], 2 );
	return $out;
}

/**
 * El inventario en dos cifras: las unidades en existencia (cada total compartido cuenta una
 * vez, que solo lo lleva quien gestiona el stock) y los productos agotados del todo.
 *
 * @return array{units:int,out:int}
 */
function dox_pos_dashboard_stock() {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$units = (int) $wpdb->get_var(
		"SELECT COALESCE( SUM( l.stock_quantity ), 0 ) FROM {$wpdb->wc_product_meta_lookup} l
		 JOIN {$wpdb->posts} p ON p.ID = l.product_id
		 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_manage_stock' AND m.meta_value = 'yes'
		 WHERE p.post_status = 'publish' AND p.post_type IN ( 'product', 'product_variation' ) AND l.stock_quantity > 0"
	);
	$out   = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->wc_product_meta_lookup} l
		 JOIN {$wpdb->posts} p ON p.ID = l.product_id
		 WHERE p.post_status = 'publish' AND p.post_type = 'product' AND l.stock_status = 'outofstock'"
	);
	// phpcs:enable
	return array( 'units' => $units, 'out' => $out );
}

/**
 * Lo más vendido en los últimos días, por producto (las tallas de uno suman juntas): unidades y
 * lo que dejó, de más a menos. Se guarda una hora.
 *
 * @param int  $days  Cuántos días atrás.
 * @param bool $fresh Sin caché.
 * @return array [{id, units, total}]
 */
function dox_pos_top_products( $days = 30, $fresh = false ) {
	$days = max( 1, (int) $days );
	$key  = 'dox_pos_top_' . $days;
	$top  = $fresh ? false : get_transient( $key );
	if ( is_array( $top ) ) {
		return $top;
	}
	$t0    = new DateTime( wp_date( 'Y-m-d' ) . ' 12:00:00', wp_timezone() );
	$range = dox_pos_history_range( ( clone $t0 )->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ), $t0->format( 'Y-m-d' ) );
	$agg   = array();
	foreach ( dox_pos_history_orders( $range ) as $o ) {
		if ( ! in_array( $o->get_status(), DOX_POS_SOLD, true ) ) {
			continue;
		}
		foreach ( $o->get_items() as $it ) {
			$pid = (int) $it->get_product_id();
			if ( ! $pid ) {
				continue;
			}
			if ( ! isset( $agg[ $pid ] ) ) {
				$agg[ $pid ] = array( 'id' => $pid, 'units' => 0, 'total' => 0.0 );
			}
			$agg[ $pid ]['units'] += (int) $it->get_quantity();
			$agg[ $pid ]['total'] += (float) $it->get_total();
		}
	}
	uasort( $agg, fn( $a, $b ) => $b['units'] <=> $a['units'] ?: $b['total'] <=> $a['total'] );
	$top = array_slice( array_values( $agg ), 0, 30 );
	set_transient( $key, $top, HOUR_IN_SECONDS );
	return $top;
}

/**
 * Lo más vendido, listo para la lista de Vender: los mismos datos que una búsqueda, con las
 * existencias de ahora mismo, y sin los que ya no tienen nada que vender.
 *
 * @param int $n Cuántos como máximo.
 * @return array
 */
function dox_pos_top_for_sale( $n = 8 ) {
	$out = array();
	foreach ( dox_pos_top_products( 30 ) as $x ) {
		$item = dox_pos_format_product( $x['id'] );
		if ( ! $item ) {
			continue;
		}
		foreach ( $item['variations'] as $v ) {
			if ( 'outofstock' !== $v['status'] ) {
				$out[] = $item;
				break;
			}
		}
		if ( count( $out ) >= $n ) {
			break;
		}
	}
	return $out;
}
