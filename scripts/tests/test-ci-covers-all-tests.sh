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
import fnmatch
import pathlib
import re
import sys

try:
    import yaml
except ModuleNotFoundError:  # pragma: no cover - PyYAML faz parte do ambiente de CI
    raise SystemExit('PyYAML indisponível: não é possível auditar a cobertura de testes')

root = pathlib.Path(sys.argv[1])
workflows = root / '.github/workflows'

# Todo diretório do repositório que contém testes. Restringir a scripts/tests/
# deixava scripts/email-migration/test_prepare_passfiles.py fora do radar — 5
# testes que passavam localmente e que nenhum workflow executava.
TEST_DIRS = (
    pathlib.Path('scripts/tests'),
    pathlib.Path('scripts/email-migration'),
)

# Todo sufixo executável usado por testes neste repositório. Restringir a .sh/.php
# tornava o guarda cego a test-pagespeed-check.mjs, que é um teste real e órfão.
TEST_SUFFIXES = {'.sh', '.php', '.mjs', '.js', '.py'}
SUFFIX_ALT = 'sh|php|mjs|js|py'
DIRS_ALT = '|'.join(re.escape(str(d)) for d in TEST_DIRS)
REFERENCE = re.compile(rf'((?:{DIRS_ALT})/[A-Za-z0-9._/-]+\.(?:{SUFFIX_ALT}))')


def _is_test_file(path: pathlib.Path) -> bool:
    """Só arquivos cujo nome os declara como teste entram na exigência.

    Um módulo de produção que convive no mesmo diretório (prepare_passfiles.py)
    não é um teste e não deve ser exigido no CI como se fosse.

    A convenção é `test_*`/`test-*` no início do nome. Sufixos como
    `foo_test.py` ou `foo.spec.js` escapariam desta checagem, então o guarda
    também os reconhece — melhor cobrar CI de um arquivo que não é teste (erro
    visível, resolvido renomeando) do que deixar um teste real fora do CI (erro
    silencioso, que foi exatamente TG-1/TG-3).
    """
    stem = path.stem
    return (
        path.name.startswith(('test_', 'test-'))
        or stem.endswith(('_test', '-test'))
        or '.spec' in path.name
        or '.test' in path.name
    )


# rglob, não iterdir: um teste em <dir>/<sub>/ escaparia de varredura rasa.
present = set()
for test_dir in TEST_DIRS:
    absolute = root / test_dir
    if not absolute.is_dir():
        continue
    present.update(
        str(path.relative_to(root)) for path in absolute.rglob('*')
        if path.is_file() and path.suffix in TEST_SUFFIXES and _is_test_file(path)
    )


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


def _covered_by_discovery(command: str, candidate: str) -> bool:
    """Reconhece cobertura por descoberta automática de testes.

    `unittest discover --start-directory <dir> --pattern 'test_*.py'` executa o
    arquivo sem citá-lo pelo nome, então uma busca por referência literal o
    acusaria de órfão. Só conta quando o diretório de descoberta é ancestral do
    candidato E o padrão casa com o nome do arquivo.
    """
    path = pathlib.Path(candidate)
    for match in DISCOVERY.finditer(command):
        start = match.group('dir').strip('\'"')
        pattern = (match.group('pattern') or 'test*.py').strip('\'"')
        try:
            path.relative_to(pathlib.Path(start))
        except ValueError:
            continue
        if fnmatch.fnmatch(path.name, pattern):
            return True
    return False


DISCOVERY = re.compile(
    r'unittest\s+discover[^\n]*?'
    r'(?:--start-directory|-s)[=\s]+(?P<dir>[^\s]+)'
    r'(?:[^\n]*?(?:--pattern|-p)[=\s]+(?P<pattern>[^\s]+))?'
)


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
            command = str(step.get('run') or '')
            names = REFERENCE.findall(command)
            names += REFERENCE.findall(str(step.get('uses') or ''))
            discovered = [c for c in present if _covered_by_discovery(command, c)]
            names += discovered
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
