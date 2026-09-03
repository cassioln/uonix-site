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

## Ambientes

Comportamentos que variam por ambiente devem usar o helper versionado em `mu-plugins/uonix-shared/environment.php`, sem duplicar hostnames em snippets visuais. O contrato canônico cobre produção (`https://uonix.com.br/`), QA (`https://uonix.ksio.dev/`), DEV (`https://test.uonix.ksio.dev/`) e local (`http://localhost:8080/`) em [ambientes.md](ambientes.md).

## Analytics, GTM e AdOpt

Em produção, o Google Site Kit deve ser a fonte de injeção do **container GTM existente**. GA4 e Meta Pixel continuam configurados dentro desse container; não habilite a colocação direta de código de Analytics ou Ads no Site Kit, pois duplicaria a medição.

O MU-plugin `mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php` permanece responsável pelo AdOpt/LGPD. Quando detecta que o módulo **Tag Manager** do Site Kit já está emitindo o container, ele suprime apenas o seu snippet GTM e o fallback `<noscript>`, preservando o banner e o fluxo de consentimento. Se a emissão pelo Site Kit for desabilitada, o MU-plugin volta a injetar o GTM configurado por ambiente como rollback.

Os IDs e a conexão OAuth do Site Kit são estado privado do WordPress e não pertencem ao Git.

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
