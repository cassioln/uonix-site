<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * UONIX Snippets - Admin - perfil editor e dashboard customizado.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 14301-15294 do export original.
// -----------------------------------------------------------------------------
/**
 * Painel Wordpress Editor
 */
// =========================================================================
// 1. LIMPEZA PRINCIPAL DO PAINEL (EDITOR)
// =========================================================================
add_action('admin_menu', function () {
    $user = wp_get_current_user();

    if (in_array('editor', (array) $user->roles)) {
        // Remove lixo de plugins e menus intrusos
        remove_menu_page('edit.php?post_type=wcps');
        remove_menu_page('kadence-blocks-home');
        remove_menu_page('kadence-blocks');
        remove_menu_page('kadence-starter-templates'); // Remove Site Assist
        remove_menu_page('maxmegamenu');
        remove_menu_page('wp-reviews-plugin-for-google/settings.php');
        remove_menu_page('pods'); // Garantia contra o Pods
        remove_menu_page('ai1wm_export'); // Garantia contra All-in-One WP Migration

        // Limpa WooCommerce
        remove_menu_page('woocommerce');
        remove_menu_page('wc-admin');
        remove_menu_page('admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM');

        // Adiciona atalho direto para Pedidos
        add_menu_page('Pedidos', 'Pedidos', 'edit_shop_orders', 'edit.php?post_type=shop_order', '', 'dashicons-cart', 55);

        // Ajustes do Fluent Forms 
        remove_submenu_page('fluent_forms', 'fluent_forms'); // Remove a lista de Formulários
        remove_submenu_page('fluent_forms', 'fluent_forms_docs');
        remove_submenu_page('fluent_forms', 'fluent_forms_reports');
        remove_submenu_page('fluent_forms', 'fluent_forms_settings');
        remove_submenu_page('fluent_forms', 'fluent_forms_transfer');
        remove_submenu_page('fluent_forms', 'fluent_forms_smtp');
        remove_submenu_page('fluent_forms', 'fluent_forms_add_ons');

        global $menu, $submenu;

        // Renomeia o menu principal
        foreach ($menu as $key => $item) {
            if ($item[2] === 'fluent_forms') {
                $menu[$key][0] = 'Leads'; // Nome do menu principal
                break;
            }
        }

        // Renomeia o submenu padrão "Entradas" para "Todas as Entradas"
        if (isset($submenu['fluent_forms'])) {
            foreach ($submenu['fluent_forms'] as $key => $item) {
                if ($item[2] === 'fluent_forms_all_entries') {
                    $submenu['fluent_forms'][$key][0] = 'Todas as Entradas';
                }
            }
        }

        // INJETA OS LINKS DIRETOS COMO SUBMENUS EXTRAS
        $submenu['fluent_forms'][] = array('Captura de Leads', 'read', 'admin.php?page=fluent_forms&route=entries&form_id=4');
        $submenu['fluent_forms'][] = array('Formulário de Contato', 'read', 'admin.php?page=fluent_forms&route=entries&form_id=3');
        $submenu['fluent_forms'][] = array('Assinantes Newsletters', 'read', 'admin.php?page=fluent_forms&route=entries&form_id=2');
    }
}, 999);

// =========================================================================
// 2. OCULTAR MENUS NATIVOS COM SEGURANÇA (Via CSS)
// =========================================================================
add_action('admin_head', function () {
    $user = wp_get_current_user();
    if (in_array('editor', (array) $user->roles)) {
        echo '<style>
            /* Oculta Ferramentas e Aparência VISUALMENTE para não confundir */
            #menu-tools, #menu-appearance { display: none !important; }
        </style>';
    }
});

// =========================================================================
// 3. LIMPEZA DE FEATURES DO WOOCOMMERCE
// =========================================================================
add_filter('woocommerce_admin_features', function ($features) {
    $user = wp_get_current_user();
    if (in_array('editor', (array) $user->roles)) {
        return array_values(array_diff($features, ['marketing', 'payments', 'analytics', 'onboarding']));
    }
    return $features;
});

// =========================================================================
// 4. LIBERAR EDIÇÃO DA POLÍTICA DE PRIVACIDADE
// =========================================================================
add_filter('map_meta_cap', function ($caps, $cap, $user_id, $args) {
    if ('edit_post' !== $cap || empty($args[0]))
        return $caps;

    $post_id = (int) $args[0];
    $policy_page_id = (int) get_option('wp_page_for_privacy_policy');

    if ($post_id === $policy_page_id && user_can($user_id, 'editor')) {
        $caps = array('edit_pages');
    }
    return $caps;
}, 10, 4);

// =========================================================================
// 5. REDIRECIONAMENTO DE LEADS 
// =========================================================================
add_action('admin_init', function () {
    // Se clicar no menu pai (fluent_forms) E NÃO tiver o parâmetro 'route' na URL
    if (isset($_GET['page']) && $_GET['page'] === 'fluent_forms' && !isset($_GET['route'])) {
        $user = wp_get_current_user();
        if (in_array('editor', (array) $user->roles)) {
            // Redireciona de volta para a visão geral (Todas as Entradas)
            wp_redirect(admin_url('admin.php?page=fluent_forms_all_entries'));
            exit;
        }
    }
});

// =========================================================================
// 6. REMOVER / AJUSTAR ITENS DA BARRA SUPERIOR PARA EDITOR
// =========================================================================
add_action('admin_bar_menu', function ($wp_admin_bar) {
    $user = wp_get_current_user();

    if (!in_array('editor', (array) $user->roles, true)) {
        return;
    }

    // Remove submenus do nome do site na barra superior
    $wp_admin_bar->remove_node('dashboard');
    $wp_admin_bar->remove_node('themes');
    $wp_admin_bar->remove_node('widgets');
    $wp_admin_bar->remove_node('menus');
    $wp_admin_bar->remove_node('appearance');

    // Garante que o nome do site continue clicável e com o link correto
    // Dentro do painel: leva para a HOME.
    // No frontend: leva para o painel.
    $site_node = $wp_admin_bar->get_node('site-name');

    if ($site_node) {
        $destino_site_name = is_admin()
            ? home_url('/')
            : admin_url('index.php');

        $existing_class = !empty($site_node->meta['class']) ? $site_node->meta['class'] : '';
        $classes = array_filter(array_unique(array_merge(explode(' ', $existing_class), array('uonix-editor-site-link'))));

        $meta = (array) $site_node->meta;
        $meta['class'] = implode(' ', $classes);

        $wp_admin_bar->add_node(array(
            'id' => 'site-name',
            'title' => $site_node->title,
            'href' => $destino_site_name,
            'meta' => $meta,
        ));
    }

    // Remove o menu principal "Fluent Forms" da barra superior
    $wp_admin_bar->remove_node('fluent_form');

    // Remove subitens do Fluent Forms, caso algum seja inserido separado
    $wp_admin_bar->remove_node('all_forms');
    $wp_admin_bar->remove_node('new_form');
    $wp_admin_bar->remove_node('fluent_forms_all_entries');
    $wp_admin_bar->remove_node('fluent_forms_community');
    $wp_admin_bar->remove_node('fluent_forms_doc');
    $wp_admin_bar->remove_node('fluent_forms_dev_doc');

    // Remove o menu "Novo" da barra superior
    $wp_admin_bar->remove_node('new-content');

}, 999);

// =========================================================================
// CSS AJUSTE VISUAL DA BARRA SUPERIOR PARA EDITOR
// =========================================================================
add_action('wp_head', 'uonix_editor_admin_bar_front_css', 999);
add_action('admin_head', 'uonix_editor_admin_bar_front_css', 999);

function uonix_editor_admin_bar_front_css()
{
    if (!is_user_logged_in()) {
        return;
    }

    $user = wp_get_current_user();

    if (!in_array('editor', (array) $user->roles, true)) {
        return;
    }

    echo '<style>
        #wpadminbar #wp-admin-bar-site-name .ab-sub-wrapper {
            display: none !important;
        }

        #wpadminbar #wp-admin-bar-site-name:hover .ab-sub-wrapper,
        #wpadminbar #wp-admin-bar-site-name.hover .ab-sub-wrapper {
            display: none !important;
        }

        #wpadminbar #wp-admin-bar-site-name > .ab-item img.site-icon,
        #wpadminbar .site-icon {
            background: transparent !important;
        }
    </style>';
}

