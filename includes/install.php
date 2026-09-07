<?php
/**
 * Instalación y actualización: rol, tablas (entradas, kardex y las del asistente) y ruta.
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
	dox_pos_install_roles();
	dox_pos_install_tables();
	dox_pos_add_rewrite();
	dox_pos_schedule_cleanup();
	if ( function_exists( 'dox_pos_ai_schedule' ) ) {
		dox_pos_ai_schedule(); // El resumen diario del asistente, a su hora.
	}
	flush_rewrite_rules();
	update_option( 'dox_pos_version', DOX_POS_VERSION );
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
 * el kardex (cada cambio de existencias, con su motivo y quién lo hizo), y las del asistente:
 * el registro de llamadas a OpenAI con su costo y las acciones que hizo, con lo de antes y lo
 * de después para deshacerlas.
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
	$ai = $wpdb->prefix . 'dox_pos_ai_log';
	dbDelta(
		"CREATE TABLE {$ai} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(20) NOT NULL DEFAULT '',
			model varchar(60) NOT NULL DEFAULT '',
			tokens_in int(11) NOT NULL DEFAULT 0,
			tokens_cached int(11) NOT NULL DEFAULT 0,
			tokens_out int(11) NOT NULL DEFAULT 0,
			cost decimal(12,6) NOT NULL DEFAULT 0,
			ms int(11) NOT NULL DEFAULT 0,
			ok tinyint(1) NOT NULL DEFAULT 1,
			note varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};"
	);
	$acts = $wpdb->prefix . 'dox_pos_ai_actions';
	dbDelta(
		"CREATE TABLE {$acts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_name varchar(190) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT '',
			kind varchar(20) NOT NULL DEFAULT '',
			label varchar(255) NOT NULL DEFAULT '',
			data longtext,
			can_undo tinyint(1) NOT NULL DEFAULT 1,
			undone_at datetime DEFAULT NULL,
			undo_user varchar(190) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};"
	);
}
