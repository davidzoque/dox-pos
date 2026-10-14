<?php
/**
 * El saldo a favor de la clienta: plata suya que la tienda guarda para que la use después. Sale de
 * dos sitios, un abono (un diseño por encargo que se va pagando por partes) o un cambio (devuelve
 * prendas y lo que valen, menos el cargo por cambio de la tienda, le queda para otra compra), y se
 * gasta pagando con él una venta, entero o en parte.
 *
 * La regla de cuentas: una venta cuenta cuando entra la plata. El abono es un pedido pagado con su
 * forma de pago y suma ese día; pagar con saldo es una línea negativa del pedido ("Saldo a favor"),
 * así que el total de esa venta ya es solo lo que entró de nuevo y no se cuenta dos veces; y el
 * cambio no resta nada (la plata entró y no salió): las prendas vuelven al inventario con un
 * reembolso de WooCommerce por 0 y lo que valen pasa al saldo.
 *
 * La clienta se reconoce por el teléfono, con el indicativo del país (3001234567 y +57 300 123 4567
 * son la misma). No se crean usuarios de WordPress.
 *
 * Cada peso queda en una tabla de movimientos que no se borra: el saldo es su suma. Un error se
 * corrige con un ajuste (solo quien administra la tienda).
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =====================================================================
 * La tabla
 * ===================================================================== */

/**
 * La tabla de los movimientos del saldo. Como la de los turnos: se crea aparte con su propia versión,
 * para que aparezca también al subir el archivo sin pasar por la activación.
 *
 * kind: deposit (abono), return (cambio o devolución), use (pagó con saldo), restore (volvió el saldo
 * de un pedido anulado o devuelto), reversal (se anuló un abono), adjust (ajuste a mano).
 */
const DOX_POS_CREDIT_DB = '1';
add_action( 'init', 'dox_pos_credit_maybe_install', 21 );
function dox_pos_credit_maybe_install() {
	if ( get_option( 'dox_pos_credit_db' ) === DOX_POS_CREDIT_DB ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = $wpdb->prefix . 'dox_pos_credit';
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(32) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			amount decimal(19,4) NOT NULL DEFAULT 0,
			kind varchar(16) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			note varchar(255) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_name varchar(190) NOT NULL DEFAULT '',
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY phone (phone),
			KEY order_id (order_id),
			KEY created (created)
		) {$charset};"
	);
	update_option( 'dox_pos_credit_db', DOX_POS_CREDIT_DB, false );
}

function dox_pos_credit_table() {
	global $wpdb;
	return $wpdb->prefix . 'dox_pos_credit';
}

/* =====================================================================
 * La clienta: su teléfono
 * ===================================================================== */

/**
 * Un teléfono con el indicativo del país, solo cifras: 3001234567 pasa a 573001234567 en una tienda
 * de Colombia; +1 (305) 555-0100 queda 13055550100. Es la misma regla de los enlaces de WhatsApp.
 *
 * @param string $phone Como lo escribieron.
 * @return string
 */
function dox_pos_phone_international( $phone ) {
	$raw    = trim( (string) $phone );
	$digits = preg_replace( '/\D/', '', $raw );
	if ( 0 === strpos( $digits, '00' ) ) {
		return substr( $digits, 2 ); // 0057... es lo mismo que +57...
	}
	if ( 0 === strpos( $raw, '+' ) ) {
		return $digits;
	}
	$code = dox_pos_calling_code();
	if ( $code && strlen( $digits ) <= 10 && 0 !== strpos( $digits, $code ) ) {
		$digits = $code . ltrim( $digits, '0' ); // El 0 de marcar dentro del país (07700 900123 en el Reino Unido) no va con el indicativo.
	}
	return $digits;
}

/**
 * La clave de la clienta en el saldo: su teléfono internacional, o vacío si no parece un teléfono.
 *
 * @param string $phone Como lo escribieron.
 * @return string
 */
function dox_pos_credit_key( $phone ) {
	$d = dox_pos_phone_international( $phone );
	return strlen( $d ) >= 7 && strlen( $d ) <= 20 ? $d : '';
}

/**
 * Un teléfono para enseñar: con el +.
 */
function dox_pos_credit_phone_label( $key ) {
	return '' === (string) $key ? '' : '+' . $key;
}

/* =====================================================================
 * El saldo y los movimientos
 * ===================================================================== */

/**
 * El saldo de una clienta, redondeado a los decimales de la tienda.
 *
 * @param string $key La clave (dox_pos_credit_key).
 * @return float
 */
function dox_pos_credit_balance( $key ) {
	global $wpdb;
	if ( '' === (string) $key ) {
		return 0.0;
	}
	$sum = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(amount) FROM %i WHERE phone = %s', dox_pos_credit_table(), $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return round( (float) $sum, wc_get_price_decimals() );
}

/**
 * El nombre más reciente con que se apuntó una clienta.
 */
