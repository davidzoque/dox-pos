<?php
/**
 * Los costos y la ganancia. El costo de cada producto vive en el campo propio de WooCommerce
 * ("Cost of Goods Sold", que trae desde la 9.5 y aquí se enciende solo): así se ve también en
 * el editor de productos de wp-admin y lo entienden otros plugins. Lo que WooCommerce no hace
 * y aquí sí:
 *
 * - Congelar el costo en cada línea del pedido en el momento de la venta. WooCommerce vuelve a
 *   calcular su cifra con el costo actual cada vez que el pedido recalcula totales, y la
 *   historia cambiaría; aquí queda en la línea como _dox_pos_unit_cost y se le devuelve a
 *   WooCommerce por su filtro, para que las dos cifras sean la misma.
 * - El costo promedio ponderado: cada entrada de mercancía con su costo recalcula el promedio
 *   del producto con lo que había y lo que entra (dox_pos_costs_after_entry, desde entries.php).
 * - La ganancia en el historial, en el Excel y en el detalle del pedido, y la carga de costos
 *   de golpe desde el Excel del inventario.
 *
 * Quién lo ve: administradores y gerentes de tienda. El rol Caja vende y registra mercancía sin
 * ver costos, y la API no se los manda.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =====================================================================
 * Encendido y permisos
 * ===================================================================== */

/**
 * ¿La caja lleva costos? El ajuste de Dox POS (Ajustes > Productos), de fábrica encendido.
 */
function dox_pos_costs_setting() {
	return ! empty( dox_pos_products_settings()['costs'] );
}

/**
 * ¿WooCommerce tiene su función de costos encendida (y la trae esta versión)?
 */
function dox_pos_wc_cogs_enabled() {
	return method_exists( 'WC_Product', 'get_cogs_value' ) && 'yes' === get_option( 'woocommerce_feature_cost_of_goods_sold_enabled' );
}

/**
 * Las dos cosas a la vez: el ajuste de la caja y la función de WooCommerce.
 */
function dox_pos_costs_on() {
	return dox_pos_costs_setting() && dox_pos_wc_cogs_enabled();
}

/**
 * ¿Este usuario ve costos y ganancia? Solo quien administra la tienda.
 */
function dox_pos_can_see_costs() {
	return dox_pos_costs_on() && current_user_can( 'manage_woocommerce' );
}

/**
 * Enciende la función de costos de WooCommerce (Ajustes > Avanzado > Funciones) si el ajuste de
 * la caja la pide. Se llama al instalar o actualizar el plugin y al guardar los ajustes. Apagar
 * el ajuste de la caja no apaga la de WooCommerce: otros plugins pueden estar usándola.
 */
function dox_pos_costs_install() {
	if ( dox_pos_costs_setting() ) {
		dox_pos_costs_enable();
	}
}

function dox_pos_costs_enable() {
	if ( method_exists( 'WC_Product', 'get_cogs_value' ) && 'yes' !== get_option( 'woocommerce_feature_cost_of_goods_sold_enabled' ) ) {
		update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'yes' );
	}
}

/* =====================================================================
 * El costo de un producto
 * ===================================================================== */

/**
 * El costo por unidad que se aplica a un producto o a una talla: el suyo, o el del producto si
 * la talla no tiene uno propio (así funciona el campo de WooCommerce: el costo va en el producto
 * y cada variación lo hereda, salvo que tenga el suyo). Null si no se ha puesto.
 *
 * @param WC_Product|null $p Producto, talla o simple.
 * @return float|null
 */
function dox_pos_product_cost( $p ) {
	if ( ! $p instanceof WC_Product || ! dox_pos_costs_on() ) {
		return null;
	}
	$own = $p->get_cogs_value();
	if ( ! $p->is_type( 'variation' ) ) {
		return null === $own ? null : (float) $own;
	}
	$parent = wc_get_product( $p->get_parent_id() );
	$pc     = $parent ? $parent->get_cogs_value() : null;
	if ( null === $own ) {
		return null === $pc ? null : (float) $pc;
	}
	$additive = method_exists( $p, 'get_cogs_value_is_additive' ) && $p->get_cogs_value_is_additive();
	return (float) $own + ( $additive ? (float) $pc : 0.0 );
}

