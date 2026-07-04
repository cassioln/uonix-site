#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"

SOURCE=""
TARGET=""
INCLUDE_GIT_FILES="0"
PRESERVE_DESTINATION_USERS="1"
YES="0"
DRY_RUN="0"
CONFIRM_PRODUCTION=""

SSH_HOST="${SSH_HOST:-${STAGING_SSH_HOST:-108.179.252.137}}"
SSH_USER="${SSH_USER:-${STAGING_SSH_USER:-uonix}}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/uonix_github_actions_staging_nopass}"
SSH_PORT="${SSH_PORT:-22}"

PROD_PATH="${PROD_PATH:-/home2/uonix/public_html}"
QA_PATH="${QA_PATH:-/home2/uonix/qa_uonix}"
REMOTE_BACKUP_ROOT="${REMOTE_BACKUP_ROOT:-/home2/uonix/_uonix-clone-backups}"
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

usage() {
  cat <<USAGE
Uso:
  scripts/clone-environment.sh --source=prod|qa|local --target=prod|qa|local [opções]

Opções:
  --include-git-files=0|1              Clona tema filho e MU-plugins versionados.
  --preserve-destination-users=0|1     Mantém usuários do destino após importar o banco.
  --dry-run                            Valida conexão, caminhos e ferramentas sem alterar o destino.
  --yes                                Executa de fato a operação.
  --confirm-production=TEXTO           Obrigatório para clonar para produção.

Variáveis úteis:
  SSH_HOST, SSH_USER, SSH_KEY, PROD_PATH, QA_PATH, LOCAL_COMPOSE_FILE.
USAGE
}

log() {
  printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"
}

die() {
  printf 'Erro: %s\n' "$*" >&2
  exit 1
}

shell_join() {
  local quoted=()

  for arg in "$@"; do
    quoted+=("$(printf '%q' "$arg")")
  done

  printf '%s ' "${quoted[@]}"
}

remote_run() {
  local script="$1"
  local attempt
  local output
  local status

  for attempt in 1 2 3 4 5; do
    set +e
    output="$(
      ssh -p "$SSH_PORT" \
        -i "$SSH_KEY" \
        -o BatchMode=yes \
        -o StrictHostKeyChecking=accept-new \
        "${SSH_USER}@${SSH_HOST}" \
        "bash -s" <<<"$script" 2>&1
    )"
    status=$?
    set -e

    if [ "$status" -eq 0 ]; then
      printf '%s\n' "$output"
      return 0
    fi

    if [ "$status" -ne 255 ] || [ "$attempt" -eq 5 ]; then
      printf '%s\n' "$output" >&2
      return "$status"
    fi

    printf 'SSH indisponível temporariamente; nova tentativa %d/5 em %ds.\n' "$(( attempt + 1 ))" "$(( attempt * 10 ))" >&2
    sleep "$(( attempt * 10 ))"
  done
}

remote_stream_to_file() {
  local remote_command="$1"
  local output_file="$2"
  local attempt
  local err_file
  local partial_file
  local status

  partial_file="${output_file}.partial"

  for attempt in 1 2 3 4 5; do
    err_file="$(mktemp)"
    rm -f "$partial_file"

    set +e
    ssh -p "$SSH_PORT" \
      -i "$SSH_KEY" \
      -o BatchMode=yes \
      -o StrictHostKeyChecking=accept-new \
      "${SSH_USER}@${SSH_HOST}" \
      "$remote_command" >"$partial_file" 2>"$err_file"
    status=$?
    set -e

    if [ "$status" -eq 0 ]; then
      cat "$err_file" >&2
      rm -f "$err_file"
      mv "$partial_file" "$output_file"
      return 0
    fi

    if [ "$status" -ne 255 ] || [ "$attempt" -eq 5 ]; then
      cat "$err_file" >&2
      rm -f "$err_file" "$partial_file"
      return "$status"
    fi

    cat "$err_file" >&2
    rm -f "$err_file" "$partial_file"
    printf 'SSH indisponível temporariamente; nova tentativa %d/5 em %ds.\n' "$(( attempt + 1 ))" "$(( attempt * 10 ))" >&2
    sleep "$(( attempt * 10 ))"
  done
}

