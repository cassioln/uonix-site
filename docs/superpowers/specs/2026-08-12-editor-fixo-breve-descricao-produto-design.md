# Editor fixo da breve descrição do produto

**Data:** 2026-08-12
**Escopo:** editor clássico de produtos WooCommerce no wp-admin

## Objetivo

Exibir, imediatamente abaixo do título e do link permanente, dois editores fixos nesta ordem:

1. **Breve descrição sobre o produto** (`post_excerpt`, editor `excerpt`);
2. **Descrição do produto** (`post_content`, editor `content`).

A breve descrição não poderá ser arrastada, recolhida nem movida pelas setas de metabox. Nenhum iframe do TinyMCE será movido depois de inicializado.

## Evidência da instalação atual

A inspeção foi feita contra WordPress **7.0.3** e WooCommerce **11.0.1** instalados localmente:

- `wp-admin/edit-form-advanced.php` executa `edit_form_after_title` antes de criar `#postdivrich` e seu editor `content`;
- `wp-admin/admin-header.php` executa `admin_head` antes de `render_screen_meta()`, que monta “Opções de tela”;
- WooCommerce registra `postexcerpt` no contexto `normal` com o callback `WC_Meta_Box_Product_Short_Description::output`;
- esse callback continua usando `wp_editor()`, editor ID `excerpt`, campo `name="excerpt"` e o filtro oficial `woocommerce_product_short_description_editor_settings`;
- o HTML servido confirma que `#postexcerpt` está em `#normal-sortables`, enquanto `#postdivrich` já nasce no fluxo fixo de `#post-body-content`.

## Alternativas consideradas

### 1. Capturar e renderizar server-side o callback registrado — escolhida

Em `admin_head`, depois do registro das metaboxes e antes de `render_screen_meta()`, localizar a única definição ativa de `postexcerpt`, validá-la integralmente, removê-la do registro reordenável e guardar sua definição. Em `edit_form_after_title`, chamar o mesmo callback no fluxo fixo.

**Vantagens:**

- usa hooks estáveis do WordPress;
- reaproveita a implementação e os filtros do WooCommerce instalado;
- não copia configurações internas do TinyMCE;
- não cria salvamento paralelo;
- não move DOM nem iframe após a inicialização;
- impede que “Opções de tela” e preferências antigas de metabox ocultem ou reposicionem o editor fixo;
- preserva extensões como Adicionar mídia, Visual/Código e filtros do editor.

### 2. Recriar o editor com chamada própria a `wp_editor()` — descartada

Duplicaria configurações hoje pertencentes ao WooCommerce. Uma atualização poderia mudar botões, filtros ou argumentos e deixar a cópia divergente.

### 3. Mover `#postexcerpt` com JavaScript — descartada

O movimento pós-inicialização já demonstrou recriar o contexto do iframe e quebrar a seleção do TinyMCE. Antecipar o movimento por timing de scripts continuaria sensível à ordem de carga.

### 4. Apenas esconder controles da metabox — descartada

A caixa continuaria no sistema reordenável, sujeita à ordem persistida por usuário e a movimentações feitas pelo WordPress ou por plugins.

## Arquitetura

Um módulo isolado no MU-plugin será carregado depois do módulo defensivo atual e registrará dois callbacks complementares:

1. `admin_head`, na prioridade `PHP_INT_MAX`, captura a definição depois de `register_and_do_post_meta_boxes()` e dos ajustes administrativos, mas antes de `render_screen_meta()`;
2. `edit_form_after_title` renderiza a definição capturada antes de o WordPress criar `#postdivrich`.

A separação é necessária: remover a metabox apenas em `edit_form_after_title` produz a ordem visual correta, mas é tarde para impedir que o WordPress emita `#postexcerpt-hide`. O JavaScript nativo de postboxes poderia então ocultar o novo wrapper fixo pelo mesmo ID.

A fase de captura, somente para `post_type=product` no editor clássico:

- usa `use_block_editor_for_post()` para permanecer inerte no editor de blocos, onde `edit_form_after_title` não é disparado;
- percorre contextos e prioridades de `$wp_meta_boxes['product']`;
- valida que todos os contextos e grupos de prioridade examinados sejam arrays antes de remover qualquer definição; um contexto ou grupo irmão malformado invalida a localização inteira e preserva a metabox nativa;
- aceita somente as prioridades que `remove_meta_box()` realmente neutraliza (`high`, `core`, `default` e `low`);
- ignora marcadores `false` criados por `remove_meta_box()`;
- aceita somente uma definição ativa de ID `postexcerpt`, título textual e callback chamável;
- não altera nada se a caixa estiver ausente, duplicada ou malformada;
- remove a metabox somente depois de validar completamente a definição que será guardada;
- não guarda nada quando o contrato é ambíguo ou inválido, preservando o comportamento nativo;
- trata chamadas repetidas de `admin_head` como idempotentes: mantém uma captura válida somente quando o registro íntegro comprova que a metabox já está removida, sem remover novamente;

