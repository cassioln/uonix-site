# Contrato da manutenção semanal com janela SSH

## Autoridade e limites

A rotina do profile Rocky pode abrir temporariamente o SSH da hospedagem **73381366 / uonix.com.br**, uma vez por ciclo semanal de manutenção, às segundas-feiras às 09h em `America/Sao_Paulo`. Essa autorização de acesso não autoriza alterações de plugins, sobrescrita local, restauração, deploy ou envio de relatório externo.

- Preservar janela preexistente e não fechar como limpeza.
- Não renovar indefinidamente, não repetir abertura ambígua e não criar outro ID para contornar recibo/trava.
- Sessão expirada, CAPTCHA ou 2FA exigem login manual no Chrome dedicado; não tentar senhas automaticamente nem copiar sessão de outro navegador.
- Sobrescrita local exige confirmação atual de que não há trabalho/processo a preservar.
- Produção exige autorização explícita de cada lock novo. O job nunca executa `aplicar-producao`, nem sua validação preparatória.
- Nenhum merge automático. A revisão de IA usa Sonnet 5.5 no provedor escolhido para a revisão; isso não muda o modelo padrão nem os pins do cron.

## Instalação e verificação

O helper operacional usa uma revisão Git fixa e limpa, em worktree detached fora do scratch. O profile/runtime é privado e separado do perfil pessoal. Atualização dessa revisão é uma operação deliberada: não resolver `HEAD` ou atualizar automaticamente na execução semanal.

Revisão inicialmente instalada: `004db26debb1da89a5f25a5c5c6b5e6b9e28ff0e`. A revisão efetivamente adotada após correções deve ser conferida no script prévio e no contrato vivo do job, não deduzida desse registro histórico.

Caminho do executor:

`/Users/cassio/.hermes/profiles/rocky/runtimes/uonix-ssh/004db26debb1da89a5f25a5c5c6b5e6b9e28ff0e`

Cada atualização usa outro diretório com seu SHA completo; não editar o runtime fixado anterior. Conferir revisão, CI, sessão e execução somente leitura no scheduler antes de substituir o contrato semanal. A expiração normal da sessão da Locaweb exige reautenticação humana; não tentar mantê-la viva artificialmente.

O script de pré-leitura do profile é `scripts/uonix-ssh-status.sh`, fora do repositório. Ele exige `HERMES_HOME` do Rocky, fixa o PATH operacional, usa o scratch do Rocky como TMPDIR, confere HEAD exato e árvore limpa e executa somente `ssh-painel.mjs status`. Seu resultado é contexto para o agente, não autorização para ultrapassar gates.

Antes de substituir o gate de janela humana do job existente, exigir:

1. Revisão independente válida do helper e do contrato do agendamento.
2. Login real no perfil dedicado e leitura headless em processo novo.
3. Execução somente leitura pelo agendador real, no mesmo profile/workdir, com resultado e entrega conferidos separadamente.
4. CI do SHA operacional conferido.

Uma prova temporária `no_agent` no mesmo job deve executar só status; não iniciar inventário/ensaio nesse teste. Só após seu encerramento e conferência, restaurar o modo com agente e aplicar o contrato semanal aprovado. Não duplicar o cron nem modificar seus horários, destinatários ou modelo por implicação.

## Fluxo de cada ciclo

1. Ler status pelo executor fixo. Falha ou alvo/login não confirmado interrompe o ciclo.
2. Revalidar SHA e árvore limpa imediatamente antes de habilitar.
3. Obter data local por ferramenta. Usar `semanal-AAAAMMDD`, apenas na segunda-feira corrente.
4. Invocar uma única vez a CLI `habilitar`, com a confirmação exata descrita no [guia do painel](plugins-ssh-painel.md).
5. Exigir saída 0 e recibo concluído com ciclo/alvo/domínio corretos, estado habilitado e sonda autenticada. Conferir o recibo privado exato. Se já habilitado, `alterou_ssh=false`; não há clique ou renovação.
6. Falha, tentativa ambígua ou trava pendente interrompe o fluxo. Não apagar recibos/travas, forjar resultado ou inventar outro ciclo. A janela nominal é de três horas; uma janela preexistente não prova tempo restante. Expiração não autoriza renovação do mesmo ciclo.
7. Fazer inventário somente leitura. Sem autorização atual de sobrescrita, parar antes de clone/ensaio e pedir apenas esse gate, não pedir novamente abertura já comprovada.
8. Com autorização local atual, seguir a skill e a documentação da esteira, incluindo CI da revisão operacional, clone completo, jornada vital e qualificação do lock. A revisão da esteira pode ser o `origin/master` vigente; é separada da revisão fixa do helper SSH.
9. Apresentar novo lock e pedir sua aprovação em sessão interativa; nunca aplicar pelo job.

Checkpoint privado por ciclo em `$HERMES_HOME/autonomy/uonix-plugins-weekly/`: data, revisões do helper e da esteira, ordem status/revalidação/abertura, fase, evidências, saída e gate. Checkpoint anterior não é autorização nem motivo para repetir abertura.

## Evidências e lacunas

A fixture Chrome real prova os controles sintéticos. Sessão real headless e sonda autenticada comprovam acesso daquele instante. Caminho real com janela já habilitada prova recarga/identidade/sonda/recibo sem clique. Nenhuma dessas provas estabelece a validade da sessão sete dias depois ou o comportamento futuro do fornecedor.

A transição real desabilitado → habilitado da CLI nova continua **não verificada**; foi exercitada somente na fixture loopback. Não fechar acesso em uso apenas para produzir essa evidência. Eventual teste de interrupção exige conferir concorrência e reabrir/confirmar na sequência.

A situação da instalação, do último teste agendado e do CI deve ser obtida dos artefatos vivos e checks, não deduzida deste documento. Configuração armazenada não comprova uma execução ou entrega futura.
