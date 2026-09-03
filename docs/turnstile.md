# Cloudflare Turnstile

Os formulários protegidos da Uonix usam o módulo `mu-plugins/uonix-integrations/34-turnstile-custom-forms.php`.
Essa proteção não depende do Loginizer Pro.

## Ambientes

- `local`: Turnstile fica desativado automaticamente.
- QA (`WP_ENVIRONMENT_TYPE=staging`, `https://uonix.ksio.dev/`): usa somente chaves de teste configuradas fora do Git.
- DEV (`WP_ENVIRONMENT_TYPE=development`, `https://test.uonix.ksio.dev/`): usa somente chaves de teste configuradas fora do Git.
- Produção (`WP_ENVIRONMENT_TYPE=production`, `https://uonix.com.br/`): pode usar chaves próprias configuradas fora do Git. A allowlist do Turnstile cobre `uonix.com.br`.

## Chaves

O módulo usa, nesta ordem:

1. Constantes definidas no `wp-config.php`.
2. Opções globais do Fluent Forms em `_fluentform_turnstile_details`.

Todos os widgets renderizados pelo helper Uonix usam `appearance="interaction-only"`, independentemente da aparência salva no Fluent Forms. Assim o Turnstile fica invisível quando a Cloudflare consegue validar sem desafio e só mostra interação quando necessário.

Exemplo para `wp-config.php`, acima de `/* That's all, stop editing! */`:

```php
define( 'UONIX_TURNSTILE_SITE_KEY', 'sua-site-key' );
define( 'UONIX_TURNSTILE_SECRET_KEY', 'sua-secret-key' );
```

Não versione a secret key no repositório. Em QA, DEV e produção provisória, mantenha as chaves no painel do Fluent Forms ou em constantes privadas do `wp-config.php` de cada ambiente. O contrato de URL e política por ambiente está em [ambientes.md](ambientes.md).

## Formulários protegidos

- `[uonix_form_captura]`, usado pelo `[uonix_sticky_lead]`.
- `[uonix_form_newsletter]`.
- `[uonix_form_trabalhe]`.
- Formulário nativo de comentários do WordPress.
- Checkout clássico do WooCommerce.

O token é validado no backend antes da gravação no Fluent Forms.
Nos comentários, a validação ocorre antes da criação do comentário. No checkout, a validação ocorre no hook de validação do WooCommerce antes da criação do pedido/orçamento.