remote_import_gzip_dump() {
  local dump_file="$1"
  local remote_command="$2"
  local marker="__UONIX_REMOTE_IMPORT_STARTED__"
  local attempt
  local gzip_err_file
  local ssh_err_file
  local gzip_status
  local ssh_status
  local pipe_status
  local status
  local remote_started

  for attempt in 1 2 3 4 5; do
    gzip_err_file="$(mktemp)"
    ssh_err_file="$(mktemp)"

    set +e
    gzip -dc "$dump_file" 2>"$gzip_err_file" | ssh -p "$SSH_PORT" \
      -i "$SSH_KEY" \
      -o BatchMode=yes \
      -o StrictHostKeyChecking=accept-new \
      "${SSH_USER}@${SSH_HOST}" \
      "printf '%s\n' '$marker' >&2; $remote_command" 2>"$ssh_err_file"
    pipe_status=("${PIPESTATUS[@]}")
    gzip_status="${pipe_status[0]}"
    ssh_status="${pipe_status[1]}"
    set -e

    remote_started="0"
    if grep -q "$marker" "$ssh_err_file"; then
      remote_started="1"
    fi

    sed "/$marker/d" "$ssh_err_file" >&2
    cat "$gzip_err_file" >&2

    if [ "$gzip_status" -eq 0 ] && [ "$ssh_status" -eq 0 ]; then
      rm -f "$gzip_err_file" "$ssh_err_file"
      return 0
    fi

    status="$ssh_status"
    if [ "$status" -eq 0 ]; then
      status="$gzip_status"
    fi

    if [ "$ssh_status" -ne 255 ] || [ "$remote_started" = "1" ] || [ "$attempt" -eq 5 ]; then
      rm -f "$gzip_err_file" "$ssh_err_file"
      return "$status"
    fi

    rm -f "$gzip_err_file" "$ssh_err_file"
    printf 'SSH indisponível temporariamente; nova tentativa %d/5 em %ds.\n' "$(( attempt + 1 ))" "$(( attempt * 10 ))" >&2
    sleep "$(( attempt * 10 ))"
  done
}

rsync_with_retry() {
  local attempt
  local output
  local status

  for attempt in 1 2 3 4 5; do
    set +e
    output="$("$@" 2>&1)"
    status=$?
    set -e

    if [ "$status" -eq 0 ]; then
      printf '%s\n' "$output"
      return 0
    fi

    case "$status" in
      10|12|30|35|255) ;;
      *)
        printf '%s\n' "$output" >&2
        return "$status"
        ;;
    esac

    if [ "$attempt" -eq 5 ]; then
      printf '%s\n' "$output" >&2
      return "$status"
    fi

    printf 'Rsync indisponível temporariamente; nova tentativa %d/5 em %ds.\n' "$(( attempt + 1 ))" "$(( attempt * 10 ))" >&2
    sleep "$(( attempt * 10 ))"
  done
}

local_wp() {
  cd "$ROOT_DIR"
  podman-compose -p "$LOCAL_COMPOSE_PROJECT" -f "$LOCAL_COMPOSE_FILE" --profile tools run --rm --no-deps -T wpcli --skip-plugins --skip-themes "$@" </dev/null
}

local_db_dump() {
  podman exec \
    -e MYSQL_PWD="$LOCAL_DB_PASSWORD" \
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
  podman exec -i \
    -e MYSQL_PWD="$LOCAL_DB_PASSWORD" \
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
  podman exec \
    -e MYSQL_PWD="$LOCAL_DB_PASSWORD" \
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

  query="$(option_upsert_select_sql "$options_table" "$where")"

  podman exec \
    -e MYSQL_PWD="$LOCAL_DB_PASSWORD" \
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
  printf '`%s`' "$identifier"
}

