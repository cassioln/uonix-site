<?php
/**
 * Aplica a configuração segura de WP Super Cache em modo Simple.
 *
 * Execute exclusivamente dentro de um WordPress que já tenha o plugin
 * wp-super-cache ativo; este arquivo não instala nem atualiza plugins.
 */
if (!defined('ABSPATH')) {
    fwrite(STDERR, "WPSC_SIMPLE_CONFIGURATION=BLOCKED missing_wordpress\n");
    exit(1);
}

foreach (array('wp_cache_setting', 'wp_cache_replace_line', 'wp_cache_enable', 'wp_super_cache_enable', 'wp_cache_verify_cache_dir', 'wpsc_check_advanced_cache', 'wp_cache_verify_config_file', 'prune_super_cache', 'wp_clear_scheduled_hook') as $function) {
    if (!function_exists($function)) {
        fwrite(STDERR, "WPSC_SIMPLE_CONFIGURATION=BLOCKED missing_function={$function}\n");
        exit(1);
    }
}

$cache_path = isset($cache_path) && is_string($cache_path) && $cache_path !== ''
    ? $cache_path
    : WP_CONTENT_DIR . '/cache/';

if (!wp_cache_verify_cache_dir() || !wpsc_check_advanced_cache() || !wp_cache_verify_config_file()) {
    fwrite(STDERR, "WPSC_SIMPLE_CONFIGURATION=BLOCKED bootstrap_artifacts\n");
    exit(1);
}
if (!isset($GLOBALS['wp_cache_config_file']) || !is_string($GLOBALS['wp_cache_config_file']) || '' === $GLOBALS['wp_cache_config_file']) {
    fwrite(STDERR, "WPSC_SIMPLE_CONFIGURATION=BLOCKED config_path_missing\n");
    exit(1);
}

$rejected_cookies = array(
    'PHPSESSID',
    'rfqtk_wp_session_',
    'wp_woocommerce_session_',
    'woocommerce_items_in_cart',
    'woocommerce_cart_hash',
);
$rejected_uri = array(
    'wp-.*\\.php',
    'index\\.php',
    '^/cotacao(?:/|$)',
    '^/finalizar-orcamento(?:/|$)',
    '^/(?:minha-conta|conta|account)(?:/|$)',
);
$settings = array(
    'wpsc_rejected_cookies' => $rejected_cookies,
    'cache_rejected_uri' => $rejected_uri,
    'wp_cache_mod_rewrite' => 0,
    // Em Simple/PHP, sem esta guarda o WPSC só serve URLs sem barra final.
    // WordPress canoniza categoria/produto com '/', então cache não era servido.
    'wp_cache_slash_check' => 1,
    'wp_cache_not_logged_in' => 2,
    'wp_cache_mobile_enabled' => 0,
    'wp_cache_make_known_anon' => 0,
    'wp_cache_object_cache' => 0,
    'cache_rebuild_files' => 1,
    'wp_cache_preload_on' => 0,
);

foreach ($settings as $field => $value) {
    // O retorno de wp_cache_setting() depende da escrita do arquivo de
    // configuração e pode ser false mesmo quando o global foi atualizado.
    // A persistência é verificada na requisição WP-CLI separada do deploy.
    wp_cache_setting($field, $value);
}

// Em instalações existentes, wp_cache_slash_check pode não ter linha própria.
// wp_cache_setting() atualiza o global, mas a API genérica do WPSC 3.1.3 não
// garante a inserção dessa chave ausente. Persista explicitamente pela própria
// rotina de escrita atômica do plugin; o processo WP-CLI seguinte confirma.
if (!wp_cache_replace_line(
    '^ *\\$wp_cache_slash_check',
    '$wp_cache_slash_check = 1;',
    $GLOBALS['wp_cache_config_file']
)) {
    fwrite(STDERR, "WPSC_SIMPLE_CONFIGURATION=BLOCKED slash_persistence_failed\n");
    exit(1);
}
$GLOBALS['wp_cache_slash_check'] = 1;

wp_cache_enable();
wp_super_cache_enable();
if (empty($GLOBALS['cache_enabled']) || empty($GLOBALS['super_cache_enabled'])) {
    fwrite(STDERR, "WPSC_SIMPLE_CONFIGURATION=BLOCKED enable_failed\n");
    exit(1);
}

wp_clear_scheduled_hook('wp_cache_preload_hook');
// Em instalação nova ainda não há arquivos em cache; o WPSC retorna false ao
// podar diretório inexistente. A chamada continua limpando qualquer resíduo,
// sem transformar o cache vazio em falha de ativação.
prune_super_cache($cache_path, true);

printf("WPSC_SIMPLE_CONFIGURATION=PASS mode=PHP rejected_cookies=%d rejected_uris=%d\n", count($rejected_cookies), count($rejected_uri));
