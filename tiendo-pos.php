<?php
/**
 * Plugin Name: Tiendo POS
 * Description: Sistema de Punto de Venta
 * Version: 1.0.0
 * Author: Laura Nathaly Suárez
 */

if (!defined('ABSPATH')) exit;

define('TIENDO_POS_VERSION', '1.0.0');
define('TIENDO_POS_PATH', plugin_dir_path(__FILE__));
define('TIENDO_POS_URL', plugin_dir_url(__FILE__));

// Activación del plugin
register_activation_hook(__FILE__, 'tiendo_pos_activate');
function tiendo_pos_activate() {
    // Crear tablas
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    $table_ventas = $wpdb->prefix . 'pos_ventas';
    $sql = "CREATE TABLE IF NOT EXISTS $table_ventas (
        id_venta BIGINT(20) NOT NULL AUTO_INCREMENT,
        id_usuario BIGINT(20) NOT NULL,
        fecha_venta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        total_venta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        metodo_pago VARCHAR(50) NOT NULL,
        estado VARCHAR(20) NOT NULL DEFAULT 'completada',
        notas TEXT NULL,
        PRIMARY KEY (id_venta),
        INDEX idx_fecha (fecha_venta),
        INDEX idx_usuario (id_usuario)
    ) $charset_collate;";
    dbDelta($sql);
    
    $table_detalle = $wpdb->prefix . 'pos_venta_detalle';
    $sql_detalle = "CREATE TABLE IF NOT EXISTS $table_detalle (
        id_detalle BIGINT(20) NOT NULL AUTO_INCREMENT,
        id_venta BIGINT(20) NOT NULL,
        id_producto BIGINT(20) NOT NULL,
        cantidad INT(11) NOT NULL DEFAULT 1,
        precio_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id_detalle),
        INDEX idx_venta (id_venta),
        INDEX idx_producto (id_producto)
    ) $charset_collate;";
    dbDelta($sql_detalle);
    
    $table_inventario = $wpdb->prefix . 'pos_inventario';
    $sql_inventario = "CREATE TABLE IF NOT EXISTS $table_inventario (
        id_inventario BIGINT(20) NOT NULL AUTO_INCREMENT,
        id_producto BIGINT(20) NOT NULL,
        stock_actual INT(11) NOT NULL DEFAULT 0,
        stock_minimo INT(11) NOT NULL DEFAULT 5,
        ultima_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        alerta_stock_bajo TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id_inventario),
        UNIQUE KEY unique_producto (id_producto),
        INDEX idx_stock_bajo (alerta_stock_bajo)
    ) $charset_collate;";
    dbDelta($sql_inventario);
    
    // Crear rol de Tendero
    tiendo_pos_crear_rol_tendero();
}

// Crear rol personalizado de Tendero
function tiendo_pos_crear_rol_tendero() {
    remove_role('tendero_pos');
    
    add_role(
        'tendero_pos',
        'Tendero POS',
        array(
            'read' => true,
            'usar_tiendo_pos' => true,
            'view_admin_dashboard' => true // ← AGREGAR ESTO
        )
    );
}
// Cargar archivos necesarios
add_action('plugins_loaded', 'tiendo_pos_init');
function tiendo_pos_init() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'tiendo_pos_wc_notice');
        return;
    }
    
    // Cargar clases
    if (file_exists(TIENDO_POS_PATH . 'includes/class-pos-database.php')) {
        require_once TIENDO_POS_PATH . 'includes/class-pos-database.php';
    }
    if (file_exists(TIENDO_POS_PATH . 'includes/class-pos-venta.php')) {
        require_once TIENDO_POS_PATH . 'includes/class-pos-venta.php';
    }
    if (file_exists(TIENDO_POS_PATH . 'includes/class-pos-ajax.php')) {
        require_once TIENDO_POS_PATH . 'includes/class-pos-ajax.php';
        Tiendo_POS_Ajax::init();
    }
    
    add_action('admin_menu', 'tiendo_pos_menu');
    add_action('admin_enqueue_scripts', 'tiendo_pos_scripts');
}

// Menú del POS
function tiendo_pos_menu() {
    // Determinar capacidad requerida
    // Administradores O Tenderos pueden ver el POS
    $capacidad_pos = current_user_can('manage_options') ? 'manage_options' : 'usar_tiendo_pos';
    
    add_menu_page(
        'Tiendo POS',
        'POS Tiendo',
        $capacidad_pos, // Administrador o Tendero
        'tiendo-pos',
        'tiendo_pos_page',
        'dashicons-cart',
        56
    );
    
    // Renombrar el primer item del submenu
    add_submenu_page(
        'tiendo-pos',
        'Punto de Venta',
        'Punto de Venta',
        $capacidad_pos,
        'tiendo-pos'
    );
    
    // Solo administradores ven estos submenús adicionales
    if (current_user_can('manage_options')) {
        add_submenu_page(
            'tiendo-pos',
            'Historial de Ventas',
            'Historial',
            'manage_options',
            'tiendo-pos-historial',
            'tiendo_pos_historial_page'
        );
        
        add_submenu_page(
            'tiendo-pos',
            'Reportes',
            'Reportes',
            'manage_options',
            'tiendo-pos-reportes',
            'tiendo_pos_reportes_page'
        );
    }
}

