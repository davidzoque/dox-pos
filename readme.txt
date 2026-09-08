=== Dox POS ===
Contributors: davidzoque
Tags: woocommerce, pos, point of sale, inventory, whatsapp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.21.1
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

La caja para la tienda que vende por WhatsApp e Instagram: ventas, apartados, mercancía, historial y kardex, sin entrar a wp-admin.

== Description ==

Añade la página /caja con su propio login. Desde ahí se buscan los productos con foto y existencias, se registran las ventas de WhatsApp o Instagram (cada una es un pedido de WooCommerce, así que el inventario baja solo), se apartan productos mientras el cliente paga, se registra la mercancía que llega y se lleva la lista de lo que hay que enviar. La marca, los colores, las fuentes, la ruta, los canales, las formas de pago y el mensaje de WhatsApp se ajustan en WooCommerce > Dox POS.

= Qué trae =

* Vender y apartar, con canal, cliente, ciudad, envío, descuento y forma de pago. Cada venta es un pedido de WooCommerce.
* Entradas de mercancía con proveedor, factura y costo, que suman al inventario.
* Pedidos de la caja y de la página web, con sus estados y el mensaje de WhatsApp preparado.
* Crear y editar productos con fotos (las del iPhone también), tallas, colores y código automático.
* Historial: ventas, la caja del día, el kardex de existencias y el rendimiento, todo descargable en Excel.
* Costos y ganancia: el costo por unidad de cada producto (en el campo de costo de WooCommerce), promedio ponderado al comprar y la ganancia de cada venta.
* Rol "Caja" para quien vende: entra a la caja y no a wp-admin.

El asistente de negocio (pendientes del día, revisión de la tienda, chat, pronóstico y resumen diario por correo) va en un añadido aparte, Dox POS Pro, que no hace falta para usar la caja.

== External services ==

Este plugin carga las dos fuentes de la caja desde **Google Fonts** (fonts.googleapis.com y fonts.gstatic.com), de fábrica encendido. Al abrir la caja, el navegador de quien la usa pide esas fuentes a Google, que recibe su dirección IP y los datos propios de una petición web. La página de ajustes hace lo mismo mientras se elige la fuente, y comprueba con Google si el nombre escrito existe. No se envía ningún dato de la tienda, de los pedidos ni de los clientes.

Se apaga en WooCommerce > Dox POS > Marca, con el interruptor "Cargar las fuentes desde Google Fonts": entonces se usan las fuentes del teléfono o del computador y el plugin no se conecta a ningún servicio de fuera.

Condiciones de Google: https://policies.google.com/terms
Privacidad de Google: https://policies.google.com/privacy

== Installation ==

Sube la carpeta `dox-pos` a `/wp-content/plugins/` y activa el plugin. Entra a `/caja` con un usuario administrador, gerente de tienda o con el rol "Caja". Para otra marca, ve a WooCommerce > Dox POS.

== Changelog ==

= 0.21.1 =
* El campo de costo de WooCommerce ya no se enciende al instalar el plugin: se enciende al guardar los ajustes con el interruptor de costos puesto, que lo dice, y la tarjeta avisa mientras falte.
* Las fuentes de la caja se pueden dejar de cargar desde Google Fonts (WooCommerce > Dox POS > Marca): con el interruptor apagado se usan las del sistema y el plugin no habla con ningún servicio de fuera.
* Por dentro: la hoja de estilo y el JS de la caja se encolan con el sistema de WordPress en vez de escribirse en la plantilla, y los iconos de la página de ajustes se imprimen filtrados con wp_kses.

