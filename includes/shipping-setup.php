<?php
/**
 * Los costos de envío, puestos desde la caja (el engranaje > Costos de envío).
 *
 * No hay tarifas propias: lo que se arma aquí son las zonas y los métodos de envío de WooCommerce,
 * con los cuatro casos que usa casi cualquier tienda (precio fijo, por peso, gratis y recoger en
 * tienda). Por eso cobra lo mismo el checkout de la web que la caja. Lo que la pantalla no sabe
 * editar (un precio con fórmula, un envío gratis con cupón, el método de otro plugin) se enseña
 * tal cual y se deja como está.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * El envío por peso se da de alta como un método más de WooCommerce.
 */
add_action( 'woocommerce_shipping_init', 'dox_pos_load_weight_method' );
function dox_pos_load_weight_method() {
	require_once DOX_POS_PATH . 'includes/class-dox-pos-shipping-weight.php';
}

add_filter( 'woocommerce_shipping_methods', 'dox_pos_register_weight_method' );
function dox_pos_register_weight_method( $methods ) {
	// La clase se carga también aquí: con los envíos desactivados en la tienda, WooCommerce no dispara
	// woocommerce_shipping_init, y al armar los métodos de una zona daría con un nombre de clase que no existe.
	dox_pos_load_weight_method();
	if ( class_exists( 'Dox_POS_Shipping_Weight' ) ) {
		$methods['dox_pos_weight'] = 'Dox_POS_Shipping_Weight';
	}
	return $methods;
}

/**
 * El formulario de producto guarda una hora si alguna zona cobra por peso (weight_matters, para avisar
 * de un producto sin peso). Esa respuesta caduca en cuanto cambian los métodos de envío, se cambien
 * desde la caja o en WooCommerce > Ajustes > Envío.
 */
add_action( 'woocommerce_shipping_zone_method_added', 'dox_pos_forget_product_form' );
add_action( 'woocommerce_shipping_zone_method_deleted', 'dox_pos_forget_product_form' );
add_action( 'woocommerce_shipping_zone_method_status_toggled', 'dox_pos_forget_product_form' );
add_action( 'woocommerce_delete_shipping_zone', 'dox_pos_forget_product_form' );
function dox_pos_forget_product_form() {
	delete_transient( 'dox_pos_product_form' );
}

/**
 * La unidad de peso de la tienda, como se escribe ("lb", no "lbs").
 *
 * @return string
 */
function dox_pos_weight_unit() {
	$u = (string) get_option( 'woocommerce_weight_unit', 'kg' );
	return 'lbs' === $u ? 'lb' : $u;
}

/**
 * La unidad de las medidas de la tienda (cm, in...).
 *
 * @return string
 */
function dox_pos_dimension_unit() {
	return (string) get_option( 'woocommerce_dimension_unit', 'cm' );
}

/**
 * Lo que pesa un paquete: cada producto por sus unidades, en la unidad de la tienda. Lo que no
 * tiene peso cuenta cero; lo que no se envía (virtual) no cuenta.
 *
 * @param array $package El paquete de WooCommerce.
 * @return float
 */
function dox_pos_package_weight( $package ) {
	$w = 0.0;
	foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
		$p = $item['data'] ?? null;
		if ( $p instanceof WC_Product && $p->needs_shipping() ) {
			$w += (float) $p->get_weight() * max( 1, (int) ( $item['quantity'] ?? 1 ) );
		}
	}
	return $w;
}

/**
 * Los tramos escritos uno por línea ("1 | 6") pasan a [ [ 'up_to' => 1.0, 'cost' => 6.0 ], ... ],
 * de menor a mayor y sin pesos repetidos. La coma vale como decimal.
 *
 * @param string $text Lo guardado en el método.
 * @return array
 */
function dox_pos_parse_weight_tiers( $text ) {
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$parts = preg_split( '/\s*[|;:=\t]\s*/', trim( $line ) );
		if ( count( $parts ) < 2 || false !== strpos( $parts[0] . $parts[1], '-' ) ) {
			continue; // Sin precio, o con un número negativo: esa línea no vale.
		}
		$up   = str_replace( ',', '.', preg_replace( '/[^\d.,]/', '', $parts[0] ) );
		$cost = str_replace( ',', '.', preg_replace( '/[^\d.,]/', '', $parts[1] ) );
		if ( ! is_numeric( $up ) || ! is_numeric( $cost ) || (float) $up <= 0 ) {
			continue;
		}
		$out[ (string) (float) $up ] = array( 'up_to' => (float) $up, 'cost' => max( 0.0, (float) $cost ) );
	}
	uasort( $out, fn( $a, $b ) => $a['up_to'] <=> $b['up_to'] );
	return array_values( $out );
}

