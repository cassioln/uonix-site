# Abertura da janela SSH Locaweb

## Escopo

CLI local para a hospedagem **73381366 / uonix.com.br**. Oferece login manual, leitura de status e uma tentativa de habilitação por ciclo. Não atualiza plugins, não clona ambientes e não possui comando de desabilitação. O job semanal nunca aplica um lock de produção.

O código fica na branch `feat/locaweb-ssh-window`; publicação, CI remoto e integração agendada devem ser verificados separadamente. Perfil de autenticação nunca vai para Git ou artefatos.

## Segurança e sessão

- Alvo exato: `https://painelhospedagem.locaweb.com.br/tools/73381366/ssh`. Validação da URL completa ocorre na própria expressão síncrona antes de ler DOM ou clicar; o domínio `uonix.com.br`, status e controles também precisam corresponder.
- Executor operacional específico do Mac/profile Rocky: `HERMES_HOME=/Users/cassio/.hermes/profiles/rocky`. A fixture Linux do CI não declara portabilidade operacional da CLI.
- Perfil exclusivo privado `0700`: `$HERMES_HOME/locaweb-ssh/perfil`. Senhas, cookies, tokens e armazenamento ficam opacos para o agente. Não copiar sessão da preview/Edge nem usar esse perfil para navegação pessoal.
- Chrome próprio, sandbox e TLS preservados, CDP por pipes sem listener TCP. O ambiente filho filtra variáveis secretas, mas não autentica executáveis: PATH, HOME, TMPDIR e CHROME_BIN são entradas locais confiáveis do operador, não uma fronteira contra o mesmo usuário. O navegador padrão do Hermes e os perfis pessoais não são alterados.
- Restauração nativa `--restore-last-session`, em login visível e status headless, permite ao próprio Chrome restaurar cookies de sessão. Pode restaurar abas antigas do perfil dedicado. Não estende a validade no servidor e depende do encerramento/gravação do navegador; queda/SIGKILL pode perder sessão.
- Login e eventual 2FA são manuais. Sessão expirada ou desafio em execução headless para o ciclo e pede reautenticação; não tenta senha automaticamente, não desativa 2FA e não marca dispositivo confiável por iniciativa própria.
- Só no login supervisionado, após observar origem conhecida de autenticação, a raiz HTTPS do painel é tolerada como retorno SSO. Sem ler DOM ou interpretar parâmetros dessa raiz, observa o endereço estável por 2 segundos e retoma a URL SSH fixa uma única vez. Outro recurso/caminho é recusado; a raiz não é prova de autenticação. `status`/`habilitar` não usam essa exceção.
- Identidade pode carregar depois do cartão SSH; a espera é limitada e nunca qualifica o recurso apenas pelo status.
- Lock exclusivo antes do Chrome. Liberação após saída confirmada, somente pelo marcador vazio do próprio ciclo e `rmdir` não recursivo. Conteúdo/dono divergente impede remoção. Coordena processos cooperativos; não é sandbox contra processo hostil do mesmo usuário capaz de alterar executáveis/runtime.
- Cancelamento e prazo global abortam continuações; não liberar o lock enquanto Chrome ainda puder agir. Não matar navegadores pessoais ou apagar perfil para resolver trava pendente.

## Comandos

Node 22+, Chrome/Chromium e, para sonda, dependências do transporte canônico (`bash`, `ssh`, `sshpass`, configuração privada e host keys) são necessários. Não há pacote npm adicional. `CHROME_BIN` aceita executável absoluto existente.

Execute na revisão que contém estes arquivos e com o `HERMES_HOME` do Rocky:

```bash
# Preparo supervisionado; janela visível, até 10 minutos.
node scripts/plugins/ssh-painel.mjs login

# Processo novo sem interface; não altera janela SSH.
node scripts/plugins/ssh-painel.mjs status

# Só após autorização vigente para esse ciclo/recurso.
node scripts/plugins/ssh-painel.mjs habilitar \
  --ciclo=semanal-AAAAMMDD \
  --confirmacao='HABILITAR SSH 73381366 uonix.com.br'
```

A frase técnica não substitui autorização humana/recorrente registrada. Não forneça senha/código no chat, comando ou terminal. `login` fecha ao reconhecer o painel exato; falha também encerra a instância própria. `--help` não abre navegador. Sucesso retorna 0; falha/gate retorna 30, com mensagem sanitizada.

### Contrato da habilitação

