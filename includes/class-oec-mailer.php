<?php
/**
 * Envío de emails a los contactos de Estadísticas (Hot Prospects, Perdidos…).
 *
 * - Gmail integrado: el socio carga su Gmail + una contraseña de aplicación en
 *   OEC → Configuración y los mails del plugin salen desde su casilla (SMTP de
 *   Google). Solo se aplica a los mails del plugin (send()); el resto del correo
 *   del sitio no se toca. Sin Gmail cargado, sale por wp_mail() tal cual (y por
 *   el plugin de SMTP que el sitio ya tenga, si tiene uno).
 * - Cada envío masivo es una campaña con una fila por persona en una cola, que
 *   además es el registro ("a quién ya le escribí y cuándo"). La cola se despacha
 *   en segundo plano (WP-Cron) y, mientras la ventana de envío está abierta, desde
 *   el navegador (oec_mail_progress), con un tope diario por el límite de Gmail.
 * - Bajas: cada mail lleva un link (admin-post.php, que ningún caché de página
 *   guarda) y la cabecera List-Unsubscribe. Quien se da de baja no vuelve a recibir.
 *
 * Tablas por sitio ($wpdb->prefix: multisite-safe): oec_mail_campaigns,
 * oec_mail_queue, oec_mail_unsubs.
 */
