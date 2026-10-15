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
 * size_attrs son los atributos de talla (una tienda puede tener Talla en la ropa y Talla calzado en
 * los zapatos), el más usado primero; size_attr es ese primero, el de siempre para quien solo tiene uno.
 * cat_size dice el de cada categoría que se fijó a mano ("-": esa categoría no lleva tallas).
 * cat_vary (0.57) dice por qué varían los productos de cada categoría fijada a mano: hasta dos atributos
 * cualesquiera (Capacidad y Color en los termos), o ninguno.
 *
 * @param bool $reset Volver a leer la opción (después de cambiarla en la misma petición).
 * @return array{quality:int,max_px:int,sku:string,size_attrs:string[],size_attr:string,cat_size:array<int,string>,cat_vary:array<int,string[]>,color_attr:string,costs:bool}
 */
function dox_pos_products_settings( $reset = false ) {
	static $cache = null;
	if ( null !== $cache && ! $reset ) {
		return $cache;
	}
	$s   = get_option( 'dox_pos_products', array() );
	$s   = is_array( $s ) ? $s : array();
	$sku = (string) ( $s['sku'] ?? '' );
	$sizes = dox_pos_size_attributes_from( $s );
	$cats  = array();
	foreach ( (array) ( $s['cat_size'] ?? array() ) as $cid => $tax ) {
		if ( (int) $cid > 0 && ( '-' === $tax || in_array( $tax, $sizes, true ) ) ) {
			$cats[ (int) $cid ] = (string) $tax;
		}
	}
	$all  = dox_pos_attribute_taxonomies();
	$vary = array();
	foreach ( (array) ( $s['cat_vary'] ?? array() ) as $cid => $set ) {
		$set = dox_pos_clean_vary( $set, $all );
		if ( (int) $cid > 0 && null !== $set ) {
			$vary[ (int) $cid ] = $set;
		}
	}
	$cache = array(
		'quality'    => isset( $s['quality'] ) && (int) $s['quality'] >= 50 ? min( 100, (int) $s['quality'] ) : 88,
		'max_px'     => isset( $s['max_px'] ) && (int) $s['max_px'] >= 800 ? min( 4000, (int) $s['max_px'] ) : 1600,
		'sku'        => in_array( $sku, array( 'codes', 'slugs', 'none' ), true ) ? $sku : 'codes',
		'size_attrs' => $sizes,
		'size_attr'  => $sizes ? $sizes[0] : '',
		'cat_size'   => $cats,
		'cat_vary'   => $vary,
		'color_attr' => dox_pos_attribute_taxonomy( (string) ( $s['color_attr'] ?? '' ), dox_pos_attribute_names( 'color' ) ),
		'costs'      => ! isset( $s['costs'] ) || ! empty( $s['costs'] ), // De fábrica se llevan costos; se apaga en Ajustes > Productos.
		'real_near'  => max( 0, min( 9, (int) ( $s['real_near'] ?? 0 ) ) ), // 0: apagado. Si no, a cuántas tallas puede estar la prenda real (includes/real-sizes.php).
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
 * Un "Varía por" limpio: hasta dos atributos de la tienda, sin repetir. Array vacío = no varía (producto
 * de talla única); null = no vale nada de lo que llegó.
 *
 * @param mixed                     $set Lo guardado: lista de taxonomías, o array( '-' ).
 * @param array<string,string>|null $all Los atributos de la tienda (null: se leen).
 * @return string[]|null
 */
function dox_pos_clean_vary( $set, $all = null ) {
	$all = null === $all ? dox_pos_attribute_taxonomies() : $all;
	$set = array_values( array_map( 'strval', (array) $set ) );
	if ( array( '-' ) === $set ) {
		return array();
	}
	$out = array();
	foreach ( $set as $tax ) {
		if ( isset( $all[ $tax ] ) && ! in_array( $tax, $out, true ) && count( $out ) < 2 ) {
			$out[] = $tax;
		}
	}
	return $out ? $out : null;
}

/**
 * Los nombres con los que se reconoce el atributo de talla o el de color.
 *
 * @param string $kind "size" o "color".
 * @return string[]
 */
function dox_pos_attribute_names( $kind ) {
	return 'size' === $kind ? array( 'talla', 'tallas', 'talle', 'size', 'sizes' ) : array( 'color', 'colores', 'colour', 'colors' );
}

/**
 * Los atributos que parecen el que se busca, el más usado primero: los que se llaman como uno
 * de los nombres o lo llevan delante o detrás ("talla-calzado", "shoe-size"). Una tienda puede
 * tener "Talla" vacía y "Talla calzado" en todos sus zapatos; se queda la que tiene productos.
 *
 * @param string[] $names Nombres que valen (en minúsculas, sin tildes).
 * @return string[] Taxonomías, de la más usada a la menos; a igual uso, la de nombre exacto.
 */
function dox_pos_attribute_candidates( $names ) {
	$found = array();
	foreach ( dox_pos_attribute_taxonomies() as $tax => $label ) {
		$exact = false;
		$near  = false;
		foreach ( array( substr( $tax, 3 ), sanitize_title( remove_accents( $label ) ) ) as $slug ) {
			foreach ( $names as $n ) {
				$exact = $exact || $slug === $n;
				$near  = $near || 0 === strpos( $slug, $n . '-' ) || substr( $slug, -strlen( $n ) - 1 ) === '-' . $n;
			}
		}
		if ( $exact || $near ) {
			$found[ $tax ] = $exact;
		}
	}
	return dox_pos_attributes_by_use( array_keys( $found ), $found );
}

/**
 * Atributos ordenados por uso: el que más productos tiene primero (la suma del count de sus
 * términos, en una sola consulta); a igual uso, el de nombre exacto y luego el orden de llegada.
 *
 * @param string[]           $taxes Taxonomías.
 * @param array<string,bool> $exact Cuáles se llaman exactamente como se buscaba.
 * @return string[]
 */
function dox_pos_attributes_by_use( $taxes, $exact = array() ) {
	$taxes = array_values( array_unique( $taxes ) );
	if ( count( $taxes ) < 2 ) {
		return $taxes;
	}
	$uses  = dox_pos_attribute_uses( $taxes );
	$order = array_flip( $taxes );
	usort(
		$taxes,
		static function ( $a, $b ) use ( $uses, $exact, $order ) {
			$na = $uses[ $a ] ?? 0;
			$nb = $uses[ $b ] ?? 0;
			if ( $na !== $nb ) {
				return $nb - $na;
			}
			$ea = ! empty( $exact[ $a ] );
			$eb = ! empty( $exact[ $b ] );
			if ( $ea !== $eb ) {
				return $ea ? -1 : 1;
			}
			return $order[ $a ] - $order[ $b ];
		}
	);
	return $taxes;
}

/**
 * Cuánto se usa cada atributo: la suma del count de sus términos, en una sola consulta.
 *
 * @param string[] $taxes Taxonomías.
 * @return array<string,int>
 */
function dox_pos_attribute_uses( $taxes ) {
	global $wpdb;
	if ( ! $taxes ) {
		return array();
	}
	$in   = implode( ',', array_fill( 0, count( $taxes ), '%s' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT taxonomy, SUM( count ) n FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ( $in ) GROUP BY taxonomy", array_values( $taxes ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	return array_map( 'intval', array_column( (array) $rows, 'n', 'taxonomy' ) );
}

/**
 * Los atributos de talla según lo guardado: los marcados en el ajuste (o el único de antes de la
 * 0.56, size_attr) o, sin ninguno, todos los que parecen de talla. El más usado primero.
 *
 * @param array $s La opción dox_pos_products tal cual.
 * @return string[]
 */
function dox_pos_size_attributes_from( $s ) {
	$all = dox_pos_attribute_taxonomies();
	$set = array();
	if ( array( '-' ) === array_values( (array) ( $s['size_attrs'] ?? array() ) ) ) {
		return array(); // Se apagaron todos a mano: la tienda no usa tallas.
	}
	foreach ( (array) ( $s['size_attrs'] ?? array() ) as $tax ) {
		if ( isset( $all[ $tax ] ) ) {
			$set[] = (string) $tax;
		}
	}
	if ( ! $set && ! empty( $s['size_attr'] ) && isset( $all[ $s['size_attr'] ] ) ) {
		$set[] = (string) $s['size_attr'];
	}
	return $set ? dox_pos_attributes_by_use( $set ) : dox_pos_attribute_candidates( dox_pos_attribute_names( 'size' ) );
}

/**
 * La taxonomía de un atributo ("pa_talla"): la del ajuste si existe, o la más usada de las que
 * parecen ese atributo (dox_pos_attribute_candidates). Vacío si la tienda no tiene ninguna así.
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
	$found = dox_pos_attribute_candidates( $names );
	return $found ? $found[0] : '';
}

/**
 * El atributo de talla de siempre: el más usado de los de talla. Para lo que no sabe de qué
 * producto se trata; lo que sí lo sabe usa dox_pos_product_size_attribute().
 *
 * @return string
 */
function dox_pos_size_attribute() {
	return dox_pos_products_settings()['size_attr'];
}

/**
 * Todos los atributos de talla de la tienda, el más usado primero.
 *
 * @return string[]
 */
function dox_pos_size_attributes() {
	return dox_pos_products_settings()['size_attrs'];
}

/**
 * Si un atributo es de talla (pa_talla, pa_talla-calzado...).
 *
 * @param string $tax Taxonomía.
 * @return bool
 */
function dox_pos_is_size_attribute( $tax ) {
	return '' !== (string) $tax && in_array( (string) $tax, dox_pos_size_attributes(), true );
}

/**
 * El atributo de talla de una variación (el que tiene puesto) y su valor.
 *
 * @param WC_Product|null $v Variación.
 * @return array{0:string,1:string} [taxonomía, slug], vacíos si no lleva talla.
 */
function dox_pos_variation_size( $v ) {
	if ( $v instanceof WC_Product ) {
		foreach ( (array) $v->get_attributes() as $tax => $slug ) {
			if ( is_string( $slug ) && '' !== $slug && dox_pos_is_size_attribute( $tax ) ) {
				return array( (string) $tax, $slug );
			}
		}
	}
	return array( '', '' );
}

/**
 * El árbol de categorías: term_id => madre. Se lee una vez por petición.
 *
 * @return array<int,int>
 */
function dox_pos_category_parents() {
	static $terms = null;
	static $when  = null;
	$now = wp_cache_get_last_changed( 'terms' ); // Una categoría creada en esta misma petición también cuenta.
	if ( null === $terms || $when !== $now ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'id=>parent' ) );
		$terms = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
		$when  = $now;
	}
	return $terms;
}

/**
 * El atributo de talla que usan los productos de cada categoría (con lo de sus subcategorías), o '' si tiene
 * productos y ninguno con tallas. Solo con más de un atributo de talla: con uno no hace falta mirar.
 *
 * @return array<int,string>
 */
function dox_pos_category_size_found() {
	static $found = null;
	if ( null !== $found ) {
		return $found;
	}
	$found = array();
	$taxes = dox_pos_size_attributes();
	$terms = dox_pos_category_parents();
	if ( count( $taxes ) < 2 ) {
		return $found;
	}
	global $wpdb;
	$keys = array_map( fn( $t ) => 'attribute_' . $t, $taxes );
	$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT x.term_id, t.meta_key k, COUNT( DISTINCT v.post_parent ) n FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} t ON t.post_id = v.ID AND t.meta_key IN ( $in ) AND t.meta_value <> '' JOIN {$wpdb->term_relationships} tr ON tr.object_id = v.post_parent JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = tr.term_taxonomy_id AND x.taxonomy = 'product_cat' WHERE v.post_type = 'product_variation' AND v.post_status IN ( 'publish', 'private' ) GROUP BY x.term_id, t.meta_key", $keys ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	// Lo de cada subcategoría cuenta también para sus madres: Zapatos sin productos propios, pero con
	// los tenis en Zapatos > Tenis, es de Talla calzado.
	$uses = array();
	foreach ( (array) $rows as $r ) {
		$x = (int) $r['term_id'];
		$n = 0;
		while ( $x && $n++ < 10 ) {
			$uses[ $x ][ substr( $r['k'], 10 ) ] = ( $uses[ $x ][ substr( $r['k'], 10 ) ] ?? 0 ) + (int) $r['n'];
			$x = (int) ( $terms[ $x ] ?? 0 );
		}
	}
	foreach ( $uses as $tid => $by ) {
		uksort( $by, fn( $a, $b ) => array_search( $a, $taxes, true ) - array_search( $b, $taxes, true ) ); // A igual uso, el más usado de la tienda.
		arsort( $by );
		$found[ $tid ] = (string) array_key_first( $by );
	}
	// Una categoría con productos y ninguno con tallas (Carteras) no lleva tallas: sin esto le tocaría el
	// tipo más usado de la tienda (calzado). Cuenta lo de sus subcategorías; una vacía hereda de su madre.
	$counts = $wpdb->get_results( "SELECT term_id, count FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat' AND count > 0", ARRAY_A );
	foreach ( (array) $counts as $r ) {
		$x = (int) $r['term_id'];
		$n = 0;
		while ( $x && $n++ < 10 ) {
			if ( ! isset( $found[ $x ] ) ) {
				$found[ $x ] = '';
			}
			$x = (int) ( $terms[ $x ] ?? 0 );
		}
	}
	return $found;
}

/**
 * La columna de una variación (su color, o su material si varía por Talla y Material): el valor que no es su
 * talla. Las unidades compartidas van por columna (includes/pools.php).
 *
 * @param WC_Product|null $v Variación.
 * @return array{0:string,1:string} [taxonomía, slug], vacíos si no tiene.
 */
function dox_pos_variation_column( $v ) {
	if ( ! $v instanceof WC_Product ) {
		return array( '', '' );
	}
	list( $stax ) = dox_pos_variation_size( $v );
	$other        = array( '', '' );
	foreach ( (array) $v->get_attributes() as $tax => $slug ) {
		if ( ! is_string( $slug ) || '' === $slug || $tax === $stax ) {
			continue;
		}
		if ( dox_pos_is_color_attribute( $tax ) ) {
			return array( (string) $tax, $slug );
		}
		if ( '' === $other[0] ) {
			$other = array( (string) $tax, $slug );
		}
	}
	return $other;
}

/**
 * El atributo de talla de cada categoría: el fijado en Ajustes > Productos, si no el que usan sus
 * productos, si no el de su categoría madre, y si nada, el de siempre. Vacío: no lleva tallas.
 *
 * @param array|null $set Lo fijado a mano (null: lo del ajuste). Ajustes lo pasa sin una categoría para saber qué le tocaría sola.
 * @return array<int,string> term_id => taxonomía.
 */
function dox_pos_category_size_map( $set = null ) {
	$taxes = dox_pos_size_attributes();
	if ( ! $taxes ) {
		return array();
	}
	$terms = dox_pos_category_parents();
	$found = dox_pos_category_size_found();
	$set   = null === $set ? dox_pos_products_settings()['cat_size'] : (array) $set;
	$map   = array();
	foreach ( $terms as $tid => $parent ) {
		$x   = (int) $tid;
		$tax = null;
		$n   = 0;
		while ( $x && $n++ < 10 ) {
			if ( isset( $set[ $x ] ) ) {
				$tax = '-' === $set[ $x ] ? '' : $set[ $x ];
				break;
			}
			if ( isset( $found[ $x ] ) ) {
				$tax = $found[ $x ];
				break;
			}
			$x = (int) ( $terms[ $x ] ?? 0 );
		}
		$map[ (int) $tid ] = null === $tax ? $taxes[0] : $tax;
	}
	return $map;
}

/**
 * Por qué varían los productos de cada categoría ("Varía por"): hasta dos atributos, en orden. El primero
 * va en las filas del formulario y el segundo en las columnas. Subiendo por las categorías madre, manda la
 * primera que tenga algo: un "Varía por" fijado (0.57), o la talla de la 0.56 (fijada en Tallas por
 * categoría o la que usan sus productos) más el color. Sin nada, la talla de siempre y el color: una tienda
 * con solo Talla y Color sigue exactamente igual.
 *
 * @param array|null $vary Los "Varía por" fijados (null: los del ajuste). Ajustes lo pasa sin una categoría para saber qué le tocaría sola.
 * @param array|null $size Las tallas fijadas a mano en la 0.56 (null: las del ajuste).
 * @return array<int,string[]> term_id => taxonomías (0, 1 o 2).
 */
function dox_pos_category_vary_map( $vary = null, $size = null ) {
	$s     = dox_pos_products_settings();
	$vary  = null === $vary ? $s['cat_vary'] : (array) $vary;
	$size  = null === $size ? $s['cat_size'] : (array) $size;
	$terms = dox_pos_category_parents();
	$found = dox_pos_category_size_found();
	$smap  = dox_pos_category_size_map( $size );
	$color = $s['color_attr'];
	$def   = array_values( array_filter( array( $s['size_attr'], $color ) ) );
	$map   = array();
	foreach ( $terms as $tid => $parent ) {
		$x   = (int) $tid;
		$out = null;
		$n   = 0;
		while ( $x && $n++ < 10 ) {
			if ( isset( $vary[ $x ] ) ) {
				$out = (array) $vary[ $x ];
				break;
			}
			if ( isset( $size[ $x ] ) || isset( $found[ $x ] ) ) {
				// Lo de la 0.56: la talla de esa categoría (la misma que le da el mapa de tallas) y el color.
				$out = array_values( array_unique( array_filter( array( $smap[ (int) $tid ] ?? '', $color ) ) ) );
				break;
			}
			$x = (int) ( $terms[ $x ] ?? 0 );
		}
		$map[ (int) $tid ] = null === $out ? $def : $out;
	}
	return $map;
}

/**
 * Los atributos que son colores: el del ajuste y los que se llaman como un color ("Color", "Color tapa").
 * Sus valores llevan muestra de color en la caja.
 *
 * @return string[]
 */
function dox_pos_color_attributes() {
	static $out = null;
	if ( null === $out ) {
		$out = dox_pos_attribute_candidates( dox_pos_attribute_names( 'color' ) );
		$set = dox_pos_products_settings()['color_attr'];
		if ( $set && ! in_array( $set, $out, true ) ) {
			array_unshift( $out, $set );
		}
	}
	return $out;
}

/**
 * Si un atributo es de color (sus valores llevan muestra).
 *
 * @param string $tax Taxonomía.
 * @return bool
 */
function dox_pos_is_color_attribute( $tax ) {
	return '' !== (string) $tax && in_array( (string) $tax, dox_pos_color_attributes(), true );
}

/**
 * Dónde va cada opción en el formulario: la primera en las filas y la segunda en las columnas. Con una sola,
 * un color va en las columnas (como un producto que solo varía por color) y lo demás en las filas.
 *
 * @param string[] $set Las opciones, en orden.
 * @return array{row:string,col:string}
 */
function dox_pos_vary_axes( $set ) {
	$set = array_values( array_filter( array_map( 'strval', (array) $set ) ) );
	if ( count( $set ) >= 2 ) {
		return array( 'row' => $set[0], 'col' => $set[1] );
	}
	if ( ! $set ) {
		return array( 'row' => '', 'col' => '' );
	}
	return dox_pos_is_color_attribute( $set[0] ) ? array( 'row' => '', 'col' => $set[0] ) : array( 'row' => $set[0], 'col' => '' );
}

/**
 * El "Varía por" de una categoría (o de la primera de un producto que tenga una).
 *
 * @param int[] $cats Categorías.
 * @return string[]
 */
function dox_pos_categories_vary( $cats ) {
	$map = dox_pos_category_vary_map();
	foreach ( (array) $cats as $cid ) {
		if ( isset( $map[ (int) $cid ] ) ) {
			return $map[ (int) $cid ];
		}
	}
	$s = dox_pos_products_settings();
	return array_values( array_filter( array( $s['size_attr'], $s['color_attr'] ) ) );
}

/**
 * Por qué varía un producto que ya existe: los atributos de sus variaciones, colocados en filas y columnas.
 * Si son los mismos que los de su categoría, en el orden de la categoría; si no, el color en las columnas y
 * una talla en las filas. Sin variaciones todavía, lo de su categoría. Error si varía por más de dos cosas o
 * por un atributo propio del producto (no global): eso se edita en WooCommerce.
 *
 * @param WC_Product $p Producto (o variación: se mira el padre).
 * @return array{row:string,col:string}|WP_Error
 */
function dox_pos_product_axes( $p ) {
	if ( $p instanceof WC_Product && $p->is_type( 'variation' ) ) {
		$p = wc_get_product( $p->get_parent_id() );
	}
	if ( ! $p instanceof WC_Product ) {
		return array( 'row' => '', 'col' => '' );
	}
	$cat = dox_pos_categories_vary( $p->get_category_ids() );
	if ( ! $p->is_type( 'variable' ) ) {
		return dox_pos_vary_axes( $cat );
	}
	$mine = array();
	foreach ( $p->get_attributes() as $a ) {
		if ( ! $a instanceof WC_Product_Attribute || ! $a->get_variation() ) {
			continue;
		}
		if ( ! $a->is_taxonomy() ) {
			/* translators: %s: attribute name */
			return new WP_Error( 'dox_pos_no_editable', sprintf( __( 'This product varies by "%s", which the register does not handle: edit it in WooCommerce.', 'dox-pos' ), $a->get_name() ) );
		}
		$mine[] = $a->get_name();
	}
	if ( count( $mine ) > 2 ) {
		/* translators: %s: list of attribute names */
		return new WP_Error( 'dox_pos_no_editable', sprintf( __( 'This product varies by %s: the register edits products that vary by up to two things. Edit it in WooCommerce; it still sells from the register.', 'dox-pos' ), implode( ', ', array_map( 'wc_attribute_label', $mine ) ) ) );
	}
	if ( ! $mine ) {
		return dox_pos_vary_axes( $cat );
	}
	if ( 1 === count( $mine ) ) {
		$i = array_search( $mine[0], $cat, true );
		if ( false !== $i && 2 === count( $cat ) ) {
			return 0 === $i ? array( 'row' => $mine[0], 'col' => '' ) : array( 'row' => '', 'col' => $mine[0] );
		}
		return dox_pos_vary_axes( $mine );
	}
	if ( ! array_diff( $mine, $cat ) && ! array_diff( $cat, $mine ) ) {
		return dox_pos_vary_axes( $cat );
	}
	// Otros que los de su categoría: el color en las columnas; si los dos son o no son color, la talla en las filas.
	$c0 = dox_pos_is_color_attribute( $mine[0] );
	$c1 = dox_pos_is_color_attribute( $mine[1] );
	if ( $c0 !== $c1 ) {
		return $c0 ? array( 'row' => $mine[1], 'col' => $mine[0] ) : array( 'row' => $mine[0], 'col' => $mine[1] );
	}
	if ( dox_pos_is_size_attribute( $mine[1] ) && ! dox_pos_is_size_attribute( $mine[0] ) ) {
		return array( 'row' => $mine[1], 'col' => $mine[0] );
	}
	return array( 'row' => $mine[0], 'col' => $mine[1] );
}

/**
 * El atributo de talla de un producto: el que ya usan sus variaciones; si aún no tiene, el de
 * su categoría; si nada, el de siempre.
 *
 * @param WC_Product|null $p        Producto (o variación: se mira el padre).
 * @param string          $fallback El que se usa si no se sabe ('' = el de siempre).
 * @return string
 */
function dox_pos_product_size_attribute( $p, $fallback = '' ) {
	if ( $p instanceof WC_Product && $p->is_type( 'variation' ) ) {
		$p = wc_get_product( $p->get_parent_id() );
	}
	if ( $p instanceof WC_Product ) {
		foreach ( $p->get_attributes() as $a ) {
			if ( $a instanceof WC_Product_Attribute && $a->get_variation() && dox_pos_is_size_attribute( $a->get_name() ) ) {
				return $a->get_name();
			}
		}
		$map = dox_pos_category_size_map();
		foreach ( $p->get_category_ids() as $cid ) {
			if ( isset( $map[ (int) $cid ] ) ) {
				return $map[ (int) $cid ];
			}
		}
	}
	return '' !== (string) $fallback ? (string) $fallback : dox_pos_size_attribute();
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
	if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === DOX_POS_VERSION && isset( $cached['attrs'] ) ) { // Sin attrs es de antes de la 0.57.
		return $cached;
	}
	$s      = dox_pos_products_settings();
	$labels = dox_pos_attribute_taxonomies();
	$lists  = array(); // Las tallas de cada atributo de talla (0.56; el Pro y las cajas viejas las leen).
	foreach ( $s['size_attrs'] as $tax ) {
		$lists[ $tax ] = array(
			'label' => html_entity_decode( (string) ( $labels[ $tax ] ?? $tax ), ENT_QUOTES, 'UTF-8' ),
			'sizes' => dox_pos_attribute_terms( $tax, 'size' ),
		);
	}
	$sizes  = $lists[ $s['size_attr'] ]['sizes'] ?? array();
	$colors = dox_pos_attribute_terms( $s['color_attr'], 'color' );
	$out    = array(
		'version'    => DOX_POS_VERSION,
		'categories' => dox_pos_product_categories(),
		'attrs'      => (object) dox_pos_vary_attributes(), // Todos los atributos de la tienda con sus valores: cada categoría varía por los suyos.
		'sizes'      => $sizes,             // Las del atributo de siempre (las de antes de la 0.56).
		'size_attr'  => $s['size_attr'],
		'size_attrs' => (object) $lists,
		'color_attr' => $s['color_attr'],
		'colors'     => $colors,
		'sku_format' => $s['sku'],
		'quality'    => $s['quality'],
		'max_px'     => $s['max_px'],
		'has_size'   => (bool) $s['size_attrs'],  // Sin atributo de talla en la tienda, la pantalla no lo pregunta.
		'has_color'  => '' !== $s['color_attr'],
		'price_range' => dox_pos_price_range(),  // Lo que cuesta lo más barato y lo más caro: para avisar de un precio raro.
		'packages'   => dox_pos_common_packages(), // Los paquetes (peso y medidas) que más se repiten en la tienda: se eligen con un toque.
		'weight_matters' => dox_pos_weight_matters(), // ¿Alguna zona cobra el envío por peso? Entonces crear un producto sin peso se avisa.
	);
	set_transient( 'dox_pos_product_form', $out, HOUR_IN_SECONDS );
	return $out;
}

