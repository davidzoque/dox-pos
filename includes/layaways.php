<?php
/**
 * Los apartados: un pedido en espera que reserva el producto hasta una fecha (o sin fecha), al que se le
 * pueden ir haciendo abonos hasta pagarlo entero.
 *
 * La marca: _dox_pos_layaway = 1 en todo apartado (no se borra al pagarlo, a diferencia de la fecha).
 * El plazo: _dox_pos_hold_until, la hora en que vence, o 0 si no vence.
 *
 * Los abonos usan el saldo a favor (credit.php), que ya resuelve que el dinero no cuente dos veces:
 * cada abono es un pedido de abono pagado con su forma de pago (cuenta como venta el día que entra y
 * suma al saldo de la clienta), ligado al apartado con _dox_pos_layaway_of, y el apartado lo usa en el
 * acto como "pagado con saldo": una línea negativa que rebaja su total. Así el total del apartado es
 * siempre lo que falta, el enlace de pago cobra eso, "Por cobrar" suma eso y, al cobrar el resto, el
 * pedido solo cuenta el dinero nuevo. Si se cancela, lo usado vuelve al saldo de la clienta (credit.php)
 * o, si la tienda lo pide, se le devuelve el dinero de los abonos con un reembolso de esos pedidos.
 *
 * Los informes cuentan un apartado el día que se paga, no el día que se apartó (history.php).
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =====================================================================
 * Qué es y cómo va un apartado
 * ===================================================================== */

/**
 * ¿Es un apartado? Los nuevos llevan la marca; los de antes de la 0.51.0, solo la fecha mientras siguen apartados.
 *
 * @param WC_Order $order El pedido.
 * @return bool
 */
function dox_pos_is_layaway( $order ) {
	if ( ! $order instanceof WC_Order || DOX_POS_VIA !== $order->get_created_via() ) {
		return false;
	}
	return (bool) $order->get_meta( '_dox_pos_layaway' ) || '' !== (string) $order->get_meta( '_dox_pos_hold_until' );
}

/**
 * ¿Sigue apartado (sin pagar ni cancelar)?
 */
function dox_pos_layaway_open( $order ) {
	return dox_pos_is_layaway( $order ) && $order->has_status( array( 'on-hold', 'pending', 'failed' ) );
}

/**
 * Los abonos hechos sobre el apartado, del más viejo al más nuevo: [{amount, date, method, user, order, number}].
 * Lo pagado con el saldo que la clienta ya tenía al apartar va también, sin pedido de abono.
 *
 * @param WC_Order $order El apartado.
 * @return array
 */
function dox_pos_layaway_deposits( $order ) {
	$out = array();
	/* translators: PHP date format: https://wordpress.org/documentation/article/customize-date-and-time-format/ */
	$fmt = _x( 'M j, G:i', 'short date and time format', 'dox-pos' );
	foreach ( $order->get_fees() as $fee ) {
		if ( 'credit' !== dox_pos_fee_type( $fee ) ) {
			continue;
		}
		$dep  = (int) $fee->get_meta( '_dox_pos_dep_order' );
		$when = (int) $fee->get_meta( '_dox_pos_dep_date' );
		if ( ! $when && $order->get_date_created() ) {
			$when = $order->get_date_created()->getTimestamp();
		}
		$num = '';
		if ( $dep ) {
			$d   = wc_get_order( $dep );
			$num = $d ? $d->get_order_number() : '';
		}
		$out[] = array(
			'amount' => - (float) $fee->get_total(),
			'date'   => $when ? wp_date( $fmt, $when ) : '',
			'ts'     => $when,
			'method' => $dep ? (string) $fee->get_meta( '_dox_pos_dep_method' ) : __( 'Store credit', 'dox-pos' ),
			'user'   => (string) $fee->get_meta( '_dox_pos_dep_user' ),
			'order'  => $dep,
			'number' => $num,
		);
	}
	usort( $out, fn( $a, $b ) => $a['ts'] <=> $b['ts'] );
	return $out;
}

