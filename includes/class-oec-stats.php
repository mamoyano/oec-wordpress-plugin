<?php
/**
 * wp-admin → Estadísticas: embudo de Zoho por formación y contactos por etapa.
 *
 * Los números y los contactos vienen de Zoho a través de
 * onlineeducation.center/connections/zoho/partner-contacts.php (ver
 * server/zoho/ en este repo). Las claves de Zoho viven SOLO en ese servidor:
 * acá se manda el token de la API de OEC del socio, y el endpoint responde
 * únicamente por las formaciones de su organización. Todo pasa por el
 * servidor de WordPress (AJAX / admin-post), el token nunca llega al navegador.
 */
if (!class_exists('OEC_Stats')) {
    class OEC_Stats {
        const COUNTS_TTL = 900; // 15 min, igual que el endpoint

        /** Etapas de Zoho, en orden del embudo. */
        const STAGES = [
            'Cold Prospects'      => 'Prospectos fríos',
            'More Info Request'   => 'Pidieron información',
            'Hot Prospects'       => 'Prospectos calientes',
            'Orders Not Finished' => 'Compra sin terminar',
            'Students - Won'      => 'Alumnos',
            // Fuera del embudo: descartados por ventas de OEC (Lost + Cancelled, lo suma el
            // endpoint), que el socio puede retomar.
            'Lost'                => 'Perdidos',
        ];

        public static function init() {
            add_action('wp_ajax_oec_stats_counts', [__CLASS__, 'ajax_counts']);
            add_action('wp_ajax_oec_stats_contacts', [__CLASS__, 'ajax_contacts']);
            add_action('admin_post_oec_stats_csv', [__CLASS__, 'download_csv']);
        }

        private static function endpoint() {
            $url = defined('OEC_ZOHO_CONTACTS_ENDPOINT') ? OEC_ZOHO_CONTACTS_ENDPOINT : 'https://onlineeducation.center/connections/zoho/partner-contacts.php';
            return apply_filters('oec_zoho_contacts_endpoint', $url);
        }

        private static function valid_uid($uid) {
            return is_string($uid) && preg_match('/^t-[A-Za-z0-9]{14}$/', $uid);
        }

        private static function valid_edition($edition) {
            return is_string($edition) && preg_match('/^te-[A-Za-z0-9]{14}$/', $edition);
        }

        /**
         * Edición actual de una formación en Zoho (Edition_UID = edition_uid de la API
         * de OEC). El listado de la API no la trae: sale del detalle, el mismo que usa
         * la ficha (OEC_Api::call, caché 24 h). '' si no se puede saber → todas las ediciones.
         */
        private static function edition_uid($uid) {
            $detail = class_exists('OEC_Api') ? OEC_Api::call('trainings/' . $uid) : [];
            $ed     = $detail['edition_uid'] ?? '';
            return self::valid_edition($ed) ? $ed : '';
        }

        /** Pedido al endpoint de Zoho. Devuelve [código HTTP, cuerpo decodificado]. */
        private static function remote($action, $uid, $edition = '', $stage = '') {
            $args = ['action' => $action, 'training_uid' => $uid];
            if ($edition !== '') $args['edition_uid'] = $edition;
            if ($stage !== '') $args['stage'] = $stage;
            $response = wp_remote_get(add_query_arg(array_map('rawurlencode', $args), self::endpoint()), [
                'headers' => ['X-API-TOKEN' => (string) get_option('oec_token'), 'Accept' => 'application/json'],
                // La primera consulta de un token revisa sus formaciones en la API de OEC
                // (con el token maestro, ~20 s); después sale del caché del endpoint.
                'timeout' => 45,
            ]);
            if (is_wp_error($response)) return [0, null];
            return [(int) wp_remote_retrieve_response_code($response), json_decode(wp_remote_retrieve_body($response), true)];
        }

        private static function error_text($code) {
            if ($code === 401) return 'El token de la API no es válido. Revísalo en Configuración.';
            if ($code === 403) return 'Esta formación no pertenece a tu organización.';
            return 'No se pudieron cargar los datos de Zoho. Prueba de nuevo en unos minutos.';
        }

        private static function require_admin() {
            if (!current_user_can('manage_options')) wp_die('Sin permiso', 403);
        }

        // === PÁGINA =========================================================

        public static function render_page() {
            $token = get_option('oec_token');
            if (!$token) {
                echo '<div class="wrap"><h1>Estadísticas</h1><p>Configura tu API Token en la pestaña de Configuración.</p></div>';
                return;
            }

            if (isset($_POST['oec_stats_refresh']) && check_admin_referer('oec_stats_refresh_action', 'oec_stats_refresh_nonce')) {
                self::clear_cache();
            }

            $page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
            $all    = isset($_GET['ver']) && $_GET['ver'] === 'todas';
            $result = self::get_trainings($token, $page, $search, $all);

            $brand = get_option('oec_brand_color', '#a435f0') ?: '#a435f0';
            $query = array_filter(['page' => 'oec-stats', 's' => $search, 'ver' => $all ? 'todas' : '']);
            $base  = add_query_arg(array_map('rawurlencode', $query), admin_url('admin.php'));
            ?>
            <style>
                .oec-st { --oec-st-brand: <?php echo esc_html($brand); ?>; max-width: 1400px; }
                .oec-st-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin: 16px 0 6px; }
                .oec-st-head h1 { margin: 0; padding: 0; }
                .oec-st-sub { color: #646970; margin: 0 0 16px; }
                .oec-st-tools, .oec-st-tools form { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0; }
                .oec-st-tools input[type=search] { min-width: 220px; }
                .oec-st-list { display: flex; flex-direction: column; gap: 12px; }
                .oec-st-row { display: grid; grid-template-columns: minmax(260px, 1.1fr) minmax(380px, 2fr) 190px; gap: 20px; align-items: center; background: #fff; border: 1px solid #dcdcde; border-radius: 10px; padding: 16px 18px; }
                .oec-st-info { display: flex; gap: 12px; align-items: center; min-width: 0; }
                .oec-st-img { width: 56px; height: 56px; border-radius: 8px; object-fit: cover; flex: 0 0 56px; background: #f0f0f1; }
                .oec-st-title { font-size: 14px; font-weight: 600; line-height: 1.3; margin: 0 0 6px; color: #1d2327; }
                .oec-st-title a { color: inherit; text-decoration: none; }
                .oec-st-title a:hover { color: var(--oec-st-brand); }
                .oec-st-chips { display: flex; flex-wrap: wrap; gap: 6px; }
                .oec-st-chip { font-size: 11px; line-height: 1; padding: 4px 8px; border-radius: 20px; background: #f0f0f1; color: #50575e; white-space: nowrap; }
                .oec-st-chip--urgent { background: #fcf0f1; color: #b32d2e; font-weight: 600; }
                .oec-st-chip--soon { background: #fcf9e8; color: #8a6d00; }
                .oec-st-funnel { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); border: 1px solid #f0f0f1; border-radius: 8px; overflow: hidden; }
                .oec-st-step { padding: 10px 6px; text-align: center; border: 0; border-left: 1px solid #f0f0f1; background: transparent; color: #1d2327; font: inherit; display: block; width: 100%; }
                .oec-st-step:first-child { border-left: 0; }
                button.oec-st-step { cursor: pointer; }
                button.oec-st-step:hover, button.oec-st-step:focus-visible { background: #f6f7f7; outline: none; box-shadow: inset 0 -2px 0 var(--oec-st-brand); }
                button.oec-st-step .oec-st-lbl::after { content: " ›"; }
                .oec-st-num { display: block; font-size: 18px; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; }
                .oec-st-lbl { display: block; font-size: 10.5px; line-height: 1.25; color: #646970; margin-top: 2px; }
                .oec-st-step.is-zero .oec-st-num { color: #a7aaad; }
                .oec-st-step--hot { background: #fff7ed; }
                .oec-st-step--hot .oec-st-num { color: #c2410c; }
                button.oec-st-step--hot:hover { background: #ffedd5; }
                .oec-st-step--final { background: color-mix(in srgb, var(--oec-st-brand) 8%, #fff); }
                .oec-st-step--final .oec-st-num { color: var(--oec-st-brand); }
                .oec-st-step--lost { border-left: 2px dashed #dcdcde; background: #f9f9f9; }
                .oec-st-step--lost .oec-st-num { color: #50575e; }
                .oec-st-goal-top { display: flex; justify-content: space-between; align-items: baseline; font-size: 12px; color: #50575e; margin-bottom: 6px; }
                .oec-st-goal-top strong { font-size: 16px; color: #1d2327; }
                .oec-st-bar { height: 8px; border-radius: 8px; background: #f0f0f1; overflow: hidden; }
                .oec-st-bar span { display: block; height: 100%; border-radius: 8px; background: var(--oec-st-brand); }
                .oec-st-goal-note { font-size: 11px; color: #646970; margin-top: 6px; }
                .oec-st-missing { font-size: 12px; color: #646970; font-style: italic; }
                .oec-st-pager { display: flex; justify-content: center; align-items: center; gap: 14px; margin: 24px 0 40px; color: #646970; }
                .oec-st-metrics { display: contents; }
                .oec-st-skel .oec-st-num, .oec-st-skel .oec-st-lbl { background: #f0f0f1; border-radius: 4px; margin: 2px auto; width: 60%; animation: oec-st-pulse 1.2s ease-in-out infinite; }
                @keyframes oec-st-pulse { 50% { opacity: .45; } }

                /* Listado de contactos */
                .oec-st-dlg { width: min(1240px, calc(100vw - 32px)); max-height: calc(100vh - 64px); padding: 0; border: 0; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
                .oec-st-dlg::backdrop { background: rgba(0,0,0,.45); }
                .oec-st-dlg-in { display: flex; flex-direction: column; max-height: calc(100vh - 64px); }
                .oec-st-dlg-head { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-start; justify-content: space-between; padding: 18px 20px; border-bottom: 1px solid #f0f0f1; }
                .oec-st-dlg-head h2 { margin: 0 0 4px; font-size: 17px; }
                .oec-st-dlg-head p { margin: 0; color: #646970; font-size: 12px; }
                .oec-st-dlg-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
                .oec-st-dlg-body { overflow: auto; padding: 0 20px; }
                .oec-st-dlg-foot { padding: 10px 20px; border-top: 1px solid #f0f0f1; color: #646970; font-size: 11.5px; }
                .oec-st-table { width: 100%; border-collapse: collapse; font-size: 13px; }
                .oec-st-table th { position: sticky; top: 0; background: #fff; text-align: left; font-weight: 600; color: #50575e; font-size: 11.5px; padding: 12px 8px 8px; border-bottom: 1px solid #dcdcde; }
                .oec-st-table td { padding: 9px 8px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
                .oec-st-table a { text-decoration: none; }
                .oec-st-table .oec-st-wa { color: #1a8d48; }
                .oec-st-table .oec-st-dim { color: #a7aaad; }
                .oec-st-dlg-msg { padding: 40px 0; text-align: center; color: #646970; }
                .oec-st-table .oec-st-col-chk { width: 28px; padding-right: 0; }
                .oec-st-mailtag { display: inline-block; font-size: 11px; line-height: 1; padding: 4px 7px; border-radius: 20px; background: #f0f0f1; color: #50575e; white-space: nowrap; }
                .oec-st-mailtag--sent { background: #edfaef; color: #1a7a33; }
                .oec-st-mailtag--unsub { background: #fcf0f1; color: #b32d2e; }
                .oec-st-selbar { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; justify-content: space-between; padding: 10px 20px; background: #f6f7f7; border-bottom: 1px solid #f0f0f1; font-size: 12.5px; color: #50575e; }
                .oec-st-selbar[hidden], .oec-st-wapanel[hidden] { display: none; }
                .oec-st-selbar select { min-height: 28px; font-size: 12.5px; }
                .oec-st-selbar .button-small .dashicons { font-size: 16px; width: 16px; height: 16px; line-height: 16px; vertical-align: -3px; color: #1a8d48; }
                .oec-st-wapanel { padding: 12px 20px; background: #f6fff9; border-bottom: 1px solid #d5f0de; }
                .oec-st-wapanel label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 5px; }
                .oec-st-wapanel textarea { width: 100%; font-size: 13px; }
                .oec-st-wapanel p { margin: 6px 0 0; }
                .oec-st-name { font-weight: 500; }
                .oec-st-noteline { font-size: 11.5px; color: #646970; margin-top: 2px; max-width: 260px; }
                .oec-st-noteline button { border: 0; background: none; padding: 0; color: #2271b1; cursor: pointer; font-size: 11.5px; }
                .oec-st-noteline button:hover { text-decoration: underline; }
                .oec-st-noteline input { width: 100%; font-size: 12px; min-height: 26px; padding: 0 6px; }
                .oec-st-phone { white-space: nowrap; }
                .oec-st-acts { display: inline-flex; gap: 4px; margin-left: 8px; vertical-align: middle; }
                .oec-st-act { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; padding: 0; border-radius: 50%; border: 1px solid #dcdcde; background: #fff; color: #50575e; cursor: pointer; text-decoration: none; }
                .oec-st-act .dashicons { font-size: 15px; width: 15px; height: 15px; line-height: 15px; }
                .oec-st-act:hover, .oec-st-act:focus-visible { background: #50575e; border-color: #50575e; color: #fff; outline: none; }
                .oec-st-act--wa { color: #1a8d48; border-color: #b8e6c9; }
                .oec-st-act--wa:hover, .oec-st-act--wa:focus-visible { background: #25d366; border-color: #25d366; }
                .oec-st-act--call { color: #2271b1; border-color: #c5d9ed; }
                .oec-st-act--call:hover, .oec-st-act--call:focus-visible { background: #2271b1; border-color: #2271b1; }
                .oec-st-last { white-space: nowrap; font-size: 12px; color: #1d2327; }
                .oec-st-last .dashicons { font-size: 14px; width: 14px; height: 14px; line-height: 14px; vertical-align: -2px; margin-right: 4px; color: #646970; }
                .oec-st-table td .oec-st-mailtag { margin: 0 4px 3px 0; }
                .oec-st-status { font-size: 12px; min-height: 28px; line-height: 1.2; border-radius: 20px; padding: 0 26px 0 10px; border-color: #dcdcde; max-width: 150px; }
                .oec-st-status[data-st="contactado"] { background-color: #eef4fb; border-color: #c5d9ed; }
                .oec-st-status[data-st="no_responde"] { background-color: #fcf9e8; border-color: #f0d98a; }
                .oec-st-status[data-st="interesado"] { background-color: #edfaef; border-color: #a7dfb5; }
                .oec-st-status[data-st="inscripto"] { background-color: color-mix(in srgb, var(--oec-st-brand) 12%, #fff); border-color: var(--oec-st-brand); }
                .oec-st-status[data-st="no_interesa"] { background-color: #f6f7f7; color: #646970; }
                .oec-st-selbar label { display: inline-flex; gap: 6px; align-items: center; }

                /* Redactar email */
                .oec-st-compose { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; padding: 18px 0 20px; }
                .oec-st-field { margin: 0 0 14px; }
                .oec-st-field > label { display: block; font-weight: 600; font-size: 12px; color: #1d2327; margin-bottom: 5px; }
                .oec-st-field input[type=text], .oec-st-field input[type=email], .oec-st-field textarea, .oec-st-field select { width: 100%; max-width: none; }
                .oec-st-field textarea { min-height: 230px; font-size: 13.5px; line-height: 1.5; }
                .oec-st-row2 { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
                .oec-st-row2 select { flex: 1 1 220px; }
                .oec-st-vars { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 8px; }
                .oec-st-vars button { font-size: 11.5px; line-height: 1; padding: 5px 8px; border-radius: 20px; border: 1px dashed #c3c4c7; background: #fff; color: #2271b1; cursor: pointer; font-family: Menlo, Consolas, monospace; }
                .oec-st-vars button:hover { border-style: solid; border-color: #2271b1; }
                .oec-st-hint { font-size: 11.5px; color: #646970; margin: 4px 0 0; }
                .oec-st-from { display: flex; gap: 8px; align-items: flex-start; padding: 10px 12px; border-radius: 8px; font-size: 12.5px; line-height: 1.45; margin-bottom: 14px; }
                .oec-st-from .dashicons { flex: 0 0 auto; margin-top: 1px; }
                .oec-st-from--ok { background: #edfaef; color: #1a3d1f; }
                .oec-st-from--warn { background: #fcf9e8; color: #6b4c00; }
                .oec-st-from--info { background: #f0f6fc; color: #1d2327; }
                .oec-st-from--ok { background: #edfaef; color: #1a3d1f; }
                .oec-st-compose-actions .oec-st-note { margin-right: auto; font-size: 12px; color: #646970; }
                .oec-st-progress .oec-st-err { color: #b32d2e; }
                .oec-st-mailtag--queued { background: #fcf9e8; color: #8a6d00; }
                .oec-st-from--info .dashicons { color: #2271b1; }
                .oec-st-serial h3 { margin: 18px 0 2px; }
                .oec-st-serial .button-hero { display: inline-flex; align-items: center; gap: 6px; margin: 14px 0 6px; white-space: normal; text-align: center; }
                .oec-st-serial .button-hero .dashicons { font-size: 20px; width: 20px; height: 20px; line-height: 20px; }
                .oec-st-serial .button-link { display: block; margin: 0 auto 8px; }
                .oec-st-prev { border: 1px solid #dcdcde; border-radius: 10px; background: #fff; display: flex; flex-direction: column; min-height: 100%; }
                .oec-st-prev-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 10px 14px; border-bottom: 1px solid #f0f0f1; font-size: 12px; color: #646970; }
                .oec-st-prev-head button { padding: 0 6px; min-height: 26px; line-height: 24px; }
                .oec-st-prev-subj { padding: 12px 16px 0; font-weight: 600; font-size: 15px; color: #1d2327; }
                .oec-st-prev-body { padding: 4px 16px 16px; font-size: 14px; line-height: 1.55; color: #1f2937; overflow-wrap: anywhere; }
                .oec-st-prev-body p { margin: 10px 0; font-size: 14px; }
                .oec-st-compose-actions { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: flex-end; border-top: 1px solid #f0f0f1; padding-top: 14px; }
                .oec-st-flash { font-size: 12.5px; }
                .oec-st-flash.is-ok { color: #1a7a33; }
                .oec-st-flash.is-err { color: #b32d2e; }
                .oec-st-progress { padding: 36px 10px 40px; text-align: center; max-width: 520px; margin: 0 auto; }
                .oec-st-progress h3 { margin: 0 0 14px; font-size: 18px; }
                .oec-st-progress .oec-st-bar { height: 10px; margin: 0 0 12px; }
                .oec-st-progress p { color: #50575e; margin: 6px 0; }
                @media (max-width: 820px) { .oec-st-compose { grid-template-columns: 1fr; } }
                /* Celular: cada persona es una tarjeta (los botones de llamar/WhatsApp, a la vista). */
                @media (max-width: 700px) {
                    .oec-st-table, .oec-st-table tbody, .oec-st-table tr, .oec-st-table td { display: block; }
                    .oec-st-table thead { display: none; }
                    .oec-st-table tr { position: relative; padding: 12px 0 12px 32px; border-bottom: 1px solid #f0f0f1; }
                    .oec-st-table td { border: 0; padding: 2px 0; }
                    .oec-st-table td.oec-st-col-chk { position: absolute; left: 0; top: 13px; width: auto; }
                    .oec-st-table td.oec-st-col-opt { display: none; }
                    .oec-st-acts { gap: 8px; }
                    .oec-st-act { width: 34px; height: 34px; }
                    .oec-st-act .dashicons { font-size: 18px; width: 18px; height: 18px; line-height: 18px; }
                    .oec-st-status { margin-top: 4px; }
                    .oec-st-noteline { max-width: none; }
                }

                @media (max-width: 1100px) { .oec-st-row { grid-template-columns: 1fr; gap: 14px; } }
                @media (max-width: 600px) {
                    .oec-st-funnel { grid-template-columns: repeat(3, minmax(0, 1fr)); }
                    .oec-st-step:nth-child(4) { border-left: 0; }
                    .oec-st-step:nth-child(n+4) { border-top: 1px solid #f0f0f1; }
                    .oec-st-tools input[type=search] { min-width: 0; flex: 1; }
                    .oec-st-table .oec-st-col-opt { display: none; }
                }
                @media (prefers-reduced-motion: reduce) { .oec-st-skel .oec-st-num, .oec-st-skel .oec-st-lbl { animation: none; } }
            </style>

            <div class="wrap oec-st">
                <div class="oec-st-head">
                    <h1>Estadísticas de conversión</h1>
                    <div class="oec-st-tools">
                        <form method="get">
                            <input type="hidden" name="page" value="oec-stats">
                            <select name="ver" onchange="this.form.submit()">
                                <option value="">Inscripción abierta</option>
                                <option value="todas" <?php selected($all); ?>>Todas las formaciones</option>
                            </select>
                            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar formación…">
                            <button type="submit" class="button">Buscar</button>
                            <?php if ($search !== '') : ?>
                                <a class="button button-link" href="<?php echo esc_url(remove_query_arg(['s', 'paged'], $base)); ?>">Limpiar</a>
                            <?php endif; ?>
                        </form>
                        <form method="post">
                            <?php wp_nonce_field('oec_stats_refresh_action', 'oec_stats_refresh_nonce'); ?>
                            <button type="submit" name="oec_stats_refresh" value="1" class="button" title="Los números se guardan 15 minutos">
                                <span class="dashicons dashicons-update" style="font-size:16px; margin-top:4px;"></span> Actualizar datos
                            </button>
                        </form>
                    </div>
                </div>
                <p class="oec-st-sub">
                    <?php if ($result['total'] > 0) :
                        echo esc_html(number_format_i18n($result['total'])) . ($all ? ' formaciones' : ' formaciones con inscripción abierta');
                        echo $search !== '' ? ' que coinciden con «' . esc_html($search) . '»' : '';
                        echo '. Toca un número para ver los contactos de esa etapa.';
                    endif; ?>
                </p>

                <?php if ($result['error']) : ?>
                    <div class="notice notice-error inline"><p>No se pudo consultar la API de OEC. Prueba de nuevo en unos minutos.</p></div>
                <?php elseif (empty($result['rows'])) : ?>
                    <div class="notice notice-info inline"><p>No hay formaciones<?php echo $all ? '' : ' con inscripción abierta'; ?><?php echo $search !== '' ? ' que coincidan con la búsqueda' : ''; ?>.</p></div>
                <?php else : ?>
                    <div class="oec-st-list">
                        <?php foreach ($result['rows'] as $t) :
                            $counts = self::cached_counts($t['id']);
                            $close  = self::days_until($t['enrollment_end'] ?? '');
                            $start  = self::days_until($t['start'] ?? '');
                            $img    = !empty($t['image']) && function_exists('oec_build_display_image_url') ? oec_build_display_image_url($t['image'], 120, 80) : ($t['image'] ?? '');
                        ?>
                        <div class="oec-st-row">
                            <div class="oec-st-info">
                                <?php if ($img) : ?><img class="oec-st-img" src="<?php echo esc_url($img); ?>" alt="" loading="lazy"><?php endif; ?>
                                <div style="min-width:0;">
                                    <p class="oec-st-title"><a href="<?php echo esc_url(home_url('/formacion/' . ($t['slug'] ?? ''))); ?>" target="_blank" rel="noopener"><?php echo esc_html($t['title'] ?? ''); ?></a></p>
                                    <div class="oec-st-chips">
                                        <span class="oec-st-chip">Edición <?php echo intval($t['edition_number'] ?? 0); ?></span>
                                        <?php if ($close !== null) :
                                            if ($close < 0)       { $cls = '';                     $txt = 'Inscripción cerrada'; }
                                            elseif ($close === 0) { $cls = ' oec-st-chip--urgent'; $txt = 'Cierra hoy'; }
                                            elseif ($close <= 7)  { $cls = ' oec-st-chip--urgent'; $txt = 'Cierra en ' . $close . ($close === 1 ? ' día' : ' días'); }
                                            elseif ($close <= 15) { $cls = ' oec-st-chip--soon';   $txt = 'Cierra en ' . $close . ' días'; }
                                            else                  { $cls = '';                     $txt = 'Cierra en ' . $close . ' días'; }
                                        ?>
                                            <span class="oec-st-chip<?php echo $cls; ?>"><?php echo esc_html($txt); ?></span>
                                        <?php endif; ?>
                                        <?php if ($start !== null && $start > 0) : ?>
                                            <span class="oec-st-chip">Comienza en <?php echo intval($start); ?> <?php echo $start === 1 ? 'día' : 'días'; ?></span>
                                        <?php elseif ($start !== null) : ?>
                                            <span class="oec-st-chip">Ya comenzó</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($counts !== null) :
                                echo self::render_counts($t, $counts); // phpcs:ignore -- escapado adentro
                            else : ?>
                                <div class="oec-st-metrics oec-st-loading" data-id="<?php echo esc_attr($t['id']); ?>" data-title="<?php echo esc_attr($t['title'] ?? ''); ?>" data-goal="<?php echo intval($t['enrollments_goal'] ?? 0); ?>" data-slug="<?php echo esc_attr($t['slug'] ?? ''); ?>">
                                    <div class="oec-st-funnel oec-st-skel"><?php for ($i = 0; $i < count(self::STAGES); $i++) : ?><div class="oec-st-step"><span class="oec-st-num">&nbsp;</span><span class="oec-st-lbl">&nbsp;</span></div><?php endfor; ?></div>
                                    <div class="oec-st-goal"><div class="oec-st-goal-note">Cargando…</div></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($result['total_pages'] > 1) : ?>
                        <div class="oec-st-pager">
                            <?php if ($page > 1) : ?>
                                <a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1, $base)); ?>">← Anterior</a>
                            <?php endif; ?>
                            <span>Página <?php echo intval($page); ?> de <?php echo intval($result['total_pages']); ?></span>
                            <?php if ($page < $result['total_pages']) : ?>
                                <a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1, $base)); ?>">Siguiente →</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <dialog class="oec-st-dlg" id="oec-st-dlg" aria-labelledby="oec-st-dlg-title">
                <div class="oec-st-dlg-in">
                    <div class="oec-st-dlg-head">
                        <div>
                            <h2 id="oec-st-dlg-title"></h2>
                            <p id="oec-st-dlg-sub"></p>
                        </div>
                        <div class="oec-st-dlg-actions">
                            <span id="oec-st-list-actions" style="display:contents;">
                                <button type="button" class="button button-primary" id="oec-st-write" disabled><span class="dashicons dashicons-email-alt" style="margin-top:4px;"></span> <span>Enviar email</span></button>
                                <button type="button" class="button" id="oec-st-copy" disabled><span class="dashicons dashicons-admin-page" style="margin-top:4px;"></span> Copiar emails</button>
                                <a class="button" id="oec-st-csv" href="#"><span class="dashicons dashicons-download" style="margin-top:4px;"></span> Descargar CSV</a>
                            </span>
                            <button type="button" class="button" id="oec-st-back" style="display:none;"><span class="dashicons dashicons-arrow-left-alt2" style="margin-top:4px;"></span> Volver a la lista</button>
                            <button type="button" class="button button-link" id="oec-st-close" aria-label="Cerrar"><span class="dashicons dashicons-no-alt" style="font-size:22px;"></span></button>
                        </div>
                    </div>
                    <div class="oec-st-selbar" id="oec-st-selbar" hidden>
                        <label>Mostrar <select id="oec-st-filter"><option value="all">Todos</option></select></label>
                        <span id="oec-st-selcount"></span>
                        <label><input type="checkbox" id="oec-st-skip-sent" checked> No elegir a quienes ya les escribí</label>
                        <button type="button" class="button button-small" id="oec-st-wa-edit"><span class="dashicons dashicons-whatsapp"></span> Mensaje de WhatsApp</button>
                    </div>
                    <div class="oec-st-wapanel" id="oec-st-wa-panel" hidden>
                        <label for="oec-st-wa-text">Mensaje que se arma al tocar WhatsApp en cada persona</label>
                        <textarea id="oec-st-wa-text" rows="3"></textarea>
                        <p class="oec-st-hint">Variables: {nombre} {apellido} {email} {telefono} {pais} {formacion} {link}. Antes de enviarlo lo puedes cambiar en WhatsApp.</p>
                        <p><button type="button" class="button button-primary" id="oec-st-wa-save">Guardar</button> <button type="button" class="button" id="oec-st-wa-cancel">Cancelar</button> <span id="oec-st-wa-flash" class="oec-st-hint"></span></p>
                    </div>
                    <div class="oec-st-dlg-body" id="oec-st-dlg-body"></div>
                    <div class="oec-st-dlg-foot">Estas personas aceptaron compartir sus datos con los organizadores de la formación. Úsalos solo para comunicaciones sobre ella. Los emails que salen automáticamente llevan un link para darse de baja. El CSV se puede importar en Google Sheets o en Google Contacts.</div>
                </div>
            </dialog>

            <template id="oec-st-compose-tpl">
                <div class="oec-st-compose">
                    <div>
                        <div class="oec-st-from" data-from></div>
                        <div class="oec-st-field">
                            <label for="oec-st-tpl">Plantilla</label>
                            <div class="oec-st-row2">
                                <select id="oec-st-tpl"></select>
                                <button type="button" class="button" data-tpl-save>Guardar como plantilla</button>
                                <button type="button" class="button button-link-delete" data-tpl-del title="Borrar esta plantilla">Borrar</button>
                            </div>
                        </div>
                        <div class="oec-st-field">
                            <label for="oec-st-subject">Asunto</label>
                            <input type="text" id="oec-st-subject" maxlength="200">
                        </div>
                        <div class="oec-st-field">
                            <label for="oec-st-body">Mensaje</label>
                            <div class="oec-st-vars" data-vars></div>
                            <textarea id="oec-st-body"></textarea>
                            <p class="oec-st-hint">Toca una variable para insertarla: se reemplaza por el dato de cada persona.</p>
                        </div>
                        <div class="oec-st-field">
                            <label for="oec-st-client" data-client-label>Abrir en</label>
                            <div class="oec-st-row2">
                                <select id="oec-st-client">
                                    <option value="gmail">Gmail</option>
                                    <option value="outlook">Outlook / Microsoft 365</option>
                                    <option value="mailto">Mi programa de correo (Apple Mail, Outlook de escritorio…)</option>
                                </select>
                            </div>
                            <input type="email" id="oec-st-account" placeholder="Tu cuenta de Gmail (opcional, si tienes varias abiertas)" style="margin-top:8px;">
                        </div>
                    </div>
                    <div class="oec-st-prev">
                        <div class="oec-st-prev-head">
                            <button type="button" class="button" data-prev="-1" aria-label="Anterior">‹</button>
                            <span data-prev-who></span>
                            <button type="button" class="button" data-prev="1" aria-label="Siguiente">›</button>
                        </div>
                        <div class="oec-st-prev-subj" data-prev-subj></div>
                        <div class="oec-st-prev-body" data-prev-body></div>
                    </div>
                    <div class="oec-st-compose-actions">
                        <span class="oec-st-note" data-quota></span>
                        <span class="oec-st-flash" data-flash></span>
                        <button type="button" class="button" data-test>Enviarme una prueba</button>
                        <button type="button" class="button" data-manual>Abrir uno por uno</button>
                        <button type="button" class="button button-primary" data-send></button>
                    </div>
                </div>
            </template>

            <script>
            (function () {
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var csvUrl  = <?php echo wp_json_encode(admin_url('admin-post.php')); ?>;
                var nonce   = <?php echo wp_json_encode(wp_create_nonce('oec_stats')); ?>;
                var labels  = <?php echo wp_json_encode(self::STAGES); ?>;

                function post(data) {
                    data.nonce = nonce;
                    return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(data) })
                        .then(function (r) { return r.json(); });
                }

                // --- Números: de a 4 formaciones a la vez ---
                var boxes = Array.prototype.slice.call(document.querySelectorAll('.oec-st-loading'));
                var running = 0, LIMIT = 4;
                var FAIL = '<div class="oec-st-missing">No se pudieron cargar los datos de Zoho.</div><div></div>';
                function next() { while (running < LIMIT && boxes.length) load(boxes.shift()); }
                function load(box) {
                    running++;
                    post({ action: 'oec_stats_counts', id: box.dataset.id, title: box.dataset.title, goal: box.dataset.goal, slug: box.dataset.slug })
                        .then(function (res) { box.outerHTML = (res && res.data && res.data.html) || FAIL; })
                        .catch(function () { box.outerHTML = FAIL; })
                        .then(function () { running--; next(); });
                }
                next();

                // --- Contactos de una etapa ---
                var dlg = document.getElementById('oec-st-dlg');
                if (!dlg) return;
                var body = document.getElementById('oec-st-dlg-body');
                var copyBtn = document.getElementById('oec-st-copy');
                var writeBtn = document.getElementById('oec-st-write');
                var selbar = document.getElementById('oec-st-selbar');
                var skipSent = document.getElementById('oec-st-skip-sent');
                var listActions = document.getElementById('oec-st-list-actions');
                var backBtn = document.getElementById('oec-st-back');
                var filterSel = document.getElementById('oec-st-filter');
                var waPanel = document.getElementById('oec-st-wa-panel');
                var mail = <?php echo wp_json_encode(self::mail_config()); ?>;
                var emails = [], contacts = [], ctx = null, picked = {}, poll = null;

                function el(tag, attrs, text) {
                    var n = document.createElement(tag);
                    if (attrs) Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
                    if (text != null) n.textContent = text;
                    return n;
                }
                function msg(text) { body.replaceChildren(el('div', { 'class': 'oec-st-dlg-msg' }, text)); }
                function fmtDate(iso) {
                    if (!iso) return '';
                    var d = new Date(iso);
                    return isNaN(d) ? '' : d.toLocaleDateString('es-AR', { day: '2-digit', month: 'short', year: 'numeric' });
                }
                var STATUS_OFF = { inscripto: 1, no_interesa: 1 }; // no se eligen solos para el email
                function canMail(c) { return !!c.email && !c.unsub && !c.queued; }
                function lastEmail(c) { return [c.sent, c.last_mail].filter(Boolean).sort().pop() || null; }
                function view(which) {
                    var list = which === 'list';
                    listActions.style.display = list ? 'contents' : 'none';
                    backBtn.style.display = list ? 'none' : ''; // .button le gana al atributo hidden
                    selbar.hidden = !list || !contacts.length;
                    if (!list) waPanel.hidden = true;
                    if (poll && list) { clearTimeout(poll); poll = null; }
                }
                function updateSel() {
                    var n = Object.keys(picked).length;
                    var mailable = contacts.filter(canMail).length;
                    document.getElementById('oec-st-selcount').textContent = n + ' de ' + mailable + ' elegidos para el email';
                    writeBtn.disabled = !n;
                    writeBtn.lastChild.textContent = n ? 'Enviar email (' + n + ')' : 'Enviar email';
                }
                function autoPick() {
                    picked = {};
                    contacts.forEach(function (c) { if (canMail(c) && !STATUS_OFF[c.status] && !(skipSent.checked && lastEmail(c))) picked[c.email] = true; });
                }
                function filtered() {
                    var f = filterSel.value;
                    return f === 'all' ? contacts : contacts.filter(function (c) { return (c.status || '') === (f === '_none' ? '' : f); });
                }
                function fillFilter() {
                    var cur = filterSel.value || 'all';
                    var n = {};
                    contacts.forEach(function (c) { n[c.status || ''] = (n[c.status || ''] || 0) + 1; });
                    filterSel.replaceChildren(el('option', { value: 'all' }, 'Todos (' + contacts.length + ')'));
                    Object.keys(mail.statuses).forEach(function (k) {
                        if (n[k]) filterSel.appendChild(el('option', { value: k || '_none' }, mail.statuses[k] + ' (' + n[k] + ')'));
                    });
                    filterSel.value = Array.prototype.some.call(filterSel.options, function (o) { return o.value === cur; }) ? cur : 'all';
                }
                function icon(name) { return el('span', { 'class': 'dashicons dashicons-' + name, 'aria-hidden': 'true' }); }
                function waLink(c) {
                    return 'https://wa.me/' + c.phone_e164 + '?text=' + encodeURIComponent(fill(mail.wa, varsFor(c)));
                }
                function logEvent(c, type) {
                    mailPost({ action: 'oec_contact_event', id: ctx.id, key: c.key, type: type }).then(function (res) {
                        if (!res || !res.success) return;
                        c[{ wa: 'last_wa', call: 'last_call', mail: 'last_mail' }[type]] = res.data.at;
                        if (!c.status) c.status = 'contactado';
                        refresh();
                    });
                }
                function saveContact(c, data, done) {
                    mailPost(Object.assign({ action: 'oec_contact_update', id: ctx.id, key: c.key }, data)).then(function (res) {
                        if (res && res.success) { c.status = res.data.status || c.status; c.note = res.data.note; }
                        if (done) done(res && res.success);
                    }).catch(function () { if (done) done(false); });
                }
                function lastContactCell(c, td) {
                    if (c.unsub) td.appendChild(el('span', { 'class': 'oec-st-mailtag oec-st-mailtag--unsub' }, 'Se dio de baja'));
                    if (c.queued) td.appendChild(el('span', { 'class': 'oec-st-mailtag oec-st-mailtag--queued' }, 'Email en cola'));
                    var ev = [
                        { at: lastEmail(c), ico: 'email-alt', txt: 'Email' },
                        { at: c.last_wa, ico: 'whatsapp', txt: 'WhatsApp' },
                        { at: c.last_call, ico: 'phone', txt: 'Llamada' }
                    ].filter(function (e) { return e.at; }).sort(function (a, b) { return a.at < b.at ? 1 : -1; });
                    if (!ev.length) { if (!c.unsub && !c.queued) td.appendChild(el('span', { 'class': 'oec-st-dim' }, '—')); return; }
                    var line = td.appendChild(el('span', { 'class': 'oec-st-last', title: ev.map(function (e) { return e.txt + ': ' + fmtDate(e.at); }).join('\n') }));
                    line.append(icon(ev[0].ico), document.createTextNode(fmtDate(ev[0].at)));
                    if (ev.length > 1) line.appendChild(el('span', { 'class': 'oec-st-dim' }, ' +' + (ev.length - 1)));
                }
                function noteLine(c, box) {
                    box.replaceChildren();
                    if (!c.key) return;
                    if (c.note) box.appendChild(el('span', null, c.note + ' '));
                    var b = box.appendChild(el('button', { type: 'button' }, c.note ? 'Editar nota' : '+ Nota'));
                    b.addEventListener('click', function () {
                        var inp = el('input', { type: 'text', maxlength: '500', placeholder: 'Ej.: llamar después del 5/11' });
                        inp.value = c.note || '';
                        box.replaceChildren(inp);
                        inp.focus();
                        var done = false;
                        function finish(save) {
                            if (done) return; done = true;
                            if (!save || inp.value.trim() === (c.note || '')) return noteLine(c, box);
                            inp.disabled = true;
                            saveContact(c, { note: inp.value.trim() }, function () { noteLine(c, box); });
                        }
                        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') finish(true); if (e.key === 'Escape') { e.preventDefault(); finish(false); } });
                        inp.addEventListener('blur', function () { finish(true); });
                    });
                }
                function table(list) {
                    var t = el('table', { 'class': 'oec-st-table' });
                    var h = el('tr');
                    var all = el('input', { type: 'checkbox', 'aria-label': 'Elegir a todos para el email' });
                    h.appendChild(el('th', { 'class': 'oec-st-col-chk' })).appendChild(all);
                    ['Nombre', 'Email', 'Teléfono', 'País', 'Último contacto', 'Estado'].forEach(function (c, i) {
                        h.appendChild(el('th', i === 3 ? { 'class': 'oec-st-col-opt' } : null, c));
                    });
                    t.appendChild(el('thead')).appendChild(h);
                    var tb = t.appendChild(el('tbody'));
                    var boxes = [];
                    list.forEach(function (c) {
                        var tr = tb.appendChild(el('tr'));
                        var td0 = tr.appendChild(el('td', { 'class': 'oec-st-col-chk' }));
                        if (canMail(c)) {
                            var cb = td0.appendChild(el('input', { type: 'checkbox', 'aria-label': 'Elegir a ' + (c.name || c.email) + ' para el email' }));
                            cb.checked = !!picked[c.email];
                            cb.addEventListener('change', function () { if (cb.checked) picked[c.email] = true; else delete picked[c.email]; syncAll(); updateSel(); });
                            boxes.push(cb);
                        }
                        var tdN = tr.appendChild(el('td'));
                        tdN.appendChild(el('div', { 'class': 'oec-st-name', title: c.created ? 'En esta etapa desde el ' + fmtDate(c.created) : '' }, niceCase(c.name) || '—'));
                        noteLine(c, tdN.appendChild(el('div', { 'class': 'oec-st-noteline' })));

                        var tdE = tr.appendChild(el('td'));
                        if (c.email) tdE.appendChild(el('a', { href: 'mailto:' + c.email }, c.email)); else tdE.appendChild(el('span', { 'class': 'oec-st-dim' }, '—'));

                        var tdP = tr.appendChild(el('td', { 'class': 'oec-st-phone' }));
                        if (c.phone_e164) {
                            tdP.appendChild(el('span', { title: c.phone !== c.phone_e164 ? 'En Zoho: ' + c.phone : '' }, '+' + c.phone_e164));
                            var acts = tdP.appendChild(el('span', { 'class': 'oec-st-acts' }));
                            var wa = acts.appendChild(el('a', { 'class': 'oec-st-act oec-st-act--wa', href: waLink(c), target: '_blank', rel: 'noopener', title: 'Escribir por WhatsApp (con el mensaje armado)', 'aria-label': 'WhatsApp' }));
                            wa.appendChild(icon('whatsapp'));
                            wa.addEventListener('click', function () { wa.href = waLink(c); logEvent(c, 'wa'); });
                            var call = acts.appendChild(el('a', { 'class': 'oec-st-act oec-st-act--call', href: 'tel:+' + c.phone_e164, title: 'Llamar', 'aria-label': 'Llamar' }));
                            call.appendChild(icon('phone'));
                            call.addEventListener('click', function () { logEvent(c, 'call'); });
                            var cp = acts.appendChild(el('button', { type: 'button', 'class': 'oec-st-act', title: 'Copiar número', 'aria-label': 'Copiar número' }));
                            cp.appendChild(icon('admin-page'));
                            cp.addEventListener('click', function () { copyText('+' + c.phone_e164, function () { cp.replaceChildren(icon('yes')); setTimeout(function () { cp.replaceChildren(icon('admin-page')); }, 1500); }); });
                        } else if (c.phone) {
                            tdP.appendChild(el('span', { 'class': 'oec-st-dim', title: 'No se pudo entender este número (le falta el código de país o tiene letras).' }, c.phone + ' ⚠'));
                        } else tdP.appendChild(el('span', { 'class': 'oec-st-dim' }, '—'));

                        tr.appendChild(el('td', { 'class': 'oec-st-col-opt', title: countryName(c.country) }, c.country || ''));
                        lastContactCell(c, tr.appendChild(el('td')));

                        var tdS = tr.appendChild(el('td'));
                        if (c.key) {
                            var sel = tdS.appendChild(el('select', { 'class': 'oec-st-status', 'aria-label': 'Estado de ' + (c.name || c.email) }));
                            Object.keys(mail.statuses).forEach(function (k) { sel.appendChild(el('option', { value: k }, mail.statuses[k])); });
                            sel.value = c.status || '';
                            sel.dataset.st = sel.value;
                            sel.addEventListener('change', function () {
                                sel.dataset.st = sel.value; sel.disabled = true;
                                saveContact(c, { status: sel.value }, function (ok) { sel.disabled = false; if (!ok) { sel.value = c.status || ''; sel.dataset.st = sel.value; } fillFilter(); });
                            });
                        }
                    });
                    function syncAll() { all.checked = boxes.length > 0 && boxes.every(function (b) { return b.checked; }); }
                    all.addEventListener('change', function () {
                        boxes.forEach(function (b) { b.checked = all.checked; b.dispatchEvent(new Event('change')); });
                    });
                    all.disabled = !boxes.length;
                    syncAll();
                    return t;
                }
                function copyText(text, done) {
                    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done);
                    else { var ta = el('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done(); }
                }
                function showList() {
                    view('list');
                    if (!contacts.length) return;
                    fillFilter();
                    var list = filtered();
                    body.replaceChildren(list.length ? table(list) : el('div', { 'class': 'oec-st-dlg-msg' }, 'Nadie con ese estado.'));
                    if (ctx.truncated) body.appendChild(el('p', { 'class': 'oec-st-dim' }, 'Se muestran los primeros 2.000 contactos.'));
                    updateSel();
                }
                function refresh() { var y = body.scrollTop; showList(); body.scrollTop = y; }
                function loadContacts() {
                    emails = []; contacts = []; picked = {}; copyBtn.disabled = true; writeBtn.disabled = true;
                    view('list'); selbar.hidden = true;
                    msg('Cargando contactos…');
                    post({ action: 'oec_stats_contacts', id: ctx.id, edition: ctx.edition, stage: ctx.stage }).then(function (res) {
                        if (!res || !res.success) { msg((res && res.data && res.data.message) || 'No se pudieron cargar los contactos.'); return; }
                        contacts = res.data.contacts || [];
                        ctx.truncated = res.data.truncated;
                        if (!contacts.length) { msg('No hay contactos en esta etapa.'); return; }
                        emails = contacts.map(function (c) { return c.email; }).filter(Boolean);
                        copyBtn.disabled = !emails.length;
                        autoPick();
                        showList();
                    }).catch(function () { msg('No se pudieron cargar los contactos.'); });
                }
                skipSent.addEventListener('change', function () { autoPick(); refresh(); });
                filterSel.addEventListener('change', showList);

                // Mensaje de WhatsApp (uno por sitio, con las mismas variables que el email)
                var waText = document.getElementById('oec-st-wa-text'), waFlash = document.getElementById('oec-st-wa-flash');
                document.getElementById('oec-st-wa-edit').addEventListener('click', function () {
                    waPanel.hidden = !waPanel.hidden;
                    if (!waPanel.hidden) { waText.value = mail.wa; waFlash.textContent = ''; waText.focus(); }
                });
                document.getElementById('oec-st-wa-cancel').addEventListener('click', function () { waPanel.hidden = true; });
                document.getElementById('oec-st-wa-save').addEventListener('click', function () {
                    waFlash.textContent = 'Guardando…';
                    mailPost({ action: 'oec_wa_message_save', message: waText.value }).then(function (res) {
                        if (!res || !res.success) { waFlash.textContent = 'No se pudo guardar.'; return; }
                        mail.wa = res.data.message; waPanel.hidden = true; refresh();
                    }).catch(function () { waFlash.textContent = 'No se pudo guardar.'; });
                });

                document.addEventListener('click', function (e) {
                    var b = e.target.closest('button.oec-st-step[data-stage]');
                    if (!b) return;
                    ctx = { id: b.dataset.id, edition: b.dataset.edition, stage: b.dataset.stage, title: b.dataset.title, slug: b.dataset.slug || '' };
                    document.getElementById('oec-st-dlg-title').textContent = labels[ctx.stage] + ' (' + b.dataset.count + ')';
                    document.getElementById('oec-st-dlg-sub').textContent = ctx.title;
                    document.getElementById('oec-st-csv').href = csvUrl + '?' + new URLSearchParams({ action: 'oec_stats_csv', id: ctx.id, edition: ctx.edition, stage: ctx.stage, _wpnonce: nonce });
                    dlg.showModal();
                    loadContacts();
                });

                // --- Redactar y enviar desde el correo del socio ---
                var countryName = (function () {
                    try { var dn = new Intl.DisplayNames(['es'], { type: 'region' }); return function (c) { try { return c && c.length === 2 ? dn.of(c.toUpperCase()) : (c || ''); } catch (e) { return c || ''; } }; }
                    catch (e) { return function (c) { return c || ''; }; }
                })();
                function niceCase(s) {
                    s = (s || '').trim();
                    if (!s || (s.toUpperCase() !== s && s.toLowerCase() !== s)) return s;
                    return s.toLowerCase().replace(/(^|[\s'-])(\p{L})/gu, function (m, a, b) { return a + b.toUpperCase(); });
                }
                function varsFor(c) {
                    var first = c.first_name || (c.name || '').split(' ')[0] || '';
                    return {
                        nombre: niceCase(first), apellido: niceCase(c.last_name), email: c.email || '', telefono: c.phone || '',
                        pais: countryName(c.country), formacion: ctx.title, link: mail.siteUrl + (ctx.slug ? 'formacion/' + ctx.slug : '')
                    };
                }
                function fill(tpl, vars) { return (tpl || '').replace(/\{(nombre|apellido|email|telefono|pais|formacion|link)\}/g, function (m, k) { return vars[k] || ''; }); }
                function renderBody(text, vars, box) {
                    box.replaceChildren();
                    fill(text, vars).split(/\n{2,}/).forEach(function (para) {
                        if (!para.trim()) return;
                        var p = box.appendChild(el('p'));
                        para.split('\n').forEach(function (line, i) {
                            if (i) p.appendChild(el('br'));
                            line.split(/(https?:\/\/\S+)/).forEach(function (part, j) {
                                if (j % 2) p.appendChild(el('a', { href: part, target: '_blank', rel: 'noopener' }, part));
                                else if (part) p.appendChild(document.createTextNode(part));
                            });
                        });
                    });
                }
                // Preferencias de este navegador (dónde abrir el email y con qué cuenta).
                function pref(k, v) {
                    try { if (v === undefined) return localStorage.getItem('oec_st_' + k) || ''; localStorage.setItem('oec_st_' + k, v); } catch (e) { return ''; }
                }
                /** Link que abre el email ya escrito para una persona. */
                function composeUrl(client, account, to, subject, text) {
                    var e = encodeURIComponent;
                    if (client === 'outlook') return 'https://outlook.office.com/mail/deeplink/compose?to=' + e(to) + '&subject=' + e(subject) + '&body=' + e(text);
                    if (client === 'mailto') return 'mailto:' + e(to).replace(/%40/g, '@') + '?subject=' + e(subject) + '&body=' + e(text);
                    return 'https://mail.google.com/mail/?view=cm&fs=1&tf=1&to=' + e(to) + '&su=' + e(subject) + '&body=' + e(text) + (account ? '&authuser=' + e(account) : '');
                }

                function compose() {
                    var chosen = contacts.filter(function (c) { return picked[c.email]; });
                    if (!chosen.length) return;
                    view('compose');
                    var node = document.getElementById('oec-st-compose-tpl').content.cloneNode(true);
                    body.replaceChildren(node);
                    var q = function (s) { return body.querySelector(s); };
                    var sel = q('#oec-st-tpl'), subj = q('#oec-st-subject'), txt = q('#oec-st-body');
                    var client = q('#oec-st-client'), account = q('#oec-st-account');
                    var flash = q('[data-flash]'), sendBtn = q('[data-send]'), testBtn = q('[data-test]'), manualBtn = q('[data-manual]');
                    var idx = 0, lastField = txt;
                    var auto = !!mail.google && !mail.broken;

                    var from = q('[data-from]');
                    if (auto) {
                        from.className += ' oec-st-from--ok';
                        from.append(el('span', { 'class': 'dashicons dashicons-yes-alt' }), el('span', null, 'Sale automáticamente desde tu Gmail (' + mail.google + '), con tu nombre. Queda en tus "Enviados" y las respuestas te llegan ahí.'));
                        q('[data-client-label]').textContent = 'Si prefieres abrirlos uno por uno, en';
                    } else {
                        from.className += ' oec-st-from--info';
                        var w = el('span', null, (mail.broken ? 'Tu cuenta de Google se desconectó. ' : '') + 'Para cada persona se abre un email ya escrito en tu correo: lo revisas y tocas Enviar. Para que salgan solos, ');
                        w.appendChild(el('a', { href: mail.configUrl, target: '_blank' }, mail.broken ? 'vuelve a conectar tu cuenta de Google' : 'conecta tu cuenta de Google'));
                        w.appendChild(document.createTextNode('.'));
                        from.append(el('span', { 'class': 'dashicons dashicons-info' }), w);
                    }
                    testBtn.style.display = manualBtn.style.display = auto ? '' : 'none';

                    client.value = pref('client') || 'gmail';
                    account.value = pref('account');
                    function syncClient() { account.style.display = client.value === 'gmail' ? '' : 'none'; }
                    client.addEventListener('change', function () { pref('client', client.value); syncClient(); });
                    account.addEventListener('change', function () { pref('account', account.value.trim()); });
                    syncClient();

                    function fillTemplates(selKey) {
                        sel.replaceChildren(el('option', { value: '' }, '— Mensaje nuevo —'));
                        Object.keys(mail.templates).forEach(function (k) { sel.appendChild(el('option', { value: k }, mail.templates[k].name)); });
                        sel.value = selKey || '';
                        q('[data-tpl-del]').style.display = sel.value ? '' : 'none';
                    }
                    sel.addEventListener('change', function () {
                        var t = mail.templates[sel.value];
                        if (t) { subj.value = t.subject; txt.value = t.body; }
                        q('[data-tpl-del]').style.display = sel.value ? '' : 'none';
                        preview();
                    });
                    var firstKey = Object.keys(mail.templates)[0] || '';
                    fillTemplates(firstKey);
                    if (firstKey) { subj.value = mail.templates[firstKey].subject; txt.value = mail.templates[firstKey].body; }

                    q('[data-tpl-save]').addEventListener('click', function () {
                        var cur = mail.templates[sel.value];
                        var name = window.prompt('Nombre de la plantilla', cur ? cur.name : '');
                        if (!name) return;
                        var key = cur && cur.name === name ? sel.value : '';
                        mailPost({ action: 'oec_mail_template_save', key: key, name: name, subject: subj.value, body: txt.value }).then(function (res) {
                            if (!res || !res.success) return say((res && res.data && res.data.message) || 'No se pudo guardar.', true);
                            mail.templates = res.data.templates; fillTemplates(res.data.key); say('Plantilla guardada.');
                        });
                    });
                    q('[data-tpl-del]').addEventListener('click', function () {
                        if (!sel.value || !window.confirm('¿Borrar la plantilla «' + mail.templates[sel.value].name + '»?')) return;
                        mailPost({ action: 'oec_mail_template_delete', key: sel.value }).then(function (res) {
                            if (res && res.success) { mail.templates = res.data.templates; fillTemplates(''); say('Plantilla borrada.'); }
                        });
                    });

                    var vbox = q('[data-vars]');
                    ['nombre', 'apellido', 'email', 'telefono', 'pais', 'formacion', 'link'].forEach(function (v) {
                        var b = vbox.appendChild(el('button', { type: 'button' }, '{' + v + '}'));
                        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
                        b.addEventListener('click', function () {
                            var act = document.activeElement;
                            var f = (act === subj || act === txt) ? act : lastField, s = f.selectionStart, en = f.selectionEnd, ins = '{' + v + '}';
                            f.value = f.value.slice(0, s) + ins + f.value.slice(en);
                            f.focus(); f.selectionStart = f.selectionEnd = s + ins.length;
                            preview();
                        });
                    });
                    [subj, txt].forEach(function (f) {
                        f.addEventListener('focus', function () { lastField = f; });
                        f.addEventListener('input', preview);
                    });

                    function preview() {
                        var c = chosen[idx], v = varsFor(c);
                        q('[data-prev-who]').textContent = 'Así le llega a ' + (niceCase(c.name) || c.email) + ' (' + (idx + 1) + ' de ' + chosen.length + ')';
                        q('[data-prev-subj]').textContent = fill(subj.value, v) || '(sin asunto)';
                        renderBody(txt.value, v, q('[data-prev-body]'));
                    }
                    body.querySelectorAll('[data-prev]').forEach(function (b) {
                        b.disabled = chosen.length < 2;
                        b.addEventListener('click', function () { idx = (idx + Number(b.dataset.prev) + chosen.length) % chosen.length; preview(); });
                    });
                    function say(text, err) { flash.textContent = text; flash.className = 'oec-st-flash ' + (err ? 'is-err' : 'is-ok'); }

                    var n = chosen.length + (chosen.length === 1 ? ' persona' : ' personas');
                    function startSerial() {
                        if (!subj.value.trim() || !txt.value.trim()) return say('Completa el asunto y el mensaje.', true);
                        serial(chosen, { subject: subj.value, body: txt.value, client: client.value, account: client.value === 'gmail' ? account.value.trim() : '' });
                    }
                    function payload(extra) { return Object.assign({ subject: subj.value, body: txt.value, title: ctx.title, slug: ctx.slug }, extra); }
                    manualBtn.addEventListener('click', startSerial);
                    if (!auto) {
                        sendBtn.textContent = 'Empezar (' + n + ')';
                        sendBtn.addEventListener('click', startSerial);
                    } else {
                        var left = Math.max(0, mail.limit - mail.sentToday);
                        q('[data-quota]').textContent = 'Hoy se enviaron ' + mail.sentToday + ' de ' + mail.limit + ' emails permitidos por día.' + (chosen.length > left ? ' Los que pasen el límite salen mañana.' : '');
                        sendBtn.textContent = 'Enviar desde tu Gmail (' + n + ')';
                        testBtn.addEventListener('click', function () {
                            testBtn.disabled = true; say('Enviando prueba…');
                            mailPost(Object.assign(payload({ action: 'oec_mail_preview_test' }), { sample: JSON.stringify(chosen[idx]) })).then(function (res) {
                                say(res && res.data && res.data.message || 'No se pudo enviar la prueba.', !(res && res.success));
                            }).catch(function () { say('No se pudo enviar la prueba.', true); }).then(function () { testBtn.disabled = false; });
                        });
                        sendBtn.addEventListener('click', function () {
                            if (!subj.value.trim() || !txt.value.trim()) return say('Completa el asunto y el mensaje.', true);
                            if (!window.confirm('¿Enviar este email desde ' + mail.google + ' a ' + n + '?')) return;
                            sendBtn.disabled = testBtn.disabled = manualBtn.disabled = true; say('Preparando el envío…');
                            mailPost(payload({ action: 'oec_mail_send', id: ctx.id, edition: ctx.edition, stage: ctx.stage, emails: JSON.stringify(chosen.map(function (c) { return c.email; })) })).then(function (res) {
                                if (!res || !res.success) { sendBtn.disabled = testBtn.disabled = manualBtn.disabled = false; return say((res && res.data && res.data.message) || 'No se pudo iniciar el envío.', true); }
                                progress(res.data.campaign, res.data.progress);
                            }).catch(function () { sendBtn.disabled = testBtn.disabled = manualBtn.disabled = false; say('No se pudo iniciar el envío.', true); });
                        });
                    }
                    preview();
                    subj.focus();
                }

                /** Una tarjeta por persona: "Abrir email para X" → se abre ya escrito, se anota y pasa a la siguiente. */
                function serial(list, msgData) {
                    var i = 0, opened = 0;
                    var where = msgData.client === 'outlook' ? 'Outlook' : (msgData.client === 'mailto' ? 'tu programa de correo' : 'Gmail');
                    function draw() {
                        var box = el('div', { 'class': 'oec-st-progress oec-st-serial' });
                        var bar = el('div', { 'class': 'oec-st-bar' });
                        bar.appendChild(el('span', { style: 'width:' + Math.round(i * 100 / list.length) + '%' }));
                        if (i >= list.length) {
                            box.append(el('h3', null, '¡Listo!'), bar, el('p', null, opened + (opened === 1 ? ' email abierto' : ' emails abiertos') + ' en ' + where + '. Quedaron anotados en "Último contacto".'));
                            var back = box.appendChild(el('button', { type: 'button', 'class': 'button button-primary' }, 'Volver a la lista'));
                            back.addEventListener('click', function () { autoPick(); showList(); }); // a quienes ya se les escribió no se los vuelve a elegir
                            body.replaceChildren(box);
                            return;
                        }
                        var c = list[i], v = varsFor(c);
                        box.append(el('p', { 'class': 'oec-st-dim' }, 'Persona ' + (i + 1) + ' de ' + list.length), bar);
                        box.appendChild(el('h3', null, niceCase(c.name) || c.email));
                        box.appendChild(el('p', null, c.email));
                        var url = composeUrl(msgData.client, msgData.account, c.email, fill(msgData.subject, v), fill(msgData.body, v));
                        var attrs = { 'class': 'button button-primary button-hero', href: url };
                        if (msgData.client !== 'mailto') { attrs.target = '_blank'; attrs.rel = 'noopener'; }
                        var go = box.appendChild(el('a', attrs));
                        go.append(el('span', { 'class': 'dashicons dashicons-email-alt' }), document.createTextNode(' Abrir email para ' + (v.nombre || c.email) + ' en ' + where));
                        go.addEventListener('click', function () {
                            opened++;
                            logEvent(c, 'mail');
                            i++;
                            setTimeout(draw, 300); // que el navegador abra el link antes de cambiar la tarjeta
                        });
                        var skip = box.appendChild(el('button', { type: 'button', 'class': 'button button-link' }, 'Saltar a la siguiente'));
                        skip.addEventListener('click', function () { i++; draw(); });
                        box.appendChild(el('p', { 'class': 'oec-st-dim' }, 'Se abre en otra pestaña con el email escrito: revísalo, toca Enviar y vuelve acá.'));
                        body.replaceChildren(box);
                    }
                    draw();
                }

                function progress(cid, p) {
                    var box = el('div', { 'class': 'oec-st-progress' });
                    var h = box.appendChild(el('h3'));
                    var bar = box.appendChild(el('div', { 'class': 'oec-st-bar' })).appendChild(el('span', { style: 'width:0%' }));
                    var line = box.appendChild(el('p'));
                    var err = box.appendChild(el('p', { 'class': 'oec-st-err' }));
                    var note = box.appendChild(el('p', { 'class': 'oec-st-dim' }, 'Puedes cerrar esta ventana: el envío sigue en segundo plano.'));
                    body.replaceChildren(box);
                    function draw(p) {
                        var total = p.sent + p.pending + p.failed + p.skipped, done = total - p.pending;
                        bar.style.width = (total ? Math.round(done * 100 / total) : 100) + '%';
                        line.textContent = p.sent + ' enviados' + (p.failed ? ' · ' + p.failed + ' con error' : '') + (p.skipped ? ' · ' + p.skipped + ' omitidos (se dieron de baja)' : '') + (p.pending ? ' · ' + p.pending + ' en espera' : '');
                        err.textContent = p.error || (p.errors && p.errors.length ? 'Error: ' + p.errors.join(' · ') : '');
                        if (!p.pending) { h.textContent = p.failed && !p.sent ? 'No se pudo enviar' : '¡Listo!'; note.textContent = p.sent ? 'Los vas a ver en "Enviados" de tu Gmail.' : ''; mail.sentToday += p.sent; return true; }
                        if (p.paused) { h.textContent = 'Se alcanzó el límite de hoy'; note.textContent = 'Los que faltan salen mañana automáticamente.'; return true; }
                        if (p.error) { h.textContent = 'El envío está en pausa'; note.textContent = 'Sigue solo cuando se resuelva (revisa la conexión con Google en OEC → Configuración).'; return true; }
                        h.textContent = 'Enviando… ' + done + ' de ' + total;
                        return false;
                    }
                    if (draw(p)) return;
                    (function tick() {
                        mailPost({ action: 'oec_mail_progress', campaign: cid }).then(function (res) {
                            if (res && res.success && draw(res.data)) return;
                            poll = setTimeout(tick, 800);
                        }).catch(function () { poll = setTimeout(tick, 3000); });
                    })();
                }

                function mailPost(data) {
                    data.nonce = mail.nonce;
                    return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(data) }).then(function (r) { return r.json(); });
                }

                writeBtn.addEventListener('click', compose);
                backBtn.addEventListener('click', function () {
                    if (poll) { clearTimeout(poll); poll = null; }
                    if (body.querySelector('.oec-st-progress:not(.oec-st-serial)')) loadContacts(); else showList();
                });
                dlg.addEventListener('close', function () { if (poll) { clearTimeout(poll); poll = null; } });
                copyBtn.addEventListener('click', function () {
                    copyText(emails.join(', '), function () { copyBtn.lastChild.textContent = ' ¡Copiados ' + emails.length + '!'; setTimeout(function () { copyBtn.lastChild.textContent = ' Copiar emails'; }, 2000); });
                });
                document.getElementById('oec-st-close').addEventListener('click', function () { dlg.close(); });
                dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
            })();
            </script>
            <?php
        }

        /** Lo que necesita la ventana de contactos (cuenta de Google, plantillas, WhatsApp, estados). */
        private static function mail_config() {
            return [
                'nonce'     => wp_create_nonce('oec_mail'),
                'google'    => class_exists('OEC_Mailer') ? OEC_Mailer::google_email() : '',
                'broken'    => class_exists('OEC_Mailer') && OEC_Mailer::google_broken(),
                'limit'     => class_exists('OEC_Mailer') ? OEC_Mailer::daily_limit() : 0,
                'sentToday' => class_exists('OEC_Mailer') ? OEC_Mailer::sent_today() : 0,
                'configUrl' => admin_url('admin.php?page=oec-main#oec-gmail'),
                'templates' => class_exists('OEC_Mailer') ? (object) OEC_Mailer::templates() : (object) [],
                'wa'        => class_exists('OEC_Contacts') ? OEC_Contacts::wa_message() : '',
                'statuses'  => class_exists('OEC_Contacts') ? (object) OEC_Contacts::STATUSES : (object) [],
                'siteUrl'   => trailingslashit(home_url('/')),
            ];
        }

        /**
         * Una página de formaciones (abiertas o todas) de la API de OEC, cacheada 1 h.
         * Una página de la pantalla = una página de la API (30 formaciones).
         */
        private static function get_trainings($token, $page, $search, $all) {
            $key    = 'oec_stats_cache_' . md5($page . '|' . $search . '|' . ($all ? 1 : 0));
            $cached = get_option($key);
            if ($cached && isset($cached['expires']) && $cached['expires'] > time()) {
                return $cached['data'];
            }

            $url = 'https://oas-api.onlineeducation.center/api-oas/v1/trainings?' . ($all ? 'relevance=0' : 'enrollment=opened')
                 . '&pg=' . intval($page) . ($search !== '' ? '&search=' . rawurlencode($search) : '');
            $response = wp_remote_get($url, [
                'headers' => ['X-API-TOKEN' => $token, 'Accept' => 'application/json'],
                'timeout' => class_exists('OEC_Api') ? OEC_Api::TIMEOUT : 8,
            ]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
                return ['rows' => [], 'total' => 0, 'total_pages' => 0, 'error' => true];
            }

            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            $rows    = array_values(array_filter($decoded['data'] ?? [], function ($t) { return self::valid_uid($t['id'] ?? ''); }));
            $pag     = $decoded['meta']['pagination'] ?? [];
            $data    = [
                'rows'        => $rows,
                'total'       => intval($pag['total'] ?? count($rows)),
                'total_pages' => intval($pag['total_pages'] ?? 1),
                'error'       => false,
            ];
            if ($rows) update_option($key, ['data' => $data, 'expires' => time() + HOUR_IN_SECONDS], false);
            return $data;
        }

        /**
         * Días desde hoy (zona horaria del sitio) hasta una fecha de la API; null si no hay.
         * Se usa el día calendario tal cual viene ("2026-09-29T00:00:00+00:00" = el 29):
         * pasarlo a la hora local lo corría al día anterior en América.
         */
        private static function days_until($date) {
            if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}/', $date, $m)) return null;
            try {
                $tz    = wp_timezone();
                $today = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
                return (int) $today->diff(new DateTimeImmutable($m[0], $tz))->format('%r%a');
            } catch (Exception $e) {
                return null;
            }
        }

        // === NÚMEROS ========================================================

        private static function counts_key($uid) {
            return 'oec_stats_cache_z_' . md5($uid);
        }

        /** ['edition' => …, 'stages' => [...]] ya cacheado, o null. */
        private static function cached_counts($uid) {
            $cached = get_option(self::counts_key($uid));
            return ($cached && isset($cached['expires']) && $cached['expires'] > time()) ? $cached['data'] : null;
        }

        public static function ajax_counts() {
            check_ajax_referer('oec_stats', 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error(null, 403);

            $t = [
                'id'               => sanitize_text_field(wp_unslash($_POST['id'] ?? '')),
                'title'            => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
                'enrollments_goal' => intval($_POST['goal'] ?? 0),
                'slug'             => sanitize_title(wp_unslash($_POST['slug'] ?? '')),
            ];
            if (!self::valid_uid($t['id'])) wp_send_json_error(null, 400);

            $counts = self::cached_counts($t['id']);
            if ($counts === null) {
                $edition = self::edition_uid($t['id']);
                [$code, $body] = self::remote('counts', $t['id'], $edition);
                if ($code !== 200 || !isset($body['stages'])) {
                    wp_send_json_error(['html' => '<div class="oec-st-missing">' . esc_html(self::error_text($code)) . '</div><div></div>']);
                }
                $counts = ['edition' => $edition, 'stages' => array_map('intval', $body['stages'])];
                update_option(self::counts_key($t['id']), ['data' => $counts, 'expires' => time() + self::COUNTS_TTL], false);
            }
            wp_send_json_success(['html' => self::render_counts($t, $counts)]);
        }

        /** Embudo + meta de alumnos de una formación (las dos celdas de la derecha de la fila). */
        private static function render_counts($t, $counts) {
            $edition = $counts['edition'] ?? '';
            $stages  = $counts['stages'] ?? [];
            $html    = '<div class="oec-st-funnel">';
            foreach (self::STAGES as $stage => $label) {
                // Un endpoint de antes de "Lost" no trae la etapa: "–" en vez de un 0 falso.
                $known = isset($stages[$stage]);
                $val   = intval($stages[$stage] ?? 0);
                $lost  = $stage === 'Lost';
                $cls   = 'oec-st-step' . ($stage === 'Hot Prospects' ? ' oec-st-step--hot' : '') . ($stage === 'Students - Won' ? ' oec-st-step--final' : '') . ($lost ? ' oec-st-step--lost' : '') . ($val === 0 ? ' is-zero' : '');
                $tip   = $lost ? 'Descartados por el equipo de ventas de OEC: pueden seguir siendo una oportunidad para ti.' : '';
                $in    = '<span class="oec-st-num">' . ($known ? esc_html(number_format_i18n($val)) : '–') . '</span><span class="oec-st-lbl">' . esc_html($label) . '</span>';
                if ($val > 0) {
                    $html .= '<button type="button" class="' . esc_attr($cls) . '" data-stage="' . esc_attr($stage) . '" data-id="' . esc_attr($t['id']) . '" data-edition="' . esc_attr($edition) . '" data-title="' . esc_attr($t['title'] ?? '') . '" data-slug="' . esc_attr($t['slug'] ?? '') . '" data-count="' . $val . '" title="' . esc_attr($tip ? $tip . ' Ver contactos.' : 'Ver contactos') . '">' . $in . '</button>';
                } else {
                    $html .= '<div class="' . esc_attr($cls) . '"' . ($tip ? ' title="' . esc_attr($tip) . '"' : '') . '>' . $in . '</div>';
                }
            }
            $html .= '</div><div class="oec-st-goal">';

            $won  = intval($stages['Students - Won'] ?? 0);
            $goal = intval($t['enrollments_goal'] ?? 0);
            if ($goal > 0) {
                $pct   = min(100, (int) round($won * 100 / $goal));
                $html .= '<div class="oec-st-goal-top"><span>Meta de alumnos</span><span><strong>' . esc_html(number_format_i18n($won)) . '</strong> / ' . esc_html(number_format_i18n($goal)) . '</span></div>'
                       . '<div class="oec-st-bar"><span style="width:' . $pct . '%;"></span></div>'
                       . '<div class="oec-st-goal-note">' . $pct . '% de la meta</div>';
            } else {
                $html .= '<div class="oec-st-goal-note">Sin meta de alumnos cargada.</div>';
            }
            return $html . '</div>';
        }

        // === CONTACTOS ======================================================

        /** Contactos de una etapa, validados y limpios. [contactos, truncated] o wp_send_json_error. */
        private static function fetch_contacts($uid, $edition, $stage, $as_json = true) {
            [$code, $body] = self::remote('contacts', $uid, $edition, $stage);
            if ($code !== 200 || !isset($body['contacts'])) {
                if ($as_json) wp_send_json_error(['message' => self::error_text($code)]);
                wp_die(esc_html(self::error_text($code)), 502);
            }
            return [self::clean_contacts($body['contacts']), !empty($body['truncated'])];
        }

        /** Para OEC_Mailer: los contactos de una etapa, o null si no se pudieron traer. */
        public static function contacts_for($uid, $edition, $stage) {
            if (!self::valid_uid($uid) || !isset(self::STAGES[$stage]) || ($edition !== '' && !self::valid_edition($edition))) return null;
            [$code, $body] = self::remote('contacts', $uid, $edition, $stage);
            return ($code === 200 && isset($body['contacts'])) ? self::clean_contacts($body['contacts']) : null;
        }

        private static function clean_contacts(array $list) {
            $out = [];
            foreach ($list as $c) {
                $email = sanitize_email($c['email'] ?? '');
                $out[] = [
                    'first_name' => sanitize_text_field($c['first_name'] ?? ''),
                    'last_name'  => sanitize_text_field($c['last_name'] ?? ''),
                    'name'       => sanitize_text_field($c['name'] ?? ''),
                    'email'      => is_email($email) ? $email : '',
                    'phone'      => sanitize_text_field($c['phone'] ?? ''),
                    'country'    => sanitize_text_field($c['country'] ?? ''),
                    'created'    => sanitize_text_field($c['created'] ?? ''),
                ];
            }
            return $out;
        }

        private static function request_params($src) {
            $uid     = sanitize_text_field(wp_unslash($src['id'] ?? ''));
            $edition = sanitize_text_field(wp_unslash($src['edition'] ?? ''));
            $stage   = wp_unslash($src['stage'] ?? '');
            if (!self::valid_uid($uid) || !isset(self::STAGES[$stage])) return null;
            if ($edition !== '' && !self::valid_edition($edition)) return null;
            return [$uid, $edition, $stage];
        }

        public static function ajax_contacts() {
            check_ajax_referer('oec_stats', 'nonce');
            if (!current_user_can('manage_options')) wp_send_json_error(null, 403);
            $p = self::request_params($_POST);
            if (!$p) wp_send_json_error(null, 400);

            [$contacts, $truncated] = self::fetch_contacts($p[0], $p[1], $p[2]);
            // Historial de envíos de esta formación y bajas, para marcar a quién ya se le escribió.
            if (class_exists('OEC_Mailer')) {
                $emails = array_filter(array_column($contacts, 'email'));
                $hist   = OEC_Mailer::history($p[0], $emails);
                $unsubs = array_flip(OEC_Mailer::unsubscribed($emails));
                foreach ($contacts as &$c) {
                    $e = strtolower($c['email']);
                    $c['sent']       = $hist[$e]['sent'] ?? null;
                    $c['queued']     = !empty($hist[$e]['queued']);
                    $c['unsub']      = isset($unsubs[$e]);
                }
                unset($c);
            }
            if (class_exists('OEC_Contacts')) $contacts = OEC_Contacts::enrich($p[0], $contacts);
            wp_send_json_success(['contacts' => $contacts, 'truncated' => $truncated]);
        }

        /** Excel/Sheets ejecutan celdas que empiezan con = + - @: se neutralizan. */
        private static function csv_cell($v) {
            $v = (string) $v;
            return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
        }

        public static function download_csv() {
            self::require_admin();
            check_admin_referer('oec_stats');
            $p = self::request_params($_GET);
            if (!$p) wp_die('Pedido inválido', 400);
            [$uid, $edition, $stage] = $p;

            [$contacts] = self::fetch_contacts($uid, $edition, $stage, false);

            $file = sanitize_file_name(strtolower(str_replace(' ', '-', $stage)) . '-' . $uid . '-' . wp_date('Y-m-d') . '.csv');
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file . '"');

            $out = fopen('php://output', 'w');
            self::write_csv($out, $contacts, $stage);
            fclose($out);
            exit;
        }

        /** Escribe el CSV (con BOM) en $out. */
        public static function write_csv($out, array $contacts, $stage) {
            fwrite($out, "\xEF\xBB\xBF"); // BOM: acentos bien en Excel
            // Encabezados que reconocen Google Sheets/GMass (merge tags) y Google Contacts.
            fputcsv($out, ['First Name', 'Last Name', 'Email', 'Phone', 'Country', 'Stage', 'Created']);
            foreach ($contacts as $c) {
                $row = array_map([__CLASS__, 'csv_cell'], [
                    $c['first_name'] !== '' ? $c['first_name'] : $c['name'],
                    $c['last_name'],
                    $c['email'],
                    $c['phone'],
                    $c['country'],
                    self::STAGES[$stage],
                    substr($c['created'], 0, 10),
                ]);
                // Con código de país si se pudo reparar ("+5491150084412"); un teléfono de
                // verdad no es una fórmula: sin apóstrofo.
                $e164 = class_exists('OEC_Contacts') ? OEC_Contacts::normalize_phone($c['phone'], $c['country']) : '';
                if ($e164 !== '') $row[3] = '+' . $e164;
                elseif (preg_match('/^\+?[\d\s().-]+$/', $c['phone'])) $row[3] = $c['phone'];
                fputcsv($out, $row);
            }
        }

        /** "Actualizar datos" y "Actualizar caché" de Configuración. */
        public static function clear_cache() {
            global $wpdb;
            $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'oec\\_stats\\_cache\\_%'");
            foreach ($names as $name) delete_option($name);
        }
    }
}
