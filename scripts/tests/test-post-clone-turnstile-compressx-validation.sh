#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${CLONE_SCRIPT:-${ROOT_DIR}/scripts/clone-environment.sh}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM
TURNSTILE_LOG="${TMP_DIR}/turnstile.log"

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

# shellcheck source-path=SCRIPTDIR
# shellcheck source=../clone-environment.sh
source "$CLONE_SCRIPT"

TEST_TURNSTILE_FAIL_ENV=''
TEST_TURNSTILE_FAIL_STATUS='0'

validate_nonprod_email_policy() { :; }
validate_local_mailpit() { :; }
validate_http_endpoint() { :; }
wp_plugin_predicate_state() { printf 'false\n'; }

wp_exec() {
  local env="${1:-}"
  local command="${2:-}"
  local php_code="${3:-}"

  case "$command" in
    core) return 0 ;;
    option)
      case "${4:-}" in
        home|siteurl) env_url "$env" ;;
        blogname) env_title "$env" ;;
        *) fail "wp option inesperada: $*" ;;
      esac
      ;;
    eval)
      case "$php_code" in
        *UONIX_TURNSTILE_POLICY_VALIDATION*)
          case "$php_code" in
            *UONIX_TURNSTILE_SITE_KEY*|*UONIX_TURNSTILE_SECRET_KEY*|*_fluentform_turnstile_details*|*get_option*|*echo*|*print*|*var_dump*)
              fail 'validação Turnstile tentou recuperar ou emitir valores de chaves'
              ;;
          esac
          case "$env" in
            prod|qa|dev)
              case "$php_code" in
                *uonix_turnstile_is_enabled*) ;;
                *) fail "política Turnstile de ${env} não exige proteção ativa" ;;
              esac
              ;;
            local)
              case "$php_code" in
                *uonix_turnstile_is_local*) ;;
                *) fail 'política Turnstile local não confirma ambiente local' ;;
              esac
              case "$php_code" in
                *uonix_turnstile_is_enabled*) ;;
                *) fail 'política Turnstile local não confirma proteção desativada' ;;
              esac
              ;;
            *) fail "ambiente Turnstile inesperado: ${env}" ;;
          esac
          printf '%s\n' "$env" >> "$TURNSTILE_LOG"
          if [ "$env" = "$TEST_TURNSTILE_FAIL_ENV" ]; then
            return "$TEST_TURNSTILE_FAIL_STATUS"
          fi
          return 0
          ;;
        *wp_get_environment_type*) uonix_env_type "$env" ;;
        *UONIX_ANALYTICS_ENABLED*) return 0 ;;
        *uonix_environment_allows_indexing*blog_public*) return 0 ;;
        *) fail "wp eval inesperado: $*" ;;
      esac
      ;;
    plugin) return 0 ;;
    *) fail "wp_exec inesperado: $*" ;;
  esac
}

: > "$TURNSTILE_LOG"
for environment in prod qa dev local; do
  validate_target_after_clone "$environment" >/dev/null 2>&1 ||
    fail "política Turnstile saudável foi rejeitada em ${environment}"
done
turnstile_matrix="$(tr '\n' ':' < "$TURNSTILE_LOG")"
[ "$turnstile_matrix" = 'prod:qa:dev:local:' ] ||
  fail "Turnstile não foi validado uma vez por ambiente: ${turnstile_matrix:-nenhuma chamada}"

TEST_TURNSTILE_FAIL_ENV='qa'
TEST_TURNSTILE_FAIL_STATUS='61'
: > "$TURNSTILE_LOG"
if validate_target_after_clone qa >/dev/null 2>&1; then
  turnstile_status=0
else
  turnstile_status=$?
fi
[ "$turnstile_status" -eq 61 ] ||
  fail "falha Turnstile foi mascarada (esperado exit 61, obtido ${turnstile_status})"
[ "$(tr '\n' ':' < "$TURNSTILE_LOG")" = 'qa:' ] ||
  fail 'validação Turnstile QA não executou exatamente uma vez na falha'

printf 'PASS: política Turnstile pós-clone é validada sem expor chaves.\n'

COMPRESSX_LOG="${TMP_DIR}/compressx.log"
COMPRESSX_CONTRACT_LOG="${TMP_DIR}/compressx-contract.log"
TEST_COMPRESSX_CURL_STATUS='0'
TEST_COMPRESSX_HTTP_STATUS='200'
TEST_COMPRESSX_CONTENT_TYPE='image/webp'
COMPRESSX_CRITICAL_IMAGE_PATHS=(/wp-content/uploads/critical.png)

