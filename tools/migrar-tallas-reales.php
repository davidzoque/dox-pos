<?php
/**
 * Migración de una sola vez (Dox POS 0.52.0): reparte por talla real las unidades de los grupos que comparten,
 * leyendo el Excel de inventario de la tienda. No va en el plugin (tools/ no entra en el zip).
 *
 * Para cada grupo de tallas que comparte unidades (una bolsa por color, o todas las tallas que heredan el total del
 * producto) con dos o más tallas reales marcadas, pone en cada talla real cuántas prendas tiene según el Excel
 * (meta _dox_pos_real_qty). Solo escribe cuando el Excel cuadra con el total del grupo en la tienda. No toca el stock.
 *
 * El Excel: una hoja (de fábrica "Inventario") con las columnas Nombre completo, SKU, Color, Talla y Disponibilidad.
 * Una Disponibilidad combinada sobre varias tallas es una sola cantidad, de la primera talla. Las celdas de nombre y
 * color combinadas (o vacías debajo de un producto) valen para las filas de abajo.
 *
 * Uso, como el usuario de la cuenta de la tienda:
 *   php migrar-tallas-reales.php --wp=/ruta/wp-load.php --xlsx=/ruta/inventario.xlsx --salida=/ruta/informe.csv
 *   ... y lo mismo con --aplicar para escribir de verdad (sin eso solo lee y saca el informe).
 * Opcional: --hoja="Inventario".
 *
 * @package DoxPos
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

$opt = array();
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( preg_match( '/^--([a-z]+)(?:=(.*))?$/', $a, $m ) ) {
		$opt[ $m[1] ] = $m[2] ?? true;
	}
}
foreach ( array( 'wp', 'xlsx', 'salida' ) as $k ) {
	if ( empty( $opt[ $k ] ) || true === $opt[ $k ] ) {
		fwrite( STDERR, "Falta --$k. Uso: php migrar-tallas-reales.php --wp=wp-load.php --xlsx=inventario.xlsx --salida=informe.csv [--aplicar] [--hoja=Inventario]\n" );
		exit( 1 );
	}
}
$aplicar = ! empty( $opt['aplicar'] );
$hoja    = is_string( $opt['hoja'] ?? null ) ? $opt['hoja'] : 'Inventario';

require_once $opt['wp'];
if ( ! function_exists( 'dox_pos_real_split' ) ) {
	fwrite( STDERR, "Esta tienda no tiene Dox POS 0.52.0 o más nueva.\n" );
	exit( 1 );
}

/* ============================ Leer el Excel ============================ */

/**
 * La hoja pedida del .xlsx: filas [número de fila => [columna => texto]] y las celdas combinadas.
 *
 * @param string $path Archivo.
 * @param string $name Nombre de la hoja.
 * @return array{rows: array, merges: string[]}
 */
function mtr_leer_xlsx( $path, $name ) {
	$z = new ZipArchive();
	if ( true !== $z->open( $path ) ) {
		throw new RuntimeException( 'No se pudo abrir el Excel.' );
	}
	$strings = array();
	$ss      = $z->getFromName( 'xl/sharedStrings.xml' );
	if ( false !== $ss ) {
		$x = simplexml_load_string( $ss );
		foreach ( $x->si as $si ) {
			$t = '';
			if ( isset( $si->t ) ) {
				$t = (string) $si->t;
			}
			foreach ( $si->r as $r ) {
				$t .= (string) $r->t;
			}
			$strings[] = $t;
		}
	}
	// Qué archivo es la hoja con ese nombre.
	$wb   = simplexml_load_string( $z->getFromName( 'xl/workbook.xml' ) );
	$rels = simplexml_load_string( $z->getFromName( 'xl/_rels/workbook.xml.rels' ) );
	$rid  = '';
	foreach ( $wb->sheets->sheet as $s ) {
		if ( trim( (string) $s['name'] ) === $name ) {
			$rid = (string) $s->attributes( 'r', true )->id;
		}
	}
	if ( '' === $rid ) {
		throw new RuntimeException( "El Excel no tiene la hoja \"$name\"." );
	}
	$file = '';
	foreach ( $rels->Relationship as $r ) {
		if ( (string) $r['Id'] === $rid ) {
			$file = 'xl/' . ltrim( preg_replace( '#^/?xl/#', '', (string) $r['Target'] ), '/' );
		}
	}
	$sheet = simplexml_load_string( $z->getFromName( $file ) );
	$rows  = array();
	foreach ( $sheet->sheetData->row as $row ) {
		$rn = (int) $row['r'];
		foreach ( $row->c as $c ) {
			$col = preg_replace( '/\d+/', '', (string) $c['r'] );
			$t   = (string) $c['t'];
			$v   = isset( $c->v ) ? (string) $c->v : '';
			if ( 's' === $t ) {
				$v = $strings[ (int) $v ] ?? '';
			} elseif ( 'inlineStr' === $t ) {
				$v = (string) $c->is->t;
			}
			$rows[ $rn ][ $col ] = trim( $v );
		}
	}
	$merges = array();
	if ( isset( $sheet->mergeCells ) ) {
		foreach ( $sheet->mergeCells->mergeCell as $m ) {
			$merges[] = (string) $m['ref'];
		}
	}
	$z->close();
	return array( 'rows' => $rows, 'merges' => $merges );
}

