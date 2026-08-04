#!/usr/bin/env bash
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="$ROOT_DIR/scripts/clone-environment.sh"
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

# FR1.B — o snapshot de usuários é obrigatório, privado e autenticado antes de
# qualquer mutação; o restore valida novamente o mesmo manifesto canônico.
fr1_users_file_mode() {
  uonix_transport_file_mode "$1"
}

fr1_write_valid_users_fixture() {
  local directory="$1"

  umask 077
  mkdir -p "$directory"
  chmod 700 "$directory"
  printf 'INSERT INTO users VALUES (1);\n' > "$directory/users.sql"
  (
    cd "$directory" || exit $?
    shasum -a 256 users.sql > users.sha256
  )
  chmod 600 "$directory/users.sql" "$directory/users.sha256"
}

fr1_prepare_users_variant() {
  local directory="$1"
  local variant="$2"

  rm -rf "$directory"
  case "$variant" in
    missing)
      mkdir -p "$directory"
      chmod 700 "$directory"
      ;;
    empty)
      fr1_write_valid_users_fixture "$directory"
      : > "$directory/users.sql"
      (
        cd "$directory" || exit $?
        shasum -a 256 users.sql > users.sha256
      )
      ;;
    truncated)
      fr1_write_valid_users_fixture "$directory"
      printf 'TRUNCATED' >> "$directory/users.sql"
      ;;
    missing-manifest)
      fr1_write_valid_users_fixture "$directory"
      rm -f "$directory/users.sha256"
      ;;
    malformed-manifest)
      fr1_write_valid_users_fixture "$directory"
      printf 'not-a-canonical-checksum\n' > "$directory/users.sha256"
      ;;
    wrong-mode)
      fr1_write_valid_users_fixture "$directory"
      chmod 755 "$directory"
      chmod 644 "$directory/users.sql" "$directory/users.sha256"
      ;;
    checksum-mismatch)
      fr1_write_valid_users_fixture "$directory"
      printf 'changed-after-manifest\n' > "$directory/users.sql"
      ;;
    healthy)
      fr1_write_valid_users_fixture "$directory"
      ;;
    *) fail "variante FR1 de usuários desconhecida: $variant" ;;
  esac
}

fr1_test_users_snapshot() (
  local location="$1"
  local producer="$2"
  local directory="$TMP_DIR/fr1-users-snapshot-${location}-${producer}"
  local status

  # shellcheck source=scripts/clone-environment.sh
  source "$CLONE_SCRIPT"
  # Guarda de bancos distintos consulta o banco real; no fixture retornaria
  # vazio e abortaria antes do comportamento sob teste. Cobertura propria em
  # test-clone-database-transport.sh.
  # shellcheck disable=SC2329
  assert_distinct_databases() { :; }
  rm -rf "$directory"
  PRESERVE_DESTINATION_USERS='1'
  log() { :; }
  is_remote_env() { [ "$location" = remote ]; }

  if [ "$location" = local ]; then
    local_db_prefix() { printf 'wpis_\n'; }
    local_db_dump() {
      [ "$producer" = empty ] || printf 'INSERT INTO users VALUES (1);\n'
      return 0
    }
  else
    wp_path() { printf '/synthetic/wp\n'; }
    wp_cli_shell() { printf 'fr1_users_wp\n'; }
    FR1_USERS_PRODUCER="$producer"
    # Invoked indirectly through the command returned by wp_cli_shell.
    # shellcheck disable=SC2329
    fr1_users_wp() {
      local argument
      local output_file=''
      # O snapshot passou a preferir mysqldump direto (wp db export --tables
      # shella out e falha na Locaweb), então o mock precisa responder a
      # `config get`. Sem isso ele devolvia 92 e o dump nunca era gerado.
      case " $* " in
        *' config get '*)
          for argument in "$@"; do
            case "$argument" in
              DB_NAME) printf 'synthetic_db\n'; return 0 ;;
              DB_USER) printf 'synthetic_user\n'; return 0 ;;
              DB_HOST) printf 'synthetic_host\n'; return 0 ;;
              DB_PASSWORD) printf 'synthetic_password\n'; return 0 ;;
            esac
          done
          return 93
          ;;
      esac
      case " $* " in
        *' db prefix '*) printf 'wpis_\n'; return 0 ;;
        *' db export '*)
          for argument in "$@"; do
            case "$argument" in *.sql) output_file="$argument" ;; esac
          done
          [ -n "$output_file" ] || return 91
          : > "$output_file"
          [ "$FR1_USERS_PRODUCER" = empty ] || printf 'INSERT INTO users VALUES (1);\n' > "$output_file"
          return 0
          ;;
      esac
      return 92
    }
    # mysqldump sintético: o snapshot o prefere ao wp-cli, então sem ele o teste
    # exercitaria apenas o fallback e nunca o caminho usado em produção.
    # shellcheck disable=SC2329
    mysqldump() {
      case " $* " in
        *' --help '*) printf -- '--single-transaction\n'; return 0 ;;
      esac
      [ "$FR1_USERS_PRODUCER" = empty ] || printf 'INSERT INTO users VALUES (1);\n'
      return 0
    }
    remote_run() (
      # The sourced script's xargs call does not invoke this eval-scoped mock.
      # shellcheck disable=SC2032
      # Evaluated remote shell snippets invoke this test-local mock indirectly.
      # shellcheck disable=SC2329
      stat() {
        [ "${1:-}" = '-c' ] || return 93
        if command stat -c '%a' "$3" >/dev/null 2>&1; then
          command stat -c '%a' "$3"
        else
          command stat -f '%Lp' "$3"
        fi
      }
      # Evaluated remote shell snippets invoke this test-local mock indirectly.
      # shellcheck disable=SC2329
      sha256sum() { shasum -a 256 "$@"; }
      eval "$2"
    )
  fi

  previous_umask="$(umask)"
  umask 022
  if snapshot_users "$([ "$location" = local ] && printf local || printf qa)" "$directory" >/dev/null 2>&1; then
    status=0
  else
    status=$?
  fi
  umask "$previous_umask"

  if [ "$producer" = empty ]; then
    [ "$status" -ne 0 ] || fail "snapshot ${location} aceitou dump de usuários vazio"
    return 0
  fi

  [ "$status" -eq 0 ] || fail "snapshot ${location} saudável falhou com exit ${status}"
  [ "$(fr1_users_file_mode "$directory")" = 700 ] || fail "snapshot ${location} deixou diretório fora de 0700"
  [ "$(fr1_users_file_mode "$directory/users.sql")" = 600 ] || fail "snapshot ${location} deixou users.sql fora de 0600"
  [ -s "$directory/users.sql" ] || fail "snapshot ${location} publicou users.sql vazio"
  [ -s "$directory/users.sha256" ] || fail "snapshot ${location} não publicou manifesto canônico"
  [ "$(fr1_users_file_mode "$directory/users.sha256")" = 600 ] || fail "snapshot ${location} deixou users.sha256 fora de 0600"
  (
    cd "$directory" || exit $?
    shasum -a 256 users.sql | cmp -s - users.sha256
  ) || fail "snapshot ${location} publicou manifesto inválido"
)

fr1_test_users_restore_variant() (
  local location="$1"
  local variant="$2"
  local directory="$TMP_DIR/fr1-users-restore-${location}-${variant}"
  local import_log="$directory.import.log"
  local status

  # shellcheck source=scripts/clone-environment.sh
  source "$CLONE_SCRIPT"
  # Guarda de bancos distintos consulta o banco real; no fixture retornaria
  # vazio e abortaria antes do comportamento sob teste. Cobertura propria em
  # test-clone-database-transport.sh.
  # shellcheck disable=SC2329
  assert_distinct_databases() { :; }
  fr1_prepare_users_variant "$directory" "$variant"
  : > "$import_log"
  PRESERVE_DESTINATION_USERS='1'
  log() { :; }
  is_remote_env() { [ "$location" = remote ]; }
  local_db_import() { printf 'import\n' >> "$import_log"; cat >/dev/null; }

  if [ "$location" = remote ]; then
    wp_path() { printf '/synthetic/wp\n'; }
    wp_cli_shell() { printf 'fr1_users_restore_wp\n'; }
    # Invoked indirectly through the command returned by wp_cli_shell.
    # shellcheck disable=SC2329
    fr1_users_restore_wp() {
      # O restore passou a resolver o cliente `mysql` (wp db import shella out e
      # falha na Locaweb), então o mock precisa responder a `config get`.
      local argument
      case " $* " in
        *' config get '*)
          for argument in "$@"; do
            case "$argument" in
              DB_NAME) printf 'synthetic_db\n'; return 0 ;;
              DB_USER) printf 'synthetic_user\n'; return 0 ;;
              DB_HOST) printf 'synthetic_host\n'; return 0 ;;
              DB_PASSWORD) printf 'synthetic_password\n'; return 0 ;;
            esac
          done
          return 93
          ;;
      esac
      case " $* " in
        *' db import '*) printf 'import\n' >> "$import_log"; return 0 ;;
      esac
      return 94
    }
    # Cliente `mysql` sintético: sem ele o restore cairia no fallback e o caminho
    # real nunca seria exercitado.
    # shellcheck disable=SC2329
    mysql() {
      printf 'import\n' >> "$import_log"
      cat >/dev/null
      return 0
    }
    remote_run() (
      # Evaluated remote shell snippets invoke this test-local mock indirectly.
      # shellcheck disable=SC2329
      stat() {
        [ "${1:-}" = '-c' ] || return 95
        if command stat -c '%a' "$3" >/dev/null 2>&1; then
          command stat -c '%a' "$3"
        else
          command stat -f '%Lp' "$3"
        fi
      }
      # Evaluated remote shell snippets invoke this test-local mock indirectly.
      # shellcheck disable=SC2329
      sha256sum() { shasum -a 256 "$@"; }
      eval "$2"
    )
  fi

  if restore_users "$([ "$location" = local ] && printf local || printf qa)" "$directory" >/dev/null 2>&1; then
    status=0
  else
    status=$?
  fi

  import_count="$(wc -l < "$import_log" | tr -d ' ')"
  if [ "$variant" = healthy ]; then
    [ "$status" -eq 0 ] || fail "restore ${location} saudável falhou com exit ${status}"
    [ "$import_count" -eq 1 ] || fail "restore ${location} saudável importou ${import_count} vezes"
  else
    [ "$status" -ne 0 ] || fail "restore ${location}/${variant} foi aceito"
    [ "$import_count" -eq 0 ] || fail "restore ${location}/${variant} iniciou importação"
  fi
)

fr1_run_users_case() {
  local case_name="$1"
  case "$case_name" in
    local-snapshot-healthy) fr1_test_users_snapshot local healthy ;;
    local-snapshot-empty) fr1_test_users_snapshot local empty ;;
    remote-snapshot-healthy) fr1_test_users_snapshot remote healthy ;;
    remote-snapshot-empty) fr1_test_users_snapshot remote empty ;;
    local-restore-*) fr1_test_users_restore_variant local "${case_name#local-restore-}" ;;
    remote-restore-*) fr1_test_users_restore_variant remote "${case_name#remote-restore-}" ;;
    *) fail "caso FR1 de usuários desconhecido: $case_name" ;;
  esac
}

FR1_USERS_CASES=(
  local-snapshot-healthy local-snapshot-empty
  remote-snapshot-healthy remote-snapshot-empty
)
for fr1_users_location in local remote; do
  for fr1_users_variant in missing empty truncated missing-manifest malformed-manifest wrong-mode checksum-mismatch healthy; do
    FR1_USERS_CASES+=("${fr1_users_location}-restore-${fr1_users_variant}")
  done
done

if [ -n "${FR1_USERS_CASE:-}" ]; then
  fr1_run_users_case "$FR1_USERS_CASE" || exit $?
  printf 'PASS: caso FR1 %s preserva usuários fail-closed.\n' "$FR1_USERS_CASE"
  exit 0
fi

for fr1_users_case in "${FR1_USERS_CASES[@]}"; do
  fr1_run_users_case "$fr1_users_case" || exit $?
done

# 1. Exclusões específicas do destino devem ser protegidas mesmo com --delete.
type bridge_upload_payload >/dev/null 2>&1 || fail 'bridge_upload_payload ausente'
payload="$TMP_DIR/payload"
target="$TMP_DIR/target"
mkdir -p "$payload/ordinary-plugin" "$target/fluent-smtp" "$target/obsolete-plugin"
printf 'new\n' > "$payload/ordinary-plugin/plugin.php"
printf 'preserve\n' > "$target/fluent-smtp/config.php"
printf 'delete\n' > "$target/obsolete-plugin/old.php"
bridge_upload_payload local "$payload" "$target" plugins
[ -f "$target/fluent-smtp/config.php" ] || fail 'plugin excluído/configuração do destino foi apagado'
[ -f "$target/ordinary-plugin/plugin.php" ] || fail 'plugin normal não foi publicado'
[ ! -e "$target/obsolete-plugin" ] || fail 'plugin obsoleto não foi removido'

# 2. Falha de tar precisa abortar o backup; não pode ser mascarada.
LOCAL_WP_CONTENT="$TMP_DIR/wp-content"
LOCAL_BACKUP_ROOT="$TMP_DIR/backups"
mkdir -p "$LOCAL_WP_CONTENT/uploads" "$TMP_DIR/failing-bin"
printf 'data\n' > "$LOCAL_WP_CONTENT/uploads/file.txt"
local_db_dump() { printf 'SQL\n'; }
tar() { return 42; }
if prepare_target_backup local "$LOCAL_BACKUP_ROOT/local/test" >/dev/null 2>&1; then
  fail 'backup continuou mesmo com tar falhando'
fi
unset -f tar

# 3. Rollback sem os dois artefatos válidos falha antes de remover qualquer dado.
rollback_dir="$TMP_DIR/rollback"
mkdir -p "$rollback_dir" "$LOCAL_WP_CONTENT/uploads"
printf 'sentinel\n' > "$LOCAL_WP_CONTENT/uploads/sentinel.txt"
# STAMP is initialized by the sourced clone script and reused by this fixture.
# shellcheck disable=SC2031
printf 'SQL\n' | gzip -c > "$rollback_dir/db-local-${STAMP}.sql.gz"
local_db_import() { cat >/dev/null; }
local_wp() { return 0; }
if rollback_target local "$rollback_dir" >/dev/null 2>&1; then
  fail 'rollback aceitou archive de arquivos ausente'
fi
[ -f "$LOCAL_WP_CONTENT/uploads/sentinel.txt" ] || fail 'rollback inválido apagou dados antes de validar artefatos'

# 4. Uma falha real após MUTATION_STARTED deve passar pelo rollback exatamente uma vez.
rollback_log="$TMP_DIR/rollback.log"
backup_dir() { printf '%s\n' "$TMP_DIR/target-backup"; }
prepare_target_backup() { mkdir -p "$2"; }
snapshot_users() { :; }
snapshot_options() { :; }
export_source_db() { printf 'SQL\n' | gzip -c > "$2"; }
import_db_to_target() { :; }
restore_users() { :; }
set_target_identity() { :; }
restore_options() { :; }
wp_exec() { printf 'mock\n'; }
remap_missing_authors() { :; }
sync_runtime_files() { :; }
enforce_smtp_plugin_policy() { :; }
clear_cache() { :; }
validate_target_after_clone() { die 'falha pós-import simulada'; }
validate_compressx_delivery() { :; }
rollback_target() { printf 'rollback\n' >> "$rollback_log"; }
SOURCE=qa
TARGET=dev
CLONE_TMP_DIR="$TMP_DIR/execution"
mkdir -p "$CLONE_TMP_DIR"
TARGET_BACKUP_DIR=''
MUTATION_STARTED=0
set +e
execute_clone_with_rollback >/dev/null 2>&1
execution_rc=$?
set -e
[ "$execution_rc" -ne 0 ] || fail 'falha pós-import retornou sucesso'
[ "$(wc -l < "$rollback_log" | tr -d ' ')" = 1 ] || fail 'rollback não ocorreu exatamente uma vez'
## 5. A senha local deve chegar ao podman pelo ambiente, nunca pelo argv.
# O mock valida cada argv antes de retornar. Assim, comandos multilinha não
# escapam da cobertura e nenhum podman/container real é chamado.
# As dublês das seções anteriores devem sair de cena para cobrir as quatro
# implementações reais do script, sem executar podman real.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
podman_mock_dir="$TMP_DIR/podman-mock-bin"
podman_capture_dir="$TMP_DIR/podman-captures"
mkdir -p "$podman_mock_dir" "$podman_capture_dir"
cat > "$podman_mock_dir/podman" <<'MOCK'
#!/usr/bin/env bash
set -u

: "${PODMAN_CAPTURE_DIR:?}"
: "${PODMAN_CALL_NAME:?}"
: "${TEST_LOCAL_DB_PASSWORD_SENTINEL:?}"

capture_dir="$PODMAN_CAPTURE_DIR"
call_name="$PODMAN_CALL_NAME"
sentinel="$TEST_LOCAL_DB_PASSWORD_SENTINEL"
argv_file="${capture_dir}/${call_name}.argv"
env_file="${capture_dir}/${call_name}.env"
stdin_file="${capture_dir}/${call_name}.stdin"
status_file="${capture_dir}/${call_name}.status"

{
  argument_number=0
  for argument in "$@"; do
    argument_number=$(( argument_number + 1 ))
    printf 'arg_%s=%q\n' "$argument_number" "$argument"
  done
} > "$argv_file"
printf '%s\n' "${MYSQL_PWD-}" > "$env_file"

mock_fail() {
  printf '%s\n' "$1" > "$status_file"
  printf 'PODMAN MOCK FAIL (%s): %s\n' "$call_name" "$1" >&2
  exit 91
}

