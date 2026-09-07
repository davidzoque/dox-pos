<?php
/**
 * Los pedidos: vender, apartar, y sus cambios de estado. Todo son pedidos de
 * WooCommerce creados con created_via = dox-pos, así que el stock lo mueve
 * WooCommerce solo: baja al pasar a "procesando" o "en espera" y vuelve al cancelar.
 * Con dos cajas a la vez, la última unidad la reserva primero el pedido con el
 * mecanismo del checkout (wc_reserve_stock_for_order), y el otro recibe el error.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DOX_POS_VIA = 'dox-pos';

/**
 * De dónde viene un pedido: "caja" (este plugin), "web" (el checkout de la tienda),
 * "admin" (hecho a mano en WooCommerce > Pedidos) u "otro" (una app, la API).
 */
function dox_pos_order_origin( $order ) {
	$via = (string) $order->get_created_via();
	if ( DOX_POS_VIA === $via ) {
		return 'caja';
	}
	if ( in_array( $via, array( 'checkout', 'store-api', '' ), true ) ) {
		return 'web';
	}
	return 'admin' === $via ? 'admin' : 'otro';
}

function dox_pos_origin_label( $origin ) {
	$labels = array(
		'caja'  => __( 'Caja', 'dox-pos' ),
		'web'   => __( 'Página web', 'dox-pos' ),
		'admin' => __( 'Manual', 'dox-pos' ),
		'otro'  => __( 'Otro', 'dox-pos' ),
	);
	return $labels[ $origin ] ?? $origin;
}

/**
 * Estado "Enviado": WooCommerce no lo trae y a Camila le hace falta entre procesando y completado.
 */
