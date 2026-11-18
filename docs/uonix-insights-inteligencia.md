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
| Painel e menu | Página em `mu-plugins/uonix-admin/52-admin-analytics-dashboard.php`; menu registrado em `49-admin-ksio-governanca.php` (PR #310) | Slug `uonix-analytics`, capability `edit_posts`. Para o usuário `ksiodev`, é **submenu de `ksio.dev`**. Para os demais, é **item próprio do menu, só se liberado** na tela "Visibilidade para usuários" (padrão: oculto). Oculto, a página e a atualização de métricas (`53`) recusam — ver [clone-ambientes.md](clone-ambientes.md#como-usar-o-painel-ksiodev). A aba Configurações só é alterada pelo `ksiodev`, mesmo com o Insights liberado — ver *Destinatários do relatório* |
| Abas | `52` (tablist) + allowlist em `53-admin-analytics-metrics.php:673-708` | Três níveis: `?tab=` → `?subtab=` → `?catalog_tab=`, validados por `sanitize_key` contra listas fechadas |
| Camada de dados GA4/GSC | `mu-plugins/uonix-admin/53-admin-analytics-metrics.php` | OAuth2 JWT RS256 escrito à mão, sem bibliotecas; escopos read-only; 10 chamadas por sync no mínimo (4 do GA4 e 6 da Search Console, #300; a leitura de visualizações do GA4 pagina e pode fazer mais) |
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
| `scripts/cron/send-weekly-executive-report.php` | `scripts/cron/` não existe e nenhum workflow tem `schedule:`. O envio é um evento do WP-Cron (`57`). Em produção, desde 2026-10-01, o próprio WP-Cron roda pelo `crontab` do sistema (#348, `docs/ambientes.md`), sem script dedicado ao relatório |
| `page=uonix-insights`, submenu `> Central de Inteligência` | Slug é `uonix-analytics`. A Central de Inteligência é uma **aba** da página, não submenu. Desde o PR #310 o Insights é submenu de `ksio.dev` para o `ksiodev`, e item próprio para os demais quando liberado |
| `_uonix_utm_*` no Fluent Forms | Lá é **uma linha JSON** com `meta_key='uonix_attribution'`, só para os formulários de captura, contato e newsletter, e **nunca lida** |

## Arquitetura

### Arquivos e carregamento

Módulos novos seguem `NN-slug.php` dentro de `mu-plugins/uonix-<domínio>/`, incluídos em **ordem explícita** pelo array do `module.php` do diretório. O número **não** governa a ordem de carga — o array governa. Precedente: o `53` (dados) já carrega antes do `52` (render).

| Arquivo | Papel |
|---|---|
| `mu-plugins/uonix-admin/50-admin-intelligence-license.php` | Licença: combina as constantes do `wp-config.php` com a licença do painel e decide se o envio automático sai. Registra só dois hooks: o handler que grava a licença do painel e a trava de gravação da opção |
| `mu-plugins/uonix-admin/55-admin-intelligence-metrics.php` | Camada de dados: regras de detecção, normalização e procedência |
| `mu-plugins/uonix-admin/56-admin-intelligence-dashboard.php` | Render: abas `intelligence`, `anomalies` e `settings` |
| `mu-plugins/uonix-admin/57-admin-intelligence-report.php` | Relatório executivo semanal por e-mail e seu agendamento |
| `mu-plugins/uonix-admin/58-admin-intelligence-anomalies.php` | Módulo 5: detecção de anomalias, estado, alerta e verificação diária |
| `mu-plugins/uonix-admin/59-admin-intelligence-executive.php` | Módulo 4: scorecard, destaques e páginas mais encontradas do e-mail semanal |
| `mu-plugins/uonix-admin/63-admin-intelligence-content-radar.php` | Módulo 8: Radar de Pautas — cron diário, candidatas, pauta por IA, descartes e o que vai ao e-mail |

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

Abas em uso: `metrics`, `destinations`, `intelligence` (Oportunidades SEO), `anomalies` (Módulo 5) e `settings`. **Acrescentar uma aba exige as duas pontas** — a allowlist do `53` e o link do `52`. Só o link faz o `tab` cair de volta em `metrics` e o painel nunca aparecer; só a allowlist deixa o painel inalcançável. `scripts/tests/test-admin-intelligence-anomalies-panel.php` guarda as duas pontas para a aba de anomalias.

Todo valor vindo do banco sai por `esc_html`/`esc_attr`/`esc_url`, e isso é **asserido**, não convenção: os painéis são renderizados em toda carga da tela e apenas escondidos, então um escape removido vazaria em qualquer aba.

### Destinatários do relatório

Lista em `wp_options`. E-mail de destinatário não é segredo; token de API é — e por isso os dois vivem em lugares diferentes.

- **Escrita: só o dono do ksio.dev** (#318). A regra é `uonix_ksio_can_configure_insights()`, em `49`: o login do dono **e** `manage_options`.
  - Vale para os três handlers da aba Configurações: salvar destinatários (`55`), **Enviar Teste Agora** (`57`) e a licença (`50`).
  - A checagem vem antes do `check_admin_referer`, e sem o 49 os três recusam com 403.
  - Esconder o formulário não bastaria: um POST direto chegaria ao handler.
  - **Trava da opção**, igual à da licença: o filtro `pre_update_option_uonix_executive_report_recipients`, na prioridade `PHP_INT_MAX`, devolve o valor antigo para quem não é o dono, e o WordPress desiste da gravação. Cobre qualquer `update_option()`, inclusive `/wp-admin/options.php`; o WP-CLI passa. O handler acima é o único gravador legítimo.
- **Leitura e visualização**: `edit_posts`, como o resto do Insights. Os demais usuários, administradores ou editores, veem a aba Configurações só para leitura: a lista de destinatários, o agendamento e o aviso de licença, sem formulário nem botão de teste.
- **Anti-padrão a não replicar**: `mu-plugins/uonix-admin/40-admin-dados-globais-rfq.php:39-43` grava sem nonce e sem re-checagem de capability, e — o defeito mais grave — **o nome da option vem de input do usuário** (`update_option( 'uox_' . $chave, … )`) sem allowlist de chave. Os valores passam por `sanitize_text_field()`, então o problema **não** é falta de sanitização: é ausência de verificação de intenção e de allowlist. Rastreado na issue #249.

### Agendamento

WP-Cron `weekly`. O painel exibe o **próximo disparo real** lido do agendador (`wp_next_scheduled`) e o horário do último envio. O texto ao usuário declara apenas a **frequência** — "semanal" — sem horário e **sem período do dia**.

**Com o envio pausado pela licença, a linha do próximo disparo diz isso** (#316): "(envio pausado pela licença)" ao lado da data. O evento continua agendado, e só o envio devolve `license_inactive`; sem a frase, o operador leria uma data que o envio não vai cumprir. Sem o módulo da licença (50) carregado, o envio também está pausado, e a frase aparece. Com o envio ativo, a linha não muda.

É proibido prometer horário ou período do dia na interface e no e-mail, incluindo formulações brandas como "às segundas pela manhã". A única afirmação que o sistema consegue provar é a frequência somada ao próximo disparo agendado.

- **Em QA e no local,** o WP-Cron dispara por tráfego, não por relógio: a deriva pode atravessar o dia inteiro, o que torna "pela manhã" tão insustentável quanto "08:00".
- **Em produção, desde 2026-10-01 (#348),** o WP-Cron roda pelo `crontab` do sistema a cada 5 minutos, com `DISABLE_WP_CRON` (`docs/ambientes.md`). O PHP não enxerga o `crontab`: se a linha sumir, nada roda, e o código não tem como saber.
- **Os textos seguem o que o código consegue ver (#351).** `uonix_intelligence_cron_by_visit()` (`55`) usa o mesmo critério do WordPress. Com o WP-Cron por visita, o painel e o e-mail dizem que o envio depende de tráfego. Sem ele, o e-mail diz só que o agendamento não depende de visitas, e o painel, que ele depende do agendador do servidor. Nenhum dos dois afirma que esse agendador está rodando.

#### Quem cria o evento: o invariante dos destinatários

**Este invariante vale para o RELATÓRIO EXECUTIVO (`57`), e deliberadamente não vale para a verificação de anomalias (`58`).** A distinção está registrada em "Verificação de anomalias" abaixo, e o código de `uonix_intelligence_anomaly_maybe_schedule()` traz a mesma nota — quem auditar o agendamento encontra a explicação nos dois lugares, em vez de achar que um deles está errado.

**Existe evento agendado se, e somente se, existe destinatário.** Um callback de `init` mantém esse invariante em toda requisição: agenda `weekly` quando há destinatário e nenhum evento, e remove o evento quando o último destinatário sai.

A lista de destinatários é, portanto, a **chave de ativação** — não um campo a mais. Isso já estava implícito em `uonix_intelligence_send_report()`, que recusa lista vazia com o motivo `no_recipients`; o agendamento passou a respeitar a mesma regra.

O que essa amarração compra, e que um `wp_schedule_event` manual por WP-CLI não dá:

- **Reprodutibilidade.** Agendamento feito à mão vive só na opção `cron`. Uma restauração de banco anterior ao agendamento, ou uma limpeza de cron, o perde em silêncio e ninguém lembra de refazer. Com o invariante, ele se restabelece na requisição seguinte, porque a lista de destinatários sobrevive.
- **Painel honesto.** Sem destinatário, o evento sai e o painel exibe "não agendado", em vez de prometer um envio que não aconteceria.

**A contenção por ambiente NÃO é automática, e depende do clone.** Não vale dizer que "a opção vive no banco de cada ambiente, então o ambiente clonado não agenda": `scripts/clone-environment.sh` copia `wp_options` da origem. Ele preserva `cron` no destino — o evento não viaja —, mas os destinatários viajariam, e o callback de `init` recriaria o evento no destino na requisição seguinte. O ambiente clonado passaria a exibir um "próximo disparo" concreto que ninguém configurou ali.

Por isso `uonix_executive_report_recipients` está em `protected_options_where()`, junto de `cron`, SMTP, Turnstile e captcha — todas configurações que ativam comportamento e não devem atravessar ambientes. `scripts/tests/test-clone-activation-options.sh` reprova o build se qualquer uma das duas sair da lista, ou se o invariante deixar de existir no módulo.

### Verificação de anomalias

O Módulo 5 (`58`) vigia dois gatilhos: silêncio de orçamentos, a partir de `wp_fluentform_submissions`, e queda de tráfego orgânico semana-a-semana, a partir da Search Console. Aba `anomalies`, badge no **rótulo** da aba, e e-mail uma vez por episódio para a lista do relatório executivo.

#### Por que o agendamento aqui NÃO segue o invariante dos destinatários

A verificação é agendada **sempre**, independentemente de existir destinatário. A razão é que ela alimenta o badge do painel, que é útil sem e-mail nenhum: quem abre a tela quer saber se há anomalia, tenha ou não cadastrado endereço. Só o **envio** depende de destinatário, e essa condição vive em `uonix_intelligence_anomaly_send_alert()`.

No relatório executivo a situação é outra: sem destinatário ele não tem o que fazer, e um agendamento ativo faria o painel prometer um envio que não aconteceria.

#### O que a API não distingue, e o que fazer a respeito

A Search Console **omite a linha** de um dia tanto quando ele ainda não foi publicado quanto quando ele teve zero impressão. Os dois casos são o mesmo byte na resposta, e essa ambiguidade é a fonte dos defeitos mais sérios deste módulo — dois deles chegaram a ser mergeados como desenho antes de a revisão do PR #293 medi-los.

Três consequências que qualquer alteração aqui precisa respeitar:

- **O que separa "não publicado" de "zero" é o NÚMERO DE DIAS PRESENTES na janela, não a última data com dado.** Ancorar as janelas na última data devolvida parece resolver a defasagem e na verdade esconde o colapso: num site desindexado a ancoragem recua para o último dia saudável, as duas janelas caem em período bom, e o módulo afirma `0,0%` e "dentro do normal" durante um apagão total de tráfego.
- **Dia ausente é resolvido por IMPUTAÇÃO OTIMISTA, não por recusa.** Tratá-lo como zero produz queda artificial — medido em −42,9% com o tráfego real do site. Mas recusar silencia colapso severo: a mesma queda de ~94% alertava com 1 impressão/dia e ficava calada com zero absoluto, ou seja, **aumentar a severidade desligava o alerta**. Dia ausente é ≥ 0, então imputa-se o valor mais favorável à hipótese "nada aconteceu" — média por dia observado da janela anterior, na janela atual; zero, na anterior — e só se conclui se a conclusão sobrevive. Isso é um limite, não um palpite.
- **A precedência do bloco de colapso é carga estrutural.** Semana atual **vazia** contra semana anterior com volume observado é colapso, e tem de ser avaliada **antes** de qualquer portão de completude da base. Com ela depois, um apagão total era detectável em **um único dia de 26** — a interseção "atual exatamente vazia E anterior exatamente completa" —, e um dia de deriva do WP-Cron perdia o episódio para sempre. O piso de ruído continua vindo antes de tudo: sem volume de referência, nem o colapso tem significado.

#### Limiares medidos, e por que o painel os audita

`organic_settle_lag_days` sai de medição (3 dias observados em 2026-09-23, mais um de margem). Fixá-lo só é seguro porque a completude é conferida à parte: se o Google atrasar mais, a janela fica incompleta e o gatilho se declara indisponível em vez de comparar janela furada.

`lead_silence_days` é o limiar que a especificação da issue #193 propunha derivar de "3-5 leads/dia", número nunca medido. O painel exibe a medição ao lado do limiar em uso.

**A medição em produção reprovou o primeiro valor na primeira execução**, que era exatamente para isso que ela existe. Medido em 2026-09-23, logo após o deploy: **14 orçamentos em 90 dias, 0,16 por dia**, e um silêncio encerrado de **10 dias já observado em operação normal**. O valor inicial era 10 — errado por descrever o funcionamento habitual do site. A suposição da issue estava fora por um fator de ~25, o mesmo tipo de erro do `min_impressions = 100`.

O valor passou a **21**, e o que o sustenta é, **nesta ordem**:

1. **A medição direta.** Um silêncio de 10 dias já aconteceu sem nada de errado, então qualquer limiar até 10 descreve a normalidade deste site.
2. **A assimetria de custo.** Alerta atrasado custa menos que alerta ignorado.
3. **21 é três semanas** — unidade humana, não um ponto que um modelo escolheu.

Há uma estimativa de falso alarme por trás (~12/ano em 10 dias, ~2,2 em 21, ~0,5 em 30, com λ = 14/90), mas ela é o **elo mais fraco** do argumento e não carrega a decisão. A revisão do PR #298 mostrou duas razões:

- **O modelo não passa no teste de aderência que o próprio dado permite.** Sob λ = 0,1556 com 13 intervalos fechados, `E[máximo] = 20,4 dias`, e o observado foi 10: `P(máx ≤ 10) = 0,046`. Rejeitado a ~5%, na direção de cauda mais **leve** — as chegadas são mais regulares que Poisson. A estimativa é conservadora, e 14 a 16 seriam defensáveis com o mesmo dado.
- **A incerteza é larga.** Erro padrão relativo de λ com n=14 é 26,7%, o que dá **1,15–3,80 falsos/ano a ±1σ e 0,58–5,78 a ±2σ** em 21 dias. Dizer "~2 por ano" subdeclara.

A faixa validada é **21 a 30**, e `test-admin-intelligence-anomalies.php` reprova fora dela. A latitude dentro da faixa é intencional: 21 detecta em três semanas, 30 em um mês, e a escolha entre os dois é julgamento que um teste não deve decidir.

#### Por que o limiar NÃO é adaptativo

O site é novo e ainda não foi divulgado, então 0,16/dia não é o estado estacionário: o volume vai crescer. Derivar o limiar da taxa medida **sem guarda** tem modo de falha pior que o problema — se os orçamentos caírem devagar, o intervalo médio cresce, o limiar cresce atrás dele, e o alerta se dissolve exatamente quando o negócio está morrendo. É a armadilha clássica do baseline adaptativo.

**Esse argumento não vale para a variante COM guarda**, e a revisão do PR #298 mostrou isso: um `clamp( k × gap, piso, teto )` com catraca só para baixo não tem caminho para cima, então não pode se dissolver. Ela é estritamente melhor no crescimento esperado e empata na queda. Não está implementada por escopo, e é o desenho a avaliar na issue de acompanhamento.

Com valor fixo o erro é sempre na direção segura: quando o volume subir, 21 fica conservador — mais lento que o ideal, nunca falso.

#### Não existe aviso de "limiar conservador demais"

A ausência é deliberada, e a tentativa de criá-lo foi retirada na revisão do PR #298 com **três defeitos independentes**: ela lia `longest_gap` como valor exato quando ele é **piso**, disparava já no estado medido em produção contradizendo a justificativa do próprio limiar, e aparecia durante colapso em curso afirmando um "patamar" que não existe no 42º dia de seca.

A causa comum é que aconselhar uma **redução** de limiar exige um modelo de falso alarme, e o que este dado sustenta é fraco demais para justificar uma ação. Enquanto isso valer, a tela informa a folga e não opina.

A medição considera apenas intervalos **encerrados**. Dois recortes, e o segundo é condicional:

- **Terminar no último orçamento** exclui o silêncio em curso. Incluí-lo tornava o aviso matematicamente garantido sempre que o gatilho acertava: `longest_gap >= silêncio_em_curso >= limiar` implica `limiar > longest_gap` falso, e o painel exibia "1 anomalia crítica detectada" com a instrução de desconsiderá-la logo abaixo.
- **Onde COMEÇAR depende de existir orçamento antes da janela**, e uma consulta resolve. O vazio inicial tem dois significados que a contagem dentro da janela não distingue: pode ser a janela de observação sendo maior que a história (site novo), ou um intervalo real cujo orçamento anterior ficou de fora. Tratá-los como um só produzia verdictos opostos para o mesmo site — medido: leads até 20/06, silêncio de três meses, retomada em 19/09 dava `longest_gap = 0` e "seguro" numa janela de 90 dias, e 90 com "inseguro" numa de 120.

Havendo orçamento antes da janela, a medição começa na **borda** e o valor é um **piso** do real, porque a consulta não enxerga além dela — o painel declara isso com "dias ou MAIS". Não havendo, começa no primeiro orçamento. Portanto `longest_gap` significa "o maior intervalo encerrado observável nesta janela", e **não** o maior intervalo absoluto entre dois orçamentos consecutivos.

### Anomalia não reverificada tem prazo

Quando a fonte falha, uma anomalia já observada continua na tela — apagá-la esconderia um incidente ativo. O que a sustenta é o `observed`, **não** o sinalizador de "já avisei": a contabilidade de aviso não pode governar o que a tela afirma, e conflacionar os dois deixava a proteção inerte e permanentemente na configuração sem destinatário, que é a que esta seção declara suportada.

São **três** fatos distintos, e cada conflação entre eles produziu um defeito: `observed` (o último achado disponível foi anômalo), `triggers` (já avisei sobre este episódio) e `meta.attempts` (já tentei, quantas vezes).

E há prazo: passados `stale_anomaly_max_days` sem reverificação, o badge cai para "indisponível" e a tela nomeia a última observação. Sem prazo, uma credencial revogada mantinha o badge crítico indefinidamente — medido em 400 dias afirmando "ainda não resolvida". Alarme permanente treina a pessoa a ignorar a tela tão bem quanto alarme semanal.

#### "Já avisei" e "já tentei" são estados diferentes

`wp_mail()` devolver `false` **não** prova que nada foi entregue: o PHPMailer transmite o `DATA` quando há ao menos um destinatário aceito e só depois lança `recipients_failed`. Um endereço com typo na lista faz os bons receberem e a função devolver falso.

Por isso a retentativa de aviso tem **teto** (`alert_max_attempts`). Esgotado, o episódio é marcado `undelivered` e o painel diz "anomalia detectada, aviso não entregue" — em vez de silenciar o episódio ou repetir o e-mail todo dia. Lista vazia é o único caso em que a retentativa é provadamente inofensiva, e ali não conta tentativa.

#### Estado por ambiente

`uonix_intelligence_anomaly_state` guarda o resultado da última verificação e o sinalizador de "já avisei" por gatilho, e está em `protected_options_where()`. É categoria distinta das opções de ativação: ela não liga automação no destino, faz a **tela** do destino afirmar um estado que é da origem. Sem a proteção, um clone `prod → qa` faz o painel do QA exibir o incidente da produção. `scripts/tests/test-clone-activation-options.sh` reprova se ela sair da lista.

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

A consulta por `query` do Search Console precisa sair do limite de 10 linhas usado hoje para a ordem de mil, para que a mineração tenha universo. A sanitização de query existente em `53` (`uonix_analytics_metrics_sanitize_query()`) — que rejeita padrão de e-mail, telefone e URL — continua valendo integralmente: ampliar o volume amplia a superfície de PII na mesma proporção. **Desde a #382 ela também recusa HTML que só aparece depois de decodificar** (ver "Saneamento da consulta", abaixo).

A sugestão de Title/Description é feita por IA (Gemini) desde 2026-09-30: ver "Sugestão de Title/Description por IA (Gemini)", abaixo. A regra determinística anterior saiu, junto com o defeito da #309.

### Minimização de PII no universo de mineração

Postura decidida na issue #253, a partir de medição em produção em 2026-09-22.

**O que a medição mostrou.** O universo real é de **111 consultas em 30 dias**, e o teto de `uonix_analytics_metrics_extended_query_limit()` (1000) **não trunca nada**. A mudança do #243 não foi "de 10 para 1000": foi de **amostra do topo para censo completo**. Sob o corte antigo de 10 linhas, com o topo do site em 106 impressões, uma consulta de 1 a 3 impressões praticamente não entrava — e é nessa cauda que a consulta identificável mora. Aquela proteção nunca foi projetada como filtro; era efeito colateral do truncamento, e removê-lo a removeu.

O filtro de `uonix_analytics_metrics_sanitize_query()` continua valendo e continua **insuficiente por construção**: das 18 classes de PII testadas na análise da issue, 14 escapam — nome próprio completo, razão social, CPF com barra, CEP curto, placa, e-mail escrito com "arroba", telefone com separador fora da classe permitida, entre outras. Ampliar o filtro com heurística de nome foi **rejeitado**: ele já produz falso positivo verificável no corpus técnico deste site (`nbr 16325 2014` e `nbr 5410 2004` são descartados hoje pela regra de 8 dígitos), e num universo de 111 consultas que devolve 5 oportunidades não há folga estatística — um descarte indevido é 20% do resultado.

**Saneamento da consulta (#382).** `uonix_analytics_metrics_sanitize_query()` decodifica **antes** de procurar marcação. Até a #382 ela tirava as tags (`wp_strip_all_tags`) e só depois decodificava `%XX` e entidades, então o HTML **codificado** voltava: medido em 2026-11-17 com o `wp_strip_all_tags` real do WordPress 7.1.4, 7 codificações devolviam a tag (`%3Cb%3E`, `&lt;b&gt;`, `&amp;lt;b&amp;gt;`, `&#60;b&#62;`, `&#x3C;b&#x3E;`, `%26lt;b%26gt;`, `%253Cb%253E`) e a tripla devolvia a entidade (`&lt;b&gt;`). Esse texto ia para o `wp_options` (snapshot), para o painel e o e-mail do Módulo 3 e para o pedido ao Gemini (54). O painel e o e-mail escapam a saída, então **não havia XSS**: o efeito era texto com HTML gravado e enviado ao Gemini. A regra agora:

1. a marcação **literal** na entrada crua continua sendo tirada, como sempre foi (`<b>x</b> teste` vira `x teste`);
2. a decodificação (`rawurldecode` e `html_entity_decode`) roda **até estabilizar, com teto de 8 voltas**; a última volta só confirma que nada mudou, então até 7 níveis de codificação passam. Um número fixo de voltas era uma aposta na profundidade do atacante: com 3, o percent quádruplo (`%2525253Cb%2525253Ex`) saía como `%3Cb%3Ex`, que ainda decodifica para `<b>`. Quem não estabiliza em 8 voltas é **recusado**;
3. depois de estabilizar, a consulta que **ainda** tem marcação (`<` seguido de letra, `/`, `!` ou `?`) ou entidade (`&nome;`, `&#n;`, `&#xh;`) é **recusada inteira** (devolve `''`), e não limpa de novo: o que sobrou foi escrito para virar tag, e limpá-lo deixaria um texto que ninguém buscou. **O Radar (63) tem uma checagem própria, mais estrita, e ela não é redundante:** recusa qualquer `<` ou `>` solto e só vê entidade nomeada sem dígito, então `carga > 12 kn` passa no 53 e o Radar a recusa;
4. o teto de 120 vale sobre o texto **decodificado**, e é em **bytes** (`strlen`): 61 `é` (122 bytes) são recusados.

**O que passa, medido:** `a < 5 kn`, `carga > 12 kn`, `viga a < b e c > d`, `ancoragem &amp; linha` (decodificada para `&`), `R&D`, `50%`. Num corpus de 48 consultas legítimas plausíveis de um site de ancoragem (com acento, números, símbolos, `%20` e `+`), **47 saem idênticas e 1 muda**: `at&t;`, que tem a forma `&nome;` sem ser uma entidade conhecida, passa a ser recusada. **O custo aceito**, dito em vez de escondido: uma consulta que tenha `&nome;` literal some das listas do painel (a linha inteira, com as métricas); com 111 consultas em 30 dias (medido em 2026-09-22), é raríssimo. **O que a #382 NÃO mudou, e que era assim antes:** o `<` colado numa letra (`a<b`, `a<3`, `a <= b`, `x <- y`, `eu <3 ancoragem`) é lido como tag aberta pelo `wp_strip_all_tags`, que corta dali até o próximo `>` (ou até o fim, se não houver): a consulta vira `a`, `x`, `eu`, e `a <b c> d` vira `a  d` (medido, idêntico antes e depois). Isso é o comportamento do WordPress sobre a entrada crua, e a #382 não o toca. `ancoragem &amp;amp;amp;amp; linha`, com 4 níveis de entidade, estabiliza e vira `ancoragem & linha` (no 53 do `master` já saía `ancoragem &amp;amp; linha`, e quem a recusava era o Radar).

**O que a #382 NÃO fecha (medido em 2026-11-17).** (1) Um caractere de controle entre o `<` e a letra (`%3C%00b%3E`, `%3C%01b%3E`) sai como `<`, o controle e `b>`: o regex não o casa, e o tokenizador HTML5 o lê como texto. Se um consumidor futuro **remover** os controles (como o `wp_kses_no_null` do WordPress faz com 0x00 a 0x08, 0x0B, 0x0C e 0x0E a 0x1F), a tag aparece (116 de 1.024 entradas varridas, contra 339 no `master`). Hoje nenhum consumidor reprocessa a consulta: painel e e-mail usam `esc_html`, e o Gemini recebe `wp_json_encode`. (2) Um `%` seguido de dois hexadecimais que não formam UTF-8 (`100%desconto`, `50%de`, `caf%E9`) vira um byte solto e a saída é **UTF-8 inválido**, igual no `master` e aqui: o `update_option` do WordPress **falha** com esse texto (medido: devolve `false` e a linha não é gravada), então um snapshot com uma consulta assim não seria gravado. Os dois estão em issues próprias.

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

### A janela da Search Console no painel de Métricas (#300)

O painel **Uônix Insights → Métricas** comparava, para a Search Console, `[hoje − N, ontem]` contra os N dias anteriores. A Search Console **não publica os últimos dias**, e a API **omite** a linha do dia não publicado (ela não volta como zero: é o mesmo byte de um dia sem impressões). A janela atual somava de N − 1 a N − 2 dias contra N (a série final chega com 2 a 3 dias de atraso; N − (L − 1), com L a defasagem de publicação, e L = 2 em 2026-11-18), e a variação saía enviesada para baixo, sem mudança real nenhuma.

**Medido em 2026-11-18** (09:13, dado final da Search Console, agregado por data, sem consulta nem página):

| Período | Dias com dado na janela atual (termina ontem) | Variação que o painel mostrava | Variação com a janela assentada (termina hoje − 4) |
|---|---|---|---|
| 7 dias | 6 de 7 | **−7,4%** (1.357 contra 1.465 impressões) | **+23,2%** (1.520 contra 1.234) |
| 30 dias | 29 de 30 | +13,5% | +5,6% |

**As duas últimas colunas são semanas diferentes**: a do meio é a janela `[hoje − 7, ontem]` (11 a 17/11), e a última é a assentada (08 a 14/11). O que o dia não publicado faz **sozinho**, sobre a MESMA janela de 11 a 17/11, medido às 12:30: **−7,4%** com a série final (6 de 7 dias) e **+19,0%** com o dia provisório (`dataState: all`, 7 de 7). A série final terminava 2 dias antes de hoje (2026-11-16), e o dia 17 vem marcado como incompleto (`firstIncompleteDate`): é o dado provisório que a coleta **não** usa, e que cresce ao longo do dia (por isso o número muda com a hora da leitura). Em 30 dias, a mesma janela dá +13,5% (final, 29 de 30) e +21,6% (provisório).

**O que mudou:**
- **Toda a leitura da Search Console do sync usa a janela assentada** (`uonix_analytics_metrics_search_console_periods()`): termina `uonix_analytics_metrics_search_console_settle_lag()` = **4** dias antes de hoje (3 medidos, mais 1 de margem; o mesmo valor do Módulo 5, e `test-admin-intelligence-anomalies.php` prende a igualdade). O **GA4 não muda**: a defasagem dele é de horas, e `uonix_analytics_metrics_periods()` segue como estava, porque também alimenta o snapshot (`periods`) e o rótulo do relatório.
- **Uma chamada nova por sincronização**, a série **por data** das duas janelas (`rowLimit` 25.000, e 2 × 365 dias são 730 linhas). Com ela o snapshot declara, em `search_console.windows`, quantos dias de cada janela **têm dado** (`days_present` de `days`). A contagem de dias presentes **não distingue** um dia não publicado de um dia que de fato teve zero impressão (a API omite os dois pelo mesmo byte): os dois fazem a janela sair `incomplete`. É a escolha de falhar fechado, ao custo de recusar uma janela cuja soma seria exata. O que ela faz melhor que "a última data com dado" é não esconder o colapso a zero (a lição do Módulo 5). Na série real de 96 dias não há dia sem linha (o mínimo é 48 impressões por dia), então hoje isso não dispara.
- **Janela com dia faltando recusa a comparação.** Se uma das duas janelas não tem todos os dias, as quatro métricas da Search Console saem com o estado `incomplete`, sem diferença nem percentual; os valores seguem mostrados (a soma dos dias que têm dado). O painel diz quantos dias faltam em cada janela, em vez de exibir um número sabidamente errado, mesmo com aviso.
- **O fetch falha fechado** sem as janelas assentadas, **antes** de qualquer chamada de rede, e **a série por data que falhar derruba a sincronização** (snapshot `stale`), porque sem a contagem não há como saber se a comparação é honesta.
- **Snapshot gerado antes da correção** não tem `windows`: o painel diz que ele compara os dias até ontem e pode estar enviesado, e **não afirma janela assentada**. A próxima sincronização o substitui. O leitor (`uonix_analytics_metrics_snapshot_search_console_window()`) valida o que foi gravado (datas que existem, `days` igual ao tamanho real, `days_present` entre 0 e `days`, janelas do mesmo tamanho e **adjacentes**: a anterior termina no dia anterior ao início da atual) e devolve a forma canônica: valor corrompido não chega ao HTML.

**O que isto NÃO muda, e não é para mudar:**
- `uonix_analytics_metrics_periods()` (GA4, `periods` do snapshot). O selo do cabeçalho do e-mail (`uonix_intelligence_report_period_label()`) e o fallback dele seguem lendo `periods`, porque descrevem o snapshot inteiro, e não só a Search Console.
- **A procedência do bloco de SEO do e-mail, ao contrário, passou a ler a janela assentada** (`uonix_intelligence_report_seo_window_label()`): as consultas dele vêm da Search Console, e rotulá-las com `periods` diria `19/10 a 17/11` para consultas que cobrem `16/10 a 14/11` (medido com a função real). Sem janela válida no snapshot (legado ou corrompido), o rótulo é o de `periods`, como antes.
- O universo de consultas, as páginas e a consulta + página **mudam de janela junto com o resumo** (a mesma janela assentada): se ficassem na janela antiga, o painel somaria o resumo de uma janela e a lista de outra.

**Quando cada período passa a ter comparação completa**, contado a partir do primeiro dia da série final (2026-08-13) e da janela anterior assentada: **7 dias** desde 30/08/2026, **30 dias** desde 15/10/2026, **90 dias** a partir de **12/02/2027** e **365 dias** a partir de **15/08/2028**. Em 2026-11-18, a janela anterior de 90 dias tem **4 de 90** dias e a de 365, **0 de 365**: as duas saem `incomplete`. Antes, a de 90 dias mostrava **+1.540,2%** e a de 365 mostrava o estado `new` ("Novo no período"), porque a base anterior era zero.

**A composição de dias da semana não é a mesma nas janelas de 30, 90 e 365 dias** (só nas de múltiplo de 7). Em 2026-11-18, a janela de 30 dias tem 5 sextas e 5 sábados, e a anterior, 5 quartas e 5 quintas; como sábado e domingo valem cerca de um terço dos outros dias neste site (perfil de 96 dias: 75, 186, 195, 215, 191, 179 e 67 impressões, de domingo a sábado), isso pesa cerca de **−3,3 pontos** nos +5,6% de 30 dias (+9,2% com a mesma composição). É anterior à #300 e **não foi corrigido aqui**: as opções são janelas de 28, 91 e 364 dias, ou uma nota na tela (**#489**).

**O que não foi medido:** a latência real da Search Console em todos os dias e horas (as três leituras de 2026-09-23, 2026-09-28 e 2026-11-18 deram 3, 3 e 2 dias, todas durante o dia, e a janela assentada usa a data de São Paulo, que entre 00:00 e 05:00 está um dia à frente da data do Pacífico em que a Search Console publica); o fuso do WordPress em produção (a janela do painel usa `America/Sao_Paulo` fixo e o Módulo 5 usa `wp_timezone()`, e só são iguais se o site estiver em São Paulo; **#490**); a defasagem do GA4, que o código supõe de horas e eu não medi; e o painel renderizado em produção (os testes renderizam com um snapshot de teste).

### Coleta das gerações mortas de snapshot

Gerações antigas de snapshot nunca foram apagadas. Medido em produção em 2026-09-22, quatro conviviam em `wp_options`: `…_snapshot_v1` (5.121 bytes), `…_v2_7` (7.207), `…_v2_30` (7.820) e `…_v3_30` (22.542). As legadas foram produzidas **antes** da minimização de texto e guardam o universo inteiro com o termo cru de cada linha.

`uonix_analytics_metrics_collect_legacy_snapshots()` apaga o que a cascata de leitura **não alcança mais**. O critério não é padrão de nome: é posição em `uonix_analytics_metrics_snapshot_option_cascade()`, que passou a ser a fonte única consultada tanto pela leitura quanto pela coleta. Apaga-se exatamente o que vem **depois da primeira entrada presente** — e como `uonix_analytics_metrics_get_snapshot()` para na primeira presente, o resto é inalcançável e a coleta é **no-op de leitura por construção**, não por coincidência de estado. O teste enumera as oito combinações de presença das três gerações de 30 dias e afere que a leitura devolve o mesmo antes e depois.

Consequências deliberadas:

- **O fallback em cascata continua sendo caminho válido, e é respeitado.** Se a geração corrente de uma janela ainda não existe, a legada que a substitui é o que o painel mostra hoje, e fica onde está. Em produção é o caso de `…_v2_7`: enquanto ninguém sincronizar a janela de 7 dias, é ele que responde. A coleta remove apenas o que está **estritamente abaixo** da geração de que o fallback depende naquele momento.
- **A coleta roda por janela, no sucesso da sincronização daquela janela** — o evento que torna o legado dela inalcançável. Uma janela que nunca é sincronizada mantém seu legado, o que é a escolha conservadora.
- **Não roda no caminho de falha.** `mark_stale()` promove o payload legado para a chave corrente; apagar dado numa sincronização que falhou trocaria "dado velho com aviso" por "sem dado" na primeira falha de rede.
- **O padrão continua valendo quando a geração corrente virar v4**, sem alteração no coletor: basta a cascata declarar a nova ordem.

### O que NÃO foi feito, e por quê

**A postura está registrada no ROPA, entrada 09** de [`docs/legal/ropa-inventario-dados-uonix.md`](../legal/ropa-inventario-dados-uonix.md), seguindo as nove colunas das outras entradas.

O campo de retenção foi preenchido como **justificativa para guarda**, e não como prazo em dias. Isso não é lacuna: a Fase 2 da matriz RoPA definida para este projeto aceita as duas formas — *"Tempo de Retenção: prazo de descarte **ou** justificativa para guarda"*.

A justificativa é factual e verificável no código: a única cópia é o *snapshot* corrente do período, **sobrescrito** a cada sincronização. Não há acúmulo histórico, e gerações anteriores são eliminadas quando se tornam inalcançáveis. Por isso não existe um prazo a declarar — existe um mecanismo, que é mais forte que um prazo, porque não depende de ninguém lembrar de executá-lo.

**Não há TTL sobre o snapshot corrente, e não deve haver.** `uonix_analytics_metrics_snapshot_is_fresh()` decide **exibição**, não validade: o módulo mostra dado velho com aviso de "desatualizado", e isso é comportamento documentado e desejado (fail-soft, ver a linha *Cache* na tabela de base factual). Um TTL no corrente trocaria "dado velho com aviso" por "indisponível" — regressão, não minimização. O que a coleta remove é geração **inalcançável**, que é outra coisa.

**O snapshot não foi excluído do backup, e a tentativa seria mecanicamente impossível.** `scripts/backup-remote-database.sh` faz `mysqldump` do banco inteiro, e `--ignore-table` exclui **tabela**, não **linha** — o snapshot é uma linha em `wp_options`. Partir o dump para contornar isso mexeria no caminho de backup, que é a última coisa que se quer frágil, por um ganho que a minimização de texto já entrega na mesma proporção.

**Quanto texto sobra, com precisão.** Não é "~5". Esse é o tamanho do conjunto de **oportunidade**, e quem guarda texto é o conjunto de **retenção**, que é estritamente maior — ele não filtra CTR, e a faixa de posição e o piso de impressões são mais folgados. Somado a isso, `search_console.queries` mantém o texto de **10 linhas incondicionalmente**, porque é a lista curta que o painel renderiza no gráfico.

Então o residual é **≥ 10 consultas com texto**, e o número exato depende da distribuição do período. Confundir os dois conjuntos subestima a superfície remanescente, e é justamente ela que o ROPA precisa declarar.

**O filtro de PII não foi ampliado** — ver a rejeição da heurística de nome próprio acima.

### Sugestão de Title/Description por IA (Gemini)

Desde 2026-09-30, cada oportunidade ganha a **página líder** da consulta e uma sugestão de Title e Description **reescritos** pelo Gemini. Nada é aplicado no Rank Math: a sugestão é exibida para revisão humana.

| Arquivo | Papel |
|---|---|
| `53` | A sincronização diária faz uma chamada a mais à Search Console, com `query` + `page`. Ela grava `search_console.query_pages`, que dá, para cada consulta, a página de mais impressões (empate pelo caminho). Só entra consulta cujo texto `queries_extended` já retém (#253). **A chamada pede 25000 linhas, o teto da API** (`uonix_analytics_metrics_query_page_limit()`, #336), e não as 1000 do universo de consultas: a Search Console ordena por cliques, e as oportunidades têm CTR baixo, quase sempre zero clique, então eram as primeiras a ficar de fora do corte se os pares consulta × página passassem de 1000. Sem a linha, a oportunidade ficaria sem página alvo. **O dado é acessório:** se a chamada falhar, o snapshot sai sem a chave, e a sincronização não cai. |
| `55` | Cada oportunidade ganha `target_page`: `null` quando o snapshot não tem `query_pages`, `''` quando a consulta não tem página, ou o caminho. `uonix_intelligence_seo_differentiators()` é a lista dos cinco rótulos que a IA pode afirmar. |
| `54` | O cliente do Gemini e o cron diário `uonix_intelligence_ai_daily`. Ele monta o pedido, valida a resposta e grava o cache `uonix_intelligence_ai_suggestions`, com autoload desligado. **A gravação é depois de cada chamada** (#335), e não só no fim: o pior caso teórico da execução (5 chamadas de até 62 s, mais até 10 HEADs de 8 s, 390 s) passa do `timeout 290` do `crontab`, e um processo cortado no meio perderia o que já gastou cota. A gravação do meio mantém o que ainda não foi reavaliado, e só a do fim tira as consultas que saíram da lista. O leitor `uonix_intelligence_ai_suggestion_for()` é o que o painel e o e-mail usam. |
| `56` | Colunas **Página Alvo**, com link para a página e para editá-la, e **Sugestão (IA)**, com o texto atual ao lado do sugerido e o aviso "revise antes de publicar", ou o texto do estado. |
| `57` e `59` | O e-mail mostra "Página: /caminho" e "Título sugerido (IA): …", e o destaque de SEO cita o título sugerido. Sem sugestão, as linhas são omitidas. |

**Configuração**, no `wp-config.php` de cada ambiente (ver [ambientes.md](ambientes.md)):
- `UONIX_GEMINI_API_KEY`. Sem ela, nada chama o Gemini.
- `UONIX_GEMINI_MODEL`, opcional, com o padrão fixo `gemini-3.8-flash`. Não usar apelido `-latest`.

**O pedido:**
- Uma chamada por oportunidade (até 5 por execução), e só quando a entrada mudou desde a última sugestão aceita.
- **A entrada, para o cache, é:** a consulta, a página, o título e a descrição atuais, a lista de diferenciais e o modelo. As métricas vão no pedido, mas ficam fora do hash: a janela de 30 dias termina ontem e muda a cada sincronização diária. Com elas no hash, cada sincronização invalidaria a sugestão (revisão do PR #329).
- A chave vai no cabeçalho `x-goog-api-key`, nunca na URL.
- O corpo é montado chave por chave, com estes campos:
  - a consulta, as impressões, a posição e o CTR;
  - a URL da página, com o título e a descrição atuais;
  - o tipo de página (`tipo_de_pagina`), só quando ela é um termo, por exemplo "categoria de produtos";
  - os diferenciais permitidos.
- Nenhum dado de lead vai ao Gemini.
- O título atual vem de `rank_math_title`, com as variáveis resolvidas pelo Rank Math quando ele expõe o resolvedor. Se sobrar variável sem resolver, vale o título do post. Variável é `%nome%` ou `%nome(argumento)%` (`%customfield(x)%`, `%date(Y)%`, #332); um `%` solto ("100%") não é variável, e o nome é só letra e `_`.
- A página líder precisa ter post **publicado** ou ser o arquivo de um termo de taxonomia pública. A home vai para `page_on_front`, e endereço que dá 404 vira `no_page`. A exceção é a URL antiga do próprio termo, como `/product-category/olhal-de-ancoragem/`: o WordPress a canonicaliza por 301, e ela resolve para o termo de destino. Feed e embed do termo não valem como página.
- **Endereço antigo que redireciona (#343).** No cron, e só nele, `uonix_intelligence_ai_follow_redirect()` segue a cadeia por HEAD (`uonix_intelligence_executive_page_status()`, do `59`).
  - **Quando o destino vale:** os saltos são do próprio domínio, no máximo `max_hops` (3) e sem ciclo, e o destino responde 2xx e é post publicado ou termo.
  - **O que a entrada descreve:** o destino, que é a página que se edita. O cache guarda `redirected_to`, e o leitor do painel e do e-mail o usa **sem HTTP**.
  - **O destino entra no hash pela URL e pelo título.** Se o redirecionamento mudar, a sugestão é refeita.
  - **O painel e o e-mail mostram o destino** ao lado do endereço antigo: "/teste-de-arrancamento → /servico/ensaios-de-arrancamento/".
  - **Sem resposta não apaga:** HEAD sem resposta, ou o orçamento de `head_max` (10) HEADs por execução esgotado, é "não sei". O cron mantém a entrada anterior, e o leitor revalida o destino a cada leitura.
  - **301 para a raiz não vale:** é o padrão de página removida mandada para a home, e a sugestão iria para a home.
  - **Limite:** o normalizador do `53` só aceita `uonix.com.br` num `Location` absoluto. No QA, o `Location` absoluto de `uonix.ksio.dev` não é seguido, como já acontece com a conferência de status do `59`. Um `Location` relativo, sem host, é seguido em qualquer ambiente.
- **Categoria e tag (#344).** Sem post, `uonix_intelligence_resolve_term_path()` (`55`) percorre as regras de reescrita como o `url_to_postid()` do core e devolve o termo que a URL abre.
  - **A regra decide, e não o slug.** O slug `olhal-de-ancoragem` existe em três taxonomias (`product_cat` 34, `post_tag` 510 e `product_tag` 465, medido no local em 2026-10-02), e só a regra diz que `/olhal-de-ancoragem/` é a categoria de produto.
  - **Título e descrição:** os de `rank_math_title` e `rank_math_description` do termo. Sem meta, o nome e a descrição do termo.
  - **O tipo de página vai ao Gemini e entra no hash** só para termos. O hash dos posts é o mesmo de antes.
  - **No painel,** o link leva ao editor do termo: "Editar categoria de produtos".
  - **O rótulo do Módulo 4 usa o mesmo resolvedor (#307):** `/olhal-de-ancoragem/` sai como "Olhal de Ancoragem", e não pelo caminho.

**Texto da consulta ao Gemini.** Decisão do Cassio em 2026-09-30: o texto vai, **mesmo no plano gratuito** da API, o que aceita que o Google use o conteúdo para melhorar os produtos dele.
- O que pesou: a consulta já vem do Google, pela Search Console, e já aparece no painel e no e-mail.
- O filtro de PII continua **insuficiente por construção**, como está registrado acima.
- Ver a entrada 09 do [ROPA](legal/ropa-inventario-dados-uonix.md).

**Validação.** A sugestão só é aceita se tudo abaixo valer. Se algo falhar, o estado é `rejected`, e nada do texto recusado é exibido.
1. A resposta é JSON com exatamente `title`, `description` e `differentiators_used`, e `differentiators_used` é uma **lista** (`array_is_list`): um objeto JSON com chaves que não sejam `0..n-1` em ordem recusa, mesmo com valores permitidos (#334). Um objeto com chaves `0..n-1` em ordem é indistinguível de lista depois do `json_decode( …, true )`, e passa: os valores passam pelas mesmas checagens, e o gravado é uma lista.
2. O título tem de 1 a 60 caracteres, e a descrição de 1 a 155.
3. Não há HTML.
4. Todo diferencial declarado está na lista.
5. Declarado é igual a presente no texto, **pelo rótulo literal**, comparado sem acento e sem caixa: todo rótulo literal declarado tem de estar no texto, e todo rótulo literal presente no texto tem de estar declarado.

**O que a regra 5 não detecta (#333).** Paráfrase: "inox 304 e 316", "NBR 16325" sem o "Conforme", "laudo e ART". Ela é aceita com `differentiators_used = []`, e a afirmação segue verdadeira, porque é um diferencial aprovado. **E o que ela recusa por grafia:** "Pronta-entrega" com hífen, ou o rótulo com espaço inseparável, caem em `rejected`. Nada errado é exibido; o custo é a taxa de recusa, que não se mediu: a leitura de produção de 2026-11-17 (04:45 UTC) mostrou **0 `rejected` em 3 respostas validadas** (as outras 2 entradas falharam antes da validação, por timeout e por 503). Amostra pequena: ela não prova que a taxa é baixa, só que não é alta. Normalizar hífen e espaço antes de comparar só vale se essa taxa subir.

**Limite declarado:** uma afirmação inventada fora da lista não é detectável pela validação. Na primeira chamada real, o modelo escreveu "máxima resistência e segurança". A proteção é a instrução do pedido e a revisão humana.

**Cache e estados:**
- Cada entrada do cache é chaveada por `sha256` de consulta + caminho, e a consulta não fica em texto puro.
- Entrada nova faz uma chamada, e o resultado substitui o anterior, inclusive quando falha. Assim, a sugestão de um título que já não existe nunca sobrevive.
- Cada chamada grava o código HTTP da última resposta (`http`, 0 em erro de transporte) e os pedidos feitos (`attempts`).
- **Causa do erro de transporte (#404):** com `http` 0, a entrada grava também `error`, o código e a mensagem do `WP_Error` da última tentativa (por exemplo, `http_request_failed: cURL error 28: Operation timed out…`). O texto sai limpo: uma linha, cortada em 2 KB antes de qualquer regex e em 160 caracteres no fim; sem tag, sem controle ou formatação Unicode; `scheme://…` vira `[url]`; a chave configurada (8 caracteres ou mais) vira `[chave]`, mesmo com até 4 bytes entre os caracteres dela e em qualquer caixa, e o que começa em `AIza` também. **Não é garantia:** a chave só viaja no cabeçalho `x-goog-api-key`, então ela não deveria aparecer numa mensagem de transporte, e a limpeza é uma camada a mais. IP e porta sem `scheme://` ficam. Se o PCRE desistir no meio da limpeza, a entrada grava `[causa omitida]` em vez do texto sem limpar. Resposta HTTP, mesmo de falha, não grava `error`. Serve para decidir o ajuste (tempo limite, nova tentativa) com dado, e não por suposição.
- **Parada (#384):** depois de um `quota_exhausted`, ou de duas falhas seguidas (`unavailable` ou `model_missing`), nenhuma chamada começa na execução. **O timeout de cada pedido ao Gemini é de 30 s** (era 15 s): medido em produção em 2026-11-17, as falhas gravadas eram todas `cURL error 28` a 15 s. Um timeout dá **um** pedido de 30 s (só 429 e 503 ganham nova tentativa), então os 62 s por candidata (2 × 30 s + 2 s de pausa) só valem para um 429 ou 503 que repete e estoura de novo. Com o `ai_budget` de 150 s do Radar, o pior término com 429 ou 503 na última é 214 s (a pausa de 2 s vem depois da conferência do orçamento), com 76 s de folga contra o `timeout 290` do `crontab`; com **todas** em timeout, o Radar termina em **158 s sem HEAD e até 182 s com HEAD** (a última chamada pode começar em 152 s e durar 30). **O Módulo 3 não tem relógio:** o teto dele é `per_run` (5 candidatas). Entre uma candidata e outra não há pausa (os 2 s só separam as duas tentativas de um 429 ou 503). Cinco timeouts somam 150 s de Gemini, mais até 80 s de HEADs (10 × 8 s): 230 s, com 60 s de folga. As entradas que precisariam de uma ficam `deferred`, com o motivo em `reason` (`quota_exhausted` ou `failures`). A sugestão `ok` com a mesma entrada continua, porque não precisava de chamada.

  **O pior caso do Módulo 3 é outro.** Varri as 32.768 sequências de 5 candidatas com **todas** as classes de resultado, pela regra do código: timeout (1 pedido, neutro: não conta e não zera); 503 e depois 503 (2 pedidos, conta); **503 e depois timeout (2 pedidos, neutro)**; 503 e depois ok (2 pedidos, zera); ok; 404; 500; e cota diária (para o cron). O máximo é **310 s de Gemini e 390 s com os 10 HEADs: 100 s acima do `timeout 290`**, e 165 sequências passam de 290 s só de Gemini. O gatilho é **cinco vezes "503 e depois timeout"**, sem ok no meio e sem nenhuma parada, porque o segundo pedido, o timeout, não entra na sequência de falhas (medido com o `ai_run` real: 5 chamadas, 10 pedidos, nenhuma `deferred`). **Quanto isso é provável não foi medido.** Cada peça já apareceu em produção: o Radar de 2026-11-17 (00:10 UTC) gravou uma entrada com `attempts: 2`, `http: 0` e `cURL error 28` (uma resposta 429 ou 503 e depois um timeout), e o Módulo 3 de 17/11 (04:45 UTC) gravou um timeout e dois 503 seguidos. Cinco seguidas "503 e depois timeout" **não** foram vistas. Uma varredura que omita as duas classes de dois pedidos que são neutras ou zeram subestima o teto (com só quatro classes, dá 246 s e 326 s). O que limita o dano: o `timeout 290` corta a gravação do **fim**, não a do meio (#335), então o que já foi pedido fica no cache, e o WP-CLI reagenda o evento **antes** de disparar o hook (medido no código do `cron event run`). Perde-se a 5.ª candidata do dia, tentada no seguinte. **A decisão de não dar `ai_budget` ao Módulo 3 se apoia na gravação por chamada (#335) e no reagendamento antes do hook, e não em o excedente ser raro**, e a saída, se isso aparecer em produção, é um orçamento de tempo próprio, como o do Radar. **Sobre o horário:** o Radar roda por volta de 00:08 UTC e o Módulo 3 por volta de 04:45 UTC, **como as leituras de produção mostraram**, e não porque o código os agende assim: o código agenda o Radar em `time() + 1 h` e o Módulo 3 em `time() + 2 h` na primeira ativação, e o WP-Cron só os mantém a 24 h um do outro. Depois de uma pausa do cron (deploy, queda), os dois podem vencer juntos e rodar no mesmo `cron event run --due-now`, e a soma passa de 290 s. A gravação por chamada limita o efeito.
- O leitor recalcula a entrada. Se o título ou a descrição mudaram depois da geração, o estado é `pending`. Mudança só nas métricas não muda nada.
- Oportunidade que saiu da lista sai do cache.
- O cache está em `protected_options_where()` e não atravessa ambientes no clone.

| Estado | Painel |
|---|---|
| `ok` | atual e sugerido, com "Gerada por IA em DD/MM — revise antes de publicar" |
| `not_configured` | IA não configurada: defina UONIX_GEMINI_API_KEY no wp-config.php. |
| `pending` | Aguardando a próxima geração diária. |
| `unavailable` | O Gemini não respondeu. Nova tentativa na próxima geração diária. |
| `rejected` | Sugestão recusada pela validação: tamanho, formato ou diferencial fora da lista. |
| `no_page` | Sem página publicada para esta consulta: removida ou redirecionada. |
| `model_missing` | Modelo indisponível: confira UONIX_GEMINI_MODEL no wp-config.php. |
| `quota_exhausted` | **Com a prova** do `quotaId` diário: "Cota diária do Gemini esgotada. Nova tentativa na próxima geração diária." **Sem a prova** (cota só inferida do `retryDelay`, ou entrada gravada antes da #403): "Cota do Gemini esgotada. Nova tentativa na próxima geração diária." Com o `retryDelay` do Gemini, acrescenta "A cota renova por volta de DD/MM HH:MM." no fuso do site (`wp_date`), e com o rótulo "(UTC)" só se o fuso do site não for conhecido (#386). **A hora só entra enquanto ainda está no futuro** (#403): vencida, ou na hora exata, sai, e o resto do texto fica. |
| `deferred` | Não pedida: a cota do Gemini acabou nesta geração (com "diária" só quando a prova existe, como acima; ou: falhas seguidas do Gemini nesta geração). Nova tentativa na próxima geração diária. A hora da renovação segue a mesma regra de `quota_exhausted`. |

**A prova da cota diária (#403).** `uonix_intelligence_ai_quota_info()` marca como cota que não volta o 429 com `quotaId` `PerDay`, ou o 429 com `retryDelay` acima de 60 s. Só o primeiro **prova** que é diária. O transporte devolve `quota_source` com o que valeu: `quota_id_daily` (o `quotaId` diz PerDay) ou `retry_delay` (só a espera passou de 60 s, que é inferência). O cron grava `quota_source` na entrada da cota, e as adiadas herdam o mesmo valor. O leitor devolve o campo só se for um dos dois valores exatos. O painel diz "diária" só com `quota_id_daily`.

No e-mail, todo estado diferente de `ok` só omite a linha.

**Medido em 2026-09-30:**
- `thinkingBudget: 0` foi aceito pelo `gemini-3.8-flash`: `STOP`, sem token de raciocínio. Com `maxOutputTokens: 20` e o raciocínio ligado, o modelo voltou sem texto (`MAX_TOKENS`), e por isso o limite é 1024.
- 503 ("high demand") em 3 de 4 chamadas seguidas. Por isso 429 e 503 ganham **uma** nova tentativa. Os demais erros esperam o cron do dia seguinte.

**Medido em 2026-10-02 e 2026-10-03 (#384):**
- A chave é do plano gratuito: 429 com `QuotaFailure`, `quotaId` `GenerateRequestsPerDayPerProjectPerModel-FreeTier`, limite 20 por dia, somando o Módulo 3 e o Radar, e `RetryInfo.retryDelay` de horas.
- Às 00:08 UTC, logo depois da renovação, o Radar teve 1 pauta ok e 8 falhas, e às 00:14 a cota do dia já tinha acabado.
- Por isso o 429 de cota que não volta nesta execução **não é repetido** e vira `quota_exhausted`. Ele é reconhecido por um `quotaId` com `PerDay`, ou por um `retryDelay` acima de 60 s, o tamanho de uma janela por minuto. Essa segunda regra é inferência: nenhum 429 por minuto foi medido. O 429 por minuto e o 503 continuam com a nova tentativa.
- `gemini-2.5-flash` deu 404 para chave nova. Por isso existe o estado `model_missing`.

**O que não entrega:**
- destaques por IA no e-mail executivo;
- aplicar a sugestão no Rank Math;
- botão para regenerar;
- detecção automática de afirmação inventada fora da lista.

## Módulo 4 — Relatório Executivo

O e-mail semanal existente (`57`) passa a abrir com um scorecard de quatro caixas e até três destaques, e ganha um bloco de páginas mais encontradas na busca. A camada de dados é o `59`, que não renderiza e não grava nada. Sem ela carregada, o e-mail sai exatamente como antes.

Decisões do Cassio em 2026-09-28: estender o e-mail semanal em vez de criar um segundo; destaques por **regra determinística**, não por LLM; **cortar o Custo por Lead** enquanto não houver fonte de gasto; e comparar orçamentos em **número absoluto** com janela de 4 semanas. O espaço do CPL no scorecard é ocupado por **Visitas**, que é o pilar 1 (volume de demanda).

### Cada caixa tem o próprio relógio, e declara

| Caixa | Fonte | Janela | Comparação |
|---|---|---|---|
| Orçamentos | Fluent Forms (form 3 e 4, sem spam) | 7 dias até ontem | 28 dias contra os 28 anteriores, **só em número absoluto** e pelo teste de diferença |
| Visitas | GA4, série diária | 7 dias até ontem | semana contra semana, em % |
| Impressões na busca | Search Console, via `organic_drop()` do Módulo 5 | semana **assentada**, ~4 dias antes de hoje | semana contra semana, em % |
| Conversão | orçamentos ÷ visitas | 28 dias até ontem | 28 contra 28, pelo teste de diferença com exposição |

As janelas terminam **ontem** porque o relatório sai segunda às 08:00, e "hoje" teria oito horas. Para os orçamentos isso é exato: vêm do banco local.

Para o GA4, o que foi **medido** em 2026-09-28 é que o dado intradiário chega em horas — às 13:00 já havia dado parcial do próprio dia. **Não foi medido que o dia anterior esteja fechado às 08:00**, e a revisão do PR #301 apontou a diferença: o processamento diário pode seguir refinando o domingo depois disso. O tamanho do erro possível foi medido no mesmo dia: o domingo 27/09 teve 2 das 65 visitas da semana (~3%). O viés possível na variação semanal de visitas é dessa ordem, para baixo, e fica declarado.

A Search Console segue com ~3 dias de atraso (medido de novo no mesmo dia), e por isso a caixa de impressões reusa as janelas assentadas do Módulo 5 em vez de somar a série de novo. Reimplementar ali seria reabrir os pontos cegos que duas revisões fecharam.

### O selo e o assunto são a semana do relatório (#308)

- **Selo do cabeçalho e assunto:** "Semana de 24/09 a 30/09/2026", a janela `week` de `uonix_intelligence_executive_windows()`, com os 7 dias até ontem. É a mesma de Orçamentos e Visitas, e vem de `uonix_intelligence_report_badge_label()`.
- **O período do snapshot de SEO,** de 30 dias, vai para a procedência do bloco de oportunidades: "Fonte: Search Console · 01/09 a 30/09 (30 dias) · sincronizado em …". Antes ele estava no selo, e quem lia o topo entendia que o relatório inteiro cobria 30 dias.
- **Sem o `59`,** o selo continua sendo o período do SEO. Aí o e-mail só tem esse bloco, e o selo está certo.

### O e-mail diz quantas oportunidades ficaram no painel (#291)

- **Os limites:** o e-mail lista `email_limit` (3) oportunidades, e o painel `panel_limit` (5). As duas são regras de `uonix_intelligence_seo_rules()`, no `55`. O e-mail é um resumo de propósito.
- **A frase:** abaixo da tabela sai "Mais N oportunidades no painel.", com o link para o painel, e só quando N > 0.
- **O número:** N = min(`matched`, `panel_limit`) − linhas no e-mail. Ele não passa do que o painel mostra a mais.
- **A caixa "Oportunidades encontradas" do painel conta o total (#371):** `matched`, e não as linhas que a tabela mostra. Com 7 oportunidades e `panel_limit` 5, a caixa diz 7, e a legenda acrescenta "· a tabela mostra as 5 de maior volume". Sem corte, a legenda é só "de N consultas analisadas". Antes, a caixa mostrava só as linhas já cortadas (5, com 12 oportunidades e 113 consultas analisadas), e o operador lia o corte como se fosse o total.
- **`matched` não é `universe`:** `matched` é o total de oportunidades antes do corte, e `universe` conta todas as consultas peneiradas, oportunidade ou não. Em 23/09 eram 5 contra 113.

### "Mudança detectável" é um teste, não um limiar

Orçamentos nunca aparecem em porcentagem. Com ~1 por semana, "▲ +100%" é um orçamento a mais. A caixa mostra o número absoluto e o resultado de um **teste binomial exato** de duas contagens de Poisson: condicionado ao total, a primeira contagem segue Binomial(n, p0), com p0 = 0,5 para janelas iguais e p0 proporcional às visitas quando se comparam taxas. O nível é o convencional, α = 0,05 — o único número do módulo que não vem de medição do site.

**Exato, e não a aproximação normal**, porque ela erra justo no volume deste site: 0 contra 5 passa em `|a − b| > 2√(a+b)` e tem valor-p exato de 0,0625.

O bilateral é por **duplicação da cauda menor**. Para p0 ≠ 0,5 existe outra convenção — a do `binom.test` do R, que soma as probabilidades menores ou iguais à observada —, e as duas divergem: para 3 em 3 com p0 = 0,2, esta dá 0,016 e aquela, 0,008. A duplicação é mais conservadora, e a revisão do PR #301 mediu que o tamanho real deste teste nunca passa de 5% na grade n ≤ 80, p0 ∈ [0,02; 0,98]. Perde poder, não inventa sinal.

A alternativa rejeitada era um limiar fixo de diferença. Com este volume, qualquer limiar fixo ou calaria sempre ou dispararia sempre — a lição do limiar de silêncio do Módulo 5, que a própria tela reprovou na primeira execução.

### Três recusas que a medição obrigou

- **Sem histórico do GA4, não há comparação.** O snapshot de 30 dias gravava `sessions.previous = 0, state = new`: o GA4 deste site não tem dado antes de ~29/08. Uma janela anterior que começa antes disso soma menos dias do que tem e acusaria um crescimento que é só a instalação. A cobertura exige dado **estritamente antes** do início da janela; para verificar isso, a série é pedida desde 7 dias antes da janela mais antiga. Em 2026-09-28, a semana anterior tem cobertura e as 4 semanas anteriores não.
- **Lacuna de coleta do GA4 dentro de uma janela também recusa a comparação (#303).** `history_covers()` só olha a **borda** (existe dado antes do início da janela?), e uma lacuna no **miolo** passava: dia ausente numa janela encerrada vale zero visitas, o que é correto com a coleta funcionando (a defasagem do GA4 é de horas) e errado quando a coleta **para** (tag quebrada, container do Tag Manager despublicado, consentimento bloqueando tudo): aí o dia ausente é zero **medição**. Uma lacuna de 16 dias na janela anterior de 4 semanas fez o e-mail imprimir "antes: até 3,3%" quando a taxa real era 1,4%: os orçamentos da janela contam todos, as visitas dos 16 dias somam zero, e a divisão infla.
  - **A regra:** `uonix_intelligence_executive_collection_gap_days()` recusa a comparação (devolve o comprimento da maior sequência, e 0 sem lacuna) se **qualquer** das duas janelas comparadas (semanas, na caixa de visitas; 4 semanas, na de conversão) tiver `ga4_gap_days` (3) ou mais dias **seguidos** sem linha do GA4. A nota da caixa é `ga4_collection_gap`, e o e-mail diz "sem comparação: N dias seguidos sem dado do GA4 no período", com o tamanho da maior sequência e sem afirmar a causa. **A contagem atual segue na tela**; só a comparação some.
  - **Dia sem linha não é dia com zero:** um dia COM linha e zero sessões é medição, e não conta. A sequência é do **comprimento** de dias ausentes, e dois dias ausentes separados por um dia com dado não somam.
  - **Por que 3 (decisão de produto, com uma faixa de risco, e não uma probabilidade medida):** o que se MEDIU em produção, de 2026-09-11 a 2026-11-16, foi **4 dias sem linha em 67** (sáb 10/10, dom 25/10, dom 01/11, sáb 14/11, todos de fim de semana, em média 3,3 sessões contra 11,9 nos dias de semana), nenhum com zero sessões e **nenhum seguido de outro** (maior sequência = 1). A **chance de falso alarme não foi medida**, e o modelo que a estimaria é rejeitado pela própria série:
    - O modelo ingênuo (Poisson por dia, com a média de cada dia da semana) dá **7,6e-08** para 3 dias seguidos numa janela de 28 dias (1 em 13 milhões). Mas no fim de semana a variância é **6,7 vezes a média** (Poisson exige 1), o modelo prevê 0,74 dia sem linha e a série tem **4**, e **38 de 40 janelas** de 28 dias têm algum dia ausente (o modelo previa 26%).
    - Com a taxa **empírica** do fim de semana (20% dos dias sem linha), 3 dias seguidos saem em **2,8e-06** (1 em 360 mil); com o dia útil suavizado em 1/47, em **6,5e-03** (1 em 154). A faixa honesta vai de **1 em ~150 a 1 em 13 milhões**, conforme a dispersão que se admita, e o dado atual não decide.
    - **Feriado prolongado não está coberto pela conta.** Sexta de feriado + sábado + domingo são 3 dias de baixa seguidos: com a taxa de fim de semana (20% por dia) a chance é de **1 em 125 por feriado**, e de 1 em 400 com 2 sessões por dia (o que 12/10 e 02/11 tiveram). Com 3 a 5 desses por ano, de **1% a 4% de chance de uma recusa por ano**, e mais no Carnaval e no recesso de fim de ano. A recusa é do lado seguro (o e-mail deixa de comparar, e o número atual segue na tela), e o texto **não afirma a causa**: diz "N dias seguidos sem dado do GA4", porque coleta que parou e feriado sem visita deixam o mesmo rastro.
    - **Por que 3 mesmo assim:** 2 daria falso alarme em quase toda janela com fim de semana fraco (15% pela taxa empírica), e 4 deixa de ver uma queda de coleta de exatamente 3 dias. Custo de errar para um lado: um e-mail que não compara; para o outro: uma conversão inflada que parece real. Rever com mais histórico, e se o tráfego mudar de ordem de grandeza.
  - **O que isto não pega:** coleta que cai por **1 ou 2 dias**. Um único dia ausente é, neste site, mais provavelmente um fim de semana sem visita (4 de 4 vezes foi). A regra troca esse ponto cego por falso alarme quase nulo.
- **Com dia ausente na semana ATUAL, a porcentagem de impressões é recusada.** A imputação otimista do Módulo 5 produz um **limite** construído para o alerta, não uma medição.
- **Com dia ausente na semana ANTERIOR, também.** Esta faltava na primeira versão, e a revisão do PR #301 mediu o dano. O `imputed_days` do Módulo 5 conta só a semana atual; na anterior, dia ausente vale zero — conservador para detectar **queda**, que é o trabalho do alerta, e errado para exibir variação nos dois sentidos. Com tráfego constante e dois dias ausentes na semana anterior, o e-mail diria "▲ +40%"; com queda real de −35% e três dias ausentes, diria "+13,8%, abaixo do limiar". O primeiro rascunho deste contrato afirmava que a porcentagem era recusada nesse caso. Era falso.

Nos três casos a caixa mostra os números observados e diz o que faltou. O colapso é a exceção deliberada: semana atual sem nenhuma impressão é −100% contra qualquer semana anterior com volume, completa ou não.

### A conversão é um teto

O GA4 roda com Consent Mode v2 e só conta, nos relatórios, as visitas com consentimento de estatística. Os orçamentos contam todos. O denominador fica menor que o real e a divisão fica **maior**, então a caixa mostra "até X%". O sentido do erro é conhecido e vai escrito na própria caixa.

**Limitação que o teste de diferença não resolve:** a exposição do teste também são visitas consentidas. Se a taxa de aceite do banner da AdOpt mudar entre os dois períodos, o denominador muda sem que a conversão real mude, e o teste lê isso como mudança de conversão. Com este dado, as duas coisas são indistinguíveis.

### Destaques

Até três, e nunca enchimento: cada um só existe se o dado que o sustenta está disponível. Descrevem, não explicam — "a visibilidade cresceu mais que a demanda" é uma constatação sobre dois números; causa, nada aqui mede.

O limiar de variação de impressões reusa `organic_drop_percent` do Módulo 5 (35%), para as duas superfícies nunca discordarem sobre o que é relevante.

### Páginas mais encontradas

Ordenadas por **impressões** — é o que a Search Console mede de procura. Páginas, e não consultas, porque o pilar 4 pergunta pelos produtos e serviços mais procurados e página é a unidade de produto e serviço no site; as consultas já aparecem no bloco de oportunidades de SEO. (O primeiro rascunho justificava a escolha com a minimização de texto do #278. Era falso: `search_console.queries` guarda 10 consultas com texto inteiro, e a revisão do PR #301 mediu que 9 das 10 maiores têm texto.)

A primeira versão lia as 10 páginas do snapshot, e a revisão do PR #301 encontrou dois defeitos:

- **Três das cinco linhas não eram páginas do site.** Conferido por HTTP em 2026-09-28: `/olhal-de-ancoragem/` dava **404** com 327 impressões, e `/projeto-de-balancim` e `/projeto-de-ancoragem` eram **301** para `/servico/...`. O bloco os apresentava como páginas, e os 301 dividiam as impressões entre o endereço antigo e o novo.
- **O ranking dependia da ordem errada.** O snapshot guarda as 10 páginas de mais **cliques**, porque a API ordena por cliques. Reordenar essas 10 por impressões perdia justamente as páginas de muita impressão e pouco clique — a quinta posição daquele dia dependia disso por 9 impressões.

Agora o bloco busca até **1000** páginas (`pages_rows`) numa janela **assentada** de 28 dias, terminando no mesmo dia que a semana das impressões. A revisão mediu 78 páginas em 30 dias. O limite é alto porque a API corta por cliques: se o universo passasse dele, o defeito voltaria na cauda. Resposta que chega exatamente no limite de linhas **cruas** é declarada no e-mail como "lista pode estar incompleta". A contagem é crua porque a propriedade é de domínio (`sc-domain:`): ela pode trazer subdomínio, que a normalização descarta, e contar depois esconderia o corte.

O status HTTP das 10 de mais impressão é conferido **sem que o cliente siga o redirecionamento**. Quem segue é o próprio código, salto a salto, até `max_hops` = 3.

- **Redirecionamento é somado ao destino final da cadeia**, que passa a declarar quantos endereços antigos inclui. A procedência do bloco só diz que houve soma quando houve.
- **404 entra marcado em vermelho**: página que o Google mostra e não existe é o achado mais acionável do bloco.
- **Status que não pôde ser conferido fica como "não verificado"**, nunca como página existente. Isso inclui falha de rede, loopback bloqueado, ciclo, redirecionamento para fora do site, 3xx sem `Location`, saltos demais, teto de consultas ou orçamento atingido **e os cinco códigos de indisponibilidade passageira (408, 429, 502, 503 e 504, #304)** — **no endereço de partida ou em qualquer ponto da cadeia**. Nesses casos nada é somado. Um destino que responde outro erro 4xx ou 5xx é tratado como fim conhecido e recebe a soma: o 500, por exemplo, é resposta do endereço, e o visitante chega nele. Os cinco passageiros ficam de fora porque o nó pode redirecionar de novo quando voltar, e tratá-lo como fim partia a cadeia em duas linhas (`/a` → `/b` → `/c`, com `/b` em 503: o e-mail mostrava `/b` com as impressões de `/a`, e `/c` em outra linha). Hoje a produção não tem cadeia de 2 saltos (12 endereços medidos em 2026-09, todos com 0 ou 1).

**A soma é feita em duas fases.** Primeiro cada endereço segue a **própria** cadeia até o fim, contando os **próprios** saltos; só depois as impressões **originais** são somadas no destino final de cada um. A memória de status garante uma requisição por endereço.

- **A segunda revisão do PR #301** mostrou que a primeira versão somava durante o laço. Com `/b` (300) → `/c` e `/a` (100) → `/b`, o e-mail mostrava `/c` com 350 e, embaixo, `/b` com 100: a mesma cadeia partida em duas linhas. O comentário da época afirmava o contrário.
- **A terceira revisão** mostrou que a versão seguinte ainda dependia da ordem. Ela reusava o fim de um trecho já resolvido por outra cadeia, sem contar os saltos daquele trecho, e somava cadeia de cinco saltos numa ordem e não na outra. Em 400 grafos aleatórios, os 6 com cadeia acima de 3 saltos mudavam com a ordem. Sem o reuso, o resultado de cada endereço depende só do grafo a partir dele, e o teste roda o mesmo grafo nas duas ordens.
- **A exceção é o esgotamento do teto ou do orçamento.** Aí **o que** fica sem conferência depende da ordem, que é por impressões. Mas o que fica sem conferência sai "não verificado" e sem soma: a ordem muda a completude, não a verdade.

Quando uma cadeia passa de `max_hops`, o endereço de partida fica "não verificado". Cada intermediário conta os **próprios** saltos: os que cabem no limite são resolvidos e somados, e os que não cabem também ficam "não verificado". Endereço que chega ao topo sem ter sido candidato também é conferido e somado.

**O custo tem teto explícito, não estimativa.** A primeira versão deste contrato dizia "até ~10 requisições", e a revisão mediu que o teto real era 15. Agora:

| Regra | Valor | O que garante |
|---|---|---|
| `head_max` | 20 | Teto **rígido** de requisições HEAD por execução, contando os saltos. |
| `head_timeout` | 8 s | Limite por requisição. Medido em produção: raiz 133 ms, serviço ~80 ms (cache), 301 2,2 s, 404 4,2 s; a revisão mediu um 301 frio em 6,81 s. Nenhum número fixo tem folga contra essa variação. O que torna 8 s defensável é o modo de falha: estourar dá "não verificado", nunca "ok". |
| `head_budget` | 20 s | Orçamento de tempo, conferido **antes** de cada requisição. Por isso o total pode passar dele por até um `head_timeout`: ~28 s no pior caso. O relógio é injetável, e o teste confere o orçamento com um relógio falso, sem dormir. |

O orçamento **não** existe por causa do `max_execution_time`: no Linux ele não conta o tempo gasto em operação de rede (nota de `set_time_limit()` no manual do PHP). O limite de relógio real vem do servidor web e do PHP-FPM, e não foi medido. O orçamento existe para as conferências HEAD não somarem mais que ~28 s a esse limite desconhecido. **Ele não protege a execução inteira:** as três chamadas ao Google (GA4, série e páginas), cada uma com o próprio pedido de token e timeout de 20 s, somam até 120 s no pior caso, fora dele. Então o botão "Enviar Teste Agora" continua dependendo de um limite que ninguém mediu.

**Desde 2026-10-01 (#348), as conferências HEAD não rodam mais no envio.**
- **O que foi medido:** em produção, com as conferências dentro do envio, `uonix_intelligence_executive_collect()` levava 27,8 s de relógio, e o relatório inteiro (`uonix_intelligence_report_context()`) 29,5 s; e um clique em "Enviar Teste Agora" não completou o envio. A causa exata daquele corte não está provada.
- **O cron diário `uonix_intelligence_page_status_daily`** (`uonix_intelligence_executive_refresh_page_status()`) busca as páginas da mesma janela do envio (`uonix_intelligence_executive_pages_window()`). Ele roda o mesmo `top_pages()`, com teto, orçamento e cadeias, e a conferência real fica por trás de um gravador.
- **O cron tem hora fixa, 06:00 no fuso do site (#350),** e não "1 h depois do primeiro carregamento". O envio semanal sai segunda às 08:00 e lê o último status gravado: com o cron em horário arbitrário, esse status vinha quase sempre do dia anterior, e uma página que entrasse no topo no dia do envio saía "não verificado". `uonix_intelligence_executive_status_first_run()` dá o primeiro disparo (hoje às 06:00 se ainda não passou, senão amanhã; nunca no passado nem no mesmo instante), e a recorrência é diária, então todo dia tem uma conferência às 06:00, **2 h antes do envio de segunda**. O horário é a âncora do primeiro disparo: o WP-Cron roda depois dele, nunca antes, e em produção passa pelo `crontab` a cada 5 minutos (atraso de minutos). Em QA e no local ele dispara por tráfego e pode **atrasar no dia**, mas não deriva (o WP-Cron reagenda ancorado no horário agendado, e não no em que rodou), e o contrato continua sem prometer horário na interface. O intervalo é de 24 h fixas: o 06:00 do fuso do site vale enquanto o deslocamento em relação a UTC não muda (o Brasil não tem horário de verão desde 2019). **Um evento já agendado não é movido** (reagendar a cada requisição o empurraria para sempre): o cron que já existe em produção segue na hora antiga até o evento ser recriado, e depois do primeiro disparo o WP-Cron o repete a cada 24 h a partir dele. Para trazê-lo ao 06:00 sem esperar, basta `wp cron event delete uonix_intelligence_page_status_daily`: o próximo carregamento recria o evento no 06:00.
- **O que ele grava** em `uonix_intelligence_page_status_cache`, por caminho: estado, código, destino e hora.
  - Não grava texto de consulta nem métrica.
  - `unknown` com qualquer código é gravado, porque é resposta do servidor. Com código ≥ 400 ele é fim de cadeia, **exceto os cinco de indisponibilidade passageira (#304): 408, 429, 502, 503 e 504**, que são "sem resposta" como o código 0 (o proxy sem a aplicação, o limite de taxa, o timeout: o nó pode redirecionar de novo quando voltar, e tratá-lo como fim parte a cadeia em duas linhas). 500 e os demais 4xx/5xx seguem fim conhecido, porque o servidor respondeu ali. Abaixo de 400 (3xx sem `Location`, 300, 304...), sem fim. Sem resposta (código 0) não é gravado, e o registro anterior ainda válido daquele caminho fica. O código dos cinco é gravado, e é por ele que o `top_pages()` decide, igual à conferência direta (preso por teste).
  - O status de hoje sempre substitui o anterior.
  - Registros não reconferidos hoje ficam até `status_max_age`, e os vencidos ou com hora no futuro saem. O leitor também recusa hora no futuro.
  - Grava sem autoload.
  - A opção está em `protected_options_where()`, porque é estado da origem.
- **Se a busca de páginas falhar, ou faltar credencial,** o cache anterior fica intacto.
- **Com o cache em dia, o envio chega às mesmas linhas** que chegaria conferindo na hora, porque roda o mesmo algoritmo sobre os mesmos status.
- **O envio** lê esse cache (`uonix_intelligence_executive_page_status_cached()`) e não faz nenhuma requisição HEAD. Registro ausente, malformado ou com mais de `status_max_age` (2 dias) vira "não verificado".
- **O que continua no envio:** as três chamadas ao Google.

A requisição passa pelo Rank Math, e isso tem um custo que o User-Agent **não** resolve:

- **O contador do redirecionamento ganha um acesso artificial por dia**, por endereço antigo conferido, desde que a conferência passou para o cron diário (#348). Ele grava só acessos e a data do último, sem User-Agent, então não há como separar esse acesso dos visitantes pelo contador.
- **O monitor de 404 não registra a requisição.** O WordPress encerra HEAD logo depois de `template_redirect`, antes do template, e o monitor captura em `get_header` ou `wp_head`.

As duas coisas foram conferidas pela terceira revisão do PR #301 no código do Rank Math e do WordPress. A versão anterior deste contrato afirmava o contrário das duas. O User-Agent `Uonix-Relatorio-Executivo/1.0` fica porque serve ao log de acesso do servidor.

**Motivos de indisponibilidade têm texto de leitor.** Os motivos da Search Console vêm do Módulo 5, mas o texto da aba Anomalias fala com o operador ("o gatilho tenta de novo na próxima verificação"). A primeira versão delegava a ele, e o e-mail imprimia essa frase. Agora o 57 tem tradução própria para cada motivo, e o teste extrai do código do 58 e do 59 todos os motivos que chegam a uma caixa e exige texto não genérico para cada um. Sem credencial do Google, o motivo é `config_missing`, e não "a consulta falhou": nenhuma consulta foi feita. Isso vale para as três caixas que dependem do Google e para o bloco de páginas, que passou a imprimir o motivo em vez de uma frase fixa.

### O que o Módulo 4 ainda não entrega

- **Custo por Lead**, até existir fonte de gasto de anúncio.
- **Destaques por LLM.** O cliente do Gemini existe (`54-admin-intelligence-ai.php`, usado pela sugestão de Title/Description do Módulo 3), mas os destaques do e-mail executivo continuam determinísticos.
- **Painel.** Nesta fatia o relatório executivo existe só no e-mail; o botão "Enviar Teste Agora" é a forma de vê-lo sob demanda.

## Módulo 8 — Radar de Pautas

Arquivo: `mu-plugins/uonix-admin/63-admin-intelligence-content-radar.php`. Decisões do Cassio em 2026-10-02 (#193):
- só dados próprios: o Search Console e o Gemini que já está em produção;
- quem escreve os posts é a equipe da Uônix, e o Radar entrega uma lista curta e priorizada;
- a lista fica no painel, e o e-mail semanal só traz pauta nova;
- a pauta sai da lista sozinha, ou pelo botão "Descartar".

### O que é candidata

- **A busca** roda num cron diário, `uonix_intelligence_content_radar_daily`, e faz duas chamadas ao Search Console numa janela de 90 dias que termina 3 dias antes de hoje:
  - por consulta, que dá as métricas exatas;
  - por consulta e página, que dá a página líder, a de **mais** impressões. As linhas de página não se somam, porque duas páginas na mesma busca contariam a impressão duas vezes.
- **Uma consulta é candidata** quando passa em todas as regras:
  - posição média **acima de 15**;
  - **5 impressões ou mais** na janela;
  - sem aspas e sem operador (`site:`, `inurl:`, `intitle:`, `intext:`, `allinurl:`, `allintitle:`);
  - sem a marca ("uonix" ou "uônix"), e sem cara de domínio (`fulano.com.br`), que é busca de navegação;
  - aceita por `uonix_analytics_metrics_sanitize_query()` (53), que recusa e-mail, telefone, link e mais de 120 caracteres;
  - sem sinal de HTML nem entidade depois do saneamento. Desde a #382 o 53 recusa a **marcação** por conta própria (decodifica até estabilizar e recusa o que ficou com tag ou entidade), mas a checagem do Radar é **mais estrita e não é redundante**: recusa qualquer `<` ou `>` solto (`carga > 12 kn` passa no 53 e sai aqui) e só vê entidade nomeada sem dígito. Antes, o 53 tirava as tags antes de decodificar, e `&amp;lt;b&amp;gt;` saía dele como `<b>` (MÉDIO 1 da revisão do PR #378).
- **Limite:** até 10 candidatas, por impressões. O limite conta depois de tirar as descartadas. Duas grafias com a mesma chave (consulta normalizada) viram uma candidata.
- **Sem repetir o Módulo 3:** a consulta que o painel do Módulo 3 já mostra como oportunidade (posição de 4 a 12 nos 30 dias do snapshot, até `panel_limit`, hoje 5) sai do Radar, nem visível nem descartada. As que o Módulo 3 calcula e não mostra continuam no Radar. Medido em 2026-10-02: "ensaio de arrancamento" estava nos dois blocos, na posição 10,1 em 30 dias e 15,1 em 90. Decisão do Cassio: cada consulta fica com uma recomendação só.
- **Medido em 2026-10-02:** 163 consultas em 90 dias, e de 5 a 10 passam. A forma de pergunta não é fonte: eram 12 consultas, com 1 impressão cada. 4 das 15 maiores acima da posição 15 eram buscas de spammer, sempre entre aspas.
- **Entre as posições 12 e 15, nenhum módulo lista a consulta.** A faixa do Módulo 3 vai até 12, e a consulta já está perto do topo.

### A pauta da IA

- **O que vai ao Gemini:**
  - a consulta, as impressões em 90 dias e a posição média;
  - a página que aparece hoje, com caminho, tipo e título. Ela é resolvida pelo 54: post, termo (#344) ou o destino de um 301 (#343, até 10 HEADs por execução).
  - Cada endereço é resolvido **uma vez por execução**, e o "não sei" também é lembrado. Medido em 2026-10-02: três endereços apareciam em duas candidatas cada, gastavam o orçamento de HEAD em dobro, e as duas últimas da lista nunca eram conferidas.
- **O que volta:** um JSON com `caminho` (`nova` ou `reforcar`), `titulo` (até 80 caracteres), `angulo` (até 300) e `intencao` (`informacional`, `comercial`, `transacional` ou `navegacional`).
  - Sem HTML e sem link.
  - Sem página resolvida, só `nova` é aceita.
- **A instrução proíbe inventar** norma, número, prazo, preço, certificação e superlativo. Ela só deixa citar NR ou NBR que já esteja na consulta ou no título da página.
- **No reforço, a página é sempre a líder,** fixada pelo código.
- **Cache e custo:**
  - o hash cobre a consulta, a página (caminho, destino, tipo e título) e o modelo, sem as métricas;
  - uma pauta `ok` com o mesmo hash não é refeita;
  - qualquer outro status é tentado de novo no dia seguinte;
  - descartadas não chamam o Gemini;
  - depois de **150 s do início** da execução, nenhuma página é resolvida (nenhum HEAD) e nenhuma chamada começa; as restantes ficam `deferred`. O `crontab` de produção corta em 290 s, e o corte derrubaria também os outros eventos do mesmo ciclo.
- **"Não sei" não é "não há página":** HEAD sem resposta, ou orçamento de HEAD esgotado, reaproveita a página de ontem, e a pauta pronta fica. Sem página anterior, a pauta fica `deferred`, sem chamada. Tratar o "não sei" como página ausente refazia uma pauta "reforcar" boa como "nova" (MÉDIO 2 da revisão do PR #378, a mesma lição do #373).
  - A página de ontem só vale para o **mesmo** caminho líder.
  - Uma página que ontem já era "não sei" fica marcada (`page_unknown`) e não vale como a de ontem: sem isso, o segundo "não sei" seguido chamava o Gemini sem página.
- **Transporte:** é o mesmo da sugestão de título, `uonix_intelligence_ai_generate()`, com uma nova tentativa em 429 e 503, menos no 429 de cota que não volta nesta execução (o `quotaId` diário, ou a espera acima de 60 s: a segunda é inferência, e por isso o painel só diz "diária" com a primeira, #403).
- **Parada (#384):** depois de um `quota_exhausted`, ou de duas falhas seguidas, nenhuma chamada começa. As restantes ficam `deferred`, com o motivo em `reason`, e a cota esgotada grava a hora da renovação em `retry_at` e a origem da classificação em `quota_source` (#403, como no Módulo 3; as adiadas herdam os dois). O painel tem texto próprio para cada caso: "diária" só com `quota_source` igual a `quota_id_daily`, e a hora da renovação só enquanto ainda é futura. Cada chamada grava `http` e `attempts`, e o erro de transporte grava também a causa em `error` (#404), como na sugestão de título. **Timeout (`cURL error 28`) não conta nessa sequência (#404), nem no Radar nem no Módulo 3:** é transitório, e dois deles seguidos adiavam o dia inteiro. Os outros erros de transporte (DNS, TLS) continuam contando. **O timeout não tem teto próprio, de propósito (#473).** Com a rede para o Gemini caída, nenhuma parada por falha dispara, e o teto é o tempo: no Radar, o `ai_budget` de 150 s corta as chamadas, e as candidatas que sobram ficam `deferred` com `reason` **`budget`**, que o painel mostra como "Não pedida: o tempo desta execução acabou antes de chegar nela" (e o e-mail não as lista, porque só entra candidata com pauta). Antes, a adiada por tempo ficava sem `reason`, e nada distinguia "a rede caiu" de "o dia acabou". O `reason` `budget` só vale para o orçamento de **tempo**: o "não sei" do resolvedor de página (a candidata espera, sem chamar o Gemini) continua sem `reason`, porque não é falta de tempo. Um contador próprio de timeouts (por exemplo, parar depois de 3 seguidos) foi **descartado**: gastaria 3 × 30 s para parar um cron que o orçamento já limita a 158 s e que se repete no dia seguinte, e adicionaria um segundo estado de parada por uma falha que a leitura de 2026-11-17 mostrou ser transitória. O custo aceito: um dia de rede fora gasta até 158 s de espera do cron e não chama o Gemini com sucesso, e o único rastro é o `error` de cada pedida (`cURL error 28`) e o `reason` das adiadas.

### O que é gravado

São três opções, sem autoload e protegidas no clone:

| Opção | Conteúdo |
|---|---|
| `uonix_intelligence_content_radar` | `updated_at`, `list_updated_at`, `status` (`ok`, `gsc_failed` ou `config_missing`), `window`, `truncated` e as candidatas (visíveis e descartadas), cada uma com a consulta, as métricas, a página e a pauta |
| `uonix_intelligence_content_radar_dismissed` | hash da consulta => data do descarte. **Sem texto** |
| `uonix_intelligence_content_radar_emailed` | hash da consulta => data em que foi ao e-mail. **Sem texto.** Cada registro vale 180 dias, e data no futuro não conta |

- **Privacidade (#253):** o texto de consulta só existe nas candidatas. O resto da resposta da API fica em memória e é descartado.
- **Falha do Search Console, ou falta de credencial,** grava só `status` e `updated_at`. A lista anterior fica, com a hora dela em `list_updated_at`.

### E-mail e painel

- **E-mail:** o bloco "Pautas novas para o blog" vem depois das oportunidades e só aparece quando há pauta nova.
  - Pauta nova quer dizer: visível, com pauta `ok` e não enviada nos últimos 180 dias. Sem a chave do Gemini, vão as candidatas sem pauta.
  - Vão no máximo 3. Se sobrarem novas, o bloco fecha com "Mais N pautas novas no painel", e N conta só as novas que não couberam. **Sem a chave do Gemini** (#459), as candidatas sem pauta também vão ao e-mail (as que já tinham pauta `ok` com a mesma entrada continuam com ela, porque a pauta pronta é retida antes de olhar a chave), e a frase passa a "Mais N consultas novas no painel": o N conta o conjunto todo, e chamar de "pauta" o que em parte ainda é só uma consulta prometeria ao leitor o que o painel não tem. As já enviadas, as descartadas e as que ainda esperam a pauta da IA não entram na conta (#380). Sem novas sobrando, a frase não aparece.
  - A linha de fonte do bloco diz de quando é a lista ("lista de DD/MM/AAAA HH:MM (UTC)"). Se a última execução não conseguiu ler o Search Console (`gsc_failed` ou `config_missing`), uma segunda linha avisa que a lista é a anterior (#381). O resto do e-mail continua em UTC, e o bloco segue essa convenção.
  - Os 180 dias impedem que uma consulta oscilando em torno do limite gere e-mail toda semana (#297).
- **Quem marca uma pauta como enviada** é só `uonix_intelligence_send_scheduled_report()`, o envio semanal agendado, e só quando o e-mail sai. O "Enviar Teste Agora" mostra o mesmo bloco e não gasta a novidade.
- **Painel:** a seção "Radar de Pautas" fica no fim da aba "Oportunidades SEO".
  - A tabela mostra consulta, posição, impressões, a página que aparece hoje (com o 301 e o link "Editar") e a pauta.
  - Há mensagens para cada estado: ainda não rodou, sem candidata, Search Console indisponível, corte de linhas, sem chave e pauta não gerada.
  - **Horários no fuso do site** (`wp_date`), como o Módulo 3 mostra os dele, e sem o rótulo "(UTC)": "atualizado em", "Gerada por IA em", "Mostrando a lista de" e a hora de renovação da cota (#386). Sem `wp_date`, cai em UTC e o rótulo volta, **exceto numa data sem hora**, que nunca leva o rótulo (#456, preso por teste em subprocesso).
  - **Dois helpers, de propósito (#457).** `uonix_intelligence_site_time()` (54, a cota do Módulo 3) e `uonix_intelligence_radar_time()` (56, a tela do Radar) formatam o mesmo instante do mesmo jeito. Não viraram um só porque o 56 trata o 54 como opcional (toda chamada dele ao 54 é guardada por `function_exists`) e um helper compartilhado daria ao painel uma dependência dura; mover o helper para o 53 não resolve, porque nenhum dos dois chama o 53 hoje. O que os mantém iguais é um teste que carrega os dois no mesmo processo e compara a saída, com `wp_date` em dois fusos e sem ele. A única diferença é o `$com_fuso` do 56.
  - **A lista cheia mostra as `max_candidates` de mais impressões.** Uma restaurada com menos impressões que as 10 da lista fica de fora até abrir vaga. O aviso de restaurar diz quantas candidatas ficaram de fora naquele momento (`overflow` do estado), sem apontar qual: o estado sabe quantas, e não se a restaurada é uma delas (#379). A ordem do estado é a do cron (impressões, depois o texto), e a restaurada entra no lugar certo na hora.
  - **"Descartar" e "Restaurar"** valem para quem vê a Central, com `edit_posts` e `uonix_ksio_can_access_tool( 'analytics' )`. O descarte vale na hora, e as descartadas ficam numa tabela recolhida enquanto ainda passarem na regra.
  - **Em lote** (pedido do Cassio em 2026-10-02): cada tabela é um formulário, com uma caixa por linha, "selecionar todas" e "Descartar selecionadas" ou "Restaurar selecionadas". O botão de uma linha vale só para ela, mesmo com outras caixas marcadas.
  - **Limites e segurança:** no máximo 20 chaves por pedido. O nonce é por ação, porque um formulário serve várias pautas, e cada chave continua conferida contra as candidatas gravadas. O aviso diz quantas foram.

### O que o Módulo 8 não entrega

- rascunho de post escrito pela IA;
- marcar a pauta como "Feita" e acompanhar o post publicado;
- pautas a partir de concorrentes, que é o Módulo 7 (#377);
- consulta em forma de pergunta como fonte;
- aplicar qualquer coisa sozinho no WordPress.

## Ordem de entrega dos módulos

Credencial faltante crescente:

1. **Módulo 3** — Oportunidades SEO (Search Console). Caminho de dados pronto.
2. **Módulos 4, 5 e 6** — Relatório Executivo, Alerta de Anomalias e Atribuição de Origem. Usam GA4 e o banco local; nenhuma credencial nova.
3. **Módulo 1** — Desperdício em Google Ads. Exige decidir entre leitura via GA4 e API direta; hoje não há token nem código.
4. **Módulo 2** — Desperdício em Meta Ads, **sem o indicador de EMQ**. Exige acesso aprovado.
5. **Módulo 8** — Radar de Pautas, com dados próprios do Search Console e o Gemini. Ver a seção do módulo.
6. **Módulo 7** — Radar de Concorrentes. Adiado em 2026-10-02 (#377): falta fonte de dados de concorrência.

Em 2026-10-02, os Módulos 1, 2 e 6 foram parados porque a Uônix não tem gasto com anúncios: #374, #375 e #376, cada uma com a condição para retomar.

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

O envio automático da Central depende de uma licença em duas camadas, combinadas por `mu-plugins/uonix-admin/50-admin-intelligence-license.php`:

- **as constantes do `wp-config.php`,** a trava forte (#312);
- **a licença do painel,** na aba Configurações, que só o dono do ksio.dev altera e que **só restringe** (#318).

**Vale a mais restritiva.** O painel nunca religa o que a constante pausou.

| Constante | Valores | Papel |
|---|---|---|
| `KSIODEV_INTELLIGENCE_STATUS` | `active`, `trial` ou `suspended` | `active` é contratado, `trial` é cortesia e `suspended` pausa |
| `KSIODEV_INTELLIGENCE_VALID_UNTIL` | `AAAA-MM-DD` | Último dia com envio, inclusive, no fuso do site (`wp_timezone()`) |

A licença do painel fica na opção `uonix_intelligence_license`, com as chaves `status` e `valid_until` e os mesmos valores das constantes. `valid_until` vazio é "sem data-limite".

### Decisão: a constante é a trava forte, e o painel só restringe

A constante foi escolhida em 2026-09-29, na #312:

- **A constante só muda com acesso ao servidor.** Uma opção no banco fica ao alcance de outro administrador, mesmo com a trava — ver *Limites*.
- **Um clone não copia `wp-config.php`** (ver `docs/ambientes.md`), então a constante de um ambiente não vaza para outro.
- **É o canal que o PHP já usa** para configuração por ambiente: `define()` no `wp-config.php`, como os segredos.

A licença do painel veio no mesmo dia, na #318, para o dono suspender ou pôr prazo sem SSH. Por isso ela só restringe: quem grava a opção sem ser o dono, trocando a senha do `ksiodev` ou pelo banco, consegue no máximo tirar a restrição do painel, nunca a da constante.

Não há filtro sobre o estado. Um filtro deixaria qualquer plugin reverter a suspensão.

### Regra de cada camada

A tabela vale para as constantes e, com as diferenças listadas depois dela, para a licença do painel.

| Constantes | Envio | Motivo |
|---|---|---|
| Nenhuma das duas | Segue como sempre | Sem controle configurado; o deploy não muda produção |
| `active` sem data | Ativo | |
| `active` ou `trial` com data de hoje ou futura | Ativo | |
| Só a data, de hoje ou futura | Ativo | Conta como `active` com prazo |
| `suspended`, com ou sem data | **Pausado** | `suspended` |
| Data já passada, com `active`, `trial` ou sem status | **Pausado** | `expired` |
| `trial` sem data | **Pausado** | `invalid`: cortesia sem fim é configuração incompleta |
| Status ou data que a regra não reconhece | **Pausado** | `invalid` |

O último caso cobre erro de digitação no **valor**: `suspenso`, `Active` ou `31/12/2026` pausam. A data é validada de ida e volta, então `2026-02-30` é inválida em vez de virar 2 de março.

**Constante definida como `null` não é constante ausente (#315).** `define( 'KSIODEV_INTELLIGENCE_STATUS', null )`, que o `wp config set --raw` e uma edição à mão produzem, é valor que a regra não reconhece: pausa, com `invalid` e a camada `configured`. Só a constante **não definida** (`defined()` falso) é "sem controle", e na constante **"sem data-limite" é só `KSIODEV_INTELLIGENCE_VALID_UNTIL` não definida**: `null` e `''` pausam com `invalid`. O caso que muda de comportamento é `STATUS` `active` com `VALID_UNTIL` definida como `null`, que alguém pode ler como "sem data-limite": antes enviava, e agora pausa (medido em 646 estados, comparando o código de antes e o de agora: as únicas diferenças são constantes definidas como `null`). Quem separa as duas é `uonix_intelligence_license_constant_value()`, que entrega `null` à regra só para a constante ausente. A regra pura `evaluate( null, null )` segue lendo `null` como "ausente", porque a camada do painel depende disso (opção ausente é sem controle).

Erro no **nome** da constante não é detectado. `KSIODEV_INTELIGENCE_STATUS` deixa a constante certa ausente, e ausente é o padrão: o envio segue. Por isso a conferência da *Operação* é obrigatória.

**Na licença do painel:**

- **opção ausente** é "sem controle", como constante ausente;
- **opção que não seja `{status, valid_until}`** com valores reconhecidos pausa, com `invalid`;
- **as duas chaves são obrigatórias.** Status ausente ou nulo pausa: no painel o status é sempre escolhido, então não existe o caso "só a data". `valid_until` ausente, nulo ou com o nome errado (`valid_untill`) também pausa, mesmo com `status` válido;
- **só `valid_until` vazio (`''`)** é "sem data-limite".

### Combinação das camadas

- **Se uma camada pausa,** o envio pausa, e o estado é o dela. Se as duas pausam, vale o da constante.
- **Se as duas enviam,** vale o prazo mais curto entre as camadas configuradas. Camada sem controle não entra. Sem prazo em nenhuma, ou com prazos iguais, vale a constante.
- **Sem nenhuma das duas,** o envio segue como sempre, e o deploy não muda produção.

`uonix_intelligence_license_state()` devolve o estado combinado e mais três chaves:

- `source`: `constant`, `panel` ou `''`, quando nenhuma camada está configurada;
- `constant` e `panel`: o que cada camada diz sozinha.

**Estado montado à mão (#314).** Os textos (`message`, `summary`, `layer_summary`, `date_label`) leem `reason`, `status` e `valid_until` por `uonix_intelligence_license_text_key()`: chave ausente, ou de tipo que não é texto (array, número, `null`), vale `''`, sem aviso de PHP. O estado do `uonix_intelligence_license_state()` sempre tem `status`, `valid_until` e `reason` como texto (`configured` e `sending` são bool), então isto só protege um chamador futuro. Estado que não é array conta como pausado e inválido. Sem data, o aviso de vencida diz "venceu" (e a camada, "Vencida"), sem inventar o dia nem deixar "em ," na frase.

### Gravação da licença do painel

- **Tela:** o bloco *Licença da Central*, na aba Configurações, só para o dono. Mostra o estado que vale, com a origem, e o que diz cada camada.
- **Handler** `admin_post_uonix_intelligence_save_license`: só o dono (403 antes do nonce), e com nonce.
- **Validação antes de gravar.** São recusados, com aviso e sem gravar nada:
  - status fora de `active`, `trial` e `suspended`;
  - data que não seja um `AAAA-MM-DD` real;
  - `trial` sem data;
  - status vazio ou ausente do POST (`missing`).
- **"Sem controle pelo painel"** apaga a opção, e só pelo valor explícito `none`. Até a #322, o status vazio também apagava. Assim, salvar o formulário com a opção malformada, ou mandar um POST sem o campo, tirava a pausa sem o dono pedir.
- **Opção malformada** é a que existe e que o 50 lê como `invalid`, por exemplo `{"status":"active"}` sem `valid_until`. Nesse caso:
  - a tela mostra um aviso acima do formulário;
  - o seletor vem com o marcador "Escolha o status" selecionado, vazio e obrigatório, além das quatro opções;
  - salvar exige escolher o status de novo.
- **Só `status` e `valid_until` são gravados.** Campo extra no POST é ignorado.
- **Trava da opção:** o filtro `pre_update_option_uonix_intelligence_license`, na prioridade `PHP_INT_MAX`, devolve o valor antigo para quem não é o dono, e o WordPress desiste da gravação.
  - Vale para qualquer `update_option()`, inclusive `/wp-admin/options.php`.
  - O WP-CLI passa, porque quem tem SSH já altera o `wp-config.php`.
- **Clone:** a opção está em `protected_options_where()`, e o destino fica com a licença que já tinha. `scripts/tests/test-clone-activation-options.sh` reprova se ela sair da lista.

### Limites

- **O painel não é barreira contra outro administrador.**
  - O `root` pode trocar a senha do `ksiodev` pela tela de Usuários (limite da #310) e depois gravar como dono.
  - Acesso ao banco ou a arquivos também contorna o painel.
- **A trava cobre `update_option()`, e não `delete_option()` nem `add_option()`.** Fora do handler do dono, os dois só são alcançáveis por código no servidor. Apagar a opção só tira a restrição do painel: a constante continua valendo.
  - O mesmo limite vale para a trava dos destinatários (`uonix_executive_report_recipients`, ver *Destinatários do relatório*): cobre `update_option()`, não `delete_option()` nem `add_option()`.
- **A suspensão definitiva é pela constante.**

### O que pausa e o que não pausa

**Pausa:**

- o relatório semanal, em `uonix_intelligence_send_report()` (57);
- o **Enviar Teste Agora**, que passa pela mesma função. Sem isso, o botão contornaria a suspensão;
- o alerta de anomalia, em `uonix_intelligence_anomaly_send_alert()` (58).

Os dois pontos devolvem o motivo `license_inactive` e tratam a ausência do 50 como licença inativa.

**Não pausa:**

- o agendamento: os eventos continuam registrados, e só o envio devolve `license_inactive`;
- a detecção de anomalias e o badge do painel;
- a loja e o site: os dois hooks do 50 só agem na gravação da licença do painel.

**A suspensão não gasta as tentativas do alerta.** `license_inactive` segue a regra de `no_recipients`: não conta como tentativa e o estado não avança. Reativada a licença com a anomalia em curso, o aviso sai na verificação seguinte.

### Aviso na interface

Com o envio pausado, as abas **Anomalias** e **Configurações** mostram um aviso de contato com a ksio.dev, com o motivo e, no vencimento, a data. Com o envio ativo não há aviso. Se o 50 não carregar, o aviso diz que o controle não carregou.

A Visão Geral do menu ksio.dev, que só o dono vê, tem o cartão **Licença da Central de Inteligência**. Ele mostra o estado que vale e a origem, `wp-config.php` ou painel, e o botão **Alterar no painel** leva ao bloco da licença.

### Operação

**Pelo painel,** logado como `ksiodev`: aba Configurações do Uônix Insights, bloco *Licença da Central*.

**No servidor,** a partir da raiz do WordPress:

```bash
wp config set KSIODEV_INTELLIGENCE_STATUS suspended --type=constant
wp config set KSIODEV_INTELLIGENCE_STATUS trial --type=constant
wp config set KSIODEV_INTELLIGENCE_VALID_UNTIL 2026-12-31 --type=constant
wp config delete KSIODEV_INTELLIGENCE_STATUS --type=constant
wp config delete KSIODEV_INTELLIGENCE_VALID_UNTIL --type=constant
wp option update uonix_intelligence_license '{"status":"suspended","valid_until":""}' --format=json
wp option delete uonix_intelligence_license
```

A opção por WP-CLI não passa pela validação do formulário. Um valor fora da regra pausa, com `invalid`, inclusive sem a chave `valid_until`: grave sempre as duas chaves, com `"valid_until":""` para "sem data-limite".

**Depois de cada `wp config set`, `wp config delete` ou `wp option`, confira.** É a única forma de pegar erro no nome da constante:

```bash
wp eval 'var_export( uonix_intelligence_license_state() );'
```

- **Com constante definida,** a chave `constant` tem de trazer `'configured' => true`. `false` significa que o nome não bate. O `configured` de fora não serve para isso, porque a licença do painel também o liga.
- **Ao suspender,** o resultado tem de trazer `'sending' => false`, `'reason' => 'suspended'` e, em `source`, a camada que suspendeu.

O mesmo estado aparece no cartão da Visão Geral do ksio.dev.

O controle é a alavanca de suspensão para destinatário externo. Antes do primeiro envio a um, a licença do ambiente deve estar configurada com o status e a data combinados.

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

O destinatário registrado é a caixa do operador. O controle de licença da Central existe desde a #312; antes de um destinatário externo, a licença do ambiente tem de estar configurada — ver *Licenciamento*.

### O que este registro não prova

A sincronização com GA4 e Search Console **não foi disparada por esta execução**. A evidência de que o caminho até a API real funciona em produção é indireta: o snapshot estava fresco, com horário de sincronização do próprio dia. Uma execução que force a sincronização — `scripts/maintenance/smoke-intelligence-seo.php` com o argumento posicional `sync` — ainda não foi registrada aqui.

Também não foi verificado o relatório renderizado: o envio reporta sucesso do `wp_mail`, não que o HTML tenha chegado legível. Isso depende de alguém abrir a caixa.

## Documentos relacionados

- [ambientes.md](ambientes.md) — contrato canônico de ambientes, constantes e guards de deploy.
- [deploy.md](deploy.md) — checklist pós-deploy e política de rollback.
- [estrutura.md](estrutura.md) — o que é e o que não é versionado.