[ "${1:-}" = exec ] || mock_fail 'comando diferente de podman exec'
shift

saw_interactive=0
saw_mysql_pwd_name=0
parsing_podman_options=1
while [ "$#" -gt 0 ]; do
  argument="$1"
  shift

  case "$argument" in
    *"MYSQL_PWD=${sentinel}"*)
      mock_fail 'argv contém MYSQL_PWD=<sentinela>'
      ;;
    *"${sentinel}"*)
      mock_fail 'argv contém a sentinela de senha'
      ;;
  esac

  if [ "$parsing_podman_options" -eq 1 ]; then
    case "$argument" in
      -i)
        saw_interactive=1
        ;;
      -e|--env)
        [ "$#" -gt 0 ] || mock_fail 'flag de ambiente sem argumento'
        if [ "$1" != MYSQL_PWD ]; then
          case "$1" in
            "MYSQL_PWD=${sentinel}") mock_fail 'argv contém MYSQL_PWD=<sentinela>' ;;
            *) mock_fail 'argv não passa somente o nome MYSQL_PWD via -e/--env' ;;
          esac
        fi
        saw_mysql_pwd_name=$(( saw_mysql_pwd_name + 1 ))
        shift
        ;;
      --env=MYSQL_PWD)
        saw_mysql_pwd_name=$(( saw_mysql_pwd_name + 1 ))
        ;;
      --env=*)
        mock_fail 'argv não passa somente o nome MYSQL_PWD via -e/--env'
        ;;
      *)
        parsing_podman_options=0
        ;;
    esac
  fi
done

[ "$saw_mysql_pwd_name" -eq 1 ] || mock_fail 'MYSQL_PWD não foi passado uma única vez por -e/--env'
[ "${MYSQL_PWD-}" = "$sentinel" ] || mock_fail 'processo pai não forneceu a sentinela no ambiente do mock'

if [ "$saw_interactive" -eq 1 ]; then
  cat > "$stdin_file"
fi
printf 'pass\n' > "$status_file"
MOCK
chmod 700 "$podman_mock_dir/podman"

assert_podman_capture() {
  local call_name="$1"
  local status_file="${podman_capture_dir}/${call_name}.status"
  local env_file="${podman_capture_dir}/${call_name}.env"
  local argv_file="${podman_capture_dir}/${call_name}.argv"

  [ -s "$argv_file" ] || fail "${call_name}: mock não capturou argv"
  [ "$(cat "$status_file")" = pass ] || fail "${call_name}: validação do mock falhou"
  [ "$(cat "$env_file")" = "$local_db_password_sentinel" ] || \
    fail "${call_name}: sentinela não chegou no ambiente do mock"
}

run_podman_mocked_call() {
  local call_name="$1"
  shift

  PODMAN_CALL_NAME="$call_name"
  export PODMAN_CALL_NAME
  if "$@" >/dev/null; then
    :
  else
    fail "${call_name}: podman mock rejeitou a chamada"
  fi
  assert_podman_capture "$call_name"
}

local_db_password_sentinel='clone-safety-password-sentinel'
LOCAL_DB_PASSWORD="$local_db_password_sentinel"
export -n LOCAL_DB_PASSWORD
export PATH="${podman_mock_dir}:$PATH"
export PODMAN_CAPTURE_DIR="$podman_capture_dir"
export TEST_LOCAL_DB_PASSWORD_SENTINEL="$local_db_password_sentinel"

run_podman_mocked_call local_db_dump local_db_dump --no-create-info
run_podman_mocked_call local_db_query local_db_query 'SELECT 1'
run_podman_mocked_call local_db_dump_options local_db_dump_options 'wpis_options' "option_name = 'siteurl'"

PODMAN_CALL_NAME=local_db_import
export PODMAN_CALL_NAME
if printf 'INSERT INTO wpis_options VALUES (1);\n' | local_db_import >/dev/null; then
  :
else
  fail 'local_db_import: podman mock rejeitou a chamada'
fi
assert_podman_capture local_db_import
[ "$(cat "${podman_capture_dir}/local_db_import.stdin")" = 'INSERT INTO wpis_options VALUES (1);' ] || \
  fail 'local_db_import: stdin não foi preservado até o mock podman'

# 6. A geração do SQL protegido local acontece dentro de uma atribuição por
# substituição de comando. Mesmo sob um `if`, a falha precisa preservar o status
# original e impedir que o cliente do banco seja iniciado.
local_option_sql_helper_log="$TMP_DIR/local-option-sql-helper.log"
local_option_sql_podman_log="$TMP_DIR/local-option-sql-podman.log"
: > "$local_option_sql_helper_log"
: > "$local_option_sql_podman_log"
option_upsert_select_sql() {
  printf 'option-upsert-select-sql\n' >> "$local_option_sql_helper_log"
  return 60
}
podman() {
  printf 'podman\n' >> "$local_option_sql_podman_log"
  return 0
}
if local_db_dump_options 'wpis_options' "option_name = 'siteurl'" >/dev/null 2>&1; then
  local_option_sql_status='0'
else
  local_option_sql_status="$?"
fi
[ "$local_option_sql_status" -eq 60 ] || fail \
  "local_db_dump_options mascarou falha de option_upsert_select_sql (esperado exit 60, obtido ${local_option_sql_status})"
[ "$(wc -l < "$local_option_sql_helper_log" | tr -d ' ')" -eq 1 ] || fail \
  'local_db_dump_options não chamou option_upsert_select_sql exatamente uma vez'
[ ! -s "$local_option_sql_podman_log" ] || fail \
  'local_db_dump_options iniciou Podman após falha de option_upsert_select_sql'
unset -f podman

# 7. A restauração local de opções protegidas deve falhar fechado. Os mocks
# representam o banco da origem já importado, com uma opção SMTP que não existe
# no snapshot do destino. Nenhum Podman ou host remoto é acessado nesta seção.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
restore_options_root="$TMP_DIR/restore-options"
restore_nonempty_dir="$restore_options_root/nonempty"
restore_empty_dir="$restore_options_root/empty"
restore_missing_sql_dir="$restore_options_root/missing-sql"
restore_missing_metadata_dir="$restore_options_root/missing-metadata"
restore_truncated_dir="$restore_options_root/truncated"
restore_incomplete_metadata_dir="$restore_options_root/incomplete-metadata"
restore_duplicate_metadata_dir="$restore_options_root/duplicate-metadata"
restore_binary_metadata_dir="$restore_options_root/binary-metadata"
restore_noncanonical_metadata_dir="$restore_options_root/noncanonical-metadata"
mkdir -p \
  "$restore_nonempty_dir" \
  "$restore_empty_dir" \
  "$restore_missing_sql_dir" \
  "$restore_missing_metadata_dir" \
  "$restore_truncated_dir" \
  "$restore_incomplete_metadata_dir" \
  "$restore_duplicate_metadata_dir" \
  "$restore_binary_metadata_dir" \
  "$restore_noncanonical_metadata_dir"
chmod 700 \
  "$restore_nonempty_dir" \
  "$restore_empty_dir" \
  "$restore_missing_sql_dir" \
  "$restore_missing_metadata_dir" \
  "$restore_truncated_dir" \
  "$restore_incomplete_metadata_dir" \
  "$restore_duplicate_metadata_dir" \
  "$restore_binary_metadata_dir" \
  "$restore_noncanonical_metadata_dir"
printf "%s\n" \
  "INSERT INTO \`wpis_options\` (\`option_name\`,\`option_value\`,\`autoload\`) VALUES ('admin_email','safe-local@example.invalid','yes') ON DUPLICATE KEY UPDATE \`option_value\`=VALUES(\`option_value\`), \`autoload\`=VALUES(\`autoload\`);" \
  > "$restore_nonempty_dir/options.sql"
: > "$restore_empty_dir/options.sql"

write_restore_options_integrity() {
  local snapshot_dir="$1"

  (
    cd "$snapshot_dir" || exit $?
    shasum -a 256 options.sql > options.sha256
  ) || return $?
  chmod 600 "$snapshot_dir/options.sql" "$snapshot_dir/options.sha256"
}

write_restore_options_integrity "$restore_nonempty_dir"
write_restore_options_integrity "$restore_empty_dir"
cp "$restore_empty_dir/options.sha256" "$restore_missing_sql_dir/options.sha256"
chmod 600 "$restore_missing_sql_dir/options.sha256"
cp "$restore_nonempty_dir/options.sql" "$restore_missing_metadata_dir/options.sql"
chmod 600 "$restore_missing_metadata_dir/options.sql"
cp "$restore_nonempty_dir/options.sql" "$restore_truncated_dir/options.sql"
write_restore_options_integrity "$restore_truncated_dir"
printf 'TRUNCATED\n' >> "$restore_truncated_dir/options.sql"
cp "$restore_nonempty_dir/options.sql" "$restore_incomplete_metadata_dir/options.sql"
printf 'incomplete\n' > "$restore_incomplete_metadata_dir/options.sha256"
chmod 600 \
  "$restore_incomplete_metadata_dir/options.sql" \
  "$restore_incomplete_metadata_dir/options.sha256"
cp "$restore_nonempty_dir/options.sql" "$restore_duplicate_metadata_dir/options.sql"
restore_checksum_line="$(cat "$restore_nonempty_dir/options.sha256")"
printf '%s\n%s' "$restore_checksum_line" "$restore_checksum_line" \
  > "$restore_duplicate_metadata_dir/options.sha256"
chmod 600 \
  "$restore_duplicate_metadata_dir/options.sql" \
  "$restore_duplicate_metadata_dir/options.sha256"
[ "$(wc -l < "$restore_duplicate_metadata_dir/options.sha256" | tr -d '[:space:]')" = 1 ] || \
  fail 'fixture duplicada não reproduz wc -l igual a um'
[ "$(grep -c '^' "$restore_duplicate_metadata_dir/options.sha256")" = 2 ] || \
  fail 'fixture duplicada não contém duas entradas lógicas'
cp "$restore_nonempty_dir/options.sql" "$restore_binary_metadata_dir/options.sql"
cp "$restore_nonempty_dir/options.sha256" "$restore_binary_metadata_dir/options.sha256"
printf '\0TRAILING' >> "$restore_binary_metadata_dir/options.sha256"
chmod 600 \
  "$restore_binary_metadata_dir/options.sql" \
  "$restore_binary_metadata_dir/options.sha256"
cp "$restore_nonempty_dir/options.sql" "$restore_noncanonical_metadata_dir/options.sql"
restore_checksum="$(cut -d' ' -f1 "$restore_nonempty_dir/options.sha256")"
printf '%s  options.sql\n' "$(printf '%s' "$restore_checksum" | tr '[:lower:]' '[:upper:]')" \
  > "$restore_noncanonical_metadata_dir/options.sha256"
chmod 600 \
  "$restore_noncanonical_metadata_dir/options.sql" \
  "$restore_noncanonical_metadata_dir/options.sha256"

RESTORE_REAL_WHERE="$(protected_options_where)"
RESTORE_FAILURES='0'
RESTORE_WHERE_STATUS='0'
RESTORE_PREFIX_STATUS='0'
RESTORE_REMOTE_PREFIX_STATUS='0'
RESTORE_DELETE_STATUS='0'
RESTORE_IMPORT_STATUS='0'
RESTORE_WP_PATH_STATUS='0'
RESTORE_WP_CLI_STATUS='0'
RESTORE_REMOTE_RUN_STATUS='0'
RESTORE_QUERY_COUNT='0'
RESTORE_IMPORT_COUNT='0'
RESTORE_REMOTE_RUN_COUNT='0'
RESTORE_REMOTE_SCRIPT=''
RESTORE_SMTP_ORIGIN_ONLY_PRESENT='1'
RESTORE_SEQUENCE=''
RESTORE_IMPORT_PAYLOAD=''
RESTORE_STATUS='0'
RESTORE_PREFIX_LOG="$restore_options_root/prefix.log"
RESTORE_REMOTE_DB_LOG="$restore_options_root/remote-db.log"
RESTORE_REMOTE_WP="$restore_options_root/mock-remote-wp"
: > "$RESTORE_PREFIX_LOG"
: > "$RESTORE_REMOTE_DB_LOG"
cat > "$RESTORE_REMOTE_WP" <<'MOCK'
#!/usr/bin/env bash
set -u

: "${RESTORE_REMOTE_DB_LOG:?}"
while [ "$#" -gt 0 ]; do
  case "$1" in
    --path=*) shift ;;
    *) break ;;
  esac
done

# O import remoto passou a resolver o cliente `mysql` em vez de chamar
# `wp db import`, que shella out e falha na Locaweb. Por isso o mock precisa
# responder também a `config get`, usado para obter as credenciais sem imprimir
# a senha em argv.
if [ "${1:-}" = config ] && [ "${2:-}" = get ]; then
  case "${3:-}" in
    DB_NAME) printf 'synthetic_db\n' ;;
    DB_USER) printf 'synthetic_user\n' ;;
    DB_HOST) printf 'synthetic_host\n' ;;
    DB_PASSWORD) printf 'synthetic_password\n' ;;
    *) exit 93 ;;
  esac
  exit 0
fi

[ "${1:-}" = db ] || exit 90

case "${2:-}" in
  prefix)
    printf 'wpis_\n'
    exit "${RESTORE_REMOTE_PREFIX_STATUS:-0}"
    ;;
  query)
    printf 'delete\n' >> "$RESTORE_REMOTE_DB_LOG"
    exit "${RESTORE_DELETE_STATUS:-0}"
    ;;
  import)
    printf 'import\n' >> "$RESTORE_REMOTE_DB_LOG"
    [ -f "${3:-}" ] || exit 91
    exit "${RESTORE_IMPORT_STATUS:-0}"
    ;;
  *)
    exit 92
    ;;
esac
MOCK
chmod 700 "$RESTORE_REMOTE_WP"

# Cliente `mysql` sintético no PATH: o import remoto o prefere ao wp-cli, então
# sem ele o teste exercitaria apenas o fallback e nunca o caminho real.
RESTORE_FAKE_BIN="$restore_options_root/bin"
mkdir -p "$RESTORE_FAKE_BIN"
cat > "$RESTORE_FAKE_BIN/mysql" <<'MOCK'
#!/usr/bin/env bash
set -u
: "${RESTORE_REMOTE_DB_LOG:?}"
printf 'import\n' >> "$RESTORE_REMOTE_DB_LOG"
cat >/dev/null
exit "${RESTORE_IMPORT_STATUS:-0}"
MOCK
chmod 700 "$RESTORE_FAKE_BIN/mysql"
PATH="$RESTORE_FAKE_BIN:$PATH"
export PATH

record_restore_failure() {
  printf 'FAIL: %s\n' "$*" >&2
  RESTORE_FAILURES=$((RESTORE_FAILURES + 1))
}

assert_restore_equal() {
  local actual="$1"
  local expected="$2"
  local message="$3"

  if [ "$actual" != "$expected" ]; then
    record_restore_failure "${message} (esperado ${expected}, obtido ${actual})"
  fi
}

reset_restore_observation() {
  RESTORE_WHERE_STATUS='0'
  RESTORE_PREFIX_STATUS='0'
  RESTORE_REMOTE_PREFIX_STATUS='0'
  RESTORE_DELETE_STATUS='0'
  RESTORE_IMPORT_STATUS='0'
  RESTORE_WP_PATH_STATUS='0'
  RESTORE_WP_CLI_STATUS='0'
  RESTORE_REMOTE_RUN_STATUS='0'
  RESTORE_QUERY_COUNT='0'
  RESTORE_IMPORT_COUNT='0'
  RESTORE_REMOTE_RUN_COUNT='0'
  RESTORE_REMOTE_SCRIPT=''
  RESTORE_SMTP_ORIGIN_ONLY_PRESENT='1'
  RESTORE_SEQUENCE=''
  RESTORE_IMPORT_PAYLOAD=''
  RESTORE_STATUS='0'
  : > "$RESTORE_PREFIX_LOG"
  : > "$RESTORE_REMOTE_DB_LOG"
}

append_restore_sequence() {
  if [ -n "$RESTORE_SEQUENCE" ]; then
    RESTORE_SEQUENCE="${RESTORE_SEQUENCE}:$1"
  else
    RESTORE_SEQUENCE="$1"
  fi
}

log() { :; }
is_remote_env() { [ "$1" != local ]; }
protected_options_where() {
  printf '%s\n' "$RESTORE_REAL_WHERE"
  return "$RESTORE_WHERE_STATUS"
}
local_db_prefix() {
  printf 'prefix\n' >> "$RESTORE_PREFIX_LOG"
  printf 'wpis_\n'
  return "$RESTORE_PREFIX_STATUS"
}
local_db_query() {
  RESTORE_QUERY_COUNT=$((RESTORE_QUERY_COUNT + 1))
  case "$1" in
    *'DELETE FROM wpis_options WHERE '*) ;;
    *) fail 'restore_options local emitiu DELETE inesperado' ;;
  esac
  case "$1" in
    *"option_name LIKE '%smtp%'"*) ;;
    *) fail 'DELETE de opções protegidas não inclui a família SMTP' ;;
  esac
  if [ "$RESTORE_DELETE_STATUS" -ne 0 ]; then
    return "$RESTORE_DELETE_STATUS"
  fi
  RESTORE_SMTP_ORIGIN_ONLY_PRESENT='0'
  append_restore_sequence delete
  return 0
}
local_db_import() {
  RESTORE_IMPORT_PAYLOAD="$(cat)"
  RESTORE_IMPORT_COUNT=$((RESTORE_IMPORT_COUNT + 1))
  append_restore_sequence import
  return "$RESTORE_IMPORT_STATUS"
}
wp_path() {
  printf '%s\n' "$restore_options_root/remote-wp"
  return "$RESTORE_WP_PATH_STATUS"
}
wp_cli_shell() {
  printf '%q\n' "$RESTORE_REMOTE_WP"
  return "$RESTORE_WP_CLI_STATUS"
}
remote_run() {
  RESTORE_REMOTE_RUN_COUNT=$((RESTORE_REMOTE_RUN_COUNT + 1))
  RESTORE_REMOTE_SCRIPT="$2"
  if [ "$RESTORE_REMOTE_RUN_STATUS" -ne 0 ]; then
    return "$RESTORE_REMOTE_RUN_STATUS"
  fi
  RESTORE_REMOTE_DB_LOG="$RESTORE_REMOTE_DB_LOG" \
  RESTORE_REMOTE_PREFIX_STATUS="$RESTORE_REMOTE_PREFIX_STATUS" \
  RESTORE_DELETE_STATUS="$RESTORE_DELETE_STATUS" \
  RESTORE_IMPORT_STATUS="$RESTORE_IMPORT_STATUS" \
    bash -c "$2"
}