// =========================================================================
// 7. OCULTAR BOTÃO "APRENDA MAIS SOBRE PEDIDOS" PARA EDITOR | MENU WOOCOMERCE/PEDIDOS
// =========================================================================
add_action('admin_head', function () {
    $user = wp_get_current_user();

    if (in_array('editor', (array) $user->roles, true)) {
        echo '<style>
            body.post-type-shop_order a.woocommerce-BlankState-cta[href*="managing-orders"],
            body.woocommerce_page_wc-orders a.woocommerce-BlankState-cta[href*="managing-orders"],
            body.post-type-shop_order .woocommerce-BlankState-cta.button-primary,
            body.woocommerce_page_wc-orders .woocommerce-BlankState-cta.button-primary {
                display: none !important;
            }
        </style>';
    }
}, 999);

// =========================================================================
// 8. FALLBACK JS PARA REMOVER BOTÃO DE DOCUMENTAÇÃO DE PEDIDOS
// =========================================================================
add_action('admin_footer', function () {
    $user = wp_get_current_user();

    if (!in_array('editor', (array) $user->roles, true)) {
        return;
    }

    ?>
    <script>
        (function () {
            function uonixRemoveWooOrdersLearnButton() {
                var buttons = document.querySelectorAll(
                    'a.woocommerce-BlankState-cta[href*="woocommerce.com/document/managing-orders"], ' +
                    'a.woocommerce-BlankState-cta[href*="managing-orders"]'
                );

                buttons.forEach(function (button) {
                    var text = button.textContent ? button.textContent.trim().toLowerCase() : '';

                    if (
                        text.indexOf('aprenda mais sobre pedidos') !== -1 ||
                        button.href.indexOf('managing-orders') !== -1
                    ) {
                        button.remove();
                    }
                });
            }

            document.addEventListener('DOMContentLoaded', uonixRemoveWooOrdersLearnButton);
            window.addEventListener('load', uonixRemoveWooOrdersLearnButton);

            var observer = new MutationObserver(uonixRemoveWooOrdersLearnButton);

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        })();
    </script>
    <?php
}, 999);

/**
 * DASHBOARD PAINEL PRINCIPAL
 */
// =========================================================================
// 1. REMOVE O PAINEL DE BOAS-VINDAS NATIVO E O TÍTULO PADRÃO
// =========================================================================
add_action('load-index.php', 'uonix_remove_default_welcome');
function uonix_remove_default_welcome()
{
    $user = wp_get_current_user();
    if (in_array('editor', (array) $user->roles)) {
        remove_action('welcome_panel', 'wp_welcome_panel');
    }
}

// =========================================================================
// 2. CRIA O NOVO CABEÇALHO PREMIUM DA UÔNIX (Substitui o nativo)
// =========================================================================
add_action('welcome_panel', 'uonix_custom_welcome_panel');
function uonix_custom_welcome_panel()
{
    //     $user = wp_get_current_user();
//     if (!in_array('editor', (array) $user->roles)) return;

    //     $primeiro_nome = $user->user_firstname ? $user->user_firstname : $user->display_name;

    // 1. Pegue o ID do usuário atual (ou defina o ID desejado)
    $user_id = get_current_user_id(); // ou $user->ID

    // 2. Obtenha o primeiro e o último nome
    $first_name = get_user_meta($user_id, 'first_name', true);
    $last_name = get_user_meta($user_id, 'last_name', true);

    // 3. Verifique se ambos estão preenchidos
    if (!empty($first_name) && !empty($last_name)) {
        $nome_completo = $first_name . ' ' . $last_name;
    } elseif (!empty($first_name)) {
        $nome_completo = $first_name;
    } else {
        // Se não tiver nome cadastrado, usa o display_name
        $user_info = get_userdata($user_id);
        $nome_completo = $user_info->display_name;
    }

    ?>
    <div class="uox-premium-header">
        <div class="uox-header-logo">
            <img src="/wp-content/uploads/2026/01/logo-uonix-branco.png" alt="Uônix">
        </div>
        <div class="uox-header-text">
            <h2>Olá, <?php echo esc_html($nome_completo); ?>!</h2>
            <p>Bem-vindo ao centro de controle do site da Uônix. Acompanhe os resultados e acesse os atalhos rápidos do
                site.</p>
        </div>
    </div>
    <?php
}

