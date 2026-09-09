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
 * @param array $data lines [ [id, qty, cost] ], supplier, invoice, date, note, ref, force. El costo
 *                    (por unidad, lo que se pagó) es opcional y solo lo manda quien administra.
 * @return array|WP_Error La entrada, ya formateada.
 */
function dox_pos_create_entry( $data ) {
	global $wpdb;
	// Si la misma entrada ya llegó (la cola sin señal reintenta), se devuelve la que existe.
	$ref = sanitize_text_field( $data['ref'] ?? '' );
	if ( $ref ) {
		$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE ref = %s', dox_pos_entries_table(), $ref ) );
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
					__( 'Invoice %1$s was already recorded on %2$s%3$s%4$s, %5$s.', 'dox-pos' ),
					$prev['invoice'],
					$prev['date'],
					/* translators: %s: proveedor */
					$prev['supplier'] ? sprintf( __( ', from %s', 'dox-pos' ), $prev['supplier'] ) : '',
					/* translators: %s: quién la registró */
					$prev['user'] ? sprintf( __( ', by %s', 'dox-pos' ), $prev['user'] ) : '',
					/* translators: %d: unidades */
					sprintf( _n( 'with %d unit', 'with %d units', $prev['units'], 'dox-pos' ), $prev['units'] )
				),
				array( 'entry' => $prev )
			);
		}
	}
	$see   = dox_pos_can_see_costs(); // El costo de la compra solo lo manda quien administra; el rol Caja registra sin costos.
	$lines = array();
	$costs = array(); // Para el kardex: el costo de compra por unidad de cada producto o talla.
	foreach ( (array) ( $data['lines'] ?? array() ) as $l ) {
		$p   = wc_get_product( (int) ( $l['id'] ?? 0 ) );
		$qty = (int) ( $l['qty'] ?? 0 );
		if ( ! $p || $qty < 1 ) {
			continue;
		}
		$cost = $see && isset( $l['cost'] ) && '' !== $l['cost'] ? dox_pos_parse_money( $l['cost'] ) : null;
		$cost = null === $cost || $cost <= 0 ? null : round( $cost, 2 );
		if ( null !== $cost ) {
			$costs[ $p->get_id() ] = $cost;
		}
		$lines[] = array( 'product' => $p, 'qty' => $qty, 'cost' => $cost );
	}
	if ( ! $lines ) {
		return new WP_Error( 'dox_pos_sin_lineas', __( 'There is nothing to record.', 'dox-pos' ) );
	}
	$saved = array();
	$units = 0;
	$total = 0.0; // Lo que costó la mercancía que trae costo.
	$ctx   = dox_pos_stock_context( 'entry', 0, trim( sanitize_text_field( $data['supplier'] ?? '' ) . ' ' . $invoice ), $costs ); // El kardex: "Entrada #N" (el número se pone al guardarla).
	foreach ( $lines as $l ) {
		$p      = $l['product'];
		$before = $p->managing_stock() ? (int) $p->get_stock_quantity() : null;
		$was    = dox_pos_product_cost( $p ); // El costo que tenía antes de promediar, para el registro.
		$after  = wc_update_product_stock( $p, $l['qty'], 'increase' );
		$saved[] = array(
			'id'          => $p->get_id(),
			'sku'         => $p->get_sku( 'edit' ),
			'name'        => dox_pos_item_name( $p ),
			'qty'         => $l['qty'],
			'before'      => $before,
			'after'       => null === $after ? null : (int) $after,
			'cost'        => $l['cost'],
			'cost_before' => $was,
		);
		$units += $l['qty'];
		if ( null !== $l['cost'] ) {
			$total += $l['qty'] * $l['cost'];
		}
	}
	// Con las unidades ya sumadas, el costo promedio de cada producto que trajo costo.
	$changed = dox_pos_costs_after_entry( $lines );
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
			'cost'       => $total > 0 ? round( $total, 2 ) : null,
			'status'     => 'ok',
			'ref'        => $ref,
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s' )
	);
	$entry_id = (int) $wpdb->insert_id;
	dox_pos_stock_log_set_ref( dox_pos_stock_context_end( $ctx ), $entry_id );
	$entry                 = dox_pos_get_entry( $entry_id );
	$entry['cost_changes'] = $changed; // Los productos cuyo costo promedio cambió con esta compra.
	return $entry;
}

/**
 * Anula una entrada: resta lo que sumó.
 *
 * @param int $id ID de la entrada.
 * @return array|WP_Error
 */
