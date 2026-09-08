<?php
/**
 * El inventario en Excel: una fila por variación (o por producto simple) con referencia,
 * código, talla, color, categoría, precio, costo (para quien administra), existencias y su
 * valor, y una segunda hoja con el resumen por categoría. La columna Costo se puede llenar y
 * subir de vuelta desde la caja para cargar los costos de golpe (costs.php). El .xlsx se arma a mano (es un zip con unos XML), sin
 * bibliotecas: abre en Excel, Numbers y Google Sheets. Las funciones dox_pos_xlsx_* de
 * abajo las usa también el historial (history.php) para sus tres informes.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manda el archivo al navegador y termina. Se llama desde /caja/?descargar=inventario,
 * ya con la sesión y el permiso de la caja comprobados.
 */
function dox_pos_send_inventory() {
	$data = dox_pos_inventory_data();
	$file = dox_pos_inventory_xlsx( $data );
	dox_pos_send_xlsx( $file, sanitize_file_name( 'inventario-' . sanitize_title( remove_accents( dox_pos_brand_name() ) ) . '-' . wp_date( 'Y-m-d' ) . '.xlsx' ) );
}

/**
 * Manda un .xlsx ya armado al navegador y termina.
 *
 * @param string $file El archivo.
 * @param string $name Cómo se llama.
 */
function dox_pos_send_xlsx( $file, $name ) {
	while ( ob_get_level() ) { // Que ningún optimizador de HTML toque un archivo binario.
		ob_end_clean();
	}
	nocache_headers();
	header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
	header( 'Content-Disposition: attachment; filename="' . $name . '"' );
	header( 'Content-Length: ' . strlen( $file ) );
	echo $file; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Es el archivo, no HTML.
	exit;
}

/**
 * Las filas del inventario, leídas directo de la base (para 3.000 variaciones tarda
 * décimas de segundo; cargar cada objeto de WooCommerce tardaría varios segundos).
 *
 * @return array{rows: array, cats: array, size_label: string, color_label: string, others: bool}
 */
