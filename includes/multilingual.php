<?php
/**
 * Tiendas en varios idiomas (Polylang o WPML): cada producto existe una vez por idioma, enlazado con
 * los otros como traducción. La caja enseña solo los del idioma principal de la tienda (y los que no
 * tienen idioma), así cada producto sale una vez en Inventario, el buscador, las cuentas y el Excel.
 * Una variación sigue a su producto.
 *
 * Las existencias se siguen cambiando solo con las funciones de WooCommerce, para que lo que la tienda
 * use para igualarlas entre traducciones (Polylang for WooCommerce, WPML o un código propio) se entere.
 * Lo que vende la web no lo iguala Dox POS: eso es de la tienda.
 *
 * Aquí queda solo lo que evita errores: filtrar las listas por idioma, llevar un ID a su ficha principal y
 * que lo creado en la caja nazca en el idioma principal. Crear y editar las traducciones desde la caja
 * (pestañas por idioma, "crear también en los otros idiomas", la reparación) es de Dox POS Pro
 * (includes/multilingual-pro.php del Pro). Sin el Pro, editar un producto toca solo la ficha principal.
 *
 * Sin Polylang ni WPML nada de esto consulta la base: las funciones devuelven '' o lo mismo que reciben.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Qué plugin de idiomas traduce los productos de la tienda: 'polylang', 'wpml' o '' (ninguno).
 *
 * @return string
 */
function dox_pos_lang_plugin() {
	static $plugin = null;
	if ( null !== $plugin ) {
		return $plugin;
	}
	$found = '';
	if ( function_exists( 'pll_default_language' ) && function_exists( 'pll_get_post' ) && function_exists( 'pll_is_translated_post_type' ) ) {
		// Polylang solo traduce los productos si se marcaron en Idiomas > Ajustes > Tipos de contenido.
		if ( pll_default_language() && pll_is_translated_post_type( 'product' ) ) {
			$found = 'polylang';
		}
	} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) && apply_filters( 'wpml_default_language', null ) ) {
		$found = 'wpml';
	}
	// Antes de init los plugins de idiomas todavía no saben nada: no se guarda la respuesta.
	if ( did_action( 'init' ) ) {
		$plugin = $found;
	}
	return $found;
}

/**
 * El idioma principal de la tienda ('es', 'en'...), o '' sin plugin de idiomas.
 *
 * @return string
 */
function dox_pos_lang_default() {
	switch ( dox_pos_lang_plugin() ) {
		case 'polylang':
			return (string) pll_default_language();
		case 'wpml':
			return (string) apply_filters( 'wpml_default_language', null );
	}
	return '';
}

/**
 * El trozo de WHERE que deja fuera los productos de otro idioma, para añadir a una consulta sobre
 * wp_posts: " AND NOT EXISTS (...)". Sirve para productos y variaciones (la variación mira a su padre).
 * Sin plugin de idiomas devuelve '' y la consulta queda como estaba.
 *
 * @param string $alias Alias de wp_posts en la consulta ('p', 'v'...).
 * @return string
 */
function dox_pos_lang_where( $alias = 'p' ) {
	static $cache = array();
	$plugin = dox_pos_lang_plugin();
	if ( '' === $plugin ) {
		return '';
	}
	$alias = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
	if ( isset( $cache[ $plugin . $alias ] ) ) {
		return $cache[ $plugin . $alias ];
	}
	global $wpdb;
	$owner = "IF( {$alias}.post_type = 'product_variation', {$alias}.post_parent, {$alias}.ID )";
	$sql   = '';
	if ( 'polylang' === $plugin ) {
		// Cada idioma de Polylang es un término de la taxonomía language: fuera lo que lleve uno que no sea el principal.
		$others = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Una vez por petición.
				$wpdb->prepare(
					"SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'language' AND t.slug <> %s",
					dox_pos_lang_default()
				)
			)
		);
		if ( $others ) {
			$sql = " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} dox_lr WHERE dox_lr.object_id = {$owner} AND dox_lr.term_taxonomy_id IN ( " . implode( ',', $others ) . ' ) )';
		}
	} else {
		$sql = $wpdb->prepare(
			" AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}icl_translations dox_lt WHERE dox_lt.element_id = {$owner} AND dox_lt.element_type = 'post_product' AND dox_lt.language_code <> %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $owner sale del alias ya limpio.
			dox_pos_lang_default()
		);
	}
	$cache[ $plugin . $alias ] = $sql;
	return $sql;
}

