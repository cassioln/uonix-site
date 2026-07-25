# Runbook de migração para Locaweb

## Estado e limite operacional

- `site.uonix.com.br` é a produção provisória durante esta migração.
- `uonix.com.br` não muda nesta etapa. Seu cutover é uma operação separada, com decisão humana própria.
- `ENABLE_DEPLOY_PRODUCTION=false` permanece obrigatório até a aprovação explícita do gate de produção.
- Este runbook é um checklist fail-closed; não contém comandos prontos para abrir transporte, publicar arquivos ou alterar DNS.

## Antes de abrir a janela

1. Definir um `RUN_ID` novo e único para a janela; não reutilizar identificadores, backups ou evidências de outra execução.
2. Confirmar backup fresco e restaurável de banco e arquivos, associado ao mesmo `RUN_ID`. Registrar localização, horário, integridade e responsável sem registrar credenciais.
3. Confirmar que a origem e o destino foram revisados por duas pessoas e que o destino continua `site.uonix.com.br`.
4. Confirmar `ENABLE_DEPLOY_PRODUCTION=false`, o estado noindex pré-cutover e a ausência de qualquer mudança planejada para `uonix.com.br`.
5. Materializar chaves, known-hosts e demais segredos apenas no ambiente aprovado, fora do repositório. Nunca copiá-los para tickets, logs ou esta documentação.
6. Revisar a execução canônica no workflow aprovado e, quando aplicável, o dry-run do clone central. Dry-run é evidência, não autorização para execução.

## Gates humanos just-in-time

Não prossiga se qualquer gate estiver ausente:

- aprovação de responsável técnico e responsável de negócio para a janela atual;
- confirmação do `RUN_ID` e do backup fresco;
- confirmação de que `site.uonix.com.br` é o único alvo desta etapa;
- confirmação de noindex antes do cutover e plano explícito para reavaliá-lo depois;
- confirmação de responsáveis por analytics, e-mail e Turnstile;
- decisão de rollback e canal de comunicação disponíveis antes de qualquer mutação.

A aprovação não é reutilizável: qualquer alteração de alvo, janela, backup, artefato ou escopo invalida os gates e exige nova revisão.

## Preflight

Registrar evidências para o `RUN_ID` sem dados sensíveis:

- versão/referência do artefato e checksum aprovado;
- disponibilidade do WordPress, banco, armazenamento e serviços necessários;
- URL canônica, títulos, ambiente WordPress, indexação e saúde de login;
- configuração de analytics desativada ou isolada enquanto o site estiver em noindex pré-cutover;
- política de e-mail: destinatário seguro, bloqueio de destinatários indevidos e transporte esperado;
- Turnstile: chaves pertencentes ao destino, modo esperado e teste funcional sem expor valores;
- backup testado, limites de rollback e responsáveis de plantão.

Qualquer preflight incompleto ou divergente encerra a janela sem publicação.

## Execução controlada

1. Habilitar produção somente após o último gate humano, alterando o controle operacional de forma auditável. Antes disso, `ENABLE_DEPLOY_PRODUCTION=false` é bloqueante.
2. Usar apenas o workflow canônico aprovado e o `RUN_ID` desta janela. Não usar atalhos, scripts legados, cópia manual ou transporte alternativo.
3. Manter noindex durante o pré-cutover. A remoção de noindex requer uma decisão posterior, observabilidade confirmada e aprovação separada.
4. Não iniciar o cutover de `uonix.com.br` durante esta execução.
5. Interromper imediatamente diante de divergência de destino, falha de backup, falha de smoke, envio de e-mail inesperado, analytics indevido ou falha de Turnstile.

## Smoke pós-execução

Validar e registrar, para `site.uonix.com.br`:

- home, páginas críticas, login e fluxo de produto/checkout aplicável;
- URL, HTTPS, redirecionamentos esperados e persistência de noindex pré-cutover;
- ausência de analytics indevido enquanto noindex estiver ativo;
- política de e-mail com destinatário seguro e sem entrega externa não autorizada;
- Turnstile no fluxo protegido;
- disponibilidade de uploads, tema, MU-plugins e integrações essenciais;
- logs de erro, métricas e alertas da janela sem incluir segredos ou dados pessoais.

Smoke incompleto é falha de release, não um item de acompanhamento.

## Rollback

O rollback deve usar exclusivamente o backup fresco do mesmo `RUN_ID` e o procedimento aprovado para o destino. Antes de iniciar, confirmar alvo, escopo, backup e responsável. Após rollback, repetir preflight e smoke, manter noindex e registrar o resultado.

Não faça tentativa corretiva ad hoc depois de uma falha. Preserve evidências, comunique os responsáveis e abra nova janela somente com novo `RUN_ID`, backup fresco e gates renovados.

## Encerramento

Encerrar a janela apenas após o smoke aprovado, decisão explícita sobre noindex e registro do estado de `ENABLE_DEPLOY_PRODUCTION`. O planejamento do cutover de `uonix.com.br` permanece pendente e requer runbook, janela e aprovação independentes.
