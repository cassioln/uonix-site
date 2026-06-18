# Estrutura Do Repositório

## Versionado

- Tema filho Kadence.
- Snippets carregados pelo tema filho.
- Workflows de deploy.
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
```

O diretório `local/wp-content/` fica fora do Git e serve apenas como runtime local para plugins, uploads, idiomas e arquivos gerados.
