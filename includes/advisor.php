<?php
/**
 * El asistente, parte 2: los números del negocio.
 *
 * La revisión de la tienda (los chequeos de productos y sus arreglos de un toque), los
 * pendientes de hoy (pedidos que se quedaron atrás, apartados que vencen, cobros, lo que
 * se agota de lo que se vende), los números del periodo para aconsejar, los consejos por
 * reglas (y su redacción por el modelo cuando hay clave), el resumen diario y las
 * descripciones de producto a partir de la foto. Todo número sale de la tienda: el modelo
 * solo redacta. Las acciones quedan apuntadas y se deshacen durante 24 horas.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =====================================================================
 * El catálogo de un vistazo (para la revisión y los consejos)
 * ===================================================================== */

/**
 * Todos los productos con lo que hace falta para revisarlos, en tres consultas: metas del
 * producto y de sus variaciones, y las categorías. Se calcula una vez por petición.
 *
 * @return array<int,array>
 */
function dox_pos_ai_catalog( $fresh = false ) {
	static $cache = null;
	if ( null !== $cache && ! $fresh ) {
		return $cache;
	}
	global $wpdb;
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Sin datos de fuera.
		"SELECT p.ID, p.post_title, p.post_status, p.post_parent, p.post_type, p.post_date,
			CHAR_LENGTH(TRIM(p.post_content)) AS dlen, CHAR_LENGTH(TRIM(p.post_excerpt)) AS slen, l.stock_status AS wc_status,
			MAX(CASE WHEN m.meta_key = '_sku' THEN m.meta_value END) AS sku,
			MAX(CASE WHEN m.meta_key = '_price' THEN m.meta_value END) AS price,
			MAX(CASE WHEN m.meta_key = '_regular_price' THEN m.meta_value END) AS regular,
			MAX(CASE WHEN m.meta_key = '_sale_price' THEN m.meta_value END) AS sale,
			MAX(CASE WHEN m.meta_key = '_stock' THEN m.meta_value END) AS stock,
			MAX(CASE WHEN m.meta_key = '_manage_stock' THEN m.meta_value END) AS manage,
			MAX(CASE WHEN m.meta_key = '_thumbnail_id' THEN m.meta_value END) AS thumb,
			MAX(CASE WHEN m.meta_key = '_product_image_gallery' THEN m.meta_value END) AS gallery
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_sku','_price','_regular_price','_sale_price','_stock','_manage_stock','_thumbnail_id','_product_image_gallery')
		LEFT JOIN {$wpdb->wc_product_meta_lookup} l ON l.product_id = p.ID
		WHERE ( p.post_type = 'product' AND p.post_status IN ('publish','private','draft','pending') )
		   OR ( p.post_type = 'product_variation' AND p.post_status IN ('publish','private') )
		GROUP BY p.ID",
		ARRAY_A
	);
	$cats = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		"SELECT tr.object_id, t.name, t.slug FROM {$wpdb->term_relationships} tr
		JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
		JOIN {$wpdb->terms} t ON t.term_id = tt.term_id",
		ARRAY_A
	);
	$num  = fn( $v ) => ( null === $v || '' === $v ) ? null : (float) $v;
	$prod = array();
	$vars = array();
	foreach ( (array) $rows as $r ) {
		$rec = array(
			'id'      => (int) $r['ID'],
			'name'    => (string) $r['post_title'],
			'status'  => (string) $r['post_status'],
			'sku'     => trim( (string) $r['sku'] ),
			'price'   => $num( $r['price'] ),
			'regular' => $num( $r['regular'] ),
			'sale'    => $num( $r['sale'] ),
			'manage'  => 'yes' === $r['manage'],
			'stock'   => 'yes' === $r['manage'] && null !== $r['stock'] && '' !== $r['stock'] ? (int) $r['stock'] : null,
		);
		if ( 'product' === $r['post_type'] ) {
			$rec += array(
				'thumb'   => (int) $r['thumb'],
				'gallery' => count( array_filter( array_map( 'trim', explode( ',', (string) $r['gallery'] ) ) ) ),
				'dlen'    => (int) $r['dlen'],
				'slen'    => (int) $r['slen'],
				'wc'      => (string) $r['wc_status'], // Lo que WooCommerce calculó: instock, outofstock, onbackorder.
				'created' => (string) $r['post_date'],
				'cats'    => array(),
				'cat_n'   => 0,
				'vars'    => array(),
			);
			$prod[ $rec['id'] ] = $rec;
		} else {
			$vars[] = $rec + array( 'parent' => (int) $r['post_parent'] );
		}
	}
	foreach ( $vars as $v ) {
		if ( isset( $prod[ $v['parent'] ] ) ) {
			$prod[ $v['parent'] ]['vars'][] = $v;
		}
	}
	foreach ( (array) $cats as $c ) {
		$pid = (int) $c['object_id'];
		if ( ! isset( $prod[ $pid ] ) || 'uncategorized' === $c['slug'] ) {
			continue;
		}
		$prod[ $pid ]['cats'][] = html_entity_decode( (string) $c['name'], ENT_QUOTES, 'UTF-8' );
		$prod[ $pid ]['cat_n']++;
	}
	foreach ( $prod as &$p ) {
		$p['type'] = $p['vars'] ? 'variable' : 'simple';
		// Solo cuentan las variaciones activas: una desactivada (privada) no se vende ni se suma.
		$enabled      = array_filter( $p['vars'], fn( $v ) => 'publish' === $v['status'] );
		$managed      = array_filter( $enabled, fn( $v ) => null !== $v['stock'] );
		$inherit      = count( $enabled ) - count( $managed ); // Las que heredan las existencias del producto.
		$p['managed'] = count( $managed );
		$p['with']    = count( array_filter( $managed, fn( $v ) => $v['stock'] > 0 ) );
		$p['shared']  = $inherit > 0 && null !== $p['stock'];
		if ( ! $p['vars'] ) {
			$p['total'] = $p['stock']; // Simple: las suyas, o null si no se controlan.
		} elseif ( $inherit > 0 && null === $p['stock'] ) {
			$p['total'] = null; // Alguna talla no lleva control y el producto tampoco: no se sabe.
		} else {
			$p['total'] = array_sum( array_column( $managed, 'stock' ) ) + ( $inherit > 0 ? (int) $p['stock'] : 0 );
		}
		$p['value'] = null === $p['total'] || $p['total'] <= 0 || ! $p['price'] ? 0.0 : (float) $p['total'] * (float) $p['price'];
	}
	unset( $p );
	$cache = $prod;
	return $cache;
}

/**
 * El nombre plegado para encontrar repetidos ("Vestido Luna" = "vestido luna").
 */
function dox_pos_ai_fold( $s ) {
	$s = remove_accents( (string) $s );
	$s = strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', $s ) );
	return trim( preg_replace( '/\s+/', ' ', $s ) );
}

/* =====================================================================
 * La revisión: los chequeos y sus arreglos
 * ===================================================================== */

/**
 * Los chequeos, en el orden en que se enseñan: clave, nombre, qué mira, gravedad y su arreglo.
 */
function dox_pos_ai_check_defs() {
	return array(
		'negativo'         => array( __( 'Existencias en negativo', 'dox-pos' ), __( 'Alguna talla quedó por debajo de cero: se vendió lo que no había o se descontó dos veces.', 'dox-pos' ), 'alta', array( 'action' => 'a_cero', 'label' => __( 'Poner en 0', 'dox-pos' ) ) ),
		'sin_precio'       => array( __( 'Sin precio', 'dox-pos' ), __( 'Publicados sin precio, o con una talla sin precio: la tienda no los deja comprar.', 'dox-pos' ), 'alta', null ),
		'oferta_mal'       => array( __( 'Oferta mal puesta', 'dox-pos' ), __( 'El precio de oferta es igual o mayor que el normal: no rebaja nada y la tienda lo tacha igual.', 'dox-pos' ), 'alta', array( 'action' => 'quitar_oferta', 'label' => __( 'Quitar la oferta', 'dox-pos' ) ) ),
		'sin_categoria'    => array( __( 'Sin categoría', 'dox-pos' ), __( 'No salen en ninguna sección de la tienda ni en los filtros.', 'dox-pos' ), 'alta', null ),
		'oculto_con_stock' => array( __( 'Ocultos con existencias', 'dox-pos' ), __( 'Están guardados como ocultos pero tienen unidades: nadie los puede comprar.', 'dox-pos' ), 'media', array( 'action' => 'publicar', 'label' => __( 'Publicar en la tienda', 'dox-pos' ) ) ),
		'sin_descripcion'  => array( __( 'Sin descripción', 'dox-pos' ), __( 'La ficha sale sin texto: vende menos y Google no la entiende. El asistente la redacta con la foto y el nombre; tú apruebas cada una.', 'dox-pos' ), 'media', array( 'action' => 'describir', 'label' => __( 'Redactar con la foto', 'dox-pos' ), 'ai' => true ) ),
		'sin_foto'         => array( __( 'Sin foto', 'dox-pos' ), __( 'Publicados sin ninguna foto.', 'dox-pos' ), 'media', null ),
		'agotado'          => array( __( 'Agotados en todas las tallas', 'dox-pos' ), __( 'Publicados sin una sola unidad. La tienda los marca "agotado"; si no van a volver, mejor ocultarlos.', 'dox-pos' ), 'media', array( 'action' => 'ocultar', 'label' => __( 'Ocultar de la tienda', 'dox-pos' ) ) ),
		'codigo_repetido'  => array( __( 'Código repetido', 'dox-pos' ), __( 'Dos productos o tallas con el mismo código: el buscador y el Excel los confunden.', 'dox-pos' ), 'media', null ),
		'sin_codigo'       => array( __( 'Sin código', 'dox-pos' ), __( 'Publicados sin código (SKU). Se pone desde WooCommerce.', 'dox-pos' ), 'media', null ),
		'nombre_repetido'  => array( __( 'Nombre repetido', 'dox-pos' ), __( 'Dos fichas con el mismo nombre: suele ser una copia vieja.', 'dox-pos' ), 'media', null ),
		'precio_raro'      => array( __( 'Precio fuera de lo normal', 'dox-pos' ), __( 'Muy por debajo o muy por encima del resto: un cero de más o de menos.', 'dox-pos' ), 'media', null ),
		'una_foto'         => array( __( 'Una sola foto', 'dox-pos' ), __( 'Con una segunda foto (la prenda puesta, el detalle) se vende más.', 'dox-pos' ), 'baja', null ),
		'una_talla'        => array( __( 'Queda una sola talla', 'dox-pos' ), __( 'Solo una talla con unidades: candidatos a promoción o a un outlet.', 'dox-pos' ), 'baja', null ),
	);
}

/**
 * Corre los chequeos sobre el catálogo.
 *
 * @param bool   $with_items Si van las listas (hasta 60 por chequeo) o solo los números.
 * @param string $only       Un chequeo concreto, con todos sus productos.
 * @return array products, hidden, checks[]
 */
function dox_pos_ai_checks( $with_items = true, $only = '' ) {
	$cat   = dox_pos_ai_catalog();
	$defs  = dox_pos_ai_check_defs();
	$found = array_fill_keys( array_keys( $defs ), array() );
	$add   = function ( $key, $p, $extra = '' ) use ( &$found ) {
		$found[ $key ][] = array( 'p' => $p, 'extra' => $extra );
	};
	// El precio "normal": entre 2.000 y cinco veces el percentil 95 de lo publicado.
	$prices = array();
	foreach ( $cat as $p ) {
		if ( 'publish' === $p['status'] && $p['price'] > 0 ) {
			$prices[] = (float) $p['price'];
		}
	}
	sort( $prices );
	$p95   = $prices ? $prices[ (int) floor( 0.95 * ( count( $prices ) - 1 ) ) ] : 0;
	$skus  = array();
	$names = array();
	$npub  = 0;
	$nhid  = 0;
	foreach ( $cat as $p ) {
		$pub  = 'publish' === $p['status'];
		$priv = 'private' === $p['status'];
		$npub += $pub ? 1 : 0;
		$nhid += $priv ? 1 : 0;
		if ( $pub || $priv ) {
			$names[ dox_pos_ai_fold( $p['name'] ) ][] = $p['id'];
		}
		if ( '' !== $p['sku'] ) {
			$skus[ strtoupper( $p['sku'] ) ][] = $p['id'];
		}
		$neg = null !== $p['stock'] && $p['stock'] < 0;
		$bad_sale = $p['sale'] > 0 && $p['regular'] > 0 && $p['sale'] >= $p['regular'];
		$no_price = $pub && ( ! $p['price'] || $p['price'] <= 0 );
		foreach ( $p['vars'] as $v ) {
			if ( '' !== $v['sku'] ) {
				$skus[ strtoupper( $v['sku'] ) ][] = $p['id'];
			}
			if ( null !== $v['stock'] && $v['stock'] < 0 ) {
				$neg = true;
			}
			if ( $v['sale'] > 0 && $v['regular'] > 0 && $v['sale'] >= $v['regular'] ) {
				$bad_sale = true;
			}
			if ( $pub && 'publish' === $v['status'] && ( ! $v['price'] || $v['price'] <= 0 ) ) {
				$no_price = true;
			}
		}
		if ( $neg ) {
			$add( 'negativo', $p );
		}
		if ( $no_price ) {
			$add( 'sin_precio', $p );
		}
		if ( $bad_sale ) {
			$add( 'oferta_mal', $p );
		}
		if ( $pub && 0 === $p['cat_n'] ) {
			$add( 'sin_categoria', $p );
		}
		if ( $priv && null !== $p['total'] && $p['total'] > 0 ) {
			/* translators: %d: unidades */
			$add( 'oculto_con_stock', $p, sprintf( _n( '%d unidad', '%d unidades', $p['total'], 'dox-pos' ), $p['total'] ) );
		}
		if ( $pub && 0 === $p['dlen'] && 0 === $p['slen'] ) {
			$add( 'sin_descripcion', $p, $p['thumb'] ? '' : __( 'sin foto', 'dox-pos' ) );
		}
		if ( $pub && ! $p['thumb'] ) {
			$add( 'sin_foto', $p );
		} elseif ( $pub && 0 === $p['gallery'] ) {
			$add( 'una_foto', $p );
		}
		if ( $pub && 'outofstock' === $p['wc'] ) {
			$add( 'agotado', $p ); // Lo que WooCommerce marca como agotado (la tienda enseña ese letrero).
		}
		if ( $pub && '' === $p['sku'] ) {
			$add( 'sin_codigo', $p );
		}
		if ( $pub && $p['price'] > 0 && ( $p['price'] < 2000 || ( $p95 > 0 && $p['price'] > 5 * $p95 ) ) ) {
			$add( 'precio_raro', $p, dox_pos_money( $p['price'] ) );
		}
		if ( $pub && ! $p['shared'] && $p['managed'] >= 2 && 1 === $p['with'] ) {
			$add( 'una_talla', $p );
		}
	}
	foreach ( $skus as $sku => $ids ) {
		$ids = array_values( array_unique( $ids ) );
		if ( count( $ids ) > 1 ) {
			foreach ( $ids as $pid ) {
				$add( 'codigo_repetido', $cat[ $pid ], $sku );
			}
		}
	}
	foreach ( $names as $ids ) {
		if ( count( $ids ) > 1 ) {
			foreach ( $ids as $pid ) {
				$add( 'nombre_repetido', $cat[ $pid ], 'publish' === $cat[ $pid ]['status'] ? __( 'publicado', 'dox-pos' ) : __( 'oculto', 'dox-pos' ) );
			}
		}
	}
	$checks = array();
	foreach ( $defs as $key => $d ) {
		if ( '' !== $only && $key !== $only ) {
			continue;
		}
		$list  = $found[ $key ];
		$value = 0.0;
		$ids   = array();
		foreach ( $list as $f ) {
			$value += (float) $f['p']['value'];
			$ids[]  = $f['p']['id'];
		}
		$items = array();
		if ( $with_items ) {
			$max = '' !== $only ? 400 : 60;
			foreach ( array_slice( $list, 0, $max ) as $f ) {
				$items[] = dox_pos_ai_check_item( $f['p'], $f['extra'] );
			}
		}
		$checks[] = array(
			'key'   => $key,
			'label' => $d[0],
			'what'  => $d[1],
			'sev'   => $d[2],
			'fix'   => $d[3],
			'count' => count( $list ),
			'value' => in_array( $key, array( 'oculto_con_stock', 'una_talla', 'negativo' ), true ) ? round( $value ) : 0,
			'ids'   => $ids,
			'items' => $items,
		);
	}
	return array( 'products' => $npub, 'hidden' => $nhid, 'checks' => $checks, 'ai_ready' => dox_pos_ai_enabled() );
}

