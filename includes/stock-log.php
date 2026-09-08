<?php
/**
 * El kardex: cada cambio de existencias deja una fila en su propia tabla, con cuántas había,
 * cuántas quedan, el motivo (venta #, apartado #, entrada #, anulación, edición, WooCommerce...),
 * quién lo hizo y el costo por unidad en ese momento (el de compra en una entrada). WooCommerce no guarda esto: solo deja una nota en el pedido cuando descuenta,
 * y de un cambio a mano en un producto no queda rastro.
 *
 * Se captura con los avisos que WooCommerce lanza al cambiar existencias
 * (woocommerce_product_set_stock y woocommerce_variation_set_stock), así que registra también
 * lo que se haga por fuera de la caja. El motivo lo pone el propio plugin cuando es él quien
 * mueve las existencias (dox_pos_stock_context) y, si no, se deduce del pedido que está
 * cambiando de estado o de dónde viene la petición (wp-admin, importación, API).
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dox_pos_stock_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'dox_pos_stock_log';
}

/* =====================================================================
 * El motivo: lo dice el plugin cuando es él quien mueve las existencias
 * ===================================================================== */

/**
 * Abre un contexto: todo cambio de existencias hasta dox_pos_stock_context_end() se apunta
 * con este motivo. Devuelve el contexto anterior, para restaurarlo.
 *
 * @param string $reason sale | hold | web | cancel | release | refund | entry | entry_undo | create | edit | admin | import | api | cli | other.
 * @param int    $ref_id El pedido o la entrada, si lo hay.
 * @param string $note   Un detalle corto (el proveedor, la factura).
 * @param array  $costs  Solo una entrada: el costo de compra por unidad de cada producto o talla (id => costo).
 * @return array|null
 */
function dox_pos_stock_context( $reason, $ref_id = 0, $note = '', $costs = array() ) {
	$prev = $GLOBALS['dox_pos_stock_ctx'] ?? null;
	$GLOBALS['dox_pos_stock_ctx'] = array( 'reason' => (string) $reason, 'ref_id' => (int) $ref_id, 'note' => (string) $note, 'ids' => array(), 'costs' => (array) $costs );
	return $prev;
}

/**
 * Cierra el contexto y devuelve los ids de las filas que se apuntaron mientras estuvo abierto
 * (una entrada de mercancía los usa para ponerles su número, que no se sabe hasta guardarla).
 *
 * @param array|null $prev Lo que devolvió dox_pos_stock_context().
 * @return int[]
 */
function dox_pos_stock_context_end( $prev = null ) {
	$ids = $GLOBALS['dox_pos_stock_ctx']['ids'] ?? array();
	$GLOBALS['dox_pos_stock_ctx'] = $prev;
	return $ids;
}

/**
 * Pone el pedido o la entrada a unas filas ya apuntadas.
 *
 * @param int[] $ids    Filas.
 * @param int   $ref_id Pedido o entrada.
 */
function dox_pos_stock_log_set_ref( $ids, $ref_id ) {
	global $wpdb;
	$ids = array_filter( array_map( 'intval', (array) $ids ) );
	if ( ! $ids || ! (int) $ref_id ) {
		return;
	}
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . dox_pos_stock_log_table() . ' SET ref_id = %d WHERE id IN (' . implode( ',', $ids ) . ')', (int) $ref_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Los ids son enteros.
}

/* =====================================================================
 * El pedido que está cambiando de estado: WooCommerce descuenta o devuelve en ese momento
 * ===================================================================== */

// Descuenta al pagar, al pasar a procesando, completado o en espera; devuelve al cancelar o volver a pendiente.
foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed', 'woocommerce_order_status_on-hold' ) as $dox_pos_hook ) {
	add_action( $dox_pos_hook, 'dox_pos_stock_order_reduce', 9 );
	add_action( $dox_pos_hook, 'dox_pos_stock_order_done', 11 );
}
foreach ( array( 'woocommerce_order_status_cancelled', 'woocommerce_order_status_pending', 'woocommerce_order_status_refunded' ) as $dox_pos_hook ) {
	add_action( $dox_pos_hook, 'dox_pos_stock_order_restore', 9 );
	add_action( $dox_pos_hook, 'dox_pos_stock_order_done', 11 );
}

