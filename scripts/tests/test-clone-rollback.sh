#!/usr/bin/env bash
# Mocks e globais abaixo são consumidos indiretamente pelo script sourceado.
# shellcheck disable=SC1091,SC2034,SC2329
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${ROOT_DIR}/scripts/clone-environment.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

export PRODUCTION_URL='https://site.uonix.com.br'
export QA_URL='https://uonix.ksio.dev'
export DEVELOPMENT_URL='https://test.uonix.ksio.dev'
export LOCAWEB_ACCOUNT_ROOT='/synthetic/locaweb'
export HOSTGATOR_QA_ROOT='/synthetic/qa'
export HOSTGATOR_DEV_ROOT='/synthetic/dev'
export UONIX_CLONE_LIBRARY_ONLY=1
# shellcheck source=../clone-environment.sh
source "$CLONE_SCRIPT"

# FR1.A — artefatos não vazios porém corruptos devem falhar antes da primeira
# mutação. Os casos focados podem ser executados isoladamente por
# FR1_INTEGRITY_CASE para preservar a evidência RED→GREEN de cada boundary.
fr1_test_corrupt_backup_before_mutation() (
  local location="$1"
  local corrupt_kind="$2"
  local case_root="$TMP_DIR/fr1-${location}-backup-${corrupt_kind}"
  local steps_log="$case_root/steps.log"
  local rollback_log="$case_root/rollback.log"
  local backup_root="$case_root/backups"
  local backup_path="$backup_root/target/${STAMP}"
  local status

  # shellcheck source=../clone-environment.sh
  source "$CLONE_SCRIPT"
  mkdir -p "$case_root/wp-content/uploads" "$case_root/runtime"
  printf 'fixture\n' > "$case_root/wp-content/uploads/file.txt"
  : > "$steps_log"
  : > "$rollback_log"

  FR1_CORRUPT_KIND="$corrupt_kind"
  SOURCE='local'
  TARGET='local'
  CLONE_MODE='execute'
  REPLACE_USERS='0'
  INCLUDE_GIT_FILES='0'
  PRESERVE_DESTINATION_USERS='1'
  CLONE_TMP_DIR="$case_root/runtime"
  TARGET_BACKUP_DIR=''
  MUTATION_STARTED='0'
  ROLLBACK_RUNNING='0'
  LOCAL_BACKUP_ROOT="$backup_root"
  LOCAL_WP_CONTENT="$case_root/wp-content"
  FR1_BACKUP_ROOT="$backup_root/target"
  FR1_BACKUP_PATH="$backup_path"

  log() { :; }
  backup_dir() { printf '%s\n' "$FR1_BACKUP_PATH"; }
  resolve_backup_root() { printf '%s\n' "$FR1_BACKUP_ROOT"; }
  local_db_dump() { printf 'SQL fixture\n'; }
  gzip() {
    if [ "${1:-}" = '-c' ] && [ "$FR1_CORRUPT_KIND" = db ]; then
      cat >/dev/null
      printf 'corrupt-gzip-but-nonempty\n'
      return 0
    fi
    command gzip "$@"
  }
  tar() {
    if [ "${1:-}" = '-czf' ] && [ "$FR1_CORRUPT_KIND" = archive ]; then
      printf 'corrupt-tar-but-nonempty\n' > "$2"
      return 0
    fi
    command tar "$@"
  }
  snapshot_users() { printf 'snapshot-users\n' >> "$steps_log"; }
  snapshot_options() { printf 'snapshot-options\n' >> "$steps_log"; }
  export_source_db() { printf 'export\n' >> "$steps_log"; }
  import_db_to_target() { printf 'import\n' >> "$steps_log"; return 61; }
  restore_users() { printf 'restore-users\n' >> "$steps_log"; }
  set_target_identity() { printf 'identity\n' >> "$steps_log"; }
  restore_options() { printf 'restore-options\n' >> "$steps_log"; }
  wp_exec() { printf 'wp-exec\n' >> "$steps_log"; }
  remap_missing_authors() { printf 'authors\n' >> "$steps_log"; }
  sync_runtime_files() { printf 'sync\n' >> "$steps_log"; }
  enforce_smtp_plugin_policy() { printf 'smtp\n' >> "$steps_log"; }
  clear_cache() { printf 'cache\n' >> "$steps_log"; }
  validate_target_after_clone() { printf 'validate\n' >> "$steps_log"; }
  validate_compressx_delivery() { printf 'compressx\n' >> "$steps_log"; }
  write_clone_summary() { printf 'summary\n' >> "$steps_log"; }
  rollback_target() { printf 'rollback\n' >> "$rollback_log"; }

  if [ "$location" = remote ]; then
    TARGET='qa'
    backup_path="$backup_root/target/${STAMP}"
    FR1_BACKUP_PATH="$backup_path"
    wp_path() { printf '%s\n' "$case_root/wp"; }
    wp_cli_shell() { printf 'fr1_mock_wp\n'; }
    fr1_mock_wp() { printf 'SQL fixture\n'; }
    is_remote_env() { return 0; }
    remote_run() ( find() { return 0; }; eval "$2" )
    mkdir -p "$case_root/wp/wp-content/uploads"
    printf 'fixture\n' > "$case_root/wp/wp-content/uploads/file.txt"
  fi

  if execute_clone_with_rollback >/dev/null 2>&1; then
    status=0
  else
    status=$?
  fi
  unset -f gzip tar

  [ "$status" -ne 0 ] || fail "backup ${location}/${corrupt_kind} corrupto foi aceito"
  [ "$MUTATION_STARTED" -eq 0 ] || fail "backup ${location}/${corrupt_kind} corrupto iniciou mutação"
  [ ! -s "$steps_log" ] || fail \
    "backup ${location}/${corrupt_kind} corrupto avançou: $(tr '\n' ':' < "$steps_log")"
  [ ! -s "$rollback_log" ] || fail "backup ${location}/${corrupt_kind} corrupto acionou rollback"

  local db_artifact="${backup_path}/db-${TARGET}-${STAMP}.sql.gz"
  local files_artifact="${backup_path}/files-${TARGET}-${STAMP}.tar.gz"
  [ -s "$db_artifact" ] || fail "fixture ${location}/${corrupt_kind} não publicou dump não vazio"
  [ -s "$files_artifact" ] || fail "fixture ${location}/${corrupt_kind} não publicou archive não vazio"
)