run_restore_options_case() {
  local environment="$1"
  local snapshot_dir="$2"

  if restore_options "$environment" "$snapshot_dir" >/dev/null 2>&1; then
    RESTORE_STATUS='0'
  else
    RESTORE_STATUS="$?"
  fi
}

# Um options.sha256 concluído não torna legítimo um options.sql ausente. A
# validação precisa ocorrer antes de consultar o prefixo ou executar o DELETE.
reset_restore_observation
run_restore_options_case local "$restore_missing_sql_dir"
assert_restore_equal "$RESTORE_STATUS" 1 \
  'restore_options aceitou options.sql local ausente'
assert_restore_equal "$RESTORE_QUERY_COUNT" 0 \
  'restore_options executou DELETE com options.sql local ausente'
assert_restore_equal "$RESTORE_IMPORT_COUNT" 0 \
  'restore_options tentou importar options.sql local ausente'
restore_prefix_count="$(wc -l < "$RESTORE_PREFIX_LOG" | tr -d ' ')"
assert_restore_equal "$restore_prefix_count" 0 \
  'restore_options consultou prefixo antes de validar options.sql local'

reset_restore_observation
run_restore_options_case qa "$restore_missing_sql_dir"
assert_restore_equal "$RESTORE_STATUS" 1 \
  'restore_options remoto aceitou options.sql ausente'
assert_restore_equal "$RESTORE_REMOTE_RUN_COUNT" 1 \
  'restore_options remoto não validou o snapshot no shell remoto'
assert_restore_equal "$(tr '\n' ':' < "$RESTORE_REMOTE_DB_LOG")" '' \
  'restore_options remoto consultou ou alterou o banco com options.sql ausente'

assert_invalid_restore_options_snapshot() {
  local environment="$1"
  local snapshot_dir="$2"
  local description="$3"

  reset_restore_observation
  run_restore_options_case "$environment" "$snapshot_dir"
  assert_restore_equal "$RESTORE_STATUS" 1 \
    "restore_options aceitou snapshot ${description} em ${environment}"
  assert_restore_equal "$RESTORE_QUERY_COUNT" 0 \
    "restore_options executou DELETE local com snapshot ${description}"
  assert_restore_equal "$RESTORE_IMPORT_COUNT" 0 \
    "restore_options importou snapshot local ${description}"
  assert_restore_equal "$(tr '\n' ':' < "$RESTORE_REMOTE_DB_LOG")" '' \
    "restore_options acessou banco remoto com snapshot ${description}"
}

assert_invalid_restore_options_snapshot local "$restore_missing_metadata_dir" 'sem metadado'
assert_invalid_restore_options_snapshot local "$restore_truncated_dir" 'truncado'
assert_invalid_restore_options_snapshot local "$restore_incomplete_metadata_dir" 'com metadado incompleto'
assert_invalid_restore_options_snapshot local "$restore_duplicate_metadata_dir" 'com duas entradas'
assert_invalid_restore_options_snapshot local "$restore_binary_metadata_dir" 'com bytes binários finais'
assert_invalid_restore_options_snapshot local "$restore_noncanonical_metadata_dir" 'com checksum não canônico'
assert_invalid_restore_options_snapshot qa "$restore_missing_metadata_dir" 'sem metadado'
assert_invalid_restore_options_snapshot qa "$restore_truncated_dir" 'truncado'
assert_invalid_restore_options_snapshot qa "$restore_incomplete_metadata_dir" 'com metadado incompleto'
assert_invalid_restore_options_snapshot qa "$restore_duplicate_metadata_dir" 'com duas entradas'
assert_invalid_restore_options_snapshot qa "$restore_binary_metadata_dir" 'com bytes binários finais'
assert_invalid_restore_options_snapshot qa "$restore_noncanonical_metadata_dir" 'com checksum não canônico'

# RED principal: o DELETE local falha com 52 e nenhum snapshot pode ser
# importado, independentemente de options.sql conter dados ou estar vazio.
reset_restore_observation
RESTORE_DELETE_STATUS='52'
run_restore_options_case local "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 52 \
  'restore_options mascarou falha do DELETE com options.sql não vazio'
assert_restore_equal "$RESTORE_IMPORT_COUNT" 0 \
  'restore_options importou options.sql após falha do DELETE'

reset_restore_observation
RESTORE_DELETE_STATUS='52'
run_restore_options_case local "$restore_empty_dir"
assert_restore_equal "$RESTORE_STATUS" 52 \
  'restore_options mascarou falha do DELETE com options.sql vazio'
assert_restore_equal "$RESTORE_IMPORT_COUNT" 0 \
  'restore_options invocou import após falha do DELETE com snapshot vazio'

# Status irmãos também devem permanecer explícitos quando restore_options roda
# dentro do boundary condicional que suprime errexit.
reset_restore_observation
RESTORE_WHERE_STATUS='49'
run_restore_options_case local "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 49 \
  'restore_options mascarou falha ao construir o filtro protegido'
restore_prefix_count="$(wc -l < "$RESTORE_PREFIX_LOG" | tr -d ' ')"
assert_restore_equal "$restore_prefix_count" 0 \
  'restore_options consultou prefixo após falha no filtro protegido'

reset_restore_observation
RESTORE_PREFIX_STATUS='50'
run_restore_options_case local "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 50 \
  'restore_options mascarou falha ao consultar prefixo local'
assert_restore_equal "$RESTORE_QUERY_COUNT" 0 \
  'restore_options executou DELETE após falha no prefixo local'

reset_restore_observation
RESTORE_IMPORT_STATUS='53'
run_restore_options_case local "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 53 \
  'restore_options mascarou falha do import local'

reset_restore_observation
RESTORE_WP_PATH_STATUS='54'
run_restore_options_case qa "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 54 \
  'restore_options remoto mascarou falha ao resolver wp_path'
assert_restore_equal "$RESTORE_REMOTE_RUN_COUNT" 0 \
  'restore_options chamou remote_run após falha de wp_path'

reset_restore_observation
RESTORE_WP_CLI_STATUS='55'
run_restore_options_case qa "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 55 \
  'restore_options remoto mascarou falha ao resolver WP-CLI'
assert_restore_equal "$RESTORE_REMOTE_RUN_COUNT" 0 \
  'restore_options chamou remote_run após falha de WP-CLI'

reset_restore_observation
RESTORE_REMOTE_RUN_STATUS='56'
run_restore_options_case qa "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 56 \
  'restore_options remoto mascarou falha de remote_run'

reset_restore_observation
RESTORE_REMOTE_PREFIX_STATUS='59'
run_restore_options_case qa "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 59 \
  'restore_options remoto mascarou falha do prefixo no shell remoto'
assert_restore_equal "$(tr '\n' ':' < "$RESTORE_REMOTE_DB_LOG")" '' \
  'restore_options remoto executou DELETE após falha do prefixo'

reset_restore_observation
RESTORE_DELETE_STATUS='52'
run_restore_options_case qa "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 52 \
  'restore_options remoto mascarou falha do DELETE'
assert_restore_equal "$(tr '\n' ':' < "$RESTORE_REMOTE_DB_LOG")" 'delete:' \
  'restore_options remoto importou após falha do DELETE'

reset_restore_observation
RESTORE_IMPORT_STATUS='53'
run_restore_options_case qa "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 53 \
  'restore_options remoto mascarou falha do import'

# Caminhos saudáveis e prova semântica: o DELETE SMTP precede qualquer import;
# como o snapshot do destino não contém a opção SMTP exclusiva da origem, ela
# não pode sobreviver a uma restauração local considerada bem-sucedida.
reset_restore_observation
run_restore_options_case local "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 0 \
  'restore_options rejeitou snapshot local não vazio saudável'
assert_restore_equal "$RESTORE_QUERY_COUNT" 1 \
  'restore_options não executou exatamente um DELETE saudável'
assert_restore_equal "$RESTORE_IMPORT_COUNT" 1 \
  'restore_options não importou exatamente uma vez o snapshot saudável'
assert_restore_equal "$RESTORE_SEQUENCE" 'delete:import' \
  'restore_options não garantiu DELETE antes do import'
assert_restore_equal "$RESTORE_SMTP_ORIGIN_ONLY_PRESENT" 0 \
  'opção SMTP exclusiva da origem sobreviveu ao restore_options bem-sucedido'
case "$RESTORE_IMPORT_PAYLOAD" in
  *admin_email*) ;;
  *) record_restore_failure 'snapshot saudável não chegou ao import local' ;;
esac
case "$RESTORE_IMPORT_PAYLOAD" in
  *smtp_origin_only*)
    record_restore_failure 'fixture do destino reintroduziu indevidamente a opção SMTP exclusiva da origem'
    ;;
esac

reset_restore_observation
run_restore_options_case local "$restore_empty_dir"
assert_restore_equal "$RESTORE_STATUS" 0 \
  'restore_options rejeitou snapshot local vazio legítimo'
assert_restore_equal "$RESTORE_QUERY_COUNT" 1 \
  'restore_options não removeu opções protegidas com snapshot vazio'
assert_restore_equal "$RESTORE_IMPORT_COUNT" 0 \
  'restore_options importou snapshot local vazio'
assert_restore_equal "$RESTORE_SEQUENCE" delete \
  'restore_options vazio não terminou após o DELETE saudável'
assert_restore_equal "$RESTORE_SMTP_ORIGIN_ONLY_PRESENT" 0 \
  'opção SMTP exclusiva da origem sobreviveu ao restore vazio bem-sucedido'

reset_restore_observation
run_restore_options_case qa "$restore_nonempty_dir"
assert_restore_equal "$RESTORE_STATUS" 0 \
  'restore_options rejeitou snapshot remoto não vazio saudável'
assert_restore_equal "$(tr '\n' ':' < "$RESTORE_REMOTE_DB_LOG")" 'delete:import:' \
  'restore_options remoto saudável não executou DELETE e import exatamente uma vez'

reset_restore_observation
run_restore_options_case qa "$restore_empty_dir"
assert_restore_equal "$RESTORE_STATUS" 0 \
  'restore_options rejeitou snapshot remoto vazio concluído'
assert_restore_equal "$(tr '\n' ':' < "$RESTORE_REMOTE_DB_LOG")" 'delete:' \
  'restore_options remoto vazio não terminou após o DELETE saudável'
case "$RESTORE_REMOTE_SCRIPT" in
  *'db prefix)" || exit $?'*) ;;
  *) record_restore_failure 'restore_options remoto não propaga explicitamente falha do prefixo' ;;
esac
# The assertion matches literal remote-shell variables, not local expansions.
# shellcheck disable=SC2016
case "$RESTORE_REMOTE_SCRIPT" in
  *'db query "$delete_sql" >/dev/null || exit $?'*) ;;
  *) record_restore_failure 'restore_options remoto não propaga explicitamente falha do DELETE' ;;
esac
# A assertiva casa variáveis do shell remoto, não expansões locais.
# O import agora resolve o cliente `mysql` em vez de chamar `wp db import`, que
# shella out e falha na Locaweb; o que importa é que a falha continue propagada
# explicitamente com `|| exit $?`.
# shellcheck disable=SC2016
case "$RESTORE_REMOTE_SCRIPT" in
  *'"$options_sql"'*' || exit $?'*) ;;
  *) record_restore_failure 'restore_options remoto não propaga explicitamente falha do import' ;;
esac
printf '%s' "$RESTORE_REMOTE_SCRIPT" | grep -q 'mysql_bin' \
  || record_restore_failure 'restore_options remoto não resolve o cliente mysql (wp db import falha na Locaweb)'

# Boundary real pós-mutation: corrupção após a conclusão do snapshot deve
# preservar o status da validação, fazer rollback uma vez e não alcançar nenhum
# passo posterior, resumo ou log de conclusão.
boundary_root="$restore_options_root/boundary"
boundary_rollback_log="$boundary_root/rollback.log"
boundary_post_restore_log="$boundary_root/post-restore.log"
boundary_clone_log="$boundary_root/clone.log"
mkdir -p "$boundary_root"
: > "$boundary_rollback_log"
: > "$boundary_post_restore_log"
: > "$boundary_clone_log"

log() { printf '%s\n' "$*" >> "$boundary_clone_log"; }
backup_dir() { printf '%s\n' "$boundary_root/backup"; }
env_url() { printf 'https://%s.example.invalid\n' "$1"; }
env_title() { printf 'Mock %s\n' "$1"; }
prepare_target_backup() { mkdir -p "$2"; }
snapshot_users() { :; }
snapshot_options() {
  cp "$restore_nonempty_dir/options.sql" "$2/options.sql"
  write_restore_options_integrity "$2"
  printf 'TRUNCATED\n' >> "$2/options.sql"
}
export_source_db() { printf 'SQL\n' | gzip -c > "$2"; }
import_db_to_target() { :; }
restore_users() { :; }
set_target_identity() { :; }
wp_exec() { printf 'wp_exec\n' >> "$boundary_post_restore_log"; }
remap_missing_authors() { printf 'remap\n' >> "$boundary_post_restore_log"; }
sync_runtime_files() { printf 'sync\n' >> "$boundary_post_restore_log"; }
enforce_smtp_plugin_policy() { printf 'smtp-policy\n' >> "$boundary_post_restore_log"; }
clear_cache() { printf 'cache\n' >> "$boundary_post_restore_log"; }
validate_target_after_clone() { printf 'validate\n' >> "$boundary_post_restore_log"; }
validate_compressx_delivery() { printf 'compressx\n' >> "$boundary_post_restore_log"; }
rollback_target() { printf 'rollback\n' >> "$boundary_rollback_log"; }

reset_restore_observation
RESTORE_DELETE_STATUS='0'
SOURCE='qa'
TARGET='local'
CLONE_MODE='execute'
REPLACE_USERS='0'
CLONE_TMP_DIR="$boundary_root/execution"
TARGET_BACKUP_DIR=''
MUTATION_STARTED='0'
ROLLBACK_RUNNING='0'
UONIX_CLONE_SUMMARY_FILE="$boundary_root/summary.txt"
mkdir -p "$CLONE_TMP_DIR"
rm -f "$UONIX_CLONE_SUMMARY_FILE"
# Globais consumidas indiretamente pelo boundary carregado do script de clone.
: "$SOURCE" "$TARGET" "$CLONE_MODE" "$REPLACE_USERS" \
  "$TARGET_BACKUP_DIR" "$MUTATION_STARTED" "$ROLLBACK_RUNNING"
if execute_clone_with_rollback >/dev/null 2>&1; then
  boundary_restore_status='0'
else
  boundary_restore_status="$?"
fi
assert_restore_equal "$boundary_restore_status" 1 \
  'boundary mascarou falha de integridade do snapshot pós-mutation'
boundary_rollback_count="$(wc -l < "$boundary_rollback_log" | tr -d ' ')"
assert_restore_equal "$boundary_rollback_count" 1 \
  'boundary não acionou rollback exatamente uma vez após snapshot inválido'
assert_restore_equal "$RESTORE_QUERY_COUNT" 0 \
  'boundary executou DELETE protegido com snapshot inválido'
assert_restore_equal "$RESTORE_IMPORT_COUNT" 0 \
  'boundary importou snapshot inválido'
if [ -s "$boundary_post_restore_log" ]; then
  record_restore_failure 'boundary continuou para passos posteriores após snapshot inválido'
fi
if [ -e "$UONIX_CLONE_SUMMARY_FILE" ]; then
  record_restore_failure 'boundary escreveu resumo de sucesso após snapshot inválido'
fi
if grep -Fq 'Clone concluído:' "$boundary_clone_log"; then
  record_restore_failure 'boundary registrou clone concluído após snapshot inválido'
fi

# 8. Uma falha ao obter o prefixo durante o snapshot sensível acontece antes da
# mutação. O status original deve chegar ao boundary sem dump, import, rollback
# ou qualquer sinal de conclusão bem-sucedida.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
snapshot_root="$TMP_DIR/snapshot-options"
snapshot_boundary_root="$snapshot_root/boundary"
snapshot_call_log="$snapshot_root/calls.log"
snapshot_boundary_log="$snapshot_boundary_root/steps.log"
snapshot_clone_log="$snapshot_boundary_root/clone.log"
snapshot_rollback_log="$snapshot_boundary_root/rollback.log"
mkdir -p "$snapshot_boundary_root"
: > "$snapshot_call_log"
: > "$snapshot_boundary_log"
: > "$snapshot_clone_log"
: > "$snapshot_rollback_log"

SNAPSHOT_FAILURES='0'
SNAPSHOT_WHERE_STATUS='0'
SNAPSHOT_PREFIX_STATUS='0'
SNAPSHOT_DUMP_STATUS='0'
SNAPSHOT_WP_PATH_STATUS='0'
SNAPSHOT_WP_CLI_STATUS='0'
SNAPSHOT_WP_PREFIX_STATUS='0'
SNAPSHOT_SQL_STATUS='0'
SNAPSHOT_REMOTE_STATUS='0'
SNAPSHOT_DUMP_COUNT='0'
SNAPSHOT_REMOTE_COUNT='0'
SNAPSHOT_REMOTE_SCRIPT=''
SNAPSHOT_REAL_WHERE="option_name LIKE '%smtp%'"

