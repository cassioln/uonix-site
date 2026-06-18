<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Admin - UX e white-label do Fluent Forms.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 15508-16203 do export original.
// -----------------------------------------------------------------------------
/**
 * UX/UI: FORMULARIOS ENTRADA ( PERFIL EDITOR )
 */
// =========================================================================
// UX/UI PREMIUM: TELA DE LEADS (FLUENT FORMS) MAIS INTELIGENTE PARA EDITOR
// =========================================================================
add_action('admin_head', 'uonix_fluentforms_ux_premium');

function uonix_fluentforms_ux_premium() {
    $user = wp_get_current_user();

    // Aplica o visual premium apenas para o perfil Editor
    if (!in_array('editor', (array) $user->roles)) {
        return;
    }

    $page  = isset($_GET['page']) ? $_GET['page'] : '';
    $route = isset($_GET['route']) ? $_GET['route'] : '';

    // -------------------------------------------------------------------------
    // 1. REGRAS PARA A TELA GERAL: "TODAS AS ENTRADAS"
    // -------------------------------------------------------------------------
    if ($page === 'fluent_forms_all_entries') {
        echo '<style>
            /* Esconde lixo do cabeçalho e upsells (versão Pro) */
            .ff_menu li:not(.active),
            .ff_menu_link_buy,
            .global-search-menu-button {
                display: none !important;
            }

            .ff_btn_group > .ff_btn_group_item:first-child,
            .ff_update_card,
            .ff_import_entries {
                display: none !important;
            }

            /* Super Destaque no Seletor de Formulários */
            .ff_entries_select .el-input__inner {
                border: 2px solid #0e3780 !important;
                background-color: #f8fafc !important;
                font-weight: 700 !important;
                color: #0f172a !important;
                border-radius: 8px !important;
                box-shadow: 0 4px 10px rgba(14, 55, 128, 0.08) !important;
            }

            .lead-title {
                color: #0e3780 !important;
                font-weight: 800 !important;
                text-transform: uppercase;
            }

            /* Melhoria na Tabela e Filtros */
            .ff_entries_search_wrap .el-input__inner {
                border-radius: 20px !important;
                padding-left: 35px !important;
                border-color: #cbd5e1 !important;
            }

            .ff_radio_group_s2 .el-radio-button.is-active .el-radio-button__inner {
                background-color: #0e3780 !important;
                border-color: #0e3780 !important;
                color: #fff !important;
            }

            .ff_table .el-table {
                border-radius: 12px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.04) !important;
                border: none !important;
            }

            .ff_table th {
                background-color: #f8fafc !important;
                color: #1e293b !important;
                font-weight: 800 !important;
                text-transform: uppercase;
                font-size: 12px !important;
                letter-spacing: 0.5px;
                border-bottom: 1px solid #eaecf0 !important;
            }
        </style>';
    }

    // -------------------------------------------------------------------------
    // 2. REGRAS PARA A TELA DE FORMULÁRIOS INDIVIDUAIS (Submenus)
    // -------------------------------------------------------------------------
    if ($page === 'fluent_forms' && $route === 'entries') {
        echo '<style>
            /* 1. Obliterar o menu superior interno (Shortcode, Preview, Design) */
            .form_internal_menu,
            .ff_entries_report_wrap .ff_btn_group_item:first-child,
            .more_menu.el-dropdown {
                display: none !important;
            }

            /* Ajuste de margem para o formulário subir e preencher o vazio deixado pelo menu */
            .ff_form_application_container {
                margin-top: 15px !important;
            }

            /* 2. MÁGICA: TROCAR O TÍTULO "ENTRADAS" PELO NOME DO FORMULÁRIO */

            /* Esconde a palavra nativa "Entradas" */
            h1.ff_section_title {
                font-size: 0 !important;
                visibility: hidden;
            }

            /* Cria um novo texto bonitão por cima */
            h1.ff_section_title::after {
                visibility: visible;
                font-size: 24px !important;
                font-weight: 800 !important;
                color: #0e3780 !important;
                display: block;
                text-transform: uppercase;
                letter-spacing: -0.5px;
            }

            /* Injeta o nome correto baseado no ID do formulário atual (form_id) */
            .ff_entries_wrap[form_id="4"] h1.ff_section_title::after {
                content: "Captura de Leads";
            }

            .ff_entries_wrap[form_id="3"] h1.ff_section_title::after {
                content: "Formulário de Contato";
            }

            .ff_entries_wrap[form_id="2"] h1.ff_section_title::after {
                content: "Assinantes Newsletters";
            }

            /* 3. Melhorias visuais extras para essa tela */
            .ff_entries_report_search .el-input__inner {
                border-radius: 20px !important;
                padding-left: 35px !important;
            }

            .ff_table .el-table {
                border-radius: 12px;
                box-shadow: 0 4px 15px rgba(0,0,0,0.04) !important;
                border: none !important;
            }

            .ff_table th {
                background-color: #f8fafc !important;
                color: #1e293b !important;
                font-weight: 800 !important;
                text-transform: uppercase;
                font-size: 12px !important;
                letter-spacing: 0.5px;
                border-bottom: 1px solid #eaecf0 !important;
            }

            /* Deixa a barra de pesquisa nativa maior e mais em destaque */
            .ff_entries_report_search {
                width: 300px !important;
                transition: all 0.3s ease;
            }

            .ff_entries_report_search .el-input__inner {
                border: 2px solid #0e3780 !important;
                border-radius: 20px !important;
            }

            .el-button--primary {
                background-color: #0e3780 !important;
                border-color: #0e3780 !important;
                border-radius: 6px !important;
                font-weight: 600 !important;
            }
            .el-button--primary:hover {
                background-color: #1a2b3c !important;
                border-color: #1a2b3c !important;
            }

            /* -------------------------------------------------------------------------
               6. LIMPEZA DO POP-UP DE EXPORTAÇÃO (Fluent Forms Freemium)
            ------------------------------------------------------------------------- */

            /* Esconde o painel gigante com os checkboxes bloqueados */
            .el-dialog__body .ff_card_wrap > .el-checkbox-group {
                display: none !important;
            }

            /* Esconde o aviso "Field selection is available only in Pro version" */
            .el-dialog__body .text-center {
                display: none !important;
            }

            /* Reduz o tamanho da caixa, já que ela agora está vazia */
            .el-dialog {
                width: 400px !important;
                border-radius: 12px !important;
            }

            /* Traduz o texto do botão "With Notes" para "Incluir Notas" via CSS */
            .el-dialog__body .ff_card_wrap > .el-checkbox > .el-checkbox__label {
                font-size: 0 !important;
            }

            .el-dialog__body .ff_card_wrap > .el-checkbox > .el-checkbox__label::after {
                content: "Incluir Notas nas exportações" !important;
                font-size: 14px !important;
                visibility: visible;
                color: #334155 !important;
            }

            /* Troca o título de "Selecione os campos..." para um título direto "Exportar Entradas" */
            .el-dialog__header_group h4 {
                font-size: 0 !important;
                visibility: hidden;
            }

            .el-dialog__header_group h4::after {
                content: "Exportar Entradas" !important;
                font-size: 18px !important;
                color: #0e3780 !important;
                font-weight: 800 !important;
                visibility: visible;
                display: block;
            }

            /* -------------------------------------------------------------------------
               7. UX/UI: TELA DE ENTRADA INDIVIDUAL DO LEAD (Estilo CRM Moderno)
            ------------------------------------------------------------------------- */

            /* A) ESCONDER RECURSOS INÚTEIS / PRO */
            .fluentform-wrapper[entry_id] .ff_entry_detail_wrap > .ff_btn_group {
                display: none !important;
            }

            .fluentform-wrapper[entry_id] .json_action {
                display: none !important;
            }

            /* B) MODERNIZAR OS CARDS DE INFORMAÇÃO */
            .fluentform-wrapper[entry_id] .ff_card {
                border-radius: 12px !important;
                box-shadow: 0 4px 15px rgba(0,0,0,0.04) !important;
                border: none !important;
                margin-bottom: 20px !important;
                overflow: hidden;
                background: #ffffff !important;
            }

            .fluentform-wrapper[entry_id] .ff_card_head {
                background-color: #f8fafc !important;
                border-bottom: 1px solid #f1f5f9 !important;
                padding: 16px 20px !important;
            }

            .fluentform-wrapper[entry_id] .entry_info_box_title {
                font-weight: 800 !important;
                color: #0e3780 !important;
                font-size: 14px !important;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            /* C) MODERNIZAR A EXIBIÇÃO DAS RESPOSTAS DO FORMULÁRIO */
            .fluentform-wrapper[entry_id] .wpf_each_entry {
                margin-bottom: 18px !important;
                border-bottom: none !important;
                padding-bottom: 0 !important;
            }

            /* Títulos dos campos (ex: NOME, EMPRESA) */
            .fluentform-wrapper[entry_id] .wpf_entry_label {
                font-size: 11px !important;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                color: #64748b !important;
                font-weight: 800 !important;
                margin-bottom: 6px !important;
            }

            /* Respostas do usuário (Caixas cinzas modernas) */
            .fluentform-wrapper[entry_id] .wpf_entry_value {
                font-size: 15px !important;
                color: #0f172a !important;
                background: #f8fafc !important;
                padding: 12px 15px !important;
                border-radius: 8px !important;
                border: 1px solid #e2e8f0 !important;
            }

            .fluentform-wrapper[entry_id] .wpf_entry_value a {
                color: #0e3780 !important;
                font-weight: 600;
            }

            /* D) MELHORAR A BARRA LATERAL (Submission Info) */
            .fluentform-wrapper[entry_id] .ff_submission_info_list li {
                padding: 12px 0 !important;
                border-bottom: 1px dashed #e2e8f0 !important;
                display: flex;
                flex-direction: column;
            }

            .fluentform-wrapper[entry_id] .ff_submission_info_list .lead-title {
                font-size: 11px !important;
                color: #64748b !important;
                font-weight: 800 !important;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 4px;
            }

            .fluentform-wrapper[entry_id] .ff_submission_info_list .lead-text {
                font-size: 14px !important;
                font-weight: 600 !important;
                color: #0f172a !important;
            }

            /* -------------------------------------------------------------------------
               8. REFINAMENTOS FINAIS: TELA INDIVIDUAL DO LEAD
            ------------------------------------------------------------------------- */

            .fluentform-wrapper[entry_id] .ff_entry_detail_wrap > .ff_btn_group {
                display: none !important;
            }

            /* B) Injetar o texto "Imprimir" ao lado do ícone */
            .fluentform-wrapper[entry_id] .entry_info_box_header .el-button--default {
                padding: 8px 15px !important;
                border-radius: 6px !important;
            }

            .fluentform-wrapper[entry_id] .entry_info_box_header .el-icon-printer::after {
                content: " Imprimir";
                font-family: inherit;
                font-size: 13px;
                font-weight: 700;
                margin-left: 6px;
            }

            /* C) Ajustar Cores dos Botões (Editar / Alterar Estado) para melhor legibilidade */
            .fluentform-wrapper[entry_id] .entry-footer .el-button--primary {
                background-color: #e0f2fe !important;
                border-color: #e0f2fe !important;
                color: #0284c7 !important;
                font-weight: 700 !important;
                transition: all 0.3s ease !important;
            }

            .fluentform-wrapper[entry_id] .entry-footer .el-button--primary:hover {
                background-color: #0e3780 !important;
                border-color: #0e3780 !important;
                color: #ffffff !important;
            }

            /* D) Injetar o Nome do Formulário no pão de migalhas do topo */
            .fluentform-wrapper[entry_id] h3 a.active {
                font-size: 0 !important;
            }

            .fluentform-wrapper[entry_id] h3 a.active::before {
                font-size: 18px !important;
                color: #0e3780 !important;
                font-weight: 800 !important;
                text-transform: uppercase;
            }

            .fluentform-wrapper[form_id="4"][entry_id] h3 a.active::before {
                content: "Captura de Leads ";
            }

            .fluentform-wrapper[form_id="3"][entry_id] h3 a.active::before {
                content: "Formulário de Contato ";
            }

            .fluentform-wrapper[form_id="2"][entry_id] h3 a.active::before {
                content: "Assinantes Newsletters ";
            }

            /* -------------------------------------------------------------------------
               9. LIMPEZA DE NOTAS E TRANSFORMAÇÃO DO LOG EM AVISO DE ROTEAMENTO
            ------------------------------------------------------------------------- */

            .entry_info_box.entry_submission_logs {
                display: none !important;
            }

            .entry-footer .ff_btn_group > .ff_btn_group_item:first-child {
                display: none !important;
            }

            /* A) Ajuste de Cor e Hover do Botão "Adicionar Nota" */
            .entry_submission_activity .entry_info_box_actions .el-button--primary {
                background-color: #e0f2fe !important;
                border-color: #e0f2fe !important;
                color: #0284c7 !important;
                font-weight: 700 !important;
                transition: all 0.3s ease !important;
            }

            .entry_submission_activity .entry_info_box_actions .el-button--primary:hover {
                background-color: #0e3780 !important;
                border-color: #0e3780 !important;
                color: #ffffff !important;
            }

            /* B) Ocultar botões nativos do Log (Geral / API) no Form 3 */
            .fluentform-wrapper[form_id="3"] .entry_submission_logs .entry_info_box_actions {
                display: none !important;
            }

            /* C) Transformar a caixa de Logs em um Painel de Roteamento Limpo */
            .entry_submission_logs .entry_info_box_actions {
                display: none !important;
            }

            .entry_submission_logs .entry_info_box_title {
                font-size: 0 !important;
                visibility: hidden;
            }

            .entry_submission_logs .entry_info_box_title::after {
                content: "Destino do Roteamento";
                font-size: 14px !important;
                color: #0e3780 !important;
                font-weight: 800 !important;
                visibility: visible;
                display: block;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .entry_submission_logs .wpf_entry_label {
                display: none !important;
            }

            .entry_submission_logs .entry_submission_log_des {
                background-color: #f0fdf4 !important;
                color: #166534 !important;
                border: 1px solid #bbf7d0 !important;
                padding: 15px 18px !important;
                border-radius: 8px !important;
                font-size: 14px !important;
                font-weight: 600 !important;
                line-height: 1.6 !important;
                box-shadow: 0 2px 4px rgba(22, 101, 52, 0.05) !important;
            }

            .entry_submission_logs .entry_submission_log_des br {
                display: block;
                content: "";
                margin-top: 5px;
            }
            
            /* -------------------------------------------------------------------------
               10. UX/UI: TELA DE RELATÓRIO VISUAL (Gráficos e Estatísticas)
            ------------------------------------------------------------------------- */

            /* A) Título Principal da tela de relatórios */
            .ff_report_viewer h1 {
                font-size: 22px !important;
                font-weight: 800 !important;
                color: #0e3780 !important;
                text-transform: uppercase;
                letter-spacing: -0.5px;
                margin-bottom: 10px !important;
            }

            /* B) Modernizar os Cards de Relatório (Gráficos e Tabelas) */
            .ff_report_viewer .ff_card, 
            .ff_report_viewer .ff_report_card {
                border-radius: 12px !important;
                box-shadow: 0 4px 15px rgba(0,0,0,0.04) !important;
                border: none !important;
                margin-bottom: 24px !important;
                overflow: hidden;
                background: #ffffff !important;
            }

            /* Cabeçalhos dos Cards */
            .ff_report_viewer .report_header, 
            .ff_report_viewer .entry_info_header {
                background-color: #f8fafc !important;
                border-bottom: 1px solid #f1f5f9 !important;
                padding: 16px 20px !important;
                display: flex;
                align-items: center;
            }
            .ff_report_viewer .report_header .title, 
            .ff_report_viewer .entry_info_header h6 {
                font-weight: 800 !important;
                color: #0e3780 !important;
                font-size: 14px !important;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin: 0 !important;
            }

            /* C) Tabelas de Dados abaixo dos gráficos */
            .ff_report_viewer .ff-table {
                border-collapse: collapse !important;
                width: 100% !important;
            }
            .ff_report_viewer .ff-table th {
                background-color: #f8fafc !important;
                color: #475569 !important;
                font-weight: 800 !important;
                text-transform: uppercase !important;
                font-size: 11px !important;
                letter-spacing: 0.5px;
                padding: 12px 15px !important;
                border-bottom: 1px solid #e2e8f0 !important;
            }
            .ff_report_viewer .ff-table td {
                padding: 12px 15px !important;
                color: #334155 !important;
                font-size: 13px !important;
                border-bottom: 1px dashed #f1f5f9 !important;
                font-weight: 500 !important;
            }

            /* D) Ajustes na Barra Lateral (Filtros e Informações) */
            .ff_report_viewer .ff_print_hide .el-checkbox__label {
                font-weight: 600 !important;
                color: #475569 !important;
            }
            .ff_report_viewer .ff_list_border_bottom li {
                padding: 12px 0 !important;
                border-bottom: 1px dashed #f1f5f9 !important;
            }
            .ff_report_viewer .ff_list_border_bottom .lead-title {
                color: #64748b !important;
                font-weight: 800 !important;
                font-size: 11px !important;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 6px;
            }

            /* E) Botões Modernos e Tradução de "Print report" */
            .ff_report_viewer .el-button--primary {
                background-color: #e0f2fe !important; 
                border-color: #e0f2fe !important;
                color: #0284c7 !important; 
                font-weight: 700 !important;
                border-radius: 6px !important;
                transition: all 0.3s ease !important;
            }
            .ff_report_viewer .el-button--primary:hover {
                background-color: #0e3780 !important; 
                border-color: #0e3780 !important;
                color: #ffffff !important;
            }

            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button--info span {
                font-size: 0 !important;
            }
            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button--info span::after {
                content: "Imprimir Relatório" !important;
                font-size: 14px !important;
                visibility: visible;
            }
            
            /* F) Estilização dos Botões Finais da Barra Lateral (Imprimir / Redefinir) */
            .ff_report_viewer .ff_print_hide .ff_btn_group {
                display: flex;
                flex-direction: column;
                gap: 12px;
                margin-top: 25px;
            }
            .ff_report_viewer .ff_print_hide .ff_btn_group .ff_btn_group_item {
                margin: 0 !important;
                width: 100%;
            }
            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button {
                width: 100% !important;
                border-radius: 8px !important;
                padding: 10px 15px !important;
                transition: all 0.3s ease !important;
                display: flex;
                justify-content: center;
                align-items: center;
            }

            /* 1. Botão "Print report" -> Copiando o estilo do botão Default */
            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button--info {
                background-color: #ffffff !important;
                border: 1px solid #cbd5e1 !important;
                color: #0e3780 !important;
                font-weight: 700 !important;
            }
            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button--info:hover {
                background-color: #f8fafc !important;
                color: #1a2b3c !important;
                border-color: #94a3b8 !important;
            }

            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button--info span {
                font-size: 0 !important;
            }
            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button--info span::before {
                content: "🖨️";
                font-family: "Font Awesome 5 Free";
                font-weight: 900;
                font-size: 16px;
                margin-right: 6px;
            }
            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button--info span::after {
                content: "Imprimir"; 
                font-size: 13px !important;
                font-weight: 700;
                font-family: inherit;
                vertical-align: middle;
                visibility: visible;
            }

            /* 2. Botão "Redefinir análise" -> Estilo Destrutivo */
            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button--default {
                background-color: #ffffff !important;
                border: 1px solid #fda4af !important;
                color: #e11d48 !important;
                font-weight: 700 !important;
            }
            .ff_report_viewer .ff_print_hide .ff_btn_group .el-button--default:hover {
                background-color: #fff1f2 !important;
                border-color: #f43f5e !important;
            }
        </style>';

    }
}

// =========================================================================
// WHITE-LABEL: SUBSTITUIR CABEÇALHO DO FLUENT FORMS PELO LOGO DO SITE
// =========================================================================
add_action('admin_footer', 'uonix_ff_whitelabel_header');

function uonix_ff_whitelabel_header() {
    $user = wp_get_current_user();

    if (!in_array('editor', (array) $user->roles)) {
        return;
    }

    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

    if (strpos($page, 'fluent_forms') !== false) {
        $custom_logo_id = get_theme_mod('custom_logo');
        $logo_url       = wp_get_attachment_image_url($custom_logo_id, 'full');
        $site_name      = get_bloginfo('name');
        ?>
        <style>
            /* 1. Remove o menu superior nativo */
            ul.ff_menu,
            .global-search-menu-button {
                display: none !important;
            }

            /* 2. Centraliza o cabeçalho */
            .ff_header {
                justify-content: center !important;
                padding: 15px 0 !important;
            }

            .ff_header_group {
                margin: 0 auto;
            }

            /* 3. Esconde a logo original, mas PERMITE a nossa logo personalizada */
            .plugin-name img:not(.uonix-logo) {
                display: none !important;
            }

            /* Estilo da nossa logo injetada */
            .uonix-logo {
                max-height: 45px !important;
                width: auto !important;
                display: block !important;
            }
        </style>

        <script type="text/javascript">
            document.addEventListener("DOMContentLoaded", function() {
                var pluginNameContainer = document.querySelector(".plugin-name");

                if (pluginNameContainer) {
                    <?php if ($logo_url) : ?>
                        pluginNameContainer.innerHTML = '<img src="<?php echo esc_url($logo_url); ?>" class="uonix-logo" alt="<?php echo esc_attr($site_name); ?>">';
                    <?php else : ?>
                        pluginNameContainer.innerHTML = '<span style="font-size: 22px; font-weight: 800; color: #0e3780; text-transform: uppercase; letter-spacing: -0.5px;">📋 <?php echo esc_js($site_name); ?></span>';
                    <?php endif; ?>
                }
            });
        </script>
        <?php
    }
}


