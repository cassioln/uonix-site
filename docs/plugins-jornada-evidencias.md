# Jornada vital — evidências da implementação local

## Estado da entrega

Implementação na branch `feat/plugins-jornada-local`, a partir de `df6fad20ef12b740c5d3d0fd32b79b1ec180ac53`, preparada para publicação em PR com autorização de Cassio. Sem merge, deploy ou aplicação em produção. Contrato e operação: [plugins-jornada-vital.md](plugins-jornada-vital.md).

O checkout principal permaneceu limpo, no commit `6c24edbbac5f3674c41235d3b62805f424ac9c1f`. A implementação está somente na worktree da branch; não existe automaticamente no checkout operacional de `origin/master`.

## Provas executadas

| Prova | Resultado observado |
|---|---|
| Jornada real independente | Saída 0: orçamento RFQ persistido, lead Form 4 e notificações cliente/admin capturadas no Mailpit |
| Integração real em `executar()` | Saída 0; jornada verde na baseline e depois de uma etapa local; orçamento/lead/notificações verificados e limpos em ambas |
| Etapa usada na integração | `user-role-editor` 4.67.1 → 4.67.1; comando WP-CLI real, **versão mantida**, sem novo clone ou SSH; não é prova de upgrade |
| Falha real do espelho de lead | Fixture removeu apenas o callback do espelho no request sintético: cotação=true, lead=false, notificações=true; saída 20 |
| Falha real de notificação | Fixture bloqueou apenas o e-mail administrativo correlacionado, sem desabilitar a expectativa: cotação=true, lead=true, notificações=false; saída 20 |
| Auditoria de limpeza posterior | Zero pedidos, leads, sessões e mensagens dos marcadores; sem guarda/trava; fixture de falhas removida |
| Mutações do gate em snapshot isolado | Controle verde; 3 mutações aplicadas, 3 confirmadas (`1→0`), 3 detectadas: sem baseline, sem pós-etapa e ignorar limpeza |
| Regressão de confirmação visual | RED sem confirmação visual, depois GREEN exigindo `recebido=true` |
| Regressões de segurança/contrato | RED→GREEN para retorno malformado, lista de notificações reduzida, ambiente herdando variáveis indevidas e limpeza parcial |
| Cancelamento e encerramento do Chrome | Regressões cobrem launcher tardio, DevTools pendente, falha de interceptação e saída atrasada após SIGKILL; não libera limpeza antes de confirmar saída do processo aberto |
| Navegador com fixture no CI | Chrome real fez clique, POST, opt-out e bloqueou outra origem antes da chamada; não equivale à prova WordPress |
| HTTP local final | Home, `/cotacao/`, `/produtos/` e `/sitemap_index.xml`: 200 |

Na integração repetida após a primeira correção de cancelamento, a baseline criou orçamento `11513`, lead `1029` e capturou 2 mensagens; após a etapa, orçamento `11514`, lead `1030` e 2 mensagens. Esses registros eram sintéticos e foram removidos; a contagem observada não é uma constante imposta ao sistema. A jornada independente também passou novamente, com auditoria de limpeza verde.

## Checks finais

Todos os comandos abaixo terminaram com saída 0:

- `python3 scripts/tests/test-plugins-politica-contratos.py`
- `python3 scripts/tests/test-plugins-ensaio.py`
- `python3 scripts/tests/test-plugins-aplicar.py`
- `python3 scripts/tests/test-plugins-relatorio-email.py`
- `bash scripts/tests/test-plugins-remoto-aplicar.sh` — fixture, não executou job em produção.
- `php scripts/tests/test-plugins-jornada.php`
- `node scripts/tests/test-plugins-jornada.mjs`
- `node scripts/tests/test-admin-atualizacoes-sistemicas-navegador.mjs` — consumidor do helper Chrome compartilhado alterado.
- `node scripts/tests/test-admin-editor-menus-navegador.mjs` — outro consumidor do helper Chrome compartilhado.
- `bash scripts/tests/test-ci-covers-all-tests.sh` — 143 arquivos de teste têm invocação efetiva no workflow; **não significa que todos os 143 foram executados nesta tarefa**.
- `actionlint .github/workflows/validate.yml`
- `git diff --check`

`php -l` e `node --check` também passaram nos novos arquivos. A varredura focada não encontrou segredo hardcoded, `shell=True`/`os.system` ou pickle no conjunto alterado. O `eval` do PHP é o transporte explícito do arquivo versionado da guarda pelo WP-CLI local; não recebe entrada de endpoint público.

## Limites e pendências

- **Revisão independente de snapshot concluída:** sessão separada, sem ferramentas, `local-44ffabfc8965343474dce139e21d1af1b7259c3e5f785e24ad923ca95258fea2`, retornou `passed=true`, sem bloqueadores. Foram corrigidos o cancelamento da jornada, o aborto imediato da interceptação e a espera de saída confirmada do Chrome após SIGKILL. As tentativas anteriores por delegação/Claude Code falharam por autenticação/modelo/timeout e não foram contadas como aprovação. A revisão do snapshot não substitui revisão humana do PR nem a prova local de integração.
- CI remoto e matriz Linux/PHP 8.3 serão registrados nos checks do PR; não são comprovados pelos testes locais, lint ou revisão do snapshot.
- A integração mantém a versão instalada: novo ensaio completo prod→local com versões efetivamente diferentes ainda é necessário para qualificar um novo lock operacional. Nenhum lock anterior ganha cobertura retroativa.
- Mailpit comprova geração/captura local, não entrega SMTP de produção. Turnstile real, pagamentos e integrações CRM/newsletter não são exercitados.
- Evidências detalhadas, JSON e capturas ficaram no scratch do profile ativo. São artefatos transitórios, não parte do repositório nem memória durável.