record_snapshot_failure() {
  printf 'FAIL: %s\n' "$*" >&2
  SNAPSHOT_FAILURES=$((SNAPSHOT_FAILURES + 1))
}

assert_snapshot_equal() {
  local actual="$1"
  local expected="$2"
  local message="$3"

  if [ "$actual" != "$expected" ]; then
    record_snapshot_failure "${message} (esperado ${expected}, obtido ${actual})"
  fi
}

reset_snapshot_observation() {
  SNAPSHOT_WHERE_STATUS='0'
  SNAPSHOT_PREFIX_STATUS='0'
  SNAPSHOT_DUMP_STATUS='0'
  SNAPSHOT_WP_PATH_STATUS='0'
  SNAPSHOT_WP_CLI_STATUS='0'
  SNAPSHOT_WP_PREFIX_STATUS='0'
  SNAPSHOT_SQL_STATUS='0'
  SNAPSHOT_REMOTE_STATUS='0'
  SNAPSHOT_DUMP_COUNT='0'
  SNAPSHOT_REMOTE_COUNT='0'
  SNAPSHOT_REMOTE_SCRIPT=''
  : > "$snapshot_call_log"
}

run_snapshot_options_case() {
  local environment="$1"
  local snapshot_dir="$2"

  if snapshot_options "$environment" "$snapshot_dir" >/dev/null 2>&1; then
    SNAPSHOT_STATUS='0'
  else
    SNAPSHOT_STATUS="$?"
  fi
}

snapshot_file_mode() {
  if stat -f '%Lp' "$1" >/dev/null 2>&1; then
    stat -f '%Lp' "$1"
  else
    stat -c '%a' "$1"
  fi
}

log() { printf '%s\n' "$*" >> "$snapshot_clone_log"; }
is_remote_env() { [ "$1" != local ]; }
protected_options_where() {
  printf 'where\n' >> "$snapshot_call_log"
  printf '%s\n' "$SNAPSHOT_REAL_WHERE"
  return "$SNAPSHOT_WHERE_STATUS"
}
local_db_prefix() {
  printf 'prefix\n' >> "$snapshot_call_log"
  printf 'wpis_\n'
  return "$SNAPSHOT_PREFIX_STATUS"
}
local_db_dump_options() {
  SNAPSHOT_DUMP_COUNT=$((SNAPSHOT_DUMP_COUNT + 1))
  printf 'dump\n' >> "$snapshot_call_log"
  printf 'SELECT 1;\n'
  return "$SNAPSHOT_DUMP_STATUS"
}
wp_path() {
  printf 'wp-path\n' >> "$snapshot_call_log"
  printf '/mock/wp\n'
  return "$SNAPSHOT_WP_PATH_STATUS"
}
wp_cli_shell() {
  printf 'wp-cli\n' >> "$snapshot_call_log"
  printf 'wp\n'
  return "$SNAPSHOT_WP_CLI_STATUS"
}
wp_exec() {
  [ "${2:-}" = db ] && [ "${3:-}" = prefix ] || fail "snapshot wp_exec inesperado: $*"
  printf 'wp-prefix\n' >> "$snapshot_call_log"
  printf 'wp_\n'
  return "$SNAPSHOT_WP_PREFIX_STATUS"
}
option_upsert_select_sql() {
  printf 'sql\n' >> "$snapshot_call_log"
  printf 'SELECT 1;\n'
  return "$SNAPSHOT_SQL_STATUS"
}
remote_run() {
  SNAPSHOT_REMOTE_COUNT=$((SNAPSHOT_REMOTE_COUNT + 1))
  SNAPSHOT_REMOTE_SCRIPT="$2"
  printf 'remote\n' >> "$snapshot_call_log"
  return "$SNAPSHOT_REMOTE_STATUS"
}

reset_snapshot_observation
SNAPSHOT_PREFIX_STATUS='50'
run_snapshot_options_case local "$snapshot_root/direct"
assert_snapshot_equal "$SNAPSHOT_STATUS" 50 \
  'snapshot_options mascarou falha ao consultar prefixo local'
assert_snapshot_equal "$SNAPSHOT_DUMP_COUNT" 0 \
  'snapshot_options iniciou dump após falha no prefixo local'

reset_snapshot_observation
SNAPSHOT_WHERE_STATUS='49'
run_snapshot_options_case local "$snapshot_root/where-failure"
assert_snapshot_equal "$SNAPSHOT_STATUS" 49 \
  'snapshot_options mascarou falha ao construir filtro protegido'
assert_snapshot_equal "$(tr '\n' ':' < "$snapshot_call_log")" 'where:' \
  'snapshot_options chamou helper após falha no filtro protegido'

reset_snapshot_observation
SNAPSHOT_DUMP_STATUS='51'
run_snapshot_options_case local "$snapshot_root/dump-failure"
assert_snapshot_equal "$SNAPSHOT_STATUS" 51 \
  'snapshot_options mascarou falha do dump local'

reset_snapshot_observation
snapshot_local_healthy_dir="$snapshot_root/local-healthy"
previous_umask="$(umask)"
umask 022
run_snapshot_options_case local "$snapshot_local_healthy_dir"
umask "$previous_umask"
assert_snapshot_equal "$SNAPSHOT_STATUS" 0 \
  'snapshot_options rejeitou caminho local saudável'
assert_snapshot_equal "$(snapshot_file_mode "$snapshot_local_healthy_dir")" 700 \
  'snapshot local deixou diretório sensível fora do modo privado 0700'
assert_snapshot_equal "$(snapshot_file_mode "$snapshot_local_healthy_dir/options.sql")" 600 \
  'snapshot local deixou options.sql fora do modo privado 0600'
[ -f "$snapshot_local_healthy_dir/options.sha256" ] || \
  record_snapshot_failure 'snapshot local não publicou evidência de integridade options.sha256'
if [ -f "$snapshot_local_healthy_dir/options.sha256" ]; then
  assert_snapshot_equal "$(snapshot_file_mode "$snapshot_local_healthy_dir/options.sha256")" 600 \
    'snapshot local deixou options.sha256 fora do modo privado 0600'
  if ! (cd "$snapshot_local_healthy_dir" && shasum -a 256 -c options.sha256 >/dev/null); then
    record_snapshot_failure 'snapshot local publicou options.sha256 inválido'
  fi
fi

reset_snapshot_observation
SNAPSHOT_WP_PATH_STATUS='54'
run_snapshot_options_case qa "$snapshot_root/remote-path-failure"
assert_snapshot_equal "$SNAPSHOT_STATUS" 54 \
  'snapshot_options remoto mascarou falha ao resolver wp_path'
assert_snapshot_equal "$(tr '\n' ':' < "$snapshot_call_log")" 'where:wp-path:' \
  'snapshot_options remoto chamou helper após falha de wp_path'

reset_snapshot_observation
SNAPSHOT_WP_CLI_STATUS='55'
run_snapshot_options_case qa "$snapshot_root/remote-cli-failure"
assert_snapshot_equal "$SNAPSHOT_STATUS" 55 \
  'snapshot_options remoto mascarou falha ao resolver WP-CLI'
assert_snapshot_equal "$(tr '\n' ':' < "$snapshot_call_log")" 'where:wp-path:wp-cli:' \
  'snapshot_options remoto chamou helper após falha de WP-CLI'

reset_snapshot_observation
SNAPSHOT_WP_PREFIX_STATUS='56'
run_snapshot_options_case qa "$snapshot_root/remote-prefix-failure"
assert_snapshot_equal "$SNAPSHOT_STATUS" 56 \
  'snapshot_options remoto mascarou falha ao consultar prefixo remoto'
assert_snapshot_equal "$(tr '\n' ':' < "$snapshot_call_log")" 'where:wp-path:wp-cli:wp-prefix:' \
  'snapshot_options remoto gerou SQL após falha no prefixo'

reset_snapshot_observation
SNAPSHOT_SQL_STATUS='57'
run_snapshot_options_case qa "$snapshot_root/remote-sql-failure"
assert_snapshot_equal "$SNAPSHOT_STATUS" 57 \
  'snapshot_options remoto mascarou falha ao gerar SQL protegido'
assert_snapshot_equal "$SNAPSHOT_REMOTE_COUNT" 0 \
  'snapshot_options chamou remote_run após falha na geração SQL'

reset_snapshot_observation
SNAPSHOT_REMOTE_STATUS='58'
run_snapshot_options_case qa "$snapshot_root/remote-run-failure"
assert_snapshot_equal "$SNAPSHOT_STATUS" 58 \
  'snapshot_options remoto mascarou falha de remote_run'

reset_snapshot_observation
run_snapshot_options_case qa "$snapshot_root/remote-healthy"
assert_snapshot_equal "$SNAPSHOT_STATUS" 0 \
  'snapshot_options rejeitou caminho remoto saudável'
case "$SNAPSHOT_REMOTE_SCRIPT" in
  *'umask 077'*) ;;
  *) record_snapshot_failure 'snapshot remoto não aplica umask 077 no shell separado' ;;
esac
case "$SNAPSHOT_REMOTE_SCRIPT" in
  *'chmod 700 '*'snapshot-options/remote-healthy'*) ;;
  *) record_snapshot_failure 'snapshot remoto não força diretório sensível para 0700' ;;
esac
# The assertion matches literal remote-shell paths and variables.
# shellcheck disable=SC2016
case "$SNAPSHOT_REMOTE_SCRIPT" in
  *'options_sql='*'options.sql'*'chmod 600 "$options_sql"'*) ;;
  *) record_snapshot_failure 'snapshot remoto não força options.sql para 0600' ;;
esac
# The assertion matches literal remote-shell paths and variables.
# shellcheck disable=SC2016
case "$SNAPSHOT_REMOTE_SCRIPT" in
  *'options_sha256='*'options.sha256'*'sha256sum options.sql > options.sha256'*'chmod 600 "$options_sql" "$options_sha256"'*) ;;
  *) record_snapshot_failure 'snapshot remoto não publica options.sha256 privado após o dump' ;;
esac

backup_dir() { printf '%s\n' "$snapshot_boundary_root/backup"; }
env_url() { printf 'https://%s.example.invalid\n' "$1"; }
env_title() { printf 'Mock %s\n' "$1"; }
prepare_target_backup() { mkdir -p "$2"; }
snapshot_users() { :; }
export_source_db() { printf 'export\n' >> "$snapshot_boundary_log"; }
import_db_to_target() { printf 'import\n' >> "$snapshot_boundary_log"; }
restore_users() { printf 'restore-users\n' >> "$snapshot_boundary_log"; }
set_target_identity() { printf 'identity\n' >> "$snapshot_boundary_log"; }
restore_options() { printf 'restore-options\n' >> "$snapshot_boundary_log"; }
wp_exec() { printf 'wp-exec\n' >> "$snapshot_boundary_log"; }
remap_missing_authors() { printf 'remap\n' >> "$snapshot_boundary_log"; }
sync_runtime_files() { printf 'sync\n' >> "$snapshot_boundary_log"; }
enforce_smtp_plugin_policy() { printf 'smtp-policy\n' >> "$snapshot_boundary_log"; }
clear_cache() { printf 'cache\n' >> "$snapshot_boundary_log"; }
validate_target_after_clone() { printf 'validate\n' >> "$snapshot_boundary_log"; }
validate_compressx_delivery() { printf 'compressx\n' >> "$snapshot_boundary_log"; }
rollback_target() { printf 'rollback\n' >> "$snapshot_rollback_log"; }

: > "$snapshot_call_log"
: > "$snapshot_boundary_log"
: > "$snapshot_clone_log"
: > "$snapshot_rollback_log"
SNAPSHOT_DUMP_COUNT='0'
SNAPSHOT_PREFIX_STATUS='50'
SOURCE='qa'
TARGET='local'
CLONE_MODE='execute'
REPLACE_USERS='0'
CLONE_TMP_DIR="$snapshot_boundary_root/execution"
TARGET_BACKUP_DIR=''
MUTATION_STARTED='0'
ROLLBACK_RUNNING='0'
UONIX_CLONE_SUMMARY_FILE="$snapshot_boundary_root/summary.txt"
mkdir -p "$CLONE_TMP_DIR"
rm -f "$UONIX_CLONE_SUMMARY_FILE"
: "$SOURCE" "$TARGET" "$CLONE_MODE" "$REPLACE_USERS" \
  "$TARGET_BACKUP_DIR" "$MUTATION_STARTED" "$ROLLBACK_RUNNING"
if execute_clone_with_rollback >/dev/null 2>&1; then
  snapshot_boundary_status='0'
else
  snapshot_boundary_status="$?"
fi
assert_snapshot_equal "$snapshot_boundary_status" 50 \
  'boundary mascarou falha do snapshot sensível pré-mutation'
assert_snapshot_equal "$MUTATION_STARTED" 0 \
  'boundary marcou mutação após falha do snapshot sensível'
assert_snapshot_equal "$SNAPSHOT_DUMP_COUNT" 0 \
  'boundary iniciou dump após falha no prefixo do snapshot'
if [ -s "$snapshot_boundary_log" ]; then
  record_snapshot_failure 'boundary chamou passos posteriores após falha do snapshot sensível'
fi
if [ -s "$snapshot_rollback_log" ]; then
  record_snapshot_failure 'boundary chamou rollback apesar de a mutação não ter começado'
fi
if [ -e "$UONIX_CLONE_SUMMARY_FILE" ]; then
  record_snapshot_failure 'boundary escreveu resumo de sucesso após falha pré-mutation'
fi
if grep -Fq 'Clone concluído:' "$snapshot_clone_log"; then
  record_snapshot_failure 'boundary registrou clone concluído após falha pré-mutation'
fi

# 9. O preflight obrigatório da origem roda dentro do OR-list de run_clone.
# Uma falha precisa atravessar dry_run_clone com o status original e impedir
# tanto o preflight seguinte quanto qualquer execução/mutação.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
preflight_call_log="$TMP_DIR/preflight-calls.log"
: > "$preflight_call_log"

log() { :; }
env_url() { printf 'https://%s.example.invalid\n' "$1"; }
preflight_env() {
  printf 'preflight:%s\n' "$1" >> "$preflight_call_log"
  if [ "$1" = qa ]; then
    return 71
  fi
  return 0
}
execute_clone_with_rollback() { printf 'mutation\n' >> "$preflight_call_log"; }
write_clone_summary() { printf 'summary\n' >> "$preflight_call_log"; }

SOURCE='qa'
TARGET='dev'
CLONE_MODE='execute'
INCLUDE_GIT_FILES='0'
PRESERVE_DESTINATION_USERS='1'

if dry_run_clone >/dev/null 2>&1; then
  preflight_dry_run_status='0'
else
  preflight_dry_run_status="$?"
fi
[ "$preflight_dry_run_status" -eq 71 ] || fail \
  "dry_run_clone mascarou falha do preflight de origem (esperado 71, obtido ${preflight_dry_run_status})"
[ "$(tr '\n' ':' < "$preflight_call_log")" = 'preflight:qa:' ] || fail \
  'dry_run_clone avançou após falha do preflight de origem'

: > "$preflight_call_log"
if run_clone >/dev/null 2>&1; then
  preflight_run_clone_status='0'
else
  preflight_run_clone_status="$?"
fi
[ "$preflight_run_clone_status" -eq 71 ] || fail \
  "run_clone --execute mascarou falha do preflight de origem (esperado 71, obtido ${preflight_run_clone_status})"
[ "$(tr '\n' ':' < "$preflight_call_log")" = 'preflight:qa:' ] || fail \
  'run_clone --execute alcançou preflight posterior ou mutação após falha 71'

# O segundo preflight é igualmente obrigatório e não pode ser convertido em
# sucesso pelos logs finais de dry_run_clone.
preflight_env() {
  printf 'preflight:%s\n' "$1" >> "$preflight_call_log"
  if [ "$1" = dev ]; then
    return 72
  fi
  return 0
}
: > "$preflight_call_log"
if dry_run_clone >/dev/null 2>&1; then
  target_preflight_status='0'
else
  target_preflight_status="$?"
fi
[ "$target_preflight_status" -eq 72 ] || fail \
  "dry_run_clone mascarou falha do preflight de destino (esperado 72, obtido ${target_preflight_status})"
[ "$(tr '\n' ':' < "$preflight_call_log")" = 'preflight:qa:preflight:dev:' ] || fail \
  'dry_run_clone avançou após falha do preflight de destino'

# A URL da origem é configuração obrigatória do dry-run. A substituição de
# comando dentro de log não pode esconder sua falha nem abrir preflight remoto.
env_url() {
  printf 'url:%s\n' "$1" >> "$preflight_call_log"
  if [ "$1" = qa ]; then
    return 67
  fi
  printf 'https://%s.example.invalid\n' "$1"
}
preflight_env() { printf 'preflight:%s\n' "$1" >> "$preflight_call_log"; }
: > "$preflight_call_log"
if dry_run_clone >/dev/null 2>&1; then
  source_url_status='0'
else
  source_url_status="$?"
fi
[ "$source_url_status" -eq 67 ] || fail \
  "dry_run_clone mascarou falha da URL de origem (esperado 67, obtido ${source_url_status})"
[ "$(tr '\n' ':' < "$preflight_call_log")" = 'url:qa:' ] || fail \
  'dry_run_clone avançou após falha da URL de origem'