A fase de renderização:

- consome a captura antes de qualquer retorno, para que estado inválido ou adulterado não possa ser reutilizado por uma chamada posterior;
- revalida imediatamente antes do HTML que o registro global da tela `product` continua íntegro e sem qualquer definição ativa de `postexcerpt`;
- se outra extensão reinserir a metabox depois da captura, não cria uma segunda cópia fixa e deixa a definição reinserida para o fluxo nativo;
- cria um wrapper fixo fora de `.meta-box-sortables`;
- mantém o ID `postexcerpt`, preservando compatibilidade com integrações que procuram a caixa pelo ID, mas não inclui alça, setas ou botão de recolher;
- usa o título capturado e associa seu `label` ao editor `excerpt`;
- chama o callback original com os mesmos dois argumentos usados por `do_meta_boxes()`.

O callback original continua emitindo `name="excerpt"`; portanto o WordPress salva normalmente em `post_excerpt`. Não haverá callback novo em `save_post` nem em `woocommerce_process_product_meta`.

## Comportamento fail-closed e atualizações

Se a tela usar o editor de blocos, se a API oficial para identificar o editor não estiver disponível, ou se a caixa estiver ausente, duplicada, corrompida, em prioridade que `remove_meta_box()` não neutraliza ou com callback não chamável, a captura não remove nem guarda nada e a renderização não cria uma cópia. A metabox original permanece no fluxo normal do WordPress. O mesmo recuo conservador vale se qualquer contexto ou grupo de prioridade irmão estiver malformado, se o registro global ficar indisponível/malformado ou se uma definição ativa reaparecer entre captura e renderização. Essa validação ocorre antes da remoção: detectar a estrutura apenas no render seria tarde e poderia deixar a tela sem a metabox nativa e sem a cópia fixa.

O JavaScript defensivo atual de estabilização do TinyMCE será mantido. No caminho fixo ele fica inerte, pois não há `postexcerpt` dentro de `.meta-box-sortables` nem botões de ordem. Se uma atualização acionar o fallback, ele volta a proteger a metabox móvel.

Essa estratégia reduz o acoplamento a nomes de classe e arquivos internos do WooCommerce: o único contrato estrutural é o ID histórico `postexcerpt`, já usado pelo próprio WordPress e WooCommerce.

## Marcação e aparência

O wrapper seguirá o padrão fixo da descrição principal:

```html
<div id="postexcerpt" class="postarea postbox uonix-product-short-description">
  <h2 class="postbox-header">
    <label for="excerpt">…</label>
    <span class="woocommerce-help-tip" aria-label="…" data-tip="…"></span>
  </h2>
  <!-- callback original do WooCommerce -->
</div>
```

Não será adicionada classe `ui-sortable-handle`, `.handle-actions`, botão de ordem ou botão de recolhimento. A aparência base virá das classes administrativas já usadas por `#postdivrich`, sem asset visual novo.

## Verificação

A entrega exige:

- teste PHP focal com RED anterior à implementação;
- escopo restrito ao editor clássico de produtos e ausência de hooks de salvamento;
- localização inequívoca, encaminhamento exato do callback/argumentos e fallback sem remoção;
- captura repetida idempotente, consumo único inclusive em retornos inválidos e reinserção tardia sem cópia duplicada;
- teste em processo PHP separado onde `use_block_editor_for_post()` realmente não existe;
- captura anterior a “Opções de tela”, sem `#postexcerpt-hide` no caminho válido;
- HTML sem controles móveis e com `excerpt` antes do marcador do editor `content`;
- prova por mutação removendo cada hook, adiando/neutralizando a captura, aceitando callback inválido e permitindo duplicidade;
- lint PHP e suíte focal atual do lifecycle TinyMCE;
- smoke no WordPress local canônico com fixture sintética descartável, incluindo preferências antigas de ocultação/ordem, identidade do iframe, Visual/Código, salvamento nativo, reload e limpeza verificada;
- revisão independente antes de commit final/PR.

## Fora de escopo

- alteração do conteúdo de produtos;
- migração de banco;
- mudança de como `post_excerpt` é salvo ou exibido no frontend;
- edição de WordPress, WooCommerce, tema ou plugins de terceiros;
- deploy para DEV, QA ou produção.
