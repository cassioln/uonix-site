# RFQ habilitado por padrão em produtos sem configuração

**Data:** 2026-08-11
**Status:** aprovado para planejamento
**Base:** `master@13c867b3ec02e736704049caea06e4355bab5c4e`

## Contexto

O plugin **NP Quote Request for WooCommerce 2.4.14** adiciona, na aba Avançado do cadastro de produtos, o campo `_gpls_woo_rfq_rfq_enable`. O plugin grava `yes` quando o campo está marcado e `no` quando o usuário o desmarca e salva.

Hoje, quando a meta não existe, o valor efetivo é vazio. O objetivo é tratar a ausência como RFQ habilitado, tanto no site quanto no editor, sem sobrescrever uma decisão explícita do usuário.

A personalização será versionada no MU-plugin `uonix-woocommerce`. O plugin de terceiros não será editado.

## Objetivo

Para produtos e variações sem a meta `_gpls_woo_rfq_rfq_enable`:

- o RFQ deve ficar ativo imediatamente, sem exigir que alguém abra e salve o produto;
- o checkbox deve aparecer marcado no editor;
- ao salvar, o plugin continuará materializando `yes` ou `no` normalmente;
- um valor explícito `no` deve continuar desmarcado e desabilitado;
- um valor explícito `yes` deve continuar marcado e habilitado.

## Fora de escopo

- Não fazer atualização em massa dos registros existentes.
- Não modificar arquivos do RFQ Toolkit ou do WooCommerce.
- Não forçar `yes` sobre produtos que tenham `no` salvo.
- Não alterar configurações globais, preços, estoque, atributos ou conteúdo dos produtos.
- Não misturar esta regra com a implementação do preço padrão `0` das variações.

## Evidência da versão instalada

Na versão instalada do RFQ Toolkit:

- o campo é renderizado por `woocommerce_wp_checkbox()` com o ID `_gpls_woo_rfq_rfq_enable`;
- o salvamento grava `yes` quando o campo está presente no POST e `no` quando está ausente;
- para produtos variáveis, o plugin propaga a decisão salva às variações existentes;
- as decisões do frontend consultam a chave por `get_post_meta()`.

No WooCommerce 10.9.4, `woocommerce_wp_checkbox()` resolve o valor pela meta do produto e compara esse valor com `yes` para gerar o atributo `checked`.

No WordPress instalado, o filtro `default_post_metadata` recebe valor padrão, ID, chave, modo single e tipo da meta. Esse filtro só participa quando não há valor físico para a chave.

## Decisão técnica

Criar um módulo focado, previsto como:

```text
mu-plugins/uonix-woocommerce/23-rfq-produto-padrao.php
```

O módulo registrará `default_post_metadata` e retornará o default `yes` apenas quando todas as condições forem verdadeiras:

1. a chave consultada é `_gpls_woo_rfq_rfq_enable`;
2. o objeto é um post válido;
3. o tipo do post é `product` ou `product_variation`.

Para leitura single, o retorno será `yes`; para leitura múltipla, será uma lista contendo `yes`. Consultas a outras chaves, tipos de post ou objetos devem preservar o valor padrão recebido.

Não será necessário consultar `metadata_exists()` dentro do filtro: o próprio caminho `get_metadata_default()` somente é usado quando a leitura bruta não encontrou meta. Isso evita recursão e mantém a regra restrita à ausência física.

## Matriz de comportamento

| Tipo | Meta física | Valor efetivo | Checkbox | Resultado no site |
|---|---|---|---|---|
| Produto | ausente | `yes` | marcado | RFQ habilitado |
| Variação | ausente | `yes` | aplicável conforme plugin | RFQ habilitado |
| Produto/variação | `yes` | `yes` | marcado | RFQ habilitado |
| Produto/variação | `no` | `no` | desmarcado | RFQ desabilitado |
| Outro tipo de post | ausente | padrão original | sem mudança | sem mudança |
| Outra chave | ausente | padrão original | sem mudança | sem mudança |

## Carregamento

O novo arquivo será incluído pelo loader de `mu-plugins/uonix-woocommerce/module.php`, depois dos módulos existentes. Ele não dependerá da ordem interna de carregamento do plugin RFQ, porque usa um hook estável do WordPress e apenas responde a leituras da chave documentada.

Se o RFQ Toolkit estiver inativo, a regra não produzirá interface nem comportamento de RFQ por si só; apenas continuará definindo o valor padrão da chave para produtos e variações.

## Fluxo de dados

1. RFQ Toolkit ou WooCommerce consulta `_gpls_woo_rfq_rfq_enable`.
2. Se existe `yes` ou `no` físico, o WordPress devolve esse valor sem aplicar o default.
3. Se a meta está ausente, o WordPress chama `default_post_metadata`.
4. O módulo confirma chave e tipo de post e devolve `yes`.
5. O frontend trata RFQ como ativo e o helper do admin renderiza o checkbox marcado.
6. Se o usuário desmarca e salva, o RFQ Toolkit grava `no`; as próximas leituras devolvem `no`, sem passar pelo default.

## Segurança e compatibilidade

- A regra preserva entrada explícita e não executa gravações silenciosas.
- Não há JavaScript dependente do DOM interno do WooCommerce.
- Não há override de template ou edição de core/plugin.
- Não há operação em massa nem necessidade de rollback de banco.
- O escopo é limitado aos post types oficiais do WooCommerce.
- A regra cobre editor, frontend, REST, importações e WP-CLI sempre que a leitura ocorrer por APIs oficiais de metadata.

## Estratégia de testes

A implementação seguirá RED → GREEN → REFACTOR.

O teste focal deverá provar:

1. meta ausente em produto retorna `yes`;
2. meta ausente em variação retorna `yes`;
3. `no` físico permanece `no`;
4. `yes` físico permanece `yes`;
5. outra chave preserva o default original;
6. outro tipo de post preserva o default original;
7. leitura múltipla mantém o formato esperado;
8. o módulo está incluído pelo loader real;
9. o HTML do checkbox contém `checked` para produto sem meta;
10. remover ou alterar o hook faz o teste focal falhar.

Além do teste focal:

- lint PHP dos arquivos alterados;
- suíte PHP nas versões normativas do repositório;
- testes do loader e do WooCommerce;
- `git diff --check`;
- smoke test do cadastro de produto no ambiente local.

## Critérios de aceite

- Um produto existente sem a chave passa a operar com RFQ ativo imediatamente.
- Ao abrir esse produto, o checkbox aparece marcado.
- Desmarcar e salvar cria ou mantém `no`, e a decisão permanece após recarregar.
- Produtos com `no` já salvo não são reativados.
- Produtos e variações com `yes` continuam inalterados.
- Nenhum registro é atualizado em massa para implantar o comportamento.
- A personalização fica isolada em módulo próprio, com testes automatizados e sem alteração no plugin de terceiros.
