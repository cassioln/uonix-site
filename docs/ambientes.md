# Contrato de ambientes Uonix

Este documento é o contrato canônico da topologia Uonix para produção, QA e local. Em caso de conflito com documentação operacional anterior, este contrato prevalece até a atualização coordenada dos documentos relacionados.

Não versionar neste documento `wp-config.php`, senhas, chaves, tokens, salts, valores de Secrets, destinatários de caixa segura, IDs de analytics ou licenças.

## Matriz canônica

| Branch | Ambiente | Host | URL | Document root | `WP_ENVIRONMENT_TYPE` | Indexação | Analytics | E-mail | Turnstile | CompressX | Deploy/guard | Clone permitido |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `master` | Produção | Locaweb | `https://uonix.com.br` | `/home/storage/f/34/12/siteuonix1/public_html` | `production` | **Indexação liberada** (`index, follow`; sem `X-Robots-Tag`) | Habilitado somente aqui; IDs e configuração ficam fora do Git | SMTP real conforme configuração exclusiva do ambiente | Chave própria, fora do Git | Runtime e opções próprios do destino; não presumir licença, ativação ou geração de mídia | `ENABLE_DEPLOY_PRODUCTION=true` (decisão do responsável, para agilizar publicação; estado confirmado em 2026-09-22). **Mesmo assim não há deploy automático**: `deploy-production.yml` só aceita `workflow_dispatch`, exige a frase `PUBLICAR <SHA>` e valida o host de destino. A proteção está no gatilho e na confirmação, não na guarda | Pode ser origem ou destino somente em operação explicitamente aprovada; destino requer confirmação dinâmica, backup fresco e preflight/dry-run no mesmo processo |
| `qa` | QA | HostGator | `https://uonix.ksio.dev` | `/home2/uonix/public_html` | `staging` | `noindex` | Desabilitado; não configurar IDs GTM/GA4/AdOpt | Apenas caixa segura configurada fora do Git, com identificação `[QA]` | Chave de teste configurada fora do Git | Runtime e opções próprios do destino; não copiar a licença/estado de outro ambiente | `ENABLE_DEPLOY_QA=true` (estado confirmado em 2026-09-22): **push na branch `qa` publica automaticamente**. Não existe automação que promova `master → qa`, **por decisão registrada** — alinhamento sob demanda, com force-push aceitável; ver *Promoção de código e isolamento* | Pode ser origem ou destino, exceto identidade; execução depende dos gates de clone |
| `local` | Local | Podman no Mac | `http://localhost:8080` | Container WordPress (`/var/www/html`) | `local` | Privado; não expor a mecanismos de busca | Desabilitado; não configurar IDs GTM/GA4/AdOpt | Mailpit | Desabilitado | Runtime local independente; não copiar licença/estado de outro ambiente | Sem deploy remoto | Pode ser origem ou destino, exceto identidade; é executado no Mac e depende dos gates de clone |

## Promoção de código e isolamento

- A branch de produção permanece `master`; ela não será renomeada para `main`.
- A promoção de código é feita por pull request direto para `master`, sob revisão. A branch `qa` recebe código já mergeado, para validação com dado real, e não é etapa de promoção.
- **A promoção `master → qa` é feita sob demanda, por alinhamento direto da branch, e não tem automação.** Decisão do responsável em 2026-09-23. Quando houver necessidade de validar algo em QA, `qa` é alinhada a `master` na hora, e **force-push é aceitável**: o histórico próprio de `qa` é ruído de promoção seletiva, não trabalho. Medido em 2026-09-22 — `origin/qa` estava 37 commits atrás de `master`, com 4 commits próprios, três dos quais já tinham equivalente em `master` e o quarto adicionava um arquivo vazio criado pelo editor web do GitHub.
  - Por que não automatizar: a validação de números acontece em **produção**, porque o Search Console é do domínio de produção. QA consultaria o mesmo dado por um runtime de staging, então ganharia isolamento de raio de dano, não fidelidade. Espelhar `master` continuamente produziria deploys frequentes num ambiente que ninguém está observando.
  - QA continua útil para **testar mudança de comportamento arriscada**, não para conferir número. É por isso que a alternativa de aposentá-lo foi recusada.
- A branch `local` recebe alterações de `master`, mas não faz merge automático de volta.
- O código versionado é separado do runtime WordPress. Não transportar `wp-config.php`, credenciais, caches, logs, backups, uploads de teste ou configurações específicas de host como se fossem código promovível.
- Produção atende em `uonix.com.br` desde o cutover de 2026-08-15. O domínio de trânsito `site.uonix.com.br` foi removido do painel e não resolve mais. A indexação está liberada (`UONIX_ALLOW_INDEXING=true`, `blog_public=1`).

## Constantes e configuração por ambiente

As constantes devem ser definidas na configuração privada de cada ambiente, nunca copiadas cegamente entre hosts. O contrato mínimo é:

