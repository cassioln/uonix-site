#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/lib/ssh-transport.sh
source "${ROOT_DIR}/scripts/lib/ssh-transport.sh"
CLONE_RUN_ID="${UONIX_CLONE_RUN_ID:-$(date +%Y%m%d-%H%M%S)-$$-${RANDOM}}"
STAMP="$CLONE_RUN_ID"

SOURCE=""
TARGET=""
CLONE_MODE=""
REPLACE_USERS="0"
CONFIRMATION=""
INCLUDE_GIT_FILES="0"
PRESERVE_DESTINATION_USERS="1"
MUTATION_STARTED="0"
TARGET_BACKUP_DIR=""
ROLLBACK_RUNNING="0"
CLONE_TMP_DIR=""
CLONE_RUNTIME_FILE_COUNT="0"
CLONE_RUNTIME_DIRECTORY_COUNT="0"
CLONE_LOCK_HELD="0"
CLONE_LOCK_PATH=""
CLONE_LOCK_ENV=""

LOCAL_BACKUP_ROOT="${LOCAL_BACKUP_ROOT:-${ROOT_DIR}/backups/clone}"

LOCAL_COMPOSE_FILE="${LOCAL_COMPOSE_FILE:-${ROOT_DIR}/local/compose.yml}"
LOCAL_COMPOSE_PROJECT="${LOCAL_COMPOSE_PROJECT:-uonix-local}"
LOCAL_WP_CONTENT="${LOCAL_WP_CONTENT:-${ROOT_DIR}/local/wp-content}"
LOCAL_DB_CONTAINER="${LOCAL_DB_CONTAINER:-uonix-local-db}"
LOCAL_DB_NAME="${LOCAL_DB_NAME:-uonix_db}"
LOCAL_DB_USER="${LOCAL_DB_USER:-uonix_user}"
LOCAL_DB_PASSWORD="${LOCAL_DB_PASSWORD:-uonix_pass}"
LOCAL_TABLE_PREFIX="${LOCAL_TABLE_PREFIX:-wpis_}"

COMPRESSX_CRITICAL_IMAGE_PATHS=(
  /wp-content/uploads/2026/03/olhal-ancoragem-uonix-catalago.png
  /wp-content/uploads/2026/03/thumb-acessorios.png
  /wp-content/uploads/2026/01/alfa_servicos-e-treinamentos.webp
)

EXCLUDED_RSYNC_ARGS=(
  --exclude='.DS_Store'
  --exclude='._*'
  --exclude='*~'
  --exclude='*.log'
  --exclude='cache/'
  --exclude='speedycache/'
  --exclude='wc-logs/'
  --exclude='wp-staging/'
  --exclude='wpmc-trash/'
  --exclude='wp-personal-data-exports/'
  --exclude='curriculos-recebidos/'
  --exclude='FLUENT_PDF_TEMPLATES/'
  --exclude='gosmtp-attachments/'
  --exclude='loginizer-config/'
  --exclude='speedycache-binary/'
  --exclude='ai1wm-backups/'
  --exclude='wpvivid_uploads/'
  --exclude='wpvividbackups/'
  --exclude='wpvivid_staging/'
  --exclude='logs/'
  --exclude='uonix-local/'
)

PLUGIN_RSYNC_EXCLUDES=(
  # Plugins com configuração própria por ambiente não entram em clones.
  --exclude='all-in-one-wp-migration-10GB/'
  --exclude='backuply/'
  --exclude='backuply-pro/'
  --exclude='compressx/'
  --exclude='fluent-smtp/'
  --exclude='fluentform/'
  # GoSMTP é legado removido; manter excluído impede que volte por runtime antigo.
  --exclude='gosmtp/'
  --exclude='gosmtp-pro/'
  --exclude='loginizer/'
  --exclude='loginizer-security/'
  --exclude='speedycache/'
  --exclude='speedycache-pro/'
  --exclude='wp-mail-logging/'
  --exclude='wpvivid-backuprestore/'
)

SUPPORTED_SMTP_PLUGIN="fluent-smtp"
LEGACY_SMTP_PLUGINS=(gosmtp gosmtp-pro)
CRITICAL_POST_CLONE_PLUGINS=(
  "$SUPPORTED_SMTP_PLUGIN"
  fluentform
)

usage() {
  cat <<'USAGE'
Uso:
  scripts/clone-environment.sh --source=prod|qa|dev|local --target=prod|qa|dev|local --dry-run
  scripts/clone-environment.sh --source=prod|qa|dev|local --target=prod|qa|dev|local --execute [opções]

Opções:
  --dry-run                       Executa somente preflight, sem alterar o destino.
  --execute                       Faz dry-run obrigatório no mesmo processo e só então executa.
  --replace-users                 Substitui usuários do destino; desmarcado por padrão.
  --confirmation='CLONAR X PARA PROD'
                                  Obrigatória quando o destino é produção.

Credenciais e paths são recebidos exclusivamente por Environment Variables/arquivos 0600.
USAGE
}

log() {
  printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"
}

die() {
  printf 'Erro: %s\n' "$*" >&2
  return 1
}

shell_join() {
  uonix_transport_shell_join "$@"
}

remote_run() {
  local environment="$1"
  local script="$2"
  uonix_transport_ssh_once "$environment" "bash -s <<< $(printf '%q' "$script")"
}

remote_run_idempotent() {
  local environment="$1"
  local script="$2"
  uonix_transport_ssh_retry "$environment" "bash -s <<< $(printf '%q' "$script")"
}

remote_stream_to_file() {
  local environment="$1"
  local remote_command="$2"
  local output_file="$3"
  uonix_transport_stream_to_file "$environment" "$remote_command" "$output_file"
}

remote_import_gzip_dump() {
  local environment="$1"
  local dump_file="$2"
  local remote_command="$3"
  uonix_transport_import_gzip "$environment" "$dump_file" "$remote_command"
}

remote_spec() {
  local environment="$1"
  local path="$2"
  printf '%s@%s:%s\n' \
    "$(uonix_environment_field "$environment" user)" \
    "$(uonix_environment_field "$environment" host)" \
    "$path"
}

valid_clone_run_id() {
  case "$1" in
    ''|*[!A-Za-z0-9._-]*) return 1 ;;
    *) return 0 ;;
  esac
}

clone_lock_path() {
  local env="$1"
  local wp_root

  if is_remote_env "$env"; then
    wp_root="$(wp_path "$env")" || return $?
    printf '%s/.uonix-operation.lock\n' "$wp_root"
  else
    printf '%s/.locks/%s.lock\n' "$LOCAL_BACKUP_ROOT" "$env"
  fi
}

acquire_clone_lock() {
  local env="$1"
  local lock_path

  valid_clone_run_id "$CLONE_RUN_ID" || {
    die 'RUN_ID de clone inválido'
    return 1
  }
  [ "$CLONE_LOCK_HELD" = 0 ] || {
    die 'Esta execução já possui um lock de clone'
    return 1
  }
  lock_path="$(clone_lock_path "$env")" || return $?

  if is_remote_env "$env"; then
    remote_run "$env" "set -euo pipefail
umask 077
lock_path=$(printf '%q' "$lock_path")
mkdir -- \"\$lock_path\"
if ! printf '%s\\n' $(printf '%q' "$CLONE_RUN_ID") > \"\$lock_path/owner\"; then
  rmdir -- \"\$lock_path\" 2>/dev/null || true
  exit 1
fi
chmod 600 \"\$lock_path/owner\"" || return $?
  else
    umask 077
    mkdir -p "$(dirname "$lock_path")" || return $?
    chmod 700 "$(dirname "$lock_path")" || return $?
    mkdir -- "$lock_path" || return $?
    if ! printf '%s\n' "$CLONE_RUN_ID" > "$lock_path/owner"; then
      rmdir -- "$lock_path" 2>/dev/null || true
      return 1
    fi
    chmod 600 "$lock_path/owner" || {
      rm -f -- "$lock_path/owner"
      rmdir -- "$lock_path" 2>/dev/null || true
      return 1
    }
  fi

  CLONE_LOCK_HELD=1
  CLONE_LOCK_PATH="$lock_path"
  CLONE_LOCK_ENV="$env"
}

