# Dox POS

Plugin de WordPress para registrar pedidos y mercancía desde el frontend, en `/caja`, sin entrar a wp-admin, con el historial de ventas, la caja del día, el kardex y un asistente de negocio (la API de OpenAI). Escribe en WooCommerce: cada venta es un pedido y el inventario baja solo.

Primera instalación: rosella.com.co (septiembre de 2026). Pensado para reinstalarlo en otras tiendas: todo lo que cambia de una marca a otra se ajusta en WooCommerce > Dox POS, sin tocar código.

## Cómo está hecho

| Carpeta | Qué hay |
|---|---|
| `dox-pos.php` | Cabecera, constantes, activación (rol + ruta). |
| `includes/settings.php` | Los ajustes (WooCommerce > Dox POS) y las funciones que el resto del plugin pregunta: marca, colores, fuentes, nombre y ruta de la pantalla, canales, formas de pago, plazo y mensaje del apartado, transportadoras, país y moneda de la tienda. También arma el `<style>` con los colores. |
| `includes/roles.php` | El rol `caja` y el permiso `dox_pos_use`. Quien es solo de caja no entra a wp-admin. |
| `includes/page.php` | La ruta (la del ajuste, `/caja` de fábrica), el login propio, el `<head>` común y las plantillas. Sin caché. |
| `includes/catalog.php` | El buscador: usa el motor de WooCommerce (nombre y SKU, con variaciones). |
| `includes/api.php` | La API REST en `/wp-json/dox-pos/v1/`. Solo con sesión y permiso. Sus respuestas se marcan sin caché para LiteSpeed (`rest_pre_dispatch`): con "Cache REST API" activo, LiteSpeed las guardaba en la caché privada del usuario hasta 30 minutos. |
| `includes/orders.php` | Vender y apartar. Cada venta es un pedido de WooCommerce (`created_via = dox-pos`): el stock lo mueve Woo. El apartado es un pedido "en espera" que Action Scheduler cancela solo al vencer el plazo. Añade el estado "Enviado". La lista de pedidos incluye los de la web. |
| `includes/entries.php` | Entradas de mercancía: suman stock y quedan en la tabla `dox_pos_entries` (la columna de líneas se llama `items` porque `lines` es palabra reservada). |
| `includes/inventory.php` | El inventario en Excel (`/caja/?descargar=inventario`): lee productos, variaciones, atributos y categorías con cuatro consultas directas y arma el .xlsx a mano (un zip con XML, sin ZipArchive ni bibliotecas): dos hojas, cadenas en línea, fórmulas de valor y totales con su resultado ya calculado. |
| `includes/stock-log.php` | El kardex: la tabla `dox_pos_stock_log` y las capturas de cada cambio de existencias (antes, después, motivo, quién). Los motivos los pone el plugin cuando es él quien mueve el inventario (`dox_pos_stock_context`) o se deducen del pedido que cambia de estado o de dónde viene la petición. |
| `includes/history.php` | El historial: las consultas de ventas, caja del día y movimientos, sus tres Excel y las rutas REST (`/history/sales`, `/history/cash`, `/history/stock`). |
| `includes/ai.php` | El asistente, parte 1: los ajustes (clave, modelo, tope, resumen, umbrales), la llamada a la API de respuestas de OpenAI (`dox_pos_ai_call`: funciones, imágenes y salidas con esquema), el registro de cada llamada con su costo, el tope mensual y la tarea programada del resumen diario con su correo. |
| `includes/advisor.php` | El asistente, parte 2: los números. El catálogo de un vistazo (tres consultas), los chequeos y sus arreglos, las acciones y su deshacer, los pendientes de hoy, lo más vendido, los números del periodo, los consejos por reglas (y su redacción por el modelo), el resumen diario y las descripciones a partir de la foto. |
| `includes/assistant.php` | El asistente, parte 3: el chat. Las funciones que el modelo puede llamar, sus ejecutores, las propuestas de cambio (editar producto, mover pedido), aplicar, y las rutas REST `/assistant/*`. |
| `includes/products.php` | Crear y editar productos desde la caja: el formulario (categorías, tallas, colores), las fotos a WebP, el código como lo arma la tienda, el producto variable con sus variaciones, y la edición (buscar, cargar el modelo talla × color, guardar cambios y añadir combinaciones). |
| `includes/shipping.php` | El costo de envío lo dan las zonas de la tienda, como en el checkout. Evalúa costos escritos como `[qty] > 2 ? 24000 : 12000`, que WooCommerce no entiende. |
| `includes/install.php` | Crea la tabla, el rol y la ruta al activar o cuando cambia la versión (se sube por archivo). Si cambia la ruta en los ajustes, reescribe las reglas en la carga siguiente. |
| `templates/` | `login.php` y `caja.php`, páginas completas fuera del tema. |
| `assets/` | `caja.css` y `caja.js` (la caja y el asistente, sin dependencias) y `ajustes.css` y `ajustes.js` (la página de ajustes: pestañas, vista previa en vivo, comprobación de la dirección, canales ordenables, etiquetas de transportadoras y la prueba de conexión con OpenAI; sin jQuery salvo la biblioteca de medios). |

