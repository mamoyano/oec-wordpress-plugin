// ═══ ENVOLTORIO — nada de este archivo queda en el ámbito global ═══════════
// El plugin convive con temas/plugins/snippets que no controlamos: funciones globales con
// nombres genéricos (debounce, throttle, qsp, setExp, getExp…, que era como vivían acá) se pisan
// con las de cualquier otro script que use el mismo nombre, y el que carga último rompe al otro.
// Todo queda dentro de esta función; lo único que se expone a window son las dos funciones que
// llaman los onclick/href del Twig (oec_get_reviews, oec_metaAddToCart — ver al final).
// Además:
// - Arranca recién con el DOM listo (aunque algún optimizador mueva este archivo al <head>).
// - Si todavía no existen los datos de la formación (el <script> en línea del Twig que define
//   OEC_TRAINING_DATA/OEC_CONFIG — un optimizador tipo WP Rocket/Autoptimize puede demorarlo o
//   reordenarlo), reintenta al "load"; si siguen sin estar, no hace nada en vez de tirar errores.
(function () {
function oecStart() {
if (typeof OEC_TRAINING_DATA === 'undefined' || typeof OEC_CONFIG === 'undefined') {
    if (!oecStart.retried) { oecStart.retried = true; window.addEventListener('load', oecStart, { once: true }); }
    else if (window.console) console.warn('[OEC] No se encontraron los datos de la formación (OEC_TRAINING_DATA); se omite la inicialización.');
    return;
}
// Un error en un bloque no tiene que cortar los demás (antes, una excepción en cualquier
// inicialización dejaba sin correr todas las siguientes: precios, región, afiliados…).
function oecSafe(label, fn) { try { fn(); } catch (err) { if (window.console) console.error('[OEC] ' + label, err); } }

// ─── CONTADOR ANIMADO (compartido: tips del hero + números de los
// anillos del hero) ─────────────────────────────────────────────
// Cuenta desde 0 hasta el valor final del texto que ya está puesto en el
// elemento, con ease-out cubic. Soporta dos formatos de número, que es
// todo lo que aparece en este bloque: entero + sufijo libre ("16" +
// " días", "89" + "%") y decimal con COMA ("5,0" el promedio de
// opiniones — la coma es el separador que ya usa toda la plantilla,
// |number_format(1,',','') — no un punto).
function oecAnimateNumberText(el, duration) {
    const text = el.textContent.trim();
    const commaMatch = text.match(/^(\d+),(\d+)(.*)$/);
    const startTime = performance.now();
    if (commaMatch) {
        const target   = parseFloat(commaMatch[1] + '.' + commaMatch[2]);
        const decimals = commaMatch[2].length;
        const suffix   = commaMatch[3];
        (function update(now) {
            const progress = Math.min((now - startTime) / duration, 1);
            const eased    = 1 - Math.pow(1 - progress, 3);
            el.textContent = (eased * target).toFixed(decimals).replace('.', ',') + suffix;
            if (progress < 1) requestAnimationFrame(update);
        })(startTime);
        return;
    }
    const intMatch = text.match(/^(\d+)(.*)$/);
    if (!intMatch) return;
    const target = parseInt(intMatch[1]);
    const suffix = intMatch[2];
    (function update(now) {
        const progress = Math.min((now - startTime) / duration, 1);
        const eased    = 1 - Math.pow(1 - progress, 3);
        el.textContent = Math.round(eased * target) + suffix;
        if (progress < 1) requestAnimationFrame(update);
    })(startTime);
}

// Esperamos a que las fuentes web terminen de cargar antes de medir el
// ancho final de cada píldora: si se mide antes (con la tipografía de
// respaldo todavía puesta), el ancho fijado queda corto y al llegar
// Manrope el contenido vuelve a crecer un par de píxeles — eso alcanza
// para que la fila (flex-wrap) reacomode una píldora de línea mientras
// dura el conteo, aunque el min-width ya esté seteado.
document.fonts.ready.then(() => {
    document.querySelectorAll('.oec-tip.destacado').forEach(tip => {
        if (!/^\d/.test(tip.textContent.trim())) return;
        // Fijar el ancho final ANTES de animar: el texto ya está en su valor
        // definitivo en este punto (todavía no arrancó el conteo), así que medir
        // acá da el ancho que la píldora va a tener al terminar. Un min-width
        // no alcanza: como sigue siendo flex-item con ancho "auto", el navegador
        // recalcula su tamaño de contenido en cada frame (aunque el resultado
        // quede clampeado al mismo mínimo) y ese recálculo puede variar por
        // redondeo de subpíxel — alcanza para reacomodar la fila (flex-wrap) un
        // pixel de más o de menos. Por eso se fija como flex-basis explícito
        // (con grow/shrink en 0), así el layout deja de mirar el contenido de
        // texto para calcular el tamaño de esta píldora.
        const finalWidth = Math.ceil(tip.getBoundingClientRect().width);
        tip.style.flex = '0 0 ' + finalWidth + 'px';
        tip.style.width = finalWidth + 'px';
        // Esperar a que el hero sea visible
        const observer = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting) {
                oecAnimateNumberText(tip, 2500);
                observer.disconnect();
            }
        });
        observer.observe(tip);
    });
});

// ─── ANILLOS ANIMADOS (rating de opiniones + los 3-4 del hero) ──
// El número de cada anillo del hero arranca a contar en el mismo
// instante en que el trazo empieza a dibujarse (misma duración, 2.5s,
// que el "transition: stroke-dashoffset" de ".oec-hero-ring-fill" en
// oec-formacion.css — si se cambia uno, cambiar el otro para que sigan
// sincronizados).
(function(){
    const rings = document.querySelectorAll('.oec-rating-ring-fill, .oec-hero-ring-fill');
    if (!rings.length) return;
    if (!('IntersectionObserver' in window)) { rings.forEach(r => r.classList.add('is-visible')); return; }
    rings.forEach(ring => {
        const observer = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting) {
                ring.classList.add('is-visible');
                if (ring.classList.contains('oec-hero-ring-fill')) {
                    const score = ring.closest('.oec-hero-ring-circle')?.querySelector('.oec-hero-ring-score');
                    if (score) oecAnimateNumberText(score, 2500);
                }
                observer.disconnect();
            }
        });
        observer.observe(ring);
    });
})();

// ─── "¿POR QUÉ ELEGIR ESTA FORMACIÓN?" — aparición escalonada ──
// Al entrar en viewport, cada ".oec-bullet" se va agregando de a uno
// (fade + leve subida, ver CSS) en vez de aparecer todos juntos — un
// solo IntersectionObserver sobre el contenedor (no uno por ítem, no
// hace falta), el escalonado se logra con un pequeño delay creciente
// por índice.
(function(){
    const wrap = document.querySelector('.oec-bullets');
    if (!wrap) return;
    const items = wrap.querySelectorAll('.oec-bullet');
    if (!items.length) return;
    if (!('IntersectionObserver' in window)) { items.forEach(el => el.classList.add('is-visible')); return; }
    const observer = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) {
            items.forEach((el, i) => setTimeout(() => el.classList.add('is-visible'), i * 70));
            observer.disconnect();
        }
    });
    observer.observe(wrap);
})();

// ─── CARRUSEL — manejado por oec-frontend.js ─────────────────

// ─── ACORDEÓN ────────────────────────────────────────────────
document.querySelectorAll('.oec-ac-btn:not(.fake)').forEach(btn => {
    btn.addEventListener('click', () => {
        const item = btn.closest('.oec-ac-item');
        item.classList.toggle('is-active');
        btn.setAttribute('aria-expanded', item.classList.contains('is-active'));
    });
});

// ─── COLLAPSIBLE (presentación, reviews) ─────────────────────
document.querySelectorAll('.oec-collapsible').forEach(wrapper => {
    const inner  = wrapper.querySelector('.oec-col-inner');
    const toggle = wrapper.querySelector('.oec-col-toggle');
    if (!inner || !toggle) return;
    // Este mismo bloque de código corre para TODOS los ".oec-collapsible"
    // de la página (docentes, presentación, contenidos, opiniones...) — el
    // botón "cargar más opiniones" solo tiene sentido revelarlo cuando el
    // que se expande es JUSTAMENTE el de opiniones. Bug real que hubo acá:
    // como antes se buscaba "#oec-more_reviews_2" sin este chequeo, CUALQUIER
    // otro bloque corto (ej. una bio de docente con poco texto, que se
    // auto-expande solo por ser corta) disparaba el botón de "cargar más
    // opiniones" aunque la sección de opiniones siguiera colapsada — se
    // veía como un salto de espacio raro y un link suelto sin contexto.
    const isReviewsWrapper = !!wrapper.querySelector('.oec-reviews-full-list');
    const expand = () => {
        inner.classList.replace('collapsed','expanded');
        toggle.style.display = 'none';
        wrapper.style.cursor = 'default';
        if (isReviewsWrapper) {
            const moreBtn = document.getElementById('oec-more_reviews_2');
            if (moreBtn) moreBtn.style.display = 'block';
        }
    };
    // Docentes: plegar a 280px cortaba la 2da tarjeta al medio (en mobile, 2 docentes ya
    // pasan ese alto). Ahí solo se pliega si lo que quedaría oculto es bastante.
    const limit = wrapper.querySelector('.oec-teacher-row') ? 440 : 280;
    requestAnimationFrame(() => {
        if (inner.scrollHeight <= limit) { expand(); return; }
        // Click en cualquier parte del wrapper expande
        wrapper.addEventListener('click', () => {
            if (inner.classList.contains('collapsed')) expand();
        });
    });
});