release_clone_lock() {
  local owner

  [ "$CLONE_LOCK_HELD" = 1 ] || return 0
  valid_clone_run_id "$CLONE_RUN_ID" || return 1
  [ -n "$CLONE_LOCK_PATH" ] && [ -n "$CLONE_LOCK_ENV" ] || return 1

  if is_remote_env "$CLONE_LOCK_ENV"; then
    remote_run "$CLONE_LOCK_ENV" "set -euo pipefail
lock_path=$(printf '%q' "$CLONE_LOCK_PATH")
owner=\"\$(cat \"\$lock_path/owner\")\"
[ \"\$owner\" = $(printf '%q' "$CLONE_RUN_ID") ]
rm -f -- \"\$lock_path/owner\"
rmdir -- \"\$lock_path\"" || return $?
  else
    [ -f "$CLONE_LOCK_PATH/owner" ] || return 1
    owner="$(<"$CLONE_LOCK_PATH/owner")" || return $?
    [ "$owner" = "$CLONE_RUN_ID" ] || return 1
    rm -f -- "$CLONE_LOCK_PATH/owner" || return $?
    rmdir -- "$CLONE_LOCK_PATH" || return $?
  fi

  CLONE_LOCK_HELD=0
  CLONE_LOCK_PATH=''
  CLONE_LOCK_ENV=''
}

local_wp() {
  cd "$ROOT_DIR"
  podman-compose -p "$LOCAL_COMPOSE_PROJECT" -f "$LOCAL_COMPOSE_FILE" --profile tools run --rm --no-deps -T wpcli --skip-plugins --skip-themes "$@" </dev/null
}

local_db_dump() {
  MYSQL_PWD="$LOCAL_DB_PASSWORD" podman exec \
    -e MYSQL_PWD \
    "$LOCAL_DB_CONTAINER" \
    mariadb-dump \
    -u "$LOCAL_DB_USER" \
    --skip-ssl \
    --single-transaction \
    --quick \
    --skip-lock-tables \
    "$LOCAL_DB_NAME" \
    "$@"
}

local_db_import() {
  MYSQL_PWD="$LOCAL_DB_PASSWORD" podman exec -i \
    -e MYSQL_PWD \
    "$LOCAL_DB_CONTAINER" \
    mariadb \
    -u "$LOCAL_DB_USER" \
    --skip-ssl \
    "$LOCAL_DB_NAME"
}

local_db_prefix() {
  printf '%s\n' "$LOCAL_TABLE_PREFIX"
}

local_db_query() {
  MYSQL_PWD="$LOCAL_DB_PASSWORD" podman exec \
    -e MYSQL_PWD \
    "$LOCAL_DB_CONTAINER" \
    mariadb \
    -u "$LOCAL_DB_USER" \
    --skip-ssl \
    "$LOCAL_DB_NAME" \
    -e "$1"
}

local_db_dump_options() {
  local options_table="$1"
  local where="$2"
  local query

  query="$(option_upsert_select_sql "$options_table" "$where")" || return $?

  MYSQL_PWD="$LOCAL_DB_PASSWORD" podman exec \
    -e MYSQL_PWD \
    "$LOCAL_DB_CONTAINER" \
    mariadb \
    -u "$LOCAL_DB_USER" \
    --skip-ssl \
    --batch \
    --raw \
    --skip-column-names \
    "$LOCAL_DB_NAME" \
    -e "$query"
}

protected_options_where() {
  cat <<'SQL'
option_name IN ('admin_email','active_plugins','active_sitewide_plugins','auto_update_plugins','cron')
OR option_name = 'downloaded_font_files'
OR option_name LIKE '%backuply%'
OR option_name LIKE '%ai1wm%'
OR option_name LIKE 'compressx%'
OR option_name LIKE '%fluentform%'
OR option_name LIKE '\_fluent\_%'
OR option_name LIKE 'fluent\_%'
OR option_name LIKE '%fluentmail%'
OR option_name LIKE '%mailchimp%'
OR option_name LIKE '%gosmtp%'
OR option_name LIKE '%smtp%'
OR option_name LIKE 'mailserver\_%'
OR option_name LIKE '%loginizer%'
OR option_name LIKE '%speedycache%'
OR option_name LIKE '%turnstile%'
OR option_name LIKE '%captcha%'
OR option_name LIKE '%recaptcha%'
OR option_name LIKE '%hcaptcha%'
OR option_name LIKE '%wp_captcha%'
OR option_name LIKE 'lz\_%'
OR option_name LIKE '%wp_mail_logging%'
OR option_name LIKE '%mail_logging%'
OR option_name LIKE '%wpvivid%'
SQL
}

quote_sql_identifier() {
  local identifier="$1"

  identifier="${identifier//\`/\`\`}"
  printf "\`%s\`" "$identifier"
}

option_upsert_select_sql() {
  local options_table="$1"
  local where="$2"
  local quoted_table

  quoted_table="$(quote_sql_identifier "$options_table")" || return $?

  cat <<SQL
SELECT CONCAT(
  'INSERT INTO ${quoted_table} (\`option_name\`,\`option_value\`,\`autoload\`) VALUES (',
  QUOTE(option_name), ',', QUOTE(option_value), ',', QUOTE(autoload),
  ') ON DUPLICATE KEY UPDATE \`option_value\`=VALUES(\`option_value\`), \`autoload\`=VALUES(\`autoload\`);'
)
FROM ${quoted_table}
WHERE ${where};
SQL
}

canonical_clone_environment() {
  uonix_env_canonical "$1"
}

clone_pair_allowed() {
  local source_environment
  local target_environment
  source_environment="$(canonical_clone_environment "$1")" || return
  target_environment="$(canonical_clone_environment "$2")" || return
  [ "$source_environment" != "$target_environment" ]
}

clone_execution_mode() {
  local source_environment
  local target_environment
  source_environment="$(canonical_clone_environment "$1")" || return
  target_environment="$(canonical_clone_environment "$2")" || return

  if [ "$source_environment" = local ] || [ "$target_environment" = local ]; then
    printf 'mac\n'
  else
    printf 'github-runner\n'
  fi
}

clone_required_confirmation() {
  local source_environment
  local target_environment
  source_environment="$(canonical_clone_environment "$1")" || return
  target_environment="$(canonical_clone_environment "$2")" || return
  printf 'CLONAR %s PARA %s\n' \
    "$(printf '%s' "$source_environment" | tr '[:lower:]' '[:upper:]')" \
    "$(printf '%s' "$target_environment" | tr '[:lower:]' '[:upper:]')"
}

clone_runtime_directories() {
  printf '%s\n' uploads plugins languages
}

is_remote_env() {
  uonix_transport_is_remote "$1"
}

wp_path() {
  uonix_environment_field "$1" document_root
}

env_url() {
  uonix_environment_field "$1" url
}

env_title() {
  uonix_environment_field "$1" title
}

wp_cli_shell() {
  local env="$1"
  local php_bin
  local wp_bin
  php_bin="$(uonix_environment_field "$env" php_bin)" || return $?
  wp_bin="$(uonix_environment_field "$env" wp_bin)" || return $?

  if [ "$php_bin" = php ] && [ "$wp_bin" = wp ]; then
    printf 'wp'
  else
    printf '%q -d disable_functions= %q' "$php_bin" "$wp_bin"
  fi
}

wp_exec() {
  local env="$1"
  shift

  if is_remote_env "$env"; then
    local path
    local wp_cli
    path="$(wp_path "$env")" || return $?
    wp_cli="$(wp_cli_shell "$env")" || return $?
    remote_run "$env" "set -euo pipefail; $wp_cli --path=$(printf '%q' "$path") $(shell_join "$@")"
  else
    local_wp "$@"
  fi
}

content_path() {
  local env="$1"
  local wp_root

  if is_remote_env "$env"; then
    wp_root="$(wp_path "$env")" || return $?
    printf '%s/wp-content\n' "$wp_root"
  else
    printf '%s\n' "$LOCAL_WP_CONTENT"
  fi
}

validate_backup_root() {
  local root="$1"

  case "$root" in
    ''|/|//*|.|..|*/../*|*/..|../*|*/./*|*/.|*$'\n'*|*$'\r'*)
      die 'Raiz de backup ausente ou insegura'
      return 1
      ;;
    /*) return 0 ;;
    *)
      die 'Raiz de backup deve ser absoluta'
      return 1
      ;;
  esac
}

resolve_backup_root() {
  local env="$1"
  local root

  if is_remote_env "$env"; then
    root="$(uonix_environment_field "$env" backup_root)" || return $?
  else
    root="${LOCAL_BACKUP_ROOT}/${env}"
  fi
  validate_backup_root "$root" || return $?
  printf '%s\n' "$root"
}

validate_backup_destination() {
  local root="$1"
  local destination="$2"

  validate_backup_root "$root" || return $?
  case "$destination" in
    "$root"/?*) ;;
    *)
      die 'Diretório de backup está fora da raiz permitida'
      return 1
      ;;
  esac
  case "$destination" in
    */../*|*/..|*/./*|*/.|*$'\n'*|*$'\r'*)
      die 'Diretório de backup inseguro'
      return 1
      ;;
  esac
}

backup_dir() {
  local env="$1"
  local root

  root="$(resolve_backup_root "$env")" || return $?
  printf '%s/%s\n' "$root" "$STAMP"
}

remote_wp_content_dir() {
  local wp_root

  wp_root="$(wp_path "$1")" || return $?
  printf '%s/wp-content\n' "$wp_root"
}

remote_db_dump_snippet() {
  local wp_cli="$1"
  local wp_root="$2"
  local target_gz="$3"

  # `wp db export` shella out via Process::run e a Locaweb desabilita proc_open
  # de forma COMPILADA — `-d disable_functions=` não vence isso. Ou seja: qualquer
  # clone com produção na origem OU no destino ficava sem backup utilizável,
  # justamente o artefato de que o rollback depende.
  #
  # O padrão aqui é o mesmo que snapshot_options já usa: ler credenciais por
  # `wp config get` e chamar o cliente direto, com MYSQL_PWD para manter a senha
  # fora de argv. Preferimos mysqldump/mariadb-dump e só caímos em `wp db export`
  # onde o cliente não existe — capacidade do host, não nome do ambiente.
  cat <<SNIPPET
db_name="\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_NAME)"
db_user="\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_USER)"
db_host="\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_HOST)"
dump_bin="\$(command -v mysqldump || command -v mariadb-dump || true)"
if [ -n "\$dump_bin" ]; then
  dump_flags='--single-transaction --quick --no-tablespaces --routines --triggers --events --default-character-set=utf8mb4'
  # Cliente 8.0 contra servidor 5.7 avisa sobre column statistics em todo dump:
  # inofensivo, mas polui o log e parece falha. A flag só existe no cliente 8.0+.
  if "\$dump_bin" --help 2>/dev/null | grep -q -- '--column-statistics'; then
    dump_flags="\$dump_flags --column-statistics=0"
  fi
  MYSQL_PWD="\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_PASSWORD)" \\
    "\$dump_bin" \$dump_flags \\
    --host="\$db_host" --user="\$db_user" "\$db_name" | gzip -c > $(printf '%q' "$target_gz")
else
  $wp_cli --path=$(printf '%q' "$wp_root") db export - | gzip -c > $(printf '%q' "$target_gz")
fi
SNIPPET
}

remote_db_dump_to_stdout() {
  local wp_cli="$1"
  local wp_root="$2"

  # Variante que emite o dump comprimido em STDOUT, para o caso em que o
  # artefato é transmitido ao runner em vez de gravado no host remoto.
  # Mesma razão do helper acima: `wp db export` shella out e a Locaweb bloqueia
  # proc_open de forma compilada, então o clone com produção na origem falhava.
  printf '%s' "db_name=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_NAME)\"; \
db_user=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_USER)\"; \
db_host=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_HOST)\"; \
dump_bin=\"\$(command -v mysqldump || command -v mariadb-dump || true)\"; \
if [ -n \"\$dump_bin\" ]; then \
dump_flags='--single-transaction --quick --no-tablespaces --routines --triggers --events --default-character-set=utf8mb4'; \
if \"\$dump_bin\" --help 2>/dev/null | grep -q -- '--column-statistics'; then dump_flags=\"\$dump_flags --column-statistics=0\"; fi; \
MYSQL_PWD=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_PASSWORD)\" \"\$dump_bin\" \$dump_flags --host=\"\$db_host\" --user=\"\$db_user\" \"\$db_name\" | gzip -c; \
else $wp_cli --path=$(printf '%q' "$wp_root") db export - | gzip -c; fi"
}

remote_db_client_snippet() {
  local wp_cli="$1"
  local wp_root="$2"

  # Resolve o cliente `mysql` e as credenciais numa forma reutilizável.
  # `wp db import` também shella out, então importar precisa do mesmo tratamento
  # que exportar: em produção o wp-cli não consegue invocar o cliente.
  cat <<SNIPPET
db_name="\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_NAME)"
db_user="\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_USER)"
db_host="\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_HOST)"
mysql_bin="\$(command -v mysql || command -v mariadb || true)"
dump_bin="\$(command -v mysqldump || command -v mariadb-dump || true)"
SNIPPET
}

remote_db_import_file_snippet() {
  local wp_cli="$1"
  local wp_root="$2"
  local sql_file_expression="$3"

  cat <<SNIPPET
$(remote_db_client_snippet "$wp_cli" "$wp_root")
if [ -n "\$mysql_bin" ]; then
  MYSQL_PWD="\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_PASSWORD)" \\
    "\$mysql_bin" --host="\$db_host" --user="\$db_user" \\
    --default-character-set=utf8mb4 "\$db_name" < ${sql_file_expression}
else
  $wp_cli --path=$(printf '%q' "$wp_root") db import ${sql_file_expression} >/dev/null
fi
SNIPPET
}

