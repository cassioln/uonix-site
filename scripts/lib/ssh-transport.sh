#!/usr/bin/env bash
# Shared transport primitives. Source this file; do not execute it directly.

UONIX_TRANSPORT_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/environment-map.sh
source "${UONIX_TRANSPORT_LIB_DIR}/environment-map.sh"
UONIX_TRANSPORT_MAX_ATTEMPTS="${UONIX_TRANSPORT_MAX_ATTEMPTS:-5}"
UONIX_TRANSPORT_RETRY_DELAY="${UONIX_TRANSPORT_RETRY_DELAY:-10}"
UONIX_LOCAL_APP_CONTAINER="${UONIX_LOCAL_APP_CONTAINER:-uonix-local-app}"

UONIX_TRANSPORT_SSH=()
UONIX_TRANSPORT_REMOTE=""
UONIX_TRANSPORT_PASSWORD_FILE=""

uonix_transport_error() {
  printf 'Erro de transporte: %s\n' "$*" >&2
}

uonix_environment_canonical() {
  uonix_env_canonical "$1"
}

uonix_environment_field() {
  local environment
  local field="$2"
  environment="$(uonix_environment_canonical "$1")" || return

  case "$field" in
    url) uonix_env_url "$environment" ;;
    title) uonix_env_title "$environment" ;;
    wordpress_environment) uonix_env_type "$environment" ;;
    transport) uonix_env_transport "$environment" ;;
    host) uonix_env_host "$environment" ;;
    port) uonix_env_port "$environment" ;;
    user) uonix_env_user "$environment" ;;
    document_root) uonix_env_path "$environment" ;;
    backup_root) uonix_env_backup_root "$environment" ;;
    php_bin) uonix_env_php_bin "$environment" ;;
    wp_bin) uonix_env_wp_bin "$environment" ;;
    requires_ssh_window) uonix_env_requires_ssh_window "$environment" ;;
    *)
      uonix_transport_error "campo de ambiente desconhecido: $field"
      return 1
      ;;
  esac
}

uonix_transport_is_remote() {
  local transport
  transport="$(uonix_environment_field "$1" transport)" || return
  [ "$transport" != 'local-podman' ]
}

uonix_transport_file_mode() {
  local file="$1"

  if stat -f '%Lp' "$file" >/dev/null 2>&1; then
    stat -f '%Lp' "$file"
  else
    stat -c '%a' "$file"
  fi
}

uonix_transport_require_private_file() {
  local file="$1"
  local description="$2"
  local mode

  if [ ! -s "$file" ]; then
    uonix_transport_error "$description ausente ou vazio."
    return 1
  fi

  mode="$(uonix_transport_file_mode "$file")" || return
  case "$mode" in
    400|600) ;;
    *)
      uonix_transport_error "$description precisa ter modo 0400 ou 0600."
      return 1
      ;;
  esac
}

