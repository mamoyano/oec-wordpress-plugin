<?php
class OEC_Ajax {

    public function __construct() {
        add_action('wp_ajax_oec_more_info',        [$this, 'oec_more_info_ajax']);
        add_action('wp_ajax_nopriv_oec_more_info', [$this, 'oec_more_info_ajax']);

        // Shortcode que expone la URL de AJAX al frontend (útil en templates Twig)
        add_shortcode('oec-ajax-url', function () {
            return admin_url('admin-ajax.php');
        });
    }

    public function oec_more_info_ajax() {
        // 1. Sanitización de entradas
        $uid      = sanitize_text_field($_POST['uid']      ?? '');
        $name     = sanitize_text_field($_POST['name']     ?? '');
        $lastname = sanitize_text_field($_POST['lastname'] ?? '');
        $phone    = sanitize_text_field($_POST['phone']    ?? '');
        $email    = sanitize_email($_POST['email']         ?? '');
        $country  = sanitize_text_field($_POST['country']  ?? 'AR');
        $locale   = sanitize_text_field($_POST['locale']   ?? 'es');

        if (!$uid || !$name || !$lastname || !is_email($email)) {
            wp_send_json_error('Datos incompletos.');
        }

        // 2. ¿Usa su propio equipo de ventas?
        $equipo_oec    = get_option('oec_equipo_ventas', '1');
        $ventas_email  = get_option('oec_ventas_email', '');

        if ($equipo_oec === '0' && !empty($ventas_email)) {
            $this->enviar_email_propio($ventas_email, $uid, $name, $lastname, $email, $phone, $country);
        } else {
            $this->enviar_a_api_oec($uid, $name, $lastname, $email, $phone, $country, $locale);
        }
    }

    /**
     * Envía los datos del formulario por email al equipo de ventas del cliente.
     */
    private function enviar_email_propio($destino, $uid, $name, $lastname, $email, $phone, $country) {
        $site_name   = get_bloginfo('name');
        $asunto      = "[{$site_name}] Nueva consulta de formación";

        $cuerpo  = "Se recibió una nueva consulta desde el formulario de contacto.\n\n";
        $cuerpo .= "Formación (ID): {$uid}\n";
        $cuerpo .= "Nombre:         {$name} {$lastname}\n";
        $cuerpo .= "Email:          {$email}\n";
        $cuerpo .= "Teléfono:       " . ($phone ?: '—') . "\n";
        $cuerpo .= "País:           {$country}\n";
        $cuerpo .= "\n— Enviado desde el plugin OEC WordPress";

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $email,  // Responder directamente al interesado
        ];

        $enviado = wp_mail($destino, $asunto, $cuerpo, $headers);

        if ($enviado) {
            wp_send_json_success('Tu consulta fue enviada correctamente. ¡Nos pondremos en contacto pronto!');
        } else {
            wp_send_json_error('Hubo un problema al enviar el mensaje. Por favor intentá de nuevo.');
        }
    }

    /**
     * Envía los datos a la API de OEC (comportamiento original).
     */
    private function enviar_a_api_oec($uid, $name, $lastname, $email, $phone, $country, $locale) {
        $payload = [
            'uid'          => $uid,
            'name'         => $name,
            'lastname'     => $lastname,
            'email'        => $email,
            'country'      => $country,
            'locale'       => $locale,
            'phone'        => $phone,
            'utm_source'   => '',
            'utm_medium'   => '',
            'utm_campaign' => '',
            'utm_term'     => '',
            'utm_content'  => '',
        ];

        $token = get_option('oec_token', '');

        $ch = curl_init('https://oas-api.onlineeducation.center/api-oas/v1/more-info');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'X-API-TOKEN: ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (isset($data['success']['message'])) {
            wp_send_json_success($data['success']['message']);
        }

        wp_send_json_error('Error en la comunicación con la API.');
    }
}