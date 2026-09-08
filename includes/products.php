<?php
/**
 * Crear productos desde la caja.
 *
 * Un producto variable con sus tallas y colores (o simple, si no tiene ninguno), con las
 * fotos convertidas a WebP en el servidor (la original no se guarda; entran JPG, PNG, WebP
 * y HEIC del iPhone) y el código armado como lo hace la tienda: el del padre más dos
 * dígitos de talla y dos de color, aprendidos de los productos que ya existen.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'dox_pos_clean_images', 'dox_pos_clean_pending_images' );

/**
 * Quién puede crear productos y subir fotos: administradores y gerentes de tienda.
 */
function dox_pos_products_cap() {
	return 'manage_woocommerce';
}

/**
 * Los ajustes de productos (WooCommerce > Dox POS > Productos), ya validados.
 *
 * @return array{quality:int,max_px:int,sku:string,size_attr:string,color_attr:string,costs:bool}
 */
function dox_pos_products_settings() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$s   = get_option( 'dox_pos_products', array() );
	$s   = is_array( $s ) ? $s : array();
	$sku = (string) ( $s['sku'] ?? '' );
	$cache = array(
		'quality'    => isset( $s['quality'] ) && (int) $s['quality'] >= 50 ? min( 100, (int) $s['quality'] ) : 88,
		'max_px'     => isset( $s['max_px'] ) && (int) $s['max_px'] >= 800 ? min( 4000, (int) $s['max_px'] ) : 1600,
		'sku'        => in_array( $sku, array( 'codes', 'slugs', 'none' ), true ) ? $sku : 'codes',
		'size_attr'  => dox_pos_attribute_taxonomy( (string) ( $s['size_attr'] ?? '' ), array( 'talla', 'tallas', 'talle', 'size', 'sizes' ) ),
		'color_attr' => dox_pos_attribute_taxonomy( (string) ( $s['color_attr'] ?? '' ), array( 'color', 'colores', 'colour', 'colors' ) ),
		'costs'      => ! isset( $s['costs'] ) || ! empty( $s['costs'] ), // De fábrica se llevan costos; se apaga en Ajustes > Productos.
	);
	return $cache;
}

/**
 * Los atributos globales de la tienda: "pa_talla" => "Talla".
 *
 * @return array<string,string>
 */
function dox_pos_attribute_taxonomies() {
	$out = array();
	if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
		foreach ( wc_get_attribute_taxonomies() as $a ) {
			$out[ 'pa_' . $a->attribute_name ] = $a->attribute_label;
		}
	}
	return $out;
}

/**
 * La taxonomía de un atributo ("pa_talla"): la del ajuste si existe, o la que se llame
 * como uno de los nombres dados. Vacío si la tienda no tiene ninguna así.
 *
 * @param string   $set   Lo que dice el ajuste.
 * @param string[] $names Nombres que valen (en minúsculas, sin tildes).
 * @return string
 */
function dox_pos_attribute_taxonomy( $set, $names ) {
	$all = dox_pos_attribute_taxonomies();
	if ( $set && isset( $all[ $set ] ) ) {
		return $set;
	}
	foreach ( $all as $tax => $label ) {
		if ( in_array( substr( $tax, 3 ), $names, true ) || in_array( strtolower( remove_accents( $label ) ), $names, true ) ) {
			return $tax;
		}
	}
	return '';
}

function dox_pos_size_attribute() {
	return dox_pos_products_settings()['size_attr'];
}

function dox_pos_color_attribute() {
	return dox_pos_products_settings()['color_attr'];
}

/* =====================================================================
 * Lo que el formulario necesita: categorías, tallas, colores
 * ===================================================================== */

/**
 * Todo lo que la pantalla "Nuevo producto" necesita para pintarse. Se guarda una hora;
 * crear un producto o un color lo renueva.
 *
 * @return array
 */
function dox_pos_product_form() {
	$cached = get_transient( 'dox_pos_product_form' );
	if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === DOX_POS_VERSION ) {
		return $cached;
	}
	$s      = dox_pos_products_settings();
	$sizes  = dox_pos_attribute_terms( $s['size_attr'], 'size' );
	$colors = dox_pos_attribute_terms( $s['color_attr'], 'color' );
	$out    = array(
		'version'    => DOX_POS_VERSION,
		'categories' => dox_pos_product_categories( $sizes ),
		'sizes'      => $sizes,
		'colors'     => $colors,
		'sku_format' => $s['sku'],
		'quality'    => $s['quality'],
		'max_px'     => $s['max_px'],
		'has_size'   => '' !== $s['size_attr'],  // Sin atributo de talla en la tienda, la pantalla no lo pregunta.
		'has_color'  => '' !== $s['color_attr'],
		'price_range' => dox_pos_price_range(),  // Lo que cuesta lo más barato y lo más caro: para avisar de un precio raro.
	);
	set_transient( 'dox_pos_product_form', $out, HOUR_IN_SECONDS );
	return $out;
}

/**
 * El precio más bajo y el más alto entre lo publicado, para avisar de un precio que se sale
 * de lo habitual (un cero de más o de menos). [0, 0] si la tienda no tiene precios.
 *
 * @return float[]
 */
function dox_pos_price_range() {
	global $wpdb;
	$r = $wpdb->get_row(
		"SELECT MIN( CAST( pm.meta_value AS DECIMAL(18,2) ) ) mn, MAX( CAST( pm.meta_value AS DECIMAL(18,2) ) ) mx
		FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		WHERE pm.meta_key = '_price' AND pm.meta_value <> '' AND pm.meta_value + 0 > 0 AND p.post_status = 'publish' AND p.post_type IN ( 'product', 'product_variation' )",
		ARRAY_A
	);
	return array( (float) ( $r['mn'] ?? 0 ), (float) ( $r['mx'] ?? 0 ) );
}

/**
 * Los valores de un atributo, listos para las fichas: las tallas ordenadas por edad
 * (con su etiqueta corta, si el tema la tiene) y los colores por uso, con su hex.
 *
 * @param string $tax  Taxonomía (pa_talla).
 * @param string $kind size o color.
 * @return array
 */
function dox_pos_attribute_terms( $tax, $kind ) {
	if ( ! $tax || ! taxonomy_exists( $tax ) ) {
		return array();
	}
	$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$out = array();
	foreach ( $terms as $t ) {
		$row = array( 'id' => (int) $t->term_id, 'slug' => $t->slug, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), 'count' => (int) $t->count );
		if ( 'size' === $kind ) {
			$label        = get_term_meta( $t->term_id, 'st-label-swatch', true ); // La etiqueta corta de XStore ("0-12 M").
			$row['label'] = is_string( $label ) ? $label : '';
			$row['key']   = dox_pos_size_sort_key( $t->name );
		} else {
			$row['hex'] = dox_pos_term_hex( $t->term_id );
		}
		$out[] = $row;
	}
	if ( 'size' === $kind ) {
		usort( $out, fn( $a, $b ) => ( $a['key'] <=> $b['key'] ) ?: strnatcasecmp( $a['name'], $b['name'] ) );
	} else {
		usort( $out, fn( $a, $b ) => ( $b['count'] <=> $a['count'] ) ?: strnatcasecmp( $a['name'], $b['name'] ) );
	}
	return array_values( $out );
}

/**
 * Un número para ordenar tallas por edad: "0-6 meses" antes que "2-3 años", y "0- Siempre"
 * (talla única) al final. Lee dos números con su unidad; los años se pasan a meses.
 *
 * @param string $name El nombre de la talla.
 * @return int
 */
function dox_pos_size_sort_key( $name ) {
	$n = mb_strtolower( remove_accents( (string) $name ) );
	if ( preg_match( '/siempre|unica|unico|one size|talla u/', $n ) ) {
		return 9999999;
	}
	if ( ! preg_match_all( '/(\d+(?:[.,]\d+)?)\s*(meses|mes|m|anos|ano|a|years|year|y)?(?![\w.])/', $n, $m, PREG_SET_ORDER ) ) {
		return 9000000;
	}
	$nums = array();
	foreach ( array_slice( $m, 0, 2 ) as $i => $tok ) {
		$nums[ $i ] = array( (float) str_replace( ',', '.', $tok[1] ), $tok[2] ?? '' );
	}
	// Un número sin unidad toma la del siguiente ("2-3 años": los dos son años).
	if ( isset( $nums[1] ) && '' === $nums[0][1] ) {
		$nums[0][1] = $nums[1][1];
	}
	$months = function ( $pair ) {
		$u = $pair[1];
		return $pair[0] * ( '' !== $u && in_array( $u[0], array( 'a', 'y' ), true ) ? 12 : 1 );
	};
	$start = $months( $nums[0] );
	$end   = isset( $nums[1] ) ? $months( $nums[1] ) : $start;
	return (int) round( $start * 1000 + min( 999, $end ) );
}

