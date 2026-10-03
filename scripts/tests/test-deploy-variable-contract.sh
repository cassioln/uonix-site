#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

python3 - "$ROOT_DIR" <<'PY'
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])

contracts = {
    '.github/workflows/deploy-qa.yml': {
        'required': (
            'vars.ENABLE_DEPLOY_QA',
            'vars.HOSTGATOR_QA_ROOT',
            'vars.QA_URL',
        ),
        'forbidden': (
            'vars.UONIX_QA_ROOT',
            'vars.UONIX_QA_URL',
        ),
    },
}

for relative_path, contract in contracts.items():
    text = (root / relative_path).read_text(encoding='utf-8')
    for variable in contract['required']:
        if not re.search(rf'\b{re.escape(variable)}\b', text):
            raise AssertionError(f'{relative_path}: Variable obrigatória ausente: {variable}')
    for variable in contract['forbidden']:
        if re.search(rf'\b{re.escape(variable)}\b', text):
            raise AssertionError(f'{relative_path}: alias obsoleto presente: {variable}')

# O ambiente remoto de desenvolvimento saiu da topologia: seu workflow de deploy
# não deve voltar por descuido, porque ele reintroduziria Variables e um docroot
# que já não pertencem ao contrato.
retired_workflows = (
    '.github/workflows/deploy-development.yml',
)

for relative_path in retired_workflows:
    if (root / relative_path).exists():
        raise AssertionError(f'{relative_path}: workflow de ambiente retirado da topologia voltou a existir')

print('PASS: deploy de QA usa somente as Variables canônicas e nenhum workflow retirado voltou.')
PY
