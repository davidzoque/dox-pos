/**
 * "Úsala en tu teléfono": el QR que conecta la app de Dox POS con esta tienda.
 *
 * El botón del teléfono de la cabecera abre una ventana con los pasos y el QR. El código lo crea el
 * servidor (POST /app/code), vale una vez y caduca a los 10 minutos: la ventana lo renueva sola
 * mientras sigue abierta. El QR se dibuja aquí mismo con qrcode-generator; no se manda a ningún sitio.
 *
 * @package DoxPos
 */
(function () {
	"use strict";
	const { __, sprintf } = (window.wp && window.wp.i18n) || { __: (s) => s, sprintf: (s, ...a) => a.reduce((t, v) => t.replace(/%(\d\$)?s/, v), s) };
	const D = window.DoxPOS;
	if (!D) return;
	const { $, esc, post, modal, cerrarModal, cfg } = D;
	let timer = 0;

	function svg(text) {
		const qr = window.qrcode(0, "M");
		qr.addData(text);
		qr.make();
		return qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true, alt: __("QR code to connect the app", "dox-pos") });
	}

	async function pintar() {
		clearTimeout(timer);
		const caja = $("#aq-code");
		if (!caja) return; // la ventana ya se cerró
		caja.innerHTML = '<p class="mp">' + esc(__("Making the code…", "dox-pos")) + "</p>";
		try {
			const r = await post("app/code");
			if (!$("#aq-code")) return;
			$("#aq-code").innerHTML = '<div class="aq-qr">' + svg(r.link) + "</div>";
			// Se renueva un poco antes de caducar, para que nunca se escanee uno vencido.
			timer = setTimeout(pintar, Math.max(30, r.expires_in - 30) * 1000);
		} catch (e) {
			if ($("#aq-code")) $("#aq-code").innerHTML = '<p class="login-err" role="alert">' + esc(e.message) + "</p>";
		}
	}

	function abrir() {
		const host = location.host;
		modal(
			"<h3>" + esc(__("Use it on your phone", "dox-pos")) + "</h3>" +
			'<ol class="aq-pasos">' +
			"<li>" + esc(__("Install the Dox POS app from the App Store or Google Play.", "dox-pos")) + "</li>" +
			"<li>" + esc(__("Open it and tap Scan the code.", "dox-pos")) + "</li>" +
			"<li>" + esc(__("Point the camera at this code.", "dox-pos")) + "</li>" +
			"</ol>" +
			'<div id="aq-code" class="aq-code"></div>' +
			'<p class="mp">' + esc(sprintf(__("The code connects the phone as %s, works once and changes every 10 minutes.", "dox-pos"), cfg.user)) + "</p>" +
			'<p class="mp">' + esc(sprintf(__("Already on the phone? Open the app and type the address of your shop: %s", "dox-pos"), host)) + "</p>" +
			'<div class="mbtn"><button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>",
			"aq"
		);
		$("#m-no").onclick = cerrarModal;
		pintar();
	}

	const b = $("#app-qr");
	if (b) b.addEventListener("click", abrir);
})();
