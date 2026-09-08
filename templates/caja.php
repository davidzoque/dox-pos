<?php
/**
 * La caja: página completa, fuera del tema.
 *
 * @package DoxPos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$logo  = dox_pos_logo_url();
$brand = dox_pos_brand_name();
$cfg   = dox_pos_js_config();
dox_pos_enqueue_caja( $cfg );
?>
<!doctype html>
<html lang="es">
<head>
<?php dox_pos_head(); ?>
</head>
<body>
<div class="app" id="app">
	<header class="bar">
		<span class="brand">
			<?php if ( $logo ) : ?>
				<img class="logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>">
			<?php else : ?>
				<span class="brand-text"><?php echo esc_html( $brand ); ?></span>
			<?php endif; ?>
			<span class="cajita"><?php echo esc_html( $cfg['screen'] ); ?></span>
		</span>
		<nav class="tabs" id="tabs">
			<button type="button" data-t="vender" aria-pressed="true"><?php esc_html_e( 'Vender', 'dox-pos' ); ?></button>
			<button type="button" data-t="entrada" aria-pressed="false"><?php esc_html_e( 'Entró mercancía', 'dox-pos' ); ?></button>
			<button type="button" data-t="pedidos" aria-pressed="false"><?php esc_html_e( 'Pedidos', 'dox-pos' ); ?> <span id="npend"></span></button>
			<?php if ( $cfg['products'] ) : ?>
			<button type="button" data-t="producto" aria-pressed="false"><?php esc_html_e( 'Productos', 'dox-pos' ); ?></button>
			<?php endif; ?>
			<button type="button" data-t="historial" aria-pressed="false"><?php echo esc_html( $cfg['history_full'] ? __( 'Historial', 'dox-pos' ) : __( 'Mi día', 'dox-pos' ) ); ?></button>
			<?php do_action( 'dox_pos_tabs', $cfg ); // Pestañas de los añadidos (el Pro pone Asistente). ?>
		</nav>
		<span class="user"><?php echo esc_html( $cfg['user'] ); ?> · <a href="<?php echo esc_url( $cfg['logout'] ); ?>"><?php esc_html_e( 'Salir', 'dox-pos' ); ?></a></span>
	</header>
	<?php do_action( 'dox_pos_after_header', $cfg ); // Avisos bajo la cabecera (el Pro pone el de datos de demostración). ?>
	<div class="cola" id="cola" hidden></div>

	<div class="body">
		<!-- ================= VENDER ================= -->
		<section class="tab" id="t-vender">
			<div class="paneseg"><div class="seg" id="paneseg">
				<button type="button" data-p="buscar" aria-pressed="true"><?php esc_html_e( 'Buscar producto', 'dox-pos' ); ?></button>
				<button type="button" data-p="pedido" aria-pressed="false"><?php esc_html_e( 'El pedido', 'dox-pos' ); ?> <span id="npane"></span></button>
			</div></div>
			<div class="cols">
				<div class="col cat" id="col-cat">
					<div class="searchwrap"><input id="q" type="search" placeholder="<?php esc_attr_e( 'Buscar por nombre o código', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Buscar producto', 'dox-pos' ); ?>"></div>
					<div class="scroll"><ul class="res" id="res"></ul></div>
				</div>
				<div class="col ord" id="col-ord">
					<div class="scroll">
						<div class="grp">
							<h4><?php esc_html_e( 'Productos', 'dox-pos' ); ?> <span id="n-lin" class="cnt"></span></h4>
							<ul class="res" id="lineas"></ul>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Cómo entró la venta', 'dox-pos' ); ?></h4>
							<div class="chips" id="f-canal"></div>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Cliente', 'dox-pos' ); ?></h4>
							<div class="g2">
								<div class="field"><label for="f-nom"><?php esc_html_e( 'Nombre', 'dox-pos' ); ?></label><input id="f-nom" autocomplete="off"></div>
								<div class="field"><label for="f-tel"><?php esc_html_e( 'WhatsApp', 'dox-pos' ); ?></label><input id="f-tel" inputmode="tel" autocomplete="off"></div>
								<div class="field"><label for="f-dep"><?php echo esc_html( $cfg['state_label'] ); ?></label><select id="f-dep"></select></div>
								<div class="field"><label for="f-ciu"><?php esc_html_e( 'Ciudad', 'dox-pos' ); ?></label><input id="f-ciu" list="ciudades" autocomplete="off"><datalist id="ciudades"></datalist></div>
							</div>
							<div class="field mt"><label for="f-dir"><?php esc_html_e( 'Dirección', 'dox-pos' ); ?></label><input id="f-dir" autocomplete="off"></div>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Pago', 'dox-pos' ); ?></h4>
							<div class="chips" id="f-pago"></div>
							<div class="field mt"><label for="f-desc"><?php esc_html_e( 'Descuento', 'dox-pos' ); ?></label><input id="f-desc" value="0" inputmode="numeric"></div>
						</div>
						<div class="grp" id="g-envio">
							<h4><?php esc_html_e( 'Envío', 'dox-pos' ); ?></h4>
							<div class="chips" id="f-envio"></div>
							<div class="field mt"><label for="f-env"><?php esc_html_e( 'Costo del envío', 'dox-pos' ); ?></label><input id="f-env" value="0" inputmode="numeric"></div>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Nota', 'dox-pos' ); ?></h4>
							<textarea id="f-nota" aria-label="<?php esc_attr_e( 'Nota', 'dox-pos' ); ?>"></textarea>
						</div>
					</div>
					<div class="foot">
						<div class="sum" id="sum"></div>
						<button type="button" class="go" id="reg"><?php esc_html_e( 'Ya pagó: registrar la venta', 'dox-pos' ); ?></button>
						<button type="button" class="go alt" id="apartar"><?php esc_html_e( 'Todavía no paga: apartar', 'dox-pos' ); ?></button>
					</div>
				</div>
			</div>
		</section>

		<!-- ================= ENTRÓ MERCANCÍA ================= -->
		<section class="tab" id="t-entrada" hidden>
			<div class="paneseg"><div class="seg" id="paneseg2">
				<button type="button" data-p="buscar" aria-pressed="true"><?php esc_html_e( 'Buscar producto', 'dox-pos' ); ?></button>
				<button type="button" data-p="pedido" aria-pressed="false"><?php esc_html_e( 'Lo que llegó', 'dox-pos' ); ?> <span id="npane2"></span></button>
			</div></div>
			<div class="cols">
				<div class="col cat" id="col-cat2">
					<div class="searchwrap"><input id="q2" type="search" placeholder="<?php esc_attr_e( 'Buscar lo que llegó', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Buscar lo que llegó', 'dox-pos' ); ?>"></div>
					<div class="scroll"><ul class="res" id="res2"></ul></div>
				</div>
				<div class="col ord" id="col-ord2">
					<div class="scroll">
						<div class="grp">
							<h4><?php esc_html_e( 'De dónde viene', 'dox-pos' ); ?></h4>
							<div class="g2">
								<div class="field"><label for="e-prov"><?php esc_html_e( 'Proveedor', 'dox-pos' ); ?></label><input id="e-prov" autocomplete="off"></div>
								<div class="field"><label for="e-fac"><?php esc_html_e( 'Factura o remisión', 'dox-pos' ); ?></label><input id="e-fac" autocomplete="off"></div>
							</div>
							<div class="field mt"><label for="e-fec"><?php esc_html_e( 'Fecha', 'dox-pos' ); ?></label><input id="e-fec" type="date"></div>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Lo que llegó', 'dox-pos' ); ?> <span id="n-lin2" class="cnt"></span></h4>
							<ul class="res" id="lineas2"></ul>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Nota', 'dox-pos' ); ?></h4>
							<textarea id="e-nota" aria-label="<?php esc_attr_e( 'Nota', 'dox-pos' ); ?>"></textarea>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Últimas entradas', 'dox-pos' ); ?></h4>
							<ul class="res" id="entradas"></ul>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Inventario completo', 'dox-pos' ); ?></h4>
							<a class="go alt dl" id="e-excel" href="<?php echo esc_url( add_query_arg( 'descargar', 'inventario', dox_pos_url() ) ); ?>" download><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg><?php esc_html_e( 'Descargar en Excel', 'dox-pos' ); ?></a>
							<p class="hint"><?php echo esc_html( $cfg['costs'] ? __( 'Todas las referencias con talla, color, precio, costo, existencias y su valor (a precio y al costo), y un resumen por categoría. Abre en Excel, Numbers o Google Sheets.', 'dox-pos' ) : __( 'Todas las referencias con talla, color, precio, existencias y su valor, y un resumen por categoría. Abre en Excel, Numbers o Google Sheets.', 'dox-pos' ) ); ?></p>
						</div>
						<?php if ( $cfg['costs'] ) : // Los costos de golpe, desde ese mismo Excel con la columna Costo llena. ?>
						<div class="grp">
							<h4><?php esc_html_e( 'Cargar costos', 'dox-pos' ); ?></h4>
							<input type="file" id="e-costos-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden>
							<button type="button" class="go alt dl" id="e-costos"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="m7 8 5-5 5 5"/><path d="M5 21h14"/></svg><?php esc_html_e( 'Subir costos desde Excel', 'dox-pos' ); ?></button>
							<p class="hint"><?php esc_html_e( 'Descarga el inventario, llena la columna Costo (en la fila de cada talla, o en la fila del producto para ponérselo a todas) y súbelo aquí. Solo cambia los costos: no toca existencias ni precios.', 'dox-pos' ); ?></p>
						</div>
						<?php endif; ?>
					</div>
					<div class="foot">
						<div class="sum" id="sum2"></div>
						<button type="button" class="go" id="reg2"><?php esc_html_e( 'Guardar entrada y sumar', 'dox-pos' ); ?></button>
					</div>
				</div>
			</div>
		</section>

		<!-- ================= PEDIDOS ================= -->
		<section class="tab" id="t-pedidos" hidden>
			<div class="wrapx">
				<table class="ped">
					<thead><tr>
						<th><?php esc_html_e( 'Pedido', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Cliente', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Productos', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Canal', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Pago', 'dox-pos' ); ?></th>
						<th class="num"><?php esc_html_e( 'Total', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'dox-pos' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody id="tped"></tbody>
				</table>
				<p class="empty big-empty" id="ped-empty" hidden><?php echo esc_html( dox_pos_show_web_orders() ? __( 'Los pedidos de la caja y de la página web aparecerán aquí.', 'dox-pos' ) : __( 'Los pedidos que registres desde la caja aparecerán aquí.', 'dox-pos' ) ); ?></p>
			</div>
		</section>

		<?php if ( $cfg['products'] ) : ?>
		<!-- ================= PRODUCTOS: crear uno nuevo o editar uno ================= -->
		<section class="tab" id="t-producto" hidden>
			<div class="col prodcol">
				<div class="pmode"><div class="seg" id="pmode">
					<button type="button" data-m="nuevo" aria-pressed="true"><?php esc_html_e( 'Nuevo producto', 'dox-pos' ); ?></button>
					<button type="button" data-m="editar" aria-pressed="false"><?php esc_html_e( 'Editar uno', 'dox-pos' ); ?></button>
				</div></div>
				<div class="scroll">
					<div class="prodwrap" id="p-buscar" hidden>
						<div class="grp">
							<h4><?php esc_html_e( 'Qué producto', 'dox-pos' ); ?></h4>
							<div class="searchwrap"><input id="p-q" type="search" placeholder="<?php esc_attr_e( 'Nombre o código', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Buscar el producto', 'dox-pos' ); ?>"></div>
							<ul class="res" id="p-res"></ul>
							<div class="pager" id="p-pager" hidden><button type="button" class="mini" id="p-prev"><?php esc_html_e( 'Anteriores', 'dox-pos' ); ?></button><span id="p-pager-txt"></span><button type="button" class="mini" id="p-next"><?php esc_html_e( 'Siguientes', 'dox-pos' ); ?></button></div>
							<p class="hint"><?php esc_html_e( 'Aquí puedes cambiar el nombre, la categoría, el precio, las fotos, la descripción, las unidades y si está publicado, y añadir tallas o colores nuevos. Para quitar una talla o un color, o para cambiar el código, hay que entrar a WooCommerce.', 'dox-pos' ); ?></p>
						</div>
					</div>
					<div class="prodwrap" id="p-form">
						<div class="editbar" id="p-editbar" hidden><span><b id="p-edit-title"></b><i id="p-edit-hint"></i></span><span class="editacts"><a class="undo" id="p-edit-view" href="#" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg><?php esc_html_e( 'Ver en la tienda', 'dox-pos' ); ?></a><button type="button" class="undo" id="p-edit-cancel"><?php esc_html_e( 'Cancelar', 'dox-pos' ); ?></button></span></div>
						<div class="newbar" id="p-newbar"><i><?php esc_html_e( 'Rellena lo que sepas; antes de crear verás un resumen para revisarlo. Lo escrito se guarda en este teléfono hasta que lo crees.', 'dox-pos' ); ?></i><button type="button" class="undo" id="p-vaciar" hidden><?php esc_html_e( 'Empezar de cero', 'dox-pos' ); ?></button></div>
						<div class="grp">
							<h4><?php esc_html_e( 'Fotos', 'dox-pos' ); ?> <span class="cnt" id="p-nfotos"></span></h4>
							<div class="fotos" id="p-fotos"></div>
							<input type="file" id="p-file" accept="image/*,.heic,.heif" multiple hidden>
							<p class="hint"><?php esc_html_e( 'La primera es la principal. Valen las del iPhone (HEIC): en la tienda quedan como WebP y la original no se guarda.', 'dox-pos' ); ?></p>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'El producto', 'dox-pos' ); ?></h4>
							<div class="field"><label for="p-nom"><?php esc_html_e( 'Nombre', 'dox-pos' ); ?></label><input id="p-nom" autocomplete="off" placeholder="<?php esc_attr_e( 'Vestido Luna', 'dox-pos' ); ?>"><p class="fwarn" id="p-nom-dup" hidden></p></div>
							<div class="field mt"><span class="lbl" id="p-cats-label"><?php esc_html_e( 'Categoría', 'dox-pos' ); ?></span><div class="catgroups" id="p-cats" role="group" aria-labelledby="p-cats-label"></div></div>
							<div class="g2 mt">
								<div class="field"><label for="p-precio"><?php esc_html_e( 'Precio', 'dox-pos' ); ?></label><input id="p-precio" inputmode="numeric" autocomplete="off" placeholder="0"></div>
								<div class="field"><label for="p-sku"><?php esc_html_e( 'Código', 'dox-pos' ); ?></label><input id="p-sku" autocomplete="off" autocapitalize="characters" spellcheck="false"><span class="pstatus" id="p-sku-st" aria-live="polite"></span></div>
							</div>
							<?php if ( $cfg['costs'] ) : // El costo por unidad, solo para quien administra. ?>
							<div class="g2 mt">
								<div class="field"><label for="p-costo"><?php esc_html_e( 'Costo por unidad', 'dox-pos' ); ?></label><input id="p-costo" inputmode="numeric" autocomplete="off" placeholder="0"></div>
								<div class="field"><span class="lbl"><?php esc_html_e( 'Ganancia', 'dox-pos' ); ?></span><p class="margen" id="p-margen"><?php esc_html_e( 'Escribe precio y costo.', 'dox-pos' ); ?></p></div>
							</div>
							<p class="hint"><?php esc_html_e( 'Lo que pagaste por cada unidad, con el envío del proveedor si lo hubo. Solo lo ven quienes administran; en cada venta queda la ganancia. Un producto de tallas lleva un costo para todas; si alguna cuesta distinto, se pone en WooCommerce.', 'dox-pos' ); ?></p>
							<?php endif; ?>
						</div>
						<div class="grp" id="g-colores">
							<h4><?php esc_html_e( 'Colores', 'dox-pos' ); ?> <span class="cnt" id="p-ncol"></span></h4>
							<div class="chips" id="p-colores"></div>
							<div class="nuevocolor" id="p-nuevocolor" hidden>
								<label class="swatchpick" title="<?php esc_attr_e( 'Elegir el tono', 'dox-pos' ); ?>"><input type="color" id="p-color-hex" value="#F4C1C0" aria-label="<?php esc_attr_e( 'Tono del color nuevo', 'dox-pos' ); ?>"></label>
								<input id="p-color-nom" placeholder="<?php esc_attr_e( 'Nombre del color', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Nombre del color nuevo', 'dox-pos' ); ?>">
								<button type="button" class="mini" id="p-color-add"><?php esc_html_e( 'Añadir', 'dox-pos' ); ?></button>
							</div>
							<p class="hint"><?php esc_html_e( 'Sin colores: el producto solo varía por talla.', 'dox-pos' ); ?></p>
						</div>
						<div class="grp" id="g-tallas">
							<h4><?php esc_html_e( 'Tallas', 'dox-pos' ); ?> <span class="cnt" id="p-ntal"></span></h4>
							<div class="chips" id="p-tallas"></div>
							<p class="hint" id="p-tallas-hint"><?php esc_html_e( 'Elige la categoría y salen sus tallas.', 'dox-pos' ); ?></p>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Unidades', 'dox-pos' ); ?> <span class="cnt"><button type="button" class="undo" id="p-todo1"><?php esc_html_e( 'Todas en 1', 'dox-pos' ); ?></button> <button type="button" class="undo" id="p-todo0"><?php esc_html_e( 'Todas en 0', 'dox-pos' ); ?></button></span></h4>
							<div class="wrapx qtywrap"><table class="qtyt" id="p-qty"></table></div>
							<p class="hint" id="p-qty-shared" hidden></p>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Descripción', 'dox-pos' ); ?> <span class="cnt"><?php esc_html_e( 'opcional', 'dox-pos' ); ?></span></h4>
							<textarea id="p-desc" aria-label="<?php esc_attr_e( 'Descripción', 'dox-pos' ); ?>" placeholder="<?php esc_attr_e( 'Tela, detalles, cómo se lava…', 'dox-pos' ); ?>"></textarea>
						</div>
						<div class="grp">
							<label class="switch"><input type="checkbox" id="p-pub" checked><span class="switch-ui" aria-hidden="true"></span><span><span id="p-pub-text"><?php esc_html_e( 'Publicar en la tienda ahora', 'dox-pos' ); ?></span><i id="p-pub-hint"><?php esc_html_e( 'Apagado: queda guardado pero oculto; lo publicas después desde Editar uno.', 'dox-pos' ); ?></i></span></label>
						</div>
					</div>
				</div>
				<div class="foot" id="p-foot">
					<div class="prodwrap">
						<div class="sum" id="p-sum"></div>
						<button type="button" class="go" id="p-crear"><?php esc_html_e( 'Crear producto', 'dox-pos' ); ?></button>
					</div>
				</div>
			</div>
		</section>
		<?php endif; ?>
		<!-- ================= HISTORIAL: ventas, la caja del día y los movimientos ================= -->
		<section class="tab" id="t-historial" hidden>
			<div class="histbar">
				<?php if ( $cfg['history_full'] ) : ?>
				<div class="seg" id="h-periodo">
					<button type="button" data-p="hoy" aria-pressed="true"><?php esc_html_e( 'Hoy', 'dox-pos' ); ?></button>
					<button type="button" data-p="semana" aria-pressed="false"><?php esc_html_e( 'Esta semana', 'dox-pos' ); ?></button>
					<button type="button" data-p="mes" aria-pressed="false"><?php esc_html_e( 'Este mes', 'dox-pos' ); ?></button>
					<button type="button" data-p="fechas" aria-pressed="false"><?php esc_html_e( 'Fechas', 'dox-pos' ); ?></button>
				</div>
				<div class="hfechas" id="h-fechas" hidden><input type="date" id="h-desde" aria-label="<?php esc_attr_e( 'Desde', 'dox-pos' ); ?>"><span><?php esc_html_e( 'a', 'dox-pos' ); ?></span><input type="date" id="h-hasta" aria-label="<?php esc_attr_e( 'Hasta', 'dox-pos' ); ?>"></div>
				<div class="seg" id="h-vista">
					<button type="button" data-v="ventas" aria-pressed="true"><?php esc_html_e( 'Ventas', 'dox-pos' ); ?></button>
					<button type="button" data-v="caja" aria-pressed="false"><?php esc_html_e( 'Caja del día', 'dox-pos' ); ?></button>
					<button type="button" data-v="mov" aria-pressed="false"><?php esc_html_e( 'Movimientos', 'dox-pos' ); ?></button>
					<?php do_action( 'dox_pos_history_views', $cfg ); // Vistas de los añadidos (el Pro pone Rendimiento): un botón data-v="<vista>". ?>
				</div>
				<?php endif; ?>
			</div>
			<div class="scroll"><div class="histwrap">
				<div class="hfil" id="h-fil" hidden>
					<div class="searchwrap"><input id="h-q" type="search" placeholder="<?php esc_attr_e( 'Producto o código', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Buscar en los movimientos', 'dox-pos' ); ?>"></div>
					<div class="chips" id="h-tipo"></div>
				</div>
				<div id="h-ventas"></div>
				<div id="h-caja" hidden></div>
				<div id="h-mov" hidden></div>
				<?php do_action( 'dox_pos_history_panels', $cfg ); // Sus paneles: un div id="h-<vista>" oculto. ?>
			</div></div>
		</section>

		<?php do_action( 'dox_pos_sections', $cfg ); // El Pro pone aquí la pestaña Asistente. ?>
	</div>
	<div class="toasts" id="toasts" aria-live="polite"></div>
	<div class="modal" id="modal" hidden><div class="card" id="modal-card" role="dialog" aria-modal="true"></div></div>
</div>
<?php
do_action( 'dox_pos_scripts', $cfg ); // Los añadidos encolan los suyos, con dependencia de dox-pos-caja.
wp_print_scripts( dox_pos_assets( 'script' ) ); // caja.js con su configuración delante, y detrás los de los añadidos.
?>
<script>window.DoxPOS && window.DoxPOS.arrancar();</script>
</body>
</html>
