<?php
/**
 * La pantalla de entrada a la caja.
 *
 * Variables que vienen de dox_pos_render():
 * - string $error       Mensaje de error, si lo hay.
 * - bool   $sin_permiso Hay sesión, pero ese usuario no tiene acceso a la caja.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$logo  = dox_pos_logo_url();
$brand = dox_pos_brand_name();
dox_pos_enqueue_caja();
?>
<!doctype html>
<html lang="es">
<head>
<?php dox_pos_head(); ?>
</head>
<body class="login-body">
<header class="bar">
	<span class="brand">
		<?php if ( $logo ) : ?>
			<img class="logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>">
		<?php else : ?>
			<span class="brand-text"><?php echo esc_html( $brand ); ?></span>
		<?php endif; ?>
		<span class="cajita"><?php echo esc_html( dox_pos_screen_name() ); ?></span>
	</span>
</header>
<main class="login">
	<form class="login-card" method="post" action="<?php echo esc_url( dox_pos_url() ); ?>">
		<h1><?php echo esc_html( dox_pos_screen_name() ); ?></h1>
		<p class="login-sub"><?php echo esc_html( sprintf( /* translators: %s: nombre de la marca */ __( 'Entra con tu usuario de %s.', 'dox-pos' ), $brand ) ); ?></p>
		<?php if ( ! empty( $sin_permiso ) ) : ?>
			<p class="login-err" role="alert"><?php esc_html_e( 'Tu usuario no tiene acceso a la caja. Entra con otro.', 'dox-pos' ); ?></p>
		<?php elseif ( ! empty( $error ) ) : ?>
			<p class="login-err" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<?php wp_nonce_field( 'dox_pos_login' ); ?>
		<input type="hidden" name="dox_pos_login" value="1">
		<input type="hidden" name="rememberme" value="forever">
		<div class="field">
			<label for="log"><?php esc_html_e( 'Usuario o correo', 'dox-pos' ); ?></label>
			<input id="log" name="log" type="text" autocomplete="username" required autofocus>
		</div>
		<div class="field">
			<label for="pwd"><?php esc_html_e( 'Clave', 'dox-pos' ); ?></label>
			<input id="pwd" name="pwd" type="password" autocomplete="current-password" required>
		</div>
		<button class="go" type="submit"><?php esc_html_e( 'Entrar', 'dox-pos' ); ?></button>
		<?php if ( ! empty( $sin_permiso ) ) : ?>
			<a class="login-alt" href="<?php echo esc_url( dox_pos_logout_url() ); ?>"><?php esc_html_e( 'Salir de este usuario', 'dox-pos' ); ?></a>
		<?php endif; ?>
	</form>
</main>
</body>
</html>
