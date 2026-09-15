# Baseline da saúde do WordPress — execução inicial

**Status:** baseline inicial preservado; Onda A publicada e validada; Onda B revalidada em contexto web e classificada.

> Nota temporal: as seções 1–6 preservam a coleta inicial e o gate local anterior à publicação. A seção 7 registra a promoção posterior da Onda A; a seção 8 registra a Onda B. Assim, referências a estado “local” abaixo descrevem aquele momento, não o estado atual dos ambientes.

**Data da coleta HTTP:** `2026-09-14T14:59:59Z` (UTC)

**Código avaliado:** branch `fix/wordpress-health-remediation`, criada de `origin/dev`.

**Ambientes públicos:**

- Produção: `https://uonix.com.br`
- QA funcional: `https://uonix.ksio.dev`
- `https://qa.uonix.com.br` não foi usado como QA funcional porque o hostname/vhost/certificado continuam inadequados.

## 1. Proveniência e escopo

A coleta desta etapa foi somente leitura e não usou autenticação, cookie jar, cache-buster ou payload mutante. Foram preservados apenas resultados sanitizados: nomes de cookies, códigos HTTP, cabeçalhos de cache e tempos; valores completos de `Set-Cookie`, tokens, e-mails e credenciais não foram registrados.

Fontes:

- `wp-project-triage`, `detect_wp_project.mjs`, versão `0.1.0`;
- `detect_plugins.mjs`, versão `0.1.0`;
- cinco skills copiadas byte a byte de `WordPress/agent-skills` no commit `d87ee6916e740c7960b6959220c0481a41b320c7`: 39 arquivos comparados, sem diferença; os 25 avisos de linha em branco no EOF emitidos por `git diff --check` estão exclusivamente nesses arquivos vendorados e foram preservados para não invalidar a cópia/hash upstream; o mesmo check excluindo `.hermes/skills/**` terminou com exit 0;
- três requisições sequenciais por URL, seguindo redirects;
- evidências remotas read-only de Site Health/WP Doctor já registradas no plano.

## 2. Triagem determinística do repositório

Os dois inspectores terminaram com exit code `0` e JSON válido. O resultado oficial foi:

- projeto: `unknown`;
- plugins detectados pelo inspector: `0`;
- `usesWpCli`: `true`;
- nenhum `composer.json`, `vendor`, `package.json`, PHPUnit, `wp-env`, Playwright ou Jest reconhecido pelo inspector;
- usos de WP-CLI encontrados nos scripts: export/import de banco, `search-replace` e `cache flush`.

### Limitação conhecida

O detector procura a convenção de um checkout WordPress com `wp-content/plugins`, `wp-content/themes` e `wp-content/mu-plugins`. O repositório Uônix é híbrido e mantém código gerenciado na raiz (`mu-plugins/`, `themes/` e `scripts/`). Portanto, `unknown` e `0 plugins` são limitações de classificação, não evidência de ausência de plugins ou MU plugins.

A suíte real também não é descoberta pelo inspector: ela é composta por testes PHP, Bash, Node e Python explicitamente listados em `.github/workflows/validate.yml`. A negativa do detector não deve ser usada para remover esses testes nem para concluir que não há cobertura.

## 3. Baseline HTTP público

Foram executados **18 GETs** — três amostras para cada uma das seis URLs — sem autenticação. Todos os requests terminaram na URL solicitada com HTTP `200`; não houve erro de transporte.

| Ambiente | URL | Mediana TTFB (s) | Status | Cookies observados | Cache |
|---|---|---:|---:|---|---|
| Produção | `/` | 1,979746 | 200 | `PHPSESSID` | `x-cache: MISS` |
| Produção | `/produtos/` | 1,865613 | 200 | `PHPSESSID` | `x-cache: MISS` |
| Produção | `/wp-json/` | 1,095340 | 200 | `PHPSESSID` | `x-cache: MISS` |
| QA funcional | `/` | 0,434784 | 200 | `PHPSESSID` | `x-cache`/`age` ausentes |
| QA funcional | `/produtos/` | 0,427070 | 200 | `PHPSESSID` | `x-cache`/`age` ausentes |
| QA funcional | `/wp-json/` | 0,263769 | 200 | `PHPSESSID` | `x-cache`/`age` ausentes |

