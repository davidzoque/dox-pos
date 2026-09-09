<?php
/**
 * La API REST de la caja: /wp-json/dox-pos/v1/
 *
 * Solo responde a usuarios con sesión y con el permiso de la caja. El JS manda
 * el nonce de WordPress en la cabecera X-WP-Nonce.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Las respuestas de la API no van a caché. LiteSpeed guarda las peticiones REST de un
 * usuario con sesión en su caché privada (hasta 30 minutos) cuando "Cache REST API" está
 * activo, y la caja se quedaría viendo existencias y pedidos viejos hasta que ese mismo
 * usuario mande algo (comprobado en rosella: x-litespeed-cache: hit,private).
 */
add_filter( 'rest_pre_dispatch', 'dox_pos_rest_nocache', 10, 3 );
function dox_pos_rest_nocache( $result, $server, $request ) {
	if ( 0 === strpos( (string) $request->get_route(), '/dox-pos/v1' ) ) {
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		do_action( 'litespeed_control_set_nocache', 'dox-pos' );
	}
	return $result;
}

add_action( 'rest_api_init', 'dox_pos_register_routes' );
function dox_pos_register_routes() {
	$ns   = 'dox-pos/v1';
	$perm = 'dox_pos_rest_permission';
	register_rest_route(
		$ns,
		'/search',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dox_pos_rest_search',
			'permission_callback' => $perm,
			'args'                => array(
				'q' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
	register_rest_route( $ns, '/shipping', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_shipping', 'permission_callback' => $perm ) );
	register_rest_route(
		$ns,
		'/orders',
		array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_orders', 'permission_callback' => $perm ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_create_order', 'permission_callback' => $perm ),
		)
	);
	register_rest_route( $ns, '/orders/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_order_detail', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/orders/(?P<id>\d+)/(?P<action>paid|release|shipped|delivered|cancel|loss)', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_order_action', 'permission_callback' => $perm ) );
	register_rest_route(
		$ns,
		'/entries',
		array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_entries', 'permission_callback' => $perm ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_create_entry', 'permission_callback' => $perm ),
		)
	);
	register_rest_route( $ns, '/entries/(?P<id>\d+)/cancel', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_cancel_entry', 'permission_callback' => $perm ) );

	// Crear productos: solo administradores y gerentes de tienda.
	$pperm = 'dox_pos_rest_products_permission';
	register_rest_route( $ns, '/products', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_create_product', 'permission_callback' => $pperm ) );
	register_rest_route( $ns, '/products/form', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_product_form', 'permission_callback' => $pperm ) );
	register_rest_route(
		$ns,
		'/products/sku',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dox_pos_rest_sku',
			'permission_callback' => $pperm,
			'args'                => array(
				'sku'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
				'prefix' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		)
	);
	register_rest_route( $ns, '/products/image', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_upload_image', 'permission_callback' => $pperm ) );
	// Editar: buscar cuál, cargarlo y guardarlo.
	register_rest_route(
		$ns,
		'/products/find',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dox_pos_rest_find_products',
			'permission_callback' => $pperm,
			'args'                => array(
				'q'    => array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'page' => array( 'type' => 'integer', 'required' => false, 'default' => 1, 'sanitize_callback' => 'absint' ),
			),
		)
	);
	register_rest_route(
		$ns,
		'/products/(?P<id>\d+)',
		array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_get_product', 'permission_callback' => $pperm ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_update_product', 'permission_callback' => $pperm ),
		)
	);
	register_rest_route( $ns, '/products/(?P<id>\d+)/card', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_product_card', 'permission_callback' => $perm ) ); // La tarjeta: cualquiera con acceso a la caja.
	register_rest_route( $ns, '/products/image/(?P<id>\d+)', array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => 'dox_pos_rest_delete_image', 'permission_callback' => $pperm ) );
	// Los costos de golpe, desde el Excel del inventario con la columna Costo llena.
	register_rest_route( $ns, '/costs/import', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_costs_import', 'permission_callback' => $pperm ) );

	// El historial: las ventas las ve cualquiera de la caja (el rol Caja, solo las suyas de hoy);
	// la caja del día y los movimientos, administradores y gerentes.
	$date = array( 'type' => 'string', 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' );
	register_rest_route( $ns, '/history/sales', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_history_sales', 'permission_callback' => $perm, 'args' => array( 'from' => $date, 'to' => $date ) ) );
	register_rest_route( $ns, '/dashboard', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_dashboard', 'permission_callback' => 'dox_pos_rest_history_permission' ) ); // El Panel: quien administra.
	register_rest_route( $ns, '/top', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_top', 'permission_callback' => $perm ) ); // Lo más vendido, para Vender antes de buscar.
	register_rest_route( $ns, '/history/cash', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_history_cash', 'permission_callback' => 'dox_pos_rest_history_permission', 'args' => array( 'from' => $date, 'to' => $date ) ) );
	register_rest_route(
		$ns,
		'/history/stock',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dox_pos_rest_history_stock',
			'permission_callback' => 'dox_pos_rest_history_permission',
			'args'                => array(
				'from'    => $date,
				'to'      => $date,
				'q'       => $date,
				'kind'    => $date,
				'product' => array( 'type' => 'integer', 'required' => false, 'default' => 0, 'sanitize_callback' => 'absint' ),
				'page'    => array( 'type' => 'integer', 'required' => false, 'default' => 1, 'sanitize_callback' => 'absint' ),
			),
		)
	);
}

/**
 * Crear productos y subir fotos pide, además de la caja, el permiso de la tienda.
 *
 * @return bool|WP_Error
 */
function dox_pos_rest_products_permission() {
	$ok = dox_pos_rest_permission();
	if ( true !== $ok ) {
		return $ok;
	}
	if ( ! current_user_can( dox_pos_products_cap() ) ) {
		return new WP_Error( 'dox_pos_sin_permiso', __( 'Only administrators and shop managers can create products.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Con sesión y con permiso; si no, 401 o 403.
 *
 * @return bool|WP_Error
 */
function dox_pos_rest_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'dox_pos_sin_sesion', __( 'Your session expired. Please sign in again.', 'dox-pos' ), array( 'status' => 401 ) );
	}
	if ( ! current_user_can( DOX_POS_CAP ) ) {
		return new WP_Error( 'dox_pos_sin_permiso', __( 'Your user does not have access to the register.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Un error de la caja se devuelve como 400 con su código, su mensaje y sus datos
 * (la entrada repetida, por ejemplo), no como 500.
 *
 * @param mixed $result Lo que devolvió la función.
 * @return WP_REST_Response|WP_Error
 */
function dox_pos_rest_out( $result ) {
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array_merge( is_array( $data ) ? $data : array(), array( 'status' => 400 ) ) );
	}
	return rest_ensure_response( $result );
}

function dox_pos_rest_search( WP_REST_Request $request ) {
	return rest_ensure_response( array( 'items' => dox_pos_search( $request->get_param( 'q' ), 20 ) ) );
}

function dox_pos_rest_top() {
	return rest_ensure_response( array( 'items' => dox_pos_top_for_sale() ) );
}

function dox_pos_rest_dashboard() {
	return rest_ensure_response( dox_pos_dashboard() );
}

function dox_pos_rest_shipping( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();
	return rest_ensure_response(
		array(
			'rates' => dox_pos_shipping_rates(
				sanitize_text_field( $b['state'] ?? '' ),
				sanitize_text_field( $b['city'] ?? '' ),
				sanitize_text_field( $b['address'] ?? '' ),
				(array) ( $b['lines'] ?? array() )
			),
		)
	);
}

function dox_pos_rest_orders() {
	return rest_ensure_response( array( 'items' => dox_pos_list_orders() ) );
}

function dox_pos_rest_order_detail( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_order_detail( (int) $request['id'] ) );
}

function dox_pos_rest_create_order( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();
	return dox_pos_rest_out( dox_pos_create_order( $b, ! empty( $b['hold'] ) ) );
}

function dox_pos_rest_order_action( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();
	$r = dox_pos_order_action( (int) $request['id'], $request['action'], $b );
	return dox_pos_rest_out( is_wp_error( $r ) ? $r : array( 'order' => $r ) );
}

function dox_pos_rest_entries() {
	return rest_ensure_response( array( 'items' => dox_pos_list_entries() ) );
}

function dox_pos_rest_create_entry( WP_REST_Request $request ) {
	$r = dox_pos_create_entry( (array) $request->get_json_params() );
	return dox_pos_rest_out( is_wp_error( $r ) ? $r : array( 'entry' => $r ) );
}

function dox_pos_rest_cancel_entry( WP_REST_Request $request ) {
	$r = dox_pos_cancel_entry( (int) $request['id'] );
	return dox_pos_rest_out( is_wp_error( $r ) ? $r : array( 'entry' => $r ) );
}

function dox_pos_rest_product_form() {
	return rest_ensure_response( dox_pos_product_form() );
}

/**
 * Con ?sku= dice si ese código está libre; con ?prefix= propone el siguiente libre.
 */
function dox_pos_rest_sku( WP_REST_Request $request ) {
	$sku = strtoupper( trim( (string) $request->get_param( 'sku' ) ) );
	if ( '' !== $sku ) {
		$problem = dox_pos_sku_problem( $sku );
		return rest_ensure_response( array( 'sku' => $sku, 'ok' => '' === $problem, 'message' => $problem ) );
	}
	$next = dox_pos_next_sku( (string) $request->get_param( 'prefix' ) );
	return rest_ensure_response( array( 'sku' => $next, 'ok' => '' !== $next, 'message' => '' ) );
}

function dox_pos_rest_upload_image( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	if ( empty( $files['file'] ) ) {
		return new WP_Error( 'dox_pos_foto', __( 'No photo was received.', 'dox-pos' ), array( 'status' => 400 ) );
	}
	return dox_pos_rest_out( dox_pos_upload_image( $files['file'], sanitize_text_field( (string) $request->get_param( 'name' ) ) ) );
}

function dox_pos_rest_delete_image( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_delete_pending_image( (int) $request['id'] ) );
}

function dox_pos_rest_find_products( WP_REST_Request $request ) {
	return rest_ensure_response( dox_pos_find_products( (string) $request->get_param( 'q' ), (int) $request->get_param( 'page' ) ) );
}

function dox_pos_rest_get_product( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_product_edit_data( (int) $request['id'] ) );
}

function dox_pos_rest_product_card( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_product_card( (int) $request['id'] ) );
}

function dox_pos_rest_update_product( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_update_product( (int) $request['id'], (array) $request->get_json_params() ) );
}

function dox_pos_rest_create_product( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_create_product( (array) $request->get_json_params() ) );
}
