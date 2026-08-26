---
name: uonix-page-editor
description: "Guia especializado para criação, edição e gestão de páginas WordPress com blocos Kadence e SEO Rank Math no projeto Uonix. Abrange geração de blocos Gutenberg/Kadence válidos, atualização e inspeção de metadados Rank Math (título, descrição, foco, robots, schema), comandos WP-CLI locais via Podman e remotos via SSH, e regras de governança."
compatibility: "WordPress 6.0+, Kadence Theme & Blocks, Rank Math SEO, WP-CLI, Podman/Docker"
---

# Uonix Page & SEO Editor (Kadence + Rank Math)

Este guia padroniza o fluxo de criação, edição e gestão técnica de páginas WordPress utilizando **Kadence Blocks** e **Rank Math SEO** dentro da infraestrutura do `uonix-site`.

---

## 1. Execução de Comandos WP-CLI no Projeto

### A) Ambiente Local (Podman)
No ambiente local do repositório, utilize o profile `tools` do Podman Compose:

```bash
# Formato padrão
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli <comando>

# Exemplos rápidos:
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli post list --post_type=page --fields=ID,post_title,post_name,post_status
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli post get <ID>
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli cache flush
```

### B) Ambientes Remotos (DEV / QA / PROD)
- **DEV / QA**: Sempre conferir o `--path` e `--url` corretos.
- **PROD**: NUNCA executar mutações destrutivas sem dry-run, backup prévio e confirmação (consulte `docs/ambientes.md`).

---

## 2. Metadados do Rank Math SEO (`postmeta`)

O Rank Math armazena seus dados diretamente na tabela `wp_postmeta`. Use as chaves abaixo para leitura e escrita via WP-CLI:

| Chave de Meta | Finalidade | Exemplo de Valor |
| :--- | :--- | :--- |
| `rank_math_title` | Título SEO (Snippet) | `Ancoragem Predial em SP | Uonix Soluções` |
| `rank_math_description` | Meta Description (140-160 chars) | `Serviço especializado de ancoragem predial e linha de vida com ART e conformidade NR-35 e NR-18. Solicite um orçamento técnico.` |
| `rank_math_focus_keyword` | Palavras-chave foco (separadas por vírgula) | `ancoragem predial, linha de vida, teste de arrancamento` |
| `rank_math_robots` | Diretivas de indexação (array PHP serializado) | `a:1:{i:0;s:5:"index";}` ou `a:2:{i:0;s:7:"noindex";i:1;s:8:"nofollow";}` |
| `rank_math_canonical_url` | URL Canônica personalizada (se houver) | `https://uonix.com.br/ancoragem-predial/` |
| `rank_math_primary_category` | ID da Categoria Principal | `12` |
| `rank_math_schema_FAQPage` | Ativar/Configurar Schema de FAQ | `off` ou bloco de schema customizado |

### Comandos WP-CLI para Rank Math

```bash
# Consultar metadados de uma página (ex: ID 150)
wp post meta get 150 rank_math_title
wp post meta get 150 rank_math_description
wp post meta get 150 rank_math_focus_keyword

# Listar todos os metadados de SEO da página
wp post meta list 150 --keys=rank_math_title,rank_math_description,rank_math_focus_keyword,rank_math_robots

# Atualizar metadados de SEO
wp post meta update 150 rank_math_title "Título Otimizado SEO | Uonix"
wp post meta update 150 rank_math_description "Descrição persuasiva com a palavra-chave e chamada para ação."
wp post meta update 150 rank_math_focus_keyword "termo principal, termo secundario"
wp post meta update 150 rank_math_robots 'a:1:{i:0;s:5:"index";}'
```

---

## 3. Estrutura de Blocos Kadence (Gutenberg)

Para evitar erros de parsing e problemas de aspas no terminal, **sempre gere o conteúdo em um arquivo HTML temporário** (`/tmp/conteudo.html`) antes de criar ou atualizar a página.

