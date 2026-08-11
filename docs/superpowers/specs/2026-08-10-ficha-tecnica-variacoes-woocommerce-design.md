# Ficha técnica estruturada por variação no WooCommerce

**Data:** 2026-08-10
**Status:** desenho funcional e técnico aprovado
**Escopo:** editor de produto variável, persistência, frontend e migração das fichas legadas

## 1. Resumo

A descrição nativa de cada variação continuará livre. Abaixo dela haverá um editor separado de ficha técnica, composto por seções e pares **Rótulo + Valor**. O usuário não verá nem escreverá HTML.

Cada seção poderá:

- ter título opcional;
- usar apresentação **Compacta** ou **Detalhada**;
- conter quantos itens forem necessários dentro dos limites técnicos de segurança;
- ser reordenada ou removida;
- ter seus itens reordenados, adicionados ou removidos.

O subtítulo do cartão será gerado dos atributos oficiais da variação. O frontend reproduzirá o padrão visual atual, com título à esquerda, atributos à direita, grade compacta para medidas e grade detalhada para informações complementares.

A implementação usará somente hooks oficiais do WooCommerce, metadados próprios e assets versionados. Não haverá modificação do WooCommerce, override de template ou dependência de plugin de campos.

## 2. Evidência do estado atual

A auditoria local encontrou:

- cinco variações com o wrapper legado `.uonix-fichas-compactas`;
- todas pertencentes ao produto **Grampo de Cabo de Aço** (`post_parent` 10382 no clone local auditado);
- uma estrutura uniforme de seis medidas e quatro informações complementares;
- ficha armazenada como HTML em `_variation_description`;
- subtítulos digitados manualmente, incluindo divergências como `Galvan` versus o termo oficial `Galvanizado`;
- CSS da ficha no post `custom_css` do tema, sem versionamento no repositório;
- CSS legado com quantidades rígidas `repeat(6, 1fr)` e `repeat(4, 1fr)`.

Os atributos oficiais das cinco variações são Tipo, Bitola e Material. Um exemplo de termo oficial é `Inox 316`, enquanto o HTML legado usa `Inox`.

## 3. Objetivos

1. Eliminar a necessidade de escrever HTML na descrição da variação.
2. Preservar a descrição livre para observações independentes da ficha.
3. Permitir seções e itens variáveis por variação.
4. Reproduzir o padrão visual atual no frontend.
5. Derivar o cabeçalho dos atributos oficiais, evitando duplicação e divergência.
6. Abranger qualquer usuário que já tenha permissão nativa para editar o produto.
7. Suportar variações carregadas, criadas e paginadas por AJAX.
8. Migrar as cinco fichas existentes de modo auditável, idempotente e reversível.
9. Resistir a atualizações do WooCommerce sem depender de sua marcação HTML interna.

## 4. Fora de escopo

- substituir a descrição nativa da variação;
- criar um editor matricial por produto;
- expor HTML livre nos campos da ficha;
- adicionar ACF, outro plugin de campos ou dependência comercial;
- editar arquivos ou templates do WooCommerce;
- alterar preço promocional, estoque, dimensões nativas ou atributos;
- migrar descrições que não contenham o wrapper legado reconhecido;
- publicar automaticamente em QA ou produção.

## 5. Separação dos módulos

A regra do preço padrão e a ficha técnica têm responsabilidades diferentes e permanecerão separadas:

```text
mu-plugins/uonix-woocommerce/
├── 21-variacao-preco-padrao.php
├── 22-ficha-tecnica-variacao.php
└── assets/
    ├── css/
    │   ├── admin-ficha-tecnica-variacao.css
    │   └── ficha-tecnica-variacao.css
    └── js/
        └── admin-ficha-tecnica-variacao.js
```

O arquivo de preço atualmente ainda não commitado como `21-admin-variacao-preco-padrao.php` será renomeado antes de seu commit porque a regra cobre também CSV, REST, WP-CLI e integrações; ela não é exclusivamente administrativa.

O loader `mu-plugins/uonix-woocommerce/module.php` registrará os módulos em ordem explícita.

## 6. Modelo de dados

### 6.1 Meta da variação

Chave:

```text
_uonix_variation_technical_sheet
```

Valor armazenado como array PHP sanitizado:

