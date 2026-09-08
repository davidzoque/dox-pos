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
5. Solo si es una versión para publicar: etiqueta `vX.Y.Z` y `git push origin vX.Y.Z`. La Action de
   `.github/workflows/release.yml` comprueba que la etiqueta y la versión coincidan, arma
   `dox-pos.zip` (el que sirve el actualizador de GitHub) y `dox-pos-wordpress-org.zip`, y publica
   la release. Tarda un minuto o dos. No muevas etiquetas ya publicadas.
6. Desplegar a un sitio: `./subir.sh CUENTA` (el script no va en el repo; está en la carpeta de
   Drive). Después, lint dentro de la jaula del servidor y prueba con clics reales en el navegador.
   Los datos de prueba se borran en la misma sesión.

## Lo que no se hace

- Nada de mu-plugins ni funciones escondidas. Nada de código bloqueado esperando una clave (WordPress.org
  no lo admite): lo de pago va en el Pro.
- No pegar claves ni secretos en el chat ni en el código.
- No subir a WordPress.org el zip que lleva `vendor/plugin-update-checker`: para eso está `dox-pos-wordpress-org.zip`.
