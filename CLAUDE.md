# OEC WordPress Plugin — contexto del proyecto

Este archivo lo lee Claude Code automáticamente al trabajar en esta carpeta.
Resume las decisiones y convenciones que se establecieron trabajando este
plugin, para que el trabajo continúe entre sesiones sin perder contexto.

## Qué es esto

Plugin de WordPress (`oec-wordpress-plugin`) que expone shortcodes propios
para Online Education Center / G-SE:

- `[oec-content]` — la ficha completa de una formación (hero, columna
  izquierda de contenido, columna derecha de precios/inscripción, sticky
  nav de secciones).
- `[oec-list]` — unas pocas formaciones (hasta 24) en grilla o en tira
  horizontal, en una sola línea de shortcode (ver sección propia más abajo).

Todo lo demás (listado completo con filtros, landings de temática, docentes,
opiniones…) lo hace el tema `oec-wp-theme`, que SIEMPRE tiene el plugin
instalado. El plugin, en cambio, se instala en sitios con cualquier tema
(Divi, Hello/Elementor, Avada…), así que no puede asumir nada del tema.

La ficha es un **componente portátil**: el mismo HTML+CSS+Twig+JS
se pega dentro de un bloque de "HTML personalizado"/Código en el editor de
WordPress de cada sitio donde se usa (son varios — algunos son comunidades
propias de OEC, otros son sitios de socios que no administramos, ver más
abajo). **Nunca pegar el contenido Twig crudo en el editor visual/de texto
enriquecido** — WordPress (wpautop / el parser HTML del editor) lo
destroza, convirtiendo `{% if x %}` en atributos HTML falsos. Si algo se ve
con errores raros de Twig después de subir un archivo, sospechar primero de
esto antes de asumir que el código está mal.

Sitio de desarrollo local (Local by Flywheel):
`/Users/marioagustinmoyano/Local Sites/oec-test/app/public/wp-content/plugins/oec-wordpress-plugin`
Se prueba en `http://oec-test.local/formacion/...` y
`http://oec-test.local/formaciones`.

## Archivos clave

**Aprendizaje de proceso — editar el archivo NO alcanza, hay que
sincronizarlo a la base de datos**: WordPress sirve lo que está en
`wp_posts.post_content`, no el archivo `-testing.html` en sí — el archivo
es la copia de trabajo, pero un cambio ahí es invisible en el sitio hasta
que se pega a mano en el editor de WordPress (proceso normal de Mario) O
se sincroniza por script. Pasó en esta sesión: se hizo todo un blindaje de
IDs + JS sobre `data/page-formacion-testing.html` y se dio por terminado
sin repegar/sincronizar — quedó probado solo "en el archivo", no en el
sitio real, hasta que un pedido de verificación en vivo (agregar el
nombre de la formación a la sticky nav) lo dejó en evidencia: el HTML que
devolvía `oec-test.local` todavía tenía los ids VIEJOS sin prefijar. Se
sincronizó con un script chico de `mysqli` directo a `wp_posts` (mismo
patrón que ya existía en el scratchpad de una sesión anterior,
`update_page.php`) — revisar SIEMPRE, antes de dar por resuelto un cambio
sobre `-testing.html`, si hace falta repetir este paso (`check_live_content.php`,
también en scratchpad de sesiones previas, sirve para comparar el largo
del contenido en la base contra el archivo local antes de pisarlo, por si
Mario hizo un cambio manual mientras tanto).

**Ficha de formación (`[oec-content]`):**
- `data/page-formacion-testing.html` — **copia de trabajo activa** de la
  plantilla Twig. Se edita y se prueba en local (pegada a mano en la
  página "Formación" de WordPress, ID 65). Ya NO tiene todo el CSS/JS
  inline — ver externalización más abajo. Lo único que queda inline es lo
  que depende de datos de la formación puntual: la isla de datos
  `window.OEC_TRAINING_DATA`/`OEC_CONFIG`/`OEC_AJAX_NONCE`, y un puñado de
  variables CSS (`--oec-brand`, `--oec-dominant`, etc.) en un `:root` chico.
- `data/page-formacion.txt` — la plantilla "de producción", **usada como
  guía al instalar el plugin en un sitio nuevo**. Se sincronizó por
  última vez con `page-formacion-testing.html` el 2026-09-17 (copia
  byte a byte, a pedido explícito de Mario — antes de eso llevaba varias
  sesiones atrasada). A partir de ahora, cada vez que se dé por
  terminado un cambio real en `page-formacion-testing.html`, hay que
  volver a copiarlo acá (`cp page-formacion-testing.html
  page-formacion.txt`) — no asumir que Mario lo va a portar a mano como
  antes, eso ya no es el flujo vigente. Si en algún momento Mario vuelve
  a pedir "no tocar este archivo", retomar el criterio viejo.

**Listado de formaciones (`[oec-list]`):**
- Sin plantilla pegada: el HTML lo arma `class-oec-shortcodes.php`
  (`list_html()`/`card_html()`); estilos y JS en `css/oec-formaciones.css` /
  `js/oec-formaciones.js` (también los usa el tema para sus tarjetas).
- `data/page-formaciones.txt` — contenido con el que el plugin crea la página
  "Formaciones" al instalarse en un sitio nuevo (una línea de `[oec-list]`).
  Ya no hay `page-formaciones-testing.html` ni la landing de nutrición
  deportiva de prueba (borradas el 2026-09-26: usaban lo eliminado).

**CSS/JS externalizados (ficha de formación):**
- `css/oec-formacion.css` — todo el CSS estático de la ficha (no tiene
  nada de Twig — el color de marca se resuelve con variables CSS
  `var(--oec-brand)` etc., definidas en el `:root` inline mencionado
  arriba).
- `js/oec-formacion.js` — toda la lógica JS de la ficha que no depende de
  Twig (precios, countdown, acordeones, carrusel, selector de región,
  créditos, etc. — ~800 líneas). Lee `window.OEC_TRAINING_DATA`/`OEC_CONFIG`
  que el `<script>` inline del Twig define ANTES (mismo truco de orden que
  ya usaba `oec-frontend.js`: el bloque inline corre síncrono en el body,
  los archivos encolados con `footer=true` corren después).
- Ambos se encolan en `class-oec-shortcodes.php` **solo** en
  `is_page('formacion')`, con la versión calculada vía `filemtime()` (no
  un número fijo tipo "1.0") — así cada edición del archivo invalida sola
  el caché del navegador sin tener que acordarse de bumpear nada a mano.
- `js/oec-frontend.js` — **MUY RECORTADO A PROPÓSITO**. Solo tiene el
  toggle del sticky nav al hacer scroll y el drag del carrusel de
  reviews/mini-bullets. Todo lo demás se sacó de acá porque duplicaba —y
  pisaba, por orden de carga (`defer` + `in_footer`, corre después)— la
  misma lógica que ya vive en `js/oec-formacion.js`. **No volver a agregar
  nada de eso acá.**
- `jQuery` (WordPress core) solo se encola en `is_page('formacion')` — es
  el único lugar del plugin que lo usa (`js/oec-formacion.js`). No cargarlo
  sitio-wide de nuevo.

**PHP:**
- `oec-main.php` — bootstrap del plugin. Acá viven `OEC_CREDITS_ALLOWED_DOMAINS`
  y `OEC_CREDITS_API_KEY_DEFAULT` (ver "Sistema de créditos" abajo), la
  metadata SEO (`wp_head`), los JSON-LD de `BreadcrumbList`/`Organization`/
  `WebSite`, el filtro de `robots_txt` y el endpoint virtual de `llms.txt`
  (ver "SEO / AEO" abajo), y el sistema de debug de performance
  (`?oec_debug=1`).
- `includes/class-oec-shortcodes.php` — registra `[oec-content]` (arma el
  `$extra`/`$data` que recibe su Twig, calcula `dominant_color_css`) y
  `[oec-list]` (HTML propio en PHP), y encola el CSS/JS de cada uno.
- `includes/class-oec-api.php` — wrapper de la API con caché vía
  `wp_options` (`OEC_Api::call()`, cachea 1h para listados/varios, 24h para
  una formación puntual por ID). `OEC_Api::call_paginated()` es la que usa
  `[oec-list]` (6h), y `fetch_many()` pide varios listados en paralelo.
