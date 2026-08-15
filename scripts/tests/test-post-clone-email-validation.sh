#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${CLONE_SCRIPT:-${ROOT_DIR}/scripts/clone-environment.sh}"
MAILPIT_MODULE="${ROOT_DIR}/mu-plugins/uonix-local/mailpit.php"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
EMAIL_EVAL_LOG="${TMP_DIR}/email-eval.log"
MAILPIT_EVAL_LOG="${TMP_DIR}/mailpit-eval.log"
PLUGIN_CHECK_LOG="${TMP_DIR}/plugin-check.log"
PLUGIN_MUTATION_LOG="${TMP_DIR}/plugin-mutation.log"
POLICY_OUTPUT_LOG="${TMP_DIR}/policy-output.log"

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
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }

# This regex intentionally matches the literal PHP variable in the module.
# shellcheck disable=SC2016
grep -Eq '\$phpmailer->SMTPAutoTLS[[:space:]]*=[[:space:]]*false' "$MAILPIT_MODULE" || \
  fail 'módulo Mailpit local não desativa TLS oportunista do PHPMailer'
grep -Fq 'PHP_INT_MAX' "$MAILPIT_MODULE" || \
  fail 'módulo Mailpit local não aplica o transporte na prioridade final'
# This fixture intentionally matches the literal PHP variable in the clone probe.
# shellcheck disable=SC2016
grep -Fq '$phpmailer = new \PHPMailer\PHPMailer\PHPMailer( true );' "$CLONE_SCRIPT" || \
  fail 'validação Mailpit não instancia PHPMailer com namespace PHP válido'

TEST_EMAIL_STATE='valid'
TEST_INACTIVE_PLUGIN=''
TEST_FLUENT_INSTALL_CHECK_STATUS='0'
TEST_FLUENT_ACTIVE_CHECK_STATUS='0'
TEST_FLUENT_INSTALL_QUERY_STATUS='0'
TEST_FLUENT_ACTIVE_QUERY_STATUS='0'
TEST_FLUENT_INSTALL_QUERY_OUTPUT='auto'
TEST_FLUENT_ACTIVE_QUERY_OUTPUT='auto'
TEST_FLUENT_ACTIVATE_STATUS='0'
TEST_FLUENT_DEACTIVATE_STATUS='0'
TEST_MAILPIT_STATE='healthy'
TEST_NONPROD_POLICY_STATE='healthy'
RED_FAILURES='0'

record_red_failure() {
  printf 'FAIL: %s\n' "$*" >&2
  RED_FAILURES=$((RED_FAILURES + 1))
}

mock_plugin_install_count() {
  local plugin="$1"
  local check_status
  local query_output
  local query_status

  case "$plugin" in
    fluent-smtp)
      check_status="$TEST_FLUENT_INSTALL_CHECK_STATUS"
      query_status="$TEST_FLUENT_INSTALL_QUERY_STATUS"
      query_output="$TEST_FLUENT_INSTALL_QUERY_OUTPUT"
      ;;
    *) fail "contagem de instalação inesperada para plugin: $plugin" ;;
  esac

  [ "$query_status" -eq 0 ] || return "$query_status"
  if [ "$query_output" != auto ]; then
    printf '%s\n' "$query_output"
    return 0
  fi

  case "$check_status" in
    0) printf '1\n' ;;
    1) printf '0\n' ;;
    *) return "$check_status" ;;
  esac
}

mock_plugin_active_json() {
  local plugin="$1"

  [ "$plugin" = fluent-smtp ] || fail "estado ativo inesperado para plugin: $plugin"
  [ "$TEST_FLUENT_ACTIVE_QUERY_STATUS" -eq 0 ] || return "$TEST_FLUENT_ACTIVE_QUERY_STATUS"
  if [ "$TEST_FLUENT_ACTIVE_QUERY_OUTPUT" != auto ]; then
    printf '%s\n' "$TEST_FLUENT_ACTIVE_QUERY_OUTPUT"
    return 0
  fi

  case "$TEST_FLUENT_INSTALL_CHECK_STATUS" in
    0)
      case "$TEST_FLUENT_ACTIVE_CHECK_STATUS" in
        0) printf '["active"]\n' ;;
        1) printf '["inactive"]\n' ;;
        *) return "$TEST_FLUENT_ACTIVE_CHECK_STATUS" ;;
      esac
      ;;
    1) printf '[]\n' ;;
    *) return "$TEST_FLUENT_INSTALL_CHECK_STATUS" ;;
  esac
}

