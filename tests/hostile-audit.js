/* Auditoría "tema hostil" — pegar en la consola de una página con la ficha o el listado (o
   correrlo desde una herramienta de automatización). Inyecta tests/hostile-theme.css, compara el
   estilo calculado de CADA elemento del plugin con esa hoja activada vs. desactivada (mismo DOM,
   misma página) y devuelve las diferencias. Resultado ideal: 0 diferencias.
   Uso: await oecHostileAudit()  → { diffs, byProp, samples } */
window.oecHostileAudit = async function (opts) {
  opts = opts || {};
  const base = (document.querySelector('link[href*="oec-wordpress-plugin/css/"]') || {}).href || '';
  const cssUrl = opts.cssUrl || base.replace(/css\/[^?]+.*$/, 'tests/hostile-theme.css');
  const css = await (await fetch(cssUrl, { cache: 'no-store' })).text();

  // Sin transiciones/animaciones mientras se mide: si no, getComputedStyle devuelve valores a mitad de camino.
  const still = document.createElement('style');
  still.textContent = '*,*::before,*::after{transition:none!important;animation-duration:0s!important;animation-delay:0s!important}';
  document.head.appendChild(still);
  const hostile = document.createElement('style');
  hostile.id = 'oec-hostile';
  hostile.textContent = css;
  document.head.appendChild(hostile);

  const ROOTS = '#oec-bleed-wrapper, #oec-menu-sticky, #oec-mobile-bar, #oec-region-panel, #oec-country-panel, #oec-region-backdrop, .oec-float-wa, .oec-float-botmaker, .oec-scope';
  const all = [...document.querySelectorAll(ROOTS)];
  const roots = all.filter(r => !all.some(o => o !== r && o.contains(r)));
  const els = roots.flatMap(r => [r, ...r.querySelectorAll('*')]).filter(e => !['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'BR'].includes(e.tagName));

  const PROPS = ['color', 'background-color', 'background-image', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
    'letter-spacing', 'word-spacing', 'text-transform', 'text-decoration-line', 'text-align', 'text-indent',
    'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width', 'border-top-style', 'border-bottom-style',
    'border-top-color', 'border-top-left-radius', 'box-shadow', 'box-sizing', 'display', 'float', 'list-style-type', 'min-height',
    'min-width', 'vertical-align', 'white-space', 'quotes'];
  const PSEUDO = ['content', 'color', 'margin-right', 'display'];

  function snap() {
    return els.map(e => {
      const cs = getComputedStyle(e), o = {};
      PROPS.forEach(p => o[p] = cs.getPropertyValue(p));
      ['::before', '::after'].forEach(ps => { const c = getComputedStyle(e, ps); PSEUDO.forEach(p => o[ps + ' ' + p] = c.getPropertyValue(p)); });
      const r = e.getBoundingClientRect(); o.__w = Math.round(r.width); o.__h = Math.round(r.height);
      return o;
    });
  }
  hostile.disabled = true; void document.body.offsetHeight; // getComputedStyle ya fuerza el recálculo; rAF no corre en pestañas ocultas
  const clean = snap();
  hostile.disabled = false; void document.body.offsetHeight;
  const dirty = snap();
  if (!opts.keep) hostile.remove();
  still.remove();

  const label = e => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/).slice(0, 3).join('.') : '');
  const byProp = {}, samples = {}; let diffs = 0, sizeDiffs = 0;
  els.forEach((e, i) => {
    const a = clean[i], b = dirty[i];
    Object.keys(a).forEach(k => {
      if (a[k] === b[k]) return;
      if (k === '__w' || k === '__h') { if (Math.abs(a[k] - b[k]) > 1) sizeDiffs++; return; }
      diffs++; byProp[k] = (byProp[k] || 0) + 1;
      (samples[k] = samples[k] || []).length < 6 && samples[k].push(label(e) + ': ' + a[k] + ' → ' + b[k]);
    });
  });
  return { elements: els.length, roots: roots.map(label), diffs, sizeDiffs, byProp, samples };
};

