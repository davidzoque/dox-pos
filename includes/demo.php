<?php
/**
 * Datos de demostración: pedidos y ventas de ejemplo hechos con los productos reales de la
 * tienda, sin tocar las existencias, para enseñar la caja y el asistente "en marcha". Se crean
 * y se quitan con un botón (Ajustes > Asistente, o el aviso de la caja). Cada pedido lleva la
 * marca _dox_pos_demo y, mientras exista, ni baja ni devuelve inventario haga lo que se haga
 * con él; al quitarlos se borran del todo, con sus filas de kardex.
 *
 * @package DoxPos
 */

defined( 'ABSPATH' ) || exit;

/**
 * Si hay datos de demostración: cuándo se crearon, cuántos pedidos y quién.
 *
 * @return array|null at, orders, user.
 */
function dox_pos_demo_state() {
	$s = get_option( 'dox_pos_demo', array() );
	return is_array( $s ) && ! empty( $s['at'] ) ? $s : null;
}

function dox_pos_demo_active() {
	return null !== dox_pos_demo_state();
}

// Un pedido de demostración nunca toca el inventario: ni al pagarse, ni al anularse.
add_filter( 'woocommerce_can_reduce_order_stock', 'dox_pos_demo_no_stock', 5, 2 );
add_filter( 'woocommerce_can_restore_order_stock', 'dox_pos_demo_no_stock', 5, 2 );
function dox_pos_demo_no_stock( $can, $order ) {
	return $order instanceof WC_Order && $order->get_meta( '_dox_pos_demo' ) ? false : $can;
}

/**
 * Las clientas de ejemplo: nombre, teléfono (que no es de nadie), ciudad y dirección.
 */
function dox_pos_demo_people() {
	$rows = array(
		array( 'Valentina Ríos', 'Barranquilla', 'ATL', 'Calle 84 # 52-31' ),
		array( 'Mariana Torres', 'Barranquilla', 'ATL', 'Carrera 43 # 70-15' ),
		array( 'Isabella Mejía', 'Bogotá', 'BOG', 'Calle 116 # 15-42' ),
		array( 'Sofía Cárdenas', 'Medellín', 'ANT', 'Carrera 35 # 10-20' ),
		array( 'Camila Ortega', 'Cali', 'VAC', 'Avenida 6N # 25-11' ),
		array( 'Laura Hernández', 'Barranquilla', 'ATL', 'Carrera 51B # 87-40' ),
		array( 'Daniela Pineda', 'Cartagena', 'BOL', 'Calle 30 # 8-12' ),
		array( 'Juliana Restrepo', 'Bogotá', 'BOG', 'Carrera 11 # 93-25' ),
		array( 'Natalia Gómez', 'Santa Marta', 'MAG', 'Calle 22 # 4-18' ),
		array( 'Paula Andrade', 'Bucaramanga', 'SAN', 'Carrera 27 # 45-60' ),
		array( 'Alejandra Ruiz', 'Barranquilla', 'ATL', 'Calle 76 # 60-09' ),
		array( 'Carolina Vargas', 'Medellín', 'ANT', 'Calle 10 # 43D-27' ),
		array( 'Gabriela Salazar', 'Pereira', 'RIS', 'Carrera 8 # 21-14' ),
		array( 'Manuela Castaño', 'Bogotá', 'BOG', 'Calle 147 # 7-70' ),
		array( 'Sara Quintero', 'Barranquilla', 'ATL', 'Carrera 46 # 82-05' ),
		array( 'Luciana Peña', 'Cali', 'VAC', 'Calle 5 # 38-22' ),
		array( 'Antonella Rojas', 'Ibagué', 'TOL', 'Carrera 5 # 60-33' ),
		array( 'Emma Suárez', 'Barranquilla', 'ATL', 'Calle 93 # 49C-18' ),
		array( 'Victoria Lozano', 'Montería', 'COR', 'Calle 29 # 3-45' ),
		array( 'Salomé Duarte', 'Barranquilla', 'ATL', 'Carrera 53 # 75-102' ),
		array( 'Martina Ocampo', 'Medellín', 'ANT', 'Carrera 80 # 32-16' ),
		array( 'Renata Sierra', 'Bogotá', 'BOG', 'Calle 80 # 69-40' ),
		array( 'Amelia Correa', 'Villavicencio', 'MET', 'Carrera 33 # 38-21' ),
		array( 'Catalina Botero', 'Barranquilla', 'ATL', 'Calle 72 # 41-19' ),
	);
	$out = array();
	foreach ( $rows as $i => $r ) {
		$slug  = sanitize_title( $r[0] );
		$out[] = array(
			'name'    => $r[0],
			'phone'   => '3000000' . str_pad( (string) ( 101 + $i ), 3, '0', STR_PAD_LEFT ),
			'city'    => $r[1],
			'state'   => $r[2],
			'address' => $r[3],
			'email'   => str_replace( '-', '.', $slug ) . '@example.com',
		);
	}
	return $out;
}