# A URL do destino tem o mesmo contrato fail-closed.
env_url() {
  printf 'url:%s\n' "$1" >> "$preflight_call_log"
  if [ "$1" = dev ]; then
    return 68
  fi
  printf 'https://%s.example.invalid\n' "$1"
}
: > "$preflight_call_log"
if dry_run_clone >/dev/null 2>&1; then
  target_url_status='0'
else
  target_url_status="$?"
fi
[ "$target_url_status" -eq 68 ] || fail \
  "dry_run_clone mascarou falha da URL de destino (esperado 68, obtido ${target_url_status})"
[ "$(tr '\n' ':' < "$preflight_call_log")" = 'url:qa:url:dev:' ] || fail \
  'dry_run_clone abriu preflight após falha da URL de destino'

# 10. O próprio preflight também pode rodar sob contexto condicional. Resolver
# o document root remoto precisa falhar antes de montar WP-CLI ou abrir SSH.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
preflight_inner_log="$TMP_DIR/preflight-inner.log"
: > "$preflight_inner_log"

log() { :; }
is_remote_env() { [ "$1" != local ]; }
wp_path() {
  printf 'wp-path\n' >> "$preflight_inner_log"
  return 63
}
wp_cli_shell() {
  printf 'wp-cli\n' >> "$preflight_inner_log"
  printf 'wp\n'
}
remote_run_idempotent() {
  printf 'remote\n' >> "$preflight_inner_log"
  return 0
}

if preflight_env qa >/dev/null 2>&1; then
  remote_path_preflight_status='0'
else
  remote_path_preflight_status="$?"
fi
[ "$remote_path_preflight_status" -eq 63 ] || fail \
  "preflight_env mascarou falha de wp_path (esperado 63, obtido ${remote_path_preflight_status})"
[ "$(tr '\n' ':' < "$preflight_inner_log")" = 'wp-path:' ] || fail \
  'preflight_env resolveu WP-CLI ou abriu remoto após falha de wp_path'

# O resolver WP-CLI remoto também precisa preservar seu nonzero.
wp_path() {
  printf 'wp-path\n' >> "$preflight_inner_log"
  printf '/mock/wp\n'
}
wp_cli_shell() {
  printf 'wp-cli\n' >> "$preflight_inner_log"
  return 64
}
: > "$preflight_inner_log"
if preflight_env qa >/dev/null 2>&1; then
  remote_cli_preflight_status='0'
else
  remote_cli_preflight_status="$?"
fi
[ "$remote_cli_preflight_status" -eq 64 ] || fail \
  "preflight_env mascarou falha de wp_cli_shell (esperado 64, obtido ${remote_cli_preflight_status})"
[ "$(tr '\n' ':' < "$preflight_inner_log")" = 'wp-path:wp-cli:' ] || fail \
  'preflight_env abriu remoto após falha de wp_cli_shell'

# O shell remoto deve validar o produtor SHA-256 e o comparador usados pelo
# restore, e o transporte idempotente deve manter seu status sentinela.
wp_cli_shell() {
  printf 'wp-cli\n' >> "$preflight_inner_log"
  printf 'wp\n'
}
PREFLIGHT_REMOTE_SCRIPT=''
remote_run_idempotent() {
  printf 'remote\n' >> "$preflight_inner_log"
  PREFLIGHT_REMOTE_SCRIPT="$2"
  return 71
}
: > "$preflight_inner_log"
if preflight_env qa >/dev/null 2>&1; then
  remote_command_preflight_status='0'
else
  remote_command_preflight_status="$?"
fi
[ "$remote_command_preflight_status" -eq 71 ] || fail \
  "preflight_env mascarou falha remota (esperado 71, obtido ${remote_command_preflight_status})"
[ "$(tr '\n' ':' < "$preflight_inner_log")" = 'wp-path:wp-cli:remote:' ] || fail \
  'preflight_env executou etapa inesperada no caminho remoto'
for remote_preflight_marker in \
  'command -v rsync >/dev/null || exit $?' \
  'command -v gzip >/dev/null || exit $?' \
  'command -v tar >/dev/null || exit $?' \
  'command -v sha256sum >/dev/null || exit $?' \
  'command -v cmp >/dev/null || exit $?' \
  '(command -v mysql >/dev/null || command -v mariadb >/dev/null) || exit $?'; do
  case "$PREFLIGHT_REMOTE_SCRIPT" in
    *"$remote_preflight_marker"*) ;;
    *) fail "preflight remoto não propaga dependência obrigatória: ${remote_preflight_marker}" ;;
  esac
done

# Uma configuração local ausente deve encerrar o preflight antes de qualquer
# consulta a banco; o ramo local nunca pode abrir transporte remoto.
LOCAL_COMPOSE_FILE="$TMP_DIR/preflight-local/missing-compose.yml"
LOCAL_WP_CONTENT="$TMP_DIR/preflight-local/wp-content"
mkdir -p "$LOCAL_WP_CONTENT"
local_db_query() {
  printf 'local-db\n' >> "$preflight_inner_log"
  return 0
}
: > "$preflight_inner_log"
if preflight_env local >/dev/null 2>&1; then
  local_config_preflight_status='0'
else
  local_config_preflight_status="$?"
fi
[ "$local_config_preflight_status" -eq 1 ] || fail \
  "preflight_env mascarou configuração local ausente (esperado 1, obtido ${local_config_preflight_status})"
[ ! -s "$preflight_inner_log" ] || fail \
  'preflight_env consultou banco ou remoto após configuração local ausente'

# O restore local depende de shasum e cmp. Uma falha de descoberta precisa
# manter o status original e impedir qualquer consulta ao banco.
LOCAL_COMPOSE_FILE="$TMP_DIR/preflight-local/compose.yml"
: > "$LOCAL_COMPOSE_FILE"
# This builtin override is exercised indirectly by preflight_env from the sourced clone code.
# shellcheck disable=SC2329
command() {
  if [ "${1:-}" = -v ]; then
    printf 'command:%s\n' "${2:-}" >> "$preflight_inner_log"
    if [ "${2:-}" = shasum ]; then
      return 65
    fi
  fi
  builtin command "$@"
}
: > "$preflight_inner_log"
if preflight_env local >/dev/null 2>&1; then
  local_hash_command_status='0'
else
  local_hash_command_status="$?"
fi
[ "$local_hash_command_status" -eq 65 ] || fail \
  "preflight_env mascarou shasum ausente (esperado 65, obtido ${local_hash_command_status})"
[ "$(tr '\n' ':' < "$preflight_inner_log")" = 'command:shasum:' ] || fail \
  'preflight_env avançou após falha ao resolver shasum local'
unset -f command

# O comparador canônico é uma dependência separada do produtor de checksum.
# This builtin override is exercised indirectly by preflight_env from the sourced clone code.
# shellcheck disable=SC2329
command() {
  if [ "${1:-}" = -v ]; then
    printf 'command:%s\n' "${2:-}" >> "$preflight_inner_log"
    if [ "${2:-}" = cmp ]; then
      return 66
    fi
  fi
  builtin command "$@"
}
: > "$preflight_inner_log"
if preflight_env local >/dev/null 2>&1; then
  local_cmp_command_status='0'
else
  local_cmp_command_status="$?"
fi
[ "$local_cmp_command_status" -eq 66 ] || fail \
  "preflight_env mascarou cmp ausente (esperado 66, obtido ${local_cmp_command_status})"
[ "$(tr '\n' ':' < "$preflight_inner_log")" = 'command:shasum:command:cmp:' ] || fail \
  'preflight_env avançou após falha ao resolver cmp local'
unset -f command

# A primeira consulta de sanidade local não pode ser mascarada pela consulta
# seguinte quando preflight_env está sob `if`/`||`.
PREFLIGHT_LOCAL_DB_CALLS='0'
local_db_query() {
  PREFLIGHT_LOCAL_DB_CALLS=$((PREFLIGHT_LOCAL_DB_CALLS + 1))
  printf 'local-db:%s\n' "$PREFLIGHT_LOCAL_DB_CALLS" >> "$preflight_inner_log"
  if [ "$PREFLIGHT_LOCAL_DB_CALLS" -eq 1 ]; then
    return 69
  fi
  return 0
}
: > "$preflight_inner_log"
if preflight_env local >/dev/null 2>&1; then
  local_db_preflight_status='0'
else
  local_db_preflight_status="$?"
fi
[ "$local_db_preflight_status" -eq 69 ] || fail \
  "preflight_env mascarou primeira consulta local (esperado 69, obtido ${local_db_preflight_status})"
[ "$(tr '\n' ':' < "$preflight_inner_log")" = 'local-db:1:' ] || fail \
  'preflight_env executou segunda consulta após falha da primeira'

# A segunda consulta obrigatória também deve preservar o status operacional.
PREFLIGHT_LOCAL_DB_CALLS='0'
local_db_query() {
  PREFLIGHT_LOCAL_DB_CALLS=$((PREFLIGHT_LOCAL_DB_CALLS + 1))
  printf 'local-db:%s\n' "$PREFLIGHT_LOCAL_DB_CALLS" >> "$preflight_inner_log"
  if [ "$PREFLIGHT_LOCAL_DB_CALLS" -eq 2 ]; then
    return 70
  fi
  return 0
}
: > "$preflight_inner_log"
if preflight_env local >/dev/null 2>&1; then
  local_options_preflight_status='0'
else
  local_options_preflight_status="$?"
fi
[ "$local_options_preflight_status" -eq 70 ] || fail \
  "preflight_env mascarou consulta home/siteurl local (esperado 70, obtido ${local_options_preflight_status})"
[ "$(tr '\n' ':' < "$preflight_inner_log")" = 'local-db:1:local-db:2:' ] || fail \
  'preflight_env não executou somente as duas consultas locais esperadas'

# 11. Os resolvers WP são usados por consultas e mutações C3 sob OR-lists.
# Falhar ao resolver o binário PHP deve interromper wp_cli_shell antes de ler o
# campo WP-CLI seguinte.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
wp_resolver_log="$TMP_DIR/wp-resolver.log"
: > "$wp_resolver_log"

uonix_environment_field() {
  printf 'field:%s\n' "$2" >> "$wp_resolver_log"
  case "$2" in
    php_bin) return 61 ;;
    wp_bin) printf 'wp\n' ;;
    *) return 90 ;;
  esac
}
if wp_cli_shell qa >/dev/null 2>&1; then
  wp_php_resolver_status='0'
else
  wp_php_resolver_status="$?"
fi
[ "$wp_php_resolver_status" -eq 61 ] || fail \
  "wp_cli_shell mascarou falha do campo PHP (esperado 61, obtido ${wp_php_resolver_status})"
[ "$(tr '\n' ':' < "$wp_resolver_log")" = 'field:php_bin:' ] || fail \
  'wp_cli_shell consultou wp_bin após falha de php_bin'

# O segundo campo do comando WP-CLI mantém seu próprio status.
uonix_environment_field() {
  printf 'field:%s\n' "$2" >> "$wp_resolver_log"
  case "$2" in
    php_bin) printf 'php\n' ;;
    wp_bin) return 62 ;;
    *) return 90 ;;
  esac
}
: > "$wp_resolver_log"
if wp_cli_shell qa >/dev/null 2>&1; then
  wp_bin_resolver_status='0'
else
  wp_bin_resolver_status="$?"
fi
[ "$wp_bin_resolver_status" -eq 62 ] || fail \
  "wp_cli_shell mascarou falha do campo WP-CLI (esperado 62, obtido ${wp_bin_resolver_status})"
[ "$(tr '\n' ':' < "$wp_resolver_log")" = 'field:php_bin:field:wp_bin:' ] || fail \
  'wp_cli_shell executou etapa inesperada após falha de wp_bin'

# wp_exec é o boundary comum das consultas e mutações C3. Uma falha ao
# resolver o document root deve impedir a resolução do CLI e o transporte.
is_remote_env() { return 0; }
# This resolver mock is exercised indirectly by wp_plugin_predicate_state.
# shellcheck disable=SC2329
wp_path() {
  printf 'wp-path\n' >> "$wp_resolver_log"
  return 63
}
# This resolver mock is exercised indirectly by wp_plugin_predicate_state.
# shellcheck disable=SC2329
wp_cli_shell() {
  printf 'wp-cli\n' >> "$wp_resolver_log"
  printf 'wp\n'
}
remote_run() {
  printf 'remote\n' >> "$wp_resolver_log"
  printf '1\n'
}
: > "$wp_resolver_log"
wp_path_resolver_output=''
if wp_path_resolver_output="$(wp_plugin_predicate_state qa is-installed fluent-smtp 2>/dev/null)"; then
  wp_path_resolver_status='0'
else
  wp_path_resolver_status="$?"
fi
[ "$wp_path_resolver_status" -eq 63 ] || fail \
  "consulta Fluent mascarou falha de wp_path (esperado 63, obtido ${wp_path_resolver_status})"
[ -z "$wp_path_resolver_output" ] || fail \
  "consulta Fluent produziu estado após falha de wp_path: ${wp_path_resolver_output}"
[ "$(tr '\n' ':' < "$wp_resolver_log")" = 'wp-path:' ] || fail \
  'consulta Fluent resolveu WP-CLI ou abriu remoto após falha de wp_path'

# A resolução do comando WP-CLI é o segundo gate obrigatório do mesmo boundary.
wp_path() {
  printf 'wp-path\n' >> "$wp_resolver_log"
  printf '/mock/wp\n'
}
wp_cli_shell() {
  printf 'wp-cli\n' >> "$wp_resolver_log"
  return 64
}
: > "$wp_resolver_log"
if wp_exec qa db query "DELETE FROM wp_options WHERE option_name LIKE '%gosmtp%'" >/dev/null 2>&1; then
  wp_cli_resolver_status='0'
else
  wp_cli_resolver_status="$?"
fi
[ "$wp_cli_resolver_status" -eq 64 ] || fail \
  "mutação GoSMTP mascarou falha de wp_cli_shell (esperado 64, obtido ${wp_cli_resolver_status})"
[ "$(tr '\n' ':' < "$wp_resolver_log")" = 'wp-path:wp-cli:' ] || fail \
  'mutação GoSMTP abriu remoto após falha de wp_cli_shell'

# A mesma falha do resolver, quando ocorre numa validação central após o início
# da mutação, precisa atravessar o boundary e acionar exatamente um rollback.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
central_resolver_log="$TMP_DIR/central-resolver.log"
central_rollback_log="$TMP_DIR/central-resolver-rollback.log"
: > "$central_resolver_log"
: > "$central_rollback_log"
# The sourced clone boundary calls this redefined log mock at runtime.
# shellcheck disable=SC2329
log() { :; }
env_url() { printf 'https://%s.example.invalid\n' "$1"; }
env_title() { printf 'Uonix %s\n' "$1"; }
uonix_env_type() { printf 'staging\n'; }
is_remote_env() { return 0; }
wp_path() {
  printf 'wp-path\n' >> "$central_resolver_log"
  return 63
}
wp_cli_shell() {
  printf 'wp-cli\n' >> "$central_resolver_log"
  printf 'wp\n'
}
remote_run() { printf 'remote\n' >> "$central_resolver_log"; }
execute_clone_mutation() {
  MUTATION_STARTED=1
  validate_target_after_clone qa
}
rollback_target() { printf 'rollback\n' >> "$central_rollback_log"; }
TARGET='qa'
TARGET_BACKUP_DIR="$TMP_DIR/central-target-backup"
MUTATION_STARTED='0'
ROLLBACK_RUNNING='0'
if execute_clone_with_rollback >/dev/null 2>&1; then
  central_resolver_status='0'
else
  central_resolver_status="$?"
fi
[ "$central_resolver_status" -eq 63 ] || fail \
  "validação central mascarou falha de wp_path (esperado 63, obtido ${central_resolver_status})"
[ "$(tr '\n' ':' < "$central_resolver_log")" = 'wp-path:' ] || fail \
  'validação central resolveu WP-CLI ou abriu remoto após falha de wp_path'
[ "$(wc -l < "$central_rollback_log" | tr -d ' ')" -eq 1 ] || fail \
  'falha de resolver pós-mutation não acionou exatamente um rollback'

# O gerador SQL é transitivo ao dump sensível. Falhar ao cotar o identificador
# deve interromper antes de iniciar qualquer cliente Podman/DB.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
option_identifier_log="$TMP_DIR/option-identifier.log"
option_identifier_podman_log="$TMP_DIR/option-identifier-podman.log"
: > "$option_identifier_log"
: > "$option_identifier_podman_log"
# local_db_dump_options reaches this redefined mock through the sourced SQL helper.
# shellcheck disable=SC2329
quote_sql_identifier() {
  printf 'quote\n' >> "$option_identifier_log"
  return 61
}
podman() {
  printf 'podman\n' >> "$option_identifier_podman_log"
  return 0
}
LOCAL_DB_PASSWORD='not-a-secret'
LOCAL_DB_CONTAINER='mock-db'
LOCAL_DB_USER='mock-user'
LOCAL_DB_NAME='mock-name'
if local_db_dump_options wp_options 'option_name = "sentinel"' >/dev/null 2>&1; then
  option_identifier_status='0'
else
  option_identifier_status="$?"
fi
[ "$option_identifier_status" -eq 61 ] || fail \
  "option_upsert_select_sql mascarou quote_sql_identifier (esperado 61, obtido ${option_identifier_status})"
[ "$(tr '\n' ':' < "$option_identifier_log")" = 'quote:' ] || fail \
  'option_upsert_select_sql não chamou quote_sql_identifier exatamente uma vez'
[ ! -s "$option_identifier_podman_log" ] || fail \
  'local_db_dump_options abriu Podman/DB após falha de quote_sql_identifier'
unset -f podman