function dox_pos_credit_name( $key ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM %i WHERE phone = %s AND name <> '' ORDER BY id DESC LIMIT 1", dox_pos_credit_table(), $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Apunta un movimiento. Quien lo hace es el usuario de la sesión, o en el Mostrador quien atiende.
 *
 * @param string $key      La clienta.
 * @param string $name     Su nombre (puede ir vacío: se queda el último).
 * @param float  $amount   Con signo: positivo entra al saldo, negativo sale.
 * @param string $kind     deposit | return | use | restore | reversal | adjust.
 * @param int    $order_id El pedido, si lo hay.
 * @param string $note     El detalle.
 * @return int El id del movimiento (0 si no se pudo).
 */
function dox_pos_credit_insert( $key, $name, $amount, $kind, $order_id = 0, $note = '' ) {
	global $wpdb;
	$amount = round( (float) $amount, wc_get_price_decimals() );
	if ( '' === (string) $key || 0.0 === $amount ) {
		return 0;
	}
	$user = function_exists( 'dox_pos_counter_actor' ) ? dox_pos_counter_actor() : wp_get_current_user();
	$ok   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		dox_pos_credit_table(),
		array(
			'phone'     => (string) $key,
			'name'      => mb_substr( sanitize_text_field( (string) $name ), 0, 190 ),
			'amount'    => $amount,
			'kind'      => (string) $kind,
			'order_id'  => (int) $order_id,
			'note'      => mb_substr( sanitize_text_field( (string) $note ), 0, 255 ),
			'user_id'   => $user ? (int) $user->ID : 0,
			'user_name' => $user ? mb_substr( (string) $user->display_name, 0, 190 ) : '',
			'created'   => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%f', '%s', '%d', '%s', '%d', '%s', '%s' )
	);
	if ( $ok ) {
		do_action( 'dox_pos_credit_changed', (string) $key, $amount, (string) $kind, (int) $order_id );
	}
	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * Un candado por clienta mientras se mira su saldo y se gasta: dos cajas a la vez con la misma
 * clienta verían las dos el saldo entero y lo gastarían dos veces.
 *
 * @param string $key La clienta.
 * @return bool Si se consiguió.
 */
function dox_pos_credit_lock( $key ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', 'dox_pos_credit_' . $key ) );
}

function dox_pos_credit_unlock( $key ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'dox_pos_credit_' . $key ) );
}

/**
 * El texto de un tipo de movimiento.
 */
function dox_pos_credit_kind_label( $kind ) {
	$labels = array(
		'deposit'  => __( 'Deposit', 'dox-pos' ),
		'return'   => __( 'Return or exchange', 'dox-pos' ),
		'use'      => __( 'Used in a sale', 'dox-pos' ),
		'restore'  => __( 'Given back (order cancelled or returned)', 'dox-pos' ),
		'reversal' => __( 'Deposit cancelled', 'dox-pos' ),
		'adjust'   => __( 'Adjustment', 'dox-pos' ),
	);
	return $labels[ $kind ] ?? $kind;
}

/**
 * Lo que la caja necesita al escribir un teléfono: si tiene saldo y cómo se llama.
 *
 * @param string $phone Como lo escribieron.
 * @return array{key:string,phone:string,name:string,balance:float}
 */
function dox_pos_credit_lookup( $phone ) {
	$key = dox_pos_credit_key( $phone );
	return array(
		'key'     => $key,
		'phone'   => dox_pos_credit_phone_label( $key ),
		'name'    => '' !== $key ? dox_pos_credit_name( $key ) : '',
		'balance' => dox_pos_credit_balance( $key ),
	);
}

/**
 * Los movimientos de una clienta, del más reciente al más viejo, con el saldo que quedó después de cada uno.
 *
 * @param string $key La clienta.
 * @return array
 */
function dox_pos_credit_history( $key ) {
	global $wpdb;
	$rows = '' === (string) $key ? array() : $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE phone = %s ORDER BY id ASC', dox_pos_credit_table(), $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$out  = array();
	$run  = 0.0;
	$dec  = wc_get_price_decimals();
	/* translators: PHP date format: https://wordpress.org/documentation/article/customize-date-and-time-format/ */
	$fmt = _x( 'F j, Y G:i', 'date and time format with year', 'dox-pos' );
	foreach ( (array) $rows as $r ) {
		$run  += (float) $r->amount;
		$order = (int) $r->order_id ? wc_get_order( (int) $r->order_id ) : null;
		$out[] = array(
			'id'      => (int) $r->id,
			'date'    => wp_date( $fmt, strtotime( $r->created . ' UTC' ) ),
			'kind'    => (string) $r->kind,
			'label'   => dox_pos_credit_kind_label( $r->kind ),
			'amount'  => round( (float) $r->amount, $dec ),
			'balance' => round( $run, $dec ),
			'note'    => (string) $r->note,
			'order'   => $order ? (int) $order->get_id() : 0,
			'number'  => $order ? (string) $order->get_order_number() : '',
			'user'    => (string) $r->user_name,
		);
	}
	return array_reverse( $out );
}

