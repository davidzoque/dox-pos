<?php
/**
 * Al desinstalar no se borra nada de la tienda, salvo que se haya pedido en Ajustes > Pantalla
 * ("Also delete my settings when the plugin is deleted", apagado de fábrica). WordPress no pregunta
 * nada mientras borra un plugin: ejecuta este archivo y ya, por eso la respuesta se guarda antes.
 *
 * Sin ese permiso solo se sueltan las tareas programadas y se reescriben los enlaces permanentes,
 * que son cosas del plugin que ya no existe, no datos de la tienda. Con él se van además el rol
 * "caja", el permiso, todos los ajustes (la clave de OpenAI incluida) y los pedidos de prueba.
 *
 * Los pedidos, el stock y las tablas de entradas, del kardex y del asistente (el histórico de lo
 * que hizo) no se tocan en ningún caso.
 *
 * @package DoxPos
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Las tareas programadas del plugin (limpiar fotos sueltas, liberar apartados) se van con él.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'dox_pos_clean_images' );
	as_unschedule_all_actions( 'dox_pos_release_hold' );
}
flush_rewrite_rules();

if ( ! get_option( 'dox_pos_delete_data' ) ) {
	return; // Nadie lo pidió: los ajustes, el rol y todo lo demás se quedan donde estaban.
}

foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role_obj = get_role( $role_name );
	if ( $role_obj ) {
		$role_obj->remove_cap( 'dox_pos_use' );
	}
}
remove_role( 'caja' );

global $wpdb;
// Los pedidos de demostración, si quedaron, se van con el plugin (sus filas de kardex también).
if ( function_exists( 'wc_get_orders' ) && get_option( 'dox_pos_demo' ) ) {
	$demo_ids = wc_get_orders( array( 'limit' => -1, 'type' => 'shop_order', 'return' => 'ids', 'status' => array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) ), 'meta_query' => array( array( 'key' => '_dox_pos_demo', 'value' => '1' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	foreach ( (array) $demo_ids as $demo_id ) {
		$demo_order = wc_get_order( $demo_id );
		if ( $demo_order ) {
			$demo_order->delete( true );
		}
	}
	$wpdb->delete( $wpdb->prefix . 'dox_pos_stock_log', array( 'note' => 'Demo' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
// Solo los ajustes de este plugin: los del Pro (dox_pos_pro_*, dox_pos_ai_*, dox_pos_recover_*) son suyos y se quedan.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE ( option_name LIKE 'dox\\_pos\\_%' OR option_name LIKE '\\_transient\\_%dox\\_pos\\_font\\_%' ) AND option_name NOT LIKE 'dox\\_pos\\_pro%' AND option_name NOT LIKE 'dox\\_pos\\_ai%' AND option_name NOT LIKE 'dox\\_pos\\_recover%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