/**
 * Un atributo de la tienda como lo pinta la caja: su nombre, si es de talla o de color, y sus valores (los
 * colores con su muestra y por uso; lo demás en el orden de las tallas).
 *
 * @param string $tax Taxonomía.
 * @return array{tax:string,label:string,size:bool,color:bool,values:array}
 */
function dox_pos_vary_attribute( $tax ) {
	$labels = dox_pos_attribute_taxonomies();
	$color  = dox_pos_is_color_attribute( $tax );
	return array(
		'tax'    => (string) $tax,
		'label'  => html_entity_decode( (string) ( $labels[ $tax ] ?? $tax ), ENT_QUOTES, 'UTF-8' ),
		'size'   => dox_pos_is_size_attribute( $tax ),
		'color'  => $color,
		'values' => dox_pos_attribute_terms( $tax, $color ? 'color' : 'size' ),
	);
}

/**
 * Todos los atributos globales de la tienda, listos para "Varía por".
 *
 * @return array<string,array>
 */
function dox_pos_vary_attributes() {
	$out = array();
	foreach ( array_keys( dox_pos_attribute_taxonomies() ) as $tax ) {
		$out[ $tax ] = dox_pos_vary_attribute( $tax );
	}
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
 * Una medida (peso, largo, ancho o alto) como la guarda WooCommerce: con punto decimal y sin ceros
 * de sobra. Vale la coma como decimal. Vacío si no es un número mayor que cero.
 *
 * @param mixed $v Lo que llegó.
 * @return string
 */
function dox_pos_measure( $v ) {
	$v = str_replace( ',', '.', trim( (string) $v ) );
	if ( '' === $v || ! is_numeric( $v ) || (float) $v <= 0 ) {
		return '';
	}
	$out = (string) wc_format_decimal( min( (float) $v, 999999.0 ), 3, true );
	return (float) $out > 0 ? $out : ''; // 0,0004 redondea a cero: tampoco es una medida.
}

/**
 * El paquete que manda el formulario (package: weight, length, width, height), solo con las medidas
 * que llegan. Null si no manda ninguna: al editar significa "no se tocó", y el producto se queda
 * como está. Al crear llegan las cuatro; al editar, solo las que cambiaron, para que escribir el
 * peso de un producto no le borre unas medidas que el formulario ni siquiera enseñaba.
 *
 * @param array $data Lo que llegó.
 * @return array|null
 */
function dox_pos_package_from( $data ) {
	if ( ! isset( $data['package'] ) || ! is_array( $data['package'] ) ) {
		return null;
	}
	$out = array();
	foreach ( array( 'weight', 'length', 'width', 'height' ) as $k ) {
		if ( array_key_exists( $k, $data['package'] ) ) {
			$out[ $k ] = dox_pos_measure( $data['package'][ $k ] );
		}
	}
	return $out ? $out : null;
}

/**
 * Pone el paquete a un producto: las medidas que vengan, y las demás se quedan como están.
 *
 * @param WC_Product $p   El producto (o una variación).
 * @param array      $pkg Alguna de weight, length, width, height.
 */
function dox_pos_set_package( $p, $pkg ) {
	foreach ( array( 'weight', 'length', 'width', 'height' ) as $k ) {
		if ( array_key_exists( $k, $pkg ) ) {
			$p->{"set_$k"}( $pkg[ $k ] );
		}
	}
}

/**
 * El paquete de un producto para el formulario. En uno con tallas manda lo que de verdad lleva cada
 * variación (la suya, o la del producto si no tiene), medida por medida: la que coincide en todas
 * es esa; la que no, sale vacía y apuntada en mixed_fields, y el formulario la deja sin tocar para
 * no pisar lo que alguien afinó en WooCommerce. Así unas tallas que pesan distinto no esconden el
 * largo, el ancho y el alto que sí comparten.
 *
 * @param WC_Product $p El producto.
 * @return array{weight:string,length:string,width:string,height:string,mixed:bool,mixed_fields:string[]}
 */
function dox_pos_product_package( $p ) {
	$keys = array( 'weight', 'length', 'width', 'height' );
	$of   = fn( $x ) => array(
		'weight' => dox_pos_measure( $x->get_weight() ),
		'length' => dox_pos_measure( $x->get_length() ),
		'width'  => dox_pos_measure( $x->get_width() ),
		'height' => dox_pos_measure( $x->get_height() ),
	);
	$own   = $of( $p );
	$mixed = array();
	if ( $p->is_type( 'variable' ) ) {
		$seen = array_fill_keys( $keys, array() );
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			foreach ( $of( $v ) as $k => $val ) { // La variación ya devuelve la del producto cuando no tiene la suya.
				$seen[ $k ][ 'v' . $val ] = $val; // Con prefijo: PHP convertiría en número una clave como "5".
			}
		}
		foreach ( $keys as $k ) {
			if ( count( $seen[ $k ] ) > 1 ) {
				$own[ $k ] = '';
				$mixed[]   = $k;
			} elseif ( 1 === count( $seen[ $k ] ) ) {
				$own[ $k ] = (string) reset( $seen[ $k ] );
			}
		}
	}
	return $own + array( 'mixed' => (bool) $mixed, 'mixed_fields' => $mixed );
}

