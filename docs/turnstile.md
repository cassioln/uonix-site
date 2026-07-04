# Cloudflare Turnstile

Os formulários protegidos da Uonix usam o módulo `mu-plugins/uonix-integrations/34-turnstile-custom-forms.php`.
Essa proteção não depende do Loginizer Pro.

## Ambientes

- `local`: Turnstile fica desativado automaticamente.
- `staging` / `production`: Turnstile fica ativo quando encontra chaves configuradas.

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

Não versione a secret key no repositório. Em QA e produção, prefira manter as chaves no painel do Fluent Forms ou em constantes do `wp-config.php` do servidor.

## Formulários protegidos

- `[uonix_form_captura]`, usado pelo `[uonix_sticky_lead]`.
- `[uonix_form_newsletter]`.
- `[uonix_form_trabalhe]`.
- Formulário nativo de comentários do WordPress.
- Checkout clássico do WooCommerce.

O token é validado no backend antes da gravação no Fluent Forms.
Nos comentários, a validação ocorre antes da criação do comentário. No checkout, a validação ocorre no hook de validação do WooCommerce antes da criação do pedido/orçamento.
