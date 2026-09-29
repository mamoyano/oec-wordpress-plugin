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
                .oec-st-funnel { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); border: 1px solid #f0f0f1; border-radius: 8px; overflow: hidden; }
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
                .oec-st-dlg { width: min(1000px, calc(100vw - 32px)); max-height: calc(100vh - 64px); padding: 0; border: 0; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
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
                                <div class="oec-st-metrics oec-st-loading" data-id="<?php echo esc_attr($t['id']); ?>" data-title="<?php echo esc_attr($t['title'] ?? ''); ?>" data-goal="<?php echo intval($t['enrollments_goal'] ?? 0); ?>">
                                    <div class="oec-st-funnel oec-st-skel"><?php for ($i = 0; $i < 5; $i++) : ?><div class="oec-st-step"><span class="oec-st-num">&nbsp;</span><span class="oec-st-lbl">&nbsp;</span></div><?php endfor; ?></div>
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
                            <button type="button" class="button" id="oec-st-copy" disabled><span class="dashicons dashicons-admin-page" style="margin-top:4px;"></span> Copiar emails</button>
                            <a class="button button-primary" id="oec-st-csv" href="#"><span class="dashicons dashicons-download" style="margin-top:4px;"></span> Descargar CSV</a>
                            <button type="button" class="button button-link" id="oec-st-close" aria-label="Cerrar"><span class="dashicons dashicons-no-alt" style="font-size:22px;"></span></button>
                        </div>
                    </div>
                    <div class="oec-st-dlg-body" id="oec-st-dlg-body"></div>
                    <div class="oec-st-dlg-foot">Estas personas aceptaron compartir sus datos con los organizadores de la formación. Úsalos solo para comunicaciones sobre ella. El CSV se puede importar en Google Sheets (y desde ahí en GMass) o en Google Contacts.</div>
                </div>
            </dialog>

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
                    post({ action: 'oec_stats_counts', id: box.dataset.id, title: box.dataset.title, goal: box.dataset.goal })
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
                var emails = [];

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
                function table(list) {
                    var t = el('table', { 'class': 'oec-st-table' });
                    var h = el('tr');
                    ['Nombre', 'Email', 'Teléfono', 'País', 'Fecha'].forEach(function (c, i) {
                        h.appendChild(el('th', i >= 3 ? { 'class': 'oec-st-col-opt' } : null, c));
                    });
                    t.appendChild(el('thead')).appendChild(h);
                    var tb = t.appendChild(el('tbody'));
                    list.forEach(function (c) {
                        var tr = tb.appendChild(el('tr'));
                        tr.appendChild(el('td', null, c.name || '—'));
                        var tdE = tr.appendChild(el('td'));
                        if (c.email) tdE.appendChild(el('a', { href: 'mailto:' + c.email }, c.email)); else tdE.appendChild(el('span', { 'class': 'oec-st-dim' }, '—'));
                        var tdP = tr.appendChild(el('td'));
                        var digits = (c.phone || '').replace(/\D/g, '');
                        if (digits) tdP.appendChild(el('a', { href: 'https://wa.me/' + digits, target: '_blank', rel: 'noopener', 'class': 'oec-st-wa', title: 'Escribir por WhatsApp' }, c.phone));
                        else tdP.appendChild(el('span', { 'class': 'oec-st-dim' }, '—'));
                        tr.appendChild(el('td', { 'class': 'oec-st-col-opt' }, c.country || ''));
                        tr.appendChild(el('td', { 'class': 'oec-st-col-opt' }, fmtDate(c.created)));
                    });
                    return t;
                }

                document.addEventListener('click', function (e) {
                    var b = e.target.closest('button.oec-st-step[data-stage]');
                    if (!b) return;
                    var stage = b.dataset.stage;
                    document.getElementById('oec-st-dlg-title').textContent = labels[stage] + ' (' + b.dataset.count + ')';
                    document.getElementById('oec-st-dlg-sub').textContent = b.dataset.title;
                    document.getElementById('oec-st-csv').href = csvUrl + '?' + new URLSearchParams({ action: 'oec_stats_csv', id: b.dataset.id, edition: b.dataset.edition, stage: stage, _wpnonce: nonce });
                    emails = []; copyBtn.disabled = true;
                    msg('Cargando contactos…');
                    dlg.showModal();
                    post({ action: 'oec_stats_contacts', id: b.dataset.id, edition: b.dataset.edition, stage: stage }).then(function (res) {
                        if (!res || !res.success) { msg((res && res.data && res.data.message) || 'No se pudieron cargar los contactos.'); return; }
                        var list = res.data.contacts || [];
                        if (!list.length) { msg('No hay contactos en esta etapa.'); return; }
                        emails = list.map(function (c) { return c.email; }).filter(Boolean);
                        copyBtn.disabled = !emails.length;
                        body.replaceChildren(table(list));
                        if (res.data.truncated) body.appendChild(el('p', { 'class': 'oec-st-dim' }, 'Se muestran los primeros 2.000 contactos.'));
                    }).catch(function () { msg('No se pudieron cargar los contactos.'); });
                });

                copyBtn.addEventListener('click', function () {
                    var text = emails.join(', ');
                    var done = function () { copyBtn.lastChild.textContent = ' ¡Copiados ' + emails.length + '!'; setTimeout(function () { copyBtn.lastChild.textContent = ' Copiar emails'; }, 2000); };
                    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done);
                    else { var ta = el('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done(); }
                });
                document.getElementById('oec-st-close').addEventListener('click', function () { dlg.close(); });
                dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
            })();
            </script>
            <?php
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
                $val = intval($stages[$stage] ?? 0);
                $cls = 'oec-st-step' . ($stage === 'Hot Prospects' ? ' oec-st-step--hot' : '') . ($stage === 'Students - Won' ? ' oec-st-step--final' : '') . ($val === 0 ? ' is-zero' : '');
                $in  = '<span class="oec-st-num">' . esc_html(number_format_i18n($val)) . '</span><span class="oec-st-lbl">' . esc_html($label) . '</span>';
                if ($val > 0) {
                    $html .= '<button type="button" class="' . esc_attr($cls) . '" data-stage="' . esc_attr($stage) . '" data-id="' . esc_attr($t['id']) . '" data-edition="' . esc_attr($edition) . '" data-title="' . esc_attr($t['title'] ?? '') . '" data-count="' . $val . '" title="Ver contactos">' . $in . '</button>';
                } else {
                    $html .= '<div class="' . esc_attr($cls) . '">' . $in . '</div>';
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
            $out = [];
            foreach ($body['contacts'] as $c) {
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
            return [$out, !empty($body['truncated'])];
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
                // Un teléfono de verdad ("+54 9 11 …") no es una fórmula: sin apóstrofo.
                if (preg_match('/^\+?[\d\s().-]+$/', $c['phone'])) $row[3] = $c['phone'];
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