remote_db_dump_tables_snippet() {
  local wp_cli="$1"
  local wp_root="$2"
  local tables_expression="$3"
  local sql_file_expression="$4"
  local tables_csv_expression="$5"

  # Dump de tabelas específicas (snapshot de usuários do destino). Mesmo motivo
  # dos outros helpers: `wp db export --tables` shella out e falha na Locaweb.
  #
  # As tabelas vêm em duas formas porque os dois caminhos exigem sintaxes
  # diferentes: mysqldump recebe argumentos separados, `wp db export --tables`
  # recebe uma lista separada por vírgula. Passar a forma errada ao fallback
  # descartaria silenciosamente todas as tabelas além da primeira.
  cat <<SNIPPET
$(remote_db_client_snippet "$wp_cli" "$wp_root")
if [ -n "\$dump_bin" ]; then
  dump_flags='--single-transaction --quick --no-tablespaces --default-character-set=utf8mb4'
  if "\$dump_bin" --help 2>/dev/null | grep -q -- '--column-statistics'; then
    dump_flags="\$dump_flags --column-statistics=0"
  fi
  MYSQL_PWD="\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_PASSWORD)" \\
    "\$dump_bin" \$dump_flags \\
    --host="\$db_host" --user="\$db_user" "\$db_name" ${tables_expression} > ${sql_file_expression}
else
  $wp_cli --path=$(printf '%q' "$wp_root") db export ${sql_file_expression} --tables=${tables_csv_expression} >/dev/null
fi
SNIPPET
}

remote_db_import_stdin_command() {
  local wp_cli="$1"
  local wp_root="$2"

  # Comando de import que consome SQL do STDIN, usado quando o dump é
  # transmitido do runner. `wp db import -` shella out e falha na Locaweb.
  printf '%s' "mysql_bin=\"\$(command -v mysql || command -v mariadb || true)\"; \
if [ -n \"\$mysql_bin\" ]; then \
MYSQL_PWD=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_PASSWORD)\" \"\$mysql_bin\" \
--host=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_HOST)\" \
--user=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_USER)\" \
--default-character-set=utf8mb4 \"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_NAME)\"; \
else $wp_cli --path=$(printf '%q' "$wp_root") db import -; fi"
}

prepare_target_backup() {
  local env="$1"
  local dir="$2"
  local wp_root
  local wp_content
  local wp_cli
  local backup_root
  local backup_items=(uploads plugins languages compressx compressx-nextgen .htaccess)

  if [ "$INCLUDE_GIT_FILES" = "1" ]; then
    backup_items+=(themes/kadence-child mu-plugins)
  fi

  log "Criando backup do destino: ${env}"

  if is_remote_env "$env"; then
    wp_root="$(wp_path "$env")" || return $?
    wp_content="${wp_root}/wp-content"
    wp_cli="$(wp_cli_shell "$env")" || return $?
    backup_root="$(resolve_backup_root "$env")" || return $?
    validate_backup_destination "$backup_root" "$dir" || return $?

    remote_run "$env" "set -euo pipefail
umask 077
mkdir -p $(printf '%q' "$dir")
chmod 700 $(printf '%q' "$dir")
$(remote_db_dump_snippet "$wp_cli" "$wp_root" "${dir}/db-${env}-${STAMP}.sql.gz")
cd $(printf '%q' "$wp_content")
set --
for item in $(shell_join "${backup_items[@]}"); do
  if [ -e \"\$item\" ]; then set -- \"\$@\" \"\$item\"; fi
done
if [ \"\$#\" -gt 0 ]; then
  tar -czf $(printf '%q' "${dir}/files-${env}-${STAMP}.tar.gz") \
    --exclude='cache' --exclude='speedycache' --exclude='wc-logs' --exclude='wp-staging' -- \"\$@\"
else
  tar -czf $(printf '%q' "${dir}/files-${env}-${STAMP}.tar.gz") --files-from=/dev/null
fi
test -s $(printf '%q' "${dir}/db-${env}-${STAMP}.sql.gz") || exit \$?
test -s $(printf '%q' "${dir}/files-${env}-${STAMP}.tar.gz") || exit \$?
gzip -t $(printf '%q' "${dir}/db-${env}-${STAMP}.sql.gz") || exit \$?
tar -tzf $(printf '%q' "${dir}/files-${env}-${STAMP}.tar.gz") >/dev/null || exit \$?
chmod 600 $(printf '%q' "${dir}/db-${env}-${STAMP}.sql.gz") $(printf '%q' "${dir}/files-${env}-${STAMP}.tar.gz")
find $(printf '%q' "$backup_root") -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' 2>/dev/null | sort -rn | tail -n +6 | cut -d' ' -f2- | while IFS= read -r old_backup; do [ -n \"\$old_backup\" ] && rm -rf -- \"\$old_backup\"; done"
  else
    local existing_items=()
    local item
    backup_root="$(resolve_backup_root "$env")" || return $?
    validate_backup_destination "$backup_root" "$dir" || return $?
    umask 077
    mkdir -p "$dir" || return $?
    chmod 700 "$dir" || return $?
    local_db_dump | gzip -c >"${dir}/db-${env}-${STAMP}.sql.gz" || return $?

    for item in "${backup_items[@]}"; do
      [ -e "${LOCAL_WP_CONTENT}/${item}" ] && existing_items+=("$item")
    done
    if [ "${#existing_items[@]}" -gt 0 ]; then
      tar -czf "${dir}/files-${env}-${STAMP}.tar.gz" \
        -C "$LOCAL_WP_CONTENT" \
        --exclude='cache' --exclude='speedycache' --exclude='wc-logs' --exclude='wp-staging' \
        -- "${existing_items[@]}" || return $?
    else
      tar -czf "${dir}/files-${env}-${STAMP}.tar.gz" --files-from=/dev/null || return $?
    fi

    [ -s "${dir}/db-${env}-${STAMP}.sql.gz" ] || return 1
    [ -s "${dir}/files-${env}-${STAMP}.tar.gz" ] || return 1
    gzip -t "${dir}/db-${env}-${STAMP}.sql.gz" || return $?
    tar -tzf "${dir}/files-${env}-${STAMP}.tar.gz" >/dev/null || return $?
    chmod 600 "${dir}/db-${env}-${STAMP}.sql.gz" "${dir}/files-${env}-${STAMP}.tar.gz" || return $?

    while IFS= read -r old_backup; do
      [ -n "$old_backup" ] && rm -rf "$old_backup"
    done < <(
      find "${LOCAL_BACKUP_ROOT}/${env}" -mindepth 1 -maxdepth 1 -type d -print0 2>/dev/null \
        | xargs -0 stat -f '%m %N' 2>/dev/null \
        | sort -rn \
        | tail -n +6 \
        | cut -d' ' -f2- || true
    )
  fi
}

snapshot_users() {
  local env="$1"
  local dir="$2"

  [ "$PRESERVE_DESTINATION_USERS" = "1" ] || return 0

  log "Preservando usuários do destino: ${env}"

  if is_remote_env "$env"; then
    local wp_root wp_cli
    wp_root="$(wp_path "$env")" || return $?
    wp_cli="$(wp_cli_shell "$env")" || return $?

    # Montadas fora do heredoc de propósito: estas referências pertencem ao shell
    # REMOTO, e mantê-las em variáveis evita que o ShellCheck as leia como
    # expansões locais perdidas (SC2016) num trecho onde a diretiva de disable
    # cairia dentro da string enviada ao host.
    local remote_users_tables remote_users_tables_csv remote_users_sql
    # As aspas simples são deliberadas: prefix e users_sql são resolvidos pelo
    # shell REMOTO, não aqui.
    # shellcheck disable=SC2016
    remote_users_tables='"${prefix}users" "${prefix}usermeta"'
    # shellcheck disable=SC2016
    remote_users_tables_csv='"${prefix}users,${prefix}usermeta"'
    # shellcheck disable=SC2016
    remote_users_sql='"$users_sql"'

    remote_run "$env" "set -uo pipefail
umask 077
users_dir=$(printf '%q' "$dir")
users_sql=$(printf '%q' "${dir}/users.sql")
users_sha256=$(printf '%q' "${dir}/users.sha256")
mkdir -p \"\$users_dir\" || exit \$?
chmod 700 \"\$users_dir\" || exit \$?
: > \"\$users_sql\" || exit \$?
: > \"\$users_sha256\" || exit \$?
chmod 600 \"\$users_sql\" \"\$users_sha256\" || exit \$?
prefix=\"\$($wp_cli --path=$(printf '%q' "$wp_root") db prefix)\" || exit \$?
$(remote_db_dump_tables_snippet "$wp_cli" "$wp_root" "$remote_users_tables" "$remote_users_sql" "$remote_users_tables_csv") || exit \$?
test -s \"\$users_sql\" || exit \$?
(
  cd \"\$users_dir\" || exit \$?
  sha256sum users.sql > users.sha256
) || exit \$?
chmod 600 \"\$users_sql\" \"\$users_sha256\" || exit \$?
test \"\$(stat -c '%a' \"\$users_dir\")\" = 700 || exit 1
test \"\$(stat -c '%a' \"\$users_sql\")\" = 600 || exit 1
test \"\$(stat -c '%a' \"\$users_sha256\")\" = 600 || exit 1
(
  cd \"\$users_dir\" || exit \$?
  set -o pipefail
  sha256sum users.sql | cmp -s - users.sha256
) || exit \$?" || return $?
  else
    local prefix
    umask 077
    mkdir -p "$dir" || return $?
    chmod 700 "$dir" || return $?
    : >"${dir}/users.sql" || return $?
    : >"${dir}/users.sha256" || return $?
    chmod 600 "${dir}/users.sql" "${dir}/users.sha256" || return $?
    prefix="$(local_db_prefix)" || return $?
    local_db_dump "${prefix}users" "${prefix}usermeta" >"${dir}/users.sql" || return $?
    [ -s "${dir}/users.sql" ] || return 1
    (
      cd "$dir" || exit $?
      shasum -a 256 users.sql > users.sha256
    ) || return $?
    chmod 600 "${dir}/users.sql" "${dir}/users.sha256" || return $?
    validate_users_snapshot "$dir" || return $?
  fi
  return 0
}

snapshot_options() {
  local env="$1"
  local dir="$2"
  local where
  local query

  log "Preservando opções sensíveis do destino: ${env}"
  where="$(protected_options_where)" || return $?

  if is_remote_env "$env"; then
    local wp_root prefix wp_cli
    wp_root="$(wp_path "$env")" || return $?
    wp_cli="$(wp_cli_shell "$env")" || return $?
    prefix="$(wp_exec "$env" db prefix)" || return $?
    query="$(option_upsert_select_sql "${prefix}options" "$where")" || return $?

    remote_run "$env" "set -uo pipefail
umask 077
mkdir -p $(printf '%q' "$dir") || exit \$?
chmod 700 $(printf '%q' "$dir") || exit \$?
options_sql=$(printf '%q' "${dir}/options.sql")
options_sha256=$(printf '%q' "${dir}/options.sha256")
: > \"\$options_sql\" || exit \$?
: > \"\$options_sha256\" || exit \$?
chmod 600 \"\$options_sql\" \"\$options_sha256\" || exit \$?
db_name=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_NAME)\" || exit \$?
db_user=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_USER)\" || exit \$?
db_pass=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_PASSWORD)\" || exit \$?
db_host=\"\$($wp_cli --path=$(printf '%q' "$wp_root") config get DB_HOST)\" || exit \$?
mysql_cmd=\"\$(command -v mysql || command -v mariadb)\" || exit \$?
MYSQL_PWD=\"\$db_pass\" \"\$mysql_cmd\" \
  --host=\"\$db_host\" \
  --user=\"\$db_user\" \
  --batch \
  --raw \
  --skip-column-names \
  \"\$db_name\" \
  -e $(printf '%q' "$query") > \"\$options_sql\" || exit \$?
