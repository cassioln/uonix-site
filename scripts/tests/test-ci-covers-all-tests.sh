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

# rglob, não iterdir: um teste em scripts/tests/<sub>/ escaparia de uma varredura
# rasa e voltaria a ficar fora do CI — exatamente a classe de falha que este guarda
# existe para impedir.
present = {
    str(path.relative_to(tests_dir)) for path in tests_dir.rglob('*')
    if path.is_file() and path.suffix in {'.sh', '.php'}
}

referenced = set()
disabled = []
for workflow in workflows.glob('*.yml'):
    text = workflow.read_text(encoding='utf-8')
    referenced.update(re.findall(r'scripts/tests/([A-Za-z0-9._/-]+\.(?:sh|php))', text))
    # Um teste referenciado dentro de um step tolerante a falha não protege nada:
    # o CI seguiria verde com o teste vermelho.
    for block in re.split(r'\n      - name: ', text):
        if 'continue-on-error: true' not in block and 'if: false' not in block:
            continue
        for name in re.findall(r'scripts/tests/([A-Za-z0-9._/-]+\.(?:sh|php))', block):
            disabled.append(f'{workflow.name}:{name}')

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

if disabled:
    raise SystemExit(
        'testes referenciados em steps tolerantes a falha (não protegem o CI): '
        + ', '.join(sorted(disabled))
    )

print(f'PASS: {len(present)} testes presentes, todos referenciados e nenhum tolerante a falha.')
PY
