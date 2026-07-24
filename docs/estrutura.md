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
- Hardening próprio em `mu-plugins/uonix-security/`, incluindo bloqueio XML-RPC/pingback sem depender do Loginizer Pro.

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

## Topologia Operacional

- Produção provisória: `https://site.uonix.com.br/` na Locaweb.
- QA: `https://uonix.ksio.dev/` na HostGator.
- DEV: `https://test.uonix.ksio.dev/` na HostGator.
- Local: `http://localhost:8080/` em Podman no Mac.

Os detalhes de branch, document root, política de indexação, e-mail, analytics, Turnstile, deploy e clone estão no [contrato de ambientes](ambientes.md). Código versionado não inclui `wp-config.php`, credenciais, caches, backups, uploads ou runtime específico do host.