uonix_transport_build_ssh_command() {
  local environment
  local transport
  local host
  local port
  local user
  local key_file
  local known_hosts_file
  local password_file
  local control_dir
  local control_path
  local expanded_control_path_length

  environment="$(uonix_environment_canonical "$1")" || return
  transport="$(uonix_environment_field "$environment" transport)" || return
  host="$(uonix_environment_field "$environment" host)" || return
  port="$(uonix_environment_field "$environment" port)" || return
  user="$(uonix_environment_field "$environment" user)" || return

  [ -n "$host" ] || { uonix_transport_error "host ausente para $environment."; return 1; }
  [ -n "$port" ] || { uonix_transport_error "porta ausente para $environment."; return 1; }
  [ -n "$user" ] || { uonix_transport_error "usuário ausente para $environment."; return 1; }

  UONIX_TRANSPORT_REMOTE="${user}@${host}"
  UONIX_TRANSPORT_PASSWORD_FILE=""

  # O clone abre várias sessões SSH/rsync no mesmo processo (preflight, dumps,
  # backup, sync, smoke e eventual rollback). Sem multiplexação, o firewall do
  # HostGator pode bloquear a rajada exatamente no meio da mutação — inclusive
  # impedindo o rollback. Essa foi a causa comprovada de quatro deploys falhos.
  #
  # %C mantém o socket curto e único por host/porta/usuário. No Actions usamos
  # RUNNER_TEMP para que dry-run e execute, que são etapas do mesmo job,
  # compartilhem o master. No Mac usamos /tmp em vez do TMPDIR longo do macOS.
  #
  # O diretório default fica em /tmp, que é previsível e gravável por qualquer
  # usuário. Se sobrar um diretório de outro dono ou com modo divergente, o
  # mkdir/chmod falha — e falhar aqui aborta TODO o transporte, inclusive o
  # rollback de um clone já em mutação. Por isso cada falha é reportada com a
  # causa, em vez de sair silenciosamente com o erro cru do mkdir.
  control_dir="${UONIX_SSH_CONTROL_DIR:-${RUNNER_TEMP:-/tmp/uonix-ssh-${UID:-0}}}"
  if ! mkdir -p "$control_dir" 2>/dev/null; then
    uonix_transport_error "não foi possível criar o diretório de sockets SSH: ${control_dir}"
    return 1
  fi
  if [ -L "$control_dir" ]; then
    uonix_transport_error "diretório de sockets SSH é um symlink: ${control_dir}"
    return 1
  fi
  if [ ! -O "$control_dir" ]; then
    uonix_transport_error "diretório de sockets SSH pertence a outro usuário: ${control_dir}"
    return 1
  fi
  if ! chmod 700 "$control_dir" 2>/dev/null; then
    uonix_transport_error "não foi possível restringir o diretório de sockets SSH: ${control_dir}"
    return 1
  fi
  control_path="${control_dir%/}/uonix-%C"
  # OpenSSH expande %C para 40 hexadecimais; sockets Unix têm limite próximo de
  # 104 bytes em várias plataformas. Falhar cedo é melhor que ignorar o config.
  expanded_control_path_length=$(( ${#control_path} + 38 ))
  if [ "$expanded_control_path_length" -gt 100 ]; then
    uonix_transport_error "ControlPath SSH longo demais (${expanded_control_path_length} bytes)."
    return 1
  fi

  case "$transport" in
    hostgator-key)
      key_file="${HOSTGATOR_SSH_KEY:-$HOME/.ssh/uonix_github_actions_staging_nopass}"
      known_hosts_file="${HOSTGATOR_SSH_KNOWN_HOSTS_FILE:-}"
      uonix_transport_require_private_file "$key_file" 'chave SSH HostGator' || return
      uonix_transport_require_private_file "$known_hosts_file" 'known_hosts HostGator' || return
      UONIX_TRANSPORT_SSH=(
        ssh
        -p "$port"
        -i "$key_file"
        -o BatchMode=yes
        -o IdentitiesOnly=yes
        -o StrictHostKeyChecking=yes
        -o "UserKnownHostsFile=$known_hosts_file"
        -o ControlMaster=auto
        -o ControlPersist=120
        -o "ControlPath=$control_path"
      )
      ;;
    locaweb-password)
      password_file="${LOCAWEB_SSH_PASSWORD_FILE:-}"
      known_hosts_file="${LOCAWEB_SSH_KNOWN_HOSTS_FILE:-}"
      uonix_transport_require_private_file "$password_file" 'arquivo de senha Locaweb' || return
      uonix_transport_require_private_file "$known_hosts_file" 'known_hosts Locaweb' || return
      command -v sshpass >/dev/null 2>&1 || { uonix_transport_error 'sshpass não encontrado.'; return 1; }
      UONIX_TRANSPORT_PASSWORD_FILE="$password_file"
      UONIX_TRANSPORT_SSH=(
        sshpass -e ssh
        -p "$port"
        -o PreferredAuthentications=password
        -o PubkeyAuthentication=no
        -o NumberOfPasswordPrompts=1
        -o StrictHostKeyChecking=yes
        -o "UserKnownHostsFile=$known_hosts_file"
        -o ControlMaster=auto
        -o ControlPersist=120
        -o "ControlPath=$control_path"
      )
      ;;
    local-podman)
      uonix_transport_error "$environment usa Podman local, não SSH."
      return 1
      ;;
    *)
      uonix_transport_error "estratégia desconhecida para $environment: $transport"
      return 1
      ;;
  esac
}

uonix_transport_execute_built_ssh() {
  if [ -n "$UONIX_TRANSPORT_PASSWORD_FILE" ]; then
    SSHPASS="$(<"$UONIX_TRANSPORT_PASSWORD_FILE")" "${UONIX_TRANSPORT_SSH[@]}" "$@"
  else
    "${UONIX_TRANSPORT_SSH[@]}" "$@"
  fi
}

uonix_transport_ssh_once() {
  local environment="$1"
  local remote_command="$2"

  uonix_transport_build_ssh_command "$environment" || return
  uonix_transport_execute_built_ssh "$UONIX_TRANSPORT_REMOTE" "$remote_command"
}

uonix_transport_validate_retry_config() {
  case "$UONIX_TRANSPORT_MAX_ATTEMPTS" in
    ''|*[!0-9]*|0*)
      uonix_transport_error 'UONIX_TRANSPORT_MAX_ATTEMPTS deve ser inteiro maior que zero.'
      return 1
      ;;
  esac
  case "$UONIX_TRANSPORT_RETRY_DELAY" in
    ''|*[!0-9]*|0?*)
      uonix_transport_error 'UONIX_TRANSPORT_RETRY_DELAY deve ser inteiro não negativo.'
      return 1
      ;;
  esac
}