/**
 * Para comparar textos: minúsculas, sin tildes, sin espacios de más ni "2- 3" frente a "2-3".
 */
function mtr_norm( $s ) {
	$s = remove_accents( mb_strtolower( (string) $s ) );
	$s = preg_replace( '/\s*-\s*/', '-', $s );
	return trim( preg_replace( '/\s+/', ' ', $s ) );
}

$x      = mtr_leer_xlsx( $opt['xlsx'], $hoja );
$rows   = $x['rows'];
$header = $rows[ min( array_keys( $rows ) ) ] ?? array();
$cols   = array();
foreach ( $header as $col => $txt ) {
	$cols[ mtr_norm( $txt ) ] = $col;
}
$need = array( 'nombre completo' => 'name', 'sku' => 'sku', 'color' => 'color', 'talla' => 'size', 'disponibilidad' => 'qty' );
$c    = array();
foreach ( $need as $h => $k ) {
	if ( ! isset( $cols[ $h ] ) ) {
		fwrite( STDERR, "La hoja no tiene la columna \"$h\".\n" );
		exit( 1 );
	}
	$c[ $k ] = $cols[ $h ];
}
// Las combinadas: en la Disponibilidad, las filas de debajo de la primera no cuentan (la cantidad es una sola);
// en el nombre y el color, el valor de arriba vale para todas.
$merged_qty  = array(); // fila => true (combinada, no la primera)
$merged_from = array(); // columna => [fila => fila de arriba]
foreach ( $x['merges'] as $ref ) {
	if ( ! preg_match( '/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $ref, $m ) || $m[1] !== $m[3] ) {
		continue;
	}
	for ( $r = (int) $m[2] + 1; $r <= (int) $m[4]; $r++ ) {
		if ( $m[1] === $c['qty'] ) {
			$merged_qty[ $r ] = true;
		}
		$merged_from[ $m[1] ][ $r ] = (int) $m[2];
	}
}
$excel    = array(); // Filas útiles: [fila, nombre, sku, color, talla, cantidad (null si no es número)].
$by_sku   = array();
$by_names = array();
$name     = '';
$color    = '';
foreach ( $rows as $rn => $r ) {
	if ( $rn === min( array_keys( $rows ) ) ) {
		continue;
	}
	$val  = function ( $k ) use ( $r, $c, $rows, $merged_from, $rn ) {
		$col = $c[ $k ];
		if ( isset( $merged_from[ $col ][ $rn ] ) ) {
			return $rows[ $merged_from[ $col ][ $rn ] ][ $col ] ?? '';
		}
		return $r[ $col ] ?? '';
	};
	$n    = $val( 'name' );
	$name = '' !== $n ? $n : $name; // Debajo del nombre de un producto vienen sus tallas.
	$co   = $val( 'color' );
	if ( '' !== $n ) {
		$color = $co;
	} elseif ( '' !== $co ) {
		$color = $co;
	}
	$size = $val( 'size' );
	$sku  = $val( 'sku' );
	if ( '' === $size && '' === $sku ) {
		continue;
	}
	$raw = $r[ $c['qty'] ] ?? '';
	$qty = isset( $merged_qty[ $rn ] ) ? 0 : ( '' === $raw ? 0 : ( is_numeric( $raw ) ? (int) round( (float) $raw ) : null ) );
	$e   = array( 'row' => $rn, 'name' => $name, 'sku' => $sku, 'color' => $color, 'size' => $size, 'qty' => $qty );
	$excel[] = $e;
	if ( '' !== $sku ) {
		$by_sku[ mb_strtolower( $sku ) ] = $e;
	}
	$by_names[ mtr_norm( $name ) . '|' . mtr_norm( $color ) . '|' . mtr_norm( $size ) ] = $e;
}

/* ============================ Comparar con la tienda ============================ */

$out   = array( 'repartido' => array(), 'no_cuadra' => array(), 'no_encontrado' => array() );
$vistos = array();
$q      = new WP_Query( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'variable' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
foreach ( $q->posts as $pid ) {
	if ( count( dox_pos_real_size_ids( $pid ) ) < 2 ) {
		continue;
	}
	$p = wc_get_product( $pid );
	foreach ( $p->get_children() as $vid ) {
		$v     = wc_get_product( $vid );
		$split = $v ? dox_pos_real_split( $v ) : null;
		if ( ! $split ) {
			continue;
		}
		$gk = $pid . ':' . implode( ',', $split['group'] );
		if ( isset( $vistos[ $gk ] ) ) {
			continue;
		}
		$vistos[ $gk ] = true;
		$color_name    = '';
		$ctax          = dox_pos_color_attribute();
		if ( $ctax ) {
			$slug       = (string) ( $v->get_attributes()[ $ctax ] ?? '' );
			$color_name = '' !== $slug ? dox_pos_attribute_label( $ctax, $slug ) : '';
		}
		$reals   = array_map( fn( $r ) => (int) $r['id'], $split['reals'] );
		$tallas  = array();
		$faltan  = array();
		$sum_all = 0;
		$sum_real = 0;
		$texto   = false;
		$poner   = array();
		foreach ( $split['group'] as $mid ) {
			$m    = wc_get_product( $mid );
			$size = dox_pos_real_size_label( $m );
			$sku  = (string) $m->get_sku( 'edit' );
			$e    = '' !== $sku ? ( $by_sku[ mb_strtolower( $sku ) ] ?? null ) : null;
			if ( ! $e ) {
				$e = $by_names[ mtr_norm( $p->get_name() ) . '|' . mtr_norm( $color_name ) . '|' . mtr_norm( $size ) ] ?? null;
			}
			if ( ! $e ) {
				$faltan[] = $size . ( '' !== $sku ? " ($sku)" : '' );
				continue;
			}
			if ( null === $e['qty'] ) {
				$texto = true;
			}
			$n        = (int) $e['qty'];
			$sum_all += $n;
			$es_real  = in_array( (int) $mid, $reals, true );
			if ( $es_real ) {
				$sum_real      += $n;
				$poner[ $mid ]  = $n;
			}
			$tallas[] = $size . ( $es_real ? '*' : '' ) . ': ' . ( null === $e['qty'] ? '?' : $n );
		}
		$fila = array( 'producto' => $p->get_name(), 'id' => $pid, 'color' => $color_name, 'total_tienda' => $split['total'], 'excel' => $sum_all, 'tallas' => implode( ' | ', $tallas ) );
		if ( $faltan ) {
			$out['no_encontrado'][] = $fila + array( 'nota' => 'No están en el Excel: ' . implode( ', ', $faltan ) );
			continue;
		}
		if ( $texto || $sum_all !== $split['total'] || $sum_real !== $split['total'] ) {
			$nota = $texto ? 'Alguna Disponibilidad no es un número' : ( $sum_all !== $split['total'] ? 'El Excel no suma el total del grupo' : 'Hay prendas en tallas que no están marcadas como talla real' );
			$out['no_cuadra'][] = $fila + array( 'nota' => $nota );
			continue;
		}
		$antes = array();
		foreach ( $split['reals'] as $r ) {
			$antes[] = $r['label'] . ': ' . ( null === $r['qty'] ? '-' : $r['qty'] );
		}
		$puestas = array();
		foreach ( $poner as $mid => $n ) {
			$puestas[] = dox_pos_real_size_label( wc_get_product( $mid ) ) . ': ' . $n;
			if ( $aplicar ) {
				$m = wc_get_product( $mid );
				$m->update_meta_data( DOX_POS_REAL_QTY_META, $n ); // Solo la etiqueta: el stock no se toca.
				$m->save_meta_data();
			}
		}
		dox_pos_real_forget();
		$out['repartido'][] = $fila + array( 'nota' => 'Puestas ' . implode( ', ', $puestas ) . ' (antes ' . implode( ', ', $antes ) . ')' );
	}
}

/* ============================ Informe ============================ */

$f = fopen( $opt['salida'], 'w' );
fwrite( $f, "\xEF\xBB\xBF" ); // Para que Excel lea las tildes.
fputcsv( $f, array( 'Lista', 'Producto', 'ID', 'Color', 'Total en la tienda', 'Suma del Excel', 'Tallas en el Excel (* talla real)', 'Nota' ) );
$nombres = array( 'repartido' => $aplicar ? 'Repartidos' : 'Se repartirían', 'no_cuadra' => 'No cuadran', 'no_encontrado' => 'No encontrados en el Excel' );
foreach ( $out as $k => $lista ) {
	foreach ( $lista as $r ) {
		fputcsv( $f, array( $nombres[ $k ], $r['producto'], $r['id'], $r['color'], $r['total_tienda'], $r['excel'], $r['tallas'], $r['nota'] ) );
	}
}
fclose( $f );
printf( "%s: %d | no cuadran: %d | no encontrados: %d | informe: %s\n", $nombres['repartido'], count( $out['repartido'] ), count( $out['no_cuadra'] ), count( $out['no_encontrado'] ), $opt['salida'] );
echo $aplicar ? "Escrito.\n" : "Modo de prueba: no se escribió nada (añade --aplicar).\n";