// =========================================================================
// 3. INJETA O CSS CUSTOMIZADO (Design System Premium)
// =========================================================================
add_action('admin_head', 'uonix_dashboard_css');
function uonix_dashboard_css()
{
    $user = wp_get_current_user();
    if (in_array('editor', (array) $user->roles)) {
        echo '<style>
    /* --- LIMPEZA DE TELA NATIVA --- */
    .wp-admin.index-php #wpbody-content > .wrap > h1 {
        display: none;
    }

    #welcome-panel {
        border: none !important;
        background: transparent !important;
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
    }

    /* --- CABEÇALHO PREMIUM UÔNIX --- */
    .uox-premium-header {
        background: linear-gradient(135deg, #0e3780 0%, #1a2b3c 100%);
        border-radius: 12px;
        padding: 35px 40px;
        margin-top: 15px;
        margin-bottom: 25px;
        box-shadow: 0 10px 30px rgba(14, 55, 128, 0.15);
        display: flex;
        align-items: center;
        gap: 40px;
        color: #ffffff;
    }

    .uox-header-logo img {
        max-height: 55px;
        display: block;
        filter: drop-shadow(0 4px 6px rgba(0,0,0,0.2));
    }

    .uox-header-text h2 {
        color: #ffffff;
        font-size: 26px;
        font-weight: 800;
        margin: 0 0 8px 0;
        padding: 0;
        border: none;
        line-height: 1.2;
    }

    .uox-header-text p {
        color: #e2e8f0;
        font-size: 15px;
        margin: 0;
    }

    @media (max-width: 768px) {
        .uox-premium-header {
            flex-direction: column;
            text-align: center;
            gap: 20px;
            padding: 30px 20px;
        }
    }

    /* --- PADRONIZAÇÃO DOS WIDGETS (CARDS DINÂMICOS) --- */
    #dashboard-widgets .postbox {
        border: 1px solid #e2e8f0 !important;
        border-radius: 12px !important;
        box-shadow: 0 2px 8px -2px rgba(14, 55, 128, 0.05), 0 1px 3px rgba(0, 0, 0, 0.03) !important;
        background: #ffffff !important;
        overflow: hidden;
        margin-bottom: 20px !important;
        height: auto !important;
        min-height: 0 !important;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    #dashboard-widgets .postbox:hover {
        border-color: #cbd5e1 !important;
        box-shadow: 0 4px 16px rgba(14, 55, 128, 0.08), 0 1px 4px rgba(15, 23, 42, 0.04) !important;
    }

    #dashboard-widgets .postbox-header {
        border-bottom: 1px solid #f1f5f9 !important;
        background: #ffffff !important;
        padding: 12px 18px !important;
        display: flex !important;
        align-items: center !important;
        min-height: 48px !important;
        box-sizing: border-box !important;
    }

    #dashboard-widgets .postbox-header h2 {
        font-size: 14.5px !important;
        font-weight: 700 !important;
        color: #0e3780 !important;
        letter-spacing: -0.01em !important;
        margin: 0 !important;
        padding: 0 !important;
        line-height: 1.3 !important;
    }

    #dashboard-widgets .inside {
        padding: 14px 18px 16px 18px !important;
        margin: 0 !important;
        height: auto !important;
        min-height: 0 !important;
    }

    #dashboard-widgets .button-primary {
        background: #0e3780 !important;
        border-color: #0e3780 !important;
        color: #fff !important;
        border-radius: 8px !important;
        box-shadow: 0 1px 3px rgba(14, 55, 128, 0.2) !important;
        text-shadow: none !important;
        font-weight: 600 !important;
        padding: 7px 16px !important;
        transition: 0.15s ease;
    }

    #dashboard-widgets .button-primary:hover {
        background: #1a2b3c !important;
        border-color: #1a2b3c !important;
        box-shadow: 0 2px 6px rgba(14, 55, 128, 0.3) !important;
    }

    /* --- ELEMENTOS INTERNOS UÔNIX --- */
    .uox-stat-box {
        text-align: center;
        padding: 12px 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .uox-stat-box:last-child {
        border-bottom: none;
    }

    .uox-stat-number {
        font-size: 30px;
        font-weight: 800;
        color: #0e3780;
        line-height: 1;
        margin-bottom: 4px;
        letter-spacing: -0.02em;
    }

    .uox-stat-label {
        font-size: 12.5px;
        color: #64748b;
        font-weight: 500;
    }

    .uox-btn-group {
        display: flex;
        gap: 10px;
        margin-top: 14px;
    }

    .uox-btn {
        flex: 1;
        text-align: center;
        padding: 9px 12px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        transition: 0.15s ease;
        border: 1px solid #cbd5e1;
        color: #0e3780;
        background: #ffffff;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        gap: 6px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
    }

    .uox-dashboard-grid .uox-btn {
        justify-content: flex-start;
        padding: 10px 12px;
    }

    .uox-btn:hover {
        background: #f8fafc;
        color: #1a2b3c;
        border-color: #94a3b8;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .uox-btn-primary {
        background: #0e3780;
        color: #ffffff !important;
        border-color: #0e3780;
        box-shadow: 0 1px 3px rgba(14, 55, 128, 0.2);
    }

    .uox-btn-primary:hover {
        background: #1a2b3c;
        color: #ffffff !important;
        border-color: #1a2b3c;
        box-shadow: 0 2px 6px rgba(14, 55, 128, 0.3);
    }

    .uox-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
        height: auto;
        min-height: 0;
    }

    .uox-list li {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 8px 12px;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid #f1f5f9;
        font-size: 13px;
        font-weight: 500;
        color: #334155;
        min-height: 0;
        box-sizing: border-box;
        transition: background 0.15s ease, border-color 0.15s ease;
    }

    .uox-list li:hover {
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    .uox-list li span {
        min-width: 0;
        overflow-wrap: anywhere;
        letter-spacing: -0.01em;
    }

    .uox-list li strong {
        flex-shrink: 0;
        color: #0e3780;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        padding: 2px 10px;
        border-radius: 9999px;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.3;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
    }

    .uox-badge {
        background: #dcfce7;
        color: #166534;
        padding: 3px 10px;
        border-radius: 9999px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.02em;
        display: inline-flex;
        align-items: center;
    }

    .uox-badge-blue {
        background: #e0f2fe;
        color: #0369a1;
        border: 1px solid #bae6fd;
    }

    .uox-badge-purple {
        background: #ede9fe;
        color: #6d28d9;
        border: 1px solid #ddd6fe;
    }

    /* --- PAGINAÇÃO DOS RANKINGS DINÂMICA --- */
    .uox-pagination {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #f1f5f9;
        min-height: 0;
        height: auto;
    }

    .uox-pagination ul {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        list-style: none;
        padding: 0;
        margin: 0;
        align-items: center;
    }

    .uox-pagination li {
        margin: 0;
        padding: 0;
        border: none;
    }

    .uox-pagination a,
    .uox-pagination span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 28px;
        height: 28px;
        padding: 0 8px;
        border-radius: 6px;
        border: 1px solid #dbe3ef;
        background: #ffffff;
        color: #0e3780;
        font-size: 12.5px;
        font-weight: 700;
        line-height: 1;
        text-decoration: none;
        box-sizing: border-box;
        transition: 0.15s;
    }

    .uox-pagination a:hover {
        background: #f8fafc;
        color: #1a2b3c;
        border-color: #94a3b8;
    }

    .uox-pagination .current {
        background: #0e3780;
        color: #ffffff;
        border-color: #0e3780;
    }

    .uox-pagination .dots {
        background: transparent;
        border-color: transparent;
        color: #94a3b8;
        min-width: auto;
        padding: 0 4px;
    }

    .uox-pagination .prev,
    .uox-pagination .next {
        font-size: 15px;
        font-weight: 800;
    }

    /* --- GRID DO ACESSO RÁPIDO --- */
    .uox-dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 12px;
    }

    .uox-dashboard-grid .uox-btn {
        padding: 10px 12px;
    }

    .uox-dashboard-grid .uox-btn-full {
        grid-column: 1 / -1;
        justify-content: center;
    }

    .uox-dashboard-grid .dashicons {
        font-size: 18px;
        width: 18px;
        height: 18px;
        color: #0e3780;
    }

    .uox-dashboard-grid .uox-btn-primary .dashicons {
        color: #ffffff;
    }

    /* --- RANKINGS DINÂMICOS (SEM ALTURA FIXA) --- */
    .uox-ranking-list {
        min-height: 0;
        height: auto;
    }

    .uox-ranking-list li {
        min-height: 0;
        box-sizing: border-box;
    }

    /* Destaque para o líder do ranking */
    .uox-ranking-list li:first-child {
        background: #eff6ff;
        border-color: #dbeafe;
    }

    .uox-ranking-list li:first-child strong {
        background: #0e3780;
        color: #ffffff;
        border-color: #0e3780;
    }

    .uox-list li.uox-empty {
        justify-content: center;
        text-align: center;
        color: #94a3b8;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        padding: 14px;
        font-style: italic;
    }

    @media (max-width: 768px) {
        .uox-dashboard-grid {
            grid-template-columns: 1fr;
        }

        .uox-btn-group {
            flex-direction: column;
        }

        .uox-pagination ul {
            justify-content: center;
        }
    }
	
</style>';
    }
}

// =========================================================================
// 4. REMOVE LIXO NATIVO E REGISTRA OS NOVOS WIDGETS
// =========================================================================
add_action('wp_dashboard_setup', 'uonix_modular_dashboard_setup', 999);
function uonix_modular_dashboard_setup()
{
    $user = wp_get_current_user();
    if (in_array('editor', (array) $user->roles)) {
        remove_meta_box('welcome-panel-content', 'dashboard', 'side');
        //      remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
        remove_meta_box('dashboard_primary', 'dashboard', 'side');
        //      remove_meta_box('dashboard_activity', 'dashboard', 'normal');
        remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
        //      remove_meta_box('dashboard_site_health', 'dashboard', 'normal');
        remove_meta_box('woocommerce_dashboard_status', 'dashboard', 'normal');

        // Blocos Modulares Padrão
//      wp_add_dashboard_widget('uox_widget_leads', 'Volume de Leads', 'uox_render_leads');
        wp_add_dashboard_widget('uox_widget_origem_leads', 'Captura de Leads', 'uox_render_origem_leads');
        wp_add_dashboard_widget('uox_widget_newsletters', 'Origem das Newsletters', 'uox_render_newsletters_origem');
        //      wp_add_dashboard_widget('uox_widget_orcamentos', 'Orçamentos (Loja)', 'uox_render_orcamentos');
        wp_add_dashboard_widget('uox_widget_blog', 'Engajamento do Blog', 'uox_render_blog');
        wp_add_dashboard_widget('uox_widget_trafego', 'Inteligência de Tráfego', 'uox_render_trafego');

        // Novos Blocos Estratégicos
        wp_add_dashboard_widget('uox_widget_acesso_rapido', 'Acesso Rápido', 'uox_render_quick_links');
        wp_add_dashboard_widget('uox_widget_crm_orcamentos', 'Últimos Orçamentos Solicitados', 'uox_render_crm_orcamentos');
        wp_add_dashboard_widget('uox_widget_manutencao_cache', 'Manutenção do Sistema', 'uox_render_manutencao_cache');
        //         wp_add_dashboard_widget('uox_widget_suporte_vip', 'Suporte Técnico VIP', 'uox_render_suporte_vip');
    }
}