/**
 * El color de una muestra ("#e8d8c3"): lo guarda el tema en st-color-swatch.
 *
 * @param int $term_id Término.
 * @return string Hex, o vacío.
 */
function dox_pos_term_hex( $term_id ) {
	$v = get_term_meta( $term_id, 'st-color-swatch', true );
	if ( is_array( $v ) ) {
		$v = reset( $v );
	}
	return is_string( $v ) ? dox_pos_hex( $v ) : '';
}

/**
 * Las categorías con productos, agrupadas por su categoría de arriba, con el prefijo de
 * código y las tallas que usan sus productos (para sugerirlos al elegirla).
 *
 * @param array $sizes Las tallas, para pasar de slug a id.
 * @return array
 */
function dox_pos_product_categories( $sizes ) {
	$all = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	if ( is_wp_error( $all ) ) {
		return array();
	}
	$by_id = array();
	foreach ( $all as $t ) {
		$by_id[ $t->term_id ] = $t;
	}
	$prefix = dox_pos_category_prefixes();
	$sets   = dox_pos_category_size_sets( $sizes );
	$plain  = fn( $s ) => html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ); // "Lazos &amp; pinzas" se guarda así en la base.
	$out    = array();
	foreach ( $all as $t ) {
		if ( $t->count < 1 || 'uncategorized' === $t->slug ) {
			continue;
		}
		$path = array();
		$x    = $t;
		$n    = 0;
		while ( $x->parent && isset( $by_id[ $x->parent ] ) && $n++ < 10 ) {
			$x = $by_id[ $x->parent ];
			array_unshift( $path, $plain( $x->name ) );
		}
		$out[] = array(
			'id'       => (int) $t->term_id,
			'name'     => $plain( $t->name ),
			'group'    => $plain( $x->name ),
			'group_id' => (int) $x->term_id,
			'path'     => implode( ' › ', $path ),
			'count'    => (int) $t->count,
			'prefix'   => $prefix[ $t->term_id ] ?? '',
			'sizes'    => $sets[ $t->term_id ] ?? array(),
		);
	}
	usort(
		$out,
		function ( $a, $b ) {
			$g = strnatcasecmp( $a['group'], $b['group'] );
			if ( $g ) {
				return $g;
			}
			if ( $a['id'] === $a['group_id'] ) {
				return -1; // La de arriba, primero en su grupo.
			}
			if ( $b['id'] === $b['group_id'] ) {
				return 1;
			}
			return strnatcasecmp( $a['path'] . ' ' . $a['name'], $b['path'] . ' ' . $b['name'] );
		}
	);
	return $out;
}

/**
 * El prefijo de código más usado en cada categoría ("VE" en Vestidos), mirando los productos.
 *
 * @return array<int,string>
 */
function dox_pos_category_prefixes() {
	global $wpdb;
	$rows  = $wpdb->get_results( "SELECT x.term_id, pm.meta_value sku FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku' AND pm.meta_value <> '' JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = tr.term_taxonomy_id AND x.taxonomy = 'product_cat' WHERE p.post_type = 'product' AND p.post_status IN ('publish','private')", ARRAY_A );
	$count = array();
	foreach ( (array) $rows as $r ) {
		if ( preg_match( '/^([A-Za-z]{1,4})\d/', $r['sku'], $m ) ) {
			$p = strtoupper( $m[1] );
			$count[ $r['term_id'] ][ $p ] = ( $count[ $r['term_id'] ][ $p ] ?? 0 ) + 1;
		}
	}
	$out = array();
	foreach ( $count as $tid => $c ) {
		arsort( $c );
		$out[ (int) $tid ] = (string) array_key_first( $c );
	}
	return $out;
}

/**
 * Las tallas que más se repiten en los productos de cada categoría (las seis de un vestido,
 * las de rango de unas medias), como ids de término.
 *
 * @param array $sizes Las tallas.
 * @return array<int,int[]>
 */
function dox_pos_category_size_sets( $sizes ) {
	global $wpdb;
	$tax = dox_pos_size_attribute();
	if ( ! $tax || ! $sizes ) {
		return array();
	}
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT x.term_id, v.post_parent, GROUP_CONCAT(DISTINCT t.meta_value ORDER BY t.meta_value SEPARATOR ',') slugs FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} t ON t.post_id = v.ID AND t.meta_key = %s AND t.meta_value <> '' JOIN {$wpdb->term_relationships} tr ON tr.object_id = v.post_parent JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = tr.term_taxonomy_id AND x.taxonomy = 'product_cat' WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' GROUP BY x.term_id, v.post_parent", 'attribute_' . $tax ), ARRAY_A );
	$by_slug = array_column( $sizes, 'id', 'slug' );
	$count   = array();
	foreach ( (array) $rows as $r ) {
		$count[ $r['term_id'] ][ $r['slugs'] ] = ( $count[ $r['term_id'] ][ $r['slugs'] ] ?? 0 ) + 1;
	}
	$out = array();
	foreach ( $count as $tid => $c ) {
		arsort( $c );
		$ids = array();
		foreach ( explode( ',', (string) array_key_first( $c ) ) as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$ids[] = (int) $by_slug[ $slug ];
			}
		}
		$out[ (int) $tid ] = $ids;
	}
	return $out;
}

/* =====================================================================
 * El código (SKU): como lo arma la tienda
 * ===================================================================== */

/**
 * Los dos dígitos de cada talla y de cada color, aprendidos de las variaciones que ya
 * existen (el código de la variación menos el del padre: talla, y color si hay). Se guarda
 * en una opción y se rehace cada día; los códigos asignados a valores nuevos se conservan.
 *
 * @param bool $rebuild Forzar la relectura.
 * @return array{size:array<string,string>,color:array<string,string>,time:int}
 */
function dox_pos_sku_codes( $rebuild = false ) {
	$codes = get_option( 'dox_pos_sku_codes', array() );
	if ( $rebuild || ! is_array( $codes ) || empty( $codes['time'] ) || $codes['time'] < time() - DAY_IN_SECONDS ) {
		$codes = dox_pos_build_sku_codes( is_array( $codes ) ? $codes : array() );
	}
	return $codes;
}

function dox_pos_build_sku_codes( $prev ) {
	global $wpdb;
	$size_tax  = dox_pos_size_attribute();
	$color_tax = dox_pos_color_attribute();
	$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT vs.meta_value vsku, ps.meta_value psku, t.meta_value talla, c.meta_value color FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} vs ON vs.post_id = v.ID AND vs.meta_key = '_sku' AND vs.meta_value <> '' JOIN {$wpdb->postmeta} ps ON ps.post_id = v.post_parent AND ps.meta_key = '_sku' AND ps.meta_value <> '' LEFT JOIN {$wpdb->postmeta} t ON t.post_id = v.ID AND t.meta_key = %s LEFT JOIN {$wpdb->postmeta} c ON c.post_id = v.ID AND c.meta_key = %s WHERE v.post_type = 'product_variation'", 'attribute_' . $size_tax, 'attribute_' . $color_tax ), ARRAY_A );
	$sizes     = array();
	$colors    = array();
	foreach ( (array) $rows as $r ) {
		if ( 0 !== strpos( $r['vsku'], $r['psku'] ) ) {
			continue;
		}
		$rem = substr( $r['vsku'], strlen( $r['psku'] ) );
		if ( ! ctype_digit( $rem ) || ( 3 !== strlen( $rem ) && 4 !== strlen( $rem ) ) ) {
			continue;
		}
		$sc = substr( $rem, 0, 2 );
		$cc = 4 === strlen( $rem ) ? substr( $rem, 2, 2 ) : '';
		if ( '' !== (string) $r['talla'] ) {
			$sizes[ $r['talla'] ][ $sc ] = ( $sizes[ $r['talla'] ][ $sc ] ?? 0 ) + 1;
		}
		if ( '' !== $cc && '' !== (string) $r['color'] ) {
			$colors[ $r['color'] ][ $cc ] = ( $colors[ $r['color'] ][ $cc ] ?? 0 ) + 1;
		}
	}
	$pick = function ( $counts, $keep ) {
		$out = array();
		foreach ( $counts as $slug => $c ) {
			arsort( $c );
			$out[ $slug ] = (string) array_key_first( $c );
		}
		foreach ( (array) $keep as $slug => $code ) {
			if ( ! isset( $out[ $slug ] ) ) {
				$out[ $slug ] = (string) $code;
			}
		}
		return $out;
	};
	$codes = array(
		'size'  => $pick( $sizes, $prev['size'] ?? array() ),
		'color' => $pick( $colors, $prev['color'] ?? array() ),
		'time'  => time(),
	);
	update_option( 'dox_pos_sku_codes', $codes, false );
	return $codes;
}