/* Foto del estilo calculado de todos los elementos del plugin (sin tema hostil) y comparador —
   para verificar que un cambio de CSS del plugin NO altera el look actual en el tema normal:
     const antes = oecSnapshot();  …recargar solo el CSS con oecReloadPluginCss()…
     oecCompareSnapshots(antes, oecSnapshot())  → mismo formato de resultado que la auditoría. */
window.oecSnapshot = function () {
  const ROOTS = '#oec-bleed-wrapper, #oec-menu-sticky, #oec-mobile-bar, #oec-region-panel, #oec-country-panel, #oec-region-backdrop, .oec-float-wa, .oec-float-botmaker, .oec-scope';
  const all = [...document.querySelectorAll(ROOTS)];
  const roots = all.filter(r => !all.some(o => o !== r && o.contains(r)));
  const els = roots.flatMap(r => [r, ...r.querySelectorAll('*')]).filter(e => !['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'BR'].includes(e.tagName));
  const PROPS = ['color', 'background-color', 'background-image', 'font-family', 'font-size', 'font-weight', 'font-style', 'line-height',
    'letter-spacing', 'word-spacing', 'text-transform', 'text-decoration-line', 'text-align', 'text-indent',
    'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width', 'border-top-style', 'border-bottom-style',
    'border-top-color', 'border-top-left-radius', 'box-shadow', 'box-sizing', 'display', 'float', 'list-style-type', 'min-height',
    'min-width', 'vertical-align', 'white-space', 'quotes', 'height', 'width'];
  const still = document.createElement('style');
  still.textContent = '*,*::before,*::after{transition:none!important;animation-duration:0s!important;animation-delay:0s!important}';
  document.head.appendChild(still); void document.body.offsetHeight;
  // Ruta relativa al contenedor del plugin (no a <body>): lo de afuera puede cambiar entre cargas
  // (ej. el id aleatorio "oec-fb-xxxx" del envoltorio de la ficha).
  const path = e => { const p = []; let n = e; for (; n && !roots.includes(n); n = n.parentElement) p.unshift(n.tagName + ':' + [...n.parentElement.children].indexOf(n)); p.unshift(n ? (n.id || n.className) : '?'); return p.join('>'); };
  const out = {};
  els.forEach(e => {
    const cs = getComputedStyle(e), o = { __label: e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\s+/).slice(0, 3).join('.') : '') };
    PROPS.forEach(p => o[p] = cs.getPropertyValue(p));
    ['::before', '::after'].forEach(ps => { const c = getComputedStyle(e, ps); ['content', 'color', 'margin-right', 'display', 'width'].forEach(p => o[ps + ' ' + p] = c.getPropertyValue(p)); });
    out[path(e)] = o;
  });
  still.remove();
  return out;
};
window.oecCompareSnapshots = function (a, b, ignore) {
  ignore = ignore || [];
  const byProp = {}, samples = {}; let diffs = 0, missing = 0;
  Object.keys(a).forEach(k => {
    if (!b[k]) { missing++; return; }
    Object.keys(a[k]).forEach(p => {
      if (p === '__label' || ignore.includes(p) || a[k][p] === b[k][p]) return;
      diffs++; byProp[p] = (byProp[p] || 0) + 1;
      (samples[p] = samples[p] || []).length < 8 && samples[p].push(a[k].__label + ': ' + a[k][p] + ' → ' + b[k][p]);
    });
  });
  return { diffs, missing, byProp, samples };
};
window.oecReloadPluginCss = function () {
  return Promise.all([...document.querySelectorAll('link[rel=stylesheet][href*="oec-wordpress-plugin/css/"]')].map(l => new Promise(res => {
    const n = l.cloneNode(), u = new URL(l.href); u.searchParams.set('oecv', Date.now()); n.href = u.href;
    n.onload = n.onerror = () => { l.remove(); res(); }; l.after(n);
  })));
};