## Otra marca

Todo se cambia en WooCommerce > Dox POS, una página con cinco pestañas y una vista previa que cambia al momento (colores, logo, nombre, dirección, canales, formas de pago, el mensaje de WhatsApp y la ventana de envío). La dirección se comprueba mientras se escribe por `/wp-json/dox-pos/v1/settings/slug`, y al guardar se vuelve a la misma pestaña (`?tab=`).

- **La marca**: nombre, logo y cinco colores (fondo, barra, principal, tono suave, texto). El resto de tonos se derivan de esos cinco en `dox_pos_theme_css()`, y el texto que va sobre la barra o sobre un botón se decide por luminancia (blanco si el fondo es oscuro). Las fuentes son de Google Fonts y se validan al guardar. Con los colores de fábrica no se inyecta nada: `caja.css` ya los trae exactos.
- **La pantalla**: la palabra que va junto al logo ("Caja") y la ruta (`/caja`).
- **Las ventas**: los canales (los que se entregan en mano no piden ciudad ni envío) y las formas de pago activas, con su nombre y la que sale marcada.
- **Los apartados**: horas, la plantilla del mensaje de WhatsApp (`{nombre}`, `{productos}`, `{total}`, `{horas}`, `{link}`, `{tienda}`) y cómo pagar por fuera del link.
- **Los envíos**: las transportadoras que se sugieren. Costos, país, departamentos y moneda salen de WooCommerce (y las ciudades de Colciudades, si está).

## Subir a un servidor

`./subir.sh milasros` empaqueta la carpeta, la deja en `wp-content/plugins/dox-pos` de esa cuenta y reinicia sus procesos PHP para que no quede código viejo en OPcache. La activación se hace una vez desde Plugins.

## Cómo se mueve el inventario

Nunca lo toca el plugin directamente: registrar una venta pone el pedido en "procesando" y WooCommerce descuenta; apartar lo pone "en espera" y también descuenta; anular o liberar lo cancela y WooCommerce devuelve. Solo las entradas de mercancía suman stock a mano (`wc_update_product_stock`), y anular una entrada lo resta. Todo eso, y lo que se haga por fuera de la caja, queda en el kardex (abajo).

## El historial y el kardex

La pestaña Historial tiene un periodo (hoy, esta semana, este mes o dos fechas; `dox_pos_history_range` lo valida en la zona horaria de la tienda y lo limita a un año) y tres vistas:

- **Ventas** (`dox_pos_history_sales`): los pedidos del periodo con `wc_get_orders` (`date_created` en segundos, todos los estados salvo borradores; solo los de la caja si el ajuste de pedidos web está apagado). Cuenta como vendido lo que está en procesando, enviado o completado (`DOX_POS_SOLD`); como pendiente lo que está en espera, pendiente o fallido (`DOX_POS_OPEN`); el resto, anulado. Totales, promedio por venta y el reparto por canal, forma de pago, vendedora y día; la lista lleva hasta 300 pedidos (el Excel, todos).
- **Caja del día** (`dox_pos_history_cash`): por día, lo vendido y lo cobrado por cada forma de pago (el título del pedido), lo que está por cobrar (contraentrega que no se ha marcado entregado) y lo apartado sin pagar, más la lista de lo pendiente.
- **Movimientos** (`dox_pos_history_stock`): las filas del kardex del periodo con filtros por lo escrito (nombre o código), por producto y por tipo de motivo (ventas, entradas, devueltos, ajustes), en páginas de 50, con el resumen de unidades que entraron y salieron por motivo.

