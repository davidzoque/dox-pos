<?php
/**
 * Cuántas prendas hay de cada talla real en un grupo que comparte unidades (0.52.0).
 *
 * Un grupo de tallas que comparte unidades (una bolsa por color, o todas las tallas que heredan el
 * total del producto) lleva un solo número: el 4 de un vestido que vale para 12-18, 18-24 y 2-3 años.
 * Las prendas físicas llevan cada una su etiqueta, y la tienda marca esas tallas como "talla real"
 * (includes/pools.php). Aquí se guarda cuántas prendas hay de cada una (meta _dox_pos_real_qty en la
 * variación de esa talla), de modo que la suma es el total del grupo:
 *
 * - Al vender una talla del grupo se elige qué prenda sale (la talla real más cercana, o la que eligió
 *   la caja) y se le resta. Al cancelar, liberar o devolver, vuelve a la misma.
 * - Una entrada de mercancía suma a la etiqueta con que llegó.
 * - Un grupo con una sola talla real no necesita nada: todas sus unidades son de esa talla.
 * - Si las cantidades no están puestas el grupo está "sin repartir", y si no suman el total (algo cambió
 *   por fuera de la caja) "no cuadra": la caja lo avisa y nada se bloquea.
 * - Con el ajuste "Ofrecer una talla solo si queda una prenda real a N tallas o menos", una talla sin
 *   prenda real cerca sale agotada (en la web y en la caja), aunque el grupo tenga unidades.
 *
 * La web no cambia nada más: el stock del grupo sigue siendo el de siempre.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DOX_POS_REAL_QTY_META  = '_dox_pos_real_qty'; // En la variación de una talla real: cuántas prendas hay con esa etiqueta.
const DOX_POS_REAL_PICK_ITEM = '_dox_pos_real_pick'; // En la línea del pedido: qué prendas van a salir [variación => cuántas].
const DOX_POS_REAL_OUT_ITEM  = '_dox_pos_real_out'; // Y las que ya salieron (se restaron): lo que vuelve al cancelar o devolver.

/**
 * El ajuste: a cuántas tallas de distancia puede estar la prenda real para ofrecer una talla. 0 = apagado.
 *
 * @return int
 */
function dox_pos_real_near() {
	return max( 0, min( 9, (int) ( dox_pos_products_settings()['real_near'] ?? 0 ) ) );
}

/**
 * El nombre de la talla de una variación ("12-18 meses").
 *
 * @param WC_Product $v Variación.
 * @return string
 */
function dox_pos_real_size_label( $v ) {
	$tax  = dox_pos_size_attribute();
	$slug = $v ? (string) ( $v->get_attributes()[ $tax ] ?? '' ) : '';
	return '' === $slug ? '' : dox_pos_attribute_label( $tax, $slug );
}

/**
 * Olvida lo calculado en esta petición (después de vender, devolver o repartir).
 */
function dox_pos_real_forget() {
	dox_pos_real_split( null, true );
}

/**
 * El reparto del grupo de una variación: sus tallas en orden (de la más pequeña a la más grande), sus tallas
 * reales con cuántas prendas tiene cada una, el total del grupo y cómo está. Null si la variación no está en un
 * grupo con dos o más tallas reales (ahí no hay nada que repartir).
 *
 * @param WC_Product|null $v      Variación.
 * @param bool            $forget Solo olvidar lo recordado.
 * @return array|null {group: int[], pos: [id => posición], reals: [{id, label, qty|null}], total: int, sum: int, state: ok|unsplit|mismatch}
 */
