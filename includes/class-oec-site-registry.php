<?php
/**
 * Registro automático del sitio en journey.oe.center (Lead Journey).
 *
 * Una vez por día (WP-Cron), y también apenas cambia la versión del plugin o la dirección del sitio,
 * el plugin le avisa al Worker: "estoy en {dominio}, mis fichas están en {prefijo}/formacion/". Con eso
 * el Worker sabe qué sitios puede imprimir como brochure en PDF (tabla brochure_sites) sin cargarlos a
 * mano. El aviso lleva el token de la API de OEC del sitio (X-OEC-Token): el Worker lo prueba contra la
 * API y solo registra instalaciones con un token válido. Al desactivar el plugin avisa la baja.
 *
 *   POST {OEC_JOURNEY_URL}/v1/sites
 *   X-OEC-Token: <oec_token>
 *   { "site": "https://g-se.com/es", "domain": "g-se.com", "prefix": "/es", "locale": "es_ES", "active": true,
 *     "plugin_version": "1.5.12", "features": ["brochure"] }
 *
 * Sin token configurado no se avisa nada. El resultado del último aviso queda en la opción
 * oec_journey_site ({ at, code, sent }) para revisarlo si hace falta. Por sitio, también en multisite.
 */
if (!defined('ABSPATH')) exit;

if (!defined('OEC_JOURNEY_URL')) define('OEC_JOURNEY_URL', 'https://journey.oe.center');

if (!class_exists('OEC_Site_Registry')) {
    class OEC_Site_Registry {
        const HOOK   = 'oec_journey_register';
        const OPTION = 'oec_journey_site';

        public static function init() {
            add_action(self::HOOK, [self::class, 'register']);
            add_action('init', [self::class, 'schedule'], 30);
        }

        /** Programa el aviso diario; y uno ya mismo si cambió la versión del plugin o la dirección. */
        /** Un sitio local (OEC_ENV = local) no se registra en producción, salvo que el filtro apunte a otro lado. */
        private static function enabled() {
            if (!get_option('oec_token')) return false;
            $local = defined('OEC_ENV') && OEC_ENV === 'local';
            return !$local || apply_filters('oec_journey_url', OEC_JOURNEY_URL) !== OEC_JOURNEY_URL;
        }

        public static function schedule() {
            if (!self::enabled()) {
                if (wp_next_scheduled(self::HOOK)) wp_clear_scheduled_hook(self::HOOK);
                return;
            }
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'daily', self::HOOK);
            }
            $last = get_option(self::OPTION);
            $sent = is_array($last) ? ($last['sent'] ?? []) : [];
            $now  = self::payload(true);
            if (($sent['site'] ?? '') !== $now['site'] || ($sent['plugin_version'] ?? '') !== $now['plugin_version']) {
                // En segundo plano (la próxima corrida de WP-Cron), sin hacer esperar esta visita. El
                // transient evita encolarlo en cada visita mientras tanto.
                if (!get_transient('oec_journey_register_soon') && !wp_next_scheduled(self::HOOK . '_once')) {
                    set_transient('oec_journey_register_soon', 1, HOUR_IN_SECONDS);
                    wp_schedule_single_event(time(), self::HOOK . '_once');
                }
            }
        }

        /** Lo que se le manda al Worker. */
        public static function payload($active = true) {
            $home   = untrailingslashit(home_url());
            $host   = strtolower((string) wp_parse_url($home, PHP_URL_HOST));
            $prefix = untrailingslashit((string) wp_parse_url($home, PHP_URL_PATH));
            return [
                'site'           => $home,
                'domain'         => preg_replace('/^www\./', '', $host),
                'prefix'         => $prefix,
                // En un multisitio por carpetas (/es, /en) varios sitios comparten dominio: el Worker
                // guarda uno por dominio + prefijo y para el brochure usa el de español.
                'locale'         => get_locale(),
                'active'         => (bool) $active,
                'plugin_version' => self::plugin_version(),
                'features'       => ['brochure'],
            ];
        }

        /** Aviso al Worker. $blocking = false en la desactivación (no se espera la respuesta). */
        public static function register($active = true, $blocking = true) {
            if (!self::enabled()) return null;
            $token = (string) get_option('oec_token');
            $active = $active !== false; // WP-Cron llama sin argumentos
            $body   = self::payload($active);
            $res = wp_remote_post(apply_filters('oec_journey_url', OEC_JOURNEY_URL) . '/v1/sites', [
                'timeout'  => $blocking ? 10 : 3,
                'blocking' => $blocking,
                'headers'  => ['Content-Type' => 'application/json', 'X-OEC-Token' => $token],
                'body'     => wp_json_encode($body),
            ]);
            $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
            if ($blocking) {
                if (!$code || $code >= 300) error_log('OEC journey: registro del sitio ' . ($code ?: (is_wp_error($res) ? $res->get_error_message() : 'sin respuesta')));
                update_option(self::OPTION, ['at' => time(), 'code' => $code, 'sent' => $body], false);
            }
            return $code;
        }

        public static function deactivate() {
            self::register(false, false);
            wp_clear_scheduled_hook(self::HOOK);
            wp_clear_scheduled_hook(self::HOOK . '_once');
            delete_option(self::OPTION);
        }

        private static function plugin_version() {
            static $v = null;
            if ($v === null) {
                $data = get_file_data(dirname(__DIR__) . '/oec-main.php', ['Version' => 'Version']);
                $v = (string) ($data['Version'] ?? '');
            }
            return $v;
        }
    }
}

add_action(OEC_Site_Registry::HOOK . '_once', [OEC_Site_Registry::class, 'register']);
