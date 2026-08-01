# Runbook de migração e operação — Locaweb / HostGator

> Documento operacional verificado em execução real (2026-07-29 a 2026-08-01).
> Não contém credenciais. Nomes de Secrets/Variables aparecem, valores nunca.

## 1. Fronteira operacional

| Ambiente | URL | Host | Branch |
| --- | --- | --- | --- |
| Produção provisória | `site.uonix.com.br` | Locaweb | `master` |
| QA | `uonix.ksio.dev` | HostGator | `qa` |
| DEV | `test.uonix.ksio.dev` | HostGator | `dev` |
| Local | `localhost:8080` | Podman | `local` |

**`uonix.com.br` é o site antigo, em produção real, e está intocável.** Enquanto o
site novo não estiver nesse domínio, `site.uonix.com.br` não é produção definitiva:
desenvolvimento e teste nele são liberados.

O cutover para `uonix.com.br` é operação separada, com runbook e aprovação próprios.
**A migração de e-mails ocorre ANTES do cutover** — pré-requisito, não tarefa paralela.

## 2. Convergência entre branches

Convergência se afere por **tree hash**, nunca por contagem de commits:

```bash
for b in master qa dev; do
  echo "$b: $(git rev-parse origin/$b^{tree})"
done
```

Os três devem exibir o mesmo hash. Divergência após merge em `master` é normal e se
resolve com PRs de alinhamento (`integration/<branch>-align-master-N`).

## 3. Guards de deploy

Três Variables controlam a publicação, todas `false` por padrão:

| Variable | Ambiente |
| --- | --- |
| `ENABLE_DEPLOY_DEVELOPMENT` | DEV |
| `ENABLE_DEPLOY_QA` | QA |
| `ENABLE_DEPLOY_PRODUCTION` | produção provisória |

**Regra fixa: ativar um guard por vez**, com dry-run, evidência e rollback
documentado antes de passar ao próximo. Ordem de menor risco: DEVELOPMENT → QA →
PRODUCTION. Ativação em cascata é proibida.

Com o guard em `false`, o job `authorize` aborta antes de qualquer contato remoto —
comprovado no run `30676363535`: zero ocorrências de `ssh`, `rsync` ou `sshpass` no
log; apenas comparação de variáveis.

Produção exige ainda a frase exata de confirmação:

```
PUBLICAR <sha-de-40-caracteres> EM SITE.UONIX.COM.BR
```

## 4. Secrets e Variables

Secrets vivem em **Environments**, não no repositório. Reusable workflows **não**
herdam secrets automaticamente: o chamador precisa de `secrets: inherit`. A ausência
disso fez `secrets.HOSTGATOR_*` chegarem vazios e abortarem o primeiro deploy real
(run `30670232135`). Corrigido no PR #12; guardado por
`scripts/tests/test-reusable-workflow-secrets.sh`.

| Environment | Secrets (nomes) |
| --- | --- |
| `development-hostgator` | `HOSTGATOR_SSH_PRIVATE_KEY`, `HOSTGATOR_SSH_KNOWN_HOSTS`, `UONIX_TURNSTILE_*` |
| `qa-hostgator` | idem |
| `production-locaweb` | `LOCAWEB_SSH_PASSWORD`, `LOCAWEB_SSH_KNOWN_HOSTS`, `LOCAWEB_FTP_PASSWORD`, `UONIX_TURNSTILE_*` |
| `clone-operations` | união dos acima |

A Locaweb autentica por **senha** (`LOCAWEB_SSH_PASSWORD`), não por chave. O HostGator
usa chave (`HOSTGATOR_SSH_PRIVATE_KEY`).

### Variables órfãs

`PRODUCTION_SSH_HOST`, `PRODUCTION_SSH_USER`, `PRODUCTION_PATH`, `STAGING_SSH_HOST`,
`STAGING_SSH_USER` e `STAGING_PATH` **não são referenciadas por nenhum workflow**
(verificado por `grep -rn 'vars\.' .github/workflows/`). São resquício de topologia
anterior — `PRODUCTION_SSH_HOST` aponta para o HostGator, enquanto a produção atual
está na Locaweb. Não confiar nelas; candidatas a remoção.

## 5. Janela SSH da Locaweb

Habilitada sob demanda no painel, com duração de 3 horas e renovação manual.

**A sonda TCP não serve para verificar o estado da janela.** Medido: com o SSH
desabilitado, `ftp.site.uonix.com.br:22` continua retornando banner
`SSH-2.0-OpenSSH_8.0`, e um teste sem credencial devolve
`Permission denied (publickey,password)` — mensagem idêntica à do HostGator, que
está ativo. Só o preflight do workflow, que autentica com o secret e executa
comandos remotos, distingue janela aberta de fechada.

## 6. Limitação de rede do HostGator

