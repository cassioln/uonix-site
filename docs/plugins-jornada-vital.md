# Jornada vital local da esteira de plugins

## Escopo e decisão

A geração do lock exige prova automática da jornada comercial completa, antes das atualizações e depois de cada etapa concluída: produto → checkout clássico → orçamento persistido → lead Form 4 → notificações RFQ capturadas no Mailpit.

Falha vital **pré-existente também bloqueia**. Ausência de alvo, dependência indisponível, execução incompleta ou limpeza incompleta não viram aviso nem sucesso. A semântica de regressão dos demais checks HTTP permanece inalterada. Etapa que já falhou em update, contrato ou smoke regressivo encerra o ensaio sem executar novas jornadas que gravariam dados, e sem gerar lock.

O desenho aprovado usa o navegador real já disponível no repositório, sem adicionar Playwright ou dependências npm. Não altera o formato do lock nem o job de produção. A autorização humana do lock e a janela SSH continuam obrigatórias; nenhum código desta jornada abre a janela ou aplica em produção.

## Componentes

| Arquivo | Responsabilidade |
|---|---|
| `scripts/plugins/ensaio.py` | Gate antes/depois das etapas, invalidação do clone antes da primeira escrita da jornada, relatório e bloqueio do lock |
| `scripts/plugins/jornada.mjs` | Coordenação, validação das evidências, API do Mailpit e limpeza em `finally` |
| `scripts/plugins/jornada-navegador.mjs` | Chrome com perfil descartável, cliques reais, formulário e bloqueio de origens diferentes |
| `scripts/plugins/jornada-wp.php` | Preflight e consultas/limpeza correlacionadas por WP-CLI no container local |
| `scripts/plugins/jornada-guarda.php` | Plugin temporário de isolamento e observação, nunca instalado em produção |
| `scripts/tests/test-plugins-ensaio.py` | Contratos do gate e da ordem de execução sem serviços externos |
| `scripts/tests/test-plugins-jornada.mjs` | Contratos, ciclo de vida, limpeza e navegador com fixture HTTP local |
| `scripts/tests/test-plugins-jornada.php` | Validação de ambiente, propriedade dos registros e transporte SMTP com stubs |
| `scripts/tests/fixtures/jornada-falhas.php` | Injeção local de falha de lead/notificação, somente em requisições correlacionadas de teste |

## Pré-requisitos adicionais

- Node 22 ou superior com WebSocket nativo e Google Chrome/Chromium disponível. `CHROME_BIN` permite selecionar um binário existente; não instala nada.
- Container `uonix-local-app`, WordPress configurado com `UONIX_ENV=local`, URLs HTTP de loopback e checkout de visitante habilitado.
- WP-CLI já preparado pela esteira em `/tmp/wp-cli.phar` no container.
- Mailpit acessível em `http://localhost:8025`; `UONIX_MAILPIT_URL` permite outro endereço HTTP de loopback, sem autenticação embutida na URL.
- Política SMTP local efetiva em `mailpit:1025`, sem autenticação ou TLS externo. O preflight inspeciona o resultado de `phpmailer_init` sem enviar mensagem.
- Produto simples publicado, comprável e em estoque; Form 4 e notificações RFQ configurados. Nenhuma notificação RFQ habilitada reprova o preflight.

O alvo é validado **antes** de navegar. Outros containers/projetos, URLs remotas e credenciais em URL são recusados. Não carregar `.env` nem credenciais de produção para a jornada independente.

## Contrato de sucesso

1. A mesma sessão nova do Chrome clica no CTA da página real de produto, preenche o checkout clássico e envia o formulário. Não há criação administrativa do pedido ou do lead esperado.
2. Existe exatamente um `shop_order` para o e-mail sintético exclusivo, com gateway `gpls-rfq`, status `gplsquote-req`, produto esperado e quantidade 1.
3. Existe exatamente uma submissão nova no Form 4, com e-mail, nome, empresa e telefone esperados; origem `FORMULÁRIO DE SOLICITAÇÃO DE ORÇAMENTO`; newsletter `nao`. A comparação respeita normalização de caixa/telefone do código do site.
4. Cada notificação RFQ habilitada (`customer_rfq` e/ou `new_rfq`) foi capturada no Mailpit, com cabeçalho de correlação, tipo, destinatários efetivos e conteúdo referente ao pedido. Não se exige quantidade global fixa; mensagens adicionais correlacionadas são permitidas.
5. A lista de notificações habilitadas não muda entre a baseline e as etapas. Desabilitar uma notificação durante a atualização não reduz a exigência silenciosamente.
6. A limpeza foi concluída e confirmada por leitura. Somente então `ok=true` pode fechar o gate.

