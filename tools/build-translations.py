#!/usr/bin/env python3
"""Genera el .mo, el .l10n.php y el JSON del JavaScript a partir del .po.

    python3 tools/build-translations.py

El .po es la única fuente. El JSON solo lleva las cadenas que aparecen en
assets/js/caja.js, y su nombre es el que espera wp_set_script_translations:
dox-pos-<locale>-<md5 de la ruta del js>.json

Antes de generar, marca en el .po con "#: assets/js/caja.js:<línea>" las
entradas que usa el JavaScript (y se la quita a las que ya no). Loco Translate
arma el JSON solo con las entradas que traen esa referencia, y solo si lleva
número de línea: sin ella, al importar el .po en Loco la caja sale en inglés.
Esas referencias se mantienen en todos los .po de languages/ (también los de
Argentina y España, dox-pos-es_AR.po y dox-pos-es_ES-espana.po), pero los
archivos de languages/ se generan solo del de es_ES.

Además arma en translations/ un zip por cada variante del español que tiene
WordPress (dox-pos-es_PE.zip, dox-pos-es_MX.zip...), con el .mo, el .l10n.php,
el JSON y el .po ya con el nombre de ese país. Son para quien los baja a mano
desde GitHub mientras translate.wordpress.org no tenga su paquete (la guía
"Dox POS en tu idioma" de help.doxstudio.com enlaza aquí). Argentina sale de
dox-pos-es_AR.po, España de dox-pos-es_ES-espana.po y las demás del es_ES.po
(español neutro). No son Releases de GitHub a propósito: las copias viejas
instaladas desde GitHub se actualizan con el primer adjunto de la última Release.
"""
import glob
import hashlib
import json
import os
import re
import struct
import sys
import zipfile

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCALE = 'es_ES'
JS = 'assets/js/caja.js'
PO = os.path.join(BASE, 'languages', 'dox-pos-%s.po' % LOCALE)
PACKS = os.path.join(BASE, 'translations')
# Las variantes del español que tiene WordPress y de qué .po sale cada una.
VARIANTS = {
    'es_AR': 'dox-pos-es_AR.po', 'es_ES': 'dox-pos-es_ES-espana.po',
    'es_CL': 'dox-pos-es_ES.po', 'es_CO': 'dox-pos-es_ES.po', 'es_CR': 'dox-pos-es_ES.po',
    'es_DO': 'dox-pos-es_ES.po', 'es_EC': 'dox-pos-es_ES.po', 'es_GT': 'dox-pos-es_ES.po',
    'es_HN': 'dox-pos-es_ES.po', 'es_MX': 'dox-pos-es_ES.po', 'es_PE': 'dox-pos-es_ES.po',
    'es_PR': 'dox-pos-es_ES.po', 'es_UY': 'dox-pos-es_ES.po', 'es_VE': 'dox-pos-es_ES.po',
}


def parse_po(path):
    entries, cur, key = [], {}, None
    def flush():
        nonlocal cur
        if cur.get('msgid') is not None:
            entries.append(cur)
        cur = {}
    for raw in open(path, encoding='utf-8'):
        line = raw.rstrip('\n')
        if not line.strip():
            flush(); key = None; continue
        if line.startswith('#'):
            continue
        m = re.match(r'^(msgid_plural|msgid|msgctxt|msgstr\[(\d)\]|msgstr)\s+"(.*)"$', line)
        if m:
            k = m.group(1)
            if k.startswith('msgstr['):
                k = 'msgstr%s' % m.group(2)
            key = k
            cur[k] = cur.get(k, '') + m.group(3)
            continue
        m = re.match(r'^"(.*)"$', line)
        if m and key:
            cur[key] += m.group(1)
            continue
        sys.exit('linea que no se entiende: %s' % line)
    flush()
    return entries


def unesc(s):
    out, i = [], 0
    while i < len(s):
        if s[i] == '\\' and i + 1 < len(s):
            out.append({'n': '\n', 't': '\t', 'r': '\r', '"': '"', '\\': '\\'}.get(s[i + 1], s[i + 1]))
            i += 2
        else:
            out.append(s[i]); i += 1
    return ''.join(out)


