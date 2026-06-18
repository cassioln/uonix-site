# UONIX Theme Snippets

Arquivos `.php` nesta pasta sao carregados automaticamente pelo `functions.php` do tema filho.

Regras:

- Use prefixos numericos para controlar ordem, como `01-checkout.php`.
- Para ativar um snippet, mantenha o arquivo com extensao `.php`.
- Para desativar sem apagar, renomeie para `_nome-do-snippet.php` ou `nome-do-snippet.disabled.php`.
- Nao feche PHP com `?>` no final do arquivo.
- Antes de subir para producao, rode `php -l snippets/nome-do-snippet.php`.

O arquivo `index.php` nao e carregado pelo loader.
