<?php
if (!class_exists('OEC_Api')) {
    class OEC_Api {

        /**
         * Respuestas ya resueltas EN ESTE REQUEST, por clave de caché
         * ("oec_cache_...", "oec_cache_pg_..."). Lo llena fetch_many() (pedidos
         * en paralelo, ver abajo) y también cada llamada individual al
         * terminar — así call()/call_paginated() se
         * responden desde memoria si el dato ya se pidió antes en la misma
         * página, sin depender de OEC_CACHE_ENABLED (en local el caché de
         * wp_options está apagado a propósito, pero esto es solo del request
         * en curso, no persiste nada). Un fallo también se guarda (con el
         * valor "vacío" de cada tipo) para no reintentar en serie, cada
         * shortcode por su cuenta, algo que ya falló una vez.
         */
        private static $mem = [];

        /**
         * Pide VARIOS endpoints a la vez (un solo lote curl_multi, vía
         * \WpOrg\Requests\Requests::request_multiple) en vez de uno tras otro.
         * Lo usa el prefetch de una página con varios [oec-list] (ver
         * OEC_Shortcodes::prefetch_page()).
         *
         * Cada $job: ['key' => clave de caché, 'kind' => 'paginated'|'plain',
         * 'ttl' => segundos, 'endpoint' => relativo a la API de OEC]. La forma
         * de decodificar y de cachear cada tipo es EXACTAMENTE la de
         * call_paginated()/call() — por eso el resultado es intercambiable con
         * el de esas funciones.
         *
         * Orden de resolución por job: memoria del request → caché de
         * wp_options (si está activa) → red, todo lo que falte junto. Devuelve
         * [clave => dato] para todos los jobs (los que fallan traen el valor
         * vacío de su tipo).
         */
        public static function fetch_many(array $jobs, $label = 'Pedidos en paralelo') {
            $use_cache = defined('OEC_CACHE_ENABLED') ? OEC_CACHE_ENABLED : true;
            $now       = time();
            $results   = [];
            $pending   = [];

            foreach ($jobs as $job) {
                $key = $job['key'];
                if (array_key_exists($key, $results) || isset($pending[$key])) continue;
                if (array_key_exists($key, self::$mem)) {
                    $results[$key] = self::$mem[$key];
                    continue;
                }
                if ($use_cache) {
                    $cached = get_option($key);
                    if ($cached && isset($cached['expires']) && $cached['expires'] > $now) {
                        $results[$key] = self::$mem[$key] = $cached['data'];
                        continue;
                    }
                }
                $pending[$key] = $job;
            }

            if (empty($pending)) return $results;

            $start    = microtime(true);
            $token    = get_option('oec_token');
            $requests = [];
            foreach ($pending as $key => $job) {
                $requests[$key] = [
                    'url'     => 'https://oas-api.onlineeducation.center/api-oas/v1/' . $job['endpoint'],
                    'headers' => ['X-API-TOKEN' => $token, 'Accept' => 'application/json'],
                ];
            }
            $responses = \WpOrg\Requests\Requests::request_multiple($requests, ['timeout' => 20]);

            foreach ($pending as $key => $job) {
                $kind    = $job['kind'];
                $empty   = ($kind === 'paginated') ? ['data' => [], 'pagination' => null] : [];
                $resp    = $responses[$key] ?? null;
                if (!($resp instanceof \WpOrg\Requests\Response) || !$resp->success) {
                    self::$mem[$key] = $results[$key] = $empty;
                    continue;
                }

                $decoded = json_decode($resp->body, true);
                if ($kind === 'paginated') {
                    $data      = [
                        'data'       => $decoded['data'] ?? [],
                        'pagination' => $decoded['meta']['pagination'] ?? null,
                    ];
                    $cacheable = !empty($data['data']);
                } else {
                    $data      = isset($decoded['data']) ? $decoded['data'] : $decoded;
                    $cacheable = !empty($data);
                }

                self::$mem[$key] = $results[$key] = $data;
                if ($use_cache && $cacheable) {
                    update_option($key, ['data' => $data, 'expires' => $now + intval($job['ttl'])], false);
                }
            }

            if (function_exists('oec_debug_record')) {
                oec_debug_record($label, microtime(true) - $start, count($pending) . ' pedidos en paralelo');
            }

            return $results;
        }

        /**
         * Realiza una llamada a la API de OEC con caché via wp_options.
         * Se usa update_option/get_option en lugar de transients para garantizar
         * compatibilidad con multisitio — cada sitio tiene su propia tabla options.
         */
        public static function call($endpoint) {
            $cache_key = 'oec_cache_' . md5($endpoint);
            $use_cache = defined('OEC_CACHE_ENABLED') ? OEC_CACHE_ENABLED : true;

            if (array_key_exists($cache_key, self::$mem)) {
                return self::$mem[$cache_key];
            }

            if ($use_cache) {
                $cached = get_option($cache_key);
                if ($cached && isset($cached['expires']) && $cached['expires'] > time()) {
                    return $cached['data'];
                }
            }

            $token = get_option('oec_token');
            $url   = 'https://oas-api.onlineeducation.center/api-oas/v1/' . $endpoint;

            $response = wp_remote_get($url, [
                'headers' => [
                    'X-API-TOKEN' => $token,
                    'Accept'      => 'application/json',
                ],
                'timeout' => 20,
            ]);

            if (is_wp_error($response)) {
                error_log('OEC API Error: ' . $response->get_error_message());
                return [];
            }

            $body    = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            $data    = isset($decoded['data']) ? $decoded['data'] : $decoded;

            if (preg_match('/^trainings\/t-[a-zA-Z0-9]{14}/', $endpoint)) {
                $expiration = 86400;
            } else {
                $expiration = 3600;
            }

            if (!empty($data)) {
                self::$mem[$cache_key] = $data;
            }
            if ($use_cache && !empty($data)) {
                update_option($cache_key, [
                    'data'    => $data,
                    'expires' => time() + $expiration,
                ], false);
            }

            return $data;
        }

        /**
         * Igual que call(), pero para endpoints de LISTADO donde hace falta el
         * "meta.pagination" que la API devuelve junto a "data" (total, cantidad
         * de páginas, página actual, etc.) — call() lo descarta porque a los
         * demás llamadores (una formación puntual, reviews...) no les sirve.
         * Cachea aparte (prefijo "oec_cache_pg_") para no compartir la entrada
         * de caché con call() sobre el mismo endpoint, ya que la forma
         * cacheada es distinta (acá guardamos data+meta juntos).
         *
         * TTL de 6 horas: el parámetro "inc-reviews=1" (estrellas de cada
         * tarjeta de [oec-list]) le agrega ~2-3x al tiempo de esta llamada
         * (829ms sin reviews vs. 2200ms con reviews, para 12 resultados —
         * medido contra la API real), y el catálogo no cambia tan seguido.
         */
        public static function call_paginated($endpoint) {
            $cache_key = 'oec_cache_pg_' . md5($endpoint);
            $use_cache = defined('OEC_CACHE_ENABLED') ? OEC_CACHE_ENABLED : true;

            if (array_key_exists($cache_key, self::$mem)) {
                return self::$mem[$cache_key];
            }

            if ($use_cache) {
                $cached = get_option($cache_key);
                if ($cached && isset($cached['expires']) && $cached['expires'] > time()) {
                    return $cached['data'];
                }
            }

            $token = get_option('oec_token');
            $url   = 'https://oas-api.onlineeducation.center/api-oas/v1/' . $endpoint;

            $response = wp_remote_get($url, [
                'headers' => [
                    'X-API-TOKEN' => $token,
                    'Accept'      => 'application/json',
                ],
                'timeout' => 20,
            ]);

            if (is_wp_error($response)) {
                error_log('OEC API Error: ' . $response->get_error_message());
                return ['data' => [], 'pagination' => null];
            }

            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            $result  = [
                'data'       => $decoded['data'] ?? [],
                'pagination' => $decoded['meta']['pagination'] ?? null,
            ];

            if (!empty($result['data'])) {
                self::$mem[$cache_key] = $result;
            }
            if ($use_cache && !empty($result['data'])) {
                update_option($cache_key, [
                    'data'    => $result,
                    'expires' => time() + 21600, // 6h — ver docblock de arriba
                ], false);
            }

            return $result;
        }

        /**
         * Lee el color dominante de una imagen SOLO desde caché, sin red — para
         * que el llamador pueda decidir si hace falta pedir la imagen antes de
         * armar un batch de requests en paralelo.
         */
        public static function get_cached_dominant_color($image_url) {
            if (empty($image_url)) return null;
            $cache_key = 'oec_cache_' . md5('dominant_color_' . $image_url);
            $cached    = get_option($cache_key);
            return ($cached && isset($cached['expires']) && $cached['expires'] > time()) ? $cached['data'] : null;
        }

        /**
         * Extrae el color dominante de una imagen remota usando GD.
         * Samplea una cuadrícula de píxeles para calcular el color promedio.
         * Resultado cacheado 7 días — la imagen de una formación no cambia.
         * Devuelve [r, g, b] o null si falla.
         */
        public static function get_dominant_color($image_url) {
            $cached = self::get_cached_dominant_color($image_url);
            if ($cached) return $cached;

            // Necesitamos GD
            if (!function_exists('imagecreatefromjpeg')) return null;

            $response = wp_remote_get($image_url, ['timeout' => 8]);
            if (is_wp_error($response)) return null;

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) return null;

            return self::compute_dominant_color_from_bytes($image_url, $body);
        }

        /**
         * Misma extracción que get_dominant_color(), pero a partir de bytes ya
         * descargados — para cuando la imagen se pidió como parte de un batch
         * en paralelo (Requests::request_multiple) en vez de individualmente.
         */
        public static function compute_dominant_color_from_bytes($image_url, $body) {
            if (empty($body) || !function_exists('imagecreatefromjpeg')) return null;

            // Crear imagen GD desde los bytes
            $img = @imagecreatefromstring($body);
            if (!$img) return null;

            // El CDN a veces ignora format=webp y devuelve un PNG con paleta indexada.
            // imagecolorat() en una imagen con paleta devuelve el índice, no el RGB —
            // forzamos truecolor para que el bit-shift de abajo sea válido siempre.
            imagepalettetotruecolor($img);

            $w = imagesx($img);
            $h = imagesy($img);

            // Samplear una cuadrícula de 8x8 píxeles (evitar bordes con 10% de margen)
            $total_r = $total_g = $total_b = $count = 0;
            $margin_x = (int)($w * 0.1);
            $margin_y = (int)($h * 0.1);
            $step_x   = max(1, (int)(($w - $margin_x * 2) / 8));
            $step_y   = max(1, (int)(($h - $margin_y * 2) / 8));

            for ($x = $margin_x; $x < $w - $margin_x; $x += $step_x) {
                for ($y = $margin_y; $y < $h - $margin_y; $y += $step_y) {
                    $rgb      = imagecolorat($img, $x, $y);
                    $r        = ($rgb >> 16) & 0xFF;
                    $g        = ($rgb >> 8)  & 0xFF;
                    $b        = $rgb & 0xFF;
                    // Ignorar píxeles muy oscuros o muy claros (fondo/sobreexposición)
                    $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                    if ($lum < 20 || $lum > 230) continue;
                    $total_r += $r;
                    $total_g += $g;
                    $total_b += $b;
                    $count++;
                }
            }
            imagedestroy($img);

            if ($count === 0) return null;

            $color = [
                'r' => (int)($total_r / $count),
                'g' => (int)($total_g / $count),
                'b' => (int)($total_b / $count),
            ];

            $cache_key = 'oec_cache_' . md5('dominant_color_' . $image_url);
            update_option($cache_key, [
                'data'    => $color,
                'expires' => time() + 604800, // 7 días
            ], false);

            return $color;
        }
        public static function clear_cache() {
            global $wpdb;
            self::$mem = [];

            $where = "option_name LIKE 'oec\_cache\_%' OR option_name LIKE 'oec\_stats\_cache\_%' OR option_name = 'oec_admin_cache'";
            $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE $where");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE $where");

            // El DELETE directo no pasa por delete_option(): sin esto, un sitio con
            // caché de objetos persistente (Redis/Memcached) seguiría sirviendo los
            // valores viejos y el botón "Actualizar caché" no tendría efecto.
            foreach ($names as $name) {
                wp_cache_delete($name, 'options');
            }
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }
}