| Ambiente | Constantes/políticas obrigatórias |
|---|---|
| Produção | `WP_ENVIRONMENT_TYPE=production`; `WP_HOME` e `WP_SITEURL` apontam para `https://uonix.com.br`; `UONIX_ALLOW_INDEXING=true`; `UONIX_ANALYTICS_ENABLED=true`. Somente este ambiente pode receber IDs de analytics e AdOpt (`UONIX_ADOPT_WEBSITE_ID` e `UONIX_ADOPT_CONSENT_TAG_IDS`). **`WP_HOME`/`WP_SITEURL` são constantes**: `wp option update home` NÃO tem efeito enquanto elas existirem — o valor da constante sempre vence sobre o banco. Em troca de domínio, editar `wp-config.php` primeiro e depois corrigir o banco com `UPDATE` SQL direto. |
| QA | `WP_ENVIRONMENT_TYPE=staging`; URL canônica `https://uonix.ksio.dev`; `UONIX_ALLOW_INDEXING=false`; `UONIX_ANALYTICS_ENABLED=false`; caixa segura não produtiva e Turnstile de teste definidos fora do repositório. |
| Local | `WP_ENVIRONMENT_TYPE=local`; URL canônica `http://localhost:8080`; `UONIX_ALLOW_INDEXING=false`; `UONIX_ANALYTICS_ENABLED=false`; Mailpit ativo e Turnstile desligado. |

Não declarar IDs GTM, GA4 ou `UONIX_ADOPT_WEBSITE_ID` em QA ou local. Os identificadores de analytics, a configuração SMTP e as chaves Turnstile são dados por ambiente e não pertencem a arquivos versionados.

Exceção deliberada: `UONIX_ADOPT_CONSENT_TAG_IDS` tem um **padrão versionado no código** desde 2026-09-23, por não ser segredo e por existir uma única conta AdOpt — ver a seção abaixo. A constante de ambiente segue existindo, apenas como override opcional. Sem `UONIX_ADOPT_WEBSITE_ID`, a AdOpt não é carregada fora de produção, então o padrão não tem efeito em QA nem local.

### Tags AdOpt de consentimento (`UONIX_ADOPT_CONSENT_TAG_IDS`)