// ─── UTILITARIOS ─────────────────────────────────────────────
function debounce(fn,w){ let t; return function(...a){ clearTimeout(t); t=setTimeout(()=>fn.apply(this,a),w); }; }
function throttle(fn,l){ let t; return function(...a){ if(!t){ fn.apply(this,a); t=true; setTimeout(()=>t=false,l); } }; }
function qsp(n){ const r=new RegExp(`[?&]${n}(=([^&#]*)|&|#|$)`).exec(location.href); return r?decodeURIComponent((r[2]||'').replace(/\+/g,' ')):null; }
function setExp(k,v,d){ try{ localStorage.setItem(k,JSON.stringify({value:v,expiry:Date.now()+d*864e5})); }catch(e){} }
function getExp(k){ try{ const s=localStorage.getItem(k); if(!s)return null; const i=JSON.parse(s); return Date.now()>i.expiry?(localStorage.removeItem(k),null):i.value; }catch(e){return null;} }
function oec_metaAddToCart(){ if(window.fbq) fbq('track','AddToCart',{content_name:OEC_TRAINING_DATA.title,content_ids:[OEC_TRAINING_DATA.id],content_type:'product'}); }

// ─── ENDPOINTS ───────────────────────────────────────────────
const OEC_API = {
    payOpts:   'https://api.onlineeducation.center/campus/api/trainings',
    revFull:   'https://api.g-se.com/v2/content/trainings',
    initPrefs: 'https://api.g-se.com/v2/initialPreferences',
    prices:    'https://api.onlineeducation.center/api-oas/v1/trainings',
    auditoriumSessions: 'https://onlineeducation.center/endpoints.php?action=auditoriumSessions&params='
};

// ─── ANUNCIO DE SESIONES EN VIVO (auditorio) ──────────────────
// El carrusel de reviews (oec-frontend.js) arranca a moverse casi al
// instante; este chequeo depende de un fetch a un endpoint externo, que
// tarda más. Si dejábamos que el carrusel arrancara y recién después esta
// función le avisara "pausate" al terminar el fetch, el freno llegaba a
// mitad de una tarjeta (se veía cortada). Por eso OEC_STOP_REVIEWS_AUTOSCROLL
// arranca en `true` ("todavía no sabemos") ANTES de siquiera pedir el
// endpoint — el carrusel no se mueve un solo píxel hasta que esta función
// resuelve que no hay sesiones próximas. Una vez que el carrusel arrancó a
// moverse (porque acá dijimos que sí), no lo volvemos a frenar.
async function initLiveSessionsMarquee(editionUid){
    window.OEC_STOP_REVIEWS_AUTOSCROLL = true;

    const $wrap=jQuery('#oec-live-wrap'), $track=jQuery('#oec-live-marquee-track');
    if(!$wrap.length || !editionUid){ window.OEC_STOP_REVIEWS_AUTOSCROLL=false; return; }

    // "_cb" (cache-buster) hace que la URL sea única en cada carga de página, para que
    // ninguna caché externa por delante del servidor (CDN/proxy) pueda servir una
    // respuesta vieja de otro origen. No afecta la caché propia del PHP, que se arma
    // con action+params, sin este parámetro.
    const url=`${OEC_API.auditoriumSessions}${editionUid}/auditorium-sessions&_cb=${Date.now()}`;
    try{
        const res=await fetch(url);
        if(!res.ok) throw new Error('Error en la respuesta del proxy: '+res.status);
        const data=await res.json();
        if(!data.sessions || !data.sessions.length){ window.OEC_STOP_REVIEWS_AUTOSCROLL=false; return; }

        const now=new Date();
        const futureSessions=data.sessions
            .filter(s=>new Date(s.acSesion.inicio)>now)
            .sort((a,b)=>new Date(a.acSesion.inicio)-new Date(b.acSesion.inicio));
        if(!futureSessions.length){ window.OEC_STOP_REVIEWS_AUTOSCROLL=false; return; }

        const formatter=new Intl.DateTimeFormat(undefined,{day:'numeric',month:'long',hour:'2-digit',minute:'2-digit',hour12:false});
        const itemsHtml=futureSessions.map(s=>`
            <span class="oec-live-item">
                <span class="oec-live-dot"></span>
                <span class="oec-live-tag">EN VIVO</span>
                ${formatter.format(new Date(s.acSesion.inicio))} hs: "${s.nombre}"
            </span>
        `).join('');
        $track.html(itemsHtml);
        $wrap.css('display','block');

        // Hay sesión próxima: el anuncio se muestra y el carrusel de reviews
        // se queda quieto (OEC_STOP_REVIEWS_AUTOSCROLL sigue en true) para no
        // saturar de movimiento la pantalla a la vez.

        // Calculamos la duración del scroll según el ancho real del contenido,
        // para que la velocidad de desplazamiento sea siempre pareja.
        requestAnimationFrame(()=>{
            const containerWidth=$wrap.find('.oec-live-marquee').outerWidth()||0;
            const contentWidth=$track.outerWidth()||0;
            const speed=50; // px por segundo
            const duration=Math.max(8,(containerWidth+contentWidth)/speed);
            $track.css({
                '--oec-live-start': containerWidth+'px',
                '--oec-live-end': (-contentWidth)+'px',
                '--oec-live-duration': duration+'s'
            });
        });
    }catch(err){
        console.error('Error cargando sesiones del auditorio:', err);
        window.OEC_STOP_REVIEWS_AUTOSCROLL=false;
    }
}

// El aviso "incluida en otra formación" ahora se arma del lado del
// servidor (data.parents, ver el Twig) — ya no hace falta pedirlo acá.

// ─── LISTAS DE REDES SOCIALES EN BIOS DE DOCENTES ─────────────
// Detecta <ul><li><a>...</a></li></ul> dentro de la bio (patrón que usan para
// linkear LinkedIn/ResearchGate/Instagram/etc. al no tener un campo dedicado) y
// las reemplaza por botones circulares con el ícono de la red correspondiente.
function initTeacherSocialLinks(){
    const ICONS = [
        { re: /linkedin\.com/i,          icon: 'bi-linkedin' },
        { re: /researchgate\.net/i,      icon: 'bi-mortarboard-fill' },
        { re: /instagram\.com/i,         icon: 'bi-instagram' },
        { re: /facebook\.com/i,          icon: 'bi-facebook' },
        { re: /(twitter\.com|x\.com)/i,  icon: 'bi-twitter-x' },
        { re: /youtube\.com/i,           icon: 'bi-youtube' },
        { re: /tiktok\.com/i,            icon: 'bi-tiktok' }
    ];
    function iconFor(href){
        const hit = ICONS.find(o => o.re.test(href));
        return hit ? hit.icon : 'bi-link-45deg';
    }
    jQuery('.oec-prose ul').each(function(){
        const $ul = jQuery(this);
        const $items = $ul.children('li');
        if(!$items.length) return;
        // Solo transformamos si CADA <li> es exclusivamente un <a> (nada de texto
        // suelto ni otros elementos) — así no tocamos listas de temario normales.
        const isSocialList = $items.toArray().every(li=>{
            const $li = jQuery(li);
            const hasOneLink = $li.children('a').length === 1;
            const hasStrayText = $li.contents().filter(function(){
                return this.nodeType===3 && this.textContent.trim()!=='';
            }).length > 0;
            return hasOneLink && !hasStrayText;
        });
        if(!isSocialList) return;

        const $row = jQuery('<div class="oec-social-links"></div>');
        $items.each(function(){
            const $a = jQuery(this).children('a').first();
            const href = $a.attr('href');
            const label = $a.text().trim();
            if(!href) return;
            // El texto del <li><a> original ("Seguir en Instagram"...) ahora se ve
            // en el botón en vez de quedar solo como title/aria-label — se arma con
            // .text() (no .html()) para no reinterpretar el label como HTML.
            const $btn = jQuery('<a></a>')
                .addClass('oec-social-btn')
                .attr({ href, target:'_blank', rel:'noopener noreferrer' });
            jQuery('<i></i>').addClass('bi ' + iconFor(href)).appendTo($btn);
            jQuery('<span></span>').addClass('oec-social-btn-label').text(label).appendTo($btn);
            $row.append($btn);
        });
        $ul.replaceWith($row);
    });
}

