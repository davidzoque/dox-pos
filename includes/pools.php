<?php
/**
 * Las unidades compartidas por color ("bolsas").
 *
 * WooCommerce solo sabe de dos formas de llevar las existencias de un producto de tallas: un total
 * del producto que las tallas heredan, o las de cada talla por su cuenta. Cuando un producto tiene
 * varios colores y las tallas de un color comparten sus unidades (cinco rompers Coral que valen para
 * 12-18 y 18-24 meses, tres Rosa para las mismas tallas), hace falta una bolsa por color, y eso lo
 * pone este archivo encima de WooCommerce: cada talla de la bolsa lleva sus propias existencias con
 * el mismo número, marcada con el meta _dox_pos_pool (el color), y aquí se mantienen iguales: se
 * vende una y bajan todas, entra mercancía por una y suben todas.
 *
 * Lo delicado es el checkout de la web, que reserva por talla: con una unidad en la bolsa, dos
 * clientas podrían llevarse dos tallas. WooCommerce deja intervenir en cómo cuenta lo reservado
 * (woocommerce_query_for_reserved_stock) y aquí se le hace sumar la bolsa entera.
 *
 * Un producto de un solo color sigue con el total del producto de siempre, que lo lleva WooCommerce
 * solo (get_manage_stock() 'parent' en las tallas) sin nada de esto.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DOX_POS_POOL_META = '_dox_pos_pool';

/**
 * La bolsa de una variación: el slug de su color, o '' si lleva las suyas o hereda el total del producto.
 *
 * @param WC_Product $v Variación.
 * @return string
 */
function dox_pos_pool_key( $v ) {
	if ( ! $v instanceof WC_Product || ! $v->is_type( 'variation' ) || true !== $v->get_manage_stock() ) {
		return '';
	}
	return (string) $v->get_meta( DOX_POS_POOL_META, true, 'edit' );
}

/**
 * El nombre de la bolsa para los textos: el color de la variación ("Coral").
 *
 * @param WC_Product $v Variación.
 * @return string
 */
function dox_pos_pool_name( $v ) {
	$tax   = dox_pos_color_attribute();
	$slug  = (string) ( $v->get_attributes()[ $tax ] ?? '' );
	$color = '' === $slug ? '' : dox_pos_attribute_label( $tax, $slug );
	$key   = dox_pos_pool_key( $v );
	if ( '' === $key ) {
		return $color;
	}
	// Con más de un grupo en el mismo color, cada uno lleva su número: "Coral (grupo 2)".
	$g    = dox_pos_pool_group( $key );
	$base = preg_replace( '/#\d+$/', '', $key );
	$more = $g > 1;
	foreach ( dox_pos_pool_keys( $v->get_parent_id() ) as $k ) {
		if ( $k !== $key && ( $k === $base || 0 === strpos( $k, $base . '#' ) ) ) {
			$more = true;
		}
	}
	if ( ! $more ) {
		return $color;
	}
	if ( '' === $color ) {
		/* translators: %d: number of the size group */
		return sprintf( __( 'group %d', 'dox-pos' ), $g );
	}
	/* translators: 1: colour, 2: number of the size group */
	return sprintf( __( '%1$s (group %2$d)', 'dox-pos' ), $color, $g );
}

/**
 * La clave de una bolsa: el slug del color (o "g" sin colores) y, del segundo grupo de tallas en adelante,
 * "#2", "#3"... Un color puede tener varios grupos que comparten entre sí (0-6 y 6-12 por un lado, 2-3 y
 * 3-4 años por otro).
 *
 * @param string $slug  Slug del color ('' si el producto no tiene colores).
 * @param int    $group Grupo (1, 2...).
 * @return string
 */
function dox_pos_pool_key_for( $slug, $group ) {
	$group = max( 1, (int) $group );
	return ( '' === (string) $slug ? 'g' : (string) $slug ) . ( $group > 1 ? '#' . $group : '' );
}

/**
 * El número de grupo de una clave de bolsa (1 si no lleva "#N").
 *
 * @param string $key Clave.
 * @return int
 */
function dox_pos_pool_group( $key ) {
	return preg_match( '/#(\d+)$/', (string) $key, $m ) ? (int) $m[1] : 1;
}

/**
 * Todas las claves de bolsa de un producto (las de sus tallas activas). Se recuerdan en la petición.
 *
 * @param int  $parent_id Producto.
 * @param bool $forget    Solo vaciar lo recordado.
 * @return string[]
 */
