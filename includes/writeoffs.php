<?php
/**
 * Bajas de inventario: lo que se daña, se pierde o se regala sin haberse vendido. Resta las unidades,
 * queda en el kardex con el motivo y con el costo por unidad en ese momento, y así la pérdida sale de
 * esas mismas filas (no hay otra tabla).
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Los motivos de una baja.
 *
 * @return array<string,string> código => texto.
 */
function dox_pos_writeoff_reasons() {
	return array(
		'damaged' => __( 'Damaged or defective', 'dox-pos' ),
		'lost'    => __( 'Lost or stolen', 'dox-pos' ),
		'gift'    => __( 'Gift or sample', 'dox-pos' ),
		'other'   => __( 'Other', 'dox-pos' ),
	);
}

/**
 * Da de baja unidades: las resta del inventario y las apunta en el kardex como pérdida.
 *
 * @param array $data lines [ [id, qty, cost, real] ], reason (damaged|lost|gift|other), note.
 * @return array|WP_Error units, value (lo perdido, al costo), unknown (líneas sin costo) y las líneas.
 */
function dox_pos_write_off( $data ) {
	if ( ! dox_pos_can_see_losses() ) {
		return new WP_Error( 'dox_pos_permiso', __( 'Only administrators and shop managers can write off stock.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	$reasons = dox_pos_writeoff_reasons();
	$reason  = sanitize_key( $data['reason'] ?? '' );
	if ( ! isset( $reasons[ $reason ] ) ) {
		return new WP_Error( 'dox_pos_motivo', __( 'Choose why they are being written off.', 'dox-pos' ) );
	}
	$lines = array();
	$costs = array(); // El costo por unidad que se escribió a mano, para el kardex.
	$seen  = array();
	foreach ( (array) ( $data['lines'] ?? array() ) as $l ) {
		if ( ! is_array( $l ) ) {
			continue;
		}
		$p   = wc_get_product( (int) ( $l['id'] ?? 0 ) );
		$qty = (int) ( $l['qty'] ?? 0 );
		if ( ! $p || $qty < 1 || isset( $seen[ $p->get_id() ] ) ) {
			continue;
		}
		$seen[ $p->get_id() ] = true;
		if ( ! $p->managing_stock() ) {
			return new WP_Error( 'dox_pos_sin_control', sprintf( /* translators: %s: product */ __( '%s does not track stock: turn it on in the product first.', 'dox-pos' ), dox_pos_item_name( $p ) ) );
		}
		$have = (int) $p->get_stock_quantity();
		if ( $qty > $have ) {
			return new WP_Error( 'dox_pos_no_alcanza', sprintf( /* translators: 1: product, 2: units in stock */ __( '%1$s only has %2$d in stock.', 'dox-pos' ), dox_pos_item_name( $p ), $have ) );
		}
		$cost = isset( $l['cost'] ) && '' !== $l['cost'] ? dox_pos_parse_money( $l['cost'] ) : null;
		$cost = null === $cost || $cost <= 0 ? null : round( $cost, 2 );
		if ( null !== $cost ) {
			$costs[ $p->get_id() ] = $cost;
		}
		$lines[] = array( 'product' => $p, 'qty' => $qty, 'cost' => $cost, 'real' => dox_pos_real_entry_target( $p, (int) ( $l['real'] ?? 0 ) ) );
	}
	if ( ! $lines ) {
		return new WP_Error( 'dox_pos_sin_lineas', __( 'There is nothing to record.', 'dox-pos' ) );
	}
	$note  = trim( $reasons[ $reason ] . ' · ' . sanitize_text_field( $data['note'] ?? '' ), ' ·' );
	$ctx   = dox_pos_stock_context( 'writeoff', 0, $note, $costs );
	$out   = array();
	$units = 0;
	$value = 0.0;
	$miss  = 0;
	foreach ( $lines as $l ) {
		$p     = $l['product'];
		$unit  = null !== $l['cost'] ? $l['cost'] : dox_pos_product_cost( $p );
		$after = wc_update_product_stock( $p, $l['qty'], 'decrease' );
		if ( $l['real'] ) {
			dox_pos_real_adjust( $l['real'], -$l['qty'] ); // La prenda de esa etiqueta que salió.
		}
		$out[]  = array(
			'id'    => $p->get_id(),
			'name'  => dox_pos_item_name( $p ),
			'qty'   => $l['qty'],
			'after' => null === $after ? null : (int) $after,
			'cost'  => null === $unit ? null : round( (float) $unit, 2 ),
		);
		$units += $l['qty'];
		if ( null === $unit ) {
			$miss++;
		} else {
			$value += $l['qty'] * (float) $unit;
		}
	}
	dox_pos_stock_context_end( $ctx );
	return array( 'units' => $units, 'value' => round( $value, 2 ), 'unknown' => $miss, 'lines' => $out );
}

/**
 * Lo dado de baja en un periodo, por día: unidades y valor al costo de cada unidad en ese momento.
 *
 * @param string $from AAAA-MM-DD.
 * @param string $to   AAAA-MM-DD.
 * @return array<string,array{units:int,value:float,unknown:int}> Por día.
 */
function dox_pos_writeoffs_by_day( $from, $to ) {
	global $wpdb;
	$table = dox_pos_stock_log_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(created_at) AS day, SUM(-delta) AS units, SUM(-delta * COALESCE(unit_cost, 0)) AS value, SUM(unit_cost IS NULL) AS unknown FROM %i WHERE reason = %s AND created_at BETWEEN %s AND %s GROUP BY DATE(created_at)', $table, 'writeoff', $from . ' 00:00:00', $to . ' 23:59:59' ) );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$out[ $r->day ] = array( 'units' => (int) $r->units, 'value' => round( (float) $r->value, 2 ), 'unknown' => (int) $r->unknown );
	}
	return $out;
}

function dox_pos_rest_write_off( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_write_off( (array) $request->get_json_params() ) );
}
