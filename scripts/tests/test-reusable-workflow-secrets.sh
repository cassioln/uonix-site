#!/usr/bin/env bash
# Garante que todo workflow que chama um reusable workflow contendo secrets
# repassa esses secrets explicitamente.
#
# MOTIVAÇÃO (falha real, 2026-07-31, run 30670232135):
# deploy-development.yml chamava ./.github/workflows/_deploy-hostgator.yml sem
# `secrets: inherit`. GitHub Actions NÃO herda secrets em reusable workflows por
# padrão: sem `secrets: inherit` no chamador (ou um mapeamento explícito em
# `secrets:`), toda referência a secrets dentro do reusable resolve para string
# vazia. HOSTGATOR_SSH_PRIVATE_KEY chegou vazio e o step `test -n` abortou.
#
# O defeito era invisível ao CI: enquanto ENABLE_DEPLOY_* ficava false, o job de
# deploy era `skipped`, então nenhum "CI verde" exercitou esse caminho.
#
# POR QUE PARSING YAML E NÃO REGEX (revisão independente do PR #12):
# a primeira versão deste guarda casava as linhas com expressão regular e dava
# PASS falso em três grafias válidas do MESMO defeito, todas aceitas pelo
# actionlint:
#   1. uses: "./.github/workflows/x.yml"          (path entre aspas)
#   2. uses: ./.github/workflows/x.yml  # comentário inline
#   3. reusable referenciando ${{ secrets['NOME'] }} (acesso por índice)
# Um guarda que só reconhece a grafia de hoje não previne a regressão de amanhã,
# por isso a detecção usa PyYAML (já instalado no CI por validate.yml) e a busca
# de secrets cobre acesso por ponto E por índice, com fail-closed para formas
# dinâmicas não analisáveis.
#
# A rodada 2 REPROVOU a versão PyYAML por PASS falso: quando o chamador usava um
# bloco `secrets:` mapeado, o guarda cobrava apenas os secrets declarados
# `required: true` no reusable e descartava o conjunto de uso real. Um secret
# efetivamente usado, porém declarado `required: false` (ou sem a chave), não
# mapeado, passava verde e chegaria vazio no Actions — o mesmo defeito do run
# 30670232135, agora com o guarda aprovando. A cobrança passou a ser a UNIÃO do
# contrato `required: true` com os secrets realmente referenciados.
# A mesma rodada mostrou que paths locais equivalentes com travessia
# (`./.github/workflows/../workflows/x.yml`) escapavam da inspeção sendo aceitos
# pelo actionlint, então o path é normalizado antes do match.
#
# LIMITE CONHECIDO E DELIBERADO: apenas reusables LOCAIS (./.github/workflows/…)
# são inspecionados. Chamadas cross-repo (org/repo/.github/workflows/x.yml@ref)
# não são analisáveis sem acesso ao outro repositório e ficam fora do escopo.
# A grafia sem `./` também não é inspecionada, porque o actionlint a reprova.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

python3 -c 'import yaml' \
  || { echo 'PyYAML ausente: instalando para auditar o repasse de secrets'; python3 -m pip install --quiet pyyaml; }

python3 - "$ROOT_DIR" <<'PY'
import pathlib
import posixpath
import re
import sys

import yaml

root = pathlib.Path(sys.argv[1])
workflows = root / '.github' / 'workflows'

if not workflows.is_dir():
    print('FALHA: .github/workflows não encontrado', file=sys.stderr)
    raise SystemExit(1)

# Acesso por ponto: ${{ secrets.NOME }}
SECRET_DOT = re.compile(r'secrets\s*\.\s*([A-Za-z0-9_]+)')
# Acesso por índice com literal: ${{ secrets['NOME'] }} / secrets["NOME"]
SECRET_INDEX_LITERAL = re.compile(r'secrets\s*\[\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\]')
# Índice dinâmico: ${{ secrets[format(...)] }} / secrets[matrix.x] — não
# analisável estaticamente, tratado como "usa secrets" (fail-closed).
SECRET_INDEX_DYNAMIC = re.compile(r'secrets\s*\[(?!\s*[\'"][A-Za-z0-9_]+[\'"]\s*\])')
# `secrets:` como chave YAML (ex.: `secrets: inherit`) não é uma referência.
EXPRESSION = re.compile(r'\$\{\{(.*?)\}\}', re.DOTALL)

# GITHUB_TOKEN é provisionado automaticamente pelo Actions em todo job, incluindo
# reusables locais: não precisa de `inherit` nem de mapeamento, e exigi-lo seria
# falso positivo que quebraria workflows legítimos.
AUTOMATIC_SECRETS = frozenset({'GITHUB_TOKEN'})

LOCAL_USES = re.compile(r'^\./\.github/workflows/([A-Za-z0-9._-]+\.ya?ml)$')