add_action( 'init', 'dox_pos_register_status' );
function dox_pos_register_status() {
	register_post_status(
		'wc-enviado',
		array(
			'label'                     => _x( 'Enviado', 'Estado de pedido', 'dox-pos' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: cantidad */
			'label_count'               => _n_noop( 'Enviado <span class="count">(%s)</span>', 'Enviados <span class="count">(%s)</span>', 'dox-pos' ),
		)
	);
}

add_filter( 'wc_order_statuses', 'dox_pos_order_statuses' );
function dox_pos_order_statuses( $statuses ) {
	$out = array();
	foreach ( $statuses as $k => $v ) {
		$out[ $k ] = $v;
		if ( 'wc-processing' === $k ) {
			$out['wc-enviado'] = _x( 'Enviado', 'Estado de pedido', 'dox-pos' );
		}
	}
	if ( ! isset( $out['wc-enviado'] ) ) {
		$out['wc-enviado'] = _x( 'Enviado', 'Estado de pedido', 'dox-pos' );
	}
	return $out;
}

// Un pedido enviado cuenta como pagado (informes) y no devuelve stock.
add_filter( 'woocommerce_order_is_paid_statuses', 'dox_pos_paid_statuses' );
function dox_pos_paid_statuses( $statuses ) {
	$statuses[] = 'enviado';
	return $statuses;
}

// Un apartado ("en espera") se puede pagar por su link. En WooCommerce normal, solo los pendientes.
add_filter( 'woocommerce_valid_order_statuses_for_payment', 'dox_pos_statuses_for_payment', 10, 2 );
function dox_pos_statuses_for_payment( $statuses, $order ) {
	if ( $order instanceof WC_Order && DOX_POS_VIA === $order->get_created_via() ) {
		$statuses[] = 'on-hold';
	}
	return $statuses;
}

// Los correos al administrador sobran: el pedido lo acaba de crear él mismo desde la caja.
foreach ( array( 'new_order', 'cancelled_order', 'failed_order' ) as $dox_pos_mail ) {
	add_filter( 'woocommerce_email_enabled_' . $dox_pos_mail, 'dox_pos_disable_admin_mail', 10, 2 );
}
function dox_pos_disable_admin_mail( $enabled, $order ) {
	if ( $order instanceof WC_Order && DOX_POS_VIA === $order->get_created_via() ) {
		return false;
	}
	return $enabled;
}

/**
 * Crea el pedido. Si $hold, queda en espera (apartado) con su plazo; si no, es una venta.
 *
 * @param array $data lines, channel, customer{name, phone, state, city, address}, payment, discount, shipping{label, method_id, instance_id, cost}, note, ref.
 * @param bool  $hold Apartado en vez de venta.
 * @return array|WP_Error El pedido formateado, con el mensaje de WhatsApp.
 */
function dox_pos_create_order( $data, $hold ) {
	// Si la misma venta ya entró (la cola sin señal reintenta), se devuelve la que existe.
	$ref = sanitize_text_field( $data['ref'] ?? '' );
	if ( $ref ) {
		$existing = wc_get_orders( array( 'meta_query' => array( array( 'key' => '_dox_pos_ref', 'value' => $ref ) ), 'limit' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $existing ) {
			return dox_pos_order_response( $existing[0], $hold );
		}
	}

	$lines = array();
	foreach ( (array) ( $data['lines'] ?? array() ) as $l ) {
		$p   = wc_get_product( (int) ( $l['id'] ?? 0 ) );
		$qty = (int) ( $l['qty'] ?? 0 );
		if ( ! $p || $qty < 1 ) {
			continue;
		}
		$parent = $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
		if ( ! $parent || 'publish' !== $parent->get_status() ) {
			return new WP_Error( 'dox_pos_no_disponible', sprintf( __( '%s ya no está a la venta.', 'dox-pos' ), dox_pos_item_name( $p ) ) );
		}
		$falta = dox_pos_stock_problem( $p, $qty );
		if ( $falta ) {
			return $falta;
		}
		$lines[] = array( 'product' => $p, 'qty' => $qty );
	}
	if ( ! $lines ) {
		return new WP_Error( 'dox_pos_sin_lineas', __( 'El pedido no tiene productos.', 'dox-pos' ) );
	}

	$methods = dox_pos_payment_methods();
	$pay_key = sanitize_key( $data['payment'] ?? 'transferencia' );
	$pay     = $methods[ $pay_key ] ?? $methods[ dox_pos_default_payment() ];
	$cust    = (array) ( $data['customer'] ?? array() );
	$name    = sanitize_text_field( $cust['name'] ?? '' );
	$parts   = preg_split( '/\s+/', trim( $name ), 2 );
	$address = array(
		'first_name' => $parts[0] ?? '',
		'last_name'  => $parts[1] ?? '',
		'phone'      => sanitize_text_field( $cust['phone'] ?? '' ),
		'address_1'  => sanitize_text_field( $cust['address'] ?? '' ),
		'city'       => sanitize_text_field( $cust['city'] ?? '' ),
		'state'      => sanitize_text_field( $cust['state'] ?? '' ),
		'country'    => dox_pos_country(),
	);

	$order = wc_create_order( array( 'customer_id' => 0, 'created_via' => DOX_POS_VIA ) );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	foreach ( $lines as $l ) {
		$item_id = $order->add_product( $l['product'], $l['qty'] );
		// El nombre de la línea lleva talla y color, que si no el pedido dice solo "Gafas de Sol".
		$item = $order->get_item( $item_id, false );
		if ( $item ) {
			$item->set_name( dox_pos_item_name( $l['product'] ) );
			$item->save();
		}
	}
	$discount = max( 0, (float) ( $data['discount'] ?? 0 ) );
	if ( $discount > 0 ) {
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( __( 'Descuento', 'dox-pos' ) );
		$fee->set_amount( -$discount );
		$fee->set_total( -$discount );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );
	}
	$ship = (array) ( $data['shipping'] ?? array() );
	if ( ! empty( $ship['label'] ) ) {
		$item = new WC_Order_Item_Shipping();
		$item->set_method_title( sanitize_text_field( $ship['label'] ) );
		$item->set_method_id( sanitize_key( $ship['method_id'] ?? 'dox_pos' ) );
		$item->set_instance_id( (int) ( $ship['instance_id'] ?? 0 ) );
		$item->set_total( max( 0, (float) ( $ship['cost'] ?? 0 ) ) );
		$order->add_item( $item );
	}
	$order->set_address( $address, 'billing' );
	$order->set_address( $address, 'shipping' );
	$order->set_payment_method( $pay['id'] );
	$order->set_payment_method_title( $pay['title'] );
	$order->set_customer_note( sanitize_textarea_field( $data['note'] ?? '' ) );
	$user = wp_get_current_user();
	$order->update_meta_data( '_dox_pos', 1 );
	$order->update_meta_data( '_dox_pos_channel', sanitize_text_field( $data['channel'] ?? '' ) );
	$order->update_meta_data( '_dox_pos_seller', $user->ID );
	$order->update_meta_data( '_dox_pos_seller_name', $user->display_name );
	if ( $ref ) {
		$order->update_meta_data( '_dox_pos_ref', $ref );
	}
	$hours = dox_pos_hold_hours();
	if ( $hold ) {
		$order->update_meta_data( '_dox_pos_hold_until', time() + $hours * HOUR_IN_SECONDS );
	}
	$order->calculate_totals();
	$order->save();

	// Dos cajas registrando la última unidad en el mismo instante pasan las dos la
	// comprobación de arriba. La reserva de WooCommerce (la misma del checkout) es una
	// operación que la base de datos no deja repetir: la segunda falla y no queda pedido.
	$reserved = dox_pos_reserve_stock( $order, $lines );
	if ( is_wp_error( $reserved ) ) {
		$order->delete( true );
		return $reserved;
	}

	$who = sprintf( __( 'Registrado desde la caja por %1$s. Canal: %2$s.', 'dox-pos' ), $user->display_name, $data['channel'] ?? '' );
	if ( $hold ) {
		$order->update_status( 'on-hold', sprintf( __( 'Apartado. Si no paga en %d horas se cancela solo. ', 'dox-pos' ), $hours ) . $who );
		dox_pos_schedule_release( $order->get_id(), $hours );
	} elseif ( $pay['paid'] ) {
		$order->add_order_note( $who );
		$order->payment_complete();
	} else {
		$order->update_status( 'processing', __( 'Paga al recibir. ', 'dox-pos' ) . $who );
	}
	// El estado nuevo ya descontó el inventario: la reserva sobra. WooCommerce la suelta
	// solo al cambiar el estado; se repite por si algún plugin cortó ese gancho.
	if ( function_exists( 'wc_release_stock_for_order' ) ) {
		wc_release_stock_for_order( $order );
	}
	return dox_pos_order_response( wc_get_order( $order->get_id() ), $hold );
}

/**
 * Lo que queda libre de un producto: sus existencias menos lo que la tienda tiene
 * retenido en pagos en curso (un cliente de la web en el checkout, u otra caja en
 * ese mismo instante).
 *
 * @param WC_Product $p       Producto o variación.
 * @param int        $exclude Pedido cuya reserva no cuenta (el que se está creando).
 * @return int[] [ libres, retenidas ].
 */
function dox_pos_stock_free( $p, $exclude = 0 ) {
	$held = function_exists( 'wc_get_held_stock_quantity' ) ? (int) wc_get_held_stock_quantity( $p, $exclude ) : 0;
	return array( (int) $p->get_stock_quantity() - $held, $held );
}

/**
 * Comprueba que alcance para $qty unidades. Es la comprobación rápida de antes de
 * crear el pedido; la que de verdad evita vender dos veces la última unidad es la
 * reserva de dox_pos_reserve_stock().
 *
 * @param WC_Product $p   Producto o variación.
 * @param int        $qty Unidades que se piden.
 * @return WP_Error|null Null si alcanza.
 */
function dox_pos_stock_problem( $p, $qty ) {
	$name = dox_pos_item_name( $p );
	if ( ! $p->is_in_stock() ) {
		return new WP_Error( 'dox_pos_sin_stock', sprintf( __( '%s está agotado.', 'dox-pos' ), $name ) );
	}
	if ( ! $p->managing_stock() || $p->backorders_allowed() ) {
		return null;
	}
	list( $free, $held ) = dox_pos_stock_free( $p );
	if ( $free >= $qty ) {
		return null;
	}
	$msg = sprintf( __( 'De %1$s quedan %2$d, no %3$d.', 'dox-pos' ), $name, max( 0, $free ), $qty );
	if ( $held > 0 ) {
		/* translators: %d: unidades retenidas */
		$msg .= ' ' . sprintf( _n( '%d está en un pago en curso.', '%d están en pagos en curso.', $held, 'dox-pos' ), $held );
	}
	return new WP_Error( 'dox_pos_sin_stock', $msg );
}

/**
 * Reserva las unidades del pedido con el mecanismo del checkout de WooCommerce
 * (wc_reserve_stock_for_order): un INSERT condicionado que bloquea la fila del
 * inventario mientras compara, así que de dos pedidos simultáneos solo uno la consigue.
 * La reserva vive segundos: el cambio de estado que viene después descuenta el
 * inventario y la suelta.
 *
 * @param WC_Order $order Pedido recién guardado, todavía pendiente.
 * @param array    $lines [ [product, qty] ], para decir cuál faltó.
 * @return true|WP_Error
 */
function dox_pos_reserve_stock( $order, $lines ) {
	if ( ! function_exists( 'wc_reserve_stock_for_order' ) ) {
		return true; // WooCommerce anterior a 4.3: queda solo la comprobación previa.
	}
	try {
		wc_reserve_stock_for_order( $order );
	} catch ( Exception $e ) {
		// WooCommerce ya soltó lo que este pedido llegó a reservar. Se busca la línea que faltó,
		// leyendo el producto de nuevo: el otro pedido pudo descontar ya.
		foreach ( $lines as $l ) {
			$p = wc_get_product( $l['product']->get_id() );
			if ( ! $p || ! $p->managing_stock() || $p->backorders_allowed() ) {
				continue;
			}
			list( $free ) = dox_pos_stock_free( $p, $order->get_id() );
			if ( $free < $l['qty'] ) {
				return new WP_Error( 'dox_pos_sin_stock', sprintf( __( 'De %1$s quedan %2$d, no %3$d: se acaba de vender por otro lado.', 'dox-pos' ), dox_pos_item_name( $p ), max( 0, $free ), $l['qty'] ) );
			}
		}
		return new WP_Error( 'dox_pos_sin_stock', __( 'Uno de los productos se acaba de vender por otro lado. Revisa las existencias y vuelve a intentarlo.', 'dox-pos' ) );
	}
	return true;
}

// La reserva de la caja dura segundos; si la tienda tiene "retener inventario" en 0
// minutos, WooCommerce no reservaría nada y se perdería la protección.
add_filter( 'woocommerce_order_hold_stock_minutes', 'dox_pos_hold_stock_minutes', 10, 2 );
function dox_pos_hold_stock_minutes( $minutes, $order ) {
	return $order instanceof WC_Order && DOX_POS_VIA === $order->get_created_via() ? 5 : $minutes;
}

/**
 * Programa la liberación del apartado.
 */
function dox_pos_schedule_release( $order_id, $hours ) {
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_unschedule_all_actions( 'dox_pos_release_hold', array( 'order_id' => $order_id ), 'dox-pos' );
		as_schedule_single_action( time() + $hours * HOUR_IN_SECONDS, 'dox_pos_release_hold', array( 'order_id' => $order_id ), 'dox-pos' );
	}
}

