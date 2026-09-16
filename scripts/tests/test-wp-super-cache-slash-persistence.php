<?php
/** Reproduz a semântica de persistência real do WPSC para campo ausente. */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = (string) file_get_contents($root . '/scripts/configure-wp-super-cache-simple.php');

// O configurador deve usar a API especializada que persiste a política de
// trailing slash mesmo quando a linha ainda não existe no config legado.
if (false === strpos($source, 'wp_cache_replace_line(')
    || false === strpos($source, "'wp_cache_slash_check'")) {
    fwrite(STDERR, "FAIL: slash-check não usa persistência explícita para config legado\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'uonix-wpsc-config-');
if (false === $tmp) {
    fwrite(STDERR, "FAIL: tempnam\n");
    exit(1);
}
register_shutdown_function(static function () use ($tmp): void { @unlink($tmp); });
file_put_contents($tmp, "<?php\n\$wp_cache_mod_rewrite = 0;\n");

function wp_cache_replace_line(string $old, string $new, string $file): bool {
    $lines = file($file);
    if (!is_array($lines)) { return false; }
    $found = false;
    foreach ($lines as $line) {
        if (preg_match("/$old/", $line)) { $found = true; break; }
    }
    $out = '';
    $inserted = false;
    foreach ($lines as $line) {
        if ($found && preg_match("/$old/", $line)) {
            $out .= $new . "\n";
        } elseif (!$found && !$inserted && preg_match('/^(if\ \(\ \!\ )?define|\$|\?>/', $line)) {
            $out .= $new . "\n" . $line;
            $inserted = true;
        } else {
            $out .= $line;
        }
    }
    return false !== file_put_contents($file, $out);
}

$GLOBALS['wp_cache_config_file'] = $tmp;
if (!wp_cache_replace_line('^ *\\$wp_cache_slash_check', '$wp_cache_slash_check = 1;', $tmp)) {
    fwrite(STDERR, "FAIL: persistência explícita falhou\n");
    exit(1);
}
// Segunda execução precisa ser no-op sem duplicar a chave no PHP gerado.
if (!wp_cache_replace_line('^ *\\$wp_cache_slash_check', '$wp_cache_slash_check = 1;', $tmp)) {
    fwrite(STDERR, "FAIL: segunda persistência idempotente falhou\n");
    exit(1);
}
$contents = (string) file_get_contents($tmp);
if (1 !== substr_count($contents, '$wp_cache_slash_check = 1;')) {
    fwrite(STDERR, "FAIL: slash-check não foi inserido exatamente uma vez\n");
    exit(1);
}
printf("PASS: slash-check ausente em config legado recebe linha persistente.\n");