def local_reusable_name(uses: str):
    """Nome do arquivo se `uses` aponta para um reusable LOCAL, senão None.

    Normaliza o path antes de casar: `./.github/workflows/../workflows/x.yml` e
    `./.github/workflows/./x.yml` são equivalentes a `./.github/workflows/x.yml`
    e o actionlint os aceita, então precisam ser inspecionados igualmente.
    """
    candidate = uses.strip()
    if not candidate.startswith('./'):
        # Grafia sem `./` é reprovada pelo actionlint; fora do escopo aqui.
        return None
    normalized = posixpath.normpath(candidate)
    match = LOCAL_USES.match('./' + normalized)
    return match.group(1) if match else None


def load(path: pathlib.Path):
    try:
        return yaml.safe_load(path.read_text(encoding='utf-8'))
    except yaml.YAMLError as exc:
        print(f'FALHA: YAML inválido em {path.relative_to(root)}: {exc}', file=sys.stderr)
        raise SystemExit(1)


def secrets_used_by(path: pathlib.Path):
    """Nomes de secrets referenciados, e se há referência dinâmica.

    Comentários YAML são removidos antes da varredura: um nome citado em
    comentário (ex.: `# antes usava ${{ secrets.LEGADO }}`) não é uso real e
    inflaria a exigência sobre o chamador.
    """
    if not path.is_file():
        return set(), False
    lines = []
    for raw in path.read_text(encoding='utf-8').splitlines():
        stripped = raw.lstrip()
        if stripped.startswith('#'):
            continue  # comentário de linha inteira
        lines.append(raw)
    text = '\n'.join(lines)
    names = set()
    dynamic = False
    for expression in EXPRESSION.findall(text):
        names.update(SECRET_DOT.findall(expression))
        names.update(SECRET_INDEX_LITERAL.findall(expression))
        if SECRET_INDEX_DYNAMIC.search(expression):
            dynamic = True
    return names - AUTOMATIC_SECRETS, dynamic


def declared_call_secrets(path: pathlib.Path):
    """Secrets declarados em on.workflow_call.secrets: (nome -> required)."""
    if not path.is_file():
        return None
    doc = load(path)
    if not isinstance(doc, dict):
        return None
    # PyYAML converte a chave `on:` em booleano True.
    triggers = doc.get('on', doc.get(True))
    if not isinstance(triggers, dict):
        return None
    call = triggers.get('workflow_call')
    if not isinstance(call, dict):
        return None
    declared = call.get('secrets')
    if not isinstance(declared, dict):
        return None
    return {
        name: bool((spec or {}).get('required', False)) if isinstance(spec, dict) else False
        for name, spec in declared.items()
    }


violations = []

for workflow in sorted(workflows.glob('*.yml')) + sorted(workflows.glob('*.yaml')):
    doc = load(workflow)
    if not isinstance(doc, dict):
        continue
    jobs = doc.get('jobs')
    if not isinstance(jobs, dict):
        continue

    for job_name, job in jobs.items():
        if not isinstance(job, dict):
            continue
        uses = job.get('uses')
        if not isinstance(uses, str):
            continue

        match = local_reusable_name(uses)
        if not match:
            continue  # cross-repo: fora do escopo (ver cabeçalho)

        target = workflows / match
        needed, dynamic = secrets_used_by(target)
        if not needed and not dynamic:
            continue  # reusable não usa secrets: nada a repassar

        passed = job.get('secrets')
        location = f'{workflow.relative_to(root)} job "{job_name}"'
        detail = ', '.join(sorted(needed)) or 'referência dinâmica'

        if passed == 'inherit':
            continue

        if isinstance(passed, dict):
            declared = declared_call_secrets(target)
            # UNIÃO deliberada: cobrar `required: true` do contrato E todo secret
            # efetivamente usado pelo reusable. Cobrar apenas `required: true`
            # deixava passar o defeito do run 30670232135 — um secret usado de
            # fato, declarado `required: false` (ou sem a chave), chegava vazio
            # com o guarda verde.
            required = {name for name, req in (declared or {}).items() if req}
            missing = sorted((required | needed) - set(passed))
            if missing:
                violations.append(
                    f'{location} mapeia secrets mas não passa: {", ".join(missing)}'
                )
            if dynamic:
                violations.append(
                    f'{location} usa referência dinâmica a secrets: exige `secrets: inherit`'
                )
            continue

        violations.append(
            f'{location} chama {match} (usa secrets: {detail}) sem repassar secrets'
        )

if violations:
    print('FALHA: reusable workflow receberia secrets vazios.', file=sys.stderr)
    print('', file=sys.stderr)
    for violation in violations:
        print(f'  {violation}', file=sys.stderr)
    print('', file=sys.stderr)
    print('Adicione `secrets: inherit` ao job que faz a chamada, ou mapeie cada', file=sys.stderr)
    print('secret exigido em um bloco `secrets:` desse mesmo job.', file=sys.stderr)
    raise SystemExit(1)

print('PASS: toda chamada de reusable com secrets repassa credenciais.')
PY