/**
 * Se vence el plazo: si sigue en espera, se cancela y WooCommerce devuelve el stock.
 */
add_action( 'dox_pos_release_hold', 'dox_pos_release_hold' );
function dox_pos_release_hold( $order_id ) {
	$order = wc_get_order( (int) $order_id );
	if ( $order && $order->has_status( 'on-hold' ) && DOX_POS_VIA === $order->get_created_via() ) {
		$ctx = dox_pos_stock_context( 'release', $order->get_id(), __( 'Venció el plazo', 'dox-pos' ) );
		$order->update_status( 'cancelled', __( 'Apartado vencido: se libera el inventario.', 'dox-pos' ) );
		dox_pos_stock_context_end( $ctx );
	}
}

/**
 * El pedido sobre el que se actúa: uno de la caja o, si el ajuste enseña los de la
 * web, cualquiera de la tienda.
 *
 * @return WC_Order|WP_Error
 */
function dox_pos_get_own_order( $id ) {
	$order = wc_get_order( (int) $id );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'dox_pos_no_existe', __( 'Ese pedido no existe.', 'dox-pos' ) );
	}
	if ( DOX_POS_VIA !== $order->get_created_via() && ! dox_pos_show_web_orders() ) {
		return new WP_Error( 'dox_pos_no_es_de_la_caja', __( 'Ese pedido no es de la caja.', 'dox-pos' ) );
	}
	return $order;
}

