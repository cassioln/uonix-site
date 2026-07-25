#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

python3 - "$ROOT_DIR" <<'PY'
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])

contracts = {
    '.github/workflows/deploy-development.yml': {
        'required': (
            'vars.ENABLE_DEPLOY_DEVELOPMENT',
            'vars.HOSTGATOR_DEV_ROOT',
            'vars.DEVELOPMENT_URL',
        ),
        'forbidden': (
            'vars.ENABLE_DEPLOY_DEV',
            'vars.UONIX_DEV_ROOT',
            'vars.UONIX_DEV_URL',
        ),
    },
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

print('PASS: deploy DEV/QA usa somente as Variables canônicas.')
PY