/**
 * Los paquetes (peso y medidas) que más se repiten entre los productos de la tienda, para ponerlos
 * con un toque: quien vende ropa manda casi todo en la misma bolsa. Como mucho cinco.
 *
 * @return array [ [ 'weight', 'length', 'width', 'height' ], ... ]
 */
function dox_pos_common_packages() {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT w.meta_value weight, l.meta_value length, a.meta_value width, h.meta_value height, COUNT(*) n
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} w ON w.post_id = p.ID AND w.meta_key = '_weight'
		LEFT JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = '_length'
		LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_width'
		LEFT JOIN {$wpdb->postmeta} h ON h.post_id = p.ID AND h.meta_key = '_height'
		WHERE p.post_type = 'product' AND p.post_status IN ( 'publish', 'private' )
		AND ( w.meta_value > '' OR l.meta_value > '' OR a.meta_value > '' OR h.meta_value > '' )
		GROUP BY w.meta_value, l.meta_value, a.meta_value, h.meta_value
		ORDER BY n DESC LIMIT 12",
		ARRAY_A
	);
	$out = array();
	foreach ( (array) $rows as $r ) {
		$pkg = array(
			'weight' => dox_pos_measure( $r['weight'] ?? '' ),
			'length' => dox_pos_measure( $r['length'] ?? '' ),
			'width'  => dox_pos_measure( $r['width'] ?? '' ),
			'height' => dox_pos_measure( $r['height'] ?? '' ),
		);
		if ( '' === implode( '', $pkg ) ) {
			continue;
		}
		$out[ implode( '|', $pkg ) ] = $pkg; // "0.5" y "0.50" son el mismo paquete.
	}
	return array_slice( array_values( $out ), 0, 5 );
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
			$row['label'] = dox_pos_term_label( $t->term_id, $tax );
			$row['key']   = dox_pos_size_sort_key( $t->name );
		} else {
			$row['hex'] = dox_pos_term_hex( $t->term_id, $tax );
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
	// Las de letras, en su orden y no por el alfabeto (antes salían L, M, S, XL, XS).
	// También las de México y Centroamérica (CH, M, G, EG o XG) y las de Brasil (PP, P, M, G, GG, XG).
	$letras = array(
		'xxxs' => 0, 'xxs' => 1, 'xs' => 2, 's' => 3, 'm' => 4, 'l' => 5, 'xl' => 6, 'xxl' => 7, '2xl' => 7, 'xxxl' => 8, '3xl' => 8, 'xxxxl' => 9, '4xl' => 9, 'xxxxxl' => 10, '5xl' => 10, '6xl' => 11,
		'xch' => 2, 'ch' => 3, 'pp' => 2, 'p' => 3, 'g' => 5, 'gg' => 6, 'eg' => 6, 'xg' => 6, 'xxg' => 7, 'exg' => 7, 'xgg' => 7,
		'chica' => 3, 'chico' => 3, 'pequena' => 3, 'pequeno' => 3, 'mediana' => 4, 'mediano' => 4, 'grande' => 5, 'extragrande' => 6,
	);
	$sola   = preg_replace( '/^talla\s*|[\s.\-]/', '', $n );
	if ( isset( $letras[ $sola ] ) ) {
		return 8000000 + $letras[ $sola ];
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
 * Los sistemas de muestras que la tienda tiene activos, para escribir ahí el color de un valor nuevo. Cada tema o
 * plugin guarda el color en su propio meta del término:
 *
 * - xstore: el tema XStore, en st-color-swatch (un array con el hex).
 * - wvs: Variation Swatches for WooCommerce de GetWooPlugins (Emran Ahmed), en product_attribute_color.
 * - cfvsw: Variation Swatches for WooCommerce de CartFlows, en cfvsw_color.
 * - thwvsf: Product Variation Swatches de ThemeHigh, en product_{taxonomía} (product_pa_color).
 * - vi: Product Variations Swatches de VillaTheme, en vi_wpvs_terms_params (un array con type y color).
 *
 * Si no hay ninguno se sigue escribiendo el de XStore, como antes: no estorba y la caja lo lee.
 *
 * @return string[]
 */
function dox_pos_swatch_systems() {
	$out = array();
	if ( 'xstore' === get_template() ) {
		$out[] = 'xstore';
	}
	if ( defined( 'WOO_VARIATION_SWATCHES_PLUGIN_FILE' ) ) {
		$out[] = 'wvs';
	}
	if ( defined( 'CFVSW_VER' ) ) {
		$out[] = 'cfvsw';
	}
	if ( defined( 'THWVSF_VERSION' ) ) {
		$out[] = 'thwvsf';
	}
	if ( defined( 'VI_WOO_PRODUCT_VARIATIONS_SWATCHES_VERSION' ) ) {
		$out[] = 'vi';
	}
	return $out ? $out : array( 'xstore' );
}

/**
 * El color de una muestra ("#e8d8c3"). Se busca en el meta de cada sistema de muestras (ver
 * dox_pos_swatch_systems()), aunque ya no esté activo: el color que se puso con un plugin sigue sirviendo si se
 * cambia de tema. Al final, el meta "color" que también lee GetWooPlugins (lo usan algunos temas).
 *
 * @param int    $term_id Término.
 * @param string $tax     Su taxonomía (pa_color); si no se dice, se busca.
 * @return string Hex, o vacío.
 */
function dox_pos_term_hex( $term_id, $tax = '' ) {
	$term_id = (int) $term_id;
	if ( '' === $tax ) {
		$t   = get_term( $term_id );
		$tax = $t && ! is_wp_error( $t ) ? $t->taxonomy : '';
	}
	$vi    = get_term_meta( $term_id, 'vi_wpvs_terms_params', true );
	$found = array(
		get_term_meta( $term_id, 'st-color-swatch', true ),
		get_term_meta( $term_id, 'product_attribute_color', true ),
		get_term_meta( $term_id, 'cfvsw_color', true ),
		$tax ? get_term_meta( $term_id, 'product_' . $tax, true ) : '',
		is_array( $vi ) && ! empty( $vi['color'] ) ? $vi['color'] : '',
		get_term_meta( $term_id, 'color', true ),
	);
	foreach ( $found as $i => $v ) {
		if ( is_array( $v ) ) {
			$v = reset( $v );
		}
		// Fuera del de XStore, solo con su #: ThemeHigh guarda en el mismo meta el id de una imagen ("123").
		$hex = is_string( $v ) && ( 0 === $i || 0 === strpos( trim( $v ), '#' ) ) ? dox_pos_hex( $v ) : '';
		if ( '' !== $hex ) {
			return $hex;
		}
	}
	return '';
}

/**
 * Guarda el color de un valor nuevo en el meta de cada sistema de muestras activo, para que la tienda enseñe su
 * círculo de color igual que la caja.
 *
 * @param int    $term_id Término.
 * @param string $tax     Taxonomía (pa_color).
 * @param string $hex     Color.
 */
function dox_pos_set_term_hex( $term_id, $tax, $hex ) {
	$hex = strtolower( dox_pos_hex( $hex ) );
	if ( '' === $hex ) {
		return;
	}
	foreach ( dox_pos_swatch_systems() as $system ) {
		if ( 'xstore' === $system ) {
			update_term_meta( $term_id, 'st-color-swatch', array( $hex ) );
		} elseif ( 'wvs' === $system ) {
			update_term_meta( $term_id, 'product_attribute_color', $hex );
		} elseif ( 'cfvsw' === $system ) {
			update_term_meta( $term_id, 'cfvsw_color', $hex );
		} elseif ( 'thwvsf' === $system ) {
			update_term_meta( $term_id, 'product_' . $tax, $hex );
		} elseif ( 'vi' === $system ) {
			$old = get_term_meta( $term_id, 'vi_wpvs_terms_params', true );
			update_term_meta( $term_id, 'vi_wpvs_terms_params', array_merge( is_array( $old ) ? $old : array(), array( 'type' => 'color', 'color' => array( $hex ), 'color_separator' => '1' ) ) );
		}
	}
}

/**
 * La etiqueta corta de una talla ("0-12 M"): la de XStore (st-label-swatch) o, con ThemeHigh, la de un atributo
 * de tipo etiqueta (product_pa_talla, que en uno de color guarda el hex y en uno de imagen el id de la imagen).
 * Los demás plugins de muestras no tienen etiqueta aparte: usan el nombre.
 *
 * @param int    $term_id Término.
 * @param string $tax     Taxonomía.
 * @return string
 */
function dox_pos_term_label( $term_id, $tax ) {
	$v = get_term_meta( $term_id, 'st-label-swatch', true );
	if ( is_string( $v ) && '' !== trim( $v ) ) {
		return $v;
	}
	$v = $tax ? get_term_meta( $term_id, 'product_' . $tax, true ) : '';
	return is_string( $v ) && '' !== trim( $v ) && ! is_numeric( $v ) && 0 !== strpos( trim( $v ), '#' ) ? $v : '';
}

/**
 * Las categorías de la tienda, agrupadas por su categoría de arriba, con el prefijo de código y
 * las tallas que usan sus productos (para sugerirlos al elegirla). Van todas, también las vacías
 * (count dice cuántos productos tiene cada una): la pantalla enseña primero las que se usan y deja
 * las demás detrás de "Más categorías", y una tienda recién instalada, sin productos, puede crear
 * el primero. La de "sin categoría" no sale: elegirla es no elegir ninguna.
 *
 * Cada una lleva su atributo de talla (size_attr: Talla calzado en Zapatos, Talla en Ropa) y por qué varían
 * sus productos (vary: Capacidad y Color en Termos). Sus tallas sugeridas (sizes) son valores de la primera
 * opción, sea talla o no.
 *
 * @param array $sizes Sin uso desde la 0.56 (cada categoría lleva las tallas de su atributo).
 * @return array
 */
function dox_pos_product_categories( $sizes = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	$all = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
	if ( is_wp_error( $all ) ) {
		return array();
	}
	$by_id = array();
	foreach ( $all as $t ) {
		$by_id[ $t->term_id ] = $t;
	}
	$prefix = dox_pos_category_prefixes();
	$sets   = dox_pos_category_size_sets();
	$attr   = dox_pos_category_size_map();
	$vary   = dox_pos_category_vary_map();
	$plain  = fn( $s ) => html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ); // "Lazos &amp; pinzas" se guarda así en la base.
	$none   = (int) get_option( 'default_product_cat', 0 ); // "Sin categoría", se llame como se llame en el idioma de la tienda.
	$out    = array();
	foreach ( $all as $t ) {
		if ( 'uncategorized' === $t->slug || ( (int) $t->term_id === $none && $t->count < 1 ) ) {
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
			'size_attr' => $attr[ (int) $t->term_id ] ?? dox_pos_size_attribute(),
			'vary'      => $vary[ (int) $t->term_id ] ?? array(), // Por qué varían sus productos (0.57): filas y columnas del formulario.
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
 * Los valores de la primera opción que más se repiten en los productos de cada categoría (las seis tallas
 * de un vestido, las capacidades de un termo), como ids de término. Solo cuentan los del atributo de las
 * filas de la categoría (su "Varía por").
 *
 * @param array $sizes Sin uso desde la 0.56.
 * @return array<int,int[]>
 */
function dox_pos_category_size_sets( $sizes = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	global $wpdb;
	$attr = array();
	foreach ( dox_pos_category_vary_map() as $tid => $set ) {
		$row = dox_pos_vary_axes( $set )['row'];
		if ( '' !== $row ) {
			$attr[ $tid ] = $row;
		}
	}
	$taxes = array_values( array_unique( $attr ) );
	if ( ! $taxes ) {
		return array();
	}
	$keys    = array_map( fn( $t ) => 'attribute_' . $t, $taxes );
	$in      = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT x.term_id, v.post_parent, t.meta_key k, GROUP_CONCAT(DISTINCT t.meta_value ORDER BY t.meta_value SEPARATOR ',') slugs FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} t ON t.post_id = v.ID AND t.meta_key IN ( $in ) AND t.meta_value <> '' JOIN {$wpdb->term_relationships} tr ON tr.object_id = v.post_parent JOIN {$wpdb->term_taxonomy} x ON x.term_taxonomy_id = tr.term_taxonomy_id AND x.taxonomy = 'product_cat' WHERE v.post_type = 'product_variation' AND v.post_status = 'publish' GROUP BY x.term_id, v.post_parent, t.meta_key", $keys ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$by_slug = array();
	foreach ( $taxes as $tax ) {
		$by_slug[ $tax ] = array_column( dox_pos_attribute_terms( $tax, 'size' ), 'id', 'slug' );
	}
	$count = array();
	foreach ( (array) $rows as $r ) {
		$tid = (int) $r['term_id'];
		$tax = substr( $r['k'], 10 );
		if ( ( $attr[ $tid ] ?? '' ) !== $tax ) {
			continue; // Un cinturón metido también en Zapatos no le cambia las tallas a Zapatos.
		}
		$count[ $tid ][ $r['slugs'] ] = ( $count[ $tid ][ $r['slugs'] ] ?? 0 ) + 1;
	}
	$out = array();
	foreach ( $count as $tid => $c ) {
		arsort( $c );
		$ids = array();
		foreach ( explode( ',', (string) array_key_first( $c ) ) as $slug ) {
			if ( isset( $by_slug[ $attr[ $tid ] ][ $slug ] ) ) {
				$ids[] = (int) $by_slug[ $attr[ $tid ] ][ $slug ];
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
	// Los valores de todos los atributos: un mismo nombre ("8") lleva el mismo código en cualquiera. Los dos
	// primeros dígitos son la primera opción (la talla, la capacidad) y los dos últimos el color, si lo hay.
	$rows = $wpdb->get_results( "SELECT v.ID, vs.meta_value vsku, ps.meta_value psku FROM {$wpdb->posts} v JOIN {$wpdb->postmeta} vs ON vs.post_id = v.ID AND vs.meta_key = '_sku' AND vs.meta_value <> '' JOIN {$wpdb->postmeta} ps ON ps.post_id = v.post_parent AND ps.meta_key = '_sku' AND ps.meta_value <> '' WHERE v.post_type = 'product_variation'", ARRAY_A );
	$vals = array();
	$meta = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} v ON v.ID = m.post_id AND v.post_type = 'product_variation' WHERE m.meta_key LIKE %s AND m.meta_value <> ''", $wpdb->esc_like( 'attribute_pa_' ) . '%' ), ARRAY_A );
	foreach ( (array) $meta as $m ) {
		$vals[ (int) $m['post_id'] ][ substr( $m['meta_key'], 10 ) ] = (string) $m['meta_value'];
	}
	$sizes  = array();
	$colors = array();
	foreach ( (array) $rows as $r ) {
		if ( 0 !== strpos( $r['vsku'], $r['psku'] ) ) {
			continue;
		}
		$rem = substr( $r['vsku'], strlen( $r['psku'] ) );
		if ( ! ctype_digit( $rem ) || ( 3 !== strlen( $rem ) && 4 !== strlen( $rem ) ) ) {
			continue;
		}
		$sc  = substr( $rem, 0, 2 );
		$cc  = 4 === strlen( $rem ) ? substr( $rem, 2, 2 ) : '';
		$va  = $vals[ (int) $r['ID'] ] ?? array();
		$ord = dox_pos_vary_axes( dox_pos_sku_order( array_keys( $va ) ) );
		$one = '' !== $ord['row'] ? (string) ( $va[ $ord['row'] ] ?? '' ) : '';
		$two = '' !== $ord['col'] ? (string) ( $va[ $ord['col'] ] ?? '' ) : '';
		if ( '' !== $one ) {
			$sizes[ $one ][ $sc ] = ( $sizes[ $one ][ $sc ] ?? 0 ) + 1;
		}
		if ( '' !== $cc && '' !== $two ) {
			$colors[ $two ][ $cc ] = ( $colors[ $two ][ $cc ] ?? 0 ) + 1;
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
 * (por ejemplo "0- Siempre"), o 00 si no hay ninguna.
 */
function dox_pos_sku_code_no_size() {
	foreach ( dox_pos_size_attributes() as $tax ) {
		foreach ( dox_pos_attribute_terms( $tax, 'size' ) as $t ) {
			if ( 9999999 === $t['key'] ) {
				return dox_pos_sku_code( 'size', $t['slug'] );
			}
		}
	}
	return '00';
}

/**
 * Los atributos de una variación en el orden de su código: la talla (o lo que no es color) primero y el color
 * después. Para leer los códigos que ya existen sin saber de qué categoría es cada producto.
 *
 * @param string[] $taxes Taxonomías.
 * @return string[] Las dos que cuentan.
 */
function dox_pos_sku_order( $taxes ) {
	$taxes = array_values( (array) $taxes );
	if ( count( $taxes ) < 2 ) {
		return $taxes;
	}
	usort(
		$taxes,
		static function ( $a, $b ) {
			$w = fn( $t ) => dox_pos_is_color_attribute( $t ) ? 2 : ( dox_pos_is_size_attribute( $t ) ? 0 : 1 );
			return $w( $a ) - $w( $b );
		}
	);
	return array_slice( $taxes, 0, 2 );
}

/**
 * El código de una variación según el formato del ajuste: el del padre, los dos dígitos de la primera opción
 * (la fila: talla, capacidad...) y los dos de la segunda (la columna: color, material...).
 *
 * @param string       $format codes, slugs o none.
 * @param string       $base   El código del padre.
 * @param WP_Term|null $size   El valor de la fila.
 * @param WP_Term|null $color  El valor de la columna.
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
		return sprintf( /* translators: 1: SKU, 2: product */ __( 'SKU %1$s is already used by %2$s.', 'dox-pos' ), $sku, $p ? dox_pos_item_name( $p ) : '#' . $id );
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
			? sprintf( /* translators: %s: size */ __( 'The photo is larger than the server allows (%s).', 'dox-pos' ), size_format( wp_max_upload_size() ) )
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
	wp_raise_memory_limit( 'image' );
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 ); // Una foto de muchos megapíxeles en un servidor lento no puede morir a mitad.
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
	// Con el tamaño de la foto en la mano (cuesta milisegundos) se le da a ImageMagick el techo
	// que necesita y se reduce en dos pasos, que es lo que hace llevadera una foto de 48 MP.
	dox_pos_limit_imagick( dox_pos_image_pixels( $path ) );
	require_once DOX_POS_PATH . 'includes/class-dox-pos-image-editor.php';
	$propio = class_exists( 'Dox_POS_Image_Editor' );
	if ( $propio ) {
		add_filter( 'wp_image_editors', 'dox_pos_image_editors' );
	}
	$editor = wp_get_image_editor( $path, array( 'mime_type' => $type ) );
	if ( $propio ) {
		remove_filter( 'wp_image_editors', 'dox_pos_image_editors' );
	}
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
 * El techo de memoria de ImageMagick, a la medida de la foto que llega. Descifrada ocupa 8 bytes
 * por píxel (93 MB una de 12 megapíxeles, 372 MB una de 48), y en cuanto no cabe en el techo,
 * ImageMagick trabaja contra disco: la misma foto de 48 MP tarda 146 segundos con 256 MB y 4 con
 * 768. Se le da lo que pide, con sitio para la copia reducida, entre 256 MB y 1 GB, que es hasta
 * donde llega un alojamiento normal.
 *
 * @param int $pixels Ancho por alto de la foto. Con 0, el techo de siempre.
 */
function dox_pos_limit_imagick( $pixels = 0 ) {
	if ( ! class_exists( 'Imagick' ) ) {
		return;
	}
	$mb = $pixels > 0 ? (int) ceil( $pixels * 8 * 2 / MB_IN_BYTES ) : 256;
	$mb = min( 1024, max( 256, $mb ) );
	try {
		Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, $mb * MB_IN_BYTES );
		Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, 2 * $mb * MB_IN_BYTES );
	} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Sin límite: se sigue igual.
	}
}

/**
 * Cuántos píxeles tiene la foto, sin descifrarla. getimagesize() no sabe de HEIC, así que en ese
 * caso se lo pregunta a ImageMagick, que solo lee la cabecera y tarda milisegundos.
 *
 * @param string $path El archivo.
 * @return int Ancho por alto, o 0 si no se sabe.
 */
function dox_pos_image_pixels( $path ) {
	$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Con un formato que no conoce avisa; para eso está el respaldo de abajo.
	if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
		return (int) $size[0] * (int) $size[1];
	}
	if ( ! class_exists( 'Imagick' ) ) {
		return 0;
	}
	try {
		$im = new Imagick();
		$im->pingImage( $path );
		$px = (int) $im->getImageWidth() * (int) $im->getImageHeight();
		$im->clear();
		return $px;
	} catch ( Exception $e ) {
		return 0;
	}
}

/**
 * El editor de la caja (el de WordPress con el muestreo previo) por delante de los suyos.
 *
 * @param string[] $editors Los editores.
 * @return string[]
 */
function dox_pos_image_editors( $editors ) {
	array_unshift( $editors, 'Dox_POS_Image_Editor' );
	return $editors;
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
	// Por qué varía: lo que manda la pantalla (size_attr en las filas y color_attr en las columnas, los de la
	// categoría elegida) o, si no lo manda, lo de su primera categoría. Una caja anterior a la 0.57 manda solo size_attr.
	$axes = dox_pos_vary_axes( dox_pos_categories_vary( $cats ) );
	$all  = dox_pos_attribute_taxonomies();
	if ( array_key_exists( 'size_attr', $data ) || array_key_exists( 'color_attr', $data ) ) {
		$want_row = (string) ( $data['size_attr'] ?? '' );
		$want_col = array_key_exists( 'color_attr', $data ) ? (string) $data['color_attr'] : $axes['col'];
		if ( ! array_key_exists( 'color_attr', $data ) && ! dox_pos_is_size_attribute( $want_row ) ) {
			$want_row = $axes['row']; // Una caja vieja manda la talla de siempre: si no es de talla, lo de la categoría.
		}
		$axes = array(
			'row' => isset( $all[ $want_row ] ) ? $want_row : '',
			'col' => isset( $all[ $want_col ] ) && $want_col !== $want_row ? $want_col : '',
		);
	}
	$size_tax  = $axes['row'];
	$color_tax = $axes['col'];
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
	// Qué tallas salen del total del producto (shared_cells, clave color|talla) y ese total (shared_stock): lo
	// lleva el producto y esas tallas lo heredan; las demás llevan las suyas. Sin shared_cells pero con
	// shared_stock, todas (los formularios anteriores a la 0.30). Null = cada talla lleva las suyas.
	$cells = null;
	if ( $variable && isset( $data['shared_cells'] ) && is_array( $data['shared_cells'] ) ) {
		$cells = array_map( 'strval', $data['shared_cells'] );
	} elseif ( $variable && isset( $data['shared_stock'] ) && '' !== $data['shared_stock'] && null !== $data['shared_stock'] ) {
		$cells = array( '*' );
	}
	// Con varios colores, las unidades compartidas van por color (una bolsa por color, includes/pools.php);
	// con uno o ninguno, el total del producto de siempre. pool_stock trae las de cada columna de color.
	$pool_stock = dox_pos_pool_stock_from( $data );
	$groups     = dox_pos_pool_groups_from( $data, $cells ); // El grupo de cada celda que comparte (1, 2...).
	$sync       = count( $colors ) > 1 || ( $groups && max( $groups ) > 1 ); // Bolsas propias: varios colores, o más de un grupo.
	$shared     = $cells && ! $sync ? max( 0, (int) ( $pool_stock ? reset( $pool_stock ) : 0 ) ) : null;
	$pools_seen = array();
	$reals      = dox_pos_real_cells_from( $data ); // La talla real de cada grupo que comparte (null: el formulario no lo manda).
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
	$pkg = dox_pos_package_from( $data );
	if ( $pkg ) {
		dox_pos_set_package( $product, $pkg ); // El peso y las medidas van en el producto; cada talla los hereda.
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
		if ( null !== $shared ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $shared );
			$product->set_stock_status( $shared > 0 ? 'instock' : 'outofstock' );
			$units = $shared;
		}
	}
	try {
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		$pid = $product->save();
	} catch ( Exception $e ) {
		dox_pos_stock_context_end( $ctx );
		return new WP_Error( 'dox_pos_no_se_pudo', sprintf( /* translators: %s: reason */ __( 'WooCommerce would not create the product: %s', 'dox-pos' ), $e->getMessage() ) );
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
				$v->set_regular_price( $price );
				$g       = (int) ( $groups[ $ckey . '|' . $sid ] ?? ( $cells && in_array( '*', $cells, true ) ? 1 : 0 ) ); // Su grupo (0 = lleva las suyas).
				$inherit = null !== $shared && $g > 0;
				$inpool  = $sync && $g > 0; // Una bolsa propia: la de su color y grupo.
				$quiet   = false;
				if ( $inpool ) {
					$pk = (string) $ckey . ( $g > 1 ? '#' . $g : '' );
					$n  = (int) ( $pool_stock[ $pk ] ?? 0 );
					$v->set_manage_stock( true );
					$v->set_stock_quantity( $n );
					$v->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
					$v->update_meta_data( DOX_POS_POOL_META, dox_pos_pool_key_for( $cterm ? $cterm->slug : '', $g ) );
					if ( isset( $pools_seen[ $pk ] ) ) {
						$quiet = true; // El kardex apunta la bolsa una sola vez, en su primera talla.
					} else {
						$pools_seen[ $pk ] = true;
						$units            += $n;
					}
				} elseif ( ! $inherit ) {
					$n = dox_pos_qty_at( $qty, (string) $ckey, (string) $sid );
					$v->set_manage_stock( true );
					$v->set_stock_quantity( $n );
					$v->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
					$units += $n;
				} else {
					$v->set_manage_stock( false ); // Sale del total del producto.
				}
				if ( $g > 0 && $reals && in_array( $ckey . '|' . $sid, $reals, true ) ) {
					$v->update_meta_data( DOX_POS_REAL_SIZE_META, 'yes' ); // La talla de la pieza física de su grupo.
				}
				if ( $cterm && isset( $by_color[ $ckey ] ) ) {
					$v->set_image_id( $by_color[ $ckey ] );
				}
				if ( $quiet ) {
					dox_pos_stock_quiet( true );
				}
				$v->save();
				if ( $quiet ) {
					dox_pos_stock_quiet( false );
				}
				$nvars++;
			}
		}
		dox_pos_pool_forget();
		dox_pos_real_size_ids( 0, true );
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
 * Un valor nuevo de la segunda opción (un color, un material): se crea el término y, si es un color, su hex en
 * el sistema de muestras de la tienda (dox_pos_set_term_hex()).
 *
 * @param string $name Nombre.
 * @param string $hex  Color (solo cuenta en un atributo de color).
 * @param string $tax  pa_color, pa_material...
 * @return WP_Term|WP_Error
 */
function dox_pos_create_color( $name, $hex, $tax ) {
	$existing = term_exists( $name, $tax );
	if ( $existing ) {
		return get_term( (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ), $tax );
	}
	$r = wp_insert_term( $name, $tax );
	if ( is_wp_error( $r ) ) {
		/* translators: 1: colour or value, 2: reason */
		return new WP_Error( 'dox_pos_color', sprintf( __( 'The color %1$s could not be created: %2$s', 'dox-pos' ), $name, $r->get_error_message() ) );
	}
	$hex = dox_pos_hex( $hex );
	if ( $hex && dox_pos_is_color_attribute( $tax ) ) {
		dox_pos_set_term_hex( (int) $r['term_id'], $tax, $hex );
	}
	delete_transient( 'dox_pos_product_form' );
	return get_term( (int) $r['term_id'], $tax );
}

/**
 * Un valor nuevo desde la caja ("42" en Talla calzado, "750 ml" en Capacidad): se crea en el atributo que se
 * diga y vuelve listo para las fichas. Si ya existe, vuelve el que hay.
 *
 * @param array $data name, attr (pa_talla-calzado) y, en un atributo de color, hex.
 * @return array|WP_Error {size, attr}
 */
function dox_pos_create_size( $data ) {
	$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
	$tax  = (string) ( $data['attr'] ?? '' );
	if ( '' === $name ) {
		return new WP_Error( 'dox_pos_talla', __( 'Write the size.', 'dox-pos' ) );
	}
	$all = dox_pos_attribute_taxonomies();
	if ( ! isset( $all[ $tax ] ) || ! taxonomy_exists( $tax ) ) {
		return new WP_Error( 'dox_pos_talla', __( 'That type of size is not in the store.', 'dox-pos' ) );
	}
	$found = term_exists( $name, $tax );
	if ( ! $found ) {
		$r = wp_insert_term( $name, $tax );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'dox_pos_talla', sprintf( /* translators: 1: size, 2: reason */ __( 'The size %1$s could not be created: %2$s', 'dox-pos' ), $name, $r->get_error_message() ) );
		}
		$found = $r;
	}
	$id  = (int) ( is_array( $found ) ? $found['term_id'] : $found );
	$hex = dox_pos_hex( (string) ( $data['hex'] ?? '' ) );
	if ( $hex && dox_pos_is_color_attribute( $tax ) && '' === dox_pos_term_hex( $id, $tax ) ) {
		dox_pos_set_term_hex( $id, $tax, $hex ); // Un color nuevo con su muestra.
	}
	delete_transient( 'dox_pos_product_form' );
	foreach ( dox_pos_attribute_terms( $tax, dox_pos_is_color_attribute( $tax ) ? 'color' : 'size' ) as $t ) {
		if ( $t['id'] === $id ) {
			return array( 'size' => $t, 'attr' => $tax );
		}
	}
	return new WP_Error( 'dox_pos_talla', __( 'The size could not be created.', 'dox-pos' ) );
}

