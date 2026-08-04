#!/usr/bin/env bash
# Contrato de banco do clone: nenhum caminho remoto pode depender de `wp db
# export`/`wp db import`.
#
# Motivo: a Locaweb desabilita proc_open/proc_close de forma COMPILADA, e
# `-d disable_functions=` não relaxa isso (medido no host). Esses subcomandos
# shellam out via Process::run, então falham SEMPRE ali. Consequência prática: um
# clone com produção na origem ou no destino ficava sem backup utilizável — o
# artefato de que o rollback depende.
#
# Este teste é estrutural de propósito. As suítes de rollback e safety exercitam
# o comportamento com mocks, mas nenhuma delas reprovava quando o caminho voltava
# a `wp db export`: as mutações escapavam. Aqui o contrato fica travado.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE="${ROOT_DIR}/scripts/clone-environment.sh"

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

[ -f "$CLONE" ] || fail 'scripts/clone-environment.sh ainda não existe.'

# Os helpers precisam existir: são o único caminho que não shella out.
for required_helper in \
  remote_db_dump_snippet \
  remote_db_dump_to_stdout \
  remote_db_dump_tables_snippet \
  remote_db_client_snippet \
  remote_db_import_file_snippet \
  remote_db_import_stdin_command; do
  grep -q "^${required_helper}() {" "$CLONE" \
    || fail "helper ausente: ${required_helper}"
done

# Cada função que toca o banco remoto tem de usar um helper, não o wp-cli direto.
assert_uses_helper() {
  local function_name="$1"
  local helper="$2"
  local body

  body="$(awk -v fn="^${function_name}\\\\(\\\\) \\\\{" '
    $0 ~ fn { inside = 1 }
    inside { print }
    inside && /^}$/ { exit }
  ' "$CLONE")"

  [ -n "$body" ] || fail "função não encontrada: ${function_name}"

  printf '%s' "$body" | grep -q "$helper" \
    || fail "${function_name} não usa ${helper}"

  # A chamada direta ao wp-cli é o defeito: `db export`/`db import` fora dos
  # helpers significa um caminho que falha na Locaweb.
  # O padrão é literal: procura o texto `$wp_cli` no script, não o valor de uma
  # variável local.
  # shellcheck disable=SC2016
  if printf '%s' "$body" | grep -E '\$wp_cli[^\n]*db (export|import)' | grep -qv 'remote_db_'; then
    fail "${function_name} ainda chama wp db export/import diretamente"
  fi
}

assert_uses_helper prepare_target_backup remote_db_dump_snippet
assert_uses_helper export_source_db remote_db_dump_to_stdout
assert_uses_helper snapshot_users remote_db_dump_tables_snippet
assert_uses_helper restore_users remote_db_import_file_snippet
assert_uses_helper restore_options remote_db_import_file_snippet
assert_uses_helper import_db_to_target remote_db_import_stdin_command
assert_uses_helper rollback_target remote_db_import_stdin_command

# `wp db export`/`db import` só podem sobrar como FALLBACK dentro dos helpers,
# para hosts sem cliente mysql instalado. Contamos as ocorrências e travamos o
# número: qualquer nova aparição fora dos helpers quebra o teste.
# O padrão é literal: casa o texto do script, não expande variáveis locais.
# shellcheck disable=SC2016
direct_calls="$(grep -cE '\$wp_cli --path=\$\(printf .%q. "\$wp_root"\) db (export|import)' "$CLONE" || true)"
[ "$direct_calls" -eq 5 ] \
  || fail "esperadas 5 chamadas diretas (fallbacks nos helpers), encontradas ${direct_calls}"

# Todas as cinco têm de estar dentro de um helper, nunca no fluxo principal.
# O padrão do grep é literal: casa o texto do script, não expande variáveis.
# shellcheck disable=SC2016
direct_call_lines="$(grep -nE '\$wp_cli --path=\$\(printf .%q. "\$wp_root"\) db (export|import)' "$CLONE")"
while IFS=: read -r line_number _; do
  [ -n "$line_number" ] || continue
  enclosing="$(awk -v target="$line_number" '
    /^[a-z_]+\(\) \{/ { current = $1 }
    NR == target { print current; exit }
  ' "$CLONE")"
  case "$enclosing" in
    remote_db_*) ;;
    *) fail "chamada direta a wp db na linha ${line_number}, fora de um helper (em ${enclosing:-fluxo principal})" ;;
  esac
done <<< "$direct_call_lines"

# A senha do banco nunca pode ir por argv. `-p<senha>` vaza em `ps`; MYSQL_PWD não.
grep -qE '\-p"?\$' "$CLONE" \
  && fail 'senha do banco passada por -p (visível em ps); use MYSQL_PWD'

# Cada invocação de cliente de banco precisa da sua própria proteção. Contar o
# total de MYSQL_PWD no arquivo não serve: sobra ocorrência de outro caminho (por
# exemplo o Podman local) e a checagem passa mesmo com um helper desprotegido.
for db_helper in \
  remote_db_dump_snippet \
  remote_db_dump_to_stdout \
  remote_db_dump_tables_snippet \
  remote_db_import_file_snippet \
  remote_db_import_stdin_command; do
  helper_body="$(awk -v fn="^${db_helper}\\\\(\\\\) \\\\{" '
    $0 ~ fn { inside = 1 }
    inside { print }
    inside && /^}$/ { exit }
  ' "$CLONE")"
  [ -n "$helper_body" ] || fail "helper não encontrado ao checar credenciais: ${db_helper}"
  printf '%s' "$helper_body" | grep -q 'MYSQL_PWD=' \
    || fail "${db_helper} invoca cliente de banco sem MYSQL_PWD (senha iria para o argv)"
done

# Credenciais são lidas do wp-config pelo próprio WordPress: nenhum segredo novo
# entra no repositório ou nas Variables.
grep -q 'config get DB_PASSWORD' "$CLONE" \
  || fail 'senha não é lida por wp config get (introduziria segredo novo)'

# Flags obrigatórias no dump completo, medidas no host de produção.
dump_body="$(awk '/^remote_db_dump_snippet\(\) \{/,/^}$/' "$CLONE")"
for required_flag in --single-transaction --quick --no-tablespaces --routines --triggers --events; do
  printf '%s' "$dump_body" | grep -q -- "$required_flag" \
    || fail "dump do clone sem ${required_flag}"
done

# Flags que exigem RELOAD, privilégio que a conta não tem (erro 1227).
for forbidden_flag in --flush-logs --master-data --flush-privileges; do
  grep -q -- "$forbidden_flag" "$CLONE" \
    && fail "clone usa ${forbidden_flag}, que exige RELOAD e falha neste host"
done

printf 'PASS: o clone não depende de wp db export/import em nenhum caminho remoto.\n'