(
  cd $(printf '%q' "$dir") || exit \$?
  sha256sum options.sql > options.sha256
) || exit \$?
chmod 600 \"\$options_sql\" \"\$options_sha256\" || exit \$?" || return $?
  else
    local prefix
    mkdir -p "$dir" || return $?
    chmod 700 "$dir" || return $?
    : >"${dir}/options.sql" || return $?
    : >"${dir}/options.sha256" || return $?
    chmod 600 "${dir}/options.sql" "${dir}/options.sha256" || return $?
    prefix="$(local_db_prefix)" || return $?
    local_db_dump_options "${prefix}options" "$where" >"${dir}/options.sql" || return $?
    (
      cd "$dir" || exit $?
      shasum -a 256 options.sql > options.sha256
    ) || return $?
    chmod 600 "${dir}/options.sql" "${dir}/options.sha256" || return $?
  fi
  return 0
}

export_source_db() {
  local env="$1"
  local dump_file="$2"

  log "Exportando banco de origem: ${env}"

  if is_remote_env "$env"; then
    local wp_root wp_cli
    wp_root="$(wp_path "$env")" || return $?
    wp_cli="$(wp_cli_shell "$env")" || return $?
    remote_stream_to_file \
      "$env" \
      "$(remote_db_dump_to_stdout "$wp_cli" "$wp_root")" \
      "$dump_file" || return $?
  else
    local_db_dump | gzip -c >"$dump_file" || return $?
  fi
  [ -s "$dump_file" ] || return 1
  gzip -t "$dump_file" || return $?
  export_source_author_map "$env" "${dump_file}.authors.tsv" || return $?
}

import_db_to_target() {
  local env="$1"
  local dump_file="$2"

  log "Importando banco no destino: ${env}"

  if is_remote_env "$env"; then
    local wp_root wp_cli
    wp_root="$(wp_path "$env")" || return $?
    wp_cli="$(wp_cli_shell "$env")" || return $?
    remote_import_gzip_dump \
      "$env" \
      "$dump_file" \
      "$(remote_db_import_stdin_command "$wp_cli" "$wp_root")"
  else
    gzip -dc "$dump_file" | local_db_import
  fi
}

validate_users_snapshot() {
  local dir="$1"
  local users_sql="${dir}/users.sql"
  local users_sha256="${dir}/users.sha256"
  local mode

  [ -d "$dir" ] || return 1
  [ -s "$users_sql" ] || return 1
  [ -s "$users_sha256" ] || return 1
  mode="$(uonix_transport_file_mode "$dir")" || return $?
  [ "$mode" = 700 ] || return 1
  mode="$(uonix_transport_file_mode "$users_sql")" || return $?
  [ "$mode" = 600 ] || return 1
  mode="$(uonix_transport_file_mode "$users_sha256")" || return $?
  [ "$mode" = 600 ] || return 1
  (
    cd "$dir" || exit $?
    set -o pipefail
    shasum -a 256 users.sql | cmp -s - users.sha256
  ) || return $?
}

restore_users() {
  local env="$1"
  local dir="$2"

  [ "$PRESERVE_DESTINATION_USERS" = "1" ] || return 0

  log "Restaurando usuários do destino: ${env}"

  if is_remote_env "$env"; then
    local wp_root wp_cli
    wp_root="$(wp_path "$env")" || return $?
    wp_cli="$(wp_cli_shell "$env")" || return $?

    # Referência ao arquivo no shell REMOTO, montada fora do heredoc para não
    # ser lida como expansão local perdida.
    # shellcheck disable=SC2016
    local remote_users_sql_ref='"$users_sql"'

    remote_run "$env" "set -uo pipefail
users_dir=$(printf '%q' "$dir")
users_sql=$(printf '%q' "${dir}/users.sql")
users_sha256=$(printf '%q' "${dir}/users.sha256")
[ -d \"\$users_dir\" ] || exit 1
[ -s \"\$users_sql\" ] || exit 1
[ -s \"\$users_sha256\" ] || exit 1
[ \"\$(stat -c '%a' \"\$users_dir\")\" = 700 ] || exit 1
[ \"\$(stat -c '%a' \"\$users_sql\")\" = 600 ] || exit 1
[ \"\$(stat -c '%a' \"\$users_sha256\")\" = 600 ] || exit 1
(
  cd \"\$users_dir\" || exit \$?
  set -o pipefail
  sha256sum users.sql | cmp -s - users.sha256
) || exit \$?
$(remote_db_import_file_snippet "$wp_cli" "$wp_root" "$remote_users_sql_ref") || exit \$?" || return $?
  else
    validate_users_snapshot "$dir" || return $?
    local_db_import <"${dir}/users.sql" || return $?
  fi
  return 0
}

validate_options_snapshot() {
  local dir="$1"
  local options_sql="${dir}/options.sql"
  local options_sha256="${dir}/options.sha256"

  [ -d "$dir" ] || return 1
  [ -f "$options_sql" ] || return 1
  [ -f "$options_sha256" ] || return 1
  (
    cd "$dir" || exit $?
    set -o pipefail
    shasum -a 256 options.sql | cmp -s - options.sha256
  ) || return $?
}

restore_options() {
  local env="$1"
  local dir="$2"
  local where

  log "Restaurando opções sensíveis do destino: ${env}"
  where="$(protected_options_where)" || return $?

  if is_remote_env "$env"; then
    local wp_root wp_cli
    wp_root="$(wp_path "$env")" || return $?
    wp_cli="$(wp_cli_shell "$env")" || return $?

    # Referência ao arquivo no shell REMOTO, montada fora do heredoc.
    # shellcheck disable=SC2016
    local remote_options_sql_ref='"$options_sql"'

    remote_run "$env" "set -euo pipefail
options_sql=$(printf '%q' "${dir}/options.sql")
options_sha256=$(printf '%q' "${dir}/options.sha256")
[ -f \"\$options_sql\" ] || exit \$?
[ -f \"\$options_sha256\" ] || exit \$?
(
  cd $(printf '%q' "$dir") || exit \$?
  set -o pipefail
  sha256sum options.sql | cmp -s - options.sha256
) || exit \$?
prefix=\"\$($wp_cli --path=$(printf '%q' "$wp_root") db prefix)\" || exit \$?
delete_sql=\"DELETE FROM \${prefix}options WHERE ${where}\"
$wp_cli --path=$(printf '%q' "$wp_root") db query \"\$delete_sql\" >/dev/null || exit \$?
if [ -s \"\$options_sql\" ]; then
  $(remote_db_import_file_snippet "$wp_cli" "$wp_root" "$remote_options_sql_ref") || exit \$?
fi" || return $?
  else
    local prefix
    validate_options_snapshot "$dir" || return $?
    prefix="$(local_db_prefix)" || return $?
    local_db_query "DELETE FROM ${prefix}options WHERE ${where}" >/dev/null || return $?
    if [ -s "${dir}/options.sql" ]; then
      local_db_import <"${dir}/options.sql" || return $?
    fi
  fi
  return 0
}

valid_author_login_hex() {
  local value="$1"

  [ -z "$value" ] && return 0
  case "$value" in
    *[!0-9A-F]*) return 1 ;;
  esac
  [ $(( ${#value} % 2 )) -eq 0 ]
}

validate_source_author_map() {
  local map_file="$1"
  local mode
  local source_id
  local login_hex
  local extra
  local seen=','

  [ -f "$map_file" ] || return 1
  mode="$(uonix_transport_file_mode "$map_file")" || return $?
  [ "$mode" = 600 ] || return 1

  while IFS=$'\t' read -r source_id login_hex extra || [ -n "$source_id$login_hex$extra" ]; do
    valid_author_id "$source_id" || return 1
    valid_author_login_hex "$login_hex" || return 1
    [ -z "$extra" ] || return 1
    case "$seen" in
      *",${source_id},"*) return 1 ;;
    esac
    seen="${seen}${source_id},"
  done < "$map_file"
}

export_source_author_map() {
  local env="$1"
  local map_file="$2"
  local prefix
  local query
  local status

  [ "$PRESERVE_DESTINATION_USERS" = "1" ] || return 0
  if is_remote_env "$env"; then
    prefix="$(wp_exec "$env" db prefix)" || return $?
  else
    prefix="$(local_db_prefix)" || return $?
  fi
  query="SELECT DISTINCT p.post_author, COALESCE(HEX(u.user_login), '') FROM ${prefix}posts p LEFT JOIN ${prefix}users u ON u.ID = p.post_author ORDER BY p.post_author"

  umask 077
  mkdir -p "$(dirname "$map_file")" || return $?
  : > "$map_file" || return $?
  chmod 600 "$map_file" || return $?
  if wp_exec "$env" db query "$query" --skip-column-names --batch --raw > "$map_file"; then
    :
  else
    status=$?
    rm -f -- "$map_file"
    return "$status"
  fi
  chmod 600 "$map_file" || return $?
  validate_source_author_map "$map_file"
}

set_target_identity() {
  local env="$1"
  local source_url="$2"
  local target_url
  local target_title

  target_url="$(env_url "$env")" || return $?
  target_title="$(env_title "$env")" || return $?

  log "Ajustando URL e identidade do destino: ${env}"

  wp_exec "$env" search-replace "$source_url" "$target_url" --all-tables-with-prefix --skip-columns=guid --quiet || return $?
  wp_exec "$env" option update home "$target_url" >/dev/null || return $?
  wp_exec "$env" option update siteurl "$target_url" >/dev/null || return $?
  wp_exec "$env" option update blogname "$target_title" >/dev/null || return $?
}

valid_author_id() {
  case "$1" in
    ''|*[!0-9]*) return 1 ;;
    *) return 0 ;;
  esac
}

valid_author_id_list() {
  case "$1" in
    ''|,*|*,|*,,*|*[!0-9,]*) return 1 ;;
    *) return 0 ;;
  esac
}

remap_missing_authors() {
  local env="$1"
  local map_file="$2"
  local prefix
  local fallback_id
  local source_id
  local login_hex
  local extra
  local destination_id
  local lookup_query
  local update_query
  local case_sql=''
  local source_ids=''
  local status

  [ "$PRESERVE_DESTINATION_USERS" = "1" ] || return 0
  validate_source_author_map "$map_file" || return $?
  [ -s "$map_file" ] || return 0

  log "Remapeando autores ausentes no destino: ${env}"

  if is_remote_env "$env"; then
    prefix="$(wp_exec "$env" db prefix)" || return $?
  else
    prefix="$(local_db_prefix)" || return $?
  fi

  if fallback_id="$(wp_exec "$env" user list --role=administrator --field=ID | head -n 1)"; then
    :
  else
    status=$?
    return "$status"
  fi
  if [ -z "$fallback_id" ]; then
    if fallback_id="$(wp_exec "$env" user list --field=ID | head -n 1)"; then
      :
    else
      status=$?
      return "$status"
    fi
  fi
  valid_author_id "$fallback_id" || return 1

  while IFS=$'\t' read -r source_id login_hex extra || [ -n "$source_id$login_hex$extra" ]; do
    valid_author_id "$source_id" || return 1
    valid_author_login_hex "$login_hex" || return 1
    [ -z "$extra" ] || return 1

    destination_id=''
    if [ -n "$login_hex" ]; then
      lookup_query="SELECT ID FROM ${prefix}users WHERE HEX(user_login) = '${login_hex}' ORDER BY ID LIMIT 1"
      if destination_id="$(wp_exec "$env" db query "$lookup_query" --skip-column-names --batch --raw)"; then
        :
      else
        status=$?
        return "$status"
      fi
    fi
    [ -n "$destination_id" ] || destination_id="$fallback_id"
    valid_author_id "$destination_id" || return 1
    case "$destination_id" in *$'\n'*) return 1 ;; esac

    case_sql="${case_sql} WHEN ${source_id} THEN ${destination_id}"
    if [ -n "$source_ids" ]; then
      source_ids="${source_ids},${source_id}"
    else
      source_ids="$source_id"
    fi
  done < "$map_file"

  [ -n "$source_ids" ] || return 0
  update_query="UPDATE ${prefix}posts SET post_author = CASE post_author${case_sql} ELSE ${fallback_id} END WHERE post_author IN (${source_ids})"
  if is_remote_env "$env"; then
    wp_exec "$env" db query "$update_query" >/dev/null || return $?
  else
    local_db_query "$update_query" >/dev/null || return $?
  fi
}

wp_plugin_predicate_state() {
  local env="$1"
  local predicate="$2"
  local plugin="$3"
  local query_output
  local status

  case "$predicate" in
    is-installed)
      if query_output="$(wp_exec "$env" plugin list \
        "--name=${plugin}" --format=count --skip-update-check)"; then
        :
      else
        status=$?
        die "Falha operacional ao consultar instalação de ${plugin} em ${env} (exit ${status})" || :
        return "$status"
      fi
      case "$query_output" in
        0) printf 'false\n' ;;
        1) printf 'true\n' ;;
        '')
          die "Saída vazia ao consultar instalação de ${plugin} em ${env}" || :
          return 1
          ;;
        *)
          die "Contagem inesperada ao consultar instalação de ${plugin} em ${env}" || :
          return 1
          ;;
      esac
      return 0
      ;;
    is-active)
      if query_output="$(wp_exec "$env" plugin list \
        "--name=${plugin}" --field=status --format=json --skip-update-check)"; then
        :
      else
        status=$?
        die "Falha operacional ao consultar atividade de ${plugin} em ${env} (exit ${status})" || :
        return "$status"
      fi
      case "$query_output" in
        '["active"]'|'["active-network"]') printf 'true\n' ;;
        '[]'|'["inactive"]') printf 'false\n' ;;
        '')
          die "Saída vazia ao consultar atividade de ${plugin} em ${env}" || :
          return 1
          ;;
        *)
          die "JSON inesperado ao consultar atividade de ${plugin} em ${env}" || :
          return 1
          ;;
      esac
      return 0
      ;;
    *)
      die "Predicado de plugin inválido: ${predicate}"
      return 1
      ;;
  esac
}

