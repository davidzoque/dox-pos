# Cómo se trabaja en este plugin (Dox POS)

Lee esto antes de tocar nada. Vale para cualquier sesión, en el Mac o en el PC.

## Dónde está el código que manda

- El repositorio es `github.com/davidzoque/dox-pos` (público). La copia de trabajo vive en Google Drive,
  en `Dox Studio/WordPress/Plugins/dox-pos`. **Antes de editar: `git status` y `git pull`**, y mira
  `git log --oneline -5` y `git tag` para saber en qué versión vas. No des por hecho que el árbol de
  trabajo es solo tuyo: otra sesión puede haber dejado cambios.
- Las carpetas `dox-pos-main` o zips sueltos no son el código: son descargas. Lo bueno está en git.
- El Pro (`dox-pos-pro`, repositorio privado) se cuelga de este por los ganchos que se explican en
  `README.md` ("El asistente vive en Dox POS Pro"). Cualquier función nueva nace en el lado que le
  toca: gratis aquí, de pago allí. Este plugin tiene que funcionar completo sin el Pro.

## Cada cambio

1. Código en inglés, comentarios en español. Sin guion largo en ningún texto.
2. `php -l` de cada archivo PHP y `node --check` de cada JS antes de subir.
3. Si el cambio se publica: sube la versión en la cabecera `Version:` y en `DOX_POS_VERSION`
   (tienen que coincidir), y añade la entrada al principio del changelog de `readme.txt` (y el
   `Stable tag`). Si cambia cómo está hecho algo, actualiza `README.md`.
4. Commit a nombre de davidzoque, **sin Co-Authored-By ni firma de ninguna IA**, con un mensaje que
   empiece por la versión: `v0.21.0: qué cambió`. `git push origin main`.
5. Solo si es una versión para publicar: etiqueta `vX.Y.Z` y `git push origin vX.Y.Z`. El plugin se
   reparte **solo por WordPress.org**: la Action de `.github/workflows/release.yml` comprueba que la
   etiqueta y la versión coincidan y deja `dox-pos.zip` (sin `languages/`) como artefacto de la
   ejecución, en la pestaña Actions. **No se crean releases en GitHub**: las copias antiguas
   instaladas desde GitHub se actualizarían con ellas. Después, SVN (`~/dev/dox-pos-svn`): trunk y
   `tags/X.Y.Z` con el mismo contenido que ese zip. No muevas etiquetas ya publicadas.
6. Desplegar a un sitio: `./subir.sh CUENTA` (el script no va en el repo; está en la carpeta de
   Drive). Después, lint dentro de la jaula del servidor y prueba con clics reales en el navegador.
   Los datos de prueba se borran en la misma sesión.

## Lo que no se hace

- Nada de mu-plugins ni funciones escondidas. Nada de código bloqueado esperando una clave (WordPress.org
  no lo admite): lo de pago va en el Pro.
- No pegar claves ni secretos en el chat ni en el código.
- `languages/` se queda en el repo (la fuente del español, para translate.wordpress.org y para Loco Translate) y no entra en el zip de WordPress.org, que no admite traducciones dentro. Hay tres: `dox-pos-es_ES.po` (español de Colombia, de donde salen el `.mo`, el `.l10n.php` y el JSON), `dox-pos-es_AR.po` (Argentina, con voseo) y `dox-pos-es_ES-espana.po` (España, para el proyecto es de translate.wordpress.org). Cada texto nuevo va en los tres.

## Las capturas del directorio

Van en `.wordpress-org/`, con el icono y los dos banners: `icon-128x128.png`,
`icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png` y `screenshot-1.png` …
`screenshot-6.png` (con sus pies de foto en la sección `== Screenshots ==` de `readme.txt`,
en el mismo orden). **No van dentro del zip**: el
`subir.sh` y la Action las excluyen, porque en WordPress.org viven en la carpeta `assets/` del
SVN, no en el plugin.

No son capturas de la tienda de un cliente: se generan con una maqueta HTML que carga el
`caja.css` y el `ajustes.css` de verdad y pinta datos de una tienda inventada (`YOURSHOP`). Están
en Drive, en `Dox Studio/WordPress/paginas/maqueta-caja/`, y se rehacen con `./hacer.sh`. Si
cambia la interfaz, hay que copiar el CSS nuevo a esa carpeta y volver a generarlas.

El icono y los banners salen de `./hacer-directorio.sh`, de `icono.html` y `banner.html`.
El isotipo es el de Dox Studio redibujado en SVG midiendo el PNG de la marca, así que sale
limpio a cualquier tamaño. El icono tiene tres fondos (`?v=a|b|c`); va el oscuro, y se cambia
con `ICONO=a ./hacer-directorio.sh`.