```php
array(
    'version'  => 1,
    'title'    => 'Dimensões (mm)',
    'sections' => array(
        array(
            'title'  => '',
            'layout' => 'compact',
            'items'  => array(
                array( 'label' => 'A', 'value' => '37' ),
                array( 'label' => 'B', 'value' => '28' ),
            ),
        ),
        array(
            'title'  => '',
            'layout' => 'detailed',
            'items'  => array(
                array( 'label' => 'Espaço mín.', 'value' => '48 mm' ),
                array( 'label' => 'Torque', 'value' => '8 N·m' ),
            ),
        ),
    ),
);
```

A ordem dos arrays é a ordem de exibição. Não são necessários identificadores persistentes para seções ou itens.

### 6.2 O que não será armazenado

- HTML renderizado;
- subtítulo do cabeçalho;
- cópia dos atributos;
- estilos inline;
- conteúdo da descrição livre.

### 6.3 Envelope submetido pelo editor

Cada painel usará um único campo oculto, indexado pelo `$loop` da variação. O valor será JSON e terá uma ação explícita:

```json
{"action":"upsert","sheet":{"version":1,"title":"Dimensões (mm)","sections":[]}}
```

ou:

```json
{"action":"delete"}
```

As semânticas não se misturam:

- campo ausente: não alterar o meta;
- `action: upsert`: validar e salvar `sheet`;
- `action: delete`: remover o meta;
- ação desconhecida, JSON vazio ou JSON inválido: preservar o meta e mostrar erro.

O botão **Remover ficha** produz `action: delete`; ele nunca depende de interpretar um objeto vazio como exclusão.

### 6.4 Limites técnicos de segurança

A interface não terá um limite de negócio visível. O backend aplicará tetos generosos para impedir payload abusivo ou acidental:

- JSON submetido: até 256 KiB por variação;
- até 50 seções;
- até 100 itens por seção;
- título geral: até 160 caracteres;
- título de seção: até 120 caracteres;
- rótulo: até 120 caracteres;
- valor: até 500 caracteres.

Exceder um teto rejeita a nova ficha e preserva o meta anterior. Não haverá truncamento silencioso.

## 7. Editor administrativo

### 7.1 Posição e ciclo de vida

O editor será renderizado após os campos nativos de cada variação por:

```text
woocommerce_product_after_variable_attributes
```

O JavaScript será carregado apenas nas telas `post.php` e `post-new.php` do post type `product`. Todos os eventos serão delegados a marcação própria do módulo para atender:

- variações carregadas por AJAX;
- paginação;
- criação individual;
- geração de variações;
- expansão e recolhimento dos painéis;
- reordenação e remoção nativas.

O código não usará IDs fixos, como `variable_description2`, nem seletores dependentes da estrutura interna do WooCommerce.

### 7.2 Estado sem ficha

Uma variação sem meta exibirá apenas:

```text
Adicionar ficha técnica
```

Ao adicionar, o título inicial será `Ficha técnica`, sem seções. O usuário poderá editar o título e adicionar a primeira seção.

### 7.3 Estado com ficha

O editor exibirá:

1. título geral editável;
2. cabeçalho automático somente leitura;
3. botão **Copiar de outra variação**;
4. botão **Remover ficha**;
5. seções ordenadas;
6. em cada seção:
   - alça de reordenação;
   - título opcional;
   - seletor `Compacta` ou `Detalhada`;
   - botão de remoção;
   - itens ordenados de Rótulo + Valor;
   - botão **Adicionar item**;
7. botão **Adicionar seção**.

A remoção e a cópia alteram apenas o estado local até o usuário acionar o salvamento nativo do WooCommerce.

### 7.4 Cópia entre variações

O botão de cópia permitirá selecionar qualquer variação filha do mesmo produto, inclusive se estiver em outra página do editor.

- A lista de IDs e nomes será carregada de modo leve.
- O conteúdo da origem será obtido sob demanda por AJAX autenticado.
- A origem precisa ter ficha.
- Se o destino já tiver dados, haverá confirmação explícita antes de substituí-los.
- A cópia não será salva até o salvamento nativo do produto.
- Não haverá cópia automática da variação anterior.

### 7.5 Cores e legibilidade

As cores dos campos serão explícitas, sem depender de herança do tema administrativo:

```text
Texto editável e selects:  #2c3338
Campo somente leitura:     #50575e
Placeholder:               #8c8f94
Fundo somente leitura:     #f0f2f4
```

