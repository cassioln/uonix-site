# Cloudflare Turnstile

Os formulários customizados da Uonix usam o módulo `mu-plugins/uonix-integrations/34-turnstile-custom-forms.php`.

## Ambientes

- `local`: Turnstile fica desativado automaticamente.
- `staging` / `production`: Turnstile fica ativo quando encontra chaves configuradas.

## Chaves

O módulo usa, nesta ordem:

1. Constantes definidas no `wp-config.php`.
2. Opções globais do Fluent Forms em `_fluentform_turnstile_details`.

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

O token é validado no backend antes da gravação no Fluent Forms.