O HostGator (`108.179.252.137`) **recusa conexão dos runners do GitHub Actions**:

```
ssh: connect to host 108.179.252.137 port 22: Connection refused
rsync error: unexplained error (code 255)
```

A mesma porta responde normalmente do IP local. É filtro por IP de origem — nenhuma
mudança de workflow contorna. **Deploy automatizado para QA e DEV está bloqueado por
infraestrutura**, não por código. Resolver exige liberar os IPs de saída dos runners
no cPanel ou usar runner self-hosted.

A Locaweb **não** tem essa restrição: runners autenticam e publicam normalmente
(comprovado nos runs `30679406274` e `30683187557`).

## 7. Peculiaridades do ambiente Locaweb

Descobertas em operação; economizam horas de diagnóstico.

- **`proc_open`/`proc_close` desabilitados no PHP.** `wp db export` falha com
  `Cannot do 'ProcessBuilder'`. Use `mysqldump` diretamente.
- **`php85 -l` dá segmentation fault** (`rc=139`) mesmo em arquivo válido. Para lint,
  use `token_get_all($src, TOKEN_PARSE)` via `wp eval`, ou um container PHP local.
- **Servidor em UTC-03.** Datas de arquivo (`ls -la`) não batem com timestamps UTC do
  GitHub Actions. Converta antes de concluir que algo não foi escrito.
- **SpeedyCache serve página estática.** Alterações de banco ou de código não
  aparecem no HTML até limpar:
  ```bash
  find wp-content/cache/speedycache -mindepth 1 -delete
  wp cache flush
  ```
  Já causou diagnóstico errado: o banco mudava e o HTML não.
- **Caminhos:** document root em
  `/home/storage/f/34/12/siteuonix1/public_html`; `wp-cli` em
  `/home/storage/f/34/12/siteuonix1/bin/wp-cli.phar`; PHP em `/usr/bin/php85`.

## 8. Regras de escrita no banco

1. **`mysqldump` (backup) antes de qualquer escrita.** Sem exceção.
2. Verificar integridade do dump: contar `CREATE TABLE` e confirmar
   `-- Dump completed` na última linha. Menos linhas que o esperado pode ser apenas
   `INSERT` multi-linha — não é sinal de corrupção por si só.
3. `wp search-replace` sempre com `--skip-columns=guid`, restrito às strings alvo, e
   com `--dry-run` antes para conferir a contagem.
4. Verificar o resultado **no HTML servido**, não na saída do comando.

## 9. A opção `downloaded_font_files` (Kadence)

Armazena o **caminho de disco absoluto** de cada ambiente:

```
localhost:8080       /var/www/html/wp-content//fonts/...
site.uonix.com.br    /home/storage/f/34/12/siteuonix1/public_html/wp-content//fonts/...
QA e DEV             /home2/uonix/{public_html,dev_uonix}/wp-content//fonts/...
```

A **barra dupla é intencional**: o Kadence faz
`str_replace(wp_content_dir(), content_url(), $path)` em
`inc/class-local-gfonts.php:202`, e como o primeiro termina com `/` e o segundo não,
a barra extra compensa. Remover a barra quebra a URL (`wp-contentfonts`).

Como o valor é específico do ambiente, **clonar o banco propaga o caminho errado** e
a fonte Barlow cai em fallback `sans-serif` (botões quebram de linha). Foi assim que
o bug se espalhou. A correção precisa ser feita em cada ambiente, não propagada.

**Pendência resolvida (2026-08-01):** `downloaded_font_files` foi adicionada a
`protected_options_where()` (`scripts/clone-environment.sh:317`) e o guarda
`scripts/tests/test-clone-path-bound-options.sh` impede regressão — ao incluir nova
opção que guarde caminho de disco, some-a à lista `path_bound_options` do teste.
Antes disso a lista protegia `admin_email`, `active_plugins`, `cron`, `backuply`,
`ai1wm`, `compressx`, `fluentform`, `mailchimp`, `smtp`, `loginizer`, `speedycache`,
`turnstile`, `captcha`, `wp_mail_logging` e `wpvivid`, mas nenhuma opção com caminho
de disco — e nenhum dos 14 testes de clone cobria essa classe.

Verificação rápida:

```bash
curl -sI <url>/wp-content/fonts/barlow/<arquivo>.woff2   # deve dar 200
```

## 10. Publicação em ordem de dependência

`mu-plugins/uonix-core.php` faz `require_once` de `uonix-shared/environment.php`.
Até 2026-08-01 esse require era **desprotegido** — o único do projeto. Se o core novo
chegasse ao servidor antes desse arquivo, o resultado era `E_COMPILE_ERROR` em
**toda** requisição, derrubando o site inteiro, inclusive `wp-admin`. `require`
ausente não é capturável por `try/catch`.