mock_mailpit_contract() {
  local environment="$1"
  local php_code="$2"
  local required_fragment

  [ "$environment" = local ] || fail "contrato Mailpit consultado em ambiente remoto: $environment"
  printf '%s\n' "$environment" >> "$MAILPIT_EVAL_LOG"

  for required_fragment in \
    wp_get_environment_type \
    PHPMailer \
    do_action_ref_array \
    phpmailer_init \
    Mailer \
    smtp \
    Host \
    mailpit \
    Port \
    1025 \
    SMTPAuth \
    SMTPSecure \
    SMTPAutoTLS \
    fsockopen \
    is_resource; do
    case "$php_code" in
      *"$required_fragment"*) ;;
      *) fail "contrato Mailpit não verifica: $required_fragment" ;;
    esac
  done
  case "$php_code" in
    *UONIX_NONPROD_EMAIL_TO*|*DB_PASSWORD*|*DB_USER*|*wp_mail\(*|*var_dump*|*print_r*)
      fail 'contrato Mailpit tenta recuperar ou emitir destinatário/credencial'
      ;;
  esac

  case "$TEST_MAILPIT_STATE" in
    healthy) return 0 ;;
    wrong-mailer|wrong-host|wrong-port|auth-enabled|tls-enabled|auto-tls-enabled|service-unavailable) return 1 ;;
    operational-failure) return 59 ;;
    *) fail "estado Mailpit inesperado: $TEST_MAILPIT_STATE" ;;
  esac
}

mock_nonprod_email_policy_contract() {
  local environment="$1"
  local php_code="$2"
  local required_fragment

  [ "$environment" = qa ] || [ "$environment" = dev ] || \
    fail "contrato de roteamento não produtivo consultado em ambiente inesperado: $environment"
  printf '%s\n' "$environment" >> "$EMAIL_EVAL_LOG"

  for required_fragment in \
    UONIX_NONPROD_EMAIL_POLICY_VALIDATION \
    UONIX_NONPROD_EMAIL_TO \
    uonix_apply_email_environment_policy \
    uonix_filter_email_environment_policy \
    uonix_prevent_unsafe_nonprod_email \
    has_filter \
    apply_filters \
    wp_mail \
    pre_wp_mail \
    PHP_INT_MAX \
    example.invalid \
    Cc: \
    Bcc: \
    Reply-To:; do
    case "$php_code" in
      *"$required_fragment"*) ;;
      *) fail "contrato de roteamento não verifica: $required_fragment" ;;
    esac
  done
  case "$php_code" in
    *DB_PASSWORD*|*DB_USER*|*'wp_mail('*|*var_dump*|*print_r*|*'echo '*|*'print '*)
      fail 'contrato de roteamento tenta enviar, recuperar credencial ou imprimir dados'
      ;;
  esac

  case "$TEST_EMAIL_STATE" in
    missing|invalid) return 1 ;;
    valid) ;;
    *) fail "estado de e-mail inesperado: $TEST_EMAIL_STATE" ;;
  esac
  case "$TEST_NONPROD_POLICY_STATE" in
    healthy) return 0 ;;
    module-missing|hook-missing|ineffective|copy-headers|reply-to-removed) return 1 ;;
    operational-failure) return 58 ;;
    *) fail "estado de política não produtiva inesperado: $TEST_NONPROD_POLICY_STATE" ;;
  esac
}

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
        *UONIX_LOCAL_MAILPIT_VALIDATION*) mock_mailpit_contract "${1:-}" "${3:-}" ;;
        *UONIX_NONPROD_EMAIL_POLICY_VALIDATION*) mock_nonprod_email_policy_contract "${1:-}" "${3:-}" ;;
        *wp_get_environment_type*) uonix_env_type "${1:-}" ;;
        *UONIX_NONPROD_EMAIL_TO*)
          printf '%s\n' "${1:-}" >> "$EMAIL_EVAL_LOG"
          case "$TEST_EMAIL_STATE" in
            valid) return 0 ;;
            missing) return 1 ;;
            invalid)
              case "${3:-}" in
                *is_email*) return 1 ;;
                *) return 0 ;;
              esac
              ;;
            *) fail "estado de e-mail inesperado: $TEST_EMAIL_STATE" ;;
          esac
          ;;
        *UONIX_ANALYTICS_ENABLED*) return 0 ;;
        *uonix_environment_allows_indexing*blog_public*) return 0 ;;
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
              mock_plugin_install_count "$plugin_name"
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
              printf '%s:%s\n' "${1:-}" "$plugin_name" >> "$PLUGIN_CHECK_LOG"
              mock_plugin_active_json "$plugin_name"
              ;;
            *) fail "argumentos inesperados em wp plugin list: $*" ;;
          esac
          ;;
        is-installed)
          case "${4:-}" in
            fluent-smtp) return "$TEST_FLUENT_INSTALL_CHECK_STATUS" ;;
            *) return 1 ;;
          esac
          ;;
        is-active)
          printf '%s:%s\n' "${1:-}" "${4:-}" >> "$PLUGIN_CHECK_LOG"
          if [ "${4:-}" = 'fluent-smtp' ]; then
            return "$TEST_FLUENT_ACTIVE_CHECK_STATUS"
          fi
          [ "${4:-}" != "$TEST_INACTIVE_PLUGIN" ]
          ;;
        activate)
          printf '%s:activate:%s\n' "${1:-}" "${4:-}" >> "$PLUGIN_MUTATION_LOG"
          [ "$TEST_FLUENT_ACTIVATE_STATUS" -eq 0 ] || return "$TEST_FLUENT_ACTIVATE_STATUS"
          TEST_FLUENT_ACTIVE_CHECK_STATUS='0'
          TEST_INACTIVE_PLUGIN=''
          ;;
        deactivate)
          printf '%s:deactivate:%s\n' "${1:-}" "${4:-}" >> "$PLUGIN_MUTATION_LOG"
          [ "$TEST_FLUENT_DEACTIVATE_STATUS" -eq 0 ] || return "$TEST_FLUENT_DEACTIVATE_STATUS"
          TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
          TEST_INACTIVE_PLUGIN="${4:-}"
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

