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
 * Con Polylang, un producto creado o editado desde la caja se escribe también en sus traducciones
 * (dox_pos_lang_sync()): cada idioma con su nombre y sus descripciones, y lo demás (precio, costo, fotos,
 * categorías, unidades, tallas y colores) copiado de la ficha principal. Con WPML todavía no (TODO: sus
 * traducciones se crean desde su propio editor).
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

/* =====================================================================
 * Las traducciones de un producto creado o editado desde la caja (Polylang)
 * ===================================================================== */

/**
 * Los idiomas de la tienda para el formulario de producto, el principal primero:
 * [ [ 'slug' => 'es', 'name' => 'Español' ], ... ]. Solo con Polylang y más de un idioma.
 *
 * @return array
 */
function dox_pos_lang_languages() {
	static $out = null;
	if ( null !== $out ) {
		return $out;
	}
	$out = array();
	if ( 'polylang' !== dox_pos_lang_plugin() || ! function_exists( 'pll_languages_list' ) ) {
		return $out;
	}
	$slugs = array_values( (array) pll_languages_list( array( 'fields' => 'slug' ) ) );
	$names = array_values( (array) pll_languages_list( array( 'fields' => 'name' ) ) );
	if ( count( $slugs ) < 2 ) {
		return $out;
	}
	$def = dox_pos_lang_default();
	foreach ( $slugs as $i => $slug ) {
		$row = array( 'slug' => (string) $slug, 'name' => (string) ( $names[ $i ] ?? $slug ) );
		if ( $slug === $def ) {
			array_unshift( $out, $row );
		} else {
			$out[] = $row;
		}
	}
	return $out;
}

/**
 * Las pestañas de idioma del formulario de producto (caja.js las enseña y esconde).
 *
 * @param array $langs dox_pos_lang_languages().
 */
function dox_pos_lang_tabs_html( $langs ) {
	if ( ! $langs ) {
		return;
	}
	echo '<div class="langtabs" role="tablist" aria-label="' . esc_attr__( 'Language of the text', 'dox-pos' ) . '" hidden>';
	foreach ( $langs as $i => $l ) {
		echo '<button type="button" class="langtab" role="tab" data-lang="' . esc_attr( $l['slug'] ) . '" aria-selected="' . ( $i ? 'false' : 'true' ) . '" title="' . esc_attr( $l['name'] ) . '">' . esc_html( strtoupper( $l['slug'] ) ) . ( $i ? '<i class="ldot" title="' . esc_attr__( 'Not translated', 'dox-pos' ) . '"></i>' : '' ) . '</button>';
	}
	echo '<span class="langtools"></span></div>';
}

/**
 * Los campos de un texto en cada otro idioma, escondidos hasta tocar su pestaña.
 *
 * @param array  $langs dox_pos_lang_languages().
 * @param string $field name, short o description.
 * @param string $tag   input o textarea.
 * @param string $label Nombre del campo, para los lectores de pantalla.
 */
function dox_pos_lang_fields_html( $langs, $field, $tag, $label ) {
	foreach ( array_slice( (array) $langs, 1 ) as $l ) {
		/* translators: 1: field, like "Name"; 2: language, like "English" */
		$aria = sprintf( __( '%1$s (%2$s)', 'dox-pos' ), $label, $l['name'] );
		$attr = 'class="p-ltx" data-lang="' . esc_attr( $l['slug'] ) . '" data-f="' . esc_attr( $field ) . '" aria-label="' . esc_attr( $aria ) . '" hidden';
		echo 'input' === $tag ? '<input ' . $attr . ' autocomplete="off">' : '<textarea ' . $attr . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cada pieza ya va escapada arriba.
	}
}

/**
 * ¿Un producto nuevo de la caja se crea también en los otros idiomas? (Ajustes > Productos.)
 *
 * @return bool
 */
function dox_pos_lang_copies_on() {
	$s = dox_pos_products_settings();
	return ! empty( $s['translations'] ) && count( dox_pos_lang_languages() ) > 1 && function_exists( 'pll_save_post_translations' );
}

