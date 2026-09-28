// oec-frontend.js
// JS propio del plugin OEC WordPress.
// Precios, formas de pago, selector de moneda, timer, créditos, afiliados y el
// resto de la lógica de la página viven en el <script> inline de page-formacion.txt.
// Este archivo SOLO maneja: toggle del sticky nav al hacer scroll (#oec-menu-sticky ya
// existe como HTML estático en el template — acá solo se lo mueve al <body> para
// escapar de cualquier ancestro con transform, y se lo muestra/oculta), y el drag
// con mouse/touch de los mini-bullets y del carrusel de reviews.
// IMPORTANTE: no agregar acá nada que ya viva en el inline script (precios, pagos,
// región/moneda, timer, créditos, sticky menu por JS, etc.) — este archivo se carga
// con defer + in_footer, así que corre DESPUÉS del inline y pisaría su trabajo.

// ============================================
// STICKY NAV BAR — fuera de WordPress para evitar escape de &&
// ============================================

(function() {
    // El sticky debe vivir en el body, no dentro del bleed wrapper con transform.
    // transform: translateX(-50%) rompe position:fixed en elementos hijos.
    const stickyEl = document.getElementById('oec-menu-sticky');
    if (!stickyEl) return;

    // Moverlo al body si está dentro del bleed wrapper
    document.body.appendChild(stickyEl);

    // Medir su alto REAL (nombre de la formación + tabs, 2 filas) para que
    // el sticky de la columna derecha (.oec-rc-sticky, position:sticky) no
    // quede tapado por esta barra ni con un hueco de más — se expone como
    // variable CSS en vez de hardcodear un px fijo en la hoja de estilos,
    // así si el alto de esta barra vuelve a cambiar (más texto, otro
    // tamaño de fuente, etc.) no hay que ir a buscar el número a mano de
    // nuevo. Se mide oculto (visibility:hidden) para no hacer parpadear la
    // barra un frame antes de que updateSticky() decida si corresponde
    // mostrarla.
    const prevDisplay = stickyEl.style.display;
    stickyEl.style.visibility = 'hidden';
    stickyEl.style.display    = 'block';
    document.documentElement.style.setProperty('--oec-sticky-h', stickyEl.offsetHeight + 'px');
    stickyEl.style.display    = prevDisplay || 'none';
    stickyEl.style.visibility = '';

    // Header fijo del TEMA (o la barra de administración de WordPress con sesión iniciada): en
    // sitios con Divi/Avada/Astra/Elementor el header suele quedar "position:fixed" arriba y, con
    // top:0, esta barra quedaba tapada (o lo tapaba). En vez de casos por tema, se mide qué hay
    // realmente pegado al borde superior de la pantalla que NO sea del plugin (fixed/sticky, que
    // arranque en y≤1) y su borde inferior pasa a ser el "techo" de esta barra: variable
    // --oec-top-offset, que usan el CSS (top de esta barra y del sticky de precios) y el scroll de
    // los links de secciones. Se recalcula en cada scroll: si el header del tema se achica o se
    // esconde al bajar, la barra lo sigue. Se ignora cualquier cosa más alta que el 40% de la
    // pantalla (un menú mobile abierto, un popup de cookies a pantalla completa, etc.).
    function isOurs(el) { return !!(el.closest && el.closest('#oec-menu-sticky, #oec-bleed-wrapper, #oec-mobile-bar, .oec-region-panel, #oec-region-backdrop, .oec-float-wa, .oec-float-botmaker, iframe[name="Botmaker"]')); }
    let lastTopOffset = -1;
    function measureTopOffset() {
        let bottom = 0;
        const maxH = window.innerHeight * 0.4;
        [12, window.innerWidth / 2, window.innerWidth - 12].forEach(x => {
            (document.elementsFromPoint ? document.elementsFromPoint(x, 1) : []).forEach(hit => {
                if (isOurs(hit)) return;
                for (let n = hit; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
                    const pos = getComputedStyle(n).position;
                    if (pos !== 'fixed' && pos !== 'sticky') continue;
                    const r = n.getBoundingClientRect();
                    if (r.top <= 1 && r.bottom > 0 && r.height <= maxH) bottom = Math.max(bottom, r.bottom);
                    break;
                }
            });
        });
        bottom = Math.round(bottom);
        if (bottom !== lastTopOffset) {
            lastTopOffset = bottom;
            document.documentElement.style.setProperty('--oec-top-offset', bottom + 'px');
        }
        return bottom;
    }

    const heroEl      = document.getElementById('oec-hero');
    let stickyVisible = false;
    let scrollTicking = false;
    let lastActive    = '';
    const navSections = ['oec-fechas','oec-presentacion','oec-docentes','oec-contenidos','oec-opiniones','oec-bullets','oec-masinformacion','oec-contacto'];

    function updateSticky() {
        const top   = window.scrollY;
        const topOffset = measureTopOffset();
        const heroH = (heroEl ? heroEl.offsetHeight : 380) - 20;

        if (top >= heroH && !stickyVisible) {
            stickyEl.style.display = 'block';
            stickyVisible = true;
        } else if (top < heroH && stickyVisible) {
            stickyEl.style.display = 'none';
            stickyVisible = false;
        }

        // Mientras el click en un link todavía está animando el scroll, no
        // recalculamos: ese click ya marcó su propio link como activo, y con
        // secciones cortas/pegadas (Contenidos/Opiniones) este cálculo por
        // scroll podía pisarlo saltando directo a la siguiente.
        if (stickyVisible && !window.OEC_STICKY_SUSPEND) {
            // Línea de referencia: justo debajo de la barra sticky, no muy
            // adentro de la página — así una sección corta no queda "saltada".
            const scrollY = top + topOffset + stickyEl.offsetHeight + 12;
            // Elegimos la sección con el offsetTop más alto que ya cruzamos,
            // no la última del array — presentacion/docentes cambian de orden
            // en el DOM según los datos, así que iterar el array en orden fijo
            // podía marcar como "activa" una sección ya pasada.
            let active = '';
            let bestTop = -Infinity;
            navSections.forEach(id => {
                const el = document.getElementById(id);
                if (el && el.offsetTop <= scrollY && el.offsetTop > bestTop) {
                    bestTop = el.offsetTop;
                    active = id;
                }
            });
            document.querySelectorAll('.oec-sticky-links a').forEach(a => {
                a.classList.toggle('active', a.getAttribute('where') === active);
            });

            // En mobile la barra de tabs scrollea horizontal (overflow-x:auto) y
            // el tab activo puede quedar fuera de vista. Solo la deslizamos cuando
            // el activo realmente cambió (no en cada frame de scroll) — evita pelear
            // con la propia animación mientras el usuario todavía la está generando.
            // block:'nearest' es lo que evita que esto además intente scrollear
            // la página verticalmente (solo mueve el contenedor horizontal interno).
            if (active && active !== lastActive) {
                lastActive = active;
                const activeLink = stickyEl.querySelector(`.oec-sticky-links a[where="${active}"]`);
                if (activeLink) activeLink.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
            }
        }

        scrollTicking = false;
    }

    // OEC_STICKY_SUSPEND solo se libera ante un gesto real de scroll del
    // usuario (rueda/touch/teclado) — nunca por un timer ligado a la
    // animación del click. Liberarlo desde el callback "complete" de esa
    // animación competía con el último scroll-event que ella misma dispara:
    // según quién ganara la carrera, el link recién clickeado podía terminar
    // pisado por el cálculo automático justo al llegar.
    const scrollKeys = ['ArrowUp','ArrowDown','PageUp','PageDown','Home','End',' '];
    window.addEventListener('wheel', () => { window.OEC_STICKY_SUSPEND = false; }, { passive: true });
    window.addEventListener('touchmove', () => { window.OEC_STICKY_SUSPEND = false; }, { passive: true });
    window.addEventListener('keydown', e => { if (scrollKeys.includes(e.key)) window.OEC_STICKY_SUSPEND = false; });

    window.addEventListener('scroll', () => {
        if (!scrollTicking) {
            requestAnimationFrame(updateSticky);
            scrollTicking = true;
        }
    }, { passive: true });

    updateSticky();
})();