function dox_pos_real_split( $v, $forget = false ) {
	static $cache = array();
	if ( $forget ) {
		$cache = array();
		return null;
	}
	if ( ! $v instanceof WC_Product || ! $v->is_type( 'variation' ) ) {
		return null;
	}
	$group = dox_pos_share_group( $v );
	if ( count( $group ) < 2 ) {
		return null;
	}
	$parent_id = (int) $v->get_parent_id();
	$reals     = array_values( array_intersect( dox_pos_real_size_ids( $parent_id ), $group ) );
	if ( count( $reals ) < 2 ) {
		return null;
	}
	$k = $parent_id . ':' . implode( ',', $group );
	if ( isset( $cache[ $k ] ) ) {
		return $cache[ $k ];
	}
	// Las tallas en orden: por lo que dura cada una (meses), como las ordena el formulario.
	$sorted = array();
	foreach ( $group as $gid ) {
		$g              = wc_get_product( $gid );
		$sorted[ $gid ] = $g ? dox_pos_size_sort_key( dox_pos_real_size_label( $g ) ) : PHP_INT_MAX;
	}
	asort( $sorted );
	$pos = array_flip( array_map( 'intval', array_keys( $sorted ) ) );
	// El total: el de la bolsa, o el del producto si las tallas lo heredan.
	$key   = dox_pos_pool_key( $v );
	$total = 0;
	if ( '' !== $key ) {
		$total = dox_pos_pool_stock( $parent_id, $key );
	} else {
		$p     = wc_get_product( $parent_id );
		$total = $p ? (int) $p->get_stock_quantity() : 0;
	}
	$out     = array();
	$sum     = 0;
	$unsplit = false;
	foreach ( $reals as $rid ) {
		$r   = wc_get_product( $rid );
		$raw = $r ? $r->get_meta( DOX_POS_REAL_QTY_META, true, 'edit' ) : '';
		$qty = '' === $raw || null === $raw ? null : max( 0, (int) $raw );
		if ( null === $qty ) {
			$unsplit = true;
		} else {
			$sum += $qty;
		}
		$out[] = array( 'id' => (int) $rid, 'label' => dox_pos_real_size_label( $r ), 'qty' => $qty );
	}
	usort( $out, fn( $a, $b ) => ( $pos[ $a['id'] ] ?? 0 ) <=> ( $pos[ $b['id'] ] ?? 0 ) );
	$cache[ $k ] = array(
		'group' => array_map( 'intval', array_keys( $sorted ) ),
		'pos'   => $pos,
		'reals' => $out,
		'total' => max( 0, (int) $total ),
		'sum'   => $sum,
		'state' => $unsplit ? 'unsplit' : ( $sum === max( 0, (int) $total ) ? 'ok' : 'mismatch' ),
	);
	return $cache[ $k ];
}

/**
 * Las prendas reales que pueden salir al vender esta talla, de la más cercana a la más lejana (a igual
 * distancia, la que tenga más prendas). Con el ajuste de distancia encendido, solo las que están dentro.
 *
 * @param WC_Product $v     Variación vendida.
 * @param array|null $split Su reparto (dox_pos_real_split).
 * @return array [{id, label, qty, dist}]
 */
function dox_pos_real_candidates( $v, $split = null ) {
	$split = $split ? $split : dox_pos_real_split( $v );
	if ( ! $split || 'unsplit' === $split['state'] ) {
		return array();
	}
	$near = dox_pos_real_near();
	$me   = $split['pos'][ (int) $v->get_id() ] ?? 0;
	$out  = array();
	foreach ( $split['reals'] as $r ) {
		if ( (int) $r['qty'] < 1 ) {
			continue;
		}
		$dist = abs( ( $split['pos'][ $r['id'] ] ?? 0 ) - $me );
		if ( $near > 0 && $dist > $near ) {
			continue;
		}
		$out[] = $r + array( 'dist' => $dist );
	}
	usort( $out, fn( $a, $b ) => $a['dist'] <=> $b['dist'] ?: $b['qty'] <=> $a['qty'] );
	return $out;
}

/**
 * ¿Se puede ofrecer esta talla? Null si el ajuste está apagado o el grupo no tiene reparto (manda el stock de
 * siempre); si no, si queda alguna prenda real lo bastante cerca.
 *
 * @param WC_Product $v Variación.
 * @return bool|null
 */
function dox_pos_real_available( $v ) {
	if ( ! dox_pos_real_near() ) {
		return null;
	}
	$split = dox_pos_real_split( $v );
	if ( ! $split || 'unsplit' === $split['state'] ) {
		return null;
	}
	return (bool) dox_pos_real_candidates( $v, $split );
}

