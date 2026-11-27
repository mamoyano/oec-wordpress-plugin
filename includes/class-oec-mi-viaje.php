<?php
/**
 * wp-admin → OEC → Mi viaje (v1.5.33): el viaje de los leads del socio, armado en Lead Journey
 * (journey.oe.center, src/mi-viaje.js; diseño en docs/socios-herramientas.md de Lead Journey).
 *
 * - Etapas: Identificado (dejó sus datos en el formulario de la ficha), Considerando (preguntó precio, cuotas,
 *   fechas… o volvió), Decidiendo (empezó el checkout en su subdominio), Alumno (pagó). Estado: activo, frío,
 *   cerrada, baja.
 * - El formulario de la ficha con "¿Equipo de Ventas de OEC?" en NO sigue yendo al email del socio y además avisa a
 *   Lead Journey (lead()).
 * - Embudo, lista por etapa, persona, WhatsApp uno por uno con el texto de cada etapa, estado y nota, CSV.
 * - La primera vez se migran el estado, la nota, los contactos y las bajas que el socio tenía en su WordPress
 *   (OEC_Contacts, OEC_Mailer).
 * - Con "Equipo de Ventas de OEC" en SÍ, solo el embudo (sin datos de contacto): lo atiende OEC.
 *
 * El navegador nunca habla con Lead Journey: todo pasa por admin-ajax con nonce + manage_options, y el servidor
 * llama con el token de la API del sitio (X-OEC-Token), igual que el registro (OEC_Site_Registry).
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('OEC_Mi_Viaje')) {
    class OEC_Mi_Viaje {
        const NONCE = 'oec_mv';
        const STAGES = [2 => 'Identificado', 3 => 'Considerando', 4 => 'Decidiendo', 5 => 'Alumno'];
        const WA_DEFAULTS = [
            2 => "Hola {nombre}, ¿cómo estás? Te escribo porque consultaste por {formacion}. ¿Te puedo ayudar con alguna duda? Acá tienes toda la información: {link}",
            3 => "Hola {nombre}, ¿cómo estás? Te escribo por {formacion}. Si quieres, te cuento los precios, las cuotas y las fechas. Toda la información: {link}",
            4 => "Hola {nombre}, vi que empezaste la inscripción a {formacion} y no llegaste a terminarla. ¿Tuviste algún problema con el pago? Te ayudo a completarla.",
            5 => "Hola {nombre}, ¡gracias por inscribirte en {formacion}! Cualquier duda que tengas, escríbeme por acá.",
        ];

        const MAIL_DEFAULTS = [
            2 => ['subject' => '{nombre}, ¿te quedó alguna duda sobre {formacion}?',
                  'body'    => "Hola {nombre}:\n\nTe escribo porque consultaste por {formacion}. ¿Te quedó alguna duda?\n\nAcá tienes toda la información y la inscripción:\n{link}\n\nSi prefieres, responde este email y te ayudo.\n\n¡Saludos!"],
            3 => ['subject' => '{nombre}, precio y fechas de {formacion}',
                  'body'    => "Hola {nombre}:\n\nTe cuento lo que me consultaste de {formacion}: el precio para tu país es {precio}.\n\nToda la información, las fechas y la inscripción:\n{link}\n\nSi tienes cualquier otra duda, responde este email.\n\n¡Saludos!"],
            4 => ['subject' => '{nombre}, ¿te ayudo a terminar tu inscripción?',
                  'body'    => "Hola {nombre}:\n\nVi que empezaste la inscripción a {formacion} y no llegaste a terminarla. ¿Tuviste algún problema con el pago?\n\nPuedes retomarla acá:\n{link}\n\nSi algo no funcionó, responde este email y lo resolvemos.\n\n¡Saludos!"],
            5 => ['subject' => '{nombre}, gracias por inscribirte en {formacion}',
                  'body'    => "Hola {nombre}:\n\n¡Gracias por inscribirte en {formacion}! Cualquier duda que tengas antes de empezar, responde este email.\n\n¡Saludos!"],
        ];

        public static function init() {
            add_action('admin_menu', [__CLASS__, 'menu'], 20);
            add_action('wp_ajax_oec_mv', [__CLASS__, 'ajax']);
            add_action('admin_post_oec_mv_csv', [__CLASS__, 'csv']);
            add_action('oec_mail_sent', [__CLASS__, 'mail_sent'], 10, 3);
            // v1.5.34: Estadísticas (Zoho) ya no está; su dirección lleva a Mi viaje.
            // admin_page_access_denied: WordPress corta las páginas que no existen antes de admin_init.
            add_action('admin_page_access_denied', function () {
                if (($_GET['page'] ?? '') === 'oec-stats' && current_user_can('manage_options')) {
                    wp_safe_redirect(admin_url('admin.php?page=oec-mi-viaje'));
                    exit;
                }
            });
        }

        public static function menu() {
            add_submenu_page('oec-main', 'Mi viaje', 'Mi viaje', 'manage_options', 'oec-mi-viaje', [__CLASS__, 'render_page'], 2);
        }

        /* ── Variables del email: vencimiento y precio ─────────────────── */

        /** La formación del socio ({ id, title, slug, enrollment_end, async }) o null. */
        public static function formation($fid) {
            foreach (self::formations() as $f) if ($f['id'] === $fid) return $f;
            return null;
        }

        /** {vencimiento}: "15 de diciembre de 2026", el día en que cierra la inscripción ('' si no tiene). */
        public static function deadline_text($fid) {
            $f = self::formation((string) $fid);
            if (!$f || $f['async'] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['enrollment_end'])) return '';
            $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            [$y, $m, $d] = array_map('intval', explode('-', $f['enrollment_end']));
            return $d . ' de ' . $months[$m - 1] . ' de ' . $y;
        }

        /** Moneda en que la ficha muestra los precios a cada país. */
        private static function currency_for($country) {
            $c = strtoupper((string) $country);
            $local = ['AR' => 'ARS', 'MX' => 'MXN', 'CO' => 'COP', 'CL' => 'CLP'];
            $eur = ['ES', 'PT', 'FR', 'IT', 'DE', 'AD', 'AT', 'BE', 'NL', 'IE', 'GR', 'FI', 'LU'];
            return $local[$c] ?? (in_array($c, $eur, true) ? 'EUR' : 'USD');
        }

        /**
         * {precio}: el precio para el país de la persona, de la misma API pública que la ficha ("ARS 194.400", o con el
         * descuento vigente "ARS 116.640 (40 % de descuento)"). null = la API no respondió (reintentar); false = no tiene.
         */
        public static function price_text($fid, $country) {
            if (!preg_match('/^t-[A-Za-z0-9]{14}$/', (string) $fid)) return false;
            $country = preg_match('/^[A-Z]{2}$/', strtoupper((string) $country)) ? strtoupper($country) : 'AR';
            $cur = self::currency_for($country);
            $key = 'oec_mv_price_' . md5($fid . '|' . $country . '|' . $cur);
            $c = get_transient($key);
            if ($c !== false) return $c === 'err' ? null : ($c === 'none' ? false : $c);
            $res = wp_remote_get('https://api.onlineeducation.center/api-oas/v1/trainings/' . rawurlencode($fid) . '/prices?country=' . $country . '&currency=' . $cur, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
            $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
            $p = $code === 200 ? json_decode(wp_remote_retrieve_body($res), true) : null;
            if ($code === 404 || ($code === 200 && is_array($p) && (!isset($p['total']) || empty($p['currency'])))) {
                set_transient($key, 'none', HOUR_IN_SECONDS);
                return false;
            }
            if (!is_array($p)) {
                set_transient($key, 'err', 5 * MINUTE_IN_SECONDS);
                return null;
            }
            $fmt = function ($n) use ($p) {
                $dec = in_array($p['currency'], ['USD', 'EUR'], true) && floor((float) $n) != (float) $n ? 2 : 0;
                return $p['currency'] . ' ' . number_format((float) $n, $dec, ',', '.');
            };
            $pct = (float) ($p['discountPercent'] ?? 0);
            $text = $fmt($p['total']);
            if ($pct > 0 && isset($p['toPriceDiscount'])) {
                $until = '';
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) ($p['expiration'] ?? ''), $m)) {
                    $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
                    $until = ' hasta el ' . intval($m[3]) . ' de ' . $months[intval($m[2]) - 1];
                }
                $text = $fmt($p['toPriceDiscount']) . ' (' . rtrim(rtrim(number_format($pct, 1, ',', ''), '0'), ',') . ' % de descuento' . $until . ')';
            }
            set_transient($key, $text, HOUR_IN_SECONDS);
            return $text;
        }

        /** Un email de una campaña de Mi viaje salió: queda en la historia de la persona en Lead Journey. */
        public static function mail_sent($row, $campaign, $subject) {
            if (strpos((string) ($campaign['stage'] ?? ''), 'mv:') !== 0) return;
            $token = (string) get_option('oec_token', '');
            if ($token === '') return;
            wp_remote_post(self::base() . '/v1/mi-viaje/seguimiento', [
                'timeout'  => 3,
                'blocking' => false,
                'headers'  => ['Content-Type' => 'application/json', 'X-OEC-Token' => $token],
                'body'     => wp_json_encode(self::who() + [
                    'who' => strtolower($row['email']), 'formation_id' => $row['training_uid'], 'contact' => 'email', 'subject' => $subject,
                    'by' => 'Envío desde tu Gmail',
                ]),
            ]);
        }

        public static function mail_templates() {
            $saved = get_option('oec_mv_mail', []);
            $out = [];
            foreach (self::MAIL_DEFAULTS as $st => $def) {
                $t = is_array($saved) && isset($saved[$st]) && is_array($saved[$st]) ? $saved[$st] : [];
                $out[$st] = ['subject' => ($t['subject'] ?? '') !== '' ? $t['subject'] : $def['subject'], 'body' => ($t['body'] ?? '') !== '' ? $t['body'] : $def['body']];
            }
            return $out;
        }

        /* ── Envío de email a los elegidos ──────────────────────────────── */

        /**
         * Las personas elegidas, vueltas a pedir a Lead Journey: del navegador solo llegan email + formación, así no se le
         * puede escribir a nadie que no esté en el viaje del sitio. Sin las que se dieron de baja (en Mi viaje o en el plugin).
         */
        private static function chosen_people() {
            $pairs = json_decode(wp_unslash($_POST['people'] ?? '[]'), true);
            $want = [];
            foreach ((array) $pairs as $x) {
                $e = strtolower(sanitize_email($x['who'] ?? ''));
                $f = sanitize_text_field($x['fid'] ?? '');
                if (is_email($e) && preg_match('/^t-[A-Za-z0-9]{14}$/', $f)) $want[$e . '|' . $f] = true;
            }
            if (!$want) wp_send_json_error(['message' => 'No elegiste a nadie.']);
            if (count($want) > 2000) wp_send_json_error(['message' => 'Elige hasta 2.000 personas por envío.']);
            [$code, $data] = self::remote('lista', ['csv' => true], 45);
            if ($code !== 200 || !is_array($data)) wp_send_json_error(['message' => 'No se pudo cargar tu lista. Prueba de nuevo en unos minutos.']);
            $out = [];
            foreach ((array) ($data['people'] ?? []) as $p) {
                $k = ($p['email'] ?? '') . '|' . ($p['fid'] ?? '');
                if (isset($want[$k]) && ($p['state'] ?? '') !== 'baja') $out[$k] = $p;
            }
            if ($out && class_exists('OEC_Mailer')) {
                $unsubs = array_flip(OEC_Mailer::unsubscribed(array_column($out, 'email')));
                $out = array_filter($out, function ($p) use ($unsubs) { return !isset($unsubs[$p['email']]); });
            }
            return $out;
        }

        private static function split_name($name) {
            $parts = preg_split('/\s+/', trim((string) $name), 2);
            return [$parts[0] ?? '', $parts[1] ?? ''];
        }

        private static function mail_from_post() {
            $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
            $body    = sanitize_textarea_field(wp_unslash($_POST['body'] ?? ''));
            if ($subject === '' || trim($body) === '') wp_send_json_error(['message' => 'Completa el asunto y el mensaje.']);
            return [$subject, $body];
        }

        private static function campaign_for($fid, $subject, $body) {
            $f = self::formation($fid);
            return [
                'training_uid'   => $fid,
                'training_title' => $f ? $f['title'] : $fid,
                'training_link'  => $f && $f['slug'] !== '' ? home_url('/formacion/' . $f['slug']) : home_url('/'),
                'subject'        => $subject,
                'body'           => $body,
            ];
        }

        /** "Enviarme una prueba": el email de la primera persona elegida, a mi email. */
        private static function mail_test() {
            if (!class_exists('OEC_Mailer') || OEC_Mailer::google_email() === '' || OEC_Mailer::google_broken()) wp_send_json_error(['message' => 'Conecta tu cuenta de Google en OEC → Configuración.']);
            [$subject, $body] = self::mail_from_post();
            $people = self::chosen_people();
            $p = reset($people);
            if (!$p) wp_send_json_error(['message' => 'Ninguna de las personas elegidas puede recibir el email.']);
            [$first, $last] = self::split_name($p['name'] ?? '');
            [$s, $html, $text, $missing] = OEC_Mailer::render(self::campaign_for($p['fid'], $subject, $body), ['email' => $p['email'], 'first_name' => $first, 'last_name' => $last, 'phone' => $p['phone'] ?? '', 'country' => $p['country'] ?? '']);
            if ($missing) wp_send_json_error(['message' => $missing === 'none' ? 'Esa formación no tiene precio para ' . ($p['country'] ?: 'su país') . ': saca {precio} del mensaje.' : 'No se pudo obtener el precio. Prueba de nuevo en unos minutos.']);
            $to  = wp_get_current_user()->user_email;
            $err = OEC_Mailer::send($to, '[Prueba] ' . $s, $html, $text);
            if ($err) wp_send_json_error(['message' => $err]);
            wp_send_json_success(['message' => 'Prueba enviada a ' . $to . ' (con los datos de ' . ($p['name'] ?: $p['email']) . ').']);
        }

        /** Crea una campaña por formación con las personas elegidas y las encola (sale por OEC_Mailer, desde su Gmail). */
        private static function mail_send() {
            if (!class_exists('OEC_Mailer') || OEC_Mailer::google_email() === '' || OEC_Mailer::google_broken()) wp_send_json_error(['message' => 'Conecta tu cuenta de Google en OEC → Configuración.']);
            [$subject, $body] = self::mail_from_post();
            $stage  = (int) ($_POST['stage'] ?? 0);
            $people = self::chosen_people();
            if (!$people) wp_send_json_error(['message' => 'Ninguna de las personas elegidas puede recibir el email (dadas de baja o ya no están en tu viaje).']);
            global $wpdb;
            $now = current_time('mysql', true);
            $by = [];
            foreach ($people as $p) $by[$p['fid']][] = $p;
            $cids = [];
            foreach ($by as $fid => $list) {
                $camp = self::campaign_for($fid, $subject, $body);
                $wpdb->insert($wpdb->prefix . 'oec_mail_campaigns', $camp + [
                    'stage' => 'mv:' . $stage, 'reply_to' => OEC_Mailer::google_email(), 'created_by' => get_current_user_id(), 'created_at' => $now,
                ]);
                $cid = (int) $wpdb->insert_id;
                if (!$cid) continue;
                $cids[] = $cid;
                foreach ($list as $p) {
                    [$first, $last] = self::split_name($p['name'] ?? '');
                    $wpdb->insert($wpdb->prefix . 'oec_mail_queue', [
                        'campaign_id' => $cid, 'training_uid' => $fid, 'email' => $p['email'],
                        'first_name' => mb_substr($first, 0, 100), 'last_name' => mb_substr($last, 0, 100),
                        'phone' => mb_substr((string) ($p['phone'] ?? ''), 0, 50), 'country' => substr((string) ($p['country'] ?? ''), 0, 10),
                        'status' => 'queued', 'created_at' => $now,
                    ]);
                }
            }
            if (!$cids) wp_send_json_error(['message' => 'No se pudo guardar el envío.']);
            OEC_Mailer::schedule(time() + 30);
            wp_send_json_success(['campaigns' => $cids, 'progress' => self::mail_progress($cids)]);
        }

        private static function mail_progress(array $cids) {
            $sum = ['sent' => 0, 'pending' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
            foreach ($cids as $cid) {
                $p = OEC_Mailer::progress((int) $cid);
                foreach (['sent', 'pending', 'failed', 'skipped'] as $k) $sum[$k] += $p[$k];
                $sum['errors'] = array_values(array_unique(array_merge($sum['errors'], $p['errors'])));
            }
            return $sum;
        }

        /** OEC atiende las consultas del sitio ("Equipo de Ventas de OEC" en SÍ). */
        private static function oec_attends() {
            return get_option('oec_equipo_ventas', '1') === '1';
        }

        private static function base() {
            return apply_filters('oec_journey_url', defined('OEC_JOURNEY_URL') ? OEC_JOURNEY_URL : 'https://journey.oe.center');
        }

        /** Dominio, prefijo y subdominio del sitio: lo mismo que manda el registro. */
        private static function who() {
            $p = class_exists('OEC_Site_Registry') ? OEC_Site_Registry::payload(true) : [];
            return ['domain' => $p['domain'] ?? '', 'prefix' => $p['prefix'] ?? '', 'subdomain' => $p['subdomain'] ?? ''];
        }

        /* ── El formulario de la ficha ──────────────────────────────────── */

        /** Consulta del formulario de la ficha (switch en NO): se avisa a Lead Journey sin esperar la respuesta. */
        public static function lead($email, $uid, $name, $lastname, $phone, $country, $question) {
            $token = (string) get_option('oec_token', '');
            if ($token === '' || !preg_match('/^t-[A-Za-z0-9]{14}$/', $uid)) return;
            $e164 = class_exists('OEC_Contacts') ? OEC_Contacts::normalize_phone($phone, $country) : '';
            wp_remote_post(self::base() . '/v1/mi-viaje/lead', [
                'timeout'  => 3,
                'blocking' => false,
                'headers'  => ['Content-Type' => 'application/json', 'X-OEC-Token' => $token],
                'body'     => wp_json_encode(self::who() + [
                    'email' => $email, 'formation_id' => $uid, 'name' => $name, 'lastname' => $lastname,
                    'phone' => $e164 !== '' ? '+' . $e164 : '', 'country' => $country, 'question' => $question,
                ]),
            ]);
        }

        /* ── Formaciones del socio (de la API de OEC) ────────────────────── */

        /** [{ id, title, slug, enrollment_end, async }] de todas las formaciones del token. Caché de 6 horas. */
        public static function formations() {
            $cached = get_transient('oec_mv_forms');
            if (is_array($cached)) return $cached;
            $token = (string) get_option('oec_token', '');
            $out = [];
            for ($pg = 1; $pg <= 10 && $token !== ''; $pg++) {
                $res = wp_remote_get('https://oas-api.onlineeducation.center/api-oas/v1/trainings?relevance=0&pg=' . $pg, [
                    'headers' => ['X-API-TOKEN' => $token, 'Accept' => 'application/json'],
                    'timeout' => class_exists('OEC_Api') ? OEC_Api::TIMEOUT : 8,
                ]);
                if (is_wp_error($res) || wp_remote_retrieve_response_code($res) >= 400) break;
                $d = json_decode(wp_remote_retrieve_body($res), true);
                foreach ((array) ($d['data'] ?? []) as $t) {
                    if (!preg_match('/^t-[A-Za-z0-9]{14}$/', (string) ($t['id'] ?? ''))) continue;
                    $out[] = [
                        'id'             => $t['id'],
                        'title'          => (string) ($t['title'] ?? ''),
                        'slug'           => (string) ($t['slug'] ?? ''),
                        'enrollment_end' => substr((string) ($t['enrollment_end'] ?? ''), 0, 10),
                        'async'          => strpos(strtoupper((string) ($t['synchronicity'] ?? '')), 'ASYNC') === 0,
                    ];
                }
                if ($pg >= intval($d['meta']['pagination']['total_pages'] ?? 1)) break;
            }
            if ($out) set_transient('oec_mv_forms', $out, 6 * HOUR_IN_SECONDS);
            return $out;
        }

        /* ── Lead Journey ───────────────────────────────────────────────── */

        /** POST /v1/mi-viaje/<op>. Devuelve [código, datos]. */
        private static function remote($op, array $body, $timeout = 20) {
            $token = (string) get_option('oec_token', '');
            if ($token === '') return [0, null];
            $forms = array_map(function ($f) { return ['id' => $f['id'], 'title' => $f['title'], 'enrollment_end' => $f['enrollment_end'], 'async' => $f['async']]; }, self::formations());
            $res = wp_remote_post(self::base() . '/v1/mi-viaje/' . $op, [
                'timeout' => $timeout,
                'headers' => ['Content-Type' => 'application/json', 'X-OEC-Token' => $token],
                'body'    => wp_json_encode(self::who() + ['formations' => $forms] + $body),
            ]);
            $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
            if (!$code || $code >= 500) error_log('OEC mi viaje: ' . $op . ' → ' . ($code ?: (is_wp_error($res) ? $res->get_error_message() : 'sin respuesta')));
            return [$code, $code ? json_decode((string) wp_remote_retrieve_body($res), true) : null];
        }

        /** Filtros que manda el navegador, validados. */
        private static function filters() {
            $g = function ($k) { return sanitize_text_field(wp_unslash($_REQUEST[$k] ?? '')); };
            $out = [];
            if (preg_match('/^t-[A-Za-z0-9]{14}$/', $g('formation_id'))) $out['formation_id'] = $g('formation_id');
            if (in_array((int) $g('days'), [7, 30, 90, 180, 365], true)) $out['days'] = (int) $g('days');
            if (isset(self::STAGES[(int) $g('stage')])) $out['stage'] = (int) $g('stage');
            if (in_array($g('state'), ['activo', 'frio', 'cerrada', 'baja', 'alumno'], true)) $out['state'] = $g('state');
            if (preg_match('/^[A-Z]{2}$/', $g('country'))) $out['country'] = $g('country');
            if ($g('sin_contactar') === '1') $out['sin_contactar'] = true;
            if ($g('q') !== '') $out['q'] = mb_substr($g('q'), 0, 80);
            return $out;
        }

        public static function ajax() {
            check_ajax_referer(self::NONCE, 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error(null, 403);
            $op = sanitize_key($_POST['op'] ?? '');
            $who = strtolower(sanitize_text_field(wp_unslash($_POST['who'] ?? '')));
            if (!is_email($who) && !preg_match('/^tel:\d{6,20}$/', $who)) $who = '';
            switch ($op) {
                case 'embudo':
                case 'lista':
                    [$code, $data] = self::remote($op, self::filters());
                    break;
                case 'persona':
                    if ($who === '') wp_send_json_error(null, 400);
                    [$code, $data] = self::remote('persona', ['who' => $who]);
                    break;
                case 'seguimiento':
                    if ($who === '') wp_send_json_error(null, 400);
                    $body = ['who' => $who, 'by' => wp_get_current_user()->display_name];
                    $fid  = sanitize_text_field(wp_unslash($_POST['formation_id'] ?? ''));
                    if (preg_match('/^t-[A-Za-z0-9]{14}$/', $fid)) $body['formation_id'] = $fid;
                    if (isset($_POST['contact'])) $body['contact'] = sanitize_key($_POST['contact']);
                    if (isset($_POST['status'])) $body['status'] = $_POST['status'] === '_none' ? '' : sanitize_key($_POST['status']);
                    if (isset($_POST['note'])) $body['note'] = mb_substr(sanitize_text_field(wp_unslash($_POST['note'])), 0, 500);
                    if (isset($_POST['unsub'])) $body['unsub'] = $_POST['unsub'] === '1';
                    [$code, $data] = self::remote('seguimiento', $body);
                    // La baja también vale para las campañas de email del plugin (OEC_Mailer).
                    if ($code === 200 && isset($body['unsub']) && is_email($who)) self::mailer_unsub($who, $body['unsub']);
                    break;
                case 'migrar':
                    wp_send_json_success(self::migrate());
                    return;
                case 'mail_test':
                    self::mail_test();
                    return;
                case 'mail_send':
                    self::mail_send();
                    return;
                case 'mail_progress':
                    $cids = array_filter(array_map('intval', (array) json_decode(wp_unslash($_POST['campaigns'] ?? '[]'), true)));
                    $info = OEC_Mailer::process(OEC_Mailer::BATCH_AJAX, 20);
                    wp_send_json_success(self::mail_progress($cids) + ['paused' => $info['paused'], 'error' => $info['error']]);
                    return;
                case 'mail_tpl':
                    $st = (int) ($_POST['stage'] ?? 0);
                    if (!isset(self::STAGES[$st])) wp_send_json_error(null, 400);
                    $tpls = self::mail_templates();
                    $tpls[$st] = ['subject' => sanitize_text_field(wp_unslash($_POST['subject'] ?? '')), 'body' => sanitize_textarea_field(wp_unslash($_POST['body'] ?? ''))];
                    update_option('oec_mv_mail', $tpls, false);
                    wp_send_json_success(['mail' => self::mail_templates()]);
                    return;
                case 'wa':
                    $msgs = self::wa_messages();
                    $st = (int) ($_POST['stage'] ?? 0);
                    if (!isset(self::STAGES[$st])) wp_send_json_error(null, 400);
                    $msgs[$st] = mb_substr(sanitize_textarea_field(wp_unslash($_POST['text'] ?? '')), 0, 1000);
                    if (trim($msgs[$st]) === '') $msgs[$st] = self::WA_DEFAULTS[$st];
                    update_option('oec_mv_wa', $msgs, false);
                    wp_send_json_success(['wa' => $msgs]);
                    return;
                default:
                    wp_send_json_error(null, 400);
                    return;
            }
            if ($code === 200 && is_array($data)) wp_send_json_success($data);
            wp_send_json_error(['code' => $code, 'error' => is_array($data) ? ($data['error'] ?? '') : ''], $code && $code < 500 ? $code : 502);
        }

        public static function wa_messages() {
            $saved = get_option('oec_mv_wa', []);
            $out = [];
            foreach (self::WA_DEFAULTS as $st => $def) $out[$st] = is_array($saved) && !empty($saved[$st]) ? (string) $saved[$st] : $def;
            return $out;
        }

        private static function mailer_unsub($email, $on) {
            global $wpdb;
            $t = $wpdb->prefix . 'oec_mail_unsubs';
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return;
            if ($on) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (email, created_at) VALUES (%s, %s)", strtolower($email), current_time('mysql', true)));
            else $wpdb->delete($t, ['email' => strtolower($email)]);
        }

        /**
         * Migración (una vez, se puede repetir): estado y nota (oec_contact_state), contactos (oec_contact_events) y
         * bajas (oec_mail_unsubs) → Lead Journey, en tandas de 200.
         */
        public static function migrate() {
            global $wpdb;
            $table = function ($n) use ($wpdb) {
                $t = $wpdb->prefix . $n;
                return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t ? $t : null;
            };
            $sent = ['states' => 0, 'events' => 0, 'unsubs' => 0];
            $ok = true;
            $push = function ($body) use (&$sent, &$ok) {
                [$code, $data] = self::remote('importar', $body, 30);
                if ($code !== 200) { $ok = false; return; }
                foreach ($sent as $k => $v) $sent[$k] += intval($data[$k] ?? 0);
            };
            if ($t = $table('oec_contact_state')) {
                foreach (array_chunk($wpdb->get_results("SELECT training_uid, ckey, status, note, updated_at FROM $t", ARRAY_A), 200) as $chunk) {
                    $push(['states' => array_map(function ($r) {
                        return ['formation_id' => $r['training_uid'], 'who' => $r['ckey'], 'status' => $r['status'], 'note' => $r['note'], 'updated_at' => $r['updated_at']];
                    }, $chunk)]);
                }
            }
            if ($t = $table('oec_contact_events')) {
                foreach (array_chunk($wpdb->get_results("SELECT training_uid, ckey, type, created_at FROM $t", ARRAY_A), 300) as $chunk) {
                    $push(['events' => array_map(function ($r) {
                        return ['formation_id' => $r['training_uid'], 'who' => $r['ckey'], 'type' => $r['type'], 'at' => $r['created_at']];
                    }, $chunk)]);
                }
            }
            if ($t = $table('oec_mail_unsubs')) {
                foreach (array_chunk($wpdb->get_col("SELECT email FROM $t"), 1000) as $chunk) $push(['unsubs' => $chunk]);
            }
            if ($ok) update_option('oec_mv_migrated', time(), false);
            return ['ok' => $ok] + $sent;
        }

        /* ── CSV ────────────────────────────────────────────────────────── */

        public static function csv() {
            if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['nonce'] ?? '', self::NONCE)) wp_die('Sin permiso', 403);
            [$code, $data] = self::remote('lista', self::filters() + ['csv' => true], 45);
            if ($code !== 200 || !is_array($data)) wp_die('No se pudo bajar la lista. Prueba de nuevo en un rato.', 502);
            $stages = [1 => 'Curioso'] + self::STAGES;
            $states = ['activo' => 'Activo', 'frio' => 'Frío', 'cerrada' => 'Inscripción cerrada', 'baja' => 'Se dio de baja', 'alumno' => 'Alumno'];
            $track  = class_exists('OEC_Contacts') ? OEC_Contacts::STATUSES : [];
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="mi-viaje-' . gmdate('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nombre', 'Email', 'Teléfono', 'País', 'Formación', 'Etapa', 'Estado', 'Seguimiento', 'Nota', 'Primera consulta', 'Último movimiento', 'Último contacto']);
            // Una celda que empieza con = + - @ se abre como fórmula en una planilla: se neutraliza (menos el teléfono).
            $safe = function ($v) { $v = (string) $v; return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; };
            foreach ((array) ($data['people'] ?? []) as $p) {
                fputcsv($out, [
                    $safe($p['name'] ?? ''), $safe($p['email'] ?? ''), (string) ($p['phone'] ?? ''), $safe($p['country'] ?? ''), $safe($p['title'] ?? ''),
                    $stages[$p['stage'] ?? 0] ?? '', $states[$p['state'] ?? ''] ?? '', $track[$p['status'] ?? ''] ?? '', $safe($p['note'] ?? ''),
                    $p['first_at'] ?? '', $p['last_at'] ?? '', $p['last_contact'] ?? '',
                ]);
            }
            fclose($out);
            exit;
        }

        /* ── Pantalla ───────────────────────────────────────────────────── */

        public static function render_page() {
            if (!get_option('oec_token')) {
                echo '<div class="wrap"><h1>Mi viaje</h1><p>Configura tu API Token en la pestaña de Configuración.</p></div>';
                return;
            }
            $brand = get_option('oec_brand_color', '#a435f0') ?: '#a435f0';
            $forms = self::formations();
            $links = [];
            foreach ($forms as $f) $links[$f['id']] = $f['slug'] !== '' ? home_url('/formacion/' . $f['slug']) : home_url('/');
            usort($forms, function ($a, $b) { return strcasecmp($a['title'], $b['title']); });
            $cfg = [
                'ajax'     => admin_url('admin-ajax.php'),
                'csv'      => admin_url('admin-post.php?action=oec_mv_csv'),
                'nonce'    => wp_create_nonce(self::NONCE),
                'oec'      => self::oec_attends(),
                'migrate'  => !self::oec_attends() && !get_option('oec_mv_migrated'),
                'links'    => $links,
                'wa'       => self::wa_messages(),
                'mail'     => self::mail_templates(),
                'gmail'    => class_exists('OEC_Mailer') && OEC_Mailer::google_email() !== '' && !OEC_Mailer::google_broken() ? OEC_Mailer::google_email() : '',
                'limit'    => class_exists('OEC_Mailer') ? OEC_Mailer::daily_limit() : 400,
                'today'    => class_exists('OEC_Mailer') ? OEC_Mailer::sent_today() : 0,
                'config'   => admin_url('admin.php?page=oec-main#oec-gmail'),
                'links_vars' => ['nombre', 'apellido', 'formacion', 'link', 'precio', 'vencimiento'],
                'statuses' => class_exists('OEC_Contacts') ? OEC_Contacts::STATUSES : ['' => 'Sin contactar'],
            ];
            ?>
            <style>
                .oec-mv { --oec-mv-brand: <?php echo esc_html($brand); ?>; max-width: 1300px; }
                .oec-mv h1 { margin: 16px 0 4px; }
                .oec-mv-sub { color: #646970; margin: 0 0 16px; max-width: 820px; }
                .oec-mv-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 16px; }
                .oec-mv-bar select, .oec-mv-bar input[type=search] { min-height: 32px; }
                .oec-mv-funnel { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
                .oec-mv-card { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 14px 16px; text-align: left; font: inherit; color: #1d2327; }
                button.oec-mv-card { cursor: pointer; }
                button.oec-mv-card:hover, button.oec-mv-card:focus-visible { border-color: var(--oec-mv-brand); box-shadow: 0 0 0 1px var(--oec-mv-brand); outline: none; }
                .oec-mv-card.is-on { border-color: var(--oec-mv-brand); box-shadow: 0 0 0 1px var(--oec-mv-brand); }
                .oec-mv-card .n { display: block; font-size: 28px; font-weight: 600; line-height: 1.15; font-variant-numeric: tabular-nums; }
                .oec-mv-card .l { display: block; font-weight: 600; margin-bottom: 4px; }
                .oec-mv-card .d { display: block; font-size: 12px; color: #646970; margin-top: 4px; }
                .oec-mv-card--final .n { color: var(--oec-mv-brand); }
                .oec-mv-month { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; color: #50575e; }
                .oec-mv-month strong { color: #1d2327; }
                .oec-mv-box { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 0; margin-bottom: 16px; overflow: auto; }
                .oec-mv-box h2 { font-size: 15px; margin: 0; padding: 14px 16px; border-bottom: 1px solid #f0f0f1; }
                .oec-mv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
                .oec-mv-table th { text-align: left; font-weight: 600; color: #50575e; font-size: 11.5px; padding: 10px 10px 8px; border-bottom: 1px solid #dcdcde; white-space: nowrap; }
                .oec-mv-table td { padding: 9px 10px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
                .oec-mv-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
                .oec-mv-table tr.is-click { cursor: pointer; }
                .oec-mv-table tr.is-click:hover td { background: #f6f7f7; }
                .oec-mv-dim { color: #a7aaad; }
                .oec-mv-tag { display: inline-block; font-size: 11px; line-height: 1; padding: 4px 7px; border-radius: 20px; background: #f0f0f1; color: #50575e; white-space: nowrap; }
                .oec-mv-tag--frio { background: #eef4fb; color: #2271b1; }
                .oec-mv-tag--baja { background: #fcf0f1; color: #b32d2e; }
                .oec-mv-tag--cerrada { background: #f6f7f7; color: #646970; }
                .oec-mv-tag--alumno { background: #edfaef; color: #1a7a33; }
                .oec-mv-acts { display: inline-flex; gap: 4px; }
                .oec-mv-act { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; padding: 0; border-radius: 50%; border: 1px solid #dcdcde; background: #fff; color: #50575e; cursor: pointer; text-decoration: none; }
                .oec-mv-act .dashicons { font-size: 15px; width: 15px; height: 15px; line-height: 1 !important; }
                .oec-mv-act--wa { color: #1a8d48; border-color: #b8e6c9; }
                .oec-mv-act--wa:hover { background: #25d366; color: #fff; }
                .oec-mv-act--call { color: #2271b1; border-color: #c5d9ed; }
                .oec-mv-act--call:hover { background: #2271b1; color: #fff; }
                .oec-mv-act:hover { background: #50575e; color: #fff; }
                .oec-mv-name { font-weight: 500; }
                .oec-mv-mail { font-size: 12px; color: #646970; }
                .oec-mv-status { font-size: 12px; min-height: 28px; max-width: 150px; }
                .oec-mv-note { width: 100%; min-width: 140px; font-size: 12px; min-height: 28px; }
                .oec-mv-wa { background: #f6fff9; border: 1px solid #d5f0de; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; }
                .oec-mv-wa textarea { width: 100%; font-size: 13px; }
                .oec-mv-wa p { margin: 6px 0 0; color: #50575e; font-size: 12px; }
                .oec-mv-note-legal { color: #646970; font-size: 12px; margin: 8px 0 24px; }
                .oec-mv-msg { padding: 28px; text-align: center; color: #646970; }
                .oec-mv-err { background: #fcf0f1; border: 1px solid #f0c4c6; color: #8a2424; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
                .oec-mv-hist { list-style: none; margin: 0; padding: 8px 16px 16px; }
                .oec-mv-hist li { padding: 8px 0; border-bottom: 1px solid #f0f0f1; display: grid; grid-template-columns: 150px 1fr; gap: 12px; }
                .oec-mv-hist .when { color: #646970; font-size: 12px; }
                .oec-mv-hist .what { white-space: pre-wrap; }
                .oec-mv-person { display: flex; flex-wrap: wrap; gap: 8px 24px; align-items: center; padding: 14px 16px; border-bottom: 1px solid #f0f0f1; }
                .oec-mv-person h2 { border: 0; padding: 0; }
                .oec-mv-flash { color: #1a7a33; font-size: 12px; margin-left: 8px; }
                .oec-mv-compose { background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px; display: grid; gap: 10px; }
                .oec-mv-compose label { font-weight: 600; font-size: 12px; display: block; margin-bottom: 4px; }
                .oec-mv-compose input[type=text], .oec-mv-compose textarea { width: 100%; }
                .oec-mv-vars { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 12px; color: #646970; }
                .oec-mv-vars button { font-size: 11.5px; border: 1px solid #dcdcde; background: #f6f7f7; border-radius: 20px; padding: 2px 9px; cursor: pointer; }
                .oec-mv-prev { background: #f6f7f7; border-radius: 8px; padding: 10px 14px; font-size: 13px; white-space: pre-wrap; }
                .oec-mv-prev strong { display: block; margin-bottom: 6px; }
                .oec-mv-row-btns { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
                .oec-mv-progress { font-size: 13px; }
                .oec-mv-table .chk { width: 26px; }
                @media (max-width: 782px) { .oec-mv-hist li { grid-template-columns: 1fr; gap: 2px; } }
            </style>
            <div class="wrap oec-mv" id="oec-mv">
                <h1>Mi viaje</h1>
                <p class="oec-mv-sub">
                    <?php if (self::oec_attends()) : ?>
                        Las consultas de tu sitio las atiende el equipo de OEC. Acá ves en qué etapa está cada persona que se interesó por tus formaciones, y cuántas se inscribieron.
                    <?php else : ?>
                        Las personas que consultaron por tus formaciones, en la etapa en que están: dejaron sus datos, preguntaron precio o fechas, empezaron la inscripción o ya se inscribieron. Toca una etapa para ver quiénes son y escribirles.
                    <?php endif; ?>
                </p>
                <div class="oec-mv-bar">
                    <label class="screen-reader-text" for="oec-mv-form">Formación</label>
                    <select id="oec-mv-form">
                        <option value="">Todas las formaciones</option>
                        <?php foreach ($forms as $f) : ?>
                            <option value="<?php echo esc_attr($f['id']); ?>"><?php echo esc_html($f['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="screen-reader-text" for="oec-mv-days">Período</label>
                    <select id="oec-mv-days">
                        <option value="">Desde siempre</option>
                        <option value="7">Últimos 7 días</option>
                        <option value="30">Últimos 30 días</option>
                        <option value="90">Últimos 3 meses</option>
                        <option value="365">Último año</option>
                    </select>
                </div>
                <div id="oec-mv-main"><div class="oec-mv-msg">Cargando…</div></div>
            </div>
            <script>
            (function () {
                var CFG = <?php echo wp_json_encode($cfg); ?>;
                var STAGES = { 1: 'Curioso', 2: 'Identificado', 3: 'Considerando', 4: 'Decidiendo', 5: 'Alumno' };
                var HINT = {
                    1: 'Hablaron con el chat sin dejar su email.',
                    2: 'Dejaron sus datos en el formulario.',
                    3: 'Preguntaron precio, cuotas o fechas, o volvieron.',
                    4: 'Empezaron la inscripción y no pagaron.',
                    5: 'Se inscribieron.'
                };
                var STATES = { activo: 'Activo', frio: 'Frío', cerrada: 'Inscripción cerrada', baja: 'Se dio de baja', alumno: 'Alumno' };
                var main = document.getElementById('oec-mv-main');
                var selForm = document.getElementById('oec-mv-form');
                var selDays = document.getElementById('oec-mv-days');
                var view = { stage: 0, state: '', country: '', sin: false, q: '' };
                var people = [];
                var picked = {};
                function pkey(p) { return p.email + '|' + p.fid; }

                function el(tag, attrs, text) {
                    var n = document.createElement(tag);
                    if (attrs) Object.keys(attrs).forEach(function (k) { if (attrs[k] !== null && attrs[k] !== undefined) n.setAttribute(k, attrs[k]); });
                    if (text !== undefined && text !== null) n.textContent = text;
                    return n;
                }
                function icon(name) { return el('span', { 'class': 'dashicons dashicons-' + name, 'aria-hidden': 'true' }); }
                function post(data) {
                    var fd = new FormData();
                    fd.append('action', 'oec_mv');
                    fd.append('nonce', CFG.nonce);
                    Object.keys(data).forEach(function (k) { if (data[k] !== undefined && data[k] !== null && data[k] !== '' && data[k] !== false) fd.append(k, data[k] === true ? '1' : data[k]); });
                    return fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
                }
                function filters() { return { formation_id: selForm.value, days: selDays.value }; }
                function fmtDate(s) {
                    if (!s) return '';
                    var d = new Date(String(s).replace(' ', 'T') + 'Z');
                    return isNaN(d) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
                }
                function ago(s) {
                    if (!s) return '';
                    var days = Math.floor((Date.now() - new Date(String(s).replace(' ', 'T') + 'Z')) / 864e5);
                    return days <= 0 ? 'hoy' : days === 1 ? 'ayer' : 'hace ' + days + ' días';
                }
                function failMsg(res) {
                    var c = res && res.data && res.data.code;
                    return c === 401 ? 'Lead Journey no reconoce el token de este sitio. Revisa el API Token en Configuración.'
                        : 'No se pudo cargar. Prueba de nuevo en un rato.';
                }
                function msg(text, cls) { main.replaceChildren(el('div', { 'class': cls || 'oec-mv-msg' }, text)); }
                function firstName(p) {
                    var n = String(p.name || '').trim().split(/\s+/)[0] || '';
                    return n ? n.charAt(0).toUpperCase() + n.slice(1).toLowerCase() : '';
                }
                function waText(p) {
                    return String(CFG.wa[p.stage] || CFG.wa[2] || '')
                        .replace(/\{nombre\}/g, firstName(p)).replace(/\{formacion\}/g, p.title || '')
                        .replace(/\{link\}/g, CFG.links[p.fid] || '').replace(/\s+$/, '');
                }
                function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
                function digits(phone) { return String(phone || '').replace(/\D/g, ''); }

                /* ── Embudo ── */
                function loadFunnel() {
                    msg('Cargando…');
                    post(Object.assign({ op: 'embudo' }, filters())).then(function (res) {
                        if (!res || !res.success) return msg(failMsg(res), 'oec-mv-err');
                        // Manda lo que Lead Journey tiene registrado del sitio (el switch se sincroniza con el registro diario).
                        CFG.oec = res.data.mode === 'oec';
                        drawFunnel(res.data);
                        if (!CFG.oec && view.stage) loadList();
                    }).catch(function () { msg('No se pudo cargar. Prueba de nuevo en un rato.', 'oec-mv-err'); });
                }
                var funnelBox, listBox;
                function drawFunnel(d) {
                    main.replaceChildren();
                    funnelBox = main.appendChild(el('div', { 'class': 'oec-mv-funnel' }));
                    d.stages.forEach(function (s) {
                        if (s.stage === 1 && !CFG.oec) return; // Curioso: llega con el contador de visitas
                        var tag = CFG.oec ? 'div' : 'button';
                        var c = funnelBox.appendChild(el(tag, { 'class': 'oec-mv-card' + (s.stage === 5 ? ' oec-mv-card--final' : '') + (view.stage === s.stage ? ' is-on' : ''), type: CFG.oec ? null : 'button' }));
                        c.appendChild(el('span', { 'class': 'l' }, STAGES[s.stage]));
                        c.appendChild(el('span', { 'class': 'n' }, String(s.total)));
                        var parts = [];
                        if (s.stage < 5) { parts.push(plural(s.activo || 0, 'activa', 'activas')); if (s.frio) parts.push(plural(s.frio, 'fría', 'frías')); if (s.cerrada) parts.push(s.cerrada + ' con la inscripción cerrada'); }
                        if (s.baja) parts.push(s.baja + ' de baja');
                        c.appendChild(el('span', { 'class': 'd' }, parts.join(' · ') || HINT[s.stage]));
                        c.title = HINT[s.stage];
                        if (!CFG.oec) c.addEventListener('click', function () { view.stage = view.stage === s.stage ? 0 : s.stage; view.state = ''; drawFunnel(d); if (view.stage) loadList(); });
                    });
                    var m = d.month || {}, a = m.actual || {}, p = m.anterior || {};
                    var mb = main.appendChild(el('div', { 'class': 'oec-mv-month' }));
                    var pct = function (x) { return x === null || x === undefined ? '–' : String(x).replace('.', ',') + ' %'; };
                    mb.appendChild(el('strong', null, 'Este mes: '));
                    mb.appendChild(document.createTextNode(plural(a.nuevos || 0, 'persona nueva', 'personas nuevas') + ', ' + plural(a.alumnos || 0, 'inscripta', 'inscriptas') + ' (' + pct(a.conversion) + '). '));
                    mb.appendChild(el('strong', null, 'Mes anterior: '));
                    mb.appendChild(document.createTextNode(plural(p.nuevos || 0, 'nueva', 'nuevas') + ', ' + plural(p.alumnos || 0, 'inscripta', 'inscriptas') + ' (' + pct(p.conversion) + ').'));
                    if (!d.subdomain) mb.appendChild(el('div', { 'class': 'oec-mv-dim' }, 'Todavía no sabemos el subdominio de tu checkout: las inscripciones aparecen después del próximo registro diario del sitio.'));
                    if (!d.total) {
                        main.appendChild(el('div', { 'class': 'oec-mv-box' })).appendChild(el('div', { 'class': 'oec-mv-msg' },
                            CFG.oec ? 'Todavía no hay personas en tu viaje.' : 'Todavía no hay personas en tu viaje. Aparecen cuando alguien deja sus datos en el formulario de una ficha o empieza la inscripción en tu checkout.'));
                    } else if (!selForm.value && d.formations && d.formations.length > 1) {
                        var fb = main.appendChild(el('div', { 'class': 'oec-mv-box' }));
                        fb.appendChild(el('h2', null, 'Por formación'));
                        var t = fb.appendChild(el('table', { 'class': 'oec-mv-table' }));
                        var hr = t.appendChild(el('thead')).appendChild(el('tr'));
                        ['Formación'].concat([2, 3, 4, 5].map(function (s) { return STAGES[s]; }), ['Frías']).forEach(function (h, i) { hr.appendChild(el('th', i ? { style: 'text-align:right' } : null, h)); });
                        var tb = t.appendChild(el('tbody'));
                        d.formations.forEach(function (f) {
                            var tr = tb.appendChild(el('tr', { 'class': 'is-click', title: 'Ver solo esta formación' }));
                            tr.appendChild(el('td', null, f.title));
                            [1, 2, 3, 4].forEach(function (i) { tr.appendChild(el('td', { 'class': 'num' + (f.stages[i] ? '' : ' oec-mv-dim') }, String(f.stages[i]))); });
                            tr.appendChild(el('td', { 'class': 'num' + (f.frio ? '' : ' oec-mv-dim') }, String(f.frio)));
                            tr.addEventListener('click', function () { if (Array.prototype.some.call(selForm.options, function (o) { return o.value === f.fid; })) { selForm.value = f.fid; loadFunnel(); } });
                        });
                    }
                    listBox = main.appendChild(el('div'));
                    if (CFG.oec) main.appendChild(el('p', { 'class': 'oec-mv-note-legal' }, 'Para ver los datos de contacto y escribirles tú, desactiva "¿Equipo de Ventas de OEC?" en Configuración.'));
                }

                /* ── Lista de una etapa ── */
                function loadList() {
                    listBox.replaceChildren(el('div', { 'class': 'oec-mv-msg' }, 'Cargando…'));
                    post(Object.assign({ op: 'lista', stage: view.stage, state: view.state, country: view.country, sin_contactar: view.sin, q: view.q }, filters())).then(function (res) {
                        if (!res || !res.success) return listBox.replaceChildren(el('div', { 'class': 'oec-mv-err' }, failMsg(res)));
                        people = res.data.people || [];
                        picked = {};
                        drawList(res.data.total);
                    });
                }
                function drawList(total) {
                    listBox.replaceChildren();
                    var bar = listBox.appendChild(el('div', { 'class': 'oec-mv-bar' }));
                    bar.appendChild(el('strong', null, STAGES[view.stage] + ': ' + total + (total === 1 ? ' persona' : ' personas')));
                    var st = bar.appendChild(el('select', { 'aria-label': 'Estado' }));
                    [['', 'Todos los estados'], ['activo', 'Activas'], ['frio', 'Frías'], ['cerrada', 'Inscripción cerrada'], ['baja', 'Se dieron de baja']].forEach(function (o) { st.appendChild(el('option', { value: o[0] }, o[1])); });
                    st.value = view.state;
                    st.addEventListener('change', function () { view.state = st.value; loadList(); });
                    var sin = bar.appendChild(el('label')); var cb = sin.appendChild(el('input', { type: 'checkbox' })); cb.checked = view.sin; sin.appendChild(document.createTextNode(' Sin contactar'));
                    cb.addEventListener('change', function () { view.sin = cb.checked; loadList(); });
                    var q = bar.appendChild(el('input', { type: 'search', placeholder: 'Buscar nombre o email', value: view.q }));
                    q.addEventListener('change', function () { view.q = q.value.trim(); loadList(); });
                    var params = Object.assign({ stage: view.stage, state: view.state, sin_contactar: view.sin ? '1' : '', q: view.q, nonce: CFG.nonce }, filters());
                    var href = CFG.csv + Object.keys(params).filter(function (k) { return params[k]; }).map(function (k) { return '&' + k + '=' + encodeURIComponent(params[k]); }).join('');
                    var dl = bar.appendChild(el('a', { 'class': 'button', href: href })); dl.appendChild(icon('download')); dl.appendChild(document.createTextNode(' Descargar CSV'));
                    var wab = bar.appendChild(el('button', { type: 'button', 'class': 'button' })); wab.appendChild(icon('whatsapp')); wab.appendChild(document.createTextNode(' Mensaje de WhatsApp'));
                    var mb = bar.appendChild(el('button', { type: 'button', 'class': 'button button-primary', disabled: '' }));
                    mb.appendChild(icon('email-alt')); var mbTxt = mb.appendChild(document.createTextNode(' Enviar email (0)'));
                    var compose = listBox.appendChild(el('div', { 'class': 'oec-mv-compose', hidden: '' }));
                    mb.addEventListener('click', function () { compose.hidden = !compose.hidden; if (!compose.hidden) drawCompose(compose); });
                    updatePicked = function () {
                        var n = Object.keys(picked).length;
                        mbTxt.textContent = ' Enviar email (' + n + ')';
                        if (n) mb.removeAttribute('disabled'); else { mb.setAttribute('disabled', ''); compose.hidden = true; }
                        if (!compose.hidden) drawCompose(compose);
                    };

                    var wa = listBox.appendChild(el('div', { 'class': 'oec-mv-wa', hidden: '' }));
                    wa.appendChild(el('label', { 'for': 'oec-mv-watext' }, 'Mensaje de WhatsApp para «' + STAGES[view.stage] + '»'));
                    var ta = wa.appendChild(el('textarea', { id: 'oec-mv-watext', rows: '3' })); ta.value = CFG.wa[view.stage] || '';
                    var wp = wa.appendChild(el('p', null, 'Variables: {nombre} {formacion} {link}. '));
                    var save = wp.appendChild(el('button', { type: 'button', 'class': 'button button-small' }, 'Guardar'));
                    var fl = wp.appendChild(el('span', { 'class': 'oec-mv-flash' }));
                    wab.addEventListener('click', function () { wa.hidden = !wa.hidden; });
                    save.addEventListener('click', function () {
                        post({ op: 'wa', stage: view.stage, text: ta.value }).then(function (res) { if (res && res.success) { CFG.wa = res.data.wa; ta.value = CFG.wa[view.stage]; fl.textContent = 'Guardado'; setTimeout(function () { fl.textContent = ''; }, 2000); } });
                    });

                    var box = listBox.appendChild(el('div', { 'class': 'oec-mv-box' }));
                    if (!people.length) { box.appendChild(el('div', { 'class': 'oec-mv-msg' }, 'No hay personas con estos filtros.')); return; }
                    var t = box.appendChild(el('table', { 'class': 'oec-mv-table' }));
                    var hr = t.appendChild(el('thead')).appendChild(el('tr'));
                    var all = hr.appendChild(el('th', { 'class': 'chk' })).appendChild(el('input', { type: 'checkbox', title: 'Elegir todas (menos las que se dieron de baja)' }));
                    ['Persona', 'País', 'Formación', 'Último movimiento', 'Estado', 'Seguimiento', 'Nota', ''].forEach(function (h) { hr.appendChild(el('th', null, h)); });
                    var tb = t.appendChild(el('tbody'));
                    people.forEach(function (p) { tb.appendChild(row(p)); });
                    all.addEventListener('change', function () {
                        tb.querySelectorAll('input[type=checkbox]:not([disabled])').forEach(function (b) { b.checked = all.checked; });
                        people.forEach(function (p) { if (p.state !== 'baja') { if (all.checked) picked[pkey(p)] = p; else delete picked[pkey(p)]; } });
                        updatePicked();
                    });
                    if (total > people.length) box.appendChild(el('div', { 'class': 'oec-mv-msg' }, 'Se muestran ' + people.length + ' de ' + total + '. Descarga el CSV para verlas todas.'));
                    listBox.appendChild(el('p', { 'class': 'oec-mv-note-legal' }, 'Los datos que descargas o copias pasan a ser tu responsabilidad: úsalos solo para responder a estas personas por tus formaciones, y respeta a quienes se dieron de baja.'));
                }
                function stateTag(p) { return el('span', { 'class': 'oec-mv-tag oec-mv-tag--' + p.state }, STATES[p.state] || p.state); }
                var updatePicked = function () {};
                function row(p) {
                    var tr = el('tr');
                    var cb = tr.appendChild(el('td', { 'class': 'chk' })).appendChild(el('input', { type: 'checkbox', 'aria-label': 'Elegir a ' + (p.name || p.email) }));
                    if (p.state === 'baja') { cb.disabled = true; cb.title = 'Se dio de baja: no recibe emails'; }
                    cb.addEventListener('change', function () { if (cb.checked) picked[pkey(p)] = p; else delete picked[pkey(p)]; updatePicked(); });
                    var who = tr.appendChild(el('td'));
                    var nb = who.appendChild(el('a', { href: '#', 'class': 'oec-mv-name' }, p.name || p.email));
                    nb.addEventListener('click', function (e) { e.preventDefault(); openPerson(p.email); });
                    who.appendChild(el('div', { 'class': 'oec-mv-mail' }, p.email));
                    tr.appendChild(el('td', null, p.country || ''));
                    tr.appendChild(el('td', null, p.title));
                    tr.appendChild(el('td', { title: fmtDate(p.last_at) }, ago(p.last_at)));
                    tr.appendChild(el('td')).appendChild(stateTag(p));
                    tr.appendChild(el('td')).appendChild(statusSelect(p));
                    tr.appendChild(el('td')).appendChild(noteInput(p));
                    tr.appendChild(el('td')).appendChild(actions(p));
                    return tr;
                }
                function statusSelect(p) {
                    var s = el('select', { 'class': 'oec-mv-status', 'aria-label': 'Seguimiento' });
                    Object.keys(CFG.statuses).forEach(function (k) { s.appendChild(el('option', { value: k }, CFG.statuses[k])); });
                    s.value = p.status || '';
                    s.addEventListener('change', function () {
                        s.disabled = true;
                        post({ op: 'seguimiento', who: p.email, formation_id: p.fid, status: s.value || '_none' }).then(function (res) {
                            s.disabled = false;
                            if (res && res.success) p.status = s.value; else s.value = p.status || '';
                        });
                    });
                    return s;
                }
                function noteInput(p) {
                    var i = el('input', { type: 'text', 'class': 'oec-mv-note', maxlength: '500', placeholder: 'Agregar nota', value: p.note || '' });
                    i.addEventListener('change', function () {
                        post({ op: 'seguimiento', who: p.email, formation_id: p.fid, note: i.value.trim() || ' ' }).then(function (res) { if (res && res.success) p.note = i.value.trim(); });
                    });
                    return i;
                }
                function actions(p) {
                    var a = el('span', { 'class': 'oec-mv-acts' });
                    var ph = digits(p.phone);
                    if (ph.length >= 10) {
                        var w = a.appendChild(el('a', { 'class': 'oec-mv-act oec-mv-act--wa', target: 'oec_wa', rel: 'noopener', title: 'WhatsApp a +' + ph, href: '#' }));
                        w.appendChild(icon('whatsapp'));
                        w.addEventListener('click', function () { w.href = 'https://wa.me/' + ph + '?text=' + encodeURIComponent(waText(p)); post({ op: 'seguimiento', who: p.email, formation_id: p.fid, contact: 'wa' }); });
                        var c = a.appendChild(el('a', { 'class': 'oec-mv-act oec-mv-act--call', href: 'tel:+' + ph, title: 'Llamar a +' + ph }));
                        c.appendChild(icon('phone'));
                        c.addEventListener('click', function () { post({ op: 'seguimiento', who: p.email, formation_id: p.fid, contact: 'call' }); });
                    }
                    var m = a.appendChild(el('a', { 'class': 'oec-mv-act', href: 'mailto:' + p.email + '?subject=' + encodeURIComponent(p.title), title: 'Email a ' + p.email }));
                    m.appendChild(icon('email-alt'));
                    m.addEventListener('click', function () { post({ op: 'seguimiento', who: p.email, formation_id: p.fid, contact: 'email' }); });
                    return a;
                }

                /* ── Email a los elegidos (desde su Gmail, OEC_Mailer) ── */
                var draft = {};
                function fill(tpl, p) {
                    var n = String(p.name || '').trim().split(/\s+/);
                    var vars = { nombre: firstName(p), apellido: n.slice(1).join(' '), formacion: p.title || '', link: CFG.links[p.fid] || '', precio: '(precio para ' + (p.country || 'su país') + ')', vencimiento: '(cierre de inscripción)' };
                    return String(tpl || '').replace(/\{(nombre|apellido|formacion|link|precio|vencimiento)\}/g, function (m, k) { return vars[k]; });
                }
                function drawCompose(box) {
                    var st = view.stage, list = Object.keys(picked).map(function (k) { return picked[k]; });
                    if (!draft[st]) draft[st] = { subject: (CFG.mail[st] || {}).subject || '', body: (CFG.mail[st] || {}).body || '' };
                    var d = draft[st];
                    box.replaceChildren();
                    if (!CFG.gmail) {
                        var w = box.appendChild(el('div', { 'class': 'oec-mv-err' }, 'Para enviar emails desde tu Gmail, conecta tu cuenta de Google en Configuración. '));
                        w.appendChild(el('a', { href: CFG.config }, 'Conectar con Google'));
                        w.appendChild(document.createTextNode(' — Mientras tanto, el botón de email de cada persona abre el mensaje en tu programa de correo.'));
                        return;
                    }
                    box.appendChild(el('div', { 'class': 'oec-mv-dim' }, 'Sale desde ' + CFG.gmail + ' a ' + plural(list.length, 'persona', 'personas') + '. Las respuestas te llegan a tu Gmail. Hoy van ' + CFG.today + ' de ' + CFG.limit + ' por día; lo que pase del tope sale mañana.'));
                    var l1 = box.appendChild(el('div')); l1.appendChild(el('label', { 'for': 'oec-mv-subj' }, 'Asunto'));
                    var subj = l1.appendChild(el('input', { type: 'text', id: 'oec-mv-subj', maxlength: '200' })); subj.value = d.subject;
                    var l2 = box.appendChild(el('div')); l2.appendChild(el('label', { 'for': 'oec-mv-body' }, 'Mensaje'));
                    var body = l2.appendChild(el('textarea', { id: 'oec-mv-body', rows: '9' })); body.value = d.body;
                    var vars = box.appendChild(el('div', { 'class': 'oec-mv-vars' }, 'Insertar: '));
                    var lastField = body;
                    subj.addEventListener('focus', function () { lastField = subj; }); body.addEventListener('focus', function () { lastField = body; });
                    CFG.links_vars.forEach(function (v) {
                        var b = vars.appendChild(el('button', { type: 'button' }, '{' + v + '}'));
                        b.addEventListener('click', function () {
                            var f = lastField, a = f.selectionStart || f.value.length;
                            f.value = f.value.slice(0, a) + '{' + v + '}' + f.value.slice(f.selectionEnd || a); f.focus(); f.dispatchEvent(new Event('input'));
                        });
                    });
                    vars.appendChild(el('span', null, ' · {precio} va en la moneda del país de cada persona; {vencimiento} es el día en que cierra la inscripción.'));
                    var prev = box.appendChild(el('div', { 'class': 'oec-mv-prev' }));
                    function redraw() {
                        d.subject = subj.value; d.body = body.value;
                        var p = list[0]; if (!p) return;
                        prev.replaceChildren(el('strong', null, 'Vista previa para ' + (p.name || p.email) + ': ' + fill(d.subject, p)));
                        prev.appendChild(document.createTextNode(fill(d.body, p)));
                    }
                    subj.addEventListener('input', redraw); body.addEventListener('input', redraw); redraw();
                    var btns = box.appendChild(el('div', { 'class': 'oec-mv-row-btns' }));
                    var save = btns.appendChild(el('button', { type: 'button', 'class': 'button' }, 'Guardar como texto de «' + STAGES[st] + '»'));
                    var test = btns.appendChild(el('button', { type: 'button', 'class': 'button' }, 'Enviarme una prueba'));
                    var send = btns.appendChild(el('button', { type: 'button', 'class': 'button button-primary' }, 'Enviar desde tu Gmail (' + list.length + ')'));
                    var out = btns.appendChild(el('span', { 'class': 'oec-mv-progress' }));
                    var payload = function (op) { return { op: op, stage: st, subject: d.subject, body: d.body, people: JSON.stringify(list.map(function (p) { return { who: p.email, fid: p.fid }; })) }; };
                    save.addEventListener('click', function () {
                        post({ op: 'mail_tpl', stage: st, subject: d.subject, body: d.body }).then(function (res) { if (res && res.success) { CFG.mail = res.data.mail; out.textContent = 'Guardado.'; } });
                    });
                    test.addEventListener('click', function () {
                        test.disabled = true; out.textContent = 'Enviando la prueba…';
                        post(payload('mail_test')).then(function (res) { test.disabled = false; out.textContent = (res && res.data && res.data.message) || 'No se pudo enviar la prueba.'; });
                    });
                    send.addEventListener('click', function () {
                        if (!confirm('¿Enviar este email a ' + plural(list.length, 'persona', 'personas') + ' desde ' + CFG.gmail + '?')) return;
                        send.disabled = true; test.disabled = true; out.textContent = 'Preparando el envío…';
                        post(payload('mail_send')).then(function (res) {
                            if (!res || !res.success) { send.disabled = false; test.disabled = false; out.textContent = (res && res.data && res.data.message) || 'No se pudo enviar.'; return; }
                            track(res.data.campaigns, res.data.progress, out);
                        });
                    });
                }
                function track(cids, pr, out) {
                    var show = function (p) {
                        var t = 'Enviados: ' + p.sent + (p.pending ? ' · en cola: ' + p.pending : '') + (p.failed ? ' · con error: ' + p.failed : '') + (p.skipped ? ' · de baja: ' + p.skipped : '');
                        if (p.paused) t += '. Llegaste al tope de hoy: el resto sale mañana solo.';
                        if (p.error) t += '. ' + p.error;
                        if (!p.pending) t += '. ¡Listo!';
                        out.textContent = t;
                    };
                    show(pr);
                    if (!pr.pending) return;
                    setTimeout(function () {
                        post({ op: 'mail_progress', campaigns: JSON.stringify(cids) }).then(function (res) {
                            if (!res || !res.success) { out.textContent += ' (el envío sigue solo aunque cierres esta pantalla)'; return; }
                            if (res.data.paused || res.data.error) { show(res.data); out.textContent += ' El envío sigue solo.'; return; }
                            track(cids, res.data, out);
                        });
                    }, 2500);
                }

                /* ── Persona ── */
                var TYPES = { form: 'Consultó en el formulario', checkout: 'Empezó la inscripción', paid: 'Se inscribió (pagó)', wa: 'Le escribiste por WhatsApp', call: 'La llamaste', email: 'Le escribiste un email', track: 'Seguimiento', unsub: 'Baja' };
                function openPerson(email) {
                    main.replaceChildren(el('div', { 'class': 'oec-mv-msg' }, 'Cargando…'));
                    post({ op: 'persona', who: email }).then(function (res) {
                        if (!res || !res.success) return msg(failMsg(res), 'oec-mv-err');
                        drawPerson(res.data);
                    });
                }
                function drawPerson(d) {
                    main.replaceChildren();
                    var back = main.appendChild(el('p')).appendChild(el('a', { href: '#' }, '← Volver al embudo'));
                    back.addEventListener('click', function (e) { e.preventDefault(); loadFunnel(); });
                    var j0 = d.journeys[0] || { email: d.who, name: '' };
                    var box = main.appendChild(el('div', { 'class': 'oec-mv-box' }));
                    var head = box.appendChild(el('div', { 'class': 'oec-mv-person' }));
                    var h = head.appendChild(el('div'));
                    h.appendChild(el('h2', null, j0.name || d.who));
                    h.appendChild(el('div', { 'class': 'oec-mv-mail' }, [d.who, j0.phone, j0.country].filter(Boolean).join(' · ')));
                    var un = head.appendChild(el('button', { type: 'button', 'class': 'button' }, d.baja ? 'Volver a darla de alta' : 'Dar de baja (no recibe más emails)'));
                    un.addEventListener('click', function () {
                        if (!d.baja && !confirm('No va a recibir más emails tuyos desde el plugin. ¿Seguro?')) return;
                        post({ op: 'seguimiento', who: d.who, unsub: d.baja ? '0' : '1' }).then(function () { openPerson(d.who); });
                    });
                    if (d.journeys.length) {
                        var t = box.appendChild(el('table', { 'class': 'oec-mv-table' }));
                        var hr = t.appendChild(el('thead')).appendChild(el('tr'));
                        ['Formación', 'Etapa', 'Estado', 'Seguimiento', 'Nota', ''].forEach(function (x) { hr.appendChild(el('th', null, x)); });
                        var tb = t.appendChild(el('tbody'));
                        d.journeys.forEach(function (p) {
                            var tr = tb.appendChild(el('tr'));
                            tr.appendChild(el('td', null, p.title));
                            tr.appendChild(el('td', null, STAGES[p.stage]));
                            tr.appendChild(el('td')).appendChild(stateTag(p));
                            tr.appendChild(el('td')).appendChild(statusSelect(p));
                            tr.appendChild(el('td')).appendChild(noteInput(p));
                            tr.appendChild(el('td')).appendChild(actions(p));
                        });
                    }
                    var hb = main.appendChild(el('div', { 'class': 'oec-mv-box' }));
                    hb.appendChild(el('h2', null, 'Historia'));
                    var ul = hb.appendChild(el('ul', { 'class': 'oec-mv-hist' }));
                    if (!d.history.length) ul.appendChild(el('li', null, 'Sin movimientos.'));
                    d.history.forEach(function (it) {
                        var li = ul.appendChild(el('li'));
                        li.appendChild(el('span', { 'class': 'when' }, fmtDate(it.at)));
                        var what = TYPES[it.type] || it.type;
                        if (it.type === 'track') what = 'Seguimiento: ' + [it.status !== undefined ? (CFG.statuses[it.status] || it.status) : '', it.note ? '«' + it.note + '»' : ''].filter(Boolean).join(' · ');
                        if (it.type === 'unsub') what = it.unsub ? 'Se dio de baja de tus emails' : 'Volvió a recibir tus emails';
                        if (it.type === 'email' && it.subject) what += ': «' + it.subject + '»';
                        var w = li.appendChild(el('span', { 'class': 'what' }, what + (it.title ? ' — ' + it.title : '') + (it.by ? ' (' + it.by + ')' : '')));
                        if (it.question) w.appendChild(el('div', { 'class': 'oec-mv-mail' }, it.question));
                    });
                }

                selForm.addEventListener('change', loadFunnel);
                selDays.addEventListener('change', loadFunnel);
                if (CFG.migrate) {
                    msg('Pasando tu seguimiento (estados, notas y bajas) a Mi viaje…');
                    post({ op: 'migrar' }).then(loadFunnel, loadFunnel);
                } else loadFunnel();
            })();
            </script>
            <?php
        }
    }
}
