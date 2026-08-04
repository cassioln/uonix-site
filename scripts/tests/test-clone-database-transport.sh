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

# Invocar o cliente não basta: o helper precisa mesmo LER ou ESCREVER o arquivo.
# Um redirecionamento para /dev/null passaria em qualquer teste que só conte
# chamadas, e o clone reportaria sucesso sem restaurar nem salvar nada. Duas
# mutações escaparam exatamente assim antes desta checagem existir.
assert_moves_data() {
  local db_helper="$1"
  local expected_redirection="$2"
  local body

  body="$(awk -v fn="^${db_helper}\\\\(\\\\) \\\\{" '
    $0 ~ fn { inside = 1 }
    inside { print }
    inside && /^}$/ { exit }
  ' "$CLONE")"

  printf '%s' "$body" | grep -qF "$expected_redirection" \
    || fail "${db_helper} não move dados: falta ${expected_redirection}"

  # O destino do dump/import não pode ser /dev/null. Só a linha que carrega o
  # redirecionamento esperado é inspecionada: os fallbacks legitimamente enviam
  # a saída informativa do wp-cli para /dev/null, e olhar o helper inteiro
  # produzia falso positivo.
  # `if` em vez de `&& fail`: sob `set -e`, grep sem match mataria a função sem
  # imprimir o motivo.
  if printf '%s' "$body" | grep -F "$expected_redirection" | grep -qE '(<|>) ?/dev/null'; then
    fail "${db_helper} aponta o dado para /dev/null"
  fi
}

# Os padrões abaixo são literais: casam o TEXTO do script, não expandem nada.
# shellcheck disable=SC2016
{
  # O dump completo grava no destino nomeado, não em lugar nenhum.
  assert_moves_data remote_db_dump_snippet '> $(printf'
  # O dump de tabelas idem, via expressão parametrizada.
  assert_moves_data remote_db_dump_tables_snippet '> ${sql_file_expression}'
  # O import de arquivo tem de alimentar o cliente pelo stdin.
  assert_moves_data remote_db_import_file_snippet '< ${sql_file_expression}'
}

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

# A ORDEM do rollback importa: banco antes dos arquivos. Restaurar arquivos sobre
# um schema divergente passa num smoke de arquivos e mente sobre o conteúdo — o
# pior resultado possível, porque parece sucesso.
#
# Cada caminho é analisado no SEU PRÓPRIO trecho. Buscar padrões no corpo inteiro
# de rollback_target não funciona: `tar -xzf` aparece nos dois caminhos, então um
# `tail -1` casava a linha do caminho local ao avaliar o remoto, e a inversão do
# remoto passava sem ser vista.
rollback_body="$(awk '/^rollback_target\(\) \{/,/^}$/' "$CLONE")"
[ -n "$rollback_body" ] || fail 'rollback_target não encontrado'

# O `else` separa o trecho remoto (antes) do local (depois).
rollback_remote="$(printf '%s' "$rollback_body" | awk '/^  else$/ {exit} {print}')"
rollback_local="$(printf '%s' "$rollback_body" | awk 'found {print} /^  else$/ {found = 1}')"
[ -n "$rollback_remote" ] && [ -n "$rollback_local" ] \
  || fail 'não foi possível separar os caminhos remoto e local do rollback'

offset_of() {
  printf '%s' "$1" | grep -n "$2" | head -1 | cut -d: -f1
}

# São TRÊS etapas, nesta ordem: extrair para staging, restaurar o banco, trocar os
# arquivos. Extrair primeiro faz um archive corrompido abortar com o destino
# intacto e o banco ainda não mexido. Restaurar o banco antes da TROCA impede que
# os arquivos cheguem sobre um schema divergente.
assert_rollback_order() {
  local path_label="$1"
  local path_body="$2"
  local import_pattern="$3"
  local extract_offset import_offset swap_offset

  extract_offset="$(offset_of "$path_body" 'tar -xzf')"
  import_offset="$(offset_of "$path_body" "$import_pattern")"
  swap_offset="$(offset_of "$path_body" 'mv -- ')"

  [ -n "$extract_offset" ] && [ -n "$import_offset" ] && [ -n "$swap_offset" ] \
    || fail "rollback ${path_label}: extração, import ou troca não localizados"
  [ "$extract_offset" -lt "$import_offset" ] \
    || fail "rollback ${path_label} restaura o banco antes de extrair (archive corrompido deixaria schema novo com arquivos antigos)"
  [ "$import_offset" -lt "$swap_offset" ] \
    || fail "rollback ${path_label} troca os arquivos antes de restaurar o banco"
}

assert_rollback_order remoto "$rollback_remote" 'gzip -dc'
assert_rollback_order local "$rollback_local" 'local_db_import'

# E nenhum dos dois caminhos pode apagar antes de ter o substituto pronto: uma
# queda no meio deixava o destino sem arquivos E sem segunda chance. Em ambos, o
# `rm -rf` tem de vir depois de o staging existir.
for rollback_path_label in remoto local; do
  case "$rollback_path_label" in
    remoto) path_body="$rollback_remote" ;;
    local)  path_body="$rollback_local" ;;
  esac
  staging_offset="$(printf '%s' "$path_body" | grep -n 'mktemp -d' | head -1 | cut -d: -f1)"
  remove_offset="$(printf '%s' "$path_body" | grep -n 'rm -rf -- ' | head -1 | cut -d: -f1)"
  [ -n "$staging_offset" ] \
    || fail "rollback ${rollback_path_label} não cria staging (apagaria antes de extrair)"
  if [ -n "$remove_offset" ] && [ "$remove_offset" -lt "$staging_offset" ]; then
    fail "rollback ${rollback_path_label} apaga antes de preparar o staging"
  fi
done

printf 'PASS: o clone não depende de wp db export/import em nenhum caminho remoto.\n'