// ─── CHAT DIRECTO (Botmaker) — sección Contacto + ícono flotante ────
// El script de Botmaker (inyectado acá mismo, recién ante la primera
// intención del usuario — ver loadBotmaker(); el Twig solo expone su URL
// en window.OEC_BOTMAKER_SRC cuando corresponde ofrecer chat directo)
// mete su propia "pelotita" flotante apenas termina de
// cargar. La escondemos con bmHide() y la reemplazamos por nuestros
// propios disparadores — cualquier elemento con la clase
// ".oec-botmaker-trigger" (hoy son dos: el botón "Iniciar chat
// directo" de la sección Contacto, y el ícono flotante
// "#oec-float-botmaker" que reemplaza al de WhatsApp cuando el monto
// de la formación es bajo — ver data.prices.total en el Twig). Al
// clickear CUALQUIERA de los dos: abre el widget (bmShow + bmMaximize)
// y manda el mismo mensaje predeterminado que ya usa el botón de
// WhatsApp (bmSendMessage) — mismo `chat_message` del Twig, cada
// disparador trae su propio data-msg con ese texto.
// Ojo: bmHide/bmShow/bmMaximize/bmSendMessage no están en la
// documentación pública de Botmaker — se confirmaron probándolos en
// vivo contra su CDN real (cargan el script real, quedan expuestos en
// window). Si Botmaker cambia su script y estos nombres dejan de
// existir, whenReady() simplemente nunca llama a fn() — no rompe nada,
// pero el botón deja de hacer algo visible; revisar esto primero si
// alguna vez "Iniciar chat directo" deja de abrir el chat.
function initBotmakerChat(){
    const triggers = document.querySelectorAll('.oec-botmaker-trigger');
    if (!triggers.length || !window.OEC_BOTMAKER_SRC) return; // chat directo no ofrecido en esta formación

    // Carga diferida: el script de Botmaker (una app React de ~200 KB comprimidos + 4 familias de
    // Google Fonts + un polyfill) se inyecta recién ante la primera señal de intención sobre un
    // disparador — hover/foco/toque (precalienta, así al click ya suele estar listo) o el click
    // mismo. Antes se cargaba en CADA visita, aunque casi nadie abriera el chat.
    let bmLoaded = false;
    function loadBotmaker(){
        if (bmLoaded) return;
        bmLoaded = true;
        const js = document.createElement('script');
        js.async = true;
        js.src = window.OEC_BOTMAKER_SRC;
        document.body.appendChild(js);
        startHideWatcher();
    }

    function whenReady(fn, triesLeft){
        if (typeof window.bmHide === 'function' && typeof window.bmShow === 'function'
            && typeof window.bmMaximize === 'function' && typeof window.bmSendMessage === 'function') {
            fn();
            return;
        }
        if (triesLeft <= 0) return; // Botmaker no cargó (bloqueado, caído, etc.) — no insistimos más
        setTimeout(() => whenReady(fn, triesLeft - 1), 200);
    }

    // Bug real (2026-10-08): las funciones bm* aparecen ANTES de que
    // Botmaker termine de conectarse con su servidor, y bmSendMessage
    // descarta el mensaje sin avisar mientras no tenga usuario (en su
    // código: "No user or business defined yet"). Con la carga diferida
    // el delay fijo de 500ms a veces no alcanzaba y el mensaje inicial se
    // perdía. Se espera a que bmInfo() traiga el contacto (se completa
    // junto con el usuario); si bmInfo deja de existir, vuelve el delay.
    function whenConnected(fn, triesLeft){
        if (typeof window.bmInfo !== 'function') { setTimeout(fn, 500); return; }
        let info = null;
        try { info = window.bmInfo(); } catch (err) { /* todavía montándose */ }
        if ((info && info.platformContactId) || triesLeft <= 0) { fn(); return; }
        setTimeout(() => whenConnected(fn, triesLeft - 1), 200);
    }
    const sentMsgs = new Set();

    // El iframe del widget se identifica por name="Botmaker" (atributo
    // real del <iframe> que inyecta su script — verificado en vivo).
    // Necesario para no confundirlo con OTRO iframe que pueda haber en
    // la página (ej. un video de Vimeo incrustado en "Presentación").
    function bmIframe(){ return document.querySelector('iframe[name="Botmaker"]'); }

    // Bug real encontrado probando el flujo completo: cuando el usuario
    // cierra la conversación desde el botón propio de Botmaker ("Cerrar
    // chat", adentro del widget), bmHide() NO se dispara solo — el
    // widget cae en su propio estado "minimizado" (la pelotita flotante
    // con el globito de pregunta que no queríamos mostrar nunca, tapando
    // "INICIAR INSCRIPCIÓN"), no en escondido de verdad. Búsqueda en la
    // documentación pública de Botmaker (incluida la que pasó Mario):
    // no hay ningún evento "se cerró la conversación" ni una opción de
    // configuración para desactivar esa pelotita de entrada.
    // Se detecta por TAMAÑO en vez de por evento: medido en vivo, el
    // iframe totalmente escondido (bmHide) da 0×0, abierto da pantalla
    // completa en mobile / ~450×647 en desktop, y esa pelotita
    // intermedia da ~330×110 — bastante más chica que "abierto" pero
    // !=0. Un vigía cada 400ms controla: si NO pedimos que esté abierto
    // (userWantsOpen) y el iframe mide algo (>0), lo volvemos a
    // esconder — cubre tanto este caso (cierre desde adentro) como la
    // carrera de la primera carga (bmHide() llamado antes de que el
    // widget termine de montarse del todo, ver sesión anterior).
    let userWantsOpen = false;
    // Refleja userWantsOpen en <body> — el CSS (".oec-formacion.css",
    // "iframe[name=\"Botmaker\"]") lo usa para esconder el widget
    // SIEMPRE por default, sin importar timing de JS; solo se
    // "desbloquea" mientras body tenga esta clase. Ver comentario largo
    // en el CSS para el porqué (la carrera de la primera carga en
    // desktop con red lenta no se podía resolver solo con JS async).
    function setOpen(open){
        userWantsOpen = open;
        document.body.classList.toggle('oec-bm-chat-open', open);
    }
    function startHideWatcher(){
        whenReady(() => {
            setInterval(() => {
                if (userWantsOpen) return;
                const iframe = bmIframe();
                if (!iframe) return;
                const r = iframe.getBoundingClientRect();
                if (r.width > 0 || r.height > 0) window.bmHide();
            }, 400);
        }, 75); // hasta 15s de espera a que Botmaker cargue (red móvil lenta)
    }

    triggers.forEach(function(trigger){
        ['pointerenter', 'focus', 'touchstart'].forEach(ev => trigger.addEventListener(ev, loadBotmaker, { once: true, passive: true }));
        trigger.addEventListener('click', function(e){
            e.preventDefault();
            loadBotmaker();
            trigger.classList.add('oec-bm-loading');
            whenReady(() => {
                trigger.classList.remove('oec-bm-loading');
                setOpen(true);
                window.bmShow();
                window.bmMaximize();
                const msg = trigger.getAttribute('data-msg') || '';
                // Una sola vez por visita: reabrir el chat no lo repite.
                if (msg && !sentMsgs.has(msg)) {
                    sentMsgs.add(msg);
                    whenConnected(() => window.bmSendMessage(msg), 75);
                }
            }, 75);
        });
    });

    // Detectar el cierre desde ADENTRO del widget (mismo-origen,
    // confirmado en vivo) para reaccionar al toque en vez de esperar
    // hasta el próximo tick del vigía (máx. 400ms de diferencia, pero
    // esto evita hasta ese destello). Se re-adjunta en cada tick del
    // vigía (barato) porque Botmaker reescribe su propio documento
    // interno (document.write) al cambiar de estado — un listener
    // puesto una sola vez se puede perder si el botón que escuchaba ya
    // no es el mismo nodo del DOM.
    // El selector es por aria-label, NO por clase — probado en vivo:
    // el botón "Cerrar chat" tiene una clase distinta en la variante
    // mobile del widget (".wc-header-right") que en la de desktop
    // (".wc-button.wc-button-regular"), pero el aria-label es igual en
    // las dos.
    //
    // Bug real encontrado probando DOS aperturas en la misma carga de
    // página (ej. la burbuja flotante y después el botón de Contacto):
    // en una reapertura, Botmaker a veces REUSA el mismo nodo <button>
    // de "Cerrar chat" de la sesión anterior en vez de crear uno nuevo
    // (no siempre hace document.write) — pero `dataset.oecBound` seguía
    // marcado desde la primera apertura, y como el listener se puso con
    // `{ once: true }`, ya se había consumido y desaparecido solo al
    // primer cierre. Con el dataset todavía en "1", el vigía creía que
    // ya había un listener vivo y nunca ponía uno nuevo — el segundo
    // click en "Cerrar chat" no hacía nada, `userWantsOpen` se quedaba
    // en `true` para siempre, y el widget quedaba visible en su estado
    // "minimizado" (la pelotita con la pregunta predeterminada de
    // Botmaker) sin que nada lo volviera a esconder. Fix: limpiar el
    // dataset justo cuando el listener se dispara (adentro del propio
    // handler) — así, si el nodo sobrevive a un cierre y se reusa en la
    // próxima apertura, el vigía lo vuelve a ver "libre" y le engancha
    // un listener fresco.
    setInterval(() => {
        if (!userWantsOpen) return;
        // Minimizado por otra vía que no sea "Cerrar chat": vuelve la burbuja.
        try {
            if (typeof window.bmInfo === 'function' && window.bmInfo().isMinimized) { setOpen(false); return; }
        } catch (err) { /* sigue el enganche de abajo */ }
        // try/catch: si Botmaker alguna vez sirve el iframe desde otro
        // origen (hoy no es el caso, verificado en vivo), leer
        // contentDocument tira SecurityError — sin esto, el vigía de
        // arriba (el que sí importa, el que esconde el widget) se
        // rompería en cascada por un error en ESTE detalle secundario.
        try {
            const iframe = bmIframe();
            const closeBtn = iframe && iframe.contentDocument && iframe.contentDocument.querySelector('[aria-label="Cerrar chat"]');
            if (closeBtn && !closeBtn.dataset.oecBound) {
                closeBtn.dataset.oecBound = '1';
                closeBtn.addEventListener('click', () => {
                    delete closeBtn.dataset.oecBound;
                    setOpen(false);
                }, { once: true });
            }
        } catch (err) { /* cross-origin u otro cambio de Botmaker — el vigía de tamaño sigue funcionando igual */ }
    }, 400);
}