/**
 * Todo lo de un apartado de una vez, para la caja, el Panel y el Pro.
 *
 * @param WC_Order $order El pedido.
 * @return array|null null si no es un apartado.
 */
function dox_pos_layaway_info( $order ) {
	if ( ! dox_pos_is_layaway( $order ) ) {
		return null;
	}
	$dec   = wc_get_price_decimals();
	$paid  = round( dox_pos_order_credit_used( $order ), $dec );
	$total = (float) $order->get_total();
	$open  = $order->has_status( array( 'on-hold', 'pending', 'failed' ) );
	$until = (int) $order->get_meta( '_dox_pos_hold_until' );
	$never = $open && 0 === $until && (bool) $order->get_meta( '_dox_pos_layaway' );
	/* translators: PHP date format: https://wordpress.org/documentation/article/customize-date-and-time-format/ */
	$fmt = _x( 'F j, G:i', 'date and time format', 'dox-pos' );
	return array(
		'open'       => $open,
		'value'      => round( $total + $paid, $dec ),           // Lo que vale: productos, envío y descuento.
		'deposited'  => $paid,                                   // Lo abonado (y el saldo que ya tenía, si lo usó).
		'remaining'  => $open ? round( $total, $dec ) : 0.0,     // Lo que falta: el total del pedido.
		'until'      => $open ? $until : 0,
		'due'        => $open && $until ? wp_date( $fmt, $until ) : '',
		'never'      => $never,
		'expired'    => $open && $until && $until <= time(),
		'hours_left' => $open && $until ? max( 0, (int) ceil( ( $until - time() ) / HOUR_IN_SECONDS ) ) : 0,
		'deposits'   => dox_pos_layaway_deposits( $order ),
	);
}

/**
 * Lo de un apartado para la lista de pedidos: sin la lista de abonos.
 */
function dox_pos_layaway_brief( $order ) {
	$i = dox_pos_layaway_info( $order );
	if ( ! $i ) {
		return null;
	}
	unset( $i['deposits'] );
	return $i;
}

/* =====================================================================
 * El plazo
 * ===================================================================== */

/**
 * El plazo de fábrica en horas (Ajustes > Apartados), o 0 si los apartados no vencen.
 */
function dox_pos_hold_default_hours() {
	return get_option( 'dox_pos_hold_never' ) ? 0 : dox_pos_hold_hours();
}

/**
 * El plazo que llega de la caja, en horas: 0 es "Sin vencer". Sin dato, el de Ajustes; como mucho, un año.
 *
 * @param mixed $v Lo que mandó la caja (horas, o vacío).
 * @return int
 */
function dox_pos_hold_hours_from( $v ) {
	if ( null === $v || '' === $v ) {
		return dox_pos_hold_default_hours();
	}
	return max( 0, min( 8784, (int) $v ) );
}

/**
 * Pone el plazo de un apartado y programa (o quita) su vencimiento.
 *
 * @param WC_Order $order El apartado.
 * @param int      $hours Horas desde ahora; 0 es "Sin vencer".
 */
function dox_pos_layaway_set_deadline( $order, $hours ) {
	$until = $hours > 0 ? time() + $hours * HOUR_IN_SECONDS : 0;
	$order->update_meta_data( '_dox_pos_layaway', 1 );
	$order->update_meta_data( '_dox_pos_hold_until', $until );
	$order->delete_meta_data( '_dox_pos_hold_expired_noted' );
	$order->save_meta_data();
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'dox_pos_release_hold', array( 'order_id' => $order->get_id() ), 'dox-pos' );
	}
	if ( $hours > 0 ) {
		dox_pos_schedule_release( $order->get_id(), $hours );
	}
}

/**
 * La frase del plazo para las notas del pedido.
 */
function dox_pos_deadline_phrase( $hours ) {
	if ( $hours <= 0 ) {
		return __( 'It has no deadline.', 'dox-pos' );
	}
	/* translators: PHP date format: https://wordpress.org/documentation/article/customize-date-and-time-format/ */
	$fmt = _x( 'F j, G:i', 'date and time format', 'dox-pos' );
	/* translators: %s: date and time */
	return sprintf( __( 'It expires on %s.', 'dox-pos' ), wp_date( $fmt, time() + $hours * HOUR_IN_SECONDS ) );
}