TEST_EMAIL_STATE='missing'
for environment in qa dev; do
  if validate_target_after_clone "$environment" >/dev/null 2>&1; then
    fail "${environment} sem caixa segura foi aceito após clone"
  fi
done

TEST_EMAIL_STATE='invalid'
for environment in qa dev; do
  if validate_target_after_clone "$environment" >/dev/null 2>&1; then
    fail "${environment} com caixa segura inválida foi aceito após clone"
  fi
done

TEST_EMAIL_STATE='valid'
for TEST_NONPROD_POLICY_STATE in \
  module-missing \
  hook-missing \
  ineffective \
  copy-headers \
  reply-to-removed; do
  for environment in qa dev; do
    : > "$EMAIL_EVAL_LOG"
    if validate_target_after_clone "$environment" >/dev/null 2>&1; then
      record_red_failure \
        "${environment} aceitou política de roteamento ineficaz: ${TEST_NONPROD_POLICY_STATE}"
    fi
    grep -qx "$environment" "$EMAIL_EVAL_LOG" || record_red_failure \
      "${environment} não executou prova in-processo: ${TEST_NONPROD_POLICY_STATE}"
  done
done

TEST_NONPROD_POLICY_STATE='operational-failure'
if validate_target_after_clone qa >/dev/null 2>&1; then
  nonprod_policy_operational_status=0
else
  nonprod_policy_operational_status=$?
fi
[ "$nonprod_policy_operational_status" -eq 58 ] || record_red_failure \
  "validação de roteamento mascarou falha operacional (esperado 58, obtido ${nonprod_policy_operational_status})"
TEST_NONPROD_POLICY_STATE='healthy'

TEST_EMAIL_STATE='missing'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
: > "$EMAIL_EVAL_LOG"
validate_target_after_clone local >/dev/null 2>&1 || fail 'local com Mailpit foi rejeitado por não ter caixa segura remota'
[ ! -s "$EMAIL_EVAL_LOG" ] || fail 'local consultou UONIX_NONPROD_EMAIL_TO apesar da política Mailpit'

TEST_FLUENT_ACTIVE_CHECK_STATUS='0'
if validate_target_after_clone local >/dev/null 2>&1; then
  fail 'Fluent SMTP ativo no local foi aceito apesar da política Mailpit'
fi

TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
TEST_EMAIL_STATE='missing'
for TEST_MAILPIT_STATE in \
  wrong-mailer \
  wrong-host \
  wrong-port \
  auth-enabled \
  tls-enabled \
  auto-tls-enabled \
  service-unavailable; do
  : > "$MAILPIT_EVAL_LOG"
  if validate_target_after_clone local >/dev/null 2>&1; then
    record_red_failure "local aceitou contrato Mailpit inválido: ${TEST_MAILPIT_STATE}"
  fi
  if ! grep -qx local "$MAILPIT_EVAL_LOG"; then
    record_red_failure "local não consultou contrato Mailpit: ${TEST_MAILPIT_STATE}"
  fi
