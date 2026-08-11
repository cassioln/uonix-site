# Ficha Técnica por Variação no WooCommerce — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar um editor estruturado de ficha técnica dentro de cada variação, mantendo a descrição livre, reproduzindo o padrão visual atual e migrando com rollback as cinco fichas legadas.

**Architecture:** Um bootstrap numerado carrega quatro classes focadas: esquema/validação, renderer/frontend, editor administrativo e comando de migração. A ficha é armazenada como array sanitizado em meta próprio; o admin submete um envelope JSON por variação; o frontend anexa HTML escapado ao payload nativo `woocommerce_available_variation`, sem override de template.

**Tech Stack:** PHP 8.3/8.5 no CI, WordPress MU-plugins, WooCommerce 11.0.0, JavaScript ES2020 com jQuery/jQuery UI já presentes no admin, CSS Grid, WP-CLI, Podman Compose e testes PHP de contrato sem PHPUnit.

## Global Constraints

- Não editar arquivos, HTML interno ou templates do WooCommerce.
- Não adicionar ACF, biblioteca JavaScript, plugin ou dependência comercial.
- Manter `_variation_description` como descrição livre; a ficha usa `_uonix_variation_technical_sheet`.
- Abranger todos os usuários com capacidade nativa de editar o produto; nunca testar o nome do perfil `administrator`.
- Não apagar meta quando o campo próprio estiver ausente; CSV, REST, WP-CLI e integrações precisam permanecer seguros.
- Aceitar somente texto simples; remover tags na entrada e aplicar `esc_html()` na saída.
- Usar valores oficiais dos atributos; aliases: `pa_tipo → Modelo`, `pa_material → Material`, `pa_bitola → Pol.`.
- Manter as cores administrativas aprovadas: `#2c3338`, `#50575e`, `#8c8f94`, `#f0f2f4`.
- Não reutilizar classes legadas `.uonix-ficha-*`; usar prefixo novo `.uonix-vts*`.
- Não impor limite visual de negócio; aplicar os tetos técnicos exatos da especificação.
- A regra de preço inicial `0` fica em alteração e PR separados; esta branch não deve conter o arquivo `21-*` nem seu hunk no loader.
- Migração é sempre WP-CLI, dry-run por padrão, com backup, hashes, rollback fail-closed e nenhuma execução automática ao carregar página.
- Nunca usar bypass de CI ou `gh pr merge --admin`.
- Especificação normativa: `docs/superpowers/specs/2026-08-10-ficha-tecnica-variacoes-woocommerce-design.md`.

## File Map

| Arquivo | Responsabilidade |
|---|---|
| `mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php` | Bootstrap fail-safe e registro das classes disponíveis |
| `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-schema.php` | Constantes, envelope JSON, sanitização e esquema v1 |
| `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-renderer.php` | Atributos oficiais, HTML escapado, payload e CSS frontend |
| `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-admin.php` | Markup admin, persistência, assets, opções de cópia e AJAX |
| `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-migration-command.php` | Parser legado e comandos dry-run/execute/rollback |
| `mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js` | Estado do repeater, seções/itens, sortables e cópia |
| `mu-plugins/uonix-woocommerce/assets/css/admin-ficha-tecnica-variacao.css` | Layout e cores do editor administrativo |
| `mu-plugins/uonix-woocommerce/assets/css/ficha-tecnica-variacao.css` | Cartão responsivo do frontend |
| `scripts/tests/test-variation-technical-sheet.php` | Contratos PHP executados no CI |
| `scripts/verify-variation-technical-sheet.php` | Prova real no WordPress/WooCommerce local e limpeza de fixtures |
| `mu-plugins/uonix-woocommerce/module.php` | Inclusão do bootstrap `22-*` |
| `.github/workflows/validate.yml` | PHP contract test e `node --check` |

## Public Interfaces (fixed for all tasks)

```php
final class Uonix_VTS_Schema {
    public const META_KEY = '_uonix_variation_technical_sheet';
    public const BACKUP_META_KEY = '_uonix_variation_technical_sheet_legacy_backup_v1';
    public static function normalize_envelope( $raw );
    public static function normalize_sheet( $sheet );
}

final class Uonix_VTS_Renderer {
    public static function register_hooks();
    public static function attribute_pairs( $variation );
    public static function render( array $sheet, $variation );
    public static function append_to_variation_data( $data, $parent, $variation );
    public static function enqueue_frontend_assets();
}

final class Uonix_VTS_Admin {
    public static function register_hooks();
    public static function render_editor( $loop, $variation_data, $variation_post );
    public static function save_variation( $variation, $loop );
    public static function enqueue_assets( $hook_suffix );
    public static function copy_options( $parent_id );
    public static function get_copy_sheet( $source_id, $parent_id );
    public static function ajax_get_copy_sheet();
}

final class Uonix_VTS_Migration_Command {
    public static function register();
    public static function parse_legacy_description( $description );
    public function migrate( $args, $assoc_args );
}
```

Every result returned by schema/parser helpers uses this shape:

```php
array(
    'ok'      => true|false,
    'code'    => null|'machine_readable_code',
    'message' => null|'Mensagem em português',
    'action'  => null|'upsert'|'delete',
    'sheet'   => null|array(),
);
```

---

### Task 1: Contract Schema, Bootstrap and CI Registration

**Files:**
- Create: `mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php`
- Create: `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-schema.php`
- Create: `scripts/tests/test-variation-technical-sheet.php`
- Modify: `mu-plugins/uonix-woocommerce/module.php:20-23`
- Modify: `.github/workflows/validate.yml:63-68`

**Interfaces:**
- Consumes: `uonix_mu_require_files()` from `mu-plugins/uonix-core.php`.
- Produces: `Uonix_VTS_Schema`, constants and normalized result shape used by every later task.

- [ ] **Step 1: Isolate the feature from the uncommitted price change**

Execute no repositório principal, depois que este plano estiver commitado:

```bash
PLAN_BASE="$(git rev-parse HEAD)"
git show "$PLAN_BASE:docs/superpowers/plans/2026-08-10-ficha-tecnica-variacoes-woocommerce.md" >/dev/null
git worktree add .worktrees/ficha-tecnica-variacoes \
  -b feat/ficha-tecnica-variacoes \
  "$PLAN_BASE"
cd .worktrees/ficha-tecnica-variacoes
git status --short
```

Esperado: status vazio e plano presente no `HEAD`. Confirme que nem `21-admin-variacao-preco-padrao.php` nem seu hunk no loader existem neste worktree.

- [ ] **Step 2: Write the first failing contract test**

Create the test harness with `ABSPATH`, WordPress stubs, assertion helpers and these cases:

```php
$valid = Uonix_VTS_Schema::normalize_envelope(
    wp_json_encode(
        array(
            'action' => 'upsert',
            'sheet'  => array(
                'version'  => 1,
                'title'    => 'Dimensões (mm)',
                'sections' => array(
                    array(
                        'title'  => '',
                        'layout' => 'compact',
                        'items'  => array(
                            array( 'label' => 'A', 'value' => '37' ),
                        ),
                    ),
                ),
            ),
        )
    )
);

vts_assert_same( true, $valid['ok'], 'envelope upsert válido' );
vts_assert_same( '37', $valid['sheet']['sections'][0]['items'][0]['value'], 'valor preservado' );

$delete = Uonix_VTS_Schema::normalize_envelope( '{"action":"delete"}' );
vts_assert_same( true, $delete['ok'], 'delete explícito aceito' );
vts_assert_same( 'delete', $delete['action'], 'ação delete preservada' );

$invalid = Uonix_VTS_Schema::normalize_envelope( '' );
vts_assert_same( false, $invalid['ok'], 'JSON vazio recusado' );

$partial = Uonix_VTS_Schema::normalize_envelope(
    '{"action":"upsert","sheet":{"version":1,"title":"Ficha","sections":[{"title":"","layout":"compact","items":[{"label":"A","value":""}]}]}}'
);
vts_assert_same( false, $partial['ok'], 'item parcial recusa a ficha inteira' );
```