/**
 * Cambia el plazo de un apartado desde el detalle del pedido: alargar, acortar o dejarlo sin vencer.
 *
 * @param WC_Order $order El apartado.
 * @param mixed    $hours Horas desde ahora; 0 es "Sin vencer".
 * @return true|WP_Error
 */
function dox_pos_layaway_change_deadline( $order, $hours ) {
	if ( ! dox_pos_layaway_open( $order ) ) {
		return new WP_Error( 'dox_pos_estado', __( 'That order is no longer on layaway.', 'dox-pos' ) );
	}
	$hours = dox_pos_hold_hours_from( $hours );
	dox_pos_layaway_set_deadline( $order, $hours );
	/* translators: 1: who changed it, 2: "It expires on ..." or "It has no deadline." */
	$order->add_order_note( sprintf( __( 'Layaway deadline changed by %1$s. %2$s', 'dox-pos' ), wp_get_current_user()->display_name, dox_pos_deadline_phrase( $hours ) ) );
	return true;
}

/* =====================================================================
 * Los abonos
 * ===================================================================== */

/**
 * Un abono a un apartado: entra el dinero (un pedido de abono, con su forma de pago, que cuenta como
 * venta hoy) y el apartado lo usa en el acto, así que su total baja a lo que falta. Si con eso queda
 * pagado entero, se da por pagado.
 *
 * @param int    $order_id El apartado.
 * @param mixed  $amount   Cuánto.
 * @param string $payment  La forma de pago (una de dox_pos_payment_methods que quede pagada).
 * @param string $ref      Para no apuntarlo dos veces si la caja reintenta (opcional).
 * @return array|WP_Error {order: el apartado formateado, deposit: el pedido de abono formateado}.
 */
function dox_pos_layaway_add_deposit( $order_id, $amount, $payment, $ref = '' ) {
	$order = wc_get_order( (int) $order_id );
	if ( ! dox_pos_layaway_open( $order ) ) {
		return new WP_Error( 'dox_pos_estado', __( 'That order is no longer on layaway.', 'dox-pos' ) );
	}
	$key = dox_pos_credit_key( $order->get_billing_phone() );
	if ( '' === $key ) {
		return new WP_Error( 'dox_pos_telefono', __( 'A deposit on a layaway needs the customer’s phone number.', 'dox-pos' ) );
	}
	if ( ! dox_pos_credit_lock( $key ) ) {
		return new WP_Error( 'dox_pos_ocupado', __( 'That balance is being used on another device. Try again in a moment.', 'dox-pos' ) );
	}
	try {
		return dox_pos_layaway_add_deposit_locked( (int) $order_id, $amount, $payment, $ref, $key );
	} finally {
		dox_pos_credit_unlock( $key );
	}
}

/**
 * El abono, ya con la clienta bloqueada (ver dox_pos_layaway_add_deposit).
 */
