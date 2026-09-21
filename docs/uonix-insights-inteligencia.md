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
| Cache | `53:272-296` | Snapshot em `wp_options`, **sem TTL**; validade por frescor de 24h; snapshot velho é preservado como `stale` (fail-soft) |
| Concorrência | `53:619` | Lock por `add_option`, reivindicado após 600s |
| Sync agendada | `53:655-663` | WP-Cron `daily`, mais refresh manual via `admin_post` |
| Write-path autenticado | `53:736-748` | `admin-post` + `check_admin_referer` + `current_user_can('manage_options')` |
| Guard de e-mail por ambiente | `mu-plugins/uonix-integrations/49-email-environment-label.php:58-66` e `:172-208` | Rotula o assunto em QA, DEV e LOCAL; **bloqueia o envio apenas em QA e DEV** quando `UONIX_NONPROD_EMAIL_TO` está ausente. Em LOCAL **não há bloqueio** — o e-mail é enviado, e a contenção é o Mailpit do ambiente |
| Atribuição de origem | `mu-plugins/uonix-integrations/39-rastreamento-utm-atribuicao.php` | Captura gated por consentimento de marketing AdOpt; grava meta `_uonix_*` no pedido WooCommerce |
| Templates de e-mail HTML | `themes/kadence-child/woocommerce/emails/` | Padrão RFQ, com helper de imagem em `mu-plugins/uonix-woocommerce/29-rfq-email-imagens-produtos.php` |

Os identificadores de propriedade GA4 e de site do Search Console estão **hardcoded como default de parâmetro** em `53-admin-analytics-metrics.php:20`, ou seja, em arquivo versionado. Isso **contradiz** [ambientes.md](ambientes.md), que determina que IDs de analytics fiquem fora do Git.

Este documento **registra o desvio, não o legitima**. A correção é rastreada em issue própria. Enquanto ela não ocorrer, nenhum código novo pode repetir o padrão: identificador de plataforma novo entra por constante no `wp-config.php`, como já ocorre com o caminho da chave da service account.

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
- **Anti-padrão a não replicar**: `mu-plugins/uonix-admin/40-admin-dados-globais-rfq.php:39-43` grava sem nonce e sem re-checagem de capability, e — o defeito mais grave — **o nome da option vem de input do usuário** (`update_option( 'uox_' . $chave, … )`) sem allowlist de chave. Os valores passam por `sanitize_text_field()`, então o problema **não** é falta de sanitização: é ausência de verificação de intenção e de allowlist. Rastreado em issue própria.

### Agendamento

WP-Cron `weekly`. O painel exibe o **próximo disparo real** lido do agendador (`wp_next_scheduled`) e o horário do último envio. O texto ao usuário declara apenas a **frequência** — "semanal" — sem horário e **sem período do dia**.

É proibido prometer horário ou período do dia na interface e no e-mail, incluindo formulações brandas como "às segundas pela manhã". WP-Cron dispara por tráfego, não por relógio, e o site não tem cronjob de servidor: a deriva pode atravessar o dia inteiro, o que torna "pela manhã" tão insustentável quanto "08:00". A única afirmação que o sistema consegue provar é a frequência somada ao próximo disparo agendado.

## Módulo 3 — Oportunidades no Search Console

Primeiro módulo a ser entregue, por ser o único cujo caminho de dados já existe. O snapshot atual já persiste posição, CTR e páginas do Search Console — e a interface não renderiza nenhum dos três.

Parâmetros fixados. A #193 apresenta três faixas divergentes para "striking distance" (4–15, 4–12 e 4–10) no mesmo texto; vale a faixa desta tabela:

| Regra | Valor |
|---|---|
| Faixa de posição | **4 a 12** |
| Volume mínimo | **mais de 100 impressões em 30 dias** |
| CTR | **abaixo de 3%** |
| Período do snapshot que alimenta a regra | **30 dias** |

Descartado o critério "CTR 50% menor que a média da indústria": não há fonte auditável para essa média, e um número sem procedência viola a regra de procedência acima.

A consulta por `query` do Search Console precisa sair do limite de 10 linhas usado hoje para a ordem de mil, para que a mineração tenha universo. A sanitização de query existente em `53:117-132` — que rejeita padrão de e-mail, telefone e URL — continua valendo integralmente: ampliar o volume amplia a superfície de PII na mesma proporção.

A sugestão de Title/Description é **determinística por regra** na primeira entrega. Integração com LLM é escopo separado.

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

## Portas de qualidade

Os testes do projeto são scripts autônomos com stubs do WordPress escritos à mão e respostas de API fixas. Eles provam a normalização do dado — **não** provam que a API externa devolve o que se supôs. Por isso há duas portas distintas:

| Porta | Critério |
|---|---|
| **Merge** | Teste novo em `scripts/tests/` + step correspondente em `.github/workflows/validate.yml` + `php -l` limpo na matriz de versões do CI |
| **Ativação** | Smoke contra a API real, executado à mão, fora do CI, com resultado registrado |

O step no workflow é obrigatório: `scripts/tests/test-ci-covers-all-tests.sh` cruza o diretório de testes com o workflow e reprova o build quando um teste não tem step.

Nenhum módulo é ativado — nem cron, nem envio automático — antes de o smoke passar. Cron registrado e inativo é estado válido e esperado.

## Licenciamento

O controle de licença da Central (status e data-limite) é configurado por constante no `wp-config.php` e ainda não existe no código. Comportamento exigido quando suspenso ou expirado: disparo automático de e-mail e alertas pausa silenciosamente, a interface exibe aviso de contato, e **nenhuma funcionalidade essencial da loja ou do site é afetada** — sem erro de PHP, sem quebra de página.

Implementação obrigatória **antes** do primeiro envio a destinatário externo. Enquanto o único leitor do relatório é o operador, o controle não protege nada; a partir do momento em que há destinatário externo, ele é a única alavanca de suspensão.

## Validação dos números

O Search Console do domínio de produção só tem dados de produção. Em `local` valida-se lógica e HTML; não se valida se o número está certo. Por isso a validação de números acontece em QA, com o guard de deploy correspondente habilitado e `UONIX_NONPROD_EMAIL_TO` definido no `wp-config.php` daquele ambiente — sem essa constante o guard bloqueia o envio, registrando em `error_log`, disparando a ação `wp_mail_failed` e exibindo aviso no admin — a falha é observável, mas o relatório não chega.

O primeiro destinatário do relatório é o operador, por semanas, antes de qualquer envio a destinatário externo. Um número errado no primeiro e-mail externo custa mais que o atraso.

## Documentos relacionados

- [ambientes.md](ambientes.md) — contrato canônico de ambientes, constantes e guards de deploy.
- [deploy.md](deploy.md) — checklist pós-deploy e política de rollback.
- [estrutura.md](estrutura.md) — o que é e o que não é versionado.
