<?php
/**
 * Bloqueia XML-RPC e pingbacks sem depender do Loginizer Pro.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_security_is_xmlrpc_request' ) ) {
	function uonix_security_is_xmlrpc_request() {
		return defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST;
	}
}

if ( ! function_exists( 'uonix_security_block_xmlrpc_request' ) ) {
	function uonix_security_block_xmlrpc_request() {
		if ( ! uonix_security_is_xmlrpc_request() ) {
			return;
		}

		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'XML-RPC is disabled';
		exit;
	}
}
add_action( 'muplugins_loaded', 'uonix_security_block_xmlrpc_request', 0 );
add_action( 'plugins_loaded', 'uonix_security_block_xmlrpc_request', 0 );

add_filter( 'xmlrpc_enabled', '__return_false', 1000 );

if ( ! function_exists( 'uonix_security_remove_xmlrpc_methods' ) ) {
	function uonix_security_remove_xmlrpc_methods( $methods ) {
		return array();
	}
}
add_filter( 'xmlrpc_methods', 'uonix_security_remove_xmlrpc_methods', 1000 );

if ( ! function_exists( 'uonix_security_close_pings' ) ) {
	function uonix_security_close_pings() {
		return false;
	}
}
add_filter( 'pings_open', 'uonix_security_close_pings', 1000 );

if ( ! function_exists( 'uonix_security_disable_pingback_defaults' ) ) {
	function uonix_security_disable_pingback_defaults() {
		return 'closed';
	}
}
add_filter( 'pre_option_default_ping_status', 'uonix_security_disable_pingback_defaults', 1000 );

if ( ! function_exists( 'uonix_security_disable_pingback_flag' ) ) {
	function uonix_security_disable_pingback_flag() {
		return 0;
	}
}
add_filter( 'pre_option_default_pingback_flag', 'uonix_security_disable_pingback_flag', 1000 );

if ( ! function_exists( 'uonix_security_remove_pingback_headers' ) ) {
	function uonix_security_remove_pingback_headers( $headers ) {
		unset( $headers['X-Pingback'] );

		return $headers;
	}
}
add_filter( 'wp_headers', 'uonix_security_remove_pingback_headers', 1000 );

if ( ! function_exists( 'uonix_security_empty_pingback_url' ) ) {
	function uonix_security_empty_pingback_url( $output, $show ) {
		if ( 'pingback_url' === $show ) {
			return '';
		}

		return $output;
	}
}
add_filter( 'bloginfo_url', 'uonix_security_empty_pingback_url', 1000, 2 );

if ( ! function_exists( 'uonix_security_remove_remote_publishing_links' ) ) {
	function uonix_security_remove_remote_publishing_links() {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
	}
}
add_action( 'init', 'uonix_security_remove_remote_publishing_links', 0 );

if ( ! function_exists( 'uonix_security_clear_pingback_links' ) ) {
	function uonix_security_clear_pingback_links( &$links ) {
		$links = array();
	}
}
add_action( 'pre_ping', 'uonix_security_clear_pingback_links', 1000 );
