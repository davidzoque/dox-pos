<?php
/**
 * Entradas de mercancía: suman al inventario y quedan en su propia tabla.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dox_pos_entries_table() {
	global $wpdb;
	return $wpdb->prefix . 'dox_pos_entries';
}

/**
 * Registra una entrada y suma las unidades.
 *
 * @param array $data lines [ [id, qty] ], supplier, invoice, date, note, ref, force.
 * @return array|WP_Error La entrada, ya formateada.
 */
function dox_pos_create_entry( $data ) {
	global $wpdb;
	// Si la misma entrada ya llegó (la cola sin señal reintenta), se devuelve la que existe.
	$ref = sanitize_text_field( $data['ref'] ?? '' );
	if ( $ref ) {
		$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . dox_pos_entries_table() . ' WHERE ref = %s', $ref ) );
		if ( $dup ) {
			return dox_pos_get_entry( (int) $dup );
		}
	}
	// La misma factura desde dos teléfonos entraría doble. Si ya hay una entrada vigente con
	// ese número se avisa (cuándo, de quién, por quién, cuántas unidades) y solo entra si la
	// caja insiste con force, después de preguntar.
	$invoice = sanitize_text_field( $data['invoice'] ?? '' );
	if ( '' !== $invoice && empty( $data['force'] ) ) {
		$prev = dox_pos_entry_by_invoice( $invoice );
		if ( $prev ) {
			return new WP_Error(
				'dox_pos_factura_repetida',
				sprintf(
					/* translators: 1: factura, 2: fecha y hora, 3: ", de Proveedor" o nada, 4: ", por Usuario" o nada, 5: "con N unidades" */
					__( 'La factura %1$s ya se registró el %2$s%3$s%4$s, %5$s.', 'dox-pos' ),
					$prev['invoice'],
					$prev['date'],
					$prev['supplier'] ? sprintf( __( ', de %s', 'dox-pos' ), $prev['supplier'] ) : '',
					$prev['user'] ? sprintf( __( ', por %s', 'dox-pos' ), $prev['user'] ) : '',
					/* translators: %d: unidades */
					sprintf( _n( 'con %d unidad', 'con %d unidades', $prev['units'], 'dox-pos' ), $prev['units'] )
				),
				array( 'entry' => $prev )
			);
		}
	}
	$lines = array();
	foreach ( (array) ( $data['lines'] ?? array() ) as $l ) {
		$p   = wc_get_product( (int) ( $l['id'] ?? 0 ) );
		$qty = (int) ( $l['qty'] ?? 0 );
		if ( ! $p || $qty < 1 ) {
			continue;
		}
		$lines[] = array( 'product' => $p, 'qty' => $qty );
	}
	if ( ! $lines ) {
		return new WP_Error( 'dox_pos_sin_lineas', __( 'No hay nada que registrar.', 'dox-pos' ) );
	}
	$saved = array();
	$units = 0;
	$ctx   = dox_pos_stock_context( 'entry', 0, trim( sanitize_text_field( $data['supplier'] ?? '' ) . ' ' . $invoice ) ); // El kardex: "Entrada #N" (el número se pone al guardarla).
	foreach ( $lines as $l ) {
		$p      = $l['product'];
		$before = $p->managing_stock() ? (int) $p->get_stock_quantity() : null;
		$after  = wc_update_product_stock( $p, $l['qty'], 'increase' );
		$saved[] = array(
			'id'     => $p->get_id(),
			'sku'    => $p->get_sku( 'edit' ),
			'name'   => dox_pos_item_name( $p ),
			'qty'    => $l['qty'],
			'before' => $before,
			'after'  => null === $after ? null : (int) $after,
		);
		$units += $l['qty'];
	}
	$wpdb->insert(
		dox_pos_entries_table(),
		array(
			'created_at' => current_time( 'mysql' ),
			'user_id'    => get_current_user_id(),
			'supplier'   => sanitize_text_field( $data['supplier'] ?? '' ),
			'invoice'    => $invoice,
			'entry_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $data['date'] ?? '' ) ) ? $data['date'] : null,
			'note'       => sanitize_textarea_field( $data['note'] ?? '' ),
			'items'      => wp_json_encode( $saved ),
			'units'      => $units,
			'status'     => 'ok',
			'ref'        => $ref,
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);
	$entry_id = (int) $wpdb->insert_id;
	dox_pos_stock_log_set_ref( dox_pos_stock_context_end( $ctx ), $entry_id );
	return dox_pos_get_entry( $entry_id );
}

