# Atualização de plugins

Plugins e o tema pai de produção são atualizados por uma esteira, e não pelo painel nem pela atualização automática do WordPress. O desenho completo, com as próximas entregas, está na issue #393. Este documento cobre o que já existe.

## Temas (#445)

O tema pai `kadence` (camada crítica) passa pela mesma esteira, na mesma ordem e com a mesma quarentena de 7 dias. Diferenças:

- O job usa `wp theme update/get` e guarda a pasta em `wp-content/themes/<slug>` como `tema-<slug>.tar.gz`. A linha da lista do job ganhou o 5º campo, `tipo` (`plugin` ou `tema`); sem ele vale `plugin`.
- A API de temas do wordpress.org dá versão e data (a quarentena funciona), mas **não traz changelog**. O tema fica sem "novidades" no e-mail, que aponta para `wordpress.org/themes/<slug>/`. Na chave do mapa da API o tema leva o prefixo `tema:`, porque plugin e tema podem ter o mesmo slug.
- `kadence-child` (camada `propria`) e `twentytwentythree` (`decisao_pendente`) nunca são aplicados.
- Restaurar o tema ativo move a pasta atual para `descartado-<slug>` e devolve a do tar, como nos plugins. O smoke roda em seguida.

## Regra de ouro

**O que vai para produção é exatamente o que foi ensaiado no local.** Atualiza-se para a versão ensaiada (`--version=<v>`), nunca "para a última".

Por isso, desde 2026-10-05, a atualização automática está **desligada** em todos os plugins e temas de produção. O `inventario` reprova (bandeira) se alguma voltar a ser ligada.

## Inventário de produção (só leitura)

Requer a janela SSH da Locaweb aberta. Rode do checkout principal, que tem o `.env`:

```bash
bash scripts/plugins/atualizar.sh inventario
```

O comando faz uma única conexão SSH, cortada em 150 s (`UONIX_PLUGINS_LIMITE_SSH`) se a janela estiver fechada. Ele salva o JSON lido em `tmp/plugins/` e imprime, por camada, o que tem atualização pendente.

| Saída | Significado |
|---|---|
| `0` | Nenhuma bandeira. |
| `10` | Há bandeira: versão major na camada crítica, plugin ou tema sem classificação, atualização automática ligada, ou versão nova indisponível por exigir PHP ou WordPress acima do site. |
| `1`/`2` | A leitura falhou. Quando a saída de produção vem incompleta, ela fica guardada em `tmp/plugins/*.bruto.txt`. |

Para reclassificar um JSON já salvo, sem conectar: `atualizar.sh inventario --entrada=tmp/plugins/<arquivo>.json`.

## Ensaio local — muta só o ambiente local

Requer a janela SSH aberta (para o inventário e o clone) e o ambiente local no ar. Rode do checkout principal:

```bash
bash scripts/plugins/atualizar.sh ensaiar
```

O comando faz, nesta ordem:

1. **Inventário** de produção, que é só leitura.
2. **Plano** (`plano.py`): para cada pendência, decide se entra e em que ordem, ou por que fica de fora. Fica de fora:
   - versão lançada há menos dias que a quarentena da camada;
   - major na camada crítica;
   - camada `decisao_pendente` ou `propria`;
   - versão que exige PHP ou WordPress acima do site.

   A quarentena é **dispensada quando o changelog do intervalo cita correção de segurança**. Precisa ser correção:
   - "security" diante de recurso ("security settings page", "Security: new settings tab") não conta;
   - classe **forte** (SQL/object injection, privilege escalation, arbitrary file…, cross-site scripting/request forgery por extenso) e CVE contam sozinhos;
   - classe **fraca** (XSS, CSRF, SSRF, open redirect, vulnerability) conta com verbo de correção ("Fixed", "prevent", "sanitize", "escape", "protect against"…), qualificador de aviso ("Stored XSS", "Authenticated … Cross-Site Scripting", "Potential XSS") ou na forma "XSS vulnerability", e não diante de nome de recurso ("XSS filter toggle", "CSRF token"), salvo se a linha falar em bypass, exploit ou attack. A data e o changelog vêm da API do wordpress.org.