option_upsert_select_sql() {
  local options_table="$1"
  local where="$2"
  local quoted_table

  quoted_table="$(quote_sql_identifier "$options_table")"

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

is_remote_env() {
  [[ "$1" == "prod" || "$1" == "qa" ]]
}

wp_path() {
  case "$1" in
    prod) printf '%s\n' "$PROD_PATH" ;;
    qa) printf '%s\n' "$QA_PATH" ;;
    local) printf '%s\n' "/var/www/html" ;;
    *) die "Ambiente inválido: $1" ;;
  esac
}

env_url() {
  case "$1" in
    prod) printf '%s\n' "https://uonix.ksio.dev" ;;
    qa) printf '%s\n' "https://qa.uonix.ksio.dev" ;;
    local) printf '%s\n' "http://localhost:8080" ;;
  esac
}

env_title() {
  case "$1" in
    prod) printf '%s\n' "Uônix" ;;
    qa) printf '%s\n' "QA - UONIX" ;;
    local) printf '%s\n' "DEV - UONIX" ;;
  esac
}

wp_exec() {
  local env="$1"
  shift

  if is_remote_env "$env"; then
    local path
    path="$(wp_path "$env")"
    remote_run "set -euo pipefail; wp --path=$(printf '%q' "$path") $(shell_join "$@")"
  else
    local_wp "$@"
  fi
}

content_path() {
  local env="$1"

  if is_remote_env "$env"; then
    printf '%s/wp-content\n' "$(wp_path "$env")"
  else
    printf '%s\n' "$LOCAL_WP_CONTENT"
  fi
}

backup_dir() {
  local env="$1"

  if is_remote_env "$env"; then
    printf '%s/%s/%s\n' "$REMOTE_BACKUP_ROOT" "$env" "$STAMP"
  else
    printf '%s/%s/%s\n' "$LOCAL_BACKUP_ROOT" "$env" "$STAMP"
  fi
}

remote_wp_content_dir() {
  printf '%s/wp-content\n' "$(wp_path "$1")"
}

prepare_target_backup() {
  local env="$1"
  local dir="$2"
  local wp_root
  local wp_content

  log "Criando backup do destino: ${env}"

  if is_remote_env "$env"; then
    wp_root="$(wp_path "$env")"
    wp_content="${wp_root}/wp-content"

    remote_run "set -euo pipefail
mkdir -p $(printf '%q' "$dir")
wp --path=$(printf '%q' "$wp_root") db export - | gzip -c > $(printf '%q' "${dir}/db-${env}-${STAMP}.sql.gz")
if [ -d $(printf '%q' "$wp_content") ]; then
  tar -czf $(printf '%q' "${dir}/files-${env}-${STAMP}.tar.gz") \
    -C $(printf '%q' "$wp_content") \
    --exclude='cache' --exclude='speedycache' --exclude='wc-logs' --exclude='wp-staging' \
    uploads plugins languages compressx compressx-nextgen .htaccess 2>/dev/null || true
fi
find $(printf '%q' "${REMOTE_BACKUP_ROOT}/${env}") -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' 2>/dev/null | sort -rn | tail -n +6 | cut -d' ' -f2- | xargs -r rm -rf"
  else
    mkdir -p "$dir"
    local_db_dump | gzip -c >"${dir}/db-${env}-${STAMP}.sql.gz"

    if [ -d "$LOCAL_WP_CONTENT" ]; then
      tar -czf "${dir}/files-${env}-${STAMP}.tar.gz" \
        -C "$LOCAL_WP_CONTENT" \
        --exclude='cache' --exclude='speedycache' --exclude='wc-logs' --exclude='wp-staging' \
        uploads plugins languages compressx compressx-nextgen .htaccess 2>/dev/null || true
    fi

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
    local wp_root
    wp_root="$(wp_path "$env")"

    remote_run "set -euo pipefail
mkdir -p $(printf '%q' "$dir")
prefix=\"\$(wp --path=$(printf '%q' "$wp_root") db prefix)\"
wp --path=$(printf '%q' "$wp_root") db export $(printf '%q' "${dir}/users.sql") --tables=\"\${prefix}users,\${prefix}usermeta\" >/dev/null"
  else
    mkdir -p "$dir"
    local prefix
    prefix="$(local_db_prefix)"
    local_db_dump "${prefix}users" "${prefix}usermeta" >"${dir}/users.sql"
  fi
}