function dox_pos_pool_keys( $parent_id, $forget = false ) {
	static $cache = array();
	if ( $forget ) {
		$cache = array();
		return array();
	}
	$parent_id = (int) $parent_id;
	if ( ! isset( $cache[ $parent_id ] ) ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cache[ $parent_id ] = array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT b.meta_value FROM {$wpdb->posts} p
					 JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s AND b.meta_value <> ''
					 WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status = 'publish'",
					DOX_POS_POOL_META,
					$parent_id
				)
			)
		);
	}
	return $cache[ $parent_id ];
}

/**
 * El producto y su color, para los avisos: "Romper Marian · Coral".
 *
 * @param WC_Product $v Variación.
 * @return string
 */
function dox_pos_pool_title( $v ) {
	$parent = wc_get_product( $v->get_parent_id() );
	$name   = dox_pos_pool_name( $v );
	return ( $parent ? $parent->get_name() : $v->get_name() ) . ( '' !== $name ? ' · ' . $name : '' );
}

/**
 * Olvida lo recordado en esta petición (quién está en cada bolsa y cuánto queda): después de
 * crear o mover tallas de sitio.
 */
function dox_pos_pool_forget() {
	dox_pos_pool_members( 0, '', true );
	dox_pos_pool_stock( 0, '', true );
	dox_pos_pool_keys( 0, true );
}

/**
 * Las variaciones (ids) de una bolsa: las activas que llevan sus existencias y la marca. Se
 * recuerdan en la petición.
 *
 * @param int    $parent_id Producto.
 * @param string $key       Bolsa.
 * @param bool   $forget    Solo vaciar lo recordado.
 * @return int[]
 */
function dox_pos_pool_members( $parent_id, $key, $forget = false ) {
	static $cache = array();
	if ( $forget ) {
		$cache = array();
		return array();
	}
	$k = (int) $parent_id . ':' . $key;
	if ( ! isset( $cache[ $k ] ) ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids         = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s AND b.meta_value = %s
				 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_manage_stock' AND m.meta_value = 'yes'
				 WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status = 'publish'
				 ORDER BY p.ID",
				DOX_POS_POOL_META,
				$key,
				$parent_id
			)
		);
		$cache[ $k ] = array_map( 'intval', (array) $ids );
	}
	return $cache[ $k ];
}

/**
 * Lo que queda en una bolsa: el menor de sus tallas (si alguna se quedó atrás, manda la más baja:
 * así nunca se vende de más). Se recuerda en la petición hasta que algo cambie.
 *
 * @param int    $parent_id Producto.
 * @param string $key       Bolsa.
 * @param bool   $forget    Solo vaciar lo recordado.
 * @return int
 */
