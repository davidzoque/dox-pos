# armar_po.py: arma un .po por variante solo con lo que falta en translate.wordpress.org, traducido con el .po del repo.
# Uso: en una carpeta con los un-<variante>.po exportados de translate.wordpress.org (filtro untranslated),
# python3 armar_po.py (necesita polib) deja un sube-<variante>.po por variante para importar.
import polib, sys
L = "/Users/davidzoque/Library/CloudStorage/GoogleDrive-david@doxstudio.com/My Drive/Dox Studio/WordPress/Plugins/dox-pos/languages/"
SRC = {"es": "dox-pos-es_ES-espana.po", "es-ar": "dox-pos-es_AR.po"}
for loc in ["es", "es-co", "es-mx", "es-cl", "es-ar"]:
    repo = polib.pofile(L + SRC.get(loc, "dox-pos-es_ES.po"))
    idx = {(e.msgctxt, e.msgid): e for e in repo}
    un = polib.pofile("un-%s.po" % loc)
    out = polib.POFile(); out.metadata = un.metadata
    falta = []
    for e in un:
        r = idx.get((e.msgctxt, e.msgid))
        if not r or (not r.msgstr and not any(r.msgstr_plural.values())) or "fuzzy" in r.flags:
            falta.append(e.msgid[:60]); continue
        n = polib.POEntry(msgid=e.msgid, msgctxt=e.msgctxt, msgid_plural=e.msgid_plural)
        if e.msgid_plural: n.msgstr_plural = dict(r.msgstr_plural)
        else: n.msgstr = r.msgstr
        out.append(n)
    out.save("sube-%s.po" % loc)
    print(loc, len(un), "->", len(out), "sin traducción en el repo:", falta)