/**
 * Cambios de estado desde la lista de pedidos.
 *
 * @param int    $id     Pedido.
 * @param string $action paid | release | shipped | delivered | cancel.
 * @param array  $extra  carrier, tracking.
 * @return array|WP_Error
 */
function dox_pos_order_action( $id, $action, $extra = array() ) {
	$order = dox_pos_get_own_order( $id );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$who  = wp_get_current_user()->display_name;
	$caja = DOX_POS_VIA === $order->get_created_via();
	// Los correos al administrador sobran también aquí: el cambio lo acaba de hacer la tienda misma
	// desde la caja (WooCommerce avisa "Pedido cancelado" hasta cuando lo cancela uno mismo).
	foreach ( array( 'new_order', 'cancelled_order', 'failed_order' ) as $mail ) {
		add_filter( 'woocommerce_email_enabled_' . $mail, '__return_false' );
	}
	switch ( $action ) {
		case 'paid':
			// En la web, "fallido" es un pago que la pasarela rechazó y que puede haber entrado por otro lado.
			if ( ! $order->has_status( array( 'on-hold', 'pending', 'failed' ) ) ) {
				return new WP_Error( 'dox_pos_estado', $caja ? __( 'Ese pedido ya no está apartado.', 'dox-pos' ) : __( 'Ese pedido ya no está pendiente de pago.', 'dox-pos' ) );
			}
			as_unschedule_all_actions( 'dox_pos_release_hold', array( 'order_id' => $order->get_id() ), 'dox-pos' );
			$order->delete_meta_data( '_dox_pos_hold_until' );
			$order->add_order_note( sprintf( __( 'Pago confirmado desde la caja por %s.', 'dox-pos' ), $who ) );
			$order->payment_complete();
			break;
		case 'release':
			// Liberar es solo para los apartados de la caja; un pedido de la web se anula.
			if ( ! $caja || ! $order->has_status( array( 'on-hold', 'pending', 'failed' ) ) ) {
				return new WP_Error( 'dox_pos_estado', __( 'Ese pedido ya no está apartado.', 'dox-pos' ) );
			}
			as_unschedule_all_actions( 'dox_pos_release_hold', array( 'order_id' => $order->get_id() ), 'dox-pos' );
			$ctx = dox_pos_stock_context( 'release', $order->get_id() );
			$order->update_status( 'cancelled', sprintf( __( 'Apartado liberado desde la caja por %s.', 'dox-pos' ), $who ) );
			dox_pos_stock_context_end( $ctx );
			break;
		case 'shipped':
			if ( ! $order->has_status( 'processing' ) ) {
				return new WP_Error( 'dox_pos_estado', __( 'Solo se puede enviar un pedido que esté por enviar.', 'dox-pos' ) );
			}
			$carrier  = sanitize_text_field( $extra['carrier'] ?? '' );
			$guide    = sanitize_text_field( $extra['tracking'] ?? '' );
			$tracking = trim( $carrier . ' ' . $guide );
			if ( $tracking ) {
				$order->update_meta_data( '_dox_pos_tracking', $tracking );
				$order->update_meta_data( '_dox_pos_carrier', $carrier );
				$order->update_meta_data( '_dox_pos_guide', $guide );
				$url = dox_pos_tracking_url( $carrier, $guide );
				if ( '' !== $url ) {
					$order->update_meta_data( '_dox_pos_tracking_url', $url );
				} else {
					$order->delete_meta_data( '_dox_pos_tracking_url' );
				}
			}
			$order->update_status( 'enviado', sprintf( __( 'Enviado. %1$s Marcado por %2$s.', 'dox-pos' ), $tracking, $who ) );
			$ship = dox_pos_ship_notify( $order ); // El correo a la clienta (si tiene) y el WhatsApp listo.
			break;
		case 'delivered':
			if ( ! $order->has_status( array( 'enviado', 'processing' ) ) ) {
				return new WP_Error( 'dox_pos_estado', __( 'Ese pedido no está en camino.', 'dox-pos' ) );
			}
			$order->update_status( 'completed', sprintf( __( 'Entregado. Marcado por %s.', 'dox-pos' ), $who ) );
			break;
		case 'cancel':
			if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
				return new WP_Error( 'dox_pos_estado', __( 'Ese pedido ya estaba anulado.', 'dox-pos' ) );
			}
			as_unschedule_all_actions( 'dox_pos_release_hold', array( 'order_id' => $order->get_id() ), 'dox-pos' );
			$order->update_status( 'cancelled', sprintf( __( 'Anulado desde la caja por %s. El inventario vuelve.', 'dox-pos' ), $who ) );
			break;
		default:
			return new WP_Error( 'dox_pos_accion', __( 'Acción desconocida.', 'dox-pos' ) );
	}
	$out = dox_pos_format_order( wc_get_order( $order->get_id() ) );
	if ( isset( $ship ) ) {
		$out['ship'] = $ship;
	}
	return $out;
}

