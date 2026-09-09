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
		wp_die( esc_html__( 'The register needs WooCommerce to be active.', 'dox-pos' ) );
	}

	// Salir de la caja: el enlace lleva salir=1 y su nonce.
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
		} else {
			do_action( 'dox_pos_download', $what ); // Los Excel de los añadidos (el Pro: rendimiento). Quien lo sirve termina con exit.
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
		return __( 'The page expired. Please try again.', 'dox-pos' );
	}
	$user = wp_signon( array(), is_ssl() );
	if ( is_wp_error( $user ) ) {
		return __( 'Wrong username or password.', 'dox-pos' );
	}
	if ( ! user_can( $user, DOX_POS_CAP ) ) {
		wp_logout();
		return __( 'That user does not have access to the register.', 'dox-pos' );
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
 * Encola lo que usa la pantalla: la hoja de estilo con los colores de la marca como CSS en línea,
 * las fuentes (si el ajuste dice que se carguen) y, con $cfg, el JS de la caja con su configuración
 * delante. La caja es una página completa fuera del tema, así que no llama a wp_head() ni a
 * wp_footer() (arrastrarían todo lo del tema y de los demás plugins): imprime solo esta cola, con
 * wp_print_styles() en la cabecera y wp_print_scripts() al final de la plantilla.
 *
 * @param array|null $cfg Lo que va al navegador, o null en el login (que no lleva JS).
 */
function dox_pos_enqueue_caja( $cfg = null ) {
	$ver = DOX_POS_VERSION;
	if ( dox_pos_fonts_on() ) {
		wp_enqueue_style( 'dox-pos-fonts', dox_pos_fonts_url(), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- La hoja de Google lleva su propia versión.
	}
	wp_enqueue_style( 'dox-pos-caja', DOX_POS_URL . 'assets/css/caja.css', array(), $ver );
	wp_add_inline_style( 'dox-pos-caja', dox_pos_theme_css() );
	if ( null === $cfg ) {
		return;
	}
	wp_enqueue_script( 'dox-pos-caja', DOX_POS_URL . 'assets/js/caja.js', array( 'wp-i18n' ), $ver, true );
	wp_set_script_translations( 'dox-pos-caja', 'dox-pos', DOX_POS_PATH . 'languages' ); // Los textos del JS salen de languages/dox-pos-<idioma>-<md5>.json.
	wp_add_inline_script( 'dox-pos-caja', 'window.DOX_POS = ' . wp_json_encode( $cfg ) . ';', 'before' );
}

/**
 * Qué se imprime en la caja: lo encolado por el plugin y por sus añadidos (todo lo que empieza por
 * "dox-pos"), y nada más. La cola trae también lo de la barra de administración y lo de otros
 * plugins que encolan pronto, y esta pantalla es una página aparte: no tiene por qué cargarlo.
 * Un añadido con otro prefijo se apunta con el filtro dox_pos_assets.
 *
 * @param string $type style | script.
 * @return string[]
 */
function dox_pos_assets( $type ) {
	$q   = 'style' === $type ? wp_styles() : wp_scripts();
	$out = array();
	foreach ( (array) $q->queue as $handle ) {
		if ( 0 === strpos( $handle, 'dox-pos' ) ) {
			$out[] = $handle;
		}
	}
	return (array) apply_filters( 'dox_pos_assets', $out, $type );
}

/**
 * El <head> común de la caja y del login: título, el icono del sitio, el color de la barra del
 * navegador y las hojas de estilo ya encoladas.
 */
function dox_pos_head() {
	$colors = dox_pos_colors();
	?>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<meta name="theme-color" content="<?php echo esc_attr( $colors['bar'] ); ?>">
	<title><?php echo esc_html( dox_pos_screen_name() . ' · ' . dox_pos_brand_name() ); ?></title>
	<?php
	wp_site_icon();
	if ( dox_pos_fonts_on() ) {
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
	}
	wp_print_styles( dox_pos_assets( 'style' ) ); // Solo lo de la caja.
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
		'losses'          => dox_pos_can_see_losses(),                    // ¿Anota y ve las pérdidas de los pedidos? (administradores y gerentes)
		'costs'           => dox_pos_can_see_costs(),                     // ¿Ve costos y ganancia? (administradores y gerentes, con los costos encendidos)
		'url'             => dox_pos_url(),
		'today'           => wp_date( 'Y-m-d' ),                          // El día de la tienda, para los periodos.
		'max_upload'      => (int) wp_max_upload_size(),
		'max_px'          => (int) dox_pos_products_settings()['max_px'], // El lado mayor con el que se guardan las fotos: el teléfono ya las manda a esa medida.
		'version'         => DOX_POS_VERSION,
	);
	return apply_filters( 'dox_pos_cfg', $cfg ); // Los añadidos meten lo suyo (el Pro: assistant, ai_ready, demo).
}
