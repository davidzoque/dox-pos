<?php
/**
 * La página de la caja: la ruta, el login propio y las plantillas.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * La ruta (/caja, o la que diga el ajuste) apunta a la variable de consulta dox_pos.
 */
add_action( 'init', 'dox_pos_add_rewrite' );
function dox_pos_add_rewrite() {
	add_rewrite_rule( '^' . dox_pos_slug() . '/?$', 'index.php?dox_pos=1', 'top' );
}

add_filter( 'query_vars', 'dox_pos_query_vars' );
function dox_pos_query_vars( $vars ) {
	$vars[] = 'dox_pos';
	return $vars;
}

/**
 * Sirve la caja (o el login) y corta la carga del tema.
 */
add_action( 'template_redirect', 'dox_pos_render', 0 );
function dox_pos_render() {
	if ( ! get_query_var( 'dox_pos' ) ) {
		return;
	}
	dox_pos_no_cache();

	if ( ! function_exists( 'wc_get_product' ) ) {
		wp_die( esc_html__( 'La caja necesita WooCommerce activo.', 'dox-pos' ) );
	}

	// Salir: /caja/?salir=1&_wpnonce=...
	if ( isset( $_GET['salir'] ) ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'dox_pos_logout' ) ) {
			wp_logout();
		}
		wp_safe_redirect( dox_pos_url() );
		exit;
	}

	$error = '';
	if ( isset( $_POST['dox_pos_login'] ) ) {
		$error = dox_pos_handle_login(); // Si entra bien, redirige y no vuelve.
	}

	if ( ! is_user_logged_in() || ! current_user_can( DOX_POS_CAP ) ) {
		$sin_permiso = is_user_logged_in(); // Entró, pero con un usuario que no tiene acceso.
		include DOX_POS_PATH . 'templates/login.php';
		exit;
	}

	// Los Excel: /caja/?descargar=inventario, o ventas|caja|movimientos&desde=&hasta= (solo leen; con la sesión y el permiso ya comprobados).
	if ( isset( $_GET['descargar'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$what = sanitize_key( wp_unslash( $_GET['descargar'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'inventario' === $what ) {
			dox_pos_send_inventory();
		} elseif ( in_array( $what, array( 'ventas', 'caja', 'movimientos' ), true ) ) {
			$g = fn( $k ) => sanitize_text_field( wp_unslash( $_GET[ $k ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			dox_pos_send_history( $what, $g( 'desde' ), $g( 'hasta' ), $g( 'q' ), (int) $g( 'producto' ), $g( 'tipo' ) );
		}
	}

	do_action( 'dox_pos_caja_open' ); // Los añadidos hacen lo suyo al abrir (el Pro pide el resumen atrasado).
	include DOX_POS_PATH . 'templates/caja.php';
	exit;
}

/**
 * Nada de caché para esta página: ni LiteSpeed, ni Cloudflare, ni buscadores.
 */
function dox_pos_no_cache() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
}

/**
 * Procesa el formulario de entrada. Los campos se llaman como en wp-login.php
 * (log, pwd, rememberme) para que wp_signon() los lea exactamente igual que el
 * login de WordPress. Devuelve el mensaje de error, o redirige si entró.
 *
 * @return string
 */
function dox_pos_handle_login() {
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'dox_pos_login' ) ) {
		return __( 'La página caducó. Vuelve a intentarlo.', 'dox-pos' );
	}
	$user = wp_signon( array(), is_ssl() );
	if ( is_wp_error( $user ) ) {
		return __( 'Usuario o clave incorrectos.', 'dox-pos' );
	}
	if ( ! user_can( $user, DOX_POS_CAP ) ) {
		wp_logout();
		return __( 'Ese usuario no tiene acceso a la caja.', 'dox-pos' );
	}
	wp_safe_redirect( dox_pos_url() );
	exit;
}

/**
 * El enlace para salir.
 *
 * @return string
 */
function dox_pos_logout_url() {
	return add_query_arg(
		array(
			'salir'    => 1,
			'_wpnonce' => wp_create_nonce( 'dox_pos_logout' ),
		),
		dox_pos_url()
	);
}

/**
 * El logo: el del ajuste, o el del sitio (Apariencia > Personalizar).
 *
 * @return string URL, o vacío.
 */
function dox_pos_logo_url() {
	$url = get_option( 'dox_pos_logo', '' );
	if ( ! $url ) {
		$id  = (int) get_theme_mod( 'custom_logo' );
		$url = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
	}
	return (string) apply_filters( 'dox_pos_logo_url', $url ? $url : '' );
}

/**
 * El <head> común de la caja y del login: título, fuentes, hoja de estilo, los
 * colores del ajuste, el icono del sitio y el color de la barra del navegador.
 */
function dox_pos_head() {
	$colors = dox_pos_colors();
	?>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<meta name="theme-color" content="<?php echo esc_attr( $colors['bar'] ); ?>">
	<title><?php echo esc_html( dox_pos_screen_name() . ' · ' . dox_pos_brand_name() ); ?></title>
	<?php wp_site_icon(); ?>
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="<?php echo esc_url( dox_pos_fonts_url() ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( DOX_POS_URL . 'assets/css/caja.css?ver=' . DOX_POS_VERSION ); ?>">
	<style><?php echo dox_pos_theme_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Solo colores validados y nombres de fuente. ?></style>
	<?php
	do_action( 'dox_pos_head' ); // Hojas de estilo de los añadidos.
}

/**
 * Lo que el JS necesita saber para trabajar.
 *
 * @return array
 */
function dox_pos_js_config() {
	$user     = wp_get_current_user();
	$country  = dox_pos_country();
	$payments = array();
	foreach ( dox_pos_payment_methods() as $key => $m ) {
		$payments[] = array( 'key' => $key, 'title' => $m['title'] );
	}
	$cfg = array(
		'rest'            => esc_url_raw( rest_url( 'dox-pos/v1/' ) ),
		'nonce'           => wp_create_nonce( 'wp_rest' ),
		'user'            => $user->display_name ? $user->display_name : $user->user_login,
		'logout'          => dox_pos_logout_url(),
		'site'            => dox_pos_brand_name(),
		'screen'          => dox_pos_screen_name(),
		'channels'        => dox_pos_channels(),
		'payments'        => $payments,
		'default_payment' => dox_pos_default_payment(),
		'carriers'        => dox_pos_carriers(),                            // [{name, url}] para el modal de envío.
		'ship_email'      => dox_pos_ship_email_on(),                       // ¿Se le manda correo a la clienta al marcar enviado?
		'state_label'     => dox_pos_state_label(),
		'states'          => function_exists( 'WC' ) ? WC()->countries->get_states( $country ) : array(),
		'cities'          => 'CO' === $country && function_exists( 'colciu_get_ciudades' ) ? colciu_get_ciudades() : array(),
		'money'           => array(
			'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'pos'      => get_option( 'woocommerce_currency_pos', 'left' ),
			'thousand' => wc_get_price_thousand_separator(),
			'decimal'  => wc_get_price_decimal_separator(),
			'decimals' => wc_get_price_decimals(),
		),
		'hold_hours'      => dox_pos_hold_hours(),
		'products'        => current_user_can( dox_pos_products_cap() ), // ¿Ve la pestaña "Productos"?
		'history_full'    => dox_pos_history_full(),                      // ¿Ve todo el historial, o solo sus ventas de hoy?
		'url'             => dox_pos_url(),
		'today'           => wp_date( 'Y-m-d' ),                          // El día de la tienda, para los periodos.
		'max_upload'      => (int) wp_max_upload_size(),
		'version'         => DOX_POS_VERSION,
	);
	return apply_filters( 'dox_pos_cfg', $cfg ); // Los añadidos meten lo suyo (el Pro: assistant, ai_ready, demo).
}