Cada vista se baja en Excel por `/caja/?descargar=ventas|caja|movimientos&desde=&hasta=` (`dox_pos_send_history`), con el mismo generador del inventario: `dox_pos_xlsx_workbook( $sheets )` arma el paquete con las hojas que le den, `dox_pos_xlsx_rows` escribe una tabla sencilla a partir de arrays (con su fila de totales en fórmula) y `dox_pos_xlsx_header` una fila de encabezado en cualquier sitio, para las hojas de resumen por secciones.

Quién ve qué: administradores y gerentes (`manage_woocommerce`, `dox_pos_history_full`) ven todo; una persona con el rol Caja ve la pestaña como "Mi día", solo con sus ventas de hoy (el servidor fija el periodo y filtra por `_dox_pos_seller`), y las rutas de caja y movimientos le responden 403.

**El kardex** (`stock-log.php`). WooCommerce no guarda un historial de existencias: solo deja una nota en el pedido cuando descuenta, y de un cambio a mano en un producto no queda rastro. La tabla `dox_pos_stock_log` guarda cada cambio: producto y variación, código, nombre (copiado, por si el producto se borra), cuántas había, cuántas quedan, la diferencia, el motivo, el pedido o la entrada, quién y una nota. Se captura con los avisos de WooCommerce: `woocommerce_product_before_set_stock` y `woocommerce_variation_before_set_stock` para las existencias viejas y `woocommerce_product_set_stock` y `woocommerce_variation_set_stock` para las nuevas. Ojo: al guardar un producto (la caja al editar, wp-admin, el importador), WooCommerce lanza el aviso de antes con el objeto que ya lleva el valor nuevo, así que las existencias viejas se leen de `get_data()` (lo cargado de la base, sin los cambios pendientes), no de `get_stock_quantity()`. Un producto recién creado en la misma petición cuenta con 0 de antes. El motivo: el plugin abre un contexto (`dox_pos_stock_context( 'entry' )`, `create`, `edit`, `release`, `entry_undo`) alrededor de lo que hace él mismo; para los pedidos, un gancho en prioridad 9 de `woocommerce_payment_complete` y de los cambios de estado (procesando, completado, en espera, cancelado, pendiente, reembolsado) apunta qué pedido está cambiando y otro en prioridad 11 lo suelta, con lo que `wc_maybe_reduce_stock_levels` (prioridad 10) queda dentro; y si no hay nada de eso, se deduce de la petición (importador de WooCommerce, reembolso por AJAX, REST, cron, wp-admin). Las filas de una entrada se apuntan con el número 0 y `dox_pos_stock_log_set_ref` les pone el número al guardarla. El kardex nunca lanza errores (un fallo va al registro de PHP y la venta sigue), y empieza en cero al instalar: no reconstruye el pasado.

## El asistente vive en Dox POS Pro

Desde la 0.19.0 el asistente (Hoy, revisión, chat, pronóstico, resumen diario, uso y los datos de
demostración) es otro plugin, `dox-pos-pro`, que se cuelga de este por ganchos. Este gratuito
funciona completo sin él. Lo que el gratuito abre para los añadidos:

- **En la caja**: el filtro `dox_pos_cfg` (lo que va al navegador en `window.DOX_POS`), y las acciones
  `dox_pos_head` (hojas de estilo), `dox_pos_tabs` (botones en la barra), `dox_pos_after_header`
  (avisos bajo la cabecera), `dox_pos_sections` (las secciones `section.tab#t-<id>`), `dox_pos_scripts`
  (scripts, después de `caja.js`) y `dox_pos_caja_open` (al abrir la caja, antes de pintar).