function dox_pos_inventory_data() {
	global $wpdb;
	$see       = dox_pos_can_see_costs(); // El costo solo va para quien administra.
	$size_tax  = dox_pos_size_attribute();
	$color_tax = dox_pos_color_attribute();
	$taxes     = dox_pos_attribute_taxonomies();

	$posts = $wpdb->get_results(
		"SELECT p.ID, p.post_parent, p.post_title, p.post_status, p.post_type,
			MAX( CASE WHEN m.meta_key = '_sku' THEN m.meta_value END ) AS sku,
			MAX( CASE WHEN m.meta_key = '_stock' THEN m.meta_value END ) AS stock,
			MAX( CASE WHEN m.meta_key = '_manage_stock' THEN m.meta_value END ) AS manage,
			MAX( CASE WHEN m.meta_key = '_stock_status' THEN m.meta_value END ) AS stock_status,
			MAX( CASE WHEN m.meta_key = '_price' THEN m.meta_value END ) AS price,
			MAX( CASE WHEN m.meta_key = '_cogs_total_value' THEN m.meta_value END ) AS cost
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( '_sku', '_stock', '_manage_stock', '_stock_status', '_price', '_cogs_total_value' )
		WHERE p.post_type IN ( 'product', 'product_variation' ) AND p.post_status IN ( 'publish', 'private', 'draft', 'pending' )
		GROUP BY p.ID",
		ARRAY_A
	);

	// Los atributos de cada variación (attribute_pa_talla = 6-12-meses).
	$attrs = array();
	$metas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m
			JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'product_variation'
			WHERE m.meta_key LIKE %s",
			$wpdb->esc_like( 'attribute_' ) . '%'
		),
		ARRAY_A
	);
	foreach ( $metas as $m ) {
		$attrs[ (int) $m['post_id'] ][ substr( $m['meta_key'], 10 ) ] = (string) $m['meta_value'];
	}

	// El nombre de cada término de atributo global (el slug es lo que guarda la variación).
	$names = array();
	$terms = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.slug, t.name, tt.taxonomy FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy LIKE %s",
			$wpdb->esc_like( 'pa_' ) . '%'
		),
		ARRAY_A
	);
	foreach ( $terms as $t ) {
		$names[ $t['taxonomy'] ][ $t['slug'] ] = html_entity_decode( $t['name'], ENT_QUOTES, 'UTF-8' );
	}

	// Categorías con su ruta (Niñas › Vestidos) y la de cada producto: la más específica.
	$cats = $wpdb->get_results( "SELECT t.term_id, t.name, tt.parent FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'product_cat'", ARRAY_A );
	$cat  = array();
	foreach ( $cats as $c ) {
		$cat[ (int) $c['term_id'] ] = array( 'name' => html_entity_decode( $c['name'], ENT_QUOTES, 'UTF-8' ), 'parent' => (int) $c['parent'] );
	}
	$path = array();
	foreach ( $cat as $id => $c ) {
		$parts = array();
		$cur   = $id;
		for ( $i = 0; $i < 10 && $cur && isset( $cat[ $cur ] ); $i++ ) {
			array_unshift( $parts, $cat[ $cur ]['name'] );
			$cur = $cat[ $cur ]['parent'];
		}
		$path[ $id ] = $parts;
	}
	$product_cat = array();
	$rels        = $wpdb->get_results( "SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat'", ARRAY_A );
	foreach ( $rels as $r ) {
		$pid = (int) $r['object_id'];
		$p   = $path[ (int) $r['term_id'] ] ?? array();
		if ( ! $p ) {
			continue;
		}
		$cur = $product_cat[ $pid ] ?? array();
		if ( ! $cur || count( $p ) > count( $cur ) || ( count( $p ) === count( $cur ) && strcmp( implode( ' › ', $p ), implode( ' › ', $cur ) ) < 0 ) ) {
			$product_cat[ $pid ] = $p;
		}
	}

	$by_id = array();
	$kids  = array();
	foreach ( $posts as $r ) {
		$by_id[ (int) $r['ID'] ] = $r;
		if ( 'product_variation' === $r['post_type'] && $r['post_parent'] ) {
			$kids[ (int) $r['post_parent'] ] = true;
		}
	}
	$estado = array(
		'publish' => __( 'Published', 'dox-pos' ),
		'private' => __( 'Hidden', 'dox-pos' ),
		'draft'   => __( 'Draft', 'dox-pos' ),
		'pending' => __( 'Pending', 'dox-pos' ),
	);
	$rows   = array();
	$others = false;
	foreach ( $posts as $r ) {
		$id = (int) $r['ID'];
		if ( 'product' === $r['post_type'] && isset( $kids[ $id ] ) ) {
			continue; // El padre de un variable no tiene existencias propias: van en cada variación.
		}
		$parent = 'product_variation' === $r['post_type'] ? ( $by_id[ (int) $r['post_parent'] ] ?? null ) : null;
		if ( 'product_variation' === $r['post_type'] && ! $parent ) {
			continue; // Variación sin padre.
		}
		$main  = $parent ? $parent : $r;
		$stock = null;
		if ( 'yes' === $r['manage'] ) {
			$stock = (int) $r['stock'];
		} elseif ( $parent && 'yes' === $parent['manage'] ) {
			$stock = (int) $parent['stock'];
		}
		$price = '' !== (string) $r['price'] ? (float) $r['price'] : (float) ( $main['price'] ?? 0 );
		$cost  = null;
		if ( $see ) { // El costo por unidad: el de la talla, o el del producto si la talla no tiene uno propio.
			if ( null !== $r['cost'] && '' !== (string) $r['cost'] ) {
				$cost = (float) $r['cost'];
			} elseif ( $parent && null !== $parent['cost'] && '' !== (string) $parent['cost'] ) {
				$cost = (float) $parent['cost'];
			}
		}
		$size  = '';
		$color = '';
		$extra = array();
		foreach ( $attrs[ $id ] ?? array() as $tax => $slug ) {
			if ( '' === $slug ) {
				continue;
			}
			$label = $names[ $tax ][ $slug ] ?? $slug;
			if ( $tax === $size_tax ) {
				$size = $label;
			} elseif ( $tax === $color_tax ) {
				$color = $label;
			} else {
				$extra[] = $label;
			}
		}
		if ( $extra ) {
			$others = true;
		}
		if ( 'publish' !== $main['post_status'] ) {
			$st = $estado[ $main['post_status'] ] ?? $main['post_status'];
		} elseif ( $parent && 'publish' !== $r['post_status'] ) {
			$st = __( 'Disabled', 'dox-pos' );
		} elseif ( null === $stock && 'outofstock' === $r['stock_status'] ) {
			$st = __( 'Out of stock', 'dox-pos' );
		} else {
			$st = $estado['publish'];
		}
		$cpath  = $product_cat[ (int) $main['ID'] ] ?? array();
		$rows[] = array(
			'ref'    => (string) $main['sku'],
			// Sin código va "(sin código)": el total del Excel suma las filas cuya casilla de código no está vacía
			// (las de producto la llevan vacía), así que una fila sin nada se quedaría fuera de la cuenta.
			'sku'    => '' !== (string) $r['sku'] ? (string) $r['sku'] : ( '' !== (string) $main['sku'] ? (string) $main['sku'] : __( '(no SKU)', 'dox-pos' ) ),
			'name'   => html_entity_decode( $main['post_title'], ENT_QUOTES, 'UTF-8' ),
			'size'   => $size,
			'color'  => $color,
			'others' => implode( ', ', $extra ),
			'cat'    => implode( ' › ', $cpath ),
			'price'  => $price,
			'stock'  => $stock,
			'value'  => null === $stock ? null : $stock * $price,
			'cost'   => $cost,
			'cost_value' => null === $stock || null === $cost ? null : $stock * $cost,
			'status' => $st,
			'pid'    => (int) $main['ID'],
			'shared' => $parent && 'yes' !== $r['manage'] && 'yes' === $parent['manage'], // Hereda las existencias del padre.
			'variable' => (bool) $parent,
			'pstatus'  => $estado[ $main['post_status'] ] ?? $main['post_status'],
			'sort'   => array( implode( ' › ', $cpath ), $main['post_title'], (int) $main['ID'], dox_pos_size_sort_key( $size ), $color, (string) $r['sku'] ),
		);
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return $a['sort'] <=> $b['sort'];
		}
	);

	// Las variaciones que comparten las existencias del padre llevan la cifra una sola vez (en la
	// primera talla); si no, el total las contaría tantas veces como tallas tenga el producto.
	$seen = array();
	foreach ( $rows as &$row ) {
		if ( empty( $row['shared'] ) ) {
			continue;
		}
		if ( isset( $seen[ $row['pid'] ] ) ) {
			$row['stock']      = null;
			$row['value']      = null;
			$row['cost_value'] = null;
			if ( $estado['publish'] === $row['status'] ) {
				$row['status'] = __( 'Shares stock', 'dox-pos' );
			}
		}
		$seen[ $row['pid'] ] = true;
	}
	unset( $row );

	// El enlace a la ficha de cada producto, para que el Excel lleve a la tienda.
	$urls = dox_pos_product_urls( array_column( $rows, 'pid' ) );
	foreach ( $rows as &$row ) {
		$row['url'] = $urls[ $row['pid'] ] ?? '';
	}
	unset( $row );

	// Resumen por categoría: referencias distintas, unidades y valor.
	$sum = array();
	foreach ( $rows as $r ) {
		$k = $r['cat'];
		if ( ! isset( $sum[ $k ] ) ) {
			$sum[ $k ] = array( 'cat' => $k, 'refs' => array(), 'units' => 0, 'value' => 0, 'cost_value' => 0 );
		}
		$sum[ $k ]['refs'][ $r['pid'] ] = true;
		$sum[ $k ]['units']            += (int) $r['stock'];
		$sum[ $k ]['value']            += (float) $r['value'];
		$sum[ $k ]['cost_value']       += (float) $r['cost_value'];
	}
	ksort( $sum, SORT_NATURAL | SORT_FLAG_CASE );
	$summary = array();
	foreach ( $sum as $s ) {
		$summary[] = array( 'cat' => $s['cat'], 'refs' => count( $s['refs'] ), 'units' => $s['units'], 'value' => $s['value'], 'cost_value' => $s['cost_value'] );
	}

	return array(
		'rows'        => $rows,
		'cats'        => $summary,
		'size_label'  => $size_tax && isset( $taxes[ $size_tax ] ) ? $taxes[ $size_tax ] : __( 'Size', 'dox-pos' ),
		'color_label' => $color_tax && isset( $taxes[ $color_tax ] ) ? $taxes[ $color_tax ] : __( 'Color', 'dox-pos' ),
		'others'      => $others,
		'costs'       => $see,
	);
}

