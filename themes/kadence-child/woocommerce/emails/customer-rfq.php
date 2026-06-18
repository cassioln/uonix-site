<?php
/**
 * Customer new RFQ email - UÔNIX PREMIUM V18 (IDENTIDADE UNIFICADA)
 * Design: Cabeçalho com número da solicitação + Endereço customizado
 */

if (!defined('ABSPATH')) exit;

// 1. FILTROS DE INTERFACE
add_filter('gpls_woo_rfq_show_prices_customer_email', '__return_false');
add_filter('woocommerce_email_order_items_args', function($args) {
    $args['show_prices'] = false;
    return $args;
}, 100);

/**
 * 2. DATA RETRIEVAL
 */
$order_id = $order->get_id();
$responsavel = $order->get_meta('section_one-name') ?: ($order->get_meta('billing_complete_name') ?: trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()));
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

$endereco_linhas = function_exists( 'uonix_shared_get_order_billing_address_lines' )
    ? uonix_shared_get_order_billing_address_lines( $order )
    : array( 'Não informado' );

do_action('woocommerce_email_header', $email_heading, $email);
?>

<div style="font-family: 'Segoe UI', Tahoma, sans-serif; color: #333; line-height: 1.6;">

    <div style="padding: 20px 0; border-bottom: 1px solid #eee; margin-bottom: 30px;">
        <p style="font-size: 16px; color: #555;">
            Olá, <strong><?php echo esc_html($responsavel ?: 'Cliente'); ?></strong>. Confirmamos o recebimento dos seus dados. 
            Nossa equipe técnica já está analisando sua solicitação para gerar a proposta comercial.
        </p>
    </div>

    <div style="background-color: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 30px; border-left: 4px solid #1a2b3c;">
        <table cellspacing="0" cellpadding="0" border="0" width="100%">
            <tr>
                <td align="left">
                    <span style="text-transform: uppercase; font-size: 11px; font-weight: bold; color: #777; letter-spacing: 1px;">Status: Recebido / Em Análise</span>
                    <h3 style="margin: 5px 0 0; color: #1a2b3c; font-size: 20px;">Orçamento #<?php echo $order->get_order_number(); ?></h3>
                </td>
            </tr>
        </table>
    </div>

    <table cellspacing="0" cellpadding="0" style="width: 100%; margin-bottom: 40px; border-collapse: collapse; border: 1px solid #eee;" width="100%">
        <thead>
            <tr style="background-color: #fdfdfd;" bgcolor="#fdfdfd">
                <th style="text-align: left; padding: 12px; border-bottom: 2px solid #eee; color: #777; font-size: 12px; text-transform: uppercase;" align="left">Itens solicitados</th>
                <th style="text-align: center; padding: 12px; border-bottom: 2px solid #eee; color: #777; font-size: 12px; text-transform: uppercase;" align="center">Qtd</th>
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
                            <td width="80" valign="middle" style="padding-right: 15px;">
                                <img src="<?php echo esc_url($img_url); ?>" width="70" height="70" style="border-radius: 4px; display: block; border: 1px solid #f2f2f2;">
                            </td>
                            <?php endif; ?>
                            <td valign="middle">
                                <div style="font-size: 14px; color: #111; font-weight: bold; line-height: 1.3;"><?php echo esc_html($name); ?></div>
                                <?php if ($sku) : ?>
                                    <div style="font-size: 11px; color: #999; margin-top: 2px;">SKU: <?php echo esc_html($sku); ?></div>
                                <?php endif; ?>
                                <?php if ($meta) : ?>
                                    <div style="font-size: 11px; color: #666; margin-top: 4px; line-height: 1.4;"><?php echo $meta; ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="padding: 15px; border: 1px solid #eee; text-align: center; vertical-align: middle; font-weight: bold; font-size: 15px;" align="center">
                    <?php echo esc_html($qty); ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div style="background-color: #ffffff; border: 1px solid #eee; border-radius: 8px; padding: 25px; margin-bottom: 30px;" bgcolor="#ffffff">
        
        <h4 style="margin: 0 0 10px; color: #1a2b3c; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px;">Dados registrados:</h4>
        <p style="margin: 0 0 20px; font-size: 14px; color: #555; line-height: 1.6;">
            <strong>Solicitante:</strong> <?php echo esc_html($responsavel); ?><br>
            <strong>Empresa:</strong> <?php echo esc_html($empresa_uonix ?: 'Não informada'); ?><br>
            <strong><?php echo esc_html($documento_label); ?>:</strong> <?php echo esc_html($documento_uonix ?: 'Não informado'); ?>
        </p>

        <h4 style="margin: 20px 0 10px; color: #1a2b3c; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px;">Contato:</h4>
        <p style="margin: 0 0 20px; font-size: 14px; color: #555; line-height: 1.6;">
            <strong>Telefone:</strong> <?php echo esc_html($order->get_billing_phone()); ?><br>
            <strong>E-mail:</strong> <?php echo esc_html($order->get_billing_email()); ?>
        </p>

        <h4 style="margin: 20px 0 10px; color: #1a2b3c; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px;">Endereço Informado:</h4>
        <div style="font-size: 14px; color: #555; line-height: 1.6;">
            <?php
            echo implode( '<br>', array_map( 'esc_html', $endereco_linhas ) );
            ?>
        </div>

        <?php if ( $note = $order->get_customer_note() ) : ?>
            <div style="margin-top: 25px; padding: 15px; background-color: #f9f9f9; border-radius: 6px; border-left: 3px solid #ccc;">
                <strong style="color: #333; font-size: 13px;">Sua observação:</strong><br>
                <span style="color: #666; font-size: 13px; font-style: italic;"><?php echo nl2br(esc_html($note)); ?></span>
            </div>
        <?php endif; ?>
    </div>

    <div style="text-align: center; padding: 40px 0; border-top: 1px solid #eee;" align="center">
        <p style="font-size: 16px; color: #1a2b3c; font-weight: bold; margin-bottom: 8px;">Aguarde, nossa equipe já está analisando seu pedido.</p>
        <div style="font-size: 14px; color: #777;">
            <p>Responderemos com a proposta comercial detalhada o mais breve possível.</p>
            <p style="margin-top: 20px; font-size: 12px; color: #999;">Uônix - Ancoragem Predial</p>
        </div>
    </div>

</div>

<?php 
do_action('woocommerce_email_footer', $email);