function dox_pos_pool_stock( $parent_id, $key, $forget = false ) {
	static $cache = array();
	if ( $forget ) {
		$cache = array();
		return 0;
	}
	$k = (int) $parent_id . ':' . $key;
	if ( ! isset( $cache[ $k ] ) ) {
		$ids = dox_pos_pool_members( $parent_id, $key );
		if ( ! $ids ) {
			return 0;
		}
		global $wpdb;
		$in          = implode( ',', array_map( 'intval', $ids ) );
		$cache[ $k ] = (int) $wpdb->get_var( "SELECT MIN( CAST( meta_value AS SIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = '_stock' AND post_id IN ( $in )" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	return $cache[ $k ];
}

/**
 * ¿Se están igualando las tallas de una bolsa, o moviendo tallas de sitio? Mientras tanto el kardex
 * no apunta nada: el movimiento es uno solo (ya apuntado en la talla que cambió), o no es un movimiento.
 *
 * @param bool|null $on Entrar (true) o salir (false); null solo pregunta.
 * @return bool
 */
function dox_pos_stock_quiet( $on = null ) {
	static $depth = 0;
	if ( null !== $on ) {
		$depth = max( 0, $depth + ( $on ? 1 : -1 ) );
	}
	return $depth > 0;
}

add_action( 'woocommerce_variation_before_set_stock', 'dox_pos_pool_remember', 5 );
/**
 * Antes de que cambien las existencias de una talla de una bolsa se apunta cuántas tenía en la base de
 * datos, para que la sincronía de abajo mueva a las hermanas la diferencia y no el número entero. Se lee
 * de la base y no del objeto porque, al guardar un producto, el objeto ya trae el valor nuevo.
 *
 * @param WC_Product $v La variación.
 */
function dox_pos_pool_remember( $v ) {
	if ( dox_pos_stock_quiet() || ! $v instanceof WC_Product || '' === dox_pos_pool_key( $v ) ) {
		return;
	}
	global $wpdb;
	$before = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock' LIMIT 1", $v->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$GLOBALS['dox_pos_pool_before'][ $v->get_id() ] = null === $before ? null : (int) $before;
}

add_action( 'woocommerce_variation_set_stock', 'dox_pos_pool_sync' );
/**
 * Cambiaron las existencias de una talla que está en una bolsa (una venta, una entrada, un cambio a
 * mano): sus hermanas se mueven lo mismo. Sin apuntar nada en el kardex y sin volver a entrar aquí.
 *
 * Se mueve la diferencia (bajó una: las hermanas bajan una), no se copia el número: dos ventas de
 * tallas distintas de la misma bolsa en el mismo instante restan las dos; copiando el número, una
 * pisaba a la otra. Cuando no se sabe lo de antes, o mientras se forman o se mueven las bolsas
 * (dox_pos_stock_quiet), se copia el número, que es lo que se quiere ahí.
 *
 * @param WC_Product $v La variación, ya con las existencias nuevas.
 */
function dox_pos_pool_sync( $v ) {
	static $busy = false;
	if ( $busy || ! $v instanceof WC_Product ) {
		return;
	}
	$key = dox_pos_pool_key( $v );
	if ( '' === $key ) {
		return;
	}
	dox_pos_pool_stock( 0, '', true ); // Lo que quedaba ya no vale.
	$n      = (int) $v->get_stock_quantity();
	$before = $GLOBALS['dox_pos_pool_before'][ $v->get_id() ] ?? null;
	unset( $GLOBALS['dox_pos_pool_before'][ $v->get_id() ] );
	$delta = null === $before || dox_pos_stock_quiet() ? null : $n - $before;
	$busy  = true;
	dox_pos_stock_quiet( true );
	try {
		foreach ( dox_pos_pool_members( $v->get_parent_id(), $key ) as $sid ) {
			if ( $sid === (int) $v->get_id() ) {
				continue;
			}
			$s = wc_get_product( $sid );
			if ( ! $s || true !== $s->get_manage_stock() ) {
				continue;
			}
			if ( null !== $delta ) {
				if ( 0 !== $delta ) {
					wc_update_product_stock( $s, abs( $delta ), $delta > 0 ? 'increase' : 'decrease' );
				}
			} elseif ( (int) $s->get_stock_quantity() !== $n ) {
				wc_update_product_stock( $s, $n, 'set' );
			}
		}
	} finally {
		dox_pos_stock_quiet( false );
		$busy = false;
	}
}

add_filter( 'woocommerce_query_for_reserved_stock', 'dox_pos_pool_reserved_query', 10, 2 );
/**
 * Lo que la tienda tiene retenido en pagos en curso, cuando la talla está en una bolsa: lo de toda
 * la bolsa, no solo lo de esa talla. Así el checkout de la web (y la caja, que usa la misma
 * reserva) no deja que dos personas se lleven dos tallas cuando queda una.
 *
 * @param string $query      La consulta de WooCommerce, ya preparada.
 * @param int    $product_id La variación.
 * @return string
 */
function dox_pos_pool_reserved_query( $query, $product_id ) {
	$v   = wc_get_product( (int) $product_id );
	$key = $v ? dox_pos_pool_key( $v ) : '';
	if ( '' === $key ) {
		return $query;
	}
	$ids = dox_pos_pool_members( $v->get_parent_id(), $key );
	if ( count( $ids ) < 2 ) {
		return $query;
	}
	$needle = 'stock_table.`product_id` = ' . (int) $product_id;
	if ( false === strpos( (string) $query, $needle ) ) {
		return $query; // WooCommerce cambió la consulta: mejor no tocarla.
	}
	return str_replace( $needle, 'stock_table.`product_id` IN ( ' . implode( ', ', array_map( 'intval', $ids ) ) . ' )', (string) $query );
}

/**
 * Las unidades de un producto contando cada bolsa (y el total del producto) una sola vez.
 *
 * @param WC_Product $p Producto.
 * @return int|null Null si no lleva existencias.
 */
function dox_pos_product_units( $p ) {
	if ( ! $p->is_type( 'variable' ) ) {
		return $p->managing_stock() ? (int) $p->get_stock_quantity() : null;
	}
	$units = true === $p->get_manage_stock() ? (int) $p->get_stock_quantity() : 0;
	$pools = array();
	foreach ( $p->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || true !== $v->get_manage_stock() ) {
			continue;
		}
		$key = dox_pos_pool_key( $v );
		if ( '' !== $key ) {
			$pools[ $key ] = dox_pos_pool_stock( $p->get_id(), $key );
		} else {
			$units += (int) $v->get_stock_quantity();
		}
	}
	return $units + array_sum( $pools );
}

/*
 * La talla real de las unidades compartidas.
 *
 * Cuando una prenda se ofrece en varias tallas que comparten unidades (un body de 18-24 meses que
 * también sirve como 12-18), la pieza física lleva una sola etiqueta. La tienda marca esa talla con
 * el meta _dox_pos_real_size = 'yes' en la variación, y la caja la resalta y la apunta en cada línea
 * de pedido que venda otra talla del mismo grupo, para que quien empaca sepa qué pieza sacar.
 */

const DOX_POS_REAL_SIZE_META = '_dox_pos_real_size';
const DOX_POS_REAL_SIZE_ITEM = 'dox_pos_real_size'; // Meta de la línea del pedido, visible (sin guion bajo).

/**
 * Las variaciones de un producto marcadas como talla real (ids). Se recuerdan en la petición.
 *
 * @param int  $parent_id Producto.
 * @param bool $forget    Solo vaciar lo recordado.
 * @return int[]
 */
function dox_pos_real_size_ids( $parent_id, $forget = false ) {
	static $cache = array();
	if ( $forget ) {
		$cache = array();
		return array();
	}
	$parent_id = (int) $parent_id;
	if ( ! isset( $cache[ $parent_id ] ) ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids                 = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s AND r.meta_value = 'yes'
				 WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status = 'publish'",
				DOX_POS_REAL_SIZE_META,
				$parent_id
			)
		);
		$cache[ $parent_id ] = array_map( 'intval', (array) $ids );
	}
	return $cache[ $parent_id ];
}