Em todas as amostras:

```text
Cache-Control: no-store, no-cache, must-revalidate
Pragma: no-cache
Expires: Thu, 19 Nov 1981 08:52:00 GMT
```

Na produção, o header adicional observado foi `x-cache: MISS`. O resultado confirma que as rotas públicas não estão sendo servidas como HIT de cache de página nesta coleta e que uma sessão PHP é emitida globalmente. Ele não substitui o teste funcional autenticado do RFQ nem prova, sozinho, qual componente inicia a sessão; a causa já foi rastreada no diagnóstico anterior ao Woo RFQ configurado com `php_session`.

### Limites da medição

- três amostras por rota não são o baseline de performance final de dez amostras com p95;
- não foram testados usuário autenticado, carrinho, checkout, orçamento ou filtros;
- TTFB depende do horário e da rede e deve ser repetido depois de cada mudança de cache/sessão;
- `200` não comprova correção de negócio, persistência do orçamento ou entrega de e-mail.

## 4. Achados classificados nesta etapa

| Achado | Classificação nesta etapa | Evidência/ação |
|---|---|---|
| `PHPSESSID` nas páginas públicas | **Confirmado** | apareceu nas 18 respostas; causa RFQ `php_session` já rastreada; remediação fica na onda RFQ/cache |
| `no-store/no-cache` global | **Confirmado** | cabeçalhos repetidos em produção e QA; compatível com a sessão nativa e impeditivo para HIT público |
| `x-cache: MISS` em produção | **Confirmado** | repetido nas três rotas públicas; não é medição de HIT |
| detector `unknown`/zero plugins | **Limitação de ferramenta** | layout híbrido da Uônix não é reconhecido; não alterar o repositório para satisfazer o detector |
| limpeza de cache por GET sem nonce | **Defeito confirmado no código versionado** | corrigido inicialmente na branch isolada; publicação posterior registrada na seção 7 |
| REST anônimo `context=edit` 401 | **Esperado, sujeito a confirmação autenticada** | não abrir correção com base apenas no request anônimo |
| HTTPS interno, frete, Action Scheduler, PHP em uploads, plugins/temas, MySQL 5.7 e cache de objeto | **Pendentes naquele baseline** | HTTPS/frete foram reclassificados na seção 8; os demais seguem para suas ondas específicas |

## 5. Primeira correção local — histórico pré-publicação da Onda A

A causa local confirmada era uma ação administrativa mutante acessível por query string dentro de `uox_render_manutencao_cache()`. A correção foi implementada inicialmente no worktree e depois promovida conforme a seção 7:

- `mu-plugins/uonix-admin/39-admin-editor-dashboard.php`
  - registra `admin_post_uonix_flush_cache`;
  - rejeita qualquer `REQUEST_METHOD` diferente de POST antes de autorização, nonce e efeitos colaterais;
  - exige `current_user_can( 'edit_posts' )`;
  - valida `check_admin_referer( 'uonix_flush_cache' )`;
  - executa `wp_cache_flush()` e `rocket_clean_domain()` somente se existir;
  - usa `wp_safe_redirect()` com `uonix_cache_flushed=1`;
  - substitui o link por formulário POST com action oculta e nonce;
  - renderer não executa mutação.
- `scripts/tests/test-admin-cache-security.php`
  - cobre GET sem efeito colateral;
  - cobre GET autenticado com action e nonce válidos no handler `admin_post`;
  - handler registrado;
  - POST autorizado e redirect;
  - nonce inválido fail-closed;
  - usuário sem `edit_posts` bloqueado;
  - formulário e aviso de sucesso.
- `.github/workflows/validate.yml`
  - adiciona step explícito para o novo teste, preservando o guard de cobertura do CI.

### Evidência TDD

- o teste inicial contra o código original terminou com exit `1` por limpeza no GET;
- o teste do contrato `admin_post` terminou com exit `1` antes do handler existir;
- após a implementação: `php -l` nos dois arquivos e o teste focal terminaram com exit `0`;
- mutação temporária removendo a validação do nonce fez o teste terminar com exit não zero;
- mutação temporária removendo a capability fez o teste terminar com exit não zero;
- as duas mutações foram restauradas e o teste focal voltou a terminar com exit `0`.

