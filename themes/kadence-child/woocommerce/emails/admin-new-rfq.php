<?php
/**
 * Admin new RFQ email - UÔNIX PREMIUM (ADMIN FINAL)
 * Ajuste: Correção do mapeamento do nome da empresa
 */

if (!defined('ABSPATH')) exit;

// 1. FILTROS TÉCNICOS
add_filter('gpls_woo_rfq_show_prices_customer_email', '__return_false');
add_filter('woocommerce_email_order_items_args', function($args) {
    $args['show_prices'] = false;
    return $args;
}, 100);

/**
 * 2. DATA RETRIEVAL
 */
$order_id = $order->get_id();

// Busca o nome do solicitante (ajustado para o seu slug billing_complete_name)
$responsavel = $order->get_meta('billing_complete_name') ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

$empresa_uonix = $order->get_meta('billing_company_name') ?: $order->get_billing_company();

$documento_uonix = $order->get_meta('billing_cnpj');
$documento_uonix = str_replace('`', '', $documento_uonix);

$documento_digits = preg_replace('/\D+/', '', $documento_uonix);
$documento_label  = 'CNPJ';

if (strlen($documento_digits) === 11) {
    $documento_label = 'CPF';
    $documento_uonix = preg_replace(
        '/(\d{3})(\d{3})(\d{3})(\d{2})/',
        '$1.$2.$3-$4',
        $documento_digits
    );
} elseif (strlen($documento_digits) === 14) {
    $documento_label = 'CNPJ';
    $documento_uonix = preg_replace(
        '/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/',
        '$1.$2.$3/$4-$5',
        $documento_digits
    );
}

do_action('woocommerce_email_header', $email_heading, $email);
?>

