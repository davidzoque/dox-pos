<?php
/**
 * Al desinstalar: se quita el rol "caja", el permiso, todos los ajustes (la clave de OpenAI
 * incluida) y la tarea del resumen diario. Los pedidos, el stock y las tablas de entradas, del
 * kardex y del asistente (el histórico de lo que hizo) no se tocan.
 *
 * @package DoxPos
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role_obj = get_role( $role_name );
	if ( $role_obj ) {
		$role_obj->remove_cap( 'dox_pos_use' );
	}
}
remove_role( 'caja' );
wp_clear_scheduled_hook( 'dox_pos_ai_daily' );

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
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'dox\\_pos\\_%' OR option_name LIKE '\\_transient\\_%dox\\_pos\\_font\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
flush_rewrite_rules();