/**
 * La dirección de la ficha de cada producto. Se cargan todos los posts de una vez (una consulta)
 * para que get_permalink no vaya uno por uno, y así sale la misma dirección que usa la tienda,
 * sea cual sea su estructura de enlaces.
 *
 * @param int[] $ids Ids de producto (se repiten sin problema).
 * @return array id => url.
 */
function dox_pos_product_urls( $ids ) {
	$ids  = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	$out  = array();
	foreach ( array_chunk( $ids, 200 ) as $chunk ) {
		_prime_post_caches( $chunk, false, false );
		foreach ( $chunk as $id ) {
			$url = get_permalink( $id );
			if ( $url ) {
				$out[ $id ] = $url;
			}
		}
	}
	return $out;
}

/**
 * Arma el .xlsx: dos hojas, encabezado fijo con filtro, precios con formato de moneda,
 * fórmulas de valor y totales (con su resultado ya calculado, por si el programa no recalcula).
 */
function dox_pos_inventory_xlsx( $d ) {
	$costs = ! empty( $d['costs'] );
	// Hoja 1: el inventario. Cada producto es un bloque: su fila (en negrita, con fondo y los
	// subtotales) y debajo una fila por talla y color, agrupadas para poder plegarlas con el +/- de Excel.
	$cols = array(
		array( __( 'Ref.', 'dox-pos' ), 'ref', 'text', 10 ),
		array( __( 'SKU', 'dox-pos' ), 'sku', 'text', 13 ),
		array( __( 'Product', 'dox-pos' ), 'name', 'text', 36 ),
		array( $d['size_label'], 'size', 'text', 15 ),
		array( $d['color_label'], 'color', 'text', 15 ),
	);
	if ( $d['others'] ) {
		$cols[] = array( __( 'Other attributes', 'dox-pos' ), 'others', 'text', 18 );
	}
	$cols[] = array( __( 'Category', 'dox-pos' ), 'cat', 'text', 30 );
	$cols[] = array( __( 'Price', 'dox-pos' ), 'price', 'money', 12 );
	if ( $costs ) {
		$cols[] = array( __( 'Cost', 'dox-pos' ), 'cost', 'cost', 12 ); // Se llena y se sube de vuelta desde la caja para cargar los costos.
	}
	$cols[] = array( __( 'Stock', 'dox-pos' ), 'stock', 'int', 12 );
	$cols[] = array( __( 'Value', 'dox-pos' ), 'value', 'value', 14 );
	if ( $costs ) {
		$cols[] = array( __( 'Value at cost', 'dox-pos' ), 'cost_value', 'cvalue', 14 );
	}
	$cols[] = array( __( 'Status', 'dox-pos' ), 'status', 'text', 20 );

	$ci = array();
	foreach ( $cols as $i => $c ) {
		$ci[ $c[1] ] = dox_pos_xlsx_col( $i );
	}
	$links = array(); // El nombre de cada producto lleva a su ficha en la tienda.
	// Estilos (ver styles.xml): 7 producto negrita, 8 producto texto, 9 producto moneda, 10 producto entero, 11 texto gris.
	$lines = array();
	$n     = 1;
	$units = 0;
	$value = 0;
	$cval  = 0;
	$rows  = $d['rows'];
	$count = count( $rows );
	$i     = 0;
	while ( $i < $count ) {
		$r = $rows[ $i ];
		$j = $i;
		while ( $j < $count && $rows[ $j ]['pid'] === $r['pid'] ) {
			$j++;
		}
		$block = array_slice( $rows, $i, $j - $i );
		$i     = $j;
		if ( ! $r['variable'] ) {
			// Producto de talla única: una sola fila, con el estilo de producto.
			$n++;
			$cells = '';
			foreach ( $cols as $k => $c ) {
				$ref = dox_pos_xlsx_col( $k ) . $n;
				switch ( $c[2] ) {
					case 'money':
						$cells .= dox_pos_xlsx_cell( $ref, $r['price'], 9 );
						break;
					case 'cost':
						$cells .= dox_pos_xlsx_cell( $ref, $r['cost'], 9 );
						break;
					case 'int':
						$cells .= dox_pos_xlsx_cell( $ref, $r['stock'], 10 );
						break;
					case 'value':
						$cells .= null === $r['stock'] ? dox_pos_xlsx_cell( $ref, null, 9 ) : dox_pos_xlsx_cell( $ref, $r['value'], 9, $ci['price'] . $n . '*' . $ci['stock'] . $n );
						break;
					case 'cvalue':
						$cells .= null === $r['stock'] || null === $r['cost'] ? dox_pos_xlsx_cell( $ref, null, 9 ) : dox_pos_xlsx_cell( $ref, $r['cost_value'], 9, $ci['cost'] . $n . '*' . $ci['stock'] . $n );
						break;
					default:
						if ( 'name' === $c[1] && ! empty( $r['url'] ) ) {
							$links[ $ref ] = $r['url'];
							$cells        .= dox_pos_xlsx_cell( $ref, $r['name'], 12 );
							break;
						}
						$cells .= dox_pos_xlsx_cell( $ref, $r[ $c[1] ], in_array( $c[1], array( 'ref', 'name' ), true ) ? 7 : 8 );
				}
			}
			$lines[] = '<row r="' . $n . '">' . $cells . '</row>';
			$units  += (int) $r['stock'];
			$value  += (float) $r['value'];
			$cval   += (float) ( $r['cost_value'] ?? 0 );
			continue;
		}
		// Producto con tallas o colores: la fila del producto con sus subtotales...
		$n++;
		$first  = $n + 1;
		$last_b = $n + count( $block );
		$bu     = 0;
		$bv     = 0;
		$bc     = 0;
		$sizes  = array();
		$colors = array();
		$prices = array();
		$bcosts = array();
		foreach ( $block as $b ) {
			$bu += (int) $b['stock'];
			$bv += (float) $b['value'];
			$bc += (float) ( $b['cost_value'] ?? 0 );
			if ( '' !== $b['size'] ) {
				$sizes[ $b['size'] ] = true;
			}
			if ( '' !== $b['color'] ) {
				$colors[ $b['color'] ] = true;
			}
			if ( $b['price'] > 0 ) {
				$prices[ (string) $b['price'] ] = true;
			}
			if ( isset( $b['cost'] ) && null !== $b['cost'] ) {
				$bcosts[ (string) $b['cost'] ] = true;
			}
		}
		$ns    = count( $sizes );
		$nc    = count( $colors );
		// El costo del producto: uno solo si todas las tallas lo tienen y es el mismo.
		$bcost = 1 === count( $bcosts ) && count( $block ) === count( array_filter( $block, fn( $x ) => isset( $x['cost'] ) && null !== $x['cost'] ) ) ? (float) array_key_first( $bcosts ) : null;
		$cells = '';
		foreach ( $cols as $k => $c ) {
			$ref = dox_pos_xlsx_col( $k ) . $n;
			switch ( $c[1] ) {
				case 'name':
					if ( ! empty( $r['url'] ) ) {
						$links[ $ref ] = $r['url'];
						$cells        .= dox_pos_xlsx_cell( $ref, $r['name'], 12 );
						break;
					}
					$cells .= dox_pos_xlsx_cell( $ref, $r['name'], 7 );
					break;
				case 'ref':
					$cells .= dox_pos_xlsx_cell( $ref, $r['ref'], 7 );
					break;
				case 'size':
					/* translators: %d: cuántas tallas */
					$cells .= dox_pos_xlsx_cell( $ref, $ns ? sprintf( _n( '%d size', '%d sizes', $ns, 'dox-pos' ), $ns ) : '', 8 );
					break;
				case 'color':
					/* translators: %d: cuántos colores */
					$cells .= dox_pos_xlsx_cell( $ref, $nc ? sprintf( _n( '%d color', '%d colors', $nc, 'dox-pos' ), $nc ) : '', 8 );
					break;
				case 'price':
					$cells .= dox_pos_xlsx_cell( $ref, 1 === count( $prices ) ? (float) array_key_first( $prices ) : null, 9 ); // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- La comparacion ya va en Yoda.
					break;
				case 'cost':
					$cells .= dox_pos_xlsx_cell( $ref, $bcost, 9 );
					break;
				case 'stock':
					$cells .= dox_pos_xlsx_cell( $ref, $bu, 10, 'SUM(' . $ci['stock'] . $first . ':' . $ci['stock'] . $last_b . ')' );
					break;
				case 'value':
					$cells .= dox_pos_xlsx_cell( $ref, $bv, 9, 'SUM(' . $ci['value'] . $first . ':' . $ci['value'] . $last_b . ')' );
					break;
				case 'cost_value':
					$cells .= dox_pos_xlsx_cell( $ref, $bc, 9, 'SUM(' . $ci['cost_value'] . $first . ':' . $ci['cost_value'] . $last_b . ')' );
					break;
				case 'status':
					$cells .= dox_pos_xlsx_cell( $ref, $r['pstatus'], 8 );
					break;
				default: // Código, otros atributos, categoría.
					$cells .= dox_pos_xlsx_cell( $ref, 'cat' === $c[1] ? $r['cat'] : '', 8 );
			}
		}
		$lines[] = '<row r="' . $n . '">' . $cells . '</row>';
		// ...y una fila por talla y color, con lo repetido en gris.
		foreach ( $block as $b ) {
			$n++;
			$cells = '';
			foreach ( $cols as $k => $c ) {
				$ref = dox_pos_xlsx_col( $k ) . $n;
				switch ( $c[2] ) {
					case 'money':
						$cells .= dox_pos_xlsx_cell( $ref, $b['price'], 2 );
						break;
					case 'cost':
						$cells .= dox_pos_xlsx_cell( $ref, $b['cost'], 2 );
						break;
					case 'int':
						$cells .= dox_pos_xlsx_cell( $ref, $b['stock'], 3 );
						break;
					case 'value':
						$cells .= null === $b['stock'] ? dox_pos_xlsx_cell( $ref, null, 2 ) : dox_pos_xlsx_cell( $ref, $b['value'], 2, $ci['price'] . $n . '*' . $ci['stock'] . $n );
						break;
					case 'cvalue':
						$cells .= null === $b['stock'] || null === $b['cost'] ? dox_pos_xlsx_cell( $ref, null, 2 ) : dox_pos_xlsx_cell( $ref, $b['cost_value'], 2, $ci['cost'] . $n . '*' . $ci['stock'] . $n );
						break;
					default:
						if ( 'status' === $c[1] ) {
							$cells .= dox_pos_xlsx_cell( $ref, $b['status'] === $b['pstatus'] ? '' : $b['status'] ); // Solo si esa talla es distinta del producto.
						} else {
							$cells .= dox_pos_xlsx_cell( $ref, $b[ $c[1] ], in_array( $c[1], array( 'ref', 'name', 'cat' ), true ) ? 11 : 0 );
						}
				}
			}
			$lines[] = '<row r="' . $n . '" outlineLevel="1">' . $cells . '</row>';
		}
		$units += $bu;
		$value += $bv;
		$cval  += $bc;
	}
	$last = $n;
	$t    = $last + 1;
	// El total suma solo las filas con código (las de cada talla y los de talla única), no las filas de producto.
	$total = dox_pos_xlsx_cell( 'A' . $t, __( 'Total', 'dox-pos' ), 6 );
	if ( $last > 1 ) {
		$total .= dox_pos_xlsx_cell( $ci['stock'] . $t, $units, 5, 'SUMIF(' . $ci['sku'] . '2:' . $ci['sku'] . $last . ',"<>",' . $ci['stock'] . '2:' . $ci['stock'] . $last . ')' );
		$total .= dox_pos_xlsx_cell( $ci['value'] . $t, $value, 4, 'SUMIF(' . $ci['sku'] . '2:' . $ci['sku'] . $last . ',"<>",' . $ci['value'] . '2:' . $ci['value'] . $last . ')' );
		if ( $costs ) {
			$total .= dox_pos_xlsx_cell( $ci['cost_value'] . $t, $cval, 4, 'SUMIF(' . $ci['sku'] . '2:' . $ci['sku'] . $last . ',"<>",' . $ci['cost_value'] . '2:' . $ci['cost_value'] . $last . ')' );
		}
	}
	$lines[] = '<row r="' . $t . '">' . $total . '</row>';
	$sheet1  = dox_pos_xlsx_sheet( $cols, $lines, $last, true, true, $links );

	// Hoja 2: por categoría.
	$cols2 = array(
		array( __( 'Category', 'dox-pos' ), 'cat', 'text', 34 ),
		array( __( 'Distinct products', 'dox-pos' ), 'refs', 'int', 13 ),
		array( __( 'Stock', 'dox-pos' ), 'units', 'int', 13 ),
		array( __( 'Value', 'dox-pos' ), 'value', 'money', 15 ),
	);
	if ( $costs ) {
		$cols2[] = array( __( 'Value at cost', 'dox-pos' ), 'cost_value', 'money', 15 );
	}
	$lines2 = array();
	$n      = 1;
	foreach ( $d['cats'] as $c ) {
		$n++;
		$lines2[] = '<row r="' . $n . '">' . dox_pos_xlsx_cell( 'A' . $n, $c['cat'] ) . dox_pos_xlsx_cell( 'B' . $n, $c['refs'], 3 ) . dox_pos_xlsx_cell( 'C' . $n, $c['units'], 3 ) . dox_pos_xlsx_cell( 'D' . $n, $c['value'], 2 ) . ( $costs ? dox_pos_xlsx_cell( 'E' . $n, $c['cost_value'] ?? null, 2 ) : '' ) . '</row>';
	}
	$last2 = $n;
	$t     = $last2 + 1;
	$total = dox_pos_xlsx_cell( 'A' . $t, __( 'Total', 'dox-pos' ), 6 );
	if ( $last2 > 1 ) {
		$total .= dox_pos_xlsx_cell( 'C' . $t, $units, 5, 'SUM(C2:C' . $last2 . ')' ) . dox_pos_xlsx_cell( 'D' . $t, $value, 4, 'SUM(D2:D' . $last2 . ')' );
		if ( $costs ) {
			$total .= dox_pos_xlsx_cell( 'E' . $t, $cval, 4, 'SUM(E2:E' . $last2 . ')' );
		}
	}
	$lines2[] = '<row r="' . $t . '">' . $total . '</row>';
	$sheet2   = dox_pos_xlsx_sheet( $cols2, $lines2, $last2 );

	return dox_pos_xlsx_workbook(
		array(
			array( 'name' => __( 'Inventory', 'dox-pos' ), 'xml' => $sheet1, 'links' => $links ),
			array( 'name' => __( 'By category', 'dox-pos' ), 'xml' => $sheet2 ),
		)
	);
}

