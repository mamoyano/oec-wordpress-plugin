<?php
/**
 * Emails a los contactos de Estadísticas.
 *
 * Dos formas de enviar (ventana de contactos de Estadísticas):
 * - "Conectar con Google" (OEC → Configuración): el socio autoriza a la app
 *   "Online Education Center" a enviar desde su Gmail (permiso gmail.send) y los
 *   emails salen automáticamente, desde su casilla, por la API de Gmail. No usa
 *   contraseñas ni SMTP: Google no acepta contraseñas de aplicación desde
 *   servidores (v1.5.0–v1.5.3, "535 5.7.8" en SiteGround y Cloudways).
 *   El secreto de la app vive SOLO en onlineeducation.center/connections/google/
 *   (server/google/callback.php): hace los pasos de OAuth que lo necesitan y
 *   devuelve al sitio un refresh token, que se guarda cifrado acá. Cada envío pide
 *   un token de acceso de 1 hora a ese intermediario.
 * - Sin Google conectado (o si el socio prefiere): se abre cada email ya escrito
 *   en Gmail/Outlook/su programa de correo, uno por uno (todo en el navegador).
 *
 * El envío automático es una cola por campaña (oec_mail_campaigns + una fila por
 * persona en oec_mail_queue, que además es el registro), despachada por WP-Cron y,
 * mientras la ventana está abierta, por el navegador. Bajas: link firmado al pie +
 * List-Unsubscribe (oec_mail_unsubs).
 */
