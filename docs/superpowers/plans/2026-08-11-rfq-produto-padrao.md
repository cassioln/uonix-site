# RFQ habilitado por padrão — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Habilitar RFQ imediatamente em produtos e variações sem configuração física, mostrar o checkbox marcado no editor e preservar qualquer `yes` ou `no` explicitamente salvo.

**Architecture:** Um módulo isolado do MU-plugin `uonix-woocommerce` filtra somente o valor padrão de `_gpls_woo_rfq_rfq_enable` por `default_post_metadata`. O filtro não grava no banco, não altera o RFQ Toolkit e só devolve `yes` para post types `product` e `product_variation` quando a metadata bruta está ausente; o plugin de terceiros continua responsável por materializar `yes` ou `no` ao salvar.

**Tech Stack:** PHP 8.3/8.5, WordPress Metadata API, WooCommerce 10.9.4, NP Quote Request for WooCommerce 2.4.14, GitHub Actions, Podman.

## Global Constraints

- Não modificar WooCommerce nem o RFQ Toolkit.
- Não fazer atualização em massa dos produtos existentes.
- Preservar `no` e `yes` fisicamente salvos.
- Não alterar preços, estoque, atributos, conteúdo ou configurações globais.
- Manter a regra separada do módulo local de preço padrão `0` das variações.
- Usar o hook oficial `default_post_metadata`, com prioridade `10` e `5` argumentos aceitos.
- Limitar o default à chave `_gpls_woo_rfq_rfq_enable` e aos post types `product` e `product_variation`.
- Adicionar todo novo teste como step direto e não tolerante a falha em `.github/workflows/validate.yml`.

---

## File map

- Create: `mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php` — única responsabilidade: resolver o default de RFQ para metadata ausente.
- Modify: `mu-plugins/uonix-woocommerce/module.php` — carregar o novo módulo depois de `22-ficha-tecnica-variacao.php`.
- Create: `scripts/tests/test-rfq-product-default.php` — harness PHP focal que simula a Metadata API, prova valores explícitos e valida o HTML do checkbox.
- Modify: `.github/workflows/validate.yml` — executar o teste focal nas matrizes PHP 8.3 e 8.5.

---

### Task 1: Implementar o default de RFQ com TDD

**Files:**
- Create: `scripts/tests/test-rfq-product-default.php`
- Create: `mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php`
- Modify: `mu-plugins/uonix-woocommerce/module.php:20-24`
- Modify: `.github/workflows/validate.yml:60-70`

**Interfaces:**
- Consumes: filtro WordPress `default_post_metadata( mixed $value, int $object_id, string $meta_key, bool $single, string $meta_type )`.
- Produces: `uonix_rfq_produto_valor_padrao( $value, $object_id, $meta_key, $single, $meta_type ): mixed`.
- Produces: valor `yes` para leitura single e `array( 'yes' )` para leitura múltipla, somente no escopo autorizado.

- [ ] **Step 1: Criar o teste focal antes do código de produção**

Criar `scripts/tests/test-rfq-product-default.php` com este conteúdo completo:

```php
<?php
/**
 * Prova o default de RFQ sem depender de uma instalação WordPress no CI.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['rfq_assertions'] = 0;
$GLOBALS['rfq_filters']    = array();
$GLOBALS['rfq_post_types'] = array(
	101 => 'product',
	102 => 'product_variation',
	103 => 'post',
);
$GLOBALS['rfq_meta']       = array();

function rfq_fail( $message ) {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function rfq_assert_same( $expected, $actual, $message ) {
	$GLOBALS['rfq_assertions']++;

	if ( $expected !== $actual ) {
		rfq_fail(
			$message
			. '; esperado=' . var_export( $expected, true )
			. '; encontrado=' . var_export( $actual, true )
		);
	}
}

function rfq_assert_contains( $needle, $haystack, $message ) {
	$GLOBALS['rfq_assertions']++;

	if ( false === strpos( $haystack, $needle ) ) {
		rfq_fail( $message . '; trecho ausente=' . var_export( $needle, true ) );
	}
}

function rfq_assert_not_contains( $needle, $haystack, $message ) {
	$GLOBALS['rfq_assertions']++;

	if ( false !== strpos( $haystack, $needle ) ) {
		rfq_fail( $message . '; trecho inesperado=' . var_export( $needle, true ) );
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['rfq_filters'][ $hook ][] = array(
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	);
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['rfq_filters'][ $hook ] ?? array() as $registration ) {
		$callback_args = array_slice(
			array_merge( array( $value ), $args ),
			0,
			$registration['accepted_args']
		);
		$value = call_user_func_array( $registration['callback'], $callback_args );
	}

	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_post_type( $post_id ) {
	return $GLOBALS['rfq_post_types'][ absint( $post_id ) ] ?? false;
}

function get_post_meta( $post_id, $meta_key, $single = false ) {
	$post_id = absint( $post_id );

	if ( isset( $GLOBALS['rfq_meta'][ $post_id ] ) && array_key_exists( $meta_key, $GLOBALS['rfq_meta'][ $post_id ] ) ) {
		$value = $GLOBALS['rfq_meta'][ $post_id ][ $meta_key ];
		return $single ? $value : array( $value );
	}

	$default = $single ? '' : array();

	return apply_filters( 'default_post_metadata', $default, $post_id, $meta_key, $single, 'post' );
}

function checked( $checked, $current = true, $display = true ) {
	$result = ( (string) $checked === (string) $current ) ? 'checked="checked"' : '';

	if ( $display ) {
		echo $result;
	}

	return $result;
}

$repo_root   = dirname( __DIR__, 2 );
$module_file = getenv( 'UONIX_TEST_RFQ_MODULE_FILE' );
$module_file = $module_file ?: $repo_root . '/mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php';

if ( ! is_readable( $module_file ) ) {
	rfq_fail( 'módulo RFQ padrão ausente' );
}

require $module_file;

$registrations = $GLOBALS['rfq_filters']['default_post_metadata'] ?? array();
rfq_assert_same( 1, count( $registrations ), 'hook default_post_metadata registrado exatamente uma vez' );
rfq_assert_same( 10, $registrations[0]['priority'], 'prioridade do hook' );
rfq_assert_same( 5, $registrations[0]['accepted_args'], 'quantidade de argumentos do hook' );

$key = '_gpls_woo_rfq_rfq_enable';

rfq_assert_same( 'yes', get_post_meta( 101, $key, true ), 'produto sem meta recebe yes' );
rfq_assert_same( 'yes', get_post_meta( 102, $key, true ), 'variação sem meta recebe yes' );
rfq_assert_same( array( 'yes' ), get_post_meta( 101, $key, false ), 'leitura múltipla mantém lista' );
rfq_assert_same( '', get_post_meta( 103, $key, true ), 'outro post type mantém default original' );
rfq_assert_same( '', get_post_meta( 101, '_outra_chave', true ), 'outra chave mantém default original' );
rfq_assert_same( '', get_post_meta( 0, $key, true ), 'ID inválido mantém default original' );

$GLOBALS['rfq_meta'][101][ $key ] = 'no';
$GLOBALS['rfq_meta'][102][ $key ] = 'yes';

rfq_assert_same( 'no', get_post_meta( 101, $key, true ), 'no físico permanece no' );
rfq_assert_same( 'yes', get_post_meta( 102, $key, true ), 'yes físico permanece yes' );

$product_html = sprintf(
	'<input type="checkbox" name="%1$s" id="%1$s" value="yes" %2$s>',
	$key,
	checked( get_post_meta( 102, $key, true ), 'yes', false )
);
$disabled_html = sprintf(
	'<input type="checkbox" name="%1$s" id="%1$s" value="yes" %2$s>',
	$key,
	checked( get_post_meta( 101, $key, true ), 'yes', false )
);

rfq_assert_contains( 'checked="checked"', $product_html, 'checkbox fica marcado para yes' );
rfq_assert_not_contains( 'checked="checked"', $disabled_html, 'checkbox respeita no explícito' );

$loader = file_get_contents( $repo_root . '/mu-plugins/uonix-woocommerce/module.php' );
rfq_assert_contains( "'23-rfq-produto-padrao.php'", $loader, 'loader inclui o módulo RFQ' );

printf(
	"PASS: RFQ usa yes somente como default ausente e respeita valor explícito (%d asserções).\n",
	$GLOBALS['rfq_assertions']
);
```

- [ ] **Step 2: Executar o teste e confirmar RED**

Run:

```bash
podman run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli \
  php scripts/tests/test-rfq-product-default.php
```

Expected: exit `1` com:

```text
FAIL: módulo RFQ padrão ausente
```

A falha deve ocorrer porque o módulo ainda não existe, não por erro de sintaxe do teste.

- [ ] **Step 3: Criar a implementação mínima**

Criar `mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php`:

