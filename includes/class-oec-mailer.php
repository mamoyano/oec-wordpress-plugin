<?php
/**
 * Emails a los contactos de Estadísticas.
 *
 * Desde v1.5.4 el plugin NO envía emails masivos: el socio los manda desde su
 * propio Gmail (o su programa de correo), uno por uno, con el mensaje ya armado
 * para cada persona (ventana de contactos de Estadísticas). Motivo: Google no
 * acepta contraseñas de aplicación desde servidores (probado en SiteGround y
 * Cloudways: "535 5.7.8"), y enviar por el correo del hosting o de OEC termina en
 * spam. Cuando OEC tenga su app de Google verificada ("Conectar con Google",
 * API de Gmail), el envío automático vuelve con una cola: la versión anterior
 * está en el historial de git (v1.5.0 – v1.5.3).
 *
 * Lo que queda acá:
 * - Plantillas de email (asunto + mensaje con variables).
 * - Historial de los envíos automáticos que hubo (oec_mail_queue) y bajas
 *   (oec_mail_unsubs, con su link firmado, que tiene que seguir funcionando).
 */
if (!class_exists('OEC_Mailer')) {
    class OEC_Mailer {
        const DB_VERSION = '2';
        const VARS       = ['nombre', 'apellido', 'email', 'telefono', 'pais', 'formacion', 'link'];

        public static function init() {
            add_action('init', [__CLASS__, 'maybe_install']);
            add_action('admin_post_oec_unsub', [__CLASS__, 'unsubscribe']);
            add_action('admin_post_nopriv_oec_unsub', [__CLASS__, 'unsubscribe']);
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
                // De v1.5.0–v1.5.3 (envío por Gmail con contraseña de aplicación): lo que
                // quedaba en cola no sale más, y la contraseña guardada se borra.
                $wpdb->query('UPDATE ' . self::t('queue') . " SET status = 'skipped', error = 'Envío automático desactivado' WHERE status IN ('queued', 'sending')");
                wp_clear_scheduled_hook('oec_mail_tick');
                foreach (['oec_gmail_pass', 'oec_gmail_pass_at', 'oec_gmail_port', 'oec_gmail_name', 'oec_mail_daily_limit', 'oec_mail_last_error'] as $o) delete_option($o);
            }
            update_option('oec_mail_db_version', self::DB_VERSION);
        }

        // === BAJAS ==========================================================

        public static function unsub_url($email) {
            $e = strtolower($email);
            return add_query_arg([
                'action' => 'oec_unsub',
                'e'      => rtrim(strtr(base64_encode($e), '+/', '-_'), '='),
                'k'      => substr(hash_hmac('sha256', $e, wp_salt('nonce')), 0, 20),
            ], admin_url('admin-post.php'));
        }

        public static function unsubscribe() {
            $e = strtolower((string) base64_decode(strtr(sanitize_text_field(wp_unslash($_REQUEST['e'] ?? '')), '-_', '+/')));
            $k = sanitize_text_field(wp_unslash($_REQUEST['k'] ?? ''));
            $ok = is_email($e) && hash_equals(substr(hash_hmac('sha256', $e, wp_salt('nonce')), 0, 20), $k);
            nocache_headers();
            if ($ok) {
                global $wpdb;
                $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::t('unsubs') . ' (email, created_at) VALUES (%s, %s)', $e, current_time('mysql', true)));
            }
            // Baja "de un clic" de Gmail/Apple (List-Unsubscribe-Post): solo el código.
            if ($_SERVER['REQUEST_METHOD'] === 'POST') { status_header($ok ? 200 : 400); exit; }
            $site = esc_html(get_bloginfo('name'));
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

        /** Emails automáticos que se enviaron a cada persona por esta formación: [email => ['sent' => fecha UTC|null, 'count' => n]]. */
        public static function history($uid, array $emails) {
            global $wpdb;
            $emails = array_values(array_unique(array_filter(array_map('strtolower', $emails))));
            $out = [];
            foreach (array_chunk($emails, 500) as $chunk) {
                $in   = implode(',', array_fill(0, count($chunk), '%s'));
                $rows = $wpdb->get_results($wpdb->prepare(
                    'SELECT email, MAX(sent_at) last_sent, COUNT(*) n_sent FROM ' . self::t('queue') . " WHERE training_uid = %s AND status = 'sent' AND email IN ($in) GROUP BY email",
                    array_merge([$uid], $chunk)
                ), ARRAY_A);
                foreach ($rows as $r) {
                    $out[$r['email']] = ['sent' => str_replace(' ', 'T', $r['last_sent']) . 'Z', 'count' => (int) $r['n_sent']];
                }
            }
            return $out;
        }

        // === PLANTILLAS =====================================================

        private static function require_ajax_admin() {
            check_ajax_referer('oec_mail', 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error(null, 403);
        }

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
