#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${CLONE_SCRIPT:-${ROOT_DIR}/scripts/clone-environment.sh}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

export PRODUCTION_URL='https://site.uonix.com.br'
export QA_URL='https://uonix.ksio.dev'
export DEVELOPMENT_URL='https://test.uonix.ksio.dev'
export LOCAWEB_SSH_HOST='ftp.site.uonix.com.br'
export LOCAWEB_SSH_PORT='22'
export LOCAWEB_SSH_USER='siteuonix1'
export LOCAWEB_DOCUMENT_ROOT='/home/storage/f/34/12/siteuonix1/public_html'
export LOCAWEB_ACCOUNT_ROOT='/home/storage/f/34/12/siteuonix1'
export LOCAWEB_PHP_BIN='/usr/bin/php85'
export LOCAWEB_WP_BIN='/home/storage/f/34/12/siteuonix1/bin/wp-cli.phar'
export HOSTGATOR_SSH_HOST='108.179.252.137'
export HOSTGATOR_SSH_PORT='22'
export HOSTGATOR_SSH_USER='uonix'
export HOSTGATOR_QA_ROOT='/home2/uonix/public_html'
export HOSTGATOR_DEV_ROOT='/home2/uonix/dev_uonix'
export UONIX_CLONE_LIBRARY_ONLY=1

# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }

expected_url='https://uonix.ksio.dev'
TEST_HOME="$expected_url"
TEST_SITEURL="$expected_url"
TEST_TITLE='QA - UONIX'
TEST_TYPE='staging'
TEST_HOME_HTTP_STATUS='200'
TEST_LOGIN_HTTP_STATUS='200'
TEST_HOME_CURL_STATUS='0'
TEST_LOGIN_CURL_STATUS='0'
TEST_CORE_STATUS='0'
TEST_HOME_WP_STATUS='0'
TEST_SITEURL_WP_STATUS='0'
TEST_TITLE_WP_STATUS='0'
TEST_TYPE_WP_STATUS='0'
CURL_LOG="$TMP_DIR/curl.log"

wp_exec() {
  case "${2:-}" in
    core)
      [ "$#" -eq 3 ] || fail "wp core recebeu argumentos inesperados: $*"
      [ "${3:-}" = 'is-installed' ] || fail "wp core inesperado: $*"
      return "$TEST_CORE_STATUS"
      ;;
    option)
      case "${3:-}" in
        get)
          [ "$#" -eq 4 ] || fail "wp option get recebeu argumentos inesperados: $*"
          case "${4:-}" in
            home)
              printf '%s\n' "$TEST_HOME"
              return "$TEST_HOME_WP_STATUS"
              ;;
            siteurl)
              printf '%s\n' "$TEST_SITEURL"
              return "$TEST_SITEURL_WP_STATUS"
              ;;
            blogname)
              printf '%s\n' "$TEST_TITLE"
              return "$TEST_TITLE_WP_STATUS"
              ;;
            *) fail "wp option get inesperado: $*" ;;
          esac
          ;;
        update)
          [ "$#" -eq 5 ] || fail "wp option update recebeu argumentos inesperados: $*"
          case "${4:-}" in
            home|siteurl|blogname) return 0 ;;
            *) fail "wp option update inesperado: $*" ;;
          esac
          ;;
        *) fail "wp option inesperado: $*" ;;
      esac
      ;;
    eval)
      case "${3:-}" in
        'echo wp_get_environment_type();')
          printf '%s\n' "$TEST_TYPE"
          return "$TEST_TYPE_WP_STATUS"
          ;;
        *) return 0 ;;
      esac
      ;;
    plugin) return 0 ;;
    *) fail "wp_exec inesperado: $*" ;;
  esac
}

curl() {
  local url=''
  local argument

  for argument in "$@"; do
    url="$argument"
  done
  printf '%s\n' "$url" >> "$CURL_LOG"
  case "$url" in
    "$expected_url")
      printf '%s' "$TEST_HOME_HTTP_STATUS"
      return "$TEST_HOME_CURL_STATUS"
      ;;
    "${expected_url}/wp-login.php")
      printf '%s' "$TEST_LOGIN_HTTP_STATUS"
      return "$TEST_LOGIN_CURL_STATUS"
      ;;
    *) fail "curl inesperado: $url" ;;
  esac
}

rollback_log="$TMP_DIR/core-validation-rollback.log"
backup_dir() { printf '%s\n' "$TMP_DIR/target-backup"; }
prepare_target_backup() { :; }
snapshot_users() { :; }
snapshot_options() { :; }
export_source_db() { :; }
import_db_to_target() { :; }
restore_users() { :; }
set_target_identity() { :; }
restore_options() { :; }
remap_missing_authors() { :; }
sync_runtime_files() { :; }
enforce_smtp_plugin_policy() { :; }
clear_cache() { :; }
validate_compressx_delivery() { :; }
rollback_target() { printf 'rollback\n' >> "$rollback_log"; }

