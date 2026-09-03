<?php
if ( ! defined( 'ABSPATH' ) ) {
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
add_action( 'admin_menu', function() {
    $user = wp_get_current_user();
    
    if ( in_array( 'editor', (array) $user->roles ) ) {
        // Remove lixo de plugins e menus intrusos
        remove_menu_page( 'edit.php?post_type=wcps' );
        remove_menu_page( 'kadence-blocks-home' );
        remove_menu_page( 'kadence-blocks' ); 
        remove_menu_page( 'kadence-starter-templates' ); // Remove Site Assist
        remove_menu_page( 'maxmegamenu' ); 
		remove_menu_page( 'wp-reviews-plugin-for-google/settings.php' ); 
        remove_menu_page( 'pods' ); // Garantia contra o Pods
        remove_menu_page( 'ai1wm_export' ); // Garantia contra All-in-One WP Migration
        
        // Limpa WooCommerce
        remove_menu_page( 'woocommerce' );
        remove_menu_page( 'wc-admin' );
        remove_menu_page( 'admin.php?page=wc-settings&tab=checkout&from=PAYMENTS_MENU_ITEM' );
        
        // Adiciona atalho direto para Pedidos
        add_menu_page( 'Pedidos', 'Pedidos', 'edit_shop_orders', 'edit.php?post_type=shop_order', '', 'dashicons-cart', 55 );
        
        // Ajustes do Fluent Forms 
        remove_submenu_page( 'fluent_forms', 'fluent_forms' ); // Remove a lista de Formulários
        remove_submenu_page( 'fluent_forms', 'fluent_forms_docs' );
        remove_submenu_page( 'fluent_forms', 'fluent_forms_reports' );
        remove_submenu_page( 'fluent_forms', 'fluent_forms_settings' );
        remove_submenu_page( 'fluent_forms', 'fluent_forms_transfer' );
        remove_submenu_page( 'fluent_forms', 'fluent_forms_smtp' );
        remove_submenu_page( 'fluent_forms', 'fluent_forms_add_ons' );
        
        global $menu, $submenu;
        
        // Renomeia o menu principal
        foreach ( $menu as $key => $item ) {
            if ( $item[2] === 'fluent_forms' ) {
                $menu[$key][0] = 'Leads'; // Nome do menu principal
                break;
            }
        }

        // Renomeia o submenu padrão "Entradas" para "Todas as Entradas"
        if ( isset( $submenu['fluent_forms'] ) ) {
            foreach ( $submenu['fluent_forms'] as $key => $item ) {
                if ( $item[2] === 'fluent_forms_all_entries' ) {
                    $submenu['fluent_forms'][$key][0] = 'Todas as Entradas';
                }
            }
        }
        
        // INJETA OS LINKS DIRETOS COMO SUBMENUS EXTRAS
        $submenu['fluent_forms'][] = array( 'Captura de Leads', 'read', 'admin.php?page=fluent_forms&route=entries&form_id=4' );
        $submenu['fluent_forms'][] = array( 'Formulário de Contato', 'read', 'admin.php?page=fluent_forms&route=entries&form_id=3' );
        $submenu['fluent_forms'][] = array( 'Assinantes Newsletters', 'read', 'admin.php?page=fluent_forms&route=entries&form_id=2' );
    }
}, 999 );

// =========================================================================
// 2. OCULTAR MENUS NATIVOS COM SEGURANÇA (Via CSS)
// =========================================================================
add_action( 'admin_head', function() {
    $user = wp_get_current_user();
    if ( in_array( 'editor', (array) $user->roles ) ) {
        echo '<style>
            /* Oculta Ferramentas e Aparência VISUALMENTE para não confundir */
            #menu-tools, #menu-appearance { display: none !important; }
        </style>';
    }
});

// =========================================================================
// 3. LIMPEZA DE FEATURES DO WOOCOMMERCE
// =========================================================================
add_filter( 'woocommerce_admin_features', function( $features ) {
    $user = wp_get_current_user();
    if ( in_array( 'editor', (array) $user->roles ) ) {
        return array_values( array_diff( $features, [ 'marketing', 'payments', 'analytics', 'onboarding' ] ) );
    }
    return $features;
} );

// =========================================================================
// 4. LIBERAR EDIÇÃO DA POLÍTICA DE PRIVACIDADE
// =========================================================================
add_filter( 'map_meta_cap', function( $caps, $cap, $user_id, $args ) {
    if ( 'edit_post' !== $cap || empty( $args[0] ) ) return $caps;
    
    $post_id = (int) $args[0];
    $policy_page_id = (int) get_option( 'wp_page_for_privacy_policy' );

    if ( $post_id === $policy_page_id && user_can( $user_id, 'editor' ) ) {
        $caps = array( 'edit_pages' );
    }
    return $caps;
}, 10, 4 );

// =========================================================================
// 5. REDIRECIONAMENTO DE LEADS 
// =========================================================================
add_action( 'admin_init', function() {
    // Se clicar no menu pai (fluent_forms) E NÃO tiver o parâmetro 'route' na URL
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'fluent_forms' && ! isset( $_GET['route'] ) ) {
        $user = wp_get_current_user();
        if ( in_array( 'editor', (array) $user->roles ) ) {
            // Redireciona de volta para a visão geral (Todas as Entradas)
            wp_redirect( admin_url( 'admin.php?page=fluent_forms_all_entries' ) );
            exit;
        }
    }
});

