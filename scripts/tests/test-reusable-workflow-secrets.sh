#!/usr/bin/env bash
# Garante que todo workflow que chama um reusable workflow contendo
# `secrets.` repassa os secrets explicitamente.
#
# MOTIVAÇÃO (falha real, 2026-07-31, run 30670232135):
# deploy-development.yml chamava ./.github/workflows/_deploy-hostgator.yml sem
# `secrets: inherit`. GitHub Actions NÃO herda secrets em reusable workflows por
# padrão: sem `secrets: inherit` no chamador (ou um bloco `secrets:` declarado
# em `workflow_call`), toda referência a `secrets.X` dentro do reusable resolve
# para string vazia.
#
# O resultado foi silencioso no CI e só apareceu ao ligar ENABLE_DEPLOY_DEVELOPMENT:
# HOSTGATOR_SSH_PRIVATE_KEY chegou vazio e o step `test -n "$..."` abortou.
# O job de deploy ficava `skipped` enquanto o guard era false, então nenhum
# "CI verde" anterior provou que o caminho de deploy funcionava.
#
# Este guarda transforma esse modo de falha em erro detectável sem precisar
# ligar guard nenhum.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

python3 - "$ROOT_DIR" <<'PY'
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])
workflows = root / '.github' / 'workflows'

if not workflows.is_dir():
    print('FALHA: .github/workflows não encontrado', file=sys.stderr)
    raise SystemExit(1)

# Um reusable é "sensível a secrets" se referencia secrets.X em uma expressão
# do Actions (${{ ... secrets.X ... }}). Exigir o delimitador evita casar
# substrings acidentais como "test-reusable-workflow-secrets.sh".
SECRET_REF = re.compile(r'\$\{\{[^}]*?secrets\.([A-Za-z0-9_]+)[^}]*?\}\}', re.DOTALL)
# `uses: ./.github/workflows/<arquivo>.yml` — chamada de reusable local.
USES_LOCAL = re.compile(r'^(?P<indent>\s*)uses:\s*(?P<path>\./\.github/workflows/[A-Za-z0-9._-]+\.ya?ml)\s*$')

def secrets_referenced(path: pathlib.Path) -> set[str]:
    if not path.is_file():
        return set()
    text = path.read_text(encoding='utf-8')
    # `secrets: inherit` não é referência a um secret específico.
    return {m.group(1) for m in SECRET_REF.finditer(text)}


def declares_workflow_call_secrets(path: pathlib.Path) -> bool:
    """True se o reusable declara um bloco `secrets:` dentro de workflow_call."""
    if not path.is_file():
        return False
    lines = path.read_text(encoding='utf-8').splitlines()
    in_call = False
    call_indent = 0
    for raw in lines:
        stripped = raw.strip()
        if not stripped or stripped.startswith('#'):
            continue
        indent = len(raw) - len(raw.lstrip())
        if stripped.startswith('workflow_call:'):
            in_call = True
            call_indent = indent
            continue
        if in_call:
            # Saiu do bloco workflow_call.
            if indent <= call_indent:
                in_call = False
                continue
            if stripped.startswith('secrets:'):
                return True
    return False


violations = []

for wf in sorted(workflows.glob('*.yml')) + sorted(workflows.glob('*.yaml')):
    lines = wf.read_text(encoding='utf-8').splitlines()
    for index, raw in enumerate(lines):
        match = USES_LOCAL.match(raw)
        if not match:
            continue

        # Não usar lstrip('./'): ele removeria também o ponto de ".github".
        target_rel = match.group('path')[2:] if match.group('path').startswith('./') else match.group('path')
        target = root / target_rel
        needed = secrets_referenced(target)
        if not needed:
            continue  # reusable não usa secrets: nada a repassar.

        if declares_workflow_call_secrets(target):
            continue  # contrato explícito no reusable; chamador passa por nome.

        # Procura `secrets:` como irmão de `uses:` dentro do mesmo job.
        uses_indent = len(match.group('indent'))
        found_secrets = False
        for following in lines[index + 1:]:
            if not following.strip() or following.strip().startswith('#'):
                continue
            following_indent = len(following) - len(following.lstrip())
            if following_indent < uses_indent:
                break  # saiu do job
            if following_indent == uses_indent and following.strip().startswith('secrets:'):
                found_secrets = True
                break
            if following_indent == uses_indent and re.match(r'^\s*uses:', following):
                break  # outra chamada; a nossa não tinha secrets

        if not found_secrets:
            violations.append(
                f'{wf.relative_to(root)}:{index + 1} chama {target_rel} '
                f'(usa secrets: {", ".join(sorted(needed))}) sem repassar secrets'
            )

if violations:
    print('FALHA: reusable workflow recebendo secrets vazios.', file=sys.stderr)
    print('', file=sys.stderr)
    for v in violations:
        print(f'  {v}', file=sys.stderr)
    print('', file=sys.stderr)
    print('Adicione `secrets: inherit` ao lado de `uses:` no chamador,', file=sys.stderr)
    print('ou declare um bloco `secrets:` em `workflow_call` do reusable.', file=sys.stderr)
    raise SystemExit(1)

print('PASS: toda chamada de reusable com secrets repassa credenciais.')
PY