- **En el JS**: `caja.js` expone `window.DoxPOS` con sus utilidades (`api`, `post`, `modal`, `confirmar`,
  `toast`, `esc`, `dinero`, `kpi`, `chip`, `verPedido`, `editarDesdeLista`, `accion`, `fijarHash`…),
  `pestaña({ id, abrir, hash, desdeHash })` para registrar una pestaña, y `on`/`emit` con los avisos
  `arranque`, `pedido` y `pestaña`. La plantilla llama a `DoxPOS.arrancar()` al final, cuando los
  añadidos ya se registraron; por eso `caja.js` no arranca solo.
- **En los ajustes**: el filtro `dox_pos_settings_tabs` (id => [título, icono, vista previa]) y las
  acciones `dox_pos_settings_panels` (dentro del formulario, mismo grupo `dox_pos`),
  `dox_pos_settings_mocks` (vistas previas) y `dox_pos_settings_assets` (encolar JS, con dependencia
  de `dox-pos-ajustes`; `ajustes.js` avisa con el evento `dox-pos-repintar`). Los avisos de un campo
  ajeno se buscan por id (`#dp-ai-key` para el código `ai_key`).
- **Al instalar**: la acción `dox_pos_installed`, para que cada añadido cree sus tablas.

El guardián que impide que un pedido de demostración toque el inventario se queda aquí
(`dox_pos_demo_no_stock`, en `orders.php`): vale aunque el Pro se desactive con pedidos de ejemplo vivos.

## El detalle de un pedido

`GET orders/{id}` (`dox_pos_order_detail`): lo mismo que la fila más `items_list` (por renglón: foto de la variación o del producto, código de la variación o del padre, cantidad, precio unitario, total, existencias, enlace a la ficha si está publicada, si existe y si se puede editar desde la caja), totales, dirección completa, fechas de creación, pago y entrega, hasta cuándo el apartado, las últimas doce notas del pedido (`wc_get_order_notes`) y, para quien administra la tienda, la dirección de edición en WooCommerce. En la caja lo abre `verPedido` en un modal ancho; los botones de acción son los mismos de la lista (`botonesPedido`) y cierran el detalle antes de actuar.

## Envíos con guía

`dox_pos_carriers()` devuelve `[{name, url}]` (los ajustes viejos, solo nombres, se completan con `dox_pos_carrier_presets()`); `dox_pos_tracking_url( transportadora, guía )` arma el enlace poniendo la guía en `{guia}` (o devuelve la página de rastreo si la plantilla no lo lleva). Al marcar enviado, `dox_pos_order_action` guarda `_dox_pos_carrier`, `_dox_pos_guide` y `_dox_pos_tracking_url` además de `_dox_pos_tracking`, y `dox_pos_ship_notify` manda el correo (`dox_pos_ship_email`, con `WC()->mailer()->wrap_message()` y `send()`, así que sale con la plantilla y el remitente de WooCommerce; nunca a un pedido de demostración) y deja el WhatsApp (`dox_pos_ship_message`, plantilla `dox_pos_ship_message` con comodines; las líneas con comodín vacío se quitan). La respuesta lleva `ship` {email, sent, whatsapp, demo} y la caja lo enseña en un modal. Deshacer un envío desde el asistente limpia los cuatro metas.

## Dos cajas a la vez

Cada persona entra con su usuario y el pedido guarda quién lo registró (`_dox_pos_seller`). Lo que evita duplicados y ventas de más:

- **Reintentos**: la referencia `ref` (arriba, en "Sin señal"). Un reintento devuelve lo que ya existe.
- **Existencias**: se comprueban en el servidor al registrar, no con lo que el teléfono vio antes, y descontando lo que la tienda tiene retenido en pagos en curso (`wc_get_held_stock_quantity`). Si no alcanza: "De X quedan 0, no 1".
- **La última unidad en el mismo instante**: las dos cajas pasan esa comprobación. Por eso, antes de cambiar el estado, el pedido reserva sus unidades con `wc_reserve_stock_for_order()`, lo mismo que hace el checkout: un INSERT condicionado en `wc_reserved_stock` que bloquea la fila del inventario mientras compara, así que solo uno lo consigue; el otro recibe "se acaba de vender por otro lado", su pedido se borra y no queda rastro. La reserva vive segundos (el cambio de estado descuenta y la suelta); el filtro `woocommerce_order_hold_stock_minutes` la fija en 5 minutos para estos pedidos por si la tienda tiene "retener inventario" en 0.
- **El mismo pedido desde dos sitios**: cada acción (pagado, liberar, enviado, entregado, anular) comprueba el estado real antes de cambiarlo.
- **La misma factura de entrada dos veces**: si ya hay una entrada vigente con ese número (sin distinguir mayúsculas), el servidor responde `dox_pos_factura_repetida` con cuándo, de quién, por quién y cuántas unidades; la caja pregunta y, si es otra entrada distinta, vuelve a mandarla con `force: true`.

