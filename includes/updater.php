<?php
/**
 * Las actualizaciones fuera de WordPress.org.
 *
 * Este archivo solo existe en la copia que se reparte desde el repositorio: el workflow lo quita,
 * junto con la carpeta vendor y la cabecera Update URI, antes de armar el zip de WordPress.org,
 * porque ahí las actualizaciones las sirve el propio directorio y los actualizadores no se
 * permiten. Quien instale el plugin desde WordPress.org nunca tendrá este código.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dox_pos_puc = DOX_POS_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $dox_pos_puc ) ) {
	require_once $dox_pos_puc;
	$dox_pos_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( 'https://github.com/davidzoque/dox-pos/', DOX_POS_FILE, 'dox-pos' );
	$dox_pos_updater->setBranch( 'main' );
	$dox_pos_updater->getVcsApi()->enableReleaseAssets();
}