function dox_pos_ai_check_item( $p, $extra = '' ) {
	return array(
		'id'     => $p['id'],
		'name'   => $p['name'],
		'sku'    => $p['sku'],
		'status' => $p['status'],
		'stock'  => $p['total'],
		'price'  => $p['price'] ? (float) $p['price'] : 0,
		'image'  => $p['thumb'] ? (string) wp_get_attachment_image_url( $p['thumb'], 'woocommerce_thumbnail' ) : '',
		'extra'  => (string) $extra,
		'url'    => get_permalink( $p['id'] ),
	);
}

/**
 * Los arreglos de un toque. Cada uno queda apuntado como una acción que se deshace 24 horas.
 *
 * @param string $action publicar | ocultar | a_cero | quitar_oferta | descripciones.
 * @param int[]  $ids    Productos.
 * @param array  $texts  Solo descripciones: [ { id, description, short } ].
 * @return array|WP_Error done, action, message, undo.
 */
function dox_pos_ai_fix( $action, $ids, $texts = array() ) {
	$by_id = array();
	if ( 'descripciones' === $action ) {
		foreach ( (array) $texts as $t ) {
			$t   = (array) $t;
			$pid = (int) ( $t['id'] ?? 0 );
			$d   = trim( sanitize_textarea_field( (string) ( $t['description'] ?? '' ) ) );
			if ( $pid && '' !== $d ) {
				$by_id[ $pid ] = array( 'description' => $d, 'short' => trim( sanitize_text_field( (string) ( $t['short'] ?? '' ) ) ) );
			}
		}
		$ids = array_keys( $by_id );
	}
	$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	if ( ! $ids ) {
		return new WP_Error( 'dox_pos_ai_vacio', __( 'No hay productos a los que aplicarlo.', 'dox-pos' ) );
	}
	if ( count( $ids ) > 300 ) {
		return new WP_Error( 'dox_pos_ai_muchos', __( 'Como mucho 300 productos de una vez.', 'dox-pos' ) );
	}
	if ( ! in_array( $action, array( 'publicar', 'ocultar', 'a_cero', 'quitar_oferta', 'descripciones' ), true ) ) {
		return new WP_Error( 'dox_pos_ai_accion', __( 'Ese arreglo no existe.', 'dox-pos' ) );
	}
	$changes = array();
	$ctx     = dox_pos_stock_context( 'edit', 0, __( 'Asistente', 'dox-pos' ) );
	foreach ( $ids as $pid ) {
		$p = wc_get_product( $pid );
		if ( ! $p || $p->is_type( 'variation' ) ) {
			continue;
		}
		$ch    = array();
		$items = array( 0 => $p );
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) {
					$items[ (int) $vid ] = $v;
				}
			}
		}
		switch ( $action ) {
			case 'publicar':
				if ( 'private' === $p->get_status() ) {
					$p->set_status( 'publish' );
					$ch['status'] = array( 'private', 'publish' );
				}
				break;
			case 'ocultar':
				if ( 'publish' === $p->get_status() ) {
					$p->set_status( 'private' );
					$ch['status'] = array( 'publish', 'private' );
				}
				break;
			case 'a_cero':
				foreach ( $items as $k => $x ) {
					if ( $x->managing_stock() && (int) $x->get_stock_quantity() < 0 ) {
						$ch['stock'][ $k ] = array( (int) $x->get_stock_quantity(), 0 );
						$x->set_stock_quantity( 0 );
						$x->set_stock_status( 'outofstock' );
						if ( $k ) {
							$x->save();
						}
					}
				}
				break;
			case 'quitar_oferta':
				foreach ( $items as $k => $x ) {
					$sale = (string) $x->get_sale_price( 'edit' );
					if ( '' !== $sale ) {
						$ch['sale'][ $k ] = array( $sale, '' );
						$x->set_sale_price( '' );
						if ( $k ) {
							$x->save();
						}
					}
				}
				break;
			case 'descripciones':
				$t    = $by_id[ $pid ];
				$html = wpautop( wp_kses_post( $t['description'] ) );
				$ch['description'] = array( (string) $p->get_description( 'edit' ), $html );
				$p->set_description( $html );
				if ( '' !== $t['short'] ) {
					$ch['short'] = array( (string) $p->get_short_description( 'edit' ), $t['short'] );
					$p->set_short_description( wp_kses_post( $t['short'] ) );
				}
				break;
		}
		if ( ! $ch ) {
			continue;
		}
		try {
			$p->save();
		} catch ( Exception $e ) {
			continue;
		}
		if ( $p->is_type( 'variable' ) ) {
			WC_Product_Variable::sync( $pid );
		}
		wc_delete_product_transients( $pid );
		do_action( 'litespeed_purge_post', $pid );
		$ch['_name']     = $p->get_name();
		$changes[ $pid ] = $ch;
	}
	dox_pos_stock_context_end( $ctx );
	$n = count( $changes );
	if ( ! $n ) {
		return new WP_Error( 'dox_pos_ai_nada', __( 'No había nada que cambiar: ya estaba hecho.', 'dox-pos' ) );
	}
	$labels = array(
		/* translators: %d: productos */
		'publicar'      => _n( 'Publicó %d producto oculto con existencias', 'Publicó %d productos ocultos con existencias', $n, 'dox-pos' ),
		/* translators: %d: productos */
		'ocultar'       => _n( 'Ocultó %d producto agotado', 'Ocultó %d productos agotados', $n, 'dox-pos' ),
		/* translators: %d: productos */
		'a_cero'        => _n( 'Puso en 0 las existencias negativas de %d producto', 'Puso en 0 las existencias negativas de %d productos', $n, 'dox-pos' ),
		/* translators: %d: productos */
		'quitar_oferta' => _n( 'Quitó la oferta mal puesta de %d producto', 'Quitó la oferta mal puesta de %d productos', $n, 'dox-pos' ),
		/* translators: %d: productos */
		'descripciones' => _n( 'Guardó la descripción de %d producto', 'Guardó la descripción de %d productos', $n, 'dox-pos' ),
	);
	$label = sprintf( $labels[ $action ], $n );
	if ( 1 === $n ) {
		$label .= ': ' . reset( $changes )['_name'];
	}
	$undo = 'a_cero' !== $action; // Unas existencias en negativo no se restauran: deshacer nunca deja menos de cero.
	$aid  = dox_pos_ai_record_action( 'revision', $label, array( 'products' => $changes ), $undo );
	delete_transient( 'dox_pos_product_form' );
	dox_pos_ai_forget();
	return array( 'done' => $n, 'action' => $aid, 'message' => $label . '.', 'undo' => $undo );
}

/**
 * Lo que se guarda calculado y cuesta dinero (hoy, los consejos que redacta el modelo) va en una
 * opción con la hora en que se guardó, no en un transient: el object cache del servidor se vacía
 * en cada purga y con él se irían los transients, así que habría que volver a pagar la redacción
 * aunque no hubieran pasado las seis horas. Una opción vive en la base y aguanta la purga.
 *
 * @param string $key     Nombre corto (advice, advice_lock).
 * @param int    $seconds Cuánto vale lo guardado.
 * @return mixed|null Null si no hay nada o ya caducó.
 */
function dox_pos_ai_kept( $key, $seconds ) {
	$v = get_option( 'dox_pos_ai_kept_' . $key, null );
	if ( ! is_array( $v ) || ! isset( $v['ts'], $v['data'] ) ) {
		return null;
	}
	$age = time() - (int) $v['ts'];
	if ( $age < 0 || $age >= (int) $seconds ) { // Negativo: alguien movió la hora del servidor.
		return null;
	}
	return $v['data'];
}

/**
 * Guarda algo con su hora. Sin autoload: no se carga en cada petición de la tienda.
 */
function dox_pos_ai_keep( $key, $data ) {
	update_option( 'dox_pos_ai_kept_' . $key, array( 'ts' => time(), 'data' => $data ), false );
}

/**
 * Lo guardado ya no vale.
 */
function dox_pos_ai_drop( $key ) {
	delete_option( 'dox_pos_ai_kept_' . $key );
}

/**
 * Los cálculos guardados (consejos, números, insignia) ya no valen: algo cambió.
 */
function dox_pos_ai_forget() {
	dox_pos_ai_drop( 'advice' );
	delete_transient( 'dox_pos_ai_advice' ); // De las versiones en que los consejos iban en caché.
	delete_transient( 'dox_pos_ai_badge' );
	foreach ( array( 30, 90 ) as $d ) {
		delete_transient( 'dox_pos_ai_ins_' . $d );
		delete_transient( 'dox_pos_ai_fc_' . $d );
	}
}

/* =====================================================================
 * Las acciones del asistente y cómo se deshacen
 * ===================================================================== */

/**
 * Apunta lo que hizo el asistente, con lo de antes y lo de después de cada dato, para poder
 * deshacerlo.
 *
 * @param string $source revision | chat.
 * @param string $label  En una frase.
 * @param array  $data   products: { id: { name|status|description|short: [antes, después], regular|sale|stock: { 0 o variación: [antes, después] } } }, orders: { id: { status: [antes, después], tracking: [antes, después] } }.
 * @param bool   $undo   Si se puede deshacer.
 * @return int La acción.
 */
function dox_pos_ai_record_action( $source, $label, $data, $undo = true ) {
	global $wpdb;
	$u = wp_get_current_user();
	$wpdb->insert(
		dox_pos_ai_actions_table(),
		array(
			'created_at' => current_time( 'mysql' ),
			'user_id'    => (int) $u->ID,
			'user_name'  => $u->display_name ? $u->display_name : ( $u->user_login ? $u->user_login : __( 'Automático', 'dox-pos' ) ),
			'source'     => substr( (string) $source, 0, 20 ),
			'kind'       => isset( $data['orders'] ) && ! isset( $data['products'] ) ? 'order' : 'product',
			'label'      => mb_substr( (string) $label, 0, 255 ),
			'data'       => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
			'can_undo'   => $undo ? 1 : 0,
		),
		array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
	);
	return (int) $wpdb->insert_id;
}

/**
 * Las últimas acciones, para la pestaña Actividad.
 */
function dox_pos_ai_actions_list( $limit = 50 ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, created_at, user_name, source, kind, label, can_undo, undone_at, undo_user FROM ' . dox_pos_ai_actions_table() . ' ORDER BY id DESC LIMIT %d', (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$out  = array();
	$lim  = current_time( 'timestamp' ) - DAY_IN_SECONDS;
	foreach ( (array) $rows as $r ) {
		$out[] = array(
			'id'        => (int) $r['id'],
			'date'      => mysql2date( 'd/m H:i', $r['created_at'] ),
			'user'      => (string) $r['user_name'],
			'source'    => (string) $r['source'],
			'kind'      => (string) $r['kind'],
			'label'     => (string) $r['label'],
			'undone'    => ! empty( $r['undone_at'] ),
			'undone_by' => (string) $r['undo_user'],
			'can_undo'  => empty( $r['undone_at'] ) && (int) $r['can_undo'] && strtotime( $r['created_at'] ) >= $lim,
		);
	}
	return $out;
}

/**
 * Deshace una acción: cada dato vuelve a lo de antes solo si sigue como lo dejó el asistente
 * (si alguien lo cambió después, se respeta y se avisa). Las existencias se deshacen por
 * diferencia, porque entre medias pudo haber ventas.
 *
 * @return array|WP_Error
 */
