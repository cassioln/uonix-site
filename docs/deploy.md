# Deploy

O contrato canônico de ambientes fica em [ambientes.md](ambientes.md). A topologia vigente é:

- Produção: `https://uonix.com.br/` na branch `master` e Locaweb (cutover concluído em 2026-08-15).
- QA: `https://uonix.ksio.dev/` na branch `qa` e HostGator.
- DEV: `https://test.uonix.ksio.dev/` na branch `dev` e HostGator.
- Local: `http://localhost:8080/` em Podman no Mac; não possui deploy remoto.

## Guardas e aprovação

Todos os deploys são fail-closed enquanto a guarda explícita do respectivo ambiente estiver desativada. A validação do workflow pode executar em um push, mas isso não autoriza publicação de arquivos. Não habilite uma guarda, faça dispatch ou altere configurações do host sem a aprovação operacional correspondente.

Produção não tem deploy automático autorizado. Qualquer publicação para `uonix.com.br` requer aprovação humana explícita, preflight, backup validado, smoke test e rollback disponível.

## Workflows

- `.github/workflows/deploy-production.yml`: produção provisória em `master`, protegida por `ENABLE_DEPLOY_PRODUCTION=false` até aprovação posterior.
- `.github/workflows/deploy-qa.yml`: QA em `qa`, protegida por `ENABLE_DEPLOY_QA=false` até validação posterior.
- `.github/workflows/deploy-development.yml`: DEV em `dev`, protegida por `ENABLE_DEPLOY_DEVELOPMENT=false` até validação posterior.
- `.github/workflows/_deploy-hostgator.yml`: implementação reutilizável para QA e DEV; não é acionada diretamente.
- `.github/workflows/clone-environment.yml`: workflow manual de clone, separado do deploy de código.

## Transporte

Para produção, SSH/rsync é o transporte previsto durante a janela técnica aprovada da Locaweb. SFTP é somente fallback manual e aprovado. FTP não é fallback normal nem automático.

O deploy transfere somente o tema filho e os MU-plugins gerenciados. Não versionar nem publicar por esse fluxo `wp-config.php`, credenciais, uploads, cache, banco, backups ou runtime específico do host.

## Pós-deploy aprovado

Após uma publicação autorizada, confirmar limpeza de cache, `wp cache flush` quando WP-CLI estiver disponível e smoke tests HTTP no URL canônico do ambiente. Uma falha em preflight, backup, manifesto, publicação ou smoke test exige a rota de rollback definida antes da mutação.