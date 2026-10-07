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
