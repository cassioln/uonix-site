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

printf 'PASS: conteúdo do dump exige marcador final exato em todos os caminhos.\n'
