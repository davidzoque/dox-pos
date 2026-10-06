<?php
/**
 * La app del teléfono (Dox POS para iPhone y Android): cómo se conecta a la tienda.
 *
 * La app habla con la misma API REST que la caja (/wp-json/dox-pos/v1/), pero desde fuera del
 * navegador no tiene la cookie de la sesión. Entra con una contraseña de aplicación de WordPress
 * (las del núcleo desde la 5.6, una por teléfono, que se pueden quitar sin tocar la contraseña de
 * la cuenta). Nadie la escribe ni la copia: se consigue canjeando un código de un solo uso.
 *
 * Dos caminos, y los dos acaban en el mismo canje (POST /app/claim):
 *
 * 1. Desde la app ("Escribe la dirección de tu tienda"): la app abre la caja en el navegador con
 *    ?app=connect, un state y un code_challenge (PKCE, como OAuth para apps nativas). La persona entra
 *    como siempre, con contraseña o con código por correo, y pulsa "Conectar este teléfono". La caja
 *    vuelve a la app (doxpos://connected) con el código, que solo vale junto al verificador que la
 *    app guardó: si otra app del teléfono se queda con el enlace, no le sirve.
 * 2. Con un QR: la caja abierta en el computador enseña un código que la app escanea.
 *
 * No pasa por wp-admin a propósito: Hide My WP y otros cambian su dirección, y quien entra a la caja
 * con un código por correo (Dox Care) quizá no sepa su contraseña de WordPress.
 *
 * El código: 32 bytes al azar, guardado solo como hash, caduca a los 10 minutos y se gasta con un
 * UPDATE atómico (dos canjes a la vez: solo uno afecta la fila).
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOX_POS_APP_ID', '6b0f4d2e-8f3a-4c51-9e7d-2a1c5b8e3f90' ); // Marca las contraseñas de aplicación que creó la app.
define( 'DOX_POS_APP_SCHEME', 'doxpos' );
define( 'DOX_POS_APP_TTL', 10 * MINUTE_IN_SECONDS );

/**
 * La tabla de los códigos.
 *
 * @return string
 */
function dox_pos_app_table() {
	global $wpdb;
	return $wpdb->prefix . 'dox_pos_app_codes';
}

/**
 * ¿Puede conectarse la app en este sitio, y con este usuario? Devuelve ok o el motivo con un texto
 * que dice qué hacer, porque es lo primero que va a fallar en muchas tiendas: los plugins de
 * seguridad suelen apagar las contraseñas de aplicación, y WordPress solo las da con HTTPS.
 *
 * @param WP_User|null $user El usuario, o null para mirar solo el sitio.
 * @return array{ok:bool,reason:string,message:string}
 */
function dox_pos_app_status( $user = null ) {
	if ( ! function_exists( 'wp_is_application_passwords_supported' ) || ! wp_is_application_passwords_supported() ) {
		return array(
			'ok'      => false,
			'reason'  => 'https',
			'message' => __( 'The phone app needs your site on HTTPS (the padlock in the address bar). Ask your hosting to turn on the SSL certificate.', 'dox-pos' ),
		);
	}
	if ( ! wp_is_application_passwords_available() ) {
		return array(
			'ok'      => false,
			'reason'  => 'disabled',
			'message' => __( 'Another plugin turned off the application passwords of WordPress, and the phone app needs them. Security plugins do this (Wordfence, Hide My WP, iThemes, All-In-One Security): turn them back on in that plugin\'s settings.', 'dox-pos' ),
		);
	}
	if ( $user instanceof WP_User && ! wp_is_application_passwords_available_for_user( $user ) ) {
		return array(
			'ok'      => false,
			'reason'  => 'user',
			'message' => __( 'Application passwords are turned off for this user, and the phone app needs them. A security plugin can do this for some roles.', 'dox-pos' ),
		);
	}
	return array(
		'ok'      => true,
		'reason'  => '',
		'message' => '',
	);
}

/**
 * Lo que la app necesita saber de la tienda para conectarse y para pintarse.
 *
 * @return array
 */
function dox_pos_app_site_info() {
	return array(
		'name'     => dox_pos_brand_name(),
		'url'      => home_url( '/' ),
		'pos_url'  => dox_pos_url(),
		'connect'  => add_query_arg( 'app', 'connect', dox_pos_url() ),
		'logo'     => dox_pos_logo_url(),
		'currency' => get_woocommerce_currency(),
		'version'  => DOX_POS_VERSION,
		'locale'   => get_locale(),
	);
}