function dox_pos_layaway_add_deposit_locked( $order_id, $amount, $payment, $ref, $key ) {
	$order = wc_get_order( $order_id ); // Otra vez, ya con el candado: pudo cambiar mientras se esperaba.
	if ( ! dox_pos_layaway_open( $order ) ) {
		return new WP_Error( 'dox_pos_estado', __( 'That order is no longer on layaway.', 'dox-pos' ) );
	}
	$dec    = wc_get_price_decimals();
	$amount = dox_pos_parse_money( $amount );
	$amount = null === $amount ? 0.0 : round( $amount, $dec );
	$left   = round( (float) $order->get_total(), $dec );
	if ( $amount <= 0 ) {
		return new WP_Error( 'dox_pos_abono_monto', __( 'Write how much the deposit is.', 'dox-pos' ) );
	}
	if ( $amount > $left + 0.00001 ) {
		/* translators: %s: amount left to pay */
		return new WP_Error( 'dox_pos_abono_monto', sprintf( __( 'The deposit cannot be more than what is left to pay (%s).', 'dox-pos' ), dox_pos_money( $left ) ) );
	}
	$methods = dox_pos_payment_methods();
	$pay_key = sanitize_key( (string) $payment );
	if ( empty( $methods[ $pay_key ]['paid'] ) ) {
		return new WP_Error( 'dox_pos_abono', __( 'A deposit is money that comes in now: choose the payment method it came in.', 'dox-pos' ) );
	}
	// El reintento de la caja sin señal: si ese abono ya entró, no se apunta otra vez.
	$ref = sanitize_text_field( (string) $ref );
	if ( '' !== $ref ) {
		foreach ( $order->get_fees() as $fee ) {
			if ( $ref === (string) $fee->get_meta( '_dox_pos_dep_ref' ) ) {
				return array( 'order' => dox_pos_format_order( $order ), 'deposit' => null );
			}
		}
	}

	// El dinero que entra: un pedido de abono, como los del saldo a favor.
	$num  = $order->get_order_number();
	$data = array(
		/* translators: %s: layaway order number */
		'deposit'  => array( 'name' => sprintf( __( 'Deposit on layaway #%s', 'dox-pos' ), $num ), 'amount' => $amount ),
		'channel'  => (string) $order->get_meta( '_dox_pos_channel' ),
		'payment'  => $pay_key,
		'customer' => array(
			'name'     => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'phone'    => $order->get_billing_phone(),
			'state'    => $order->get_billing_state(),
			'city'     => $order->get_billing_city(),
			'address'  => $order->get_billing_address_1(),
			'postcode' => $order->get_billing_postcode(),
		),
		'ref'      => '' !== $ref ? $ref . '-dep' : '',
	);
	$made = dox_pos_create_order_locked( $data, false );
	if ( is_wp_error( $made ) ) {
		return $made;
	}
	$dep = wc_get_order( (int) $made['order']['id'] );
	if ( ! $dep instanceof WC_Order ) {
		return new WP_Error( 'dox_pos_abono', __( 'The deposit could not be recorded. Nothing was changed.', 'dox-pos' ) );
	}
	$dep->update_meta_data( '_dox_pos_layaway_of', $order->get_id() );
	$dep->save_meta_data();

	// El apartado lo usa en el acto: una línea negativa, como pagar con saldo, que rebaja su total.
	$order = wc_get_order( $order->get_id() );
	$who   = wp_get_current_user()->display_name;
	$fee   = new WC_Order_Item_Fee();
	/* translators: %s: payment method */
	$fee->set_name( sprintf( __( 'Deposit (%s)', 'dox-pos' ), $methods[ $pay_key ]['title'] ) );
	$fee->set_amount( -$amount );
	$fee->set_total( -$amount );
	$fee->set_tax_status( 'none' );
	$fee->add_meta_data( '_dox_pos_fee', 'credit', true );
	$fee->add_meta_data( '_dox_pos_dep_order', $dep->get_id(), true );
	$fee->add_meta_data( '_dox_pos_dep_date', time(), true );
	$fee->add_meta_data( '_dox_pos_dep_method', $methods[ $pay_key ]['title'], true );
	$fee->add_meta_data( '_dox_pos_dep_user', $who, true );
	if ( '' !== $ref ) {
		$fee->add_meta_data( '_dox_pos_dep_ref', $ref, true );
	}
	$order->add_item( $fee );
	$order->set_total( round( (float) $order->get_total() - $amount, $dec ) );
	$order->update_meta_data( '_dox_pos_credit_key', $key );
	$order->update_meta_data( '_dox_pos_credit_posted', 1 ); // Lo que se use de aquí en adelante vuelve al saldo si se cancela.
	$order->save();
	/* translators: %s: layaway order number */
	dox_pos_credit_insert( $key, trim( $order->get_formatted_billing_full_name() ), -$amount, 'use', $order->get_id(), sprintf( __( 'Deposit on layaway #%s', 'dox-pos' ), $num ) );
	$left = round( (float) $order->get_total(), $dec );
	/* translators: 1: amount, 2: payment method, 3: who, 4: deposited so far, 5: left to pay */
	$order->add_order_note( sprintf( __( 'Deposit of %1$s (%2$s) recorded by %3$s. Deposited so far: %4$s. Left to pay: %5$s.', 'dox-pos' ), dox_pos_money( $amount ), $methods[ $pay_key ]['title'], $who, dox_pos_money( dox_pos_order_credit_used( $order ) ), dox_pos_money( $left ) ) );

	// Con este abono quedó pagado entero: se da por pagado (pasa a por enviar).
	if ( $left <= 0 ) {
		dox_pos_layaway_complete( $order, 'saldo' );
	}
	return array(
		'order'   => dox_pos_format_order( wc_get_order( $order->get_id() ) ),
		'deposit' => dox_pos_format_order( wc_get_order( $dep->get_id() ) ),
	);
}

