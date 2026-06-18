# Uonix Site

Repositório de código próprio do site Uonix.

## Estrutura

- `.github/workflows/`: deploys via GitHub Actions.
- `themes/kadence-child/`: tema filho ativo do site e snippets visuais acoplados ao Kadence.
- `plugins/plugins-customizados/`: plugins próprios, quando existirem.
- `mu-plugins/`: módulos globais do site carregados por `uonix-core.php`.
- `local/`: ambiente local Podman.
- `scripts/`: comandos auxiliares locais.
- `docs/`: documentação operacional.

Plugins de terceiros, uploads e arquivos gerados pelo WordPress não são versionados.

## Branches

- `dev`: deploy automático para staging.
- `master`: branch de produção. O deploy de produção fica manual pelo workflow.

## Ambientes

- Staging: `qa.uonix.ksio.dev`
- Produção: `uonix.ksio.dev`
- Tema principal: `themes/kadence-child`
- Caminho staging do tema: `/home2/uonix/qa_uonix/wp-content/themes/kadence-child`
- Caminho staging dos MU-plugins: `/home2/uonix/qa_uonix/wp-content/mu-plugins`

## Local

```bash
podman-compose -p uonix-local -f local/compose.yml up -d
podman-compose -p uonix-local -f local/compose.yml down
```

- WordPress local: `http://localhost:8080`
- Mailpit local: `http://localhost:8025`

Para o local ficar fiel ao servidor, mantenha `local/wp-content/plugins` e `local/wp-content/uploads` sincronizados fora do Git e importe um dump atual do banco.
