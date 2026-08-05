#!/usr/bin/env bash
# Prova que a validação de dump olha o SQL completo, não só o envelope gzip.
# Um gzip íntegro pode conter dump truncado, lixo ou um marcador no meio.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${ROOT_DIR}/scripts/clone-environment.sh"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/uonix-dump-content.XXXXXX")"
trap 'rm -rf -- "$TMP_ROOT"' EXIT

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

# shellcheck source=scripts/clone-environment.sh
export UONIX_CLONE_LIBRARY_ONLY=1
# shellcheck disable=SC1090,SC1091
. "$CLONE_SCRIPT" >/dev/null 2>&1

# Chamadas indiretas pelo helper sourceado.
# shellcheck disable=SC2329
uonix_env_error() { :; }
# shellcheck disable=SC2329
log() { :; }

make_gzip() {
  local name="$1"
  local body="$2"
  printf '%b' "$body" | gzip -c >"${TMP_ROOT}/${name}.sql.gz"
}

expect_pass() {
  local name="$1"
  assert_dump_content_complete "${TMP_ROOT}/${name}.sql.gz" "$name" \
    || fail "dump legítimo '${name}' foi reprovado"
}

expect_fail() {
  local name="$1"
  if assert_dump_content_complete "${TMP_ROOT}/${name}.sql.gz" "$name"; then
    fail "dump inválido '${name}' foi aceito"
  fi
}

# O marcador pode ter data (padrão) ou não (--skip-dump-date). Espaço em branco
# após o marcador é legítimo; ele ainda deve ser a última linha não vazia.
make_gzip completo_data '-- MySQL dump 10.13\nCREATE TABLE wp_options (id int);\n-- Dump completed on 2026-08-04 20:00:00\n'
make_gzip completo_sem_data '-- MariaDB dump 10.19\nCREATE TABLE wp_options (id int);\n-- Dump completed\n\n   \n'
expect_pass completo_data
expect_pass completo_sem_data

# Um dump vazio é válido para a ferramenta. A completude é dada pelo marcador,
# não por uma contagem arbitrária de tabelas; o preflight do clone valida o
# WordPress separadamente.
make_gzip vazio_legitimo '-- MySQL dump 10.13\n-- Dump completed on 2026-08-04 20:00:00\n'
expect_pass vazio_legitimo

# Casos que gzip -t aprova, mas que não podem virar backup/importação.
make_gzip truncado '-- MySQL dump 10.13\nCREATE TABLE wp_options (id int);\nINSERT INTO wp_options VALUES (1);\n'
make_gzip so_cabecalho '-- MySQL dump 10.13\n-- Host: db.example\n'
make_gzip marcador_no_meio '-- MySQL dump 10.13\n-- Dump completed on 2026-08-04 20:00:00\nLIXO APÓS O MARCADOR\n'
make_gzip marcador_falso 'prefixo -- Dump completed on 2026-08-04 20:00:00\n'
make_gzip lixo 'isto não é SQL\n'

for invalid in truncado so_cabecalho marcador_no_meio marcador_falso lixo; do
  gzip -t "${TMP_ROOT}/${invalid}.sql.gz" \
    || fail "fixture '${invalid}' não tem envelope gzip íntegro"
  expect_fail "$invalid"
done

# Envelope corrompido também precisa falhar.
printf 'não é gzip' >"${TMP_ROOT}/corrompido.sql.gz"
expect_fail corrompido

# Dump legítimo com MUITAS linhas em branco no fim: `tail -n 5` era uma janela
# arbitrária e empurrava o marcador para fora, reprovando dump bom (falso
# negativo = clone abortado sem motivo).
{
  printf -- '-- MariaDB dump\n'
  # SQL literal: os backticks são do MySQL, não expansão de shell.
  # shellcheck disable=SC2016
  printf 'CREATE TABLE `x` (`id` int);\n'
  printf -- '-- Dump completed on 2026-08-05  1:00:00\n'
  printf '\n\n\n\n\n\n\n\n'
} | gzip -c >"${TMP_ROOT}/completo_muitas_linhas_vazias.sql.gz"
expect_pass completo_muitas_linhas_vazias

# Terminador CRLF (host Windows / transferência ASCII): o \r residual não pode
# transformar dump bom em reprovado. Este caso usa --skip-dump-date, onde o
# marcador é a linha INTEIRA: sem normalizar o \r, o `case` não tem glob final
# para absorvê-lo e o dump legítimo seria reprovado.
{
  printf -- '-- MariaDB dump\r\n'
  # shellcheck disable=SC2016
  printf 'CREATE TABLE `x` (`id` int);\r\n'
  printf -- '-- Dump completed\r\n'
} | gzip -c >"${TMP_ROOT}/completo_crlf.sql.gz"
expect_pass completo_crlf