<div style="font-family: 'Segoe UI', Tahoma, sans-serif; color: #333; line-height: 1.6;">

    <div style="padding: 20px 0; border-bottom: 1px solid #eee; margin-bottom: 30px;">
        <h2 style="color: #1a2b3c; margin-top: 0; font-size: 24px;">Pedido #<?php echo $order->get_order_number(); ?></h2>
        <p style="font-size: 16px; color: #555;">
            Olá, equipe <strong>Uônix</strong>. Uma nova solicitação de orçamento foi registrada no site. 
            Confira os detalhes abaixo para gerar a proposta comercial:
        </p>
    </div>

    <div style="background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 30px; border-left: 4px solid #1a2b3c;">
        <table cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td align="left">
                    <span style="text-transform: uppercase; font-size: 11px; font-weight: bold; color: #777; letter-spacing: 1px;">Status: Aguardando Análise</span>
                    <h3 style="margin: 5px 0 0; color: #1a2b3c; font-size: 20px;">Orçamento #<?php echo $order->get_order_number(); ?></h3>
                </td>
                <td align="right">
                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>" 
                       style="background-color: #1a2b3c; color: #ffffff; padding: 12px 20px; text-decoration: none; border-radius: 4px; font-size: 13px; font-weight: bold; display: inline-block;">
                       ABRIR NO PAINEL
                    </a>
                </td>
            </tr>
        </table>
    </div>

    <table cellspacing="0" cellpadding="0" style="width: 100%; margin-bottom: 40px; border-collapse: collapse; border: 1px solid #eee;" width="100%">
        <thead>
            <tr style="background-color: #fdfdfd;" bgcolor="#fdfdfd">
                <th style="text-align: left; padding: 12px; border-bottom: 2px solid #eee; color: #777; font-size: 13px; text-transform: uppercase;" align="left">Item Solicitado</th>
                <th style="text-align: center; padding: 12px; border-bottom: 2px solid #eee; color: #777; font-size: 13px; text-transform: uppercase;" align="center">Qtd</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $order->get_items() as $item_id => $item ) : 
            $product = $item->get_product();
            $qty     = $item->get_quantity();
            $img_url = $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : '';
            
            // --- LIMPEZA DO NOME: TROCA <br> POR ' - ' ---
            $name    = str_ireplace(['<br>', '<br/>', '<br />'], ' - ', $item->get_name());
            
            $meta    = wc_display_item_meta($item, ['echo' => false]);
            $sku     = ($product && $product->get_sku()) ? $product->get_sku() : '';
        ?>
            <tr>
                <td style="padding: 15px; border: 1px solid #eee; vertical-align: middle;">
                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                        <tr>
                            <?php if ($img_url) : ?>
                            <td width="90" valign="middle" style="padding-right: 15px;">
                                <img src="<?php echo esc_url($img_url); ?>" width="80" height="80" style="border-radius: 4px; display: block; object-fit: cover;">
                            </td>
                            <?php endif; ?>
                            <td valign="middle">
                                <div style="font-size: 15px; color: #111; font-weight: bold; line-height: 1.3;"><?php echo esc_html($name); ?></div>
                                <?php if ($sku) : ?>
                                    <div style="font-size: 12px; color: #888; margin-top: 3px;">SKU: <?php echo esc_html($sku); ?></div>
                                <?php endif; ?>
                                <?php if ($meta) : ?>
                                    <div style="font-size: 12px; color: #666; margin-top: 5px; line-height: 1.4;"><?php echo $meta; ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="padding: 15px; border: 1px solid #eee; text-align: center; vertical-align: middle; font-weight: bold; font-size: 16px;" align="center">
                    <?php echo esc_html($qty); ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div style="background-color: #ffffff; border: 1px solid #eee; border-radius: 8px; padding: 25px; margin-bottom: 30px;" bgcolor="#ffffff">
        
        <h4 style="margin: 0 0 10px; color: #1a2b3c; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Dados do Solicitante:</h4>
        <p style="margin: 0 0 20px; font-size: 14px; color: #555; line-height: 1.5;">
            <strong>Responsável:</strong> <?php echo esc_html($responsavel); ?><br>
            <strong>Empresa:</strong> <?php echo esc_html($empresa_uonix ?: 'Não informada'); ?><br>
            <strong><?php echo esc_html($documento_label); ?>:</strong> <?php echo esc_html($documento_uonix ?: 'Não informado'); ?>
        </p>

        <h4 style="margin: 20px 0 10px; color: #1a2b3c; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Contato Direto:</h4>
        <p style="margin: 0 0 20px; font-size: 14px; color: #555; line-height: 1.5;">
            <strong>Telefone:</strong> <?php echo esc_html($order->get_billing_phone()); ?><br>
            <strong>E-mail:</strong> <?php echo esc_html($order->get_billing_email()); ?>
        </p>

        <h4 style="margin: 20px 0 10px; color: #1a2b3c; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Endereço de Entrega/Faturamento:</h4>
        <div style="font-size: 14px; color: #555; line-height: 1.6;">
            <?php 
            // Extração dos campos individuais
            $rua         = $order->get_billing_address_1();
            $numero      = $order->get_meta('billing_address_3'); // Mapeado conforme seu formulário
            $complemento = $order->get_billing_address_2();
            $cidade      = $order->get_billing_city();
            $estado      = $order->get_billing_state();
            $cep         = $order->get_billing_postcode();

            // Linha 1: Rua, Numero
            echo esc_html($rua) . ($numero ? ', ' . esc_html($numero) : '') . '<br>';

            // Linha 2: Complemento (Somente se existir)
            if ( ! empty( $complemento ) ) {
                echo esc_html($complemento) . '<br>';
            }

            // Linha 3: Cidade / Estado
            echo esc_html($cidade) . ' / ' . esc_html($estado) . '<br>';

            // Linha 4: CEP
            echo 'CEP: ' . esc_html($cep);
            ?>
        </div>

        <?php if ( $note = $order->get_customer_note() ) : ?>
            <div style="margin-top: 30px; padding: 20px; background-color: #f2f9ff; border-radius: 8px; border-left: 4px solid #003399;">
                <strong style="color: #003399; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Observações do Cliente:</strong>
                <div style="color: #111; font-size: 15px; font-weight: 600; margin-top: 8px; line-height: 1.5;">
                    <?php echo nl2br(esc_html($note)); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($additional_content) : ?>
        <div style="text-align: center; padding: 20px 0; font-size: 14px; color: #888; border-top: 1px solid #eee;">
            <?php echo wpautop(wptexturize($additional_content)); ?>
        </div>
    <?php endif; ?>

    <div style="text-align: center; padding: 40px 0;" align="center">
        <p style="font-size: 11px; color: #bbb; text-transform: uppercase; letter-spacing: 1px;">
            E-mail Administrativo - Sistema Interno Uônix<br>
            Gerado em: <?php echo date('d/m/Y \à\s H:i'); ?>
        </p>
    </div>

</div>

<?php 
do_action('woocommerce_email_footer', $email);