done

TEST_MAILPIT_STATE='operational-failure'
if validate_target_after_clone local >/dev/null 2>&1; then
  mailpit_operational_status='0'
else
  mailpit_operational_status="$?"
fi
[ "$mailpit_operational_status" -eq 59 ] || record_red_failure \
  "validação Mailpit mascarou falha operacional (esperado exit 59, obtido ${mailpit_operational_status})"

TEST_MAILPIT_STATE='healthy'
: > "$MAILPIT_EVAL_LOG"
: > "$EMAIL_EVAL_LOG"
validate_target_after_clone local >/dev/null 2>&1 || \
  record_red_failure 'local saudável com Mailpit disponível foi rejeitado'
grep -qx local "$MAILPIT_EVAL_LOG" || \
  record_red_failure 'local saudável não provou o transporte Mailpit final'
[ ! -s "$EMAIL_EVAL_LOG" ] || \
  record_red_failure 'local consultou UONIX_NONPROD_EMAIL_TO ao validar Mailpit'

TEST_MAILPIT_STATE='service-unavailable'
TEST_EMAIL_STATE='valid'
TEST_FLUENT_ACTIVE_CHECK_STATUS='0'
: > "$MAILPIT_EVAL_LOG"
for environment in prod qa dev; do
  validate_target_after_clone "$environment" >/dev/null 2>&1 || \
    record_red_failure "${environment} passou a exigir serviço Mailpit local"
done
[ ! -s "$MAILPIT_EVAL_LOG" ] || \
  record_red_failure 'produção/QA/DEV consultaram contrato Mailpit local'
TEST_MAILPIT_STATE='healthy'

TEST_FLUENT_INSTALL_CHECK_STATUS='42'
TEST_FLUENT_ACTIVE_CHECK_STATUS='0'
if enforce_smtp_plugin_policy local >/dev/null 2>&1; then
  policy_install_status='0'
else
  policy_install_status="$?"
fi
[ "$policy_install_status" -eq 42 ] || record_red_failure \
  "enforce_smtp_plugin_policy local mascarou erro operacional de is-installed (esperado exit 42, obtido ${policy_install_status})"

TEST_FLUENT_INSTALL_CHECK_STATUS='0'
TEST_FLUENT_ACTIVE_CHECK_STATUS='42'
if enforce_smtp_plugin_policy local >/dev/null 2>&1; then
  policy_active_status='0'
else
  policy_active_status="$?"
fi
[ "$policy_active_status" -eq 42 ] || record_red_failure \
  "enforce_smtp_plugin_policy local mascarou erro operacional de is-active (esperado exit 42, obtido ${policy_active_status})"

if validate_target_after_clone local >/dev/null 2>&1; then
  validation_active_status='0'
else
  validation_active_status="$?"
fi
[ "$validation_active_status" -eq 42 ] || record_red_failure \
  "validate_target_after_clone local mascarou erro operacional de is-active (esperado exit 42, obtido ${validation_active_status})"

TEST_FLUENT_INSTALL_CHECK_STATUS='1'
TEST_FLUENT_INSTALL_QUERY_STATUS='1'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
if enforce_smtp_plugin_policy local >/dev/null 2>&1; then
  policy_install_exit_one_status='0'
else
  policy_install_exit_one_status="$?"
fi
[ "$policy_install_exit_one_status" -eq 1 ] || record_red_failure \
  "enforce_smtp_plugin_policy local converteu erro operacional exit 1 de instalação em ausência legítima (obtido exit ${policy_install_exit_one_status})"
TEST_FLUENT_INSTALL_QUERY_STATUS='0'

TEST_FLUENT_INSTALL_CHECK_STATUS='0'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
TEST_FLUENT_ACTIVE_QUERY_STATUS='1'
if enforce_smtp_plugin_policy local >/dev/null 2>&1; then
  policy_active_exit_one_status='0'
else
  policy_active_exit_one_status="$?"
fi
[ "$policy_active_exit_one_status" -eq 1 ] || record_red_failure \
  "enforce_smtp_plugin_policy local converteu erro operacional exit 1 de atividade em inatividade legítima (obtido exit ${policy_active_exit_one_status})"
if validate_target_after_clone local >/dev/null 2>&1; then
  validation_active_exit_one_status='0'
else
  validation_active_exit_one_status="$?"
