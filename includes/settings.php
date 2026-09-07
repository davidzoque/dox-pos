<?php
/**
 * Los ajustes: WooCommerce > Dox POS. Todo lo que cambia de una tienda a otra:
 * la marca (nombre, logo, colores, fuentes), la pantalla (nombre y ruta), las
 * ventas (canales y formas de pago), los apartados (plazo y mensaje) y los
 * envíos (transportadoras). Los valores de fábrica son los de Rosella, la
 * primera tienda; para otra marca se cambian desde aquí, sin tocar código.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =====================================================================
 * Valores de fábrica
 * ===================================================================== */

/**
 * Los cinco colores base. El resto de tonos (bordes, fondos hundidos, texto
 * secundario) se derivan de estos, así que con cinco se viste toda la caja.
 */
function dox_pos_default_colors() {
	return array(
		'bg'      => '#FEF8F4', // Fondo.
		'bar'     => '#F4C1C0', // Barra superior.
		'primary' => '#731C2A', // Botones y pestaña activa.
		'soft'    => '#F4C1C0', // Etiquetas, burbuja de WhatsApp y fotos que faltan.
		'ink'     => '#38352F', // Texto.
	);
}

function dox_pos_default_fonts() {
	return array(
		'ui'    => 'Poppins',
		'serif' => 'Libre Baskerville',
	);
}

function dox_pos_default_channels() {
	return array(
		array( 'name' => 'WhatsApp', 'pickup' => false ),
		array( 'name' => 'Instagram', 'pickup' => false ),
		array( 'name' => __( 'En persona', 'dox-pos' ), 'pickup' => true ),
	);
}

/**
 * Las formas de pago que la caja sabe manejar. "paid" = queda pagado al registrar;
 * contraentrega no, y por eso el pedido se queda en "procesando" hasta que llega.
 */
function dox_pos_builtin_payments() {
	return array(
		'nequi'         => array( 'id' => 'dox_pos_nequi', 'title' => 'Nequi', 'paid' => true ),
		'transferencia' => array( 'id' => 'dox_pos_transfer', 'title' => __( 'Transferencia', 'dox-pos' ), 'paid' => true ),
		'contraentrega' => array( 'id' => 'cod', 'title' => __( 'Contraentrega', 'dox-pos' ), 'paid' => false ),
		'efectivo'      => array( 'id' => 'dox_pos_cash', 'title' => __( 'Efectivo', 'dox-pos' ), 'paid' => true ),
		'tarjeta'       => array( 'id' => 'dox_pos_card', 'title' => __( 'Tarjeta', 'dox-pos' ), 'paid' => true ),
	);
}

function dox_pos_default_hold_message() {
	return __( "Hola {nombre}, te aparté {productos} por {total}. Te lo guardo {horas} horas.\nPara confirmarlo puedes pagar aquí:\n{link}", 'dox-pos' );
}

/* =====================================================================
 * Lo que el resto del plugin pregunta
 * ===================================================================== */

function dox_pos_brand() {
	$b = get_option( 'dox_pos_brand', array() );
	return is_array( $b ) ? $b : array();
}

/**
 * El nombre de la marca: el del ajuste, o el del sitio sin su lema ("Rosella | Vistiendo con
 * Encanto" se queda en "Rosella").
 */
function dox_pos_brand_name() {
	$b = dox_pos_brand();
	if ( ! empty( $b['name'] ) ) {
		return (string) $b['name'];
	}
	$site = trim( (string) preg_split( '/\s+[|\x{2013}\x{2014}-]\s+/u', get_bloginfo( 'name' ), 2 )[0] );
	return $site ? $site : get_bloginfo( 'name' );
}

/**
 * Los cinco colores, ya validados; lo que falte lo pone el valor de fábrica.
 *
 * @return array<string,string>
 */
function dox_pos_colors() {
	$b   = dox_pos_brand();
	$set = isset( $b['colors'] ) && is_array( $b['colors'] ) ? $b['colors'] : array();
	$out = array();
	foreach ( dox_pos_default_colors() as $k => $default ) {
		$hex       = isset( $set[ $k ] ) ? dox_pos_hex( $set[ $k ] ) : '';
		$out[ $k ] = $hex ? $hex : $default;
	}
	return $out;
}

/**
 * Las dos fuentes (de Google Fonts): la de la interfaz y la de los títulos y totales.
 *
 * @return array{ui:string,serif:string}
 */
function dox_pos_fonts() {
	$b   = dox_pos_brand();
	$out = dox_pos_default_fonts();
	foreach ( $out as $k => $default ) {
		if ( ! empty( $b[ 'font_' . $k ] ) ) {
			$out[ $k ] = (string) $b[ 'font_' . $k ];
		}
	}
	return $out;
}

function dox_pos_screen() {
	$s = get_option( 'dox_pos_screen', array() );
	return is_array( $s ) ? $s : array();
}

/**
 * Cómo se llama la pantalla en la barra y en el título ("Caja").
 */
function dox_pos_screen_name() {
	$s = dox_pos_screen();
	return ! empty( $s['name'] ) ? (string) $s['name'] : __( 'Caja', 'dox-pos' );
}

/**
 * La ruta: dominio.com/<esto>/.
 */
function dox_pos_slug() {
	$s    = dox_pos_screen();
	$slug = ! empty( $s['slug'] ) ? sanitize_title( $s['slug'] ) : '';
	return $slug ? $slug : DOX_POS_SLUG;
}

function dox_pos_sales() {
	$s = get_option( 'dox_pos_sales', array() );
	return is_array( $s ) ? $s : array();
}

/**
 * Si la pestaña Pedidos enseña también los de la página web (y los hechos a mano en
 * WooCommerce). De fábrica sí; se apaga en Ajustes > Ventas.
 */
function dox_pos_show_web_orders() {
	$s = dox_pos_sales();
	return ! isset( $s['web_orders'] ) || ! empty( $s['web_orders'] );
}

/**
 * Los canales por los que entra una venta. "pickup" = se entrega en mano, sin envío.
 *
 * @return array<int,array{name:string,pickup:bool}>
 */
function dox_pos_channels() {
	$s   = dox_pos_sales();
	$out = array();
	foreach ( (array) ( $s['channels'] ?? array() ) as $ch ) {
		$name = sanitize_text_field( $ch['name'] ?? '' );
		if ( $name ) {
			$out[] = array( 'name' => $name, 'pickup' => ! empty( $ch['pickup'] ) );
		}
	}
	return $out ? $out : dox_pos_default_channels();
}

/**
 * Las formas de pago activas, con el título que se les puso.
 *
 * @return array<string,array{id:string,title:string,paid:bool}>
 */
function dox_pos_payment_methods() {
	$s   = dox_pos_sales();
	$set = isset( $s['payments'] ) && is_array( $s['payments'] ) ? $s['payments'] : null;
	$out = array();
	foreach ( dox_pos_builtin_payments() as $key => $m ) {
		if ( null === $set ) { // Sin ajuste guardado: todas.
			$out[ $key ] = $m;
			continue;
		}
		if ( empty( $set[ $key ]['on'] ) ) {
			continue;
		}
		if ( ! empty( $set[ $key ]['title'] ) ) {
			$m['title'] = sanitize_text_field( $set[ $key ]['title'] );
		}
		$out[ $key ] = $m;
	}
	return $out ? $out : dox_pos_builtin_payments();
}

/**
 * La forma de pago que sale marcada al abrir la caja.
 */
function dox_pos_default_payment() {
	$s       = dox_pos_sales();
	$methods = dox_pos_payment_methods();
	$key     = sanitize_key( $s['default_payment'] ?? '' );
	if ( $key && isset( $methods[ $key ] ) ) {
		return $key;
	}
	return isset( $methods['transferencia'] ) ? 'transferencia' : (string) array_key_first( $methods );
}

/**
 * Las transportadoras que se sugieren al marcar un pedido como enviado.
 *
 * @return string[]
 */
function dox_pos_carriers() {
	$s   = dox_pos_sales();
	$out = array();
	foreach ( (array) ( $s['carriers'] ?? array() ) as $c ) {
		$row  = is_array( $c ) ? $c : array( 'name' => $c ); // Los ajustes viejos guardaban solo el nombre.
		$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		if ( '' === $name ) {
			continue;
		}
		$url = trim( (string) ( $row['url'] ?? '' ) );
		if ( '' === $url ) {
			$url = dox_pos_carrier_presets()[ dox_pos_carrier_key( $name ) ]['url'] ?? '';
		}
		$out[] = array( 'name' => $name, 'url' => $url );
	}
	return $out;
}

/**
 * "Inter Rapidísimo", "interrapidisimo" e "Interrapidísimo" son la misma transportadora.
 */
function dox_pos_carrier_key( $name ) {
	return strtolower( preg_replace( '/[^a-z0-9]+/i', '', remove_accents( (string) $name ) ) );
}

/**
 * Las transportadoras conocidas con el enlace de rastreo que se sabe (comprobado el 5/09/2026:
 * Coordinadora acepta la guía en la dirección; Servientrega, Interrapidísimo y TCC la piden en
 * su página, así que el enlace lleva allí y el número va aparte). Sin enlace: solo el nombre.
 *
 * @return array<string,array{name:string,url:string}>
 */
function dox_pos_carrier_presets() {
	return array(
		'coordinadora'    => array( 'name' => 'Coordinadora', 'url' => 'https://rastreo.coordinadora.com/?guia={guia}' ),
		'servientrega'    => array( 'name' => 'Servientrega', 'url' => 'https://www.servientrega.com/wps/portal/rastreo-envio' ),
		'interrapidisimo' => array( 'name' => 'Interrapidísimo', 'url' => 'https://interrapidisimo.com/' ),
		'tcc'             => array( 'name' => 'TCC', 'url' => 'https://www.tcc.com.co/rastrear-envio/' ),
		'envia'           => array( 'name' => 'Envía', 'url' => '' ),
		'deprisa'         => array( 'name' => 'Deprisa', 'url' => '' ),
		'472'             => array( 'name' => '4-72', 'url' => '' ),
	);
}

/**
 * El enlace de rastreo de un envío: la plantilla de la transportadora (la de la tienda, o la
 * conocida) con la guía puesta donde va {guia}. Una plantilla sin {guia} es la página de
 * rastreo tal cual. Vacío si no hay transportadora con enlace.
 */
function dox_pos_tracking_url( $carrier, $guide ) {
	$key = dox_pos_carrier_key( $carrier );
	if ( '' === $key ) {
		return '';
	}
	$url = '';
	foreach ( dox_pos_carriers() as $c ) {
		if ( dox_pos_carrier_key( $c['name'] ) === $key ) {
			$url = $c['url'];
			break;
		}
	}
	if ( '' === $url ) {
		$url = dox_pos_carrier_presets()[ $key ]['url'] ?? '';
	}
	if ( '' === $url ) {
		return '';
	}
	$guide = trim( (string) $guide );
	if ( false !== strpos( $url, '{guia}' ) ) {
		return '' === $guide ? '' : str_replace( '{guia}', rawurlencode( $guide ), $url );
	}
	return $url;
}

/**
 * Si al marcar enviado se le manda un correo a la clienta (cuando el pedido tiene correo). De fábrica sí.
 */
function dox_pos_ship_email_on() {
	$s = dox_pos_sales();
	return ! isset( $s['ship_email'] ) || ! empty( $s['ship_email'] );
}

function dox_pos_default_ship_message() {
	return __( "Hola {nombre}, tu pedido #{pedido} de {tienda} ya salió con {transportadora}.\nGuía: {guia}\nPuedes seguirlo aquí: {link}", 'dox-pos' );
}

/**
 * La plantilla del mensaje de WhatsApp del envío, con sus {comodines}.
 */
function dox_pos_ship_message_template() {
	$t = trim( (string) get_option( 'dox_pos_ship_message', '' ) );
	return $t ? $t : dox_pos_default_ship_message();
}

