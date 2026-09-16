<?php
/**
 * Contrato de cache: newsletter gera HTML estável entre requests e IDs únicos
 * entre instâncias da mesma requisição.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = $root . '/mu-plugins/uonix-forms/32-form-newsletter.php';
$fixture = __DIR__ . '/fixtures/newsletter-render-isolated.php';
$failures = array();

function newsletter_id_fail(string $message): void {
    global $failures;
    $failures[] = $message;
}

function newsletter_id_run_fixture(string $fixture, ?string $argument = null): string {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture);
    if (null !== $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $output = array();
    $status = 0;
    exec($command, $output, $status);
    if (0 !== $status) {
        newsletter_id_fail("fixture isolado falhou (exit {$status})");
        return '';
    }
    return implode("\n", $output);
}

if (!is_file($module) || !is_file($fixture)) {
    fwrite(STDERR, "FAIL: módulo ou fixture ausente\n");
    exit(1);
}

// Não pode restar chamada executável a gerador temporal/aleatório.
$tokens = token_get_all((string) file_get_contents($module));
$code = '';
foreach ($tokens as $token) {
    if (is_array($token) && in_array($token[0], array(T_COMMENT, T_DOC_COMMENT, T_WHITESPACE), true)) {
        continue;
    }
    $code .= is_array($token) ? $token[1] : $token;
}
foreach (array('uniqid(', 'wp_rand(', 'mt_rand(', 'random_int(', 'microtime(') as $forbidden) {
    if (false !== strpos($code, $forbidden)) {
        newsletter_id_fail("fonte não determinística executável: {$forbidden}");
    }
}

// Dois processos distintos representam duas requisições PHP independentes.
$first = newsletter_id_run_fixture($fixture);
$second = newsletter_id_run_fixture($fixture);
if ('' === $first || '' === $second) {
    newsletter_id_fail('render isolado vazio');
} elseif ($first !== $second) {
    newsletter_id_fail('HTML difere entre processos PHP independentes: cache não será servido');
}

$extract_ids = static function (string $html): array {
    preg_match_all('/id="container_(unf_[a-z0-9_]+)"/i', $html, $matches);
    return $matches[1];
};
if (array('unf_1') !== $extract_ids($first)) {
    newsletter_id_fail('primeira requisição não gerou o ID determinístico esperado unf_1');
}

// Duas ocorrências no mesmo processo devem receber IDs distintos.
$intra_raw = newsletter_id_run_fixture($fixture, 'twice');
$intra = json_decode($intra_raw, true);
if (!is_array($intra) || 2 !== count($intra)) {
    newsletter_id_fail('fixture intra-requisição não retornou dois HTMLs');
} else {
    $ids_a = $extract_ids((string) $intra[0]);
    $ids_b = $extract_ids((string) $intra[1]);
    if (array('unf_1') !== $ids_a || array('unf_2') !== $ids_b) {
        newsletter_id_fail('IDs não são únicos e sequenciais dentro da mesma requisição');
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}
printf("PASS: newsletter tem IDs determinísticos entre requests e únicos por instância.\n");