The stubs must implement `wp_json_encode()`, `wp_strip_all_tags()`, `sanitize_text_field()` and `wp_check_invalid_utf8()` without weakening assertions.

- [ ] **Step 3: Run the test and prove RED**

Run:

```bash
php scripts/tests/test-variation-technical-sheet.php
```

Expected: non-zero with `Class "Uonix_VTS_Schema" not found` or missing required class file.

- [ ] **Step 4: Implement the schema class**

Use these constants exactly:

```php
public const VERSION             = 1;
public const MAX_PAYLOAD_BYTES   = 262144;
public const MAX_SECTIONS        = 50;
public const MAX_ITEMS           = 100;
public const MAX_TITLE_CHARS     = 160;
public const MAX_SECTION_CHARS   = 120;
public const MAX_LABEL_CHARS     = 120;
public const MAX_VALUE_CHARS     = 500;
public const META_KEY            = '_uonix_variation_technical_sheet';
public const BACKUP_META_KEY     = '_uonix_variation_technical_sheet_legacy_backup_v1';
```

`normalize_envelope()` must execute in this order:

```php
if ( ! is_string( $raw ) || '' === $raw ) {
    return self::failure( 'empty_json', 'A ficha técnica recebida está vazia.' );
}
if ( strlen( $raw ) > self::MAX_PAYLOAD_BYTES ) {
    return self::failure( 'payload_too_large', 'A ficha técnica excede o limite de segurança.' );
}
$decoded = json_decode( $raw, true );
if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
    return self::failure( 'invalid_json', 'A ficha técnica contém JSON inválido.' );
}
$action = isset( $decoded['action'] ) ? (string) $decoded['action'] : '';
if ( 'delete' === $action ) {
    return self::success( 'delete', null );
}
if ( 'upsert' !== $action || ! isset( $decoded['sheet'] ) ) {
    return self::failure( 'invalid_action', 'A ação da ficha técnica é inválida.' );
}
$result = self::normalize_sheet( $decoded['sheet'] );
if ( ! $result['ok'] ) {
    return $result;
}
return self::success( 'upsert', $result['sheet'] );
```

`normalize_sheet()` must reject wrong version, missing title, more than 50 sections, more than 100 items, unknown layouts, strings beyond their exact caps, partial items and an upsert with zero valid sections. It must drop fully empty items and sections. Sanitize each accepted string by `wp_strip_all_tags( $value, true )`, remove control characters with `/[\x00-\x1F\x7F]/u`, then call `sanitize_text_field()`; never truncate.

- [ ] **Step 5: Add the fail-safe bootstrap and loader entry**

Bootstrap content must be structurally equivalent to:

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

uonix_mu_require_files(
    __DIR__ . '/ficha-tecnica-variacao',
    array( 'class-uonix-vts-schema.php' ),
    'uonix-woocommerce/ficha-tecnica-variacao'
);
```

Add only `'22-ficha-tecnica-variacao.php'` after `20-catalogo-titulos-produtos.php` in this feature worktree.

- [ ] **Step 6: Make the test load the real schema and verify GREEN**

No topo do teste, após os stubs, carregue o bootstrap real com um stub fiel do helper:

```php
function uonix_mu_require_files( $base_dir, $files, $scope ) {
    foreach ( $files as $file ) {
        require_once rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR . $file;
    }
}

$repo_root = dirname( __DIR__, 2 );
require_once $repo_root . '/mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php';

$module_source = file_get_contents( $repo_root . '/mu-plugins/uonix-woocommerce/module.php' );
vts_assert_contains( "'22-ficha-tecnica-variacao.php'", $module_source, 'loader registra o bootstrap 22' );
```

Run:

```bash
php scripts/tests/test-variation-technical-sheet.php
```

Expected final line: `PASS: contratos da ficha técnica por variação.`

- [ ] **Step 7: Register the test directly in CI**

Add in the PHP job:

```yaml
      - name: Test variation technical sheet
        run: php scripts/tests/test-variation-technical-sheet.php
```

Run:

```bash
bash scripts/tests/test-ci-covers-all-tests.sh
php -l mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php
php -l mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-schema.php
git diff --check
```

Expected: all exit 0.

- [ ] **Step 8: Commit Task 1**

```bash
git add .github/workflows/validate.yml \
  mu-plugins/uonix-woocommerce/module.php \
  mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php \
  mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-schema.php \
  scripts/tests/test-variation-technical-sheet.php
git commit -m "feat(woocommerce): define esquema da ficha por variacao"
```

---

### Task 2: Escaped Renderer, Official Attributes and Frontend CSS

**Files:**
- Create: `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-renderer.php`
- Create: `mu-plugins/uonix-woocommerce/assets/css/ficha-tecnica-variacao.css`
- Modify: `mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php`
- Modify: `scripts/tests/test-variation-technical-sheet.php`

**Interfaces:**
- Consumes: normalized v1 arrays and `Uonix_VTS_Schema::META_KEY`.
- Produces: `Uonix_VTS_Renderer::render()`, official attribute pairs and the `woocommerce_available_variation` filter.

- [ ] **Step 1: Add failing renderer and hook tests**

Stub `add_filter()`, `add_action()`, `esc_html()`, `wc_attribute_label()`, `taxonomy_exists()`, `get_term_by()`, `is_wp_error()`, `is_product()`, `wp_enqueue_style()` and a variation fixture exposing `get_attributes()`, `get_meta()` and `get_id()`.

Assert:

```php
$pairs = Uonix_VTS_Renderer::attribute_pairs( $variation );
vts_assert_same(
    array(
        array( 'label' => 'Modelo', 'value' => 'Pesado' ),
        array( 'label' => 'Material', 'value' => 'Inox 316' ),
        array( 'label' => 'Pol.', 'value' => '5/16"' ),
    ),
    $pairs,
    'subtítulo usa aliases e valores oficiais'
);

$html = Uonix_VTS_Renderer::render( $valid['sheet'], $variation );
vts_assert_contains( 'class="uonix-vts"', $html, 'wrapper novo presente' );
vts_assert_contains( 'Dimensões (mm)', $html, 'título renderizado' );
vts_assert_contains( 'Modelo: Pesado · Material: Inox 316 · Pol.: 5/16&quot;', $html, 'subtítulo oficial' );
vts_assert_not_contains( '<script>', Uonix_VTS_Renderer::render( $xss_sheet, $variation ), 'script nunca executável' );