function dox_pos_ai_undo( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dox_pos_ai_actions_table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( ! $row ) {
		return new WP_Error( 'dox_pos_ai_no_accion', __( 'No existe esa acción.', 'dox-pos' ) );
	}
	if ( ! empty( $row['undone_at'] ) ) {
		return new WP_Error( 'dox_pos_ai_deshecha', __( 'Esa acción ya se deshizo.', 'dox-pos' ) );
	}
	if ( ! (int) $row['can_undo'] ) {
		return new WP_Error( 'dox_pos_ai_no_deshacer', __( 'Esa acción no se puede deshacer.', 'dox-pos' ) );
	}
	if ( strtotime( $row['created_at'] ) < current_time( 'timestamp' ) - DAY_IN_SECONDS ) {
		return new WP_Error( 'dox_pos_ai_tarde', __( 'Solo se puede deshacer durante las 24 horas siguientes.', 'dox-pos' ) );
	}
	$data    = json_decode( (string) $row['data'], true );
	$data    = is_array( $data ) ? $data : array();
	$who     = wp_get_current_user()->display_name;
	$skipped = array();
	$ctx     = dox_pos_stock_context( 'edit', 0, __( 'Deshecho en el asistente', 'dox-pos' ) );
	// Un producto creado por el asistente: a la papelera (se recupera desde WooCommerce), salvo que ya haya vendido.
	if ( ! empty( $data['created'] ) ) {
		$p = wc_get_product( (int) $data['created'] );
		if ( $p && 'trash' !== $p->get_status() ) {
			if ( dox_pos_ai_product_has_sales( $p ) ) {
				$skipped[] = $p->get_name() . ' (' . __( 'ya tiene ventas', 'dox-pos' ) . ')';
			} else {
				wp_trash_post( $p->get_id() );
				delete_transient( 'dox_pos_product_form' );
			}
		}
	}
	foreach ( (array) ( $data['products'] ?? array() ) as $pid => $ch ) {
		$p = wc_get_product( (int) $pid );
		if ( ! $p ) {
			$skipped[] = sprintf( '#%d', (int) $pid );
			continue;
		}
		$name = $p->get_name();
		foreach ( array( 'name', 'status', 'description', 'short' ) as $f ) {
			if ( ! isset( $ch[ $f ] ) || ! is_array( $ch[ $f ] ) ) {
				continue;
			}
			list( $b, $a ) = $ch[ $f ];
			$cur = 'name' === $f ? $p->get_name() : ( 'status' === $f ? $p->get_status() : ( 'description' === $f ? $p->get_description( 'edit' ) : $p->get_short_description( 'edit' ) ) );
			if ( trim( (string) $cur ) !== trim( (string) $a ) ) {
				$skipped[] = $name . ' (' . $f . ')';
				continue;
			}
			if ( 'name' === $f ) {
				$p->set_name( $b );
			} elseif ( 'status' === $f ) {
				$p->set_status( $b );
			} elseif ( 'description' === $f ) {
				$p->set_description( $b );
			} else {
				$p->set_short_description( $b );
			}
		}
		foreach ( array( 'regular', 'sale', 'stock' ) as $f ) {
			foreach ( (array) ( $ch[ $f ] ?? array() ) as $k => $pair ) {
				if ( ! is_array( $pair ) || 2 !== count( $pair ) ) {
					continue;
				}
				list( $b, $a ) = $pair;
				$x = (int) $k ? wc_get_product( (int) $k ) : $p;
				if ( ! $x ) {
					continue;
				}
				if ( 'stock' === $f ) {
					$cur = (int) $x->get_stock_quantity();
					$new = max( 0, $cur - ( (int) $a - (int) $b ) );
					$x->set_stock_quantity( $new );
					$x->set_stock_status( $new > 0 ? 'instock' : 'outofstock' );
				} elseif ( 'regular' === $f ) {
					if ( (float) $x->get_regular_price( 'edit' ) !== (float) $a ) {
						$skipped[] = $name . ' (' . __( 'precio', 'dox-pos' ) . ')';
						continue;
					}
					$x->set_regular_price( (string) $b );
				} else {
					if ( (string) $x->get_sale_price( 'edit' ) !== (string) $a ) {
						$skipped[] = $name . ' (' . __( 'oferta', 'dox-pos' ) . ')';
						continue;
					}
					$x->set_sale_price( (string) $b );
				}
				if ( (int) $k ) {
					$x->save();
				}
			}
		}
		if ( isset( $ch['cats'] ) && is_array( $ch['cats'] ) && 2 === count( $ch['cats'] ) ) {
			$cur = array_map( 'intval', $p->get_category_ids() );
			$aft = array_map( 'intval', (array) $ch['cats'][1] );
			sort( $cur );
			sort( $aft );
			if ( $cur === $aft ) {
				$p->set_category_ids( array_map( 'intval', (array) $ch['cats'][0] ) );
			} else {
				$skipped[] = $name . ' (' . __( 'categoría', 'dox-pos' ) . ')';
			}
		}
		if ( isset( $ch['sku'] ) && is_array( $ch['sku'] ) && 2 === count( $ch['sku'] ) ) {
			if ( (string) $p->get_sku( 'edit' ) === (string) $ch['sku'][1] ) {
				try {
					$p->set_sku( (string) $ch['sku'][0] );
				} catch ( Exception $e ) {
					$skipped[] = $name . ' (' . __( 'código', 'dox-pos' ) . ')';
				}
			} else {
				$skipped[] = $name . ' (' . __( 'código', 'dox-pos' ) . ')';
			}
		}
		if ( isset( $ch['images'] ) && is_array( $ch['images'] ) && 2 === count( $ch['images'] ) ) {
			list( $ib, $ia ) = $ch['images'];
			$cur_gal = array_map( 'intval', $p->get_gallery_image_ids( 'edit' ) );
			if ( (int) $p->get_image_id( 'edit' ) === (int) $ia[0] && $cur_gal === array_map( 'intval', (array) $ia[1] ) ) {
				$p->set_image_id( (int) $ib[0] );
				$p->set_gallery_image_ids( array_map( 'intval', (array) $ib[1] ) );
			} else {
				$skipped[] = $name . ' (' . __( 'fotos', 'dox-pos' ) . ')';
			}
		}
		if ( ! empty( $ch['new_vars'] ) && is_array( $ch['new_vars'] ) ) {
			// Las tallas que añadió el asistente se quitan si siguen con las unidades que él puso.
			$size_tax = dox_pos_size_attribute();
			$left     = array();
			foreach ( $ch['new_vars'] as $vid => $qty ) {
				$v = wc_get_product( (int) $vid );
				if ( ! $v || ! $v->is_type( 'variation' ) ) {
					continue;
				}
				if ( (int) $v->get_stock_quantity() !== (int) $qty ) {
					$skipped[] = $name . ' (' . dox_pos_format_variation( $v )['label'] . ')';
					$left[ (string) ( $v->get_attributes()[ $size_tax ] ?? '' ) ] = true;
					continue;
				}
				$v->delete( true );
			}
			$attrs = $p->get_attributes();
			if ( $size_tax && isset( $attrs[ $size_tax ] ) && ! empty( $ch['sizes_added'] ) ) {
				$remove = array();
				foreach ( array_map( 'intval', (array) $ch['sizes_added'] ) as $sid ) {
					$t = get_term( $sid, $size_tax );
					if ( $t && ! is_wp_error( $t ) && ! isset( $left[ $t->slug ] ) ) {
						$remove[] = $sid;
					}
				}
				if ( $remove ) {
					$attrs[ $size_tax ]->set_options( array_values( array_diff( array_map( 'intval', $attrs[ $size_tax ]->get_options() ), $remove ) ) );
					$p->set_attributes( $attrs );
					wp_remove_object_terms( $p->get_id(), $remove, $size_tax );
				}
			}
		}
		try {
			$p->save();
		} catch ( Exception $e ) {
			$skipped[] = $name;
			continue;
		}
		if ( $p->is_type( 'variable' ) ) {
			WC_Product_Variable::sync( $p->get_id() );
		}
		wc_delete_product_transients( $p->get_id() );
		do_action( 'litespeed_purge_post', $p->get_id() );
	}
	foreach ( (array) ( $data['orders'] ?? array() ) as $oid => $ch ) {
		$o = wc_get_order( (int) $oid );
		if ( ! $o instanceof WC_Order ) {
			continue;
		}
		foreach ( array( 'new_order', 'cancelled_order', 'failed_order' ) as $mail ) {
			add_filter( 'woocommerce_email_enabled_' . $mail, '__return_false' );
		}
		if ( isset( $ch['tracking'] ) && is_array( $ch['tracking'] ) && (string) $o->get_meta( '_dox_pos_tracking' ) === (string) $ch['tracking'][1] ) {
			if ( '' === (string) $ch['tracking'][0] ) {
				foreach ( array( '_dox_pos_tracking', '_dox_pos_carrier', '_dox_pos_guide', '_dox_pos_tracking_url' ) as $mk ) {
					$o->delete_meta_data( $mk );
				}
			} else {
				$o->update_meta_data( '_dox_pos_tracking', (string) $ch['tracking'][0] );
			}
			$o->save();
		}
		if ( isset( $ch['status'] ) && is_array( $ch['status'] ) ) {
			if ( $o->get_status() === $ch['status'][1] ) {
				/* translators: %s: quién */
				$o->update_status( $ch['status'][0], sprintf( __( 'Deshecho desde el asistente por %s.', 'dox-pos' ), $who ) );
			} else {
				$skipped[] = '#' . $o->get_order_number();
			}
		}
	}
	dox_pos_stock_context_end( $ctx );
	$wpdb->update( dox_pos_ai_actions_table(), array( 'undone_at' => current_time( 'mysql' ), 'undo_user' => (string) $who ), array( 'id' => (int) $id ), array( '%s', '%s' ), array( '%d' ) );
	dox_pos_ai_forget();
	delete_transient( 'dox_pos_product_form' );
	$msg = __( 'Deshecho.', 'dox-pos' );
	if ( $skipped ) {
		/* translators: %s: lista */
		$msg .= ' ' . sprintf( __( 'Se dejó como está lo que alguien cambió después: %s.', 'dox-pos' ), implode( ', ', array_slice( array_unique( $skipped ), 0, 6 ) ) );
	}
	return array( 'ok' => true, 'message' => $msg );
}

/* =====================================================================
 * Los pedidos: pendientes, lo que se vende y lo que se agota
 * ===================================================================== */

/**
 * Los pedidos de un periodo, ya resumidos (una fila por pedido con sus líneas). Vendidos por
 * defecto: procesando, enviado o completado.
 *
 * @param string     $from     AAAA-MM-DD.
 * @param string     $to       AAAA-MM-DD.
 * @param array|null $statuses Con "wc-".
 * @return array
 */
function dox_pos_ai_period_orders( $from, $to, $statuses = null ) {
	$range = dox_pos_history_range( $from, $to );
	$args  = array(
		'limit'        => 1000,
		'type'         => 'shop_order',
		'orderby'      => 'date',
		'order'        => 'DESC',
		'date_created' => $range['start'] . '...' . $range['end'],
		'status'       => $statuses ? $statuses : array( 'wc-processing', 'wc-enviado', 'wc-completed' ),
	);
	if ( ! dox_pos_show_web_orders() ) {
		$args['created_via'] = DOX_POS_VIA;
	}
	$rows = array();
	foreach ( wc_get_orders( $args ) as $o ) {
		$f     = dox_pos_format_order( $o );
		$disc  = 0.0;
		foreach ( $o->get_fees() as $fee ) {
			if ( (float) $fee->get_total() < 0 ) {
				$disc += -(float) $fee->get_total();
			}
		}
		$items = array();
		$units = 0;
		foreach ( $o->get_items() as $it ) {
			$units  += (int) $it->get_quantity();
			$items[] = array(
				'pid'   => (int) $it->get_product_id(),
				'vid'   => (int) $it->get_variation_id(),
				'qty'   => (int) $it->get_quantity(),
				'total' => (float) $it->get_total(),
				'name'  => $it->get_name(),
			);
		}
		$ts     = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
		$rows[] = array(
			'id'       => $o->get_id(),
			'number'   => $f['number'],
			'status'   => $o->get_status(),
			'total'    => (float) $f['total'],
			'units'    => $units,
			'discount' => $disc,
			'shipping' => (float) $o->get_shipping_total(),
			'cod'      => (bool) $f['cod'],
			'channel'  => (string) $f['channel'],
			'payment'  => (string) $f['payment'],
			'city'     => trim( (string) ( $o->has_shipping_address() ? $o->get_shipping_city() : $o->get_billing_city() ) ),
			'seller'   => (string) $f['seller'],
			'phone'    => preg_replace( '/\D/', '', (string) $f['phone'] ),
			'customer' => (string) $f['customer'],
			'origin'   => (string) $f['origin'],
			'hold'     => (bool) $o->get_meta( '_dox_pos_hold_until' ),
			'weekday'  => $ts ? (int) wp_date( 'N', $ts ) : 0,
			'hour'     => $ts ? (int) wp_date( 'G', $ts ) : 0,
			'day'      => $ts ? wp_date( 'Y-m-d', $ts ) : $range['from'],
			'items'    => $items,
		);
	}
	return $rows;
}

/**
 * Lo más vendido en los últimos N días, por talla y color (o producto si es simple).
 *
 * @return array<int,array{id:int,pid:int,vid:int,name:string,units:int,revenue:float}>
 */
function dox_pos_ai_top_sellers( $days = 30 ) {
	static $cache = array();
	$days = max( 1, (int) $days );
	if ( isset( $cache[ $days ] ) ) {
		return $cache[ $days ];
	}
	$now  = current_time( 'timestamp' );
	$rows = dox_pos_ai_period_orders( wp_date( 'Y-m-d', $now - ( $days - 1 ) * DAY_IN_SECONDS ), wp_date( 'Y-m-d', $now ) );
	$agg  = array();
	foreach ( $rows as $r ) {
		foreach ( $r['items'] as $it ) {
			$k = $it['vid'] ? $it['vid'] : $it['pid'];
			if ( ! $k ) {
				continue;
			}
			if ( ! isset( $agg[ $k ] ) ) {
				$agg[ $k ] = array( 'id' => $k, 'pid' => $it['pid'], 'vid' => $it['vid'], 'name' => $it['name'], 'units' => 0, 'revenue' => 0.0 );
			}
			$agg[ $k ]['units']   += $it['qty'];
			$agg[ $k ]['revenue'] += $it['total'];
		}
	}
	uasort( $agg, fn( $a, $b ) => $b['units'] <=> $a['units'] ?: $b['revenue'] <=> $a['revenue'] );
	$cache[ $days ] = $agg;
	return $agg;
}

/**
 * Los pedidos que todavía no terminaron.
 *
 * @return WC_Order[]
 */
function dox_pos_ai_open_orders() {
	$args = array(
		'limit'   => 300,
		'type'    => 'shop_order',
		'orderby' => 'date',
		'order'   => 'ASC',
		'status'  => array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-enviado', 'wc-failed' ),
	);
	if ( ! dox_pos_show_web_orders() ) {
		$args['created_via'] = DOX_POS_VIA;
	}
	return wc_get_orders( $args );
}

/**
 * Los pendientes de hoy: qué pedido se quedó atrás y por qué, con qué se puede hacer; lo que
 * hay por cobrar; lo que se agota de lo que se vende; y cómo fue ayer.
 *
 * @return array items, money, stock, yesterday, counts.
 */
