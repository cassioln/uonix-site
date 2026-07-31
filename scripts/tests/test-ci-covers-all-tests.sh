#!/usr/bin/env bash
# Garante que todo teste presente em scripts/tests/ é realmente executado pelo CI.
#
# Motivação: validate.yml lista cada teste individualmente, sem descoberta. Ao
# adicionar scripts/tests/test-deploy-remote-blocks.sh o arquivo passou verde
# localmente mas NUNCA rodou no CI, então o "CI success" não provava nada sobre o
# comportamento que ele verificava. test-clone-rollback.sh e test-pagespeed-check.mjs
# estavam na mesma situação. Um teste que não roda no CI não protege ninguém.
#
# O guarda reprova quatro situações:
#   1. teste presente no repositório e não referenciado por nenhum workflow;
#   2. workflow referenciando teste inexistente (renomeio sem atualizar o CI);
#   3. teste referenciado dentro de um step desativado ou tolerante a falha;
#   4. teste em subdiretório de scripts/tests/, que uma varredura rasa não veria.
#
# Convenção deliberada (FP-1): a cobertura exigida é DIRETA — todo teste tem seu
# próprio step no workflow. Cobertura transitiva (um teste invocando outro, ou um
# wrapper/make/npm) NÃO conta, de propósito: um teste que só roda como efeito
# colateral de outro pode ser silenciosamente desligado sem que ninguém perceba,
# e o relatório de falha aponta para o teste errado. Hoje nenhum teste depende de
# invocação transitiva; as menções cruzadas em scripts/tests/ são comentários de
# referência. Se algum dia um helper legítimo precisar existir, dê a ele um nome
# que não case com o padrão de teste (ex.: `lib-*.sh`) em vez de afrouxar o guarda.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

python3 - "$ROOT_DIR" <<'PY'
import pathlib
import re
import sys

try:
    import yaml
except ModuleNotFoundError:  # pragma: no cover - PyYAML faz parte do ambiente de CI
    raise SystemExit('PyYAML indisponível: não é possível auditar a cobertura de testes')

root = pathlib.Path(sys.argv[1])
workflows = root / '.github/workflows'
tests_dir = root / 'scripts/tests'

# Todo sufixo executável usado por testes neste repositório. Restringir a .sh/.php
# tornava o guarda cego a test-pagespeed-check.mjs, que é um teste real e órfão.
TEST_SUFFIXES = {'.sh', '.php', '.mjs', '.js', '.py'}
REFERENCE = re.compile(r'scripts/tests/([A-Za-z0-9._/-]+\.(?:sh|php|mjs|js|py))')

# rglob, não iterdir: um teste em scripts/tests/<sub>/ escaparia de varredura rasa.
present = {
    str(path.relative_to(tests_dir)) for path in tests_dir.rglob('*')
    if path.is_file() and path.suffix in TEST_SUFFIXES
}


def is_effectively_enabled(value) -> bool:
    """Só uma condição RECONHECIDAMENTE verdadeira mantém o step protegido.

    Allowlist, não denylist: `if: false || false` e `if: ${{ 1 == 2 }}` desativam
    um step sem casar com nenhuma forma literal de "false". Como não podemos
    avaliar a linguagem de expressão do GitHub Actions aqui, qualquer condição
    que não seja comprovadamente sempre-verdadeira é tratada como suspeita, e o
    guarda exige que ela seja declarada em CONDICOES_ACEITAS.
    """
    if value is None:
        return True
    if isinstance(value, bool):
        return value is True
    return _normalize_condition(str(value)) in CONDICOES_ACEITAS


def _normalize_condition(text: str) -> str:
    text = text.strip()
    inner = re.fullmatch(r'\$\{\{(.*)\}\}', text)
    if inner:
        text = inner.group(1).strip()
    return ' '.join(text.lower().split())


# Condições sob as quais um teste ainda conta como executado pelo CI. Adicionar
# aqui é uma decisão consciente: cada entrada é uma condição que se sabe verdadeira
# nas execuções que o gate exige.
CONDICOES_ACEITAS = {
    'true',
    'success()',
    'always()',
}


def is_fault_tolerant(value) -> bool:
    """Tolerante a falha é tudo que não for explicitamente falso.

    `continue-on-error: ${{ true }}` e `continue-on-error: 'true'` escapam de uma
    checagem `is True`, mas o GitHub Actions os honra.
    """
    if value is None:
        return False
    if isinstance(value, bool):
        return value is True
    return _normalize_condition(str(value)) not in {'false', '0', 'off', 'no', ''}


referenced: set[str] = set()
unprotected: list[str] = []

for workflow in sorted(workflows.glob('*.y*ml')):
    text = workflow.read_text(encoding='utf-8')

    # Parse estruturado em vez de fatiar texto: um split por `- name:` vaza o
    # cabeçalho do job seguinte para o último step do job anterior, atribuindo
    # `continue-on-error` ao teste errado.
    document = yaml.safe_load(text) or {}
    for job_name, job in (document.get('jobs') or {}).items():
        if not isinstance(job, dict):
            continue
        job_enabled = is_effectively_enabled(job.get('if'))
        job_tolerant = is_fault_tolerant(job.get('continue-on-error'))
        for step in job.get('steps') or []:
            if not isinstance(step, dict):
                continue
            names = REFERENCE.findall(str(step.get('run') or ''))
            names += REFERENCE.findall(str(step.get('uses') or ''))
            if not names:
                continue
            step_enabled = job_enabled and is_effectively_enabled(step.get('if'))
            step_tolerant = job_tolerant or is_fault_tolerant(step.get('continue-on-error'))
            if step_enabled and not step_tolerant:
                # TG-6: só um step que o YAML parseado realmente executa conta como
                # cobertura. Derivar `referenced` do texto bruto fazia um step
                # COMENTADO satisfazer o guarda.
                referenced.update(names)
                continue
            reason = 'tolerante a falha' if step_tolerant else 'condição não reconhecida como verdadeira'
            for name in names:
                unprotected.append(f'{workflow.name}:{job_name}:{name} ({reason})')

missing = sorted(present - referenced)
if missing:
    raise SystemExit(
        'testes presentes no repositório mas NÃO executados por nenhum workflow: '
        + ', '.join(missing)
    )

dangling = sorted(referenced - present)
if dangling:
    raise SystemExit(
        'workflows referenciam testes que não existem: ' + ', '.join(dangling)
    )

if unprotected:
    raise SystemExit(
        'testes que não protegem o CI: ' + ', '.join(sorted(unprotected))
    )

print(f'PASS: {len(present)} testes presentes, todos referenciados e efetivamente executados.')
PY
