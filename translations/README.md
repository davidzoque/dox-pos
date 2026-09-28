# Dox POS in Spanish / Dox POS en español

WordPress installs Dox POS translations on its own from translate.wordpress.org, but only once a language has
enough approved strings. Until then, a store in that language sees Dox POS in English. These files fix it by hand.

WordPress instala solo las traducciones de Dox POS desde translate.wordpress.org, pero solo cuando un idioma
tiene suficientes textos aprobados. Mientras tanto, una tienda en ese idioma ve Dox POS en inglés. Estos
archivos lo arreglan a mano.

**Guide / Guía:** https://help.doxstudio.com/dox-pos-in-your-language/ · https://help.doxstudio.com/es/dox-pos-en-tu-idioma/

| Country / País | File / Archivo |
|---|---|
| Argentina | dox-pos-es_AR.zip |
| Chile | dox-pos-es_CL.zip |
| Colombia | dox-pos-es_CO.zip |
| Costa Rica | dox-pos-es_CR.zip |
| Ecuador | dox-pos-es_EC.zip |
| España | dox-pos-es_ES.zip |
| Guatemala | dox-pos-es_GT.zip |
| Honduras | dox-pos-es_HN.zip |
| México | dox-pos-es_MX.zip |
| Perú | dox-pos-es_PE.zip |
| Puerto Rico | dox-pos-es_PR.zip |
| República Dominicana | dox-pos-es_DO.zip |
| Uruguay | dox-pos-es_UY.zip |
| Venezuela | dox-pos-es_VE.zip |

Unzip it into `wp-content/languages/plugins/` of your site. Plugin updates don't touch that folder.

Descomprímelo en `wp-content/languages/plugins/` de tu sitio. Las actualizaciones del plugin no tocan esa carpeta.

These zips are built by `tools/build-translations.py` from the `.po` files in `languages/`. Don't edit them by hand.