# O mesmo helper também protege a limpeza GoSMTP no C3. Uma falha ao cotar a
# tabela não pode ser convertida em sucesso por uma consulta DB posterior.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
gosmtp_quote_log="$TMP_DIR/gosmtp-quote.log"
: > "$gosmtp_quote_log"
log() { :; }
wp_plugin_predicate_state() {
  case "$3" in
    fluent-smtp) printf 'true\n' ;;
    gosmtp|gosmtp-pro) printf 'false\n' ;;
    *) return 90 ;;
  esac
}
wp_exec() {
  if [ "${2:-}" = db ] && [ "${3:-}" = prefix ]; then
    printf 'wp_\n'
    return 0
  fi
  printf 'wp-exec:%s\n' "${2:-unknown}" >> "$gosmtp_quote_log"
  return 0
}
quote_sql_identifier() {
  printf 'quote\n' >> "$gosmtp_quote_log"
  return 61
}
if enforce_smtp_plugin_policy qa >/dev/null 2>&1; then
  gosmtp_quote_status='0'
else
  gosmtp_quote_status="$?"
fi
[ "$gosmtp_quote_status" -eq 61 ] || fail \
  "limpeza GoSMTP mascarou quote_sql_identifier (esperado 61, obtido ${gosmtp_quote_status})"
[ "$(tr '\n' ':' < "$gosmtp_quote_log")" = 'wp-exec:plugin:quote:' ] || fail \
  'limpeza GoSMTP abriu consulta DB após falha de quote_sql_identifier'

# 12. Todo boundary remoto direto do clone deve preservar falhas dos resolvers
# de document root/WP-CLI mesmo quando o chamador está em um OR-list. Nenhum
# formatter pode fabricar /wp-content e nenhum transporte pode ser aberto.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r9_resolver_log="$TMP_DIR/c3r9-resolver-boundaries.log"
c3r9_bridge_root="$TMP_DIR/c3r9-bridge"
c3r9_author_map="$TMP_DIR/c3r9-source-authors.tsv"
mkdir -p "$c3r9_bridge_root"
printf '1\t61646D696E\n' > "$c3r9_author_map"
chmod 600 "$c3r9_author_map"

log() { :; }
is_remote_env() { [ "$1" != local ]; }
wp_path() {
  printf 'wp-path\n' >> "$c3r9_resolver_log"
  [ "$C3R9_WP_PATH_STATUS" -eq 0 ] || return "$C3R9_WP_PATH_STATUS"
  printf '/mock/wp\n'
}
wp_cli_shell() {
  printf 'wp-cli\n' >> "$c3r9_resolver_log"
  [ "$C3R9_WP_CLI_STATUS" -eq 0 ] || return "$C3R9_WP_CLI_STATUS"
  printf 'wp\n'
}
# Remote clone boundaries resolve their fields through this redefined mock.
# shellcheck disable=SC2329
uonix_environment_field() {
  printf 'field:%s\n' "$2" >> "$c3r9_resolver_log"
  printf '/mock/%s\n' "$2"
}
remote_run() { printf 'transport:remote-run\n' >> "$c3r9_resolver_log"; }
remote_run_idempotent() { printf 'transport:remote-read\n' >> "$c3r9_resolver_log"; }
remote_stream_to_file() { printf 'transport:stream\n' >> "$c3r9_resolver_log"; }
remote_import_gzip_dump() { printf 'transport:import\n' >> "$c3r9_resolver_log"; }
uonix_rsync_to_runner() { printf 'transport:rsync-download\n' >> "$c3r9_resolver_log"; }
write_directory_manifest() { printf 'transport:manifest-write\n' >> "$c3r9_resolver_log"; }
verify_directory_manifest() { printf 'transport:manifest-verify\n' >> "$c3r9_resolver_log"; }
bridge_upload_payload() { printf 'transport:rsync-upload\n' >> "$c3r9_resolver_log"; }
verify_payload_at_target() { printf 'transport:target-verify\n' >> "$c3r9_resolver_log"; }

run_c3r9_boundary() {
  case "$1" in
    prepare_target_backup) prepare_target_backup qa "$TMP_DIR/c3r9-backup" ;;
    snapshot_users) snapshot_users qa "$TMP_DIR/c3r9-backup" ;;
    export_source_db) export_source_db qa "$TMP_DIR/c3r9-source.sql.gz" ;;
    import_db_to_target) import_db_to_target qa "$TMP_DIR/c3r9-source.sql.gz" ;;
    restore_users) restore_users qa "$TMP_DIR/c3r9-backup" ;;
    remap_missing_authors) remap_missing_authors qa "$c3r9_author_map" ;;
    clear_cache) clear_cache qa ;;
    rollback_target) rollback_target qa "$TMP_DIR/c3r9-backup" ;;
    *) return 99 ;;
  esac
}

assert_c3r9_resolver_failure() {
  local boundary="$1"
  local failure_point="$2"
  local expected_status="$3"
  local expected_log="$4"
  local actual_status

  C3R9_WP_PATH_STATUS=0
  C3R9_WP_CLI_STATUS=0
  if [ "$failure_point" = wp-path ]; then
    C3R9_WP_PATH_STATUS="$expected_status"
  else
    C3R9_WP_CLI_STATUS="$expected_status"
  fi
  : > "$c3r9_resolver_log"
  if run_c3r9_boundary "$boundary" >/dev/null 2>&1; then
    actual_status=0
  else
    actual_status=$?
  fi
  [ "$actual_status" -eq "$expected_status" ] || fail \
    "${boundary} mascarou ${failure_point} (esperado ${expected_status}, obtido ${actual_status})"
  [ "$(tr '\n' ':' < "$c3r9_resolver_log")" = "$expected_log" ] || fail \
    "${boundary} executou helper/transporte após falha de ${failure_point}: $(tr '\n' ':' < "$c3r9_resolver_log")"
}

C3R9_WP_PATH_STATUS=71
C3R9_WP_CLI_STATUS=0
: > "$c3r9_resolver_log"
if c3r9_content_output="$(content_path qa 2>/dev/null)"; then
  c3r9_content_status=0
else
  c3r9_content_status=$?
fi
[ "$c3r9_content_status" -eq 71 ] || fail \
  "content_path mascarou wp_path (esperado 71, obtido ${c3r9_content_status})"
[ -z "$c3r9_content_output" ] || fail \
  "content_path fabricou path após falha: ${c3r9_content_output}"
[ "$(tr '\n' ':' < "$c3r9_resolver_log")" = 'wp-path:' ] || fail \
  'content_path executou helper inesperado após falha de wp_path'

: > "$c3r9_resolver_log"
if c3r9_remote_content_output="$(remote_wp_content_dir qa 2>/dev/null)"; then
  c3r9_remote_content_status=0
else
  c3r9_remote_content_status=$?
fi
[ "$c3r9_remote_content_status" -eq 71 ] || fail \
  "remote_wp_content_dir mascarou wp_path (esperado 71, obtido ${c3r9_remote_content_status})"
[ -z "$c3r9_remote_content_output" ] || fail \
  "remote_wp_content_dir fabricou path após falha: ${c3r9_remote_content_output}"
[ "$(tr '\n' ':' < "$c3r9_resolver_log")" = 'wp-path:' ] || fail \
  'remote_wp_content_dir executou helper inesperado após falha de wp_path'

: > "$c3r9_resolver_log"
if bridge_runtime_directory qa local uploads "$c3r9_bridge_root" >/dev/null 2>&1; then
  c3r9_bridge_status=0
else
  c3r9_bridge_status=$?
fi
[ "$c3r9_bridge_status" -eq 71 ] || fail \
  "bridge_runtime_directory mascarou content_path (esperado 71, obtido ${c3r9_bridge_status})"
[ "$(tr '\n' ':' < "$c3r9_resolver_log")" = 'wp-path:' ] || fail \
  'bridge_runtime_directory abriu transporte após falha de content_path'

mkdir -p "$LOCAL_WP_CONTENT/uploads"
: > "$c3r9_resolver_log"
if bridge_runtime_directory local qa uploads "$c3r9_bridge_root" >/dev/null 2>&1; then
  c3r9_target_bridge_status=0
else
  c3r9_target_bridge_status=$?
fi
[ "$c3r9_target_bridge_status" -eq 71 ] || fail \
  "bridge_runtime_directory mascarou content_path do destino (esperado 71, obtido ${c3r9_target_bridge_status})"
[ "$(tr '\n' ':' < "$c3r9_resolver_log")" = 'wp-path:' ] || fail \
  'bridge_runtime_directory abriu transporte após falha de content_path do destino'

PRESERVE_DESTINATION_USERS=1
INCLUDE_GIT_FILES=0
for c3r9_boundary in \
  prepare_target_backup snapshot_users export_source_db import_db_to_target \
  restore_users remap_missing_authors clear_cache rollback_target; do
  assert_c3r9_resolver_failure "$c3r9_boundary" wp-path 71 'wp-path:'
  assert_c3r9_resolver_failure "$c3r9_boundary" wp-cli 72 'wp-path:wp-cli:'
done

# 13. Os resolvers de identidade são gates antes de qualquer leitura/mutação WP.
# Cada nonzero deve atravessar set_target_identity e a validação central sem ser
# substituído pelo status de um resolver ou wp_exec posterior.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r9_identity_log="$TMP_DIR/c3r9-identity-resolvers.log"
log() { :; }
env_url() {
  printf 'env-url\n' >> "$c3r9_identity_log"
  [ "$C3R9_ENV_URL_STATUS" -eq 0 ] || return "$C3R9_ENV_URL_STATUS"
  printf 'https://qa.example.invalid\n'
}
env_title() {
  printf 'env-title\n' >> "$c3r9_identity_log"
  [ "$C3R9_ENV_TITLE_STATUS" -eq 0 ] || return "$C3R9_ENV_TITLE_STATUS"
  printf 'Mock QA\n'
}
uonix_env_type() {
  printf 'env-type\n' >> "$c3r9_identity_log"
  [ "$C3R9_ENV_TYPE_STATUS" -eq 0 ] || return "$C3R9_ENV_TYPE_STATUS"
  printf 'staging\n'
}
wp_exec() {
  printf 'wp-exec\n' >> "$c3r9_identity_log"
  return 74
}

assert_c3r9_identity_failure() {
  local boundary="$1"
  local failure_point="$2"
  local expected_status="$3"
  local expected_log="$4"
  local actual_status

  C3R9_ENV_URL_STATUS=0
  C3R9_ENV_TITLE_STATUS=0
  C3R9_ENV_TYPE_STATUS=0
  case "$failure_point" in
    env-url) C3R9_ENV_URL_STATUS="$expected_status" ;;
    env-title) C3R9_ENV_TITLE_STATUS="$expected_status" ;;
    env-type) C3R9_ENV_TYPE_STATUS="$expected_status" ;;
    *) return 99 ;;
  esac
  : > "$c3r9_identity_log"
  if [ "$boundary" = set_target_identity ]; then
    if set_target_identity qa 'https://source.example.invalid' >/dev/null 2>&1; then
      actual_status=0
    else
      actual_status=$?
    fi
  elif validate_target_after_clone qa >/dev/null 2>&1; then
    actual_status=0
  else
    actual_status=$?
  fi
  [ "$actual_status" -eq "$expected_status" ] || fail \
    "${boundary} mascarou ${failure_point} (esperado ${expected_status}, obtido ${actual_status})"
  [ "$(tr '\n' ':' < "$c3r9_identity_log")" = "$expected_log" ] || fail \
    "${boundary} chamou resolver/WP após falha de ${failure_point}: $(tr '\n' ':' < "$c3r9_identity_log")"
}

assert_c3r9_identity_failure set_target_identity env-url 67 'env-url:'
assert_c3r9_identity_failure set_target_identity env-title 68 'env-url:env-title:'
assert_c3r9_identity_failure validate_target_after_clone env-url 67 'env-url:'
assert_c3r9_identity_failure validate_target_after_clone env-title 68 'env-url:env-title:'
assert_c3r9_identity_failure validate_target_after_clone env-type 69 'env-url:env-title:env-type:'

# 14. Uma falha de env_url dentro da validação real, após MUTATION_STARTED,
# conserva o status primário, aciona um único rollback e não chega ao resumo de
# sucesso. Se o resolver do próprio rollback falhar, seu status também fica
# observável sem substituir o exit primário.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r9_boundary_log="$TMP_DIR/c3r9-post-mutation-boundary.log"
c3r9_boundary_error="$TMP_DIR/c3r9-post-mutation-boundary.err"
: > "$c3r9_boundary_log"
: > "$c3r9_boundary_error"

log() { printf 'log:%s\n' "$*" >> "$c3r9_boundary_log"; }
backup_dir() { printf '%s\n' "$TMP_DIR/c3r9-real-boundary-backup"; }
prepare_target_backup() { :; }
snapshot_users() { :; }
snapshot_options() { :; }
export_source_db() { : > "$2"; }
import_db_to_target() { :; }
restore_users() { :; }
set_target_identity() { :; }
restore_options() { :; }
remap_missing_authors() { :; }
sync_runtime_files() { :; }
enforce_smtp_plugin_policy() { :; }
clear_cache() { :; }
validate_compressx_delivery() { printf 'compressx-after-failure\n' >> "$c3r9_boundary_log"; }
write_clone_summary() { printf 'summary-after-failure\n' >> "$c3r9_boundary_log"; }
env_url() {
  local call_count
  printf 'env-url\n' >> "$c3r9_boundary_log"
  call_count="$(awk '$0 == "env-url" { count++ } END { print count + 0 }' "$c3r9_boundary_log")"
  if [ "$call_count" -eq 3 ]; then
    return 67
  fi
  printf 'https://%s.example.invalid\n' "$1"
}
env_title() { printf 'Mock %s\n' "$1"; }
uonix_env_type() { printf 'staging\n'; }
wp_exec() { printf 'wp-exec\n' >> "$c3r9_boundary_log"; }
is_remote_env() { return 0; }
wp_path() {
  printf 'rollback-wp-path\n' >> "$c3r9_boundary_log"
  return 88
}
wp_cli_shell() { printf 'rollback-wp-cli\n' >> "$c3r9_boundary_log"; printf 'wp\n'; }
# Invocado indiretamente pelo rollback do script sourceado, se ele chegar lá — o
# ponto do teste é justamente que NÃO deve chegar. O rollback usa o caminho com
# retry (remote_run_idempotent) porque restaurar de um backup validado é
# idempotente.
# shellcheck disable=SC2329
remote_run_idempotent() { printf 'rollback-transport\n' >> "$c3r9_boundary_log"; }

SOURCE=qa
TARGET=dev
CLONE_TMP_DIR="$TMP_DIR/c3r9-real-boundary"
mkdir -p "$CLONE_TMP_DIR"
TARGET_BACKUP_DIR=''
MUTATION_STARTED=0
ROLLBACK_RUNNING=0
CLONE_FAILURE_STATUS=0
if execute_clone_with_rollback >/dev/null 2>"$c3r9_boundary_error"; then
  c3r9_boundary_status=0
else
  c3r9_boundary_status=$?
fi
[ "$c3r9_boundary_status" -eq 67 ] || fail \
  "boundary pós-mutation mascarou env_url (esperado 67, obtido ${c3r9_boundary_status})"
[ "$CLONE_FAILURE_STATUS" -eq 67 ] || fail \
  "clone_handle_failure perdeu causa primária (esperado 67, obtido ${CLONE_FAILURE_STATUS})"
[ "$(awk '$0 == "rollback-wp-path" { count++ } END { print count + 0 }' "$c3r9_boundary_log")" -eq 1 ] || fail \
  'boundary pós-mutation não executou exatamente um rollback'
[ "$(awk '$0 == "rollback-wp-cli" || $0 == "rollback-transport" { count++ } END { print count + 0 }' "$c3r9_boundary_log")" -eq 0 ] || fail \
  'rollback abriu helper/transporte após falha de wp_path'
case "$(<"$c3r9_boundary_error")" in
  *'exit 88'*) ;;
  *) fail 'falha do resolver no rollback não permaneceu visível com exit 88' ;;
esac
case "$(<"$c3r9_boundary_log")" in
  *summary-after-failure*|*compressx-after-failure*|*'Clone concluído:'*)
    fail 'boundary emitiu resumo/log de sucesso após falha pós-mutation'
    ;;
esac

# 15. O backup_root é um gate pré-mutation: a falha do resolver precisa
# atravessar todos os callers sem fabricar /<STAMP> nem abrir transporte.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r10_backup_log="$TMP_DIR/c3r10-backup-root.log"
c3r10_backup_failures=0
: > "$c3r10_backup_log"

record_c3r10_backup_failure() {
  printf 'FAIL: %s\n' "$*" >&2
  c3r10_backup_failures=$((c3r10_backup_failures + 1))
}

log() { printf 'log:%s\n' "$*" >> "$c3r10_backup_log"; }
is_remote_env() { [ "$1" != local ]; }
env_url() { printf 'https://%s.example.invalid\n' "$1"; }
wp_path() { printf 'wp-path\n' >> "$c3r10_backup_log"; printf '/synthetic/wp\n'; }
wp_cli_shell() { printf 'wp-cli\n' >> "$c3r10_backup_log"; printf 'wp\n'; }
C3R10_BACKUP_FIELD_STATUS=73
C3R10_BACKUP_FIELD_VALUE='/synthetic/backups/qa'
uonix_environment_field() {
  printf 'field:%s\n' "$2" >> "$c3r10_backup_log"
  if [ "$2" = backup_root ]; then
    [ "$C3R10_BACKUP_FIELD_STATUS" -eq 0 ] || return "$C3R10_BACKUP_FIELD_STATUS"
    printf '%s\n' "$C3R10_BACKUP_FIELD_VALUE"
    return 0
  fi
  printf '/synthetic/%s\n' "$2"
}
remote_run() { printf 'remote-run\n' >> "$c3r10_backup_log"; }
remote_run_idempotent() { printf 'remote-read\n' >> "$c3r10_backup_log"; }
execute_clone_mutation() { printf 'mutation\n' >> "$c3r10_backup_log"; }
write_clone_summary() { printf 'summary\n' >> "$c3r10_backup_log"; }
rollback_target() { printf 'rollback\n' >> "$c3r10_backup_log"; }