/**
 * Arma el .xlsx con sus hojas: las partes fijas del paquete, el libro, las relaciones y los estilos.
 *
 * @param array $sheets [ [ name, xml ], ... ] en orden. El nombre de hoja admite hasta 31 caracteres.
 * @return string El archivo.
 */
function dox_pos_xlsx_workbook( $sheets ) {
	$xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
	$types = '';
	$rels  = '';
	$list  = '';
	$parts = array();
	foreach ( array_values( $sheets ) as $i => $sh ) {
		$n      = $i + 1;
		$name   = mb_substr( str_replace( array( '[', ']', ':', '*', '?', '/', '\\' ), ' ', (string) $sh['name'] ), 0, 31 );
		$types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		$rels  .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
		$list  .= '<sheet name="' . dox_pos_xlsx_text( $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
		$parts[ 'xl/worksheets/sheet' . $n . '.xml' ] = $sh['xml'];
		if ( empty( $sh['links'] ) ) {
			continue;
		}
		$hr = '';
		$k  = 0;
		foreach ( $sh['links'] as $url ) {
			$k++;
			$hr .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . dox_pos_xlsx_text( $url ) . '" TargetMode="External"/>';
		}
		$parts[ 'xl/worksheets/_rels/sheet' . $n . '.xml.rels' ] = $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $hr . '</Relationships>';
	}
	$rs    = count( $sheets ) + 1;
	$files = array(
		'[Content_Types].xml'        => $xml . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $types . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
		'_rels/.rels'                => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
		'xl/workbook.xml'            => $xml . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="14000"/></bookViews><sheets>' . $list . '</sheets><calcPr fullCalcOnLoad="1"/></workbook>',
		'xl/_rels/workbook.xml.rels' => $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . $rs . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
		'xl/styles.xml'              => $xml . dox_pos_xlsx_styles(),
	);
	return dox_pos_zip( array_merge( $files, $parts ) );
}

/**
 * Los estilos (cellXfs): 0 normal, 1 encabezado, 2 moneda, 3 entero, 4 moneda negrita, 5 entero
 * negrita, 6 texto negrita, 7 a 10 la fila de producto (negrita y fondo: texto, texto normal,
 * moneda, entero), 11 texto gris, 12 enlace en la fila de producto (negrita, subrayado y fondo)
 * y 13 enlace suelto (subrayado). El color del enlace es el de la marca.
 */
function dox_pos_xlsx_styles() {
	$link = strtoupper( ltrim( (string) dox_pos_colors()['primary'], '#' ) );
	if ( ! preg_match( '/^[0-9A-F]{6}$/', $link ) ) {
		$link = '0563C1'; // El azul de siempre, si la marca no tiene color válido.
	}
	return '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;$&quot;\ #,##0"/></numFmts><fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><sz val="11"/><color rgb="FF8C8580"/><name val="Calibri"/></font><font><b/><u/><sz val="11"/><color rgb="FF' . $link . '"/><name val="Calibri"/></font><font><u/><sz val="11"/><color rgb="FF' . $link . '"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE7DCD4"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF7EEE8"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="14"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/><xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/><xf numFmtId="164" fontId="1" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/><xf numFmtId="3" fontId="1" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

/**
 * Las filas de una tabla sencilla, a partir de arrays con las claves de $cols, y si se piden
 * columnas a sumar, una fila de totales con su fórmula. Deja $n en la última fila escrita.
 *
 * @param array $cols  [ [título, clave, tipo (text|money|int), ancho, clave de la dirección], ... ].
 * @param array $rows  Los datos.
 * @param int   $n     La última fila ya escrita (1 = solo el encabezado).
 * @param array $sum   Claves de las columnas que se suman al final.
 * @param array $links Se llena con los enlaces (celda => dirección) de las columnas que la traen.
 * @return string Las filas en XML.
 */
function dox_pos_xlsx_rows( $cols, $rows, &$n, $sum = array(), &$links = null ) {
	$lines = array();
	$first = $n + 1;
	foreach ( $rows as $r ) {
		$n++;
		$cells = '';
		foreach ( $cols as $k => $c ) {
			$ref = dox_pos_xlsx_col( $k ) . $n;
			$v   = $r[ $c[1] ] ?? null;
			if ( 'money' === $c[2] ) {
				$cells .= dox_pos_xlsx_cell( $ref, null === $v || '' === $v ? null : (float) $v, 2 );
			} elseif ( 'int' === $c[2] ) {
				$cells .= dox_pos_xlsx_cell( $ref, null === $v || '' === $v ? null : (int) $v, 3 );
			} elseif ( isset( $c[4] ) && is_array( $links ) && ! empty( $r[ $c[4] ] ) ) {
				$links[ $ref ] = (string) $r[ $c[4] ]; // La columna lleva la dirección de su ficha.
				$cells        .= dox_pos_xlsx_cell( $ref, is_scalar( $v ) ? (string) $v : '', 13 );
			} else {
				$cells .= dox_pos_xlsx_cell( $ref, is_scalar( $v ) ? (string) $v : '' );
			}
		}
		$lines[] = '<row r="' . $n . '">' . $cells . '</row>';
	}
	if ( $sum && $rows ) {
		$last = $n;
		$n++;
		$cells = dox_pos_xlsx_cell( 'A' . $n, __( 'Total', 'dox-pos' ), 6 );
		foreach ( $cols as $k => $c ) {
			if ( ! in_array( $c[1], $sum, true ) || 0 === $k ) {
				continue;
			}
			$col = dox_pos_xlsx_col( $k );
			$tot = 0;
			foreach ( $rows as $r ) {
				$tot += (float) ( $r[ $c[1] ] ?? 0 );
			}
			$cells .= dox_pos_xlsx_cell( $col . $n, $tot, 'money' === $c[2] ? 4 : 5, 'SUM(' . $col . $first . ':' . $col . $last . ')' );
		}
		$lines[] = '<row r="' . $n . '">' . $cells . '</row>';
	}
	return implode( '', $lines );
}

/**
 * Una fila de encabezado en cualquier sitio de la hoja (para las secciones de un resumen).
 */
function dox_pos_xlsx_header( $cols, $n ) {
	$cells = '';
	foreach ( $cols as $k => $c ) {
		$cells .= dox_pos_xlsx_cell( dox_pos_xlsx_col( $k ) . $n, $c[0], 1 );
	}
	return '<row r="' . $n . '">' . $cells . '</row>';
}

/**
 * Una hoja: encabezado en negrita y fijo, anchos de columna, filas y filtro.
 *
 * @param array    $cols   [ [título, clave, tipo, ancho], ... ].
 * @param string[] $lines  Las filas ya en XML (de la 2 en adelante).
 * @param int      $last   Última fila con datos (para el filtro).
 * @param bool     $groups Filas agrupadas bajo la de su producto.
 * @param bool     $filter Con el filtro del encabezado (no en una hoja de secciones).
 * @param array    $links  Enlaces de la hoja: celda => dirección, en el mismo orden que las relaciones.
 */
function dox_pos_xlsx_sheet( $cols, $lines, $last, $groups = false, $filter = true, $links = array() ) {
	$x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
	$x .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
	if ( $groups ) { // Filas agrupadas bajo la de su producto (el resumen va arriba, no abajo).
		$x .= '<sheetPr><outlinePr summaryBelow="0" summaryRight="0"/></sheetPr>';
	}
	$x .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>';
	$x .= '<sheetFormatPr defaultRowHeight="15"' . ( $groups ? ' outlineLevelRow="1"' : '' ) . '/><cols>';
	foreach ( $cols as $i => $c ) {
		$x .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . $c[3] . '" customWidth="1"/>';
	}
	$x .= '</cols><sheetData><row r="1">';
	foreach ( $cols as $i => $c ) {
		$x .= dox_pos_xlsx_cell( dox_pos_xlsx_col( $i ) . '1', $c[0], 1 );
	}
	$x .= '</row>' . implode( '', $lines ) . '</sheetData>';
	if ( $filter ) {
		$x .= '<autoFilter ref="A1:' . dox_pos_xlsx_col( count( $cols ) - 1 ) . max( 1, $last ) . '"/>';
	}
	// Los enlaces van después del filtro, como pide el formato, y apuntan a las relaciones de la hoja.
	if ( $links ) {
		$x .= '<hyperlinks>';
		$i  = 0;
		foreach ( $links as $ref => $url ) {
			$i++;
			$x .= '<hyperlink ref="' . $ref . '" r:id="rId' . $i . '"/>';
		}
		$x .= '</hyperlinks>';
	}
	$x .= '</worksheet>';
	return $x;
}

/**
 * Una celda: texto (inline, sin tabla de cadenas compartidas), número o fórmula con su resultado.
 */
function dox_pos_xlsx_cell( $ref, $value, $style = 0, $formula = '' ) {
	$s = $style ? ' s="' . (int) $style . '"' : '';
	if ( '' !== $formula ) {
		return '<c r="' . $ref . '"' . $s . '><f>' . dox_pos_xlsx_text( $formula ) . '</f><v>' . dox_pos_xlsx_num( $value ) . '</v></c>';
	}
	if ( null === $value || '' === $value ) {
		return '<c r="' . $ref . '"' . $s . '/>';
	}
	if ( is_int( $value ) || is_float( $value ) ) {
		return '<c r="' . $ref . '"' . $s . '><v>' . dox_pos_xlsx_num( $value ) . '</v></c>';
	}
	$value = (string) $value;
	$space = preg_match( '/^\s|\s$/u', $value ) ? ' xml:space="preserve"' : '';
	return '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t' . $space . '>' . dox_pos_xlsx_text( $value ) . '</t></is></c>';
}

/**
 * Un número tal como lo espera el XML: punto decimal, sin ceros de más.
 */
function dox_pos_xlsx_num( $v ) {
	$v = (float) $v;
	if ( floor( $v ) === $v && abs( $v ) < 1e15 ) {
		return (string) (int) $v;
	}
	return rtrim( rtrim( sprintf( '%.4F', $v ), '0' ), '.' );
}

/**
 * Texto seguro para el XML: sin caracteres de control (Excel no los admite) y con las entidades escapadas.
 */
function dox_pos_xlsx_text( $s ) {
	$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s );
	return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/**
 * La letra de la columna: 0 = A, 25 = Z, 26 = AA.
 */
function dox_pos_xlsx_col( $i ) {
	$s = '';
	$i = (int) $i;
	do {
		$s = chr( 65 + ( $i % 26 ) ) . $s;
		$i = intdiv( $i, 26 ) - 1;
	} while ( $i >= 0 );
	return $s;
}

/**
 * Un zip en memoria (formato PKZIP con deflate): lo que necesita un .xlsx, sin la extensión ZipArchive.
 *
 * @param array<string,string> $files Ruta dentro del zip => contenido. El primero debe ser [Content_Types].xml.
 */
function dox_pos_zip( $files ) {
	$now    = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Hora local para la fecha del zip.
	$dtime  = ( (int) gmdate( 'H', $now ) << 11 ) | ( (int) gmdate( 'i', $now ) << 5 ) | ( (int) gmdate( 's', $now ) >> 1 );
	$ddate  = ( ( (int) gmdate( 'Y', $now ) - 1980 ) << 9 ) | ( (int) gmdate( 'n', $now ) << 5 ) | (int) gmdate( 'j', $now );
	$body   = '';
	$dir    = '';
	$offset = 0;
	$count  = 0;
	foreach ( $files as $name => $data ) {
		$crc   = crc32( $data );
		$usize = strlen( $data );
		$comp  = gzdeflate( $data, 6 );
		$csize = strlen( $comp );
		$head  = "\x50\x4b\x03\x04" . pack( 'vvvvvVVVvv', 20, 0, 8, $dtime, $ddate, $crc, $csize, $usize, strlen( $name ), 0 ) . $name;
		$body .= $head . $comp;
		$dir  .= "\x50\x4b\x01\x02" . pack( 'vvvvvvVVVvvvvvVV', 20, 20, 0, 8, $dtime, $ddate, $crc, $csize, $usize, strlen( $name ), 0, 0, 0, 0, 0, $offset ) . $name;
		$offset += strlen( $head ) + $csize;
		$count++;
	}
	return $body . $dir . "\x50\x4b\x05\x06" . pack( 'vvvvVVv', 0, 0, $count, $count, strlen( $dir ), $offset, 0 );
}