/**
 * Crea un código de un solo uso para un usuario. Devuelve el código en claro (la tabla guarda su hash).
 *
 * @param int    $user_id    Quién va a entrar en la app.
 * @param string $challenge  El code_challenge de la app (PKCE), o vacío para el QR.
 * @return string
 */
function dox_pos_app_new_code( $user_id, $challenge = '' ) {
	global $wpdb;
	$table = dox_pos_app_table();
	$now   = time();
	// Limpieza de paso: los que caducaron hace más de un día ya no explican nada.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', $table, gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$code = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64url del código, no ofusca nada.
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table,
		array(
			'code_hash'  => hash( 'sha256', $code ),
			'user_id'    => (int) $user_id,
			'created_by' => get_current_user_id(),
			'challenge'  => (string) $challenge,
			'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', $now + DOX_POS_APP_TTL ),
		)
	);
	return $code;
}

/**
 * El enlace que lleva el QR. Lo abre la app (lo lee su propio escáner).
 *
 * @param string $code El código.
 * @return string
 */
function dox_pos_app_link( $code ) {
	$link = DOX_POS_APP_SCHEME . '://connect?' . http_build_query(
		array(
			'site' => home_url( '/' ),
			'code' => $code,
		),
		'',
		'&',
		PHP_QUERY_RFC3986
	);
	return (string) apply_filters( 'dox_pos_app_link', $link, $code );
}

/**
 * Canjea un código por una contraseña de aplicación nueva.
 *
 * @param string $code     El código.
 * @param string $verifier El code_verifier de la app (solo si el código se pidió con challenge).
 * @param string $device   El nombre del teléfono, para reconocerlo en la lista.
 * @return array|WP_Error
 */
function dox_pos_app_claim( $code, $verifier, $device ) {
	global $wpdb;
	$bad = new WP_Error( 'dox_pos_app_code', __( 'This code is no longer valid. Make a new one in the register.', 'dox-pos' ), array( 'status' => 400 ) );
	if ( ! is_string( $code ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $code ) ) {
		return $bad;
	}
	$table = dox_pos_app_table();
	$hash  = hash( 'sha256', $code );
	$now   = gmdate( 'Y-m-d H:i:s' );
	$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE code_hash = %s', $table, $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( ! $row || null !== $row['used_at'] || $row['expires_at'] < $now ) {
		return $bad;
	}
	if ( '' !== $row['challenge'] ) {
		$calc = is_string( $verifier ) && preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ? rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		if ( '' === $calc || ! hash_equals( $row['challenge'], $calc ) ) {
			return $bad;
		}
	}
	// Se gasta antes de crear nada: si dos peticiones llegan a la vez, solo una cambia la fila.
	$spent = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_at = %s WHERE code_hash = %s AND used_at IS NULL AND expires_at >= %s', $table, $now, $hash, $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( 1 !== (int) $spent ) {
		return $bad;
	}
	$user = get_userdata( (int) $row['user_id'] );
	if ( ! $user || ! user_can( $user, DOX_POS_CAP ) ) {
		return new WP_Error( 'dox_pos_sin_permiso', __( 'Your user does not have access to the register.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	$status = dox_pos_app_status( $user );
	if ( ! $status['ok'] ) {
		return new WP_Error( 'dox_pos_app_' . $status['reason'], $status['message'], array( 'status' => 400 ) );
	}
	$device = trim( preg_replace( '/[^\p{L}\p{N} ._\'’()-]/u', '', (string) $device ) );
	$device = '' !== $device ? mb_substr( $device, 0, 60 ) : __( 'Phone', 'dox-pos' );
	$made   = WP_Application_Passwords::create_new_application_password(
		$user->ID,
		array(
			/* translators: %s: the phone's name, as the app sends it */
			'name'   => sprintf( __( 'Dox POS app · %s', 'dox-pos' ), $device ),
			'app_id' => DOX_POS_APP_ID,
		)
	);
	if ( is_wp_error( $made ) ) {
		return new WP_Error( $made->get_error_code(), $made->get_error_message(), array( 'status' => 400 ) );
	}
	return array(
		'user'     => $user->user_login,
		'password' => $made[0],
		'uuid'     => $made[1]['uuid'],
		'name'     => $user->display_name,
		'site'     => dox_pos_app_site_info(),
	);
}

/**
 * Los teléfonos conectados de un usuario: sus contraseñas de aplicación con la marca de la app.
 *
 * @param int $user_id El usuario.
 * @return array
 */
function dox_pos_app_devices( $user_id ) {
	$out = array();
	foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
		if ( DOX_POS_APP_ID !== ( $item['app_id'] ?? '' ) ) {
			continue;
		}
		$out[] = array(
			'uuid'      => $item['uuid'],
			'name'      => $item['name'],
			'created'   => (int) $item['created'],
			'last_used' => $item['last_used'] ? (int) $item['last_used'] : null,
		);
	}
	return $out;
}

/* ---------------------------------------------------------------- API REST */

add_action( 'rest_api_init', 'dox_pos_app_routes' );
function dox_pos_app_routes() {
	$ns = 'dox-pos/v1';
	// Pública: la app la pide al escribir la dirección, antes de tener cuenta. Solo dice lo que ya se ve en la caja.
	register_rest_route(
		$ns,
		'/app/info',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => fn() => rest_ensure_response( array_merge( dox_pos_app_site_info(), array( 'status' => dox_pos_app_status() ) ) ),
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		$ns,
		'/app/code',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dox_pos_rest_app_code',
			'permission_callback' => 'dox_pos_rest_permission',
			'args'                => array(
				'user' => array(
					'type'    => 'integer',
					'default' => 0,
				),
			),
		)
	);
	// Pública: quien la llama todavía no tiene cuenta en la app; la llave es el código.
	register_rest_route(
		$ns,
		'/app/claim',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dox_pos_rest_app_claim',
			'permission_callback' => '__return_true',
			'args'                => array(
				'code'     => array(
					'type'     => 'string',
					'required' => true,
				),
				'verifier' => array(
					'type'    => 'string',
					'default' => '',
				),
				'device'   => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);
	// La app la llama nada más conectarse: si el servidor se come la cabecera Authorization, aquí da 401.
	register_rest_route(
		$ns,
		'/app/me',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dox_pos_rest_app_me',
			'permission_callback' => 'dox_pos_rest_permission',
		)
	);
	register_rest_route(
		$ns,
		'/app/devices',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dox_pos_rest_app_devices',
			'permission_callback' => 'dox_pos_rest_permission',
		)
	);
	register_rest_route(
		$ns,
		'/app/devices/(?P<user>\d+)/(?P<uuid>[0-9a-f-]{36})',
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'dox_pos_rest_app_disconnect',
			'permission_callback' => 'dox_pos_rest_permission',
		)
	);
}

