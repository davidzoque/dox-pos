#!/usr/bin/env python3
"""Genera el .mo, el .l10n.php y el JSON del JavaScript a partir del .po.

    python3 tools/build-translations.py

El .po es la única fuente. El JSON solo lleva las cadenas que aparecen en
assets/js/caja.js, y su nombre es el que espera wp_set_script_translations:
dox-pos-<locale>-<md5 de la ruta del js>.json
"""
import hashlib
import json
import os
import re
import struct
import sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOCALE = 'es_ES'
JS = 'assets/js/caja.js'
PO = os.path.join(BASE, 'languages', 'dox-pos-%s.po' % LOCALE)


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


def write_mo(path, pairs):
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
    open(path, 'wb').write(mo + bytes(data))
    return n


def main():
    entries = parse_po(PO)
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

    mo_pairs = [(k.encode(), v.encode()) for k, v, _ in items]
    mo_pairs.append((b'', header.encode()))
    n = write_mo(os.path.join(BASE, 'languages', 'dox-pos-%s.mo' % LOCALE), mo_pairs)

    lines = ['<?php', '/**', ' * Traducción al español de Dox POS (generada del .po).', ' *',
             ' * @package DoxPos', ' */', '', 'return array(',
             "\t'domain'       => 'dox-pos',",
             "\t'plural-forms' => '%s'," % plural,
             "\t'language'     => '%s'," % LOCALE,
             "\t'messages'     => array("]
    for k, v, _ in sorted(items, key=lambda x: x[0]):
        lines.append('\t\t' + php_key(k) + ' => ' + php_key(v) + ',')
    lines += ['\t),', ');']
    open(os.path.join(BASE, 'languages', 'dox-pos-%s.l10n.php' % LOCALE), 'w', encoding='utf-8').write('\n'.join(lines) + '\n')

    js = open(os.path.join(BASE, JS), encoding='utf-8').read()
    used = set()
    lit_re = re.compile(r"""\b_{1,2}n?\s*\(\s*("(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*')""")
    for m in lit_re.finditer(js):
        used.add(js_unesc(m.group(1)[1:-1]))
    data = {'': {'domain': 'messages', 'lang': LOCALE, 'plural-forms': plural}}
    for k, v, pl in items:
        clean = k.split('\x04')[-1]
        if clean.split('\0')[0] in used:
            data[clean.split('\0')[0]] = v.split('\0')
    out = {'translation-revision-date': '2026-09-08 00:00:00+0000', 'generator': 'Dox POS',
           'source': JS, 'domain': 'messages', 'locale_data': {'messages': data}}
    name = 'dox-pos-%s-%s.json' % (LOCALE, hashlib.md5(JS.encode()).hexdigest())
    open(os.path.join(BASE, 'languages', name), 'w', encoding='utf-8').write(
        json.dumps(out, ensure_ascii=False, separators=(',', ':')))
    print('mo: %d entradas | l10n.php: %d | %s: %d' % (n, len(items), name, len(data) - 1))


main()
