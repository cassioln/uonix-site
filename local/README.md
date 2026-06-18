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
podman-compose -f local/compose.yml --profile tools run --rm --no-deps wpcli cache flush
```

## Acessos

- WordPress: http://localhost:8080
- Mailpit: http://localhost:8025

## Dados Locais

- `local/wp-content/` fica fora do Git e guarda plugins, uploads e arquivos gerados localmente.
- O banco usa o volume Podman `uonix-site_db_data`.
- O core do WordPress usa o volume `uonix-site_wordpress_core`.
- O prefixo de tabela local é `wpis_`, igual ao dump de QA.

Para recriar do zero, pare os containers e remova os volumes desejados manualmente.

## Importar Banco QA

Exemplo com um dump em `~/Downloads/uonix_qa.sql`:

```bash
podman exec uonix-local-db mariadb -u root -proot_password -e "DROP DATABASE IF EXISTS uonix_db; CREATE DATABASE uonix_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON uonix_db.* TO 'uonix_user'@'%'; FLUSH PRIVILEGES;"
podman exec -i uonix-local-db mariadb -u uonix_user -puonix_pass uonix_db < ~/Downloads/uonix_qa.sql
podman exec uonix-local-db mariadb -u uonix_user -puonix_pass uonix_db -e "UPDATE wpis_options SET option_value='http://localhost:8080' WHERE option_name IN ('siteurl','home');"
podman-compose -f local/compose.yml --profile tools run --rm --no-deps wpcli search-replace 'https://qa.uonix.ksio.dev' 'http://localhost:8080' --all-tables-with-prefix --skip-columns=guid
```