fi
[ "$validation_active_exit_one_status" -eq 1 ] || record_red_failure \
  "validate_target_after_clone local converteu erro operacional exit 1 de atividade em conformidade Mailpit (obtido exit ${validation_active_exit_one_status})"
TEST_FLUENT_ACTIVE_QUERY_STATUS='0'

TEST_FLUENT_INSTALL_CHECK_STATUS='1'
TEST_FLUENT_INSTALL_QUERY_OUTPUT=''
if enforce_smtp_plugin_policy local >/dev/null 2>&1; then
  record_red_failure 'enforce_smtp_plugin_policy local aceitou saída vazia da consulta explícita de instalação'
fi
TEST_FLUENT_INSTALL_QUERY_OUTPUT='2'
if enforce_smtp_plugin_policy local >/dev/null 2>&1; then
  record_red_failure 'enforce_smtp_plugin_policy local aceitou contagem inesperada da consulta explícita de instalação'
fi
TEST_FLUENT_INSTALL_QUERY_OUTPUT='auto'

TEST_FLUENT_INSTALL_CHECK_STATUS='0'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
TEST_FLUENT_ACTIVE_QUERY_OUTPUT=''
if validate_target_after_clone local >/dev/null 2>&1; then
  record_red_failure 'validate_target_after_clone local aceitou saída vazia da consulta explícita de atividade'
fi
TEST_FLUENT_ACTIVE_QUERY_OUTPUT='["unexpected"]'
if validate_target_after_clone local >/dev/null 2>&1; then
  record_red_failure 'validate_target_after_clone local aceitou JSON inesperado da consulta explícita de atividade'
fi
TEST_FLUENT_ACTIVE_QUERY_OUTPUT='auto'

TEST_FLUENT_INSTALL_CHECK_STATUS='1'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
: > "$PLUGIN_MUTATION_LOG"
enforce_smtp_plugin_policy local >/dev/null 2>&1 || fail 'ausência legítima do Fluent SMTP no local foi rejeitada'
[ ! -s "$PLUGIN_MUTATION_LOG" ] || fail 'ausência legítima do Fluent SMTP causou mutação de plugin no local'

TEST_FLUENT_INSTALL_CHECK_STATUS='0'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
: > "$PLUGIN_MUTATION_LOG"
enforce_smtp_plugin_policy local >/dev/null 2>&1 || fail 'Fluent SMTP legitimamente inativo no local foi rejeitado'
if grep -q 'local:deactivate:fluent-smtp' "$PLUGIN_MUTATION_LOG"; then
  fail 'Fluent SMTP já inativo no local foi desativado novamente'
fi

TEST_FLUENT_ACTIVE_CHECK_STATUS='0'
TEST_FLUENT_DEACTIVATE_STATUS='0'
: > "$PLUGIN_MUTATION_LOG"
enforce_smtp_plugin_policy local >/dev/null 2>&1 || fail 'política SMTP não conseguiu preparar o local para Mailpit'
grep -qx 'local:deactivate:fluent-smtp' "$PLUGIN_MUTATION_LOG" || fail 'Fluent SMTP instalado no local não foi desativado para preservar Mailpit'
if grep -q 'local:activate:fluent-smtp' "$PLUGIN_MUTATION_LOG"; then
  fail 'política SMTP ativou Fluent SMTP no local e conflitou com Mailpit'
fi

TEST_FLUENT_ACTIVE_CHECK_STATUS='0'
TEST_FLUENT_DEACTIVATE_STATUS='29'
if enforce_smtp_plugin_policy local >/dev/null 2>&1; then
  fail 'falha real ao desativar Fluent SMTP no local não foi propagada'
fi
TEST_FLUENT_DEACTIVATE_STATUS='0'

TEST_FLUENT_INSTALL_CHECK_STATUS='1'
for environment in prod qa dev; do
  if enforce_smtp_plugin_policy "$environment" >/dev/null 2>&1; then
    fail "${environment} sem Fluent SMTP instalado foi aceito pela política SMTP"
  fi
done

TEST_FLUENT_INSTALL_CHECK_STATUS='0'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
TEST_FLUENT_ACTIVATE_STATUS='23'
if enforce_smtp_plugin_policy qa >/dev/null 2>&1; then
  fail 'falha ao ativar Fluent SMTP em QA não foi propagada'
fi