/**
 * El código de dos dígitos de una talla o un color. Si no tiene (un color nuevo), se le da
 * el siguiente libre y se guarda.
 *
 * @param string $kind size o color.
 * @param string $slug El valor.
 * @return string
 */
function dox_pos_sku_code( $kind, $slug ) {
	$codes = dox_pos_sku_codes();
	if ( isset( $codes[ $kind ][ $slug ] ) ) {
		return $codes[ $kind ][ $slug ];
	}
	$max = 0;
	foreach ( (array) $codes[ $kind ] as $c ) {
		$max = max( $max, (int) $c );
	}
	$new                    = str_pad( (string) ( $max + 1 ), 2, '0', STR_PAD_LEFT );
	$codes[ $kind ][ $slug ] = $new;
	update_option( 'dox_pos_sku_codes', $codes, false );
	return $new;
}

/**
 * El código de talla de un producto sin tallas: el de la "talla única" de la tienda
 * ("0- Siempre" en Rosella), o 00 si no hay ninguna.
 */
function dox_pos_sku_code_no_size() {
	foreach ( dox_pos_attribute_terms( dox_pos_size_attribute(), 'size' ) as $t ) {
		if ( 9999999 === $t['key'] ) {
			return dox_pos_sku_code( 'size', $t['slug'] );
		}
	}
	return '00';
}

/**
 * El código de una variación según el formato del ajuste.
 *
 * @param string       $format codes, slugs o none.
 * @param string       $base   El código del padre.
 * @param WP_Term|null $size   La talla.
 * @param WP_Term|null $color  El color.
 * @return string
 */
function dox_pos_variation_sku( $format, $base, $size, $color ) {
	if ( 'none' === $format || '' === $base ) {
		return '';
	}
	if ( 'slugs' === $format ) {
		return implode( '-', array_filter( array( $base, $size ? $size->slug : '', $color ? $color->slug : '' ) ) );
	}
	return $base . ( $size ? dox_pos_sku_code( 'size', $size->slug ) : dox_pos_sku_code_no_size() ) . ( $color ? dox_pos_sku_code( 'color', $color->slug ) : '0' );
}

/**
 * El siguiente código libre con ese prefijo: VE82 es el mayor de los vestidos, así que VE83.
 * Cuenta también los productos simples con código largo (DD94110 es el artículo 94).
 *
 * @param string $prefix Letras.
 * @return string Vacío si no se pudo.
 */
function dox_pos_next_sku( $prefix ) {
	global $wpdb;
	$prefix = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $prefix ) );
	if ( '' === $prefix ) {
		return '';
	}
	$skus = $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product' WHERE pm.meta_key = '_sku' AND pm.meta_value LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	$max  = 0;
	foreach ( (array) $skus as $sku ) {
		$digits = substr( strtoupper( $sku ), strlen( $prefix ) );
		if ( '' === $digits || ! ctype_digit( $digits ) ) {
			continue;
		}
		$n = null;
		if ( strlen( $digits ) <= 3 ) {
			$n = $digits;
		} else {
			// Código largo: quitar talla y color (3 o 4 dígitos) y quedarse con un artículo de 2 cifras, o de 3.
			foreach ( array( 4, 3 ) as $cut ) {
				$c = substr( $digits, 0, -$cut );
				if ( 2 === strlen( $c ) ) {
					$n = $c;
					break;
				}
			}
			if ( null === $n && 3 === strlen( substr( $digits, 0, -3 ) ) ) {
				$n = substr( $digits, 0, -3 );
			}
		}
		if ( null !== $n ) {
			$max = max( $max, (int) $n );
		}
	}
	$next = $max + 1;
	for ( $i = 0; $i < 50; $i++ ) {
		$cand = $prefix . str_pad( (string) $next, $next > 99 ? 3 : 2, '0', STR_PAD_LEFT );
		if ( '' === dox_pos_sku_problem( $cand ) ) {
			return $cand;
		}
		$next++;
	}
	return '';
}

/**
 * ¿Está libre ese código? Devuelve el motivo si no, o vacío si sí.
 *
 * @param string $sku Código.
 * @return string
 */
function dox_pos_sku_problem( $sku ) {
	$sku = trim( (string) $sku );
	if ( '' === $sku ) {
		return __( 'Enter a SKU.', 'dox-pos' );
	}
	if ( ! preg_match( '/^[A-Za-z0-9._-]{2,40}$/', $sku ) ) {
		return __( 'Only letters, numbers, dots and hyphens.', 'dox-pos' );
	}
	$id = wc_get_product_id_by_sku( $sku );
	if ( $id ) {
		$p = wc_get_product( $id );
		return sprintf( /* translators: 1: código, 2: producto */ __( 'SKU %1$s is already used by %2$s.', 'dox-pos' ), $sku, $p ? dox_pos_item_name( $p ) : '#' . $id );
	}
	return '';
}

/* =====================================================================
 * Las fotos: a WebP en el servidor; la original no se guarda
 * ===================================================================== */

/**
 * Recibe una foto subida, la reduce al tamaño del ajuste, la guarda como WebP con la calidad
 * del ajuste, crea el adjunto (con sus tamaños, también en WebP) y descarta la original.
 * Queda marcada como pendiente hasta que se crea el producto; si nadie la usa, se borra al
 * día siguiente.
 *
 * @param array  $file  Un elemento de $_FILES.
 * @param string $title El nombre del producto, para el archivo y el texto alternativo.
 * @return array|WP_Error
 */
function dox_pos_upload_image( $file, $title = '' ) {
	if ( ! empty( $file['error'] ) ) {
		$msg = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
			? sprintf( /* translators: %s: tamaño */ __( 'The photo is larger than the server allows (%s).', 'dox-pos' ), size_format( wp_max_upload_size() ) )
			: __( 'The photo did not upload completely. Please try again.', 'dox-pos' );
		return new WP_Error( 'dox_pos_foto', $msg );
	}
	if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'dox_pos_foto', __( 'No photo was received.', 'dox-pos' ) );
	}
	$type = wp_get_image_mime( $file['tmp_name'] );
	if ( ! in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'image/avif', 'image/bmp', 'image/tiff' ), true ) ) {
		return new WP_Error( 'dox_pos_foto', __( 'The store cannot read that file as a photo. JPG, PNG, WebP and HEIC work.', 'dox-pos' ) );
	}
	$s = dox_pos_products_settings();
	dox_pos_limit_imagick();
	wp_raise_memory_limit( 'image' );
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 120 );
	}
	// La calidad va por el filtro, no por set_quality(): el editor de WordPress vuelve a la de
	// fábrica (82) después de cada redimensión y al generar los tamaños. Comprobado en WP 7.1.
	$quality = function () use ( $s ) {
		return (int) $s['quality'];
	};
	add_filter( 'wp_editor_set_quality', $quality, 99 );
	$saved = dox_pos_convert_to_webp( $file['tmp_name'], $type, $s['max_px'], $title );
	if ( is_wp_error( $saved ) ) {
		remove_filter( 'wp_editor_set_quality', $quality, 99 );
		return $saved;
	}
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/webp',
			'post_title'     => $title ? $title : pathinfo( $saved['file'], PATHINFO_FILENAME ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'guid'           => $saved['guid'],
		),
		$saved['path']
	);
	if ( ! $id || is_wp_error( $id ) ) {
		remove_filter( 'wp_editor_set_quality', $quality, 99 );
		wp_delete_file( $saved['path'] );
		return new WP_Error( 'dox_pos_foto', __( 'The photo could not be added to the media library.', 'dox-pos' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $saved['path'] ) ); // Los tamaños, también en WebP y con la misma calidad.
	remove_filter( 'wp_editor_set_quality', $quality, 99 );
	if ( $title ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $title );
	}
	update_post_meta( $id, '_dox_pos_pending', time() );
	wp_delete_file( $file['tmp_name'] ); // La original no se guarda.
	return array(
		'id'     => (int) $id,
		'url'    => wp_get_attachment_image_url( $id, 'woocommerce_thumbnail' ),
		'full'   => wp_get_attachment_url( $id ),
		'width'  => (int) $saved['width'],
		'height' => (int) $saved['height'],
		'kb'     => (int) round( (int) $saved['filesize'] / 1024 ),
		'from'   => $type,
	);
}