> [!IMPORTANT]
> Todo bloco Kadence exige um `uniqueID` único (ex: `_uonix_hero_sec`, `_uonix_h1_main`). O comentário do bloco (`<!-- wp:kadence/... -->`) e o HTML interno devem coincidir para evitar que o editor visual do WordPress exiba o aviso de *"Attempt Block Recovery"*.

### A) Hero / Seção de 1 Coluna (`rowlayout` + `column` + `advancedheading`)

```html
<!-- wp:kadence/rowlayout {"uniqueID":"_uonix_hero","columns":1,"colLayout":"equal","padding":["60","20","60","20"],"paddingUnit":"px"} -->
<div class="wp-block-kadence-rowlayout alignnone"><div id="kt-layout-id-_uonix_hero" class="kt-row-layout-inner kt-layout-id-_uonix_hero">
<!-- wp:kadence/column {"uniqueID":"_uonix_hero_col"} -->
<div class="wp-block-kadence-column inner-column-1">
<!-- wp:kadence/advancedheading {"uniqueID":"_uonix_h1_hero","level":1,"color":"#0f172a"} -->
<h1 class="kt-adv-heading_uonix_h1_hero">Título Principal H1 da Página</h1>
<!-- /wp:kadence/advancedheading -->
<!-- wp:paragraph {"fontSize":"medium"} -->
<p class="has-medium-font-size">Parágrafo introdutório com contexto de alta relevância semântica para o usuário e motores de busca.</p>
<!-- /wp:paragraph -->
<!-- wp:kadence/advancedbtn {"uniqueID":"_uonix_btn_hero"} -->
<div class="wp-block-kadence-advancedbtn"><a class="kt-button button kt-btn-size-large kt-btn-style-basic" href="/contato/">Solicitar Orçamento</a></div>
<!-- /wp:kadence/advancedbtn -->
</div>
<!-- /wp:kadence/column -->
</div></div>
<!-- /wp:kadence/rowlayout -->
```

### B) Grid de 2 Colunas (Texto + Destaque)

```html
<!-- wp:kadence/rowlayout {"uniqueID":"_uonix_grid_2col","columns":2,"colLayout":"equal","columnGutter":"default"} -->
<div class="wp-block-kadence-rowlayout alignnone"><div id="kt-layout-id-_uonix_grid_2col" class="kt-row-layout-inner kt-layout-id-_uonix_grid_2col">
<!-- wp:kadence/column {"uniqueID":"_uonix_col_left"} -->
<div class="wp-block-kadence-column inner-column-1">
<!-- wp:kadence/advancedheading {"uniqueID":"_uonix_h2_sec","level":2,"color":"#1e293b"} -->
<h2 class="kt-adv-heading_uonix_h2_sec">Benefícios e Diferenciais</h2>
<!-- /wp:kadence/advancedheading -->
<!-- wp:paragraph -->
<p>Explicação detalhada dos serviços prestados com rigor técnico e conformidade com normas regulamentadoras.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:kadence/column -->
<!-- wp:kadence/column {"uniqueID":"_uonix_col_right"} -->
<div class="wp-block-kadence-column inner-column-2">
<!-- wp:kadence/infobox {"uniqueID":"_uonix_info_1","mediaType":"icon"} -->
<div class="wp-block-kadence-infobox kt-info-box-_uonix_info_1">
<div class="kt-blocks-info-box-link-wrap">
<div class="kt-blocks-info-box-text-wrap">
<h3 class="kt-blocks-info-box-title">Garantia e ART</h3>
<p class="kt-blocks-info-box-text">Todos os serviços acompanham laudo técnico e responsabilidade técnica registrada no CREA.</p>
</div>
</div>
</div>
<!-- /wp:kadence/infobox -->
</div>
<!-- /wp:kadence/column -->
</div></div>
<!-- /wp:kadence/rowlayout -->
```