function dox_pos_stock_order_reduce( $order_id ) {
	dox_pos_stock_order_context( $order_id, false );
}

function dox_pos_stock_order_restore( $order_id ) {
	dox_pos_stock_order_context( $order_id, true );
}

/**
 * Apunta qué pedido está cambiando de estado, salvo que el plugin ya haya dicho el motivo
 * (un apartado vencido, por ejemplo).
 *
 * @param int  $order_id Pedido.
 * @param bool $restore  Si devuelve existencias (cancelado) en vez de descontarlas.
 */
function dox_pos_stock_order_context( $order_id, $restore ) {
	if ( ! empty( $GLOBALS['dox_pos_stock_ctx']['reason'] ) ) {
		return;
	}
	$order = wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$caja = DOX_POS_VIA === $order->get_created_via();
	if ( $restore ) {
		$reason = $order->has_status( 'refunded' ) ? 'refund' : 'cancel';
	} elseif ( $caja ) {
		$reason = $order->has_status( 'on-hold' ) ? 'hold' : 'sale';
	} else {
		$reason = in_array( dox_pos_order_origin( $order ), array( 'web', 'otro' ), true ) ? 'web' : 'sale';
	}
	$GLOBALS['dox_pos_stock_order'] = array( 'reason' => $reason, 'ref_id' => (int) $order_id, 'note' => '' );
}

function dox_pos_stock_order_done() {
	unset( $GLOBALS['dox_pos_stock_order'] );
}

/* =====================================================================
 * La captura: antes y después de cada cambio
 * ===================================================================== */

// WooCommerce avisa antes y después de cada cambio. Ojo: en el aviso de antes el objeto ya puede
// llevar el valor nuevo (al guardar un producto lo lanza justo antes de escribir el meta), así que las
// existencias viejas se leen de lo que el objeto tenía cargado de la base (get_data), no de la propiedad.
add_action( 'woocommerce_product_before_set_stock', 'dox_pos_stock_remember' );
add_action( 'woocommerce_variation_before_set_stock', 'dox_pos_stock_remember' );
// Por si un WooCommerce viejo no avisa antes al guardar: se lee igual antes de escribir.
add_action( 'woocommerce_before_product_object_save', 'dox_pos_stock_remember_save' );
add_action( 'woocommerce_before_product_variation_object_save', 'dox_pos_stock_remember_save' );
add_action( 'woocommerce_product_set_stock', 'dox_pos_stock_changed' );
add_action( 'woocommerce_variation_set_stock', 'dox_pos_stock_changed' );

function dox_pos_stock_remember( $product ) {
	if ( $product instanceof WC_Product && $product->get_id() && ! array_key_exists( $product->get_id(), $GLOBALS['dox_pos_stock_before'] ?? array() ) ) {
		$data = $product->get_data(); // Lo cargado de la base, sin los cambios pendientes.
		$GLOBALS['dox_pos_stock_before'][ $product->get_id() ] = $data['stock_quantity'] ?? null;
	}
}

function dox_pos_stock_remember_save( $product ) {
	if ( ! $product instanceof WC_Product || ! $product->get_id() ) {
		return;
	}
	if ( ! array_key_exists( 'stock_quantity', $product->get_changes() ) ) {
		return;
	}
	if ( array_key_exists( $product->get_id(), $GLOBALS['dox_pos_stock_before'] ?? array() ) ) {
		return; // Ya lo apuntó el aviso de antes.
	}
	$data = $product->get_data(); // Lo cargado de la base, sin los cambios pendientes.
	$GLOBALS['dox_pos_stock_before'][ $product->get_id() ] = $data['stock_quantity'] ?? null;
}