/**
 * Horas que se guarda un apartado antes de liberarse solo.
 */
function dox_pos_hold_hours() {
	$h = (int) get_option( 'dox_pos_hold_hours', 48 );
	return $h > 0 ? $h : 48;
}

/**
 * Cómo pagar por fuera del link (número de cuenta, Nequi...). Va al final del mensaje.
 */
function dox_pos_payment_note() {
	return trim( (string) get_option( 'dox_pos_payment_note', '' ) );
}

/**
 * La plantilla del mensaje de WhatsApp del apartado, con sus {comodines}.
 */
function dox_pos_hold_message_template() {
	$t = trim( (string) get_option( 'dox_pos_hold_message', '' ) );
	return $t ? $t : dox_pos_default_hold_message();
}

/**
 * El país de la tienda (Ajustes de WooCommerce). Los departamentos y las ciudades salen de ahí.
 */
function dox_pos_country() {
	return function_exists( 'WC' ) ? (string) WC()->countries->get_base_country() : 'CO';
}

/**
 * El indicativo del país, sin el "+", para armar el enlace de WhatsApp.
 */
function dox_pos_calling_code() {
	if ( ! function_exists( 'WC' ) ) {
		return '57';
	}
	$code = WC()->countries->get_country_calling_code( dox_pos_country() );
	$code = is_array( $code ) ? reset( $code ) : $code;
	return preg_replace( '/\D/', '', (string) $code );
}

/**
 * Cómo llama WooCommerce al campo de departamento en este país ("Departamento", "Provincia"...).
 */
function dox_pos_state_label() {
	if ( function_exists( 'WC' ) ) {
		$locale = WC()->countries->get_country_locale();
		$c      = dox_pos_country();
		if ( ! empty( $locale[ $c ]['state']['label'] ) ) {
			return (string) $locale[ $c ]['state']['label'];
		}
	}
	return __( 'Departamento', 'dox-pos' );
}

/**
 * Un importe como texto plano ("$189.000"), con el formato de la tienda.
 */
function dox_pos_money( $amount ) {
	return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
}

/* =====================================================================
 * Colores: validar, mezclar y decidir el texto que va encima
 * ===================================================================== */

/**
 * Un color como #RRGGBB en mayúsculas, o vacío si no es un color.
 */
function dox_pos_hex( $value ) {
	$v = strtoupper( trim( (string) $value ) );
	if ( preg_match( '/^#?([0-9A-F]{6})$/', $v, $m ) ) {
		return '#' . $m[1];
	}
	if ( preg_match( '/^#?([0-9A-F])([0-9A-F])([0-9A-F])$/', $v, $m ) ) {
		return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
	}
	return '';
}

/**
 * Mezcla dos colores: $t es cuánto pesa el segundo (0 a 1).
 */
function dox_pos_mix( $a, $b, $t ) {
	$ca  = sscanf( ltrim( $a, '#' ), '%02x%02x%02x' );
	$cb  = sscanf( ltrim( $b, '#' ), '%02x%02x%02x' );
	$out = '#';
	for ( $i = 0; $i < 3; $i++ ) {
		$out .= sprintf( '%02X', (int) round( $ca[ $i ] * ( 1 - $t ) + $cb[ $i ] * $t ) );
	}
	return $out;
}

/**
 * ¿Es oscuro? Sirve para decidir si el texto que va encima es blanco o de color.
 */
function dox_pos_is_dark( $hex ) {
	list( $r, $g, $b ) = sscanf( ltrim( $hex, '#' ), '%02x%02x%02x' );
	$lin = function ( $c ) {
		$c /= 255;
		return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	};
	$l = 0.2126 * $lin( $r ) + 0.7152 * $lin( $g ) + 0.0722 * $lin( $b );
	return $l < 0.25;
}

/**
 * El <style> que viste la caja con los colores y fuentes del ajuste. Si los
 * colores son los de fábrica no se toca nada: caja.css ya los trae exactos.
 */
function dox_pos_theme_css() {
	$c    = dox_pos_colors();
	$f    = dox_pos_fonts();
	$vars = array(
		'--ui'    => '"' . $f['ui'] . '",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
		'--serif' => '"' . $f['serif'] . '",Georgia,serif',
	);
	if ( $c !== dox_pos_default_colors() ) {
		$dark_bar   = dox_pos_is_dark( $c['bar'] );
		$dark_prim  = dox_pos_is_dark( $c['primary'] );
		$dark_soft  = dox_pos_is_dark( $c['soft'] );
		$vars      += array(
			'--paper'       => $c['bg'],
			'--bar'         => $c['bar'],
			'--primary'     => $c['primary'],
			'--soft'        => $c['soft'],
			'--ink'         => $c['ink'],
			'--primary-ink' => $dark_prim ? '#FFFFFF' : $c['ink'],
			'--bar-ink'     => $dark_bar ? '#FFFFFF' : $c['primary'],
			'--soft-ink'    => $dark_soft ? '#FFFFFF' : $c['ink'],
			'--soft-accent' => $dark_soft ? '#FFFFFF' : $c['primary'],
			// Con la barra oscura, la pestaña activa se pinta al revés para que siempre se vea.
			'--tab-on'      => $dark_bar ? '#FFFFFF' : $c['primary'],
			'--tab-on-ink'  => $dark_bar ? $c['bar'] : ( $dark_prim ? '#FFFFFF' : $c['ink'] ),
			'--sunk'        => dox_pos_mix( $c['bg'], $c['ink'], 0.03 ),
			'--line'        => dox_pos_mix( $c['bg'], $c['ink'], 0.12 ),
			'--faint'       => dox_pos_mix( $c['bg'], $c['ink'], 0.5 ),
			'--muted'       => dox_pos_mix( $c['ink'], $c['bg'], 0.12 ),
			'--warn-soft'   => dox_pos_mix( $c['bg'], $c['ink'], 0.08 ),
			'--scrim'       => 'rgba(' . implode( ',', sscanf( ltrim( $c['ink'], '#' ), '%02x%02x%02x' ) ) . ',.45)',
		);
	}
	$css = '';
	foreach ( $vars as $k => $v ) {
		$css .= $k . ':' . $v . ';';
	}
	return ':root{' . $css . '}';
}

/**
 * La hoja de Google Fonts con las dos fuentes.
 */
function dox_pos_fonts_url() {
	$f = dox_pos_fonts();
	return 'https://fonts.googleapis.com/css2?family=' . str_replace( ' ', '+', $f['ui'] ) . ':wght@400;500;600;700&family=' . str_replace( ' ', '+', $f['serif'] ) . ':wght@400;700&display=swap';
}

/**
 * ¿Google Fonts conoce esa fuente? Se pregunta una vez al día por fuente; si no hay
 * red se da por buena para no bloquear el guardado.
 */
function dox_pos_google_font_exists( $family ) {
	$key   = 'dox_pos_font_' . md5( $family );
	$known = get_transient( $key );
	if ( false !== $known ) {
		return 'yes' === $known;
	}
	$r = wp_remote_get(
		'https://fonts.googleapis.com/css2?family=' . rawurlencode( $family ) . '&display=swap',
		array(
			'timeout'    => 6,
			'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
		)
	);
	if ( is_wp_error( $r ) ) {
		return true;
	}
	$ok = 200 === (int) wp_remote_retrieve_response_code( $r );
	set_transient( $key, $ok ? 'yes' : 'no', DAY_IN_SECONDS );
	return $ok;
}
/* =====================================================================
 * La página de ajustes (WooCommerce > Dox POS)
 *
 * Una aplicación dentro de wp-admin: cabecera fija con las cinco pestañas
 * (marca, pantalla, ventas, apartados, envíos), tarjetas y una vista previa
 * que cambia al momento. Guarda por el Settings API de siempre (options.php).
 * ===================================================================== */

add_action( 'admin_menu', 'dox_pos_admin_menu' );
function dox_pos_admin_menu() {
	add_submenu_page( 'woocommerce', 'Dox POS', 'Dox POS', 'manage_woocommerce', 'dox-pos', 'dox_pos_settings_page' );
}

// options.php pide manage_options si no se le dice otra cosa; los gerentes de tienda también guardan.
add_filter( 'option_page_capability_dox_pos', 'dox_pos_option_page_capability' );
function dox_pos_option_page_capability() {
	return 'manage_woocommerce';
}

// El enlace "Ajustes" en la lista de plugins.
add_filter( 'plugin_action_links_' . plugin_basename( DOX_POS_FILE ), 'dox_pos_action_links' );
function dox_pos_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=dox-pos' ) ) . '">' . esc_html__( 'Ajustes', 'dox-pos' ) . '</a>' );
	return $links;
}

add_action( 'admin_init', 'dox_pos_register_settings' );
function dox_pos_register_settings() {
	register_setting( 'dox_pos', 'dox_pos_brand', array( 'type' => 'array', 'sanitize_callback' => 'dox_pos_sanitize_brand', 'default' => array() ) );
	register_setting( 'dox_pos', 'dox_pos_logo', array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '' ) );
	register_setting( 'dox_pos', 'dox_pos_screen', array( 'type' => 'array', 'sanitize_callback' => 'dox_pos_sanitize_screen', 'default' => array() ) );
	register_setting( 'dox_pos', 'dox_pos_sales', array( 'type' => 'array', 'sanitize_callback' => 'dox_pos_sanitize_sales', 'default' => array() ) );
	register_setting( 'dox_pos', 'dox_pos_hold_hours', array( 'type' => 'integer', 'sanitize_callback' => 'dox_pos_sanitize_hours', 'default' => 48 ) );
	register_setting( 'dox_pos', 'dox_pos_hold_message', array( 'type' => 'string', 'sanitize_callback' => 'dox_pos_sanitize_hold_message', 'default' => '' ) );
	register_setting( 'dox_pos', 'dox_pos_ship_message', array( 'type' => 'string', 'sanitize_callback' => 'dox_pos_sanitize_ship_message', 'default' => '' ) );
	register_setting( 'dox_pos', 'dox_pos_payment_note', array( 'type' => 'string', 'sanitize_callback' => 'dox_pos_sanitize_note', 'default' => '' ) );
	register_setting( 'dox_pos', 'dox_pos_products', array( 'type' => 'array', 'sanitize_callback' => 'dox_pos_sanitize_products', 'default' => array() ) );
	register_setting( 'dox_pos', 'dox_pos_ai', array( 'type' => 'array', 'sanitize_callback' => 'dox_pos_sanitize_ai', 'default' => array() ) );
}

/**
 * Los ajustes de "Nuevo producto": calidad y tamaño del WebP, formato del código y los
 * atributos de talla y color. Vacío en los atributos = se detectan por el nombre.
 */
function dox_pos_sanitize_products( $in ) {
	$in   = is_array( $in ) ? $in : array();
	$all  = dox_pos_attribute_taxonomies();
	$q    = (int) ( $in['quality'] ?? 88 );
	$px   = (int) ( $in['max_px'] ?? 1600 );
	$sku  = (string) ( $in['sku'] ?? '' );
	$size = (string) ( $in['size_attr'] ?? '' );
	$col  = (string) ( $in['color_attr'] ?? '' );
	if ( $q < 50 || $q > 100 ) {
		add_settings_error( 'dox_pos', 'quality', __( 'La calidad va de 50 a 100. Se dejó en 88.', 'dox-pos' ) );
		$q = 88;
	}
	if ( $px < 800 || $px > 4000 ) {
		add_settings_error( 'dox_pos', 'max_px', __( 'El lado mayor va de 800 a 4000 px. Se dejó en 1600.', 'dox-pos' ) );
		$px = 1600;
	}
	delete_transient( 'dox_pos_product_form' );
	return array(
		'quality'    => $q,
		'max_px'     => $px,
		'sku'        => in_array( $sku, array( 'codes', 'slugs', 'none' ), true ) ? $sku : 'codes',
		'size_attr'  => isset( $all[ $size ] ) ? $size : '',
		'color_attr' => isset( $all[ $col ] ) ? $col : '',
	);
}

