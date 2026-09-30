<?php
if (!class_exists('OEC_Ajax')) {
    class OEC_Ajax {

        public function __construct() {
            add_action('wp_ajax_oec_more_info',        [$this, 'oec_more_info_ajax']);
            add_action('wp_ajax_nopriv_oec_more_info', [$this, 'oec_more_info_ajax']);

            // Sistema de créditos por descuentos
            add_action('wp_ajax_oec_check_credits',        [$this, 'oec_check_credits_ajax']);
            add_action('wp_ajax_nopriv_oec_check_credits', [$this, 'oec_check_credits_ajax']);
            add_action('wp_ajax_oec_list_coupons',         [$this, 'oec_list_coupons_ajax']);
            add_action('wp_ajax_nopriv_oec_list_coupons',  [$this, 'oec_list_coupons_ajax']);
            add_action('wp_ajax_oec_request_redeem',        [$this, 'oec_request_redeem_ajax']);
            add_action('wp_ajax_nopriv_oec_request_redeem', [$this, 'oec_request_redeem_ajax']);

            // Shortcode que expone la URL de AJAX al frontend (útil en templates Twig)
            add_shortcode('oec-ajax-url', function () {
                return admin_url('admin-ajax.php');
            });

            // Página de confirmación del segundo paso del canje (link del primer email)
            add_shortcode('oec-confirm-redeem', [$this, 'oec_confirm_redeem_shortcode']);
        }

        /**
         * Nonce compartido por todos los endpoints públicos de este archivo — solo
         * se genera embebido en la página de la formación (ver class-oec-shortcodes.php),
         * así que un request directo a admin-ajax.php sin haber cargado esa página
         * primero no lo tiene. No para contra un atacante dirigido, pero frena bots
         * y scrapers genéricos que le peguen directo al endpoint.
         */
        private function check_nonce() {
            if (!check_ajax_referer('oec_ajax', 'nonce', false)) {
                wp_send_json_error('Sesión inválida, recarga la página e intenta de nuevo.');
            }
        }

        /**
         * Throttle simple por email: como mucho un request cada $seconds a esta
         * acción. Pensado para oec_request_redeem, que manda un email real —
         * sin esto, cualquiera puede usarlo para bombardear de emails a otra
         * persona con solo saber su dirección.
         */
        private function throttle($action, $key, $seconds) {
            $throttle_key = 'oec_throttle_' . $action . '_' . md5(strtolower($key));
            if (get_transient($throttle_key)) return false;
            set_transient($throttle_key, 1, $seconds);
            return true;
        }

        public function oec_more_info_ajax() {
            $this->check_nonce();

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
                // Mismo texto que enviar_a_api_oec() más abajo — un solo
                // mensaje de éxito para las dos rutas posibles del formulario.
                wp_send_json_success('¡Listo! Ya recibimos tu consulta — te vamos a contactar a la brevedad.');
            } else {
                wp_send_json_error('Hubo un problema al enviar el mensaje. Por favor intenta de nuevo.');
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

            // El mensaje de éxito NO se toma de $data['success']['message'] —
            // ese texto lo devuelve la API externa de OEC (fuera de este
            // repo) y quedó con "usted"/redacción vieja. Usamos siempre
            // nuestro propio texto (mismo tuteo/tono que enviar_email_propio()
            // más arriba), así el copy de cara al usuario queda bajo nuestro
            // control sin depender de lo que responda un sistema externo.
            if (isset($data['success'])) {
                wp_send_json_success('¡Listo! Ya recibimos tu consulta — te vamos a contactar a la brevedad.');
            }

            wp_send_json_error('Error en la comunicación con la API.');
        }

        /**
         * El sistema de créditos solo existe en nuestras comunidades propias
         * (ver OEC_CREDITS_ALLOWED_DOMAINS en oec-main.php) — en un sitio de
         * socio queda completamente inactivo.
         */
        private function credits_enabled() {
            return function_exists('oec_credits_system_enabled') && oec_credits_system_enabled();
        }

        /**
         * Llamada genérica a la API de créditos/cupones (siempre server-side, la
         * API key nunca se expone al navegador). La key solo se usa si el
         * dominio actual está habilitado (credits_enabled()) — en cualquier
         * otro sitio esto ni se llega a leer.
         */
        private function credits_api_request($method, $url, $body = null) {
            $api_key = $this->credits_enabled() ? oec_credits_api_key_value() : '';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $api_key, 'Content-Type: application/json']);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            $response = curl_exec($ch);
            curl_close($ch);

            return json_decode($response, true);
        }

        /**
         * Trae los cupones vigentes (no vencidos) de una edición, ordenados de
         * mayor a menor porcentaje. No incluye el código de descuento — eso solo
         * se revela al confirmar el canje por email.
         */
        private function get_valid_coupons($edition_uid) {
            $data = $this->credits_api_request('GET', 'https://api.onlineeducation.center/contable/api/coupons?edition_uid=' . urlencode($edition_uid));
            if (empty($data) || !is_array($data)) return [];

            $now = new DateTime('now', new DateTimeZone('UTC'));
            $valid = array_filter($data, function ($coupon) use ($now) {
                $exp = DateTime::createFromFormat('Y-m-d', $coupon['expiration'] ?? '', new DateTimeZone('UTC'));
                return $exp && $exp >= $now;
            });

            usort($valid, function ($a, $b) { return $b['percentage'] - $a['percentage']; });
            return array_values($valid);
        }

        /**
         * Balance de créditos de un email.
         */
        public function oec_check_credits_ajax() {
            $this->check_nonce();
            if (!$this->credits_enabled()) wp_send_json_error('Este sistema no está disponible en este sitio.');

            $email = sanitize_email($_POST['email'] ?? '');
            if (!is_email($email)) wp_send_json_error('Email inválido.');

            $data = $this->credits_api_request('GET', 'https://api.onlineeducation.center/contable/api/points/' . urlencode($email));
            if (!isset($data['balance'])) wp_send_json_error('No se pudo verificar el balance.');

            wp_send_json_success(['balance' => intval($data['balance'])]);
        }

        /**
         * Lista de descuentos disponibles (porcentaje + créditos que cuesta cada uno).
         */
        public function oec_list_coupons_ajax() {
            $this->check_nonce();
            if (!$this->credits_enabled()) wp_send_json_error('Este sistema no está disponible en este sitio.');

            $edition_uid = sanitize_text_field($_POST['edition_uid'] ?? '');
            if (!$edition_uid) wp_send_json_error('Falta edition_uid.');

            $coupons = $this->get_valid_coupons($edition_uid);
            $out = array_map(function ($c) {
                return ['percentage' => intval($c['percentage']), 'points_value' => intval($c['points_value'])];
            }, $coupons);

            wp_send_json_success($out);
        }

        /**
         * Paso 1 del canje: valida balance y el cupón pedido contra la lista real
         * (nunca confía ciegamente en lo que mande el navegador), genera un token
         * de un solo uso (expira en 30 min) y manda el email de confirmación.
         */
        public function oec_request_redeem_ajax() {
            $this->check_nonce();
            if (!$this->credits_enabled()) wp_send_json_error('Este sistema no está disponible en este sitio.');

            $email         = sanitize_email($_POST['email'] ?? '');
            $training_uid  = sanitize_text_field($_POST['training_uid'] ?? '');
            $edition_uid   = sanitize_text_field($_POST['edition_uid'] ?? '');
            $points_amount = intval($_POST['points_amount'] ?? 0);
            $training_name = sanitize_text_field($_POST['training_name'] ?? '');

            if (!is_email($email) || !$training_uid || !$edition_uid || $points_amount <= 0) {
                wp_send_json_error('Datos incompletos.');
            }

            // Este paso manda un email real al destinatario — sin este freno,
            // alguien podría usarlo para bombardear de emails a otra persona con
            // solo conocer su dirección.
            if (!$this->throttle('redeem', $email, 2 * MINUTE_IN_SECONDS)) {
                wp_send_json_error('Ya te enviamos un email hace un momento. Revisa tu bandeja (y spam) antes de pedir otro.');
            }

            // Balance
            $balanceData = $this->credits_api_request('GET', 'https://api.onlineeducation.center/contable/api/points/' . urlencode($email));
            $balance = intval($balanceData['balance'] ?? 0);
            if ($balance < $points_amount) wp_send_json_error('No tienes créditos suficientes para este descuento.');

            // El cupón pedido tiene que existir de verdad en la lista vigente — no
            // confiamos en el "discpercent" que mande el navegador.
            $coupons = $this->get_valid_coupons($edition_uid);
            $match = null;
            foreach ($coupons as $c) {
                if (intval($c['points_value']) === $points_amount) { $match = $c; break; }
            }
            if (!$match) wp_send_json_error('Ese descuento ya no está disponible.');

            $token = wp_generate_password(32, false);
            set_transient('oec_redeem_' . $token, [
                'email'          => $email,
                'training_uid'   => $training_uid,
                'edition_uid'    => $edition_uid,
                'points_amount'  => $points_amount,
                'disc_percent'   => intval($match['percentage']),
                'training_name'  => $training_name,
            ], 30 * MINUTE_IN_SECONDS);

            // La página con [oec-confirm-redeem] se crea sola (ver
            // OEC_Admin::oec_ensure_redeem_confirm_page) — nada que configurar acá.
            $confirm_slug = defined('OEC_REDEEM_CONFIRM_SLUG') ? OEC_REDEEM_CONFIRM_SLUG : 'confirmacion-de-canje-de-creditos';
            $confirm_page = get_page_by_path($confirm_slug, OBJECT, 'page');
            $confirm_base = $confirm_page ? get_permalink($confirm_page) : home_url('/');
            $confirm_url  = add_query_arg('oec_redeem_token', $token, $confirm_base);

            $body  = '<p>Se solicitó canjear ' . $points_amount . ' de tus créditos para obtener un ' . intval($match['percentage']) . '% de descuento en <b>' . esc_html($training_name) . '</b>.</p>';
            $body .= '<p>Si fuiste tú, confirma el canje:</p>';
            $body .= '<p><a href="' . esc_url($confirm_url) . '" style="display:inline-block;background:#2271b1;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:700;">CONFIRMAR CANJE</a></p>';
            $body .= '<p>Si no fuiste tú, ignora este email — no se va a descontar nada de tu balance.</p>';
            $body .= '<p>Este enlace es de un solo uso y expira en 30 minutos.</p>';

            $sent = wp_mail($email, 'Confirmación de canje de créditos por descuento', $body, ['Content-Type: text/html; charset=UTF-8']);
            if (!$sent) wp_send_json_error('No se pudo enviar el email de confirmación.');

            wp_send_json_success('Te enviamos un email a ' . esc_html($email) . ' para confirmar el canje.');
        }

        /**
         * CSS de la tarjeta de confirmación de canje — con estilo propio (esta
         * página vive suelta dentro del tema de WordPress, no dentro del Twig
         * de [oec-content], así que no puede depender de su CSS).
         */
        private function credits_page_style() {
            $color = get_option('oec_brand_color', '#a435f0');
            return "<style>
                .oecrd-wrap{max-width:520px;margin:60px auto;padding:0 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;box-sizing:border-box;}
                .oecrd-wrap *{box-sizing:border-box;}
                .oecrd-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;box-shadow:0 4px 16px rgba(0,0,0,.06);}
                .oecrd-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin-bottom:18px;}
                .oecrd-icon.is-ok{background:#ecfdf3;color:#12b76a;}
                .oecrd-icon.is-error{background:#fef3f2;color:#f04438;}
                .oecrd-icon svg{width:28px;height:28px;}
                .oecrd-title{font-size:20px;font-weight:800;color:#111827;margin:0 0 10px;}
                .oecrd-text{font-size:15px;color:#374151;line-height:1.6;margin:0 0 14px;}
                .oecrd-text:last-child{margin-bottom:0;}
                .oecrd-text a{color:{$color};font-weight:700;text-decoration:none;}
                .oecrd-text a:hover{text-decoration:underline;}
                .oecrd-code-box{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin:16px 0;}
                .oecrd-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:19px;font-weight:700;color:#111827;letter-spacing:.5px;word-break:break-all;}
                .oecrd-copy-btn{display:flex;align-items:center;gap:6px;background:{$color};color:#fff;border:none;border-radius:8px;padding:9px 14px;font-size:13px;font-weight:700;cursor:pointer;flex-shrink:0;white-space:nowrap;}
                .oecrd-copy-btn:hover{opacity:.88;}
                .oecrd-copy-btn svg{width:14px;height:14px;flex-shrink:0;}
                .oecrd-howto{background:#f9fafb;border-radius:10px;padding:14px 16px;font-size:13.5px;color:#4b5563;line-height:1.6;margin-top:18px;}
                .oecrd-howto b{color:#111827;}
                .oecrd-balance{font-size:13.5px;color:#6b7280;margin-top:14px;}
            </style>";
        }

        /**
         * Envuelve el contenido de la página de confirmación en una tarjeta con
         * el estilo de marca (ícono de check/error, título, cuerpo).
         */
        private function credits_page_html($status, $title, $body_html) {
            $icon = $status === 'ok'
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg>';
            $icon_class = $status === 'ok' ? 'is-ok' : 'is-error';

            return $this->credits_page_style() . '
            <div class="oecrd-wrap">
                <div class="oecrd-card">
                    <div class="oecrd-icon ' . $icon_class . '">' . $icon . '</div>
                    <h2 class="oecrd-title">' . esc_html($title) . '</h2>
                    ' . $body_html . '
                </div>
            </div>';
        }

        /**
         * Paso 2 del canje (shortcode para la página donde cae el link del email):
         * valida el token, resta los créditos de verdad, y manda el código real
         * por un segundo email. El token se borra apenas se usa (un solo uso).
         */
        public function oec_confirm_redeem_shortcode($atts) {
            if (!$this->credits_enabled()) {
                return $this->credits_page_html('error', 'No disponible', '<p class="oecrd-text">Este sistema no está disponible en este sitio.</p>');
            }

            $token = sanitize_text_field($_GET['oec_redeem_token'] ?? '');
            if (!$token) {
                return $this->credits_page_html('error', 'Falta el código', '<p class="oecrd-text">No se proporcionó ningún código de confirmación.</p>');
            }

            $data = get_transient('oec_redeem_' . $token);
            if (!$data) {
                return $this->credits_page_html('error', 'Enlace no válido', '<p class="oecrd-text">Puede que ya se haya usado, o que hayan pasado más de 30 minutos desde que lo pediste. Vuelve a solicitar el canje desde la página de la formación.</p>');
            }

            // Un solo uso: lo borramos ya mismo, antes de procesar nada más.
            delete_transient('oec_redeem_' . $token);

            $email         = $data['email'];
            $training_uid  = $data['training_uid'];
            $edition_uid   = $data['edition_uid'];
            $points_amount = $data['points_amount'];
            $disc_percent  = $data['disc_percent'];
            $training_name = $data['training_name'];

            // Re-chequeo de balance por si cambió entre el paso 1 y este clic
            $balanceData = $this->credits_api_request('GET', 'https://api.onlineeducation.center/contable/api/points/' . urlencode($email));
            $balance = intval($balanceData['balance'] ?? 0);
            if ($balance < $points_amount) {
                return $this->credits_page_html('error', 'Créditos insuficientes', '<p class="oecrd-text">Ya no tienes créditos suficientes para este descuento.</p>');
            }

            // Buscar el cupón vigente que corresponde, para obtener el código real
            $coupons = $this->get_valid_coupons($edition_uid);
            $match = null;
            foreach ($coupons as $c) {
                if (intval($c['points_value']) === $points_amount) { $match = $c; break; }
            }
            if (!$match || empty($match['code'])) {
                return $this->credits_page_html('error', 'Descuento no disponible', '<p class="oecrd-text">No encontramos el descuento seleccionado. Puede que ya no esté disponible.</p>');
            }

            // Restar los créditos
            $this->credits_api_request('POST', 'https://api.onlineeducation.center/contable/api/points', [
                'email'        => $email,
                'amount'       => -$points_amount,
                'reference'    => 'Compra de descuento',
                'training_uid' => $training_uid,
                'edition_uid'  => $edition_uid,
            ]);
            $remaining = $balance - $points_amount;

            // Link directo a la matriculación (mismo criterio que usa el Twig
            // de la formación): si estamos viendo la formación en el sitio de
            // su propia comunidad, el checkout es "register_url"; si no,
            // "register". Si por lo que sea la API no responde, volvemos a la
            // ficha de la formación como respaldo — desde ahí el botón de
            // inscripción real igual funciona.
            $training_url = home_url('/formacion/?id=' . $training_uid);
            $training_data = class_exists('OEC_Api') ? OEC_Api::call('trainings/' . $training_uid) : null;
            if (!empty($training_data['community'])) {
                $in_community = function_exists('oec_is_community_site') && oec_is_community_site($training_data['community']);
                $register_link = $in_community ? ($training_data['register_url'] ?? '') : ($training_data['register'] ?? '');
                if ($register_link) $training_url = $register_link;
            }

            // Email con el código real
            $body  = '<p>¡Gracias por canjear tus créditos!</p>';
            $body .= '<p>El código de descuento del ' . esc_html($disc_percent) . '% para <b>' . esc_html($training_name) . '</b> es:</p>';
            $body .= '<p style="background:#f3f4f6;padding:16px;border-radius:6px;font-weight:700;font-size:18px;">' . esc_html($match['code']) . '</p>';
            $body .= '<p>Para usarlo: inicia tu inscripción y, en el resumen de compra, pégalo en el campo "Código de descuento" y presiona "Aplicar".</p>';
            $body .= '<p><a href="' . esc_url($training_url) . '">Iniciar inscripción</a></p>';
            wp_mail($email, 'Tu código de descuento para ' . $training_name, $body, ['Content-Type: text/html; charset=UTF-8']);

            $code = esc_html($match['code']);
            $body_html = '
                <p class="oecrd-text">El código del descuento del <b>' . esc_html($disc_percent) . '%</b> para <b>' . esc_html($training_name) . '</b> es:</p>
                <div class="oecrd-code-box">
                    <span class="oecrd-code" id="oecrd-code-value">' . $code . '</span>
                    <button type="button" class="oecrd-copy-btn" id="oecrd-copy-btn" data-code="' . $code . '">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                        <span class="oecrd-copy-label">Copiar</span>
                    </button>
                </div>
                <div class="oecrd-howto">
                    <b>¿Cómo lo uso?</b><br>
                    <a href="' . esc_url($training_url) . '">Inicia la inscripción</a> y, en el resumen de compra, pega el código en el campo <b>"Código de descuento"</b> y presiona <b>"Aplicar"</b>.
                </div>
                <p class="oecrd-balance">Ahora te quedan <b>' . number_format($remaining, 0, ',', '.') . '</b> créditos. También te enviamos esta información a ' . esc_html($email) . '.</p>
                <script>
                (function(){
                    var btn = document.getElementById("oecrd-copy-btn");
                    if (!btn) return;
                    var label = btn.querySelector(".oecrd-copy-label");
                    var original = label.textContent;

                    function flash(text){
                        label.textContent = text;
                        setTimeout(function(){ label.textContent = original; }, 1500);
                    }

                    // navigator.clipboard solo existe en contexto seguro (HTTPS,
                    // o el hostname exacto "localhost") — en un sitio de prueba
                    // por http:// (como oec-test.local) es undefined. Fallback
                    // con execCommand para que el botón funcione igual.
                    function fallbackCopy(text){
                        var ta = document.createElement("textarea");
                        ta.value = text;
                        ta.style.position = "fixed";
                        ta.style.opacity = "0";
                        document.body.appendChild(ta);
                        ta.focus();
                        ta.select();
                        var ok = false;
                        try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
                        document.body.removeChild(ta);
                        return ok;
                    }

                    btn.addEventListener("click", function(){
                        var code = btn.dataset.code;
                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(code).then(
                                function(){ flash("¡Copiado!"); },
                                function(){ flash(fallbackCopy(code) ? "¡Copiado!" : "No se pudo copiar"); }
                            );
                        } else {
                            flash(fallbackCopy(code) ? "¡Copiado!" : "No se pudo copiar");
                        }
                    });
                })();
                </script>';

            return $this->credits_page_html('ok', 'Muchas gracias por canjear créditos por descuentos', $body_html);
        }
    }
}