/**
 * Las existencias cambiaron: se apunta la fila. Nunca lanza errores; el kardex no frena una venta.
 *
 * @param WC_Product $product Producto o variación, ya con las existencias nuevas.
 */
function dox_pos_stock_changed( $product ) {
	try {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$id = (int) $product->get_id();
		if ( ! $id ) {
			return;
		}
		$after    = $product->get_stock_quantity();
		$known    = array_key_exists( $id, $GLOBALS['dox_pos_stock_before'] ?? array() );
		$before   = $known ? $GLOBALS['dox_pos_stock_before'][ $id ] : null;
		$creating = false;
		unset( $GLOBALS['dox_pos_stock_before'][ $id ] );
		if ( ! $known || null === $before ) {
			// ¿Se acaba de crear en esta misma petición? Entonces antes había 0. Sin dato de antes y sin
			// ser nuevo, no se apunta (sería repetir un aviso ya apuntado); si es conocido pero nulo, es
			// un producto que no llevaba existencias y empieza a llevarlas: queda con "antes" en blanco.
			$post = get_post( $id );
			$born = $post ? (int) get_post_time( 'U', true, $post ) : 0;
			if ( $born && $born >= (int) ( $_SERVER['REQUEST_TIME'] ?? time() ) - 60 ) {
				$before   = 0;
				$creating = true;
			} elseif ( ! $known ) {
				return;
			}
		}
		if ( null === $after ) {
			return; // Un producto que no lleva existencias (el padre de un variable, por ejemplo): nada que apuntar.
		}
		$b = null === $before ? null : (int) $before;
		$a = (int) $after;
		if ( $b === $a ) {
			return;
		}
		$key = $b . '>' . $a;
		if ( ( $GLOBALS['dox_pos_stock_last'][ $id ] ?? '' ) === $key ) {
			return; // El mismo cambio avisado dos veces.
		}
		$GLOBALS['dox_pos_stock_last'][ $id ] = $key;
		$ctx  = dox_pos_stock_guess_context( $creating );
		$user = wp_get_current_user();
		// El costo por unidad en este momento: el de compra si es una entrada que lo trae; si no, el del producto.
		$unit = isset( $ctx['costs'][ $id ] ) ? (float) $ctx['costs'][ $id ] : dox_pos_product_cost( $product );
		global $wpdb;
		$wpdb->insert(
			dox_pos_stock_log_table(),
			array(
				'created_at'   => current_time( 'mysql' ),
				'product_id'   => $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : $id,
				'variation_id' => $product->is_type( 'variation' ) ? $id : 0,
				'sku'          => (string) $product->get_sku( 'edit' ),
				'name'         => dox_pos_item_name( $product ),
				'qty_before'   => $b,
				'qty_after'    => $a,
				'delta'        => (int) $a - (int) $b,
				'unit_cost'    => null === $unit ? null : round( (float) $unit, 2 ),
				'reason'       => $ctx['reason'],
				'ref_id'       => (int) $ctx['ref_id'],
				'user_id'      => (int) $user->ID,
				'user_name'    => $user->ID ? (string) $user->display_name : '',
				'note'         => mb_substr( (string) $ctx['note'], 0, 255 ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%d', '%d', '%s', '%s' )
		);
		if ( $wpdb->insert_id && isset( $GLOBALS['dox_pos_stock_ctx']['ids'] ) ) {
			$GLOBALS['dox_pos_stock_ctx']['ids'][] = (int) $wpdb->insert_id;
		}
	} catch ( Throwable $e ) {
		// El kardex nunca frena una venta: el error queda en el registro de PHP y la venta sigue.
		error_log( 'Dox POS kardex: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * El motivo cuando el plugin no lo dijo: el pedido que cambia de estado, y si no, de dónde viene la petición.
 *
 * @param bool $creating El producto se acaba de crear.
 * @return array{reason:string,ref_id:int,note:string}
 */
function dox_pos_stock_guess_context( $creating ) {
	$ctx = $GLOBALS['dox_pos_stock_ctx'] ?? null;
	if ( is_array( $ctx ) && ! empty( $ctx['reason'] ) ) {
		return $ctx;
	}
	$o = $GLOBALS['dox_pos_stock_order'] ?? null;
	if ( is_array( $o ) ) {
		return $o;
	}
	$note = $creating ? __( 'Producto nuevo', 'dox-pos' ) : '';
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return array( 'reason' => 'cli', 'ref_id' => 0, 'note' => $note );
	}
	if ( wp_doing_ajax() ) {
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo para saber de dónde viene.
		if ( 'woocommerce_do_ajax_product_import' === $action ) {
			return array( 'reason' => 'import', 'ref_id' => 0, 'note' => $note );
		}
		if ( 'woocommerce_refund_line_items' === $action ) {
			return array( 'reason' => 'refund', 'ref_id' => (int) ( $_REQUEST['order_id'] ?? 0 ), 'note' => '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return array( 'reason' => 'api', 'ref_id' => 0, 'note' => $note );
	}
	if ( wp_doing_cron() || did_action( 'action_scheduler_before_execute' ) > did_action( 'action_scheduler_after_execute' ) ) {
		return array( 'reason' => 'other', 'ref_id' => 0, 'note' => __( 'Tarea programada', 'dox-pos' ) );
	}
	if ( is_admin() ) {
		return array( 'reason' => 'admin', 'ref_id' => 0, 'note' => $note );
	}
	return array( 'reason' => 'other', 'ref_id' => 0, 'note' => $note );
}

/* =====================================================================
 * Cómo se leen los motivos
 * ===================================================================== */

/**
 * El texto de un motivo: "Venta #123", "Entrada #4", "Editado en la caja".
 *
 * @param string $reason El código.
 * @param int    $ref    Pedido o entrada.
 * @return string
 */
function dox_pos_stock_reason_label( $reason, $ref = 0 ) {
	$n = (int) $ref ? ' #' . (int) $ref : '';
	switch ( $reason ) {
		case 'sale':
			return __( 'Venta', 'dox-pos' ) . $n;
		case 'hold':
			return __( 'Apartado', 'dox-pos' ) . $n;
		case 'web':
			return __( 'Pedido web', 'dox-pos' ) . $n;
		case 'cancel':
			return __( 'Anulado', 'dox-pos' ) . $n;
		case 'release':
			return __( 'Apartado', 'dox-pos' ) . $n . ' ' . __( 'liberado', 'dox-pos' );
		case 'refund':
			return __( 'Devolución', 'dox-pos' ) . $n;
		case 'entry':
			return __( 'Entrada', 'dox-pos' ) . $n;
		case 'entry_undo':
			return __( 'Entrada', 'dox-pos' ) . $n . ' ' . __( 'anulada', 'dox-pos' );
		case 'create':
			return __( 'Creado en la caja', 'dox-pos' );
		case 'edit':
			return __( 'Editado en la caja', 'dox-pos' );
		case 'admin':
			return __( 'Cambiado en WooCommerce', 'dox-pos' );
		case 'import':
			return __( 'Importación', 'dox-pos' );
		case 'api':
			return __( 'Por la API', 'dox-pos' );
		case 'cli':
			return __( 'Por consola', 'dox-pos' );
		default:
			return __( 'Otro', 'dox-pos' );
	}
}

/**
 * Los motivos agrupados como los filtra la pantalla.
 *
 * @return array<string,string[]>
 */
function dox_pos_stock_kinds() {
	return array(
		'ventas'    => array( 'sale', 'hold', 'web' ),
		'entradas'  => array( 'entry', 'entry_undo' ),
		'devueltos' => array( 'cancel', 'release', 'refund' ),
		'ajustes'   => array( 'create', 'edit', 'admin', 'import', 'api', 'cli', 'other' ),
	);
}