/**
 * Pone el costo de un producto o talla (null o 0 lo quita) y lo guarda.
 *
 * @param WC_Product $p    Producto o talla.
 * @param float|null $cost Costo por unidad.
 * @param bool       $save Si se guarda ya (false: quien llama guarda).
 * @return bool Si se pudo (la función de costos está encendida).
 */
function dox_pos_set_product_cost( $p, $cost, $save = true ) {
	if ( ! $p instanceof WC_Product || ! dox_pos_costs_on() ) {
		return false;
	}
	$cost = null === $cost || '' === $cost || (float) $cost <= 0 ? null : round( (float) $cost, 2 );
	$p->set_cogs_value( $cost );
	if ( $p->is_type( 'variation' ) && method_exists( $p, 'set_cogs_value_is_additive' ) ) {
		$p->set_cogs_value_is_additive( false ); // El costo de una talla es el suyo entero, no un añadido al del producto.
	}
	if ( $save ) {
		$p->save();
	}
	return true;
}

/**
 * Pone un costo a todo el producto: en el producto y, si es de tallas, quitando el propio de cada
 * talla para que todas lo hereden (es lo que se pide desde la caja: "el costo se pone a todas").
 *
 * @param WC_Product $p    El producto (no una talla).
 * @param float|null $cost Costo por unidad; null o 0 lo quita.
 */
function dox_pos_set_cost_all( $p, $cost ) {
	if ( ! dox_pos_set_product_cost( $p, $cost ) ) {
		return;
	}
	if ( ! $p->is_type( 'variable' ) ) {
		return;
	}
	foreach ( $p->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( $v && null !== $v->get_cogs_value() ) {
			dox_pos_set_product_cost( $v, null );
		}
	}
}

/**
 * El costo que la caja enseña al editar un producto: uno solo si todas las tallas cuestan lo
 * mismo; '' si varían (con el menor y el mayor); null si no tiene.
 *
 * @param WC_Product $p El producto.
 * @return array{cost: float|string|null, min: float, max: float}
 */
function dox_pos_product_cost_summary( $p ) {
	$costs = array();
	$all   = 0;
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || 'publish' !== $v->get_status() ) {
				continue;
			}
			$all++;
			$c = dox_pos_product_cost( $v );
			if ( null !== $c ) {
				$costs[] = $c;
			}
		}
	} else {
		$all = 1;
		$c   = dox_pos_product_cost( $p );
		if ( null !== $c ) {
			$costs[] = $c;
		}
	}
	if ( ! $costs ) {
		return array( 'cost' => null, 'min' => 0, 'max' => 0 );
	}
	$u = array_values( array_unique( $costs ) );
	if ( 1 === count( $u ) && count( $costs ) === $all ) {
		return array( 'cost' => $u[0], 'min' => $u[0], 'max' => $u[0] );
	}
	return array( 'cost' => '', 'min' => min( $costs ), 'max' => max( $costs ) );
}

/**
 * Las existencias sobre las que se promedia el costo de quien lo lleva: las suyas si es simple o
 * una talla con costo propio; si es un producto de tallas, la suma de las tallas que heredan su
 * costo (más las del producto si las lleva en conjunto).
 *
 * @param WC_Product $holder Quien lleva el costo.
 * @return int
 */
function dox_pos_cost_stock( $holder ) {
	if ( ! $holder->is_type( 'variable' ) ) {
		return $holder->managing_stock() ? max( 0, (int) $holder->get_stock_quantity() ) : 0;
	}
	$n = true === $holder->get_manage_stock() ? max( 0, (int) $holder->get_stock_quantity() ) : 0;
	foreach ( $holder->get_children() as $vid ) {
		$v = wc_get_product( $vid );
		if ( $v && null === $v->get_cogs_value() && true === $v->get_manage_stock() ) {
			$n += max( 0, (int) $v->get_stock_quantity() );
		}
	}
	return $n;
}