3. **Clone `prod → local`.** Com `--reusar-clone`, reaproveita um clone desta esteira de menos de 24 h que **nenhum ensaio tocou** (`tmp/plugins/ultimo-clone.json`). O marcador é apagado antes da primeira atualização: depois dela o banco local já está migrado, e um novo ensaio sobre ele não testaria a migração a partir do estado de produção.
4. **Alinhamento:** versões e ativação do local iguais às de produção. O clone exclui alguns plugins e preserva a lista de ativos do destino. Plugins das camadas `decisao_pendente` e `propria` não são alinhados por versão. Um item **ativo em produção e ausente no local** reprova o ensaio (saída 30), mesmo nessas camadas: o smoke rodaria sem ele, por exemplo com outro tema. O `fluent-smtp` fica inativo no local, que usa o Mailpit. Por isso ele **nunca entra no lock**: o código dele não carrega no ensaio e precisa ser ensaiado à parte.
5. **Backup** do banco local por `mariadb-dump`, validado pelo número de tabelas e pela linha `Dump completed`.
6. **Smoke antes**, como linha de base. Depois, a atualização para as **versões exatas** do plano:
   - lote (acoplada e comum) primeiro;
   - a camada crítica um por vez, com o WooCommerce por último;
   - `wp wc update` depois do WooCommerce;
   - contratos estáticos do que mudou e smoke depois de cada etapa.
7. **Lock** (`lock.json`), gravado **só quando tudo fica verde**, com as versões que a entrega 3 vai aplicar em produção.

Tudo fica em `tmp/plugins/ensaio-<data>/`: inventário, plano, backup, smokes, relatório e lock.

| Saída | Significado |
|---|---|
| `0` | Verde, com o lock gravado, ou nada a ensaiar. |
| `20` | Regressão: um check verde na linha de base ficou vermelho ou sumiu. Também sai assim com contrato quebrado, atualização que falhou ou instalou versão diferente da do plano, ou erro durante uma etapa (por exemplo, um plugin que derruba o WordPress). O relatório diz em qual etapa. |
| `30` | Falha de preparação: clone, alinhamento, backup ou WP-CLI. Nada foi atualizado. |

Opções: `--aceitar-major=a,b`, `--dispensar-quarentena=a,b`, `--reusar-clone`, `--entrada=<inventário salvo>`.

### O que o smoke confere

A lista sai de `ensaio.py`, na função `smoke()`:

- **Páginas:** home, blog, serviços, produtos, cotação, sitemap, REST, login, um produto, um post e uma página de serviço (do Pods). Cada uma precisa responder 200, sem erro fatal.
- **Megamenu:** itens e widgets nas colunas.
- **SEO:** `meta description` e `ld+json`.
- **Tema:** o CSS global do `kadence`, a classe `wp-theme-kadence` e a `wp-child-theme-kadence-child` no `<body>`, e o rodapé `#colophon`. Medidos com o `kadence-child` ativo (1 de cada) e com o `twentytwentythree` no lugar (0 de cada).
- **Cotação:** adicionar um produto pela Store API.
- **Contratos em execução:** post type `servicos` e rotas da Store API.
- **WooCommerce:** banco migrado.
- **Log:** nenhum `PHP Fatal` novo no `debug.log`.

Cada marcador foi **medido desligando o plugin** no local, e o check precisa ficar vermelho nesse caso. Exemplo: o texto `mega-menu-item` sozinho não serve, porque o nosso CSS inline o repete. Sem o Max Mega Menu, sobravam 71 ocorrências. Ao mudar um marcador, repita a medição.

O WP-CLI do ensaio roda dentro do container do site, no mesmo PHP que serve as páginas, a partir de um `wp-cli.phar` conferido pelo sha512 publicado.

## Aplicação em produção

Requer a janela SSH aberta. Rode do checkout principal, com o lock de um ensaio verde de no máximo 72 h:

```bash
bash scripts/plugins/atualizar.sh aplicar-producao --lock=tmp/plugins/ensaio-<data>/lock.json
```

