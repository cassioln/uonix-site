# Editor fixo da breve descrição — plano de implementação

> **Para trabalhadores agênticos:** executar cada tarefa com TDD estrito (RED → GREEN → refatoração) e revisão independente antes da entrega.

**Objetivo:** tornar o editor `excerpt` fixo e imediatamente anterior ao editor `content`, sem mover TinyMCE no DOM nem duplicar o salvamento do WordPress.

**Arquitetura:** em `admin_head` na prioridade `PHP_INT_MAX`, depois que WordPress/WooCommerce registraram as metaboxes e antes de o core montar “Opções de tela”, confirmar pela API oficial que o produto usa o editor clássico, localizar a definição ativa de `postexcerpt`, removê-la somente após validação e guardar seu callback original. Em `edit_form_after_title`, executar esse callback antes de `#postdivrich`. Se o editor ou o contrato não for reconhecido de forma inequívoca, preservar a metabox original.

**Tecnologias:** PHP 8.3/8.5, hooks WordPress, editor clássico, WooCommerce 11, testes PHP autônomos e Node para o fallback TinyMCE existente.

## Restrições globais

- Não editar WordPress, WooCommerce, tema ou plugin de terceiro.
- Não mover editor/iframe com JavaScript.
- Não registrar rotina nova de salvamento.
- Não alterar dados de produtos existentes.
- Manter o módulo `24-admin-resumo-editor-estavel.php` como fallback defensivo.
- Não realizar deploy.

---

### Tarefa 1: contrato PHP do layout fixo

**Arquivos:**

- Criar: `scripts/tests/test-product-short-description-layout.php`
- Criar: `scripts/tests/test-product-short-description-no-block-api.php`
- Modificar: `.github/workflows/validate.yml`

**Interface produzida pelo teste:**

- ação `admin_head` registrada uma vez, prioridade `PHP_INT_MAX`, zero argumentos;
- ação `edit_form_after_title` registrada uma vez, prioridade 10, um argumento;
- funções públicas prefixadas `uonix_admin_resumo_fixo_`.

- [x] Criar um harness PHP que simule `$wp_meta_boxes`, `add_action()`, `remove_meta_box()`, escaping e tooltip.
- [x] Cobrir produto incorreto, editor de blocos, caixa ausente, prioridade desconhecida, callback inválido, duas caixas ativas e uma caixa válida.
- [x] Cobrir captura repetida antes do render, contexto não padrão, reinserção tardia, registro global malformado, contexto/grupo irmão malformado antes da remoção e ausência real da API em processo isolado.
- [x] No caso válido, exigir remoção após localização, callback/argumentos encaminhados e wrapper sem `.handle-actions`, `ui-sortable-handle` ou `handlediv`.
- [x] Exigir que a saída de `edit_form_after_title` apareça antes de um marcador simulado de `#postdivrich`.
- [x] Exigir ausência de registros em `save_post` e `woocommerce_process_product_meta`.
- [x] Registrar o teste no workflow canônico.
- [x] Executar `php scripts/tests/test-product-short-description-layout.php` e confirmar falha pela ausência do módulo/registro.

### Tarefa 2: implementação mínima server-side

**Arquivos:**

- Criar: `mu-plugins/uonix-woocommerce/25-admin-resumo-fixo.php`
- Modificar: `mu-plugins/uonix-woocommerce/module.php`

**Interfaces:**

```php
uonix_admin_resumo_fixo_localizar( array $meta_boxes, string $screen_id ): ?array
uonix_admin_resumo_fixo_caixa_utilizavel( $box ): bool
uonix_admin_resumo_fixo_registro_livre( array $meta_boxes, string $screen_id ): bool
uonix_admin_resumo_fixo_capturar(): void
uonix_admin_resumo_fixo_renderizar( $post ): void
```

- [x] Implementar `uonix_admin_resumo_fixo_localizar()` percorrendo contextos e prioridades removíveis, recusando toda a localização se qualquer contexto/grupo irmão não for examinável e aceitando exatamente uma definição ativa/chamável de `postexcerpt`.
- [x] Implementar `uonix_admin_resumo_fixo_capturar()` com escopo no editor clássico de `product`, captura fail-closed e remoção antes de “Opções de tela”.
- [x] Implementar `uonix_admin_resumo_fixo_renderizar()` com título escapado, wrapper fixo ID `postexcerpt`, consumo único da definição guardada e chamada idêntica a `do_meta_boxes()`.
- [x] Tornar a captura repetida idempotente e revalidar no render que nenhuma definição ativa reapareceu; qualquer estado não comprovável recua sem HTML paralelo.
- [x] Adicionar o módulo ao loader depois do módulo 24.
- [x] Executar o teste focal e confirmar PASS.

### Tarefa 3: validade das provas e regressões

**Arquivos:**

- Testar: `scripts/tests/test-product-short-description-layout.php`
- Testar: `scripts/tests/test-product-short-description-no-block-api.php`
- Testar: `scripts/tests/test-product-excerpt-editor.php`
- Testar: `scripts/tests/test-product-excerpt-editor.mjs`

- [ ] Reverter temporariamente cada contrato load-bearing numa cópia integral da árvore: hooks `admin_head` e `edit_form_after_title`, escopo de produto, guarda do editor clássico, unicidade da caixa, prioridade removível, `is_callable`, remoção antecipada, consumo único e chamada do callback.
- [ ] Para cada mutação, confirmar baseline verde, mutação aplicada, PHP válido e teste vermelho pelo motivo esperado.
- [ ] Executar:

```bash
php -l mu-plugins/uonix-woocommerce/25-admin-resumo-fixo.php
php scripts/tests/test-product-short-description-layout.php
php scripts/tests/test-product-excerpt-editor.php
node --test scripts/tests/test-product-excerpt-editor.mjs
bash scripts/tests/test-ci-covers-all-tests.sh
```

- [ ] Executar a suíte PHP gerenciada e os validadores aplicáveis em ambiente equivalente ao CI.

### Tarefa 4: smoke real e revisão

**Arquivos:** nenhum arquivo de produção adicional.

- [x] Atualizar o WordPress local canônico em `http://localhost:8080/` com a principal remota real e montar exatamente o MU-plugin da worktree, preservando o banco.
- [x] Criar produto e usuário exclusivamente sintéticos, confirmar uma única ocorrência de `#postexcerpt`, fora de `.meta-box-sortables`, antes de `#postdivrich` e limpar as fixtures com verificação de órfãos.
- [x] Confirmar editor `excerpt`, `name="excerpt"`, Visual/Código, Adicionar mídia, Pods e painel de dados do WooCommerce.
- [x] Confirmar que preferências antigas `metaboxhidden_product` e `meta-box-order_product` não ocultam nem reposicionam o editor fixo.
- [x] Confirmar seleção Visual, alternância Visual↔Código, identidade de `iframe`/`contentWindow`/`contentDocument`, salvamento em `post_excerpt` e reload.
- [x] Confirmar por teste focal que o fallback preserva a metabox quando o callback é inválido.
- [ ] Solicitar revisão independente read-only do diff e corrigir bloqueantes.
- [ ] Reexecutar os gates após qualquer correção e preparar commit/PR sem deploy.

### Tarefa 5: publicação controlada

- [ ] Congelar o diff validado e obter revisão independente vinculada ao SHA exato.
- [ ] Criar commit convencional, publicar somente a branch da feature e abrir PR não draft contra a default branch medida (`master`).
- [ ] Confirmar que o `headRefOid` do PR é o SHA revisado, acompanhar todos os checks e reportar o estado real.
- [ ] Não fazer merge nem deploy nesta entrega.
