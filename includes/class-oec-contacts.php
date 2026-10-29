<?php
/**
 * Seguimiento de los contactos de Estadísticas: teléfono normalizado (para
 * WhatsApp y llamadas), estado y nota que elige el socio, y registro de cada
 * WhatsApp/llamada que abre desde la ventana de contactos.
 *
 * Todo es por formación: la misma persona puede estar "Interesada" en una y
 * "No le interesa" en otra. La clave de cada persona es su email (en
 * minúsculas) o, si no tiene, su teléfono normalizado ("tel:5491150084412").
 *
 * Tablas por sitio: oec_contact_state (estado + nota) y oec_contact_events
 * (WhatsApp/llamadas). Los emails ya quedan en oec_mail_queue (OEC_Mailer).
 */
if (!class_exists('OEC_Contacts')) {
    class OEC_Contacts {
        const DB_VERSION = '1';

        /** Estados, en el orden en que se muestran. '' = sin contactar. */
        const STATUSES = [
            ''            => 'Sin contactar',
            'contactado'  => 'Contactado',
            'no_responde' => 'No responde',
            'interesado'  => 'Interesado',
            'inscripto'   => 'Se inscribió',
            'no_interesa' => 'No le interesa',
        ];

        /** Código telefónico por país (ISO de Zoho). Lo que no está acá solo se acepta si ya trae el código. */
        const DIAL = [
            'AR' => '54', 'BO' => '591', 'BR' => '55', 'CL' => '56', 'CO' => '57', 'CR' => '506', 'CU' => '53',
            'DO' => '1', 'EC' => '593', 'SV' => '503', 'GT' => '502', 'HN' => '504', 'MX' => '52', 'NI' => '505',
            'PA' => '507', 'PY' => '595', 'PE' => '51', 'PR' => '1', 'UY' => '598', 'VE' => '58', 'ES' => '34',
            'US' => '1', 'CA' => '1', 'PT' => '351', 'IT' => '39', 'FR' => '33', 'DE' => '49', 'GB' => '44',
            'AD' => '376', 'AU' => '61', 'CH' => '41', 'NL' => '31', 'BE' => '32', 'IE' => '353', 'GQ' => '240',
        ];

        const WA_DEFAULT = "Hola {nombre}, ¿cómo estás? Te escribo por {formacion}. ¿Te quedó alguna duda? Acá tienes toda la información: {link}";

        public static function init() {
            add_action('init', [__CLASS__, 'maybe_install']);
            add_action('wp_ajax_oec_contact_event', [__CLASS__, 'ajax_event']);
            add_action('wp_ajax_oec_contact_update', [__CLASS__, 'ajax_update']);
            add_action('wp_ajax_oec_wa_message_save', [__CLASS__, 'ajax_wa_message']);
        }

        private static function t($name) {
            global $wpdb;
            return $wpdb->prefix . 'oec_contact_' . $name;
        }

        public static function maybe_install() {
            if (get_option('oec_contacts_db_version') === self::DB_VERSION) return;
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $c = $wpdb->get_charset_collate();
            dbDelta("CREATE TABLE " . self::t('state') . " (
                training_uid varchar(20) NOT NULL,
                ckey varchar(190) NOT NULL,
                status varchar(20) NOT NULL DEFAULT '',
                note varchar(500) NOT NULL DEFAULT '',
                updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (training_uid,ckey)
            ) $c;");
            dbDelta("CREATE TABLE " . self::t('events') . " (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                training_uid varchar(20) NOT NULL,
                ckey varchar(190) NOT NULL,
                type varchar(10) NOT NULL,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY training_key (training_uid,ckey)
            ) $c;");
            update_option('oec_contacts_db_version', self::DB_VERSION);
        }

        // === TELÉFONOS ======================================================

        /**
         * Teléfono de Zoho → solo dígitos con código de país ("5491150084412"), o ''
         * si no se puede usar. Repara lo típico: sin código de país (lo toma del
         * país del contacto), ceros adelante, "+", espacios y guiones.
         */
        public static function normalize_phone($phone, $country) {
            $phone = trim((string) $phone);
            if ($phone === '' || preg_match('/[a-z]/i', $phone)) return '';
            $digits = ltrim(preg_replace('/\D/', '', $phone), '0');
            $dial   = self::DIAL[strtoupper((string) $country)] ?? '';
            if ($dial !== '' && strpos($digits, $dial) === 0 && strlen($digits) - strlen($dial) >= 7) {
                $full = $digits;
            } elseif ($dial !== '') {
                $full = $dial . $digits;
            } else {
                $full = strlen($digits) >= 11 ? $digits : '';
            }
            // WhatsApp necesita el 9 de los celulares argentinos (54 9 11 …).
            if (strpos($full, '54') === 0 && strlen($full) === 12) $full = '549' . substr($full, 2);
            $len = strlen($full);
            return ($len >= 10 && $len <= 15) ? $full : '';
        }

        public static function contact_key(array $c) {
            if (!empty($c['email'])) return strtolower($c['email']);
            return !empty($c['phone_e164']) ? 'tel:' . $c['phone_e164'] : '';
        }

        // === DATOS PARA LA VENTANA ==========================================

        /**
         * Suma a cada contacto: phone_e164, key, status, note, last_wa, last_call.
         * Fechas en ISO UTC ("2026-10-29T21:33:40Z").
         */
        public static function enrich($uid, array $contacts) {
            global $wpdb;
            foreach ($contacts as &$c) {
                $c['phone_e164'] = self::normalize_phone($c['phone'] ?? '', $c['country'] ?? '');
                $c['key']        = self::contact_key($c);
            }
            unset($c);
            $keys = array_values(array_unique(array_filter(array_column($contacts, 'key'))));
            $state = $events = [];
            foreach (array_chunk($keys, 500) as $chunk) {
                $in   = implode(',', array_fill(0, count($chunk), '%s'));
                $args = array_merge([$uid], $chunk);
                foreach ($wpdb->get_results($wpdb->prepare('SELECT ckey, status, note FROM ' . self::t('state') . " WHERE training_uid = %s AND ckey IN ($in)", $args), ARRAY_A) as $r) {
                    $state[$r['ckey']] = $r;
                }
                foreach ($wpdb->get_results($wpdb->prepare('SELECT ckey, type, MAX(created_at) last, COUNT(*) n FROM ' . self::t('events') . " WHERE training_uid = %s AND ckey IN ($in) GROUP BY ckey, type", $args), ARRAY_A) as $r) {
                    $events[$r['ckey']][$r['type']] = ['at' => str_replace(' ', 'T', $r['last']) . 'Z', 'n' => (int) $r['n']];
                }
            }
            foreach ($contacts as &$c) {
                $k = $c['key'];
                $c['status']    = $state[$k]['status'] ?? '';
                $c['note']      = $state[$k]['note'] ?? '';
                $c['last_wa']   = $events[$k]['wa']['at'] ?? null;
                $c['last_call'] = $events[$k]['call']['at'] ?? null;
                // Alguien a quien ya se le escribió o llamó y no tiene estado: "Contactado".
                if ($c['status'] === '' && ($c['last_wa'] || $c['last_call'] || !empty($c['sent']))) $c['status'] = 'contactado';
            }
            unset($c);
            return $contacts;
        }

        public static function wa_message() {
            $m = get_option('oec_wa_message', '');
            return $m !== '' ? $m : self::WA_DEFAULT;
        }

        // === AJAX ===========================================================

        private static function require_ajax_admin() {
            check_ajax_referer('oec_mail', 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error(null, 403);
        }

        private static function post_target() {
            $uid = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
            $key = strtolower(sanitize_text_field(wp_unslash($_POST['key'] ?? '')));
            $ok  = preg_match('/^t-[A-Za-z0-9]{14}$/', $uid) && (is_email($key) || preg_match('/^tel:\d{10,15}$/', $key));
            if (!$ok) wp_send_json_error(null, 400);
            return [$uid, $key];
        }

        /** Se abrió WhatsApp o se tocó "Llamar" para esta persona. */
        public static function ajax_event() {
            self::require_ajax_admin();
            [$uid, $key] = self::post_target();
            $type = in_array($_POST['type'] ?? '', ['wa', 'call'], true) ? $_POST['type'] : '';
            if (!$type) wp_send_json_error(null, 400);
            global $wpdb;
            $now = current_time('mysql', true);
            $wpdb->insert(self::t('events'), ['training_uid' => $uid, 'ckey' => $key, 'type' => $type, 'user_id' => get_current_user_id(), 'created_at' => $now]);
            wp_send_json_success(['at' => str_replace(' ', 'T', $now) . 'Z']);
        }

        /** Cambia el estado y/o la nota de una persona (los campos que vengan). */
        public static function ajax_update() {
            self::require_ajax_admin();
            [$uid, $key] = self::post_target();
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare('SELECT status, note FROM ' . self::t('state') . ' WHERE training_uid = %s AND ckey = %s', $uid, $key), ARRAY_A) ?: ['status' => '', 'note' => ''];
            if (isset($_POST['status'])) {
                $st = sanitize_key(wp_unslash($_POST['status']));
                if (!array_key_exists($st, self::STATUSES)) wp_send_json_error(null, 400);
                $row['status'] = $st;
            }
            if (isset($_POST['note'])) $row['note'] = mb_substr(sanitize_text_field(wp_unslash($_POST['note'])), 0, 500);
            $wpdb->replace(self::t('state'), [
                'training_uid' => $uid, 'ckey' => $key, 'status' => $row['status'], 'note' => $row['note'],
                'updated_by' => get_current_user_id(), 'updated_at' => current_time('mysql', true),
            ]);
            wp_send_json_success($row);
        }

        public static function ajax_wa_message() {
            self::require_ajax_admin();
            $m = trim(sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')));
            update_option('oec_wa_message', $m, false);
            wp_send_json_success(['message' => $m !== '' ? $m : self::WA_DEFAULT]);
        }
    }
}