/**
 * Los pedidos, los más recientes primero: los de la caja y, si el ajuste lo dice
 * (de fábrica sí), también los de la página web y los hechos a mano en WooCommerce.
 */
function dox_pos_list_orders( $limit = 80 ) {
	$args = array(
		'limit'   => $limit,
		'orderby' => 'date',
		'order'   => 'DESC',
		'status'  => array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-enviado', 'wc-completed', 'wc-cancelled', 'wc-failed', 'wc-refunded' ),
	);
	if ( ! dox_pos_show_web_orders() ) {
		$args['created_via'] = DOX_POS_VIA;
	}
	return array_map( 'dox_pos_format_order', wc_get_orders( $args ) );
}

/**
 * Un pedido tal como lo entiende la caja. Los estados se leen según el origen: en la
 * caja, "en espera" es un apartado; en la web, "pendiente" es que dejó el pago a medias
 * (sin inventario descontado), "en espera" que el pago va en camino (PSE) y "fallido"
 * que la pasarela lo rechazó.
 */
function dox_pos_format_order( $order ) {
	$status = $order->get_status();
	$origin = dox_pos_order_origin( $order );
	$caja   = 'caja' === $origin;
	$map    = array(
		'processing' => array( 'por_enviar', __( 'Por enviar', 'dox-pos' ) ),
		'enviado'    => array( 'enviado', __( 'Enviado', 'dox-pos' ) ),
		'completed'  => array( 'entregado', __( 'Entregado', 'dox-pos' ) ),
		'cancelled'  => array( 'anulado', __( 'Anulado', 'dox-pos' ) ),
		'refunded'   => array( 'reembolsado', __( 'Reembolsado', 'dox-pos' ) ),
	);
	if ( $caja ) {
		$map['on-hold'] = array( 'apartado', __( 'Apartado', 'dox-pos' ) );
		$map['pending'] = array( 'apartado', __( 'Apartado', 'dox-pos' ) );
		$map['failed']  = array( 'apartado', __( 'Apartado', 'dox-pos' ) ); // El link de pago no pasó: sigue apartado.
	} else {
		$map['pending'] = array( 'sin_pagar', __( 'Sin pagar', 'dox-pos' ) );
		$map['on-hold'] = array( 'por_confirmar', __( 'Pago por confirmar', 'dox-pos' ) );
		$map['failed']  = array( 'fallido', __( 'Pago fallido', 'dox-pos' ) );
	}
	$st    = $map[ $status ] ?? array( $status, wc_get_order_status_name( $status ) );
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = $item->get_name() . ( $item->get_quantity() > 1 ? ' ×' . $item->get_quantity() : '' );
	}
	$until = (int) $order->get_meta( '_dox_pos_hold_until' );
	$hold  = $caja && in_array( $status, array( 'on-hold', 'pending', 'failed' ), true );
	$open  = ! in_array( $status, array( 'completed', 'cancelled', 'refunded' ), true );
	// La dirección de envío si la hay: en la web puede ser distinta de la de facturación.
	$ship  = $order->has_shipping_address();
	$city  = $ship ? $order->get_shipping_city() : $order->get_billing_city();
	$state = $ship ? $order->get_shipping_state() : $order->get_billing_state();
	$phone = $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_shipping_phone();
	$wa    = '';
	if ( $hold ) {
		$wa = dox_pos_whatsapp_url( $phone, dox_pos_hold_message( $order ) );
	} elseif ( 'enviado' === $status && $phone ) {
		$wa = dox_pos_whatsapp_url( $phone, dox_pos_ship_message( $order ) ); // Con la guía y el enlace de rastreo.
	} elseif ( ! $caja && $open && $phone ) {
		$wa = dox_pos_whatsapp_url( $phone, dox_pos_web_message( $order ) );
	}
	return array(
		'id'           => $order->get_id(),
		'number'       => $order->get_order_number(),
		'date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m H:i' ) : '',
		'customer'     => trim( $order->get_formatted_billing_full_name() ),
		'phone'        => $phone,
		'city'         => trim( $city . ( $state ? ', ' . dox_pos_state_name( $state ) : '' ), ', ' ),
		'address'      => $ship ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
		'items'        => implode( ' + ', $items ),
		'origin'       => $origin,
		'origin_label' => dox_pos_origin_label( $origin ),
		'source'       => 'web' === $origin ? dox_pos_order_source( $order ) : '',
		'channel'      => $caja ? $order->get_meta( '_dox_pos_channel' ) : dox_pos_origin_label( $origin ),
		'payment'      => $order->get_payment_method_title(),
		'paid'         => $order->is_paid(),
		'cod'          => 'cod' === $order->get_payment_method(), // Contraentrega: "procesando" no es pagado.
		'total'        => (float) $order->get_total(),
		'status'       => $st[0],
		'label'        => $st[1],
		'hours_left'   => $hold && $until ? max( 0, (int) ceil( ( $until - time() ) / HOUR_IN_SECONDS ) ) : 0,
		'tracking'     => $order->get_meta( '_dox_pos_tracking' ),
		'carrier'      => (string) $order->get_meta( '_dox_pos_carrier' ),
		'guide'        => (string) $order->get_meta( '_dox_pos_guide' ),
		'tracking_url' => (string) $order->get_meta( '_dox_pos_tracking_url' ),
		'email'        => (string) $order->get_billing_email(),
		'note'         => $order->get_customer_note(),
		'seller'       => $order->get_meta( '_dox_pos_seller_name' ),
		'pay_url'      => $hold ? $order->get_checkout_payment_url() : '',
		'whatsapp'     => $wa,
	);
}

