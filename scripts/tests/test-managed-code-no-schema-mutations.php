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

function schema_token_is_trivia(mixed $token): bool {
    return is_array($token)
        && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
}

/** @param list<mixed> $tokens */
function schema_next_significant(array $tokens, int $index): ?int {
    for ($next = $index + 1, $count = count($tokens); $next < $count; $next++) {
        if (!schema_token_is_trivia($tokens[$next])) {
            return $next;
        }
    }
    return null;
}

/** @param list<mixed> $tokens */
function schema_previous_significant(array $tokens, int $index): ?int {
    for ($previous = $index - 1; $previous >= 0; $previous--) {
        if (!schema_token_is_trivia($tokens[$previous])) {
            return $previous;
        }
    }
    return null;
}

function schema_token_is_name(mixed $token): bool {
    if (!is_array($token)) {
        return false;
    }
    $ids = array(T_STRING);
    foreach (array('T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE') as $constant) {
        if (defined($constant)) {
            $ids[] = constant($constant);
        }
    }
    return in_array($token[0], $ids, true);
}

function schema_callable_basename(string $name): string {
    $parts = explode('\\', ltrim($name, '\\'));
    return strtolower((string) end($parts));
}

function schema_string_token_value(mixed $token): ?string {
    if (!is_array($token)) {
        return null;
    }
    if (T_ENCAPSED_AND_WHITESPACE === $token[0]) {
        return $token[1];
    }
    if (T_CONSTANT_ENCAPSED_STRING !== $token[0] || strlen($token[1]) < 2) {
        return null;
    }

    $quote = $token[1][0];
    $body = substr($token[1], 1, -1);
    if ("'" === $quote) {
        return preg_replace_callback(
            '/\\\\([\\\\\'])/',
            static fn(array $match): string => $match[1],
            $body
        );
    }
    return stripcslashes($body);
}

/** @param list<mixed> $tokens */
function schema_without_trivia(array $tokens): array {
    return array_values(array_filter(
        $tokens,
        static fn(mixed $token): bool => !schema_token_is_trivia($token)
    ));
}

/** @param list<mixed> $tokens */
function schema_matching_parenthesis(array $tokens, int $open_index): ?int {
    $depth = 0;
    for ($index = $open_index, $count = count($tokens); $index < $count; $index++) {
        if ('(' === $tokens[$index]) {
            $depth++;
        } elseif (')' === $tokens[$index]) {
            $depth--;
            if (0 === $depth) {
                return $index;
            }
        }
    }
    return null;
}

/** @param list<mixed> $tokens @return list<list<mixed>> */
function schema_split_top_level(array $tokens, string $delimiter): array {
    $parts = array();
    $part = array();
    $depth = 0;
    foreach ($tokens as $token) {
        if (in_array($token, array('(', '[', '{'), true)) {
            $depth++;
        } elseif (in_array($token, array(')', ']', '}'), true)) {
            $depth--;
        }
        if (0 === $depth && $delimiter === $token) {
            $parts[] = $part;
            $part = array();
            continue;
        }
        $part[] = $token;
    }
    $parts[] = $part;
    return $parts;
}

/** @param list<mixed> $tokens */
function schema_trim_parentheses(array $tokens): array {
    while (count($tokens) >= 2 && '(' === $tokens[0]) {
        $closing = schema_matching_parenthesis($tokens, 0);
        if ($closing !== count($tokens) - 1) {
            break;
        }
        $tokens = array_slice($tokens, 1, -1);
    }
    return $tokens;
}

/**
 * @param list<mixed> $tokens
 * @param array<string,string> $constants
 */