/**
 * Los textos de cada idioma que manda el formulario (i18n: { en: { name, short, description } }).
 * Solo los idiomas que mandó; los que no, no se tocan.
 *
 * @param array $data Lo que manda la caja.
 * @return array<string,array{name:string,short:string,description:string}>
 */
function dox_pos_lang_texts_from( $data ) {
	$out = array();
	$in  = is_array( $data['i18n'] ?? null ) ? $data['i18n'] : array();
	foreach ( array_slice( dox_pos_lang_languages(), 1 ) as $l ) {
		if ( ! is_array( $in[ $l['slug'] ] ?? null ) ) {
			continue;
		}
		$t                 = $in[ $l['slug'] ];
		$out[ $l['slug'] ] = array(
			'name'        => sanitize_text_field( (string) ( $t['name'] ?? '' ) ),
			'short'       => trim( (string) ( $t['short'] ?? '' ) ), // Crudo: dox_pos_lang_set_text() lo compara así y lo limpia al ponerlo.
			'description' => trim( (string) ( $t['description'] ?? '' ) ),
		);
	}
	return $out;
}

/**
 * Los textos de las traducciones de un producto, para editarlos en la caja. null en un idioma sin traducción.
 *
 * @param int $pid Producto (la ficha principal).
 * @return array<string,array|null>
 */
function dox_pos_lang_edit_texts( $pid ) {
	$out = array();
	foreach ( array_slice( dox_pos_lang_languages(), 1 ) as $l ) {
		$tid               = (int) pll_get_post( (int) $pid, $l['slug'] );
		$t                 = $tid && $tid !== (int) $pid ? wc_get_product( $tid ) : null;
		$out[ $l['slug'] ] = $t ? array(
			'id'          => $tid,
			'name'        => $t->get_name( 'edit' ),
			'short'       => dox_pos_plain_text( $t->get_short_description( 'edit' ) ),
			'description' => dox_pos_plain_text( $t->get_description( 'edit' ) ),
			'url'         => get_permalink( $tid ),
		) : null;
	}
	return $out;
}

/**
 * El código de la traducción: el de la ficha principal con el idioma detrás (YUBA-BLKGLD-EN), porque
 * WooCommerce no deja repetir un código. Una tienda que lo hace de otra forma lo cambia con el filtro
 * dox_pos_translation_sku. Si el código ya lo lleva otro producto, la traducción se queda sin código.
 *
 * @param string $sku  Código de la ficha principal.
 * @param string $lang Idioma de la traducción.
 * @param int    $id   ID de la traducción (o de su variación).
 * @return string
 */
function dox_pos_lang_sku( $sku, $lang, $id ) {
	$sku = (string) $sku;
	if ( '' === $sku ) {
		return '';
	}
	$s = (string) apply_filters( 'dox_pos_translation_sku', $sku . '-' . strtoupper( $lang ), $sku, $lang, (int) $id );
	if ( '' === $s ) {
		return '';
	}
	return wc_product_has_unique_sku( (int) $id, $s ) ? $s : '';
}

/**
 * Una copia de un producto (con sus variaciones), con la herramienta de duplicar de WooCommerce. La copia
 * nace marcada (de qué ficha y de qué idioma es, y a medias): si la petición se corta antes de terminar, la
 * siguiente vez que se guarda el producto la encuentra y la termina en vez de hacer otra.
 *
 * @param WC_Product $main Ficha principal.
 * @param string     $lang Idioma de la copia.
 * @return int ID de la copia, 0 si no se pudo.
 */