fr1_test_corrupt_export() (
  local location="$1"
  local case_root="$TMP_DIR/fr1-${location}-export"
  local dump_file="$case_root/source.sql.gz"
  local status

  # shellcheck source=../clone-environment.sh
  source "$CLONE_SCRIPT"
  mkdir -p "$case_root"
  log() { :; }

  if [ "$location" = local ]; then
    is_remote_env() { return 1; }
    local_db_dump() { printf 'SQL fixture\n'; }
    gzip() {
      if [ "${1:-}" = '-c' ]; then
        cat >/dev/null
        printf 'corrupt-source-gzip-but-nonempty\n'
        return 0
      fi
      command gzip "$@"
    }
  else
    is_remote_env() { return 0; }
    wp_path() { printf '/synthetic/wp\n'; }
    wp_cli_shell() { printf 'wp\n'; }
    remote_stream_to_file() {
      printf 'corrupt-source-gzip-but-nonempty\n' > "$3"
      return 0
    }
  fi

  if export_source_db "$([ "$location" = local ] && printf local || printf qa)" "$dump_file" >/dev/null 2>&1; then
    status=0
  else
    status=$?
  fi
  unset -f gzip 2>/dev/null || true

  [ -s "$dump_file" ] || fail "fixture export ${location} não publicou bytes não vazios"
  [ "$status" -ne 0 ] || fail "export ${location} aceitou gzip corrupto"
)