function schema_static_string(array $tokens, array $constants): ?string {
    $tokens = schema_trim_parentheses(schema_without_trivia($tokens));
    if (array() === $tokens) {
        return '';
    }

    $concatenated = schema_split_top_level($tokens, '.');
    if (count($concatenated) > 1) {
        $value = '';
        foreach ($concatenated as $part) {
            $part_value = schema_static_string($part, $constants);
            if (null === $part_value) {
                return null;
            }
            $value .= $part_value;
        }
        return $value;
    }

    if (1 === count($tokens)) {
        $literal = schema_string_token_value($tokens[0]);
        if (null !== $literal) {
            return $literal;
        }
        if (is_array($tokens[0]) && T_VARIABLE === $tokens[0][0]) {
            return $constants[$tokens[0][1]] ?? null;
        }
        return null;
    }

    if ('"' === $tokens[0] && '"' === $tokens[count($tokens) - 1]) {
        $value = '';
        $interpolation_depth = 0;
        foreach (array_slice($tokens, 1, -1) as $token) {
            if ($interpolation_depth > 0) {
                if ('{' === $token) {
                    $interpolation_depth++;
                } elseif ('}' === $token) {
                    $interpolation_depth--;
                }
                continue;
            }
            if (
                is_array($token)
                && in_array($token[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true)
            ) {
                $value .= 'x';
                $interpolation_depth = 1;
                continue;
            }
            $part = schema_string_token_value($token);
            if (null !== $part) {
                $value .= $part;
            } elseif (is_array($token) && T_VARIABLE === $token[0]) {
                $value .= $constants[$token[1]] ?? 'x';
            } else {
                $value .= 'x';
            }
        }
        return 0 === $interpolation_depth ? $value : null;
    }

    if (
        is_array($tokens[0])
        && T_START_HEREDOC === $tokens[0][0]
        && is_array($tokens[count($tokens) - 1])
        && T_END_HEREDOC === $tokens[count($tokens) - 1][0]
    ) {
        $value = '';
        foreach (array_slice($tokens, 1, -1) as $token) {
            $part = schema_string_token_value($token);
            if (null !== $part) {
                $value .= $part;
            } elseif (is_array($token) && T_VARIABLE === $token[0]) {
                $value .= $constants[$token[1]] ?? 'x';
            } else {
                return null;
            }
        }
        return $value;
    }

    $function = null;
    $open_index = null;
    if (schema_token_is_name($tokens[0]) && '(' === ($tokens[1] ?? null)) {
        $function = schema_callable_basename($tokens[0][1]);
        $open_index = 1;
    } elseif (
        isset($tokens[0], $tokens[1], $tokens[2], $tokens[3])
        && is_array($tokens[0])
        && T_VARIABLE === $tokens[0][0]
        && is_array($tokens[1])
        && T_OBJECT_OPERATOR === $tokens[1][0]
        && schema_token_is_name($tokens[2])
        && '(' === $tokens[3]
    ) {
        $function = schema_callable_basename($tokens[2][1]);
        $open_index = 3;
    }

    if (null !== $function && null !== $open_index) {
        $closing = schema_matching_parenthesis($tokens, $open_index);
        if ($closing !== count($tokens) - 1) {
            return null;
        }
        $arguments = schema_split_top_level(
            array_slice($tokens, $open_index + 1, $closing - $open_index - 1),
            ','
        );
        if (in_array($function, array('sprintf', 'prepare'), true) && isset($arguments[0])) {
            $format = schema_static_string($arguments[0], $constants);
            if (null === $format) {
                return null;
            }
            $argument_index = 0;
            $rendered = preg_replace_callback(
                '/%%|%(?:(\d+)\$)?[-+0-9.]*[bcdeEfFgGosuxX]/',
                static function (array $match) use ($arguments, $constants, &$argument_index): string {
                    if ('%%' === $match[0]) {
                        return '%';
                    }
                    $position = isset($match[1]) && '' !== $match[1]
                        ? ((int) $match[1])
                        : (++$argument_index);
                    $value = isset($arguments[$position])
                        ? schema_static_string($arguments[$position], $constants)
                        : null;
                    return $value ?? 'x';
                },
                $format
            );
            return is_string($rendered) ? $rendered : null;
        }
        if (in_array($function, array('strtolower', 'strtoupper', 'trim'), true) && isset($arguments[0])) {
            $value = schema_static_string($arguments[0], $constants);
            if (null === $value) {
                return null;
            }
            return match ($function) {
                'strtolower' => strtolower($value),
                'strtoupper' => strtoupper($value),
                default => trim($value),
            };
        }
    }

    return null;
}

function schema_ddl_match(string $value): ?string {
    $pattern = '/^\s*(?:'
        . 'CREATE\s+(?:(?:OR\s+REPLACE|TEMPORARY|UNLOGGED|UNIQUE|FULLTEXT|SPATIAL)\s+)*(?:TABLE|INDEX|DATABASE|SCHEMA|VIEW|TRIGGER|PROCEDURE|FUNCTION)'
        . '|ALTER\s+(?:TABLE|INDEX|DATABASE|SCHEMA|VIEW|COLUMN)'
        . '|DROP\s+(?:TABLE|INDEX|DATABASE|SCHEMA|VIEW|TRIGGER|PROCEDURE|FUNCTION|COLUMN)'
        . '|TRUNCATE(?:\s+TABLE)?'
        . '|RENAME\s+(?:TABLE|INDEX|DATABASE|SCHEMA|COLUMN)'
        . ')\b/i';
    return preg_match($pattern, $value, $match) ? strtoupper($match[0]) : null;
}

/** @param list<mixed> $tokens @return list<mixed> */
function schema_assignment_expression(array $tokens, int $start): array {
    $expression = array();
    $depth = 0;
    for ($index = $start, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];
        if (in_array($token, array('(', '[', '{'), true)) {
            $depth++;
        } elseif (in_array($token, array(')', ']', '}'), true)) {
            $depth--;
        }
        if (0 === $depth && ';' === $token) {
            break;
        }
        $expression[] = $token;
    }
    return $expression;
}