/**
 * La respuesta al crear: el pedido y, si es apartado, el mensaje para WhatsApp.
 */
function dox_pos_order_response( $order, $hold ) {
	$out = array( 'order' => dox_pos_format_order( $order ) );
	if ( $hold ) {
		$out['message']  = dox_pos_hold_message( $order );
		$out['whatsapp'] = dox_pos_whatsapp_url( $order->get_billing_phone(), $out['message'] );
	}
	return $out;
}

/**
 * El mensaje del apartado, listo para pegar en WhatsApp. Sale de la plantilla del
 * ajuste, con sus comodines, y al final lo de "cómo pagar por fuera del link".
 */
function dox_pos_hold_message( $order ) {
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = $item->get_name() . ( $item->get_quantity() > 1 ? ' ×' . $item->get_quantity() : '' );
	}
	$text = strtr(
		dox_pos_hold_message_template(),
		array(
			'{nombre}'    => $order->get_billing_first_name(),
			'{productos}' => implode( ' + ', $items ),
			'{total}'     => dox_pos_money( $order->get_total() ),
			'{horas}'     => dox_pos_hold_hours(),
			'{link}'      => $order->get_checkout_payment_url(),
			'{tienda}'    => dox_pos_brand_name(),
		)
	);
	// Sin nombre, "Hola , te aparté" queda "Hola, te aparté".
	$text = preg_replace( '/[ \t]+([,.!?])/', '$1', $text );
	$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
	$note = dox_pos_payment_note();
	if ( $note ) {
		$text .= "\n" . $note;
	}
	return trim( $text );
}

/**
 * Enlace wa.me con el mensaje escrito. Un número local (sin indicativo) lleva
 * delante el del país de la tienda: en Colombia, 3001234567 pasa a 573001234567.
 */
function dox_pos_whatsapp_url( $phone, $text ) {
	$digits = preg_replace( '/\D/', '', (string) $phone );
	$code   = dox_pos_calling_code();
	if ( $code && strlen( $digits ) <= 10 && 0 !== strpos( $digits, $code ) ) {
		$digits = $code . $digits;
	}
	return 'https://wa.me/' . $digits . '?text=' . rawurlencode( $text );
}

/**
 * Mensaje corto para escribirle por WhatsApp a quien compró por la web.
 */
function dox_pos_web_message( $order ) {
	/* translators: 1: nombre, 2: tienda, 3: número de pedido */
	$text = sprintf( __( 'Hola %1$s, te escribo de %2$s por tu pedido #%3$s.', 'dox-pos' ), $order->get_billing_first_name(), dox_pos_brand_name(), $order->get_order_number() );
	return trim( preg_replace( '/[ \t]+([,.!?])/', '$1', $text ) );
}

/**
 * De dónde llegó el cliente de la web, según la atribución que guarda WooCommerce
 * (Instagram, Google, Facebook...). Vacío si entró directo o no se sabe.
 */
function dox_pos_order_source( $order ) {
	$type = (string) $order->get_meta( '_wc_order_attribution_source_type' );
	$src  = strtolower( trim( (string) $order->get_meta( '_wc_order_attribution_utm_source' ) ) );
	if ( 'typein' === $type || '' === $src || '(direct)' === $src ) {
		return '';
	}
	$known = array( 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'google' => 'Google', 'tiktok' => 'TikTok', 'whatsapp' => 'WhatsApp', 'pinterest' => 'Pinterest', 'youtube' => 'YouTube' );
	foreach ( $known as $needle => $name ) {
		if ( false !== strpos( $src, $needle ) ) {
			return $name;
		}
	}
	if ( in_array( $src, array( 'ig', 'fb' ), true ) ) {
		return 'ig' === $src ? 'Instagram' : 'Facebook';
	}
	return ucfirst( preg_replace( '/^(www|l|m|lm)\./', '', $src ) );
}

/**
 * El nombre del departamento a partir de su código.
 */
function dox_pos_state_name( $code ) {
	$states = WC()->countries->get_states( dox_pos_country() );
	return $states[ $code ] ?? $code;
}

/* =====================================================================
 * El aviso del envío: el mensaje de WhatsApp y el correo a la clienta
 * ===================================================================== */

/**
 * El mensaje de WhatsApp del envío, con la transportadora, la guía y el enlace de rastreo.
 * Una línea cuyo dato quedó vacío ("Guía:") se quita sola.
 */