Texto branco será reservado a botões de fundo escuro e ao estado nativo de seleção de texto. Placeholders opcionais poderão ser itálicos, mas dados preenchidos não.

## 8. Subtítulo automático

O subtítulo será calculado em tempo de renderização com os valores oficiais da variação. Nada será copiado para o meta da ficha.

Para preservar o padrão editorial atual, os atributos conhecidos terão apresentação:

```text
pa_tipo      → Modelo
pa_material  → Material
pa_bitola    → Pol.
```

A ordem desses três será Modelo, Material, Pol. Outros atributos de variação serão acrescentados depois, na ordem definida no produto e usando seus rótulos oficiais.

Exemplo:

```text
Modelo: Pesado · Material: Inox 316 · Pol.: 5/16"
```

Os valores virão do nome oficial do termo da taxonomia ou do valor textual oficial do atributo. Não haverá abreviação manual de `Inox 316` para `Inox` nem de `Galvanizado` para `Galvan`.

## 9. Salvamento, autorização e validação

O meta será processado antes do save nativo por:

```text
woocommerce_admin_process_variation_object
```

Regras:

1. usar a capacidade nativa de editar o produto/variação, nunca o nome do perfil;
2. aproveitar o fluxo autenticado e o nonce da tela de produto do WooCommerce;
3. usar nonce próprio e capacidade `edit_post` nos endpoints AJAX de cópia;
4. atualizar ou excluir o meta somente quando o campo próprio estiver presente na requisição;
5. não apagar dados em CSV, REST, WP-CLI ou integração que não envie o campo;
6. decodificar o envelope JSON e aceitar somente `upsert` ou `delete`;
7. em `upsert`, normalizar `sheet` para o esquema v1 e aceitar somente `compact` ou `detailed` como layout;
8. remover seções completamente vazias e itens com ambos os campos vazios;
9. rejeitar a ficha inteira quando um item tiver somente rótulo ou somente valor;
10. salvar apenas texto simples sanitizado;
11. preservar o meta anterior em qualquer falha de validação;
12. mostrar aviso administrativo claro após falha, sem afirmar que a ficha foi salva.

Todos os perfis com capacidade para editar o produto terão o mesmo editor. Clientes e usuários sem essa capacidade não terão acesso.

## 10. Frontend

### 10.1 Integração

O módulo acrescentará o HTML gerado ao payload nativo por:

```text
woocommerce_available_variation
```

O HTML da ficha será anexado depois da descrição livre no campo de resposta que o template nativo já exibe. Consequências:

- a descrição continua independente no banco;
- nenhuma cópia de template é necessária;
- a troca de atributos continua usando o mecanismo nativo;
- ficha ausente não gera marcação vazia;
- a remoção do módulo não corrompe a descrição.

### 10.2 Escapamento

Título, títulos de seção, rótulos, valores e atributos serão renderizados com escapamento de texto. Na entrada, tags e caracteres de controle serão removidos antes da sanitização de texto; somente o conteúdo textual restante poderá ser persistido. No frontend, `esc_html()` será aplicado novamente. HTML ou script nunca será armazenado como marcação nem executado.

### 10.3 Aparência

O cartão seguirá o padrão aprovado:

- fundo branco;
- borda cinza-azulada;
- cantos arredondados;
- cabeçalho cinza-claro;
- título à esquerda;
- atributos à direita;
- células compactas centralizadas, com rótulo menor e valor destacado;
- células detalhadas alinhadas à esquerda, com rótulo menor e valor destacado;
- título de seção omitido quando vazio;
- título de seção exibido como faixa discreta quando preenchido.

As classes novas usarão prefixo próprio e não reutilizarão os seletores legados.

### 10.4 Grade responsiva

Cada seção será sua própria grade CSS:

- `Compacta`: `repeat(auto-fit, minmax(68px, 1fr))`;
- `Detalhada`: `repeat(auto-fit, minmax(150px, 1fr))`.

Assim, seis itens ocupam seis colunas quando houver espaço, quatro itens detalhados ocupam quatro colunas, e quantidades maiores quebram apenas quando a largura mínima não couber. O cabeçalho empilha título e atributos em telas estreitas.

Os separadores usarão `gap` de 1 px com a cor da borda como fundo da grade, evitando regras frágeis de `:last-child` quando houver quebra de linha.

## 11. Assets e compatibilidade

