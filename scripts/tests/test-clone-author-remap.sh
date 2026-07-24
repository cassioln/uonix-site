#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="$ROOT_DIR/scripts/clone-environment.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

export UONIX_CLONE_LIBRARY_ONLY=1
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"

PRESERVE_DESTINATION_USERS=1
log() { :; }
local_db_prefix() { printf 'wp_\n'; }

SOURCE_MAP_PAYLOAD=$'5\t616C696365\n7\t626F62\n9\t\n'
LOOKUP_FAILURE_HEX=''
UPDATE_QUERY_LOG="$TMP_DIR/update-query.log"
LOOKUP_QUERY_LOG="$TMP_DIR/lookup-query.log"
: > "$UPDATE_QUERY_LOG"
: > "$LOOKUP_QUERY_LOG"

local_wp() {
  local query="${3:-}"
  case " $* " in
    *' db query SELECT DISTINCT '* )
      printf '%s' "$SOURCE_MAP_PAYLOAD"
      ;;
    *' db query SELECT ID FROM '* )
      printf '%s\n' "$query" >> "$LOOKUP_QUERY_LOG"
      case "$query" in
        *"HEX(user_login) = '616C696365'"*)
          [ "$LOOKUP_FAILURE_HEX" = '616C696365' ] && return 81
          printf '12\n'
          ;;
        *"HEX(user_login) = '626F62'"*)
          [ "$LOOKUP_FAILURE_HEX" = '626F62' ] && return 82
          printf '5\n'
          ;;
        *) return 83 ;;
      esac
      ;;
    *' user list --role=administrator '* )
      printf '99\n'
      ;;
    *)
      printf 'unexpected local_wp call: %s\n' "$*" >&2
      return 84
      ;;
  esac
}

local_db_query() {
  printf '%s\n' "$1" >> "$UPDATE_QUERY_LOG"
}

author_map="$TMP_DIR/source-authors.tsv"
if ! export_source_author_map local "$author_map"; then
  fail 'export_source_author_map falhou no caso canônico'
fi
[ "$(<"$author_map")" = "${SOURCE_MAP_PAYLOAD%$'\n'}" ] || fail 'mapa de autores não preservou ID e HEX(user_login)'
[ "$(uonix_transport_file_mode "$author_map")" = 600 ] || fail 'mapa de autores não ficou privado 0600'

if ! remap_missing_authors local "$author_map"; then
  fail 'remap_missing_authors rejeitou mapa canônico'
fi
expected_query='UPDATE wp_posts SET post_author = CASE post_author WHEN 5 THEN 12 WHEN 7 THEN 5 WHEN 9 THEN 99 ELSE 99 END WHERE post_author IN (5,7,9)'
[ "$(wc -l < "$UPDATE_QUERY_LOG" | tr -d ' ')" -eq 1 ] || fail 'remapeamento precisa executar um único UPDATE atômico'
[ "$(<"$UPDATE_QUERY_LOG")" = "$expected_query" ] || fail "query de remapeamento inesperada: $(<"$UPDATE_QUERY_LOG")"

invalid_map="$TMP_DIR/invalid-authors.tsv"
printf '5\tNOT-HEX\n' > "$invalid_map"
chmod 600 "$invalid_map"
: > "$UPDATE_QUERY_LOG"
if remap_missing_authors local "$invalid_map" >/dev/null 2>&1; then
  fail 'mapa com login não hexadecimal foi aceito'
fi
[ ! -s "$UPDATE_QUERY_LOG" ] || fail 'mapa inválido iniciou mutação'

LOOKUP_FAILURE_HEX='626F62'
: > "$UPDATE_QUERY_LOG"
if remap_missing_authors local "$author_map" >/dev/null 2>&1; then
  fail 'falha de lookup do destino foi mascarada'
else
  status=$?
fi
[ "$status" -eq 82 ] || fail "status do lookup foi perdido: $status"
[ ! -s "$UPDATE_QUERY_LOG" ] || fail 'falha de lookup iniciou mutação parcial'

PRESERVE_DESTINATION_USERS=0
LOOKUP_FAILURE_HEX=''
: > "$UPDATE_QUERY_LOG"
if ! remap_missing_authors local "$invalid_map"; then
  fail 'replace-users deveria ignorar o mapa de preservação'
fi
[ ! -s "$UPDATE_QUERY_LOG" ] || fail 'replace-users executou remapeamento indevido'

printf 'PASS: autores preservados são remapeados por login sem colisão de IDs.\n'