function dox_pos_sanitize_brand( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$old = dox_pos_brand();
	$out = array( 'name' => sanitize_text_field( $in['name'] ?? '' ), 'colors' => array() );
	foreach ( dox_pos_default_colors() as $k => $default ) {
		$out['colors'][ $k ] = dox_pos_hex( $in['colors'][ $k ] ?? '' ) ?: $default;
	}
	foreach ( dox_pos_default_fonts() as $k => $default ) {
		$font = sanitize_text_field( $in[ 'font_' . $k ] ?? '' );
		$font = trim( preg_replace( '/\s+/', ' ', $font ) );
		if ( '' === $font || $font === $default ) {
			$out[ 'font_' . $k ] = '';
			continue;
		}
		$was = (string) ( $old[ 'font_' . $k ] ?? '' );
		if ( $font !== $was && ! dox_pos_google_font_exists( $font ) ) {
			add_settings_error( 'dox_pos', 'font_' . $k, sprintf( /* translators: %s: nombre de la fuente */ __( 'Google Fonts no conoce la fuente "%s". Copia el nombre tal como aparece en fonts.google.com. Se dejó la anterior.', 'dox-pos' ), $font ) );
			$font = $was;
		}
		$out[ 'font_' . $k ] = $font;
	}
	return $out;
}

/**
 * ¿Se puede usar esa ruta? Devuelve el motivo si no, o vacío si sí. Lo usan el
 * guardado y la comprobación en vivo mientras se escribe.
 *
 * @param string $slug La ruta, ya pasada por sanitize_title.
 * @return string
 */
function dox_pos_slug_problem( $slug ) {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return __( 'Escribe una ruta: letras, números y guiones.', 'dox-pos' );
	}
	$taken = in_array( $slug, array( 'wp-admin', 'wp-login', 'wp-json', 'wp-content', 'wp-includes', 'feed', 'shop', 'tienda', 'cart', 'carrito', 'checkout', 'finalizar-compra', 'my-account', 'mi-cuenta' ), true );
	if ( ! $taken ) {
		$perma = (array) get_option( 'woocommerce_permalinks', array() );
		foreach ( array( 'product_base', 'category_base', 'tag_base' ) as $k ) {
			if ( $slug === trim( (string) ( $perma[ $k ] ?? '' ), '/' ) ) {
				$taken = true;
			}
		}
	}
	if ( ! $taken && get_page_by_path( $slug, OBJECT, array( 'page', 'post', 'product' ) ) ) {
		$taken = true;
	}
	if ( $taken ) {
		return sprintf( /* translators: %s: ruta */ __( 'La ruta /%s/ ya la usa otra página.', 'dox-pos' ), $slug );
	}
	return '';
}

function dox_pos_sanitize_screen( $in ) {
	$in   = is_array( $in ) ? $in : array();
	$old  = dox_pos_screen();
	$slug = sanitize_title( $in['slug'] ?? '' );
	$prev = ! empty( $old['slug'] ) ? sanitize_title( $old['slug'] ) : DOX_POS_SLUG;
	if ( '' === $slug ) {
		$slug = DOX_POS_SLUG;
	}
	if ( $slug !== $prev ) {
		$problem = dox_pos_slug_problem( $slug );
		if ( $problem ) {
			add_settings_error( 'dox_pos', 'slug', $problem . ' ' . __( 'Se dejó la anterior.', 'dox-pos' ) );
			$slug = $prev;
		} else {
			update_option( 'dox_pos_flush', 1 ); // En la próxima carga se reescriben las rutas con la nueva.
		}
	}
	return array(
		'name' => sanitize_text_field( $in['name'] ?? '' ),
		'slug' => $slug,
	);
}

function dox_pos_sanitize_sales( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$out = array( 'channels' => array(), 'payments' => array(), 'default_payment' => '', 'carriers' => array() );

	foreach ( (array) ( $in['channels'] ?? array() ) as $ch ) {
		$name = sanitize_text_field( is_array( $ch ) ? ( $ch['name'] ?? '' ) : '' );
		if ( '' === $name ) {
			continue;
		}
		$out['channels'][] = array( 'name' => $name, 'pickup' => ! empty( $ch['pickup'] ) );
	}
	if ( ! $out['channels'] ) {
		add_settings_error( 'dox_pos', 'channels', __( 'Hace falta al menos un canal de venta. Se pusieron los de fábrica.', 'dox-pos' ) );
		$out['channels'] = dox_pos_default_channels();
	}

	$set = (array) ( $in['payments'] ?? array() );
	$any = false;
	foreach ( dox_pos_builtin_payments() as $key => $m ) {
		$on                      = ! empty( $set[ $key ]['on'] );
		$any                     = $any || $on;
		$out['payments'][ $key ] = array(
			'on'    => $on,
			'title' => sanitize_text_field( $set[ $key ]['title'] ?? '' ) ?: $m['title'],
		);
	}
	if ( ! $any ) {
		add_settings_error( 'dox_pos', 'payments', __( 'Hace falta al menos una forma de pago. Se activaron todas.', 'dox-pos' ) );
		foreach ( $out['payments'] as &$p ) {
			$p['on'] = true;
		}
		unset( $p );
	}
	$default = sanitize_key( $in['default_payment'] ?? '' );
	if ( ! $default || empty( $out['payments'][ $default ]['on'] ) ) {
		foreach ( $out['payments'] as $key => $p ) {
			if ( $p['on'] ) {
				$default = $key;
				break;
			}
		}
	}
	$out['default_payment'] = $default;
	$out['web_orders']      = ! empty( $in['web_orders'] );

	// Transportadoras: nombre y enlace de rastreo. El {guia} se protege, que esc_url se lo comería.
	$carriers = is_array( $in['carriers'] ?? null ) ? $in['carriers'] : preg_split( '/\r\n|\r|\n/', (string) ( $in['carriers'] ?? '' ) );
	$seen     = array();
	foreach ( (array) $carriers as $c ) {
		$row  = is_array( $c ) ? $c : array( 'name' => $c );
		$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		$key  = dox_pos_carrier_key( $name );
		if ( '' === $key || isset( $seen[ $key ] ) ) {
			continue;
		}
		$url = trim( (string) ( $row['url'] ?? '' ) );
		if ( '' !== $url ) {
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				$url = 'https://' . $url;
			}
			$url = str_replace( '__GUIA__', '{guia}', esc_url_raw( str_replace( array( '{guia}', '%7Bguia%7D' ), '__GUIA__', $url ) ) );
			if ( ! preg_match( '#^https://#i', $url ) ) {
				/* translators: %s: transportadora */
				add_settings_error( 'dox_pos', 'carrier_url', sprintf( __( 'El enlace de %s no vale: tiene que empezar por https://', 'dox-pos' ), $name ) );
				$url = '';
			}
		}
		$seen[ $key ]      = true;
		$out['carriers'][] = array( 'name' => $name, 'url' => $url );
	}
	$out['ship_email'] = ! empty( $in['ship_email'] );
	return $out;
}

function dox_pos_sanitize_hours( $v ) {
	$h = absint( $v );
	return $h > 0 ? $h : 48;
}

function dox_pos_sanitize_hold_message( $v ) {
	$v = str_replace( "\r\n", "\n", sanitize_textarea_field( (string) $v ) ); // El navegador manda \r\n; el mensaje va con \n.
	return trim( $v ) === trim( dox_pos_default_hold_message() ) ? '' : $v;
}

function dox_pos_sanitize_ship_message( $v ) {
	$v = str_replace( "\r\n", "\n", sanitize_textarea_field( (string) $v ) );
	return trim( $v ) === trim( dox_pos_default_ship_message() ) ? '' : $v;
}

function dox_pos_sanitize_note( $v ) {
	return str_replace( "\r\n", "\n", sanitize_textarea_field( (string) $v ) );
}

/**
 * La comprobación de la ruta en vivo: /wp-json/dox-pos/v1/settings/slug?slug=...
 */