/**
 * Lee la foto (JPG, PNG, WebP, HEIC...), la reduce si su lado mayor pasa de $max_px y la
 * guarda como WebP en la carpeta de subidas con un nombre único a partir del título.
 *
 * @param string $path   Archivo de origen.
 * @param string $type   Su mime.
 * @param int    $max_px Lado mayor.
 * @param string $title  Para el nombre del archivo.
 * @return array|WP_Error Lo que devuelve WP_Image_Editor::save().
 */
function dox_pos_convert_to_webp( $path, $type, $max_px, $title ) {
	$editor = wp_get_image_editor( $path, array( 'mime_type' => $type ) );
	if ( is_wp_error( $editor ) ) {
		return new WP_Error( 'dox_pos_foto', in_array( $type, array( 'image/heic', 'image/heif' ), true ) ? __( 'This server cannot read HEIC photos. Send the photo as a JPG.', 'dox-pos' ) : __( 'The photo could not be read.', 'dox-pos' ) );
	}
	$size = $editor->get_size();
	if ( max( (int) $size['width'], (int) $size['height'] ) > $max_px ) {
		$r = $editor->resize( $max_px, $max_px, false );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'dox_pos_foto', __( 'The photo could not be resized.', 'dox-pos' ) );
		}
	}
	$editor->set_quality(); // La del filtro.
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return new WP_Error( 'dox_pos_foto', $uploads['error'] );
	}
	$base  = sanitize_title( remove_accents( $title ) );
	$base  = $base ? $base : 'producto';
	$name  = wp_unique_filename( $uploads['path'], $base . '.webp' );
	$saved = $editor->save( trailingslashit( $uploads['path'] ) . $name, 'image/webp' );
	unset( $editor );
	if ( is_wp_error( $saved ) ) {
		return new WP_Error( 'dox_pos_foto', __( 'The photo could not be saved as WebP.', 'dox-pos' ) );
	}
	$saved['guid'] = trailingslashit( $uploads['url'] ) . $name;
	return $saved;
}

/**
 * ImageMagick con techo de memoria: una foto de 12 MP en HEIC se decodifica en unos 200 MB;
 * por encima del techo usa disco en vez de tumbar el proceso.
 */
function dox_pos_limit_imagick() {
	if ( ! class_exists( 'Imagick' ) ) {
		return;
	}
	try {
		Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );
		Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024 );
	} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Sin límite: se sigue igual.
	}
}

/**
 * Borra una foto subida desde la caja que todavía no está en ningún producto.
 *
 * @param int $id Adjunto.
 * @return array|WP_Error
 */
function dox_pos_delete_pending_image( $id ) {
	if ( 'attachment' !== get_post_type( $id ) || ! get_post_meta( $id, '_dox_pos_pending', true ) ) {
		return new WP_Error( 'dox_pos_foto', __( 'That photo is not pending.', 'dox-pos' ) );
	}
	wp_delete_attachment( $id, true );
	return array( 'deleted' => (int) $id );
}

/**
 * Las fotos que se subieron y no acabaron en ningún producto (la pantalla se cerró): se
 * borran al día siguiente. Lo lanza Action Scheduler una vez al día.
 */
function dox_pos_clean_pending_images() {
	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 50,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_dox_pos_pending',
					'value'   => time() - DAY_IN_SECONDS,
					'compare' => '<',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	foreach ( (array) $ids as $id ) {
		wp_delete_attachment( (int) $id, true );
	}
	return count( (array) $ids );
}

/* =====================================================================
 * Crear el producto
 * ===================================================================== */

/**
 * Crea el producto con sus variaciones.
 *
 * @param array $data name, price, cost (por unidad, opcional), sku, categories [id], description, publish, ref,
 *                    sizes [id], colors [ {key, id | name, hex} ], qty {colorKey: {sizeId: n}},
 *                    images [ {id, color: colorKey | ''} ].
 * @return array|WP_Error
 */