def js_unesc(s):
    """Deshace los escapes de una cadena de JavaScript, \\u2026 incluido."""
    out, i = [], 0
    simple = {'n': '\n', 't': '\t', 'r': '\r', 'b': '\b', 'f': '\f', 'v': '\v', '0': '\0'}
    while i < len(s):
        if s[i] != '\\' or i + 1 >= len(s):
            out.append(s[i]); i += 1; continue
        c = s[i + 1]
        if c == 'u' and i + 5 < len(s) + 1:
            out.append(chr(int(s[i + 2:i + 6], 16))); i += 6
        elif c == 'x':
            out.append(chr(int(s[i + 2:i + 4], 16))); i += 4
        elif c in simple:
            out.append(simple[c]); i += 2
        else:
            out.append(c); i += 2
    return ''.join(out)


def php_str(s):
    if '\n' in s or '\t' in s:
        return '"' + s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t') + '"'
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def php_key(s):
    return ' . "\\0" . '.join(php_str(p) for p in s.split('\0'))


def mo_bytes(pairs):
    pairs = sorted(pairs, key=lambda p: p[0])
    n = len(pairs)
    off_o, off_t = 28, 28 + n * 8
    cur = off_t + n * 8
    data, keys, vals = bytearray(), [], []
    for k, _ in pairs:
        keys.append((len(k), cur)); data += k + b'\0'; cur += len(k) + 1
    for _, v in pairs:
        vals.append((len(v), cur)); data += v + b'\0'; cur += len(v) + 1
    mo = struct.pack('<IIIIIII', 0x950412de, 0, n, off_o, off_t, 0, 0)
    for l, o in keys:
        mo += struct.pack('<II', l, o)
    for l, o in vals:
        mo += struct.pack('<II', l, o)
    return mo + bytes(data)


def js_strings():
    """Los textos que caja.js pasa a __() y _n(), ya sin los escapes de JavaScript, con la
    línea donde aparecen por primera vez."""
    js = open(os.path.join(BASE, JS), encoding='utf-8').read()
    lit_re = re.compile(r"""\b_{1,2}n?\s*\(\s*("(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*')""")
    used = {}
    for m in lit_re.finditer(js):
        used.setdefault(js_unesc(m.group(1)[1:-1]), js.count('\n', 0, m.start()) + 1)
    return used


def sync_js_refs(used, po):
    """Deja "#: assets/js/caja.js:<línea>" justo en las entradas del .po cuyo texto usa el JavaScript.

    Es el mismo criterio con el que se arma el JSON (el texto sin el contexto), así que el JSON
    que compila Loco Translate al importar el .po sale igual que el nuestro. Loco ignora la
    referencia si no lleva número de línea. Solo cambia las líneas "#:" de las entradas que lo
    necesitan; el resto del archivo queda igual.
    """
    blocks = open(po, encoding='utf-8', newline='').read().split('\n\n')
    changed = 0
    for n, block in enumerate(blocks):
        lines = block.split('\n')
        at = next((i for i, l in enumerate(lines) if l.startswith('msgid ')), None)
        if at is None:
            continue
        mid = re.match(r'^msgid\s+"(.*)"$', lines[at]).group(1)
        for l in lines[at + 1:]:
            if not l.startswith('"'):
                break
            mid += l[1:-1]
        mid = unesc(mid)
        if mid == '':
            continue
        refs = [r for l in lines if l.startswith('#:') for r in l[2:].split()]
        others = [r for r in refs if re.sub(r':\d+$', '', r) != JS]
        want = others + (['%s:%d' % (JS, used[mid])] if mid in used else [])
        if want == refs:
            continue
        refs = want
        lines = [l for l in lines if not l.startswith('#:')]
        # Va después de los comentarios ("# " y "#.") y antes de las marcas ("#,") y del msgid.
        pos = next(i for i, l in enumerate(lines) if not (l == '#' or l.startswith('# ') or l.startswith('#.')))
        if refs:
            lines.insert(pos, '#: ' + ' '.join(refs))
        blocks[n] = '\n'.join(lines)
        changed += 1
    if changed:
        open(po, 'w', encoding='utf-8', newline='').write('\n\n'.join(blocks))
    return changed