/**
 * El costo promedio ponderado tras una entrada de mercancía: por cada producto (o talla con
 * costo propio), (lo que había × su costo + lo que entra × lo que costó) / (lo que había + lo
 * que entra). Si no había nada, o no tenía costo, el costo pasa a ser el de esta compra. Se
 * llama con las existencias ya sumadas.
 *
 * @param array $lines [ [ product => WC_Product, qty => int, cost => float|null ] ].
 * @return array Los productos cuyo costo cambió: [ [ id, name, before, after ] ].
 */
function dox_pos_costs_after_entry( $lines ) {
	if ( ! dox_pos_costs_on() ) {
		return array();
	}
	$groups = array();
	foreach ( $lines as $l ) {
		if ( null === $l['cost'] || $l['cost'] <= 0 || $l['qty'] < 1 ) {
			continue;
		}
		$p   = $l['product'];
		$own = $p->is_type( 'variation' ) && null !== $p->get_cogs_value();
		// Quien lleva el costo: la talla si tiene el suyo; si no, el producto.
		$holder = $own || ! $p->is_type( 'variation' ) ? $p : wc_get_product( $p->get_parent_id() );
		if ( ! $holder ) {
			continue;
		}
		$k = $holder->get_id();
		if ( ! isset( $groups[ $k ] ) ) {
			$groups[ $k ] = array( 'holder' => $holder, 'qty' => 0, 'amount' => 0.0 );
		}
		$groups[ $k ]['qty']    += (int) $l['qty'];
		$groups[ $k ]['amount'] += (int) $l['qty'] * (float) $l['cost'];
	}
	$changed = array();
	foreach ( $groups as $g ) {
		$holder = wc_get_product( $g['holder']->get_id() ); // Recién leído: con las existencias ya sumadas.
		if ( ! $holder ) {
			continue;
		}
		$cur = dox_pos_product_cost( $holder );
		$before = max( 0, dox_pos_cost_stock( $holder ) - $g['qty'] ); // Lo que había antes de sumar esta entrada.
		if ( null === $cur || $cur <= 0 || $before <= 0 ) {
			$new = $g['amount'] / $g['qty'];
		} else {
			$new = ( $before * $cur + $g['amount'] ) / ( $before + $g['qty'] );
		}
		$new = round( $new, 2 );
		if ( null !== $cur && abs( $new - $cur ) < 0.005 ) {
			continue;
		}
		dox_pos_set_product_cost( $holder, $new );
		$changed[] = array( 'id' => $holder->get_id(), 'name' => dox_pos_item_name( $holder ), 'before' => $cur, 'after' => $new );
	}
	return $changed;
}

/* =====================================================================
 * El costo congelado en cada venta
 * ===================================================================== */

// Cada línea de producto que entra en un pedido (desde la caja, la web o wp-admin) se lleva el costo
// por unidad de ese momento. Una línea que ya lo traía no se toca.
add_action( 'woocommerce_new_order_item', 'dox_pos_cost_snapshot', 10, 3 );
function dox_pos_cost_snapshot( $item_id, $item, $order_id ) {
	if ( ! $item instanceof WC_Order_Item_Product || ! dox_pos_costs_on() ) {
		return;
	}
	if ( '' !== (string) $item->get_meta( '_dox_pos_unit_cost', true, 'edit' ) ) {
		return;
	}
	$product = $item->get_product();
	$cost    = $product ? dox_pos_product_cost( $product ) : null;
	if ( null === $cost ) {
		return;
	}
	$item->update_meta_data( '_dox_pos_unit_cost', wc_format_decimal( $cost, 2 ) );
	$item->save_meta_data();
}

// WooCommerce recalcula su costo de línea con el costo actual del producto cada vez que el pedido
// recalcula totales; con esto usa el congelado, y su cifra coincide con la del historial.
add_filter( 'woocommerce_calculated_order_item_cogs_value', 'dox_pos_cost_frozen', 10, 2 );
function dox_pos_cost_frozen( $value, $item ) {
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return $value;
	}
	$unit = $item->get_meta( '_dox_pos_unit_cost', true, 'edit' );
	if ( '' === (string) $unit ) {
		return $value;
	}
	return (float) $unit * (int) $item->get_quantity();
}

/**
 * El costo por unidad congelado en una línea del pedido, o null si esa venta no lo tiene (es de
 * antes de llevar costos, o el producto no tenía).
 *
 * @param WC_Order_Item $item La línea.
 * @return float|null
 */