function dox_pos_create_product( $data ) {
	$s   = dox_pos_products_settings();
	$ref = sanitize_text_field( (string) ( $data['ref'] ?? '' ) );
	if ( $ref ) {
		$dup = dox_pos_product_by_ref( $ref ); // El mismo envío dos veces: se devuelve lo que ya existe.
		if ( $dup ) {
			return dox_pos_product_response( $dup );
		}
	}
	$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
	if ( '' === $name ) {
		return new WP_Error( 'dox_pos_sin_nombre', __( 'Give the product a name.', 'dox-pos' ) );
	}
	$price = wc_format_decimal( (string) ( $data['price'] ?? '' ) );
	if ( '' === $price || (float) $price <= 0 ) {
		return new WP_Error( 'dox_pos_sin_precio', __( 'Set a price.', 'dox-pos' ) );
	}
	$cats = array();
	foreach ( (array) ( $data['categories'] ?? array() ) as $cid ) {
		if ( (int) $cid && term_exists( (int) $cid, 'product_cat' ) ) {
			$cats[] = (int) $cid;
		}
	}
	if ( ! $cats ) {
		return new WP_Error( 'dox_pos_sin_categoria', __( 'Choose at least one category.', 'dox-pos' ) );
	}
	$size_tax  = $s['size_attr'];
	$color_tax = $s['color_attr'];
	$sizes     = array(); // id => WP_Term
	if ( $size_tax ) {
		foreach ( (array) ( $data['sizes'] ?? array() ) as $sid ) {
			$t = get_term( (int) $sid, $size_tax );
			if ( $t && ! is_wp_error( $t ) ) {
				$sizes[ (int) $t->term_id ] = $t;
			}
		}
	}
	$colors = array(); // key => WP_Term
	if ( $color_tax ) {
		foreach ( (array) ( $data['colors'] ?? array() ) as $c ) {
			$c     = (array) $c;
			$key   = sanitize_text_field( (string) ( $c['key'] ?? '' ) );
			$cid   = (int) ( $c['id'] ?? 0 );
			$cname = sanitize_text_field( (string) ( $c['name'] ?? '' ) );
			if ( $cid ) {
				$t = get_term( $cid, $color_tax );
			} elseif ( '' !== $cname ) {
				$t = dox_pos_create_color( $cname, (string) ( $c['hex'] ?? '' ), $color_tax );
				if ( is_wp_error( $t ) ) {
					return $t;
				}
			} else {
				continue;
			}
			if ( $t && ! is_wp_error( $t ) ) {
				$colors[ '' !== $key ? $key : (string) $t->term_id ] = $t;
			}
		}
	}
	$qty = (array) ( $data['qty'] ?? array() );
	$sku = strtoupper( sanitize_text_field( (string) ( $data['sku'] ?? '' ) ) );
	if ( '' !== $sku ) {
		$problem = dox_pos_sku_problem( $sku );
		if ( $problem ) {
			return new WP_Error( 'dox_pos_codigo_ocupado', $problem );
		}
	} elseif ( 'none' !== $s['sku'] ) {
		return new WP_Error( 'dox_pos_sin_codigo', __( 'The product SKU is missing.', 'dox-pos' ) );
	}
	$images = array();
	foreach ( (array) ( $data['images'] ?? array() ) as $im ) {
		$im  = (array) $im;
		$iid = (int) ( $im['id'] ?? 0 );
		if ( $iid && 'attachment' === get_post_type( $iid ) && wp_attachment_is_image( $iid ) ) {
			$images[] = array( 'id' => $iid, 'color' => sanitize_text_field( (string) ( $im['color'] ?? '' ) ) );
		}
	}
	// La principal es la primera "para todas"; el resto va a la galería, y la primera de cada color a sus variaciones.
	$main     = 0;
	$gallery  = array();
	$by_color = array();
	foreach ( $images as $im ) {
		if ( ! $main && '' === $im['color'] ) {
			$main = $im['id'];
			continue;
		}
		if ( '' !== $im['color'] && ! isset( $by_color[ $im['color'] ] ) ) {
			$by_color[ $im['color'] ] = $im['id'];
		}
		$gallery[] = $im['id'];
	}
	if ( ! $main && $images ) {
		$main    = $images[0]['id'];
		$gallery = array_values( array_diff( $gallery, array( $main ) ) );
	}

	$variable = $sizes || $colors;
	$ctx      = dox_pos_stock_context( 'create' ); // El kardex: las unidades iniciales quedan como "Creado en la caja".
	$product  = $variable ? new WC_Product_Variable() : new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( empty( $data['publish'] ) ? 'private' : 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_category_ids( $cats );
	$product->set_description( wp_kses_post( (string) ( $data['description'] ?? '' ) ) );
	$cost = dox_pos_can_see_costs() ? dox_pos_parse_money( $data['cost'] ?? '' ) : null;
	if ( null !== $cost && $cost > 0 ) {
		$product->set_cogs_value( round( $cost, 2 ) ); // El costo por unidad va en el producto; cada talla lo hereda.
	}
	if ( $main ) {
		$product->set_image_id( $main );
	}
	if ( $gallery ) {
		$product->set_gallery_image_ids( $gallery );
	}
	$units = 0;
	if ( ! $variable ) {
		$n = dox_pos_qty_at( $qty, '', '0' );
		$product->set_regular_price( $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $n );
		$product->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
		$units = $n;
	} else {
		$attrs = array();
		if ( $colors ) {
			$attrs[] = dox_pos_make_attribute( $color_tax, array_map( fn( $t ) => (int) $t->term_id, array_values( $colors ) ), count( $attrs ) );
		}
		if ( $sizes ) {
			$attrs[] = dox_pos_make_attribute( $size_tax, array_keys( $sizes ), count( $attrs ) );
		}
		$product->set_attributes( $attrs );
	}
	try {
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		$pid = $product->save();
	} catch ( Exception $e ) {
		dox_pos_stock_context_end( $ctx );
		return new WP_Error( 'dox_pos_no_se_pudo', sprintf( /* translators: %s: motivo */ __( 'WooCommerce would not create the product: %s', 'dox-pos' ), $e->getMessage() ) );
	}
	if ( ! $pid ) {
		dox_pos_stock_context_end( $ctx );
		return new WP_Error( 'dox_pos_no_se_pudo', __( 'WooCommerce would not create the product.', 'dox-pos' ) );
	}
	update_post_meta( $pid, '_dox_pos_ref', $ref );
	update_post_meta( $pid, '_dox_pos_created_by', get_current_user_id() );

	$nvars = 0;
	if ( $variable ) {
		// Los términos del padre se fijan a mano: set_attributes() no los relaciona.
		if ( $colors ) {
			wp_set_object_terms( $pid, array_map( fn( $t ) => (int) $t->term_id, array_values( $colors ) ), $color_tax, false );
		}
		if ( $sizes ) {
			wp_set_object_terms( $pid, array_map( 'intval', array_keys( $sizes ) ), $size_tax, false );
		}
		$color_list = $colors ? $colors : array( '' => null );
		$size_list  = $sizes ? $sizes : array( 0 => null );
		foreach ( $color_list as $ckey => $cterm ) {
			foreach ( $size_list as $sid => $sterm ) {
				$v = new WC_Product_Variation();
				$v->set_parent_id( $pid );
				$v->set_status( 'publish' );
				$va = array();
				if ( $cterm ) {
					$va[ $color_tax ] = $cterm->slug;
				}
				if ( $sterm ) {
					$va[ $size_tax ] = $sterm->slug;
				}
				$v->set_attributes( $va );
				$vsku = dox_pos_variation_sku( $s['sku'], $sku, $sterm, $cterm );
				if ( '' !== $vsku ) {
					try {
						$v->set_sku( $vsku );
					} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ese código ya existía: la variación se queda sin código, el producto no se pierde.
					}
				}
				$n = dox_pos_qty_at( $qty, (string) $ckey, (string) $sid );
				$v->set_regular_price( $price );
				$v->set_manage_stock( true );
				$v->set_stock_quantity( $n );
				$v->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
				if ( $cterm && isset( $by_color[ $ckey ] ) ) {
					$v->set_image_id( $by_color[ $ckey ] );
				}
				$v->save();
				$units += $n;
				$nvars++;
			}
		}
		WC_Product_Variable::sync( $pid );
		wc_delete_product_transients( $pid );
	}
	dox_pos_stock_context_end( $ctx );
	foreach ( $images as $im ) {
		delete_post_meta( $im['id'], '_dox_pos_pending' );
		wp_update_post( array( 'ID' => $im['id'], 'post_parent' => $pid ) );
	}
	delete_transient( 'dox_pos_product_form' );
	do_action( 'litespeed_purge_post', $pid );
	return dox_pos_product_response( wc_get_product( $pid ), $units, $nvars );
}

/**
 * Un atributo global del producto, con sus valores y para variaciones.
 *
 * @param string $tax      pa_talla.
 * @param int[]  $term_ids Valores.
 * @param int    $position Orden.
 * @return WC_Product_Attribute
 */
function dox_pos_make_attribute( $tax, $term_ids, $position ) {
	$a = new WC_Product_Attribute();
	$a->set_id( wc_attribute_taxonomy_id_by_name( $tax ) );
	$a->set_name( $tax );
	$a->set_options( array_map( 'intval', $term_ids ) );
	$a->set_position( (int) $position );
	$a->set_visible( true );
	$a->set_variation( true );
	return $a;
}

/**
 * Un color nuevo: se crea el término y, si el tema usa muestras de color, su hex.
 *
 * @param string $name Nombre.
 * @param string $hex  Color.
 * @param string $tax  pa_color.
 * @return WP_Term|WP_Error
 */
function dox_pos_create_color( $name, $hex, $tax ) {
	$existing = term_exists( $name, $tax );
	if ( $existing ) {
		return get_term( (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ), $tax );
	}
	$r = wp_insert_term( $name, $tax );
	if ( is_wp_error( $r ) ) {
		return new WP_Error( 'dox_pos_color', sprintf( /* translators: 1: color, 2: motivo */ __( 'The color %1$s could not be created: %2$s', 'dox-pos' ), $name, $r->get_error_message() ) );
	}
	$hex = dox_pos_hex( $hex );
	if ( $hex ) {
		update_term_meta( (int) $r['term_id'], 'st-color-swatch', array( strtolower( $hex ) ) );
	}
	delete_transient( 'dox_pos_product_form' );
	return get_term( (int) $r['term_id'], $tax );
}

/**
 * Cuántas unidades pidió la caja para una combinación.
 *
 * @param array  $qty  {colorKey: {sizeId: n}}.
 * @param string $ckey Clave del color ('' sin colores).
 * @param string $sid  Id de la talla ('0' sin tallas).
 * @return int
 */
function dox_pos_qty_at( $qty, $ckey, $sid ) {
	$row = isset( $qty[ $ckey ] ) && is_array( $qty[ $ckey ] ) ? $qty[ $ckey ] : array();
	return max( 0, (int) ( $row[ $sid ] ?? 0 ) );
}

/**
 * El producto creado con esa referencia, si el envío se repitió.
 *
 * @param string $ref Referencia.
 * @return WC_Product|null
 */
function dox_pos_product_by_ref( $ref ) {
	$ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_key'       => '_dox_pos_ref', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $ref, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	$p = $ids ? wc_get_product( (int) $ids[0] ) : null;
	return $p ? $p : null;
}

/**
 * Lo que la caja enseña al terminar.
 *
 * @param WC_Product $p     El producto.
 * @param int|null   $units Unidades que se pusieron (si se saben).
 * @param int|null   $nvars Variaciones creadas (si se saben).
 * @return array
 */
function dox_pos_product_response( $p, $units = null, $nvars = null ) {
	global $wpdb;
	if ( null === $nvars ) {
		$nvars = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'", $p->get_id() ) );
	}
	if ( null === $units ) {
		$units = $p->is_type( 'variable' )
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(pm.meta_value) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} v ON v.ID = pm.post_id AND v.post_parent = %d AND v.post_type = 'product_variation' WHERE pm.meta_key = '_stock'", $p->get_id() ) )
			: (int) $p->get_stock_quantity();
	}
	return array(
		'product' => array(
			'id'         => $p->get_id(),
			'name'       => $p->get_name(),
			'sku'        => $p->get_sku( 'edit' ),
			'status'     => $p->get_status(),
			'url'        => get_permalink( $p->get_id() ),
			'edit_url'   => admin_url( 'post.php?post=' . $p->get_id() . '&action=edit' ),
			'variations' => (int) $nvars,
			'units'      => (int) $units,
			'image'      => $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : '',
		),
	);
}