/**
 * Y de vuelta: los tramos como se guardan, uno por línea.
 *
 * @param array $tiers [ [ 'up_to', 'cost' ], ... ].
 * @return string
 */
function dox_pos_format_weight_tiers( $tiers ) {
	$lines = array();
	foreach ( (array) $tiers as $t ) {
		$lines[] = wc_format_decimal( (float) $t['up_to'], 3, true ) . ' | ' . wc_format_decimal( (float) $t['cost'], wc_get_price_decimals(), true );
	}
	return implode( "\n", $lines );
}

/**
 * El país de la tienda con su nombre, sin el código que WooCommerce le cuelga a algunos
 * ("United States (US)").
 *
 * @return string
 */
function dox_pos_country_name() {
	$code  = dox_pos_country();
	$names = function_exists( 'WC' ) ? WC()->countries->get_countries() : array();
	$name  = html_entity_decode( (string) ( $names[ $code ] ?? $code ), ENT_QUOTES, 'UTF-8' );
	return trim( (string) preg_replace( '/\s*\([A-Z]{2}\)\s*$/', '', $name ) );
}

/**
 * ¿Quién pone los costos de envío? Quien administra la tienda.
 *
 * @return bool
 */
function dox_pos_can_manage_shipping() {
	return current_user_can( 'manage_woocommerce' );
}

/**
 * ¿Alguna zona cobra por peso con el método de la caja? Entonces un producto sin peso importa,
 * y el formulario lo avisa.
 *
 * @return bool
 */