Corrigido: o require agora é guardado por `is_readable()`, a ausência vai para
`error_log`, e `uonix_mu_detect_environment()` cai em `'production'` (o ambiente mais
restritivo) quando `uonix_resolve_environment()` não existe — para que a política de
indexação e o bloqueio de e-mail não sejam afrouxados por um arquivo faltando.
Guardado por `scripts/tests/test-mu-loader-resilience.sh`.

O `deploy-production.yml` publica o core (linha 235) **antes** do loop de módulos
(linha 243). Com a proteção acima a janela deixou de ser fatal, mas a ordem abaixo
continua sendo a recomendada para primeira instalação.

**Ordem segura para primeira instalação:**

1. backup manual completo (arquivos + banco);
2. arquivos **novos** primeiro, inertes — os `module.php` antigos ainda não os
   carregam;
3. conferir hash de cada um contra o bundle;
4. os arquivos de **ativação** (`uonix-core.php` e os `module.php`);
5. os demais arquivos;
6. limpar cache e validar por HTTP em cada etapa.

Foi o método usado com sucesso em 2026-08-01 para publicar 16 arquivos sem
indisponibilidade.

## 11. Política de indexação

`site.uonix.com.br` deve servir com `noindex` até o cutover. Verificação:

```bash
curl -sI https://site.uonix.com.br/ | grep -i x-robots-tag
# esperado: x-robots-tag: noindex, nofollow, noarchive
```

Emitido por `mu-plugins/uonix-security/06-environment-indexing.php`, condicionado a
`UONIX_ALLOW_INDEXING === true && UONIX_ENV === 'production'` — fail-closed: sem
liberação explícita, aplica `noindex, nofollow, noarchive` e força `blog_public=0`.

Constantes em produção (verificadas):

```
UONIX_ENV               = 'production'
UONIX_ALLOW_INDEXING    = false
UONIX_ANALYTICS_ENABLED = true
UONIX_GTM_CONTAINER_ID  = 'GTM-P8TR5CCH'
UONIX_ADOPT_WEBSITE_ID  = definido
UONIX_NONPROD_EMAIL_TO  = não definido
```

## 12. Diagnóstico do smoke de produção

O step `Clear cache and run smoke tests` encadeia sete assertivas. Até 2026-08-01 elas
eram **silenciosas** (`test`/`[`/`exit 1`) e, quando falhavam, o log mostrava apenas o
cache flush e `exit code 1` — sem indicar qual reprovou, o que exigia SSH para
descobrir. Cada uma agora imprime um rótulo `smoke: <nome do check>` antes de
executar (nenhum valor de secret é impresso). Ordem das verificações:

1. `wp cache flush`
2. `wp core is-installed`
3. `UONIX_ENV = production`
4. `option get home` = URL alvo
5. `option get siteurl` = URL alvo
6. política de indexação (`UONIX_ALLOW_INDEXING === false`, função
   `uonix_environment_allows_indexing` existente e retornando `false`,
   `blog_public === "0"`)
7. coerência de `uonix_analytics_configuration`
8. `X-Robots-Tag` com `noindex`, `nofollow` e `noarchive`

Para isolar sem outro run, rode cada uma manualmente via `wp eval` no servidor.

## 13. Rollback

O rollback automático (`if: failure()`) restaura os arquivos a partir do backup do
mesmo `RUN_ID` e limpa o cache. Validado por hash: `diff -rq` entre backup e disco
retornou vazio, 62 arquivos em ambos.

Atenção: a validação HTTP pós-rollback usa a **mesma** assertiva de `X-Robots-Tag`
que o smoke. Se o header não puder ser satisfeito, o step de rollback aparece
vermelho mesmo tendo restaurado corretamente. Confirme o estado real por
`diff -rq` contra o backup antes de concluir que houve perda.

Cuidado com contagem de arquivos: `find -name 'uonix*'` **não** alcança arquivos
dentro dos módulos e produz falso alarme de perda de dados. Compare diretórios
inteiros.

## 14. Backups

| Local | Origem |
| --- | --- |
| `_uonix-deploy-backups/<RUN_ID>/` | criado pelo workflow, retém 5 |
| `_uonix-manual-backups/<stamp>/` | criado manualmente antes de intervenção |

Ambos ficam fora do document root. Dumps de banco em `~/uonix-copyfix/`.

## 15. Limites permanentes

- Não desabilitar SSH por automação de painel: gate humano.
- Não usar FTP para deploy.
- `--delete` no `rsync` restrito ao tema filho e a cada diretório `uonix-*`; nunca na
  raiz do WordPress.
- Nenhuma credencial em log, ticket ou documentação.
- Cutover de `uonix.com.br`, SMTP real e checkout transacional ficam fora de qualquer
  execução automatizada.
