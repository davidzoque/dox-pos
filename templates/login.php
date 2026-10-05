<?php
/**
 * La pantalla de entrada a la caja.
 *
 * Variables que vienen de dox_pos_render():
 * - string $error       Mensaje de error, si lo hay.
 * - bool   $sin_permiso Hay sesión, pero ese usuario no tiene acceso a la caja.
 * - string $code_step   Entrar con código: '' (contraseña), 'ask' o 'code'.
 * - string $code_login  El usuario o correo que escribió para el código.
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
<html <?php language_attributes(); ?>>
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
		<p class="login-sub"><?php echo esc_html( sprintf( /* translators: %s: brand name */ __( 'Sign in with your %s account.', 'dox-pos' ), $brand ) ); ?></p>
		<?php if ( ! empty( $sin_permiso ) ) : ?>
			<p class="login-err" role="alert"><?php esc_html_e( 'Your user does not have access to the register. Sign in with another one.', 'dox-pos' ); ?></p>
		<?php elseif ( ! empty( $error ) ) : ?>
			<p class="login-err" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $code_step ) ) : ?>
			<?php wp_nonce_field( 'dox_pos_code' ); ?>
			<input type="hidden" name="dox_pos_code" value="1">
			<?php if ( 'code' === $code_step ) : ?>
				<p class="login-ok" role="status"><?php esc_html_e( 'If that account exists, we sent a 6-digit code to its email. It may take a minute; check spam too.', 'dox-pos' ); ?></p>
				<input type="hidden" name="log" value="<?php echo esc_attr( $code_login ); ?>">
				<div class="field">
					<label for="code"><?php esc_html_e( 'Code', 'dox-pos' ); ?></label>
					<input id="code" name="code" class="login-code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus>
				</div>
				<button class="go" type="submit" name="verify" value="1"><?php esc_html_e( 'Sign in', 'dox-pos' ); ?></button>
				<a class="login-alt" href="<?php echo esc_url( add_query_arg( 'codigo', 1, dox_pos_url() ) ); ?>"><?php esc_html_e( 'Request another code', 'dox-pos' ); ?></a>
			<?php else : ?>
				<div class="field">
					<label for="log"><?php esc_html_e( 'Username or email', 'dox-pos' ); ?></label>
					<input id="log" name="log" type="text" autocomplete="username" value="<?php echo esc_attr( $code_login ); ?>" required autofocus>
				</div>
				<button class="go" type="submit" name="send" value="1"><?php esc_html_e( 'Send me a code', 'dox-pos' ); ?></button>
			<?php endif; ?>
			<a class="login-alt" href="<?php echo esc_url( dox_pos_url() ); ?>"><?php esc_html_e( 'Sign in with your password', 'dox-pos' ); ?></a>
		<?php else : ?>
			<?php wp_nonce_field( 'dox_pos_login' ); ?>
			<input type="hidden" name="dox_pos_login" value="1">
			<input type="hidden" name="rememberme" value="forever">
			<div class="field">
				<label for="log"><?php esc_html_e( 'Username or email', 'dox-pos' ); ?></label>
				<input id="log" name="log" type="text" autocomplete="username" required autofocus>
			</div>
			<div class="field">
				<label for="pwd"><?php esc_html_e( 'Password', 'dox-pos' ); ?></label>
				<input id="pwd" name="pwd" type="password" autocomplete="current-password" required>
			</div>
			<button class="go" type="submit"><?php esc_html_e( 'Sign in', 'dox-pos' ); ?></button>
			<?php if ( empty( $sin_permiso ) && dox_pos_login_code_enabled() ) : ?>
				<a class="login-alt" href="<?php echo esc_url( add_query_arg( 'codigo', 1, dox_pos_url() ) ); ?>"><?php esc_html_e( 'Sign in with a code by email', 'dox-pos' ); ?></a>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( ! empty( $sin_permiso ) ) : ?>
			<a class="login-alt" href="<?php echo esc_url( dox_pos_logout_url() ); ?>"><?php esc_html_e( 'Sign out of this account', 'dox-pos' ); ?></a>
		<?php endif; ?>
	</form>
</main>
</body>
</html>
