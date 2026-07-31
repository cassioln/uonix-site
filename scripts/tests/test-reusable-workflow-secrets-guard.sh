#!/usr/bin/env bash
# Testa o guarda test-reusable-workflow-secrets.sh contra fixtures sintéticas.
#
# MOTIVAÇÃO: o guarda passou por 3 rodadas de revisão independente e cada uma
# encontrou um defeito nele — dois PASS falsos e um falso positivo. O padrão é
# claro: um guarda sem testes próprios é só mais código não verificado.
#
# Estes casos fixam os comportamentos que quase entraram errados no repositório:
#   1. secret usado e NÃO mapeado deve ser cobrado          (PASS falso, rodada 2)
#   2. GITHUB_TOKEN é automático e NÃO deve ser exigido     (falso positivo, rodada 3)
#   3. mapeamento completo deve passar                      (controle positivo)
#   4. nome citado em comentário YAML não é uso real        (falso positivo, rodada 3)
#
# Sem estes casos, o CI do repositório nunca exercitaria os caminhos — nenhum
# reusable local usa GITHUB_TOKEN hoje, exatamente o mesmo padrão de
# invisibilidade que deixou o defeito original passar.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GUARD="$ROOT_DIR/scripts/tests/test-reusable-workflow-secrets.sh"

[ -f "$GUARD" ] || { echo "FALHA: guarda não encontrado em $GUARD" >&2; exit 1; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0

# Monta um caso: $1 = conteúdo do reusable, $2 = conteúdo do chamador.
build_case() {
  rm -rf "$WORK/case"
  mkdir -p "$WORK/case/.github/workflows" "$WORK/case/scripts/tests"
  cp "$GUARD" "$WORK/case/scripts/tests/"
  printf '%s\n' "$1" > "$WORK/case/.github/workflows/_reuse.yml"
  printf '%s\n' "$2" > "$WORK/case/.github/workflows/caller.yml"
}

# Roda o guarda no caso montado e compara com o esperado.
# $1 = descrição, $2 = exit code esperado
expect() {
  local description="$1" expected="$2" actual
  bash "$WORK/case/scripts/tests/test-reusable-workflow-secrets.sh" >/dev/null 2>&1
  actual=$?
  if [ "$actual" -eq "$expected" ]; then
    printf '  ok    %s\n' "$description"
  else
    printf '  FALHA %s (esperado exit %s, obtido %s)\n' "$description" "$expected" "$actual" >&2
    failures=$((failures + 1))
  fi
}

# shellcheck disable=SC2016  # ${{ }} deve ficar literal na fixture YAML
REUSE_REAL_SECRET='name: R
on:
  workflow_call:
    secrets:
      OPCIONAL:
        required: false
jobs:
  j:
    runs-on: ubuntu-latest
    steps:
      - run: echo usa
        env:
          V: ${{ secrets.SEGREDO_REAL }}'

CALLER_NO_SECRETS='name: C
on:
  workflow_dispatch:
jobs:
  call:
    uses: ./.github/workflows/_reuse.yml'

CALLER_OTHER_SECRET='name: C
on:
  workflow_dispatch:
jobs:
  call:
    uses: ./.github/workflows/_reuse.yml
    secrets:
      OUTRO: x'

# shellcheck disable=SC2016  # ${{ }} deve ficar literal na fixture YAML
CALLER_MAPS_IT='name: C
on:
  workflow_dispatch:
jobs:
  call:
    uses: ./.github/workflows/_reuse.yml
    secrets:
      SEGREDO_REAL: ${{ secrets.SEGREDO_REAL }}'

CALLER_INHERIT='name: C
on:
  workflow_dispatch:
jobs:
  call:
    uses: ./.github/workflows/_reuse.yml
    secrets: inherit'

# shellcheck disable=SC2016  # ${{ }} deve ficar literal na fixture YAML
REUSE_ONLY_GITHUB_TOKEN='name: R
on:
  workflow_call:
jobs:
  j:
    runs-on: ubuntu-latest
    steps:
      - run: gh api /repos/x/y
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}'

# shellcheck disable=SC2016  # ${{ }} deve ficar literal na fixture YAML
REUSE_SECRET_IN_COMMENT='name: R
on:
  workflow_call:
jobs:
  j:
    runs-on: ubuntu-latest
    steps:
      # antes usava ${{ secrets.LEGADO_TOKEN }} - removido
      - run: echo nada'

REUSE_NO_SECRETS='name: R
on:
  workflow_call:
jobs:
  j:
    runs-on: ubuntu-latest
    steps:
      - run: echo nada'

echo 'Testando o guarda de repasse de secrets:'

# 1. Secret real usado, chamador sem bloco secrets: deve ACUSAR.
build_case "$REUSE_REAL_SECRET" "$CALLER_NO_SECRETS"
expect 'secret usado, chamador sem secrets -> acusa' 1

# 2. Secret real usado, chamador mapeia OUTRO secret: deve ACUSAR.
#    Este é o PASS falso da rodada 2 (SEGREDO_REAL é required:false).
build_case "$REUSE_REAL_SECRET" "$CALLER_OTHER_SECRET"
expect 'secret required:false usado e nao mapeado -> acusa' 1

# 3. Chamador mapeia o secret correto: deve PASSAR.
build_case "$REUSE_REAL_SECRET" "$CALLER_MAPS_IT"
expect 'mapeamento completo -> passa' 0

# 4. Chamador usa inherit: deve PASSAR.
build_case "$REUSE_REAL_SECRET" "$CALLER_INHERIT"
expect 'secrets inherit -> passa' 0

# 5. Reusable usa APENAS GITHUB_TOKEN (automático): deve PASSAR.
#    Este é o falso positivo da rodada 3.
build_case "$REUSE_ONLY_GITHUB_TOKEN" "$CALLER_NO_SECRETS"
expect 'GITHUB_TOKEN e automatico -> passa' 0

# 6. Nome de secret apenas em comentário YAML: deve PASSAR.
build_case "$REUSE_SECRET_IN_COMMENT" "$CALLER_NO_SECRETS"
expect 'secret citado em comentario -> passa' 0

# 7. Reusable sem secrets nenhum: deve PASSAR.
build_case "$REUSE_NO_SECRETS" "$CALLER_NO_SECRETS"
expect 'reusable sem secrets -> passa' 0

# 8. Travessia de path com secret usado e sem repasse: deve ACUSAR.
build_case "$REUSE_REAL_SECRET" 'name: C
on:
  workflow_dispatch:
jobs:
  call:
    uses: ./.github/workflows/../workflows/_reuse.yml'
expect 'travessia de path -> acusa' 1

if [ "$failures" -ne 0 ]; then
  printf 'FALHA: %s caso(s) do guarda com comportamento inesperado.\n' "$failures" >&2
  exit 1
fi

echo 'PASS: 8 casos do guarda de repasse de secrets com comportamento correto.'
