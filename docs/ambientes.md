# Contrato de ambientes Uonix

Este documento é o contrato canônico da topologia Uonix para produção, QA, DEV e local. Em caso de conflito com documentação operacional anterior, este contrato prevalece até a atualização coordenada dos documentos relacionados.

Não versionar neste documento `wp-config.php`, senhas, chaves, tokens, salts, valores de Secrets, destinatários de caixa segura, IDs de analytics ou licenças.

## Matriz canônica

| Branch | Ambiente | Host | URL | Document root | `WP_ENVIRONMENT_TYPE` | Indexação | Analytics | E-mail | Turnstile | CompressX | Deploy/guard | Clone permitido |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `master` | Produção | Locaweb | `https://uonix.com.br` | `/home/storage/f/34/12/siteuonix1/public_html` | `production` | **Indexação liberada** (`index, follow`; sem `X-Robots-Tag`) | Habilitado somente aqui; IDs e configuração ficam fora do Git | SMTP real conforme configuração exclusiva do ambiente | Chave própria, fora do Git | Runtime e opções próprios do destino; não presumir licença, ativação ou geração de mídia | Workflow de produção em `master`, mas fail-closed: `ENABLE_DEPLOY_PRODUCTION=false`; não há deploy automático | Pode ser origem ou destino somente em operação explicitamente aprovada; destino requer confirmação dinâmica, backup fresco e preflight/dry-run no mesmo processo |
| `qa` | QA | HostGator | `https://uonix.ksio.dev` | `/home2/uonix/public_html` | `staging` | `noindex` | Desabilitado; não configurar IDs GTM/GA4/AdOpt | Apenas caixa segura configurada fora do Git, com identificação `[QA]` | Chave de teste configurada fora do Git | Runtime e opções próprios do destino; não copiar a licença/estado de outro ambiente | Workflow de QA, mantido bloqueado até validação: `ENABLE_DEPLOY_QA=false` | Pode ser origem ou destino, exceto identidade; execução depende dos gates de clone |
| `dev` | DEV | HostGator | `https://test.uonix.ksio.dev` | `/home2/uonix/dev_uonix` | `development` | `noindex` | Desabilitado; não configurar IDs GTM/GA4/AdOpt | Apenas caixa segura configurada fora do Git, com identificação `[DEV]` | Chave de teste configurada fora do Git | Runtime e opções próprios do destino; não copiar a licença/estado de outro ambiente | Workflow de DEV, mantido bloqueado até validação: `ENABLE_DEPLOY_DEVELOPMENT=false` | Pode ser origem ou destino, exceto identidade; execução depende dos gates de clone |
| `local` | Local | Podman no Mac | `http://localhost:8080` | Container WordPress (`/var/www/html`) | `local` | Privado; não expor a mecanismos de busca | Desabilitado; não configurar IDs GTM/GA4/AdOpt | Mailpit | Desabilitado | Runtime local independente; não copiar licença/estado de outro ambiente | Sem deploy remoto | Pode ser origem ou destino, exceto identidade; é executado no Mac e depende dos gates de clone |

## Promoção de código e isolamento

- A branch de produção permanece `master`; ela não será renomeada para `main`.
- O fluxo normal de promoção é `dev → qa → master` por revisão.
- A branch `local` recebe alterações de `dev`, mas não faz merge automático de volta para `dev`.
- O código versionado é separado do runtime WordPress. Não transportar `wp-config.php`, credenciais, caches, logs, backups, uploads de teste ou configurações específicas de host como se fossem código promovível.
- Produção atende em `uonix.com.br` desde o cutover de 2026-08-15. O domínio de trânsito `site.uonix.com.br` foi removido do painel e não resolve mais. A indexação está liberada (`UONIX_ALLOW_INDEXING=true`, `blog_public=1`).

## Constantes e configuração por ambiente

As constantes devem ser definidas na configuração privada de cada ambiente, nunca copiadas cegamente entre hosts. O contrato mínimo é:

| Ambiente | Constantes/políticas obrigatórias |
|---|---|
| Produção | `WP_ENVIRONMENT_TYPE=production`; `WP_HOME` e `WP_SITEURL` apontam para `https://uonix.com.br`; `UONIX_ALLOW_INDEXING=true`; `UONIX_ANALYTICS_ENABLED=true`. Somente este ambiente pode receber IDs de analytics. **`WP_HOME`/`WP_SITEURL` são constantes**: `wp option update home` NÃO tem efeito enquanto elas existirem — o valor da constante sempre vence sobre o banco. Em troca de domínio, editar `wp-config.php` primeiro e depois corrigir o banco com `UPDATE` SQL direto. |
| QA | `WP_ENVIRONMENT_TYPE=staging`; URL canônica `https://uonix.ksio.dev`; `UONIX_ALLOW_INDEXING=false`; `UONIX_ANALYTICS_ENABLED=false`; caixa segura não produtiva e Turnstile de teste definidos fora do repositório. |
| DEV | `WP_ENVIRONMENT_TYPE=development`; URL canônica `https://test.uonix.ksio.dev`; `UONIX_ALLOW_INDEXING=false`; `UONIX_ANALYTICS_ENABLED=false`; caixa segura não produtiva e Turnstile de teste definidos fora do repositório. |
| Local | `WP_ENVIRONMENT_TYPE=local`; URL canônica `http://localhost:8080`; `UONIX_ALLOW_INDEXING=false`; `UONIX_ANALYTICS_ENABLED=false`; Mailpit ativo e Turnstile desligado. |

Não declarar IDs GTM, GA4 ou AdOpt em QA, DEV ou local. Os identificadores de analytics, a configuração SMTP e as chaves Turnstile são dados por ambiente e não pertencem a arquivos versionados.

## Contrato de clone

A ferramenta aceita os quatro nomes canônicos `prod`, `qa`, `dev` e `local`. Os quatro pares de identidade (`prod → prod`, `qa → qa`, `dev → dev` e `local → local`) são proibidos. Os 12 pares direcionais entre ambientes distintos são permitidos apenas como capacidade técnica, nunca como autorização operacional.

| Categoria do par | Executor previsto | Requisitos antes de qualquer mutação |
|---|---|---|
| Remoto ↔ remoto (`prod`, `qa`, `dev`) | GitHub Actions a partir da referência canônica | Dry-run/preflight no mesmo processo, backup validado do destino, manifesto verificado e confirmação dinâmica. Para destino `prod`, exigir aprovação just-in-time além desses gates. |
| Qualquer par com `local` | Mac como ponte privada | Dry-run/preflight no mesmo processo, backup validado do destino e confirmação dinâmica; não executar automaticamente por workflow remoto. |

Por padrão, o clone preserva usuários, URL, título, configuração SMTP, analytics, Turnstile, licenças e configuração do host no destino. Substituir usuários exige opção explícita. Um clone não copia `wp-config.php`.

### CompressX em clones

O CompressX é gerenciado por ambiente. Seus diretórios de runtime (`wp-content/compressx` e `wp-content/compressx-nextgen`) e suas opções são preservados no destino e entram no backup local do destino; eles não são sincronizados pelo clone padrão. Se faltarem derivados WebP/AVIF, a reparação ou geração deve ocorrer no próprio destino. Este contrato não afirma que exista licença, plugin ativo ou mídia pré-gerada em qualquer ambiente.

## Deploy e guarda

- Produção usa SSH/rsync quando a janela técnica da Locaweb estiver aberta; SFTP é somente fallback manual. Não há fallback automático para FTP.
- Todos os guards de deploy iniciam bloqueados. Em especial, `ENABLE_DEPLOY_PRODUCTION=false` é obrigatório até autorização posterior; permanecer fail-closed é a decisão vigente.
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
