# Baseline da saúde do WordPress — execução inicial

**Status:** diagnóstico inicial concluído; nenhuma mutação remota foi executada.

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
| limpeza de cache por GET sem nonce | **Defeito confirmado no código versionado** | corrigido localmente na branch isolada; não publicado |
| REST anônimo `context=edit` 401 | **Esperado, sujeito a confirmação autenticada** | não abrir correção com base apenas no request anônimo |
| HTTPS interno, frete, Action Scheduler, PHP em uploads, plugins/temas, MySQL 5.7 e cache de objeto | **Pendentes** | exigem as ondas específicas e seus gates; nenhuma alteração foi feita nesta etapa |

## 5. Primeira correção local — Onda A preparada

A causa local confirmada era uma ação administrativa mutante acessível por query string dentro de `uox_render_manutencao_cache()`. A correção foi implementada somente no worktree:

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

Essas evidências são locais. Elas não autorizam deploy, atualização remota, flush de cache em QA/produção ou merge.

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

## 7. Próximos gates

1. Executar a suíte local aplicável e o guard `test-ci-covers-all-tests.sh`; separar falhas preexistentes de regressões.
2. Rodar scan de segurança no diff e revisão independente em contexto limpo, com SHA/patch congelados.
3. Se aprovado, criar PR da branch baseada em `origin/dev`; CI deve passar no SHA exato.
4. Somente após autorização específica da onda: promover para o QA funcional, criar backup quando houver mutação remota, testar como editor e usuário sem capability e ler o estado de volta.
5. Produção permanece bloqueada até revisão, CI, QA, rollback e autorização explícita.

Nenhum dado pessoal de produção, cookie completo, segredo ou token faz parte deste documento.