= 0.21.0 =
* Costos y ganancia. Cada producto lleva su costo por unidad en el campo de costo de WooCommerce (la función "Cost of Goods Sold", que el plugin enciende solo): se pone al crear o editar en la caja, y de golpe desde el Excel del inventario con la columna Costo llena (Entró mercancía > Subir costos desde Excel). Cada compra registrada en Entró mercancía lleva lo que costó cada unidad y recalcula el costo promedio ponderado del producto. Cada venta deja el costo congelado en su pedido, así que subir el costo después no cambia la historia. El historial enseña la ganancia y el margen (Ventas, Caja del día por día, el detalle de cada pedido) y el kardex el costo por unidad de cada movimiento; los Excel llevan las columnas de costo, ganancia y valor al costo. Todo esto solo lo ven administradores y gerentes; el rol Caja no. Se apaga en Ajustes > Productos.
* Para los añadidos: las acciones dox_pos_history_views y dox_pos_history_panels (vistas nuevas en Historial), dox_pos_download (Excel propios) y DoxPOS.vistaHistorial en el JS.

= 0.20.0 =
* Mientras el plugin no esté en WordPress.org, se actualiza solo desde las releases de GitHub (Plugin Update Checker): cada etiqueta v* arma dos zips, el de GitHub con el actualizador y el de WordPress.org sin él. En Plugins aparece "hay una actualización" como con cualquier otro.

= 0.19.0 =
* El plugin se parte en dos: Dox POS (este, la caja entera) y Dox POS Pro (el asistente: Hoy, revisión, chat, pronóstico, resumen diario, uso y datos de demostración). El gratuito funciona completo sin el Pro, y el Pro se cuelga de él por ganchos, sin copiar nada. Quien tenía la 0.18.0 instala el Pro y lo activa: todo sigue donde estaba, con sus tablas, su clave y su historial.
* Arreglado de paso un fallo escondido: el borrador del formulario de producto no se guardaba desde la 0.11.1, porque el chat tenía otra función con el mismo nombre que la pisaba. Cada uno va ahora en su archivo y no se estorban.

= 0.18.0 =
* Pestaña Uso en el Asistente: lo que ha costado la API, sin salir de la caja. Arriba, el gasto del mes con cuántas llamadas, el de hoy, el promedio por llamada y en cuánto cerraría el mes al ritmo actual; después el tope del mes con su barra; un gráfico día a día de los últimos 30 días; en qué se va (consejos, chat, resumen diario, descripciones y pruebas) con sus llamadas y su costo; y las últimas veinte llamadas con quién las pidió, cuántos tokens, cuánto tardaron y cuánto costaron. Todo sale del registro del plugin, que guarda 90 días, y se ve en dólares, que es como cobra OpenAI.

= 0.17.1 =
* Los consejos que redacta el asistente ya no se guardan en la caché del servidor, sino en la base de datos con su hora. LiteSpeed vacía su caché cada vez que purga y con ella se iban los consejos, así que se volvía a pagar la redacción aunque no hubieran pasado las seis horas. Ahora aguantan la purga y solo se rehacen a las seis horas, al tocar "Actualizar" o cuando algo cambia de verdad en la tienda.

= 0.17.0 =
* En el Excel del inventario, el nombre de cada producto es un enlace a su ficha en la tienda: se toca y se abre. Va en la fila del producto (la de negrita), no en las de cada talla, y sale subrayado con el color de la marca. Lo mismo en el Excel de movimientos del kardex. Son enlaces de verdad del formato, no una fórmula, así que funcionan en Excel, Numbers, LibreOffice y Google Sheets, y la dirección es la que tenga la tienda para ese producto.

= 0.16.0 =
* El correo del resumen y la pantalla de Hoy quedan tocables: el número de un pedido abre su detalle, el nombre de un producto abre su ficha, "Vendido ayer" lleva a las ventas de ayer y "Esta semana" al historial de la semana. Vale en el texto que escribe el asistente, en los pendientes, en lo que se agota, en el pronóstico y en los consejos. Solo se enlaza lo que existe de verdad: el pedido se comprueba y el producto sale de la tienda, así que nunca lleva a una página vacía. La dirección de la caja entiende #pedidos/123, #producto/123, #asistente/revision/sin_foto y #historial/ventas/2026-09-05, con la caja recién abierta o ya abierta.

