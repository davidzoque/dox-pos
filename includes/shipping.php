<?php
/**
 * El costo de envío lo dicen las reglas de la tienda (zonas de envío de WooCommerce),
 * igual que en el checkout. Aquí se arma un "paquete" con el destino y los productos
 * y se le pregunta a cada método de la zona.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Las opciones de envío para un destino.
 *
 * @param string $state   Departamento (código ISO, p. ej. CO-ATL).
 * @param string $city    Ciudad, tal como la escribió.
 * @param string $address Dirección.
 * @param array  $lines   [ [ 'id' => id de variación o producto, 'qty' => n ], ... ].
 * @return array [ [ 'id', 'method_id', 'instance_id', 'label', 'cost' ], ... ]
 */
function dox_pos_shipping_rates( $state, $city, $address, $lines ) {
	$contents = array();
	$cost     = 0;
	foreach ( (array) $lines as $l ) {
		if ( ! is_array( $l ) ) {
			continue;
		}
		$p = wc_get_product( (int) ( $l['id'] ?? 0 ) );
		if ( ! $p ) {
			continue;
		}
		$qty        = max( 1, (int) ( $l['qty'] ?? 1 ) );
		$line_total = (float) $p->get_price() * $qty;
		$contents[] = array(
			'product_id'        => $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id(),
			'variation_id'      => $p->is_type( 'variation' ) ? $p->get_id() : 0,
			'quantity'          => $qty,
			'data'              => $p,
			'line_total'        => $line_total,
			'line_subtotal'     => $line_total,
			'line_tax'          => 0,
			'line_subtotal_tax' => 0,
		);
		$cost += $line_total;
	}
	if ( ! $contents ) {
		return array();
	}
	$package = array(
		'contents'        => $contents,
		'contents_cost'   => $cost,
		'applied_coupons' => array(),
		'user'            => array( 'ID' => get_current_user_id() ),
		'destination'     => array(
			'country'   => dox_pos_country(),
			'state'     => $state,
			'postcode'  => '',
			'city'      => $city,
			'address'   => $address,
			'address_1' => $address,
			'address_2' => '',
		),
		'cart_subtotal'   => $cost,
	);

	$zone  = WC_Shipping_Zones::get_zone_matching_package( $package );
	$rates = array();
	foreach ( $zone->get_shipping_methods( true ) as $method ) {
		if ( 'free_shipping' === $method->id ) {
			// El envío gratis mira el carrito, y aquí no hay carrito: se evalúa a mano.
			if ( ! dox_pos_free_shipping_applies( $method, $cost ) ) {
				continue;
			}
		} elseif ( ! $method->is_available( $package ) ) {
			continue;
		}
		$method->rates = array();
		$method->calculate_shipping( $package );
		$qty = dox_pos_package_qty( $package );
		foreach ( $method->rates as $rate ) {
			// Algunos plugins dan a todas sus tarifas el mismo id y se pisan entre sí: aquí cada instancia es una.
			$key = $method->id . ':' . (int) $method->get_instance_id();
			if ( (float) $rate->get_cost() <= 0 && false !== strpos( (string) $method->get_option( 'cost' ), '?' ) ) {
				// Costos escritos como "[qty] > 2 ? 24000 : 12000": WooCommerce no sabe evaluarlos y da 0.
				$manual = dox_pos_eval_ternary( (string) $method->get_option( 'cost' ), $qty, $cost );
				if ( null !== $manual ) {
					$rate->set_cost( $manual );
				}
			}
			$rates[ $key ] = $rate;
		}
	}
	// Por aquí pasan los ajustes del tema (por ejemplo, el envío gratis hasta 2 prendas).
	$rates = apply_filters( 'woocommerce_package_rates', $rates, $package );

	$out = array();
	foreach ( $rates as $key => $rate ) {
		$out[] = array(
			'id'          => $key,
			'method_id'   => $rate->get_method_id(),
			'instance_id' => (int) $rate->get_instance_id(),
			'label'       => $rate->get_label(),
			'cost'        => (float) $rate->get_cost(),
		);
	}
	return $out;
}

/**
 * Unidades que se envían en el paquete.
 */
function dox_pos_package_qty( $package ) {
	$qty = 0;
	foreach ( $package['contents'] as $item ) {
		$qty += (int) $item['quantity'];
	}
	return $qty;
}

/**
 * Evalúa un costo del tipo "[qty] > 2 ? 24000 : 12000" (o con [cost]). Solo esa forma;
 * cualquier otra cosa devuelve null y se deja lo que calculó el método.
 *
 * @param string $expr Lo que hay en el ajuste "Costo".
 * @param int    $qty  Unidades.
 * @param float  $cost Subtotal.
 * @return float|null
 */
function dox_pos_eval_ternary( $expr, $qty, $cost ) {
	$e = html_entity_decode( $expr, ENT_QUOTES, 'UTF-8' );
	$e = str_replace( array( '[qty]', '[cost]' ), array( $qty, $cost ), $e );
	$e = preg_replace( '/\s+/', '', $e );
	if ( ! preg_match( '/^(\d+(?:\.\d+)?)(>=|<=|==|>|<)(\d+(?:\.\d+)?)\?(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', $e, $m ) ) {
		return null;
	}
	$a = (float) $m[1];
	$b = (float) $m[3];
	switch ( $m[2] ) {
		case '>':
			$ok = $a > $b;
			break;
		case '>=':
			$ok = $a >= $b;
			break;
		case '<':
			$ok = $a < $b;
			break;
		case '<=':
			$ok = $a <= $b;
			break;
		default:
			$ok = $a === $b;
	}
	return (float) ( $ok ? $m[4] : $m[5] );
}

/**
 * ¿Aplica el envío gratis? Solo por mínimo de compra; los que exigen cupón no aplican en la caja.
 *
 * @param WC_Shipping_Method $method El método.
 * @param float              $cost   Subtotal.
 * @return bool
 */
function dox_pos_free_shipping_applies( $method, $cost ) {
	if ( 'yes' !== $method->enabled ) {
		return false;
	}
	$requires = (string) $method->get_option( 'requires' );
	$min      = (float) $method->get_option( 'min_amount' );
	if ( '' === $requires ) {
		return true;
	}
	if ( in_array( $requires, array( 'min_amount', 'either' ), true ) ) {
		return $cost >= $min;
	}
	return false;
}
