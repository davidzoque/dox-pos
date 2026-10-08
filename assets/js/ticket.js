/* Dox POS: el ticket del Mostrador. Lo dibuja para imprimir (mostrador.js) y para la vista previa del
   editor en Ajustes (ticket-ajustes.js), así lo que se ve al editarlo es lo que sale en el papel.
   window.DoxPOSTicket({ dinero }) devuelve { venta, cierre, documento, codigo }.
   El encabezado, el pie y lo que se enseña salen de los ajustes de la tienda (includes/counter.php,
   dox_pos_counter_receipt_settings), que el Pro puede ampliar con el filtro del mismo nombre. */
(function () {
	"use strict";

	const i18n = (window.wp && window.wp.i18n) || {};
	const __ = i18n.__ || ((s) => s);
	const _n = i18n._n || ((s, p, n) => (n === 1 ? s : p));
	const sprintf = i18n.sprintf || ((s) => s);
	const esc = (s) => String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/"/g, "&quot;");

	// Code 128, juego B: los anchos de barra y espacio de cada símbolo (el último es el de parada).
	const C128 = "212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 211214 211232 2331112".split(" ");
	function codigo(txt) {
		const vals = [104];
		for (const ch of String(txt)) { const c = ch.charCodeAt(0) - 32; if (c >= 0 && c <= 94) vals.push(c); }
		let suma = 104;
		for (let i = 1; i < vals.length; i++) suma += vals[i] * i;
		vals.push(suma % 103, 106);
		let x = 10, barras = "";
		vals.forEach((v) => {
			const p = C128[v];
			for (let i = 0; i < p.length; i++) { const w = +p[i]; if (i % 2 === 0) barras += '<rect x="' + x + '" y="0" width="' + w + '" height="40"/>'; x += w; }
		});
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + (x + 10) + ' 40" preserveAspectRatio="none" shape-rendering="crispEdges">' + barras + "</svg>";
	}

	window.DoxPOSTicket = function (fmt) {
		const dinero = (fmt && fmt.dinero) || ((n) => String(n));
		const ver = (s, k) => !s.show || s.show[k] !== false; // Sin el ajuste, todo se enseña.
		const fila = (a, b, cls) => '<div class="r' + (cls ? " " + cls : "") + '"><span>' + a + "</span><span>" + b + "</span></div>";
		function cabecera(s) {
			return '<div class="c">' + (s.logo ? '<img class="logo" src="' + esc(s.logo) + '" alt="">' : "") + '<div class="name">' + esc(s.name || "") + "</div>" + (s.header ? '<div class="pre">' + esc(s.header) + "</div>" : "") + "</div><hr>";
		}
		// Lo que añade el editor del Pro (si lo hay en los ajustes): el cupón para la próxima compra, solo
		// en ventas que llegan al mínimo, y un código QR con su texto (window.DoxPOSQr lo dibuja).
		function extras(s, r, dev) {
			let h = "";
			const c = s.coupon;
			if (c && c.code && !dev && (+r.total || 0) >= (+c.min || 0)) h += '<div class="cupon">' + (c.text ? '<div class="pre">' + esc(c.text) + "</div>" : "") + '<div class="cod">' + esc(c.code) + "</div></div>";
			if (s.qr && typeof window.DoxPOSQr === "function") {
				const svg = window.DoxPOSQr(s.qr);
				if (svg) h += (s.qr_label ? '<div class="c pre qrt">' + esc(s.qr_label) + "</div>" : "") + '<div class="qr">' + svg + "</div>";
			}
			return h;
		}
		// El ticket de una venta, o el comprobante de una devolución (dev: {amount, method, lines}).
		function venta(r, dev) {
			const s = r.store || {};
			let h = cabecera(s);
			h += fila(esc(sprintf(__("Order #%s", "dox-pos"), r.number)), "") + '<div class="s0">' + esc(r.date) + "</div>";
			if (r.seller && ver(s, "seller")) h += '<div class="s0">' + esc(sprintf(__("Served by %s", "dox-pos"), r.seller)) + "</div>";
			if (r.customer && ver(s, "customer")) h += '<div class="s0">' + esc(sprintf(__("Customer: %s", "dox-pos"), r.customer)) + "</div>";
			h += "<hr>";
			if (dev) {
				h += '<div class="c big">' + esc(__("RETURN", "dox-pos")) + "</div><hr>";
				dev.lines.forEach((l) => { h += fila(esc(l.name), "") + '<div class="s">' + esc(sprintf(__("%d returned", "dox-pos"), l.qty)) + "</div>"; });
				h += "<hr>" + fila(esc(__("RETURNED", "dox-pos")), dinero(dev.amount), "big") + '<div class="s0">' + esc(dev.method === "efectivo" ? __("In cash", "dox-pos") : sprintf(__("By the same payment method (%s)", "dox-pos"), (r.payments || []).map((p) => p.title).join(" + "))) + "</div>";
			} else {
				r.lines.forEach((l) => { h += fila(esc(l.name), dinero(l.total)) + '<div class="s">' + esc(l.qty + " x " + dinero(l.unit) + (l.sku && ver(s, "sku") ? " · " + l.sku : "")) + "</div>"; });
				h += "<hr>" + fila(esc(__("Subtotal", "dox-pos")), dinero(r.subtotal));
				if (r.discount) h += fila(esc(__("Discount", "dox-pos")), "−" + dinero(r.discount));
				const tax = (r.taxes || []).reduce((a, t) => a + t.amount, 0);
				if (!r.included && tax) {
					if (ver(s, "taxes")) (r.taxes || []).forEach((t) => { h += fila(esc(t.label), dinero(t.amount)); });
					else h += fila(esc(__("Tax", "dox-pos")), dinero(tax)); // Sin desglose: una sola línea.
				}
				h += fila(esc(__("TOTAL", "dox-pos")), dinero(r.total), "big");
				if (r.included && tax && ver(s, "taxes")) h += '<div class="s0">' + esc(sprintf(__("Includes %s", "dox-pos"), r.taxes.map((t) => t.label + " " + dinero(t.amount)).join(", "))) + "</div>";
				h += "<hr>";
				(r.payments || []).forEach((p) => { h += fila(esc(p.title), dinero(p.amount)); });
				if (r.tendered != null) h += fila(esc(__("Cash received", "dox-pos")), dinero(r.tendered)) + fila(esc(__("Change due", "dox-pos")), dinero(r.change || 0));
				if (r.refunded) h += fila(esc(__("Returned", "dox-pos")), "−" + dinero(r.refunded));
			}
			h += "<hr>" + (s.footer ? '<div class="c pre">' + esc(s.footer) + "</div>" : "");
			h += extras(s, r, dev);
			if (ver(s, "barcode")) h += '<div class="code">' + codigo(r.code) + '</div><div class="c s0">' + esc(r.code) + "</div>";
			return h;
		}
		// El cierre de caja (z: el turno cerrado, s: la tienda).
		function cierre(z, s) {
			const r = z.summary || {};
			let h = cabecera(s) + '<div class="c big">' + esc(__("TILL CLOSING", "dox-pos")) + "</div>" + (z.register_name ? '<div class="c">' + esc(z.register_name) + "</div>" : "") + "<hr>";
			h += '<div class="s0">' + esc(sprintf(__("Opened: %1$s %2$s by %3$s", "dox-pos"), z.opened_day, z.opened_at, z.opened_name)) + "</div>";
			h += '<div class="s0">' + esc(sprintf(__("Closed: %1$s %2$s by %3$s", "dox-pos"), z.closed_day, z.closed_at, z.closed_name)) + "</div><hr>";
			h += fila(esc(sprintf(_n("%d sale", "%d sales", r.orders || 0, "dox-pos"), r.orders || 0)), dinero(r.total || 0), "big");
			(r.payments || []).forEach((p) => { h += fila(esc(p.title), dinero(p.amount)); });
			if (r.refund_cash || r.refund_other) h += fila(esc(__("Returns", "dox-pos")), "−" + dinero((r.refund_cash || 0) + (r.refund_other || 0)));
			h += "<hr>" + fila(esc(__("Float", "dox-pos")), dinero(r.float || 0)) + fila(esc(__("Cash sales", "dox-pos")), dinero(r.cash_sales || 0));
			if (r.refund_cash) h += fila(esc(__("Cash given back", "dox-pos")), "−" + dinero(r.refund_cash));
			(r.extra_cash || []).forEach((x) => { if (x.amount) h += fila(esc(x.label), (x.amount < 0 ? "−" : "") + dinero(Math.abs(x.amount))); }); // Entradas y salidas de dinero (Pro).
			h += fila(esc(__("Expected", "dox-pos")), dinero(r.expected || 0), "big") + fila(esc(__("Counted", "dox-pos")), dinero(z.counted || 0), "big");
			const d = z.difference || 0;
			h += fila(esc(d === 0 ? __("It matches", "dox-pos") : d > 0 ? __("Over", "dox-pos") : __("Short", "dox-pos")), dinero(Math.abs(d)), "big");
			if (z.note) h += '<div class="pre">' + esc(z.note) + "</div>";
			return h;
		}
		// Las plantillas del editor del Pro: cambian la letra y las rayas sobre la base (la clásica).
		const PLANTILLAS = {
			modern: "body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;line-height:1.45}hr{border-top:1.5px solid #000}.name{font-size:1.6em;letter-spacing:-.01em}.big{font-size:1.3em}.s{opacity:.7}",
			compact: "body{line-height:1.2;padding-top:1mm;padding-bottom:4mm}hr{margin:3px 0}.name{font-size:1.15em}.logo{max-height:14mm}.code{height:9mm;margin-top:4px}",
		};
		// La página completa, con el ancho del rollo y el tamaño de letra de los ajustes.
		function documento(cuerpo, s) {
			s = s || {};
			const papel = +s.width === 58 ? 58 : 80, util = papel === 58 ? 48 : 72, lado = (papel - util) / 2;
			const base = { small: 9.5, normal: 11, large: 13 }[s.size] || 11;
			const letra = papel === 58 ? base - 1 : base;
			return '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(__("Receipt", "dox-pos")) + "</title><style>" +
				"@page{size:" + papel + "mm auto;margin:0}html,body{margin:0;padding:0;background:#fff;color:#000}" +
				"body{width:" + util + "mm;padding:3mm " + lado + "mm 8mm;font:" + letra + "px/1.4 ui-monospace,Menlo,Consolas,'Courier New',monospace;-webkit-print-color-adjust:exact;print-color-adjust:exact}" +
				".c{text-align:center}.name{font-weight:700;font-size:1.35em;margin:2px 0}.pre{white-space:pre-line}.logo{display:block;margin:0 auto 4px;max-width:60%;max-height:22mm;filter:grayscale(1) contrast(1.4)}" +
				"hr{border:0;border-top:1px dashed #000;margin:6px 0}.r{display:flex;justify-content:space-between;gap:8px}.r span:last-child{white-space:nowrap}" +
				".s{padding-left:8px;opacity:.8}.s0{opacity:.85}.big{font-weight:700;font-size:1.15em}.code{margin:8px auto 2px;height:12mm}.code svg{width:100%;height:100%}" +
				".cupon{border:1.5px dashed #000;border-radius:3mm;padding:2mm;margin:8px 0;text-align:center}.cupon .cod{font-weight:700;font-size:1.3em;letter-spacing:.08em;margin-top:2px}.qrt{margin-top:8px}.qr{width:26mm;height:26mm;margin:4px auto}.qr svg{width:100%;height:100%;display:block}" +
				(PLANTILLAS[s.template] || "") +
				"</style></head><body>" + cuerpo + "</body></html>";
		}
		return { venta: venta, cierre: cierre, documento: documento, codigo: codigo };
	};
})();
