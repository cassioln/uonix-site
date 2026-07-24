# Uonix — Remediation T33 e nova revisão independente

> **Para Hermes:** executar de forma serial no worktree autorizado. `default/Sol` controla gates; `codex-terra` pode implementar apenas correções locais após o gate de triagem. Não usar dispatcher automático, `delegate_task`, Git remoto ou operações de infraestrutura.

**Goal:** converter o veredito `REPROVADO` da revisão independente T33 em um conjunto verificável de correções locais, validar um snapshot auditável e repetir a revisão read-only antes de considerar Tasks 34+.

**Architecture:** o card existente `t_ac86f403` continua sendo o único ledger para T33. Ele permanece `scheduled` como hold manual, com o comentário de reprovação. Não criar um segundo card T33; a nova revisão será uma nova evidência sobre esse mesmo card, somente após a suíte local estar verde.

**Tech Stack:** Bash 3.2, PHP 8.3/8.5, Node.js, Git, GitHub Actions YAML, Podman local sem pull/rede externa quando requerido, Hermes Kanban.

---

## Estado vigente e limites

```text
WORKTREE AUTORIZADO:
/Users/cassio/GitHubPessoal/uonix-site-multi-environment-migration

BRANCH:
feature/multi-environment-migration

BASELINE HEAD:
19fe1c1effd86b4faad6226e62b3d1aa730f5e74

BOARD:
uonix-local-code-29-33

T33:
t_ac86f403 / scheduled / assignee=codex-sol-reviewer
VEREDITO VIGENTE: REPROVADO

T34+:
NÃO INICIADO
```

A fonte persistente de continuidade é:

- `/Users/cassio/.hermes/handoffs/uonix-tasks-29-33.md`, seção **Checkpoint vigente — T33 reprovada e remediation local**;
- card `t_ac86f403`, incluindo o comentário `T33 REVIEWER VERDICT: REPROVADO`;
- plano principal `/Users/cassio/GitHubPessoal/uonix-site/.hermes/plans/2026-07-21_002558-migracao-locaweb-e-topologia-multiambiente.md`.

### Proibições até a nova T33 aprovada

- Não iniciar Tasks 34–64.
- Não fazer commit, push, PR, merge, checkout destrutivo, reset, stash ou limpeza de untracked.
- Não acessar GitHub mutável, SSH, hosts, banco real, Secrets, `.env`, clone real, rehearsal, migração, deploy ou produção.
- Não alterar guards remotos; `ENABLE_DEPLOY_PRODUCTION=false` permanece obrigatório.
- Não tratar o exit `0` do processo do reviewer anterior como aprovação.

---

## Task R1 — Fechar a triagem dos achados T33

**Objective:** obter uma lista completa, classificada e reproduzível dos achados antes de editar código.

**Files:**
- Ler: card `t_ac86f403`, handoff vigente, diff completo e testes relacionados.
- Criar fora do Git: relatório sanitizado em `/tmp/uonix-t33-triage-<RUN_ID>.md` se necessário.

**Passos:**

1. Consultar o card T33 e confirmar que permanece `scheduled`, sem worker ativo e sem diagnostics.
2. Recuperar o resultado do reviewer anterior apenas de artefatos/session logs acessíveis; não inventar achados que não estejam presentes.
3. Se a enumeração completa não puder ser recuperada, executar uma auditoria local **read-only** do snapshot atual para produzir uma nova lista canônica de achados, com `path:line`, impacto, severidade e teste reprodutível.
4. Classificar cada item como bloqueante, alto, médio, baixo ou documental.
5. Separar problemas já resolvidos daqueles que só possuem mudança parcial ou nenhum teste verde.
6. Registrar no handoff somente fatos confirmados; não alterar o status de T33.

**Aceite:** lista integral de achados com evidência, sem alteração de arquivos de produção e sem ação remota.

---

## Task R2 — Criar baseline auditável antes da remediation

**Objective:** impedir que alterações preexistentes no worktree sejam confundidas com a correção T33.

**Files:**
- Criar fora do Git: `/tmp/uonix-t33-baseline-<RUN_ID>/` com permissões privadas.

**Passos:**

1. Confirmar `pwd`, branch, HEAD e `git status --porcelain=v1 -uall`.
2. Criar manifestos separados para arquivos tracked modificados e arquivos untracked, sem ler `.env`, chaves ou arquivos de credencial.
3. Calcular hashes de manifestos e de diffs tracked sem imprimir conteúdo sensível.
4. Registrar comandos, hashes e contagens no relatório sanitizado e no handoff.
5. Nunca usar `git clean`, `reset`, `stash` ou cópia indiscriminada para obter o baseline.

**Aceite:** snapshot local identificável que permita ao reviewer distinguir baseline, remediation e estado não rastreado.

---

## Task R3 — Corrigir contratos de workflow por TDD

**Objective:** alinhar os testes estáticos ao desenho fail-closed atual e provar que eles rejeitam regressões.

**Files prováveis:**
- `scripts/tests/test-production-workflow.sh`
- `scripts/tests/test-clone-workflow.sh`
- `scripts/tests/test-clone-lock.sh`
- `.github/workflows/deploy-production.yml`
- `.github/workflows/clone-environment.yml`
- `.github/workflows/_deploy-hostgator.yml`
- `.github/workflows/validate.yml`

**Passos:**

