#!/usr/bin/env bash
# A guarda de bancos distintos existe e REPROVA quando origem e destino
# compartilham banco?
#
# Por que importa: o nome do ambiente nao prova que o banco e outro. Na HostGator
# QA e DEV dividem conta, servidor e usuario SSH — se apontarem para o mesmo
# schema, o clone exporta e reimporta sobre si mesmo, destruindo o destino e
# deixando o backup como unica copia.
set -uo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${ROOT_DIR}/scripts/clone-environment.sh"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

[ -f "$CLONE_SCRIPT" ] || fail 'clone-environment.sh não encontrado'

# --- Caso 1: a guarda existe e é chamada antes de qualquer escrita ------------
grep -q '^assert_distinct_databases()' "$CLONE_SCRIPT" \
  || fail 'assert_distinct_databases não existe'

mutation_body="$(awk '/^execute_clone_mutation\(\) \{/,/^}$/' "$CLONE_SCRIPT")"
[ -n "$mutation_body" ] || fail 'execute_clone_mutation não encontrada'

guard_offset="$(printf '%s' "$mutation_body" | grep -n 'assert_distinct_databases' | head -1 | cut -d: -f1)"
backup_offset="$(printf '%s' "$mutation_body" | grep -n 'prepare_target_backup' | head -1 | cut -d: -f1)"
[ -n "$guard_offset" ] \
  || fail 'execute_clone_mutation não chama assert_distinct_databases (execução real ficaria sem guarda)'
[ -n "$backup_offset" ] || fail 'prepare_target_backup não localizado'
[ "$guard_offset" -lt "$backup_offset" ] \
  || fail "guarda roda depois do backup (guarda linha ${guard_offset}, backup linha ${backup_offset})"

# O dry-run também precisa avisar, senão o operador só descobre na execução.
dry_body="$(awk '/^dry_run_clone\(\) \{/,/^}$/' "$CLONE_SCRIPT")"
printf '%s' "$dry_body" | grep -q 'assert_distinct_databases' \
  || fail 'dry-run não verifica bancos distintos'

# --- Caso 2: comportamento real, com identidades controladas -----------------
# shellcheck source=../clone-environment.sh
verificar_comportamento() {
  local identidade_origem="$1"
  local identidade_destino="$2"

  (
    set +e
    # O caminho é resolvido em tempo de execução; o shellcheck não o segue.
    # shellcheck disable=SC1090,SC1091
    . "$CLONE_SCRIPT" >/dev/null 2>&1

    # Estes três substituem funções do script sourceado e são invocados por ele,
    # não diretamente aqui — daí o SC2329.
    # shellcheck disable=SC2329
    uonix_env_error() { printf 'ERRO: %s\n' "$1" >&2; }
    # shellcheck disable=SC2329
    log() { :; }
    # shellcheck disable=SC2329
    database_identity() {
      case "$1" in
        origem) printf '%s\n' "$identidade_origem" ;;
        destino) printf '%s\n' "$identidade_destino" ;;
      esac
    }

    assert_distinct_databases origem destino >/dev/null 2>&1
    printf '%s' "$?"
  )
}

status_iguais="$(verificar_comportamento 'db.host|uonix_wp' 'db.host|uonix_wp')"
[ "$status_iguais" != '0' ] \
  || fail 'guarda aceitou origem e destino no MESMO banco (o clone destruiria os dados)'

status_distintos="$(verificar_comportamento 'db.host|uonix_qa' 'db.host|uonix_dev')"
[ "$status_distintos" = '0' ] \
  || fail "guarda reprovou bancos legitimamente distintos (exit ${status_distintos})"

# Mesmo schema em hosts diferentes é válido; mesmo host com schema diferente também.
status_host_diferente="$(verificar_comportamento 'host-a|mesmo_schema' 'host-b|mesmo_schema')"
[ "$status_host_diferente" = '0' ] \
  || fail 'guarda reprovou mesmo schema em hosts distintos, que é um par válido'

# Identidade vazia não pode passar: sem evidência, aborta.
status_vazio="$(verificar_comportamento '|' '|')"
[ "$status_vazio" != '0' ] \
  || fail 'guarda aceitou identidade de banco vazia (sem evidência de que são distintos)'

# O caso acima não isola o guard de vazio: com '|' igual a '|', a checagem de
# igualdade também reprovaria, e remover o guard de vazio passaria sem ser visto.
# Estes dois casos deixam as identidades DIFERENTES, então só o guard de vazio
# pode reprovar — sem ele, o clone seguiria sem saber em que banco vai escrever.
status_origem_vazia="$(verificar_comportamento '|' 'db.host|uonix_dev')"
[ "$status_origem_vazia" != '0' ] \
  || fail 'guarda aceitou origem com identidade de banco vazia'

status_destino_vazio="$(verificar_comportamento 'db.host|uonix_qa' '|')"
[ "$status_destino_vazio" != '0' ] \
  || fail 'guarda aceitou destino com identidade de banco vazia'

printf 'PASS: clone recusa origem e destino no mesmo banco, antes de qualquer escrita.\n'