Nesse gate, as evidências ainda eram locais e não autorizavam deploy, atualização remota, flush de cache em QA/produção ou merge. A promoção posterior só ocorreu após os gates independentes registrados na seção 7.

## 6. Gate local executado

Resultados frescos desta execução:

- testes PHP: `39/39` pass;
- testes Node (`.js`/`.mjs`): `9/9` pass;
- testes Python de migração de e-mail: `11/11` pass;
- testes Bash: `35/35` pass na repetição integral sem exclusões;
- lint PHP: `103` arquivos gerenciados + o novo teste, sem erro de sintaxe;
- guard de cobertura: `85` testes presentes, todos referenciados e efetivamente executados pelo CI;
- YAML do workflow: parse válido; `actionlint` disponível e sem saída de erro.

Retificação: `CURL_LOG` não apontava para um diretório removido pelo código sob teste. O timeout encerrava o processo e o `trap` do fixture limpava seu diretório temporário. A causa real era `scripts/tests/test-post-clone-core-validation.sh` executar três cenários negativos com o backoff de produção de 195 s por cenário. O fixture agora simula `sleep`; `scripts/tests/test-clone-smoke-resilience.sh` continua validando especificamente 6 esperas e total de 195 s. Após a correção, o teste focal passou em 0,744 s, o teste específico de backoff passou e `shellcheck` passou; a bateria Bash completa passou 35/35.

### Revisão independente do método HTTP

O reviewer `openai-codex`/`gpt-5.6-sol` rejeitou o patch inicial no SHA-256 `9514f140331430ad3fcf93466b536808a101d317bcad9f2d61b9026604ca8eb4`: `admin-post.php` despacha a action a partir de `$_REQUEST`, e `check_admin_referer()` também aceita nonce vindo de `$_REQUEST`; sem verificar `REQUEST_METHOD`, um GET autenticado com nonce válido ainda alcançava os flushes.

O achado foi reproduzido no harness: o novo caso GET acionou flush e nonce e terminou em RED. A correção rejeita método diferente de POST antes de capability, nonce e efeitos colaterais. O teste focal voltou a passar, e uma cópia mutante sem a guarda terminou com exit 1 no caso GET.

A nova revisão independente Sol/xhigh aprovou o patch de código no SHA-256 `8c71576e3287a1e59d7ef41184b378b99cfed9eb213c5b6edb243504c0f81e63` e o diff staged no SHA-256 `b932707f8987e112afacf40d9727b67bf97b2f1b9260604ed13c51e4d6c331f3`, sem achados de segurança ou lógica. O reviewer executou com exit 0 o teste de cache e os dois testes shell relacionados, confirmou que os hashes não mudaram durante a janela e deixou apenas sugestões não bloqueantes de cobertura adicional.

## 7. Gates executados posteriormente na Onda A

1. A suíte local e o guard `test-ci-covers-all-tests.sh` passaram sem exclusões: PHP 39/39, Bash 35/35, Node 9/9, Python 11/11 e cobertura CI 85/85.
2. A revisão independente final aprovou o método POST, capability e nonce, incluindo prova por mutação.
3. A PR #177 foi validada no run `34866484799` e mergeada em `dev`; Validate `34868084449` e Deploy Development `34868084764` terminaram com sucesso.
4. A PR #178 foi validada no run `34869195454` e mergeada em `qa`; Validate `34869671483`, Deploy QA `34869672156` e probe real 5/5 terminaram com sucesso.
5. A PR #179 foi validada no run `34871004726` e mergeada em `master`; Validate `34871418997` terminou com sucesso. Após autorização explícita para o SHA `f2807587ed35b54278fce78efd9123c571e22428`, o Deploy Production `34872661206` concluiu backup, publicação, manifesto, cache e smoke com sucesso; o readback confirmou probe 5/5, checksum correspondente, lock ausente e zero resíduos transitórios.

Nenhum dado pessoal de produção, cookie completo, segredo ou token faz parte deste documento.

