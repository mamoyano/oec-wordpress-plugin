#!/usr/bin/env python3
"""Genera css/oec-formacion.min.css a partir de css/oec-formacion.css (el que se edita).

Solo saca comentarios y espacios: no reescribe valores ni reordena reglas (csso, por ejemplo,
cambiaba `background: none` por `0 0` y `outline: none` por `0`). Respeta los textos entre comillas.
Correrlo después de cada cambio en oec-formacion.css:  python3 tools/build-css.py
Si el .min queda más viejo que el original, el plugin sirve el original (ver el enqueue en
includes/class-oec-shortcodes.php): nunca llega al navegador un CSS desactualizado.
"""
import os, re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'css', 'oec-formacion.css')
DST = os.path.join(ROOT, 'css', 'oec-formacion.min.css')

def minify(css):
    out, i, n = [], 0, len(css)
    while i < n:
        c = css[i]
        if c in '"\'':                       # texto entre comillas: se copia tal cual
            j = i + 1
            while j < n and css[j] != c:
                j += 2 if css[j] == '\\' else 1
            out.append(css[i:j + 1]); i = j + 1
        elif css.startswith('/*', i):        # comentario: afuera
            j = css.find('*/', i + 2)
            i = n if j < 0 else j + 2
            out.append(' ')
        elif c.isspace():                    # cualquier tira de espacios → uno solo
            while i < n and css[i].isspace():
                i += 1
            out.append(' ')
        else:
            out.append(c); i += 1
    s = ''.join(out)
    # Sin espacios alrededor de { } ; (nunca alrededor de ":" ni de "," — en un selector
    # ".a :where(x)" el espacio es un combinador y cambia el significado).
    s = re.sub(r'\s*([{};])\s*', r'\1', s)
    return s.replace(';}', '}').strip() + '\n'

with open(SRC, encoding='utf-8') as f:
    css = f.read()
with open(DST, 'w', encoding='utf-8') as f:
    f.write(minify(css))
print(f'css/oec-formacion.min.css: {os.path.getsize(DST)} bytes (original {os.path.getsize(SRC)})')
