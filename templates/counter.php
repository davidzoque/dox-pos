<?php
/**
 * La pestaña Mostrador (includes/counter.php): el escáner y los botones rápidos a la izquierda, la
 * venta y el cobro a la derecha. Lo pinta y lo mueve assets/js/mostrador.js.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<section class="tab" id="t-mostrador" hidden>
			<div class="turno">
				<span class="m-turno" id="m-turno"></span>
				<span class="m-turno-acc">
					<button type="button" class="mini" id="m-devolver"><?php esc_html_e( 'Return', 'dox-pos' ); ?> <kbd>F3</kbd></button>
					<button type="button" class="mini" id="m-ultimo-ticket" hidden><?php esc_html_e( 'Last receipt', 'dox-pos' ); ?></button>
					<button type="button" class="mini" id="m-cerrar" hidden><?php esc_html_e( 'Close the till', 'dox-pos' ); ?></button>
					<button type="button" class="mini sec" id="m-atajos"><?php esc_html_e( 'This device', 'dox-pos' ); ?></button>
				</span>
			</div>
			<div class="cols most">
				<div class="col cat">
					<div class="lector">
						<div class="caja-esc">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M10 8v8M13 8v8M17 8v8"/></svg>
							<input id="m-q" type="search" placeholder="<?php esc_attr_e( 'Scan, or type the name or SKU', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Scan or search', 'dox-pos' ); ?>">
							<button type="button" class="cam" id="m-cam" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg><?php esc_html_e( 'Camera', 'dox-pos' ); ?></button>
						</div>
						<div class="estado" id="m-estado"></div>
					</div>
					<div class="ultimo" id="m-ultimo" hidden></div>
					<div class="scroll">
						<ul class="res" id="m-res" hidden></ul>
						<div id="m-rapidos-box">
							<div class="rtit m-rtit"><span><?php esc_html_e( 'No barcode', 'dox-pos' ); ?></span><?php if ( ! empty( $cfg['counter']['edit'] ) ) : ?><button type="button" class="linkbtn" id="m-editar"><?php esc_html_e( 'Choose buttons', 'dox-pos' ); ?></button><?php endif; ?></div>
							<div class="rapidos" id="m-rapidos"></div>
						</div>
					</div>
				</div>
				<div class="col ord">
					<div class="scroll">
						<div class="grp">
							<h4><?php esc_html_e( 'This sale', 'dox-pos' ); ?> <span id="m-n" class="cnt"></span></h4>
							<ul class="res" id="m-lineas"></ul>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Customer', 'dox-pos' ); ?> <span class="cnt"><?php esc_html_e( 'optional', 'dox-pos' ); ?></span></h4>
							<div class="g2">
								<input id="m-nom" autocomplete="off" placeholder="<?php esc_attr_e( 'Name', 'dox-pos' ); ?>" aria-label="<?php esc_attr_e( 'Customer name', 'dox-pos' ); ?>">
								<input id="m-tel" type="tel" autocomplete="off" placeholder="<?php esc_attr_e( 'Phone', 'dox-pos' ); ?>" aria-label="<?php esc_attr_e( 'Customer phone', 'dox-pos' ); ?>">
							</div>
						</div>
					</div>
					<div class="foot">
						<div class="acc">
							<button type="button" class="mini" id="m-espera"><span><?php esc_html_e( 'On hold', 'dox-pos' ); ?><span class="badge" id="m-nesp" hidden></span></span><kbd>F8</kbd></button>
							<button type="button" class="mini" id="m-desc"><?php esc_html_e( 'Discount', 'dox-pos' ); ?><kbd>F9</kbd></button>
							<button type="button" class="mini sec" id="m-vaciar"><?php esc_html_e( 'Empty', 'dox-pos' ); ?><kbd>Esc</kbd></button>
						</div>
						<div class="sum" id="m-sum"></div>
						<div class="pagar">
							<button type="button" class="go" id="m-efectivo"><?php esc_html_e( 'Cash', 'dox-pos' ); ?><kbd>F4</kbd></button>
							<button type="button" class="go alt" id="m-tarjeta"><?php esc_html_e( 'Card', 'dox-pos' ); ?><kbd>F6</kbd></button>
							<button type="button" class="go alt" id="m-otro"><?php esc_html_e( 'Other / split', 'dox-pos' ); ?><kbd>F7</kbd></button>
						</div>
					</div>
				</div>
			</div>
		</section>