## 8. Onda B — Site Health, REST, RFQ e falsos positivos

**Fechamento da coleta:** `2026-09-14T18:16:17Z` (UTC).

Esta etapa combinou probes sanitizados por WP-CLI, requisições HTTP públicas, uma requisição HTTPS autenticada real à tela de Saúde do Site em cada ambiente e um RFQ sintético no QA. Os cookies e tokens usados para a tela administrativa nunca foram impressos: cada sessão tinha expiração de cinco minutos, foi revogada pelo token exato e o readback terminou com zero sessões de curta duração e zero probes remotos.

### 8.1 Site Health no request HTTPS real

| Ambiente | HTTP do painel | Diretos `good` | Diretos `recommended` | Diretos `critical` | HTTPS WooCommerce | REST | Authorization |
|---|---:|---:|---:|---:|---|---|---|
| QA | 200 | 25 | 4 | 3 | `good` | `good` | `good` |
| Produção | 200 | 27 | 4 | 3 | `good` | `good` | `good` |

Críticos reais nos dois ambientes: `php_sessions`, `theme_version` e `woocommerce_shipping_methods`. Recomendações na coleta final:

- QA: `plugin_version`, `scheduled_events`, `search_engine_visibility` e `sql_server`;
- produção: `persistent_object_cache`, `plugin_version`, `scheduled_events` e `sql_server`.

`scheduled_events` alternou entre `good` e `recommended` em execuções próximas no QA. Portanto, não foi declarado resolvido nem tratado como falha determinística; segue para observação e causa raiz na Onda E.

O checkout gerado por WooCommerce usa HTTPS nos dois ambientes. O crítico “conexão insegura” só apareceu no processo WP-CLI sem `HTTPS`/`X-Forwarded-Proto`; no painel web real o teste `woocommerce_secure_connection` retornou `good`. A classificação final de `is_ssl() = false` no CLI é **falso positivo de contexto**, sem mudança de proxy ou opções.

### 8.2 REST e cabeçalho Authorization

- `/wp-json/` e `/wp-json/wp/v2/types/post` retornaram HTTP 200 anonimamente em QA e produção;
- `context=edit` anônimo retornou HTTP 401 com `rest_forbidden_context`, como esperado;
- o mesmo contexto administrativo retornou HTTP 200 com usuário autenticado no processo e o teste `rest_availability` do painel web real retornou `good` nos dois ambientes;
- a regra de encaminhamento de `Authorization` existe no `.htaccess`; uma requisição Basic fictícia chegou ao autenticador do WordPress, e o teste assíncrono oficial do painel, executado com cookie e nonce válidos, retornou `authorization_header=good` em QA e produção.

Assim, REST anônimo `401` para `context=edit`, REST `recommended` no WP-CLI sem cookie e Authorization `recommended` no WP-CLI sem header são **resultados esperados/falsos positivos de execução**, não defeitos do site.

### 8.3 RFQ positivo em QA e limpeza

O plugin `woo-rfq-for-woocommerce` estava ativo na versão `2.4.14`, com atualização `2.4.15` disponível, e configurado em modo `php_session`. O teste real no navegador confirmou:

- carrinho iniciado vazio, produto adicionado e quantidade `1` preservada;
- `/cotacao/` e `/finalizar-orcamento/` carregadas sem preço, moeda ou cobrança;
- Turnstile habilitado e resposta não vazia antes do envio, sem registrar seu valor; isso comprova a presença do controle no fluxo testado, mas não prova isoladamente a validação server-side nem a inexistência de eventual caminho fail-open;
- consentimento obrigatório marcado e newsletter desmarcada;
- uma única submissão criou o RFQ sintético `11365`, status `gplsquote-req`, método interno `gpls-rfq`, um item/uma unidade, `is_paid=false`, `needs_payment=false` e transação vazia;
- o usuário confirmou o recebimento do e-mail com prefixo `[QA]` na caixa segura, sem compartilhar o conteúdo.

O log automático do observador transitório não é usado como prova: um trap fail-closed o removeu antes da leitura após um erro no probe de limpeza. A entrega é classificada separadamente como **confirmação externa do usuário**. O pedido foi identificado por ID, e-mail reservado `example.com`, marcador, status, idade e ausência de pagamento antes da exclusão.