enforce_smtp_plugin_policy() {
  local env="$1"
  local active_state
  local installed_state
  local plugin
  local options_table
  local prefix
  local status

  log "Aplicando política SMTP do destino: ${env}"

  if [ "$env" = local ]; then
    installed_state="$(wp_plugin_predicate_state "$env" is-installed "$SUPPORTED_SMTP_PLUGIN")" || return $?
    case "$installed_state" in
      true)
        active_state="$(wp_plugin_predicate_state "$env" is-active "$SUPPORTED_SMTP_PLUGIN")" || return $?
        if [ "$active_state" = true ]; then
          wp_exec "$env" plugin deactivate "$SUPPORTED_SMTP_PLUGIN" >/dev/null 2>&1 || {
            die "Não foi possível desativar ${SUPPORTED_SMTP_PLUGIN} no local"
            return 1
          }
        elif [ "$active_state" != false ]; then
          die "Estado inesperado de ${SUPPORTED_SMTP_PLUGIN} no local: ${active_state}"
          return 1
        fi
        ;;
      false) ;;
      *)
        die "Estado de instalação inesperado de ${SUPPORTED_SMTP_PLUGIN} no local: ${installed_state}"
        return 1
        ;;
    esac
    log "Mailpit é o transporte local; ${SUPPORTED_SMTP_PLUGIN} permanece desativado."
  else
    installed_state="$(wp_plugin_predicate_state "$env" is-installed "$SUPPORTED_SMTP_PLUGIN")" || return $?
    case "$installed_state" in
      true)
        wp_exec "$env" plugin activate "$SUPPORTED_SMTP_PLUGIN" >/dev/null 2>&1 || {
          die "Não foi possível ativar ${SUPPORTED_SMTP_PLUGIN} no destino: ${env}"
          return 1
        }
        ;;
      false)
        die "${SUPPORTED_SMTP_PLUGIN} não está instalado no destino remoto: ${env}"
        return 1
        ;;
      *)
        die "Estado de instalação inesperado de ${SUPPORTED_SMTP_PLUGIN} em ${env}: ${installed_state}"
        return 1
        ;;
    esac
  fi

  for plugin in "${LEGACY_SMTP_PLUGINS[@]}"; do
    installed_state="$(wp_plugin_predicate_state "$env" is-installed "$plugin")" || return $?
    case "$installed_state" in
      true)
        wp_exec "$env" plugin deactivate "$plugin" >/dev/null 2>&1 || true
        if wp_exec "$env" plugin delete "$plugin" >/dev/null 2>&1; then
          :
        else
          status=$?
          die "Não foi possível remover plugin SMTP legado ${plugin} no destino: ${env}" || :
          return "$status"
        fi
        log "Plugin SMTP legado removido do destino: ${plugin}"
        ;;
      false) ;;
      *)
        die "Estado de instalação inesperado de ${plugin} em ${env}: ${installed_state}"
        return 1
        ;;
    esac
  done

  if prefix="$(wp_exec "$env" db prefix | awk 'NF { value = $0 } END { print value }')"; then
    :
  else
    status=$?
    die "Não foi possível obter o prefixo do banco no destino: ${env}" || :
    return "$status"
  fi
  if [ -z "$prefix" ]; then
    die "Prefixo vazio do banco no destino: ${env}" || :
    return 1
  fi
  options_table="$(quote_sql_identifier "${prefix}options")" || return $?
  wp_exec "$env" db query "DELETE FROM ${options_table} WHERE option_name LIKE '%gosmtp%'" >/dev/null || return $?
}

clear_cache() {
  local env="$1"
  local cache_dirs=(
    speedycache
    min
    critical-css
    background-css
    busting
    wp-rocket
  )

  log "Limpando cache do destino: ${env}"

  if is_remote_env "$env"; then
    local wp_root
    local remote_cache_dirs
    local wp_cli
    wp_root="$(wp_path "$env")" || return $?
    remote_cache_dirs="$(shell_join "${cache_dirs[@]}")"
    wp_cli="$(wp_cli_shell "$env")" || return $?

    remote_run "$env" "set -euo pipefail
wp_content=$(printf '%q' "${wp_root}/wp-content")
cache_root=\"\$wp_content/cache\"
if [ -d \"\$cache_root\" ]; then
  for cache_dir in ${remote_cache_dirs}; do
    rm -rf \"\$cache_root/\$cache_dir\"
  done
fi
$wp_cli --path=$(printf '%q' "$wp_root") transient delete --all || true
$wp_cli --path=$(printf '%q' "$wp_root") cache flush || true"
  else
    local cache_dir

    for cache_dir in "${cache_dirs[@]}"; do
      rm -rf "${LOCAL_WP_CONTENT}/cache/${cache_dir}" 2>/dev/null || true
    done

    local_wp transient delete --all || true
    local_wp cache flush || true
  fi
}

validate_compressx_delivery() {
  local env="$1"
  local base_url
  local image_path
  local url
  local headers_file
  local content_type
  local content_length
  local http_status
  local failed="0"

  case "$env" in
    dev|local)
      log "Pulando validação CompressX para ${env}; DEV/local pode não servir AVIF/WebP via HTTPS."
      return 0
      ;;
    prod|qa) ;;
    *)
      die "Ambiente inválido para validar CompressX: ${env}"
      return 1
      ;;
  esac

  command -v curl >/dev/null || die "curl não encontrado para validar CompressX."

  base_url="$(env_url "$env")" || return $?
  log "Validando entrega CompressX no destino: ${env}"

  for image_path in "${COMPRESSX_CRITICAL_IMAGE_PATHS[@]}"; do
    url="${base_url}${image_path}"
    headers_file="$(mktemp)" || return $?

    if ! curl -L -sS --max-time 30 -o /dev/null -D "$headers_file" \
      -H 'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8' \
      "$url"; then
      rm -f "$headers_file"
      printf 'Erro: CompressX não conseguiu validar a imagem: %s\n' "$url" >&2
      failed="1"
      continue
    fi

    http_status="$(
      awk '/^HTTP\// { status = $2 } END { gsub(/\r/, "", status); print status }' "$headers_file"
    )"
    content_type="$(
      awk 'BEGIN { IGNORECASE = 1 } /^content-type:/ { value = $0 } END { gsub(/\r/, "", value); sub(/^[^:]+:[[:space:]]*/, "", value); sub(/;.*/, "", value); print tolower(value) }' "$headers_file"
    )"
    content_length="$(
      awk 'BEGIN { IGNORECASE = 1 } /^content-length:/ { value = $0 } END { gsub(/\r/, "", value); sub(/^[^:]+:[[:space:]]*/, "", value); print value }' "$headers_file"
    )"
    rm -f "$headers_file"

    case "$http_status" in
      200) ;;
      *)
        printf 'Erro: CompressX retornou HTTP %s para %s. Esperado status 200.\n' \
          "${http_status:-?}" "$url" >&2
        failed="1"
        continue
        ;;
    esac

    case "$content_type" in
      image/avif|image/webp)
        log "CompressX OK: ${image_path} -> ${content_type}${content_length:+ (${content_length} bytes)}"
        ;;
      *)
        printf 'Erro: CompressX retornou %s para %s (HTTP %s, content-length %s). Esperado image/avif ou image/webp.\n' \
          "${content_type:-sem content-type}" "$url" "${http_status:-?}" "${content_length:-?}" >&2
        failed="1"
        ;;
    esac
  done

  [ "$failed" = "0" ] || die "Validação CompressX falhou no destino: ${env}"
}

write_directory_manifest() {
  local directory="$1"
  local manifest="$2"

  (
    cd "$directory" || exit $?
    set -o pipefail
    find . -type f -print | LC_ALL=C sort | while IFS= read -r file; do
      shasum -a 256 "$file"
    done > "$manifest" || exit $?
  ) || return $?
}

verify_directory_manifest() {
  local directory="$1"
  local manifest="$2"

  (
    cd "$directory" || exit $?
    shasum -a 256 -c "$manifest" >/dev/null || exit $?
  ) || return $?
}