/**
 * Fija desde la caja qué tallas usa una categoría (Zapatos: Talla calzado; Carteras: ninguna). Lo de la 0.56,
 * para las cajas que aún no se recargaron: quita su "Varía por" para que valga lo que se fija aquí.
 *
 * @param array $data category (id) y attr (taxonomía, o "-" para sin tallas).
 * @return array|WP_Error {categories}
 */
function dox_pos_set_category_size( $data ) {
	$cid = (int) ( $data['category'] ?? 0 );
	$tax = (string) ( $data['attr'] ?? '' );
	if ( ! $cid || ! term_exists( $cid, 'product_cat' ) ) {
		return new WP_Error( 'dox_pos_categoria', __( 'That category is not in the store.', 'dox-pos' ) );
	}
	if ( '-' !== $tax && ! dox_pos_is_size_attribute( $tax ) ) {
		return new WP_Error( 'dox_pos_talla', __( 'That type of size is not in the store.', 'dox-pos' ) );
	}
	$o = get_option( 'dox_pos_products', array() );
	$o = is_array( $o ) ? $o : array();
	$o['cat_size']         = (array) ( $o['cat_size'] ?? array() );
	$o['cat_size'][ $cid ] = $tax;
	unset( $o['cat_vary'][ $cid ] );
	update_option( 'dox_pos_products', $o );
	dox_pos_products_settings( true );
	delete_transient( 'dox_pos_product_form' );
	return array( 'categories' => dox_pos_product_categories() );
}