function dox_pos_weight_matters() {
	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return false;
	}
	$zones   = wp_list_pluck( WC_Shipping_Zones::get_zones(), 'zone_id' );
	$zones[] = 0;
	foreach ( $zones as $zid ) {
		$zone = new WC_Shipping_Zone( (int) $zid );
		foreach ( $zone->get_shipping_methods( true ) as $m ) {
			if ( 'dox_pos_weight' === $m->id ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Todo lo que la pantalla de costos de envío necesita: las zonas con sus costos, y los estados
 * del país de la tienda para armar una zona nueva.
 *
 * @return array
 */
function dox_pos_shipping_setup() {
	$country = dox_pos_country();
	$states  = WC()->countries->get_states( $country );
	$zones   = array();
	foreach ( WC_Shipping_Zones::get_zones() as $z ) {
		$zones[] = dox_pos_shipping_zone_data( new WC_Shipping_Zone( (int) $z['zone_id'] ) );
	}
	$zones[] = dox_pos_shipping_zone_data( new WC_Shipping_Zone( 0 ) ); // Lo que no cubre ninguna otra zona.
	return array(
		'country'      => $country,
		'country_name' => dox_pos_country_name(),
		'state_label'  => dox_pos_state_label(),
		'states'       => is_array( $states ) ? array_map( fn( $s ) => html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ), $states ) : array(),
		'weight_unit'  => dox_pos_weight_unit(),
		'zones'        => $zones,
		'wc_url'       => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
	);
}

/**
 * Una zona, como la pinta la pantalla.
 *
 * El scope dice qué cubre: country (todo el país de la tienda), states (algunos de sus estados), rest (lo
 * que no cubre ninguna otra) o custom (otros países, códigos postales: sus lugares se cambian en WooCommerce).
 *
 * @param WC_Shipping_Zone $zone La zona.
 * @return array
 */
function dox_pos_shipping_zone_data( $zone ) {
	$country = dox_pos_country();
	$locs    = $zone->get_zone_locations();
	$scope   = 0 === (int) $zone->get_id() ? 'rest' : 'custom';
	$states  = array();
	if ( 'rest' !== $scope && $locs ) {
		if ( 1 === count( $locs ) && 'country' === $locs[0]->type && $country === $locs[0]->code ) {
			$scope = 'country';
		} else {
			$all = true;
			foreach ( $locs as $l ) {
				$parts = explode( ':', (string) $l->code, 2 );
				if ( 'state' !== $l->type || 2 !== count( $parts ) || $parts[0] !== $country ) {
					$all = false;
					break;
				}
				$states[] = $parts[1];
			}
			if ( $all ) {
				$scope = 'states';
			} else {
				$states = array();
			}
		}
	}
	$rates = array();
	foreach ( $zone->get_shipping_methods( false ) as $m ) {
		$rates[] = dox_pos_shipping_rate_data( $m );
	}
	return array(
		'id'     => (int) $zone->get_id(),
		'name'   => 'rest' === $scope ? '' : html_entity_decode( (string) $zone->get_zone_name(), ENT_QUOTES, 'UTF-8' ),
		'where'  => 'rest' === $scope ? '' : trim( (string) preg_replace( '/\s*\([A-Z]{2}\)/', '', html_entity_decode( wp_strip_all_tags( (string) $zone->get_formatted_location( 6 ) ), ENT_QUOTES, 'UTF-8' ) ) ), // Sin el código que WooCommerce cuelga a algunos países ("United States (US)").
		'scope'  => $scope,
		'states' => $states,
		'rates'  => $rates,
	);
}

/**
 * ¿Es un número suelto, y no una fórmula de WooCommerce ("10 + [qty] * 2")?
 *
 * @param string $v Lo que hay en el ajuste.
 * @return bool
 */
function dox_pos_plain_amount( $v ) {
	return '' === trim( (string) $v ) || (bool) preg_match( '/^\s*\d+(?:[.,]\d+)?\s*$/', (string) $v );
}

/**
 * Un costo de envío (un método dentro de una zona), como lo pinta la pantalla.
 *
 * El type es flat, weight, free, pickup u other. Con editable la pantalla sabe cambiarle el precio;
 * si no, why dice por qué (formula, coupon, other) y solo se puede encender o apagar.
 *
 * @param WC_Shipping_Method $m El método.
 * @return array
 */
function dox_pos_shipping_rate_data( $m ) {
	$out = array(
		'id'       => (int) $m->instance_id,
		'method'   => (string) $m->id,
		'type'     => 'other',
		'title'    => html_entity_decode( (string) $m->title, ENT_QUOTES, 'UTF-8' ),
		'enabled'  => 'yes' === $m->enabled,
		'editable' => false,
		'why'      => 'other',
		'label'    => html_entity_decode( wp_strip_all_tags( (string) $m->get_method_title() ), ENT_QUOTES, 'UTF-8' ),
	);
	$amount = fn( $v ) => (float) str_replace( ',', '.', trim( (string) $v ) );
	switch ( $m->id ) {
		case 'flat_rate':
		case 'local_pickup':
			$cost            = (string) $m->get_option( 'cost' );
			$out['type']     = 'flat_rate' === $m->id ? 'flat' : 'pickup';
			$out['editable'] = dox_pos_plain_amount( $cost );
			$out['why']      = $out['editable'] ? '' : 'formula';
			$out['cost']     = $out['editable'] ? $amount( $cost ) : 0;
			$out['formula']  = $out['editable'] ? '' : $cost;
			break;
		case 'free_shipping':
			$requires        = (string) $m->get_option( 'requires' );
			$out['type']     = 'free';
			$out['editable'] = in_array( $requires, array( '', 'min_amount' ), true );
			$out['why']      = $out['editable'] ? '' : 'coupon';
			$out['min']      = 'min_amount' === $requires || 'either' === $requires || 'both' === $requires ? $amount( $m->get_option( 'min_amount' ) ) : 0;
			break;
		case 'dox_pos_weight':
			$over            = trim( (string) $m->get_option( 'over_cost' ) );
			$extra           = trim( (string) $m->get_option( 'over_extra' ) );
			$out['type']     = 'weight';
			$out['editable'] = true;
			$out['why']      = '';
			$out['tiers']    = $m->get_tiers();
			$out['over']     = '' === $over ? '' : $amount( $over );
			$out['extra']    = '' === $extra ? '' : $amount( $extra );
			break;
	}
	return $out;
}

/**
 * La zona pedida (0 es "el resto"), o un error.
 *
 * @param int $id Zona.
 * @return WC_Shipping_Zone|WP_Error
 */
function dox_pos_shipping_zone( $id ) {
	$id = (int) $id;
	if ( 0 === $id ) {
		return new WC_Shipping_Zone( 0 );
	}
	$zone = WC_Shipping_Zones::get_zone( $id );
	if ( ! $zone ) {
		return new WP_Error( 'dox_pos_sin_zona', __( 'That shipping zone no longer exists.', 'dox-pos' ) );
	}
	return $zone;
}

/**
 * Crea una zona (id 0) o le cambia el nombre y los lugares.
 *
 * @param int   $id   Zona; 0 para crearla.
 * @param array $data name, scope (country o states) y states[].
 * @return array|WP_Error La pantalla entera, ya con el cambio.
 */
function dox_pos_shipping_save_zone( $id, $data ) {
	$id      = (int) $id;
	$country = dox_pos_country();
	$all     = WC()->countries->get_states( $country );
	$all     = is_array( $all ) ? $all : array(); // Un país sin regiones devuelve false.
	$scope   = 'states' === ( $data['scope'] ?? '' ) ? 'states' : 'country';
	$states  = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $data['states'] ?? array() ) ), fn( $s ) => isset( $all[ $s ] ) ) ) );
	if ( $id ) {
		$zone = dox_pos_shipping_zone( $id );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}
		$now = dox_pos_shipping_zone_data( $zone );
		if ( 'custom' === $now['scope'] ) {
			$scope = 'custom'; // Otros países o códigos postales: aquí solo se le cambia el nombre.
		} elseif ( ! isset( $data['scope'] ) ) {
			// Llegó solo el nombre: los lugares se quedan como estaban (sin esto, una zona de regiones pasaba a ser de todo el país).
			$scope  = $now['scope'];
			$states = $now['states'];
		}
	} else {
		$zone = new WC_Shipping_Zone();
	}
	if ( 'states' === $scope && ! $states ) {
		/* translators: %s: label of the regions of the country (State, Province) */
		return new WP_Error( 'dox_pos_zona_vacia', sprintf( __( 'Choose at least one: %s.', 'dox-pos' ), dox_pos_state_label() ) );
	}
	// Un lugar no puede estar en dos zonas: WooCommerce cobraría la primera y la otra no se usaría nunca.
	if ( 'custom' !== $scope ) {
		foreach ( WC_Shipping_Zones::get_zones() as $z ) {
			if ( (int) $z['zone_id'] === $id ) {
				continue;
			}
			$other = dox_pos_shipping_zone_data( new WC_Shipping_Zone( (int) $z['zone_id'] ) );
			if ( 'country' === $scope && 'country' === $other['scope'] ) {
				/* translators: 1: country, 2: zone name */
				return new WP_Error( 'dox_pos_zona_repetida', sprintf( __( 'All of %1$s already has its zone: %2$s.', 'dox-pos' ), dox_pos_country_name(), $other['name'] ) );
			}
			$twice = 'states' === $scope && 'states' === $other['scope'] ? array_intersect( $states, $other['states'] ) : array();
			if ( $twice ) {
				/* translators: 1: state or province, 2: zone name */
				return new WP_Error( 'dox_pos_zona_repetida', sprintf( __( '%1$s is already in another zone: %2$s.', 'dox-pos' ), html_entity_decode( (string) $all[ reset( $twice ) ], ENT_QUOTES, 'UTF-8' ), $other['name'] ) );
			}
		}
	}
	$name = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 200 ); // Lo que cabe en la columna de WooCommerce.
	if ( $id && ! array_key_exists( 'name', $data ) ) {
		$name = (string) $zone->get_zone_name(); // No llegó nombre: se queda el que tenía (la caja siempre lo manda; otro cliente de la API puede que no).
	}
	// El nombre que la caja puso sola a partir de los lugares se rehace cuando los lugares cambian ("Antioquia, Caldas"
	// no puede seguir llamándose así con otras regiones); el que escribió alguien se respeta.
	if ( $id && 'custom' !== $scope && dox_pos_shipping_zone_auto_name( $now['scope'], $now['states'], $all ) === $name ) {
		$name = '';
	}
	if ( '' === $name ) {
		$name = 'custom' === $scope ? $zone->get_zone_name() : dox_pos_shipping_zone_auto_name( $scope, $states, $all );
	}
	$zone->set_zone_name( $name );
	if ( 'custom' !== $scope ) {
		$zone->clear_locations( array( 'state', 'country', 'continent', 'postcode' ) );
		if ( 'country' === $scope ) {
			$zone->add_location( $country, 'country' );
		} else {
			foreach ( $states as $s ) {
				$zone->add_location( $country . ':' . $s, 'state' );
			}
		}
	}
	$zone->save();
	if ( 'custom' !== $scope ) {
		dox_pos_shipping_place_zone( (int) $zone->get_id(), $scope );
	}
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	return dox_pos_shipping_setup();
}

