# Clone De Ambientes

## Visão Geral

A ferramenta `ksio.dev > Clone de Ambientes` prepara clones completos entre produção, QA e localhost. O código pesado fica em `scripts/clone-environment.sh`; o painel apenas dispara GitHub Actions para clones remotos ou gera o comando local.

## Ambientes

- Produção: `/home2/uonix/public_html`, URL `https://uonix.ksio.dev`, título `Uônix`.
- QA: `/home2/uonix/qa_uonix`, URL `https://qa.uonix.ksio.dev`, título `QA - UONIX`.
- Localhost: `http://localhost:8080`, título `DEV - UONIX`.

## Segurança

- Clonar para produção exige a confirmação exata `CLONAR PARA PRODUCAO`, exceto em `--dry-run`.
- Use `--dry-run` para validar conexões, caminhos e ferramentas sem alterar banco ou arquivos.
- O destino sempre recebe backup antes do clone real.
- A retenção padrão mantém os últimos 5 backups por ambiente.
- Usuários do destino são preservados por padrão.
- Opções sensíveis do destino são preservadas: plugins gerenciados por ambiente, estado de ativação dos plugins, cron, GoSMTP/WP Mail SMTP, Turnstile, reCAPTCHA/hCaptcha, Mailchimp, captcha/Loginizer, Backuply, Fluent Forms, CompressX e `admin_email`.
- Opções sensíveis são restauradas por `option_name`, sem reaproveitar `option_id`, para evitar colisões com opções importadas da origem.
- Mailpit é carregado somente quando `UONIX_ENV` é `local`.

## Arquivos Runtime

- O clone sincroniza `uploads`, `plugins` e `languages`, sem incluir cache, logs, backups, staging, arquivos temporários ou PII de currículos.
- Em `uploads`, ficam fora da migração: `curriculos-recebidos`, `FLUENT_PDF_TEMPLATES`, `gosmtp-attachments`, `loginizer-config`, `speedycache-binary`, `wc-logs`, `wp-personal-data-exports`, `wp-staging`, `wpvivid_*`, `wpmc-trash`, `*.log` e arquivos temporários `*~`.
- Os plugins `all-in-one-wp-migration-10GB`, `backuply`, `backuply-pro`, `compressx`, `fluent-smtp`, `fluentform`, `gosmtp`, `gosmtp-pro`, `loginizer`, `loginizer-security`, `speedycache`, `speedycache-pro`, `wp-mail-logging` e `wpvivid-backuprestore` são gerenciados por ambiente e não são copiados entre ambientes.
- As opções desses plugins em `wp_options` também são preservadas no destino para evitar sobrescrever chaves, SMTP, Turnstile, licença, cron e estado de ativação local.
- Os diretórios `wp-content/compressx` e `wp-content/compressx-nextgen` são runtime do CompressX, entram no backup do destino e não são sincronizados entre ambientes no clone padrão. Se o destino não tiver WebP/AVIF gerado, rode ou repare o CompressX no próprio destino.
- Após clone real para QA ou produção, o script valida imagens críticas com `Accept: image/avif,image/webp` e falha se elas ainda forem servidas como PNG/JPEG original.
- Tema filho e MU-plugins versionados só entram no clone quando `--include-git-files=1`.

## GitHub Actions

Para o painel disparar clones remotos, defina no `wp-config.php` do ambiente que usará a tela:

```php
define( 'UONIX_GITHUB_TOKEN', 'github_pat_...' );
define( 'UONIX_GITHUB_REPO', 'cassioln/uonix-site' );
define( 'UONIX_GITHUB_WORKFLOW_REF', 'qa' );
```

O token deve ter permissão mínima para disparar workflows no repositório.

## Matriz De Cenários

Pares suportados:

- `prod -> qa`
- `qa -> prod`
- `prod -> local`
- `qa -> local`
- `local -> qa`
- `local -> prod`

Destinos `prod` devem ser testados primeiro com `--dry-run`. Clone real para produção exige `--confirm-production='CLONAR PARA PRODUCAO'`.

## Comandos Locais

Validar todos os pares sem alterar destino:

```bash
cd /Users/cassio/GitHubPessoal/uonix-site
for pair in 'prod qa' 'qa prod' 'prod local' 'qa local' 'local qa' 'local prod'; do
  set -- $pair
  SSH_KEY="$HOME/.ssh/uonix_github_actions_staging_nopass" scripts/clone-environment.sh --source="$1" --target="$2" --include-git-files=0 --preserve-destination-users=1 --dry-run
done
```

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