$data = Uonix_VTS_Renderer::append_to_variation_data(
    array( 'variation_description' => '<p>Descrição livre</p>' ),
    null,
    $variation
);
vts_assert_contains( '<p>Descrição livre</p>', $data['variation_description'], 'descrição livre preservada' );
vts_assert_true(
    strpos( $data['variation_description'], '<p>Descrição livre</p>' ) < strpos( $data['variation_description'], 'uonix-vts' ),
    'ficha anexada depois da descrição'
);
```

Also assert the filter is registered with accepted args `3` and `wp_enqueue_scripts` with accepted args `0`.

- [ ] **Step 2: Run RED**

```bash
php scripts/tests/test-variation-technical-sheet.php
```

Expected: failure because `Uonix_VTS_Renderer` does not exist.

- [ ] **Step 3: Implement renderer behavior**

`attribute_pairs()` must read `$variation->get_attributes()`, resolve taxonomy slugs to official term names and order known names as:

```php
$aliases = array(
    'pa_tipo'     => 'Modelo',
    'pa_material' => 'Material',
    'pa_bitola'   => 'Pol.',
);
```

Append remaining attributes after those three using `wc_attribute_label()`. Skip empty values.

`render()` must emit this hierarchy, with every dynamic string passed through `esc_html()`:

```html
<div class="uonix-vts">
  <div class="uonix-vts__card">
    <div class="uonix-vts__header">
      <strong class="uonix-vts__title">Dimensões (mm)</strong>
      <span class="uonix-vts__subtitle">Modelo: Pesado · Material: Inox 316 · Pol.: 5/16&quot;</span>
    </div>
    <section class="uonix-vts__section uonix-vts__section--compact">
      <div class="uonix-vts__grid uonix-vts__grid--compact">
        <div class="uonix-vts__item"><strong>A</strong><span>37</span></div>
      </div>
    </section>
  </div>
