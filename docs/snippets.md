# Snippets E MU-Plugins

O site usa uma arquitetura híbrida:

```text
mu-plugins/uonix-core.php
mu-plugins/uonix-*/
themes/kadence-child/snippets/
```

`mu-plugins/uonix-core.php` carrega os módulos globais do site. O tema filho carrega apenas snippets visuais que continuam acoplados ao Kadence.

## Onde Alterar

- Checkout, carrinho, Fluent Forms, formulários, shortcodes, admin e integrações: `mu-plugins/uonix-*`.
- Ajustes visuais de catálogo, produto e layout do tema: `themes/kadence-child/snippets/`.
- Helpers reutilizáveis: `mu-plugins/uonix-shared/`.

## Regras

- Cada módulo tem um `module.php` com lista explícita de arquivos.
- Nomes prefixados por número preservam a origem e ajudam na ordem de carga.
- Evitar duplicação de função, classe, shortcode, action e filter.
- Manter comentários objetivos, explicando apenas decisões e blocos não óbvios.
- Manter plugins de terceiros fora do Git.
- Para alterações de frontend, validar no local antes do push.

## Validação

```bash
find mu-plugins themes/kadence-child/snippets -name '*.php' -print0 | xargs -0 -n1 php -l
```

Se o host não tiver PHP instalado, valide dentro do container local:

```bash
podman exec uonix-local-app sh -lc 'find /var/www/html/wp-content/mu-plugins /var/www/html/wp-content/themes/kadence-child/snippets -name "*.php" -print0 | xargs -0 -n1 php -l'
```