if (!class_exists('OEC_Mailer')) {
    class OEC_Mailer {
        const DB_VERSION    = '2';
        const BATCH_CRON    = 15;   // mails por pasada de cron
        const BATCH_AJAX    = 3;    // mails por pedido del navegador
        const DAILY_DEFAULT = 400;  // Gmail gratis: ~500 destinatarios por día
        const VARS          = ['nombre', 'apellido', 'email', 'telefono', 'pais', 'formacion', 'link'];
        const BROKER        = 'https://onlineeducation.center/connections/google/callback.php';

        public static function init() {
            add_action('init', [__CLASS__, 'maybe_install']);
            add_action('oec_mail_tick', [__CLASS__, 'cron_tick']);
            add_action('admin_post_oec_unsub', [__CLASS__, 'unsubscribe']);
            add_action('admin_post_nopriv_oec_unsub', [__CLASS__, 'unsubscribe']);

            add_action('admin_post_oec_google_connect', [__CLASS__, 'google_connect']);
            add_action('admin_post_oec_google_return', [__CLASS__, 'google_return']);
            add_action('admin_post_oec_google_disconnect', [__CLASS__, 'google_disconnect']);
            add_action('admin_post_oec_google_settings', [__CLASS__, 'google_settings']);

            add_action('wp_ajax_oec_mail_test', [__CLASS__, 'ajax_test']);
            add_action('wp_ajax_oec_mail_send', [__CLASS__, 'ajax_send']);
            add_action('wp_ajax_oec_mail_preview_test', [__CLASS__, 'ajax_preview_test']);
            add_action('wp_ajax_oec_mail_progress', [__CLASS__, 'ajax_progress']);
            add_action('wp_ajax_oec_mail_template_save', [__CLASS__, 'ajax_template_save']);
            add_action('wp_ajax_oec_mail_template_delete', [__CLASS__, 'ajax_template_delete']);
        }

        // === TABLAS =========================================================

        private static function t($name) {
            global $wpdb;
            return $wpdb->prefix . 'oec_mail_' . $name;
        }

        public static function maybe_install() {
            $installed = get_option('oec_mail_db_version');
            if ($installed === self::DB_VERSION) return;
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $c = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE " . self::t('campaigns') . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                training_uid varchar(20) NOT NULL,
                training_title varchar(255) NOT NULL DEFAULT '',
                training_link varchar(500) NOT NULL DEFAULT '',
                stage varchar(40) NOT NULL,
                subject varchar(255) NOT NULL,
                body longtext NOT NULL,
                reply_to varchar(190) NOT NULL DEFAULT '',
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY training_uid (training_uid)
            ) $c;");
            dbDelta("CREATE TABLE " . self::t('queue') . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                campaign_id bigint(20) unsigned NOT NULL,
                training_uid varchar(20) NOT NULL,
                email varchar(190) NOT NULL,
                first_name varchar(100) NOT NULL DEFAULT '',
                last_name varchar(100) NOT NULL DEFAULT '',
                phone varchar(50) NOT NULL DEFAULT '',
                country varchar(10) NOT NULL DEFAULT '',
                status varchar(10) NOT NULL DEFAULT 'queued',
                error varchar(255) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                sent_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY campaign_status (campaign_id,status),
                KEY status (status),
                KEY training_email (training_uid,email)
            ) $c;");
            dbDelta("CREATE TABLE " . self::t('unsubs') . " (
                email varchar(190) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (email)
            ) $c;");
            if ($installed === '1') {
                // De v1.5.0–v1.5.3 (Gmail con contraseña de aplicación, que no funcionaba
                // desde servidores): lo encolado no sale y la contraseña se borra.
                $wpdb->query('UPDATE ' . self::t('queue') . " SET status = 'skipped', error = 'Envío anterior cancelado' WHERE status IN ('queued', 'sending')");
                wp_clear_scheduled_hook('oec_mail_tick');
                foreach (['oec_gmail_user', 'oec_gmail_pass', 'oec_gmail_pass_at', 'oec_gmail_port', 'oec_gmail_name', 'oec_mail_last_error'] as $o) delete_option($o);
            }
            update_option('oec_mail_db_version', self::DB_VERSION);
        }

        // === CIFRADO (refresh token) ========================================

        private static function crypt_key() {
            return hash('sha256', wp_salt('auth') . '|oec-google', true);
        }

        private static function encrypt($plain) {
            if (!function_exists('openssl_encrypt')) return '';
            $iv  = random_bytes(12);
            $tag = '';
            $enc = openssl_encrypt($plain, 'aes-256-gcm', self::crypt_key(), OPENSSL_RAW_DATA, $iv, $tag);
            return $enc === false ? '' : base64_encode($iv . $tag . $enc);
        }

        private static function decrypt($stored) {
            $raw = base64_decode((string) $stored, true);
            if (!$raw || strlen($raw) < 29 || !function_exists('openssl_decrypt')) return '';
            $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::crypt_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return $out === false ? '' : $out;
        }

        // === CONECTAR CON GOOGLE ============================================

        private static function broker() {
            return apply_filters('oec_google_broker', defined('OEC_GOOGLE_BROKER') ? OEC_GOOGLE_BROKER : self::BROKER);
        }

        /** Email de la cuenta de Google conectada, o ''. */
        public static function google_email() {
            return get_option('oec_google_refresh', '') !== '' ? (string) get_option('oec_google_email', '') : '';
        }

        /** Hay que volver a conectar (Google revocó el permiso o venció). */
        public static function google_broken() {
            return (bool) get_option('oec_google_broken', false);
        }

        public static function from_name() {
            return get_option('oec_mail_from_name', '') ?: get_bloginfo('name');
        }

        public static function daily_limit() {
            return max(1, intval(get_option('oec_mail_daily_limit', self::DAILY_DEFAULT)));
        }

        private static function config_url($args = []) {
            return add_query_arg($args, admin_url('admin.php?page=oec-main')) . '#oec-gmail';
        }

        public static function google_connect() {
            if (!current_user_can('manage_options')) wp_die('Sin permiso', 403);
            check_admin_referer('oec_google_connect');
            $state = wp_generate_password(32, false);
            set_transient('oec_google_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS);
            wp_redirect(add_query_arg(['action' => 'start', 'return' => rawurlencode(admin_url('admin-post.php')), 'state' => $state], self::broker()));
            exit;
        }

        /** Vuelta del intermediario: canjea el código de un solo uso por el refresh token. */
        public static function google_return() {
            if (!current_user_can('manage_options')) wp_die('Sin permiso', 403);
            $key   = 'oec_google_state_' . get_current_user_id();
            $state = get_transient($key);
            delete_transient($key);
            if (!$state || !hash_equals($state, (string) ($_GET['state'] ?? ''))) {
                wp_safe_redirect(self::config_url(['oec_google' => 'expired'])); exit;
            }
            $result = sanitize_key($_GET['oec_google'] ?? '');
            if ($result !== 'ok') { wp_safe_redirect(self::config_url(['oec_google' => $result ?: 'error'])); exit; }

            $res  = wp_remote_post(self::broker(), ['timeout' => 20, 'body' => ['action' => 'exchange', 'code' => sanitize_text_field(wp_unslash($_GET['code'] ?? ''))]]);
            $body = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
            if (empty($body['refresh_token'])) { wp_safe_redirect(self::config_url(['oec_google' => 'error'])); exit; }

            update_option('oec_google_refresh', self::encrypt($body['refresh_token']), false);
            update_option('oec_google_email', sanitize_email($body['email'] ?? ''), false);
            delete_option('oec_google_broken');
            delete_option('oec_mail_last_error');
            delete_transient('oec_google_access');
            self::schedule(time() + 30); // si había envíos en pausa esperando la reconexión
            wp_safe_redirect(self::config_url(['oec_google' => 'connected'])); exit;
        }

        public static function google_disconnect() {
            if (!current_user_can('manage_options')) wp_die('Sin permiso', 403);
            check_admin_referer('oec_google_disconnect');
            $rt = self::decrypt(get_option('oec_google_refresh', ''));
            // Revocar en Google no necesita el secreto de la app.
            if ($rt !== '') wp_remote_post('https://oauth2.googleapis.com/revoke', ['timeout' => 10, 'body' => ['token' => $rt]]);
            foreach (['oec_google_refresh', 'oec_google_email', 'oec_google_broken', 'oec_mail_last_error'] as $o) delete_option($o);
            delete_transient('oec_google_access');
            wp_safe_redirect(self::config_url(['oec_google' => 'disconnected'])); exit;
        }

        public static function google_settings() {
            if (!current_user_can('manage_options')) wp_die('Sin permiso', 403);
            check_admin_referer('oec_google_settings');
            update_option('oec_mail_from_name', sanitize_text_field(wp_unslash($_POST['oec_mail_from_name'] ?? '')), false);
            update_option('oec_mail_daily_limit', max(1, min(2000, intval($_POST['oec_mail_daily_limit'] ?? self::DAILY_DEFAULT))), false);
            wp_safe_redirect(self::config_url(['oec_google' => 'saved'])); exit;
        }

        /** Token de acceso de 1 h (cacheado), o WP_Error. */
        private static function access_token($force = false) {
            if (!$force) {
                $cached = self::decrypt(get_transient('oec_google_access') ?: '');
                if ($cached !== '') return $cached;
            }
            $rt = self::decrypt(get_option('oec_google_refresh', ''));
            if ($rt === '') return new WP_Error('oec_google', 'Conecta tu cuenta de Google en OEC → Configuración.');
            $res  = wp_remote_post(self::broker(), ['timeout' => 20, 'body' => ['action' => 'refresh', 'refresh_token' => $rt]]);
            $body = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
            if (!empty($body['access_token'])) {
                set_transient('oec_google_access', self::encrypt($body['access_token']), max(60, intval($body['expires_in'] ?? 3600) - 120));
                return $body['access_token'];
            }
            if (($body['error'] ?? '') === 'invalid_grant') {
                update_option('oec_google_broken', 1, false);
                return new WP_Error('oec_google', 'Google ya no permite enviar desde tu cuenta (se quitó el permiso o venció). Vuelve a tocar "Conectar con Google" en OEC → Configuración.');
            }
            return new WP_Error('oec_google', 'No se pudo hablar con Google en este momento. Se reintenta más tarde.');
        }

        /**
         * Envía un email desde la cuenta de Google conectada (API de Gmail). Devuelve ''
         * si salió, o el error. $permanent queda en true si el problema es de esa
         * dirección (no tiene sentido reintentar).
         */
        public static function send($to, $subject, $html, $text, array $headers = [], &$permanent = false) {
            $permanent = false;
            $from = self::google_email();
            if ($from === '') return 'Conecta tu cuenta de Google en OEC → Configuración.';
            if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
                require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
            }
            // PHPMailer solo arma el mensaje (MIME); lo envía la API de Gmail.
            try {
                $m = new PHPMailer\PHPMailer\PHPMailer(true);
                $m->CharSet = 'UTF-8';
                $m->setFrom($from, self::from_name(), false);
                $m->addAddress($to);
                $m->Subject = $subject;
                $m->isHTML(true);
                $m->Body    = $html;
                $m->AltBody = $text;
                $m->preSend();
                // Las cabeceras propias (List-Unsubscribe…) van tal cual: PHPMailer codifica
                // las largas (=?us-ascii?Q?…?=) y así Gmail no reconoce el link de baja.
                $extra = '';
                foreach ($headers as $h) $extra .= str_replace(["\r", "\n"], '', $h) . "\r\n";
                $raw = rtrim(strtr(base64_encode($extra . $m->getSentMIMEMessage()), '+/', '-_'), '=');
            } catch (Exception $e) {
                $permanent = true;
                return 'Dirección de email inválida';
            }
            for ($try = 0; $try < 2; $try++) {
                $token = self::access_token($try > 0);
                if (is_wp_error($token)) return $token->get_error_message();
                $res = wp_remote_post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                    'timeout' => 30,
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                    'body'    => wp_json_encode(['raw' => $raw]),
                ]);
                if (is_wp_error($res)) return 'No se pudo hablar con Gmail en este momento. Se reintenta más tarde.';
                $code = (int) wp_remote_retrieve_response_code($res);
                if ($code === 200) return '';
                if ($code === 401) { delete_transient('oec_google_access'); continue; }
                $msg = (string) (json_decode(wp_remote_retrieve_body($res), true)['error']['message'] ?? '');
                if ($code === 400 && (stripos($msg, 'recipient') !== false || stripos($msg, 'invalid to') !== false)) { $permanent = true; return 'Dirección de email inválida'; }
                if ($code === 403 && stripos($msg, 'insufficient') !== false) {
                    update_option('oec_google_broken', 1, false);
                    return 'Falta el permiso para enviar. Vuelve a tocar "Conectar con Google" en OEC → Configuración y deja marcada la opción de enviar emails.';
                }
                if ($code === 429 || stripos($msg, 'limit') !== false) return 'Gmail alcanzó su límite de envíos por hoy. Se sigue mañana.';
                return 'Gmail no aceptó el envío' . ($msg !== '' ? ': ' . $msg : '') . '.';
            }
            return 'Gmail no aceptó el envío.';
        }

        public static function ajax_test() {
            self::require_ajax_admin();
            $to  = wp_get_current_user()->user_email;
            $err = self::send($to, 'Prueba de envío — ' . get_bloginfo('name'),
                '<p>¡Funciona! Este email salió desde tu cuenta de Google (' . esc_html(self::google_email()) . ') con el plugin de Online Education Center.</p>',
                '¡Funciona! Este email salió desde tu cuenta de Google (' . self::google_email() . ') con el plugin de Online Education Center.');
            if ($err) wp_send_json_error(['message' => $err]);
            delete_option('oec_mail_last_error');
            wp_send_json_success(['message' => 'Listo: te enviamos un email de prueba a ' . $to . '. También lo vas a ver en "Enviados" de tu Gmail.']);
        }

        // === MENSAJE ========================================================

        private static function country_name($code) {
            $code = strtoupper((string) $code);
            if (strlen($code) === 2 && class_exists('Locale')) {
                $name = Locale::getDisplayRegion('-' . $code, 'es');
                if ($name && $name !== $code) return $name;
            }
            return $code;
        }

        /** Valores de las variables para una persona. */
        public static function vars_for(array $c, array $campaign) {
            $first = $c['first_name'] !== '' ? $c['first_name'] : trim(strtok((string) ($c['name'] ?? ''), ' ') ?: '');
            return [
                'nombre'    => self::nice_case($first),
                'apellido'  => self::nice_case($c['last_name']),
                'email'     => $c['email'],
                'telefono'  => $c['phone'],
                'pais'      => self::country_name($c['country']),
                'formacion' => $campaign['training_title'],
                'link'      => $campaign['training_link'],
            ];
        }

        /** "GONZALO" / "gonzalo" → "Gonzalo"; deja intacto lo que ya viene con mayúsculas y minúsculas. */
        private static function nice_case($s) {
            $s = trim((string) $s);
            if ($s === '' || (mb_strtoupper($s) !== $s && mb_strtolower($s) !== $s)) return $s;
            return mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8');
        }

        private static function replace_vars($tpl, array $vars, $escape) {
            return preg_replace_callback('/\{(' . implode('|', self::VARS) . ')\}/u', function ($m) use ($vars, $escape) {
                $v = (string) ($vars[$m[1]] ?? '');
                return $escape ? esc_html($v) : $v;
            }, $tpl);
        }

        public static function unsub_url($email) {
            $e = strtolower($email);
            return add_query_arg([
                'action' => 'oec_unsub',
                'e'      => rtrim(strtr(base64_encode($e), '+/', '-_'), '='),
                'k'      => substr(hash_hmac('sha256', $e, wp_salt('nonce')), 0, 20),
            ], admin_url('admin-post.php'));
        }

        /** [asunto, html, texto] de un mail de la campaña para una persona. */
        public static function render(array $campaign, array $c) {
            $vars    = self::vars_for($c, $campaign);
            $subject = wp_strip_all_tags(self::replace_vars($campaign['subject'], $vars, false));
            $text    = self::replace_vars($campaign['body'], $vars, false);
            $body    = make_clickable(wpautop(self::replace_vars(esc_html($campaign['body']), $vars, true)));
            $unsub   = self::unsub_url($c['email']);
            $from    = self::from_name();
            $html = '<!doctype html><html><body style="margin:0;padding:0;">'
                  . '<div style="max-width:600px;margin:0 auto;padding:24px 20px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#1f2937;">'
                  . $body
                  . '<p style="margin-top:32px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">'
                  . 'Recibes este email porque te interesaste en ' . esc_html($campaign['training_title']) . '. '
                  . '<a href="' . esc_url($unsub) . '" style="color:#6b7280;">No quiero recibir más emails de ' . esc_html($from) . '</a>.</p>'
                  . '</div></body></html>';
            $text .= "\n\n--\nNo quiero recibir más emails de " . $from . ': ' . $unsub;
            return [$subject, $html, $text];
        }

        private static function list_headers($email) {
            return ['List-Unsubscribe: <' . self::unsub_url($email) . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'];
        }

        // === BAJAS ==========================================================

        public static function unsubscribe() {
            $e = strtolower((string) base64_decode(strtr(sanitize_text_field(wp_unslash($_REQUEST['e'] ?? '')), '-_', '+/')));
            $k = sanitize_text_field(wp_unslash($_REQUEST['k'] ?? ''));
            $ok = is_email($e) && hash_equals(substr(hash_hmac('sha256', $e, wp_salt('nonce')), 0, 20), $k);
            nocache_headers();
            if ($ok) {
                global $wpdb;
                $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::t('unsubs') . ' (email, created_at) VALUES (%s, %s)', $e, current_time('mysql', true)));
                $wpdb->query($wpdb->prepare('UPDATE ' . self::t('queue') . " SET status = 'skipped', error = 'Se dio de baja' WHERE email = %s AND status = 'queued'", $e));
            }
            // Baja "de un clic" de Gmail/Apple (List-Unsubscribe-Post): solo el código.
            if ($_SERVER['REQUEST_METHOD'] === 'POST') { status_header($ok ? 200 : 400); exit; }
            $site = esc_html(self::from_name());
            wp_die($ok
                ? '<h1>Listo</h1><p>No vas a recibir más emails de ' . $site . '.</p>'
                : '<h1>Enlace inválido</h1><p>No pudimos procesar la baja. Responde el email que recibiste y te quitamos de la lista.</p>',
                $site, ['response' => $ok ? 200 : 400]);
        }

        /** De una lista de emails, los que se dieron de baja. */
        public static function unsubscribed(array $emails) {
            global $wpdb;
            $emails = array_values(array_unique(array_filter(array_map('strtolower', $emails))));
            if (!$emails) return [];
            $out = [];
            foreach (array_chunk($emails, 500) as $chunk) {
                $in  = implode(',', array_fill(0, count($chunk), '%s'));
                $out = array_merge($out, $wpdb->get_col($wpdb->prepare('SELECT email FROM ' . self::t('unsubs') . " WHERE email IN ($in)", $chunk)));
            }
            return $out;
        }

        // === CAMPAÑAS =======================================================

        private static function require_ajax_admin() {
            check_ajax_referer('oec_mail', 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error(null, 403);
        }

        /** Datos de la campaña que llegan del formulario de redacción, validados. */
        private static function campaign_from_post() {
            $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
            $body    = sanitize_textarea_field(wp_unslash($_POST['body'] ?? ''));
            if ($subject === '' || trim($body) === '') wp_send_json_error(['message' => 'Completa el asunto y el mensaje.']);
            $slug = sanitize_title(wp_unslash($_POST['slug'] ?? ''));
            return [
                'training_title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
                'training_link'  => $slug !== '' ? home_url('/formacion/' . $slug) : home_url('/'),
                'subject'        => $subject,
                'body'           => $body,
            ];
        }

        private static function clean_contact(array $c) {
            $email = strtolower(sanitize_email($c['email'] ?? ''));
            return [
                'email'      => is_email($email) ? $email : '',
                'first_name' => sanitize_text_field($c['first_name'] ?? ''),
                'last_name'  => sanitize_text_field($c['last_name'] ?? ''),
                'name'       => sanitize_text_field($c['name'] ?? ''),
                'phone'      => sanitize_text_field($c['phone'] ?? ''),
                'country'    => substr(sanitize_text_field($c['country'] ?? ''), 0, 10),
            ];
        }

        /** "Enviarme una prueba": la campaña con los datos de una persona, a mi email. */
        public static function ajax_preview_test() {
            self::require_ajax_admin();
            $campaign = self::campaign_from_post();
            $sample   = json_decode(wp_unslash($_POST['sample'] ?? ''), true);
            $c = self::clean_contact(is_array($sample) ? $sample : []);
            if (!$c['email']) $c['email'] = wp_get_current_user()->user_email;
            [$subject, $html, $text] = self::render($campaign, $c);
            $to  = wp_get_current_user()->user_email;
            $err = self::send($to, '[Prueba] ' . $subject, $html, $text);
            if ($err) wp_send_json_error(['message' => $err]);
            wp_send_json_success(['message' => 'Prueba enviada a ' . $to . '.']);
        }

        /**
         * Crea la campaña y encola a los elegidos. Los contactos se vuelven a pedir a
         * Zoho (OEC_Stats::contacts_for): del navegador solo llegan los emails elegidos,
         * así no se le puede escribir a nadie que no esté en esa etapa.
         */
        public static function ajax_send() {
            self::require_ajax_admin();
            if (self::google_email() === '' || self::google_broken()) wp_send_json_error(['message' => 'Conecta tu cuenta de Google en OEC → Configuración.']);
            $campaign = self::campaign_from_post();
            $uid      = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
            $edition  = sanitize_text_field(wp_unslash($_POST['edition'] ?? ''));
            $stage    = wp_unslash($_POST['stage'] ?? '');
            $chosen   = array_filter(array_map('strtolower', array_map('sanitize_email', (array) json_decode(wp_unslash($_POST['emails'] ?? '[]'), true))));
            if (!$chosen) wp_send_json_error(['message' => 'No elegiste a nadie.']);

            $contacts = OEC_Stats::contacts_for($uid, $edition, $stage);
            if ($contacts === null) wp_send_json_error(['message' => 'No se pudieron cargar los contactos de Zoho. Prueba de nuevo en unos minutos.']);

            $chosen = array_flip($chosen);
            $unsubs = array_flip(self::unsubscribed(array_keys($chosen)));
            $rows   = [];
            foreach ($contacts as $c) {
                $c = self::clean_contact($c);
                if (!$c['email'] || !isset($chosen[$c['email']]) || isset($unsubs[$c['email']]) || isset($rows[$c['email']])) continue;
                if ($c['first_name'] === '' && $c['name'] !== '') $c['first_name'] = trim(strtok($c['name'], ' '));
                $rows[$c['email']] = $c;
            }
            if (!$rows) wp_send_json_error(['message' => 'Ninguna de las personas elegidas puede recibir el email (sin email o dadas de baja).']);

            global $wpdb;
            $now = current_time('mysql', true);
            $wpdb->insert(self::t('campaigns'), [
                'training_uid'   => $uid,
                'training_title' => $campaign['training_title'],
                'training_link'  => $campaign['training_link'],
                'stage'          => $stage,
                'subject'        => $campaign['subject'],
                'body'           => $campaign['body'],
                'reply_to'       => self::google_email(),
                'created_by'     => get_current_user_id(),
                'created_at'     => $now,
            ]);
            $cid = (int) $wpdb->insert_id;
            if (!$cid) wp_send_json_error(['message' => 'No se pudo guardar el envío.']);
            foreach ($rows as $c) {
                $wpdb->insert(self::t('queue'), [
                    'campaign_id'  => $cid,
                    'training_uid' => $uid,
                    'email'        => $c['email'],
                    'first_name'   => mb_substr($c['first_name'], 0, 100),
                    'last_name'    => mb_substr($c['last_name'], 0, 100),
                    'phone'        => mb_substr($c['phone'], 0, 50),
                    'country'      => $c['country'],
                    'status'       => 'queued',
                    'created_at'   => $now,
                ]);
            }
            self::schedule(time() + 30);
            wp_send_json_success(['campaign' => $cid, 'progress' => self::progress($cid)]);
        }

        public static function ajax_progress() {
            self::require_ajax_admin();
            $cid  = intval($_POST['campaign'] ?? 0);
            $info = self::process(self::BATCH_AJAX, 20);
            wp_send_json_success(self::progress($cid) + ['paused' => $info['paused'], 'error' => $info['error'], 'limit' => self::daily_limit()]);
        }

        private static function progress($cid) {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) n FROM ' . self::t('queue') . ' WHERE campaign_id = %d GROUP BY status', $cid), OBJECT_K);
            $n = function ($s) use ($rows) { return isset($rows[$s]) ? (int) $rows[$s]->n : 0; };
            $errors = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT error FROM ' . self::t('queue') . " WHERE campaign_id = %d AND status = 'failed' LIMIT 3", $cid));
            return [
                'sent'    => $n('sent'),
                'pending' => $n('queued') + $n('sending'),
                'failed'  => $n('failed'),
                'skipped' => $n('skipped'),
                'errors'  => $errors,
            ];
        }

        // === COLA ===========================================================

        private static function schedule($when) {
            $next = wp_next_scheduled('oec_mail_tick');
            if ($next && $next <= $when) return;
            if ($next) wp_unschedule_event($next, 'oec_mail_tick');
            wp_schedule_single_event($when, 'oec_mail_tick');
        }

        /** Inicio del día de hoy (hora del sitio) en UTC, para el tope diario. */
        private static function today_start_utc() {
            $d = new DateTimeImmutable('today', wp_timezone());
            return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        public static function sent_today() {
            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::t('queue') . " WHERE status = 'sent' AND sent_at >= %s", self::today_start_utc()));
        }

        public static function cron_tick() {
            $info = self::process(self::BATCH_CRON, 40);
            if ($info['remaining'] <= 0) return;
            if ($info['error'] !== '') {
                // Sin permiso de Google: espera la reconexión (google_return la reprograma).
                if (self::google_broken()) return;
                self::schedule(time() + HOUR_IN_SECONDS);
            } elseif ($info['paused']) {
                self::schedule((new DateTimeImmutable('tomorrow', wp_timezone()))->getTimestamp() + 300);
            } else {
                self::schedule(time() + 60);
            }
        }

        /**
         * Despacha hasta $max mails de la cola (o $seconds segundos). Cada fila se
         * "toma" con un UPDATE condicional: dos procesos a la vez nunca mandan el
         * mismo mail.
         */
        public static function process($max, $seconds) {
            global $wpdb;
            $q = self::t('queue');
            // "Enviando" hace más de 10 min: se cortó a mitad. No se reintenta (podría haber salido).
            $wpdb->query($wpdb->prepare("UPDATE $q SET status = 'failed', error = 'Se interrumpió el envío' WHERE status = 'sending' AND sent_at < %s", gmdate('Y-m-d H:i:s', time() - 600)));

            $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status = 'queued'");
            if ($remaining > 0 && (self::google_email() === '' || self::google_broken())) {
                return ['remaining' => $remaining, 'paused' => false, 'error' => 'Conecta tu cuenta de Google en OEC → Configuración para seguir enviando.'];
            }
            $start     = microtime(true);
            $budget    = self::daily_limit() - self::sent_today();
            $done      = 0;
            $campaigns = [];

            while ($remaining > 0 && $done < $max && $budget > 0 && microtime(true) - $start < $seconds) {
                $row = $wpdb->get_row("SELECT * FROM $q WHERE status = 'queued' ORDER BY id LIMIT 1", ARRAY_A);
                if (!$row) break;
                $claimed = $wpdb->query($wpdb->prepare("UPDATE $q SET status = 'sending', sent_at = %s WHERE id = %d AND status = 'queued'", current_time('mysql', true), $row['id']));
                if (!$claimed) continue;
                $remaining--;

                if (self::unsubscribed([$row['email']])) {
                    $wpdb->update($q, ['status' => 'skipped', 'error' => 'Se dio de baja'], ['id' => $row['id']]);
                    continue;
                }
                $cid = (int) $row['campaign_id'];
                if (!isset($campaigns[$cid])) {
                    $campaigns[$cid] = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::t('campaigns') . ' WHERE id = %d', $cid), ARRAY_A);
                }
                $campaign = $campaigns[$cid];
                if (!$campaign) {
                    $wpdb->update($q, ['status' => 'failed', 'error' => 'Envío borrado'], ['id' => $row['id']]);
                    continue;
                }
                [$subject, $html, $text] = self::render($campaign, $row);
                $permanent = false;
                $err = self::send($row['email'], $subject, $html, $text, self::list_headers($row['email']), $permanent);
                if ($err === '') {
                    $wpdb->update($q, ['status' => 'sent', 'sent_at' => current_time('mysql', true), 'error' => ''], ['id' => $row['id']]);
                    delete_option('oec_mail_last_error');
                    $budget--;
                } elseif ($permanent) {
                    $wpdb->update($q, ['status' => 'failed', 'error' => mb_substr($err, 0, 250)], ['id' => $row['id']]);
                } else {
                    // Problema de la cuenta o de Google (permiso, límite, conexión): afecta a
                    // todos. La fila vuelve a la cola y se corta la pasada.
                    $wpdb->update($q, ['status' => 'queued', 'sent_at' => null, 'error' => mb_substr($err, 0, 250)], ['id' => $row['id']]);
                    update_option('oec_mail_last_error', ['message' => $err, 'at' => time()], false);
                    $limit = strpos($err, 'límite') !== false;
                    return ['remaining' => $remaining + 1, 'paused' => $limit, 'error' => $limit ? '' : $err];
                }
                $done++;
            }
            return ['remaining' => $remaining, 'paused' => $remaining > 0 && $budget <= 0, 'error' => ''];
        }

        /** Emails automáticos a cada persona por esta formación: [email => ['sent' => fecha UTC|null, 'count' => n, 'queued' => bool]]. */
        public static function history($uid, array $emails) {
            global $wpdb;
            $emails = array_values(array_unique(array_filter(array_map('strtolower', $emails))));
            $out = [];
            foreach (array_chunk($emails, 500) as $chunk) {
                $in   = implode(',', array_fill(0, count($chunk), '%s'));
                $rows = $wpdb->get_results($wpdb->prepare(
                    'SELECT email, MAX(CASE WHEN status = \'sent\' THEN sent_at END) last_sent, SUM(status IN (\'queued\', \'sending\')) pending, SUM(status = \'sent\') n_sent FROM ' . self::t('queue') . " WHERE training_uid = %s AND email IN ($in) GROUP BY email",
                    array_merge([$uid], $chunk)
                ), ARRAY_A);
                foreach ($rows as $r) {
                    $out[$r['email']] = ['sent' => $r['last_sent'] ? str_replace(' ', 'T', $r['last_sent']) . 'Z' : null, 'count' => (int) $r['n_sent'], 'queued' => (int) $r['pending'] > 0];
                }
            }
            return $out;
        }

        // === PLANTILLAS =====================================================

        public static function templates() {
            $t = get_option('oec_mail_templates', null);
            if (is_array($t)) return $t;
            return [
                'seguimiento' => [
                    'name'    => 'Seguimiento de interesados',
                    'subject' => '{nombre}, ¿te quedó alguna duda sobre {formacion}?',
                    'body'    => "Hola {nombre}:\n\nVimos que te interesaste en {formacion} y queríamos saber si te quedó alguna duda.\n\nAcá tienes toda la información y la inscripción:\n{link}\n\nSi prefieres, responde este email y te ayudamos.\n\n¡Saludos!",
                ],
            ];
        }

        public static function ajax_template_save() {
            self::require_ajax_admin();
            $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            if ($name === '') wp_send_json_error(['message' => 'Ponle un nombre a la plantilla.']);
            $tpls = self::templates();
            $key  = sanitize_key(wp_unslash($_POST['key'] ?? '')) ?: sanitize_key(remove_accents($name)) . '-' . wp_generate_password(4, false);
            $tpls[$key] = [
                'name'    => $name,
                'subject' => sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
                'body'    => sanitize_textarea_field(wp_unslash($_POST['body'] ?? '')),
            ];
            update_option('oec_mail_templates', $tpls, false);
            wp_send_json_success(['templates' => $tpls, 'key' => $key]);
        }

        public static function ajax_template_delete() {
            self::require_ajax_admin();
            $tpls = self::templates();
            unset($tpls[sanitize_key(wp_unslash($_POST['key'] ?? ''))]);
            update_option('oec_mail_templates', $tpls, false);
            wp_send_json_success(['templates' => $tpls]);
        }
    }
}