- `includes/class-oec-ajax.php` — manejadores `wp_ajax_*` para todo lo que
  necesita tocar una API key del lado del servidor (formulario de "más
  información", sistema de créditos/cupones). Todos los endpoints públicos
  de este archivo requieren nonce (`check_nonce()`).
- `includes/class-oec-admin.php` — página de ajustes del plugin en
  wp-admin (token, color de marca, API key de créditos, URL de página de
  confirmación de canje, botón de "Actualizar caché").

## `[oec-list]` — vidriera simple de pocas formaciones (rehecho 2026-09-26)

Decisión de Mario (2026-09-26): el plugin hace solo dos cosas — la ficha (`[oec-content]`) y
listados CHICOS de formaciones. Todo lo demás (listado completo con filtros, landings de temática,
docentes, opiniones, videos, blog) lo arma el tema `oec-wp-theme` con sus propios shortcodes
(`[oec-tira]`, `[oec-docentes]`, `[oec-opiniones]`, `[oec-cierres]`…). Se eliminaron del plugin:
`filters="yes"` (sidebar, combo de orden, `?oec_*`), el paginador (`?oec_pg`), el SEO propio del
listado (`oec_list_seo_metadata()`/`oec_list_robots()`/`ItemList`), y los shortcodes
`[oec-teachers]`, `[oec-reviews]`, `[oec-video]`, `[oec-articles]` y `[oec-url]` (este último se
MUDÓ al tema, `inc/urls.php`, porque lo usan sus landings). La versión anterior completa quedó en
el historial (y en el backup `plugin-backup-antes.tgz` del scratchpad de esa sesión).

**Uso — una sola línea, sin cuerpo Twig:**
```
[oec-list layout="grid" limit="6" subject-id="nutricion-deportiva" enrollment="opened"]
[oec-list layout="scroll" limit="10" order="enrollment_end" title="Últimos días para inscribirte" more-url="/formaciones" countdown="yes"]
```
- `layout`: `grid` (todas visibles, default) o `scroll` (tira horizontal con flechas; en mobile
  sin flechas, con el "peek" de la tarjeta siguiente).
- `limit`: 1 a 24 (`OEC_Shortcodes::LIST_MAX`); default 6 en grid, 10 en scroll.
  `trainings-per-page` (el nombre viejo) se sigue aceptando como alias.
- `title` (`<h2 class="oec-list-title">`), `more-url` (grid: botón "Ver todas las formaciones";
  scroll: anillo punteado con flecha al final). `more-url` pasa por `resolve_site_url()`
  (una ruta `/x` se ancla al subsitio del multisite, sin duplicar `/es`).
- `countdown="yes"`: cuenta regresiva del cierre en cada tarjeta abierta y no asincrónica; el JS
  la muestra recién con ≤15 días.
- Filtros → API, sin validar valores (`LIST_FILTER_MAP`): `type`, `enrollment`, `subject-id`
  (varias por coma = AND), `from-date`/`to-date` (fecha o `this-month`/`next-month`/
  `month-after-next`, `resolve_relative_dates()`), `search`, `order`, `synchronicity`, `modality`.
- Sin resultados: la tira no imprime nada; la grilla muestra "No hay formaciones disponibles…".

**Por qué el markup pasó a PHP (`list_html()`/`card_html()` en `class-oec-shortcodes.php`)**:
antes cada sitio pegaba ~300 líneas de Twig dentro del shortcode — frágil (Gutenberg/wpautop lo
rompían), cada sitio con su propia copia desactualizada de la tarjeta, y sin forma de blindarlo.
Ahora hay una sola tarjeta, todo el texto de la API sale como texto plano escapado (`plain()` +
`esc_html`/`esc_attr` — el Twig viejo imprimía sin escapar, `autoescape=false`). Un
`[oec-list]…Twig…[/oec-list]` viejo sigue funcionando: el cuerpo se ignora y sale la tarjeta nueva
(`filters`/`pagination` se ignoran). Por eso `oec_post_uses_shortcodes()` ahora solo mira
`[oec-content]` (editor clásico forzado + sin wpautop solo donde hay Twig de verdad; forzarlo para
`[oec-list]` le cambiaba el editor a cualquier página de un socio). Mismas clases de tarjeta que
`oec_formacion_card()` del tema (`inc/tiras.php`). Copy: "Comienza cuando quieras" (antes
"Comience cuando quiera", usted).

**Blindaje**: la salida va envuelta en `<div class="oec-scope" style="--oec-brand:#…">` (`scope()`)
— el color de marca va en línea en el propio elemento, no depende de un `<style>` en el head. La
capa de aislamiento de `css/oec-formaciones.css` cuelga de `.oec-scope`. Grilla con
`minmax(min(330px, 100%), 1fr)` (con 330px fijos desbordaba en celulares angostos). Verificado con
`tests/hostile-audit.js` (grid + scroll, 1366px y 375px, tira scrolleada): **0 diferencias de
estilo y 0 de tamaño** en 565 elementos — ojo al repetirlo: sin fijar el ancho de `.oec-scope`
salen cientos de "sizeDiffs" porque el CSS hostil cambia el ancho del contenedor DEL TEMA (347→371px
en mobile) y las tarjetas se adaptan; eso es externo al plugin. Fijar el ancho durante la auditoría
(`.oec-scope{width:Npx!important}`) para medir solo lo del plugin.

**CSS/JS** (`css/oec-formaciones.css` ~165 líneas, `js/oec-formaciones.js` ~70): capa de
aislamiento, contenedor, tira, countdown, grilla y tarjeta. JS: flechas de las tiras + countdown,
sin dependencias. Se encolan con `has_shortcode()` en `wp_enqueue_scripts` y, como red para page
builders (Elementor/Divi guardan el shortcode fuera de `post_content`), también desde `scope()` al
renderizar. **`oec-wp-theme` usa estos dos archivos para SUS tarjetas y tiras** (sin `.oec-scope`):
no renombrar `.oec-card`, `.fecha-*`, `.oec-scroll-*`, `.oec-countdown`/`.oec-cd-*`,
`.oec-empty-title` sin tocar el tema. El paginador (`.oec-pager-*`) ya NO está en el plugin: el
tema tiene sus propios estilos completos (verificado en `/es/formaciones/`, igual que antes).
`.oec-scope .oec-card:hover`/`.oec-badge-item`/`.oec-scroll-arrow:hover` llevan un selector más
específico para que el tema (que los pinta con su acento) no le gane al color de marca del plugin.

**Varios `[oec-list]` en una página**: `prefetch_page()` (hook `template_redirect`) lee el
`post_content`, calcula el endpoint de cada uno con el MISMO `prepare_list()` y los pide en paralelo
(`OEC_Api::fetch_many()`, solo si son 2 o más). Medido: 5 listados → un lote de ~2,1s en frío.

**Gotcha de WordPress — no mezclar la forma vieja con la nueva en una misma página**: si una página
tiene un `[oec-list …]` sin cerrar y MÁS ABAJO un `[oec-list]…[/oec-list]` (forma vieja), el regex
de shortcodes de WordPress toma el primero como si abriera hasta ese `[/oec-list]` y se "come" todo
lo del medio (pasó probando: solo se renderizó la primera tira). Con todos sin cerrar (lo normal
ahora) no pasa. Si hace falta convivir, cerrar el nuevo como `[oec-list … /]`.

**Performance — `inc-reviews=1`**: sigue siendo lo más caro de cada listado (la API tarda 2-3x más
con ese flag: ~830ms sin, ~2000-2200ms con 12 resultados; el tamaño de respuesta casi no cambia —
es procesamiento del lado de la API). Hace falta para las estrellas. `call_paginated()` cachea 6h.
En local el caché está SIEMPRE apagado (`OEC_CACHE_ENABLED = OEC_ENV === 'production'`), así que
cada recarga paga el costo completo — no alarmarse. Alternativas (pedir a la API que lo optimice, o
resumir reviews por afuera) quedan a criterio de Mario.

**Aprendizajes de la versión vieja que siguen valiendo**:
- Ancho relativo en mobile: `calc(100% - Xpx)`, nunca `vw` (el `vw` ignora el padding del tema; con
  82vw no había "peek" en un celular real).
- `overflow-x: auto` fuerza `overflow-y` a `auto`: el track de la tira necesita padding vertical
  para que el hover (`translateY(-8px)` + sombra) no se recorte.
- Nunca escribir `{% … %}` ni la sintaxis real de un shortcode dentro de un comentario (Twig y
  `do_shortcode()` los interpretan igual).

## SEO / AEO

Ya implementado (`oec-main.php`):
- Meta tags clásicos (title, description, canonical, Open Graph,
  `twitter:title`/`description`, `og:locale`) vía `oec_seo_and_stars_metadata()`.
- JSON-LD `Course` (desde v1.4.2 en PHP, `oec_course_jsonld()` — ver su sección) + `FAQPage` (en el propio Twig de la ficha),
  `BreadcrumbList` y `Organization`/`WebSite` (en `oec-main.php`, todas las
  páginas). El `Course` declara `offers` con `availability: OutOfStock`
  cuando la formación tiene inscripción cerrada, en vez de omitir `offers`
  directamente — así Google entiende "temporalmente sin cupo", no "nunca
  hay oferta".
- `robots.txt`: filtro `robots_txt` que agrega `Allow: /` explícito para
  GPTBot, ClaudeBot, PerplexityBot, Google-Extended, etc. — funciona sin
  archivo físico, en cualquier sitio donde se instale el plugin.
- `llms.txt`: endpoint virtual (rewrite rule + `template_redirect`) en la
  raíz del sitio, con descripción básica + link al listado de formaciones.

## Formación con inscripción cerrada — comportamiento especial

Cuando `not openEnrollment` (Twig, en la ficha de formación):
- Se ocultan: la fila de tips del hero, los 4 anillos del hero, el sticky
  de inscripción de la columna derecha (`.oec-rc-sticky`, se saca del DOM
  entero, no solo con CSS), y la mobile bar fija de abajo
  (`#oec-mobile-bar`, ídem). Motivo: son elementos de "urgencia para
  inscribirte ya", sin sentido con el cupo cerrado.
- Se **mantienen** intactos: docentes, presentación, contenidos, "¿Por qué
  elegir esta formación?", "Más información", reviews — es el contenido
  indexable/citable por buscadores y agentes de IA, y además es la fuente
  del `FAQPage` JSON-LD (ocultarlo generaría structured data sin contenido
  visible correspondiente).
- La columna derecha muestra hasta 10 "formaciones alternativas" con
  inscripción abierta y misma temática (`data.wpgroup`), vía
  `OEC_Shortcodes::get_similar_open_trainings()` — el subject-id real se
  extrae salteando tokens de `wpgroup` que tengan un dígito (los tags de
  campaña tipo `mundial2026`, `hot-gse2026` siempre traen año/número
  pegado; la temática real nunca).
- La sección de contacto pasa a "Avísame cuando abra inscripciones" con
  formulario de espera (nombre/apellido/email) — la foto/nombre de la
  Customer Success Manager se muestra igual en ambos casos (antes solo se
  mostraba con inscripción abierta).
- CSS: cuando no hay mobile bar, `.oec-cols--no-mobile-bar` y
  `.oec-float-wa--no-mobile-bar` (clases condicionales del Twig) le sacan
  el padding/posición extra que esos elementos necesitaban para no tapar
  contenido — si no, queda un hueco vacío abajo en mobile.

## Convenciones establecidas

- **Nunca exponer API keys sensibles en el JS del navegador.** Todo lo que
  pueda restar créditos, mandar emails, o pegarle a una API con clave
  privada pasa por un endpoint `wp_ajax_*` en `class-oec-ajax.php`.
- **Endpoints AJAX públicos**: todos requieren nonce (`check_nonce()`,
  acción `oec_ajax`, generado en `class-oec-shortcodes.php` y expuesto al
  JS como `OEC_AJAX_NONCE`). El que manda un email real
  (`oec_request_redeem`) además tiene throttle de 2 min por email
  (`throttle()`) para que no se pueda usar para bombardear de emails a
  otra persona. Esto frena bots/scrapers genéricos, no a un atacante
  dirigido que primero cargue la página real para sacar un nonce válido —
  es la protección estándar de WordPress para este caso.
- **Colores del checkout externo** (`--checkout-blue` / `--checkout-blue-hover`
  en `:root`, usados por el botón "Iniciar inscripción"): **no es
  responsabilidad nuestra** — el checkout lo desarrolla otro equipo. Los
  hex actuales son un placeholder y así se quedan; no hay que perseguir
  esto como pendiente.
- **Tuteo formal en todo el copy visible** ("puedes", "necesitas",
  "Canjea"...) — nunca voseo ("podés", "necesitás") ni "usted". Barrido
  completo hecho sobre el Twig y todo el PHP (`class-oec-ajax.php`,
  `class-oec-admin.php`); si se agrega texto nuevo de cara al usuario,
  mantener el mismo criterio.
- El selector de país/moneda (`#reg_config`) mueve sus paneles a
  `document.body` por JS al iniciar, para escapar de cualquier ancestro
  con `transform` que rompa `position:fixed`. Aprendizaje caro: un
  elemento con el atributo `hidden` deja de esconderse si alguna regla
  CSS le pone `display: flex/grid/block` — siempre agregar
  `.selector[hidden] { display: none !important; }` como salvavidas
  cuando se combine `hidden` con `display` propio. El sticky nav
  (`#menu-sticky`) se mueve al `<body>` por el mismo motivo (headers
  "sticky" del tema que se achican al hacer scroll son la causa típica de
  un ancestro con `transform`). Si en el
  futuro aparece OTRO elemento `position:fixed` en el plugin que se vea
  con bordes raros o cortado, sospechar primero de esto antes de tocar
  sus valores de `top`/`left`/`inset`.
- El bloque sticky de la columna derecha (`.oec-rc-sticky`) usa
  `position: sticky` puro (sin JS de scroll para la posición) — la
  columna derecha necesita `align-items: stretch` en `.oec-cols` para
  darle alto de sobra donde "deslizarse". Un `IntersectionObserver` chico
  solo decide *cuándo* mostrarlo (recién cuando `.oec-rc`, el bloque fijo
  original, ya no se ve), para no mostrar dos botones de inscripción a la
  vez.
- **Link de matriculación (`enrollmentLink`)**: NUNCA se arma a mano
  (`https://{subdomain}.onlineeducation.center/es/checkoutv2/enrollment/{id}`
  era la versión vieja, incorrecta) — hay que usar los campos que ya trae
  `data` desde la API de formación: `data.register_url` si estamos viendo
  la formación en el sitio de su propia comunidad (`data.community in
  extra.current_url`), o `data.register` si no. Mismo criterio en
  `class-oec-ajax.php` (`oec_confirm_redeem_shortcode`) para el link de
  inscripción que se manda en el email/página de confirmación de canje —
  ahí sí hace falta pedirle el training completo a `OEC_Api::call()`
  porque el transient del canje solo guarda id/nombre, no el resto de
  `data`.
- **Sticky nav de secciones** (tabs "Inicio / Fechas y precios /
  Presentación / Docentes / Contenidos / Opiniones / Por qué elegir / Más
  información / Contáctanos"): al hacer click, el link se marca activo al
  instante y se levanta `window.OEC_STICKY_SUSPEND = true` — el detector
  automático de sección activa (en `oec-frontend.js`) se ignora mientras
  esa bandera esté en `true`. **Importante**: esa bandera se libera
  SOLO ante un gesto real de scroll del usuario (`wheel`/`touchmove`/tecla
  de scroll en `oec-frontend.js`), nunca por un timer ligado a la
  animación del click — liberarla desde el callback `complete` de
  `.animate()` compite con el último scroll-event que esa misma animación
  dispara, y podía terminar pisando el tab recién clickeado (más
  probable con secciones cortas y pegadas, como "Contenidos" con un solo
  módulo). Presentación/Docentes cambian de orden en el DOM según los
  datos (`docentes_primero`) — por eso el cálculo de sección activa elige
  la de mayor `offsetTop` por debajo del scroll actual, no la última de un
  array en orden fijo.
- **Nombre de la formación en la sticky nav**: `.oec-sticky-inner` pasó de
  una sola fila (los tabs) a `flex-direction: column` con dos filas —
  arriba `<a class="oec-sticky-title scroll-link" where="oec-hero">{{ data.name }}</a>`
  (el nombre completo de la formación, truncado con `text-overflow:
  ellipsis` si no entra en una línea), abajo la fila de tabs de siempre.
  Reutiliza el MISMO mecanismo genérico de click de `.scroll-link`
  (`oec-formacion.js`) que ya usan los tabs y los rings del hero — no hizo
  falta JS nuevo. Apunta a `where="oec-hero"` (no a `where="oec-top"`,
  que es el wrapper `.oec-page` que empieza DESPUÉS del hero) a propósito:
  así el click lleva al usuario al principio de TODO (arriba del hero),
  no a mitad del hero.
  - El primer tab de `.oec-sticky-links` lleva `padding-left: 0
    !important` (`:first-child`) para que su texto arranque exactamente
    debajo de la primera letra del nombre de la formación — sin esto,
    el padding horizontal normal de los tabs (necesario para separarlos
    entre sí) lo corría 14px a la derecha respecto del título de arriba.
  - Alto de los tabs bajado de `padding: 14px` a `padding: 9px` (más
    compacto, a pedido, ahora que la barra ya tiene dos filas y no hace
    falta que cada una sea tan alta).
  - **El offset de scroll pasó de un número fijo (100px) a uno calculado
    en el momento del click** (`stickyEl.offsetHeight + 24`) — con dos
    filas el alto de la barra ya no es una constante que convenga
    hardcodear, y así queda a salvo de que vuelva a cambiar de nuevo.
  - **Bug real encontrado y arreglado — el click en un tab casi no
    scrolleaba (o scrolleaba a un lugar random) con el tema `oec-wp-theme`
    puesto**: la causa es que ese tema le agrega `scroll-behavior: smooth`
    a `<html>` (`style.css`, sección RESET) — con eso activo, el navegador
    intenta "suavizar" código él mismo CADA asignación de `scrollTop` que
    hacía `$('html,body').animate({scrollTop:...})` de jQuery en cada
    frame de SU PROPIA animación manual — dos animaciones de scroll
    peleando entre sí, el resultado final quedaba en cualquier posición
    intermedia en vez del destino real. Se reemplazó por scroll nativo
    (`window.scrollTo({top, behavior:'smooth'})`), que es la misma API
    que usa `scroll-behavior: smooth` por debajo — no compite consigo
    misma, y funciona igual de bien exista o no esa propiedad CSS en el
    tema. **Antes de volver a usar `$.animate({scrollTop:...})` en
    cualquier parte de este plugin, tener en cuenta este conflicto.**
  - `.oec-rc-sticky` (el sticky de precios/inscripción de la columna
    derecha) usaba `top: 76px` a mano, calculado para el alto de la barra
    de UNA sola fila — con la segunda fila ya no alcanzaba. Se resolvió
    con una variable CSS medida en JS en vez de otro número fijo:
    `oec-frontend.js` mide el alto REAL de `#oec-menu-sticky` (oculto,
    con `visibility:hidden` un instante, para no hacerlo parpadear) apenas
    lo mueve a `<body>`, y lo expone como `--oec-sticky-h` en `:root`;
    `.oec-rc-sticky { top: calc(var(--oec-sticky-h, 76px) + 8px); }` en el
    CSS. Si la barra sticky vuelve a cambiar de alto en el futuro (más
    texto, otra tipografía), este valor se recalcula solo — no hace falta
    volver a medir a mano.
  - **Gap real encontrado en el blindaje de la sesión anterior**: el
    subrayado del tab activo (`.oec-sticky-links a.active { border-bottom-color:
    var(--oec-brand); }`) había desaparecido — la pasada de `!important`
    de la sesión anterior blindó el shorthand `border-bottom` de la fila
    base, pero el ALLOWLIST de propiedades a blindar no incluía las
    variantes "longhand" de un solo lado (`border-bottom-color`,
    `border-top-color`, etc.) — con el shorthand de la base ya
    `!important` y el override puntual del estado activo SIN
    `!important`, el shorthand le ganaba al override sin importar la
    especificidad (una declaración `!important` le gana a cualquier no-
    `!important`, siempre, sea cual sea su especificidad). Se revisó TODO
    el plugin buscando el mismo patrón (`border-*-color/width/style` sin
    `!important` conviviendo con un `border`/`border-*` shorthand con
    `!important` en una regla más general) — este era el único caso. Si
    se agrega un override de estado (`.algo.active`, `.algo:hover`, etc.)
    sobre una propiedad longhand de borde en el futuro, acordarse de
    blindarlo con `!important` también, no solo el shorthand de la base.
- **Bug real (preexistente, no de esta sesión) en `.oec-collapsible`**:
  el bloque "Opiniones" mostraba "Cargar más opiniones" ya visible
  mientras la lista de opiniones seguía colapsada (con el "+" todavía
  ahí) — quedaba un salto de espacio raro y un link sin contexto entre
  medio. Causa: el manejador de `.oec-collapsible` (`oec-formacion.js`,
  "COLLAPSIBLE (presentación, reviews)") corre GENÉRICO para TODOS los
  bloques colapsables de la página (bio de cada docente, presentación,
  contenidos, opiniones), y su `expand()` — sin chequear de cuál bloque
  se trataba — siempre buscaba `#oec-more_reviews_2` y lo mostraba. Como
  varios de esos bloques se auto-expanden solos si su contenido entra en
  menos de 280px (ej. una bio corta de docente), CUALQUIERA de ellos
  expandiéndose disparaba el botón de opiniones aunque la sección de
  opiniones en sí siguiera colapsada. Fix: cada wrapper ahora chequea una
  sola vez si es el de opiniones (`wrapper.querySelector('.oec-reviews-full-list')`)
  y solo en ese caso toca `#oec-more_reviews_2` dentro de su propio
  `expand()`. Si se agrega otro bloque colapsable "especial" con su
  propio efecto secundario al expandirse, seguir este mismo patrón
  (chequear de cuál wrapper se trata ANTES de tocar algo fuera de él) en
  vez de asumir que el handler genérico solo se aplica a un bloque.
- **Colisión nueva encontrada con `oec-wp-theme` — `<section>` con 64px de
  padding de más, arriba Y abajo, en TODAS las tarjetas de la ficha**:
  después de arreglar lo de arriba, Mario seguía viendo un espacio
  enorme debajo de "Cargar más opiniones". La causa real no tenía nada
  que ver con el collapsible — el tema le pone `section { padding-block:
  4rem; }` (64px; hay una variante de 3rem en un media query para mobile)
  a CUALQUIER `<section>` de la página, y varias tarjetas de la ficha son
  literalmente `<section class="oec-card">` (Docentes, Presentación,
  Contenidos, Opiniones, Por qué elegir, Más información, Contacto — 7 en
  total). `.oec-card` nunca declaraba su propio `padding`, así que el
  `padding-block: 4rem` del tema quedaba sin competencia y se sumaba
  ENTERO, arriba y abajo, encima del padding propio de `.oec-card-pad`
  (24px) — el mismo patrón exacto que ya rompió `.post-content` con los
  enlaces/viñetas/H1, pero esta vez con un selector de elemento suelto
  distinto (`section` en vez de `.post-content X`). Fix: `.oec-card {
  padding-block: 0 !important; }` — mismo tratamiento en
  `oec-formaciones.css` para `.oec-scroll-section` (las landings de
  temática TAMBIÉN usan `<section>` para cada tira horizontal, mismo
  riesgo). Verificado en vivo: las 7 `<section class="oec-card">` de la
  ficha pasaron de 64-88px de padding-bottom real a 0-24px (el 24px que
  queda es el de `.oec-card-pad`, correcto e intencional).
  **Aprendizaje para la próxima vez que "aparece espacio de la nada"**:
  antes de sospechar del propio HTML/JS del plugin, revisar primero si el
  elemento en cuestión es un tag HTML semántico genérico (`section`,
  `article`, `aside`, `nav`, `ul`, `ol`, `p`, `a`...) sin una clase propia
  que ya le fije esa propiedad — esos son exactamente los que un tema
  puede resetear globalmente sin que el plugin se entere. Ya van tres
  veces con `oec-wp-theme` (`.post-content` en `page.php`, `line-height`
  del `body`, y ahora `section { padding-block }`) — vale la pena, la
  próxima vez que aparezca un espacio/tamaño raro, chequear ESTOS TRES
  primero (specificidad de clase genérica, line-height heredado, padding
  de un tag semántico) antes de buscar en cualquier otro lado.
- **Iconos de "Se puede pagar con" (`.oec-pay-tile`/`.oec-pay-grid`)**:
  se había revisado el CSS (`width:100%; height:100%; object-fit:
  contain` en el `<img>`, caja de 26px de alto) y no se encontró ningún
  bug — la imagen de origen que devuelve `imgrsize.oe-img.center` para
  estos íconos es cuadrada (120×120), así que `object-fit:contain` la
  deja chica y centrada dentro de una caja ancha, con aire a los
  costados. A pedido de Mario se ajustó igual el contenedor para que se
  vea mejor con ese asset cuadrado: `.oec-pay-tile` pasó de `height:26px;
  padding:3px` a `height:36px; padding:6px 2px` (más aire arriba/abajo,
  menos a los costados, caja más alta → el `object-fit:contain` deja
  crecer más el logo verticalmente antes de tocar el borde). No se tocó
  el asset de origen.
- **Chip de fecha ("calendario", `.oec-cal-chip`)**: se veía más alto de
  lo esperado ("no parece un calendario"). La causa real NO era el
  padding — era que `.oec-cal-chip-month`/`.oec-cal-chip-day` nunca
  declaraban su propio `line-height`, heredando el `line-height: 1.7`
  que `oec-wp-theme` le pone al `body` (pensado para párrafos de
  prosa, no para un número grande de 2 dígitos en una etiqueta chica) —
  con fuente de 20px, eso son 34px de alto de línea, casi el doble de lo
  necesario. Mismo mecanismo exacto que ya rompió el subrayado del tab
  activo y el "peek" de tarjetas en sesiones anteriores: un elemento del
  plugin que nunca fijó una propiedad explícita queda a merced de lo que
  sea que el tema activo defina para esa propiedad de forma global. Fix:
  `line-height: 1.2 !important` en ambas líneas del chip (y en
  `.oec-cal-label`/`.oec-cal-date`, mismo problema), más `width: 46px →
  54px` y padding vertical `3px → 2px` para además compensar la
  proporción (antes 46×58 aprox., ahora 54×44 — más ancho que alto,
  como un calendario real).
  - **Barrido preventivo sobre el resto de las píldoras/badges chicos de
    la ficha** (mismo síntoma, mismo diagnóstico, encontrado ANTES de
    que Mario lo reportara uno por uno): se les agregó `line-height:
    1.2 !important` a todos los que declaraban `font-size` pero no
    `line-height` propio y cuyo alto depende directamente del texto (no
    de un contenedor con tamaño fijo) — `.oec-tip`/`.oec-tip.destacado`
    (pastillas "8 ediciones", "81 alumnos"..., medido: 27px → 21px de
    alto), `.oec-mini-bullet` (34px → 26px), `.oec-hero-cert-badge`,
    `.oec-live-label`, `.oec-region-label`/`.oec-region-sublabel`,
    `.oec-cal-timer`, `.oec-addcal-btn`/`.oec-addcal-opt`,
    `.oec-pay-label`, `.oec-cd-prefix`, `.oec-hero-ring-score`,
    `.oec-rating-ring-score`. `.oec-hero-ring-label` ya tenía su propio
    `line-height: 1.3` de antes — no le hacía falta. **Si en el futuro
    se agrega un badge/pill/label chico nuevo, declararle SIEMPRE su
    propio `line-height` explícito** (no asumir que el default del
    navegador o de ningún tema en particular es razonable) — este es
    ya el tercer síntoma distinto de la misma causa raíz (el primero fue
    la fuente del hero, el segundo el chip de fecha) en esta misma
    sesión de blindaje.
- **Promedio numérico junto a las estrellas del hero** (debajo del `<h1>`):
  ya existía el promedio en el ring 1 de la derecha del hero, pero la fila
  de estrellas sola (`.oec-sprite`) quedaba incompleta — se le agregó al
  lado `<a class="oec-hero-rating-avg scroll-link" where="oec-opiniones">{{ reviews.summary.average|number_format(1,',','') }}</a>`
  (mismo filtro/formato "decimal con coma" que ya usa el ring, mismo
  `scroll-link` a Opiniones que ya tenían las estrellas). No repite la
  cantidad de opiniones acá (esa se lee en el ring) — solo el número, para
  no duplicar toda la info en dos lugares de la misma pantalla. Color
  `#fbbf24` (dorado, a juego con las estrellas del sprite — el sprite es
  una imagen, no hay una variable CSS de "color de estrella" en el
  plugin para reusar, así que quedó hardcodeado acá). Centrado real: las
  dos `<a>` (sprite + número) se envolvieron en
  `.oec-hero-rating-row { display: inline-flex; align-items: center;
  gap: 8px; }` — antes el número estaba con `vertical-align: middle`
  suelto, que con las distintas métricas de fuente de cada elemento no
  centraba bien (quedaba corrido hacia abajo); flex con `align-items:
  center` sí centra por altura real de caja, sin depender de baseline.
- **Espacio entre las dos líneas de `.oec-teacher-org`** (afiliación +
  especialidad de un docente, en la sección Docentes de la ficha —
  `docente.origin` y `docente.background` se imprimen como dos
  `<div class="oec-teacher-org">` seguidos): la regla base pasó a
  `margin: 4px 0 0` (antes `margin: 0`) — a pedido, en las DOS, no solo
  en la segunda vía hermano adyacente (que fue el primer intento). Ojo
  con el resultado real: como estos divs son hijos de un `<div>` sin
  flex/grid (colapso de márgenes normal de bloque), el margin-top de 4px
  de cada uno colapsa con el margin-bottom del anterior (0 en
  `.oec-teacher-name`, 0 en el propio `.oec-teacher-org`) — el resultado
  visual entre las dos líneas de org termina siendo exactamente 4px (no
  8px), verificado en vivo con `getBoundingClientRect()`.
- **Tarjeta de docente en mobile angosto — imagen arriba, texto abajo**
  (`.oec-teacher-row`, `@media max-width:600px`): con afiliaciones
  largas ("Universidad Nacional de La Plata; Universidad Nacional de
  José C. Paz...") el layout de imagen+texto lado a lado (el de
  escritorio) dejaba el texto partido en muchas líneas cortas,
  apretadas contra el ícono "+" de expandir — se veía peor cuanto más
  angosta la pantalla. Se apila (`flex-direction:column`, todo
  centrado) en vez de forzar la fila — el texto pasa a tener todo el
  ancho de la tarjeta para acomodarse, no solo lo que le queda al lado
  de la imagen. Se aplica a las dos variantes (`.oec-teacher-row`
  normal Y `.oec-ac--teachers-lg .oec-teacher-row`, la de pocos
  docentes/imagen más grande) — la "lg" además se achica un toque
  (76px → 64px de imagen) ya apilada, para no quedar excesiva. El "+"
  de expandir queda afuera de `.oec-teacher-row` (es hermano suyo
  dentro de `.oec-ac-btn`), así que apilar el contenido de adentro no
  le afecta su posición.
- **Anillos animados del hero** (`.oec-hero-ring-fill`, los 4 círculos de
  la derecha del hero: días para inscribirte, rating, % en vivo,
  descuento): el trazo pasó de `1.4s` a `2.5s` de transición (se pedía
  más lenta, "no se pueden apreciar"). El NÚMERO de cada anillo
  (`.oec-hero-ring-score`) ahora también cuenta animado, arrancando en el
  mismo instante que el trazo (mismo IntersectionObserver, misma
  duración 2.5s — si se cambia una, cambiar la otra en
  `js/oec-formacion.js`/`css/oec-formacion.css` para que no se
  desincronicen). Reutiliza la MISMA función que ya animaba las píldoras
  "X ediciones"/"X alumnos" del hero (antes vivía inline adentro del
  bloque de tips, ahora es una función compartida,
  `oecAnimateNumberText(el, duration)`, al principio de `oec-formacion.js`) —
  se le sumó soporte para el formato "decimal con coma" (`"4,9"`, el
  rating promedio) además del que ya tenía (entero + sufijo libre, sirve
  tanto para "16 días" como para "67%"). Verificado con la función aislada
  (no depende del Browser pane, que esta sesión tuvo problemas de
  paint/rAF con la pestaña oculta): los 3 formatos reales (entero,
  decimal con coma, porcentaje) cuentan de 0 al valor final correcto en
  el tiempo esperado.
  - **Círculos apretados en mobile** (`@media max-width:960px`): el
    número tocaba casi el borde del círculo, sobre todo en los anillos
    de porcentaje ("66%", "100%"). Se agrandó el círculo `44px → 50px`
    y se subió el número de `11px → 12px` con `letter-spacing: -.6px`
    (antes `-.4px`) — una combinación de "círculo más grande" +
    "número un poco más compacto" en vez de solo una de las dos cosas
    (Mario preguntó cuál convenía; esta mezcla da más aire sin achicar
    el número, que hubiera perdido legibilidad). Verificado con
    `canvas.measureText()` contra el ancho real disponible (diámetro
    interior del anillo, `círculo × 29/40` — la proporción real del
    aro según su `viewBox`): el caso más ajustado ("66%"/"20%") pasó de
    ~1px de aire a 7px.
  - **Bug real encontrado en el mismo chequeo — DOS overrides de mobile
    completamente ignorados desde el blindaje `!important` de una
    sesión anterior**: `.oec-hero-ring-label { font-size: 8.5px; }` y
    `.oec-hero-subtitle { font-size: 14px; }` (ambos dentro de
    `@media max-width:960px`) nunca tenían efecto — sus reglas de
    escritorio (`.oec-hero-ring-label`/`.oec-hero-subtitle`) ya tenían
    `font-size` con `!important` desde el blindaje, y una declaración
    `!important` le gana a cualquier no-`!important` sin importar el
    origen (media query o no) — el texto se seguía viendo al tamaño de
    escritorio en mobile, sin ningún error visible. Se detectó con un
    script que compara, selector por selector, cada propiedad
    sobreescrita dentro de un `@media` contra la misma propiedad en la
    regla base — si la base tiene `!important` y el override de mobile
    no, es un bug silencioso. Se corrió sobre LOS DOS archivos CSS del
    plugin completos (no solo esta zona) y no aparecieron más casos
    después de arreglar estos dos. **Aprendizaje importante para
    cualquier cambio de CSS de acá en más**: si se agrega o edita un
    override dentro de un `@media` para una propiedad que la regla base
    ya blinda con `!important` (la gran mayoría desde el blindaje —
    `color`, fuentes, `margin`/`padding`, bordes, `list-style`,
    `text-decoration`...), el override TAMBIÉN necesita `!important`, o
    se ignora en silencio. El script de verificación no quedó guardado
    como archivo del proyecto (fue ad-hoc, un-liner de una sesión) —
    si hace falta repetir esta auditoría, rehacerlo es sencillo: parsear
    el CSS (con los comentarios `/* */` primero reemplazados por
    espacios en blanco, preservando saltos de línea — si no, un
    comentario que cite código con llaves literales, como el de
    ".oec-card" más arriba en este mismo archivo, desincroniza el
    conteo de llaves y da falsos negativos), agrupar declaraciones por
    selector exacto, y comparar propiedades dentro de `@media` sin
    `!important` contra la misma propiedad en la regla base con
    `!important`.
- **Redes sociales del docente — el botón ahora muestra el texto, no
  solo el ícono** (`initTeacherSocialLinks()`, `oec-formacion.js`, la
  función que detecta un `<ul><li><a>` de puro-links al final de la bio
  y lo reemplaza por botones circulares): el texto original del link
  ("Seguir en Instagram") antes solo quedaba en `title`/`aria-label` — a
  pedido, ahora también se ve, como `<span class="oec-social-btn-label">`
  al lado del ícono. Se arma con `.text()` (no `.html()` con interpolación
  de string) para no reinterpretar el label como HTML si el texto de
  origen trajera algún caracter raro. El botón pasó de círculo (34×34,
  solo ícono) a píldora (`border-radius:20px`, padding asimétrico,
  `display:inline-flex` con `gap`) para hacerle lugar al texto.
- **Fecha de las reviews — pasó de abajo del nombre/estrellas a abajo
  del comentario** (`.oec-rf-date`, dentro de `.oec-review-full`): antes
  vivía en `.oec-rf-meta` (columna izquierda, junto al nombre y las
  estrellas); ahora vive en un wrapper nuevo, `.oec-rf-body` (columna
  derecha, junto con `.oec-rf-text`), como el último elemento — abajo
  del texto de la reseña, no al costado. Tocar DOS lugares si se cambia
  esto de nuevo: el Twig (`data/page-formacion-testing.html`, dentro del
  `{% for review in reviews.all %}`) Y el JS
  (`oec-formacion.js`, `oec_get_reviews()` — arma el mismo HTML a mano
  con template literals para las reviews de "cargar más opiniones",
  página 2 en adelante). El layout mobile (`@media max-width:960px`,
  antes ponía `.oec-rf-text` a `flex: 0 0 100%` para que saltara a su
  propia fila) ahora apunta a `.oec-rf-body` en su lugar, para que
  arrastre la fecha con el texto a esa misma fila de abajo.
- **"¿Por qué elegir esta formación?" — aparición escalonada**: cada
  `.oec-bullet` arranca en `opacity:0; transform:translateY(14px)` y
  se le agrega la clase `.is-visible` (fade + leve subida, `.5s`) con un
  delay creciente de 70ms por ítem cuando el contenedor `.oec-bullets`
  entra en viewport — un solo `IntersectionObserver` sobre el
  contenedor completo (no uno por ítem), el escalonado se logra con
  `setTimeout(..., i * 70)` en el JS, no con CSS `nth-child`. Respeta
  `prefers-reduced-motion: reduce` (se muestran directamente, sin
  animación). Mismo patrón ya establecido en este archivo para los
  anillos del hero y las píldoras de tips — `IntersectionObserver` +
  clase `.is-visible`, nada de librerías externas de animación.
- **Altura del hero** (`#oec-hero`/`.oec-hero-content`): usa `min-height` +
  `box-sizing: border-box` — sin `border-box`, el `min-height` no incluye
  el padding y la altura real termina siendo min-height + padding-top +
  padding-bottom, mucho más alta de lo que dice el número (nos pasó: un
  `min-height` "bajado" no achicaba nada porque faltaba este box-sizing).
  El piso está pensado para que la mayoría de las formaciones (título de
  1-2 líneas + tips + anillos) caigan siempre en la misma altura fija —
  solo los títulos excepcionalmente largos lo superan y crecen un poco
  más, a propósito (para no cortar contenido).
- **Caché de CSS/JS en el navegador**: los assets se encolan con versión
  `filemtime()`, no un número fijo — con un número fijo, el navegador
  puede seguir sirviendo una versión vieja después de editar el archivo,
  indefinidamente, sin ningún aviso. Nos comió bastante tiempo de debugging
  esta sesión hasta que lo arreglamos. Si se agrega un enqueue nuevo,
  seguir el mismo patrón.
- **Debug de performance**: `oec_debug_time()`/panel en pantalla, se activa
  con `?oec_debug=1` en la URL (cualquier sitio). Antes se prendía solo
  con estar en local (`OEC_ENV === 'local'`) — se sacó ese auto-encendido
  porque quedaba de fondo mientras se probaban otras cosas, sin que hiciera
  falta.
- Formato de moneda: `Number.prototype.toLocaleString('es-AR', {...})` en
  vez de un formateador a mano — ya da punto de miles / coma decimal
  correctos.
- La sección de contacto ("¿Alguna duda?") vive **dentro** de
  `.oec-col-left`, como su último bloque, antes del selector de
  país/moneda (que es el verdadero último elemento de la columna). No es
  un diálogo modal (se sacó el `#oec-overlay` viejo por completo).

## Sección de Contacto — 3 canales (Email / chat anónimo Botmaker / WhatsApp)

Rediseño completo de la sección `#oec-contacto` (antes: solo una tarjeta
del CSM + formulario, en 2 columnas parejas). Ahora son 2 columnas con 3
bloques — `.oec-contact-grid`:
- **Columna izquierda — `.oec-contact-block--email`, "Por Email"**: lo
  mismo que había antes (foto/nombre/rol del CSM, texto, formulario
  Nombre/Apellido/[Teléfono]/Email), pero apilado en una sola columna
  interna en vez de foto+texto de un lado y formulario del otro.
- **Columna derecha — `.oec-contact-col-alt`**, dos bloques apilados:
  - **"Por chat anónimo"** (`.oec-contact-block--chat`) — **actualizado
    2026-09-17**: ya NO depende de un ID configurado en wp-admin (ese
    campo se sacó por completo) — el ID de Botmaker está hardcodeado en
    el plugin (`OEC_BOTMAKER_PROJECT_ID`, `oec-main.php`) y el bloque
    aparece o no según `showChatAnonimo` (equipo de ventas de OEC +
    monto de la formación) — ver la sección "Sección de Contacto — chat
    anónimo hardcodeado + reglas por precio" más abajo para la tabla de
    verdad completa. El botón "Iniciar chat anónimo" NO es el link
    genérico que da Botmaker (esa "pelotita" flotante default se
    esconde) — abre el widget con nuestro propio botón y le manda el
    MISMO texto predeterminado que ya usa WhatsApp (ver `chat_message`
    más abajo).
  - **"Por WhatsApp"** (`.oec-contact-block--whatsapp`) — mismo `wa_link`
    que ya usaba el botón flotante (`#oec-float-wa`), sin duplicar
    lógica. **Actualizado 2026-09-17**: también condicional ahora
    (`showWhatsappBlock`), ver misma sección de abajo.

**`chat_message`** (`data/page-formacion-testing.html`, junto a
`wa_number`/`wa_link`, cerca del principio del archivo): un solo lugar
que arma "Info sobre {canonical} por favor." — lo usan TANTO el link de
WhatsApp (con `|url_encode`, reemplazando el `%20` a mano que había
antes) COMO el `data-msg` del botón de Botmaker. Si el texto predeterminado
cambia algún día, tocarlo ahí una sola vez.

**Botmaker — API sin documentación pública, confirmada probándola en
vivo contra su CDN real** (no inventada/adivinada): el script oficial
(`go.botmaker.com/rest/webchat/p/{ID}/init.js`) expone en `window`:
`bmHide()`, `bmShow()`, `bmMaximize()`, `bmSendMessage(texto)` — se
confirmó cargando el script real en una página de prueba y viendo el
mensaje aparecer de verdad en la transcripción del chat (inspeccionando
el iframe, que resultó ser same-origin-accesible). Ningún método de
estos está en la ayuda pública de Botmaker (se buscó, incluida la URL
que pasó Mario — tampoco documenta un evento de "se cerró la
conversación" ni una forma de desactivar la pelotita flotante desde el
arranque). Uso en `js/oec-formacion.js` (`initBotmakerChat()`):
- El iframe del widget se identifica por `iframe[name="Botmaker"]`
  (atributo real, verificado en vivo) — nunca `document.querySelector('iframe')`
  a secas, para no confundirlo con OTRO iframe que pueda haber en la
  página (ej. un video de Vimeo incrustado en "Presentación").
- **Vigía continuo (`setInterval`, cada 400ms), no un solo `bmHide()`
  al cargar** — reemplaza el enfoque anterior de "reintentar bmHide()
  unas pocas veces con delays crecientes" (no alcanzaba). Mientras una
  bandera propia `userWantsOpen` esté en `false`, cualquier tamaño >0
  del iframe (medido con `getBoundingClientRect()`) dispara `bmHide()`
  de nuevo. Cubre DOS casos con el mismo mecanismo:
  1. La carrera de la primera carga (que `window.bmHide` ya exista como
     función no significa que el widget terminó de montarse del todo —
     un solo llamado apenas aparece la función a veces quedaba pisado
     por la propia inicialización de Botmaker).
  2. **Bug real encontrado probando el flujo completo**: cuando el
     usuario cierra la conversación desde el botón propio de Botmaker
     ("Cerrar chat", adentro del widget), `bmHide()` NO se dispara
     solo — el widget cae en su estado "minimizado" propio (la
     pelotita flotante con el globito de pregunta, tapando "INICIAR
     INSCRIPCIÓN" en mobile), no en escondido de verdad. Medido en
     vivo: escondido da 0×0, abierto da pantalla completa en mobile /
     ~450×647 en desktop, y esa pelotita intermedia da ~330×110 —
     bastante más chica que "abierto" pero `!=0`, la señal que usa el
     vigía para saber que hay que volver a esconderlo.
- Además del vigía, se engancha directamente al botón "Cerrar chat"
  DENTRO del iframe (`.wc-header-right`, mismo-origen confirmado) para
  reaccionar al toque (`userWantsOpen = false`) en vez de esperar hasta
  el próximo tick — el listener se reintenta re-adjuntar en cada tick
  porque Botmaker reescribe su propio documento interno
  (`document.write`) al cambiar de estado, así que un listener puesto
  una sola vez se puede perder. Envuelto en `try/catch` (por si Botmaker
  alguna vez sirve el iframe desde otro origen — hoy no es el caso,
  verificado) para que un error acá nunca tire abajo el vigía de tamaño,
  que es el que realmente importa.
- Al clickear el botón propio: `userWantsOpen = true`, `bmShow()` +
  `bmMaximize()`, y 500ms después `bmSendMessage(msg)` (el widget
  necesita ese respiro para poder recibir un mensaje recién abierto).
- Si Botmaker cambiara su script y estos nombres dejaran de existir, el
  polling (`whenReady()`, hasta 10s) simplemente nunca arranca el
  vigía — no rompe la página, pero el botón deja de abrir el chat en
  silencio; revisar esto primero si algún día "Iniciar chat anónimo"
  deja de funcionar.

**Bug real encontrado después — la carrera de la primera carga NO
estaba resuelta del todo, solo en redes rápidas (el vigía JS depende de
que Botmaker termine de cargar su script async)**: en desktop, con red
más lenta que en las pruebas locales, el widget se llegaba a ver un
instante al cargar la página antes de que `bmHide()` pudiera correr —
el vigía de JS por sí solo no alcanza porque DEPENDE de que
`window.bmHide` ya exista, y eso tarda lo que tarde en cargar el script
de Botmaker (fuera de nuestro control). **Fix real: esconder por CSS
puro, no por JS** (`css/oec-formacion.css`):
```css
iframe[name="Botmaker"] { display: none !important; }
body.oec-bm-chat-open iframe[name="Botmaker"] { display: flex !important; }
```
El CSS aplica apenas existe el `<iframe name="Botmaker">` en el DOM,
sin depender de NINGÚN timing de JS — verificado en vivo con muestreo
cada 30ms desde el instante de la navegación: `display:none` ya en la
primera muestra, siempre, sin importar la velocidad de red. `initBotmakerChat()`
ahora tiene una función `setOpen(bool)` que sincroniza `userWantsOpen`
CON la clase `oec-bm-chat-open` en `<body>` (se agrega al abrir con el
botón propio, se saca al detectar el cierre) — el vigía de JS y
`bmHide()`/`bmShow()` siguen existiendo (mantienen el ESTADO INTERNO de
Botmaker sincronizado, para que sus propias animaciones/lógica no se
confundan), pero la VISIBILIDAD real ya no depende de ellos.

**Segundo bug real encontrado en el mismo arreglo — el botón "Cerrar
chat" tiene una clase CSS distinta según el tamaño de pantalla**: en
mobile es `.wc-header-right`; en desktop es `.wc-button.wc-button-regular`
— mismo `aria-label="Cerrar chat"` en los dos casos. El selector para
detectar el cierre desde adentro del iframe se cambió de la clase
(frágil, ya demostró variar) a `[aria-label="Cerrar chat"]`, que
funciona en ambas variantes. Si Botmaker vuelve a cambiar su markup,
revisar este atributo primero.

**Tercer bug real, encontrado por Mario inspeccionando a mano con
devtools — esconder el `<iframe>` NO alcanza, hay que esconder su
ENVOLTORIO**: Botmaker no inyecta el iframe suelto directo en `<body>`
— lo envuelve en un `<div>` propio, también hijo directo de `<body>`,
sin ninguna clase/id propios (solo estilo inline:
`position:fixed; width:450px; height:100%; left:0%; z-index:10000;
display:flex`). El CSS `iframe[name="Botmaker"] { display:none
!important }` escondía el iframe en sí, pero ese `<div>` contenedor
seguía existiendo con sus dimensiones completas (450px de ancho × TODA
la altura de la pantalla), `pointer-events` normal (no `none`) y
`z-index:10000` — invisible a los ojos (adentro no había nada
renderizado) pero seguía recibiendo clicks de cualquier link/botón que
tuviera la mala suerte de caer en esa franja izquierda de 450px de
ancho, a lo alto de toda la página. Confirmado con
`document.elementFromPoint()`: con el bug, un click a mitad del hero
caía sobre este `<div>` fantasma en vez del `<h1>` real. Fix: en vez de
esconder el iframe, se esconde el ENVOLTORIO completo, con un selector
`:has()` (soporte ya universal en navegadores evergreen a esta fecha):
```css
body div:has(> iframe[name="Botmaker"]) { display: none !important; }
body.oec-bm-chat-open div:has(> iframe[name="Botmaker"]) { display: flex !important; }
```
Como el iframe vive DENTRO de ese div, esconder el padre ya alcanza
(un hijo de un ancestro `display:none` tampoco recibe clicks) — se
dejó la regla vieja del iframe solo como red de seguridad adicional,
no hace falta borrarla. Verificado en vivo con
`getComputedStyle()`/`elementFromPoint()`: por default el `<h1>` vuelve
a ser el elemento real debajo del punto (antes tapado por el div
fantasma), y el ciclo completo (abrir con nuestro botón → cerrar con
"Cerrar chat" de Botmaker) sigue funcionando igual, con el envoltorio
apareciendo/desapareciendo junto con el iframe en los dos casos. **Si
Botmaker cambia de nuevo su markup**, revisar primero si sigue
envolviendo el iframe en un div propio — si algún día deja de hacerlo
(o lo anida distinto), este selector `:has()` deja de matchear y el
problema puede reaparecer en silencio.

**Ancho de las 2 columnas — ojo con esto la próxima vez que se toque**:
el ancho real disponible para `.oec-contact-grid` es el de
`.oec-col-left` (la columna IZQUIERDA de TODA la ficha, ~578-620px en
desktop normal — el resto se lo lleva `.oec-col-right`, la sidebar de
precios, 400px fija), **NO el viewport completo**. La primera versión
usó `flex-basis` calculado contra el viewport (320px + 280px + 32px de
gap ≈ 632px) y terminaba apilando las 2 columnas en una sola en
CUALQUIER pantalla de escritorio normal, porque nunca entraban en los
~578-620px reales disponibles — se corrigió a 260px + 220px + 24px de
gap (≈504px), verificado en vivo que entran cómodas en la columna real.
Si se vuelve a rediseñar esta sección, medir el ancho de
`.oec-col-left` primero, no asumir el ancho de la ventana.

**Botones "apagados hasta hover" — para no competirle a "INICIAR
INSCRIPCIÓN"**: los 3 CTA de esta sección (`.oec-form button` "Enviar
mis datos", `.oec-contact-cta--chat` "Iniciar chat anónimo",
`.oec-contact-cta--whatsapp` "Escribir por WhatsApp") pasaron de
rellenos de color a estilo outline (`background: transparent; border:
1.5px solid currentColor`) por default, a ancho completo
(`display:flex; width:100%`). Se rellenan de color recién al hacer
hover — y no solo al hacer hover del botón en sí: para
"Iniciar chat anónimo"/"Escribir por WhatsApp", el hover del CUADRO
entero (`.oec-contact-block--chat`/`--whatsapp`, no solo el botón)
también los activa (`.oec-contact-block--chat:hover .oec-contact-cta--chat`),
a pedido explícito ("apagados hasta que hago mouseover en el cuadro
correspondiente") — verificado con hover real (no simulado) en varios
puntos del cuadro, no solo sobre el botón. El verde de WhatsApp en
outline usa un tono más oscuro (`#1da851`) que el sólido (`#25d366`) —
el verde de marca original se leía pálido/bajo contraste como texto
sobre fondo claro; al hacer hover sigue rellenando con el verde sólido
de siempre. El formulario (`.oec-contact-form-wrap`) perdió el
`max-width` que tenía — a pedido, ocupa todo el ancho real disponible
de su columna.

**Mensaje de éxito del formulario — el texto con "usted" NO estaba en
este repo** (`class-oec-ajax.php`, `enviar_a_api_oec()`): el mensaje que
se mostraba ("Solicitud recibida, nos pondremos en contacto con usted a
la brevedad...") no era un string hardcodeado acá — se tomaba
directamente de `$data['success']['message']`, la respuesta de la API
EXTERNA de OEC (`oas-api.onlineeducation.center/api-oas/v1/more-info`,
fuera de este repo). Se cambió para IGNORAR ese texto externo y usar
siempre un mensaje propio (mismo en las dos rutas posibles del
formulario — `enviar_a_api_oec()` y `enviar_email_propio()`, antes
tenían redacciones distintas): *"¡Listo! Ya recibimos tu consulta — te
vamos a contactar a la brevedad."* — tuteo correcto, un solo lugar para
tocar el texto de acá en más sin depender de lo que devuelva un sistema
externo (que además puede cambiar su copy sin avisarnos). De paso,
`.oec-success`/`.oec-error` (`css/oec-formacion.css`) pasaron de texto
de color suelto a una tarjeta con fondo/borde suave + ícono, más acorde
al resto del sistema de diseño de la ficha.

**Bug real en mobile — "Opiniones de alumnos" se desbordaba del
cuadro** (`.oec-reviews-summary`): en pantallas angostas, el anillo
(84px fijo) + las estrellas (`.oec-sprite`, 110px fijo, no se puede
achicar) + el texto "N opiniones verificadas" en una sola fila no
entraban en el ancho real disponible — el texto quedaba 10px afuera del
borde derecho del cuadro (medido con `getBoundingClientRect()`, no a
ojo). Se arregló en el `@media (max-width: 600px)` existente: el
anillo se achica un poco (84px → 68px) y `.oec-reviews-summary-right`
pasa de fila a columna (estrellas arriba, texto abajo, en vez de
forzarlos en una sola línea) — mismo criterio de "apilar en vez de
forzar" que ya se usó para `.oec-review-full` en mobile.

**Fecha corta de módulo en mobile muy angosto** (lista de "Fechas y
precios", `#oec-precios`): cada fila de módulo mostraba la fecha larga
entre paréntesis ("Módulo 1 (06 de Agosto de 2026)") — en una pantalla
muy chica no entraba en una sola línea junto al nombre del módulo y el
precio, forzando un salto de línea feo. En vez de recortar con CSS
(`text-overflow`, que hubiera cortado la fecha a la mitad de forma
ilegible), se generan las DOS versiones desde el propio Twig y se
alterna cuál se ve por CSS:
- Filtro nuevo `format_date_short` (`class-oec-shortcodes.php`, al lado
  de `format_date`) — mismo timestamp, formato `"06 de Ago."` (mes
  abreviado a 3 letras + punto, sin año). `format_date` original queda
  intacto, sigue usándose en todos los demás lugares de la ficha.
- El Twig (`data/page-formacion-testing.html`, dentro del `{% for
  modulo in data.modules.data %}` de `#oec-precios`) imprime las dos
  versiones siempre, envueltas en spans hermanos:
  `.oec-price-date-long` (la de `format_date`) y `.oec-price-date-short`
  (la de `format_date_short`), ambas dentro de un `.oec-price-date`
  (que reemplazó el `style="font-size:11px;color:#9ca3af"` inline que
  tenía antes esa fecha, ahora como clase).
- CSS (`oec-formacion.css`): por default (desktop) `.oec-price-date-long`
  visible y `.oec-price-date-short` oculta; dentro del `@media
  (max-width: 600px)` existente se invierten. Mismo patrón de "las dos
  variantes conviven en el HTML, el CSS decide cuál se ve" que ya usa
  el resto del plugin para mobile vs desktop — no hace falta JS ni
  media query dentro del propio filtro Twig (Twig no sabe en qué
  viewport está el visitante, así que la decisión tiene que vivir en
  CSS, no en PHP).

## Sistema de créditos por descuentos

Flujo completo ya implementado en `class-oec-ajax.php` (balance, cupones
vigentes, token de un solo uso por email, dos emails, resta de créditos) y
conectado en el frontend (`oec_check_credits`, `oec_list_coupons`,
`oec_request_redeem`).

**Decisión clave**: este sistema solo existe en las comunidades propias de
OEC (las que administramos nosotros) — en un sitio de socio queda
completamente inactivo, sin que haga falta configurar nada.

- `OEC_CREDITS_ALLOWED_DOMAINS` (en `oec-main.php`): lista hardcodeada de
  dominios habilitados. **Pendiente: Mario tiene que completarla** con los
  dominios reales de sus comunidades.
- `OEC_CREDITS_API_KEY_DEFAULT` (en `oec-main.php`): key real de la API de
  créditos, hardcodeada — pero solo tiene efecto si el dominio actual está
  en la lista de arriba (`oec_credits_system_enabled()`). **Pendiente:
  Mario tiene que completarla** con el valor real.
- Ojo: el string de la key igual queda dentro del archivo del plugin que
  se distribuye a TODOS los sitios (propios y de socios) — el gate de
  dominio solo controla si se *usa*, no evita que alguien con acceso al
  hosting de un sitio de socio pueda leer el archivo y ver el valor. Fue
  una decisión consciente de Mario (prioriza cero fricción para las
  comunidades propias); si en algún momento esto preocupa, la alternativa
  sin ese riesgo es cargar la key solo vía wp-admin (`oec_credits_api_key`)
  en cada comunidad propia, en vez de hardcodearla acá.
- El bloque de UI de créditos en el Twig está envuelto en
  `{% if extra.credits_enabled %}` — en un sitio no habilitado ni siquiera
  se renderiza (y `initCreditsBox()` ya no-opea solo porque el elemento no
  existe).
- `log_canje_a_sheets()` (la función vieja que registraba cada canje en
  una hoja de cálculo, "para chusmear") — **se descartó, no se va a
  portar.** No hace falta retomarlo.

## Protección contra corrupción de contenido por parte de WordPress

WordPress puede destrozar el Twig crudo pegado en el editor por **dos
mecanismos totalmente independientes** — los dos están neutralizados a
nivel de plugin (`oec-main.php`, sección "3e. PROTECCIÓN CONTRA
CORRUPCIÓN DE GUTENBERG"), sin depender de que cada sitio/usuario haga
nada especial:

1. **Corrupción al editar (Gutenberg block-validation)** — con el editor
   de bloques, simplemente **abrir** para editar una página con un bloque
   "HTML personalizado" dispara la validación de bloques de Gutenberg,
   que re-parsea el HTML guardado con el tokenizer del propio navegador
   para compararlo contra lo esperado. Ese tokenizer no entiende Twig:
   `<option {% if opt.selected %} selected{% endif %}>` se transforma en
   atributos HTML falsos (`{%="" if="" opt.selected="" %}=""...`). Pasa
   con solo abrir el editor (no hace falta ni tocar nada ni guardar para
   que se corrompa en memoria; un guardado posterior sí lo persiste) — y
   es independiente de que el bloque ya sea "texto plano" (lo es). Fix:
   `oec_post_uses_shortcodes()` detecta si un post usa `[oec-content]`
   (vía `has_shortcode()`; desde 2026-09-26 ya no mira `[oec-list]`, que
   no lleva Twig), y tres filtros nativos de
   WordPress actúan en consecuencia — `use_block_editor_for_post` fuerza
   el editor clásico para esas páginas (sin plugin externo tipo "Disable
   Gutenberg"), `user_can_richedit` saca directamente la pestaña
   Visual/TinyMCE (otra vía de corrupción si alguien la usa), y
   `wp_insert_post_data` es una red de seguridad: si detecta la firma
   típica de esta corrupción (`{%=""`/`%}=""`) en un guardado, lo bloquea
   y mantiene la versión anterior en vez de persistir la rota (avisa por
   `admin_notices`).
2. **Corrupción al mostrar (`wpautop`)** — WordPress le aplica `wpautop()`
   al contenido en el filtro `the_content` (prioridad 10): envuelve en
   `<p>` cada salto de línea en blanco. Con 800 líneas de CSS/JS/Twig
   crudo, esto revienta todo visualmente (esto NO se guarda en la base —
   es puramente al renderizar, el `post_content` real queda intacto).
   WordPress normalmente evita esto solo (`do_blocks()` detecta un
   comentario `<!-- wp:... -->` en el contenido y saca `wpautop` al
   vuelo) — pero es frágil: si el comentario no está (o alguien lo borra
   sin saber para qué sirve — nos pasó a nosotros mismos), `wpautop`
   vuelve a actuar. Fix: un filtro propio en `the_content`, prioridad 8
   (antes que `wpautop`), que saca `wpautop` **de forma incondicional**
   para cualquier página con nuestros shortcodes, sin depender de ningún
   comentario de bloque. Mismo patrón de "filtro que se autoelimina" que
   usa `do_blocks()` internamente.

**Conclusión práctica**: al pegar el shortcode en un bloque de WordPress,
**no hace falta** envolverlo en `<!-- wp:html -->...<!-- /wp:html -->` —
ambos problemas ya están cubiertos por el plugin. Si algo se ve corrompido
de una de estas dos formas después de editar a mano, sospechar primero de
esto antes de asumir que el Twig está mal escrito.

## Cacheo en Cloudflare — qué se puede desactualizar, y el webhook de refresco

Mario va a poner estas páginas detrás de Cloudflare con caché de página
(24h de TTL). Análisis completo de qué se rompe/desactualiza con eso:

- **El número del countdown en sí — SEGURO**: `updateCountdowns()`
  calcula contra `Date.now()` real del visitante en su navegador, no
  contra el momento en que se generó el HTML cacheado — nunca muestra
  un número mal, sin importar cuánto tiempo lleve cacheada la página.
- **Lo que SÍ queda "congelado" hasta por 24h** (todo lo que se decide
  con Twig/PHP al momento de generar el HTML, comparando contra "now"
  de ESE momento, no del visitante):
  - `enrollment_closed`/`fecha_tipo` en cada tarjeta de `[oec-list]`
    (badges, si existe el countdown, el `enrollment=opened` que ya
    filtró la API al pedir la lista) — una formación que cierra
    inscripción durante la ventana de caché sigue mostrándose como
    abierta, con countdown tickeando, hasta que expire el caché.
    Afecta más a una tira de "últimos días para inscribirte", justamente
    por ser la más urgente.
  - `from-date="next-month"`/`"this-month"` — mal exactamente en el
    límite de mes (si el caché se llena el último día del mes y sigue
    sirviéndose al día siguiente).
  - **`openEnrollment`/`force_contact` en la FICHA de formación**
    (`page-formacion-testing.html:17`) — el de mayor impacto de negocio:
    si una formación cierra inscripción durante la ventana de caché, la
    página sigue mostrando el botón de compra y el JSON-LD con
    `offers.availability: InStock`. Si ABRE durante la ventana (pasa de
    cerrada a abierta), sigue mostrando el formulario de "avísame
    cuando abra" en vez del botón de compra — venta perdida.
  - Nonces AJAX (`OEC_AJAX_NONCE`, formularios de contacto/créditos/canje)
    — quedan fijos en el HTML cacheado; si el nonce vence antes que el
    caché, esos formularios fallan con "Sesión inválida, recarga la
    página e intenta de nuevo" (visible, no silencioso, pero sí fricción).

**Webhook de refresco — ELIMINADO (2026-09-28)**: existió un `POST /wp-json/oec/v1/purge-cache`
(`includes/class-oec-webhook.php`) que limpiaba el caché del plugin y purgaba URLs en Cloudflare, con
Zone ID/API Token/"URLs a purgar" en wp-admin. Decisión de Mario: el plugin puede o no estar detrás
de Cloudflare, así que no se mete con eso. En wp-admin → OEC → Configuración queda SOLO una caja
"Caché del plugin" con el botón **"Actualizar caché"** (`oec_clear_cache` → `OEC_Api::clear_cache()`);
el botón duplicado "Limpiar caché" del encabezado de "Configuración de Conexión" se sacó. Copia del
archivo borrado en el scratchpad de esa sesión. Las opciones viejas (`oec_webhook_secret`,
`oec_cloudflare_*`, `oec_cache_purge_extra_urls`) pueden quedar en la base de sitios donde se abrió
esa pantalla; no las lee nadie.

**`OEC_Api::clear_cache()` y la caché de objetos**: borra con un `DELETE` directo, que no pasa por
`delete_option()`. Por eso después invalida a mano `wp_cache` (cada opción borrada + `alloptions` +
`notoptions`). Sin eso, en un sitio con Redis/Memcached el botón no tenía efecto (se detectó al
probarlo: `get_option()` seguía devolviendo el valor borrado).

## Colisión de CSS con el tema `oec-wp-theme` ("Lightweight WordPress company theme")

Mario tiene un segundo proyecto relacionado, un tema de WordPress liviano
para el sitio de la empresa (`wp-content/themes/oec-wp-theme`, repo propio).
Al activarlo en `oec-test.local`, rompió visualmente la ficha de formación
(`[oec-content]`): enlaces subrayados donde no debían (incluido el botón
"INICIAR INSCRIPCIÓN", que además cambió de color), viñetas dobles en la
sección "Contenidos", espacios de más en el acordeón "Más información", y
tipografía distinta en el hero.

**Causa raíz encontrada y confirmada por código (no solo visualmente)**:
`page.php` del tema le pone la clase `post-content` a la `<article>` de
**cualquier** página (`page.php:19`, `post_class('post-content')`) — pero
el bloque de tipografía `.post-content h2/h3/h4/p/ul/ol/li/a/blockquote/
code/pre` en `style.css` (sección con encabezado "SINGLE POST" en el propio
archivo) está pensado únicamente para el cuerpo de una entrada de blog:
`single.php` es la ÚNICA plantilla que además le agrega la clase
`entry-content` (`single.php:40`, `class="post-content entry-content"`).
Como esas reglas usan selectores de una sola clase (especificidad 0,1,1) y
el CSS del plugin en varios lugares usaba selectores de elemento sueltos
(`a { text-decoration: none }`) o de una sola clase sin `!important`
(`.oec-btn-enroll`, `.oec-ac-btn h3`, `.oec-prose ul`...), y como WordPress
carga los `wp_enqueue_scripts` de los plugins antes que los del tema (el
CSS del tema queda más abajo en el `<head>`, así que gana los empates de
especificidad), el tema terminaba pisando el diseño del plugin en
cualquier página — no solo en esta, en cualquier sitio que use este tema
con cualquier plugin que renderice su propio HTML dentro de una página.

**Arreglo aplicado (en el repo del TEMA, no en este)**: se scopearon esas
~9 reglas de `.post-content` a `.post-content.entry-content` en
`style.css` — así solo se aplican cuando la página es realmente un post de
blog (que sí trae ambas clases), nunca a una página común que puede tener
un shortcode/plugin con su propio diseño completo adentro. Si en algún
momento una página SIN shortcode necesita esta misma tipografía de
artículo, la forma correcta es agregarle también la clase `entry-content`
en su propio template, no aflojar el selector de nuevo.

**Arreglo aplicado acá (en el plugin)**: `#oec-hero` y `#oec-mobile-bar`
(`css/oec-formacion.css`) son los dos únicos bloques de la ficha que viven
FUERA de `.oec-page` (que sí declaraba su propia `font-family`) — no
tenían su propia declaración de fuente y por eso heredaban lo que sea que
el `body` del tema activo definiera. Se les agregó la misma pila de
fuentes del sistema explícitamente, para que no dependan de que el tema
active no toque `body { font-family }` — importante porque el plugin se
instala en sitios que no administramos, donde no hay forma de controlar
qué define el tema de esa comunidad en `body`.

**Hallazgo relacionado, sin resolver todavía (a criterio de Mario)**:
`page.php` del tema también imprime SIEMPRE su propio
`<h1><?php the_title(); ?></h1>` + breadcrumb genérico dentro de
`.page-hero`, antes del `<article>` — confirmado leyendo el código, no
solo intuido. En una página como "Formación", que ya trae su propio
`<h1 class="oec-h1">` con el título real de la formación (y su propio
`BreadcrumbList` vía JSON-LD, ver sección de SEO más arriba), esto
reintroduce el mismo problema de doble-H1 que ya se había encontrado y
corregido una vez en la landing de nutrición deportiva (ver "Single-H1-
per-page SEO best practice" en el historial del proyecto) — pero ahora a
nivel de TEMA, afectando a cualquier página que use un shortcode con su
propio hero/breadcrumb. No se tocó todavía porque es una decisión de
arquitectura del tema (¿todas las páginas quieren ese hero genérico, o
hace falta una plantilla/bandera para que las páginas "aplicación"
—las que solo contienen un shortcode— lo salteen?), no algo para resolver
sin consultarlo.

**Decisión tomada después (Mario, 2026-09-12): blindar TODO, no solo este
caso puntual.** Motivo textual: "el tema se tiene que adaptar al plugin,
ya que el tema nos sirve para 5 o 6 implementaciones nuestras nomás, el
plugin nos tiene que servir para infinitas implementaciones en infinitos
temas". Se hizo un blindaje completo de estilos Y funciones — ver sección
siguiente.

## Blindaje del plugin contra CUALQUIER tema/plugin host (CSS + JS + PHP)

Filosofía general, para toda esta sección: **el plugin nunca debe asumir
nada sobre lo que hace el tema o los demás plugins del sitio donde se
instala** (puede ser cualquiera de infinitos sitios de socios que no
administramos) — cualquier estilo, ID, nombre de función/clase, o variable
global del plugin puede chocar con algo genérico del entorno, y en ese
choque el plugin tiene que ganar siempre. Se atacaron tres frentes:

### 1. CSS — `!important` selectivo, no ciego

`css/oec-formacion.css` y `css/oec-formaciones.css` tenían gran parte de
sus reglas sin `!important` (selectores de una sola clase o de elemento
suelto, especificidad baja) — cualquier hoja de estilos "genérica de
página" de un tema (como pasó con `oec-wp-theme`, ver sección anterior)
puede pisarlas. Se agregó `!important` a TODA declaración de estas
propiedades, en ambos archivos: `color`, `background`/`background-color`,
`font-family`/`font-weight`/`font-style`/`font-size`, `line-height`,
`text-decoration`/`text-align`/`text-transform`/`text-shadow`,
`letter-spacing`, `list-style`/`list-style-type`/`list-style-position`,
`margin*`, `padding*`, `border*`/`border-radius`, `box-shadow`, `outline`,
`white-space`.

**Por qué NO se tocaron TODAS las propiedades (nada de `display`,
`cursor`, `user-select`, `flex`, `width`, `height`, `position`, `opacity`,
`transform`, `transition`, `animation`, `overflow`, `box-sizing`, layout
de flex/grid, `scroll-behavior`)**: esas SÍ se manipulan en caliente desde
JS vía `elemento.style.propiedad = ...` en varios lugares (acordeones,
dropdowns, drag-to-scroll de carruseles, la píldora animada del hero,
paneles de país/moneda, etc. — ver `js/oec-formacion.js`,
`js/oec-frontend.js`, `js/oec-formaciones.js`). Un `!important` en la hoja
de estilos GANA incluso a un estilo inline puesto por JS — si se le
hubiera puesto `!important` a `display`, por ejemplo, ningún acordeón,
dropdown ni carrusel del sitio volvería a abrir/cerrar nunca más. Antes de
tocar una propiedad nueva con `!important` en el futuro, buscar primero
`.style.esaPropiedad` en los 3 archivos JS — si aparece, no ponerle
`!important` en el CSS.

Dentro de las propiedades SÍ blindadas, la jerarquía interna del propio
plugin se mantiene intacta a propósito: cuando dos reglas propias
compiten por la misma propiedad (ej. `p` de base vs `.oec-prose p` más
específico), ambas quedaron con `!important` — entre reglas todas
`!important`, la especificidad normal se sigue respetando, así que la más
específica de las DOS sigue ganando iguales que antes. Lo único que
cambió es que las dos ahora también le ganan a cualquier regla externa NO
`!important`, sea cual sea su especificidad u orden de carga.

`@keyframes` se dejó completamente intacto (el `!important` ahí no tiene
efecto real, y las animaciones no compiten con un tema por especificidad
de todos modos).

### 2. IDs de HTML — todos con prefijo `oec-` o `oec_`

`data/page-formacion-testing.html` tenía ~28 ids SIN prefijar,
compartidos por su nombre genérico con lo que cualquier tema/plugin
podría usar en la misma página — un ID duplicado en una página HTML rompe
`getElementById`, `querySelector('#id')` y CSS `#id` de forma
impredecible (gana el que sea, según orden del DOM). Los más riesgosos
eran literalmente `id="top"` (el wrapper principal de toda la ficha,
`.oec-page`) y `id="precios"`/`id="contacto"`/`id="right-column"` —
nombres que CUALQUIER tema podría reutilizar sin pensarlo dos veces.
Todos se renombraron con el prefijo `oec-` (o `oec_` cuando el id original
ya usaba guion bajo, para tocar lo mínimo posible), consistente en HTML +
CSS + JS:

`fechas`, `presentacion`, `docentes`, `contenidos`, `opiniones`,
`bullets`, `masinformacion`, `contacto` (las 8 secciones del sticky nav,
más el array `navSections` en `oec-frontend.js`), `menu-sticky`,
`bullet-pay`, `disc-early-payment`, `countries_select`,
`currencies_select`, `organization-description`, `pierdealgo`,
`po_bullet`, `po_div`, `po_span`, `precios`, `r-column`, `reg_config`,
`regional_change`, `right-column`, `sticky-open`, `sticky-close`, `top`,
`training-title`, y el patrón dinámico `more_reviews_${next}` (el botón
"cargar más opiniones" que se regenera con un número creciente cada vez
que se hace click).

**Ojo con `page-formacion.txt`** (producción, stale — ver "Archivos
clave"): tiene ESTOS MISMOS ids sin prefijar (`precios`, `right-column`,
`training-title`, `organization-description`...). No se tocó, siguiendo
la regla de siempre de no editarlo — pero significa que la producción
actual (mientras no se porte `-testing.html`) sigue con este riesgo
latente. Al portar la plantilla de testing a producción (pendiente #1),
este blindaje de IDs viaja solo con el resto de los cambios.

**Convención para código nuevo**: cualquier `id="..."` nuevo que se
agregue a una plantilla del plugin (ficha, listado, o una futura landing)
tiene que empezar con `oec-` (o `oec_` si el resto usa guion bajo) — sin
excepción, aunque el nombre "obviamente" no vaya a chocar con nada.

### 3. Funciones y clases PHP/JS — inmunes a colisión de nombre, no solo a pisarse el diseño

Este es el riesgo más grave de los tres: una colisión de CSS rompe el
diseño, pero una colisión de **nombre de función o clase PHP** (dos
`function oec_debug_time()` o dos `class OEC_Shortcodes`) es un **fatal
error de PHP que tira abajo el sitio entero**, no solo esta página. Se
confirmó que el tema `oec-wp-theme` usa el MISMO prefijo `oec_` para sus
propias funciones (misma autoría, mismo criterio de nombres) — hoy no hay
ningún nombre exacto repetido entre plugin y tema, pero nada lo impide a
futuro, y el plugin además se instala en sitios de terceros con sus
propios temas/plugins fuera de nuestro control.

**Arreglo**: las 19 funciones sueltas de `oec-main.php` (`oec_debug_time`,
`oec_get_page_bundle`, `oec_seo_and_stars_metadata`, etc.) y las 5 clases
del plugin (`OEC_Admin`, `OEC_Ajax`, `OEC_Api`, `OEC_Shortcodes`,
`OEC_Webhook`) quedaron envueltas en guards:

```php
if (!function_exists('oec_debug_time')) {
    function oec_debug_time(...) { ... }
}
```
```php
if (!class_exists('OEC_Shortcodes')) {
    class OEC_Shortcodes { ... }
}
```

**Por qué esto y no un refactor a namespace/clase única**: un namespace
de verdad es más "correcto" en teoría, pero hay que tocar cada
`add_action`/`add_filter` con callback en string (`'oec_xxx'` pasa a
necesitar `__NAMESPACE__ . '\\oec_xxx'` o un array callback) en varios
archivos, con riesgo real de romper un hook por un descuido de sintaxis.
El guard `function_exists`/`class_exists` da la MISMA garantía (cero
fatal error por colisión) sin tocar ni un solo call site — y como
WordPress carga los plugins ANTES que el tema, si alguna vez hay una
colisión real, la definición del PLUGIN gana siempre (se carga primero),
y la del tema/otro plugin simplemente no se redeclara — exactamente el
comportamiento que hace falta ("el tema se adapta al plugin, no al
revés"), gratis, con este patrón. Ya existía un precedente parcial de
esta misma idea en el código (`class-oec-ajax.php`/`class-oec-admin.php`/
`class-oec-shortcodes.php` ya llamaban a `oec_credits_system_enabled()`
protegido con `function_exists()` en el call site) — esto extiende el
mismo criterio a la DEFINICIÓN, de forma consistente.

**Convención para código nuevo**: toda función/clase global nueva que se
agregue en `oec-main.php` o `includes/*.php` tiene que ir envuelta en su
propio guard `function_exists`/`class_exists`, igual que las que ya
están. Si se agrega una función DENTRO de una clase (método), no hace
falta — un método no puede colisionar con nada fuera de esa clase.

**JS — mismo criterio, dos funciones renombradas**: `js/oec-formacion.js`
exponía dos funciones a `window` sin prefijo — `metaAddToCart` (llamada
desde `onclick="..."` en los botones de inscripción del Twig) y
`get_reviews` (desde un link "cargar más opiniones"). Nombres así de
genéricos son un blanco fácil para chocar con un snippet de tracking de
terceros (Meta Pixel, GTM) o con JS de cualquier tema/plugin del sitio —
se renombraron a `oec_metaAddToCart`/`oec_get_reviews`, actualizando los 4
call sites `onclick="..."`/`href="javascript:..."` en
`page-formacion-testing.html` y la auto-referencia interna del propio
`get_reviews` (arma el link de "cargar más" de la página siguiente). El
resto de las funciones JS del plugin (`activateFrame`, `flash`,
`fallbackCopy`, `replaceWA`, y todas las de `oec-formacion.js`/
`oec-frontend.js`/`oec-formaciones.js`) ya vivían dentro de IIFEs
`(function(){...})()` — sin fuga a `window`, ningún riesgo de colisión;
se verificó cada una específicamente, no se tocaron.
**CORRECCIÓN (2026-09-25, "Blindaje v2")**: lo de arriba era FALSO para `oec-formacion.js` —
declaraba ~40 funciones globales (`debounce`, `throttle`, `qsp`, `setExp`, `getExp`…). Ahora todo
el archivo vive en una IIFE; ver sección "Blindaje v2".

**Convención para código nuevo**: cualquier función JS que necesite
exponerse a `window` (para un `onclick`/`href="javascript:"` inline, por
ejemplo) tiene que llamarse `oec_algo`, nunca un nombre genérico. Todo lo
demás va dentro de una IIFE.

### Verificación hecha

`php -l` sobre los 6 archivos PHP tocados (sin errores), balance de
llaves/paréntesis en ambos CSS (428/428 y 169/169, sin desbalances),
`node -c` sobre los 2 archivos JS de la ficha (sin errores), y una prueba
real en vivo contra `oec-test.local`: `/formaciones`, la landing de
nutrición deportiva, una ficha real con `?oec_debug=1` (panel de debug
funcionando, sin fatal/parse errors, sin warnings/notices en el HTML), y
el webhook (`POST /wp-json/oec/v1/purge-cache` sigue devolviendo 401 sin
secreto, como corresponde) — las 5 clases envueltas en `class_exists`
siguen instanciándose y funcionando exactamente igual que antes.

## Compatibilidad con WordPress Multisite

Mario pidió (2026-09-17) confirmar que el plugin esté preparado para
instalarse en una red multisite. Auditoría hecha buscando los patrones
típicos que rompen en multisite (`grep` sobre TODO el plugin, sin
`vendor/`):

- **Nada de tablas/prefijos hardcodeados**: la única query directa a
  `$wpdb` (`OEC_Api::clear_cache()`, borra las opciones `oec_cache_*`)
  usa `$wpdb->options` — WordPress resuelve solo esa propiedad a la
  tabla de opciones del sitio ACTUAL (`wp_options`, `wp_2_options`,
  `wp_3_options`...) según el blog activo, así que ya es multisite-safe
  sin tocar nada.
- **Todo el estado del plugin vive en `wp_options`** (`get_option`/
  `update_option`/`delete_option`, más algún `set_transient`) — color de
  marca, token de la API, ID de Botmaker, secreto del webhook, caché de
  `OEC_Api`, todo. Como `wp_options` es una tabla POR SITIO en
  multisite, cada sitio de la red configura y cachea de forma
  completamente independiente sin que haga falta ningún código especial
  — es exactamente el comportamiento que ya quiere la arquitectura del
  plugin ("mismo componente, pegado en sitios distintos con branding/
  configuración propia").
- **Sin `wp_cache_*` (caché de objetos)**: si el plugin usara la Object
  Cache API de WordPress con un backend persistente compartido en toda
  la red (Redis/Memcached, común en instalaciones grandes) y sin
  especificar `group` correctamente, ahí sí podría haber fuga de datos
  entre sitios — no es el caso, el caché se guarda como filas de
  `wp_options` directamente (`oec_cache_*`), que ya está aislado por
  sitio como se explicó arriba.
- **Rutas de assets con `plugin_dir_url()`/`plugin_dir_path()`**, nunca
  una URL armada a mano — correctas en cualquier sitio de la red, ya
  que apuntan al directorio real y compartido del plugin
  (`wp-content/plugins/oec-wordpress-plugin/`), no a algo específico de
  un sitio.
- **Sin `is_multisite()`/`switch_to_blog()`/`get_sites()` en ningún
  lado** — y no hace falta: el plugin nunca necesita leer/escribir datos
  de OTRO sitio de la red desde el contexto de uno solo (cada shortcode
  solo le habla a la API externa de OEC y a las opciones del sitio
  actual), así que no hay ningún caso de uso que requeriría esas
  funciones.
- **Menú de ajustes** (`OEC_Admin::add_menus()`) registrado en
  `admin_menu` (no `network_admin_menu`) — a propósito: en un network
  activado, cada sitio sigue viendo y configurando SU PROPIO wp-admin →
  OEC → Configuración (branding, tokens, secreto del webhook, ID de
  Botmaker...) de forma independiente, que es el comportamiento
  correcto para este plugin (no un ajuste único para toda la red).
- **`register_rest_route()`/`add_rewrite_rule()`** — ambos se registran
  en hooks estándar (`rest_api_init`/`init`) que corren una vez por
  request de CADA sitio; WordPress ya resuelve el namespace REST y las
  reglas de reescritura por sitio sin intervención del plugin.

**Único ajuste hecho, defensivo más que correctivo**:
`OEC_CREDITS_ALLOWED_DOMAINS`/`OEC_CREDITS_API_KEY_DEFAULT`/
`OEC_REDEEM_CONFIRM_SLUG` (`oec-main.php`) se definían con `define()`
SIN el guard `if (!defined(...))` que ya usaban `OEC_ENV`/
`OEC_CACHE_ENABLED` un poco más arriba en el mismo archivo — inconsistente
con el resto. Si por cualquier motivo este archivo llegara a incluirse
dos veces en el mismo request (un MU-plugin raro, un `sunrise.php`
custom, un entorno de testing que hace `include` manual), un `define()`
duplicado tira un warning de PHP ("Constant already defined") en vez de
fallar silencioso — no es un escenario exclusivo de multisite, pero es
justamente en redes grandes/complejas donde aparecen más `sunrise.php`/
mu-plugins raros, así que se guardó igual que las otras dos constantes,
por consistencia. `php -l` corrido después, sin errores.

**Conclusión**: no se encontró ningún bloqueante real para multisite —
la arquitectura ya usaba las APIs correctas de WordPress
(`get_option`/`update_option`/`plugin_dir_url()`) desde antes, que son
multisite-safe por diseño. Lo único pendiente, y esto es igual en
single-site, es que la regla de reescritura de `/formacion/...`
necesita un "guardar cambios" en Ajustes → Enlaces permanentes la
primera vez que se activa en un sitio nuevo (no hay
`register_activation_hook` que dispare `flush_rewrite_rules()`
automático) — si al instalar en un sitio nuevo de la red las URLs de
`/formacion/...-t-XXXXXXXXXXXXXX/` dan 404, revisar esto primero.

**Actualización 2026-09-17 — `oec-test.local` YA es multisite, cambió
el flujo de pruebas en local**: en la siguiente sesión de trabajo se
descubrió (probando en vivo, no algo que se haya tocado desde este
repo) que Mario ya convirtió el sitio local a una red multisite por
subdirectorio, con al menos:
- `blog_id=1`: `oec-test.local/` (sitio raíz — redirige con 302 a
  `/es/`, no parece servir contenido propio).
- `blog_id=2`: `oec-test.local/es/` — **este es el sitio real donde se
  prueba todo ahora**. Tablas propias (`wp_2_posts`, `wp_2_options`,
  etc.) — las opciones del plugin (`oec_equipo_ventas`,
  `oec_brand_color`, `oec_token`...) viven en `wp_2_options`, NO en
  `wp_options` a secas.
- `blog_id=3`: `oec-test.local/en/` — sin confirmar contenido.

**Las páginas "Formación"/"Formaciones" tienen OTROS IDs acá**: dentro
de `wp_2_posts`, "Formación" es el post **ID 8** (no 65) y
"Formaciones" es el post **ID 7** (no 64). Cualquier script de sync
tipo `mysqli` (ver "Aprendizaje de proceso" al principio de este
archivo) tiene que apuntar a `wp_2_posts` con estos IDs nuevos —
escribir a `wp_posts`/ID 65/64 (el patrón viejo, de antes de
multisite) actualiza el sitio RAÍZ, que no es el que se sirve en
`/es/...` y por lo tanto no tiene ningún efecto visible. **Antes de
sincronizar de nuevo, confirmar que estos IDs/tabla siguen siendo los
correctos** — corrieron esto una sola vez, no está garantizado que
seguirán siendo así para siempre (Mario podría reestructurar la red de
nuevo).
- URLs de prueba actualizadas: `http://oec-test.local/es/formaciones/`
  y `http://oec-test.local/es/formacion/{slug}-t-{id}/` (con el
  prefijo `/es/`, antes no hacía falta).

## Sección de Contacto — chat anónimo hardcodeado + reglas por precio (2026-09-17)

A pedido de Mario, la sección "¿Alguna duda?" y los íconos flotantes de
contacto se rediseñaron para depender de **quién atiende** (equipo de
ventas de OEC o no, `extra.equipo_oec`) y, si atiende OEC, de **cuánto
cuesta la formación** (`data.prices.total`, el mismo campo que ya
llega en la respuesta principal de la API de formación — no hace falta
pedir nada nuevo).

**Botmaker dejó de ser configurable por sitio — ahora está hardcodeado
en el plugin**: el campo "Botmaker — ID de proyecto (chat anónimo)"
que existía en wp-admin → OEC → Configuración se sacó por completo
(input, guardado, y la variable `$botmaker_id` que lo leía). En su
lugar, `oec-main.php` define `OEC_BOTMAKER_PROJECT_ID` (valor
`6EJESDV1VQ`, el mismo que ya estaba cargado para pruebas) con el
mismo patrón `if (!defined(...))` que el resto de las constantes del
plugin. Motivo (explicado por Mario): el chat anónimo SIEMPRE es el
proyecto de Botmaker de OEC — no tiene sentido que cada sitio de socio
lo configure, ya que solo se ofrece cuando el equipo de OEC atiende el
contacto (ver abajo). `class-oec-shortcodes.php` arma
`extra.botmaker_id` a partir de esa constante
(`defined('OEC_BOTMAKER_PROJECT_ID') ? OEC_BOTMAKER_PROJECT_ID : ''`),
no de `get_option()` — el Twig sigue leyendo `extra.botmaker_id` igual
que antes, así que no hizo falta tocar su nombre en ningún lado.

**Tabla de verdad completa** (calculada en el Twig,
`data/page-formacion-testing.html`, justo debajo de `wa_link`/
`chat_message`, en 4 variables: `showChatAnonimo`, `showWhatsappBlock`,
`showFloatWhatsapp`, `showFloatBotmaker`) — **actualizada 2026-09-18**
para sumar el estado de inscripción (`openEnrollment`, ya calculado
arriba en el mismo archivo) como segunda condición, ver sección propia
más abajo ("Chat anónimo también con inscripción cerrada"):

| ¿Atiende OEC? | Precio (`data.prices.total`) | ¿Inscripción abierta? | Bloque "Por chat anónimo" | Bloque "Por WhatsApp" | Ícono flotante |
|---|---|---|---|---|---|
| NO | (no aplica) | (no aplica) | ❌ | ✅ | WhatsApp |
| SÍ | < 200 | (no aplica) | ✅ | ❌ | Botmaker |
| SÍ | ≥ 200 | Cerrada | ✅ | ❌ | Botmaker |
| SÍ | ≥ 200 | Abierta | ✅ | ✅ | WhatsApp |

Nunca los dos íconos flotantes a la vez — son mutuamente excluyentes
por diseño (mismo lugar en pantalla, mismo tamaño, código de color
distinto). El bloque "Por chat anónimo" en Contacto, cuando aparece,
es indiferente al monto Y al estado de inscripción (siempre se ofrece
si atiende OEC) — el monto y la inscripción SOLO deciden cuál ícono
flota y si además se suma "Por WhatsApp" en Contacto.

**Nuevo ícono flotante `#oec-float-botmaker`**: mismo lugar/tamaño/
comportamiento que `#oec-float-wa` (ya existía) —
`css/oec-formacion.css` unificó ambas reglas bajo un selector
compartido (`.oec-float-wa, .oec-float-botmaker`) para no duplicar
posición/sombra/transición, y cada uno solo difiere en color de fondo
(`#25D366` fijo para WhatsApp, `var(--oec-brand)` para Botmaker — este
último no tiene un color de marca propio como WhatsApp). Abre el
mismo widget de Botmaker que el botón de la sección Contacto.

**JS (`js/oec-formacion.js`, `initBotmakerChat()`) generalizado a
MÚLTIPLES disparadores**: antes buscaba un solo botón por `id`
(`#oec-botmaker-cta`); ahora usa
`document.querySelectorAll('.oec-botmaker-trigger')` y engancha el
mismo handler a todos los que haya — hoy son dos (el botón de Contacto
y el ícono flotante nuevo), cada uno con su propio `data-msg` (mismo
`chat_message` del Twig en los dos). El resto del mecanismo (vigía de
tamaño del iframe, `bmHide`/`bmShow`/`bmMaximize`/`bmSendMessage`,
detección de cierre desde adentro del widget) no cambió — ver la
sección "Sección de Contacto — 3 canales" más abajo para el detalle
completo de todo eso.

**Bug real encontrado probando en vivo — el ícono flotante podía
quedar fuera de la pantalla, mucho más abajo del viewport**: en una
formación puntual (un webinar con el banner "EN VIVO" activo), el
`getBoundingClientRect()` de `#oec-float-botmaker` daba `y≈3400` con
un viewport de 768px de alto — invisible en la práctica, había que
scrollear miles de píxeles para encontrarlo. Causa: un `<div>` sin
clase, ancestro directo del ícono, tenía
`transform: matrix(1,0,0,1,-512,0)` puesto por ALGO ajeno a este
plugin (**CORRECCIÓN 2026-09-25: SÍ era del propio plugin** — el `transform: translateX(-50%)`
de `#oec-bleed-wrapper` en `full_bleed_wrap()`; ya no existe, ver "Blindaje v2"; no se identificó el origen exacto en ese momento — no es del propio plugin,
se revisó `oec-formacion.js`/`oec-frontend.js` completos buscando
`appendTo`/`insertBefore` y no hay nada que mueva estos elementos ahí)
— con `position:fixed`, un ancestro con `transform` se vuelve el marco
de referencia en vez del viewport, exactamente el mismo mecanismo ya
documentado en este archivo para `#oec-region-panel`/`#menu-sticky`/
`.oec-buscando-overlay`. Confirmado que NO es un bug general del sitio
(en otra formación sin el banner "EN VIVO", `#oec-float-wa` se
posicionaba perfecto, sin ancestro con transform) — algo puntual de
esa página en particular. Fix (mismo patrón ya establecido, aplicado
preventivamente en vez de perseguir el origen exacto del transform):
`$('#oec-float-wa, #oec-float-botmaker').appendTo(document.body);` al
principio del `jQuery(document).ready(...)` de `oec-formacion.js` — los
mueve a `<body>` apenas carga la página, inmunes a cualquier ancestro
con transform que aparezca en el árbol, venga de donde venga. Verificado
en vivo: mismo `getBoundingClientRect()` después del fix da `y≈692`,
dentro del viewport, botón visible y funcional (probado: click real
abre el widget de Botmaker con el mensaje predeterminado).

**Verificado en vivo, los 3 casos de la tabla completos** (en
`oec-test.local/es/`, toggleando `oec_equipo_ventas` en `wp_2_options`
para forzar cada escenario y restaurando el valor original al
terminar): caso "NO atiende OEC" (WA flotante + bloque WhatsApp, sin
rastro de Botmaker en el HTML), caso "SÍ atiende OEC + precio < 200"
(training real con `data.prices.total = 0`: flota Botmaker, bloque
chat sí/WhatsApp no), caso "SÍ atiende OEC + precio ≥ 200" (training
real con `data.prices.total = 516`: flota WhatsApp, los dos bloques de
Contacto). `php -l` en los 3 archivos PHP tocados, `node -c` en el JS,
balance de llaves del CSS — todo sin errores.

**Bug real encontrado por Mario al día siguiente — la "pelotita" de
Botmaker volvía a aparecer, pero SOLO en la SEGUNDA apertura del chat
en la misma carga de página**: con dos disparadores ahora en la misma
pantalla (la burbuja flotante y el botón de la sección Contacto,
ambos con la clase `.oec-botmaker-trigger`), Mario reportó: abrir con
la burbuja flotante y cerrar andaba perfecto, pero abrir DESPUÉS con
el botón de Contacto (mismo page load) y cerrar dejaba la pelotita
flotante de Botmaker con su mensaje predeterminado pegada en pantalla
(exactamente el bug "minimizado" que ya se había resuelto para el
PRIMER open/close, ver más arriba). Reproducido en vivo de forma
determinística: 1er ciclo (abrir+cerrar) siempre OK, 2do ciclo (abrir
con cualquier disparador+cerrar, en la misma carga de página) siempre
roto — no importaba cuál disparador se usara para el 2do ciclo.

Causa raíz (confirmada inspeccionando `closeBtn.dataset.oecBound`
antes/después de cada click, no adivinada): el listener de "Cerrar
chat" se pone con `{ once: true }` — se autoelimina solo después de
dispararse una vez. El vigía (`setInterval`, cada 400ms) evita poner
un segundo listener mientras `closeBtn.dataset.oecBound === '1'`,
asumiendo que Botmaker SIEMPRE recrea el botón (`document.write`) en
cada cambio de estado — pero en una REAPERTURA (no un cambio de estado
dentro de la misma sesión de chat, sino abrir de nuevo después de
haber cerrado antes), Botmaker a veces REUSA el mismo nodo `<button>`
de la vez anterior en vez de crear uno nuevo. Ese nodo reusado todavía
tenía `dataset.oecBound = '1'` de la primera apertura — pero el
listener `{once:true}` que puso ese flag ya se había disparado y
autoeliminado al primer cierre. Resultado: el vigía veía `oecBound`
en `'1'` y NUNCA volvía a enganchar un listener nuevo — clickear
"Cerrar chat" la segunda vez no hacía absolutamente nada,
`userWantsOpen` se quedaba en `true` para siempre, y el widget quedaba
visible en su estado "minimizado" (330×110, pelotita con la pregunta
default de Botmaker) sin que nada lo volviera a esconder.

Fix (`js/oec-formacion.js`, `initBotmakerChat()`): el propio handler
del click, cuando se dispara, ahora también limpia el flag
(`delete closeBtn.dataset.oecBound;`) ANTES de llamar a `setOpen(false)`
— así, si el mismo nodo sobrevive y se reusa en una futura apertura, el
vigía lo vuelve a ver "libre" y le engancha un listener fresco, sin
importar si Botmaker reconstruyó el DOM o reusó el nodo viejo.
Verificado en vivo con la MISMA secuencia que reportó Mario, 3 ciclos
seguidos en la misma carga de página (flotante → Contacto → flotante
de nuevo), inspeccionando `body.classList.contains('oec-bm-chat-open')`
y el `display` real del iframe antes/después de cada cierre — los 3
ciclos cerraron perfecto, sin ningún rastro de la pelotita. **Si este
bug vuelve a aparecer**, sospechar primero de este mismo mecanismo
(`dataset.oecBound` desincronizado del ciclo de vida real del
listener `{once:true}`) antes de perseguir otra causa.

## Estilo de "bloques" de la ficha — calcado del checkout real (2026-09-18)

A pedido de Mario, se alinearon bordes/fondo/radios/sombras de los
bloques grandes de la ficha (`.oec-card`, `.oec-rc`, `.oec-rc-sticky`,
`.oec-ac-item`) con el aspecto visual del checkout real
(`https://{comunidad}.onlineeducation.center/checkout3/{slug}`, un
front-end en React/Tailwind, ajeno a este repo). **No se adivinaron
los valores** — se inspeccionó el checkout en vivo (`getComputedStyle()`
sobre la tarjeta principal y las filas de opción de módulos) para
sacar los números exactos:
- Tarjeta principal del checkout: clases Tailwind `bg-white rounded-2xl
  border border-slate-200 shadow-sm` → computado:
  `border-radius: 16px`, `border: 1px solid #e2e8f0`,
  `box-shadow: 0 1px 3px 0 rgba(0,0,0,.1), 0 1px 2px -1px rgba(0,0,0,.1)`.
- Fila de opción de módulo: `rounded-xl border-2 border-slate-200` →
  `border-radius: 12px`, color de borde igual (`#e2e8f0`).

Aplicado en `css/oec-formacion.css`: `.oec-card` (todas las secciones:
Docentes, Presentación, Contenidos, Opiniones, Por qué elegir, Más
información, Contacto) y `.oec-rc`/`.oec-rc-header` (caja de precios no
sticky) con los mismos valores; `.oec-ac-item` (filas de acordeón — cada
docente, cada módulo) al mismo color de borde y `border-radius: 12px`
(antes 10px).

**Corrección posterior — `.oec-rc-sticky` (la versión que sigue al
scroll) NO debía tener una sombra distinta**: el primer intento le
puso a propósito una sombra más fuerte (`0 4px 16px`), asumiendo que
"flotar" pegado en el scroll necesita más elevación visual que un
bloque estático — supuesto propio, sin verificarlo contra la
referencia real. Mario lo marcó ("lo que no ha quedado bien... es
nuestro bloque sticky"); inspeccionando el checkout de nuevo, esta vez
su PROPIO "Resumen de compra" (también `position:sticky`,
`div.sticky.top-[135px]` envolviendo `bg-white rounded-2xl border
border-slate-200 shadow-sm`) — usa la MISMA sombra suave que cualquier
tarjeta normal, sin tratamiento especial por ser sticky. Se corrigió
`.oec-rc-sticky` al mismo `box-shadow` que `.oec-card`/`.oec-rc`
(`0 1px 3px 0 rgba(0,0,0,.1), 0 1px 2px -1px rgba(0,0,0,.1)`) — ya no
"inventar" un estilo de sticky que la referencia real no tiene.
**Aprendizaje**: al calcar el estilo de una referencia externa, si hay
una versión sticky/flotante de algo en la referencia, inspeccionar
ESA versión puntual también — no asumir que "sticky" implica
automáticamente una elevación visual distinta solo porque suena lógico.

**Bug de colisión encontrado de paso — borde AMARILLO al pasar el mouse
por CUALQUIER sección de la ficha**: `oec-wp-theme/style.css` tiene
`.oec-card:hover { border-color: var(--color-accent) !important; }`
(sección "Armonizar colores del plugin con el tema", ya documentada
varias veces en este archivo) — pensada para las tarjetas CLICKEABLES
del listado (`/formaciones`, donde `.oec-card` es un `<a>` y tiene
sentido un highlight de hover). El problema: en la FICHA, `.oec-card`
es la MISMA clase pero en un `<section>` no clickeable (Docentes,
Contenidos, Opiniones...) — la regla del tema no distingue entre los
dos contextos, solo por nombre de clase. Cuando el `--color-accent` del
tema cambió a amarillo (`#ffde59`, antes era naranja `#e8952a` — cambio
hecho por Mario en el tema, no en esta sesión), el efecto pasó de
"discreto" a "borde amarillo brillante en cualquier sección al pasar el
mouse", lo cual disparó el reclamo. Fix EN EL PLUGIN (no en el tema esta
vez, porque acá el problema es que dos contextos MUY distintos comparten
sin querer el mismo nombre de clase — no hay nada que "arreglar" del
lado del tema sin romper el hover útil del listado):
`#oec-top .oec-card:hover { border-color: #e2e8f0 !important; }` — el
selector con el id del wrapper de la ficha (`#oec-top`) le agrega
especificidad (id + clase + pseudo-clase) por encima de la regla del
tema (clase + pseudo-clase sola), así que gana siempre, sin importar el
orden de carga — un `!important` con MÁS especificidad le gana a otro
`!important` de menor especificidad, la regla de desempate por orden de
carga solo aplica cuando la especificidad es IGUAL. Verificado en vivo
con hover real (`getComputedStyle()` sobre la sección bajo el cursor):
`border-color` da `rgb(226, 232, 240)` (`#e2e8f0`, el gris del checkout)
en vez de amarillo.

**Más "aire" pedido en dos lugares — ambos eran valores fijos chicos,
subidos a mano**:
- `.oec-rc-sticky { top: calc(var(--oec-sticky-h, 76px) + 8px); }` →
  `+ 24px`. Antes casi no había separación visible entre la barra
  sticky del encabezado y la tarjeta sticky de precios/inscripción de
  la columna derecha al hacer scroll; verificado en vivo (con la
  pantalla ancha de sobra para que NO se dispare el JS que reubica
  `.oec-rc` a la columna izquierda en mobile — ver "Ojo con probar esto"
  más abajo): el hueco real entre las dos pasó de ~8px a ~24px.
- El offset de aterrizaje al clickear un link del menú sticky
  (`js/oec-formacion.js`, el handler de `.scroll-link`) pasó de
  `$t.offset().top - stickyH - 24` a `... - 40` — quedaba "pegado" justo
  debajo de la barra sticky del encabezado, sin margen de lectura.
  Verificado en vivo (`getBoundingClientRect()` del target vs. la barra
  sticky después de un click real): el hueco pasó de ~24px a ~40px.

**Ojo con probar esto en el Browser pane**: el ancho por default de la
pestaña del pane es angosto (~592-800px), por DEBAJO del breakpoint de
960px que usa `oec-frontend.js` para reubicar `.oec-rc` (la caja de
precios) de la columna derecha a un slot dentro de la columna
izquierda (comportamiento mobile intencional) — con el pane en su
ancho default, `.oec-rc-sticky` NUNCA se activa (el
`IntersectionObserver` que la muestra observa específicamente
`#oec-r-column .oec-rc`, que en mobile ya no existe ahí). Para probar
cualquier cosa de la columna derecha/sticky, forzar un viewport ancho
de verdad primero (`resize_window` con `width` > 960).

## Chat anónimo también con inscripción cerrada (2026-09-18)

Mario se acordó de un caso que faltaba en la lógica de contacto por
precio (ver "Sección de Contacto — chat anónimo hardcodeado + reglas
por precio" arriba): con inscripción CERRADA, WhatsApp (de ventas de
OEC) también tiene que esconderse a favor del chat anónimo, sin
importar el precio — motivo textual: "que nos cobra mucho por cada
conversación" y no tiene sentido pagar ese costo por una formación a
la que ya nadie se puede inscribir. Fórmula que dio Mario: "SI PRECIO
< 200 OR InscripcionesCerradas THEN Chat anónimo SÍ, WhatsApp NO".

**Importante — esto SOLO aplica dentro de la rama "SÍ atiende OEC"**:
cuando `extra.equipo_oec` es `false` (sitio de socio), WhatsApp usa el
número DEL SOCIO (`extra.ventas_whatsapp`), no el de OEC — ese costo
por conversación no es nuestro, así que esa rama no cambia (WhatsApp
sigue siendo la única opción ahí, con o sin inscripción abierta; nunca
hay chat anónimo en esa rama, Botmaker es exclusivamente el proyecto
de OEC). Tampoco tendría sentido "dejar el chat anónimo" en esa rama:
no existe ahí.

Cambio en `data/page-formacion-testing.html`, mismo bloque de 4
variables de siempre (usa `openEnrollment`, ya calculado más arriba en
el archivo — no hizo falta pedir ni calcular nada nuevo):
```twig
{% set showWhatsappBlock = not extra.equipo_oec or (data.prices.total >= 200 and openEnrollment) %}
{% set showFloatWhatsapp = showWhatsappBlock %}
{% set showFloatBotmaker = extra.equipo_oec and extra.botmaker_id and (data.prices.total < 200 or not openEnrollment) %}
```
(`showChatAnonimo` no cambió — ya era indiferente al precio, y ahora
sigue siendo indiferente al estado de inscripción también, por la
misma razón: se ofrece siempre que atienda OEC).

Verificado en vivo con una formación real con inscripción CERRADA y
precio exactamente en el límite (`data.prices.total = 200`, que ANTES
de este cambio hubiera mostrado WhatsApp por no ser `< 200`): con el
fix, flota Botmaker (no WhatsApp), bloque "Por chat anónimo" sí,
bloque "Por WhatsApp" no — confirma que el `OR inscripción cerrada`
gana aunque el precio por sí solo no lo hubiera activado. Re-verificado
también el caso sin cambios (inscripción abierta, precio 516): sigue
mostrando WhatsApp normal, sin regresión.

**Sincronizado**: `wp_2_posts` ID 8 (multisite) y `data/page-formacion.txt`.

## Link "incluida en" sin `/es/` + countdown viejo eliminado (2026-09-18)

**1. Link de "Esta formación está incluida en" daba 404 en multisite**:
el Twig armaba `<a href="/formacion/{{ p.slug }}">` — ruta absoluta sin el
prefijo del subsitio (`/es/`, `/en/`), que apunta a la raíz de la red. Fix:
`{{ extra.detail_url }}{{ p.slug }}` (`detail_url` ya es
`get_site_url() . '/formacion/'`, multisite-aware, y ya lo usaban el listado
y "formaciones alternativas"). Verificado en vivo: los links ahora salen como
`http://oec-test.local/es/formacion/...`. Los demás `/formacion/` en PHP usan
`home_url()`/`get_site_url()` (bien); el de `class-oec-admin.php` es solo
código de ejemplo dentro de un `<pre>` de la pantalla de docs.
**Pendiente detectado**: la landing vieja del plugin tenía el mismo problema en sus
`more-url` — resuelto (hoy `more-url` pasa por `resolve_site_url()`).

**2. Countdown viejo de la caja de "descuento por pago anticipado"
reemplazado por el componente nuevo, y el viejo eliminado por completo**:
la caja usaba `<div class="timer oec-disc-timer" date=".." startphrase=..
endphrase=..>` con texto plano ("Quedan 7 Días, 5 Hs., 56 Min., 00 Seg. para
aprovechar.") armado por `makeTimer()`. Ahora usa el mismo
`.oec-countdown` + `.oec-cd-unit/-num/-label` (misma animación de "tic",
mismo `updateCountdowns()`) que el resto de la ficha, con la variante
`.oec-countdown--disc` (rojo de urgencia como el texto viejo, prefijo
"Quedan", `flex-wrap` para pantallas angostas). Eliminado: `makeTimer()` y sus
llamadas (`oec-formacion.js`; el `setInterval` ahora llama solo a
`updateCountdowns`), y en `oec-formacion.css` las reglas `.timer`,
`.oec-disc-timer` y `.oec-cal-timer` (esta última ya estaba muerta, sin uso en
Twig/JS). Ya no queda ningún `.timer` en JS/CSS/Twig. Misma regla de
visibilidad de siempre: el countdown aparece solo con 15 días o menos.
Verificado en vivo con una formación real con parent + descuento anticipado
(la del listado local: `diseno-mesociclos-hipertrofia-...`): `typeof
makeTimer` es `undefined`, cero elementos `.timer`, y con la fecha ajustada a
7 días se ve "Quedan 07 Días 05 Hs 55 Min 51 Seg" tickeando dentro de la caja.
Sincronizado a `wp_2_posts` ID 8 y `data/page-formacion.txt`.
**Aprendizaje**: para buscar formaciones de prueba con una característica
puntual, escanear las URLs del listado con `curl -sL` — SIN `-L`, las URLs de
ficha sin barra final devuelven un 301 vacío y el escaneo "no encuentra nada".

## `og:image`/`twitter:image` sin pasar por imgrsize (2026-09-22)

Mario detectó (imagen del `<head>` en Safari) que la ficha de formación mandaba `og:image`/
`og:image:secure_url`/`twitter:image` con la URL CRUDA que manda la API
(`https://static1.onlineeducation.center/uploads/campus/...`, el archivo original) en vez de
pasar por `imgrsize.oe-img.center` (sistema propio de resize/caché) como el resto de la ficha
(hero, JSON-LD `Course`, thumbnails de tarjeta...). Causa: `oec_seo_and_stars_metadata()`
(`oec-main.php`) usaba `$data['image']` tal cual — el único lugar del plugin que no pasaba una
imagen de la API por `imgrsize`.

Fix: `oec_build_display_image_url($image, $width=1200, $quality=89)` (`oec-main.php`, al lado de
`oec_build_color_thumb_url()`, mismo patrón — extrae el filename de la URL de la API con
`explode('/')`/`end()` y arma la URL de `imgrsize.oe-img.center/campus/capacitacion/imagen/...`)
con `&format=webp`, igual que ya usa el JSON-LD `Course`/el hero en
`data/page-formacion-testing.html`. **No se reusó `oec_build_color_thumb_url()` tal cual** —
esa función sirve para el análisis de color dominante
(`OEC_Api::compute_dominant_color_from_bytes()`) y no lleva `format=webp` a propósito. `$img` en
`oec_seo_and_stars_metadata()` ahora sale de `oec_build_display_image_url($data['image'])`.

Verificado en vivo: las 3 meta tags de una ficha real ya no traen `static1.onlineeducation.center`
en ningún lado del HTML, la URL de `imgrsize` devuelve 200 (jpg o webp según el `Accept` del
pedido — comportamiento normal de ese CDN, no algo nuevo). `php -l` sin errores.

**El error de consola de Safari en la misma captura (`SyntaxError: Can't create duplicate
variable: 'SafariAppExtensionPage'`) NO es del plugin** — ese string no aparece en ningún archivo
del repo, y el plugin no usa `document.write()` en ningún JS propio (las únicas menciones son
comentarios sobre el comportamiento INTERNO de Botmaker). Es el identificador de un content
script que inyecta una extensión de Safari del propio Mario (bloqueador de anuncios, gestor de
contraseñas, etc.) — se reinyecta y choca consigo misma, ajeno a esta página. No se tocó nada al
respecto.

## Ficha: "Chat directo", Botmaker diferido, íconos, barra de compartir (2026-09-24)

- **"Chat anónimo" → "Chat directo"** en todo el copy visible (título "Por chat directo", botón
  "Iniciar chat", `aria-label` del ícono flotante "Chatear con nosotros"). Variable Twig
  renombrada `showChatAnonimo` → `showChatDirecto` (Twig + comentario del JS). De paso se corrigió
  voseo que había quedado en esos dos bloques ("podés", "Arrancás" → "puedes", "Empiezas").
- **Botmaker se carga recién ante intención del usuario** (`initBotmakerChat()`, `loadBotmaker()`):
  el Twig ya no inyecta el `init.js`, solo expone `window.OEC_BOTMAKER_SRC`. El script se inyecta
  en el primer `pointerenter`/`focus`/`touchstart` sobre un `.oec-botmaker-trigger` (precalienta) o
  en el click. Motivo: el widget es una app React de ~206 KB comprimidos + 4 familias de Google
  Fonts + polyfill, y antes se descargaba en CADA visita a la ficha. El vigía de tamaño arranca
  recién al inyectar (`startHideWatcher()`), con 75 intentos (15 s). Mientras carga, el disparador
  lleva `.oec-bm-loading` (cursor de espera + opacidad). El CSS que esconde el envoltorio del iframe
  no cambió. Verificado en vivo: 0 pedidos a Botmaker al cargar; click en frío → chat abierto con
  el mensaje predeterminado en ~1,3 s; cerrar/reabrir/cerrar sin que quede la pelotita.
- **`bootstrap-icons` se encola desde el plugin** en la ficha (`wp_enqueue_style('bootstrap-icons', ...)`,
  mismo handle que `oec-wp-theme` → WordPress lo carga una sola vez; en un tema que no lo trae,
  llega igual en el `<head>`). Se sacaron los `<link rel=preload>`/`<noscript>` de las primeras
  líneas de `page-formacion-testing.html` (quedaban en el `<body>`, descubiertos tarde y
  duplicados). El listado y la landing todavía tienen sus propios `<link>` en el Twig (inofensivo,
  misma URL).
- **Sticky de inscripción (`.oec-rc-sticky`)**: el `IntersectionObserver` observaba
  `#oec-r-column .oec-rc`; si la página cargaba por debajo de 960px (el JS muda `.oec-rc` a la
  columna izquierda) y después se agrandaba la ventana, no se enganchaba nunca → columna derecha
  vacía al scrollear. Ahora observa `.oec-rc` a secas.
- **Barra de compartir en el hero** (`.oec-share`, debajo de las estrellas): WhatsApp / LinkedIn /
  X / Facebook / copiar enlace + "Más" (share nativo, solo mobile y si existe `navigator.share`).
  Misma lógica que `oec-wp-theme/assets/js/share.js` pero con clases propias `oec-share-*`
  (`initShareBar()`, `oec-formacion.js`; estilos para fondo oscuro al final de `oec-formacion.css`).
  Comparte `extra.current_url` (la URL de ESTE sitio, no el canonical) y empuja el evento `share`
  a `dataLayer` (`content_type: course`, `item_id: data.id`).
- **Performance medida (local)**: servidor ~1,1 s en frío, todo espera de la API (4 pedidos en
  paralelo en `oec_get_page_bundle()`); con caché ~26 ms de Twig. Navegador: 38 pedidos, CLS 0,
  40 de 42 imágenes lazy. Los pedidos del navegador a `api.onlineeducation.center` (precios y
  medios de pago por país) y `api.g-se.com/initialPreferences` son por visitante y tienen que
  quedarse client-side (la página va cacheada en Cloudflare). Lo más pesado que queda: la fuente
  de `bootstrap-icons` (~130 KB woff2 para ~40 íconos) — un subset/SVG inline ahorraría ~120 KB en
  sitios cuyo tema no la use; no hecho.
- **Hallazgo de conversión (no tocado, propuesto a Mario)**: en 1366×768 el precio final
  (y≈955) y el botón "Iniciar inscripción" (y≈1078) de la columna derecha quedan debajo del
  pliegue — la caja arranca con fechas/calendario/lista de módulos antes del precio y el CTA.

**Sincronizado**: `wp_2_posts` ID 8 (la base coincidía byte a byte con el `.txt` anterior, sin
cambios manuales) y `data/page-formacion.txt`.

## Ficha: CTA en el hero, urgencia, "Más información" con íconos, citas (2026-09-24)

Pedido de Mario (de una lista de 6 propuestas eligió estas 4; **el hero NO se puede achicar** —
ediciones/alumnos/docentes/horas/certificados/avales se quedan todos):
- **Botón "INICIAR INSCRIPCIÓN" en el hero** (`.oec-hero-cta`, dentro de `.oec-hero-right`, debajo de
  los anillos, `align-self: stretch` → mismo ancho que la fila de anillos, `min-width: 280px`). Motivo:
  en 1366×768 el botón de la columna derecha quedaba ~300 px debajo del pliegue. Mismo link
  (`enrollmentLink`) y `oec_metaAddToCart()` que los demás. Debajo, una línea de precio que completa
  `getRegPrices()` por país reusando las MISMAS clases que la barra mobile: "Desde
  `.price-module-N` por módulo · X% off pagando todo" (multi-módulo), `.total-price` + "X% off"
  (un módulo con descuento), `.just-price` (sin descuento) o "Gratis" (`data.prices.total <= 0`). El %
  sale de `early_payment` si está vigente, si no de `full_payment`. No se muestra con
  `force_contact`, y como vive dentro de `.oec-hero-right` tampoco con inscripción cerrada. Oculto en
  ≤960px (ahí ya está `#oec-mobile-bar`). No agrandó el hero: la columna derecha tenía aire de sobra.
- **Anillo del countdown en modo urgente** (`.oec-hero-ring--urgent`, cuando `cd_daysLeft <= 7`):
  trazo ámbar `#f59e0b`, etiqueta `#fcd34d` en negrita y un pulso. El pulso va en
  `.oec-hero-ring-circle::after` porque el `box-shadow` del círculo tiene `!important` (blindaje) y un
  `!important` le gana a cualquier `@keyframes` — animar el `box-shadow` del propio círculo no hacía
  nada. Respeta `prefers-reduced-motion` (anillo fijo, sin pulso).
- **"Más información"**: ícono por ítem (`.oec-ac-ico`, cuadrado 36px con tinte de `--oec-brand`, se
  rellena en hover/abierto): Organizador `bi-building`, Destinado a `bi-people-fill`, Descripción
  completa `bi-file-earmark-text`, Certificados `bi-award-fill`, Avales `bi-patch-check-fill`,
  Requisitos `bi-list-check`, Metodología `bi-clock-history`. **"Destinado a" arranca abierto**
  (`is-active` + `aria-expanded="true"` en el Twig; el acordeón hace toggle, así que cerrarlo anda
  igual). Título pasó de "Destinado a:" a "Destinado a". El FAQ JSON-LD no cambió.
- **Citas dentro del texto de la API** (Presentación y cuerpos de acordeón, `.oec-prose`): el editor de
  OEC marca las citas como `<p style="margin-left:40px"><em>…</em></p>` (ej. la de Rugby) — se veía
  como un bloque en cursiva corrido a la derecha. Ahora `.oec-prose p[style*="margin-left"]` y
  `.oec-prose blockquote` se muestran como "cita destacada" (borde izquierdo de marca, fondo con
  tinte 6%, texto `#1f2937` 16,5px). El `!important` del `margin` le gana al `style` inline del editor.
- De paso: "Estudie a su ritmo" (usted) → "Estudia a tu ritmo" en Metodología.

Verificado en vivo (Holway y Rugby, 1366×768 y 375px): CTA con precio real por país, anillo urgente en
ámbar ("6 días"/"2 días"), "Destinado a" abierto y el toggle funcionando, la cita sin desbordar en
mobile, CTA oculto en mobile, sin scroll horizontal. Sincronizado a `wp_2_posts` ID 8 y
`page-formacion.txt`.

## Ficha: ajustes al CTA del hero + flecha en todos los botones de inscripción (2026-09-24)

- **Texto del precio del hero**: "Desde X por módulo" era engañoso (los módulos pueden tener precios
  distintos) → **"ARS X el 1er módulo"** (`.price-module-N` del primer módulo). Si el 1er módulo es
  gratis → **"El 1er módulo es gratis"**, decidido en el Twig con el precio BASE de la API
  (`data.prices.data[N].amount <= 0` — un módulo que vale 0 vale 0 en cualquier país, no hace falta
  esperar a los precios por país del navegador). Ejemplo real: `especialista-hipertrofia-basada-evidencia-t-j6a95eeec9dd17`.
- **Nombre del descuento**: el anticipado (`early_payment`, vigente) es **"X% off pagando ahora"**; el de
  pago completo (`full_payment`, solo con más de un módulo) es **"X% off pagando todo"**. Antes los dos
  decían "pagando todo". Variables `heroDiscPct`/`heroDiscText` en el Twig.
- **Ancho fijo del botón del hero**: 400px siempre (= 4 anillos = ancho de la columna de precios; queda
  alineado con ella), sin depender de cuántos anillos haya. En desktop `.oec-hero-right` pasó a
  `align-items: center` → con 2-3 anillos, los anillos se centran sobre el botón.
- **Flecha + hover en TODOS los botones "INICIAR INSCRIPCIÓN"** (caja de precios, sticky, barra mobile y
  el del hero): clase compartida `.oec-enroll-arrow` + `<i class="bi bi-arrow-right">`. Hover: sube 1px,
  sombra azul (`color-mix` de `--checkout-blue`), la flecha se corre 3px. Respeta
  `prefers-reduced-motion`. Verificado que la barra mobile no salta de línea ni en 320px.
- **Bug encontrado de paso (introducido con el CTA del hero)**: el JS que agrega `affcod` (código de
  afiliado) a los links de inscripción solo miraba `.oec-btn-enroll/.oec-mb-enroll/.oec-sticky-enroll`
  → una inscripción desde el botón del hero perdía la atribución al afiliado. Se sumó
  `a.oec-hero-cta-btn` al selector. **Si se agrega otro botón de inscripción, sumarlo ahí también.**
- **`getRegPrices()`**: un módulo que vale 0 ahora se muestra como "GRATIS" (antes "ARS 0") en todos
  lados que usan `.price-module-N` (lista de módulos, sticky, barra mobile).

Sincronizado a `wp_2_posts` ID 8 y `page-formacion.txt`.

## Blindaje v2 — el plugin a prueba de CUALQUIER tema (2026-09-25)

Pedido de Mario: el plugin se instala en sitios con Divi, Avada, Hello/Elementor, Astra… que no
manejamos; la ficha (`[oec-content]`) y los listados (`[oec-list]` y compañía) tienen que verse y
funcionar igual en todos. (Con `oec-wp-theme` los listados y las landings los arma el TEMA con sus
propios shortcodes — `[oec-tira]`, `[oec-docentes]`, `[oec-cierres]`… en `oec-wp-theme/inc/` — pero
reutilizando clases y CSS del plugin: `.oec-card`, `.oec-scroll-section`, `.fecha-*`. Ojo al tocar
esas clases.) El blindaje v1 (más arriba) había puesto `!important` solo en lo que el plugin YA
declaraba; todo lo que nunca fijó quedaba a merced del tema.

**Método (repetible): `tests/hostile-theme.css` + `tests/hostile-audit.js`** (no se encolan nunca).
El CSS imita reglas típicas de temas sobre etiquetas sueltas, con especificidad de ID
(`:is(body,#x) h2`), `html{font-size:62.5%}`, `*{box-sizing:content-box}`, `li::before` con
flechitas, botones/inputs/img/section estilados… El JS, pegado en la consola (o vía
`fetch` + `eval` en el Browser pane), inyecta esa hoja y compara el estilo calculado de CADA
elemento del plugin con la hoja activada vs. desactivada, sobre el mismo DOM:
`await oecHostileAudit()` → `{diffs, sizeDiffs, byProp, samples}`. También trae
`oecSnapshot()` / `oecCompareSnapshots(a,b)` / `oecReloadPluginCss()` para verificar que un
cambio de CSS no altere el look actual (recarga solo el CSS, sin recargar la página).
Ojo: no usa `requestAnimationFrame` (no corre con el panel oculto).
**Resultado**: ficha 11.283 diferencias → **0** (desktop y 375 px, con acordeones y "leer más"
abiertos, 1.400-2.060 elementos); listado 3.301 → **0**; tamaños que cambiaban 711 → 0.

**1. Capa de aislamiento al principio de cada CSS** (`oec-formacion.css`, raíces = `#oec-bleed-wrapper`
+ lo que el JS muda a `<body>`; `oec-formaciones.css`, raíz = `.oec-scope`):
- Todo dentro de `:where(...)` (especificidad 0: cualquier regla propia del plugin le gana) y con
  `!important` (le gana a cualquier regla NORMAL del tema, tenga la especificidad que tenga).
- Solo propiedades que el JS no toca (nunca display/visibility/opacity/width/position/flex/max-height).
- Las raíces fijan lo que antes se heredaba del `<body>` del tema (fuente del sistema, 16px,
  interlineado 1.7, color `#1e2d3d` — los valores exactos que tenía `oec-wp-theme`, así el look no
  cambió) + margin/padding/borde/fondo en 0 (la barra sticky es un `<nav>` raíz: un tema que estila
  `nav` le metía 80px de padding).
- Heredables → `inherit` (no un valor fijo) para no cortar la herencia del propio diseño.
- Etiquetas típicas de tema (h1-h6, p, ul, li, a, button, input, img, section, span…) → margin/padding/
  borde/fondo/sombra/decoración en 0; `a` → `--oec-brand`; `hr` visible; inputs fondo blanco;
  botones/inputs con `line-height: normal` (heredar el 1.7 los agrandaba 7-8px); `min-width/min-height`
  → `auto` (no 0: rompía cómo se achican ítems flex).
- Pseudo-elementos de tema (`li::before{content:'→'}`, comillas, adornos) → `content:none` en esas
  etiquetas. Los `content` propios del plugin pasaron a `!important` (si no, esta capa los mataba).
- **Estilos en línea**: un `!important` del CSS le gana a un `style="…"` normal. Dos casos:
  (a) el Twig — los ~15 `style` que chocaban llevan ahora su propio `!important` en línea (le gana a
  todo); (b) el HTML de la API (`.oec-prose`, `.oec-subject`, `.oec-teacher-org`, `.oec-methodol`: el
  editor de OEC pone cosas como `<p style="font-size:1.5em;font-weight:bold">`) queda FUERA de la capa
  con `!important` y tiene su propia capa SIN `!important` pero con doble ID (`#oec-bleed-wrapper#oec-bleed-wrapper`,
  especificidad 2,1,0): le gana a las reglas de tema típicas y el estilo en línea del editor le sigue
  ganando a ella. Verificado: el titular de Holway sigue en 22,5px negrita.
- **Pasada de `!important` repetida** (reglas agregadas después del blindaje v1 lo habían perdido, ej.
  `margin-left:auto`) + sumadas `min-width`/`min-height`/`vertical-align`/`background-image` (el JS no
  las toca). Si se agrega una regla nueva con estas propiedades, va con `!important`.
- **Ojo con `max-width` en imágenes**: la capa fija `img, svg, video, iframe { max-width: 100% !important }`,
  así que una regla propia de tamaño máximo de imagen SIN `!important` pierde en silencio. Pasó con
  `.oec-cert img { max-width: 120px }` (miniatura de certificado): las imágenes pasaron a ocupar ~310 px
  (lo vio Mario, 2026-09-26). Ahora lleva `!important`; un chequeo en vivo de todas las reglas del
  plugin (`max-width`/`max-height`/`width`/`height` sin `!important` sobre img/svg/video/iframe) no
  encontró otro caso. Una regla nueva de ese tipo va con `!important`.
- **Ojo con las animaciones**: un `!important` le gana a cualquier `@keyframes`. La capa fija
  `box-shadow: none !important` en los `<span>`, y eso apagó en silencio el pulso de las píldoras
  destacadas del hero (`.oec-tip.destacado`, `oec-tip-pulse`) — lo detectó Mario, la auditoría no
  (desactiva animaciones para medir). Se pasó el pulso a un `::after` propio (mismo arreglo que el
  anillo urgente). Si se agrega una animación, animarla sobre una propiedad/elemento que la capa no
  fije (opacity, transform, o un pseudo-elemento propio).
- Excepciones medidas y aceptadas vs. el look anterior: botones con la fuente del plugin (antes Arial
  13,3px del navegador, porque el tema no normaliza botones), `strong` 700 (el tema forzaba 900),
  márgenes en línea del Twig que el viejo `p{…!important}` global pisaba sin querer ahora se respetan.

**2. Fugas del plugin HACIA el tema, eliminadas**: `a{text-decoration:none!important}` y
`p{margin:0 0 8px!important}` globales (le sacaban el subrayado a TODOS los links del sitio y le
forzaban márgenes a todos sus párrafos en la ficha), `.hidden{display:none}` (sin uso),
`#oec-bleed-wrapper ~ *` (no matcheaba nada). En `oec-formaciones.css`, los selectores con nombres
genéricos quedaron acotados (los de filtros ya no existen desde 2026-09-26);
`.fecha-*` y `.enrollment-closed` → `.oec-card …` (**no**
`.oec-scope`: el tema los usa en sus propias tarjetas — verificado, 47 fechas en la landing).

**3. `.oec-scope`**: PHP envuelve la salida de `[oec-list]` en `<div class="oec-scope">`
(`scope()`, en el `add_shortcode`), con el color de marca en línea (`--oec-brand`). Da una raíz
única para la capa de aislamiento.

**4. Ancho completo de la ficha (`full_bleed_wrap()`) rehecho**: antes `width:100vw; left:50%;
transform:translateX(-50%)` + `html,body{overflow-x:visible!important}`. Tres problemas: (a) ese
`transform` era el que rompía todo lo `position:fixed` de adentro (la "causa ajena" que nunca se
había encontrado); (b) `100vw` incluye la barra de scroll → scroll horizontal en Windows; (c)
`overflow-x:visible` destapaba desbordes del TEMA (menús fuera de pantalla → scroll horizontal en
todo el sitio). Ahora: un `<script>` en línea que corre antes de pintar mide la posición real del
contenedor del tema y `documentElement.clientWidth` → `--oec-bleed-ml`/`--oec-bleed-w` (funciona
con sidebar/contenedor no centrado; sin JS cae a `calc(50% - 50vw)`), sin `transform`, y
`html,body{overflow-x:clip}` (recorta igual que hidden pero no rompe `position:sticky`).

**5. JS**:
- `oec-formacion.js` entero en una IIFE; solo expone `oec_get_reviews`/`oec_metaAddToCart` (los usa el
  Twig). Arranca con el DOM listo; si `OEC_TRAINING_DATA`/`OEC_CONFIG` todavía no existen (un
  optimizador tipo WP Rocket/Autoptimize demoró el `<script>` en línea) reintenta en `load` y si no,
  no hace nada en vez de tirar errores. Las inicializaciones del `ready` van con `oecSafe()`
  (try/catch): una falla ya no corta las siguientes (precios, región, afiliados…).
- **Bug de multisite arreglado**: el formulario "Más información" y créditos posteaban a
  `/wp-admin/admin-ajax.php` fijo → en `/es/` caía en el sitio RAÍZ de la red, con su configuración
  (token, equipo de ventas). Ahora `window.OEC_AJAX_URL = admin_url('admin-ajax.php')` vía
  `wp_add_inline_script('oec-formacion', …, 'before')` y `oecAjaxUrl()` lo usa.
- **Sticky del tema**: `position:sticky` (caja de precios) no funciona si un ancestro tiene
  `overflow:hidden` (Divi y otros lo ponen en sus envoltorios). `unblockStickyAncestors()` pasa esos
  ancestros a `overflow:clip` (+ `display:flow-root` si eran block, para no perder el contexto de
  formato que daba hidden). Solo `hidden`, nunca `auto/scroll` (podría ser el que scrollea la página).
  Verificado simulando `main{overflow:hidden}`: sin el arreglo el sticky se iba (−533 → −1033px), con él
  queda fijo.
- **Header fijo del tema / barra de admin de WP**: `oec-frontend.js` mide en cada scroll qué hay pegado al
  borde superior que NO sea del plugin (fixed/sticky, y≤1, <40% de la pantalla) →
  `--oec-top-offset`; lo usan el `top` de la barra sticky, el de la caja de precios y el scroll de los
  tabs. Dinámico: si el header del tema se achica/esconde al bajar, la barra lo sigue. Verificado con
  un header simulado de 80px.
- **Encolado tardío**: con page builders (Elementor) el shortcode no está en `post_content` y
  `has_shortcode()` no lo ve → el listado quedaba sin CSS/JS. `scope()` llama a
  `enqueue_list_assets()` al renderizar (idempotente). Incluye `bootstrap-icons` (mismo handle que el
  tema). Verificado con un shortcode "alias" que genera `[oec-list]` desde un mu-plugin temporal.

**Estado de la landing en la base**: `wp_2_posts` ID 317 ya NO es la landing del plugin — Mario la
rehízo con los shortcodes del tema (33.379 bytes). El archivo de referencia del formato viejo se
borró el 2026-09-26.

**Sincronizado**: ficha → `wp_2_posts` ID 8 + `page-formacion.txt`; listado → ID 7 + `page-formaciones.txt`
(la página 7 igual la renderiza el tema). Páginas de prueba temporales (IDs 454/455) y mu-plugins de
prueba borrados.

## Ficha: "Más información" en preguntas + FAQPage fiel + breadcrumb visible (2026-09-25)

Origen: una auditoría SEO (hecha desde el proyecto del tema) marcó que la ficha declaraba un
`FAQPage` con preguntas que no se veían y un `BreadcrumbList` sin breadcrumb visible — Google pide
que lo marcado sea visible y coincida con la página. Resultó cierto a medias: 6 de las 8 preguntas sí
estaban (dentro del acordeón, que para Google cuenta como visible), pero `objetives` ("¿Qué vas a
aprender?") y `graduate_profile` ("¿Qué vas a poder hacer al terminar?") iban SOLO en el JSON-LD.

- **Una sola fuente para las preguntas** (`data/page-formacion-testing.html`, antes de la sección):
  `faq_q` (texto de cada pregunta) y `faq_has` (¿el dato viene con contenido real?). El título visible
  de cada ítem del acordeón (`<h3>{{ faq_q.x }}</h3>`) y el `name` del JSON-LD salen de la misma
  variable, con la misma condición → no pueden quedar distintos.
- **`faq_has` trata como vacío** `null`, texto en blanco y HTML sin texto (`<p></p>`, `&nbsp;`) —
  `objetives`/`graduate_profile`/`requirements`/`target_audience` son opcionales para el organizador
  (medido en 30 formaciones abiertas: `objetives` en 19, `graduate_profile` en 12). Sin dato: no hay ni
  ítem ni pregunta.
- **Ítems nuevos**: "¿Qué vas a aprender?" (`bi-lightbulb-fill`) y "¿Qué vas a poder hacer al
  terminar?" (`bi-mortarboard-fill`), después de "¿A quién está dirigida…?" (que sigue abierto por
  defecto). Cuerpo `.oec-ac-body.oec-prose` (región de HTML de la API para la capa de aislamiento).
- **Títulos** (tuteo, "esta formación" porque hay cursos, diplomados, webinars, certificaciones…):
  ¿Quién organiza esta formación? · ¿A quién está dirigida esta formación? · ¿Qué vas a aprender? ·
  ¿Qué vas a poder hacer al terminar? · ¿En qué consiste esta formación? · ¿Qué certificado vas a
  recibir? · ¿Qué avales científicos tiene esta formación? · ¿Qué requisitos necesitas para
  inscribirte? · ¿Cómo son los horarios y la metodología?. "¿Quién organiza…?" se sumó también al
  JSON-LD (antes era visible y no estaba marcado). La sección sigue titulada "Más información".
- **Contexto honesto para decidir cosas acá**: desde 2023 Google solo muestra resultados enriquecidos
  de FAQ para sitios de gobierno/salud, así que esto no trae "desplegables" en Google; el valor es AEO
  (ChatGPT/Perplexity/AI Overviews buscan pares pregunta-respuesta), escaneo del usuario y no tener
  marcado que no coincida con lo visible.
- **Breadcrumb visible** en el hero, primer elemento de `.oec-hero-left` (se ve también con
  inscripción cerrada): `<nav class="oec-breadcrumb">`, ítems de `extra.breadcrumb`.
  `oec_training_breadcrumb_items($data)` (`oec-main.php`) es la ÚNICA fuente para el visible y para el
  `BreadcrumbList` JSON-LD (`oec_breadcrumb_jsonld()`). Cambios de paso: "Formaciones" solo aparece si
  el sitio tiene una página publicada con slug `formaciones` (con su título real; antes se armaba igual
  `/formaciones`, 404 en un sitio sin esa página), y el último ítem va sin URL (Google lo permite; antes
  apuntaba al canonical, que puede ser OTRA comunidad).
- **Gotcha de CSS encontrado**: en mobile `.oec-hero-left` toma el ancho de su contenido, y el
  breadcrumb en una sola línea (sin cortes) la ensanchaba a 475px en un celular de 375 → título y
  píldoras cortados. `contain: inline-size` en `.oec-breadcrumb` hace que su ancho no cuente para el
  de la columna; ahora ocupa lo disponible y el último tramo se corta con "…". Tenerlo en cuenta para
  cualquier otro elemento `white-space: nowrap` que se agregue a esa columna.

Verificado en 4 formaciones reales (con objetivos y perfil / solo objetivos / solo perfil / ninguno):
preguntas del JSON-LD idénticas a los títulos visibles, ninguna respuesta vacía, JSON válido,
breadcrumb visible = JSON-LD, 0 errores; tema hostil 0 diferencias (desktop y mobile). Sincronizado a
`wp_2_posts` ID 8 y `page-formacion.txt`.

## Mobile: certificados apilados + tarjetas de docente más prolijas (2026-09-26)

- **Certificados** (`@media max-width:600px`): `.oec-cert` en columna — miniatura arriba a 150px
  (NO a ancho completo, a pedido: las imágenes de certificado no son lindas) y el texto abajo a todo
  el ancho (antes quedaba en una columna de ~137px). En escritorio siguen al lado, a 120px
  (`max-width: 120px !important`, ver "Ojo con `max-width` en imágenes" en Blindaje v2).
- **Tarjeta de docente en mobile**: el "+" / "−" sale del flujo (`position:absolute`, esquina superior
  derecha, vía `.oec-ac-btn:has(> .oec-teacher-row)`) — antes le comía ancho a la fila y la foto y el
  texto quedaban corridos a la izquierda, con el ícono flotando a media altura. Ahora quedan centrados
  en toda la tarjeta (medido: 0px de desvío). Foto 64px en las dos variantes, nombre 16px (la "lg"
  llegaba a 18px), afiliación 13px, padding 18px/40px.
- **Sección Docentes plegada**: el `.oec-collapsible` genérico pliega a 280px; en mobile 2 docentes ya
  miden ~320px, así que la 2da tarjeta quedaba cortada al medio con dos "+" juntos. Para el bloque de
  docentes (el wrapper que contiene `.oec-teacher-row`) el umbral ahora es 440px
  (`js/oec-formacion.js`, COLLAPSIBLE); el resto de los bloques sigue en 280px.

Verificado en mobile (375px) en la de Fisiología (2 docentes, bio abierta/cerrada) y en Holway
(certificados), sin scroll horizontal; tema hostil 0 diferencias. Solo CSS/JS, sin sync a la base.

## v1.3.2 — padding mobile 18px + barra de inscripción que se va al llegar al footer (2026-09-29)

- `@media (max-width: 600px)`: `.oec-container` y `.oec-hero-content` pasaron de 14px a **18px** de
  padding lateral.
- `#oec-mobile-bar` se desvanece y baja (`.oec-mobile-bar--away`: opacity 0 + `translateY(100%)` +
  `visibility:hidden` con demora + `pointer-events:none`) cuando el final de `#oec-bleed-wrapper`
  sube por encima del borde inferior de la pantalla, es decir, cuando empieza a verse lo que el tema
  pone debajo (footer). Vuelve al subir. JS al final de `js/oec-frontend.js` (listener de scroll +
  resize, sin rAF). Si la ficha es lo último de la página, la barra nunca se va. Verificado en 375px:
  "Términos de uso" del footer quedaba tapado por la barra y ahora recibe el click.
- **Cómo se publica una versión** (Plugin Update Checker con `enableReleaseAssets()`): subir
  `Version:` en `oec-main.php`, commit + push a `main`, y un release de GitHub con tag `vX.Y.Z` y el
  asset `oec-wordpress-plugin.zip` — carpeta `oec-wordpress-plugin/` con los archivos versionados en
  git MENOS `CLAUDE.md`, `tests/` y `.gitignore` (mismo contenido que el zip de v1.3.1). Los sitios lo
  ven en Plugins → "Hay una nueva versión".

## v1.3.3 — el plugin aguanta una caída de la API de OEC (2026-09-29)

Incidente: justo después de publicar v1.3.2, nuevo.g-se.com se puso muy lento. No era la
actualización: `oas-api.onlineeducation.center` y `api.onlineeducation.center` dejaron de responder
(los pedidos quedaban colgados, ni la raíz contestaba; `onlineeducation.center` y `api.g-se.com`
andaban). El servidor esperaba 20s por pedido y, como un fallo no se guardaba, CADA visita volvía a
esperar: medido 20,4s en una ficha cuyo resumen de opiniones no estaba en caché
(`?oec_debug=1` → "Batch en paralelo (summary) 20000 ms").

Arreglo (`OEC_Api`, usado por `call()`, `call_paginated()`, `fetch_many()` y
`oec_get_page_bundle()`):
- timeout `OEC_Api::TIMEOUT` = 8s (antes 20; `connect_timeout` 4 en los lotes);
- un pedido que falla (timeout/sin respuesta/5xx, `OEC_Api::failed()`) se marca con un transient
  `oec_down_<md5(clave de caché)>` por `DOWN_TTL` = 5 min: en ese lapso nadie vuelve a salir a la red
  por él;
- mientras tanto se usa `OEC_Api::stale()`: el último dato bueno guardado, aunque esté vencido.
Medido en local con la API caída: 1ra visita 8,1s, siguientes 0,1s. Con la API sana no cambia nada.
Una ficha que NUNCA se cacheó igual sale vacía si la API está caída (no hay de dónde sacar datos).

Del lado del navegador, lo que depende de `api.onlineeducation.center` (precios por país, medios de
pago) queda sin completar mientras la API no responda; no traba el resto del JS (son pedidos async).

**`?ver=` de los assets**: `oec-wp-theme/inc/performance.php` (`oec_remove_query_strings()`) le sacaba
el `?ver=` a todo CSS/JS que no fuera del tema, incluido el plugin — y el plugin depende de ese
`?ver=filemtime()` para que el navegador baje los archivos nuevos al actualizar. Se excluyó
`/plugins/oec-wordpress-plugin/` en el TEMA (repo aparte). Ojo: otros temas/plugins de
"performance" en sitios de socios pueden hacer lo mismo.

## wp-admin → Estadísticas: embudo y contactos desde Zoho (2026-09-29)

Todo en `includes/class-oec-stats.php` (`OEC_Stats`; `OEC_Admin::page_stats()` solo delega).
**Decisión de Mario: NO usar `sales-analytics`** (datos internos de OEC) — los números y los contactos
salen de Zoho, que replica las etapas. Etapas (sin "Leads"): `Cold Prospects`, `More Info Request`,
`Hot Prospects`, `Orders Not Finished`, `Students - Won`. El usuario, al pedir info, consiente que
OEC comparta sus datos con el socio educativo.

**Arquitectura** — las claves de Zoho NUNCA van en el plugin (se instala en sitios de socios):
- `server/zoho/partner-contacts.php` (en este repo, NO va en el zip del plugin) se sube a
  `onlineeducation.center/connections/zoho/` con un `zoho-config.php` al lado (plantilla
  `zoho-config.example.php`; el real está en `.gitignore`). Mario no tiene ese sitio en git: lo sube
  a SiteGround a mano.
- El plugin le pega desde el SERVIDOR con `X-API-TOKEN` = token de OEC del socio
  (`OEC_ZOHO_CONTACTS_ENDPOINT` en `oec-main.php`, filtro `oec_zoho_contacts_endpoint`).
  El endpoint solo responde por formaciones que la API de OEC le lista a ese token (token de socio ≈
  12 formaciones, 1 pedido; el de oec-test es MAESTRO: ve las 2.216, ~20 s la primera vez, cacheado
  1 h por token en `data/owners_<sha256>.json`; un uid desconocido no re-escanea si la lista tiene
  <5 min). Valida `t-[A-Za-z0-9]{14}` / `te-[A-Za-z0-9]{14}` / etapa en lista cerrada (el uid va
  dentro del `criteria` de Zoho: sin validar, se inyecta).
- Zoho: conteos con `Deals/actions/count?criteria=` (5 en paralelo, ~1 s; caché 15 min en el
  endpoint y 15 min en el plugin); contactos con `Deals/search` (200 por página, tope 2.000) +
  `Contacts?ids=` de a 100. El refresh token actual tiene scope `ZohoCRM.modules.ALL` (sin COQL, sin
  settings). Al rotar: `ZohoCRM.modules.deals.READ,ZohoCRM.modules.contacts.READ` alcanza.
- **Por edición**: el Deal tiene `Edition_UID` (`te-…`) = `edition_uid` del DETALLE de la formación en
  la API de OEC (el listado no lo trae → `OEC_Api::call('trainings/{id}')`, caché 24 h). Sin filtrar,
  Holway daba 825 alumnos (todas las ediciones); con la edición, 353 (= sales-analytics).
- Pantalla: formaciones con inscripción abierta o todas (`?ver=todas`), buscador, paginación de la
  API (30). Números por AJAX de a 4 (`oec_stats_counts`). Cada número >0 abre un `<dialog>` con los
  contactos (`oec_stats_contacts`; datos insertados con `textContent`), "Copiar emails" y "Descargar
  CSV" (`admin-post.php?action=oec_stats_csv`, BOM, encabezados `First Name/Last Name/Email/Phone/
  Country/Stage/Created` para Google Sheets→GMass o Google Contacts; celdas `= + - @` neutralizadas
  salvo teléfonos). Todo con nonce `oec_stats` + `manage_options`. Meta de alumnos =
  `Students - Won` / `enrollments_goal` de la API de OEC.
- Días a cierre/inicio: día calendario de la fecha de la API tal cual (`days_until()`); pasarlo a hora
  de Argentina corría `…T00:00:00+00:00` al día anterior.
- **Seguridad pendiente del lado de OEC**: `list-deals.php` (el Thickbox viejo de "Hot Prospects")
  responde SIN autenticación y el `training_uid` se inyecta en el `criteria` (probado: devolvía 419 Hot
  Prospects de todas las formaciones). Además imprime el uid sin escapar (XSS). El plugin nuevo ya no
  lo usa; hay que borrarlo cuando los socios actualicen, o parcharlo antes. Las claves de Zoho de ese
  archivo quedaron expuestas en la sesión del 2026-09-29: rotarlas después de las pruebas.
- Probar sin loguearse al navegador: el endpoint se levanta con `php -S` + `zoho-config.php` de prueba,
  y un mu-plugin temporal con el filtro `oec_zoho_contacts_endpoint`. **Ojo**: `download_csv()` hace
  `exit` → si se llama desde un script, vuelca el CSV (con datos personales) a la salida; probar
  `OEC_Stats::write_csv()` sobre `php://memory` y contar filas.

**v1.4.0** (2026-09-29): Estadísticas desde Zoho + íconos alineados en wp-admin → Contáctenos. Gotcha de
wp-admin: un `.dashicons` dentro de `.button` hereda el `line-height` del botón (34 px) y el glifo se
dibuja ~8 px más abajo que su caja de 18 px; fijarle `line-height` igual a su alto.

## v1.4.1 — "¿estamos en la comunidad?" compara solo el dominio (2026-09-30)

`inCommunity` (Twig de la ficha) decide: link de inscripción `data.register_url` (propia comunidad)
vs `data.register`, "Organiza: …" debajo del título, y el ítem "¿Quién organiza esta formación?"
(este además necesita `organization.short_description`). Antes era
`data.community in extra.current_url`: buscar la URL de la comunidad como TEXTO dentro de la URL de
la página → en `nuevo.g-se.com` daba falso (no contiene `https://g-se.com`), igual con `www.` o
barra final. Ahora:
- `oec_is_community_site($community_url)` (`oec-main.php`): compara solo el host de `data.community`
  con el de `home_url()`, sin `www.`/protocolo/puerto/ruta, aceptando subdominios (`nuevo.g-se.com`
  = `g-se.com`; `notg-se.com` y `g-se.com.ar` NO). Probado con 10 casos.
- La ficha recibe `extra.in_community`; el Twig usa
  `extra.in_community is defined ? extra.in_community : (…comparación vieja…)`.
- **Compatibilidad con plantillas ya pegadas**: `render_content()` reemplaza al vuelo
  `data.community in extra.current_url` por `extra.in_community` antes de renderizar → los sitios
  quedan bien con solo actualizar el plugin, sin volver a pegar la plantilla. Probado con la
  plantilla vieja y la nueva en la base local.
- Mismo criterio en el email/página de confirmación de canje (`class-oec-ajax.php`, antes
  `str_contains(home_url(), community)`) y en el ejemplo de wp-admin.
- **Link a la organización** (`extra.org_url`, `organization_url()` en `class-oec-shortcodes.php`):
  `{página "organizacion"}?slug={data.organization.data.slug}` — la landing que arma `oec-wp-theme`
  (`page-organizacion.php`; 404 si el slug no existe). Solo si el sitio tiene PUBLICADA una página con
  slug `organizacion`; si no, `""` y "Organiza:" usa el link de antes (`{community}/es/socio/{facebook_app_id}`,
  nueva pestaña). Con org_url: "Organiza:" enlaza ahí (misma pestaña) y "¿Quién organiza esta
  formación?" suma un botón discreto `.oec-org-more` "Conocer más sobre {org}" (`display:flex` +
  `fit-content`: la descripción de la API a veces termina en texto suelto y el botón quedaba pegado a
  la última palabra). OJO: esto SÍ requiere volver a pegar la plantilla — el reemplazo al vuelo solo
  cubre la condición de comunidad, no el link ni el botón. Tema hostil: 0/0 en 1.577 elementos.
- Para probar en local como si el sitio fuera la comunidad: mu-plugin temporal con
  `add_filter('pre_option_home', fn() => 'https://nuevo.g-se.com/es')` + `remove_action('template_redirect',
  'redirect_canonical')` activado por un query param; borrarlo al terminar.

## v1.4.2 — `Course` en PHP, completo para Google y para IA (2026-09-30)

Origen: auditoría de performance/SEO hecha desde el tema antes de pasar nuevo.g-se.com a g-se.com.
El `Course` que armaba el Twig a mano era JSON válido, pero le faltaban dos campos que Google pide
para "Course info": `offers.category` y, en `hasCourseInstance`, `courseWorkload` (o `courseSchedule`).

- **Ahora sale del `<head>`**: `oec_course_jsonld($data, $summary)` en `oec-main.php`, llamado desde
  `oec_seo_and_stars_metadata()` después del breadcrumb, con `wp_json_encode` (+ `JSON_HEX_TAG`, así un
  `</script>` en un texto de la API no puede cortar el bloque). Ayudante `oec_jsonld_text()`: HTML de
  la API → texto plano (espacio en los cierres de bloque, como la meta description), cortado en palabra.
- **Plantillas ya pegadas**: `render_content()` saca del HTML renderizado el bloque `Course` viejo del
  Twig (regex sobre `"@type": "Course"`), así no hay dos `Course` en la página y alcanza con actualizar
  el plugin, sin volver a pegar la plantilla en cada sitio. `page-formacion-testing.html` y
  `page-formacion.txt` ya no lo traen (quedó un comentario Twig).
- **Campos nuevos**: `@id` (`{canonical}#course`), `offers.category` (Paid/Free según `prices.total`),
  `courseWorkload` (`PT{lecture_hours}H`: son las "horas cátedra" que muestra la ficha, tal cual),
  `educationalCredentialAwarded` (certificados, con `recognizedBy` = organización que lo otorga),
  `syllabusSections` (un `Syllabus` por módulo: asignaturas + horas), `teaches` (`objetives`),
  `coursePrerequisites` (`requirements`), `totalHistoricalEnrollment` (`total_students`). Todo se ve
  en la ficha (contenidos, "Más información"), como pide Google.
- **Se mantiene** la lógica de antes: `force_contact` → sin `offers`; inscripción cerrada → `OutOfStock`
  con la URL de la propia ficha; abierta → `InStock` + `validThrough` + link de inscripción
  (`register_url` si `oec_is_community_site()`, si no `register`); fechas de inicio/fin solo si es
  sincrónica/mixta. Las fechas se toman como día de calendario (`AAAA-MM-DD` del valor de la API).
- Verificado en local con 8 formaciones abiertas y 3 cerradas: un solo `Course` por página, JSON
  válido, `BreadcrumbList` + `Course` + `FAQPage`.

## v1.4.3 — force_contact completo, bajada desde `headline`, video desde `video_url` (2026-10-05)

- **`force_contact: true`** = no se vende online: nunca "INICIAR INSCRIPCIÓN", se invita a contactar.
  Revisado lugar por lugar (simulado: ninguna formación abierta lo tenía): hero, caja fija de la
  columna derecha, sticky, barra mobile, formulario de Contacto (pide teléfono, botón "SOLICITAR
  INFORMACIÓN") y JSON-LD (`oec_course_jsonld()` omite `offers`). Había dos huecos: el hero quedaba
  sin llamado a la acción y la caja fija `.oec-rc` quedaba VACÍA con inscripción abierta (sus ramas
  eran "abierta y sin force_contact" / "cerrada"). Ahora los dos muestran "SOLICITAR INFORMACIÓN"
  (`scroll-link` a `#oec-contacto`); la caja suma `.oec-rc-contact-text`.
- **Bajada del título**: sale de `data.headline` (viene con `<p>`: se le sacan las etiquetas, sin tope de
  largo); si viene vacío, de `description_oa` como antes (solo si tiene ≤200 caracteres).
- **Video de "Presentación"**: la API manda el texto en `short_description` y el video aparte en
  `video_url`. `split_vimeo_intro(video_url)` arma el iframe con `OEC_Shortcodes::video_embed_iframe()`
  (mismo `<iframe loading="lazy" src=…>` que venía pegado + `allow`/`allowfullscreen`/`title`; acepta
  Vimeo y YouTube, cualquier otra URL no se embebe). Sin `video_url` sigue buscando el iframe pegado al
  principio del texto (datos viejos); con los dos, no lo duplica.
- **`upgrade_template()`** (`class-oec-shortcodes.php`) junta los reemplazos "al vuelo" para plantillas
  ya pegadas con versiones viejas (comunidad por dominio de 1.4.1 + bajada y video de 1.4.3): cada sitio
  queda bien con solo actualizar el plugin. Lo de force_contact (hero y caja) SÍ está en la plantilla:
  para eso hay que volver a pegarla.

**v1.4.4** (2026-10-05): con `force_contact` tampoco se muestran las formas de pago ("Se puede pagar
con"). La caja sticky (`#oec-sticky-pay-wrap`) las traía ocultas y `getRegPrices()` las hacía visibles
igual: ahora el Twig no genera ese bloque con `force_contact` y el JS no muestra ni llena ninguna caja
de pago si `OEC_CONFIG.force_contact` (cubre plantillas ya pegadas sin volver a pegarlas). La caja fija,
la barra mobile y el bullet de pago ya estaban condicionados. Probado con la plantilla de 1.4.3 y la
nueva (force_contact simulado en ND2, que tiene 3 medios de pago) y sin force_contact (siguen apareciendo).
También se saca el link "¿O necesitas más información?" (`.oec-sticky-more`) de la caja sticky: con
force_contact el botón ya es "SOLICITAR INFORMACIÓN" y llevaba al mismo lugar (Twig + `remove()` en el JS
para plantillas ya pegadas).

## v1.4.7 — LCP de la ficha y enlaces rastreables (2026-10-06)

Auditoría post-lanzamiento en g-se.com (Lighthouse móvil de la ficha: 72, LCP 6,5 s; SEO 92).
- **Preload del hero en el `<head>`**: `oec_hero_preload()` (`oec-main.php`, `wp_head` prioridad 1), dos
  `<link rel="preload" as="image" fetchpriority="high">` con `media` (`max-width: 600px` → `w=700&q=82`,
  `min-width: 601px` → `w=1400&q=89`): las MISMAS URLs que `--oec-hero-bg-url(-mobile)` en
  `css/oec-formacion.css` (corte en 600px). El del Twig usaba `imagesrcset`+`imagesizes`: en un celular
  de pantalla densa elegía la de 1400, el CSS pintaba la de 700 y se bajaban las dos.
- **jQuery al pie** en la ficha (`wp_script_add_data(… 'group', 1)`): era el único JS en el `<head>`.
- **Enlaces rastreables** (Lighthouse "Links are not crawlable" + `aria-prohibited-attr`): los
  `.oec-botmaker-trigger` pasan de `href="javascript:void(0)"` a `href="#chat" role="button"`, y los
  `<a class="… scroll-link" where="X">` sin href reciben `href="#X"`. Los dos handlers ya hacían
  `preventDefault`, así que no cambia el comportamiento.
- **Plantillas ya pegadas**: `render_content()` aplica todo eso al HTML renderizado (saca el preload
  viejo, el preconnect a jsdelivr si los íconos no salen de ahí, y corrige los enlaces): no hace falta
  volver a pegarlas. `page-formacion-testing.html` / `.txt` ya vienen corregidas (siguen idénticas).
- A/B en local (ficha móvil): SEO 92 → 100, accesibilidad 91 → 95, 3 → 2 recursos bloqueantes, una
  sola descarga de la imagen del hero. Sin errores de consola; scroll-links y chat probados.

## v1.4.8 — el carrusel de opiniones ya no anima fuera de pantalla (2026-10-06)

PageSpeed de una ficha (móvil) marcaba TBT 830 ms y 5,2 s de hilo principal; `oec-frontend.js` sumaba
~1,2 s de trabajo con solo ~140 ms de JS propio. Causa: el auto-scroll de `#oec-rev-carousel` era un
`requestAnimationFrame` infinito que escribía `scrollLeft` cada 3 cuadros durante toda la visita
(forzando maquetado/pintado), aunque el carrusel estuviera fuera de pantalla o ya al final.
- Ahora corre solo mientras el carrusel está visible (`IntersectionObserver`), se corta al llegar al
  final (si ya se movió, o si no hay nada que desplazar) y respeta `prefers-reduced-motion`.
- `OEC_STOP_REVIEWS_AUTOSCROLL` (la pone `initLiveSessionsMarquee` mientras resuelve si hay sesiones en
  vivo, y la deja en `true` si las hay): mientras esté en `true` se re-chequea cada 500 ms con
  `setTimeout`, sin mover nada; cuando pasa a `false`, arranca. Hover/touch siguen pausando igual.
- Probado en Chrome headless (412 px): fuera de pantalla 0 llamadas a rAF; en pantalla avanza; al
  salir, 0 de nuevo; sin errores de consola.

## v1.4.9 — la barra fija de celular ya no crece al cargar (2026-10-06)

Lighthouse móvil marcaba CLS ~0,054 en las fichas con inscripción abierta, siempre en `#oec-mobile-bar`
(es `position: fixed; bottom: 0`: si gana alto, su borde de arriba sube y cuenta como salto).
Medido con un PerformanceObserver de layout-shift en Chrome headless (412 px): la barra pasaba de 121
a 143 px porque `.oec-mb-price-line` arranca con "···" y, cuando el JS trae los precios de la región
("ARS 172.000"), el texto con dos precios ("Inicia con el módulo 1 (…) o los 3 con 20% off (…)") pasa a
dos renglones. Los precios dependen de la región del visitante: no se pueden poner del lado del servidor.
- CSS: `@media (max-width: 480px) { .oec-mb-price-line:has(.total-price) { min-height: 2lh } }`.
- Además, `render_content()` deja visibles de entrada (`style="display:flex"`) las `.oec-countdown`
  a las que les faltan ≤ 15 días (el mismo criterio que `updateCountdowns()`), para que tampoco salten
  al aparecer. Con HTML cacheado que cruza los 15 días, el JS las muestra igual que antes.
- Resultado: la barra no cambia de alto (143 px en 412; 153 en 360); queda ~0,01 de reacomodo del texto
  del precio a lo ancho.

## v1.4.10 — `provider` del Course con la organización, no la comunidad (2026-10-06)

Revisión de schema del sitio: `provider.url` del `Course` era `data.community` (p. ej. https://g-se.com) con
el nombre de la organización que dicta (una universidad) → dato contradictorio. Ahora `provider` lleva
`url` y `@id` de la landing de la organización en el sitio (`oec_organizacion_url($slug)` de oec-wp-theme,
`@id` = `{url}#organization`, el mismo que imprime esa landing), solo si la organización está en el catálogo
(`OEC_AI_Catalog::get_organization`); si no (o en otro tema), `provider` queda solo con el nombre.

## Pendientes abiertos (a retomar)

1. ~~`page-formacion.txt` y `page-formaciones.txt` atrasadas~~ — **resuelto
   2026-09-17**: se sincronizaron byte a byte con sus respectivas
   `-testing.html`, a pedido explícito de Mario, para que sirvan de guía
   al instalar el plugin. Ver "Archivos clave" arriba para el nuevo
   criterio (repetir la copia después de cada cambio real, no esperar a
   que Mario lo porte a mano).
2. `OEC_CREDITS_ALLOWED_DOMAINS` y `OEC_CREDITS_API_KEY_DEFAULT` en
   `oec-main.php` siguen con placeholders — Mario tiene que completarlos
   con los valores reales (ver "Sistema de créditos" arriba).
3. `[oec-list]` rehecho como vidriera simple (2026-09-26) — ver su sección.
   El costo de `inc-reviews=1` sigue anotado ahí como decisión A CRITERIO
   DE MARIO. En el tema quedaron reglas CSS de "armonización" para clases
   del listado viejo (`.filtro-enlace`, `.filtros-header`,
   `.btn-filtros-movil`, `.resultados-info`… en `oec-wp-theme/style.css`)
   que ya no matchean nada — limpieza opcional del lado del tema.
4. ~~Webhook de refresco de caché~~ — eliminado 2026-09-28, queda solo el
   botón "Actualizar caché" en wp-admin (ver "Cacheo en Cloudflare").
5. Colisión de CSS con `oec-wp-theme` diagnosticada y arreglada en la raíz
   (scopeo de `.post-content` a `.post-content.entry-content` en el tema +
   fuente explícita en `#oec-hero`/`#oec-mobile-bar` en el plugin), y
   además se hizo un blindaje COMPLETO del plugin (CSS con `!important`
   selectivo, IDs con prefijo `oec-`, funciones/clases PHP con guards
   `function_exists`/`class_exists`, JS sin fugas a `window` sin
   prefijo) — ver sección "Blindaje del plugin contra CUALQUIER tema/
   plugin host" más arriba. **Resuelto también** (2026-09-12) el
   duplicado de `<h1>`/breadcrumb del lado del TEMA: `page.php` de
   `oec-wp-theme` ahora detecta con
   `function_exists('oec_post_uses_shortcodes') && oec_post_uses_shortcodes($post->post_content)`
   si la página es una "página aplicación" (trae uno de nuestros
   shortcodes) y, si es así, se saltea por completo el `.page-hero`
   genérico (h1 + breadcrumb) Y el `<section><div class="container">`
   que envolvía el `<article>` — el shortcode ya trae su propio hero full-
   bleed, así que además de evitar el doble-h1 se evita que quede metido
   adentro de un contenedor centrado con padding pensado para prosa
   simple. Con `function_exists()` de por medio, el tema no rompe si
   algún día se usa sin este plugin activo (la condición da `false` y se
   muestra el hero genérico de siempre, comportamiento previo intacto).
6. Sección de Contacto rediseñada en 3 canales (Email / chat anónimo
   Botmaker / WhatsApp) — ver "Sección de Contacto" arriba, probada de
   punta a punta (incluido el flujo real de abrir el chat y mandar el
   mensaje, contra el CDN real de Botmaker). **Actualizado
   2026-09-17**: el ID de Botmaker ya no es una opción de wp-admin —
   está hardcodeado (`OEC_BOTMAKER_PROJECT_ID`, `oec-main.php`) y el
   chat anónimo/WhatsApp ahora aparecen según reglas de negocio (equipo
   de ventas de OEC + monto de la formación) — ver "Sección de Contacto
   — chat anónimo hardcodeado + reglas por precio" para el detalle
   completo y la tabla de verdad.
7. Estadísticas desde Zoho (ver su sección): subir `server/zoho/partner-contacts.php`
   + `zoho-config.php` a onlineeducation.center, probar, sacar `list-deals.php` y
   rotar las claves de Zoho.
