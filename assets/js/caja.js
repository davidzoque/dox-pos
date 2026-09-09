/* Dox POS: la caja. Habla con /wp-json/dox-pos/v1/ y todo lo que guarda lo guarda WooCommerce. */
(function () {
	"use strict";

	// Los textos salen del sistema de traducción de WordPress (wp.i18n). Sin él (un optimizador que
	// lo tire, por ejemplo), la caja sigue funcionando en inglés en vez de quedarse en blanco.
	const i18n = (window.wp && window.wp.i18n) || {};
	const __ = i18n.__ || ((s) => s);
	const _n = i18n._n || ((s, p, n) => (n === 1 ? s : p));
	const sprintf = i18n.sprintf || ((s) => s);

	const cfg = window.DOX_POS || {};
	const $ = (s) => document.querySelector(s);
	const esc = (s) => String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/"/g, "&quot;");
	const num = (s) => parseInt(String(s).replace(/\D/g, ""), 10) || 0;

	// Los canales y las formas de pago vienen de los ajustes (WooCommerce > Dox POS).
	const CANALES = (cfg.channels && cfg.channels.length) ? cfg.channels : [{ name: "WhatsApp", pickup: false }];
	const PAGOS = (cfg.payments && cfg.payments.length) ? cfg.payments.map((p) => [p.key, p.title]) : [["transferencia", "Transferencia"]];
	const pagoInicial = PAGOS.some((p) => p[0] === cfg.default_payment) ? cfg.default_payment : PAGOS[0][0];

	// Un importe con el formato de la tienda ($189.000, 189.000 $, etc.).
	const M = Object.assign({ symbol: "$", pos: "left", thousand: ".", decimal: ",", decimals: 0 }, cfg.money || {});
	function dinero(n) {
		const v = Number(n) || 0;
		const parts = Math.abs(v).toFixed(M.decimals).split(".");
		let s = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, M.thousand);
		if (parts[1]) s += M.decimal + parts[1];
		const con = { left: M.symbol + s, right: s + M.symbol, left_space: M.symbol + " " + s, right_space: s + " " + M.symbol };
		return (v < 0 ? "−" : "") + (con[M.pos] || con.left);
	}

	const st = {
		tab: "vender",
		pane: "buscar",
		canal: CANALES[0].name,
		pago: pagoInicial,
		lineas: [],                               // el pedido: [{vid, n}]
		entrada: [],                              // lo que llegó: [{vid, n}]
		res: { venta: [], entrada: [] },          // últimos resultados de cada pestaña
		abierto: { venta: null, entrada: null },  // producto desplegado en cada pestaña
		top: null,                                // lo más vendido, para Vender antes de buscar
		topAt: 0,                                 // cuándo se pidió
		topOn: false,                             // ¿la lista de Vender enseña eso ahora?
		cat: { items: [], page: 0, more: true, loading: false, at: 0, total: 0 }, // el catálogo de la A a la Z, para Inventario antes de buscar
		catOn: false,                             // ¿la lista de Inventario enseña eso ahora?
		vars: {},                                 // id de variación -> {v, p}
		rates: [],                                // opciones de envío que dio la tienda
		rate: null,                               // la elegida
		pedidos: [],
		entradas: [],
		ocupado: false,                           // mientras se guarda algo
		sincronizando: false,                     // mientras se vacía la cola sin señal
		flash: null,                              // la línea recién agregada, para iluminarla
	};
	// El canal elegido se entrega en mano: sin ciudad ni envío.
	function sinEnvio() {
		const c = CANALES.find((x) => x.name === st.canal);
		return !!(c && c.pickup);
	}

	// ---------- API ----------
	async function api(path, opts) {
		const o = Object.assign({ credentials: "same-origin" }, opts || {});
		o.headers = Object.assign({ "X-WP-Nonce": cfg.nonce, Accept: "application/json" }, o.headers || {});
		let r;
		try {
			r = await fetch(cfg.rest + path, o);
		} catch (e) {
			if (e.name === "AbortError") throw e;
			const err = new Error(__("No signal", "dox-pos")); // fetch solo falla así cuando no hay red
			err.red = true;
			throw err;
		}
		if (r.status === 401 || r.status === 403) {
			sesionCaducada();
			throw new Error("sesion");
		}
		if (!r.ok) {
			let j = null;
			try { j = await r.json(); } catch (e) { /* sin cuerpo */ }
			const err = new Error(j && j.message ? j.message : sprintf(__("Could not reach the store (%s).", "dox-pos"), r.status));
			if (j && j.code) err.code = j.code; // por ejemplo dox_pos_factura_repetida
			throw err;
		}
		return r.json();
	}
	const post = (path, body) => api(path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body || {}) });
	function sesionCaducada() {
		toast(__("Your session expired. Please sign in again.", "dox-pos"));
		setTimeout(() => location.reload(), 1800);
	}

	// ---------- búsqueda ----------
	const pendientes = { venta: {}, entrada: {} }; // {timer, ctrl} por pestaña
	const inputDe = (modo) => (modo === "venta" ? $("#q") : $("#q2"));
	const listaDe = (modo) => (modo === "venta" ? $("#res") : $("#res2"));

	// Espera un momento a que termine de escribir antes de preguntar al servidor.
	function programar(modo) {
		clearTimeout(pendientes[modo].timer);
		pendientes[modo].timer = setTimeout(() => buscar(modo), 250);
	}
	async function buscar(modo) {
		const q = inputDe(modo).value.trim();
		const ul = listaDe(modo);
		const p = pendientes[modo];
		if (p.ctrl) p.ctrl.abort();
		if (q.length < 2) {
			st.res[modo] = [];
			if (modo === "venta") { mostrarTop(); return; } // Sin buscar nada: lo más vendido.
			mostrarCatalogo(); // En Inventario, el catálogo de la A a la Z.
			return;
			ul.innerHTML = vacio(__("Type two letters of the name, or the SKU.", "dox-pos"));
			return;
		}
		p.ctrl = new AbortController();
		if (!st.res[modo].length) ul.innerHTML = vacio("Buscando…");
		try {
			const data = await api("search?q=" + encodeURIComponent(q), { signal: p.ctrl.signal });
			data.items.forEach((prod) => prod.variations.forEach((v) => { st.vars[v.id] = { v: v, p: prod }; }));
			st.res[modo] = data.items;
			if (modo === "venta") st.topOn = false; else st.catOn = false;
			if (data.items.length === 1) st.abierto[modo] = data.items[0].id; // si hay uno solo, se abre
			else if (!data.items.some((x) => x.id === st.abierto[modo])) st.abierto[modo] = null;
			pintarResultados(modo);
			pintarLineas($("#lineas"), st.lineas, "venta");
			pintarLineas($("#lineas2"), st.entrada, "entrada");
		} catch (e) {
			if (e.name === "AbortError" || e.message === "sesion") return;
			ul.innerHTML = vacio(__("The search failed. Check the connection and try again.", "dox-pos"));
		}
	}
	// Lo más vendido en los últimos treinta días, para que Vender no salga vacío antes de buscar.
	// Se pide de nuevo al minuto, o después de una venta, para que las existencias estén al día.
	async function mostrarTop() {
		if (!st.top || Date.now() - st.topAt > 60000) {
			try {
				const d = await api("top");
				st.top = d.items || [];
				st.topAt = Date.now();
			} catch (e) {
				if (e.message === "sesion") return;
				st.top = st.top || [];
			}
		}
		if (inputDe("venta").value.trim().length >= 2) return; // Ya está escribiendo: manda la búsqueda.
		st.top.forEach((prod) => prod.variations.forEach((v) => { st.vars[v.id] = { v: v, p: prod }; }));
		st.res.venta = st.top;
		st.topOn = true;
		if (!st.top.some((x) => x.id === st.abierto.venta)) st.abierto.venta = null;
		pintarResultados("venta");
	}
	// Inventario no arranca vacío: el catálogo entero, de la A a la Z, de veinte en veinte; al llegar abajo carga más.
	// Se vuelve a pedir desde el principio al minuto de quieto, o después de guardar una entrada, para que las existencias estén al día.
	async function mostrarCatalogo(mas) {
		const c = st.cat;
		if (!mas && c.items.length && Date.now() - c.at > 60000) { c.items = []; c.page = 0; c.more = true; }
		if (c.loading) return;
		if ((!c.items.length || mas) && c.more) {
			c.loading = true;
			const ul = listaDe("entrada");
			if (mas) ul.insertAdjacentHTML("beforeend", '<li class="empty cargando">' + esc(__("Loading more\u2026", "dox-pos")) + "</li>");
			try {
				const d = await api("catalog?page=" + (c.page + 1));
				c.items = c.items.concat(d.items || []);
				c.page = d.page || c.page + 1;
				c.more = !!d.more;
				c.total = d.total || 0;
				c.at = Date.now();
			} catch (e) {
				c.loading = false;
				ul.querySelectorAll(".cargando").forEach((x) => x.remove());
				if (e.message === "sesion") return;
				if (!c.items.length) { ul.innerHTML = vacio(__("The catalogue could not be loaded. Check the connection and try again.", "dox-pos")); return; }
				if (e.red) toast(__("No signal: more products could not be loaded.", "dox-pos"));
				return;
			}
			c.loading = false;
		}
		if (inputDe("entrada").value.trim().length >= 2) return; // Ya está escribiendo: manda la búsqueda.
		c.items.forEach((prod) => prod.variations.forEach((v) => { st.vars[v.id] = { v: v, p: prod }; }));
		st.res.entrada = c.items;
		st.catOn = true;
		if (!c.items.some((x) => x.id === st.abierto.entrada)) st.abierto.entrada = null;
		pintarResultados("entrada");
	}
	const vacio = (txt) => '<li class="empty">' + esc(txt) + "</li>";
	function iniciales(n) {
		return n.replace(/[^A-Za-zÁÉÍÓÚÑáéíóúñ ]/g, "").split(" ").filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join("");
	}
	function miniatura(p, sm) {
		const t = document.createElement("span");
		t.className = "thumb" + (sm ? " sm" : "");
		t.textContent = iniciales(p.name);
		if (p.image) {
			const im = document.createElement("img");
			im.alt = "";
			im.loading = "lazy";
			im.onerror = () => im.remove();
			im.src = p.image;
			t.appendChild(im);
		}
		return t;
	}
	function enPedido(vid, modo) {
		const l = (modo === "venta" ? st.lineas : st.entrada).find((x) => x.vid === vid);
		return l ? l.n : 0;
	}
	// Las tallas que comparten un total (las existencias van en el producto, no en cada talla): lo que
	// este pedido ya lleva de cualquiera de ellas sale del mismo total.
	const bolsa = (v) => (v.shared ? (v.pool || "*") : ""); // Con quién comparte: todas las tallas ('*') o las de su color.
	function enPedidoCompartido(v, modo) {
		const b = bolsa(v);
		return (modo === "venta" ? st.lineas : st.entrada).reduce((a, l) => { const d = st.vars[l.vid]; return a + (d && d.v.shared && d.v.parent === v.parent && bolsa(d.v) === b ? l.n : 0); }, 0);
	}
	// Lo que queda libre: las existencias de la tienda menos lo que ya está en este pedido.
	const libres = (v) => (v.stock === null ? null : v.stock - (v.shared ? enPedidoCompartido(v, "venta") : enPedido(v.id, "venta")));
	function disponible(v) {
		return v.stock === null ? v.status !== "outofstock" : libres(v) > 0;
	}
	function textoStock(v, modo) {
		if (v.stock === null) return v.status === "outofstock" ? __("out of stock", "dox-pos") : __("no limit", "dox-pos");
		const n = modo === "venta" ? libres(v) : v.stock;
		if (n <= 0) return __("none left", "dox-pos");
		if (v.shared && v.pool && v.pool !== "*") return sprintf(__("%1$s left for all %2$s sizes", "dox-pos"), "<b>" + n + "</b>", esc(v.pool_name || ""));
		if (v.shared) return sprintf(v.talla ? __("%s left for all sizes", "dox-pos") : __("%s left for all options", "dox-pos"), "<b>" + n + "</b>");
		return sprintf(__("%s left", "dox-pos"), "<b>" + n + "</b>");
	}
	function pintarResultados(modo) {
		const ul = listaDe(modo);
		ul.innerHTML = "";
		const items = st.res[modo];
		const top = modo === "venta" && st.topOn; // La lista de arranque de Vender: lo más vendido.
		const cat = modo === "entrada" && st.catOn; // Y la de Inventario: el catálogo de la A a la Z.
		if (!items.length) {
			ul.innerHTML = vacio(cat ? __("There are no products yet.", "dox-pos") : top ? __("Type two letters of the name, or the SKU.", "dox-pos") : __("Nothing matches. Try a single word.", "dox-pos"));
			return;
		}
		if (top) ul.innerHTML = '<li class="rtit">' + esc(__("Best sellers of the last 30 days", "dox-pos")) + "</li>";
		if (cat) ul.innerHTML = '<li class="rtit">' + esc(__("All products", "dox-pos")) + (st.cat.total ? ' <span class="cnt">' + st.cat.items.length + " / " + st.cat.total + "</span>" : "") + "</li>";
		items.forEach((p) => {
			// Las tallas que comparten un total lo cuentan una sola vez: cinco unidades entre tres tallas no son quince.
			const bolsas = {};
			let hay = 0;
			p.variations.forEach((v) => { if (v.shared) { if (v.stock) bolsas[bolsa(v)] = v.stock; } else hay += v.stock || 0; });
			hay += Object.values(bolsas).reduce((a, b) => a + b, 0);
			const sinLimite = p.variations.some((v) => v.stock === null && v.status !== "outofstock");
			const conTalla = p.variations.some((v) => v.talla);
			const que = conTalla ? _n("size", "sizes", p.variations.length, "dox-pos") : _n("option", "options", p.variations.length, "dox-pos");
			const li = document.createElement("li");
			const b = document.createElement("button");
			b.type = "button";
			b.className = "prod";
			b.setAttribute("aria-expanded", st.abierto[modo] === p.id);
			b.innerHTML =
				'<span class="n">' + esc(p.name) + "<i>" + p.variations.length + " " + que + "</i></span>" +
				'<span class="p">' + dinero(p.price) + "<i>" + (sinLimite ? __("available", "dox-pos") : hay ? sprintf(__("%d in stock", "dox-pos"), hay) : __("out of stock", "dox-pos")) + "</i></span>";
			b.prepend(miniatura(p));
			b.onclick = () => {
				st.abierto[modo] = st.abierto[modo] === p.id ? null : p.id;
				pintarResultados(modo);
			};
			li.appendChild(b);
			if (st.abierto[modo] === p.id) {
				const box = document.createElement("div");
				box.className = "vars";
				p.variations.forEach((v) => {
					const vb = document.createElement("button");
					vb.type = "button";
					vb.className = "var";
					if (modo === "venta" && !disponible(v)) vb.disabled = true;
					vb.innerHTML =
						'<span class="l">' + esc(v.label) + "<i>" + esc(v.sku) + "</i></span>" +
						'<span class="s">' + textoStock(v, modo) + "</span>";
					vb.onclick = () => añadir(v.id, modo);
					box.appendChild(vb);
				});
				if (cfg.products && hayProducto()) { // Administradores y gerentes: editar el producto desde aquí.
					const eb = document.createElement("button");
					eb.type = "button";
					eb.className = "var editlink";
					eb.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar este producto';
					eb.onclick = () => editarDesdeLista(p.id);
					box.appendChild(eb);
				}
				li.appendChild(box);
			}
			ul.appendChild(li);
		});
		if (cat && st.cat.more) { // Si lo cargado no llena el hueco (pantalla alta), se pide la siguiente página ya.
			const sc = ul.parentElement;
			if (sc && sc.scrollHeight <= sc.clientHeight + 10) mostrarCatalogo(true);
		}
	}

	// ---------- el pedido y la entrada ----------
	// El costo que la tienda conoce de una talla (solo llega a quien administra): la entrada lo propone como costo de compra.
	const costoConocido = (vid) => (cfg.costs && st.vars[vid] && st.vars[vid].v.cost > 0 ? Math.round(st.vars[vid].v.cost) : 0);
	function añadir(vid, modo) {
		const arr = modo === "venta" ? st.lineas : st.entrada;
		const ya = arr.find((l) => l.vid === vid);
		if (ya) ya.n++;
		else arr.push(modo === "entrada" ? { vid: vid, n: 1, c: costoConocido(vid) } : { vid: vid, n: 1 });
		st.flash = modo + ":" + vid;
		pintarTodo();
	}
	function pintarLineas(ul, arr, modo) {
		ul.innerHTML = arr.length ? "" : '<li class="empty" style="padding:8px 2px">' + esc(__("Tap a product on the left to add it.", "dox-pos")) + "</li>";
		arr.forEach((l, i) => {
			const d = st.vars[l.vid];
			if (!d) return;
			const li = document.createElement("li");
			const conCosto = modo === "entrada" && !!cfg.costs; // Cada línea de la entrada lleva lo que costó la unidad, en su propia fila.
			li.className = "lin" + (conCosto ? " cost" : "") + (st.flash === modo + ":" + l.vid ? " flash" : "");
			li.dataset.vid = String(l.vid);
			li.innerHTML =
				'<span class="n">' + esc(d.p.name) + "<i>" + esc(d.v.label) + " · " + esc(d.v.sku) + (modo === "entrada" && d.v.shared ? " · " + esc(__("goes to the total shared by all sizes", "dox-pos")) : "") + "</i></span>" +
				'<span class="qty"><button type="button" data-d="-1" aria-label="' + esc(__("One less", "dox-pos")) + '">−</button><span>' + l.n + '</span><button type="button" data-d="1" aria-label="' + esc(__("One more", "dox-pos")) + '">+</button></span>' +
				(conCosto
					? '<span class="cst"><label>' + esc(__("Cost per unit", "dox-pos")) + '</label><input inputmode="numeric" placeholder="0" value="' + esc(miles(l.c || 0)) + '" aria-label="' + esc(__("Cost per unit", "dox-pos")) + '"><span class="vt">' + (l.c ? dinero(l.n * l.c) : "") + "</span></span>"
					: '<span class="v">' + dinero(l.n * d.v.price) + "</span>");
			if (conCosto) {
				const inp = li.querySelector(".cst input");
				inp.addEventListener("focus", () => inp.select());
				inp.addEventListener("input", () => {
					const v = num(inp.value), old = l.c || 0;
					l.c = v;
					// Las otras tallas del mismo producto que iban con el mismo costo cambian con esta: una compra suele costar igual en todas.
					arr.forEach((o) => { const od = st.vars[o.vid]; if (o !== l && od && od.p.id === d.p.id && (o.c || 0) === old) o.c = v; });
					ul.querySelectorAll("li[data-vid]").forEach((row) => {
						const o = arr.find((x) => String(x.vid) === row.dataset.vid);
						if (!o) return;
						const oi = row.querySelector(".cst input");
						if (oi && oi !== inp && num(oi.value) !== (o.c || 0)) oi.value = miles(o.c || 0);
						row.querySelector(".vt").textContent = o.c ? dinero(o.n * o.c) : "";
					});
					formatearMiles(inp);
					pintarSum();
				});
			}
			li.querySelectorAll(".qty button").forEach((b) => {
				b.onclick = () => {
					const delta = +b.dataset.d;
					if (delta > 0 && modo === "venta" && d.v.stock !== null && libres(d.v) < 1) return;
					l.n += delta;
					if (l.n < 1) arr.splice(i, 1);
					pintarTodo();
				};
			});
			li.prepend(miniatura(d.p, true));
			ul.appendChild(li);
		});
	}
	function totales() {
		const sub = st.lineas.reduce((a, l) => a + l.n * (st.vars[l.vid] ? st.vars[l.vid].v.price : 0), 0);
		const desc = num($("#f-desc").value);
		const env = sinEnvio() ? 0 : num($("#f-env").value);
		return { sub: sub, desc: desc, env: env, tot: Math.max(0, sub - desc + env) };
	}
	function pintarSum() {
		const t = totales();
		$("#sum").innerHTML =
			"<div><span>" + esc(__("Subtotal", "dox-pos")) + "</span><span>" + dinero(t.sub) + "</span></div>" +
			(t.desc ? "<div><span>" + esc(__("Discount", "dox-pos")) + "</span><span>−" + dinero(t.desc) + "</span></div>" : "") +
			(sinEnvio() ? "" : "<div><span>" + esc(__("Shipping", "dox-pos")) + "</span><span>" + dinero(t.env) + "</span></div>") +
			'<div class="tot"><span>' + esc(__("Total", "dox-pos")) + "</span><span>" + dinero(t.tot) + "</span></div>";
		$("#reg").disabled = st.ocupado || !st.lineas.length;
		$("#apartar").disabled = st.ocupado || !st.lineas.length;
		const uds = st.entrada.reduce((a, l) => a + l.n, 0);
		const costo = cfg.costs ? st.entrada.reduce((a, l) => a + l.n * (l.c || 0), 0) : 0;
		const sinCosto = cfg.costs ? st.entrada.filter((l) => !l.c).length : 0;
		$("#sum2").innerHTML = (cfg.costs && st.entrada.length ? "<div><span>" + esc(__("Cost of the goods", "dox-pos")) + "</span><span>" + (costo ? dinero(costo) : __("no cost", "dox-pos")) + (sinCosto && costo ? " · " + esc(sprintf(_n("%d line with no cost", "%d lines with no cost", sinCosto, "dox-pos"), sinCosto)) : "") + "</span></div>" : "") +
			'<div class="tot"><span>' + esc(__("Units coming in", "dox-pos")) + "</span><span>" + uds + "</span></div>";
		$("#reg2").disabled = st.ocupado || !st.entrada.length;
	}
	function pintarTodo() {
		if (st.res.venta.length) pintarResultados("venta");
		if (st.res.entrada.length) pintarResultados("entrada");
		pintarLineas($("#lineas"), st.lineas, "venta");
		pintarLineas($("#lineas2"), st.entrada, "entrada");
		st.flash = null;
		const u = st.lineas.reduce((a, l) => a + l.n, 0);
		$("#n-lin").textContent = u ? sprintf(_n("%d unit", "%d units", u, "dox-pos"), u) : "";
		$("#npane").textContent = u ? "· " + u : "";
		const u2 = st.entrada.reduce((a, l) => a + l.n, 0);
		$("#n-lin2").textContent = u2 ? sprintf(_n("%d unit", "%d units", u2, "dox-pos"), u2) : "";
		$("#npane2").textContent = u2 ? "· " + u2 : "";
		pintarSum();
		programarEnvio();
	}

	// ---------- envío: lo calcula la tienda con sus zonas ----------
	let envioTimer = null;
	function programarEnvio() {
		clearTimeout(envioTimer);
		envioTimer = setTimeout(cargarEnvio, 400);
	}
	async function cargarEnvio() {
		const state = $("#f-dep").value;
		const city = $("#f-ciu").value.trim();
		if (sinEnvio() || !st.lineas.length || (!state && !city)) {
			st.rates = [];
			st.rate = null;
			pintarEnvio();
			return;
		}
		try {
			const data = await post("shipping", { state: state, city: city, address: $("#f-dir").value.trim(), lines: st.lineas.map((l) => ({ id: l.vid, qty: l.n })) });
			st.rates = data.rates || [];
			st.rate = st.rates.find((r) => st.rate && r.id === st.rate.id) || st.rates[0] || null;
			if (st.rate) $("#f-env").value = Math.round(st.rate.cost);
			pintarEnvio();
			pintarSum();
		} catch (e) {
			if (e.message !== "sesion") { st.rates = []; pintarEnvio(); }
		}
	}
	function pintarEnvio() {
		$("#g-envio").hidden = sinEnvio();
		const box = $("#f-envio");
		box.innerHTML = "";
		if (!st.rates.length) {
			box.innerHTML = '<span class="hint">' + (st.lineas.length ? esc(sprintf(__("Choose the %s and the city to see the store\u2019s shipping options.", "dox-pos"), (cfg.state_label || "state").toLowerCase())) : esc(__("Add products and set the city to see the shipping.", "dox-pos"))) + "</span>";
			return;
		}
		st.rates.forEach((r) => {
			const b = chip(r.label + " · " + dinero(r.cost), st.rate && st.rate.id === r.id, () => {
				st.rate = r;
				$("#f-env").value = Math.round(r.cost);
				pintarEnvio();
				pintarSum();
			});
			box.appendChild(b);
		});
	}

	// Mientras se guarda, el botón lo dice y no se puede volver a tocar.
	function ocupar(btn, texto) {
		const antes = btn.textContent;
		btn.textContent = texto;
		btn.setAttribute("aria-busy", "true");
		return () => { btn.textContent = antes; btn.removeAttribute("aria-busy"); };
	}

	// ---------- registrar la venta o apartar ----------
	async function cerrar(hold) {
		if (st.ocupado || !st.lineas.length) return;
		if (hold && !$("#f-tel").value.trim()) {
			toast(__("A layaway needs the customer\u2019s WhatsApp number.", "dox-pos"));
			$("#f-tel").focus();
			return;
		}
		st.ocupado = true;
		pintarSum();
		const soltar = ocupar(hold ? $("#apartar") : $("#reg"), hold ? __("Putting on layaway\u2026", "dox-pos") : __("Recording\u2026", "dox-pos"));
		const conEnvio = !sinEnvio() && (st.rate || num($("#f-env").value) > 0);
		const payload = {
			ref: uuid(),
			hold: hold,
			lines: st.lineas.map((l) => ({ id: l.vid, qty: l.n })),
			channel: st.canal,
			payment: st.pago,
			discount: num($("#f-desc").value),
			note: $("#f-nota").value.trim(),
			customer: { name: $("#f-nom").value.trim(), phone: $("#f-tel").value.trim(), state: $("#f-dep").value, city: $("#f-ciu").value.trim(), address: $("#f-dir").value.trim() },
			shipping: conEnvio ? { label: st.rate ? st.rate.label : __("Shipping", "dox-pos"), method_id: st.rate ? st.rate.method_id : "dox_pos", instance_id: st.rate ? st.rate.instance_id : 0, cost: num($("#f-env").value) } : null,
		};
		const tipo = hold ? "apartado" : "venta";
		const resumen = resumenLineas(st.lineas);
		if (!navigator.onLine) {
			encolar(tipo, "orders", payload, resumen);
			limpiarPedido();
		} else {
			try {
				const data = await post("orders", payload);
				limpiarPedido();
				await Promise.all([refrescarStock(), cargarPedidos()]);
				if (hold) modalWhatsApp(data);
				else toast(sprintf(__("Sale recorded: order #%s. The stock has already gone down.", "dox-pos"), data.order.number));
			} catch (e) {
				if (e.red) {
					encolar(tipo, "orders", payload, resumen);
					limpiarPedido();
				} else if (e.message !== "sesion") {
					toast(e.message);
				}
			}
		}
		st.ocupado = false;
		soltar();
		pintarSum();
	}
	function resumenLineas(arr) {
		return arr.map((l) => {
			const d = st.vars[l.vid];
			return d ? d.p.name + " · " + d.v.label + (l.n > 1 ? " ×" + l.n : "") : "";
		}).filter(Boolean).join(" + ");
	}
	function limpiarPedido() {
		st.lineas = [];
		st.rate = null;
		st.rates = [];
		["#f-nom", "#f-tel", "#f-dir", "#f-nota", "#f-ciu"].forEach((s) => { $(s).value = ""; });
		$("#f-desc").value = "0";
		$("#f-env").value = "0";
		pintarTodo();
	}
	// Vuelve a preguntar por lo que está en pantalla, para que las existencias se vean al día.
	async function refrescarStock() {
		pa.at = 0; // El Panel se vuelve a pedir la próxima vez que se abra.
		if ($("#q").value.trim().length >= 2) await buscar("venta");
		else if (st.topOn) { st.topAt = 0; await mostrarTop(); }
		if ($("#q2").value.trim().length >= 2) await buscar("entrada");
		else if (st.catOn) { st.cat.at = 0; await mostrarCatalogo(); }
	}

	// ---------- pedidos ----------
	// Los de la caja y, si el ajuste lo dice, los de la página web (etiqueta "Página web" y de
	// dónde llegó el cliente) y los hechos a mano en WooCommerce ("Manual").
	async function cargarPedidos() {
		try {
			const d = await api("orders");
			st.pedidos = d.items || [];
			pintarPedidos();
		} catch (e) { /* se queda lo que había */ }
	}
	const SIN_PAGAR = ["sin_pagar", "por_confirmar", "fallido"]; // Estados de un pedido web que aún no pagó.
	// Los botones de un pedido según su estado; los mismos en la lista y en el detalle (ahí cierran el detalle antes).
	function botonesPedido(p, box, enModal) {
		const run = (fn) => () => { if (enModal) cerrarModal(); fn(); };
		const btn = (txt, sec, fn) => {
			const b = document.createElement("button");
			b.type = "button";
			b.className = "mini" + (sec ? " sec" : "");
			b.textContent = txt;
			b.onclick = run(fn);
			box.appendChild(b);
		};
		const wa = () => {
			if (!p.whatsapp) return;
			const a = document.createElement("a");
			a.className = "mini sec";
			a.href = p.whatsapp;
			a.target = "_blank";
			a.rel = "noopener";
			a.textContent = "WhatsApp";
			box.appendChild(a);
		};
		// Un pedido web sin pagar (o con el pago rechazado) no ha descontado inventario; uno "por confirmar", sí.
		const sinStock = p.status === "sin_pagar" || p.status === "fallido";
		if (p.status === "apartado") {
			btn(__("Paid", "dox-pos"), false, () => accion(p, "paid"));
			btn(__("Release", "dox-pos"), true, () => confirmar(sprintf(__("Release the layaway for %s? The product goes back to stock.", "dox-pos"), p.customer || __("this customer", "dox-pos")), () => accion(p, "release")));
			wa();
		} else if (SIN_PAGAR.includes(p.status)) {
			btn(__("Paid", "dox-pos"), false, () => confirmar(sprintf(sinStock ? __("Confirm the payment of order #%s? It moves to ready to ship and the stock goes down.", "dox-pos") : __("Confirm the payment of order #%s? It moves to ready to ship.", "dox-pos"), p.number), () => accion(p, "paid")));
			btn(__("Cancel", "dox-pos"), true, () => confirmar(sprintf(sinStock ? __("Cancel order #%s?", "dox-pos") : __("Cancel order #%s? The stock goes back.", "dox-pos"), p.number), () => accion(p, "cancel")));
			wa();
		} else if (p.status === "por_enviar") {
			btn(__("Mark as shipped", "dox-pos"), false, () => modalEnvio(p));
			btn(__("Cancel", "dox-pos"), true, () => confirmar(sprintf(__("Cancel order #%s? The stock goes back.", "dox-pos"), p.number), () => accion(p, "cancel")));
			wa();
		} else if (p.status === "enviado") {
			btn(__("Mark as delivered", "dox-pos"), false, () => accion(p, "delivered"));
			wa();
		}
	}
	// ----- el detalle de un pedido: se abre tocando el número o los productos -----
	async function verPedido(id) {
		modal("<h3>" + esc(__("Order", "dox-pos")) + '</h3><p class="mp">' + esc(__("Loading\u2026", "dox-pos")) + "</p>", "wide");
		try {
			const d = await api("orders/" + id);
			if ($("#modal").hidden) return; // lo cerraron mientras cargaba
			pintarDetalle(d);
		} catch (e) {
			if (e.message === "sesion") return;
			modal("<h3>" + esc(__("Order", "dox-pos")) + '</h3><p class="mp">' + esc(e.red ? __("No signal: the order could not be loaded.", "dox-pos") : e.message) + '</p><div class="mbtn"><button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>", "wide");
			$("#m-no").onclick = cerrarModal;
		}
	}
	// La ganancia en color según el margen: verde si llega al que la tienda quiere, ámbar si se queda
	// corta, rojo si es poca. Los umbrales vienen de los ajustes del asistente cuando está el Pro.
	function pildoraGanancia(profit, margin) {
		const good = +cfg.margin_good || 40, low = +cfg.margin_low || 20;
		const sin = margin === null || margin === undefined;
		const cls = sin ? "e" : (margin >= good ? "c" : (margin >= low ? "y" : "r"));
		return '<span class="tag ' + cls + '">' + dinero(profit) + (sin ? "" : " · " + margin + " %") + "</span>";
	}
	function pintarDetalle(d) {
		const cls = { apartado: "b", sin_pagar: "b", por_confirmar: "b", fallido: "b", por_enviar: "a", enviado: "e", entregado: "c", anulado: "d", reembolsado: "d" }[d.status] || "e";
		const web = d.origin !== "caja";
		let pago = __("Pays on delivery", "dox-pos");
		if (d.paid && !d.cod) pago = d.paid_at ? sprintf(__("Paid on %s", "dox-pos"), esc(d.paid_at)) : __("Paid", "dox-pos");
		else if (d.status === "apartado" || d.status === "sin_pagar" || d.status === "fallido") pago = __("Unpaid", "dox-pos");
		else if (d.status === "por_confirmar") pago = __("In progress", "dox-pos");
		else if (d.status === "anulado" || d.status === "reembolsado") pago = "";
		const items = (d.items_list || []).map((it) => "<li>" +
			'<span class="thumb">' + (it.image ? '<img src="' + esc(it.image) + '" alt="" loading="lazy">' : esc(iniciales(it.name || ""))) + "</span>" +
			'<div class="odi"><b>' + esc(it.name) + "</b>" +
			'<span class="sub">' + (it.sku ? '<span class="sku">' + esc(it.sku) + "</span> · " : "") + it.qty + " × " + dinero(it.price) + (it.stock !== null && it.stock !== undefined ? " · " + esc(it.shared && it.pool_name ? sprintf(__("%1$d left for all %2$s sizes", "dox-pos"), it.stock, it.pool_name) : sprintf(it.shared ? __("%d left for all sizes", "dox-pos") : __("%d left", "dox-pos"), it.stock)) : "") + (it.unit_cost !== null && it.unit_cost !== undefined ? " · " + esc(sprintf(__("cost %s", "dox-pos"), dinero(it.unit_cost))) : "") + "</span>" +
			'<span class="odlinks">' + (it.url ? '<a href="' + esc(it.url) + '" target="_blank" rel="noopener">' + esc(__("View in the store", "dox-pos")) + '</a>' : '<span class="sub">' + (it.exists ? __("Hidden in the store", "dox-pos") : __("It no longer exists", "dox-pos")) + "</span>") + (it.editable && hayProducto() ? ' · <button type="button" class="lnk" data-edit="' + it.product_id + '">' + esc(__("Edit", "dox-pos")) + '</button>' : "") + "</span>" +
			"</div>" +
			'<span class="num">' + dinero(it.total) + "</span></li>").join("");
		const dir = [d.address, d.address2, d.city].filter(Boolean).join(", ");
		let h = "<h3>" + esc(sprintf(__("Order #%s", "dox-pos"), d.number)) + ' <span class="tag ' + cls + '">' + esc(d.label) + "</span></h3>";
		h += '<p class="mp">' + esc(d.created) + " · " + (web ? esc(d.origin_label) + (d.source ? " " + esc(sprintf(__("(from %s)", "dox-pos"), d.source)) : "") : esc(d.channel) + " · " + esc(__("Register", "dox-pos")) + (d.seller ? " · " + esc(d.seller) : "")) + (d.demo ? " · " + esc(__("demo", "dox-pos")) : "") + "</p>";
		h += '<div class="od">';
		h += "<section><h4>" + esc(__("Products", "dox-pos")) + "</h4>" + (items ? '<ul class="odl">' + items + "</ul>" : '<p class="sub">' + esc(__("No products.", "dox-pos")) + "</p>");
		h += '<table class="tot"><tbody><tr><td>' + esc(__("Subtotal", "dox-pos")) + "</td><td>" + dinero(d.subtotal) + "</td></tr>" +
			(d.discount ? "<tr><td>" + esc(__("Discount", "dox-pos")) + "</td><td>−" + dinero(d.discount) + "</td></tr>" : "") +
			(d.shipping_total || d.shipping_method ? "<tr><td>" + esc(__("Shipping", "dox-pos")) + (d.shipping_method ? " · " + esc(d.shipping_method) : "") + "</td><td>" + dinero(d.shipping_total) + "</td></tr>" : "") +
			'<tr class="t"><td>' + esc(__("Total", "dox-pos")) + "</td><td>" + dinero(d.total) + "</td></tr>" +
			// La pérdida: lo que ese pedido costó de más (un envío más caro de lo cobrado, un imprevisto). Solo quien administra.
			(d.loss !== undefined ? '<tr class="loss"><td>' + esc(__("Loss", "dox-pos")) + (d.loss_note ? ' <span class="sub">· ' + esc(d.loss_note) + "</span>" : "") + "</td><td>" + (d.loss ? '<span class="tag r">−' + dinero(d.loss) + '</span><button type="button" class="lnk" id="m-loss">' + esc(__("Edit", "dox-pos")) + "</button>" : '<button type="button" class="mini" id="m-loss">' + esc(__("Note a loss", "dox-pos")) + "</button>") + "</td></tr>" : "") +
			// La ganancia, para quien administra, en las ventas hechas: con el costo congelado al venderse (y la pérdida ya restada), en color según el margen.
			(d.cost !== undefined && ["por_enviar", "enviado", "entregado"].includes(d.status) ? (d.profit !== null ? "<tr><td>" + esc(__("Profit", "dox-pos")) + "</td><td>" + pildoraGanancia(d.profit, d.margin) + "</td></tr>" : "<tr><td>" + esc(__("Profit", "dox-pos")) + '</td><td><span class="sub">' + esc(sprintf(_n("no cost on %d line", "no cost on %d lines", d.cost_missing, "dox-pos"), d.cost_missing)) + "</span></td></tr>") : "") +
			"</tbody></table></section>";
		h += '<section><h4>' + esc(__("Customer", "dox-pos")) + '</h4><div class="kv">';
		h += "<span>" + esc(__("Name", "dox-pos")) + "</span><span>" + esc(d.customer || __("No name", "dox-pos")) + "</span>";
		if (d.phone) h += "<span>" + esc(__("Phone", "dox-pos")) + "</span><span>" + esc(d.phone) + (d.whatsapp ? ' · <a href="' + esc(d.whatsapp) + '" target="_blank" rel="noopener">WhatsApp</a>' : "") + "</span>";
		if (d.email) h += "<span>" + esc(__("Email", "dox-pos")) + '</span><span><a href="mailto:' + esc(d.email) + '">' + esc(d.email) + "</a></span>";
		if (dir) h += "<span>" + esc(__("Address", "dox-pos")) + "</span><span>" + esc(dir) + "</span>";
		if (d.note) h += "<span>" + esc(__("Note", "dox-pos")) + "</span><span>" + esc(d.note) + "</span>";
		h += "</div></section>";
		h += '<section><h4>' + esc(__("Payment and shipping", "dox-pos")) + '</h4><div class="kv">';
		h += "<span>" + esc(__("Payment", "dox-pos")) + "</span><span>" + esc(d.payment) + (pago ? " · " + pago : "") + "</span>";
		if (d.status === "apartado" && d.hold_until) h += "<span>" + esc(__("Layaway", "dox-pos")) + "</span><span>" + esc(sprintf(__("expires on %s", "dox-pos"), d.hold_until)) + "</span>";
		if (d.tracking) h += "<span>" + esc(__("Shipping", "dox-pos")) + "</span><span>" + (d.tracking_url ? '<a href="' + esc(d.tracking_url) + '" target="_blank" rel="noopener">' + esc(d.tracking) + "</a>" : esc(d.tracking)) + "</span>";
		if (d.completed_at) h += "<span>" + esc(__("Delivered", "dox-pos")) + "</span><span>" + esc(d.completed_at) + "</span>";
		h += "</div></section>";
		if (d.notes && d.notes.length) h += "<section><h4>" + esc(__("History", "dox-pos")) + '</h4><ul class="odn">' + d.notes.map((n) => '<li><span class="sub">' + esc(n.date) + "</span>" + esc(n.text) + "</li>").join("") + "</ul></section>";
		h += "</div>";
		h += '<div class="mbtn stick odb"><div class="oda"></div>' + (d.edit_url ? '<a class="mini sec" href="' + esc(d.edit_url) + '" target="_blank" rel="noopener">' + esc(__("Open in WooCommerce", "dox-pos")) + '</a>' : "") + '<button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>";
		modal(h, "wide");
		botonesPedido(d, $("#modal-card .oda"), true);
		$("#m-no").onclick = cerrarModal;
		if ($("#m-loss")) $("#m-loss").onclick = () => modalPerdida(d);
		$("#modal-card").querySelectorAll("[data-edit]").forEach((b) => { b.onclick = () => { cerrarModal(); editarDesdeLista(+b.dataset.edit); }; });
	}
	// ----- la tarjeta de un producto: se abre tocando su nombre en el asistente, el historial o las entradas, sin salir de ahí -----
	async function verProducto(id) {
		modal("<h3>" + esc(__("Product", "dox-pos")) + '</h3><p class="mp">' + esc(__("Loading\u2026", "dox-pos")) + "</p>", "wide");
		try {
			const d = await api("products/" + id + "/card");
			if ($("#modal").hidden) return; // lo cerraron mientras cargaba
			pintarProductoCard(d);
		} catch (e) {
			if (e.message === "sesion") return;
			modal("<h3>" + esc(__("Product", "dox-pos")) + '</h3><p class="mp">' + esc(e.red ? __("No signal: the product could not be loaded.", "dox-pos") : e.message) + '</p><div class="mbtn"><button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>", "wide");
			$("#m-no").onclick = cerrarModal;
		}
	}
	function pintarProductoCard(d) {
		const conTalla = d.variations.some((v) => v.talla);
		const rango = (a, b) => (a === b ? dinero(b) : sprintf(__("%1$s to %2$s", "dox-pos"), dinero(a), dinero(b)));
		let h = "<h3>" + esc(d.name) + ' <span class="tag ' + (d.status === "publish" ? "c" : "e") + '">' + esc(d.status_label) + "</span></h3>";
		h += '<div class="od"><div class="pc-top"><span class="thumb pc-img">' + (d.image_large || d.image ? '<img src="' + esc(d.image_large || d.image) + '" alt="">' : esc(iniciales(d.name))) + '</span><div class="kv">';
		if (d.sku) h += "<span>" + esc(__("Code", "dox-pos")) + '</span><span><span class="sku">' + esc(d.sku) + "</span></span>";
		h += "<span>" + esc(__("Price", "dox-pos")) + "</span><span>" + rango(d.price_min, d.price_max) + "</span>";
		if (d.cost !== undefined) { // El costo y lo que deja cada unidad: solo quien administra.
			let costo = d.cost === null ? '<span class="sub">' + esc(__("no cost", "dox-pos")) + "</span>" : (d.cost === "" ? rango(d.cost_min, d.cost_max) : dinero(d.cost));
			if (d.cost > 0 && d.price_max > 0 && d.price_min === d.price_max) costo += " · " + pildoraGanancia(d.price_max - d.cost, Math.round((d.price_max - d.cost) / d.price_max * 100));
			h += "<span>" + esc(__("Cost", "dox-pos")) + "</span><span>" + costo + "</span>";
		}
		if (d.categories && d.categories.length) h += "<span>" + esc(__("Category", "dox-pos")) + "</span><span>" + esc(d.categories.join(", ")) + "</span>";
		h += "<span>" + esc(__("Units", "dox-pos")) + "</span><span>" + (d.units === null ? '<span class="sub">' + esc(__("not tracked", "dox-pos")) + "</span>" : "<b>" + esc(sprintf(_n("%d unit", "%d units", d.units, "dox-pos"), d.units)) + "</b>" + (d.shared !== null ? ' <span class="sub">· ' + esc(__("shared by all sizes", "dox-pos")) + "</span>" : (d.pool_names && d.pool_names.length ? ' <span class="sub">· ' + esc(sprintf(__("%s: units shared among their sizes", "dox-pos"), d.pool_names.join(", "))) + "</span>" : ""))) + "</span>";
		h += "</div></div>";
		if (d.variations.length > 1 || (d.variations.length === 1 && d.variations[0].talla)) {
			h += '<table class="tot pc-var"><thead><tr><th>' + esc(conTalla ? __("Size", "dox-pos") : __("Option", "dox-pos")) + "</th><th>" + esc(__("Code", "dox-pos")) + '</th><th class="num">' + esc(__("Units", "dox-pos")) + "</th></tr></thead><tbody>";
			d.variations.forEach((v) => {
				const st = v.stock === null ? (v.status === "outofstock" ? __("out of stock", "dox-pos") : __("no limit", "dox-pos")) : (v.shared ? (v.pool_name ? sprintf(__("%1$d for all %2$s sizes", "dox-pos"), v.stock, v.pool_name) : sprintf(__("%d for all sizes", "dox-pos"), v.stock)) : String(v.stock));
				h += "<tr" + (v.stock === 0 || v.status === "outofstock" ? ' class="off"' : "") + "><td>" + esc(v.label) + "</td><td>" + (v.sku ? '<span class="sku">' + esc(v.sku) + "</span>" : "") + '</td><td class="num">' + esc(st) + "</td></tr>";
			});
			h += "</tbody></table>";
		}
		if (d.description) h += '<p class="sub pc-desc">' + esc(d.description) + "</p>";
		h += "</div>";
		h += '<div class="mbtn stick odb"><div class="oda">' + (d.editable && hayProducto() ? '<button type="button" class="mini" id="m-edit">' + esc(__("Edit", "dox-pos")) + "</button>" : "") + (d.url ? '<a class="mini sec" href="' + esc(d.url) + '" target="_blank" rel="noopener">' + esc(__("View in the store", "dox-pos")) + "</a>" : "") + '</div><button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>";
		modal(h, "wide");
		$("#m-no").onclick = cerrarModal;
		if ($("#m-edit")) $("#m-edit").onclick = () => { cerrarModal(); editarDesdeLista(d.id); };
	}
	function pintarPedidos() {
		const tb = $("#tped");
		tb.innerHTML = "";
		const pend = st.pedidos.filter((p) => p.status === "apartado" || p.status === "por_enviar" || p.status === "por_confirmar").length;
		$("#npend").textContent = pend ? "· " + pend : "";
		$("#ped-empty").hidden = !!st.pedidos.length;
		st.pedidos.forEach((p) => {
			const tr = document.createElement("tr");
			if (p.status === "anulado" || p.status === "reembolsado") tr.className = "off";
			const cls = { apartado: "b", sin_pagar: "b", por_confirmar: "b", fallido: "b", por_enviar: "a", enviado: "e", entregado: "c", anulado: "d", reembolsado: "d" }[p.status] || "e";
			const web = p.origin !== "caja";
			let pago = __("Pays on delivery", "dox-pos");
			if (p.paid && !p.cod) pago = __("Paid", "dox-pos");
			else if (p.status === "apartado" || p.status === "sin_pagar" || p.status === "fallido") pago = __("Unpaid", "dox-pos");
			else if (p.status === "por_confirmar") pago = __("In progress", "dox-pos");
			else if (p.status === "anulado" || p.status === "reembolsado") pago = "";
			// El canal: en la caja, por dónde entró la venta y quién la registró; en la web, la etiqueta y de dónde llegó el cliente.
			const canal = web
				? '<span class="tag w">' + esc(p.origin_label) + "</span>" + (p.source ? '<br><span class="sub">' + esc(sprintf(__("from %s", "dox-pos"), p.source)) + "</span>" : "")
				: esc(p.channel) + '<br><span class="sub">' + esc(__("Register", "dox-pos")) + (p.seller ? " · " + esc(p.seller) : "") + "</span>";
			tr.innerHTML =
				'<td class="num">' + (p.id ? '<button type="button" class="lnk" data-ver="' + p.id + '">#' + esc(p.number) + "</button>" : "#" + esc(p.number)) + '<br><span class="sub">' + esc(p.date) + "</span></td>" +
				'<td><span class="who">' + esc(p.customer || __("No name", "dox-pos")) + '</span><br><span class="sub">' + esc(p.city) + (p.phone ? " · " + esc(p.phone) : "") + "</span></td>" +
				'<td class="items"><button type="button" class="lnk items" data-ver="' + p.id + '">' + esc(p.items) + "</button>" + (p.note ? '<br><span class="sub">' + esc(p.note) + "</span>" : "") + "</td>" +
				"<td>" + canal + "</td>" +
				"<td>" + esc(p.payment) + '<br><span class="sub">' + pago + "</span></td>" +
				'<td class="num">' + dinero(p.total) + "</td>" +
				'<td><span class="tag ' + cls + '">' + esc(p.label) + "</span>" +
					(p.loss ? '<br><span class="tag r">' + esc(__("Loss", "dox-pos")) + " −" + dinero(p.loss) + "</span>" : "") +
					(p.status === "apartado" ? '<br><span class="sub">' + esc(sprintf(__("expires in %d h", "dox-pos"), p.hours_left)) + "</span>" : "") +
					(p.tracking ? '<br><span class="sub">' + (p.tracking_url ? '<a href="' + esc(p.tracking_url) + '" target="_blank" rel="noopener">' + esc(p.tracking) + "</a>" : esc(p.tracking)) + "</span>" : "") + "</td>";
			const td = document.createElement("td");
			td.className = "acts";
			botonesPedido(p, td, false);
			tr.appendChild(td);
			tb.appendChild(tr);
		});
	}
	async function accion(p, act, extra) {
		try {
			const r = await post("orders/" + p.id + "/" + act, extra || {});
			await Promise.all([cargarPedidos(), refrescarStock()]);
			emit("pedido", { id: p.id, act: act }); // Los añadidos se enteran (el asistente repinta sus pendientes).
			const s = r && ((r.order && r.order.ship) || r.ship); // La ruta devuelve {order}; el asistente, el pedido directo.
			if (act === "shipped" && s) {
				// Qué pasó con el aviso a la clienta, y el WhatsApp con la guía a un toque.
				let t = s.demo ? esc(__("This is a demo order: no emails are sent.", "dox-pos")) : (s.email ? esc(sprintf(s.sent ? __("An email with the tracking number and the tracking link reached %s.", "dox-pos") : __("The email to %s could not be sent.", "dox-pos"), s.email)) : esc(__("This order has no email address.", "dox-pos")));
				if (s.whatsapp) t += " " + esc(__("If you want, tell them on WhatsApp too: the message already carries the tracking number.", "dox-pos"));
				modal("<h3>" + esc(__("Marked as shipped", "dox-pos")) + '</h3><p class="mp">' + t + '</p><div class="mbtn">' + (s.whatsapp ? '<a class="go" href="' + esc(s.whatsapp) + '" target="_blank" rel="noopener">' + esc(__("Tell them on WhatsApp", "dox-pos")) + "</a>" : "") + '<button type="button" class="go alt" id="m-no">' + esc(__("Done", "dox-pos")) + "</button></div>");
				$("#m-no").onclick = cerrarModal;
				return;
			}
			const msgs = { paid: __("Payment confirmed. It is now ready to ship.", "dox-pos"), release: __("Layaway released. The product goes back to stock.", "dox-pos"), shipped: __("Marked as shipped.", "dox-pos"), delivered: __("Delivered. The order is closed.", "dox-pos"), cancel: __("Order cancelled. The stock goes back.", "dox-pos") };
			let msg = msgs[act];
			if (act === "cancel" && (p.status === "sin_pagar" || p.status === "fallido")) msg = __("Order cancelled.", "dox-pos");
			else if (act === "loss") msg = extra && extra.amount > 0 ? __("Loss noted.", "dox-pos") : __("Loss removed.", "dox-pos");
			toast(msg);
		} catch (e) {
			if (e.message !== "sesion") toast(e.message);
		}
	}

	// ---------- entradas ----------
	async function guardarEntrada() {
		if (st.ocupado || !st.entrada.length) return;
		st.ocupado = true;
		pintarSum();
		const soltar = ocupar($("#reg2"), "Guardando…");
		const payload = { ref: uuid(), lines: st.entrada.map((l) => ({ id: l.vid, qty: l.n, cost: l.c || "" })), supplier: $("#e-prov").value.trim(), invoice: $("#e-fac").value.trim(), date: $("#e-fec").value, note: $("#e-nota").value.trim() };
		const resumen = resumenLineas(st.entrada);
		const limpiar = () => {
			st.entrada = [];
			$("#e-nota").value = "";
			$("#e-fac").value = "";
			pintarTodo();
		};
		if (!navigator.onLine) {
			encolar("entrada", "entries", payload, resumen);
			limpiar();
		} else {
			try {
				const d = await registrarEntrada(payload);
				if (d) {
					limpiar();
					await Promise.all([refrescarStock(), cargarEntradas()]);
					const ch = (d.entry.cost_changes || []).length;
					toast(sprintf(_n("Entry saved. The stock went up by %d unit.", "Entry saved. The stock went up by %d units.", d.entry.units, "dox-pos"), d.entry.units) + (ch ? " " + sprintf(_n("The average cost changed on %d product.", "The average cost changed on %d products.", ch, "dox-pos"), ch) : ""));
				}
			} catch (e) {
				if (e.red) {
					encolar("entrada", "entries", payload, resumen);
					limpiar();
				} else if (e.message !== "sesion") {
					toast(e.message);
				}
			}
		}
		st.ocupado = false;
		soltar();
		pintarSum();
	}
	// Manda la entrada. Si esa factura ya estaba registrada (desde otro teléfono, por ejemplo),
	// la tienda lo dice y aquí se pregunta: con "sí" se vuelve a mandar insistiendo (force);
	// con "no" devuelve null y el formulario se queda como estaba.
	async function registrarEntrada(payload) {
		try {
			return await post("entries", payload);
		} catch (e) {
			if (e.code !== "dox_pos_factura_repetida") throw e;
			const otra = await preguntar(e.message + " " + __("Is this a different entry?", "dox-pos"), __("Yes, record it", "dox-pos"), __("No", "dox-pos"));
			if (!otra) return null;
			return post("entries", Object.assign({}, payload, { force: true }));
		}
	}
	async function cargarEntradas() {
		try {
			const d = await api("entries");
			st.entradas = d.items || [];
			pintarEntradas();
		} catch (e) { /* se queda lo que había */ }
	}
	function pintarEntradas() {
		const ul = $("#entradas");
		ul.innerHTML = st.entradas.length ? "" : '<li class="empty" style="padding:8px 2px">' + esc(__("No stock entries recorded yet.", "dox-pos")) + "</li>";
		st.entradas.forEach((e) => {
			const li = document.createElement("li");
			li.className = "lin" + (e.status !== "ok" ? " off" : "");
			// Cada prenda con su foto y su nombre, que abre la ficha; debajo, de dónde vino la entrada.
			// Quien no puede entrar a Productos las ve igual, pero sin abrir nada.
			const abre = !!cfg.products;
			const prendas = (e.lines && e.lines.length ? e.lines : []).map((l) => {
				const foto = '<span class="thumb sm">' + (l.image ? '<img src="' + esc(l.image) + '" alt="" loading="lazy">' : esc(iniciales(l.name || ""))) + "</span>";
				const txt = "<span>" + esc(l.name) + (l.qty > 1 ? ' <b>×' + l.qty + "</b>" : "") + "</span>";
				return abre
					? '<button type="button" class="epz" data-pid="' + (l.pid || l.id) + '">' + foto + txt + "</button>"
					: '<span class="epz">' + foto + txt + "</span>";
			}).join("");
			li.innerHTML =
				'<span class="n">' + (prendas ? '<span class="epl">' + prendas + "</span>" : esc(e.items)) + "<i>" + esc(e.date) + (e.supplier ? " · " + esc(e.supplier) : "") + (e.invoice ? " · " + esc(e.invoice) : "") + (e.user ? " · " + esc(e.user) : "") + (e.cost ? " · " + dinero(e.cost) : "") + (e.status !== "ok" ? " · " + esc(__("voided", "dox-pos")) : "") + "</i></span>" +
				'<span class="v">+' + e.units + "</span>";
			li.querySelectorAll("button.epz").forEach((b) => { b.onclick = () => verProducto(+b.dataset.pid); });
			if (e.status === "ok") {
				const b = document.createElement("button");
				b.type = "button";
				b.className = "undo";
				b.textContent = __("Void", "dox-pos");
				b.onclick = () => confirmar(sprintf(_n("Void this entry? %d unit is taken back.", "Void this entry? %d units are taken back.", e.units, "dox-pos"), e.units), async () => {
					try {
						await post("entries/" + e.id + "/cancel", {});
						await Promise.all([cargarEntradas(), refrescarStock()]);
						toast(__("Entry voided.", "dox-pos"));
					} catch (err) {
						if (err.message !== "sesion") toast(err.message);
					}
				});
				li.appendChild(b);
			}
			ul.appendChild(li);
		});
	}

	// ---------- cola sin señal: lo que no pudo salir se guarda en el teléfono y se reintenta ----------
	const COLA = "dox_pos_cola";
	function leerCola() {
		try { return JSON.parse(localStorage.getItem(COLA) || "[]"); } catch (e) { return []; }
	}
	function guardarCola(c) {
		try { localStorage.setItem(COLA, JSON.stringify(c)); } catch (e) { /* sin espacio: se pierde el aviso, no la venta en curso */ }
		pintarCola();
	}
	function encolar(tipo, path, payload, resumen) {
		const c = leerCola();
		c.push({ id: payload.ref, tipo: tipo, path: path, payload: payload, resumen: resumen, creado: Date.now() });
		guardarCola(c);
		toast(tipo === "entrada" ? __("No signal. The entry is kept on this phone and goes in on its own when the connection is back.", "dox-pos")
			: tipo === "apartado" ? __("No signal. The layaway is kept on this phone and goes in on its own when the connection is back.", "dox-pos")
			: __("No signal. The sale is kept on this phone and goes in on its own when the connection is back.", "dox-pos"));
	}
	function quitarDeCola(id) {
		guardarCola(leerCola().filter((x) => x.id !== id));
	}
	function pintarCola() {
		const c = leerCola();
		const bar = $("#cola");
		if (!c.length && navigator.onLine) { bar.hidden = true; return; }
		bar.hidden = false;
		const n = c.length;
		let txt = "<b>" + esc(navigator.onLine ? __("Waiting to be sent.", "dox-pos") : __("No signal.", "dox-pos")) + "</b> ";
		if (n) {
			txt += esc(sprintf(_n("%d record kept on this phone", "%d records kept on this phone", n, "dox-pos"), n)) + esc(navigator.onLine ? __(", sending\u2026", "dox-pos") : __("; they go in on their own when the connection is back.", "dox-pos"));
			if (navigator.onLine) txt += ' <button type="button" class="mini" id="cola-reintentar">' + esc(__("Send now", "dox-pos")) + "</button>";
		} else {
			txt += esc(__("Whatever you record is kept on this phone and goes in on its own when the connection is back.", "dox-pos"));
		}
		bar.innerHTML = txt;
		const b = $("#cola-reintentar");
		if (b) b.onclick = vaciarCola;
	}
	async function vaciarCola() {
		if (st.sincronizando || !navigator.onLine) { pintarCola(); return; }
		const c = leerCola();
		if (!c.length) { pintarCola(); return; }
		st.sincronizando = true;
		let hechos = 0;
		for (const item of c) {
			try {
				let d;
				try {
					d = await post(item.path, item.payload);
				} catch (e) {
					// La factura ya estaba registrada cuando llegó la conexión: se pregunta antes de descartar.
					if (e.code !== "dox_pos_factura_repetida") throw e;
					if (!(await preguntar(e.message + " " + __("Is this a different entry?", "dox-pos"), __("Yes, record it", "dox-pos"), __("No, discard it", "dox-pos")))) {
						quitarDeCola(item.id);
						toast(sprintf(__("Entry discarded: %s.", "dox-pos"), item.resumen));
						continue;
					}
					item.payload.force = true;
					d = await post(item.path, item.payload);
				}
				quitarDeCola(item.id);
				hechos++;
				if (item.tipo === "entrada") toast(sprintf(__("Entry saved: %s.", "dox-pos"), item.resumen));
				else if (item.tipo === "apartado") toast(sprintf(__("Layaway recorded: order #%s. The WhatsApp button is in Orders.", "dox-pos"), d.order.number));
				else toast(sprintf(__("Sale recorded: order #%s.", "dox-pos"), d.order.number));
			} catch (e) {
				if (e.red || e.message === "sesion") break; // sigue sin señal: se espera
				quitarDeCola(item.id);                        // la tienda lo rechazó (por ejemplo, ya no queda stock)
				toast(sprintf(__("%1$s could not be recorded: %2$s", "dox-pos"), item.resumen, e.message));
			}
		}
		st.sincronizando = false;
		pintarCola();
		if (hechos) { refrescarStock(); cargarPedidos(); cargarEntradas(); }
	}

	// ---------- chips, departamento y ciudad ----------
	function chip(label, pressed, fn) {
		const b = document.createElement("button");
		b.type = "button";
		b.className = "chip";
		b.textContent = label;
		b.setAttribute("aria-pressed", !!pressed);
		b.onclick = fn;
		return b;
	}
	function chips(el, lista, sel, cb) {
		el.innerHTML = "";
		lista.forEach((x) => {
			const par = Array.isArray(x) ? x : [x, x];
			el.appendChild(chip(par[1], sel === par[0], () => cb(par[0])));
		});
	}
	function pintarChips() {
		chips($("#f-canal"), CANALES.map((c) => c.name), st.canal, (x) => { st.canal = x; pintarChips(); pintarSum(); pintarEnvio(); programarEnvio(); });
		chips($("#f-pago"), PAGOS, st.pago, (x) => { st.pago = x; pintarChips(); });
	}
	function pintarDepartamentos() {
		const sel = $("#f-dep");
		const states = cfg.states || {};
		sel.innerHTML = '<option value="">' + esc(cfg.state_label || "Departamento") + "</option>" +
			Object.keys(states).map((k) => '<option value="' + esc(k) + '">' + esc(states[k]) + "</option>").join("");
		sel.onchange = () => { pintarCiudades(); programarEnvio(); };
	}
	// Las ciudades del departamento elegido, si el sitio tiene Colciudades.
	function pintarCiudades() {
		const lista = (cfg.cities || {})[$("#f-dep").value] || [];
		$("#ciudades").innerHTML = lista.map((c) => '<option value="' + esc(c) + '"></option>').join("");
	}

	// ---------- avisos y ventanas ----------
	// Los avisos se apilan abajo (aria-live los lee el lector de pantalla), duran 3,6 s y tocarlos los cierra.
	function toast(txt) {
		const box = $("#toasts");
		while (box.children.length >= 3) box.firstChild.remove();
		const t = document.createElement("div");
		t.className = "toast";
		t.textContent = txt;
		box.appendChild(t);
		let ido = false;
		const irse = () => {
			if (ido) return;
			ido = true;
			t.classList.add("out");
			setTimeout(() => t.remove(), 170);
		};
		t.onclick = irse;
		setTimeout(irse, 3600);
	}
	// La ventana entra desde el centro (CSS, @starting-style) y sale más rápido; Escape la cierra
	// y al cerrar el foco vuelve a donde estaba.
	let focoAntes = null;
	let pendiente = null; // la respuesta que espera "preguntar"
	function modal(html, cls) {
		const m = $("#modal");
		const card = $("#modal-card");
		clearTimeout(modalTimer); // si uno se estaba cerrando, el nuevo no se lleva su escondida
		responder(false); // si había una pregunta abierta, queda en "no"
		card.className = "card" + (cls ? " " + cls : "");
		card.innerHTML = html;
		m.classList.remove("out");
		focoAntes = document.activeElement;
		m.hidden = false;
		const f = card.querySelector("input") || card.querySelector("#m-no") || card.querySelector("button, a");
		if (f) f.focus();
	}
	function responder(ok) {
		if (!pendiente) return;
		const r = pendiente;
		pendiente = null;
		r(!!ok);
	}
	let modalTimer = 0;
	function cerrarModal(ok) {
		const m = $("#modal");
		if (m.hidden || m.classList.contains("out")) return;
		responder(ok);
		m.classList.add("out");
		modalTimer = setTimeout(() => {
			m.hidden = true;
			m.classList.remove("out");
			if (focoAntes && focoAntes.focus && document.contains(focoAntes)) focoAntes.focus();
		}, 150);
	}
	// Una pregunta de sí o no. Devuelve una promesa con la respuesta; cerrar la ventana
	// (Escape, tocar fuera) cuenta como "no".
	function preguntar(texto, si, no) {
		return new Promise((resolve) => {
			modal("<h3>" + esc(__("One moment", "dox-pos")) + '</h3><p class="mp">' + esc(texto) + '</p><div class="mbtn"><button type="button" class="go" id="m-ok">' + esc(si || __("Yes, go on", "dox-pos")) + '</button><button type="button" class="go alt" id="m-no">' + esc(no || __("No", "dox-pos")) + "</button></div>");
			pendiente = resolve;
			$("#m-ok").onclick = () => cerrarModal(true);
			$("#m-no").onclick = () => cerrarModal(false);
		});
	}
	function confirmar(texto, fn) {
		preguntar(texto).then((ok) => { if (ok) fn(); });
	}
	function modalWhatsApp(d) {
		modal("<h3>" + esc(sprintf(__("Layaway #%s", "dox-pos"), d.order.number)) + '</h3><p class="mp">' + esc(sprintf(__("The product is already reserved. WhatsApp opens with the message written: you only have to send it. If it is not paid within %d hours, it goes back to stock on its own.", "dox-pos"), cfg.hold_hours || 48)) + '</p><div class="burb">' + esc(d.message) + '</div><div class="mbtn"><a class="go" href="' + esc(d.whatsapp) + '" target="_blank" rel="noopener">' + esc(__("Open WhatsApp", "dox-pos")) + '</a><button type="button" class="go alt" id="m-no">' + esc(__("Close", "dox-pos")) + "</button></div>");
		$("#m-no").onclick = cerrarModal;
	}
	function modalEnvio(p) {
		const carriers = (cfg.carriers || []).map((c) => (typeof c === "string" ? c : c.name));
		const lista = carriers.length ? '<datalist id="transportadoras">' + carriers.map((c) => '<option value="' + esc(c) + '"></option>').join("") + "</datalist>" : "";
		const pista = carriers.length ? carriers.slice(0, 2).join(", ") + "…" : __("DHL, UPS\u2026", "dox-pos");
		// Qué le va a llegar a la clienta: el correo con la guía si tiene correo; si no, el WhatsApp listo.
		const aviso = p.email && cfg.ship_email ? '<p class="mp">' + esc(sprintf(__("When you mark it as shipped, %s gets an email with the carrier, the tracking number and the tracking link.", "dox-pos"), p.email)) + "</p>" : (p.phone ? '<p class="mp">' + esc(__("No email address: when you mark it as shipped, the WhatsApp message with the tracking number is ready for you.", "dox-pos")) + "</p>" : "");
		modal("<h3>" + esc(sprintf(__("Ship order #%s", "dox-pos"), p.number)) + '</h3><p class="mp">' + esc(p.customer || "") + (p.city ? " · " + esc(p.city) : "") + "</p>" + aviso + '<div class="field"><label for="m-car">' + esc(__("Carrier", "dox-pos")) + '</label><input id="m-car" list="transportadoras" placeholder="' + esc(pista) + '" autocomplete="off">' + lista + '</div><div class="field mt"><label for="m-gui">' + esc(__("Tracking number", "dox-pos")) + '</label><input id="m-gui" placeholder="' + esc(__("Optional", "dox-pos")) + '"></div><div class="mbtn"><button type="button" class="go" id="m-ok">' + esc(__("Mark as shipped", "dox-pos")) + '</button><button type="button" class="go alt" id="m-no">' + esc(__("Cancel", "dox-pos")) + "</button></div>");
		$("#m-ok").onclick = () => {
			const extra = { carrier: $("#m-car").value.trim(), tracking: $("#m-gui").value.trim() };
			cerrarModal();
			accion(p, "shipped", extra);
		};
		$("#m-no").onclick = cerrarModal;
	}
	// La pérdida de un pedido: se anota desde su detalle con el motivo, y se puede cambiar o quitar.
	function modalPerdida(d) {
		modal("<h3>" + esc(sprintf(__("Loss on order #%s", "dox-pos"), d.number)) + '</h3><p class="mp">' + esc(__("What this order cost you beyond the goods: a shipment that cost more than what was charged, a freight you refunded, a repair. It comes off the profit of this sale.", "dox-pos")) + '</p><div class="field"><label for="m-lamt">' + esc(__("How much", "dox-pos")) + '</label><input id="m-lamt" inputmode="numeric" placeholder="0" value="' + (d.loss ? esc(miles(Math.round(d.loss))) : "") + '"></div><div class="field mt"><label for="m-lnote">' + esc(__("Why", "dox-pos")) + '</label><input id="m-lnote" value="' + esc(d.loss_note || "") + '" placeholder="' + esc(__("The shipping cost more than what was charged", "dox-pos")) + '"></div><div class="mbtn"><button type="button" class="go" id="m-ok">' + esc(__("Save", "dox-pos")) + "</button>" + (d.loss ? '<button type="button" class="go alt" id="m-del">' + esc(__("Remove the loss", "dox-pos")) + "</button>" : "") + '<button type="button" class="go alt" id="m-no">' + esc(__("Cancel", "dox-pos")) + "</button></div>");
		const amt = $("#m-lamt");
		amt.addEventListener("input", () => formatearMiles(amt));
		const guardar = async (amount, note) => {
			cerrarModal();
			await accion(d, "loss", { amount: amount, note: note });
			verPedido(d.id); // De vuelta al detalle, ya con la pérdida.
		};
		$("#m-ok").onclick = () => {
			const n = num(amt.value);
			if (n <= 0) { amt.focus(); return; }
			guardar(n, $("#m-lnote").value.trim());
		};
		if ($("#m-del")) $("#m-del").onclick = () => guardar(0, "");
		$("#m-no").onclick = () => { cerrarModal(); verPedido(d.id); };
		amt.focus();
	}
	function uuid() {
		return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : "r" + Date.now().toString(16) + Math.random().toString(16).slice(2);
	}

	// ---------- nuevo producto: fotos a WebP, tallas y colores, y el código como lo arma la tienda ----------
	// Solo lo ven administradores y gerentes (la pestaña no existe para el rol Caja). El formulario
	// (categorías, tallas, colores) se pide a la tienda la primera vez que se abre la pestaña.
	// A prueba de errores: el aviso se queda en el campo que falla; las categorías van por grupos y
	// cada una enseña solo sus tallas; los colores, los más usados; las unidades empiezan vacías;
	// antes de crear hay un repaso con lo que se va a crear; y lo escrito se guarda en el teléfono
	// por si la página se recarga o se cae la sesión.
	const pr = {
		cargado: false, abriendo: null, form: null,
		grupos: {}, totales: {}, legacy: false, legacyTotal: 0, // Qué tallas comparten unidades (clave color|talla => grupo 1, 2...) y las unidades de cada bolsa.
		fotos: [],          // [{uid, file, estado: cola|subiendo|ok|error, id, url, color, kb, error}]
		cats: [],           // ids de categoría elegidos
		colores: [],        // [{key, id, name, hex}]; id 0 = color nuevo (key "n:nombre")
		tallas: [],         // ids de talla elegidos
		qty: {},            // "colorKey|tallaId" -> unidades (sin valor = 0)
		manual: false,      // el código lo escribió la persona: no se vuelve a sugerir
		tallasTocadas: false, // las tallas las eligió la persona: la categoría ya no las cambia
		skuOk: undefined,   // lo que dijo la tienda del código escrito
		subiendo: false, creando: false,
		modo: "nuevo",      // nuevo | editar
		edit: null,         // el producto cargado para editar (lo que dio products/{id})
		ppage: 1,           // página de la lista de productos en "Editar uno"
		grupo: null,        // el grupo de categorías desplegado
		masColores: false,  // enseñar todos los colores, no solo los más usados
		masTallas: false,   // enseñar todas las tallas, no solo las de la categoría
		dup: null,          // un producto que ya se llama así: {id, sku, name}
		restaurando: false, // mientras se vuelve a cargar el borrador no se guarda otra vez
	};
	const hayProducto = () => !!$("#t-producto");
	let uidN = 0;

	// Se guarda la promesa: si la pestaña y un enlace a una ficha piden el formulario a la vez
	// (pasa al llegar desde el correo), se pide una sola vez y los dos esperan lo mismo.
	async function abrirProducto() {
		if (pr.cargado) return;
		if (!pr.abriendo) {
			pr.abriendo = (async () => {
				try {
					pr.form = await api("products/form");
					pr.cargado = true;
					pintarProducto();
					ofrecerBorrador();
				} catch (e) {
					if (e.message !== "sesion") toast(sprintf(__("The form could not be loaded: %s", "dox-pos"), e.message));
				} finally {
					pr.abriendo = null;
				}
			})();
		}
		return pr.abriendo;
	}
	function pintarProducto() {
		pintarFotos();
		pintarCategorias();
		pintarColores();
		pintarTallas();
		pintarCantidades();
		pintarResumenProducto();
	}

	// Fotos: se suben de una en una nada más elegirlas (la tienda las convierte a WebP) mientras se
	// sigue rellenando el resto. La primera es la principal; tocar otra la pone de primera.
	function pintarFotos() {
		const box = $("#p-fotos");
		box.innerHTML = "";
		pr.fotos.forEach((f, i) => {
			const d = document.createElement("div");
			d.className = "foto" + (f.estado === "subiendo" ? " subiendo" : "");
			const ph = () => {
				const s = document.createElement("span");
				s.className = "ph";
				s.textContent = f.ext || __("PHOTO", "dox-pos");
				d.prepend(s);
			};
			if (f.url) {
				const im = document.createElement("img");
				im.alt = "";
				im.src = f.url;
				im.onerror = () => { im.remove(); ph(); }; // HEIC en Chrome no se ve hasta que vuelve convertida
				d.appendChild(im);
			} else ph();
			if (i === 0) {
				const b = document.createElement("span");
				b.className = "badge";
				b.textContent = "Principal";
				d.appendChild(b);
			}
			const x = document.createElement("button");
			x.type = "button";
			x.className = "x";
			x.setAttribute("aria-label", __("Remove the photo", "dox-pos"));
			x.textContent = "×";
			x.onclick = (e) => { e.stopPropagation(); quitarFoto(f); };
			d.appendChild(x);
			if (f.estado !== "ok") {
				const s = document.createElement("span");
				s.className = "st" + (f.estado === "error" ? " err" : "");
				s.textContent = f.estado === "error" ? (f.error || __("Could not upload", "dox-pos")) + " · " + __("tap to try again", "dox-pos") : (f.estado === "subiendo" ? __("Converting\u2026", "dox-pos") : __("Queued", "dox-pos"));
				d.appendChild(s);
			} else if (pr.colores.length) {
				const sel = document.createElement("select");
				sel.setAttribute("aria-label", __("Which color the photo is for", "dox-pos"));
				sel.innerHTML = '<option value="">' + esc(__("All colors", "dox-pos")) + "</option>" + pr.colores.map((c) => '<option value="' + esc(c.key) + '"' + (f.color === c.key ? " selected" : "") + ">" + esc(c.name) + "</option>").join("");
				sel.onclick = (e) => e.stopPropagation();
				sel.onchange = () => { f.color = sel.value; programarBorrador(); };
				d.appendChild(sel);
			} else if (f.kb) {
				const s = document.createElement("span");
				s.className = "st ok";
				s.textContent = "WebP · " + f.kb + " KB";
				d.appendChild(s);
			}
			d.onclick = () => {
				if (f.estado === "error") { f.estado = "cola"; pintarFotos(); procesarFotos(); return; }
				if (i > 0) { pr.fotos.splice(i, 1); pr.fotos.unshift(f); pintarFotos(); programarBorrador(); }
			};
			box.appendChild(d);
		});
		const add = document.createElement("button");
		add.type = "button";
		add.className = "foto foto-add";
		add.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/></svg><span>' + (pr.fotos.length ? esc(__("Another photo", "dox-pos")) : esc(__("Add photos", "dox-pos"))) + "</span>";
		add.onclick = () => $("#p-file").click();
		box.appendChild(add);
		const n = pr.fotos.length;
		$("#p-nfotos").textContent = n ? sprintf(_n("%d photo", "%d photos", n, "dox-pos"), n) : "";
	}
	function agregarArchivos(files) {
		Array.from(files || []).forEach((file) => {
			if (file.size > 200 * 1024 * 1024) { toast(sprintf(__("%s is larger than the server allows.", "dox-pos"), file.name)); return; } // El del servidor se mira al subir, ya reducida.
			const ext = (file.name.split(".").pop() || "").toUpperCase();
			const f = { uid: ++uidN, file: file, ext: ext.length <= 4 ? ext : __("PHOTO", "dox-pos"), estado: "cola", url: "", local: "", id: 0, color: "", kb: 0, error: "" };
			try { f.url = URL.createObjectURL(file); f.local = f.url; } catch (e) { /* sin vista previa */ }
			pr.fotos.push(f);
		});
		pintarFotos();
		pintarResumenProducto();
		procesarFotos();
	}
	// Reduce la foto en el propio teléfono antes de mandarla. Un teléfono nuevo saca fotos de 24 o
	// 48 megapíxeles: son varios megas por foto y el servidor tarda segundos en descifrarlas. A la
	// medida con la que la tienda las va a guardar pesan unos cientos de kilobytes, así que suben
	// al instante aunque haya poca señal. Si el navegador no sabe leer el formato (un HEIC en
	// Android, por ejemplo) devuelve null y se manda la original: el servidor la reduce igual.
	async function reducirFoto(file, maxPx) {
		if (!maxPx || !file || !window.createImageBitmap || !HTMLCanvasElement.prototype.toBlob) return null;
		if (file.size <= 500 * 1024) return null; // Ya es pequeña: no hay nada que ganar.
		let bmp = null;
		try {
			bmp = await createImageBitmap(file, { imageOrientation: "from-image" }); // ya derecha, sin depender del EXIF
		} catch (e) {
			return null; // El navegador no lee ese formato.
		}
		try {
			const lado = Math.max(bmp.width, bmp.height);
			if (!lado || lado <= maxPx) return null;
			let cv = document.createElement("canvas");
			let w = bmp.width, h = bmp.height, src = bmp;
			// En dos pasos cuando la reducción es grande: de una sola pasada el navegador deja
			// dientes de sierra en los bordes finos.
			if (lado > maxPx * 3) {
				const e1 = (maxPx * 2) / lado;
				cv.width = w = Math.max(1, Math.round(bmp.width * e1));
				cv.height = h = Math.max(1, Math.round(bmp.height * e1));
				const c1 = cv.getContext("2d");
				if (!c1) return null;
				c1.imageSmoothingEnabled = true;
				c1.imageSmoothingQuality = "high";
				c1.drawImage(bmp, 0, 0, w, h);
				src = cv;
				cv = document.createElement("canvas");
			}
			const e2 = maxPx / Math.max(w, h);
			cv.width = Math.max(1, Math.round(w * e2));
			cv.height = Math.max(1, Math.round(h * e2));
			const ctx = cv.getContext("2d");
			if (!ctx) return null;
			ctx.imageSmoothingEnabled = true;
			ctx.imageSmoothingQuality = "high";
			ctx.drawImage(src, 0, 0, cv.width, cv.height);
			const aBlob = (tipo) => new Promise((res) => { try { cv.toBlob(res, tipo, 0.95); } catch (e) { res(null); } });
			let blob = await aBlob("image/webp");
			if (!blob || blob.type !== "image/webp") blob = await aBlob("image/jpeg"); // Safari viejo no escribe WebP.
			if (!blob || !blob.size || blob.size >= file.size) return null; // No mejoró: se manda la original.
			return new File([blob], (file.name || "foto").replace(/\.[^.]+$/, "") + (blob.type === "image/webp" ? ".webp" : ".jpg"), { type: blob.type });
		} catch (e) {
			return null;
		} finally {
			if (bmp && bmp.close) bmp.close();
		}
	}
	async function procesarFotos() {
		if (pr.subiendo) return;
		const f = pr.fotos.find((x) => x.estado === "cola");
		if (!f) { pintarResumenProducto(); return; }
		pr.subiendo = true;
		f.estado = "subiendo";
		pintarFotos();
		pintarResumenProducto();
		try {
			if (!f.lista) { f.lista = (await reducirFoto(f.file, cfg.max_px)) || f.file; }
			if (cfg.max_upload && f.lista.size > cfg.max_upload) { throw new Error(sprintf(__("%s is larger than the server allows.", "dox-pos"), f.file.name || "")); }
			const fd = new FormData();
			fd.append("file", f.lista, f.lista.name || "foto");
			fd.append("name", $("#p-nom").value.trim());
			const d = await api("products/image", { method: "POST", body: fd });
			if (pr.fotos.includes(f)) { // si la quitaron mientras subía, se borra del servidor
				f.id = d.id; f.kb = d.kb; f.estado = "ok";
				if (f.local) { try { URL.revokeObjectURL(f.local); } catch (e) { /* nada */ } f.local = ""; }
				f.url = d.url;
			} else {
				api("products/image/" + d.id, { method: "DELETE" }).catch(() => {});
			}
		} catch (e) {
			f.estado = "error";
			f.error = e.red ? __("No signal", "dox-pos") : (e.message === "sesion" ? __("Session expired", "dox-pos") : e.message);
		}
		pr.subiendo = false;
		pintarFotos();
		procesarFotos();
	}
	function quitarFoto(f) {
		pr.fotos = pr.fotos.filter((x) => x !== f);
		if (f.local) { try { URL.revokeObjectURL(f.local); } catch (e) { /* nada */ } }
		if (f.id && !f.existing) api("products/image/" + f.id, { method: "DELETE" }).catch(() => {}); // Una foto que ya era del producto se suelta al guardar.
		pintarFotos();
		pintarResumenProducto();
	}

	// Categorías: por grupos (la de arriba y las suyas), para no enseñar treinta y seis botones de golpe.
	// Se toca el grupo y salen las suyas; lo elegido queda en una línea aparte con su ×. La primera que se
	// elige sugiere las tallas que usan sus productos y el siguiente código libre de su prefijo.
	function gruposDeCategorias() {
		const grupos = [];
		((pr.form && pr.form.categories) || []).forEach((c) => {
			let g = grupos.find((x) => x.id === c.group_id);
			if (!g) { g = { id: c.group_id, name: c.group, cats: [] }; grupos.push(g); }
			g.cats.push(c);
		});
		return grupos;
	}
	function pintarCategorias() {
		const box = $("#p-cats");
		box.innerHTML = "";
		const cats = (pr.form && pr.form.categories) || [];
		if (!cats.length) { box.innerHTML = '<span class="hint">La tienda no tiene categorías con productos.</span>'; return; }
		const grupos = gruposDeCategorias();
		if (pr.grupo === null && pr.cats.length) { // Con una elegida y ningún grupo abierto, se abre el suyo.
			const c = cats.find((x) => x.id === pr.cats[0]);
			if (c) pr.grupo = c.group_id;
		}
		const heads = document.createElement("div");
		heads.className = "catheads";
		grupos.forEach((g) => {
			const dentro = g.cats.filter((c) => pr.cats.includes(c.id)).length;
			const ch = chip(g.name, false, () => { pr.grupo = pr.grupo === g.id ? null : g.id; pintarCategorias(); });
			ch.classList.add("grp");
			ch.setAttribute("aria-expanded", pr.grupo === g.id);
			if (dentro) ch.classList.add("has");
			if (pr.grupo === g.id) ch.classList.add("open");
			heads.appendChild(ch);
		});
		box.appendChild(heads);
		const g = grupos.find((x) => x.id === pr.grupo);
		if (g) {
			const row = document.createElement("div");
			row.className = "catg open";
			g.cats.forEach((c) => {
				const dentro = c.path && c.path !== c.group ? c.path.replace(c.group + " › ", "") + " › " : "";
				const label = c.id === c.group_id ? (g.cats.length > 1 ? sprintf(__("%s in general", "dox-pos"), c.name) : c.name) : dentro + c.name;
				const ch = chip(label, pr.cats.includes(c.id), () => toggleCat(c));
				ch.title = sprintf(_n("%d product", "%d products", c.count, "dox-pos"), c.count);
				row.appendChild(ch);
			});
			box.appendChild(row);
		}
		const sel = document.createElement("div");
		sel.className = "catsel";
		if (pr.cats.length) {
			const s = document.createElement("span");
			s.textContent = pr.cats.length === 1 ? "Elegida:" : "Elegidas:";
			sel.appendChild(s);
			pr.cats.forEach((id) => {
				const c = cats.find((x) => x.id === id);
				if (!c) return;
				const b = document.createElement("button");
				b.type = "button";
				b.className = "chip on";
				b.setAttribute("aria-pressed", "true");
				b.innerHTML = esc(c.name) + " <b>×</b>";
				b.setAttribute("aria-label", "Quitar " + c.name);
				b.onclick = () => toggleCat(c);
				sel.appendChild(b);
			});
		} else {
			sel.innerHTML = '<span class="hint">Toca un grupo y elige la categoría. Puedes marcar más de una.</span>';
		}
		box.appendChild(sel);
	}
	function toggleCat(c) {
		const i = pr.cats.indexOf(c.id);
		if (i >= 0) pr.cats.splice(i, 1); else pr.cats.push(c.id);
		if (i < 0 && pr.cats.length === 1) {
			if (!pr.tallasTocadas && c.sizes && c.sizes.length) pr.tallas = c.sizes.slice();
			if (!pr.manual && c.prefix) sugerirCodigo(c.prefix);
		}
		if (!pr.cats.length && !pr.tallasTocadas) pr.tallas = []; // Sin categoría, las tallas sugeridas se van con ella.
		pintarCategorias();
		pintarTallas();
		pintarCantidades();
		pintarResumenProducto();
	}

	// El código: la tienda propone el siguiente libre del prefijo de la categoría (VE82 -> VE83) y,
	// si se escribe otro, comprueba que esté libre mientras se escribe.
	let skuTimer = null, skuReq = 0;
	async function sugerirCodigo(prefix) {
		const n = ++skuReq;
		try {
			const d = await api("products/sku?prefix=" + encodeURIComponent(prefix));
			if (n !== skuReq || pr.manual) return;
			if (d.sku) { $("#p-sku").value = d.sku; pr.skuOk = true; estadoSku(sprintf(__("Free: the next one after %s.", "dox-pos"), prefix), "ok"); }
		} catch (e) { /* se escribe a mano */ }
		pintarResumenProducto();
	}
	function comprobarCodigo() {
		clearTimeout(skuTimer);
		const el = $("#p-sku");
		const v = el.value.trim().toUpperCase();
		if (el.value !== v) el.value = v;
		pr.manual = v !== "";
		pr.skuOk = undefined;
		if (!v) { estadoSku("", ""); pintarResumenProducto(); return; }
		estadoSku(__("Checking\u2026", "dox-pos"), "");
		skuTimer = setTimeout(async () => {
			const n = ++skuReq;
			try {
				const d = await api("products/sku?sku=" + encodeURIComponent(v));
				if (n !== skuReq) return;
				pr.skuOk = d.ok;
				estadoSku(d.ok ? __("Free.", "dox-pos") : d.message, d.ok ? "ok" : "bad");
			} catch (e) { estadoSku("", ""); }
			pintarResumenProducto();
		}, 350);
	}
	function estadoSku(t, cls) {
		const s = $("#p-sku-st");
		s.textContent = t;
		s.className = "pstatus " + cls;
	}

	// El nombre: si ya hay un producto que se llama igual, se avisa debajo (con el enlace para editarlo).
	let nomTimer = null, nomReq = 0;
	const plegar = (s) => String(s || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/\s+/g, " ").trim();
	function programarNombre() {
		clearTimeout(nomTimer);
		nomTimer = setTimeout(comprobarNombre, 500);
	}
	async function comprobarNombre() {
		const box = $("#p-nom-dup");
		const v = $("#p-nom").value.trim();
		pr.dup = null;
		box.hidden = true;
		box.innerHTML = "";
		if (pr.edit || v.length < 3) return;
		const n = ++nomReq;
		try {
			const d = await api("products/find?q=" + encodeURIComponent(v) + "&page=1");
			if (n !== nomReq || pr.edit) return;
			const igual = (d.items || []).find((p) => plegar(p.name) === plegar(v));
			if (!igual) return;
			pr.dup = { id: igual.id, sku: igual.sku || "", name: igual.name };
			box.innerHTML = esc(__("There is already a product called", "dox-pos")) + " <b>" + esc(igual.name) + "</b>" + (igual.sku ? " (" + esc(igual.sku) + ")" : "") + (igual.status !== "publish" ? ", " + esc(__("hidden", "dox-pos")) : "") + ". " + esc(__("If it is the same one, better ", "dox-pos"));
			const b = document.createElement("button");
			b.type = "button";
			b.className = "undo";
			b.textContent = __("edit it", "dox-pos");
			b.onclick = () => editarDesdeLista(igual.id);
			box.appendChild(b);
			box.appendChild(document.createTextNode("."));
			box.hidden = false;
		} catch (e) { /* sin aviso */ }
	}

	// Un número con puntos de miles mientras se escribe, para que se vean los ceros.
	const miles = (v) => (v ? String(v).replace(/\B(?=(\d{3})+(?!\d))/g, M.thousand) : "");
	function formatearMiles(el) {
		if (!el) return;
		const s = miles(num(el.value));
		if (el.value !== s) el.value = s;
	}
	function formatearPrecio() { formatearMiles($("#p-precio")); formatearMiles($("#p-costo")); }
	// El costo escrito en el formulario (0 si no hay campo o está vacío).
	const costoForm = () => ($("#p-costo") ? num($("#p-costo").value) : 0);
	// Qué costo se manda: al crear, el escrito; al editar, solo si cambió ('' = no tocar; 0 = quitarlo).
	function costoPayload() {
		if (!cfg.costs || !$("#p-costo")) return "";
		const v = costoForm();
		if (!pr.edit) return v || "";
		if (pr.edit.cost === "" && !v) return ""; // Las tallas cuestan distinto y no se escribió nada: se quedan como están.
		const was = pr.edit.cost === null || pr.edit.cost === undefined || pr.edit.cost === "" ? 0 : Math.round(Number(pr.edit.cost));
		return v === was ? "" : v;
	}
	// Lo que deja cada unidad con el precio y el costo escritos.
	function pintarMargen() {
		const el = $("#p-margen");
		if (!el) return;
		const precio = num($("#p-precio").value), costo = costoForm();
		el.className = "margen";
		if (!precio && !costo) { el.textContent = __("Enter a price and a cost.", "dox-pos"); return; }
		if (!costo) { el.textContent = pr.edit && pr.edit.cost === "" ? sprintf(__("The sizes cost different amounts: from %1$s to %2$s.", "dox-pos"), dinero(pr.edit.cost_min), dinero(pr.edit.cost_max)) : __("No cost: its sales do not count towards the profit.", "dox-pos"); return; }
		if (!precio) { el.textContent = __("The price is missing.", "dox-pos"); return; }
		const g = precio - costo;
		el.textContent = sprintf(__("%1$s per unit (%2$d %%)", "dox-pos"), dinero(g), Math.round(g / precio * 100)) + (g < 0 ? __(": sold at a loss", "dox-pos") : "");
		if (g < 0) el.className = "margen bad";
	}

	// Colores: los más usados (doce) y "Más colores…" para el resto; "Otro color…" crea uno nuevo con su tono.
	function pintarColores() {
		const box = $("#p-colores");
		box.innerHTML = "";
		$("#g-colores").hidden = !(pr.form && pr.form.has_color) || !!(pr.edit && pr.edit.type !== "variable");
		if (!pr.form || !pr.form.has_color || (pr.edit && pr.edit.type !== "variable")) return;
		if (pr.edit && !pr.edit.colors.length) { // Un producto que no varía por color no gana colores desde aquí.
			box.innerHTML = '<span class="hint">' + esc(__("This product has no colors. If it needs them, they have to be created in WooCommerce.", "dox-pos")) + "</span>";
			$("#p-ncol").textContent = "";
			return;
		}
		const dot = (hex) => { const i = document.createElement("i"); i.className = "dot"; i.style.background = hex; return i; };
		const all = pr.form.colors || [];
		const TOP = 12;
		const ver = pr.masColores || pr.edit || all.length <= TOP + 3 ? all : all.filter((c, i) => i < TOP || pr.colores.some((x) => x.key === String(c.id)));
		ver.forEach((c) => {
			const ch = chip(c.name, pr.colores.some((x) => x.key === String(c.id)), () => toggleColor({ key: String(c.id), id: c.id, name: c.name, hex: c.hex }));
			if (c.hex) ch.prepend(dot(c.hex));
			if (pr.edit && pr.edit.colors.some((x) => String(x.key) === String(c.id))) ch.disabled = true; // Los que ya tiene no se quitan desde aquí.
			box.appendChild(ch);
		});
		pr.colores.filter((c) => !c.id).forEach((c) => {
			const ch = chip(c.name, true, () => toggleColor(c));
			ch.prepend(dot(c.hex));
			box.appendChild(ch);
		});
		if (ver.length < all.length) {
			const mas = chip(sprintf(__("More colors (%d)\u2026", "dox-pos"), all.length - ver.length), false, () => { pr.masColores = true; pintarColores(); });
			mas.classList.add("plus");
			box.appendChild(mas);
		}
		const nuevo = chip(__("Another color\u2026", "dox-pos"), false, () => {
			const row = $("#p-nuevocolor");
			row.hidden = !row.hidden;
			if (!row.hidden) $("#p-color-nom").focus();
		});
		nuevo.classList.add("plus");
		box.appendChild(nuevo);
		$("#p-ncol").textContent = pr.colores.length ? pr.colores.map((c) => c.name).join(", ") : "";
	}
	function toggleColor(c) {
		const i = pr.colores.findIndex((x) => x.key === c.key);
		if (i >= 0) {
			pr.colores.splice(i, 1);
			pr.fotos.forEach((f) => { if (f.color === c.key) f.color = ""; });
		} else pr.colores.push(c);
		pintarColores();
		pintarFotos();
		pintarCantidades();
		pintarResumenProducto();
	}
	function añadirColorNuevo() {
		const name = $("#p-color-nom").value.trim();
		if (!name) { $("#p-color-nom").focus(); return; }
		const ya = (pr.form.colors || []).find((c) => c.name.toLowerCase() === name.toLowerCase());
		const c = ya ? { key: String(ya.id), id: ya.id, name: ya.name, hex: ya.hex } : { key: "n:" + name.toLowerCase(), id: 0, name: name, hex: $("#p-color-hex").value };
		if (!pr.colores.some((x) => x.key === c.key)) pr.colores.push(c);
		$("#p-color-nom").value = "";
		$("#p-nuevocolor").hidden = true;
		pintarColores();
		pintarFotos();
		pintarCantidades();
		pintarResumenProducto();
	}

	// Tallas: solo las que usa la categoría elegida (y "Otras tallas…" para el resto), por edad, con su
	// etiqueta corta si el tema la tiene. Las que marca la categoría se pueden quitar con un toque.
	function tallasDeCategoria() {
		const cats = (pr.form && pr.form.categories) || [];
		const ids = [];
		pr.cats.forEach((cid) => {
			const c = cats.find((x) => x.id === cid);
			((c && c.sizes) || []).forEach((s) => { if (!ids.includes(s)) ids.push(s); });
		});
		return ids;
	}
	function pintarTallas() {
		const box = $("#p-tallas");
		const hint = $("#p-tallas-hint");
		box.innerHTML = "";
		$("#g-tallas").hidden = !(pr.form && pr.form.has_size) || !!(pr.edit && pr.edit.type !== "variable");
		if (pr.edit && pr.edit.type === "variable" && !pr.edit.sizes.length) { // Un producto que no varía por talla no gana tallas desde aquí.
			box.innerHTML = '<span class="hint">' + esc(__("This product has no sizes. If it needs them, they have to be created in WooCommerce.", "dox-pos")) + "</span>";
			$("#p-ntal").textContent = "";
			hint.textContent = "";
			return;
		}
		const all = (pr.form && pr.form.sizes) || [];
		const deCat = tallasDeCategoria();
		const ver = pr.masTallas || pr.edit ? all : all.filter((t) => deCat.includes(t.id) || pr.tallas.includes(t.id));
		ver.forEach((t) => {
			const ch = chip(t.label || t.name, pr.tallas.includes(t.id), () => {
				pr.tallasTocadas = true;
				const i = pr.tallas.indexOf(t.id);
				if (i >= 0) pr.tallas.splice(i, 1); else pr.tallas.push(t.id);
				pintarTallas();
				pintarCantidades();
				pintarResumenProducto();
			});
			ch.title = t.name;
			if (pr.edit && pr.edit.sizes.includes(t.id)) ch.disabled = true; // Las que ya tiene no se quitan desde aquí.
			box.appendChild(ch);
		});
		if (ver.length < all.length) {
			const mas = chip(ver.length ? __("Other sizes\u2026", "dox-pos") : __("Choose sizes\u2026", "dox-pos"), false, () => { pr.masTallas = true; pintarTallas(); });
			mas.classList.add("plus");
			box.appendChild(mas);
		}
		const n = pr.tallas.length;
		$("#p-ntal").textContent = n ? sprintf(_n("%d size", "%d sizes", n, "dox-pos"), n) : __("one size", "dox-pos");
		if (pr.edit) hint.textContent = "";
		else if (!pr.cats.length && !n) hint.textContent = __("Choose the category and its sizes appear.", "dox-pos");
		else if (!n) hint.textContent = pr.cats.length && !deCat.length && !pr.masTallas ? __("This category does not use sizes: it stays one size.", "dox-pos") : __("No sizes: one size.", "dox-pos");
		else if (!pr.tallasTocadas) hint.textContent = __("Marked by the category. If the product does not come in all of them, tap the extra ones to remove them.", "dox-pos");
		else hint.textContent = "";
	}
	const tallasOrdenadas = () => ((pr.form && pr.form.sizes) || []).filter((t) => pr.tallas.includes(t.id));

	// Unidades: una casilla por talla y color. Empiezan vacías (0): lo que no se escribe no existe.
	const qKey = (ckey, sid) => ckey + "|" + sid;
	const cantidad = (ckey, sid) => { const v = pr.qty[qKey(ckey, sid)]; return v === undefined ? 0 : v; };
	const columnas = () => (pr.colores.length ? pr.colores : [{ key: "", name: __("Units", "dox-pos"), hex: "" }]);
	const filas = () => { const r = tallasOrdenadas(); return r.length ? r : [{ id: 0, name: __("One size", "dox-pos"), label: "" }]; };
	// Todas las casillas talla × color que hay ahora mismo en el formulario.
	const celdas = () => { const out = []; filas().forEach((r) => columnas().forEach((c) => out.push(qKey(c.key, r.id)))); return out; };
	// Las que salen del total del producto, sin las de tallas o colores que ya se quitaron.
	// Los grupos de tallas que comparten unidades: clave color|talla => grupo (1, 2...). Con un color y un grupo es el
	// total del producto; con varios colores o varios grupos, una bolsa por grupo (includes/pools.php).
	const grupoDe = (k) => pr.grupos[k] || 0;
	const compartidasVivas = () => { const keys = celdas(); return Object.keys(pr.grupos).filter((k) => keys.includes(k) && pr.grupos[k] > 0); };
	const gruposVivos = () => { const out = {}; compartidasVivas().forEach((k) => { out[k] = pr.grupos[k]; }); return out; };
	const pk = (ckey, g) => (g > 1 ? ckey + "#" + g : ckey); // La clave de la bolsa: la columna de color y, del segundo grupo en adelante, "#2"...
	const gruposDe = (ckey, rows) => [...new Set(rows.map((r) => grupoDe(qKey(ckey, r.id))).filter(Boolean))].sort((a, b) => a - b);
	// Las unidades compartidas de cada bolsa viva: {clave: n}.
	const totalesVivos = () => { const out = {}; const rows = filas(); columnas().forEach((c) => gruposDe(c.key, rows).forEach((g) => { out[pk(c.key, g)] = pr.totales[pk(c.key, g)] || 0; })); return out; };
	// Los grupos de una columna van seguidos (1, 2...): si uno se queda vacío, los demás se corren y sus unidades con ellos.
	function ordenarGrupos(ckey) {
		const rows = filas();
		const viejos = gruposDe(ckey, rows);
		if (viejos.every((g, i) => g === i + 1)) return;
		const mapa = {};
		viejos.forEach((g, i) => { mapa[g] = i + 1; });
		const totales = {};
		viejos.forEach((g) => { totales[pk(ckey, mapa[g])] = pr.totales[pk(ckey, g)] || 0; delete pr.totales[pk(ckey, g)]; });
		Object.assign(pr.totales, totales);
		rows.forEach((r) => { const k = qKey(ckey, r.id); if (pr.grupos[k]) pr.grupos[k] = mapa[pr.grupos[k]]; });
	}
	// Cada talla sale del total del producto o lleva las suyas, y se cambia con un toque. Vale al crear y al
	// editar: es como están la mayoría de los productos de una tienda que creció con el tiempo (unas tallas
	// del total, otras con las suyas), y WooCommerce lo permite talla por talla.
	function pintarCantidades() {
		const box = $("#p-qty");
		const keys = celdas();
		Object.keys(pr.grupos).forEach((k) => { if (!keys.includes(k)) delete pr.grupos[k]; });
		const variable = pr.edit ? pr.edit.type === "variable" : (pr.tallas.length > 0 || pr.colores.length > 0);
		const cols = columnas();
		const rows = filas();
		const multi = cols.length > 1; // Con varios colores, cada color lleva sus propias unidades compartidas (una bolsa por color).
		const pool = (key) => (pr.totales[key] ? miles(pr.totales[key]) : "\u2013");
		let algunas = 0, conGrupos = false;
		// Una tarjeta por color (una sola si el producto no tiene colores): sus tallas en filas, y debajo las unidades que
		// comparten, una caja por grupo. En el teléfono se apilan; en pantalla grande van en rejilla. Nada se desplaza a lo ancho.
		box.innerHTML = cols.map((c) => {
			ordenarGrupos(c.key);
			const gs = gruposDe(c.key, rows);
			const varios = gs.length > 1;
			if (varios) conGrupos = true;
			const comparten = rows.filter((r) => grupoDe(qKey(c.key, r.id)) > 0);
			algunas += comparten.length;
			let h = '<div class="qcard">';
			if (pr.colores.length) h += '<div class="qcard-h"><span class="qcard-n">' + (c.hex ? '<i class="dot" style="background:' + esc(c.hex) + '"></i>' : "") + esc(c.name) + "</span>" + (variable && rows.length > 1 ? '<label class="qshare qall"><input type="checkbox" data-all="' + esc(c.key) + '"' + (comparten.length === rows.length && !varios ? " checked" : "") + ">" + esc(__("All sizes share", "dox-pos")) + "</label>" : "") + "</div>";
			rows.forEach((r) => {
				const k = qKey(c.key, r.id);
				const g = grupoDe(k);
				let ctrl = "";
				if (variable) {
					// El menú de cada talla: lleva las suyas, comparte (en el grupo 1, 2...), o abre otro grupo que comparte aparte.
					const lista = gs.length ? gs : [1];
					let opts = '<option value="0"' + (g === 0 ? " selected" : "") + ">" + esc(__("Does not share", "dox-pos")) + "</option>";
					lista.forEach((x) => { opts += '<option value="' + x + '"' + (g === x ? " selected" : "") + ">" + esc(varios ? sprintf(__("Shares (group %d)", "dox-pos"), x) : __("Shares", "dox-pos")) + "</option>"; });
					if (gs.length) opts += '<option value="new">' + esc(__("Shares, another group", "dox-pos")) + "</option>";
					ctrl = '<select class="qsel" data-k="' + esc(k) + '" aria-label="' + esc(r.name + ", " + c.name) + '">' + opts + "</select>";
				}
				const v = pr.qty[k];
				h += '<div class="qrow"><span class="qsz" title="' + esc(r.name) + '">' + esc(r.label || r.name) + '</span><span class="qcell">' +
					(g ? '<span class="qpool" data-pool="' + esc(pk(c.key, g)) + '" title="' + esc(__("Shared units", "dox-pos")) + '">' + pool(pk(c.key, g)) + "</span>" : '<input inputmode="numeric" placeholder="0" data-c="' + esc(c.key) + '" data-s="' + r.id + '" value="' + (v === undefined ? "" : v) + '" aria-label="' + esc(r.name + ", " + c.name) + '">') +
					ctrl + "</span></div>";
			});
			gs.forEach((x) => {
				const key = pk(c.key, x);
				const n = rows.filter((r) => grupoDe(qKey(c.key, r.id)) === x).length;
				h += '<div class="qpoolbox"><label for="p-junto-' + esc(key) + '">' + esc(varios ? sprintf(__("Shared units · group %d", "dox-pos"), x) : __("Shared units", "dox-pos")) + '</label><input id="p-junto-' + esc(key) + '" data-pool="' + esc(key) + '" inputmode="numeric" placeholder="0" value="' + (pr.totales[key] ? miles(pr.totales[key]) : "") + '"></div>';
				h += '<p class="qhint">' + esc(sprintf(_n("%s takes these units.", "%s share these units: when one of them sells, they all go down.", n, "dox-pos"), nombresCompartidas(rows, [c], x))) + "</p>";
			});
			return h + "</div>";
		}).join("");
		// Debajo de las tarjetas: cómo hacer que compartan, o que cada color (y cada grupo) va por su cuenta.
		const hint = $("#p-qty-shared");
		const partes = [];
		if (variable && keys.length > 1 && !algunas) partes.push(__("Do several sizes sell from the same units? Choose \u201cShares\u201d on each one and write how many there are for all of them.", "dox-pos"));
		if (multi && algunas) partes.push(__("Each colour keeps its own shared units: selling a size only lowers the units of its colour.", "dox-pos"));
		if (conGrupos) partes.push(__("Each group keeps its own units too: selling a size only lowers the units of its group.", "dox-pos"));
		if (multi && algunas && pr.legacy) partes.push(sprintf(__("These sizes used to share one total for every colour (%d). From now on each colour keeps its own: check the numbers before saving.", "dox-pos"), pr.legacyTotal));
		hint.hidden = !partes.length;
		hint.textContent = partes.join(" ");
		box.querySelectorAll("input[inputmode]:not([data-pool])").forEach((inp) => {
			inp.addEventListener("input", () => { pr.qty[qKey(inp.dataset.c, inp.dataset.s)] = num(inp.value); pintarResumenProducto(); });
			inp.addEventListener("focus", () => inp.select());
		});
		box.querySelectorAll("input[data-pool]").forEach((inp) => {
			inp.oninput = () => { pr.totales[inp.dataset.pool] = num(inp.value); box.querySelectorAll('.qpool[data-pool="' + inp.dataset.pool + '"]').forEach((x) => { x.textContent = pool(inp.dataset.pool); }); pintarResumenProducto(); };
			inp.onfocus = () => inp.select();
		});
		box.querySelectorAll(".qsel").forEach((sel) => {
			sel.onchange = () => { // Lleva las suyas, comparte en un grupo, o abre otro grupo nuevo.
				const k = sel.dataset.k, ckey = k.slice(0, k.lastIndexOf("|"));
				if (sel.value === "new") pr.grupos[k] = Math.max(0, ...gruposDe(ckey, rows)) + 1;
				else if (sel.value === "0") delete pr.grupos[k];
				else pr.grupos[k] = +sel.value;
				pintarCantidades();
				pintarResumenProducto();
			};
		});
		box.querySelectorAll(".qshare input[data-all]").forEach((cb) => {
			const cnt = rows.filter((r) => grupoDe(qKey(cb.dataset.all, r.id)) > 0).length;
			cb.indeterminate = cnt > 0 && (cnt < rows.length || gruposDe(cb.dataset.all, rows).length > 1); // Unas sí y otras no, o varios grupos: a medias.
			cb.onchange = () => { // Todas las tallas de ese color comparten en un solo grupo, o ninguna.
				rows.forEach((r) => { const k = qKey(cb.dataset.all, r.id); if (cb.checked) pr.grupos[k] = 1; else delete pr.grupos[k]; });
				pintarCantidades();
				pintarResumenProducto();
			};
		});
	}
	// Las tallas (y el color, si hay más de uno) que comparten unidades, con nombre: "2-3 años, 3-4 años y 4-5 años".
	function nombresCompartidas(rows, cols, g) {
		const names = [];
		rows.forEach((r) => cols.forEach((c) => { const x = grupoDe(qKey(c.key, r.id)); if (x > 0 && (!g || x === g)) names.push((r.label || r.name) + (cols.length > 1 ? " " + c.name : "")); }));
		const first = names.slice(0, 4);
		const rest = names.length - first.length;
		if (rest > 0) return first.join(", ") + " " + sprintf(__("and %d more", "dox-pos"), rest);
		if (first.length < 2) return first.join("");
		return sprintf(__("%1$s and %2$s", "dox-pos"), first.slice(0, -1).join(", "), first[first.length - 1]);
	}
	function unidadesTotales() {
		// Las unidades de cada bolsa (por color y grupo), una vez; más lo que lleve cada talla suya.
		let u = 0;
		const rows = filas();
		columnas().forEach((c) => {
			rows.forEach((r) => { if (!grupoDe(qKey(c.key, r.id))) u += cantidad(c.key, r.id); });
			gruposDe(c.key, rows).forEach((g) => { u += pr.totales[pk(c.key, g)] || 0; });
		});
		return u;
	}

	// Qué falta, y en qué campo. El aviso se queda debajo del campo hasta que se arregla.
	function problemaProducto() {
		if (!$("#p-nom").value.trim()) return { msg: __("Give the product a name.", "dox-pos"), sel: "#p-nom" };
		if (!pr.cats.length) return { msg: __("Choose a category.", "dox-pos"), sel: "#p-cats" };
		if (num($("#p-precio").value) <= 0 && !(pr.edit && pr.edit.price === "")) return { msg: __("Set a price.", "dox-pos"), sel: "#p-precio" }; // Editando un producto con precios distintos por talla, vacío = no tocarlos.
		if (!pr.edit && pr.form && pr.form.sku_format !== "none" && !$("#p-sku").value.trim()) return { msg: __("The SKU is missing.", "dox-pos"), sel: "#p-sku" };
		if (!pr.edit && pr.skuOk === false) return { msg: __("That SKU is already taken.", "dox-pos"), sel: "#p-sku" };
		if (pr.fotos.some((f) => f.estado === "error")) return { msg: __("A photo could not be uploaded: tap it to try again, or remove it.", "dox-pos"), sel: "#p-fotos" };
		if (pr.fotos.some((f) => f.estado !== "ok")) return { msg: __("Wait for the photos to finish uploading.", "dox-pos"), sel: "#p-fotos" };
		return null;
	}
	function señalar(p) {
		limpiarSeñales();
		const el = $(p.sel);
		const cont = el.closest(".field") || el.closest(".grp") || el;
		cont.classList.add("bad");
		const e = document.createElement("p");
		e.className = "ferr";
		e.setAttribute("role", "alert");
		e.textContent = p.msg;
		if (cont.classList.contains("field")) cont.appendChild(e);
		else { const h = cont.querySelector("h4"); if (h) h.insertAdjacentElement("afterend", e); else cont.prepend(e); }
		cont.scrollIntoView({ block: "center", behavior: "smooth" });
		if (el.matches("input, textarea")) setTimeout(() => el.focus({ preventScroll: true }), 250);
	}
	function limpiarSeñales() {
		document.querySelectorAll("#p-form .bad").forEach((x) => x.classList.remove("bad"));
		document.querySelectorAll("#p-form .ferr").forEach((x) => x.remove());
	}
	const formularioTocado = () => !!($("#p-nom").value.trim() || pr.cats.length || pr.fotos.length || $("#p-desc").value.trim() || num($("#p-precio").value) || costoForm());
	function pintarResumenProducto() {
		const nt = pr.tallas.length, nc = pr.colores.length, u = unidadesTotales();
		const vars = (nt || 1) * (nc || 1);
		let que = (nt || nc)
			? sprintf(_n("%d variation", "%d variations", vars, "dox-pos"), vars) + (nt && nc ? " (" + sprintf(_n("%d size", "%d sizes", nt, "dox-pos"), nt) + " × " + sprintf(_n("%d color", "%d colors", nc, "dox-pos"), nc) + ")" : "")
			: __("One-size product", "dox-pos");
		if (pr.edit && pr.edit.type !== "variable") que = __("One-size product", "dox-pos");
		const precio = num($("#p-precio").value);
		const txtPrecio = precio > 0 ? dinero(precio) : (pr.edit && pr.edit.price === "" ? sprintf(__("from %1$s to %2$s", "dox-pos"), dinero(pr.edit.price_min), dinero(pr.edit.price_max)) : dinero(0));
		const costo = costoForm();
		const txtCosto = costo > 0 ? dinero(costo) + (precio > 0 ? " · " + sprintf(__("leaves %1$s (%2$d %%)", "dox-pos"), dinero(precio - costo), Math.round((precio - costo) / precio * 100)) : "") : (pr.edit && pr.edit.cost === "" ? sprintf(__("from %1$s to %2$s", "dox-pos"), dinero(pr.edit.cost_min), dinero(pr.edit.cost_max)) : "");
		$("#p-sum").innerHTML = "<div><span>" + esc(que) + "</span><span>" + esc(sprintf(_n("%d unit", "%d units", u, "dox-pos"), u)) + "</span></div>" +
			(cfg.costs && txtCosto ? "<div><span>" + esc(__("Cost", "dox-pos")) + "</span><span>" + txtCosto + "</span></div>" : "") +
			'<div class="tot"><span>' + esc(__("Price", "dox-pos")) + "</span><span>" + txtPrecio + "</span></div>";
		pintarMargen();
		$("#p-crear").textContent = pr.edit ? __("Save changes", "dox-pos") : __("Review and create", "dox-pos");
		$("#p-crear").disabled = pr.creando;
		$("#p-vaciar").hidden = !!pr.edit || !formularioTocado();
		limpiarSeñales();
		programarBorrador();
	}

	// El repaso: todo lo que se va a crear, en una ventana, con sus avisos (sin foto, sin unidades, un
	// precio raro, un nombre que ya existe, tallas que marcó la categoría). Se crea desde ahí.
	function modalRevisar() {
		return new Promise((resolve) => {
			const form = pr.form || {};
			const cats = (form.categories || []).filter((c) => pr.cats.includes(c.id)).map((c) => c.name);
			const rows = filas(), cols = columnas();
			const u = unidadesTotales();
			const precio = num($("#p-precio").value);
			const fotosOk = pr.fotos.filter((f) => f.estado === "ok");
			const avisos = [];
			if (!fotosOk.length) avisos.push(__("No photo: it will show a gray box in the store.", "dox-pos"));
			if (!u) avisos.push(__("No units: it will show as out of stock.", "dox-pos"));
			const pr0 = form.price_range || [0, 0];
			if (pr0[0] > 0 && (precio < pr0[0] / 2 || precio > pr0[1] * 2)) avisos.push(sprintf(__("The price is outside what is usual: in the store it goes from %1$s to %2$s. Check the zeros.", "dox-pos"), dinero(pr0[0]), dinero(pr0[1])));
			if (pr.dup) avisos.push(sprintf(__("There is already a product called %s.", "dox-pos"), pr.dup.name + (pr.dup.sku ? " (" + pr.dup.sku + ")" : "")));
			if (cfg.costs && costoForm() > precio) avisos.push(__("The cost is higher than the price: it would sell at a loss.", "dox-pos"));
			if (pr.tallas.length > 1 && !pr.tallasTocadas) avisos.push(sprintf(__("The category marked all %d sizes. If the product does not come in all of them, go back and remove the extra ones.", "dox-pos"), pr.tallas.length));
			let tabla = "";
			const vivas = new Set(compartidasVivas());
			if (pr.tallas.length || pr.colores.length) {
				// Cada bolsa: "Coral (grupo 2): 3 unidades compartidas entre 2 tallas".
				const bolsas = [];
				cols.forEach((c) => { const gs = gruposDe(c.key, rows); gs.forEach((g) => { const n = rows.filter((r) => grupoDe(qKey(c.key, r.id)) === g).length, tot = pr.totales[pk(c.key, g)] || 0; const quien = (cols.length > 1 ? c.name : "") + (gs.length > 1 ? (cols.length > 1 ? " " : "") + sprintf(__("(group %d)", "dox-pos"), g) : ""); bolsas.push(esc((quien ? quien + ": " : "") + sprintf(_n("%1$d shared unit between %2$d sizes", "%1$d shared units between %2$d sizes", tot, "dox-pos"), tot, n))); }); });
				if (bolsas.length) tabla = ' <span class="sub">' + bolsas.join(" · ") + "</span>";
				tabla += '<table class="revt"><thead><tr><th></th>' + cols.map((c) => "<th>" + esc(c.name) + "</th>").join("") + "</tr></thead><tbody>" +
					rows.map((r) => "<tr><th>" + esc(r.label || r.name) + "</th>" + cols.map((c) => { const k = qKey(c.key, r.id); return vivas.has(k) ? '<td class="zero">' + esc(gruposDe(c.key, rows).length > 1 ? sprintf(__("shared (group %d)", "dox-pos"), grupoDe(k)) : __("shared", "dox-pos")) + "</td>" : '<td class="' + (cantidad(c.key, r.id) ? "" : "zero") + '">' + cantidad(c.key, r.id) + "</td>"; }).join("") + "</tr>").join("") + "</tbody></table>";
			}
			const li = (k, v) => "<div><dt>" + k + "</dt><dd>" + v + "</dd></div>";
			modal("<h3>" + esc(__("Check before creating", "dox-pos")) + '</h3><dl class="rev">' +
				li(esc(__("Name", "dox-pos")), esc($("#p-nom").value.trim())) +
				li(esc(__("SKU", "dox-pos")), esc($("#p-sku").value.trim() || __("no SKU", "dox-pos"))) +
				li(esc(__("Price", "dox-pos")), dinero(precio)) +
				(cfg.costs ? li(esc(__("Cost", "dox-pos")), costoForm() ? dinero(costoForm()) + " · " + esc(sprintf(__("leaves %s per unit", "dox-pos"), dinero(precio - costoForm()))) : esc(__("No cost", "dox-pos"))) : "") +
				li(esc(__("Category", "dox-pos")), esc(cats.join(", "))) +
				(pr.colores.length ? li(esc(__("Colors", "dox-pos")), esc(pr.colores.map((c) => c.name).join(", "))) : "") +
				li(esc(__("Units", "dox-pos")), esc(sprintf(_n("%d unit", "%d units", u, "dox-pos"), u)) + tabla) +
				li(esc(__("Photos", "dox-pos")), fotosOk.length ? esc(sprintf(_n("%d photo", "%d photos", fotosOk.length, "dox-pos"), fotosOk.length)) : esc(__("No photo", "dox-pos"))) +
				li(esc(__("Store", "dox-pos")), esc($("#p-pub").checked ? __("It is published now", "dox-pos") : __("It stays hidden", "dox-pos"))) +
				"</dl>" +
				(avisos.length ? '<ul class="revwarn">' + avisos.map((a) => "<li>" + esc(a) + "</li>").join("") + "</ul>" : "") +
				'<div class="mbtn stick">' + (fotosOk.length
					? '<button type="button" class="go" id="m-ok">' + esc(__("Create product", "dox-pos")) + '</button><button type="button" class="go alt" id="m-no">' + esc(__("Back to review", "dox-pos")) + "</button>"
					: '<button type="button" class="go" id="m-foto">' + esc(__("Add a photo", "dox-pos")) + '</button><button type="button" class="go alt" id="m-ok">' + esc(__("Create without a photo", "dox-pos")) + '</button><button type="button" class="go alt" id="m-no">' + esc(__("Back", "dox-pos")) + "</button>") + "</div>");
			pendiente = resolve;
			$("#m-ok").onclick = () => cerrarModal(true);
			$("#m-no").onclick = () => cerrarModal(false);
			const f = $("#m-foto");
			if (f) f.onclick = () => { cerrarModal(false); $("#p-file").click(); };
		});
	}
	async function crearProducto() {
		if (pr.creando) return;
		const p = problemaProducto();
		if (p) { señalar(p); return; } // El aviso va en el campo; un aviso flotante encima del botón solo estorbaría el siguiente toque.
		if (!navigator.onLine) { toast(__("Creating a product needs a connection.", "dox-pos")); return; }
		if (!pr.edit) {
			if (!(await modalRevisar())) return;
		} else if (!pr.fotos.length && !(await preguntar(__("The product will have no photo. Save it like that?", "dox-pos"), __("Yes, save it", "dox-pos"), __("No", "dox-pos")))) return;
		pr.creando = true;
		pintarResumenProducto();
		const soltar = ocupar($("#p-crear"), pr.edit ? "Guardando…" : "Creando…");
		const rows = tallasOrdenadas();
		const qty = {};
		columnas().forEach((c) => { qty[c.key] = {}; filas().forEach((r) => { qty[c.key][r.id] = cantidad(c.key, r.id); }); });
		const payload = {
			ref: uuid(),
			name: $("#p-nom").value.trim(),
			price: num($("#p-precio").value),
			cost: costoPayload(),
			sku: $("#p-sku").value.trim(),
			categories: pr.cats.slice(),
			description: $("#p-desc").value.trim(),
			publish: $("#p-pub").checked,
			sizes: rows.map((t) => t.id),
			colors: pr.colores.map((c) => ({ key: c.key, id: c.id, name: c.name, hex: c.hex })),
			qty: qty,
			shared_cells: compartidasVivas(), // Las tallas que comparten unidades (clave color|talla)...
			shared_groups: gruposVivos(),     // ...en qué grupo está cada una (1, 2...)...
			pool_stock: totalesVivos(),       // ...y las unidades de cada bolsa (columna de color, y "#2" del segundo grupo en adelante).
			images: pr.fotos.filter((f) => f.id).map((f) => ({ id: f.id, color: f.color || "" })),
		};
		try {
			const d = await post(pr.edit ? "products/" + pr.edit.id : "products", payload);
			if (pr.edit) modalProductoGuardado(d.product);
			else { borrarBorrador(); modalProductoCreado(d.product); }
			limpiarProducto();
		} catch (e) {
			if (e.red) toast(__("No signal. Try again when the connection is back: the photos are already uploaded.", "dox-pos"));
			else if (e.message !== "sesion") toast(e.message);
		}
		pr.creando = false;
		soltar();
		pintarResumenProducto();
	}
	function modalProductoCreado(p) {
		const que = p.variations ? sprintf(_n("%d variation", "%d variations", p.variations, "dox-pos"), p.variations) : __("one size", "dox-pos");
		modal("<h3>" + esc(p.name) + '</h3><p class="mp">' + esc(p.status === "publish" ? __("It is already in the store", "dox-pos") : __("Saved hidden, not published", "dox-pos")) + " · " + esc(p.sku || __("no SKU", "dox-pos")) + " · " + esc(que) + " · " + esc(sprintf(_n("%d unit", "%d units", p.units, "dox-pos"), p.units)) + '.</p><div class="mbtn"><a class="go" href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(__("View in the store", "dox-pos")) + '</a><button type="button" class="go alt" id="m-fix">' + esc(__("Fix something", "dox-pos")) + '</button><button type="button" class="go alt" id="m-no">' + esc(__("Create another", "dox-pos")) + "</button></div>");
		$("#m-no").onclick = cerrarModal;
		$("#m-fix").onclick = () => { cerrarModal(); editarDesdeLista(p.id); };
	}
	function limpiarProducto() {
		pr.fotos.forEach((f) => { if (f.local) { try { URL.revokeObjectURL(f.local); } catch (e) { /* nada */ } } });
		pr.fotos = []; pr.cats = []; pr.colores = []; pr.tallas = []; pr.qty = {};
		pr.manual = false; pr.tallasTocadas = false; pr.skuOk = undefined; pr.grupos = {}; pr.totales = {}; pr.legacy = false; pr.legacyTotal = 0;
		pr.grupo = null; pr.masColores = false; pr.masTallas = false; pr.dup = null;
		["#p-nom", "#p-precio", "#p-costo", "#p-sku", "#p-desc", "#p-color-nom"].forEach((s) => { const el = $(s); if (el) el.value = ""; });
		$("#p-pub").checked = true;
		$("#p-nuevocolor").hidden = true;
		$("#p-nom-dup").hidden = true;
		estadoSku("", "");
		pr.edit = null;
		$("#p-sku").disabled = false;
		$("#p-editbar").hidden = true;
		$("#p-pub-text").textContent = __("Publish in the store now", "dox-pos");
		$("#p-pub-hint").textContent = __("Off: it is saved but hidden; you publish it later from Edit one.", "dox-pos");
		pintarProducto();
		pintarModo();
		if (pr.modo === "editar") buscarProductos(pr.ppage || 1);
	}

	// El borrador: lo escrito se guarda en el teléfono mientras se rellena y, si la página se recarga
	// (o cae la sesión, o el iPhone descarga la pestaña al ir a buscar una foto), al volver se ofrece
	// seguir con él. Las fotos ya subidas se conservan un día: después la tienda las borra.
	const BORRADOR = "dox_pos_borrador";
	let borradorTimer = null;
	function programarBorrador() {
		if (!hayProducto() || pr.edit || pr.restaurando || !pr.cargado) return;
		clearTimeout(borradorTimer);
		borradorTimer = setTimeout(guardarBorrador, 400);
	}
	function guardarBorrador() {
		if (pr.edit || pr.restaurando) return;
		const b = {
			ts: Date.now(), nombre: $("#p-nom").value, precio: $("#p-precio").value, costo: $("#p-costo") ? $("#p-costo").value : "", sku: $("#p-sku").value, manual: pr.manual,
			cats: pr.cats, colores: pr.colores, tallas: pr.tallas, tocadas: pr.tallasTocadas, qty: pr.qty,
			desc: $("#p-desc").value, pub: $("#p-pub").checked,
			fotos: pr.fotos.filter((f) => f.estado === "ok" && f.id).map((f) => ({ id: f.id, url: f.url, color: f.color, kb: f.kb })),
		};
		try {
			if (!formularioTocado()) { if (!pendiente) localStorage.removeItem(BORRADOR); return; }
			localStorage.setItem(BORRADOR, JSON.stringify(b));
		} catch (e) { /* sin espacio: se sigue sin borrador */ }
	}
	function leerBorrador() {
		try { const b = JSON.parse(localStorage.getItem(BORRADOR) || "null"); return b && typeof b === "object" ? b : null; } catch (e) { return null; }
	}
	function borrarBorrador() {
		try { localStorage.removeItem(BORRADOR); } catch (e) { /* nada */ }
	}
	async function ofrecerBorrador() {
		const b = leerBorrador();
		if (!b || pr.edit || pr.modo !== "nuevo" || formularioTocado()) return;
		const que = (b.nombre || "").trim() || __("one with no name yet", "dox-pos");
		const viejo = Date.now() - (b.ts || 0) > 20 * 3600 * 1000;
		if (await preguntar(sprintf(__("You have a product half done: %s. Carry on with it?", "dox-pos"), que), __("Carry on", "dox-pos"), __("Start over", "dox-pos"))) restaurarBorrador(b, viejo);
		else borrarBorrador();
	}
	function restaurarBorrador(b, sinFotos) {
		pr.restaurando = true;
		$("#p-nom").value = b.nombre || "";
		$("#p-precio").value = b.precio || "";
		if ($("#p-costo")) $("#p-costo").value = b.costo || "";
		$("#p-sku").value = b.sku || "";
		$("#p-desc").value = b.desc || "";
		$("#p-pub").checked = b.pub !== false;
		pr.manual = !!b.manual;
		pr.cats = (b.cats || []).slice();
		pr.colores = (b.colores || []).slice();
		pr.tallas = (b.tallas || []).slice();
		pr.tallasTocadas = !!b.tocadas;
		pr.qty = Object.assign({}, b.qty || {});
		pr.fotos = sinFotos ? [] : (b.fotos || []).map((f) => ({ uid: ++uidN, file: null, ext: "", estado: "ok", url: f.url, local: "", id: f.id, color: f.color || "", kb: f.kb || 0, error: "" }));
		pr.grupo = null;
		formatearPrecio();
		if ($("#p-sku").value) { pr.skuOk = undefined; comprobarCodigo(); } // Se vuelve a comprobar por si alguien usó ese código.
		pintarProducto();
		comprobarNombre();
		pr.restaurando = false;
		if (sinFotos && b.fotos && b.fotos.length) toast(__("The photos of that draft were already deleted because of the time passed: upload them again.", "dox-pos"));
	}
	function vaciarProducto() {
		confirmar(__("Start over? What you typed is erased.", "dox-pos"), () => {
			const fotos = pr.fotos.filter((f) => f.id && !f.existing);
			fotos.forEach((f) => api("products/image/" + f.id, { method: "DELETE" }).catch(() => {}));
			borrarBorrador();
			limpiarProducto();
		});
	}

	// ---------- editar un producto que ya existe ----------
	// El mismo formulario: se busca el producto, se carga y el botón pasa a "Guardar cambios". El código
	// y las tallas y colores que ya tiene quedan fijos (quitarlos sería borrar variaciones con historial).
	function pintarModo() {
		const editar = pr.modo === "editar";
		document.querySelectorAll("#pmode button").forEach((b) => b.setAttribute("aria-pressed", b.dataset.m === pr.modo));
		$("#p-buscar").hidden = !editar || !!pr.edit;
		$("#p-form").hidden = editar && !pr.edit;
		$("#p-foot").hidden = editar && !pr.edit;
		$("#p-newbar").hidden = editar;
	}
	function ponerModo(m) {
		if (pr.modo === m) return;
		pr.modo = m;
		if (pr.edit) limpiarProducto(); else pintarModo();
		if (m === "editar") { buscarProductos(pr.ppage || 1); $("#p-q").focus(); }
		if (m === "nuevo") ofrecerBorrador();
	}
	// La lista sale sola, por orden alfabético y en páginas de 20; con algo escrito, se filtra.
	let pqTimer = null, pqReq = 0;
	function programarBusquedaProducto() {
		clearTimeout(pqTimer);
		pqTimer = setTimeout(() => buscarProductos(1), 300);
	}
	async function buscarProductos(page) {
		const q = $("#p-q").value.trim();
		const n = ++pqReq;
		pr.ppage = page;
		if (!$("#p-res").children.length) $("#p-res").innerHTML = vacio("Buscando…");
		try {
			const d = await api("products/find?q=" + encodeURIComponent(q) + "&page=" + page);
			if (n !== pqReq) return;
			pintarBusquedaProductos(d.items || [], d);
		} catch (e) { if (e.message !== "sesion") toast(e.message); }
	}
	function pintarBusquedaProductos(items, d) {
		const ul = $("#p-res");
		ul.innerHTML = "";
		const pager = $("#p-pager");
		const total = d ? d.total : items.length, page = d ? d.page : 1, pages = d ? d.pages : 1;
		pager.hidden = !total || pages <= 1;
		if (!pager.hidden) {
			const desde = (page - 1) * 20 + 1, hasta = Math.min(total, page * 20);
			$("#p-pager-txt").textContent = desde + "-" + hasta + " de " + total;
			$("#p-prev").disabled = page <= 1;
			$("#p-next").disabled = page >= pages;
			$("#p-prev").onclick = () => { buscarProductos(page - 1); $("#t-producto .scroll").scrollTop = 0; };
			$("#p-next").onclick = () => { buscarProductos(page + 1); $("#t-producto .scroll").scrollTop = 0; };
		}
		if (!items.length) { ul.innerHTML = vacio($("#p-q").value.trim() ? __("Nothing matches. Try one word or the SKU.", "dox-pos") : __("The store has no products.", "dox-pos")); return; }
		items.forEach((p) => {
			const li = document.createElement("li");
			const b = document.createElement("button");
			b.type = "button";
			b.className = "prod";
			const estado = p.status === "publish" ? "" : '<i class="oculto">' + (p.status === "private" ? __("Hidden", "dox-pos") : __("Draft", "dox-pos")) + "</i>";
			const que = p.type === "variable" ? sprintf(_n("%d variation", "%d variations", p.variations, "dox-pos"), p.variations) : __("one size", "dox-pos");
			b.innerHTML = '<span class="n">' + esc(p.name) + "<i>" + esc(p.sku || __("no SKU", "dox-pos")) + " · " + esc(que) + "</i>" + estado + "</span>" + '<span class="p">' + dinero(p.price) + "</span>";
			b.prepend(miniatura(p));
			b.onclick = () => cargarProducto(p.id);
			li.appendChild(b);
			ul.appendChild(li);
		});
	}
	async function cargarProducto(id) {
		await abrirProducto();
		let d;
		try { d = await api("products/" + id); } catch (e) { if (e.message !== "sesion") toast(e.message); return; }
		if (pr.edit || pr.fotos.length) limpiarProducto();
		pr.modo = "editar";
		pr.edit = d;
		pr.grupos = Object.assign({}, d.shared_groups || {}); // Las tallas que hoy comparten unidades, con su grupo.
		if (!Object.keys(pr.grupos).length) (d.shared_cells || []).forEach((k) => { pr.grupos[k] = 1; });
		pr.totales = Object.assign({}, d.pool_stock || {}); // Las unidades compartidas por columna de color.
		pr.legacy = !!d.legacy_pool; // Varios colores con un solo total: al guardar, cada color lleva el suyo.
		pr.legacyTotal = d.shared === null || d.shared === undefined ? 0 : num(d.shared);
		pr.cats = (d.categories || []).slice();
		pr.tallas = (d.sizes || []).slice();
		pr.colores = (d.colors || []).map((c) => ({ key: String(c.key), id: c.id, name: c.name, hex: c.hex }));
		pr.qty = {};
		Object.keys(d.qty || {}).forEach((ck) => { Object.keys(d.qty[ck] || {}).forEach((sid) => { pr.qty[qKey(ck, sid)] = num(d.qty[ck][sid]); }); });
		pr.fotos = (d.images || []).map((im) => ({ uid: ++uidN, file: null, ext: "", estado: "ok", url: im.url || "", local: "", id: im.id, color: im.color || "", kb: 0, error: "", existing: true }));
		pr.manual = true; pr.tallasTocadas = true; pr.skuOk = undefined;
		pr.masColores = true; pr.masTallas = true; pr.grupo = null; pr.dup = null;
		$("#p-nom-dup").hidden = true;
		$("#p-nom").value = d.name || "";
		$("#p-precio").value = d.price === "" || d.price === null ? "" : String(d.price);
		if ($("#p-costo")) $("#p-costo").value = d.cost === "" || d.cost === null || d.cost === undefined ? "" : String(Math.round(d.cost));
		formatearPrecio();
		$("#p-sku").value = d.sku || "";
		$("#p-sku").disabled = true;
		estadoSku(d.sku ? __("The SKU is only changed in WooCommerce.", "dox-pos") : __("No SKU.", "dox-pos"), "");
		$("#p-desc").value = d.description || "";
		$("#p-pub").checked = d.status === "publish";
		$("#p-pub-text").textContent = __("Published in the store", "dox-pos");
		$("#p-pub-hint").textContent = __("Off: it stays hidden, not shown and not sold.", "dox-pos");
		const que = d.type === "variable" ? sprintf(_n("%d variation", "%d variations", d.variations, "dox-pos"), d.variations) : __("one size", "dox-pos");
		$("#p-edit-title").textContent = sprintf(__("Editing %1$s%2$s (%3$s)", "dox-pos"), d.sku ? d.sku + " · " : "", d.name, que);
		$("#p-edit-hint").textContent = d.price === "" ? sprintf(__("The sizes have different prices (from %1$s to %2$s): if you type a price, it goes to all of them.", "dox-pos"), dinero(d.price_min), dinero(d.price_max)) : __("Changes are saved with the button below.", "dox-pos");
		$("#p-edit-view").href = d.url || "#";
		$("#p-edit-view").title = d.status === "publish" ? __("It opens in another tab", "dox-pos") : __("It is hidden: you see it because you are signed in, the customer does not", "dox-pos");
		$("#p-editbar").hidden = false;
		pintarProducto();
		pintarModo();
		$("#t-producto .scroll").scrollTop = 0;
	}
	function modalProductoGuardado(p) {
		const que = p.variations ? sprintf(_n("%d variation", "%d variations", p.variations, "dox-pos"), p.variations) : __("one size", "dox-pos");
		modal("<h3>" + esc(__("Saved", "dox-pos")) + '</h3><p class="mp">' + esc(p.name) + " · " + esc(p.status === "publish" ? __("published", "dox-pos") : __("hidden", "dox-pos")) + " · " + esc(p.sku || __("no SKU", "dox-pos")) + " · " + esc(que) + " · " + esc(sprintf(_n("%d unit", "%d units", p.units, "dox-pos"), p.units)) + '.</p><div class="mbtn"><a class="go" href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(__("View in the store", "dox-pos")) + '</a><button type="button" class="go alt" id="m-no">' + esc(__("Done", "dox-pos")) + "</button></div>");
		$("#m-no").onclick = cerrarModal;
	}
	// Desde la lista de Vender o de Entró mercancía: "Editar este producto".
	async function editarDesdeLista(id) {
		const tab = document.querySelector('#tabs button[data-t="producto"]');
		if (tab) tab.click();
		pr.modo = "editar";
		pintarModo();
		await cargarProducto(id);
	}

	// ---------- historial: las ventas, la caja del día y los movimientos (kardex) ----------
	// Administradores y gerentes ven todo con su periodo (hoy, la semana, el mes o dos fechas);
	// el rol Caja ve solo sus ventas de hoy, sin periodo ni totales del negocio.
	const hi = { periodo: "hoy", desde: "", hasta: "", vista: "ventas", q: "", tipo: "", producto: 0, productoNombre: "", page: 1, req: 0 };
	const hayHistorial = () => !!$("#t-historial");
	const fechaISO = (d) => d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
	const hoyTienda = () => (cfg.today ? new Date(cfg.today + "T12:00:00") : new Date());
	function rangoHistorial() {
		const hoy = hoyTienda();
		if (hi.periodo === "semana") { const d = new Date(hoy); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return [fechaISO(d), fechaISO(hoy)]; } // desde el lunes
		if (hi.periodo === "mes") return [fechaISO(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), fechaISO(hoy)];
		if (hi.periodo === "fechas") return [hi.desde || fechaISO(hoy), hi.hasta || fechaISO(hoy)];
		return [fechaISO(hoy), fechaISO(hoy)];
	}
	const DIAS = () => [__("Sun", "dox-pos"), __("Mon", "dox-pos"), __("Tue", "dox-pos"), __("Wed", "dox-pos"), __("Thu", "dox-pos"), __("Fri", "dox-pos"), __("Sat", "dox-pos")];
	function diaBonito(iso) {
		const d = new Date(iso + "T12:00:00");
		return isNaN(d) ? iso : DIAS()[d.getDay()] + " " + d.getDate() + "/" + String(d.getMonth() + 1).padStart(2, "0");
	}
	function textoPeriodo() {
		const [a, b] = rangoHistorial();
		if (a === b) return hi.periodo === "hoy" ? sprintf(__("for today, %s", "dox-pos"), diaBonito(a)) : sprintf(__("for %s", "dox-pos"), diaBonito(a));
		return sprintf(__("from %1$s to %2$s", "dox-pos"), diaBonito(a), diaBonito(b));
	}
	function excelHistorial(que) {
		const [a, b] = rangoHistorial();
		let u = (cfg.url || "/caja/") + "?descargar=" + que + "&desde=" + a + "&hasta=" + b;
		if (que === "movimientos") {
			if (hi.q) u += "&q=" + encodeURIComponent(hi.q);
			if (hi.producto) u += "&producto=" + hi.producto;
			if (hi.tipo) u += "&tipo=" + hi.tipo;
		}
		return u;
	}
	const excelLink = (que) => '<a class="undo dl" href="' + esc(excelHistorial(que)) + '" download>' + esc(__("Download as Excel", "dox-pos")) + "</a>";
	// Las vistas del historial: las tres del núcleo y las que registren los añadidos con DoxPOS.vistaHistorial({id, cargar, pintar}).
	// Cada una dice cómo se carga con el periodo (desde, hasta) y cómo se pinta; su panel es el div #h-<id> de la plantilla.
	const vistasHist = {};
	function vistaHistorial(def) { vistasHist[def.id] = def; }
	async function cargarHistorial() {
		if (!hayHistorial()) return;
		const def = vistasHist[hi.vista];
		const box = $("#h-" + hi.vista);
		if (!def || !box) return;
		const [a, b] = rangoHistorial();
		const n = ++hi.req;
		Object.keys(vistasHist).forEach((k) => { const el = $("#h-" + k); if (el) el.hidden = k !== hi.vista; });
		$("#h-fil").hidden = hi.vista !== "mov";
		if (!box.dataset.listo) box.innerHTML = '<p class="empty">' + esc(__("Loading\u2026", "dox-pos")) + "</p>";
		box.style.opacity = ".55";
		try {
			const d = await def.cargar(a, b);
			if (n !== hi.req) return;
			box.dataset.listo = "1";
			def.pintar(d);
		} catch (e) {
			if (n === hi.req && e.message !== "sesion") box.innerHTML = '<p class="empty">' + esc(e.red ? __("No signal: the history is read from the store.", "dox-pos") : e.message) + "</p>";
		}
		if (n === hi.req) box.style.opacity = "";
	}
	const kpi = (v, l) => '<div class="kpi"><b>' + v + "</b><span>" + esc(l) + "</span></div>";
	function listaHist(titulo, arr, conUnidades) {
		if (!arr || !arr.length) return "";
		return '<div class="grp"><h4>' + esc(titulo) + '</h4><ul class="bl">' + arr.map((x) =>
			"<li><span>" + esc(x.label || x.name) + "</span><span>" + esc(sprintf(_n("%d sale", "%d sales", x.n, "dox-pos"), x.n)) + (conUnidades && x.units !== undefined ? " · " + esc(sprintf(_n("%d unit", "%d units", x.units, "dox-pos"), x.units)) : "") + " · <b>" + dinero(x.total) + "</b></span></li>"
		).join("") + "</ul></div>";
	}
	function filaPedidoHist(p, conCosto) {
		const vendido = ["por_enviar", "enviado", "entregado"].includes(p.status);
		const cls = { apartado: "b", sin_pagar: "b", por_confirmar: "b", fallido: "b", por_enviar: "a", enviado: "e", entregado: "c", anulado: "d", reembolsado: "d" }[p.status] || "e";
		const web = p.origin !== "caja";
		const canal = web ? '<span class="tag w">' + esc(p.origin_label) + "</span>" : esc(p.channel) + (p.seller ? '<br><span class="sub">' + esc(p.seller) + "</span>" : "");
		return '<tr class="' + (p.status === "anulado" || p.status === "reembolsado" ? "off" : "") + '"><td class="num">' + (p.id ? '<button type="button" class="lnk" data-ver="' + p.id + '">#' + esc(p.number) + "</button>" : "#" + esc(p.number)) + '<br><span class="sub">' + esc(p.date) + "</span></td>" +
			'<td><span class="who">' + esc(p.customer || __("No name", "dox-pos")) + '</span><br><span class="sub">' + esc(p.city) + "</span></td>" +
			'<td class="items">' + esc(p.items) + "</td><td>" + canal + "</td>" +
			"<td>" + esc(p.payment) + (p.cod && p.status !== "entregado" && p.status !== "anulado" ? '<br><span class="sub">' + esc(__("pays on delivery", "dox-pos")) + "</span>" : "") + "</td>" +
			'<td class="num">' + dinero(p.total) + (p.loss ? '<br><span class="tag r">' + esc(__("Loss", "dox-pos")) + " −" + dinero(p.loss) + "</span>" : "") + "</td>" +
			(conCosto ? '<td class="num">' + (vendido ? (p.profit !== null && p.profit !== undefined ? pildoraGanancia(p.profit, p.margin) : '<span class="sub">' + esc(__("no cost", "dox-pos")) + "</span>") : "") + "</td>" : "") +
			'<td><span class="tag ' + cls + '">' + esc(p.label) + "</span></td></tr>";
	}
	function pintarVentas(d) {
		const t = d.totals;
		const full = !!cfg.history_full;
		let h = '<p class="hsub">' + esc(full ? __("Sales", "dox-pos") : __("Your sales", "dox-pos")) + " " + esc(textoPeriodo()) + "</p>";
		const conCosto = !!d.costs; // Solo quien administra, con los costos encendidos.
		h += '<div class="kpis">' + kpi(dinero(t.sold), __("Sold", "dox-pos")) + kpi(t.orders, _n("Sale", "Sales", t.orders, "dox-pos")) + kpi(t.units, _n("Unit", "Units", t.units, "dox-pos")) + kpi(dinero(t.avg), __("Per sale", "dox-pos")) +
			(conCosto ? kpi(dinero(t.profit), __("Profit", "dox-pos") + (t.margin !== null ? " · " + t.margin + " %" : "")) : "") +
			(d.losses && t.loss > 0 ? kpi("−" + dinero(t.loss), sprintf(_n("Loss on %d sale", "Losses on %d sales", t.loss_n, "dox-pos"), t.loss_n)) : "") +
			(t.pending_n ? kpi(dinero(t.pending), sprintf(__("Not paid yet (%d)", "dox-pos"), t.pending_n)) : "") + "</div>";
		if (conCosto && t.no_cost_n) h += '<p class="hint">' + esc(sprintf(_n("%d sale does not have the cost of all its products, so it does not count towards the profit.", "%d sales do not have the cost of all their products, so they do not count towards the profit.", t.no_cost_n, "dox-pos"), t.no_cost_n)) + " " + esc(__("The cost is set in Products, or loaded all at once from the inventory Excel in Inventory.", "dox-pos")) + "</p>";
		if (full) h += '<div class="brk">' + listaHist(__("By channel", "dox-pos"), d.by_channel) + listaHist(__("By payment method", "dox-pos"), d.by_payment) + listaHist(__("By salesperson", "dox-pos"), d.by_seller) +
			(d.by_day.length > 1 ? listaHist(__("By day", "dox-pos"), d.by_day.map((x) => Object.assign({}, x, { label: diaBonito(x.name) })), true) : "") + "</div>";
		h += '<div class="grp"><h4>' + esc(__("Orders", "dox-pos")) + ' <span class="cnt">' + d.count + (d.count > d.items.length ? " · " + esc(sprintf(__("the latest %d", "dox-pos"), d.items.length)) : "") + (d.count ? " " + excelLink("ventas") : "") + "</span></h4>";
		if (!d.items.length) h += '<p class="empty">' + esc(__("Nothing in this period.", "dox-pos")) + "</p>";
		else h += '<div class="wrapx2"><table class="ped hped"><thead><tr><th>' + esc(__("Order", "dox-pos")) + '</th><th>' + esc(__("Customer", "dox-pos")) + '</th><th>' + esc(__("Products", "dox-pos")) + '</th><th>' + esc(__("Channel", "dox-pos")) + '</th><th>' + esc(__("Payment", "dox-pos")) + '</th><th class="num">' + esc(__("Total", "dox-pos")) + "</th>" + (conCosto ? '<th class="num">' + esc(__("Profit", "dox-pos")) + "</th>" : "") + "<th>" + esc(__("Status", "dox-pos")) + "</th></tr></thead><tbody>" + d.items.map((p) => filaPedidoHist(p, conCosto)).join("") + "</tbody></table></div>";
		h += "</div>";
		$("#h-ventas").innerHTML = h;
	}
	function pintarCaja(d) {
		const t = d.totals;
		let h = '<p class="hsub">' + esc(__("The cash", "dox-pos")) + " " + esc(textoPeriodo()) + "</p>";
		const conCosto = !!d.costs;
		const conPerdida = !!d.losses && t.loss > 0; // Hubo pérdidas anotadas en el periodo: salen como cifra y como columna.
		h += '<div class="kpis">' + kpi(dinero(t.cashed), __("Collected", "dox-pos")) + kpi(dinero(t.sold), __("Sold", "dox-pos")) + (conCosto ? kpi(dinero(t.profit), __("Profit", "dox-pos")) : "") + (conPerdida ? kpi("−" + dinero(t.loss), __("Losses", "dox-pos")) : "") + kpi(dinero(t.cod), __("To collect on delivery", "dox-pos") + (t.cod_n ? " (" + t.cod_n + ")" : "")) + kpi(dinero(t.holds), __("Unpaid layaway", "dox-pos") + (t.holds_n ? " (" + t.holds_n + ")" : "")) + "</div>";
		if (conCosto && t.no_cost_n) h += '<p class="hint">' + esc(sprintf(_n("%d sale without a complete cost does not count towards the profit.", "%d sales without a complete cost do not count towards the profit.", t.no_cost_n, "dox-pos"), t.no_cost_n)) + "</p>";
		if (d.method_totals && d.method_totals.length) h += '<div class="brk">' + listaHist(__("Collected by payment method", "dox-pos"), d.method_totals) + "</div>";
		h += '<div class="grp"><h4>' + esc(__("By day", "dox-pos")) + (d.days.length ? ' <span class="cnt">' + excelLink("caja") + "</span>" : "") + "</h4>";
		if (!d.days.length) h += '<p class="empty">Nada en este periodo.</p>';
		else {
			const tot = (m) => (d.method_totals.find((x) => x.name === m) || {}).total || 0;
			h += '<div class="wrapx2"><table class="ped hped"><thead><tr><th>' + esc(__("Day", "dox-pos")) + '</th><th class="num">' + esc(__("Sales", "dox-pos")) + '</th><th class="num">' + esc(__("Sold", "dox-pos")) + "</th>" + (conCosto ? '<th class="num">' + esc(__("Cost", "dox-pos")) + '</th><th class="num">' + esc(__("Profit", "dox-pos")) + "</th>" : "") + (conPerdida ? '<th class="num">' + esc(__("Losses", "dox-pos")) + "</th>" : "") + d.methods.map((m) => '<th class="num">' + esc(m) + "</th>").join("") + '<th class="num">' + esc(__("To collect", "dox-pos")) + '</th><th class="num">' + esc(__("Layaway", "dox-pos")) + "</th></tr></thead><tbody>";
			d.days.forEach((r) => {
				h += "<tr><td>" + esc(diaBonito(r.day)) + '</td><td class="num">' + r.n + '</td><td class="num">' + dinero(r.sold) + "</td>" + (conCosto ? '<td class="num">' + (r.n ? dinero(r.cost) : "") + '</td><td class="num">' + (r.n ? dinero(r.profit) : "") + "</td>" : "") + (conPerdida ? '<td class="num">' + (r.loss ? "−" + dinero(r.loss) : "") + "</td>" : "") + d.methods.map((m) => '<td class="num">' + (r.methods[m] ? dinero(r.methods[m]) : "") + "</td>").join("") + '<td class="num">' + (r.cod ? dinero(r.cod) : "") + '</td><td class="num">' + (r.holds ? dinero(r.holds) : "") + "</td></tr>";
			});
			if (d.days.length > 1) h += '<tr class="tot"><td>' + esc(__("Total", "dox-pos")) + '</td><td class="num">' + t.orders + '</td><td class="num">' + dinero(t.sold) + "</td>" + (conCosto ? '<td class="num">' + dinero(t.cost) + '</td><td class="num">' + dinero(t.profit) + "</td>" : "") + (conPerdida ? '<td class="num">−' + dinero(t.loss) + "</td>" : "") + d.methods.map((m) => '<td class="num">' + dinero(tot(m)) + "</td>").join("") + '<td class="num">' + dinero(t.cod) + '</td><td class="num">' + dinero(t.holds) + "</td></tr>";
			h += "</tbody></table></div>";
		}
		h += "</div>";
		if (d.pending && d.pending.length) {
			h += '<div class="grp"><h4>' + esc(__("To collect", "dox-pos")) + ' <span class="cnt">' + d.pending.length + '</span></h4><ul class="bl">' + d.pending.map((p) =>
				"<li><span>#" + esc(p.number) + " · " + esc(p.customer || __("No name", "dox-pos")) + ' <span class="sub">' + (p.kind === "cod" ? esc(__("cash on delivery", "dox-pos")) + ", " : "") + esc(String(p.label).toLowerCase()) + "</span></span><span><b>" + dinero(p.total) + "</b></span></li>"
			).join("") + "</ul></div>";
		}
		$("#h-caja").innerHTML = h;
	}
	function pintarTipos() {
		const tipos = [["", __("All", "dox-pos")], ["ventas", __("Sales", "dox-pos")], ["entradas", __("Stock entries", "dox-pos")], ["devueltos", __("Returned", "dox-pos")], ["ajustes", __("Adjustments", "dox-pos")]];
		chips($("#h-tipo"), tipos, hi.tipo, (x) => { hi.tipo = x; hi.page = 1; cargarHistorial(); });
	}
	function pintarMovimientos(d) {
		let h = '<p class="hsub">' + esc(__("Movements", "dox-pos")) + " " + esc(textoPeriodo()) + "</p>";
		if (hi.producto) h += '<p class="hint">' + esc(__("Only", "dox-pos")) + " <b>" + esc(hi.productoNombre) + '</b> <button type="button" class="undo" id="h-solo-x">' + esc(__("see them all", "dox-pos")) + "</button></p>";
		h += '<p class="hint">' + (d.total ? esc(sprintf(_n("%d movement", "%d movements", d.total, "dox-pos"), d.total)) + " · " + esc(__("in", "dox-pos")) + " <b>+" + d.in + "</b> · " + esc(__("out", "dox-pos")) + " <b>−" + d.out + "</b> · " + excelLink("movimientos") : esc(__("No movements in this period. Every sale, stock entry, cancellation and stock change is kept here, with its reason and who did it.", "dox-pos"))) + "</p>";
		if (d.items.length) {
			const conCosto = !!d.costs; // El costo por unidad de cada movimiento (el de compra en una entrada), para quien administra.
			h += '<div class="wrapx2"><table class="ped kdx"><thead><tr><th>' + esc(__("When", "dox-pos")) + '</th><th>' + esc(__("Product", "dox-pos")) + '</th><th class="num">' + esc(__("Before", "dox-pos")) + '</th><th class="num">' + esc(__("Change", "dox-pos")) + '</th><th class="num">' + esc(__("After", "dox-pos")) + "</th>" + (conCosto ? '<th class="num">' + esc(__("Unit cost", "dox-pos")) + "</th>" : "") + "<th>" + esc(__("Reason", "dox-pos")) + "</th><th>" + esc(__("Who", "dox-pos")) + "</th></tr></thead><tbody>";
			d.items.forEach((r) => {
				h += '<tr><td class="num"><span class="sub">' + esc(r.date) + '</span></td><td><button type="button" class="linkp" data-pid="' + r.product_id + '" data-name="' + esc(r.name) + '">' + esc(r.name) + '</button><br><span class="sub">' + esc(r.sku) + '</span></td><td class="num">' + (r.before === null ? "" : r.before) + '</td><td class="num"><span class="delta ' + (r.delta > 0 ? "in" : "out") + '">' + (r.delta > 0 ? "+" : "−") + Math.abs(r.delta) + '</span></td><td class="num"><b>' + (r.after === null ? "" : r.after) + "</b></td>" + (conCosto ? '<td class="num">' + (r.unit_cost === null || r.unit_cost === undefined ? "" : dinero(r.unit_cost)) + "</td>" : "") + "<td>" + esc(r.label) + (r.note ? '<br><span class="sub">' + esc(r.note) + "</span>" : "") + "</td><td>" + esc(r.user) + "</td></tr>";
			});
			h += "</tbody></table></div>";
			if (d.pages > 1) h += '<div class="pager"><button type="button" class="mini" id="h-prev"' + (d.page <= 1 ? " disabled" : "") + ">" + esc(__("Previous", "dox-pos")) + "</button><span>" + esc(sprintf(__("%1$s-%2$s of %3$s", "dox-pos"), (d.page - 1) * 50 + 1, Math.min(d.total, d.page * 50), d.total)) + '</span><button type="button" class="mini" id="h-next"' + (d.page >= d.pages ? " disabled" : "") + ">" + esc(__("Next", "dox-pos")) + "</button></div>";
		}
		const box = $("#h-mov");
		box.innerHTML = h;
		box.querySelectorAll(".linkp").forEach((b) => { b.onclick = () => { hi.producto = +b.dataset.pid; hi.productoNombre = b.dataset.name; hi.page = 1; cargarHistorial(); }; });
		const x = $("#h-solo-x");
		if (x) x.onclick = () => { hi.producto = 0; hi.productoNombre = ""; hi.page = 1; cargarHistorial(); };
		const pv = $("#h-prev"), nx = $("#h-next");
		if (pv) pv.onclick = () => { hi.page--; cargarHistorial(); $("#t-historial .scroll").scrollTop = 0; };
		if (nx) nx.onclick = () => { hi.page++; cargarHistorial(); $("#t-historial .scroll").scrollTop = 0; };
	}

	vistaHistorial({ id: "ventas", cargar: (a, b) => api("history/sales?from=" + a + "&to=" + b), pintar: pintarVentas });
	vistaHistorial({ id: "caja", cargar: (a, b) => api("history/cash?from=" + a + "&to=" + b), pintar: pintarCaja });
	vistaHistorial({ id: "mov", cargar: (a, b) => api("history/stock?from=" + a + "&to=" + b + "&q=" + encodeURIComponent(hi.q) + "&product=" + hi.producto + "&kind=" + hi.tipo + "&page=" + hi.page), pintar: pintarMovimientos });

	// ---------- los costos de golpe: el Excel del inventario con la columna Costo llena ----------
	async function subirCostos() {
		const inp = $("#e-costos-file");
		const f = inp.files && inp.files[0];
		inp.value = "";
		if (!f) return;
		const soltar = ocupar($("#e-costos"), __("Uploading\u2026", "dox-pos"));
		try {
			const fd = new FormData();
			fd.append("file", f, f.name);
			const r = await api("costs/import", { method: "POST", body: fd });
			let t = sprintf(_n("%d cost changed", "%d costs changed", r.updated, "dox-pos"), r.updated) + " " + sprintf(_n("out of %d row.", "out of %d rows.", r.rows, "dox-pos"), r.rows);
			if (r.same) t += " " + sprintf(_n("%d was already the same.", "%d were already the same.", r.same, "dox-pos"), r.same);
			if (r.skipped) t += " " + sprintf(_n("%d row with no cost (skipped).", "%d rows with no cost (skipped).", r.skipped, "dox-pos"), r.skipped);
			if (r.missing) t += " " + sprintf(_n("%d SKU is not in the store", "%d SKUs are not in the store", r.missing, "dox-pos"), r.missing) + (r.missing_list && r.missing_list.length ? ": " + r.missing_list.map(esc).join(", ") : "") + ".";
			modal("<h3>" + esc(__("Costs loaded", "dox-pos")) + '</h3><p class="mp">' + t + '</p><div class="mbtn"><button type="button" class="go alt" id="m-no">' + esc(__("Done", "dox-pos")) + "</button></div>");
			$("#m-no").onclick = cerrarModal;
		} catch (e) {
			if (e.message !== "sesion") toast(e.red ? __("No signal: the file could not be uploaded.", "dox-pos") : e.message);
		}
		soltar();
	}

	// ---------- el Panel ----------
	// Las cifras del día como cuadro de mando, para quien administra. Se piden al abrir la pestaña (de nuevo
	// si pasó medio minuto, o hubo una venta o un pedido tocado); "Actualizar" las pide ya mismo.
	const pa = { at: 0, data: null };
	async function cargarPanel(fuerza) {
		const box = $("#panel");
		if (!box) return;
		if (!fuerza && pa.data && Date.now() - pa.at < 30000) { pintarPanel(pa.data); return; }
		if (!pa.data) box.innerHTML = '<p class="empty">' + esc(__("Loading\u2026", "dox-pos")) + "</p>";
		box.style.opacity = ".6";
		try {
			pa.data = await api("dashboard");
			pa.at = Date.now();
			pintarPanel(pa.data);
		} catch (e) {
			if (e.message !== "sesion") box.innerHTML = '<p class="empty">' + esc(e.red ? __("No signal: the dashboard reads from the shop.", "dox-pos") : e.message) + "</p>";
		}
		box.style.opacity = "";
	}
	const thumbHtml = (p) => '<span class="thumb sm">' + esc(iniciales(p.name)) + (p.image ? '<img alt="" loading="lazy" src="' + esc(p.image) + '">' : "") + "</span>";
	function pintarPanel(d) {
		const t = d.today, y = d.yesterday, w = d.week, m = d.month, p = d.pending;
		const conCosto = !!d.costs;
		const ventas = (n) => sprintf(_n("%d sale", "%d sales", n, "dox-pos"), n);
		const unidades = (n) => sprintf(_n("%d unit", "%d units", n, "dox-pos"), n);
		// Una tarjeta: la cifra, el rótulo y una línea pequeña debajo; con "go", tocarla abre esa pestaña.
		const card = (v, l, sub, go) => '<div class="kpi"' + (go ? ' data-go="' + esc(go) + '" role="link" tabindex="0"' : "") + "><b>" + v + "</b><span>" + esc(l) + "</span>" + (sub ? "<i>" + sub + "</i>" : "") + "</div>";
		const dif = (a, b) => { if (!(b > 0)) return ""; const pc = Math.round((a - b) / b * 100); return '<em class="' + (pc >= 0 ? "up" : "down") + '">' + (pc >= 0 ? "+" : "\u2212") + Math.abs(pc) + "\u00a0%</em> · "; };
		const cobrar = (p.cod || 0) + (p.holds || 0);
		let h = '<div class="pnl-head"><p class="hsub">' + esc(d.date_label) + '</p><button type="button" class="mini" id="pnl-ref">' + esc(__("Refresh", "dox-pos")) + "</button></div>";
		h += '<div class="kpis big">' +
			card(dinero(t.sold), __("Sold today", "dox-pos"), esc(ventas(t.orders) + " · " + unidades(t.units) + " · " + sprintf(__("yesterday %s", "dox-pos"), dinero(y.sold))), "historial/ventas/hoy") +
			(conCosto ? card(dinero(t.profit || 0), __("Profit today", "dox-pos"), esc([t.margin !== null && t.margin !== undefined ? sprintf(__("%d %% margin", "dox-pos"), t.margin) : "", t.no_cost_n ? sprintf(_n("%d sale with no cost", "%d sales with no cost", t.no_cost_n, "dox-pos"), t.no_cost_n) : "", d.losses && t.loss > 0 ? sprintf(__("losses %s", "dox-pos"), dinero(t.loss)) : ""].filter(Boolean).join(" · ")), "historial/caja") : "") +
			card(dinero(cobrar), __("To collect", "dox-pos"), esc(sprintf(__("cash on delivery %1$s (%2$d) · layaway %3$s (%4$d)", "dox-pos"), dinero(p.cod || 0), p.cod_n || 0, dinero(p.holds || 0), p.holds_n || 0)), "pedidos") +
			card(dinero(w.sold), __("This week", "dox-pos"), dif(w.sold, w.prev) + esc(ventas(w.orders) + " · " + sprintf(__("last week at this point: %s", "dox-pos"), dinero(w.prev))), "historial/ventas/semana") +
			card(dinero(m.sold), __("This month", "dox-pos"), dif(m.sold, m.prev) + esc(ventas(m.orders) + " · " + sprintf(__("last month at this point: %s", "dox-pos"), dinero(m.prev))), "historial/ventas/mes") +
			(d.extra || []).map((x) => card(x.money !== undefined && x.money !== null ? dinero(x.money) : esc(x.text || ""), x.label || "", esc(x.sub || ""), x.go || "")).join("") +
			"</div>";
		// Los últimos catorce días en barras; hoy, en color.
		const max = Math.max(1, ...d.series.map((x) => x.total));
		h += '<div class="pgrid">';
		h += '<div class="pcard pwide"><h4>' + esc(__("Last 14 days", "dox-pos")) + '<span class="cnt">' + dinero(d.series.reduce((a, x) => a + x.total, 0)) + '</span></h4><div class="pbars">' +
			d.series.map((x, i) => '<div class="pbar' + (i === d.series.length - 1 ? " now" : "") + '" title="' + esc(diaBonito(x.day) + ": " + dinero(x.total) + " · " + ventas(x.n)) + '"><i style="--h:' + Math.round(x.total / max * 100) + '%"></i><span>' + parseInt(x.day.slice(8), 10) + "</span></div>").join("") + "</div></div>";
		h += '<div class="pcard"><h4>' + esc(__("Best sellers", "dox-pos")) + '<span class="cnt">' + esc(__("last 30 days", "dox-pos")) + "</span></h4>" +
			(d.top.length ? '<ul class="bl ptop">' + d.top.map((x) => "<li>" + thumbHtml(x) + '<button type="button" class="lnk n" data-prod="' + x.id + '">' + esc(x.name) + "</button><span>" + esc(unidades(x.units)) + " · <b>" + dinero(x.total) + "</b></span></li>").join("") + "</ul>" : '<p class="empty">' + esc(__("No sales in the last 30 days.", "dox-pos")) + "</p>") + "</div>";
		h += '<div class="pcard"><h4>' + esc(__("Collected today", "dox-pos")) + '<span class="cnt">' + dinero(t.cashed) + "</span></h4>" +
			(d.methods.length ? '<ul class="bl">' + d.methods.map((x) => "<li><span>" + esc(x.name) + "</span><span>" + esc(ventas(x.n)) + " · <b>" + dinero(x.total) + "</b></span></li>").join("") + "</ul>" : '<p class="empty">' + esc(__("Nothing collected yet today.", "dox-pos")) + "</p>") + "</div>";
		const pend = [[__("To ship", "dox-pos"), p.por_enviar], [__("On the way", "dox-pos"), p.enviado], [__("Layaways", "dox-pos"), p.apartado], [__("Payments to confirm", "dox-pos"), p.por_confirmar], [__("Website orders unpaid", "dox-pos"), p.sin_pagar]].filter((f) => f[1] > 0);
		h += '<div class="pcard"><h4>' + esc(__("Orders to handle", "dox-pos")) + "</h4>" +
			(pend.length ? '<ul class="bl">' + pend.map((f) => '<li><button type="button" class="lnk" data-go="pedidos">' + esc(f[0]) + "</button><span><b>" + f[1] + "</b></span></li>").join("") + "</ul>" : '<p class="empty">' + esc(__("Nothing pending.", "dox-pos")) + "</p>") + "</div>";
		h += '<div class="pcard"><h4>' + esc(__("Stock", "dox-pos")) + '</h4><ul class="bl"><li><button type="button" class="lnk" data-go="entrada">' + esc(__("Units in stock", "dox-pos")) + "</button><span><b>" + (miles(d.stock.units) || "0") + "</b></span></li><li><span>" + esc(__("Products out of stock", "dox-pos")) + "</span><span><b>" + (miles(d.stock.out) || "0") + "</b></span></li></ul></div>";
		h += "</div>";
		const box = $("#panel");
		box.innerHTML = h;
		$("#pnl-ref").onclick = () => cargarPanel(true);
		box.querySelectorAll("[data-go]").forEach((el) => {
			el.onclick = () => ir(el.dataset.go);
			el.onkeydown = (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); ir(el.dataset.go); } };
		});
	}
	// Abre una pestaña como lo haría el #: "pedidos", "historial/ventas/semana"...
	function ir(donde) {
		if (donde.split("/")[0] === "vender") { $('#tabs button[data-t="vender"]').click(); return; }
		if (location.hash === "#" + donde) abrirDesdeHash(); else location.hash = "#" + donde; // El cambio de # ya abre la pestaña.
	}

	// ---------- pestañas y arranque ----------
	// Cada pestaña se registra con lo que hace al abrirse, lo que añade al # y cómo lee lo que venga en el #.
	// Las del núcleo van aquí; los añadidos (el Pro) registran las suyas con DoxPOS.pestaña().
	const pestañas = {};
	function pestaña(def) { pestañas[def.id] = def; }
	// Avisos entre módulos: "arranque" (todo listo), "pedido" (hubo una acción sobre un pedido) y "pestaña" (cambió).
	const oyentes = {};
	function on(ev, fn) { (oyentes[ev] = oyentes[ev] || []).push(fn); }
	function emit(ev, d) { (oyentes[ev] || []).forEach((fn) => { try { fn(d); } catch (e) { console.error(e); } }); }
	pestaña({ id: "entrada", abrir: () => { cargarEntradas(); if (!st.cat.items.length && !inputDe("entrada").value.trim()) mostrarCatalogo(); } }); // El catálogo se pide la primera vez que se abre.
	pestaña({ id: "pedidos", abrir: cargarPedidos });
	pestaña({ id: "producto", abrir: abrirProducto });
	pestaña({ id: "panel", abrir: () => cargarPanel(false) });
	on("pedido", () => { pa.at = 0; }); // Un pedido tocado: el Panel se vuelve a pedir al abrirlo.
	pestaña({
		id: "historial",
		abrir: cargarHistorial,
		hash: () => (hi.vista !== "ventas" ? hi.vista : ""),
		// #historial/mov, #historial/ventas/2026-09-05 (las ventas de un día) o #historial/ventas/semana.
		desdeHash(vista, extra) {
			if (vistasHist[vista]) { hi.vista = vista; document.querySelectorAll("#h-vista button").forEach((x) => x.setAttribute("aria-pressed", x.dataset.v === vista)); }
			const per = /^\d{4}-\d{2}-\d{2}$/.test(extra || "") ? "fechas" : (["hoy", "semana", "mes"].includes(extra) ? extra : "");
			if (per) {
				hi.periodo = per;
				if (per === "fechas") { hi.desde = extra; hi.hasta = extra; ["#h-desde", "#h-hasta"].forEach((sel) => { const el = $(sel); if (el) el.value = extra; }); }
				document.querySelectorAll("#h-periodo button").forEach((x) => x.setAttribute("aria-pressed", x.dataset.p === per));
				const fx = $("#h-fechas");
				if (fx) fx.hidden = per !== "fechas";
			}
		},
	});
	// La dirección lleva dónde se está (#asistente/chat, #historial/caja; Vender va sin nada) para que una
	// recarga vuelva al mismo sitio. replaceState: no se ensucia el historial del navegador con cada toque.
	function fijarHash() {
		let h = st.tab === "vender" ? "" : st.tab;
		const def = pestañas[st.tab];
		const sub = h && def && def.hash ? def.hash() : "";
		if (sub) h += "/" + sub;
		const want = h ? "#" + h : "";
		if (location.hash !== want) history.replaceState(null, "", location.pathname + location.search + want);
	}
	// Abre lo que diga la dirección: la pestaña y su vista, y, si viene, el pedido (#pedidos/123) o la ficha
	// de un producto (#producto/123). Así los enlaces del correo del resumen llegan a donde apuntan, se
	// abra la caja de cero o ya estuviera abierta.
	function abrirDesdeHash(inicial) {
		const [tabH, vistaH, extraH] = location.hash.replace(/^#/, "").split("/");
		if (!tabH && inicial === true && cfg.open_tab && cfg.open_tab !== "vender") { // Sin # al entrar: la pestaña que diga la configuración (el Panel para quien administra).
			const t0 = document.querySelector('#tabs button[data-t="' + cfg.open_tab + '"]');
			if (t0) setTimeout(() => t0.click(), 0);
			return;
		}
		if (!tabH || tabH === "vender") return;
		const t = document.querySelector('#tabs button[data-t="' + tabH + '"]');
		if (!t) return;
		const idH = /^\d+$/.test(vistaH || "") ? +vistaH : 0;
		const def = pestañas[tabH];
		if (def && def.desdeHash) def.desdeHash(vistaH || "", extraH || "");
		setTimeout(() => {
			t.click();
			if (tabH === "pedidos" && idH) verPedido(idH);
			if (tabH === "producto" && idH) verProducto(idH); // La tarjeta; desde ahí, Editar.
		}, 0);
	}
	function init() {
		document.querySelectorAll("#tabs button").forEach((b) => {
			b.onclick = () => {
				st.tab = b.dataset.t;
				document.querySelectorAll("#tabs button").forEach((x) => x.setAttribute("aria-pressed", x === b));
				document.querySelectorAll("section.tab").forEach((el) => { el.hidden = el.id !== "t-" + st.tab; });
				fijarHash();
				const def = pestañas[st.tab];
				if (def && def.abrir) def.abrir();
				emit("pestaña", st.tab);
			};
		});
		if (hayProducto()) {
			const file = $("#p-file");
			file.addEventListener("change", () => { agregarArchivos(file.files); file.value = ""; });
			const zona = $("#p-fotos");
			zona.addEventListener("dragover", (e) => { e.preventDefault(); zona.classList.add("over"); });
			zona.addEventListener("dragleave", () => zona.classList.remove("over"));
			zona.addEventListener("drop", (e) => { e.preventDefault(); zona.classList.remove("over"); if (e.dataTransfer) agregarArchivos(e.dataTransfer.files); });
			$("#p-nom").addEventListener("input", () => { pintarResumenProducto(); programarNombre(); });
			$("#p-precio").addEventListener("input", () => { formatearPrecio(); pintarResumenProducto(); });
			if ($("#p-costo")) $("#p-costo").addEventListener("input", () => { formatearPrecio(); pintarResumenProducto(); });
			$("#p-desc").addEventListener("input", programarBorrador);
			$("#p-pub").addEventListener("change", programarBorrador);
			$("#p-sku").addEventListener("input", comprobarCodigo);
			$("#p-vaciar").onclick = vaciarProducto;
			$("#p-color-add").onclick = añadirColorNuevo;
			$("#p-color-nom").addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); añadirColorNuevo(); } });
			$("#p-crear").onclick = crearProducto;
			document.querySelectorAll("#pmode button").forEach((b) => { b.onclick = () => ponerModo(b.dataset.m); });
			$("#p-q").addEventListener("input", programarBusquedaProducto);
			$("#p-edit-cancel").onclick = () => limpiarProducto();
		}
		if (hayHistorial()) {
			document.querySelectorAll("#h-periodo button").forEach((b) => {
				b.onclick = () => {
					hi.periodo = b.dataset.p;
					document.querySelectorAll("#h-periodo button").forEach((x) => x.setAttribute("aria-pressed", x === b));
					$("#h-fechas").hidden = hi.periodo !== "fechas";
					hi.page = 1;
					cargarHistorial();
				};
			});
			["#h-desde", "#h-hasta"].forEach((sel) => {
				const el = $(sel);
				if (!el) return;
				el.value = fechaISO(hoyTienda());
				el.addEventListener("change", () => { hi.desde = $("#h-desde").value; hi.hasta = $("#h-hasta").value; hi.page = 1; cargarHistorial(); });
			});
			document.querySelectorAll("#h-vista button").forEach((b) => {
				b.onclick = () => {
					hi.vista = b.dataset.v;
					document.querySelectorAll("#h-vista button").forEach((x) => x.setAttribute("aria-pressed", x === b));
					hi.page = 1;
					fijarHash();
					cargarHistorial();
				};
			});
			pintarTipos();
			let hqTimer = null;
			$("#h-q").addEventListener("input", () => { clearTimeout(hqTimer); hqTimer = setTimeout(() => { hi.q = $("#h-q").value.trim(); hi.page = 1; cargarHistorial(); }, 300); });
		}
		emit("arranque"); // Los añadidos enganchan lo suyo (el Pro: el chat, la insignia, el aviso de demostración).
		abrirDesdeHash(true);
		// Y si cambia el # con la caja ya abierta (el enlace del correo cae en esta misma pestaña, o
		// se toca "atrás"), se abre lo que pida sin recargar.
		window.addEventListener("hashchange", abrirDesdeHash);
		// En pantalla estrecha cada pestaña enseña una columna: buscar, o el pedido (o lo que llegó).
		[["#paneseg", "#col-cat", "#col-ord"], ["#paneseg2", "#col-cat2", "#col-ord2"]].forEach(([seg, cat, ord]) => {
			document.querySelectorAll(seg + " button").forEach((b) => {
				b.onclick = () => {
					document.querySelectorAll(seg + " button").forEach((x) => x.setAttribute("aria-pressed", x === b));
					$(cat).dataset.off = b.dataset.p === "buscar" ? "0" : "1";
					$(ord).dataset.off = b.dataset.p === "pedido" ? "0" : "1";
				};
			});
			$(cat).dataset.off = "0";
			$(ord).dataset.off = "1";
		});
		$("#q").addEventListener("input", () => programar("venta"));
		$("#q2").addEventListener("input", () => programar("entrada"));
		// Inventario: al llegar abajo de la lista del catálogo, la siguiente página.
		listaDe("entrada").parentElement.addEventListener("scroll", (e) => { const el = e.target; if (st.catOn && st.cat.more && !st.cat.loading && el.scrollTop + el.clientHeight >= el.scrollHeight - 240) mostrarCatalogo(true); });
		["#f-desc", "#f-env"].forEach((s) => $(s).addEventListener("input", pintarSum));
		["#f-ciu", "#f-dir"].forEach((s) => $(s).addEventListener("input", programarEnvio));
		$("#reg").onclick = () => cerrar(false);
		$("#apartar").onclick = () => cerrar(true);
		$("#reg2").onclick = guardarEntrada;
		if ($("#e-costos")) {
			$("#e-costos").onclick = () => $("#e-costos-file").click();
			$("#e-costos-file").addEventListener("change", subirCostos);
		}
		$("#modal").addEventListener("click", (e) => { if (e.target === $("#modal")) cerrarModal(); });
		// Tocar el número o los productos de un pedido (en Pedidos, Historial u Hoy) abre su detalle.
		// Solo cuando el atributo trae el número del pedido: un data-ver vacío es otro botón
		// (los añadidos usan sus propios atributos) y no tiene que abrir ninguna ficha.
		document.addEventListener("click", (e) => { const b = e.target.closest("[data-ver]"); const id = b ? parseInt(b.dataset.ver, 10) : 0; if (b && id > 0) { e.preventDefault(); verPedido(id); } });
		// Y tocar el nombre de un producto (en el resumen, los consejos o lo que se agota) abre su ficha.
		// Un producto nombrado en cualquier parte (el asistente, el historial): su tarjeta, sin salir de donde se está.
		document.addEventListener("click", (e) => { const b = e.target.closest("[data-prod]"); if (b) { e.preventDefault(); verProducto(+b.dataset.prod); } });
		document.addEventListener("keydown", (e) => { if (e.key === "Escape" && !$("#modal").hidden) cerrarModal(); });
		const hoy = new Date();
		$("#e-fec").value = hoy.getFullYear() + "-" + String(hoy.getMonth() + 1).padStart(2, "0") + "-" + String(hoy.getDate()).padStart(2, "0");
		pintarChips();
		pintarDepartamentos();
		pintarTodo();
		pintarEnvio();
		mostrarTop(); // Lo más vendido, mientras no se busque nada.
		listaDe("entrada").innerHTML = vacio(__("Search what arrived by name or SKU.", "dox-pos"));
		cargarPedidos();
		window.addEventListener("online", () => { pintarCola(); vaciarCola(); });
		window.addEventListener("offline", pintarCola);
		setInterval(vaciarCola, 30000);
		pintarCola();
		vaciarCola();
		$("#q").focus();
	}
	// Lo que los añadidos pueden usar (el Pro se cuelga de aquí). El script va al final del body y la plantilla
	// llama a DoxPOS.arrancar() después de cargar los añadidos, así que todo lo que toca ya existe y las pestañas
	// de los añadidos ya están registradas. Esperar a DOMContentLoaded es una trampa con optimizadores que
	// retrasan el JS (el evento ya pasó, o fingen readyState).
	window.DoxPOS = {
		cfg, M, $, esc, num, dinero, iniciales, miniatura, kpi, chip, chips, uuid, ocupar,
		api, post, modal, cerrarModal, confirmar, preguntar, toast,
		verPedido, verProducto, editarDesdeLista, cargarPedidos, accion, refrescarStock, fijarHash, hayProducto,
		vistaHistorial, rangoHistorial, textoPeriodo, excelLink, diaBonito, listaHist,
		pestaña, on, emit, pestañaActual: () => st.tab, arrancar: init,
	};
})();