/**
 * El mismo producto (o variación) en el idioma principal: un pedido de la web en inglés o un código
 * que dio con la ficha en inglés se cuentan en la ficha principal. Si no tiene traducción, el mismo ID.
 *
 * @param int $id ID de producto o de variación.
 * @return int
 */
function dox_pos_lang_main_id( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return 0;
	}
	switch ( dox_pos_lang_plugin() ) {
		case 'polylang':
			$t = pll_get_post( $id, dox_pos_lang_default() );
			if ( $t ) {
				return (int) $t;
			}
			// Polylang gratis no traduce variaciones: la de la ficha principal es la que lleva los mismos atributos.
			$post = get_post( $id );
			if ( $post && 'product_variation' === $post->post_type && $post->post_parent ) {
				$main = (int) pll_get_post( (int) $post->post_parent, dox_pos_lang_default() );
				if ( $main && $main !== (int) $post->post_parent ) {
					$twin = dox_pos_lang_twin_variation( $id, $main );
					return $twin ? $twin : $id;
				}
			}
			return $id;
		case 'wpml':
			$type = get_post_type( $id );
			if ( ! $type ) {
				return $id;
			}
			return (int) apply_filters( 'wpml_object_id', $id, $type, true, dox_pos_lang_default() );
	}
	return $id;
}

/**
 * La variación de otro producto con los mismos valores en cada atributo (attribute_pa_talla = m...).
 *
 * @param int $id     ID de la variación.
 * @param int $main   ID del producto donde buscarla.
 * @return int 0 si no hay una igual.
 */
function dox_pos_lang_twin_variation( $id, $main ) {
	global $wpdb;
	// La que la caja creó o copió de esta lleva su marca: vale aunque los atributos cambien después.
	$of = (int) get_post_meta( $id, '_dox_pos_lang_of', true );
	if ( $of && (int) get_post_field( 'post_parent', $of ) === (int) $main ) {
		return $of;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$marked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_dox_pos_lang_of' AND m.meta_value = %s WHERE p.post_parent = %d AND p.post_type = 'product_variation' LIMIT 1", (string) $id, $main ) );
	if ( $marked ) {
		return $marked;
	}
	$attrs = array();
	foreach ( get_post_meta( $id ) as $k => $v ) {
		if ( 0 === strpos( $k, 'attribute_' ) ) {
			$attrs[ $k ] = (string) $v[0];
		}
	}
	if ( ! $attrs ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'", $main ) );
	foreach ( array_map( 'intval', $ids ) as $vid ) {
		$same = true;
		foreach ( $attrs as $k => $v ) {
			if ( (string) get_post_meta( $vid, $k, true ) !== $v ) {
				$same = false;
				break;
			}
		}
		if ( $same ) {
			return $vid;
		}
	}
	return 0;
}

/**
 * Los argumentos de get_terms() para las categorías de producto, con Polylang solo las del idioma principal
 * (si las categorías se traducen). Sin plugin de idiomas, los mismos.
 *
 * @param array $args Argumentos de get_terms().
 * @return array
 */
function dox_pos_lang_term_args( $args ) {
	if ( 'polylang' === dox_pos_lang_plugin() && function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( 'product_cat' ) ) {
		$args['lang'] = dox_pos_lang_default();
	}
	return $args;
}

/**
 * Una categoría creada desde la caja queda en el idioma principal.
 *
 * @param int $term_id Categoría.
 */
function dox_pos_lang_assign_term( $term_id ) {
	if ( 'polylang' === dox_pos_lang_plugin() && function_exists( 'pll_set_term_language' ) && function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( 'product_cat' ) ) {
		pll_set_term_language( (int) $term_id, dox_pos_lang_default() );
	}
}

/**
 * Un producto recién creado desde la caja queda en el idioma principal (Polylang le pondría el del
 * filtro de idioma del escritorio, que puede ser otro).
 *
 * @param int $id ID del producto.
 */
function dox_pos_lang_assign( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return;
	}
	switch ( dox_pos_lang_plugin() ) {
		case 'polylang':
			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $id, dox_pos_lang_default() );
			}
			break;
		case 'wpml':
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'    => $id,
					'element_type'  => 'post_product',
					'trid'          => false,
					'language_code' => dox_pos_lang_default(),
				)
			);
			break;
	}
}