function dox_pos_lang_duplicate( $main, $lang ) {
	if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
		$f = WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
		if ( ! file_exists( $f ) ) {
			return 0;
		}
		include_once $f;
	}
	$mid = (int) $main->get_id();
	// La referencia de la caja es de la ficha principal: con ella en la copia, un reintento devolvería la copia.
	$skip = fn( $keys ) => array_merge( (array) $keys, array( '_dox_pos_ref', '_dox_pos_lang_of', '_dox_pos_lang', '_dox_pos_lang_pending', '_dox_pos_lang_imgs' ) );
	// Las marcas van en el mismo guardado que crea cada ficha: no queda ninguna copia sin ellas.
	$mark = function ( $dup, $orig ) use ( $mid, $lang ) {
		$dup->update_meta_data( '_dox_pos_lang_of', (int) $orig->get_id() );
		if ( (int) $orig->get_id() === $mid ) {
			$dup->update_meta_data( '_dox_pos_lang', (string) $lang );
			$dup->update_meta_data( '_dox_pos_lang_pending', 1 );
		}
	};
	add_filter( 'woocommerce_duplicate_product_exclude_meta', $skip );
	add_action( 'woocommerce_product_duplicate_before_save', $mark, 1, 2 );
	try {
		$d = ( new WC_Admin_Duplicate_Product() )->product_duplicate( wc_get_product( $mid ) );
	} catch ( Exception $e ) {
		$d = null;
	} finally {
		remove_filter( 'woocommerce_duplicate_product_exclude_meta', $skip );
		remove_action( 'woocommerce_product_duplicate_before_save', $mark, 1 );
	}
	return $d && $d->get_id() ? (int) $d->get_id() : 0;
}

/**
 * La copia de una ficha que la caja empezó en ese idioma y no llegó a enlazar (la petición se cortó). Solo
 * una a medias: una traducción terminada que alguien desenlazó en Polylang no se vuelve a coger.
 *
 * @param int    $pid  Ficha principal.
 * @param string $lang Idioma.
 * @return int 0 si no hay.
 */
function dox_pos_lang_orphan( $pid, $lang ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_dox_pos_lang_of' AND o.meta_value = %s JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = '_dox_pos_lang' AND l.meta_value = %s JOIN {$wpdb->postmeta} w ON w.post_id = p.ID AND w.meta_key = '_dox_pos_lang_pending' WHERE p.post_type = 'product' AND p.post_status NOT IN ( 'trash', 'auto-draft' ) ORDER BY p.ID ASC", (string) $pid, (string) $lang ) );
	foreach ( array_map( 'intval', $ids ) as $id ) {
		// Polylang le pone un idioma al guardarla (el principal, casi siempre): vale mientras no esté enlazada con otra.
		$group = array_diff( array_map( 'intval', (array) pll_get_post_translations( $id ) ), array( $id ) );
		if ( ! $group ) {
			return $id;
		}
	}
	return 0;
}

/**
 * Un candado por producto e idioma mientras se crea su traducción. Es una fila de wp_options que se inserta con
 * INSERT IGNORE (add_option() no sirve: si ya existe, la pisa). Uno de hace más de dos minutos es de una petición
 * que murió y se puede coger.
 *
 * @param int    $pid  Ficha principal.
 * @param string $lang Idioma.
 * @param bool   $take true coge el candado, false lo suelta.
 * @return bool Si se cogió.
 */
function dox_pos_lang_lock( $pid, $lang, $take = true ) {
	global $wpdb;
	static $mine = array(); // Lo que escribió esta petición en cada candado: solo suelta el suyo.
	$key = 'dox_pos_lang_lock_' . (int) $pid . '_' . sanitize_key( $lang );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tiene que ir a la base, sin caché.
	if ( ! $take ) {
		if ( isset( $mine[ $key ] ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $mine[ $key ] ) );
			unset( $mine[ $key ] );
		}
		return true;
	}
	$now = time();
	$val = $now . '-' . wp_generate_password( 8, false ); // La hora (para la caducidad) y algo único.
	$got = (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $key, $val ) );
	if ( ! $got ) {
		$old = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		$got = (int) $old < $now - 120 && $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $val, $key, $old ) );
	}
	// phpcs:enable
	if ( $got ) {
		$mine[ $key ] = $val;
	}
	return (bool) $got;
}

/**
 * Las traducciones de un producto leídas de la base, sin la caché de Polylang: [ idioma => ID ].
 *
 * @param int $pid Producto.
 * @return array<string,int>
 */
function dox_pos_lang_links_db( $pid ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Justo lo que se quiere: sin caché.
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT tt.description FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'post_translations' WHERE tr.object_id = %d LIMIT 1", (int) $pid ) );
	$map = $raw ? maybe_unserialize( $raw ) : array();
	return is_array( $map ) ? array_map( 'intval', $map ) : array();
}