```php
<?php
/**
 * WooCommerce: RFQ ativo por padrão sem configuração explícita.
 *
 * A ausência de `_gpls_woo_rfq_rfq_enable` equivale a `yes` para produtos e
 * variações. Valores físicos `yes` e `no` continuam sob controle do RFQ Toolkit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define o valor de RFQ somente quando a metadata física está ausente.
 *
 * @param mixed  $value     Valor padrão atual.
 * @param int    $object_id ID do post.
 * @param string $meta_key  Chave consultada.
 * @param bool   $single    Se a leitura espera um valor único.
 * @param string $meta_type Tipo da metadata.
 * @return mixed
 */
function uonix_rfq_produto_valor_padrao( $value, $object_id, $meta_key, $single, $meta_type ) {
	if ( 'post' !== $meta_type || '_gpls_woo_rfq_rfq_enable' !== $meta_key ) {
		return $value;
	}

	$object_id = absint( $object_id );

	if ( ! $object_id ) {
		return $value;
	}

	$post_type = get_post_type( $object_id );

	if ( ! in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
		return $value;
	}

	return $single ? 'yes' : array( 'yes' );
}

add_filter( 'default_post_metadata', 'uonix_rfq_produto_valor_padrao', 10, 5 );
```

Adicionar ao array de `mu-plugins/uonix-woocommerce/module.php`, depois de `22-ficha-tecnica-variacao.php`:

```php
		'22-ficha-tecnica-variacao.php',
		'23-rfq-produto-padrao.php',
```

Adicionar a `.github/workflows/validate.yml`, no job PHP, depois de `Test WooCommerce-optional callbacks`:

```yaml
      - name: Test RFQ product default
        run: php scripts/tests/test-rfq-product-default.php
```

- [ ] **Step 4: Executar GREEN e os gates focais**

Run:

```bash
podman run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli \
  php scripts/tests/test-rfq-product-default.php
podman run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli \
  php -l mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php
podman run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli \
  php -l scripts/tests/test-rfq-product-default.php
python3 -c 'import yaml' || python3 -m pip install --quiet pyyaml
bash scripts/tests/test-ci-covers-all-tests.sh
actionlint
git diff --check
```

Expected:

```text
PASS: RFQ usa yes somente como default ausente e respeita valor explícito (14 asserções).
No syntax errors detected in mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php
No syntax errors detected in scripts/tests/test-rfq-product-default.php
PASS: <N> testes presentes, todos referenciados e efetivamente executados.
```

`actionlint` e `git diff --check` devem sair com código `0` e sem erros.

- [ ] **Step 5: Provar que o hook é load-bearing**

Aplicar temporariamente esta mutação somente em `23-rfq-produto-padrao.php`:

```diff
-add_filter( 'default_post_metadata', 'uonix_rfq_produto_valor_padrao', 10, 5 );
+add_filter( 'default_post_metadata_broken', 'uonix_rfq_produto_valor_padrao', 10, 5 );
```

Run:

```bash
podman run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli \
  php scripts/tests/test-rfq-product-default.php
```

Expected: exit `1` com:

```text
FAIL: hook default_post_metadata registrado exatamente uma vez
```

Reverter imediatamente a mutação para `default_post_metadata` e repetir:

```bash
podman run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli \
  php scripts/tests/test-rfq-product-default.php
```

Expected: PASS com 14 asserções.

- [ ] **Step 6: Criar o commit funcional**

```bash
git add \
  .github/workflows/validate.yml \
  mu-plugins/uonix-woocommerce/module.php \
  mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php \
  scripts/tests/test-rfq-product-default.php
git diff --cached --check
git commit -m "feat(woocommerce): habilita RFQ por padrao"
```

Expected: commit com exatamente quatro arquivos alterados e nenhuma mudança no RFQ Toolkit.

---

### Task 2: Verificar integração real e fechar os gates

**Files:**
- Verify only: `mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php`
- Verify only: `mu-plugins/uonix-woocommerce/module.php`
- Verify only: `.github/workflows/validate.yml`
- Verify only: `scripts/tests/test-rfq-product-default.php`

**Interfaces:**
- Consumes: banco local `uonix_db`, rede Podman `uonix-local_default`, volume `uonix-site_wordpress_core` e plugins locais existentes.
- Produces: evidência de produto temporário sem meta → `yes`/checkbox marcado; `no` salvo → checkbox desmarcado; fixture removida ao final.

- [ ] **Step 1: Rodar o contrato focal em PHP 8.3 e 8.5**

```bash
for version in 8.3 8.5; do
  podman run --rm \
    -v "$PWD:/app:ro" \
    -w /app \
    "php:${version}-cli" \
    php scripts/tests/test-rfq-product-default.php
done
```

Expected: as duas versões imprimem PASS com 14 asserções.

- [ ] **Step 2: Exercitar WordPress, WooCommerce e o HTML real do helper**

Executar um WP-CLI efêmero. Ele usa o banco local existente, monta os plugins locais de terceiros e sobrepõe apenas os MU-plugins/tema com esta worktree; não copia arquivos sobre outra branch.