/**
 * Las tallas que comparten unidades con una variación, ella incluida (ids): las de su bolsa, o las que
 * heredan el total del producto. Vacío si lleva las suyas.
 *
 * @param WC_Product $v Variación.
 * @return int[]
 */
function dox_pos_share_group( $v ) {
	if ( ! $v instanceof WC_Product || ! $v->is_type( 'variation' ) ) {
		return array();
	}
	$key = dox_pos_pool_key( $v );
	if ( '' !== $key ) {
		return dox_pos_pool_members( $v->get_parent_id(), $key );
	}
	if ( 'parent' !== $v->get_manage_stock() ) {
		return array();
	}
	static $cache = array();
	$parent_id = (int) $v->get_parent_id();
	if ( ! isset( $cache[ $parent_id ] ) ) {
		global $wpdb;
		// Las que no llevan sus existencias: con el padre gestionándolas, heredan su total.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids                 = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_manage_stock'
				 WHERE p.post_parent = %d AND p.post_type = 'product_variation' AND p.post_status = 'publish'
				   AND ( m.meta_value IS NULL OR m.meta_value <> 'yes' )
				 ORDER BY p.ID",
				$parent_id
			)
		);
		$cache[ $parent_id ] = array_map( 'intval', (array) $ids );
	}
	return $cache[ $parent_id ];
}

/**
 * La talla real del grupo de una variación: si ella es la real, y si no, el nombre de la talla (o
 * tallas) que es de verdad la pieza. Solo cuando comparte unidades con otra talla: una talla que lleva
 * las suyas no necesita decir nada.
 *
 * @param WC_Product $v Variación.
 * @return array{real: bool, of: string}
 */
