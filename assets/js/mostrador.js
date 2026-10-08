/* Dox POS: el Mostrador, la caja de la tienda física (includes/counter.php, templates/counter.php).
   Se cuelga de la caja por window.DoxPOS, como el Pro: registra su pestaña y usa la API, las ventanas
   y los avisos de caja.js. Vende con el escáner (un lector USB o Bluetooth escribe el código como un
   teclado y termina en Enter) o con la cámara, y cobra en efectivo, tarjeta u otra forma de pago. */
(function () {
	"use strict";

	const D = window.DoxPOS;
	if (!D || !D.cfg || !D.cfg.counter) return;

	const i18n = (window.wp && window.wp.i18n) || {};
	const __ = i18n.__ || ((s) => s);
	const _n = i18n._n || ((s, p, n) => (n === 1 ? s : p));
	const sprintf = i18n.sprintf || ((s) => s);

	const { cfg, $, esc, dinero, num, redondear, api, post, modal, cerrarModal, preguntar, toast, miniatura, uuid } = D;
	const C = cfg.counter;
	const PAGOS = {};
	(C.payments || []).forEach((p) => { PAGOS[p.key] = p.title; });

	// ---------- lo que se recuerda en este equipo ----------
	// Si este equipo es el mostrador (abre siempre aquí) y las ventas en espera. Es del navegador a
	// propósito: el ordenador del mostrador y el teléfono de la misma persona abren cada uno lo suyo.
	const MEM_EQUIPO = "dox_pos_counter_device";
	const MEM_ESPERA = "dox_pos_counter_hold";
	function leer(k, def) {
		try { const v = localStorage.getItem(k); return v === null ? def : JSON.parse(v); } catch (e) { return def; }
	}
	function guardar(k, v) {
		try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sin memoria: no se recuerda */ }
	}
	// Antes del arranque de la caja: sin # en la dirección, este equipo abre en el Mostrador.
	if (leer(MEM_EQUIPO, false) && !location.hash.replace(/^#/, "")) cfg.open_tab = "mostrador";

	const m = {
		lineas: [],      // la venta: [{vid, n}]
		vars: {},        // id de variación -> {v, p}
		res: [],         // resultados de la búsqueda por nombre
		abierto: null,   // el producto desplegado en los resultados
		desc: { tipo: "$", valor: 0 }, // el descuento: en dinero o en %
		quote: null,     // el total que dio la tienda (con impuestos), y para qué venta
		quoteKey: "",
		ocupado: false,
		rapidos: C.quick || [],
	};
	const recordar = (prod) => prod.variations.forEach((v) => { m.vars[v.id] = { v: v, p: prod }; });
	m.rapidos.forEach(recordar);

	// ---------- sonido: un pitido corto al leer bien, uno grave si no ----------
	let audio = null;
	function pitar(ok) {
		try {
			audio = audio || new (window.AudioContext || window.webkitAudioContext)();
			const o = audio.createOscillator(), g = audio.createGain();
			o.frequency.value = ok ? 1760 : 220;
			g.gain.value = 0.06;
			o.connect(g); g.connect(audio.destination);
			o.start();
			o.stop(audio.currentTime + (ok ? 0.07 : 0.22));
		} catch (e) { /* sin sonido */ }
	}

	// ---------- existencias ----------
	const bolsa = (v) => (v.shared ? (v.pool || "*") : "");
	function enVenta(v) {
		if (!v.shared) { const l = m.lineas.find((x) => x.vid === v.id); return l ? l.n : 0; }
		const b = bolsa(v);
		return m.lineas.reduce((a, l) => { const d = m.vars[l.vid]; return a + (d && d.v.shared && d.v.parent === v.parent && bolsa(d.v) === b ? l.n : 0); }, 0);
	}
	const libres = (v) => (v.stock === null ? (v.status === "outofstock" ? 0 : Infinity) : v.stock - enVenta(v));

	// ---------- la venta ----------
	function añadir(vid) {
		const d = m.vars[vid];
		if (!d) return false;
		if (libres(d.v) < 1) {
			pitar(false);
			ultimo(d, false);
			return false;
		}
		const ya = m.lineas.find((l) => l.vid === vid);
		if (ya) ya.n++; else m.lineas.push({ vid: vid, n: 1 });
		m.flash = vid;
		pitar(true);
		ultimo(d, true);
		pintar();
		return true;
	}
	// El último que entró, en grande, para que quien cobra vea qué leyó el escáner sin mirar la lista.
	function ultimo(d, ok) {
		const box = $("#m-ultimo");
		box.hidden = false;
		box.className = "ultimo" + (ok ? "" : " no");
		box.innerHTML = "<span>" + (ok ? esc(__("Added:", "dox-pos")) : esc(__("None left:", "dox-pos"))) + " <b>" + esc(d.p.name) + "</b> · " + esc(d.v.label) + (d.v.sku ? " · " + esc(d.v.sku) : "") + '</span><span class="v">' + dinero(d.v.price) + "</span>";
		box.prepend(miniatura(d.p, true));
	}
	function sub() { return m.lineas.reduce((a, l) => a + l.n * (m.vars[l.vid] ? m.vars[l.vid].v.price : 0), 0); }
	function descMonto() {
		const s = sub();
		const v = m.desc.tipo === "%" ? redondear(s * Math.min(100, m.desc.valor) / 100) : m.desc.valor;
		return Math.max(0, Math.min(v, s));
	}
	const lineasPayload = () => m.lineas.map((l) => ({ id: l.vid, qty: l.n }));

	function pintarLineas() {
		const ul = $("#m-lineas");
		ul.innerHTML = m.lineas.length ? "" : '<li class="empty" style="padding:8px 2px">' + esc(__("Scan a product or tap one on the left.", "dox-pos")) + "</li>";
		m.lineas.forEach((l, i) => {
			const d = m.vars[l.vid];
			if (!d) return;
			const li = document.createElement("li");
			li.className = "lin" + (m.flash === l.vid ? " flash" : "");
			li.innerHTML =
				'<span class="n">' + esc(d.p.name) + "<i>" + esc(d.v.label) + (d.v.sku ? " · " + esc(d.v.sku) : "") + "</i></span>" +
				'<span class="qty"><button type="button" data-d="-1" aria-label="' + esc(__("One less", "dox-pos")) + '">−</button><span>' + l.n + '</span><button type="button" data-d="1" aria-label="' + esc(__("One more", "dox-pos")) + '">+</button></span>' +
				'<span class="v">' + dinero(l.n * d.v.price) + "</span>";
			li.querySelectorAll(".qty button").forEach((b) => {
				b.onclick = () => {
					const delta = +b.dataset.d;
					if (delta > 0 && libres(d.v) < 1) { pitar(false); toast(__("There are no more units of this one.", "dox-pos")); return; }
					l.n += delta;
					if (l.n < 1) m.lineas.splice(i, 1);
					pintar();
				};
			});
			li.prepend(miniatura(d.p, true));
			ul.appendChild(li);
		});
		m.flash = null;
		const u = m.lineas.reduce((a, l) => a + l.n, 0);
		$("#m-n").textContent = u ? sprintf(_n("%d item", "%d items", u, "dox-pos"), u) : "";
	}

	// ---------- el total ----------
	// Con impuestos que se suman al precio (Estados Unidos), el total lo calcula la tienda con su
	// dirección, igual que lo hará el pedido. Sin eso, se suma aquí.
	const claveVenta = () => JSON.stringify([lineasPayload(), descMonto()]);
	let cotTimer = 0;
	function totalLocal() { const s = sub(), d = descMonto(); return { subtotal: s, discount: d, tax: 0, total: Math.max(0, s - d) }; }
	async function cotizar() {
		if (!C.taxes || !m.lineas.length) return totalLocal();
		const k = claveVenta();
		if (m.quote && m.quoteKey === k) return m.quote;
		const q = await post("counter/quote", { lines: lineasPayload(), discount: descMonto() });
		const out = { subtotal: +q.subtotal, discount: +q.discount, tax: +q.tax, total: +q.total };
		if (k === claveVenta()) { m.quote = out; m.quoteKey = k; }
		return out;
	}
	function pintarSum() {
		const t = C.taxes && m.lineas.length ? (m.quoteKey === claveVenta() ? m.quote : null) : totalLocal();
		const s = sub(), d = descMonto();
		let h = "<div><span>" + esc(__("Subtotal", "dox-pos")) + "</span><span>" + dinero(s) + "</span></div>";
		if (d) h += "<div><span>" + esc(m.desc.tipo === "%" ? sprintf(__("Discount (%s%%)", "dox-pos"), m.desc.valor) : __("Discount", "dox-pos")) + "</span><span>−" + dinero(d) + "</span></div>";
		if (C.taxes) h += "<div><span>" + esc(__("Tax", "dox-pos")) + "</span><span>" + (t ? dinero(t.tax) : "…") + "</span></div>";
		h += '<div class="tot"><span>' + esc(__("Total", "dox-pos")) + "</span><span>" + (t ? dinero(t.total) : "…") + "</span></div>";
		$("#m-sum").innerHTML = h;
		const vacio = !m.lineas.length || m.ocupado;
		["#m-efectivo", "#m-tarjeta", "#m-otro"].forEach((s2) => { $(s2).disabled = vacio; });
		$("#m-desc").disabled = !m.lineas.length;
		$("#m-vaciar").disabled = !m.lineas.length;
		if (C.taxes && m.lineas.length && !t) {
			clearTimeout(cotTimer);
			cotTimer = setTimeout(() => { cotizar().then(pintarSum).catch((e) => { if (e.message !== "sesion") $("#m-sum").querySelector(".tot span:last-child").textContent = "?"; }); }, 200);
		}
	}
	function pintarEspera() {
		const n = leer(MEM_ESPERA, []).length;
		const b = $("#m-nesp");
		b.hidden = !n;
		b.textContent = n;
	}
	function pintar() {
		pintarLineas();
		pintarSum();
		pintarEspera();
		if (m.res.length) pintarResultados();
	}

	// ---------- escanear y buscar ----------
	const estado = (html) => { $("#m-estado").innerHTML = html; };
	function estadoListo() {
		estado('<span><span class="ok">● ' + esc(__("Scanner ready:", "dox-pos")) + "</span> " + esc(__("scan the barcode, there is no need to tap anything", "dox-pos")) + "</span><span class=\"tecla\"><kbd>F2</kbd> " + esc(__("search", "dox-pos")) + "</span>");
	}
	async function escanear(code) {
		code = String(code || "").trim();
		if (!code) return;
		$("#m-q").value = "";
		cerrarResultados();
		if (/^DOXPOS-\d+$/i.test(code)) { devolucion(code); return; } // El código de un ticket: su devolución.
		try {
			const r = await api("counter/scan?code=" + encodeURIComponent(code));
			if (!r.item) throw new Error("codigo");
			recordar(r.item);
			if (r.vid) { añadir(r.vid); estadoListo(); return; }
			// El código es del producto, no de una talla: se abre para elegirla.
			m.res = [r.item];
			m.abierto = r.item.id;
			pintarResultados();
			pitar(true);
			estado("<span>" + esc(__("Choose the size:", "dox-pos")) + " <b>" + esc(r.item.name) + "</b></span>");
		} catch (e) {
			if (e.message === "sesion") return;
			pitar(false);
			if (e.red) { estado('<span class="no">' + esc(__("No signal: the product could not be looked up.", "dox-pos")) + "</span>"); return; }
			// No es un código: quizá se escribió un nombre y se dio Enter. Se busca como en Vender.
			$("#m-q").value = code;
			const hay = await buscar(code);
			$("#m-q").select(); // La siguiente lectura reemplaza este código en vez de pegarse detrás.
			estado('<span class="no">' + esc(sprintf(hay ? __("No product has the code %s. These are the ones whose name matches.", "dox-pos") : __("No product has the code %s.", "dox-pos"), code)) + "</span>");
		}
	}
	let busTimer = 0, busCtrl = null;
	async function buscar(q) {
		if (busCtrl) busCtrl.abort();
		if (q.length < 2) { cerrarResultados(); return false; }
		busCtrl = new AbortController();
		try {
			const d = await api("search?q=" + encodeURIComponent(q), { signal: busCtrl.signal });
			d.items.forEach(recordar);
			m.res = d.items;
			m.abierto = d.items.length === 1 ? d.items[0].id : null;
			pintarResultados();
			return d.items.length > 0;
		} catch (e) {
			return false;
		}
	}
	function cerrarResultados() {
		m.res = [];
		m.abierto = null;
		$("#m-res").hidden = true;
		$("#m-rapidos-box").hidden = false;
	}
	function textoStock(v) {
		const n = libres(v);
		if (n === Infinity) return __("available", "dox-pos");
		if (n <= 0) return __("none left", "dox-pos");
		return sprintf(__("%s left", "dox-pos"), "<b>" + n + "</b>");
	}
	function pintarResultados() {
		const ul = $("#m-res");
		ul.hidden = false;
		$("#m-rapidos-box").hidden = true;
		ul.innerHTML = m.res.length ? "" : '<li class="empty">' + esc(__("Nothing matches. Try a single word.", "dox-pos")) + "</li>";
		m.res.forEach((p) => {
			const li = document.createElement("li");
			const b = document.createElement("button");
			b.type = "button";
			b.className = "prod";
			b.setAttribute("aria-expanded", m.abierto === p.id);
			b.innerHTML = '<span class="n">' + esc(p.name) + "<i>" + esc(p.sku || "") + '</i></span><span class="p">' + dinero(p.price) + "</span>";
			b.prepend(miniatura(p));
			b.onclick = () => {
				if (p.variations.length === 1) { añadir(p.variations[0].id); volverAlLector(); return; }
				m.abierto = m.abierto === p.id ? null : p.id;
				pintarResultados();
			};
			li.appendChild(b);
			if (m.abierto === p.id && p.variations.length > 1) {
				const box = document.createElement("div");
				box.className = "vars";
				p.variations.forEach((v) => {
					const vb = document.createElement("button");
					vb.type = "button";
					vb.className = "var";
					vb.disabled = libres(v) < 1;
					vb.innerHTML = '<span class="l">' + esc(v.label) + "<i>" + esc(v.sku) + '</i></span><span class="s">' + textoStock(v) + "</span>";
					vb.onclick = () => { añadir(v.id); volverAlLector(); };
					box.appendChild(vb);
				});
				li.appendChild(box);
			}
			ul.appendChild(li);
		});
	}
	// Después de añadir desde la lista: vuelve a los botones y al lector, listo para el siguiente.
	function volverAlLector() {
		$("#m-q").value = "";
		cerrarResultados();
		estadoListo();
		enfocar();
	}
	const editable = (el) => !!el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
	function enfocar() {
		if (D.pestañaActual() !== "mostrador" || !$("#modal").hidden) return;
		if (editable(document.activeElement) && document.activeElement.id !== "m-q") return; // Escribiendo el cliente: no se le quita el foco.
		$("#m-q").focus({ preventScroll: true });
	}

	// ---------- los botones rápidos ----------
	function pintarRapidos() {
		const box = $("#m-rapidos");
		box.innerHTML = "";
		if (!m.rapidos.length) {
			box.innerHTML = '<p class="empty">' + esc(C.edit ? __("Put here what you sell without a barcode (a gift bag, an alteration, a gift card): tap “Choose buttons”.", "dox-pos") : __("The shop has not chosen any buttons yet.", "dox-pos")) + "</p>";
			return;
		}
		m.rapidos.forEach((p) => {
			const b = document.createElement("button");
			b.type = "button";
			b.className = "rap";
			const precios = p.variations.map((v) => v.price);
			const min = Math.min.apply(null, precios), max = Math.max.apply(null, precios);
			b.innerHTML = '<span class="n">' + esc(p.name) + '</span><span class="p">' + (min === max ? dinero(min) : dinero(min) + " – " + dinero(max)) + "</span>";
			b.prepend(miniatura(p));
			b.onclick = () => {
				if (p.variations.length === 1) { añadir(p.variations[0].id); enfocar(); return; }
				m.res = [p];
				m.abierto = p.id;
				pintarResultados();
			};
			box.appendChild(b);
		});
	}
	// Quien administra elige los botones sin salir del Mostrador: busca, añade, quita y guarda.
	function elegirRapidos() {
		let lista = m.rapidos.slice();
		modal("<h3>" + esc(__("Buttons for products without a barcode", "dox-pos")) + '</h3><p class="mp">' + esc(__("They show up on the Counter to sell with one tap. Up to 24.", "dox-pos")) + '</p><input type="search" id="mr-q" placeholder="' + esc(__("Search by name or SKU", "dox-pos")) + '" autocomplete="off"><ul class="res" id="mr-res" style="max-height:180px;overflow:auto;margin:8px 0"></ul><div class="rtit">' + esc(__("Chosen", "dox-pos")) + '</div><ul class="res" id="mr-sel" style="max-height:200px;overflow:auto;margin-bottom:14px"></ul><div class="mbtn"><button type="button" class="go" id="mr-ok">' + esc(__("Save", "dox-pos")) + '</button><button type="button" class="go alt" id="m-no">' + esc(__("Cancel", "dox-pos")) + "</button></div>", "cobro");
		const fila = (p, accion, txt) => {
			const li = document.createElement("li");
			li.className = "lin";
			li.innerHTML = '<span class="n">' + esc(p.name) + "<i>" + dinero(p.price) + '</i></span><button type="button" class="mini">' + esc(txt) + "</button>";
			li.prepend(miniatura(p, true));
			li.querySelector("button").onclick = accion;
			return li;
		};
		const pintarSel = () => {
			const ul = $("#mr-sel");
			ul.innerHTML = lista.length ? "" : '<li class="empty">' + esc(__("None yet.", "dox-pos")) + "</li>";
			lista.forEach((p, i) => ul.appendChild(fila(p, () => { lista.splice(i, 1); pintarSel(); }, __("Remove", "dox-pos"))));
		};
		pintarSel();
		let t = 0;
		$("#mr-q").addEventListener("input", () => {
			clearTimeout(t);
			t = setTimeout(async () => {
				const q = $("#mr-q").value.trim();
				const ul = $("#mr-res");
				if (q.length < 2) { ul.innerHTML = ""; return; }
				try {
					const d = await api("search?q=" + encodeURIComponent(q));
					ul.innerHTML = "";
					d.items.slice(0, 8).forEach((p) => ul.appendChild(fila(p, () => {
						if (lista.some((x) => x.id === p.id)) return;
						if (lista.length >= 24) { toast(__("Up to 24 buttons.", "dox-pos")); return; }
						lista.push(p);
						pintarSel();
					}, __("Add", "dox-pos"))));
				} catch (e) { /* se reintenta al escribir */ }
			}, 250);
		});
		$("#m-no").onclick = () => cerrarModal(false);
		$("#mr-ok").onclick = async () => {
			try {
				const d = await post("counter/quick", { ids: lista.map((p) => p.id) });
				m.rapidos = d.items || [];
				m.rapidos.forEach(recordar);
				pintarRapidos();
				cerrarModal(true);
				toast(__("Buttons saved.", "dox-pos"));
			} catch (e) {
				if (e.message !== "sesion") toast(e.message);
			}
		};
	}

	// ---------- en espera ----------
	// La venta se aparca en este equipo (no reserva existencias) para atender al siguiente cliente.
	function aparcar() {
		const lista = leer(MEM_ESPERA, []);
		const vars = {};
		m.lineas.forEach((l) => { vars[l.vid] = m.vars[l.vid]; });
		lista.push({ id: uuid(), at: Date.now(), lineas: m.lineas, vars: vars, desc: m.desc, nom: $("#m-nom").value.trim(), tel: $("#m-tel").value.trim(), total: sub() - descMonto() });
		guardar(MEM_ESPERA, lista.slice(-20));
		limpiar();
		toast(__("Sale on hold. Tap “On hold” to pick it up again.", "dox-pos"));
	}
	function retomar(id) {
		const lista = leer(MEM_ESPERA, []);
		const i = lista.findIndex((x) => x.id === id);
		if (i < 0) return;
		const v = lista.splice(i, 1)[0];
		guardar(MEM_ESPERA, lista);
		Object.keys(v.vars || {}).forEach((k) => { if (v.vars[k]) m.vars[k] = v.vars[k]; });
		m.lineas = (v.lineas || []).filter((l) => m.vars[l.vid]);
		m.desc = v.desc || { tipo: "$", valor: 0 };
		$("#m-nom").value = v.nom || "";
		$("#m-tel").value = v.tel || "";
		m.quote = null;
		pintar();
	}
	function verEspera() {
		if (m.lineas.length) { aparcar(); return; }
		const lista = leer(MEM_ESPERA, []);
		if (!lista.length) { toast(__("There are no sales on hold.", "dox-pos")); return; }
		const hora = (t) => new Date(t).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
		modal("<h3>" + esc(__("Sales on hold", "dox-pos")) + '</h3><ul class="esperas">' + lista.slice().reverse().map((v) => {
			const u = (v.lineas || []).reduce((a, l) => a + l.n, 0);
			return '<li><button type="button" data-id="' + esc(v.id) + '"><span><b>' + esc(v.nom || sprintf(__("Sale of %s", "dox-pos"), hora(v.at))) + "</b><small>" + esc(sprintf(_n("%d item", "%d items", u, "dox-pos"), u)) + " · " + esc(hora(v.at)) + '</small></span><span class="v">' + dinero(v.total) + "</span></button></li>";
		}).join("") + '</ul><div class="mbtn"><button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>", "cobro");
		$("#m-no").onclick = () => cerrarModal(false);
		$("#modal-card").querySelectorAll("[data-id]").forEach((b) => { b.onclick = () => { cerrarModal(true); retomar(b.dataset.id); }; });
	}

	// ---------- descuento ----------
	function descuento() {
		if (!m.lineas.length) return;
		const t = m.desc.tipo;
		modal("<h3>" + esc(__("Discount", "dox-pos")) + '</h3><div class="seg" id="md-tipo" style="margin-bottom:12px"><button type="button" data-t="$" aria-pressed="' + (t === "$") + '">' + esc(sprintf(__("Amount (%s)", "dox-pos"), D.M.symbol)) + '</button><button type="button" data-t="%" aria-pressed="' + (t === "%") + '">' + esc(__("Percent (%)", "dox-pos")) + '</button></div><div class="field recibe cobro"><input id="md-v" inputmode="decimal" value="' + esc(m.desc.valor ? String(m.desc.valor) : "") + '" placeholder="0"></div><p class="hint">' + esc(sprintf(__("Subtotal: %s", "dox-pos"), dinero(sub()))) + '</p><div class="mbtn" style="margin-top:14px"><button type="button" class="go" id="md-ok">' + esc(__("Apply", "dox-pos")) + '</button><button type="button" class="go alt" id="md-quitar">' + esc(__("No discount", "dox-pos")) + "</button></div>", "cobro");
		let tipo = t;
		$("#md-tipo").querySelectorAll("button").forEach((b) => { b.onclick = () => { tipo = b.dataset.t; $("#md-tipo").querySelectorAll("button").forEach((x) => x.setAttribute("aria-pressed", x === b)); $("#md-v").focus(); }; });
		const aplicar = (valor) => { m.desc = { tipo: tipo, valor: Math.max(0, valor) }; m.quote = null; cerrarModal(true); pintar(); };
		$("#md-ok").onclick = () => aplicar(tipo === "%" ? Math.min(100, parseFloat(String($("#md-v").value).replace(",", ".")) || 0) : num($("#md-v").value));
		$("#md-quitar").onclick = () => aplicar(0);
		$("#md-v").addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); $("#md-ok").click(); } });
	}

	// ---------- el turno: abrir y cerrar caja ----------
	m.shift = null;
	m.lastFloat = 0;
	const IM = D.M.decimals > 0 ? "decimal" : "numeric";
	// Cuando se cierra la ventana sin aceptar (Escape, tocar fuera, "Ahora no").
	function alCerrarModal(fn) {
		const el = $("#modal");
		const ob = new MutationObserver(() => { if (el.hidden) { ob.disconnect(); fn(); } });
		ob.observe(el, { attributes: true, attributeFilter: ["hidden"] });
	}
	async function cargarTurno() {
		try {
			const d = await api("counter/shift");
			m.shift = d.shift;
			m.lastFloat = d.last_float || 0;
		} catch (e) { /* sin señal: se queda lo que había */ }
		pintarTurno();
	}
	function pintarTurno() {
		const s = m.shift, box = $("#m-turno");
		$("#m-cerrar").hidden = !s;
		if (!s) {
			box.innerHTML = '<span class="dot off"></span>' + esc(__("The till is closed.", "dox-pos")) + ' <button type="button" class="linkbtn" id="m-abrir">' + esc(__("Open the till", "dox-pos")) + "</button>";
			$("#m-abrir").onclick = () => abrirCaja();
			return;
		}
		const r = s.summary || {};
		box.innerHTML = '<span><span class="dot"></span>' + esc(sprintf(__("Open since %s", "dox-pos"), s.opened_at)) + '</span><span class="sep"></span><span>' + esc(__("Float", "dox-pos")) + " <b>" + dinero(r.float || 0) + '</b></span><span class="sep"></span><span>' + esc(sprintf(_n("%d sale", "%d sales", r.orders || 0, "dox-pos"), r.orders || 0)) + " · <b>" + dinero(r.total || 0) + "</b></span>";
	}
	// Abre la caja contando la base. Devuelve si quedó abierta.
	function abrirCaja(motivo) {
		return new Promise((resolve) => {
			modal("<h3>" + esc(__("Open the till", "dox-pos")) + '</h3><p class="mp">' + esc(motivo || __("Count the cash in the drawer: it is the float, and the closing compares against it.", "dox-pos")) + '</p><div class="field recibe"><label for="ma-f">' + esc(__("Cash in the drawer", "dox-pos")) + '</label><input id="ma-f" inputmode="' + IM + '" autocomplete="off" value="' + esc(m.lastFloat ? D.miles(m.lastFloat) : "") + '" placeholder="0"></div><div class="fila" style="margin-top:16px"><button type="button" class="go alt" id="m-no">' + esc(__("Not now", "dox-pos")) + '</button><button type="button" class="go" id="ma-ok">' + esc(__("Open the till", "dox-pos")) + "<kbd>Enter</kbd></button></div>", "cobro");
			let listo = false;
			alCerrarModal(() => { if (!listo) resolve(false); });
			$("#m-no").onclick = () => cerrarModal(false);
			$("#ma-f").addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); $("#ma-ok").click(); } });
			$("#ma-f").select();
			$("#ma-ok").onclick = async () => {
				try {
					const d = await post("counter/shift/open", { float: num($("#ma-f").value) });
					m.shift = d.shift;
					listo = true;
					cerrarModal(true);
					pintarTurno();
					toast(__("Till open. You can charge now.", "dox-pos"));
					resolve(true);
				} catch (e) {
					if (e.message !== "sesion") toast(e.message);
				}
			};
		});
	}
	const asegurarCaja = () => (m.shift ? Promise.resolve(true) : abrirCaja(__("The till is not open yet. Count the cash in the drawer to open it.", "dox-pos")));
	const filaSum = (t, v, cls) => '<div class="' + (cls || "") + '"><span>' + esc(t) + "</span><span>" + v + "</span></div>";
	function resumenHtml(r) {
		let h = '<div class="sum cierre">' + filaSum(sprintf(_n("%d sale", "%d sales", r.orders || 0, "dox-pos"), r.orders || 0), dinero(r.total || 0), "tot2");
		(r.payments || []).forEach((p) => { h += filaSum(p.title, dinero(p.amount)); });
		if (r.refund_cash || r.refund_other) h += filaSum(__("Returns", "dox-pos"), "−" + dinero((r.refund_cash || 0) + (r.refund_other || 0)));
		h += '</div><div class="sum cierre">' + filaSum(__("Float", "dox-pos"), dinero(r.float || 0)) + filaSum(__("Cash sales", "dox-pos"), dinero(r.cash_sales || 0));
		if (r.refund_cash) h += filaSum(__("Cash given back", "dox-pos"), "−" + dinero(r.refund_cash));
		return h + filaSum(__("Cash that should be in the drawer", "dox-pos"), dinero(r.expected || 0), "tot2") + "</div>";
	}
	async function cerrarCaja() {
		if (m.lineas.length) { toast(__("Charge the current sale or put it on hold before closing the till.", "dox-pos")); return; }
		await cargarTurno();
		if (!m.shift) { toast(__("The till is not open.", "dox-pos")); return; }
		const r = m.shift.summary || {};
		modal("<h3>" + esc(__("Close the till", "dox-pos")) + "</h3>" + resumenHtml(r) + '<div class="field recibe"><label for="mz-c">' + esc(__("Cash counted in the drawer", "dox-pos")) + '</label><input id="mz-c" inputmode="' + IM + '" autocomplete="off" placeholder="0"></div><div class="cambio falta" id="mz-dif"></div><input id="mz-n" autocomplete="off" placeholder="' + esc(__("Note (optional): why it is short or over", "dox-pos")) + '"><div class="fila" style="margin-top:14px"><button type="button" class="go alt" id="m-no">' + esc(__("Back", "dox-pos")) + '</button><button type="button" class="go" id="mz-ok" disabled>' + esc(__("Close the till", "dox-pos")) + "</button></div>", "cobro");
		const repintar = () => {
			const v = $("#mz-c").value.trim(), box = $("#mz-dif");
			$("#mz-ok").disabled = !v;
			if (!v) { box.className = "cambio falta"; box.innerHTML = "<span>" + esc(__("Count the drawer", "dox-pos")) + "</span><b></b>"; return; }
			const d = redondear(num(v) - (r.expected || 0));
			box.className = "cambio" + (d ? " falta" : "");
			box.innerHTML = "<span>" + esc(d === 0 ? __("It matches", "dox-pos") : d > 0 ? __("Over", "dox-pos") : __("Short", "dox-pos")) + "</span><b>" + dinero(Math.abs(d)) + "</b>";
		};
		$("#mz-c").addEventListener("input", repintar);
		$("#mz-c").addEventListener("keydown", (e) => { if (e.key === "Enter" && !$("#mz-ok").disabled) { e.preventDefault(); $("#mz-ok").click(); } });
		$("#m-no").onclick = () => cerrarModal(false);
		repintar();
		$("#mz-ok").onclick = async () => {
			try {
				const d = await post("counter/shift/close", { counted: num($("#mz-c").value), note: $("#mz-n").value.trim() });
				m.shift = null;
				pintarTurno();
				const z = d.shift, dif = z.difference || 0;
				modal('<div class="total"><span>' + esc(__("Till closed", "dox-pos")) + "</span><b>" + dinero(z.counted || 0) + '</b></div><div class="cambio' + (dif ? " falta" : "") + '"><span>' + esc(dif === 0 ? __("It matches", "dox-pos") : dif > 0 ? __("Over", "dox-pos") : __("Short", "dox-pos")) + "</span><b>" + dinero(Math.abs(dif)) + '</b></div><div class="fila"><button type="button" class="go alt" id="mt-print">' + esc(__("Print the closing", "dox-pos")) + '</button><button type="button" class="go" id="m-no">' + esc(__("Done", "dox-pos")) + "</button></div>", "cobro");
				$("#m-no").onclick = () => cerrarModal(true);
				$("#mt-print").onclick = () => imprimir(cierreHtml(z, d.store || {}), (d.store || {}).width);
			} catch (e) {
				if (e.message !== "sesion") toast(e.message);
			}
		};
	}

	// ---------- el ticket: se imprime desde el navegador en cualquier impresora ----------
	const EQ_IMPRIMIR = "dox_pos_counter_print"; // Este equipo imprime el ticket solo al cobrar.
	const C128 = "212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 321221 312212 322112 322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 231113 231311 112133 112331 132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 332111 314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 142211 241211 221114 413111 241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 211214 211232 2331112".split(" ");
	// Code 128 (juego B) en SVG: el número del pedido, que el escáner lee para la devolución.
	function codigoBarras(txt) {
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
	const fila2 = (a, b, cls) => '<div class="r' + (cls ? " " + cls : "") + '"><span>' + a + "</span><span>" + b + "</span></div>";
	function cabecera(s) {
		return '<div class="c">' + (s.logo ? '<img class="logo" src="' + esc(s.logo) + '" alt="">' : "") + '<div class="name">' + esc(s.name || "") + "</div>" + (s.header ? '<div class="pre">' + esc(s.header) + "</div>" : "") + "</div><hr>";
	}
	function ticketHtml(r, dev) {
		const s = r.store || {};
		let h = cabecera(s);
		h += fila2(esc(sprintf(__("Order #%s", "dox-pos"), r.number)), "") + '<div class="s0">' + esc(r.date) + "</div>";
		if (r.seller) h += '<div class="s0">' + esc(sprintf(__("Served by %s", "dox-pos"), r.seller)) + "</div>";
		if (r.customer) h += '<div class="s0">' + esc(sprintf(__("Customer: %s", "dox-pos"), r.customer)) + "</div>";
		h += "<hr>";
		if (dev) {
			h += '<div class="c big">' + esc(__("RETURN", "dox-pos")) + "</div><hr>";
			dev.lines.forEach((l) => { h += fila2(esc(l.name), "") + '<div class="s">' + esc(sprintf(__("%d returned", "dox-pos"), l.qty)) + "</div>"; });
			h += "<hr>" + fila2(esc(__("RETURNED", "dox-pos")), dinero(dev.amount), "big") + '<div class="s0">' + esc(dev.method === "efectivo" ? __("In cash", "dox-pos") : sprintf(__("By the same payment method (%s)", "dox-pos"), (r.payments || []).map((p) => p.title).join(" + "))) + "</div>";
		} else {
			r.lines.forEach((l) => { h += fila2(esc(l.name), dinero(l.total)) + '<div class="s">' + esc(l.qty + " x " + dinero(l.unit) + (l.sku ? " · " + l.sku : "")) + "</div>"; });
			h += "<hr>" + fila2(esc(__("Subtotal", "dox-pos")), dinero(r.subtotal));
			if (r.discount) h += fila2(esc(__("Discount", "dox-pos")), "−" + dinero(r.discount));
			if (!r.included) (r.taxes || []).forEach((t) => { h += fila2(esc(t.label), dinero(t.amount)); });
			h += fila2(esc(__("TOTAL", "dox-pos")), dinero(r.total), "big");
			if (r.included && (r.taxes || []).length) h += '<div class="s0">' + esc(sprintf(__("Includes %s", "dox-pos"), r.taxes.map((t) => t.label + " " + dinero(t.amount)).join(", "))) + "</div>";
			h += "<hr>";
			(r.payments || []).forEach((p) => { h += fila2(esc(p.title), dinero(p.amount)); });
			if (r.tendered != null) h += fila2(esc(__("Cash received", "dox-pos")), dinero(r.tendered)) + fila2(esc(__("Change", "dox-pos")), dinero(r.change || 0));
			if (r.refunded) h += fila2(esc(__("Returned", "dox-pos")), "−" + dinero(r.refunded));
		}
		h += "<hr>" + (s.footer ? '<div class="c pre">' + esc(s.footer) + "</div>" : "");
		return h + '<div class="code">' + codigoBarras(r.code) + '</div><div class="c s0">' + esc(r.code) + "</div>";
	}
	function cierreHtml(z, s) {
		const r = z.summary || {};
		let h = cabecera(s) + '<div class="c big">' + esc(__("TILL CLOSING", "dox-pos")) + "</div><hr>";
		h += '<div class="s0">' + esc(sprintf(__("Opened: %1$s %2$s by %3$s", "dox-pos"), z.opened_day, z.opened_at, z.opened_name)) + "</div>";
		h += '<div class="s0">' + esc(sprintf(__("Closed: %1$s %2$s by %3$s", "dox-pos"), z.closed_day, z.closed_at, z.closed_name)) + "</div><hr>";
		h += fila2(esc(sprintf(_n("%d sale", "%d sales", r.orders || 0, "dox-pos"), r.orders || 0)), dinero(r.total || 0), "big");
		(r.payments || []).forEach((p) => { h += fila2(esc(p.title), dinero(p.amount)); });
		if (r.refund_cash || r.refund_other) h += fila2(esc(__("Returns", "dox-pos")), "−" + dinero((r.refund_cash || 0) + (r.refund_other || 0)));
		h += "<hr>" + fila2(esc(__("Float", "dox-pos")), dinero(r.float || 0)) + fila2(esc(__("Cash sales", "dox-pos")), dinero(r.cash_sales || 0));
		if (r.refund_cash) h += fila2(esc(__("Cash given back", "dox-pos")), "−" + dinero(r.refund_cash));
		h += fila2(esc(__("Expected", "dox-pos")), dinero(r.expected || 0), "big") + fila2(esc(__("Counted", "dox-pos")), dinero(z.counted || 0), "big");
		const d = z.difference || 0;
		h += fila2(esc(d === 0 ? __("It matches", "dox-pos") : d > 0 ? __("Over", "dox-pos") : __("Short", "dox-pos")), dinero(Math.abs(d)), "big");
		if (z.note) h += '<div class="pre">' + esc(z.note) + "</div>";
		return h;
	}
	// Imprime en un marco escondido, con el ancho del rollo. El navegador abre su diálogo de impresión.
	function imprimir(cuerpo, ancho) {
		const papel = +ancho === 58 ? 58 : 80, util = papel === 58 ? 48 : 72, lado = (papel - util) / 2;
		const f = document.createElement("iframe");
		f.setAttribute("aria-hidden", "true");
		f.style.cssText = "position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none";
		document.body.appendChild(f);
		const doc = f.contentDocument;
		doc.open();
		doc.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(__("Receipt", "dox-pos")) + "</title><style>" +
			"@page{size:" + papel + "mm auto;margin:0}html,body{margin:0;padding:0;background:#fff;color:#000}" +
			"body{width:" + util + "mm;padding:3mm " + lado + "mm 8mm;font:" + (papel === 58 ? 10 : 11) + "px/1.4 ui-monospace,Menlo,Consolas,'Courier New',monospace;-webkit-print-color-adjust:exact;print-color-adjust:exact}" +
			".c{text-align:center}.name{font-weight:700;font-size:1.35em;margin:2px 0}.pre{white-space:pre-line}.logo{display:block;margin:0 auto 4px;max-width:60%;max-height:22mm;filter:grayscale(1) contrast(1.4)}" +
			"hr{border:0;border-top:1px dashed #000;margin:6px 0}.r{display:flex;justify-content:space-between;gap:8px}.r span:last-child{white-space:nowrap}" +
			".s{padding-left:8px;color:#000;opacity:.8}.s0{opacity:.85}.big{font-weight:700;font-size:1.15em}.code{margin:8px auto 2px;height:12mm}.code svg{width:100%;height:100%}" +
			"</style></head><body>" + cuerpo + "</body></html>");
		doc.close();
		const imgs = Array.prototype.slice.call(doc.images);
		Promise.all(imgs.map((i) => (i.complete ? 0 : new Promise((ok) => { i.onload = i.onerror = ok; })))).then(() => {
			setTimeout(() => {
				try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) { toast(__("The browser did not let the receipt print.", "dox-pos")); }
				// El foco vuelve a la caja: si se queda en el marco, el lector y las teclas no llegan.
				window.focus();
				const b = !$("#modal").hidden && $("#modal-card").querySelector("#m-no");
				if (b) b.focus(); else enfocar();
				setTimeout(() => f.remove(), 60000);
			}, 60);
		});
	}
	const MEM_ULTIMO = "dox_pos_counter_last";
	async function imprimirPedido(q, dev) {
		try {
			const d = await api("counter/order?q=" + encodeURIComponent(q));
			if (!d.receipt) { toast(__("That order was not found.", "dox-pos")); return; }
			imprimir(ticketHtml(d.receipt, dev), (d.receipt.store || {}).width);
		} catch (e) {
			if (e.message !== "sesion") toast(e.red ? __("No signal: the receipt could not be loaded.", "dox-pos") : e.message);
		}
	}
	function pintarUltimo() { $("#m-ultimo-ticket").hidden = !leer(MEM_ULTIMO, 0); }

	// ---------- cobrar ----------
	// Los billetes con que suele pagar el cliente: lo justo, y lo que sale de redondear hacia arriba
	// al siguiente billete de la moneda de la tienda.
	function sugerencias(total) {
		const out = [];
		(C.bills || []).slice().sort((a, b) => a - b).forEach((b) => {
			const v = Math.ceil(total / b - 1e-9) * b;
			if (v > total + 1e-9 && out.indexOf(v) < 0) out.push(v);
		});
		return out.sort((a, b) => a - b).slice(0, 4);
	}
	async function totalParaCobrar() {
		if (!m.lineas.length || m.ocupado) return null;
		if (!(await asegurarCaja())) return null;
		try {
			return await cotizar();
		} catch (e) {
			if (e.message !== "sesion") toast(e.red ? __("No signal: the total could not be calculated.", "dox-pos") : e.message);
			return null;
		}
	}
	async function efectivo() {
		const t = await totalParaCobrar();
		if (!t) return;
		const sug = sugerencias(t.total);
		modal('<div class="total"><span>' + esc(__("Total to charge", "dox-pos")) + "</span><b>" + dinero(t.total) + '</b></div><div class="field recibe"><label for="mc-r">' + esc(__("Received", "dox-pos")) + '</label><input id="mc-r" inputmode="' + IM + '" autocomplete="off" placeholder="' + esc(dinero(t.total)) + '"></div><div class="billetes"><button type="button" class="chip" data-v="' + t.total + '" data-exact="1" aria-pressed="true">' + esc(__("Exact", "dox-pos")) + "</button>" + sug.map((v) => '<button type="button" class="chip" data-v="' + v + '" aria-pressed="false">' + dinero(v) + "</button>").join("") + '</div><div class="cambio" id="mc-cambio"></div><div class="fila"><button type="button" class="go alt" id="m-no">' + esc(__("Back", "dox-pos")) + '</button><button type="button" class="go" id="mc-ok">' + esc(__("Charge", "dox-pos")) + "<kbd>Enter</kbd></button></div>", "cobro");
		const inp = $("#mc-r");
		const recibido = () => (inp.value.trim() ? num(inp.value) : t.total); // Vacío es "justo".
		const repintar = () => {
			const r = recibido(), c = redondear(r - t.total);
			const box = $("#mc-cambio");
			box.className = "cambio" + (c < 0 ? " falta" : "");
			box.innerHTML = "<span>" + esc(c < 0 ? __("Missing", "dox-pos") : __("Change", "dox-pos")) + "</span><b>" + dinero(Math.abs(c)) + "</b>";
			$("#mc-ok").disabled = c < 0;
			$("#modal-card").querySelectorAll(".billetes .chip").forEach((b) => b.setAttribute("aria-pressed", inp.value.trim() === "" ? !!b.dataset.exact : !b.dataset.exact && Math.abs(+b.dataset.v - r) < 1e-9));
		};
		$("#modal-card").querySelectorAll(".billetes .chip").forEach((b) => { b.onclick = () => { inp.value = b.dataset.exact ? "" : D.miles(+b.dataset.v); repintar(); inp.focus(); }; });
		inp.addEventListener("input", repintar);
		inp.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); $("#mc-ok").click(); } });
		$("#m-no").onclick = () => cerrarModal(false);
		$("#mc-ok").onclick = () => { if (recibido() + 1e-9 < t.total) return; cerrarModal(true); registrar("efectivo", recibido(), t.total); };
		repintar();
	}
	async function tarjeta() {
		const t = await totalParaCobrar();
		if (!t) return;
		modal('<div class="total"><span>' + esc(PAGOS.tarjeta || __("Card", "dox-pos")) + "</span><b>" + dinero(t.total) + '</b></div><p class="mp">' + esc(__("Charge it on the shop’s card machine. When the payment goes through, record the sale.", "dox-pos")) + '</p><div class="fila"><button type="button" class="go alt" id="m-no">' + esc(__("Back", "dox-pos")) + '</button><button type="button" class="go" id="mc-ok">' + esc(__("Paid: record the sale", "dox-pos")) + "<kbd>Enter</kbd></button></div>", "cobro");
		$("#m-no").onclick = () => cerrarModal(false);
		$("#mc-ok").onclick = () => { cerrarModal(true); registrar("tarjeta", null, t.total); };
		$("#mc-ok").focus();
	}
	// Otra forma de pago, o varias: cuánto va en cada una. Lo de más solo puede ser efectivo (el cambio).
	async function otro() {
		const t = await totalParaCobrar();
		if (!t) return;
		modal('<div class="total"><span>' + esc(__("Charge", "dox-pos")) + "</span><b>" + dinero(t.total) + '</b></div><p class="mp">' + esc(__("One payment method or several: write how much goes on each. “The rest” fills in what is missing.", "dox-pos")) + '</p><div class="partes">' + (C.payments || []).map((p) => '<div class="parte"><label for="mp-' + esc(p.key) + '">' + esc(p.title) + '</label><button type="button" class="mini" data-resto="' + esc(p.key) + '">' + esc(__("The rest", "dox-pos")) + '</button><input id="mp-' + esc(p.key) + '" data-k="' + esc(p.key) + '" inputmode="' + IM + '" autocomplete="off" placeholder="0"></div>').join("") + '</div><div class="cambio falta" id="mp-falta"></div><div class="fila"><button type="button" class="go alt" id="m-no">' + esc(__("Back", "dox-pos")) + '</button><button type="button" class="go" id="mp-ok" disabled>' + esc(__("Charge", "dox-pos")) + "<kbd>Enter</kbd></button></div>", "cobro");
		const card = $("#modal-card");
		const ins = Array.prototype.slice.call(card.querySelectorAll("input[data-k]"));
		const partes = () => ins.map((i) => ({ key: i.dataset.k, amount: num(i.value) })).filter((p) => p.amount > 0);
		const estadoPago = () => {
			const ps = partes();
			const suma = ps.reduce((a, p) => a + p.amount, 0), cash = ps.filter((p) => p.key === "efectivo").reduce((a, p) => a + p.amount, 0);
			return { ps: ps, d: redondear(suma - t.total), cash: cash };
		};
		const repintar = () => {
			const e = estadoPago(), box = $("#mp-falta");
			const mal = !e.ps.length || e.d < 0 || e.d > e.cash + 1e-9;
			box.className = "cambio" + (mal ? " falta" : "");
			box.innerHTML = e.d < 0 || !e.ps.length ? "<span>" + esc(__("Missing", "dox-pos")) + "</span><b>" + dinero(Math.abs(e.ps.length ? e.d : t.total)) + "</b>"
				: e.d > e.cash + 1e-9 ? "<span>" + esc(__("Too much (only cash gives change)", "dox-pos")) + "</span><b>" + dinero(e.d) + "</b>"
				: "<span>" + esc(e.d > 0 ? __("Change", "dox-pos") : __("Complete", "dox-pos")) + "</span><b>" + dinero(e.d) + "</b>";
			$("#mp-ok").disabled = mal;
		};
		card.querySelectorAll("[data-resto]").forEach((b) => {
			b.onclick = () => {
				const inp = ins.find((i) => i.dataset.k === b.dataset.resto);
				const otros = ins.filter((i) => i !== inp).reduce((a, i) => a + num(i.value), 0);
				inp.value = D.miles(Math.max(0, redondear(t.total - otros)));
				repintar();
				inp.focus();
			};
		});
		ins.forEach((i) => {
			i.addEventListener("input", repintar);
			i.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); if (!$("#mp-ok").disabled) $("#mp-ok").click(); } });
		});
		$("#m-no").onclick = () => cerrarModal(false);
		$("#mp-ok").onclick = () => {
			const e = estadoPago();
			cerrarModal(true);
			registrar(e.ps[0].key, null, t.total, e.ps.length > 1 || e.ps[0].key === "efectivo" ? e.ps : null);
		};
		repintar();
	}
	async function registrar(pago, recibido, total, partes) {
		if (m.ocupado || !m.lineas.length) return;
		m.ocupado = true;
		pintarSum();
		const payload = {
			ref: uuid(),
			counter: true,
			hold: false,
			lines: lineasPayload(),
			payment: pago,
			discount: descMonto(),
			note: "",
			customer: { name: $("#m-nom").value.trim(), phone: $("#m-tel").value.trim() },
		};
		if (pago === "efectivo" && recibido != null) payload.tendered = recibido;
		if (partes) payload.payments = partes;
		try {
			const d = await post("orders", payload);
			limpiar();
			exito(d.order || {}, total);
			D.emit("pedido", d.order);
			D.refrescarStock();
			D.cargarPedidos();
			cargarTurno();
		} catch (e) {
			if (e.message !== "sesion") toast(e.red ? __("No signal: the sale was not recorded. Try again when the connection is back.", "dox-pos") : e.message);
			if (e.code === "dox_pos_falta_dinero") m.quote = null; // El total cambió: se vuelve a pedir.
			if (e.code === "dox_pos_caja_cerrada") { m.shift = null; pintarTurno(); }
		}
		m.ocupado = false;
		pintarSum();
		enfocar();
	}
	// La venta quedó: el cambio en grande (si lo hay) y el ticket, solo o con un toque.
	function exito(o, total) {
		guardar(MEM_ULTIMO, o.id);
		pintarUltimo();
		const auto = !!leer(EQ_IMPRIMIR, false);
		const cambio = o.change != null ? o.change : null;
		modal('<div class="total"><span>' + esc(sprintf(__("Sale #%s recorded", "dox-pos"), o.number)) + "</span><b>" + dinero(o.total != null ? o.total : total) + "</b></div>" + (cambio != null ? '<div class="cambio"><span>' + esc(__("Change", "dox-pos")) + "</span><b>" + dinero(cambio) + "</b></div>" : '<p class="mp">' + esc(__("The stock has already gone down.", "dox-pos")) + "</p>") + '<div class="fila"><button type="button" class="go alt" id="mt-print">' + esc(auto ? __("Print again", "dox-pos") : __("Print receipt", "dox-pos")) + '<kbd>P</kbd></button><button type="button" class="go" id="m-no">' + esc(__("Next customer", "dox-pos")) + "<kbd>Enter</kbd></button></div>", "cobro");
		$("#m-no").onclick = () => cerrarModal(true);
		$("#mt-print").onclick = () => imprimirPedido("DOXPOS-" + o.id);
		$("#m-no").focus();
		if (auto) imprimirPedido("DOXPOS-" + o.id);
	}

	// ---------- devoluciones ----------
	// Se escanea el ticket (o se escribe el número del pedido), se eligen las piezas y cómo se devuelve el dinero.
	async function devolucion(codigo) {
		if (m.lineas.length) { toast(__("Charge the current sale or put it on hold before a return.", "dox-pos")); return; }
		if (!(await asegurarCaja())) return;
		if (codigo) { buscarDevolucion(codigo); return; }
		modal("<h3>" + esc(__("Return", "dox-pos")) + '</h3><p class="mp">' + esc(__("Scan the barcode on the receipt, or type the order number.", "dox-pos")) + '</p><input id="mv-q" autocomplete="off" placeholder="' + esc(__("Order number", "dox-pos")) + '"><div class="fila" style="margin-top:14px"><button type="button" class="go alt" id="m-no">' + esc(__("Back", "dox-pos")) + '</button><button type="button" class="go" id="mv-ok">' + esc(__("Find the order", "dox-pos")) + "<kbd>Enter</kbd></button></div>", "cobro");
		$("#m-no").onclick = () => cerrarModal(false);
		const ir = () => { const q = $("#mv-q").value.trim(); if (q) buscarDevolucion(q); };
		$("#mv-ok").onclick = ir;
		$("#mv-q").addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); ir(); } });
	}
	async function buscarDevolucion(q) {
		let r;
		try {
			r = (await api("counter/order?q=" + encodeURIComponent(q))).receipt;
		} catch (e) {
			if (e.message !== "sesion") toast(e.red ? __("No signal: the order could not be looked up.", "dox-pos") : e.message);
			return;
		}
		if (!r) { pitar(false); toast(sprintf(__("No order has the number %s.", "dox-pos"), q)); return; }
		pitar(true);
		const lineas = r.lines.filter((l) => l.refundable > 0);
		if (!lineas.length) {
			modal("<h3>" + esc(sprintf(__("Order #%s", "dox-pos"), r.number)) + '</h3><p class="mp">' + esc(__("Everything on this order has already been returned.", "dox-pos")) + '</p><div class="mbtn"><button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>", "cobro");
			$("#m-no").onclick = () => cerrarModal(false);
			return;
		}
		const sel = {};
		let metodo = "efectivo";
		const original = (r.payments || []).map((p) => p.title).join(" + ");
		modal("<h3>" + esc(sprintf(__("Return from order #%s", "dox-pos"), r.number)) + '</h3><p class="mp">' + esc(r.date + " · " + dinero(r.total)) + '</p><ul class="res devol">' + lineas.map((l) => '<li class="lin"><span class="n">' + esc(l.name) + "<i>" + esc(sprintf(__("%1$d of %2$d can be returned", "dox-pos"), l.refundable, l.qty)) + '</i></span><span class="qty"><button type="button" data-id="' + l.id + '" data-d="-1">−</button><span id="mv-n' + l.id + '">0</span><button type="button" data-id="' + l.id + '" data-d="1">+</button></span><span class="v" id="mv-v' + l.id + '">' + dinero(0) + "</span></li>").join("") + '</ul><div class="chips" id="mv-met" style="margin:12px 0"><button type="button" class="chip" data-m="efectivo" aria-pressed="true">' + esc(__("Cash from the drawer", "dox-pos")) + '</button><button type="button" class="chip" data-m="original" aria-pressed="false">' + esc(sprintf(__("Same method (%s)", "dox-pos"), original)) + '</button></div><div class="cambio falta" id="mv-tot"></div><div class="fila"><button type="button" class="go alt" id="m-no">' + esc(__("Back", "dox-pos")) + '</button><button type="button" class="go" id="mv-ok" disabled>' + esc(__("Make the return", "dox-pos")) + "</button></div>", "cobro");
		const monto = () => redondear(lineas.reduce((a, l) => a + (sel[l.id] || 0) * l.gross, 0));
		const repintar = () => {
			lineas.forEach((l) => { $("#mv-n" + l.id).textContent = sel[l.id] || 0; $("#mv-v" + l.id).textContent = dinero((sel[l.id] || 0) * l.gross); });
			const a = monto(), box = $("#mv-tot");
			box.className = "cambio" + (a ? "" : " falta");
			box.innerHTML = "<span>" + esc(metodo === "efectivo" ? __("Give back in cash", "dox-pos") : __("Give back by the same method", "dox-pos")) + "</span><b>" + dinero(a) + "</b>";
			$("#mv-ok").disabled = !a;
		};
		$("#modal-card").querySelectorAll(".devol .qty button").forEach((b) => {
			b.onclick = () => {
				const l = lineas.find((x) => String(x.id) === b.dataset.id);
				sel[l.id] = Math.max(0, Math.min(l.refundable, (sel[l.id] || 0) + +b.dataset.d));
				repintar();
			};
		});
		$("#mv-met").querySelectorAll(".chip").forEach((b) => { b.onclick = () => { metodo = b.dataset.m; $("#mv-met").querySelectorAll(".chip").forEach((x) => x.setAttribute("aria-pressed", x === b)); repintar(); }; });
		$("#m-no").onclick = () => cerrarModal(false);
		$("#mv-ok").onclick = async () => {
			const lines = lineas.filter((l) => sel[l.id]).map((l) => ({ id: l.id, qty: sel[l.id] }));
			$("#mv-ok").disabled = true;
			try {
				const d = await post("counter/refund", { order: r.id, lines: lines, method: metodo });
				if (d.shift) { m.shift = d.shift; pintarTurno(); }
				const dev = { amount: d.amount, method: d.method, lines: lines.map((l) => ({ name: lineas.find((x) => x.id === l.id).name, qty: l.qty })) };
				modal('<div class="total"><span>' + esc(__("Return done", "dox-pos")) + "</span><b>" + dinero(d.amount) + '</b></div><p class="mp">' + esc(d.method === "efectivo" ? __("Give the money back from the drawer. The pieces are back in stock.", "dox-pos") : __("Give the money back on the card machine or by the original payment method. The pieces are back in stock.", "dox-pos")) + '</p><div class="fila"><button type="button" class="go alt" id="mt-print">' + esc(__("Print receipt", "dox-pos")) + '<kbd>P</kbd></button><button type="button" class="go" id="m-no">' + esc(__("Done", "dox-pos")) + "<kbd>Enter</kbd></button></div>", "cobro");
				$("#m-no").onclick = () => cerrarModal(true);
				$("#m-no").focus();
				$("#mt-print").onclick = () => imprimir(ticketHtml(d.receipt, dev), (d.receipt.store || {}).width);
				D.emit("pedido", { id: r.id });
				D.refrescarStock();
				D.cargarPedidos();
			} catch (e) {
				$("#mv-ok").disabled = false;
				if (e.message !== "sesion") toast(e.message);
			}
		};
		repintar();
	}
	function limpiar() {
		m.lineas = [];
		m.desc = { tipo: "$", valor: 0 };
		m.quote = null;
		m.quoteKey = "";
		$("#m-nom").value = "";
		$("#m-tel").value = "";
		$("#m-ultimo").hidden = true;
		$("#m-q").value = "";
		cerrarResultados();
		estadoListo();
		pintar();
	}
	function vaciar() {
		if ($("#m-q").value) { $("#m-q").value = ""; cerrarResultados(); estadoListo(); return; }
		if (!m.lineas.length) return;
		preguntar(__("Empty this sale? Nothing is recorded.", "dox-pos"), __("Empty", "dox-pos"), __("Cancel", "dox-pos")).then((ok) => { if (ok) limpiar(); enfocar(); });
	}

	// ---------- la cámara como escáner (donde el navegador sabe leer códigos) ----------
	const FORMATOS = ["ean_13", "ean_8", "upc_a", "upc_e", "code_128", "code_39", "itf", "qr_code"];
	async function camara() {
		let det, stream;
		try {
			det = new window.BarcodeDetector({ formats: FORMATOS });
			stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" }, audio: false });
		} catch (e) {
			toast(__("The camera could not be opened. Check that the browser has permission to use it.", "dox-pos"));
			return;
		}
		modal("<h3>" + esc(__("Point at the barcode", "dox-pos")) + '</h3><div class="camara"><video id="mk-v" playsinline muted></video></div><div class="mbtn"><button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>", "cobro");
		const v = $("#mk-v");
		v.srcObject = stream;
		v.play().catch(() => {});
		$("#m-no").onclick = () => cerrarModal(false);
		let vivo = true;
		const parar = () => { vivo = false; stream.getTracks().forEach((tr) => tr.stop()); };
		const mirar = async () => {
			if (!vivo) return;
			if ($("#modal").hidden || !document.contains(v)) { parar(); return; } // Se cerró la ventana.
			try {
				const r = await det.detect(v);
				if (r && r.length) { parar(); cerrarModal(true); escanear(r[0].rawValue); return; }
			} catch (e) { /* el cuadro aún no estaba listo */ }
			setTimeout(mirar, 200);
		};
		setTimeout(mirar, 300);
	}

	// Lo de este equipo (se guarda en el navegador) y los atajos.
	function esteEquipo() {
		const fila = (k, t) => "<kbd>" + esc(k) + "</kbd><span>" + esc(t) + "</span>";
		const tog = (id, on, t, s) => '<label class="tog"><input type="checkbox" id="' + id + '"' + (on ? " checked" : "") + "><span><b>" + esc(t) + "</b><small>" + esc(s) + "</small></span></label>";
		modal("<h3>" + esc(__("This device", "dox-pos")) + "</h3>" +
			tog("me-mostrador", leer(MEM_EQUIPO, false), __("This device is the counter", "dox-pos"), __("It always opens on the Counter.", "dox-pos")) +
			tog("me-imprimir", leer(EQ_IMPRIMIR, false), __("Print the receipt when charging", "dox-pos"), __("Without asking, on this device’s printer.", "dox-pos")) +
			'<div class="rtit">' + esc(__("Keyboard shortcuts", "dox-pos")) + '</div><div class="atajos">' +
			fila("F2", __("Search a product by name", "dox-pos")) + fila("F3", __("Return", "dox-pos")) + fila("F4", __("Charge in cash", "dox-pos")) + fila("F6", __("Charge by card", "dox-pos")) +
			fila("F7", __("Another payment method, or split", "dox-pos")) + fila("F8", __("Put the sale on hold, or pick one up", "dox-pos")) +
			fila("F9", __("Discount", "dox-pos")) + fila("Esc", __("Clear the search, or empty the sale", "dox-pos")) +
			'</div><p class="mp">' + esc(__("The scanner works without tapping anything: when it reads a barcode the product goes into the sale, and the barcode of a receipt opens its return. On a laptop the F keys may need the Fn key.", "dox-pos")) + '</p><div class="mbtn"><button type="button" class="go" id="m-no">' + esc(__("Done", "dox-pos")) + "</button></div>", "cobro");
		$("#m-no").onclick = () => cerrarModal(true);
		$("#me-mostrador").onchange = (e) => guardar(MEM_EQUIPO, e.target.checked ? true : null);
		$("#me-imprimir").onchange = (e) => guardar(EQ_IMPRIMIR, e.target.checked ? true : null);
	}

	// ---------- teclado: el escáner y los atajos ----------
	// El lector escribe como un teclado. Si el foco no está en un campo (se tocó un botón), lo que llega
	// se lleva al campo del lector para que la lectura no se pierda.
	function teclado(e) {
		if (D.pestañaActual() !== "mostrador") return;
		if (!$("#modal").hidden) return; // Las ventanas llevan sus propias teclas.
		const k = e.key;
		const acciones = { F2: () => { $("#m-q").focus(); $("#m-q").select(); }, F3: () => devolucion(), F4: efectivo, F6: tarjeta, F7: otro, F8: verEspera, F9: descuento };
		if (acciones[k]) { e.preventDefault(); acciones[k](); return; }
		if (k === "Escape") { e.preventDefault(); vaciar(); return; }
		const a = document.activeElement;
		if (editable(a) || e.ctrlKey || e.metaKey || e.altKey) return;
		if (k === " " && a && a.tagName === "BUTTON") return; // La barra espaciadora pulsa el botón que tiene el foco.
		if (k.length === 1) {
			e.preventDefault();
			const q = $("#m-q");
			const todo = q.value && q.selectionStart === 0 && q.selectionEnd === q.value.length; // Lo que quedó seleccionado se reemplaza.
			q.focus();
			q.value = todo ? k : q.value + k;
			return;
		}
		if (k === "Enter" && $("#m-q").value.trim()) { e.preventDefault(); escanear($("#m-q").value); }
	}

	// ---------- arranque ----------
	D.pestaña({ id: "mostrador", abrir: () => { pintar(); cargarTurno(); setTimeout(enfocar, 0); } });
	D.on("arranque", () => {
		const q = $("#m-q");
		q.addEventListener("keydown", (e) => {
			if (e.key !== "Enter") return;
			e.preventDefault();
			clearTimeout(busTimer); // El lector termina en Enter: no hace falta la búsqueda por nombre.
			const v = q.value.trim();
			if (!v) return;
			// Si la lista ya enseña lo que se buscó por nombre y hay uno solo, Enter lo añade.
			if (m.res.length === 1 && m.res[0].variations.length === 1 && q.dataset.buscado === v) { añadir(m.res[0].variations[0].id); volverAlLector(); return; }
			escanear(v);
		});
		q.addEventListener("input", () => {
			clearTimeout(busTimer);
			const v = q.value.trim();
			if (!v) { cerrarResultados(); estadoListo(); return; }
			// Escribir a mano es lento; el lector termina en milisegundos y llega antes el Enter.
			busTimer = setTimeout(() => { q.dataset.buscado = v; buscar(v); }, 350);
		});
		document.addEventListener("keydown", teclado);
		$("#m-efectivo").onclick = efectivo;
		$("#m-tarjeta").onclick = tarjeta;
		$("#m-otro").onclick = otro;
		$("#m-espera").onclick = verEspera;
		$("#m-desc").onclick = descuento;
		$("#m-vaciar").onclick = vaciar;
		$("#m-atajos").onclick = esteEquipo;
		$("#m-devolver").onclick = () => devolucion();
		$("#m-cerrar").onclick = cerrarCaja;
		$("#m-ultimo-ticket").onclick = () => imprimirPedido("DOXPOS-" + leer(MEM_ULTIMO, 0));
		// P en la ventana de la venta (o de la devolución) imprime el ticket.
		document.addEventListener("keydown", (e) => { if ((e.key === "p" || e.key === "P") && !$("#modal").hidden && $("#mt-print") && !editable(document.activeElement)) { e.preventDefault(); $("#mt-print").click(); } });
		if ($("#m-editar")) $("#m-editar").onclick = elegirRapidos;
		if ("BarcodeDetector" in window && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
			$("#m-cam").hidden = false;
			$("#m-cam").onclick = camara;
		}
		// Al volver a la ventana o cerrar un aviso, el lector recupera el foco.
		window.addEventListener("focus", enfocar);
		estadoListo();
		pintarRapidos();
		pintarUltimo();
		pintarTurno();
		pintar();
	});
})();
