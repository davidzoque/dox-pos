/* Dox POS: la vista previa del ticket en Ajustes > Ventas (el editor básico). Dibuja una venta de
   ejemplo con ticket.js, el mismo que imprime el Mostrador, y la repinta con cada cambio. */
(function () {
	"use strict";
	const cfg = window.DOX_POS_TICKET || {};
	const frame = document.getElementById("dp-ticket-preview");
	const form = document.getElementById("dp-form");
	if (!frame || !form || !window.DoxPOSTicket) return;

	// Un importe con el formato de la tienda, como en la caja.
	const M = Object.assign({ symbol: "$", pos: "left", thousand: ",", decimal: ".", decimals: 2 }, cfg.money || {});
	function dinero(n) {
		const v = Number(n) || 0;
		const parts = Math.abs(v).toFixed(M.decimals).split(".");
		let s = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, M.thousand);
		if (parts[1]) s += M.decimal + parts[1];
		const con = { left: M.symbol + s, right: s + M.symbol, left_space: M.symbol + " " + s, right_space: s + " " + M.symbol };
		return (v < 0 ? "−" : "") + (con[M.pos] || con.left);
	}
	const T = window.DoxPOSTicket({ dinero: dinero });
	const campo = (n) => form.querySelector('[name="dox_pos_sales[' + n + ']"]');
	function tienda() {
		const show = {};
		form.querySelectorAll('[name^="dox_pos_sales[receipt_show]"]').forEach((c) => { show[c.name.replace(/^.*\[(\w+)\]$/, "$1")] = c.checked; });
		const logo = campo("receipt_logo");
		return {
			name: cfg.name || "",
			logo: logo && logo.checked ? cfg.logo || "" : "",
			header: campo("receipt_header") ? campo("receipt_header").value : "",
			footer: campo("receipt_footer") ? campo("receipt_footer").value : "",
			width: campo("receipt_width") ? +campo("receipt_width").value : 80,
			size: campo("receipt_size") ? campo("receipt_size").value : "normal",
			show: show,
		};
	}
	function pintar() {
		const s = tienda();
		const r = Object.assign({}, cfg.sample || {}, { store: s });
		frame.srcdoc = T.documento(T.venta(r), s);
		frame.style.width = (s.width === 58 ? 220 : 302) + "px"; // El rollo a tamaño de pantalla (96 ppp).
	}
	// Alto según lo que mide el ticket, sin barra de desplazamiento. Se mide otra vez un momento después:
	// al cambiar el ancho o la letra, el primer cálculo puede salir con el tamaño anterior.
	function medir() {
		try {
			const d = frame.contentDocument;
			frame.style.height = Math.ceil(d.body.getBoundingClientRect().height) + 2 + "px"; // Lo que mide el papel, no el marco: así también encoge.
		} catch (e) { /* nada */ }
	}
	frame.addEventListener("load", () => { medir(); setTimeout(medir, 80); const img = frame.contentDocument && frame.contentDocument.querySelector("img"); if (img) img.addEventListener("load", medir); });
	form.addEventListener("input", (e) => { if (/receipt_/.test(e.target.name || "")) pintar(); });
	form.addEventListener("change", (e) => { if (/receipt_/.test(e.target.name || "")) pintar(); });
	pintar();
})();