/**
 * Quién gestiona los teléfonos de los demás: quien entra a los ajustes de Dox POS.
 *
 * @return bool
 */
function dox_pos_app_can_manage() {
	return current_user_can( 'manage_woocommerce' );
}

function dox_pos_rest_app_code( WP_REST_Request $request ) {
	$uid = (int) $request->get_param( 'user' );
	$me  = get_current_user_id();
	if ( $uid && $uid !== $me ) {
		// El QR de otra persona (una cajera): solo quien gestiona la tienda, y para alguien que pueda usar la caja.
		if ( ! dox_pos_app_can_manage() ) {
			return new WP_Error( 'dox_pos_sin_permiso', __( 'Your user does not have access to the register.', 'dox-pos' ), array( 'status' => 403 ) );
		}
		$user = get_userdata( $uid );
		if ( ! $user || ! user_can( $user, DOX_POS_CAP ) ) {
			return new WP_Error( 'dox_pos_app_user', __( 'That user does not have access to the register.', 'dox-pos' ), array( 'status' => 400 ) );
		}
	} else {
		$user = wp_get_current_user();
	}
	$status = dox_pos_app_status( $user );
	if ( ! $status['ok'] ) {
		return new WP_Error( 'dox_pos_app_' . $status['reason'], $status['message'], array( 'status' => 400 ) );
	}
	$code = dox_pos_app_new_code( $user->ID );
	return rest_ensure_response(
		array(
			'link'       => dox_pos_app_link( $code ),
			'expires_in' => DOX_POS_APP_TTL,
			'user'       => $user->display_name,
		)
	);
}

function dox_pos_rest_app_claim( WP_REST_Request $request ) {
	$out = dox_pos_app_claim( $request->get_param( 'code' ), $request->get_param( 'verifier' ), $request->get_param( 'device' ) );
	return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
}

function dox_pos_rest_app_me() {
	$user = wp_get_current_user();
	return rest_ensure_response(
		array(
			'user' => array(
				'id'    => $user->ID,
				'login' => $user->user_login,
				'name'  => $user->display_name,
			),
			'site' => dox_pos_app_site_info(),
			'cfg'  => dox_pos_js_config(), // La misma configuración que recibe la caja del navegador.
		)
	);
}