/**
 * Qué prendas salen al vender $qty de esta talla: la elegida primero (si puede), y después las más cercanas.
 *
 * @param WC_Product $v         Variación vendida.
 * @param int        $qty       Cuántas.
 * @param int        $preferred La talla real que eligió la caja (0: la más cercana).
 * @return array [variación real => cuántas]; puede quedar corto si no hay reparto que alcance.
 */
function dox_pos_real_plan( $v, $qty, $preferred = 0 ) {
	$cands = dox_pos_real_candidates( $v );
	if ( $preferred ) {
		usort( $cands, fn( $a, $b ) => ( (int) ( $b['id'] === (int) $preferred ) ) <=> ( (int) ( $a['id'] === (int) $preferred ) ) );
	}
	$plan = array();
	$left = max( 0, (int) $qty );
	foreach ( $cands as $c ) {
		if ( $left < 1 ) {
			break;
		}
		$take = min( $left, (int) $c['qty'] );
		if ( $take > 0 ) {
			$plan[ (int) $c['id'] ] = $take;
			$left                  -= $take;
		}
	}
	return $plan;
}

/**
 * El plan en palabras, para la línea del pedido: "12-18 meses", o "12-18 meses ×1, 18-24 meses ×1".
 *
 * @param array $plan [variación real => cuántas].
 * @return string
 */
function dox_pos_real_plan_label( $plan ) {
	$parts = array();
	foreach ( (array) $plan as $rid => $n ) {
		$label = dox_pos_real_size_label( wc_get_product( (int) $rid ) );
		if ( '' !== $label ) {
			$parts[] = 1 === count( $plan ) ? $label : $label . ' ×' . (int) $n;
		}
	}
	return implode( ', ', $parts );
}

/**
 * Suma o resta prendas a una talla real. Solo si el grupo ya tiene cantidades (uno "sin repartir" no se toca:
 * el número saldría inventado).
 *
 * @param int $rid   Variación de la talla real.
 * @param int $delta Cuántas (negativo: salen).
 * @return bool Si se movió.
 */
function dox_pos_real_adjust( $rid, $delta ) {
	$r = wc_get_product( (int) $rid );
	if ( ! $r || 0 === (int) $delta ) {
		return false;
	}
	$raw = $r->get_meta( DOX_POS_REAL_QTY_META, true, 'edit' );
	if ( '' === $raw || null === $raw ) {
		return false;
	}
	$r->update_meta_data( DOX_POS_REAL_QTY_META, max( 0, (int) $raw + (int) $delta ) );
	$r->save_meta_data();
	dox_pos_real_forget();
	return true;
}

/**
 * En la línea del pedido que crea la caja: qué prenda va a salir (la elegida o la más cercana), y la talla real
 * visible para quien empaca. La resta se hace cuando WooCommerce baja el stock (ver abajo).
 *
 * @param WC_Order_Item_Product $item      Línea.
 * @param WC_Product            $product   Lo vendido.
 * @param int                   $preferred La talla real elegida en la caja (0: la más cercana).
 */
function dox_pos_real_tag_line( $item, $product, $preferred = 0 ) {
	if ( ! $item instanceof WC_Order_Item_Product || ! dox_pos_real_split( $product ) ) {
		return;
	}
	$plan = dox_pos_real_plan( $product, (int) $item->get_quantity(), (int) $preferred );
	if ( $plan ) {
		$item->update_meta_data( DOX_POS_REAL_PICK_ITEM, $plan );
		$item->update_meta_data( DOX_POS_REAL_SIZE_ITEM, dox_pos_real_plan_label( $plan ) );
	}
}

add_action( 'woocommerce_reduce_order_item_stock', 'dox_pos_real_on_reduce', 10, 3 );
/**
 * WooCommerce acaba de bajar el stock de una línea (venta, apartado, pedido de la web): salen sus prendas. Las
 * de la web no traen elección: se toma la más cercana en ese momento.
 *
 * @param WC_Order_Item_Product $item  Línea.
 * @param array                 $change {product, from, to}.
 * @param WC_Order              $order Pedido.
 */
