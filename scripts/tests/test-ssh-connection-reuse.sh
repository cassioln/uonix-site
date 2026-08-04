#!/usr/bin/env bash
# Garante que os deploys reutilizam UMA conexão SSH em vez de abrir uma por passo.
#
# MOTIVAÇÃO: o job de DEV/QA abre 14 conexões SSH/rsync em sequência rápida. O
# firewall do HostGator (cPHulk/CSF) trata a rajada como abuso e passa a recusar
# a porta 22 NO MEIO do deploy — o preflight passa e um passo posterior morre com
# "Connection refused", deixando lock órfão no ambiente. Quatro runs falharam
# assim (30872950365, 30873957795, 30874352281, 30874827363), cada vez num passo
# diferente conforme o IP do runner era barrado mais cedo.
#
# Provado empiricamente contra o host real: 12 conexões seguidas SEM
# multiplexação eram barradas; COM ControlMaster/ControlPersist passaram 12/12,
# criando um único socket de controle.
#
# O guarda falha se a multiplexação for removida ou enfraquecida, e se um
# `ControlPath` longo puder estourar o limite do socket UNIX (o que desliga a
# multiplexação em silêncio, sem erro visível).
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

python3 - "$ROOT_DIR" <<'PY'
import pathlib
import re
import sys

try:
    import yaml
except ModuleNotFoundError:  # pragma: no cover - PyYAML existe no CI
    raise SystemExit('PyYAML indisponível: não é possível auditar a multiplexação SSH')

root = pathlib.Path(sys.argv[1])

# Workflows que abrem várias conexões ao MESMO host num único job.
TARGETS = (
    pathlib.Path('.github/workflows/_deploy-hostgator.yml'),
    pathlib.Path('.github/workflows/deploy-production.yml'),
)

failures: list[str] = []
checked = 0


def steps_of(document: dict):
    for job_name, job in (document.get('jobs') or {}).items():
        if not isinstance(job, dict):
            continue
        for step in job.get('steps') or []:
            if isinstance(step, dict):
                yield job_name, step


for relative in TARGETS:
    path = root / relative
    if not path.is_file():
        failures.append(f'{relative}: workflow ausente')
        continue

    document = yaml.safe_load(path.read_text(encoding='utf-8')) or {}
    all_run = '\n'.join(
        str(step.get('run') or '') for _job, step in steps_of(document)
    )

    # Quantas vezes o job efetivamente contata o host remoto.
    remote_calls = len(re.findall(r'^\s*(?:ssh|rsync)(?:\s|\\)', all_run, re.M))

    if remote_calls <= 2:
        # Um ou dois contatos não caracterizam rajada; multiplexar é opcional.
        continue

    checked += 1

    if 'ControlMaster' not in all_run:
        failures.append(
            f'{relative}: {remote_calls} conexões remotas sem ControlMaster '
            '(rajada volta a ser barrada pelo firewall do host)'
        )
        continue

    if not re.search(r'ControlMaster\s+auto', all_run):
        failures.append(f'{relative}: ControlMaster presente mas não está em modo auto')

    persist = re.search(r'ControlPersist\s+(\d+)', all_run)
    if not persist:
        failures.append(
            f'{relative}: ControlPersist ausente — o socket morre entre steps e '
            'cada step reabre a conexão'
        )
    elif int(persist.group(1)) < 60:
        failures.append(
            f'{relative}: ControlPersist={persist.group(1)}s é curto demais para '
            'cobrir backup e publicação'
        )

    control_path = re.search(r'ControlPath\s+(\S+)', all_run)
    if not control_path:
        failures.append(f'{relative}: ControlPath não declarado')
    else:
        value = control_path.group(1)
        # Um caminho longo estoura o limite do socket UNIX (~104 bytes no macOS,
        # 108 no Linux) e a multiplexação é desligada SEM erro visível. `%C` é o
        # hash curto de host/porta/usuário e evita esse modo de falha.
        if '%C' not in value and len(value.replace('$HOME', '/home/runner')) > 80:
            failures.append(
                f'{relative}: ControlPath longo sem %C pode estourar o limite do '
                'socket UNIX e desligar a multiplexação em silêncio'
            )

    # A multiplexação não pode substituir a verificação de host key.
    if re.search(r'StrictHostKeyChecking\s*=?\s*no', all_run):
        failures.append(
            f'{relative}: StrictHostKeyChecking=no — multiplexar não autoriza '
            'aceitar host key desconhecida'
        )

if failures:
    raise SystemExit('multiplexação SSH inadequada: ' + '; '.join(failures))

if 0 == checked:
    raise SystemExit(
        'nenhum workflow com rajada de conexões foi auditado: o guarda perdeu '
        'o alvo (renomeio de arquivo ou mudança de indentação?)'
    )

print(f'PASS: {checked} workflow(s) com múltiplas conexões reutilizam um único socket SSH.')
PY
