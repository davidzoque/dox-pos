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
			<?php if ( $cfg['history_full'] ) : ?>
			<button type="button" data-t="panel" aria-pressed="false"><?php esc_html_e( 'Dashboard', 'dox-pos' ); ?></button>
			<?php endif; ?>
			<button type="button" data-t="vender" aria-pressed="true"><?php esc_html_e( 'Sell', 'dox-pos' ); ?></button>
			<button type="button" data-t="entrada" aria-pressed="false"><?php esc_html_e( 'Inventory', 'dox-pos' ); ?></button>
			<button type="button" data-t="pedidos" aria-pressed="false"><?php esc_html_e( 'Orders', 'dox-pos' ); ?> <span id="npend"></span></button>
			<?php if ( $cfg['products'] ) : ?>
			<button type="button" data-t="producto" aria-pressed="false"><?php esc_html_e( 'Products', 'dox-pos' ); ?></button>
			<?php endif; ?>
			<button type="button" data-t="historial" aria-pressed="false"><?php echo esc_html( $cfg['history_full'] ? __( 'Reports', 'dox-pos' ) : __( 'My day', 'dox-pos' ) ); ?></button>
			<?php do_action( 'dox_pos_tabs', $cfg ); // Pestañas de los añadidos (el Pro pone Asistente). ?>
		</nav>
		<span class="user"><?php echo esc_html( $cfg['user'] ); ?> · <a href="<?php echo esc_url( $cfg['logout'] ); ?>"><?php esc_html_e( 'Sign out', 'dox-pos' ); ?></a></span>
	</header>
	<?php do_action( 'dox_pos_after_header', $cfg ); // Avisos bajo la cabecera (el Pro pone el de datos de demostración). ?>
	<div class="cola" id="cola" hidden></div>

	<div class="body">
		<!-- ================= VENDER ================= -->
		<section class="tab" id="t-vender">
			<div class="paneseg"><div class="seg" id="paneseg">
				<button type="button" data-p="buscar" aria-pressed="true"><?php esc_html_e( 'Search products', 'dox-pos' ); ?></button>
				<button type="button" data-p="pedido" aria-pressed="false"><?php esc_html_e( 'The order', 'dox-pos' ); ?> <span id="npane"></span></button>
			</div></div>
			<div class="cols">
				<div class="col cat" id="col-cat">
					<div class="searchwrap"><input id="q" type="search" placeholder="<?php esc_attr_e( 'Search by name or SKU', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Search products', 'dox-pos' ); ?>"></div>
					<div class="scroll"><ul class="res" id="res"></ul></div>
				</div>
				<div class="col ord" id="col-ord">
					<div class="scroll">
						<div class="grp">
							<h4><?php esc_html_e( 'Products', 'dox-pos' ); ?> <span id="n-lin" class="cnt"></span></h4>
							<ul class="res" id="lineas"></ul>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'How the sale came in', 'dox-pos' ); ?></h4>
							<div class="chips" id="f-canal"></div>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Customer', 'dox-pos' ); ?></h4>
							<div class="g2">
								<div class="field"><label for="f-nom"><?php esc_html_e( 'Name', 'dox-pos' ); ?></label><input id="f-nom" autocomplete="off"></div>
								<div class="field"><label for="f-tel"><?php esc_html_e( 'WhatsApp', 'dox-pos' ); ?></label><input id="f-tel" inputmode="tel" autocomplete="off"></div>
								<div class="field"><label for="f-dep"><?php echo esc_html( $cfg['state_label'] ); ?></label><select id="f-dep"></select></div>
								<div class="field"><label for="f-ciu"><?php esc_html_e( 'City', 'dox-pos' ); ?></label><input id="f-ciu" list="ciudades" autocomplete="off"><datalist id="ciudades"></datalist></div>
							</div>
							<div class="field mt"><label for="f-dir"><?php esc_html_e( 'Address', 'dox-pos' ); ?></label><input id="f-dir" autocomplete="off"></div>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Payment', 'dox-pos' ); ?></h4>
							<div class="chips" id="f-pago"></div>
							<div class="field mt"><label for="f-desc"><?php esc_html_e( 'Discount', 'dox-pos' ); ?></label><input id="f-desc" value="0" inputmode="numeric"></div>
						</div>
						<div class="grp" id="g-envio">
							<h4><?php esc_html_e( 'Shipping', 'dox-pos' ); ?></h4>
							<div class="chips" id="f-envio"></div>
							<div class="field mt"><label for="f-env"><?php esc_html_e( 'Shipping cost', 'dox-pos' ); ?></label><input id="f-env" value="0" inputmode="numeric"></div>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Note', 'dox-pos' ); ?></h4>
							<textarea id="f-nota" aria-label="<?php esc_attr_e( 'Note', 'dox-pos' ); ?>"></textarea>
						</div>
					</div>
					<div class="foot">
						<div class="sum" id="sum"></div>
						<button type="button" class="go" id="reg"><?php esc_html_e( 'Paid: record the sale', 'dox-pos' ); ?></button>
						<button type="button" class="go alt" id="apartar"><?php esc_html_e( 'Not paid yet: put on layaway', 'dox-pos' ); ?></button>
					</div>
				</div>
			</div>
		</section>

		<!-- ================= ENTRÓ MERCANCÍA ================= -->
		<section class="tab" id="t-entrada" hidden>
			<div class="paneseg"><div class="seg" id="paneseg2">
				<button type="button" data-p="buscar" aria-pressed="true"><?php esc_html_e( 'Search products', 'dox-pos' ); ?></button>
				<button type="button" data-p="pedido" aria-pressed="false"><?php esc_html_e( 'What arrived', 'dox-pos' ); ?> <span id="npane2"></span></button>
			</div></div>
			<div class="cols">
				<div class="col cat" id="col-cat2">
					<div class="searchwrap"><input id="q2" type="search" placeholder="<?php esc_attr_e( 'Search what arrived', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Search what arrived', 'dox-pos' ); ?>"></div>
					<div class="scroll"><ul class="res" id="res2"></ul></div>
				</div>
				<div class="col ord" id="col-ord2">
					<div class="scroll">
						<div class="grp">
							<h4><?php esc_html_e( 'Where it comes from', 'dox-pos' ); ?></h4>
							<div class="g2">
								<div class="field"><label for="e-prov"><?php esc_html_e( 'Supplier', 'dox-pos' ); ?></label><input id="e-prov" autocomplete="off"></div>
								<div class="field"><label for="e-fac"><?php esc_html_e( 'Invoice or delivery note', 'dox-pos' ); ?></label><input id="e-fac" autocomplete="off"></div>
							</div>
							<div class="field mt"><label for="e-fec"><?php esc_html_e( 'Date', 'dox-pos' ); ?></label><input id="e-fec" type="date"></div>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'What arrived', 'dox-pos' ); ?> <span id="n-lin2" class="cnt"></span></h4>
							<ul class="res" id="lineas2"></ul>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Note', 'dox-pos' ); ?></h4>
							<textarea id="e-nota" aria-label="<?php esc_attr_e( 'Note', 'dox-pos' ); ?>"></textarea>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Latest stock entries', 'dox-pos' ); ?></h4>
							<ul class="res" id="entradas"></ul>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Full inventory', 'dox-pos' ); ?></h4>
							<a class="go alt dl" id="e-excel" href="<?php echo esc_url( add_query_arg( 'descargar', 'inventario', dox_pos_url() ) ); ?>" download><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg><?php esc_html_e( 'Download as Excel', 'dox-pos' ); ?></a>
							<p class="hint"><?php echo esc_html( $cfg['costs'] ? __( 'Every product with size, color, price, cost, stock and its value (at price and at cost), plus a summary by category. Opens in Excel, Numbers or Google Sheets.', 'dox-pos' ) : __( 'Every product with size, color, price, stock and its value, plus a summary by category. Opens in Excel, Numbers or Google Sheets.', 'dox-pos' ) ); ?></p>
						</div>
						<?php if ( $cfg['costs'] ) : // Los costos de golpe, desde ese mismo Excel con la columna Costo llena. ?>
						<div class="grp">
							<h4><?php esc_html_e( 'Upload costs', 'dox-pos' ); ?></h4>
							<input type="file" id="e-costos-file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden>
							<button type="button" class="go alt dl" id="e-costos"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V3"/><path d="m7 8 5-5 5 5"/><path d="M5 21h14"/></svg><?php esc_html_e( 'Upload costs from Excel', 'dox-pos' ); ?></button>
							<p class="hint"><?php esc_html_e( 'Download the inventory, fill in the Cost column (on each size row, or on the product row to set it for all of them) and upload it here. It only changes costs: it does not touch stock or prices.', 'dox-pos' ); ?></p>
						</div>
						<?php endif; ?>
					</div>
					<div class="foot">
						<div class="sum" id="sum2"></div>
						<button type="button" class="go" id="reg2"><?php esc_html_e( 'Save the entry and add the stock', 'dox-pos' ); ?></button>
					</div>
				</div>
			</div>
		</section>

		<!-- ================= PEDIDOS ================= -->
		<section class="tab" id="t-pedidos" hidden>
			<div class="wrapx">
				<table class="ped">
					<thead><tr>
						<th><?php esc_html_e( 'Order', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Products', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Channel', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Payment', 'dox-pos' ); ?></th>
						<th class="num"><?php esc_html_e( 'Total', 'dox-pos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'dox-pos' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody id="tped"></tbody>
				</table>
				<p class="empty big-empty" id="ped-empty" hidden><?php echo esc_html( dox_pos_show_web_orders() ? __( 'Orders from the register and from the website will show up here.', 'dox-pos' ) : __( 'The orders you record from the register will show up here.', 'dox-pos' ) ); ?></p>
			</div>
		</section>

		<?php if ( $cfg['products'] ) : ?>
		<!-- ================= PRODUCTOS: crear uno nuevo o editar uno ================= -->
		<section class="tab" id="t-producto" hidden>
			<div class="col prodcol">
				<div class="pmode"><div class="seg" id="pmode">
					<button type="button" data-m="nuevo" aria-pressed="true"><?php esc_html_e( 'New product', 'dox-pos' ); ?></button>
					<button type="button" data-m="editar" aria-pressed="false"><?php esc_html_e( 'Edit one', 'dox-pos' ); ?></button>
				</div></div>
				<div class="scroll">
					<div class="prodwrap" id="p-buscar" hidden>
						<div class="grp">
							<h4><?php esc_html_e( 'Which product', 'dox-pos' ); ?></h4>
							<div class="searchwrap"><input id="p-q" type="search" placeholder="<?php esc_attr_e( 'Name or SKU', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Search for the product', 'dox-pos' ); ?>"></div>
							<ul class="res" id="p-res"></ul>
							<div class="pager" id="p-pager" hidden><button type="button" class="mini" id="p-prev"><?php esc_html_e( 'Previous', 'dox-pos' ); ?></button><span id="p-pager-txt"></span><button type="button" class="mini" id="p-next"><?php esc_html_e( 'Next', 'dox-pos' ); ?></button></div>
							<p class="hint"><?php esc_html_e( 'Here you can change the name, the category, the price, the photos, the description, the units and whether it is published, and add new sizes or colors. To remove a size or a color, or to change the SKU, you have to go into WooCommerce.', 'dox-pos' ); ?></p>
						</div>
					</div>
					<div class="prodwrap" id="p-form">
						<div class="editbar" id="p-editbar" hidden><span><b id="p-edit-title"></b><i id="p-edit-hint"></i></span><span class="editacts"><a class="undo" id="p-edit-view" href="#" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg><?php esc_html_e( 'View in the store', 'dox-pos' ); ?></a><button type="button" class="undo" id="p-edit-cancel"><?php esc_html_e( 'Cancel', 'dox-pos' ); ?></button></span></div>
						<div class="newbar" id="p-newbar"><i><?php esc_html_e( 'Fill in what you know; before creating you will see a summary to review. What you type is kept on this phone until you create it.', 'dox-pos' ); ?></i><button type="button" class="undo" id="p-vaciar" hidden><?php esc_html_e( 'Start over', 'dox-pos' ); ?></button></div>
						<div class="grp">
							<h4><?php esc_html_e( 'Photos', 'dox-pos' ); ?> <span class="cnt" id="p-nfotos"></span></h4>
							<div class="fotos" id="p-fotos"></div>
							<input type="file" id="p-file" accept="image/*,.heic,.heif" multiple hidden>
							<p class="hint"><?php esc_html_e( 'The first one is the main photo. iPhone photos (HEIC) work: the store keeps them as WebP and the original is not stored.', 'dox-pos' ); ?></p>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'The product', 'dox-pos' ); ?></h4>
							<div class="field"><label for="p-nom"><?php esc_html_e( 'Name', 'dox-pos' ); ?></label><input id="p-nom" autocomplete="off" placeholder="<?php esc_attr_e( 'Luna dress', 'dox-pos' ); ?>"><p class="fwarn" id="p-nom-dup" hidden></p></div>
							<div class="field mt"><span class="lbl" id="p-cats-label"><?php esc_html_e( 'Category', 'dox-pos' ); ?></span><div class="catgroups" id="p-cats" role="group" aria-labelledby="p-cats-label"></div></div>
							<div class="g2 mt">
								<div class="field"><label for="p-precio"><?php esc_html_e( 'Price', 'dox-pos' ); ?></label><input id="p-precio" inputmode="numeric" autocomplete="off" placeholder="0"></div>
								<div class="field"><label for="p-sku"><?php esc_html_e( 'SKU', 'dox-pos' ); ?></label><input id="p-sku" autocomplete="off" autocapitalize="characters" spellcheck="false"><span class="pstatus" id="p-sku-st" aria-live="polite"></span></div>
							</div>
							<?php if ( $cfg['costs'] ) : // El costo por unidad, solo para quien administra. ?>
							<div class="g2 mt">
								<div class="field"><label for="p-costo"><?php esc_html_e( 'Cost per unit', 'dox-pos' ); ?></label><input id="p-costo" inputmode="numeric" autocomplete="off" placeholder="0"></div>
								<div class="field"><span class="lbl"><?php esc_html_e( 'Profit', 'dox-pos' ); ?></span><p class="margen" id="p-margen"><?php esc_html_e( 'Enter a price and a cost.', 'dox-pos' ); ?></p></div>
							</div>
							<p class="hint"><?php esc_html_e( 'What you paid for each unit, including the supplier\'s shipping if there was any. Only people who administer the store can see it, and the profit is kept with every sale. A product with sizes carries one cost for all of them; if one costs something different, set it in WooCommerce.', 'dox-pos' ); ?></p>
							<?php endif; ?>
						</div>
						<div class="grp" id="g-colores">
							<h4><?php esc_html_e( 'Colors', 'dox-pos' ); ?> <span class="cnt" id="p-ncol"></span></h4>
							<div class="chips" id="p-colores"></div>
							<div class="nuevocolor" id="p-nuevocolor" hidden>
								<label class="swatchpick" title="<?php esc_attr_e( 'Pick the shade', 'dox-pos' ); ?>"><input type="color" id="p-color-hex" value="#F4C1C0" aria-label="<?php esc_attr_e( 'Shade of the new color', 'dox-pos' ); ?>"></label>
								<input id="p-color-nom" placeholder="<?php esc_attr_e( 'Color name', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Name of the new color', 'dox-pos' ); ?>">
								<button type="button" class="mini" id="p-color-add"><?php esc_html_e( 'Add', 'dox-pos' ); ?></button>
							</div>
							<p class="hint"><?php esc_html_e( 'No colors: the product only varies by size.', 'dox-pos' ); ?></p>
						</div>
						<div class="grp" id="g-tallas">
							<h4><?php esc_html_e( 'Sizes', 'dox-pos' ); ?> <span class="cnt" id="p-ntal"></span></h4>
							<div class="chips" id="p-tallas"></div>
							<p class="hint" id="p-tallas-hint"><?php esc_html_e( 'Choose the category and its sizes appear.', 'dox-pos' ); ?></p>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Units', 'dox-pos' ); ?></h4>
							<div class="wrapx qtywrap"><table class="qtyt" id="p-qty"></table></div>
							<div class="field qjunto" id="p-junto-box" hidden><label for="p-junto-n"><?php esc_html_e( 'Shared units', 'dox-pos' ); ?></label><input id="p-junto-n" inputmode="numeric" placeholder="0"></div>
							<p class="hint" id="p-qty-shared" hidden></p>
						</div>
						<div class="grp">
							<h4><?php esc_html_e( 'Description', 'dox-pos' ); ?> <span class="cnt"><?php esc_html_e( 'optional', 'dox-pos' ); ?></span></h4>
							<textarea id="p-desc" aria-label="<?php esc_attr_e( 'Description', 'dox-pos' ); ?>" placeholder="<?php esc_attr_e( 'Fabric, details, how to wash it…', 'dox-pos' ); ?>"></textarea>
						</div>
						<div class="grp">
							<label class="switch"><input type="checkbox" id="p-pub" checked><span class="switch-ui" aria-hidden="true"></span><span><span id="p-pub-text"><?php esc_html_e( 'Publish in the store now', 'dox-pos' ); ?></span><i id="p-pub-hint"><?php esc_html_e( 'Off: it is saved but hidden; you publish it later from Edit one.', 'dox-pos' ); ?></i></span></label>
						</div>
					</div>
				</div>
				<div class="foot" id="p-foot">
					<div class="prodwrap">
						<div class="sum" id="p-sum"></div>
						<button type="button" class="go" id="p-crear"><?php esc_html_e( 'Create product', 'dox-pos' ); ?></button>
					</div>
				</div>
			</div>
		</section>
		<?php endif; ?>
		<?php if ( $cfg['history_full'] ) : ?>
		<!-- ================= PANEL: las cifras del día, como cuadro de mando ================= -->
		<section class="tab" id="t-panel" hidden>
			<div class="scroll"><div class="histwrap pnl" id="panel"></div></div>
		</section>
		<?php endif; ?>

		<!-- ================= HISTORIAL: ventas, la caja del día y los movimientos ================= -->
		<section class="tab" id="t-historial" hidden>
			<div class="histbar">
				<?php if ( $cfg['history_full'] ) : ?>
				<div class="seg" id="h-periodo">
					<button type="button" data-p="hoy" aria-pressed="true"><?php esc_html_e( 'Today', 'dox-pos' ); ?></button>
					<button type="button" data-p="semana" aria-pressed="false"><?php esc_html_e( 'This week', 'dox-pos' ); ?></button>
					<button type="button" data-p="mes" aria-pressed="false"><?php esc_html_e( 'This month', 'dox-pos' ); ?></button>
					<button type="button" data-p="fechas" aria-pressed="false"><?php esc_html_e( 'Dates', 'dox-pos' ); ?></button>
				</div>
				<div class="hfechas" id="h-fechas" hidden><input type="date" id="h-desde" aria-label="<?php esc_attr_e( 'From', 'dox-pos' ); ?>"><span><?php esc_html_e( 'to', 'dox-pos' ); ?></span><input type="date" id="h-hasta" aria-label="<?php esc_attr_e( 'To', 'dox-pos' ); ?>"></div>
				<div class="seg" id="h-vista">
					<button type="button" data-v="ventas" aria-pressed="true"><?php esc_html_e( 'Sales', 'dox-pos' ); ?></button>
					<button type="button" data-v="caja" aria-pressed="false"><?php esc_html_e( 'Daily cash', 'dox-pos' ); ?></button>
					<button type="button" data-v="mov" aria-pressed="false"><?php esc_html_e( 'Movements', 'dox-pos' ); ?></button>
					<?php do_action( 'dox_pos_history_views', $cfg ); // Vistas de los añadidos (el Pro pone Rendimiento): un botón data-v="<vista>". ?>
				</div>
				<?php endif; ?>
			</div>
			<div class="scroll"><div class="histwrap">
				<div class="hfil" id="h-fil" hidden>
					<div class="searchwrap"><input id="h-q" type="search" placeholder="<?php esc_attr_e( 'Product or SKU', 'dox-pos' ); ?>" autocomplete="off" aria-label="<?php esc_attr_e( 'Search the movements', 'dox-pos' ); ?>"></div>
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