def compile_po(po, locale, used):
    """Los tres archivos de una traducción, como {nombre: bytes}, con el nombre de ese locale."""
    entries = parse_po(po)
    header = ''
    items = []  # (clave, valor, es_plural)
    for e in entries:
        mid = unesc(e['msgid'])
        ctx = unesc(e['msgctxt']) if 'msgctxt' in e else None
        if 'msgid_plural' in e:
            s0, s1 = unesc(e.get('msgstr0', '')), unesc(e.get('msgstr1', ''))
            if not s0 and not s1:
                continue
            k, v, pl = mid + '\0' + unesc(e['msgid_plural']), s0 + '\0' + s1, True
        else:
            v = unesc(e.get('msgstr', ''))
            if mid == '':
                header = v; continue
            if not v:
                continue
            k, pl = mid, False
        if ctx is not None:
            k = ctx + '\x04' + k
        items.append((k, v, pl))

    plural = 'nplurals=2; plural=(n != 1);'
    m = re.search(r'Plural-Forms:\s*([^\n]+)', header)
    if m:
        plural = m.group(1).strip()

    header = re.sub(r'Language: [^\n]*', 'Language: ' + locale, header)
    mo_pairs = [(k.encode(), v.encode()) for k, v, _ in items]
    mo_pairs.append((b'', header.encode()))
    files = {'dox-pos-%s.mo' % locale: mo_bytes(mo_pairs)}

    lines = ['<?php', '/**', ' * Traducción al español de Dox POS (generada del .po).', ' *',
             ' * @package DoxPos', ' */', '', 'return array(',
             "\t'domain'       => 'dox-pos',",
             "\t'plural-forms' => '%s'," % plural,
             "\t'language'     => '%s'," % locale,
             "\t'messages'     => array("]
    for k, v, _ in sorted(items, key=lambda x: x[0]):
        lines.append('\t\t' + php_key(k) + ' => ' + php_key(v) + ',')
    lines += ['\t),', ');']
    files['dox-pos-%s.l10n.php' % locale] = ('\n'.join(lines) + '\n').encode()

    data = {'': {'domain': 'messages', 'lang': locale, 'plural-forms': plural}}
    for k, v, pl in items:
        clean = k.split('\x04')[-1]  # sin el contexto, para ver si el .js usa ese texto
        if clean.split('\0')[0] in used:
            # La clave lleva el contexto ("contexto\u0004texto"), que es lo que busca wp.i18n: si se
            # quitara, una entrada con contexto pisaria a la que no lo tiene (le paso a "Sale").
            data[k.split('\0')[0]] = v.split('\0')
    out = {'translation-revision-date': '2026-09-08 00:00:00+0000', 'generator': 'Dox POS',
           'source': JS, 'domain': 'messages', 'locale_data': {'messages': data}}
    name = 'dox-pos-%s-%s.json' % (locale, hashlib.md5(JS.encode()).hexdigest())
    files[name] = json.dumps(out, ensure_ascii=False, separators=(',', ':')).encode()
    return files, len(items), len(data) - 1


def write_pack(locale, po, used):
    """El zip de una variante, siempre igual byte a byte si no cambia nada (fecha fija), para
    que git no vea cambios donde no los hay."""
    files, _, _ = compile_po(os.path.join(BASE, 'languages', po), locale, used)
    text = open(os.path.join(BASE, 'languages', po), encoding='utf-8').read()
    files['dox-pos-%s.po' % locale] = re.sub(r'"Language: [^\\]*\\n"', '"Language: %s\\n"' % locale, text, count=1).encode()
    path = os.path.join(PACKS, 'dox-pos-%s.zip' % locale)
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        for name in sorted(files):
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            z.writestr(info, files[name])
    return path


def main():
    used = js_strings()
    marked = sum(sync_js_refs(used, po) for po in sorted(glob.glob(os.path.join(BASE, 'languages', 'dox-pos-*.po'))))
    files, n_items, n_js = compile_po(PO, LOCALE, used)
    for name, data in files.items():
        open(os.path.join(BASE, 'languages', name), 'wb').write(data)
    os.makedirs(PACKS, exist_ok=True)
    for locale in sorted(VARIANTS):
        write_pack(locale, VARIANTS[locale], used)
    print('mo y l10n.php: %d entradas | JSON: %d | referencias a caja.js cambiadas: %d | zips: %d en translations/'
          % (n_items, n_js, marked, len(VARIANTS)))


main()