// ─── COUNTDOWN EN BLOQUES (fecha límite de inscripción y de descuento) ───────
function updateCountdowns(){
    const now = Date.now()/1000;
    const pad = n => n.toString().padStart(2,'0');
    document.querySelectorAll('.oec-countdown').forEach(el => {
        const end  = Date.parse(el.getAttribute('date'))/1000;
        const left = Math.max(0, end-now);
        const d=Math.floor(left/86400), h=Math.floor((left%86400)/3600), m=Math.floor((left%3600)/60), s=Math.floor(left%60);
        if (d<=15) el.style.display='flex';

        const daysUnit = el.querySelector('.oec-cd-days');
        if (daysUnit) daysUnit.classList.toggle('oec-cd-hide', d<=0);

        [['d',d],['h',h],['m',m],['s',s]].forEach(([unit,val]) => {
            const span = el.querySelector(`.oec-cd-num[data-unit="${unit}"]`);
            if (!span) return;
            const text = pad(val);
            if (span.textContent !== text) {
                span.textContent = text;
                // Reiniciar la animación de "tic" en cada cambio de valor.
                span.classList.remove('oec-cd-pulse');
                void span.offsetWidth;
                span.classList.add('oec-cd-pulse');
            }
        });
    });
}

// ─── AÑADIR AL CALENDARIO (widget propio, sin librerías externas) ─
function initAddToCalendar(){
    const $wrap=jQuery('#oec-addcal');
    if(!$wrap.length) return;
    const $btn=jQuery('#oec-addcal-btn'), $menu=jQuery('#oec-addcal-menu');

    const pad2=n=>String(n).padStart(2,'0');
    const toUtcStamp=d=>`${d.getUTCFullYear()}${pad2(d.getUTCMonth()+1)}${pad2(d.getUTCDate())}T${pad2(d.getUTCHours())}${pad2(d.getUTCMinutes())}${pad2(d.getUTCSeconds())}Z`;

    function buildEvent(){
        const cal=OEC_TRAINING_DATA.calendar;
        const startISO = cal.beforeStart ? OEC_TRAINING_DATA.start : OEC_TRAINING_DATA.enrollment_end;
        const start=new Date(startISO);
        const end=new Date(start.getTime()+60*60*1000); // 1 hora, igual que el widget que reemplaza
        const title=`Inscribirme en: ${OEC_TRAINING_DATA.name}`;
        const desc=cal.short_description;
        const location=`${OEC_TRAINING_DATA.community}/es/formacion/${cal.slug}?utm_source=addtocalendar&utm_medium=button&utm_campaign=ficha-${OEC_TRAINING_DATA.community_oec_domain}`;
        return { start, end, title, desc, location };
    }
    function openGoogle(){
        const {start,end,title,desc,location}=buildEvent();
        const url=`https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent(title)}&dates=${toUtcStamp(start)}/${toUtcStamp(end)}&details=${encodeURIComponent(desc)}&location=${encodeURIComponent(location)}`;
        window.open(url,'_blank','noopener');
    }
    function openOutlook(){
        const {start,end,title,desc,location}=buildEvent();
        const url=`https://outlook.live.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent&startdt=${start.toISOString()}&enddt=${end.toISOString()}&subject=${encodeURIComponent(title)}&body=${encodeURIComponent(desc)}&location=${encodeURIComponent(location)}`;
        window.open(url,'_blank','noopener');
    }
    function downloadIcs(){
        const {start,end,title,desc,location}=buildEvent();
        const esc=s=>String(s).replace(/[\\;,]/g,m=>'\\'+m).replace(/\n/g,'\\n');
        const ics=[
            'BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//OEC//Formacion//ES','CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            `UID:${Date.now()}@onlineeducation.center`,
            `DTSTAMP:${toUtcStamp(new Date())}`,
            `DTSTART:${toUtcStamp(start)}`,
            `DTEND:${toUtcStamp(end)}`,
            `SUMMARY:${esc(title)}`,
            `DESCRIPTION:${esc(desc)}`,
            `URL:${location}`,
            'END:VEVENT','END:VCALENDAR'
        ].join('\r\n');
        const blob=new Blob([ics],{type:'text/calendar;charset=utf-8'});
        const a=document.createElement('a');
        a.href=URL.createObjectURL(blob);
        a.download='evento.ics';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(()=>URL.revokeObjectURL(a.href), 2000);
    }

    $btn.on('click',function(e){
        e.stopPropagation();
        const willOpen=$menu.prop('hidden');
        $menu.prop('hidden',!willOpen);
        $btn.attr('aria-expanded',willOpen?'true':'false');
    });
    $menu.on('click','.oec-addcal-opt',function(e){
        e.preventDefault();
        const provider=jQuery(this).data('provider');
        if(provider==='google') openGoogle();
        else if(provider==='outlook') openOutlook();
        else if(provider==='ics') downloadIcs();
        $menu.prop('hidden',true);
        $btn.attr('aria-expanded','false');
    });
    jQuery(document).on('click',function(e){
        if(!jQuery(e.target).closest('#oec-addcal').length){ $menu.prop('hidden',true); $btn.attr('aria-expanded','false'); }
    });
    jQuery(document).on('keydown',function(e){
        if(e.key==='Escape'){ $menu.prop('hidden',true); $btn.attr('aria-expanded','false'); }
    });
}