/**
 * Crea un pedido de ejemplo. Nada de existencias: los filtros de arriba lo impiden, y el
 * pedido no lleva la marca de ventas contadas de WooCommerce (total_sales queda como está).
 *
 * @param array $a items [[pool, qty]], person, channel, payment (clave de la caja o 'web'),
 *                 status, ts, shipping, discount, hold_until, tracking, modified, note, seller.
 * @return WC_Order|null
 */
function dox_pos_demo_order( $a ) {
	$web   = 'web' === $a['payment'];
	$order = wc_create_order( array( 'customer_id' => 0, 'created_via' => $web ? 'checkout' : DOX_POS_VIA ) );
	if ( is_wp_error( $order ) ) {
		return null;
	}
	foreach ( $a['items'] as $pair ) {
		$p = wc_get_product( $pair[0]['id'] );
		if ( ! $p ) {
			continue;
		}
		$item_id = $order->add_product( $p, $pair[1] );
		$item    = $order->get_item( $item_id, false );
		if ( $item ) {
			$item->set_name( dox_pos_item_name( $p ) );
			$item->save();
		}
	}
	if ( ! empty( $a['discount'] ) ) {
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( __( 'Descuento', 'dox-pos' ) );
		$fee->set_amount( -(float) $a['discount'] );
		$fee->set_total( -(float) $a['discount'] );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );
	}
	if ( ! empty( $a['shipping'] ) ) {
		$sh = new WC_Order_Item_Shipping();
		$sh->set_method_title( __( 'Envío', 'dox-pos' ) );
		$sh->set_method_id( 'dox_pos' );
		$sh->set_total( (float) $a['shipping'] );
		$order->add_item( $sh );
	}
	$who   = $a['person'];
	$parts = preg_split( '/\s+/', trim( $who['name'] ), 2 );
	$addr  = array(
		'first_name' => $parts[0] ?? '',
		'last_name'  => $parts[1] ?? '',
		'phone'      => isset( $a['phone'] ) ? (string) $a['phone'] : $who['phone'],
		'address_1'  => $who['address'],
		'city'       => $who['city'],
		'state'      => $who['state'],
		'country'    => dox_pos_country(),
	);
	if ( $web ) {
		$addr['email'] = $who['email'];
	}
	$order->set_address( $addr, 'billing' );
	$order->set_address( $addr, 'shipping' );
	if ( $web ) {
		$gateways = function_exists( 'WC' ) && WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : array();
		$gw       = $gateways ? reset( $gateways ) : null;
		$order->set_payment_method( $gw ? $gw->id : 'wompi' );
		$order->set_payment_method_title( $gw ? $gw->get_title() : 'Wompi' );
	} else {
		$methods = dox_pos_payment_methods();
		$m       = $methods[ $a['payment'] ] ?? ( dox_pos_builtin_payments()[ $a['payment'] ] ?? reset( $methods ) );
		$order->set_payment_method( $m['id'] );
		$order->set_payment_method_title( $m['title'] );
		$order->update_meta_data( '_dox_pos', 1 );
		$order->update_meta_data( '_dox_pos_channel', $a['channel'] );
		$order->update_meta_data( '_dox_pos_seller', (int) $a['seller']['id'] );
		$order->update_meta_data( '_dox_pos_seller_name', $a['seller']['name'] );
	}
	$order->update_meta_data( '_dox_pos_demo', 1 );
	if ( ! empty( $a['hold_until'] ) ) {
		$order->update_meta_data( '_dox_pos_hold_until', (int) $a['hold_until'] );
	}
	if ( ! empty( $a['tracking'] ) ) {
		$order->update_meta_data( '_dox_pos_tracking', $a['tracking'] );
		$parts = explode( ' ', (string) $a['tracking'], 2 );
		$order->update_meta_data( '_dox_pos_carrier', $parts[0] );
		$order->update_meta_data( '_dox_pos_guide', $parts[1] ?? '' );
		$url = dox_pos_tracking_url( $parts[0], $parts[1] ?? '' );
		if ( '' !== $url ) {
			$order->update_meta_data( '_dox_pos_tracking_url', $url );
		}
	}
	if ( ! empty( $a['note'] ) ) {
		$order->set_customer_note( $a['note'] );
	}
	$order->calculate_totals();
	$order->set_date_created( (int) $a['ts'] );
	$order->set_status( $a['status'] );
	$order->save();
	if ( ! empty( $a['modified'] ) ) {
		dox_pos_demo_set_modified( $order->get_id(), (int) $a['modified'] );
	}
	return $order;
}