/**
 * El nombre que la caja le pone a una zona cuando nadie escribe uno: el país, o sus primeras regiones.
 *
 * @param string $scope  country o states.
 * @param array  $states Códigos de las regiones.
 * @param array  $all    Las regiones del país: código => nombre.
 * @return string
 */
function dox_pos_shipping_zone_auto_name( $scope, $states, $all ) {
	if ( 'states' !== $scope ) {
		return dox_pos_country_name();
	}
	$picked = array_map( fn( $s ) => html_entity_decode( (string) ( $all[ $s ] ?? $s ), ENT_QUOTES, 'UTF-8' ), array_slice( (array) $states, 0, 3 ) );
	return implode( ', ', $picked ) . ( count( (array) $states ) > 3 ? '…' : '' );
}

/**
 * Qué es otra zona respecto a la que se acaba de guardar: wider (cubre sus destinos y más, así que tiene
 * que ir después), narrower (cubre solo una parte, así que tiene que ir antes) o nada (no se pisan).
 *
 * @param array  $locs    Los lugares de la otra zona (objetos con type y code).
 * @param string $scope   Lo que cubre la guardada: country o states.
 * @param string $country El país de la tienda.
 * @return string wider, narrower o vacío.
 */
function dox_pos_shipping_zone_relation( $locs, $scope, $country ) {
	if ( ! $locs ) {
		return 'wider'; // Una zona sin lugares encaja con cualquier destino ("Everywhere").
	}
	$continent   = function_exists( 'WC' ) ? (string) WC()->countries->get_continent_code_for_country( $country ) : '';
	$in_cont     = false; // Un continente que incluye el país.
	$has_country = false; // El país entero.
	$our_states  = 0;     // Regiones de este país.
	$foreign     = 0;     // Lugares de fuera: otros países, sus regiones, otros continentes.
	$postcodes   = false;
	foreach ( $locs as $l ) {
		$code = (string) $l->code;
		if ( 'postcode' === $l->type ) {
			$postcodes = true;
		} elseif ( 'continent' === $l->type && $code === $continent ) {
			$in_cont = true;
		} elseif ( 'country' === $l->type && $code === $country ) {
			$has_country = true;
		} elseif ( 'state' === $l->type && 0 === strpos( $code, $country . ':' ) ) {
			++$our_states;
		} else {
			++$foreign;
		}
	}
	$covers = $has_country || $in_cont; // Encaja con cualquier destino del país.
	if ( $postcodes ) {
		// Los códigos postales recortan la zona a una parte de sus lugares: si esos lugares son de aquí, va antes. Una
		// zona que solo tiene códigos postales encaja en cualquier país (WooCommerce no le mira el país), así que también.
		$only_postcodes = ! $covers && ! $our_states && ! $foreign;
		return ( $covers || $our_states || $only_postcodes ) ? 'narrower' : '';
	}
	if ( 'states' === $scope ) {
		return $covers ? 'wider' : ''; // El país entero, solo o junto a otros lugares.
	}
	if ( $covers ) {
		return ( $in_cont || $foreign ) ? 'wider' : ''; // El país y algo más (otros países, un continente, regiones de otro país).
	}
	return $our_states ? 'narrower' : ''; // Regiones de este país.
}

