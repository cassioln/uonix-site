#!/usr/bin/env bash
# Contrato do search-replace de URLs: primeiro a forma JSON-escapada, depois a
# literal, sempre preservando GUID. Nunca usar host puro — QA é substring de DEV.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${ROOT_DIR}/scripts/clone-environment.sh"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/uonix-url-replace.XXXXXX")"
CALLS="${TMP_ROOT}/calls.tsv"
trap 'rm -rf -- "$TMP_ROOT"' EXIT

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

export UONIX_CLONE_LIBRARY_ONLY=1
# shellcheck source=scripts/clone-environment.sh
# shellcheck disable=SC1090,SC1091
. "$CLONE_SCRIPT" >/dev/null 2>&1

# Chamadas indiretas pelo código sourceado.
# shellcheck disable=SC2329
log() { :; }
# shellcheck disable=SC2329
env_url() {
  [ "$1" = dev ] || return 1
  printf '%s\n' 'https://test.uonix.ksio.dev'
}
# shellcheck disable=SC2329
env_title() { printf '%s\n' 'Uonix DEV'; }
# shellcheck disable=SC2329
wp_exec() {
  printf '%s' "$1"
  shift
  while [ "$#" -gt 0 ]; do
    printf '\t%s' "$1"
    shift
  done
  printf '\n'
} >>"$CALLS"

escaped_source="$(json_escaped_url 'https://uonix.ksio.dev')"
escaped_target="$(json_escaped_url 'https://test.uonix.ksio.dev')"
[ "$escaped_source" = 'https:\/\/uonix.ksio.dev' ] \
  || fail "escape da origem incorreto: ${escaped_source}"
[ "$escaped_target" = 'https:\/\/test.uonix.ksio.dev' ] \
  || fail "escape do destino incorreto: ${escaped_target}"

set_target_identity dev 'https://uonix.ksio.dev' >/dev/null \
  || fail 'set_target_identity falhou no cenário válido QA→DEV'

expected="${TMP_ROOT}/expected.tsv"
printf '%s\n' \
  $'dev\tsearch-replace\thttps:\\/\\/uonix.ksio.dev\thttps:\\/\\/test.uonix.ksio.dev\t--all-tables-with-prefix\t--skip-columns=guid\t--quiet' \
  $'dev\tsearch-replace\thttps://uonix.ksio.dev\thttps://test.uonix.ksio.dev\t--all-tables-with-prefix\t--skip-columns=guid\t--quiet' \
  $'dev\toption\tupdate\thome\thttps://test.uonix.ksio.dev' \
  $'dev\toption\tupdate\tsiteurl\thttps://test.uonix.ksio.dev' \
  $'dev\toption\tupdate\tblogname\tUonix DEV' >"$expected"

cmp -s "$expected" "$CALLS" || {
  printf 'Esperado:\n' >&2
  sed 's/^/  /' "$expected" >&2
  printf 'Obtido:\n' >&2
  sed 's/^/  /' "$CALLS" >&2
  fail 'sequência/argumentos do replace de URLs divergiram'
}

# Invariante contra a corrupção test.test: nenhum padrão é o host nu.
if cut -f3 "$CALLS" | grep -qx 'uonix\.ksio\.dev'; then
  fail 'replace usa host puro; re-clone geraria test.test.uonix.ksio.dev'
fi

printf 'PASS: URLs escapadas migram antes das literais, sem tocar GUID nem host puro.\n'
