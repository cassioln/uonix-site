<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - Fluent Forms - roteamento dinamico de e-mail.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 1412-1664 do export original.
// -----------------------------------------------------------------------------
/**
 * Roteamento de E-mail para Fluent Forms 
 */
/**
 * UÔNIX: Roteamento Estratégico DINÂMICO
 * Puxa os e-mails de destino diretamente das configurações do painel
 */
add_filter( 'wp_mail', function( $args ) {
    
    // 1. Variáveis de Identificação
    $assunto_post = '';
    $is_rh_custom = false;
    $parsed_data = [];

    // A) Verifica se veio do formulário normal do Fluent Forms (Contato)
    if (isset($_POST['form_assunto'])) {
        $assunto_post = $_POST['form_assunto'];
    } elseif (isset($_POST['data'])) {
        parse_str($_POST['data'], $parsed_data);
        $assunto_post = isset($parsed_data['form_assunto']) ? $parsed_data['form_assunto'] : '';
    }

    // B) Verifica se veio do nosso formulário em HTML (Trabalhe Conosco)
    if (isset($_POST['action']) && $_POST['action'] === 'uonix_processar_trabalhe') {
        $assunto_post = 'rh';
        $is_rh_custom = true;
    }

    if ( !empty($assunto_post) ) {
        
        // 2. Mapeamento para o Assunto Dinâmico (Labels amigáveis)
        $labels = [
            'Informações'  => 'Informações',
            'orcamento'    => 'Solicitar orçamento',
            'produtos'     => 'Conhecer produtos e soluções',
            'apoio'        => 'Apoio técnico para seu projeto',
            'garantia'     => 'Atendimento pós-venda e garantia',
            'laudos'       => 'Ensaios, laudos e certificações',
            'parcerias'    => 'Representação comercial e parcerias',
            'fornecedores' => 'Cadastro de fornecedores',
            'rh'           => 'Trabalhe conosco',
            'feedback'     => 'Sugestões, elogios ou reclamações',
            'lgpd'         => 'LGPD',
            'outros'       => 'Outros assuntos'
        ];
        $label_bonito = isset($labels[$assunto_post]) ? $labels[$assunto_post] : $assunto_post;
        
        // 3. Lógica de Roteamento (Puxando do Banco de Dados / Painel da Juh)
        $rotas_dinamicas = [
            'Informações'  => get_option('uox_rota_informacoes', 'atendimento@uonix.com.br'),
            'orcamento'    => get_option('uox_rota_orcamento', 'fernando@uonix.com.br'),
            'produtos'     => get_option('uox_rota_produtos', 'fernando@uonix.com.br'),
            'apoio'        => get_option('uox_rota_apoio', 'fernando@uonix.com.br'),
            'garantia'     => get_option('uox_rota_garantia', 'atendimento@uonix.com.br'),
            'laudos'       => get_option('uox_rota_laudos', 'fernando@uonix.com.br'),
            'parcerias'    => get_option('uox_rota_parcerias', 'administrativo@uonix.com.br'),
            'fornecedores' => get_option('uox_rota_fornecedores', 'administrativo@uonix.com.br'),
            'rh'           => get_option('uox_rota_rh', 'administrativo@uonix.com.br'),
            'feedback'     => get_option('uox_rota_feedback', 'atendimento@uonix.com.br, marketing@uonix.com.br'),
            'lgpd'         => get_option('uox_rota_lgpd', 'administrativo@uonix.com.br'),
            'outros'       => get_option('uox_rota_outros', 'contato@uonix.com.br')
        ];

        // Aplica o e-mail destino. Se a chave não existir ou a Juh deixar vazio, cai no fallback (Outros)
        $args['to'] = (!empty($rotas_dinamicas[$assunto_post])) ? $rotas_dinamicas[$assunto_post] : $rotas_dinamicas['outros'];
        
        // 4. Personalização do Assunto do E-mail
        if ($is_rh_custom) {
            $nome_remetente = sanitize_text_field($_POST['nome'] ?? 'Candidato');
            $args['subject'] = "Site Uônix [{$label_bonito}]: Novo Currículo - {$nome_remetente}";
        } else {
            $args['subject'] = "Site Uônix [{$label_bonito}]: Novo Contato";
        }

        // 5. INJEÇÃO DO E-MAIL PERSONALIZADO (Só para o RH)
        if ( $is_rh_custom ) {
            
            // Resgatar os dados do candidato via $_POST
            $candidato_nome  = sanitize_text_field($_POST['nome'] ?? '');
            $candidato_email = sanitize_email($_POST['email'] ?? '');
            $candidato_tel   = sanitize_text_field($_POST['telefone'] ?? '');
            $candidato_msg   = sanitize_textarea_field($_POST['mensagem'] ?? '');
            if (empty($candidato_msg)) { $candidato_msg = 'Não preenchido'; }
            
            // Pega o link do currículo que passamos no passo 1
            $link_curriculo  = esc_url($_POST['link_curriculo_temp'] ?? '#');
            $data_atual      = date('d/m/Y');
            
            // Constrói o HTML bonito de recrutamento com aviso LGPD (Tudo direto no PHP!)
            $html_rh = '
            <p>&nbsp;</p>
            <table style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);" border="0" width="600" cellspacing="0" cellpadding="0" align="center">
            <tbody>
            <tr>
            <td style="padding: 30px; border-bottom: 1px solid #eee; background-color: #f8fafc; border-radius: 8px 8px 0 0;" align="center">
            <img src="/wp-content/uploads/2026/01/logo-uonix.png" alt="Uônix" width="200" />
            </td>
            </tr>
            <tr>
            <td style="padding: 40px;">
            <div style="text-align: center; margin-bottom: 35px;">
            <span style="display: inline-block; padding: 6px 14px; background-color: #e0e7ff; color: #0e3780; font-size: 11px; font-weight: bold; border-radius: 20px; text-transform: uppercase; letter-spacing: 1px;">Banco de Talentos</span>
            <h2 style="color: #1a2b3c; margin: 15px 0 5px 0;">Novo Currículo Recebido</h2>
            <p style="color: #64748b; font-size: 15px; margin: 0;">Uma nova candidatura acabou de chegar pelo site.</p>
            </div>

            <table style="border-collapse: collapse; margin-bottom: 30px;" width="100%">
            <tbody>
            <tr>
            <td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 14px; width: 30%;"><strong>Candidato:</strong></td>
            <td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #1a2b3c; font-size: 16px; font-weight: bold; text-align: left;">'.$candidato_nome.'</td>
            </tr>
            <tr>
            <td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 14px;"><strong>E-mail:</strong></td>
            <td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; text-align: left;"><a href="mailto:'.$candidato_email.'" style="color: #0e3780; text-decoration: none; font-size: 15px;">'.$candidato_email.'</a></td>
            </tr>
            <tr>
            <td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; color: #64748b; font-size: 14px;"><strong>Telefone:</strong></td>
            <td style="padding: 14px 0; border-bottom: 1px solid #f1f5f9; text-align: left;">
            <a href="tel:'.$candidato_tel.'" style="color: #0e3780; text-decoration: none; font-weight: bold; font-size: 15px;">'.$candidato_tel.'</a>
            </td>
            </tr>
            </tbody>
            </table>

            <div style="padding: 20px; background-color: #f8fafc; border-radius: 6px; border-left: 4px solid #0e3780; margin-bottom: 30px;">
            <strong style="color: #0e3780; font-size: 12px; text-transform: uppercase;">Apresentação do Candidato:</strong><br />
            <p style="color: #475569; line-height: 1.6; font-size: 14px; margin-top: 8px;">'.nl2br(esc_html($candidato_msg)).'</p>
            </div>

            <div style="text-align: center; margin: 30px 0;">
            <a href="'.$link_curriculo.'" target="_blank" style="display: inline-block; padding: 16px 32px; background-color: #0e3780; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 800; font-size: 15px; letter-spacing: 0.5px; box-shadow: 0 4px 12px rgba(14, 55, 128, 0.25);">Baixar Currículo</a>
            </div>

            <div style="margin-bottom: 35px; padding: 15px; background-color: #fffbeb; border: 1px solid #fef3c7; border-radius: 6px; text-align: center;">
            <strong style="color: #b45309; font-size: 12px; text-transform: uppercase;">🔒 Aviso de Segurança (LGPD)</strong>
            <p style="color: #92400e; font-size: 12px; margin: 6px 0 0 0; line-height: 1.5;">O link acima expirará em 30 dias e o arquivo será excluído do servidor do site. Recomendamos que baixe e arquive este documento na base segura da empresa.</p>
            </div>

            <p style="margin-top: 30px; color: #94a3b8; font-size: 11px; text-align: center;">Recebido em '.$data_atual.' | Origem: Página Trabalhe Conosco</p>
            </td>
            </tr>
            <tr>
            <td style="padding: 20px; background-color: #1a2b3c; color: #ffffff; font-size: 12px; border-radius: 0 0 8px 8px;" align="center">© Uônix. Todos os direitos reservados.</td>
            </tr>
            </tbody>
            </table>
            ';

            // Substitui totalmente a mensagem padrão do Fluent Forms pelo nosso HTML
            $args['message'] = $html_rh;

        } else {

            $get_field_value = function($key) use ($parsed_data) {
                $value = '';

                if (isset($_POST[$key])) {
                    $value = $_POST[$key];
                }
                elseif (isset($parsed_data[$key])) {
                    $value = $parsed_data[$key];
                }

                if (is_array($value)) {
                    $value = array_filter(array_map(function($item) {
                        if (is_scalar($item)) {
                            return trim((string) $item);
                        }
                        return '';
                    }, $value));

                    return implode(', ', $value);
                }

                if (is_scalar($value)) {
                    return trim((string) $value);
                }

                return '';
            };

            $assunto    = $get_field_value('form_assunto');
            $nome       = $get_field_value('form_nome');
            $empresa    = $get_field_value('form_empresa');
            $email      = $get_field_value('form_email');
            $telefone   = $get_field_value('form_telefone');
            $mensagem   = $get_field_value('form_mensagem');
            $newsletter = $get_field_value('form_newsletters');

            if (empty($assunto) && empty($nome) && empty($empresa) && empty($email) && empty($telefone) && empty($mensagem)) {
                $fallback_keys = function($possible_keys) use ($parsed_data) {
                    foreach ($possible_keys as $k) {
                        if (isset($_POST[$k])) {
                            $v = $_POST[$k];
                        } elseif (isset($parsed_data[$k])) {
                            $v = $parsed_data[$k];
                        } else {
                            continue;
                        }

                        if (is_array($v)) {
                            $v = array_filter(array_map(function($item) {
                                return is_scalar($item) ? trim((string) $item) : '';
                            }, $v));
                            $v = implode(', ', $v);
                        } elseif (is_scalar($v)) {
                            $v = trim((string) $v);
                        } else {
                            $v = '';
                        }

                        if ($v !== '') { return $v; }
                    }
                    return '';
                };

                $assunto    = $fallback_keys(['form_assunto', 'assunto', 'subject']);
                $nome       = $fallback_keys(['form_nome', 'nome', 'name']);
                $empresa    = $fallback_keys(['form_empresa', 'empresa', 'company']);
                $email      = $fallback_keys(['form_email', 'email']);
                $telefone   = $fallback_keys(['form_telefone', 'telefone', 'phone', 'celular']);
                $mensagem   = $fallback_keys(['form_mensagem', 'mensagem', 'message']);
                $newsletter = $fallback_keys(['form_newsletters', 'newsletter', 'newsletters']);
            }

            $news_val = !empty($newsletter) ? 'Sim' : 'Não';

            $assunto   = ($assunto   !== '') ? $assunto   : '-';
            $nome      = ($nome      !== '') ? $nome      : '-';
            $empresa   = ($empresa   !== '') ? $empresa   : '-';
            $email     = ($email     !== '') ? $email     : '-';
            $telefone  = ($telefone  !== '') ? $telefone  : '-';
            $mensagem  = ($mensagem  !== '') ? $mensagem  : '-';

            $replace_map = [
                '%ASSUNTO%'        => esc_html($assunto),
                '%NOME%'           => esc_html($nome),
                '%EMPRESA%'        => esc_html($empresa),
                '%EMAIL%'          => esc_html($email),
                '%TELEFONE%'       => esc_html($telefone),
                '%NEWSLETTER_VAL%' => esc_html($news_val),
                '%MENSAGEM%'       => nl2br(esc_html($mensagem)),
            ];

            $args['message'] = strtr($args['message'], $replace_map);

            $args['message'] = preg_replace('/<table[^>]*id="template_footer"[^>]*>.*?<\/table>/is', '', $args['message']);
        }
    }
    return $args;
}, 99 );


