/* Dox POS: la página de ajustes. Pestañas, vista previa en vivo, comprobación de la ruta
   mientras se escribe, cambios sin guardar y avisos. Sin jQuery: solo wp.media para el logo. */
(function () {
	"use strict";
	const cfg = window.DOX_POS_AJUSTES || {};
	const i18n = cfg.i18n || {};
	const defaults = cfg.defaults || {};
	const app = document.getElementById("dp-app");
	const form = document.getElementById("dp-form");
	const prev = document.getElementById("dp-preview");
	if (!app || !form || !prev) return;
	const $ = (s, el) => (el || app).querySelector(s);
	const $$ = (s, el) => Array.from((el || app).querySelectorAll(s));

	const ICON = {
		check: '<path d="M20 6 9 17l-5-5"/>',
		x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
		alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
	};
	const icon = (n) => '<svg class="dp-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICON[n] + "</svg>";

	// ---------- la cabecera mide para que la vista previa se pegue debajo ----------
	const head = $(".dp-head");
	function medir() { app.style.setProperty("--dp-head-h", head.offsetHeight + "px"); }

	// ---------- pestañas ----------
	const tabs = $$(".dp-tab-btn");
	const panels = $$(".dp-panel");
	const ind = $(".dp-ind");
	// Qué vista previa enseña cada pestaña: lo dice el botón (data-view), así los añadidos traen la suya.
	const views = {};
	tabs.forEach((b) => { views[b.dataset.tab] = b.dataset.view || "caja"; });
	let current = "";
	function indicador() {
		const b = tabs.find((t) => t.dataset.tab === current);
		if (!b || !ind) return;
		ind.style.width = b.offsetWidth + "px";
		ind.style.transform = "translateX(" + b.offsetLeft + "px)";
	}
	function abrir(id, foco) {
		if (!views[id]) id = "marca";
		if (id === current) return;
		current = id;
		tabs.forEach((b) => {
			const on = b.dataset.tab === id;
			b.setAttribute("aria-selected", on ? "true" : "false");
			b.tabIndex = on ? 0 : -1;
			if (on && foco) b.focus();
		});
		panels.forEach((p) => { p.hidden = p.dataset.panel !== id; });
		$$(".dp-mock").forEach((m) => { m.hidden = m.dataset.view !== views[id]; });
		indicador();
		// Al guardar, WordPress vuelve a la dirección del campo _wp_http_referer: que traiga la pestaña.
		const u = new URL(location.href);
		u.searchParams.set("tab", id);
		u.searchParams.delete("settings-updated");
		const ref = form.querySelector('input[name="_wp_http_referer"]');
		if (ref) ref.value = u.pathname + u.search;
		history.replaceState(null, "", u);
	}
	tabs.forEach((b) => {
		b.addEventListener("click", () => abrir(b.dataset.tab, false));
		b.addEventListener("keydown", (e) => {
			const i = tabs.indexOf(b);
			let j = -1;
			if (e.key === "ArrowRight") j = (i + 1) % tabs.length;
			if (e.key === "ArrowLeft") j = (i - 1 + tabs.length) % tabs.length;
			if (e.key === "Home") j = 0;
			if (e.key === "End") j = tabs.length - 1;
			if (j >= 0) { e.preventDefault(); abrir(tabs[j].dataset.tab, true); }
		});
	});
	ind.classList.add("no-anim");
	abrir(new URL(location.href).searchParams.get("tab") || location.hash.replace("#", "") || "marca", false);
	medir();
	requestAnimationFrame(() => requestAnimationFrame(() => ind.classList.remove("no-anim")));
	addEventListener("resize", () => { medir(); indicador(); });
	if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { medir(); indicador(); });

	// ---------- colores: las mismas reglas que dox_pos_theme_css() en PHP ----------
	function hex(v) {
		v = String(v || "").trim().toUpperCase();
		let m = v.match(/^#?([0-9A-F]{6})$/);
		if (m) return "#" + m[1];
		m = v.match(/^#?([0-9A-F])([0-9A-F])([0-9A-F])$/);
		return m ? "#" + m[1] + m[1] + m[2] + m[2] + m[3] + m[3] : "";
	}
	const rgb = (h) => [1, 3, 5].map((i) => parseInt(h.substr(i, 2), 16));
	function mix(a, b, t) {
		const A = rgb(a), B = rgb(b);
		return "#" + A.map((c, i) => Math.round(c * (1 - t) + B[i] * t).toString(16).padStart(2, "0")).join("").toUpperCase();
	}
	function isDark(h) {
		const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
		const [r, g, b] = rgb(h);
		return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b) < 0.25;
	}
	const swatches = $$(".dp-swatch");
	swatches.forEach((t) => { t.dataset.last = hex($(".dp-hex", t).value) || t.dataset.default; });
	function pintarColores() {
		const c = {};
		swatches.forEach((t) => {
			const h = hex($(".dp-hex", t).value);
			if (h) t.dataset.last = h; // mientras se escribe un hex a medias, se queda el último válido
			c[t.dataset.key] = t.dataset.last;
			t.style.setProperty("--sw", c[t.dataset.key]);
			$('input[type="color"]', t).value = c[t.dataset.key];
		});
		const darkBar = isDark(c.bar), darkPrim = isDark(c.primary), darkSoft = isDark(c.soft);
		const vars = {
			"--bg": c.bg, "--bar": c.bar, "--primary": c.primary, "--soft": c.soft, "--ink": c.ink,
			"--primary-ink": darkPrim ? "#FFFFFF" : c.ink,
			"--bar-ink": darkBar ? "#FFFFFF" : c.primary,
			"--soft-accent": darkSoft ? "#FFFFFF" : c.primary,
			"--tab-on": darkBar ? "#FFFFFF" : c.primary,
			"--tab-on-ink": darkBar ? c.bar : (darkPrim ? "#FFFFFF" : c.ink),
			"--sunk": mix(c.bg, c.ink, 0.03),
			"--line": mix(c.bg, c.ink, 0.12),
			"--muted": mix(c.ink, c.bg, 0.12),
		};
		Object.keys(vars).forEach((k) => prev.style.setProperty(k, vars[k]));
		const tile = $("#dp-logo-tile");
		if (tile) { tile.style.setProperty("--tile", c.bar); tile.style.color = darkBar ? "#fff" : "rgba(0,0,0,.6)"; }
		const reset = $("#dp-colors-reset");
		if (reset) reset.disabled = !swatches.some((t) => c[t.dataset.key] !== t.dataset.default);
	}
	swatches.forEach((t) => {
		const color = $('input[type="color"]', t), text = $(".dp-hex", t);
		color.addEventListener("input", () => { text.value = color.value.toUpperCase(); pintarColores(); sucio(); });
		text.addEventListener("blur", () => { text.value = t.dataset.last; });
	});
	const reset = $("#dp-colors-reset");
	if (reset) reset.addEventListener("click", () => {
		swatches.forEach((t) => { $(".dp-hex", t).value = t.dataset.default; });
		pintarColores();
		sucio();
	});

	// ---------- fuentes: se cargan de Google al momento para verlas en la vista previa ----------
	const cargadas = {};
	function cargarFuente(fam, cb) {
		if (cargadas[fam]) return cb(cargadas[fam] === "ok");
		const l = document.createElement("link");
		l.rel = "stylesheet";
		l.href = "https://fonts.googleapis.com/css2?family=" + encodeURIComponent(fam).replace(/%20/g, "+") + ":wght@400;500;600;700&display=swap";
		l.onload = () => { cargadas[fam] = "ok"; cb(true); };
		l.onerror = () => { cargadas[fam] = "no"; l.remove(); cb(false); };
		document.head.appendChild(l);
	}
	[["ui", "#dp-font-ui", "sans-serif"], ["serif", "#dp-font-serif", "serif"]].forEach(([k, sel, fallback]) => {
		const el = $(sel);
		if (!el) return;
		const estado = el.closest(".dp-field").querySelector(".dp-status");
		let t;
		el.addEventListener("input", () => {
			clearTimeout(t);
			estado.textContent = "";
			estado.className = "dp-status";
			el.closest(".dp-field").classList.remove("is-invalid");
			t = setTimeout(() => {
				const fam = el.value.trim().replace(/\s+/g, " ") || (defaults.fonts || {})[k] || el.placeholder;
				cargarFuente(fam, (ok) => {
					if (fam !== (el.value.trim().replace(/\s+/g, " ") || (defaults.fonts || {})[k] || el.placeholder)) return;
					if (ok) {
						prev.style.setProperty("--" + k, '"' + fam + '",' + fallback);
						if (el.value.trim()) { estado.textContent = i18n.fontOk || ""; estado.classList.add("ok"); }
					} else {
						estado.textContent = i18n.fontBad || "";
						estado.classList.add("bad");
					}
				});
			}, 500);
		});
	});

	// ---------- nombre, pantalla, logo y dirección ----------
	const logoInput = $("#dox_pos_logo");
	function limpiarSlug(v) {
		return String(v || "").toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9-]+/g, "-").replace(/-{2,}/g, "-");
	}
	function pintarTextos() {
		const url = (logoInput.value || "").trim() || cfg.siteLogo || "";
		[[$("#dp-preview-logo"), $("#dp-preview-brand")], [$("#dp-logo-img"), $("#dp-logo-empty")]].forEach(([img, alt]) => {
			if (!img || !alt) return;
			if (url) { if (img.getAttribute("src") !== url) img.src = url; img.hidden = false; alt.hidden = true; }
			else { img.hidden = true; alt.hidden = false; }
		});
		$("#dp-preview-brand").textContent = $("#dp-name").value.trim() || cfg.siteName || "";
		$("#dp-preview-screen").textContent = $("#dp-screen").value.trim() || defaults.screen || "";
		const s = limpiarSlug($("#dp-slug").value).replace(/^-|-$/g, "") || defaults.slug || "";
		$("#dp-preview-url").textContent = (cfg.homeHost || "") + "/" + s;
	}
	$("#dp-logo-pick").addEventListener("click", () => {
		if (!window.wp || !wp.media) return;
		const frame = wp.media({ title: i18n.pickLogo || "", button: { text: i18n.use || "OK" }, multiple: false, library: { type: "image" } });
		frame.on("select", () => {
			const a = frame.state().get("selection").first().toJSON();
			logoInput.value = a.url;
			pintarTextos();
			sucio();
		});
		frame.open();
	});
	$("#dp-logo-clear").addEventListener("click", () => { logoInput.value = ""; pintarTextos(); sucio(); });

	// La dirección se comprueba en vivo: ¿la usa otra página?
	const slug = $("#dp-slug"), slugEstado = $("#dp-slug-status");
	let st;
	function comprobarSlug() {
		const v = limpiarSlug(slug.value).replace(/^-|-$/g, "");
		slugEstado.className = "dp-status";
		slugEstado.textContent = "";
		slug.closest(".dp-field").classList.remove("is-invalid");
		clearTimeout(st);
		if (!v) return;
		if (v === cfg.slug) { slugEstado.textContent = i18n.slugSame || ""; slugEstado.classList.add("wait"); return; }
		slugEstado.textContent = i18n.checking || "";
		slugEstado.classList.add("wait");
		st = setTimeout(async () => {
			try {
				const r = await fetch(cfg.rest + "settings/slug?slug=" + encodeURIComponent(v), { headers: { "X-WP-Nonce": cfg.nonce }, credentials: "same-origin" });
				const j = await r.json();
				if (limpiarSlug(slug.value).replace(/^-|-$/g, "") !== v) return; // ya escribió otra cosa
				slugEstado.className = "dp-status " + (j.ok ? "ok" : "bad");
				slugEstado.textContent = j.ok ? (i18n.slugOk || "") : (j.message || "");
			} catch (e) {
				slugEstado.className = "dp-status";
				slugEstado.textContent = "";
			}
		}, 350);
	}
	slug.addEventListener("input", () => {
		const pos = slug.selectionStart;
		const limpio = limpiarSlug(slug.value);
		if (limpio !== slug.value) { slug.value = limpio; slug.setSelectionRange(pos, pos); }
		comprobarSlug();
	});
	slug.addEventListener("blur", () => { slug.value = limpiarSlug(slug.value).replace(/^-|-$/g, ""); pintarTextos(); comprobarSlug(); });

	// ---------- canales: añadir, quitar y ordenar ----------
	const lista = $("#dp-canales");
	const plantilla = $(".dp-row", lista).cloneNode(true);
	let n = lista.children.length; // los índices nuevos siguen contando aunque se quiten filas
	function pintarCanales() {
		const box = $("#dp-preview-channels");
		const names = $$(".dp-row .dp-input", lista).map((i) => i.value.trim()).filter(Boolean);
		box.innerHTML = "";
		names.slice(0, 4).forEach((name, i) => {
			const s = document.createElement("span");
			if (!i) s.className = "on";
			s.textContent = name;
			box.appendChild(s);
		});
	}
	$("#dp-canal-add").addEventListener("click", () => {
		const d = plantilla.cloneNode(true);
		d.classList.add("is-new");
		$$("input", d).forEach((i) => {
			i.name = i.name.replace(/\[channels\]\[\d+\]/, "[channels][" + n + "]");
			if (i.type === "checkbox") i.checked = false; else i.value = "";
		});
		n++;
		lista.appendChild(d);
		$(".dp-input", d).focus();
		sucio();
	});
	lista.addEventListener("click", (e) => {
		const b = e.target.closest(".dp-quitar");
		if (!b) return;
		const row = b.closest(".dp-row");
		row.classList.add("out");
		setTimeout(() => { row.remove(); pintarCanales(); sucio(); }, 140);
	});
	// Arrastrar por el asa: la fila cambia de sitio al cruzar la mitad de la vecina.
	let drag = null;
	lista.addEventListener("pointerdown", (e) => {
		const grip = e.target.closest(".dp-grip");
		if (!grip || e.button !== 0) return;
		drag = { row: grip.closest(".dp-row"), grip };
		drag.row.classList.add("is-dragging");
		grip.setPointerCapture(e.pointerId);
		e.preventDefault();
	});
	lista.addEventListener("pointermove", (e) => {
		if (!drag) return;
		const rows = $$(".dp-row", lista);
		const idxD = rows.indexOf(drag.row);
		for (const r of rows) {
			if (r === drag.row) continue;
			const b = r.getBoundingClientRect();
			const idxR = rows.indexOf(r);
			if (idxR < idxD && e.clientY < b.top + b.height / 2) { lista.insertBefore(drag.row, r); break; }
			if (idxR > idxD && e.clientY > b.top + b.height / 2) { lista.insertBefore(drag.row, r.nextSibling); break; }
		}
	});
	function soltar() {
		if (!drag) return;
		drag.row.classList.remove("is-dragging");
		drag = null;
		pintarCanales();
		sucio();
	}
	lista.addEventListener("pointerup", soltar);
	lista.addEventListener("pointercancel", soltar);
	// Y con el teclado, desde el asa: flecha arriba o abajo.
	lista.addEventListener("keydown", (e) => {
		const grip = e.target.closest(".dp-grip");
		if (!grip) return;
		const row = grip.closest(".dp-row");
		if (e.key === "ArrowUp" && row.previousElementSibling) { e.preventDefault(); lista.insertBefore(row, row.previousElementSibling); }
		else if (e.key === "ArrowDown" && row.nextElementSibling) { e.preventDefault(); lista.insertBefore(row.nextElementSibling, row); }
		else return;
		grip.focus();
		pintarCanales();
		sucio();
	});

	// ---------- formas de pago ----------
	const pagos = $("#dp-pagos");
	function pintarPagos() {
		const box = $("#dp-preview-payments");
		box.innerHTML = "";
		$$(".dp-pay", pagos).forEach((row) => {
			if (!$('input[type="checkbox"]', row).checked) return;
			const s = document.createElement("span");
			const input = $(".dp-input", row);
			s.textContent = input.value.trim() || input.placeholder;
			if ($('input[type="radio"]', row).checked) s.className = "on";
			box.appendChild(s);
		});
	}
	pagos.addEventListener("change", (e) => {
		const sw = e.target.closest('input[type="checkbox"]');
		if (!sw) return;
		const row = sw.closest(".dp-pay");
		const radio = $('input[type="radio"]', row);
		row.classList.toggle("off", !sw.checked);
		radio.disabled = !sw.checked;
		if (!sw.checked && radio.checked) {
			radio.checked = false;
			const primera = $$('.dp-pay input[type="checkbox"]', pagos).find((c) => c.checked);
			if (primera) $('input[type="radio"]', primera.closest(".dp-pay")).checked = true;
		}
	});

	// ---------- apartados: horas, mensaje y nota ----------
	const mensaje = $("#dox_pos_hold_message"), nota = $("#dox_pos_payment_note"), horas = $("#dox_pos_hold_hours");
	function linkear(text) {
		const frag = document.createDocumentFragment();
		const re = /https?:\/\/[^\s]+/g;
		let last = 0, m;
		while ((m = re.exec(text))) {
			frag.appendChild(document.createTextNode(text.slice(last, m.index)));
			const a = document.createElement("a");
			a.textContent = m[0];
			a.href = "#";
			a.tabIndex = -1;
			a.addEventListener("click", (e) => e.preventDefault());
			frag.appendChild(a);
			last = m.index + m[0].length;
		}
		frag.appendChild(document.createTextNode(text.slice(last)));
		return frag;
	}
	function pintarMensaje() {
		const s = cfg.sample || {};
		const t = mensaje.value.trim() || defaults.message || "";
		let out = t
			.replace(/\{nombre\}/g, s.name || "")
			.replace(/\{productos\}/g, s.products || "")
			.replace(/\{total\}/g, s.total || "")
			.replace(/\{horas\}/g, String(parseInt(horas.value, 10) > 0 ? parseInt(horas.value, 10) : defaults.hours || 48))
			.replace(/\{link\}/g, s.link || "")
			.replace(/\{tienda\}/g, $("#dp-name").value.trim() || cfg.siteName || "");
		out = out.replace(/[ \t]+([,.!?])/g, "$1").replace(/[ \t]{2,}/g, " ");
		const extra = nota.value.trim();
		if (extra) out += "\n" + extra;
		const p = $("#dp-preview-message");
		p.textContent = "";
		p.appendChild(linkear(out.trim()));
	}
	$$("#dp-placeholders .dp-chip").forEach((b) => b.addEventListener("click", () => {
		const ini = mensaje.selectionStart, fin = mensaje.selectionEnd, ins = b.dataset.insert;
		mensaje.value = mensaje.value.slice(0, ini) + ins + mensaje.value.slice(fin);
		mensaje.focus();
		mensaje.setSelectionRange(ini + ins.length, ini + ins.length);
		pintarMensaje();
		sucio();
	}));
	$("#dp-message-reset").addEventListener("click", () => { mensaje.value = defaults.message || ""; pintarMensaje(); sucio(); });

	// ---------- transportadoras: nombre y enlace de rastreo ----------
	const carriersBox = $("#dp-carriers"), carrierPreset = $("#dp-carrier-preset"), carrierAdd = $("#dp-carrier-add");
	const presets = cfg.carrierPresets || {};
	function carrierNames() { return $$(".dp-carrier input[data-k='name']", carriersBox).map((i) => i.value.trim()).filter(Boolean); }
	function pintarTransportadoras() {
		const box = $("#dp-preview-carriers");
		if (!box || !carriersBox) return;
		box.innerHTML = "";
		const names = carrierNames();
		(names.length ? names : ["Interrapidísimo", "Servientrega", "Coordinadora"]).slice(0, 5).forEach((name) => {
			const s = document.createElement("span");
			s.textContent = name;
			if (!names.length) s.className = "faint";
			box.appendChild(s);
		});
	}
	function addCarrier(name, url, focus) {
		const key = "c" + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
		const row = document.createElement("div");
		row.className = "dp-carrier dp-row is-new";
		row.innerHTML = '<input type="text" class="dp-input" data-k="name" name="dox_pos_sales[carriers][' + key + '][name]" placeholder="' + (i18n.carrierName || "Transportadora") + '" aria-label="' + (i18n.carrierName || "Transportadora") + '">' +
			'<input type="text" class="dp-input" data-k="url" name="dox_pos_sales[carriers][' + key + '][url]" placeholder="https://… {guia}" aria-label="' + (i18n.carrierUrl || "Enlace de rastreo") + '" inputmode="url" autocomplete="off">' +
			'<button type="button" class="dp-carrier-x" aria-label="' + (i18n.remove || "Quitar") + '">' + icon("x") + "</button>";
		row.querySelector("[data-k='name']").value = name || "";
		row.querySelector("[data-k='url']").value = url || "";
		carriersBox.appendChild(row);
		if (focus) row.querySelector("[data-k='name']").focus();
		pintarTransportadoras();
		sucio();
	}
	if (carriersBox) {
		carriersBox.addEventListener("click", (e) => { const b = e.target.closest(".dp-carrier-x"); if (b) { b.closest(".dp-carrier").remove(); pintarTransportadoras(); sucio(); } });
		carriersBox.addEventListener("input", (e) => { if (e.target.dataset.k === "name") pintarTransportadoras(); });
		if (carrierAdd) carrierAdd.addEventListener("click", () => addCarrier("", "", true));
		if (carrierPreset) carrierPreset.addEventListener("change", () => {
			const p = presets[carrierPreset.value];
			if (p && !carrierNames().some((n) => n.toLowerCase() === p.name.toLowerCase())) addCarrier(p.name, p.url, false);
			carrierPreset.value = "";
		});
	}
	// El mensaje de WhatsApp del envío: comodines y texto de fábrica.
	const shipMsg = $("#dox_pos_ship_message");
	if (shipMsg) {
		$$("#dp-ship-placeholders .dp-chip").forEach((b) => b.addEventListener("click", () => {
			const ini = shipMsg.selectionStart, fin = shipMsg.selectionEnd, ins = b.dataset.insert;
			shipMsg.value = shipMsg.value.slice(0, ini) + ins + shipMsg.value.slice(fin);
			shipMsg.focus();
			shipMsg.setSelectionRange(ini + ins.length, ini + ins.length);
			sucio();
		}));
		const r = $("#dp-ship-reset");
		if (r) r.addEventListener("click", () => { shipMsg.value = defaults.shipMessage || ""; sucio(); });
	}

	// ---------- productos: la calidad y el tamaño del WebP, y el código de ejemplo ----------
	function pintarProductos() {
		const q = $("#dp-quality"), px = $("#dp-maxpx");
		if (!q || !px) return;
		const pq = $("#dp-preview-quality"), ppx = $("#dp-preview-px"), sku = $("#dp-preview-sku");
		if (pq) pq.textContent = q.value || "88";
		if (ppx) ppx.textContent = px.value || "1600";
		const fmt = (form.querySelector('input[name="dox_pos_products[sku]"]:checked') || {}).value;
		if (sku) sku.textContent = fmt === "slugs" ? "VE83-6-12-meses-rosa" : (fmt === "none" ? "VE83" : "VE830213");
	}

	// ---------- todo se repinta con cualquier cambio ----------
	function repintar() { pintarColores(); pintarTextos(); pintarCanales(); pintarPagos(); pintarTransportadoras(); pintarMensaje(); pintarProductos(); document.dispatchEvent(new CustomEvent("dox-pos-repintar")); }
	form.addEventListener("input", () => { repintar(); sucio(); });
	form.addEventListener("change", () => { repintar(); sucio(); });
	repintar();

	// ---------- cambios sin guardar ----------
	function foto() {
		const fd = new FormData(form);
		const pares = [];
		for (const [k, v] of fd.entries()) { if (k.indexOf("dox_pos") === 0) pares.push(k + "=" + v); }
		return pares.join("&");
	}
	const inicial = foto();
	let saliendo = false;
	function sucio() {
		const d = foto() !== inicial;
		app.classList.toggle("is-dirty", d);
		return d;
	}
	const guardar = $("#dp-save");
	form.addEventListener("submit", () => {
		saliendo = true;
		guardar.setAttribute("aria-busy", "true");
		$(".dp-save-text", guardar).textContent = i18n.saving || "…";
	});
	$("#dp-discard").addEventListener("click", () => { saliendo = true; location.reload(); });
	addEventListener("keydown", (e) => {
		if ((e.metaKey || e.ctrlKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === "s") { e.preventDefault(); form.requestSubmit(); }
	});
	addEventListener("beforeunload", (e) => {
		if (saliendo || !sucio()) return;
		e.preventDefault();
		e.returnValue = "";
	});

	// ---------- avisos del guardado ----------
	const toasts = $("#dp-toasts");
	function toast(msg, type) {
		const t = document.createElement("div");
		t.className = "dp-toast is-" + (type || "error"); // Con "is-": WordPress mueve de sitio cualquier div.error o div.updated
		t.innerHTML = icon(type === "success" ? "check" : "alert") + '<span></span><button type="button">' + icon("x") + "</button>";
		t.querySelector("span").textContent = msg;
		t.querySelector("button").setAttribute("aria-label", i18n.close || "Cerrar");
		const quitar = () => { if (t.classList.contains("out")) return; t.classList.add("out"); setTimeout(() => t.remove(), 170); };
		t.querySelector("button").addEventListener("click", quitar);
		toasts.appendChild(t);
		if (type === "success") setTimeout(quitar, 5000);
	}
	const campos = { font_ui: ["marca", "#dp-font-ui"], font_serif: ["marca", "#dp-font-serif"], slug: ["pantalla", "#dp-slug"], channels: ["ventas", "#dp-canales"], payments: ["ventas", "#dp-pagos"], quality: ["productos", "#dp-quality"], max_px: ["productos", "#dp-maxpx"] };
	(cfg.notices || []).forEach((nt) => {
		toast(nt.message, nt.type === "success" ? "success" : "error");
		// Los campos de los añadidos no están en la lista: se buscan por su id (#dp-ai-key para ai_key) y su pestaña.
		let c = campos[nt.code];
		if (!c) {
			const guess = $("#dp-" + String(nt.code).replace(/_/g, "-"));
			const panel = guess && guess.closest(".dp-panel");
			if (!panel) return;
			c = [panel.dataset.panel, "#dp-" + String(nt.code).replace(/_/g, "-")];
		}
		abrir(c[0], false);
		const el = $(c[1]);
		const field = el && el.closest(".dp-field");
		if (field) {
			field.classList.add("is-invalid");
			const s = field.querySelector(".dp-status");
			if (s) { s.textContent = nt.message; s.className = "dp-status bad"; }
		}
	});
})();
