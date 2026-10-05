<?php
if (!class_exists('OEC_Shortcodes')) {
    class OEC_Shortcodes {
        private $twig;

        public function __construct() {
            $loader = new \Twig\Loader\ArrayLoader([]);
            $this->twig = new \Twig\Environment($loader, [
                'autoescape' => false,
                'debug' => true
            ]);

            // 1. FILTRO: format_date
            // $includeYear=false para los casos donde el año sobra (ej. un ring
            // chico del hero) — "17 de Septiembre" en vez de "17 de Septiembre de 2026".
            $this->twig->addFilter(new \Twig\TwigFilter('format_date', function ($date, $includeYear = true) {
                return self::format_date_es($date, $includeYear);
            }));

            // 1b. FILTRO: format_date_short — "06 de Ago." (mismo timestamp que
            // format_date, mes abreviado a 3 letras + punto, sin año). Pensado
            // para mobile angosto, donde la fecha larga ("06 de Agosto de 2026")
            // no entra en una línea dentro de la lista de módulos de precios.
            $this->twig->addFilter(new \Twig\TwigFilter('format_date_short', function ($date) {
                if (!$date) return '';

                if (is_numeric($date)) {
                    $timestamp = (int) $date;
                } else {
                    try {
                        // Sin zona en el string (ej. "2026-10-16"): es un día de
                        // calendario en la hora del sitio, no medianoche UTC.
                        $dt = new \DateTime($date, wp_timezone());
                        $timestamp = $dt->getTimestamp();
                    } catch (\Exception $e) {
                        $timestamp = strtotime($date);
                    }
                }

                if (!$timestamp || $timestamp === -1) return '';

                $meses_abrev = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
                $mes = $meses_abrev[(int) self::site_date('n', $timestamp) - 1];
                return self::site_date('d', $timestamp) . ' de ' . $mes . '.';
            }));

            // 2. FILTRO: truncate
            $this->twig->addFilter(new \Twig\TwigFilter('truncate', function ($string, $limit = 150, $separator = '...') {
                $string = strip_tags($string);
                if (mb_strlen($string) <= $limit) return $string;

                $truncated  = mb_substr($string, 0, $limit);
                $last_space = mb_strrpos($truncated, ' ');

                if ($last_space !== false) {
                    $truncated = mb_substr($truncated, 0, $last_space);
                }

                return $truncated . $separator;
            }));

            // 3. FILTRO: split_vimeo_intro(video_url)
            // Video + texto de "Presentación" para el layout de 2 columnas. Desde 2026-10
            // la API manda el video aparte, en data.video_url: si viene, el iframe se arma
            // con esa URL (video_embed_iframe()) y "rest" es el texto entero. Si no viene,
            // se sigue buscando como antes un <iframe> de Vimeo pegado AL PRINCIPIO de
            // short_description y se lo separa del resto. Sin video, "iframe" es null y
            // "rest" el HTML intacto: el Twig cae al layout de una sola columna.
            $this->twig->addFilter(new \Twig\TwigFilter('split_vimeo_intro', function ($html, $video_url = '') {
                $html  = trim((string) $html);
                $embed = self::video_embed_iframe($video_url);
                if ($embed) {
                    // Si además quedó un iframe viejo pegado al principio del texto, no se duplica.
                    $rest = preg_replace('/^<iframe\b[^>]*>.*?<\/iframe>\s*/is', '', $html, 1);
                    return ['iframe' => $embed, 'rest' => trim((string) $rest)];
                }
                if (preg_match('/^(<iframe\b[^>]*\bsrc=["\'][^"\']*vimeo[^"\']*["\'][^>]*>.*?<\/iframe>)\s*(.*)$/is', $html, $m)) {
                    $iframe = $m[1];
                    // El embed de Vimeo que llega del CMS no trae loading="lazy" — sin
                    // esto el player (JS/CSS de terceros, pesado) carga eager apenas el
                    // DOM llega a esta sección, esté o no por debajo del fold.
                    if (!preg_match('/\bloading\s*=/i', $iframe)) {
                        $iframe = preg_replace('/^<iframe\b/i', '<iframe loading="lazy"', $iframe, 1);
                    }
                    return ['iframe' => $iframe, 'rest' => trim($m[2])];
                }
                return ['iframe' => null, 'rest' => $html];
            }));

            // 4. FILTRO: fix_orphan_li
            // A veces el HTML que carga quien arma la formación trae <li> sueltos,
            // sin el <ul> que los tiene que envolver — el navegador igual los
            // muestra (por eso "andan"), pero quedan afuera de cualquier estilo
            // que apunte a "ul li" (los bullets lindos con cuadradito de color,
            // por ejemplo), así que se ven como bullets default del navegador y
            // mezclados sin criterio con el resto. Esto agrupa cualquier racha de
            // <li> que aparezca como hijo directo (sin <ul>/<ol> de por medio) y
            // los envuelve en un <ul> real, para que el HTML quede válido y
            // tome el mismo estilo que el resto de las listas.
            $this->twig->addFilter(new \Twig\TwigFilter('fix_orphan_li', function ($html) {
                $html = (string) $html;
                if (stripos($html, '<li') === false) return $html;

                $dom = new \DOMDocument();
                libxml_use_internal_errors(true);
                $dom->loadHTML(
                    '<?xml encoding="UTF-8"><div id="oec-root">' . $html . '</div>',
                    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
                );
                libxml_clear_errors();

                $root = $dom->getElementById('oec-root');
                if (!$root) return $html;

                $children = iterator_to_array($root->childNodes);
                $i = 0;
                $count = count($children);
                while ($i < $count) {
                    $node = $children[$i];
                    if ($node->nodeType === XML_ELEMENT_NODE && strtolower($node->nodeName) === 'li') {
                        $ul = $dom->createElement('ul');
                        $root->insertBefore($ul, $node);
                        while ($i < $count) {
                            $cur = $children[$i];
                            if ($cur->nodeType === XML_ELEMENT_NODE && strtolower($cur->nodeName) === 'li') {
                                $ul->appendChild($cur);
                                $i++;
                            } elseif ($cur->nodeType === XML_TEXT_NODE && trim($cur->textContent) === '') {
                                // Espacio en blanco entre <li> (saltos de línea del HTML de
                                // origen) — no corta la racha, se descarta sin más (el <ul>
                                // ya resuelve el espaciado visual).
                                $root->removeChild($cur);
                                $i++;
                            } else {
                                break;
                            }
                        }
                    } else {
                        $i++;
                    }
                }

                $out = '';
                foreach ($root->childNodes as $child) {
                    $out .= $dom->saveHTML($child);
                }
                return $out;
            }));

            // 5. Shortcodes. [oec-list] arma su propio HTML (ver render_list()): no lleva
            // cuerpo Twig. Si una página vieja todavía lo trae ([oec-list]...[/oec-list]),
            // el cuerpo se ignora.
            add_shortcode('oec-list', function ($a) { return $this->scope($this->render_list($a)); });
            add_shortcode('oec-content', [$this, 'render_content']); // la ficha trae su propio envoltorio (#oec-bleed-wrapper)

            // Varios [oec-list] en la misma página: sus listados se piden en paralelo
            // antes de renderizar (ver prefetch_page()).
            add_action('template_redirect', [$this, 'prefetch_page'], 5);

            // 4. jQuery + CSS/JS del plugin, solo en la página de formación — es el
            // único lugar del plugin que usa jQuery (js/oec-formacion.js), así que
            // no tiene sentido cargarlo (+ jquery-migrate) en TODAS las páginas del
            // sitio solo porque el plugin está activo.
            add_action('wp_enqueue_scripts', function() {
                if (!is_page('formacion')) return;

                wp_enqueue_script('jquery');

                // CSS/JS de la ficha de formación solo se cargan en esa página. El
                // HTML/Twig de data/page-formacion-testing.html (pegado a mano en el
                // editor) tiene un <style> y un <script> chicos con SOLO lo que
                // depende de datos de esta formación puntual (color de marca, la
                // isla de datos window.OEC_TRAINING_DATA/OEC_CONFIG/OEC_AJAX_NONCE);
                // todo lo demás (~500 líneas de CSS y ~800 de JS, sin nada de Twig)
                // vive acá, en archivos estáticos versionables.
                //
                // La versión se calcula con filemtime() (no un número fijo tipo
                // "1.0") para que cada edición de estos archivos invalide sola el
                // caché del navegador — con un número fijo, el navegador puede
                // seguir sirviendo una versión vieja de la caché indefinidamente
                // después de editar el CSS/JS.
                $css_path = plugin_dir_path(dirname(__FILE__)) . 'css/oec-formacion.css';
                $js_frontend_path = plugin_dir_path(dirname(__FILE__)) . 'js/oec-frontend.js';
                $js_formacion_path = plugin_dir_path(dirname(__FILE__)) . 'js/oec-formacion.js';

                // Íconos de la ficha, en el <head> y con el MISMO handle que usa
                // oec-wp-theme: si el tema ya lo encola, WordPress lo carga una sola
                // vez; en un tema que no lo trae, sigue llegando. Reemplaza el <link>
                // que antes iba pegado dentro del Twig (en el <body>, descubierto tarde).
                wp_enqueue_style('bootstrap-icons', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css', [], null);

                wp_enqueue_style(
                    'oec-formacion',
                    plugin_dir_url(dirname(__FILE__)) . 'css/oec-formacion.css',
                    [],
                    file_exists($css_path) ? filemtime($css_path) : '1.0'
                );

                wp_enqueue_script(
                    'oec-frontend',
                    plugin_dir_url(dirname(__FILE__)) . 'js/oec-frontend.js',
                    ['jquery'],
                    file_exists($js_frontend_path) ? filemtime($js_frontend_path) : '1.0',
                    true  // footer
                );
                // oec-formacion.js referencia OEC_TRAINING_DATA/OEC_CONFIG/
                // OEC_AJAX_NONCE, que el <script> inline del Twig define ANTES
                // (ese bloque va embebido en el propio contenido de la página,
                // que el navegador parsea/ejecuta antes que cualquier script
                // encolado con footer=true). Por eso alcanza con footer+defer
                // acá también, sin declarar una dependencia explícita de un
                // handle que no existe (el bloque inline no es un asset
                // encolado por WordPress).
                wp_enqueue_script(
                    'oec-formacion',
                    plugin_dir_url(dirname(__FILE__)) . 'js/oec-formacion.js',
                    ['jquery'],
                    file_exists($js_formacion_path) ? filemtime($js_formacion_path) : '1.0',
                    true  // footer
                );
                // URL de admin-ajax de ESTE sitio: con una ruta fija "/wp-admin/admin-ajax.php", en un
                // multisite por subcarpeta (/es/) los formularios caían en el sitio raíz de la red.
                wp_add_inline_script('oec-formacion', 'window.OEC_AJAX_URL = ' . wp_json_encode(admin_url('admin-ajax.php')) . ';', 'before');
                // Agregar defer para no bloquear el render
                add_filter('script_loader_tag', function($tag, $handle) {
                    if ($handle === 'oec-frontend' || $handle === 'oec-formacion') {
                        return str_replace(' src=', ' defer src=', $tag);
                    }
                    return $tag;
                }, 10, 2);
            });

            // CSS/JS de [oec-list]: en cualquier página que lo use (has_shortcode, no
            // is_page(): se puede pegar en cualquier página, varias veces). Con page
            // builders el shortcode no está en post_content — ahí lo encola scope()
            // al renderizar.
            add_action('wp_enqueue_scripts', function() {
                global $post;
                if ($post && has_shortcode($post->post_content, 'oec-list')) $this->enqueue_list_assets();
            });

            // Desactivar wptexturize en la ficha: convierte " en “ ” y -- en –,
            // rompiendo la sintaxis Twig del cuerpo de [oec-content].
            add_filter('no_texturize_shortcodes', function($shortcodes) {
                $shortcodes[] = 'oec-content';
                return $shortcodes;
            });
        }

        /**
         * Envoltorio de la salida de [oec-list]: le da a css/oec-formaciones.css UNA raíz
         * segura (.oec-scope) sobre la que aplicar su capa de aislamiento del tema, y lleva
         * el color de marca como variable CSS (--oec-brand) en el propio elemento — así no
         * depende de ningún <style> en el <head> ni de en qué punto de la página se imprima.
         */
        private function scope($html) {
            // Encolado "tardío" como red de seguridad: con page builders (Elementor, algunos modos
            // de Divi) el shortcode NO está en post_content (vive en metadatos del builder), así que
            // el has_shortcode() de wp_enqueue_scripts no lo ve y la página quedaba sin CSS/JS.
            // Encolar acá, en el momento en que el shortcode realmente se renderiza, hace que
            // WordPress los imprima igual (en el footer). Si ya estaban encolados, no duplica nada.
            $this->enqueue_list_assets();
            if (trim((string) $html) === '') return '';
            $brand = sanitize_hex_color(get_option('oec_brand_color', '#a435f0')) ?: '#a435f0';
            return '<div class="oec-scope" style="--oec-brand:' . esc_attr($brand) . '">' . $html . '</div>';
        }

        /** CSS/JS de [oec-list] (+ íconos). Idempotente. Las tiras del tema oec-wp-theme también los usan. */
        public function enqueue_list_assets() {
            static $done = false;
            if ($done) return;
            $done = true;
            $css_path = plugin_dir_path(dirname(__FILE__)) . 'css/oec-formaciones.css';
            $js_path  = plugin_dir_path(dirname(__FILE__)) . 'js/oec-formaciones.js';
            // Mismo handle que usa oec-wp-theme: si el tema ya lo encola, se carga una sola vez.
            wp_enqueue_style('bootstrap-icons', 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css', [], null);
            wp_enqueue_style('oec-formaciones', plugin_dir_url(dirname(__FILE__)) . 'css/oec-formaciones.css', [], file_exists($css_path) ? filemtime($css_path) : '1.0');
            wp_enqueue_script('oec-formaciones', plugin_dir_url(dirname(__FILE__)) . 'js/oec-formaciones.js', [], file_exists($js_path) ? filemtime($js_path) : '1.0', true);
            add_filter('script_loader_tag', function ($tag, $handle) {
                return $handle === 'oec-formaciones' ? str_replace(' src=', ' defer src=', $tag) : $tag;
            }, 10, 2);
        }

        /**
         * Resuelve una URL "de este sitio" escrita en un atributo de shortcode
         * (more-url="/formaciones?..."). Una ruta que empieza con UNA sola "/" es
         * relativa a la raíz del DOMINIO, y en un multisite por subdirectorio
         * (oec-test.local/es/) eso apunta afuera del subsitio → 404. Acá se la ancla
         * al sitio actual con home_url(): "/formaciones" → "https://sitio/es/formaciones".
         * Se deja intacto: lo vacío, una URL absoluta, una protocol-relative
         * ("//cdn..."), un "#ancla" o una ruta sin "/" inicial. Si la ruta YA trae el
         * prefijo del subsitio ("/es/formaciones"), no se lo duplica.
         */
        private function resolve_site_url($url) {
            $url = trim((string) $url);
            if ($url === '' || $url[0] !== '/' || (isset($url[1]) && $url[1] === '/')) return $url;

            $home   = wp_parse_url(home_url());
            $prefix = rtrim($home['path'] ?? '', '/'); // "/es" o ""
            if ($prefix !== '' && preg_match('#^' . preg_quote($prefix, '#') . '(?:[/?\#]|$)#', $url)) {
                $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
                return $origin . $url;
            }
            return home_url($url);
        }

        /**
         * Atributos de [oec-list] que pasan directo como filtro a la API (izquierda: nombre
         * en el shortcode; derecha: query param real de la API). No se validan contra una
         * lista fija de valores: se mandan tal cual, así no hay que tocar el plugin si la
         * API suma un valor nuevo.
         */
        const LIST_FILTER_MAP = [
            'type'          => 'type',           // Curso, Webinar, etc.
            'enrollment'    => 'enrollment',     // opened | closed
            'subject-id'    => 'subject-id',     // temática — varias separadas por coma = AND (todas a la vez)
            'from-date'     => 'from_date',      // sobre fecha de inicio: YYYY-MM-DD, this-month, next-month
            'to-date'       => 'to_date',        // YYYY-MM-DD, this-month, next-month, month-after-next
            'search'        => 'search-term',
            'order'         => 'order',          // start_date | enrollment_end | published_date (vacío = orden sugerido)
            'synchronicity' => 'synchronicity',  // SYNC | MIXED | ASYNC | ASYNC-F
            'modality'      => 'modality',       // ONLINE | ONSITE | BLEND
        ];

        /** Tope de formaciones por [oec-list]: es para vidrieras chicas, no un catálogo. */
        const LIST_MAX = 24;

        /**
         * Varios [oec-list] en una misma página (ej. 3-4 tiras en una landing): sin esto
         * cada uno pide su listado a la API cuando le toca renderizarse, uno tras otro. Acá
         * se leen del post_content ANTES de renderizar (template_redirect), se calcula el
         * MISMO endpoint que va a pedir cada uno (prepare_list()) y se piden todos juntos
         * en paralelo (OEC_Api::fetch_many()); cada shortcode encuentra después su dato en
         * memoria. Es solo una optimización: si algo falla, el shortcode lo pide él mismo.
         * Con un solo listado no hace nada.
         */
        public function prefetch_page() {
            if (is_admin() || !is_singular()) return;
            $post = get_queried_object();
            if (!($post instanceof WP_Post) || strpos($post->post_content, '[oec-list') === false) return;
            if (!preg_match_all('/' . get_shortcode_regex(['oec-list']) . '/', $post->post_content, $matches, PREG_SET_ORDER)) return;

            $jobs = [];
            foreach ($matches as $m) {
                if ($m[1] === '[' && $m[6] === ']') continue; // [[oec-list]] escapado: no se ejecuta
                $raw      = shortcode_parse_atts($m[3]);
                $endpoint = $this->prepare_list(is_array($raw) ? $raw : [])['endpoint'];
                $jobs[$endpoint] = ['key' => 'oec_cache_pg_' . md5($endpoint), 'kind' => 'paginated', 'endpoint' => $endpoint, 'ttl' => 21600];
            }
            if (count($jobs) < 2) return;
            OEC_Api::fetch_many(array_values($jobs), 'Prefetch de listados [oec-list]');
        }

        /**
         * Atributos resueltos + endpoint de la API de un [oec-list]. Separado de
         * render_list() para que prefetch_page() calcule exactamente el mismo endpoint.
         */
        private function prepare_list($atts) {
            $defaults = [
                'layout'             => 'grid', // grid (todas visibles) | scroll (tira horizontal con flechas)
                'limit'              => '',     // cuántas formaciones (1 a LIST_MAX)
                'title'              => '',     // <h2> opcional arriba
                'more-url'           => '',     // link "ver todas" opcional
                'countdown'          => 'no',   // yes = cuenta regresiva del cierre de inscripción (si faltan ≤15 días)
                'trainings-per-page' => '',     // nombre viejo de "limit", se sigue aceptando
            ];
            foreach (self::LIST_FILTER_MAP as $attr_key => $api_key) {
                $defaults[$attr_key] = '';
            }
            $atts = shortcode_atts($defaults, $atts, 'oec-list');

            $atts['layout'] = $atts['layout'] === 'scroll' ? 'scroll' : 'grid';
            $limit = $atts['limit'] !== '' ? $atts['limit'] : $atts['trainings-per-page'];
            $limit = $limit !== '' ? intval($limit) : ($atts['layout'] === 'scroll' ? 10 : 6);
            $atts['limit'] = min(self::LIST_MAX, max(1, $limit));

            list($atts['from-date'], $atts['to-date']) = $this->resolve_relative_dates(trim($atts['from-date']), trim($atts['to-date']));

            $params = [
                'inc-reviews' => 1,              // estrellas de cada tarjeta (hace más lenta la respuesta de la API, ver CLAUDE.md)
                'relevance'   => 0,
                'pagination'  => $atts['limit'], // en la API, "pagination" es el TAMAÑO de página
                'pg'          => 1,
            ];
            foreach (self::LIST_FILTER_MAP as $attr_key => $api_key) {
                $value = trim((string) $atts[$attr_key]);
                if ($value !== '') $params[$api_key] = $value;
            }

            return ['atts' => $atts, 'endpoint' => 'trainings?' . http_build_query($params, '', '&')];
        }

        public function render_list($atts) {
            $prepared = $this->prepare_list($atts);
            $atts     = $prepared['atts'];
            $endpoint = $prepared['endpoint'];

            $rows = oec_debug_time('Shortcode [oec-list]: API trainings', function () use ($endpoint) {
                return OEC_Api::call_paginated($endpoint)['data'];
            }, function ($r) { return (is_array($r) ? count($r) : 0) . ' formaciones'; });
            $rows = is_array($rows) ? array_slice($rows, 0, $atts['limit']) : [];

            return oec_debug_time('Shortcode [oec-list]: HTML', function () use ($rows, $atts) {
                return $this->list_html($rows, $atts);
            });
        }

        /**
         * HTML de un [oec-list]. Todo el markup es del plugin (no hay plantilla que pegue
         * cada sitio): así se puede blindar contra cualquier tema (ver la capa de
         * aislamiento de css/oec-formaciones.css) y todos los sitios reciben las mejoras.
         */
        private function list_html(array $rows, array $atts) {
            $is_scroll = $atts['layout'] === 'scroll';
            $more_url  = $this->resolve_site_url($atts['more-url']);
            $title     = $atts['title'] !== '' ? '<h2 class="oec-list-title">' . esc_html($atts['title']) . '</h2>' : '';

            if (!$rows) {
                // Una tira vacía no se muestra (es una vidriera: mejor nada que un hueco); una
                // grilla avisa, porque suele ser el contenido principal de la página.
                if ($is_scroll) return '';
                return '<section class="oec-list oec-list--grid">' . $title . '<p class="oec-empty-title">No hay formaciones disponibles en este momento.</p></section>';
            }

            $rows      = $this->normalize_dates($rows);
            $countdown = $atts['countdown'] === 'yes';
            $cards     = '';
            foreach ($rows as $row) {
                if (is_array($row)) $cards .= $this->card_html($row, $countdown);
            }

            if ($is_scroll) {
                $more = $more_url ? '<a class="oec-scroll-more" href="' . esc_url($more_url) . '" aria-label="Ver todas las formaciones"><i class="bi bi-arrow-right"></i></a>' : '';
                return '<section class="oec-list oec-list--scroll">' . $title
                    . '<div class="oec-scroll-wrapper">'
                    . '<button type="button" class="oec-scroll-arrow oec-scroll-arrow-left" aria-label="Anterior"><i class="bi bi-chevron-left"></i></button>'
                    . '<div class="oec-scroll-track">' . $cards . $more . '</div>'
                    . '<button type="button" class="oec-scroll-arrow oec-scroll-arrow-right" aria-label="Siguiente"><i class="bi bi-chevron-right"></i></button>'
                    . '</div></section>';
            }

            $more = $more_url ? '<div class="oec-list-more"><a class="oec-list-more-link" href="' . esc_url($more_url) . '">Ver todas las formaciones <i class="bi bi-arrow-right"></i></a></div>' : '';
            return '<section class="oec-list oec-list--grid">' . $title . '<div class="oec-grid">' . $cards . '</div>' . $more . '</section>';
        }

        /**
         * Tarjeta de una formación. Mismas clases que las tarjetas de oec-wp-theme
         * (oec_formacion_card(), inc/tiras.php), que usan este mismo CSS/JS.
         * Las fechas ya vienen normalizadas a la hora del sitio (normalize_dates()).
         */
        private function card_html(array $row, $countdown) {
            $now      = time();
            $end_ts   = !empty($row['enrollment_end']) ? strtotime($row['enrollment_end']) : 0;
            $start_ts = !empty($row['start']) ? strtotime($row['start']) : 0;
            $closed   = $end_ts && $end_ts < $now;
            $is_async = in_array($row['synchronicity'] ?? '', ['ASYNC', 'ASYNC-F'], true);

            if ($is_async && !$closed) {
                list($fecha_clase, $fecha_icono, $fecha_tipo) = ['fecha-async', 'bi-infinity', 'async'];
            } elseif ($closed) {
                list($fecha_clase, $fecha_icono, $fecha_tipo) = ['fecha-cerrada', 'bi-lock', 'cerrada'];
            } elseif ($start_ts && self::site_date('Ymd', $start_ts) > self::site_date('Ymd', $now)) {
                list($fecha_clase, $fecha_icono, $fecha_tipo) = ['fecha-inicio', 'bi-calendar3', 'inicio'];
            } else {
                // Ya empezó pero la inscripción sigue abierta → se muestra el cierre.
                list($fecha_clase, $fecha_icono, $fecha_tipo) = ['fecha-cierre', 'bi-calendar-check', 'cierre'];
            }

            $modality_raw = (string) ($row['modality'] ?? '');
            $modality     = strpos($modality_raw, 'BLEND') !== false ? 'Mixta' : self::capitalize($modality_raw);
            $sync         = ['SYNC' => 'Sincrónica', 'MIXED' => 'Mixta', 'ASYNC-F' => 'Asincrónica c/foros'][$row['synchronicity'] ?? ''] ?? 'Asincrónica';
            $type         = self::plain($row['type'] ?? '');
            $edition      = intval($row['edition_number'] ?? 0);
            $rev_count    = intval($row['reviews_summary']['count'] ?? 0);
            $rev_avg      = (float) ($row['reviews_summary']['average'] ?? 0);
            $title        = self::plain($row['title'] ?? '');
            $org          = self::plain($row['organization']['data']['name'] ?? '');
            $image_file   = basename((string) wp_parse_url((string) ($row['image'] ?? ''), PHP_URL_PATH));
            $url          = get_site_url() . '/formacion/' . ($row['slug'] ?? '');

            $badges = [];
            foreach (['great_lecturers' => 'Docentes Destacados', 'great_topic' => 'Temática Destacada', 'great_organizer' => 'Organizador Líder', 'great_certification' => 'Certificación Oficial', 'great_price' => 'Mejor Precio'] as $key => $label) {
                if (!empty($row[$key])) $badges[] = '<div class="oec-badge-item">' . esc_html($label) . '</div>';
            }

            $h  = '<a href="' . esc_url($url) . '" class="oec-card' . ($closed ? ' enrollment-closed' : '') . '">';
            $h .= '<div class="oec-image-wrapper"><div class="oec-badges-container">' . implode('', $badges) . '</div>';
            if ($image_file !== '') {
                $img = 'https://imgrsize.oe-img.center/campus/capacitacion/imagen/' . rawurlencode($image_file) . '?w=640&q=89';
                $h  .= '<img src="' . esc_url($img) . '" class="oec-image" alt="' . esc_attr($title) . '" loading="lazy">';
            }
            $h .= '</div>';

            $h .= '<div class="oec-body">';
            $h .= '<div class="oec-title">' . esc_html(self::cut($title, 100)) . '</div>';
            if ($org !== '') {
                $h .= '<div class="oec-organization"><i class="bi bi-building"></i> ' . esc_html(self::cut($org, 35)) . '</div>';
            }
            $h .= '<div class="oec-description-container"><div class="oec-description">' . esc_html(self::plain($row['short_description'] ?? '')) . '</div></div>';
            $h .= '<div class="oec-grid-reviews">';
            if ($rev_count > 0) {
                $h .= '<div class="oec-stars-container"><div class="oec-stars" style="width:' . esc_attr(round($rev_avg * 20, 2)) . '%"></div></div>';
                $h .= '<span class="oec-stars-average">' . esc_html(number_format($rev_avg, 1, ',', '.')) . '</span>';
                $h .= '<span class="oec-cant-reviewers' . ($rev_count > 30 ? ' destacado-review' : '') . '">(' . $rev_count . ' opiniones)</span>';
            } else {
                $h .= '<div class="oec-new-label">Nueva formación</div>';
            }
            $h .= '</div></div>';

            $h .= '<div class="oec-footer"><div class="oec-footer-top"><div class="oec-tags-container">' . esc_html(self::capitalize($type));
            if ($edition > 0) {
                $h .= '<span class="separador">•</span><span class="' . ($edition > 5 ? 'destacado-edicion' : '') . '">' . $edition . 'ª ed</span>';
            }
            if ($modality !== '') $h .= '<span class="separador">•</span> ' . esc_html($modality);
            $h .= '<span class="separador">•</span> ' . esc_html($sync) . '</div></div>';

            $h .= '<div class="oec-footer-bottom"><div class="oec-start-date ' . $fecha_clase . '"><i class="bi ' . $fecha_icono . '"></i>';
            if ($fecha_tipo === 'async') {
                $h .= '<span class="fecha-texto">Comienza cuando quieras</span>';
            } elseif ($fecha_tipo === 'cerrada') {
                $h .= '<span class="fecha-texto">Inscripciones cerradas</span>';
            } else {
                $is_inicio = $fecha_tipo === 'inicio';
                $h .= '<div class="fecha-contenedor"><span class="fecha-valor">' . esc_html(self::format_date_es($is_inicio ? $row['start'] : $row['enrollment_end'])) . '</span>'
                    . '<span class="fecha-etiqueta">' . ($is_inicio ? 'Fecha de inicio' : 'Cierre de inscripciones') . '</span></div>';
            }
            $h .= '</div><div class="oec-btn-fake">Ver ' . esc_html($type !== '' ? $type : 'formación') . '</div></div>';

            if ($countdown && !$is_async && !$closed && $end_ts) {
                // El JS lo muestra recién cuando faltan 15 días o menos (ver js/oec-formaciones.js).
                $h .= '<div class="oec-countdown" date="' . esc_attr(gmdate('c', $end_ts)) . '">'
                    . '<div class="oec-cd-unit oec-cd-days"><span class="oec-cd-num" data-unit="d">00</span><span class="oec-cd-label">Días</span></div>'
                    . '<div class="oec-cd-unit"><span class="oec-cd-num" data-unit="h">00</span><span class="oec-cd-label">Hs</span></div>'
                    . '<div class="oec-cd-unit"><span class="oec-cd-num" data-unit="m">00</span><span class="oec-cd-label">Min</span></div>'
                    . '<div class="oec-cd-unit"><span class="oec-cd-num" data-unit="s">00</span><span class="oec-cd-label">Seg</span></div>'
                    . '</div>';
            }
            $h .= '</div></a>';
            return $h;
        }

        /** Texto plano de un campo de la API: sin HTML, con las entidades decodificadas (se escapa al imprimir). */
        private static function plain($s) {
            $s = html_entity_decode(wp_strip_all_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', $s)));
        }

        private static function cut($s, $max) {
            return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 3)) . '...' : $s;
        }

        /** Como el filtro capitalize de Twig: primera letra en mayúscula, el resto en minúscula. */
        private static function capitalize($s) {
            $s = mb_strtolower((string) $s);
            return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
        }

        /**
         * "this-month" / "next-month" → primer y último día de ese mes (YYYY-MM-DD), para
         * no tener que editar una fecha fija a mano cada mes. to-date acepta además
         * "this-month" / "next-month" / "month-after-next" → ÚLTIMO día de ese mes. Cualquier
         * otro valor (fecha literal, o vacío) pasa sin tocar.
         */
        private function resolve_relative_dates($from, $to) {
            $meses_adelante = ['this-month' => 0, 'next-month' => 1, 'month-after-next' => 2];
            $ultimo_dia = function ($meses) {
                $dt = new DateTime('first day of this month', wp_timezone());
                if ($meses > 0) $dt->modify('+' . $meses . ' month');
                return $dt->format('Y-m-t');
            };
            if (isset($meses_adelante[$to])) {
                $to = $ultimo_dia($meses_adelante[$to]);
            }
            if (!isset($meses_adelante[$from])) {
                return [$from, $to];
            }
            $dt = new DateTime('first day of this month', wp_timezone());
            if ($meses_adelante[$from] > 0) {
                $dt->modify('+' . $meses_adelante[$from] . ' month');
            }
            $computed_from = $dt->format('Y-m-01');
            $computed_to   = $dt->format('Y-m-t');
            return [$computed_from, $to !== '' ? $to : $computed_to];
        }

        public function render_content($atts, $content = null) {
            preg_match('/t-[a-zA-Z0-9]{14}/', $_SERVER['REQUEST_URI'], $matches);
            $id = !empty($matches[0]) ? $matches[0] : '';

            if (!$id) return "ID de formación no encontrado en la URL.";

            $full_url    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
            $current_url = strtok($full_url, '?');
            $brand_color = get_option('oec_brand_color', '#a435f0');

            // Plantillas pegadas con versiones anteriores del plugin: se ponen al día al vuelo.
            $content = $this->upgrade_template($content);

            // Formación + reviews + resumen + color dominante: todo sale de acá,
            // compartido con la metadata de SEO de wp_head (oec_get_page_bundle(),
            // en oec-main.php). Esa función memoiza el resultado dentro del mismo
            // request y dispara el batch en paralelo bien temprano — wp_head
            // corre antes que este shortcode, así que para cuando llegamos acá
            // ya está todo resuelto, sin pedir nada de nuevo.
            $bundle             = oec_get_page_bundle();
            $data               = $bundle['data'];
            $reviews_data       = $bundle['reviews'];
            $reviews_summary    = $bundle['summary'];
            $dominant_color     = $bundle['dominant_color'];
            $dominant_color_css = $bundle['dominant_color_css'];

            // Seleccionar hasta 5 reviews destacadas
            $reviews_top = [];
            if (!empty($reviews_data['reviews'])) {
                foreach ($reviews_data['reviews'] as $review) {
                    if (count($reviews_top) >= 5) break;
                    if (
                        $review['rating'] >= 4 &&
                        $review['author']['image'] !== '/img/user-default.jpg' &&
                        strlen($review['comment']) >= 50
                    ) {
                        $reviews_top[] = $review;
                    }
                }
            }

            // Formaciones similares con inscripción abierta — solo se pide si
            // ESTA formación tiene las inscripciones cerradas (mismo cálculo que
            // "openEnrollment" en el Twig, pero acá hace falta en PHP porque
            // dispara una llamada de red). Con inscripciones abiertas no tiene
            // sentido gastar la llamada: nunca se muestran.
            $openEnrollment = !empty($data['enrollment_end'])
                && date('Ymd', strtotime($data['enrollment_end'])) >= date('Ymd');
            $similar = $openEnrollment ? [] : $this->get_similar_open_trainings($data);

            $output = oec_debug_time('Shortcode: render Twig', function () use ($content, $data, $current_url, $brand_color, $dominant_color, $dominant_color_css, $reviews_top, $reviews_data, $reviews_summary, $similar) {
                return $this->process_twig($content, [
                'data'    => $data,
                'similar' => $similar,
                'extra' => [
                    'current_url'         => $current_url,
                    // ¿Estamos en la comunidad dueña de la formación? (solo dominio, ver oec_is_community_site()).
                    'in_community'        => function_exists('oec_is_community_site') && oec_is_community_site($data['community'] ?? ''),
                    // Landing de la organización en ESTE sitio (vacío si el sitio no la tiene, ver organization_url()).
                    'org_url'             => $this->organization_url($data),
                    'brand_color'         => $brand_color,
                    'detail_url'          => get_site_url() . '/formacion/',
                    'equipo_oec'          => get_option('oec_equipo_ventas', '1') === '1',
                    'ventas_whatsapp'     => get_option('oec_ventas_whatsapp', ''),
                    'ventas_email'        => get_option('oec_ventas_email', ''),
                    'dominant_color'      => $dominant_color,
                    'dominant_color_css'  => $dominant_color_css,
                    'ajax_nonce'          => wp_create_nonce('oec_ajax'),
                    'credits_enabled'     => function_exists('oec_credits_system_enabled') && oec_credits_system_enabled(),
                    'botmaker_id'         => defined('OEC_BOTMAKER_PROJECT_ID') ? OEC_BOTMAKER_PROJECT_ID : '',
                    // Mismos ítems que el BreadcrumbList JSON-LD (oec-main.php): el hero los muestra visibles.
                    'breadcrumb'          => function_exists('oec_training_breadcrumb_items') ? oec_training_breadcrumb_items($data) : [],
                ],
                'reviews' => [
                    'top'        => $reviews_top,
                    'all'        => $reviews_data['reviews'] ?? [],
                    'pagination' => $reviews_data['pagination'] ?? [],
                    'summary'    => $reviews_summary,
                ],
                ]);
            });

            // El JSON-LD Course ahora sale del <head> (oec_course_jsonld(), oec-main.php, con
            // wp_json_encode). Las plantillas pegadas antes de v1.4.2 todavía traen el suyo armado
            // en Twig: se saca acá para no declarar dos Course distintos en la misma página.
            $output = preg_replace('#<script type="application/ld\+json">\s*\{\s*"@context":\s*"https://schema\.org",\s*"@type":\s*"Course".*?</script>#s', '', (string) $output, 1);

            return $this->full_bleed_wrap($output, $dominant_color_css);
        }

        /**
         * Primer token de "wpgroup" que sirve como subject-id real. El campo
         * mezcla temática y tags de campaña en cualquier orden (ej. "mundial2026
         * hot-gse2026 marzoal50-2026 nutricion-deportiva") — los tags de campaña
         * siempre traen un año/número pegado, la temática real nunca. Saltear
         * los que tengan un dígito alcanza para encontrar la temática sin
         * mantener una lista hardcodeada de campañas.
         */
        /**
         * URL de la landing de la organización que dicta la formación, en ESTE sitio:
         * {página "organizacion"}?slug={organization.data.slug} (la arma oec-wp-theme,
         * page-organizacion.php). El plugin también se instala en sitios sin ese tema, así
         * que solo se devuelve si el sitio tiene publicada una página con slug "organizacion";
         * si no, "" y la plantilla usa el link de antes ({comunidad}/es/socio/{id}).
         */
        private function organization_url($data) {
            $slug = sanitize_title($data['organization']['data']['slug'] ?? '');
            if ($slug === '') return '';
            $page = get_page_by_path('organizacion');
            if (!$page || $page->post_status !== 'publish') return '';
            return add_query_arg('slug', $slug, get_permalink($page));
        }

        private function extract_subject_id($wpgroup) {
            if (empty($wpgroup)) return null;
            foreach (preg_split('/\s+/', trim($wpgroup)) as $token) {
                if ($token !== '' && !preg_match('/\d/', $token)) {
                    return $token;
                }
            }
            return null;
        }

        /**
         * Hasta 10 formaciones de la misma temática (subject-id) que SÍ tengan
         * inscripción abierta — para ofrecer como alternativa cuando la
         * formación actual ya cerró la suya. Se cachea vía OEC_Api::call() como
         * cualquier otra llamada a la API (1 hora, por ser un listado).
         */
        private function get_similar_open_trainings($data) {
            $subjectId = $this->extract_subject_id($data['wpgroup'] ?? '');
            if (!$subjectId) return [];

            $endpoint = 'trainings?relevance=0&inc-reviews=1&subject-id=' . urlencode($subjectId);
            $results  = OEC_Api::call($endpoint);
            if (empty($results) || !is_array($results)) return [];

            $today   = date('Ymd');
            $similar = [];
            foreach ($results as $t) {
                if (($t['id'] ?? null) === ($data['id'] ?? null)) continue; // excluir la formación actual
                if (empty($t['enrollment_end']) || date('Ymd', strtotime($t['enrollment_end'])) < $today) continue;
                $similar[] = $t;
                if (count($similar) >= 10) break;
            }
            return $similar;
        }

        /**
         * Envuelve el contenido en un div que ocupa el 100% del ancho de la ventana,
         * escapando cualquier contenedor limitado del tema activo.
         * Funciona con cualquier tema porque usa JavaScript para calcular el offset
         * real del elemento respecto al borde de la pantalla.
         */
        private function full_bleed_wrap($html, $dominant_color_css = 'rgb(248,250,252)') {
            $uid = 'oec-fb-' . substr(md5(microtime()), 0, 8);

            $gradient = "linear-gradient(to bottom, {$dominant_color_css} 0px, rgb(248,250,252) 500px)";

            // Reemplazo de número de WhatsApp si el cliente usa su propio equipo de ventas si el cliente usa su propio equipo de ventas
            $equipo_oec      = get_option('oec_equipo_ventas', '1');
            $ventas_whatsapp = get_option('oec_ventas_whatsapp', '');
            $wa_script = '';
            if ($equipo_oec === '0' && !empty($ventas_whatsapp)) {
                $wa_num = esc_js($ventas_whatsapp);
                $wa_script = <<<JS

    <script>
    (function() {
        var oec_wa = '5493512584960';
        var cte_wa = '{$wa_num}';

        function replaceWA(root) {
            // 1. href de links de WhatsApp
            (root.querySelectorAll ? root : document).querySelectorAll('a[href*="' + oec_wa + '"]').forEach(function(el) {
                el.href = el.href.replace(oec_wa, cte_wa);
            });
            // 2. Nodos de texto visibles
            var walker = document.createTreeWalker(
                root.querySelectorAll ? root : document.body,
                NodeFilter.SHOW_TEXT,
                null,
                false
            );
            var node;
            while ((node = walker.nextNode())) {
                if (node.nodeValue.indexOf(oec_wa) !== -1) {
                    node.nodeValue = node.nodeValue.split(oec_wa).join(cte_wa);
                }
            }
        }

        // Ejecutar en el DOM inicial
        document.addEventListener('DOMContentLoaded', function() { replaceWA(document.body); });

        // Observar cambios dinámicos (lo que inyecta oec-trainings-v17.js después)
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) replaceWA(node);
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });

        // Detener el observer cuando la página ya terminó de cargar
        window.addEventListener('load', function() {
            // Último pase para asegurarse de no perder nada
            replaceWA(document.body);
            setTimeout(function() { observer.disconnect(); }, 3000);
        });
    })();
    </script>
    JS;
            }

            // Ancho completo ("full bleed") sin importar el contenedor del tema:
            // - SIN transform (antes: left:50% + translateX(-50%)). Un ancestro con transform se
            //   vuelve el marco de referencia de todo lo position:fixed que tenga adentro — era la
            //   causa real de los íconos flotantes/paneles que aparecían a miles de px de pantalla.
            // - El script de abajo corre ANTES de que se pinte el contenido y mide la posición real
            //   del contenedor del tema y el ancho sin barra de scroll (documentElement.clientWidth):
            //   funciona aunque el contenedor no esté centrado (temas con sidebar) y no genera scroll
            //   horizontal en Windows (100vw incluye la barra de scroll). Sin JS, cae a la fórmula
            //   clásica calc(50% - 50vw), que asume contenedor centrado.
            // - overflow-x: clip (no visible, como antes) en html/body: evita cualquier scroll
            //   horizontal igual que el "overflow-x: hidden" que usan muchos temas, pero sin volver
            //   a body un contenedor de scroll (eso rompía el position:sticky de la columna de
            //   precios). "visible" destapaba desbordes del propio tema (menús fuera de pantalla).
            return <<<HTML
    <div id="{$uid}" class="oec-bleed-host">
        <style>
            .oec-bleed-host { position: relative !important; width: 100% !important; overflow: visible !important; }
            #oec-bleed-wrapper {
                position: relative !important;
                width: var(--oec-bleed-w, 100vw) !important;
                max-width: none !important;
                margin-left: var(--oec-bleed-ml, calc(50% - 50vw)) !important;
                margin-right: 0 !important;
                left: auto !important; right: auto !important; transform: none !important;
                overflow: visible !important;
            }
            html, body { overflow-x: clip !important; }
            .wp-block-post-title { display: none !important; }
        </style>
        <div id="oec-bleed-wrapper">
            <script>(function(){var w=document.getElementById('oec-bleed-wrapper');if(!w)return;function f(){var p=w.parentElement.getBoundingClientRect();w.style.setProperty('--oec-bleed-ml',(-p.left)+'px');w.style.setProperty('--oec-bleed-w',document.documentElement.clientWidth+'px');}f();window.addEventListener('resize',f);window.addEventListener('load',f);})();</script>
            {$html}
        </div>
    </div>{$wa_script}
    HTML;
        }

        /**
         * Fechas "de calendario" de la API → instantes reales en la zona
         * horaria del sitio (Ajustes > Generales).
         *
         * La API manda días sueltos como si fueran instantes UTC, y no siempre
         * igual: el cierre de inscripción de un mismo curso llega como
         * 2026-09-24T00:00:00+00:00 en los listados y 2026-09-24T23:59:59+00:00
         * en la ficha. Para el negocio significa "la inscripción cierra al
         * TERMINAR el 24" en la hora del sitio. Leídos tal cual, el countdown
         * terminaba el 23 a las 21:00 (hora argentina) y el template daba la
         * inscripción por cerrada desde las 21:00 del 24.
         *
         * - Claves de CIERRE (enrollment_end, expiration del pago anticipado):
         *   se toma el día y se lleva a las 23:59:59 de ese día. Vale también
         *   para fechas sueltas "YYYY-MM-DD" (así llega expiration).
         * - Otras fechas de día (start, end, publish_start, date) que llegan
         *   como medianoche UTC: ese mismo día a las 00:00. Las que traen una
         *   hora real (ej. start 12:00) no se tocan.
         */
        private const DAY_END_KEYS = ['enrollment_end', 'expiration'];
        private const DAY_KEYS     = ['start', 'end', 'publish_start', 'date'];

        private function normalize_dates($value, $key = null) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = $this->normalize_dates($v, $k);
                }
                return $value;
            }
            if (!is_string($value) || !is_string($key)) return $value;

            if (in_array($key, self::DAY_END_KEYS, true)
                && preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(?:00:00:00|23:59:59)(?:\.0+)?(?:\+00:00|Z))?$/', $value, $m)) {
                return (new \DateTime($m[1] . ' 23:59:59', wp_timezone()))->format('c');
            }
            if (in_array($key, self::DAY_KEYS, true)
                && preg_match('/^(\d{4}-\d{2}-\d{2})(?:T00:00:00(?:\.0+)?(?:\+00:00|Z))?$/', $value, $m)) {
                return (new \DateTime($m[1] . ' 00:00:00', wp_timezone()))->format('c');
            }
            return $value;
        }

        /**
         * <iframe> del video de "Presentación" a partir de data.video_url — el mismo que antes
         * venía pegado en short_description (loading="lazy" + src del player), así la ficha se
         * ve igual. Acepta Vimeo (vimeo.com/ID, vimeo.com/ID/hash, player.vimeo.com/video/ID)
         * y YouTube (watch?v=, youtu.be/, /embed/, /shorts/). Cualquier otra URL → "" (no se
         * embebe una página cualquiera que llegue por la API).
         */
        public static function video_embed_iframe($url) {
            $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
            if ($url === '') return '';
            if (preg_match('#^https?://(?:www\.)?player\.vimeo\.com/video/(\d+)(?:\?([^\s"\'<>]*))?#i', $url, $m)) {
                $src = 'https://player.vimeo.com/video/' . $m[1] . (!empty($m[2]) ? '?' . $m[2] : '');
            } elseif (preg_match('#^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d+)(?:/([0-9a-f]+))?#i', $url, $m)) {
                $src = 'https://player.vimeo.com/video/' . $m[1] . (!empty($m[2]) ? '?h=' . $m[2] : '');
            } elseif (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#i', $url, $m)) {
                $src = 'https://www.youtube-nocookie.com/embed/' . $m[1];
            } else {
                return '';
            }
            return '<iframe loading="lazy" src="' . esc_url($src) . '" title="Video de presentación" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>';
        }

        /**
         * Pone al día una plantilla de la ficha pegada con una versión anterior del plugin, para
         * que cada sitio quede bien con solo actualizar el plugin, sin volver a pegarla. Cada
         * reemplazo apunta a un texto EXACTO de la plantilla vieja: en la actual no matchea nada.
         */
        private function upgrade_template($content) {
            $content = (string) $content;
            // < 1.4.1: "¿es la comunidad dueña?" buscaba la URL de la comunidad como texto dentro
            // de la URL de la página → falla con subdominios (nuevo.g-se.com). Ahora: solo dominio.
            $content = preg_replace('/data\.community\s+in\s+extra\.current_url/', 'extra.in_community', $content);
            // < 1.4.3: la bajada salía de description_oa (si tenía hasta 200 caracteres); ahora sale
            // de headline, y description_oa queda de respaldo con el mismo tope.
            $old_sub = "{% set oa_text = data.description_oa|default('')|striptags|trim %}";
            if (strpos($content, $old_sub) !== false) {
                $content = str_replace($old_sub, self::SUBTITLE_TWIG, $content);
                $content = str_replace('{% if oa_text and oa_text|length > 0 and oa_text|length <= 200 %}', '{% if oa_text and (headline_text or oa_text|length <= 200) %}', $content);
            }
            // < 1.4.3: el video de "Presentación" solo se buscaba pegado en short_description;
            // ahora también llega aparte en video_url.
            $content = preg_replace('/split_vimeo_intro(?!\s*\()/', "split_vimeo_intro(data.video_url|default(''))", $content);
            return $content;
        }

        /** Bajada del título: headline (sin HTML) o, si viene vacío, description_oa. */
        const SUBTITLE_TWIG = "{% set headline_text = data.headline|default('')|striptags|replace({'&nbsp;':' '})|trim %}{% set oa_text = headline_text ?: data.description_oa|default('')|striptags|trim %}";

        /** "17 de Septiembre de 2026" (o sin año), en la hora del sitio. Filtro Twig format_date y tarjetas de [oec-list]. */
        public static function format_date_es($date, $includeYear = true) {
            if (!$date) return '';

            if (is_numeric($date)) {
                $timestamp = (int) $date;
            } else {
                try {
                    // Sin zona en el string (ej. "2026-10-16"): es un día de
                    // calendario en la hora del sitio, no medianoche UTC.
                    $dt = new \DateTime($date, wp_timezone());
                    $timestamp = $dt->getTimestamp();
                } catch (\Exception $e) {
                    $timestamp = strtotime($date);
                }
            }

            if (!$timestamp || $timestamp === -1) return '';

            $meses_en = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            $meses_es = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

            $format = $includeYear ? 'd \d\e F \d\e Y' : 'd \d\e F';
            return str_replace($meses_en, $meses_es, self::site_date($format, $timestamp));
        }

        /** date() pero en la zona horaria del sitio. */
        private static function site_date($format, $timestamp) {
            return (new \DateTime('@' . (int) $timestamp))->setTimezone(wp_timezone())->format($format);
        }

        private function process_twig($template_string, $variables) {
            if (empty($template_string)) return '';

            try {
                // 1. Decodificar entidades HTML y caracteres especiales que mete WP
                $template_string = $this->full_html_decode($template_string);

                // 2. Fechas en la zona horaria del sitio (ver normalize_dates()):
                // "now"|date y los |date(...) del template calculan "hoy" en la
                // hora del sitio, no en UTC (WordPress fija PHP en UTC).
                $this->twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone(wp_timezone());
                $variables = $this->normalize_dates($variables);

                // 3. Renderizado Twig
                $template = $this->twig->createTemplate($template_string);
                return $template->render($variables);
            } catch (Exception $e) {
                return "Error en Twig: " . $e->getMessage();
            }
        }

        private function full_html_decode($string) {
            // wptexturize de WordPress convierte varios caracteres tipográficos.
            // Los revertimos ANTES de html_entity_decode para que strtr los encuentre.
            $fix = [
                // Smart quotes — entidades numéricas
                '&#8220;'  => '"',
                '&#8221;'  => '"',
                '&#8216;'  => "'",
                '&#8217;'  => "'",
                // Smart quotes — caracteres Unicode directos
                "\u{201C}" => '"',
                "\u{201D}" => '"',
                "\u{2018}" => "'",
                "\u{2019}" => "'",
                // Guiones — wptexturize convierte -- y --- en estos
                '&#8211;'  => '-',   // en dash –
                '&#8212;'  => '-',   // em dash —
                "\u{2013}" => '-',   // – en dash Unicode
                "\u{2014}" => '-',   // — em dash Unicode
                // Puntos suspensivos
                '&#8230;'  => '...',
                "\u{2026}" => '...',
                // Otras entidades comunes
                '&#038;'   => '&',
                '&amp;'    => '&',
                '&nbsp;'   => ' ',
                "\xc2\xa0" => ' ',
            ];
            $string = strtr($string, $fix);

            // Decodificar el resto de entidades estándar (&gt; → > etc.)
            $string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');

            return $string;
        }
    }
}