uonix_transport_ssh_retry() {
  local environment="$1"
  local remote_command="$2"
  local attempt=1
  local status

  uonix_transport_validate_retry_config || return

  while [ "$attempt" -le "$UONIX_TRANSPORT_MAX_ATTEMPTS" ]; do
    if uonix_transport_ssh_once "$environment" "$remote_command"; then
      return 0
    else
      status=$?
    fi
    if [ "$status" -ne 255 ] || [ "$attempt" -eq "$UONIX_TRANSPORT_MAX_ATTEMPTS" ]; then
      return "$status"
    fi

    if [ "$UONIX_TRANSPORT_RETRY_DELAY" -gt 0 ]; then
      sleep "$(( attempt * UONIX_TRANSPORT_RETRY_DELAY ))"
    fi
    attempt=$(( attempt + 1 ))
  done
}

uonix_transport_stream_to_file() {
  local environment="$1"
  local remote_command="$2"
  local output_file="$3"
  local partial_file="${output_file}.partial"
  local attempt=1
  local status

  uonix_transport_validate_retry_config || return

  while [ "$attempt" -le "$UONIX_TRANSPORT_MAX_ATTEMPTS" ]; do
    rm -f "$partial_file"
    if uonix_transport_ssh_once "$environment" "$remote_command" > "$partial_file"; then
      status=0
    else
      status=$?
    fi

    if [ "$status" -eq 0 ]; then
      if mv "$partial_file" "$output_file"; then
        return 0
      else
        status=$?
      fi
      rm -f "$partial_file" >/dev/null 2>&1 || :
      return "$status"
    fi
    rm -f "$partial_file"
    if [ "$status" -ne 255 ] || [ "$attempt" -eq "$UONIX_TRANSPORT_MAX_ATTEMPTS" ]; then
      return "$status"
    fi
    if [ "$UONIX_TRANSPORT_RETRY_DELAY" -gt 0 ]; then
      sleep "$(( attempt * UONIX_TRANSPORT_RETRY_DELAY ))"
    fi
    attempt=$(( attempt + 1 ))
  done
}

uonix_transport_import_gzip() {
  local environment="$1"
  local dump_file="$2"
  local remote_command="$3"
  local marker='__UONIX_REMOTE_IMPORT_STARTED__'
  local attempt=1
  local stderr_file
  local gzip_status
  local ssh_status
  local statuses
  local remote_started

  [ -s "$dump_file" ] || { uonix_transport_error 'dump gzip ausente ou vazio.'; return 1; }
  uonix_transport_validate_retry_config || return

  while [ "$attempt" -le "$UONIX_TRANSPORT_MAX_ATTEMPTS" ]; do
    stderr_file="$(mktemp)"
    uonix_transport_build_ssh_command "$environment" || { rm -f "$stderr_file"; return 1; }

    if [ -n "$UONIX_TRANSPORT_PASSWORD_FILE" ]; then
      if gzip -dc "$dump_file" | SSHPASS="$(<"$UONIX_TRANSPORT_PASSWORD_FILE")" "${UONIX_TRANSPORT_SSH[@]}" \
        "$UONIX_TRANSPORT_REMOTE" "printf '%s\\n' '$marker' >&2; $remote_command" 2> "$stderr_file"; then
        statuses=("${PIPESTATUS[@]}")
      else
        statuses=("${PIPESTATUS[@]}")
      fi
    else
      if gzip -dc "$dump_file" | "${UONIX_TRANSPORT_SSH[@]}" \
        "$UONIX_TRANSPORT_REMOTE" "printf '%s\\n' '$marker' >&2; $remote_command" 2> "$stderr_file"; then
        statuses=("${PIPESTATUS[@]}")
      else
        statuses=("${PIPESTATUS[@]}")
      fi
    fi

    gzip_status="${statuses[0]}"
    ssh_status="${statuses[1]}"
    remote_started=0
    if grep -q "$marker" "$stderr_file"; then
      remote_started=1
    fi
    grep -v "$marker" "$stderr_file" >&2 || true
    rm -f "$stderr_file"

    if [ "$gzip_status" -eq 0 ] && [ "$ssh_status" -eq 0 ]; then
      return 0
    fi
    if [ "$gzip_status" -ne 0 ]; then
      return "$gzip_status"
    fi
    if [ "$ssh_status" -ne 255 ] || [ "$remote_started" -eq 1 ] || [ "$attempt" -eq "$UONIX_TRANSPORT_MAX_ATTEMPTS" ]; then
      return "$ssh_status"
    fi

    if [ "$UONIX_TRANSPORT_RETRY_DELAY" -gt 0 ]; then
      sleep "$(( attempt * UONIX_TRANSPORT_RETRY_DELAY ))"
    fi
    attempt=$(( attempt + 1 ))
  done
}