snapshot_options() {
  local env="$1"
  local dir="$2"
  local where
  local query

  log "Preservando opções sensíveis do destino: ${env}"
  where="$(protected_options_where)"

  if is_remote_env "$env"; then
    local wp_root prefix
    wp_root="$(wp_path "$env")"
    prefix="$(wp_exec "$env" db prefix)"
    query="$(option_upsert_select_sql "${prefix}options" "$where")"

    remote_run "set -euo pipefail
mkdir -p $(printf '%q' "$dir")
db_name=\"\$(wp --path=$(printf '%q' "$wp_root") config get DB_NAME)\"
db_user=\"\$(wp --path=$(printf '%q' "$wp_root") config get DB_USER)\"
db_pass=\"\$(wp --path=$(printf '%q' "$wp_root") config get DB_PASSWORD)\"
db_host=\"\$(wp --path=$(printf '%q' "$wp_root") config get DB_HOST)\"
mysql_cmd=\"\$(command -v mysql || command -v mariadb)\"
MYSQL_PWD=\"\$db_pass\" \"\$mysql_cmd\" \
  --host=\"\$db_host\" \
  --user=\"\$db_user\" \
  --batch \
  --raw \
  --skip-column-names \
  \"\$db_name\" \
  -e $(printf '%q' "$query") > $(printf '%q' "${dir}/options.sql")"
  else
    local prefix
    mkdir -p "$dir"
    prefix="$(local_db_prefix)"
    local_db_dump_options "${prefix}options" "$where" >"${dir}/options.sql"
  fi
}

export_source_db() {
  local env="$1"
  local dump_file="$2"

  log "Exportando banco de origem: ${env}"

  if is_remote_env "$env"; then
    local wp_root
    wp_root="$(wp_path "$env")"
    remote_stream_to_file \
      "wp --path=$(printf '%q' "$wp_root") db export - | gzip -c" \
      "$dump_file"
  else
    local_db_dump | gzip -c >"$dump_file"
  fi
}

import_db_to_target() {
  local env="$1"
  local dump_file="$2"

  log "Importando banco no destino: ${env}"

  if is_remote_env "$env"; then
    local wp_root
    wp_root="$(wp_path "$env")"
    remote_import_gzip_dump \
      "$dump_file" \
      "wp --path=$(printf '%q' "$wp_root") db import -"
  else
    gzip -dc "$dump_file" | local_db_import
  fi
}