// =========================================================================
// 6. REMOVER / AJUSTAR ITENS DA BARRA SUPERIOR PARA EDITOR
// =========================================================================
add_action( 'admin_bar_menu', function( $wp_admin_bar ) {
    $user = wp_get_current_user();

    if ( ! in_array( 'editor', (array) $user->roles, true ) ) {
        return;
    }

    // Remove o botão "Personalizar"
    $wp_admin_bar->remove_node( 'customize' );

    // Remove submenus do nome do site na barra superior
    $wp_admin_bar->remove_node( 'dashboard' );
    $wp_admin_bar->remove_node( 'themes' );
    $wp_admin_bar->remove_node( 'widgets' );
    $wp_admin_bar->remove_node( 'menus' );
    $wp_admin_bar->remove_node( 'appearance' );

    // Garante que o nome do site continue clicável e com o link correto
    // Dentro do painel: leva para a HOME.
    // No frontend: leva para o painel.
    $site_node = $wp_admin_bar->get_node( 'site-name' );

    if ( $site_node ) {
        $destino_site_name = is_admin()
            ? home_url( '/' )
            : admin_url( 'index.php' );

        $existing_class = ! empty( $site_node->meta['class'] ) ? $site_node->meta['class'] : '';
        $classes        = array_filter( array_unique( array_merge( explode( ' ', $existing_class ), array( 'uonix-editor-site-link' ) ) ) );

        $meta          = (array) $site_node->meta;
        $meta['class'] = implode( ' ', $classes );

        $wp_admin_bar->add_node( array(
            'id'    => 'site-name',
            'title' => $site_node->title,
            'href'  => $destino_site_name,
            'meta'  => $meta,
        ) );
    }

    // Remove o menu principal "Fluent Forms" da barra superior
    $wp_admin_bar->remove_node( 'fluent_form' );

    // Remove subitens do Fluent Forms, caso algum seja inserido separado
    $wp_admin_bar->remove_node( 'all_forms' );
    $wp_admin_bar->remove_node( 'new_form' );
    $wp_admin_bar->remove_node( 'fluent_forms_all_entries' );
    $wp_admin_bar->remove_node( 'fluent_forms_community' );
    $wp_admin_bar->remove_node( 'fluent_forms_doc' );
    $wp_admin_bar->remove_node( 'fluent_forms_dev_doc' );

    // Remove o menu "Novo" da barra superior
    $wp_admin_bar->remove_node( 'new-content' );

    // Remove o atalho do Loginizer "Open New Tab"
    $wp_admin_bar->remove_node( 'loginizer-admin-shortcut' );

}, 999 );

// =========================================================================
// CSS AJUSTE VISUAL DA BARRA SUPERIOR PARA EDITOR
// =========================================================================
add_action( 'wp_head', 'uonix_editor_admin_bar_front_css', 999 );
add_action( 'admin_head', 'uonix_editor_admin_bar_front_css', 999 );