= 0.15.0 =
* El detalle de un pedido: tocando el número o los productos (en Pedidos, en el Historial o con "Ver" en los pendientes de Hoy) se abre con cada producto con su foto, código, talla y color, cantidad y precio, lo que queda en existencias, el enlace a la ficha en la tienda y "Editar" (abre Productos); los totales con descuento y envío; el cliente con teléfono, WhatsApp, correo, dirección y nota; el pago (y cuándo se pagó), el apartado y hasta cuándo, la guía con su enlace y cuándo se entregó; y el historial del pedido (las notas que WooCommerce y la caja van apuntando). Los mismos botones de siempre abajo, y para administradores "Abrir en WooCommerce".

= 0.14.0 =
* Transportadoras con enlace de rastreo (Ajustes > Envíos): cada una con su nombre y su enlace, con {guia} donde la transportadora espera el número (Coordinadora lo acepta; Servientrega, Interrapidísimo y TCC piden el número en su página, así que el enlace lleva allí). Vienen de fábrica las conocidas; cualquier otra se añade.
* Al marcar un pedido como enviado con transportadora y guía, si el pedido tiene correo le llega a la clienta un correo con la transportadora, el número de guía, el botón "Seguir el envío", lo que va en el paquete y la dirección, con la plantilla de correos de la tienda (logo, colores, pie) y desde su remitente. Se apaga en Ajustes > Envíos. Si tiene teléfono, la caja deja listo el mensaje de WhatsApp con la guía y el enlace (texto editable con comodines). La caja dice qué pasó con el aviso, y en Pedidos la guía es un enlace. El asistente enseña en la propuesta a quién se avisará.

= 0.13.0 =
* Datos de demostración (WooCommerce > Dox POS > Asistente): un botón crea cuatro semanas de ventas de ejemplo por todos los canales y formas de pago (con envíos, contraentregas y descuentos) y una docena de pedidos abiertos con algo por hacer, uno de cada cosa que el asistente detecta. Se hacen con los productos reales, no tocan las existencias (un pedido de demostración nunca baja ni devuelve inventario, haga lo que se haga con él) y llevan sus filas de kardex. La caja enseña un aviso mientras estén, con el botón para quitarlos; al quitarlos se borran del todo. El resumen diario avisa si los incluye.
* El chat guardado en el navegador caduca a los siete días. En el servidor no se guardan conversaciones: solo las llamadas (tokens y costo) y las acciones aplicadas, que se purgan a los 90 días una vez al día.

= 0.12.0 =
* El chat crea productos. Con el clip (o pegando una foto) se adjuntan hasta seis fotos por mensaje: pasan por el mismo convertidor a WebP de la pestaña Productos y el modelo las mira para proponer el nombre, el color y una descripción. La función crear_producto comprueba la categoría (por su nombre en la tienda), las tallas (las que existen; si la categoría suele llevarlas y no se dijeron, pregunta), los colores (los nuevos se crean), las unidades por talla, el código (se asigna solo con el prefijo de la categoría, como en Productos) y las fotos, y avisa de un precio fuera de lo habitual o de un nombre repetido. Sale una tarjeta con todo y el botón Crear; se crea con la misma función que la pestaña Productos y se puede deshacer 24 horas (va a la papelera si no vendió). Desde la tarjeta, "Editar" abre el producto en Productos.
* editar_producto también cambia categorías, código, añade tallas nuevas (con sus unidades; una variación por color, con el precio común) y fotos adjuntas (la primera queda de principal si no había). Todo con Deshacer, que respeta lo que alguien cambió después.
* Pronóstico (función pronostico y bloque en Hoy cuando hay historia: 14 días y 15 ventas): con el ritmo de venta de los últimos 30 días (o los que haya), por producto cuántos días de existencias quedan, cuándo se acabarían y cuánto reponer para cubrir un mes; cómo cerraría el mes frente al anterior y al mismo día del anterior; el mejor y el peor día de la semana; y lo que no se mueve. Dos consejos nuevos salen de ahí: lo que se agota en menos de una semana y hacia dónde va el mes. Siempre se dice que es una proyección.

