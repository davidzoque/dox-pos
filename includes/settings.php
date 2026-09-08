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
		array( 'name' => __( 'In person', 'dox-pos' ), 'pickup' => true ),
	);
}

/**
 * Las formas de pago que la caja sabe manejar. "paid" = queda pagado al registrar;
 * contraentrega no, y por eso el pedido se queda en "procesando" hasta que llega.
 */
function dox_pos_builtin_payments() {
	return array(
		'nequi'         => array( 'id' => 'dox_pos_nequi', 'title' => 'Nequi', 'paid' => true ),
		'transferencia' => array( 'id' => 'dox_pos_transfer', 'title' => __( 'Bank transfer', 'dox-pos' ), 'paid' => true ),
		'contraentrega' => array( 'id' => 'cod', 'title' => __( 'Cash on delivery', 'dox-pos' ), 'paid' => false ),
		'efectivo'      => array( 'id' => 'dox_pos_cash', 'title' => __( 'Cash payment', 'dox-pos' ), 'paid' => true ),
		'tarjeta'       => array( 'id' => 'dox_pos_card', 'title' => __( 'Card', 'dox-pos' ), 'paid' => true ),
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
/**
 * ¿Se cargan las fuentes desde Google Fonts? De fábrica sí; apagado, la caja y la página de
 * ajustes usan las fuentes del sistema y el plugin no habla con ningún servidor de fuera.
 */
function dox_pos_fonts_on() {
	$b = dox_pos_brand();
	return ! isset( $b['fonts_google'] ) || ! empty( $b['fonts_google'] );
}

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
	return ! empty( $s['name'] ) ? (string) $s['name'] : __( 'Register', 'dox-pos' );
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
	return __( 'State', 'dox-pos' );
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
	$on   = dox_pos_fonts_on();
	$vars = array(
		'--ui'    => ( $on ? '"' . $f['ui'] . '",' : '' ) . '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif',
		'--serif' => ( $on ? '"' . $f['serif'] . '",' : '' ) . 'Georgia,serif',
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
 * La hoja de Google Fonts con las dos fuentes, o vacío si el ajuste dice que no se carguen.
 */
function dox_pos_fonts_url() {
	if ( ! dox_pos_fonts_on() ) {
		return '';
	}
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
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=dox-pos' ) ) . '">' . esc_html__( 'Settings', 'dox-pos' ) . '</a>' );
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
		add_settings_error( 'dox_pos', 'quality', __( 'Quality goes from 50 to 100. It was left at 88.', 'dox-pos' ) );
		$q = 88;
	}
	if ( $px < 800 || $px > 4000 ) {
		add_settings_error( 'dox_pos', 'max_px', __( 'The longest side goes from 800 to 4000 px. It was left at 1600.', 'dox-pos' ) );
		$px = 1600;
	}
	delete_transient( 'dox_pos_product_form' );
	// El campo de costo de WooCommerce se enciende aquí y solo aquí: al guardar con el interruptor
	// puesto, que es una decisión de quien administra. La instalación del plugin no toca nada suyo.
	if ( ! empty( $in['costs'] ) && method_exists( 'WC_Product', 'get_cogs_value' ) && ! dox_pos_wc_cogs_enabled() ) {
		dox_pos_costs_enable();
		add_settings_error( 'dox_pos', 'costs_on', __( 'The WooCommerce cost field was turned on (Settings > Advanced > Features), which is where each product\'s cost is stored.', 'dox-pos' ), 'info' );
	}
	return array(
		'quality'    => $q,
		'max_px'     => $px,
		'sku'        => in_array( $sku, array( 'codes', 'slugs', 'none' ), true ) ? $sku : 'codes',
		'size_attr'  => isset( $all[ $size ] ) ? $size : '',
		'color_attr' => isset( $all[ $col ] ) ? $col : '',
		'costs'      => ! empty( $in['costs'] ),
	);
}

function dox_pos_sanitize_brand( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$old = dox_pos_brand();
	$out = array( 'name' => sanitize_text_field( $in['name'] ?? '' ), 'colors' => array(), 'fonts_google' => empty( $in['fonts_google'] ) ? 0 : 1 );
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
		// Solo se le pregunta a Google si las fuentes se cargan de ahí.
		if ( $out['fonts_google'] && $font !== $was && ! dox_pos_google_font_exists( $font ) ) {
			add_settings_error( 'dox_pos', 'font_' . $k, sprintf( /* translators: %s: nombre de la fuente */ __( 'Google Fonts does not know the font "%s". Copy the name exactly as it appears on fonts.google.com. The previous one was kept.', 'dox-pos' ), $font ) );
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
		return __( 'Enter a path: letters, numbers and hyphens.', 'dox-pos' );
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
		return sprintf( /* translators: %s: ruta */ __( 'The path /%s/ is already used by another page.', 'dox-pos' ), $slug );
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
			add_settings_error( 'dox_pos', 'slug', $problem . ' ' . __( 'The previous one was kept.', 'dox-pos' ) );
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
		add_settings_error( 'dox_pos', 'channels', __( 'At least one sales channel is needed. The default ones were restored.', 'dox-pos' ) );
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
		add_settings_error( 'dox_pos', 'payments', __( 'At least one payment method is needed. All of them were turned on.', 'dox-pos' ) );
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
				add_settings_error( 'dox_pos', 'carrier_url', sprintf( __( 'The %s link is not valid: it has to start with https://', 'dox-pos' ), $name ) );
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
/**
 * Las etiquetas de un icono para wp_kses: así el SVG se imprime escapado, sin silenciar el aviso.
 * El navegador corrige "viewbox" a "viewBox" al leer un SVG dentro del HTML.
 *
 * @return array
 */
function dox_pos_svg_tags() {
	$shape = array( 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true );
	return array(
		'svg'      => array_merge( $shape, array( 'class' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'xmlns' => true, 'aria-hidden' => true, 'focusable' => true, 'role' => true ) ),
		'g'        => $shape,
		'path'     => array_merge( $shape, array( 'd' => true ) ),
		'circle'   => array_merge( $shape, array( 'cx' => true, 'cy' => true, 'r' => true ) ),
		'rect'     => array_merge( $shape, array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ) ),
		'line'     => array_merge( $shape, array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ) ),
		'polyline' => array_merge( $shape, array( 'points' => true ) ),
		'polygon'  => array_merge( $shape, array( 'points' => true ) ),
	);
}

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
	if ( dox_pos_fonts_on() ) { // Con el ajuste apagado, ni la caja ni esta página piden nada a Google.
		wp_enqueue_style( 'dox-pos-inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- La hoja de Google lleva su propia versión.
		wp_enqueue_style( 'dox-pos-fonts', dox_pos_fonts_url(), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Igual.
	}
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
				'screen'  => __( 'Register', 'dox-pos' ),
				'slug'    => DOX_POS_SLUG,
				'hours'   => 48,
			),
			'siteLogo' => ( (int) get_theme_mod( 'custom_logo' ) ) ? wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'full' ) : '',
			'siteName' => dox_pos_brand_name(),
			'sample'   => array(
				'name'     => __( 'Ana', 'dox-pos' ),
				'products' => __( 'Ella dress (M · Pink)', 'dox-pos' ),
				'total'    => dox_pos_money( 189000 ),
				'link'     => add_query_arg( array( 'pay_for_order' => 'true', 'key' => 'wc_order_ejemplo' ), wc_get_endpoint_url( 'order-pay', 1234, wc_get_checkout_url() ) ),
			),
			'notices'  => dox_pos_settings_notices(),
			'i18n'     => array(
				'carriers' => array( __( 'DHL', 'dox-pos' ), __( 'UPS', 'dox-pos' ), __( 'FedEx', 'dox-pos' ) ),
				'pickLogo'  => __( 'Register logo', 'dox-pos' ),
				'use'       => __( 'Use this image', 'dox-pos' ),
				'channel'   => __( 'Channel name', 'dox-pos' ),
				'pickup'    => __( 'Pickup', 'dox-pos' ),
				'pickupLong' => __( 'Handed over in person, no shipping', 'dox-pos' ),
				'remove'    => __( 'Remove', 'dox-pos' ),
				'reorder'   => __( 'Drag to reorder, or use the arrow keys', 'dox-pos' ),
				'checking'  => __( 'Checking…', 'dox-pos' ),
				'carrierName' => __( 'Carrier', 'dox-pos' ),
				'carrierUrl' => __( 'Tracking link', 'dox-pos' ),
				'slugOk'    => __( 'Available.', 'dox-pos' ),
				'slugSame'  => __( 'That is the current path.', 'dox-pos' ),
				'fontOk'    => __( 'Google Fonts has it.', 'dox-pos' ),
				'fontBad'   => __( 'Google Fonts does not know that font. Copy the name exactly as it appears on fonts.google.com.', 'dox-pos' ),
				'saving'    => __( 'Saving…', 'dox-pos' ),
				'unsaved'   => __( 'There are unsaved changes.', 'dox-pos' ),
				'close'     => __( 'Close', 'dox-pos' ),
				'removeTag' => __( 'Remove %s', 'dox-pos' ),
			),
		)
	);
	do_action( 'dox_pos_settings_assets' ); // Los añadidos encolan lo suyo (el Pro, su JS del asistente).
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
	// Cada pestaña: título, icono y qué vista previa enseña. Los añadidos (el Pro) meten las suyas por el filtro.
	$tabs     = apply_filters(
		'dox_pos_settings_tabs',
		array(
			'marca'     => array( __( 'Brand', 'dox-pos' ), 'palette', 'caja' ),
			'pantalla'  => array( __( 'Screen', 'dox-pos' ), 'screen', 'caja' ),
			'ventas'    => array( __( 'Sales', 'dox-pos' ), 'bag', 'caja' ),
			'apartados' => array( __( 'Layaways', 'dox-pos' ), 'clock', 'whatsapp' ),
			'envios'    => array( __( 'Shipments', 'dox-pos' ), 'truck', 'envio' ),
			'productos' => array( __( 'Products', 'dox-pos' ), 'tag', 'producto' ),
		)
	);
	$skufmt   = array(
		'codes' => array( __( 'Like the store: parent + size + color', 'dox-pos' ), __( 'Two digits for the size and two for the color, learned from the products that already exist (VE83 + 01 + 13 = VE830113). With no color, a 0.', 'dox-pos' ) ),
		'slugs' => array( __( 'Parent, size and color with hyphens', 'dox-pos' ), __( 'VE83-6-12-months-pink. Easy to read at a glance; longer.', 'dox-pos' ) ),
		'none'  => array( __( 'No SKU on the variations', 'dox-pos' ), __( 'Only the product carries a SKU; the variations carry none.', 'dox-pos' ) ),
	);
	$labels   = array(
		'bg'      => __( 'Background', 'dox-pos' ),
		'bar'     => __( 'Bar', 'dox-pos' ),
		'primary' => __( 'Primary', 'dox-pos' ),
		'soft'    => __( 'Soft', 'dox-pos' ),
		'ink'     => __( 'Text', 'dox-pos' ),
	);
	$hints    = array(
		'bg'      => __( 'The whole screen', 'dox-pos' ),
		'bar'     => __( 'Logo and tabs', 'dox-pos' ),
		'primary' => __( 'Buttons and selection', 'dox-pos' ),
		'soft'    => __( 'Tags and bubble', 'dox-pos' ),
		'ink'     => __( 'The lettering', 'dox-pos' ),
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
					<a class="dp-btn dp-btn-ghost dp-urlchip" href="<?php echo esc_url( dox_pos_url() ); ?>" target="_blank" rel="noopener"><?php echo wp_kses( dox_pos_icon( 'external' ), dox_pos_svg_tags() ); ?><span><?php echo esc_html( $host . '/' . $slug ); ?></span></a>
					<button type="button" class="dp-btn dp-btn-ghost dp-discard" id="dp-discard"><?php echo wp_kses( dox_pos_icon( 'undo' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Discard', 'dox-pos' ); ?></button>
					<button type="submit" form="dp-form" class="dp-btn dp-btn-primary" id="dp-save"><span class="dp-dot" aria-hidden="true"></span><span class="dp-save-text"><?php esc_html_e( 'Save changes', 'dox-pos' ); ?></span></button>
				</div>
			</div>
			<nav class="dp-tabbar" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'dox-pos' ); ?>">
				<?php foreach ( $tabs as $id => $t ) : ?>
				<button type="button" role="tab" class="dp-tab-btn" id="dp-tab-<?php echo esc_attr( $id ); ?>" data-tab="<?php echo esc_attr( $id ); ?>" data-view="<?php echo esc_attr( $t[2] ?? 'caja' ); ?>" aria-selected="false" aria-controls="dp-panel-<?php echo esc_attr( $id ); ?>" tabindex="-1"><?php echo wp_kses( dox_pos_icon( $t[1] ), dox_pos_svg_tags() ); ?><span><?php echo esc_html( $t[0] ); ?></span></button>
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
							<h2><?php esc_html_e( 'Identity', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'What whoever opens the register sees: the name in the browser tab and on the sign-in screen, and the logo on the bar.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dp-name"><?php esc_html_e( 'Brand name', 'dox-pos' ); ?></label>
							<input type="text" id="dp-name" name="dox_pos_brand[name]" value="<?php echo esc_attr( $brand['name'] ?? '' ); ?>" class="dp-input" placeholder="<?php echo esc_attr( dox_pos_brand_name() ); ?>" autocomplete="off">
							<p class="dp-hint"><?php esc_html_e( 'Empty: the site name, without the tagline.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field">
							<span class="dp-label" id="dp-logo-label"><?php esc_html_e( 'Logo', 'dox-pos' ); ?></span>
							<div class="dp-logo" role="group" aria-labelledby="dp-logo-label">
								<div class="dp-logo-tile" id="dp-logo-tile">
									<img id="dp-logo-img" src="<?php echo esc_url( $logo ); ?>" alt="" <?php echo $logo ? '' : 'hidden'; ?>>
									<span class="dp-logo-empty" id="dp-logo-empty" <?php echo $logo ? 'hidden' : ''; ?>><?php echo wp_kses( dox_pos_icon( 'image' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'No logo: the name shows', 'dox-pos' ); ?></span>
								</div>
								<div class="dp-logo-actions">
									<input type="hidden" id="dox_pos_logo" name="dox_pos_logo" value="<?php echo esc_attr( get_option( 'dox_pos_logo', '' ) ); ?>">
									<button type="button" class="dp-btn dp-btn-soft" id="dp-logo-pick"><?php echo wp_kses( dox_pos_icon( 'image' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Choose from the library', 'dox-pos' ); ?></button>
									<button type="button" class="dp-btn dp-btn-link" id="dp-logo-clear"><?php esc_html_e( 'Use the site\'s one', 'dox-pos' ); ?></button>
									<p class="dp-hint"><?php esc_html_e( 'It sits on the bar: if the bar is dark, a light version works better. Empty: the logo from Appearance > Customize.', 'dox-pos' ); ?></p>
								</div>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Colors', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Five colors dress the whole register; the border and background shades come from them. The text on the bar and on the buttons is decided on its own, from how dark the background is.', 'dox-pos' ); ?></p>
							<button type="button" class="dp-btn dp-btn-link dp-card-action" id="dp-colors-reset" disabled><?php echo wp_kses( dox_pos_icon( 'undo' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Back to the site\'s colors', 'dox-pos' ); ?></button>
						</div>
						<div class="dp-swatches">
							<?php foreach ( $labels as $k => $label ) : ?>
							<div class="dp-swatch" data-key="<?php echo esc_attr( $k ); ?>" data-default="<?php echo esc_attr( $defaults[ $k ] ); ?>" style="--sw:<?php echo esc_attr( $colors[ $k ] ); ?>">
								<label class="dp-swatch-well" for="dp-pick-<?php echo esc_attr( $k ); ?>">
									<input type="color" id="dp-pick-<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $colors[ $k ] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: nombre del color */ __( 'Pick the color: %s', 'dox-pos' ), $label ) ); ?>">
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
							<h2><?php esc_html_e( 'Fonts', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Two fonts for the register: one for the interface and one for the total and the headings. They are downloaded from Google Fonts when the register opens, so the browser of whoever uses it connects to Google (fonts.googleapis.com and fonts.gstatic.com). If you would rather nothing left the site, turn the switch off and the fonts of the phone or the computer are used.', 'dox-pos' ); ?></p>
						</div>
						<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_brand[fonts_google]" value="1" <?php checked( dox_pos_fonts_on() ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'Load the fonts from Google Fonts', 'dox-pos' ); ?></span></label>
						<div class="dp-mt">
						<div class="dp-grid-2">
							<div class="dp-field">
								<label class="dp-label" for="dp-font-ui"><?php esc_html_e( 'Interface', 'dox-pos' ); ?></label>
								<input type="text" id="dp-font-ui" name="dox_pos_brand[font_ui]" value="<?php echo esc_attr( $brand['font_ui'] ?? '' ); ?>" class="dp-input" placeholder="<?php echo esc_attr( dox_pos_default_fonts()['ui'] ); ?>" list="dp-font-list" autocomplete="off">
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php echo esc_html( sprintf( /* translators: %s: fuente de fábrica */ __( 'Empty: %s.', 'dox-pos' ), dox_pos_default_fonts()['ui'] ) ); ?></p>
							</div>
							<div class="dp-field">
								<label class="dp-label" for="dp-font-serif"><?php esc_html_e( 'Totals and headings', 'dox-pos' ); ?></label>
								<input type="text" id="dp-font-serif" name="dox_pos_brand[font_serif]" value="<?php echo esc_attr( $brand['font_serif'] ?? '' ); ?>" class="dp-input" placeholder="<?php echo esc_attr( dox_pos_default_fonts()['serif'] ); ?>" list="dp-font-list" autocomplete="off">
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php echo esc_html( sprintf( /* translators: %s: fuente de fábrica */ __( 'Empty: %s.', 'dox-pos' ), dox_pos_default_fonts()['serif'] ) ); ?></p>
							</div>
						</div>
						<datalist id="dp-font-list">
							<?php foreach ( dox_pos_font_suggestions() as $f ) : ?>
							<option value="<?php echo esc_attr( $f ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
						</div>
					</div>
				</section>

				<!-- ===================== Pantalla ===================== -->
				<section class="dp-panel" id="dp-panel-pantalla" data-panel="pantalla" role="tabpanel" aria-labelledby="dp-tab-pantalla" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Name and address', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'What the screen is called and at what address it opens. The address can be changed; if you change it, tell whoever has it saved on their phone.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dp-screen"><?php esc_html_e( 'Screen name', 'dox-pos' ); ?></label>
							<input type="text" id="dp-screen" name="dox_pos_screen[name]" value="<?php echo esc_attr( $screen['name'] ?? '' ); ?>" class="dp-input" placeholder="<?php esc_attr_e( 'Register', 'dox-pos' ); ?>" autocomplete="off">
							<p class="dp-hint"><?php esc_html_e( 'The word next to the logo and in the tab title. Empty: Register.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dp-slug"><?php esc_html_e( 'Register address', 'dox-pos' ); ?></label>
							<div class="dp-urlfield">
								<span class="dp-urlfield-pre"><?php echo esc_html( $host ); ?>/</span>
								<input type="text" id="dp-slug" name="dox_pos_screen[slug]" value="<?php echo esc_attr( $slug ); ?>" class="dp-input" spellcheck="false" autocomplete="off" autocapitalize="off" pattern="[a-z0-9-]+">
								<span class="dp-urlfield-post">/</span>
							</div>
							<p class="dp-status" id="dp-slug-status" aria-live="polite"></p>
							<p class="dp-hint"><?php esc_html_e( 'Only letters, numbers and hyphens. It is checked right away that no other page uses it; the change applies when you save.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-callout">
							<?php echo wp_kses( dox_pos_icon( 'link' ), dox_pos_svg_tags() ); ?>
							<div>
								<b><?php esc_html_e( 'Right now the register is at', 'dox-pos' ); ?> <a href="<?php echo esc_url( dox_pos_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $host . '/' . $slug . '/' ); ?></a></b>
								<span><?php esc_html_e( 'On a phone: open it in the browser and choose "Add to Home Screen". It looks like an app.', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Who signs in', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Administrators, shop managers and users with the "Cashier" role can sign in. Someone who only has the Cashier role never sees the WordPress dashboard: they sign in and go straight to the register.', 'dox-pos' ); ?></p>
							<a class="dp-btn dp-btn-soft dp-card-action" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"><?php echo wp_kses( dox_pos_icon( 'plus' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'New user', 'dox-pos' ); ?></a>
						</div>
						<ul class="dp-people">
							<?php foreach ( $access['users'] as $u ) : ?>
							<li><span class="dp-avatar" aria-hidden="true"><?php echo esc_html( $u['initials'] ); ?></span><span class="dp-person"><b><?php echo esc_html( $u['name'] ); ?></b><i><?php echo esc_html( $u['role'] ); ?></i></span></li>
							<?php endforeach; ?>
						</ul>
						<?php if ( $access['total'] > count( $access['users'] ) ) : ?>
						<p class="dp-hint"><a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: usuarios */ _n( 'See the remaining user', 'See all %d users', $access['total'], 'dox-pos' ), $access['total'] ) ); ?></a></p>
						<?php endif; ?>
					</div>
				</section>

				<!-- ===================== Ventas ===================== -->
				<section class="dp-panel" id="dp-panel-ventas" data-panel="ventas" role="tabpanel" aria-labelledby="dp-tab-ventas" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Where the sale comes in', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'The first one is selected by default. "Pickup" channels do not ask for a city or for shipping. Drag to reorder.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-rows" id="dp-canales">
							<?php foreach ( $channels as $i => $ch ) : ?>
							<div class="dp-row">
								<button type="button" class="dp-grip" aria-label="<?php esc_attr_e( 'Drag to reorder, or use the arrow keys', 'dox-pos' ); ?>"><?php echo wp_kses( dox_pos_icon( 'grip' ), dox_pos_svg_tags() ); ?></button>
								<input type="text" name="dox_pos_sales[channels][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $ch['name'] ); ?>" class="dp-input" placeholder="<?php esc_attr_e( 'Channel name', 'dox-pos' ); ?>" aria-label="<?php esc_attr_e( 'Channel name', 'dox-pos' ); ?>">
								<label class="dp-switch" title="<?php esc_attr_e( 'Handed over in person, no shipping', 'dox-pos' ); ?>"><input type="checkbox" role="switch" name="dox_pos_sales[channels][<?php echo (int) $i; ?>][pickup]" value="1" <?php checked( $ch['pickup'] ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'Pickup', 'dox-pos' ); ?></span></label>
								<button type="button" class="dp-iconbtn dp-quitar" aria-label="<?php esc_attr_e( 'Remove', 'dox-pos' ); ?>"><?php echo wp_kses( dox_pos_icon( 'trash' ), dox_pos_svg_tags() ); ?></button>
							</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="dp-btn dp-btn-soft" id="dp-canal-add"><?php echo wp_kses( dox_pos_icon( 'plus' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Add channel', 'dox-pos' ); ?></button>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Payment methods', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Turn off the ones you do not use and name them however you want. The one marked "default" comes selected when the register opens.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-pays" id="dp-pagos">
							<?php foreach ( $payments as $key => $m ) : ?>
							<div class="dp-pay<?php echo isset( $active[ $key ] ) ? '' : ' off'; ?>" data-key="<?php echo esc_attr( $key ); ?>">
								<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_sales[payments][<?php echo esc_attr( $key ); ?>][on]" value="1" <?php checked( isset( $active[ $key ] ) ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( $m['title'] ); ?></span></label>
								<div class="dp-pay-main">
									<input type="text" name="dox_pos_sales[payments][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $active[ $key ]['title'] ?? ( $sales['payments'][ $key ]['title'] ?? $m['title'] ) ); ?>" class="dp-input" placeholder="<?php echo esc_attr( $m['title'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: forma de pago */ __( 'Name of %s', 'dox-pos' ), $m['title'] ) ); ?>">
									<span class="dp-hint"><?php echo $m['paid'] ? esc_html__( 'It is marked as paid when recorded.', 'dox-pos' ) : esc_html__( 'It stays "to ship" and is collected on delivery.', 'dox-pos' ); ?></span>
								</div>
								<label class="dp-def"><input type="radio" name="dox_pos_sales[default_payment]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $default, $key ); ?> <?php disabled( ! isset( $active[ $key ] ) ); ?>><span class="dp-def-on"><?php echo wp_kses( dox_pos_icon( 'check' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Default', 'dox-pos' ); ?></span><span class="dp-def-off"><?php esc_html_e( 'Make default', 'dox-pos' ); ?></span></label>
							</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Orders from the website', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'The Orders tab also shows what people buy in the online store, tagged "Website" and with where the customer came from, so you can mark it shipped or delivered from your phone just like a register sale.', 'dox-pos' ); ?></p>
						</div>
						<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_sales[web_orders]" value="1" <?php checked( dox_pos_show_web_orders() ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'Show website orders in the register', 'dox-pos' ); ?></span></label>
					</div>
				</section>

				<!-- ===================== Apartados ===================== -->
				<section class="dp-panel" id="dp-panel-apartados" data-panel="apartados" role="tabpanel" aria-labelledby="dp-tab-apartados" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Deadline', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'A layaway keeps the product reserved. If it is not paid within the deadline, it cancels itself and the product goes back to stock.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-field dp-field-short">
							<label class="dp-label" for="dox_pos_hold_hours"><?php esc_html_e( 'Held for', 'dox-pos' ); ?></label>
							<div class="dp-unitfield">
								<input type="number" min="1" max="720" step="1" id="dox_pos_hold_hours" name="dox_pos_hold_hours" value="<?php echo esc_attr( dox_pos_hold_hours() ); ?>" class="dp-input" inputmode="numeric">
								<span class="dp-unit"><?php esc_html_e( 'hours', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'WhatsApp message', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'It opens already written in WhatsApp when you put something on layaway; you only have to send it. Tap a placeholder to insert it where the cursor is.', 'dox-pos' ); ?></p>
							<button type="button" class="dp-btn dp-btn-link dp-card-action" id="dp-message-reset"><?php echo wp_kses( dox_pos_icon( 'undo' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Default message', 'dox-pos' ); ?></button>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dox_pos_hold_message"><?php esc_html_e( 'Text', 'dox-pos' ); ?></label>
							<div class="dp-chips" id="dp-placeholders" aria-label="<?php esc_attr_e( 'Placeholders', 'dox-pos' ); ?>">
								<?php foreach ( array( 'nombre', 'productos', 'total', 'horas', 'link', 'tienda' ) as $p ) : ?>
								<button type="button" class="dp-chip" data-insert="{<?php echo esc_attr( $p ); ?>}">{<?php echo esc_html( $p ); ?>}</button>
								<?php endforeach; ?>
							</div>
							<textarea id="dox_pos_hold_message" name="dox_pos_hold_message" rows="5" class="dp-input dp-textarea"><?php echo esc_textarea( dox_pos_hold_message_template() ); ?></textarea>
							<p class="dp-hint"><?php echo wp_kses( __( '<code>{link}</code> is the order\'s payment link and <code>{tienda}</code> is the brand name.', 'dox-pos' ), $kses_a ); ?></p>
						</div>
						<div class="dp-field">
							<label class="dp-label" for="dox_pos_payment_note"><?php esc_html_e( 'How to pay outside the link', 'dox-pos' ); ?></label>
							<textarea id="dox_pos_payment_note" name="dox_pos_payment_note" rows="2" class="dp-input dp-textarea" placeholder="<?php esc_attr_e( 'Or by bank transfer to 000-123456', 'dox-pos' ); ?>"><?php echo esc_textarea( dox_pos_payment_note() ); ?></textarea>
							<p class="dp-hint"><?php esc_html_e( 'It is added at the end of the message, as is. Empty: only the link goes.', 'dox-pos' ); ?></p>
						</div>
					</div>
				</section>

				<!-- ===================== Envíos ===================== -->
				<section class="dp-panel" id="dp-panel-envios" data-panel="envios" role="tabpanel" aria-labelledby="dp-tab-envios" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Carriers', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'They are suggested when you mark an order as shipped (you can also type another one on the spot). The tracking link goes in the email and in the WhatsApp message to the customer: put {guia} where the carrier expects the number; if their page does not take it, leave the link to the tracking page and the number goes separately.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-rows dp-carriers" id="dp-carriers">
							<?php foreach ( $carriers as $i => $c ) : ?>
							<div class="dp-carrier dp-row">
								<input type="text" class="dp-input" data-k="name" name="dox_pos_sales[carriers][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="<?php esc_attr_e( 'Carrier', 'dox-pos' ); ?>" aria-label="<?php esc_attr_e( 'Carrier', 'dox-pos' ); ?>">
								<input type="text" class="dp-input" data-k="url" name="dox_pos_sales[carriers][<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $c['url'] ); ?>" placeholder="https://… {guia}" aria-label="<?php esc_attr_e( 'Tracking link', 'dox-pos' ); ?>" inputmode="url" autocomplete="off">
								<button type="button" class="dp-carrier-x" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: transportadora */ __( 'Remove %s', 'dox-pos' ), $c['name'] ) ); ?>"><?php echo wp_kses( dox_pos_icon( 'x' ), dox_pos_svg_tags() ); ?></button>
							</div>
							<?php endforeach; ?>
						</div>
						<div class="dp-carrier-add">
							<select id="dp-carrier-preset" class="dp-input" aria-label="<?php esc_attr_e( 'Add a known carrier', 'dox-pos' ); ?>">
								<option value=""><?php esc_html_e( 'Add a known one…', 'dox-pos' ); ?></option>
								<?php foreach ( dox_pos_carrier_presets() as $key => $p ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $p['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="dp-btn dp-btn-ghost" id="dp-carrier-add"><?php esc_html_e( 'Another carrier', 'dox-pos' ); ?></button>
						</div>
						<p class="dp-hint"><?php esc_html_e( 'Coordinadora takes the tracking number in the link. Servientrega, Interrapidísimo and TCC ask for it on their own page: the link takes you there. Any other one: its name and, if it has one, its link with {guia}.', 'dox-pos' ); ?></p>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Notice to the customer', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'When you mark an order as shipped, if it has an email address the customer gets one with the carrier, the tracking number and the tracking link, using the store\'s email design and sender. If it has a phone number, the register gets the WhatsApp message ready.', 'dox-pos' ); ?></p>
						</div>
						<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_sales[ship_email]" value="1" <?php checked( dox_pos_ship_email_on() ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'Email the customer when marking as shipped', 'dox-pos' ); ?></span></label>
						<div class="dp-field dp-mt">
							<label class="dp-label" for="dox_pos_ship_message"><?php esc_html_e( 'Shipping WhatsApp message', 'dox-pos' ); ?></label>
							<div class="dp-chips" id="dp-ship-placeholders" aria-label="<?php esc_attr_e( 'Placeholders', 'dox-pos' ); ?>">
								<?php foreach ( array( 'nombre', 'pedido', 'transportadora', 'guia', 'link', 'productos', 'tienda' ) as $p ) : ?>
								<button type="button" class="dp-chip" data-insert="{<?php echo esc_attr( $p ); ?>}">{<?php echo esc_html( $p ); ?>}</button>
								<?php endforeach; ?>
							</div>
							<textarea id="dox_pos_ship_message" name="dox_pos_ship_message" rows="4" class="dp-input dp-textarea"><?php echo esc_textarea( dox_pos_ship_message_template() ); ?></textarea>
							<p class="dp-hint"><?php esc_html_e( 'A line whose placeholder ends up empty (no tracking number, no link) removes itself.', 'dox-pos' ); ?> <button type="button" class="dp-btn dp-btn-link" id="dp-ship-reset"><?php esc_html_e( 'Default message', 'dox-pos' ); ?></button></p>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Costs and zones', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'The register has no rates of its own: it charges shipping just like the checkout, with WooCommerce zones. The country, the states and the cities come from the store settings.', 'dox-pos' ); ?></p>
							<a class="dp-btn dp-btn-soft dp-card-action" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=shipping' ) ); ?>"><?php echo wp_kses( dox_pos_icon( 'truck' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Shipping zones', 'dox-pos' ); ?></a>
						</div>
						<dl class="dp-facts">
							<div><dt><?php echo wp_kses( dox_pos_icon( 'pin' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Country', 'dox-pos' ); ?></dt><dd><?php echo esc_html( $cname ); ?> <i><?php echo esc_html( sprintf( /* translators: 1: etiqueta (Departamento), 2: cuántos */ __( '%1$s: %2$d', 'dox-pos' ), dox_pos_state_label(), count( $states ) ) ); ?></i></dd></div>
							<div><dt><?php echo wp_kses( dox_pos_icon( 'coins' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Currency', 'dox-pos' ); ?></dt><dd><?php echo esc_html( get_woocommerce_currency() ); ?> <i><?php echo esc_html( sprintf( /* translators: %s: importe de ejemplo */ __( 'written as %s', 'dox-pos' ), dox_pos_money( 12000 ) ) ); ?></i></dd></div>
							<div><dt><?php echo wp_kses( dox_pos_icon( 'truck' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Zones', 'dox-pos' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: zonas */ _n( '%d shipping zone', '%d shipping zones', $zones, 'dox-pos' ), $zones ) ); ?></dd></div>
							<div><dt><?php echo wp_kses( dox_pos_icon( 'chat' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Cities', 'dox-pos' ); ?></dt><dd><?php echo function_exists( 'colciu_get_ciudades' ) ? esc_html__( 'With a list to choose from (Colciudades)', 'dox-pos' ) : esc_html__( 'Typed by hand', 'dox-pos' ); ?></dd></div>
						</dl>
					</div>
				</section>

				<!-- ===================== Productos ===================== -->
				<section class="dp-panel" id="dp-panel-productos" data-panel="productos" role="tabpanel" aria-labelledby="dp-tab-productos" hidden>
					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Photos', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'Photos uploaded from the register are resized and stored as WebP on the server, with their sizes; the original (JPG, PNG or iPhone HEIC) is not kept.', 'dox-pos' ); ?></p>
						</div>
						<div class="dp-grid-2">
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-quality"><?php esc_html_e( 'WebP quality', 'dox-pos' ); ?></label>
								<div class="dp-unitfield">
									<input type="number" min="50" max="100" step="1" id="dp-quality" name="dox_pos_products[quality]" value="<?php echo esc_attr( $products['quality'] ); ?>" class="dp-input" inputmode="numeric">
									<span class="dp-unit">%</span>
								</div>
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php esc_html_e( 'Default is 88: a 12 MP iPhone photo ends up around 300 KB (at 82, around 210). Above 92 it is barely noticeable and weighs much more.', 'dox-pos' ); ?></p>
							</div>
							<div class="dp-field dp-field-short">
								<label class="dp-label" for="dp-maxpx"><?php esc_html_e( 'Longest side', 'dox-pos' ); ?></label>
								<div class="dp-unitfield">
									<input type="number" min="800" max="4000" step="100" id="dp-maxpx" name="dox_pos_products[max_px]" value="<?php echo esc_attr( $products['max_px'] ); ?>" class="dp-input" inputmode="numeric">
									<span class="dp-unit">px</span>
								</div>
								<p class="dp-status" aria-live="polite"></p>
								<p class="dp-hint"><?php esc_html_e( 'Default is 1600: plenty for the product page and the zoom. Smaller photos are left as they are.', 'dox-pos' ); ?></p>
							</div>
						</div>
						<div class="dp-callout">
							<?php echo wp_kses( dox_pos_icon( $heic ? 'check' : 'alert' ), dox_pos_svg_tags() ); ?>
							<div>
								<b><?php echo $heic ? esc_html__( 'This server reads HEIC photos', 'dox-pos' ) : esc_html__( 'This server does not read HEIC photos', 'dox-pos' ); ?></b>
								<span><?php echo $heic ? esc_html__( 'iPhone photos go in as they are and come out as WebP.', 'dox-pos' ) : esc_html__( 'ImageMagick with libheif is needed. In the meantime, the iPhone sends JPG if you choose "Most Compatible" in Settings > Camera > Formats.', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Sizes and colors', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'The WooCommerce attributes the register builds the variations with. They are detected by name; change them if the store calls them something else.', 'dox-pos' ); ?></p>
						</div>
						<?php if ( ! $attrs ) : ?>
						<p class="dp-hint"><?php esc_html_e( 'The store has no global attributes yet (Products > Attributes). Without them, the register creates one-size products.', 'dox-pos' ); ?></p>
						<?php else : ?>
						<div class="dp-grid-2">
							<?php foreach ( array( 'size_attr' => __( 'Size', 'dox-pos' ), 'color_attr' => __( 'Color', 'dox-pos' ) ) as $k => $label ) : ?>
							<div class="dp-field">
								<label class="dp-label" for="dp-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label>
								<select id="dp-<?php echo esc_attr( $k ); ?>" name="dox_pos_products[<?php echo esc_attr( $k ); ?>]" class="dp-input">
									<option value=""><?php echo esc_html( $products[ $k ] ? sprintf( /* translators: %s: atributo detectado */ __( 'Automatic: %s', 'dox-pos' ), $attrs[ $products[ $k ] ] ) : __( 'Automatic: none', 'dox-pos' ) ); ?></option>
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
							<h2><?php esc_html_e( 'The SKU', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'The register suggests the next free SKU for the prefix the category uses (if the last dress is VE82, it suggests VE83) and builds each variation\'s SKU like this:', 'dox-pos' ); ?></p>
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
							<h2><?php esc_html_e( 'Costs and profit', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'What each unit costs you, so you can see the profit of every sale in the history, in the Excel files and in the order detail. The cost is stored in the WooCommerce cost field (it also shows in its product editor) and every sale freezes it in the order: if the cost goes up later, past sales do not change. In Stock in, every purchase with its cost recalculates the product\'s average cost.', 'dox-pos' ); ?></p>
						</div>
						<?php if ( ! method_exists( 'WC_Product', 'get_cogs_value' ) ) : ?>
						<div class="dp-callout">
							<?php echo wp_kses( dox_pos_icon( 'alert' ), dox_pos_svg_tags() ); ?>
							<div>
								<b><?php esc_html_e( 'This version of WooCommerce does not have the cost field', 'dox-pos' ); ?></b>
								<span><?php esc_html_e( 'WooCommerce 9.5 or newer is needed. Once you update it, costs turn on by themselves.', 'dox-pos' ); ?></span>
							</div>
						</div>
						<?php endif; ?>
						<?php if ( method_exists( 'WC_Product', 'get_cogs_value' ) && dox_pos_costs_setting() && ! dox_pos_wc_cogs_enabled() ) : ?>
						<div class="dp-callout">
							<?php echo wp_kses( dox_pos_icon( 'info' ), dox_pos_svg_tags() ); ?>
							<div>
								<b><?php esc_html_e( 'The WooCommerce cost field still has to be turned on', 'dox-pos' ); ?></b>
								<span><?php esc_html_e( 'Save these settings with the switch on and it turns on (WooCommerce > Settings > Advanced > Features > Cost of Goods Sold). Until then, the register does not ask for costs.', 'dox-pos' ); ?></span>
							</div>
						</div>
						<?php endif; ?>
						<label class="dp-switch"><input type="checkbox" role="switch" name="dox_pos_products[costs]" value="1" <?php checked( dox_pos_costs_setting() ); ?>><span class="dp-switch-ui" aria-hidden="true"></span><span class="dp-switch-text"><?php esc_html_e( 'Track product costs and see the profit (turns on the WooCommerce cost field)', 'dox-pos' ); ?></span></label>
						<p class="dp-hint"><?php esc_html_e( 'Administrators and shop managers see it; the Cashier role sells and records stock without seeing costs. To load costs all at once: download the inventory as Excel from the register, fill in the Cost column and upload it with "Upload costs from Excel" in Stock in.', 'dox-pos' ); ?></p>
					</div>

					<div class="dp-card">
						<div class="dp-card-head">
							<h2><?php esc_html_e( 'Who creates products', 'dox-pos' ); ?></h2>
							<p><?php esc_html_e( 'The "Products" tab (create a new one or edit one) is for administrators and shop managers. Someone with only the Cashier role sells and records stock, but does not touch products.', 'dox-pos' ); ?></p>
							<a class="dp-btn dp-btn-soft dp-card-action" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php echo wp_kses( dox_pos_icon( 'users' ), dox_pos_svg_tags() ); ?><?php esc_html_e( 'Users', 'dox-pos' ); ?></a>
						</div>
					</div>
				</section>

				<?php do_action( 'dox_pos_settings_panels' ); // Las pestañas de los añadidos (el Pro pone Asistente). ?>
			</form>

			<aside class="dp-side" aria-label="<?php esc_attr_e( 'Preview', 'dox-pos' ); ?>">
				<div class="dp-side-head">
					<span class="dp-side-title"><?php esc_html_e( 'Preview', 'dox-pos' ); ?></span>
					<span class="dp-live"><i aria-hidden="true"></i><?php esc_html_e( 'Live', 'dox-pos' ); ?></span>
				</div>
				<div class="dp-device" id="dp-preview" style="--ui:<?php echo esc_attr( '"' . $fonts['ui'] . '",sans-serif' ); ?>;--serif:<?php echo esc_attr( '"' . $fonts['serif'] . '",serif' ); ?>">
					<div class="dp-chrome"><span class="dp-dots" aria-hidden="true"><i></i><i></i><i></i></span><span class="dp-url" id="dp-preview-url"><?php echo esc_html( $host . '/' . $slug ); ?></span></div>

					<div class="dp-mock" data-view="caja">
						<div class="dp-bar">
							<img class="dp-logo-prev" id="dp-preview-logo" src="<?php echo esc_url( $logo ); ?>" alt="" <?php echo $logo ? '' : 'hidden'; ?>>
							<span class="dp-brand" id="dp-preview-brand" <?php echo $logo ? 'hidden' : ''; ?>><?php echo esc_html( dox_pos_brand_name() ); ?></span>
							<span class="dp-cajita" id="dp-preview-screen"><?php echo esc_html( dox_pos_screen_name() ); ?></span>
							<span class="dp-tabs"><span class="dp-tab on"><?php esc_html_e( 'Sell', 'dox-pos' ); ?></span><span class="dp-tab"><?php esc_html_e( 'Orders', 'dox-pos' ); ?></span></span>
						</div>
						<div class="dp-body-prev">
							<div class="dp-card-prev">
								<p class="dp-lbl"><?php esc_html_e( 'How the sale came in', 'dox-pos' ); ?></p>
								<p class="dp-chips-prev" id="dp-preview-channels"></p>
								<p class="dp-line"><span class="dp-thumb">VE</span><span><?php esc_html_e( 'Ella dress', 'dox-pos' ); ?><i>M · Rosa</i></span><span class="dp-tag-prev"><?php esc_html_e( 'Layaway', 'dox-pos' ); ?></span></p>
								<p class="dp-lbl"><?php esc_html_e( 'How they pay', 'dox-pos' ); ?></p>
								<p class="dp-chips-prev" id="dp-preview-payments"></p>
								<p class="dp-total"><span><?php esc_html_e( 'Total', 'dox-pos' ); ?></span><b><?php echo esc_html( dox_pos_money( 189000 ) ); ?></b></p>
								<span class="dp-go"><?php esc_html_e( 'Paid: record the sale', 'dox-pos' ); ?></span>
								<span class="dp-go alt"><?php esc_html_e( 'Not paid yet: put on layaway', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<div class="dp-mock dp-wa" data-view="whatsapp" hidden>
						<div class="dp-wa-head"><span class="dp-wa-avatar">A</span><span class="dp-wa-who"><b><?php esc_html_e( 'Ana', 'dox-pos' ); ?></b><i><?php esc_html_e( 'online', 'dox-pos' ); ?></i></span></div>
						<div class="dp-wa-body"><div class="dp-wa-bubble"><p id="dp-preview-message"></p><span class="dp-wa-time">10:42</span></div></div>
					</div>

					<div class="dp-mock dp-envio" data-view="envio" hidden>
						<div class="dp-modal-prev">
							<p class="dp-modal-title"><?php esc_html_e( 'Mark as shipped', 'dox-pos' ); ?></p>
							<p class="dp-lbl"><?php esc_html_e( 'Carrier', 'dox-pos' ); ?></p>
							<p class="dp-chips-prev" id="dp-preview-carriers"></p>
							<p class="dp-lbl"><?php esc_html_e( 'Tracking number', 'dox-pos' ); ?></p>
							<span class="dp-fake-input"></span>
							<span class="dp-go"><?php esc_html_e( 'Save', 'dox-pos' ); ?></span>
						</div>
					</div>

					<div class="dp-mock" data-view="producto" hidden>
						<div class="dp-bar">
							<span class="dp-cajita" style="border-left:0;padding-left:0"><?php echo esc_html( dox_pos_screen_name() ); ?></span>
							<span class="dp-tabs"><span class="dp-tab"><?php esc_html_e( 'Sell', 'dox-pos' ); ?></span><span class="dp-tab on"><?php esc_html_e( 'Products', 'dox-pos' ); ?></span></span>
						</div>
						<div class="dp-body-prev">
							<div class="dp-card-prev">
								<p class="dp-lbl"><?php esc_html_e( 'Photos', 'dox-pos' ); ?></p>
								<div class="dp-photos">
									<span class="dp-photo"><i>WebP · <b id="dp-preview-quality"><?php echo esc_html( $products['quality'] ); ?></b> %</i></span>
									<span class="dp-photo"><i><b id="dp-preview-px"><?php echo esc_html( $products['max_px'] ); ?></b> px</i></span>
									<span class="dp-photo add"><?php echo wp_kses( dox_pos_icon( 'camera' ), dox_pos_svg_tags() ); ?></span>
								</div>
								<p class="dp-lbl"><?php esc_html_e( 'Sizes', 'dox-pos' ); ?></p>
								<p class="dp-chips-prev"><span class="on">0-6 M</span><span class="on">6-12 M</span><span class="on">12-18 M</span><span>18-24 M</span></p>
								<p class="dp-lbl"><?php esc_html_e( 'SKU', 'dox-pos' ); ?></p>
								<span class="dp-fake-input dp-mono" id="dp-preview-sku">VE83</span>
								<span class="dp-go"><?php esc_html_e( 'Create product', 'dox-pos' ); ?></span>
							</div>
						</div>
					</div>

					<?php do_action( 'dox_pos_settings_mocks' ); // Las vistas previas de los añadidos. ?>
				</div>
				<p class="dp-side-note"><?php esc_html_e( 'What you change shows up here right away. In the register, when you save.', 'dox-pos' ); ?></p>
			</aside>
		</div>
	</div>
	<?php
}