1. Ler integralmente os workflows e os dois contratos antes de editar; `scripts/tests/` pode estar untracked, portanto não confiar apenas em `git diff`.
2. Atualizar cada assert legado que exige a estrutura antiga somente quando o novo contrato oferecer proteção equivalente ou mais forte.
3. Preservar como requisitos mínimos: autorização manual ligada a SHA/ref, allowlist antes de Secrets, Environment separado de clone em produção, Secrets por step, `concurrency`, owner lock, preflight em argv/heredoc literal, checkout do SHA aprovado, smoke e rollback fail-closed.
4. Fazer RED explícito de cada novo requisito antes do GREEN.
5. Rodar, no mínimo:

```bash
bash scripts/tests/test-production-workflow.sh
bash scripts/tests/test-clone-workflow.sh
bash scripts/tests/test-clone-lock.sh
bash -n scripts/clone-environment.sh scripts/tests/test-production-workflow.sh scripts/tests/test-clone-workflow.sh scripts/tests/test-clone-lock.sh
```

6. Rodar `actionlint` depois de cada alteração de workflow, sem executar workflow remoto.

**Aceite:** os três contratos passam, rejeitam entradas inseguras e não dependem de layout YAML obsoleto.

---

## Task R4 — Corrigir runtime PHP e caminhos irmãos por TDD

**Objective:** garantir que MU-plugins/callbacks sejam seguros sem WooCommerce e que a lógica de clone preserve o estado exigido.

**Files prováveis:**
- `mu-plugins/uonix-content/37-blog-arquivo-editor.php`
- `mu-plugins/uonix-woocommerce/14-checkout-newsletter-mirror.php`
- `mu-plugins/uonix-woocommerce/16-woocommerce-thank-you.php`
- `mu-plugins/uonix-woocommerce/17-woocommerce-checkout-design.php`
- `mu-plugins/uonix-woocommerce/20-catalogo-titulos-produtos.php`
- `scripts/clone-environment.sh`
- testes PHP e Bash adjacentes em `scripts/tests/`

**Passos:**

1. Para cada finding, criar ou completar teste RED mínimo que reproduza a ausência de WooCommerce, hook sem argumentos ou falha de remapeamento.
2. Aplicar a menor correção fail-closed: validar argumentos, usar `function_exists()`/`class_exists()` apropriados e propagar erros sem mutar o destino.
3. Procurar sibling paths que invocam a mesma API opcional; corrigir a classe de falha, não só um callback.
4. Executar os testes focados primeiro e, depois, as suites PHP nas imagens locais 8.3 e 8.5 com `--pull=never` e sem rede externa.

**Aceite:** regressões reproduzidas em RED e verdes em PHP 8.3/8.5; nenhuma dependência de WooCommerce é assumida em callback global.

---

## Task R5 — Revisar documentação e scripts legados

**Objective:** remover instruções operacionais contraditórias sem misturar esse trabalho com operações remotas.

**Files prováveis:**
- `README.md`
- `docs/deploy.md`
- `docs/clone-ambientes.md`
- `docs/migracao-locaweb.md`
- `local/README.md`
- scripts legados relacionados a staging/clone, somente se o finding T33 os apontar.

**Passos:**

1. Classificar referência como histórica, deprecada ou ativa.
2. Atualizar somente instruções ativas que contradigam a topologia `master → site.uonix.com.br`, `qa → uonix.ksio.dev`, `dev → test.uonix.ksio.dev`, `local → localhost`.
3. Documentar que deploy/clones/migração remotos continuam bloqueados por T33 e por aprovações humanas just-in-time.
4. Não prometer fallback FTP automático nem ativar guards.

**Aceite:** documentação não instrui operação insegura, e histórico permanece rotulado em vez de apagado cegamente.

---

## Task R6 — Validar e congelar snapshot local

**Objective:** produzir evidência suficiente para uma segunda T33 independente.

**Passos:**

1. Executar contratos de workflow e clone, Bash syntax, ShellCheck e `actionlint`.
2. Executar PHP lint e testes comportamentais em 8.3 e 8.5; rodar Node e Compose somente se os arquivos relacionados tiverem mudado.
3. Executar `git diff --check` global.
4. Produzir manifestos/hashes atualizados de tracked e untracked, comparando-os ao baseline R2.
5. Registrar resultados reais, comandos, exit codes e escopo de arquivos; não resumir como verde sem output verificável.
6. Confirmar zero containers temporários, processos workers, recursos `uonix-t32-*` e efeitos remotos residuais.

**Aceite:** suíte relevante verde, snapshot identificado, zero efeito remoto e nenhuma credencial exposta.

---

## Task R7 — Repetir T33 e decidir o gate

**Objective:** obter veredito novo, independente e read-only sobre o snapshot R6.

**Passos:**

1. Manter `t_ac86f403` em hold manual; não usar dispatcher automático.
2. Iniciar sessão `codex-sol-reviewer/gpt-5.6-sol` com worktree, SHA/base, manifestos e escopo exatos.
3. Proibir edição, containers, rede externa, SSH, Git mutável, Secrets, lifecycle Kanban e delegação.
4. Exigir veredito explícito `APROVADO`, `APROVADO_COM_RESSALVAS` ou `REPROVADO`, com `path:line` e evidência.
5. O Sol confere o resultado antes de qualquer alteração no card.
6. Somente se aprovado: completar/adjudicar T33, atualizar handoff e considerar planejamento da Task 34.
7. Se reprovado: registrar comentário, manter hold manual e voltar à Task R1 com achados adicionais.

**Aceite:** decisão auditável; nenhuma inferência baseada apenas no código de saída do processo.

---

## Próximo passo imediato

Executar **Task R1 — Fechar a triagem dos achados T33**. Ela é read-only, não toca ambiente remoto e evita implementar correções contra uma lista incompleta de problemas.