/**
 * Las clientas con movimientos de saldo: las que tienen saldo primero, por cuánto, y luego las
 * demás por su último movimiento. Con $q, las que coinciden por nombre o teléfono. Y lo que la
 * tienda debe en total: la suma de los saldos a favor.
 *
 * @param string $q    Lo escrito.
 * @param int    $page Página (de 50).
 * @return array
 */
function dox_pos_credit_customers( $q = '', $page = 1 ) {
	global $wpdb;
	$table = dox_pos_credit_table();
	$per   = 50;
	$page  = max( 1, (int) $page );
	$where = '';
	$args  = array( $table );
	$q     = trim( (string) $q );
	if ( '' !== $q ) {
		$digits = preg_replace( '/\D/', '', $q );
		$like   = '%' . $wpdb->esc_like( $q ) . '%';
		if ( strlen( $digits ) >= 3 ) {
			$where  = ' WHERE ( phone LIKE %s OR phone IN ( SELECT phone FROM %i WHERE name LIKE %s ) )';
			$args[] = '%' . $wpdb->esc_like( $digits ) . '%';
		} else {
			$where  = ' WHERE phone IN ( SELECT phone FROM %i WHERE name LIKE %s )';
		}
		$args[] = $table;
		$args[] = $like;
	}
	$args[] = $per + 1;
	$args[] = ( $page - 1 ) * $per;
	$sql    = 'SELECT phone, SUM(amount) AS balance, MAX(id) AS last_id, MAX(created) AS last_at, COUNT(*) AS n FROM %i' . $where . ' GROUP BY phone ORDER BY ( SUM(amount) > 0 ) DESC, SUM(amount) DESC, MAX(id) DESC LIMIT %d OFFSET %d';
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	$more   = count( (array) $rows ) > $per;
	$rows   = array_slice( (array) $rows, 0, $per );
	$dec    = wc_get_price_decimals();
	$out    = array();
	/* translators: PHP date format: https://wordpress.org/documentation/article/customize-date-and-time-format/ */
	$fmt = _x( 'F j, Y', 'date format with year', 'dox-pos' );
	foreach ( $rows as $r ) {
		$out[] = array(
			'key'     => (string) $r->phone,
			'phone'   => dox_pos_credit_phone_label( $r->phone ),
			'name'    => dox_pos_credit_name( $r->phone ),
			'balance' => round( (float) $r->balance, $dec ),
			'last'    => wp_date( $fmt, strtotime( $r->last_at . ' UTC' ) ),
			'n'       => (int) $r->n,
		);
	}
	$owed = $wpdb->get_row( $wpdb->prepare( 'SELECT SUM(b) AS total, COUNT(*) AS n FROM ( SELECT SUM(amount) AS b FROM %i GROUP BY phone HAVING SUM(amount) > 0.00001 ) t', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array(
		'items' => $out,
		'more'  => $more,
		'page'  => $page,
		'owed'  => round( (float) ( $owed->total ?? 0 ), $dec ), // Lo que la tienda debe en saldo a favor.
		'owed_n' => (int) ( $owed->n ?? 0 ),
	);
}

/**
 * Un ajuste a mano, con su motivo: corrige un error o carga un saldo que se llevaba por fuera (en
 * una hoja de cálculo). Solo quien administra la tienda.
 *
 * @param string $phone  La clienta.
 * @param string $name   Su nombre.
 * @param mixed  $amount Con signo.
 * @param string $note   El motivo (obligatorio).
 * @return array|WP_Error El saldo nuevo y los movimientos.
 */
function dox_pos_credit_adjust( $phone, $name, $amount, $note ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return new WP_Error( 'dox_pos_permiso', __( 'Only administrators and shop managers can adjust a balance.', 'dox-pos' ) );
	}
	$key = dox_pos_credit_key( $phone );
	if ( '' === $key ) {
		return new WP_Error( 'dox_pos_telefono', __( 'Write the customer’s phone number.', 'dox-pos' ) );
	}
	$raw    = trim( (string) $amount );
	$neg    = 0 === strpos( $raw, '-' ) || 0 === strpos( $raw, '−' );
	$amount = dox_pos_parse_money( ltrim( $raw, '-−' ) );
	$amount = null === $amount ? 0.0 : round( abs( $amount ), wc_get_price_decimals() ) * ( $neg ? -1 : 1 );
	if ( 0.0 === $amount ) {
		return new WP_Error( 'dox_pos_importe', __( 'Write the amount.', 'dox-pos' ) );
	}
	$note = sanitize_text_field( (string) $note );
	if ( '' === $note ) {
		return new WP_Error( 'dox_pos_motivo', __( 'Write why: it stays in the history.', 'dox-pos' ) );
	}
	if ( ! dox_pos_credit_lock( $key ) ) {
		return new WP_Error( 'dox_pos_ocupado', __( 'That balance is being used on another device. Try again in a moment.', 'dox-pos' ) );
	}
	try {
		$balance = dox_pos_credit_balance( $key );
		if ( $amount < 0 && $balance + $amount < -0.00001 ) {
			/* translators: %s: balance */
			return new WP_Error( 'dox_pos_saldo', sprintf( __( 'The balance is %s: it cannot go below zero.', 'dox-pos' ), dox_pos_money( $balance ) ) );
		}
		dox_pos_credit_insert( $key, '' !== trim( (string) $name ) ? $name : dox_pos_credit_name( $key ), $amount, 'adjust', 0, $note );
	} finally {
		dox_pos_credit_unlock( $key );
	}
	return dox_pos_credit_customer( $key );
}

/**
 * Una clienta con su saldo y sus movimientos, para el detalle de la caja.
 */
function dox_pos_credit_customer( $key ) {
	return array(
		'key'       => (string) $key,
		'phone'     => dox_pos_credit_phone_label( $key ),
		'name'      => dox_pos_credit_name( $key ),
		'balance'   => dox_pos_credit_balance( $key ),
		'movements' => dox_pos_credit_history( $key ),
		'message'   => dox_pos_credit_message_url( $key ),
	);
}

/* =====================================================================
 * El pedido: lo que el saldo deja en él
 * ===================================================================== */

/**
 * Qué es un cargo del pedido: el descuento (negativo, como siempre), el pago con saldo (negativo,
 * con _dox_pos_fee = credit) o un abono (positivo, con _dox_pos_fee = deposit).
 *
 * @param WC_Order_Item_Fee $fee El cargo.
 * @return string discount | credit | deposit | other.
 */
function dox_pos_fee_type( $fee ) {
	$t = (string) $fee->get_meta( '_dox_pos_fee', true, 'edit' );
	if ( in_array( $t, array( 'credit', 'deposit' ), true ) ) {
		return $t;
	}
	return (float) $fee->get_total() < 0 ? 'discount' : 'other';
}

/**
 * El descuento del pedido: sus cargos negativos que no son pago con saldo.
 */
function dox_pos_order_discount( $order ) {
	$d = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( 'discount' === dox_pos_fee_type( $fee ) ) {
			$d += - (float) $fee->get_total();
		}
	}
	return $d;
}

