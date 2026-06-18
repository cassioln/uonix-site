# Clone De Ambientes

## Visão Geral

A ferramenta `ksio.dev > Clone de Ambientes` prepara clones completos entre produção, QA e localhost. O código pesado fica em `scripts/clone-environment.sh`; o painel apenas dispara GitHub Actions para clones remotos ou gera o comando local.

## Ambientes

- Produção: `/home2/uonix/public_html`, URL `https://uonix.ksio.dev`, título `Uônix`.
- QA: `/home2/uonix/qa_uonix`, URL `https://qa.uonix.ksio.dev`, título `QA - UONIX`.
- Localhost: `http://localhost:8080`, título `DEV - UONIX`.

## Segurança

- Clonar para produção exige a confirmação exata `CLONAR PARA PRODUCAO`.
- O destino sempre recebe backup antes do clone.
- A retenção padrão mantém os últimos 5 backups por ambiente.
- Usuários do destino são preservados por padrão.
- Opções sensíveis do destino são preservadas: GoSMTP, Turnstile, captcha/Loginizer e `admin_email`.
- Mailpit é carregado somente quando `UONIX_ENV` é `local`.

## GitHub Actions

Para o painel disparar clones remotos, defina no `wp-config.php` do ambiente que usará a tela:

```php
define( 'UONIX_GITHUB_TOKEN', 'github_pat_...' );
define( 'UONIX_GITHUB_REPO', 'cassioln/uonix-site' );
define( 'UONIX_GITHUB_WORKFLOW_REF', 'qa' );
```

O token deve ter permissão mínima para disparar workflows no repositório.

## Comandos Locais

Clonar QA para localhost:

```bash
cd /Users/cassio/GitHubPessoal/uonix-site
SSH_KEY="$HOME/.ssh/uonix_github_actions_staging_nopass" scripts/clone-environment.sh --source=qa --target=local --include-git-files=0 --preserve-destination-users=1 --yes
```

Clonar produção para QA, pelo terminal:

```bash
cd /Users/cassio/GitHubPessoal/uonix-site
SSH_KEY="$HOME/.ssh/uonix_github_actions_staging_nopass" scripts/clone-environment.sh --source=prod --target=qa --include-git-files=0 --preserve-destination-users=1 --yes
```