Readback final do QA:

- RFQs agregados: `2 → 3 → 2`;
- pedido `11365`: ausente;
- pedidos e usuários com o e-mail sintético: zero;
- observador e log remoto: ausentes;
- ação `2964` (`woocommerce_delete_legacy_report_transients`) criada pela exclusão: `complete`;
- nenhuma ação pendente de e-mail foi identificada.

### 8.4 Sessão PHP, cache e atributos do cookie

Requisições frescas à homepage, catálogo e finalização confirmaram `PHPSESSID` e cache impedido globalmente:

```text
Cache-Control: no-store, no-cache, must-revalidate
Pragma: no-cache
Expires: Thu, 19 Nov 1981 08:52:00 GMT
```

- QA: `PHPSESSID` com `Secure` e `HttpOnly`, mas sem `SameSite`; Cloudflare `DYNAMIC`;
- produção: `PHPSESSID` sem `Secure`, `HttpOnly` ou `SameSite`; `x-cache: BYPASS`;
- amostra pública desta rodada: QA `/` 0,986 s e `/produtos/` 0,682 s; produção `/` 2,502 s e `/produtos/` 2,242 s. São amostras diagnósticas, não o p95 da Onda F.

O defeito de sessão/cache está **confirmado**, mas não será corrigido removendo o cookie às cegas: o teste RFQ provou dependência de persistência. A Onda D deve migrar/endurecer a estratégia sem quebrar o orçamento; a Onda F mede cache e p95 depois disso.

### 8.5 Matriz operacional final da Onda B

| Achado | Classificação final | Decisão |
|---|---|---|
| WooCommerce “sem HTTPS” apenas no WP-CLI | **Falso positivo** | nenhuma mudança; painel real e checkout estão em HTTPS |
| REST `context=edit` anônimo 401 | **Esperado** | manter proteção; autenticado retorna 200 |
| Authorization ausente apenas no WP-CLI | **Falso positivo** | teste HTTP oficial retornou `good`; nenhuma mudança no `.htaccess` |
| `PHPSESSID` global + `no-store` | **Defeito confirmado** | tratar nas Ondas D/F preservando o RFQ |
| Atributos do `PHPSESSID` em produção | **Defeito confirmado** | endurecer `Secure`, `HttpOnly`, `SameSite` e estratégia de sessão na Onda D |
| Zero métodos de frete com 22 produtos físicos | **Dependente de decisão comercial** | o RFQ funciona sem preço/pagamento; não criar frete gratuito ou fictício. Só implementar “a combinar” após definição explícita de texto, zonas e e-mail |
| Tema/plugins atualizáveis | **Defeito de manutenção** | Onda D, em lotes e primeiro no QA |
| `scheduled_events` intermitente | **Defeito operacional a confirmar** | Onda E e observação de 24 h |
| Cache persistente/TTFB | **Defeito ou capacidade de host** | Onda F |
| MySQL 5.7 | **Débito de infraestrutura** | Onda G, condicionado ao host/janela/rollback |
| Site Kit | **Sem ação nesta onda** | rota autenticada em produção HTTP 200; nenhuma tag gerida pelo Site Kit, coerente com GTM customizado; rota não registrada no QA |
| WordPress/PHP, uploads habilitados, debug, tabelas WooCommerce, HPOS, overrides e autoload | **Aprovados** | checks `good`; não entram em remediação na Onda B |

Não houve alteração de conteúdo, pedido ou e-mail em produção. Os efeitos da validação em QA foram: sessões administrativas temporárias, o RFQ sintético, o observador/log transitório, o e-mail entregue à caixa segura e a ação `2964` criada pela exclusão. As sessões foram revogadas; RFQ, usuário sintético, observador e log foram removidos; a ação `2964` terminou `complete`. O e-mail permaneceu somente na caixa segura como evidência externa confirmada pelo usuário.

## 9. Baseline reproduzível das Ondas D/F — sessão e cache HTTP

**Coleta:** `2026-09-14T20:59:50.784888Z` (UTC).