1. Exige confirmação exata e ID de ciclo simples, perfil privado e lock exclusivo.
2. Recusa ciclo já usado, recibo inválido ou intenção pendente de tentativa anterior. Não inventar outro ID para contornar recusa.
3. Lê o recurso exato. Se já habilitado, não clica nem renova a janela; apenas recarrega e sonda.
4. Se desabilitado, recusa nova abertura quando houver abertura própria concluída nas últimas 3 horas, contadas conservadoramente do fim da verificação (depois do clique). Fechamento manual nesse intervalo não autoriza renovação automática. Grava intenção privada **antes** do primeiro clique. Se já habilitado, grava somente recibo de leitura; uma falha sem clique não bloqueia todos os ciclos seguintes.
5. Cada clique revalida URL, identidade, estado, modal e controle no mesmo turno JS. Confirma apenas o modal de habilitação com título/ação/Cancelar/duração esperados. Nunca clica em desabilitar.
6. Aguarda a alteração concluir, exige recarga em novo documento (`loaderId` diferente), relê status/identidade e executa sonda SSH autenticada somente leitura pelo transporte do projeto, com host keys e limite de tempo.
7. Só conclui o recibo depois de painel **e** sonda verdes. Erro/interrupção deixa intenção pendente, bloqueando nova tentativa automática. Não faz fechamento/rollback remoto; o estado final desejado é acesso habilitado.

`ssh-sonda.sh` usa `atualizar.sh` como biblioteca, valida checkout e identidade do transporte, carrega a configuração existente sem imprimi-la e executa apenas `echo JANELA-ABERTA`. Não chama inventário/update. Usa configuração canônica com host keys, mas desativa multiplexação/ControlPersist nesta sonda única para não criar master desacoplado. O supervisor limita o tempo, sinaliza o grupo próprio e confere remanescentes; a sonda não desabilita a janela no painel.

### Recibos e recuperação

`$HERMES_HOME/locaweb-ssh/ciclos/<ciclo>.json`, modo `0600`: alvo, domínio, ciclo, timestamps, estado, indicação de alteração e sonda. Não contém credenciais. Esse diretório é operacional e não deve ser publicado.

Intenção pendente ou `.json.novo` remanescente pede revisão humana: conferir processos, painel após recarga e sonda antes de decidir a recuperação. Não apagar globalmente recibos/travas nem reexecutar com novo ID; nenhuma remoção automática está implementada. Recibo concluído comprova aquele ciclo, não o estado futuro.

A duração nominal é **3 horas**, não o tempo restante de janela preexistente. Não existe renovador permanente. Nesta etapa Cassio pediu manter acesso habilitado; eventual teste que o feche precisa reabri-lo e confirmar na sequência.

## Evidências e limites

- RED→GREEN: identidade/estado, perfil/locks, URL no caminho CDP, navegação entre pré-checagem/leitura, retorno SSO e cookie sintético só de sessão.
- Chrome real em loopback: persistência nativa entre processos e modal/cliques de habilitação sintética. Negativos e falhas cobrem autorização, ciclo repetido, intenção pendente, já habilitado sem clique, confirmação após recarga, sonda e cancelamento.
- Sessão **real** dedicada foi autenticada manualmente, encerrada e reutilizada em processo headless independente, validando hospedagem/identidade e SSH habilitado. CLI `status` retornou 0 com `alterou_ssh=false`.
- Sonda canônica real retornou `SSH_AUTENTICADO_OK`. Não comprova atualização de plugins nem duração semanal da autenticação.
- O caminho real `habilitar` com janela **já habilitada** concluiu recarga em novo documento, identidade, sonda e recibo privado com `alterou_ssh=false`. Não fechou, abriu nem renovou a janela. Isso não comprova o clique real partindo do estado desabilitado.
- Abertura automatizada em estado desabilitado, execução no scheduler, publicação e CI remoto têm gates próprios: só registrar como concluídos após evidência real. Revisões anteriores de login/status não cobrem automaticamente o módulo mutante.

Conferências locais:

```bash
node scripts/tests/test-plugins-ssh-painel.mjs
node scripts/tests/test-plugins-ssh-abertura.mjs
node --check scripts/plugins/ssh-painel.mjs
node --check scripts/plugins/ssh-painel-chrome.mjs
node --check scripts/plugins/ssh-abertura.mjs
bash -n scripts/plugins/ssh-sonda.sh
shellcheck -x scripts/plugins/ssh-sonda.sh
bash scripts/tests/test-ci-covers-all-tests.sh
actionlint .github/workflows/validate.yml
git diff --check
```

O guarda de cobertura mostra registro/invocação na configuração CI, não execução de todos os testes localmente. Fixture não substitui painel real. Sessão válida hoje não garante funcionamento na segunda-feira; Mac/scheduler precisam estar disponíveis.

## Integração semanal

Usar somente o job existente de segunda às 09h `America/Sao_Paulo`. Executor deve apontar para revisão fixa fora do scratch; não executar fonte mutável ou worktree que será removida. Antes da integração mutante, provar status pelo scheduler com os mesmos profile, ambiente e diretório, conferindo resultado e entrega separadamente.

O ciclo pode abrir SSH uma vez com a autorização recorrente vigente. Não renovar após expiração ou repetir tentativa ambígua. Sem sessão válida, avisar e parar. Confirmar escopo de sobrescrita local antes de clone/ensaio. Aplicação em produção sempre exige um novo lock explicitamente aprovado em conversa interativa: o job **nunca executa `aplicar-producao`**.