/**
 * Los idiomas en los que a un producto le falta su traducción (o la tiene a medio crear): [ slug => nombre ].
 * La que está en la papelera no falta: alguien la quitó y la caja no la vuelve a hacer.
 * Vacío sin Polylang, con un solo idioma, o si el producto no es del idioma principal.
 *
 * @param int $pid Ficha principal.
 * @return array<string,string>
 */
function dox_pos_lang_missing( $pid ) {
	$out   = array();
	$langs = dox_pos_lang_languages();
	$pid   = (int) $pid;
	if ( count( $langs ) < 2 || ! $pid || pll_get_post_language( $pid ) !== dox_pos_lang_default() ) {
		return $out;
	}
	$links = (array) pll_get_post_translations( $pid );
	foreach ( array_slice( $langs, 1 ) as $l ) {
		$tid  = (int) ( $links[ $l['slug'] ] ?? 0 );
		$post = $tid ? get_post( $tid ) : null;
		if ( ! $post || ( 'trash' !== $post->post_status && get_post_meta( $tid, '_dox_pos_lang_pending', true ) ) ) {
			$out[ $l['slug'] ] = $l['name'];
		}
	}
	return $out;
}

/**
 * Pone un texto solo si cambió (comparado sin HTML): así no se pierde el HTML hecho en WooCommerce.
 *
 * @param WC_Product $p     Producto.
 * @param string     $field 'short' o 'description'.
 * @param string     $text  Lo escrito.
 */
function dox_pos_lang_set_text( $p, $field, $text ) {
	$now = 'short' === $field ? $p->get_short_description( 'edit' ) : $p->get_description( 'edit' );
	if ( trim( (string) $text ) === dox_pos_plain_text( $now ) ) {
		return;
	}
	if ( 'short' === $field ) {
		$p->set_short_description( wp_kses_post( (string) $text ) );
	} else {
		$p->set_description( wp_kses_post( (string) $text ) );
	}
}

/**
 * Las fotos de la ficha principal en su traducción, solo cuando cambian en la principal. Una traducción puede
 * llevar sus propias fotos (subidas aparte, con el texto alternativo en su idioma): esas no se pisan mientras la
 * principal siga con las mismas. _dox_pos_lang_imgs guarda las de la principal que se vieron la última vez.
 *
 * @param WC_Product $from    Ficha principal (o su variación).
 * @param WC_Product $to      La traducción (o su variación).
 * @param bool       $gallery También la galería (las variaciones no la tienen).
 */
function dox_pos_lang_copy_images( $from, $to, $gallery ) {
	$now  = array( (string) (int) $from->get_image_id( 'edit' ), $gallery ? implode( ',', $from->get_gallery_image_ids( 'edit' ) ) : '' );
	$raw  = (string) $to->get_meta( '_dox_pos_lang_imgs', true );
	$last = '' === $raw ? null : array_pad( explode( '|', $raw, 2 ), 2, '' );
	$to->update_meta_data( '_dox_pos_lang_imgs', implode( '|', $now ) );
	// La foto y la galería van cada una por su lado: cambiar solo la galería no pisa la foto propia.
	// La primera vez que la caja ve la traducción, lo que ya tenga es suyo.
	if ( $last ? $now[0] !== $last[0] : ! $to->get_image_id( 'edit' ) ) {
		$to->set_image_id( $from->get_image_id( 'edit' ) );
	}
	if ( $gallery && ( $last ? $now[1] !== $last[1] : ! $to->get_gallery_image_ids( 'edit' ) ) ) {
		$to->set_gallery_image_ids( $from->get_gallery_image_ids( 'edit' ) );
	}
}

/**
 * Lo compartido de la ficha principal, copiado a una ficha (la traducción o una de sus variaciones):
 * precio (salvo que el filtro dox_pos_lang_sync_price diga que no), costo, foto (si cambió), peso y medidas, unidades.
 *
 * @param WC_Product $from  De dónde.
 * @param WC_Product $to    A dónde.
 * @param bool       $price Copiar el precio.
 */
