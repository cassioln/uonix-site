# Ambiente Local Uonix

Este ambiente roda WordPress localmente e monta o tema filho diretamente do repo:

`../themes/kadence-child -> /var/www/html/wp-content/themes/kadence-child`

Assim, qualquer alteração no tema em `themes/kadence-child` aparece no WordPress local sem copiar arquivos.

## Comandos

```bash
cd /Users/cassio/GitHubPessoal/uonix-site
podman-compose -f local/compose.yml up -d
podman-compose -f local/compose.yml down
podman-compose -f local/compose.yml logs -f wordpress
```

## Acessos

- WordPress: http://localhost:8080
- Mailpit: http://localhost:8025

## Dados Locais

- `local/wp-content/` fica fora do Git e guarda plugins, uploads e arquivos gerados localmente.
- O banco usa o volume Podman `uonix-site_db_data`.
- O core do WordPress usa o volume `uonix-site_wordpress_core`.

Para recriar do zero, pare os containers e remova os volumes desejados manualmente.