function dox_pos_cancel_entry( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', dox_pos_entries_table(), $id ), ARRAY_A );
	if ( ! $row ) {
		return new WP_Error( 'dox_pos_no_existe', __( 'That stock entry does not exist.', 'dox-pos' ) );
	}
	if ( 'ok' !== $row['status'] ) {
		return new WP_Error( 'dox_pos_ya_anulada', __( 'That stock entry was already voided.', 'dox-pos' ) );
	}
	// Una entrada de demostración (las crea Dox POS Pro) nunca sumó nada, así que tampoco resta.
	if ( ! dox_pos_is_demo_ref( $row['ref'] ?? '' ) ) {
		$ctx = dox_pos_stock_context( 'entry_undo', $id );
		foreach ( (array) json_decode( $row['items'], true ) as $l ) {
			$p = wc_get_product( (int) $l['id'] );
			if ( $p ) {
				wc_update_product_stock( $p, (int) $l['qty'], 'decrease' );
			}
		}
		dox_pos_stock_context_end( $ctx );
	}
	$wpdb->update( dox_pos_entries_table(), array( 'status' => 'anulada' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	return dox_pos_get_entry( $id );
}

function dox_pos_get_entry( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', dox_pos_entries_table(), $id ), ARRAY_A );
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
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE status = 'ok' AND LOWER( invoice ) = LOWER( %s ) ORDER BY id DESC LIMIT 1", dox_pos_entries_table(), trim( (string) $invoice ) ), ARRAY_A );
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
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', dox_pos_entries_table(), $limit ), ARRAY_A );
	return array_map( 'dox_pos_format_entry', $rows ?: array() );
}

/**
 * La foto y el producto padre de una lista de tallas o productos, resuelto de una vez para toda
 * la lista: tres consultas en vez de una por línea, que con cuarenta entradas serían cientos.
 *
 * @param int[] $ids Productos o variaciones.
 * @return array<int,array{pid:int,image:string}>
 */
function dox_pos_items_media( $ids ) {
	global $wpdb;
	$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	$out = array();
	if ( ! $ids ) {
		return $out;
	}
	$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_parent, pm.meta_value AS thumb, pp.meta_value AS parent_thumb FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' LEFT JOIN {$wpdb->postmeta} pp ON pp.post_id = p.post_parent AND pp.meta_key = '_thumbnail_id' WHERE p.ID IN ({$marks})", ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- El sniff no ve los marcadores dentro de la variable ni cuenta los argumentos desempaquetados.
	$thumbs = array();
	foreach ( (array) $rows as $r ) {
		$t = (int) ( $r['thumb'] ? $r['thumb'] : $r['parent_thumb'] );
		$out[ (int) $r['ID'] ] = array( 'pid' => (int) $r['post_parent'] ? (int) $r['post_parent'] : (int) $r['ID'], 'thumb' => $t, 'image' => '' );
		if ( $t ) {
			$thumbs[] = $t;
		}
	}
	if ( $thumbs ) {
		_prime_post_caches( array_values( array_unique( $thumbs ) ), false, true ); // Las fotos y sus tamaños, de una vez.
		foreach ( $out as $id => $d ) {
			if ( $d['thumb'] ) {
				$url = wp_get_attachment_image_url( $d['thumb'], 'woocommerce_thumbnail' );
				$out[ $id ]['image'] = $url ? $url : '';
			}
			unset( $out[ $id ]['thumb'] );
		}
	}
	return $out;
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
		'cost'     => dox_pos_can_see_costs() && isset( $row['cost'] ) && null !== $row['cost'] && '' !== $row['cost'] ? (float) $row['cost'] : null, // Lo que costó la mercancía, si se apuntó.
		'status'   => $row['status'],
		'user'     => $user ? $user->display_name : '',
		'items'    => implode( ' + ', array_map( fn( $l ) => $l['name'] . ' ×' . $l['qty'], $lines ) ),
		// Y las mismas líneas una por una, con su foto y su ficha, para poder tocarlas en la caja.
		'lines'    => dox_pos_entry_lines( $lines ),
	);
}

/**
 * Las líneas de una entrada tal como las pinta la caja.
 *
 * @param array $lines Lo guardado en la entrada.
 * @return array
 */
function dox_pos_entry_lines( $lines ) {
	$media = dox_pos_items_media( wp_list_pluck( (array) $lines, 'id' ) );
	$out   = array();
	foreach ( (array) $lines as $l ) {
		$id    = (int) ( $l['id'] ?? 0 );
		$m     = $media[ $id ] ?? array( 'pid' => $id, 'image' => '' );
		$out[] = array(
			'id'    => $id,
			'pid'   => (int) $m['pid'],
			'name'  => (string) ( $l['name'] ?? '' ),
			'sku'   => (string) ( $l['sku'] ?? '' ),
			'qty'   => (int) ( $l['qty'] ?? 0 ),
			'image' => (string) $m['image'],
		);
	}
	return $out;
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