/* =====================================================================
 * Editar un producto que ya existe
 * ===================================================================== */

/**
 * Productos entre los que elegir cuál editar, por páginas: todos por orden alfabético o,
 * con algo escrito, los que se llaman así o tienen ese código (el suyo o el de una talla);
 * publicados, ocultos y borradores.
 *
 * @param string $q    Lo escrito ('' = todos).
 * @param int    $page Página, desde 1.
 * @param int    $per  Cuántos por página.
 * @return array{items: array, total: int, page: int, pages: int}
 */
function dox_pos_find_products( $q, $page = 1, $per = 20 ) {
	global $wpdb;
	$q     = trim( (string) $q );
	$page  = max( 1, (int) $page );
	$per   = max( 1, min( 50, (int) $per ) );
	$where = "p.post_type = 'product' AND p.post_status IN ( 'publish', 'private', 'draft' )";
	$args  = array();
	if ( '' !== $q ) {
		$like   = '%' . $wpdb->esc_like( $q ) . '%';
		$where .= " AND ( p.post_title LIKE %s OR m.meta_value LIKE %s OR p.ID IN ( SELECT v.post_parent FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} vm ON vm.post_id = v.ID AND vm.meta_key = '_sku' AND vm.meta_value LIKE %s WHERE v.post_type = 'product_variation' ) )";
		$args   = array( $like, $like, $like );
	}
	$from  = "FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE {$where}";
	$count = "SELECT COUNT( DISTINCT p.ID ) {$from}";
	$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count, ...$args ) : $count ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Sin lo escrito no hay nada que preparar.
	// Con algo escrito, primero los que empiezan así; luego por nombre.
	$order = '' !== $q ? $wpdb->prepare( '( p.post_title LIKE %s ) DESC, ', $wpdb->esc_like( $q ) . '%' ) : '';
	$sql   = "SELECT p.ID {$from} GROUP BY p.ID ORDER BY {$order}p.post_title ASC, p.ID ASC LIMIT %d OFFSET %d";
	$ids   = $wpdb->get_col( $wpdb->prepare( $sql, ...array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$items = array();
	foreach ( array_map( 'intval', $ids ) as $pid ) {
		$p = wc_get_product( $pid );
		if ( ! $p ) {
			continue;
		}
		$items[] = array(
			'id'         => (int) $pid,
			'name'       => $p->get_name(),
			'sku'        => $p->get_sku( 'edit' ),
			'status'     => $p->get_status(),
			'type'       => $p->get_type(),
			'price'      => (float) $p->get_price(),
			'image'      => $p->get_image_id() ? wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' ) : '',
			'variations' => $p->is_type( 'variable' ) ? count( $p->get_children() ) : 0,
		);
	}
	return array( 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => max( 1, (int) ceil( $total / $per ) ) );
}

/**
 * El modelo talla × color de un producto variable: las tallas y colores de sus atributos y
 * una fila por variación (talla, color, existencias propias o heredadas, precio, imagen).
 * Error si el producto varía por algo que la caja no maneja.
 *
 * @param WC_Product $p         El producto.
 * @param string     $size_tax  pa_talla.
 * @param string     $color_tax pa_color.
 * @return array{sizes: int[], colors: array, variations: array, shared: int|null}|WP_Error
 */
function dox_pos_product_model( $p, $size_tax, $color_tax ) {
	$out = array( 'sizes' => array(), 'colors' => array(), 'variations' => array(), 'shared' => null );
	if ( ! $p->is_type( 'variable' ) ) {
		return $out;
	}
	foreach ( $p->get_attributes() as $a ) {
		if ( ! $a instanceof WC_Product_Attribute || ! $a->get_variation() ) {
			continue;
		}
		$name = $a->get_name();
		if ( $name !== $size_tax && $name !== $color_tax ) {
			/* translators: %s: nombre del atributo */
			return new WP_Error( 'dox_pos_no_editable', sprintf( __( 'This product varies by "%s", which the register does not handle: edit it in WooCommerce.', 'dox-pos' ), wc_attribute_label( $name ) ) );
		}
		if ( $name === $size_tax ) {
			$out['sizes'] = array_map( 'intval', $a->get_options() );
		} else {
			foreach ( $a->get_options() as $tid ) {
				$t = get_term( (int) $tid, $color_tax );
				if ( $t && ! is_wp_error( $t ) ) {
					$out['colors'][] = array( 'key' => (string) $t->term_id, 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), 'hex' => dox_pos_term_hex( $t->term_id ) );
				}
			}
		}
	}
	$size_by_slug  = array();
	$color_by_slug = array();
	foreach ( $out['sizes'] as $tid ) {
		$t = get_term( $tid, $size_tax );
		if ( $t && ! is_wp_error( $t ) ) {
			$size_by_slug[ $t->slug ] = $tid;
		}
	}
	foreach ( $out['colors'] as $c ) {
		$t = get_term( $c['id'], $color_tax );
		if ( $t && ! is_wp_error( $t ) ) {
			$color_by_slug[ $t->slug ] = $c['key'];
		}
	}
	$parent_stock = $p->managing_stock() ? (int) $p->get_stock_quantity() : null;
	$inherits     = false;
	foreach ( $p->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( ! $v || ! $v->is_type( 'variation' ) ) {
			continue;
		}
		$va    = $v->get_attributes(); // taxonomía => slug.
		$sslug = (string) ( $va[ $size_tax ] ?? '' );
		$cslug = (string) ( $va[ $color_tax ] ?? '' );
		if ( ( $out['sizes'] && ! isset( $size_by_slug[ $sslug ] ) ) || ( $out['colors'] && ! isset( $color_by_slug[ $cslug ] ) ) ) {
			return new WP_Error( 'dox_pos_no_editable', __( 'This product has variations for "any" size or color, or with values that no longer exist: edit it in WooCommerce.', 'dox-pos' ) );
		}
		$own = true === $v->get_manage_stock(); // 'parent' = hereda las del producto.
		if ( ! $own && null !== $parent_stock ) {
			$inherits = true;
		}
		$out['variations'][] = array(
			'id'     => (int) $vid,
			'size'   => $out['sizes'] ? (int) $size_by_slug[ $sslug ] : 0,
			'color'  => $out['colors'] ? (string) $color_by_slug[ $cslug ] : '',
			'stock'  => $own ? (int) $v->get_stock_quantity() : null,
			'price'  => (float) $v->get_regular_price( 'edit' ),
			'image'  => (int) $v->get_image_id( 'edit' ), // Sin 'edit' devolvería la del padre.
			'status' => $v->get_status(),
		);
	}
	$out['shared'] = $inherits ? $parent_stock : null;
	return $out;
}

/**
 * La descripción sin HTML, para el cuadro de texto de la caja. Si no se toca, no se guarda.
 *
 * @param string $html Descripción guardada.
 * @return string
 */
function dox_pos_plain_text( $html ) {
	$t = (string) $html;
	$t = preg_replace( '#<br\s*/?>#i', "\n", $t );
	$t = preg_replace( '#</(p|div|li|h[1-6]|tr)>#i', "\n", $t );
	$t = wp_strip_all_tags( $t );
	$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
	$t = preg_replace( "/[ \t]+\n/", "\n", $t );
	$t = preg_replace( "/\n{3,}/", "\n\n", $t );
	return trim( $t );
}

/**
 * Lo que la caja necesita para editar un producto: datos, tallas, colores, unidades y fotos.
 *
 * @param int $id Producto.
 * @return array|WP_Error
 */
