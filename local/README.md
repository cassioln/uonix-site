# Ambiente Local Uonix

Este ambiente roda WordPress localmente e monta o código próprio diretamente do repo:

`../themes/kadence-child -> /var/www/html/wp-content/themes/kadence-child`

`../mu-plugins -> /var/www/html/wp-content/mu-plugins`

Assim, qualquer alteração no tema ou nos MU-plugins aparece no WordPress local sem copiar arquivos.

## Comandos

```bash
cd /Users/cassio/GitHubPessoal/uonix-site
podman-compose -p uonix-local -f local/compose.yml up -d
podman-compose -p uonix-local -f local/compose.yml down
podman-compose -p uonix-local -f local/compose.yml logs -f wordpress
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli cache flush
```

## Acessos

- WordPress: http://localhost:8080
- Mailpit: http://localhost:8025

## Dados Locais

- `local/wp-content/` fica fora do Git e guarda plugins, uploads e arquivos gerados localmente.
- O banco usa o volume Podman `uonix-site_db_data`.
- O core do WordPress usa o volume `uonix-site_wordpress_core`.
- O prefixo de tabela local é `wpis_`, igual ao dump de QA.

Nao remova os volumes se quiser preservar o banco e o core local.

## Recriar Ambiente Local

Use este fluxo quando containers ou imagens forem apagados por acidente.

1. Entre no repo:

```bash
cd /Users/cassio/GitHubPessoal/uonix-site
```

2. Confira se os volumes de dados ainda existem:

```bash
podman volume ls | rg 'uonix-site_(db_data|wordpress_core)'
```

Se `uonix-site_db_data` existir, o banco local provavelmente foi preservado. Se `uonix-site_wordpress_core` existir, o core do WordPress tambem foi preservado.

3. Garanta que os diretorios locais montados existem:

```bash
mkdir -p local/wp-content/plugins local/wp-content/uploads local/wp-content/languages
```

4. Baixe de novo as imagens que faltarem:

```bash
podman-compose -p uonix-local -f local/compose.yml pull
```

5. Recrie e suba os containers usando os volumes existentes:

```bash
podman-compose -p uonix-local -f local/compose.yml up -d
```

6. Confira se os containers subiram:

```bash
podman ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' | rg 'uonix-local|NAMES'
```

Devem aparecer:

- `uonix-local-db`
- `uonix-local-app`
- `uonix-local-mailpit`

7. Valide o WordPress local:

```bash
curl -I --max-time 20 http://localhost:8080/
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T wpcli core is-installed
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T wpcli option get home
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T wpcli option get siteurl
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps -T wpcli option get blogname
```

Esperado:

- `curl` retorna `HTTP/1.1 200 OK`.
- `home` e `siteurl` retornam `http://localhost:8080`.
- `blogname` retorna `DEV - UONIX`.

8. Se o volume do banco tambem foi removido, suba os containers vazios e recarregue os dados a partir de QA:

```bash
podman-compose -p uonix-local -f local/compose.yml up -d
SSH_KEY="$HOME/.ssh/uonix_github_actions_staging_nopass" scripts/clone-environment.sh --source=qa --target=local --include-git-files=0 --preserve-destination-users=1 --dry-run
SSH_KEY="$HOME/.ssh/uonix_github_actions_staging_nopass" scripts/clone-environment.sh --source=qa --target=local --include-git-files=0 --preserve-destination-users=1 --yes
```

Esse clone sobrescreve somente o ambiente local. Ele preserva as opcoes locais sensiveis e ajusta `home`, `siteurl` e `blogname` para localhost.

9. Se nao puder usar o clone remoto, importe um dump manual seguindo a secao abaixo.

Para recriar do zero intencionalmente, pare os containers e remova os volumes desejados manualmente. Isso apaga dados locais.

## Importar Banco QA

Exemplo com um dump em `~/Downloads/uonix_qa.sql`:

```bash
podman exec uonix-local-db mariadb -u root -proot_password -e "DROP DATABASE IF EXISTS uonix_db; CREATE DATABASE uonix_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON uonix_db.* TO 'uonix_user'@'%'; FLUSH PRIVILEGES;"
podman exec -i uonix-local-db mariadb -u uonix_user -puonix_pass uonix_db < ~/Downloads/uonix_qa.sql
podman exec uonix-local-db mariadb -u uonix_user -puonix_pass uonix_db -e "UPDATE wpis_options SET option_value='http://localhost:8080' WHERE option_name IN ('siteurl','home');"
podman-compose -p uonix-local -f local/compose.yml --profile tools run --rm --no-deps wpcli search-replace 'https://qa.uonix.ksio.dev' 'http://localhost:8080' --all-tables-with-prefix --skip-columns=guid
```

## Clonar Ambientes

Use o script central para clonar QA ou produção para localhost:

```bash
cd /Users/cassio/GitHubPessoal/uonix-site
SSH_KEY="$HOME/.ssh/uonix_github_actions_staging_nopass" scripts/clone-environment.sh --source=qa --target=local --include-git-files=0 --preserve-destination-users=1 --yes
```

O script mantém o título local como `DEV - UONIX`, preserva GoSMTP/Turnstile/Loginizer do destino e deixa o Mailpit ativo apenas em localhost.


## Validar Clone Sem Alterar

```bash
cd /Users/cassio/GitHubPessoal/uonix-site
SSH_KEY="$HOME/.ssh/uonix_github_actions_staging_nopass" scripts/clone-environment.sh --source=qa --target=local --include-git-files=0 --preserve-destination-users=1 --dry-run
```
