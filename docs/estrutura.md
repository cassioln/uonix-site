# Estrutura Do Repositório

## Versionado

- Tema filho Kadence.
- MU-plugins modulares para regras de negócio, shortcodes e integrações.
- Snippets visuais carregados pelo tema filho.
- Workflows de deploy.
- Workflow manual de clone entre ambientes.
- Scripts locais.
- Documentação.
- Código próprio em `plugins/plugins-customizados/` e `mu-plugins/`.

## Não Versionado

- Plugins de terceiros.
- Uploads do WordPress.
- Backups.
- Cache.
- Dumps SQL.
- Arquivos sensíveis como `wp-config.php` e `.env`.

## Ambiente Local

O ambiente em `local/` monta o tema filho diretamente do Git:

```text
themes/kadence-child -> /var/www/html/wp-content/themes/kadence-child
mu-plugins -> /var/www/html/wp-content/mu-plugins
```

O diretório `local/wp-content/` fica fora do Git e serve apenas como runtime local para plugins, uploads, idiomas e arquivos gerados.