= 0.11.1 =
* La caja recuerda dónde estabas: la dirección lleva la pestaña y la vista (/caja/#asistente/chat, /caja/#historial/caja), así que al recargar se abre lo mismo en vez de Vender. La conversación del chat, con sus tarjetas y su estado, se queda en el navegador hasta cerrar la pestaña; el borrador de lo que se estaba escribiendo, también.
* Los consejos no se redactan dos veces si dos peticiones llegan a la vez (Hoy en dos ventanas, o Hoy y el resumen): la segunda se lleva los consejos por reglas hasta que la primera termina.

= 0.11.0 =
* Pestaña Asistente (administradores y gerentes) con cuatro vistas. Hoy: cómo fue ayer y la semana, lo por cobrar, los pendientes con sus botones (apartados vencidos o por vencer, pedidos por enviar desde hace días, enviados sin marcar entregado, contraentregas por cobrar, compras de la web sin pagar o con el pago por confirmar, pedidos en cero, sin teléfono, sin guía, posibles repetidos), lo que se agota de lo que se vende en 30 días y los consejos del negocio. Revisión: catorce chequeos de productos (existencias en negativo, sin precio, oferta mal puesta, sin categoría, ocultos con existencias, sin descripción, sin foto, agotados, código repetido, sin código, nombre repetido, precio fuera de lo normal, una sola foto, una sola talla) con la lista de cada uno y arreglos de un toque (publicar, ocultar, poner en 0, quitar la oferta, redactar descripciones con la foto). Chat: el modelo responde con las funciones de la caja (buscar, ver un producto, ventas, caja, movimientos, pedidos, pendientes, revisión, los números del negocio, entradas) y propone cambios (editar un producto, mover un pedido) que se confirman con un botón. Actividad: cada cambio con quién lo confirmó, y Deshacer durante 24 horas (lo que alguien cambió después se respeta; las existencias se deshacen por diferencia).
* Resumen diario por correo a la hora que se elija (tarea programada de WordPress, con repesca al abrir la caja si no salió a su hora), con cómo fue ayer, lo pendiente, lo que se agota y un consejo. Queda también en la pestaña Hoy.
* Ajustes > Asistente: la clave de OpenAI (se guarda solo en el servidor), el modelo (de fábrica gpt-5.6-luna), un tope de gasto mensual, la hora y los destinatarios del resumen, los umbrales de los avisos y un botón para probar la conexión. Sin clave, el asistente enseña los números y los consejos por reglas; con clave, además redacta y responde.
* Todo número sale de la tienda (consultas y reglas); el modelo solo redacta. Cada llamada a OpenAI queda apuntada con sus tokens y su costo en la tabla dox_pos_ai_log; las acciones, en dox_pos_ai_actions.

= 0.10.0 =
* Pestaña Historial: las ventas del periodo (hoy, esta semana, este mes o dos fechas) con totales, el promedio por venta y el reparto por canal, forma de pago, vendedora y día; la caja del día (cuánto entró por efectivo, transferencia, Nequi o la web, lo que está por cobrar en contraentregas y lo apartado sin pagar, día por día); y los movimientos de inventario. Cada vista se baja en Excel. Una vendedora con rol Caja ve solo sus ventas de hoy ("Mi día").
* El kardex: cada cambio de existencias queda en la tabla dox_pos_stock_log con cuántas había, cuántas quedan, el motivo (venta #, apartado #, pedido web #, anulado, apartado liberado, devolución, entrada #, entrada anulada, creado o editado en la caja, cambiado en WooCommerce, importación) y quién lo hizo. Se captura con los avisos de WooCommerce, así que registra también lo que se haga por fuera de la caja. Empieza en cero al instalar: no reconstruye el pasado.
* La pantalla de producto nuevo, a prueba de errores: el aviso se queda en rojo debajo del campo que falta y la pantalla baja hasta él; las categorías van por grupos (se toca el grupo y salen las suyas); se enseñan solo las tallas que usa la categoría (y "Otras tallas…") y los doce colores más usados (y "Más colores…"); las unidades empiezan vacías; el precio se escribe con puntos de miles; si ya hay un producto con ese nombre, avisa con el enlace para editarlo; antes de crear sale un repaso con todo lo que se va a crear y sus avisos (sin foto, sin unidades, precio fuera de lo habitual de la tienda, nombre repetido, tallas marcadas por la categoría), y desde ahí se agrega la foto o se crea; lo escrito se guarda en el teléfono y al volver pregunta si seguir con ese producto; "Empezar de cero" vacía el formulario; y al crear, "Corregir algo" abre el producto para editarlo.
* El generador de Excel es común a todos los informes (dox_pos_xlsx_workbook); el inventario sale igual que antes.

= 0.9.1 =
* El Excel del inventario va por bloques: cada producto es una fila en negrita con fondo (referencia, nombre, cuántas tallas y colores, categoría, precio si es uno solo, y sus unidades y valor sumados) y debajo una fila por talla y color con lo repetido en gris. Las filas de cada producto están agrupadas: con el +/- del margen de Excel (o el botón "1" de arriba) se pliegan y queda un producto por fila. El total de abajo suma solo las filas con código.
* En Productos > Editar uno la lista sale sola, por orden alfabético y en páginas de 20, con "Anteriores" y "Siguientes"; escribir algo la filtra.
* Los avisos de la edición, más claros, y al editar un producto, el enlace "Ver en la tienda" arriba a la derecha (abre la ficha en otra pestaña).

= 0.9.0 =
* Editar productos desde la caja: la pestaña "Nuevo producto" pasa a llamarse "Productos" y tiene dos modos, crear uno nuevo o editar uno. Se busca por nombre o código (también los ocultos), se carga en el mismo formulario y se cambian nombre, categoría, precio, fotos (y qué foto va con cada color), descripción, si está publicado y las unidades por talla y color; se añaden tallas o colores nuevos y salen sus variaciones con el código de siempre. El código y las tallas o colores que ya tiene quedan fijos (quitarlos sería borrar variaciones con historial). Un producto que lleva las existencias en conjunto lo dice y las deja para "Entró mercancía". En Vender y en Entró mercancía, al abrir un producto aparece "Editar este producto".

= 0.8.0 =
* Botón "Descargar en Excel" en la pestaña de mercancía: el inventario completo en un .xlsx de verdad (no un CSV), con una fila por talla y color (referencia, código, producto, talla, color, categoría, precio, existencias, valor y estado), encabezado fijo con filtros, totales y una segunda hoja con el resumen por categoría. Abre en Excel, Numbers y Google Sheets. El archivo se arma en el servidor sin bibliotecas.

= 0.7.0 =
* La pestaña Pedidos enseña también los de la página web (y los hechos a mano en WooCommerce), con la etiqueta "Página web" y de dónde llegó el cliente (Instagram, Google...). Se marcan enviados y entregados igual que los de la caja; uno con el pago pendiente o rechazado se puede confirmar o anular, y el botón de WhatsApp abre el chat con quien compró. Se apaga en Ajustes > Ventas.
* Un pedido contraentrega ya no sale como "Pagado" mientras está por enviar.
* Las respuestas de la API de la caja ya no pasan por la caché de LiteSpeed: con "Cache REST API" activo, LiteSpeed guardaba la lista de pedidos y las existencias de cada usuario hasta 30 minutos, y una caja podía ver inventario viejo mientras la otra vendía.
* Marcar, anular o confirmar un pedido desde la caja ya no dispara los correos de aviso al administrador (WooCommerce avisaba "Pedido cancelado" hasta cuando lo anulaba uno mismo).

= 0.6.0 =
* Nuevo producto desde la caja (pestaña para administradores y gerentes): fotos, nombre, categoría, precio, código, colores, tallas y unidades por talla y color, y el producto queda creado en WooCommerce con sus variaciones, listo para vender.
* Las fotos se convierten a WebP en el servidor con la calidad y el tamaño del ajuste (88 y 1600 px de fábrica), con sus tamaños también en WebP; la original no se guarda. Entran JPG, PNG, WebP y HEIC del iPhone si el servidor tiene ImageMagick con libheif (la página de ajustes lo dice). La primera foto es la principal, y una foto se puede asignar a un color.
* Al elegir la categoría se marcan las tallas que usan sus productos y se propone el siguiente código libre de su prefijo (VE82 pasa a VE83); el código de cada variación se arma como en la tienda (talla y color con dos dígitos, aprendidos de los productos que ya existen) o con guiones, según el ajuste. Un color nuevo se crea con su tono.
* Ajustes > Productos: calidad y lado mayor del WebP, atributos de talla y color, formato del código y quién puede crear productos. Las fotos subidas que no acaban en ningún producto se borran solas al día siguiente.

= 0.5.1 =
* Dos cajas a la vez: la última unidad ya no se puede vender dos veces. Al registrar, el pedido reserva sus unidades con el mismo mecanismo del checkout de WooCommerce (una operación que la base de datos no deja repetir); si otra caja o un cliente de la web se adelantó en ese mismo instante, la venta se rechaza con su aviso y no queda pedido. La comprobación previa también cuenta las unidades que están en un pago en curso de la web.
* Factura repetida: si la factura o remisión ya se registró (desde otro teléfono, por ejemplo), la caja dice cuándo, de quién, por quién y cuántas unidades, y pregunta antes de sumar otra vez. También al enviar lo que quedó guardado sin señal.
* La caja arranca directo al cargar su script, sin esperar al evento DOMContentLoaded, para que un optimizador que retrase el JS no la deje en blanco.

= 0.5.0 =
* La página de ajustes, rehecha como una aplicación: cabecera fija con cinco pestañas (marca, pantalla, ventas, apartados, envíos), tarjetas, interruptores, y una vista previa a la derecha que cambia al momento: colores, logo, nombre, dirección, canales, formas de pago, el mensaje de WhatsApp tal como lo verá la clienta y la ventana de envío con las transportadoras.
* La dirección de la caja se comprueba mientras se escribe (si la usa otra página, avisa antes de guardar) y se ve la dirección completa.
* Las fuentes se cargan de Google Fonts al momento en la vista previa; si el nombre no existe, lo dice ahí mismo.
* Los canales se ordenan arrastrando (o con las flechas del teclado); las transportadoras son etiquetas; los comodines del mensaje se insertan con un toque.
* Botón de guardar siempre a la vista, aviso de cambios sin guardar, Cmd/Ctrl+S guarda, y al guardar se vuelve a la misma pestaña.
* Los gerentes de tienda también pueden guardar los ajustes.

= 0.4.0 =
* Ajustes para otras marcas (WooCommerce > Dox POS): nombre, logo, cinco colores con vista previa, fuentes de Google Fonts, nombre y ruta de la pantalla, canales de venta, formas de pago, plantilla del mensaje de WhatsApp y transportadoras. País, moneda e indicativo de WhatsApp salen de WooCommerce.
* La caja responde al tacto: los botones se hunden al pulsar, las ventanas y los avisos entran y salen con transición, los avisos se apilan y se cierran al tocarlos, Escape cierra la ventana y el foco vuelve a su sitio, los botones dicen "Registrando…" mientras guardan, la línea recién agregada se ilumina, los objetivos táctiles miden al menos 36 px y los campos ya no provocan zoom en iPhone.

= 0.3.0 =
* Sin señal: lo que se registre con la pantalla abierta y sin conexión se guarda en el teléfono y entra solo al volver, sin duplicarse.

= 0.2.0 =
* Vender (pedido de WooCommerce que descuenta), apartar con plazo y link de pago, entradas de mercancía con histórico, lista de pedidos con estados (apartado, por enviar, enviado, entregado), anular, y el costo de envío calculado por las zonas de la tienda.

= 0.1.0 =
* Ruta /caja con login propio, rol "caja" sin acceso a wp-admin, y buscador de productos y variaciones con foto y existencias.