if (!class_exists('OEC_Mailer')) {
    class OEC_Mailer {
        const DB_VERSION    = '1';
        const BATCH_CRON    = 15;   // mails por pasada de cron
        const BATCH_AJAX    = 3;    // mails por pedido del navegador
        const DAILY_DEFAULT = 400;  // Gmail gratis: ~500 destinatarios por día
        const VARS          = ['nombre', 'apellido', 'email', 'telefono', 'pais', 'formacion', 'link'];

        /** true mientras send() está mandando: phpmailer_init solo actúa ahí. */
        private static $sending = false;
        private static $alt_body = '';
        private static $last_error = '';
        private static $last_smtp  = 0;
        private static $smtp_detail = '';
        /** Lo que de verdad se usó en el último envío por Gmail, para diagnosticar (sin la contraseña). */
        private static $diag = [];
        /** Datos del formulario que se están probando antes de guardarlos (ajax_test). */
        private static $trial = null;

        public static function init() {
            add_action('init', [__CLASS__, 'maybe_install']);
            add_action('phpmailer_init', [__CLASS__, 'configure_phpmailer'], 99);
            add_action('phpmailer_init', [__CLASS__, 'inspect_phpmailer'], PHP_INT_MAX);
            add_action('wp_mail_failed', [__CLASS__, 'capture_error']);
            add_action('oec_mail_tick', [__CLASS__, 'cron_tick']);

            add_action('admin_post_oec_unsub', [__CLASS__, 'unsubscribe']);
            add_action('admin_post_nopriv_oec_unsub', [__CLASS__, 'unsubscribe']);

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
            if (get_option('oec_mail_db_version') === self::DB_VERSION) return;
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
            update_option('oec_mail_db_version', self::DB_VERSION);
        }

        // === GMAIL ==========================================================

        private static function crypt_key() {
            return hash('sha256', wp_salt('auth') . '|oec-gmail', true);
        }

        public static function encrypt($plain) {
            if (!function_exists('openssl_encrypt')) return '';
            $iv  = random_bytes(12);
            $tag = '';
            $enc = openssl_encrypt($plain, 'aes-256-gcm', self::crypt_key(), OPENSSL_RAW_DATA, $iv, $tag);
            return $enc === false ? '' : base64_encode($iv . $tag . $enc);
        }

        public static function decrypt($stored) {
            $raw = base64_decode((string) $stored, true);
            if (!$raw || strlen($raw) < 29 || !function_exists('openssl_decrypt')) return '';
            $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::crypt_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return $out === false ? '' : $out;
        }

        /** ['user' => …, 'pass' => …, 'name' => …] o null si no está configurado (o no se puede descifrar). */
        public static function gmail() {
            if (self::$trial) return self::$trial;
            $user = get_option('oec_gmail_user', '');
            $pass = $user ? self::decrypt(get_option('oec_gmail_pass', '')) : '';
            if (!$user || $pass === '') return null;
            return ['user' => $user, 'pass' => $pass, 'name' => get_option('oec_gmail_name', '') ?: get_bloginfo('name')];
        }

        /** Guarda la caja "Enviar desde tu Gmail" de Configuración. Devuelve un mensaje para mostrar. */
        public static function save_settings($post) {
            if (!empty($post['oec_gmail_remove'])) {
                delete_option('oec_gmail_user');
                delete_option('oec_gmail_pass');
                return 'Se quitó el Gmail. Los emails van a salir con el correo del sitio.';
            }
            $user = sanitize_email(wp_unslash($post['oec_gmail_user'] ?? ''));
            update_option('oec_gmail_name', sanitize_text_field(wp_unslash($post['oec_gmail_name'] ?? '')), false);
            update_option('oec_mail_daily_limit', max(1, min(2000, intval($post['oec_mail_daily_limit'] ?? self::DAILY_DEFAULT))), false);
            if ($user && !is_email($user)) return 'El email de Gmail no es válido.';
            update_option('oec_gmail_user', $user, false);
            // Google la muestra en 4 grupos ("abcd efgh ijkl mnop"): los espacios no van.
            $pass = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', wp_unslash($post['oec_gmail_pass'] ?? ''));
            if ($pass !== '') {
                // Las contraseñas de aplicación son 16 letras. Otra cosa suele ser la contraseña
                // normal de Google (la completa sola el navegador o el gestor de contraseñas).
                if (!preg_match('/^[a-z]{16}$/i', $pass)) {
                    return 'Esa no es una contraseña de aplicación: tienen 16 letras (Google las muestra en 4 grupos de 4). No se guardó. Si el navegador o tu gestor de contraseñas completó el campo solo, bórralo y pega la contraseña de aplicación.';
                }
                update_option('oec_gmail_pass', self::encrypt($pass), false);
                update_option('oec_gmail_pass_at', time(), false);
                delete_option('oec_gmail_port'); // credenciales nuevas: se vuelve a probar el puerto normal
            }
            return 'Configuración de email guardada.';
        }

        public static function daily_limit() {
            return max(1, intval(get_option('oec_mail_daily_limit', self::DAILY_DEFAULT)));
        }

        /** ¿El sitio ya manda por SMTP con otro plugin? Solo para avisarlo en Configuración. */
        public static function other_smtp_plugin() {
            if (defined('WPMS_PLUGIN_VER') || class_exists('WPMailSMTP\Core')) return 'WP Mail SMTP';
            if (defined('FLUENTMAIL')) return 'FluentSMTP';
            if (class_exists('PostmanOptions')) return 'Post SMTP';
            if (function_exists('easy_wp_smtp')) return 'Easy WP SMTP';
            if (defined('OEC_SMTP_USER') && defined('OEC_SMTP_PASS')) return 'el correo configurado en el tema';
            return '';
        }

        public static function configure_phpmailer($phpmailer) {
            if (!self::$sending) return;
            if (self::$alt_body !== '') $phpmailer->AltBody = self::$alt_body;
            $g = self::gmail();
            if (!$g) return;
            $ssl  = (int) get_option('oec_gmail_port', 587) === 465;
            $smtp = apply_filters('oec_gmail_smtp', ['host' => 'smtp.gmail.com', 'port' => $ssl ? 465 : 587, 'secure' => $ssl ? 'ssl' : 'tls', 'auth' => true]);
            $phpmailer->isSMTP();
            $phpmailer->Host       = $smtp['host'];
            $phpmailer->Port       = $smtp['port'];
            $phpmailer->SMTPSecure = $smtp['secure'];
            $phpmailer->SMTPAutoTLS = $smtp['secure'] !== '';
            $phpmailer->SMTPAuth   = $smtp['auth'];
            $phpmailer->Username   = $g['user'];
            $phpmailer->Password   = $g['pass'];
            $phpmailer->Timeout    = 20;
            // El primer error del servidor (p. ej. "535 … Username and Password not
            // accepted"): PHPMailer lo pisa después con el del QUIT y lo traduce. Solo se
            // leen las respuestas del servidor, nunca lo que manda el cliente.
            $phpmailer->SMTPDebug   = 2;
            $phpmailer->Debugoutput = function ($str) {
                // Saludo del servidor ("220 smtp.gmail.com ESMTP … - gsmtp"): dice si de verdad
                // nos atendió Google o un servidor del hosting que intercepta el puerto.
                if (empty(self::$diag['greeting']) && preg_match('/SERVER -> CLIENT: 220[ -]([^\r\n]*)/', $str, $m)) {
                    self::$diag['greeting'] = mb_substr(trim($m[1]), 0, 150);
                }
                if (!self::$last_smtp && preg_match('/SERVER -> CLIENT: ([45]\d\d)[ -]([^\r\n]*)/', $str, $m)) {
                    self::$last_smtp   = (int) $m[1];
                    self::$smtp_detail = mb_substr(trim($m[2]), 0, 150);
                }
            };
            // Gmail reescribe el remitente a la cuenta autenticada: se pone igual.
            $phpmailer->setFrom($g['user'], $g['name'], false);
        }

        /** Corre después de cualquier otro plugin: anota con qué servidor y usuario se va a enviar de verdad. */
        public static function inspect_phpmailer($phpmailer) {
            if (!self::$sending || !self::gmail()) return;
            $g = self::gmail();
            self::$diag += [
                'host'      => (string) $phpmailer->Host,
                'port'      => (int) $phpmailer->Port,
                'user'      => (string) $phpmailer->Username,
                'pass_ours' => $phpmailer->Password === $g['pass'],
            ];
        }

        public static function capture_error($wp_error) {
            if (!self::$sending || !is_wp_error($wp_error)) return;
            self::$last_error = $wp_error->get_error_message();
            if (self::$smtp_detail !== '') self::$last_error .= ' (' . self::$last_smtp . ' ' . self::$smtp_detail . ')';
        }

        /** Error de SMTP → algo que el socio entienda. */
        private static function human_error($e, $code) {
            $g = self::gmail() !== null;
            if (in_array($code, [534, 535], true)) {
                if (!$g) return 'El servidor de correo rechazó el usuario o la contraseña.';
                if (stripos(self::$smtp_detail, '5.7.9') !== false) return 'Gmail pide una contraseña de aplicación: la que guardaste parece ser tu contraseña normal de Google. Crea una en myaccount.google.com/apppasswords.';
                if (stripos(self::$smtp_detail, '5.7.14') !== false) return 'Google bloqueó el inicio de sesión desde tu servidor. Entra a tu cuenta de Google, revisa las alertas de seguridad y confirma que fuiste tú; después vuelve a probar.';
                return 'Gmail rechazó el email o la contraseña de aplicación. Revísalos en OEC → Configuración.';
            }
            if ($code === 550 && stripos($e, '5.4.5') !== false || $code === 421 || stripos($e, 'limit exceeded') !== false) {
                return 'Se alcanzó el límite de envíos de la cuenta de correo. Se reintenta más tarde.';
            }
            if ($code === 0 && (stripos($e, 'connect') !== false || stripos($e, 'conectar') !== false)) {
                return $g ? 'No se pudo conectar con Gmail desde tu servidor. Consulta a tu hosting si permite conexiones SMTP salientes (puerto 587).' : 'No se pudo conectar con el servidor de correo.';
            }
            return $e !== '' ? $e : 'No se pudo enviar el email.';
        }

        /**
         * Manda un email del plugin (por Gmail si está configurado). Devuelve '' si
         * salió bien, o el error. $extra_headers: cabeceras adicionales.
         */
        public static function send($to, $subject, $html, $text = '', array $extra_headers = [], $reply_to = '') {
            $headers = array_merge(['Content-Type: text/html; charset=UTF-8'], $extra_headers);
            if ($reply_to && is_email($reply_to)) $headers[] = 'Reply-To: ' . $reply_to;
            self::$sending    = true;
            self::$alt_body   = $text;
            self::$last_error = '';
            self::$last_smtp  = 0;
            self::$smtp_detail = '';
            self::$diag        = [];
            $ok = wp_mail($to, $subject, $html, $headers);
            self::$sending  = false;
            self::$alt_body = '';
            return $ok ? '' : self::human_error(self::$last_error, self::$last_smtp);
        }

        public static function ajax_test() {
            check_ajax_referer('oec_mail', 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error(null, 403);
            $to  = wp_get_current_user()->user_email;

            // Se prueba lo que está escrito en el formulario (aunque no se haya guardado);
            // si funciona, se guarda. Antes se probaba siempre lo guardado, y una contraseña
            // recién pegada sin tocar "Guardar" nunca se probaba.
            $f_user = sanitize_email(wp_unslash($_POST['user'] ?? ''));
            $f_pass = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', wp_unslash($_POST['pass'] ?? ''));
            $f_name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
            $saved  = self::gmail();
            $new    = $f_pass !== '' || ($f_user !== '' && $saved && strcasecmp($f_user, $saved['user']) !== 0);
            if ($new) {
                if (!is_email($f_user)) wp_send_json_error(['message' => 'Escribe tu dirección de Gmail.']);
                if ($f_pass === '') $f_pass = $saved['pass'] ?? '';
                if (!preg_match('/^[a-z]{16}$/i', $f_pass)) {
                    wp_send_json_error(['message' => 'Esa no es una contraseña de aplicación: tienen 16 letras (Google las muestra en 4 grupos de 4).']);
                }
                self::$trial = ['user' => $f_user, 'pass' => $f_pass, 'name' => $f_name ?: (get_option('oec_gmail_name', '') ?: get_bloginfo('name'))];
                delete_option('oec_gmail_port');
            }
            $g   = self::gmail();
            $err = self::send($to, 'Prueba de envío — ' . get_bloginfo('name'),
                '<p>¡Funciona! Este es un email de prueba del plugin de Online Education Center.</p><p>' . ($g ? 'Salió desde tu Gmail (' . esc_html($g['user']) . ').' : 'Salió con el correo del sitio (no hay Gmail configurado).') . '</p>',
                "¡Funciona! Este es un email de prueba del plugin de Online Education Center.");
            if ($err && $g) {
                $d = self::$diag;
                // Otro plugin cambió el servidor, el usuario o la contraseña después de nosotros.
                if (!empty($d['host']) && ($d['host'] !== 'smtp.gmail.com' || strcasecmp($d['user'], $g['user']) !== 0 || empty($d['pass_ours']))
                    && !has_filter('oec_gmail_smtp')) {
                    wp_send_json_error(['message' => 'Otro plugin de tu sitio cambia la configuración de correo y pisa la de Gmail (se intentó enviar con ' . $d['host'] . ' como ' . ($d['user'] ?: 'sin usuario') . '). Desactiva ese plugin de correo o configura tu Gmail en él.']);
                }
                // El hosting atiende el puerto 587 con su propio servidor: se prueba por el 465 (SSL).
                $google = isset($d['greeting']) && preg_match('/gsmtp|google/i', $d['greeting']);
                if (!$google && (int) get_option('oec_gmail_port', 587) !== 465 && !has_filter('oec_gmail_smtp')) {
                    $first = $err;
                    update_option('oec_gmail_port', 465, false);
                    $err = self::send($to, 'Prueba de envío — ' . get_bloginfo('name'),
                        '<p>¡Funciona! Este es un email de prueba del plugin de Online Education Center.</p><p>Salió desde tu Gmail (' . esc_html($g['user']) . '), por el puerto 465.</p>',
                        "¡Funciona! Este es un email de prueba del plugin de Online Education Center.");
                    if ($err) {
                        delete_option('oec_gmail_port');
                        $err = $first . ' Tu servidor no se conecta directo con Gmail' . (isset($d['greeting']) ? ' (respondió: ' . $d['greeting'] . ')' : '') . ': consulta a tu hosting si permite conexiones SMTP salientes a smtp.gmail.com.';
                        wp_send_json_error(['message' => $err]);
                    }
                    wp_send_json_success(['message' => 'Listo: tu servidor no deja salir el correo por el puerto habitual, así que se configuró el 465 y funcionó. Te enviamos un email de prueba a ' . $to . '.']);
                }
                $tech = trim(self::$last_smtp ? self::$last_smtp . ' ' . self::$smtp_detail : '');
                if ($tech !== '') $err .= ' (Respuesta de Gmail: ' . $tech . ')';
                // Chequeo directo, sin wp_mail(): ¿Google acepta este usuario y esta contraseña
                // desde este servidor? Separa "algo del sitio cambia los datos" de "Google no deja".
                if (in_array(self::$last_smtp, [534, 535], true) && !has_filter('oec_gmail_smtp')) {
                    $direct = self::direct_auth_check($g['user'], $g['pass'], (int) get_option('oec_gmail_port', 587));
                    $hint   = ' [contraseña probada: ' . substr($g['pass'], 0, 1) . str_repeat('•', 14) . substr($g['pass'], -1) . ']';
                    if ($direct['ok']) {
                        $hooks = self::mail_hooks();
                        $err = 'Gmail acepta tu email y tu contraseña cuando se le pregunta directo, pero no cuando envía WordPress: algo en tu sitio cambia los datos al enviar (otro plugin de correo, o un fragmento de código como los de WPCode).'
                             . ($hooks ? ' Enganchados al correo: ' . implode(', ', $hooks) . '.' : '') . $hint;
                    } elseif ($direct['code']) {
                        $err .= ' Chequeo directo: Gmail también la rechaza desde este servidor (' . $direct['code'] . ' ' . $direct['text'] . ').' . $hint;
                    } else {
                        $err .= ' (Chequeo directo: ' . $direct['text'] . ')';
                    }
                }
                $len = strlen($g['pass']);
                if ($len !== 16) $err .= ' La contraseña guardada tiene ' . $len . ' caracteres y las de aplicación tienen 16: vuelve a pegarla.';
                if (!$new) {
                    $at   = (int) get_option('oec_gmail_pass_at', 0);
                    $err .= ' Se probó la contraseña guardada' . ($at ? ' el ' . wp_date('j/n/Y \a \l\a\s H:i', $at) : '') . ': si creaste una nueva, pégala en el campo y vuelve a probar.';
                }
            }
            if ($err) wp_send_json_error(['message' => $err]);
            if ($new) {
                // Funcionó con lo del formulario: queda guardado.
                update_option('oec_gmail_user', self::$trial['user'], false);
                update_option('oec_gmail_pass', self::encrypt(self::$trial['pass']), false);
                update_option('oec_gmail_pass_at', time(), false);
                if ($f_name !== '') update_option('oec_gmail_name', $f_name, false);
                delete_option('oec_mail_last_error');
                wp_send_json_success(['message' => 'Funcionó y quedó guardado: te enviamos un email de prueba a ' . $to . '. Revisa tu bandeja (y la carpeta de spam).', 'saved' => true]);
            }
            wp_send_json_success(['message' => 'Listo: te enviamos un email de prueba a ' . $to . '. Revisa tu bandeja (y la carpeta de spam).']);
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
            $site    = get_bloginfo('name');
            $html = '<!doctype html><html><body style="margin:0;padding:0;">'
                  . '<div style="max-width:600px;margin:0 auto;padding:24px 20px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#1f2937;">'
                  . $body
                  . '<p style="margin-top:32px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">'
                  . 'Recibes este email porque te interesaste en ' . esc_html($campaign['training_title']) . '. '
                  . '<a href="' . esc_url($unsub) . '" style="color:#6b7280;">No quiero recibir más emails de ' . esc_html($site) . '</a>.</p>'
                  . '</div></body></html>';
            $text .= "\n\n--\nNo quiero recibir más emails de " . $site . ': ' . $unsub;
            return [$subject, $html, $text];
        }

        private static function list_headers($email) {
            $url = self::unsub_url($email);
            return ['List-Unsubscribe: <' . $url . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'];
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
                // Lo que estaba en cola para esa persona ya no sale.
                $wpdb->query($wpdb->prepare('UPDATE ' . self::t('queue') . " SET status = 'skipped', error = 'Se dio de baja' WHERE email = %s AND status = 'queued'", $e));
            }
            // Baja "de un clic" de Gmail/Apple (List-Unsubscribe-Post): solo el código.
            if ($_SERVER['REQUEST_METHOD'] === 'POST') { status_header($ok ? 200 : 400); exit; }
            $site = esc_html(get_bloginfo('name'));
            wp_die($ok
                ? '<h1>Listo</h1><p>No vas a recibir más emails de ' . $site . '.</p>'
                : '<h1>Enlace inválido</h1><p>No pudimos procesar la baja. Responde el email que recibiste y te quitamos de la lista.</p>',
                $site, ['response' => $ok ? 200 : 400]);
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
            $reply   = sanitize_email(wp_unslash($_POST['reply_to'] ?? ''));
            if ($subject === '' || trim($body) === '') wp_send_json_error(['message' => 'Completa el asunto y el mensaje.']);
            $slug = sanitize_title(wp_unslash($_POST['slug'] ?? ''));
            return [
                'training_title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
                'training_link'  => $slug !== '' ? home_url('/formacion/' . $slug) : home_url('/'),
                'subject'        => $subject,
                'body'           => $body,
                'reply_to'       => is_email($reply) ? $reply : '',
            ];
        }

        /** "Enviarme una prueba": la campaña con los datos de la primera persona, a mi email. */
        public static function ajax_preview_test() {
            self::require_ajax_admin();
            $campaign = self::campaign_from_post();
            $sample   = json_decode(wp_unslash($_POST['sample'] ?? ''), true);
            $c = self::clean_contact(is_array($sample) ? $sample : []);
            if (!$c['email']) $c['email'] = wp_get_current_user()->user_email;
            [$subject, $html, $text] = self::render($campaign, $c);
            $to  = wp_get_current_user()->user_email;
            $err = self::send($to, '[Prueba] ' . $subject, $html, $text, [], $campaign['reply_to']);
            if ($err) wp_send_json_error(['message' => $err]);
            wp_send_json_success(['message' => 'Prueba enviada a ' . $to . '.']);
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

        /**
         * Crea la campaña y encola a los elegidos. Los contactos se vuelven a pedir a
         * Zoho (OEC_Stats::contacts_for): del navegador solo llegan los emails elegidos,
         * así no se le puede escribir a nadie que no esté en esa etapa.
         */
        public static function ajax_send() {
            self::require_ajax_admin();
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
                'reply_to'       => $campaign['reply_to'],
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
            $cid = intval($_POST['campaign'] ?? 0);
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
                // Gmail rechazó la conexión: reintentar cada minuto podría hacer que
                // Google bloquee la cuenta. Se reintenta en una hora (o al reabrir el envío).
                self::schedule(time() + HOUR_IN_SECONDS);
            } elseif ($info['paused']) {
                // Tope diario: sigue mañana a primera hora (hora del sitio).
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
            // Una fila que quedó "enviando" más de 10 min se cortó a mitad: no se
            // reintenta (podría haber salido) y queda como error para que se vea.
            $wpdb->query($wpdb->prepare("UPDATE $q SET status = 'failed', error = 'Se interrumpió el envío' WHERE status = 'sending' AND sent_at < %s", gmdate('Y-m-d H:i:s', time() - 600)));

            $start     = microtime(true);
            $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM $q WHERE status = 'queued'");
            $budget    = self::daily_limit() - self::sent_today();
            $paused    = $remaining > 0 && $budget <= 0;
            $done      = 0;
            $campaigns = [];

            while ($remaining > 0 && $done < $max && $budget > 0 && microtime(true) - $start < $seconds) {
                $row = $wpdb->get_row("SELECT * FROM $q WHERE status = 'queued' ORDER BY id LIMIT 1", ARRAY_A);
                if (!$row) break;
                $claimed = $wpdb->query($wpdb->prepare("UPDATE $q SET status = 'sending', sent_at = %s WHERE id = %d AND status = 'queued'", current_time('mysql', true), $row['id']));
                if (!$claimed) continue;
                $remaining--;

                if (!self::valid_address($row['email'])) {
                    $wpdb->update($q, ['status' => 'failed', 'error' => 'Dirección de email inválida'], ['id' => $row['id']]);
                    continue;
                }
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
                $err = self::send($row['email'], $subject, $html, $text, self::list_headers($row['email']), $campaign['reply_to']);
                if ($err === '') {
                    $wpdb->update($q, ['status' => 'sent', 'sent_at' => current_time('mysql', true), 'error' => ''], ['id' => $row['id']]);
                    $budget--;
                    delete_option('oec_mail_last_error');
                } else {
                    // Las direcciones ya se validaron arriba: un error acá es de Gmail o del
                    // servidor (contraseña, límite, conexión) y afecta a todos. La fila vuelve
                    // a la cola y se corta la pasada, en vez de marcar a todo el mundo como fallido.
                    $wpdb->update($q, ['status' => 'queued', 'sent_at' => null, 'error' => mb_substr($err, 0, 250)], ['id' => $row['id']]);
                    update_option('oec_mail_last_error', ['message' => $err, 'at' => time()], false);
                    return ['remaining' => $remaining + 1, 'paused' => false, 'error' => $err];
                }
                $done++;
            }
            if ($remaining > 0 && $budget <= 0) $paused = true;
            return ['remaining' => $remaining, 'paused' => $paused, 'error' => ''];
        }

        /**
         * Pregunta a smtp.gmail.com, sin PHPMailer ni wp_mail(), si acepta este usuario y esta
         * contraseña (AUTH PLAIN) y corta antes de enviar nada. ['ok' => bool, 'code' => int, 'text' => string].
         */
        private static function direct_auth_check($user, $pass, $port) {
            $ssl = $port === 465;
            $ctx = stream_context_create(['ssl' => ['peer_name' => 'smtp.gmail.com', 'verify_peer' => true]]);
            $fp  = @stream_socket_client(($ssl ? 'ssl' : 'tcp') . '://smtp.gmail.com:' . ($ssl ? 465 : 587), $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
            if (!$fp) return ['ok' => false, 'code' => 0, 'text' => 'no se pudo conectar (' . $errstr . ')'];
            stream_set_timeout($fp, 10);
            $read = function () use ($fp) {
                $all = '';
                while (($line = fgets($fp, 1024)) !== false) {
                    $all .= $line;
                    if (strlen($line) < 4 || $line[3] === ' ') break;
                }
                return [(int) substr($all, 0, 3), trim(preg_replace('/^\d{3}[ -]/m', '', $all))];
            };
            $cmd = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
            $out = ['ok' => false, 'code' => 0, 'text' => ''];
            [$code] = $read();
            if ($code !== 220) { fclose($fp); return ['ok' => false, 'code' => 0, 'text' => 'el servidor no respondió como Gmail']; }
            $cmd('EHLO ' . (parse_url(home_url(), PHP_URL_HOST) ?: 'localhost'));
            if (!$ssl) {
                [$code] = $cmd('STARTTLS');
                if ($code !== 220 || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($fp);
                    return ['ok' => false, 'code' => 0, 'text' => 'no se pudo cifrar la conexión'];
                }
                $cmd('EHLO ' . (parse_url(home_url(), PHP_URL_HOST) ?: 'localhost'));
            }
            [$code, $text] = $cmd('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass));
            $out = ['ok' => $code === 235, 'code' => $code, 'text' => mb_substr(strtok($text, "\n"), 0, 120)];
            $cmd('QUIT');
            fclose($fp);
            return $out;
        }

        /** Funciones y plugins enganchados al correo de WordPress, para el diagnóstico (sin los nuestros). */
        private static function mail_hooks() {
            global $wp_filter;
            $out = [];
            foreach (['phpmailer_init', 'pre_wp_mail', 'wp_mail'] as $hook) {
                if (empty($wp_filter[$hook])) continue;
                foreach ($wp_filter[$hook]->callbacks as $cbs) {
                    foreach ($cbs as $cb) {
                        $f = $cb['function'];
                        if (is_array($f)) $name = (is_object($f[0]) ? get_class($f[0]) : $f[0]) . '::' . $f[1];
                        elseif (is_string($f)) $name = $f;
                        else {
                            try { $r = new ReflectionFunction($f); $name = 'función de ' . str_replace(ABSPATH, '', (string) $r->getFileName()); }
                            catch (Exception $e) { $name = 'función anónima'; }
                        }
                        if (strpos($name, 'OEC_Mailer') === 0) continue;
                        $out[] = $name;
                    }
                }
            }
            $out = array_unique($out);
            // wp_mail() reemplazada por un plugin (es "pluggable").
            try {
                $file = (new ReflectionFunction('wp_mail'))->getFileName();
                if (strpos($file, WPINC . '/pluggable.php') === false) array_unshift($out, 'wp_mail() reemplazada en ' . str_replace(ABSPATH, '', $file));
            } catch (Exception $e) {}
            return array_values($out);
        }

        /** Lo que acepta WordPress y lo que acepta PHPMailer (más estricto en algunos casos). */
        private static function valid_address($email) {
            if (!is_email($email)) return false;
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            return \PHPMailer\PHPMailer\PHPMailer::validateAddress($email);
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

        /** Último email enviado / en cola a cada persona por esta formación: [email => ['sent' => fecha UTC|null, 'queued' => bool]]. */
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
