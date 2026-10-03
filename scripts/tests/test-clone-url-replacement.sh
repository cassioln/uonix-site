#!/usr/bin/env bash
# Contrato do search-replace de URLs: primeiro a forma JSON-escapada, depois a
# literal, sempre preservando GUID. Nunca usar host puro.
#
# O fixture usa o apex de produção e seu alias `www` porque um é substring do
# outro — ambos resolvem para production em mu-plugins/uonix-shared/environment.php.
# Com host puro, um segundo passe reprocessaria o resultado do primeiro e geraria
# `www.www.uonix.com.br`. O par anterior usava o ambiente remoto de
# desenvolvimento, retirado da topologia; a propriedade testada é a mesma.
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
  [ "$1" = prod ] || return 1
  printf '%s\n' 'https://www.uonix.com.br'
}
# shellcheck disable=SC2329
env_title() { printf '%s\n' 'Uônix'; }
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

escaped_source="$(json_escaped_url 'https://uonix.com.br')"
escaped_target="$(json_escaped_url 'https://www.uonix.com.br')"
[ "$escaped_source" = 'https:\/\/uonix.com.br' ] \
  || fail "escape da origem incorreto: ${escaped_source}"
[ "$escaped_target" = 'https:\/\/www.uonix.com.br' ] \
  || fail "escape do destino incorreto: ${escaped_target}"

set_target_identity prod 'https://uonix.com.br' >/dev/null \
  || fail 'set_target_identity falhou no cenário válido de origem substring do destino'

expected="${TMP_ROOT}/expected.tsv"
printf '%s\n' \
  $'prod\tsearch-replace\thttps:\\/\\/uonix.com.br\thttps:\\/\\/www.uonix.com.br\t--all-tables-with-prefix\t--skip-columns=guid\t--quiet' \
  $'prod\tsearch-replace\thttps://uonix.com.br\thttps://www.uonix.com.br\t--all-tables-with-prefix\t--skip-columns=guid\t--quiet' \
  $'prod\toption\tupdate\thome\thttps://www.uonix.com.br' \
  $'prod\toption\tupdate\tsiteurl\thttps://www.uonix.com.br' \
  $'prod\toption\tupdate\tblogname\tUônix' >"$expected"

cmp -s "$expected" "$CALLS" || {
  printf 'Esperado:\n' >&2
  sed 's/^/  /' "$expected" >&2
  printf 'Obtido:\n' >&2
  sed 's/^/  /' "$CALLS" >&2
  fail 'sequência/argumentos do replace de URLs divergiram'
}

# Invariante contra a corrupção por duplicação de prefixo: nenhum padrão é o host nu.
if cut -f3 "$CALLS" | grep -qx 'uonix\.com\.br'; then
  fail 'replace usa host puro; re-clone geraria www.www.uonix.com.br'
fi

printf 'PASS: URLs escapadas migram antes das literais, sem tocar GUID nem host puro.\n'