### C) Seção FAQ / Sanfona (Integrada com Schema FAQ)

```html
<!-- wp:kadence/rowlayout {"uniqueID":"_uonix_faq_sec","columns":1} -->
<div class="wp-block-kadence-rowlayout alignnone"><div id="kt-layout-id-_uonix_faq_sec" class="kt-row-layout-inner kt-layout-id-_uonix_faq_sec">
<!-- wp:kadence/column {"uniqueID":"_uonix_faq_col"} -->
<div class="wp-block-kadence-column inner-column-1">
<!-- wp:kadence/advancedheading {"uniqueID":"_uonix_h2_faq","level":2} -->
<h2 class="kt-adv-heading_uonix_h2_faq">Perguntas Frequentes</h2>
<!-- /wp:kadence/advancedheading -->
<!-- wp:kadence/accordion {"uniqueID":"_uonix_accordion_faq"} -->
<div class="wp-block-kadence-accordion kt-accordion-wrap">
<!-- wp:kadence/pane {"uniqueID":"_uonix_pane_1","title":"Qual a periodicidade de inspeção necessária?"} -->
<div class="wp-block-kadence-pane kt-pane-1">
<!-- wp:paragraph -->
<p>Conforme a NR-35, o sistema deve passar por inspeção anual preventiva ou após qualquer ocorrência de esforço estrutural.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:kadence/pane -->
</div>
<!-- /wp:kadence/accordion -->
</div>
<!-- /wp:kadence/column -->
</div></div>
<!-- /wp:kadence/rowlayout -->
```

---

## 4. Fluxo Completo de Criação / Edição de Página

### Passo 1: Montar o arquivo de conteúdo
Crie o arquivo com o markup dos blocos:
```bash
cat > /tmp/pagina-nova.html << 'EOF'
<!-- CONTEÚDO DOS BLOCOS AQUI -->
EOF
```

### Passo 2: Criar ou Atualizar no WordPress
```bash
# CRIAR NOVA PÁGINA (como rascunho):
PAGE_ID=$(podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli post create /tmp/pagina-nova.html \
  --post_type=page \
  --post_title="Nome da Página" \
  --post_name="slug-da-pagina" \
  --post_status=draft \
  --porcelain)

# OU ATUALIZAR PÁGINA EXISTENTE:
# podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli post update <PAGE_ID> /tmp/pagina-nova.html --post_title="Novo Título"
```

### Passo 3: Injetar Metadados Rank Math SEO
```bash
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli post meta update $PAGE_ID rank_math_title "Título SEO | Uonix"
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli post meta update $PAGE_ID rank_math_description "Descrição otimizada para o snippet do Google."
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli post meta update $PAGE_ID rank_math_focus_keyword "palavra chave foco"
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli post meta update $PAGE_ID rank_math_robots 'a:1:{i:0;s:5:"index";}'
```

### Passo 4: Limpar Cache e Verificar
```bash
# Limpar cache de rewrite e transients
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli cache flush

# Obter URLs de conferência
echo "Admin Editor: http://localhost:8080/wp-admin/post.php?post=${PAGE_ID}&action=edit"
echo "Preview: http://localhost:8080/?p=${PAGE_ID}&preview=true"
```

---

## 5. Checklist de Qualidade e Boas Práticas

- [ ] **Hierarquia de Títulos**: Apenas um `H1` por página; `H2` para seções principais; `H3` para cards/subitens.
- [ ] **Atributos de Imagens**: Todas as imagens devem ter tags `alt` descritivas e contextuais.
- [ ] **Rank Math Snippet**: Meta description entre 130 e 155 caracteres para evitar truncamento no Google.
- [ ] **Prefixos de IDs**: Todos os blocos Kadence devem usar prefixos claros nos `uniqueID` (ex: `_uonix_...`).
- [ ] **Rascunho Inicial**: Sempre crie como `post_status=draft` antes de publicar definitivamente.