function dox_pos_line_unit_cost( $item ) {
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return null;
	}
	$unit = $item->get_meta( '_dox_pos_unit_cost', true, 'edit' );
	if ( '' !== (string) $unit ) {
		return (float) $unit;
	}
	if ( dox_pos_costs_on() && method_exists( $item, 'get_cogs_value' ) ) {
		$v = (float) $item->get_cogs_value();
		$q = (int) $item->get_quantity();
		if ( $v > 0 && $q > 0 ) {
			return round( $v / $q, 2 );
		}
	}
	return null;
}

/**
 * El costo y la ganancia de un pedido. La ganancia es lo que pagó la clienta por los productos
 * (con el descuento ya aplicado, sin el envío, que se le paga a la transportadora, y sin
 * impuestos) menos el costo de lo vendido. Si a alguna línea le falta el costo, la ganancia no
 * se sabe (null) y se dice cuántas faltan.
 *
 * @param WC_Order $order El pedido.
 * @return array{cost: float|null, profit: float|null, revenue: float, complete: bool, missing: int}
 */
function dox_pos_order_profit( $order ) {
	$cost    = 0.0;
	$missing = 0;
	$n       = 0;
	foreach ( $order->get_items() as $it ) {
		$n++;
		$u = dox_pos_line_unit_cost( $it );
		if ( null === $u ) {
			$missing++;
			continue;
		}
		$cost += $u * (int) $it->get_quantity();
	}
	$revenue = round( (float) $order->get_total() - (float) $order->get_shipping_total() - (float) $order->get_total_tax(), 2 );
	if ( ! $n || $missing === $n ) {
		return array( 'cost' => null, 'profit' => null, 'revenue' => $revenue, 'complete' => false, 'missing' => $missing );
	}
	$cost = round( $cost, 2 );
	return array(
		'cost'     => $cost,
		'profit'   => $missing ? null : round( $revenue - $cost, 2 ),
		'revenue'  => $revenue,
		'complete' => 0 === $missing,
		'missing'  => $missing,
	);
}

/**
 * El porcentaje de margen: la ganancia sobre lo vendido. Null si no hay venta.
 */
function dox_pos_margin( $profit, $revenue ) {
	return (float) $revenue > 0 ? (int) round( (float) $profit / (float) $revenue * 100 ) : null;
}

/* =====================================================================
 * Cargar costos desde el Excel del inventario
 * ===================================================================== */

/**
 * Lee la primera hoja de un .xlsx y devuelve sus filas como arrays por columna (0 = A), con los
 * textos y los números tal cual. Sin bibliotecas: el zip con ZipArchive y los XML con SimpleXML.
 * Entiende los archivos que arma la caja (textos en la celda) y los que guarda Excel, Numbers o
 * Google Sheets (textos en la tabla compartida).
 *
 * @param string $path Ruta del archivo.
 * @param int    $max  Filas como mucho.
 * @return array|WP_Error
 */