/**
 * Lo que se pagó con saldo a favor en el pedido.
 */
function dox_pos_order_credit_used( $order ) {
	$d = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( 'credit' === dox_pos_fee_type( $fee ) ) {
			$d += - (float) $fee->get_total();
		}
	}
	return $d;
}

/**
 * Lo que el pedido lleva en abonos al saldo.
 */
function dox_pos_order_deposit( $order ) {
	$d = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( 'deposit' === dox_pos_fee_type( $fee ) ) {
			$d += (float) $fee->get_total();
		}
	}
	return $d;
}

/**
 * Los abonos del pedido, para la lista y el ticket: [{name, total}].
 */
function dox_pos_order_deposit_lines( $order ) {
	$out     = array();
	$layaway = (int) $order->get_meta( '_dox_pos_layaway_of' ) > 0; // "Abono al apartado #72": su nombre ya dice que es un abono.
	foreach ( $order->get_fees() as $fee ) {
		if ( 'deposit' === dox_pos_fee_type( $fee ) ) {
			$out[] = array( 'name' => $fee->get_name(), 'total' => (float) $fee->get_total(), 'layaway' => $layaway );
		}
	}
	return $out;
}

/**
 * Lo que la tienda devolvió en dinero de un pedido (no cuenta lo que pasó al saldo, que va con 0).
 */
function dox_pos_order_money_refunded( $order ) {
	return (float) $order->get_total_refunded();
}

/**
 * El abono que se le pone a un pedido: un cargo positivo, sin impuesto (no es la venta de nada:
 * el impuesto se cobra cuando se entrega lo que se paga con él).
 *
 * @param WC_Order $order  El pedido.
 * @param string   $name   La descripción ("Abono diseño X").
 * @param float    $amount Cuánto.
 */
function dox_pos_add_deposit_fee( $order, $name, $amount ) {
	$fee = new WC_Order_Item_Fee();
	$fee->set_name( '' !== $name ? $name : __( 'Deposit to store credit', 'dox-pos' ) );
	$fee->set_amount( $amount );
	$fee->set_total( $amount );
	$fee->set_tax_status( 'none' );
	$fee->add_meta_data( '_dox_pos_fee', 'deposit', true );
	$order->add_item( $fee );
}

/**
 * El pago con saldo: un cargo negativo, sin impuesto (el impuesto ya se calculó sobre el precio entero).
 */
function dox_pos_add_credit_fee( $order, $amount ) {
	$fee = new WC_Order_Item_Fee();
	$fee->set_name( __( 'Store credit', 'dox-pos' ) );
	$fee->set_amount( -$amount );
	$fee->set_total( -$amount );
	$fee->set_tax_status( 'none' );
	$fee->add_meta_data( '_dox_pos_fee', 'credit', true );
	$order->add_item( $fee );
}