restore_users() {
  local env="$1"
  local dir="$2"

  [ "$PRESERVE_DESTINATION_USERS" = "1" ] || return 0

  log "Restaurando usuários do destino: ${env}"

  if is_remote_env "$env"; then
    local wp_root
    wp_root="$(wp_path "$env")"

    remote_run "set -euo pipefail
users_sql=$(printf '%q' "${dir}/users.sql")
if [ -s \"\$users_sql\" ]; then
  wp --path=$(printf '%q' "$wp_root") db import \"\$users_sql\"
fi"
  else
    if [ -s "${dir}/users.sql" ]; then
      local_db_import <"${dir}/users.sql"
    fi
  fi
}

restore_options() {
  local env="$1"
  local dir="$2"
  local where

  log "Restaurando opções sensíveis do destino: ${env}"
  where="$(protected_options_where)"

  if is_remote_env "$env"; then
    local wp_root
    wp_root="$(wp_path "$env")"

    remote_run "set -euo pipefail
options_sql=$(printf '%q' "${dir}/options.sql")
prefix=\"\$(wp --path=$(printf '%q' "$wp_root") db prefix)\"
delete_sql=\"DELETE FROM \${prefix}options WHERE ${where}\"
wp --path=$(printf '%q' "$wp_root") db query \"\$delete_sql\" >/dev/null
if [ -s \"\$options_sql\" ]; then
  wp --path=$(printf '%q' "$wp_root") db import \"\$options_sql\" >/dev/null
fi"
  else
    local prefix
    prefix="$(local_db_prefix)"
    local_db_query "DELETE FROM ${prefix}options WHERE ${where}" >/dev/null
    if [ -s "${dir}/options.sql" ]; then
      local_db_import <"${dir}/options.sql"
    fi
  fi
}

set_target_identity() {
  local env="$1"
  local source_url="$2"
  local target_url
  local target_title

  target_url="$(env_url "$env")"
  target_title="$(env_title "$env")"

  log "Ajustando URL e identidade do destino: ${env}"

  wp_exec "$env" search-replace "$source_url" "$target_url" --all-tables-with-prefix --skip-columns=guid --quiet
  wp_exec "$env" option update home "$target_url" >/dev/null
  wp_exec "$env" option update siteurl "$target_url" >/dev/null
  wp_exec "$env" option update blogname "$target_title" >/dev/null
}

remap_missing_authors() {
  local env="$1"

  [ "$PRESERVE_DESTINATION_USERS" = "1" ] || return 0

  log "Remapeando autores ausentes no destino: ${env}"

  if is_remote_env "$env"; then
    local wp_root
    wp_root="$(wp_path "$env")"

    remote_run "set -euo pipefail
prefix=\"\$(wp --path=$(printf '%q' "$wp_root") db prefix)\"
valid_ids=\"\$(wp --path=$(printf '%q' "$wp_root") user list --field=ID | paste -sd, -)\"
fallback_id=\"\$(wp --path=$(printf '%q' "$wp_root") user list --role=administrator --field=ID | head -n 1)\"
[ -n \"\$fallback_id\" ] || fallback_id=\"\$(wp --path=$(printf '%q' "$wp_root") user list --field=ID | head -n 1)\"
[ -n \"\$valid_ids\" ] || exit 0
wp --path=$(printf '%q' "$wp_root") db query \"UPDATE \${prefix}posts SET post_author = \${fallback_id} WHERE post_author NOT IN (\${valid_ids})\" >/dev/null"
  else
    local prefix valid_ids fallback_id
    prefix="$(local_db_prefix)"
    valid_ids="$(local_wp user list --field=ID | paste -sd, -)"
    fallback_id="$(local_wp user list --role=administrator --field=ID | head -n 1 || true)"
    [ -n "$fallback_id" ] || fallback_id="$(local_wp user list --field=ID | head -n 1 || true)"
    [ -n "$valid_ids" ] || return 0
    local_db_query "UPDATE ${prefix}posts SET post_author = ${fallback_id} WHERE post_author NOT IN (${valid_ids})" >/dev/null
  fi
}

enforce_smtp_plugin_policy() {
  local env="$1"
  local plugin
  local options_table
  local prefix

  log "Aplicando política SMTP do destino: ${env}"

  if wp_exec "$env" plugin is-installed "$SUPPORTED_SMTP_PLUGIN" >/dev/null 2>&1; then
    wp_exec "$env" plugin activate "$SUPPORTED_SMTP_PLUGIN" >/dev/null 2>&1 || die "Não foi possível ativar ${SUPPORTED_SMTP_PLUGIN} no destino: ${env}"
  elif [ "$env" != "local" ]; then
    die "${SUPPORTED_SMTP_PLUGIN} não está instalado no destino remoto: ${env}"
  else
    log "${SUPPORTED_SMTP_PLUGIN} não está instalado no local; mantendo clone local sem SMTP de produção."
  fi

  for plugin in "${LEGACY_SMTP_PLUGINS[@]}"; do
    if wp_exec "$env" plugin is-installed "$plugin" >/dev/null 2>&1; then
      wp_exec "$env" plugin deactivate "$plugin" >/dev/null 2>&1 || true
      wp_exec "$env" plugin delete "$plugin" >/dev/null 2>&1 || die "Não foi possível remover plugin SMTP legado ${plugin} no destino: ${env}"
      log "Plugin SMTP legado removido do destino: ${plugin}"
    fi
  done

  prefix="$(wp_exec "$env" db prefix | awk 'NF { value = $0 } END { print value }')"
  options_table="$(quote_sql_identifier "${prefix}options")"
  wp_exec "$env" db query "DELETE FROM ${options_table} WHERE option_name LIKE '%gosmtp%'" >/dev/null
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
    wp_root="$(wp_path "$env")"
    remote_cache_dirs="$(shell_join "${cache_dirs[@]}")"

    remote_run "set -euo pipefail
wp_content=$(printf '%q' "${wp_root}/wp-content")
cache_root=\"\$wp_content/cache\"
if [ -d \"\$cache_root\" ]; then
  for cache_dir in ${remote_cache_dirs}; do
    rm -rf \"\$cache_root/\$cache_dir\"
  done
fi
wp --path=$(printf '%q' "$wp_root") transient delete --all || true
wp --path=$(printf '%q' "$wp_root") cache flush || true"
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

  if [ "$env" = "local" ]; then
    log "Pulando validação CompressX para local; dev/local pode não servir AVIF/WebP via HTTPS."
    return 0
  fi

  command -v curl >/dev/null || die "curl não encontrado para validar CompressX."

  base_url="$(env_url "$env")"
  log "Validando entrega CompressX no destino: ${env}"

  for image_path in "${COMPRESSX_CRITICAL_IMAGE_PATHS[@]}"; do
    url="${base_url}${image_path}"
    headers_file="$(mktemp)"

    if ! curl -k -L -sS -o /dev/null -D "$headers_file" \
      -H 'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8' \
      "$url"; then
      rm -f "$headers_file"
      printf 'Erro: CompressX não conseguiu validar a imagem: %s\n' "$url" >&2
      failed="1"
      continue
    fi

    http_status="$(
      awk '/^HTTP\// { status = $2 } END { print status }' "$headers_file"
    )"
    content_type="$(
      awk 'BEGIN { IGNORECASE = 1 } /^content-type:/ { value = $0 } END { gsub(/\r/, "", value); sub(/^[^:]+:[[:space:]]*/, "", value); sub(/;.*/, "", value); print tolower(value) }' "$headers_file"
    )"
    content_length="$(
      awk 'BEGIN { IGNORECASE = 1 } /^content-length:/ { value = $0 } END { gsub(/\r/, "", value); sub(/^[^:]+:[[:space:]]*/, "", value); print value }' "$headers_file"
    )"
    rm -f "$headers_file"

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

sync_one_dir() {
  local source_env="$1"
  local target_env="$2"
  local relative_dir="$3"
  local source_content
  local target_content
  local rsync_args=("${EXCLUDED_RSYNC_ARGS[@]}")

  if [ "$relative_dir" = "plugins" ]; then
    rsync_args+=("${PLUGIN_RSYNC_EXCLUDES[@]}")
  fi

  source_content="$(content_path "$source_env")"
  target_content="$(content_path "$target_env")"

  if is_remote_env "$source_env" && is_remote_env "$target_env"; then
    remote_run "set -euo pipefail
src=$(printf '%q' "${source_content}/${relative_dir}")
dst=$(printf '%q' "${target_content}/${relative_dir}")
if [ -d \"\$src\" ]; then
  mkdir -p \"\$dst\"
  rsync -a --delete $(shell_join "${rsync_args[@]}") \"\$src/\" \"\$dst/\"
fi"
  elif is_remote_env "$source_env"; then
    if remote_run "[ -d $(printf '%q' "${source_content}/${relative_dir}") ]"; then
      mkdir -p "${target_content}/${relative_dir}"
      rsync_with_retry rsync -az --delete "${rsync_args[@]}" \
        -e "ssh -p ${SSH_PORT} -i ${SSH_KEY} -o BatchMode=yes -o StrictHostKeyChecking=accept-new" \
        "${SSH_USER}@${SSH_HOST}:${source_content}/${relative_dir}/" \
        "${target_content}/${relative_dir}/"
    fi
  elif is_remote_env "$target_env"; then
    [ -d "${source_content}/${relative_dir}" ] || return 0
    remote_run "mkdir -p $(printf '%q' "${target_content}/${relative_dir}")"
    rsync_with_retry rsync -az --delete "${rsync_args[@]}" \
      -e "ssh -p ${SSH_PORT} -i ${SSH_KEY} -o BatchMode=yes -o StrictHostKeyChecking=accept-new" \
      "${source_content}/${relative_dir}/" \
      "${SSH_USER}@${SSH_HOST}:${target_content}/${relative_dir}/"
  else
    [ -d "${source_content}/${relative_dir}" ] || return 0
    mkdir -p "${target_content}/${relative_dir}"
    rsync_with_retry rsync -a --delete "${rsync_args[@]}" \
      "${source_content}/${relative_dir}/" \
      "${target_content}/${relative_dir}/"
  fi
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
    sync_one_dir "$source_env" "$target_env" "$dir"
  done
}

preflight_env() {
  local env="$1"
  local wp_root
  local wp_content

  log "Validando ambiente: ${env}"

  if is_remote_env "$env"; then
    wp_root="$(wp_path "$env")"
    wp_content="${wp_root}/wp-content"

    remote_run "set -euo pipefail
command -v wp >/dev/null
command -v rsync >/dev/null
command -v gzip >/dev/null
command -v tar >/dev/null
(command -v mysql >/dev/null || command -v mariadb >/dev/null)
test -d $(printf '%q' "$wp_root")
test -d $(printf '%q' "$wp_content")
wp --path=$(printf '%q' "$wp_root") db prefix >/dev/null
wp --path=$(printf '%q' "$wp_root") option get home >/dev/null
wp --path=$(printf '%q' "$wp_root") option get siteurl >/dev/null"
  else
    [ -f "$LOCAL_COMPOSE_FILE" ] || die "Compose local não encontrado: ${LOCAL_COMPOSE_FILE}"
    [ -d "$LOCAL_WP_CONTENT" ] || die "wp-content local não encontrado: ${LOCAL_WP_CONTENT}"

    local_db_query "SELECT 1" >/dev/null
    local_db_query "SELECT option_value FROM \`${LOCAL_TABLE_PREFIX}options\` WHERE option_name IN ('home','siteurl')" >/dev/null
  fi
}

dry_run_clone() {
  local dirs=(uploads plugins languages)

  if [ "$INCLUDE_GIT_FILES" = "1" ]; then
    dirs+=(themes/kadence-child mu-plugins)
  fi

  log "Dry-run solicitado: nenhuma alteração será aplicada."
  log "Origem: ${SOURCE} ($(env_url "$SOURCE"))"
  log "Destino: ${TARGET} ($(env_url "$TARGET"))"
  log "Arquivos versionados: ${INCLUDE_GIT_FILES}; preservar usuários: ${PRESERVE_DESTINATION_USERS}"

  if [ "$TARGET" = "prod" ]; then
    log "Destino produção: clone real exigirá --confirm-production='CLONAR PARA PRODUCAO'."
  fi

  preflight_env "$SOURCE"
  preflight_env "$TARGET"

  log "Diretórios que seriam sincronizados em wp-content: ${dirs[*]}"
  log "Opções preservadas no destino: plugins gerenciados, active_plugins, cron, SMTP/captcha/Turnstile/CompressX/admin_email."
  log "Política SMTP pós-clone: ativar fluent-smtp em QA/prod e remover gosmtp/gosmtp-pro se existirem."
  log "Dry-run concluído sem alterações."
}

for arg in "$@"; do
  case "$arg" in
    --source=*) SOURCE="${arg#*=}" ;;
    --target=*) TARGET="${arg#*=}" ;;
    --include-git-files=*) INCLUDE_GIT_FILES="${arg#*=}" ;;
    --preserve-destination-users=*) PRESERVE_DESTINATION_USERS="${arg#*=}" ;;
    --confirm-production=*) CONFIRM_PRODUCTION="${arg#*=}" ;;
    --dry-run) DRY_RUN="1" ;;
    --yes) YES="1" ;;
    -h|--help) usage; exit 0 ;;
    *) die "Argumento inválido: $arg" ;;
  esac