uonix_transport_shell_join() {
  local argument
  local quoted=()

  for argument in "$@"; do
    quoted+=("$(printf '%q' "$argument")")
  done
  printf '%s ' "${quoted[@]}"
}

uonix_transport_rsync() {
  local environment="$1"
  local rsh
  shift

  uonix_transport_build_ssh_command "$environment" || return
  rsh="$(uonix_transport_shell_join "${UONIX_TRANSPORT_SSH[@]}")"

  if [ -n "$UONIX_TRANSPORT_PASSWORD_FILE" ]; then
    SSHPASS="$(<"$UONIX_TRANSPORT_PASSWORD_FILE")" rsync -e "$rsh" "$@"
  else
    rsync -e "$rsh" "$@"
  fi
}

# Retry interno exclusivo dos transfers declarativos runner<->ambiente abaixo.
# Não usar para comandos remotos mutáveis que não sejam replay-safe.
uonix_transport_rsync_retry() {
  local environment="$1"
  local attempt=1
  local status
  shift

  uonix_transport_validate_retry_config || return

  while [ "$attempt" -le "$UONIX_TRANSPORT_MAX_ATTEMPTS" ]; do
    if uonix_transport_rsync "$environment" "$@"; then
      return 0
    else
      status=$?
    fi
    case "$status" in
      10|12|30|35|255) ;;
      *) return "$status" ;;
    esac
    [ "$attempt" -lt "$UONIX_TRANSPORT_MAX_ATTEMPTS" ] || return "$status"
    if [ "$UONIX_TRANSPORT_RETRY_DELAY" -gt 0 ]; then
      sleep "$(( attempt * UONIX_TRANSPORT_RETRY_DELAY ))"
    fi
    attempt=$(( attempt + 1 ))
  done
}

# Interface pública exigida pelo clone. O separador `--` evita que opções da
# operação sejam interpretadas como argumentos do transporte.
uonix_exec() {
  local environment
  local remote_command

  environment="$(uonix_env_canonical "$1")" || return
  shift
  [ "${1:-}" = '--' ] || { uonix_transport_error 'uonix_exec exige separador --.'; return 1; }
  shift
  [ "$#" -gt 0 ] || { uonix_transport_error 'comando ausente em uonix_exec.'; return 1; }

  if [ "$environment" = local ]; then
    podman exec "$UONIX_LOCAL_APP_CONTAINER" "$@"
    return
  fi

  remote_command="$(uonix_transport_shell_join "$@")"
  uonix_transport_ssh_once "$environment" "$remote_command"
}

uonix_stream_from() {
  local environment
  local source_path="$2"
  environment="$(uonix_env_canonical "$1")" || return

  if [ "$environment" = local ]; then
    podman exec "$UONIX_LOCAL_APP_CONTAINER" cat -- "$source_path"
  else
    uonix_transport_ssh_once "$environment" "cat -- $(printf '%q' "$source_path")"
  fi
}

uonix_stream_to() {
  local environment
  local target_path="$2"
  environment="$(uonix_env_canonical "$1")" || return

  if [ "$environment" = local ]; then
    podman exec -i "$UONIX_LOCAL_APP_CONTAINER" sh -c "cat > $(printf '%q' "$target_path")"
  else
    uonix_transport_ssh_once "$environment" "cat > $(printf '%q' "$target_path")"
  fi
}

uonix_rsync_to_runner() {
  local environment
  local remote_path="$2"
  local local_path="$3"
  local remote
  environment="$(uonix_env_canonical "$1")" || return
  shift 3

  mkdir -p "$local_path"
  if [ "$environment" = local ]; then
    rsync -a --delete "$@" -- "${remote_path%/}/" "${local_path%/}/"
    return
  fi

  remote="$(uonix_env_user "$environment")@$(uonix_env_host "$environment")"
  uonix_transport_rsync_retry "$environment" -az --delete "$@" -- \
    "${remote}:${remote_path%/}/" \
    "${local_path%/}/"
}

uonix_rsync_from_runner() {
  local environment
  local local_path="$2"
  local remote_path="$3"
  local remote
  environment="$(uonix_env_canonical "$1")" || return
  shift 3

  [ -d "$local_path" ] || { uonix_transport_error "diretório local ausente: $local_path"; return 1; }
  if [ "$environment" = local ]; then
    mkdir -p "$remote_path"
    rsync -a --delete "$@" -- "${local_path%/}/" "${remote_path%/}/"
    return
  fi

  remote="$(uonix_env_user "$environment")@$(uonix_env_host "$environment")"
  uonix_transport_rsync_retry "$environment" -az --delete "$@" -- \
    "${local_path%/}/" \
    "${remote}:${remote_path%/}/"
}