function dox_pos_xlsx_read( $path, $max = 20000 ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'dox_pos_sin_zip', __( 'Este servidor no puede leer archivos de Excel: le falta la extensión zip de PHP.', 'dox-pos' ) );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) {
		return new WP_Error( 'dox_pos_no_excel', __( 'Ese archivo no es un Excel (.xlsx).', 'dox-pos' ) );
	}
	$prev_errors = libxml_use_internal_errors( true ); // Un XML roto no suelta avisos: devuelve false y se avisa aquí.
	$ns    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	$rns   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	$sheet = 'xl/worksheets/sheet1.xml';
	$wb    = $zip->getFromName( 'xl/workbook.xml' );
	$rels  = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
	if ( $wb && $rels ) { // La primera hoja del libro: su id en el libro y su ruta en las relaciones.
		$wx = simplexml_load_string( $wb );
		$rx = simplexml_load_string( $rels );
		if ( $wx && $rx ) {
			$wx->registerXPathNamespace( 'm', $ns );
			$first = $wx->xpath( '//m:sheets/m:sheet' );
			$rid   = $first ? (string) $first[0]->attributes( $rns )->id : '';
			foreach ( $rx->Relationship as $r ) {
				if ( $rid && (string) $r['Id'] === $rid ) {
					$t     = ltrim( (string) $r['Target'], '/' );
					$sheet = 0 === strpos( $t, 'xl/' ) ? $t : 'xl/' . $t;
				}
			}
		}
	}
	$xml = $zip->getFromName( $sheet );
	$ss  = $zip->getFromName( 'xl/sharedStrings.xml' );
	$zip->close();
	if ( ! $xml ) {
		libxml_use_internal_errors( $prev_errors );
		return new WP_Error( 'dox_pos_no_excel', __( 'No se encontró la hoja dentro del Excel.', 'dox-pos' ) );
	}
	$strings = array();
	if ( $ss ) {
		$sx = simplexml_load_string( $ss );
		if ( $sx ) {
			foreach ( $sx->si as $si ) {
				$strings[] = html_entity_decode( wp_strip_all_tags( $si->asXML() ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
			}
		}
	}
	$dx = simplexml_load_string( $xml );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev_errors );
	if ( ! $dx || ! isset( $dx->sheetData ) ) {
		return new WP_Error( 'dox_pos_no_excel', __( 'No se pudo leer la hoja del Excel.', 'dox-pos' ) );
	}
	$rows = array();
	foreach ( $dx->sheetData->row as $row ) {
		if ( count( $rows ) >= $max ) {
			break;
		}
		$cells = array();
		foreach ( $row->c as $c ) {
			$ref = (string) $c['r'];
			$col = 0;
			foreach ( str_split( strtoupper( preg_replace( '/\d+$/', '', $ref ) ) ) as $ch ) {
				$col = $col * 26 + ( ord( $ch ) - 64 );
			}
			$col = max( 0, $col - 1 );
			$t   = (string) $c['t'];
			if ( 's' === $t ) {
				$v = $strings[ (int) $c->v ] ?? '';
			} elseif ( 'inlineStr' === $t ) {
				$v = isset( $c->is ) ? html_entity_decode( wp_strip_all_tags( $c->is->asXML() ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
			} elseif ( 'b' === $t ) {
				$v = '1' === (string) $c->v;
			} else {
				$v = isset( $c->v ) ? (string) $c->v : '';
			}
			$cells[ $col ] = $v;
		}
		$rows[] = $cells;
	}
	return $rows;
}

/**
 * Un importe escrito de cualquier forma ("85000", "85.000", "$ 85.000,50") en número, con los
 * separadores de la tienda. Null si no es un número.
 */
function dox_pos_parse_money( $v ) {
	if ( is_int( $v ) || is_float( $v ) ) {
		return (float) $v;
	}
	$s = trim( (string) $v );
	if ( '' === $s ) {
		return null;
	}
	if ( is_numeric( $s ) ) {
		return (float) $s;
	}
	$th = wc_get_price_thousand_separator();
	$de = wc_get_price_decimal_separator();
	$s  = preg_replace( '/[^\d.,\-]/u', '', $s );
	if ( '' !== $th ) {
		$s = str_replace( $th, '', $s );
	}
	if ( '' !== $de && '.' !== $de ) {
		$s = str_replace( $de, '.', $s );
	}
	return is_numeric( $s ) ? (float) $s : null;
}

/**
 * Carga los costos desde el Excel del inventario (o cualquier hoja con una columna "Código" o
 * "Ref." y otra "Costo"): una fila con código pone el costo a esa talla; una fila de producto
 * (con Ref. y sin código) lo pone al producto entero, para todas sus tallas. Solo toca costos:
 * ni existencias ni precios. Las filas con la casilla de costo vacía se saltan.
 *
 * @param array|null $file Lo que llegó en $_FILES['file'].
 * @return array|WP_Error updated, same, skipped, missing (cuántos y ejemplos).
 */
function dox_pos_costs_import( $file ) {
	if ( ! dox_pos_can_see_costs() ) {
		return new WP_Error( 'dox_pos_sin_permiso', __( 'Los costos los cargan administradores y gerentes.', 'dox-pos' ) );
	}
	if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'dox_pos_sin_archivo', __( 'No llegó ningún archivo.', 'dox-pos' ) );
	}
	if ( ! preg_match( '/\.xlsx$/i', (string) ( $file['name'] ?? '' ) ) ) {
		return new WP_Error( 'dox_pos_no_excel', __( 'Sube el archivo de Excel (.xlsx) que descargaste de la caja.', 'dox-pos' ) );
	}
	$rows = dox_pos_xlsx_read( $file['tmp_name'] );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	if ( count( $rows ) < 2 ) {
		return new WP_Error( 'dox_pos_excel_vacio', __( 'El Excel no tiene filas.', 'dox-pos' ) );
	}
	// Las columnas, por el título de la primera fila.
	$fold = fn( $s ) => strtolower( trim( remove_accents( (string) $s ) ) );
	$cols = array( 'sku' => null, 'ref' => null, 'cost' => null );
	foreach ( $rows[0] as $i => $h ) {
		$h = $fold( $h );
		if ( null === $cols['sku'] && in_array( $h, array( 'codigo', 'código', 'sku', 'code' ), true ) ) {
			$cols['sku'] = $i;
		} elseif ( null === $cols['ref'] && in_array( $h, array( 'ref.', 'ref', 'referencia', 'reference' ), true ) ) {
			$cols['ref'] = $i;
		} elseif ( null === $cols['cost'] && in_array( $h, array( 'costo', 'coste', 'cost', 'costo unitario', 'costo por unidad' ), true ) ) {
			$cols['cost'] = $i;
		}
	}
	if ( null === $cols['cost'] ) {
		return new WP_Error( 'dox_pos_sin_columna', __( 'El Excel no tiene una columna "Costo". Descarga el inventario desde la caja, llena esa columna y súbelo.', 'dox-pos' ) );
	}
	if ( null === $cols['sku'] && null === $cols['ref'] ) {
		return new WP_Error( 'dox_pos_sin_columna', __( 'El Excel no tiene la columna "Código" ni "Ref.": no se sabe a qué producto va cada costo.', 'dox-pos' ) );
	}
	$out = array( 'updated' => 0, 'same' => 0, 'skipped' => 0, 'missing' => 0, 'missing_list' => array(), 'rows' => count( $rows ) - 1 );
	$did = array(); // Un producto no se toca dos veces en la misma carga.
	foreach ( array_slice( $rows, 1 ) as $r ) {
		$cost = dox_pos_parse_money( $r[ $cols['cost'] ] ?? '' );
		if ( null === $cost || $cost < 0 ) {
			$out['skipped']++;
			continue;
		}
		$sku = null !== $cols['sku'] ? trim( (string) ( $r[ $cols['sku'] ] ?? '' ) ) : '';
		$ref = null !== $cols['ref'] ? trim( (string) ( $r[ $cols['ref'] ] ?? '' ) ) : '';
		$key = '' !== $sku ? $sku : $ref;
		if ( '' === $key || '(sin código)' === $key ) {
			$out['skipped']++;
			continue;
		}
		$pid = wc_get_product_id_by_sku( $key );
		$p   = $pid ? wc_get_product( $pid ) : null;
		if ( ! $p ) {
			$out['missing']++;
			if ( count( $out['missing_list'] ) < 8 ) {
				$out['missing_list'][] = $key;
			}
			continue;
		}
		if ( isset( $did[ $p->get_id() ] ) ) {
			continue;
		}
		$did[ $p->get_id() ] = true;
		$cur = $p->is_type( 'variable' ) ? dox_pos_product_cost_summary( $p )['cost'] : dox_pos_product_cost( $p );
		if ( is_float( $cur ) && abs( $cur - $cost ) < 0.005 ) {
			$out['same']++;
			continue;
		}
		if ( $p->is_type( 'variable' ) ) {
			dox_pos_set_cost_all( $p, $cost );
		} else {
			dox_pos_set_product_cost( $p, $cost );
		}
		$out['updated']++;
	}
	return $out;
}

/**
 * La ruta: POST /costs/import con el archivo en "file".
 */
function dox_pos_rest_costs_import( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	return dox_pos_rest_out( dox_pos_costs_import( $files['file'] ?? null ) );
}
