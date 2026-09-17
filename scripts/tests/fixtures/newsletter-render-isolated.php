<?php
/**
 * Renderização isolada para test-newsletter-deterministic-id.php.
 * Cada processo PHP representa uma requisição independente.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$module = $root . '/mu-plugins/uonix-forms/32-form-newsletter.php';
$GLOBALS['uonix_shortcodes'] = array();

function add_shortcode(string $tag, $callback): void {
    $GLOBALS['uonix_shortcodes'][$tag] = $callback;
}
function add_action(string $hook, $callback, int $priority = 10, int $args = 1): void {}
function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): void {}
function shortcode_atts(array $pairs, $atts): array { return array_merge($pairs, is_array($atts) ? $atts : array()); }
function esc_attr($text): string { return (string) $text; }
function esc_html($text): string { return (string) $text; }
function esc_url($url): string { return (string) $url; }
function get_the_title(): string { return 'Página de Teste'; }
function wp_create_nonce($action = ''): string { return 'nonce-fixo'; }
function admin_url(string $path = ''): string { return 'https://uonix.com.br/wp-admin/' . $path; }
function home_url(string $path = ''): string { return 'https://uonix.com.br' . $path; }
function get_permalink(): string { return 'https://uonix.com.br/pagina/'; }
function is_admin(): bool { return false; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value): string { return (string) $value; }
function sanitize_email($value): string { return (string) $value; }
function is_email($value) { return false !== strpos((string) $value, '@'); }
function wp_send_json_error($data = null, $code = 0): void {}
function wp_send_json_success($data = null, $code = 0): void {}
function wp_verify_nonce($nonce, $action = ''): bool { return true; }
function get_option($name, $default = false) { return $default; }
function update_option($name, $value, $autoload = null): bool { return true; }
function wp_mail($to, $subject, $message, $headers = '', $attachments = array()): bool { return true; }
function get_bloginfo($show = ''): string { return 'Uônix'; }
function wp_kses_post($content) { return $content; }
function wp_json_encode($value, int $flags = 0) { return json_encode($value, $flags); }
function wp_doing_ajax(): bool { return false; }
function did_action($hook): int { return 0; }
function plugin_dir_url($file): string { return 'https://uonix.com.br/wp-content/mu-plugins/'; }
function wp_enqueue_style(...$args): void {}
function wp_enqueue_script(...$args): void {}
function wp_localize_script(...$args): void {}
function uonix_turnstile_render_widget(string $action, array $args = array()): string {
    return '<div class="uonix-turnstile-widget" data-action="' . $action . '" data-appearance="' . ($args['appearance'] ?? '') . '"></div>';
}

define('ABSPATH', '/tmp/uonix-wp/');
require $module;

$render = $GLOBALS['uonix_shortcodes']['uonix_form_newsletter'] ?? null;
if (!is_callable($render)) {
    fwrite(STDERR, "shortcode ausente\n");
    exit(1);
}

if (isset($argv[1]) && 'twice' === $argv[1]) {
    $first = (string) $render(array('layout' => 'accordion'));
    $second = (string) $render(array('layout' => 'accordion'));
    echo json_encode(array($first, $second), JSON_UNESCAPED_UNICODE);
    exit(0);
}

echo (string) $render(array('layout' => 'accordion'));
