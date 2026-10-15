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
 * usuario mande algo (comprobado en la primera tienda: x-litespeed-cache: hit,private).
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
	// Los costos de envío, puestos desde la caja: las zonas y los métodos de WooCommerce. Solo quien administra.
	$sperm = 'dox_pos_rest_shipping_setup_permission';
	register_rest_route( $ns, '/shipping/setup', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_shipping_setup', 'permission_callback' => $sperm ) );
	register_rest_route( $ns, '/shipping/zones', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_shipping_save_zone', 'permission_callback' => $sperm ) );
	register_rest_route(
		$ns,
		'/shipping/zones/(?P<zone>\d+)',
		array(
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_shipping_save_zone', 'permission_callback' => $sperm ),
			array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => 'dox_pos_rest_shipping_delete_zone', 'permission_callback' => $sperm ),
		)
	);
	register_rest_route( $ns, '/shipping/zones/(?P<zone>\d+)/rates', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_shipping_save_rate', 'permission_callback' => $sperm ) );
	register_rest_route(
		$ns,
		'/shipping/zones/(?P<zone>\d+)/rates/(?P<rate>\d+)',
		array(
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_shipping_save_rate', 'permission_callback' => $sperm ),
			array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => 'dox_pos_rest_shipping_delete_rate', 'permission_callback' => $sperm ),
		)
	);
	register_rest_route(
		$ns,
		'/orders',
		array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_orders', 'permission_callback' => $perm ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_create_order', 'permission_callback' => $perm ),
		)
	);
	register_rest_route( $ns, '/orders/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_order_detail', 'permission_callback' => $perm ) );
	// La foto de una línea sin producto (un diseño por encargo): la sube quien vende, aunque no pueda crear productos.
	register_rest_route( $ns, '/sale/image', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_upload_line_image', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/sale/image/(?P<id>\d+)', array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => 'dox_pos_rest_delete_line_image', 'permission_callback' => $perm ) );
	register_rest_route( $ns, '/orders/(?P<id>\d+)/(?P<action>paid|release|shipped|delivered|cancel|loss|deposit|deadline)', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_order_action', 'permission_callback' => $perm ) );
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
	register_rest_route( $ns, '/products/categories', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_create_category', 'permission_callback' => $pperm ) ); // Una categoría nueva, desde el formulario de producto.
	register_rest_route( $ns, '/products/sizes', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_create_size', 'permission_callback' => 'dox_pos_rest_terms_permission' ) ); // Un valor nuevo ("42", "750 ml") en un atributo.
	register_rest_route( $ns, '/products/attributes', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_create_attribute', 'permission_callback' => 'dox_pos_rest_terms_permission' ) ); // Un atributo nuevo (Capacidad) con sus valores.
	register_rest_route( $ns, '/products/swatch', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_value_hex', 'permission_callback' => 'dox_pos_rest_terms_permission' ) ); // El tono de un color que no lo tiene.
	register_rest_route( $ns, '/products/category-size', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_category_size', 'permission_callback' => $pperm ) ); // Qué tallas usa una categoría (0.56).
	register_rest_route( $ns, '/products/category-vary', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dox_pos_rest_category_vary', 'permission_callback' => $pperm ) ); // Por qué varían los productos de una categoría.
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
	register_rest_route( $ns, '/dashboard/series', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_dashboard_series', 'permission_callback' => 'dox_pos_rest_history_permission', 'args' => array( 'days' => array( 'type' => 'integer', 'default' => 90 ) ) ) ); // La gráfica del Panel cuando pide un tramo más largo.
	register_rest_route( $ns, '/top', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_top', 'permission_callback' => $perm ) ); // Lo más vendido, para Vender antes de buscar.
	register_rest_route( $ns, '/catalog', array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'dox_pos_rest_catalog', 'permission_callback' => $perm, 'args' => array( 'page' => array( 'type' => 'integer', 'default' => 1 ) ) ) ); // El catálogo de la A a la Z, para Inventario antes de buscar.
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
 * Crear atributos, valores (una talla, un color) o el tono de un color: además, el permiso de WooCommerce para los
 * términos de productos (lo tienen quien administra y el gestor de la tienda).
 *
 * @return true|WP_Error
 */
function dox_pos_rest_terms_permission() {
	$ok = dox_pos_rest_products_permission();
	if ( true !== $ok ) {
		return $ok;
	}
	if ( ! current_user_can( 'manage_product_terms' ) ) {
		return new WP_Error( 'dox_pos_sin_permiso', __( 'Only administrators and shop managers can create products.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Con sesión y con permiso; si no, 401 o 403.
 *
 * @return bool|WP_Error
 */
function dox_pos_rest_permission( $request = null ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'dox_pos_sin_sesion', __( 'Your session expired. Please sign in again.', 'dox-pos' ), array( 'status' => 401 ) );
	}
	if ( ! current_user_can( DOX_POS_CAP ) ) {
		return new WP_Error( 'dox_pos_sin_permiso', __( 'Your user does not have access to the register.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	// Quien solo cobra en el Mostrador no llega al resto de la caja ni pidiéndolo a mano.
	// Sin la petición no se sabe a qué ruta va: se le niega (falla cerrado).
	if ( dox_pos_is_counter_only() && ( ! $request instanceof WP_REST_Request || ! dox_pos_counter_only_allows( $request ) ) ) {
		return new WP_Error( 'dox_pos_solo_mostrador', __( 'Your user only has access to the Counter.', 'dox-pos' ), array( 'status' => 403 ) );
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

function dox_pos_rest_catalog( WP_REST_Request $request ) {
	return rest_ensure_response( dox_pos_catalog_page( (int) $request->get_param( 'page' ) ) );
}

function dox_pos_rest_dashboard() {
	return rest_ensure_response( dox_pos_dashboard() );
}

/**
 * La gráfica del Panel para un tramo que no viene con él (90 días). Solo los tramos que ofrece.
 *
 * @param WP_REST_Request $request La petición.
 * @return WP_REST_Response
 */
function dox_pos_rest_dashboard_series( WP_REST_Request $request ) {
	$days = (int) $request->get_param( 'days' );
	if ( ! in_array( $days, DOX_POS_DASH_RANGES, true ) ) {
		$days = max( DOX_POS_DASH_RANGES );
	}
	return rest_ensure_response( array( 'days' => $days, 'series' => dox_pos_dashboard_long( $days ) ) );
}

function dox_pos_rest_shipping( WP_REST_Request $request ) {
	$b = (array) $request->get_json_params();
	return rest_ensure_response(
		array(
			'rates' => dox_pos_shipping_rates(
				sanitize_text_field( $b['state'] ?? '' ),
				sanitize_text_field( $b['city'] ?? '' ),
				sanitize_text_field( $b['address'] ?? '' ),
				(array) ( $b['lines'] ?? array() ),
				sanitize_text_field( $b['postcode'] ?? '' )
			),
		)
	);
}

/**
 * Poner los costos de envío pide, además de la caja, administrar la tienda.
 *
 * @return bool|WP_Error
 */
function dox_pos_rest_shipping_setup_permission() {
	$ok = dox_pos_rest_permission();
	if ( true !== $ok ) {
		return $ok;
	}
	if ( ! dox_pos_can_manage_shipping() ) {
		return new WP_Error( 'dox_pos_sin_permiso', __( 'Only administrators and shop managers can set the shipping costs.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	return true;
}

function dox_pos_rest_shipping_setup() {
	return rest_ensure_response( dox_pos_shipping_setup() );
}

function dox_pos_rest_shipping_save_zone( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_shipping_save_zone( (int) $request['zone'], (array) $request->get_json_params() ) );
}

function dox_pos_rest_shipping_delete_zone( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_shipping_delete_zone( (int) $request['zone'] ) );
}

function dox_pos_rest_shipping_save_rate( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_shipping_save_rate( (int) $request['zone'], (int) $request['rate'], (array) $request->get_json_params() ) );
}

function dox_pos_rest_shipping_delete_rate( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_shipping_delete_rate( (int) $request['zone'], (int) $request['rate'] ) );
}

function dox_pos_rest_orders() {
	return rest_ensure_response( array( 'items' => dox_pos_is_counter_only() ? array() : dox_pos_list_orders() ) ); // El cajero de mostrador no ve los pedidos.
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

function dox_pos_rest_create_category( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_create_category( (array) $request->get_json_params() ) );
}

function dox_pos_rest_create_size( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_create_size( (array) $request->get_json_params() ) );
}

function dox_pos_rest_category_size( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_set_category_size( (array) $request->get_json_params() ) );
}

function dox_pos_rest_category_vary( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_set_category_vary( (array) $request->get_json_params() ) );
}

function dox_pos_rest_create_attribute( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_create_attribute( (array) $request->get_json_params() ) );
}

function dox_pos_rest_value_hex( WP_REST_Request $request ) {
	return dox_pos_rest_out( dox_pos_set_value_hex( (array) $request->get_json_params() ) );
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

function dox_pos_rest_upload_line_image( WP_REST_Request $request ) {
	return dox_pos_rest_upload_image( $request ); // La misma subida que la de un producto: WebP y pendiente hasta que se use.
}

function dox_pos_rest_delete_line_image( WP_REST_Request $request ) {
	$id = (int) $request['id'];
	if ( ! dox_pos_line_image_ok( $id ) ) {
		return new WP_Error( 'dox_pos_foto', __( 'That photo is not pending.', 'dox-pos' ), array( 'status' => 400 ) );
	}
	return dox_pos_rest_out( dox_pos_delete_pending_image( $id ) );
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
	$data = (array) $request->get_json_params();
	return dox_pos_rest_out( dox_pos_real_qty_after( dox_pos_update_product( (int) $request['id'], $data ), $data ) );
}

function dox_pos_rest_create_product( WP_REST_Request $request ) {
	$data = (array) $request->get_json_params();
	return dox_pos_rest_out( dox_pos_real_qty_after( dox_pos_create_product( $data ), $data ) );
}

/**
 * Después de guardar un producto desde el formulario: las prendas por talla real (includes/real-sizes.php).
 *
 * @param array|WP_Error $r    Lo que devolvió el guardado.
 * @param array          $data Lo que mandó el formulario.
 * @return array|WP_Error
 */
function dox_pos_real_qty_after( $r, $data ) {
	if ( is_array( $r ) && ! empty( $r['product']['id'] ) ) {
		dox_pos_real_qty_save( (int) $r['product']['id'], $data );
	}
	return $r;
}