## Pedidos de la página web

La pestaña Pedidos lista todos los pedidos de la tienda (`dox_pos_list_orders`, sin filtrar por `created_via` salvo que el ajuste `web_orders` de Ventas esté apagado). Cada uno lleva su origen (`dox_pos_order_origin`: caja, web, admin u otro): los de la caja muestran el canal y quién los registró; los de la web, la etiqueta "Página web" y de dónde llegó el cliente según la atribución que guarda WooCommerce (`_wc_order_attribution_utm_source`: Instagram, Google...). Los estados se leen según el origen: en la caja, "en espera" es un apartado; en la web, "pendiente" es que dejó el pago a medias (sin inventario descontado), "en espera" que el pago va en camino (PSE) y "fallido" que la pasarela lo rechazó. Las acciones son las mismas (pagado, enviado, entregado, anular) salvo liberar, que es solo de apartados; confirmar el pago de un pedido web llama a `payment_complete()`, igual que haría la pasarela. Los correos de WooCommerce al cliente salen como si el cambio se hiciera en wp-admin; los del administrador (nuevo pedido, cancelado, fallido) se apagan para los pedidos de la caja y durante cualquier acción hecha desde la caja, porque el aviso llegaría a quien acaba de hacer el cambio. "Pagado" se decide con `is_paid()` menos contraentrega (`cod`), que en "procesando" todavía no ha pagado.

## El inventario en Excel

El botón "Descargar en Excel" de la pestaña de mercancía baja `inventario-<marca>-<fecha>.xlsx`. Cada producto es un bloque: su fila (estilos 7 a 10: negrita y fondo, con cuántas tallas y colores, el precio si es único y `SUM` de sus unidades y valor) y debajo una fila por variación con `outlineLevel="1"` (Excel las agrupa bajo el producto, `summaryBelow="0"`) y lo repetido en gris; un producto simple es una sola fila con estilo de producto. El total usa `SUMIF` sobre la columna Código (vacía en las filas de producto) para no contar dos veces; se evitó `SUBTOTAL` porque Numbers no lo tiene. Cada fila de variación lleva: referencia (el código del padre), código, producto, talla, color, otros atributos si los hay, categoría (la más específica, con su ruta), precio actual, existencias, valor (fórmula precio × existencias) y estado (publicado, oculto, borrador, desactivada, agotado). Ordenado por categoría, producto, talla (por edad) y color; encabezado fijo con filtro y fila de totales. La segunda hoja suma referencias, unidades y valor por categoría. El padre de un producto variable no sale como fila (sus existencias están en cada variación) y un producto sin control de existencias sale con la celda vacía. El nombre del producto, en su fila, es un enlace a su ficha en la tienda (estilo 12: negrita, subrayado y el color de la marca): `dox_pos_product_urls()` carga los posts de una vez con `_prime_post_caches` y saca la dirección con `get_permalink`, así respeta la estructura de enlaces de cada tienda; para 531 productos son 0,25 s. Son enlaces del formato, no fórmulas `HYPERLINK`: `dox_pos_xlsx_sheet()` recibe celda => dirección, escribe `<hyperlinks>` después del `autoFilter` (el orden que pide el esquema) y `dox_pos_xlsx_workbook()` añade `xl/worksheets/_rels/sheetN.xml.rels` con cada `Target` externo, en el mismo orden. En el Excel de movimientos vale lo mismo: la columna Producto lleva la clave de la dirección como quinto elemento y `dox_pos_xlsx_rows()` la enlaza con el estilo 13. No usa ZipArchive: `dox_pos_zip()` escribe el zip con `gzdeflate` y `crc32`, así que funciona en cualquier hosting.

