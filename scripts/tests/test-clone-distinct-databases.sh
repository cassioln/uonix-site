#!/usr/bin/env bash
# A guarda de bancos distintos existe, valida a identidade REAL e reprova quando
# origem e destino compartilham banco sem revelar host/schema?
set -uo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${ROOT_DIR}/scripts/clone-environment.sh"
export UONIX_CLONE_LIBRARY_ONLY=1

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

[ -f "$CLONE_SCRIPT" ] || fail 'clone-environment.sh não encontrado'

# --- 1. A execução real e o dry-run chamam a guarda antes de qualquer escrita -
grep -q '^assert_distinct_databases()' "$CLONE_SCRIPT" \
  || fail 'assert_distinct_databases não existe'

mutation_body="$(awk '/^execute_clone_mutation\(\) \{/,/^}$/' "$CLONE_SCRIPT")"
[ -n "$mutation_body" ] || fail 'execute_clone_mutation não encontrada'

guard_offset="$(printf '%s' "$mutation_body" | grep -n 'assert_distinct_databases' | head -1 | cut -d: -f1)"
backup_offset="$(printf '%s' "$mutation_body" | grep -n 'prepare_target_backup' | head -1 | cut -d: -f1)"
[ -n "$guard_offset" ] \
  || fail 'execução real não chama assert_distinct_databases'
[ -n "$backup_offset" ] || fail 'prepare_target_backup não localizado'
[ "$guard_offset" -lt "$backup_offset" ] \
  || fail "guarda roda depois do backup (guarda ${guard_offset}, backup ${backup_offset})"

dry_body="$(awk '/^dry_run_clone\(\) \{/,/^}$/' "$CLONE_SCRIPT")"
printf '%s' "$dry_body" | grep -q 'assert_distinct_databases' \
  || fail 'dry-run não verifica bancos distintos'

# --- 2. database_identity real rejeita campos vazios e só devolve hash --------
verificar_identidade() {
  local host="$1"
  local schema="$2"

  (
    set +e
    # shellcheck source=scripts/clone-environment.sh
    # shellcheck disable=SC1090,SC1091
    . "$CLONE_SCRIPT" >/dev/null 2>&1

    # Chamadas indiretas pela implementação real de database_identity.
    # shellcheck disable=SC2329
    is_remote_env() { return 1; }
    # shellcheck disable=SC2329
    local_wp() {
      case "${3:-}" in
        DB_HOST) printf '%s\n' "$host" ;;
        DB_NAME) printf '%s\n' "$schema" ;;
        *) return 90 ;;
      esac
    }

    database_identity local
  )
}

identity_ok="$(verificar_identidade 'db.host' 'uonix_qa')" \
  || fail 'database_identity reprovou host/schema completos'
case "$identity_ok" in
  *[!0-9a-f]*|'') fail 'database_identity não devolveu digest hexadecimal' ;;
esac
[ "${#identity_ok}" -eq 64 ] \
  || fail "digest da identidade não tem 64 caracteres (${#identity_ok})"

for incomplete in \
  'sem-host|uonix_qa' \
  'db.host|sem-schema' \
  'sem-host|sem-schema'; do
  host="${incomplete%%|*}"
  schema="${incomplete#*|}"
  [ "$host" = sem-host ] && host=''
  [ "$schema" = sem-schema ] && schema=''
  if verificar_identidade "$host" "$schema" >/dev/null 2>&1; then
    fail "database_identity aceitou identidade incompleta (${incomplete})"
  fi
done

# --- 3. assert_distinct_databases compara os digests e falha fechado ----------
verificar_guarda() {
  local origem="$1"
  local destino="$2"
  (
    set +e
    # shellcheck source=scripts/clone-environment.sh
    # shellcheck disable=SC1090,SC1091
    . "$CLONE_SCRIPT" >/dev/null 2>&1

    # Chamadas indiretas pelo assert sourceado.
    # shellcheck disable=SC2329
    uonix_env_error() { printf 'ERRO: %s\n' "$1" >&2; }
    # shellcheck disable=SC2329
    log() { :; }
    # shellcheck disable=SC2329
    database_identity() {
      case "$1" in
        origem) printf '%s\n' "$origem" ;;
        destino) printf '%s\n' "$destino" ;;
      esac
    }

    assert_distinct_databases origem destino
  )
}

test_digest() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum | cut -d' ' -f1
  else
    shasum -a 256 | cut -d' ' -f1
  fi
}

hash_a="$(printf '%s' 'db.host|uonix_qa' | test_digest)"
hash_b="$(printf '%s' 'db.host|uonix_dev' | test_digest)"

if verificar_guarda "$hash_a" "$hash_a" >/dev/null 2>&1; then
  fail 'guarda aceitou origem e destino no MESMO banco'
fi
verificar_guarda "$hash_a" "$hash_b" >/dev/null 2>&1 \
  || fail 'guarda reprovou bancos legitimamente distintos'

# Falha ao identificar qualquer lado precisa abortar, não comparar lixo.
if verificar_guarda '' "$hash_b" >/dev/null 2>&1; then
  fail 'guarda aceitou falha de identidade na origem'
fi
if verificar_guarda "$hash_a" '' >/dev/null 2>&1; then
  fail 'guarda aceitou falha de identidade no destino'
fi

# --- 4. Value-blind: a mensagem não pode interpolar identidade/hash -----------
identity_body="$(awk '/^assert_distinct_databases\(\) \{/,/^}$/' "$CLONE_SCRIPT")"
identity_error_lines="$(printf '%s\n' "$identity_body" | grep 'uonix_env_error' || true)"
# Padrões literais: casam o TEXTO do script, não expandem variável aqui.
# shellcheck disable=SC2016
if printf '%s' "$identity_error_lines" | grep -qE '\$\{?(source_identity|target_identity)\}?'; then
  fail 'mensagem da guarda expõe a identidade/hash do banco'
fi

printf 'PASS: clone recusa banco idêntico, rejeita identidade incompleta e não revela valores.\n'