: > "$c3r10_backup_log"
if c3r10_backup_output="$(backup_dir qa 2>/dev/null)"; then
  c3r10_backup_status=0
else
  c3r10_backup_status=$?
fi
[ "$c3r10_backup_status" -eq 73 ] || record_c3r10_backup_failure \
  "backup_dir mascarou backup_root (esperado 73, obtido ${c3r10_backup_status})"
[ -z "$c3r10_backup_output" ] || record_c3r10_backup_failure \
  "backup_dir fabricou caminho após falha: ${c3r10_backup_output}"

: > "$c3r10_backup_log"
if prepare_target_backup qa '/synthetic/backups/stamp' >/dev/null 2>&1; then
  c3r10_prepare_status=0
else
  c3r10_prepare_status=$?
fi
[ "$c3r10_prepare_status" -eq 73 ] || record_c3r10_backup_failure \
  "prepare_target_backup mascarou backup_root (esperado 73, obtido ${c3r10_prepare_status})"
if grep -Eq '^remote-(run|read)$' "$c3r10_backup_log"; then
  record_c3r10_backup_failure 'prepare_target_backup abriu remoto após falha de backup_root'
fi

for c3r10_unsafe_root in '' relative/path /; do
  C3R10_BACKUP_FIELD_STATUS=0
  C3R10_BACKUP_FIELD_VALUE="$c3r10_unsafe_root"
  : > "$c3r10_backup_log"
  if prepare_target_backup qa '/synthetic/backups/qa/stamp' >/dev/null 2>&1; then
    c3r10_unsafe_status=0
  else
    c3r10_unsafe_status=$?
  fi
  [ "$c3r10_unsafe_status" -ne 0 ] || record_c3r10_backup_failure \
    "prepare_target_backup aceitou backup_root inseguro: '${c3r10_unsafe_root}'"
  if grep -Eq '^remote-(run|read)$' "$c3r10_backup_log"; then
    record_c3r10_backup_failure \
      "prepare_target_backup abriu remoto com backup_root inseguro: '${c3r10_unsafe_root}'"
  fi
done
C3R10_BACKUP_FIELD_STATUS=73
C3R10_BACKUP_FIELD_VALUE='/synthetic/backups/qa'

# Duas barras iniciais podem ser interpretadas como raiz em Unix. A raiz
# ambígua precisa falhar antes de fabricar caminho ou abrir transporte.
C3R10_BACKUP_FIELD_STATUS=0
C3R10_BACKUP_FIELD_VALUE='//'
: > "$c3r10_backup_log"
if c3r10_double_slash_output="$(backup_dir qa 2>/dev/null)"; then
  c3r10_double_slash_status=0
else
  c3r10_double_slash_status=$?
fi
[ "$c3r10_double_slash_status" -ne 0 ] || record_c3r10_backup_failure \
  'backup_dir aceitou backup_root ambíguo //'
[ -z "$c3r10_double_slash_output" ] || record_c3r10_backup_failure \
  "backup_dir fabricou caminho com backup_root //: ${c3r10_double_slash_output}"
if prepare_target_backup qa '///c3r10-stamp' >/dev/null 2>&1; then
  c3r10_double_slash_prepare_status=0
else
  c3r10_double_slash_prepare_status=$?
fi
[ "$c3r10_double_slash_prepare_status" -ne 0 ] || record_c3r10_backup_failure \
  'prepare_target_backup aceitou backup_root ambíguo //'
if grep -Eq '^remote-(run|read)$' "$c3r10_backup_log"; then
  record_c3r10_backup_failure 'backup_root // abriu remoto antes de falhar'
fi

# LOCAL_BACKUP_ROOT=/ não pode virar //local e escapar para a raiz do host.
c3r10_saved_local_backup_root="$LOCAL_BACKUP_ROOT"
c3r10_saved_local_compose_file="$LOCAL_COMPOSE_FILE"
c3r10_saved_local_wp_content="$LOCAL_WP_CONTENT"
LOCAL_BACKUP_ROOT='/'
if c3r10_local_root_output="$(backup_dir local 2>/dev/null)"; then
  c3r10_local_root_status=0
else
  c3r10_local_root_status=$?
fi
[ "$c3r10_local_root_status" -ne 0 ] || record_c3r10_backup_failure \
  'backup_dir local aceitou LOCAL_BACKUP_ROOT=/'
[ -z "$c3r10_local_root_output" ] || record_c3r10_backup_failure \
  "backup_dir local fabricou caminho sob a raiz: ${c3r10_local_root_output}"

# O preflight local e o dry-run de um par remoto -> local precisam validar os
# dois backup roots antes de qualquer consulta local ou acesso remoto.
LOCAL_COMPOSE_FILE="$TMP_DIR/c3r10-local-compose.yml"
LOCAL_WP_CONTENT="$TMP_DIR/c3r10-local-wp-content"
: > "$LOCAL_COMPOSE_FILE"
mkdir -p "$LOCAL_WP_CONTENT"
# This builtin override is exercised indirectly by preflight_env from the sourced clone code.
# shellcheck disable=SC2329
command() { return 0; }
local_db_query() { printf 'local-db\n' >> "$c3r10_backup_log"; }
: > "$c3r10_backup_log"
if preflight_env local >/dev/null 2>&1; then
  c3r10_local_preflight_status=0
else
  c3r10_local_preflight_status=$?
fi
[ "$c3r10_local_preflight_status" -ne 0 ] || record_c3r10_backup_failure \
  'preflight_env local aceitou LOCAL_BACKUP_ROOT=/'
if grep -Eq '^(remote-(run|read)|local-db)$' "$c3r10_backup_log"; then
  record_c3r10_backup_failure \
    "preflight_env local avançou após backup_root inválido: $(tr '\n' ':' < "$c3r10_backup_log")"
fi

C3R10_BACKUP_FIELD_STATUS=0
C3R10_BACKUP_FIELD_VALUE='/synthetic/backups/qa'
SOURCE=qa
TARGET=local
INCLUDE_GIT_FILES=0
: > "$c3r10_backup_log"
if dry_run_clone >/dev/null 2>&1; then
  c3r10_local_dry_run_status=0
else
  c3r10_local_dry_run_status=$?
fi
[ "$c3r10_local_dry_run_status" -ne 0 ] || record_c3r10_backup_failure \
  'dry_run_clone aceitou destino local com LOCAL_BACKUP_ROOT=/'
if grep -Eq '^(remote-(run|read)|local-db)$' "$c3r10_backup_log"; then
  record_c3r10_backup_failure \
    "dry_run_clone abriu boundary antes de validar ambos os backup roots: $(tr '\n' ':' < "$c3r10_backup_log")"
fi

unset -f command local_db_query
LOCAL_BACKUP_ROOT="$c3r10_saved_local_backup_root"
LOCAL_COMPOSE_FILE="$c3r10_saved_local_compose_file"
LOCAL_WP_CONTENT="$c3r10_saved_local_wp_content"
C3R10_BACKUP_FIELD_STATUS=73
C3R10_BACKUP_FIELD_VALUE='/synthetic/backups/qa'

: > "$c3r10_backup_log"
if preflight_env qa >/dev/null 2>&1; then
  c3r10_preflight_status=0
else
  c3r10_preflight_status=$?
fi
[ "$c3r10_preflight_status" -eq 73 ] || record_c3r10_backup_failure \
  "preflight_env mascarou backup_root (esperado 73, obtido ${c3r10_preflight_status})"
if grep -Eq '^remote-(run|read)$' "$c3r10_backup_log"; then
  record_c3r10_backup_failure 'preflight_env abriu remoto após falha de backup_root'
fi

SOURCE=qa
TARGET=dev
CLONE_MODE=execute
MUTATION_STARTED=0
TARGET_BACKUP_DIR=''
ROLLBACK_RUNNING=0
: > "$c3r10_backup_log"
if run_clone >/dev/null 2>&1; then
  c3r10_run_status=0
else
  c3r10_run_status=$?
fi
[ "$c3r10_run_status" -eq 73 ] || record_c3r10_backup_failure \
  "run_clone mascarou backup_root no dry-run (esperado 73, obtido ${c3r10_run_status})"
[ "$MUTATION_STARTED" -eq 0 ] || record_c3r10_backup_failure \
  'falha de backup_root alterou MUTATION_STARTED'
if grep -Eq '^(remote-(run|read)|mutation|rollback|summary)$' "$c3r10_backup_log"; then
  record_c3r10_backup_failure \
    "falha pré-mutation avançou indevidamente: $(tr '\n' ':' < "$c3r10_backup_log")"
fi
case "$(<"$c3r10_backup_log")" in
  *'Dry-run concluído'*|*'Clone concluído:'*)
    record_c3r10_backup_failure 'falha de backup_root emitiu log de sucesso'
    ;;
esac

# O mapa real também precisa falhar fechado quando a raiz Locaweb obrigatória
# não existe, usando apenas configuração sintética e sem abrir transporte.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r10_saved_locaweb_root="${LOCAWEB_ACCOUNT_ROOT-}"
unset LOCAWEB_ACCOUNT_ROOT
if c3r10_prod_backup_output="$(backup_dir prod 2>/dev/null)"; then
  c3r10_prod_backup_status=0
else
  c3r10_prod_backup_status=$?
fi
LOCAWEB_ACCOUNT_ROOT="$c3r10_saved_locaweb_root"
export LOCAWEB_ACCOUNT_ROOT
[ "$c3r10_prod_backup_status" -ne 0 ] || record_c3r10_backup_failure \
  'backup_dir prod aceitou LOCAWEB_ACCOUNT_ROOT ausente'
[ -z "$c3r10_prod_backup_output" ] || record_c3r10_backup_failure \
  "backup_dir prod fabricou caminho sem LOCAWEB_ACCOUNT_ROOT: ${c3r10_prod_backup_output}"

[ "$c3r10_backup_failures" -eq 0 ] || exit 1

# 16. Cada etapa obrigatória da ponte precisa preservar seu status e impedir a
# próxima etapa, mesmo quando bridge_runtime_directory está em um OR-list.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r10_bridge_log="$TMP_DIR/c3r10-bridge.log"
c3r10_bridge_failures=0
c3r10_bridge_root="$TMP_DIR/c3r10-bridge-root"

record_c3r10_bridge_failure() {
  printf 'FAIL: %s\n' "$*" >&2
  c3r10_bridge_failures=$((c3r10_bridge_failures + 1))
}

log() { :; }
is_remote_env() { return 0; }
content_path() { printf '/synthetic/%s/wp-content\n' "$1"; }
remote_run_idempotent() {
  printf 'probe\n' >> "$c3r10_bridge_log"
  return 0
}
uonix_rsync_to_runner() {
  printf 'download\n' >> "$c3r10_bridge_log"
  [ "$C3R10_BRIDGE_FAIL_STAGE" = download ] && return 61
  mkdir -p "$3"
  printf 'fixture\n' > "$3/file.txt"
  return 0
}
write_directory_manifest() {
  printf 'manifest-write\n' >> "$c3r10_bridge_log"
  [ "$C3R10_BRIDGE_FAIL_STAGE" = manifest-write ] && return 62
  printf 'synthetic manifest\n' > "$2"
  return 0
}
verify_directory_manifest() {
  printf 'manifest-verify\n' >> "$c3r10_bridge_log"
  [ "$C3R10_BRIDGE_FAIL_STAGE" = manifest-verify ] && return 63
  return 0
}
remote_run() {
  printf 'target-mkdir\n' >> "$c3r10_bridge_log"
  [ "$C3R10_BRIDGE_FAIL_STAGE" = target-mkdir ] && return 64
  return 0
}
bridge_upload_payload() {
  printf 'upload\n' >> "$c3r10_bridge_log"
  [ "$C3R10_BRIDGE_FAIL_STAGE" = upload ] && return 65
  return 0
}
verify_payload_at_target() {
  printf 'target-verify\n' >> "$c3r10_bridge_log"
  [ "$C3R10_BRIDGE_FAIL_STAGE" = target-verify ] && return 66
  return 0
}

for c3r10_bridge_case in \
  'download:61:probe:download:' \
  'manifest-write:62:probe:download:manifest-write:' \
  'manifest-verify:63:probe:download:manifest-write:manifest-verify:' \
  'target-mkdir:64:probe:download:manifest-write:manifest-verify:target-mkdir:' \
  'upload:65:probe:download:manifest-write:manifest-verify:target-mkdir:upload:' \
  'target-verify:66:probe:download:manifest-write:manifest-verify:target-mkdir:upload:target-verify:'; do
  C3R10_BRIDGE_FAIL_STAGE="${c3r10_bridge_case%%:*}"
  c3r10_bridge_remainder="${c3r10_bridge_case#*:}"
  c3r10_bridge_expected_status="${c3r10_bridge_remainder%%:*}"
  c3r10_bridge_expected_log="${c3r10_bridge_remainder#*:}"
  : > "$c3r10_bridge_log"
  CLONE_RUNTIME_DIRECTORY_COUNT=0
  CLONE_RUNTIME_FILE_COUNT=0
  : "$CLONE_RUNTIME_DIRECTORY_COUNT" "$CLONE_RUNTIME_FILE_COUNT"
  if bridge_runtime_directory qa dev uploads "$c3r10_bridge_root" >/dev/null 2>&1; then
    c3r10_bridge_status=0
  else
    c3r10_bridge_status=$?
  fi
  [ "$c3r10_bridge_status" -eq "$c3r10_bridge_expected_status" ] || \
    record_c3r10_bridge_failure \
      "ponte mascarou ${C3R10_BRIDGE_FAIL_STAGE} (esperado ${c3r10_bridge_expected_status}, obtido ${c3r10_bridge_status})"
  [ "$(tr '\n' ':' < "$c3r10_bridge_log")" = "$c3r10_bridge_expected_log" ] || \
    record_c3r10_bridge_failure \
      "ponte avançou após ${C3R10_BRIDGE_FAIL_STAGE}: $(tr '\n' ':' < "$c3r10_bridge_log")"
done

# A verificação remota inclui envio do manifest, checksum e remoção do manifest;
# cada boundary também precisa preservar seu próprio status.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r10_target_verify_log="$TMP_DIR/c3r10-target-verify.log"
c3r10_target_manifest="$TMP_DIR/c3r10-target-manifest.sha256"
printf 'synthetic manifest\n' > "$c3r10_target_manifest"
is_remote_env() { return 0; }
uonix_stream_to() {
  printf 'stream\n' >> "$c3r10_target_verify_log"
  cat >/dev/null
  [ "$C3R10_VERIFY_FAIL_STAGE" = stream ] && return 67
  return 0
}
remote_run() {
  case "$2" in
    *sha256sum*)
      printf 'remote-checksum\n' >> "$c3r10_target_verify_log"
      [ "$C3R10_VERIFY_FAIL_STAGE" = checksum ] && return 68
      ;;
    *'rm -f'*)
      printf 'remote-cleanup\n' >> "$c3r10_target_verify_log"
      [ "$C3R10_VERIFY_FAIL_STAGE" = cleanup ] && return 69
      ;;
    *) return 90 ;;
  esac
  return 0
}

for c3r10_verify_case in \
  'stream:67:stream:' \
  'checksum:68:stream:remote-checksum:remote-cleanup:' \
  'cleanup:69:stream:remote-checksum:remote-cleanup:'; do
  C3R10_VERIFY_FAIL_STAGE="${c3r10_verify_case%%:*}"
  c3r10_verify_remainder="${c3r10_verify_case#*:}"
  c3r10_verify_expected_status="${c3r10_verify_remainder%%:*}"
  c3r10_verify_expected_log="${c3r10_verify_remainder#*:}"
  : > "$c3r10_target_verify_log"
  if verify_payload_at_target qa '/synthetic/target' "$c3r10_target_manifest" >/dev/null 2>&1; then
    c3r10_verify_status=0
  else
    c3r10_verify_status=$?
  fi
  [ "$c3r10_verify_status" -eq "$c3r10_verify_expected_status" ] || \
    record_c3r10_bridge_failure \
      "verificação no destino mascarou ${C3R10_VERIFY_FAIL_STAGE} (esperado ${c3r10_verify_expected_status}, obtido ${c3r10_verify_status})"
  [ "$(tr '\n' ':' < "$c3r10_target_verify_log")" = "$c3r10_verify_expected_log" ] || \
    record_c3r10_bridge_failure \
      "verificação no destino avançou após ${C3R10_VERIFY_FAIL_STAGE}: $(tr '\n' ':' < "$c3r10_target_verify_log")"
done

# sync_runtime_files para imediatamente no primeiro diretório com falha.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r10_sync_log="$TMP_DIR/c3r10-sync.log"
: > "$c3r10_sync_log"
log() { :; }
sync_one_dir() {
  printf '%s\n' "$3" >> "$c3r10_sync_log"
  [ "$3" = uploads ] && return 74
  return 0
}
INCLUDE_GIT_FILES=0
if sync_runtime_files qa dev >/dev/null 2>&1; then
  c3r10_sync_status=0
else
  c3r10_sync_status=$?
fi
[ "$c3r10_sync_status" -eq 74 ] || record_c3r10_bridge_failure \
  "sync_runtime_files mascarou uploads (esperado 74, obtido ${c3r10_sync_status})"
[ "$(tr '\n' ':' < "$c3r10_sync_log")" = 'uploads:' ] || \
  record_c3r10_bridge_failure \
    "sync_runtime_files avançou após uploads: $(tr '\n' ':' < "$c3r10_sync_log")"

