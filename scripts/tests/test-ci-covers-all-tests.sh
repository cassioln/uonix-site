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


def is_disabled(value) -> bool:
    """Um step vale como desativado quando sua condição é constantemente falsa.

    Cobre `if: false`, `if: ${{ false }}` e variações de espaçamento/caixa. Uma
    comparação de substring literal deixava passar `if: ${{ false }}`.
    """
    if value is None:
        return False
    if isinstance(value, bool):
        return value is False
    text = str(value).strip()
    inner = re.fullmatch(r'\$\{\{(.*)\}\}', text)
    if inner:
        text = inner.group(1).strip()
    return text.lower() in {'false', '0', 'off', 'no'}


referenced: set[str] = set()
unprotected: list[str] = []

for workflow in sorted(workflows.glob('*.y*ml')):
    text = workflow.read_text(encoding='utf-8')
    referenced.update(REFERENCE.findall(text))

    # Parse estruturado em vez de fatiar texto: um split por `- name:` vaza o
    # cabeçalho do job seguinte para o último step do job anterior, atribuindo
    # `continue-on-error` ao teste errado.
    document = yaml.safe_load(text) or {}
    for job_name, job in (document.get('jobs') or {}).items():
        if not isinstance(job, dict):
            continue
        job_disabled = is_disabled(job.get('if'))
        job_tolerant = job.get('continue-on-error') is True
        for step in job.get('steps') or []:
            if not isinstance(step, dict):
                continue
            names = REFERENCE.findall(str(step.get('run') or ''))
            if not names:
                continue
            step_disabled = job_disabled or is_disabled(step.get('if'))
            step_tolerant = job_tolerant or step.get('continue-on-error') is True
            if not (step_disabled or step_tolerant):
                continue
            reason = 'desativado' if step_disabled else 'tolerante a falha'
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