function dox_pos_ai_pending() {
	$s     = dox_pos_ai_settings();
	$now   = time();
	$items = array();
	$money = array( 'cod' => 0.0, 'cod_n' => 0, 'holds' => 0.0, 'holds_n' => 0 );
	$seen  = array();
	$dias  = function ( $h ) {
		$d = (int) floor( $h / 24 );
		/* translators: %d: días */
		return $d >= 1 ? sprintf( _n( '%d día', '%d días', $d, 'dox-pos' ), $d ) : sprintf( _n( '%d hora', '%d horas', (int) $h, 'dox-pos' ), (int) $h );
	};
	foreach ( dox_pos_ai_open_orders() as $o ) {
		$f      = dox_pos_format_order( $o );
		$ts     = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : $now;
		$mod    = $o->get_date_modified() ? $o->get_date_modified()->getTimestamp() : $ts;
		$age    = max( 0, ( $now - $ts ) / 3600 );
		$since  = max( 0, ( $now - $mod ) / 3600 );
		$who    = $f['customer'] ? $f['customer'] : __( 'sin nombre', 'dox-pos' );
		$num    = '#' . $f['number'];
		$total  = dox_pos_money( $f['total'] );
		$wa     = $f['phone'] ? dox_pos_whatsapp_url( $f['phone'], dox_pos_web_message( $o ) ) : '';
		$add    = function ( $kind, $sev, $title, $detail, $acts ) use ( &$items, $f, $age, $wa ) {
			$items[] = array( 'kind' => $kind, 'sev' => $sev, 'title' => $title, 'detail' => $detail, 'actions' => $acts, 'age' => $age, 'order' => $f, 'wa' => $wa );
		};
		switch ( $f['status'] ) {
			case 'apartado':
				$money['holds']   += $f['total'];
				$money['holds_n']++;
				$until = (int) $o->get_meta( '_dox_pos_hold_until' );
				if ( $until && $until < $now ) {
					/* translators: 1: pedido, 2: cliente, 3: hace cuánto, 4: total */
					$add( 'apartado_vencido', 'alta', __( 'Apartado vencido sin liberar', 'dox-pos' ), sprintf( __( '%1$s de %2$s venció hace %3$s y sigue reservando el inventario (%4$s).', 'dox-pos' ), $num, $who, $dias( ( $now - $until ) / 3600 ), $total ), array( 'release', 'paid', 'whatsapp' ) );
				} elseif ( $until && $until - $now < 6 * HOUR_IN_SECONDS ) {
					/* translators: 1: pedido, 2: cliente, 3: horas, 4: total */
					$add( 'apartado_vence', 'media', __( 'Apartado por vencer', 'dox-pos' ), sprintf( __( '%1$s de %2$s vence en %3$d h (%4$s). Un mensaje a tiempo suele cerrarlo.', 'dox-pos' ), $num, $who, max( 1, (int) ceil( ( $until - $now ) / 3600 ) ), $total ), array( 'whatsapp', 'paid', 'release' ) );
				}
				break;
			case 'sin_pagar':
				if ( $age >= $s['pay_hours'] ) {
					/* translators: 1: pedido, 2: cliente, 3: hace cuánto, 4: total */
					$add( 'web_sin_pagar', 'media', __( 'Compra de la web sin terminar', 'dox-pos' ), sprintf( __( '%1$s de %2$s lleva %3$s sin pagar (%4$s). Escríbele por WhatsApp o anúlalo.', 'dox-pos' ), $num, $who, $dias( $age ), $total ), array( 'whatsapp', 'paid', 'cancel' ) );
				}
				break;
			case 'por_confirmar':
				if ( $age >= $s['pay_hours'] ) {
					/* translators: 1: pedido, 2: cliente, 3: hace cuánto, 4: total */
					$add( 'web_por_confirmar', 'alta', __( 'Pago por confirmar', 'dox-pos' ), sprintf( __( '%1$s de %2$s: el pago lleva %3$s en proceso (%4$s). Mira en la pasarela si entró; si no, anúlalo para soltar el inventario.', 'dox-pos' ), $num, $who, $dias( $age ), $total ), array( 'paid', 'cancel', 'whatsapp' ) );
				}
				break;
			case 'fallido':
				/* translators: 1: pedido, 2: cliente, 3: total */
				$add( 'web_fallido', 'media', __( 'Pago rechazado', 'dox-pos' ), sprintf( __( '%1$s de %2$s: la pasarela rechazó el pago (%3$s). Ofrécele otra forma de pagar.', 'dox-pos' ), $num, $who, $total ), array( 'whatsapp', 'paid', 'cancel' ) );
				break;
			case 'por_enviar':
				if ( $age >= $s['ship_days'] * 24 ) {
					/* translators: 1: pedido, 2: cliente, 3: ciudad, 4: hace cuánto */
					$add( 'por_enviar', 'alta', __( 'Por enviar desde hace días', 'dox-pos' ), sprintf( __( '%1$s de %2$s (%3$s) lleva %4$s sin salir.', 'dox-pos' ), $num, $who, $f['city'] ? $f['city'] : __( 'sin ciudad', 'dox-pos' ), $dias( $age ) ), array( 'shipped', 'whatsapp' ) );
				}
				if ( 'caja' === $f['origin'] && '' === trim( (string) $f['phone'] ) ) {
					/* translators: 1: pedido, 2: cliente */
					$add( 'sin_telefono', 'baja', __( 'Pedido sin teléfono', 'dox-pos' ), sprintf( __( '%1$s de %2$s no tiene WhatsApp: si hay que avisar del envío, no hay a dónde.', 'dox-pos' ), $num, $who ), array() );
				}
				break;
			case 'enviado':
				if ( $f['cod'] ) {
					$money['cod'] += $f['total'];
					$money['cod_n']++;
				}
				if ( $since >= $s['deliver_days'] * 24 ) {
					if ( $f['cod'] ) {
						/* translators: 1: pedido, 2: cliente, 3: hace cuánto, 4: total */
						$add( 'cod_por_cobrar', 'alta', __( 'Contraentrega por cobrar', 'dox-pos' ), sprintf( __( '%1$s de %2$s salió hace %3$s y sigue sin marcarse entregado: confirma si la transportadora ya cobró %4$s.', 'dox-pos' ), $num, $who, $dias( $since ), $total ), array( 'delivered', 'whatsapp' ) );
					} else {
						/* translators: 1: pedido, 2: cliente, 3: hace cuánto */
						$add( 'enviado_viejo', 'media', __( 'Enviado sin marcar entregado', 'dox-pos' ), sprintf( __( '%1$s de %2$s salió hace %3$s. Si ya llegó, márcalo entregado para cerrarlo.', 'dox-pos' ), $num, $who, $dias( $since ) ), array( 'delivered', 'whatsapp' ) );
					}
				}
				if ( '' === trim( (string) $f['tracking'] ) ) {
					/* translators: 1: pedido, 2: cliente */
					$add( 'sin_guia', 'baja', __( 'Enviado sin guía', 'dox-pos' ), sprintf( __( '%1$s de %2$s se marcó enviado sin transportadora ni guía.', 'dox-pos' ), $num, $who ), array( 'whatsapp' ) );
				}
				break;
		}
		if ( $f['total'] <= 0 && ! in_array( $f['status'], array( 'anulado', 'reembolsado' ), true ) ) {
			/* translators: 1: pedido, 2: cliente */
			$add( 'total_cero', 'alta', __( 'Pedido en cero', 'dox-pos' ), sprintf( __( '%1$s de %2$s tiene total 0: el descuento se comió el valor, o se registró mal.', 'dox-pos' ), $num, $who ), array( 'cancel' ) );
		}
		$digits = preg_replace( '/\D/', '', (string) $f['phone'] );
		if ( '' !== $digits && $f['total'] > 0 ) {
			$k = $digits . '|' . round( $f['total'] );
			if ( isset( $seen[ $k ] ) && abs( $ts - $seen[ $k ]['ts'] ) <= 2 * HOUR_IN_SECONDS ) {
				/* translators: 1: pedido, 2: otro pedido, 3: cliente, 4: minutos */
				$add( 'repetido', 'media', __( 'Posible pedido repetido', 'dox-pos' ), sprintf( __( '%1$s y %2$s de %3$s tienen el mismo teléfono y el mismo total, con %4$d minutos de diferencia.', 'dox-pos' ), $num, '#' . $seen[ $k ]['number'], $who, (int) round( abs( $ts - $seen[ $k ]['ts'] ) / 60 ) ), array( 'cancel' ) );
			}
			$seen[ $k ] = array( 'ts' => $ts, 'number' => $f['number'] );
		}
	}
	$rank = array( 'alta' => 0, 'media' => 1, 'baja' => 2 );
	usort( $items, fn( $a, $b ) => $rank[ $a['sev'] ] <=> $rank[ $b['sev'] ] ?: $b['age'] <=> $a['age'] );
	$counts = array( 'alta' => 0, 'media' => 0, 'baja' => 0 );
	foreach ( $items as $it ) {
		$counts[ $it['sev'] ]++;
	}

	// Lo que se agota de lo que se vendió en 30 días.
	$stock = array();
	foreach ( dox_pos_ai_top_sellers( 30 ) as $t ) {
		if ( $t['units'] < 1 ) {
			continue;
		}
		$p = wc_get_product( $t['id'] );
		if ( ! $p || ! $p->managing_stock() ) {
			continue;
		}
		$q = (int) $p->get_stock_quantity();
		if ( $q <= $s['low_stock'] ) {
			$stock[] = array( 'id' => $t['id'], 'product_id' => $t['pid'], 'name' => dox_pos_item_name( $p ), 'stock' => $q, 'units' => $t['units'] );
		}
		if ( count( $stock ) >= 15 ) {
			break;
		}
	}

	// Ayer, frente al mismo día de la semana pasada y al promedio de los últimos 7 días.
	$tnow  = current_time( 'timestamp' );
	$yday  = wp_date( 'Y-m-d', $tnow - DAY_IN_SECONDS );
	$y     = dox_pos_history_sales( $yday, $yday, 0, 0 );
	$lw    = dox_pos_history_sales( wp_date( 'Y-m-d', $tnow - 8 * DAY_IN_SECONDS ), wp_date( 'Y-m-d', $tnow - 8 * DAY_IN_SECONDS ), 0, 0 )['totals'];
	$w7    = dox_pos_history_sales( wp_date( 'Y-m-d', $tnow - 7 * DAY_IN_SECONDS ), $yday, 0, 0 )['totals'];
	$yest  = array(
		'date'       => $yday,
		'date_label' => wp_date( 'l j \d\e F', $tnow - DAY_IN_SECONDS ),
		'sold'       => $y['totals']['sold'],
		'orders'     => $y['totals']['orders'],
		'units'      => $y['totals']['units'],
		'by_channel' => array_slice( $y['by_channel'], 0, 4 ),
		'last_week'  => $lw['sold'],
		'avg7'       => round( $w7['sold'] / 7 ),
	);
	return array( 'items' => $items, 'money' => $money, 'stock' => $stock, 'yesterday' => $yest, 'counts' => $counts );
}

/* =====================================================================
 * Los números del negocio y los consejos
 * ===================================================================== */

/**
 * Cómo va el negocio en los últimos N días, comparado con los N anteriores.
 */