// ================= FUNÇÕES DE RENDERIZAÇÃO =================

// Bloco 1: Volume de Leads
function uox_render_leads()
{
    global $wpdb;
    $tabela_ff = $wpdb->prefix . 'fluentform_submissions';
    $form4_leads = 0;
    $form3_contato = 0;

    if ($wpdb->get_var("SHOW TABLES LIKE '$tabela_ff'") == $tabela_ff) {
        $form4_leads = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $tabela_ff WHERE form_id = %d", 4));
        $form3_contato = $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $tabela_ff WHERE form_id = %d", 3));
    }
    ?>
    <ul class="uox-list">
        <li><span>Captura de Leads (ID 4)</span> <strong><?php echo $form4_leads; ?></strong></li>
        <li><span>Form. de Contato (ID 3)</span> <strong><?php echo $form3_contato; ?></strong></li>
    </ul>
    <div class="uox-btn-group">
        <a href="admin.php?page=fluent_forms_all_entries" class="uox-btn uox-btn-primary">Ver Entradas</a>
    </div>
    <?php
}

// Bloco 2: Ranking de Origem (Captura ID 4)
function uox_render_ranking_origem_paginado($form_id, $chave_do_campo, $page_var)
{
    global $wpdb;

    $tabela_ff = $wpdb->prefix . 'fluentform_submissions';
    $origens_count = array();
    $itens_por_pagina = 4;

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tabela_ff)) === $tabela_ff) {
        $respostas = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT response 
				 FROM {$tabela_ff} 
				 WHERE form_id = %d 
				 AND (
					status IS NULL 
					OR status = '' 
					OR status NOT IN ('trashed', 'trash', 'spam')
				 )",
                $form_id
            )
        );

        foreach ($respostas as $json) {
            $dados = json_decode($json, true);

            if (isset($dados[$chave_do_campo])) {
                $origem = $dados[$chave_do_campo];
                $origem_txt = is_array($origem) ? implode(', ', $origem) : $origem;
                $origem_txt = trim((string) $origem_txt);

                if ($origem_txt === '') {
                    continue;
                }

                if (!isset($origens_count[$origem_txt])) {
                    $origens_count[$origem_txt] = 0;
                }

                $origens_count[$origem_txt]++;
            }
        }
    }

    arsort($origens_count);

    $pagina_atual = isset($_GET[$page_var]) ? max(1, absint($_GET[$page_var])) : 1;
    $total_itens = count($origens_count);
    $total_paginas = (int) ceil($total_itens / $itens_por_pagina);

    if ($total_paginas > 0 && $pagina_atual > $total_paginas) {
        $pagina_atual = $total_paginas;
    }

    $offset = ($pagina_atual - 1) * $itens_por_pagina;
    $origens_pagina = array_slice($origens_count, $offset, $itens_por_pagina, true);

    echo '<ul class="uox-list uox-ranking-list">';

    if (empty($origens_pagina)) {
        echo '<li class="uox-empty"><span>Nenhum dado encontrado.</span></li>';
    } else {
        foreach ($origens_pagina as $nome => $quantidade) {
            echo '<li><span>' . esc_html($nome) . '</span> <strong>' . esc_html($quantidade) . '</strong></li>';
        }
    }

    echo '</ul>';

    if ($total_paginas > 1) {
        echo '<div class="uox-pagination">';

        echo paginate_links(array(
            'base' => esc_url_raw(add_query_arg($page_var, '%#%')),
            'format' => '',
            'current' => $pagina_atual,
            'total' => $total_paginas,
            'prev_text' => '‹',
            'next_text' => '›',
            'type' => 'list',
        ));

        echo '</div>';
    }
}

// Bloco 3: Ranking de Origem — Leads, Captura ID 4
function uox_render_origem_leads()
{
    uox_render_ranking_origem_paginado(
        4,
        'capturalead_origem',
        'uox_leads_origem_pg'
    );
}

// Bloco 3: Ranking de Origem — Newsletters ID 2
function uox_render_newsletters_origem()
{
    uox_render_ranking_origem_paginado(
        2,
        'newsletters_origem',
        'uox_news_origem_pg'
    );
}

// Bloco 4: Orçamentos
function uox_render_orcamentos()
{
    $pedidos_pendentes = function_exists('wc_orders_count') ? (wc_orders_count('wc-pending') + wc_orders_count('wc-on-hold')) : 0;
    ?>
    <div class="uox-stat-box">
        <div class="uox-stat-number"><?php echo $pedidos_pendentes; ?></div>
        <div class="uox-stat-label">Aguardando retorno</div>
    </div>
    <div class="uox-btn-group">
        <a href="edit.php?post_type=shop_order" class="uox-btn uox-btn-primary">Ver Orçamentos</a>
    </div>
    <?php
}

// Bloco 5: Engajamento e Blog
function uox_render_blog()
{
    $comentarios_pendentes = wp_count_comments()->moderated;
    $comentarios_aprovados = wp_count_comments()->approved;
    ?>
    <ul class="uox-list">
        <li>
            <span>Coment. Pendentes</span>
            <strong
                style="color: <?php echo $comentarios_pendentes > 0 ? '#dc2626' : '#0f172a'; ?>"><?php echo $comentarios_pendentes; ?></strong>
        </li>
        <li><span>Coment. Aprovados</span> <strong><?php echo $comentarios_aprovados; ?></strong></li>
    </ul>
    <div class="uox-btn-group">
        <a href="edit-comments.php" class="uox-btn uox-btn-primary">Moderar</a>
        <a href="edit.php" class="uox-btn">Ver Posts</a>
    </div>
    <?php
}

// Bloco 6: Tráfego (GA4 & Meta Pixel)
function uox_render_trafego()
{
    ?>
    <p style="font-size: 13px; color: #64748b; margin-bottom: 12px; line-height: 1.5;">
        O rastreamento de tráfego opera via Google Tag Manager (GTM), integrando <strong>Google Analytics 4</strong> e
        <strong>Meta Pixel</strong> sob governança e consentimento LGPD da AdOpt.
    </p>
    <div style="margin-bottom: 14px; display: flex; flex-wrap: wrap; gap: 6px;">
        <span class="uox-badge">Status LGPD: Blindado</span>
        <span class="uox-badge uox-badge-blue">GA4 Ativo</span>
        <span class="uox-badge uox-badge-purple">Meta Pixel Ativo</span>
    </div>
    <div class="uox-btn-group">
        <?php // Só para quem pode abrir o Uônix Insights; senão o botão levaria a "sem permissão". ?>
        <?php if (!function_exists('uonix_ksio_can_access_tool') || uonix_ksio_can_access_tool('analytics')): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=uonix-analytics')); ?>" class="uox-btn uox-btn-primary">
                Central de Analytics
            </a>
        <?php endif; ?>
        <a href="https://business.facebook.com/events_manager2" target="_blank" rel="noopener noreferrer" class="uox-btn">
            Meta Events
        </a>
    </div>
    <?php
}

