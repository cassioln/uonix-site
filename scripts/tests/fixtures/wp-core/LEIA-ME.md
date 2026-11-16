# Pacotes JavaScript do núcleo do WordPress (cópia para teste)

Cópia exata, sem alteração, de cinco pacotes de `wp-includes/js/dist/` do WordPress **7.1.4**, buscados
em produção (`https://uonix.com.br/wp-includes/js/dist/`) em 2026-11-16:

| Arquivo | Pacote |
|---|---|
| `hooks.js` | `@wordpress/hooks` |
| `private-apis.js` | `@wordpress/private-apis` |
| `url.js` | `@wordpress/url` |
| `i18n.js` | `@wordpress/i18n` |
| `api-fetch.js` | `@wordpress/api-fetch` |

**Para que serve:** `scripts/tests/test-admin-widgets-lote.mjs` carrega o `api-fetch.js` REAL num `vm`,
com o mesmo `createRootURLMiddleware` que o WordPress registra, e confere que o desvio
`/batch/v1` → `/uonix/v1/lote` (`mu-plugins/uonix-admin/assets/js/admin-widgets-lote.js`) roda **antes**
do middleware de URL. Com um stub de `wp.apiFetch.use()` isso não era provado (#365).

**Licença:** GPL-2.0-or-later, como o WordPress. Não editar estes arquivos: o teste depende de serem
idênticos aos do núcleo.

**Quando o WordPress mudar de versão:** rebaixar os cinco arquivos, atualizar a versão acima e rodar o
teste. Se a ordem do middleware mudar no núcleo, é exatamente isso que o teste deve acusar.