/** @return list<string> */
function schema_forbidden_calls(): array {
    return array(
        'dbdelta',
        'maybe_create_table',
        'maybe_add_column',
        'register_activation_hook',
    );
}

/** @return array<string, int> Maps a query sink to its SQL argument offset. */
function schema_query_sinks(): array {
    return array(
        'query' => 0,
        'exec' => 0,
        'mysql_query' => 0,
        'mysqli_query' => 1,
    );
}

/** @return list<string> */
function schema_mutation_findings(string $source, string $label): array {
    $tokens = token_get_all($source);
    $forbidden_calls = schema_forbidden_calls();
    $query_sinks = schema_query_sinks();
    $constants = array();
    $findings = array();
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token)) {
            continue;
        }
        [$id, $text, $line] = $token;

        if (T_VARIABLE === $id) {
            $next = schema_next_significant($tokens, $index);
            if (null !== $next && '=' === $tokens[$next]) {
                $expression_start = schema_next_significant($tokens, $next);
                $value = null === $expression_start
                    ? null
                    : schema_static_string(
                        schema_assignment_expression($tokens, $expression_start),
                        $constants
                    );
                if (null === $value) {
                    unset($constants[$text]);
                } else {
                    $constants[$text] = $value;
                    $ddl = schema_ddl_match($value);
                    if (null !== $ddl) {
                        $findings[] = sprintf('%s:%d contém DDL de schema (%s)', $label, $line, $ddl);
                    }
                }
            }

            $next = schema_next_significant($tokens, $index);
            if (
                null !== $next
                && '(' === $tokens[$next]
                && isset($constants[$text])
                && in_array(schema_callable_basename($constants[$text]), $forbidden_calls, true)
            ) {
                $findings[] = sprintf('%s:%d chama callback proibido %s()', $label, $line, $constants[$text]);
            }
            continue;
        }

        if (!schema_token_is_name($token)) {
            continue;
        }
        $callable = schema_callable_basename($text);
        $next = schema_next_significant($tokens, $index);
        if (null === $next || '(' !== $tokens[$next]) {
            continue;
        }
        $previous = schema_previous_significant($tokens, $index);
        if (null !== $previous && is_array($tokens[$previous]) && T_FUNCTION === $tokens[$previous][0]) {
            continue;
        }

        $closing = schema_matching_parenthesis($tokens, $next);
        if (null === $closing) {
            continue;
        }
        $arguments = schema_split_top_level(array_slice($tokens, $next + 1, $closing - $next - 1), ',');

        if (in_array($callable, $forbidden_calls, true)) {
            $findings[] = sprintf('%s:%d chama %s()', $label, $line, $text);
            continue;
        }

        if (in_array($callable, array('call_user_func', 'call_user_func_array'), true) && isset($arguments[0])) {
            $callback = schema_static_string($arguments[0], $constants);
            if (null !== $callback && in_array(schema_callable_basename($callback), $forbidden_calls, true)) {
                $findings[] = sprintf('%s:%d chama callback proibido %s()', $label, $line, $callback);
            }
            continue;
        }

        if (array_key_exists($callable, $query_sinks)) {
            $sql_argument = $query_sinks[$callable];
            $query = isset($arguments[$sql_argument])
                ? schema_static_string($arguments[$sql_argument], $constants)
                : null;
            $ddl = null === $query ? null : schema_ddl_match($query);
            if (null !== $ddl) {
                $findings[] = sprintf('%s:%d executa DDL de schema (%s)', $label, $line, $ddl);
            }
        }
    }

    return $findings;
}

