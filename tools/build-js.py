#!/usr/bin/env python3
"""Genera js/oec-formacion.min.js y js/oec-frontend.min.js (los JS de la ficha) con terser.

Correrlo después de CADA cambio en esos dos archivos y commitear los .min junto con los
originales (igual que tools/build-css.py con el CSS). Necesita Node (usa `npx terser`, versión
fija). El enqueue (class-oec-shortcodes.php) sirve el .min solo si no es más viejo que el
original: si alguien edita el original y no regenera, se sirve el original.

Opciones de terser: comprime y acorta nombres locales, pero sin tocar los nombres de arriba
del archivo (toplevel off) ni las propiedades: las funciones que la plantilla llama desde un
onclick (oec_get_reviews, oec_metaAddToCart) se exponen a window explícitamente.
"""
import os
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FILES = ['js/oec-formacion.js', 'js/oec-frontend.js']

for rel in FILES:
    src = os.path.join(ROOT, rel)
    dst = src[:-3] + '.min.js'
    subprocess.run(
        ['npx', '--yes', 'terser@5.36.0', src, '--compress', '--mangle', '--ecma', '2020', '--output', dst],
        check=True,
    )
    print(f'{os.path.relpath(dst, ROOT)}: {os.path.getsize(dst)} bytes (original {os.path.getsize(src)})')
sys.exit(0)
