# Deploy

O contrato canônico de ambientes fica em [ambientes.md](ambientes.md). A topologia vigente é:

- Produção: `https://uonix.com.br/` na branch `master` e Locaweb (cutover concluído em 2026-08-15).
- QA: `https://uonix.ksio.dev/` na branch `qa` e HostGator.
- Local: `http://localhost:8080/` em Podman no Mac; não possui deploy remoto.

## Guardas e aprovação

As guardas de QA e de produção **estão habilitadas** (`ENABLE_DEPLOY_QA=true`, `ENABLE_DEPLOY_PRODUCTION=true`), por decisão do responsável para agilizar publicação. Estado confirmado em 2026-09-22 nas *repository variables* — o valor vive no GitHub, não neste arquivo; confira com `gh variable list`.

Consequência prática: **push na branch `qa` publica em QA automaticamente.** Em produção, o que impede publicação acidental não é a guarda, e sim o gatilho `workflow_dispatch` exclusivo, a frase de confirmação `PUBLICAR <SHA>` e a validação do host no próprio workflow.

Produção continua sem deploy automático. Qualquer publicação para `uonix.com.br` requer aprovação humana explícita, preflight, backup validado, smoke test e rollback disponível. Não altere configurações do host nem faça dispatch sem a aprovação operacional correspondente.

## Workflows

- `.github/workflows/deploy-production.yml`: produção em `master`, apenas por `workflow_dispatch` com a frase `PUBLICAR <SHA>`. A guarda `ENABLE_DEPLOY_PRODUCTION` está `true`; a proteção efetiva é o gatilho manual e a confirmação.
- `.github/workflows/deploy-qa.yml`: QA em `qa`, disparada por `push` na branch. A guarda `ENABLE_DEPLOY_QA` está `true`, portanto o push publica.
- `.github/workflows/_deploy-hostgator.yml`: implementação reutilizável para QA; não é acionada diretamente.
- `.github/workflows/clone-environment.yml`: workflow manual de clone, separado do deploy de código.

## Transporte

Para produção, SSH/rsync é o transporte previsto durante a janela técnica aprovada da Locaweb. SFTP é somente fallback manual e aprovado. FTP não é fallback normal nem automático.

O deploy transfere somente o tema filho e os MU-plugins gerenciados. Não versionar nem publicar por esse fluxo `wp-config.php`, credenciais, uploads, cache, banco, backups ou runtime específico do host.

## Pós-deploy aprovado

Após uma publicação autorizada, confirmar limpeza de cache, `wp cache flush` quando WP-CLI estiver disponível e smoke tests HTTP no URL canônico do ambiente. Uma falha em preflight, backup, manifesto, publicação ou smoke test exige a rota de rollback definida antes da mutação.

### Limite conhecido: a borda da Cloudflare não é purgada

Todos os hosts do projeto passam por Cloudflare, e **nenhuma etapa do deploy nem o botão de limpeza no painel administrativo invalidam a borda** — não há chamada à API de purge. A borda expira somente por TTL, o que pode levar cerca de uma hora.

Consequência prática: uma correção publicada e confirmada na origem pode continuar ausente na URL pública. Antes de concluir que o deploy falhou, distinguir os dois casos:

```bash
curl -sI https://uonix.com.br/ | grep -iE 'cf-cache-status|^age|cache-control'
```

`cf-cache-status: HIT` com `age` alto indica resposta da borda, não da origem. Para ver a origem imediatamente, acrescentar uma query string qualquer à URL (`?v=1`), que a borda trata como recurso distinto. Purgar a borda de verdade exige a conta Cloudflare — ou a implementação da purga automática, rastreada em issue própria.