/**
 * WooCommerce cobra la primera zona que encaja con el destino, por orden, y no pasa a la siguiente. La
 * zona guardada tiene que ir antes que cualquiera más amplia que ella (el país entero para una de
 * regiones; varios países, un continente o una zona sin lugares para cualquiera) y después de las más
 * estrechas (las regiones del país, para la del país entero). Si ya está en un sitio que vale no se
 * mueve, y las demás conservan el orden que tenían entre sí.
 *
 * @param int    $id    La zona recién guardada.
 * @param string $scope country o states.
 */
function dox_pos_shipping_place_zone( $id, $scope ) {
	$country = dox_pos_country();
	$others  = array(); // Las demás zonas, en su orden.
	$rel     = array(); // Qué es cada una respecto a la guardada.
	$cur     = 0;       // Cuántas tiene delante ahora.
	$found   = false;
	foreach ( WC_Shipping_Zones::get_zones() as $z ) {
		$zid = (int) $z['zone_id'];
		if ( $zid === $id ) {
			$found = true;
			continue;
		}
		if ( ! $found ) {
			++$cur;
		}
		$others[] = $zid;
		$rel[]    = dox_pos_shipping_zone_relation( (array) $z['zone_locations'], $scope, $country );
	}
	if ( ! $found ) {
		return;
	}
	$hi = array_search( 'wider', $rel, true ); // Como mucho, justo antes de la primera más amplia.
	$hi = false === $hi ? count( $others ) : (int) $hi;
	$lo = 0;                                   // Como poco, justo después de la última más estrecha.
	foreach ( $rel as $i => $r ) {
		if ( 'narrower' === $r ) {
			$lo = $i + 1;
		}
	}
	$lo   = min( $lo, $hi ); // Si el orden ya venía torcido, manda ir antes que la más amplia.
	$slot = min( max( $cur, $lo ), $hi );
	if ( $slot === $cur ) {
		return; // Ya está donde vale.
	}
	array_splice( $others, $slot, 0, array( $id ) );
	foreach ( $others as $order => $zid ) {
		$zone = new WC_Shipping_Zone( $zid );
		if ( (int) $zone->get_zone_order() !== $order + 1 ) {
			$zone->set_zone_order( $order + 1 );
			$zone->save();
		}
	}
}

