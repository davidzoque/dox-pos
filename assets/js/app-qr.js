/**
 * "Úsala en tu teléfono": todo lo de la app de Dox POS vive en esta ventana, no en los ajustes.
 *
 * - El QR que conecta un teléfono. El código lo crea el servidor (POST /app/code), vale una vez y
 *   caduca a los 10 minutos: la ventana lo renueva sola mientras sigue abierta. Se dibuja aquí mismo
 *   con qrcode-generator; no se manda a ningún sitio.
 * - Quien administra elige de quién es el teléfono ("Conectar el teléfono de…"), para las cajeras.
 * - Los teléfonos conectados, con "Desconectar". Cada uno ve los suyos; quien administra, los de todas.
 *
 * @package DoxPos
 */
(function () {
	"use strict";
	const { __, sprintf } = (window.wp && window.wp.i18n) || { __: (s) => s, sprintf: (s, ...a) => a.reduce((t, v) => t.replace(/%(\d\$)?s/, v), s) };
	const D = window.DoxPOS;
	if (!D) return;
	const { $, esc, api, post, modal, cerrarModal, toast } = D;
	let timer = 0;
	let para = 0; // de quién es el teléfono que se conecta: 0 = quien está en la caja

	function svg(text) {
		const qr = window.qrcode(0, "M");
		qr.addData(text);
		qr.make();
		return qr.createSvgTag({ cellSize: 6, margin: 2, scalable: true, alt: __("QR code to connect the app", "dox-pos") });
	}

	function fecha(ts) {
		if (!ts) return "";
		try {
			return new Date(ts * 1000).toLocaleDateString(document.documentElement.lang || undefined, { day: "numeric", month: "short", year: "numeric" });
		} catch (e) {
			return "";
		}
	}

	async function pintarQR() {
		clearTimeout(timer);
		if (!$("#aq-code")) return; // la ventana ya se cerró
		$("#aq-code").innerHTML = '<p class="mp">' + esc(__("Making the code…", "dox-pos")) + "</p>";
		try {
			const r = await post("app/code", para ? { user: para } : {});
			if (!$("#aq-code")) return;
			$("#aq-code").innerHTML = '<div class="aq-qr">' + svg(r.link) + "</div>";
			$("#aq-quien").textContent = sprintf(__("The code connects the phone as %s, works once and changes every 10 minutes.", "dox-pos"), r.user);
			// Se renueva un poco antes de caducar, para que nunca se escanee uno vencido.
			timer = setTimeout(pintarQR, Math.max(30, r.expires_in - 30) * 1000);
		} catch (e) {
			if ($("#aq-code")) $("#aq-code").innerHTML = '<p class="login-err" role="alert">' + esc(e.message) + "</p>";
		}
	}

	async function pintarTelefonos() {
		const box = $("#aq-tels");
		if (!box) return;
		let r;
		try {
			r = await api("app/devices");
		} catch (e) {
			box.innerHTML = "";
			return;
		}
		if (!$("#aq-tels")) return;
		// El selector, solo para quien administra y si hay alguien más que pueda usar la caja.
		if (r.people && r.people.length > 1 && !$("#aq-para")) {
			const opts = r.people.map((p) => '<option value="' + p.id + '"' + (p.id === r.me ? " selected" : "") + ">" + esc(p.name) + "</option>").join("");
			$("#aq-sel").innerHTML = '<div class="field"><label for="aq-para">' + esc(__("Connect the phone of", "dox-pos")) + '</label><select id="aq-para">' + opts + "</select></div>";
			$("#aq-para").onchange = (ev) => {
				const v = parseInt(ev.target.value, 10);
				para = v === r.me ? 0 : v;
				pintarQR();
			};
		}
		const filas = [];
		(r.users || []).forEach((u) => {
			u.devices.forEach((d) => {
				const quien = u.user === r.me ? "" : esc(u.name) + " · ";
				const usado = d.last_used ? sprintf(__("used %s", "dox-pos"), fecha(d.last_used)) : __("not used yet", "dox-pos");
				filas.push('<li><span><b>' + esc(d.name.replace(/^[^·]*·\s*/, "")) + "</b><small>" + quien + esc(sprintf(__("connected %s", "dox-pos"), fecha(d.created))) + " · " + esc(usado) + '</small></span><button type="button" class="mini" data-u="' + u.user + '" data-id="' + esc(d.uuid) + '">' + esc(__("Disconnect", "dox-pos")) + "</button></li>");
			});
		});
		box.innerHTML = filas.length ? "<h4>" + esc(__("Connected phones", "dox-pos")) + '</h4><ul class="aq-lista">' + filas.join("") + "</ul>" : "";
		box.querySelectorAll("button[data-id]").forEach((b) => {
			b.onclick = async () => {
				b.disabled = true;
				try {
					await api("app/devices/" + b.dataset.u + "/" + b.dataset.id, { method: "DELETE" });
					toast(__("The phone was disconnected.", "dox-pos"));
					pintarTelefonos();
				} catch (e) {
					b.disabled = false;
					toast(e.message);
				}
			};
		});
	}

	function abrir() {
		para = 0;
		modal(
			"<h3>" + esc(__("Use it on your phone", "dox-pos")) + "</h3>" +
			'<ol class="aq-pasos">' +
			"<li>" + esc(__("Install the Dox POS app from the App Store or Google Play.", "dox-pos")) + "</li>" +
			"<li>" + esc(__("Open it and tap Scan the code.", "dox-pos")) + "</li>" +
			"<li>" + esc(__("Point the camera at this code.", "dox-pos")) + "</li>" +
			"</ol>" +
			'<div id="aq-sel"></div>' +
			'<div id="aq-code" class="aq-code"></div>' +
			'<p class="mp" id="aq-quien"></p>' +
			'<p class="mp">' + esc(sprintf(__("Already on the phone? Open the app and type the address of your shop: %s", "dox-pos"), location.host)) + "</p>" +
			'<div id="aq-tels" class="aq-tels"></div>' +
			'<div class="mbtn"><button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>",
			"aq"
		);
		$("#m-no").onclick = cerrarModal;
		pintarQR();
		pintarTelefonos();
	}

	const b = $("#app-qr");
	if (b) b.addEventListener("click", abrir);
})();
