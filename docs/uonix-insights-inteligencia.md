# Central de Inteligência do Uônix Insights

Este documento é o contrato de projeto da Central de Inteligência — a camada do painel **Uônix Insights** que transforma dados do Google Search Console, GA4, plataformas de anúncio e do banco local em oportunidades acionáveis, relatório executivo por e-mail e alertas. Ele governa as decisões de arquitetura; a especificação de produto (o que cada módulo entrega ao usuário) está na issue [#193](https://github.com/cassioln/uonix-site/issues/193).

Em caso de conflito com o contrato de ambientes, [ambientes.md](ambientes.md) prevalece.

Não versionar neste documento `wp-config.php`, senhas, chaves, tokens, IDs de analytics, IDs de conta de anúncio, IDs de pixel, e-mails de service account ou destinatários de relatório. Onde um identificador é necessário para entender o código, referencie o arquivo em que ele está pinado.

## Por que este arquivo está em `docs/` e não em `docs/superpowers/`

`docs/superpowers/` é **gitignored** (`.gitignore:51`) desde o commit `8346b0a` (2026-08-21), que removeu 3.310 linhas de "planos gerados por agentes" e adicionou a regra. Qualquer spec escrita lá é artefato local: não chega a `master`, não passa por revisão em PR e não é contrato.

Consequência: os documentos `docs/superpowers/specs/2026-09-11-uonix-insights-marketing-operations-design.md` e `...-phase-2-ga4-search-console-design.md` descrevem com fidelidade o que foi construído nas fases 1 e 2, mas **nunca estiveram sob versionamento**. Servem como contexto histórico, não como norma. Este arquivo não os revoga — eles não têm o que revogar.

Specs que governam código pertencem a `docs/`, versionadas e revisadas.

## Base factual: o que já existe

| Peça | Onde | Estado |
|---|---|---|
| Painel e menu | `mu-plugins/uonix-admin/52-admin-analytics-dashboard.php:21-33` | Menu **top-level**, slug `uonix-analytics`, capability `edit_posts`, **sem submenus** |
| Abas | `52` (tablist) + allowlist em `53-admin-analytics-metrics.php:673-708` | Três níveis: `?tab=` → `?subtab=` → `?catalog_tab=`, validados por `sanitize_key` contra listas fechadas |
| Camada de dados GA4/GSC | `mu-plugins/uonix-admin/53-admin-analytics-metrics.php` | OAuth2 JWT RS256 escrito à mão, sem bibliotecas; escopos read-only; 8 chamadas por sync |
| Cache | `53`, `uonix_analytics_metrics_snapshot_option()` e `…_get_snapshot()` | Snapshot em `wp_options`, **sem TTL sobre a geração corrente**, por desenho; validade por frescor de 24h; snapshot velho é preservado como `stale` (fail-soft). Gerações **inalcançáveis** pela cascata de leitura são coletadas — ver *Coleta das gerações mortas de snapshot* |
| Concorrência | `53:619` | Lock por `add_option`, reivindicado após 600s |
| Sync agendada | `53:655-663` | WP-Cron `daily`, mais refresh manual via `admin_post` |
| Write-path autenticado | `53:736-748` | `admin-post` + `check_admin_referer` + `current_user_can('manage_options')` |
| Guard de e-mail por ambiente | `mu-plugins/uonix-integrations/49-email-environment-label.php:58-66` e `:172-208` | Rotula o assunto em QA, DEV e LOCAL; **bloqueia o envio apenas em QA e DEV** quando `UONIX_NONPROD_EMAIL_TO` está ausente. Em LOCAL **não há bloqueio** — o e-mail é enviado, e a contenção é o Mailpit do ambiente |
| Atribuição de origem | `mu-plugins/uonix-integrations/39-rastreamento-utm-atribuicao.php` | Captura gated por consentimento de marketing AdOpt; grava meta `_uonix_*` no pedido WooCommerce |
| Templates de e-mail HTML | `themes/kadence-child/woocommerce/emails/` | Padrão RFQ, com helper de imagem em `mu-plugins/uonix-woocommerce/29-rfq-email-imagens-produtos.php` |

Os identificadores de propriedade GA4 e de site do Search Console estão **hardcoded como default de parâmetro** em `53-admin-analytics-metrics.php:20`, ou seja, em arquivo versionado. Isso **contradiz** [ambientes.md](ambientes.md), que determina que IDs de analytics fiquem fora do Git.

Este documento **registra o desvio, não o legitima**. A correção é rastreada na issue #248. Enquanto ela não ocorrer, nenhum código novo pode repetir o padrão: identificador de plataforma novo entra por constante no `wp-config.php`, como já ocorre com o caminho da chave da service account.

## Correções à especificação original

A issue #193 descreve o produto corretamente e o repositório incorretamente. As sete premissas abaixo são falsas e não devem orientar implementação:

| #193 afirma | Realidade |
|---|---|
| Credenciais vêm do `.env` | **PHP não lê `.env`**: zero `getenv()`, `$_ENV`, `parse_ini_file` ou Dotenv em `mu-plugins/`, `themes/`, `plugins/`. O único leitor do `.env` é Bash (`scripts/lib/environment-map.sh`) |
| Token de desenvolvedor do Google Ads disponível | Não existe no repositório. Não há uma linha de código de Google Ads API |
| "Metadados CAPI no banco"; auditoria de EMQ | **Meta CAPI não está implementado**: nenhuma chamada a `graph.facebook.com` em código executável (`mu-plugins/`, `themes/`, `scripts/`); as ocorrências no repositório estão em documentação de skill. Sem CAPI não há EMQ para medir. O Pixel é client-side via GTM |
| "Transients de 12 horas já existentes no Uônix Insights" | **Zero transients no Insights.** O padrão é snapshot em `wp_options` com frescor de 24h e lock |
| `scripts/cron/send-weekly-executive-report.php` | `scripts/cron/` não existe; nenhum cronjob de servidor documentado; nenhum workflow com `schedule:` |
| `page=uonix-insights`, submenu `> Central de Inteligência` | Slug é `uonix-analytics`, menu top-level sem submenus |
| `_uonix_utm_*` no Fluent Forms | Lá é **uma linha JSON** com `meta_key='uonix_attribution'`, só para os formulários de captura, contato e newsletter, e **nunca lida** |

## Arquitetura

### Arquivos e carregamento

Módulos novos seguem `NN-slug.php` dentro de `mu-plugins/uonix-<domínio>/`, incluídos em **ordem explícita** pelo array do `module.php` do diretório. O número **não** governa a ordem de carga — o array governa. Precedente: o `53` (dados) já carrega antes do `52` (render).

| Arquivo | Papel |
|---|---|
| `mu-plugins/uonix-admin/55-admin-intelligence-metrics.php` | Camada de dados: regras de detecção, normalização e procedência |
| `mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php` | Render: aba, tabelas, cards |

Dados precedem render no array. Não inflar o `52`, que já tem mais de 1.500 linhas com CSS inline.

### Sincronização e cache

Um só relógio. A Central **estende** o snapshot existente do `53` (bump de versão com fallback de leitura da versão anterior, como já ocorreu de `v1` para `v2`), reaproveitando o mesmo lock, a mesma janela de frescor e a mesma cota de API.

É proibido criar um segundo pipeline de sync com option e lock próprios para as mesmas fontes: dois relógios divergem, e a divergência aparece como dois números diferentes para a mesma métrica no mesmo relatório.

### Procedência dos números

Todo bloco — card no painel e bloco no e-mail — declara **fonte e horário de sincronização**, e um bloco cujo snapshot não está fresco se declara `stale` por conta própria. Uma declaração global no rodapé não satisfaz este requisito: o relatório mistura fontes com frescores diferentes.

Nenhum número sem fonte declarada. Nenhum texto que prometa métrica, conversão, conformidade ou recebimento de dado que o WordPress não consiga provar.

### Credenciais

O canal de configuração sensível para PHP é `define()` no `wp-config.php` de cada ambiente, fora do Git, conforme [ambientes.md](ambientes.md). Chaves que hoje existem apenas no `.env` precisam ser migradas para constante antes de qualquer código depender delas — é etapa manual por ambiente, não detalhe de implementação.

Rejeitados: leitor de `.env` em `mu-plugins/` (coloca arquivo de segredo no document root, servível por HTTP num erro de configuração de servidor) e armazenamento em `wp_options` (tokens seriam copiados para backups e clones de ambiente).

O hardening existente em `53` é referência para qualquer credencial de arquivo: rejeitar symlink, rejeitar caminho dentro de `ABSPATH`, validar o host do endpoint de token.

### Aba no painel

A Central é uma aba nova no painel existente, adicionada à allowlist de `tab` no `53` e à tablist do `52`. Mantém a semântica ARIA e o funcionamento sem JavaScript já implementados: os painéis são escondidos server-side e as abas são links reais.

### Destinatários do relatório

Lista em `wp_options`. E-mail de destinatário não é segredo; token de API é — e por isso os dois vivem em lugares diferentes.

- **Escrita**: `admin-post` + `check_admin_referer` + `current_user_can('manage_options')`, espelhando `53:736-748`.
- **Leitura e visualização**: `edit_posts`, como o resto do Insights.
- **Anti-padrão a não replicar**: `mu-plugins/uonix-admin/40-admin-dados-globais-rfq.php:39-43` grava sem nonce e sem re-checagem de capability, e — o defeito mais grave — **o nome da option vem de input do usuário** (`update_option( 'uox_' . $chave, … )`) sem allowlist de chave. Os valores passam por `sanitize_text_field()`, então o problema **não** é falta de sanitização: é ausência de verificação de intenção e de allowlist. Rastreado na issue #249.

### Agendamento

WP-Cron `weekly`. O painel exibe o **próximo disparo real** lido do agendador (`wp_next_scheduled`) e o horário do último envio. O texto ao usuário declara apenas a **frequência** — "semanal" — sem horário e **sem período do dia**.

É proibido prometer horário ou período do dia na interface e no e-mail, incluindo formulações brandas como "às segundas pela manhã". WP-Cron dispara por tráfego, não por relógio, e o site não tem cronjob de servidor: a deriva pode atravessar o dia inteiro, o que torna "pela manhã" tão insustentável quanto "08:00". A única afirmação que o sistema consegue provar é a frequência somada ao próximo disparo agendado.

#### Quem cria o evento: o invariante dos destinatários

**Existe evento agendado se, e somente se, existe destinatário.** Um callback de `init` mantém esse invariante em toda requisição: agenda `weekly` quando há destinatário e nenhum evento, e remove o evento quando o último destinatário sai.

A lista de destinatários é, portanto, a **chave de ativação** — não um campo a mais. Isso já estava implícito em `uonix_intelligence_send_report()`, que recusa lista vazia com o motivo `no_recipients`; o agendamento passou a respeitar a mesma regra.

O que essa amarração compra, e que um `wp_schedule_event` manual por WP-CLI não dá:

- **Reprodutibilidade.** Agendamento feito à mão vive só na opção `cron`. Uma restauração de banco anterior ao agendamento, ou uma limpeza de cron, o perde em silêncio e ninguém lembra de refazer. Com o invariante, ele se restabelece na requisição seguinte, porque a lista de destinatários sobrevive.
- **Painel honesto.** Sem destinatário, o evento sai e o painel exibe "não agendado", em vez de prometer um envio que não aconteceria.

**A contenção por ambiente NÃO é automática, e depende do clone.** Não vale dizer que "a opção vive no banco de cada ambiente, então o ambiente clonado não agenda": `scripts/clone-environment.sh` copia `wp_options` da origem. Ele preserva `cron` no destino — o evento não viaja —, mas os destinatários viajariam, e o callback de `init` recriaria o evento no destino na requisição seguinte. O ambiente clonado passaria a exibir um "próximo disparo" concreto que ninguém configurou ali.

Por isso `uonix_executive_report_recipients` está em `protected_options_where()`, junto de `cron`, SMTP, Turnstile e captcha — todas configurações que ativam comportamento e não devem atravessar ambientes. `scripts/tests/test-clone-activation-options.sh` reprova o build se qualquer uma das duas sair da lista, ou se o invariante deixar de existir no módulo.

**O que faz o trabalho é o `DELETE`, não a preservação** — e a distinção importa para a próxima opção de ativação que alguém acrescentar. `snapshot_options()` gera uma instrução de replay por linha **que existe no destino**; num ambiente que nunca cadastrou destinatário não existe linha, e o snapshot sai vazio para essa opção. Quem impede a herança é o `DELETE FROM ... WHERE <predicado>` que `restore_options()` roda **antes** do replay: ele remove do destino a linha que acabou de vir da origem. Ou seja, "preservar a opção do destino" e "impedir que a opção da origem seja herdada" são efeitos diferentes, e é o segundo que fecha o furo. Estar no predicado garante os dois, porque o mesmo predicado governa snapshot e `DELETE`.

Sem essa proteção, o guard de e-mail de `49-email-environment-label.php` ainda conteria o **envio**, redirecionando para a caixa segura do ambiente. O que ele não conteria é a **afirmação** do painel. Contenção de envio não é contenção de ativação.

Duas guardas de falha fechada, ambas cobertas por teste: o evento **não** é criado se a recorrência `weekly` não estiver registrada — agendar sem ela produziria um disparo único disfarçado de semanal —, e um evento já existente **não** é reagendado, porque mover a data a cada requisição empurraria o envio para nunca.

Carregar o arquivo continua não escrevendo no agendador. Em mu-plugin o carregamento roda antes de `init` e antes dos plugins; quem agenda é o callback.

O teste que afirma isso **semeia um destinatário antes de carregar os módulos**, de propósito. Sem a semente ele passava por motivo errado: com a lista vazia, o callback sairia no guard de destinatários antes de tocar no agendador, e uma chamada indevida no carregamento não seria detectada. A asserção só tem valor quando existe destinatário e o agendador, ainda assim, continua vazio.

O horário do primeiro disparo — próxima segunda-feira, 08:00 no fuso do site — é **arbitrário de propósito**, pela mesma razão que a interface não pode prometê-lo.

## Módulo 3 — Oportunidades no Search Console

Primeiro módulo a ser entregue, por ser o único cujo caminho de dados já existe. O snapshot atual já persiste posição, CTR e páginas do Search Console — e a interface não renderiza nenhum dos três.

Parâmetros fixados. A #193 apresenta três faixas divergentes para "striking distance" (4–15, 4–12 e 4–10) no mesmo texto; vale a faixa desta tabela:

| Regra | Valor |
|---|---|
| Faixa de posição | **4 a 12** |
| Piso de ruído de volume | **mais de 5 impressões em 30 dias** |
| CTR | **abaixo de 3%** |
| Período do snapshot que alimenta a regra | **30 dias** |
| Priorização | **ordenação por impressões decrescentes**, e o relatório mostra as primeiras |

Descartado o critério "CTR 50% menor que a média da indústria": não há fonte auditável para essa média, e um número sem procedência viola a regra de procedência acima.

### O volume é piso de ruído, não critério de relevância

Quem separa oportunidade boa de ruim é a **ordenação**, não o piso.

O piso existe porque, neste volume, o critério de CTR já implica **zero clique**: para qualquer consulta com até 33 impressões, `cliques < 0,03 × impressões` só é satisfeito com nenhum clique. Toda linha admitida hoje tem zero clique — e abaixo de um punhado de impressões isso não é oportunidade perdida, é consulta que quase ninguém teve a chance de clicar. O piso descarta essa ausência de informação.

> [!NOTE]
> Consequência que vale saber ao ler o relatório: na escala atual do site, "taxa de clique abaixo de 3%" não está selecionando consultas com CTR ruim, e sim consultas **sem nenhum clique**. O critério continua defensável para distância de salto — ranquear e não receber clique é justamente o sintoma de título e descrição fracos —, mas a redação sugere uma gradação que o dado não tem. Revisar se o site crescer o suficiente para que o portão de CTR passe a admitir linhas com clique.

O valor anterior era "mais de 100 impressões" e vinha da especificação de produto, não de medição. Medido em produção em 2026-09-22, após sincronização real: **a consulta de maior volume do site inteiro tem 106 impressões em 30 dias.** Das 111 consultas, 40 passavam na faixa de posição e 106 no CTR — e **zero** passavam em faixa mais volume. O módulo devolvia zero por construção.

Isso passou por mais de cem testes no CI, 28 mutações detectadas e duas aprovações de revisão independente, porque todos verificavam a lógica contra dado sintético de um site grande. **É o caso concreto que justifica a porta de ativação existir separada da porta de merge**, e o motivo de o teste de fronteira agora incluir um universo na escala real deste site.

Qualquer revisão futura deste piso deve ser feita contra a distribuição medida, não contra referência de mercado: um limiar absoluto calibrado para outro site volta a zerar o módulo em silêncio.

A consulta por `query` do Search Console precisa sair do limite de 10 linhas usado hoje para a ordem de mil, para que a mineração tenha universo. A sanitização de query existente em `53:117-132` — que rejeita padrão de e-mail, telefone e URL — continua valendo integralmente: ampliar o volume amplia a superfície de PII na mesma proporção.

A sugestão de Title/Description é **determinística por regra** na primeira entrega. Integração com LLM é escopo separado.

### Minimização de PII no universo de mineração

Postura decidida na issue #253, a partir de medição em produção em 2026-09-22.

**O que a medição mostrou.** O universo real é de **111 consultas em 30 dias**, e o teto de `uonix_analytics_metrics_extended_query_limit()` (1000) **não trunca nada**. A mudança do #243 não foi "de 10 para 1000": foi de **amostra do topo para censo completo**. Sob o corte antigo de 10 linhas, com o topo do site em 106 impressões, uma consulta de 1 a 3 impressões praticamente não entrava — e é nessa cauda que a consulta identificável mora. Aquela proteção nunca foi projetada como filtro; era efeito colateral do truncamento, e removê-lo a removeu.

O filtro de `uonix_analytics_metrics_sanitize_query()` continua valendo e continua **insuficiente por construção**: das 18 classes de PII testadas na análise da issue, 14 escapam — nome próprio completo, razão social, CPF com barra, CEP curto, placa, e-mail escrito com "arroba", telefone com separador fora da classe permitida, entre outras. Ampliar o filtro com heurística de nome foi **rejeitado**: ele já produz falso positivo verificável no corpus técnico deste site (`nbr 16325 2014` e `nbr 5410 2004` são descartados hoje pela regra de 8 dígitos), e num universo de 111 consultas que devolve 5 oportunidades não há folga estatística — um descarte indevido é 20% do resultado.

**O que é persistido, e em que forma.**

| Campo | Texto da consulta | Métrica | Quem lê |
|---|---|---|---|
| `search_console.queries` (lista curta, 10 linhas) | **sim**, sempre | sim | gráfico "Principais consultas orgânicas" no `52` |
| `search_console.queries_extended` (universo), linha **dentro** da faixa de retenção | **sim** | sim | `uonix_intelligence_seo_opportunities()` no `55` |
| `search_console.queries_extended`, linha **fora** da faixa | **não** — a chave `query` é omitida | sim | ninguém lê o texto; a métrica alimenta a recalibração |

A forma de "texto ausente" é **omitir a chave**, não gravá-la vazia. `uonix_intelligence_seo_opportunities()` já abre com `isset( $row['query'], … )` e pula a linha quando falha — o mesmo guard que trata linha de snapshot v2 —, então a ausência entra por um caminho que já existia e já tinha teste. Omitir é também o que reduz bytes no `wp_options`, que é o objetivo, e o que faz `array_column( …, 'query' )` pular a linha. String vazia manteria `isset()` verdadeiro: um consumidor futuro que checasse só `isset` renderizaria linha fantasma em silêncio, enquanto a chave ausente falha alto.

**Por que a métrica fica.** A recalibração do piso tem de ser feita contra a **distribuição medida** (ver acima) — e distribuição é de impressões e posição, não de texto. Preservar a distribuição é, portanto, obrigatório, e preservar o texto não é. É isso que permite responder "quantas linhas entrariam se o piso caísse de 5 para 3" sem guardar uma única string a mais, e sem nova chamada de API. O que se perde é saber **quais** consultas entrariam: a recalibração passa a ter duas etapas — escolher o limiar pela distribuição, depois uma sincronização nova para ver os termos.

**O acoplamento entre retenção e regra é verificado, não presumido.** Quem decide o que é oportunidade é `uonix_intelligence_seo_rules()`, no `55`. Quem decide o que guarda texto é `uonix_analytics_metrics_query_text_retention()`, no `53`. O `53` **não pode** chamar o `55`: carrega antes dele, e inverter a dependência seria pior que o problema. Em vez disso:

| Limiar | Retenção (`53`) | Seleção (`55`) |
|---|---|---|
| Posição mínima | 3,0 | 4,0 |
| Posição máxima | 15,0 | 12,0 |
| Impressões | 3 ou mais (inclusivo) | mais de 5 (exclusivo) |

A faixa de retenção é **deliberadamente mais frouxa, com margem**, e `scripts/tests/test-query-text-retention-superset.php` reprova o build se ela deixar de conter a faixa de seleção — por comparação numérica, por exigência de margem estrita, por implicação rodando as duas funções reais sobre uma grade que cruza as duas fronteiras, e por equivalência de ponta a ponta: as oportunidades derivadas do universo minimizado têm de ser **idênticas** às derivadas do universo íntegro, com a distribuição de posição e impressões intacta. Sem esse teste, alargar a regra do `55` faria a oportunidade aparecer sem termo — ou desaparecer em silêncio, que é pior.

A mensagem de falha diz o que fazer: **alargar a retenção no `53`**, nunca estreitar a regra do `55` para caber nela. A regra é o produto; a retenção é a política de dado.

### Coleta das gerações mortas de snapshot

Gerações antigas de snapshot nunca foram apagadas. Medido em produção em 2026-09-22, quatro conviviam em `wp_options`: `…_snapshot_v1` (5.121 bytes), `…_v2_7` (7.207), `…_v2_30` (7.820) e `…_v3_30` (22.542). As legadas foram produzidas **antes** da minimização de texto e guardam o universo inteiro com o termo cru de cada linha.

`uonix_analytics_metrics_collect_legacy_snapshots()` apaga o que a cascata de leitura **não alcança mais**. O critério não é padrão de nome: é posição em `uonix_analytics_metrics_snapshot_option_cascade()`, que passou a ser a fonte única consultada tanto pela leitura quanto pela coleta. Apaga-se exatamente o que vem **depois da primeira entrada presente** — e como `uonix_analytics_metrics_get_snapshot()` para na primeira presente, o resto é inalcançável e a coleta é **no-op de leitura por construção**, não por coincidência de estado. O teste enumera as oito combinações de presença das três gerações de 30 dias e afere que a leitura devolve o mesmo antes e depois.

Consequências deliberadas:

- **O fallback em cascata continua sendo caminho válido, e é respeitado.** Se a geração corrente de uma janela ainda não existe, a legada que a substitui é o que o painel mostra hoje, e fica onde está. Em produção é o caso de `…_v2_7`: enquanto ninguém sincronizar a janela de 7 dias, é ele que responde. A coleta remove apenas o que está **estritamente abaixo** da geração de que o fallback depende naquele momento.
- **A coleta roda por janela, no sucesso da sincronização daquela janela** — o evento que torna o legado dela inalcançável. Uma janela que nunca é sincronizada mantém seu legado, o que é a escolha conservadora.
- **Não roda no caminho de falha.** `mark_stale()` promove o payload legado para a chave corrente; apagar dado numa sincronização que falhou trocaria "dado velho com aviso" por "sem dado" na primeira falha de rede.
- **O padrão continua valendo quando a geração corrente virar v4**, sem alteração no coletor: basta a cascata declarar a nova ordem.

### O que NÃO foi feito, e por quê

**Não há TTL sobre o snapshot corrente, e não deve haver.** `uonix_analytics_metrics_snapshot_is_fresh()` decide **exibição**, não validade: o módulo mostra dado velho com aviso de "desatualizado", e isso é comportamento documentado e desejado (fail-soft, ver a linha *Cache* na tabela de base factual). Um TTL no corrente trocaria "dado velho com aviso" por "indisponível" — regressão, não minimização. O que a coleta remove é geração **inalcançável**, que é outra coisa.

**O snapshot não foi excluído do backup, e a tentativa seria mecanicamente impossível.** `scripts/backup-remote-database.sh` faz `mysqldump` do banco inteiro, e `--ignore-table` exclui **tabela**, não **linha** — o snapshot é uma linha em `wp_options`. Partir o dump para contornar isso mexeria no caminho de backup, que é a última coisa que se quer frágil, por um ganho que a minimização de texto já entrega: cada backup passa a carregar o texto de ~5 consultas em vez de 111, na mesma proporção.

**O filtro de PII não foi ampliado** — ver a rejeição da heurística de nome próprio acima.

## Ordem de entrega dos módulos

Credencial faltante crescente:

1. **Módulo 3** — Oportunidades SEO (Search Console). Caminho de dados pronto.
2. **Módulos 4, 5 e 6** — Relatório Executivo, Alerta de Anomalias e Atribuição de Origem. Usam GA4 e o banco local; nenhuma credencial nova.
3. **Módulo 1** — Desperdício em Google Ads. Exige decidir entre leitura via GA4 e API direta; hoje não há token nem código.
4. **Módulo 2** — Desperdício em Meta Ads, **sem o indicador de EMQ**. Exige acesso aprovado.
5. **Módulos 7 e 8** — Radar de Concorrentes (exige crawler, inexistente) e Radar de Pautas (exige LLM).

O Módulo 4 não entrega Custo por Lead enquanto não houver fonte de gasto de anúncio. O Módulo 6 entrega **cobertura declarada**: como a captura de origem é gated por consentimento de marketing, parte dos leads nunca terá origem conhecida, e o relatório declara a fração coberta. É proibido substituir o dado negado por inferência server-side equivalente — seria o mesmo tratamento de dado que o consentimento nega, por outra via.

## Exclusões explícitas

- Não criar um segundo pipeline de sincronização para fontes que o `53` já consulta.
- Não exibir número sem fonte e horário de sincronização.
- Não prometer horário exato de disparo.
- Não ler segredo a partir de arquivo dentro do document root.
- Não gravar token de API em `wp_options`.
- Não contornar o guard de e-mail por ambiente.
- Não inferir origem de lead cujo consentimento de marketing foi negado.
- Não escrever spec de arquitetura em `docs/superpowers/`.
- Não criar TTL que apague o snapshot **corrente**: dado velho com aviso é comportamento desejado, "indisponível" é regressão.
- Não persistir texto de consulta fora da faixa de `uonix_analytics_metrics_query_text_retention()`, e não descartar **métrica** junto com o texto.
- Não fazer o `53` chamar o `55`: ele carrega antes. O acoplamento entre retenção e regra é declarado em teste, não em `require`.

## Portas de qualidade

Os testes do projeto são scripts autônomos com stubs do WordPress escritos à mão e respostas de API fixas. Eles provam a normalização do dado — **não** provam que a API externa devolve o que se supôs. Por isso há duas portas distintas:

| Porta | Critério |
|---|---|
| **Merge** | Teste novo em `scripts/tests/` + step correspondente em `.github/workflows/validate.yml` + `php -l` limpo na matriz de versões do CI |
| **Ativação** | Smoke contra a API real, executado à mão, fora do CI, com resultado registrado |

O step no workflow é obrigatório: `scripts/tests/test-ci-covers-all-tests.sh` cruza o diretório de testes com o workflow e reprova o build quando um teste não tem step.

Nenhum módulo é ativado — nem cron, nem envio automático — antes de o smoke passar. Cron registrado e inativo é estado válido e esperado.

A porta de ativação do Módulo 3 foi atravessada em 2026-09-22 (ver *Registro da porta de ativação*), e é o que autoriza o agendamento automático descrito em *Agendamento*. A ativação continua sendo uma decisão humana: ela é expressa por **cadastrar um destinatário**, não por rodar um comando. Enquanto a lista está vazia, o módulo segue registrado e inativo — o mesmo estado válido de antes.

## Licenciamento

O controle de licença da Central (status e data-limite) é configurado por constante no `wp-config.php` e ainda não existe no código. Comportamento exigido quando suspenso ou expirado: disparo automático de e-mail e alertas pausa silenciosamente, a interface exibe aviso de contato, e **nenhuma funcionalidade essencial da loja ou do site é afetada** — sem erro de PHP, sem quebra de página.

Implementação obrigatória **antes** do primeiro envio a destinatário externo. Enquanto o único leitor do relatório é o operador, o controle não protege nada; a partir do momento em que há destinatário externo, ele é a única alavanca de suspensão.

## Validação dos números

O Search Console do domínio de produção só tem dados de produção. Em `local` valida-se lógica e HTML; não se valida se o número está certo. Por isso a validação de números acontece em QA, com o guard de deploy correspondente habilitado e `UONIX_NONPROD_EMAIL_TO` definido no `wp-config.php` daquele ambiente — sem essa constante o guard bloqueia o envio, registrando em `error_log`, disparando a ação `wp_mail_failed` e exibindo aviso no admin — a falha é observável, mas o relatório não chega.

O primeiro destinatário do relatório é o operador, por semanas, antes de qualquer envio a destinatário externo. Um número errado no primeiro e-mail externo custa mais que o atraso.

## Registro da porta de ativação

Executado à mão em produção em 2026-09-22, no commit `ba55b8e`.

| Verificação | Resultado |
|---|---|
| Módulo carregado (funções de regra, painel e envio presentes em runtime) | sim |
| Piso de impressões publicado | 5 |
| Snapshot fresco | sim — sincronizado em `2026-09-22T03:40:41Z` |
| Universo de consultas | 111 |
| Oportunidades devolvidas | 5 |
| Envio real do relatório | sim — 1 destinatário, sem motivo de falha |
| Evento semanal agendado | **não** |

As cinco oportunidades que o primeiro relatório levou:

| # | Consulta | Posição | Impressões |
|---|---|---|---|
| 1 | olhal de ancoragem | 11,4 | 53 |
| 2 | projeto de andaimes | 10,3 | 28 |
| 3 | teste de ancoragem predial | 9,6 | 22 |
| 4 | barra roscada de aço inox | 10,5 | 10 |
| 5 | barra roscada aço inox | 10,3 | 8 |

Este é o **baseline**. Um relatório futuro que chegue com zero linhas, sem que o site tenha perdido tráfego, é regressão — não ausência de oportunidade. Foi exatamente assim que o limiar antigo falhou em silêncio, e é contra estes números que a próxima revisão do piso deve ser conferida.

O destinatário registrado é a caixa do operador. O controle de licença da Central ainda não existe no código; enquanto não existir, o destinatário não pode deixar de ser o operador — ver *Licenciamento*.

### O que este registro não prova

A sincronização com GA4 e Search Console **não foi disparada por esta execução**. A evidência de que o caminho até a API real funciona em produção é indireta: o snapshot estava fresco, com horário de sincronização do próprio dia. Uma execução que force a sincronização — `scripts/maintenance/smoke-intelligence-seo.php` com o argumento posicional `sync` — ainda não foi registrada aqui.

Também não foi verificado o relatório renderizado: o envio reporta sucesso do `wp_mail`, não que o HTML tenha chegado legível. Isso depende de alguém abrir a caixa.

## Documentos relacionados

- [ambientes.md](ambientes.md) — contrato canônico de ambientes, constantes e guards de deploy.
- [deploy.md](deploy.md) — checklist pós-deploy e política de rollback.
- [estrutura.md](estrutura.md) — o que é e o que não é versionado.