fr1_run_integrity_case() {
  case "$1" in
    local-backup-db) fr1_test_corrupt_backup_before_mutation local db ;;
    local-backup-archive) fr1_test_corrupt_backup_before_mutation local archive ;;
    remote-backup-db) fr1_test_corrupt_backup_before_mutation remote db ;;
    remote-backup-archive) fr1_test_corrupt_backup_before_mutation remote archive ;;
    local-export) fr1_test_corrupt_export local ;;
    remote-export) fr1_test_corrupt_export remote ;;
    *) fail "caso FR1 de integridade desconhecido: $1" ;;
  esac
}

if [ -n "${FR1_INTEGRITY_CASE:-}" ]; then
  fr1_run_integrity_case "$FR1_INTEGRITY_CASE" || exit $?
  printf 'PASS: caso FR1 %s rejeita artefato corrupto.\n' "$FR1_INTEGRITY_CASE"
  exit 0
fi

for fr1_integrity_case in \
  local-backup-db \
  local-backup-archive \
  remote-backup-db \
  remote-backup-archive \
  local-export \
  remote-export; do
  fr1_run_integrity_case "$fr1_integrity_case" || exit $?
done

# D2.1 — uma falha obrigatória na finalização do backup deve cruzar o boundary
# com o status original, antes de qualquer importação, mutação ou rollback.
backup_failure_root="$TMP_DIR/backup-failure"
backup_failure_mutation_log="$backup_failure_root/mutation.log"
backup_failure_rollback_log="$backup_failure_root/rollback.log"
mkdir -p "$backup_failure_root/wp-content/uploads" "$backup_failure_root/runtime"
printf 'fixture\n' > "$backup_failure_root/wp-content/uploads/file.txt"
: > "$backup_failure_mutation_log"
: > "$backup_failure_rollback_log"

LOCAL_BACKUP_ROOT="$backup_failure_root/backups"
LOCAL_WP_CONTENT="$backup_failure_root/wp-content"
SOURCE='qa'
TARGET='local'
CLONE_MODE='execute'
REPLACE_USERS='0'
INCLUDE_GIT_FILES='0'
CLONE_TMP_DIR="$backup_failure_root/runtime"
TARGET_BACKUP_DIR=''
MUTATION_STARTED='0'
ROLLBACK_RUNNING='0'
CLONE_FAILURE_STATUS='0'

log() { :; }
local_db_dump() { printf 'SQL fixture\n'; }
chmod() {
  if [ "${1:-}" = 600 ]; then
    return 42
  fi
  command chmod "$@"
}
snapshot_users() { :; }
snapshot_options() { :; }
export_source_db() { printf 'source db\n' | gzip -c > "$2"; }
import_db_to_target() { printf 'import\n' >> "$backup_failure_mutation_log"; }
restore_users() { printf 'restore-users\n' >> "$backup_failure_mutation_log"; }
set_target_identity() { printf 'identity\n' >> "$backup_failure_mutation_log"; }
restore_options() { printf 'restore-options\n' >> "$backup_failure_mutation_log"; }
wp_exec() { printf 'wp-exec\n' >> "$backup_failure_mutation_log"; }
remap_missing_authors() { printf 'authors\n' >> "$backup_failure_mutation_log"; }
sync_runtime_files() { printf 'sync\n' >> "$backup_failure_mutation_log"; }
enforce_smtp_plugin_policy() { printf 'smtp\n' >> "$backup_failure_mutation_log"; }
clear_cache() { printf 'cache\n' >> "$backup_failure_mutation_log"; }
validate_target_after_clone() { printf 'validate\n' >> "$backup_failure_mutation_log"; }
validate_compressx_delivery() { printf 'compressx\n' >> "$backup_failure_mutation_log"; }
write_clone_summary() { printf 'summary\n' >> "$backup_failure_mutation_log"; }
rollback_target() { printf 'rollback\n' >> "$backup_failure_rollback_log"; }