bridge_upload_payload() {
  local target_env="$1"
  local payload_dir="$2"
  local target_path="$3"
  local relative_dir="$4"
  local rsync_args=("${EXCLUDED_RSYNC_ARGS[@]}")

  if [ "$relative_dir" = plugins ]; then
    rsync_args+=("${PLUGIN_RSYNC_EXCLUDES[@]}")
  fi

  uonix_rsync_from_runner "$target_env" "$payload_dir" "$target_path" "${rsync_args[@]}" || return $?
}

verify_payload_at_target() {
  local target_env="$1"
  local target_path="$2"
  local manifest="$3"
  local remote_manifest
  local status

  if is_remote_env "$target_env"; then
    remote_manifest="${target_path}/.uonix-clone-${STAMP}.sha256"
    uonix_stream_to "$target_env" "$remote_manifest" < "$manifest" || return $?
    if remote_run "$target_env" "cd $(printf '%q' "$target_path") && sha256sum -c $(printf '%q' "$(basename "$remote_manifest")") >/dev/null"; then
      :
    else
      status=$?
      remote_run "$target_env" "rm -f -- $(printf '%q' "$remote_manifest")" || true
      return "$status"
    fi
    remote_run "$target_env" "rm -f -- $(printf '%q' "$remote_manifest")" || return $?
    return 0
  fi

  verify_directory_manifest "$target_path" "$manifest" || return $?
}

bridge_runtime_directory() {
  local source_env="$1"
  local target_env="$2"
  local relative_dir="$3"
  local bridge_root="$4"
  local source_path
  local target_path
  local bridge_dir
  local payload_dir
  local manifest
  local file_count
  local status
  local rsync_args=("${EXCLUDED_RSYNC_ARGS[@]}")

  if [ "$relative_dir" = plugins ]; then
    rsync_args+=("${PLUGIN_RSYNC_EXCLUDES[@]}")
  fi

  source_path="$(content_path "$source_env")/${relative_dir}" || return $?
  target_path="$(content_path "$target_env")/${relative_dir}" || return $?
  bridge_dir="${bridge_root}/${relative_dir}"
  payload_dir="${bridge_dir}/payload"
  manifest="${bridge_dir}/manifest.sha256"

  rm -rf "$bridge_dir" || return $?
  mkdir -p "$payload_dir" || return $?

  if is_remote_env "$source_env"; then
    if remote_run_idempotent "$source_env" "test -d $(printf '%q' "$source_path")"; then
      :
    else
      status=$?
      if [ "$status" -eq 1 ]; then
        log "Diretório ausente na origem; pulando: ${source_path}"
        return 0
      fi
      return "$status"
    fi
  elif [ ! -d "$source_path" ]; then
    log "Diretório ausente na origem; pulando: ${source_path}"
    return 0
  fi

  uonix_rsync_to_runner "$source_env" "$source_path" "$payload_dir" "${rsync_args[@]}" || return $?
  write_directory_manifest "$payload_dir" "$manifest" || return $?
  verify_directory_manifest "$payload_dir" "$manifest" || return $?
  file_count="$(find "$payload_dir" -type f -print | wc -l | tr -d ' ')" || return $?
  CLONE_RUNTIME_DIRECTORY_COUNT=$(( CLONE_RUNTIME_DIRECTORY_COUNT + 1 ))
  CLONE_RUNTIME_FILE_COUNT=$(( CLONE_RUNTIME_FILE_COUNT + file_count ))

  if is_remote_env "$target_env"; then
    remote_run "$target_env" "mkdir -p $(printf '%q' "$target_path")" || return $?
  else
    mkdir -p "$target_path" || return $?
  fi
  bridge_upload_payload "$target_env" "$payload_dir" "$target_path" "$relative_dir" || return $?
  if verify_payload_at_target "$target_env" "$target_path" "$manifest"; then
    return 0
  else
    status=$?
  fi
  die "Checksum divergente após ponte: ${source_env} -> ${target_env} (${relative_dir})" || :
  return "$status"
}

sync_one_dir() {
  local source_env="$1"
  local target_env="$2"
  local relative_dir="$3"
  local bridge_root="${CLONE_TMP_DIR:?CLONE_TMP_DIR não definido}/runtime-bridge"

  bridge_runtime_directory "$source_env" "$target_env" "$relative_dir" "$bridge_root"
}

sync_runtime_files() {
  local source_env="$1"
  local target_env="$2"
  local dirs=(uploads plugins languages)

  if [ "$INCLUDE_GIT_FILES" = "1" ]; then
    dirs+=(themes/kadence-child mu-plugins)
  fi

  for dir in "${dirs[@]}"; do
    log "Sincronizando wp-content/${dir}"
    sync_one_dir "$source_env" "$target_env" "$dir" || return $?
  done
}

preflight_env() {
  local env="$1"
  local wp_root
  local wp_content

  log "Validando ambiente: ${env}"
  resolve_backup_root "$env" >/dev/null || return $?

  if is_remote_env "$env"; then
    local wp_cli
    wp_root="$(wp_path "$env")" || return $?
    wp_content="${wp_root}/wp-content"
    wp_cli="$(wp_cli_shell "$env")" || return $?

    remote_run_idempotent "$env" "set -euo pipefail
command -v rsync >/dev/null || exit \$?
command -v gzip >/dev/null || exit \$?
command -v tar >/dev/null || exit \$?
command -v sha256sum >/dev/null || exit \$?
command -v cmp >/dev/null || exit \$?
(command -v mysql >/dev/null || command -v mariadb >/dev/null) || exit \$?
test -d $(printf '%q' "$wp_root") || exit \$?
test -d $(printf '%q' "$wp_content") || exit \$?
$wp_cli --path=$(printf '%q' "$wp_root") db prefix >/dev/null || exit \$?
$wp_cli --path=$(printf '%q' "$wp_root") option get home >/dev/null || exit \$?
$wp_cli --path=$(printf '%q' "$wp_root") option get siteurl >/dev/null || exit \$?" || return $?
  else
    [ -f "$LOCAL_COMPOSE_FILE" ] || {
      die "Compose local não encontrado: ${LOCAL_COMPOSE_FILE}"
      return 1
    }
    [ -d "$LOCAL_WP_CONTENT" ] || {
      die "wp-content local não encontrado: ${LOCAL_WP_CONTENT}"
      return 1
    }

    command -v shasum >/dev/null || return $?
    command -v cmp >/dev/null || return $?
    local_db_query "SELECT 1" >/dev/null || return $?
    local_db_query "SELECT option_value FROM \`${LOCAL_TABLE_PREFIX}options\` WHERE option_name IN ('home','siteurl')" >/dev/null || return $?
  fi
}

dry_run_clone() {
  local dirs=(uploads plugins languages)
  local source_url
  local target_url

  if [ "$INCLUDE_GIT_FILES" = "1" ]; then
    dirs+=(themes/kadence-child mu-plugins)
  fi

  source_url="$(env_url "$SOURCE")" || return $?
  target_url="$(env_url "$TARGET")" || return $?
  resolve_backup_root "$SOURCE" >/dev/null || return $?
  resolve_backup_root "$TARGET" >/dev/null || return $?
  log "Dry-run solicitado: nenhuma alteração será aplicada."
  log "Origem: ${SOURCE} (${source_url})"
  log "Destino: ${TARGET} (${target_url})"
  log "Arquivos versionados: ${INCLUDE_GIT_FILES}; preservar usuários: ${PRESERVE_DESTINATION_USERS}"

  if [ "$TARGET" = prod ]; then
    log "Destino produção: clone real exigirá --confirmation='$(clone_required_confirmation "$SOURCE" "$TARGET")'."
  fi
  if [ "$SOURCE" = prod ] || [ "$TARGET" = prod ]; then
    log 'Par com produção: a janela SSH Locaweb de três horas deve estar habilitada.'
  fi

  preflight_env "$SOURCE" || return $?
  preflight_env "$TARGET" || return $?

  log "Diretórios que seriam sincronizados em wp-content: ${dirs[*]}"
  log "Opções preservadas no destino: plugins gerenciados, active_plugins, cron, SMTP/captcha/Turnstile/CompressX/admin_email."
  log "Política SMTP pós-clone: ativar fluent-smtp em produção/QA/DEV e manter desativado no local (Mailpit)."
  log "Dry-run concluído sem alterações."
}

clone_parse_arguments() {
  SOURCE=""
  TARGET=""
  CLONE_MODE=""
  REPLACE_USERS="0"
  CONFIRMATION=""
  INCLUDE_GIT_FILES="0"
  PRESERVE_DESTINATION_USERS="1"

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --source=*) SOURCE="${1#*=}" ;;
      --target=*) TARGET="${1#*=}" ;;
      --dry-run)
        [ -z "$CLONE_MODE" ] || { uonix_env_error 'informe somente um modo'; return 1; }
        CLONE_MODE='dry-run'
        ;;
      --execute)
        [ -z "$CLONE_MODE" ] || { uonix_env_error 'informe somente um modo'; return 1; }
        CLONE_MODE='execute'
        ;;
      --replace-users) REPLACE_USERS='1' ;;
      --confirmation=*) CONFIRMATION="${1#*=}" ;;
      -h|--help) CLONE_MODE='help' ;;
      *) uonix_env_error "argumento inválido: $1"; return 1 ;;
    esac
    shift
  done
}

clone_validate_request() {
  local expected_confirmation

  [ "$CLONE_MODE" = dry-run ] || [ "$CLONE_MODE" = execute ] || {
    uonix_env_error 'informe --dry-run ou --execute'
    return 1
  }
  SOURCE="$(canonical_clone_environment "$SOURCE")" || return
  TARGET="$(canonical_clone_environment "$TARGET")" || return
  clone_pair_allowed "$SOURCE" "$TARGET" || {
    uonix_env_error 'origem e destino não podem ser iguais'
    return 1
  }
  case "$REPLACE_USERS" in 0|1) ;; *) return 1 ;; esac

  if [ "$CLONE_MODE" = execute ] && [ "$TARGET" = prod ]; then
    expected_confirmation="$(clone_required_confirmation "$SOURCE" "$TARGET")"
    [ "$CONFIRMATION" = "$expected_confirmation" ] || {
      uonix_env_error "destino produção exige --confirmation='$expected_confirmation'"
      return 1
    }
  fi

  if [ "$REPLACE_USERS" = 1 ]; then
    PRESERVE_DESTINATION_USERS=0
  else
    PRESERVE_DESTINATION_USERS=1
  fi
}