add_action( 'rest_api_init', 'dox_pos_register_settings_routes' );
function dox_pos_register_settings_routes() {
	register_rest_route(
		'dox-pos/v1',
		'/settings/slug',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dox_pos_rest_slug_check',
			'permission_callback' => 'dox_pos_rest_settings_permission',
			'args'                => array(
				'slug' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}

function dox_pos_rest_settings_permission() {
	return current_user_can( 'manage_woocommerce' );
}

function dox_pos_rest_slug_check( WP_REST_Request $request ) {
	$slug    = sanitize_title( $request->get_param( 'slug' ) );
	$current = dox_pos_slug();
	$problem = $slug === $current ? '' : dox_pos_slug_problem( $slug );
	return rest_ensure_response(
		array(
			'slug'    => $slug,
			'ok'      => '' === $problem,
			'current' => $slug === $current,
			'message' => $problem,
		)
	);
}

/**
 * Los avisos del guardado (los propios y el "Ajustes guardados" de WordPress),
 * listos para que el JS los enseñe como notificaciones y junto al campo.
 *
 * @return array<int,array{code:string,type:string,message:string}>
 */
function dox_pos_settings_notices() {
	$out = array();
	foreach ( get_settings_errors() as $e ) {
		$out[] = array(
			'code'    => (string) $e['code'],
			'type'    => 'settings_updated' === $e['code'] ? 'success' : (string) $e['type'],
			'message' => wp_strip_all_tags( (string) $e['message'] ),
		);
	}
	return $out;
}

/**
 * Fuentes de Google que se sugieren al escribir. Se puede escribir cualquier otra.
 *
 * @return string[]
 */
function dox_pos_font_suggestions() {
	return array( 'Inter', 'Poppins', 'Montserrat', 'DM Sans', 'Manrope', 'Plus Jakarta Sans', 'Outfit', 'Nunito', 'Lato', 'Roboto', 'Open Sans', 'Work Sans', 'Raleway', 'Josefin Sans', 'Quicksand', 'Karla', 'Figtree', 'Sora', 'Libre Baskerville', 'Playfair Display', 'Cormorant Garamond', 'EB Garamond', 'Lora', 'Merriweather', 'DM Serif Display', 'Fraunces', 'Cinzel', 'Italiana' );
}

/**
 * Quiénes pueden entrar a la caja: administradores, gerentes de tienda y el rol "Caja".
 *
 * @return array{users:array<int,array{name:string,role:string,initials:string}>,total:int}
 */
function dox_pos_users_with_access( $limit = 6 ) {
	$roles = array( 'administrator', 'shop_manager', 'caja' );
	$names = wp_roles()->get_names();
	$all   = get_users( array( 'role__in' => $roles, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => 'ID' ) );
	$users = get_users( array( 'role__in' => $roles, 'orderby' => 'display_name', 'order' => 'ASC', 'number' => $limit ) );
	$out   = array();
	foreach ( $users as $u ) {
		$role = '';
		foreach ( $roles as $r ) {
			if ( in_array( $r, (array) $u->roles, true ) ) {
				$role = isset( $names[ $r ] ) ? translate_user_role( $names[ $r ] ) : $r;
				break;
			}
		}
		$name  = $u->display_name ? $u->display_name : $u->user_login;
		$parts = preg_split( '/\s+/', trim( $name ) );
		$ini   = mb_strtoupper( mb_substr( $parts[0], 0, 1 ) . ( isset( $parts[1] ) ? mb_substr( $parts[1], 0, 1 ) : '' ) );
		$out[] = array( 'name' => $name, 'role' => $role, 'initials' => $ini );
	}
	return array( 'users' => $out, 'total' => count( $all ) );
}

/**
 * Un icono de línea (estilo Lucide), en SVG dentro del HTML: sin peticiones ni emojis.
 *
 * @param string $name  Cuál.
 * @param string $class Clases extra.
 * @return string
 */
function dox_pos_icon( $name, $class = '' ) {
	$paths = array(
		'palette'  => '<circle cx="13.5" cy="6.5" r=".9"/><circle cx="17.5" cy="10.5" r=".9"/><circle cx="8.5" cy="7.5" r=".9"/><circle cx="6.5" cy="12.5" r=".9"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.6-.7 1.6-1.6 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.6 1.6-1.6H16c3.3 0 6-2.7 6-6 0-4.9-4.5-8.6-10-8.6z"/>',
		'screen'   => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
		'bag'      => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
		'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
		'truck'    => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
		'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		'plus'     => '<path d="M5 12h14"/><path d="M12 5v14"/>',
		'trash'    => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
		'grip'     => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
		'check'    => '<path d="M20 6 9 17l-5-5"/>',
		'x'        => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
		'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>',
		'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
		'info'     => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
		'undo'     => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
		'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
		'chat'     => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 21l1.9-5.2A8.4 8.4 0 0 1 3 11.5a8.4 8.4 0 0 1 9-8.4 8.4 8.4 0 0 1 9 8.4z"/>',
		'alert'    => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'pin'      => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
		'coins'    => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
		'tag'      => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1"/>',
		'camera'   => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/>',
		'sparkle'  => '<path d="M12 3l1.9 5.6 5.6 1.9-5.6 1.9L12 18l-1.9-5.6L4.5 10.5l5.6-1.9z"/><path d="M19 2v4"/><path d="M17 4h4"/><path d="M5 18v3"/><path d="M3.5 19.5h3"/>',
		'mail'     => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}
	return '<svg class="dp-i' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
}

add_action( 'admin_enqueue_scripts', 'dox_pos_admin_assets' );
function dox_pos_admin_assets( $hook ) {
	if ( 'woocommerce_page_dox-pos' !== $hook ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'dox-pos-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_enqueue_style( 'dox-pos-fonts', dox_pos_fonts_url(), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	$ver = DOX_POS_VERSION . '.' . (int) filemtime( DOX_POS_PATH . 'assets/css/ajustes.css' ) . (int) filemtime( DOX_POS_PATH . 'assets/js/ajustes.js' ); // Con la fecha: un cambio nunca se queda en la caché.
	wp_enqueue_style( 'dox-pos-ajustes', DOX_POS_URL . 'assets/css/ajustes.css', array(), $ver );
	wp_enqueue_script( 'dox-pos-ajustes', DOX_POS_URL . 'assets/js/ajustes.js', array( 'jquery' ), $ver, true );

	$home = home_url( '/' );
	wp_localize_script(
		'dox-pos-ajustes',
		'DOX_POS_AJUSTES',
		array(
			'rest'     => esc_url_raw( rest_url( 'dox-pos/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'home'     => $home,
			'homeHost' => preg_replace( '#^https?://#', '', untrailingslashit( $home ) ),
			'slug'     => dox_pos_slug(),
			'carrierPresets' => dox_pos_carrier_presets(),
			'defaults' => array(
				'colors'  => dox_pos_default_colors(),
				'fonts'   => dox_pos_default_fonts(),
				'message' => dox_pos_default_hold_message(),
				'shipMessage' => dox_pos_default_ship_message(),
				'screen'  => __( 'Caja', 'dox-pos' ),
				'slug'    => DOX_POS_SLUG,
				'hours'   => 48,
			),
			'siteLogo' => ( (int) get_theme_mod( 'custom_logo' ) ) ? wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'full' ) : '',
			'siteName' => dox_pos_brand_name(),
			'sample'   => array(
				'name'     => __( 'Ana', 'dox-pos' ),
				'products' => __( 'Vestido Ella (M · Rosa)', 'dox-pos' ),
				'total'    => dox_pos_money( 189000 ),
				'link'     => add_query_arg( array( 'pay_for_order' => 'true', 'key' => 'wc_order_ejemplo' ), wc_get_endpoint_url( 'order-pay', 1234, wc_get_checkout_url() ) ),
			),
			'notices'  => dox_pos_settings_notices(),
			'i18n'     => array(
				'pickLogo'  => __( 'Logo de la caja', 'dox-pos' ),
				'use'       => __( 'Usar esta imagen', 'dox-pos' ),
				'channel'   => __( 'Nombre del canal', 'dox-pos' ),
				'pickup'    => __( 'En mano', 'dox-pos' ),
				'pickupLong' => __( 'Se entrega en mano, sin envío', 'dox-pos' ),
				'remove'    => __( 'Quitar', 'dox-pos' ),
				'reorder'   => __( 'Arrastra para ordenar, o usa las flechas del teclado', 'dox-pos' ),
				'checking'  => __( 'Comprobando…', 'dox-pos' ),
				'carrierName' => __( 'Transportadora', 'dox-pos' ),
				'carrierUrl' => __( 'Enlace de rastreo', 'dox-pos' ),
				'demoWorking' => __( 'Un momento: se están creando los pedidos de ejemplo…', 'dox-pos' ),
				'demoRemoving' => __( 'Quitando los pedidos de ejemplo…', 'dox-pos' ),
				'demoConfirm' => __( '¿Quitar los datos de demostración? Se borran los pedidos de ejemplo; las existencias no cambian.', 'dox-pos' ),
				'slugOk'    => __( 'Disponible.', 'dox-pos' ),
				'slugSame'  => __( 'Es la ruta actual.', 'dox-pos' ),
				'fontOk'    => __( 'Google Fonts la tiene.', 'dox-pos' ),
				'fontBad'   => __( 'Google Fonts no conoce esa fuente. Copia el nombre tal como sale en fonts.google.com.', 'dox-pos' ),
				'saving'    => __( 'Guardando…', 'dox-pos' ),
				'unsaved'   => __( 'Hay cambios sin guardar.', 'dox-pos' ),
				'close'     => __( 'Cerrar', 'dox-pos' ),
				'removeTag' => __( 'Quitar %s', 'dox-pos' ),
			),
		)
	);
}

/**
 * La página.
 */
function dox_pos_settings_page() {
	$brand    = dox_pos_brand();
	$colors   = dox_pos_colors();
	$defaults = dox_pos_default_colors();
	$fonts    = dox_pos_fonts();
	$screen   = dox_pos_screen();
	$sales    = dox_pos_sales();
	$channels = dox_pos_channels();
	$payments = dox_pos_builtin_payments();
	$active   = dox_pos_payment_methods();
	$default  = dox_pos_default_payment();
	$logo     = dox_pos_logo_url();
	$slug     = dox_pos_slug();
	$carriers = dox_pos_carriers();
	$access   = dox_pos_users_with_access( 6 );
	$country  = dox_pos_country();
	$states   = function_exists( 'WC' ) ? (array) WC()->countries->get_states( $country ) : array();
	$cname    = function_exists( 'WC' ) && isset( WC()->countries->countries[ $country ] ) ? WC()->countries->countries[ $country ] : $country;
	$zones    = class_exists( 'WC_Shipping_Zones' ) ? count( WC_Shipping_Zones::get_zones() ) : 0;
	$host     = preg_replace( '#^https?://#', '', untrailingslashit( home_url( '/' ) ) );
	$products = dox_pos_products_settings();
	$praw     = (array) get_option( 'dox_pos_products', array() ); // Lo guardado tal cual: vacío = automático.
	$attrs    = dox_pos_attribute_taxonomies();
	$heic     = class_exists( 'Imagick' ) && in_array( 'HEIC', (array) Imagick::queryFormats( 'HEIC' ), true );
	$ai       = dox_pos_ai_settings();
	$ai_hint  = dox_pos_ai_key_hint();
	$ai_use   = dox_pos_ai_month_usage();
	$ai_price = dox_pos_ai_prices( $ai['model'] );
	$ai_next  = dox_pos_ai_next_summary();
	$ai_to    = implode( ', ', dox_pos_ai_recipients() );
	$tabs     = array(
		'marca'     => array( __( 'Marca', 'dox-pos' ), 'palette' ),
		'pantalla'  => array( __( 'Pantalla', 'dox-pos' ), 'screen' ),
		'ventas'    => array( __( 'Ventas', 'dox-pos' ), 'bag' ),
		'apartados' => array( __( 'Apartados', 'dox-pos' ), 'clock' ),
		'envios'    => array( __( 'Envíos', 'dox-pos' ), 'truck' ),
		'productos' => array( __( 'Productos', 'dox-pos' ), 'tag' ),
		'asistente' => array( __( 'Asistente', 'dox-pos' ), 'sparkle' ),
	);
	$skufmt   = array(
		'codes' => array( __( 'Como la tienda: padre + talla + color', 'dox-pos' ), __( 'Dos dígitos por talla y dos por color, aprendidos de los productos que ya existen (VE83 + 01 + 13 = VE830113). Sin color, un 0.', 'dox-pos' ) ),
		'slugs' => array( __( 'Padre, talla y color con guiones', 'dox-pos' ), __( 'VE83-6-12-meses-rosa. Se lee de un vistazo; más largo.', 'dox-pos' ) ),
		'none'  => array( __( 'Sin código en las variaciones', 'dox-pos' ), __( 'Solo el producto lleva código; las variaciones, ninguno.', 'dox-pos' ) ),
	);
	$labels   = array(
		'bg'      => __( 'Fondo', 'dox-pos' ),
		'bar'     => __( 'Barra', 'dox-pos' ),
		'primary' => __( 'Principal', 'dox-pos' ),
		'soft'    => __( 'Suave', 'dox-pos' ),
		'ink'     => __( 'Texto', 'dox-pos' ),
	);
	$hints    = array(
		'bg'      => __( 'Toda la pantalla', 'dox-pos' ),
		'bar'     => __( 'Logo y pestañas', 'dox-pos' ),
		'primary' => __( 'Botones y elegido', 'dox-pos' ),
		'soft'    => __( 'Etiquetas y burbuja', 'dox-pos' ),
		'ink'     => __( 'Las letras', 'dox-pos' ),
	);
	$kses_a   = array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'code' => array(), 'b' => array() );
	?>
	<div class="dp-app" id="dp-app">
		<header class="dp-head">
			<div class="dp-head-row">
				<div class="dp-brandmark">
					<span class="dp-mark" aria-hidden="true">D</span>
					<h1 class="dp-title">Dox POS <span class="dp-pill"><?php echo esc_html( DOX_POS_VERSION ); ?></span></h1>
				</div>
				<div class="dp-head-actions">
					<a class="dp-btn dp-btn-ghost dp-urlchip" href="<?php echo esc_url( dox_pos_url() ); ?>" target="_blank" rel="noopener"><?php echo dox_pos_icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $host . '/' . $slug ); ?></span></a>
					<button type="button" class="dp-btn dp-btn-ghost dp-discard" id="dp-discard"><?php echo dox_pos_icon( 'undo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Descartar', 'dox-pos' ); ?></button>
					<button type="submit" form="dp-form" class="dp-btn dp-btn-primary" id="dp-save"><span class="dp-dot" aria-hidden="true"></span><span class="dp-save-text"><?php esc_html_e( 'Guardar cambios', 'dox-pos' ); ?></span></button>
				</div>
			</div>
			<nav class="dp-tabbar" role="tablist" aria-label="<?php esc_attr_e( 'Secciones de los ajustes', 'dox-pos' ); ?>">
				<?php foreach ( $tabs as $id => $t ) : ?>
				<button type="button" role="tab" class="dp-tab-btn" id="dp-tab-<?php echo esc_attr( $id ); ?>" data-tab="<?php echo esc_attr( $id ); ?>" aria-selected="false" aria-controls="dp-panel-<?php echo esc_attr( $id ); ?>" tabindex="-1"><?php echo dox_pos_icon( $t[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php echo esc_html( $t[0] ); ?></span></button>
				<?php endforeach; ?>
				<i class="dp-ind" aria-hidden="true"></i>
			</nav>
		</header>
		<div class="dp-foreign"><hr class="wp-header-end" hidden></div>

		<div class="dp-toasts" id="dp-toasts" aria-live="polite"></div>

		<div class="dp-body">
			<form method="post" action="options.php" class="dp-form" id="dp-form" novalidate>
				<?php settings_fields( 'dox_pos' ); ?>

				<!-- ===================== Marca ===================== -->
				<section class="dp-panel" id="dp-panel-marca" data-panel="marca" role="tabpanel" aria-labelledby="dp-tab-marca" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Identidad', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Lo que ve quien abre la caja: el nombre en la pestaña del navegador y en la entrada, y el logo sobre la barra.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dp-name"><?php esc_html_e( 'Nombre de la marca', 'dox-pos' ); ?></label>
							<input type="text" id="dp-name" name="dox_pos_brand[name]" value="<?php echo esc_attr( $brand['name'] ?? '' ); ?>" class="dp-input" placeholder="<?php echo esc_attr( dox_pos_brand_name() ); ?>" autocomplete="off">
							<p class="dp-hint"><?php esc_html_e( 'Vacío: el nombre del sitio, sin el lema.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field">
							<span class="dp-label" id="dp-logo-label"><?php esc_html_e( 'Logo', 'dox-pos' ); ?></span>
							<div class="dp-logo" role="group" aria-labelledby="dp-logo-label">
								<div class="dp-logo-tile" id="dp-logo-tile">
									<img id="dp-logo-img" src="<?php echo esc_url( $logo ); ?>" alt="" <?php echo $logo ? '' : 'hidden'; ?>>
									<span class="dp-logo-empty" id="dp-logo-empty" <?php echo $logo ? 'hidden' : ''; ?>><?php echo dox_pos_icon( 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Sin logo: sale el nombre', 'dox-pos' ); ?></span>
								</div>
								<div class="dp-logo-actions">
									<input type="hidden" id="dox_pos_logo" name="dox_pos_logo" value="<?php echo esc_attr( get_option( 'dox_pos_logo', '' ) ); ?>">
									<button type="button" class="dp-btn dp-btn-soft" id="dp-logo-pick"><?php echo dox_pos_icon( 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Elegir de la biblioteca', 'dox-pos' ); ?></button>
									<button type="button" class="dp-btn dp-btn-link" id="dp-logo-clear"><?php esc_html_e( 'Usar el del sitio', 'dox-pos' ); ?></button>
									<p class="dp-hint"><?php esc_html_e( 'Va sobre la barra: si la barra es oscura, conviene una versión clara. Vacío: el logo de Apariencia > Personalizar.', 'dox-pos' ); ?></p>
								</div>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Colores', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Cinco colores visten toda la caja; los tonos de bordes y fondos se derivan de ellos. El texto sobre la barra y los botones se decide solo según lo oscuro que sea el fondo.', 'dox-pos' ); ?></p>
							<button type="button" class="dp-btn dp-btn-link dp-card-action" id="dp-colors-reset" disabled><?php echo dox_pos_icon( 'undo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Volver a los del sitio', 'dox-pos' ); ?></button>
						</div>
						<div class="dp-swatches">
							<?php foreach ( $labels as $k => $label ) : ?>
							<div class="dp-swatch" data-key="<?php echo esc_attr( $k ); ?>" data-default="<?php echo esc_attr( $defaults[ $k ] ); ?>" style="--sw:<?php echo esc_attr( $colors[ $k ] ); ?>">
								<label class="dp-swatch-well" for="dp-pick-<?php echo esc_attr( $k ); ?>">
									<input type="color" id="dp-pick-<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $colors[ $k ] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: nombre del color */ __( 'Elegir el color: %s', 'dox-pos' ), $label ) ); ?>">
								</label>
								<div class="dp-swatch-meta">
									<label class="dp-swatch-name" for="dp-color-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label>
									<input type="text" id="dp-color-<?php echo esc_attr( $k ); ?>" class="dp-input dp-hex" name="dox_pos_brand[colors][<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $colors[ $k ] ); ?>" maxlength="7" spellcheck="false" autocomplete="off">
									<span class="dp-swatch-hint"><?php echo esc_html( $hints[ $k ] ); ?></span>
								</div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Fuentes', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Dos fuentes de Google Fonts: una para la interfaz y otra para el total y los títulos. Escribe el nombre tal como sale en fonts.google.com; la vista previa la carga al momento.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-grid-2">
							<div class="dp-field">
								<label class="dp-label" for="dp-font-ui"><?php esc_html_e( 'Interfaz', 'dox-pos' ); ?></label>
								<input type="text" id="dp-font-ui" name="dox_pos_brand[font_ui]" value="<?php echo esc_attr( $brand['font_ui'] ?? '' ); ?>" class="dp-input" placeholder="<?php echo esc_attr( dox_pos_default_fonts()['ui'] ); ?>" list="dp-font-list" autocomplete="off">
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php echo esc_html( sprintf( /* translators: %s: fuente de fábrica */ __( 'Vacío: %s.', 'dox-pos' ), dox_pos_default_fonts()['ui'] ) ); ?></p>
							</div>
							<div class="dp-field">
								<label class="dp-label" for="dp-font-serif"><?php esc_html_e( 'Totales y títulos', 'dox-pos' ); ?></label>
								<input type="text" id="dp-font-serif" name="dox_pos_brand[font_serif]" value="<?php echo esc_attr( $brand['font_serif'] ?? '' ); ?>" class="dp-input" placeholder="<?php echo esc_attr( dox_pos_default_fonts()['serif'] ); ?>" list="dp-font-list" autocomplete="off">
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php echo esc_html( sprintf( /* translators: %s: fuente de fábrica */ __( 'Vacío: %s.', 'dox-pos' ), dox_pos_default_fonts()['serif'] ) ); ?></p>
							</div>
						</div>
						<datalist id="dp-font-list">
							<?php foreach ( dox_pos_font_suggestions() as $f ) : ?>
							<option value="<?php echo esc_attr( $f ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
					</div>
				</section>

				<!-- ===================== Pantalla ===================== -->
				<section class="dp-panel" id="dp-panel-pantalla" data-panel="pantalla" role="tabpanel" aria-labelledby="dp-tab-pantalla" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Nombre y dirección', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Cómo se llama la pantalla y en qué dirección se abre. La dirección se puede cambiar; si la cambias, avisa a quien la tenga guardada en el teléfono.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dp-screen"><?php esc_html_e( 'Nombre de la pantalla', 'dox-pos' ); ?></label>
							<input type="text" id="dp-screen" name="dox_pos_screen[name]" value="<?php echo esc_attr( $screen['name'] ?? '' ); ?>" class="dp-input" placeholder="<?php esc_attr_e( 'Caja', 'dox-pos' ); ?>" autocomplete="off">
							<p class="dp-hint"><?php esc_html_e( 'La palabra junto al logo y en el título de la pestaña. Vacío: Caja.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dp-slug"><?php esc_html_e( 'Dirección de la caja', 'dox-pos' ); ?></label>
							<div class="dp-urlfield">
								<span class="dp-urlfield-pre"><?php echo esc_html( $host ); ?>/</span>
								<input type="text" id="dp-slug" name="dox_pos_screen[slug]" value="<?php echo esc_attr( $slug ); ?>" class="dp-input" spellcheck="false" autocomplete="off" autocapitalize="off" pattern="[a-z0-9-]+">
								<span class="dp-urlfield-post">/</span>
							</div>
							<p class="dp-status" id="dp-slug-status" aria-live="polite"></p>
							<p class="dp-hint"><?php esc_html_e( 'Solo letras, números y guiones. Se comprueba al momento que no la use otra página; el cambio se aplica al guardar.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-callout">
							<?php echo dox_pos_icon( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div>
								<b><?php esc_html_e( 'Ahora mismo la caja está en', 'dox-pos' ); ?> <a href="<?php echo esc_url( dox_pos_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $host . '/' . $slug . '/' ); ?></a></b>
								<span><?php esc_html_e( 'En el teléfono: abrirla en el navegador y "Añadir a pantalla de inicio". Queda como una app.', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Quién entra', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Entran los administradores, los gerentes de tienda y los usuarios con el rol "Caja". Quien solo tiene el rol Caja no ve el escritorio de WordPress: entra y va directo a la caja.', 'dox-pos' ); ?></p>
							<a class="dp-btn dp-btn-soft dp-card-action" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"><?php echo dox_pos_icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Nuevo usuario', 'dox-pos' ); ?></a>
						</div>
						<ul class="dp-people">
							<?php foreach ( $access['users'] as $u ) : ?>
							<li><span class="dp-avatar" aria-hidden="true"><?php echo esc_html( $u['initials'] ); ?></span><span class="dp-person"><b><?php echo esc_html( $u['name'] ); ?></b><i><?php echo esc_html( $u['role'] ); ?></i></span></li>
							<?php endforeach; ?>
						</ul>
						<?php if ( $access['total'] > count( $access['users'] ) ) : ?>
						<p class="dp-hint"><a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: usuarios */ _n( 'Ver el usuario que falta', 'Ver los %d usuarios', $access['total'], 'dox-pos' ), $access['total'] ) ); ?></a></p>
						<?php endif; ?>
					</div>
				</section>

				<!-- ===================== Ventas ===================== -->
				<section class="dp-panel" id="dp-panel-ventas" data-panel="ventas" role="tabpanel" aria-labelledby="dp-tab-ventas" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Por dónde entra la venta', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'El primero es el que sale marcado. Los canales "en mano" no preguntan ciudad ni envío. Arrastra para ordenar.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-rows" id="dp-canales">
							<?php foreach ( $channels as $i => $ch ) : ?>
							<div class="dp-row">
								<button type="button" class="dp-grip" aria-label="<?php esc_attr_e( 'Arrastra para ordenar, o usa las flechas del teclado', 'dox-pos' ); ?>"><?php echo dox_pos_icon( 'grip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
								<input type="text" name="dox_pos_sales[channels][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $ch['name'] ); ?>" class="dp-input" placeholder="<?php esc_attr_e( 'Nombre del canal', 'dox-pos' ); ?>" aria-label="<?php esc_attr_e( 'Nombre del canal', 'dox-pos' ); ?>">
								<label class="dp-switch" title="<?php esc_attr_e( 'Se entrega en mano, sin envío', 'dox-pos' ); ?>"><input type="checkbox" role="switch" name="dox_pos_sales[channels][<?php echo (int) $i; ?>][pickup]" value="1" <?php checked( $ch['pickup'] ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'En mano', 'dox-pos' ); ?></span></label>
								<button type="button" class="dp-iconbtn dp-quitar" aria-label="<?php esc_attr_e( 'Quitar', 'dox-pos' ); ?>"><?php echo dox_pos_icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
							</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="dp-btn dp-btn-soft" id="dp-canal-add"><?php echo dox_pos_icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Añadir canal', 'dox-pos' ); ?></button>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Formas de pago', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Apaga las que no uses y ponles el nombre que quieras. La marcada "por defecto" sale elegida al abrir la caja.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-pays" id="dp-pagos">
							<?php foreach ( $payments as $key => $m ) : ?>
							<div class="dp-pay<?php echo isset( $active[ $key ] ) ? '' : ' off'; ?>" data-key="<?php echo esc_attr( $key ); ?>">
								<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_sales[payments][<?php echo esc_attr( $key ); ?>][on]" value="1" <?php checked( isset( $active[ $key ] ) ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( $m['title'] ); ?></span></label>
								<div class="dp-pay-main">
									<input type="text" name="dox_pos_sales[payments][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $active[ $key ]['title'] ?? ( $sales['payments'][ $key ]['title'] ?? $m['title'] ) ); ?>" class="dp-input" placeholder="<?php echo esc_attr( $m['title'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: forma de pago */ __( 'Nombre de %s', 'dox-pos' ), $m['title'] ) ); ?>">
									<span class="dp-hint"><?php echo $m['paid'] ? esc_html__( 'Queda pagado al registrar.', 'dox-pos' ) : esc_html__( 'Queda "por enviar" y se cobra al entregar.', 'dox-pos' ); ?></span>
								</div>
								<label class="dp-def"><input type="radio" name="dox_pos_sales[default_payment]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $default, $key ); ?> <?php disabled( ! isset( $active[ $key ] ) ); ?>><span class="dp-def-on"><?php echo dox_pos_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Por defecto', 'dox-pos' ); ?></span><span class="dp-def-off"><?php esc_html_e( 'Hacer por defecto', 'dox-pos' ); ?></span></label>
							</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Pedidos de la página web', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'La pestaña Pedidos enseña también lo que compran en la tienda en línea, con la etiqueta "Página web" y de dónde llegó el cliente, para marcarlo enviado o entregado desde el teléfono igual que una venta de la caja.', 'dox-pos' ); ?></p>
						</div>
						<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_sales[web_orders]" value="1" <?php checked( dox_pos_show_web_orders() ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'Mostrar los pedidos de la web en la caja', 'dox-pos' ); ?></span></label>
					</div>
				</section>

				<!-- ===================== Apartados ===================== -->
				<section class="dp-panel" id="dp-panel-apartados" data-panel="apartados" role="tabpanel" aria-labelledby="dp-tab-apartados" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Plazo', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Un apartado deja el producto reservado. Si no paga en el plazo, se cancela solo y el producto vuelve al inventario.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field dp-field-short">
							<label class="dp-label" for="dox_pos_hold_hours"><?php esc_html_e( 'Se guarda durante', 'dox-pos' ); ?></label>
							<div class="dp-unitfield">
								<input type="number" min="1" max="720" step="1" id="dox_pos_hold_hours" name="dox_pos_hold_hours" value="<?php echo esc_attr( dox_pos_hold_hours() ); ?>" class="dp-input" inputmode="numeric">
								<span class="dp-unit"><?php esc_html_e( 'horas', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Mensaje de WhatsApp', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Se abre ya escrito en WhatsApp al apartar; solo hay que enviarlo. Toca un comodín para insertarlo donde está el cursor.', 'dox-pos' ); ?></p>
							<button type="button" class="dp-btn dp-btn-link dp-card-action" id="dp-message-reset"><?php echo dox_pos_icon( 'undo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Mensaje de fábrica', 'dox-pos' ); ?></button>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dox_pos_hold_message"><?php esc_html_e( 'Texto', 'dox-pos' ); ?></label>
							<div class="dp-chips" id="dp-placeholders" aria-label="<?php esc_attr_e( 'Comodines', 'dox-pos' ); ?>">
								<?php foreach ( array( 'nombre', 'productos', 'total', 'horas', 'link', 'tienda' ) as $p ) : ?>
								<button type="button" class="dp-chip" data-insert="{<?php echo esc_attr( $p ); ?>}">{<?php echo esc_html( $p ); ?>}</button>
								<?php endforeach; ?>
							</div>
							<textarea id="dox_pos_hold_message" name="dox_pos_hold_message" rows="5" class="dp-input dp-textarea"><?php echo esc_textarea( dox_pos_hold_message_template() ); ?></textarea>
							<p class="dp-hint"><?php echo wp_kses( __( '<code>{link}</code> es el enlace de pago del pedido y <code>{tienda}</code>, el nombre de la marca.', 'dox-pos' ), $kses_a ); ?></p>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dox_pos_payment_note"><?php esc_html_e( 'Cómo pagar por fuera del link', 'dox-pos' ); ?></label>
							<textarea id="dox_pos_payment_note" name="dox_pos_payment_note" rows="2" class="dp-input dp-textarea" placeholder="<?php esc_attr_e( 'O por Nequi al 300 123 4567', 'dox-pos' ); ?>"><?php echo esc_textarea( dox_pos_payment_note() ); ?></textarea>
							<p class="dp-hint"><?php esc_html_e( 'Se añade al final del mensaje, tal cual. Vacío: solo va el link.', 'dox-pos' ); ?></p>
						</div>
					</div>
				</section>

				<!-- ===================== Envíos ===================== -->
				<section class="dp-panel" id="dp-panel-envios" data-panel="envios" role="tabpanel" aria-labelledby="dp-tab-envios" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Transportadoras', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Se sugieren al marcar un pedido como enviado (también se puede escribir otra en el momento). El enlace de rastreo va en el correo y en el WhatsApp a la clienta: pon {guia} donde la transportadora espera el número; si su página no lo admite, deja el enlace de la página de rastreo y el número va aparte.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-rows dp-carriers" id="dp-carriers">
							<?php foreach ( $carriers as $i => $c ) : ?>
							<div class="dp-carrier dp-row">
								<input type="text" class="dp-input" data-k="name" name="dox_pos_sales[carriers][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="<?php esc_attr_e( 'Transportadora', 'dox-pos' ); ?>" aria-label="<?php esc_attr_e( 'Transportadora', 'dox-pos' ); ?>">
								<input type="text" class="dp-input" data-k="url" name="dox_pos_sales[carriers][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $c['url'] ); ?>" placeholder="https://… {guia}" aria-label="<?php esc_attr_e( 'Enlace de rastreo', 'dox-pos' ); ?>" inputmode="url" autocomplete="off">
								<button type="button" class="dp-carrier-x" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: transportadora */ __( 'Quitar %s', 'dox-pos' ), $c['name'] ) ); ?>"><?php echo dox_pos_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
							</div>
							<?php endforeach; ?>
						</div>
						<div class="dp-carrier-add">
							<select id="dp-carrier-preset" class="dp-input" aria-label="<?php esc_attr_e( 'Añadir una transportadora conocida', 'dox-pos' ); ?>">
								<option value=""><?php esc_html_e( 'Añadir una conocida…', 'dox-pos' ); ?></option>
								<?php foreach ( dox_pos_carrier_presets() as $key => $p ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $p['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="dp-btn dp-btn-ghost" id="dp-carrier-add"><?php esc_html_e( 'Otra transportadora', 'dox-pos' ); ?></button>
						</div>
						<p class="dp-hint"><?php esc_html_e( 'Coordinadora acepta la guía en el enlace. Servientrega, Interrapidísimo y TCC la piden en su página: el enlace lleva allí. Cualquier otra: su nombre y, si la tiene, su enlace con {guia}.', 'dox-pos' ); ?></p>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Aviso a la clienta', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Al marcar un pedido como enviado, si tiene correo le llega uno con la transportadora, la guía y el enlace de rastreo, con el diseño de los correos de la tienda y desde su remitente. Si tiene teléfono, la caja deja listo el mensaje de WhatsApp.', 'dox-pos' ); ?></p>
						</div>
						<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_sales[ship_email]" value="1" <?php checked( dox_pos_ship_email_on() ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'Correo a la clienta al marcar enviado', 'dox-pos' ); ?></span></label>
						<div class="dp-field dp-mt">
							<label class="dp-label" for="dox_pos_ship_message"><?php esc_html_e( 'Mensaje de WhatsApp del envío', 'dox-pos' ); ?></label>
							<div class="dp-chips" id="dp-ship-placeholders" aria-label="<?php esc_attr_e( 'Comodines', 'dox-pos' ); ?>">
								<?php foreach ( array( 'nombre', 'pedido', 'transportadora', 'guia', 'link', 'productos', 'tienda' ) as $p ) : ?>
								<button type="button" class="dp-chip" data-insert="{<?php echo esc_attr( $p ); ?>}">{<?php echo esc_html( $p ); ?>}</button>
								<?php endforeach; ?>
							</div>
							<textarea id="dox_pos_ship_message" name="dox_pos_ship_message" rows="4" class="dp-input dp-textarea"><?php echo esc_textarea( dox_pos_ship_message_template() ); ?></textarea>
							<p class="dp-hint"><?php esc_html_e( 'Una línea cuyo comodín quede vacío (sin guía, sin enlace) se quita sola.', 'dox-pos' ); ?> <button type="button" class="dp-btn dp-btn-link" id="dp-ship-reset"><?php esc_html_e( 'Mensaje de fábrica', 'dox-pos' ); ?></button></p>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Costos y zonas', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'La caja no tiene tarifas propias: cobra el envío igual que el checkout, con las zonas de WooCommerce. Y el país, los departamentos y las ciudades salen de los ajustes de la tienda.', 'dox-pos' ); ?></p>
							<a class="dp-btn dp-btn-soft dp-card-action" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>"><?php echo dox_pos_icon( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Zonas de envío', 'dox-pos' ); ?></a>
						</div>
						<dl class="dp-facts">
							<div><dt><?php echo dox_pos_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'País', 'dox-pos' ); ?></dt><dd><?php echo esc_html( $cname ); ?> <i><?php echo esc_html( sprintf( /* translators: 1: etiqueta (Departamento), 2: cuántos */ __( '%1$s: %2$d', 'dox-pos' ), dox_pos_state_label(), count( $states ) ) ); ?></i></dd></div>
							<div><dt><?php echo dox_pos_icon( 'coins' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Moneda', 'dox-pos' ); ?></dt><dd><?php echo esc_html( get_woocommerce_currency() ); ?> <i><?php echo esc_html( sprintf( /* translators: %s: importe de ejemplo */ __( 'se escribe %s', 'dox-pos' ), dox_pos_money( 12000 ) ) ); ?></i></dd></div>
							<div><dt><?php echo dox_pos_icon( 'truck' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Zonas', 'dox-pos' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: zonas */ _n( '%d zona de envío', '%d zonas de envío', $zones, 'dox-pos' ), $zones ) ); ?></dd></div>
							<div><dt><?php echo dox_pos_icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Ciudades', 'dox-pos' ); ?></dt><dd><?php echo function_exists( 'colciu_get_ciudades' ) ? esc_html__( 'Con lista para elegir (Colciudades)', 'dox-pos' ) : esc_html__( 'Se escriben a mano', 'dox-pos' ); ?></dd></div>
						</dl>
					</div>
				</section>

				<!-- ===================== Productos ===================== -->
				<section class="dp-panel" id="dp-panel-productos" data-panel="productos" role="tabpanel" aria-labelledby="dp-tab-productos" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Fotos', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Las fotos que se suben desde la caja se reducen y se guardan como WebP en el servidor, con sus tamaños; la original (JPG, PNG o HEIC del iPhone) no se guarda.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-grid-2">
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-quality"><?php esc_html_e( 'Calidad del WebP', 'dox-pos' ); ?></label>
								<div class="dp-unitfield">
									<input type="number" min="50" max="100" step="1" id="dp-quality" name="dox_pos_products[quality]" value="<?php echo esc_attr( $products['quality'] ); ?>" class="dp-input" inputmode="numeric">
									<span class="dp-unit">%</span>
								</div>
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php esc_html_e( 'De fábrica, 88: una foto de 12 MP del iPhone queda en unos 300 KB (a 82, en 210). Por encima de 92 casi no se nota y pesa mucho más.', 'dox-pos' ); ?></p>
							</div>
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-maxpx"><?php esc_html_e( 'Lado mayor', 'dox-pos' ); ?></label>
								<div class="dp-unitfield">
									<input type="number" min="800" max="4000" step="100" id="dp-maxpx" name="dox_pos_products[max_px]" value="<?php echo esc_attr( $products['max_px'] ); ?>" class="dp-input" inputmode="numeric">
									<span class="dp-unit">px</span>
								</div>
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php esc_html_e( 'De fábrica, 1600: de sobra para la ficha y el zoom. Las fotos más pequeñas se dejan como están.', 'dox-pos' ); ?></p>
							</div>
						</div>
						<div class="dp-callout">
							<?php echo dox_pos_icon( $heic ? 'check' : 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div>
								<b><?php echo $heic ? esc_html__( 'Este servidor lee fotos HEIC', 'dox-pos' ) : esc_html__( 'Este servidor no lee fotos HEIC', 'dox-pos' ); ?></b>
								<span><?php echo $heic ? esc_html__( 'Las del iPhone entran tal cual y salen en WebP.', 'dox-pos' ) : esc_html__( 'Hace falta ImageMagick con libheif. Mientras tanto, el iPhone manda JPG si se elige "Más compatible" en Ajustes > Cámara > Formatos.', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Tallas y colores', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Los atributos de WooCommerce con los que la caja arma las variaciones. Se detectan solos por el nombre; cámbialos si la tienda los llama de otra forma.', 'dox-pos' ); ?></p>
						</div>
						<?php if ( ! $attrs ) : ?>
						<p class="dp-hint"><?php esc_html_e( 'La tienda no tiene atributos globales todavía (Productos > Atributos). Sin ellos, la caja crea productos de talla única.', 'dox-pos' ); ?></p>
						<?php else : ?>
						<div class="dp-grid-2">
							<?php foreach ( array( 'size_attr' => __( 'Talla', 'dox-pos' ), 'color_attr' => __( 'Color', 'dox-pos' ) ) as $k => $label ) : ?>
							<div class="dp-field">
								<label class="dp-label" for="dp-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label>
								<select id="dp-<?php echo esc_attr( $k ); ?>" name="dox_pos_products[<?php echo esc_attr( $k ); ?>]" class="dp-input">
									<option value=""><?php echo esc_html( $products[ $k ] ? sprintf( /* translators: %s: atributo detectado */ __( 'Automático: %s', 'dox-pos' ), $attrs[ $products[ $k ] ] ) : __( 'Automático: ninguno', 'dox-pos' ) ); ?></option>
									<?php foreach ( $attrs as $tax => $alabel ) : ?>
									<option value="<?php echo esc_attr( $tax ); ?>" <?php selected( $praw[ $k ] ?? '', $tax ); ?>><?php echo esc_html( $alabel . ' (' . $tax . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'El código (SKU)', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'La caja propone el siguiente código libre del prefijo que usa la categoría (si el último vestido es VE82, propone VE83) y arma el de cada variación así:', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-pays" id="dp-skufmt">
							<?php foreach ( $skufmt as $key => $t ) : ?>
							<label class="dp-pay dp-radio">
								<input type="radio" name="dox_pos_products[sku]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $products['sku'], $key ); ?>>
								<span class="dp-radio-ui" aria-hidden="true"></span>
								<span class="dp-pay-main"><b><?php echo esc_html( $t[0] ); ?></b><span class="dp-hint"><?php echo esc_html( $t[1] ); ?></span></span>
							</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Quién crea productos', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'La pestaña "Productos" (crear uno nuevo o editar uno) la ven los administradores y los gerentes de tienda. Quien solo tiene el rol Caja vende y registra mercancía, pero no toca los productos.', 'dox-pos' ); ?></p>
							<a class="dp-btn dp-btn-soft dp-card-action" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php echo dox_pos_icon( 'users' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Usuarios', 'dox-pos' ); ?></a>
						</div>
					</div>
				</section>

				<!-- ===================== Asistente ===================== -->
				<section class="dp-panel" id="dp-panel-asistente" data-panel="asistente" role="tabpanel" aria-labelledby="dp-tab-asistente" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'La clave de OpenAI', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'El asistente de la caja usa la API de OpenAI para redactar el resumen diario, los consejos y las descripciones de producto, y para responder en el chat. Sin clave sigue enseñando los pendientes, la revisión y los consejos por reglas. La clave se guarda solo en este servidor: nunca sale al navegador.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dp-ai-key"><?php esc_html_e( 'Clave (API key)', 'dox-pos' ); ?></label>
							<input type="password" id="dp-ai-key" name="dox_pos_ai[key]" value="" class="dp-input" placeholder="sk-…" autocomplete="new-password" spellcheck="false">
							<p class="dp-status <?php echo $ai_hint ? 'ok' : 'wait'; ?>" id="dp-ai-key-status"><?php echo $ai_hint ? esc_html( sprintf( /* translators: %s: últimas letras */ __( 'Hay una clave guardada que termina en %s. Pega otra solo para cambiarla.', 'dox-pos' ), $ai_hint ) ) : esc_html__( 'Sin clave todavía: el chat y la redacción están apagados.', 'dox-pos' ); ?></p>
							<p class="dp-hint"><?php echo wp_kses( __( 'Se crea en <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com › API keys</a>, con una cuenta que tenga crédito cargado. Empieza por "sk-".', 'dox-pos' ), $kses_a ); ?></p>
						</div>
						<?php if ( $ai_hint ) : ?>
						<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_ai[forget]" value="1"><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'Quitar la clave guardada al guardar los cambios', 'dox-pos' ); ?></span></label>
						<?php endif; ?>
						<div class="dp-callout">
							<?php echo dox_pos_icon( 'sparkle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div>
								<b><?php esc_html_e( 'Probar la conexión', 'dox-pos' ); ?></b>
								<span><?php esc_html_e( 'Manda una llamada mínima con la clave guardada y dice cuánto tardó y cuánto costó. Guarda primero si acabas de pegarla.', 'dox-pos' ); ?></span>
								<p class="dp-status" id="dp-ai-test-status" aria-live="polite"></p>
								<button type="button" class="dp-btn dp-btn-soft dp-ai-test" id="dp-ai-test" <?php disabled( ! $ai_hint ); ?>><?php esc_html_e( 'Probar ahora', 'dox-pos' ); ?></button>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Modelo y tope de gasto', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'De fábrica, GPT-5.6 Luna: el más barato de OpenAI que llama funciones y lee fotos. El tope frena las llamadas cuando el gasto del mes llega a esa cifra; el uso normal de una tienda queda muy por debajo.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-grid-2">
							<div class="dp-field">
								<label class="dp-label" for="dp-ai-model"><?php esc_html_e( 'Modelo', 'dox-pos' ); ?></label>
								<input type="text" id="dp-ai-model" name="dox_pos_ai[model]" value="<?php echo esc_attr( $ai['model'] ); ?>" class="dp-input" list="dp-ai-models" autocomplete="off" spellcheck="false" autocapitalize="off">
								<datalist id="dp-ai-models">
									<?php foreach ( array( 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol', 'gpt-6-astra', 'gpt-5-mini' ) as $mdl ) : ?>
									<option value="<?php echo esc_attr( $mdl ); ?>"></option>
									<?php endforeach; ?>
								</datalist>
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php esc_html_e( 'El nombre tal como sale en la lista de modelos de OpenAI. Vacío: gpt-5.6-luna.', 'dox-pos' ); ?></p>
							</div>
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-ai-cap"><?php esc_html_e( 'Tope al mes', 'dox-pos' ); ?></label>
								<div class="dp-unitfield">
									<input type="number" min="0.5" max="1000" step="0.5" id="dp-ai-cap" name="dox_pos_ai[cap]" value="<?php echo esc_attr( $ai['cap'] ); ?>" class="dp-input" inputmode="decimal">
									<span class="dp-unit">USD</span>
								</div>
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php esc_html_e( 'Al llegar, el asistente deja de llamar a OpenAI hasta el mes siguiente y lo dice en pantalla.', 'dox-pos' ); ?></p>
							</div>
						</div>
						<dl class="dp-facts">
							<div><dt><?php echo dox_pos_icon( 'coins' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Este mes', 'dox-pos' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $ai_use['cost'], 2 ) . ' USD' ); ?> <i><?php echo esc_html( sprintf( /* translators: 1: llamadas, 2: fallidas */ _n( '%1$d llamada', '%1$d llamadas', $ai_use['calls'], 'dox-pos' ), $ai_use['calls'] ) . ( $ai_use['failed'] ? ' · ' . sprintf( /* translators: %d: fallidas */ __( '%d con error', 'dox-pos' ), $ai_use['failed'] ) : '' ) ); ?></i></dd></div>
							<div><dt><?php echo dox_pos_icon( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Precio del modelo', 'dox-pos' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $ai_price['in'], 2 ) . ' / ' . number_format_i18n( $ai_price['out'], 2 ) . ' USD' ); ?> <i><?php echo $ai_price['known'] ? esc_html__( 'por millón de tokens de entrada y de salida (lista de OpenAI)', 'dox-pos' ) : esc_html__( 'estimado: ese modelo no está en la lista conocida', 'dox-pos' ); ?></i></dd></div>
						</dl>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Resumen diario', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Cada mañana, un correo con cómo fue ayer, lo pendiente de hoy (pedidos atrasados, pagos por confirmar, apartados que vencen), lo que se agota de lo que se vende y un consejo. El mismo resumen queda en la pestaña Asistente de la caja.', 'dox-pos' ); ?></p>
						</div>
						<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_ai[summary_on]" value="1" <?php checked( $ai['summary_on'] ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'Enviar el resumen cada día', 'dox-pos' ); ?></span></label>
						<div class="dp-grid-2 dp-mt">
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-ai-hour"><?php esc_html_e( 'A las', 'dox-pos' ); ?></label>
								<select id="dp-ai-hour" name="dox_pos_ai[summary_hour]" class="dp-input">
									<?php for ( $hh = 0; $hh < 24; $hh++ ) : ?>
									<option value="<?php echo (int) $hh; ?>" <?php selected( $ai['summary_hour'], $hh ); ?>><?php echo esc_html( $hh . ':00' ); ?></option>
									<?php endfor; ?>
								</select>
								<p class="dp-hint"><?php echo esc_html( sprintf( /* translators: %s: zona horaria */ __( 'Hora de la tienda (%s).', 'dox-pos' ), wp_timezone_string() ) ); ?></p>
							</div>
							<div class="dp-field">
								<label class="dp-label" for="dp-ai-to"><?php esc_html_e( 'A quién', 'dox-pos' ); ?></label>
								<input type="text" id="dp-ai-to" name="dox_pos_ai[summary_to]" value="<?php echo esc_attr( $ai['summary_to'] ); ?>" class="dp-input" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" autocomplete="off" inputmode="email">
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php esc_html_e( 'Correos separados por coma. Vacío: el del administrador del sitio.', 'dox-pos' ); ?></p>
							</div>
						</div>
						<div class="dp-callout">
							<?php echo dox_pos_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div>
								<b><?php echo $ai['summary_on'] && $ai_next ? esc_html( sprintf( /* translators: %s: cuándo */ __( 'El próximo sale %s', 'dox-pos' ), $ai_next ) ) : esc_html__( 'El resumen está apagado', 'dox-pos' ); ?></b>
								<span><?php echo esc_html( sprintf( /* translators: %s: correos */ __( 'A %s, por el correo del sitio. Si el servidor no pudo mandarlo a su hora, sale en cuanto alguien abre la caja.', 'dox-pos' ), $ai_to ) ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Cuándo avisa', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Los umbrales de los pendientes de hoy. Los apartados avisan solos cuando vencen o faltan menos de seis horas.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-grid-2">
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-ai-ship"><?php esc_html_e( 'Por enviar desde hace', 'dox-pos' ); ?></label>
								<div class="dp-unitfield"><input type="number" min="1" max="60" step="1" id="dp-ai-ship" name="dox_pos_ai[ship_days]" value="<?php echo esc_attr( $ai['ship_days'] ); ?>" class="dp-input" inputmode="numeric"><span class="dp-unit"><?php esc_html_e( 'días', 'dox-pos' ); ?></span></div>
								<p class="dp-hint"><?php esc_html_e( 'Un pedido pagado (o contraentrega) que sigue sin marcarse enviado.', 'dox-pos' ); ?></p>
							</div>
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-ai-deliver"><?php esc_html_e( 'Enviado sin entregar hace', 'dox-pos' ); ?></label>
								<div class="dp-unitfield"><input type="number" min="1" max="90" step="1" id="dp-ai-deliver" name="dox_pos_ai[deliver_days]" value="<?php echo esc_attr( $ai['deliver_days'] ); ?>" class="dp-input" inputmode="numeric"><span class="dp-unit"><?php esc_html_e( 'días', 'dox-pos' ); ?></span></div>
								<p class="dp-hint"><?php esc_html_e( 'En contraentrega es plata por cobrar: avisa como urgente.', 'dox-pos' ); ?></p>
							</div>
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-ai-pay"><?php esc_html_e( 'Pago de la web sin confirmar', 'dox-pos' ); ?></label>
								<div class="dp-unitfield"><input type="number" min="1" max="720" step="1" id="dp-ai-pay" name="dox_pos_ai[pay_hours]" value="<?php echo esc_attr( $ai['pay_hours'] ); ?>" class="dp-input" inputmode="numeric"><span class="dp-unit"><?php esc_html_e( 'horas', 'dox-pos' ); ?></span></div>
								<p class="dp-hint"><?php esc_html_e( 'Compras sin pagar o con el pago "en proceso" más tiempo que esto.', 'dox-pos' ); ?></p>
							</div>
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-ai-low"><?php esc_html_e( 'Se agota cuando quedan', 'dox-pos' ); ?></label>
								<div class="dp-unitfield"><input type="number" min="0" max="100" step="1" id="dp-ai-low" name="dox_pos_ai[low_stock]" value="<?php echo esc_attr( $ai['low_stock'] ); ?>" class="dp-input" inputmode="numeric"><span class="dp-unit"><?php esc_html_e( 'o menos', 'dox-pos' ); ?></span></div>
								<p class="dp-hint"><?php esc_html_e( 'Solo de lo que se vendió en los últimos 30 días.', 'dox-pos' ); ?></p>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Datos de demostración', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Para enseñar la caja y el asistente con la tienda en marcha: cuatro semanas de ventas de ejemplo por todos los canales y formas de pago, con envíos, contraentregas y descuentos, y una docena de pedidos abiertos con algo por hacer (uno de cada cosa que el asistente sabe detectar: apartado vencido, envío atrasado, contraentrega por cobrar, compra de la web sin pagar, pedido repetido…). Se hacen con los productos reales y no tocan las existencias. Mientras estén, la caja enseña un aviso; se quitan de golpe con el botón.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-callout">
							<div>
								<p class="dp-line dp-text" id="dp-demo-state"><?php echo esc_html( dox_pos_demo_status_text() ); ?></p>
								<p class="dp-status" id="dp-demo-status" aria-live="polite"></p>
								<button type="button" class="dp-btn dp-btn-primary dp-mt" id="dp-demo-on" <?php echo dox_pos_demo_active() ? 'hidden' : ''; ?>><?php esc_html_e( 'Crear datos de demostración', 'dox-pos' ); ?></button>
								<button type="button" class="dp-btn dp-btn-ghost dp-mt" id="dp-demo-off" <?php echo dox_pos_demo_active() ? '' : 'hidden'; ?>><?php esc_html_e( 'Quitar los datos de demostración', 'dox-pos' ); ?></button>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Quién lo usa y qué puede hacer', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'La pestaña Asistente de la caja (Hoy, Revisión, Chat y Actividad) la ven los administradores y los gerentes de tienda. Todo número sale de la tienda; el modelo solo redacta. Ningún cambio se aplica sin un botón de confirmación, cada uno queda apuntado con quién lo confirmó, y se deshace durante 24 horas.', 'dox-pos' ); ?></p>
						</div>
					</div>
				</section>
			</form>

			<aside class="dp-side" aria-label="<?php esc_attr_e( 'Vista previa', 'dox-pos' ); ?>">
				<div class="dp-side-head">
					<span class="dp-side-title"><?php esc_html_e( 'Vista previa', 'dox-pos' ); ?></span>
					<span class="dp-live"><i aria-hidden="true"></i><?php esc_html_e( 'En vivo', 'dox-pos' ); ?></span>
				</div>
				<div class="dp-device" id="dp-preview" style="--ui:<?php echo esc_attr( '"' . $fonts['ui'] . '",sans-serif' ); ?>;--serif:<?php echo esc_attr( '"' . $fonts['serif'] . '",serif' ); ?>">
					<div class="dp-chrome"><span class="dp-dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="dp-url" id="dp-preview-url"><?php echo esc_html( $host . '/' . $slug ); ?></span></div>

					<div class="dp-mock" data-view="caja">
						<div class="dp-bar">
							<img class="dp-logo-prev" id="dp-preview-logo" src="<?php echo esc_url( $logo ); ?>" alt="" <?php echo $logo ? '' : 'hidden'; ?>>
							<span class="dp-brand" id="dp-preview-brand" <?php echo $logo ? 'hidden' : ''; ?>><?php echo esc_html( dox_pos_brand_name() ); ?></span>
							<span class="dp-cajita" id="dp-preview-screen"><?php echo esc_html( dox_pos_screen_name() ); ?></span>
							<span class="dp-tabs"><span class="dp-tab on"><?php esc_html_e( 'Vender', 'dox-pos' ); ?></span><span class="dp-tab"><?php esc_html_e( 'Pedidos', 'dox-pos' ); ?></span></span>
						</div>
						<div class="dp-body-prev">
							<div class="dp-card-prev">
								<p class="dp-lbl"><?php esc_html_e( 'Cómo entró la venta', 'dox-pos' ); ?></p>
								<p class="dp-chips-prev" id="dp-preview-channels"></p>
								<p class="dp-line"><span class="dp-thumb">VE</span><span><?php esc_html_e( 'Vestido Ella', 'dox-pos' ); ?><i>M · Rosa</i></span><span class="dp-tag-prev"><?php esc_html_e( 'Apartado', 'dox-pos' ); ?></span></p>
								<p class="dp-lbl"><?php esc_html_e( 'Cómo paga', 'dox-pos' ); ?></p>
								<p class="dp-chips-prev" id="dp-preview-payments"></p>
								<p class="dp-total"><span><?php esc_html_e( 'Total', 'dox-pos' ); ?></span><b><?php echo esc_html( dox_pos_money( 189000 ) ); ?></b></p>
								<span class="dp-go"><?php esc_html_e( 'Ya pagó: registrar la venta', 'dox-pos' ); ?></span>
								<span class="dp-go alt"><?php esc_html_e( 'Todavía no paga: apartar', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-mock dp-wa" data-view="whatsapp" hidden>
						<div class="dp-wa-head"><span class="dp-wa-avatar">A</span><span class="dp-wa-who"><b><?php esc_html_e( 'Ana', 'dox-pos' ); ?></b><i><?php esc_html_e( 'en línea', 'dox-pos' ); ?></i></span></div>
						<div class="dp-wa-body"><div class="dp-wa-bubble"><p id="dp-preview-message"></p><span class="dp-wa-time">10:42</span></div></div>
					</div>

					<div class="dp-mock dp-envio" data-view="envio" hidden>
						<div class="dp-modal-prev">
							<p class="dp-modal-title"><?php esc_html_e( 'Marcar como enviado', 'dox-pos' ); ?></p>
							<p class="dp-lbl"><?php esc_html_e( 'Transportadora', 'dox-pos' ); ?></p>
							<p class="dp-chips-prev" id="dp-preview-carriers"></p>
							<p class="dp-lbl"><?php esc_html_e( 'Número de guía', 'dox-pos' ); ?></p>
							<span class="dp-fake-input"></span>
							<span class="dp-go"><?php esc_html_e( 'Guardar', 'dox-pos' ); ?></span>
						</div>
					</div>

					<div class="dp-mock" data-view="producto" hidden>
						<div class="dp-bar">
							<span class="dp-cajita" style="border-left:0;padding-left:0"><?php echo esc_html( dox_pos_screen_name() ); ?></span>
							<span class="dp-tabs"><span class="dp-tab"><?php esc_html_e( 'Vender', 'dox-pos' ); ?></span><span class="dp-tab on"><?php esc_html_e( 'Productos', 'dox-pos' ); ?></span></span>
						</div>
						<div class="dp-body-prev">
							<div class="dp-card-prev">
								<p class="dp-lbl"><?php esc_html_e( 'Fotos', 'dox-pos' ); ?></p>
								<div class="dp-photos">
									<span class="dp-photo"><i>WebP · <b id="dp-preview-quality"><?php echo esc_html( $products['quality'] ); ?></b> %</i></span>
									<span class="dp-photo"><i><b id="dp-preview-px"><?php echo esc_html( $products['max_px'] ); ?></b> px</i></span>
									<span class="dp-photo add"><?php echo dox_pos_icon( 'camera' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								</div>
								<p class="dp-lbl"><?php esc_html_e( 'Tallas', 'dox-pos' ); ?></p>
								<p class="dp-chips-prev"><span class="on">0-6 M</span><span class="on">6-12 M</span><span class="on">12-18 M</span><span>18-24 M</span></p>
								<p class="dp-lbl"><?php esc_html_e( 'Código', 'dox-pos' ); ?></p>
								<span class="dp-fake-input dp-mono" id="dp-preview-sku">VE83</span>
								<span class="dp-go"><?php esc_html_e( 'Crear producto', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-mock" data-view="asistente" hidden>
						<div class="dp-bar">
							<span class="dp-cajita" style="border-left:0;padding-left:0"><?php echo esc_html( dox_pos_screen_name() ); ?></span>
							<span class="dp-tabs"><span class="dp-tab"><?php esc_html_e( 'Pedidos', 'dox-pos' ); ?></span><span class="dp-tab on"><?php esc_html_e( 'Asistente', 'dox-pos' ); ?></span></span>
						</div>
						<div class="dp-body-prev">
							<div class="dp-card-prev">
								<p class="dp-lbl"><?php esc_html_e( 'Resumen de las', 'dox-pos' ); ?> <b id="dp-preview-hour"><?php echo esc_html( $ai['summary_hour'] . ':00' ); ?></b></p>
								<p class="dp-line dp-text"><?php esc_html_e( 'Ayer se vendieron $358.000 en 2 ventas, por WhatsApp. Hoy: un pedido por enviar que se está atrasando y un apartado que vence esta tarde.', 'dox-pos' ); ?></p>
								<p class="dp-lbl"><?php esc_html_e( 'Pendientes de hoy', 'dox-pos' ); ?></p>
								<p class="dp-line"><span class="dp-tag-prev"><?php esc_html_e( 'Urgente', 'dox-pos' ); ?></span><span><?php esc_html_e( '#1240 por enviar', 'dox-pos' ); ?><i><?php esc_html_e( 'hace', 'dox-pos' ); ?> <b id="dp-preview-ship"><?php echo esc_html( $ai['ship_days'] ); ?></b> <?php esc_html_e( 'días', 'dox-pos' ); ?></i></span></p>
								<p class="dp-line"><span class="dp-tag-prev"><?php esc_html_e( 'Hoy', 'dox-pos' ); ?></span><span><?php esc_html_e( 'Apartado #1244', 'dox-pos' ); ?><i><?php esc_html_e( 'vence en 4 h', 'dox-pos' ); ?></i></span></p>
								<p class="dp-lbl"><?php esc_html_e( 'Se agota', 'dox-pos' ); ?></p>
								<p class="dp-line"><span class="dp-thumb">VE</span><span><?php esc_html_e( 'Vestido Ella · M', 'dox-pos' ); ?><i><?php esc_html_e( 'vendió 6, quedan', 'dox-pos' ); ?> <b id="dp-preview-low"><?php echo esc_html( $ai['low_stock'] ); ?></b></i></span></p>
							</div>
						</div>
					</div>
				</div>
				<p class="dp-side-note"><?php esc_html_e( 'Lo que cambies se ve aquí al momento. En la caja, al guardar.', 'dox-pos' ); ?></p>
			</aside>
		</div>
	</div>
	<?php
}