// Bloco 7: Acesso Rápido - Edição Uônix
function uox_render_quick_links()
{
    ?>
    <p style="font-size: 13px; color: #64748b; margin-top: 0; margin-bottom: 15px; line-height: 1.5;">
        Clique nos botões abaixo para editar as seções principais do site de forma direta, sem precisar navegar pelos menus.
    </p>
    <div class="uox-dashboard-grid">
        <a href="/wp-admin/post.php?post=6130&action=edit" target="_blank" rel="noopener noreferrer" class="uox-btn">
            <span class="dashicons dashicons-format-image"></span> Banner Home
        </a>
        <a href="/wp-admin/site-editor.php?p=%2Fwp_block%2F10973&canvas=edit" target="_blank" rel="noopener noreferrer"
            class="uox-btn">
            <span class="dashicons dashicons-buddicons-community"></span> Selo Aniversário
        </a>
        <a href="/wp-admin/site-editor.php?p=%2Fwp_block%2F7255&canvas=edit" target="_blank" rel="noopener noreferrer"
            class="uox-btn">
            <span class="dashicons dashicons-cart"></span> Banner Produtos
        </a>
        <a href="/wp-admin/site-editor.php?p=%2Fwp_block%2F3631&canvas=edit" target="_blank" rel="noopener noreferrer"
            class="uox-btn">
            <span class="dashicons dashicons-phone"></span> Topo (Contatos)
        </a>
        <a href="/wp-admin/site-editor.php?p=%2Fwp_block%2F2859&canvas=edit" target="_blank" rel="noopener noreferrer"
            class="uox-btn">
            <span class="dashicons dashicons-editor-help"></span> Dúvidas (FAQ)
        </a>
        <a href="/wp-admin/upload.php?page=uonix-curriculos-recebidos" class="uox-btn">
            <span class="dashicons dashicons-media-text"></span> Currículos Recebidos
        </a>
        <a href="/wp-admin/admin.php?page=fluent_forms_all_entries" class="uox-btn">
            <span class="dashicons dashicons-email-alt"></span> Leads
        </a>
        <a href="/wp-admin/widgets.php" class="uox-btn">
            <span class="dashicons dashicons-table-row-after"></span> Rodapé
        </a>
        <a href="/wp-admin/admin.php?page=uox-dados-globais" class="uox-btn uox-btn-primary uox-btn-full">
            <span class="dashicons dashicons-building"></span> Alterar Telefones e Endereço
        </a>
    </div>
    <?php
}

// NOVO: Bloco 8 - Mini CRM de Orçamentos Recentes (Foco Operacional)
function uox_render_crm_orcamentos()
{
    if (!function_exists('wc_get_orders')) {
        echo '<p style="color:#64748b; font-size:13px;">WooCommerce offline.</p>';
        return;
    }

    // Busca os 4 pedidos mais recentes, INDEPENDENTE do status ('any')
    $orders = wc_get_orders(array(
        'limit' => 4,
        'status' => 'any'
    ));

    if (empty($orders)) {
        echo '<ul class="uox-list"><li class="uox-empty"><span>Nenhum orçamento registrado no momento.</span></li></ul>';
        return;
    }

    echo '<ul class="uox-list">';
    foreach ($orders as $order) {
        $responsavel = $order->get_meta('billing_complete_name') ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $empresa = $order->get_meta('billing_company_name') ?: $order->get_billing_company();

        $nome_exibir = $empresa ? $empresa : $responsavel;
        $link_edit = esc_url(admin_url('post.php?post=' . $order->get_id() . '&action=edit'));

        // Exibe apenas o Link e o Nome Completo (limite de 3 palavras removido)
        echo '<li>
                <span><a href="' . $link_edit . '" style="text-decoration:none; font-weight:600; color:#0e3780;">#' . $order->get_order_number() . '</a> - ' . esc_html($nome_exibir) . '</span> 
              </li>';
    }
    echo '</ul>';
    echo '<div class="uox-btn-group"><a href="edit.php?post_type=shop_order" class="uox-btn uox-btn-primary">Ver Todos os Pedidos</a></div>';
}

// NOVO: Bloco 9 - Botão de Limpeza do Cache Dinâmico
if (!function_exists('uox_cache_flush_throttle_seconds')) {
    /**
     * Janela mínima entre duas purgas manuais de cache, em segundos.
     *
     * Enquanto o botão só limpava cache de objeto ele era inofensivo. Agora que
     * purga o cache de PÁGINA, cada clique esfria o site inteiro: a home era
     * servida do disco em 260 ms e categoria/produto regeneravam em ~2,1 s e
     * ~2,8 s (medições registradas em 32-rfq-stable-asset-version.php). Um editor
     * publicando em sequência clica várias vezes seguidas, e o p95 público já
     * estava acima da meta de 600 ms.
     *
     * A capability segue `edit_posts` de propósito: o botão existe justamente
     * para o editor não depender de um dev. O throttle limita o dano sem tirar a
     * ferramenta de quem precisa dela.
     *
     * Filtrável para ajuste sem alterar código. Zero desliga o throttle.
     *
     * @return int
     */
    function uox_cache_flush_throttle_seconds()
    {
        $seconds = (int) apply_filters('uonix_cache_flush_throttle_seconds', 60);

        return $seconds > 0 ? $seconds : 0;
    }
}

if (!function_exists('uox_cache_flush_remaining_seconds')) {
    /**
     * Segundos restantes da janela de throttle, para o aviso ao editor.
     *
     * O transient guarda o instante da última purga, então o restante é derivado —
     * nunca a janela inteira, que faria quem esperou 55s ler "aguarde 60 segundos".
     *
     * Com o throttle ativo nunca devolve 0: o aviso só aparece quando a purga FOI
     * recusada, e "aguarde 0 segundos" contradiria a recusa. Devolve 0 apenas quando o
     * throttle está DESLIGADO (janela 0) — situação em que o handler nunca redireciona
     * para o estado "aguarde", e o renderer não imprime o aviso.
     *
     * @return int
     */
    function uox_cache_flush_remaining_seconds()
    {
        $janela = uox_cache_flush_throttle_seconds();

        if ($janela <= 0) {
            return 0;
        }

        $inicio = get_transient('uonix_cache_flush_lock');

        if (!is_numeric($inicio)) {
            return $janela;
        }

        $restante = $janela - (time() - (int) $inicio);

        if ($restante < 1) {
            return 1;
        }

        return $restante > $janela ? $janela : $restante;
    }
}

function uox_handle_flush_cache()
{
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
        wp_die('Método inválido para limpar o cache.');
    }

    if (!current_user_can('edit_posts')) {
        wp_die('Você não tem permissão para limpar o cache.');
    }

    check_admin_referer('uonix_flush_cache');

    // Throttle DEPOIS de método, capability e nonce: gravar o transient é efeito
    // colateral, e nenhum efeito colateral pode acontecer antes da autorização.
    // Também impede que a janela seja sondada por quem não passou pelos gates.
    $throttle_seconds = uox_cache_flush_throttle_seconds();

    if ($throttle_seconds > 0 && get_transient('uonix_cache_flush_lock')) {
        // Redirect com estado próprio, não com sucesso: dizer "limpou" sem ter
        // limpado é o mesmo defeito que este bloco de código acabou de corrigir na
        // outra ponta.
        wp_safe_redirect(add_query_arg('uonix_cache_flushed', 'aguarde', admin_url('index.php')));
        exit;
    }

    // O site tem DUAS camadas de cache locais, disjuntas por configuração, e o
    // botão precisa das duas. wp_cache_flush() cobre só a primeira.
    //
    // Existe uma TERCEIRA camada, fora do servidor, que este botão NÃO alcança: a
    // borda da Cloudflare. Não há chamada à API de purge aqui, então a borda só
    // expira por TTL. É por isso que o aviso de sucesso não promete limpeza total —
    // ver uox_render_manutencao_cache(). Implementar a purga real depende de
    // provisionar CLOUDFLARE_ZONE_ID e CLOUDFLARE_API_TOKEN como constantes no
    // wp-config.php de produção, e está rastreado em issue própria.
    //
    // 1) Cache de OBJETO.
    wp_cache_flush();

    // 2) Cache de PÁGINA (WP Super Cache, modo Simple/PHP). É esta que guarda o
    //    HTML que o editor está tentando atualizar, e wp_cache_flush() NÃO a
    //    alcança: o perfil aplicado por scripts/configure-wp-super-cache-simple.php
    //    grava wp_cache_object_cache = 0, então o WPSC escreve em disco e ignora
    //    o cache de objeto. Enquanto só havia wp_cache_flush() aqui, o aviso
    //    "cache totalmente limpa" saía e a página continuava vindo do arquivo
    //    estático antigo.
    //
    //    wp_cache_clear_cache() é a purga total do próprio plugin — a mesma que o
    //    painel e a REST API dele chamam. Ela poda supercache/ e a raiz de
    //    $cache_path e dispara a action wp_cache_cleared. Verificado no WPSC 3.1.3
    //    de produção: wp-cache-phase2.php:3411.
    //
    //    function_exists porque o WPSC é instalado somente pelo deploy de
    //    produção; em QA e local o botão precisa seguir funcionando sem ele.
    //    Não há fallback para prune_super_cache(): as duas funções vivem no mesmo
    //    wp-cache-phase2.php, então um elseif entre elas seria inalcançável.
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }

    if ($throttle_seconds > 0) {
        // Guarda o INSTANTE da purga, não um booleano: é o que permite informar
        // quanto falta em vez de repetir a janela inteira. O TTL vem do throttle,
        // nunca 0 — no WordPress, expiração 0 significa transient SEM expiração, e o
        // botão viraria trava permanente de uso único.
        set_transient('uonix_cache_flush_lock', time(), $throttle_seconds);
    }

    wp_safe_redirect(add_query_arg('uonix_cache_flushed', '1', admin_url('index.php')));
    exit;
}
add_action('admin_post_uonix_flush_cache', 'uox_handle_flush_cache');