</div>
```

Omit `.uonix-vts__section-title` entirely when section title is empty. Return `''` when sheet normalization fails or has no valid section.

`append_to_variation_data()` must normalize stored meta defensively and leave `$data` byte-for-byte unchanged when no valid sheet exists.

- [ ] **Step 4: Add the responsive CSS**

The complete structural rules must use:

```css
.uonix-vts { width: 100%; margin: 14px 0; }
.uonix-vts__card { overflow: hidden; background: #fff; border: 1px solid #dbe3ef; border-radius: 8px; box-shadow: 0 1px 5px rgba(15, 23, 42, .035); }
.uonix-vts__header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 12px; color: #1e293b; background: #f1f5f9; border-bottom: 1px solid #dbe3ef; }
.uonix-vts__title { font-size: 16px; font-weight: 800; line-height: 1.15; white-space: nowrap; }
.uonix-vts__subtitle { color: #64748b; font-size: 12px; font-weight: 700; line-height: 1.15; text-align: right; }
.uonix-vts__section-title { padding: 7px 10px; color: #64748b; background: #f8fafc; border-bottom: 1px solid #dbe3ef; font-size: 11px; font-weight: 800; text-transform: uppercase; }
.uonix-vts__grid { display: grid; gap: 1px; background: #dbe3ef; }
.uonix-vts__grid--compact { grid-template-columns: repeat(auto-fit, minmax(68px, 1fr)); }
.uonix-vts__grid--detailed { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
.uonix-vts__item { min-width: 0; padding: 8px 10px; background: #fff; }
.uonix-vts__grid--compact .uonix-vts__item { padding: 8px 4px; text-align: center; }
.uonix-vts__item strong, .uonix-vts__item span { display: block; }
.uonix-vts__item strong { margin-bottom: 3px; color: #64748b; font-size: 11px; font-weight: 800; line-height: 1.05; }
.uonix-vts__item span { color: #0f172a; font-size: 15px; font-weight: 800; line-height: 1.05; overflow-wrap: anywhere; }
.uonix-vts__grid--compact .uonix-vts__item span { font-size: 17px; }
@media (max-width: 600px) {
  .uonix-vts__header { align-items: flex-start; flex-direction: column; gap: 3px; padding: 7px 10px; }
  .uonix-vts__title { font-size: 15px; white-space: normal; }
  .uonix-vts__subtitle { font-size: 11px; text-align: left; }
  .uonix-vts__grid--detailed { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); }
}
```

- [ ] **Step 5: Register renderer and frontend asset**

Append renderer class to the bootstrap require list, then call:

```php
if ( class_exists( 'Uonix_VTS_Renderer' ) ) {
    Uonix_VTS_Renderer::register_hooks();
}
```

`enqueue_frontend_assets()` must return unless `function_exists( 'is_product' ) && is_product()`, then enqueue the CSS using `UONIX_MU_PATH`, `UONIX_MU_URL` and `filemtime()`.

- [ ] **Step 6: Run GREEN and commit**

```bash
php scripts/tests/test-variation-technical-sheet.php
php -l mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-renderer.php
git diff --check
git add mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php \
  mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-renderer.php \
  mu-plugins/uonix-woocommerce/assets/css/ficha-tecnica-variacao.css \
  scripts/tests/test-variation-technical-sheet.php
git commit -m "feat(woocommerce): renderiza ficha estruturada da variacao"
```

---

### Task 3: Admin Markup and Fail-Closed Persistence

**Files:**
- Create: `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-admin.php`
- Modify: `mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php`
- Modify: `scripts/tests/test-variation-technical-sheet.php`

**Interfaces:**
- Consumes: schema envelope and `WC_Product_Variation` CRUD meta methods.
- Produces: server-rendered editor shell and `woocommerce_admin_process_variation_object` persistence.

- [ ] **Step 1: Add failing hook, rendering and save tests**

Record action registrations and assert:

```php
vts_assert_hook( 'woocommerce_product_after_variable_attributes', array( 'Uonix_VTS_Admin', 'render_editor' ), 3 );
vts_assert_hook( 'woocommerce_admin_process_variation_object', array( 'Uonix_VTS_Admin', 'save_variation' ), 2 );
```

Create a fake variation object with `get_id()`, `get_parent_id()`, `get_meta()`, `update_meta_data()` and `delete_meta_data()`. Cover:

```php
$_POST = array();
Uonix_VTS_Admin::save_variation( $variation, 0 );
vts_assert_same( $before, $variation->meta, 'campo ausente não altera meta' );

$_POST['uonix_variation_technical_sheet'][0] = wp_json_encode(
    array( 'action' => 'upsert', 'sheet' => $sheet )
);
Uonix_VTS_Admin::save_variation( $variation, 0 );
vts_assert_same( '37', $variation->meta[ Uonix_VTS_Schema::META_KEY ]['sections'][0]['items'][0]['value'], 'upsert persiste array sanitizado' );

$_POST['uonix_variation_technical_sheet'][0] = '{invalid';
Uonix_VTS_Admin::save_variation( $variation, 0 );
vts_assert_same( $saved, $variation->meta, 'JSON inválido preserva meta anterior' );
vts_assert_contains( 'não foi salva', end( $GLOBALS['wc_meta_box_errors'] ), 'erro administrativo visível' );

$_POST['uonix_variation_technical_sheet'][0] = '{"action":"delete"}';
Uonix_VTS_Admin::save_variation( $variation, 0 );
vts_assert_false( isset( $variation->meta[ Uonix_VTS_Schema::META_KEY ] ), 'delete explícito remove meta' );
```

Also render one existing and one empty variation, asserting unique loop-indexed input names and no duplicate HTML IDs. Set capability to false and assert unauthorized rendering produces an empty buffer.

- [ ] **Step 2: Run RED**

```bash
php scripts/tests/test-variation-technical-sheet.php
```

Expected: failure because admin class/hooks are absent.

- [ ] **Step 3: Implement registration and persistence**

`register_hooks()` must contain only:

```php
add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_editor' ), 10, 3 );
add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
```

`save_variation()` algorithm:

```php
if ( ! is_object( $variation ) || ! method_exists( $variation, 'get_parent_id' ) ) {
    return;
}
if ( ! isset( $_POST['uonix_variation_technical_sheet'] ) || ! is_array( $_POST['uonix_variation_technical_sheet'] ) ) {
    return;
}
if ( ! array_key_exists( $loop, $_POST['uonix_variation_technical_sheet'] ) ) {
    return;
}
$parent_id = absint( $variation->get_parent_id() );
if ( ! $parent_id || ! current_user_can( 'edit_post', $parent_id ) ) {
    return;
}
$raw    = wp_unslash( $_POST['uonix_variation_technical_sheet'][ $loop ] );
$result = Uonix_VTS_Schema::normalize_envelope( $raw );
if ( ! $result['ok'] ) {
    WC_Admin_Meta_Boxes::add_error( 'A ficha técnica da variação #' . $variation->get_id() . ' não foi salva: ' . $result['message'] );
    return;
}
if ( 'delete' === $result['action'] ) {
    $variation->delete_meta_data( Uonix_VTS_Schema::META_KEY );
    return;
}
$variation->update_meta_data( Uonix_VTS_Schema::META_KEY, $result['sheet'] );
```

Do not call `$variation->save()` inside this hook; WooCommerce saves it immediately afterward.

- [ ] **Step 4: Implement accessible server markup**

`render_editor()` deve resolver o `WP_Post` com `wc_get_product( $variation_post->ID )`, obter o parent ID e retornar sem markup quando `current_user_can( 'edit_post', $parent_id )` for falso. Depois, deve normalizar o meta defensivamente e produzir:

```html
<div class="uonix-vts-admin is-active" data-had-sheet="1" data-variation-id="10410">
  <input type="hidden" class="uonix-vts-admin__payload" name="uonix_variation_technical_sheet[2]" value="{&quot;action&quot;:&quot;upsert&quot;,&quot;sheet&quot;:{&quot;version&quot;:1,&quot;title&quot;:&quot;Ficha técnica&quot;,&quot;sections&quot;:[{&quot;title&quot;:&quot;&quot;,&quot;layout&quot;:&quot;compact&quot;,&quot;items&quot;:[{&quot;label&quot;:&quot;A&quot;,&quot;value&quot;:&quot;37&quot;}]}]}}">
  <button type="button" class="button uonix-vts-admin__add">Adicionar ficha técnica</button>
  <div class="uonix-vts-admin__editor">
    <label>Título geral<input type="text" class="uonix-vts-admin__sheet-title" aria-label="Título geral da ficha técnica"></label>
    <label>Cabeçalho automático<input type="text" class="uonix-vts-admin__subtitle" aria-label="Cabeçalho automático da variação" readonly></label>
    <div class="uonix-vts-admin__sections"></div>
    <button type="button" class="button uonix-vts-admin__add-section">Adicionar seção</button>
    <button type="button" class="button-link-delete uonix-vts-admin__remove-sheet">Remover ficha</button>
  </div>
  <template class="uonix-vts-admin__section-template">
    <section class="uonix-vts-admin__section">
      <div class="uonix-vts-admin__section-head">
        <button type="button" class="button-link uonix-vts-admin__section-handle" aria-label="Reordenar seção">↕</button>
        <input type="text" class="uonix-vts-admin__section-title" aria-label="Título opcional da seção" placeholder="Título opcional">
        <select class="uonix-vts-admin__section-layout" aria-label="Formato da seção">
          <option value="compact">Compacta</option>
          <option value="detailed">Detalhada</option>
        </select>
        <button type="button" class="button-link uonix-vts-admin__remove-section" aria-label="Remover seção">×</button>
      </div>
      <div class="uonix-vts-admin__items"></div>
      <button type="button" class="button uonix-vts-admin__add-item">Adicionar item</button>
    </section>
  </template>
  <template class="uonix-vts-admin__item-template">
    <div class="uonix-vts-admin__item">
      <button type="button" class="button-link uonix-vts-admin__item-handle" aria-label="Reordenar item">↕</button>
      <input type="text" class="uonix-vts-admin__item-label" aria-label="Rótulo do item" placeholder="Rótulo">
      <input type="text" class="uonix-vts-admin__item-value" aria-label="Valor do item" placeholder="Valor">
      <button type="button" class="button-link uonix-vts-admin__remove-item" aria-label="Remover item">×</button>
    </div>
  </template>
</div>
```

O PHP deve usar `esc_attr( wp_json_encode( $envelope ) )` no valor real do campo oculto. Botões precisam manter `type="button"`, drag handles precisam manter `aria-label`, campos precisam manter `aria-label`, e os templates usam classes em vez de IDs. O hidden payload fica desabilitado quando a variação nunca teve ficha e o editor está inativo.

O valor do campo readonly deve ser montado no PHP a partir de `Uonix_VTS_Renderer::attribute_pairs( $variation )`, concatenando cada par como `Rótulo: Valor` com ` · `. O teste deve confirmar `Modelo: Pesado · Material: Inox 316 · Pol.: 5/16&quot;` no HTML administrativo.

- [ ] **Step 5: Register the class, run GREEN and commit**

Add admin class to the bootstrap require list and register only when `class_exists()`.

```bash
php scripts/tests/test-variation-technical-sheet.php
php -l mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-admin.php
git diff --check
git add mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php \
  mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-admin.php \
  scripts/tests/test-variation-technical-sheet.php
git commit -m "feat(woocommerce): salva ficha no editor de variacoes"
```

---

### Task 4: Dynamic Inline Editor JavaScript and Admin CSS

**Files:**
- Create: `mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js`
- Create: `mu-plugins/uonix-woocommerce/assets/css/admin-ficha-tecnica-variacao.css`
- Modify: `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-admin.php`
- Modify: `.github/workflows/validate.yml:217-224`
- Modify: `scripts/tests/test-variation-technical-sheet.php`

**Interfaces:**
- Consumes: markup and envelope from Task 3.
- Produces: add/remove/reorder/serialize behavior across AJAX-loaded variation pages.

- [ ] **Step 1: Add failing asset/enqueue contracts**

Assert admin enqueue is registered with one accepted argument and is no-op outside `post.php|post-new.php` / `product`. Assert localized data includes `parentId` and core strings, under the fixed JavaScript object name `uonixVtsAdmin`.

Run before implementation:

```bash
php scripts/tests/test-variation-technical-sheet.php
node --check mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js
```

Expected: PHP failure and Node missing-file failure.

- [ ] **Step 2: Implement admin asset scoping**

Add hook:

```php
add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 10, 1 );
```

`enqueue_assets( $hook_suffix )` must return unless:

```php
in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true )
&& get_current_screen()
&& 'product' === get_current_screen()->post_type
```

Enqueue the two files with `filemtime()` and dependencies `array( 'jquery', 'jquery-ui-sortable' )` for JavaScript. Localize the script as `uonixVtsAdmin`; Task 5 will extend this same object with AJAX/copy data.

- [ ] **Step 3: Implement deterministic editor state**

The JavaScript must be one strict IIFE and expose no globals. Use these functions and responsibilities:

```javascript
(function ($) {
  'use strict';

  const config = window.uonixVtsAdmin || {};

  function collectSheet($editor) {
    const sections = [];
    $editor.find('.uonix-vts-admin__section').each(function () {
      const $section = $(this);
      const items = [];
      $section.find('.uonix-vts-admin__item').each(function () {
        const $item = $(this);
        items.push({
          label: String($item.find('.uonix-vts-admin__item-label').val() || ''),
          value: String($item.find('.uonix-vts-admin__item-value').val() || '')
        });
      });
      sections.push({
        title: String($section.find('.uonix-vts-admin__section-title').val() || ''),
        layout: String($section.find('.uonix-vts-admin__section-layout').val() || 'compact'),
        items: items
      });
    });
    return {
      version: 1,
      title: String($editor.find('.uonix-vts-admin__sheet-title').val() || ''),
      sections: sections
    };
  }

  function sync($root) {
    const $payload = $root.find('.uonix-vts-admin__payload');
    if ($root.hasClass('is-deleted')) {
      $payload.prop('disabled', false).val(JSON.stringify({ action: 'delete' }));
      return;
    }
    if (!$root.hasClass('is-active')) {
      $payload.prop('disabled', true).val('');
      return;
    }
    $payload.prop('disabled', false).val(JSON.stringify({
      action: 'upsert',
      sheet: collectSheet($root)
    }));
  }

  function initSortable($root) {
    $root.find('.uonix-vts-admin__sections').sortable({
      handle: '.uonix-vts-admin__section-handle',
      items: '> .uonix-vts-admin__section',
      update: function () { sync($root); }
    });
    $root.find('.uonix-vts-admin__items').sortable({
      handle: '.uonix-vts-admin__item-handle',
      items: '> .uonix-vts-admin__item',
      connectWith: false,
      update: function () { sync($root); }
    });
  }

  function appendItem($root, $items, item) {
    const fragment = $root.find('.uonix-vts-admin__item-template')[0].content.cloneNode(true);
    const $item = $(fragment).find('.uonix-vts-admin__item');
    $item.find('.uonix-vts-admin__item-label').val(String(item.label || ''));
    $item.find('.uonix-vts-admin__item-value').val(String(item.value || ''));
    $items.append($item);
  }

  function appendSection($root, section) {
    const fragment = $root.find('.uonix-vts-admin__section-template')[0].content.cloneNode(true);
    const $section = $(fragment).find('.uonix-vts-admin__section');
    $section.find('.uonix-vts-admin__section-title').val(String(section.title || ''));
    $section.find('.uonix-vts-admin__section-layout').val('detailed' === section.layout ? 'detailed' : 'compact');
    const $items = $section.find('.uonix-vts-admin__items');
    (Array.isArray(section.items) ? section.items : []).forEach(function (item) {
      appendItem($root, $items, item);
    });
    $root.find('.uonix-vts-admin__sections').append($section);
  }

  function renderSheetIntoEditor($root, sheet) {
    $root.find('.uonix-vts-admin__sheet-title').val(String(sheet.title || ''));
    $root.find('.uonix-vts-admin__sections').empty();
    (Array.isArray(sheet.sections) ? sheet.sections : []).forEach(function (section) {
      appendSection($root, section);
    });
    initSortable($root);
  }

  function initAll() {
    $('.uonix-vts-admin').each(function () {
      const $root = $(this);
      if ($root.data('uonixVtsReady')) return;
      $root.data('uonixVtsReady', true);
      const raw = String($root.find('.uonix-vts-admin__payload').val() || '');
      if (raw) {
        try {
          const envelope = JSON.parse(raw);
          if ('upsert' === envelope.action && envelope.sheet) {
            renderSheetIntoEditor($root, envelope.sheet);
          }
        } catch (error) {
          $root.addClass('has-payload-error');
        }
      }
      initSortable($root);
      sync($root);
    });
  }

  $(document)
    .on('input change', '.uonix-vts-admin input, .uonix-vts-admin select', function () { sync($(this).closest('.uonix-vts-admin')); })
    .on('click', '.uonix-vts-admin__add', function () {
      const $root = $(this).closest('.uonix-vts-admin');
      $root.addClass('is-active').removeClass('is-deleted');
      $root.find('.uonix-vts-admin__sheet-title').val('Ficha técnica');
      sync($root);
    })
    .on('click', '.uonix-vts-admin__add-section', function () {
      const $root = $(this).closest('.uonix-vts-admin');
      appendSection($root, {
        title: '',
        layout: 'compact',
        items: [{ label: '', value: '' }]
      });
      initSortable($root);
      sync($root);
    })
    .on('click', '.uonix-vts-admin__add-item', function () {
      const $root = $(this).closest('.uonix-vts-admin');
      appendItem($root, $(this).siblings('.uonix-vts-admin__items'), { label: '', value: '' });
      initSortable($root);
      sync($root);
    })
    .on('click', '.uonix-vts-admin__remove-item', function () {
      const $root = $(this).closest('.uonix-vts-admin');
      $(this).closest('.uonix-vts-admin__item').remove();
      sync($root);
    })
    .on('click', '.uonix-vts-admin__remove-section', function () {
      const $root = $(this).closest('.uonix-vts-admin');
      $(this).closest('.uonix-vts-admin__section').remove();
      sync($root);
    })
    .on('click', '.uonix-vts-admin__remove-sheet', function () {
      const $root = $(this).closest('.uonix-vts-admin');
      if (!window.confirm('Remover a ficha técnica desta variação ao salvar?')) return;
      if ('1' === String($root.attr('data-had-sheet'))) {
        $root.addClass('is-deleted').removeClass('is-active');
      } else {
        $root.removeClass('is-active is-deleted');
      }
      sync($root);
    });

  $('#woocommerce-product-data').on('woocommerce_variations_loaded woocommerce_variations_added', initAll);
  $(initAll);
})(jQuery);
```

Fichas já salvas devem passar por `renderSheetIntoEditor()` durante `initAll()`, inclusive após paginação AJAX. Impeça inicialização repetida do sortable destruindo uma instância existente antes de reinicializar a lista.

- [ ] **Step 4: Implement admin CSS with approved colors**

The file must explicitly style inputs/selects within `.uonix-vts-admin`:

```css
.uonix-vts-admin { clear: both; margin: 16px 0 0; padding: 14px; border: 1px solid #c9d5e6; border-radius: 6px; background: #f8fafc; }
.uonix-vts-admin input[type="text"], .uonix-vts-admin select { color: #2c3338 !important; background: #fff; }
.uonix-vts-admin input[readonly] { color: #50575e !important; background: #f0f2f4; }
.uonix-vts-admin input::placeholder { color: #8c8f94; opacity: 1; }
.uonix-vts-admin__editor { display: none; }
.uonix-vts-admin.is-active .uonix-vts-admin__editor { display: block; }
.uonix-vts-admin.is-active .uonix-vts-admin__add { display: none; }
.uonix-vts-admin__section { margin-top: 12px; border: 1px solid #cbd5e1; border-radius: 5px; background: #fff; }
.uonix-vts-admin__section-head, .uonix-vts-admin__item { display: grid; grid-template-columns: 28px minmax(120px, 1fr) minmax(120px, 1fr) 32px; gap: 8px; align-items: center; padding: 8px; }
.uonix-vts-admin__section-head { grid-template-columns: 28px minmax(160px, 1fr) 150px 32px; background: #eef2f6; border-bottom: 1px solid #d9e0e8; }
.uonix-vts-admin__section-handle, .uonix-vts-admin__item-handle { cursor: move; color: #646970; }
.uonix-vts-admin__remove-item, .uonix-vts-admin__remove-section { color: #b32d2e; }
.uonix-vts-admin.is-deleted::after { display: block; content: "Ficha marcada para remoção ao salvar."; color: #b32d2e; font-weight: 600; }
@media (max-width: 782px) {
  .uonix-vts-admin__section-head, .uonix-vts-admin__item { grid-template-columns: 28px 1fr 32px; }
  .uonix-vts-admin__section-layout, .uonix-vts-admin__item-value { grid-column: 2; }
}
```

- [ ] **Step 5: Add JavaScript syntax gate and run GREEN**

Add to the shell/config job:

```yaml
      - name: Validate variation technical sheet admin script
        run: node --check mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js
```

Run:

```bash
node --check mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js
php scripts/tests/test-variation-technical-sheet.php
bash scripts/tests/test-ci-covers-all-tests.sh
git diff --check
```

- [ ] **Step 6: Commit Task 4**

```bash
git add .github/workflows/validate.yml \
  mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-admin.php \
  mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js \
  mu-plugins/uonix-woocommerce/assets/css/admin-ficha-tecnica-variacao.css \
  scripts/tests/test-variation-technical-sheet.php
git commit -m "feat(woocommerce): adiciona editor dinamico da ficha"
```

---

### Task 5: Explicit Copy from Any Sibling Variation

**Files:**
- Modify: `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-admin.php`
- Modify: `mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js`
- Modify: `scripts/tests/test-variation-technical-sheet.php`

**Interfaces:**
- Consumes: parent product/variation relationship, nonce and schema-normalized sheet.
- Produces: `copy_options()`, `get_copy_sheet()`, authenticated AJAX and explicit overwrite confirmation.

- [ ] **Step 1: Write failing copy/security tests**

Cover all cases:

```php
$options = Uonix_VTS_Admin::copy_options( 10382 );
vts_assert_same( 10410, $options[0]['id'], 'lista contém variação filha' );
vts_assert_contains( '#10410', $options[0]['label'], 'label inclui ID' );

$copy = Uonix_VTS_Admin::get_copy_sheet( 10410, 10382 );
vts_assert_same( true, $copy['ok'], 'ficha da irmã retornada' );
vts_assert_same( '37', $copy['sheet']['sections'][0]['items'][0]['value'], 'dados normalizados retornados' );

$wrong_parent = Uonix_VTS_Admin::get_copy_sheet( 10410, 99999 );
vts_assert_same( false, $wrong_parent['ok'], 'origem fora do pai recusada' );

$without_sheet = Uonix_VTS_Admin::get_copy_sheet( 10411, 10382 );
vts_assert_same( false, $without_sheet['ok'], 'origem sem ficha recusada' );
```

Endpoint tests must prove invalid nonce and missing `edit_post` capability never return sheet data.

- [ ] **Step 2: Run RED**

```bash
php scripts/tests/test-variation-technical-sheet.php
```

Expected: missing copy methods/action.

- [ ] **Step 3: Implement copy options and AJAX**

Register:

```php
add_action( 'wp_ajax_uonix_get_variation_technical_sheet', array( __CLASS__, 'ajax_get_copy_sheet' ), 10, 0 );
```

Build labels using:

```php
$formatted = wc_get_formatted_variation( $variation, true, false, false );
$label     = sprintf( '#%d — %s', $variation_id, $formatted );
```

The AJAX handler must:

1. verify `check_ajax_referer( 'uonix_variation_technical_sheet_copy', 'nonce', false )`;
2. read `source_id` and `parent_id` with `absint()`;
3. require `current_user_can( 'edit_post', $parent_id )`;
4. require source `get_parent_id() === $parent_id`;
5. normalize stored meta;
6. return only `sheet` via `wp_send_json_success()`;
7. never expose description, price or unrelated meta.

Na Task 5, estenda `uonixVtsAdmin` com `ajaxUrl`, `nonce`, `copyAction` e `copyOptions` uma vez por editor de produto. Não crie um segundo objeto global.

Adicione ao cabeçalho do editor o markup real abaixo:

```html
<label class="uonix-vts-admin__copy-control">
  Copiar de outra variação
  <select class="uonix-vts-admin__copy-source" aria-label="Variação de origem"></select>
</label>
<button type="button" class="button uonix-vts-admin__copy">Copiar</button>
```

Ao preencher cada select, exclua a opção cujo ID seja igual ao `data-variation-id` do destino.

- [ ] **Step 4: Add copy behavior to JavaScript**

Populate `.uonix-vts-admin__copy-source` selects from localized options. On copy button:

```javascript
const hasData = $root.hasClass('is-active') && collectSheet($root).sections.length > 0;
if (hasData && !window.confirm('Substituir a ficha atual pela ficha selecionada?')) return;
$.post(config.ajaxUrl, {
  action: config.copyAction,
  nonce: config.nonce,
  source_id: sourceId,
  parent_id: config.parentId
}).done(function (response) {
  if (!response || !response.success || !response.data || !response.data.sheet) {
    window.alert('Não foi possível copiar a ficha selecionada.');
    return;
  }
  renderSheetIntoEditor($root, response.data.sheet);
  $root.addClass('is-active').removeClass('is-deleted');
  sync($root);
}).fail(function () {
  window.alert('Não foi possível copiar a ficha selecionada.');
});
```

Reutilize `renderSheetIntoEditor()` da Task 4: ela deve limpar as seções atuais, definir o título, clonar uma seção por seção de origem e todos os itens na ordem original. A cópia permanece sem salvar até o salvamento nativo do WooCommerce.

- [ ] **Step 5: Run GREEN and commit**

```bash
php scripts/tests/test-variation-technical-sheet.php
node --check mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js
git diff --check
git add mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-admin.php \
  mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js \
  scripts/tests/test-variation-technical-sheet.php
git commit -m "feat(woocommerce): copia ficha entre variacoes"
```

---

### Task 6: Fail-Closed Legacy Migration and Rollback Command

**Files:**
- Create: `mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-migration-command.php`
- Modify: `mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php`
- Modify: `scripts/tests/test-variation-technical-sheet.php`

**Interfaces:**
- Consumes: legacy `.uonix-fichas-compactas` descriptions and schema v1.
- Produces: `wp uonix ficha-tecnica migrate --dry-run|--execute|--rollback` with verified backup and idempotence.

- [ ] **Step 1: Add the exact legacy fixture and failing parser tests**

Use a fixture containing text before/after the wrapper, six measures and four details. Assert:

```php
$parsed = Uonix_VTS_Migration_Command::parse_legacy_description( $legacy );
vts_assert_same( true, $parsed['ok'], 'wrapper legado reconhecido' );
vts_assert_same( 'Dimensões (mm)', $parsed['sheet']['title'], 'título extraído' );
vts_assert_same( 'compact', $parsed['sheet']['sections'][0]['layout'], 'medidas viram compacta' );
vts_assert_same( 6, count( $parsed['sheet']['sections'][0]['items'] ), 'seis medidas extraídas' );
vts_assert_same( 'detailed', $parsed['sheet']['sections'][1]['layout'], 'informações viram detalhada' );
vts_assert_same( 4, count( $parsed['sheet']['sections'][1]['items'] ), 'quatro detalhes extraídos' );
vts_assert_same( '<p>Antes</p><p>Depois</p>', $parsed['remaining_description'], 'texto livre preservado byte a byte' );
vts_assert_not_contains( 'Galvan', wp_json_encode( $parsed['sheet'] ), 'subtítulo legado não é migrado' );
```

Add malformed wrapper, 5+4 count, 6+3 count and duplicate wrapper cases; all must fail.

Use an in-memory WordPress/WooCommerce store with five fixture variations to exercise the command class itself. Stub `get_posts()`, `wc_get_product()`, `current_time()` and `WP_CLI`, then assert:

```php
$command->migrate( array(), array( 'dry-run' => true ) );
vts_assert_same( 0, $GLOBALS['vts_store_writes'], 'dry-run não grava' );

$command->migrate( array(), array( 'execute' => true ) );
vts_assert_same( 5, $GLOBALS['vts_store_writes'], 'execute grava as cinco variações' );
vts_assert_same( 5, vts_count_verified_backups(), 'execute cria cinco backups' );

$writes_after_execute = $GLOBALS['vts_store_writes'];
$command->migrate( array(), array( 'execute' => true ) );
vts_assert_same( $writes_after_execute, $GLOBALS['vts_store_writes'], 'segunda execução é idempotente' );

vts_mutate_post_migration_sheet( 10410 );
$snapshot = vts_store_snapshot();
vts_expect_cli_error( function () use ( $command ) {
    $command->migrate( array(), array( 'rollback' => true ) );
} );
vts_assert_same( $snapshot, vts_store_snapshot(), 'rollback com edição posterior não grava nada' );

vts_restore_post_migration_sheet( 10410 );
$command->migrate( array(), array( 'rollback' => true ) );
vts_assert_same( 5, vts_count_legacy_wrappers(), 'rollback restaura as cinco descrições' );
vts_assert_same( 0, vts_count_structured_sheets(), 'rollback remove metas estruturados' );
```

Os helpers de teste acima devem ser implementados no mesmo arquivo usando arrays determinísticos; não devem acessar banco, rede ou WordPress real.

- [ ] **Step 2: Run RED**

```bash
php scripts/tests/test-variation-technical-sheet.php
```

Expected: missing migration class/parser.

- [ ] **Step 3: Implement exact wrapper extraction**

Do not use a nested-div regex. Implement a balanced tag scan:

1. find the opening `<div>` whose class token includes `uonix-fichas-compactas`;
2. tokenize subsequent `<div ...>` and `</div>` tags with offsets;
3. increment depth on open and decrement on close;
4. stop when depth returns to zero;
5. preserve exact `before` and `after` substrings with `substr()`;
6. reject zero, duplicate or unbalanced wrappers.

Parse only the extracted wrapper using `DOMDocument` and `DOMXPath`. Require exactly one ficha/header/measures/info node, six compact pairs and four detailed pairs. Use element text content, then pass the resulting sheet through `Uonix_VTS_Schema::normalize_sheet()`.

- [ ] **Step 4: Implement preflight, execution and logical transaction**

`migrate()` mode selection:

```php
$execute  = isset( $assoc_args['execute'] );
$rollback = isset( $assoc_args['rollback'] );
$dry_run  = isset( $assoc_args['dry-run'] ) || ( ! $execute && ! $rollback );
if ( (int) $execute + (int) $rollback + (int) isset( $assoc_args['dry-run'] ) > 1 ) {
    WP_CLI::error( 'Escolha somente --dry-run, --execute ou --rollback.' );
}
```

Preflight must find variation descriptions containing `uonix-fichas-compactas`, parse all before any write and require exactly five valid candidates. Store for each candidate:

```php
array(
    'original_description'       => $description,
    'source_hash'                => hash( 'sha256', $description ),
    'remaining_description'      => $parsed['remaining_description'],
    'remaining_description_hash' => hash( 'sha256', $parsed['remaining_description'] ),
    'sheet'                      => $parsed['sheet'],
    'sheet_hash'                 => hash( 'sha256', wp_json_encode( $parsed['sheet'] ) ),
    'migrated_at_gmt'            => current_time( 'mysql', true ),
    'version'                    => 1,
);
```

On execute, use `wc_get_product()`, `set_description()`, `update_meta_data()` and `save()`. Re-read after every save and compare both hashes. Track changed IDs; on any exception or mismatch, restore every changed ID from its in-memory backup before calling `WP_CLI::error()`.

Se o backup meta já existir, valide `source_hash`, `original_description` e `version`; nunca o sobrescreva quando divergir. Após um rollback válido, uma nova execução pode reutilizar o mesmo backup somente quando esses campos ainda corresponderem exatamente à origem restaurada.

On idempotent rerun, accept zero wrappers only when exactly five backup metas exist and current sheet/description hashes match their post-migration hashes; output `NO-CHANGE: 5 fichas já migradas e verificadas.`

Rollback must require exactly five backups and matching current hashes, restore original descriptions, delete structured meta, save and retain backup meta. Any post-migration edit aborts all rollback writes.

- [ ] **Step 5: Register the WP-CLI command safely**

Add migration class to bootstrap, then:

```php
if ( class_exists( 'Uonix_VTS_Migration_Command' ) ) {
    Uonix_VTS_Migration_Command::register();
}
```

`register()` must no-op unless `defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' )`, then call:

```php
WP_CLI::add_command( 'uonix ficha-tecnica', __CLASS__ );
```

Expected command output:

```text
DRY-RUN OK: 5 fichas legadas reconhecidas; nenhuma alteração realizada.
EXECUTE OK: 5 fichas migradas; 5 backups verificados.
NO-CHANGE: 5 fichas já migradas e verificadas.
ROLLBACK OK: 5 descrições restauradas; backups preservados.
```

- [ ] **Step 6: Run GREEN and commit**

```bash
php scripts/tests/test-variation-technical-sheet.php
php -l mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-migration-command.php
git diff --check
git add mu-plugins/uonix-woocommerce/22-ficha-tecnica-variacao.php \
  mu-plugins/uonix-woocommerce/ficha-tecnica-variacao/class-uonix-vts-migration-command.php \
  scripts/tests/test-variation-technical-sheet.php
git commit -m "feat(woocommerce): migra fichas legadas com rollback"
```

---

### Task 7: Real WooCommerce Lifecycle Verifier

**Files:**
- Create: `scripts/verify-variation-technical-sheet.php`

**Interfaces:**
- Consumes: all production hooks/classes from Tasks 1-6.
- Produces: real CRUD proof with deterministic cleanup; it is an operational verifier, not an unexecuted CI test.

- [ ] **Step 1: Write the verifier with fail-safe cleanup first**

The script must:

1. require WooCommerce classes/functions or exit non-zero;
2. remember the current user;
3. create a temporary `shop_manager` user;
4. create a draft variable parent and one child variation;
5. register a shutdown cleanup before the first fixture write;
6. delete child, parent and user in cleanup;
7. query for orphan child IDs after deletion and fail if any remain.

Use a unique prefix `TESTE Ficha Técnica Hermes` plus process ID. Never print credentials or unrelated meta.

- [ ] **Step 2: Add real assertions**

Drive the actual hook instead of calling the save callback directly:

```php
wp_set_current_user( $shop_manager_id );
$_POST['uonix_variation_technical_sheet'][0] = wp_json_encode(
    array( 'action' => 'upsert', 'sheet' => $fixture_sheet )
);
do_action( 'woocommerce_admin_process_variation_object', $variation, 0 );
$variation->save();
$reloaded = wc_get_product( $variation->get_id() );
verify_same( '37', $reloaded->get_meta( Uonix_VTS_Schema::META_KEY, true )['sections'][0]['items'][0]['value'], 'shop_manager salva ficha' );

$payload = apply_filters(
    'woocommerce_available_variation',
    array( 'variation_description' => '<p>Descrição livre</p>' ),
    $parent,
    $reloaded
);
verify_contains( '<p>Descrição livre</p>', $payload['variation_description'], 'descrição livre preservada' );
verify_contains( 'uonix-vts', $payload['variation_description'], 'renderer executado pelo filtro real' );
verify_contains( '37', $payload['variation_description'], 'valor renderizado' );
```

Also assert invalid JSON preserves previous meta, absent field preserves it, explicit delete removes it, unauthorized user cannot overwrite it and attempted `<script>` is never present as executable markup.

- [ ] **Step 3: Run the verifier against the feature worktree mount**

Because the Compose file has fixed container names, stop the current stack without deleting volumes, then start it from the feature worktree:

```bash
podman-compose -p uonix-local -f /Users/cassio/GitHubPessoal/uonix-site/local/compose.yml down
podman-compose -p uonix-local -f local/compose.yml up -d db wordpress mailpit
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T \
  -v "$PWD/scripts:/workspace/scripts:ro" \
  wpcli eval-file /workspace/scripts/verify-variation-technical-sheet.php
```

Never add `-v` to `podman-compose down`. Expected:

```text
PASS: ficha técnica percorreu save, reload, frontend, autorização e cleanup sem órfãos.
```

- [ ] **Step 4: Prove mutation RED twice**

Mutation A: temporarily change the save hook string in the feature worktree to `woocommerce_admin_process_variation_object_MUTATED`; run verifier and require non-zero because no meta was saved. Restore immediately.

Mutation B: temporarily change `woocommerce_available_variation` to `woocommerce_available_variation_MUTATED`; run verifier and require non-zero because `uonix-vts` is absent. Restore immediately.

After each restore, rerun and require PASS. Confirm:

```bash
git diff --check
git status --short
```

Only the intended verifier file may remain uncommitted.

- [ ] **Step 5: Commit Task 7**

```bash
git add scripts/verify-variation-technical-sheet.php
git commit -m "test(woocommerce): verifica ficha em ciclo real"
```

---

### Task 8: Full Gates, Local Migration, Visual QA and PR

**Files:**
- No new production files expected.
- Modify only files that fail a gate, with a focused test reproducing each failure first.

**Interfaces:**
- Consumes: complete feature branch.
- Produces: verified local artifact, migration evidence and fail-closed PR.

- [ ] **Step 1: Run all repository-relevant static gates**

```bash
git ls-files -z -- 'mu-plugins/**/*.php' 'themes/kadence-child/**/*.php' | xargs -0 -n1 php -l
php scripts/tests/test-variation-technical-sheet.php
node --check mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js
bash scripts/tests/test-ci-covers-all-tests.sh
bash scripts/tests/test-mu-loader-resilience.sh
bash scripts/tests/test-deploy-mu-plugin-order.sh
bash scripts/tests/test-deploy-bundle.sh
git diff --check
git status --short
```

Expected: every command exit 0 and worktree clean.

- [ ] **Step 2: Re-run real lifecycle verifier**

```bash
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T \
  -v "$PWD/scripts:/workspace/scripts:ro" \
  wpcli eval-file /workspace/scripts/verify-variation-technical-sheet.php
curl -fsS -o /dev/null -w '%{http_code}\n' http://localhost:8080/
curl -fsS -o /dev/null -w '%{http_code}\n' http://localhost:8025/
```

Expected: verifier PASS, then `200` and `200`.

- [ ] **Step 3: Exercise admin UI across AJAX lifecycle**

Using the existing authenticated local browser session:

1. open product 10382;
2. expand variation 10410;
3. confirm input text color is `rgb(44, 51, 56)` and readonly color `rgb(80, 87, 94)`;
4. add/reorder/remove sections and items;
5. paginate variations and return;
6. generate a temporary variation and confirm editor initialization;
7. copy from a variation on another page;
8. cancel and confirm no database change;
9. save and reopen to confirm persistence;
10. use a `shop_manager` and confirm editing remains available;
11. use a user without `edit_post` and confirm editor/AJAX/save are denied;
12. select different variations on the frontend and confirm the card changes;
13. reset the variation selection and confirm the card is cleared by the native WooCommerce flow;
14. verify six compact plus four detailed items, then a fixture with different item counts;
15. verify `·`, `×`, quotes, fractions, units and attempted `<script>` text;
16. verify native price, stock, image, dimensions, attributes, order and deletion still work.

Do not enter credentials through automation. Stop and ask the user if the existing session is not authenticated.

- [ ] **Step 4: Run migration dry-run and archive exact evidence**

```bash
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T \
  wpcli uonix ficha-tecnica migrate --dry-run
```

Require exact count 5, six compact items and four detailed items per candidate. Save command output to a local ignored evidence file, not to the repository.

- [ ] **Step 5: Execute, visually compare, rollback and re-execute**

```bash
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T \
  wpcli uonix ficha-tecnica migrate --execute
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T \
  wpcli uonix ficha-tecnica migrate --execute
```

Expected first `EXECUTE OK`, second `NO-CHANGE`.

Compare all five variation selections on desktop and mobile. Then:

```bash
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T \
  wpcli uonix ficha-tecnica migrate --rollback
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T \
  wpcli uonix ficha-tecnica migrate --execute
```

Expected `ROLLBACK OK`, then `EXECUTE OK`. Recheck frontend and description preservation.

- [ ] **Step 6: Restore the main local mount**

Restaure o mount do repositório principal sem remover volumes:

```bash
podman-compose -p uonix-local -f local/compose.yml down
podman-compose -p uonix-local -f /Users/cassio/GitHubPessoal/uonix-site/local/compose.yml up -d db wordpress mailpit
curl -fsS -o /dev/null -w '%{http_code}\n' http://localhost:8080/
curl -fsS -o /dev/null -w '%{http_code}\n' http://localhost:8025/
```

Esperado: `200` e `200`. Nunca execute `down -v`.

- [ ] **Step 7: Independent review before PR**

Review the full diff for:

- core/template overrides;
- role-name checks;
- unescaped dynamic output;
- meta wipe when field absent;
- direct writes without backup in migration;
- selector dependence on WooCommerce internal IDs;
- accidental inclusion of `21-*` price code;
- secrets or local evidence files.

Fix every finding with a reproducing test and a focused commit.

- [ ] **Step 8: Open a dedicated PR and wait for CI**

Crie `/tmp/uonix-vts-pr-body.md` com `write_file` e este conteúdo exato:

```markdown
## Escopo

- editor estruturado por variação, separado da descrição livre;
- seções compactas e detalhadas reordenáveis;
- atributos oficiais no cabeçalho;
- cópia explícita entre variações;
- frontend responsivo sem override do WooCommerce;
- migração WP-CLI das cinco fichas com dry-run, idempotência e rollback.

## Verificação

- `php scripts/tests/test-variation-technical-sheet.php`;
- `node --check mu-plugins/uonix-woocommerce/assets/js/admin-ficha-tecnica-variacao.js`;
- ciclo real via `scripts/verify-variation-technical-sheet.php`;
- mutações RED dos hooks de save e frontend;
- migração local execute → no-change → rollback → execute;
- validação administrativa, AJAX, desktop e mobile.

## Segurança e rollout

- especificação: `docs/superpowers/specs/2026-08-10-ficha-tecnica-variacoes-woocommerce-design.md`;
- sem alteração em core/template do WooCommerce;
- regra de preço `0` permanece em PR separado;
- CSS adicional legado permanece disponível para rollback e não é removido neste PR;
- DEV/ambientes seguintes dependem de CI verde, backup e validação humana.
```

```bash
git status --short
git log --oneline --decorate -8
git push -u origin feat/ficha-tecnica-variacoes
gh pr create \
  --base master \
  --head feat/ficha-tecnica-variacoes \
  --title "feat(woocommerce): editor estruturado de ficha por variação" \
  --body-file /tmp/uonix-vts-pr-body.md
```

The PR body must cite the specification, contract test, real verifier, mutation proof and local migration cycle. It must state explicitly that the legacy Additional CSS remains for rollback and is not part of this PR.

Wait until the PR head SHA equals the tested SHA and every required check is genuinely green. Never merge with pending, cancelled, missing or failed checks and never use `--admin`.

- [ ] **Step 9: DEV rollout gate after PR approval**

Only after green CI and approved review:

1. deploy code to DEV;
2. take content/database backup under the existing runbook;
3. run migration `--dry-run` and compare count/hash evidence;
4. run `--execute`;
5. clear cache;
6. validate all five combinations;
7. ask for human validation before any QA/production promotion.

The old Additional CSS is removed only in a later, separate cleanup after DEV/QA parity is confirmed.

## Plan Completion Criteria

- Eight tasks completed in order with one focused commit per code task.
- Contract test is directly referenced by CI.
- PHP 8.3/8.5 lint, Node syntax and repository guards are green.
- Real WooCommerce verifier passes and both mutations prove RED.
- Five-item migration completes execute → no-change → rollback → execute.
- Admin/AJAX/frontend visual behavior is validated locally.
- Price-default files are absent from this feature diff.
- Dedicated PR is open with head SHA equal to the verified SHA.
- No merge or environment promotion occurs without required green gates and human validation.
