#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${CLONE_SCRIPT:-${ROOT_DIR}/scripts/clone-environment.sh}"

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

export PRODUCTION_URL='https://uonix.com.br'
export QA_URL='https://uonix.ksio.dev'
export DEVELOPMENT_URL='https://test.uonix.ksio.dev'
export LOCAWEB_SSH_HOST='ftp.uonix.com.br'
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

TEST_MISSING_PLUGIN=''
TEST_INACTIVE_PLUGIN=''
TEST_ANALYTICS_ENABLED='false'
TEST_INDEXING_POLICY_VALID='true'

wp_exec() {
  case "${2:-}" in
    core) return 0 ;;
    option)
      case "${4:-}" in
        home|siteurl) env_url "${1:-}" ;;
        blogname) env_title "${1:-}" ;;
        *) fail "wp option inesperada: $*" ;;
      esac
      ;;
    eval)
      case "${3:-}" in
        *wp_get_environment_type*) uonix_env_type "${1:-}" ;;
        *UONIX_NONPROD_EMAIL_TO*) return 0 ;;
        *UONIX_ANALYTICS_ENABLED*) [ "$TEST_ANALYTICS_ENABLED" = 'false' ] ;;
        *uonix_environment_allows_indexing*blog_public*) [ "$TEST_INDEXING_POLICY_VALID" = 'true' ] ;;
        *) fail "wp eval inesperado: $*" ;;
      esac
      ;;
    plugin)
      case "${3:-}" in
        list)
          local plugin_name
          case "$#" in
            6)
              case "${4:-}" in
                --name=*) plugin_name="${4#--name=}" ;;
                *) fail "wp plugin list sem --name=<plugin> na posição esperada: $*" ;;
              esac
              [ -n "$plugin_name" ] || fail 'wp plugin list recebeu nome de plugin vazio'
              [ "${5:-}" = '--format=count' ] || fail "wp plugin list sem --format=count na posição esperada: $*"
              [ "${6:-}" = '--skip-update-check' ] || fail "wp plugin list sem --skip-update-check na posição esperada: $*"
              case "$plugin_name" in
                fluent-smtp|fluentform) ;;
                *) fail "plugin inesperado na consulta de instalação: $plugin_name" ;;
              esac
              if [ "$plugin_name" = "$TEST_MISSING_PLUGIN" ]; then
                printf '0\n'
              else
                printf '1\n'
              fi
              ;;
            7)
              case "${4:-}" in
                --name=*) plugin_name="${4#--name=}" ;;
                *) fail "wp plugin list sem --name=<plugin> na posição esperada: $*" ;;
              esac
              [ -n "$plugin_name" ] || fail 'wp plugin list recebeu nome de plugin vazio'
              [ "${5:-}" = '--field=status' ] || fail "wp plugin list sem --field=status na posição esperada: $*"
              [ "${6:-}" = '--format=json' ] || fail "wp plugin list sem --format=json na posição esperada: $*"
              [ "${7:-}" = '--skip-update-check' ] || fail "wp plugin list sem --skip-update-check na posição esperada: $*"
              case "$plugin_name" in
                fluent-smtp|fluentform) ;;
                *) fail "plugin inesperado na consulta de atividade: $plugin_name" ;;
              esac
              if [ "$plugin_name" = "$TEST_MISSING_PLUGIN" ]; then
                printf '[]\n'
              elif [ "$plugin_name" = "$TEST_INACTIVE_PLUGIN" ]; then
                printf '["inactive"]\n'
              else
                printf '["active"]\n'
              fi
              ;;
            *) fail "argumentos inesperados em wp plugin list: $*" ;;
          esac
          ;;
        is-active)
          [ "$#" -eq 4 ] || fail "argumentos inesperados em wp plugin is-active: $*"
          case "${4:-}" in
            fluent-smtp|fluentform) ;;
            *) fail "plugin crítico inesperado em wp plugin is-active: ${4:-}" ;;
          esac
          [ "${4:-}" != "$TEST_MISSING_PLUGIN" ] && [ "${4:-}" != "$TEST_INACTIVE_PLUGIN" ]
          ;;
        *) fail "wp plugin inesperado: $*" ;;
      esac
      ;;
    *) fail "wp_exec inesperado: $*" ;;
  esac
}

curl() {
  printf '200'
}

for TEST_INACTIVE_PLUGIN in fluent-smtp fluentform; do
  if validate_target_after_clone qa >/dev/null 2>&1; then
    fail "plugin crítico ${TEST_INACTIVE_PLUGIN} inativo foi aceito após clone"
  fi
done

TEST_INACTIVE_PLUGIN=''
TEST_ANALYTICS_ENABLED='true'
if validate_target_after_clone qa >/dev/null 2>&1; then
  fail 'analytics habilitado em QA foi aceito após clone'
fi

TEST_ANALYTICS_ENABLED='false'
TEST_INDEXING_POLICY_VALID='false'
if validate_target_after_clone qa >/dev/null 2>&1; then
  fail 'política de indexação inválida em QA foi aceita após clone'
fi

TEST_INDEXING_POLICY_VALID='true'
TEST_MISSING_PLUGIN='fluent-smtp'
TEST_INACTIVE_PLUGIN=''
validate_target_after_clone local >/dev/null 2>&1 || fail 'local sem Fluent SMTP foi rejeitado apesar da política Mailpit'

TEST_MISSING_PLUGIN=''
TEST_INACTIVE_PLUGIN='fluent-smtp'
validate_target_after_clone local >/dev/null 2>&1 || fail 'local com Fluent SMTP inativo foi rejeitado apesar da política Mailpit'

TEST_INACTIVE_PLUGIN='fluentform'
if validate_target_after_clone local >/dev/null 2>&1; then
  fail 'Fluent Forms inativo no local foi aceito como se fosse a exceção Mailpit'
fi

TEST_INACTIVE_PLUGIN=''
validate_target_after_clone qa >/dev/null 2>&1 || fail 'validação rejeitou políticas pós-clone saudáveis'

printf 'PASS: plugins críticos, analytics e indexação pós-clone são validados.\n'
