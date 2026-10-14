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
<html <?php language_attributes(); ?> data-theme="<?php echo esc_attr( dox_pos_appearance() ); ?>">
<head>
<?php dox_pos_head(); ?>
</head>
<body class="login-body">
<?php
// Los iconos del login, en una línea cada uno (los mismos trazos que la caja).
$dox_pos_ic = array(
	'vender' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>',
	'pedido' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>',
	'caja'   => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
	'llave'  => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
	'correo' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
	'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
	'candado' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
	'ojo'    => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
	'flecha' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
	'mal'    => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>',
	'bien'   => '<path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="M22 4 12 14l-3-3"/>',
);
$dox_pos_svg = function ( $k ) use ( $dox_pos_ic ) {
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $dox_pos_ic[ $k ] . '</svg>';
};
$dox_pos_kses = array( 'svg' => array( 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true ), 'path' => array( 'd' => true ), 'circle' => array( 'cx' => true, 'cy' => true, 'r' => true ), 'rect' => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true ) );
$dox_pos_i  = function ( $k ) use ( $dox_pos_svg, $dox_pos_kses ) {
	echo wp_kses( $dox_pos_svg( $k ), $dox_pos_kses );
};
$dox_pos_code_on = empty( $sin_permiso ) && dox_pos_login_code_enabled();