if execute_clone_with_rollback >/dev/null 2>&1; then
  backup_failure_status='0'
else
  backup_failure_status="$?"
fi
unset -f chmod

[ "$backup_failure_status" -eq 42 ] || fail \
  "backup falho foi mascarado (esperado exit 42, obtido ${backup_failure_status})"
[ "$MUTATION_STARTED" -eq 0 ] || fail 'backup falho deixou MUTATION_STARTED ativo'
[ ! -s "$backup_failure_mutation_log" ] || fail \
  "backup falho iniciou mutação: $(tr '\n' ':' < "$backup_failure_mutation_log")"
[ ! -s "$backup_failure_rollback_log" ] || fail \
  'backup falho acionou rollback apesar de a mutação não ter começado'

# D2.2 — o rollback valida todos os artefatos antes da primeira remoção. Um
# archive de arquivos ausente deve preservar os dados e não tocar o banco.
# shellcheck source=../clone-environment.sh
source "$CLONE_SCRIPT"
missing_archive_root="$TMP_DIR/missing-archive"
missing_archive_backup="$missing_archive_root/backup"
missing_archive_db_log="$missing_archive_root/db.log"
mkdir -p "$missing_archive_backup" "$missing_archive_root/wp-content/uploads"
printf 'sentinel\n' > "$missing_archive_root/wp-content/uploads/sentinel.txt"
printf 'database fixture\n' | gzip -c > "$missing_archive_backup/db-local-${STAMP}.sql.gz"
: > "$missing_archive_db_log"

LOCAL_WP_CONTENT="$missing_archive_root/wp-content"
log() { :; }
local_db_import() { printf 'import\n' >> "$missing_archive_db_log"; cat >/dev/null; }
local_wp() { printf 'wp\n' >> "$missing_archive_db_log"; }

if rollback_target local "$missing_archive_backup" >/dev/null 2>&1; then
  missing_archive_status='0'
else
  missing_archive_status="$?"
fi

[ "$missing_archive_status" -ne 0 ] || fail \
  'rollback aceitou archive de arquivos ausente'
[ -f "$LOCAL_WP_CONTENT/uploads/sentinel.txt" ] || fail \
  'rollback removeu dados antes de validar o archive de arquivos'
[ ! -s "$missing_archive_db_log" ] || fail \
  'rollback tocou banco/cache antes de validar o archive de arquivos'

# D2.3 — uma falha no primeiro passo pós-MUTATION_STARTED atravessa o boundary
# com a causa primária e aciona exatamente um rollback, sem passos posteriores.
# shellcheck source=../clone-environment.sh
source "$CLONE_SCRIPT"
post_import_root="$TMP_DIR/post-import"
post_import_log="$post_import_root/steps.log"
mkdir -p "$post_import_root/runtime"
: > "$post_import_log"

SOURCE='qa'
TARGET='dev'
CLONE_MODE='execute'
REPLACE_USERS='0'
INCLUDE_GIT_FILES='0'
CLONE_TMP_DIR="$post_import_root/runtime"
TARGET_BACKUP_DIR=''
MUTATION_STARTED='0'
ROLLBACK_RUNNING='0'
CLONE_FAILURE_STATUS='0'

