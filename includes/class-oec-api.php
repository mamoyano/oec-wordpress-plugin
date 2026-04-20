<?php
class OEC_Api {

    /**
     * Realiza una llamada a la API de OEC con caché via WordPress transients.
     * La caché se desactiva automáticamente en entorno local (OEC_CACHE_ENABLED = false).
     */
    public static function call($endpoint) {
        $cache_key    = 'oec_api_' . md5($endpoint);
        $use_cache    = defined('OEC_CACHE_ENABLED') ? OEC_CACHE_ENABLED : true;

        // Intentar obtener del caché (solo en producción)
        if ($use_cache) {
            $cached_data = get_transient($cache_key);
            if ($cached_data !== false) {
                return $cached_data;
            }
        }

        // El token siempre viene de wp_options (se guarda desde wp-admin → OEC → Configuración)
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

        $data_to_cache = isset($decoded['data']) ? $decoded['data'] : $decoded;

        // Determinar expiración de caché
        if (preg_match('/^trainings\/t-[a-zA-Z0-9]{14}/', $endpoint)) {
            $expiration = 86400; // 24 horas para detalle de formación
        } else {
            $expiration = 3600;  // 1 hora para listados
        }

        // Guardar en caché solo si está habilitado y hay datos
        if ($use_cache && !empty($data_to_cache)) {
            set_transient($cache_key, $data_to_cache, $expiration);
        }

        return $data_to_cache;
    }
}