Sem `--confirmacao`, o comando só valida o lock e mostra a frase exata. Ela inclui o sha256 curto do lock, e é preciso repeti-la:

```bash
bash scripts/plugins/atualizar.sh aplicar-producao --lock=tmp/plugins/ensaio-<data>/lock.json --confirmacao='ATUALIZAR PROD <sha12>'
```

O comando faz, nesta ordem:

1. **Validação do lock** (`lock.py`). O lock é recusado se o sha256 não conferir, se o resultado não for verde, se tiver mais de 72 h (`UONIX_PLUGINS_LOCK_MAX_HORAS`), se tiver algum item cujo código não carrega no local ou se tiver um slug inválido. Plugin e tema são aceitos (campo `tipo` do item); qualquer outro tipo é recusado. Plugin e tema são aceitos (`tipo` do item); outro tipo é recusado.
2. **Sonda da janela SSH**, com corte em 30 s. Janela fechada pendura em silêncio; aqui ela falha rápido.
3. **Backup do banco** de produção (`backup-remote-database.sh`), sob cão de guarda.
4. **Job remoto** (`remoto-aplicar.sh`). Ele é enviado ao servidor e roda **desacoplado da conexão** (`nohup`, com log e status em `_uonix-deploy-backups/plugins-<data>/`):
   - **preflight:** produção precisa estar nas versões "de" do lock; se divergir, sai 30 sem alterar nada;
   - backup das pastas dos plugins e dos temas (`plugin-<slug>.tar.gz` e `tema-<slug>.tar.gz`); o `tar` que sai com 1 (arquivo mudou durante a leitura, comum com o site vivo) é repetido até 3 vezes, 5 s entre elas (`TAR_TENTATIVAS`, `TAR_ESPERA`), e um erro de verdade (2 ou mais) aborta na hora; o arquivo gerado precisa ser legível (`tar -tzf`);
   - modo de manutenção e atualização em **etapas**: os plugins fora da camada crítica formam um lote, e cada crítico é uma etapa sozinho; a versão instalada é conferida, e o `wp wc update` também;
   - smoke depois de cada etapa e depois da limpeza de cache.

   **Se uma etapa falhar:**
   1. os plugins **dela** são restaurados, sem apagar nada; a pasta quebrada vai para `descartado-<slug>` no backup;
   2. se o site seguir vermelho, **tudo o que foi aplicado nesta execução** é restaurado, em ordem reversa.

   Os plugins de etapas anteriores que passaram no smoke ficam aplicados.

   **A restauração devolve as pastas, não o banco.** Migrações de banco (`wp wc update`, upgrades de plugin) não são desfeitas. Se for preciso voltar o banco, o dump está em `_uonix-deploy-backups/plugins-<data>/db-prod-<data>.sql.gz`. Restaurar esse dump desfaz também o que foi gravado depois dele, como solicitações de orçamento, e por isso é uma decisão manual.
5. **Smoke de produção** do Mac (`smoke_producao.py`), só leitura, com os mesmos marcadores do ensaio e as páginas extras do ensaio (produto, post, serviço).
6. **Registro** em `aplicacao-<backup>.json`, ao lado do lock, com o log do job em `aplicar-<backup>.log`.
7. **Relatório por e-mail** (`relatorio_email.py`), veja abaixo. Ele roda em toda saída que chegou ao job (0, 20, 30 do preflight, 40 e 50) e **nunca muda a saída**: se o envio falhar, só aparece um aviso com o comando para reenviar.

Aplicar o mesmo lock duas vezes é seguro: na segunda vez, produção já está nas versões "para", o preflight detecta drift e nada muda.

| Saída | Significado |
|---|---|
| `0` | Aplicado, com o smoke do job e o smoke de produção do Mac verdes. |
| `20` | Parou numa etapa, restaurou, e o site ficou **verde** de novo, nos dois smokes. |
| `30` | Recusado sem alterar nada: lock, confirmação, janela, backup, drift ou site já falhando antes. |
| `40` | O job não terminou normalmente: a conexão caiu ou ele foi interrompido por sinal. **Não reexecute:** rode `estado-producao`. |
| `50` | **Intervenção necessária:** uma restauração falhou, o site seguiu vermelho depois de restaurar tudo, ou o smoke do Mac ficou vermelho depois de o job dizer que estava verde. Os caminhos do backup são impressos. |