// ─── PRECIOS ─────────────────────────────────────────────────
// Recibe uno o varios ids destino (la misma info se muestra en el bloque
// fijo y en el sticky) — un solo fetch, se pinta en todos los destinos.
function checkPayOpts(where, tid, country){
    const targets = Array.isArray(where) ? where : [where];
    fetch(`${OEC_API.payOpts}/${tid}/currentEdition/payment-methods-locale?country=${country}`,{headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():Promise.reject())
    .then(data=>{
        if(!data.data?.length) return;
        const html = data.data.map(p=>{
            const l=p.logo.substring(p.logo.lastIndexOf('/')+1);
            return `<div class="oec-pay-tile" title="${p.es.name}"><img src="https://imgrsize.oe-img.center/statics/checkout/${l}?w=100&q=90" width="100" height="100" alt="${p.es.name}" loading="lazy"></div>`;
        }).join('');
        targets.forEach(id=>{
            const el=document.getElementById(id);
            if(el) el.innerHTML=html;
        });
    }).catch(()=>{});
}
// Formato de moneda es-AR: punto para miles, coma para decimales (solo si hay centavos)
function formatOecMoney(n){
    return Number(n).toLocaleString('es-AR', { minimumFractionDigits:0, maximumFractionDigits:2 });
}
function getRegPrices(id,total,country,currency){
    if(total<=0){ jQuery('.just-price').text('GRATIS'); return; }
    // Medios de pago en paralelo con los precios (solo dependen del país): antes se pedían recién
    // con los precios ya llegados, y esa cadena de pedidos estiraba la carga (PageSpeed seguía
    // midiendo cuando arrancaban GTM y el Pixel). Se pintan en cajas que se muestran más abajo.
    if(!OEC_CONFIG.force_contact) checkPayOpts(['oec-po_span','oec-sticky-pay','oec-mb-pay'],id,country);
    fetch(`${OEC_API.prices}/${id}/prices?country=${country}&currency=${currency}`)
    .then(r=>r.json())
    .then(data=>{
        const t=data.discountPercent?data.toPriceDiscount:data.total, cur=data.currency;
        jQuery('.total-price').text(`${cur} ${formatOecMoney(Math.round(t*100)/100)}`);
        jQuery('.just-price').text(`${cur} ${formatOecMoney(Math.round(data.total*100)/100)}`);
        // Un módulo que vale 0 se muestra como GRATIS (igual que .just-price con total 0), no "ARS 0".
        if(data.modules) jQuery.each(data.modules,(i,v)=>jQuery(`.price-module-${i}`).text(v.amount>0 ? `${cur} ${formatOecMoney(Math.round(v.amount*100)/100)}` : 'GRATIS'));
        // force_contact: la formación no se vende online → no se muestran formas de pago (ni en la
        // caja sticky, que en plantillas pegadas antes de 1.4.4 trae el bloque igual, oculto).
        if(OEC_CONFIG.force_contact) return;
        const poDivEl = document.getElementById('oec-po_div');
        if(poDivEl) poDivEl.style.display='block';
        const stickyPayWrapEl = document.getElementById('oec-sticky-pay-wrap');
        if(stickyPayWrapEl) stickyPayWrapEl.style.display='block';
        const poBulletEl = document.getElementById('oec-po_bullet');
        if(poBulletEl && Array.isArray(data.data)) {
            poBulletEl.innerHTML = data.data.map(p => {
                const l = p.logo.substring(p.logo.lastIndexOf('/')+1);
                return `<img src="https://imgrsize.oe-img.center/statics/checkout/${l}?w=100&q=90" alt="${p.es.name}" title="${p.es.name}" height="24" loading="lazy" style="max-width:60px;object-fit:contain">`;
            }).join('');
        }
    }).catch(console.error);
}
// País y moneda del visitante. Sin "force-cache" (v1.4.12): con ese modo el navegador reusaba la
// respuesta guardada sin preguntar, así que al cambiar de red/VPN seguía viendo el país anterior, y los
// precios (la API pide revalidar: max-age=0, must-revalidate) podían quedar viejos.
function setCountryCurrency(){
    fetch(OEC_API.initPrefs,{cache:'no-store'}).then(r=>r.json())
    .then(data=>{
        const c=String(qsp('country')||data.country||'AR').toUpperCase();
        let cur=String(qsp('currency')||data.currency||'ARS').toUpperCase();
        // El país detectado puede no estar entre los 16 fijos de la plantilla: sin esto el <select>
        // quedaba vacío y la etiqueta decía "Argentina" (pasó con NL). La moneda, en cambio, tiene que
        // ser una de las del selector — la API de precios solo cotiza esas (con otra responde error).
        oecEnsureCountryOption(c);
        if(!jQuery(`#oec-currencies_select option[value="${cur}"]`).length) cur='USD';
        jQuery('#oec-countries_select, #c_country').val(c);
        jQuery('#oec-currencies_select').val(cur);
        jQuery('.oec-more-info-form').attr('data-country',c);
        getRegPrices(OEC_TRAINING_DATA.id,OEC_TRAINING_DATA.prices.total,c,cur);
        updateOecRegionUI();
    }).catch(()=>{ getRegPrices(OEC_TRAINING_DATA.id,OEC_TRAINING_DATA.prices.total,'AR','ARS'); updateOecRegionUI(); });
}

// ─── SELECTOR REGIONAL (país / moneda) — imita el checkout ────
// Todos los países (ISO 3166-1). El nombre en español sale de Intl.DisplayNames: así cada país del
// panel tiene su CÓDIGO real. Antes era una lista de nombres sin código y, si el país no estaba entre
// los 16 fijos, se mandaba "XX" (Otros Países), que la API cotiza distinto y sin medios de pago.
const OEC_ALL_COUNTRY_CODES='AF AX AL DE AD AO AI AG AQ SA DZ AR AM AW AU AT AZ BS BH BD BB BE BZ BJ BM BY BO BQ BA BW BR BN BG BF BI BT CV KH CM CA QA TD CZ CL CN CY VA CO KM CG CD KP KR CI CR HR CU CW DK DM EC EG SV AE ER SK SI ES US EE SZ ET PH FI FJ FR GA GM GE GH GI GD GR GL GP GU GT GF GG GN GW GQ GY HT HN HK HU IN ID IQ IR IE BV IM CX NF IS KY CC CK FO GS HM FK MP MH UM PN SB TC VG VI IL IT JM JP JE JO KZ KE KG KI XK KW LA LS LV LB LR LY LI LT LU MO MK MG MY MW MV ML MT MA MQ MU MR YT MX FM MD MC MN ME MS MZ MM NA NR NP NI NE NG NU NO NC NZ OM NL PK PW PS PA PG PY PE PF PL PT PR GB CF DO RE RW RO RU EH WS AS BL KN SM MF PM VC SH LC ST SN RS SC SL SG SX SY SO LK ZA SD SS SE CH SR SJ TH TW TZ TJ IO TF TL TG TK TO TT TN TM TR TV UA UG UY UZ VU VE VN WF YE DJ ZM ZW'.split(' ');
const oecRegionNames=(()=>{ try{ return new Intl.DisplayNames(['es'],{type:'region'}); }catch(e){ return null; } })();
function oecCountryName(code){ try{ return (oecRegionNames && oecRegionNames.of(code)) || code; }catch(e){ return code; } }
// Agrega al <select> oculto el país que no esté entre los fijos de la plantilla.
function oecEnsureCountryOption(code){
    const $sel=jQuery('#oec-countries_select');
    if(!code || !$sel.length || $sel.find(`option[value="${code}"]`).length) return;
    $sel.append(jQuery('<option>').val(code).text(oecCountryName(code)));
}

let oecCountryOverrideName=null, oecFreqNameToCode={};

function updateOecRegionUI(){
    const $cSel=jQuery('#oec-countries_select'), $curSel=jQuery('#oec-currencies_select');
    const countryName=oecCountryOverrideName||$cSel.find('option:selected').text()||'Argentina';
    const currencyCode=$curSel.val()||'ARS';
    jQuery('#oec-region-trigger-label').text(`Ver precios: ${countryName} · ${currencyCode}`);
    jQuery('#oec-country-current-label').text(countryName);
    jQuery('#oec-currency-list li').attr('aria-selected','false');
    jQuery(`#oec-currency-list li[data-value="${currencyCode}"]`).attr('aria-selected','true');
    jQuery('#oec-country-freq li').attr('aria-selected','false');
    if(!oecCountryOverrideName) jQuery(`#oec-country-freq li[data-value="${$cSel.val()}"]`).attr('aria-selected','true');
    jQuery('#oec-country-all li').attr('aria-selected','false');
    jQuery(`#oec-country-all li[data-code="${$cSel.val()}"]`).attr('aria-selected','true');
}

function positionOecRegionPanel($panel){
    const trigger=document.getElementById('oec-region-trigger');
    if(!trigger) return;
    const r=trigger.getBoundingClientRect(), vw=window.innerWidth, vh=window.innerHeight;
    const panelW=$panel.outerWidth()||300;
    // Preferimos abrir hacia arriba (el trigger suele estar al pie de la columna); si no entra, abrimos hacia abajo.
    const spaceAbove=r.top-16, spaceBelow=vh-r.bottom-16;
    const openUp=spaceAbove>=Math.min(320,spaceBelow) || spaceAbove>=spaceBelow;
    const maxH=Math.max(180, (openUp?spaceAbove:spaceBelow));
    $panel.css('max-height', Math.min(440, maxH)+'px');
    let top=openUp ? r.top-$panel.outerHeight()-8 : r.bottom+8;
    top=Math.max(10, Math.min(top, vh-$panel.outerHeight()-10));
    let left=Math.max(10, Math.min(r.left, vw-panelW-10));
    $panel.css({ top: top+'px', left: left+'px' });
}

function openOecRegionPanel(id){
    jQuery('#oec-region-backdrop').addClass('is-visible');
    jQuery('#oec-region-trigger').attr('aria-expanded','true');
    const $panel=jQuery('#'+id);
    $panel.prop('hidden',false);
    positionOecRegionPanel($panel);
}

function closeAllOecRegionPanels(){
    jQuery('#oec-region-panel, #oec-country-panel').prop('hidden',true);
    jQuery('#oec-region-backdrop').removeClass('is-visible');
    jQuery('#oec-region-trigger').attr('aria-expanded','false');
}

function initOecRegionSelector(){
    if(!document.getElementById('oec-reg_config')) return;

    // Movemos el backdrop y los paneles al <body>: así quedan inmunes a cualquier
    // ancestro con transform/animación que rompa el position:fixed, y su z-index
    // queda siempre por encima de todo lo demás en la página (incluido el footer).
    jQuery('#oec-region-backdrop, #oec-region-panel, #oec-country-panel').appendTo(document.body);

    const $freq=jQuery('#oec-country-freq'), $all=jQuery('#oec-country-all'), $cur=jQuery('#oec-currency-list');

    jQuery('#oec-countries_select option').each(function(){
        const val=jQuery(this).val(), name=jQuery(this).text();
        if(val==='XX') return;
        oecFreqNameToCode[name.trim().toLowerCase()]=val;
        $freq.append(`<li data-value="${val}" role="option"><span>${name}</span><i class="bi bi-check-lg"></i></li>`);
    });
    OEC_ALL_COUNTRY_CODES.map(code=>[code, oecCountryName(code)])
        .sort((a,b)=>a[1].localeCompare(b[1],'es'))
        .forEach(([code,name])=>{ $all.append(jQuery(`<li role="option"><span></span><i class="bi bi-check-lg"></i></li>`).attr('data-code',code).find('span').text(name).end()); });
    jQuery('#oec-currencies_select option').each(function(){
        const val=jQuery(this).val(), name=jQuery(this).text();
        $cur.append(`<li data-value="${val}" role="option"><span>${name}</span><i class="bi bi-check-lg"></i></li>`);
    });

    jQuery('#oec-region-trigger').on('click',function(e){
        e.stopPropagation();
        const isOpen=!jQuery('#oec-region-panel').prop('hidden') || !jQuery('#oec-country-panel').prop('hidden');
        closeAllOecRegionPanels();
        if(!isOpen) openOecRegionPanel('oec-region-panel');
    });
    jQuery('#oec-country-trigger').on('click',function(e){ e.stopPropagation(); jQuery('#oec-region-panel').prop('hidden',true); openOecRegionPanel('oec-country-panel'); });
    jQuery('#oec-country-back').on('click',function(e){ e.stopPropagation(); jQuery('#oec-country-panel').prop('hidden',true); openOecRegionPanel('oec-region-panel'); });
    jQuery('.oec-region-close').on('click',function(e){ e.stopPropagation(); closeAllOecRegionPanels(); });
    jQuery('#oec-region-backdrop').on('click',function(){ closeAllOecRegionPanels(); });
    jQuery(document).on('keydown',function(e){ if(e.key==='Escape') closeAllOecRegionPanels(); });
    // Reposicionamos (no cerramos) ante scroll/resize de la PÁGINA, para que el panel
    // siga anclado al botón. Sin "capture", así no reacciona al scroll interno de la
    // lista de países (eso rompía "Volver"/cerrar al scrollear la lista).
    function oecRepositionOpenPanels(){
        if(!jQuery('#oec-region-panel').prop('hidden')) positionOecRegionPanel(jQuery('#oec-region-panel'));
        if(!jQuery('#oec-country-panel').prop('hidden')) positionOecRegionPanel(jQuery('#oec-country-panel'));
    }
    window.addEventListener('scroll', oecRepositionOpenPanels, {passive:true});
    jQuery(window).on('resize', oecRepositionOpenPanels);

    $freq.on('click','li',function(){
        jQuery('#oec-countries_select').val(jQuery(this).data('value'));
        oecCountryOverrideName=null;
        updateOecRegionUI();
        jQuery('#oec-country-panel').prop('hidden',true); openOecRegionPanel('oec-region-panel');
    });
    $all.on('click','li',function(){
        // Siempre el código real del país (antes, fuera de los 16 fijos, iba "XX" → otros precios).
        const code=String(jQuery(this).attr('data-code')||'');
        if(!code) return;
        oecEnsureCountryOption(code);
        jQuery('#oec-countries_select').val(code); oecCountryOverrideName=null;
        updateOecRegionUI();
        jQuery('#oec-country-panel').prop('hidden',true); openOecRegionPanel('oec-region-panel');
    });
    $cur.on('click','li',function(){
        jQuery('#oec-currencies_select').val(jQuery(this).data('value'));
        updateOecRegionUI();
    });

    // Respaldo: clic fuera de #oec-reg_config (el trigger) y fuera de los paneles
    // (ahora viven en <body>, ya no dentro de #oec-reg_config) también cierra.
    jQuery(document).on('click',function(e){
        const $t=jQuery(e.target);
        if(!$t.closest('#oec-reg_config').length && !$t.closest('.oec-region-panel').length) closeAllOecRegionPanels();
    });

    updateOecRegionUI();
}

// ─── REVIEWS PÁGINAS 2+ ──────────────────────────────────────
function oec_get_reviews(where, tid, page){
    page=parseInt(page,10)||2;
    fetch(`${OEC_API.revFull}/${tid}/reviews?page=${page}`,{headers:{'Accept':'application/json'}})
    .then(r=>r.ok?r.json():{reviews:[],pagination:{total:0}})
    .then(data=>{
        const el=document.getElementById(where); if(!el||!data.reviews.length) return;
        const next=page+1;
        let html='<div class="oec-reviews-full-list">';
        data.reviews.forEach(rv=>{
            const px=Math.round(rv.rating*22*88/110);
            const img=rv.author.image.startsWith('http')?rv.author.image:`https://imgrsize.oe-img.center${rv.author.image}?w=80&q=70&format=webp`;
            const name=rv.author.prefix?`${rv.author.prefix} ${rv.author.first_name} ${rv.author.last_name}`:`${rv.author.first_name} ${rv.author.last_name}`;
            // Mismo criterio de tamaño según largo del comentario que en el
            // render server-side (Twig) — si se toca uno, tocar el otro.
            const len=(rv.comment||'').length;
            const rfSize = len<30?'oec-rf-text--xxl' : len<60?'oec-rf-text--xl' : len<150?'oec-rf-text--l' : len<300?'oec-rf-text--m' : 'oec-rf-text--s';
            html+=`<div class="oec-review-full"><img src="${img}" class="oec-rf-avatar" width="40" height="40" alt="${name}" loading="lazy"><div class="oec-rf-meta"><div class="oec-rf-name">${name}</div><div class="oec-rf-sprite"><div class="oec-rf-fill" style="width:${px}px"></div></div></div><div class="oec-rf-body"><div class="oec-rf-text ${rfSize}">${rv.comment}</div><div class="oec-rf-date">${new Date(rv.date).toLocaleDateString('es-AR')}</div></div></div>`;
        });
        if(data.pagination.total>page) html+=`<div id="oec-more_reviews_${next}"><a class="oec-mas-op" href="javascript:oec_get_reviews('oec-more_reviews_${next}','${tid}',${next})">Cargar más <i class="bi bi-chevron-down"></i></a></div>`;
        html+='</div>';
        el.innerHTML=html;
    }).catch(console.error);
}

// ─── CRÉDITOS ────────────────────────────────────────────────
// admin-ajax del SITIO ACTUAL (lo define PHP con admin_url()): una ruta fija "/wp-admin/..." en un
// multisite por subcarpeta (/es/) caía en el sitio raíz de la red, con OTRA configuración (token,
// equipo de ventas…); y en un WordPress instalado en una subcarpeta directamente no existe.
function oecAjaxUrl(){ return window.OEC_AJAX_URL || '/wp-admin/admin-ajax.php'; }

// Actualiza el ring 3 del hero (descuento) solo si pct supera lo que ya
// muestra — sirve tanto para revelarlo (arrancó oculto porque no había
// descuento "estático") como para reemplazar un % menor por uno mejor.
function updateDiscountRing(pct, label){
    const fill  = document.getElementById('oec-hero-ring-discount-fill');
    const score = document.getElementById('oec-hero-ring-discount-score');
    if(!fill || !score) return;
    const current = parseInt(score.textContent || '0', 10);
    if(pct <= current) return;
    const wrap = document.getElementById('oec-hero-ring-discount');
    const lbl  = document.getElementById('oec-hero-ring-discount-label');
    fill.style.setProperty('--oec-ring-offset', (213.6 * (1 - pct / 100)).toFixed(2) + 'px');
    score.textContent = pct + '%';
    if(lbl) lbl.textContent = label;
    if(wrap) wrap.style.display = '';
    fill.classList.add('is-visible');
}

function initCreditsBox(){
    const editionUid = OEC_TRAINING_DATA.edition_uid;
    if(!editionUid || !document.getElementById('oec-credits-box')) return;
    jQuery.post(oecAjaxUrl(), { action:'oec_list_coupons', edition_uid: editionUid, nonce: OEC_AJAX_NONCE })
        .done(res=>{
            if(!res.success || !res.data || !res.data.length) return; // sin cupones vigentes, no mostramos nada
            window.OEC_COUPONS = res.data;
            // res.data viene ordenado de mayor a menor % (ver get_valid_coupons en
            // class-oec-ajax.php) — si el mejor cupón supera al descuento "estático"
            // (pago anticipado/completo) que ya se pintó server-side, actualizamos
            // el ring 3 del hero con el nuevo % y lo revelamos si estaba oculto.
            updateDiscountRing(res.data[0].percentage, 'off canjeando créditos');
            renderCoupons(res.data);
            jQuery('#oec-credits-box').css('display','block');

            const sEmail=localStorage.getItem('userEmail'), sCredits=sessionStorage.getItem('userCredits');
            if(sEmail && sCredits){
                jQuery('#oec-current-email').text(sEmail); jQuery('#oec-current-my-points').text(sCredits);
                jQuery('#oec-email-change-section').show();
                showDiscountBtns(sCredits, sEmail);
            } else if(sEmail){
                jQuery('#oec-email-change-section').show();
                checkUserPoints(sEmail);
            } else {
                jQuery('#oec-credits-links').show();
            }
        });
}

function renderCoupons(list){
    const stdEl=document.querySelector('.std-disc-percent');
    const std=stdEl?parseInt(stdEl.textContent||'0',10):0;
    const html=list.map(c=>`
        <div class="oec-coupon" data-points="${c.points_value}" data-percent="${c.percentage}" data-beats-current="${c.percentage>std?1:0}">
            <div class="oec-coupon-pct">${c.percentage}%</div>
            <div class="oec-coupon-info">
                <p>Canjea <strong>${c.points_value}</strong> créditos por este ${c.percentage}% de descuento.</p>
                <div class="oec-coupon-action"></div>
            </div>
        </div>
    `).join('');
    jQuery('#oec-coupons-list').html(html);
}

function checkUserPoints(email){
    if(!email) return;
    jQuery.post(oecAjaxUrl(), { action:'oec_check_credits', email, nonce: OEC_AJAX_NONCE })
        .done(res=>{
            if(!res.success){ jQuery('#oec-credits-status').text(res.data||'Error al verificar créditos.'); return; }
            const balance=res.data.balance;
            localStorage.setItem('userEmail',email); sessionStorage.setItem('userCredits',balance);
            jQuery('#oec-current-email').text(email); jQuery('#oec-current-my-points').text(balance);
            jQuery('#oec-credits-email-form,#oec-credits-links').hide();
            jQuery('#oec-email-change-section').show();
            jQuery('#oec-credits-status').text('');
            showDiscountBtns(balance,email);
        })
        .fail(()=>jQuery('#oec-credits-status').text('Error al verificar créditos.'));
}

function showDiscountBtns(balance,email){
    balance=parseInt(balance,10);
    jQuery('#oec-coupons-list .oec-coupon').each(function(){
        const $c=jQuery(this), pts=parseInt($c.data('points'),10), pct=parseInt($c.data('percent'),10), beats=$c.data('beats-current')==1;
        const $action=$c.find('.oec-coupon-action');
        if(beats && balance>=pts){
            $action.html(`<a href="#" class="oec-redeem-btn" data-points="${pts}" data-percent="${pct}">Obtener descuento</a><p style="margin-top:4px">Confirmación por email a ${email}.</p>`);
        } else {
            $action.html(`<p>${balance>=pts?'Ya tienes un descuento igual o mejor activo.':'Créditos insuficientes.'}</p>`);
        }
    });
}

// ─── BARRA DE COMPARTIR (hero) ─────────────────────────────────
// Misma lógica que assets/js/share.js de oec-wp-theme (popup por red, copiar con feedback, "Más" =
// share nativo en mobile, evento "share" a dataLayer para GA4), con clases propias oec-share-* para
// no depender de ese tema ni engancharse dos veces si un tema trae su propia ".share-bar".
(function initShareBar(){
    document.querySelectorAll('.oec-share').forEach(function(bar){
        const url = bar.dataset.shareUrl || location.href.split('?')[0];
        const title = bar.dataset.shareTitle || document.title;
        const more = bar.querySelector('[data-share="more"]');
        if (more && navigator.share) more.hidden = false;

        function track(method){
            if (!window.dataLayer) return;
            window.dataLayer.push({ event: 'share', method: method, content_type: 'course', item_id: bar.dataset.itemId || '', item_name: title });
        }
        function popup(u){
            const w = 600, h = 520;
            window.open(u, 'oec-share', 'width=' + w + ',height=' + h + ',left=' + Math.max(0, (screen.width - w) / 2) + ',top=' + Math.max(0, (screen.height - h) / 2) + ',noopener,noreferrer');
        }
        function copied(btn){
            const old = btn.querySelector('.oec-share-tooltip'); if (old) old.remove();
            const tip = document.createElement('span');
            tip.className = 'oec-share-tooltip'; tip.setAttribute('role', 'status'); tip.textContent = 'Enlace copiado';
            btn.appendChild(tip); setTimeout(() => tip.remove(), 1800);
        }
        const u = encodeURIComponent(url), t = encodeURIComponent(title);
        bar.querySelectorAll('[data-share]').forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.preventDefault();
                const m = btn.dataset.share;
                track(m === 'copy' ? 'copy_link' : m);
                if (m === 'whatsapp') popup('https://wa.me/?text=' + encodeURIComponent(title + ' ' + url));
                else if (m === 'linkedin') popup('https://www.linkedin.com/sharing/share-offsite/?url=' + u);
                else if (m === 'x') popup('https://twitter.com/intent/tweet?url=' + u + '&text=' + t);
                else if (m === 'facebook') popup('https://www.facebook.com/sharer/sharer.php?u=' + u);
                else if (m === 'more' && navigator.share) navigator.share({ title: title, url: url }).catch(() => {});
                else if (m === 'copy') {
                    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(url).then(() => copied(btn)).catch(() => {});
                    else { const i = document.createElement('input'); i.value = url; i.style.position = 'fixed'; i.style.opacity = '0'; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); copied(btn); } catch (_e) {} i.remove(); }
                }
            });
        });
    });
})();