/** @param list<string> $managed_roots @return list<string> */
function schema_scan_roots(array $managed_roots, string $label_root): array {
    $findings = array();
    $label_prefix = rtrim($label_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    foreach ($managed_roots as $managed_root) {
        if (!is_dir($managed_root) || is_link($managed_root)) {
            $findings[] = $managed_root . ': raiz gerenciada ausente ou inválida';
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($managed_root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                $findings[] = $file->getPathname() . ': symlink não é código gerenciado regular';
                continue;
            }
            if (!$file->isFile() || 'php' !== strtolower($file->getExtension())) {
                continue;
            }
            $path = $file->getPathname();
            $relative = str_starts_with($path, $label_prefix)
                ? substr($path, strlen($label_prefix))
                : $path;
            $source = file_get_contents($path);
            if (false === $source) {
                $findings[] = $relative . ': não foi possível ler';
                continue;
            }
            array_push($findings, ...schema_mutation_findings($source, $relative));
        }
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

$bad_interpolated_ddl = <<<'PHP'
<?php
$wpdb->query("ALTER TABLE {$table} ADD COLUMN probe int");
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_interpolated_ddl, 'bad-interpolated.php')),
    'DDL em string interpolada é detectado no sink SQL'
);

$bad_prepared_ddl = <<<'PHP'
<?php
$verb = 'ALTER';
$wpdb->query($wpdb->prepare('%s TABLE %i ADD COLUMN probe int', $verb, $table));
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_prepared_ddl, 'bad-prepared.php')),
    'DDL embrulhado em wpdb prepare é detectado no sink SQL'
);

$bad_fully_qualified_call = <<<'PHP'
<?php
\dbDelta($sql);
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_fully_qualified_call, 'bad-qualified-call.php')),
    'chamada global qualificada para dbDelta é detectada'
);

$bad_variable_call = <<<'PHP'
<?php
$callback = 'dbDelta';
$callback($sql);
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_variable_call, 'bad-variable-call.php')),
    'chamada indireta por variável para dbDelta é detectada'
);

$bad_callback_call = <<<'PHP'
<?php
call_user_func('dbDelta', $sql);
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_callback_call, 'bad-callback-call.php')),
    'call_user_func para dbDelta é detectado'
);

$forbidden_call_cases = array(
    'dbDelta' => '<?php dbDelta($sql);',
    'maybe_create_table' => '<?php maybe_create_table($table, $sql);',
    'maybe_add_column' => '<?php maybe_add_column($table, $column, $sql);',
    'register_activation_hook' => "<?php register_activation_hook(__FILE__, 'activate_schema');",
);
foreach ($forbidden_call_cases as $callable => $source) {
    contract_assert(
        1 === count(schema_mutation_findings($source, 'forbidden-' . $callable . '.php')),
        'callback proibido permanece coberto: ' . $callable
    );
}