/**
 * Da el apartado por pagado: lo que falta entró por la forma de pago elegida (o ya no falta nada).
 *
 * @param WC_Order $order   El apartado.
 * @param string   $payment La forma de pago del resto; 'saldo' si ya lo cubrieron los abonos.
 * @return true|WP_Error
 */
function dox_pos_layaway_complete( $order, $payment ) {
	$left = round( (float) $order->get_total(), wc_get_price_decimals() );
	if ( $left <= 0 ) {
		$order->set_payment_method( 'dox_pos_credit' );
		$order->set_payment_method_title( dox_pos_order_credit_used( $order ) > 0 ? __( 'Deposits', 'dox-pos' ) : __( 'Store credit', 'dox-pos' ) );
	} else {
		$methods = dox_pos_payment_methods();
		$key     = sanitize_key( (string) $payment );
		if ( '' !== $key ) {
			if ( empty( $methods[ $key ]['paid'] ) ) {
				return new WP_Error( 'dox_pos_pago', __( 'Choose how the rest was paid.', 'dox-pos' ) );
			}
			$order->set_payment_method( $methods[ $key ]['id'] );
			$order->set_payment_method_title( $methods[ $key ]['title'] );
		}
	}
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'dox_pos_release_hold', array( 'order_id' => $order->get_id() ), 'dox-pos' );
	}
	$order->update_meta_data( '_dox_pos_layaway', 1 );
	$order->delete_meta_data( '_dox_pos_hold_until' );
	$order->save();
	$who = wp_get_current_user()->display_name;
	if ( $left <= 0 ) {
		/* translators: %s: who */
		$order->add_order_note( sprintf( __( 'Layaway paid in full with its deposits. Marked by %s.', 'dox-pos' ), $who ) );
	} else {
		/* translators: 1: amount, 2: payment method, 3: who */
		$order->add_order_note( sprintf( __( 'Rest of the layaway paid: %1$s (%2$s). Confirmed by %3$s.', 'dox-pos' ), dox_pos_money( $left ), $order->get_payment_method_title(), $who ) );
	}
	$order->payment_complete();
	return true;
}

/**
 * Lo que se le devuelve en dinero a la clienta al cancelar un apartado: sus abonos (los pedidos de
 * abono, con un reembolso de WooCommerce que resta de las ventas el día que sale). Lo que pagó con el
 * saldo que ya tenía vuelve a su saldo, como siempre.
 *
 * Se llama después de cancelar el apartado (que ya devolvió todo al saldo). Cada pedido de abono
 * conserva su estado (como las devoluciones del Mostrador y las del saldo): si pasara a "reembolsado",
 * Caja del día dejaría de sumar su cobro y además restaría la devolución, dos veces. El abono se saca
 * del saldo aquí mismo, así que el saldo queda como estaba antes del apartado.
 *
 * @param WC_Order $order   El apartado, ya cancelado.
 * @param string   $payment Con qué se devuelve (una forma de pago de la tienda).
 * @return float Lo devuelto.
 */