Destinatários administrativos são comparados por SHA-256, não publicados nos relatórios. O teste usa nome, empresa, endereço e e-mail sintéticos exclusivos; não lê pedidos, leads ou mensagens de outros clientes.

## Isolamento e limpeza

- O browser bloqueia requisições de origens diferentes pelo protocolo DevTools antes de continuá-las. Isso inclui consultas de CEP/CNPJ e analytics presentes na página.
- O timeout de 90 s cancela a sequência DevTools; nenhum comando posterior pode continuar. Com navegador já aberto, aguarda o fechamento antes de liberar a limpeza. Se o launcher resolver tardiamente, apenas fecha, sem navegar ou submeter formulário.
- A guarda local bloqueia o HTTP pela API do WordPress e recusa SMTP fora do Mailpit. Webhooks do WooCommerce e feeds não e-mail do Fluent Forms são impedidos de agendar entrega para a requisição sintética.
- O subprocesso da jornada recebe uma lista explícita de variáveis de execução local (PATH, diretórios temporários e configuração Podman/Chrome); não herda as credenciais carregadas pelo `.env` da esteira.
- E-mails do Fluent Forms são processados de forma síncrona no teste. Essa instrumentação **não comprova** o agendador assíncrono de produção nem integrações CRM/newsletter.
- A limpeza usa o serviço do WooCommerce e o modelo do Fluent Forms, com IDs/formulários correlacionados. O `LIKE` localiza candidatos, mas só Form 4 com e-mail/empresa exatos pode ser apagado. Remove apenas a sessão registrada pela adição do próprio produto e eventual conta sintética criada por engano.
- Mensagens só são excluídas pelos IDs cujo cabeçalho de propriedade foi confirmado. **Nunca enviar `DELETE /api/v1/messages` com `IDs` vazio ou ausente:** a API apagaria a caixa inteira.
- A cópia temporária da guarda é conferida por caminho, conteúdo e propriedade da execução antes de ser removida. A trava impede jornadas concorrentes.
- Interrupção abrupta/SIGKILL pode deixar uma trava local; o próximo ciclo deve recusar e pedir investigação. Não apagar pastas, registros ou travas às cegas.

Os controles de HTTP/SMTP e navegador não são um firewall do sistema operacional. Não cobrem código arbitrário que ignore a API HTTP do WordPress. A jornada não prova entrega SMTP de produção, antispam/Turnstile real, CRM, newsletter ou pagamentos.

## Execução e evidências

`ensaiar` chama a jornada automaticamente. Para diagnóstico independente no clone local já preparado:

```bash
node scripts/plugins/jornada.mjs --produto-id=<ID-local> --saida=<diretorio-de-evidencias>
```

Saídas da jornada independente: `0` verde; `20` reprovação operacional/funcional; `30` argumentos/alvo inválidos antes da execução. O ensaio transforma ausência de prova ou qualquer reprovação vital em saída `20`, sem lock.

Cada fase gera `jornada-antes.json` ou `jornada-etapa-N.json`, mais o subdiretório homônimo com `relatorio.json` e `cotacao.png`. Em falha, `jornada-falha.json` indica a prova reprovada; o relatório Markdown aponta os arquivos. Consultas/limpeza usam o marcador `uonix-jornada-<hex>`.

Um lock anterior à implementação não ganha essa cobertura retroativamente. Para recomendar produção, é necessário novo ensaio pelo código atualizado, evidências completas e autorização explícita de Cassio para o lock resultante. Teste de integração com versão mantida não é atualização real, novo clone ou lock operacional de produção.

## Verificação de desenvolvimento

```bash
python3 scripts/tests/test-plugins-ensaio.py
node scripts/tests/test-plugins-jornada.mjs
php scripts/tests/test-plugins-jornada.php
bash scripts/tests/test-ci-covers-all-tests.sh
```

O CI executa contratos, stubs e fixture de navegador sem WordPress/Podman/Mailpit. A prova real no WordPress local é separada. Para injetar falhas reais, instalar a fixture apenas no local e apenas enquanto durar a prova, selecionando `uonix_jornada_falha_fixture=lead` ou `notificacao`; remover plugin/opção em `finally`, com leitura posterior. Não executar essa fixture em produção.