?>
<main class="lg">
	<div class="lg-card">
		<aside class="lg-marca">
			<span class="lg-logo">
				<?php if ( $logo ) : ?>
					<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>">
				<?php else : ?>
					<span><?php echo esc_html( $brand ); ?></span>
				<?php endif; ?>
				<span class="cajita"><?php echo esc_html( dox_pos_screen_name() ); ?></span>
			</span>
			<p class="lg-tit"><?php echo wp_kses( sprintf( /* translators: %s: "in one place", highlighted */ __( 'Everything in the store, %s', 'dox-pos' ), '<span>' . esc_html__( 'in one place', 'dox-pos' ) . '</span>' ), array( 'span' => array() ) ); ?></p>
			<ul class="lg-lista">
				<li><span class="ic"><?php $dox_pos_i( 'vender' ); ?></span><?php esc_html_e( 'Sell in the store, on social media or by message', 'dox-pos' ); ?></li>
				<li><span class="ic"><?php $dox_pos_i( 'pedido' ); ?></span><?php esc_html_e( 'Orders, layaways and shipping', 'dox-pos' ); ?></li>
				<li><span class="ic"><?php $dox_pos_i( 'caja' ); ?></span><?php esc_html_e( 'Stock up to date in the store and on the website', 'dox-pos' ); ?></li>
			</ul>
			<?php $dox_pos_credito = (string) apply_filters( 'dox_pos_login_credit', 'Dox POS' ); // Una tienda con su propia marca lo puede quitar. ?>
			<?php if ( '' !== $dox_pos_credito ) : ?>
				<p class="lg-pie"><?php echo esc_html( $dox_pos_credito ); ?></p>
			<?php endif; ?>
		</aside>

		<form class="lg-form" method="post" action="<?php echo esc_url( dox_pos_url() ); ?>">
			<h1><?php esc_html_e( 'Sign in', 'dox-pos' ); ?></h1>
			<p class="lg-sub"><?php echo esc_html( sprintf( /* translators: %s: brand name */ __( 'Sign in with your %s account.', 'dox-pos' ), $brand ) ); ?></p>
			<?php if ( ! empty( $sin_permiso ) ) : ?>
				<p class="lg-aviso mal" role="alert"><?php $dox_pos_i( 'mal' ); ?><span><?php esc_html_e( 'Your user does not have access to the register. Sign in with another one.', 'dox-pos' ); ?></span></p>
			<?php elseif ( ! empty( $error ) ) : ?>
				<p class="lg-aviso mal" role="alert"><?php $dox_pos_i( 'mal' ); ?><span><?php echo esc_html( $error ); ?></span></p>
			<?php endif; ?>

			<?php if ( $dox_pos_code_on ) : // Contraseña o código: un control de dos, no un enlace suelto. ?>
				<nav class="lg-modo" aria-label="<?php esc_attr_e( 'How to sign in', 'dox-pos' ); ?>">
					<a href="<?php echo esc_url( dox_pos_url() ); ?>"<?php echo empty( $code_step ) ? ' aria-current="page"' : ''; ?>><?php $dox_pos_i( 'llave' ); ?><?php esc_html_e( 'Password', 'dox-pos' ); ?></a>
					<a href="<?php echo esc_url( add_query_arg( 'codigo', 1, dox_pos_url() ) ); ?>"<?php echo ! empty( $code_step ) ? ' aria-current="page"' : ''; ?>><?php $dox_pos_i( 'correo' ); ?><?php esc_html_e( 'Code by email', 'dox-pos' ); ?></a>
				</nav>
			<?php endif; ?>

			<?php if ( ! empty( $code_step ) ) : ?>
				<?php wp_nonce_field( 'dox_pos_code' ); ?>
				<input type="hidden" name="dox_pos_code" value="1">
				<input type="hidden" name="step" value="<?php echo 'code' === $code_step ? 'verify' : 'send'; ?>">
				<?php if ( 'code' === $code_step ) : ?>
					<p class="lg-aviso bien" role="status"><?php $dox_pos_i( 'bien' ); ?><span><?php esc_html_e( 'If that account can use the register, we sent a 6-digit code to its email. It may take a minute; check spam too.', 'dox-pos' ); ?></span></p>
					<input type="hidden" name="log" value="<?php echo esc_attr( $code_login ); ?>">
					<div class="field">
						<label for="code"><?php esc_html_e( 'Code', 'dox-pos' ); ?></label>
						<div class="lg-codigo"><input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" placeholder="000000" required autofocus></div>
					</div>
					<button class="go" type="submit"><?php esc_html_e( 'Sign in', 'dox-pos' ); ?><?php $dox_pos_i( 'flecha' ); ?></button>
					<a class="lg-sec" href="<?php echo esc_url( add_query_arg( 'codigo', 1, dox_pos_url() ) ); ?>"><?php esc_html_e( 'Request another code', 'dox-pos' ); ?></a>
				<?php else : ?>
					<p class="lg-aviso info"><?php $dox_pos_i( 'correo' ); ?><span><?php esc_html_e( 'We send a 6-digit code to the email of your account. It also works if you forgot your password.', 'dox-pos' ); ?></span></p>
					<div class="field">
						<label for="log"><?php esc_html_e( 'Username or email', 'dox-pos' ); ?></label>
						<div class="lg-campo"><?php $dox_pos_i( 'user' ); ?><input id="log" name="log" type="text" autocomplete="username" value="<?php echo esc_attr( $code_login ); ?>" required autofocus></div>
					</div>
					<button class="go" type="submit"><?php esc_html_e( 'Send me a code', 'dox-pos' ); ?><?php $dox_pos_i( 'flecha' ); ?></button>
				<?php endif; ?>
			<?php else : ?>
				<?php wp_nonce_field( 'dox_pos_login' ); ?>
				<input type="hidden" name="dox_pos_login" value="1">
				<input type="hidden" name="rememberme" value="forever">
				<div class="field">
					<label for="log"><?php esc_html_e( 'Username or email', 'dox-pos' ); ?></label>
					<div class="lg-campo"><?php $dox_pos_i( 'user' ); ?><input id="log" name="log" type="text" autocomplete="username" required autofocus></div>
				</div>
				<div class="field">
					<label for="pwd"><?php esc_html_e( 'Password', 'dox-pos' ); ?></label>
					<div class="lg-campo con-ojo"><?php $dox_pos_i( 'candado' ); ?><input id="pwd" name="pwd" type="password" autocomplete="current-password" required>
						<button type="button" class="ojo" id="lg-ojo" aria-pressed="false" aria-label="<?php esc_attr_e( 'Show the password', 'dox-pos' ); ?>" data-ocultar="<?php esc_attr_e( 'Hide the password', 'dox-pos' ); ?>"><?php $dox_pos_i( 'ojo' ); ?></button></div>
				</div>
				<button class="go" type="submit"><?php esc_html_e( 'Sign in', 'dox-pos' ); ?><?php $dox_pos_i( 'flecha' ); ?></button>
			<?php endif; ?>

			<?php if ( ! empty( $sin_permiso ) ) : ?>
				<a class="lg-sec" href="<?php echo esc_url( dox_pos_logout_url() ); ?>"><?php esc_html_e( 'Sign out of this account', 'dox-pos' ); ?></a>
			<?php else : ?>
				<p class="lg-ayuda"><?php esc_html_e( 'No user yet?', 'dox-pos' ); ?> <b><?php esc_html_e( 'Ask whoever runs the store.', 'dox-pos' ); ?></b></p>
			<?php endif; ?>
		</form>
	</div>
</main>
<?php
// El ojo de la contraseña: verla mientras se escribe.
wp_print_inline_script_tag( '(function(){var b=document.getElementById("lg-ojo");if(!b)return;var i=document.getElementById("pwd"),m=b.getAttribute("aria-label"),o=b.dataset.ocultar;b.onclick=function(){var v=i.type==="password";i.type=v?"text":"password";b.setAttribute("aria-pressed",v?"true":"false");b.setAttribute("aria-label",v?o:m);i.focus();};})();' );
?>
</body>
</html>