/**
 * La fecha de modificación hacia atrás (WooCommerce siempre pone "ahora" al guardar): para
 * que un "enviado hace ocho días" cuente como tal.
 */
function dox_pos_demo_set_modified( $order_id, $ts ) {
	global $wpdb;
	$gmt = gmdate( 'Y-m-d H:i:s', $ts );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders' ) ) ) {
		$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $gmt ), array( 'id' => (int) $order_id ), array( '%s' ), array( '%d' ) );
	}
	if ( 'shop_order' === get_post_type( $order_id ) ) {
		$wpdb->update( $wpdb->posts, array( 'post_modified' => get_date_from_gmt( $gmt ), 'post_modified_gmt' => $gmt ), array( 'ID' => (int) $order_id ), array( '%s', '%s' ), array( '%d' ) );
		clean_post_cache( $order_id );
	}
	wp_cache_delete( 'order-' . (int) $order_id, 'orders' );
	wp_cache_delete( (int) $order_id, 'orders' );
}

/**
 * Crea los datos de demostración: unas cuatro semanas de ventas por todos los canales y
 * formas de pago, y una docena de pedidos abiertos con lo que el asistente sabe detectar.
 *
 * @return array|WP_Error orders, sales, open.
 */
function dox_pos_demo_create() {
	if ( dox_pos_demo_active() ) {
		return new WP_Error( 'dox_pos_demo_ya', __( 'Ya hay datos de demostración. Quítalos antes de crear otros.', 'dox-pos' ) );
	}
	if ( ! function_exists( 'wc_create_order' ) ) {
		return new WP_Error( 'dox_pos_sin_woo', __( 'WooCommerce no está activo.', 'dox-pos' ) );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	wp_raise_memory_limit( 'admin' );
	// Lo que se puede "vender": tallas activas con existencias, o simples con existencias; con foto y precio.
	$pool = array();
	foreach ( dox_pos_ai_catalog( true ) as $p ) {
		if ( 'publish' !== $p['status'] || ! $p['thumb'] || null === $p['total'] || $p['total'] <= 0 ) {
			continue;
		}
		if ( $p['vars'] ) {
			foreach ( $p['vars'] as $v ) {
				if ( 'publish' === $v['status'] && (int) $v['stock'] > 0 && (float) $v['price'] > 0 ) {
					$pool[] = array( 'id' => $v['id'], 'pid' => $p['id'], 'stock' => (int) $v['stock'], 'price' => (float) $v['price'] );
				}
			}
		} elseif ( (float) $p['price'] > 0 ) {
			$pool[] = array( 'id' => $p['id'], 'pid' => $p['id'], 'stock' => (int) $p['total'], 'price' => (float) $p['price'] );
		}
	}
	if ( count( $pool ) < 10 ) {
		return new WP_Error( 'dox_pos_demo_pocos', __( 'Hacen falta al menos diez productos publicados con foto, precio y existencias.', 'dox-pos' ) );
	}
	mt_srand( 20260905 ); // Siempre el mismo juego: se puede repetir la demostración igual.
	shuffle( $pool );
	$popular = array_slice( $pool, 0, 30 );
	$low     = array_slice( array_values( array_filter( $pool, fn( $x ) => $x['stock'] <= 2 ) ), 0, 4 ); // Lo que se agota.
	$pick    = function ( $n ) use ( $popular, $low ) {
		$items = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$src     = $low && 0 === mt_rand( 0, 3 ) ? $low : $popular;
			$it      = $src[ mt_rand( 0, count( $src ) - 1 ) ];
			$items[] = array( $it, 1 );
		}
		return $items;
	};
	$people   = dox_pos_demo_people();
	$person   = fn( $i ) => $people[ $i % count( $people ) ];
	$user     = wp_get_current_user();
	$seller   = array( 'id' => (int) $user->ID, 'name' => $user->display_name ? $user->display_name : $user->user_login );
	$channels = array_values( array_map( fn( $c ) => $c['name'], dox_pos_channels() ) );
	$pickup   = '';
	foreach ( dox_pos_channels() as $c ) {
		if ( ! empty( $c['pickup'] ) ) {
			$pickup = $c['name'];
		}
	}
	$pays = array_keys( dox_pos_payment_methods() );
	$pay  = function () use ( $pays ) {
		$w = array( 'efectivo' => 35, 'transferencia' => 25, 'nequi' => 20, 'contraentrega' => 15, 'tarjeta' => 5 );
		$c = array();
		foreach ( $pays as $k ) {
			$c[ $k ] = $w[ $k ] ?? 5;
		}
		$r = mt_rand( 1, max( 1, array_sum( $c ) ) );
		foreach ( $c as $k => $n ) {
			$r -= $n;
			if ( $r <= 0 ) {
				return $k;
			}
		}
		return $pays[0];
	};
	// Sin correos ni cuenta de ventas mientras se crean: son de mentira.
	foreach ( array( 'new_order', 'cancelled_order', 'failed_order', 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice', 'low_stock', 'no_stock' ) as $mail ) {
		add_filter( 'woocommerce_email_enabled_' . $mail, '__return_false' );
	}
	foreach ( array( 'completed', 'processing', 'on-hold' ) as $st ) {
		remove_action( 'woocommerce_order_status_' . $st, 'wc_update_total_sales_counts' );
	}
	$now   = time();
	$made  = array();
	$sales = 0;
	$k     = 0;
	// Cuatro semanas de ventas: más los viernes y sábados, y algo hoy y ayer.
	for ( $d = 28; $d >= 0; $d-- ) {
		$day = $now - $d * DAY_IN_SECONDS;
		$wd  = (int) wp_date( 'N', $day );
		$n   = in_array( $wd, array( 5, 6 ), true ) ? mt_rand( 2, 4 ) : mt_rand( 0, 2 );
		if ( 0 === $d ) {
			$n = 2;
		} elseif ( 1 === $d ) {
			$n = 3;
		}
		for ( $i = 0; $i < $n; $i++ ) {
			$k++;
			$ts      = strtotime( wp_date( 'Y-m-d', $day ) . ' ' . mt_rand( 9, 19 ) . ':' . str_pad( (string) mt_rand( 0, 59 ), 2, '0', STR_PAD_LEFT ) . ':00 ' . wp_timezone_string() );
			$web     = 0 === mt_rand( 0, 8 );
			$channel = $web ? '' : $channels[ mt_rand( 0, count( $channels ) - 1 ) ];
			$inhand  = ! $web && '' !== $pickup && $channel === $pickup;
			$paykey  = $web ? 'web' : ( $inhand ? ( 0 === mt_rand( 0, 1 ) ? 'efectivo' : $pay() ) : $pay() );
			if ( $inhand && 'contraentrega' === $paykey ) {
				$paykey = 'efectivo';
			}
			$status  = 'completed';
			$track   = '';
			if ( ! $inhand && $d <= 5 && $d >= 2 && 0 === mt_rand( 0, 1 ) ) {
				$status = 'enviado';
				$track  = 'Servientrega ' . mt_rand( 100000000, 999999999 );
			} elseif ( ! $inhand && $d <= 1 ) {
				$status = 'processing';
			}
			$o = dox_pos_demo_order(
				array(
					'items'    => $pick( mt_rand( 1, 100 ) <= 65 ? 1 : 2 ),
					'person'   => $person( $k ),
					'channel'  => $channel,
					'payment'  => $paykey,
					'status'   => $status,
					'ts'       => $ts,
					'shipping' => $inhand ? 0 : 12000,
					'discount' => 0 === mt_rand( 0, 7 ) ? 10000 : 0,
					'tracking' => $track,
					'modified' => 'enviado' === $status ? $ts + 6 * HOUR_IN_SECONDS : 0,
					'seller'   => $seller,
				)
			);
			if ( $o ) {
				$made[] = $o;
				$sales++;
			}
		}
	}
	// Los pendientes: uno de cada cosa que el asistente sabe ver.
	$open = array(
		array( 'payment' => 'transferencia', 'status' => 'on-hold', 'ts' => $now - 3 * DAY_IN_SECONDS, 'hold_until' => $now - 26 * HOUR_IN_SECONDS, 'channel' => $channels[0] ),
		array( 'payment' => 'nequi', 'status' => 'on-hold', 'ts' => $now - 45 * HOUR_IN_SECONDS, 'hold_until' => $now + 3 * HOUR_IN_SECONDS, 'channel' => $channels[0] ),
		array( 'payment' => 'transferencia', 'status' => 'processing', 'ts' => $now - 4 * DAY_IN_SECONDS - 3 * HOUR_IN_SECONDS, 'channel' => $channels[0], 'shipping' => 12000 ),
		array( 'payment' => 'nequi', 'status' => 'processing', 'ts' => $now - 26 * HOUR_IN_SECONDS, 'channel' => $channels[ min( 1, count( $channels ) - 1 ) ], 'phone' => '', 'shipping' => 12000 ),
		array( 'payment' => 'contraentrega', 'status' => 'enviado', 'ts' => $now - 9 * DAY_IN_SECONDS, 'modified' => $now - 8 * DAY_IN_SECONDS, 'tracking' => 'Coordinadora 74110022', 'channel' => $channels[0], 'shipping' => 12000 ),
		array( 'payment' => 'transferencia', 'status' => 'enviado', 'ts' => $now - 8 * DAY_IN_SECONDS, 'modified' => $now - 7 * DAY_IN_SECONDS, 'tracking' => 'Servientrega 998877665', 'channel' => $channels[0], 'shipping' => 12000 ),
		array( 'payment' => 'nequi', 'status' => 'enviado', 'ts' => $now - 2 * DAY_IN_SECONDS, 'modified' => $now - DAY_IN_SECONDS, 'tracking' => '', 'channel' => $channels[0], 'shipping' => 12000 ),
		array( 'payment' => 'web', 'status' => 'pending', 'ts' => $now - 30 * HOUR_IN_SECONDS, 'shipping' => 12000 ),
		array( 'payment' => 'web', 'status' => 'on-hold', 'ts' => $now - 28 * HOUR_IN_SECONDS, 'shipping' => 12000 ),
		array( 'payment' => 'web', 'status' => 'failed', 'ts' => $now - 5 * HOUR_IN_SECONDS, 'shipping' => 12000 ),
		array( 'payment' => 'efectivo', 'status' => 'processing', 'ts' => $now - 2 * HOUR_IN_SECONDS, 'channel' => $channels[0], 'shipping' => 12000, 'repeat' => true ),
		array( 'payment' => 'efectivo', 'status' => 'processing', 'ts' => $now - HOUR_IN_SECONDS, 'channel' => $channels[0], 'shipping' => 12000, 'repeat' => true ),
	);
	$rep_items = $pick( 1 );
	$rep_who   = $person( 40 );
	foreach ( $open as $i => $a ) {
		$k++;
		$a['items']  = ! empty( $a['repeat'] ) ? $rep_items : $pick( 1 );
		$a['person'] = ! empty( $a['repeat'] ) ? $rep_who : $person( $k );
		$a['seller'] = $seller;
		$o = dox_pos_demo_order( $a );
		if ( $o ) {
			$made[] = $o;
		}
	}
	foreach ( array( 'completed', 'processing', 'on-hold' ) as $st ) {
		add_action( 'woocommerce_order_status_' . $st, 'wc_update_total_sales_counts' );
	}
	dox_pos_demo_kardex( $made, $seller );
	update_option( 'dox_pos_demo', array( 'at' => $now, 'orders' => count( $made ), 'sales' => $sales, 'user' => $seller['name'] ), false );
	dox_pos_demo_refresh();
	return array( 'orders' => count( $made ), 'sales' => $sales, 'open' => count( $made ) - $sales );
}

/**
 * Las filas de kardex de las ventas de ejemplo, contadas hacia atrás desde las existencias
 * de hoy (que no cambiaron): la última venta deja lo que hay ahora, la anterior una más, y así.
 *
 * @param WC_Order[] $orders Los pedidos creados.
 * @param array      $seller id, name.
 */
function dox_pos_demo_kardex( $orders, $seller ) {
	global $wpdb;
	$by = array();
	foreach ( $orders as $o ) {
		if ( ! $o->has_status( array( 'processing', 'enviado', 'completed' ) ) ) {
			continue;
		}
		foreach ( $o->get_items() as $it ) {
			$id = $it->get_variation_id() ? $it->get_variation_id() : $it->get_product_id();
			if ( ! $id ) {
				continue;
			}
			$by[ $id ][] = array( 'ts' => $o->get_date_created() ? $o->get_date_created()->getTimestamp() : time(), 'qty' => (int) $it->get_quantity(), 'order' => $o->get_id(), 'web' => 'checkout' === $o->get_created_via(), 'name' => $it->get_name(), 'pid' => (int) $it->get_product_id() );
		}
	}
	foreach ( $by as $id => $rows ) {
		$p = wc_get_product( $id );
		if ( ! $p || ! $p->managing_stock() ) {
			continue;
		}
		usort( $rows, fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
		$after = (int) $p->get_stock_quantity();
		foreach ( $rows as $r ) {
			$before = $after + $r['qty'];
			$wpdb->insert(
				dox_pos_stock_log_table(),
				array(
					'created_at'   => wp_date( 'Y-m-d H:i:s', $r['ts'] ),
					'product_id'   => $r['pid'],
					'variation_id' => $id === $r['pid'] ? 0 : $id,
					'sku'          => (string) $p->get_sku( 'edit' ),
					'name'         => $r['name'],
					'qty_before'   => $before,
					'qty_after'    => $after,
					'delta'        => -$r['qty'],
					'reason'       => $r['web'] ? 'web' : 'sale',
					'ref_id'       => $r['order'],
					'user_id'      => $r['web'] ? 0 : $seller['id'],
					'user_name'    => $r['web'] ? '' : $seller['name'],
					'note'         => 'Demo',
				),
				array( '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
			);
			$after = $before;
		}
	}
}

/**
 * Quita los datos de demostración: borra del todo los pedidos marcados (si alguno llegó a
 * contarse como venta de WooCommerce, se descuenta) y sus filas de kardex. Las existencias
 * no cambiaron nunca, así que no hay nada que devolver.
 *
 * @return array orders.
 */
function dox_pos_demo_remove() {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	global $wpdb;
	$n = 0;
	if ( function_exists( 'wc_get_orders' ) ) {
		$ids = wc_get_orders(
			array(
				'limit'      => -1,
				'type'       => 'shop_order',
				'return'     => 'ids',
				'status'     => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ),
				'meta_query' => array( array( 'key' => '_dox_pos_demo', 'value' => '1' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		foreach ( (array) $ids as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o instanceof WC_Order ) {
				continue;
			}
			if ( 'yes' === $o->get_meta( '_recorded_sales' ) ) {
				$store = WC_Data_Store::load( 'product' );
				foreach ( $o->get_items() as $it ) {
					if ( $it->get_product_id() ) {
						$store->update_product_sales( $it->get_product_id(), absint( $it->get_quantity() ), 'decrease' );
					}
				}
			}
			$o->delete( true );
			$n++;
		}
	}
	$wpdb->delete( dox_pos_stock_log_table(), array( 'note' => 'Demo' ), array( '%s' ) );
	delete_option( 'dox_pos_demo' );
	dox_pos_demo_refresh();
	return array( 'orders' => $n );
}

/**
 * Lo que el asistente tenía calculado ya no vale: se olvidan las cachés y el resumen de hoy
 * (si ya salió, la caja pide otro al abrirse, con los números de ahora).
 */
function dox_pos_demo_refresh() {
	if ( function_exists( 'dox_pos_ai_forget' ) ) {
		dox_pos_ai_forget();
	}
	$last = get_option( 'dox_pos_ai_summary', array() );
	if ( is_array( $last ) && ( $last['date'] ?? '' ) === wp_date( 'Y-m-d' ) ) {
		delete_option( 'dox_pos_ai_summary' );
	}
}

/* =====================================================================
 * Las rutas: estado, crear y quitar (solo gerentes y administradores)
 * ===================================================================== */

add_action( 'rest_api_init', 'dox_pos_register_demo_routes' );
function dox_pos_register_demo_routes() {
	$ns = 'dox-pos/v1';
	register_rest_route( $ns, '/demo', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_demo_state', 'permission_callback' => 'dox_pos_rest_settings_permission' ) );
	register_rest_route( $ns, '/demo/on', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_demo_on', 'permission_callback' => 'dox_pos_rest_settings_permission' ) );
	register_rest_route( $ns, '/demo/off', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_demo_off', 'permission_callback' => 'dox_pos_rest_settings_permission' ) );
}

function dox_pos_demo_status_text() {
	$s = dox_pos_demo_state();
	if ( ! $s ) {
		return __( 'No hay datos de demostración.', 'dox-pos' );
	}
	/* translators: 1: fecha, 2: pedidos, 3: quién */
	return sprintf( __( 'Activos desde el %1$s: %2$d pedidos de ejemplo (los creó %3$s). En la caja sale el aviso.', 'dox-pos' ), wp_date( 'j \d\e F \a \l\a\s G:i', (int) $s['at'] ), (int) $s['orders'], (string) $s['user'] );
}

function dox_pos_rest_demo_state() {
	return rest_ensure_response( array( 'active' => dox_pos_demo_active(), 'message' => dox_pos_demo_status_text() ) );
}

function dox_pos_rest_demo_on() {
	$r = dox_pos_demo_create();
	if ( is_wp_error( $r ) ) {
		return dox_pos_rest_out( $r );
	}
	/* translators: 1: pedidos, 2: ventas, 3: abiertos */
	return rest_ensure_response( array( 'active' => true, 'message' => sprintf( __( 'Listo: %1$d pedidos de ejemplo (%2$d ventas de las últimas cuatro semanas y %3$d abiertos con algo por hacer). Abre la caja y mira Hoy, Pedidos e Historial.', 'dox-pos' ), $r['orders'], $r['sales'], $r['open'] ), 'status' => dox_pos_demo_status_text() ) );
}

function dox_pos_rest_demo_off() {
	$r = dox_pos_demo_remove();
	/* translators: %d: pedidos */
	return rest_ensure_response( array( 'active' => false, 'message' => sprintf( __( 'Quitados: %d pedidos de ejemplo. Las existencias no se tocaron.', 'dox-pos' ), $r['orders'] ), 'status' => dox_pos_demo_status_text() ) );
}
