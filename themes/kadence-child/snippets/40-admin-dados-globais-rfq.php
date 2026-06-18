<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Admin - dados globais, shortcode [uonix] e RFQ.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 15295-15507 do export original.
// -----------------------------------------------------------------------------
/**
 * SHORTCODE / Variaveis globais
 */
// =========================================================================
// 1. CRIA O MENU "DADOS DA UÔNIX" NO PAINEL (Acessível para o Editor)
// =========================================================================
add_action('admin_menu', 'uox_menu_dados_globais');
function uox_menu_dados_globais() {
    add_menu_page(
        'Dados da Uônix',       // Título da página
        'Dados da Uônix',       // Nome no menu
        'edit_pages',           // Capacidade
        'uox-dados-globais',    // Slug
        'uox_render_dados_page',// Função que renderiza
        'dashicons-building',      // Ícone
        30                      // Posição no menu
    );
}

// =========================================================================
// 2. RENDERIZA A TELA ORGANIZADA EM ABAS COM DESIGN CLEAN
// =========================================================================
function uox_render_dados_page() {
    if (isset($_POST['uox_salvar_dados'])) {
        foreach ($_POST['uox_dados'] as $chave => $valor) {
            update_option('uox_' . sanitize_text_field($chave), sanitize_text_field($valor));
        }
        echo '<div class="notice notice-success is-dismissible"><p>Dados atualizados com sucesso!</p></div>';
    }

    // Estruturação dos dados mapeados por ID de aba
    $campos = [
        'contatos' => [
            'label'  => '📞 Contatos',
            'inputs' => [
                'telefone_1' => ['label' => 'Telefone 1', 'default' => '11 4372 9366'],
                'telefone_2' => ['label' => 'Telefone 2', 'default' => ''],
                'whatsapp_1' => ['label' => 'WhatsApp 1', 'default' => '11 94725 4885'],
                'whatsapp_2' => ['label' => 'WhatsApp 2', 'default' => '11 91684 0784'],
                'whatsapp_3' => ['label' => 'WhatsApp 3', 'default' => ''],
            ]
        ],
        'emails' => [
            'label'  => '📧 E-mails Gerais',
            'inputs' => [
                'email_contato'       => ['label' => 'E-mail (Contato)', 'default' => 'contato@uonix.com.br'],
                'email_atendimento'   => ['label' => 'E-mail (Atendimento)', 'default' => 'atendimento@uonix.com.br'],
                'email_administrativo'=> ['label' => 'E-mail (Administrativo)', 'default' => 'administrativo@uonix.com.br'],
                'email_marketing'     => ['label' => 'E-mail (Marketing)', 'default' => 'marketing@uonix.com.br'],
            ]
        ],
        'endereco' => [
            'label'  => '📍 Endereço',
            'inputs' => [
                'end_rua'         => ['label' => 'Rua', 'default' => 'Rua Melo Franco 115'],
                'end_complemento' => ['label' => 'Complemento', 'default' => 'Sala 01'],
                'end_cidade'      => ['label' => 'Cidade', 'default' => 'Guarulhos'],
                'end_estado'      => ['label' => 'Estado', 'default' => 'SP'],
                'end_cep'         => ['label' => 'CEP', 'default' => '07033-220'],
            ]
        ],
        'redes-sociais' => [
            'label'  => '🌐 Redes Sociais',
            'inputs' => [
                'social_facebook' => ['label' => 'Facebook', 'default' => 'https://web.facebook.com/Uonix.Montagens/'],
                'social_instagram'=> ['label' => 'Instagram', 'default' => 'https://www.instagram.com/uonix.montagens/'],
                'social_linkedin' => ['label' => 'LinkedIn', 'default' => 'https://www.linkedin.com/company/uonix-montagens-e-consultoria-tecnica/'],
                'social_youtube'  => ['label' => 'YouTube', 'default' => 'http://www.youtube.com/@uonixmontagens'],
                'social_x'        => ['label' => 'X (Twitter)', 'default' => ''],
            ]
        ],
        'roteamento' => [
            'label'  => '🔀 Roteamento de Formulários',
            'inputs' => [
                'rota_informacoes' => ['label' => 'Informações', 'default' => 'atendimento@uonix.com.br'],
                'rota_orcamento'   => ['label' => 'Solicitar orçamento', 'default' => 'fernando@uonix.com.br'],
                'rota_produtos'    => ['label' => 'Conhecer produtos/soluções', 'default' => 'fernando@uonix.com.br'],
                'rota_apoio'       => ['label' => 'Apoio técnico para projeto', 'default' => 'fernando@uonix.com.br'],
                'rota_garantia'    => ['label' => 'Pós-venda e garantia', 'default' => 'atendimento@uonix.com.br'],
                'rota_laudos'      => ['label' => 'Ensaios e certificações', 'default' => 'fernando@uonix.com.br'],
                'rota_parcerias'   => ['label' => 'Representação comercial', 'default' => 'administrativo@uonix.com.br'],
                'rota_fornecedores'=> ['label' => 'Cadastro de fornecedores', 'default' => 'administrativo@uonix.com.br'],
                'rota_rh'          => ['label' => 'Trabalhe conosco (RH)', 'default' => 'administrativo@uonix.com.br'],
                'rota_feedback'    => ['label' => 'Sugestões/Reclamações', 'default' => 'atendimento@uonix.com.br, marketing@uonix.com.br'],
                'rota_lgpd'        => ['label' => 'LGPD', 'default' => 'administrativo@uonix.com.br'],
                'rota_outros'      => ['label' => 'Outros assuntos', 'default' => 'contato@uonix.com.br'],
            ]
        ]
    ];
    ?>
    <div class="wrap" style="background: #ffffff; padding: 25px 35px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); margin-top: 20px; max-width: 1050px; border: 1px solid #e2e8f0;">
        
        <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 25px;">
            <div>
                <h1 style="margin: 0 0 5px 0; font-weight: 800; color: #0e3780; font-size: 23px;">Painel de Controle Uônix</h1>
                <p style="margin: 0; color: #64748b; font-size: 13px;">Atualize dados institucionais e configure o roteamento de e-mails em tempo real.</p>
            </div>
            <div>
                <img src="/wp-content/uploads/2026/01/logo-uonix.png" alt="Uônix" style="max-height: 48px; display: block;">
            </div>
        </div>
        
        <h2 class="nav-tab-wrapper" style="border-bottom: 1px solid #cbd5e1; margin-bottom: 10px; padding-bottom: 0;">
            <?php $is_first = true; foreach ($campos as $id => $secao): ?>
                <a href="#uox-tab-<?php echo $id; ?>" class="nav-tab <?php echo $is_first ? 'nav-tab-active' : ''; ?>" data-tab="<?php echo $id; ?>" style="font-weight: 600; font-size: 13px; padding: 8px 16px; margin-right: 4px;">
                    <?php echo $secao['label']; ?>
                </a>
            <?php $is_first = false; endforeach; ?>
        </h2>

        <form method="POST" action="">
            
            <?php $is_first = true; foreach ($campos as $id => $secao): ?>
                <div id="uox-tab-<?php echo $id; ?>" class="uox-tab-content" style="<?php echo $is_first ? '' : 'display:none;'; ?> padding: 10px 5px;">
                    
                    <?php if ($id === 'roteamento'): ?>
                        <div style="background-color: #f0fdf4; border-left: 4px solid #16a34a; padding: 15px; margin: 10px 0 25px 0; border-radius: 4px;">
                            <strong style="color: #15803d; display: block; margin-bottom: 3px; font-size: 14px;">✉️ Diretório de Encaminhamento</strong>
                            <span style="color: #166534; font-size: 13px; font-weight: 500;">Para enviar para vários e-mails, separe por vírgula. (Exemplo: comercial@uonix.com.br, engenharia@uonix.com.br)</span>
                        </div>
                    <?php endif; ?>

                    <table class="form-table" style="margin-top: 0;">
                        <?php foreach ($secao['inputs'] as $chave => $config): 
                            $valor_salvo = get_option('uox_' . $chave, $config['default']);
                        ?>
                        <tr style="border-bottom: 1px solid #f8fafc;">
                            <th scope="row" style="padding: 16px 10px 16px 0; width: 240px; vertical-align: middle;">
                                <label style="font-weight: 600; color: #334155; font-size: 14px;"><?php echo $config['label']; ?></label>
                            </th>
                            <td style="padding: 16px 10px; vertical-align: middle;">
                                <input type="text" name="uox_dados[<?php echo $chave; ?>]" value="<?php echo esc_attr($valor_salvo); ?>" class="regular-text" style="width: 100%; max-width: 480px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 7px 12px; font-size: 14px; color: #1e293b;">
                                
                                <?php if ($id !== 'roteamento'): ?>
                                    <p class="description" style="margin-top: 5px; color: #64748b; font-size: 12px;">Tag curta: <code>[uonix <?php echo $chave; ?>]</code></p>
                                    <?php if (strpos($chave, 'telefone') !== false || strpos($chave, 'whatsapp') !== false): ?>
                                        <p class="description" style="color: #0284c7; font-size: 11px; margin-top: 1px; font-weight: 500;">Uso em links de clique: <code>[uonix <?php echo $chave; ?> link]</code></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php $is_first = false; endforeach; ?>

            <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
                <button type="submit" name="uox_salvar_dados" class="button button-primary" style="height: 42px; padding: 0 30px; font-size: 14px; font-weight: 700; border-radius: 6px; background-color: #0e3780; border-color: #0e3780; box-shadow: 0 4px 10px rgba(14, 55, 128, 0.15);">Salvar Todas as Configurações</button>
            </div>
        </form>
    </div>

    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const abas = document.querySelectorAll('.nav-tab-wrapper .nav-tab');
            const conteudos = document.querySelectorAll('.uox-tab-content');

            abas.forEach(aba => {
                aba.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Remove destaque visual das abas
                    abas.forEach(a => a.classList.remove('nav-tab-active'));
                    // Esconde seções
                    conteudos.forEach(c => c.style.display = 'none');

                    // Ativa aba atual
                    this.classList.add('nav-tab-active');
                    // Renderiza conteúdo selecionado
                    const targetId = 'uox-tab-' + this.getAttribute('data-tab');
                    document.getElementById(targetId).style.display = 'block';
                });
            });
        });
    </script>
    <?php
}