// Mover al body todos los elementos fixed/overlay que estén dentro del bleed wrapper
document.addEventListener('DOMContentLoaded', function() {
    ['oec-mobile-bar', 'oec-overlay', 'oec-sticky-open', 'oec-sticky-close', 'oec-float-wa'].forEach(id => {
        const el = document.getElementById(id);
        if (el && el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
    });

    // En mobile la columna derecha (".oec-rc": fechas, countdown, agregar al
    // calendario, precios) está oculta por CSS — la única inscripción visible
    // es la barra fija de abajo. La reubicamos al principio de la columna
    // izquierda, antes de "Presentación", para que esa info no desaparezca.
    // Es el mismo nodo (no una copia), así que conserva sus listeners y no
    // duplica ningún id. Reacciona a cambios de breakpoint (no solo a la carga
    // inicial) porque girar una tablet de vertical a horizontal cruza los
    // 960px sin recargar la página, y sin esto la card quedaba huérfana en
    // la columna izquierda con el layout de desktop ya activo.
    (function initRcCardPosition() {
        const rColumn    = document.getElementById('oec-r-column');
        const mobileSlot = document.getElementById('oec-mobile-rc-slot');
        const rc         = document.querySelector('.oec-rc');
        if (!rColumn || !mobileSlot || !rc) return;

        const slotParent = mobileSlot.parentElement;
        const slotNext   = mobileSlot.nextSibling;
        let isMobile = null;

        function placeMobile() {
            if (isMobile === true) return;
            slotParent.insertBefore(rc, slotNext);
            if (mobileSlot.parentElement) mobileSlot.remove();
            isMobile = true;
        }
        function placeDesktop() {
            if (isMobile === false) return;
            rColumn.prepend(rc);
            slotParent.insertBefore(mobileSlot, slotNext);
            isMobile = false;
        }

        const mq = window.matchMedia('(max-width: 960px)');
        mq.addEventListener('change', e => e.matches ? placeMobile() : placeDesktop());
        mq.matches ? placeMobile() : placeDesktop();
    })();

    // ─── DRAG EN MINI BULLETS ────────────────────────────────────
    const miniBullets = document.getElementById('oec-mini-bullets');
    if (miniBullets) {
        let isDraggingMB = false, startXMB, startScrollMB;
        miniBullets.style.userSelect = 'none';
        miniBullets.style.cursor = 'grab';
        miniBullets.addEventListener('mousedown', e => {
            isDraggingMB = false;
            startXMB = e.pageX;
            startScrollMB = miniBullets.scrollLeft;
            miniBullets.style.cursor = 'grabbing';
            document.body.style.userSelect = 'none';
        });
        document.addEventListener('mousemove', e => {
            if (startXMB === undefined) return;
            const diff = e.pageX - startXMB;
            if (Math.abs(diff) > 5) {
                isDraggingMB = true;
                miniBullets.scrollLeft = startScrollMB - diff;
            }
        });
        document.addEventListener('mouseup', () => {
            startXMB = undefined;
            miniBullets.style.cursor = 'grab';
            document.body.style.userSelect = '';
            // Pequeño delay para que el click del + no se cancele si no hubo drag real
            setTimeout(() => { isDraggingMB = false; }, 10);
        });
        miniBullets.addEventListener('click', e => {
            if (isDraggingMB) e.stopPropagation();
        });
    }

    // ─── CARRUSEL DE REVIEWS ─────────────────────────────────────
    const carousel = document.getElementById('oec-rev-carousel');
    if (carousel) {
        carousel.style.scrollBehavior = 'unset';

        let paused = false, isDragging = false, startX, startScroll, frameCount = 0, manuallyPaused = false;

        // Sin duplicación — mostramos las reviews únicas y el scroll se detiene al final
        function step() {
            if (!paused && !isDragging && !manuallyPaused && !window.OEC_STOP_REVIEWS_AUTOSCROLL) {
                frameCount++;
                if (frameCount % 3 === 0) {
                    carousel.scrollLeft += 1;
                }
            }
            requestAnimationFrame(step);
        }
        requestAnimationFrame(step);

        carousel.addEventListener('mouseenter', () => { if (!manuallyPaused) paused = true; });
        carousel.addEventListener('mouseleave', () => { if (!manuallyPaused) paused = false; });

        carousel.addEventListener('mousedown', e => {
            e.preventDefault();
            isDragging = true;
            paused = true;
            startX = e.pageX; startScroll = carousel.scrollLeft;
            carousel.style.cursor = 'grabbing';
            document.body.style.userSelect = 'none';
        });
        document.addEventListener('mousemove', e => {
            if (!isDragging) return;
            carousel.scrollLeft = startScroll - (e.pageX - startX);
        });
        document.addEventListener('mouseup', () => {
            if (!isDragging) return;
            isDragging = false;
            manuallyPaused = true; // detener para siempre
            carousel.style.cursor = 'grab';
            document.body.style.userSelect = '';
        });

        carousel.addEventListener('touchstart', e => {
            paused = true; startX = e.touches[0].pageX; startScroll = carousel.scrollLeft;
        }, { passive: true });
        carousel.addEventListener('touchmove', e => {
            carousel.scrollLeft = startScroll - (e.touches[0].pageX - startX);
        }, { passive: true });
        carousel.addEventListener('touchend', () => { paused = false; });
    }
});