function dox_pos_real_on_reduce( $item, $change, $order ) {
	if ( ! $item instanceof WC_Order_Item_Product || $item->get_meta( DOX_POS_REAL_OUT_ITEM ) ) {
		return;
	}
	$product = $item->get_product();
	if ( ! dox_pos_real_split( $product ) ) {
		return;
	}
	$qty  = (int) $item->get_quantity();
	$plan = (array) $item->get_meta( DOX_POS_REAL_PICK_ITEM );
	// Lo planeado al crear la línea pudo quedarse sin prendas (otra venta a la vez): se rehace lo que falte.
	$ok = array();
	foreach ( $plan as $rid => $n ) {
		$s = dox_pos_real_split( wc_get_product( (int) $rid ) );
		foreach ( $s ? $s['reals'] : array() as $r ) {
			if ( (int) $r['id'] === (int) $rid && (int) $r['qty'] >= (int) $n ) {
				$ok[ (int) $rid ] = (int) $n;
			}
		}
	}
	if ( array_sum( $ok ) < $qty ) {
		$ok = dox_pos_real_plan( $product, $qty, $ok ? (int) array_key_first( $ok ) : 0 );
	}
	if ( ! $ok ) {
		return; // Sin reparto que alcance: la línea queda como siempre (el grupo saldrá "no cuadra").
	}
	foreach ( $ok as $rid => $n ) {
		dox_pos_real_adjust( $rid, -$n );
	}
	$label = dox_pos_real_plan_label( $ok );
	$item->update_meta_data( DOX_POS_REAL_OUT_ITEM, $ok );
	$item->delete_meta_data( DOX_POS_REAL_PICK_ITEM );
	$item->update_meta_data( DOX_POS_REAL_SIZE_ITEM, $label );
	$item->save_meta_data();
	/* translators: %s: actual size of the piece that left */
	dox_pos_stock_log_note_last( (int) $product->get_id(), sprintf( __( 'Piece: %s', 'dox-pos' ), $label ) );
}

add_action( 'woocommerce_restore_order_item_stock', 'dox_pos_real_on_restore', 10, 4 );
/**
 * WooCommerce devolvió al stock una línea (cancelar, liberar un apartado, un pedido de la web que falló):
 * vuelven sus prendas a la talla real de donde salieron.
 *
 * @param WC_Order_Item_Product $item      Línea.
 * @param int                   $new_stock Existencias nuevas.
 * @param int                   $old_stock Las de antes.
 * @param WC_Order              $order     Pedido.
 */
function dox_pos_real_on_restore( $item, $new_stock, $old_stock, $order ) {
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return;
	}
	$out = (array) $item->get_meta( DOX_POS_REAL_OUT_ITEM );
	if ( ! $out ) {
		return;
	}
	$back = max( 0, (int) $new_stock - (int) $old_stock );
	$back = $back ? $back : array_sum( $out );
	dox_pos_real_give_back( $item, $back );
	$product = $item->get_product();
	if ( $product ) {
		/* translators: %s: actual size of the piece that came back */
		dox_pos_stock_log_note_last( (int) $product->get_id(), sprintf( __( 'Piece: %s', 'dox-pos' ), dox_pos_real_plan_label( $out ) ) );
	}
}

add_action( 'woocommerce_refund_created', 'dox_pos_real_on_refund', 10, 2 );
/**
 * Una devolución que vuelve al stock (en dinero o al saldo a favor): vuelven las prendas de cada línea devuelta.
 * Se lee de la devolución y no del aviso de reposición de WooCommerce, que con tallas que heredan el total del
 * producto no dice bien cuántas volvieron.
 *
 * @param int   $refund_id La devolución.
 * @param array $args      Lo que se pidió (line_items, restock_items).
 */
function dox_pos_real_on_refund( $refund_id, $args ) {
	if ( empty( $args['restock_items'] ) || empty( $args['line_items'] ) || empty( $args['order_id'] ) ) {
		return;
	}
	$order = wc_get_order( (int) $args['order_id'] );
	if ( ! $order ) {
		return;
	}
	foreach ( (array) $args['line_items'] as $item_id => $li ) {
		$qty  = abs( (int) ( $li['qty'] ?? 0 ) );
		$item = $order->get_item( (int) $item_id );
		if ( $qty && $item instanceof WC_Order_Item_Product ) {
			dox_pos_real_give_back( $item, $qty );
		}
	}
}