function dox_pos_lang_copy_shared( $from, $to, $price ) {
	if ( $price ) {
		$to->set_regular_price( $from->get_regular_price( 'edit' ) );
		$to->set_sale_price( $from->get_sale_price( 'edit' ) );
		$to->set_date_on_sale_from( $from->get_date_on_sale_from( 'edit' ) );
		$to->set_date_on_sale_to( $from->get_date_on_sale_to( 'edit' ) );
	}
	if ( method_exists( $from, 'get_cogs_value' ) ) {
		$to->set_cogs_value( $from->get_cogs_value( 'edit' ) );
	}
	dox_pos_lang_copy_images( $from, $to, ! $to instanceof WC_Product_Variation ); // La galería solo la tiene el producto.
	$to->set_weight( $from->get_weight( 'edit' ) );
	$to->set_length( $from->get_length( 'edit' ) );
	$to->set_width( $from->get_width( 'edit' ) );
	$to->set_height( $from->get_height( 'edit' ) );
	$to->set_manage_stock( $from->get_manage_stock( 'edit' ) );
	$to->set_backorders( $from->get_backorders( 'edit' ) );
	if ( $from->get_manage_stock( 'edit' ) && 'parent' !== $from->get_manage_stock() ) {
		$to->set_stock_quantity( $from->get_stock_quantity( 'edit' ) );
	}
	$to->set_stock_status( $from->get_stock_status( 'edit' ) );
	$pool = $from->get_meta( DOX_POS_POOL_META, true ); // La bolsa de color (includes/pools.php) también va con la variación.
	if ( '' !== (string) $pool ) {
		$to->update_meta_data( DOX_POS_POOL_META, $pool );
	} elseif ( '' !== (string) $to->get_meta( DOX_POS_POOL_META, true ) ) {
		$to->delete_meta_data( DOX_POS_POOL_META );
	}
}

/**
 * Escribe un producto de la caja en sus traducciones: las crea si faltan (con el ajuste puesto, o si se
 * escribió algo en ese idioma), pone el texto de cada idioma y copia lo compartido. Se puede repetir sin
 * miedo: una traducción que se cortó a medias (enlazada o no) se termina, y nunca se hace una segunda.
 * Nada de esto es un movimiento de unidades: el kardex no lo apunta.
 *
 * @param int   $pid    Ficha principal, ya guardada.
 * @param array $texts  dox_pos_lang_texts_from().
 * @param bool  $create Crear las traducciones que falten aunque no se haya escrito nada en ellas.
 * @return array made (idiomas creados), untranslated (creados con el texto de la principal), nocat ([idioma => categorías sin traducir]),
 *               failed (los que no se pudieron crear).
 */