// ─── DOCUMENT READY ──────────────────────────────────────────
jQuery(document).ready(function($){
    const $doc=$( document), $win=$(window);

    // Bloque sticky de inscripción: la posición la resuelve el navegador solo
    // (position:sticky), acá solo decidimos CUÁNDO mostrarlo — recién cuando
    // ".oec-rc" (el bloque fijo original, con su propio botón) ya no se ve,
    // así nunca hay dos botones de "Iniciar inscripción" en pantalla a la vez.
    (function initStickyVisibility(){
        // ".oec-rc" a secas (no "#oec-r-column .oec-rc"): en mobile oec-frontend.js lo muda a la
        // columna izquierda, y si la página carga angosta y después se agranda, el selector con
        // "#oec-r-column" daba null y el sticky no se enganchaba nunca. En mobile el sticky ya
        // está oculto por CSS, así que observarlo ahí no molesta.
        const mainBox=document.querySelector('.oec-rc'), stickyBox=document.getElementById('oec-rc-sticky');
        if(!mainBox || !stickyBox || !('IntersectionObserver' in window)) return;
        const io=new IntersectionObserver(entries=>{
            stickyBox.classList.toggle('is-visible', !entries[0].isIntersecting);
        }, { root:null, threshold:0, rootMargin:'-70px 0px 0px 0px' }); // -70px ≈ alto del nav sticky
        io.observe(mainBox);
    })();

    // position:sticky deja de funcionar si CUALQUIER ancestro es un contenedor de scroll
    // (overflow hidden en algún eje) — muchos temas lo ponen en sus envoltorios
    // (#page-container de Divi, wrappers de Elementor/Avada…) para esconder desbordes. Se pasa a
    // "clip": recorta lo mismo pero no crea contenedor de scroll. Como "clip" no crea contexto de
    // formato de bloque (sí lo hacía "hidden": contiene floats y márgenes), a los que son
    // display:block se les da "flow-root" (= block + ese mismo contexto), así el layout del tema no
    // cambia. Solo se tocan los ancestros del bloque sticky, nada más del sitio.
    (function unblockStickyAncestors(){
        const sticky=document.getElementById('oec-rc-sticky');
        if(!sticky || !CSS.supports('overflow','clip')) return;
        for(let n=sticky.parentElement; n && n!==document.documentElement; n=n.parentElement){
            const cs=getComputedStyle(n);
            // Solo "hidden": un auto/scroll puede ser el que realmente scrollea la página (ahí el
            // sticky funciona igual, relativo a ese contenedor) y pasarlo a clip la dejaría sin scroll.
            const fix=v=>v==='hidden'?'clip':null;
            const x=fix(cs.overflowX), y=fix(cs.overflowY);
            if(!x && !y) continue;
            if(x) n.style.setProperty('overflow-x','clip','important');
            if(y) n.style.setProperty('overflow-y','clip','important');
            if(cs.display==='block') n.style.setProperty('display','flow-root');
        }
    })();

    // Ícono flotante de contacto (WhatsApp o Botmaker, nunca los dos a
    // la vez): movido a <body> por el mismo motivo que
    // #oec-region-backdrop/#oec-region-panel más abajo — inmune a
    // cualquier ancestro con transform/animación que rompa
    // position:fixed. Encontrado en vivo: en una formación con banner
    // "EN VIVO" (el marquee de sesiones, más abajo en este archivo),
    // el botón terminaba a miles de píxeles del viewport real, fuera
    // de vista — un ancestro sin clase quedaba con
    // transform:translateX(...) (no de este plugin; no se identificó
    // el origen exacto, pero da igual: moverlo a <body> lo deja
    // inmune a cualquier transform que aparezca en el árbol, venga de
    // donde venga).
    $('#oec-float-wa, #oec-float-botmaker').appendTo(document.body);

    // Bios de docentes: convierte listas de enlaces sociales en botones — no depende
    // de ningún fetch, corre apenas el DOM está listo
    // force_contact: el botón de la caja sticky ya es "SOLICITAR INFORMACIÓN" — el link "¿O necesitas
    // más información?" lleva al mismo lugar. Plantillas pegadas antes de 1.4.4 lo traen igual: se saca.
    if (OEC_CONFIG.force_contact) $('.oec-sticky-more').remove();
    oecSafe('initTeacherSocialLinks', initTeacherSocialLinks);
    oecSafe('initBotmakerChat', initBotmakerChat);
    oecSafe('initAddToCalendar', initAddToCalendar);

    // Formulario de contacto
    $doc.on('submit','.oecMoreInfoForm',function(e){
        e.preventDefault();
        const $f=$(this), $c=$f.closest('.oec-more-info-form'), $r=$c.find('.oec-form-response');
        $f.fadeOut(200,()=>{
            $r.text('Enviando...');
            $.post(oecAjaxUrl(),{action:'oec_more_info',nonce:OEC_AJAX_NONCE,uid:$c.data('uid'),country:$c.data('country'),locale:$c.data('locale'),name:$f.find('[name="name"]').val(),lastname:$f.find('[name="lastname"]').val(),phone:$f.find('[name="phone"]').val(),email:$f.find('[name="email"]').val()})
            .done(res=>{ $r.html(res.success?`<div class="oec-success"><i class="bi bi-check-square"></i> ${res.data}</div>`:`<div class="oec-error"><i class="bi bi-x-square"></i> ${res.data}</div>`); if(!res.success)$f.fadeIn(); })
            .fail(()=>{ $r.html('<div class="oec-error"><i class="bi bi-x-square"></i> Error de conexión.</div>'); $f.fadeIn(); });
        });
    });
    $doc.on('focusin focusout','.oecMoreInfoForm input',function(e){ $(this).closest('.oecMoreInfoForm').find('button').toggleClass('btn-active',e.type==='focusin'); });

    // Fecha local
    oecSafe('fecha local', () => {
        const tStart=new Date(OEC_TRAINING_DATA.start);
        $('.localdate').text(tStart.toLocaleDateString('es-ES',{day:'numeric',month:'long'}));
        if(OEC_TRAINING_DATA.type==='Webinar') $('.start').text(tStart.toLocaleString('en-US',{year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:true}).replace(',',''));
    });

    // Scroll links
    // Offset dinámico en vez de un número fijo: se mide el alto REAL del
    // sticky en el momento del click (ahora tiene 2 filas — nombre +
    // tabs — así que su alto ya no es una constante que convenga
    // hardcodear, y esto queda a salvo de que vuelva a cambiar más
    // adelante) + 24px de aire para que el título de la sección no quede
    // pegado contra la barra al llegar.
    // OEC_STICKY_SUSPEND=true congela el cálculo automático de "activo" en
    // oec-frontend.js — ese cálculo solo se reanuda ante un scroll real del
    // usuario (ver oec-frontend.js), nunca por un timer: liberarlo al
    // terminar la animación competía con el último scroll-event que ella
    // misma dispara, y el link recién clickeado podía terminar pisado.
    //
    // Scroll NATIVO (window.scrollTo con behavior:'smooth'), no
    // $.animate({scrollTop}) — descubierto al blindar el plugin contra
    // temas ajenos: si el tema activo le pone "scroll-behavior: smooth" a
    // <html> (como hace oec-wp-theme), el navegador intercepta CADA
    // asignación de scrollTop que hace jQuery en cada frame de su propia
    // animación e intenta suavizarla también — dos animaciones de scroll
    // peleándose entre sí, terminando en cualquier posición intermedia,
    // no en el destino real. El scroll nativo no tiene este problema
    // (es la misma API que "scroll-behavior: smooth" ya usa por debajo) y
    // funciona igual haya o no haya un tema seteando esa propiedad.
    $doc.on('click','.scroll-link',function(e){
        e.preventDefault();
        const $link = $(this);
        const id = $link.attr('where');

        const $t = $(`#${id}`);
        if (!$t.length) return;

        // "Metodología y horarios" vive colapsado dentro de un acordeón — con
        // solo el scroll no se entendía adónde había que mirar, así que el
        // ring del hero que apunta acá también lo despliega.
        if (id === 'oec-pierdealgo') {
            $t.addClass('is-active');
            $t.children('.oec-ac-btn').attr('aria-expanded', 'true');
        }

        $('.oec-sticky-links a').removeClass('active');
        $link.addClass('active');
        window.OEC_STICKY_SUSPEND = true;
        const stickyEl = document.getElementById('oec-menu-sticky');
        const stickyH  = (stickyEl && stickyEl.style.display !== 'none') ? stickyEl.offsetHeight : 0;
        // "- 24" antes — a pedido de Mario ("quedo muy encima del
        // contenido... necesitamos más aire"), se subió a "- 40" para que
        // el destino no quede pegado contra la barra sticky.
        // + el header fijo del tema / barra de admin de WP, si hay (lo mide oec-frontend.js).
        const topOffset = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--oec-top-offset')) || 0;
        window.scrollTo({ top: Math.max(0, $t.offset().top - topOffset - stickyH - 40), behavior: 'smooth' });
    });

    // Responsive — no duplicar, usar CSS order en mobile
    // La columna derecha se oculta en mobile con CSS y se muestra la mobile bar

    // ─── STICKY NAV — manejado por oec-frontend.js (evita escape de && por WordPress) ───

    // Sesiones en vivo del auditorio — corre siempre, no depende de si la
    // inscripción está abierta (alumnos ya inscriptos también ven esta página)
    // La API espera el UID de la EDICIÓN (no del training) — por eso edition_uid, no id
    oecSafe('initLiveSessionsMarquee', () => { const p = initLiveSessionsMarquee(OEC_TRAINING_DATA.edition_uid); if (p && p.catch) p.catch(err => console.error('[OEC] initLiveSessionsMarquee', err)); });

    if(OEC_CONFIG.openEnrollment){
        // Meta Pixel
        if(window.fbq) fbq('track','ViewContent',{content_name:OEC_TRAINING_DATA.title,content_ids:[OEC_TRAINING_DATA.id],content_type:'product'});

        // Countdowns (.oec-countdown: fecha de inicio/cierre de inscripción y descuento por pago anticipado)
        const today=new Date(), todayN=today.getFullYear()*10000+(today.getMonth()+1)*100+today.getDate();
        // enrollment_end ya llega como el final del día de cierre en la hora
        // del sitio (normalize_dates() del plugin): se compara el instante.
        const enrEnd=new Date(OEC_TRAINING_DATA.enrollment_end);
        const earlyExp=OEC_TRAINING_DATA.prices.discounts.early_payment.expiration;
        if(enrEnd>=today||(earlyExp&&new Date(earlyExp)>=today)){
            updateCountdowns();
            setInterval(updateCountdowns,1000);
        }

        // Cambio regional
        $('#oec-regional_change').on('click keypress',function(e){ if(e.type==='click'||e.key==='Enter'){ e.preventDefault(); const base=$('#oec-reg_config').attr('base_url'); window.location.href=`${base}?country=${$('#oec-countries_select').val()}&currency=${$('#oec-currencies_select').val()}`; } });
        oecSafe('initOecRegionSelector', initOecRegionSelector);
        oecSafe('setCountryCurrency', setCountryCurrency);

        // Ocultar descuento vencido
        const $disc=$('#oec-disc-early-payment'), dAttr=$disc.attr('date');
        if(dAttr&&todayN>parseInt(dAttr,10)) $disc.hide();

        // Affiliate code
        const aff=qsp('affcod')||getExp('AffiliateCode');
        if(aff){ setExp('AffiliateCode',aff,90); $('a.oec-btn-enroll,a.oec-mb-enroll,a.oec-sticky-enroll,a.oec-hero-cta-btn').each(function(){ const h=$(this).attr('href')||''; if(h) $(this).attr('href',h+(h.includes('?')?'&':'?')+'affcod='+encodeURIComponent(aff)); }); }

        // Créditos
        oecSafe('initCreditsBox', initCreditsBox);

        $doc
            .on('click','#oec-check-points-link',e=>{ e.preventDefault(); $('#oec-credits-links').hide(); $('#oec-credits-email-form').show(); })
            .on('submit','#oec-credits-email-form',e=>{ e.preventDefault(); const em=$('#oec-user-email').val().trim(); if(em) checkUserPoints(em); })
            .on('click','#oec-change-email',e=>{
                e.preventDefault();
                localStorage.removeItem('userEmail'); sessionStorage.removeItem('userCredits');
                $('#oec-email-change-section,#oec-how-get-credits').hide();
                $('#oec-coupons-list .oec-coupon-action').empty();
                $('#oec-credits-links').show();
            })
            .on('click','.oec-how-points-link',e=>{ e.preventDefault(); $('#oec-how-get-credits').show(); })
            .on('click','.oec-redeem-btn',function(e){
                e.preventDefault();
                const $btn=$(this), email=localStorage.getItem('userEmail');
                if(!email) return;
                // Guardamos el contenedor ANTES de vaciarlo — una vez que
                // .html() reemplaza su contenido, $btn queda huérfano (sin
                // padre en el documento) y $btn.closest(...) ya no encuentra
                // nada, dejando el "Procesando…" pegado para siempre aunque
                // el servidor sí haya respondido.
                const $action = $btn.closest('.oec-coupon-action');
                const payload={
                    action:'oec_request_redeem', email, nonce: OEC_AJAX_NONCE,
                    training_uid: OEC_TRAINING_DATA.id,
                    edition_uid:  OEC_TRAINING_DATA.edition_uid,
                    points_amount: $btn.data('points'),
                    training_name: OEC_TRAINING_DATA.name
                };
                $action.html('<p>Procesando…</p>');
                $.post(oecAjaxUrl(), payload)
                    .done(res=>$action.html(`<p>${res.success?res.data:('<span class="oec-error">'+res.data+'</span>')}</p>`))
                    .fail(()=>$action.html('<p class="oec-error">Error. Intenta de nuevo.</p>'));
            });
    }

    // Exportar globales — prefijadas "oec_" a propósito: sin el prefijo,
    // nombres genéricos como "metaAddToCart" pueden pisar (o ser pisados
    // por) una función del mismo nombre definida por el tema activo, otro
    // plugin, o un snippet de tracking de terceros (Meta Pixel, GTM) en
    // cualquier sitio donde se instale — no es hipotético, es la misma
    // clase de colisión que ya rompió el CSS de la ficha con el tema nuevo.
    window.oec_get_reviews   = oec_get_reviews;
    window.oec_metaAddToCart = oec_metaAddToCart;
});

} // fin oecStart
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', oecStart, { once: true });
else oecStart();
})();
