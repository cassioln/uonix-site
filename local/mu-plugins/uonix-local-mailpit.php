<?php
/**
 * Forces local WordPress e-mails to Mailpit.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action(
    'phpmailer_init',
    function ( $phpmailer ) {
        $phpmailer->isSMTP();
        $phpmailer->Host       = 'mailpit';
        $phpmailer->Port       = 1025;
        $phpmailer->SMTPAuth   = false;
        $phpmailer->SMTPSecure = '';
    }
);
