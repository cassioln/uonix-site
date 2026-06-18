# Deploy

## Staging

Push na branch `dev` dispara o workflow `.github/workflows/deploy-staging.yml`.

O workflow envia apenas o tema filho:

```text
themes/kadence-child/ -> /home2/uonix/qa_uonix/wp-content/themes/kadence-child/
```

Variáveis esperadas no GitHub:

- `STAGING_SSH_HOST`
- `STAGING_SSH_USER`
- `STAGING_PATH`

Secret esperado:

- `STAGING_SSH_KEY`

## Produção

A branch `master` representa produção, mas o deploy é manual pelo workflow `.github/workflows/deploy-production.yml`.

Essa escolha reduz risco de publicar alterações no site principal sem uma confirmação explícita.

## Pós-deploy

Os workflows tentam limpar cache de página do SpeedyCache e executar `wp cache flush` quando WP-CLI estiver disponível.