function tiendo_pos_scripts($hook) {
    if (strpos($hook, 'tiendo-pos') === false) {
        return;
    }
    
    wp_enqueue_style(
        'tiendo-pos-global',
        TIENDO_POS_URL . 'admin/css/pos-global.css',
        array(),
        TIENDO_POS_VERSION
    );
    
    if ($hook == 'toplevel_page_tiendo-pos') {
        wp_enqueue_style(
            'tiendo-pos-interface',
            TIENDO_POS_URL . 'admin/css/pos-interface.css',
            array('tiendo-pos-global'),
            TIENDO_POS_VERSION
        );
        
        wp_enqueue_script(
            'tiendo-pos-interface',
            TIENDO_POS_URL . 'admin/js/pos-interface.js',
            array('jquery'),
            TIENDO_POS_VERSION,
            true
        );
        
        wp_localize_script('tiendo-pos-interface', 'tiendoPosData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tiendo_pos_nonce'),
            'currency' => get_woocommerce_currency_symbol(),
            'userName' => wp_get_current_user()->display_name,
        ));
    }
}

function tiendo_pos_page() {
    if (file_exists(TIENDO_POS_PATH . 'admin/views/pos-interface.php')) {
        include TIENDO_POS_PATH . 'admin/views/pos-interface.php';
    }
}

function tiendo_pos_historial_page() {
    echo '<div class="wrap"><h1>Historial de Ventas</h1><p>Próximamente...</p></div>';
}

function tiendo_pos_reportes_page() {
    echo '<div class="wrap"><h1>Reportes</h1><p>Próximamente...</p></div>';
}

function tiendo_pos_wc_notice() {
    echo '<div class="notice notice-error"><p>Tiendo POS requiere WooCommerce</p></div>';
}

/**
 * REDIRECT AUTOMÁTICO SOLO PARA TENDEROS
 */
add_filter('login_redirect', 'tiendo_pos_tendero_redirect', 10, 3);
function tiendo_pos_tendero_redirect($redirect_to, $request, $user) {
    if (isset($user->roles) && is_array($user->roles)) {
        // Solo si el usuario es Tendero POS
        if (in_array('tendero_pos', $user->roles)) {
            return admin_url('admin.php?page=tiendo-pos');
        }
    }
    return $redirect_to;
}

/**
 * Ocultar menús innecesarios para Tenderos
 */
add_action('admin_menu', 'tiendo_pos_ocultar_menus_tendero', 999);
function tiendo_pos_ocultar_menus_tendero() {
    if (current_user_can('tendero_pos') && !current_user_can('manage_options')) {
        // Remover menús que no necesita el tendero
        remove_menu_page('index.php');                  // Dashboard
        remove_menu_page('edit.php');                   // Entradas
        remove_menu_page('upload.php');                 // Medios
        remove_menu_page('edit.php?post_type=page');    // Páginas
        remove_menu_page('edit-comments.php');          // Comentarios
        remove_menu_page('themes.php');                 // Apariencia
        remove_menu_page('plugins.php');                // Plugins
        remove_menu_page('users.php');                  // Usuarios
        remove_menu_page('tools.php');                  // Herramientas
        remove_menu_page('options-general.php');        // Ajustes
        remove_menu_page('woocommerce');                // WooCommerce
        remove_menu_page('edit.php?post_type=product'); // Productos
    }
}

/**
 * Redirigir al POS si intenta acceder al dashboard
 */
/**
 * Shortcode para página de acceso al POS - CON LOGIN INTEGRADO
 */
