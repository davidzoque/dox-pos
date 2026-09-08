<?php
/**
 * El buscador: productos y variaciones con foto, precio y existencias.
 *
 * Usa el mismo motor de búsqueda que el administrador de WooCommerce
 * (nombre, descripción y SKU, incluyendo el SKU de cada variación).
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Busca y devuelve productos agrupados, con sus variaciones.
 *
 * @param string $term  Lo que se escribió.
 * @param int    $limit Cuántos productos como máximo.
 * @return array
 */
function dox_pos_search( $term, $limit = 20 ) {
	$term = trim( (string) $term );
	if ( mb_strlen( $term ) < 2 ) {
		return array();
	}
	// Primero los que se llaman así (WooCommerce corta por orden alfabético y "Vestido Ella"
	// se quedaba fuera detrás de cuarenta "Body vestido"), después el resto del buscador.
	$ids   = dox_pos_title_matches( $term, $limit );
	$store = WC_Data_Store::load( 'product' );
	$ids   = array_merge( $ids, $store->search_products( $term, '', true, false, $limit * 4 ) );

	// Una variación encontrada por su SKU representa a su producto padre; se respeta el orden en que salieron.
	$parents = array();
	foreach ( $ids as $id ) {
		$pid = dox_pos_parent_id( $id );
		if ( $pid ) {
			$parents[ $pid ] = true;
		}
	}

	// Los que se llaman como lo que se escribió van primero: WooCommerce devuelve por orden
	// alfabético y "Vestido Ella" saldría detrás de todos los "Body vestido". Se ordena por
	// el título antes de cargar cada producto, que es lo caro.
	$ranked = dox_pos_rank( array_keys( $parents ), $term );

	$out = array();
	foreach ( array_slice( $ranked, 0, $limit ) as $pid ) {
		$item = dox_pos_format_product( $pid );
		if ( $item ) {
			$out[] = $item;
		}
	}
	return $out;
}

/**
 * Productos publicados cuyo título contiene lo escrito, tal cual.
 *
 * @param string $term  Lo que se escribió.
 * @param int    $limit Cuántos como máximo.
 * @return int[]
 */
function dox_pos_title_matches( $term, $limit ) {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( $term ) . '%';
	$ids  = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE %s ORDER BY post_title ASC LIMIT %d",
			$like,
			$limit
		)
	);
	return array_map( 'intval', $ids );
}

/**
 * El producto padre de un ID que puede ser una variación, sin cargar el objeto entero.
 *
 * @param int $id ID de producto o de variación.
 * @return int
 */
function dox_pos_parent_id( $id ) {
	$post = get_post( $id );
	if ( ! $post ) {
		return 0;
	}
	return 'product_variation' === $post->post_type ? (int) $post->post_parent : (int) $post->ID;
}

/**
 * Ordena IDs de producto por lo que se parece su título a lo escrito.
 *
 * @param int[]  $ids  IDs de producto, en el orden en que salieron.
 * @param string $term Lo que se escribió.
 * @return int[]
 */
function dox_pos_rank( $ids, $term ) {
	$q     = dox_pos_fold( $term );
	$words = array_filter( explode( ' ', $q ) );
	$rows  = array();
	foreach ( $ids as $i => $pid ) {
		$name  = dox_pos_fold( get_the_title( $pid ) );
		$score = 0;
		if ( 0 === strpos( $name, $q ) ) {
			$score = 3; // Empieza igual.
		} elseif ( false !== strpos( $name, $q ) ) {
			$score = 2; // Lo contiene entero.
		} else {
			$score = 1;
			foreach ( $words as $w ) {
				if ( false === strpos( $name, $w ) ) {
					$score = 0; // Alguna palabra solo está en la descripción o en el SKU.
					break;
				}
			}
		}
		$rows[] = array( $pid, $score, $i );
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return ( $b[1] <=> $a[1] ) ?: ( $a[2] <=> $b[2] );
		}
	);
	return array_column( $rows, 0 );
}

/**
 * Minúsculas y sin tildes, para comparar.
 *
 * @param string $s Texto.
 * @return string
 */
function dox_pos_fold( $s ) {
	return trim( preg_replace( '/\s+/', ' ', mb_strtolower( remove_accents( (string) $s ) ) ) );
}

/**
 * Un producto tal como lo entiende la caja.
 *
 * @param int $pid ID del producto padre (o del simple).
 * @return array|null
 */
function dox_pos_format_product( $pid ) {
	$p = wc_get_product( $pid );
	if ( ! $p || 'publish' !== $p->get_status() ) {
		return null;
	}
	$image = $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : '';

	$variations = array();
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && 'publish' === $v->get_status() ) {
				$variations[] = dox_pos_format_variation( $v );
			}
		}
	} else {
		$variations[] = dox_pos_format_variation( $p, true );
	}

	return array(
		'id'         => (int) $pid,
		'name'       => $p->get_name(),
		'sku'        => $p->get_sku( 'edit' ),
		'type'       => $p->get_type(),
		'price'      => (float) $p->get_price(),
		'image'      => $image ? $image : '',
		'variations' => $variations,
	);
}

/**
 * Una variación (o un producto simple, como talla única).
 *
 * @param WC_Product $v      La variación o el producto.
 * @param bool       $simple Si es un producto simple.
 * @return array
 */
function dox_pos_format_variation( $v, $simple = false ) {
	$talla  = '';
	$color  = '';
	$others = array();
	if ( ! $simple ) {
		$size_tax  = dox_pos_size_attribute();  // pa_talla, o el que diga el ajuste
		$color_tax = dox_pos_color_attribute(); // pa_color
		foreach ( $v->get_attributes() as $tax => $slug ) {
			$name = dox_pos_attribute_label( $tax, $slug );
			if ( $tax === $size_tax ) {
				$talla = $name;
			} elseif ( $tax === $color_tax ) {
				$color = $name;
			} else {
				$others[] = $name;
			}
		}
	}
	$label = implode( ' · ', array_filter( array_merge( array( $talla, $color ), $others ) ) );

	// get_manage_stock() devuelve 'parent' cuando la variación hereda el stock del producto: también cuenta.
	$manage = $v->get_manage_stock();

	return array(
		'id'     => (int) $v->get_id(),
		'sku'    => $v->get_sku( 'edit' ),
		'label'  => $label ? $label : __( 'One size', 'dox-pos' ),
		'talla'  => $talla,
		'color'  => $color,
		'price'  => (float) $v->get_price(),
		'cost'   => dox_pos_can_see_costs() ? dox_pos_product_cost( $v ) : null, // Solo para quien administra: la entrada lo propone como costo de compra.
		'stock'  => $manage ? (int) $v->get_stock_quantity() : null,
		'status' => $v->get_stock_status(),
	);
}

/**
 * El nombre legible de un valor de atributo ("18-24-meses" -> "18-24 meses").
 *
 * @param string $tax  El atributo (pa_talla, pa_color...).
 * @param string $slug El valor guardado en la variación.
 * @return string
 */
function dox_pos_attribute_label( $tax, $slug ) {
	if ( '' === $slug ) {
		return __( 'Any', 'dox-pos' );
	}
	if ( taxonomy_exists( $tax ) ) {
		$term = get_term_by( 'slug', $slug, $tax );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->name;
		}
	}
	return $slug;
}