function dox_pos_layaway_refund_deposits( $order, $payment ) {
	$methods = dox_pos_payment_methods();
	$key     = sanitize_key( (string) $payment );
	$title   = $methods[ $key ]['title'] ?? '';
	$back    = 0.0;
	// Al cliente no le sale el correo de "reembolsado" de WooCommerce (se lo dice la tienda) y el pedido de abono no cambia de estado.
	add_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );
	add_filter( 'woocommerce_order_fully_refunded_status', '__return_false' );
	foreach ( dox_pos_layaway_deposits( $order ) as $d ) {
		$dep = $d['order'] ? wc_get_order( $d['order'] ) : null;
		if ( ! $dep instanceof WC_Order ) {
			continue;
		}
		$amount = round( (float) $dep->get_total() - (float) $dep->get_total_refunded(), wc_get_price_decimals() );
		if ( $amount <= 0 ) {
			continue;
		}
		$refund = wc_create_refund(
			array(
				'order_id'       => $dep->get_id(),
				'amount'         => $amount,
				/* translators: %s: layaway order number */
				'reason'         => sprintf( __( 'Layaway #%s cancelled: deposit given back', 'dox-pos' ), $order->get_order_number() ),
				'refund_payment' => false, // El dinero lo da la tienda.
			)
		);
		if ( is_wp_error( $refund ) ) {
			continue;
		}
		$refund->update_meta_data( '_dox_pos_refund_method', '' !== $title ? $key : 'original' );
		$refund->save_meta_data();
		$back += $amount;
		// Ese abono sale del saldo: la plata se le devolvió.
		$dep = wc_get_order( $dep->get_id() );
		if ( ! $dep->get_meta( '_dox_pos_deposit_reversed' ) ) {
			/* translators: %s: layaway order number */
			dox_pos_credit_insert( (string) $dep->get_meta( '_dox_pos_credit_key' ), trim( $dep->get_formatted_billing_full_name() ), -$amount, 'reversal', $dep->get_id(), sprintf( __( 'Layaway #%s cancelled: deposit given back', 'dox-pos' ), $order->get_order_number() ) );
			$dep->update_meta_data( '_dox_pos_deposit_reversed', 1 );
			$dep->save_meta_data();
		}
	}
	remove_filter( 'woocommerce_email_enabled_customer_refunded_order', '__return_false' );
	remove_filter( 'woocommerce_order_fully_refunded_status', '__return_false' );
	if ( $back > 0 ) {
		/* translators: 1: amount, 2: payment method */
		$order->add_order_note( sprintf( __( 'Deposits given back to the customer: %1$s (%2$s).', 'dox-pos' ), dox_pos_money( $back ), '' !== $title ? $title : __( 'same payment method', 'dox-pos' ) ) );
	}
	return $back;
}

/* =====================================================================
 * Avisos
 * ===================================================================== */

/**
 * Los apartados que vencen pronto (en los próximos $days días) y los vencidos que siguen en pie
 * (los que tienen abonos no se cancelan solos), los que vencen antes primero.
 *
 * @param int $days Cuántos días adelante.
 * @return array
 */
function dox_pos_layaways_due( $days = 3 ) {
	$out    = array();
	$limit  = time() + $days * DAY_IN_SECONDS;
	$orders = wc_get_orders(
		array(
			'limit'       => 200,
			'type'        => 'shop_order',
			'created_via' => DOX_POS_VIA,
			'status'      => array( 'wc-on-hold', 'wc-pending', 'wc-failed' ),
		)
	);
	foreach ( $orders as $o ) {
		$i = dox_pos_layaway_info( $o );
		if ( ! $i || ! $i['until'] || $i['until'] > $limit ) {
			continue;
		}
		$out[] = array(
			'id'         => $o->get_id(),
			'number'     => $o->get_order_number(),
			'customer'   => trim( $o->get_formatted_billing_full_name() ),
			'phone'      => $o->get_billing_phone(),
			'value'      => $i['value'],
			'deposited'  => $i['deposited'],
			'remaining'  => $i['remaining'],
			'due'        => $i['due'],
			'until'      => $i['until'],
			'expired'    => $i['expired'],
			'hours_left' => $i['hours_left'],
			'whatsapp'   => dox_pos_whatsapp_url( $o->get_billing_phone(), dox_pos_hold_message( $o ) ),
		);
	}
	usort( $out, fn( $a, $b ) => $a['until'] <=> $b['until'] );
	return $out;
}