- CSS e JavaScript terão versões baseadas em `filemtime`.
- CSS do frontend será carregado somente em páginas de produto.
- JavaScript e CSS administrativos serão carregados somente na edição de produto.
- O JavaScript não dependerá de biblioteca externa além das já fornecidas pelo WordPress/WooCommerce para a tela.
- Não será usado TinyMCE nem `wp_editor()`, eliminando conflito de IDs e inicialização AJAX.
- Não haverá override de core, template ou arquivo do tema.

## 12. Migração legada

### 12.1 Forma de execução

A migração não rodará ao abrir uma página. Ela será um comando WP-CLI versionado, disponível somente quando `WP_CLI` estiver ativo, com operações explícitas:

```text
wp uonix ficha-tecnica migrate --dry-run
wp uonix ficha-tecnica migrate --execute
wp uonix ficha-tecnica migrate --rollback
```

`--dry-run` será o padrão quando nenhuma operação mutável for informada.

### 12.2 Reconhecimento

O parser usará DOM/XPath para reconhecer somente:

```text
.uonix-fichas-compactas
└── .uonix-ficha-compacta
    ├── .uonix-ficha-header
    ├── .uonix-medidas-grid
    └── .uonix-info-grid
```

Antes de executar, o relatório precisa confirmar:

- exatamente cinco variações candidatas;
- um título em cada ficha;
- seis pares na grade de medidas de cada ficha;
- quatro pares na grade de informações de cada ficha;
- nenhuma ficha já migrada com conflito;
- hash do conteúdo fonte de cada variação.

Qualquer divergência aborta toda a execução antes de gravar dados.

### 12.3 Conversão

Para cada variação:

1. criar backup do conteúdo integral da descrição;
2. registrar hash da origem, data, versão de migração e hash esperado após migração;
3. usar o título legado como título geral;
4. converter `.uonix-medidas-grid` em seção sem título, `compact`;
5. converter `.uonix-info-grid` em seção sem título, `detailed`;
6. ignorar o subtítulo legado e passar a derivá-lo dos atributos oficiais;
7. salvar o meta estruturado v1;
8. remover apenas o wrapper reconhecido da descrição;
9. preservar texto anterior ou posterior ao wrapper;
10. verificar por releitura que o meta e a descrição correspondem ao esperado.

Chave de backup:

```text
_uonix_variation_technical_sheet_legacy_backup_v1
```

A execução será transacional no nível lógico: se qualquer registro falhar, os registros já alterados na mesma execução serão restaurados dos backups antes de o comando encerrar com erro.

### 12.4 Idempotência e rollback

- executar novamente uma migração completa apenas relatará `já migrado`;
- rollback só ocorrerá quando os hashes atuais corresponderem ao estado pós-migração registrado;
- se alguém editar ficha ou descrição depois da migração, rollback recusará sobrescrever esse trabalho e indicará resolução manual;
- rollback restaura a descrição original e remove o meta estruturado criado pela migração;
- o backup só será removido em uma operação futura separada, depois da validação em produção.

## 13. CSS legado

O novo frontend usará classes diferentes e CSS versionado. O bloco legado do CSS adicional, identificado pelo comentário `UÔNIX - Ficha técnica compacta`, permanecerá durante a validação e não afetará o novo cartão.

Depois de confirmar migração e paridade visual em DEV/QA:

1. fazer backup do post `custom_css`;
2. remover somente o bloco legado identificado;
3. limpar cache;
4. validar produto variável no desktop e no mobile;
5. manter rollback do CSS disponível.

A remoção do CSS legado não precisa estar no mesmo PR da funcionalidade.

## 14. Tratamento de erros

| Situação | Comportamento |
|---|---|
| campo JSON ausente | não alterar o meta existente |
| JSON vazio, inválido ou com ação desconhecida | preservar meta e mostrar erro |
| `action: delete` válido | remover somente o meta da ficha |
| payload acima do teto | preservar meta e mostrar erro |
| layout desconhecido | rejeitar ficha inteira |
| item totalmente vazio | descartar o item |
| item parcialmente preenchido | rejeitar ficha inteira |
| seção sem itens válidos | descartar a seção |
| cópia de origem sem ficha | não alterar destino e avisar |
| destino já preenchido | pedir confirmação antes da cópia |
| usuário sem capacidade | negar renderização mutável/AJAX/save |
| parser legado divergente | abortar migração sem gravação |
| erro no meio da migração | restaurar alterações da execução |
| rollback após edição posterior | recusar para não sobrescrever trabalho |

