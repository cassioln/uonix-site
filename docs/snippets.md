# Snippets

Os snippets migrados ficam em:

```text
themes/kadence-child/snippets/
```

O carregamento é feito pelo `functions.php` do tema filho, simulando a organização do plugin Code Snippets.

## Regras

- Um snippet por domínio funcional sempre que possível.
- Nomes prefixados por número para preservar ordem previsível.
- Evitar duplicação de função, classe, shortcode, action e filter.
- Manter plugins de terceiros fora do Git.
- Para alterações de frontend, validar no local antes do push.

## Validação

```bash
find themes/kadence-child/snippets -name '*.php' -print0 | xargs -0 -n1 php -l
```

Se o host não tiver PHP instalado, valide dentro do container local:

```bash
podman exec uonix-local-app sh -lc 'find /var/www/html/wp-content/themes/kadence-child/snippets -name "*.php" -print0 | xargs -0 -n1 php -l'
```