function uonix_editor_admin_bar_front_css() {
    if ( ! is_user_logged_in() ) {
        return;
    }

    $user = wp_get_current_user();

    if ( ! in_array( 'editor', (array) $user->roles, true ) ) {
        return;
    }

    echo '<style>
        #wpadminbar #wp-admin-bar-customize {
            display: none !important;
        }

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
add_action( 'admin_head', function() {
    $user = wp_get_current_user();

    if ( in_array( 'editor', (array) $user->roles, true ) ) {
        echo '<style>
            body.post-type-shop_order a.woocommerce-BlankState-cta[href*="managing-orders"],
            body.woocommerce_page_wc-orders a.woocommerce-BlankState-cta[href*="managing-orders"],
            body.post-type-shop_order .woocommerce-BlankState-cta.button-primary,
            body.woocommerce_page_wc-orders .woocommerce-BlankState-cta.button-primary {
                display: none !important;
            }
        </style>';
    }
}, 999 );

// =========================================================================
// 8. FALLBACK JS PARA REMOVER BOTÃO DE DOCUMENTAÇÃO DE PEDIDOS
// =========================================================================
add_action( 'admin_footer', function() {
    $user = wp_get_current_user();

    if ( ! in_array( 'editor', (array) $user->roles, true ) ) {
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

                buttons.forEach(function(button) {
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
}, 999 );

/**
 * DASHBOARD PAINEL PRINCIPAL
 */
// =========================================================================
// 1. REMOVE O PAINEL DE BOAS-VINDAS NATIVO E O TÍTULO PADRÃO
// =========================================================================
add_action('load-index.php', 'uonix_remove_default_welcome');
function uonix_remove_default_welcome() {
    $user = wp_get_current_user();
    if (in_array('editor', (array) $user->roles)) {
        remove_action('welcome_panel', 'wp_welcome_panel');
    }
}

// =========================================================================
// 2. CRIA O NOVO CABEÇALHO PREMIUM DA UÔNIX (Substitui o nativo)
// =========================================================================
add_action('welcome_panel', 'uonix_custom_welcome_panel');
function uonix_custom_welcome_panel() {
//     $user = wp_get_current_user();
//     if (!in_array('editor', (array) $user->roles)) return;
    
//     $primeiro_nome = $user->user_firstname ? $user->user_firstname : $user->display_name;
	
	// 1. Pegue o ID do usuário atual (ou defina o ID desejado)
	$user_id = get_current_user_id(); // ou $user->ID

	// 2. Obtenha o primeiro e o último nome
	$first_name = get_user_meta( $user_id, 'first_name', true );
	$last_name  = get_user_meta( $user_id, 'last_name', true );

	// 3. Verifique se ambos estão preenchidos
	if ( ! empty( $first_name ) && ! empty( $last_name ) ) {
		$nome_completo = $first_name . ' ' . $last_name;
	} elseif ( ! empty( $first_name ) ) {
		$nome_completo = $first_name;
	} else {
		// Se não tiver nome cadastrado, usa o display_name
		$user_info     = get_userdata( $user_id );
		$nome_completo = $user_info->display_name; 
	}

    ?>
    <div class="uox-premium-header">
        <div class="uox-header-logo">
            <img src="/wp-content/uploads/2026/01/logo-uonix-branco.png" alt="Uônix">
        </div>
        <div class="uox-header-text">
            <h2>Olá, <?php echo esc_html($nome_completo); ?>!</h2>
            <p>Bem-vindo ao centro de controle do site da Uônix. Acompanhe os resultados e acesse os atalhos rápidos do site.</p>
        </div>
    </div>
    <?php
}

// =========================================================================
// 3. INJETA O CSS CUSTOMIZADO (Design System Premium)
// =========================================================================
add_action('admin_head', 'uonix_dashboard_css');
function uonix_dashboard_css() {
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

    /* --- PADRONIZAÇÃO DOS WIDGETS (CARDS) --- */
    #dashboard-widgets .postbox {
        border: none !important;
        border-radius: 12px !important;
        box-shadow: 0 4px 15px rgba(0,0,0,0.04) !important;
        background: #ffffff !important;
        overflow: hidden;
    }

    #dashboard-widgets .postbox-header {
        border-bottom: 1px solid #f1f5f9 !important;
        background: #ffffff !important;
        padding: 12px 15px !important;
    }

    #dashboard-widgets .postbox-header h2 {
        font-size: 15px !important;
        font-weight: 700 !important;
        color: #0e3780 !important;
    }

    #dashboard-widgets .inside {
        padding: 20px !important;
        margin: 0 !important;
    }

    #dashboard-widgets .button-primary {
        background: #0e3780 !important;
        border-color: #0e3780 !important;
        color: #fff !important;
        border-radius: 6px !important;
        box-shadow: none !important;
        text-shadow: none !important;
        font-weight: 600 !important;
        padding: 6px 14px !important;
    }

    #dashboard-widgets .button-primary:hover {
        background: #1a2b3c !important;
        border-color: #1a2b3c !important;
    }

    /* --- ELEMENTOS INTERNOS UÔNIX --- */
    .uox-stat-box {
        text-align: center;
        padding: 15px 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .uox-stat-box:last-child {
        border-bottom: none;
    }

    .uox-stat-number {
        font-size: 32px;
        font-weight: 800;
        color: #0f172a;
        line-height: 1;
        margin-bottom: 5px;
    }

    .uox-stat-label {
        font-size: 13px;
        color: #64748b;
        font-weight: 500;
    }

    .uox-btn-group {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }

    .uox-btn {
        flex: 1;
        text-align: center;
        padding: 10px 12px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        transition: 0.2s;
        border: 1px solid #cbd5e1;
        color: #0e3780;
        background: #ffffff;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        gap: 6px;
    }
	
	.uox-dashboard-grid .uox-btn {
		justify-content: left;
	}
	
    .uox-btn:hover {
        background: #f8fafc;
        color: #1a2b3c;
        border-color: #94a3b8;
    }

    .uox-btn-primary {
        background: #0e3780;
        color: #ffffff !important;
        border-color: #0e3780;
    }

    .uox-btn-primary:hover {
        background: #1a2b3c;
        color: #ffffff !important;
        border-color: #1a2b3c;
    }

    .uox-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }

    .uox-list li {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 0 4px;
        border-bottom: 1px dashed #e2e8f0;
        font-size: 14px;
        color: #475569;
    }
	
    .uox-list li:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .uox-list li span {
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .uox-list li strong {
        flex-shrink: 0;
        color: #0f172a;
        font-weight: 800;
    }

    .uox-badge {
        background: #dcfce7;
        color: #166534;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
    }

    .uox-badge-blue {
        background: #e0f2fe;
        color: #0284c7;
        border-color: #bae6fd;
    }

    /* --- PAGINAÇÃO DOS RANKINGS --- */
    .uox-pagination {
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid #f1f5f9;
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
        min-width: 30px;
        height: 30px;
        padding: 0 9px;
        border-radius: 7px;
        border: 1px solid #dbe3ef;
        background: #ffffff;
        color: #0e3780;
        font-size: 13px;
        font-weight: 700;
        line-height: 1;
        text-decoration: none;
        box-sizing: border-box;
        transition: 0.2s;
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
        font-size: 16px;
        font-weight: 800;
    }

    /* --- GRID DO ACESSO RÁPIDO --- */
    .uox-dashboard-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-top: 15px;
    }

    .uox-dashboard-grid .uox-btn {
        padding: 12px 10px;
    }

    .uox-dashboard-grid .uox-btn-full {
        grid-column: 1 / -1;
		justify-content: center;
    }

    .uox-dashboard-grid .dashicons {
        font-size: 18px;
        width: 18px;
        height: 18px;
    }
	
	/* --- ALTURA FIXA DOS RANKINGS --- */
	.uox-ranking-list {
		min-height: 200px;
	}

	.uox-ranking-list li {
		min-height: 43px;
		box-sizing: border-box;
	}

	/* Reserva espaço visual para a paginação quando ela existir */
	.uox-pagination {
		min-height: 43px;
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
function uonix_modular_dashboard_setup() {
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
function uox_render_leads() {
    global $wpdb;
    $tabela_ff = $wpdb->prefix . 'fluentform_submissions';
    $form4_leads = 0; $form3_contato = 0;
    
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
function uox_render_ranking_origem_paginado($form_id, $chave_do_campo, $page_var) {
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
        echo '<li><span>Nenhum dado encontrado.</span></li>';
    } else {
        foreach ($origens_pagina as $nome => $quantidade) {
            echo '<li><span>' . esc_html($nome) . '</span> <strong>' . esc_html($quantidade) . '</strong></li>';
        }
    }

    echo '</ul>';

    if ($total_paginas > 1) {
        echo '<div class="uox-pagination">';

        echo paginate_links(array(
            'base'      => esc_url_raw(add_query_arg($page_var, '%#%')),
            'format'    => '',
            'current'   => $pagina_atual,
            'total'     => $total_paginas,
            'prev_text' => '‹',
            'next_text' => '›',
            'type'      => 'list',
        ));

        echo '</div>';
    }
}

// Bloco 3: Ranking de Origem — Leads, Captura ID 4
function uox_render_origem_leads() {
    uox_render_ranking_origem_paginado(
        4,
        'capturalead_origem',
        'uox_leads_origem_pg'
    );
}

// Bloco 3: Ranking de Origem — Newsletters ID 2
function uox_render_newsletters_origem() {
    uox_render_ranking_origem_paginado(
        2,
        'newsletters_origem',
        'uox_news_origem_pg'
    );
}

// Bloco 4: Orçamentos
function uox_render_orcamentos() {
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
function uox_render_blog() {
    $comentarios_pendentes = wp_count_comments()->moderated;
    $comentarios_aprovados = wp_count_comments()->approved;
    ?>
    <ul class="uox-list">
        <li>
            <span>Coment. Pendentes</span> 
            <strong style="color: <?php echo $comentarios_pendentes > 0 ? '#dc2626' : '#0f172a'; ?>"><?php echo $comentarios_pendentes; ?></strong>
        </li>
        <li><span>Coment. Aprovados</span> <strong><?php echo $comentarios_aprovados; ?></strong></li>
    </ul>
    <div class="uox-btn-group">
        <a href="edit-comments.php" class="uox-btn uox-btn-primary">Moderar</a>
        <a href="edit.php" class="uox-btn">Ver Posts</a>
    </div>
    <?php
}

// Bloco 6: Tráfego (GA4)
function uox_render_trafego() {
    ?>
    <p style="font-size: 13px; color: #64748b; margin-bottom: 15px; line-height: 1.5;">
        O rastreamento do Google Analytics 4 (GA4) está ativo, captando métricas de visitas com segurança e respeitando a escolha de cookies dos usuários.
    </p>
    <div style="margin-bottom: 15px;">
        <span class="uox-badge">Status LGPD: Blindado</span>
        <span class="uox-badge uox-badge-blue" style="margin-left: 8px;">GA4 Ativo</span>
    </div>
    <div class="uox-btn-group">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=googlesitekit-dashboard' ) ); ?>" class="uox-btn">
            Ver Relatórios de Acesso
        </a>
    </div>
    <?php
}

// Bloco 7: Acesso Rápido - Edição Uônix
function uox_render_quick_links() {
    ?>
    <p style="font-size: 13px; color: #64748b; margin-top: 0; margin-bottom: 15px; line-height: 1.5;">
        Clique nos botões abaixo para editar as seções principais do site de forma direta, sem precisar navegar pelos menus.
    </p>
    <div class="uox-dashboard-grid">
		<a href="URL-DO-SEU-LINK" target="_blank" rel="noopener noreferrer" class="uox-btn">
			<span class="dashicons dashicons-format-image"></span> Banner Home
		</a>
		<a href="/wp-admin/site-editor.php?p=%2Fwp_block%2F10973&canvas=edit" target="_blank" rel="noopener noreferrer" class="uox-btn">
            <span class="dashicons dashicons-buddicons-community"></span> Selo Aniverário
        </a>
        <a href="/wp-admin/site-editor.php?p=%2Fwp_block%2F7255&canvas=edit" target="_blank" rel="noopener noreferrer" class="uox-btn">
            <span class="dashicons dashicons-cart"></span> Banner Produtos
        </a>
        <a href="/wp-admin/site-editor.php?p=%2Fwp_block%2F3631&canvas=edit" target="_blank" rel="noopener noreferrer" class="uox-btn">
            <span class="dashicons dashicons-phone"></span> Topo (Contatos)
        </a>
        <a href="/wp-admin/site-editor.php?p=%2Fwp_block%2F2859&canvas=edit" target="_blank" rel="noopener noreferrer" class="uox-btn">
            <span class="dashicons dashicons-editor-help"></span> Dúvidas (FAQ)
        </a>
		<a href="/wp-admin/upload.php?page=uonix-curriculos-recebidos" class="uox-btn">
            <span class="dashicons dashicons-media-text"></span> Currículos Recebidos
        </a>
        <a href="/wp-admin/admin.php?page=uox-dados-globais" class="uox-btn uox-btn-primary uox-btn-full">
            <span class="dashicons dashicons-building"></span> Alterar Telefones e Endereço
        </a>
    </div>
    <?php
}

// NOVO: Bloco 8 - Mini CRM de Orçamentos Recentes (Foco Operacional)
function uox_render_crm_orcamentos() {
    if ( ! function_exists( 'wc_get_orders' ) ) {
        echo '<p style="color:#64748b; font-size:13px;">WooCommerce offline.</p>';
        return;
    }
    
    // Busca os 4 pedidos mais recentes, INDEPENDENTE do status ('any')
    $orders = wc_get_orders( array( 
        'limit' => 4, 
        'status' => 'any' 
    ) );
    
    if ( empty( $orders ) ) {
        echo '<ul class="uox-list"><li><span>Nenhum orçamento registrado no momento.</span></li></ul>';
        return;
    }
    
    echo '<ul class="uox-list">';
    foreach ( $orders as $order ) {
        $responsavel = $order->get_meta('billing_complete_name') ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $empresa = $order->get_meta('billing_company_name') ?: $order->get_billing_company();
        
        $nome_exibir = $empresa ? $empresa : $responsavel;
        $link_edit = esc_url( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) );
        
        // Exibe apenas o Link e o Nome Completo (limite de 3 palavras removido)
        echo '<li>
                <span><a href="'.$link_edit.'" style="text-decoration:none; font-weight:600; color:#0e3780;">#' . $order->get_order_number() . '</a> - ' . esc_html($nome_exibir) . '</span> 
              </li>';
    }
    echo '</ul>';
    echo '<div class="uox-btn-group"><a href="edit.php?post_type=shop_order" class="uox-btn uox-btn-primary">Ver Todos os Pedidos</a></div>';
}

// NOVO: Bloco 9 - Botão de Limpeza do Cache Dinâmico
function uox_render_manutencao_cache() {
    // Escuta a ação do clique do botão para limpar o cache de forma limpa
    if (isset($_GET['uox_flush_action']) && $_GET['uox_flush_action'] == 'run') {
        wp_cache_flush();
        if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
        if (class_exists('LiteSpeed_Cache_API')) { do_action('litespeed_purge_all'); }
        
        echo '<div class="notice notice-success is-dismissible" style="margin: 0 0 15px 0; border-radius:6px;"><p>A memória cache do site foi totalmente limpa e atualizada!</p></div>';
    }
    ?>
    <p style="font-size: 13px; color: #64748b; margin-top: 0; margin-bottom: 15px; line-height: 1.5;">
        Caso faça alterações em textos, imagens ou banners e não consiga visualizar de imediato, force a limpeza global da memória cache clicando abaixo.
    </p>
    <div class="uox-btn-group">
        <a href="<?php echo esc_url(add_query_arg('uox_flush_action', 'run')); ?>" class="uox-btn uox-btn-primary">Limpar Memória do Site</a>
    </div>
    <?php
}

// NOVO: Bloco 10 - Suporte Técnico (Sua Assinatura)
function uox_render_suporte_vip() {
    ?>
    <p style="font-size: 13px; color: #64748b; margin-top: 0; margin-bottom: 15px; line-height: 1.5;">
        Painel corporativo desenvolvido sob medida.<br>
        <strong>Status do Servidor:</strong> Online <span style="color:#16a34a">●</span><br>
        <strong>Ambiente de Homologação:</strong> Monitorado 🔒
    </p>
    <div class="uox-btn-group">
        <a href="https://wa.me/5511999999999?text=Oi%20Cassio,%20preciso%20de%20ajuda%20no%20painel%20da%20Uonix." target="_blank" class="uox-btn" style="background:#25d366; color:#ffffff; border-color:#25d366;">
            Chamar Suporte Técnico
        </a>
    </div>
    <?php
}


