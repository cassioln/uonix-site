# Clone De Ambientes

## Visão Geral

A ferramenta `ksio.dev > Clone de Ambientes` prepara uma solicitação de clone; ela não escreve banco ou arquivos diretamente. O código de transferência fica em `scripts/clone-environment.sh`; o workflow remoto é manual e pares que envolvem `local` usam o Mac como ponte privada.

O contrato canônico é [ambientes.md](ambientes.md). Clone é uma capacidade técnica, não uma autorização operacional.

## Ambientes

- `prod`: produção provisória na Locaweb, `/home/storage/f/34/12/siteuonix1/public_html`, URL `https://site.uonix.com.br/`, `WP_ENVIRONMENT_TYPE=production`.
- `qa`: QA na HostGator, `/home2/uonix/public_html`, URL `https://uonix.ksio.dev/`, `WP_ENVIRONMENT_TYPE=staging`.
- `dev`: DEV na HostGator, `/home2/uonix/dev_uonix`, URL `https://test.uonix.ksio.dev/`, `WP_ENVIRONMENT_TYPE=development`.
- `local`: Podman no Mac, URL `http://localhost:8080/`, `WP_ENVIRONMENT_TYPE=local`.

## Segurança

- Os quatro pares de identidade são proibidos. Os 12 pares direcionais entre ambientes diferentes exigem dry-run/preflight no mesmo processo, backup validado do destino e manifesto verificado.
- Destino `prod` exige aprovação humana just-in-time e confirmação dinâmica que nomeie origem e destino, além dos demais gates.
- Usuários do destino são preservados por padrão; substituí-los requer opção explícita.
- URL, título, SMTP, analytics, Turnstile, licenças e configuração do host pertencem ao destino. O clone não copia `wp-config.php`.
- O painel pode solicitar uma execução manual no GitHub Actions, mas nunca é writer de checkout, banco ou arquivos do ambiente.
- Nunca usar FTP como fallback normal. SSH/rsync é preferido; SFTP é somente fallback manual e aprovado.

## Arquivos Runtime

- O clone sincroniza somente runtime autorizado, sem incluir cache, logs, backups, staging, arquivos temporários ou PII de currículos.
- Em `uploads`, ficam fora da migração: `curriculos-recebidos`, `FLUENT_PDF_TEMPLATES`, `gosmtp-attachments` legado, `loginizer-config`, `speedycache-binary`, `wc-logs`, `wp-personal-data-exports`, `wp-staging`, `wpvivid_*`, `wpmc-trash`, `*.log` e arquivos temporários `*~`.
- Plugins gerenciados por ambiente, como `all-in-one-wp-migration-10GB`, `backuply`, `compressx`, `fluent-smtp`, `fluentform`, `gosmtp`, `gosmtp-pro`, `loginizer`, `loginizer-security`, `speedycache`, `speedycache-pro` e `wpvivid-backuprestore`, não são copiados entre ambientes.
- `fluent-smtp` é o SMTP suportado nos ambientes remotos. `gosmtp` e `gosmtp-pro` são legados removidos; o clone não copia esses diretórios e remove plugins e opções residuais do destino em execução real.
- Os diretórios `wp-content/compressx` e `wp-content/compressx-nextgen` são runtime do CompressX: entram no backup do destino e não são sincronizados pelo clone padrão.
- Tema filho e MU-plugins versionados são entregues pela referência canônica; o clone transfere apenas runtime autorizado.

## GitHub Actions

`.github/workflows/clone-environment.yml` atende somente clones remotos entre `prod`, `qa` e `dev`; ele usa a referência canônica e não executa pares com `local`. Operações com `local` são realizadas no Mac após os mesmos gates de segurança.

### GitHub Environments

- `clone-operations` é o Environment usado por toda solicitação remota que não escreve em produção, inclusive quando `prod` é a origem. Ele concentra apenas os nomes de Variables e Secrets necessários ao transporte aprovado, nunca seus valores no repositório ou nos logs.
- `production-clone` é selecionado somente para `execute` com destino `prod`, depois de o workflow validar a allowlist, a flag de produção e a confirmação vinculada ao SHA. Ele é um boundary separado para a escrita em produção; não substitui dry-run, backup, janela SSH ou aprovação humana just-in-time.

Não registrar token, chave, senha, `wp-config.php` ou valor de Secret nesta documentação. A configuração por ambiente é mantida fora do Git e só deve ser materializada após a validação da solicitação.

## Matriz De Cenários

Os pares permitidos como capacidade técnica são:

```text
prod -> qa, dev, local
qa   -> prod, dev, local
dev  -> prod, qa, local
local -> prod, qa, dev
```

Todo clone deve começar com dry-run. Clones reais permanecem bloqueados até os gates do destino estarem aprovados.

## Comandos Locais

Use uma chave privada aprovada fora do repositório e execute primeiro apenas o dry-run. O exemplo abaixo não deve ser executado sem os pré-requisitos, a janela operacional e as aprovações descritas acima:

```bash
cd "$(git rev-parse --show-toplevel)"
HOSTGATOR_SSH_KEY='/caminho/para/chave-aprovada' HOSTGATOR_SSH_KNOWN_HOSTS_FILE='/caminho/para/known-hosts-aprovado' scripts/clone-environment.sh --source=qa --target=local --dry-run
```

Após gates humanos just-in-time, backup validado e revisão do dry-run, a mesma CLI usa `--execute`; este documento não autoriza a operação. O destino `prod` continua sujeito à confirmação dinâmica e aprovação humana.

O modo aprovado mantém as mesmas variáveis de transporte, trocando apenas o modo da CLI por `--execute` após a revisão humana:

```bash
# Somente em janela aprovada; os placeholders não são credenciais operacionais.
HOSTGATOR_SSH_KEY='/caminho/para/chave-aprovada' HOSTGATOR_SSH_KNOWN_HOSTS_FILE='/caminho/para/known-hosts-aprovado' scripts/clone-environment.sh --source=qa --target=local --execute
```