/**
 * Fija desde la caja por qué varían los productos de una categoría (Termos: Capacidad y Color; Fundas: Modelo;
 * Carteras: nada). Lo mismo que Ajustes > Productos > Varía por: queda para siempre, y sus subcategorías sin
 * uno propio lo heredan. Vuelven todas las categorías.
 *
 * @param array $data category (id) y attrs (hasta dos taxonomías; vacío: no varía).
 * @return array|WP_Error {categories}
 */
function dox_pos_set_category_vary( $data ) {
	$cid = (int) ( $data['category'] ?? 0 );
	if ( ! $cid || ! term_exists( $cid, 'product_cat' ) ) {
		return new WP_Error( 'dox_pos_categoria', __( 'That category is not in the store.', 'dox-pos' ) );
	}
	$want = array_values( array_filter( array_map( 'strval', (array) ( $data['attrs'] ?? array() ) ) ) );
	$set  = $want ? dox_pos_clean_vary( $want ) : array();
	if ( null === $set || count( $set ) !== count( array_unique( $want ) ) ) {
		return new WP_Error( 'dox_pos_varia', __( 'Choose up to two different attributes of the store.', 'dox-pos' ) );
	}
	$o = get_option( 'dox_pos_products', array() );
	$o = is_array( $o ) ? $o : array();
	$o['cat_vary']         = (array) ( $o['cat_vary'] ?? array() );
	$o['cat_vary'][ $cid ] = $set ? $set : array( '-' );
	$o['cat_size']         = (array) ( $o['cat_size'] ?? array() );
	$o['cat_size'][ $cid ] = dox_pos_vary_size_compat( $set ); // Para una versión anterior a la 0.57 (y el Pro): su talla, o sin tallas.
	update_option( 'dox_pos_products', $o );
	dox_pos_products_settings( true );
	delete_transient( 'dox_pos_product_form' );
	return array( 'categories' => dox_pos_product_categories() );
}

/**
 * Lo que una versión anterior a la 0.57 entiende de un "Varía por": su atributo de talla, o "-" (sin tallas).
 *
 * @param string[] $set Opciones.
 * @return string
 */