```bash
ROOT="$PWD"
LOCAL_CONTENT=/Users/cassio/GitHubPessoal/uonix-site/local/wp-content

podman run --rm \
  --network uonix-local_default \
  -e WORDPRESS_DB_HOST=db \
  -e WORDPRESS_DB_USER=uonix_user \
  -e WORDPRESS_DB_PASSWORD=uonix_pass \
  -e WORDPRESS_DB_NAME=uonix_db \
  -e WORDPRESS_TABLE_PREFIX=wpis_ \
  -v uonix-site_wordpress_core:/var/www/html:z \
  -v "$LOCAL_CONTENT:/var/www/html/wp-content:z" \
  -v "$ROOT/mu-plugins:/var/www/html/wp-content/mu-plugins:ro,z" \
  -v "$ROOT/themes/kadence-child:/var/www/html/wp-content/themes/kadence-child:ro,z" \
  --entrypoint php \
  wordpress:cli-php8.2 \
  -d memory_limit=512M \
  /usr/local/bin/wp --allow-root --path=/var/www/html eval '
$key = "_gpls_woo_rfq_rfq_enable";
$old_post = $GLOBALS["post"] ?? null;
$product_id = wp_insert_post(array(
    "post_type" => "product",
    "post_status" => "draft",
    "post_title" => "uonix-rfq-default-integration",
));
if (is_wp_error($product_id) || !$product_id) {
    throw new RuntimeException("não foi possível criar produto temporário");
}
try {
    if (metadata_exists("post", $product_id, $key)) {
        throw new RuntimeException("fixture nasceu com meta física inesperada");
    }
    if ("yes" !== get_post_meta($product_id, $key, true)) {
        throw new RuntimeException("produto sem meta não recebeu default yes");
    }
    if (!function_exists("woocommerce_wp_checkbox")) {
        require_once WC_ABSPATH . "includes/admin/wc-meta-box-functions.php";
    }
    $GLOBALS["post"] = get_post($product_id);
    ob_start();
    woocommerce_wp_checkbox(array(
        "id" => $key,
        "label" => "Enable RFQ for this product.",
    ));
    $enabled_html = ob_get_clean();
    if (!str_contains($enabled_html, "checked=\"checked\"")) {
        throw new RuntimeException("checkbox não ficou marcado para default yes");
    }
    update_post_meta($product_id, $key, "no");
    if ("no" !== get_post_meta($product_id, $key, true)) {
        throw new RuntimeException("no explícito não foi preservado");
    }
    ob_start();
    woocommerce_wp_checkbox(array(
        "id" => $key,
        "label" => "Enable RFQ for this product.",
    ));
    $disabled_html = ob_get_clean();
    if (str_contains($disabled_html, "checked=\"checked\"")) {
        throw new RuntimeException("checkbox ignorou no explícito");
    }
    echo "PASS: integração real RFQ default yes e no explícito.\n";
} finally {
    wp_delete_post($product_id, true);
    $GLOBALS["post"] = $old_post;
}
'
```

Expected:

```text
PASS: integração real RFQ default yes e no explícito.
```

A execução deve terminar com código `0`; o `finally` remove o produto temporário inclusive após falha.

- [ ] **Step 3: Rodar os gates finais do repositório**

```bash
podman run --rm -v "$PWD:/app:ro" -w /app php:8.3-cli \
  php scripts/tests/test-rfq-product-default.php
bash scripts/tests/test-ci-covers-all-tests.sh
actionlint
git diff --check
git status --short --branch
```

Expected:

- teste RFQ PASS com 14 asserções;
- auditor de CI PASS;
- `actionlint` e `git diff --check` com exit `0`;
- árvore sem arquivos modificados após o commit funcional;
- branch à frente de `origin/master` somente pelos commits da especificação, do plano, da adaptação do plano ao PHP em container e da implementação.

- [ ] **Step 4: Confirmar escopo do histórico antes do push**

```bash
git log --oneline origin/master..HEAD
git diff --stat origin/master...HEAD
git diff --check origin/master...HEAD
```

Expected:

- commit da especificação `docs(woocommerce): especifica RFQ habilitado por padrao`;
- commit do plano `docs(woocommerce): planeja RFQ habilitado por padrao`;
- commit da adaptação `docs(woocommerce): usa PHP em container no plano RFQ`;
- commit funcional `feat(woocommerce): habilita RFQ por padrao`;
- somente especificação, plano, módulo RFQ, loader, teste focal e workflow de validação no diff;
- nenhum arquivo de plugin de terceiros e nenhum arquivo de banco.
