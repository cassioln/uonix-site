# Contrato de ambientes Uonix

Este documento é o contrato canônico da topologia Uonix para produção provisória, QA, DEV e local. Em caso de conflito com documentação operacional anterior, este contrato prevalece até a atualização coordenada dos documentos relacionados.

Não versionar neste documento `wp-config.php`, senhas, chaves, tokens, salts, valores de Secrets, destinatários de caixa segura, IDs de analytics ou licenças.

## Matriz canônica

| Branch | Ambiente | Host | URL | Document root | `WP_ENVIRONMENT_TYPE` | Indexação | Analytics | E-mail | Turnstile | CompressX | Deploy/guard | Clone permitido |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `master` | Produção provisória | Locaweb | `https://site.uonix.com.br` | `/home/storage/f/34/12/siteuonix1/public_html` | `production` | `noindex` até o cutover aprovado para o domínio oficial | Habilitado somente aqui; IDs e configuração ficam fora do Git | SMTP real conforme configuração exclusiva do ambiente | Chave própria, fora do Git | Runtime e opções próprios do destino; não presumir licença, ativação ou geração de mídia | Workflow de produção em `master`, mas fail-closed: `ENABLE_DEPLOY_PRODUCTION=false`; não há deploy automático nem cutover autorizado | Pode ser origem ou destino somente em operação explicitamente aprovada; destino requer confirmação dinâmica, backup fresco e preflight/dry-run no mesmo processo |
| `qa` | QA | HostGator | `https://uonix.ksio.dev` | `/home2/uonix/public_html` | `staging` | `noindex` | Desabilitado; não configurar IDs GTM/GA4/AdOpt | Apenas caixa segura configurada fora do Git, com identificação `[QA]` | Chave de teste configurada fora do Git | Runtime e opções próprios do destino; não copiar a licença/estado de outro ambiente | Workflow de QA, mantido bloqueado até validação: `ENABLE_DEPLOY_QA=false` | Pode ser origem ou destino, exceto identidade; execução depende dos gates de clone |
| `dev` | DEV | HostGator | `https://test.uonix.ksio.dev` | `/home2/uonix/dev_uonix` | `development` | `noindex` | Desabilitado; não configurar IDs GTM/GA4/AdOpt | Apenas caixa segura configurada fora do Git, com identificação `[DEV]` | Chave de teste configurada fora do Git | Runtime e opções próprios do destino; não copiar a licença/estado de outro ambiente | Workflow de DEV, mantido bloqueado até validação: `ENABLE_DEPLOY_DEVELOPMENT=false` | Pode ser origem ou destino, exceto identidade; execução depende dos gates de clone |
| `local` | Local | Podman no Mac | `http://localhost:8080` | Container WordPress (`/var/www/html`) | `local` | Privado; não expor a mecanismos de busca | Desabilitado; não configurar IDs GTM/GA4/AdOpt | Mailpit | Desabilitado | Runtime local independente; não copiar licença/estado de outro ambiente | Sem deploy remoto | Pode ser origem ou destino, exceto identidade; é executado no Mac e depende dos gates de clone |

## Promoção de código e isolamento

- A branch de produção permanece `master`; ela não será renomeada para `main`.
- O fluxo normal de promoção é `dev → qa → master` por revisão.
- A branch `local` recebe alterações de `dev`, mas não faz merge automático de volta para `dev`.
- O código versionado é separado do runtime WordPress. Não transportar `wp-config.php`, credenciais, caches, logs, backups, uploads de teste ou configurações específicas de host como se fossem código promovível.
- Produção em `site.uonix.com.br` continua provisória. O cutover para o domínio oficial é uma etapa futura, separada e sujeita a aprovação explícita. Enquanto isso, a indexação de produção permanece bloqueada.

## Constantes e configuração por ambiente

As constantes devem ser definidas na configuração privada de cada ambiente, nunca copiadas cegamente entre hosts. O contrato mínimo é:

| Ambiente | Constantes/políticas obrigatórias |
|---|---|
| Produção provisória | `WP_ENVIRONMENT_TYPE=production`; `WP_HOME` e `WP_SITEURL` apontam para `https://site.uonix.com.br`; `UONIX_ALLOW_INDEXING=false`; `UONIX_ANALYTICS_ENABLED=true`. Somente este ambiente pode receber IDs de analytics. |
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

## Documentação relacionada

- [Clone de ambientes](clone-ambientes.md): contrato operacional, preservação de runtime e procedimentos de clone.
- [Deploy](deploy.md): workflows e guardas de deploy.
- [Ambiente local](../local/README.md): execução Podman, Mailpit e verificações locais.
