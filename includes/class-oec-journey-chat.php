<?php
/**
 * Chat con IA de Lead Journey en los sitios de socios (v1.5.26; docs/socios-chat.md del proyecto Lead Journey).
 *
 * Si el socio eligió que las consultas las conteste OEC ("Equipo de ventas de OEC", oec_equipo_ventas) y el Worker
 * ya tiene su catálogo, el registro diario (OEC_Site_Registry) recibe "chat": true y lo guarda en la opción
 * oec_journey_chat. Con eso el plugin:
 *  - carga https://journey.oe.center/chat.js (la interfaz del chat, la misma del Tema OEC) con su configuración
 *    en window.oecAiChat: la ficha que se está viendo, su link de inscripción y el pase a WhatsApp;
 *  - muestra en la ficha los botones del chat en lugar del WhatsApp (oec_contact_channels_base()).
 * En las comunidades (tienen el Tema OEC y su propio chat) no hace nada. Sin "chat": true, tampoco.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('OEC_Journey_Chat')) {
    class OEC_Journey_Chat {
        const OPTION = 'oec_journey_chat';

        /** Ficha que se está mostrando: [id, title, enroll, open]. La anota el shortcode de la ficha. */
        private static $page = null;

        public static function init() {
            add_action('wp_footer', [self::class, 'enqueue'], 5);
        }

        /** ¿El chat de Lead Journey atiende este sitio? */
        public static function active() {
            if (function_exists('oec_credits_system_enabled') && oec_credits_system_enabled()) return false; // comunidad
            if (function_exists('oec_is_embed') && oec_is_embed()) return false; // ficha incrustada en un iframe
            return get_option(self::OPTION, '0') === '1' && get_option('oec_equipo_ventas', '1') === '1';
        }

        /** Lo llama la ficha ([oec-content]) con los datos de la formación y su link de inscripción. */
        public static function set_page($data, $enroll_url, $open) {
            $id = (string) ($data['id'] ?? '');
            if (!preg_match('/^t-[A-Za-z0-9]{14}$/', $id)) return;
            self::$page = ['id' => $id, 'title' => (string) ($data['title'] ?? ''), 'enroll' => (string) $enroll_url, 'open' => (bool) $open];
        }

        public static function enqueue() {
            if (is_admin() || !self::active()) return;
            $base = apply_filters('oec_journey_url', defined('OEC_JOURNEY_URL') ? OEC_JOURNEY_URL : 'https://journey.oe.center');
            wp_enqueue_script('oec-journey-chat', $base . '/chat.js', [], self::version(), ['in_footer' => true, 'strategy' => 'defer']);
            wp_localize_script('oec-journey-chat', 'oecAiChat', self::config($base));
        }

        private static function config($base) {
            $p = self::$page;
            $links = [];
            if ($p && $p['enroll'] !== '') $links['INSCRIPCION'] = esc_url_raw($p['enroll']);
            if ($p) {
                $launch = $p['open']
                    ? 'Hola, ¿me cuentas más sobre esta formación?'
                    : 'Hola, veo que esta formación tiene la inscripción cerrada. ¿Qué otras parecidas me recomiendas?';
            } else {
                $launch = is_front_page() ? 'Hola, ¿qué formaciones me recomiendas?' : 'Hola';
            }
            return [
                'context'        => $p ? ['mode' => 'formacion', 'id' => $p['id']] : ['mode' => 'catalogo'],
                'contextTitle'   => $p ? $p['title'] : '',
                'launch'         => $launch,
                'links'          => (object) $links,
                // Pase a WhatsApp por Lead Journey: abre el WhatsApp de la Bandeja unido a esta conversación.
                'waHandoff'      => $base . '/wa/chat',
                'siteName'       => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                // v1.5.31: el globo flotante del chat (páginas sin el botón de la ficha) con el color de marca del sitio.
                'brandColor'     => (string) sanitize_hex_color(get_option('oec_brand_color', '')),
                'logo'           => (string) get_site_icon_url(96),
                'streamEndpoint' => $base . '/v1/chat',
                'clickEndpoint'  => $base . '/v1/chat/click',
                'convUrl'        => $base . '/conversacion/',
            ];
        }

        /** Para la caché del navegador: cambia una vez por día (chat.js lo actualiza el Worker, no el plugin). */
        private static function version() {
            return gmdate('Ymd');
        }
    }
    OEC_Journey_Chat::init();
}