curl() {
  local headers_file=''
  local accept_header=''
  local max_time=''
  local url=''

  while [ "$#" -gt 0 ]; do
    case "$1" in
      -D)
        headers_file="${2:-}"
        shift 2
        ;;
      -H)
        accept_header="${2:-}"
        shift 2
        ;;
      -o)
        shift 2
        ;;
      --max-time)
        max_time="${2:-}"
        shift 2
        ;;
      -k|--insecure)
        printf 'tls-bypass\n' >> "$COMPRESSX_CONTRACT_LOG"
        return 97
        ;;
      -L|-sS)
        shift
        ;;
      *)
        url="$1"
        shift
        ;;
    esac
  done

  if [ -z "$headers_file" ]; then
    printf 'headers-file:ausente\n' >> "$COMPRESSX_CONTRACT_LOG"
    return 98
  fi
  [ "$accept_header" = 'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8' ] ||
    fail "CompressX usou Accept inesperado: ${accept_header}"
  if [ "$max_time" != '30' ]; then
    printf 'max-time:%s\n' "${max_time:-ausente}" >> "$COMPRESSX_CONTRACT_LOG"
    return 97
  fi
  printf '%s\n' "$url" >> "$COMPRESSX_LOG"
  printf 'HTTP/2 %s\r\ncontent-type: %s\r\ncontent-length: 123\r\n\r\n' \
    "$TEST_COMPRESSX_HTTP_STATUS" "$TEST_COMPRESSX_CONTENT_TYPE" > "$headers_file"
  return "$TEST_COMPRESSX_CURL_STATUS"
}

: > "$COMPRESSX_LOG"
: > "$COMPRESSX_CONTRACT_LOG"
for environment in prod qa dev local; do
  if validate_compressx_delivery "$environment" >/dev/null 2>&1; then
    :
  else
    case "$(<"$COMPRESSX_CONTRACT_LOG")" in
      *tls-bypass*) fail 'CompressX tentou ignorar a validação TLS' ;;
      *max-time:*) fail "CompressX não limitou o curl a 30 segundos: $(<"$COMPRESSX_CONTRACT_LOG")" ;;
      *) fail "CompressX saudável foi rejeitado em ${environment}" ;;
    esac
  fi
done
compressx_matrix="$(tr '\n' ':' < "$COMPRESSX_LOG")"
expected_compressx_matrix="${PRODUCTION_URL}/wp-content/uploads/critical.png:${QA_URL}/wp-content/uploads/critical.png:"
[ "$compressx_matrix" = "$expected_compressx_matrix" ] ||
  fail "CompressX deveria consultar somente produção e QA: ${compressx_matrix:-nenhuma chamada}"

printf 'PASS: política de ambientes CompressX pós-clone é respeitada.\n'

TEST_COMPRESSX_HTTP_STATUS='500'
TEST_COMPRESSX_CONTENT_TYPE='image/webp'
if validate_compressx_delivery qa >/dev/null 2>&1; then
  fail 'CompressX aceitou HTTP 500 com content-type image/webp'
fi

printf 'PASS: CompressX rejeita resposta HTTP sem sucesso.\n'

TEST_COMPRESSX_HTTP_STATUS='204'
if validate_compressx_delivery qa >/dev/null 2>&1; then
  fail 'CompressX aceitou HTTP 204 sem imagem entregue'
fi

printf 'PASS: CompressX exige HTTP 200 com imagem entregue.\n'

TEST_COMPRESSX_HTTP_STATUS='200'
TEST_COMPRESSX_CONTENT_TYPE='image/png'
if validate_compressx_delivery qa >/dev/null 2>&1; then
  fail 'CompressX aceitou imagem original em vez de AVIF/WebP'
fi

TEST_COMPRESSX_CONTENT_TYPE='image/avif'
validate_compressx_delivery qa >/dev/null 2>&1 ||
  fail 'CompressX rejeitou entrega AVIF saudável'

TEST_COMPRESSX_CONTENT_TYPE='image/webp'
TEST_COMPRESSX_CURL_STATUS='28'
if validate_compressx_delivery qa >/dev/null 2>&1; then
  fail 'CompressX aceitou falha operacional do curl'
fi

printf 'PASS: CompressX exige AVIF/WebP e rejeita falha do curl.\n'

TEST_COMPRESSX_CURL_STATUS='0'
TEST_COMPRESSX_CONTENT_TYPE='image/webp'
: > "$COMPRESSX_LOG"
# Invoked indirectly by validate_compressx_delivery from the sourced clone script.
# shellcheck disable=SC2329
env_url() {
  [ "$1" != qa ] || return 73
  uonix_environment_field "$1" url
}
if validate_compressx_delivery qa >/dev/null 2>&1; then
  compressx_resolver_status=0
else
  compressx_resolver_status=$?
fi
[ "$compressx_resolver_status" -eq 73 ] ||
  fail "CompressX mascarou falha de env_url (esperado exit 73, obtido ${compressx_resolver_status})"
[ ! -s "$COMPRESSX_LOG" ] ||
  fail 'CompressX abriu curl após falha local de env_url'

printf 'PASS: resolver CompressX falha fechado antes do curl.\n'

env_url() { uonix_environment_field "$1" url; }
mktemp() { return 74; }
: > "$COMPRESSX_LOG"
: > "$COMPRESSX_CONTRACT_LOG"
if validate_compressx_delivery qa >/dev/null 2>&1; then
  compressx_mktemp_status=0
else
  compressx_mktemp_status=$?
fi
[ "$compressx_mktemp_status" -eq 74 ] ||
  fail "CompressX mascarou falha de mktemp (esperado exit 74, obtido ${compressx_mktemp_status})"
[ ! -s "$COMPRESSX_LOG" ] ||
  fail 'CompressX abriu curl após falha de mktemp'

printf 'PASS: arquivo temporário CompressX falha fechado antes do curl.\n'