function dox_pos_lang_sync( $pid, $texts, $create ) {
	$out   = array( 'made' => array(), 'untranslated' => array(), 'nocat' => array(), 'failed' => array() );
	$langs = dox_pos_lang_languages();
	$main  = wc_get_product( (int) $pid );
	if ( count( $langs ) < 2 || ! $main || ! function_exists( 'pll_save_post_translations' ) ) {
		return $out;
	}
	if ( dox_pos_lang_main_id( (int) $pid ) !== (int) $pid || pll_get_post_language( (int) $pid ) !== dox_pos_lang_default() ) {
		return $out; // Una traducción editada por su ID (o una ficha en otro idioma) no manda sobre las demás.
	}
	$def   = dox_pos_lang_default();
	$links = array( $def => (int) $pid );
	foreach ( (array) pll_get_post_translations( (int) $pid ) as $l => $t ) {
		$links[ $l ] = (int) $t;
	}
	$links[ $def ] = (int) $pid;
	$cat_tr        = function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( 'product_cat' );
	$held = array(); // Los candados que se cogieron (dox_pos_lang_lock()).
	dox_pos_stock_quiet( true );
	try {
		foreach ( array_slice( $langs, 1 ) as $l ) {
			$lang  = $l['slug'];
			$sent  = $texts[ $lang ] ?? null;
			$typed = $sent && ( '' !== $sent['name'] || '' !== $sent['short'] || '' !== $sent['description'] );
			$tid   = (int) ( $links[ $lang ] ?? 0 );
			$new   = false;
			if ( $tid && ! wc_get_product( $tid ) ) {
				$tid = 0;
			}
			if ( $tid && 'trash' === get_post_status( $tid ) ) {
				continue; // La mandaron a la papelera: se respeta, ni se toca ni se hace otra.
			}
			if ( ! $tid || get_post_meta( $tid, '_dox_pos_lang_pending', true ) ) {
				if ( ! $tid && ! $create && ! $typed ) {
					continue;
				}
				// Crear (o terminar) una traducción, de una petición a la vez: abrir el producto y guardarlo casi a la
				// vez, o dos teléfonos, harían dos copias. La que llega segunda no crea nada y lo dice.
				if ( ! dox_pos_lang_lock( (int) $pid, $lang ) ) {
					$out['failed'][] = $l['name'];
					continue;
				}
				$held[] = $lang;
				// Con el candado puesto, los enlaces de la base (los leídos antes pueden ser de antes de que la otra terminara).
				foreach ( dox_pos_lang_links_db( (int) $pid ) as $ol => $oid ) {
					if ( $ol !== $def && ! isset( $links[ $ol ] ) ) {
						$links[ $ol ] = $oid;
					}
				}
				$tid = (int) ( $links[ $lang ] ?? 0 );
				if ( $tid && ! wc_get_product( $tid ) ) {
					$tid = 0;
				}
				if ( $tid && 'trash' === get_post_status( $tid ) ) {
					continue; // La otra petición la dejó hecha y alguien ya la mandó a la papelera.
				}
				if ( ! $tid ) {
					// Primero la que se empezó y se cortó antes de enlazarla; si no hay, una copia nueva.
					$tid = dox_pos_lang_orphan( (int) $pid, $lang );
					if ( ! $tid ) {
						$tid = dox_pos_lang_duplicate( $main, $lang );
					}
					if ( ! $tid ) {
						$out['failed'][] = $l['name'];
						continue;
					}
					pll_set_post_language( $tid, $lang );
					$links[ $lang ] = $tid;
					pll_save_post_translations( $links );
					update_post_meta( $tid, '_dox_pos_lang_pending', 1 );
				}
				wp_cache_delete( $tid, 'post_meta' );
				// La que quedó a medias (la petición se cortó) se termina como si fuera nueva.
				if ( get_post_meta( $tid, '_dox_pos_lang_pending', true ) ) {
					$new           = true;
					$out['made'][] = $l['name'];
				}
			}
			$t = wc_get_product( $tid );
			// El texto: el que se escribió en esa pestaña; si no se escribió nombre, el de la principal.
			if ( $new || $sent ) {
				$t->set_name( $sent && '' !== $sent['name'] ? $sent['name'] : $main->get_name( 'edit' ) );
				if ( $sent ) {
					dox_pos_lang_set_text( $t, 'short', '' !== $sent['short'] || ! $new ? $sent['short'] : $main->get_short_description( 'edit' ) );
					dox_pos_lang_set_text( $t, 'description', '' !== $sent['description'] || ! $new ? $sent['description'] : $main->get_description( 'edit' ) );
				}
				if ( $new && ( ! $typed || $t->get_name( 'edit' ) === $main->get_name( 'edit' ) ) ) {
					$out['untranslated'][] = $l['name'];
				}
			}
			if ( $new || in_array( $t->get_status( 'edit' ), array( 'publish', 'private' ), true ) ) {
				$t->set_status( $main->get_status( 'edit' ) );
			}
			$t->set_catalog_visibility( $main->get_catalog_visibility( 'edit' ) );
			// Las categorías, en su traducción; la que no tiene traducción en ese idioma se queda fuera (y se avisa).
			$cats = array();
			foreach ( $main->get_category_ids( 'edit' ) as $cid ) {
				$tc = $cat_tr ? (int) pll_get_term( (int) $cid, $lang ) : (int) $cid;
				if ( $tc ) {
					$cats[] = $tc;
				} else {
					$term                   = get_term( (int) $cid, 'product_cat' );
					$out['nocat'][ $l['name'] ][] = $term && ! is_wp_error( $term ) ? $term->name : (string) $cid;
				}
			}
			$t->set_category_ids( $cats );
			$price = (bool) apply_filters( 'dox_pos_lang_sync_price', true, (int) $pid, $lang );
			dox_pos_lang_copy_shared( $main, $t, $price );
			if ( $new || '' === $t->get_sku( 'edit' ) || $main->get_sku( 'edit' ) === preg_replace( '/-\d+$/', '', $t->get_sku( 'edit' ) ) ) {
				try {
					$t->set_sku( dox_pos_lang_sku( $main->get_sku( 'edit' ), $lang, $tid ) );
				} catch ( Exception $e ) {
					$t->set_sku( '' ); // Ocupado: sin código antes que con el de otro.
				}
			}
			if ( $main->is_type( 'variable' ) ) {
				$t->set_attributes( $main->get_attributes( 'edit' ) );
				$t->update_meta_data( '_dox_pos_axes', $main->get_meta( '_dox_pos_axes', true ) );
			}
			$t->save();
			if ( $main->is_type( 'variable' ) ) {
				dox_pos_lang_sync_variations( $main, $tid, $lang, $price );
			}
			delete_post_meta( $tid, '_dox_pos_lang_pending' ); // Terminada.
			wc_delete_product_transients( $tid );
			do_action( 'litespeed_purge_post', $tid );
		}
	} finally {
		dox_pos_stock_quiet( false );
		foreach ( $held as $lang ) {
			dox_pos_lang_lock( (int) $pid, $lang, false );
		}
	}
	return $out;
}