/**
 * Devuelve a su talla real hasta $n prendas de las que salieron con esta línea.
 *
 * @param WC_Order_Item_Product $item Línea.
 * @param int                   $n    Cuántas.
 * @return int Las devueltas.
 */
function dox_pos_real_give_back( $item, $n ) {
	$out = (array) $item->get_meta( DOX_POS_REAL_OUT_ITEM );
	if ( ! $out || $n < 1 ) {
		return 0;
	}
	$done = 0;
	foreach ( array_reverse( $out, true ) as $rid => $k ) {
		$take = min( (int) $k, $n - $done );
		if ( $take < 1 ) {
			break;
		}
		dox_pos_real_adjust( (int) $rid, $take );
		$out[ $rid ] = (int) $k - $take;
		if ( $out[ $rid ] < 1 ) {
			unset( $out[ $rid ] );
		}
		$done += $take;
	}
	if ( $out ) {
		$item->update_meta_data( DOX_POS_REAL_OUT_ITEM, $out );
	} else {
		$item->delete_meta_data( DOX_POS_REAL_OUT_ITEM );
	}
	$item->save_meta_data();
	return $done;
}

/**
 * Lo que dice la caja del grupo de una variación (Vender, Inventario, Mostrador y la lista del formulario).
 *
 * @param WC_Product $v Variación.
 * @return array|null {state, total, reals: [{id, label, qty}], pick: id de la propuesta (0 si ninguna), avail: bool|null}
 */
function dox_pos_real_info( $v ) {
	$split = dox_pos_real_split( $v );
	if ( ! $split ) {
		return null;
	}
	$cands = dox_pos_real_candidates( $v, $split );
	return array(
		'state' => $split['state'],
		'total' => $split['total'],
		'reals' => $split['reals'],
		'picks' => array_map( fn( $c ) => (int) $c['id'], $cands ), // Las que pueden salir, la propuesta primero.
		'avail' => dox_pos_real_available( $v ),
		'entry' => dox_pos_real_entry_target( $v ), // A qué talla real suma una entrada de esta talla, de entrada.
	);
}

/*
 * Con el ajuste de distancia encendido, una talla sin prenda real cerca sale agotada: en la web (no se puede
 * elegir ni añadir al carrito) y en la caja. Se calcula al vuelo con las cantidades de cada talla real, así que
 * cambia sola con cada venta, devolución, entrada o reparto.
 */
add_filter( 'woocommerce_product_is_in_stock', 'dox_pos_real_is_in_stock', 20, 2 );
add_filter( 'woocommerce_product_variation_get_stock_status', 'dox_pos_real_stock_status', 20, 2 );

/**
 * @param bool       $in  Lo que dice WooCommerce.
 * @param WC_Product $p   El producto.
 * @return bool
 */
function dox_pos_real_is_in_stock( $in, $p ) {
	if ( ! $in || ! $p instanceof WC_Product || ! $p->is_type( 'variation' ) ) {
		return $in;
	}
	return false === dox_pos_real_available( $p ) ? false : $in;
}

/**
 * @param string     $status El estado de las existencias.
 * @param WC_Product $p      La variación.
 * @return string
 */
function dox_pos_real_stock_status( $status, $p ) {
	if ( 'instock' !== $status || ! $p instanceof WC_Product ) {
		return $status;
	}
	return false === dox_pos_real_available( $p ) ? 'outofstock' : $status;
}

/**
 * La clave de celda del formulario de una variación (color|talla, con los ids de sus términos).
 *
 * @param WC_Product $v Variación.
 * @return string
 */