O smoke de produção do Mac roda depois de **toda** execução que mudou produção (0, 20 e 50). Ele é a segunda opinião e não depende de o job ter avaliado certo.

Se o job receber TERM, INT ou HUP, ele desliga a manutenção e grava o status 143, que o Mac reporta como 40. **SIGKILL não pode ser tratado**, e o matador de processos da hospedagem compartilhada usa SIGKILL. Nesse caso o status fica ausente e o `.maintenance` pode ficar no lugar; o WordPress o ignora depois de 10 minutos. `estado-producao` mostra as duas coisas.

### Relatório por e-mail

Ao fim da aplicação, sai um e-mail de `cassio@ksio.dev`, assinado por Cassio Lima (ksiodev). O destino depende do resultado:

- **Cliente** (`RELATORIO_PARA`): só quando tudo deu certo, isto é, saída 0, todo plugin confirmado na versão nova e smoke de produção todo verde. O e-mail é pessoal e não menciona automação.
- **Técnico** (`RELATORIO_PARA_TECNICO`, padrão `uonix@ksio.dev`): em **qualquer** outro caso, inclusive saída 0 com ressalva. O cliente não é notificado. O e-mail técnico traz os motivos, a versão de cada item, o registro, os comandos de diagnóstico e restauração e as últimas 120 linhas do log do job.

O e-mail do cliente segue a linguagem visual do ksio.dev: fundo `#080d12`, ciano `#00c9eb` e o símbolo `<K>`. O símbolo vai como PNG inline (`scripts/plugins/marca/ksio-mark.png`, gerado com `rsvg-convert -w 171` a partir de `assets/brand/ksio-mark.svg` do site), porque SVG não aparece no Gmail nem no Outlook. Cada plugin tem o nome ligado à página oficial no wordpress.org (só quando a API respondeu, então plugin pago fica sem link) e as novidades da versão nova, até 8 itens, com link para o changelog completo. A seção "Quarentena" explica ao cliente o prazo de observação das versões novas (lido do motivo do plano) e quando cada item entra; os outros motivos do plano são internos e ficam no relatório técnico.

O relatório do cliente traz:
- o resultado;
- cada plugin, com nome comercial e função, e a versão de antes e a que ficou instalada;
- as correções de segurança e as notas do fabricante;
- o smoke de produção, com os pontos que falharam;
- o que ficou para o próximo ciclo;
- o backup.

A versão "depois" é a que o WP-CLI de produção leu no fim do job (bloco `== versões finais` do log), nunca a do plano. Sem log, ela aparece como "não confirmada". A prévia HTML fica em `relatorio-<backup>.html`, ao lado do lock.

A configuração fica no `.env` do checkout principal:

| Variável | Padrão |
|---|---|
| `RELATORIO_SMTP_HOST` / `RELATORIO_SMTP_PORTA` | `mail.ksio.dev` / `465` (SSL) |
| `RELATORIO_SMTP_USUARIO` | `cassio@ksio.dev` |
| `RELATORIO_SMTP_SENHA` ou `RELATORIO_SMTP_SENHA_FILE` | obrigatória |
| `RELATORIO_DE` | o usuário |
| `RELATORIO_PARA` | o remetente; vários separados por vírgula. O destino do cliente é `marketing@uonix.com.br`. |
| `RELATORIO_PARA_TECNICO` | `uonix@ksio.dev` |
| `RELATORIO_CCO` | `uonix@ksio.dev`: cópia oculta do e-mail do cliente, só no envelope SMTP (o cliente não vê). Vazio desliga. |

`UONIX_RELATORIO_EMAIL=0` desliga o envio; a prévia é gravada mesmo assim.

Para reenviar, ou só gerar a prévia de uma aplicação já registrada:

```bash
bash scripts/plugins/atualizar.sh relatorio --aplicacao=tmp/plugins/ensaio-<data>/aplicacao-<backup>.json [--sem-enviar]
```

**Para testar o template sem enviar nada para fora**, use o Mailpit do ambiente local (`http://localhost:8025`). Ele captura tudo e tem uma checagem de compatibilidade do HTML com os clientes de e-mail. Sem SSL só é aceito para `localhost`, e nesse caso a senha nem é carregada.

```bash
RELATORIO_SMTP_HOST=localhost RELATORIO_SMTP_PORTA=1025 RELATORIO_SMTP_SEGURANCA=nenhuma python3 scripts/plugins/relatorio_email.py --dir tmp/plugins/ensaio-<data> --aplicacao tmp/plugins/ensaio-<data>/aplicacao-<backup>.json --saida-final 0 --log tmp/plugins/ensaio-<data>/aplicar-<backup>.log --enviar
```

### Depois de uma queda: `estado-producao`

```bash
bash scripts/plugins/atualizar.sh estado-producao [--backup=plugins-<data>]
```

O comando é só leitura e mostra:
- se a manutenção está ativa;
- se há job vivo;
- o status e o fim do log do último job, ou do job indicado.

Em 2026-10-07, um job ainda ligado à conexão morreu no meio e deixou o site em manutenção por cerca de 5 minutos. Por isso o job roda desacoplado, e o primeiro passo depois de qualquer queda é **ler o estado**, nunca reexecutar.

## Camadas — `ops/plugins/politica.json`

| Camada | Quando | Aplicação | Quarentena | Major |
|---|---|---|---|---|
| `critica` | Orçamento, lead, e-mail, SEO, navegação, cache e páginas de serviço. | Um por vez, com smoke depois de cada um | 7 dias após o lançamento, salvo correção de segurança | Bloqueia: exige decisão |
| `acoplada` | Nosso código depende de símbolo, marcação ou opção do plugin. | Em lote | — | Alerta |
| `comum` | Sem dependência medida e fora dos fluxos críticos. | Em lote | — | Alerta |
| `decisao_pendente` | Inativo ou ferramenta pontual; decidir entre remover e manter. | Nunca pela esteira | — | Não se aplica |
| `propria` | Código deste repositório (`kadence-child`). | Só por deploy | — | Não se aplica |

Todo plugin que aparece em produção precisa estar na política. Um plugin novo sem classificação vira bandeira no inventário.

## Contratos — `ops/plugins/contratos.json`

Um contrato é o que mu-plugins e tema filho usam de um plugin: funções, classes, hooks que ele precisa continuar disparando, shortcodes e marcadores. Marcador é um texto que precisa continuar no fonte do plugin, como uma classe CSS que estilizamos, um nome de opção que lemos ou o trecho que monta o nome de um hook dinâmico. A verificação lê o fonte instalado, sem carregar o WordPress:

```bash
bash scripts/plugins/atualizar.sh contratos            # todos, no ambiente local
bash scripts/plugins/atualizar.sh contratos fluentform # um plugin
```

Ela sai com `20` quando algum item sumiu e diz qual mu-plugin depende dele. Isso pega, **antes de qualquer teste de tela**, a atualização que renomeia uma classe interna. Exemplo: 6 mu-plugins pegam `FluentForm\App\Services\Form\SubmissionHandlerService` do container do Fluent Forms, e um rename quebraria todos os formulários em silêncio.

`post_types`, `rotas_rest` e `hooks_dinamicos` não se provam pelo fonte. Eles ficam no contrato para a checagem em execução do ensaio local, a entrega 2 da #393.

### Ao criar ou mudar um mu-plugin que usa um plugin de terceiros

1. Acrescente o símbolo ao contrato do plugin, com o arquivo em `usado_em`.
2. Se o plugin ainda não tinha contrato, marque `"contrato": true` na política.
3. Rode `atualizar.sh contratos <slug>` contra o local.

O teste `scripts/tests/test-plugins-politica-contratos.py` garante, no CI, que política e contratos são coerentes e que todo `usado_em` aponta para um arquivo existente.