SOURCE='prod'
TARGET='qa'
CLONE_TMP_DIR="$TMP_DIR/core-validation-boundary"
mkdir -p "$CLONE_TMP_DIR"
TARGET_BACKUP_DIR=''
MUTATION_STARTED='0'
ROLLBACK_RUNNING='0'
# Globais consumidas indiretamente pelo boundary carregado do script de clone.
: "$SOURCE" "$TARGET" "$TARGET_BACKUP_DIR" "$MUTATION_STARTED" "$ROLLBACK_RUNNING"
TEST_CORE_STATUS='47'
: > "$rollback_log"
if execute_clone_with_rollback >/dev/null 2>&1; then
  boundary_status='0'
else
  boundary_status="$?"
fi
[ "$boundary_status" -eq 47 ] || fail \
  "boundary mascarou falha central pós-mutação (esperado exit 47, obtido ${boundary_status})"
rollback_count="$(wc -l < "$rollback_log" | tr -d ' ')"
[ "$rollback_count" -eq 1 ] || fail \
  "boundary não acionou rollback exatamente uma vez (obtido ${rollback_count})"

MUTATION_STARTED='0'
TARGET_BACKUP_DIR=''
: "$MUTATION_STARTED" "$TARGET_BACKUP_DIR"
TEST_CORE_STATUS='42'
if validate_target_after_clone qa >/dev/null 2>&1; then
  core_status='0'
else
  core_status="$?"
fi
[ "$core_status" -eq 42 ] || fail \
  "core is-installed mascarou falha operacional (esperado exit 42, obtido ${core_status})"

TEST_CORE_STATUS='0'
TEST_HOME_WP_STATUS='43'
if validate_target_after_clone qa >/dev/null 2>&1; then
  home_status='0'
else
  home_status="$?"
fi
[ "$home_status" -eq 43 ] || fail \
  "option get home mascarou falha operacional (esperado exit 43, obtido ${home_status})"

TEST_HOME_WP_STATUS='0'
TEST_SITEURL_WP_STATUS='44'
if validate_target_after_clone qa >/dev/null 2>&1; then
  siteurl_status='0'
else
  siteurl_status="$?"
fi
[ "$siteurl_status" -eq 44 ] || fail \
  "option get siteurl mascarou falha operacional (esperado exit 44, obtido ${siteurl_status})"

TEST_SITEURL_WP_STATUS='0'
TEST_TITLE_WP_STATUS='45'
if validate_target_after_clone qa >/dev/null 2>&1; then
  title_status='0'
else
  title_status="$?"
fi
[ "$title_status" -eq 45 ] || fail \
  "option get blogname mascarou falha operacional (esperado exit 45, obtido ${title_status})"

TEST_TITLE_WP_STATUS='0'
TEST_TYPE_WP_STATUS='46'
if validate_target_after_clone qa >/dev/null 2>&1; then
  type_status='0'
else
  type_status="$?"
fi
[ "$type_status" -eq 46 ] || fail \
  "wp_get_environment_type mascarou falha operacional (esperado exit 46, obtido ${type_status})"

TEST_TYPE_WP_STATUS='0'
TEST_TITLE='Título divergente'
if validate_target_after_clone qa >/dev/null 2>&1; then
  fail 'título divergente foi aceito após clone'
fi

TEST_TITLE='QA - UONIX'
TEST_HOME='https://origem.example.test'
if validate_target_after_clone qa >/dev/null 2>&1; then
  fail 'home divergente foi aceito após clone'
fi

TEST_HOME="$expected_url"
TEST_SITEURL='https://origem.example.test'
if validate_target_after_clone qa >/dev/null 2>&1; then
  fail 'siteurl divergente foi aceito após clone'
fi

TEST_SITEURL="$expected_url"
TEST_TYPE='production'
if validate_target_after_clone qa >/dev/null 2>&1; then
  fail 'WP_ENVIRONMENT_TYPE divergente foi aceito após clone'
fi

TEST_TYPE='staging'
TEST_HOME_HTTP_STATUS='200'
TEST_HOME_CURL_STATUS='28'
: > "$CURL_LOG"
if validate_target_after_clone qa >/dev/null 2>&1; then
  fail 'home curl exit 28 com HTTP 200 foi aceito após clone'
fi

TEST_HOME_CURL_STATUS='0'
TEST_HOME_HTTP_STATUS='500'
: > "$CURL_LOG"
if validate_target_after_clone qa >/dev/null 2>&1; then
  fail 'home HTTP 500 foi aceito após clone'
fi

TEST_HOME_HTTP_STATUS='200'
TEST_LOGIN_HTTP_STATUS='500'
: > "$CURL_LOG"
if validate_target_after_clone qa >/dev/null 2>&1; then
  fail 'wp-login HTTP 500 foi aceito após clone'
fi

TEST_LOGIN_HTTP_STATUS='302'
: > "$CURL_LOG"
validate_target_after_clone qa || fail 'validação rejeitou home/login HTTP saudáveis'
grep -qx "$expected_url" "$CURL_LOG" || fail 'home não foi validada por HTTP'
grep -qx "${expected_url}/wp-login.php" "$CURL_LOG" || fail 'wp-login não foi validado por HTTP'

printf 'PASS: validações centrais e HTTP pós-clone\n'