function dox_pos_real_size( $v ) {
	$none  = array( 'real' => false, 'of' => '' );
	$group = dox_pos_share_group( $v );
	if ( count( $group ) < 2 ) {
		return $none;
	}
	$real = array_values( array_intersect( dox_pos_real_size_ids( $v->get_parent_id() ), $group ) );
	if ( ! $real ) {
		return $none;
	}
	if ( in_array( (int) $v->get_id(), $real, true ) ) {
		return array( 'real' => true, 'of' => '' );
	}
	$tax   = dox_pos_size_attribute();
	$names = array();
	foreach ( $real as $rid ) {
		$r    = wc_get_product( $rid );
		$slug = $r ? (string) ( $r->get_attributes()[ $tax ] ?? '' ) : '';
		if ( '' !== $slug ) {
			$names[] = dox_pos_attribute_label( $tax, $slug );
		}
	}
	return array( 'real' => false, 'of' => implode( ' / ', array_unique( $names ) ) );
}

/**
 * Apunta en la línea del pedido la talla real de la pieza, si se vendió otra talla de su grupo.
 *
 * @param WC_Order_Item_Product $item    Línea.
 * @param WC_Product|null       $product Lo vendido.
 */
function dox_pos_tag_real_size( $item, $product ) {
	if ( ! $item instanceof WC_Order_Item_Product || ! $product instanceof WC_Product ) {
		return;
	}
	$r = dox_pos_real_size( $product );
	if ( '' !== $r['of'] ) {
		$item->update_meta_data( DOX_POS_REAL_SIZE_ITEM, $r['of'] );
	}
}

add_action( 'woocommerce_checkout_create_order_line_item', 'dox_pos_tag_real_size_web', 10, 3 );
/**
 * Lo mismo para los pedidos de la web (el checkout clásico y el de bloques pasan por aquí).
 *
 * @param WC_Order_Item_Product $item   Línea.
 * @param string                $key    Clave del carrito.
 * @param array                 $values La línea del carrito.
 */
function dox_pos_tag_real_size_web( $item, $key, $values ) {
	dox_pos_tag_real_size( $item, $values['data'] ?? null );
}

add_filter( 'woocommerce_order_item_display_meta_key', 'dox_pos_real_size_meta_key', 10, 2 );
/**
 * El nombre del meta en el pedido de WooCommerce y en los correos: "Actual size" ("Talla real").
 *
 * @param string        $display Lo que WooCommerce iba a enseñar.
 * @param WC_Meta_Data  $meta    El meta.
 * @return string
 */
function dox_pos_real_size_meta_key( $display, $meta ) {
	return isset( $meta->key ) && DOX_POS_REAL_SIZE_ITEM === $meta->key ? __( 'Actual size', 'dox-pos' ) : $display;
}

add_action( 'woocommerce_email_before_order_table', 'dox_pos_real_size_email_start', 1, 2 );
add_action( 'woocommerce_email_after_order_table', 'dox_pos_real_size_email_end', 99 );
/**
 * Mientras se arma la tabla de productos de un correo, para quién es: la tienda o la clienta.
 *
 * @param WC_Order $order         Pedido.
 * @param bool     $sent_to_admin Va a la tienda.
 */
function dox_pos_real_size_email_start( $order, $sent_to_admin ) {
	$GLOBALS['dox_pos_email_to'] = $sent_to_admin ? 'admin' : 'customer';
}

/**
 * Terminó la tabla de productos del correo.
 */
function dox_pos_real_size_email_end() {
	unset( $GLOBALS['dox_pos_email_to'] );
}

add_filter( 'woocommerce_order_item_get_formatted_meta_data', 'dox_pos_real_size_staff_only', 10, 2 );
/**
 * La talla real es para quien empaca, no para la clienta: sale en wp-admin y en los correos a la
 * tienda, y no en los correos a la clienta, en la página de gracias ni en Mi cuenta (le compró
 * 12-18 meses: leer otra talla la confundiría).
 *
 * @param array                 $meta Los metas que se van a enseñar.
 * @param WC_Order_Item_Product $item Línea.
 * @return array
 */
function dox_pos_real_size_staff_only( $meta, $item ) {
	$email = $GLOBALS['dox_pos_email_to'] ?? ''; // Dentro de la tabla de un correo.
	$staff = '' !== $email ? 'admin' === $email : is_admin(); // wp-admin, también al recargar las líneas por AJAX.
	if ( $staff ) {
		return $meta;
	}
	foreach ( $meta as $id => $m ) {
		if ( isset( $m->key ) && DOX_POS_REAL_SIZE_ITEM === $m->key ) {
			unset( $meta[ $id ] );
		}
	}
	return $meta;
}
