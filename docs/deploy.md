# Deploy

## Staging

Push na branch `qa` dispara o workflow `.github/workflows/deploy-staging.yml`.

O workflow envia apenas o tema filho:

```text
themes/kadence-child/ -> /home2/uonix/qa_uonix/wp-content/themes/kadence-child/
mu-plugins/uonix-*     -> /home2/uonix/qa_uonix/wp-content/mu-plugins/
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

## Clone de ambientes

O workflow `.github/workflows/clone-environment.yml` é manual e clona banco e arquivos runtime entre `prod` e `qa`. A tela `ksio.dev > Clone de Ambientes` pode disparar esse workflow quando `UONIX_GITHUB_TOKEN` estiver definido no `wp-config.php`.

## Pós-deploy

Os workflows tentam limpar cache de página do SpeedyCache e executar `wp cache flush` quando WP-CLI estiver disponível.