function uox_render_manutencao_cache()
{
    $flush_state = isset($_GET['uonix_cache_flushed']) && is_string($_GET['uonix_cache_flushed'])
        ? sanitize_key(wp_unslash($_GET['uonix_cache_flushed']))
        : '';

    if ('1' === $flush_state) {
        // O aviso descreve SOMENTE o que o botão realmente faz. Dizer "totalmente
        // limpa" era falso: a borda da Cloudflare fica intacta e continua servindo
        // HTML antigo por até ~1h, então o editor via conteúdo velho depois de um
        // "sucesso" e perdia confiança na ferramenta.
        echo '<div class="notice notice-success is-dismissible" style="margin: 0 0 15px 0; border-radius:6px;"><p>Cache do servidor limpo: memória de objetos e páginas gravadas em disco. A borda da Cloudflare não é limpa por aqui — ela expira sozinha, o que pode levar cerca de uma hora.</p></div>';
    } elseif ('aguarde' === $flush_state && uox_cache_flush_throttle_seconds() > 0) {
        // A guarda da janela > 0 evita "Aguarde 0 segundo(s)": com o throttle desligado
        // o handler nunca redireciona para este estado, então só se chega aqui por URL
        // obsoleta ou montada à mão.
        // Aviso explícito, não silêncio: o editor precisa saber que NÃO limpou
        // agora, e por quê. Um "sucesso" aqui reproduziria o defeito original.
        //
        // Mostra o tempo RESTANTE, não a janela inteira: quem esperou 55s e clicou de
        // novo não pode ler "aguarde 60 segundos". E deixa claro que a janela é do
        // SITE, não do usuário — o lock é único, então a limpeza pode ter sido feita
        // por outro editor.
        printf(
            '<div class="notice notice-info is-dismissible" style="margin: 0 0 15px 0; border-radius:6px;"><p>A memória cache do site já foi limpa nos últimos instantes, por você ou por outro editor. Aguarde %d segundo(s) antes de limpar de novo — cada limpeza deixa o site mais lento enquanto as páginas são regeradas.</p></div>',
            (int) uox_cache_flush_remaining_seconds()
        );
    }

    if (!current_user_can('edit_posts')) {
        echo '<p>Você não tem permissão para limpar o cache do site.</p>';
        return;
    }
    ?>
    <p style="font-size: 13px; color: #64748b; margin-top: 0; margin-bottom: 15px; line-height: 1.5;">
        Caso faça alterações em textos, imagens ou banners e não consiga visualizar de imediato, limpe o cache do servidor
        clicando abaixo. Isso cobre a memória de objetos e as páginas gravadas em disco, mas não a borda da Cloudflare, que
        expira por conta própria.
    </p>
    <div class="uox-btn-group">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('uonix_flush_cache'); ?>
            <input type="hidden" name="action" value="uonix_flush_cache">
            <button type="submit" class="uox-btn uox-btn-primary">Limpar Cache do Servidor</button>
        </form>
    </div>
    <?php
    uox_render_lista_atualizacoes_pendentes();
}

// Classifica a gravidade de plugins/temas comparando major/minor: o
// WordPress não informa "quantas versões atrasadas", só a atual e a mais
// nova disponível. O núcleo não passa por aqui — ver uox_get_atualizacoes_pendentes().
function uox_get_gravidade_atualizacao($versao_atual, $versao_nova)
{
    $atual = array_pad(array_map('intval', explode('.', (string) $versao_atual)), 3, 0);
    $nova = array_pad(array_map('intval', explode('.', (string) $versao_nova)), 3, 0);

    if ($nova[0] > $atual[0]) {
        return 'alta';
    }

    if ($nova[0] === $atual[0] && $nova[1] > $atual[1]) {
        return 'media';
    }

    return 'baixa';
}

// Ordem de prioridade das gravidades, da mais urgente para a mais branda.
function uox_ordem_gravidade($gravidade)
{
    $ordem = array('critica' => 0, 'alta' => 1, 'media' => 2, 'baixa' => 3);

    return $ordem[$gravidade] ?? 99;
}

// Lê os transients que o próprio WordPress já mantém via cron de verificação
// de atualizações (wp_version_check/wp_update_plugins/wp_update_themes) —
// sem chamada extra à API do wordpress.org nesta função. Retorna os itens já
// agrupados por aba (plugins/core/temas) e ordenados da gravidade mais alta
// para a mais baixa dentro de cada grupo.
function uox_get_atualizacoes_pendentes()
{
    $grupos = array(
        'plugins' => array(),
        'core' => array(),
        'temas' => array(),
    );

    // WordPress (núcleo): gravidade sempre "crítica", fixa — é a base de todo
    // o site e normalmente carrega correções de segurança, independente da
    // distância numérica entre as versões.
    global $wp_version;
    $core_updates = get_site_transient('update_core');

    if (!empty($core_updates->updates[0]) && 'latest' !== $core_updates->updates[0]->response) {
        $grupos['core'][] = array(
            'nome' => 'WordPress (núcleo)',
            'versao_atual' => $wp_version,
            'versao_nova' => $core_updates->updates[0]->current,
            'gravidade' => 'critica',
        );
    }

    // Plugins.
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $plugin_updates = get_site_transient('update_plugins');

    if (!empty($plugin_updates->response) && is_array($plugin_updates->response)) {
        $instalados = get_plugins();

        foreach ($plugin_updates->response as $arquivo_plugin => $dados) {
            // O transient só é refeito pelo cron: um plugin apagado fora do
            // WordPress (deploy, SSH) continuaria aparecendo como "? → x.y.z",
            // com gravidade "Alta" (intval('?') = 0). Não instalado, não lista.
            if (!isset($instalados[$arquivo_plugin])) {
                continue;
            }

            $versao_atual = $instalados[$arquivo_plugin]['Version'] ?? '?';
            $versao_nova = $dados->new_version ?? '?';

            $grupos['plugins'][] = array(
                'nome' => $instalados[$arquivo_plugin]['Name'] ?? $arquivo_plugin,
                'versao_atual' => $versao_atual,
                'versao_nova' => $versao_nova,
                'gravidade' => uox_get_gravidade_atualizacao($versao_atual, $versao_nova),
            );
        }
    }

    // Temas (todos os instalados com atualização pendente, não só o ativo —
    // o site usa tema filho, então o pai também pode aparecer aqui).
    $theme_updates = get_site_transient('update_themes');

    if (!empty($theme_updates->response) && is_array($theme_updates->response)) {
        foreach ($theme_updates->response as $stylesheet => $dados) {
            $tema = wp_get_theme($stylesheet);

            // Mesmo caso dos plugins: tema removido fora do WordPress não lista.
            if (!$tema->exists()) {
                continue;
            }

            $versao_atual = $tema->get('Version');
            // A API de temas devolve array nativo (diferente de plugins, que usa objeto).
            $versao_nova = is_array($dados) ? ($dados['new_version'] ?? '?') : ($dados->new_version ?? '?');

            $grupos['temas'][] = array(
                'nome' => $tema->get('Name'),
                'versao_atual' => $versao_atual,
                'versao_nova' => $versao_nova,
                'gravidade' => uox_get_gravidade_atualizacao($versao_atual, $versao_nova),
            );
        }
    }

    foreach ($grupos as &$itens_grupo) {
        usort($itens_grupo, function ($a, $b) {
            return uox_ordem_gravidade($a['gravidade']) <=> uox_ordem_gravidade($b['gravidade']);
        });
    }
    unset($itens_grupo);

    return $grupos;
}