Foram executados 50 GETs anônimos e independentes em produção, dez por rota, sem cookie jar e sem submissão de formulário. Redirects same-site foram seguidos. A primeira amostra foi separada das nove seguintes, tratadas apenas como candidatas aquecidas — nenhum purge foi feito, portanto “primeira observada” não equivale a cache comprovadamente frio. As 50 respostas finais tiveram HTTP 200, corpo não vazio e zero falha de transporte.

| Rota | Primeira TTFB (ms) | Mediana TTFB amostras 2–10 (ms) | p95 TTFB amostras 2–10 (ms) | Cookie observado |
|---|---:|---:|---:|---|
| `/` | 5505,928 | 2696,050 | 3605,237 | `PHPSESSID` |
| `/fixacao-quimica/` | 2361,339 | 2612,576 | 2868,776 | `PHPSESSID` |
| `/produtos/sapatilha-para-cabo-de-aco/` | 2720,166 | 2815,616 | 4457,341 | `PHPSESSID` |
| `/cotacao/` | 2571,393 | 2662,197 | 2877,754 | `PHPSESSID` |
| `/finalizar-orcamento/` com carrinho vazio | 4182,722 | 4531,735 | 6265,399 | `PHPSESSID` |

Resultados de cache e sessão:

- homepage, categoria e produto: 10/10 `Cache-Control: no-store, no-cache, must-revalidate` e 10/10 `x-cache: MISS`;
- cotação: 10/10 `Cache-Control: no-cache, must-revalidate, max-age=0, private` e 10/10 `x-cache: MISS`;
- finalização com carrinho vazio: 10/10 respondeu primeiro 302 e terminou em `/cotacao/` com HTTP 200; 10/10 `x-cache: MISS` e controle `private/no-cache`;
- `Age` e qualquer sinal de HIT estiveram ausentes nas 50 respostas;
- `PHPSESSID` foi emitido nas 50 respostas. O único atributo observado no `Set-Cookie` foi `Path`; `Secure`, `HttpOnly` e `SameSite` estavam ausentes.

Classificação: a sessão PHP global continua sendo defeito confirmado da Onda D e impede cache anônimo efetivo. As três rotas públicas cacheáveis excederam amplamente o alvo p95 de 600 ms, logo a Onda F ainda não atende seu aceite. A rota de finalização acima mede somente o redirect de carrinho vazio e **não** substitui um checkout/RFQ funcional com sessão, que deve ser exercitado no QA após migrar a estratégia oficial do plugin.

## 10. Inventário de runtime/cache — decisão de capacidade da Onda F

**Coleta somente leitura:** 2026-09-14. Os JSONs brutos ficam fora do repositório em `/tmp/uonix-df-prod-inventory/` e `/tmp/uonix-df-qa-inventory/`; sua estrutura foi validada com `python3 -m json.tool`.

| Ambiente | `advanced-cache.php` | `object-cache.php` | `WP_CACHE` | Redis/Memcached/APCu | Classe observada | Classificação |
|---|---|---|---|---|---|---|
| Produção | ausente | ausente | indefinida | `false` / `false` / `false` | `WP_Object_Cache`; estado externo `null` | **cache de página ausente; cache de objeto persistente indisponível no runtime observado** |
| QA funcional | ausente | ausente | indefinida | Redis/APCu `true`; Memcached `false` | `WP_Object_Cache`; estado externo `null` | **cache de página ausente; cache de objeto persistente inativo e não justificado** |

Em produção, a extensão `Zend OPcache` estava habilitada. Ela acelera bytecode no processo PHP, mas não é cache de objeto persistente entre requisições e não atende o critério de cache de objeto desta onda. A tentativa de sondar extensões diretamente no PHP de produção teve `SIGSEGV`; a classificação de Redis/Memcached/APCu acima vem da execução posterior corrigida do inventário, não desse processo interrompido. Em QA, Redis/APCu carregados só provam que o PHP pode falar com esses mecanismos; sem drop-in, endpoint, autenticação/configuração e readback entre requisições, não provam um cache persistente ativo.

### Decisão técnica de preparação — sem mutação