done

[[ "$SOURCE" =~ ^(prod|qa|local)$ ]] || die "Informe --source=prod|qa|local"
[[ "$TARGET" =~ ^(prod|qa|local)$ ]] || die "Informe --target=prod|qa|local"
[ "$SOURCE" != "$TARGET" ] || die "Origem e destino não podem ser iguais"
[[ "$INCLUDE_GIT_FILES" =~ ^(0|1)$ ]] || die "--include-git-files precisa ser 0 ou 1"
[[ "$PRESERVE_DESTINATION_USERS" =~ ^(0|1)$ ]] || die "--preserve-destination-users precisa ser 0 ou 1"

if [ "$TARGET" = "prod" ] && [ "$DRY_RUN" != "1" ] && [ "$CONFIRM_PRODUCTION" != "CLONAR PARA PRODUCAO" ]; then
  die "Para clonar para produção, informe --confirm-production='CLONAR PARA PRODUCAO'"
fi

if [ "$YES" != "1" ] && [ "$DRY_RUN" != "1" ]; then
  usage
  die "Execução bloqueada. Rode novamente com --yes depois de revisar origem e destino."
fi

if ( is_remote_env "$SOURCE" || is_remote_env "$TARGET" ) && [ ! -f "$SSH_KEY" ]; then
  die "Chave SSH não encontrada: ${SSH_KEY}"