function dox_pos_ship_message( $order ) {
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = $item->get_name() . ( $item->get_quantity() > 1 ? ' ×' . $item->get_quantity() : '' );
	}
	$carrier = trim( (string) $order->get_meta( '_dox_pos_carrier' ) );
	$text    = strtr(
		dox_pos_ship_message_template(),
		array(
			'{nombre}'         => $order->get_billing_first_name(),
			'{pedido}'         => $order->get_order_number(),
			'{transportadora}' => $carrier ? $carrier : __( 'la transportadora', 'dox-pos' ),
			'{guia}'           => trim( (string) $order->get_meta( '_dox_pos_guide' ) ),
			'{link}'           => (string) $order->get_meta( '_dox_pos_tracking_url' ),
			'{productos}'      => implode( ' + ', $items ),
			'{tienda}'         => dox_pos_brand_name(),
		)
	);
	$text = preg_replace( '/^[^\n]*:[ \t]*$/m', '', $text );
	$text = preg_replace( '/[ \t]+([,.!?])/', '$1', $text );
	$text = preg_replace( '/[ \t]{2,}/', ' ', $text );
	$text = preg_replace( "/\n{2,}/", "\n", $text );
	return trim( $text );
}

/**
 * Al marcar enviado: el correo a la clienta si el pedido tiene correo (y el ajuste está
 * encendido), y el enlace de WhatsApp listo si tiene teléfono. Un pedido de demostración no
 * manda correos. Devuelve lo que pasó, para que la caja lo diga.
 *
 * @return array email, sent, whatsapp, demo.
 */
function dox_pos_ship_notify( $order ) {
	$order = wc_get_order( $order->get_id() ); // Con los datos recién guardados.
	$out   = array( 'email' => '', 'sent' => false, 'whatsapp' => '', 'demo' => (bool) $order->get_meta( '_dox_pos_demo' ) );
	$email = sanitize_email( (string) $order->get_billing_email() );
	if ( $email && dox_pos_ship_email_on() && ! $out['demo'] ) {
		$out['email'] = $email;
		$out['sent']  = dox_pos_ship_email( $order );
		/* translators: %s: correo */
		$order->add_order_note( $out['sent'] ? sprintf( __( 'Aviso de envío mandado por correo a %s.', 'dox-pos' ), $email ) : sprintf( __( 'No se pudo mandar el aviso de envío por correo a %s.', 'dox-pos' ), $email ) );
	}
	$phone = $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_shipping_phone();
	if ( $phone ) {
		$out['whatsapp'] = dox_pos_whatsapp_url( $phone, dox_pos_ship_message( $order ) );
	}
	return $out;
}

/**
 * El correo a la clienta cuando su pedido sale: transportadora, guía, enlace de rastreo, lo que
 * va en el paquete y a dónde. Con la plantilla de correos de WooCommerce (logo, colores y pie de
 * la tienda) y desde el remitente de la tienda, así que se ve como los demás correos que recibe.
 *
 * @return bool Si salió.
 */
function dox_pos_ship_email( $order ) {
	$to = sanitize_email( (string) $order->get_billing_email() );
	if ( ! $to || ! function_exists( 'WC' ) || ! WC()->mailer() ) {
		return false;
	}
	$brand   = dox_pos_brand_name();
	$number  = $order->get_order_number();
	$carrier = trim( (string) $order->get_meta( '_dox_pos_carrier' ) );
	$guide   = trim( (string) $order->get_meta( '_dox_pos_guide' ) );
	$url     = (string) $order->get_meta( '_dox_pos_tracking_url' );
	$color   = sanitize_hex_color( (string) get_option( 'woocommerce_email_base_color', '#7f54b3' ) );
	$color   = $color ? $color : '#7f54b3';
	$items   = array();
	foreach ( $order->get_items() as $it ) {
		$items[] = esc_html( $it->get_name() . ( $it->get_quantity() > 1 ? ' ×' . $it->get_quantity() : '' ) );
	}
	$addr = $order->get_formatted_shipping_address();
	if ( ! $addr ) {
		$addr = $order->get_formatted_billing_address();
	}
	$name = trim( (string) $order->get_billing_first_name() );
	/* translators: %s: nombre */
	$html = '<p>' . ( $name ? sprintf( esc_html__( 'Hola %s,', 'dox-pos' ), esc_html( $name ) ) : esc_html__( 'Hola,', 'dox-pos' ) ) . '</p>';
	/* translators: 1: pedido, 2: transportadora */
	$html .= '<p>' . ( $carrier ? sprintf( esc_html__( 'Tu pedido #%1$s ya salió con %2$s.', 'dox-pos' ), esc_html( $number ), esc_html( $carrier ) ) : sprintf( /* translators: %s: pedido */ esc_html__( 'Tu pedido #%s ya salió.', 'dox-pos' ), esc_html( $number ) ) );
	if ( $guide ) {
		/* translators: %s: guía */
		$html .= ' ' . sprintf( esc_html__( 'Número de guía: %s.', 'dox-pos' ), '<strong>' . esc_html( $guide ) . '</strong>' );
	}
	$html .= '</p>';
	if ( $url ) {
		$html .= '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;background:' . esc_attr( $color ) . ';color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">' . esc_html__( 'Seguir el envío', 'dox-pos' ) . '</a></p>';
		if ( $guide && false === strpos( $url, rawurlencode( $guide ) ) ) {
			$html .= '<p>' . esc_html__( 'En esa página escribe el número de guía para ver dónde va.', 'dox-pos' ) . '</p>';
		}
	}
	if ( $items ) {
		$html .= '<p><strong>' . esc_html__( 'Lo que va en el paquete', 'dox-pos' ) . '</strong><br>' . implode( '<br>', $items ) . '</p>';
	}
	if ( $addr ) {
		$html .= '<p><strong>' . esc_html__( 'Va a', 'dox-pos' ) . '</strong><br>' . wp_kses( $addr, array( 'br' => array() ) ) . '</p>';
	}
	$html .= '<p>' . esc_html__( 'Si tienes cualquier duda, responde a este correo.', 'dox-pos' ) . '</p>';
	$html .= '<p>' . esc_html__( 'Gracias por tu compra,', 'dox-pos' ) . '<br>' . esc_html( $brand ) . '</p>';
	/* translators: %s: pedido */
	$heading = sprintf( __( 'Tu pedido #%s va en camino', 'dox-pos' ), $number );
	/* translators: 1: tienda, 2: pedido */
	$subject = sprintf( __( '%1$s: tu pedido #%2$s va en camino', 'dox-pos' ), $brand, $number );
	$mailer  = WC()->mailer();
	return (bool) $mailer->send( $to, $subject, $mailer->wrap_message( $heading, $html ) );
}