# Mesma forma, com data: aqui o glob absorveria o \r, então este caso sozinho
# NÃO provaria a normalização — por isso o de cima existe.
{
  printf -- '-- MariaDB dump\r\n'
  printf -- '-- Dump completed on 2026-08-05  1:00:00\r\n'
} | gzip -c >"${TMP_ROOT}/completo_crlf_com_data.sql.gz"
expect_pass completo_crlf_com_data

# O backup de QA/DEV fica no host remoto. O snippet enviado por SSH precisa ter
# o MESMO contrato; testar só o helper local deixaria o caminho real sem prova.
expect_remote_pass() {
  local name="$1"
  local snippet
  snippet="$(remote_dump_content_check_snippet "${TMP_ROOT}/${name}.sql.gz")" \
    || fail 'não foi possível gerar o snippet remoto de validação'
  bash -c "set -euo pipefail
${snippet}" || fail "snippet remoto reprovou dump legítimo '${name}'"
}

expect_remote_fail() {
  local name="$1"
  local snippet
  snippet="$(remote_dump_content_check_snippet "${TMP_ROOT}/${name}.sql.gz")" \
    || fail 'não foi possível gerar o snippet remoto de validação'
  if bash -c "set -euo pipefail
${snippet}" >/dev/null 2>&1; then
    fail "snippet remoto aceitou dump inválido '${name}'"
  fi
}

expect_remote_pass completo_data
expect_remote_pass completo_sem_data
expect_remote_pass vazio_legitimo
for invalid in truncado so_cabecalho marcador_no_meio marcador_falso lixo corrompido; do
  expect_remote_fail "$invalid"
done

# O helper correto precisa estar nos pontos que importam, não apenas existir.
function_body() {
  local name="$1"
  awk -v fn="$name" '
    $0 == fn "() {" { inside = 1 }
    inside { print }
    inside && /^}$/ { exit }
  ' "$CLONE_SCRIPT" | grep -v '^[[:space:]]*#'
}

prepare_body="$(function_body prepare_target_backup)"
export_body="$(function_body export_source_db)"
rollback_body="$(function_body rollback_target)"

[ "$(printf '%s\n' "$prepare_body" | grep -c 'remote_dump_content_check_snippet')" -eq 1 ] \
  || fail 'backup remoto do destino não valida o marcador final do SQL'
[ "$(printf '%s\n' "$prepare_body" | grep -c 'assert_dump_content_complete')" -eq 1 ] \
  || fail 'backup local do destino não valida o marcador final do SQL'
[ "$(printf '%s\n' "$export_body" | grep -c 'assert_dump_content_complete')" -eq 1 ] \
  || fail 'dump da origem a ser importado não valida o marcador final do SQL'
[ "$(printf '%s\n' "$rollback_body" | grep -c 'remote_dump_content_check_snippet')" -eq 1 ] \
  || fail 'rollback remoto não revalida o marcador final do SQL'
[ "$(printf '%s\n' "$rollback_body" | grep -c 'assert_dump_content_complete')" -eq 1 ] \
  || fail 'rollback local não revalida o marcador final do SQL'

# O snippet remoto precisa DERIVAR o marcador do helper, não repetir a string.
# Sem isso, mudar dump_completion_marker deixaria os dois lados divergentes com
# o teste ainda verde, porque as fixtures usam o marcador antigo nos dois casos.
snippet_body="$(function_body remote_dump_content_check_snippet)"
printf '%s\n' "$snippet_body" | grep -q 'dump_completion_marker' \
  || fail 'snippet remoto hard-codeia o marcador em vez de usar dump_completion_marker'
printf '%s\n' "$snippet_body" | grep -q "'-- Dump completed'" \
  && fail 'snippet remoto ainda repete o marcador como literal'

# Prova comportamental da ligação: trocando o helper, o snippet acompanha.
generated_with_custom_marker="$(
  # Override deliberado: o snippet chama este helper indiretamente.
  # shellcheck disable=SC2329
  dump_completion_marker() { printf '%s' '-- MARCADOR_TROCADO'; }
  remote_dump_content_check_snippet '/tmp/x.sql.gz'
)" || fail 'não foi possível gerar o snippet com marcador substituído'
printf '%s\n' "$generated_with_custom_marker" | grep -q 'MARCADOR_TROCADO' \
  || fail 'snippet remoto não acompanha mudança em dump_completion_marker'

# Nenhum dos dois caminhos pode voltar à janela fixa de linhas.
for fn in assert_dump_content_complete remote_dump_content_check_snippet; do
  if printf '%s\n' "$(function_body "$fn")" | grep -qE 'tail -n [2-9]'; then
    fail "${fn} usa janela fixa de linhas; dump com muitas linhas vazias reprovaria"
  fi
done

printf 'PASS: conteúdo do dump exige marcador final exato em todos os caminhos.\n'