$query_sink_cases = array(
    'query' => "<?php \$wpdb->query('ALTER TABLE wp_example ADD COLUMN unsafe int');",
    'exec' => "<?php \$pdo->exec('ALTER TABLE wp_example ADD COLUMN unsafe int');",
    'mysql_query' => "<?php mysql_query('ALTER TABLE wp_example ADD COLUMN unsafe int');",
    'mysqli_query' => "<?php mysqli_query(\$connection, 'ALTER TABLE wp_example ADD COLUMN unsafe int');",
);
foreach ($query_sink_cases as $sink => $source) {
    contract_assert(
        1 === count(schema_mutation_findings($source, 'sink-' . $sink . '.php')),
        'sink SQL permanece coberto com o argumento correto: ' . $sink
    );
}

$bad_mid_token_ddl = <<<'PHP'
<?php
$sql = 'CREA' . 'TE TABLE wp_example (id bigint)';
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_mid_token_ddl, 'bad-mid-token.php')),
    'verbo DDL dividido no meio de um token é detectado'
);

$bad_unique_index = <<<'PHP'
<?php
$wpdb->query('CREATE UNIQUE INDEX probe_idx ON wp_example (id)');
PHP;
contract_assert(
    1 === count(schema_mutation_findings($bad_unique_index, 'bad-unique-index.php')),
    'CREATE UNIQUE INDEX é detectado'
);

$benign_unrelated_literals = <<<'PHP'
<?php
$button = 'Please drop';
$help = 'Table of contents';
PHP;
contract_assert(
    array() === schema_mutation_findings($benign_unrelated_literals, 'benign-unrelated.php'),
    'literais não relacionados em comandos distintos não formam DDL artificialmente'
);

$benign_ui_label = <<<'PHP'
<?php
$label = 'Você não pode usar CREATE TABLE aqui';
PHP;
contract_assert(
    array() === schema_mutation_findings($benign_ui_label, 'benign-label.php'),
    'texto de interface que menciona DDL não é confundido com execução'
);

$synthetic_root = sys_get_temp_dir() . '/uonix-schema-roots-' . getmypid() . '-' . bin2hex(random_bytes(4));
$synthetic_mu = $synthetic_root . '/mu-plugins';
$synthetic_theme = $synthetic_root . '/themes/kadence-child';
mkdir($synthetic_mu, 0700, true);
mkdir($synthetic_theme, 0700, true);
file_put_contents($synthetic_mu . '/bad-mu.php', "<?php dbDelta(\$sql);\n");
file_put_contents($synthetic_theme . '/bad-theme.php', "<?php \$wpdb->query('DROP TABLE wp_example');\n");
$synthetic_findings = schema_scan_roots(array($synthetic_mu, $synthetic_theme), $synthetic_root);
contract_assert(
    2 === count($synthetic_findings)
        && str_contains(implode("\n", $synthetic_findings), 'mu-plugins/bad-mu.php')
        && str_contains(implode("\n", $synthetic_findings), 'themes/kadence-child/bad-theme.php'),
    'varredura percorre cada raiz recebida e atribui as duas violações à raiz correta'
);
unlink($synthetic_mu . '/bad-mu.php');
unlink($synthetic_theme . '/bad-theme.php');
rmdir($synthetic_mu);
rmdir($synthetic_theme);
rmdir(dirname($synthetic_theme));
rmdir($synthetic_root);

$managed_roots = array(
    $root . '/mu-plugins',
    $root . '/themes/kadence-child',
);
contract_assert(
    array($root . '/mu-plugins', $root . '/themes/kadence-child') === $managed_roots,
    'varredura real cobre exatamente mu-plugins e o tema filho gerenciado'
);
$repo_findings = schema_scan_roots($managed_roots, $root);

contract_assert(
    array() === $repo_findings,
    "código gerenciado não contém migração automática de schema:\n" . implode("\n", $repo_findings)
);

if (0 !== $failures) {
    fwrite(STDERR, sprintf("FAIL: %d de %d contratos de schema falharam.\n", $failures, $assertions));
    exit(1);
}

printf("PASS: código gerenciado não migra schema implicitamente. (%d asserções)\n", $assertions);