fi

if [ "$DRY_RUN" = "1" ]; then
  dry_run_clone
  exit 0
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

target_backup_dir="$(backup_dir "$TARGET")"
source_db_dump="${tmp_dir}/source-${SOURCE}-${STAMP}.sql.gz"
source_url="$(env_url "$SOURCE")"

log "Clone solicitado: ${SOURCE} -> ${TARGET}"
log "Arquivos versionados: ${INCLUDE_GIT_FILES}; preservar usuários: ${PRESERVE_DESTINATION_USERS}"

prepare_target_backup "$TARGET" "$target_backup_dir"
snapshot_users "$TARGET" "$target_backup_dir"
snapshot_options "$TARGET" "$target_backup_dir"
export_source_db "$SOURCE" "$source_db_dump"
import_db_to_target "$TARGET" "$source_db_dump"
restore_users "$TARGET" "$target_backup_dir"
set_target_identity "$TARGET" "$source_url"
restore_options "$TARGET" "$target_backup_dir"
wp_exec "$TARGET" option update home "$(env_url "$TARGET")" >/dev/null
wp_exec "$TARGET" option update siteurl "$(env_url "$TARGET")" >/dev/null
wp_exec "$TARGET" option update blogname "$(env_title "$TARGET")" >/dev/null
remap_missing_authors "$TARGET"
sync_runtime_files "$SOURCE" "$TARGET"
enforce_smtp_plugin_policy "$TARGET"
clear_cache "$TARGET"
validate_compressx_delivery "$TARGET"

log "Clone concluído: ${SOURCE} -> ${TARGET}"