## Editar un producto

La misma pantalla, en el modo "Editar uno": `GET /products/find?q=&page=` lista todos los productos por orden alfabético en páginas de 20 (también ocultos y borradores) y con `q` filtra por nombre o código (el código de una talla lleva a su producto; primero los que empiezan así), `GET /products/{id}` devuelve lo que el formulario necesita y `POST /products/{id}` guarda. `dox_pos_product_model()` lee el producto variable como tallas × colores (los valores de sus atributos y una fila por variación con talla, color, existencias propias o heredadas, precio e imagen propia, con `get_image_id('edit')` para no heredar la del padre); si el producto varía por otro atributo o tiene variaciones para "cualquier" valor, no se edita desde la caja. Reglas al guardar: la descripción solo se escribe si se tocó (se compara con su versión sin HTML, así no se pierde el formato de una hecha en WooCommerce); el precio vacío no toca nada (un producto con precios distintos por talla se carga con el campo vacío); las fotos que se quitan se sueltan del producto pero el archivo queda en la biblioteca; la imagen de una variación se cambia solo si su color recibió otra foto, o se quita si esa foto dejó de ser del producto (una imagen puesta a mano en WooCommerce que no está en la galería no se toca); las tallas y colores nuevos se añaden a los atributos y salen sus variaciones (precio del campo o el más repetido, código con `dox_pos_variation_sku`); las existencias en conjunto (`shared`: el padre las controla y las variaciones heredan) no se editan aquí. Los términos del padre se vuelven a fijar con `wp_set_object_terms` y `WC_Product_Variable::sync()` cierra.

## Nuevo producto

Pestaña de la caja que ven administradores y gerentes de tienda (`manage_woocommerce`; el rol Caja no la tiene). Una sola columna: fotos, nombre, categoría, precio y código, colores, tallas, unidades por talla y color, descripción y el interruptor de publicar. Lo que hace cada parte:

- **Fotos**: se suben de una en una nada más elegirlas (`POST /products/image`, multipart) y el servidor las convierte: `wp_get_image_editor` (Imagick) lee JPG, PNG, WebP y HEIC, reduce al lado mayor del ajuste y guarda WebP con la calidad del ajuste; `wp_generate_attachment_metadata` hace los tamaños, también en WebP. La original se descarta. La calidad va por el filtro `wp_editor_set_quality`, porque el editor de WordPress vuelve a la de fábrica después de cada redimensión (probado en WP 7.1: con `set_quality()` a secas salían 212 KB tanto a 82 como a 88; con el filtro, 291 KB a 88). Imagick lleva un techo de memoria de 256 MB (un HEIC de 12 MP se decodifica en unos 200 MB; el proceso llegó a 430 MB de RSS en la prueba). Cada foto queda marcada `_dox_pos_pending` hasta que se crea el producto; quitarla la borra (`DELETE /products/image/{id}`) y las que nadie usó se borran al día siguiente (`dox_pos_clean_images`, Action Scheduler). La primera es la principal, el resto va a la galería, y una foto asignada a un color es la imagen de las variaciones de ese color.
- **Categorías**: las que tienen productos, agrupadas por la de arriba. Para cada una se mira cómo están hechos sus productos: el prefijo de código más usado y el conjunto de tallas más repetido. Elegir la primera categoría marca esas tallas y pide el siguiente código libre (`GET /products/sku?prefix=VE`); escribir otro código lo comprueba mientras se escribe (`?sku=`).
- **Código**: el del padre es prefijo + número (VE83). El de cada variación, según el ajuste: como la tienda (padre + dos dígitos de talla + dos de color, o un 0 sin color: `VE830113`), con guiones (`VE83-6-12-meses-rosa`) o ninguno. Los dos dígitos de cada talla y color se aprenden de las variaciones que ya existen (`dox_pos_sku_codes`, opción que se rehace cada día); un valor nuevo recibe el siguiente libre y se guarda. Sin tallas se usa el código de la talla única (0- Siempre = 11 en Rosella).
- **Tallas y colores**: los atributos globales `pa_talla` y `pa_color` (o los que diga el ajuste; se detectan por el nombre). Las tallas se ordenan por edad leyendo los números del nombre (`dox_pos_size_sort_key`); la etiqueta corta es el meta `st-label-swatch` de XStore. Los colores traen su tono del meta `st-color-swatch`; un color nuevo se crea como término con ese meta.
- **Crear**: `POST /products` con `ref` (si el envío se repite, devuelve el que ya existe); las unidades iniciales quedan en el kardex como "Creado en la caja". `WC_Product_Variable` con `WC_Product_Attribute` por atributo, y después `wp_set_object_terms` en el padre (sin eso el desplegable sale vacío), una `WC_Product_Variation` por color y talla con código, precio, `manage_stock` y unidades (0 = agotada), `WC_Product_Variable::sync()`. Sin tallas ni colores sale un producto simple. Publicar apagado = `private`, que la caja no vende ni la tienda enseña.