function dox_pos_product_edit_data( $id ) {
	$p = wc_get_product( (int) $id );
	if ( ! $p || ! in_array( $p->get_type(), array( 'simple', 'variable' ), true ) ) {
		return new WP_Error( 'dox_pos_no_editable', __( 'That product cannot be edited from the register (only simple products and those with sizes and colors).', 'dox-pos' ) );
	}
	$s     = dox_pos_products_settings();
	$model = dox_pos_product_model( $p, $s['size_attr'], $s['color_attr'] );
	if ( is_wp_error( $model ) ) {
		return $model;
	}
	$images = array();
	$ids    = array_merge( $p->get_image_id() ? array( (int) $p->get_image_id() ) : array(), array_map( 'intval', $p->get_gallery_image_ids() ) );
	foreach ( array_values( array_unique( $ids ) ) as $iid ) {
		if ( ! wp_attachment_is_image( $iid ) ) {
			continue;
		}
		$images[] = array( 'id' => $iid, 'url' => wp_get_attachment_image_url( $iid, 'woocommerce_thumbnail' ), 'color' => '' );
	}
	// Qué foto va con cada color: la que tienen sus variaciones.
	foreach ( $model['variations'] as $v ) {
		if ( '' === $v['color'] || ! $v['image'] ) {
			continue;
		}
		foreach ( $images as &$im ) {
			if ( $im['id'] === $v['image'] && '' === $im['color'] ) {
				$im['color'] = $v['color'];
				break;
			}
		}
		unset( $im );
	}
	$prices = array();
	$qty    = array();
	$units  = 0;
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $model['variations'] as $v ) {
			$prices[] = (float) $v['price'];
			if ( null !== $v['stock'] ) {
				$qty[ $v['color'] ][ (string) $v['size'] ] = $v['stock'];
				$units += $v['stock'];
			}
		}
		if ( null !== $model['shared'] ) {
			$units = (int) $model['shared'];
		}
	} else {
		$prices[] = (float) $p->get_regular_price( 'edit' );
		if ( $p->managing_stock() ) {
			$qty['']['0'] = (int) $p->get_stock_quantity();
			$units        = (int) $p->get_stock_quantity();
		}
	}
	$prices = array_values( array_unique( array_filter( $prices, fn( $x ) => $x > 0 ) ) );
	$cs     = dox_pos_can_see_costs() ? dox_pos_product_cost_summary( $p ) : null;
	return array(
		'id'          => $p->get_id(),
		'name'        => $p->get_name(),
		'sku'         => $p->get_sku( 'edit' ),
		'status'      => $p->get_status(),
		'type'        => $p->get_type(),
		'url'         => get_permalink( $p->get_id() ),
		'categories'  => array_map( 'intval', $p->get_category_ids() ),
		'description' => dox_pos_plain_text( $p->get_description( 'edit' ) ),
		'price'       => 1 === count( $prices ) ? $prices[0] : '', // Vacío: las tallas tienen precios distintos.
		'price_min'   => $prices ? min( $prices ) : 0,
		'price_max'   => $prices ? max( $prices ) : 0,
		'cost'        => $cs ? $cs['cost'] : null, // Uno solo; '' si las tallas cuestan distinto; null sin costo (o sin permiso para verlo).
		'cost_min'    => $cs ? $cs['min'] : 0,
		'cost_max'    => $cs ? $cs['max'] : 0,
		'sizes'       => $model['sizes'],
		'colors'      => $model['colors'],
		'qty'         => (object) $qty,
		'shared'      => $model['shared'], // Existencias en conjunto (null si cada talla lleva las suyas).
		'images'      => $images,
		'variations'  => count( $model['variations'] ),
		'units'       => $units,
	);
}

/**
 * El precio más repetido entre las variaciones (para una talla nueva cuando no se escribe precio).
 *
 * @param array $variations Filas del modelo.
 * @return string
 */
function dox_pos_common_price( $variations ) {
	$count = array();
	foreach ( $variations as $v ) {
		$k = (string) $v['price'];
		if ( (float) $k > 0 ) {
			$count[ $k ] = ( $count[ $k ] ?? 0 ) + 1;
		}
	}
	if ( ! $count ) {
		return '';
	}
	arsort( $count );
	return (string) array_key_first( $count );
}

/**
 * Guarda los cambios de un producto: nombre, categorías, precio, descripción, publicado u
 * oculto, fotos (y cuál va con cada color), unidades, y tallas o colores nuevos. Las tallas
 * y colores que ya tenía no se quitan desde aquí (sería borrar variaciones).
 *
 * @param int   $id   Producto.
 * @param array $data Igual que al crear: name, price ('' = no tocar), cost ('' = no tocar, 0 = quitarlo),
 *                    categories, description, publish, sizes, colors, qty, images.
 * @return array|WP_Error
 */