log() { :; }
backup_dir() { printf '%s\n' "$post_import_root/backup"; }
prepare_target_backup() { printf 'backup\n' >> "$post_import_log"; }
snapshot_users() { printf 'users\n' >> "$post_import_log"; }
snapshot_options() { printf 'options\n' >> "$post_import_log"; }
export_source_db() { printf 'export\n' >> "$post_import_log"; : > "$2"; }
import_db_to_target() { printf 'import\n' >> "$post_import_log"; return 57; }
restore_users() { printf 'restore-users-after-failure\n' >> "$post_import_log"; }
set_target_identity() { printf 'identity-after-failure\n' >> "$post_import_log"; }
restore_options() { printf 'restore-options-after-failure\n' >> "$post_import_log"; }
wp_exec() { printf 'wp-after-failure\n' >> "$post_import_log"; }
remap_missing_authors() { printf 'authors-after-failure\n' >> "$post_import_log"; }
sync_runtime_files() { printf 'sync-after-failure\n' >> "$post_import_log"; }
enforce_smtp_plugin_policy() { printf 'smtp-after-failure\n' >> "$post_import_log"; }
clear_cache() { printf 'cache-after-failure\n' >> "$post_import_log"; }
validate_target_after_clone() { printf 'validate-after-failure\n' >> "$post_import_log"; }
validate_compressx_delivery() { printf 'compressx-after-failure\n' >> "$post_import_log"; }
write_clone_summary() { printf 'summary-after-failure\n' >> "$post_import_log"; }
rollback_target() { printf 'rollback\n' >> "$post_import_log"; }

if execute_clone_with_rollback >/dev/null 2>&1; then
  post_import_status='0'
else
  post_import_status="$?"
fi

[ "$post_import_status" -eq 57 ] || fail \
  "falha pós-import foi mascarada (esperado exit 57, obtido ${post_import_status})"
[ "$CLONE_FAILURE_STATUS" -eq 57 ] || fail \
  "handler perdeu a causa primária pós-import (esperado 57, obtido ${CLONE_FAILURE_STATUS})"
[ "$(awk '$0 == "rollback" { count++ } END { print count + 0 }' "$post_import_log")" -eq 1 ] || fail \
  'falha pós-import não acionou rollback exatamente uma vez'
case "$(<"$post_import_log")" in
  *after-failure*)
    fail "boundary avançou após falha pós-import: $(tr '\n' ':' < "$post_import_log")"
    ;;
esac

# D2.4 — um TERM real recebido após MUTATION_STARTED usa o mesmo handler e
# executa um único rollback antes de sair com o status convencional do sinal.
signal_root="$TMP_DIR/signal"
signal_log="$signal_root/steps.log"
signal_backup="$signal_root/backup"
mkdir -p "$signal_backup"
: > "$signal_log"
printf 'backup sentinel\n' > "$signal_backup/preserve-me.txt"

if SIGNAL_LOG="$signal_log" SIGNAL_BACKUP="$signal_backup" CLONE_SCRIPT="$CLONE_SCRIPT" \
  /bin/bash -c '
    set -u
    export UONIX_CLONE_LIBRARY_ONLY=1
    source "$CLONE_SCRIPT"
    TARGET="dev"
    TARGET_BACKUP_DIR="$SIGNAL_BACKUP"
    MUTATION_STARTED="1"
    ROLLBACK_RUNNING="0"
    CLONE_FAILURE_STATUS="0"
    rollback_target() { printf "rollback\n" >> "$SIGNAL_LOG"; }
    trap "clone_on_signal 143" TERM
    kill -TERM "$$"
    printf "after-signal\n" >> "$SIGNAL_LOG"
  ' >/dev/null 2>&1; then
  signal_status='0'
else
  signal_status="$?"
fi

[ "$signal_status" -eq 143 ] || fail \
  "TERM pós-mutação retornou status inesperado (esperado 143, obtido ${signal_status})"
[ "$(awk '$0 == "rollback" { count++ } END { print count + 0 }' "$signal_log")" -eq 1 ] || fail \
  'TERM pós-mutação não acionou rollback exatamente uma vez'
if grep -Fq 'after-signal' "$signal_log"; then
  fail 'processo continuou depois do handler de TERM'
fi