function dox_pos_vary_size_compat( $set ) {
	foreach ( (array) $set as $tax ) {
		if ( dox_pos_is_size_attribute( $tax ) ) {
			return (string) $tax;
		}
	}
	return '-';
}

/**
 * Un atributo nuevo desde la caja ("Capacidad" con 350 ml, 500 ml y 1 L): se crea como atributo global de
 * WooCommerce con sus valores. Si se parece a uno que ya existe ("Tallas" y "Talla"), no se crea y se dice cuál
 * es, salvo que se pida crearlo igual (force).
 *
 * @param array $data name, values (lista o texto separado por comas) y force.
 * @return array|WP_Error {attr, created}; el error dox_pos_parecido lleva similar (taxonomía) y label.
 */
function dox_pos_create_attribute( $data ) {
	$name = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
	if ( '' === $name ) {
		return new WP_Error( 'dox_pos_atributo', __( 'Give the attribute a name.', 'dox-pos' ) );
	}
	$vals = $data['values'] ?? array();
	$vals = is_array( $vals ) ? $vals : explode( ',', (string) $vals );
	$vals = array_values( array_unique( array_filter( array_map( fn( $v ) => trim( sanitize_text_field( (string) $v ) ), $vals ) ) ) );
	$all  = dox_pos_attribute_taxonomies();
	$tax  = '';
	$new  = false;
	foreach ( $all as $t => $label ) {
		$same = dox_pos_attribute_likeness( $name, $label, substr( $t, 3 ) );
		if ( 2 === $same ) {
			$tax = $t; // Se llama igual: se usa ese y se le añaden los valores.
			break;
		}
		if ( 1 === $same && empty( $data['force'] ) ) {
			return new WP_Error(
				'dox_pos_parecido',
				/* translators: %s: attribute name */
				sprintf( __( 'The store already has an attribute called %s.', 'dox-pos' ), html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) ),
				array( 'similar' => $t, 'label' => html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) )
			);
		}
	}
	if ( '' === $tax ) {
		if ( ! function_exists( 'wc_create_attribute' ) ) {
			return new WP_Error( 'dox_pos_atributo', __( 'WooCommerce would not create the attribute.', 'dox-pos' ) );
		}
		$slug = substr( wc_sanitize_taxonomy_name( remove_accents( $name ) ), 0, 27 ); // WooCommerce admite 28 letras.
		$slug = '' !== $slug ? $slug : 'atributo';
		$base = $slug;
		$i    = 2;
		while ( taxonomy_exists( 'pa_' . $slug ) || isset( $all[ 'pa_' . $slug ] ) ) {
			$slug = substr( $base, 0, 24 ) . '-' . $i++;
		}
		$id = wc_create_attribute( array( 'name' => $name, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ) );
		if ( is_wp_error( $id ) ) {
			/* translators: 1: attribute, 2: reason */
			return new WP_Error( 'dox_pos_atributo', sprintf( __( 'The attribute %1$s could not be created: %2$s', 'dox-pos' ), $name, $id->get_error_message() ) );
		}
		$tax = wc_attribute_taxonomy_name( $slug );
		$new = true;
		// WooCommerce registra sus taxonomías al cargar la página: la nueva se registra ya para poder meterle valores.
		if ( ! taxonomy_exists( $tax ) ) {
			register_taxonomy( $tax, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false ) );
		}
		dox_pos_products_settings( true );
	}
	foreach ( $vals as $v ) {
		if ( ! term_exists( $v, $tax ) ) {
			wp_insert_term( $v, $tax );
		}
	}
	delete_transient( 'dox_pos_product_form' );
	return array( 'attr' => dox_pos_vary_attribute( $tax ), 'created' => $new ); // created: false si ya existía y solo se le añadieron valores.
}

/**
 * Cuánto se parecen dos nombres de atributo: 2 si son el mismo ("Capacidad" y "capacidad"), 1 si se parecen
 * ("Tallas" y "Talla", "Talla zapato" y "Talla zapatos", "Colour" y "Color"), 0 si no.
 *
 * @param string $name  Lo escrito.
 * @param string $label El nombre de uno que existe.
 * @param string $slug  Su slug (sin pa_).
 * @return int
 */
function dox_pos_attribute_likeness( $name, $label, $slug ) {
	$norm = fn( $x ) => sanitize_title( remove_accents( html_entity_decode( (string) $x, ENT_QUOTES, 'UTF-8' ) ) );
	$a    = $norm( $name );
	$best = 0;
	foreach ( array( $norm( $label ), $norm( $slug ) ) as $b ) {
		if ( '' === $a || '' === $b ) {
			continue;
		}
		if ( $a === $b ) {
			return 2;
		}
		$sing = fn( $x ) => preg_replace( '/(es|s)(?=-|$)/', '', $x ); // Tallas, talles, colores: sin plural.
		if ( $sing( $a ) === $sing( $b ) || ( strlen( $a ) >= 4 && strlen( $b ) >= 4 && levenshtein( $a, $b ) <= 2 ) ) {
			$best = 1;
		}
	}
	return $best;
}

/**
 * Crea una categoría de productos desde el formulario (o devuelve la que ya se llama así en ese
 * mismo sitio). Se crea al momento, no con el producto: así queda lista para los siguientes.
 *
 * @param array $data name y, si va dentro de otra, parent (id).
 * @return array|WP_Error La categoría, como la pinta el formulario.
 */
function dox_pos_create_category( $data ) {
	$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
	if ( '' === $name ) {
		return new WP_Error( 'dox_pos_sin_nombre', __( 'Give the category a name.', 'dox-pos' ) );
	}
	$parent = (int) ( $data['parent'] ?? 0 );
	if ( $parent && ! term_exists( $parent, 'product_cat' ) ) {
		$parent = 0;
	}
	$found = term_exists( $name, 'product_cat', $parent );
	if ( $found ) {
		$id = (int) ( is_array( $found ) ? $found['term_id'] : $found );
	} else {
		$r = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'dox_pos_categoria', sprintf( /* translators: 1: category, 2: reason */ __( 'The category %1$s could not be created: %2$s', 'dox-pos' ), $name, $r->get_error_message() ) );
		}
		$id = (int) $r['term_id'];
	}
	delete_transient( 'dox_pos_product_form' );
	foreach ( dox_pos_product_categories() as $c ) {
		if ( $c['id'] === $id ) {
			return array( 'category' => $c );
		}
	}
	if ( $found ) {
		// Ya existía y la lista no la enseña: es la de "sin categoría" de WooCommerce, que aquí no se elige.
		/* translators: %s: category name */
		return new WP_Error( 'dox_pos_categoria', sprintf( __( '%s is the category WooCommerce gives to products that have none, so it is not chosen here. Use another name.', 'dox-pos' ), $name ) );
	}
	return new WP_Error( 'dox_pos_categoria', __( 'The category was created, but it could not be read back. Reload the page.', 'dox-pos' ) );
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
		dox_pos_pool_forget();
		$units = (int) dox_pos_product_units( $p ); // Cada bolsa de color y el total del producto, una sola vez.
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
 * El modelo de un producto variable: los valores de sus dos opciones (las filas, "sizes", y las columnas,
 * "colors", sean tallas y colores o capacidades y materiales) y una fila por variación (su valor de cada
 * opción, existencias propias o heredadas, precio, imagen). Error si varía por algo que la caja no maneja.
 *
 * @param WC_Product $p         El producto.
 * @param string     $size_tax  Sin uso desde la 0.57 (el Pro lo manda): cada producto lleva los suyos.
 * @param string     $color_tax Sin uso desde la 0.57.
 * @return array{sizes: int[], colors: array, variations: array, shared: int|null, size_attr: string, color_attr: string}|WP_Error
 */
