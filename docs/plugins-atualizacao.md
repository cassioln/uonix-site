# Atualização de plugins

Plugins e o tema pai de produção são atualizados por uma esteira, e não pelo painel nem pela atualização automática do WordPress. O desenho completo, com as próximas entregas, está na issue #393. Este documento cobre o que já existe.

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

   A quarentena é **dispensada quando o changelog do intervalo cita correção de segurança**. A data e o changelog vêm da API do wordpress.org.
3. **Clone `prod → local`.** Com `--reusar-clone`, reaproveita um clone desta esteira de menos de 24 h que **nenhum ensaio tocou** (`tmp/plugins/ultimo-clone.json`). O marcador é apagado antes da primeira atualização: depois dela o banco local já está migrado, e um novo ensaio sobre ele não testaria a migração a partir do estado de produção.
4. **Alinhamento:** versões e ativação do local iguais às de produção. O clone exclui alguns plugins e preserva a lista de ativos do destino. Plugins das camadas `decisao_pendente` e `propria` não são alinhados por versão. O `fluent-smtp` fica inativo no local, que usa o Mailpit. Por isso ele **nunca entra no lock**: o código dele não carrega no ensaio e precisa ser ensaiado à parte.
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
- **Cotação:** adicionar um produto pela Store API.
- **Contratos em execução:** post type `servicos` e rotas da Store API.
- **WooCommerce:** banco migrado.
- **Log:** nenhum `PHP Fatal` novo no `debug.log`.

Cada marcador foi **medido desligando o plugin** no local, e o check precisa ficar vermelho nesse caso. Exemplo: o texto `mega-menu-item` sozinho não serve, porque o nosso CSS inline o repete. Sem o Max Mega Menu, sobravam 71 ocorrências. Ao mudar um marcador, repita a medição.

O WP-CLI do ensaio roda dentro do container do site, no mesmo PHP que serve as páginas, a partir de um `wp-cli.phar` conferido pelo sha512 publicado.

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
