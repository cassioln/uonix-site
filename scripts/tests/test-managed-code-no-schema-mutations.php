<?php
/**
 * Contrato: código publicado não pode migrar schema ao ser carregado.
 *
 * Alterações de schema exigem um comando explícito, lock e marcador próprios; não
 * podem acontecer implicitamente entre o snapshot e a migração protegida.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;
$assertions = 0;

/** @return list<string> */
function schema_mutation_findings(string $source, string $label): array {
    $tokens = token_get_all($source);
    $forbidden_calls = array(
        'dbdelta',
        'maybe_create_table',
        'maybe_add_column',
        'register_activation_hook',
    );
    $findings = array();
    $strings = array();
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token)) {
            continue;
        }

        [$id, $text, $line] = $token;
        if (T_STRING === $id && in_array(strtolower($text), $forbidden_calls, true)) {
            for ($next = $index + 1; $next < $count; $next++) {
                $candidate = $tokens[$next];
                if (is_array($candidate) && in_array($candidate[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                    continue;
                }
                if ('(' === $candidate) {
                    $findings[] = sprintf('%s:%d chama %s()', $label, $line, $text);
                }
                break;
            }
        }

        if (in_array($id, array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE), true)) {
            $strings[] = array($line, trim($text, "'\""));
        }
    }

    $joined = '';
    $first_string_line = 1;
    foreach ($strings as [$line, $text]) {
        if ('' === $joined) {
            $first_string_line = $line;
        }
        $joined .= ' ' . stripcslashes($text);
    }
    $ddl_view = preg_replace('/%(?:\d+\$)?[-+0-9.]*[bcdeEfFgGosuxX]/', ' ', $joined);
    if (!is_string($ddl_view)) {
        $ddl_view = $joined;
    }
    if (preg_match('/\b(?:CREATE|ALTER|DROP|TRUNCATE|RENAME)\s+(?:TABLE|INDEX|DATABASE|COLUMN)\b/i', $ddl_view, $match)) {
        $findings[] = sprintf('%s:%d contém DDL de schema (%s)', $label, $first_string_line, strtoupper($match[0]));
    }

    return $findings;
}

function contract_assert(bool $condition, string $message): void {
    global $assertions, $failures;
    $assertions++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$benign = <<<'PHP'
<?php
// dbDelta('CREATE TABLE ignored_comment');
function save_admin_form() { update_option('example', 'value'); }
PHP;
contract_assert(
    array() === schema_mutation_findings($benign, 'benign.php'),
    'comentários e gravações de formulário sem DDL não são falsos positivos'
);

$bad_call = <<<'PHP'
<?php
function migrate() { dbDelta($sql); }
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_call, 'bad-call.php')),
    'chamada dbDelta é detectada mesmo dentro de função'
);

$bad_split_ddl = <<<'PHP'
<?php
$sql = 'ALTER ' . 'TABLE wp_example ADD COLUMN unsafe int';
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_split_ddl, 'bad-split.php')),
    'DDL concatenado é detectado'
);

$bad_dynamic_ddl = <<<'PHP'
<?php
$verb = 'ALTER';
$wpdb->query(sprintf('%s TABLE `%s` ADD COLUMN probe int', $verb, $table));
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_dynamic_ddl, 'bad-dynamic.php')),
    'DDL montado com verbo dinâmico e sprintf é detectado'
);

$managed_roots = array(
    $root . '/mu-plugins',
    $root . '/themes/kadence-child',
);
$repo_findings = array();
foreach ($managed_roots as $managed_root) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($managed_root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || 'php' !== strtolower($file->getExtension())) {
            continue;
        }
        $path = $file->getPathname();
        $relative = substr($path, strlen($root) + 1);
        $source = file_get_contents($path);
        if (false === $source) {
            $repo_findings[] = $relative . ': não foi possível ler';
            continue;
        }
        array_push($repo_findings, ...schema_mutation_findings($source, $relative));
    }
}

contract_assert(
    array() === $repo_findings,
    "código gerenciado não contém migração automática de schema:\n" . implode("\n", $repo_findings)
);

if (0 !== $failures) {
    fwrite(STDERR, sprintf("FAIL: %d de %d contratos de schema falharam.\n", $failures, $assertions));
    exit(1);
}

printf("PASS: código gerenciado não migra schema implicitamente. (%d asserções)\n", $assertions);