## A prueba de errores

La pantalla de producto nuevo está pensada para alguien que no lee los avisos:

- **El error se queda en el campo**: `problemaProducto()` devuelve el mensaje y el selector del campo; `señalar()` marca el contenedor en rojo, pone el mensaje debajo (`role="alert"`), baja la pantalla hasta él y le da el foco. Cualquier cambio en el formulario limpia las marcas.
- **Categorías por grupos**: una fila con la categoría de arriba de cada grupo; tocarla despliega las suyas (la de arriba se llama "X en general" cuando tiene hijas). Lo elegido queda en una línea aparte con su ×, aunque sea de otro grupo.
- **Solo lo que hace falta**: las tallas que enseña son las que usan los productos de las categorías elegidas (más las marcadas), con "Otras tallas…" para ver las 38; los colores, los doce más usados (más los marcados), con "Más colores…". Al editar un producto se ven todos.
- **Unidades vacías** (0) en vez de 1 por talla: lo que no se escribe no existe. La talla que marcó la categoría se quita con un toque, y el aviso lo dice.
- **Precio con puntos de miles** mientras se escribe (`formatearPrecio`, con el separador de la tienda), para que se vean los ceros.
- **Nombre repetido**: al escribir el nombre se busca (`products/find`) y, si hay uno igual (sin tildes ni mayúsculas), avisa debajo con el enlace para editarlo.
- **El repaso antes de crear** (`modalRevisar`): nombre, código, precio, categorías, colores, la tabla de tallas por unidades, fotos y si se publica, con los avisos que toquen: sin foto (el botón principal pasa a "Agregar foto" y crear sin foto queda como secundario), sin unidades, precio fuera de lo habitual (menos de la mitad del más barato o más del doble del más caro de la tienda, `price_range` del formulario), nombre repetido y tallas marcadas por la categoría sin tocar.
- **El borrador**: todo cambio se guarda en `localStorage` (`dox_pos_borrador`) a los 400 ms, con las fotos ya subidas. Al abrir la pestaña o volver a "Nuevo producto" con el formulario vacío, pregunta si seguir con ese producto; las fotos se restauran solo si el borrador tiene menos de 20 horas (la tienda borra las fotos sin usar al día siguiente). Se borra al crear el producto o con "Empezar de cero".
- **Después de crear**: "Corregir algo" abre el producto recién creado en el modo de edición.

## Sin señal

Si la pantalla ya está abierta y se cae la conexión, registrar una venta, un apartado o una entrada la guarda en `localStorage` (clave `dox_pos_cola`) y la caja la reintenta al recibir el evento `online`, cada 30 segundos y con el botón "Enviar ahora". Cada registro lleva una referencia (`ref`, un UUID) que el servidor guarda (`_dox_pos_ref` en el pedido, columna `ref` en las entradas): si el reintento llega dos veces, devuelve lo que ya existe y no duplica. Si la tienda rechaza el registro (por ejemplo, ya no queda stock), sale de la cola y se avisa; si lo que rechaza es una factura repetida, pregunta antes de descartarla. No es una app sin conexión: la página tiene que estar cargada.

## Quién entra

Administradores y gerentes de tienda entran con su clave de siempre. Para una persona que solo vende se le crea un usuario con el rol **Caja**: entra a `/caja` y a nada más.