/* =====================================================================
 * El detalle de un pedido, para abrirlo desde la lista
 * ===================================================================== */

/**
 * Un pedido con todo lo que la caja enseña al abrirlo: cada producto con su foto, código, talla
 * y color, enlace a la tienda y existencias; los totales; el cliente y su dirección; el pago y
 * el envío; y el historial (las últimas doce notas del pedido, que WooCommerce apunta con cada
 * cambio de estado y la caja con cada aviso).
 *
 * @param int $id Pedido.
 * @return array|WP_Error
 */
function dox_pos_order_detail( $id ) {
	$order = dox_pos_get_own_order( $id );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$f     = dox_pos_format_order( $order );
	$items = array();
	foreach ( $order->get_items() as $it ) {
		$pid    = (int) $it->get_product_id();
		$vid    = (int) $it->get_variation_id();
		$p      = $vid ? wc_get_product( $vid ) : ( $pid ? wc_get_product( $pid ) : null );
		$p      = $p ? $p : null;
		$parent = $p && $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
		$parent = $parent ? $parent : null;
		$img    = $p ? (int) $p->get_image_id() : 0;
		if ( ! $img && $parent ) {
			$img = (int) $parent->get_image_id();
		}
		$sku = $p ? (string) $p->get_sku( 'edit' ) : '';
		if ( '' === $sku && $parent ) {
			$sku = (string) $parent->get_sku( 'edit' );
		}
		$qty   = (int) $it->get_quantity();
		$stock = null;
		if ( $p && $p->managing_stock() ) {
			$stock = 'parent' === $p->get_manage_stock() && $parent ? (int) $parent->get_stock_quantity() : (int) $p->get_stock_quantity();
		}
		$items[] = array(
			'id'           => $vid ? $vid : $pid,
			'product_id'   => $pid,
			'variation_id' => $vid,
			'name'         => $it->get_name(),
			'sku'          => $sku,
			'qty'          => $qty,
			'price'        => $qty > 0 ? round( (float) $it->get_subtotal() / $qty ) : 0,
			'total'        => (float) $it->get_total(),
			'image'        => $img ? (string) wp_get_attachment_image_url( $img, 'woocommerce_thumbnail' ) : '',
			'url'          => $parent && 'publish' === $parent->get_status() ? get_permalink( $parent->get_id() ) : '',
			'stock'        => $stock,
			'exists'       => (bool) $p,
			'editable'     => (bool) $parent && in_array( $parent->get_type(), array( 'simple', 'variable' ), true ),
		);
	}
	$discount = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( (float) $fee->get_total() < 0 ) {
			$discount += -(float) $fee->get_total();
		}
	}
	$ship_method = '';
	foreach ( $order->get_shipping_methods() as $sm ) {
		$ship_method = (string) $sm->get_method_title();
	}
	$notes = array();
	foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 12 ) ) as $n ) {
		$notes[] = array(
			'date'     => $n->date_created ? $n->date_created->date_i18n( 'd/m H:i' ) : '',
			'text'     => trim( wp_strip_all_tags( (string) $n->content ) ),
			'customer' => ! empty( $n->customer_note ),
		);
	}
	$ship  = $order->has_shipping_address();
	$until = (int) $order->get_meta( '_dox_pos_hold_until' );
	$fmt   = 'j \d\e F, G:i';
	return array_merge(
		$f,
		array(
			'items_list'      => $items,
			'subtotal'        => (float) $order->get_subtotal(),
			'discount'        => $discount,
			'shipping_total'  => (float) $order->get_shipping_total(),
			'shipping_method' => $ship_method,
			'address2'        => (string) ( $ship ? $order->get_shipping_address_2() : $order->get_billing_address_2() ),
			'created'         => $order->get_date_created() ? $order->get_date_created()->date_i18n( $fmt ) : '',
			'paid_at'         => $order->get_date_paid() ? $order->get_date_paid()->date_i18n( $fmt ) : '',
			'completed_at'    => $order->get_date_completed() ? $order->get_date_completed()->date_i18n( $fmt ) : '',
			'hold_until'      => $until ? wp_date( $fmt, $until ) : '',
			'notes'           => $notes,
			'edit_url'        => current_user_can( 'manage_woocommerce' ) ? $order->get_edit_order_url() : '',
			'demo'            => (bool) $order->get_meta( '_dox_pos_demo' ),
		)
	);
}