rollback_target() {
  local env="$1"
  local dir="$2"
  local wp_root
  local wp_content
  local wp_cli
  local dump_file="${dir}/db-${env}-${STAMP}.sql.gz"
  local files_file="${dir}/files-${env}-${STAMP}.tar.gz"

  [ -n "$dir" ] || return 1
  log "Executando rollback automático do destino: ${env}"

  if is_remote_env "$env"; then
    wp_root="$(wp_path "$env")" || return $?
    wp_content="${wp_root}/wp-content"
    wp_cli="$(wp_cli_shell "$env")" || return $?
    # Rollback usa o caminho COM retry, ao contrário do resto da mutação: ele
    # restaura a partir de um backup já validado, então repetir converge para o
    # mesmo estado — é idempotente por construção. E uma única tentativa aqui
    # falharia exatamente no cenário que motivou a multiplexação: rede instável.
    # Sem retry, o destino ficaria com banco da origem e arquivos parciais.
    #
    # A extração vai para um diretório temporário e só então substitui os itens,
    # em vez de apagar antes de extrair. Uma queda no meio deixava o destino sem
    # arquivos E sem segunda chance.
    remote_run_idempotent "$env" "set -euo pipefail
test -n $(printf '%q' "$wp_content")
test $(printf '%q' "$wp_content") != /
test -s $(printf '%q' "$dump_file")
test -s $(printf '%q' "$files_file")
gzip -t $(printf '%q' "$dump_file")
tar -tzf $(printf '%q' "$files_file") >/dev/null
staging=\"\$(mktemp -d $(printf '%q' "$wp_content")/.uonix-rollback.XXXXXX)\"
# O trap remove SOMENTE o que ainda nao foi promovido. Um rm -rf cego no staging
# destruiria o item original guardado como .replaced-item: com set -e, um mv
# falho aborta o script, o trap roda e o original vai embora junto — perda de
# dados, nao estado misto. Antes de limpar, devolve o que estiver guardado.
trap 'for replaced in \"\$staging\"/.replaced-*; do
  test -e \"\$replaced\" || continue
  original=\"\${replaced##*/.replaced-}\"
  test -e $(printf '%q' "$wp_content")/\"\$original\" || mv -- \"\$replaced\" $(printf '%q' "$wp_content")/\"\$original\" || true
done
rm -rf -- \"\$staging\"' EXIT
# Extrai antes de tocar em qualquer coisa: com set -e um archive corrompido
# aborta aqui, com o destino intacto e o banco ainda nao mexido. Restaurar o
# banco e falhar nos arquivos deixaria schema novo com arquivos antigos.
tar -xzf $(printf '%q' "$files_file") -C \"\$staging\"
gzip -dc $(printf '%q' "$dump_file") | { $(remote_db_import_stdin_command "$wp_cli" "$wp_root"); }
for item in uploads plugins languages compressx compressx-nextgen .htaccess; do
  if [ -e \"\$staging/\$item\" ]; then
    # Guarda o atual dentro do staging em vez de apagar: se o mv seguinte falhar,
    # o original ainda existe. Apagar antes deixava metade dos itens ausente.
    if [ -e $(printf '%q' "$wp_content")/\"\$item\" ]; then
      mv -- $(printf '%q' "$wp_content")/\"\$item\" \"\$staging/.replaced-\$item\"
    fi
    mv -- \"\$staging/\$item\" $(printf '%q' "$wp_content")/\"\$item\"
  fi
done
$wp_cli --path=$(printf '%q' "$wp_root") cache flush || true"
  else
    [ -n "$LOCAL_WP_CONTENT" ] && [ "$LOCAL_WP_CONTENT" != / ] || return 1
    [ -s "$dump_file" ] || return 1
    [ -s "$files_file" ] || return 1
    gzip -t "$dump_file" || return 1
    tar -tzf "$files_file" >/dev/null || return 1
    # Extrai PRIMEIRO para o staging, sem tocar em nada do destino. Se o archive
    # estiver corrompido, o rollback aborta com o destino ainda intacto e o banco
    # não é mexido — restaurar o banco e falhar nos arquivos deixaria o destino
    # com schema novo e arquivos antigos, um estado que nenhum smoke detecta.
    local rollback_staging rollback_status
    rollback_staging="$(mktemp -d "${LOCAL_WP_CONTENT:?}/.uonix-rollback.XXXXXX")" || return $?
    # Preserva o status ORIGINAL de cada passo em vez de achatar para 1: o
    # relatório de falha do rollback informa esse código, e trocá-lo esconde a
    # causa real de quem for investigar.
    #
    # O status é capturado com `|| rollback_status=$?`, não dentro de
    # `if ! cmd; then rollback_status=$?`: nessa forma o `!` já normalizou $? para
    # 0 e o rollback devolvia sucesso mesmo tendo falhado.
    rollback_status=0
    tar -xzf "$files_file" -C "$rollback_staging" || rollback_status=$?
    if [ "$rollback_status" -ne 0 ]; then
      rm -rf -- "$rollback_staging"
      return "$rollback_status"
    fi

    # Com os arquivos já garantidos em staging, o banco vem antes da troca:
    # restaurar arquivos sobre um schema divergente passa num smoke de arquivos e
    # mente sobre o conteúdo.
    gzip -dc "$dump_file" | local_db_import || rollback_status=$?
    if [ "$rollback_status" -ne 0 ]; then
      rm -rf -- "$rollback_staging"
      return "$rollback_status"
    fi

    for item in uploads plugins languages compressx compressx-nextgen .htaccess; do
      if [ -e "$rollback_staging/$item" ]; then
        # Move o atual para dentro do staging em vez de apagá-lo: se o `mv`
        # seguinte falhar, o item original ainda existe e é devolvido ao lugar.
        # Apagar primeiro deixava o destino com metade dos itens restaurados e a
        # outra metade AUSENTE — pior que não ter tentado, porque o site fica
        # quebrado de um jeito que o backup não explica.
        if [ -e "${LOCAL_WP_CONTENT:?}/${item}" ]; then
          mv -- "${LOCAL_WP_CONTENT:?}/${item}" "$rollback_staging/.replaced-${item}" || rollback_status=$?
          if [ "$rollback_status" -ne 0 ]; then
            rm -rf -- "$rollback_staging"
            return "$rollback_status"
          fi
        fi
        mv -- "$rollback_staging/$item" "${LOCAL_WP_CONTENT:?}/${item}" || rollback_status=$?
        if [ "$rollback_status" -ne 0 ]; then
          # Devolve o original antes de desistir, para não deixar o item ausente.
          if [ -e "$rollback_staging/.replaced-${item}" ]; then
            mv -- "$rollback_staging/.replaced-${item}" "${LOCAL_WP_CONTENT:?}/${item}" || true
          fi
          rm -rf -- "$rollback_staging"
          return "$rollback_status"
        fi
      fi
    done
    rm -rf -- "$rollback_staging"
    local_wp cache flush || true
  fi
}

clone_handle_failure() {
  local status="${1:-1}"
  local rollback_status

  if [ "$MUTATION_STARTED" = 1 ] && [ "$ROLLBACK_RUNNING" = 0 ] && [ -n "$TARGET_BACKUP_DIR" ]; then
    ROLLBACK_RUNNING=1
    if rollback_target "$TARGET" "$TARGET_BACKUP_DIR"; then
      :
    else
      rollback_status=$?
      printf 'Erro: rollback automático também falhou (exit %s); preserve o backup %s.\n' \
        "$rollback_status" "$TARGET_BACKUP_DIR" >&2
    fi
    ROLLBACK_RUNNING=0
  fi
  # Sourced-library callers inspect the primary failure after this handler.
  # shellcheck disable=SC2034
  CLONE_FAILURE_STATUS="$status"
  return 0
}

clone_on_error() {
  local status=$?
  trap - ERR
  clone_handle_failure "$status"
  exit "$status"
}

validate_http_endpoint() {
  local label="$1"
  local url="$2"
  local status
  local curl_status

  if status="$(curl -L -sS -o /dev/null -w '%{http_code}' --max-time 30 "$url")"; then
    :
  else
    curl_status=$?
    die "$label falhou no curl (exit $curl_status)"
    return 1
  fi
  case "$status" in
    2??|3??) return 0 ;;
    *)
      die "$label respondeu HTTP $status"
      return 1
      ;;
  esac
}

validate_local_mailpit() {
  local status

  # The PHP probe must remain literal; shell expansion would corrupt PHP variables.
  # shellcheck disable=SC2016
  if wp_exec local eval '
/* UONIX_LOCAL_MAILPIT_VALIDATION */
if ( "local" !== wp_get_environment_type() ) {
    exit( 1 );
}
if ( ! class_exists( "PHPMailer\\PHPMailer\\PHPMailer" ) ) {
    require_once ABSPATH . WPINC . "/PHPMailer/Exception.php";
    require_once ABSPATH . WPINC . "/PHPMailer/PHPMailer.php";
    require_once ABSPATH . WPINC . "/PHPMailer/SMTP.php";
}
$phpmailer = new \PHPMailer\PHPMailer\PHPMailer( true );
do_action_ref_array( "phpmailer_init", array( &$phpmailer ) );
$mailpit_host = strtolower( rtrim( (string) $phpmailer->Host, "." ) );
if (
    "smtp" !== strtolower( (string) $phpmailer->Mailer )
    || "mailpit" !== $mailpit_host
    || 1025 !== (int) $phpmailer->Port
    || false !== $phpmailer->SMTPAuth
    || "" !== (string) $phpmailer->SMTPSecure
    || false !== $phpmailer->SMTPAutoTLS
) {
    exit( 1 );
}
$mailpit_socket = @fsockopen( $mailpit_host, (int) $phpmailer->Port, $error_code, $error_message, 3 );
if ( ! is_resource( $mailpit_socket ) ) {
    exit( 1 );
}
fclose( $mailpit_socket );
' >/dev/null; then
    return 0
  else
    status=$?
  fi
  die "Mailpit local indisponível ou transporte PHPMailer inválido (exit ${status})" || :
  return "$status"
}

validate_nonprod_email_policy() {
  local env="$1"
  local status

  # The PHP probe must remain literal; shell expansion would corrupt PHP variables.
  # shellcheck disable=SC2016
  if wp_exec "$env" eval '
/* UONIX_NONPROD_EMAIL_POLICY_VALIDATION */
if (
    ! defined( "UONIX_ENV" )
    || ! in_array( UONIX_ENV, array( "staging", "development" ), true )
    || ! defined( "UONIX_NONPROD_EMAIL_TO" )
    || ! function_exists( "uonix_apply_email_environment_policy" )
    || ! function_exists( "uonix_filter_email_environment_policy" )
    || ! function_exists( "uonix_prevent_unsafe_nonprod_email" )
) {
    exit( 1 );
}
if (
    PHP_INT_MAX !== has_filter( "wp_mail", "uonix_filter_email_environment_policy" )
    || PHP_INT_MAX !== has_filter( "pre_wp_mail", "uonix_prevent_unsafe_nonprod_email" )
) {
    exit( 1 );
}
$safe_recipient = trim( (string) UONIX_NONPROD_EMAIL_TO );
if ( ! is_email( $safe_recipient ) ) {
    exit( 1 );
}
$reply_to = "Reply-To: reply@example.invalid";
$fixture  = array(
    "to"      => "origin@example.invalid",
    "subject" => "Synthetic routing probe",
    "message" => "Synthetic body",
    "headers" => array(
        "Cc: copy@example.invalid",
        "Bcc: blind-copy@example.invalid",
        $reply_to,
    ),
);
$filtered = apply_filters( "wp_mail", $fixture );
if ( ! is_array( $filtered ) || ! isset( $filtered["to"] ) || (string) $filtered["to"] !== $safe_recipient ) {
    exit( 1 );
}
$headers = $filtered["headers"] ?? array();
if ( ! is_array( $headers ) ) {
    $headers = preg_split( "/\\r\\n|\\r|\\n/", (string) $headers );
}
$reply_to_preserved = false;
foreach ( $headers as $header ) {
    if ( preg_match( "/^\\s*(?:Cc|Bcc)\\s*:/i", (string) $header ) ) {
        exit( 1 );
    }
    if ( trim( (string) $header ) === $reply_to ) {
        $reply_to_preserved = true;
    }
}
if ( ! $reply_to_preserved ) {
    exit( 1 );
}
' >/dev/null; then
    return 0
  else
    status=$?
  fi
  die "Política efetiva de e-mail QA/DEV ausente ou inválida (exit ${status})" || :
  return "$status"
}

