/* Dox POS: la caja. Habla con /wp-json/dox-pos/v1/ y todo lo que guarda lo guarda WooCommerce. */
(function () {
	"use strict";

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
			const err = new Error("Sin señal"); // fetch solo falla así cuando no hay red
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
			const err = new Error(j && j.message ? j.message : "No se pudo hablar con la tienda (" + r.status + ").");
			if (j && j.code) err.code = j.code; // por ejemplo dox_pos_factura_repetida
			throw err;
		}
		return r.json();
	}
	const post = (path, body) => api(path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body || {}) });
	function sesionCaducada() {
		toast("Tu sesión caducó. Vuelve a entrar.");
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
			ul.innerHTML = vacio("Escribe dos letras del nombre, o el código.");
			return;
		}
		p.ctrl = new AbortController();
		if (!st.res[modo].length) ul.innerHTML = vacio("Buscando…");
		try {
			const data = await api("search?q=" + encodeURIComponent(q), { signal: p.ctrl.signal });
			data.items.forEach((prod) => prod.variations.forEach((v) => { st.vars[v.id] = { v: v, p: prod }; }));
			st.res[modo] = data.items;
			if (data.items.length === 1) st.abierto[modo] = data.items[0].id; // si hay uno solo, se abre
			else if (!data.items.some((x) => x.id === st.abierto[modo])) st.abierto[modo] = null;
			pintarResultados(modo);
			pintarLineas($("#lineas"), st.lineas, "venta");
			pintarLineas($("#lineas2"), st.entrada, "entrada");
		} catch (e) {
			if (e.name === "AbortError" || e.message === "sesion") return;
			ul.innerHTML = vacio("No se pudo buscar. Revisa la conexión y vuelve a intentarlo.");
		}
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
	// Lo que queda libre: las existencias de la tienda menos lo que ya está en este pedido.
	const libres = (v) => (v.stock === null ? null : v.stock - enPedido(v.id, "venta"));
	function disponible(v) {
		return v.stock === null ? v.status !== "outofstock" : libres(v) > 0;
	}
	function textoStock(v, modo) {
		if (v.stock === null) return v.status === "outofstock" ? "agotado" : "sin límite";
		const n = modo === "venta" ? libres(v) : v.stock;
		return n > 0 ? "quedan <b>" + n + "</b>" : "sin existencias";
	}
	function pintarResultados(modo) {
		const ul = listaDe(modo);
		ul.innerHTML = "";
		const items = st.res[modo];
		if (!items.length) {
			ul.innerHTML = vacio("Nada con eso. Prueba con una sola palabra.");
			return;
		}
		items.forEach((p) => {
			const hay = p.variations.reduce((a, v) => a + (v.stock || 0), 0);
			const sinLimite = p.variations.some((v) => v.stock === null && v.status !== "outofstock");
			const conTalla = p.variations.some((v) => v.talla);
			const que = p.variations.length === 1 ? (conTalla ? "talla" : "opción") : (conTalla ? "tallas" : "opciones");
			const li = document.createElement("li");
			const b = document.createElement("button");
			b.type = "button";
			b.className = "prod";
			b.setAttribute("aria-expanded", st.abierto[modo] === p.id);
			b.innerHTML =
				'<span class="n">' + esc(p.name) + "<i>" + p.variations.length + " " + que + "</i></span>" +
				'<span class="p">' + dinero(p.price) + "<i>" + (sinLimite ? "disponible" : hay ? hay + " en existencia" : "agotado") + "</i></span>";
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
		ul.innerHTML = arr.length ? "" : '<li class="empty" style="padding:8px 2px">Toca un producto de la izquierda para agregarlo.</li>';
		arr.forEach((l, i) => {
			const d = st.vars[l.vid];
			if (!d) return;
			const li = document.createElement("li");
			const conCosto = modo === "entrada" && !!cfg.costs; // Cada línea de la entrada lleva lo que costó la unidad, en su propia fila.
			li.className = "lin" + (conCosto ? " cost" : "") + (st.flash === modo + ":" + l.vid ? " flash" : "");
			li.dataset.vid = String(l.vid);
			li.innerHTML =
				'<span class="n">' + esc(d.p.name) + "<i>" + esc(d.v.label) + " · " + esc(d.v.sku) + "</i></span>" +
				'<span class="qty"><button type="button" data-d="-1" aria-label="Una menos">−</button><span>' + l.n + '</span><button type="button" data-d="1" aria-label="Una más">+</button></span>' +
				(conCosto
					? '<span class="cst"><label>Costo por unidad</label><input inputmode="numeric" placeholder="0" value="' + esc(miles(l.c || 0)) + '" aria-label="Costo por unidad"><span class="vt">' + (l.c ? dinero(l.n * l.c) : "") + "</span></span>"
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
			"<div><span>Subtotal</span><span>" + dinero(t.sub) + "</span></div>" +
			(t.desc ? "<div><span>Descuento</span><span>−" + dinero(t.desc) + "</span></div>" : "") +
			(sinEnvio() ? "" : "<div><span>Envío</span><span>" + dinero(t.env) + "</span></div>") +
			'<div class="tot"><span>Total</span><span>' + dinero(t.tot) + "</span></div>";
		$("#reg").disabled = st.ocupado || !st.lineas.length;
		$("#apartar").disabled = st.ocupado || !st.lineas.length;
		const uds = st.entrada.reduce((a, l) => a + l.n, 0);
		const costo = cfg.costs ? st.entrada.reduce((a, l) => a + l.n * (l.c || 0), 0) : 0;
		const sinCosto = cfg.costs ? st.entrada.filter((l) => !l.c).length : 0;
		$("#sum2").innerHTML = (cfg.costs && st.entrada.length ? "<div><span>Costo de la mercancía</span><span>" + (costo ? dinero(costo) : "sin costo") + (sinCosto && costo ? " · " + sinCosto + (sinCosto === 1 ? " línea sin costo" : " líneas sin costo") : "") + "</span></div>" : "") +
			'<div class="tot"><span>Unidades que entran</span><span>' + uds + "</span></div>";
		$("#reg2").disabled = st.ocupado || !st.entrada.length;
	}
	function pintarTodo() {
		if (st.res.venta.length) pintarResultados("venta");
		if (st.res.entrada.length) pintarResultados("entrada");
		pintarLineas($("#lineas"), st.lineas, "venta");
		pintarLineas($("#lineas2"), st.entrada, "entrada");
		st.flash = null;
		const u = st.lineas.reduce((a, l) => a + l.n, 0);
		$("#n-lin").textContent = u ? u + (u === 1 ? " unidad" : " unidades") : "";
		$("#npane").textContent = u ? "· " + u : "";
		const u2 = st.entrada.reduce((a, l) => a + l.n, 0);
		$("#n-lin2").textContent = u2 ? u2 + (u2 === 1 ? " unidad" : " unidades") : "";
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
			box.innerHTML = '<span class="hint">' + (st.lineas.length ? "Elige el " + esc((cfg.state_label || "departamento").toLowerCase()) + " y la ciudad para ver las opciones de envío de la tienda." : "Agrega productos y pon la ciudad para ver el envío.") + "</span>";
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
			toast("Para apartar hace falta el WhatsApp del cliente.");
			$("#f-tel").focus();
			return;
		}
		st.ocupado = true;
		pintarSum();
		const soltar = ocupar(hold ? $("#apartar") : $("#reg"), hold ? "Apartando…" : "Registrando…");
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
			shipping: conEnvio ? { label: st.rate ? st.rate.label : "Envío", method_id: st.rate ? st.rate.method_id : "dox_pos", instance_id: st.rate ? st.rate.instance_id : 0, cost: num($("#f-env").value) } : null,
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
				else toast("Venta registrada: pedido #" + data.order.number + ". El inventario ya bajó.");
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
		if ($("#q").value.trim().length >= 2) await buscar("venta");
		if ($("#q2").value.trim().length >= 2) await buscar("entrada");
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
			btn("Ya pagó", false, () => accion(p, "paid"));
			btn("Liberar", true, () => confirmar("¿Liberar el apartado de " + (p.customer || "este cliente") + "? El producto vuelve al inventario.", () => accion(p, "release")));
			wa();
		} else if (SIN_PAGAR.includes(p.status)) {
			btn("Ya pagó", false, () => confirmar("¿Confirmar el pago del pedido #" + p.number + "? Pasa a por enviar" + (sinStock ? " y descuenta el inventario." : "."), () => accion(p, "paid")));
			btn("Anular", true, () => confirmar("¿Anular el pedido #" + p.number + "?" + (sinStock ? "" : " El inventario vuelve."), () => accion(p, "cancel")));
			wa();
		} else if (p.status === "por_enviar") {
			btn("Marcar enviado", false, () => modalEnvio(p));
			btn("Anular", true, () => confirmar("¿Anular el pedido #" + p.number + "? El inventario vuelve.", () => accion(p, "cancel")));
			wa();
		} else if (p.status === "enviado") {
			btn("Marcar entregado", false, () => accion(p, "delivered"));
			wa();
		}
	}
	// ----- el detalle de un pedido: se abre tocando el número o los productos -----
	async function verPedido(id) {
		modal('<h3>Pedido</h3><p class="mp">Cargando…</p>', "wide");
		try {
			const d = await api("orders/" + id);
			if ($("#modal").hidden) return; // lo cerraron mientras cargaba
			pintarDetalle(d);
		} catch (e) {
			if (e.message === "sesion") return;
			modal('<h3>Pedido</h3><p class="mp">' + esc(e.red ? "Sin señal: no se pudo cargar el pedido." : e.message) + '</p><div class="mbtn"><button type="button" class="go alt" id="m-no">Cerrar</button></div>', "wide");
			$("#m-no").onclick = cerrarModal;
		}
	}
	function pintarDetalle(d) {
		const cls = { apartado: "b", sin_pagar: "b", por_confirmar: "b", fallido: "b", por_enviar: "a", enviado: "e", entregado: "c", anulado: "d", reembolsado: "d" }[d.status] || "e";
		const web = d.origin !== "caja";
		let pago = "Paga al recibir";
		if (d.paid && !d.cod) pago = "Pagado" + (d.paid_at ? " el " + esc(d.paid_at) : "");
		else if (d.status === "apartado" || d.status === "sin_pagar" || d.status === "fallido") pago = "Sin pagar";
		else if (d.status === "por_confirmar") pago = "En proceso";
		else if (d.status === "anulado" || d.status === "reembolsado") pago = "";
		const items = (d.items_list || []).map((it) => "<li>" +
			'<span class="thumb">' + (it.image ? '<img src="' + esc(it.image) + '" alt="" loading="lazy">' : esc(iniciales(it.name || ""))) + "</span>" +
			'<div class="odi"><b>' + esc(it.name) + "</b>" +
			'<span class="sub">' + (it.sku ? '<span class="sku">' + esc(it.sku) + "</span> · " : "") + it.qty + " × " + dinero(it.price) + (it.stock !== null && it.stock !== undefined ? " · quedan " + it.stock : "") + (it.unit_cost !== null && it.unit_cost !== undefined ? " · costo " + dinero(it.unit_cost) : "") + "</span>" +
			'<span class="odlinks">' + (it.url ? '<a href="' + esc(it.url) + '" target="_blank" rel="noopener">Ver en la tienda</a>' : '<span class="sub">' + (it.exists ? "Oculto en la tienda" : "Ya no existe") + "</span>") + (it.editable && hayProducto() ? ' · <button type="button" class="lnk" data-edit="' + it.product_id + '">Editar</button>' : "") + "</span>" +
			"</div>" +
			'<span class="num">' + dinero(it.total) + "</span></li>").join("");
		const dir = [d.address, d.address2, d.city].filter(Boolean).join(", ");
		let h = '<h3>Pedido #' + esc(d.number) + ' <span class="tag ' + cls + '">' + esc(d.label) + "</span></h3>";
		h += '<p class="mp">' + esc(d.created) + " · " + (web ? esc(d.origin_label) + (d.source ? " (desde " + esc(d.source) + ")" : "") : esc(d.channel) + " · Caja" + (d.seller ? " · " + esc(d.seller) : "")) + (d.demo ? " · demostración" : "") + "</p>";
		h += '<div class="od">';
		h += "<section><h4>Productos</h4>" + (items ? '<ul class="odl">' + items + "</ul>" : '<p class="sub">Sin productos.</p>');
		h += '<table class="tot"><tbody><tr><td>Subtotal</td><td>' + dinero(d.subtotal) + "</td></tr>" +
			(d.discount ? "<tr><td>Descuento</td><td>−" + dinero(d.discount) + "</td></tr>" : "") +
			(d.shipping_total || d.shipping_method ? "<tr><td>Envío" + (d.shipping_method ? " · " + esc(d.shipping_method) : "") + "</td><td>" + dinero(d.shipping_total) + "</td></tr>" : "") +
			'<tr class="t"><td>Total</td><td>' + dinero(d.total) + "</td></tr>" +
			// La ganancia, para quien administra, en las ventas hechas: con el costo congelado al venderse.
			(d.cost !== undefined && ["por_enviar", "enviado", "entregado"].includes(d.status) ? (d.profit !== null ? "<tr><td>Ganancia</td><td>" + dinero(d.profit) + (d.margin !== null ? ' <span class="sub">' + d.margin + " %</span>" : "") + "</td></tr>" : '<tr><td>Ganancia</td><td><span class="sub">sin costo en ' + d.cost_missing + (d.cost_missing === 1 ? " línea" : " líneas") + "</span></td></tr>") : "") +
			"</tbody></table></section>";
		h += '<section><h4>Cliente</h4><div class="kv">';
		h += "<span>Nombre</span><span>" + esc(d.customer || "Sin nombre") + "</span>";
		if (d.phone) h += "<span>Teléfono</span><span>" + esc(d.phone) + (d.whatsapp ? ' · <a href="' + esc(d.whatsapp) + '" target="_blank" rel="noopener">WhatsApp</a>' : "") + "</span>";
		if (d.email) h += '<span>Correo</span><span><a href="mailto:' + esc(d.email) + '">' + esc(d.email) + "</a></span>";
		if (dir) h += "<span>Dirección</span><span>" + esc(dir) + "</span>";
		if (d.note) h += "<span>Nota</span><span>" + esc(d.note) + "</span>";
		h += "</div></section>";
		h += '<section><h4>Pago y envío</h4><div class="kv">';
		h += "<span>Pago</span><span>" + esc(d.payment) + (pago ? " · " + pago : "") + "</span>";
		if (d.status === "apartado" && d.hold_until) h += "<span>Apartado</span><span>vence el " + esc(d.hold_until) + "</span>";
		if (d.tracking) h += "<span>Envío</span><span>" + (d.tracking_url ? '<a href="' + esc(d.tracking_url) + '" target="_blank" rel="noopener">' + esc(d.tracking) + "</a>" : esc(d.tracking)) + "</span>";
		if (d.completed_at) h += "<span>Entregado</span><span>" + esc(d.completed_at) + "</span>";
		h += "</div></section>";
		if (d.notes && d.notes.length) h += '<section><h4>Historial</h4><ul class="odn">' + d.notes.map((n) => '<li><span class="sub">' + esc(n.date) + "</span>" + esc(n.text) + "</li>").join("") + "</ul></section>";
		h += "</div>";
		h += '<div class="mbtn stick odb"><div class="oda"></div>' + (d.edit_url ? '<a class="mini sec" href="' + esc(d.edit_url) + '" target="_blank" rel="noopener">Abrir en WooCommerce</a>' : "") + '<button type="button" class="go alt" id="m-no">Cerrar</button></div>';
		modal(h, "wide");
		botonesPedido(d, $("#modal-card .oda"), true);
		$("#m-no").onclick = cerrarModal;
		$("#modal-card").querySelectorAll("[data-edit]").forEach((b) => { b.onclick = () => { cerrarModal(); editarDesdeLista(+b.dataset.edit); }; });
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
			let pago = "Paga al recibir";
			if (p.paid && !p.cod) pago = "Pagado";
			else if (p.status === "apartado" || p.status === "sin_pagar" || p.status === "fallido") pago = "Sin pagar";
			else if (p.status === "por_confirmar") pago = "En proceso";
			else if (p.status === "anulado" || p.status === "reembolsado") pago = "";
			// El canal: en la caja, por dónde entró la venta y quién la registró; en la web, la etiqueta y de dónde llegó el cliente.
			const canal = web
				? '<span class="tag w">' + esc(p.origin_label) + "</span>" + (p.source ? '<br><span class="sub">desde ' + esc(p.source) + "</span>" : "")
				: esc(p.channel) + '<br><span class="sub">Caja' + (p.seller ? " · " + esc(p.seller) : "") + "</span>";
			tr.innerHTML =
				'<td class="num">' + (p.id ? '<button type="button" class="lnk" data-ver="' + p.id + '">#' + esc(p.number) + "</button>" : "#" + esc(p.number)) + '<br><span class="sub">' + esc(p.date) + "</span></td>" +
				'<td><span class="who">' + esc(p.customer || "Sin nombre") + '</span><br><span class="sub">' + esc(p.city) + (p.phone ? " · " + esc(p.phone) : "") + "</span></td>" +
				'<td class="items"><button type="button" class="lnk items" data-ver="' + p.id + '">' + esc(p.items) + "</button>" + (p.note ? '<br><span class="sub">' + esc(p.note) + "</span>" : "") + "</td>" +
				"<td>" + canal + "</td>" +
				"<td>" + esc(p.payment) + '<br><span class="sub">' + pago + "</span></td>" +
				'<td class="num">' + dinero(p.total) + "</td>" +
				'<td><span class="tag ' + cls + '">' + esc(p.label) + "</span>" +
					(p.status === "apartado" ? '<br><span class="sub">vence en ' + p.hours_left + " h</span>" : "") +
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
				let t = s.demo ? "Es un pedido de demostración: no se mandan correos." : (s.email ? (s.sent ? "Le llegó un correo a " + esc(s.email) + " con la guía y el enlace de rastreo." : "No se pudo mandar el correo a " + esc(s.email) + ".") : "Este pedido no tiene correo.");
				if (s.whatsapp) t += " Si quieres, avísale también por WhatsApp: el mensaje ya va con la guía.";
				modal('<h3>Marcado como enviado</h3><p class="mp">' + t + '</p><div class="mbtn">' + (s.whatsapp ? '<a class="go" href="' + esc(s.whatsapp) + '" target="_blank" rel="noopener">Avisar por WhatsApp</a>' : "") + '<button type="button" class="go alt" id="m-no">Listo</button></div>');
				$("#m-no").onclick = cerrarModal;
				return;
			}
			const msgs = { paid: "Pago confirmado. Queda por enviar.", release: "Apartado liberado. El producto vuelve al inventario.", shipped: "Marcado como enviado.", delivered: "Entregado. Pedido cerrado.", cancel: "Pedido anulado. El inventario vuelve." };
			toast(act === "cancel" && (p.status === "sin_pagar" || p.status === "fallido") ? "Pedido anulado." : msgs[act]);
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
					toast("Entrada guardada. El inventario subió " + d.entry.units + (d.entry.units === 1 ? " unidad." : " unidades.") + (ch ? " El costo promedio cambió en " + ch + (ch === 1 ? " producto." : " productos.") : ""));
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
			const otra = await preguntar(e.message + " ¿Es otra entrada distinta?", "Sí, registrarla", "No");
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
		ul.innerHTML = st.entradas.length ? "" : '<li class="empty" style="padding:8px 2px">Todavía no hay entradas registradas.</li>';
		st.entradas.forEach((e) => {
			const li = document.createElement("li");
			li.className = "lin" + (e.status !== "ok" ? " off" : "");
			li.innerHTML =
				'<span class="n">' + esc(e.items) + "<i>" + esc(e.date) + (e.supplier ? " · " + esc(e.supplier) : "") + (e.invoice ? " · " + esc(e.invoice) : "") + (e.user ? " · " + esc(e.user) : "") + (e.cost ? " · " + dinero(e.cost) : "") + (e.status !== "ok" ? " · anulada" : "") + "</i></span>" +
				'<span class="v">+' + e.units + "</span>";
			if (e.status === "ok") {
				const b = document.createElement("button");
				b.type = "button";
				b.className = "undo";
				b.textContent = "Anular";
				b.onclick = () => confirmar("¿Anular esta entrada? " + (e.units === 1 ? "Se resta la unidad." : "Se restan las " + e.units + " unidades."), async () => {
					try {
						await post("entries/" + e.id + "/cancel", {});
						await Promise.all([cargarEntradas(), refrescarStock()]);
						toast("Entrada anulada.");
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
		const que = tipo === "entrada" ? "La entrada" : tipo === "apartado" ? "El apartado" : "La venta";
		toast("Sin señal. " + que + " quedó guardada en el teléfono y entra sola cuando vuelva la conexión.");
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
		let txt = navigator.onLine ? "<b>Pendiente de enviar.</b> " : "<b>Sin señal.</b> ";
		if (n) {
			txt += n + (n === 1 ? " registro guardado en el teléfono" : " registros guardados en el teléfono") + (navigator.onLine ? ", enviando…" : "; entran solos cuando vuelva la conexión.");
			if (navigator.onLine) txt += ' <button type="button" class="mini" id="cola-reintentar">Enviar ahora</button>';
		} else {
			txt += "Lo que registres se guarda en el teléfono y entra solo al volver la conexión.";
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
					if (!(await preguntar(e.message + " ¿Es otra entrada distinta?", "Sí, registrarla", "No, descartarla"))) {
						quitarDeCola(item.id);
						toast("Entrada descartada: " + item.resumen + ".");
						continue;
					}
					item.payload.force = true;
					d = await post(item.path, item.payload);
				}
				quitarDeCola(item.id);
				hechos++;
				if (item.tipo === "entrada") toast("Entrada guardada: " + item.resumen + ".");
				else if (item.tipo === "apartado") toast("Apartado registrado: pedido #" + d.order.number + ". En Pedidos tienes el botón de WhatsApp.");
				else toast("Venta registrada: pedido #" + d.order.number + ".");
			} catch (e) {
				if (e.red || e.message === "sesion") break; // sigue sin señal: se espera
				quitarDeCola(item.id);                        // la tienda lo rechazó (por ejemplo, ya no queda stock)
				toast("No se pudo registrar " + item.resumen + ": " + e.message);
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
			modal('<h3>Un momento</h3><p class="mp">' + esc(texto) + '</p><div class="mbtn"><button type="button" class="go" id="m-ok">' + esc(si || "Sí, seguir") + '</button><button type="button" class="go alt" id="m-no">' + esc(no || "No") + "</button></div>");
			pendiente = resolve;
			$("#m-ok").onclick = () => cerrarModal(true);
			$("#m-no").onclick = () => cerrarModal(false);
		});
	}
	function confirmar(texto, fn) {
		preguntar(texto).then((ok) => { if (ok) fn(); });
	}
	function modalWhatsApp(d) {
		modal('<h3>Apartado #' + esc(d.order.number) + '</h3><p class="mp">El producto ya quedó reservado. Se abre WhatsApp con el mensaje escrito: solo hay que darle enviar. Si no paga en ' + (cfg.hold_hours || 48) + ' horas, vuelve solo al inventario.</p><div class="burb">' + esc(d.message) + '</div><div class="mbtn"><a class="go" href="' + esc(d.whatsapp) + '" target="_blank" rel="noopener">Abrir WhatsApp</a><button type="button" class="go alt" id="m-no">Cerrar</button></div>');
		$("#m-no").onclick = cerrarModal;
	}
	function modalEnvio(p) {
		const carriers = (cfg.carriers || []).map((c) => (typeof c === "string" ? c : c.name));
		const lista = carriers.length ? '<datalist id="transportadoras">' + carriers.map((c) => '<option value="' + esc(c) + '"></option>').join("") + "</datalist>" : "";
		const pista = carriers.length ? carriers.slice(0, 2).join(", ") + "…" : "Interrapidísimo, Servientrega…";
		// Qué le va a llegar a la clienta: el correo con la guía si tiene correo; si no, el WhatsApp listo.
		const aviso = p.email && cfg.ship_email ? '<p class="mp">Al marcar enviado le llega un correo a ' + esc(p.email) + " con la transportadora, la guía y el enlace de rastreo.</p>" : (p.phone ? '<p class="mp">Sin correo: al marcar enviado te queda listo el WhatsApp con la guía.</p>' : "");
		modal('<h3>Enviar el pedido #' + esc(p.number) + '</h3><p class="mp">' + esc(p.customer || "") + (p.city ? " · " + esc(p.city) : "") + "</p>" + aviso + '<div class="field"><label for="m-car">Transportadora</label><input id="m-car" list="transportadoras" placeholder="' + esc(pista) + '" autocomplete="off">' + lista + '</div><div class="field mt"><label for="m-gui">Número de guía</label><input id="m-gui" placeholder="Opcional"></div><div class="mbtn"><button type="button" class="go" id="m-ok">Marcar enviado</button><button type="button" class="go alt" id="m-no">Cancelar</button></div>');
		$("#m-ok").onclick = () => {
			const extra = { carrier: $("#m-car").value.trim(), tracking: $("#m-gui").value.trim() };
			cerrarModal();
			accion(p, "shipped", extra);
		};
		$("#m-no").onclick = cerrarModal;
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
					if (e.message !== "sesion") toast("No se pudo cargar el formulario: " + e.message);
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
				s.textContent = f.ext || "FOTO";
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
			x.setAttribute("aria-label", "Quitar la foto");
			x.textContent = "×";
			x.onclick = (e) => { e.stopPropagation(); quitarFoto(f); };
			d.appendChild(x);
			if (f.estado !== "ok") {
				const s = document.createElement("span");
				s.className = "st" + (f.estado === "error" ? " err" : "");
				s.textContent = f.estado === "error" ? (f.error || "No se pudo subir") + " · toca para reintentar" : (f.estado === "subiendo" ? "Convirtiendo…" : "En cola");
				d.appendChild(s);
			} else if (pr.colores.length) {
				const sel = document.createElement("select");
				sel.setAttribute("aria-label", "Para qué color es la foto");
				sel.innerHTML = '<option value="">Todos los colores</option>' + pr.colores.map((c) => '<option value="' + esc(c.key) + '"' + (f.color === c.key ? " selected" : "") + ">" + esc(c.name) + "</option>").join("");
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
		add.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/></svg><span>' + (pr.fotos.length ? "Otra foto" : "Agregar fotos") + "</span>";
		add.onclick = () => $("#p-file").click();
		box.appendChild(add);
		const n = pr.fotos.length;
		$("#p-nfotos").textContent = n ? n + (n === 1 ? " foto" : " fotos") : "";
	}
	function agregarArchivos(files) {
		Array.from(files || []).forEach((file) => {
			if (cfg.max_upload && file.size > cfg.max_upload) { toast(file.name + " pesa más de lo que admite el servidor."); return; }
			const ext = (file.name.split(".").pop() || "").toUpperCase();
			const f = { uid: ++uidN, file: file, ext: ext.length <= 4 ? ext : "FOTO", estado: "cola", url: "", local: "", id: 0, color: "", kb: 0, error: "" };
			try { f.url = URL.createObjectURL(file); f.local = f.url; } catch (e) { /* sin vista previa */ }
			pr.fotos.push(f);
		});
		pintarFotos();
		pintarResumenProducto();
		procesarFotos();
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
			const fd = new FormData();
			fd.append("file", f.file, f.file.name || "foto");
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
			f.error = e.red ? "Sin señal" : (e.message === "sesion" ? "Sesión caducada" : e.message);
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
				const label = c.id === c.group_id ? (g.cats.length > 1 ? c.name + " en general" : c.name) : dentro + c.name;
				const ch = chip(label, pr.cats.includes(c.id), () => toggleCat(c));
				ch.title = c.count + (c.count === 1 ? " producto" : " productos");
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
			if (d.sku) { $("#p-sku").value = d.sku; pr.skuOk = true; estadoSku("Libre: el siguiente de " + prefix + ".", "ok"); }
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
		estadoSku("Comprobando…", "");
		skuTimer = setTimeout(async () => {
			const n = ++skuReq;
			try {
				const d = await api("products/sku?sku=" + encodeURIComponent(v));
				if (n !== skuReq) return;
				pr.skuOk = d.ok;
				estadoSku(d.ok ? "Libre." : d.message, d.ok ? "ok" : "bad");
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
			box.innerHTML = "Ya existe un producto llamado <b>" + esc(igual.name) + "</b>" + (igual.sku ? " (" + esc(igual.sku) + ")" : "") + (igual.status !== "publish" ? ", oculto" : "") + ". Si es el mismo, mejor ";
			const b = document.createElement("button");
			b.type = "button";
			b.className = "undo";
			b.textContent = "edítalo";
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
		if (!precio && !costo) { el.textContent = "Escribe precio y costo."; return; }
		if (!costo) { el.textContent = pr.edit && pr.edit.cost === "" ? "Las tallas cuestan distinto: de " + dinero(pr.edit.cost_min) + " a " + dinero(pr.edit.cost_max) + "." : "Sin costo: sus ventas no entran en la ganancia."; return; }
		if (!precio) { el.textContent = "Falta el precio."; return; }
		const g = precio - costo;
		el.textContent = dinero(g) + " por unidad (" + Math.round(g / precio * 100) + " %)" + (g < 0 ? ": se vende a pérdida" : "");
		if (g < 0) el.className = "margen bad";
	}

	// Colores: los más usados (doce) y "Más colores…" para el resto; "Otro color…" crea uno nuevo con su tono.
	function pintarColores() {
		const box = $("#p-colores");
		box.innerHTML = "";
		$("#g-colores").hidden = !(pr.form && pr.form.has_color) || !!(pr.edit && pr.edit.type !== "variable");
		if (!pr.form || !pr.form.has_color || (pr.edit && pr.edit.type !== "variable")) return;
		if (pr.edit && !pr.edit.colors.length) { // Un producto que no varía por color no gana colores desde aquí.
			box.innerHTML = '<span class="hint">Este producto no tiene colores. Si los necesita, hay que crearlos en WooCommerce.</span>';
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
			const mas = chip("Más colores (" + (all.length - ver.length) + ")…", false, () => { pr.masColores = true; pintarColores(); });
			mas.classList.add("plus");
			box.appendChild(mas);
		}
		const nuevo = chip("Otro color…", false, () => {
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
			box.innerHTML = '<span class="hint">Este producto no tiene tallas. Si las necesita, hay que crearlas en WooCommerce.</span>';
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
			const mas = chip(ver.length ? "Otras tallas…" : "Elegir tallas…", false, () => { pr.masTallas = true; pintarTallas(); });
			mas.classList.add("plus");
			box.appendChild(mas);
		}
		const n = pr.tallas.length;
		$("#p-ntal").textContent = n ? n + (n === 1 ? " talla" : " tallas") : "talla única";
		if (pr.edit) hint.textContent = "";
		else if (!pr.cats.length && !n) hint.textContent = "Elige la categoría y salen sus tallas.";
		else if (!n) hint.textContent = pr.cats.length && !deCat.length && !pr.masTallas ? "Esta categoría no usa tallas: queda de talla única." : "Sin tallas: talla única.";
		else if (!pr.tallasTocadas) hint.textContent = "Marcadas por la categoría. Si el producto no viene en todas, toca las que sobran para quitarlas.";
		else hint.textContent = "";
	}
	const tallasOrdenadas = () => ((pr.form && pr.form.sizes) || []).filter((t) => pr.tallas.includes(t.id));

	// Unidades: una casilla por talla y color. Empiezan vacías (0): lo que no se escribe no existe.
	const qKey = (ckey, sid) => ckey + "|" + sid;
	const cantidad = (ckey, sid) => { const v = pr.qty[qKey(ckey, sid)]; return v === undefined ? 0 : v; };
	const columnas = () => (pr.colores.length ? pr.colores : [{ key: "", name: "Unidades", hex: "" }]);
	const filas = () => { const r = tallasOrdenadas(); return r.length ? r : [{ id: 0, name: "Talla única", label: "" }]; };
	function pintarCantidades() {
		const t = $("#p-qty");
		const shared = !!(pr.edit && pr.edit.shared !== null && pr.edit.shared !== undefined);
		$("#p-qty-shared").hidden = !shared;
		$("#p-todo1").hidden = shared;
		$("#p-todo0").hidden = shared;
		if (shared) { // Existencias en conjunto para todas las tallas: se cambian por mercancía o en WooCommerce.
			t.innerHTML = "";
			$("#p-qty-shared").textContent = "Este producto no lleva las unidades por talla, sino un total para todas: " + pr.edit.shared + (pr.edit.shared === 1 ? " unidad" : " unidades") + ". Para cambiarlo, registra la mercancía en Entró mercancía o hazlo en WooCommerce. Una talla nueva usa ese mismo total.";
			return;
		}
		const cols = columnas();
		let h = "<thead><tr><th></th>" + cols.map((c) => "<th>" + (c.hex ? '<i class="dot" style="background:' + esc(c.hex) + '"></i>' : "") + esc(c.name) + "</th>").join("") + "</tr></thead><tbody>";
		filas().forEach((r) => {
			h += '<tr><th scope="row" title="' + esc(r.name) + '">' + esc(r.label || r.name) + "</th>" +
				cols.map((c) => { const v = pr.qty[qKey(c.key, r.id)]; return '<td><input inputmode="numeric" placeholder="0" data-c="' + esc(c.key) + '" data-s="' + r.id + '" value="' + (v === undefined ? "" : v) + '" aria-label="' + esc(r.name + ", " + c.name) + '"></td>'; }).join("") + "</tr>";
		});
		t.innerHTML = h + "</tbody>";
		t.querySelectorAll("input").forEach((inp) => {
			inp.addEventListener("input", () => { pr.qty[qKey(inp.dataset.c, inp.dataset.s)] = num(inp.value); pintarResumenProducto(); });
			inp.addEventListener("focus", () => inp.select());
		});
	}
	function ponerTodas(n) {
		filas().forEach((r) => columnas().forEach((c) => { pr.qty[qKey(c.key, r.id)] = n; }));
		pintarCantidades();
		pintarResumenProducto();
	}
	function unidadesTotales() {
		if (pr.edit && pr.edit.shared !== null && pr.edit.shared !== undefined) return pr.edit.shared;
		let u = 0;
		filas().forEach((r) => columnas().forEach((c) => { u += cantidad(c.key, r.id); }));
		return u;
	}

	// Qué falta, y en qué campo. El aviso se queda debajo del campo hasta que se arregla.
	function problemaProducto() {
		if (!$("#p-nom").value.trim()) return { msg: "Ponle nombre al producto.", sel: "#p-nom" };
		if (!pr.cats.length) return { msg: "Elige una categoría.", sel: "#p-cats" };
		if (num($("#p-precio").value) <= 0 && !(pr.edit && pr.edit.price === "")) return { msg: "Ponle precio.", sel: "#p-precio" }; // Editando un producto con precios distintos por talla, vacío = no tocarlos.
		if (!pr.edit && pr.form && pr.form.sku_format !== "none" && !$("#p-sku").value.trim()) return { msg: "Falta el código.", sel: "#p-sku" };
		if (!pr.edit && pr.skuOk === false) return { msg: "Ese código ya está ocupado.", sel: "#p-sku" };
		if (pr.fotos.some((f) => f.estado === "error")) return { msg: "Una foto no se pudo subir: tócala para reintentar, o quítala.", sel: "#p-fotos" };
		if (pr.fotos.some((f) => f.estado !== "ok")) return { msg: "Espera a que terminen de subir las fotos.", sel: "#p-fotos" };
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
			? vars + (vars === 1 ? " variación" : " variaciones") + (nt && nc ? " (" + nt + (nt === 1 ? " talla" : " tallas") + " × " + nc + (nc === 1 ? " color" : " colores") + ")" : "")
			: "Producto de talla única";
		if (pr.edit && pr.edit.type !== "variable") que = "Producto de talla única";
		const precio = num($("#p-precio").value);
		const txtPrecio = precio > 0 ? dinero(precio) : (pr.edit && pr.edit.price === "" ? "de " + dinero(pr.edit.price_min) + " a " + dinero(pr.edit.price_max) : dinero(0));
		const costo = costoForm();
		const txtCosto = costo > 0 ? dinero(costo) + (precio > 0 ? " · deja " + dinero(precio - costo) + " (" + Math.round((precio - costo) / precio * 100) + " %)" : "") : (pr.edit && pr.edit.cost === "" ? "de " + dinero(pr.edit.cost_min) + " a " + dinero(pr.edit.cost_max) : "");
		$("#p-sum").innerHTML = "<div><span>" + esc(que) + "</span><span>" + u + (u === 1 ? " unidad" : " unidades") + "</span></div>" +
			(cfg.costs && txtCosto ? "<div><span>Costo</span><span>" + txtCosto + "</span></div>" : "") +
			'<div class="tot"><span>Precio</span><span>' + txtPrecio + "</span></div>";
		pintarMargen();
		$("#p-crear").textContent = pr.edit ? "Guardar cambios" : "Revisar y crear";
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
			if (!fotosOk.length) avisos.push("Sin foto: en la tienda saldrá con un cuadro gris.");
			if (!u) avisos.push("Sin unidades: saldrá agotado.");
			const pr0 = form.price_range || [0, 0];
			if (pr0[0] > 0 && (precio < pr0[0] / 2 || precio > pr0[1] * 2)) avisos.push("El precio se sale de lo habitual: en la tienda va de " + dinero(pr0[0]) + " a " + dinero(pr0[1]) + ". Revisa los ceros.");
			if (pr.dup) avisos.push("Ya existe un producto llamado " + pr.dup.name + (pr.dup.sku ? " (" + pr.dup.sku + ")" : "") + ".");
			if (cfg.costs && costoForm() > precio) avisos.push("El costo es mayor que el precio: se vendería a pérdida.");
			if (pr.tallas.length > 1 && !pr.tallasTocadas) avisos.push("Las " + pr.tallas.length + " tallas las marcó la categoría. Si el producto no viene en todas, vuelve y quita las que sobran.");
			let tabla = "";
			if (pr.tallas.length || pr.colores.length) {
				tabla = '<table class="revt"><thead><tr><th></th>' + cols.map((c) => "<th>" + esc(c.name) + "</th>").join("") + "</tr></thead><tbody>" +
					rows.map((r) => "<tr><th>" + esc(r.label || r.name) + "</th>" + cols.map((c) => '<td class="' + (cantidad(c.key, r.id) ? "" : "zero") + '">' + cantidad(c.key, r.id) + "</td>").join("") + "</tr>").join("") + "</tbody></table>";
			}
			const li = (k, v) => "<div><dt>" + k + "</dt><dd>" + v + "</dd></div>";
			modal('<h3>Revisa antes de crear</h3><dl class="rev">' +
				li("Nombre", esc($("#p-nom").value.trim())) +
				li("Código", esc($("#p-sku").value.trim() || "sin código")) +
				li("Precio", dinero(precio)) +
				(cfg.costs ? li("Costo", costoForm() ? dinero(costoForm()) + " · deja " + dinero(precio - costoForm()) + " por unidad" : "Sin costo") : "") +
				li("Categoría", esc(cats.join(", "))) +
				(pr.colores.length ? li("Colores", esc(pr.colores.map((c) => c.name).join(", "))) : "") +
				li("Unidades", u + (u === 1 ? " unidad" : " unidades") + tabla) +
				li("Fotos", fotosOk.length ? fotosOk.length + (fotosOk.length === 1 ? " foto" : " fotos") : "Sin foto") +
				li("Tienda", $("#p-pub").checked ? "Se publica ahora" : "Queda oculto") +
				"</dl>" +
				(avisos.length ? '<ul class="revwarn">' + avisos.map((a) => "<li>" + esc(a) + "</li>").join("") + "</ul>" : "") +
				'<div class="mbtn stick">' + (fotosOk.length
					? '<button type="button" class="go" id="m-ok">Crear producto</button><button type="button" class="go alt" id="m-no">Volver a revisar</button>'
					: '<button type="button" class="go" id="m-foto">Agregar foto</button><button type="button" class="go alt" id="m-ok">Crear sin foto</button><button type="button" class="go alt" id="m-no">Volver</button>') + "</div>");
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
		if (!navigator.onLine) { toast("Para crear un producto hace falta conexión."); return; }
		if (!pr.edit) {
			if (!(await modalRevisar())) return;
		} else if (!pr.fotos.length && !(await preguntar("El producto quedará sin foto. ¿Guardarlo así?", "Sí, guardar", "No"))) return;
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
			images: pr.fotos.filter((f) => f.id).map((f) => ({ id: f.id, color: f.color || "" })),
		};
		try {
			const d = await post(pr.edit ? "products/" + pr.edit.id : "products", payload);
			if (pr.edit) modalProductoGuardado(d.product);
			else { borrarBorrador(); modalProductoCreado(d.product); }
			limpiarProducto();
		} catch (e) {
			if (e.red) toast("Sin señal. Vuelve a intentarlo cuando vuelva la conexión: las fotos ya están subidas.");
			else if (e.message !== "sesion") toast(e.message);
		}
		pr.creando = false;
		soltar();
		pintarResumenProducto();
	}
	function modalProductoCreado(p) {
		const que = p.variations ? p.variations + (p.variations === 1 ? " variación" : " variaciones") : "talla única";
		modal("<h3>" + esc(p.name) + '</h3><p class="mp">' + (p.status === "publish" ? "Ya está en la tienda" : "Guardado oculto, sin publicar") + " · " + esc(p.sku || "sin código") + " · " + que + " · " + p.units + (p.units === 1 ? " unidad" : " unidades") + '.</p><div class="mbtn"><a class="go" href="' + esc(p.url) + '" target="_blank" rel="noopener">Ver en la tienda</a><button type="button" class="go alt" id="m-fix">Corregir algo</button><button type="button" class="go alt" id="m-no">Crear otro</button></div>');
		$("#m-no").onclick = cerrarModal;
		$("#m-fix").onclick = () => { cerrarModal(); editarDesdeLista(p.id); };
	}
	function limpiarProducto() {
		pr.fotos.forEach((f) => { if (f.local) { try { URL.revokeObjectURL(f.local); } catch (e) { /* nada */ } } });
		pr.fotos = []; pr.cats = []; pr.colores = []; pr.tallas = []; pr.qty = {};
		pr.manual = false; pr.tallasTocadas = false; pr.skuOk = undefined;
		pr.grupo = null; pr.masColores = false; pr.masTallas = false; pr.dup = null;
		["#p-nom", "#p-precio", "#p-costo", "#p-sku", "#p-desc", "#p-color-nom"].forEach((s) => { const el = $(s); if (el) el.value = ""; });
		$("#p-pub").checked = true;
		$("#p-nuevocolor").hidden = true;
		$("#p-nom-dup").hidden = true;
		estadoSku("", "");
		pr.edit = null;
		$("#p-sku").disabled = false;
		$("#p-editbar").hidden = true;
		$("#p-pub-text").textContent = "Publicar en la tienda ahora";
		$("#p-pub-hint").textContent = "Apagado: queda guardado pero oculto; lo publicas después desde Editar uno.";
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
		const que = (b.nombre || "").trim() || "uno sin nombre todavía";
		const viejo = Date.now() - (b.ts || 0) > 20 * 3600 * 1000;
		if (await preguntar("Tienes un producto a medias: " + que + ". ¿Seguir con él?", "Seguir", "Empezar de cero")) restaurarBorrador(b, viejo);
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
		if (sinFotos && b.fotos && b.fotos.length) toast("Las fotos de ese borrador ya se borraron por el tiempo pasado: vuelve a subirlas.");
	}
	function vaciarProducto() {
		confirmar("¿Empezar de cero? Se borra lo escrito.", () => {
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
		if (!items.length) { ul.innerHTML = vacio($("#p-q").value.trim() ? "Nada con eso. Prueba con una palabra o con el código." : "La tienda no tiene productos."); return; }
		items.forEach((p) => {
			const li = document.createElement("li");
			const b = document.createElement("button");
			b.type = "button";
			b.className = "prod";
			const estado = p.status === "publish" ? "" : '<i class="oculto">' + (p.status === "private" ? "Oculto" : "Borrador") + "</i>";
			const que = p.type === "variable" ? p.variations + (p.variations === 1 ? " variación" : " variaciones") : "talla única";
			b.innerHTML = '<span class="n">' + esc(p.name) + "<i>" + esc(p.sku || "sin código") + " · " + que + "</i>" + estado + "</span>" + '<span class="p">' + dinero(p.price) + "</span>";
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
		estadoSku(d.sku ? "El código solo se cambia en WooCommerce." : "Sin código.", "");
		$("#p-desc").value = d.description || "";
		$("#p-pub").checked = d.status === "publish";
		$("#p-pub-text").textContent = "Publicado en la tienda";
		$("#p-pub-hint").textContent = "Apagado: queda oculto, ni se ve ni se vende.";
		const que = d.type === "variable" ? d.variations + (d.variations === 1 ? " variación" : " variaciones") : "talla única";
		$("#p-edit-title").textContent = "Editando " + (d.sku ? d.sku + " · " : "") + d.name + " (" + que + ")";
		$("#p-edit-hint").textContent = d.price === "" ? "Las tallas tienen precios distintos (de " + dinero(d.price_min) + " a " + dinero(d.price_max) + "): si escribes un precio, se pone a todas." : "Los cambios se guardan con el botón de abajo.";
		$("#p-edit-view").href = d.url || "#";
		$("#p-edit-view").title = d.status === "publish" ? "Se abre en otra pestaña" : "Está oculto: lo ves tú porque tienes sesión, la clienta no";
		$("#p-editbar").hidden = false;
		pintarProducto();
		pintarModo();
		$("#t-producto .scroll").scrollTop = 0;
	}
	function modalProductoGuardado(p) {
		const que = p.variations ? p.variations + (p.variations === 1 ? " variación" : " variaciones") : "talla única";
		modal('<h3>Guardado</h3><p class="mp">' + esc(p.name) + " · " + (p.status === "publish" ? "publicado" : "oculto") + " · " + esc(p.sku || "sin código") + " · " + que + " · " + p.units + (p.units === 1 ? " unidad" : " unidades") + '.</p><div class="mbtn"><a class="go" href="' + esc(p.url) + '" target="_blank" rel="noopener">Ver en la tienda</a><button type="button" class="go alt" id="m-no">Listo</button></div>');
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
	const DIAS = ["dom", "lun", "mar", "mié", "jue", "vie", "sáb"];
	function diaBonito(iso) {
		const d = new Date(iso + "T12:00:00");
		return isNaN(d) ? iso : DIAS[d.getDay()] + " " + d.getDate() + "/" + String(d.getMonth() + 1).padStart(2, "0");
	}
	function textoPeriodo() {
		const [a, b] = rangoHistorial();
		if (a === b) return hi.periodo === "hoy" ? "de hoy, " + diaBonito(a) : "del " + diaBonito(a);
		return "del " + diaBonito(a) + " al " + diaBonito(b);
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
	const excelLink = (que) => '<a class="undo dl" href="' + esc(excelHistorial(que)) + '" download>Descargar en Excel</a>';
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
		if (!box.dataset.listo) box.innerHTML = '<p class="empty">Cargando…</p>';
		box.style.opacity = ".55";
		try {
			const d = await def.cargar(a, b);
			if (n !== hi.req) return;
			box.dataset.listo = "1";
			def.pintar(d);
		} catch (e) {
			if (n === hi.req && e.message !== "sesion") box.innerHTML = '<p class="empty">' + esc(e.red ? "Sin señal: el historial se lee de la tienda." : e.message) + "</p>";
		}
		if (n === hi.req) box.style.opacity = "";
	}
	const kpi = (v, l) => '<div class="kpi"><b>' + v + "</b><span>" + esc(l) + "</span></div>";
	function listaHist(titulo, arr, conUnidades) {
		if (!arr || !arr.length) return "";
		return '<div class="grp"><h4>' + esc(titulo) + '</h4><ul class="bl">' + arr.map((x) =>
			"<li><span>" + esc(x.label || x.name) + "</span><span>" + x.n + (x.n === 1 ? " venta" : " ventas") + (conUnidades && x.units !== undefined ? " · " + x.units + (x.units === 1 ? " unidad" : " unidades") : "") + " · <b>" + dinero(x.total) + "</b></span></li>"
		).join("") + "</ul></div>";
	}
	function filaPedidoHist(p, conCosto) {
		const vendido = ["por_enviar", "enviado", "entregado"].includes(p.status);
		const cls = { apartado: "b", sin_pagar: "b", por_confirmar: "b", fallido: "b", por_enviar: "a", enviado: "e", entregado: "c", anulado: "d", reembolsado: "d" }[p.status] || "e";
		const web = p.origin !== "caja";
		const canal = web ? '<span class="tag w">' + esc(p.origin_label) + "</span>" : esc(p.channel) + (p.seller ? '<br><span class="sub">' + esc(p.seller) + "</span>" : "");
		return '<tr class="' + (p.status === "anulado" || p.status === "reembolsado" ? "off" : "") + '"><td class="num">' + (p.id ? '<button type="button" class="lnk" data-ver="' + p.id + '">#' + esc(p.number) + "</button>" : "#" + esc(p.number)) + '<br><span class="sub">' + esc(p.date) + "</span></td>" +
			'<td><span class="who">' + esc(p.customer || "Sin nombre") + '</span><br><span class="sub">' + esc(p.city) + "</span></td>" +
			'<td class="items">' + esc(p.items) + "</td><td>" + canal + "</td>" +
			"<td>" + esc(p.payment) + (p.cod && p.status !== "entregado" && p.status !== "anulado" ? '<br><span class="sub">paga al recibir</span>' : "") + "</td>" +
			'<td class="num">' + dinero(p.total) + "</td>" +
			(conCosto ? '<td class="num">' + (vendido ? (p.profit !== null && p.profit !== undefined ? dinero(p.profit) + '<br><span class="sub">' + p.margin + " %</span>" : '<span class="sub">sin costo</span>') : "") + "</td>" : "") +
			'<td><span class="tag ' + cls + '">' + esc(p.label) + "</span></td></tr>";
	}
	function pintarVentas(d) {
		const t = d.totals;
		const full = !!cfg.history_full;
		let h = '<p class="hsub">' + (full ? "Ventas " : "Tus ventas ") + esc(textoPeriodo()) + "</p>";
		const conCosto = !!d.costs; // Solo quien administra, con los costos encendidos.
		h += '<div class="kpis">' + kpi(dinero(t.sold), "Vendido") + kpi(t.orders, t.orders === 1 ? "Venta" : "Ventas") + kpi(t.units, t.units === 1 ? "Unidad" : "Unidades") + kpi(dinero(t.avg), "Por venta") +
			(conCosto ? kpi(dinero(t.profit), "Ganancia" + (t.margin !== null ? " · " + t.margin + " %" : "")) : "") +
			(t.pending_n ? kpi(dinero(t.pending), "Sin pagar aún (" + t.pending_n + ")") : "") + "</div>";
		if (conCosto && t.no_cost_n) h += '<p class="hint">' + t.no_cost_n + (t.no_cost_n === 1 ? " venta no tiene" : " ventas no tienen") + " el costo de todos sus productos y no " + (t.no_cost_n === 1 ? "entra" : "entran") + " en la ganancia. El costo se pone en Productos, o se carga de golpe desde el Excel del inventario en Entró mercancía.</p>";
		if (full) h += '<div class="brk">' + listaHist("Por canal", d.by_channel) + listaHist("Por forma de pago", d.by_payment) + listaHist("Por vendedora", d.by_seller) +
			(d.by_day.length > 1 ? listaHist("Por día", d.by_day.map((x) => Object.assign({}, x, { label: diaBonito(x.name) })), true) : "") + "</div>";
		h += '<div class="grp"><h4>Pedidos <span class="cnt">' + d.count + (d.count > d.items.length ? " · los últimos " + d.items.length : "") + (d.count ? " " + excelLink("ventas") : "") + "</span></h4>";
		if (!d.items.length) h += '<p class="empty">Nada en este periodo.</p>';
		else h += '<div class="wrapx2"><table class="ped hped"><thead><tr><th>Pedido</th><th>Cliente</th><th>Productos</th><th>Canal</th><th>Pago</th><th class="num">Total</th>' + (conCosto ? '<th class="num">Ganancia</th>' : "") + '<th>Estado</th></tr></thead><tbody>' + d.items.map((p) => filaPedidoHist(p, conCosto)).join("") + "</tbody></table></div>";
		h += "</div>";
		$("#h-ventas").innerHTML = h;
	}
	function pintarCaja(d) {
		const t = d.totals;
		let h = '<p class="hsub">La caja ' + esc(textoPeriodo()) + "</p>";
		const conCosto = !!d.costs;
		h += '<div class="kpis">' + kpi(dinero(t.cashed), "Cobrado") + kpi(dinero(t.sold), "Vendido") + (conCosto ? kpi(dinero(t.profit), "Ganancia") : "") + kpi(dinero(t.cod), "Por cobrar al entregar" + (t.cod_n ? " (" + t.cod_n + ")" : "")) + kpi(dinero(t.holds), "Apartado sin pagar" + (t.holds_n ? " (" + t.holds_n + ")" : "")) + "</div>";
		if (conCosto && t.no_cost_n) h += '<p class="hint">' + t.no_cost_n + (t.no_cost_n === 1 ? " venta sin costo completo no entra" : " ventas sin costo completo no entran") + " en la ganancia.</p>";
		if (d.method_totals && d.method_totals.length) h += '<div class="brk">' + listaHist("Cobrado por forma de pago", d.method_totals) + "</div>";
		h += '<div class="grp"><h4>Por día' + (d.days.length ? ' <span class="cnt">' + excelLink("caja") + "</span>" : "") + "</h4>";
		if (!d.days.length) h += '<p class="empty">Nada en este periodo.</p>';
		else {
			const tot = (m) => (d.method_totals.find((x) => x.name === m) || {}).total || 0;
			h += '<div class="wrapx2"><table class="ped hped"><thead><tr><th>Día</th><th class="num">Ventas</th><th class="num">Vendido</th>' + (conCosto ? '<th class="num">Costo</th><th class="num">Ganancia</th>' : "") + d.methods.map((m) => '<th class="num">' + esc(m) + "</th>").join("") + '<th class="num">Por cobrar</th><th class="num">Apartado</th></tr></thead><tbody>';
			d.days.forEach((r) => {
				h += "<tr><td>" + esc(diaBonito(r.day)) + '</td><td class="num">' + r.n + '</td><td class="num">' + dinero(r.sold) + "</td>" + (conCosto ? '<td class="num">' + (r.n ? dinero(r.cost) : "") + '</td><td class="num">' + (r.n ? dinero(r.profit) : "") + "</td>" : "") + d.methods.map((m) => '<td class="num">' + (r.methods[m] ? dinero(r.methods[m]) : "") + "</td>").join("") + '<td class="num">' + (r.cod ? dinero(r.cod) : "") + '</td><td class="num">' + (r.holds ? dinero(r.holds) : "") + "</td></tr>";
			});
			if (d.days.length > 1) h += '<tr class="tot"><td>Total</td><td class="num">' + t.orders + '</td><td class="num">' + dinero(t.sold) + "</td>" + (conCosto ? '<td class="num">' + dinero(t.cost) + '</td><td class="num">' + dinero(t.profit) + "</td>" : "") + d.methods.map((m) => '<td class="num">' + dinero(tot(m)) + "</td>").join("") + '<td class="num">' + dinero(t.cod) + '</td><td class="num">' + dinero(t.holds) + "</td></tr>";
			h += "</tbody></table></div>";
		}
		h += "</div>";
		if (d.pending && d.pending.length) {
			h += '<div class="grp"><h4>Por cobrar <span class="cnt">' + d.pending.length + '</span></h4><ul class="bl">' + d.pending.map((p) =>
				"<li><span>#" + esc(p.number) + " · " + esc(p.customer || "Sin nombre") + ' <span class="sub">' + (p.kind === "cod" ? "contraentrega, " : "") + esc(String(p.label).toLowerCase()) + "</span></span><span><b>" + dinero(p.total) + "</b></span></li>"
			).join("") + "</ul></div>";
		}
		$("#h-caja").innerHTML = h;
	}
	function pintarTipos() {
		const tipos = [["", "Todos"], ["ventas", "Ventas"], ["entradas", "Entradas"], ["devueltos", "Devueltos"], ["ajustes", "Ajustes"]];
		chips($("#h-tipo"), tipos, hi.tipo, (x) => { hi.tipo = x; hi.page = 1; cargarHistorial(); });
	}
	function pintarMovimientos(d) {
		let h = '<p class="hsub">Movimientos ' + esc(textoPeriodo()) + "</p>";
		if (hi.producto) h += '<p class="hint">Solo <b>' + esc(hi.productoNombre) + '</b> <button type="button" class="undo" id="h-solo-x">ver todos</button></p>';
		h += '<p class="hint">' + (d.total ? d.total + (d.total === 1 ? " movimiento" : " movimientos") + " · entraron <b>+" + d.in + "</b> · salieron <b>−" + d.out + "</b> · " + excelLink("movimientos") : "Sin movimientos en este periodo. Aquí queda cada venta, entrada, anulación y cambio de existencias, con su motivo y quién lo hizo.") + "</p>";
		if (d.items.length) {
			const conCosto = !!d.costs; // El costo por unidad de cada movimiento (el de compra en una entrada), para quien administra.
			h += '<div class="wrapx2"><table class="ped kdx"><thead><tr><th>Cuándo</th><th>Producto</th><th class="num">Había</th><th class="num">Cambio</th><th class="num">Quedan</th>' + (conCosto ? '<th class="num">Costo unit.</th>' : "") + '<th>Motivo</th><th>Quién</th></tr></thead><tbody>';
			d.items.forEach((r) => {
				h += '<tr><td class="num"><span class="sub">' + esc(r.date) + '</span></td><td><button type="button" class="linkp" data-pid="' + r.product_id + '" data-name="' + esc(r.name) + '">' + esc(r.name) + '</button><br><span class="sub">' + esc(r.sku) + '</span></td><td class="num">' + (r.before === null ? "" : r.before) + '</td><td class="num"><span class="delta ' + (r.delta > 0 ? "in" : "out") + '">' + (r.delta > 0 ? "+" : "−") + Math.abs(r.delta) + '</span></td><td class="num"><b>' + (r.after === null ? "" : r.after) + "</b></td>" + (conCosto ? '<td class="num">' + (r.unit_cost === null || r.unit_cost === undefined ? "" : dinero(r.unit_cost)) + "</td>" : "") + "<td>" + esc(r.label) + (r.note ? '<br><span class="sub">' + esc(r.note) + "</span>" : "") + "</td><td>" + esc(r.user) + "</td></tr>";
			});
			h += "</tbody></table></div>";
			if (d.pages > 1) h += '<div class="pager"><button type="button" class="mini" id="h-prev"' + (d.page <= 1 ? " disabled" : "") + '>Anteriores</button><span>' + ((d.page - 1) * 50 + 1) + "-" + Math.min(d.total, d.page * 50) + " de " + d.total + '</span><button type="button" class="mini" id="h-next"' + (d.page >= d.pages ? " disabled" : "") + ">Siguientes</button></div>";
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
		const soltar = ocupar($("#e-costos"), "Subiendo…");
		try {
			const fd = new FormData();
			fd.append("file", f, f.name);
			const r = await api("costs/import", { method: "POST", body: fd });
			let t = r.updated + (r.updated === 1 ? " costo cambiado" : " costos cambiados") + " de " + r.rows + (r.rows === 1 ? " fila." : " filas.");
			if (r.same) t += " " + r.same + " ya " + (r.same === 1 ? "estaba" : "estaban") + " igual.";
			if (r.skipped) t += " " + r.skipped + (r.skipped === 1 ? " fila sin costo" : " filas sin costo") + " (se saltan).";
			if (r.missing) t += " " + r.missing + (r.missing === 1 ? " código no está" : " códigos no están") + " en la tienda" + (r.missing_list && r.missing_list.length ? ": " + r.missing_list.map(esc).join(", ") : "") + ".";
			modal("<h3>Costos cargados</h3><p class=\"mp\">" + t + '</p><div class="mbtn"><button type="button" class="go alt" id="m-no">Listo</button></div>');
			$("#m-no").onclick = cerrarModal;
		} catch (e) {
			if (e.message !== "sesion") toast(e.red ? "Sin señal: no se pudo subir el archivo." : e.message);
		}
		soltar();
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
	pestaña({ id: "entrada", abrir: cargarEntradas });
	pestaña({ id: "pedidos", abrir: cargarPedidos });
	pestaña({ id: "producto", abrir: abrirProducto });
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
	function abrirDesdeHash() {
		const [tabH, vistaH, extraH] = location.hash.replace(/^#/, "").split("/");
		if (!tabH || tabH === "vender") return;
		const t = document.querySelector('#tabs button[data-t="' + tabH + '"]');
		if (!t) return;
		const idH = /^\d+$/.test(vistaH || "") ? +vistaH : 0;
		const def = pestañas[tabH];
		if (def && def.desdeHash) def.desdeHash(vistaH || "", extraH || "");
		setTimeout(() => {
			t.click();
			if (tabH === "pedidos" && idH) verPedido(idH);
			if (tabH === "producto" && idH) editarDesdeLista(idH);
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
			$("#p-todo1").onclick = () => ponerTodas(1);
			$("#p-todo0").onclick = () => ponerTodas(0);
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
		abrirDesdeHash();
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
		document.addEventListener("click", (e) => { const b = e.target.closest("[data-ver]"); if (b) { e.preventDefault(); verPedido(+b.dataset.ver); } });
		// Y tocar el nombre de un producto (en el resumen, los consejos o lo que se agota) abre su ficha.
		document.addEventListener("click", (e) => { const b = e.target.closest("[data-prod]"); if (b) { e.preventDefault(); editarDesdeLista(+b.dataset.prod); } });
		document.addEventListener("keydown", (e) => { if (e.key === "Escape" && !$("#modal").hidden) cerrarModal(); });
		const hoy = new Date();
		$("#e-fec").value = hoy.getFullYear() + "-" + String(hoy.getMonth() + 1).padStart(2, "0") + "-" + String(hoy.getDate()).padStart(2, "0");
		pintarChips();
		pintarDepartamentos();
		pintarTodo();
		pintarEnvio();
		listaDe("venta").innerHTML = vacio("Escribe dos letras del nombre, o el código.");
		listaDe("entrada").innerHTML = vacio("Busca lo que llegó por nombre o por código.");
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
		verPedido, editarDesdeLista, cargarPedidos, accion, refrescarStock, fijarHash, hayProducto,
		vistaHistorial, rangoHistorial, textoPeriodo, excelLink, diaBonito, listaHist,
		pestaña, on, emit, pestañaActual: () => st.tab, arrancar: init,
	};
})();
