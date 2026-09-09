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
		/* translators: %d: número del grupo de tallas */
		return sprintf( __( 'group %d', 'dox-pos' ), $g );
	}
	/* translators: 1: color, 2: número del grupo de tallas */
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
