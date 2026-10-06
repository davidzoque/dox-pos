<?php
/**
 * "Conectar este teléfono": la pantalla que ve quien abrió la caja desde la app, ya con la sesión.
 *
 * Variables que vienen de dox_pos_app_connect_screen():
 * - WP_User $user  Quién va a quedar conectado.
 * - string  $error Por qué no se puede conectar (sin HTTPS, contraseñas de aplicación apagadas...).
 * - string  $back  El enlace de vuelta a la app, cuando ya decidió (conectar o cancelar).
 * - string  $logo, $brand
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
dox_pos_enqueue_caja();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<?php dox_pos_head(); ?>
<?php if ( $back ) : ?>
<meta http-equiv="refresh" content="0;url=<?php echo esc_url( $back, array( DOX_POS_APP_SCHEME ) ); ?>">
<?php endif; ?>
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
	<?php if ( $back ) : ?>
		<div class="login-card">
			<h1><?php esc_html_e( 'Back to the app', 'dox-pos' ); ?></h1>
			<p class="login-sub"><?php esc_html_e( 'If the app does not open by itself, tap the button.', 'dox-pos' ); ?></p>
			<a class="go" href="<?php echo esc_url( $back, array( DOX_POS_APP_SCHEME ) ); ?>"><?php esc_html_e( 'Open the app', 'dox-pos' ); ?></a>
		</div>
	<?php else : ?>
		<form class="login-card" method="post" action="<?php echo esc_url( dox_pos_url() ); ?>">
			<h1><?php esc_html_e( 'Connect this phone', 'dox-pos' ); ?></h1>
			<?php if ( $error ) : ?>
				<p class="login-err" role="alert"><?php echo esc_html( $error ); ?></p>
			<?php else : ?>
				<p class="login-sub">
					<?php
					/* translators: 1: the user's name, 2: the shop's name */
					echo esc_html( sprintf( __( 'The Dox POS app will use the register as %1$s, at %2$s. You can disconnect this phone at any time from the phone button at the top of the register.', 'dox-pos' ), $user->display_name, $brand ) );
					?>
				</p>
			<?php endif; ?>
			<?php wp_nonce_field( 'dox_pos_app_connect' ); ?>
			<?php if ( ! $error ) : ?>
				<button class="go" type="submit" name="dox_pos_app" value="connect"><?php esc_html_e( 'Connect', 'dox-pos' ); ?></button>
			<?php endif; ?>
			<button class="go alt" type="submit" name="dox_pos_app" value="cancel"><?php esc_html_e( 'Cancel', 'dox-pos' ); ?></button>
			<a class="login-alt" href="<?php echo esc_url( dox_pos_logout_url() ); ?>"><?php esc_html_e( 'Use another account', 'dox-pos' ); ?></a>
		</form>
	<?php endif; ?>
</main>
</body>
</html>