# A mesma falha, depois do import, conserva o status primário, aciona exatamente
# um rollback e não publica nenhum indicador de sucesso.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r10_bridge_boundary_log="$TMP_DIR/c3r10-bridge-boundary.log"
: > "$c3r10_bridge_boundary_log"
log() { printf 'log:%s\n' "$*" >> "$c3r10_bridge_boundary_log"; }
backup_dir() { printf '%s\n' "$TMP_DIR/c3r10-bridge-backup"; }
prepare_target_backup() { :; }
snapshot_users() { :; }
snapshot_options() { :; }
export_source_db() { : > "$2"; }
import_db_to_target() { :; }
restore_users() { :; }
set_target_identity() { :; }
restore_options() { :; }
wp_exec() { :; }
remap_missing_authors() { :; }
sync_one_dir() {
  printf 'sync:%s\n' "$3" >> "$c3r10_bridge_boundary_log"
  [ "$3" = uploads ] && return 255
  return 0
}
enforce_smtp_plugin_policy() { printf 'smtp-after-failure\n' >> "$c3r10_bridge_boundary_log"; }
clear_cache() { printf 'cache-after-failure\n' >> "$c3r10_bridge_boundary_log"; }
validate_target_after_clone() { printf 'validation-after-failure\n' >> "$c3r10_bridge_boundary_log"; }
validate_compressx_delivery() { printf 'compressx-after-failure\n' >> "$c3r10_bridge_boundary_log"; }
write_clone_summary() { printf 'summary-after-failure\n' >> "$c3r10_bridge_boundary_log"; }
rollback_target() { printf 'rollback\n' >> "$c3r10_bridge_boundary_log"; }
env_url() { printf 'https://%s.example.invalid\n' "$1"; }
env_title() { printf 'Synthetic %s\n' "$1"; }
SOURCE=qa
TARGET=dev
CLONE_TMP_DIR="$TMP_DIR/c3r10-bridge-boundary"
mkdir -p "$CLONE_TMP_DIR"
TARGET_BACKUP_DIR=''
MUTATION_STARTED=0
ROLLBACK_RUNNING=0
CLONE_FAILURE_STATUS=0
INCLUDE_GIT_FILES=0
: "$INCLUDE_GIT_FILES"
if execute_clone_with_rollback >/dev/null 2>&1; then
  c3r10_bridge_boundary_status=0
else
  c3r10_bridge_boundary_status=$?
fi
[ "$c3r10_bridge_boundary_status" -eq 255 ] || record_c3r10_bridge_failure \
  "boundary mascarou ponte (esperado 255, obtido ${c3r10_bridge_boundary_status})"
[ "$CLONE_FAILURE_STATUS" -eq 255 ] || record_c3r10_bridge_failure \
  "boundary perdeu causa primária da ponte (esperado 255, obtido ${CLONE_FAILURE_STATUS})"
[ "$(awk '$0 == "rollback" { count++ } END { print count + 0 }' "$c3r10_bridge_boundary_log")" -eq 1 ] || \
  record_c3r10_bridge_failure 'falha da ponte não acionou exatamente um rollback'
[ "$(awk '/^sync:/ { printf "%s:", $0 }' "$c3r10_bridge_boundary_log")" = 'sync:uploads:' ] || \
  record_c3r10_bridge_failure 'boundary sincronizou diretórios posteriores após falha da ponte'
case "$(<"$c3r10_bridge_boundary_log")" in
  *smtp-after-failure*|*cache-after-failure*|*validation-after-failure*|*compressx-after-failure*|*summary-after-failure*|*'Clone concluído:'*)
    record_c3r10_bridge_failure 'boundary avançou ou publicou sucesso após falha da ponte'
    ;;
esac

[ "$c3r10_bridge_failures" -eq 0 ] || exit 1

# 17. Os quatro wp_exec de identidade são uma sequência fail-closed: qualquer
# falha preserva o status e impede todas as atualizações posteriores.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r10_identity_log="$TMP_DIR/c3r10-identity.log"
c3r10_identity_failures=0

record_c3r10_identity_failure() {
  printf 'FAIL: %s\n' "$*" >&2
  c3r10_identity_failures=$((c3r10_identity_failures + 1))
}

log() { :; }
env_url() { printf 'https://%s.example.invalid\n' "$1"; }
env_title() { printf 'Synthetic %s\n' "$1"; }
wp_exec() {
  local operation
  case "$2" in
    search-replace) operation='search-replace' ;;
    option) operation="option-${4:-unknown}" ;;
    *) operation='unexpected' ;;
  esac
  printf '%s\n' "$operation" >> "$c3r10_identity_log"
  [ "$operation" = "$C3R10_IDENTITY_FAIL_OPERATION" ] && return "$C3R10_IDENTITY_FAIL_STATUS"
  return 0
}

for c3r10_identity_case in \
  'search-replace:73:search-replace:' \
  'option-home:74:search-replace:option-home:' \
  'option-siteurl:75:search-replace:option-home:option-siteurl:' \
  'option-blogname:76:search-replace:option-home:option-siteurl:option-blogname:'; do
  C3R10_IDENTITY_FAIL_OPERATION="${c3r10_identity_case%%:*}"
  c3r10_identity_remainder="${c3r10_identity_case#*:}"
  C3R10_IDENTITY_FAIL_STATUS="${c3r10_identity_remainder%%:*}"
  c3r10_identity_expected_log="${c3r10_identity_remainder#*:}"
  : > "$c3r10_identity_log"
  if set_target_identity qa 'https://source.example.invalid' >/dev/null 2>&1; then
    c3r10_identity_status=0
  else
    c3r10_identity_status=$?
  fi
  [ "$c3r10_identity_status" -eq "$C3R10_IDENTITY_FAIL_STATUS" ] || \
    record_c3r10_identity_failure \
      "set_target_identity mascarou ${C3R10_IDENTITY_FAIL_OPERATION} (esperado ${C3R10_IDENTITY_FAIL_STATUS}, obtido ${c3r10_identity_status})"
  [ "$(tr '\n' ':' < "$c3r10_identity_log")" = "$c3r10_identity_expected_log" ] || \
    record_c3r10_identity_failure \
      "set_target_identity avançou após ${C3R10_IDENTITY_FAIL_OPERATION}: $(tr '\n' ':' < "$c3r10_identity_log")"
done

# Falha intermediária real após MUTATION_STARTED cruza o boundary uma vez e não
# alcança restore/sync/validação/resumo.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
c3r10_identity_boundary_log="$TMP_DIR/c3r10-identity-boundary.log"
: > "$c3r10_identity_boundary_log"
log() { printf 'log:%s\n' "$*" >> "$c3r10_identity_boundary_log"; }
backup_dir() { printf '%s\n' "$TMP_DIR/c3r10-identity-backup"; }
prepare_target_backup() { :; }
snapshot_users() { :; }
snapshot_options() { :; }
export_source_db() { : > "$2"; }
import_db_to_target() { :; }
restore_users() { :; }
restore_options() { printf 'restore-after-failure\n' >> "$c3r10_identity_boundary_log"; }
remap_missing_authors() { printf 'authors-after-failure\n' >> "$c3r10_identity_boundary_log"; }
sync_runtime_files() { printf 'sync-after-failure\n' >> "$c3r10_identity_boundary_log"; }
enforce_smtp_plugin_policy() { printf 'smtp-after-failure\n' >> "$c3r10_identity_boundary_log"; }
clear_cache() { printf 'cache-after-failure\n' >> "$c3r10_identity_boundary_log"; }
validate_target_after_clone() { printf 'validation-after-failure\n' >> "$c3r10_identity_boundary_log"; }
validate_compressx_delivery() { printf 'compressx-after-failure\n' >> "$c3r10_identity_boundary_log"; }
write_clone_summary() { printf 'summary-after-failure\n' >> "$c3r10_identity_boundary_log"; }
rollback_target() { printf 'rollback\n' >> "$c3r10_identity_boundary_log"; }
env_url() { printf 'https://%s.example.invalid\n' "$1"; }
env_title() { printf 'Synthetic %s\n' "$1"; }
C3R10_IDENTITY_FAIL_OPERATION='option-siteurl'
C3R10_IDENTITY_FAIL_STATUS=75
wp_exec() {
  local operation
  case "$2" in
    search-replace) operation='search-replace' ;;
    option) operation="option-${4:-unknown}" ;;
    *) operation='unexpected' ;;
  esac
  printf 'wp:%s\n' "$operation" >> "$c3r10_identity_boundary_log"
  [ "$operation" = "$C3R10_IDENTITY_FAIL_OPERATION" ] && return "$C3R10_IDENTITY_FAIL_STATUS"
  return 0
}
SOURCE=qa
TARGET=dev
CLONE_TMP_DIR="$TMP_DIR/c3r10-identity-boundary"
mkdir -p "$CLONE_TMP_DIR"
TARGET_BACKUP_DIR=''
MUTATION_STARTED=0
ROLLBACK_RUNNING=0
CLONE_FAILURE_STATUS=0
if execute_clone_with_rollback >/dev/null 2>&1; then
  c3r10_identity_boundary_status=0
else
  c3r10_identity_boundary_status=$?
fi
[ "$c3r10_identity_boundary_status" -eq 75 ] || record_c3r10_identity_failure \
  "boundary mascarou identidade intermediária (esperado 75, obtido ${c3r10_identity_boundary_status})"
[ "$CLONE_FAILURE_STATUS" -eq 75 ] || record_c3r10_identity_failure \
  "boundary perdeu causa primária da identidade (esperado 75, obtido ${CLONE_FAILURE_STATUS})"
[ "$(awk '$0 == "rollback" { count++ } END { print count + 0 }' "$c3r10_identity_boundary_log")" -eq 1 ] || \
  record_c3r10_identity_failure 'falha de identidade não acionou exatamente um rollback'
[ "$(awk '/^wp:/ { printf "%s:", $0 }' "$c3r10_identity_boundary_log")" = \
  'wp:search-replace:wp:option-home:wp:option-siteurl:' ] || \
  record_c3r10_identity_failure 'boundary executou wp_exec posterior à falha intermediária'
case "$(<"$c3r10_identity_boundary_log")" in
  *restore-after-failure*|*authors-after-failure*|*sync-after-failure*|*smtp-after-failure*|*cache-after-failure*|*validation-after-failure*|*compressx-after-failure*|*summary-after-failure*|*'Clone concluído:'*)
    record_c3r10_identity_failure 'boundary avançou ou publicou sucesso após falha de identidade'
    ;;
esac

[ "$c3r10_identity_failures" -eq 0 ] || exit 1

# remap_missing_authors é exercida diretamente, sem mock da função sob teste.
# O mapa canônico precisa chegar à função atual; falhas de prefixo, fallback e
# lookup devem preservar o status e impedir qualquer UPDATE parcial.
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"
# Guarda de bancos distintos consulta o banco real; no fixture retornaria
# vazio e abortaria antes do comportamento sob teste. Cobertura propria em
# test-clone-database-transport.sh.
# shellcheck disable=SC2329
assert_distinct_databases() { :; }
remap_query_log="$TMP_DIR/remap-missing-authors-queries.log"
remap_lookup_calls_file="$TMP_DIR/remap-missing-authors-lookup-calls"
remap_map_file="$TMP_DIR/remap-missing-authors-source.tsv"
remap_failures=0
printf '5\t616C696365\n7\t\n' > "$remap_map_file"
chmod 600 "$remap_map_file"

record_remap_failure() {
  printf 'FAIL: %s\n' "$*" >&2
  remap_failures=$((remap_failures + 1))
}

log() { :; }
is_remote_env() { [ "$1" != local ]; }
local_db_prefix() {
  printf 'prefix\n' >> "$remap_query_log"
  [ "$REMAP_PREFIX_STATUS" -eq 0 ] || return "$REMAP_PREFIX_STATUS"
  printf 'wpis_\n'
}
local_wp() {
  local lookup_calls

  case " $* " in
    *' user list --role=administrator --field=ID '*)
      printf '%s\n' "$REMAP_ADMIN_IDS"
      return "$REMAP_ADMIN_STATUS"
      ;;
    *' user list --field=ID '*)
      printf '%s\n' "$REMAP_FALLBACK_IDS"
      return "$REMAP_FALLBACK_STATUS"
      ;;
    *' db query '*)
      lookup_calls="$(<"$remap_lookup_calls_file")"
      lookup_calls=$((lookup_calls + 1))
      printf '%s\n' "$lookup_calls" > "$remap_lookup_calls_file"
      printf '%s\n' "$REMAP_LOOKUP_ID"
      return "$REMAP_LOOKUP_STATUS"
      ;;
    *) return 98 ;;
  esac
}
local_db_query() {
  REMAP_QUERY_COUNT=$((REMAP_QUERY_COUNT + 1))
  printf '%s\n' "$1" >> "$remap_query_log"
}

run_remap_case() {
  local case_name="$1"
  local expected_status="$2"
  local expected_query_count="$3"
  local expected_query="${4:-}"
  local expected_lookup_count="${5:-0}"
  local actual_status
  local actual_lookup_count

  REMAP_QUERY_COUNT=0
  : > "$remap_query_log"
  printf '0\n' > "$remap_lookup_calls_file"
  if remap_missing_authors local "$remap_map_file" >/dev/null 2>&1; then
    actual_status=0
  else
    actual_status=$?
  fi
  actual_lookup_count="$(<"$remap_lookup_calls_file")"

  [ "$actual_status" -eq "$expected_status" ] || record_remap_failure \
    "${case_name}: status esperado ${expected_status}, obtido ${actual_status}"
  [ "$REMAP_QUERY_COUNT" -eq "$expected_query_count" ] || record_remap_failure \
    "${case_name}: queries esperadas ${expected_query_count}, obtidas ${REMAP_QUERY_COUNT}"
  [ "$actual_lookup_count" -eq "$expected_lookup_count" ] || record_remap_failure \
    "${case_name}: lookups esperados ${expected_lookup_count}, obtidos ${actual_lookup_count}"
  if [ -n "$expected_query" ]; then
    [ "$(tail -n 1 "$remap_query_log")" = "$expected_query" ] || record_remap_failure \
      "${case_name}: query válida inesperada: $(tail -n 1 "$remap_query_log")"
  fi
}

PRESERVE_DESTINATION_USERS=1
REMAP_PREFIX_STATUS=81
REMAP_ADMIN_STATUS=0
REMAP_ADMIN_IDS=9
REMAP_FALLBACK_STATUS=0
REMAP_FALLBACK_IDS=9
REMAP_LOOKUP_STATUS=0
REMAP_LOOKUP_ID=12
run_remap_case 'prefix-failure' 81 0

REMAP_PREFIX_STATUS=0
REMAP_ADMIN_STATUS=82
run_remap_case 'administrator-list-failure' 82 0

REMAP_ADMIN_STATUS=0
REMAP_ADMIN_IDS=''
REMAP_FALLBACK_STATUS=83
run_remap_case 'fallback-list-failure' 83 0

REMAP_FALLBACK_STATUS=0
REMAP_FALLBACK_IDS=''
run_remap_case 'empty-fallback-id' 1 0

REMAP_ADMIN_IDS='xyz'
REMAP_FALLBACK_IDS=9
run_remap_case 'malformed-fallback-id' 1 0

REMAP_ADMIN_IDS=9
REMAP_LOOKUP_STATUS=84
run_remap_case 'author-lookup-failure' 84 0 '' 1

REMAP_LOOKUP_STATUS=0
REMAP_LOOKUP_ID='abc'
run_remap_case 'malformed-lookup-id' 1 0 '' 1

REMAP_LOOKUP_ID=''
run_remap_case 'empty-lookup-uses-fallback' 0 1 \
  'UPDATE wpis_posts SET post_author = CASE post_author WHEN 5 THEN 9 WHEN 7 THEN 9 ELSE 9 END WHERE post_author IN (5,7)' 1

REMAP_LOOKUP_ID=12
run_remap_case 'valid-author-remap' 0 1 \
  'UPDATE wpis_posts SET post_author = CASE post_author WHEN 5 THEN 12 WHEN 7 THEN 9 ELSE 9 END WHERE post_author IN (5,7)' 1

[ "$remap_failures" -eq 0 ] || exit 1

# Os auxiliares legados de staging não podem mais abrir transporte ou manter
# paths destrutivos. O contrato é uma falha explícita que aponta somente para
# workflows canônicos e para o dry-run central.
for legacy_staging_script in deploy-staging.sh sync-from-staging.sh; do
  legacy_staging_path="$ROOT_DIR/scripts/$legacy_staging_script"
  legacy_staging_output="$TMP_DIR/$legacy_staging_script.out"
  if bash "$legacy_staging_path" >"$legacy_staging_output" 2>&1; then
    fail "$legacy_staging_script deixou de falhar fechado"
  fi
  legacy_staging_message="$(<"$legacy_staging_output")"
  case "$legacy_staging_message" in
    *workflow*|*Workflow*) ;;
    *) fail "$legacy_staging_script não aponta para workflows canônicos" ;;
  esac
  case "$legacy_staging_message" in
    *'clone-environment.sh --dry-run'*) ;;
    *) fail "$legacy_staging_script não aponta para clone dry-run" ;;
  esac
  legacy_staging_source="$(<"$legacy_staging_path")"
  case "$legacy_staging_source" in
    *'/home2/uonix/qa_uonix'*|*rsync*|*ssh*)
      fail "$legacy_staging_script ainda contém transporte ou path legado"
      ;;
  esac
done

[ "$RESTORE_FAILURES" -eq 0 ] || exit 1
[ "$SNAPSHOT_FAILURES" -eq 0 ] || exit 1

printf 'PASS: exclusões, backup, credenciais, opções protegidas e rollback falham de forma segura.\n'
