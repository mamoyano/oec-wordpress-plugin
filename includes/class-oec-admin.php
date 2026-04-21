<?php
class OEC_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menus']);
    }

    public function add_menus() {
        add_menu_page('OEC', 'Online Education Center', 'manage_options', 'oec-main', [$this, 'page_config'], 'dashicons-welcome-learn-more', 20);
        add_submenu_page('oec-main', 'Configuración', 'Configuración', 'manage_options', 'oec-main', [$this, 'page_config']);
        add_submenu_page('oec-main', 'Campus Virtual', 'Campus Virtual', 'manage_options', 'oec-campus', [$this, 'page_campus']);
        add_submenu_page('oec-main', 'Estadísticas', 'Estadísticas', 'manage_options', 'oec-stats', [$this, 'page_stats']);
        add_submenu_page('oec-main', 'Cuenta Contable', 'Cuenta Contable', 'manage_options', 'oec-account', [$this, 'page_account']);
        add_submenu_page('oec-main', 'Mesa de Ayuda', 'Mesa de Ayuda', 'manage_options', 'oec-help', [$this, 'page_help']);
        add_submenu_page('oec-main', 'Documentación', 'Documentación', 'manage_options', 'oec-docs', [$this, 'page_docs']);
        add_submenu_page('oec-main', 'Contáctenos', 'Contáctenos', 'manage_options', 'oec-contact', [$this, 'page_contact']);

        // Suprimir notices de otros plugins en todas las páginas de OEC
        add_action('admin_head', function() {
            $page = $_GET['page'] ?? '';
            if (str_starts_with($page, 'oec-')) {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            }
        });
    }


    /**
     * MOTOR REUTILIZABLE: Renderiza el sistema de auth + iframe para cualquier slug
     * @param string $title Título de la página
     * @param string $target_url URL a incrustar
     * @param bool $requires_auth Si es falso, carga el iframe directamente
     */
    public function render_oec_iframe($title, $target_url, $requires_auth = true) {
        $current_domain = home_url(); 
        $auth_url = "https://account.onlineeducation.center/wp-login?urlorigin=" . urlencode($current_domain);
        ?>
        <div class="wrap oec-wrapper-page" data-target="<?php echo esc_url($target_url); ?>" data-auth="<?php echo $requires_auth ? 'true' : 'false'; ?>">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1 style="margin: 0;"><?php echo esc_html($title); ?></h1>
                
                <div class="oec-page-actions" style="display: flex; gap: 8px;">

                    <?php if ($requires_auth) : ?>
                    <button onclick="clearOECSession();" 
                            class="button button-small oec-btn-reconnect" 
                            style="display:none; border-color:#d63638; color:#d63638; background: #fff8f7;">
                        <span class="dashicons dashicons-update" style="font-size: 14px; margin-top: 4px;"></span> 
                        Reconectar aquí
                    </button>
                    <?php endif; ?>

                    <a href="<?php echo esc_url($target_url); ?>" 
                       target="_blank" 
                       class="button button-small" 
                       title="Abrir en una nueva pestaña">
                        <span class="dashicons dashicons-external" style="font-size: 14px; margin-top: 4px;"></span> 
                        En otra pestaña
                    </a>


                </div>
            </div>
            
            <div class="oec-main-container" style="background:#fff; border:1px solid #ccd0d4; padding:20px; text-align:center; border-radius:8px; min-height: 450px; position: relative; overflow: hidden;">
                
                <?php if ($requires_auth) : ?>
                    <div class="oec-login-block" style="display:none; padding:60px 0;">
                        <span class="dashicons dashicons-lock" style="font-size:50px; width:50px; height:50px; color:#a435f0; margin-bottom:20px;"></span>
                        <h2>Sincronización de Sesión</h2>
                        <p>Haz clic abajo para iniciar sesión en <strong><?php echo esc_html($title); ?></strong>.</p>
                        <button class="button button-primary button-large oec-btn-auth">Iniciar Sesión Segura</button>
                    </div>
                <?php endif; ?>

                <div class="oec-loading-block" style="padding:100px 0;">
                    <span class="spinner is-active" style="float:none; margin-bottom:10px; width:30px; height:30px;"></span>
                    <p>Cargando plataforma...</p>
                </div>

                <iframe class="oec-content-frame" 
                        src="about:blank" 
                        style="width:100%; height:calc(100dvh - 180px); border:none; display:none;">
                </iframe>
            </div>
        </div>

        <script>
        (function() {
            const wrapper = document.querySelector('.oec-wrapper-page[data-target="<?php echo $target_url; ?>"]');
            const requiresAuth = wrapper.dataset.auth === 'true';
            
            const btnAuth = wrapper.querySelector('.oec-btn-auth');
            const loginBlock = wrapper.querySelector('.oec-login-block');
            const loadingBlock = wrapper.querySelector('.oec-loading-block');
            const frame = wrapper.querySelector('.oec-content-frame');
            const btnReconnect = wrapper.querySelector('.oec-btn-reconnect');
            
            const authUrl = "<?php echo $auth_url; ?>";
            const targetUrl = "<?php echo $target_url; ?>";

            function activateFrame() {
                if(loginBlock) loginBlock.style.display = 'none';
                loadingBlock.style.display = 'block';
                if(btnReconnect) btnReconnect.style.display = 'inline-block'; 
                
                frame.src = targetUrl;
                frame.onload = function() {
                    loadingBlock.style.display = 'none';
                    frame.style.display = 'block';
                };
            }

            window.clearOECSession = function() {
                localStorage.removeItem('oec_validated');
                location.reload();
                
            };

            if (!window.oecMessageListenerSet) {
                window.addEventListener('message', (event) => {
                    if (event.origin !== "https://account.onlineeducation.center") return;
                    if (event.data.success) {
                        localStorage.setItem('oec_validated', 'true');
                        location.reload();
                    }
                });
                window.oecMessageListenerSet = true;
            }

            // Lógica de decisión: ¿Cargamos directo o pedimos Auth?
            if (!requiresAuth || localStorage.getItem('oec_validated') === 'true') {
                activateFrame();
            } else {
                loadingBlock.style.display = 'none';
                if(loginBlock) loginBlock.style.display = 'block';
                if(btnReconnect) btnReconnect.style.display = 'none';
            }

            if(btnAuth) {
                btnAuth.addEventListener('click', function() {
                    window.open(authUrl, 'OECAuth', 'width=600,height=700');
                });
            }
        })();
        </script>
        <?php
    }



    public function page_config() {
        // 1. PROCESAR GUARDADO MANUAL
        if (isset($_POST['oec_save'])) {
            update_option('oec_token', sanitize_text_field($_POST['oec_token']));
            update_option('oec_brand_color', sanitize_hex_color($_POST['oec_brand_color']));

            // Equipo de ventas
            $equipo_oec = isset($_POST['oec_equipo_ventas']) ? '1' : '0';
            update_option('oec_equipo_ventas', $equipo_oec);
            if ($equipo_oec === '0') {
                update_option('oec_ventas_whatsapp', sanitize_text_field($_POST['oec_ventas_whatsapp'] ?? ''));
                update_option('oec_ventas_email', sanitize_email($_POST['oec_ventas_email'] ?? ''));
            }
            
            // Forzamos limpieza de caché para validar el nuevo token
            delete_transient('oec_api_cache_data2');
            
            echo '<div class="updated" style="margin: 20px 0;"><p>Configuración guardada.</p></div>';
        }
        
        $pages_just_created = false;
        $token = get_option('oec_token');
        $color = get_option('oec_brand_color', '#a435f0');

        // 2. LÓGICA DE CACHÉ Y VALIDACIÓN DE API
        $cached_data = get_transient('oec_api_cache_data2');
        $api_error = false;

        if (false === $cached_data && $token) {
            $list = OEC_Api::call('trainings?pagination=1');
            
            // VERIFICAR SI LA API DEVOLVIÓ ERROR (401, 403, etc)
            if (isset($list['error']) || !$list) {
                $api_error = true;
                delete_transient('oec_api_cache_data2'); // No guardamos errores en caché
            } else {
                $first_id = (!empty($list) && isset($list[0]['id'])) ? $list[0]['id'] : 't-n5adc84f2e3786';
                $content = OEC_Api::call('trainings/' . $first_id);

                $cached_data = [
                    'list'    => $list,
                    'content' => $content
                ];

                set_transient('oec_api_cache_data2', $cached_data, 72 * 3600);
                
                // GUARDAR DATOS EN LA DB
                if (isset($content['organization']['data']['subdomain'])) {
                    update_option('oec_subdomain', $content['organization']['data']['subdomain']);
                }
                if (isset($content['key_account_manager'])) {
                    update_option('oec_kam_name', $content['key_account_manager']['full_name']);
                    update_option('oec_kam_email', $content['key_account_manager']['email']);
                    update_option('oec_kam_phone', $content['key_account_manager']['phone_number']);
                    update_option('oec_kam_avatar', $content['key_account_manager']['image'] ?? '');
                }

                // CREAR PÁGINAS OEC (solo la primera vez que el token es válido)
                $pages_just_created = $this->oec_create_pages();
            }
        }

        // Recuperar opciones para la vista
        $sub = get_option('oec_subdomain', 'esperando...');
        $equipo_oec    = get_option('oec_equipo_ventas', '1');
        $ventas_wa     = get_option('oec_ventas_whatsapp', '');
        $ventas_email  = get_option('oec_ventas_email', '');
        $test_data_list = $cached_data['list'] ?? null;
        $test_data_content = $cached_data['content'] ?? null;

        // DECISIÓN DE PANTALLA: Si no hay token O hay error de API, mostramos activación
        $show_activation = empty($token) || $api_error;
        ?>
        
        <div class="wrap">
            <header style="margin-bottom: 40px;">
                <img src="https://onlineeducation.center/wp-content/uploads/2024/12/online-education.center.webp" alt="OEC" style="max-height: 75px;">
                <p class="about-description" style="margin: 0; font-size: 16px;">
                    Portal avanzado para gestionar tus formaciones, tu dinero, comunicaciones, estadísticas y mucho más.
                </p>
            </header>

            <?php if ( $pages_just_created ) : ?>
                <div style="background: #edfaef; border: 1px solid #00a32a; border-left: 4px solid #00a32a; padding: 16px 20px; border-radius: 4px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 14px;">
                    <span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 26px; width: 26px; height: 26px; flex-shrink: 0; margin-top: 2px;"></span>
                    <div>
                        <p style="margin: 0 0 6px; font-weight: 600; font-size: 14px; color: #1a3d1f;">¡Páginas creadas correctamente!</p>
                        <p style="margin: 0; font-size: 13px; color: #2d6a35; line-height: 1.5;">
                            Se crearon dos páginas en borrador en tu WordPress:<br>
                            <strong>Formaciones</strong> (slug: <code>oec-formaciones</code>) y <strong>Formación</strong> (slug: <code>oec-formacion</code>).<br>
                            Revisalas y publicalas desde 
                            <a href="<?php echo admin_url('edit.php?post_type=page'); ?>" style="color: #00a32a; font-weight: 600;">Páginas → Todos los borradores</a>.
                        </p>
                    </div>
                </div>
                <div style="background: #fff8e5; border: 1px solid #dba617; border-left: 4px solid #dba617; padding: 16px 20px; border-radius: 4px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 14px;">
                    <span class="dashicons dashicons-warning" style="color: #dba617; font-size: 26px; width: 26px; height: 26px; flex-shrink: 0; margin-top: 2px;"></span>
                    <div>
                        <p style="margin: 0 0 6px; font-weight: 600; font-size: 14px; color: #6b4c00;">⚠️ Paso obligatorio: regenerar los enlaces permanentes</p>
                        <p style="margin: 0; font-size: 13px; color: #7a5800; line-height: 1.5;">
                            Para que las URLs de las formaciones funcionen correctamente, debés regenerar los enlaces permanentes de WordPress.<br><br>
                            <strong>Hacé clic acá ahora:</strong>
                            <a href="<?php echo admin_url('options-permalink.php'); ?>" style="color: #7a5800; font-weight: 700; text-decoration: underline;">
                                Ajustes → Enlaces permanentes
                            </a>
                            → y luego clic en <strong>"Guardar cambios"</strong> sin modificar nada.<br><br>
                            Sin este paso las páginas van a dar <strong>error 404</strong>.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( $show_activation ) : ?>
                <div class="card" style="max-width: 600px; margin: 40px auto; padding: 40px; text-align: center; border: 1px solid #ccd0d4;">
                    <?php if ($api_error) : ?>
                        <div style="background: #fbeaea; color: #d63638; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #d63638;">
                            <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
                            <strong>Error de Autenticación:</strong> El token ingresado es inválido o ha expirado.
                        </div>
                    <?php endif; ?>

                    <span class="dashicons dashicons-lock" style="font-size: 60px; width: 60px; height: 60px; color: #ccc; margin-bottom: 20px;"></span>
                    <h2 style="font-size: 24px;">Activar Online Education Center</h2>
                    <p style="font-size: 16px; color: #666; line-height: 1.5;">
                        Pídale su OEC API Token a su Key Account Manager.
                    </p>
                    <hr style="margin: 30px 0;">
                    <form method="post">
                        <table class="form-table" style="text-align: left;">
                            <tr>
                                <th scope="row" style="width: 150px;">OEC API Token</th>
                                <td>
                                    <input type="password" name="oec_token" value="<?php echo esc_attr($token); ?>" class="regular-text" style="width: 100%;" placeholder="Ingrese su token aquí" required>
                                </td>
                            </tr>
                        </table>
                        <div style="margin-top: 20px;">
                            <?php submit_button('Activar Plugin', 'primary', 'oec_save', false); ?>
                        </div>
                    </form>
                </div>

            <?php else : ?>
                <h2 style="margin-top: 30px;">Gestión de Herramientas</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-top: 15px;">
                    <div class="card" style="margin: 0; padding: 20px;">
                        <span class="dashicons dashicons-admin-appearance" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $color; ?>;"></span>
                        <h3>Campus Virtual</h3>
                        <p>Accede al campus virtual para enviar nuevas formaciones, gestionarlas, administrar alumnos, clases, exámenes y mucho más.</p>
                        <a href="<?php echo admin_url('admin.php?page=oec-campus'); ?>" class="button button-secondary">Ir al Campus</a>
                    </div>

                    <div class="card" style="margin: 0; padding: 20px;">
                        <span class="dashicons dashicons-chart-bar" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $color; ?>;"></span>
                        <h3>Estadísticas</h3>
                        <p>Visualiza el rendimiento de tus formaciones, métricas de inscritos y progreso general.</p>
                        <a href="<?php echo admin_url('admin.php?page=oec-stats'); ?>" class="button button-secondary">Ver Reportes</a>
                    </div>

                    <div class="card" style="margin: 0; padding: 20px;">
                        <span class="dashicons dashicons-money-alt" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $color; ?>;"></span>
                        <h3>Cuenta Contable</h3>
                        <p>Gestiona tu dinero, revisa estados de cuenta, transferencias y detalles de pagos realizados.</p>
                        <a href="<?php echo admin_url('admin.php?page=oec-account'); ?>" class="button button-secondary">Ver Facturación</a>
                    </div>

                    <div class="card" style="margin: 0; padding: 20px;">
                        <span class="dashicons dashicons-sos" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $color; ?>;"></span>
                        <h3>Mesa de Ayuda</h3>
                        <p>Responde las consultas privadas de todos tus alumnos en todas tus formaciones.</p>
                        <a href="<?php echo admin_url('admin.php?page=oec-help'); ?>" class="button button-secondary">Solicitar Soporte</a>
                    </div>

                    <div class="card" style="margin: 0; padding: 20px;">
                        <span class="dashicons dashicons-media-document" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $color; ?>;"></span>
                        <h3>Documentación</h3>
                        <p>Consulta manuales, guías de uso y bases de conocimiento sobre la plataforma.</p>
                        <a href="<?php echo admin_url('admin.php?page=oec-docs'); ?>" class="button button-secondary">Leer Guías</a>
                    </div>

                    <div class="card" style="margin: 0; padding: 20px;">
                        <span class="dashicons dashicons-email" style="font-size: 40px; width: 40px; height: 40px; color: <?php echo $color; ?>;"></span>
                        <h3>Contáctanos</h3>
                        <p>Habla directamente con tu Key Account Manager asignado para atención personalizada.</p>
                        <a href="<?php echo admin_url('admin.php?page=oec-contact'); ?>" class="button button-secondary">Vías de Contacto</a>
                    </div>
                </div>

                <hr style="margin: 40px 0;">
                
                <div style="width: 100%;">
                    <div style="background: #fff; padding: 30px; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h2 style="margin-top:0;">Configuración de Conexión</h2>
                        <form method="post">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">OEC API Token</th>
                                    <td>
                                        <input type="password" name="oec_token" value="<?php echo esc_attr($token); ?>" class="regular-text" required>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Subdominio OEC</th>
                                    <td>
                                        <strong>https://</strong> 
                                        <input type="text" value="<?php echo esc_attr($sub); ?>" readonly style="width: 150px; background: #f0f0f1; border:1px solid #ccc;"> 
                                        <strong>.onlineeducation.center</strong>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Color de Identidad</th>
                                    <td>
                                        <input type="color" name="oec_brand_color" value="<?php echo esc_attr($color); ?>" style="width: 60px; height: 30px; padding: 2px;">
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">¿Equipo de Ventas de OEC?</th>
                                    <td>
                                        <label class="oec-toggle" style="display:inline-flex; align-items:center; gap:10px; cursor:pointer;">
                                            <div style="position:relative; width:46px; height:26px;">
                                                <input type="checkbox" name="oec_equipo_ventas" id="oec_equipo_ventas" value="1" <?php checked($equipo_oec, '1'); ?> style="opacity:0; width:0; height:0; position:absolute;">
                                                <span id="oec-toggle-track" style="position:absolute; inset:0; border-radius:13px; transition:.3s; cursor:pointer; background:<?php echo $equipo_oec === '1' ? '#2271b1' : '#ccc'; ?>;"></span>
                                                <span id="oec-toggle-thumb" style="position:absolute; height:20px; width:20px; left:<?php echo $equipo_oec === '1' ? '23px' : '3px'; ?>; bottom:3px; background:#fff; border-radius:50%; transition:.3s;"></span>
                                            </div>
                                            <span id="oec-toggle-label" style="font-weight:600;"><?php echo $equipo_oec === '1' ? 'SÍ' : 'NO'; ?></span>
                                        </label>
                                        <p class="description" style="margin-top:6px;">Si está en SÍ, se usa el equipo comercial de OEC para los contactos del formulario y WhatsApp.</p>
                                    </td>
                                </tr>
                                <tr id="oec-ventas-fields" style="<?php echo $equipo_oec === '1' ? 'display:none;' : ''; ?>">
                                    <th scope="row">WhatsApp de contacto</th>
                                    <td>
                                        <input type="text" name="oec_ventas_whatsapp" value="<?php echo esc_attr($ventas_wa); ?>" class="regular-text" placeholder="Ej: 5491112345678 (con código de país, sin +)">
                                        <p class="description">Número internacional sin el signo +. Ej: 5491112345678</p>
                                    </td>
                                </tr>
                                <tr id="oec-ventas-email-field" style="<?php echo $equipo_oec === '1' ? 'display:none;' : ''; ?>">
                                    <th scope="row">Email de contacto</th>
                                    <td>
                                        <input type="email" name="oec_ventas_email" value="<?php echo esc_attr($ventas_email); ?>" class="regular-text" placeholder="ventas@tuorganizacion.com">
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button('Actualizar Configuración', 'primary', 'oec_save'); ?>
                        </form>
                        <script>
                        (function(){
                            var cb = document.getElementById('oec_equipo_ventas');
                            var track = document.getElementById('oec-toggle-track');
                            var thumb = document.getElementById('oec-toggle-thumb');
                            var label = document.getElementById('oec-toggle-label');
                            var rows  = [
                                document.getElementById('oec-ventas-fields'),
                                document.getElementById('oec-ventas-email-field')
                            ];
                            cb.addEventListener('change', function(){
                                if (cb.checked) {
                                    track.style.background = '#2271b1';
                                    thumb.style.left = '23px';
                                    label.textContent = 'SÍ';
                                    rows.forEach(function(r){ r.style.display = 'none'; });
                                } else {
                                    track.style.background = '#ccc';
                                    thumb.style.left = '3px';
                                    label.textContent = 'NO';
                                    rows.forEach(function(r){ r.style.display = ''; });
                                }
                            });
                        })();
                        </script>
                    </div>
                </div>

                <hr style="margin: 40px 0;">

                <div style="background: #fff; padding: 30px; border: 1px solid #ccd0d4; border-radius: 4px; margin-top: 20px;">
                    <div style="margin-bottom: 30px;">
                        <h3 style="display: flex; align-items: center; gap: 10px; margin-top: 0;">
                            <span class="dashicons dashicons-editor-code" style="font-size: 24px; width: 24px; height: 24px;"></span> 
                            Desarrollo con Twig 3.x
                        </h3>
                        <p style="font-size: 14px; color: #444; max-width: 800px;">
                            Twig es un motor de plantillas moderno y seguro para PHP que permite separar la lógica de los datos del diseño visual. 
                            Utilice la sintaxis <code>{{ ... }}</code> para imprimir valores y <code>{% ... %}</code> para estructuras lógicas (bucles o condiciones).
                        </p>
                        <p>
                            <strong>Filtros personalizados incluidos:</strong> 
                            <code>format_date</code> (formato de fecha) y <code>|truncate(n)</code> (truncar textos largos en (n) cantidad de caracteres).
                        </p>
                        <a href="https://twig.symfony.com/doc/3.x/" target="_blank" class="button button-secondary">
                            <span class="dashicons dashicons-external" style="font-size: 16px; margin-top: 4px;"></span> Saber más sobre Twig
                        </a>
                    </div>

                    <div style="display: flex; gap: 30px; align-items: flex-start;">
                        
                        <div style="flex: 1; min-width: 0;">
                            <h3 style="margin-top: 0;">Listados de Formaciones</h4>
                            <p class="description" style="margin-bottom: 10px;">Los datos de sus formaciones en los listados son:</p>
                            
                            <pre style="background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; height: 250px; overflow-y: auto; font-size: 11px; white-space: pre-wrap; word-wrap: break-word; margin-bottom: 15px;"><?php 
                                echo $test_data_list ? esc_html(json_encode($test_data_list, JSON_PRETTY_PRINT)) : 'Sin datos disponibles.'; 
                            ?></pre>
                            <p class="description" style="margin-bottom: 10px;">Usted los puede usar en cualquier página, con el shortcode: <code>[oec-list] ... [/oec-list]</code>. Ejemplo funcional: (Objeto <code>data</code>)</p>
                            <pre style="background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; height: 250px; overflow-y: auto; font-size: 11px; white-space: pre-wrap; word-wrap: break-word; margin-bottom: 15px;">
                                <?php 
                                $example_code = '
[oec-list]
    {% for row in data %}
        <h3>{{ row.title }}</h3>
        <p class="description">
            {{ row.short_description|striptags|truncate(250) }}
        </p>
        <a class="link" href="/formacion/{{ row.slug }}">Ver más</a>
    {% endfor %}
[/oec-list]';
                                echo esc_html($example_code); 
                                ?>
                            </pre>
                            <p style="margin: 10px 0; font-weight: bold;">Filtros sobre listados:</p>
                            <p style="margin: 5px 0 0; font-size: 13px;"><code>pagination="<b>no</b>|yes"</code> para activar el paginado automático de la API.</p>
                            <p style="margin: 5px 0 0; font-size: 13px;"><code>trainings-per-page="<b>n</b>"</code> para indicar la cantidad de formaciones por cada página.</p>
                            <p style="margin: 10px 0; font-weight: bold;">Ejemplo:</p>
                            <p><code>[oec-list pagination="yes" trainings-per-page="3"] ... [/oec-list]</code></p>
                        </div>

                        <div style="flex: 1; min-width: 0;">
                            <h3 style="margin-top: 0;">Detalle de Formación</h4>
                            <p class="description" style="margin-bottom: 10px;">Los datos de sus formaciones en el detalle de cada formación son:</p>
                            
                            <pre style="background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; height: 250px; overflow-y: auto; font-size: 11px; white-space: pre-wrap; word-wrap: break-word; margin-bottom: 15px;"><?php 
                                echo $test_data_content ? esc_html(json_encode($test_data_content, JSON_PRETTY_PRINT)) : 'Sin datos disponibles.'; 
                            ?></pre>
                            <p class="description" style="margin-bottom: 10px;">Usted puede mostrar el detalle de sus formaciones en cualquier página, con el shortcode: <code>[oec-content] ... [/oec-content]</code>. Ejemplo funcional: (Objeto <code>data</code>)</p>
                            <pre style="background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; height: 250px; overflow-y: auto; font-size: 11px; white-space: pre-wrap; word-wrap: break-word; margin-bottom: 15px;">
                                <?php 
                                $example_code = '
[oec-content]
    <h1>{{ data.name }}</h1>
    <p class="description">
        {{ data.short_description|raw }}
    </p>
    <a class="enroll-button" href="https://{{ data.organization.data.subdomain }}.onlineeducation.center/es/checkoutv2/enrollment/{{ data.id }}">INICIAR INSCRIPCION</a>
[/oec-content]';
                                echo esc_html($example_code); 
                                ?>
                            </pre>

                        </div>

                    </div>
                </div>


            <?php endif; ?>
        </div>
        <?php
    }


    public function page_contact() { 
        $kam_name   = get_option('oec_kam_name', 'Tu Asistente OEC');
        $kam_email  = get_option('oec_kam_email', 'soporte@onlineeducation.center');
        $kam_phone  = get_option('oec_kam_phone');
        $kam_avatar = get_option('oec_kam_avatar');
        
        // Limpiamos el número para el enlace de WhatsApp (solo números)
        $wa_phone = preg_replace('/[^0-9]/', '', $kam_phone);
        ?>
        <div class="wrap">
            <h1>Contáctenos</h1>
            <div class="card" style="max-width: 600px; padding: 30px; margin-top: 20px;">
                <h2>Estamos para ayudarte</h2>
                <p>Si tienes alguna duda técnica o comercial, tu Key Account Manager asignado se pondrá en contacto contigo.</p>
                <hr style="margin: 20px 0;">
                
                <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 25px;">
                    <div style="background: #f0f0f1; border-radius: 50%; width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; overflow: hidden; border: 1px solid #ccd0d4; flex-shrink: 0;">
                        <?php if (!empty($kam_avatar)) : ?>
                            <img src="<?php echo esc_url($kam_avatar); ?>" alt="<?php echo esc_attr($kam_name); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else : ?>
                            <span class="dashicons dashicons-businessman" style="font-size: 40px; width: 40px; height: 40px;"></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 style="margin:0; font-size: 18px;"><?php echo esc_html($kam_name); ?></h3>
                        <p style="margin: 5px 0; color: #666;">Key Account Manager</p>
                        <p style="margin: 0; font-size: 13px;"><strong>Email:</strong> <?php echo esc_html($kam_email); ?></p>
                        <?php if ($kam_phone) : ?>
                            <p style="margin: 0; font-size: 13px;"><strong>Teléfono:</strong> <?php echo esc_html($kam_phone); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <a href="mailto:<?php echo esc_attr($kam_email); ?>" class="button button-primary" style="display: inline-flex; align-items: center; gap: 5px; height: 35px; padding: 0 15px;">
                        <span class="dashicons dashicons-email" style="font-size: 18px; width: 18px; height: 18px; margin-top: 2px;"></span>
                        Contactar por Email
                    </a>

                    <?php if (!empty($wa_phone)) : ?>
                        <a href="https://wa.me/<?php echo esc_attr($wa_phone); ?>" target="_blank" class="button" style="display: inline-flex; align-items: center; gap: 5px; height: 35px; padding: 0 15px; background-color: #25D366; color: white; border-color: #128C7E;">
                            <span class="dashicons dashicons-whatsapp" style="font-size: 18px; width: 18px; height: 18px; margin-top: 2px;"></span>
                            WhatsApp
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
        <?php
    }



    // Campus Virtual
    public function page_campus() { 
        $sub = get_option('oec_subdomain', 'g-se');
        $target_url = "https://{$sub}.onlineeducation.center/es/campus";
        $this->render_oec_iframe('Campus Virtual', $target_url, true); 
    }


    // Estadísticas
    public function page_stats() {
        $token = get_option('oec_token');

        if (!$token) {
            echo '<div class="wrap"><h1>Estadísticas</h1><p>Configure su API Token en la pestaña de Configuración.</p></div>';
            return;
        }

        // Cargamos la librería Thickbox nativa de WordPress para el Modal
        add_thickbox();

        // --- 1. LÓGICA DE PAGINACIÓN Y CACHÉ TOTAL ---
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 6;
        $transient_key = 'oec_stats_full_cache_p9' . $page;
        $cached_content = get_transient($transient_key);

        if (false === $cached_content) {
            // A. Obtener listado de formaciones
            $trainings_url = "https://oas-api.onlineeducation.center/api-oas/v1/trainings?relevance=0";
            $response = wp_remote_get($trainings_url, [
                'headers' => ['X-API-TOKEN' => $token, 'Accept' => 'application/json'],
                'timeout' => 20
            ]);
            
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $all_trainings = $data['data'] ?? [];
            $total_trainings = count($all_trainings);
            
            // B. Segmentar para la página actual
            $offset = ($page - 1) * $per_page;
            $paged_trainings = array_slice($all_trainings, $offset, $per_page);

            // C. Recolectar estadísticas de cada formación
            $processed_trainings = [];
            foreach ($paged_trainings as $t) {
                $analytics_url = "https://sales-analytics.onlineeducation.center/v2/global/metrics/trainings/{$t['id']}/edition/{$t['edition_number']}";
                $ana_response = wp_remote_get($analytics_url, [
                    'headers' => ['X-API-TOKEN' => '']
                ]);
                $stats = json_decode(wp_remote_retrieve_body($ana_response), true);

                $processed_trainings[] = [
                    'info'  => $t,
                    'stats' => $stats ?: []
                ];
            }

            $cached_content = [
                'data_list' => $processed_trainings,
                'total'     => $total_trainings,
                'has_more'  => $total_trainings > ($offset + $per_page)
            ];

            set_transient($transient_key, $cached_content, 12 * 3600);
        }
        ?>

        <div class="wrap">
            <h1 class="wp-heading-inline">Estadísticas de Conversión</h1>
            <hr class="wp-header-end">

            <?php if (empty($cached_content['data_list'])) : ?>
                <div class="notice notice-info"><p>No hay formaciones para mostrar en esta página.</p></div>
            <?php else : ?>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 25px; margin-top: 20px;">
                    <?php foreach ($cached_content['data_list'] as $item) : 
                        $t = $item['info'];
                        $stats = $item['stats'];
                        if (empty($stats)) continue;

                        $funnel = [
                            ['label' => 'LEADS', 'val' => $stats['Leads.CurrentVal'] ?? 0, 'bg' => '#2271b1', 'w' => '100%'],
                            ['label' => 'COLD PROSPECTS', 'val' => $stats['Cold Prospects.CurrentVal'] ?? 0, 'bg' => '#3399ff', 'w' => '92%'],
                            ['label' => 'MORE INFO REQUEST', 'val' => $stats['More Info Request.CurrentVal'] ?? 0, 'bg' => '#ffb900', 'w' => '84%'],
                            ['label' => 'HOT PROSPECTS', 'val' => $stats['Hot Prospects.CurrentVal'] ?? 0, 'bg' => '#46b450', 'w' => '76%', 'is_hot' => true],
                            ['label' => 'O.N.F.', 'val' => $stats['Orders Not Finished.CurrentVal'] ?? 0, 'bg' => '#d63638', 'w' => '68%'],
                            ['label' => 'ALUMNOS', 'val' => $stats['Enrollments.CurrentVal'] ?? 0, 'bg' => '#1d2327', 'w' => '60%', 'is_final' => true],
                        ];
                    ?>

                    <div class="card" style="margin: 0; padding: 20px; border-radius: 8px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <div style="display: flex; gap: 15px; margin-bottom: 20px;">
                            <img src="<?php echo esc_url($t['image']); ?>" style="width: 60px; height: 60px; object-fit: cover; border-radius: 4px;">
                            <div style="flex: 1;">
                                <h3 style="margin:0; font-size: 15px; line-height: 1.2;"><?php echo esc_html($t['title']); ?></h3>
                                <div style="font-size: 11px; color: #666; margin-top: 6px; background: #f9f9f9; padding: 4px; border-radius: 3px; display: inline-block;">
                                    Edición: <strong><?php echo $t['edition_number']; ?></strong> | 
                                    Inicio: <strong><?php echo $stats['Training.DaysToStart'] ?? 0; ?>d</strong> | 
                                    Cierre: <strong><?php echo $stats['Training.DaysToEnrollmentEnd'] ?? 0; ?>d</strong>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; align-items: center; gap: 4px;">
                            <?php foreach ($funnel as $step) : ?>
                                <?php 
                                // Lógica mejorada para Hot Prospects
                                if (isset($step['is_hot'])) : 
                                    if ($step['val'] > 0) : 
                                        $zoho_url = "https://onlineeducation.center/connections/zoho/list-deals.php?training_uid=" . $t['id'] . "&TB_iframe=true&width=1000&height=600";
                                    ?>
                                        <a href="<?php echo esc_url($zoho_url); ?>" 
                                        class="thickbox" 
                                        title="Listado de Hot Prospects - <?php echo esc_attr($t['title']); ?>"
                                        style="width: <?php echo $step['w']; ?>; background: <?php echo $step['bg']; ?>; color: #fff; padding: 8px 15px; text-decoration: none; border-radius: 2px; display: flex; justify-content: space-between; align-items: center; transition: 0.2s;">
                                            <span style="font-size: 10px; font-weight: bold;">🔥 <?php echo $step['label']; ?></span>
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <strong><?php echo number_format($step['val']); ?></strong>
                                                <span class="dashicons dashicons-external" style="font-size: 14px; margin-top: 2px;"></span>
                                            </div>
                                        </a>
                                    <?php else : ?>
                                        <div style="width: <?php echo $step['w']; ?>; background: <?php echo $step['bg']; ?>; color: #fff; padding: 8px 15px; border-radius: 2px; display: flex; justify-content: space-between; align-items: center; opacity: 0.6; cursor: not-allowed;">
                                            <span style="font-size: 10px; font-weight: bold;">🔥 <?php echo $step['label']; ?></span>
                                            <strong>0</strong>
                                        </div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <div style="width: <?php echo $step['w']; ?>; background: <?php echo $step['bg']; ?>; color: #fff; padding: 8px 15px; border-radius: 2px; display: flex; justify-content: space-between; align-items: center;">
                                        <span style="font-size: 10px; font-weight: bold; <?php echo isset($step['is_final']) ? 'color: #46b450;' : ''; ?>"><?php echo $step['label']; ?></span>
                                        <strong style="<?php echo isset($step['is_final']) ? 'font-size: 18px;' : ''; ?>"><?php echo number_format($step['val']); ?></strong>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 40px; text-align: center; padding-bottom: 40px;">
                    <?php if ($page > 1) : ?>
                        <a href="<?php echo admin_url('admin.php?page=oec-stats&paged=' . ($page - 1)); ?>" class="button">Anterior</a>
                    <?php endif; ?>
                    <span style="margin: 0 15px; color: #666;">Página <?php echo $page; ?></span>
                    <?php if ($cached_content['has_more']) : ?>
                        <a href="<?php echo admin_url('admin.php?page=oec-stats&paged=' . ($page + 1)); ?>" class="button">Siguiente</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }


    // Cuenta contable
    public function page_account() { 
        $target_url = "https://account.onlineeducation.center/es/billing";
        $this->render_oec_iframe('Cuenta Contable', $target_url, true); 
    }

    // Mesa de ayuda
    public function page_help() { 
        $target_url = "https://account.onlineeducation.center/es/help-desk";
        $this->render_oec_iframe('Mesa de Ayuda', $target_url, true); 
    }

    // Documentación
    public function page_docs() { 
        $target_url = "https://kb.onlineeducation.center/es/";
        $this->render_oec_iframe('Documentación', $target_url, false); 
    }






    // ─────────────────────────────────────────────────────────────────
    // CREAR PÁGINAS OEC AUTOMÁTICAMENTE
    // Se ejecuta una sola vez cuando el token se valida correctamente.
    // Las páginas se crean en estado "borrador" para que el usuario
    // las revise y publique cuando quiera.
    // ─────────────────────────────────────────────────────────────────
    public function oec_create_pages() {

        // Flag para no volver a crearlas si ya existen
        if (get_option('oec_pages_created')) {
            return false;
        }

        $pages = [
            [
                'title'   => 'Formaciones',
                'slug'    => 'oec-formaciones',
                'content' => $this->oec_get_page_formaciones(),
            ],
            [
                'title'   => 'Formación',
                'slug'    => 'oec-formacion',
                'content' => $this->oec_get_page_formacion(),
            ],
        ];

        // El bloque <!-- wp:html --> le indica a WordPress que es HTML puro
        // y no aplica wpautop ni ningún otro filtro de formato al contenido.
        kses_remove_filters();

        foreach ($pages as $page) {
            $existing = get_page_by_path($page['slug'], OBJECT, 'page');
            if ($existing) {
                continue;
            }

            wp_insert_post([
                'post_title'   => $page['title'],
                'post_name'    => $page['slug'],
                'post_content' => wp_slash('<!-- wp:html -->' . $page['content'] . '<!-- /wp:html -->'),
                'post_status'  => 'draft',
                'post_type'    => 'page',
                'post_author'  => get_current_user_id(),
            ], false, false);
        }

        kses_init_filters();

        update_option('oec_pages_created', true);
        flush_rewrite_rules();

        return true;
    }


    private function oec_get_page_formaciones() {
        $file = plugin_dir_path(__FILE__) . '../data/page-formaciones.txt';
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        return '[oec-list][/oec-list]';
    }


    private function oec_get_page_formacion() {
        $file = plugin_dir_path(__FILE__) . '../data/page-formacion.txt';
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        return '[oec-content][/oec-content]';
    }

}