/**
 * Si algo vuelve a calcular el pedido (WooCommerce, al editarlo), el pago con saldo sigue sin tocar el
 * impuesto: WooCommerce le pondría su parte negativa a cualquier cargo negativo, como a un descuento.
 *
 * @param WC_Order_Item_Fee $fee El cargo.
 */
add_action( 'woocommerce_order_item_fee_after_calculate_taxes', 'dox_pos_credit_fee_no_tax' );
function dox_pos_credit_fee_no_tax( $fee ) {
	if ( 'credit' === (string) $fee->get_meta( '_dox_pos_fee', true, 'edit' ) ) {
		$fee->set_taxes( false );
	}
}

/**
 * Apunta en el saldo lo que un pedido recién creado abona y gasta. Se llama cuando el pedido ya
 * quedó (stock reservado, cobro hecho): si algo falla antes, no queda nada a medias.
 *
 * @param WC_Order $order El pedido.
 */
function dox_pos_credit_post_order( $order ) {
	if ( $order->get_meta( '_dox_pos_credit_posted' ) ) {
		return;
	}
	$key  = (string) $order->get_meta( '_dox_pos_credit_key' );
	$name = trim( $order->get_formatted_billing_full_name() );
	$num  = $order->get_order_number();
	$used = dox_pos_order_credit_used( $order );
	$dep  = dox_pos_order_deposit( $order );
	if ( '' === $key || ( $used <= 0 && $dep <= 0 ) ) {
		return;
	}
	if ( $dep > 0 ) {
		$names = implode( ', ', wp_list_pluck( dox_pos_order_deposit_lines( $order ), 'name' ) );
		/* translators: 1: what the deposit is for, 2: order number */
		dox_pos_credit_insert( $key, $name, $dep, 'deposit', $order->get_id(), sprintf( __( '%1$s (order #%2$s)', 'dox-pos' ), $names, $num ) );
	}
	if ( $used > 0 ) {
		/* translators: %s: order number */
		dox_pos_credit_insert( $key, $name, -$used, 'use', $order->get_id(), sprintf( __( 'Paid part of order #%s', 'dox-pos' ), $num ) );
	}
	$order->update_meta_data( '_dox_pos_credit_posted', 1 );
	$order->save_meta_data();
}

/**
 * Lo que vuelve al saldo de lo que se pagó con él en un pedido: lo que ya volvió se lleva en
 * _dox_pos_credit_restored, para no devolverlo dos veces.
 *
 * @param WC_Order $order  El pedido.
 * @param float    $amount Cuánto (como mucho, lo que se pagó con saldo y no ha vuelto).
 * @param string   $note   El detalle.
 * @return float Lo que volvió.
 */
function dox_pos_credit_give_back( $order, $amount, $note ) {
	// El pedido tal como está guardado: otro paso de la misma petición (WooCommerce al darlo por
	// reembolsado entero) pudo devolver ya una parte, y una copia vieja la devolvería otra vez.
	$fresh = wc_get_order( $order->get_id() );
	$order = $fresh instanceof WC_Order ? $fresh : $order;
	$key  = (string) $order->get_meta( '_dox_pos_credit_key' );
	$left = round( dox_pos_order_credit_used( $order ) - (float) $order->get_meta( '_dox_pos_credit_restored' ), wc_get_price_decimals() );
	$amount = round( min( (float) $amount, $left ), wc_get_price_decimals() );
	if ( '' === $key || $amount <= 0 || ! $order->get_meta( '_dox_pos_credit_posted' ) ) {
		return 0.0;
	}
	dox_pos_credit_insert( $key, trim( $order->get_formatted_billing_full_name() ), $amount, 'restore', $order->get_id(), $note );
	$order->update_meta_data( '_dox_pos_credit_restored', wc_format_decimal( (float) $order->get_meta( '_dox_pos_credit_restored' ) + $amount, wc_get_price_decimals() ) );
	$order->save_meta_data();
	return $amount;
}

/**
 * Un pedido anulado o devuelto entero: el saldo que gastó vuelve, y el abono que dejó sale (una sola
 * vez cada cosa, aunque el pedido pase por los dos estados). Vale también si se anula en WooCommerce.
 */
add_action( 'woocommerce_order_status_cancelled', 'dox_pos_credit_on_cancel', 20 );
add_action( 'woocommerce_order_status_refunded', 'dox_pos_credit_on_cancel', 20 );
function dox_pos_credit_on_cancel( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order || ! $order->get_meta( '_dox_pos_credit_posted' ) ) {
		return;
	}
	$num  = $order->get_order_number();
	/* translators: %s: order number */
	$note = $order->has_status( 'refunded' ) ? sprintf( __( 'Order #%s was returned in full', 'dox-pos' ), $num ) : sprintf( __( 'Order #%s was cancelled', 'dox-pos' ), $num );
	dox_pos_credit_give_back( $order, PHP_FLOAT_MAX, $note );
	$dep = dox_pos_order_deposit( $order );
	if ( $dep > 0 && ! $order->get_meta( '_dox_pos_deposit_reversed' ) ) {
		$key = (string) $order->get_meta( '_dox_pos_credit_key' );
		dox_pos_credit_insert( $key, trim( $order->get_formatted_billing_full_name() ), -$dep, 'reversal', $order->get_id(), $note );
		$order->update_meta_data( '_dox_pos_deposit_reversed', 1 );
		$order->save_meta_data();
	}
}