## 15. Verificação

### 15.1 CI versionado

Adicionar teste PHP de contrato ao padrão de `scripts/tests/` e registrá-lo em `.github/workflows/validate.yml` para provar:

- carregamento pelo módulo;
- registro dos hooks com argumentos corretos;
- normalização do esquema v1;
- tetos de segurança;
- descarte de vazios e rejeição de linha parcial;
- layouts permitidos;
- escapamento do renderer;
- descrição livre independente;
- subtítulo com valores oficiais;
- parser da estrutura legada;
- idempotência e pré-condições do rollback.

Os gates existentes executarão `php -l` em PHP 8.3 e PHP 8.5. O workflow também executará `node --check` no novo JavaScript administrativo. `test-ci-covers-all-tests.sh` deverá reconhecer o teste novo como coberto.

### 15.2 WordPress/WooCommerce local real

Verificar no clone local:

1. criar ficha em variação existente;
2. salvar, recarregar e comparar o meta;
3. adicionar variação individualmente;
4. gerar variações a partir de atributos;
5. navegar entre páginas de variações por AJAX;
6. reordenar seções e itens;
7. copiar ficha de variação em outra página;
8. confirmar aviso antes de sobrescrever destino preenchido;
9. remover ficha e salvar;
10. preservar descrição livre antes e depois da ficha;
11. testar usuário não administrador com permissão de produto;
12. negar usuário sem permissão;
13. selecionar cada variação no frontend e conferir troca do cartão;
14. conferir exatamente o padrão de seis colunas compactas e quatro detalhadas;
15. conferir outras quantidades de itens;
16. conferir caracteres como `·`, `×`, aspas, frações e unidades;
17. inserir tentativa de HTML/script e confirmar escapamento;
18. validar desktop e viewport móvel;
19. confirmar que preço, estoque, dimensões, imagem e atributos continuam salvando;
20. confirmar que ordenação e remoção nativas de variações continuam funcionando.

### 15.3 Migração

1. executar dry-run e arquivar o relatório das cinco fichas;
2. executar em cópia local;
3. comparar dados estruturados com o HTML original;
4. validar frontend das cinco variações;
5. executar rollback e comparar hashes com o estado inicial;
6. executar novamente e confirmar idempotência;
7. repetir a migração final no local;
8. só então executar em DEV com backup.

### 15.4 Prova de validade

Os testes focados devem falhar em duas mutações independentes: remover temporariamente o hook de salvamento e remover temporariamente o filtro de frontend. Cada teste deve voltar a passar após a restauração. As mutações não serão commitadas.

Fixtures, variações filhas, produtos de teste e arquivos temporários serão removidos mesmo quando o teste falhar. Uma consulta final verificará que não restaram variações órfãs.

## 16. Rollout

1. manter a regra de preço padrão em alteração/PR separado;
2. implementar a ficha em branch e PR próprios;
3. executar CI e testes locais;
4. migrar somente o ambiente local;
5. validar visual e funcionalmente;
6. publicar em DEV;
7. executar `--dry-run` em DEV e guardar o relatório;
8. criar backup e executar migração em DEV;
9. solicitar validação humana do produto variável;
10. promover aos ambientes seguintes apenas com os gates normais verdes;
11. não usar bypass de CI;
12. remover o CSS legado apenas depois da paridade confirmada.

## 17. Critérios de aceitação

A solução estará pronta quando:

- usuários autorizados criarem a ficha sem escrever HTML;
- descrição livre e ficha coexistirem;
- houver seções ilimitadas no uso normal, com modos compacto e detalhado;
- títulos de seção vazios não produzirem faixa extra;
- o cartão reproduzir o padrão visual aprovado;
- textos dos inputs forem escuros e legíveis;
- o cabeçalho usar `Modelo`, `Material` e `Pol.` com valores oficiais;
- novas variações começarem sem ficha e puderem copiar outra explicitamente;
- variações carregadas por AJAX funcionarem;
- nenhum template do WooCommerce for sobrescrito;
- as cinco fichas forem migradas sem perda de texto livre;
- dry-run, execução, idempotência e rollback forem comprovados;
- CI PHP 8.3/8.5 e os demais gates estiverem verdes;
- teste local real e prova de mutação passarem;
- não restarem fixtures ou arquivos temporários;
- validação visual em DEV for aprovada antes de qualquer promoção posterior.