TEST_FLUENT_ACTIVATE_STATUS='0'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
: > "$PLUGIN_MUTATION_LOG"
for environment in prod qa dev; do
  enforce_smtp_plugin_policy "$environment" >/dev/null 2>&1 || fail "política SMTP rejeitou Fluent SMTP instalável em ${environment}"
  grep -qx "${environment}:activate:fluent-smtp" "$PLUGIN_MUTATION_LOG" || fail "Fluent SMTP não foi ativado em ${environment}"
done

TEST_EMAIL_STATE='valid'
for environment in prod qa dev; do
  TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
  if validate_target_after_clone "$environment" >/dev/null 2>&1; then
    fail "${environment} aceitou Fluent SMTP inativo após clone"
  fi
done

TEST_FLUENT_ACTIVE_CHECK_STATUS='0'
: > "$EMAIL_EVAL_LOG"
validate_target_after_clone prod >/dev/null 2>&1 || fail 'produção saudável foi rejeitada pela política de e-mail/SMTP'
[ ! -s "$EMAIL_EVAL_LOG" ] || fail 'produção consultou UONIX_NONPROD_EMAIL_TO'

: > "$EMAIL_EVAL_LOG"
for environment in qa dev; do
  validate_target_after_clone "$environment" >/dev/null 2>&1 || fail "${environment} saudável foi rejeitado pela política de e-mail/SMTP"
done
grep -qx 'qa' "$EMAIL_EVAL_LOG" || fail 'QA não validou a caixa segura'
grep -qx 'dev' "$EMAIL_EVAL_LOG" || fail 'DEV não validou a caixa segura'

grep -Fq \
  'Política SMTP pós-clone: ativar fluent-smtp em produção/QA/DEV e manter desativado no local (Mailpit).' \
  "$CLONE_SCRIPT" || fail 'resumo do dry-run contradiz a política SMTP por ambiente'

rollback_log="$TMP_DIR/smtp-rollback.log"
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
clear_cache() { :; }
validate_target_after_clone() { :; }
validate_compressx_delivery() { :; }
rollback_target() { printf 'rollback\n' >> "$rollback_log"; }

TEST_FLUENT_INSTALL_CHECK_STATUS='0'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
TEST_FLUENT_ACTIVATE_STATUS='37'
SOURCE='qa'
TARGET='dev'
CLONE_TMP_DIR="$TMP_DIR/smtp-boundary"
mkdir -p "$CLONE_TMP_DIR"
TARGET_BACKUP_DIR=''
MUTATION_STARTED='0'
ROLLBACK_RUNNING='0'
# Globais consumidas indiretamente pelo boundary carregado do script de clone.
: "$SOURCE" "$TARGET" "$TARGET_BACKUP_DIR" "$MUTATION_STARTED" "$ROLLBACK_RUNNING"
: > "$rollback_log"
if execute_clone_with_rollback >"$POLICY_OUTPUT_LOG" 2>&1; then
  boundary_status='0'
else
  boundary_status="$?"
fi
[ "$boundary_status" -eq 1 ] || record_red_failure \
  "boundary de clone mascarou falha SMTP pós-mutação (esperado exit 1, obtido ${boundary_status})"
[ "$(wc -l < "$rollback_log" | tr -d ' ')" = 1 ] || record_red_failure \
  'boundary de clone não chamou rollback exatamente uma vez após falha SMTP pós-mutação'

TEST_FLUENT_INSTALL_CHECK_STATUS='1'
TEST_FLUENT_INSTALL_QUERY_STATUS='1'
TEST_FLUENT_ACTIVE_CHECK_STATUS='1'
SOURCE='dev'
TARGET='local'
CLONE_TMP_DIR="$TMP_DIR/plugin-query-boundary"
mkdir -p "$CLONE_TMP_DIR"
TARGET_BACKUP_DIR=''
MUTATION_STARTED='0'
ROLLBACK_RUNNING='0'
: > "$rollback_log"
if execute_clone_with_rollback >"$POLICY_OUTPUT_LOG" 2>&1; then
  query_boundary_status='0'
else
  query_boundary_status="$?"
fi
[ "$query_boundary_status" -eq 1 ] || record_red_failure \
  "boundary de clone mascarou erro operacional exit 1 da consulta de plugin (esperado exit 1, obtido ${query_boundary_status})"
[ "$(wc -l < "$rollback_log" | tr -d ' ')" = 1 ] || record_red_failure \
  'boundary de clone não chamou rollback exatamente uma vez após erro exit 1 da consulta de plugin pós-mutação'

[ "$RED_FAILURES" -eq 0 ] || exit 1

printf 'PASS: validações de e-mail e SMTP pós-clone.\n'