/**
 * Antes de anular desde la caja un pedido con abono: si ese saldo ya se gastó, anularlo dejaría a la
 * clienta debiendo. Se avisa en vez de anular.
 *
 * @param WC_Order $order El pedido.
 * @return true|WP_Error
 */
function dox_pos_credit_can_cancel( $order ) {
	$dep = dox_pos_order_deposit( $order );
	if ( $dep <= 0 || ! $order->get_meta( '_dox_pos_credit_posted' ) || $order->get_meta( '_dox_pos_deposit_reversed' ) ) {
		return true;
	}
	$balance = dox_pos_credit_balance( (string) $order->get_meta( '_dox_pos_credit_key' ) );
	if ( $balance + 0.00001 < $dep ) {
		/* translators: 1: deposit, 2: balance */
		return new WP_Error( 'dox_pos_saldo_usado', sprintf( __( 'This deposit (%1$s) was already used: the customer’s balance is %2$s. Cancelling it would leave the customer owing the difference.', 'dox-pos' ), dox_pos_money( $dep ), dox_pos_money( $balance ) ) );
	}
	return true;
}

/* =====================================================================
 * El cambio o la devolución que queda como saldo
 * ===================================================================== */

/**
 * El cargo por cambio de la tienda (Ajustes > Ventas), en %. De fábrica, 0.
 */
function dox_pos_exchange_fee() {
	$s = dox_pos_sales();
	return isset( $s['exchange_fee'] ) ? max( 0.0, min( 100.0, (float) $s['exchange_fee'] ) ) : 0.0;
}

/**
 * Lo que vale una unidad devuelta: lo que pagó por ella, con su parte del descuento y del impuesto.
 * El pago con saldo no cuenta como descuento: es una forma de pago, y la prenda vale su precio.
 *
 * @param WC_Order              $order El pedido.
 * @param WC_Order_Item_Product $item  La línea.
 * @return float
 */
function dox_pos_unit_value( $order, $item ) {
	$items = 0.0;
	foreach ( $order->get_items() as $it ) {
		$items += (float) $it->get_total() + (float) $it->get_total_tax();
	}
	$fees = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( 'discount' === dox_pos_fee_type( $fee ) ) {
			$fees += (float) $fee->get_total() + (float) $fee->get_total_tax();
		}
	}
	$factor = $items > 0 ? ( $items + $fees ) / $items : 1;
	$qty    = max( 1, (int) $item->get_quantity() );
	return ( (float) $item->get_total() + (float) $item->get_total_tax() ) * $factor / $qty;
}

/**
 * Devuelve prendas de un pedido al saldo de la clienta: vuelven al inventario (si se pide), no sale
 * dinero y lo que valen, menos el cargo por cambio, queda como saldo. Es un reembolso de WooCommerce
 * por 0, que apunta las unidades devueltas sin restar de lo vendido.
 *
 * @param int   $order_id El pedido.
 * @param array $lines    [{id (de la línea), qty}].
 * @param array $args     restock (bool), fee (porcentaje), note, phone y name (si el pedido no tiene teléfono).
 * @return array|WP_Error
 */