Corrigido em 2026-09-23 (issue #264). A versão anterior desta seção descrevia um formato
de UUID que a AdOpt não usa, e um painel em `app.goadopt.io` que não é o desta conta.
O procedimento descrito rotacionava algo que nunca havia sido provisionado.

- **Valor padrão:** embutido em `mu-plugins/uonix-forms/49-forms-global-autofill.php`, na
  constante `UONIX_ADOPT_CONSENT_TAG_IDS_PADRAO` (hoje `9BxuTvI1_q`, a tag `uonix.com.br`).
  **Não** requer provisionamento por SSH.
- **Por que não fica no `wp-config.php`:** o ID não é segredo — a AdOpt o entrega na
  configuração pública que todo visitante baixa — e existe uma única conta AdOpt, usada
  somente em produção. A ausência silenciosa da constante manteve o módulo inativo por
  meses sem emitir sinal algum; embutir o padrão remove essa classe de falha.
- **Formato:** string separada por vírgula com o `id` de cada tag da AdOpt. São
  identificadores curtos de **10 caracteres** no alfabeto `[A-Za-z0-9_-]` — por exemplo
  `9BxuTvI1_q`. **Não são UUIDs.** Nomes de categoria (`funcional`, `marketing`) são
  rejeitados explicitamente e nunca funcionam como ID.
- **Categoria importa, e é pré-requisito:** a tag precisa estar em uma categoria
  **recusável** no painel. A categoria `Necessárias` (id 1) é aceita incondicionalmente
  pela AdOpt mesmo quando o visitante clica em "Rejeitar tudo", então uma tag ali
  autorizaria a persistência contra uma recusa explícita. A tag `uonix.com.br` foi movida
  para `Funcional` em 2026-09-23 exatamente por isso.
- **Política Fail-Closed:** se nenhum ID válido restar, o sistema opera estritamente sem
  persistência de dados no navegador.
- **Rotação (override de emergência, sem deploy):** declarar
  `define( 'UONIX_ADOPT_CONSENT_TAG_IDS', 'novo-id,antigo-id' );` no `wp-config.php` de
  produção — a constante tem precedência sobre o padrão embutido. Depois do período de
  transição, remover o ID antigo, ou remover a constante para voltar ao padrão do código.
  O caminho normal de mudança é um PR alterando `UONIX_ADOPT_CONSENT_TAG_IDS_PADRAO`.
- **Onde ver os IDs no painel:** card AdOpt do painel do WordPress → **Escanear tags**
  (`dash.goadopt.io/org/uonix/disclaimer/cookies-uonix/tags`). Cada tag mostra seu ID e um
  seletor de **Classificação**.
- **Verificação no runtime:**
  ```bash
  wp eval 'printf("tags=%d ids=%s\n", count(uonix_adopt_get_consent_tag_ids()), implode(",", uonix_adopt_get_consent_tag_ids()));'
  ```
  Esperado em produção: `tags=1` ou mais. `tags=0` significa autopreenchimento inativo.

## Contrato de clone

A ferramenta aceita os três nomes canônicos `prod`, `qa` e `local`. Os três pares de identidade (`prod → prod`, `qa → qa` e `local → local`) são proibidos. Os 6 pares direcionais entre ambientes distintos são permitidos apenas como capacidade técnica, nunca como autorização operacional.

| Categoria do par | Executor previsto | Requisitos antes de qualquer mutação |
|---|---|---|
| Remoto ↔ remoto (`prod`, `qa`) | GitHub Actions a partir da referência canônica | Dry-run/preflight no mesmo processo, backup validado do destino, manifesto verificado e confirmação dinâmica. Para destino `prod`, exigir aprovação just-in-time além desses gates. |
| Qualquer par com `local` | Mac como ponte privada | Dry-run/preflight no mesmo processo, backup validado do destino e confirmação dinâmica; não executar automaticamente por workflow remoto. |

Por padrão, o clone preserva usuários, URL, título, configuração SMTP, analytics, Turnstile, licenças e configuração do host no destino. Substituir usuários exige opção explícita. Um clone não copia `wp-config.php`.

### CompressX em clones

O CompressX é gerenciado por ambiente. Seus diretórios de runtime (`wp-content/compressx` e `wp-content/compressx-nextgen`) e suas opções são preservados no destino e entram no backup local do destino; eles não são sincronizados pelo clone padrão. Se faltarem derivados WebP/AVIF, a reparação ou geração deve ocorrer no próprio destino. Este contrato não afirma que exista licença, plugin ativo ou mídia pré-gerada em qualquer ambiente.

## Deploy e guarda

- Produção usa SSH/rsync quando a janela técnica da Locaweb estiver aberta; SFTP é somente fallback manual. Não há fallback automático para FTP.
- **Os guards de deploy estão habilitados.** `ENABLE_DEPLOY_QA=true` e `ENABLE_DEPLOY_PRODUCTION=true`, por decisão do responsável para agilizar publicação. Estado confirmado em 2026-09-22 diretamente nas *repository variables*.
- O que protege produção hoje **não é a guarda**, e sim: gatilho exclusivamente `workflow_dispatch`, frase de confirmação `PUBLICAR <SHA>`, e validação do host de destino no próprio workflow. Continua valendo exigir preflight, backup validado, smoke test e rollback disponível antes de publicar.
- Podem restar *repository variables* do ambiente retirado da topologia, algumas já sem nenhum consumidor no repositório. Elas não são nomeadas aqui de propósito — `scripts/tests/test-no-retired-environment-references.sh` reprova a reintrodução desses nomes, e a guarda está certa. O inventário e a ordem de remoção ficam na issue correspondente.
- O valor de uma *repository variable* vive no GitHub, não neste documento. Este arquivo descreve o estado, mas não o controla: para conferir, use `gh variable list`. Tratar esta seção como fonte de verdade sobre esses valores já produziu conclusão errada.
- A guarda de deploy não substitui preflight, backup, smoke test, rollback nem aprovação humana nas operações de alto impacto.
- Nenhum ambiente deve receber produção automática, migração, importação de banco, promoção de document root ou mudança de domínio com base apenas neste documento.

## Encerramento da migração

Registro das decisões finais, para não se perderem no histórico de conversa.

### Hospedagem antiga (`186.202.135.240`, Windows)

**Desativação AUTORIZADA por Cassio em 2026-08-17.**

Era o último caminho de rollback do cutover: se algo desse errado, re-adicionar o domínio ao
plano Windows era a volta possível. Os critérios que sustentavam a espera foram atendidos:

| critério | estado |
|---|---|
| cutover concluído e validado | ✅ 2026-08-15 |
| sitemap lido pelo Google | ✅ 16/08, 50 páginas, "processado" |
| páginas indexadas | ✅ 32 |
| erro de cobertura no Search Console | ✅ nenhum |
| checkout validado com envio real | ✅ 2026-08-17 |

Ao desativar, o rollback deixa de existir. Qualquer problema posterior se resolve para
frente, no ambiente novo.

### Rotação de credenciais

**Fora de escopo deste board por decisão de Cassio (2026-08-17):** tratado direto com os
usuários das caixas. Verificado que nenhuma credencial está versionada — `git grep` por
padrões de senha, token e chave privada não retorna nada, e o `.env` está no `.gitignore`.

### Trade-off aceito: 404 nas URLs do site antigo

Cassio definiu que nada do site do Criador de Sites seria aproveitado. Consequência medida
depois: **114 rotas antigas respondem 404**, e a autoridade de SEO delas é perdida.

Os 20 "redirects" que parecem funcionar **não são cadastrados** — é o
`redirect_guess_404_permalink()` do core adivinhando pelo slug. Provado inventando slugs que
nunca existiram (`/porca-sextavada`, `/barra-roscada-304`) e que ainda assim redirecionam.

Diagnóstico completo, com CSV das 135 rotas medidas, no card C43 do board
`uonix-cutover-dominio`. **Pausado a pedido de Cassio** em 2026-08-17.

## Documentação relacionada

- [Clone de ambientes](clone-ambientes.md): contrato operacional, preservação de runtime e procedimentos de clone.
- [Deploy](deploy.md): workflows e guardas de deploy.
- [Ambiente local](../local/README.md): execução Podman, Mailpit e verificações locais.
