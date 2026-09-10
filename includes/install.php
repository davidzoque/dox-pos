<?php
/**
 * Instalación y actualización: rol, tablas (entradas y kardex) y ruta.
 *
 * Se ejecuta al activar y también cuando cambia la versión (el plugin se sube
 * por archivo, sin pasar por "Activar", y aun así tiene que crear lo que falte).
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'dox_pos_maybe_install', 20 ); // Después de registrar la ruta y el estado.
function dox_pos_maybe_install() {
	if ( get_option( 'dox_pos_version' ) !== DOX_POS_VERSION ) {
		dox_pos_install();
		return;
	}
	// Cambió la ruta en los ajustes: las reglas se reescriben en la carga siguiente,
	// cuando init ya registró la nueva.
	if ( get_option( 'dox_pos_flush' ) ) {
		delete_option( 'dox_pos_flush' );
		flush_rewrite_rules();
	}
}

/**
 * Deja todo lo que el plugin necesita. Repetirlo no hace daño.
 */
function dox_pos_install() {
	if ( ! is_textdomain_loaded( 'dox-pos' ) ) {
		dox_pos_textdomain(); // Al activar desde la lista de plugins, init ya pasó y la traducción no se cargó; la ruta de fábrica sale del idioma.
	}
	dox_pos_install_roles();
	dox_pos_install_tables();
	dox_pos_freeze_slug();
	dox_pos_add_rewrite();
	dox_pos_schedule_cleanup();
	dox_pos_migrate_tokens();
	flush_rewrite_rules();
	update_option( 'dox_pos_version', DOX_POS_VERSION );
	do_action( 'dox_pos_installed' ); // Los añadidos crean lo suyo (el Pro, sus tablas y su tarea).
}

/**
 * Deja la ruta escrita en el ajuste la primera vez, para que no se mueva nunca: ni al cambiar el
 * idioma del sitio ni al actualizar. Hasta la 0.36.0 la ruta de fábrica era /caja/ en cualquier
 * idioma; una tienda que ya existía y nunca eligió ruta se queda ahí, que es donde sus vendedoras la
 * tienen guardada. Una instalación nueva toma la de su idioma (dox_pos_default_slug: /pos/, /caja/).
 */
function dox_pos_freeze_slug() {
	$screen = dox_pos_screen();
	if ( ! empty( $screen['slug'] ) ) {
		return;
	}
	$was            = (string) get_option( 'dox_pos_version', '' ); // Vacío en una instalación nueva.
	$screen['slug'] = '' !== $was && version_compare( $was, '0.37.0', '<' ) ? 'caja' : dox_pos_default_slug();
	update_option( 'dox_pos_screen', $screen );
}

/**
 * Los comodines de los mensajes pasaron de español a inglés en la 0.24.0 ({nombre} es {name}).
 * Lo que la tienda ya tenía escrito se traduce una sola vez: los dos mensajes de WhatsApp y el
 * enlace de rastreo de cada transportadora. Una instalación nueva no tiene nada que cambiar.
 */
function dox_pos_migrate_tokens() {
	if ( get_option( 'dox_pos_tokens_en' ) ) {
		return;
	}
	update_option( 'dox_pos_tokens_en', 1, false );
	$map = array(
		'{nombre}'         => '{name}',
		'{productos}'      => '{items}',
		'{horas}'          => '{hours}',
		'{pedido}'         => '{order}',
		'{tienda}'         => '{store}',
		'{transportadora}' => '{carrier}',
		'{guia}'           => '{tracking}',
	);
	foreach ( array( 'dox_pos_hold_message', 'dox_pos_ship_message' ) as $key ) {
		$text = (string) get_option( $key, '' );
		if ( '' !== $text ) {
			update_option( $key, strtr( $text, $map ) );
		}
	}
	$sales = get_option( 'dox_pos_sales' );
	if ( ! is_array( $sales ) || empty( $sales['carriers'] ) || ! is_array( $sales['carriers'] ) ) {
		return;
	}
	foreach ( $sales['carriers'] as $i => $carrier ) {
		if ( isset( $carrier['url'] ) ) {
			$sales['carriers'][ $i ]['url'] = strtr( (string) $carrier['url'], $map );
		}
	}
	update_option( 'dox_pos_sales', $sales );
}

/**
 * Una vez al día se borran las fotos subidas desde la caja que no acabaron en ningún producto.
 */
function dox_pos_schedule_cleanup() {
	if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}
	if ( ! as_has_scheduled_action( 'dox_pos_clean_images' ) ) {
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'dox_pos_clean_images', array(), 'dox-pos' );
	}
}

/**
 * Las tablas: las entradas de mercancía (WooCommerce no guarda lo que entra, solo lo que sale),
 * con lo que costó, y el kardex (cada cambio de existencias, con su motivo, quién lo hizo y el
 * costo por unidad en ese momento).
 */
function dox_pos_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = $wpdb->prefix . 'dox_pos_entries';
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			supplier varchar(190) NOT NULL DEFAULT '',
			invoice varchar(100) NOT NULL DEFAULT '',
			entry_date date DEFAULT NULL,
			note text,
			items longtext NOT NULL,
			units int(11) NOT NULL DEFAULT 0,
			cost decimal(15,2) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'ok',
			ref varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY ref (ref)
		) {$charset};"
	);
	$log = $wpdb->prefix . 'dox_pos_stock_log';
	dbDelta(
		"CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sku varchar(100) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			qty_before int(11) DEFAULT NULL,
			qty_after int(11) DEFAULT NULL,
			delta int(11) NOT NULL DEFAULT 0,
			unit_cost decimal(15,2) DEFAULT NULL,
			reason varchar(20) NOT NULL DEFAULT 'other',
			ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_name varchar(190) NOT NULL DEFAULT '',
			note varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY product_id (product_id),
			KEY variation_id (variation_id),
			KEY reason (reason)
		) {$charset};"
	);
}