/**
 * Anula una entrada: resta lo que sumó.
 *
 * @param int $id ID de la entrada.
 * @return array|WP_Error
 */
function dox_pos_cancel_entry( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dox_pos_entries_table() . ' WHERE id = %d', $id ), ARRAY_A );
	if ( ! $row ) {
		return new WP_Error( 'dox_pos_no_existe', __( 'Esa entrada no existe.', 'dox-pos' ) );
	}
	if ( 'ok' !== $row['status'] ) {
		return new WP_Error( 'dox_pos_ya_anulada', __( 'Esa entrada ya estaba anulada.', 'dox-pos' ) );
	}
	$ctx = dox_pos_stock_context( 'entry_undo', $id );
	foreach ( (array) json_decode( $row['items'], true ) as $l ) {
		$p = wc_get_product( (int) $l['id'] );
		if ( $p ) {
			wc_update_product_stock( $p, (int) $l['qty'], 'decrease' );
		}
	}
	dox_pos_stock_context_end( $ctx );
	$wpdb->update( dox_pos_entries_table(), array( 'status' => 'anulada' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	return dox_pos_get_entry( $id );
}

function dox_pos_get_entry( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dox_pos_entries_table() . ' WHERE id = %d', $id ), ARRAY_A );
	return $row ? dox_pos_format_entry( $row ) : null;
}

/**
 * La última entrada vigente (no anulada) con esa factura o remisión, si la hay.
 * Sin distinguir mayúsculas: "f-1023" y "F-1023" son la misma.
 *
 * @param string $invoice Número tal como se escribió.
 * @return array|null Formateada.
 */
function dox_pos_entry_by_invoice( $invoice ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dox_pos_entries_table() . " WHERE status = 'ok' AND LOWER( invoice ) = LOWER( %s ) ORDER BY id DESC LIMIT 1", trim( (string) $invoice ) ), ARRAY_A );
	return $row ? dox_pos_format_entry( $row ) : null;
}

/**
 * Las últimas entradas.
 *
 * @param int $limit Cuántas.
 * @return array
 */
function dox_pos_list_entries( $limit = 40 ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . dox_pos_entries_table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	return array_map( 'dox_pos_format_entry', $rows ?: array() );
}

function dox_pos_format_entry( $row ) {
	$lines = (array) json_decode( $row['items'], true );
	$user  = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : null;
	return array(
		'id'       => (int) $row['id'],
		'date'     => mysql2date( 'd/m H:i', $row['created_at'] ),
		'day'      => $row['entry_date'] ? mysql2date( 'd/m/Y', $row['entry_date'] ) : '',
		'supplier' => $row['supplier'],
		'invoice'  => $row['invoice'],
		'note'     => $row['note'],
		'units'    => (int) $row['units'],
		'status'   => $row['status'],
		'user'     => $user ? $user->display_name : '',
		'items'    => implode( ' + ', array_map( fn( $l ) => $l['name'] . ' ×' . $l['qty'], $lines ) ),
	);
}

/**
 * El nombre completo de una variación o producto, para las listas.
 *
 * @param WC_Product $p Producto.
 * @return string
 */
function dox_pos_item_name( $p ) {
	if ( ! $p->is_type( 'variation' ) ) {
		return $p->get_name();
	}
	$parent = wc_get_product( $p->get_parent_id() );
	$v      = dox_pos_format_variation( $p ); // talla primero, después color, como en el buscador
	return ( $parent ? $parent->get_name() : $p->get_name() ) . ' · ' . $v['label'];
}
