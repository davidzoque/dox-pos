<?php
/**
 * El asistente, parte 1: la conexión con OpenAI.
 *
 * Los ajustes (clave, modelo, tope de gasto, resumen diario y umbrales de los avisos), la
 * llamada a la API de respuestas de OpenAI (con funciones, imágenes y salidas con esquema),
 * el registro de cada llamada con sus tokens y su costo, el tope mensual, y la tarea
 * programada que manda el resumen diario por correo. La clave vive solo en el servidor:
 * nunca sale al navegador ni a la API de la caja.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DOX_POS_AI_MODEL = 'gpt-5.6-luna';

/* =====================================================================
 * Ajustes
 * ===================================================================== */

function dox_pos_ai_defaults() {
	return array(
		'key'          => '',
		'model'        => DOX_POS_AI_MODEL,
		'cap'          => 5,   // USD al mes.
		'summary_on'   => 1,
		'summary_hour' => 8,   // Hora de la tienda.
		'summary_to'   => '',  // Correos separados por coma. Vacío: el del administrador.
		'ship_days'    => 2,   // "Por enviar" desde hace más de N días.
		'deliver_days' => 6,   // "Enviado" hace más de N días sin marcar entregado.
		'pay_hours'    => 24,  // Un pago de la web sin confirmar más de N horas.
		'low_stock'    => 2,   // Se avisa cuando de algo que se vende quedan N o menos.
	);
}

/**
 * Los ajustes ya validados.
 *
 * @return array
 */
function dox_pos_ai_settings() {
	$s = get_option( 'dox_pos_ai', array() );
	$s = is_array( $s ) ? $s : array();
	$d = dox_pos_ai_defaults();
	$o = array_merge( $d, array_intersect_key( $s, $d ) );
	$o['key']          = (string) $o['key'];
	$o['model']        = trim( (string) $o['model'] ) ? trim( (string) $o['model'] ) : DOX_POS_AI_MODEL;
	$o['cap']          = (float) $o['cap'] > 0 ? (float) $o['cap'] : 5.0;
	$o['summary_on']   = ! empty( $o['summary_on'] );
	$o['summary_hour'] = min( 23, max( 0, (int) $o['summary_hour'] ) );
	$o['summary_to']   = (string) $o['summary_to'];
	$o['ship_days']    = max( 1, (int) $o['ship_days'] );
	$o['deliver_days'] = max( 1, (int) $o['deliver_days'] );
	$o['pay_hours']    = max( 1, (int) $o['pay_hours'] );
	$o['low_stock']    = max( 0, (int) $o['low_stock'] );
	return $o;
}

function dox_pos_ai_key() {
	return dox_pos_ai_settings()['key'];
}

/**
 * ¿Hay clave? Sin ella el asistente enseña los números y los consejos por reglas, pero no
 * redacta ni responde en el chat.
 */
function dox_pos_ai_enabled() {
	return '' !== dox_pos_ai_key();
}

/**
 * Las últimas letras de la clave, para que la página de ajustes diga cuál hay sin enseñarla.
 */
function dox_pos_ai_key_hint() {
	$k = dox_pos_ai_key();
	return '' === $k ? '' : substr( $k, -4 );
}

function dox_pos_ai_model() {
	return dox_pos_ai_settings()['model'];
}

/**
 * A quién va el resumen diario.
 *
 * @return string[]
 */
