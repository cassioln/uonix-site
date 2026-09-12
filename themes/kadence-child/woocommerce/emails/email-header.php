<?php
/**
 * Email Header - UÔNIX CUSTOM
 *
 * Garante dimensões proporcionais e centralizadas para o logotipo nos clientes de e-mail (Gmail, Outlook, etc.).
 *
 * @package Kadence_Child\WooCommerce\Emails
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );
$store_name                 = $store_name ?? get_bloginfo( 'name', 'display' );

/**
 * Filter the URL used for the email header image/logo link.
 */
$header_image_url = apply_filters( 'woocommerce_email_header_image_url', home_url() );
$img              = get_option( 'woocommerce_email_header_image' );

if ( apply_filters( 'woocommerce_is_email_preview', false ) ) {
	$img_transient = get_transient( 'woocommerce_email_header_image' );
	$img           = false !== $img_transient ? $img_transient : $img;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
		<meta content="width=device-width, initial-scale=1.0" name="viewport">
		<title><?php echo esc_html( $store_name ); ?></title>
	</head>
	<body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0">
		<table width="100%" id="outer_wrapper" role="presentation">
			<tr>
				<td><!-- Deliberately empty to support consistent sizing and layout across multiple email clients. --></td>
				<td width="600">
					<div id="wrapper" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
						<table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" id="inner_wrapper" role="presentation">
							<tr>
								<td align="center" valign="top">
									<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation">
										<tr>
											<td id="template_header_image" align="center" style="text-align: center; padding: 24px 0 16px;">
												<?php
												if ( $img ) {
													$image_html = '<img src="' . esc_url( $img ) . '" alt="' . esc_attr( $store_name ) . '" width="150" height="125" style="border: none; display: inline-block; font-size: 14px; font-weight: bold; height: auto; max-width: 150px; width: 150px; outline: none; text-decoration: none; vertical-align: middle; margin: 0 auto;" />';
													if ( $header_image_url ) {
														echo '<p style="margin: 0; text-align: center;"><a href="' . esc_url( $header_image_url ) . '" style="display: inline-block; text-decoration: none;" target="_blank">' . $image_html . '</a></p>';
													} else {
														echo '<p style="margin: 0; text-align: center;">' . $image_html . '</p>';
													}
												} elseif ( $header_image_url ) {
													echo '<p class="email-logo-text" style="margin: 0; text-align: center;"><a href="' . esc_url( $header_image_url ) . '" style="color: inherit; text-decoration: none;" target="_blank">' . esc_html( $store_name ) . '</a></p>';
												} else {
													echo '<p class="email-logo-text" style="margin: 0; text-align: center;">' . esc_html( $store_name ) . '</p>';
												}
												?>
											</td>
										</tr>
									</table>
									<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_container" role="presentation">
										<tr>
											<td align="center" valign="top">
												<!-- Header -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_header" role="presentation">
													<tr>
														<td id="header_wrapper">
															<h1><?php echo esc_html( $email_heading ); ?></h1>
														</td>
													</tr>
												</table>
												<!-- End Header -->
											</td>
										</tr>
										<tr>
											<td align="center" valign="top">
												<!-- Body -->
												<table border="0" cellpadding="0" cellspacing="0" width="100%" id="template_body" role="presentation">
													<tr>
														<td valign="top" id="body_content">
															<!-- Content -->
															<table border="0" cellpadding="20" cellspacing="0" width="100%" role="presentation">
																<tr>
																	<td valign="top" id="body_content_inner_cell">
																		<div id="body_content_inner">