function dox_pos_rest_app_devices() {
	$ids = dox_pos_app_can_manage() ? get_users(
		array(
			'capability' => DOX_POS_CAP,
			'fields'     => 'ID',
			'number'     => 200,
		)
	) : array( get_current_user_id() );
	$out = array();
	foreach ( $ids as $id ) {
		$devices = dox_pos_app_devices( (int) $id );
		if ( $devices ) {
			$u     = get_userdata( (int) $id );
			$out[] = array(
				'user'    => (int) $id,
				'name'    => $u ? $u->display_name : '',
				'devices' => $devices,
			);
		}
	}
	return rest_ensure_response( array( 'users' => $out ) );
}

function dox_pos_rest_app_disconnect( WP_REST_Request $request ) {
	$uid = (int) $request['user'];
	if ( $uid !== get_current_user_id() && ! dox_pos_app_can_manage() ) {
		return new WP_Error( 'dox_pos_sin_permiso', __( 'Your user does not have access to the register.', 'dox-pos' ), array( 'status' => 403 ) );
	}
	$item = WP_Application_Passwords::get_user_application_password( $uid, (string) $request['uuid'] );
	if ( ! $item || DOX_POS_APP_ID !== ( $item['app_id'] ?? '' ) ) {
		return new WP_Error( 'dox_pos_app_device', __( 'That phone is no longer connected.', 'dox-pos' ), array( 'status' => 404 ) );
	}
	WP_Application_Passwords::delete_application_password( $uid, $item['uuid'] );
	return rest_ensure_response( array( 'ok' => true ) );
}

/* ------------------------------------------------- "Conectar este teléfono" */

/**
 * La petición de la app que espera a que la persona entre: state y code_challenge. Llega en la URL
 * (?app=connect) y se guarda en una cookie corta, porque entrar (con contraseña o con código por
 * correo) recarga la caja sin los parámetros.
 *
 * @return array{state:string,challenge:string}|null
 */
function dox_pos_app_pending() {
	$cookie = 'dox_pos_app';
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Solo se lee qué pide la app; lo que escribe va con nonce.
	if ( isset( $_GET['app'] ) && 'connect' === $_GET['app'] ) {
		$state     = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$challenge = sanitize_text_field( wp_unslash( $_GET['challenge'] ?? '' ) );
		// phpcs:enable
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $state ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) ) {
			return null; // Sin PKCE no se conecta por aquí: lo pide la app, nunca un enlace suelto.
		}
		$value = $state . '.' . $challenge;
		setcookie(
			$cookie,
			$value,
			array(
				'expires'  => time() + 15 * MINUTE_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ $cookie ] = $value;
	}
	$raw = isset( $_COOKIE[ $cookie ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) : '';
	if ( ! preg_match( '/^([A-Za-z0-9_-]{8,128})\.([A-Za-z0-9_-]{43})$/', $raw, $m ) ) {
		return null;
	}
	return array(
		'state'     => $m[1],
		'challenge' => $m[2],
	);
}

function dox_pos_app_forget_pending() {
	setcookie(
		'dox_pos_app',
		'',
		array(
			'expires'  => time() - HOUR_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
	unset( $_COOKIE['dox_pos_app'] );
}

/**
 * Con la sesión abierta y una petición de la app pendiente: pregunta si se conecta, y al confirmar
 * vuelve a la app con el código. Pinta su propia página y termina; si no hay nada pendiente, vuelve.
 *
 * @param array $pending Lo de dox_pos_app_pending().
 */
function dox_pos_app_connect_screen( $pending ) {
	$user   = wp_get_current_user();
	$status = dox_pos_app_status( $user );
	$back   = '';
	$error  = $status['ok'] ? '' : $status['message'];
	$choice = isset( $_POST['dox_pos_app'] ) ? sanitize_key( wp_unslash( $_POST['dox_pos_app'] ) ) : '';
	if ( '' !== $choice ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'dox_pos_app_connect' ) ) {
			$error = __( 'The page expired. Please try again.', 'dox-pos' );
		} elseif ( 'cancel' === $choice ) {
			$back = DOX_POS_APP_SCHEME . '://connected?' . http_build_query(
				array(
					'error' => 'cancelled',
					'state' => $pending['state'],
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			);
		} elseif ( 'connect' === $choice && $status['ok'] ) {
			$back = DOX_POS_APP_SCHEME . '://connected?' . http_build_query(
				array(
					'code'  => dox_pos_app_new_code( $user->ID, $pending['challenge'] ),
					'state' => $pending['state'],
					'site'  => home_url( '/' ),
				),
				'',
				'&',
				PHP_QUERY_RFC3986
			);
		}
		if ( $back ) {
			dox_pos_app_forget_pending();
		}
	}
	$logo  = dox_pos_logo_url();
	$brand = dox_pos_brand_name();
	include DOX_POS_PATH . 'templates/app-connect.php';
	exit;
}