function dox_pos_credit_return( $order_id, $lines, $args = array() ) {
	global $wpdb;
	$lock = 'dox_pos_refund_' . (int) $order_id; // El mismo candado de las devoluciones del Mostrador.
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
		return new WP_Error( 'dox_pos_ocupado', __( 'That order is being returned on another device. Try again in a moment.', 'dox-pos' ) );
	}
	try {
		return dox_pos_credit_return_locked( $order_id, $lines, $args );
	} finally {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

function dox_pos_credit_return_locked( $order_id, $lines, $args ) {
	$order = wc_get_order( (int) $order_id );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'dox_pos_no_pedido', __( 'That order does not exist.', 'dox-pos' ) );
	}
	if ( ! in_array( $order->get_status(), array( 'processing', 'enviado', 'completed' ), true ) || ( 'cod' === $order->get_payment_method() && ! $order->has_status( 'completed' ) ) ) {
		return new WP_Error( 'dox_pos_no_devolver', __( 'This order was not paid: there is nothing to return.', 'dox-pos' ) );
	}
	$key  = dox_pos_credit_key( (string) ( $args['phone'] ?? '' ) );
	$key  = '' !== $key ? $key : dox_pos_credit_key( $order->get_billing_phone() );
	if ( '' === $key ) {
		return new WP_Error( 'dox_pos_telefono', __( 'The balance is kept by phone number, and this order has none. Write it.', 'dox-pos' ) );
	}
	$name = trim( (string) ( $args['name'] ?? '' ) );
	$name = '' !== $name ? $name : trim( $order->get_formatted_billing_full_name() );
	$want = array();
	foreach ( (array) $lines as $l ) {
		$item_id = (int) ( is_array( $l ) ? ( $l['id'] ?? 0 ) : 0 );
		$qty     = (int) ( is_array( $l ) ? ( $l['qty'] ?? 0 ) : 0 );
		if ( $item_id && $qty > 0 ) {
			$want[ $item_id ] = ( $want[ $item_id ] ?? 0 ) + $qty;
		}
	}
	$dec   = wc_get_price_decimals();
	$items = array();
	$value = 0.0;
	$what  = array();
	foreach ( $want as $item_id => $qty ) {
		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$left = (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item_id ) );
		if ( $qty > $left ) {
			/* translators: 1: units, 2: product */
			return new WP_Error( 'dox_pos_de_mas', sprintf( __( 'Only %1$d of %2$s can still be returned.', 'dox-pos' ), $left, $item->get_name() ) );
		}
		$items[ $item_id ] = array( 'qty' => $qty, 'refund_total' => 0, 'refund_tax' => array() );
		$value            += dox_pos_unit_value( $order, $item ) * $qty;
		$what[]            = $item->get_name() . ( $qty > 1 ? ' ×' . $qty : '' );
	}
	if ( ! $items ) {
		return new WP_Error( 'dox_pos_sin_lineas', __( 'Choose what is being returned.', 'dox-pos' ) );
	}
	$value  = round( $value, $dec );
	$pct    = isset( $args['fee'] ) && '' !== (string) $args['fee'] ? max( 0.0, min( 100.0, (float) $args['fee'] ) ) : dox_pos_exchange_fee();
	$fee    = round( $value * $pct / 100, $dec );
	$credit = round( $value - $fee, $dec );
	$note   = sanitize_text_field( (string) ( $args['note'] ?? '' ) );

	// WooCommerce manda "Pedido reembolsado" a la clienta en cada reembolso, y con todo devuelto pasa el
	// pedido a "Reembolsado" (que lo anularía en los informes). Aquí no salió dinero: ni lo uno ni lo otro.
	$quiet = function () {
		return false;
	};
	add_filter( 'woocommerce_email_enabled_customer_refunded_order', $quiet );
	add_filter( 'woocommerce_order_fully_refunded_status', $quiet );
	$ctx    = dox_pos_stock_context( 'refund', $order->get_id() );
	$refund = wc_create_refund(
		array(
			'order_id'       => $order->get_id(),
			'amount'         => 0,
			/* translators: %s: reason */
			'reason'         => '' !== $note ? sprintf( __( 'To store credit: %s', 'dox-pos' ), $note ) : __( 'To store credit', 'dox-pos' ),
			'line_items'     => $items,
			'restock_items'  => ! isset( $args['restock'] ) || ! empty( $args['restock'] ),
			'refund_payment' => false,
		)
	);
	dox_pos_stock_context_end( $ctx );
	remove_filter( 'woocommerce_email_enabled_customer_refunded_order', $quiet );
	remove_filter( 'woocommerce_order_fully_refunded_status', $quiet );
	if ( is_wp_error( $refund ) ) {
		return $refund;
	}
	$user = function_exists( 'dox_pos_counter_actor' ) ? dox_pos_counter_actor() : wp_get_current_user();
	$refund->update_meta_data( '_dox_pos_refund_method', 'saldo' );
	$refund->update_meta_data( '_dox_pos_credit', wc_format_decimal( $credit, $dec ) );
	$refund->update_meta_data( '_dox_pos_credit_fee', wc_format_decimal( $fee, $dec ) );
	$refund->update_meta_data( '_dox_pos_seller_name', $user->display_name );
	$refund->save();
	$detail = implode( ', ', $what );
	if ( $fee > 0 ) {
		/* translators: 1: what came back, 2: percent, 3: amount kept by the shop */
		$detail = sprintf( __( '%1$s. Exchange fee %2$s%%: %3$s', 'dox-pos' ), $detail, wc_format_localized_decimal( $pct ), dox_pos_money( $fee ) );
	}
	/* translators: 1: order number, 2: detail */
	$movement = sprintf( __( 'Order #%1$s: %2$s', 'dox-pos' ), $order->get_order_number(), $detail ) . ( '' !== $note ? '. ' . $note : '' );
	if ( ! dox_pos_credit_lock( $key ) ) {
		$refund->delete( true ); // Sin candado no se apunta: mejor no dejar las prendas devueltas sin su saldo.
		return new WP_Error( 'dox_pos_ocupado', __( 'That balance is being used on another device. Try again in a moment.', 'dox-pos' ) );
	}
	try {
		dox_pos_credit_insert( $key, $name, $credit, 'return', $order->get_id(), $movement );
		$balance = dox_pos_credit_balance( $key );
	} finally {
		dox_pos_credit_unlock( $key );
	}
	/* translators: 1: amount, 2: user, 3: detail */
	$order->add_order_note( sprintf( __( 'Returned to store credit: %1$s by %2$s. %3$s', 'dox-pos' ), dox_pos_money( $credit ), $user->display_name, $detail ) );
	return array(
		'value'   => $value,
		'fee'     => $fee,
		'credit'  => $credit,
		'balance' => $balance,
		'name'    => $name,
		'phone'   => dox_pos_credit_phone_label( $key ),
		'message' => dox_pos_credit_message_url( $key ),
		'refund'  => (int) $refund->get_id(),
	);
}

