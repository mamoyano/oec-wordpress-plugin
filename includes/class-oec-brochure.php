<?php
/**
 * Brochure de una formación: {ficha}/brochure/[?pais=XX]
 *
 * Página A4 autónoma (sin wp_head, sin tema, sin scripts) que el Worker journey.oe.center imprime
 * en PDF cuando alguien pide el brochure en el chat con IA. Es exclusiva del chat: no se enlaza
 * desde ningún lado y no se indexa.
 *
 * - Marca del sitio: la del tema OEC si está (mismas funciones que /wp-json/oec/v1/brand); si no
 *   (sitio de un socio), logo del sitio y color de wp-admin. Ver brand().
 * - Medios de pago del país de ?pais= (la misma API que la ficha). Sin país, un texto genérico.
 * - Sin precios, sin cuentas regresivas ni nonces: el mismo contenido da siempre el mismo HTML,
 *   así el Worker solo vuelve a imprimir cuando algo cambió de verdad (compara un hash).
 * - Formación inexistente: 404 (oec_get_page_bundle()).
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('OEC_Brochure')) {
    class OEC_Brochure {
        const PAY_API   = 'https://api.onlineeducation.center/campus/api/trainings/%s/currentEdition/payment-methods-locale?country=%s';
        const PAY_TTL   = 86400;
        const UTM       = 'utm_source=brochure&utm_medium=pdf';
        // Países cuyos medios de pago se juntan en el brochure sin país (pedido de Mario, 13/11/2026).
        const ALL_COUNTRIES = ['AR', 'CO', 'CL', 'MX', 'EC', 'BO', 'PE', 'US', 'ES', 'UY', 'SA'];

        public function __construct() {
            add_action('template_redirect', [$this, 'maybe_render'], 0);
        }

        public function maybe_render() {
            $qv = (string) get_query_var('oec_brochure');
            if ($qv === '' || !preg_match('/^t-[a-zA-Z0-9]{14}$/', $qv)) return;

            header('X-Robots-Tag: noindex, nofollow', true);
            header('Content-Type: text/html; charset=utf-8', true);

            $bundle = function_exists('oec_get_page_bundle') ? oec_get_page_bundle() : null;
            $data   = $bundle['data'] ?? null;
            if (!$bundle || !empty($bundle['not_found']) || !is_array($data) || empty($data['title'])) {
                // No existe (404 explícito de la API) o la API está caída sin copia guardada (503).
                $code = (!$bundle || !empty($bundle['not_found'])) ? 404 : 503;
                status_header($code);
                header('Cache-Control: public, max-age=60', true);
                echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Brochure no disponible</title><p>Este brochure no está disponible.</p>';
                exit;
            }

            $country = strtoupper(sanitize_text_field(wp_unslash($_GET['pais'] ?? '')));
            if (!preg_match('/^[A-Z]{2}$/', $country)) $country = '';

            try {
                $html = $this->render($data, $country, $bundle);
            } catch (\Throwable $e) {
                error_log('OEC brochure: ' . $e->getMessage());
                status_header(500);
                echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>No se pudo generar el brochure.</p>';
                exit;
            }

            status_header(200);
            header('Cache-Control: public, max-age=3600', true);
            header('X-OEC-Brochure: 1', true);
            $canonical = self::ficha_url($data);
            if ($canonical) header('Link: <' . esc_url_raw($canonical) . '>; rel="canonical"', true);
            echo $html;
            exit;
        }

        /** Arma el HTML. Público para poder probarlo sin pasar por la URL. */
        public function render(array $data, $country = '', array $bundle = []) {
            $twig = new \Twig\Environment(
                new \Twig\Loader\FilesystemLoader(plugin_dir_path(__DIR__) . 'templates'),
                ['autoescape' => 'html', 'strict_variables' => false]
            );
            $twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone(wp_timezone());
            $twig->addFilter(new \Twig\TwigFilter('format_date', fn($d, $y = true) => OEC_Shortcodes::format_date_es($d, $y)));
            $twig->addFilter(new \Twig\TwigFilter('rich', [self::class, 'rich'], ['is_safe' => ['html']]));
            $twig->addFilter(new \Twig\TwigFilter('plain', [self::class, 'plain']));
            $twig->addFilter(new \Twig\TwigFilter('img', [self::class, 'img_url']));
            // Texto real, no un "-" o "&nbsp;" de relleno (pasa en asignaturas sin descripción).
            $twig->addFilter(new \Twig\TwigFilter('has_text', fn($h) => (bool) preg_match('/[\p{L}\p{N}]/u', self::plain($h))));
            $twig->addFilter(new \Twig\TwigFilter('stars', fn($n) => str_repeat('★', max(0, min(5, (int) $n)))));

            $today    = current_time('Y-m-d');
            $open     = !empty($data['enrollment_end']) && substr((string) $data['enrollment_end'], 0, 10) >= $today;
            $in_comm  = function_exists('oec_is_community_site') && oec_is_community_site($data['community'] ?? '');
            $enroll   = $in_comm ? ($data['register_url'] ?? '') : ($data['register'] ?? '');
            $contact  = function_exists('oec_contact_channels') ? oec_contact_channels($data, false, $open) : null;
            $ficha    = self::ficha_url($data);
            $sync     = strtoupper((string) ($data['synchronicity'] ?? ''));
            $force    = !empty($data['force_contact']);

            // WhatsApp solo si el canal está siempre disponible: con horario de ventas (comunidades,
            // USD 300 o más) un PDF no sabe a qué hora lo van a leer.
            $whatsapp = ($contact && !empty($contact['whatsapp']) && empty($contact['hours'])) ? $contact['whatsapp'] : '';

            $css = (string) @file_get_contents(plugin_dir_path(__DIR__) . 'css/oec-brochure.css');

            return $twig->render('brochure.twig', [
                'data'      => $data,
                'brand'     => self::brand($data),
                'css'       => $css,
                'site_name' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                'site_host' => preg_replace('/^www\./', '', (string) wp_parse_url(home_url(), PHP_URL_HOST)),
                'in_community' => $in_comm,
                'open'      => $open,
                'force_contact' => $force,
                'enroll_url'=> ($open && !$force && $enroll) ? self::with_utm($enroll) : '',
                'ficha_url' => $ficha ? self::with_utm($ficha) : '',
                'sync'      => $sync,
                'async'     => in_array($sync, ['ASYNC', 'ASYNC-F'], true),
                'access'    => OEC_Shortcodes::access_info($data),
                'teachers'  => self::teachers($data),
                'payments'  => $force ? null : self::payments($data['id'] ?? '', $country),
                'reviews'   => self::top_reviews($bundle['reviews']['reviews'] ?? []),
                'review_summary' => self::review_summary($bundle['summary'] ?? null),
                'country'   => $country,
                'country_name' => self::country_name($country),
                'whatsapp'  => $whatsapp,
                'chat_ia'   => (bool) apply_filters('oec_chat_ia_available', false),
                'partner_email' => ($contact && ($contact['form'] ?? '') === 'partner') ? ($contact['email'] ?? '') : '',
            ]);
        }

        /** URL de la ficha en ESTE sitio (lo que ve quien abre el PDF). */
        public static function ficha_url(array $data) {
            $slug = (string) ($data['slug'] ?? '');
            if (!preg_match('/t-[a-zA-Z0-9]{14}$/', $slug)) $slug = (string) ($data['id'] ?? '');
            return $slug ? get_site_url() . '/formacion/' . rawurlencode($slug) : '';
        }

        private static function with_utm($url) {
            return $url . (strpos($url, '?') === false ? '?' : '&') . self::UTM;
        }

        /**
         * Marca del sitio. Con el tema OEC: nombre, logo de email (pensado para fondo oscuro) y
         * colores de Tema OEC → Identidad, igual que /wp-json/oec/v1/brand. Sin el tema (socio): logo
         * del sitio (o el de la organización si el sitio es de ella), color de marca del plugin y
         * encabezado claro. Filtro oec_brochure_brand para casos especiales.
         */
        public static function brand(array $data) {
            if (function_exists('oec_nl_colors') && defined('OEC_NL_LOGO')) {
                $c = (array) oec_nl_colors();
                $brand = [
                    'name'    => defined('OEC_NL_BRAND') ? (string) OEC_NL_BRAND : get_bloginfo('name'),
                    'logo'    => (string) OEC_NL_LOGO,
                    'header'  => 'dark',
                    'dark'    => $c['dark'] ?? '#071B2D',
                    'primary' => $c['primary'] ?? '#194872',
                    'accent'  => $c['accent'] ?? '#e8952a',
                    'text'    => $c['text'] ?? '#1e2d3d',
                ];
            } else {
                $primary = sanitize_hex_color(get_option('oec_brand_color', '#a435f0')) ?: '#a435f0';
                $logo = '';
                $logo_id = (int) get_theme_mod('custom_logo') ?: (int) get_option('site_logo');
                if ($logo_id) $logo = (string) wp_get_attachment_image_url($logo_id, 'medium');
                $header = 'light';
                $dark   = '#1e2d3d';
                if (!$logo) {
                    // El logo de la organización es claro (va sobre su color_header): encabezado oscuro.
                    $org  = $data['organization']['data'] ?? [];
                    $host = fn($u) => preg_replace('/^www\./', '', strtolower((string) wp_parse_url((string) $u, PHP_URL_HOST)));
                    if (!empty($org['logo']) && !empty($org['domain']) && $host($org['domain']) === $host(home_url())) {
                        $logo   = self::img_url((string) $org['logo'], 400);
                        $header = 'dark';
                        $dark   = sanitize_hex_color($org['color_header'] ?? '') ?: $dark;
                    }
                }
                $brand = [
                    'name'    => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                    'logo'    => $logo,
                    'header'  => $header,
                    'dark'    => $dark,
                    'primary' => $primary,
                    'accent'  => $primary,
                    'text'    => '#1e2d3d',
                ];
            }
            $brand = (array) apply_filters('oec_brochure_brand', $brand, $data);
            foreach (['dark', 'primary', 'accent', 'text'] as $k) {
                $brand[$k] = sanitize_hex_color($brand[$k] ?? '') ?: '#1e2d3d';
            }
            return $brand;
        }

        /** Docentes de la formación + los de las asignaturas, sin repetir. */
        private static function teachers(array $data) {
            $out = [];
            $add = function ($t) use (&$out) {
                $id = $t['id'] ?? ($t['full_name'] ?? '');
                if (!$id || isset($out[$id])) return;
                $out[$id] = $t;
            };
            foreach ((array) ($data['teachers']['data'] ?? []) as $t) $add($t);
            foreach ((array) ($data['modules']['data'] ?? []) as $m) {
                foreach ((array) ($m['subjects']['data'] ?? []) as $s) {
                    foreach ((array) ($s['teachers']['data'] ?? []) as $t) $add($t);
                }
            }
            return array_values($out);
        }

        /**
         * Medios de pago para el brochure, con caché de 24 h en wp_options (prefijo oec_cache_, así el
         * botón "Actualizar caché" también los borra).
         * - Con país: los de ese país (nombre y logo), como la ficha.
         * - Sin país: todos los de los países de ALL_COUNTRIES juntos, uno por logo (para la grilla de
         *   logos). Se piden en paralelo una vez por formación y por día: dependen de la empresa que
         *   cobra (OEC, G-SE, un socio…), así que no sirve una lista única para todas.
         * null = sin datos (API caída y sin copia): la plantilla muestra un texto genérico.
         */
        public static function payments($id, $country) {
            if (!preg_match('/^t-[a-zA-Z0-9]{14}$/', (string) $id)) return null;
            $countries = $country ? [$country] : self::ALL_COUNTRIES;
            $key = 'oec_cache_' . md5('brochure-pay/' . $id . '/' . implode(',', $countries));
            $use_cache = defined('OEC_CACHE_ENABLED') ? OEC_CACHE_ENABLED : true;

            $cached = $use_cache ? get_option($key) : false;
            if ($cached && ($cached['expires'] ?? 0) > time()) return $cached['data'] ?: null;
            if (OEC_Api::is_down($key)) return OEC_Api::stale($key) ?: null;

            $requests = [];
            foreach ($countries as $c) {
                $requests[$c] = ['url' => sprintf(self::PAY_API, rawurlencode($id), rawurlencode($c)), 'headers' => ['Accept' => 'application/json']];
            }
            $responses = \WpOrg\Requests\Requests::request_multiple($requests, ['timeout' => OEC_Api::TIMEOUT, 'connect_timeout' => 4]);

            $list = [];
            $ok   = 0;
            foreach ($countries as $c) {
                $res = $responses[$c] ?? null;
                if (!($res instanceof \WpOrg\Requests\Response) || !$res->success) continue;
                $ok++;
                $decoded = json_decode($res->body, true);
                foreach ((array) ($decoded['data'] ?? []) as $p) {
                    $name = trim((string) ($p['es']['name'] ?? ''));
                    if ($name === '') continue;
                    $logo = (string) ($p['logo'] ?? '');
                    $file = $logo ? substr($logo, strrpos($logo, '/') + 1) : '';
                    $item = [
                        'name' => $name,
                        // Igual que la ficha (checkPayOpts): el logo pasa por imgrsize.
                        'logo' => $file ? 'https://imgrsize.oe-img.center/statics/checkout/' . rawurlencode($file) . '?w=120&q=90' : '',
                    ];
                    if ($country) { $list[] = $item; continue; }
                    // Todos los países: uno por logo (la tarjeta y la transferencia comparten ícono en
                    // varios países), con un nombre corto que sirva para todos.
                    if (!$file || isset($list[$file])) continue;
                    if (stripos($file, 'credit_card') !== false) $item['name'] = 'Tarjetas de crédito y débito';
                    elseif (stripos($file, 'banktransfer') !== false) $item['name'] = 'Transferencia bancaria';
                    // "Pagos Seguros en Línea (PSE)" → "PSE"; "… Argentina (PagoTic)" → "PagoTic".
                    elseif (preg_match('/\(([^)]+)\)\s*$/', $name, $m)) $item['name'] = trim($m[1]);
                    $list[$file] = $item;
                }
            }
            if ($ok < count($countries)) {
                // Faltó algún país: mejor la última lista completa que una a medias.
                OEC_Api::mark_down($key);
                $stale = OEC_Api::stale($key);
                if ($stale || !$list) return $stale ?: null;
            } else {
                update_option($key, ['data' => array_values($list), 'expires' => time() + self::PAY_TTL], false);
            }
            return $list ? array_values($list) : null;
        }

        /** Hasta 3 opiniones para el brochure: 4 o 5 estrellas y un comentario con contenido (60+ letras). */
        private static function top_reviews($reviews) {
            $out = [];
            foreach ((array) $reviews as $r) {
                if (count($out) >= 3) break;
                $text = self::plain($r['comment'] ?? '');
                if ((int) ($r['rating'] ?? 0) < 4 || mb_strlen($text) < 60) continue;
                if (mb_strlen($text) > 420) $text = rtrim(mb_substr($text, 0, mb_strrpos(mb_substr($text, 0, 400), ' '))) . '…';
                $r['comment'] = $text;
                $out[] = $r;
            }
            return $out;
        }

        /** Promedio y cantidad, solo si hay al menos 5 opiniones y el promedio es 4 o más. */
        private static function review_summary($s) {
            $avg = (float) ($s['average'] ?? 0);
            $n   = (int) ($s['count'] ?? 0);
            return ($n >= 5 && $avg >= 4) ? ['average' => round($avg, 1), 'count' => $n] : null;
        }

        /** "Argentina" para AR (extensión intl de PHP); '' si no está o no se conoce el código. */
        private static function country_name($code) {
            if (!$code || !class_exists('Locale')) return '';
            $name = (string) \Locale::getDisplayRegion('-' . $code, 'es');
            return ($name !== '' && strcasecmp($name, $code) !== 0) ? $name : '';
        }

        /** HTML de la API, limpio para imprimir: solo etiquetas de texto, sin estilos ni párrafos vacíos. */
        public static function rich($html) {
            $html = (string) $html;
            if ($html === '') return '';
            // Texto sin etiquetas de bloque (ej. la descripción de la organización): cada salto, un párrafo.
            if (!preg_match('#<(p|ul|ol|li|br|h[1-6]|div)\b#i', $html)) {
                $html = '<p>' . implode('</p><p>', preg_split('/\s*\R\s*/', trim($html))) . '</p>';
            }
            $html = wp_kses($html, [
                'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
                'ul' => [], 'ol' => [], 'li' => [], 'a' => ['href' => true], 'h3' => [], 'h4' => [],
            ]);
            $html = preg_replace('#<p>(?:\s|&nbsp;|<br\s*/?>)*</p>#i', '', $html);
            // "<p>texto</p><p>" sin cerrar al final (pasa en las asignaturas).
            $html = preg_replace('#<p>\s*$#', '', $html);
            // <li> sueltos, fuera de una lista.
            if (stripos($html, '<li') !== false && stripos($html, '<ul') === false && stripos($html, '<ol') === false) {
                $html = preg_replace('#((?:\s*<li>.*?</li>)+)#is', '<ul>$1</ul>', $html);
            }
            return force_balance_tags(trim($html));
        }

        public static function plain($html) {
            $t = html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES, 'UTF-8');
            return trim(preg_replace('/\s+/u', ' ', $t));
        }

        /** Imagen de static1 vía imgrsize (como la ficha), en JPG: imprime igual en cualquier Chromium. */
        public static function img_url($url, $width = 600) {
            $url = (string) $url;
            if ($url === '' || strpos($url, 'static1.onlineeducation.center/uploads/') === false) return $url;
            $path = substr($url, strpos($url, '/uploads/') + strlen('/uploads/'));
            return 'https://imgrsize.oe-img.center/' . $path . '?w=' . (int) $width . '&q=85';
        }
    }
}