// Somente informativo: sem link, formulário ou atalho para disparar
// atualização — os únicos botões são de navegação (abas e paginação). O papel
// editor não tem (e não deve ganhar aqui) a capability
// update_plugins/update_core/update_themes.
function uox_render_lista_atualizacoes_pendentes()
{
    $grupos = uox_get_atualizacoes_pendentes();

    // Sem nenhuma pendência, o bloco inteiro some do card.
    if (0 === array_sum(array_map('count', $grupos))) {
        return;
    }

    $cores_gravidade = array(
        'critica' => array('bg' => '#450a0a', 'cor' => '#ffffff', 'label' => 'Crítica'),
        'alta' => array('bg' => '#fee2e2', 'cor' => '#b91c1c', 'label' => 'Alta'),
        'media' => array('bg' => '#fef3c7', 'cor' => '#92400e', 'label' => 'Média'),
        'baixa' => array('bg' => '#dbeafe', 'cor' => '#1e40af', 'label' => 'Baixa'),
    );

    $rotulos_categoria = array(
        'plugins' => 'Plugin',
        'core' => null,
        'temas' => 'Tema',
    );

    // "Todos" junta as categorias, da gravidade mais alta para a mais baixa;
    // cada item leva a categoria para ser identificado fora da própria aba —
    // menos o núcleo, cujo nome já diz "WordPress".
    $todos = array();

    foreach (array('core', 'plugins', 'temas') as $chave) {
        foreach ($grupos[$chave] as $item) {
            if (null !== $rotulos_categoria[$chave]) {
                $item['categoria'] = $rotulos_categoria[$chave];
            }

            $todos[] = $item;
        }
    }

    usort($todos, function ($a, $b) {
        return uox_ordem_gravidade($a['gravidade']) <=> uox_ordem_gravidade($b['gravidade']);
    });

    $paineis = array(
        'todos' => array('rotulo' => 'Todos', 'itens' => $todos),
        'plugins' => array('rotulo' => 'Plugins', 'itens' => $grupos['plugins']),
        'core' => array('rotulo' => 'WordPress', 'itens' => $grupos['core']),
        'temas' => array('rotulo' => 'Tema', 'itens' => $grupos['temas']),
    );

    // Categoria sem pendência não ganha aba.
    $paineis = array_filter($paineis, function ($painel) {
        return !empty($painel['itens']);
    });

    // Com uma categoria só, "Todos" repetiria a mesma lista: fica a aba única.
    if (2 === count($paineis)) {
        unset($paineis['todos']);
    }

    // Abre na primeira aba que sobrou — "Todos", quando existe.
    $aba_ativa = array_key_first($paineis);

    // Limite por página, para o card não crescer sem fim no dashboard.
    $por_pagina = 10;
    $estilo_botao_pagina = 'font-size:12px; padding:3px 10px; border-radius:6px; border:1px solid #cbd5e1; background:#ffffff; color:#334155; cursor:pointer;';

    echo '<div class="uox-atualizacoes-sistemicas" id="uox-atualizacoes-sistemicas" style="margin-top: 18px; padding-top: 15px; border-top: 1px solid #e2e8f0;">';
    // Os itens têm display:flex inline, que venceria o [hidden] do navegador —
    // sem esta regra, as páginas 2+ e os painéis ocultos continuariam à vista.
    echo '<style>#uox-atualizacoes-sistemicas [hidden]{display:none !important;}#uox-atualizacoes-sistemicas [data-uox-pagina-acao]:disabled{opacity:.45;cursor:default;}</style>';
    echo '<p style="font-size: 13px; font-weight: 600; color: #334155; margin: 0 0 10px 0;">Atualizações Sistêmicas</p>';
    echo '<div class="uox-atualizacoes-tabs" role="tablist" aria-label="Categorias de atualização" style="display:flex; gap:6px; margin-bottom:10px; flex-wrap:wrap;">';

    foreach ($paineis as $chave => $painel) {
        $ativa = $aba_ativa === $chave;

        printf(
            '<button type="button" class="uox-atualizacoes-tab%1$s" id="uox-atualizacoes-tab-%2$s" data-uox-aba="%2$s" role="tab" aria-selected="%3$s" aria-controls="uox-atualizacoes-painel-%2$s" tabindex="%4$s" style="font-size:12px; font-weight:600; padding:5px 12px; border-radius:999px; border:1px solid #cbd5e1; background:%5$s; color:%6$s; cursor:pointer;">%7$s (%8$d)</button>',
            $ativa ? ' is-active' : '',
            esc_attr($chave),
            $ativa ? 'true' : 'false',
            $ativa ? '0' : '-1',
            $ativa ? '#334155' : '#f1f5f9',
            $ativa ? '#ffffff' : '#334155',
            esc_html($painel['rotulo']),
            count($painel['itens'])
        );
    }

    echo '</div>';

    // Altura travada pelo JS na maior página entre as abas (normalmente a 1 de
    // "Todos"), para o card não mudar de tamanho ao trocar de aba ou de página.
    echo '<div class="uox-atualizacoes-paineis" style="position: relative; overflow-y: auto;">';

    foreach ($paineis as $chave => $painel) {
        $paginas = (int) ceil(count($painel['itens']) / $por_pagina);

        printf(
            '<div class="uox-atualizacoes-painel" data-uox-painel="%1$s" id="uox-atualizacoes-painel-%1$s" role="tabpanel" aria-labelledby="uox-atualizacoes-tab-%1$s" tabindex="0" data-uox-pagina-atual="1" data-uox-paginas="%2$d" style="display:flex; flex-direction:column; min-height:100%%; box-sizing:border-box;"%3$s>',
            esc_attr($chave),
            $paginas,
            $aba_ativa === $chave ? '' : ' hidden'
        );

        echo '<ul style="list-style: none; margin: 0; padding: 0; font-size: 13px; color: #334155;">';

        foreach (array_values($painel['itens']) as $indice => $item) {
            $gravidade = $cores_gravidade[$item['gravidade']];
            $pagina = intdiv($indice, $por_pagina) + 1;

            printf(
                '<li data-uox-pagina="%d"%s style="display:flex; justify-content:space-between; align-items:center; gap:12px; padding:6px 0; border-bottom:1px solid #f1f5f9;">
                    <span>%s%s <span style="color:#94a3b8;">(%s &rarr; %s)</span></span>
                    <span style="background:%s; color:%s; font-size:11px; font-weight:600; padding:2px 8px; border-radius:10px; white-space:nowrap;">%s</span>
                </li>',
                $pagina,
                $pagina > 1 ? ' hidden' : '',
                isset($item['categoria']) ? '<span style="color:#94a3b8; font-size:11px;">' . esc_html($item['categoria']) . ' &middot;</span> ' : '',
                esc_html($item['nome']),
                esc_html($item['versao_atual']),
                esc_html($item['versao_nova']),
                esc_attr($gravidade['bg']),
                esc_attr($gravidade['cor']),
                esc_html($gravidade['label'])
            );
        }

        echo '</ul>';

        if ($paginas > 1) {
            printf(
                // margin-top:auto cola a paginação no fundo da área de altura
                // fixa, mesmo numa última página com poucos itens.
                '<div class="uox-atualizacoes-paginacao" style="display:flex; justify-content:flex-end; align-items:center; gap:8px; margin-top:auto; padding-top:8px; font-size:12px; color:#64748b;">
                    <button type="button" data-uox-pagina-acao="anterior" disabled style="%1$s">&lsaquo; Anterior</button>
                    <span data-uox-pagina-status aria-live="polite">Página 1 de %2$d</span>
                    <button type="button" data-uox-pagina-acao="proxima" style="%1$s">Próxima &rsaquo;</button>
                </div>',
                esc_attr($estilo_botao_pagina),
                $paginas
            );
        }

        echo '</div>';
    }

    echo '</div>';

    echo '<p class="uox-atualizacoes-aviso" style="margin: 12px 0 0 0; padding: 8px 10px; border-left: 3px solid #b91c1c; background: #fef2f2; color: #7f1d1d; font-size: 12px; line-height: 1.5;"><strong>&#9888; Atenção:</strong> atualizações atrasadas deixam o site exposto a falhas de segurança e invasões. Elas devem ser aplicadas no perfil de <strong>administrador</strong>, sempre depois de um <strong>backup completo</strong>.</p>';
    echo '</div>';
    ?>
    <script>
        (function () {
            var wrap = document.getElementById('uox-atualizacoes-sistemicas');

            if (!wrap || wrap.dataset.uoxTabsBound) {
                return;
            }

            wrap.dataset.uoxTabsBound = '1';

            var caixa = wrap.querySelector('.uox-atualizacoes-paineis');

            // Trava a altura da área dos painéis na maior página entre todas as
            // abas — normalmente a página 1 de "Todos", que contém as demais;
            // nomes longos quebrando linha podem fazer outra página passar dela.
            // Mede clones invisíveis, sem mexer no que está à vista.
            function fixarAltura() {
                if (!caixa) {
                    return;
                }

                var maior = 0;

                caixa.querySelectorAll('[data-uox-painel]').forEach(function (painel) {
                    var paginas = parseInt(painel.dataset.uoxPaginas, 10) || 1;

                    for (var pagina = 1; pagina <= paginas; pagina++) {
                        var clone = painel.cloneNode(true);
                        clone.removeAttribute('data-uox-painel');
                        clone.removeAttribute('id');
                        clone.removeAttribute('tabindex');
                        clone.hidden = false;
                        clone.setAttribute('aria-hidden', 'true');
                        // Atribuição (=), não concatenação: precisa descartar o
                        // min-height:100% inline do painel, senão o clone mede ao
                        // menos a altura já travada e a trava nunca encolhe.
                        clone.style.cssText = 'position:absolute; top:0; left:0; right:0; visibility:hidden; pointer-events:none;';
                        clone.querySelectorAll('[data-uox-pagina]').forEach(function (item) {
                            item.hidden = String(pagina) !== item.dataset.uoxPagina;
                        });

                        caixa.appendChild(clone);
                        maior = Math.max(maior, clone.offsetHeight);
                        caixa.removeChild(clone);
                    }
                });

                // Medida 0 = widget recolhido ou oculto em "Opções de tela":
                // travar em 0 esconderia a lista até o próximo resize.
                caixa.style.height = maior > 0 ? maior + 'px' : '';
            }

            fixarAltura();
            window.addEventListener('load', fixarAltura);

            // A largura muda a quebra de linha dos itens — por resize da janela,
            // mas também ao abrir o widget recolhido, arrastá-lo de coluna ou
            // mudar o número de colunas, que não disparam resize. Só a largura
            // importa: a altura que este código trava não pode realimentá-lo.
            if ('ResizeObserver' in window) {
                var larguraMedida = -1;

                new ResizeObserver(function () {
                    if (wrap.clientWidth === larguraMedida) {
                        return;
                    }

                    larguraMedida = wrap.clientWidth;
                    fixarAltura();
                }).observe(wrap);
            } else {
                window.addEventListener('resize', fixarAltura);
            }

            function irParaPagina(painel, pagina) {
                var total = parseInt(painel.dataset.uoxPaginas, 10) || 1;
                pagina = Math.min(Math.max(pagina, 1), total);
                painel.dataset.uoxPaginaAtual = String(pagina);

                painel.querySelectorAll('[data-uox-pagina]').forEach(function (item) {
                    item.hidden = parseInt(item.dataset.uoxPagina, 10) !== pagina;
                });

                var status = painel.querySelector('[data-uox-pagina-status]');
                var anterior = painel.querySelector('[data-uox-pagina-acao="anterior"]');
                var proxima = painel.querySelector('[data-uox-pagina-acao="proxima"]');

                if (status) {
                    status.textContent = 'Página ' + pagina + ' de ' + total;
                }

                if (anterior) {
                    anterior.disabled = pagina <= 1;
                }

                if (proxima) {
                    proxima.disabled = pagina >= total;
                }
            }

            wrap.addEventListener('click', function (event) {
                var acao = event.target.closest('[data-uox-pagina-acao]');

                if (acao) {
                    var painelAtual = acao.closest('[data-uox-painel]');
                    var atual = parseInt(painelAtual.dataset.uoxPaginaAtual, 10) || 1;
                    irParaPagina(painelAtual, 'proxima' === acao.dataset.uoxPaginaAcao ? atual + 1 : atual - 1);
                    return;
                }

                var tab = event.target.closest('[data-uox-aba]');

                if (tab) {
                    ativarAba(tab);
                }
            });

            // Teclado no padrão ARIA de abas: setas, Home e End movem o foco e
            // ativam a aba; só a aba ativa fica na ordem do Tab (tabindex 0).
            wrap.querySelector('[role="tablist"]').addEventListener('keydown', function (event) {
                var abas = Array.prototype.slice.call(wrap.querySelectorAll('[data-uox-aba]'));
                var atual = abas.indexOf(document.activeElement);

                if (-1 === atual) {
                    return;
                }

                var destino = {
                    ArrowRight: (atual + 1) % abas.length,
                    ArrowLeft: (atual - 1 + abas.length) % abas.length,
                    Home: 0,
                    End: abas.length - 1
                }[event.key];

                if (undefined === destino) {
                    return;
                }

                event.preventDefault();
                ativarAba(abas[destino]);
                abas[destino].focus();
            });

            function ativarAba(tab) {
                var aba = tab.dataset.uoxAba;

                // Trocar de aba recomeça a paginação de todas na página 1.
                if (!tab.classList.contains('is-active')) {
                    wrap.querySelectorAll('[data-uox-painel]').forEach(function (painel) {
                        irParaPagina(painel, 1);
                    });
                }

                wrap.querySelectorAll('[data-uox-aba]').forEach(function (botao) {
                    var ativo = botao === tab;
                    botao.classList.toggle('is-active', ativo);
                    botao.setAttribute('aria-selected', ativo ? 'true' : 'false');
                    botao.tabIndex = ativo ? 0 : -1;
                    botao.style.background = ativo ? '#334155' : '#f1f5f9';
                    botao.style.color = ativo ? '#ffffff' : '#334155';
                });

                wrap.querySelectorAll('[data-uox-painel]').forEach(function (painel) {
                    painel.hidden = painel.dataset.uoxPainel !== aba;
                });
            }
        })();
    </script>
    <?php
}

// NOVO: Bloco 10 - Suporte Técnico (Sua Assinatura)
function uox_render_suporte_vip()
{
    ?>
    <p style="font-size: 13px; color: #64748b; margin-top: 0; margin-bottom: 15px; line-height: 1.5;">
        Painel corporativo desenvolvido sob medida.<br>
        <strong>Status do Servidor:</strong> Online <span style="color:#16a34a">●</span><br>
        <strong>Ambiente de Homologação:</strong> Monitorado 🔒
    </p>
    <div class="uox-btn-group">
        <a href="https://wa.me/5511999999999?text=Oi%20Cassio,%20preciso%20de%20ajuda%20no%20painel%20da%20Uonix."
            target="_blank" class="uox-btn" style="background:#25d366; color:#ffffff; border-color:#25d366;">
            Chamar Suporte Técnico
        </a>
    </div>
    <?php
}