function dox_pos_product_model( $p, $size_tax = '', $color_tax = '' ) {
	$axes = dox_pos_product_axes( $p );
	if ( is_wp_error( $axes ) ) {
		return $axes;
	}
	$size_tax  = $axes['row'];
	$color_tax = $axes['col'];
	$out       = array( 'sizes' => array(), 'colors' => array(), 'variations' => array(), 'shared' => null, 'pools' => array(), 'legacy_pool' => false, 'size_attr' => $size_tax, 'color_attr' => $color_tax );
	if ( ! $p->is_type( 'variable' ) ) {
		return $out;
	}
	$swatch = dox_pos_is_color_attribute( $color_tax );
	foreach ( $p->get_attributes() as $a ) {
		if ( ! $a instanceof WC_Product_Attribute || ! $a->get_variation() ) {
			continue;
		}
		$name = $a->get_name();
		if ( $name === $size_tax ) {
			$out['sizes'] = array_map( 'intval', $a->get_options() );
		} elseif ( $name === $color_tax ) {
			foreach ( $a->get_options() as $tid ) {
				$t = get_term( (int) $tid, $color_tax );
				if ( $t && ! is_wp_error( $t ) ) {
					$out['colors'][] = array( 'key' => (string) $t->term_id, 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), 'hex' => $swatch ? dox_pos_term_hex( $t->term_id, $color_tax ) : '' );
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
		$own  = true === $v->get_manage_stock(); // 'parent' = hereda las del producto.
		$pool = $own ? dox_pos_pool_key( $v ) : ''; // O comparte una bolsa: la de su color y grupo (includes/pools.php).
		$g    = '' !== $pool ? dox_pos_pool_group( $pool ) : 0;
		$ckey = $out['colors'] ? (string) $color_by_slug[ $cslug ] : '';
		if ( ! $own && null !== $parent_stock ) {
			$inherits = true;
		}
		if ( '' !== $pool ) {
			$out['pools'][ $ckey . ( $g > 1 ? '#' . $g : '' ) ] = dox_pos_pool_stock( $p->get_id(), $pool ); // Clave del formulario: columna de color y "#2"...
		}
		$out['variations'][] = array(
			'id'     => (int) $vid,
			'size'   => $out['sizes'] ? (int) $size_by_slug[ $sslug ] : 0,
			'color'  => $ckey,
			'stock'  => $own ? (int) $v->get_stock_quantity() : null,
			'pool'   => $pool,
			'group'  => $g,
			'price'  => (float) $v->get_regular_price( 'edit' ),
			'image'  => (int) $v->get_image_id( 'edit' ), // Sin 'edit' devolvería la del padre.
			'status' => $v->get_status(),
			'real'   => 'yes' === $v->get_meta( DOX_POS_REAL_SIZE_META, true, 'edit' ), // La talla de la pieza física de su grupo.
		);
	}
	$out['shared']      = $inherits ? $parent_stock : null;
	$out['legacy_pool'] = $inherits && count( $out['colors'] ) > 1; // Un total único para varios colores: al guardar, cada color pasa a llevar el suyo.
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
	$model = dox_pos_product_model( $p );
	if ( is_wp_error( $model ) ) {
		return $model;
	}
	// La opción que le falta (un producto creado sin colores): se le puede añadir la de su categoría.
	$cat_axes = dox_pos_vary_axes( dox_pos_categories_vary( $p->get_category_ids() ) );
	$add_row  = '';
	$add_col  = '';
	if ( $p->is_type( 'variable' ) && $model['variations'] ) {
		if ( '' === $model['size_attr'] ) {
			$add_row = $cat_axes['row'] !== $model['color_attr'] ? $cat_axes['row'] : '';
		}
		if ( '' === $model['color_attr'] ) {
			$add_col = $cat_axes['col'] !== $model['size_attr'] ? $cat_axes['col'] : '';
		}
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
	$shared_cells  = array(); // Las tallas que no llevan las suyas: salen del total del producto, o de una bolsa.
	$shared_groups = array(); // Y en qué grupo está cada una (1, 2...): un color puede tener varios grupos.
	$pool_stock    = array(); // Las unidades compartidas por bolsa (clave: columna de color y "#2" del segundo grupo en adelante).
	$real_cells    = array(); // Las tallas marcadas como talla real (la etiqueta de la pieza física de su grupo).
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $model['variations'] as $v ) {
			$prices[] = (float) $v['price'];
			if ( $v['real'] ) {
				$real_cells[] = $v['color'] . '|' . $v['size'];
			}
			if ( null !== $v['stock'] && '' === $v['pool'] ) {
				$qty[ $v['color'] ][ (string) $v['size'] ] = $v['stock'];
				$units += $v['stock'];
			} else {
				$cell                   = $v['color'] . '|' . $v['size'];
				$shared_cells[]         = $cell;
				$shared_groups[ $cell ] = '' !== $v['pool'] ? (int) $v['group'] : 1;
				if ( null === $v['stock'] && null !== $model['shared'] ) {
					$pool_stock[ $v['color'] ] = (int) $model['shared'];
				}
			}
		}
		if ( null !== $model['shared'] ) {
			$units += (int) $model['shared']; // El total compartido, una sola vez, más lo de las tallas que llevan las suyas.
		}
		foreach ( $model['pools'] as $ckey => $n ) {
			$pool_stock[ $ckey ] = (int) $n; // Y cada bolsa de color, una vez.
			$units              += (int) $n;
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
		'package'     => dox_pos_product_package( $p ), // Peso y medidas; mixed si las tallas no llevan todas lo mismo.
		'sizes'       => $model['sizes'],
		'size_attr'   => $model['size_attr'], // Las filas (la primera opción): los valores que se pueden añadir son de este atributo.
		'color_attr'  => $model['color_attr'], // Las columnas (la segunda opción).
		'add_size_attr'  => $add_row,          // La opción de las filas que se le puede añadir (no tiene ninguna), o ''.
		'add_color_attr' => $add_col,          // Y la de las columnas.
		'colors'      => $model['colors'],
		'qty'         => (object) $qty,
		'shared'      => $model['shared'],  // El total del producto (null si cada talla lleva las suyas).
		'shared_cells' => $shared_cells,    // Qué tallas comparten unidades (del producto, o de una bolsa); el resto llevan las suyas y se editan.
		'shared_groups' => (object) $shared_groups,        // En qué grupo está cada una (1, 2...).
		'pool_stock'  => (object) $pool_stock,             // Las unidades compartidas por bolsa (columna de color, y "#2" del segundo grupo en adelante).
		'real_cells'  => $real_cells,                      // Qué talla es la real en cada grupo (clave color|talla).
		'real_qty'    => (object) dox_pos_real_qty_cells( $p ), // Cuántas prendas hay de cada talla real (null: sin repartir).
		'legacy_pool' => ! empty( $model['legacy_pool'] ), // Varios colores con un solo total: al guardar, cada color lleva el suyo.
		'images'      => $images,
		'variations'  => count( $model['variations'] ),
		'units'       => $units,
	);
}

/**
 * La tarjeta de un producto: lo que se enseña al tocar su nombre en cualquier parte de la caja (el
 * asistente, el historial, las entradas) sin salir de donde se está. Lo mismo que trae el buscador
 * (dox_pos_format_product) más el estado, las categorías, el enlace, las unidades y, para quien
 * administra, el costo.
 *
 * @param int $id Producto (o variación: se abre su padre).
 * @return array|WP_Error
 */
function dox_pos_product_card( $id ) {
	$p = wc_get_product( (int) $id );
	if ( $p && $p->is_type( 'variation' ) ) {
		$p = wc_get_product( $p->get_parent_id() );
	}
	if ( ! $p ) {
		return new WP_Error( 'dox_pos_no_existe', __( 'That product does not exist.', 'dox-pos' ) );
	}
	$card = dox_pos_format_product( $p->get_id() );
	$cats = array();
	foreach ( $p->get_category_ids() as $cid ) {
		$t = get_term( $cid, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) ) {
			$cats[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
		}
	}
	$labels = array( 'publish' => __( 'Published', 'dox-pos' ), 'private' => __( 'Hidden', 'dox-pos' ), 'draft' => __( 'Draft', 'dox-pos' ), 'pending' => __( 'Draft', 'dox-pos' ) );
	$status = $p->get_status();
	// Las unidades: las de cada talla, más el total del producto una sola vez si las tallas lo comparten.
	$units  = null;
	$shared = null;
	$pools  = array(); // Las bolsas de color: cada una cuenta una sola vez.
	foreach ( $card['variations'] as $v ) {
		if ( null === $v['stock'] ) {
			continue;
		}
		if ( ! empty( $v['shared'] ) && '*' !== $v['pool'] ) {
			$pools[ $v['pool'] ] = array( 'stock' => (int) $v['stock'], 'name' => $v['pool_name'] );
		} elseif ( ! empty( $v['shared'] ) ) {
			$shared = (int) $v['stock'];
		} else {
			$units = (int) $units + (int) $v['stock'];
		}
	}
	if ( null !== $shared ) {
		$units = (int) $units + $shared;
	}
	foreach ( $pools as $b ) {
		$units = (int) $units + $b['stock'];
	}
	$prices = array_values( array_unique( array_map( fn( $v ) => (float) $v['price'], $card['variations'] ) ) );
	$out    = $card + array(
		'image_large'  => $p->get_image_id() ? (string) wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_single' ) : '',
		'status'       => $status,
		'status_label' => $labels[ $status ] ?? $status,
		'url'          => 'publish' === $status ? get_permalink( $p->get_id() ) : '',
		'categories'   => $cats,
		'units'        => $units,
		'shared'       => $shared,
		'pool_names'   => array_values( array_unique( array_filter( array_column( $pools, 'name' ) ) ) ), // Los colores con bolsa propia.
		'price_min'    => $prices ? min( $prices ) : 0.0,
		'price_max'    => $prices ? max( $prices ) : 0.0,
		'editable'     => in_array( $p->get_type(), array( 'simple', 'variable' ), true ) && current_user_can( dox_pos_products_cap() ),
		'description'  => wp_trim_words( dox_pos_plain_text( $p->get_description( 'edit' ) ), 40 ),
		'package'      => dox_pos_product_package( $p ), // Peso y medidas, para el envío.
	);
	if ( dox_pos_can_see_costs() ) {
		$cs              = dox_pos_product_cost_summary( $p );
		$out['cost']     = $cs['cost']; // Uno solo; '' si las tallas cuestan distinto; null sin costo.
		$out['cost_min'] = $cs['min'];
		$out['cost_max'] = $cs['max'];
	}
	return $out;
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
 *                    categories, description, publish, sizes, colors, qty, images, shared_cells (las
 *                    tallas que salen del total del producto, clave color|talla) y shared_stock (ese total).
 * @return array|WP_Error
 */
function dox_pos_update_product( $id, $data ) {
	$p = wc_get_product( (int) $id );
	if ( ! $p || ! in_array( $p->get_type(), array( 'simple', 'variable' ), true ) ) {
		return new WP_Error( 'dox_pos_no_editable', __( 'That product cannot be edited from the register.', 'dox-pos' ) );
	}
	$s     = dox_pos_products_settings();
	$model = dox_pos_product_model( $p );
	if ( is_wp_error( $model ) ) {
		return $model;
	}
	// A un producto que no tiene una de las dos opciones (sin colores, o solo con colores) se le puede añadir: la que
	// mande la caja. Sus variaciones de hoy pasan al valor que se elige primero (adopt_size, adopt_color).
	$adopt = dox_pos_adopt_axis( $p, $model, $data ); // Puede cambiar la clave del color nuevo en $data por su id.
	if ( is_wp_error( $adopt ) ) {
		return $adopt;
	}
	if ( $adopt ) {
		$model = dox_pos_product_model( wc_get_product( $p->get_id() ) );
		if ( is_wp_error( $model ) ) {
			return $model;
		}
		$p = wc_get_product( $p->get_id() );
	}
	$size_tax  = $model['size_attr'];
	$color_tax = $model['color_attr'];
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
	// Qué tallas salen del total del producto (shared_cells, clave color|talla) y ese total (shared_stock), tal
	// como lo manda el formulario desde la 0.30. Sin shared_cells, ninguna talla cambia de sitio y solo se
	// escriben las que llevan las suyas; split_stock (0.27) equivale a "ninguna sale del total".
	$cells = null;
	if ( $variable && isset( $data['shared_cells'] ) && is_array( $data['shared_cells'] ) ) {
		$cells = array_map( 'strval', $data['shared_cells'] );
	} elseif ( $variable && ! empty( $data['split_stock'] ) ) {
		$cells = array();
	}

	// Los valores de cada opción: los que ya tenía, más los nuevos. Una opción que el producto no tiene solo se
	// añade con adopt (arriba): si no, sus variaciones quedarían para "cualquier" valor.
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
	// Con varios colores las unidades compartidas van por color (una bolsa por color, includes/pools.php); con
	// uno o ninguno, el total del producto. pool_stock trae las de cada columna de color.
	$pool_stock   = dox_pos_pool_stock_from( $data );
	$groups       = dox_pos_pool_groups_from( $data, $cells ); // El grupo de cada celda que comparte (1, 2...); vacío si el formulario no lo manda.
	$sync         = null !== $cells && $variable && ( count( $colors ) > 1 || ( $groups && max( $groups ) > 1 ) ); // Bolsas propias: varios colores, o más de un grupo.
	$pool         = null !== $cells && $cells && ! $sync ? max( 0, (int) ( $pool_stock ? reset( $pool_stock ) : ( $model['shared'] ?? 0 ) ) ) : null;
	$pools_logged = array(); // Las bolsas que el kardex ya apuntó en este guardado (una talla por bolsa).
	$reals        = $variable ? dox_pos_real_cells_from( $data ) : null; // La talla real de cada grupo; null: no se toca (el chat del Pro no la manda).
	// El slug del color, que es la marca de la bolsa; y las unidades de la bolsa de ese color: las del formulario, o las de hoy.
	$slug_of      = function ( $ckey ) use ( $colors, $color_tax ) {
		if ( isset( $colors[ $ckey ] ) && $colors[ $ckey ] instanceof WP_Term ) {
			return $colors[ $ckey ]->slug;
		}
		$t = get_term( (int) $ckey, $color_tax );
		return $t && ! is_wp_error( $t ) ? $t->slug : (string) $ckey;
	};
	// Las unidades de una bolsa ($pk: la columna de color y "#2" del segundo grupo en adelante): las del formulario, o las de hoy.
	$pool_of      = function ( $pk ) use ( $pool_stock, $model ) {
		return max( 0, (int) ( $pool_stock[ (string) $pk ] ?? ( $model['pools'][ (string) $pk ] ?? ( $model['shared'] ?? 0 ) ) ) );
	};
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
		if ( null !== $cells ) {
			if ( $cells && ! $sync ) { // Alguna talla sale del total: lo lleva el producto.
				$p->set_manage_stock( true );
				$p->set_stock_quantity( $pool );
				$p->set_stock_status( $pool > 0 ? 'instock' : 'outofstock' );
			} else { // Ninguna, o las bolsas van por color: el producto deja de llevarlas.
				$p->set_manage_stock( false );
				$p->set_stock_quantity( null );
			}
		}
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
	$pkg = dox_pos_package_from( $data ); // Null: el formulario no lo tocó.
	if ( null !== $pkg ) {
		dox_pos_set_package( $p, $pkg );
	}
	try {
		$pid = $p->save();
	} catch ( Exception $e ) {
		dox_pos_stock_context_end( $ctx );
		/* translators: %s: reason */
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
		// Las variaciones que ya existen: precio, unidades e imagen del color. Primero las que siguen en la misma bolsa:
		// así un cambio en el número de la bolsa lo apunta el kardex en una de ellas, antes de que entre (callada) una
		// talla nueva y deje a las demás ya igualadas sin que nadie lo apunte.
		$known  = array_values( array_unique( array_merge( $before, array_column( $images, 'id' ) ) ) );
		$stays  = function ( $vr ) use ( $cells, $groups, $sync, $slug_of ) {
			if ( null === $cells || ! $sync || '' === $vr['pool'] ) {
				return 0;
			}
			$g = (int) ( $groups[ $vr['color'] . '|' . $vr['size'] ] ?? 0 );
			return $g > 0 && dox_pos_pool_key_for( $slug_of( $vr['color'] ), $g ) === $vr['pool'] ? 1 : 0;
		};
		$ordered = $model['variations'];
		usort( $ordered, fn( $a, $b ) => $stays( $b ) <=> $stays( $a ) ); // usort es estable desde PHP 8: el resto sigue en su orden.
		foreach ( $ordered as $vr ) {
			$v = wc_get_product( $vr['id'] );
			if ( ! $v ) {
				continue;
			}
			$changed = false;
			if ( '' !== $price && (float) $vr['price'] !== (float) $price ) {
				$v->set_regular_price( $price );
				$changed = true;
			}
			// Cada medida escrita va a todo el producto: la talla que llevaba la suya la suelta y hereda la nueva.
			// Las que no se tocaron se quedan como estaban, también en las tallas.
			if ( null !== $pkg ) {
				$drop = array();
				foreach ( array_keys( $pkg ) as $k ) {
					if ( '' !== (string) $v->{"get_$k"}( 'edit' ) ) {
						$drop[ $k ] = '';
					}
				}
				if ( $drop ) {
					dox_pos_set_package( $v, $drop );
					$changed = true;
				}
			}
			// Comparte unidades (el total del producto, o la bolsa de su color) o lleva las suyas: lo que diga el
			// formulario (shared_cells); si no lo manda, cada talla se queda como está y solo se escriben las suyas.
			$cell     = $vr['color'] . '|' . $vr['size'];
			$was_pool = '' !== $vr['pool'];
			// Qué quiere el formulario para esta talla: un grupo (1, 2...) o las suyas (0). Sin formulario, como está.
			$g       = null !== $cells ? (int) ( $groups[ $cell ] ?? 0 ) : ( $was_pool ? (int) $vr['group'] : ( null === $vr['stock'] ? 1 : 0 ) );
			$inherit = $g > 0;
			$quiet   = false; // Cambiar de sitio no es un movimiento: el kardex no lo apunta.
			if ( $inherit && $sync ) { // Una bolsa propia: la de su color y grupo.
				$pk   = $vr['color'] . ( $g > 1 ? '#' . $g : '' );
				$want = dox_pos_pool_key_for( $slug_of( $vr['color'] ), $g );
				$n    = $pool_of( $pk );
				if ( $vr['pool'] !== $want ) { // Entra en la bolsa, o cambia de grupo.
					$quiet = true;
					$v->set_manage_stock( true );
					$v->update_meta_data( DOX_POS_POOL_META, $want );
					$changed = true;
				} elseif ( isset( $pools_logged[ $pk ] ) ) {
					$quiet = true; // La bolsa ya se apuntó en su primera talla; las demás la siguen calladas.
				}
				if ( $n !== (int) $v->get_stock_quantity() || $vr['pool'] !== $want ) {
					$v->set_stock_quantity( $n );
					$v->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
					$changed = true;
				}
				$pools_logged[ $pk ] = true;
			} elseif ( $inherit && null !== $cells ) { // El total del producto (un color, un grupo).
				if ( null !== $vr['stock'] ) {
					$quiet = true;
					$v->set_manage_stock( false ); // Pasa a salir del total del producto; sus unidades sueltas se olvidan.
					$v->set_stock_quantity( null );
					$v->delete_meta_data( DOX_POS_POOL_META );
					$changed = true;
				}
			} elseif ( ! $inherit ) {
				$row = $qty[ $vr['color'] ] ?? null;
				if ( is_array( $row ) && array_key_exists( (string) $vr['size'], $row ) ) {
					$n = max( 0, (int) $row[ (string) $vr['size'] ] );
					if ( null === $vr['stock'] || $was_pool ) {
						$quiet = true;
						$v->set_manage_stock( true ); // Deja de compartir: desde ahora lleva las suyas.
						$v->delete_meta_data( DOX_POS_POOL_META );
						$changed = true;
					}
					if ( $n !== $vr['stock'] || $was_pool ) {
						$v->set_stock_quantity( $n );
						$v->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
						$changed = true;
					}
				}
			}
			// La talla real: se decide entre las tallas que comparten (puede haber varias en un grupo). La que no comparte
			// conserva su marca tal cual: ahí no dice nada, y vuelve a valer si un día comparte otra vez. Es un meta de la
			// variación, no un movimiento: no toca existencias ni el kardex.
			if ( null !== $reals && $inherit ) {
				$real = in_array( $cell, $reals, true );
				if ( $real !== $vr['real'] ) {
					if ( $real ) {
						$v->update_meta_data( DOX_POS_REAL_SIZE_META, 'yes' );
					} else {
						$v->delete_meta_data( DOX_POS_REAL_SIZE_META );
					}
					$changed = true;
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
				if ( $quiet ) {
					dox_pos_stock_quiet( true );
				}
				$v->save();
				if ( $quiet ) {
					dox_pos_stock_quiet( false );
					dox_pos_pool_forget(); // Cambió de sitio: quién está en cada bolsa ya no es lo recordado.
				}
			}
		}
		dox_pos_pool_forget();
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
				$g       = null !== $cells ? (int) ( $groups[ $ckey . '|' . $sid ] ?? 0 ) : ( null !== $model['shared'] ? 1 : 0 );
				$inherit = $g > 0;
				$quiet   = false;
				if ( $inherit && $sync ) { // Entra en una bolsa propia: la de su color y grupo.
					$pk = (string) $ckey . ( $g > 1 ? '#' . $g : '' );
					$n  = $pool_of( $pk );
					$v->set_manage_stock( true );
					$v->set_stock_quantity( $n );
					$v->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
					$v->update_meta_data( DOX_POS_POOL_META, dox_pos_pool_key_for( $cterm ? $cterm->slug : '', $g ) );
					// El kardex apunta la bolsa una vez: si ya existía o ya se apuntó, esta talla entra callada.
					$quiet               = isset( $model['pools'][ $pk ] ) || isset( $pools_logged[ $pk ] );
					$pools_logged[ $pk ] = true;
				} elseif ( ! $inherit ) {
					$n = dox_pos_qty_at( $qty, (string) $ckey, (string) $sid );
					$v->set_manage_stock( true );
					$v->set_stock_quantity( $n );
					$v->set_stock_status( $n > 0 ? 'instock' : 'outofstock' );
				} else {
					$v->set_manage_stock( false ); // Sale del total del producto.
				}
				if ( $inherit && $reals && in_array( $ckey . '|' . $sid, $reals, true ) ) {
					$v->update_meta_data( DOX_POS_REAL_SIZE_META, 'yes' );
				}
				if ( $cterm && isset( $by_color[ $ckey ] ) ) {
					$v->set_image_id( $by_color[ $ckey ] );
				}
				if ( $quiet ) {
					dox_pos_stock_quiet( true );
				}
				$v->save();
				if ( $quiet ) {
					dox_pos_stock_quiet( false );
				}
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
		dox_pos_pool_forget();
		dox_pos_real_size_ids( 0, true );
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

/**
 * Añade a un producto la opción que no tiene (colores a un producto que solo varía por talla, o al revés):
 * el atributo pasa al producto con el valor elegido primero, y todas sus variaciones de hoy toman ese valor.
 * Así no queda ninguna para "cualquier" color y sus unidades, códigos e historial siguen donde estaban. Los
 * demás valores se añaden después como cualquier valor nuevo.
 *
 * @param WC_Product $p     Producto variable.
 * @param array      $model Su modelo (dox_pos_product_model).
 * @param array      $data  Lo que manda la caja: size_attr y adopt_size (id), o color_attr y adopt_color (clave: id o "n:nombre").
 *                          Si el valor era nuevo ("n:negro"), su clave pasa a ser su id en todo $data.
 * @return bool|WP_Error true si se añadió algo.
 */
function dox_pos_adopt_axis( $p, $model, &$data ) {
	if ( ! $p->is_type( 'variable' ) || ! $model['variations'] ) {
		return false;
	}
	$all  = dox_pos_attribute_taxonomies();
	$done = false;
	foreach ( array( 'size', 'color' ) as $axis ) {
		$have = $model[ $axis . '_attr' ];
		$tax  = (string) ( $data[ $axis . '_attr' ] ?? '' );
		$pick = $data[ 'adopt_' . $axis ] ?? '';
		if ( '' !== $have || '' === $tax || '' === (string) $pick || ! isset( $all[ $tax ] ) ) {
			continue;
		}
		$other = $model[ ( 'size' === $axis ? 'color' : 'size' ) . '_attr' ];
		if ( $tax === $other || isset( $p->get_attributes()[ $tax ] ) ) {
			return new WP_Error( 'dox_pos_varia', __( 'That option is already in the product.', 'dox-pos' ) );
		}
		$t = null;
		if ( 'color' === $axis ) {
			foreach ( (array) ( $data['colors'] ?? array() ) as $c ) {
				$c = (array) $c;
				if ( (string) ( $c['key'] ?? '' ) !== (string) $pick ) {
					continue;
				}
				$t = (int) ( $c['id'] ?? 0 ) ? get_term( (int) $c['id'], $tax ) : dox_pos_create_color( sanitize_text_field( (string) ( $c['name'] ?? '' ) ), (string) ( $c['hex'] ?? '' ), $tax );
				break;
			}
		} else {
			$t = get_term( (int) $pick, $tax );
		}
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		if ( ! $t instanceof WP_Term ) {
			return new WP_Error( 'dox_pos_varia', __( 'The new option could not be added: reload and try again.', 'dox-pos' ) );
		}
		$attrs         = $p->get_attributes();
		$attrs[ $tax ] = dox_pos_make_attribute( $tax, array( (int) $t->term_id ), 'size' === $axis ? 0 : count( $attrs ) );
		$p->set_attributes( $attrs );
		$p->save();
		wp_set_object_terms( $p->get_id(), array( (int) $t->term_id ), $tax, true );
		foreach ( $model['variations'] as $vr ) {
			$v = wc_get_product( $vr['id'] );
			if ( ! $v ) {
				continue;
			}
			$va         = $v->get_attributes();
			$va[ $tax ] = $t->slug;
			$v->set_attributes( $va );
			dox_pos_stock_quiet( true ); // No es un movimiento de unidades: el kardex no lo apunta.
			$v->save();
			dox_pos_stock_quiet( false );
		}
		if ( 'color' === $axis && (string) $pick !== (string) $t->term_id ) {
			$data = dox_pos_rename_column( $data, (string) $pick, (string) $t->term_id );
		}
		$model[ $axis . '_attr' ] = $tax;
		$done                     = true;
	}
	if ( $done ) {
		wc_delete_product_transients( $p->get_id() );
	}
	return $done;
}

/**
 * Cambia la clave de una columna en todo lo que manda la caja ("n:negro" pasa a "123" cuando el color ya existe).
 *
 * @param array  $data Lo que llegó.
 * @param string $old  Clave de antes.
 * @param string $to   Clave nueva.
 * @return array
 */
function dox_pos_rename_column( $data, $old, $to ) {
	$cell = fn( $k ) => 0 === strpos( (string) $k, $old . '|' ) ? $to . substr( (string) $k, strlen( $old ) ) : (string) $k;
	$pool = fn( $k ) => (string) $k === $old || 0 === strpos( (string) $k, $old . '#' ) ? $to . substr( (string) $k, strlen( $old ) ) : (string) $k;
	foreach ( (array) ( $data['colors'] ?? array() ) as $i => $c ) {
		if ( (string) ( ( (array) $c )['key'] ?? '' ) === $old ) {
			$data['colors'][ $i ] = array_merge( (array) $c, array( 'key' => $to, 'id' => (int) $to ) );
		}
	}
	if ( isset( $data['qty'][ $old ] ) ) {
		$data['qty'][ $to ] = $data['qty'][ $old ];
		unset( $data['qty'][ $old ] );
	}
	foreach ( array( 'shared_cells', 'real_cells' ) as $k ) {
		if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
			$data[ $k ] = array_map( $cell, $data[ $k ] );
		}
	}
	foreach ( array( 'shared_groups' => $cell, 'real_qty' => $cell, 'pool_stock' => $pool ) as $k => $fn ) {
		if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
			$data[ $k ] = array_combine( array_map( $fn, array_keys( $data[ $k ] ) ), array_values( $data[ $k ] ) );
		}
	}
	foreach ( (array) ( $data['images'] ?? array() ) as $i => $im ) {
		if ( (string) ( ( (array) $im )['color'] ?? '' ) === $old ) {
			$data['images'][ $i ] = array_merge( (array) $im, array( 'color' => $to ) );
		}
	}
	return $data;
}

/**
 * Las unidades compartidas que manda el formulario, por columna de color (pool_stock: {clave: n}; la clave
 * es '' cuando el producto no tiene colores). Los formularios anteriores a la 0.32 mandaban un solo shared_stock.
 *
 * @param array $data Lo que llegó.
 * @return array clave => unidades
 */
function dox_pos_pool_stock_from( $data ) {
	$out = array();
	if ( isset( $data['pool_stock'] ) && is_array( $data['pool_stock'] ) ) {
		foreach ( $data['pool_stock'] as $k => $n ) {
			$out[ (string) $k ] = max( 0, (int) $n );
		}
	}
	if ( ! $out && isset( $data['shared_stock'] ) && '' !== $data['shared_stock'] && null !== $data['shared_stock'] ) {
		$out['*'] = max( 0, (int) $data['shared_stock'] );
	}
	return $out;
}

/**
 * La talla real que marca el formulario en cada grupo que comparte (real_cells: claves color|talla). Null si no
 * lo manda: así un guardado sin ese dato (el chat del Pro, un formulario anterior a la 0.49) no la cambia.
 *
 * @param array $data Lo que llegó.
 * @return string[]|null
 */
function dox_pos_real_cells_from( $data ) {
	if ( ! isset( $data['real_cells'] ) || ! is_array( $data['real_cells'] ) ) {
		return null;
	}
	return array_values( array_unique( array_map( 'strval', $data['real_cells'] ) ) ); // Igual que shared_cells: solo se compara, no se guarda.
}

/**
 * En qué grupo comparte cada talla, según el formulario: shared_groups {celda: grupo}; los formularios que solo
 * mandan shared_cells ponen todas en el grupo 1.
 *
 * @param array      $data  Lo que llegó.
 * @param array|null $cells Las celdas que comparten (shared_cells), ya leídas.
 * @return array celda => grupo (1, 2...)
 */
function dox_pos_pool_groups_from( $data, $cells ) {
	$out = array();
	if ( isset( $data['shared_groups'] ) && is_array( $data['shared_groups'] ) ) {
		foreach ( $data['shared_groups'] as $cell => $g ) {
			if ( (int) $g > 0 ) {
				$out[ (string) $cell ] = min( 9, (int) $g );
			}
		}
	}
	if ( ! $out && is_array( $cells ) ) {
		foreach ( $cells as $cell ) {
			if ( '*' !== $cell ) {
				$out[ (string) $cell ] = 1;
			}
		}
	}
	return $out;
}