/**
 * Borra una zona con sus costos. La de "el resto" no se borra: solo se vacía.
 *
 * @param int $id Zona.
 * @return array|WP_Error
 */
function dox_pos_shipping_delete_zone( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return new WP_Error( 'dox_pos_zona_fija', __( 'That zone cannot be deleted.', 'dox-pos' ) );
	}
	$zone = dox_pos_shipping_zone( $id );
	if ( is_wp_error( $zone ) ) {
		return $zone;
	}
	WC_Shipping_Zones::delete_zone( $id );
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	return dox_pos_shipping_setup();
}

/**
 * Crea un costo de envío en una zona (instancia 0) o cambia uno que ya existe.
 *
 * @param int   $zone_id     Zona.
 * @param int   $instance_id El método dentro de la zona; 0 para crearlo.
 * @param array $data        type, title, enabled, cost, min, tiers[[up_to, cost]], over, extra.
 * @return array|WP_Error
 */
function dox_pos_shipping_save_rate( $zone_id, $instance_id, $data ) {
	global $wpdb;
	$zone = dox_pos_shipping_zone( $zone_id );
	if ( is_wp_error( $zone ) ) {
		return $zone;
	}
	$instance_id = (int) $instance_id;
	$types       = array( 'flat' => 'flat_rate', 'weight' => 'dox_pos_weight', 'free' => 'free_shipping', 'pickup' => 'local_pickup' );
	$created     = false;
	if ( ! $instance_id ) {
		$type = (string) ( $data['type'] ?? '' );
		if ( ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'dox_pos_sin_tipo', __( 'Choose how this shipping is charged.', 'dox-pos' ) );
		}
		$instance_id = (int) $zone->add_shipping_method( $types[ $type ] );
		$created     = true;
	}
	$method = null;
	foreach ( $zone->get_shipping_methods( false ) as $m ) {
		if ( (int) $m->instance_id === $instance_id ) {
			$method = $m;
			break;
		}
	}
	if ( ! $method ) {
		if ( $created && $instance_id ) {
			$zone->delete_shipping_method( $instance_id ); // Se creó la fila pero el método no carga: no se deja huérfana.
		}
		return new WP_Error( 'dox_pos_sin_costo', __( 'That shipping cost no longer exists.', 'dox-pos' ) );
	}
	$now   = dox_pos_shipping_rate_data( $method );
	$money = fn( $v ) => wc_format_decimal( max( 0.0, (float) dox_pos_parse_money( $v ) ), wc_get_price_decimals(), true );
	if ( $now['editable'] ) {
		$method->init_instance_settings();
		$set   = $method->instance_settings;
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( '' !== $title ) {
			$set['title'] = $title;
		}
		switch ( $now['type'] ) {
			case 'flat':
			case 'pickup':
				if ( isset( $data['cost'] ) ) {
					$set['cost'] = $money( $data['cost'] );
				}
				break;
			case 'free':
				if ( isset( $data['min'] ) ) {
					$min               = (float) dox_pos_parse_money( $data['min'] );
					$set['requires']   = $min > 0 ? 'min_amount' : '';
					$set['min_amount'] = $min > 0 ? $money( $min ) : '0';
				}
				break;
			case 'weight':
				if ( isset( $data['tiers'] ) || $created ) {
					$tiers = array();
					foreach ( (array) ( $data['tiers'] ?? array() ) as $t ) {
						$t  = array_values( (array) $t );
						$up = round( (float) str_replace( ',', '.', (string) ( $t[0] ?? '' ) ), 3 ); // Con los tres decimales con que se guarda.
						if ( $up > 0 ) {
							$tiers[ (string) $up ] = array( 'up_to' => $up, 'cost' => max( 0.0, (float) dox_pos_parse_money( $t[1] ?? 0 ) ) );
						}
					}
					uasort( $tiers, fn( $a, $b ) => $a['up_to'] <=> $b['up_to'] );
					$over = $data['over'] ?? '';
					if ( ! $tiers && ( '' === $over || null === $over ) ) {
						if ( $created ) {
							$zone->delete_shipping_method( $instance_id );
						}
						return new WP_Error( 'dox_pos_sin_tramos', __( 'Add at least one weight range with its price.', 'dox-pos' ) );
					}
					$set['tiers']      = dox_pos_format_weight_tiers( array_values( $tiers ) );
					$set['over_cost']  = '' === $over || null === $over ? '' : $money( $over );
					$extra             = $data['extra'] ?? '';
					$set['over_extra'] = '' === $extra || null === $extra || (float) dox_pos_parse_money( $extra ) <= 0 ? '' : $money( $extra );
				}
				break;
		}
		update_option( $method->get_instance_option_key(), apply_filters( 'woocommerce_shipping_' . $method->id . '_instance_settings_values', $set, $method ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- El gancho es de WooCommerce.
	}
	if ( isset( $data['enabled'] ) ) {
		$on = ! empty( $data['enabled'] );
		if ( $on !== $now['enabled'] && $wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => $on ? 1 : 0 ), array( 'instance_id' => $instance_id ) ) ) {
			do_action( 'woocommerce_shipping_zone_method_status_toggled', $instance_id, $method->id, (int) $zone->get_id(), $on ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- El gancho es de WooCommerce.
		}
	}
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	delete_transient( 'dox_pos_product_form' ); // El formulario avisa de los productos sin peso solo si alguna zona cobra por peso.
	return dox_pos_shipping_setup();
}

/**
 * Quita un costo de envío de su zona.
 *
 * @param int $zone_id     Zona.
 * @param int $instance_id El método dentro de la zona.
 * @return array|WP_Error
 */
function dox_pos_shipping_delete_rate( $zone_id, $instance_id ) {
	$zone = dox_pos_shipping_zone( $zone_id );
	if ( is_wp_error( $zone ) ) {
		return $zone;
	}
	// WooCommerce borra la instancia que se le diga sin mirar de qué zona es, y responde true aunque no exista:
	// aquí se comprueba antes que de verdad es un costo de esta zona.
	$instance_id = (int) $instance_id;
	$ours        = false;
	foreach ( $zone->get_shipping_methods( false ) as $m ) {
		if ( (int) $m->instance_id === $instance_id ) {
			$ours = true;
			break;
		}
	}
	if ( ! $ours || ! $zone->delete_shipping_method( $instance_id ) ) {
		return new WP_Error( 'dox_pos_sin_costo', __( 'That shipping cost no longer exists.', 'dox-pos' ) );
	}
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	delete_transient( 'dox_pos_product_form' );
	return dox_pos_shipping_setup();
}