- O candidato de cache de página para ensaio em QA é **WP Super Cache em modo Simple**, pois não exige regras adicionais no `.htaccess`; o modo Expert está descartado para este ensaio por editar regras Apache.
- O ensaio deve excluir por URI as páginas de cotação, finalização e conta/endpoints, e por cookie toda sessão WooCommerce (`woocommerce_cart_hash`, `woocommerce_items_in_cart`, `wp_woocommerce_session_*`) e toda sessão RFQ: tanto o legado `PHPSESSID` quanto o backend candidato `rfqtk_wp_session_*`. O sufixo do último é configurável pelo plugin; a regra deve casar o prefixo. A exclusão por cookie é obrigatória mesmo em URL pública: um orçamento pode iniciar em outra página.
- `DONOTCACHEPAGE`/hook tardio sozinho não é uma proteção suficiente para um arquivo já servido antes do bootstrap; a configuração de exclusões do motor e a prova HTTP por matriz de cookies/URI são o aceite.
- Cache de objeto não será instalado por plugin de filesystem para “zerar” o diagnóstico. Sem serviço persistente de host comprovado, a classificação de produção permanece **indisponível** e a de QA permanece **não justificada/inativa**, apesar das extensões carregadas.

### Gate HTTP preparado para o ensaio QA

O verificador local `/tmp/uonix-assert-page-cache-qa.py` faz somente GETs, não mantém cookie jar e usa valores sintéticos para `PHPSESSID`, `rfqtk_wp_session_*`, `woocommerce_items_in_cart` e `wp_woocommerce_session_*`. No modo de aceite ele exige marcador `WP-Super-Cache` apenas na segunda requisição anônima de homepage/produto e o proíbe em cotação, finalização e em qualquer requisição com os cookies sintéticos. Assim, testa a sessão iniciada fora dessas páginas, que é o caso que uma exclusão só por URI deixaria escapar.

### Gate de promoção do backend RFQ

O candidato versionado de sessão em cookie usa o filtro de pré-opção do WordPress para manter `rfq_cookie` de forma uniforme — página, AJAX, cron e CLI devem usar o mesmo backend, pois alternar com `php_session` separaria o estado do mesmo orçamento. A política possui rollback operacional: `uonix_rfq_cookie_session_enabled=0` devolve imediatamente a opção persistida `php_session`; `UONIX_RFQ_COOKIE_SESSION_ENABLED=false` em `wp-config.php` tem precedência para contingência sem depender do painel.

Antes de qualquer promoção para `master`, o aceite obrigatório é: (1) testar o módulo no ambiente de ensaio com o plugin RFQ real, (2) provar que uma cotação iniciada em uma requisição é reencontrada na próxima pelo cookie `rfqtk_wp_session_*`, (3) provar que não há `PHPSESSID` nem `session_start()` no caminho cookie, (4) testar a transição de visitante que já possua a sessão PHP legada e classificar explicitamente o tratamento — continuidade, aviso/limpeza ou rollback — e (5) exercitar o rollback pela opção, seguido de releitura. O ensaio feito apenas alterando a opção oficial em QA já demonstrou ausência de `PHPSESSID` em GETs públicos e preservação de uma cotação cookie; ele não substitui esse aceite do módulo versionado.

O teste de pré-mudança foi deliberadamente vermelho, com três bloqueios esperados: homepage e produto sem marcador de cache e ausência de URL de conta. A leitura oficial de QA retornou `woocommerce_cart_page_id=593`, `woocommerce_checkout_page_id=597` e `woocommerce_myaccount_page_id=0`; não há página de conta atribuída para validar. Em 2026-09-14, o controlador decidiu manter a funcionalidade de conta fora do produto e exigir exclusão preventiva de rotas de conta no cache. Portanto, o ensaio QA deverá ler a configuração do motor e provar rejeição explícita para os padrões de URI de conta adotados; criar uma página de conta apenas para produzir um teste não é permitido.

Nenhum plugin, drop-in, regra de Apache, configuração de CDN ou opção WordPress foi alterado por este inventário. O aceite da Onda F continua pendente: cache seguro para carrinho/checkout/conta/RFQ, matriz HTTP de exclusões, p95 final `<600 ms` nas rotas cacheáveis e classificação final de cache de objeto em QA e produção.