add_shortcode('acceso_pos', 'tiendo_pos_shortcode_acceso');
function tiendo_pos_shortcode_acceso() {
    // Si ya está logueado
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        
        // Si es tendero o admin
        if (in_array('tendero_pos', $user->roles) || current_user_can('manage_options')) {
            ob_start();
            ?>
            <div style="text-align: center; padding: 60px 20px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 600px; margin: 40px auto;">
                <div style="width: 100px; height: 100px; background: #83b735; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 48px; color: #fff;">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1"></circle>
                        <circle cx="20" cy="21" r="1"></circle>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                    </svg>
                </div>
                <h1 style="font-size: 32px; color: #2c3338; margin-bottom: 10px; font-weight: 700;">Bienvenido, <?php echo esc_html($user->display_name); ?></h1>
                <p style="font-size: 18px; color: #666; margin-bottom: 30px;">Estas listo para usar el punto de venta</p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=tiendo-pos')); ?>" style="display: inline-block; padding: 18px 40px; background: #83b735; color: #fff; text-decoration: none; font-size: 20px; font-weight: 700; border-radius: 8px; transition: all 0.3s; box-shadow: 0 4px 12px rgba(131, 183, 53, 0.3);">
                    IR AL PUNTO DE VENTA
                </a>
            </div>
            <?php
            return ob_get_clean();
        } else {
            return '<div style="text-align: center; padding: 40px; background: #fff3cd; border-radius: 8px; margin: 40px auto; max-width: 600px; border: 2px solid #ffc107;">
                <p style="font-size: 18px; color: #856404; margin: 0;">No tienes permisos para acceder al POS.</p>
            </div>';
        }
    } else {
        // Si NO está logueado - Mostrar formulario de login personalizado
        ob_start();
        
        // Obtener URL actual para redirect
        $redirect_to = get_permalink();
        
        ?>
        <style>
            .tiendo-login-container {
                max-width: 450px;
                margin: 40px auto;
                background: #fff;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            }
            .tiendo-login-header {
                text-align: center;
                margin-bottom: 30px;
            }
            .tiendo-login-icon {
                width: 100px;
                height: 100px;
                background: #83b735;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 20px;
            }
            .tiendo-login-title {
                font-size: 28px;
                font-weight: 700;
                color: #2c3338;
                margin: 0 0 8px 0;
            }
            .tiendo-login-subtitle {
                font-size: 16px;
                color: #666;
                margin: 0;
            }
            .tiendo-form-group {
                margin-bottom: 20px;
            }
            .tiendo-label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #2c3338;
                font-size: 14px;
            }
            .tiendo-input {
                width: 100%;
                padding: 14px 16px;
                font-size: 16px;
                border: 2px solid #ddd;
                border-radius: 8px;
                box-sizing: border-box;
                transition: all 0.2s;
                font-family: inherit;
            }
            .tiendo-input:focus {
                outline: none;
                border-color: #83b735;
                box-shadow: 0 0 0 3px rgba(131, 183, 53, 0.1);
            }
            .tiendo-btn-login {
                width: 100%;
                padding: 16px;
                background: #83b735;
                color: #fff;
                border: none;
                border-radius: 8px;
                font-size: 18px;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.3s;
                box-shadow: 0 4px 12px rgba(131, 183, 53, 0.3);
            }
            .tiendo-btn-login:hover {
                background: #6fa02d;
                transform: translateY(-2px);
                box-shadow: 0 6px 16px rgba(131, 183, 53, 0.4);
            }
            .tiendo-btn-login:active {
                transform: translateY(0);
            }
            .tiendo-error {
                background: #f8d7da;
                color: #721c24;
                padding: 12px 16px;
                border-radius: 8px;
                margin-bottom: 20px;
                border: 1px solid #f5c6cb;
                font-size: 14px;
            }
            .tiendo-footer {
                text-align: center;
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #eee;
            }
            .tiendo-footer-text {
                color: #999;
                font-size: 13px;
                margin: 0;
            }
        </style>
        
        <div class="tiendo-login-container">
            <div class="tiendo-login-header">
                <div class="tiendo-login-icon">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2">
                        <circle cx="9" cy="21" r="1"></circle>
                        <circle cx="20" cy="21" r="1"></circle>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                    </svg>
                </div>
                <h1 class="tiendo-login-title">TIENDO POS</h1>
                <p class="tiendo-login-subtitle">Sistema de Punto de Venta</p>
            </div>
            
            <?php
            // Mostrar errores de login
            if (isset($_GET['login']) && $_GET['login'] == 'failed') {
                echo '<div class="tiendo-error">Usuario o contraseña incorrectos. Intenta de nuevo.</div>';
            }
            if (isset($_GET['login']) && $_GET['login'] == 'empty') {
                echo '<div class="tiendo-error">Por favor completa todos los campos.</div>';
            }
            ?>
            
            <form method="post" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>">
                <div class="tiendo-form-group">
                    <label class="tiendo-label">Usuario</label>
                    <input 
                        type="text" 
                        name="log" 
                        class="tiendo-input" 
                        placeholder="Ingresa tu usuario"
                        required
                        autocomplete="username"
                    >
                </div>
                
                <div class="tiendo-form-group">
                    <label class="tiendo-label">Contraseña</label>
                    <input 
                        type="password" 
                        name="pwd" 
                        class="tiendo-input" 
                        placeholder="Ingresa tu contraseña"
                        required
                        autocomplete="current-password"
                    >
                </div>
                
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($redirect_to); ?>">
                <input type="hidden" name="testcookie" value="1">
                
                <button type="submit" class="tiendo-btn-login">
                    INICIAR SESION
                </button>
            </form>
            
            <div class="tiendo-footer">
                <p class="tiendo-footer-text">Problemas para ingresar? Contacta al administrador</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}