function dox_pos_ai_recipients() {
	$raw = dox_pos_ai_settings()['summary_to'];
	$out = array();
	foreach ( preg_split( '/[\s,;]+/', $raw ) as $e ) {
		$e = sanitize_email( $e );
		if ( $e && is_email( $e ) ) {
			$out[] = $e;
		}
	}
	if ( ! $out ) {
		$admin = sanitize_email( get_option( 'admin_email' ) );
		if ( $admin ) {
			$out[] = $admin;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Valida lo que llega de la página de ajustes. La clave se guarda solo si viene una nueva
 * (el campo va vacío al pintar la página); "quitar" la borra.
 */
function dox_pos_sanitize_ai( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$old = get_option( 'dox_pos_ai', array() );
	$old = is_array( $old ) ? $old : array();
	$out = dox_pos_ai_defaults();
	$key = trim( (string) ( $in['key'] ?? '' ) );
	if ( '' === $key ) {
		$key = (string) ( $old['key'] ?? '' );
	} elseif ( ! preg_match( '/^sk-[A-Za-z0-9_\-]{20,}$/', $key ) ) {
		add_settings_error( 'dox_pos', 'ai_key', __( 'Eso no parece una clave de OpenAI: empiezan por "sk-" y no llevan espacios. Se dejó la que había.', 'dox-pos' ) );
		$key = (string) ( $old['key'] ?? '' );
	}
	if ( ! empty( $in['forget'] ) ) {
		$key = '';
	}
	$out['key']   = $key;
	$model        = sanitize_text_field( (string) ( $in['model'] ?? '' ) );
	$out['model'] = preg_match( '/^[a-z0-9.\-_]{3,60}$/i', $model ) ? $model : DOX_POS_AI_MODEL;
	if ( $model && $out['model'] !== $model ) {
		add_settings_error( 'dox_pos', 'ai_model', __( 'El nombre del modelo solo lleva letras, números, puntos y guiones. Se dejó gpt-5.6-luna.', 'dox-pos' ) );
	}
	$cap = (float) str_replace( ',', '.', (string) ( $in['cap'] ?? 5 ) );
	if ( $cap < 0.5 || $cap > 1000 ) {
		add_settings_error( 'dox_pos', 'ai_cap', __( 'El tope mensual va de 0,5 a 1000 USD. Se dejó en 5.', 'dox-pos' ) );
		$cap = 5;
	}
	$out['cap']          = round( $cap, 2 );
	$out['summary_on']   = empty( $in['summary_on'] ) ? 0 : 1;
	$out['summary_hour'] = min( 23, max( 0, (int) ( $in['summary_hour'] ?? 8 ) ) );
	$mails               = array();
	$bad                 = array();
	foreach ( preg_split( '/[\s,;]+/', (string) ( $in['summary_to'] ?? '' ) ) as $e ) {
		if ( '' === trim( $e ) ) {
			continue;
		}
		$s = sanitize_email( $e );
		if ( $s && is_email( $s ) ) {
			$mails[] = $s;
		} else {
			$bad[] = $e;
		}
	}
	if ( $bad ) {
		/* translators: %s: lo que no era un correo */
		add_settings_error( 'dox_pos', 'ai_to', sprintf( __( 'No es un correo: %s. Se quitó de la lista.', 'dox-pos' ), implode( ', ', array_map( 'sanitize_text_field', $bad ) ) ) );
	}
	$out['summary_to']   = implode( ', ', array_unique( $mails ) );
	$out['ship_days']    = min( 60, max( 1, (int) ( $in['ship_days'] ?? 2 ) ) );
	$out['deliver_days'] = min( 90, max( 1, (int) ( $in['deliver_days'] ?? 6 ) ) );
	$out['pay_hours']    = min( 720, max( 1, (int) ( $in['pay_hours'] ?? 24 ) ) );
	$out['low_stock']    = min( 100, max( 0, (int) ( $in['low_stock'] ?? 2 ) ) );
	return $out;
}

// Al guardar los ajustes se reprograma el resumen diario con su hora nueva.
add_action( 'update_option_dox_pos_ai', 'dox_pos_ai_on_update', 10, 2 );
add_action( 'add_option_dox_pos_ai', 'dox_pos_ai_on_add', 10, 2 );
function dox_pos_ai_on_update( $old, $new ) {
	dox_pos_ai_schedule( is_array( $new ) ? $new : null );
	delete_transient( 'dox_pos_ai_advice' );
}
function dox_pos_ai_on_add( $name, $new ) {
	dox_pos_ai_schedule( is_array( $new ) ? $new : null );
}

/* =====================================================================
 * Precios y costo
 * ===================================================================== */

/**
 * Lo que cobra OpenAI por millón de tokens (entrada, entrada en caché, salida), en USD, según
 * su lista de precios del 5/09/2026. Un modelo desconocido se estima como terra, por lo alto.
 *
 * @return array{in:float,cached:float,out:float,known:bool}
 */
function dox_pos_ai_prices( $model ) {
	$known = array(
		'gpt-5.6-luna'  => array( 0.20, 0.02, 1.20 ),
		'gpt-5.6-terra' => array( 2.00, 0.20, 12.00 ),
		'gpt-5.6-sol'   => array( 4.00, 0.40, 20.00 ),
		'gpt-6-astra'   => array( 10.00, 1.00, 50.00 ),
		'gpt-5-mini'    => array( 0.25, 0.025, 2.00 ),
		'gpt-5-nano'    => array( 0.05, 0.005, 0.40 ),
		'gpt-5.1'       => array( 1.25, 0.125, 10.00 ),
		'gpt-5'         => array( 1.25, 0.125, 10.00 ),
	);
	$m = strtolower( trim( (string) $model ) );
	foreach ( $known as $k => $p ) {
		if ( 0 === strpos( $m, $k ) ) {
			return array( 'in' => $p[0], 'cached' => $p[1], 'out' => $p[2], 'known' => true );
		}
	}
	return array( 'in' => 2.00, 'cached' => 0.20, 'out' => 12.00, 'known' => false );
}

/**
 * Lo que costó una llamada, en USD.
 *
 * @param string $model El modelo.
 * @param array  $usage in, cached, out.
 * @return float
 */
function dox_pos_ai_cost( $model, $usage ) {
	$p      = dox_pos_ai_prices( $model );
	$in     = max( 0, (int) ( $usage['in'] ?? 0 ) );
	$cached = min( $in, max( 0, (int) ( $usage['cached'] ?? 0 ) ) );
	$out    = max( 0, (int) ( $usage['out'] ?? 0 ) );
	return round( ( ( $in - $cached ) * $p['in'] + $cached * $p['cached'] + $out * $p['out'] ) / 1000000, 6 );
}

function dox_pos_ai_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'dox_pos_ai_log';
}

function dox_pos_ai_actions_table() {
	global $wpdb;
	return $wpdb->prefix . 'dox_pos_ai_actions';
}

/**
 * Apunta una llamada (o su fallo).
 */
function dox_pos_ai_log( $kind, $model, $usage, $cost, $ms, $ok, $note = '' ) {
	global $wpdb;
	try {
		$wpdb->insert(
			dox_pos_ai_log_table(),
			array(
				'created_at'    => current_time( 'mysql' ),
				'user_id'       => get_current_user_id(),
				'kind'          => substr( (string) $kind, 0, 20 ),
				'model'         => substr( (string) $model, 0, 60 ),
				'tokens_in'     => (int) ( $usage['in'] ?? 0 ),
				'tokens_cached' => (int) ( $usage['cached'] ?? 0 ),
				'tokens_out'    => (int) ( $usage['out'] ?? 0 ),
				'cost'          => (float) $cost,
				'ms'            => (int) $ms,
				'ok'            => $ok ? 1 : 0,
				'note'          => mb_substr( (string) $note, 0, 255 ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%f', '%d', '%d', '%s' )
		);
	} catch ( Throwable $e ) {
		error_log( 'Dox POS asistente: no se pudo apuntar la llamada: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * El uso de este mes (de la tienda): llamadas, tokens y costo.
 *
 * @return array{calls:int,cost:float,tokens:int,failed:int,since:string}
 */
function dox_pos_ai_month_usage() {
	global $wpdb;
	$since = wp_date( 'Y-m-01 00:00:00' );
	$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) n, COALESCE(SUM(cost),0) c, COALESCE(SUM(tokens_in+tokens_out),0) t, COALESCE(SUM(1-ok),0) f FROM ' . dox_pos_ai_log_table() . ' WHERE created_at >= %s', $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return array(
		'calls'  => (int) ( $row['n'] ?? 0 ),
		'cost'   => round( (float) ( $row['c'] ?? 0 ), 4 ),
		'tokens' => (int) ( $row['t'] ?? 0 ),
		'failed' => (int) ( $row['f'] ?? 0 ),
		'since'  => $since,
	);
}

/**
 * Cómo se llama cada tipo de llamada en pantalla.
 */
function dox_pos_ai_kind_label( $kind ) {
	$map = array(
		'summary'  => __( 'Resumen diario', 'dox-pos' ),
		'advice'   => __( 'Consejos', 'dox-pos' ),
		'chat'     => __( 'Chat', 'dox-pos' ),
		'describe' => __( 'Descripciones', 'dox-pos' ),
		'test'     => __( 'Prueba de la clave', 'dox-pos' ),
	);
	$k = (string) $kind;
	return $map[ $k ] ?? ( '' === $k ? __( 'Otra', 'dox-pos' ) : $k );
}

/**
 * El gasto del asistente para la pestaña Uso: este mes contra el tope, hoy, por tipo, día a día
 * y las últimas llamadas. Todo sale del registro (dox_pos_ai_log), que guarda 90 días.
 *
 * @param int $days Cuántos días pinta el gráfico.
 * @return array
 */
function dox_pos_ai_usage( $days = 30 ) {
	global $wpdb;
	$t     = dox_pos_ai_log_table();
	$s     = dox_pos_ai_settings();
	$days  = min( 90, max( 7, (int) $days ) );
	$now   = current_time( 'timestamp' );
	$month = dox_pos_ai_month_usage();
	$mday  = (int) wp_date( 'j', $now );
	$mdays = (int) wp_date( 't', $now );

	$hoy = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) n, COALESCE(SUM(cost),0) c FROM ' . $t . ' WHERE created_at >= %s', wp_date( 'Y-m-d 00:00:00', $now ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	$kinds = array();
	$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT kind, COUNT(*) n, COALESCE(SUM(cost),0) c, COALESCE(SUM(1-ok),0) f FROM ' . $t . ' WHERE created_at >= %s GROUP BY kind ORDER BY c DESC', $month['since'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	foreach ( (array) $rows as $r ) {
		$kinds[] = array( 'kind' => (string) $r['kind'], 'label' => dox_pos_ai_kind_label( $r['kind'] ), 'calls' => (int) $r['n'], 'cost' => round( (float) $r['c'], 4 ), 'failed' => (int) $r['f'] );
	}

	// Día a día, con los días sin nada en cero para que el gráfico no mienta.
	$from = wp_date( 'Y-m-d', $now - ( $days - 1 ) * DAY_IN_SECONDS );
	$byd  = array();
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(created_at) d, COUNT(*) n, COALESCE(SUM(cost),0) c FROM ' . $t . ' WHERE created_at >= %s GROUP BY d', $from . ' 00:00:00' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	foreach ( (array) $rows as $r ) {
		$byd[ (string) $r['d'] ] = array( 'calls' => (int) $r['n'], 'cost' => round( (float) $r['c'], 4 ) );
	}
	$daily = array();
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$ts   = $now - $i * DAY_IN_SECONDS;
		$key  = wp_date( 'Y-m-d', $ts );
		$daily[] = array(
			'date'  => $key,
			'label' => wp_date( 'j/n', $ts ),
			'day'   => (int) wp_date( 'j', $ts ),
			'calls' => $byd[ $key ]['calls'] ?? 0,
			'cost'  => $byd[ $key ]['cost'] ?? 0,
		);
	}

	// Las últimas llamadas, con quién las pidió (las del cron no tienen persona).
	$recent = array();
	$rows   = $wpdb->get_results( 'SELECT created_at, kind, model, tokens_in, tokens_cached, tokens_out, cost, ms, ok, note, user_id FROM ' . $t . ' ORDER BY id DESC LIMIT 20', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	foreach ( (array) $rows as $r ) {
		$u   = (int) $r['user_id'] ? get_userdata( (int) $r['user_id'] ) : null;
		$recent[] = array(
			'at'     => mysql2date( 'j/n H:i', $r['created_at'] ),
			'kind'   => (string) $r['kind'],
			'label'  => dox_pos_ai_kind_label( $r['kind'] ),
			'who'    => $u ? $u->display_name : __( 'automático', 'dox-pos' ),
			'tokens' => (int) $r['tokens_in'] + (int) $r['tokens_out'],
			'cached' => (int) $r['tokens_cached'],
			'cost'   => round( (float) $r['cost'], 6 ),
			'secs'   => round( (int) $r['ms'] / 1000, 1 ),
			'ok'     => (bool) (int) $r['ok'],
			'note'   => (string) $r['note'],
		);
	}

	$all   = $wpdb->get_row( 'SELECT COUNT(*) n, COALESCE(SUM(cost),0) c, MIN(created_at) f FROM ' . $t, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$price = dox_pos_ai_prices( $s['model'] );
	return array(
		'month'   => array(
			'name'       => ucfirst( wp_date( 'F', $now ) ),
			'cost'       => $month['cost'],
			'calls'      => $month['calls'],
			'tokens'     => $month['tokens'],
			'failed'     => $month['failed'],
			'day'        => $mday,
			'days'       => $mdays,
			'projection' => round( $mday > 0 ? $month['cost'] / $mday * $mdays : 0, 4 ),
		),
		'today'   => array( 'calls' => (int) ( $hoy['n'] ?? 0 ), 'cost' => round( (float) ( $hoy['c'] ?? 0 ), 4 ) ),
		'cap'     => (float) $s['cap'],
		'kinds'   => $kinds,
		'daily'   => $daily,
		'recent'  => $recent,
		'all'     => array(
			'calls' => (int) ( $all['n'] ?? 0 ),
			'cost'  => round( (float) ( $all['c'] ?? 0 ), 4 ),
			'since' => $all['f'] ? mysql2date( 'j \d\e F', $all['f'] ) : '',
		),
		'model'   => $s['model'],
		'price'   => array( 'in' => $price['in'], 'out' => $price['out'], 'cached' => $price['cached'], 'known' => $price['known'] ),
		'keep'    => 90,
		'ready'   => dox_pos_ai_enabled(),
	);
}

/**
 * Null si se puede gastar; si no, el error que explica el tope.
 */
function dox_pos_ai_cap_problem() {
	$s = dox_pos_ai_settings();
	$u = dox_pos_ai_month_usage();
	if ( $u['cost'] >= $s['cap'] ) {
		/* translators: %s: tope en USD */
		return new WP_Error( 'dox_pos_ai_tope', sprintf( __( 'El asistente llegó al tope de gasto del mes (%s USD). Se sube en WooCommerce > Dox POS > Asistente.', 'dox-pos' ), number_format_i18n( $s['cap'], 2 ) ) );
	}
	return null;
}

/* =====================================================================
 * La llamada a OpenAI (API de respuestas)
 * ===================================================================== */

function dox_pos_ai_endpoint() {
	return (string) apply_filters( 'dox_pos_ai_endpoint', 'https://api.openai.com/v1/responses' );
}

/**
 * Manda el cuerpo tal cual y devuelve la respuesta decodificada, o un error que se entiende.
 *
 * @param array $body    El cuerpo de la petición.
 * @param int   $timeout Segundos.
 * @return array|WP_Error
 */
function dox_pos_ai_request( $body, $timeout = 60 ) {
	$r = wp_remote_post(
		dox_pos_ai_endpoint(),
		array(
			'timeout' => max( 10, (int) $timeout ),
			'headers' => array(
				'Authorization' => 'Bearer ' . dox_pos_ai_key(),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $r ) ) {
		/* translators: %s: el error de red */
		return new WP_Error( 'dox_pos_ai_red', sprintf( __( 'No se pudo hablar con OpenAI: %s', 'dox-pos' ), $r->get_error_message() ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $r );
	$j    = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	$msg  = is_array( $j ) && ! empty( $j['error']['message'] ) ? sanitize_text_field( (string) $j['error']['message'] ) : '';
	if ( 401 === $code ) {
		return new WP_Error( 'dox_pos_ai_clave', __( 'OpenAI no aceptó la clave: no es válida o se revocó. Revísala en WooCommerce > Dox POS > Asistente.', 'dox-pos' ) );
	}
	if ( 429 === $code ) {
		return new WP_Error( 'dox_pos_ai_limite', trim( __( 'OpenAI no atendió la llamada: la cuenta se quedó sin crédito o hubo demasiadas llamadas seguidas.', 'dox-pos' ) . ' ' . $msg ) );
	}
	if ( 404 === $code || ( 400 === $code && false !== stripos( $msg, 'model' ) ) ) {
		/* translators: 1: modelo, 2: lo que dijo OpenAI */
		return new WP_Error( 'dox_pos_ai_modelo', sprintf( __( 'OpenAI no reconoce el modelo "%1$s". %2$s', 'dox-pos' ), dox_pos_ai_model(), $msg ) );
	}
	if ( $code >= 400 || ! is_array( $j ) ) {
		/* translators: 1: código HTTP, 2: mensaje */
		return new WP_Error( 'dox_pos_ai_error', trim( sprintf( __( 'OpenAI respondió con un error (%1$d). %2$s', 'dox-pos' ), $code, $msg ) ) );
	}
	if ( ! empty( $j['error'] ) ) {
		return new WP_Error( 'dox_pos_ai_error', $msg ? $msg : __( 'OpenAI devolvió un error.', 'dox-pos' ) );
	}
	return $j;
}

/**
 * Una llamada al modelo. Comprueba la clave y el tope, arma la petición, la apunta y devuelve
 * el texto, las llamadas a funciones que pidió el modelo y lo que costó.
 *
 * @param array $a kind (para el registro), instructions, input (los items de la conversación),
 *                 tools, tool_choice, effort (none|low|medium|high), max_output, schema
 *                 (name + schema para una salida JSON), timeout.
 * @return array|WP_Error output, text, refusal, calls, usage, cost, ms, status, incomplete.
 */
function dox_pos_ai_call( $a ) {
	$s = dox_pos_ai_settings();
	if ( '' === $s['key'] ) {
		return new WP_Error( 'dox_pos_ai_sin_clave', __( 'Falta la clave de OpenAI. Se pone en WooCommerce > Dox POS > Asistente.', 'dox-pos' ) );
	}
	$cap = dox_pos_ai_cap_problem();
	if ( $cap ) {
		return $cap;
	}
	$body = array(
		'model'             => $s['model'],
		'input'             => array_values( (array) $a['input'] ),
		'store'             => false,
		'max_output_tokens' => max( 200, (int) ( $a['max_output'] ?? 2000 ) ),
	);
	if ( ! empty( $a['instructions'] ) ) {
		$body['instructions'] = (string) $a['instructions'];
	}
	if ( ! empty( $a['tools'] ) ) {
		$body['tools']               = array_values( $a['tools'] );
		$body['tool_choice']         = $a['tool_choice'] ?? 'auto';
		$body['parallel_tool_calls'] = true;
		$body['include']             = array( 'reasoning.encrypted_content' ); // Sin guardar nada en OpenAI, el razonamiento vuelve cifrado y se le devuelve en la vuelta siguiente.
	}
	$effort = (string) ( $a['effort'] ?? 'low' );
	if ( '' !== $effort ) {
		$body['reasoning'] = array( 'effort' => $effort );
	}
	if ( ! empty( $a['schema'] ) ) {
		$body['text'] = array(
			'format' => array(
				'type'   => 'json_schema',
				'name'   => (string) $a['schema']['name'],
				'schema' => $a['schema']['schema'],
				'strict' => true,
			),
		);
	}
	$body = apply_filters( 'dox_pos_ai_request_body', $body, $a );
	$t0   = microtime( true );
	$r    = dox_pos_ai_request( $body, (int) ( $a['timeout'] ?? 60 ) );
	$ms   = (int) round( ( microtime( true ) - $t0 ) * 1000 );
	$kind = (string) ( $a['kind'] ?? 'other' );
	if ( is_wp_error( $r ) ) {
		dox_pos_ai_log( $kind, $s['model'], array(), 0, $ms, false, $r->get_error_message() );
		return $r;
	}
	$usage = array(
		'in'        => (int) ( $r['usage']['input_tokens'] ?? 0 ),
		'cached'    => (int) ( $r['usage']['input_tokens_details']['cached_tokens'] ?? 0 ),
		'out'       => (int) ( $r['usage']['output_tokens'] ?? 0 ),
		'reasoning' => (int) ( $r['usage']['output_tokens_details']['reasoning_tokens'] ?? 0 ),
	);
	$cost  = dox_pos_ai_cost( $s['model'], $usage );
	dox_pos_ai_log( $kind, $s['model'], $usage, $cost, $ms, true, '' );
	$output = isset( $r['output'] ) && is_array( $r['output'] ) ? $r['output'] : array();
	return array(
		'output'     => $output,
		'text'       => dox_pos_ai_output_text( $output ),
		'refusal'    => dox_pos_ai_output_refusal( $output ),
		'calls'      => dox_pos_ai_output_calls( $output ),
		'usage'      => $usage,
		'cost'       => $cost,
		'ms'         => $ms,
		'status'     => (string) ( $r['status'] ?? '' ),
		'incomplete' => (string) ( $r['incomplete_details']['reason'] ?? '' ),
	);
}

/**
 * El texto que escribió el modelo (todos los mensajes de la respuesta, seguidos).
 */
function dox_pos_ai_output_text( $output ) {
	$parts = array();
	foreach ( (array) $output as $item ) {
		if ( 'message' !== ( $item['type'] ?? '' ) ) {
			continue;
		}
		foreach ( (array) ( $item['content'] ?? array() ) as $c ) {
			if ( 'output_text' === ( $c['type'] ?? '' ) && isset( $c['text'] ) ) {
				$parts[] = (string) $c['text'];
			}
		}
	}
	return trim( implode( "\n", $parts ) );
}

function dox_pos_ai_output_refusal( $output ) {
	foreach ( (array) $output as $item ) {
		if ( 'message' !== ( $item['type'] ?? '' ) ) {
			continue;
		}
		foreach ( (array) ( $item['content'] ?? array() ) as $c ) {
			if ( 'refusal' === ( $c['type'] ?? '' ) ) {
				return (string) ( $c['refusal'] ?? '' );
			}
		}
	}
	return '';
}

/**
 * Las funciones que el modelo quiere que se ejecuten.
 *
 * @return array<int,array{call_id:string,name:string,arguments:array}>
 */
function dox_pos_ai_output_calls( $output ) {
	$calls = array();
	foreach ( (array) $output as $item ) {
		if ( 'function_call' !== ( $item['type'] ?? '' ) ) {
			continue;
		}
		$args = json_decode( (string) ( $item['arguments'] ?? '' ), true );
		$calls[] = array(
			'call_id'   => (string) ( $item['call_id'] ?? '' ),
			'name'      => (string) ( $item['name'] ?? '' ),
			'arguments' => is_array( $args ) ? $args : array(),
		);
	}
	return $calls;
}

/**
 * Una respuesta JSON del modelo (salida con esquema), decodificada. Null si no se pudo.
 */
function dox_pos_ai_json( $r ) {
	if ( is_wp_error( $r ) || '' === (string) ( $r['text'] ?? '' ) ) {
		return null;
	}
	$j = json_decode( (string) $r['text'], true );
	return is_array( $j ) ? $j : null;
}

/* =====================================================================
 * El resumen diario: la tarea programada y el correo
 * ===================================================================== */

add_action( 'dox_pos_ai_daily', 'dox_pos_ai_daily_run' );

/**
 * Programa (o reprograma) el resumen diario a la hora del ajuste, en la zona horaria de la
 * tienda. El cron de WordPress lo dispara (en rosella lo llama un cron real cada 5 minutos).
 *
 * @param array|null $s Los ajustes recién guardados, o null para leerlos.
 */
function dox_pos_ai_schedule( $s = null ) {
	$s = is_array( $s ) ? array_merge( dox_pos_ai_defaults(), $s ) : dox_pos_ai_settings();
	wp_clear_scheduled_hook( 'dox_pos_ai_daily' );
	if ( empty( $s['summary_on'] ) ) {
		return;
	}
	$hour = min( 23, max( 0, (int) $s['summary_hour'] ) );
	$next = new DateTime( 'today', wp_timezone() );
	$next->setTime( $hour, 0, 0 );
	if ( $next->getTimestamp() <= time() + 60 ) {
		$next->modify( '+1 day' );
	}
	wp_schedule_event( $next->getTimestamp(), 'daily', 'dox_pos_ai_daily' );
}

/**
 * Cuándo sale el próximo resumen, como texto ("mañana a las 8:00"), o vacío.
 */
function dox_pos_ai_next_summary() {
	$ts = wp_next_scheduled( 'dox_pos_ai_daily' );
	if ( ! $ts ) {
		return '';
	}
	$day = wp_date( 'Y-m-d', $ts );
	$hm  = wp_date( 'G:i', $ts );
	if ( $day === wp_date( 'Y-m-d' ) ) {
		/* translators: %s: hora */
		return sprintf( __( 'hoy a las %s', 'dox-pos' ), $hm );
	}
	if ( $day === wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ) {
		/* translators: %s: hora */
		return sprintf( __( 'mañana a las %s', 'dox-pos' ), $hm );
	}
	return wp_date( 'j \d\e F \a \l\a\s G:i', $ts );
}

/**
 * Si el resumen de hoy no salió y ya pasó su hora (el servidor estuvo apagado, o la tarea se
 * perdió), se pide para ya. Se llama al abrir la caja: cuesta dos lecturas de opciones.
 */
function dox_pos_ai_catchup() {
	dox_pos_ai_purge();
	$s = dox_pos_ai_settings();
	if ( ! $s['summary_on'] ) {
		return;
	}
	if ( ! wp_next_scheduled( 'dox_pos_ai_daily' ) ) {
		dox_pos_ai_schedule( $s );
	}
	$last = get_option( 'dox_pos_ai_summary', array() );
	if ( ( $last['date'] ?? '' ) === wp_date( 'Y-m-d' ) || (int) wp_date( 'G' ) < $s['summary_hour'] ) {
		return;
	}
	if ( ! wp_next_scheduled( 'dox_pos_ai_daily', array( 'catchup' ) ) ) {
		wp_schedule_single_event( time(), 'dox_pos_ai_daily', array( 'catchup' ) );
	}
}

/**
 * Arma y manda el resumen del día. Con $force se manda aunque ya haya salido hoy.
 *
 * @return array|WP_Error Lo guardado: date, text, html, to, sent, at.
 */
function dox_pos_ai_daily_run( $force = false ) {
	dox_pos_ai_purge();
	$force = (bool) $force && 'catchup' !== $force;
	$s     = dox_pos_ai_settings();
	$today = wp_date( 'Y-m-d' );
	$last  = get_option( 'dox_pos_ai_summary', array() );
	if ( ! $force && ( ! $s['summary_on'] || ( $last['date'] ?? '' ) === $today ) ) {
		return is_array( $last ) ? $last : array();
	}
	if ( ! function_exists( 'dox_pos_ai_summary_build' ) ) {
		return new WP_Error( 'dox_pos_ai_sin_modulo', 'Falta advisor.php' );
	}
	$sum  = dox_pos_ai_summary_build();
	$to   = dox_pos_ai_recipients();
	$sent = $to ? dox_pos_ai_send_mail( $to, $sum['subject'], $sum['html'] ) : false;
	$out  = array(
		'date'  => $today,
		'text'  => $sum['text'],
		'html'  => $sum['html'],
		'to'    => $to,
		'sent'  => (bool) $sent,
		'at'    => wp_date( 'G:i' ),
		'model' => $sum['model'],
	);
	update_option( 'dox_pos_ai_summary', $out, false );
	return $out;
}

/**
 * Un correo en HTML con el nombre de la marca como remitente.
 */
function dox_pos_ai_send_mail( $to, $subject, $html ) {
	$name = function () {
		return dox_pos_brand_name();
	};
	add_filter( 'wp_mail_from_name', $name );
	$ok = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	remove_filter( 'wp_mail_from_name', $name );
	return (bool) $ok;
}

/**
 * Lo que envejece se va, una vez al día: las llamadas apuntadas y las acciones de más de 90
 * días. Las llamadas de los últimos meses hacen falta para el tope mensual; las acciones solo
 * se deshacen 24 horas. El chat no se guarda en el servidor (en el navegador dura 7 días).
 */
function dox_pos_ai_purge( $days = 90 ) {
	global $wpdb;
	$today = wp_date( 'Y-m-d' );
	if ( get_option( 'dox_pos_ai_purged' ) === $today ) {
		return;
	}
	update_option( 'dox_pos_ai_purged', $today, false );
	$edge = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - max( 31, (int) $days ) * DAY_IN_SECONDS );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . dox_pos_ai_log_table() . ' WHERE created_at < %s', $edge ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . dox_pos_ai_actions_table() . ' WHERE created_at < %s', $edge ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