function dox_pos_variation_cell( $v ) {
	$va    = $v->get_attributes();
	$size  = get_term_by( 'slug', (string) ( $va[ dox_pos_size_attribute() ] ?? '' ), dox_pos_size_attribute() );
	$ctax  = dox_pos_color_attribute();
	$color = $ctax ? get_term_by( 'slug', (string) ( $va[ $ctax ] ?? '' ), $ctax ) : null;
	return ( $color ? (string) $color->term_id : '' ) . '|' . ( $size ? (int) $size->term_id : 0 );
}

/**
 * Las cantidades por talla real de un producto, para el formulario: celda => cuántas (null: sin poner).
 *
 * @param WC_Product $p Producto.
 * @return array
 */
function dox_pos_real_qty_cells( $p ) {
	$out = array();
	foreach ( dox_pos_real_size_ids( $p->get_id() ) as $rid ) {
		$r = wc_get_product( $rid );
		if ( ! $r ) {
			continue;
		}
		$raw                                   = $r->get_meta( DOX_POS_REAL_QTY_META, true, 'edit' );
		$out[ dox_pos_variation_cell( $r ) ] = '' === $raw || null === $raw ? null : (int) $raw;
	}
	return $out;
}

/**
 * Guarda el reparto que mandó el formulario (real_qty: celda => cuántas). Lo que no llega no se toca; una talla
 * que ya no es real pierde su número. No mueve existencias ni el kardex: solo dice qué etiqueta lleva cada prenda.
 *
 * @param int   $product_id Producto.
 * @param array $data       Lo que llegó del formulario.
 */
function dox_pos_real_qty_save( $product_id, $data ) {
	$p = wc_get_product( (int) $product_id );
	if ( ! $p || ! $p->is_type( 'variable' ) ) {
		return;
	}
	$want  = isset( $data['real_qty'] ) && is_array( $data['real_qty'] ) ? $data['real_qty'] : null;
	dox_pos_real_size_ids( 0, true ); // Las marcas de talla real pudieron cambiar en este mismo guardado.
	dox_pos_pool_forget();
	$reals = dox_pos_real_size_ids( $p->get_id() );
	foreach ( $p->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) {
			continue;
		}
		$is_real = in_array( (int) $vid, $reals, true );
		$has     = '' !== (string) $v->get_meta( DOX_POS_REAL_QTY_META, true, 'edit' );
		if ( ! $is_real ) {
			if ( $has ) {
				$v->delete_meta_data( DOX_POS_REAL_QTY_META );
				$v->save_meta_data();
			}
			continue;
		}
		$cell = dox_pos_variation_cell( $v );
		if ( null === $want || ! array_key_exists( $cell, $want ) ) {
			continue;
		}
		$n = $want[ $cell ];
		if ( null === $n || '' === $n ) {
			$v->delete_meta_data( DOX_POS_REAL_QTY_META );
		} else {
			$v->update_meta_data( DOX_POS_REAL_QTY_META, max( 0, (int) $n ) );
		}
		$v->save_meta_data();
	}
	dox_pos_real_forget();
}

/**
 * En una entrada de mercancía: a qué talla real suman las unidades que llegaron de esta talla. La que dijo la caja
 * si es una talla real de su grupo; si no, ella misma si es real, o la talla real más cercana.
 *
 * @param WC_Product $p    La talla que entra.
 * @param int        $want La talla real que eligió la caja (0: decide esto).
 * @return int La variación de la talla real, o 0 si el grupo no lleva prendas por talla real.
 */
function dox_pos_real_entry_target( $p, $want = 0 ) {
	$split = dox_pos_real_split( $p );
	if ( ! $split ) {
		return 0;
	}
	$ids = array_map( fn( $r ) => (int) $r['id'], $split['reals'] );
	if ( $want && in_array( (int) $want, $ids, true ) ) {
		return (int) $want;
	}
	if ( in_array( (int) $p->get_id(), $ids, true ) ) {
		return (int) $p->get_id();
	}
	$me   = $split['pos'][ (int) $p->get_id() ] ?? 0;
	$best = 0;
	$bd   = PHP_INT_MAX;
	foreach ( $ids as $rid ) {
		$d = abs( ( $split['pos'][ $rid ] ?? 0 ) - $me );
		if ( $d < $bd ) {
			$bd   = $d;
			$best = $rid;
		}
	}
	return $best;
}