/* =====================================================================
 * El mensaje a la clienta
 * ===================================================================== */

/**
 * El enlace de WhatsApp (o de mensaje de texto) con el saldo de la clienta escrito.
 *
 * @param string $key La clienta.
 * @return string
 */
function dox_pos_credit_message_url( $key ) {
	$balance = dox_pos_credit_balance( $key );
	$name    = dox_pos_credit_name( $key );
	$first   = trim( (string) strtok( $name, ' ' ) );
	/* translators: 1: customer first name, 2: shop, 3: balance */
	$text = sprintf( __( 'Hi %1$s, this is %2$s. Your store credit is %3$s: you can use it on your next purchase.', 'dox-pos' ), $first, dox_pos_brand_name(), dox_pos_money( $balance ) );
	$text = trim( preg_replace( '/[ \t]+([,.!?])/', '$1', $text ) );
	return dox_pos_message_url( $key, $text );
}

/* =====================================================================
 * La API
 * ===================================================================== */

add_action( 'rest_api_init', 'dox_pos_credit_routes' );
function dox_pos_credit_routes() {
	$ns   = 'dox-pos/v1';
	$perm = 'dox_pos_rest_permission';
	register_rest_route( $ns, '/credit', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_credit_lookup', 'permission_callback' => $perm ) ); // El saldo de un teléfono, al vender.
	register_rest_route( $ns, '/credit/customers', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_credit_customers', 'permission_callback' => 'dox_pos_rest_history_permission' ) );
	register_rest_route( $ns, '/credit/customer', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_credit_customer', 'permission_callback' => 'dox_pos_rest_history_permission' ) );
	register_rest_route( $ns, '/credit/adjust', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_credit_adjust', 'permission_callback' => 'dox_pos_rest_history_permission' ) );
	register_rest_route( $ns, '/orders/(?P<id>\d+)/credit', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_credit_return', 'permission_callback' => $perm ) );
}

function dox_pos_rest_credit_lookup( WP_REST_Request $request ) {
	return rest_ensure_response( dox_pos_credit_lookup( (string) $request->get_param( 'phone' ) ) );
}

function dox_pos_rest_credit_customers( WP_REST_Request $request ) {
	return rest_ensure_response( dox_pos_credit_customers( (string) $request->get_param( 'q' ), (int) $request->get_param( 'page' ) ) );
}

function dox_pos_rest_credit_customer( WP_REST_Request $request ) {
	$key = dox_pos_credit_key( (string) $request->get_param( 'phone' ) );
	if ( '' === $key ) {
		return dox_pos_rest_out( new WP_Error( 'dox_pos_telefono', __( 'Write the customer’s phone number.', 'dox-pos' ) ) );
	}
	return rest_ensure_response( dox_pos_credit_customer( $key ) );
}

function dox_pos_rest_credit_adjust( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();
	return dox_pos_rest_out( dox_pos_credit_adjust( (string) ( $b['phone'] ?? '' ), (string) ( $b['name'] ?? '' ), $b['amount'] ?? '', (string) ( $b['note'] ?? '' ) ) );
}

/**
 * Devolver al saldo desde el detalle del pedido: quien administra la tienda (crea saldo, que es
 * dinero de la tienda). En el Mostrador va por su propia devolución, con sus permisos.
 */
function dox_pos_rest_credit_return( WP_REST_Request $request ) {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return dox_pos_rest_out( new WP_Error( 'dox_pos_permiso', __( 'Only administrators and shop managers can return an order to store credit.', 'dox-pos' ) ) );
	}
	$order = dox_pos_get_own_order( (int) $request['id'] );
	if ( is_wp_error( $order ) ) {
		return dox_pos_rest_out( $order );
	}
	$b = (array) $request->get_json_params();
	$r = dox_pos_credit_return( $order->get_id(), (array) ( $b['lines'] ?? array() ), $b );
	if ( is_wp_error( $r ) ) {
		return dox_pos_rest_out( $r );
	}
	$r['order'] = dox_pos_format_order( wc_get_order( $order->get_id() ) );
	return rest_ensure_response( $r );
}
