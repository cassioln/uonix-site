#!/usr/bin/env bash
# Garante que todo teste presente em scripts/tests/ é executado pelo CI.
#
# Motivação: o CI lista cada teste individualmente em validate.yml. Ao adicionar
# scripts/tests/test-deploy-remote-blocks.sh, o arquivo passou verde localmente mas
# NUNCA rodou no CI — a suíte "16/16" era apenas local. test-clone-rollback.sh
# estava na mesma situação. Um teste que não roda no CI não protege ninguém.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

python3 - "$ROOT_DIR" <<'PY'
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])
workflows = root / '.github/workflows'
tests_dir = root / 'scripts/tests'

# Este próprio arquivo é o único dispensado: ele é o guarda, e é referenciado abaixo.
present = {
    path.name for path in tests_dir.iterdir()
    if path.is_file() and path.suffix in {'.sh', '.php'}
}

referenced = set()
for workflow in workflows.glob('*.yml'):
    text = workflow.read_text(encoding='utf-8')
    referenced.update(re.findall(r'scripts/tests/([A-Za-z0-9._-]+\.(?:sh|php))', text))

missing = sorted(present - referenced)
if missing:
    raise SystemExit(
        'testes presentes no repositório mas NÃO executados por nenhum workflow: '
        + ', '.join(missing)
    )

# Referências a testes inexistentes quebrariam o CI silenciosamente ao renomear.
dangling = sorted(referenced - present)
if dangling:
    raise SystemExit(
        'workflows referenciam testes que não existem: ' + ', '.join(dangling)
    )

print(f'PASS: {len(present)} testes presentes e todos referenciados pelos workflows.')
PY