validate_turnstile_policy() {
  local env="$1"
  local status

  case "$env" in
    prod|qa|dev)
      if wp_exec "$env" eval '
/* UONIX_TURNSTILE_POLICY_VALIDATION */
if (
    ! in_array( wp_get_environment_type(), array( "production", "staging", "development" ), true )
    || ! function_exists( "uonix_turnstile_is_enabled" )
    || ! uonix_turnstile_is_enabled()
) {
    exit( 1 );
}
' >/dev/null; then
        return 0
      else
        status=$?
      fi
      ;;
    local)
      if wp_exec "$env" eval '
/* UONIX_TURNSTILE_POLICY_VALIDATION */
if (
    "local" !== wp_get_environment_type()
    || ! function_exists( "uonix_turnstile_is_local" )
    || ! function_exists( "uonix_turnstile_is_enabled" )
    || ! uonix_turnstile_is_local()
    || uonix_turnstile_is_enabled()
) {
    exit( 1 );
}
' >/dev/null; then
        return 0
      else
        status=$?
      fi
      ;;
    *)
      die "Ambiente inválido para validar Turnstile: ${env}"
      return 1
      ;;
  esac

  die "Política Turnstile ausente ou inválida em ${env} (exit ${status})" || :
  return "$status"
}

validate_target_after_clone() {
  local env="$1"
  local active_state
  local plugin
  local expected_url
  local expected_title
  local expected_type
  local actual_home
  local actual_siteurl
  local actual_title
  local actual_type

  expected_url="$(env_url "$env")" || return $?
  expected_title="$(env_title "$env")" || return $?
  expected_type="$(uonix_env_type "$env")" || return $?
  wp_exec "$env" core is-installed >/dev/null || return $?
  actual_home="$(wp_exec "$env" option get home | awk 'NF { value=$0 } END { print value }')" || return $?
  actual_siteurl="$(wp_exec "$env" option get siteurl | awk 'NF { value=$0 } END { print value }')" || return $?
  actual_title="$(wp_exec "$env" option get blogname | awk 'NF { value=$0 } END { print value }')" || return $?
  actual_type="$(wp_exec "$env" eval 'echo wp_get_environment_type();' | awk 'NF { value=$0 } END { print value }')" || return $?
  [ "$actual_home" = "$expected_url" ] || {
    die "home incorreto após clone: $actual_home"
    return 1
  }
  [ "$actual_siteurl" = "$expected_url" ] || {
    die "siteurl incorreto após clone: $actual_siteurl"
    return 1
  }
  [ "$actual_title" = "$expected_title" ] || {
    die "título incorreto após clone: $actual_title"
    return 1
  }
  [ "$actual_type" = "$expected_type" ] || {
    die "WP_ENVIRONMENT_TYPE incorreto após clone: $actual_type"
    return 1
  }

  validate_turnstile_policy "$env" || return $?
  if [ "$env" = qa ] || [ "$env" = dev ]; then
    validate_nonprod_email_policy "$env" || return $?
  fi
  if [ "$env" != prod ]; then
    wp_exec "$env" eval 'if (!defined("UONIX_ANALYTICS_ENABLED") || UONIX_ANALYTICS_ENABLED !== false) { exit(1); }' >/dev/null || {
      die "analytics habilitado ou indefinido fora de produção"
      return 1
    }
  fi
  # The PHP probe must keep $allows_indexing and $blog_public literal for WP-CLI.
  # shellcheck disable=SC2016
  wp_exec "$env" eval 'if (!function_exists("uonix_environment_allows_indexing")) { exit(1); } $allows_indexing = uonix_environment_allows_indexing(); $blog_public = "1" === (string) get_option("blog_public"); if ($allows_indexing !== $blog_public) { exit(1); }' >/dev/null || {
    die "política de indexação inválida após clone"
    return 1
  }
  for plugin in "${CRITICAL_POST_CLONE_PLUGINS[@]}"; do
    if [ "$env" = local ] && [ "$plugin" = "$SUPPORTED_SMTP_PLUGIN" ]; then
      active_state="$(wp_plugin_predicate_state "$env" is-active "$plugin")" || return $?
      case "$active_state" in
        true)
          die "Fluent SMTP ativo no local; o transporte deve permanecer no Mailpit"
          return 1
          ;;
        false) ;;
        *)
          die "Estado inesperado de ${plugin} no local: ${active_state}"
          return 1
          ;;
      esac
      continue
    fi
    wp_exec "$env" plugin is-active "$plugin" >/dev/null || {
      die "plugin crítico inativo após clone: $plugin"
      return 1
    }
  done

  if [ "$env" = local ]; then
    validate_local_mailpit || return $?
  fi

  validate_http_endpoint 'home' "$expected_url" || return 1
  validate_http_endpoint 'wp-login' "${expected_url}/wp-login.php" || return 1
}

write_clone_summary() {
  [ -n "${UONIX_CLONE_SUMMARY_FILE:-}" ] || return 0
  umask 077
  {
    printf 'source=%s\n' "$SOURCE"
    printf 'target=%s\n' "$TARGET"
    printf 'mode=%s\n' "$CLONE_MODE"
    printf 'replace_users=%s\n' "$REPLACE_USERS"
    printf 'backup_id=%s\n' "$(basename "${TARGET_BACKUP_DIR:-none}")"
    printf 'runtime_file_count=%s\n' "$CLONE_RUNTIME_FILE_COUNT"
    printf 'runtime_directory_count=%s\n' "$CLONE_RUNTIME_DIRECTORY_COUNT"
  } > "$UONIX_CLONE_SUMMARY_FILE"
}

execute_clone_mutation() {
  local source_db_dump
  local source_url
  local target_url
  local target_title

  [ -n "$CLONE_TMP_DIR" ] || CLONE_TMP_DIR="$(mktemp -d)"
  TARGET_BACKUP_DIR="$(backup_dir "$TARGET")" || return $?
  source_db_dump="${CLONE_TMP_DIR}/source-${SOURCE}-${STAMP}.sql.gz"
  source_url="$(env_url "$SOURCE")" || return $?
  target_url="$(env_url "$TARGET")" || return $?
  target_title="$(env_title "$TARGET")" || return $?

  log "Clone solicitado: ${SOURCE} -> ${TARGET}"
  log "Substituir usuários: ${REPLACE_USERS}"

  prepare_target_backup "$TARGET" "$TARGET_BACKUP_DIR" || return $?
  snapshot_users "$TARGET" "$TARGET_BACKUP_DIR" || return $?
  snapshot_options "$TARGET" "$TARGET_BACKUP_DIR" || return $?
  export_source_db "$SOURCE" "$source_db_dump" || return $?

  MUTATION_STARTED=1
  import_db_to_target "$TARGET" "$source_db_dump" || return $?
  restore_users "$TARGET" "$TARGET_BACKUP_DIR" || return $?
  set_target_identity "$TARGET" "$source_url" || return $?
  restore_options "$TARGET" "$TARGET_BACKUP_DIR" || return $?
  wp_exec "$TARGET" option update home "$target_url" >/dev/null || return $?
  wp_exec "$TARGET" option update siteurl "$target_url" >/dev/null || return $?
  wp_exec "$TARGET" option update blogname "$target_title" >/dev/null || return $?
  remap_missing_authors "$TARGET" "${source_db_dump}.authors.tsv" || return $?
  sync_runtime_files "$SOURCE" "$TARGET" || return $?
  enforce_smtp_plugin_policy "$TARGET" || return $?
  clear_cache "$TARGET" || return $?
  validate_target_after_clone "$TARGET" || return $?
  validate_compressx_delivery "$TARGET" || return $?
  MUTATION_STARTED=0
  write_clone_summary || return $?
  log "Clone concluído: ${SOURCE} -> ${TARGET}; backup: $(basename "$TARGET_BACKUP_DIR")"
}

execute_clone_with_rollback() {
  local status

  if execute_clone_mutation; then
    return 0
  else
    status=$?
  fi
  clone_handle_failure "$status"
  return "$status"
}

run_clone() {
  local operation_status
  local release_status

  if [ "$CLONE_MODE" = dry-run ]; then
    dry_run_clone || return $?
    write_clone_summary || return $?
    return
  fi

  # Guard estrutural: nenhuma execução real passa sem preflight no mesmo processo.
  dry_run_clone || return $?
  acquire_clone_lock "$TARGET" || return $?
  if execute_clone_with_rollback; then
    operation_status=0
  else
    operation_status=$?
  fi
  if release_clone_lock; then
    release_status=0
  else
    release_status=$?
    printf 'Erro: falha ao liberar lock do clone (exit %s).\n' "$release_status" >&2
  fi
  [ "$operation_status" -ne 0 ] && return "$operation_status"
  return "$release_status"
}

clone_on_signal() {
  local status="$1"
  local release_status
  trap - HUP INT TERM
  clone_handle_failure "$status"
  if release_clone_lock; then
    :
  else
    release_status=$?
    printf 'Erro: falha ao liberar lock após sinal (exit %s).\n' "$release_status" >&2
  fi
  exit "$status"
}

clone_cleanup() {
  local status="$1"
  local release_status=0
  local cleanup_environment

  trap - EXIT
  if [ "$CLONE_LOCK_HELD" = 1 ]; then
    if release_clone_lock; then
      :
    else
      release_status=$?
      printf 'Erro: cleanup não conseguiu liberar lock do clone (exit %s).\n' "$release_status" >&2
    fi
  fi
  rm -rf "${CLONE_TMP_DIR:-}"

  # Fecha os masters SSH desta execução. Na Locaweb (senha) um socket vivo
  # permitiria a qualquer processo do mesmo usuário reusar a sessão autenticada
  # durante os 120s de ControlPersist. É limpeza: nunca altera o status de saída.
  for cleanup_environment in "$SOURCE" "$TARGET"; do
    [ -n "$cleanup_environment" ] || continue
    is_remote_env "$cleanup_environment" || continue
    uonix_transport_close_master "$cleanup_environment" || true
  done

  if [ "$status" -eq 0 ] && [ "$release_status" -ne 0 ]; then
    exit "$release_status"
  fi
  exit "$status"
}

clone_main() {
  clone_parse_arguments "$@" || { usage; return 1; }
  if [ "$CLONE_MODE" = help ]; then
    usage
    return 0
  fi
  clone_validate_request || { usage; return 1; }

  CLONE_TMP_DIR="$(mktemp -d)"
  trap 'clone_cleanup $?' EXIT
  trap 'clone_on_signal 129' HUP
  trap 'clone_on_signal 130' INT
  trap 'clone_on_signal 143' TERM
  run_clone
}

if [ "${UONIX_CLONE_LIBRARY_ONLY:-0}" != 1 ] && [ "${BASH_SOURCE[0]}" = "$0" ]; then
  clone_main "$@"
fi
