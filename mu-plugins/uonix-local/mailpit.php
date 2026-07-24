<?php
/**
 * Redireciona e-mails do WordPress local para o Mailpit.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'UONIX_ENV' ) || 'local' !== UONIX_ENV ) {
    return;
}

add_action(
    'phpmailer_init',
    function ( $phpmailer ) {
        $phpmailer->isSMTP();
        $phpmailer->Host        = 'mailpit';
        $phpmailer->Port        = 1025;
        $phpmailer->SMTPAuth    = false;
        $phpmailer->SMTPSecure  = '';
        $phpmailer->SMTPAutoTLS = false;
    },
    PHP_INT_MAX,
    1
);