/**
 * Las variaciones de la traducción: cada una de la principal con su gemela (la crea si falta), con su
 * precio, unidades y foto. Las que la principal ya no tiene se quedan como están.
 *
 * @param WC_Product $main  Ficha principal (variable).
 * @param int        $tid   Su traducción.
 * @param string     $lang  Idioma de la traducción.
 * @param bool       $price Copiar el precio.
 */
function dox_pos_lang_sync_variations( $main, $tid, $lang, $price ) {
	foreach ( $main->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v ) {
			continue;
		}
		$twin = dox_pos_lang_twin_variation( (int) $vid, (int) $tid );
		$w    = $twin ? wc_get_product( $twin ) : null;
		if ( ! $w ) {
			$w = new WC_Product_Variation();
			$w->set_parent_id( (int) $tid );
		}
		$w->set_status( $v->get_status( 'edit' ) ); // También la que se ocultó porque la principal la había perdido y vuelve.
		$w->set_attributes( $v->get_attributes( 'edit' ) );
		dox_pos_lang_copy_shared( $v, $w, $price );
		$w->update_meta_data( '_dox_pos_lang_of', (int) $vid );
		if ( '' === $w->get_sku( 'edit' ) || $v->get_sku( 'edit' ) === preg_replace( '/-\d+$/', '', $w->get_sku( 'edit' ) ) ) {
			try {
				$w->set_sku( dox_pos_lang_sku( $v->get_sku( 'edit' ), $lang, (int) $w->get_id() ) );
			} catch ( Exception $e ) {
				$w->set_sku( '' );
			}
		}
		$w->save();
	}
	// La que copió de una variación que la principal ya no tiene, deja de venderse en ese idioma. Las hijas se leen de
	// la base: la lista de WooCommerce va en un transitorio que puede seguir con la que se borró.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$kids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'", $main->get_id() ) ) );
	foreach ( wc_get_product( (int) $tid )->get_children() as $wid ) {
		$of = (int) get_post_meta( $wid, '_dox_pos_lang_of', true );
		if ( $of && ! in_array( $of, $kids, true ) ) {
			$w = wc_get_product( $wid );
			if ( $w && 'private' !== $w->get_status( 'edit' ) ) {
				$w->set_status( 'private' );
				$w->save();
			}
		}
	}
	WC_Product_Variable::sync( (int) $tid );
}