// =========================================================================
// 3. SHORTCODE CENTRALIZADO [uonix campo]
// =========================================================================
add_shortcode('uonix', 'uox_render_shortcode_simples');
function uox_render_shortcode_simples($atts) {
    if (empty($atts) || !isset($atts[0])) { return ''; }
    $campo = sanitize_text_field($atts[0]);
    $valor = get_option('uox_' . $campo, '');
    if (isset($atts[1]) && strtolower(trim($atts[1])) === 'link') {
        $valor = preg_replace('/[^0-9]/', '', $valor);
    }
    return $valor;
}
add_filter('widget_text', 'do_shortcode');


/**
 * UÔNIX: Interceptação do Destinatário do RFQ-Toolkit
 * Sobrescreve o destinatário fixo do plugin de orçamento pelo e-mail dinâmico 
 */
add_filter( 'woocommerce_email_recipient_new_rfq', 'uonix_rfq_destinatario_dinamico', 10, 2 );

function uonix_rfq_destinatario_dinamico( $recipient, $object ) {
    
    // Busca o e-mail cadastrado no painel pela Juh
    $email_orcamento = get_option('uox_rota_orcamento');
    
    // Se houver valor salvo no painel, sobrescreve o destinatário
    if ( ! empty( $email_orcamento ) ) {
        return sanitize_text_field( $email_orcamento );
    }
    
    return $recipient;
}