function dox_pos_ai_insights( $days = 30, $fresh = false ) {
	$days = min( 365, max( 7, (int) $days ) );
	$key  = 'dox_pos_ai_ins_' . $days;
	if ( ! $fresh ) {
		$c = get_transient( $key );
		if ( is_array( $c ) ) {
			return $c;
		}
	}
	$now   = current_time( 'timestamp' );
	$to    = wp_date( 'Y-m-d', $now );
	$from  = wp_date( 'Y-m-d', $now - ( $days - 1 ) * DAY_IN_SECONDS );
	$pto   = wp_date( 'Y-m-d', $now - $days * DAY_IN_SECONDS );
	$pfrom = wp_date( 'Y-m-d', $now - ( 2 * $days - 1 ) * DAY_IN_SECONDS );
	$cur   = dox_pos_ai_period_orders( $from, $to );
	$prev  = dox_pos_ai_period_orders( $pfrom, $pto );
	$sum   = function ( $rows ) {
		$t = array( 'orders' => count( $rows ), 'sold' => 0.0, 'units' => 0, 'avg' => 0.0 );
		foreach ( $rows as $r ) {
			$t['sold']  += $r['total'];
			$t['units'] += $r['units'];
		}
		$t['avg'] = $t['orders'] ? round( $t['sold'] / $t['orders'] ) : 0;
		return $t;
	};
	$t      = $sum( $cur );
	$tp     = $sum( $prev );
	$change = $tp['sold'] > 0 ? (int) round( ( $t['sold'] - $tp['sold'] ) / $tp['sold'] * 100 ) : null;
	$group  = function ( $rows, $field ) {
		$g = array();
		foreach ( $rows as $r ) {
			$k = (string) $r[ $field ];
			$k = '' === $k ? __( 'Sin dato', 'dox-pos' ) : $k;
			if ( ! isset( $g[ $k ] ) ) {
				$g[ $k ] = array( 'name' => $k, 'n' => 0, 'total' => 0.0 );
			}
			$g[ $k ]['n']++;
			$g[ $k ]['total'] += $r['total'];
		}
		usort( $g, fn( $a, $b ) => $b['total'] <=> $a['total'] );
		return array_values( $g );
	};
	$names = array( 1 => __( 'lunes', 'dox-pos' ), __( 'martes', 'dox-pos' ), __( 'miércoles', 'dox-pos' ), __( 'jueves', 'dox-pos' ), __( 'viernes', 'dox-pos' ), __( 'sábado', 'dox-pos' ), __( 'domingo', 'dox-pos' ) );
	$wd    = array();
	$band  = array();
	$disc  = 0.0;
	$ship  = 0.0;
	$cod   = array( 'n' => 0, 'total' => 0.0 );
	$one   = 0;
	$phones = array();
	foreach ( $cur as $r ) {
		$d = $names[ $r['weekday'] ] ?? '';
		if ( $d ) {
			$wd[ $d ] = ( $wd[ $d ] ?? 0 ) + $r['total'];
		}
		$b = $r['hour'] < 12 ? __( 'la mañana', 'dox-pos' ) : ( $r['hour'] < 18 ? __( 'la tarde', 'dox-pos' ) : __( 'la noche', 'dox-pos' ) );
		$band[ $b ] = ( $band[ $b ] ?? 0 ) + $r['total'];
		$disc      += $r['discount'];
		$ship      += $r['shipping'];
		if ( $r['cod'] ) {
			$cod['n']++;
			$cod['total'] += $r['total'];
		}
		if ( 1 === $r['units'] ) {
			$one++;
		}
		if ( '' !== $r['phone'] ) {
			$phones[ $r['phone'] ] = ( $phones[ $r['phone'] ] ?? 0 ) + 1;
		}
	}
	arsort( $wd );
	arsort( $band );
	$cat = dox_pos_ai_catalog();
	$top = array();
	foreach ( array_slice( dox_pos_ai_top_sellers( $days ), 0, 8, true ) as $x ) {
		$p = wc_get_product( $x['id'] );
		$top[] = array( 'id' => $x['id'], 'product_id' => $x['pid'], 'name' => $p ? dox_pos_item_name( $p ) : $x['name'], 'units' => $x['units'], 'revenue' => round( $x['revenue'] ), 'stock' => $p && $p->managing_stock() ? (int) $p->get_stock_quantity() : null );
	}
	$bycat = array();
	foreach ( $cur as $r ) {
		foreach ( $r['items'] as $it ) {
			foreach ( (array) ( $cat[ $it['pid'] ]['cats'] ?? array( __( 'Sin categoría', 'dox-pos' ) ) ) as $c ) {
				$bycat[ $c ] = ( $bycat[ $c ] ?? 0 ) + $it['total'];
			}
		}
	}
	arsort( $bycat );
	// Apartados y compras de la web sin pagar en el periodo, mirando todos los estados.
	$all   = dox_pos_ai_period_orders( $from, $to, array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-enviado', 'wc-completed', 'wc-cancelled', 'wc-failed', 'wc-refunded' ) );
	$holds = array( 'n' => 0, 'paid' => 0, 'released' => 0, 'open' => 0 );
	$aband = 0;
	foreach ( $all as $r ) {
		if ( $r['hold'] ) {
			$holds['n']++;
			if ( in_array( $r['status'], array( 'processing', 'enviado', 'completed' ), true ) ) {
				$holds['paid']++;
			} elseif ( 'cancelled' === $r['status'] ) {
				$holds['released']++;
			} else {
				$holds['open']++;
			}
		}
		if ( 'web' === $r['origin'] && in_array( $r['status'], array( 'pending', 'failed' ), true ) ) {
			$aband++;
		}
	}
	// El inventario: cuánto hay, qué está oculto, qué queda en una talla y qué no rota.
	$inv     = array( 'units' => 0, 'value' => 0.0, 'hidden_n' => 0, 'hidden_value' => 0.0, 'single_n' => 0, 'single_value' => 0.0, 'dead_n' => 0, 'dead_value' => 0.0 );
	$sold90  = array();
	if ( $t['orders'] > 0 ) {
		foreach ( dox_pos_ai_top_sellers( 90 ) as $x ) {
			$sold90[ $x['pid'] ] = true;
		}
	}
	$edge = $now - 90 * DAY_IN_SECONDS;
	foreach ( $cat as $p ) {
		if ( 'publish' === $p['status'] && $p['total'] > 0 ) {
			$inv['units'] += $p['total'];
			$inv['value'] += $p['value'];
			if ( ! $p['shared'] && $p['managed'] >= 2 && 1 === $p['with'] ) {
				$inv['single_n']++;
				$inv['single_value'] += $p['value'];
			}
			if ( $t['orders'] > 0 && ! isset( $sold90[ $p['id'] ] ) && strtotime( $p['created'] ) < $edge ) {
				$inv['dead_n']++;
				$inv['dead_value'] += $p['value'];
			}
		}
		if ( 'private' === $p['status'] && $p['total'] > 0 ) {
			$inv['hidden_n']++;
			$inv['hidden_value'] += $p['value'];
		}
	}
	$out = array(
		'days'       => $days,
		'from'       => $from,
		'to'         => $to,
		'totals'     => $t + array( 'change' => $change, 'prev_sold' => $tp['sold'], 'prev_orders' => $tp['orders'] ),
		'by_channel' => array_slice( $group( $cur, 'channel' ), 0, 6 ),
		'by_payment' => array_slice( $group( $cur, 'payment' ), 0, 6 ),
		'by_city'    => array_slice( $group( $cur, 'city' ), 0, 6 ),
		'by_seller'  => array_slice( $group( $cur, 'seller' ), 0, 6 ),
		'by_weekday' => $wd,
		'by_band'    => $band,
		'discount'   => round( $disc ),
		'shipping'   => round( $ship ),
		'cod'        => $cod,
		'single'     => $one,
		'repeat'     => count( array_filter( $phones, fn( $n ) => $n >= 2 ) ),
		'customers'  => count( $phones ),
		'top'        => $top,
		'by_cat'     => array_slice( $bycat, 0, 6, true ),
		'holds'      => $holds,
		'abandoned'  => $aband,
		'inventory'  => $inv,
	);
	set_transient( $key, $out, 30 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * Consejos por reglas, con la cifra que los sustenta. Sin modelo: es lo que se enseña cuando
 * no hay clave; con clave, el modelo los ordena y redacta.
 *
 * @return array<int,array{title:string,text:string,go:string}>
 */
function dox_pos_ai_rule_advice( $ins, $pend, $checks ) {
	$out = array();
	$c   = array();
	foreach ( $checks['checks'] as $ch ) {
		$c[ $ch['key'] ] = $ch;
	}
	$t   = $ins['totals'];
	$inv = $ins['inventory'];
	$m   = 'dox_pos_money';
	$n   = fn( $x ) => number_format_i18n( (float) $x );
	if ( $t['orders'] < 5 ) {
		$bits = array();
		if ( ! empty( $c['sin_descripcion']['count'] ) ) {
			$bits[] = sprintf( __( '%s sin descripción', 'dox-pos' ), $n( $c['sin_descripcion']['count'] ) );
		}
		if ( ! empty( $c['sin_foto']['count'] ) ) {
			$bits[] = sprintf( __( '%s sin foto', 'dox-pos' ), $n( $c['sin_foto']['count'] ) );
		}
		if ( ! empty( $c['oculto_con_stock']['count'] ) ) {
			$bits[] = sprintf( __( '%s ocultos con existencias', 'dox-pos' ), $n( $c['oculto_con_stock']['count'] ) );
		}
		$out[] = array(
			'title' => __( 'Todavía hay pocas ventas registradas', 'dox-pos' ),
			'text'  => sprintf( __( 'Con una semana de ventas en la caja te diré qué se mueve y qué no. Mientras tanto, lo que más ayuda a vender es tener la tienda completa: %s.', 'dox-pos' ), $bits ? implode( ', ', $bits ) : __( 'fotos, descripciones y existencias al día', 'dox-pos' ) ),
			'go'    => 'revision',
		);
	}
	$low = array();
	foreach ( $pend['stock'] as $sx ) {
		$low[] = sprintf( __( '%1$s (vendió %2$d, quedan %3$d)', 'dox-pos' ), $sx['name'], $sx['units'], $sx['stock'] );
		if ( count( $low ) >= 3 ) {
			break;
		}
	}
	if ( $low ) {
		$out[] = array( 'title' => __( 'Reponer lo que se está agotando', 'dox-pos' ), 'text' => sprintf( __( 'Se vende y casi no queda: %s. Pide antes de que se agote del todo.', 'dox-pos' ), implode( '; ', $low ) ), 'go' => '' );
	}
	// Con historia suficiente, el pronóstico afina: qué se acaba esta semana y cómo va el mes.
	$fc = dox_pos_ai_forecast();
	if ( $fc['enough'] ) {
		$week = array_slice( array_values( array_filter( $fc['soon'], fn( $i ) => $i['days'] <= 7 && 'publish' === $i['status'] ) ), 0, 3 );
		if ( $week ) {
			/* translators: 1: producto, 2: existencias, 3: cuánto dura */
			$bits = array_map( fn( $i ) => sprintf( __( '%1$s (quedan %2$d, dura %3$s)', 'dox-pos' ), $i['name'], $i['stock'], $i['days'] < 1 ? __( 'menos de un día', 'dox-pos' ) : sprintf( _n( '%d día', '%d días', $i['days'], 'dox-pos' ), $i['days'] ) ), $week );
			$need = array_map( fn( $i ) => $i['name'] . ': ' . $i['reorder'], array_filter( $week, fn( $i ) => $i['reorder'] > 0 ) );
			/* translators: %s: lista */
			$out[] = array( 'title' => __( 'Se agota en menos de una semana', 'dox-pos' ), 'text' => sprintf( __( 'Al ritmo de venta actual: %s.', 'dox-pos' ), implode( '; ', $bits ) ) . ( $need ? ' ' . sprintf( __( 'Para cubrir un mes harían falta %s.', 'dox-pos' ), implode( ', ', $need ) ) : '' ), 'go' => '' );
		}
		$mo = $fc['month'];
		if ( $mo['last'] > 0 && $mo['day'] >= 7 ) {
			$pct = (int) round( ( $mo['projection'] - $mo['last'] ) / $mo['last'] * 100 );
			if ( abs( $pct ) >= 15 ) {
				$out[] = array(
					'title' => $pct > 0 ? sprintf( __( 'El mes va camino de subir un %d %%', 'dox-pos' ), $pct ) : sprintf( __( 'El mes va camino de bajar un %d %%', 'dox-pos' ), abs( $pct ) ),
					/* translators: 1: vendido, 2: día, 3: mes, 4: proyección, 5: mes anterior, 6: nombre del mes anterior */
					'text'  => sprintf( __( 'Con %1$s vendidos hasta el día %2$d, %3$s cerraría en unos %4$s frente a %5$s de %6$s. Es una proyección con el ritmo actual, no una promesa.', 'dox-pos' ), $m( $mo['mtd'] ), $mo['day'], $mo['name'], $m( $mo['projection'] ), $m( $mo['last'] ), $mo['last_name'] ),
					'go'    => '',
				);
			}
		}
	}
	if ( $inv['dead_n'] > 0 ) {
		$out[] = array( 'title' => __( 'Mover lo que no rota', 'dox-pos' ), 'text' => sprintf( __( '%1$s productos con %2$s en existencias no han vendido nada en 90 días. Un descuento del 20 %% o un combo con lo que sí rota los saca.', 'dox-pos' ), $n( $inv['dead_n'] ), $m( $inv['dead_value'] ) ), 'go' => '' );
	}
	if ( $inv['single_n'] >= 3 ) {
		$out[] = array( 'title' => __( 'Un outlet con las últimas tallas', 'dox-pos' ), 'text' => sprintf( __( '%1$s productos quedan en una sola talla (%2$s). Juntarlos en una sección de últimas unidades con un precio especial libera plata y espacio.', 'dox-pos' ), $n( $inv['single_n'] ), $m( $inv['single_value'] ) ), 'go' => 'revision:una_talla' );
	}
	if ( $inv['hidden_n'] > 0 ) {
		$out[] = array( 'title' => __( 'Productos ocultos con existencias', 'dox-pos' ), 'text' => sprintf( __( '%1$s productos están ocultos y tienen %2$s en unidades que nadie puede comprar. Publícalos o decide qué hacer con ellos.', 'dox-pos' ), $n( $inv['hidden_n'] ), $m( $inv['hidden_value'] ) ), 'go' => 'revision:oculto_con_stock' );
	}
	if ( ! empty( $c['sin_descripcion']['count'] ) && $t['orders'] >= 5 ) {
		$out[] = array( 'title' => __( 'Fichas sin descripción', 'dox-pos' ), 'text' => sprintf( __( '%s productos publicados no tienen descripción. En la ficha y en Google, un texto corto vende; el asistente los redacta con la foto y tú apruebas.', 'dox-pos' ), $n( $c['sin_descripcion']['count'] ) ), 'go' => 'revision:sin_descripcion' );
	}
	if ( $t['orders'] >= 5 && $ins['by_channel'] ) {
		$ch  = $ins['by_channel'][0];
		$pct = $t['sold'] > 0 ? (int) round( $ch['total'] / $t['sold'] * 100 ) : 0;
		if ( $pct >= 50 ) {
			$out[] = array( 'title' => sprintf( __( 'El %1$d %% entra por %2$s', 'dox-pos' ), $pct, $ch['name'] ), 'text' => sprintf( __( 'De %1$s vendidos en %2$d días, %3$s llegaron por %4$s. Cuida ese canal: responde rápido y publica ahí lo que se está agotando.', 'dox-pos' ), $m( $t['sold'] ), $ins['days'], $m( $ch['total'] ), $ch['name'] ), 'go' => '' );
		}
	}
	if ( $t['orders'] >= 5 && $ins['cod']['n'] > 0 ) {
		$pct = (int) round( $ins['cod']['n'] / $t['orders'] * 100 );
		if ( $pct >= 30 ) {
			$out[] = array( 'title' => sprintf( __( '%d %% de las ventas son contraentrega', 'dox-pos' ), $pct ), 'text' => sprintf( __( '%1$s en %2$d pedidos se cobran al entregar. Marca cada uno como entregado cuando la transportadora pague, para saber qué plata ya entró.', 'dox-pos' ), $m( $ins['cod']['total'] ), $ins['cod']['n'] ), 'go' => 'pedidos' );
		}
	}
	if ( $t['sold'] > 0 && $ins['discount'] > 0 ) {
		$pct = (int) round( $ins['discount'] / ( $t['sold'] + $ins['discount'] ) * 100 );
		if ( $pct >= 8 ) {
			$out[] = array( 'title' => sprintf( __( 'Los descuentos se llevan el %d %%', 'dox-pos' ), $pct ), 'text' => sprintf( __( 'En %1$d días se rebajaron %2$s. Si pasa del 10 %%, revisa que no se esté regalando margen: mejor un regalo pequeño que un descuento.', 'dox-pos' ), $ins['days'], $m( $ins['discount'] ) ), 'go' => '' );
		}
	}
	if ( $t['orders'] >= 10 && $ins['by_weekday'] && $ins['by_band'] ) {
		$day  = array_key_first( $ins['by_weekday'] );
		$band = array_key_first( $ins['by_band'] );
		$out[] = array( 'title' => sprintf( __( 'Se vende más los %1$s por %2$s', 'dox-pos' ), $day, $band ), 'text' => sprintf( __( 'Los %1$s suman %2$s y por %3$s entra %4$s. Publica y responde en esas horas; agenda las historias para ese día.', 'dox-pos' ), $day, $m( $ins['by_weekday'][ $day ] ), $band, $m( $ins['by_band'][ $band ] ) ), 'go' => '' );
	}
	if ( $t['orders'] >= 10 ) {
		$pct = (int) round( $ins['single'] / $t['orders'] * 100 );
		if ( $pct >= 60 ) {
			$out[] = array( 'title' => sprintf( __( 'Ticket promedio de %s', 'dox-pos' ), $m( $t['avg'] ) ), 'text' => sprintf( __( 'El %d %% de las ventas son de una sola unidad. Al cerrar, ofrece un complemento (un accesorio, un lazo, la talla siguiente): sube el ticket sin buscar más clientas.', 'dox-pos' ), $pct ), 'go' => '' );
		}
	}
	if ( $ins['holds']['n'] >= 4 && $ins['holds']['released'] > $ins['holds']['paid'] ) {
		$out[] = array( 'title' => __( 'Se liberan más apartados de los que se pagan', 'dox-pos' ), 'text' => sprintf( __( 'De %1$d apartados, %2$d se pagaron y %3$d se liberaron. Pide un abono al apartar o acorta el plazo.', 'dox-pos' ), $ins['holds']['n'], $ins['holds']['paid'], $ins['holds']['released'] ), 'go' => '' );
	}
	if ( $ins['abandoned'] >= 3 ) {
		$out[] = array( 'title' => sprintf( __( '%d compras de la web quedaron sin pagar', 'dox-pos' ), $ins['abandoned'] ), 'text' => __( 'Llegaron al pago y no lo terminaron. Un WhatsApp de "¿te ayudo a terminar tu compra?" suele cerrar varias.', 'dox-pos' ), 'go' => 'pedidos' );
	}
	if ( $ins['repeat'] >= 3 ) {
		$out[] = array( 'title' => sprintf( __( '%d clientas repitieron compra', 'dox-pos' ), $ins['repeat'] ), 'text' => sprintf( __( 'De %1$d clientas con teléfono, %2$d compraron más de una vez en %3$d días. Un mensaje de gracias o un detalle en el siguiente envío las fideliza.', 'dox-pos' ), $ins['customers'], $ins['repeat'], $ins['days'] ), 'go' => '' );
	}
	if ( null !== $t['change'] && abs( $t['change'] ) >= 20 ) {
		$out[] = array(
			'title' => $t['change'] > 0 ? sprintf( __( 'Las ventas subieron un %d %%', 'dox-pos' ), $t['change'] ) : sprintf( __( 'Las ventas bajaron un %d %%', 'dox-pos' ), abs( $t['change'] ) ),
			'text'  => sprintf( __( '%1$s en los últimos %2$d días frente a %3$s en los %2$d anteriores.', 'dox-pos' ), $m( $t['sold'] ), $ins['days'], $m( $t['prev_sold'] ) ) . ( $t['change'] < 0 ? ' ' . __( 'Mira qué canal cayó y qué se dejó de publicar.', 'dox-pos' ) : '' ),
			'go'    => '',
		);
	}
	return array_slice( $out, 0, 6 );
}

/**
 * Los consejos de hoy: por reglas y, si hay clave, redactados por el modelo. Se guardan seis horas.
 */
function dox_pos_ai_advice( $fresh = false ) {
	if ( ! $fresh ) {
		$c = dox_pos_ai_kept( 'advice', 6 * HOUR_IN_SECONDS );
		if ( is_array( $c ) ) {
			return $c;
		}
	}
	$ins    = dox_pos_ai_insights( 30, $fresh );
	$pend   = dox_pos_ai_pending();
	$checks = dox_pos_ai_checks( false );
	$rules  = dox_pos_ai_rule_advice( $ins, $pend, $checks );
	$out    = array( 'items' => $rules, 'ai' => false, 'at' => wp_date( 'G:i' ) );
	// Dos peticiones a la vez (Hoy en dos ventanas, o Hoy y el resumen) no pagan dos veces la redacción:
	// mientras una consulta al modelo, la otra se lleva los consejos por reglas sin guardarlos.
	if ( dox_pos_ai_enabled() && $rules && dox_pos_ai_kept( 'advice_lock', 2 * MINUTE_IN_SECONDS ) ) {
		return $out;
	}
	if ( dox_pos_ai_enabled() && $rules ) {
		dox_pos_ai_keep( 'advice_lock', 1 );
		$brand = dox_pos_brand_name();
		$r     = dox_pos_ai_call(
			array(
				'kind'         => 'advice',
				'instructions' => sprintf( __( 'Eres el asesor de negocio de %s, una tienda que vende por su página web, por WhatsApp e Instagram y en persona, y registra todo en la caja Dox POS. Recibes los números de los últimos días (JSON) y una lista de consejos calculados por reglas. Devuelve entre 3 y 6 consejos, del más al menos importante para vender más y cobrar mejor. Cada uno: un título de máximo 8 palabras y un texto de una o dos frases con la cifra que lo sustenta. Solo cifras que estén en el JSON; no inventes nada. Español de Colombia, de tú, concreto, sin emojis ni signos raros. El campo "ver" se copia del consejo por reglas en que se basa (o queda vacío).', 'dox-pos' ), $brand ),
				'input'        => array( array( 'role' => 'user', 'content' => wp_json_encode( array( 'negocio' => dox_pos_ai_compact_insights( $ins ), 'consejos_por_reglas' => $rules ), JSON_UNESCAPED_UNICODE ) ) ),
				'schema'       => array(
					'name'   => 'consejos',
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'consejos' ),
						'properties'           => array(
							'consejos' => array(
								'type'  => 'array',
								'items' => array(
									'type'                 => 'object',
									'additionalProperties' => false,
									'required'             => array( 'titulo', 'texto', 'ver' ),
									'properties'           => array(
										'titulo' => array( 'type' => 'string' ),
										'texto'  => array( 'type' => 'string' ),
										'ver'    => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
				'effort'       => 'medium',
				'max_output'   => 3000,
				'timeout'      => 60,
			)
		);
		$j = dox_pos_ai_json( $r );
		if ( $j && ! empty( $j['consejos'] ) && is_array( $j['consejos'] ) ) {
			$items = array();
			foreach ( array_slice( $j['consejos'], 0, 6 ) as $x ) {
				$title = sanitize_text_field( (string) ( $x['titulo'] ?? '' ) );
				$text  = sanitize_textarea_field( (string) ( $x['texto'] ?? '' ) );
				if ( '' === $title || '' === $text ) {
					continue;
				}
				$items[] = array( 'title' => $title, 'text' => $text, 'go' => preg_replace( '/[^a-z_:0-9]/', '', (string) ( $x['ver'] ?? '' ) ) );
			}
			if ( $items ) {
				$out['items'] = $items;
				$out['ai']    = true;
			}
		}
		dox_pos_ai_drop( 'advice_lock' );
	}
	dox_pos_ai_keep( 'advice', $out );
	return $out;
}

/**
 * Los números en pocas líneas para el modelo (sin listas largas).
 */
function dox_pos_ai_compact_insights( $ins ) {
	$m = fn( $v ) => dox_pos_money( $v );
	return array(
		'periodo_dias'          => $ins['days'],
		'vendido'               => $m( $ins['totals']['sold'] ),
		'ventas'                => $ins['totals']['orders'],
		'unidades'              => $ins['totals']['units'],
		'ticket_promedio'       => $m( $ins['totals']['avg'] ),
		'vendido_periodo_anterior' => $m( $ins['totals']['prev_sold'] ),
		'cambio_pct'            => $ins['totals']['change'],
		'por_canal'             => array_map( fn( $x ) => $x['name'] . ': ' . $m( $x['total'] ) . ' (' . $x['n'] . ')', $ins['by_channel'] ),
		'por_forma_de_pago'     => array_map( fn( $x ) => $x['name'] . ': ' . $m( $x['total'] ) . ' (' . $x['n'] . ')', $ins['by_payment'] ),
		'por_ciudad'            => array_map( fn( $x ) => $x['name'] . ': ' . $x['n'], array_slice( $ins['by_city'], 0, 4 ) ),
		'por_dia_de_semana'     => array_map( $m, $ins['by_weekday'] ),
		'por_franja'            => array_map( $m, $ins['by_band'] ),
		'descuentos'            => $m( $ins['discount'] ),
		'envios_cobrados'       => $m( $ins['shipping'] ),
		'contraentrega'         => $ins['cod']['n'] . ' pedidos, ' . $m( $ins['cod']['total'] ),
		'ventas_de_una_unidad'  => $ins['single'],
		'clientas_con_telefono' => $ins['customers'],
		'clientas_que_repiten'  => $ins['repeat'],
		'mas_vendido'           => array_map( fn( $x ) => $x['name'] . ': ' . $x['units'] . ' uds, ' . $m( $x['revenue'] ) . ( null === $x['stock'] ? '' : ', quedan ' . $x['stock'] ), $ins['top'] ),
		'por_categoria'         => array_map( $m, $ins['by_cat'] ),
		'apartados'             => $ins['holds'],
		'compras_web_sin_pagar' => $ins['abandoned'],
		'inventario'            => array(
			'unidades'                => $ins['inventory']['units'],
			'valor'                   => $m( $ins['inventory']['value'] ),
			'ocultos_con_existencias' => $ins['inventory']['hidden_n'] . ' (' . $m( $ins['inventory']['hidden_value'] ) . ')',
			'en_una_sola_talla'       => $ins['inventory']['single_n'] . ' (' . $m( $ins['inventory']['single_value'] ) . ')',
			'sin_vender_90_dias'      => $ins['inventory']['dead_n'] . ' (' . $m( $ins['inventory']['dead_value'] ) . ')',
		),
	);
}

/* =====================================================================
 * Hoy: lo que ve Camila al abrir el asistente
 * ===================================================================== */

function dox_pos_ai_today( $fresh = false ) {
	$s      = dox_pos_ai_settings();
	$pend   = dox_pos_ai_pending();
	$adv    = dox_pos_ai_advice( $fresh );
	$fc     = dox_pos_ai_forecast( 30, $fresh );
	$now    = current_time( 'timestamp' );
	$monday = $now - ( ( (int) wp_date( 'N', $now ) - 1 ) * DAY_IN_SECONDS );
	$week   = dox_pos_history_sales( wp_date( 'Y-m-d', $monday ), wp_date( 'Y-m-d', $now ), 0, 0 )['totals'];
	$lweek  = dox_pos_history_sales( wp_date( 'Y-m-d', $monday - 7 * DAY_IN_SECONDS ), wp_date( 'Y-m-d', $now - 7 * DAY_IN_SECONDS ), 0, 0 )['totals'];
	$last   = get_option( 'dox_pos_ai_summary', array() );
	$sum    = null;
	if ( is_array( $last ) && ( $last['date'] ?? '' ) === wp_date( 'Y-m-d' ) ) {
		$sum = array( 'text' => (string) $last['text'], 'at' => (string) $last['at'], 'sent' => ! empty( $last['sent'] ), 'to' => count( (array) $last['to'] ), 'ai' => '' !== (string) ( $last['model'] ?? '' ) );
	}
	$u   = dox_pos_ai_month_usage();
	$txt = array( $sum ? (string) $sum['text'] : '' ); // de aquí salen los pedidos que se pueden abrir
	foreach ( $pend['items'] as $it ) {
		$txt[] = $it['detail'];
	}
	foreach ( $adv['items'] as $ad ) {
		$txt[] = $ad['text'];
	}
	return array(
		'date_label' => ucfirst( wp_date( 'l j \d\e F' ) ),
		'summary'    => $sum,
		'yesterday'  => $pend['yesterday'],
		'week'       => array( 'sold' => $week['sold'], 'orders' => $week['orders'], 'prev' => $lweek['sold'] ),
		'money'      => $pend['money'],
		'pending'    => $pend['items'],
		'counts'     => $pend['counts'],
		'stock'      => $pend['stock'],
		'forecast'   => $fc['enough'] ? array( 'days' => $fc['hist'], 'month' => $fc['month'], 'soon' => array_slice( array_values( array_filter( $fc['soon'], fn( $i ) => 'publish' === $i['status'] ) ), 0, 6 ) ) : null,
		'advice'     => $adv,
		'links'      => dox_pos_ai_links_map( $pend, $fc, $txt ),
		'ai_ready'   => dox_pos_ai_enabled(),
		'settings'   => array( 'on' => $s['summary_on'], 'hour' => $s['summary_hour'], 'to' => dox_pos_ai_recipients(), 'next' => dox_pos_ai_next_summary(), 'low_stock' => $s['low_stock'] ),
		'usage'      => array( 'calls' => $u['calls'], 'cost' => $u['cost'], 'cap' => $s['cap'] ),
	);
}

/**
 * Lo que va en la pestaña: cuántos pendientes urgentes hay. Se guarda cinco minutos.
 */
function dox_pos_ai_badge() {
	$c = get_transient( 'dox_pos_ai_badge' );
	if ( is_array( $c ) ) {
		return $c;
	}
	$pend = dox_pos_ai_pending();
	$out  = array( 'urgent' => $pend['counts']['alta'], 'pending' => count( $pend['items'] ) );
	set_transient( 'dox_pos_ai_badge', $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}

/* =====================================================================
 * El resumen diario
 * ===================================================================== */

/**
 * Arma el resumen: los datos, el texto (del modelo si hay clave, si no de plantilla) y el correo.
 *
 * @return array subject, text, html, model.
 */
function dox_pos_ai_summary_build() {
	$brand  = dox_pos_brand_name();
	$pend   = dox_pos_ai_pending();
	$adv    = dox_pos_ai_advice();
	$checks = dox_pos_ai_checks( false );
	$y      = $pend['yesterday'];
	$now    = current_time( 'timestamp' );
	$monday = $now - ( ( (int) wp_date( 'N', $now ) - 1 ) * DAY_IN_SECONDS );
	$week   = dox_pos_history_sales( wp_date( 'Y-m-d', $monday ), wp_date( 'Y-m-d', $now ), 0, 0 )['totals'];
	$lweek  = dox_pos_history_sales( wp_date( 'Y-m-d', $monday - 7 * DAY_IN_SECONDS ), wp_date( 'Y-m-d', $now - 7 * DAY_IN_SECONDS ), 0, 0 )['totals'];
	$m      = 'dox_pos_money';
	$rev    = array();
	foreach ( $checks['checks'] as $ch ) {
		if ( $ch['count'] > 0 && 'baja' !== $ch['sev'] ) {
			$rev[ $ch['label'] ] = $ch['count'];
		}
	}
	$data = array(
		'tienda'                  => $brand,
		'hoy'                     => wp_date( 'l j \d\e F' ),
		'ayer'                    => array(
			'dia'                    => $y['date_label'],
			'vendido'                => $m( $y['sold'] ),
			'ventas'                 => $y['orders'],
			'unidades'               => $y['units'],
			'por_canal'              => array_map( fn( $x ) => $x['name'] . ': ' . $m( $x['total'] ) . ' (' . $x['n'] . ')', $y['by_channel'] ),
			'mismo_dia_semana_pasada' => $m( $y['last_week'] ),
			'promedio_diario_7_dias' => $m( $y['avg7'] ),
		),
		'esta_semana'             => array( 'vendido' => $m( $week['sold'] ), 'ventas' => $week['orders'], 'semana_pasada_a_esta_altura' => $m( $lweek['sold'] ) ),
		'pendientes'              => array_map( fn( $i ) => $i['title'] . ': ' . $i['detail'], array_slice( $pend['items'], 0, 12 ) ),
		'por_cobrar_contraentrega' => $m( $pend['money']['cod'] ) . ' (' . $pend['money']['cod_n'] . ')',
		'apartados_sin_pagar'     => $m( $pend['money']['holds'] ) . ' (' . $pend['money']['holds_n'] . ')',
		'se_agota'                => array_map( fn( $x ) => $x['name'] . ': vendió ' . $x['units'] . ', quedan ' . $x['stock'], array_slice( $pend['stock'], 0, 8 ) ),
		'consejos'                => array_map( fn( $a ) => $a['title'] . '. ' . $a['text'], array_slice( $adv['items'], 0, 3 ) ),
		'revision'                => array_slice( $rev, 0, 6, true ),
	);
	$text  = '';
	$model = '';
	if ( dox_pos_ai_enabled() ) {
		$r = dox_pos_ai_call(
			array(
				'kind'         => 'summary',
				'instructions' => sprintf( __( 'Escribes el resumen de la mañana para la dueña de %s, que vende por su página web, WhatsApp e Instagram y lleva todo en la caja Dox POS. Con los datos del JSON escribe entre 5 y 9 líneas en español de Colombia, de tú, sin emojis, sin markdown ni títulos: cómo fue ayer con sus cifras (si no hubo ventas, dilo sin dramatizar y compara con la semana), lo urgente de hoy con los números de pedido, lo que se está agotando, y un consejo del día concreto. Solo cifras del JSON; nada inventado. Cada idea en su línea.', 'dox-pos' ), $brand ),
				'input'        => array( array( 'role' => 'user', 'content' => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ) ),
				'effort'       => 'low',
				'max_output'   => 1500,
				'timeout'      => 60,
			)
		);
		if ( ! is_wp_error( $r ) && '' !== $r['text'] ) {
			$text  = $r['text'];
			$model = dox_pos_ai_model();
		}
	}
	if ( '' === $text ) {
		$text = dox_pos_ai_summary_template( $y, $week, $lweek, $pend, $adv );
	}
	return array(
		/* translators: 1: marca, 2: día */
		'subject' => ( function_exists( 'dox_pos_demo_active' ) && dox_pos_demo_active() ? '[Demo] ' : '' ) . sprintf( __( '%1$s hoy, %2$s', 'dox-pos' ), $brand, wp_date( 'l j \d\e F' ) ),
		'text'    => ( function_exists( 'dox_pos_demo_active' ) && dox_pos_demo_active() ? __( 'Ojo: estos números incluyen los datos de demostración.', 'dox-pos' ) . "\n\n" : '' ) . $text,
		'html'    => dox_pos_ai_summary_html( $text, $y, $week, $pend, $adv, dox_pos_ai_links_map( $pend ) ),
		'model'   => $model,
	);
}

/**
 * El resumen sin modelo: frases hechas con los números.
 */
function dox_pos_ai_summary_template( $y, $week, $lweek, $pend, $adv ) {
	$m  = 'dox_pos_money';
	$l  = array();
	if ( $y['orders'] > 0 ) {
		/* translators: 1: día, 2: vendido, 3: ventas, 4: unidades */
		$l[] = sprintf( _n( 'Ayer, %1$s, se vendieron %2$s en %3$d venta (%4$d unidades).', 'Ayer, %1$s, se vendieron %2$s en %3$d ventas (%4$d unidades).', $y['orders'], 'dox-pos' ), $y['date_label'], $m( $y['sold'] ), $y['orders'], $y['units'] );
	} else {
		/* translators: %s: día */
		$l[] = sprintf( __( 'Ayer, %s, no se registraron ventas.', 'dox-pos' ), $y['date_label'] );
	}
	if ( $y['avg7'] > 0 ) {
		/* translators: 1: promedio, 2: semana pasada */
		$l[] = sprintf( __( 'El promedio de los últimos 7 días es %1$s al día; el mismo día de la semana pasada fueron %2$s.', 'dox-pos' ), $m( $y['avg7'] ), $m( $y['last_week'] ) );
	}
	/* translators: 1: vendido, 2: ventas, 3: semana pasada */
	$l[] = sprintf( __( 'Esta semana van %1$s en %2$d ventas (la semana pasada a esta altura, %3$s).', 'dox-pos' ), $m( $week['sold'] ), $week['orders'], $m( $lweek['sold'] ) );
	if ( $pend['items'] ) {
		/* translators: %d: pendientes */
		$l[] = sprintf( _n( 'Hay %d pendiente:', 'Hay %d pendientes:', count( $pend['items'] ), 'dox-pos' ), count( $pend['items'] ) );
		foreach ( array_slice( $pend['items'], 0, 6 ) as $it ) {
			$l[] = '- ' . $it['detail'];
		}
	} else {
		$l[] = __( 'No hay pendientes: nada por enviar tarde, ni pagos por confirmar, ni apartados vencidos.', 'dox-pos' );
	}
	if ( $pend['money']['cod_n'] || $pend['money']['holds_n'] ) {
		/* translators: 1: contraentrega, 2: cuántos, 3: apartados, 4: cuántos */
		$l[] = sprintf( __( 'Por cobrar en contraentregas: %1$s (%2$d). Apartado sin pagar: %3$s (%4$d).', 'dox-pos' ), $m( $pend['money']['cod'] ), $pend['money']['cod_n'], $m( $pend['money']['holds'] ), $pend['money']['holds_n'] );
	}
	if ( $pend['stock'] ) {
		$l[] = __( 'Se agota de lo que se vende: ', 'dox-pos' ) . implode( '; ', array_map( fn( $x ) => sprintf( __( '%1$s (quedan %2$d)', 'dox-pos' ), $x['name'], $x['stock'] ), array_slice( $pend['stock'], 0, 5 ) ) ) . '.';
	}
	if ( $adv['items'] ) {
		$l[] = __( 'Consejo del día: ', 'dox-pos' ) . $adv['items'][0]['title'] . '. ' . $adv['items'][0]['text'];
	}
	return implode( "\n", $l );
}

/* =====================================================================
 * Los enlaces del resumen y de los consejos
 * ===================================================================== */

/**
 * Qué se puede tocar en un texto del asistente: los pedidos que nombra y los productos que
 * salen en él. Se arma con lo que ya se miró (los pendientes, lo que se agota y el
 * pronóstico), así que no cuesta consultas nuevas.
 *
 * @param array      $pend   Lo que devuelve dox_pos_ai_pending().
 * @param array|null $fc     El pronóstico, si ya se calculó.
 * @param array      $textos Textos donde buscar pedidos que no estén entre los pendientes.
 * @return array orders (número => id), products (nombre => id).
 */
function dox_pos_ai_links_map( $pend, $fc = null, $textos = array() ) {
	$map = array( 'orders' => array(), 'products' => array() );
	foreach ( $pend['items'] as $it ) {
		if ( ! empty( $it['order']['id'] ) ) {
			$map['orders'][ (string) $it['order']['number'] ] = (int) $it['order']['id'];
		}
	}
	$add = function ( $name, $id ) use ( &$map ) {
		$id   = (int) $id;
		$name = trim( explode( ' · ', (string) $name )[0] ); // el nombre, sin la talla ni el color
		if ( $id > 0 && mb_strlen( $name ) >= 5 && ! isset( $map['products'][ $name ] ) ) {
			$map['products'][ $name ] = $id;
		}
	};
	foreach ( $pend['stock'] as $sx ) {
		$add( $sx['name'], empty( $sx['product_id'] ) ? $sx['id'] : $sx['product_id'] );
	}
	if ( null === $fc ) {
		$fc = dox_pos_ai_forecast();
	}
	foreach ( array_merge( (array) ( $fc['soon'] ?? array() ), (array) ( $fc['dead'] ?? array() ) ) as $i ) {
		$add( $i['name'], $i['id'] );
	}
	// Un número que nombra el texto y no está entre los pendientes (el otro de un pedido repetido,
	// uno ya cerrado): se mira una vez, para que también se pueda abrir.
	foreach ( (array) $textos as $t ) {
		if ( ! preg_match_all( '/#(\d{2,10})(?!\d)/', (string) $t, $mm ) ) {
			continue;
		}
		foreach ( array_unique( $mm[1] ) as $n ) {
			if ( ! isset( $map['orders'][ $n ] ) && count( $map['orders'] ) < 60 ) {
				$id = dox_pos_ai_order_id( $n );
				if ( $id ) {
					$map['orders'][ $n ] = $id;
				}
			}
		}
	}
	return $map;
}

/**
 * El pedido que lleva ese número, si existe y se puede abrir desde la caja. Se recuerda lo
 * mirado para no consultar dos veces el mismo número.
 *
 * @return int 0 si no hay nada que abrir.
 */
function dox_pos_ai_order_id( $number ) {
	static $seen = array();
	$n = (string) $number;
	if ( ! isset( $seen[ $n ] ) ) {
		$o          = ctype_digit( $n ) ? wc_get_order( (int) $n ) : false;
		$seen[ $n ] = ( $o instanceof WC_Order && (string) $o->get_order_number() === $n && ( dox_pos_show_web_orders() || 'caja' === dox_pos_order_origin( $o ) ) ) ? $o->get_id() : 0;
	}
	return $seen[ $n ];
}

/**
 * A dónde lleva un consejo dentro de la caja: la misma dirección que abre su botón "Ver".
 */
function dox_pos_ai_go_hash( $go ) {
	$p   = explode( ':', (string) $go );
	$que = isset( $p[1] ) ? preg_replace( '/[^a-z_0-9]/', '', $p[1] ) : '';
	switch ( $p[0] ) {
		case 'pedidos':
			return '#pedidos';
		case 'producto':
			return $que ? '#producto/' . (int) $que : '';
		case 'revision':
			return '#asistente/revision' . ( $que ? '/' . $que : '' );
		case 'chat':
			return '#asistente/chat';
	}
	return '';
}

/**
 * Deja tocables los números de pedido (#1234) y los nombres de producto de un texto que ya
 * viene escapado. Un solo barrido, así ningún enlace acaba dentro de otro.
 *
 * @param string $html  Texto ya pasado por esc_html().
 * @param array  $map   Lo que devuelve dox_pos_ai_links_map().
 * @param string $style Estilo en línea del enlace (en el correo tiene que ir ahí).
 * @return string
 */
function dox_pos_ai_linkify( $html, $map, $style = '' ) {
	$base  = dox_pos_url();
	$prods = array();
	foreach ( (array) ( $map['products'] ?? array() ) as $name => $id ) {
		$prods[ mb_strtolower( esc_html( $name ) ) ] = (int) $id;
	}
	$names = array_keys( $prods );
	usort( $names, fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) ); // primero el nombre más largo
	$alt = array();
	foreach ( $names as $n ) {
		$alt[] = preg_quote( $n, '~' );
	}
	$re  = '~#(?P<num>\d{2,10})(?!\d)' . ( $alt ? '|(?<![\p{L}\p{N}])(?P<prod>' . implode( '|', $alt ) . ')(?![\p{L}\p{N}])' : '' ) . '~ui';
	$att = $style ? ' style="' . esc_attr( $style ) . '"' : '';
	$out = preg_replace_callback(
		$re,
		function ( $m ) use ( $map, $prods, $base, $att ) {
			if ( ! empty( $m['num'] ) ) {
				$id = $map['orders'][ $m['num'] ] ?? dox_pos_ai_order_id( $m['num'] );
				$to = $id ? '#pedidos/' . $id : '';
			} else {
				$id = $prods[ mb_strtolower( $m[0] ) ] ?? 0;
				$to = $id ? '#producto/' . $id : '';
			}
			return $to ? '<a href="' . esc_url( $base . $to ) . '"' . $att . '>' . $m[0] . '</a>' : $m[0];
		},
		$html
	);
	return null === $out ? $html : $out;
}

/**
 * El correo del resumen: sencillo, con los colores de la marca, el botón a la caja y cada
 * pedido, producto y cifra que nombra enlazados a donde se ven.
 */
function dox_pos_ai_summary_html( $text, $y, $week, $pend, $adv, $map = null ) {
	$c     = dox_pos_colors();
	$brand = dox_pos_brand_name();
	$m     = 'dox_pos_money';
	$e     = 'esc_html';
	$ink   = dox_pos_is_dark( $c['bar'] ) ? '#FFFFFF' : $c['primary'];
	$base  = dox_pos_url();
	$map   = null === $map ? dox_pos_ai_links_map( $pend ) : $map;
	$ls    = 'color:' . $c['primary'] . ';text-decoration:underline';
	// Un enlace a la caja, y el texto con sus pedidos y productos ya enlazados.
	$a  = function ( $to, $html, $style = '' ) use ( $base ) {
		return '<a href="' . esc_url( $base . $to ) . '" style="' . esc_attr( $style ) . '">' . $html . '</a>';
	};
	$lk  = fn( $t ) => dox_pos_ai_linkify( esc_html( $t ), $map, $ls );
	$kpi = function ( $v, $l, $to = '' ) use ( $c, $a, $ls ) {
		$lab = $to ? $a( $to, esc_html( $l ), $ls ) : '<span style="color:#7A726D">' . esc_html( $l ) . '</span>';
		return '<td style="padding:10px 12px;border:1px solid #EAE3DD;border-radius:10px;vertical-align:top"><div style="font-size:20px;font-weight:600;color:' . esc_attr( $c['ink'] ) . '">' . esc_html( $v ) . '</div><div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;margin-top:2px">' . $lab . '</div></td>';
	};
	$ayer = '#historial/ventas/' . ( $y['date'] ?? '' );
	$h  = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $e( $brand ) . '</title></head>';
	$h .= '<body style="margin:0;padding:0;background:#F4F1EE;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . esc_attr( $c['ink'] ) . '">';
	$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F4F1EE"><tr><td align="center" style="padding:24px 12px">';
	$h .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FFFFFF;border-radius:16px;overflow:hidden">';
	$h .= '<tr><td style="background:' . esc_attr( $c['bar'] ) . ';color:' . esc_attr( $ink ) . ';padding:18px 24px"><div style="font-size:18px;font-weight:600">' . $e( $brand ) . '</div><div style="font-size:13px;opacity:.85;margin-top:2px">' . $e( ucfirst( wp_date( 'l j \d\e F' ) ) ) . '</div></td></tr>';
	$h .= '<tr><td style="padding:22px 24px 6px;font-size:15px;line-height:1.55">' . nl2br( $lk( $text ) ) . '</td></tr>';
	$h .= '<tr><td style="padding:14px 24px 6px"><table role="presentation" cellspacing="6" cellpadding="0" width="100%"><tr>' . $kpi( $m( $y['sold'] ), __( 'Vendido ayer', 'dox-pos' ), $ayer ) . $kpi( (string) $y['orders'], _n( 'Venta ayer', 'Ventas ayer', $y['orders'], 'dox-pos' ), $ayer ) . $kpi( $m( $week['sold'] ), __( 'Esta semana', 'dox-pos' ), '#historial/ventas/semana' ) . '</tr></table></td></tr>';
	if ( $pend['items'] ) {
		$h .= '<tr><td style="padding:14px 24px 0"><div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#7A726D;font-weight:600">' . $e( __( 'Pendientes de hoy', 'dox-pos' ) ) . '</div><ul style="margin:8px 0 0;padding-left:18px;font-size:14px;line-height:1.5">';
		foreach ( array_slice( $pend['items'], 0, 8 ) as $it ) {
			$h .= '<li style="margin:4px 0"><b>' . $e( $it['title'] ) . '.</b> ' . $lk( $it['detail'] ) . '</li>';
		}
		$h .= '</ul>';
		$mas = count( $pend['items'] ) - 8;
		if ( $mas > 0 ) {
			/* translators: %d: cuántos pendientes más */
			$h .= '<div style="margin:8px 0 0;font-size:13px">' . $a( '#asistente/hoy', $e( sprintf( _n( 'y %d más en la caja', 'y %d más en la caja', $mas, 'dox-pos' ), $mas ) ), $ls ) . '</div>';
		}
		$h .= '</td></tr>';
	}
	if ( $pend['stock'] ) {
		$h .= '<tr><td style="padding:14px 24px 0"><div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#7A726D;font-weight:600">' . $e( __( 'Se agota lo que se vende', 'dox-pos' ) ) . '</div><ul style="margin:8px 0 0;padding-left:18px;font-size:14px;line-height:1.5">';
		foreach ( array_slice( $pend['stock'], 0, 6 ) as $x ) {
			$pid  = (int) ( empty( $x['product_id'] ) ? $x['id'] : $x['product_id'] );
			$name = $pid ? $a( '#producto/' . $pid, $e( $x['name'] ), $ls ) : $e( $x['name'] );
			/* translators: 1: vendidas, 2: quedan */
			$h   .= '<li style="margin:4px 0">' . $name . ' <span style="color:#7A726D">' . $e( sprintf( __( 'vendió %1$d, quedan %2$d', 'dox-pos' ), $x['units'], $x['stock'] ) ) . '</span></li>';
		}
		$h .= '</ul></td></tr>';
	}
	if ( $adv['items'] ) {
		$h .= '<tr><td style="padding:14px 24px 0"><div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#7A726D;font-weight:600">' . $e( __( 'Consejos', 'dox-pos' ) ) . '</div>';
		foreach ( array_slice( $adv['items'], 0, 3 ) as $ad ) {
			$to  = dox_pos_ai_go_hash( $ad['go'] ?? '' );
			$ttl = $to ? $a( $to, $e( $ad['title'] ), $ls ) : $e( $ad['title'] );
			$h  .= '<div style="margin:8px 0;padding:10px 12px;background:#FBF7F4;border-radius:10px;font-size:14px;line-height:1.5"><b>' . $ttl . '</b><br>' . $lk( $ad['text'] ) . '</div>';
		}
		$h .= '</td></tr>';
	}
	$h .= '<tr><td style="padding:20px 24px 24px" align="left"><a href="' . esc_url( $base . '#asistente/hoy' ) . '" style="display:inline-block;background:' . esc_attr( $c['primary'] ) . ';color:' . ( dox_pos_is_dark( $c['primary'] ) ? '#FFFFFF' : esc_attr( $c['ink'] ) ) . ';text-decoration:none;font-weight:600;font-size:14px;padding:12px 18px;border-radius:12px">' . $e( __( 'Abrir la caja', 'dox-pos' ) ) . '</a></td></tr>';
	/* translators: %s: hora */
	$h .= '<tr><td style="padding:0 24px 20px;font-size:12px;color:#7A726D;line-height:1.5">' . $e( __( 'Lo subrayado se abre en la caja: el número de un pedido lleva a su detalle y el nombre de un producto, a su ficha.', 'dox-pos' ) ) . ' ' . $e( sprintf( __( 'Enviado por Dox POS a las %s. Los números salen de la tienda; el asistente solo los redacta. La hora y los destinatarios se cambian en WooCommerce > Dox POS > Asistente.', 'dox-pos' ), wp_date( 'G:i' ) ) ) . '</td></tr>';
	$h .= '</table></td></tr></table></body></html>';
	return $h;
}

/* =====================================================================
 * Descripciones a partir de la foto
 * ===================================================================== */

/**
 * Propone la descripción de un producto mirando su foto principal, su nombre, su categoría y
 * sus tallas y colores. No la guarda: la persona la revisa y la aprueba.
 *
 * @return array|WP_Error id, name, image, description, short, cost.
 */
function dox_pos_ai_describe( $id ) {
	$p = wc_get_product( (int) $id );
	if ( ! $p || $p->is_type( 'variation' ) ) {
		return new WP_Error( 'dox_pos_no_existe', __( 'Ese producto no existe.', 'dox-pos' ) );
	}
	$iid = (int) $p->get_image_id();
	if ( ! $iid ) {
		return new WP_Error( 'dox_pos_sin_foto', __( 'Ese producto no tiene foto: primero súbele una.', 'dox-pos' ) );
	}
	$part = dox_pos_ai_image_part( $iid );
	if ( is_wp_error( $part ) ) {
		return $part;
	}
	$cats   = array();
	foreach ( $p->get_category_ids() as $cid ) {
		$t = get_term( $cid, 'product_cat' );
		if ( $t && ! is_wp_error( $t ) && 'uncategorized' !== $t->slug ) {
			$cats[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
		}
	}
	$attrs = array();
	foreach ( $p->get_attributes() as $a ) {
		if ( $a instanceof WC_Product_Attribute ) {
			$names = array();
			if ( $a->is_taxonomy() ) {
				foreach ( $a->get_terms() as $t ) {
					$names[] = $t->name;
				}
			} else {
				$names = $a->get_options();
			}
			$attrs[] = wc_attribute_label( $a->get_name() ) . ': ' . implode( ', ', array_slice( $names, 0, 12 ) );
		}
	}
	$brand = dox_pos_brand_name();
	$lines = array( __( 'Producto: ', 'dox-pos' ) . $p->get_name() );
	if ( $cats ) {
		$lines[] = __( 'Categoría: ', 'dox-pos' ) . implode( ' › ', $cats );
	}
	foreach ( $attrs as $a ) {
		$lines[] = $a;
	}
	$r = dox_pos_ai_call(
		array(
			'kind'         => 'describe',
			'instructions' => sprintf( __( 'Escribes fichas de producto para la tienda en línea %s. Con la foto y los datos, escribe una descripción de 40 a 70 palabras en español de Colombia, cálida y concreta: qué es la prenda o el accesorio, su color y los detalles que se ven (cuello, estampado, botones, lazo, textura, largo), y para qué ocasión sirve. En tercera persona sobre el producto ("Este vestido…"). No inventes materiales, medidas, tallas ni cuidados que no se vean; sin precios, emojis, hashtags ni mayúsculas sostenidas. Además, una versión corta: una sola frase de hasta 110 caracteres.', 'dox-pos' ), $brand ),
			'input'        => array(
				array(
					'role'    => 'user',
					'content' => array(
						array( 'type' => 'input_text', 'text' => implode( "\n", $lines ) ),
						$part,
					),
				),
			),
			'schema'       => array(
				'name'   => 'ficha',
				'schema' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'descripcion', 'corta' ),
					'properties'           => array(
						'descripcion' => array( 'type' => 'string' ),
						'corta'       => array( 'type' => 'string' ),
					),
				),
			),
			'effort'       => 'low',
			'max_output'   => 1200,
			'timeout'      => 60,
		)
	);
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$j = dox_pos_ai_json( $r );
	if ( ! $j || '' === trim( (string) ( $j['descripcion'] ?? '' ) ) ) {
		return new WP_Error( 'dox_pos_ai_vacio', $r['refusal'] ? $r['refusal'] : __( 'El modelo no devolvió la descripción. Vuelve a intentarlo.', 'dox-pos' ) );
	}
	return array(
		'id'          => $p->get_id(),
		'name'        => $p->get_name(),
		'sku'         => $p->get_sku( 'edit' ),
		'image'       => (string) wp_get_attachment_image_url( $iid, 'woocommerce_thumbnail' ),
		'description' => trim( sanitize_textarea_field( (string) $j['descripcion'] ) ),
		'short'       => trim( sanitize_text_field( (string) ( $j['corta'] ?? '' ) ) ),
		'cost'        => $r['cost'],
	);
}

/* =====================================================================
 * Fotos para el modelo y ventas de un producto (lo usan el chat y el deshacer)
 * ===================================================================== */

/**
 * Una foto de la biblioteca tal como la ve el modelo: el tamaño mediano en base64 y en detalle
 * bajo (unos 85 tokens por foto).
 *
 * @param int $iid Adjunto.
 * @return array|WP_Error
 */
function dox_pos_ai_image_part( $iid ) {
	$path = get_attached_file( $iid );
	$meta = wp_get_attachment_metadata( $iid );
	if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && $path ) {
		foreach ( array( 'medium_large', 'woocommerce_single', 'large', 'medium' ) as $size ) {
			if ( ! empty( $meta['sizes'][ $size ]['file'] ) && file_exists( dirname( $path ) . '/' . $meta['sizes'][ $size ]['file'] ) ) {
				$path = dirname( $path ) . '/' . $meta['sizes'][ $size ]['file'];
				break;
			}
		}
	}
	if ( ! $path || ! file_exists( $path ) || filesize( $path ) > 4 * MB_IN_BYTES ) {
		return new WP_Error( 'dox_pos_foto', __( 'No se pudo leer la foto del producto.', 'dox-pos' ) );
	}
	$type = wp_check_filetype( $path )['type'];
	if ( ! in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
		return new WP_Error( 'dox_pos_foto', __( 'La foto está en un formato que el modelo no lee (vale JPG, PNG, WebP o GIF).', 'dox-pos' ) );
	}
	return array( 'type' => 'input_image', 'image_url' => 'data:' . $type . ';base64,' . base64_encode( (string) file_get_contents( $path ) ), 'detail' => 'low' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

/**
 * ¿Ese producto (o alguna de sus tallas) está en algún pedido? Se mira en los renglones de
 * pedido, que es inmediato (la tabla de análisis de WooCommerce se llena después).
 *
 * @param WC_Product $p El producto.
 * @return bool
 */
function dox_pos_ai_product_has_sales( $p ) {
	global $wpdb;
	$ids = array_merge( array( (int) $p->get_id() ), array_map( 'intval', $p->get_children() ) );
	$in  = implode( ',', array_map( 'intval', $ids ) );
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key IN ('_product_id','_variation_id') AND meta_value IN ($in)" ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Solo enteros.
}

/* =====================================================================
 * El pronóstico: cuándo se agota, cuánto pedir, cómo cierra el mes
 * ===================================================================== */

/**
 * Proyección lineal con el ritmo de venta de los últimos N días (o de la historia real, si
 * es más corta): por producto, cuántos días de existencias quedan, cuándo se acabarían y
 * cuánto reponer para cubrir un mes; cómo cerraría el mes frente al anterior; el mejor y el
 * peor día; y lo que no se mueve. Solo vale con historia suficiente (14 días y 15 ventas);
 * si no la hay, lo dice en vez de inventar. Se guarda 30 minutos.
 *
 * @param int  $days  Ventana, de 14 a 365.
 * @param bool $fresh Sin caché.
 * @return array enough, hist, from, to, totals, last7, month, weekdays, soon, dead, note.
 */
function dox_pos_ai_forecast( $days = 30, $fresh = false ) {
	$days = min( 365, max( 14, (int) $days ) );
	$key  = 'dox_pos_ai_fc_' . $days;
	if ( ! $fresh ) {
		$c = get_transient( $key );
		if ( is_array( $c ) ) {
			return $c;
		}
	}
	$now   = current_time( 'timestamp' );
	$today = wp_date( 'Y-m-d', $now );
	// La historia real: desde la primera venta, si es más corta que la ventana.
	$fargs = array( 'limit' => 1, 'type' => 'shop_order', 'orderby' => 'date', 'order' => 'ASC', 'status' => array( 'wc-processing', 'wc-enviado', 'wc-completed' ) );
	if ( ! dox_pos_show_web_orders() ) {
		$fargs['created_via'] = DOX_POS_VIA;
	}
	$first    = wc_get_orders( $fargs );
	$first_ts = $first && $first[0]->get_date_created() ? $first[0]->get_date_created()->getTimestamp() : time();
	$hist     = (int) min( $days, max( 1, floor( ( time() - $first_ts ) / DAY_IN_SECONDS ) + 1 ) );
	$from     = wp_date( 'Y-m-d', $now - ( $hist - 1 ) * DAY_IN_SECONDS );
	$rows     = dox_pos_ai_period_orders( $from, $today );
	$cat      = dox_pos_ai_catalog();
	$sold     = array();
	$tot      = array( 'orders' => count( $rows ), 'units' => 0, 'sold' => 0.0 );
	$last7    = 0.0;
	$edge7    = wp_date( 'Y-m-d', $now - 6 * DAY_IN_SECONDS );
	$wd_sum   = array();
	foreach ( $rows as $r ) {
		$tot['units'] += $r['units'];
		$tot['sold']  += $r['total'];
		if ( $r['day'] >= $edge7 ) {
			$last7 += $r['total'];
		}
		if ( $r['weekday'] ) {
			$wd_sum[ $r['weekday'] ] = ( $wd_sum[ $r['weekday'] ] ?? 0 ) + $r['total'];
		}
		foreach ( $r['items'] as $it ) {
			if ( ! $it['pid'] ) {
				continue;
			}
			if ( ! isset( $sold[ $it['pid'] ] ) ) {
				$sold[ $it['pid'] ] = array( 'units' => 0, 'revenue' => 0.0 );
			}
			$sold[ $it['pid'] ]['units']   += $it['qty'];
			$sold[ $it['pid'] ]['revenue'] += $it['total'];
		}
	}
	$enough = $tot['orders'] >= 15 && $hist >= 14;

	// Cuántas veces cayó cada día de la semana en la ventana, para promediar.
	$names = array( 1 => __( 'lunes', 'dox-pos' ), __( 'martes', 'dox-pos' ), __( 'miércoles', 'dox-pos' ), __( 'jueves', 'dox-pos' ), __( 'viernes', 'dox-pos' ), __( 'sábado', 'dox-pos' ), __( 'domingo', 'dox-pos' ) );
	$wd_n  = array();
	for ( $i = 0; $i < $hist; $i++ ) {
		$d          = (int) wp_date( 'N', $now - $i * DAY_IN_SECONDS );
		$wd_n[ $d ] = ( $wd_n[ $d ] ?? 0 ) + 1;
	}
	$wd_avg = array();
	foreach ( $wd_n as $d => $n ) {
		$wd_avg[ $d ] = round( ( $wd_sum[ $d ] ?? 0 ) / max( 1, $n ) );
	}
	arsort( $wd_avg );
	$best  = $wd_avg ? array( 'name' => $names[ array_key_first( $wd_avg ) ], 'avg' => reset( $wd_avg ) ) : null;
	$worst = $wd_avg ? array( 'name' => $names[ array_key_last( $wd_avg ) ], 'avg' => end( $wd_avg ) ) : null;

	// Por producto: ritmo diario sobre los días que lleva a la venta (mínimo 7), cobertura y reposición.
	$items = array();
	foreach ( $sold as $pid => $x ) {
		$p = $cat[ $pid ] ?? null;
		if ( ! $p || null === $p['total'] || $x['units'] <= 0 ) {
			continue;
		}
		$age   = max( 1, (int) floor( ( $now - strtotime( $p['created'] ) ) / DAY_IN_SECONDS ) + 1 );
		$eff   = max( 7, min( $hist, $age ) );
		$vel   = $x['units'] / $eff;
		$cover = $p['total'] > 0 ? $p['total'] / $vel : 0;
		$items[] = array(
			'id'      => (int) $pid,
			'name'    => $p['name'],
			'units'   => (int) $x['units'],
			'stock'   => (int) $p['total'],
			'weekly'  => round( $vel * 7, 1 ),
			'days'    => (int) floor( $cover ),
			'date'    => $p['total'] > 0 ? wp_date( 'j \d\e M', $now + (int) floor( $cover ) * DAY_IN_SECONDS ) : '',
			'reorder' => (int) max( 0, ceil( $vel * 30 - $p['total'] ) ),
			'status'  => $p['status'],
		);
	}
	usort( $items, fn( $a, $b ) => $a['days'] <=> $b['days'] ?: $b['units'] <=> $a['units'] );
	$soon = array_values( array_filter( $items, fn( $i ) => $i['days'] <= 21 ) );

	// Lo que no se mueve: publicado, con existencias, sin una venta en la ventana y anterior a ella.
	$dead = array();
	if ( $enough ) {
		$edge = $now - $hist * DAY_IN_SECONDS;
		foreach ( $cat as $p ) {
			if ( 'publish' === $p['status'] && $p['total'] > 0 && ! isset( $sold[ $p['id'] ] ) && strtotime( $p['created'] ) < $edge ) {
				$dead[] = array( 'id' => $p['id'], 'name' => $p['name'], 'stock' => (int) $p['total'], 'value' => round( $p['value'] ) );
			}
		}
		usort( $dead, fn( $a, $b ) => $b['value'] <=> $a['value'] );
		$dead = array_slice( $dead, 0, 8 );
	}

	// El mes: lo vendido hasta hoy, proyectado al cierre, frente al mes anterior entero y al mismo día.
	$tz      = wp_timezone();
	$mstart  = wp_date( 'Y-m-01', $now );
	$mdays   = (int) wp_date( 't', $now );
	$mday    = (int) wp_date( 'j', $now );
	$mtd     = dox_pos_history_sales( $mstart, $today, 0, 0 )['totals'];
	$daily   = $mday > 0 ? $mtd['sold'] / $mday : 0;
	$lm      = new DateTime( $mstart, $tz );
	$lm->modify( '-1 day' );
	$lm_end   = $lm->format( 'Y-m-d' );
	$lm_start = $lm->format( 'Y-m-01' );
	$same     = new DateTime( $lm_start, $tz );
	$same->modify( '+' . ( $mday - 1 ) . ' days' );
	$same_end = min( $same->format( 'Y-m-d' ), $lm_end );
	$last     = dox_pos_history_sales( $lm_start, $lm_end, 0, 0 )['totals'];
	$lsame    = dox_pos_history_sales( $lm_start, $same_end, 0, 0 )['totals'];
	$month    = array(
		'name'       => wp_date( 'F', $now ),
		'mtd'        => round( $mtd['sold'] ),
		'orders'     => (int) $mtd['orders'],
		'day'        => $mday,
		'days'       => $mdays,
		'projection' => round( $mtd['sold'] + $daily * ( $mdays - $mday ) ),
		'last'       => round( $last['sold'] ),
		'last_name'  => wp_date( 'F', $lm->getTimestamp() + 12 * HOUR_IN_SECONDS ),
		'last_same'  => round( $lsame['sold'] ),
	);

	$out = array(
		'enough'   => $enough,
		'hist'     => $hist,
		'from'     => $from,
		'to'       => $today,
		'totals'   => array( 'orders' => $tot['orders'], 'units' => $tot['units'], 'sold' => round( $tot['sold'] ), 'daily' => round( $tot['sold'] / max( 1, $hist ) ), 'daily7' => round( $last7 / min( 7, max( 1, $hist ) ) ) ),
		'month'    => $month,
		'weekdays' => array( 'best' => $best, 'worst' => $worst ),
		'soon'     => $enough ? array_slice( $soon, 0, 12 ) : array(),
		'dead'     => $dead,
		/* translators: 1: ventas, 2: días */
		'note'     => $enough ? __( 'Proyección lineal con el ritmo de venta del periodo; no es una promesa.', 'dox-pos' ) : sprintf( __( 'Hay %1$d ventas en %2$d días de historia; el pronóstico empieza a valer con 14 días y 15 ventas.', 'dox-pos' ), $tot['orders'], $hist ),
	);
	set_transient( $key, $out, 30 * MINUTE_IN_SECONDS );
	return $out;
}