# D2.5 — se a restauração do archive falhar depois das validações, o handler
# reporta o status do rollback, preserva o backup e não avança para o banco.
# shellcheck source=../clone-environment.sh
source "$CLONE_SCRIPT"
rollback_failure_root="$TMP_DIR/rollback-failure"
rollback_failure_backup="$rollback_failure_root/backup"
rollback_failure_archive_source="$rollback_failure_root/archive-source"
rollback_failure_wp_content="$rollback_failure_root/wp-content"
rollback_failure_log="$rollback_failure_root/steps.log"
rollback_failure_error="$rollback_failure_root/error.log"
mkdir -p \
  "$rollback_failure_backup" \
  "$rollback_failure_archive_source/uploads" \
  "$rollback_failure_wp_content/uploads" \
  "$rollback_failure_root/runtime"
printf 'backup payload\n' > "$rollback_failure_archive_source/uploads/backup.txt"
printf 'current target\n' > "$rollback_failure_wp_content/uploads/current.txt"
printf 'database backup\n' | gzip -c > "$rollback_failure_backup/db-local-${STAMP}.sql.gz"
command tar -czf "$rollback_failure_backup/files-local-${STAMP}.tar.gz" \
  -C "$rollback_failure_archive_source" uploads
: > "$rollback_failure_log"
: > "$rollback_failure_error"

LOCAL_WP_CONTENT="$rollback_failure_wp_content"
SOURCE='qa'
TARGET='local'
CLONE_MODE='execute'
REPLACE_USERS='0'
INCLUDE_GIT_FILES='0'
CLONE_TMP_DIR="$rollback_failure_root/runtime"
TARGET_BACKUP_DIR=''
MUTATION_STARTED='0'
ROLLBACK_RUNNING='0'
CLONE_FAILURE_STATUS='0'

log() { :; }
backup_dir() { printf '%s\n' "$rollback_failure_backup"; }
prepare_target_backup() { :; }
snapshot_users() { :; }
snapshot_options() { :; }
export_source_db() { : > "$2"; }
import_db_to_target() { return 57; }
restore_users() { :; }
set_target_identity() { :; }
restore_options() { :; }
wp_exec() { :; }
remap_missing_authors() { :; }
sync_runtime_files() { :; }
enforce_smtp_plugin_policy() { :; }
clear_cache() { :; }
validate_target_after_clone() { :; }
validate_compressx_delivery() { :; }
write_clone_summary() { :; }
local_db_import() { printf 'db-import\n' >> "$rollback_failure_log"; cat >/dev/null; }
local_wp() { printf 'cache\n' >> "$rollback_failure_log"; }
tar() {
  case "${1:-}" in
    -xzf)
      printf 'extract-failure\n' >> "$rollback_failure_log"
      return 82
      ;;
    *) command tar "$@" ;;
  esac
}

if execute_clone_with_rollback >/dev/null 2>"$rollback_failure_error"; then
  rollback_failure_status='0'
else
  rollback_failure_status="$?"
fi
unset -f tar

[ "$rollback_failure_status" -eq 57 ] || fail \
  "falha do rollback substituiu a causa primária (esperado 57, obtido ${rollback_failure_status})"
[ "$CLONE_FAILURE_STATUS" -eq 57 ] || fail \
  "handler perdeu a causa primária com rollback falho (esperado 57, obtido ${CLONE_FAILURE_STATUS})"
grep -Fq \
  "rollback automático também falhou (exit 82); preserve o backup ${rollback_failure_backup}." \
  "$rollback_failure_error" || fail 'falha do rollback não foi reportada com status e backup'
[ -s "$rollback_failure_backup/db-local-${STAMP}.sql.gz" ] || fail \
  'falha do rollback apagou o dump de backup'
[ -s "$rollback_failure_backup/files-local-${STAMP}.tar.gz" ] || fail \
  'falha do rollback apagou o archive de backup'
if grep -Fq 'db-import' "$rollback_failure_log"; then
  fail 'rollback tentou restaurar o banco após falha ao extrair os arquivos'
fi

printf 'PASS: rollback fail-safe por mocks.\n'