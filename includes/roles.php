<?php
/**
 * El rol "caja" y el permiso que abre la puerta.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crea el rol "caja" (solo puede usar la caja) y da el permiso a administradores
 * y gerentes de tienda. Se ejecuta al activar; repetirlo no hace daño.
 */
function dox_pos_install_roles() {
	$role = get_role( 'caja' );
	if ( ! $role ) {
		$role = add_role( 'caja', __( 'Cashier', 'dox-pos' ), array( 'read' => true ) );
	}
	if ( $role ) {
		$role->add_cap( DOX_POS_CAP );
	}
	dox_pos_install_counter_role();
	foreach ( array( 'administrator', 'shop_manager' ) as $name ) {
		$r = get_role( $name );
		if ( $r ) {
			$r->add_cap( DOX_POS_CAP );
		}
	}
}

/**
 * ¿Este usuario es solo de caja? Tiene el rol y ningún permiso de edición.
 *
 * @param WP_User|null $user Por defecto, el usuario actual.
 * @return bool
 */
function dox_pos_is_cashier_only( $user = null ) {
	$user = $user instanceof WP_User ? $user : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}
	return array_intersect( array( 'caja', DOX_POS_COUNTER_ROLE ), (array) $user->roles )
		&& ! user_can( $user, 'edit_posts' )
		&& ! user_can( $user, 'manage_woocommerce' );
}

/**
 * Quien es solo de caja no entra a wp-admin: se le manda a la caja.
 */
add_action( 'admin_init', 'dox_pos_lock_admin' );
function dox_pos_lock_admin() {
	if ( wp_doing_ajax() || ! dox_pos_is_cashier_only() ) {
		return;
	}
	wp_safe_redirect( dox_pos_url() );
	exit;
}

/**
 * Sin barra de administración para quien es solo de caja.
 *
 * @param bool $show Lo que WordPress iba a hacer.
 * @return bool
 */
add_filter( 'show_admin_bar', 'dox_pos_hide_admin_bar' );
function dox_pos_hide_admin_bar( $show ) {
	return dox_pos_is_cashier_only() ? false : $show;
}

/**
 * Si entra por el login normal de WordPress, quien es solo de caja acaba en la caja.
 *
 * @param string           $redirect_to A dónde iba.
 * @param string           $requested   Lo que pidió.
 * @param WP_User|WP_Error $user        El usuario que entró.
 * @return string
 */
add_filter( 'login_redirect', 'dox_pos_login_redirect', 10, 3 );
function dox_pos_login_redirect( $redirect_to, $requested, $user ) {
	if ( $user instanceof WP_User && dox_pos_is_cashier_only( $user ) ) {
		return dox_pos_url();
	}
	return $redirect_to;
}

/**
 * El rol "Cajero de mostrador": solo cobra en el Mostrador de la tienda física. No ve Vender,
 * Inventario, Pedidos ni los informes, y la API tampoco le deja hacer esas cosas por detrás
 * (dox_pos_counter_only_allows). El rol "Caja" sigue igual para quien vende por chat.
 */
const DOX_POS_COUNTER_ROLE = 'caja_mostrador';
function dox_pos_install_counter_role() {
	$role = get_role( DOX_POS_COUNTER_ROLE );
	if ( ! $role ) {
		$role = add_role( DOX_POS_COUNTER_ROLE, __( 'Counter cashier', 'dox-pos' ), array( 'read' => true ) );
	}
	if ( $role ) {
		$role->add_cap( DOX_POS_CAP );
	}
}

/**
 * ¿Este usuario solo cobra en el Mostrador? Tiene el rol y nada que le dé más (gerente, editor).
 *
 * @param WP_User|null $user Por defecto, el usuario actual.
 * @return bool
 */
function dox_pos_is_counter_only( $user = null ) {
	$user = $user instanceof WP_User ? $user : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}
	return in_array( DOX_POS_COUNTER_ROLE, (array) $user->roles, true )
		&& ! in_array( 'caja', (array) $user->roles, true )
		&& ! user_can( $user, 'edit_posts' )
		&& ! user_can( $user, 'manage_woocommerce' );
}

/**
 * Lo que la API le deja hacer a quien solo cobra en el Mostrador: buscar, escanear, cobrar (solo
 * ventas de mostrador), el turno y las devoluciones. La lista de pedidos le llega vacía.
 *
 * @param WP_REST_Request $request La petición.
 * @return bool
 */
function dox_pos_counter_only_allows( $request ) {
	$route  = preg_replace( '#^/dox-pos/v1#', '', (string) $request->get_route() );
	$method = $request->get_method();
	if ( 'GET' === $method ) {
		$ok = (bool) preg_match( '#^/(search|top|orders|counter/(scan|shift|order))$#', $route );
	} elseif ( '/orders' === $route ) {
		$b  = (array) $request->get_json_params();
		$ok = ! empty( $b['counter'] ) && empty( $b['hold'] );
	} else {
		$ok = (bool) preg_match( '#^/counter/(quote|shift/open|shift/close|refund)$#', $route );
	}
	// Los añadidos abren lo suyo del Mostrador (el Pro: el PIN y las entradas y salidas de dinero).
	return (bool) apply_filters( 'dox_pos_counter_only_allows', $ok, $route, $method, $request );
}