function dox_pos_update_product( $id, $data ) {
	$p = wc_get_product( (int) $id );
	if ( ! $p || ! in_array( $p->get_type(), array( 'simple', 'variable' ), true ) ) {
		return new WP_Error( 'dox_pos_no_editable', __( 'That product cannot be edited from the register.', 'dox-pos' ) );
	}
	$s         = dox_pos_products_settings();
	$size_tax  = $s['size_attr'];
	$color_tax = $s['color_attr'];
	$model     = dox_pos_product_model( $p, $size_tax, $color_tax );
	if ( is_wp_error( $model ) ) {
		return $model;
	}
	$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
	if ( '' === $name ) {
		return new WP_Error( 'dox_pos_sin_nombre', __( 'Give the product a name.', 'dox-pos' ) );
	}
	$price = wc_format_decimal( (string) ( $data['price'] ?? '' ) );
	if ( '' !== $price && (float) $price <= 0 ) {
		return new WP_Error( 'dox_pos_sin_precio', __( 'The price has to be greater than zero.', 'dox-pos' ) );
	}
	// El costo: '' o ausente = no tocar; 0 = quitarlo; más = ponerlo a todo el producto (a todas las tallas).
	$cost = null;
	if ( dox_pos_can_see_costs() && isset( $data['cost'] ) && '' !== $data['cost'] ) {
		$cost = dox_pos_parse_money( $data['cost'] );
		$cost = null === $cost ? null : max( 0.0, $cost );
	}
	$cats = array();
	foreach ( (array) ( $data['categories'] ?? array() ) as $cid ) {
		if ( (int) $cid && term_exists( (int) $cid, 'product_cat' ) ) {
			$cats[] = (int) $cid;
		}
	}
	if ( ! $cats ) {
		return new WP_Error( 'dox_pos_sin_categoria', __( 'Choose at least one category.', 'dox-pos' ) );
	}
	$variable = $p->is_type( 'variable' );

	// Tallas y colores: los que ya tenía, más los nuevos. A un producto que no varía por color
	// (o por talla) no se le añaden desde aquí: sus variaciones quedarían para "cualquier" color.
	$sizes  = array();
	$colors = array();
	if ( $variable ) {
		foreach ( $model['sizes'] as $tid ) {
			$t = get_term( $tid, $size_tax );
			if ( $t && ! is_wp_error( $t ) ) {
				$sizes[ $tid ] = $t;
			}
		}
		foreach ( $model['colors'] as $c ) {
			$t = get_term( $c['id'], $color_tax );
			if ( $t && ! is_wp_error( $t ) ) {
				$colors[ $c['key'] ] = $t;
			}
		}
		if ( $size_tax && $model['sizes'] ) {
			foreach ( (array) ( $data['sizes'] ?? array() ) as $sid ) {
				$t = get_term( (int) $sid, $size_tax );
				if ( $t && ! is_wp_error( $t ) ) {
					$sizes[ (int) $t->term_id ] = $t;
				}
			}
		}
		if ( $color_tax && $model['colors'] ) {
			foreach ( (array) ( $data['colors'] ?? array() ) as $c ) {
				$c     = (array) $c;
				$key   = sanitize_text_field( (string) ( $c['key'] ?? '' ) );
				$cid   = (int) ( $c['id'] ?? 0 );
				$cname = sanitize_text_field( (string) ( $c['name'] ?? '' ) );
				if ( $cid ) {
					$t = get_term( $cid, $color_tax );
				} elseif ( '' !== $cname ) {
					$t = dox_pos_create_color( $cname, (string) ( $c['hex'] ?? '' ), $color_tax );
					if ( is_wp_error( $t ) ) {
						return $t;
					}
				} else {
					continue;
				}
				if ( $t && ! is_wp_error( $t ) ) {
					$colors[ '' !== $key ? $key : (string) $t->term_id ] = $t;
				}
			}
		}
	}
	$qty    = (array) ( $data['qty'] ?? array() );
	$images = array();
	foreach ( (array) ( $data['images'] ?? array() ) as $im ) {
		$im  = (array) $im;
		$iid = (int) ( $im['id'] ?? 0 );
		if ( $iid && 'attachment' === get_post_type( $iid ) && wp_attachment_is_image( $iid ) ) {
			$images[] = array( 'id' => $iid, 'color' => sanitize_text_field( (string) ( $im['color'] ?? '' ) ) );
		}
	}
	$before = array_merge( $p->get_image_id() ? array( (int) $p->get_image_id() ) : array(), array_map( 'intval', $p->get_gallery_image_ids() ) );

	// Los datos.
	$p->set_name( $name );
	$p->set_category_ids( $cats );
	$p->set_status( empty( $data['publish'] ) ? 'private' : 'publish' );
	$desc = (string) ( $data['description'] ?? '' );
	if ( trim( $desc ) !== dox_pos_plain_text( $p->get_description( 'edit' ) ) ) {
		$p->set_description( wp_kses_post( $desc ) ); // Solo si se tocó: así no se pierde el HTML de una descripción hecha en WooCommerce.
	}
	// Fotos: la principal es la primera "para todas"; el resto va a la galería; las que se quitaron
	// se sueltan del producto (el archivo sigue en la biblioteca de medios).
	$main     = 0;
	$gallery  = array();
	$by_color = array();
	foreach ( $images as $im ) {
		if ( ! $main && '' === $im['color'] ) {
			$main = $im['id'];
			continue;
		}
		if ( '' !== $im['color'] && ! isset( $by_color[ $im['color'] ] ) ) {
			$by_color[ $im['color'] ] = $im['id'];
		}
		$gallery[] = $im['id'];
	}
	if ( ! $main && $images ) {
		$main    = $images[0]['id'];
		$gallery = array_values( array_diff( $gallery, array( $main ) ) );
	}
	$p->set_image_id( $main );
	$p->set_gallery_image_ids( $gallery );
	$ctx = dox_pos_stock_context( 'edit' ); // El kardex: "Editado en la caja".

	if ( ! $variable ) {
		if ( '' !== $price ) {
			$p->set_regular_price( $price );
		}
		if ( $p->managing_stock() && isset( $qty[''] ) && is_array( $qty[''] ) && array_key_exists( '0', $qty[''] ) ) {
			$n = max( 0, (int) $qty['']['0'] );
			if ( $n !== (int) $p->get_stock_quantity() ) {
				$p->set_stock_quantity( $n );
				$p->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
			}
		}
	} else {
		$new_sizes  = array_values( array_diff( array_keys( $sizes ), $model['sizes'] ) );
		$new_colors = array_values( array_diff( array_keys( $colors ), array_column( $model['colors'], 'key' ) ) );
		if ( $new_sizes || $new_colors ) {
			$attrs = $p->get_attributes();
			if ( $new_sizes && isset( $attrs[ $size_tax ] ) ) {
				$attrs[ $size_tax ]->set_options( array_values( array_unique( array_merge( array_map( 'intval', $attrs[ $size_tax ]->get_options() ), $new_sizes ) ) ) );
			}
			if ( $new_colors && isset( $attrs[ $color_tax ] ) ) {
				$attrs[ $color_tax ]->set_options( array_values( array_unique( array_merge( array_map( 'intval', $attrs[ $color_tax ]->get_options() ), array_map( fn( $k ) => (int) $colors[ $k ]->term_id, $new_colors ) ) ) ) );
			}
			$p->set_attributes( $attrs );
		}
	}
	if ( null !== $cost ) {
		$p->set_cogs_value( $cost > 0 ? round( $cost, 2 ) : null );
	}
	try {
		$pid = $p->save();
	} catch ( Exception $e ) {
		dox_pos_stock_context_end( $ctx );
		/* translators: %s: motivo */
		return new WP_Error( 'dox_pos_no_se_pudo', sprintf( __( 'WooCommerce would not save the product: %s', 'dox-pos' ), $e->getMessage() ) );
	}

	$nvars = count( $model['variations'] );
	if ( $variable ) {
		if ( $colors ) {
			wp_set_object_terms( $pid, array_map( fn( $t ) => (int) $t->term_id, array_values( $colors ) ), $color_tax, false );
		}
		if ( $sizes ) {
			wp_set_object_terms( $pid, array_map( 'intval', array_keys( $sizes ) ), $size_tax, false );
		}
		// Las variaciones que ya existen: precio, unidades e imagen del color.
		$known = array_values( array_unique( array_merge( $before, array_column( $images, 'id' ) ) ) );
		foreach ( $model['variations'] as $vr ) {
			$v = wc_get_product( $vr['id'] );
			if ( ! $v ) {
				continue;
			}
			$changed = false;
			if ( '' !== $price && (float) $vr['price'] !== (float) $price ) {
				$v->set_regular_price( $price );
				$changed = true;
			}
			if ( null === $model['shared'] && null !== $vr['stock'] ) {
				$row = $qty[ $vr['color'] ] ?? null;
				if ( is_array( $row ) && array_key_exists( (string) $vr['size'], $row ) ) {
					$n = max( 0, (int) $row[ (string) $vr['size'] ] );
					if ( $n !== $vr['stock'] ) {
						$v->set_stock_quantity( $n );
						$v->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
						$changed = true;
					}
				}
			}
			if ( '' !== $vr['color'] ) {
				$want = $by_color[ $vr['color'] ] ?? 0;
				if ( $want && $want !== $vr['image'] ) {
					$v->set_image_id( $want );
					$changed = true;
				} elseif ( ! $want && $vr['image'] && in_array( $vr['image'], $known, true ) ) {
					$v->set_image_id( 0 ); // Se le quitó la foto a ese color: vuelve a la principal.
					$changed = true;
				}
			}
			if ( $changed ) {
				$v->save();
			}
		}
		// Las combinaciones nuevas.
		$existing = array();
		foreach ( $model['variations'] as $vr ) {
			$existing[ $vr['color'] . '|' . $vr['size'] ] = true;
		}
		$base_price = '' !== $price ? $price : dox_pos_common_price( $model['variations'] );
		$parent_sku = $p->get_sku( 'edit' );
		$color_list = $colors ? $colors : array( '' => null );
		$size_list  = $sizes ? $sizes : array( 0 => null );
		foreach ( $color_list as $ckey => $cterm ) {
			foreach ( $size_list as $sid => $sterm ) {
				if ( isset( $existing[ $ckey . '|' . $sid ] ) ) {
					continue;
				}
				$v = new WC_Product_Variation();
				$v->set_parent_id( $pid );
				$v->set_status( 'publish' );
				$va = array();
				if ( $cterm ) {
					$va[ $color_tax ] = $cterm->slug;
				}
				if ( $sterm ) {
					$va[ $size_tax ] = $sterm->slug;
				}
				$v->set_attributes( $va );
				$vsku = dox_pos_variation_sku( $s['sku'], $parent_sku, $sterm, $cterm );
				if ( '' !== $vsku ) {
					try {
						$v->set_sku( $vsku );
					} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Ese código ya existía: la variación se queda sin código.
					}
				}
				if ( '' !== $base_price ) {
					$v->set_regular_price( $base_price );
				}
				if ( null === $model['shared'] ) {
					$n = dox_pos_qty_at( $qty, (string) $ckey, (string) $sid );
					$v->set_manage_stock( true );
					$v->set_stock_quantity( $n );
					$v->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
				} else {
					$v->set_manage_stock( false ); // Hereda las existencias en conjunto del producto.
				}
				if ( $cterm && isset( $by_color[ $ckey ] ) ) {
					$v->set_image_id( $by_color[ $ckey ] );
				}
				$v->save();
				$nvars++;
			}
		}
		if ( null !== $cost ) {
			// El costo escrito vale para todas las tallas: la que tenía uno propio lo suelta y hereda el del producto.
			foreach ( $model['variations'] as $vr ) {
				$v = wc_get_product( $vr['id'] );
				if ( $v && null !== $v->get_cogs_value() ) {
					dox_pos_set_product_cost( $v, null );
				}
			}
		}
		WC_Product_Variable::sync( $pid );
		wc_delete_product_transients( $pid );
	}
	dox_pos_stock_context_end( $ctx );
	foreach ( $images as $im ) {
		delete_post_meta( $im['id'], '_dox_pos_pending' );
		if ( (int) get_post_field( 'post_parent', $im['id'] ) !== (int) $pid ) {
			wp_update_post( array( 'ID' => $im['id'], 'post_parent' => $pid ) );
		}
	}
	delete_transient( 'dox_pos_product_form' );
	do_action( 'litespeed_purge_post', $pid );
	return dox_pos_product_response( wc_get_product( $pid ), null, $nvars );
}
